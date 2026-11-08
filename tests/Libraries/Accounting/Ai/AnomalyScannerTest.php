<?php

namespace Tests\Libraries\Accounting\Ai;

use App\Libraries\Accounting\Ai\AnomalyContext;
use App\Libraries\Accounting\Ai\AnomalyRule;
use App\Libraries\Accounting\Ai\AnomalyScanner;
use App\Libraries\Accounting\Ai\Finding;
use App\Libraries\Accounting\Ai\FindingService;
use App\Libraries\Accounting\AccountingError;
use App\Libraries\Accounting\AccountingException;
use App\Libraries\Accounting\EntityAccountService;
use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\DatabaseTestTrait;
use Config\Database;
use DateTimeImmutable;
use Tests\Support\AiFixtureTrait;
use Tests\Support\DatabaseFingerprintTrait;

/**
 * The scan: it keeps what the rules find, once however often it runs, writes only the findings and its line in the
 * audit log, keeps what a person decided, and stops at once when the master switch is off.
 *
 * @internal
 */
final class AnomalyScannerTest extends CIUnitTestCase
{
    use DatabaseTestTrait;
    use AiFixtureTrait;
    use DatabaseFingerprintTrait;

    protected $migrate     = true;
    protected $migrateOnce = true;
    protected $refresh     = false;
    protected $namespace   = null;

    private AnomalyScanner $scanner;

    public static function setUpBeforeClass(): void
    {
        $seeder = Database::seeder('tests');
        $seeder->call('TestDatabaseBootstrapSeeder');
    }

    protected function setUp(): void
    {
        parent::setUp();

        $this->startAgent();
        $this->scanner = new AnomalyScanner();
    }

    protected function tearDown(): void
    {
        $this->stopAgent();

        parent::tearDown();
    }

    private function history(): void
    {
        $this->manualJournal('2026-10-10', $this->rentExpense(), $this->cashAccount(), '25000.00', 'Round one');

        $customer = $this->insertCustomer();
        $entities = new EntityAccountService();
        $entities->fill();
        $this->manualJournal('2026-10-11', (int) $entities->accountFor('customer', $customer), $this->accountId('sales_revenue'), '300.00', 'Adjust customer');

        $this->manualJournal('2026-10-12', $this->electricityExpense(), $this->cashAccount(), '75.00', 'Twice');
        $this->manualJournal('2026-10-13', $this->electricityExpense(), $this->cashAccount(), '75.00', 'Twice');
    }

    private function findingRows(): array
    {
        return $this->accountingDb()->table('accounting_ai_findings')->orderBy('finding_id')->get()->getResult();
    }

    private function refused(callable $call): AccountingException
    {
        try {
            $call();
        } catch (AccountingException $failure) {
            return $failure;
        }

        $this->fail('The call was not refused.');
    }

    public function testScan_WritesTheFindingsOfTheRulesThatFoundSomething(): void
    {
        $this->history();

        $report = $this->scanner->scan($this->agentEmployee(), null, '2020-01-01');

        $codes = array_map(static fn (object $row): string => $row->rule_code, $this->findingRows());

        $this->assertEqualsCanonicalizing(['AI-ROUND', 'AI-CONTROL-MANUAL', 'AI-DUPLICATE'], $codes);
        $this->assertSame(3, $report->createdCount());
        $this->assertSame(0, $report->existingCount());
        $this->assertSame(1, $report->created['AI-ROUND']);
        $this->assertSame(0, $report->created['AI-BACKDATED']);
        $this->assertSame('2020-01-01', $report->since);
    }

