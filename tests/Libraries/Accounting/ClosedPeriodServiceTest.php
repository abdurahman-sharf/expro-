<?php

namespace Tests\Libraries\Accounting;

use App\Libraries\Accounting\AccountingError;
use App\Libraries\Accounting\AccountingException;
use App\Libraries\Accounting\AccountingGuard;
use App\Libraries\Accounting\ClosedPeriodService;
use App\Libraries\Accounting\JournalService;
use App\Libraries\Accounting\PeriodGuard;
use App\Libraries\Accounting\PostingError;
use App\Libraries\Accounting\PostingException;
use App\Libraries\Organization\BranchError;
use App\Libraries\Organization\BranchException;
use CodeIgniter\Database\BaseConnection;
use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\DatabaseTestTrait;
use Config\Database;
use Tests\Support\AccountingFixtureTrait;

class ClosedPeriodServiceTest extends CIUnitTestCase
{
    use DatabaseTestTrait;
    use AccountingFixtureTrait;

    protected $migrate     = true;
    protected $migrateOnce = true;
    protected $refresh     = false;
    protected $namespace   = null;

    private ClosedPeriodService $service;
    private ?BaseConnection $other = null;

    public static function setUpBeforeClass(): void
    {
        $seeder = Database::seeder('tests');
        $seeder->call('TestDatabaseBootstrapSeeder');
    }

    protected function setUp(): void
    {
        parent::setUp();

        $this->reinstallChart();
        $this->setConfigValue('accounting_base_currency', 'SAR');
        $this->setConfigValue('accounting_start_date', '2020-01-01');
        $this->service = new ClosedPeriodService();
    }

    protected function tearDown(): void
    {
        $this->releaseOpenTransactions();

        if ($this->other !== null) {
            $this->other->transRollback();
            $this->other->close();
            $this->other = null;
        }

        parent::tearDown();
    }

    private function assertFailsWith(AccountingError $expected, callable $action): ?AccountingException
    {
        try {
            $action();
        } catch (AccountingException $exception) {
            $this->assertSame($expected, $exception->error);

            return $exception;
        }

        $this->fail('Expected the failure ' . $expected->value . '.');
    }

    /**
     * @param list<int>|null $branchIds
     */
    private function closer(?array $branchIds = null): int
    {
        return $this->employeeWithGrants([AccountingGuard::PERIODS_CLOSE, AccountingGuard::PERIODS_REOPEN], $branchIds);
    }

    private function postOn(string $date, int $branchId = 1): int
    {
        return (new JournalService())->postNew(['date' => $date, 'branch_id' => $branchId] + $this->journalInput());
    }

    private function draftOn(string $date, int $branchId = 1): int
    {
        return (new JournalService())->saveDraft(['date' => $date, 'branch_id' => $branchId] + $this->journalInput());
    }

    private function ago(int $days): string
    {
        return date('Y-m-d', strtotime("-{$days} days"));
    }

    public function testClose_ClosesTheCompanyThroughADate(): void
    {
        $employee = $this->closer();
        $date     = $this->ago(30);

        $id = $this->service->close(null, $date, 'Month end', $employee);

        $row = $this->accountingDb()->table('accounting_closed_periods')->where('closed_period_id', $id)->get()->getRow();

        $this->assertNull($row->branch_id);
        $this->assertSame($date, $row->date_to);
        $this->assertSame('1', (string) $row->active);
        $this->assertSame('Month end', $row->note);
        $this->assertSame((string) $employee, (string) $row->created_by);
        $this->assertSame($date, $this->service->cutoff(null));
    }

    public function testClose_StartsTheNextClosingTheDayAfterThePreviousOne(): void
    {
        $employee = $this->closer();

        $this->service->close(null, $this->ago(40), '', $employee);
        $id = $this->service->close(null, $this->ago(10), '', $employee);

        $row = $this->accountingDb()->table('accounting_closed_periods')->where('closed_period_id', $id)->get()->getRow();

        $this->assertSame($this->ago(39), $row->date_from);
    }

