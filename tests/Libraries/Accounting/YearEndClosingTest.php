<?php

namespace Tests\Libraries\Accounting;

use App\Libraries\Accounting\AccountingError;
use App\Libraries\Accounting\AccountingException;
use App\Libraries\Accounting\AccountingGuard;
use App\Libraries\Accounting\AccountType;
use App\Libraries\Accounting\BalanceSheet;
use App\Libraries\Accounting\BalanceSheetReport;
use App\Libraries\Accounting\ClosedPeriodService;
use App\Libraries\Accounting\CostCenterService;
use App\Libraries\Accounting\IncomeStatement;
use App\Libraries\Accounting\JournalDraft;
use App\Libraries\Accounting\JournalEntryType;
use App\Libraries\Accounting\JournalLineDraft;
use App\Libraries\Accounting\JournalService;
use App\Libraries\Accounting\JournalSettings;
use App\Libraries\Accounting\JournalStatus;
use App\Libraries\Accounting\PostingError;
use App\Libraries\Accounting\PostingException;
use App\Libraries\Accounting\PostingService;
use App\Libraries\Accounting\ReportPeriod;
use App\Libraries\Accounting\ReportScope;
use App\Libraries\Accounting\TrialBalance;
use App\Libraries\Accounting\TrialBalanceClosing;
use App\Libraries\Accounting\YearEndBranchStatus;
use App\Libraries\Accounting\YearEndClosing;
use App\Libraries\Accounting\YearEndEntries;
use App\Libraries\Organization\BranchError;
use App\Libraries\Organization\BranchException;
use CodeIgniter\Database\BaseConnection;
use CodeIgniter\Events\Events;
use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\DatabaseTestTrait;
use Config\Database;
use Tests\Support\AccountingFixtureTrait;
use Tests\Support\ConcurrentDbRaceTrait;
use Tests\Support\LockOrderTrait;
use Tests\Support\ReportFixtureTrait;

/**
 * Closing a year in the books: what the preview shows, the one journal each branch gets and the way
 * it is approved and taken back, what stops it, and that the statements read the same before it and
 * after it.
 *
 * @internal
 */
final class YearEndClosingTest extends CIUnitTestCase
{
    use DatabaseTestTrait;
    use AccountingFixtureTrait;
    use ConcurrentDbRaceTrait;
    use LockOrderTrait;
    use ReportFixtureTrait;

    protected $migrate     = true;
    protected $migrateOnce = true;
    protected $refresh     = false;
    protected $namespace;
    private YearEndClosing $service;
    private ?BaseConnection $other = null;
    private int $second;
    private int $employee;

    public static function setUpBeforeClass(): void
    {
        $seeder = Database::seeder('tests');
        $seeder->call('TestDatabaseBootstrapSeeder');
    }

    protected function setUp(): void
    {
        parent::setUp();

        $this->resetBranches();
        $this->reinstallChart();
        $this->setConfigValue('accounting_base_currency', 'SAR');

        $this->second   = $this->createBranch('T2', 'Second branch');
        $this->employee = $this->closer();
        $this->service  = new YearEndClosing();
    }

    protected function tearDown(): void
    {
        if ($this->other !== null) {
            $this->other->transRollback();
            $this->other->close();
            $this->other = null;
        }

        $this->releaseOpenTransactions();
        $this->clearJournals();
        $this->reinstallChart();
        $this->resetBranches();

        parent::tearDown();
    }

    /**
     * @param list<int>|null $branchIds
     */
    private function closer(?array $branchIds = null): int
    {
        return $this->employeeWithGrants([AccountingGuard::YEAR_END_CLOSE], $branchIds);
    }

    private function yearId(string $name = '2025'): int
    {
        return (int) $this->accountingDb()->table('accounting_fiscal_years')->where('name', $name)->get()->getRow()->fiscal_year_id;
    }

    /**
     * Branch 1 earns 250 in 2025 (sales 1,000, cost of sales 600, expenses 150) and branch 2 loses
     * 200 (sales 300, expenses 500).
     */
    private function twoBranchYear(): void
    {
        $cash = $this->accountId('cash');

        $this->postOn('2025-03-10', 1, [[$cash, '1000.00', '0'], [$this->accountId('sales_revenue'), '0', '1000.00']]);
        $this->postOn('2025-04-10', 1, [[$this->accountId('cogs'), '600.00', '0'], [$this->accountId('purchases'), '0', '600.00']]);
        $this->postOn('2025-05-10', 1, [[$this->accountId('expenses_default'), '150.00', '0'], [$cash, '0', '150.00']]);
        $this->postOn('2025-03-12', $this->second, [[$cash, '300.00', '0'], [$this->accountId('sales_revenue'), '0', '300.00']]);
        $this->postOn('2025-06-12', $this->second, [[$this->accountId('expenses_default'), '500.00', '0'], [$cash, '0', '500.00']]);
    }

    /**
     * @param list<array{int, string, string}> $lines
     */
    private function postYearEnd(string $date, int $branchId, array $lines): int
    {
        return (new PostingService())->postYearEnd(new JournalDraft(
            date: $date,
            lines: array_map(static fn (array $line): JournalLineDraft => new JournalLineDraft($line[0], debit: $line[1], credit: $line[2]), $lines),
            sourceType: YearEndEntries::SOURCE_TYPE,
            sourceId: 900001,
            currencyCode: 'SAR',
            branchId: $branchId,
            entryType: JournalEntryType::YearEnd,
        ))->journalId;
    }

    private function closePeriods(string $date = '2025-12-31'): void
    {
        $this->closeThrough($date);
    }

    /**
     * @return list<int>
     */
    private function close(?int $branchId = null, ?int $employee = null, string $year = '2025'): array
    {
        $employee ??= $this->employee;
        $preview = $this->service->preview($branchId, $this->yearId($year), $employee);

        return $this->service->approve($branchId, $this->yearId($year), $preview->fingerprint, $employee);
    }

    private function closeBoth(): void
    {
        $this->twoBranchYear();
        $this->closePeriods();
        $this->close();
    }

    private function refusal(callable $action): AccountingException
    {
        try {
            $action();
        } catch (AccountingException $exception) {
            return $exception;
        }

        $this->fail('Expected the books to refuse.');
    }

    private function assertRefused(AccountingError $error, callable $action): AccountingException
    {
        $exception = $this->refusal($action);

        $this->assertSame($error, $exception->error, $exception->getMessage());

        return $exception;
    }

    private function counts(): array
    {
        return [
            'journals' => $this->countRows('accounting_journals'),
            'lines'    => $this->countRows('accounting_journal_lines'),
            'closings' => $this->countRows('accounting_year_end_closings'),
            'audit'    => $this->countRows('accounting_audit_log'),
        ];
    }

    private function closingOf(int $branchId, string $year = '2025', string $status = 'active'): ?object
    {
        return $this->accountingDb()->table('accounting_year_end_closings')->where('branch_id', $branchId)->where('fiscal_year_id', $this->yearId($year))->where('status', $status)->get()->getRow();
    }

    /**
     * @return array<int, array{debit: string, credit: string}> The lines of a journal by account.
     */
    private function linesOf(int $journalId): array
    {
        $lines = [];

        foreach ($this->accountingDb()->table('accounting_journal_lines')->where('journal_id', $journalId)->orderBy('line_no')->get()->getResult() as $line) {
            $lines[(int) $line->account_id] = ['debit' => bcadd((string) $line->debit, '0', 4), 'credit' => bcadd((string) $line->credit, '0', 4)];
        }

        return $lines;
    }

    /**
     * @return list<array{string, string, string}> The center, percent and amount of every distribution row of a journal, in line order.
     */
    private function distributionOf(int $journalId): array
    {
        $rows = $this->accountingDb()->table('accounting_journal_line_distributions d')
            ->select('d.cost_center_id, d.percent, d.amount')
            ->join('accounting_journal_lines l', 'l.line_id = d.line_id')
            ->where('l.journal_id', $journalId)
            ->orderBy('l.line_no')
            ->orderBy('d.distribution_id')
            ->get()
            ->getResultArray();

        return array_map(static fn (array $row): array => [(string) $row['cost_center_id'], $row['percent'], $row['amount']], $rows);
    }

    /**
     * @return list<string> The cost centers named by the lines of a journal themselves.
     */
    private function centersOf(int $journalId): array
    {
        return array_map('strval', array_column($this->accountingDb()->table('accounting_journal_lines')->select('cost_center_id')->where('journal_id', $journalId)->where('cost_center_id IS NOT NULL', null, false)->orderBy('line_no')->get()->getResultArray(), 'cost_center_id'));
    }

    private function journal(int $journalId): object
    {
        return $this->accountingDb()->table('accounting_journals')->where('journal_id', $journalId)->get()->getRow();
    }

    /**
     * Debit less credit of an account in the books up to the day, in a branch or in all of them.
     */
    private function balance(int $accountId, ?int $branchId = null, string $to = '2099-12-31'): string
    {
        $builder = $this->accountingDb()->table('accounting_journal_lines l')
            ->select('COALESCE(SUM(l.debit - l.credit), 0) AS net', false)
            ->join('accounting_journals j', 'j.journal_id = l.journal_id')
            ->whereIn('j.status', ['posted', 'reversed'])
            ->where('l.account_id', $accountId)
            ->where('j.journal_date <=', $to);

        if ($branchId !== null) {
            $builder->where('j.branch_id', $branchId);
        }

        return bcadd((string) $builder->get()->getRow()->net, '0', 4);
    }

