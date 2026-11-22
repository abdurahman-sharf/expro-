<?php

namespace Tests\Libraries\EInvoicing;

use App\Libraries\EInvoicing\EInvoiceError;
use App\Libraries\EInvoicing\EInvoiceException;
use App\Libraries\EInvoicing\FakeZatcaHttp;
use App\Libraries\EInvoicing\ZatcaAuth;
use App\Libraries\EInvoicing\ZatcaAuthSource;
use App\Libraries\EInvoicing\ZatcaEnvironment;
use App\Libraries\EInvoicing\ZatcaHttpClient;
use App\Libraries\EInvoicing\ZatcaHttpFailure;
use App\Libraries\EInvoicing\ZatcaHttpReply;
use App\Libraries\EInvoicing\ZatcaOutcome;
use App\Libraries\EInvoicing\ZatcaRequest;
use CodeIgniter\Test\CIUnitTestCase;
use Config\Zatca;
use Tests\Support\FakeZatcaAuthority;

/**
 * The client of the APIs of ZATCA against answers written as the official guideline describes them: what each call
 * sends (the address, the headers, the credentials and the body), and how each status is read for the queue. Nothing
 * leaves the machine: the transport is a fake one.
 */
class ZatcaHttpClientTest extends CIUnitTestCase
{
    private function credentials(?EInvoiceException $refusal = null): ZatcaAuthSource
    {
        return new class ($refusal) implements ZatcaAuthSource {
            /**
             * @var list<array{0: int|null, 1: string, 2: string}>
             */
            public array $asked = [];

            public function __construct(private readonly ?EInvoiceException $refusal)
            {
            }

            public function auth(?int $unitId, string $environment, string $kind, string $now): ZatcaAuth
            {
                $this->asked[] = [$unitId, $environment, $kind];

                if ($this->refusal !== null) {
                    throw $this->refusal;
                }

                return new ZatcaAuth($kind . '-token', $kind . '-secret');
            }
        };
    }

    private function client(FakeZatcaHttp $http, ?ZatcaAuthSource $credentials = null, ZatcaEnvironment $environment = ZatcaEnvironment::Sandbox): ZatcaHttpClient
    {
        return new ZatcaHttpClient($http, $environment, $credentials ?? $this->credentials(), new Zatca());
    }

    private function document(string $kind = ZatcaRequest::REPORTING): ZatcaRequest
    {
        return new ZatcaRequest($kind, 'sandbox', '8e6000cf-1a98-4174-b3e7-b5d5954bc10d', 'hash-of-the-document', '<Invoice/>', 7);
    }

    /**
     * @param array<string, mixed> $extra
     */
    private static function results(array $warnings = [], array $errors = [], array $extra = []): array
    {
        return ['validationResults' => ['infoMessages' => [], 'warningMessages' => $warnings, 'errorMessages' => $errors, 'status' => $errors !== [] ? 'ERROR' : 'PASS'], ...$extra];
    }

    public function testSubmit_ReportsASimplifiedDocumentWithTheProductionCertificateOfItsUnit(): void
    {
        $http        = new FakeZatcaHttp([FakeZatcaHttp::json(200, self::results([], [], ['reportingStatus' => 'REPORTED']))]);
        $credentials = $this->credentials();
        $response    = $this->client($http, $credentials)->submit($this->document());
        $request     = $http->requests[0];

        $this->assertSame(ZatcaOutcome::Accepted, $response->outcome);
        $this->assertSame(['POST', 'https://gw-fatoora.zatca.gov.sa/e-invoicing/developer-portal/invoices/reporting/single'], [$request->method, $request->url]);
        $this->assertSame(['V2', '0', 'application/json', 'en'], [$request->header('Accept-Version'), $request->header('Clearance-Status'), $request->header('Content-Type'), $request->header('Accept-Language')]);
        $this->assertSame('Basic ' . base64_encode('production-token:production-secret'), $request->header('Authorization'));
        $this->assertSame(['invoiceHash' => 'hash-of-the-document', 'uuid' => '8e6000cf-1a98-4174-b3e7-b5d5954bc10d', 'invoice' => base64_encode('<Invoice/>')], json_decode($request->body, true));
        $this->assertSame([[7, 'sandbox', 'production']], $credentials->asked, 'the certificate of the unit that issued the document');
    }