    public function testClose_WritesAnAuditEntryInTheBranchOrForTheCompany(): void
    {
        $employee = $this->closer();
        $second   = $this->createBranch('B2');

        $this->service->close(null, $this->ago(30), '', $employee);
        $this->service->close($second, $this->ago(20), '', $employee);

        $entries = $this->accountingDb()->table('accounting_audit_log')->where('action', 'period_closed')->orderBy('audit_id')->get()->getResult();

        $this->assertNull($entries[0]->branch_id);
        $this->assertSame((string) $second, (string) $entries[1]->branch_id);
    }

    public function testClose_RejectsADateNotAfterThePreviousClosing(): void
    {
        $employee = $this->closer();
        $this->service->close(null, $this->ago(30), '', $employee);

        $this->assertFailsWith(AccountingError::ClosingNotAfterPrevious, fn () => $this->service->close(null, $this->ago(30), '', $employee));
        $this->assertFailsWith(AccountingError::ClosingNotAfterPrevious, fn () => $this->service->close(null, $this->ago(40), '', $employee));
    }

    public function testClose_RejectsAFutureDate(): void
    {
        $this->assertFailsWith(AccountingError::ClosingInFuture, fn () => $this->service->close(null, date('Y-m-d', strtotime('+2 days')), '', $this->closer()));
    }

    public function testClose_RejectsADateBeforeAccountingStarts(): void
    {
        $this->setConfigValue('accounting_start_date', $this->ago(20));

        $this->assertFailsWith(AccountingError::ClosingBeforeStart, fn () => $this->service->close(null, $this->ago(30), '', $this->closer()));
    }

    public function testClose_RejectsADateOutsideEveryFiscalYear(): void
    {
        $this->accountingDb()->table('accounting_fiscal_years')->where('start_date <', '2024-01-01')->delete();

        $this->assertFailsWith(AccountingError::ClosingOutsideFiscalYear, fn () => $this->service->close(null, '2022-06-30', '', $this->closer()));
    }

    public function testClose_RejectsAnInvalidDate(): void
    {
        $this->assertFailsWith(AccountingError::InvalidDate, fn () => $this->service->close(null, '2026-02-30', '', $this->closer()));
    }

    public function testClose_RejectsWhileDraftsLieInThePeriodAndListsThem(): void
    {
        $draftId = $this->draftOn($this->ago(35));

        $exception = $this->assertFailsWith(AccountingError::ClosingBlockedByDrafts, fn () => $this->service->close(null, $this->ago(30), '', $this->closer()));

        $this->assertSame(1, $exception->context['count']);
        $this->assertSame([$draftId], $exception->context['ids']);
        $this->assertNull($this->service->cutoff(null));
    }

    public function testClose_IgnoresDraftsDatedAfterTheClosingDate(): void
    {
        $this->draftOn($this->ago(5));

        $this->service->close(null, $this->ago(30), '', $this->closer());

        $this->assertSame($this->ago(30), $this->service->cutoff(null));
    }

    public function testClose_OfABranchIsBlockedOnlyByThatBranchsDrafts(): void
    {
        $second = $this->createBranch('B2');
        $this->draftOn($this->ago(35), $second);

        $this->service->close(1, $this->ago(30), '', $this->closer());

        $this->assertFailsWith(AccountingError::ClosingBlockedByDrafts, fn () => $this->service->close($second, $this->ago(30), '', $this->closer()));
        $this->assertFailsWith(AccountingError::ClosingBlockedByDrafts, fn () => $this->service->close(null, $this->ago(30), '', $this->closer()));
    }

    public function testClose_OfOneBranchLeavesTheOthersOpen(): void
    {
        $second = $this->createBranch('B2');
        $date   = $this->ago(30);

        $this->service->close($second, $date, '', $this->closer());

        $guard = new PeriodGuard();

        $this->assertSame($date, $guard->effectiveCutoff($second)['date']);
        $this->assertNull($guard->effectiveCutoff(1));
        $this->assertNull($this->service->cutoff(null));
    }