    private function company(): ReportScope
    {
        return ReportScope::resolve(null, null);
    }

    private function sheet(string $asOf, ?ReportScope $scope = null): BalanceSheetReport
    {
        return (new BalanceSheet())->build($scope ?? $this->company(), $asOf, 5);
    }

    private function assertEquation(BalanceSheetReport $sheet, string $message = ''): void
    {
        $this->assertSame($sheet->assets->total, $sheet->liabilitiesAndEquity(), 'Assets = liabilities + equity. ' . $message);
        $this->assertTrue($sheet->balances(), $message);
    }

    /**
     * @return array<string, array{opening: string, debit: string, credit: string}>
     */
    private function trial(TrialBalanceClosing $closing, ?ReportScope $scope = null, string $to = '2025-12-31'): array
    {
        $rows = [];

        foreach ((new TrialBalance())->build($scope ?? $this->company(), ReportPeriod::of(null, $to), closing: $closing)->rows as $row) {
            $rows[$row->code] = ['opening' => $row->opening, 'debit' => $row->debit, 'credit' => $row->credit];
        }

        return $rows;
    }

    /**
     * @return array<string, string> What each account stands at in a trial balance, debit less credit, the accounts at nothing left out.
     */
    private function standing(TrialBalanceClosing $closing): array
    {
        $net = [];

        foreach ($this->trial($closing) as $code => $row) {
            $amount = bcsub($row['debit'], $row['credit'], 4);

            if (bccomp($amount, '0', 4) !== 0) {
                $net[(string) $code] = $amount;
            }
        }

        return $net;
    }

    private function auditActions(string $entityType): array
    {
        return array_column($this->accountingDb()->table('accounting_audit_log')->where('entity_type', $entityType)->orderBy('audit_id')->get()->getResultArray(), 'action');
    }

    public function testPreview_ListsTheRevenueAndExpenseAccountsOfEachBranchWithWhatClosesThem(): void
    {
        $this->twoBranchYear();
        $this->closePeriods();

        $preview = $this->service->preview(null, $this->yearId(), $this->employee);

        $this->assertTrue($preview->forCompany);
        $this->assertSame([1, $this->second], array_map(static fn ($plan): int => $plan->branchId, $preview->branches));

        [$first, $other] = $preview->branches;

        $this->assertSame(YearEndBranchStatus::Ready, $first->status);
        $this->assertSame('1000.0000', $first->revenue);
        $this->assertSame('750.0000', $first->expense);
        $this->assertSame('250.0000', $first->result);
        $this->assertTrue($first->isProfit());
        $this->assertSame(['411', '511', '541'], array_map(static fn ($line): string => $line->code, $first->lines));

        $sales = $first->lines[0];

        $this->assertSame(AccountType::Revenue, $sales->type);
        $this->assertSame('-1000.0000', $sales->net, 'A revenue that kept its credit balance is a negative net');
        $this->assertSame('1000.0000', $sales->closingDebit());
        $this->assertSame('0.0000', $sales->closingCredit());

        $this->assertSame('-200.0000', $other->result);
        $this->assertTrue($other->isLoss());
        $this->assertTrue($preview->canApprove());
        $this->assertSame([], $preview->blockers);
        $this->assertSame($this->accountId('retained_earnings'), (int) $preview->retainedAccount->account_id);
        $this->assertSame(64, strlen($preview->fingerprint));
    }

    public function testPreview_WritesNothing(): void
    {
        $this->twoBranchYear();
        $this->closePeriods();
        $counts = $this->counts();

        $this->service->preview(null, $this->yearId(), $this->employee);
        $this->service->preview(1, $this->yearId(), $this->employee);

        $this->assertSame($counts, $this->counts());
    }

    public function testPreview_RequiresThePermissionToCloseTheYear(): void
    {
        $this->twoBranchYear();

        $nobody = $this->employeeWithGrants([AccountingGuard::PERIODS_CLOSE, AccountingGuard::JOURNALS_POST]);

        $this->assertRefused(AccountingError::PermissionDenied, fn () => $this->service->preview(null, $this->yearId(), $nobody));
        $this->assertRefused(AccountingError::PermissionDenied, fn () => $this->service->preview(1, $this->yearId(), $nobody));
    }

    public function testPreview_RejectsABranchTheEmployeeDoesNotWorkIn(): void
    {
        $this->twoBranchYear();
        $limited = $this->closer([1]);

        $this->assertSame(1, $this->service->preview(1, $this->yearId(), $limited)->branches[0]->branchId);

        try {
            $this->service->preview($this->second, $this->yearId(), $limited);
            $this->fail('A branch outside the scope must be refused.');
        } catch (BranchException $exception) {
            $this->assertSame(BranchError::NotAllowed, $exception->error);
        }
    }

    public function testPreview_RejectsACompanyRunForAnEmployeeLimitedToSomeBranches(): void
    {
        $this->twoBranchYear();
        $limited = $this->closer([1]);

        try {
            $this->service->preview(null, $this->yearId(), $limited);
            $this->fail('A company run needs every branch.');
        } catch (BranchException $exception) {
            $this->assertSame(BranchError::CompanyScopeNeeded, $exception->error);
        }
    }

    public function testPreview_RejectsABranchThatDoesNotExistAndAYearThatDoesNotExist(): void
    {
        try {
            $this->service->preview(999, $this->yearId(), $this->employee);
            $this->fail('An unknown branch must be refused.');
        } catch (BranchException $exception) {
            $this->assertSame(BranchError::NotFound, $exception->error);
        }

        $this->assertRefused(AccountingError::YearEndYearNotFound, fn () => $this->service->preview(null, 99999, $this->employee));
    }

    public function testPreview_NamesThePeriodsOfTheCompanyThatAreNotClosedThroughTheEndOfTheYear(): void
    {
        $this->twoBranchYear();

        $preview = $this->service->preview(null, $this->yearId(), $this->employee);

        $this->assertFalse($preview->canApprove());
        $this->assertSame(AccountingError::YearEndPeriodNotClosed, $preview->blockers[0]->error);
        $this->assertSame(['name' => '2025', 'date' => '2025-12-31'], $preview->blockers[0]->context);

        $this->closeThrough('2025-12-30');

        $this->assertSame(AccountingError::YearEndPeriodNotClosed, $this->service->preview(null, $this->yearId(), $this->employee)->blockers[0]->error, 'One day short is not closed through the end');

        $this->closeThrough('2025-12-31');

        $this->assertSame([], $this->service->preview(null, $this->yearId(), $this->employee)->blockers);
    }

    public function testPreview_IgnoresTheClosingOfOneBranchWhenTheCompanyHasNotClosed(): void
    {
        $this->twoBranchYear();
        $this->closeThrough('2025-12-31', 1);

        $this->assertSame(AccountingError::YearEndPeriodNotClosed, $this->service->preview(1, $this->yearId(), $this->employee)->blockers[0]->error);
    }

    public function testPreview_CountsTheDraftsOfTheYearInTheBranchesOfTheRun(): void
    {
        $this->twoBranchYear();
        $this->closePeriods();

        $journals = new JournalService();
        $journals->saveDraft(['date' => '2025-06-01', 'branch_id' => $this->second] + $this->journalInput('10.00'), null);
        $journals->saveDraft(['date' => '2024-06-01', 'branch_id' => 1] + $this->journalInput('10.00'), null);
        $journals->saveDraft(['date' => '2026-06-01', 'branch_id' => 1] + $this->journalInput('10.00'), null);

        $company = $this->service->preview(null, $this->yearId(), $this->employee);

        $this->assertSame(1, $company->draftCount, 'Only the draft dated in the year counts');
        $this->assertSame(AccountingError::YearEndHasDrafts, $company->blockers[0]->error);
        $this->assertSame(['name' => '2025', 'count' => 1], $company->blockers[0]->context);
        $this->assertSame(0, $this->service->preview(1, $this->yearId(), $this->employee)->draftCount, 'The draft of the other branch is not in the run of branch 1');
        $this->assertSame(1, $this->service->preview($this->second, $this->yearId(), $this->employee)->draftCount);
    }

    public function testPreview_MarksABranchThatMovedNothingAsHavingNothingToClose(): void
    {
        $this->postOn('2025-03-10', 1, [[$this->accountId('cash'), '40.00', '0'], [$this->accountId('sales_revenue'), '0', '40.00']]);
        $this->closePeriods();

        $preview = $this->service->preview(null, $this->yearId(), $this->employee);

        $this->assertSame([YearEndBranchStatus::Ready, YearEndBranchStatus::NoActivity], array_map(static fn ($plan): YearEndBranchStatus => $plan->status, $preview->branches));
        $this->assertSame([], $preview->branches[1]->lines);
        $this->assertSame('0.0000', $preview->branches[1]->result);
        $this->assertTrue($preview->canApprove());
    }

    public function testPreview_LeavesOutAnAccountWhoseDebitsAndCreditsCancel(): void
    {
        $sales = $this->accountId('sales_revenue');
        $cash  = $this->accountId('cash');

        $this->postOn('2025-03-10', 1, [[$cash, '70.00', '0'], [$sales, '0', '70.00']]);
        $this->postOn('2025-03-11', 1, [[$sales, '70.00', '0'], [$cash, '0', '70.00']]);
        $this->closePeriods();

        $preview = $this->service->preview(1, $this->yearId(), $this->employee);

        $this->assertSame(YearEndBranchStatus::NoActivity, $preview->branches[0]->status);
        $this->assertSame([], $preview->branches[0]->lines);
    }

