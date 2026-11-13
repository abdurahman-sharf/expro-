<?php

namespace Tests\Libraries\Accounting\Ai;

use App\Libraries\Accounting\AccountingError;
use App\Libraries\Accounting\AccountingException;
use App\Libraries\Accounting\AccountingGuard;
use App\Libraries\Accounting\Ai\ProposalDraft;
use App\Libraries\Accounting\Ai\ProposalRunner;
use App\Libraries\Accounting\Ai\ProposalService;
use App\Libraries\Accounting\Ai\Proposer;
use App\Libraries\Accounting\JournalLineDraft;
use CodeIgniter\Events\Events;
use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\DatabaseTestTrait;
use Config\Database;
use DateTimeImmutable;
use Tests\Support\AiFixtureTrait;

/**
 * Running the proposers: they turn what they suggest into proposals one by one, a suggestion made already is left
 * alone so the run can be repeated, one the checks refuse is listed, the proposals past their time expire first,
 * and the master switch stops it. Nothing is posted.
 *
 * @internal
 */
final class ProposalRunnerTest extends CIUnitTestCase
{
    use DatabaseTestTrait;
    use AiFixtureTrait;

    protected $migrate     = true;
    protected $migrateOnce = true;
    protected $refresh     = false;
    protected $namespace   = null;
    private DateTimeImmutable $today;

    public static function setUpBeforeClass(): void
    {
        $seeder = Database::seeder('tests');
        $seeder->call('TestDatabaseBootstrapSeeder');
    }

    protected function setUp(): void
    {
        parent::setUp();

        $this->startAgent();
        $this->today = new DateTimeImmutable('2026-11-25');
    }

    protected function tearDown(): void
    {
        $this->stopAgent();

        parent::tearDown();
    }

    private function history(): void
    {
        foreach (['2026-08-05', '2026-09-05', '2026-10-05'] as $date) {
            $this->manualJournal($date, $this->rentExpense(), $this->cashAccount(), '2000.00', 'Office rent');
        }

        foreach (['2026-10-01', '2026-10-02'] as $date) {
            $this->manualJournal($date, $this->accountIdByCode('5204'), $this->cashAccount(), '100.00', 'Gulf Cleaning');
        }

        $this->manualJournal('2026-11-20', $this->otherExpenses(), $this->cashAccount(), '250.00', 'Gulf Cleaning');
    }

    /**
     * @param list<ProposalDraft> $drafts
     */
    private function fixed(array $drafts): Proposer
    {
        return new class ($drafts) implements Proposer {
            /**
             * @param list<ProposalDraft> $drafts
             */
            public function __construct(private array $drafts)
            {
            }

            public function kind(): string
            {
                return 'fixed';
            }

            public function name(): string
            {
                return 'rules:fixed';
            }

            public function propose(DateTimeImmutable $today, ?array $branchIds): array
            {
                return $this->drafts;
            }
        };
    }

    public function testRun_MakesTheProposalsOfBothProposersAndPostsNothing(): void
    {
        $this->history();

        $before = $this->journalCount();
        $report = (new ProposalRunner())->run($this->agentEmployee(), $this->today);

        $this->assertSame(['rules:reclassify' => 1, 'rules:recurring' => 1], $report->created);
        $this->assertSame(2, $report->createdCount());
        $this->assertSame(2, $this->countRows('accounting_ai_proposals'));
        $this->assertSame($before, $this->journalCount());
        $this->assertSame(0, (int) $this->accountingDb()->table('accounting_journals')->where('source_type', ProposalService::SOURCE)->countAllResults());
    }

    public function testRun_CanBeRepeatedAndCountsWhatWasStandingAlready(): void
    {
        $this->history();
        $runner   = new ProposalRunner();
        $employee = $this->agentEmployee();

        $runner->run($employee, $this->today);
        $again = $runner->run($employee, $this->today);

        $this->assertSame(0, $again->createdCount());
        $this->assertSame(2, $again->duplicateCount());
        $this->assertSame(2, $this->countRows('accounting_ai_proposals'));
    }