    public function testClose_OfTheCompanyHoldsBackEveryBranchAndALaterBranchClosingWins(): void
    {
        $second = $this->createBranch('B2');
        $employee = $this->closer();

        $this->service->close(null, $this->ago(30), '', $employee);
        $this->service->close($second, $this->ago(10), '', $employee);

        $guard = new PeriodGuard();

        $this->assertSame($this->ago(30), $guard->effectiveCutoff(1)['date']);
        $this->assertSame($this->ago(10), $guard->effectiveCutoff($second)['date']);
    }

    public function testClose_RequiresThePermission(): void
    {
        $this->assertFailsWith(AccountingError::PermissionDenied, fn () => $this->service->close(null, $this->ago(30), '', $this->employeeWithGrants([])));
    }

    public function testClose_OfTheCompanyRequiresEveryBranch(): void
    {
        try {
            $this->service->close(null, $this->ago(30), '', $this->closer([1]));
            $this->fail('Expected the company scope to be required.');
        } catch (BranchException $exception) {
            $this->assertSame(BranchError::CompanyScopeNeeded, $exception->error);
        }
    }

    public function testClose_OfABranchRequiresThatBranchInTheEmployeesScope(): void
    {
        $second = $this->createBranch('B2');

        try {
            $this->service->close($second, $this->ago(30), '', $this->closer([1]));
            $this->fail('Expected the branch to be refused.');
        } catch (BranchException $exception) {
            $this->assertSame(BranchError::NotAllowed, $exception->error);
        }

        $this->service->close(1, $this->ago(30), '', $this->closer([1]));

        $this->assertSame($this->ago(30), $this->service->cutoff(1));
    }

    public function testClose_RejectsABranchThatDoesNotExist(): void
    {
        $this->expectException(BranchException::class);

        $this->service->close(9999, $this->ago(30), '', $this->closer());
    }

    public function testReopen_ReopensOnlyTheLatestClosingOfTheScope(): void
    {
        $employee = $this->closer();
        $this->service->close(null, $this->ago(40), '', $employee);
        $this->service->close(null, $this->ago(10), '', $employee);

        $this->service->reopen(null, 'Late invoice', $employee);

        $this->assertSame($this->ago(40), $this->service->cutoff(null));

        $this->service->reopen(null, 'Still wrong', $employee);

        $this->assertNull($this->service->cutoff(null));
        $this->assertFailsWith(AccountingError::NothingToReopen, fn () => $this->service->reopen(null, 'Again', $employee));
    }

    public function testReopen_RecordsWhoReopenedItAndWhy(): void
    {
        $employee = $this->closer();
        $id       = $this->service->close(null, $this->ago(10), '', $employee);

        $this->service->reopen(null, 'Late invoice', $employee);

        $row = $this->accountingDb()->table('accounting_closed_periods')->where('closed_period_id', $id)->get()->getRow();

        $this->assertSame('0', (string) $row->active);
        $this->assertSame((string) $employee, (string) $row->reopened_by);
        $this->assertSame('Late invoice', $row->reopen_reason);
        $this->assertNotNull($row->reopened_at);

        $audit = $this->accountingDb()->table('accounting_audit_log')->where('action', 'period_reopened')->get()->getRow();

        $this->assertSame((string) $employee, (string) $audit->employee_id);
    }

    public function testReopen_OfTheCompanyDoesNotReopenABranchThatClosedOnItsOwn(): void
    {
        $second   = $this->createBranch('B2');
        $employee = $this->closer();
        $this->service->close(null, $this->ago(30), '', $employee);
        $this->service->close($second, $this->ago(30), '', $employee);

        $this->service->reopen(null, 'Reopen the company', $employee);

        $guard = new PeriodGuard();

        $this->assertNull($guard->effectiveCutoff(1));
        $this->assertSame($this->ago(30), $guard->effectiveCutoff($second)['date']);
    }

    public function testReopen_OfABranchLeavesTheCompanyClosing(): void
    {
        $employee = $this->closer();
        $this->service->close(null, $this->ago(30), '', $employee);
        $this->service->close(1, $this->ago(10), '', $employee);

        $this->service->reopen(1, 'Branch fix', $employee);

        $this->assertSame($this->ago(30), $this->service->cutoff(null));
        $this->assertNull($this->service->cutoff(1));
    }