    public function testPreview_ReadsTheMovementOfTheYearAndNotWhatTheAccountsCarriedFromEarlierYears(): void
    {
        $sales = $this->accountId('sales_revenue');
        $cash  = $this->accountId('cash');

        $this->postOn('2024-06-10', 1, [[$cash, '500.00', '0'], [$sales, '0', '500.00']]);
        $this->postOn('2025-06-10', 1, [[$cash, '120.00', '0'], [$sales, '0', '120.00']]);
        $this->closePeriods();

        $year2025 = $this->service->preview(1, $this->yearId('2025'), $this->employee)->branches[0];
        $year2024 = $this->service->preview(1, $this->yearId('2024'), $this->employee)->branches[0];

        $this->assertSame('120.0000', $year2025->result, 'The books held 620 in the revenue account, and 2025 closes the 120 it moved');
        $this->assertSame('500.0000', $year2024->result);
    }

    public function testPreview_IgnoresTheLinesOfADraftWhichOnlyBlocksTheClosing(): void
    {
        $this->twoBranchYear();
        $this->closePeriods();
        (new JournalService())->saveDraft(['date' => '2025-07-01', 'branch_id' => 1] + $this->journalInput('70.00'), null);

        $preview = $this->service->preview(1, $this->yearId(), $this->employee);

        $this->assertSame('1000.0000', $preview->branches[0]->revenue, 'The 70 of the draft is not in the books');
        $this->assertSame(1, $preview->draftCount);
    }

    public function testPreview_DoesNotCountAJournalThatClosesTheYearAsMovementOfTheYear(): void
    {
        $this->twoBranchYear();
        $this->postYearEnd('2025-12-31', 1, [[$this->accountId('sales_revenue'), '1000.00', '0'], [$this->accountId('retained_earnings'), '0', '1000.00']]);
        $this->closePeriods();

        $preview = $this->service->preview(1, $this->yearId(), $this->employee);

        $this->assertSame('1000.0000', $preview->branches[0]->revenue, 'A closing written by other means is not a sale');
        $this->assertSame('250.0000', $preview->branches[0]->result);
    }

    public function testApprove_SeesAClosingThatCommittedAfterTheSnapshotOfTheCaller(): void
    {
        $this->twoBranchYear();
        $this->closePeriods();
        $preview = $this->service->preview(1, $this->yearId(), $this->employee);
        $this->other = $this->openSecondConnection();
        $this->startWithSnapshot($this->other);

        $this->service->approve(1, $this->yearId(), $preview->fingerprint, $this->employee);

        try {
            (new YearEndClosing($this->other))->approve(1, $this->yearId(), $preview->fingerprint, $this->employee);
            $this->fail('The year was closed after the snapshot of the second request.');
        } catch (AccountingException $exception) {
            $this->assertSame(AccountingError::YearEndAlreadyClosed, $exception->error);
        } finally {
            $this->other->transRollback();
            $this->other->close();
            $this->other = null;
        }

        $this->assertSame(1, $this->accountingDb()->table('accounting_journals')->where('source_type', 'year_end')->countAllResults());
    }

    public function testApprove_SeesThePeriodsThatWereReopenedAfterTheSnapshotOfTheCaller(): void
    {
        $this->twoBranchYear();
        $this->closePeriods();
        $preview = $this->service->preview(1, $this->yearId(), $this->employee);
        $this->other = $this->openSecondConnection();
        $this->startWithSnapshot($this->other);

        $this->accountingDb()->table('accounting_closed_periods')->update(['active' => 0]);

        try {
            (new YearEndClosing($this->other))->approve(1, $this->yearId(), $preview->fingerprint, $this->employee);
            $this->fail('The periods were reopened after the snapshot of the second request.');
        } catch (AccountingException $exception) {
            $this->assertSame(AccountingError::YearEndPeriodNotClosed, $exception->error);
        } finally {
            $this->other->transRollback();
            $this->other->close();
            $this->other = null;
        }

        $this->assertSame(0, $this->countRows('accounting_year_end_closings'));
    }

    public function testPreview_ReadsAReversedJournalWithItsReversalSoItLeavesNothingToClose(): void
    {
        $sale = $this->cashSale('2025-03-10', 1, '90.00');
        $this->reverseOn($sale, '2025-03-20');
        $this->closePeriods();

        $this->assertSame(YearEndBranchStatus::NoActivity, $this->service->preview(1, $this->yearId(), $this->employee)->branches[0]->status);
    }

    public function testPreview_ReportsTheRetainedEarningsAccountOfTheRoutingAsMissingWhenItIsGone(): void
    {
        $this->twoBranchYear();
        $this->closePeriods();
        $this->accountingDb()->table('accounting_accounts')->where('account_id', $this->accountId('retained_earnings'))->update(['active' => 0]);

        $preview = $this->service->preview(null, $this->yearId(), $this->employee);

        $this->assertFalse($preview->canApprove());
        $this->assertNull($preview->retainedAccount);
        $this->assertSame(AccountingError::YearEndRetainedEarningsUnset, $preview->blockers[0]->error);
    }

    public function testPreview_ReportsAnAccountOfTheRoutingThatIsNotEquityAsMissing(): void
    {
        $this->twoBranchYear();
        $this->closePeriods();
        $this->accountingDb()->table('accounting_accounts')->where('account_id', $this->accountId('retained_earnings'))->update(['account_type' => 'liability']);

        $this->assertSame(AccountingError::YearEndRetainedEarningsUnset, $this->service->preview(null, $this->yearId(), $this->employee)->blockers[0]->error);
    }

    public function testPreview_TakesTheRetainedEarningsAccountFromTheRouting(): void
    {
        $this->twoBranchYear();
        $this->closePeriods();

        $other = $this->customAccount('3', '39', 'Other equity');
        $this->accountingDb()->table('accounting_accounts')->where('account_id', $other)->update(['account_type' => 'equity', 'normal_balance' => 'credit']);
        $this->accountingDb()->table('accounting_routing')->where('routing_key', 'fiscal.retained_earnings')->update(['account_id' => $other]);

        $preview = $this->service->preview(null, $this->yearId(), $this->employee);

        $this->assertSame($other, (int) $preview->retainedAccount->account_id);

        $this->service->approve(null, $this->yearId(), $preview->fingerprint, $this->employee);

        $this->assertSame('-250.0000', $this->balance($other, 1));
        $this->assertSame('0.0000', $this->balance($this->accountId('retained_earnings')));
    }

    public function testPreview_MarksABranchWhoseYearIsAlreadyClosed(): void
    {
        $this->twoBranchYear();
        $this->closePeriods();
        $this->close(1);

        $preview = $this->service->preview(null, $this->yearId(), $this->employee);

        $this->assertSame(YearEndBranchStatus::AlreadyClosed, $preview->branches[0]->status);
        $this->assertSame('250.0000', $preview->branches[0]->result);
        $this->assertNotNull($preview->branches[0]->closing);
        $this->assertSame(YearEndBranchStatus::Ready, $preview->branches[1]->status);
        $this->assertCount(1, $preview->ready());
    }

    public function testApprove_WritesOneJournalForTheBranchThatMovesTheProfitToRetainedEarnings(): void
    {
        $this->twoBranchYear();
        $this->closePeriods();

        $ids     = $this->close(1);
        $closing = $this->closingOf(1);

        $this->assertSame([(int) $closing->closing_id], $ids);

        $journal = $this->journal((int) $closing->journal_id);

        $this->assertSame('2025-12-31', $journal->journal_date);
        $this->assertSame('year_end', $journal->source_type);
        $this->assertSame((int) $closing->closing_id, (int) $journal->source_id);
        $this->assertSame(JournalEntryType::YearEnd->value, $journal->entry_type);
        $this->assertSame(JournalStatus::Posted->value, $journal->status);
        $this->assertSame('1', (string) $journal->branch_id);
        $this->assertSame('user', $journal->origin);
        $this->assertSame((string) $this->employee, (string) $journal->posted_by);
        $this->assertSame('1000.0000', $journal->total_debit);
        $this->assertSame('1000.0000', $journal->total_credit);

        $this->assertSame([
            $this->accountId('sales_revenue')     => ['debit' => '1000.0000', 'credit' => '0.0000'],
            $this->accountId('cogs')              => ['debit' => '0.0000', 'credit' => '600.0000'],
            $this->accountId('expenses_default')  => ['debit' => '0.0000', 'credit' => '150.0000'],
            $this->accountId('retained_earnings') => ['debit' => '0.0000', 'credit' => '250.0000'],
        ], $this->linesOf((int) $journal->journal_id));

        $this->assertSame('250.0000', $closing->result_amount);
        $this->assertSame('2025-01-01', $closing->period_from);
        $this->assertSame('2025-12-31', $closing->period_to);
        $this->assertSame($this->accountId('retained_earnings'), (int) $closing->retained_account_id);
        $this->assertSame('1', (string) $closing->revision);
        $this->assertSame(1, (int) $closing->active_key);
    }

    public function testApprove_LeavesTheBalancesOfTheRevenueAndExpenseAccountsAtNothingAndRetainedEarningsHoldingTheResult(): void
    {
        $this->twoBranchYear();
        $this->closePeriods();

        $this->close(1);

        foreach (['sales_revenue', 'cogs', 'expenses_default'] as $key) {
            $this->assertSame('0.0000', $this->balance($this->accountId($key), 1), $key);
        }

        $this->assertSame('-250.0000', $this->balance($this->accountId('retained_earnings'), 1));
        $this->assertSame('-300.0000', $this->balance($this->accountId('sales_revenue'), $this->second), 'The other branch is untouched');
    }

