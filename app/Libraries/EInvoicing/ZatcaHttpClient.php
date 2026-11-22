<?php

namespace App\Libraries\EInvoicing;

use Config\Zatca;
use SensitiveParameter;

/**
 * The client of the APIs of ZATCA (version 2), as the Fatoora portal manual names them and the detailed guideline
 * reads their answers, for one environment:
 *
 * - POST /compliance: the compliance certificate, for a request and the one-time password (OTP header);
 * - POST /compliance/invoices: the compliance checks of a document, with the compliance certificate;
 * - POST /production/csids: the production certificate, for the compliance request, with the compliance certificate;
 * - PATCH /production/csids: a new production certificate, for a new request and password, with the one it replaces;
 * - POST /invoices/reporting/single and /invoices/clearance/single: a simplified document reported, a tax document
 *   cleared, with the production certificate.
 *
 * An answer is read by its status: 200 accepted, 202 accepted with warnings, 303 clearance switched off (the document
 * is then reported, the same file), 400 refused, 401 and 403 credentials not accepted, 413 too large, and 429 and the
 * faults of the service not received; no answer is a timeout when the request may have arrived. The fields of the
 * bodies are read as the sandbox will show them; they are held against it once the sandbox is approved (D-10). It sends
 * through the transport it is given, and the real one asks the guard before every request.
 */
final class ZatcaHttpClient implements ZatcaClient, ZatcaOnboardingClient
{
    public const PATH_COMPLIANCE_CSID     = '/compliance';
    public const PATH_COMPLIANCE_INVOICES = '/compliance/invoices';
    public const PATH_PRODUCTION_CSID     = '/production/csids';
    public const PATH_REPORTING           = '/invoices/reporting/single';
    public const PATH_CLEARANCE           = '/invoices/clearance/single';
    private const RECEIVED_NOT            = [429, 500, 502, 503, 504];

    private Zatca $config;

    /**
     * @param (callable(): string)|null $clock The moment the certificates are read at, now when none is given.
     */
    public function __construct(
        private readonly ZatcaHttp $http,
        private readonly ZatcaEnvironment $environment,
        private readonly ?ZatcaAuthSource $credentials = null,
        ?Zatca $config = null,
        private readonly mixed $clock = null,
    ) {
        $this->config = $config ?? config(Zatca::class);
    }

    public function name(): string
    {
        return 'zatca-' . $this->environment->value;
    }

    public function submit(ZatcaRequest $request): ZatcaResponse
    {
        try {
            $auth = $this->credentials?->auth($request->unitId, $this->environment->value, $request->kind === ZatcaRequest::COMPLIANCE ? EInvoiceCredentials::COMPLIANCE : EInvoiceCredentials::PRODUCTION, $this->now());
        } catch (EInvoiceException $missing) {
            return new ZatcaResponse(ZatcaOutcome::NotSent, null, [], [$missing->getMessage()]);
        }

        if ($auth === null) {
            return new ZatcaResponse(ZatcaOutcome::NotSent, null, [], ['No credentials are configured for ZATCA.']);
        }

        $response = $this->document($request, $auth);

        if ($request->kind === ZatcaRequest::CLEARANCE && $response->httpStatus === 303) {
            $reported = $this->document($request->as(ZatcaRequest::REPORTING), $auth);

            return new ZatcaResponse($reported->outcome, $reported->httpStatus, $reported->warnings, $reported->errors, null, ZatcaRequest::REPORTING);
        }

        return $response;
    }

