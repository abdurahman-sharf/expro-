<?php

namespace Tests\Controllers;

use App\Libraries\Accounting\AccountingGuard;
use App\Libraries\Accounting\ClosedPeriodService;
use App\Libraries\Accounting\JournalService;
use App\Libraries\Accounting\YearEndClosing;
use CodeIgniter\Exceptions\PageNotFoundException;
use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\DatabaseTestTrait;
use Tests\Support\AccountingControllerFixtureTrait;

class AccountingClosingsControllerTest extends CIUnitTestCase
{
    use DatabaseTestTrait;
    use AccountingControllerFixtureTrait;

    protected $migrate     = true;
    protected $migrateOnce = true;
    protected $refresh     = false;
    protected $namespace   = null;

    protected function setUp(): void
    {
        parent::setUp();

        $this->prepareAccounting();
        $this->setConfigValue('accounting_start_date', '2020-01-01');
    }

    protected function tearDown(): void
    {
        $this->releaseOpenTransactions();

        parent::tearDown();
    }

    /**
     * @param list<int>|null $branchIds
     */
    private function closer(?array $branchIds = null, bool $reopen = true): int
    {
        $permissions = ['accounting_periods', AccountingGuard::PERIODS_CLOSE];

        if ($reopen) {
            $permissions[] = AccountingGuard::PERIODS_REOPEN;
        }

        $personId = $this->employeeWithGrants($permissions, $branchIds);
        $this->actAs($personId);

        return $personId;
    }

    private function ago(int $days): string
    {
        return date('Y-m-d', strtotime("-{$days} days"));
    }

    public function testGetIndex_ShowsTheSummaryAndTheTableToAUserWithTheModuleGrant(): void
    {
        $this->actAs($this->employeeWithGrants(['accounting_periods']));

        $response = $this->get('/accounting_periods/closings');

        $response->assertStatus(200);
        $response->assertSee('acc-closing-summary');
        $response->assertSee(lang('Accounting_periods.nothing_closed'));
        $response->assertDontSee('accounting_periods/closings/close_form');
    }

    public function testGetIndex_OffersCloseAndReopenAccordingToThePermissions(): void
    {
        $employee = $this->closer();
        (new ClosedPeriodService())->close(null, $this->ago(30), '', $employee);

        $response = $this->get('/accounting_periods/closings');

        $response->assertSee('closings/close_form?scope=company');
        $response->assertSee('closings/reopen_form?scope=company');

        $this->closer(null, false);

        $response = $this->get('/accounting_periods/closings');

        $response->assertSee('closings/close_form?scope=company');
        $response->assertDontSee('closings/reopen_form?scope=company');
    }

    public function testGetIndex_ShowsAChipForEachBranchOnlyWhenThereAreSeveral(): void
    {
        $this->closer();

        $this->get('/accounting_periods/closings')->assertDontSee('closings/close_form?scope=1');

        $second = $this->createBranch('B2');

        $response = $this->get('/accounting_periods/closings');

        $response->assertSee('closings/close_form?scope=1');
        $response->assertSee('closings/close_form?scope=' . $second);
    }

    public function testGetIndex_OffersTheCompanyClosingOnlyToSomeoneWithEveryBranch(): void
    {
        $second = $this->createBranch('B2');
        $this->closer([$second]);

        $response = $this->get('/accounting_periods/closings');

        $response->assertDontSee('closings/close_form?scope=company');
        $response->assertSee('closings/close_form?scope=' . $second);
    }

    public function testGetSearch_ListsTheClosingsWithTheirScopeAndReopeningDetails(): void
    {
        $employee = $this->closer();
        $service  = new ClosedPeriodService();
        $service->close(null, $this->ago(30), 'Company', $employee);
        $service->close(null, $this->ago(10), 'Later', $employee);
        $service->reopen(null, 'Late invoice', $employee);

        $rows = $this->jsonOf($this->get('/accounting_periods/closings/search'))['rows'];

        $this->assertSame(['Later', 'Company'], array_column($rows, 'note'));
        $this->assertStringContainsString(lang('Accounting_periods.status_reopened'), $rows[0]['status']);
        $this->assertStringContainsString('Late invoice', $rows[0]['reopened']);
        $this->assertSame(lang('Accounting_periods.scope_company'), $rows[1]['scope']);
    }