    public function testApprove_PostsALossAsADebitToRetainedEarnings(): void
    {
        $this->twoBranchYear();
        $this->closePeriods();

        $this->close($this->second);

        $lines = $this->linesOf((int) $this->closingOf($this->second)->journal_id);

        $this->assertSame(['debit' => '200.0000', 'credit' => '0.0000'], $lines[$this->accountId('retained_earnings')]);
        $this->assertSame(['debit' => '300.0000', 'credit' => '0.0000'], $lines[$this->accountId('sales_revenue')]);
        $this->assertSame(['debit' => '0.0000', 'credit' => '500.0000'], $lines[$this->accountId('expenses_default')]);
        $this->assertSame('200.0000', $this->balance($this->accountId('retained_earnings'), $this->second));
        $this->assertSame('-200.0000', $this->closingOf($this->second)->result_amount);
    }

    public function testApprove_ClosesABalanceThatIsOppositeToTheNatureOfItsAccountByItsOwnSign(): void
    {
        $returns = $this->accountId('sales_returns');
        $sales   = $this->accountId('sales_revenue');
        $rent    = $this->accountId('expenses_default');
        $cash    = $this->accountId('cash');

        $this->postOn('2025-03-10', 1, [[$returns, '80.00', '0'], [$cash, '0', '80.00']]);
        $this->postOn('2025-03-11', 1, [[$sales, '30.00', '0'], [$cash, '0', '30.00']]);
        $this->postOn('2025-03-12', 1, [[$cash, '25.00', '0'], [$rent, '0', '25.00']]);
        $this->closePeriods();

        $this->close(1);

        $lines = $this->linesOf((int) $this->closingOf(1)->journal_id);

        $this->assertSame(['debit' => '0.0000', 'credit' => '80.0000'], $lines[$returns], 'A revenue account with a debit balance is closed by a credit');
        $this->assertSame(['debit' => '0.0000', 'credit' => '30.0000'], $lines[$sales]);
        $this->assertSame(['debit' => '25.0000', 'credit' => '0.0000'], $lines[$rent], 'An expense account with a credit balance is closed by a debit');
        $this->assertSame(['debit' => '85.0000', 'credit' => '0.0000'], $lines[$this->accountId('retained_earnings')], 'The loss of 85 is a debit');

        foreach ([$returns, $sales, $rent] as $accountId) {
            $this->assertSame('0.0000', $this->balance($accountId, 1));
        }
    }

    public function testApprove_WritesNoRetainedEarningsLineWhenTheResultIsZero(): void
    {
        $cash = $this->accountId('cash');

        $this->postOn('2025-03-10', 1, [[$cash, '100.00', '0'], [$this->accountId('sales_revenue'), '0', '100.00']]);
        $this->postOn('2025-03-11', 1, [[$this->accountId('expenses_default'), '100.00', '0'], [$cash, '0', '100.00']]);
        $this->closePeriods();

        $this->close(1);

        $lines = $this->linesOf((int) $this->closingOf(1)->journal_id);

        $this->assertCount(2, $lines);
        $this->assertArrayNotHasKey($this->accountId('retained_earnings'), $lines);
        $this->assertSame('0.0000', $this->closingOf(1)->result_amount);
    }

    public function testApprove_ClosesAnAccountAndABranchThatWereDeactivatedSince(): void
    {
        $this->twoBranchYear();
        $this->closePeriods();
        $this->accountingDb()->table('accounting_accounts')->where('account_id', $this->accountId('expenses_default'))->update(['active' => 0]);
        $this->accountingDb()->table('branches')->where('branch_id', $this->second)->update(['active' => 0]);

        $ids = $this->close();

        $this->assertCount(2, $ids);
        $this->assertSame('0.0000', $this->balance($this->accountId('expenses_default')));
        $this->assertSame('0.0000', $this->balance($this->accountId('sales_revenue')));
    }

    public function testApprove_MovesNoProfitBetweenBranches(): void
    {
        $this->twoBranchYear();
        $this->closePeriods();

        $this->close();

        $this->assertSame('-250.0000', $this->balance($this->accountId('retained_earnings'), 1));
        $this->assertSame('200.0000', $this->balance($this->accountId('retained_earnings'), $this->second));
        $this->assertSame('-50.0000', $this->balance($this->accountId('retained_earnings')), 'The company holds the sum of its branches');

        foreach ([1, $this->second] as $branchId) {
            $closing = $this->closingOf($branchId);
            $this->assertSame((string) $branchId, (string) $this->journal((int) $closing->journal_id)->branch_id);
        }
    }

    public function testApprove_WritesTheJournalsOfAllTheBranchesOfACompanyRunAndSkipsTheOneWithNothingToClose(): void
    {
        $this->postOn('2025-03-10', 1, [[$this->accountId('cash'), '40.00', '0'], [$this->accountId('sales_revenue'), '0', '40.00']]);
        $this->closePeriods();

        $ids = $this->close();

        $this->assertCount(1, $ids);
        $this->assertNotNull($this->closingOf(1));
        $this->assertNull($this->closingOf($this->second));
        $this->assertSame(1, $this->countRows('accounting_year_end_closings'));
    }

    public function testApprove_RefusesWhenNoBranchOfTheRunHasAnythingToClose(): void
    {
        $this->closePeriods();
        $preview = $this->service->preview(null, $this->yearId(), $this->employee);
        $counts  = $this->counts();

        $this->assertRefused(AccountingError::YearEndNothingToClose, fn () => $this->service->approve(null, $this->yearId(), $preview->fingerprint, $this->employee));
        $this->assertSame($counts, $this->counts());
    }

    public function testApprove_WritesInsideTheClosedPeriodAndRecordsTheExceptionInTheAuditLog(): void
    {
        $this->twoBranchYear();
        $this->closePeriods();

        $this->close(1);

        $closing = $this->closingOf(1);
        $entry   = $this->accountingDb()->table('accounting_audit_log')->where('entity_type', 'year_end_closing')->where('entity_id', (int) $closing->closing_id)->get()->getRow();
        $details = json_decode($entry->details, true);

        $this->assertSame('year_end_closed', $entry->action);
        $this->assertSame((string) $this->employee, (string) $entry->employee_id);
        $this->assertSame('1', (string) $entry->branch_id);
        $this->assertTrue($details['closed_period_exception']);
        $this->assertSame('2025-12-31', $details['closed_period_until']);
        $this->assertSame((int) $closing->journal_id, $details['journal_id']);
        $this->assertSame('250.0000', $details['result']);
        $this->assertSame(3, $details['lines'], 'Three accounts of revenue and expense, and the retained earnings line besides');
        $this->assertSame(1, $this->accountingDb()->table('accounting_audit_log')->where('entity_type', 'journal')->where('entity_id', (int) $closing->journal_id)->where('action', 'posted')->countAllResults());
    }

    public function testApprove_LeavesTheClosedPeriodsAsTheyWere(): void
    {
        $this->twoBranchYear();
        $this->closePeriods();
        $before = $this->accountingDb()->table('accounting_closed_periods')->get()->getResultArray();

        $this->close();

        $this->assertSame($before, $this->accountingDb()->table('accounting_closed_periods')->get()->getResultArray());
        $this->assertSame('2025-12-31', (new ClosedPeriodService())->cutoff(null));
    }

    public function testApprove_DoesNotLetAnythingElseIntoTheClosedPeriod(): void
    {
        $this->twoBranchYear();
        $this->closePeriods();
        $this->close(1);

        try {
            $this->cashSale('2025-12-31', 1, '10.00');
            $this->fail('A sale in a closed period must still be refused.');
        } catch (PostingException $exception) {
            $this->assertSame(PostingError::PeriodClosed, $exception->error);
        }

        try {
            (new JournalService())->postNew(['date' => '2025-12-31', 'branch_id' => 1] + $this->journalInput('10.00'), null);
            $this->fail('A manual journal in a closed period must still be refused.');
        } catch (PostingException $exception) {
            $this->assertSame(PostingError::PeriodClosed, $exception->error);
        }
    }

    public function testApprove_RequiresThePermissionToCloseTheYear(): void
    {
        $this->twoBranchYear();
        $this->closePeriods();
        $preview = $this->service->preview(null, $this->yearId(), $this->employee);
        $nobody  = $this->employeeWithGrants([AccountingGuard::PERIODS_CLOSE, AccountingGuard::JOURNALS_POST, AccountingGuard::JOURNALS_REVERSE]);
        $counts  = $this->counts();

        $this->assertRefused(AccountingError::PermissionDenied, fn () => $this->service->approve(null, $this->yearId(), $preview->fingerprint, $nobody));
        $this->assertRefused(AccountingError::PermissionDenied, fn () => $this->service->approve(1, $this->yearId(), $preview->fingerprint, $nobody));
        $this->assertSame($counts, $this->counts());
    }

    public function testApprove_RejectsAnEmployeeLimitedToOneBranchRunningForTheCompanyOrAnotherBranch(): void
    {
        $this->twoBranchYear();
        $this->closePeriods();
        $limited = $this->closer([1]);
        $preview = $this->service->preview(null, $this->yearId(), $this->employee);
        $counts  = $this->counts();

        foreach ([null, $this->second] as $scope) {
            try {
                $this->service->approve($scope, $this->yearId(), $preview->fingerprint, $limited);
                $this->fail('The run must be refused.');
            } catch (BranchException $exception) {
                $this->assertContains($exception->error, [BranchError::CompanyScopeNeeded, BranchError::NotAllowed]);
            }
        }

        $this->assertSame($counts, $this->counts());
    }

