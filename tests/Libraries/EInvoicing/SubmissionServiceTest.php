<?php

namespace Tests\Libraries\EInvoicing;

use App\Libraries\EInvoicing\FakeZatcaTransport;
use App\Libraries\EInvoicing\SubmissionService;
use App\Libraries\EInvoicing\ZatcaOutcome;
use App\Libraries\EInvoicing\ZatcaRequest;
use App\Libraries\EInvoicing\ZatcaResponse;
use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\DatabaseTestTrait;
use Config\Database;

/**
 * The queue of the second phase against a ZATCA that lives in memory: what is reported and what is cleared, the
 * answers kept as they came, a refusal that is final, a fault tried again later, no answer sent again the same way,
 * and no document moved by a worker that lost its claim or sent twice with success.
 */
class SubmissionServiceTest extends CIUnitTestCase
{
    use DatabaseTestTrait;

    protected $migrate     = true;
    protected $migrateOnce = true;
    protected $refresh     = false;
    protected $namespace   = null;

    private int $next = 880000;

    public static function setUpBeforeClass(): void
    {
        Database::seeder('tests')->call('TestDatabaseBootstrapSeeder');
    }

    protected function tearDown(): void
    {
        $db = Database::connect();

        $db->table('einvoice_submissions')->truncate();
        $db->table('einvoice_cleared_documents')->truncate();
        $db->table('einvoice_outbox')->truncate();
        $db->table('einvoice_documents')->where('source_type', 'sale')->where('source_id >=', 880000)->delete();

        parent::tearDown();
    }

    private function queued(string $subtype = 'simplified'): int
    {
        $id = ++$this->next;
        $db = Database::connect();

        $db->table('einvoice_documents')->insert([
            'source_type'     => 'sale',
            'source_id'       => $id,
            'doc_kind'        => 'invoice',
            'subtype'         => $subtype,
            'document_number' => 'Q-' . $id,
            'uuid'            => sprintf('00000000-0000-4000-8000-%012d', $id),
            'icv'             => $id,
            'previous_hash'   => 'x',
            'invoice_hash'    => 'hash-' . $id,
            'issue_date'      => '2026-11-21',
            'issue_time'      => '10:00:00',
            'tax_exclusive'   => '100.00',
            'tax_amount'      => '15.00',
            'tax_inclusive'   => '115.00',
            'xml'             => '<Invoice id="' . $id . '"/>',
        ]);

        $documentId = (int) $db->insertID();

        SubmissionService::enqueue($db, $documentId, 'production');

        return $documentId;
    }

    private function row(int $documentId): object
    {
        return Database::connect()->table('einvoice_outbox')->where('document_id', $documentId)->get()->getRow();
    }

    private function makeDue(): void
    {
        Database::connect()->table('einvoice_outbox')->where('next_attempt_at IS NOT NULL', null, false)->update(['next_attempt_at' => '2000-01-01 00:00:00']);
    }

    public function testDispatch_ReportsASimplifiedInvoiceAndClearsATaxInvoice(): void
    {
        $simplified = $this->queued();
        $standard   = $this->queued('standard');
        $zatca      = new FakeZatcaTransport(static fn (ZatcaRequest $request): ZatcaResponse => new ZatcaResponse(ZatcaOutcome::Accepted, 200, [], [], $request->kind === ZatcaRequest::CLEARANCE ? '<Invoice cleared="1"/>' : null));

        $this->assertSame([SubmissionService::REPORTED => 1, SubmissionService::CLEARED => 1], (new SubmissionService($zatca))->dispatch());
        $this->assertSame([SubmissionService::REPORTED, SubmissionService::CLEARED], [$this->row($simplified)->status, $this->row($standard)->status]);
        $this->assertSame([ZatcaRequest::REPORTING, ZatcaRequest::CLEARANCE], array_map(static fn (ZatcaRequest $request): string => $request->kind, $zatca->requests));
        $this->assertSame(base64_encode((string) Database::connect()->table('einvoice_documents')->where('document_id', $simplified)->get()->getRow()->xml), $zatca->requests[0]->body()['invoice'], 'the file goes out as it was issued');
        $this->assertSame('<Invoice cleared="1"/>', Database::connect()->table('einvoice_cleared_documents')->where('document_id', $standard)->get()->getRow()->xml);
        $this->assertSame(2, Database::connect()->table('einvoice_submissions')->countAllResults());
    }

    public function testDispatch_KeepsTheWarningsOfAnAcceptedDocument(): void
    {
        $document = $this->queued();

        (new SubmissionService(new FakeZatcaTransport([new ZatcaResponse(ZatcaOutcome::AcceptedWithWarnings, 202, ['BR-KSA-08: the identification is missing'])])))->dispatch();

        $submission = Database::connect()->table('einvoice_submissions')->where('document_id', $document)->get()->getRow();

        $this->assertSame(SubmissionService::REPORTED, $this->row($document)->status);
        $this->assertSame(['BR-KSA-08: the identification is missing'], json_decode((string) $submission->warnings, true));
        $this->assertSame(ZatcaOutcome::AcceptedWithWarnings->value, $submission->outcome);
    }