    public function testReopen_RequiresAReason(): void
    {
        $employee = $this->closer();
        $this->service->close(null, $this->ago(10), '', $employee);

        $this->assertFailsWith(AccountingError::ReasonRequired, fn () => $this->service->reopen(null, '  ', $employee));
        $this->assertFailsWith(AccountingError::ReasonRequired, fn () => $this->service->reopen(null, str_repeat('x', 256), $employee));
    }

    public function testReopen_RequiresItsOwnPermission(): void
    {
        $this->service->close(null, $this->ago(10), '', $this->closer());

        $closeOnly = $this->employeeWithGrants([AccountingGuard::PERIODS_CLOSE]);

        $this->assertFailsWith(AccountingError::PermissionDenied, fn () => $this->service->reopen(null, 'Reason', $closeOnly));
    }

    public function testPreview_CountsTheJournalsTheClosingWouldCoverAndListsTheDrafts(): void
    {
        $employee = $this->closer();
        $this->postOn($this->ago(50));
        $this->postOn($this->ago(45));
        $this->postOn($this->ago(5));
        $draftId = $this->draftOn($this->ago(32));

        $preview = $this->service->preview(null, $this->ago(30), $employee);

        $this->assertNull($preview['previous']);
        $this->assertSame(2, $preview['journals']);
        $this->assertSame('200.0000', $preview['total_debit']);
        $this->assertSame(1, $preview['draft_count']);
        $this->assertSame($draftId, (int) $preview['drafts'][0]->journal_id);
    }

    public function testPreview_CountsOnlyWhatLiesAfterThePreviousClosing(): void
    {
        $employee = $this->closer();
        $this->postOn($this->ago(50));
        $this->service->close(null, $this->ago(40), '', $employee);
        $this->postOn($this->ago(35));

        $preview = $this->service->preview(null, $this->ago(30), $employee);

        $this->assertSame($this->ago(40), $preview['previous']);
        $this->assertSame(1, $preview['journals']);
    }

    public function testSummary_ListsTheCompanyAndOnlyTheBranchesInTheEmployeesScope(): void
    {
        $second = $this->createBranch('B2');
        $this->createBranch('B3');
        $employee = $this->closer();
        $this->service->close(null, $this->ago(30), '', $employee);
        $this->service->close($second, $this->ago(10), '', $employee);

        $all     = $this->service->summary(null);
        $limited = $this->service->summary($this->closer([$second]));

        $this->assertSame($this->ago(30), $all['company']);
        $this->assertSame(['HQ', 'B2', 'B3'], array_column($all['branches'], 'code'));
        $this->assertSame(['B2'], array_column($limited['branches'], 'code'));
        $this->assertSame($this->ago(10), $limited['branches'][0]->cutoff);
    }

    public function testSearch_FiltersByScopeAndStatusAndHidesOtherBranches(): void
    {
        $second   = $this->createBranch('B2');
        $employee = $this->closer();
        $this->service->close(null, $this->ago(30), 'Company', $employee);
        $this->service->close(1, $this->ago(20), 'Head office', $employee);
        $this->service->close($second, $this->ago(10), 'Second', $employee);
        $this->service->reopen($second, 'Fix', $employee);

        $notes = static fn (array $result): array => array_column($result['rows'], 'note');

        $this->assertSame(['Second', 'Head office', 'Company'], $notes($this->service->search([], null)));
        $this->assertSame(['Company'], $notes($this->service->search(['scope' => 'company'], null)));
        $this->assertSame(['Second'], $notes($this->service->search(['scope' => (string) $second], null)));
        $this->assertSame(['Second'], $notes($this->service->search(['status' => 'reopened'], null)));
        $this->assertSame(['Head office', 'Company'], $notes($this->service->search(['status' => 'active'], null)));
        $this->assertSame(['Head office', 'Company'], $notes($this->service->search([], $this->closer([1]))));
        $this->assertSame(['Company'], $notes($this->service->search([], $this->closer([]))));
    }