    public function testGetSearch_HidesTheClosingsOfBranchesOutsideTheScope(): void
    {
        $second   = $this->createBranch('B2');
        $employee = $this->closer();
        $service  = new ClosedPeriodService();
        $service->close(1, $this->ago(30), 'Head office', $employee);
        $service->close($second, $this->ago(20), 'Second', $employee);

        $this->closer([$second]);

        $this->assertSame(['Second'], array_column($this->jsonOf($this->get('/accounting_periods/closings/search'))['rows'], 'note'));
    }

    public function testGetCloseForm_SuggestsTheEndOfLastMonthAndShowsThePreviousClosing(): void
    {
        $this->closer();

        $response = $this->get('/accounting_periods/closings/close_form?scope=company');

        $response->assertSee('value="' . date('Y-m-t', strtotime('first day of last month')) . '"');
    }

    public function testGetCloseForm_IsNotFoundWithoutThePermissionOrForAScopeOutsideTheEmployees(): void
    {
        $second = $this->createBranch('B2');
        $this->actAs($this->employeeWithGrants(['accounting_periods']));

        try {
            $this->get('/accounting_periods/closings/close_form?scope=company');
            $this->fail('Expected the form to be hidden.');
        } catch (PageNotFoundException) {
            $this->addToAssertionCount(1);
        }

        $this->closer([1]);

        $this->expectException(PageNotFoundException::class);

        $this->get('/accounting_periods/closings/close_form?scope=' . $second);
    }

    public function testGetPreview_CountsTheJournalsAndListsTheBlockingDrafts(): void
    {
        $this->closer();
        (new JournalService())->postNew(['date' => $this->ago(50)] + $this->journalInput());
        $draftId = (new JournalService())->saveDraft(['date' => $this->ago(40)] + $this->journalInput());

        $result = $this->jsonOf($this->get('/accounting_periods/closings/preview?scope=company&date=' . $this->ago(30)));

        $this->assertTrue($result['success']);
        $this->assertSame(1, $result['journals']);
        $this->assertSame(1, $result['draft_count']);
        $this->assertSame($draftId, $result['drafts'][0]['id']);
    }

    public function testGetPreview_RefusesAnInvalidDate(): void
    {
        $this->closer();

        $result = $this->jsonOf($this->get('/accounting_periods/closings/preview?scope=company&date=nope'));

        $this->assertFalse($result['success']);
    }

    public function testPostClose_ClosesTheCompanyAndReportsTheDraftsThatBlockIt(): void
    {
        $this->closer();

        $closed = $this->jsonOf($this->post('/accounting_periods/closings/close', ['scope' => 'company', 'date' => $this->ago(30), 'note' => 'Month end']));

        $this->assertTrue($closed['success']);
        $this->assertSame($this->ago(30), (new ClosedPeriodService())->cutoff(null));

        (new JournalService())->saveDraft(['date' => $this->ago(20)] + $this->journalInput());

        $blocked = $this->jsonOf($this->post('/accounting_periods/closings/close', ['scope' => 'company', 'date' => $this->ago(10)]));

        $this->assertFalse($blocked['success']);
        $this->assertSame(lang('Accounting.error_closing_blocked_by_drafts', ['1']), $blocked['message']);
    }

    public function testPostClose_ClosesOneBranchOnly(): void
    {
        $second = $this->createBranch('B2');
        $this->closer();

        $result = $this->jsonOf($this->post('/accounting_periods/closings/close', ['scope' => (string) $second, 'date' => $this->ago(30)]));

        $this->assertTrue($result['success']);
        $this->assertSame($this->ago(30), (new ClosedPeriodService())->cutoff($second));
        $this->assertNull((new ClosedPeriodService())->cutoff(1));
    }

    public function testPostClose_RefusesTheCompanyToSomeoneLimitedToBranchesAndAnUnknownScope(): void
    {
        $this->closer([1]);

        $this->assertFalse($this->jsonOf($this->post('/accounting_periods/closings/close', ['scope' => 'company', 'date' => $this->ago(30)]))['success']);

        $this->expectException(PageNotFoundException::class);

        $this->post('/accounting_periods/closings/close', ['scope' => 'everything', 'date' => $this->ago(30)]);
    }

