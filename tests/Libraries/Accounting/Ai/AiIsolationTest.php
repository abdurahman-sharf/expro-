<?php

namespace Tests\Libraries\Accounting\Ai;

use App\Libraries\Accounting\Ai\AiSettings;
use App\Libraries\Accounting\Ai\AnomalyScanner;
use App\Libraries\Accounting\Ai\ProposalRunner;
use App\Libraries\Accounting\Ai\ProposalService;
use App\Libraries\Accounting\Ai\ProposalStatus;
use App\Libraries\Accounting\AccountingException;
use App\Libraries\Accounting\JournalService;
use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\DatabaseTestTrait;
use Config\Database;
use Tests\Support\AiFixtureTrait;
use Tests\Support\DatabaseFingerprintTrait;
use Tests\Support\ItemFixtureTrait;
use Tests\Support\SaleAccountingFixtureTrait;

/**
 * The books work the same with the agent on or off: a sale posts the same journals, a manual journal is posted and
 * reversed as before, a journal exists only for a proposal that a person approved, and switching the agent off leaves
 * everything it recorded where it was.
 *
 * @internal
 */
final class AiIsolationTest extends CIUnitTestCase
{
    use DatabaseTestTrait;
    use AiFixtureTrait;
    use ItemFixtureTrait;
    use SaleAccountingFixtureTrait;
    use DatabaseFingerprintTrait;

    protected $migrate     = true;
    protected $migrateOnce = true;
    protected $refresh     = false;
    protected $namespace   = null;

    public static function setUpBeforeClass(): void
    {
        $seeder = Database::seeder('tests');
        $seeder->call('TestDatabaseBootstrapSeeder');
    }

    protected function tearDown(): void
    {
        $this->stopSelling();
        $this->stopAgent();

        parent::tearDown();
    }

    /**
     * @return list<array{source: string, entry: string, revision: int, status: string, date: string, lines: list<string>}>
     */
    private function theReferenceSale(): array
    {
        $invoice = $this->referenceInvoice();
        $saleId  = $this->save($invoice['items'], $invoice['taxes'], [$this->payment('Cash', '400.00')]);

        return array_map(static function (array $journal): array {
            unset($journal['id']);

            return $journal;
        }, $this->journals($saleId));
    }

    private function startBooks(bool $agent): void
    {
        $this->startSelling();
        $this->resetAgentThresholds();
        $this->setConfigValue(AiSettings::ENABLED_KEY, $agent ? '1' : '0');
    }

    public function testASale_PostsTheSameJournalsWithTheAgentOffAndWithTheAgentOnAndBusy(): void
    {
        $this->startBooks(false);
        $off = $this->theReferenceSale();

        $this->stopSelling();
        $this->startBooks(true);

        $employee = $this->agentEmployee();
        (new ProposalRunner())->run($employee);
        (new AnomalyScanner())->scan($employee, null, '2020-01-01');

        $proposals = new ProposalService();
        $proposals->approve($proposals->create($this->proposalDraft('75.00', 'busy'), $employee), $employee);

        $on = $this->theReferenceSale();

        $this->assertNotSame([], $off);
        $this->assertSame($off, $on);
    }

    public function testTheBooks_PostAndReverseAManualJournalWithTheAgentOff(): void
    {
        $this->startAgent(false);

        $service   = new JournalService();
        $journalId = $service->postNew($this->journalInput('120.00'), null);
        $reversal  = $service->reverse($journalId, '2026-10-22', 'Test', null);

        $this->assertGreaterThan($journalId, $reversal);
        $this->assertSame('reversed', $this->accountingDb()->table('accounting_journals')->where('journal_id', $journalId)->get()->getRow()->status);
        $this->assertSame(0, $this->countRows('accounting_ai_proposals'));
        $this->assertSame(0, $this->countRows('accounting_ai_findings'));
    }

