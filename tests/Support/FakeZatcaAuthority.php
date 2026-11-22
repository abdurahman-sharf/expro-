<?php

namespace Tests\Support;

use App\Libraries\EInvoicing\FakeZatcaHttp;
use App\Libraries\EInvoicing\InvoiceHash;
use App\Libraries\EInvoicing\StampVerifier;
use App\Libraries\EInvoicing\ZatcaHttpClient;
use App\Libraries\EInvoicing\ZatcaHttpFailure;
use App\Libraries\EInvoicing\ZatcaHttpReply;
use App\Libraries\EInvoicing\ZatcaHttpRequest;
use OpenSSLAsymmetricKey;
use OpenSSLCertificate;

/**
 * A ZATCA that lives in memory and answers the way the official APIs do, for the tests of the whole cycle: it issues
 * real certificates for the requests it is sent (signed by a certificate authority of its own), checks the one-time
 * password and the credentials of each call, verifies the stamp and the hash of every document, and answers each with
 * the status and the body the guideline describes. A test can make it refuse, warn, fail, or not answer. It sends
 * nothing anywhere.
 */
final class FakeZatcaAuthority
{
    public const OTP = '123456';

    /**
     * @var list<ZatcaHttpRequest>
     */
    public array $requests = [];

    /**
     * @var array<string, string> What is said next of the documents sent, by the uuid of a document or `*` for all: accept, warn, reject, fail, silent, unauthorized, clearance_off.
     */
    public array $next = [];

    /**
     * @var array<string, array{csr: string, secret: string, kind: string}> The certificates issued, by token.
     */
    private array $issued = [];

    /**
     * @var array<string, string> The requests behind each compliance request number.
     */
    private array $requestsById = [];

    private OpenSSLAsymmetricKey $authorityKey;
    private OpenSSLCertificate $authority;
    private int $serial = 1000;

    public function __construct()
    {
        $key = openssl_pkey_new(['private_key_type' => OPENSSL_KEYTYPE_EC, 'curve_name' => 'secp256k1']);
        $csr = openssl_csr_new(['commonName' => 'Fake ZATCA CA', 'organizationName' => 'Fake ZATCA', 'countryName' => 'SA'], $key, ['digest_alg' => 'sha256']);

        $this->authorityKey = $key;
        $this->authority    = openssl_csr_sign($csr, null, $key, 3650, ['digest_alg' => 'sha256'], 1);
    }

    public function transport(): FakeZatcaHttp
    {
        return new FakeZatcaHttp(fn (ZatcaHttpRequest $request): ZatcaHttpFailure|ZatcaHttpReply => $this->answer($request));
    }

    private function answer(ZatcaHttpRequest $request): ZatcaHttpFailure|ZatcaHttpReply
    {
        $this->requests[] = $request;

        $path = (string) parse_url($request->url, PHP_URL_PATH);
        $body = json_decode($request->body, true) ?: [];

        return match (true) {
            str_ends_with($path, ZatcaHttpClient::PATH_COMPLIANCE_INVOICES)                             => $this->document($request, $body, 'compliance'),
            str_ends_with($path, ZatcaHttpClient::PATH_COMPLIANCE_CSID)                                 => $this->compliance($request, $body),
            str_ends_with($path, ZatcaHttpClient::PATH_PRODUCTION_CSID) && $request->method === 'POST'  => $this->production($request, $body),
            str_ends_with($path, ZatcaHttpClient::PATH_PRODUCTION_CSID) && $request->method === 'PATCH' => $this->renewal($request, $body),
            str_ends_with($path, ZatcaHttpClient::PATH_REPORTING)                                       => $this->document($request, $body, 'production'),
            str_ends_with($path, ZatcaHttpClient::PATH_CLEARANCE)                                       => $this->document($request, $body, 'production', true),
            default                                                                                     => FakeZatcaHttp::json(404, ['message' => 'Not found']),
        };
    }

    /**
     * @param array<string, mixed> $body
     */
    private function compliance(ZatcaHttpRequest $request, array $body): ZatcaHttpReply
    {
        if ($request->header('OTP') !== self::OTP) {
            return FakeZatcaHttp::json(400, ['errors' => [['code' => 'Invalid-OTP', 'message' => 'The provided OTP is invalid']]]);
        }

        $csr       = (string) base64_decode((string) ($body['csr'] ?? ''), true);
        $requestId = (string) (1234567890000 + count($this->requestsById));

        $this->requestsById[$requestId] = $csr;

        return $this->certificate($csr, 'compliance', $requestId);
    }

    /**
     * @param array<string, mixed> $body
     */
    private function production(ZatcaHttpRequest $request, array $body): ZatcaHttpReply
    {
        if ($this->credential($request, 'compliance') === null) {
            return FakeZatcaHttp::json(401, ['message' => 'Unauthorized']);
        }

        $csr = $this->requestsById[(string) ($body['compliance_request_id'] ?? '')] ?? null;

        return $csr === null ? FakeZatcaHttp::json(400, ['errors' => [['code' => 'Invalid-Request-ID', 'message' => 'No such compliance request']]]) : $this->certificate($csr, 'production', (string) (2234567890000 + $this->serial));
    }