    public function testSubmit_ClearsATaxDocumentAndKeepsTheFileZatcaStamped(): void
    {
        $http     = new FakeZatcaHttp([FakeZatcaHttp::json(200, self::results([], [], ['clearanceStatus' => 'CLEARED', 'clearedInvoice' => base64_encode('<Invoice cleared="1"/>')]))]);
        $response = $this->client($http, null, ZatcaEnvironment::Production)->submit($this->document(ZatcaRequest::CLEARANCE));

        $this->assertSame([ZatcaOutcome::Accepted, '<Invoice cleared="1"/>'], [$response->outcome, $response->clearedInvoice]);
        $this->assertSame(['https://gw-fatoora.zatca.gov.sa/e-invoicing/core/invoices/clearance/single', '1'], [$http->requests[0]->url, $http->requests[0]->header('Clearance-Status')]);
    }

    public function testSubmit_ReportsATaxDocumentWhenClearanceIsSwitchedOff(): void
    {
        $http     = new FakeZatcaHttp([FakeZatcaHttp::json(303, ['message' => 'Clearance is deactivated']), FakeZatcaHttp::json(200, self::results([], [], ['reportingStatus' => 'REPORTED']))]);
        $response = $this->client($http)->submit($this->document(ZatcaRequest::CLEARANCE));

        $this->assertSame([ZatcaOutcome::Accepted, ZatcaRequest::REPORTING], [$response->outcome, $response->kind], 'the same file, reported (303)');
        $this->assertStringEndsWith('/invoices/reporting/single', $http->requests[1]->url);
        $this->assertSame($http->requests[0]->body, $http->requests[1]->body);
    }

    public function testSubmit_ReadsWarningsAndErrorsWithTheirCodes(): void
    {
        $warning = ['type' => 'WARNING', 'code' => 'BR-KSA-37', 'category' => 'KSA', 'message' => 'The building number should be 4 digits', 'status' => 'WARNING'];
        $error   = ['type' => 'ERROR', 'code' => 'BR-KSA-08', 'category' => 'KSA', 'message' => 'The buyer identification is not valid', 'status' => 'ERROR'];

        $warned  = $this->client(new FakeZatcaHttp([FakeZatcaHttp::json(202, self::results([$warning], [], ['reportingStatus' => 'REPORTED']))]))->submit($this->document());
        $refused = $this->client(new FakeZatcaHttp([FakeZatcaHttp::json(400, self::results([], [$error], ['reportingStatus' => 'NOT_REPORTED']))]))->submit($this->document());

        $this->assertSame([ZatcaOutcome::AcceptedWithWarnings, ['BR-KSA-37: The building number should be 4 digits']], [$warned->outcome, $warned->warnings]);
        $this->assertSame([ZatcaOutcome::Rejected, 400, ['BR-KSA-08: The buyer identification is not valid']], [$refused->outcome, $refused->httpStatus, $refused->errors]);
    }

    public function testSubmit_ReadsEveryStatusOfTheGuidelineAsTheQueueActsOnIt(): void
    {
        $read = fn (int $status): ZatcaOutcome => $this->client(new FakeZatcaHttp([new ZatcaHttpReply($status, '')]))->submit($this->document())->outcome;

        $this->assertSame(ZatcaOutcome::Unauthorized, $read(401), 'check the certificate and its secret');
        $this->assertSame(ZatcaOutcome::Unauthorized, $read(403));
        $this->assertSame(ZatcaOutcome::Rejected, $read(413), 'too large: not received, and never received as it is');

        foreach ([429, 500, 503, 504] as $status) {
            $response = $this->client(new FakeZatcaHttp([new ZatcaHttpReply($status, '')]))->submit($this->document());

            $this->assertSame([ZatcaOutcome::ServerError, ['HTTP ' . $status . ': not received']], [$response->outcome, $response->errors], 'sent again later');
        }

        $this->assertSame(ZatcaOutcome::Rejected, $this->client(new FakeZatcaHttp([FakeZatcaHttp::json(200, self::results([], [], ['reportingStatus' => 'NOT_REPORTED']))]))->submit($this->document())->outcome, 'what says it was not reported was not');
    }

    public function testSubmit_TellsNoAnswerFromAConnectionThatWasNeverMade(): void
    {
        $timeout = $this->client(new FakeZatcaHttp([new ZatcaHttpFailure('No answer in time.', true)]))->submit($this->document());
        $refused = $this->client(new FakeZatcaHttp([new ZatcaHttpFailure('Could not connect.', false)]))->submit($this->document());

        $this->assertSame(ZatcaOutcome::Timeout, $timeout->outcome, 'may have arrived: sent again as it was');
        $this->assertSame(ZatcaOutcome::ServerError, $refused->outcome, 'never arrived');
    }