    public function testScan_StoresEachFindingOpenWithItsKeySeverityAndEvidence(): void
    {
        $this->history();
        $this->scanner->scan($this->agentEmployee(), null, '2020-01-01');

        $row = $this->accountingDb()->table('accounting_ai_findings')->where('rule_code', 'AI-ROUND')->get()->getRow();

        $this->assertSame('open', $row->status);
        $this->assertSame('low', $row->severity);
        $this->assertSame(hash('sha256', 'AI-ROUND|' . $row->subject_id), $row->finding_key);
        $this->assertSame('journal', $row->subject_type);
        $this->assertSame(1, (int) $row->branch_id);
        $this->assertSame('2026-10-10', $row->occurred_on);
        $this->assertNull($row->decided_by);
        $this->assertNull($row->decided_at);
        $this->assertNull($row->decision_reason);
        $this->assertSame('25000.0000', json_decode((string) $row->evidence, true)[0]['amount']);
        $this->assertSame('25000.0000', json_decode((string) $row->template_args, true)['amount']);
    }

    public function testScan_CreatesNoDuplicateWhenItRunsAgain(): void
    {
        $this->history();
        $employee = $this->agentEmployee();

        $this->scanner->scan($employee, null, '2020-01-01');

        $before = $this->findingRows();
        $again  = $this->scanner->scan($employee, null, '2020-01-01');

        $this->assertSame(0, $again->createdCount());
        $this->assertSame(3, $again->existingCount());
        $this->assertEquals($before, $this->findingRows());
    }

    public function testScan_WritesOnlyTheFindingsAndItsLineInTheAuditLog(): void
    {
        $this->history();

        $employee = $this->agentEmployee();
        $before   = $this->databaseFingerprint();

        $this->scanner->scan($employee, null, '2020-01-01');

        $after = $this->databaseFingerprint();

        $changed = array_keys(array_filter($after, static fn (array $print, string $table): bool => $print !== ($before[$table] ?? null), ARRAY_FILTER_USE_BOTH));

        $this->assertEqualsCanonicalizing(['ospos_accounting_ai_findings', 'ospos_accounting_audit_log'], $changed);
    }

    public function testScan_WritesOneAuditEntryWithTheFigures(): void
    {
        $this->history();
        $employee = $this->agentEmployee();

        $this->scanner->scan($employee, null, '2020-01-01');

        $entries = $this->accountingDb()->table('accounting_audit_log')->where('action', 'scan_run')->get()->getResult();

        $this->assertCount(1, $entries);
        $this->assertSame('ai_scan', $entries[0]->entity_type);
        $this->assertSame('ai', $entries[0]->origin);
        $this->assertSame($employee, (int) $entries[0]->employee_id);

        $details = json_decode((string) $entries[0]->details, true);

        $this->assertSame(3, $details['created']);
        $this->assertSame(0, $details['existing']);
        $this->assertSame('2020-01-01', $details['since']);
        $this->assertSame(1, $details['by_rule']['AI-ROUND']);
    }

    public function testScan_RefusesWhenTheAgentIsSwitchedOffAndWritesNothing(): void
    {
        $this->history();
        $this->setConfigValue('accounting_ai_enabled', '0');

        $employee = $this->agentEmployee();
        $before   = $this->databaseFingerprint();
        $failure  = $this->refused(fn () => $this->scanner->scan($employee, null, '2020-01-01'));

        $this->assertSame(AccountingError::AiDisabled, $failure->error);
        $this->assertSame($before, $this->databaseFingerprint());
    }

    public function testScan_RequiresThePermissionToUseTheAgent(): void
    {
        $this->history();

        $failure = $this->refused(fn () => $this->scanner->scan($this->employeeWithGrants([]), null, '2020-01-01'));

        $this->assertSame(AccountingError::PermissionDenied, $failure->error);
        $this->assertSame([], $this->findingRows());
    }

    public function testScan_AcceptsTheSystem(): void
    {
        $this->history();

        $this->scanner->scan(null, null, '2020-01-01');

        $this->assertCount(3, $this->findingRows());
        $this->assertNull($this->accountingDb()->table('accounting_audit_log')->where('action', 'scan_run')->get()->getRow()->employee_id);
    }