    /**
     * @param array<string, mixed> $body
     */
    private function renewal(ZatcaHttpRequest $request, array $body): ZatcaHttpReply
    {
        if ($this->credential($request, 'production') === null) {
            return FakeZatcaHttp::json(401, ['message' => 'Unauthorized']);
        }

        if ($request->header('OTP') !== self::OTP) {
            return FakeZatcaHttp::json(400, ['errors' => [['code' => 'Invalid-OTP', 'message' => 'The provided OTP is invalid']]]);
        }

        return $this->certificate((string) base64_decode((string) ($body['csr'] ?? ''), true), 'production', (string) (3234567890000 + $this->serial));
    }

    private function certificate(string $csr, string $kind, string $requestId): ZatcaHttpReply
    {
        $issued = @openssl_csr_sign($csr, $this->authority, $this->authorityKey, $kind === 'compliance' ? 30 : 365, ['digest_alg' => 'sha256'], ++$this->serial);

        if ($issued === false) {
            return FakeZatcaHttp::json(400, ['errors' => [['code' => 'Invalid-CSR', 'message' => 'The CSR cannot be read']]]);
        }

        openssl_x509_export($issued, $pem);

        $token  = base64_encode((string) preg_replace('/-----[A-Z ]+-----|\s+/', '', $pem));
        $secret = bin2hex(random_bytes(16));

        $this->issued[$token] = ['csr' => $csr, 'secret' => $secret, 'kind' => $kind];

        return FakeZatcaHttp::json(200, ['requestID' => (int) $requestId, 'dispositionMessage' => 'ISSUED', 'binarySecurityToken' => $token, 'secret' => $secret, 'errors' => null]);
    }

    /**
     * @return array{csr: string, secret: string, kind: string}|null The certificate the call was made with, when it is one of the kind asked.
     */
    private function credential(ZatcaHttpRequest $request, string $kind): ?array
    {
        $header = (string) $request->header('Authorization');
        $pair   = str_starts_with($header, 'Basic ') ? (string) base64_decode(substr($header, 6), true) : '';

        [$token, $secret] = array_pad(explode(':', $pair, 2), 2, '');

        $issued = $this->issued[$token] ?? null;

        return $issued !== null && $issued['kind'] === $kind && hash_equals($issued['secret'], $secret) ? $issued : null;
    }

    /**
     * @param array<string, mixed> $body
     */
    private function document(ZatcaHttpRequest $request, array $body, string $kind, bool $clearance = false): ZatcaHttpFailure|ZatcaHttpReply
    {
        $uuid = (string) ($body['uuid'] ?? '');
        $say  = $this->next[$uuid] ?? $this->next['*'] ?? 'accept';

        if ($say === 'silent') {
            return new ZatcaHttpFailure('No answer in time.', true);
        }

        if ($say === 'fail') {
            return FakeZatcaHttp::json(503, ['message' => 'Service unavailable']);
        }

        if ($say === 'unauthorized' || $this->credential($request, $kind) === null) {
            return FakeZatcaHttp::json(401, ['message' => 'Unauthorized']);
        }

        if ($clearance && $say === 'clearance_off') {
            return FakeZatcaHttp::json(303, ['message' => 'Clearance is deactivated, please use the reporting API']);
        }

        $xml    = (string) base64_decode((string) ($body['invoice'] ?? ''), true);
        $stamp  = (new StampVerifier())->verify($xml);
        $errors = [];

        if ($xml === '' || InvoiceHash::of($xml) !== (string) ($body['invoiceHash'] ?? '')) {
            $errors[] = ['type' => 'ERROR', 'code' => 'invoiceHash', 'category' => 'HASH', 'message' => 'The invoice hash is not the hash of the invoice', 'status' => 'ERROR'];
        }

        if (! $stamp['signed'] || ! $stamp['document'] || ! $stamp['properties'] || ! $stamp['signature']) {
            $errors[] = ['type' => 'ERROR', 'code' => 'signature', 'category' => 'SIGNATURE', 'message' => 'The cryptographic stamp is not valid', 'status' => 'ERROR'];
        }

        if ($say === 'reject') {
            $errors[] = ['type' => 'ERROR', 'code' => 'BR-KSA-08', 'category' => 'KSA', 'message' => 'The buyer identification is not valid', 'status' => 'ERROR'];
        }

        $warnings = $say === 'warn' ? [['type' => 'WARNING', 'code' => 'BR-KSA-37', 'category' => 'KSA', 'message' => 'The seller address building number should be 4 digits', 'status' => 'WARNING']] : [];
        $field    = $clearance ? 'clearanceStatus' : 'reportingStatus';
        $results  = ['infoMessages' => [['type' => 'INFO', 'code' => 'XSD_ZATCA_VALID', 'category' => 'XSD validation', 'message' => 'Complied with UBL 2.1 standards in line with ZATCA specifications', 'status' => 'PASS']], 'warningMessages' => $warnings, 'errorMessages' => $errors, 'status' => $errors !== [] ? 'ERROR' : ($warnings !== [] ? 'WARNING' : 'PASS')];

        if ($errors !== []) {
            return FakeZatcaHttp::json(400, ['validationResults' => $results, $field => $clearance ? 'NOT_CLEARED' : 'NOT_REPORTED']);
        }

        return FakeZatcaHttp::json($warnings === [] ? 200 : 202, ['validationResults' => $results, $field => $clearance ? 'CLEARED' : 'REPORTED', 'clearedInvoice' => $clearance ? base64_encode($xml) : null]);
    }
}