    public function testRun_ReportsWhatTheChecksRefusedWithTheReason(): void
    {
        $this->closeThrough('2026-11-30');

        $report = (new ProposalRunner(null, null, null, [$this->fixed([$this->proposalDraft('500.00', 'closed', '2026-11-10'), $this->proposalDraft('500.00', 'open', '2026-12-10')])]))->run(null, $this->today);

        $this->assertSame(1, $report->createdCount());
        $this->assertCount(1, $report->refused);
        $this->assertSame('rules:fixed', $report->refused[0]['proposer']);
        $this->assertSame('closed', $report->refused[0]['key']);
        $this->assertNotSame('', $report->refused[0]['reason']);
    }

    public function testRun_ListsASuggestionThatTheChecksOfTheServiceRefuseAndGoesOn(): void
    {
        $foreign = new ProposalDraft('recurring', 'rules:test', 'foreign', '2026-11-10', 1, 'x', [new JournalLineDraft($this->rentExpense(), debit: '10.00', foreignAmount: '3.00', currencyCode: 'USD'), new JournalLineDraft($this->cashAccount(), credit: '10.00')], [['type' => 'journal', 'journal_id' => 1]], []);

        $report = (new ProposalRunner(null, null, null, [$this->fixed([$foreign, $this->proposalDraft('500.00', 'fine')])]))->run(null, $this->today);

        $this->assertSame(1, $report->createdCount());
        $this->assertCount(1, $report->refused);
        $this->assertSame('foreign', $report->refused[0]['key']);
        $this->assertNotSame('', $report->refused[0]['reason']);
    }

    public function testRun_RunsAProposerThatWasAddedThroughTheInterface(): void
    {
        $report = (new ProposalRunner(null, null, null, [$this->fixed([$this->proposalDraft('500.00', 'added')])]))->run(null, $this->today);

        $this->assertSame(['rules:fixed' => 1], $report->created);
        $this->assertSame('rules:test', $this->accountingDb()->table('accounting_ai_proposals')->get()->getRow()->proposer);
    }

    public function testRun_ExpiresTheProposalsPastTheirTimeFirstAndMakesTheSameOneAgain(): void
    {
        $runner   = new ProposalRunner(null, null, null, [$this->fixed([$this->proposalDraft('500.00', 'again')])]);
        $employee = $this->agentEmployee();

        $runner->run($employee, $this->today);
        $this->accountingDb()->table('accounting_ai_proposals')->update(['expires_at' => '2020-01-01 00:00:00']);

        $report = $runner->run($employee, $this->today);

        $this->assertSame(1, $report->expired);
        $this->assertSame(1, $report->createdCount());
        $this->assertSame(['expired', 'proposed'], array_column($this->accountingDb()->table('accounting_ai_proposals')->orderBy('proposal_id')->get()->getResultArray(), 'status'));
    }

    public function testRun_RefusesWhenTheAgentIsSwitchedOffAndWritesNothing(): void
    {
        $this->history();
        $this->setConfigValue('accounting_ai_enabled', '0');

        try {
            (new ProposalRunner())->run($this->agentEmployee(), $this->today);
            $this->fail('The run was not refused.');
        } catch (AccountingException $failure) {
            $this->assertSame(AccountingError::AiDisabled, $failure->error);
        }

        $this->assertSame(0, $this->countRows('accounting_ai_proposals'));
        $this->assertSame(0, (int) $this->accountingDb()->table('accounting_audit_log')->where('action', 'proposers_run')->countAllResults());
    }

