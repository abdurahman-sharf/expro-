<?php

namespace Tests\Libraries\Accounting;

use App\Libraries\Accounting\AccountingError;
use App\Libraries\Accounting\AccountingException;
use App\Libraries\Accounting\AccountingGuard;
use App\Libraries\Accounting\FiscalYearService;
use App\Libraries\Accounting\JournalService;
use App\Libraries\Organization\BranchError;
use App\Libraries\Organization\BranchException;
use CodeIgniter\Database\BaseConnection;
use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\DatabaseTestTrait;
use Config\Database;
use Tests\Support\AccountingFixtureTrait;

class FiscalYearServiceTest extends CIUnitTestCase
{
    use DatabaseTestTrait;
    use AccountingFixtureTrait;

    protected $migrate     = true;
    protected $migrateOnce = true;
    protected $refresh     = false;
    protected $namespace   = null;

    private FiscalYearService $service;
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
        $this->setConfigValue('accounting_auto_fiscal_year', '1');
        $this->accountingDb()->table('accounting_fiscal_years')->where('company_id', 1)->delete();
        $this->service = new FiscalYearService();
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

    private function assertFailsWith(AccountingError $expected, callable $action): void
    {
        try {
            $action();
        } catch (AccountingException $exception) {
            $this->assertSame($expected, $exception->error);

            return;
        }

        $this->fail('Expected the failure ' . $expected->value . '.');
    }

    private function year(string $name, string $start, string $end): int
    {
        return $this->service->create(['name' => $name, 'start_date' => $start, 'end_date' => $end]);
    }

    private function manager(?array $branchIds = null): int
    {
        return $this->employeeWithGrants([AccountingGuard::PERIODS_MANAGE], $branchIds);
    }

    /**
     * @return list<string>
     */
    private function names(): array
    {
        return array_column($this->service->all(), 'name');
    }

    public function testCreate_AddsTheFirstYearWithAnyStart(): void
    {
        $id = $this->year('2026', '2026-04-01', '2027-03-31');

        $year = $this->service->find($id);

        $this->assertSame('2026-04-01', $year->start_date);
        $this->assertSame('2027-03-31', $year->end_date);
        $this->assertSame('open', $year->status);
    }

    public function testCreate_RequiresTheNextYearToStartTheDayAfterTheLastEnds(): void
    {
        $this->year('2026', '2026-01-01', '2026-12-31');

        $this->assertFailsWith(AccountingError::FiscalYearGap, fn () => $this->year('2028', '2028-01-01', '2028-12-31'));

        $this->year('2027', '2027-01-01', '2027-12-31');

        $this->assertSame(['2026', '2027'], $this->names());
    }

    public function testCreate_AllowsAYearEndingTheDayBeforeTheFirstOne(): void
    {
        $this->year('2026', '2026-01-01', '2026-12-31');

        $this->year('2025', '2025-01-01', '2025-12-31');

        $this->assertSame(['2025', '2026'], $this->names());
    }

    public function testCreate_RejectsDatesThatOverlapAYear(): void
    {
        $this->year('2026', '2026-01-01', '2026-12-31');

        $this->assertFailsWith(AccountingError::FiscalYearOverlap, fn () => $this->year('Overlap', '2026-12-31', '2027-12-30'));
        $this->assertFailsWith(AccountingError::FiscalYearOverlap, fn () => $this->year('Inside', '2026-03-01', '2026-03-31'));
    }

    public function testCreate_RejectsAnEndBeforeTheStart(): void
    {
        $this->assertFailsWith(AccountingError::FiscalYearRange, fn () => $this->year('Bad', '2026-06-01', '2026-05-31'));
    }

    public function testCreate_AcceptsUpTo400DaysAndRejectsMore(): void
    {
        $this->year('Long', '2026-01-01', date('Y-m-d', strtotime('2026-01-01 +399 days')));

        $this->assertSame(1, $this->countRows('accounting_fiscal_years'));

        $this->accountingDb()->table('accounting_fiscal_years')->where('company_id', 1)->delete();

        $this->assertFailsWith(AccountingError::FiscalYearTooLong, fn () => $this->year('Longer', '2026-01-01', date('Y-m-d', strtotime('2026-01-01 +400 days'))));
    }

    public function testCreate_AcceptsAShortFirstYear(): void
    {
        $id = $this->year('Short', '2026-10-01', '2026-12-31');

        $this->assertSame('2026-12-31', $this->service->find($id)->end_date);
    }

    public function testCreate_RejectsInvalidDatesAndNames(): void
    {
        $this->assertFailsWith(AccountingError::InvalidDate, fn () => $this->year('2026', '2026-02-30', '2026-12-31'));
        $this->assertFailsWith(AccountingError::FiscalYearInvalidName, fn () => $this->year('  ', '2026-01-01', '2026-12-31'));
        $this->assertFailsWith(AccountingError::FiscalYearInvalidName, fn () => $this->year(str_repeat('x', 101), '2026-01-01', '2026-12-31'));
    }