    public function testPostClose_RefusesSomeoneWithoutThePermission(): void
    {
        $this->actAs($this->employeeWithGrants(['accounting_periods']));

        $this->assertFalse($this->jsonOf($this->post('/accounting_periods/closings/close', ['scope' => 'company', 'date' => $this->ago(30)]))['success']);
    }

    public function testPostReopen_ReopensTheLatestClosingWithAReason(): void
    {
        $employee = $this->closer();
        (new ClosedPeriodService())->close(null, $this->ago(30), '', $employee);

        $missing = $this->jsonOf($this->post('/accounting_periods/closings/reopen', ['scope' => 'company', 'reason' => '']));
        $reopened = $this->jsonOf($this->post('/accounting_periods/closings/reopen', ['scope' => 'company', 'reason' => 'Late invoice']));

        $this->assertFalse($missing['success']);
        $this->assertTrue($reopened['success']);
        $this->assertNull((new ClosedPeriodService())->cutoff(null));
    }

    public function testGetReopenForm_IsNotFoundWithoutThePermission(): void
    {
        $this->closer(null, false);

        $this->expectException(PageNotFoundException::class);

        $this->get('/accounting_periods/closings/reopen_form?scope=company');
    }

    public function testGetIndex_ExplainsWhoGrantsThePermissionToSomeoneWhoCanOnlyView(): void
    {
        $this->actAs($this->employeeWithGrants(['accounting_periods']));

        $this->get('/accounting_periods/closings')->assertSee(lang('Accounting_periods.permission_note'));

        $this->closer(null, false);

        $this->get('/accounting_periods/closings')->assertDontSee(lang('Accounting_periods.permission_note'));
    }

    public function testGetReopenForm_CarriesTheClosingTheDialogShowed(): void
    {
        $employee = $this->closer();
        (new ClosedPeriodService())->close(null, $this->ago(30), '', $employee);

        $this->get('/accounting_periods/closings/reopen_form?scope=company')->assertSee('name="cutoff" value="' . $this->ago(30) . '"');
    }

    public function testPostReopen_RefusesWhileTheYearIsClosedOutAndSaysWhichYear(): void
    {
        $employee = $this->employeeWithGrants(['accounting_periods', AccountingGuard::PERIODS_CLOSE, AccountingGuard::PERIODS_REOPEN, AccountingGuard::YEAR_END_CLOSE]);
        $this->actAs($employee);
        (new JournalService())->postNew(['date' => '2023-05-10'] + $this->journalInput());
        (new ClosedPeriodService())->close(null, '2023-12-31', 'Year', $employee);

        $year    = (int) $this->accountingDb()->table('accounting_fiscal_years')->where('name', '2023')->get()->getRow()->fiscal_year_id;
        $preview = (new YearEndClosing())->preview(null, $year, $employee);
        $closing = (new YearEndClosing())->approve(null, $year, $preview->fingerprint, $employee)[0];

        $result = $this->jsonOf($this->post('/accounting_periods/closings/reopen', ['scope' => 'company', 'reason' => 'Late invoice']));

        $this->assertFalse($result['success']);
        $this->assertSame(lang('Accounting.error_year_end_closed_out', ['2023', '2023-12-31']), $result['message']);
        $this->assertSame('2023-12-31', (new ClosedPeriodService())->cutoff(null));

        (new YearEndClosing())->reverse($closing, 'Back', $employee);

        $this->assertTrue($this->jsonOf($this->post('/accounting_periods/closings/reopen', ['scope' => 'company', 'reason' => 'Late invoice']))['success']);
    }

    public function testPostReopen_RefusesAClosingThatChangedSinceTheDialogWasOpened(): void
    {
        $employee = $this->closer();
        (new ClosedPeriodService())->close(null, $this->ago(40), '', $employee);
        (new ClosedPeriodService())->close(null, $this->ago(10), '', $employee);

        $first  = $this->jsonOf($this->post('/accounting_periods/closings/reopen', ['scope' => 'company', 'reason' => 'First click', 'cutoff' => $this->ago(10)]));
        $second = $this->jsonOf($this->post('/accounting_periods/closings/reopen', ['scope' => 'company', 'reason' => 'Second click', 'cutoff' => $this->ago(10)]));

        $this->assertTrue($first['success']);
        $this->assertFalse($second['success']);
        $this->assertStringContainsString($this->ago(40), $second['message']);
        $this->assertSame($this->ago(40), (new ClosedPeriodService())->cutoff(null));
    }
}