    public function testApprove_LetsAnEmployeeLimitedToABranchCloseThatBranchAndNoOther(): void
    {
        $this->twoBranchYear();
        $this->closePeriods();
        $limited = $this->closer([$this->second]);

        $this->close($this->second, $limited);

        $this->assertNotNull($this->closingOf($this->second));
        $this->assertNull($this->closingOf(1));
    }

    public function testApprove_RefusesWithoutTheClosingOfThePeriodsOfTheCompany(): void
    {
        $this->twoBranchYear();
        $preview = $this->service->preview(null, $this->yearId(), $this->employee);
        $counts  = $this->counts();

        $this->assertRefused(AccountingError::YearEndPeriodNotClosed, fn () => $this->service->approve(null, $this->yearId(), $preview->fingerprint, $this->employee));

        $this->closeThrough('2025-12-31', 1);
        $this->closeThrough('2025-12-31', $this->second);

        $this->assertRefused(AccountingError::YearEndPeriodNotClosed, fn () => $this->service->approve(null, $this->yearId(), $preview->fingerprint, $this->employee));
        $this->assertSame($counts, $this->counts());
    }

    public function testApprove_RefusesWhileTheYearHoldsADraftAndAcceptsOnceItIsGone(): void
    {
        $this->twoBranchYear();
        $this->closePeriods();
        $draft  = (new JournalService())->saveDraft(['date' => '2025-07-01', 'branch_id' => 1] + $this->journalInput('10.00'), null);
        $counts = $this->counts();

        $preview = $this->service->preview(1, $this->yearId(), $this->employee);

        $this->assertRefused(AccountingError::YearEndHasDrafts, fn () => $this->service->approve(1, $this->yearId(), $preview->fingerprint, $this->employee));
        $this->assertSame($counts, $this->counts());

        (new JournalService())->deleteDraft($draft, null);

        $this->assertCount(1, $this->close(1));
    }

    public function testApprove_RefusesAPreviewThatIsNotTheOneTheBooksGiveNow(): void
    {
        $this->twoBranchYear();
        $this->closePeriods();
        $counts = $this->counts();

        $this->assertRefused(AccountingError::YearEndPreviewChanged, fn () => $this->service->approve(null, $this->yearId(), str_repeat('0', 64), $this->employee));
        $this->assertRefused(AccountingError::YearEndPreviewChanged, fn () => $this->service->approve(null, $this->yearId(), '', $this->employee));

        $stale = $this->service->preview(1, $this->yearId(), $this->employee)->fingerprint;

        $this->assertRefused(AccountingError::YearEndPreviewChanged, fn () => $this->service->approve(null, $this->yearId(), $stale, $this->employee));
        $this->assertSame($counts, $this->counts());
    }

    public function testApprove_RefusesAPreviewOfTheBooksBeforeAnEntryChangedThem(): void
    {
        $this->twoBranchYear();
        $this->closePeriods();
        $preview = $this->service->preview(1, $this->yearId(), $this->employee);

        $this->accountingDb()->table('accounting_journal_lines')->where('account_id', $this->accountId('expenses_default'))->where('debit', '150.00')->update(['debit' => '151.00']);
        $this->accountingDb()->table('accounting_journal_lines')->where('account_id', $this->accountId('cash'))->where('credit', '150.00')->update(['credit' => '151.00']);

        $this->assertRefused(AccountingError::YearEndPreviewChanged, fn () => $this->service->approve(1, $this->yearId(), $preview->fingerprint, $this->employee));
        $this->assertNull($this->closingOf(1));
    }

    public function testApprove_RefusesToCloseABranchTwiceAndWritesNothingTheSecondTime(): void
    {
        $this->twoBranchYear();
        $this->closePeriods();
        $preview = $this->service->preview(1, $this->yearId(), $this->employee);
        $this->service->approve(1, $this->yearId(), $preview->fingerprint, $this->employee);
        $counts = $this->counts();

        $this->assertRefused(AccountingError::YearEndAlreadyClosed, fn () => $this->service->approve(1, $this->yearId(), $preview->fingerprint, $this->employee));
        $this->assertSame($counts, $this->counts());
        $this->assertSame(1, $this->accountingDb()->table('accounting_journals')->where('source_type', 'year_end')->countAllResults());
    }

    public function testApprove_ClosesOnlyTheBranchesThatAreNotClosedYetWhenTheCompanyRunsAfterOneBranch(): void
    {
        $this->twoBranchYear();
        $this->closePeriods();
        $this->close(1);

        $ids = $this->close();

        $this->assertCount(1, $ids);
        $this->assertSame(2, $this->accountingDb()->table('accounting_year_end_closings')->where('status', 'active')->countAllResults());
        $this->assertSame((string) $this->second, (string) $this->journal((int) $this->closingOf($this->second)->journal_id)->branch_id);
    }

    public function testApprove_WritesNoJournalOfABranchWhenAnotherBranchOfTheRunCannotBePosted(): void
    {
        $this->twoBranchYear();
        $this->closePeriods();
        $preview = $this->service->preview(null, $this->yearId(), $this->employee);
        $counts  = $this->counts();

        $this->accountingDb()->table('accounting_accounts')->where('account_id', $this->accountId('retained_earnings'))->update(['is_group' => 1]);

        $this->assertRefused(AccountingError::YearEndRetainedEarningsUnset, fn () => $this->service->approve(null, $this->yearId(), $preview->fingerprint, $this->employee));
        $this->assertSame($counts, $this->counts(), 'Neither branch got a journal');
    }

    public function testApprove_RollsBackTheBranchesAlreadyWrittenWhenALaterOneFails(): void
    {
        $this->twoBranchYear();
        $rent = $this->customAccount('54', '5411', 'Rent');
        $this->postOn('2025-07-10', $this->second, [[$rent, '80.00', '0'], [$this->accountId('cash'), '0', '80.00']]);
        $this->closePeriods();
        $preview = $this->service->preview(null, $this->yearId(), $this->employee);
        $counts  = $this->counts();

        $this->accountingDb()->table('accounting_accounts')->where('account_id', $rent)->update(['is_group' => 1]);

        $written  = 0;
        $listener = static function ($query) use (&$written): void {
            if (preg_match('/^INSERT INTO\s+`?\w*accounting_journals`?\s/', (string) $query->getQuery()) === 1) {
                $written++;
            }
        };

        Events::on('DBQuery', $listener);

        try {
            $this->service->approve(null, $this->yearId(), $preview->fingerprint, $this->employee);
            $this->fail('The second branch holds a balance on an account that no longer takes entries.');
        } catch (PostingException $exception) {
            $this->assertSame(PostingError::AccountNotPostable, $exception->error);
        } finally {
            Events::removeListener('DBQuery', $listener);
        }

        $this->assertSame(1, $written, 'The journal of the first branch was written before the second failed');
        $this->assertSame($counts, $this->counts(), 'And was rolled back with the rest');
    }

    public function testApprove_ClosesAYearThatHoldsTheMostAJournalCanHold(): void
    {
        $this->postOn('2025-03-10', 1, [[$this->accountId('cash'), '99999999999.9999', '0'], [$this->accountId('sales_revenue'), '0', '99999999999.9999']]);
        $this->closePeriods();

        $this->close(1);

        $this->assertSame('99999999999.9999', bcadd((string) $this->journal((int) $this->closingOf(1)->journal_id)->total_debit, '0', 4));
    }

    public function testPreview_BlocksAClosingOfMoreThanTheMostAJournalCanHold(): void
    {
        $this->postOn('2025-03-10', 1, [[$this->accountId('cash'), '99999999999.9999', '0'], [$this->accountId('sales_revenue'), '0', '99999999999.9999']]);
        $this->postOn('2025-03-11', 1, [[$this->accountId('cash'), '0.0001', '0'], [$this->accountId('sales_revenue'), '0', '0.0001']]);
        $this->closePeriods();

        $preview = $this->service->preview(1, $this->yearId(), $this->employee);

        $this->assertFalse($preview->canApprove());
        $this->assertSame([AccountingError::YearEndAmountTooLarge], array_map(static fn (AccountingException $blocker): AccountingError => $blocker->error, $preview->blockers));
        $this->assertSame(['max' => '99999999999.9999', 'name' => 'HQ'], $preview->blockers[0]->context);
    }

    public function testPreview_BlocksAClosingWhoseAccountsAreEachWithinTheLimitButTogetherAreNot(): void
    {
        $revenue = [$this->accountId('sales_revenue'), $this->customAccount('41', '413', 'Other sales'), $this->customAccount('41', '414', 'More sales')];

        foreach ($revenue as $account) {
            $this->postOn('2025-03-10', 1, [[$this->accountId('cash'), '50000000000.0000', '0'], [$account, '0', '50000000000.0000']]);
        }

        $this->closePeriods();

        $preview = $this->service->preview(1, $this->yearId(), $this->employee);

        $this->assertSame([AccountingError::YearEndAmountTooLarge], array_map(static fn (AccountingException $blocker): AccountingError => $blocker->error, $preview->blockers));
    }

    public function testPreview_BlocksALossOfMoreThanTheMostAJournalCanHold(): void
    {
        foreach (['2025-03-10', '2025-03-11'] as $date) {
            $this->postOn($date, 1, [[$this->accountId('expenses_default'), '50000000000.0000', '0'], [$this->accountId('cash'), '0', '50000000000.0000']]);
        }

        $this->closePeriods();

        $preview = $this->service->preview(1, $this->yearId(), $this->employee);

        $this->assertSame([AccountingError::YearEndAmountTooLarge], array_map(static fn (AccountingException $blocker): AccountingError => $blocker->error, $preview->blockers));
    }