    public function testCreate_RejectsANameAlreadyUsed(): void
    {
        $this->year('2026', '2026-01-01', '2026-12-31');

        $this->assertFailsWith(AccountingError::FiscalYearNameTaken, fn () => $this->year('2026', '2027-01-01', '2027-12-31'));
    }

    public function testCreate_RequiresThePermissionAndEveryBranch(): void
    {
        $this->assertFailsWith(AccountingError::PermissionDenied, fn () => $this->service->create(['name' => '2026', 'start_date' => '2026-01-01', 'end_date' => '2026-12-31'], $this->employeeWithGrants([])));

        try {
            $this->service->create(['name' => '2026', 'start_date' => '2026-01-01', 'end_date' => '2026-12-31'], $this->manager([1]));
            $this->fail('Expected the company scope to be required.');
        } catch (BranchException $exception) {
            $this->assertSame(BranchError::CompanyScopeNeeded, $exception->error);
        }

        $this->assertSame(0, $this->countRows('accounting_fiscal_years'));
    }

    public function testCreate_WritesAnAuditEntryForTheCompany(): void
    {
        $employee = $this->manager();
        $id       = $this->service->create(['name' => '2026', 'start_date' => '2026-01-01', 'end_date' => '2026-12-31'], $employee);

        $audit = $this->accountingDb()->table('accounting_audit_log')->where('entity_type', 'fiscal_year')->where('entity_id', $id)->get()->getRow();

        $this->assertSame('created', $audit->action);
        $this->assertSame((string) $employee, (string) $audit->employee_id);
        $this->assertNull($audit->branch_id);
    }

    public function testUpdate_ChangesTheNameAndDescriptionOfAnyYear(): void
    {
        $first = $this->year('2026', '2026-01-01', '2026-12-31');
        $this->year('2027', '2027-01-01', '2027-12-31');

        $this->service->update($first, ['name' => 'FY26', 'start_date' => '2026-01-01', 'end_date' => '2026-12-31', 'description' => 'First year']);

        $year = $this->service->find($first);

        $this->assertSame('FY26', $year->name);
        $this->assertSame('First year', $year->description);
    }

    public function testUpdate_MovesTheEndOfTheLastYearOnly(): void
    {
        $first = $this->year('2026', '2026-01-01', '2026-12-31');
        $last  = $this->year('2027', '2027-01-01', '2027-12-31');

        $this->service->update($last, ['name' => '2027', 'start_date' => '2027-01-01', 'end_date' => '2028-01-31']);

        $this->assertSame('2028-01-31', $this->service->find($last)->end_date);
        $this->assertFailsWith(AccountingError::FiscalYearBoundary, fn () => $this->service->update($first, ['name' => '2026', 'start_date' => '2026-01-01', 'end_date' => '2026-11-30']));
    }

    public function testUpdate_MovesTheStartOfTheFirstYearEarlier(): void
    {
        $first = $this->year('2026', '2026-04-01', '2026-12-31');

        $this->service->update($first, ['name' => '2026', 'start_date' => '2026-01-01', 'end_date' => '2026-12-31']);

        $this->assertSame('2026-01-01', $this->service->find($first)->start_date);
    }

    public function testUpdate_RejectsShrinkingPastAJournal(): void
    {
        $id = $this->year('2026', '2026-01-01', '2026-12-31');
        (new JournalService())->postNew(['date' => '2026-11-15'] + $this->journalInput());

        $this->assertFailsWith(AccountingError::FiscalYearHasJournals, fn () => $this->service->update($id, ['name' => '2026', 'start_date' => '2026-01-01', 'end_date' => '2026-10-31']));

        $this->service->update($id, ['name' => '2026', 'start_date' => '2026-01-01', 'end_date' => '2026-11-30']);

        $this->assertSame('2026-11-30', $this->service->find($id)->end_date);
    }

    public function testUpdate_RejectsShrinkingPastAClosedPeriod(): void
    {
        $id = $this->year('2026', '2026-01-01', '2026-12-31');
        $this->closeThrough('2026-06-30');

        $this->assertFailsWith(AccountingError::FiscalYearClosed, fn () => $this->service->update($id, ['name' => '2026', 'start_date' => '2026-07-01', 'end_date' => '2026-12-31']));
    }

    public function testUpdate_RejectsTwoYearsOverlappingAfterTheChange(): void
    {
        $this->year('2026', '2026-01-01', '2026-12-31');
        $last = $this->year('2027', '2027-01-01', '2027-12-31');

        $this->assertFailsWith(AccountingError::FiscalYearBoundary, fn () => $this->service->update($last, ['name' => '2027', 'start_date' => '2026-12-01', 'end_date' => '2027-12-31']));
    }