    public function complianceCsid(string $csr, #[SensitiveParameter] string $otp): ZatcaCsid
    {
        return $this->csid($this->call('POST', self::PATH_COMPLIANCE_CSID, ['OTP' => $otp], ['csr' => base64_encode($csr)]));
    }

    public function productionCsid(string $complianceRequestId, #[SensitiveParameter] ZatcaAuth $compliance): ZatcaCsid
    {
        return $this->csid($this->call('POST', self::PATH_PRODUCTION_CSID, ['Authorization' => $compliance->header()], ['compliance_request_id' => $complianceRequestId]));
    }

    public function renewProductionCsid(string $csr, #[SensitiveParameter] string $otp, #[SensitiveParameter] ZatcaAuth $production): ZatcaCsid
    {
        return $this->csid($this->call('PATCH', self::PATH_PRODUCTION_CSID, ['OTP' => $otp, 'Authorization' => $production->header()], ['csr' => base64_encode($csr)]));
    }

    private function document(ZatcaRequest $request, ZatcaAuth $auth): ZatcaResponse
    {
        [$path, $extra] = match ($request->kind) {
            ZatcaRequest::CLEARANCE => [self::PATH_CLEARANCE, ['Clearance-Status' => '1']],
            ZatcaRequest::REPORTING => [self::PATH_REPORTING, ['Clearance-Status' => '0']],
            default                 => [self::PATH_COMPLIANCE_INVOICES, []],
        };

        try {
            $reply = $this->call('POST', $path, ['Authorization' => $auth->header(), ...$extra], $request->body());
        } catch (ZatcaHttpFailure $failure) {
            return new ZatcaResponse($failure->mayHaveArrived ? ZatcaOutcome::Timeout : ZatcaOutcome::ServerError, null, [], [$failure->getMessage()]);
        } catch (EInvoiceException $blocked) {
            return new ZatcaResponse(ZatcaOutcome::NotSent, null, [], [$blocked->getMessage()]);
        }

        return self::read($reply, $request->kind);
    }

    /**
     * What an answer to a document says.
     */
    public static function read(ZatcaHttpReply $reply, string $kind): ZatcaResponse
    {
        $body     = $reply->json();
        $results  = is_array($body['validationResults'] ?? null) ? $body['validationResults'] : [];
        $warnings = self::messages($results['warningMessages'] ?? []);
        $errors   = self::messages($results['errorMessages'] ?? []);
        $status   = strtoupper((string) ($body[$kind === ZatcaRequest::CLEARANCE ? 'clearanceStatus' : 'reportingStatus'] ?? ''));
        $cleared  = is_string($body['clearedInvoice'] ?? null) ? (base64_decode($body['clearedInvoice'], true) ?: null) : null;

        $outcome = match (true) {
            in_array($reply->status, [200, 202], true) && str_starts_with($status, 'NOT_') => ZatcaOutcome::Rejected,
            $reply->status === 200                                                         => $warnings === [] ? ZatcaOutcome::Accepted : ZatcaOutcome::AcceptedWithWarnings,
            $reply->status === 202                                                         => ZatcaOutcome::AcceptedWithWarnings,
            in_array($reply->status, [400, 413], true)                                     => ZatcaOutcome::Rejected,
            in_array($reply->status, [401, 403], true)                                     => ZatcaOutcome::Unauthorized,
            default                                                                        => ZatcaOutcome::ServerError,
        };

        if ($errors === [] && ($outcome === ZatcaOutcome::Rejected || ! in_array($reply->status, [200, 202, 303], true))) {
            $errors = ['HTTP ' . $reply->status . (in_array($reply->status, self::RECEIVED_NOT, true) ? ': not received' : '')];
        }

        return new ZatcaResponse($outcome, $reply->status, $warnings, $errors, $outcome->accepted() ? $cleared : null);
    }

    /**
     * @param mixed $messages The messages of an answer, each with its code and its text.
     *
     * @return list<string>
     */
    private static function messages(mixed $messages): array
    {
        if (! is_array($messages)) {
            return [];
        }

        $read = [];

        foreach ($messages as $message) {
            if (is_array($message)) {
                $read[] = trim((string) ($message['code'] ?? '') . ': ' . (string) ($message['message'] ?? ''), ': ');
            } elseif (is_string($message) && trim($message) !== '') {
                $read[] = trim($message);
            }
        }

        return $read;
    }

    /**
     * @throws EInvoiceException When ZATCA refuses the request or gives no certificate.
     */
    private function csid(ZatcaHttpReply $reply): ZatcaCsid
    {
        $body  = $reply->json();
        $token = (string) ($body['binarySecurityToken'] ?? '');

        if ($reply->status !== 200 || $token === '' || (string) ($body['secret'] ?? '') === '') {
            $said = self::messages($body['errors'] ?? []);

            if ($said === [] && isset($body['message'])) {
                $said = [trim((string) ($body['code'] ?? '') . ': ' . (string) $body['message'], ': ')];
            }

            throw new EInvoiceException(EInvoiceError::ZatcaRefused, 'ZATCA gave no certificate: HTTP ' . $reply->status . ($said === [] ? '' : ', ' . implode('; ', $said)) . '.', ['field' => 'HTTP ' . $reply->status]);
        }

        $certificate = base64_decode($token, true);
        $parsed      = $certificate === false ? false : openssl_x509_parse("-----BEGIN CERTIFICATE-----\n" . chunk_split($certificate, 64, "\n") . "-----END CERTIFICATE-----\n");

        if ($parsed === false) {
            throw new EInvoiceException(EInvoiceError::CertificateUnreadable, 'The certificate ZATCA gave cannot be read.');
        }

        return new ZatcaCsid(
            (string) ($body['requestID'] ?? ''),
            $token,
            (string) $body['secret'],
            gmdate('Y-m-d H:i:s', (int) $parsed['validFrom_time_t']),
            gmdate('Y-m-d H:i:s', (int) $parsed['validTo_time_t']),
            (string) $certificate,
        );
    }

    /**
     * @param array<string, string> $headers
     * @param array<string, mixed>  $body
     *
     * @throws EInvoiceException|ZatcaHttpFailure
     */
    private function call(string $method, string $path, #[SensitiveParameter] array $headers, array $body): ZatcaHttpReply
    {
        return $this->http->send(new ZatcaHttpRequest(
            $this->environment->value,
            $method,
            $this->environment->baseUrl($this->config) . $path,
            ['Accept' => 'application/json', 'Content-Type' => 'application/json', 'Accept-Version' => 'V2', 'Accept-Language' => $this->config->language, ...$headers],
            json_encode($body, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES),
        ));
    }

    private function now(): string
    {
        return is_callable($this->clock) ? (string) ($this->clock)() : date('Y-m-d H:i:s');
    }
}