    public function testSearch_ShowsTheReopeningDetails(): void
    {
        $employee = $this->closer();
        $this->service->close(null, $this->ago(10), '', $employee);
        $this->service->reopen(null, 'Late invoice', $employee);

        $row = $this->service->search([], null)['rows'][0];

        $this->assertSame('Late invoice', $row->reopen_reason);
        $this->assertNotSame('', (string) $row->reopened_by_name);
    }

    private function holdOpen(callable $work): void
    {
        $db = $this->accountingDb();
        $db->resetTransStatus();
        $db->transStart();
        $work();
    }

    public function testClose_WaitsForAnotherClosingOfTheSameScopeAndThenRefusesTheSameDate(): void
    {
        $employee    = $this->closer();
        $this->other = $this->openSecondConnection();
        $date        = $this->ago(30);

        $this->holdOpen(fn () => $this->service->close(null, $date, '', $employee));

        $this->assertWaitsForLock(fn () => (new ClosedPeriodService($this->other))->close(null, $date, '', $employee));

        $this->accountingDb()->transComplete();

        $this->assertFailsWith(AccountingError::ClosingNotAfterPrevious, fn () => (new ClosedPeriodService($this->other))->close(null, $date, '', $employee));
        $this->assertSame(1, $this->accountingDb()->table('accounting_closed_periods')->countAllResults());
    }

    public function testClose_DoesNotWaitForAClosingOfAnotherBranch(): void
    {
        $employee    = $this->closer();
        $second      = $this->createBranch('B2');
        $this->other = $this->openSecondConnection();

        $this->holdOpen(fn () => $this->service->close(1, $this->ago(30), '', $employee));

        (new ClosedPeriodService($this->other))->close($second, $this->ago(30), '', $employee);

        $this->accountingDb()->transComplete();

        $this->assertSame(2, $this->accountingDb()->table('accounting_closed_periods')->countAllResults());
    }

    public function testReopen_WaitsForAnotherReopeningOfTheSameScope(): void
    {
        $employee    = $this->closer();
        $this->other = $this->openSecondConnection();
        $this->service->close(null, $this->ago(40), '', $employee);
        $this->service->close(null, $this->ago(10), '', $employee);

        $this->holdOpen(fn () => $this->service->reopen(null, 'First', $employee, $this->ago(10)));

        $this->assertWaitsForLock(fn () => (new ClosedPeriodService($this->other))->reopen(null, 'Second', $employee, $this->ago(10)));

        $this->accountingDb()->transComplete();

        $this->assertSame($this->ago(40), $this->service->cutoff(null));
    }

    public function testReopen_RefusesAClosingThatIsNotTheOneTheScreenShowed(): void
    {
        $employee = $this->closer();
        $this->service->close(null, $this->ago(40), '', $employee);
        $this->service->close(null, $this->ago(10), '', $employee);
        $this->service->reopen(null, 'First', $employee, $this->ago(10));

        $exception = $this->assertFailsWith(AccountingError::ClosingChanged, fn () => $this->service->reopen(null, 'Second', $employee, $this->ago(10)));

        $this->assertSame($this->ago(40), $exception->context['date']);
        $this->assertSame($this->ago(40), $this->service->cutoff(null));
    }

    public function testReopen_AcceptsTheClosingTheScreenShowed(): void
    {
        $employee = $this->closer();
        $this->service->close(null, $this->ago(10), '', $employee);

        $this->service->reopen(null, 'Late invoice', $employee, $this->ago(10));

        $this->assertNull($this->service->cutoff(null));
    }

    public function testClose_RefusesAJournalPostedByARequestHoldingAnOlderSnapshot(): void
    {
        $employee    = $this->closer();
        $this->other = $this->openSecondConnection();

        $this->startWithSnapshot($this->other);
        $this->service->close(null, '2026-03-31', '', $employee);

        try {
            (new JournalService($this->other))->postNew(['date' => '2026-03-15'] + $this->journalInput());
            $this->fail('Expected the journal to be refused.');
        } catch (PostingException $exception) {
            $this->assertSame(PostingError::PeriodClosed, $exception->error);
        }

        $this->assertSame(0, $this->accountingDb()->table('accounting_journals')->countAllResults());
    }
}