    public function testUpdate_RejectsANameAlreadyUsed(): void
    {
        $this->year('2026', '2026-01-01', '2026-12-31');
        $last = $this->year('2027', '2027-01-01', '2027-12-31');

        $this->assertFailsWith(AccountingError::FiscalYearNameTaken, fn () => $this->service->update($last, ['name' => '2026', 'start_date' => '2027-01-01', 'end_date' => '2027-12-31']));
    }

    public function testUpdate_RejectsAYearThatDoesNotExist(): void
    {
        $this->assertFailsWith(AccountingError::FiscalYearNotFound, fn () => $this->service->update(9999, ['name' => 'X', 'start_date' => '2026-01-01', 'end_date' => '2026-12-31']));
    }

    public function testUpdate_RequiresThePermissionAndEveryBranch(): void
    {
        $id = $this->year('2026', '2026-01-01', '2026-12-31');

        $this->assertFailsWith(AccountingError::PermissionDenied, fn () => $this->service->update($id, ['name' => 'X', 'start_date' => '2026-01-01', 'end_date' => '2026-12-31'], $this->employeeWithGrants([])));

        $this->expectException(BranchException::class);

        $this->service->update($id, ['name' => 'X', 'start_date' => '2026-01-01', 'end_date' => '2026-12-31'], $this->manager([1]));
    }

    public function testDelete_RemovesTheLastYearAndTheFirstYearButNotOneInTheMiddle(): void
    {
        $first  = $this->year('2025', '2025-01-01', '2025-12-31');
        $middle = $this->year('2026', '2026-01-01', '2026-12-31');
        $last   = $this->year('2027', '2027-01-01', '2027-12-31');

        $this->assertFailsWith(AccountingError::FiscalYearBoundary, fn () => $this->service->delete($middle));

        $this->service->delete($last);
        $this->service->delete($first);

        $this->assertSame(['2026'], $this->names());
    }

    public function testDelete_RejectsAYearWithJournalsOfAnyState(): void
    {
        $id = $this->year('2026', '2026-01-01', '2026-12-31');
        (new JournalService())->saveDraft(['date' => '2026-05-05'] + $this->journalInput());

        $this->assertFailsWith(AccountingError::FiscalYearHasJournals, fn () => $this->service->delete($id));
        $this->assertNotNull($this->service->find($id));
    }

    public function testDelete_RejectsAYearWithAClosedPeriod(): void
    {
        $id = $this->year('2026', '2026-01-01', '2026-12-31');
        $this->closeThrough('2026-03-31');

        $this->assertFailsWith(AccountingError::FiscalYearClosed, fn () => $this->service->delete($id));
    }

    public function testSuggest_StartsTheDayAfterTheLastYearAndKeepsItsTwelveMonths(): void
    {
        $this->year('Short', '2026-07-01', '2027-06-30');

        $this->assertSame(['name' => '2027/2028', 'start_date' => '2027-07-01', 'end_date' => '2028-06-30'], $this->service->suggest());
    }

    public function testSuggest_KeepsTheLengthOfAYearOfFiftyTwoWeeks(): void
    {
        $this->year('Weeks', '2026-02-02', '2027-02-01');
        $this->accountingDb()->table('accounting_fiscal_years')->where('company_id', 1)->update(['end_date' => '2027-01-31']);

        $this->assertSame('2028-01-30', $this->service->suggest()['end_date']);
    }

    public function testSuggest_StartsOnTheAccountingStartDateWhenThereIsNoYear(): void
    {
        $this->setConfigValue('accounting_start_date', '2026-01-01');

        $this->assertSame(['name' => '2026', 'start_date' => '2026-01-01', 'end_date' => '2026-12-31'], $this->service->suggest());
    }

    public function testSuggest_GivesAYearOfTwelveMonthsFromAnyStartDate(): void
    {
        $this->setConfigValue('accounting_start_date', '2026-04-01');

        $this->assertSame('2027-03-31', $this->service->suggest()['end_date']);
    }

    public function testEnsureCovering_CreatesTheNextCalendarYearWhenTheDateNeedsIt(): void
    {
        $this->year('2026', '2026-01-01', '2026-12-31');

        $this->assertTrue($this->service->ensureCovering('2027-02-10'));

        $this->assertSame(['2026', '2027'], $this->names());

        $audit = $this->accountingDb()->table('accounting_audit_log')->where('entity_type', 'fiscal_year')->orderBy('audit_id', 'DESC')->get()->getRow();

        $this->assertSame('created', $audit->action);
        $this->assertSame('system', $audit->origin);
        $this->assertStringContainsString('"automatic":true', $audit->details);
    }