    public function testApprove_RefusesAClosingOfMoreThanTheMostAJournalCanHoldAndWritesNothing(): void
    {
        foreach (['2025-03-10', '2025-03-11'] as $date) {
            $this->postOn($date, 1, [[$this->accountId('cash'), '50000000000.0000', '0'], [$this->accountId('sales_revenue'), '0', '50000000000.0000']]);
        }

        $this->closePeriods();
        $preview = $this->service->preview(1, $this->yearId(), $this->employee);
        $counts  = $this->counts();

        $this->assertRefused(AccountingError::YearEndAmountTooLarge, fn () => $this->service->approve(1, $this->yearId(), $preview->fingerprint, $this->employee));
        $this->assertSame($counts, $this->counts());
    }

    public function testApprove_RefusesAJournalThatTheNumberOfTheClosingFindsAlreadyWritten(): void
    {
        $this->twoBranchYear();
        $this->closePeriods();
        $this->service->reverse($this->close(1)[0], 'Back', $this->employee);
        $this->accountingDb()->table('accounting_year_end_closings')->truncate();
        $counts = $this->counts();

        $this->assertRefused(AccountingError::WriteFailed, fn () => $this->close(1));
        $this->assertSame($counts, $this->counts(), 'No closing is kept that closed nothing');
        $this->assertNull($this->closingOf(1));
    }

    public function testApprove_TakesTheLocksOfTheBranchesInTheOrderOfTheirNumbers(): void
    {
        $this->twoBranchYear();
        $this->closePeriods();
        $preview = $this->service->preview(null, $this->yearId(), $this->employee);

        $taken = $this->periodLocksTakenBy(fn () => $this->service->approve(null, $this->yearId(), $preview->fingerprint, $this->employee));

        $this->assertSame([1, $this->second], array_slice($taken, 0, 2), 'Every branch of the run is locked before anything is written');
        $this->assertSame([1, $this->second], array_values(array_unique($taken)));
    }

    public function testApprove_TakesOnlyTheLockOfTheBranchOfABranchRun(): void
    {
        $this->twoBranchYear();
        $this->closePeriods();
        $preview = $this->service->preview($this->second, $this->yearId(), $this->employee);

        $taken = $this->periodLocksTakenBy(fn () => $this->service->approve($this->second, $this->yearId(), $preview->fingerprint, $this->employee));

        $this->assertSame([$this->second], array_values(array_unique($taken)));
    }

    public function testApprove_WaitsForARequestThatHoldsTheBranchAndWritesNothingWhenItCannotHaveIt(): void
    {
        $this->twoBranchYear();
        $this->closePeriods();
        $preview = $this->service->preview(1, $this->yearId(), $this->employee);
        $counts  = $this->counts();

        $this->holdTheBranchLock(1, 'FOR UPDATE');
        $this->accountingDb()->query('SET SESSION innodb_lock_wait_timeout = 1');

        try {
            $this->assertWaitsForLock(fn () => $this->service->approve(1, $this->yearId(), $preview->fingerprint, $this->employee));
        } finally {
            $this->accountingDb()->query('SET SESSION innodb_lock_wait_timeout = 50');
        }

        $this->assertSame($counts, $this->counts());
    }

    public function testReverse_WaitsForARequestThatPostsInTheBranchAndTakesNothingBackWhenItCannotHaveIt(): void
    {
        $this->twoBranchYear();
        $this->closePeriods();
        $closing = $this->close(1)[0];
        $counts  = $this->counts();

        $this->holdTheBranchLock(1, 'LOCK IN SHARE MODE');
        $this->accountingDb()->query('SET SESSION innodb_lock_wait_timeout = 1');

        try {
            $this->assertWaitsForLock(fn () => $this->service->reverse($closing, 'Why', $this->employee));
        } finally {
            $this->accountingDb()->query('SET SESSION innodb_lock_wait_timeout = 50');
        }

        $this->assertSame($counts, $this->counts());
        $this->assertNotNull($this->closingOf(1));
    }

    private function holdTheBranchLock(int $branchId, string $mode): void
    {
        $this->other = $this->openSecondConnection(false);
        $this->other->resetTransStatus();
        $this->other->transStart();
        $this->other->query('SELECT branch_id FROM ' . $this->other->prefixTable('accounting_period_locks') . ' WHERE company_id = 1 AND branch_id = ' . $branchId . ' ' . $mode);
    }

    public function testApprove_ClosesTheYearAgainAfterTheClosingWasReversedAsASecondRevision(): void
    {
        $this->twoBranchYear();
        $this->closePeriods();
        $first = $this->close(1);

        $this->service->reverse($first[0], 'Wrong figures', $this->employee);
        $this->cashSale('2026-02-10', 1, '5.00');
        $second = $this->close(1);

        $this->assertNotSame($first[0], $second[0]);
        $this->assertSame(2, $this->accountingDb()->table('accounting_year_end_closings')->where('branch_id', 1)->countAllResults());
        $this->assertSame('2', (string) $this->closingOf(1)->revision);
        $this->assertSame('reversed', $this->accountingDb()->table('accounting_year_end_closings')->where('closing_id', $first[0])->get()->getRow()->status);
        $this->assertSame('0.0000', $this->balance($this->accountId('sales_revenue'), 1, '2025-12-31'));
        $this->assertSame('-250.0000', $this->balance($this->accountId('retained_earnings'), 1, '2025-12-31'));
    }

    public function testReverse_MirrorsTheClosingOnItsDayAndRestoresTheBooksExactly(): void
    {
        $this->twoBranchYear();
        $this->closePeriods();

        $keys   = ['sales_revenue', 'cogs', 'expenses_default', 'retained_earnings', 'cash'];
        $before = [];

        foreach (['2025-06-30', '2025-12-31', '2026-06-30'] as $day) {
            foreach ($keys as $key) {
                $before[$day][$key] = $this->balance($this->accountId($key), null, $day);
            }
        }

        $standingBefore = $this->standing(TrialBalanceClosing::After);
        $closings       = $this->close();
        $reversal       = $this->service->reverse($closings[0], 'Wrong figures', $this->employee);
        $this->service->reverse($closings[1], 'Wrong figures', $this->employee);

        foreach ($before as $day => $balances) {
            foreach ($balances as $key => $expected) {
                $this->assertSame($expected, $this->balance($this->accountId($key), null, $day), "{$key} on {$day} is as it was");
            }
        }

        $this->assertSame($standingBefore, $this->standing(TrialBalanceClosing::After), 'Even a trial balance that counts the closing stands where it stood');

        $mirror = $this->journal($reversal);

        $this->assertSame('2025-12-31', $mirror->journal_date, 'The reversal is written on the day of the closing');
        $this->assertSame(JournalEntryType::YearEnd->value, $mirror->entry_type);
        $this->assertSame('reversal', $mirror->source_type);
        $this->assertSame((string) $this->closingOf(1, '2025', 'reversed')->journal_id, (string) $mirror->reverses_journal_id);
    }

    public function testReverse_MarksTheClosingAndItsJournalReversedAndKeepsTheRow(): void
    {
        $this->twoBranchYear();
        $this->closePeriods();
        $closing  = $this->close(1)[0];
        $original = (int) $this->closingOf(1)->journal_id;

        $reversal = $this->service->reverse($closing, '  Wrong figures  ', $this->employee);
        $row      = $this->accountingDb()->table('accounting_year_end_closings')->where('closing_id', $closing)->get()->getRow();

        $this->assertSame('reversed', $row->status);
        $this->assertNull($row->active_key);
        $this->assertSame($reversal, (int) $row->reversal_journal_id);
        $this->assertSame((string) $this->employee, (string) $row->reversed_by);
        $this->assertSame('Wrong figures', $row->reverse_reason);
        $this->assertNotNull($row->reversed_at);
        $this->assertSame($original, (int) $row->journal_id, 'The closing keeps its journal');
        $this->assertSame(JournalStatus::Reversed->value, $this->journal($original)->status);
        $this->assertSame(JournalStatus::Posted->value, $this->journal($reversal)->status);
        $this->assertSame('Wrong figures', $this->journal($reversal)->description);
        $this->assertNull($this->closingOf(1));
    }

    public function testReverse_WritesTheMirrorOfEveryLineAndRecordsItInTheAuditLog(): void
    {
        $this->twoBranchYear();
        $this->closePeriods();
        $closing  = $this->close(1)[0];
        $original = (int) $this->closingOf(1)->journal_id;

        $reversal = $this->service->reverse($closing, 'Wrong figures', $this->employee);

        foreach ($this->linesOf($original) as $accountId => $line) {
            $this->assertSame(['debit' => $line['credit'], 'credit' => $line['debit']], $this->linesOf($reversal)[$accountId]);
        }

        $this->assertSame(['year_end_closed', 'year_end_reversed'], $this->auditActions('year_end_closing'));
        $this->assertContains('reversed', $this->auditActions('journal'));

        $entry = $this->accountingDb()->table('accounting_audit_log')->where('action', 'year_end_reversed')->get()->getRow();

        $this->assertSame((string) $this->employee, (string) $entry->employee_id);
        $this->assertSame('Wrong figures', json_decode($entry->details, true)['reason']);
        $this->assertSame($reversal, json_decode($entry->details, true)['reversal_id']);
    }