    public function testRun_ExpiresNothingAndWritesNothingWhenTheAgentIsSwitchedOffAndNothingIsSuggested(): void
    {
        $id = (new ProposalService())->create($this->proposalDraft('500.00', 'late'), $this->agentEmployee());
        $this->accountingDb()->table('accounting_ai_proposals')->where('proposal_id', $id)->update(['expires_at' => '2020-01-01 00:00:00']);
        $this->setConfigValue('accounting_ai_enabled', '0');

        try {
            (new ProposalRunner(null, null, null, [$this->fixed([])]))->run($this->agentEmployee(), $this->today);
            $this->fail('The run was not refused.');
        } catch (AccountingException $failure) {
            $this->assertSame(AccountingError::AiDisabled, $failure->error);
        }

        $this->assertSame('proposed', $this->accountingDb()->table('accounting_ai_proposals')->where('proposal_id', $id)->get()->getRow()->status);
        $this->assertSame(0, (int) $this->accountingDb()->table('accounting_audit_log')->where('action', 'proposers_run')->countAllResults());
    }

    public function testRun_StopsAtOnceWhenTheAgentIsSwitchedOffWhileItRuns(): void
    {
        $this->history();

        $off      = false;
        $employee = $this->agentEmployee();
        $listener = function ($query) use (&$off): void {
            if (! $off && str_contains((string) $query->getQuery(), 'INSERT INTO `ospos_accounting_ai_proposals`')) {
                $off = true;
                $this->setConfigValue('accounting_ai_enabled', '0');
            }
        };

        Events::on('DBQuery', $listener);

        try {
            (new ProposalRunner())->run($employee, $this->today);
            $this->fail('The run went on after the switch was turned off.');
        } catch (AccountingException $failure) {
            $this->assertSame(AccountingError::AiDisabled, $failure->error);
        } finally {
            Events::removeListener('DBQuery', $listener);
        }

        $this->assertSame(1, $this->countRows('accounting_ai_proposals'));
    }

    public function testRun_RequiresThePermissionToUseTheAgent(): void
    {
        $this->expectExceptionObject(new AccountingException(AccountingError::PermissionDenied, 'Missing permission ' . AccountingGuard::AI_USE . '.', ['permission' => AccountingGuard::AI_USE]));

        (new ProposalRunner())->run($this->employeeWithGrants([AccountingGuard::JOURNALS_POST]), $this->today);
    }

    public function testRun_WritesOneAuditEntryWithTheFigures(): void
    {
        $this->history();

        (new ProposalRunner())->run($this->agentEmployee(), $this->today);

        $entries = $this->accountingDb()->table('accounting_audit_log')->where('action', 'proposers_run')->get()->getResult();

        $this->assertCount(1, $entries);
        $this->assertSame('ai', $entries[0]->origin);
        $this->assertSame(['expired' => 0, 'created' => 2, 'duplicates' => 0, 'refused' => 0], json_decode((string) $entries[0]->details, true));
    }

    public function testRun_LooksOnlyAtTheBranchesTheEmployeeWorksIn(): void
    {
        $branch = $this->createBranch();

        foreach (['2026-08-05', '2026-09-05', '2026-10-05'] as $date) {
            $this->manualJournal($date, $this->rentExpense(), $this->cashAccount(), '2000.00', 'Office rent', null, $branch);
        }

        $local = (new ProposalRunner())->run($this->agentEmployee([], [1]), $this->today);
        $all   = (new ProposalRunner())->run($this->agentEmployee(), $this->today);

        $this->assertSame(0, $local->createdCount());
        $this->assertSame([], $local->refused);
        $this->assertSame(1, $all->createdCount());
    }

    public function testDefaults_ListsTheTwoRuleBasedProposers(): void
    {
        $this->assertSame(['rules:reclassify', 'rules:recurring'], array_map(static fn (Proposer $proposer): string => $proposer->name(), ProposalRunner::defaults()));
        $this->assertSame(['reclassify', 'recurring'], array_map(static fn (Proposer $proposer): string => $proposer->kind(), ProposalRunner::defaults()));
    }
}