    public function testEnsureCovering_KeepsTheLengthOfAYearThatDoesNotFollowTheCalendar(): void
    {
        $this->year('2026/2027', '2026-07-01', '2027-06-30');

        $this->service->ensureCovering('2027-08-01');

        $next = $this->service->covering('2027-08-01');

        $this->assertSame('2027-07-01', $next->start_date);
        $this->assertSame('2028-06-30', $next->end_date);
    }

    public function testEnsureCovering_CreatesAtMostTwoYearsForOneDate(): void
    {
        $this->year('2026', '2026-01-01', '2026-12-31');

        $this->assertFalse($this->service->ensureCovering('2029-05-05'));
        $this->assertSame(['2026', '2027', '2028'], $this->names());
        $this->assertTrue($this->service->ensureCovering('2028-05-05'));
    }

    public function testEnsureCovering_DoesNothingWhenAutomaticCreationIsOff(): void
    {
        $this->setConfigValue('accounting_auto_fiscal_year', '0');
        $this->year('2026', '2026-01-01', '2026-12-31');

        $this->assertFalse($this->service->ensureCovering('2027-02-10'));
        $this->assertSame(['2026'], $this->names());
    }

    public function testEnsureCovering_DoesNothingForADateBeforeTheFirstYearOrWithoutAnyYear(): void
    {
        $this->assertFalse($this->service->ensureCovering('2026-02-10'));

        $this->year('2026', '2026-01-01', '2026-12-31');

        $this->assertFalse($this->service->ensureCovering('2025-06-01'));
        $this->assertSame(['2026'], $this->names());
    }

    public function testEnsureCovering_IsSafeToCallAgain(): void
    {
        $this->year('2026', '2026-01-01', '2026-12-31');

        $this->service->ensureCovering('2027-02-10');
        $this->service->ensureCovering('2027-02-10');

        $this->assertSame(['2026', '2027'], $this->names());
    }

    public function testAll_CountsPostedJournalsAndMarksWhereEachYearStands(): void
    {
        $thisYear = (int) date('Y');
        $past     = $this->year('past', ($thisYear - 1) . '-01-01', ($thisYear - 1) . '-12-31');
        $current  = $this->year('current', $thisYear . '-01-01', $thisYear . '-12-31');
        $this->year('future', ($thisYear + 1) . '-01-01', ($thisYear + 1) . '-12-31');

        (new JournalService())->postNew(['date' => $thisYear . '-02-02'] + $this->journalInput());
        (new JournalService())->saveDraft(['date' => $thisYear . '-03-03'] + $this->journalInput());

        $years = [];

        foreach ($this->service->all() as $row) {
            $years[$row->name] = $row;
        }

        $this->assertSame('past', $years['past']->state);
        $this->assertSame('current', $years['current']->state);
        $this->assertSame('future', $years['future']->state);
        $this->assertSame(1, (int) $years['current']->journal_count);
        $this->assertSame(0, (int) $years['past']->journal_count);
        $this->assertNotNull($past + $current);
    }

    public function testDaysUntilLastYearEnds_WarnsWithinSixtyDaysOnly(): void
    {
        $this->assertNull($this->service->daysUntilLastYearEnds());

        $this->year('far', date('Y-m-d', strtotime('-100 days')), date('Y-m-d', strtotime('+200 days')));

        $this->assertNull($this->service->daysUntilLastYearEnds());

        $this->accountingDb()->table('accounting_fiscal_years')->where('company_id', 1)->update(['end_date' => date('Y-m-d', strtotime('+30 days'))]);

        $this->assertSame(30, $this->service->daysUntilLastYearEnds());
    }

    public function testDelete_WaitsForAPostingInProgressAndThenRefusesTheYear(): void
    {
        $id          = $this->year('2026', '2026-01-01', '2026-12-31');
        $this->other = $this->openSecondConnection();

        $db = $this->accountingDb();
        $db->resetTransStatus();
        $db->transStart();
        (new JournalService())->postNew(['date' => '2026-05-05'] + $this->journalInput());

        $this->assertWaitsForLock(fn () => (new FiscalYearService($this->other))->delete($id));

        $db->transComplete();

        $this->assertFailsWith(AccountingError::FiscalYearHasJournals, fn () => (new FiscalYearService($this->other))->delete($id));
        $this->assertNotNull($this->service->find($id));
    }

    public function testEnsureCovering_CreatesTheYearOnceWhenTwoRequestsNeedIt(): void
    {
        $this->year('2026', '2026-01-01', '2026-12-31');
        $this->other = $this->openSecondConnection();

        $this->startWithSnapshot($this->other);
        $this->assertTrue($this->service->ensureCovering('2027-03-01'));

        (new FiscalYearService($this->other))->ensureCovering('2027-03-01');

        $this->assertSame(['2026', '2027'], $this->names());
    }
}