    public function testScan_KeepsWhatAPersonDecided(): void
    {
        $this->history();
        $employee = $this->agentEmployee();

        $this->scanner->scan($employee, null, '2020-01-01');

        $round = (int) $this->accountingDb()->table('accounting_ai_findings')->where('rule_code', 'AI-ROUND')->get()->getRow()->finding_id;
        (new FindingService())->decide($round, 'dismissed', 'Budgeted', $employee);

        $again = $this->scanner->scan($employee, null, '2020-01-01');

        $row = $this->accountingDb()->table('accounting_ai_findings')->where('finding_id', $round)->get()->getRow();

        $this->assertSame(0, $again->createdCount());
        $this->assertSame('dismissed', $row->status);
        $this->assertSame('Budgeted', $row->decision_reason);
        $this->assertCount(3, $this->findingRows());
    }

    public function testScan_LooksOnlyAtTheBranchesTheEmployeeWorksIn(): void
    {
        $branch = $this->createBranch();
        $this->manualJournal('2026-10-10', $this->rentExpense(), $this->cashAccount(), '25000.00', 'Round', null, $branch);

        $this->assertSame(0, $this->scanner->scan($this->agentEmployee([], [1]), null, '2020-01-01')->createdCount());
        $this->assertSame(1, $this->scanner->scan($this->agentEmployee(), null, '2020-01-01')->createdCount());
        $this->assertSame($branch, (int) $this->findingRows()[0]->branch_id);
    }

    public function testScan_UsesTheLookbackOfTheSettingForTheStartWhenNoneIsGiven(): void
    {
        $journal = $this->manualJournal('2026-10-10', $this->rentExpense(), $this->cashAccount(), '25000.00', 'Round');
        $this->postedAt($journal, date('Y-m-d H:i:s', strtotime('-30 days')));

        $this->setThreshold('lookback_days', '10');

        $this->assertSame(0, $this->scanner->scan($this->agentEmployee())->createdCount());

        $this->setThreshold('lookback_days', '40');

        $report = $this->scanner->scan($this->agentEmployee());

        $this->assertSame(1, $report->createdCount());
        $this->assertSame(date('Y-m-d', strtotime('-40 days')), $report->since);
    }

    public function testScan_RunsARuleThatWasAddedThroughTheInterface(): void
    {
        $rule = new class () implements AnomalyRule {
            public function code(): string
            {
                return 'AI-CUSTOM';
            }

            public function detect(AnomalyContext $context): array
            {
                return [new Finding('AI-CUSTOM', Finding::HIGH, 'one', 1, 'journal', 1, '2026-10-10', ['x' => 'y'], [['type' => 'journal', 'journal_id' => 1]])];
            }
        };

        $scanner = new AnomalyScanner(null, null, null, [$rule]);

        $this->assertSame(['AI-CUSTOM' => 1], $scanner->scan(null)->created);
        $this->assertSame(['AI-CUSTOM' => 1], $scanner->scan(null)->existing);
        $this->assertSame('AI-CUSTOM', $this->findingRows()[0]->rule_code);
    }

    public function testDefaults_ListsTheSevenRules(): void
    {
        $this->assertSame(
            ['AI-OFFHOURS', 'AI-ROUND', 'AI-DUPLICATE', 'AI-BALANCE-JUMP', 'AI-CONTROL-MANUAL', 'AI-REVERSAL-CHAIN', 'AI-BACKDATED'],
            array_map(static fn (AnomalyRule $rule): string => $rule->code(), AnomalyScanner::defaults()),
        );
    }

    public function testScan_AcceptsADateOfTodayThatWasGiven(): void
    {
        $journal = $this->manualJournal('2026-10-10', $this->rentExpense(), $this->cashAccount(), '25000.00', 'Round');
        $this->postedAt($journal, '2026-10-10 10:00:00');

        $this->assertSame('2026-07-12', $this->scanner->scan(null, new DateTimeImmutable('2026-10-10'))->since);
        $this->assertSame(1, $this->scanner->scan(null, new DateTimeImmutable('2026-10-10'))->existingCount());
    }
}