    public function testReverse_CopiesTheDistributionTheClosingWasGiven(): void
    {
        $centers = new CostCenterService();
        $first   = $centers->create(['code' => 'A', 'name' => 'Alpha']);
        $second  = $centers->create(['code' => 'B', 'name' => 'Beta']);

        $this->setConfigValue(JournalSettings::AUTOMATIC_DEFAULT, '1');
        $centers->setDefaults($this->accountId('sales_revenue'), [['cost_center_id' => $first, 'percent' => '60'], ['cost_center_id' => $second, 'percent' => '40']]);
        $this->twoBranchYear();
        $this->closePeriods();

        $closing  = $this->close(1)[0];
        $original = (int) $this->closingOf(1)->journal_id;
        $reversal = $this->service->reverse($closing, 'Wrong figures', $this->employee);

        $closed = $this->distributionOf($original);

        $this->assertSame([[(string) $first, '60.0000', '600.0000'], [(string) $second, '40.0000', '400.0000']], $closed);
        $this->assertSame($closed, $this->distributionOf($reversal));
    }

    public function testReverse_TakesBackTheSingleCenterOfAClosingLineThatHasBeenSwitchedOffSince(): void
    {
        $centers = new CostCenterService();
        $center  = $centers->create(['code' => 'A', 'name' => 'Alpha']);

        $this->setConfigValue(JournalSettings::AUTOMATIC_DEFAULT, '1');
        $centers->setDefaults($this->accountId('sales_revenue'), [['cost_center_id' => $center, 'percent' => '100']]);
        $this->twoBranchYear();
        $this->closePeriods();

        $closing  = $this->close(1)[0];
        $original = (int) $this->closingOf(1)->journal_id;

        $centers->deactivate($center);

        $reversal = $this->service->reverse($closing, 'Wrong figures', $this->employee);

        $this->assertSame([(string) $center], $this->centersOf($original));
        $this->assertSame([(string) $center], $this->centersOf($reversal));
    }

    public function testReverse_RequiresThePermissionAReasonAndTheBranchOfTheClosing(): void
    {
        $this->twoBranchYear();
        $this->closePeriods();
        $closing = $this->close(1)[0];
        $nobody  = $this->employeeWithGrants([AccountingGuard::PERIODS_REOPEN, AccountingGuard::JOURNALS_REVERSE]);
        $other   = $this->closer([$this->second]);
        $counts  = $this->counts();

        $this->assertRefused(AccountingError::PermissionDenied, fn () => $this->service->reverse($closing, 'Why', $nobody));
        $this->assertRefused(AccountingError::ReasonRequired, fn () => $this->service->reverse($closing, '   ', $this->employee));
        $this->assertRefused(AccountingError::ReasonRequired, fn () => $this->service->reverse($closing, str_repeat('x', 256), $this->employee));
        $this->assertRefused(AccountingError::YearEndNotFound, fn () => $this->service->reverse(99999, 'Why', $this->employee));

        try {
            $this->service->reverse($closing, 'Why', $other);
            $this->fail('A branch outside the scope must be refused.');
        } catch (BranchException $exception) {
            $this->assertSame(BranchError::NotAllowed, $exception->error);
        }

        $this->assertSame($counts, $this->counts());
        $this->assertNotNull($this->closingOf(1));
    }

    public function testReverse_RefusesAClosingWhoseJournalIsNotPostedAnymore(): void
    {
        $this->twoBranchYear();
        $this->closePeriods();
        $closing = $this->close(1)[0];
        $this->accountingDb()->table('accounting_journals')->where('journal_id', (int) $this->closingOf(1)->journal_id)->update(['status' => JournalStatus::Reversed->value]);
        $counts = $this->counts();

        $this->assertRefused(AccountingError::NotPosted, fn () => $this->service->reverse($closing, 'Why', $this->employee));
        $this->assertSame($counts, $this->counts());
    }

    public function testReverse_AcceptsAReasonOfTwoHundredAndFiftyFiveCharacters(): void
    {
        $this->twoBranchYear();
        $this->closePeriods();
        $closing = $this->close(1)[0];

        $reversal = $this->service->reverse($closing, str_repeat('x', 255), $this->employee);

        $this->assertSame(255, mb_strlen($this->journal($reversal)->description));
    }

    public function testReverse_RefusesAClosingThatWasTakenBackAlready(): void
    {
        $this->twoBranchYear();
        $this->closePeriods();
        $closing = $this->close(1)[0];
        $this->service->reverse($closing, 'Once', $this->employee);
        $counts = $this->counts();

        $this->assertRefused(AccountingError::YearEndAlreadyReversed, fn () => $this->service->reverse($closing, 'Twice', $this->employee));
        $this->assertSame($counts, $this->counts());
        $this->assertSame(1, $this->accountingDb()->table('accounting_journals')->where('source_type', 'reversal')->where('entry_type', 'year_end')->countAllResults());
    }

    public function testReverse_LocksTheJournalOfTheClosingBeforeThePeriodLocks(): void
    {
        $this->twoBranchYear();
        $this->closePeriods();
        $closing = $this->close(1)[0];

        $order = $this->lockOrderOf(fn () => $this->service->reverse($closing, 'Why', $this->employee));

        $this->assertSame('journal', $order[0], 'The order of a reversal and of the edit of a journal: its row, then the period locks');
        $this->assertContains('period', $order);
    }

    public function testReverse_TakesTheLockOfTheBranchOfTheClosing(): void
    {
        $this->twoBranchYear();
        $this->closePeriods();
        $closing = $this->close($this->second)[0];

        $taken = $this->periodLocksTakenBy(fn () => $this->service->reverse($closing, 'Why', $this->employee));

        $this->assertSame([$this->second], $taken);
    }

    public function testClosings_ListsTheClosingsOfTheBranchesTheEmployeeMaySee(): void
    {
        $this->twoBranchYear();
        $this->closePeriods();
        $this->close();

        $all     = $this->service->closings($this->employee);
        $limited = $this->service->closings($this->closer([$this->second]));
        $none    = $this->service->closings($this->employeeWithGrants([AccountingGuard::YEAR_END_CLOSE], []));

        $this->assertSame(['HQ', 'T2'], array_map(static fn (object $row): string => (string) $row->branch_code, $all));
        $this->assertSame(['T2'], array_map(static fn (object $row): string => (string) $row->branch_code, $limited));
        $this->assertSame([], $none);
        $this->assertSame('2025', $all[0]->year_name);
        $this->assertSame('active', $all[0]->status);
        $this->assertSame([], $this->service->closings($this->employee, $this->yearId('2024')));
        $this->assertCount(2, $this->service->closings($this->employee, $this->yearId('2025')));
    }

    public function testYears_ListsTheYearsThatHaveEndedLatestFirstWithTheClosingsOfTheBranchesTheEmployeeMaySee(): void
    {
        $this->twoBranchYear();
        $this->closePeriods();
        $this->close(1);
        $this->service->reverse($this->close($this->second)[0], 'Back', $this->employee);

        $count = function (int $employee): array {
            $counts = [];

            foreach ($this->service->years($employee) as $year) {
                $counts[$year->name] = $year->closing_count;
            }

            return $counts;
        };

        $all = $count($this->employee);

        $this->assertSame((string) ((int) date('Y') - 1), (string) array_key_first($all), 'The latest year that has ended comes first');
        $this->assertArrayHasKey('2025', $all);
        $this->assertArrayNotHasKey(date('Y'), $all, 'A year that has not ended is not offered');
        $this->assertSame(1, $all['2025'], 'A closing that was taken back is not counted');
        $this->assertSame(0, $all['2024']);
        $this->assertSame(1, $count($this->closer([1]))['2025']);
        $this->assertSame(0, $count($this->closer([$this->second]))['2025'], 'The closing of another branch is not counted');
        $this->assertSame(0, $count($this->employeeWithGrants([AccountingGuard::YEAR_END_CLOSE], []))['2025']);
        $this->assertSame(0, $count($this->employee)['2024']);
    }

    public function testApprove_KeepsTheBalanceSheetBalancedForOneBranchAndForSeveral(): void
    {
        $this->twoBranchYear();
        $this->closePeriods();

        $scopes = ['company' => $this->company(), 'first' => ReportScope::resolve([1], null), 'second' => ReportScope::resolve([$this->second], null)];
        $before = array_map(fn (ReportScope $scope): BalanceSheetReport => $this->sheet('2025-12-31', $scope), $scopes);

        foreach ($before as $name => $sheet) {
            $this->assertEquation($sheet, $name);
        }

        $this->close();

        foreach ($scopes as $name => $scope) {
            $after = $this->sheet('2025-12-31', $scope);

            $this->assertEquation($after, $name . ' after');
            $this->assertSame($before[$name]->equityTotal(), $after->equityTotal(), $name . ': the total of equity does not change');
            $this->assertSame($before[$name]->assets->total, $after->assets->total);
            $this->assertSame('0.0000', $after->unclosedResult, $name . ': nothing is left unclosed');
        }

        $this->assertSame('250.0000', $this->sheet('2025-12-31', $scopes['first'])->equityTotal());
        $this->assertSame('-200.0000', $this->sheet('2025-12-31', $scopes['second'])->equityTotal());
        $this->assertSame('250.0000', $this->sheet('2025-12-31', $scopes['first'])->equity->total, 'It moved to account 32');
    }