    public function testSubmit_SendsNothingForAUnitWithoutItsCertificate(): void
    {
        $http     = new FakeZatcaHttp([]);
        $response = $this->client($http, $this->credentials(new EInvoiceException(EInvoiceError::CertificateExpired, 'The certificate of the unit ran out.')))->submit($this->document());

        $this->assertSame([ZatcaOutcome::NotSent, ['The certificate of the unit ran out.']], [$response->outcome, $response->errors]);
        $this->assertSame([], $http->requests);
    }

    public function testSubmit_SendsTheComplianceChecksWithTheComplianceCertificate(): void
    {
        $http        = new FakeZatcaHttp([FakeZatcaHttp::json(200, self::results([], [], ['reportingStatus' => 'REPORTED']))]);
        $credentials = $this->credentials();

        $this->client($http, $credentials)->submit($this->document(ZatcaRequest::COMPLIANCE));

        $this->assertStringEndsWith('/compliance/invoices', $http->requests[0]->url);
        $this->assertNull($http->requests[0]->header('Clearance-Status'));
        $this->assertSame([[7, 'sandbox', 'compliance']], $credentials->asked);
    }

    public function testComplianceCsid_AsksWithTheRequestAndThePasswordAndReadsTheCertificateItGets(): void
    {
        $authority = new FakeZatcaAuthority();
        $http      = $authority->transport();
        $key       = openssl_pkey_new(['private_key_type' => OPENSSL_KEYTYPE_EC, 'curve_name' => 'secp256k1']);

        openssl_csr_export(openssl_csr_new(['commonName' => 'EGS1', 'organizationName' => 'Taxpayer', 'countryName' => 'SA'], $key, ['digest_alg' => 'sha256']), $csr);

        $csid    = $this->client($http)->complianceCsid($csr, FakeZatcaAuthority::OTP);
        $request = $http->requests[0];

        $this->assertSame(['POST', FakeZatcaAuthority::OTP, ['csr' => base64_encode($csr)]], [$request->method, $request->header('OTP'), json_decode($request->body, true)]);
        $this->assertStringEndsWith('/compliance', $request->url);
        $this->assertNull($request->header('Authorization'));
        $this->assertSame(base64_decode($csid->token, true), $csid->certificate, 'the token is the certificate in base64, in base64');
        $this->assertMatchesRegularExpression('/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}$/', $csid->validTo);
        $this->assertNotSame('', $csid->requestId);

        $production = $this->client($http)->productionCsid($csid->requestId, $csid->auth());

        $this->assertSame(['POST', $csid->auth()->header(), ['compliance_request_id' => $csid->requestId]], [$http->requests[1]->method, $http->requests[1]->header('Authorization'), json_decode($http->requests[1]->body, true)]);

        $renewed = $this->client($http)->renewProductionCsid($csr, FakeZatcaAuthority::OTP, $production->auth());

        $this->assertSame(['PATCH', $production->auth()->header(), FakeZatcaAuthority::OTP], [$http->requests[2]->method, $http->requests[2]->header('Authorization'), $http->requests[2]->header('OTP')]);
        $this->assertNotSame($production->certificate, $renewed->certificate);
    }

    public function testComplianceCsid_RefusesWhenZatcaGivesNoCertificateAndSaysWhy(): void
    {
        try {
            $this->client((new FakeZatcaAuthority())->transport())->complianceCsid('not a request', '654321');
            $this->fail('A certificate was given for a wrong password.');
        } catch (EInvoiceException $refusal) {
            $this->assertSame(EInvoiceError::ZatcaRefused, $refusal->error);
            $this->assertStringContainsString('Invalid-OTP', $refusal->getMessage());
            $this->assertStringNotContainsString('654321', $refusal->getMessage(), 'the password is never repeated');
        }
    }

    public function testSubmit_NeverShowsTheCredentialsOrThePasswordOfARequest(): void
    {
        $http = new FakeZatcaHttp([FakeZatcaHttp::json(200, self::results())]);

        $this->client($http)->submit($this->document());

        $shown = print_r($http->requests[0], true);

        $this->assertStringNotContainsString(base64_encode('production-token:production-secret'), $shown);
        $this->assertStringContainsString('***', $shown);
        $this->assertStringNotContainsString('production-secret', print_r(new ZatcaAuth('production-token', 'production-secret'), true));
    }
}