    public function testDispatch_RefusesForGoodWhatZatcaRejects(): void
    {
        $document = $this->queued();
        $zatca    = new FakeZatcaTransport([new ZatcaResponse(ZatcaOutcome::Rejected, 400, [], ['BR-KSA-51: the line does not add up'])]);
        $service  = new SubmissionService($zatca);

        $service->dispatch();
        $this->makeDue();
        $service->dispatch();

        $this->assertSame([SubmissionService::REJECTED, 'BR-KSA-51: the line does not add up'], [$this->row($document)->status, $this->row($document)->last_error_code]);
        $this->assertCount(1, $zatca->requests, 'a refused document is not sent again');
    }

    public function testDispatch_TriesAFaultAgainLaterAndSendsTheSameRequestAfterNoAnswer(): void
    {
        $document = $this->queued();
        $zatca    = new FakeZatcaTransport([new ZatcaResponse(ZatcaOutcome::ServerError, 503), new ZatcaResponse(ZatcaOutcome::Timeout), new ZatcaResponse(ZatcaOutcome::Accepted, 200)]);
        $service  = new SubmissionService($zatca);

        $service->dispatch();

        $this->assertSame([SubmissionService::RETRY, '1'], [$this->row($document)->status, (string) $this->row($document)->attempts]);
        $this->assertSame([], $service->dispatch(), 'nothing is due before the wait is over');

        $this->makeDue();
        $service->dispatch();

        $this->assertSame(SubmissionService::UNKNOWN, $this->row($document)->status);

        $this->makeDue();
        $service->dispatch();

        $this->assertSame([SubmissionService::REPORTED, '3'], [$this->row($document)->status, (string) $this->row($document)->attempts]);
        $this->assertCount(3, $zatca->requests);
        $this->assertSame($zatca->requests[0]->fingerprint(), $zatca->requests[2]->fingerprint(), 'the same request every time');
    }

    public function testDispatch_LetsNoWorkerThatLostItsLeaseMoveADocument(): void
    {
        $document = $this->queued();
        $second   = new SubmissionService(new FakeZatcaTransport([new ZatcaResponse(ZatcaOutcome::Accepted, 200)]));
        $first    = new SubmissionService(new FakeZatcaTransport(static function () use ($document, $second): ZatcaResponse {
            Database::connect()->table('einvoice_outbox')->where('document_id', $document)->update(['lease_until' => '2000-01-01 00:00:00']);
            $second->dispatch();

            return new ZatcaResponse(ZatcaOutcome::Rejected, 400, [], ['too late']);
        }));

        $this->assertSame(['late' => 1], $first->dispatch());
        $this->assertSame(SubmissionService::REPORTED, $this->row($document)->status, 'the answer of the worker that lost its lease changes nothing');
        $this->assertSame(2, Database::connect()->table('einvoice_submissions')->where('document_id', $document)->countAllResults(), 'both answers are kept');
    }

    public function testDispatch_SendsEachOfManyDocumentsOnceWithSuccessWhateverFails(): void
    {
        $documents = array_map(fn (): int => $this->queued(), range(1, 120));
        $random    = 7;
        $zatca     = new FakeZatcaTransport(static function () use (&$random): ZatcaResponse {
            $random = ($random * 1103515245 + 12345) % 2147483648;

            return match ($random % 4) {
                0       => new ZatcaResponse(ZatcaOutcome::ServerError, 500),
                1       => new ZatcaResponse(ZatcaOutcome::Timeout),
                default => new ZatcaResponse(ZatcaOutcome::Accepted, 200),
            };
        });
        $service = new SubmissionService($zatca);

        for ($round = 0; $round < 40 && Database::connect()->table('einvoice_outbox')->whereIn('document_id', $documents)->whereIn('status', SubmissionService::DUE)->countAllResults() > 0; $round++) {
            $service->dispatch(50);
            $this->makeDue();
        }

        $successes = Database::connect()->table('einvoice_submissions')->select('document_id, COUNT(*) AS n')->whereIn('document_id', $documents)->where('outcome', ZatcaOutcome::Accepted->value)->groupBy('document_id')->get()->getResult();

        $this->assertSame(count($documents), Database::connect()->table('einvoice_outbox')->whereIn('document_id', $documents)->where('status', SubmissionService::REPORTED)->countAllResults());
        $this->assertCount(count($documents), $successes);
        $this->assertSame([1], array_values(array_unique(array_map(static fn (object $row): int => (int) $row->n, $successes))), 'no document was sent with success twice');
    }
}