    public function testAnyCall_RefusesAndWritesNothingWhileTheAgentIsOff(): void
    {
        $this->startAgent(false);
        $employee = $this->agentEmployee();
        $this->manualJournal('2026-10-10', $this->rentExpense(), $this->cashAccount(), '25000.00', 'Round');

        $before = $this->databaseFingerprint();

        foreach ([
            fn () => (new ProposalService())->create($this->proposalDraft(), $employee),
            fn () => (new ProposalRunner())->run($employee),
            fn () => (new AnomalyScanner())->scan($employee),
            fn () => (new ProposalService())->approve(1, $employee),
        ] as $call) {
            try {
                $call();
                $this->fail('A call went through while the agent was off.');
            } catch (AccountingException $failure) {
                $this->assertSame('ai_disabled', $failure->error->value);
            }
        }

        $this->assertSame($before, $this->databaseFingerprint());
    }

    public function testApprove_PostsAJournalOnlyForAProposalThatAPersonApproved(): void
    {
        $this->startAgent();

        $employee  = $this->agentEmployee();
        $proposals = new ProposalService();
        $approved  = $proposals->create($this->proposalDraft('10.00', 'approved'), $employee);
        $rejected  = $proposals->create($this->proposalDraft('20.00', 'rejected'), $employee);
        $expired   = $proposals->create($this->proposalDraft('30.00', 'expired'), $employee);
        $stale     = $proposals->create($this->proposalDraft('40.00', 'stale', '2026-10-05'), $employee);
        $standing  = $proposals->create($this->proposalDraft('50.00', 'standing'), $employee);

        $this->assertSame(0, $this->aiJournals());

        $proposals->approve($approved, $employee);
        $proposals->reject($rejected, 'No', $employee);
        $this->accountingDb()->table('accounting_ai_proposals')->where('proposal_id', $expired)->update(['expires_at' => '2020-01-01 00:00:00']);
        $proposals->expireDue();
        $this->closeThrough('2026-10-10');

        try {
            $proposals->approve($stale, $employee);
        } catch (AccountingException) {
            $this->addToAssertionCount(1);
        }

        $this->assertSame(1, $this->aiJournals());
        $this->assertSame(ProposalStatus::Approved->value, $this->accountingDb()->table('accounting_ai_proposals')->where('proposal_id', $approved)->get()->getRow()->status);

        foreach ([$rejected, $expired, $stale, $standing] as $id) {
            $this->assertNull($this->accountingDb()->table('accounting_ai_proposals')->where('proposal_id', $id)->get()->getRow()->journal_id);
            $this->assertSame(0, (int) $this->accountingDb()->table('accounting_journals')->where(['source_type' => ProposalService::SOURCE, 'source_id' => $id])->countAllResults());
        }

        $orphans = $this->accountingDb()->query('SELECT COUNT(*) AS found FROM ' . $this->accountingDb()->prefixTable('accounting_journals') . ' j LEFT JOIN ' . $this->accountingDb()->prefixTable('accounting_ai_proposals') . ' p ON p.proposal_id = j.source_id AND p.journal_id = j.journal_id AND p.status = ? WHERE j.source_type = ? AND p.proposal_id IS NULL', [ProposalStatus::Approved->value, ProposalService::SOURCE])->getRow()->found;

        $this->assertSame(0, (int) $orphans);
    }

    public function testSetEnabled_KeepsWhatTheAgentRecordedAndStopsTheRestWhenSwitchedOff(): void
    {
        $this->startAgent();

        $employee  = $this->agentEmployee();
        $proposals = new ProposalService();
        $id        = $proposals->create($this->proposalDraft(), $employee);

        $this->manualJournal('2026-10-10', $this->rentExpense(), $this->cashAccount(), '25000.00', 'Round');
        (new AnomalyScanner())->scan($employee, null, '2020-01-01');

        (new AiSettings())->setEnabled(false, $employee);

        $this->assertSame(1, $this->countRows('accounting_ai_proposals'));
        $this->assertSame(1, $this->countRows('accounting_ai_findings'));
        $this->assertSame('proposed', $proposals->find($id, $employee)->status);

        try {
            $proposals->approve($id, $employee);
            $this->fail('The approval went through.');
        } catch (AccountingException $failure) {
            $this->assertSame('ai_disabled', $failure->error->value);
        }
    }

    private function aiJournals(): int
    {
        return (int) $this->accountingDb()->table('accounting_journals')->where('source_type', ProposalService::SOURCE)->countAllResults();
    }
}