    public function testApprove_KeepsTheBalanceSheetBalancedWhenTheYearAfterHasItsOwnResult(): void
    {
        $this->twoBranchYear();
        $this->closePeriods();
        $this->close();
        $this->cashSale('2026-02-10', 1, '45.00');

        $sheet = $this->sheet('2026-03-31');

        $this->assertEquation($sheet);
        $this->assertSame('45.0000', $sheet->unclosedResult, 'Only the new year is unclosed');
        $this->assertSame('50.0000', $sheet->equity->total);
    }

    public function testApprove_LeavesTheIncomeStatementOfTheYearAsItWas(): void
    {
        $this->twoBranchYear();
        $this->closePeriods();

        $statement = static fn (ReportScope $scope): object => (new IncomeStatement())->build($scope, ReportPeriod::of('2025-01-01', '2025-12-31'), 3);
        $scopes    = [$this->company(), ReportScope::resolve([1], null), ReportScope::resolve([$this->second], null)];
        $before    = array_map($statement, $scopes);

        $this->close();

        foreach ($scopes as $index => $scope) {
            $this->assertEquals($before[$index], $statement($scope));
        }

        $this->assertSame('50.0000', $statement($scopes[0])->netIncome);
        $this->assertSame('250.0000', $statement($scopes[1])->netIncome);
    }

    public function testApprove_LeavesTheClosingOfTheFirstYearOutOfTheIncomeStatementOfTwoYears(): void
    {
        $this->twoBranchYear();
        $this->cashSale('2026-03-10', 1, '70.00');
        $this->closePeriods();

        $statement = fn (string $from, string $to) => (new IncomeStatement())->build($this->company(), ReportPeriod::of($from, $to), 3);
        $before    = [$statement('2025-01-01', '2025-12-31'), $statement('2026-01-01', '2026-12-31')];

        $this->close();

        $this->assertEquals($before, [$statement('2025-01-01', '2025-12-31'), $statement('2026-01-01', '2026-12-31')]);
        $this->assertSame('70.0000', $statement('2026-01-01', '2026-12-31')->netIncome, 'The closing of 2025 does not appear as a result of 2026 or as revenue of 2025');
        $this->assertSame('1300.0000', $statement('2025-01-01', '2025-12-31')->revenue->total);
    }

    public function testApprove_ShowsTheTrialBalanceBeforeTheClosingByDefaultAndAfterItOnRequest(): void
    {
        $this->twoBranchYear();
        $this->closePeriods();
        $this->close();

        $default = (new TrialBalance())->build($this->company(), ReportPeriod::of(null, '2025-12-31'));
        $before  = $this->trial(TrialBalanceClosing::Before);
        $after   = $this->trial(TrialBalanceClosing::After);

        $this->assertSame(TrialBalanceClosing::Before, $default->closing);
        $this->assertSame('1300.0000', $before['411']['credit'], 'Before the closing the accounts of the year hold what they hold');
        $this->assertArrayNotHasKey('32', $before, 'Retained earnings hold nothing before the closing');
        $this->assertSame('1300.0000', $after['411']['debit'], 'After it they carry the closing entry');
        $this->assertSame('1300.0000', $after['411']['credit']);
        $this->assertSame('250.0000', $after['32']['credit']);
        $this->assertSame('200.0000', $after['32']['debit']);
        $this->assertTrue((new TrialBalance())->build($this->company(), ReportPeriod::of(null, '2025-12-31'), closing: TrialBalanceClosing::Before)->balances());
        $this->assertTrue((new TrialBalance())->build($this->company(), ReportPeriod::of(null, '2025-12-31'), closing: TrialBalanceClosing::After)->balances());

        foreach ($before as $code => $row) {
            $this->assertSame($row, $this->trial(TrialBalanceClosing::Before)[$code]);
        }
    }

    public function testReverse_RestoresTheTrialBalanceAndTheBalanceSheetOfTheYear(): void
    {
        $this->twoBranchYear();
        $this->closePeriods();

        $trial = $this->trial(TrialBalanceClosing::Before, null, '2026-12-31');
        $sheet = $this->sheet('2026-12-31');

        $closings = $this->close();

        foreach ($closings as $closing) {
            $this->service->reverse($closing, 'Back', $this->employee);
        }

        $this->assertSame($trial, $this->trial(TrialBalanceClosing::Before, null, '2026-12-31'));
        $this->assertEquals($sheet, $this->sheet('2026-12-31'));
    }

    public function testApprove_RejectsACompanyRunOfAnEmployeeLimitedToOneBranchAndListsOnlyTheClosingsOfHisBranch(): void
    {
        $this->twoBranchYear();
        $this->closePeriods();
        $limited = $this->closer([1]);

        $preview = $this->service->preview(1, $this->yearId(), $limited);

        $this->assertSame([1], array_map(static fn ($plan): int => $plan->branchId, $preview->branches));

        try {
            $this->service->approve(null, $this->yearId(), $preview->fingerprint, $limited);
            $this->fail('A company close needs every branch.');
        } catch (BranchException $exception) {
            $this->assertSame(BranchError::CompanyScopeNeeded, $exception->error);
        }

        $this->service->approve(1, $this->yearId(), $preview->fingerprint, $limited);

        $this->assertSame(['HQ'], array_map(static fn (object $row): string => (string) $row->branch_code, $this->service->closings($limited)));
        $this->assertCount(1, $this->service->closings($this->employee));
    }

    public function testApprove_WritesOneClosingForEachBranchWhenTwoCompanyRunsRace(): void
    {
        $this->twoBranchYear();
        $this->closePeriods();
        $preview = $this->service->preview(null, $this->yearId(), $this->employee);
        $this->assertTrue($preview->canApprove());

        [$first, $second] = $this->raceTwoProcesses(
            'yearEndClose',
            [(string) $this->employee, (string) $this->yearId(), 'company'],
            [(string) $this->employee, (string) $this->yearId(), 'company'],
        );

        $this->assertSame(1, (int) $first + (int) $second, 'Of two runs at the same moment, one closes and the other finds it closed');
        $this->assertSame(1, $this->accountingDb()->table('accounting_year_end_closings')->where('branch_id', 1)->countAllResults());
        $this->assertSame(1, $this->accountingDb()->table('accounting_year_end_closings')->where('branch_id', $this->second)->countAllResults());
        $this->assertSame(2, $this->accountingDb()->table('accounting_journals')->where('source_type', 'year_end')->countAllResults());
        $this->assertSame('-50.0000', $this->balance($this->accountId('retained_earnings')));
        $this->assertSame('0.0000', $this->balance($this->accountId('sales_revenue')));
    }

    public function testApprove_WritesOneClosingWhenTwoRunsOfOneBranchRace(): void
    {
        $this->twoBranchYear();
        $this->closePeriods();

        [$first, $second] = $this->raceTwoProcesses(
            'yearEndClose',
            [(string) $this->employee, (string) $this->yearId(), '1'],
            [(string) $this->employee, (string) $this->yearId(), '1'],
        );

        $this->assertSame(1, (int) $first + (int) $second);
        $this->assertSame(1, $this->accountingDb()->table('accounting_journals')->where('source_type', 'year_end')->countAllResults());
        $this->assertSame('-250.0000', $this->balance($this->accountId('retained_earnings')));
    }

    public function testApprove_MakesARequestThatArrivesWhileAnotherIsClosingWaitAndThenRefusesItAsAlreadyClosed(): void
    {
        $this->twoBranchYear();
        $this->closePeriods();
        $preview     = $this->service->preview(1, $this->yearId(), $this->employee);
        $this->other = $this->openSecondConnection(false);
        $this->other->resetTransStatus();
        $this->other->transStart();

        (new YearEndClosing($this->other))->approve(1, $this->yearId(), $preview->fingerprint, $this->employee);

        $worker  = $this->startRaceWorker('yearEndCloseOutcome', [(string) $this->employee, (string) $this->yearId(), '1']);
        $waiting = $this->raceWorkerIsRunning($worker);

        $this->other->transComplete();
        $this->other->close();
        $this->other = null;

        $this->assertTrue($waiting, 'The second request waits for the first');
        $this->assertSame('year_end_already_closed', $this->finishRaceWorker($worker));
        $this->assertSame(1, $this->accountingDb()->table('accounting_journals')->where('source_type', 'year_end')->countAllResults());
    }

    public function testApprove_RefusesARunWhenTheCompanyReopenedItsPeriodsMeanwhile(): void
    {
        $this->twoBranchYear();
        (new ClosedPeriodService())->close(null, '2025-12-31', 'Year', $this->employeeWithGrants([AccountingGuard::PERIODS_CLOSE]));
        $preview = $this->service->preview(null, $this->yearId(), $this->employee);

        (new ClosedPeriodService())->reopen(null, 'Late invoice', $this->employeeWithGrants([AccountingGuard::PERIODS_REOPEN]));

        $this->assertRefused(AccountingError::YearEndPeriodNotClosed, fn () => $this->service->approve(null, $this->yearId(), $preview->fingerprint, $this->employee));
        $this->assertSame(0, $this->countRows('accounting_year_end_closings'));
    }

    public function testApprove_WritesAJournalThatEveryReportKnowsByItsTypeAndByNoOtherMark(): void
    {
        $this->twoBranchYear();
        $this->closePeriods();
        $this->close(1);

        $builder = $this->accountingDb()->table('accounting_journals j')->select('j.journal_id');
        $kept    = array_map(static fn (object $row): int => (int) $row->journal_id, YearEndEntries::leaveOut($builder, $this->accountingDb())->get()->getResult());
        $closing = (int) $this->closingOf(1)->journal_id;

        $this->assertNotContains($closing, $kept);
        $this->assertCount(5, $kept, 'The five journals of the year stay');
    }
}
