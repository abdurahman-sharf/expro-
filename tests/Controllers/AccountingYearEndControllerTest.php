<?php

namespace Tests\Controllers;

use App\Libraries\Accounting\AccountingGuard;
use App\Libraries\Accounting\ClosedPeriodService;
use App\Libraries\Accounting\YearEndClosing;
use CodeIgniter\Exceptions\PageNotFoundException;
use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\DatabaseTestTrait;
use Config\Filters;
use Config\Services;
use ReflectionClass;
use Tests\Support\AccountingControllerFixtureTrait;
use Tests\Support\ReportFixtureTrait;

/**
 * @internal
 */
final class AccountingYearEndControllerTest extends CIUnitTestCase
{
    use DatabaseTestTrait;
    use AccountingControllerFixtureTrait;
    use ReportFixtureTrait;

    protected $migrate     = true;
    protected $migrateOnce = true;
    protected $refresh     = false;
    protected $namespace;
    private int $second = 0;

    protected function setUp(): void
    {
        parent::setUp();

        $this->prepareAccounting();
        $this->setConfigValue('accounting_start_date', '2020-01-01');
        $this->second = $this->createBranch('B2', 'Second branch');
    }

    protected function tearDown(): void
    {
        $this->releaseOpenTransactions();
        $this->clearJournals();

        parent::tearDown();
    }

    /**
     * @param list<int>|null $branchIds
     */
    private function closer(?array $branchIds = null, bool $permission = true): int
    {
        $personId = $this->employeeWithGrants(['accounting_periods', ...($permission ? [AccountingGuard::YEAR_END_CLOSE] : [])], $branchIds);
        $this->actAs($personId);

        return $personId;
    }

    private function yearId(string $name = '2025'): int
    {
        return (int) $this->accountingDb()->table('accounting_fiscal_years')->where('name', $name)->get()->getRow()->fiscal_year_id;
    }

    private function twoBranchYear(): void
    {
        $cash = $this->accountId('cash');

        $this->postOn('2025-03-10', 1, [[$cash, '1000.00', '0'], [$this->accountId('sales_revenue'), '0', '1000.00']]);
        $this->postOn('2025-05-10', 1, [[$this->accountId('expenses_default'), '150.00', '0'], [$cash, '0', '150.00']]);
        $this->postOn('2025-06-12', $this->second, [[$this->accountId('expenses_default'), '500.00', '0'], [$cash, '0', '500.00']]);
    }

    private function readyYear(): void
    {
        $this->twoBranchYear();
        $this->closeThrough('2025-12-31');
    }

    private function fingerprint(?int $branchId = null): string
    {
        return (new YearEndClosing())->preview($branchId, $this->yearId(), $this->employeeWithGrants([AccountingGuard::YEAR_END_CLOSE]))->fingerprint;
    }

    public function testGetIndexIsNotFoundWhileAccountingIsOff(): void
    {
        $this->closer();
        $this->setConfigValue('accounting_enabled', '0');

        $this->expectException(PageNotFoundException::class);

        $this->get('/accounting_periods/year_end');
    }

    public function testGetIndexShowsTheClosingsButNoPreviewToAUserWithoutThePermission(): void
    {
        $this->readyYear();
        $this->closer();
        (new YearEndClosing())->approve(1, $this->yearId(), $this->fingerprint(1), $this->employeeWithGrants([AccountingGuard::YEAR_END_CLOSE]));

        $this->closer(null, false);

        $response = $this->get('/accounting_periods/year_end');

        $response->assertStatus(200);
        $response->assertSee('year_end_permission_note');
        $response->assertSee('year_end_closings_table');
        $response->assertDontSee('year_end_preview');
        $response->assertDontSee('year_end_approve');
        $response->assertDontSee('year-end-reverse');
    }

    public function testGetIndexShowsThePreviewOfTheLatestYearWithTheLinesOfEachBranch(): void
    {
        $this->readyYear();
        $this->closer();

        $response = $this->get('/accounting_periods/year_end');

        $response->assertStatus(200);
        $response->assertSee('year_end_preview');
        $response->assertSee('year_end_branch_1');
        $response->assertSee('year_end_branch_' . $this->second);
        $response->assertSee(lang('Accounting_periods.year_end_status_ready'));
        $response->assertSee('year-end-retained-row');
        $response->assertSee('1,000.00');
        $response->assertSee(lang('Accounting_periods.year_end_loss'));
        $response->assertSee('year_end_approve');
    }

    public function testGetIndexOffersTheApproveButtonWithTheFingerprintOfWhatItShows(): void
    {
        $this->readyYear();
        $this->closer();

        $response = $this->get('/accounting_periods/year_end?year=' . $this->yearId() . '&scope=company');

        $response->assertSee('year_end/approve_form?year=' . $this->yearId() . '&amp;scope=company&amp;fingerprint=' . $this->fingerprint());
    }

    public function testGetIndexExplainsWhatStopsTheClosingAndOffersNoApproveButton(): void
    {
        $this->twoBranchYear();
        $this->closer();

        $response = $this->get('/accounting_periods/year_end');

        $response->assertSee('year-end-blocker');
        $response->assertSee(lang('Accounting.error_year_end_period_not_closed', ['2025', '2025-12-31']));
        $response->assertDontSee('id="year_end_approve"');
    }

    public function testGetIndexSaysWhichBranchHasNothingToCloseAndWhichIsClosedAlready(): void
    {
        $this->postOn('2025-03-10', 1, [[$this->accountId('cash'), '40.00', '0'], [$this->accountId('sales_revenue'), '0', '40.00']]);
        $this->closeThrough('2025-12-31');
        $this->closer();

        $response = $this->get('/accounting_periods/year_end');

        $response->assertSee(lang('Accounting_periods.year_end_status_no_activity'));
        $response->assertSee(lang('Accounting_periods.year_end_nothing'));

        (new YearEndClosing())->approve(1, $this->yearId(), $this->fingerprint(1), $this->employeeWithGrants([AccountingGuard::YEAR_END_CLOSE]));

        $response = $this->get('/accounting_periods/year_end');

        $response->assertSee(lang('Accounting_periods.year_end_status_already_closed'));
        $response->assertSee(lang('Accounting_periods.year_end_no_ready'));
        $response->assertDontSee('id="year_end_approve"');
    }

    public function testGetIndexOffersTheCompanyOnlyToSomeoneWithEveryBranch(): void
    {
        $this->readyYear();
        $this->closer([$this->second]);

        $response = $this->get('/accounting_periods/year_end');

        $response->assertDontSee('value="company"');
        $response->assertSee('value="' . $this->second . '"');
        $response->assertSee('year_end_branch_' . $this->second);
        $response->assertDontSee('year_end_branch_1');

        $this->expectException(PageNotFoundException::class);

        $this->get('/accounting_periods/year_end?scope=company');
    }

    public function testGetIndexIsNotFoundForABranchOutsideTheEmployeesScope(): void
    {
        $this->readyYear();
        $this->closer([1]);

        $this->expectException(PageNotFoundException::class);

        $this->get('/accounting_periods/year_end?scope=' . $this->second);
    }

    public function testGetIndexShowsTheClosingsOfTheBranchesTheEmployeeMaySeeOnly(): void
    {
        $this->readyYear();
        $closer = $this->employeeWithGrants([AccountingGuard::YEAR_END_CLOSE]);
        $run    = new YearEndClosing();
        $run->approve(null, $this->yearId(), $this->fingerprint(), $closer);

        $this->closer([$this->second]);

        $response = $this->get('/accounting_periods/year_end');

        $response->assertSee('B2');
        $response->assertDontSee('<bdi dir="ltr">HQ</bdi> Default');

        $this->closer();

        $this->assertSame(2, substr_count($this->get('/accounting_periods/year_end')->getBody(), 'year-end-reverse'));
    }

    public function testGetIndexListsTheYearsThatEndedAndMarksTheOnesThatHaveAClosing(): void
    {
        $this->readyYear();
        $this->closer();
        (new YearEndClosing())->approve(1, $this->yearId(), $this->fingerprint(1), $this->employeeWithGrants([AccountingGuard::YEAR_END_CLOSE]));

        $response = $this->get('/accounting_periods/year_end');

        $response->assertSee('2025 (' . lang('Accounting_periods.year_end_closed_mark') . ')');
        $response->assertDontSee('>2035<');
    }

    public function testGetIndexAddsATabToThePeriodsScreens(): void
    {
        $this->closer();

        foreach (['/accounting_periods', '/accounting_periods/closings', '/accounting_periods/opening'] as $page) {
            $this->get($page)->assertSee('accounting_periods/year_end');
        }
    }

    public function testGetApproveFormAsksForConfirmationAndCarriesTheFingerprint(): void
    {
        $this->readyYear();
        $this->closer();
        $fingerprint = $this->fingerprint();

        $response = $this->get('/accounting_periods/year_end/approve_form?year=' . $this->yearId() . '&scope=company&fingerprint=' . $fingerprint);

        $response->assertStatus(200);
        $response->assertSee('action="' . base_url('accounting_periods/year_end/approve') . '"');
        $response->assertSee('method="post"');
        $response->assertSee('name="fingerprint" value="' . $fingerprint . '"');
        $response->assertSee('name="confirm"');
        $response->assertSee(lang('Accounting_periods.year_end_approve_warning'));
    }

    public function testFormsCarryTheTokenAgainstForgedRequestsWhenTheProtectionIsOn(): void
    {
        $this->readyYear();
        $employee = $this->employeeWithGrants([AccountingGuard::YEAR_END_CLOSE]);
        $preview  = (new YearEndClosing())->preview(null, $this->yearId(), $employee);

        service('filters')->enableFilters(['csrf'], 'before');

        try {
            $approve = view('accounting_periods/year_end_approve_form', ['preview' => $preview, 'scope' => 'company', 'fingerprint' => $preview->fingerprint, 'year_id' => $this->yearId()]);
            $reverse = view('accounting_periods/year_end_reverse_form', ['closing' => (object) ['closing_id' => 5, 'year_name' => '2025', 'branch_code' => 'HQ']]);
        } finally {
            Services::resetSingle('filters');
        }

        $this->assertStringContainsString('name="' . csrf_token() . '"', $approve);
        $this->assertStringContainsString('name="' . csrf_token() . '"', $reverse);
    }

    public function testRoutesAreCoveredByTheGlobalProtectionAgainstForgedRequests(): void
    {
        $globals = (new ReflectionClass(Filters::class))->getDefaultProperties()['globals'];

        $this->assertArrayHasKey('csrf', $globals['before']);

        foreach (['accounting_periods/year_end/approve', 'accounting_periods/year_end/reverse/5'] as $uri) {
            $this->assertSame(0, preg_match('#^(' . $globals['before']['csrf']['except'] . ')#', $uri), "{$uri} is not excepted");
        }
    }

    public function testGetApproveFormIsNotFoundWithoutThePermissionAndWhenThereIsNothingToApprove(): void
    {
        $this->readyYear();
        $this->closer(null, false);

        try {
            $this->get('/accounting_periods/year_end/approve_form?year=' . $this->yearId() . '&scope=company');
            $this->fail('The form must be hidden without the permission.');
        } catch (PageNotFoundException) {
            $this->addToAssertionCount(1);
        }

        $this->closer();
        $this->accountingDb()->table('accounting_closed_periods')->truncate();

        $this->expectException(PageNotFoundException::class);

        $this->get('/accounting_periods/year_end/approve_form?year=' . $this->yearId() . '&scope=company');
    }

    public function testPostApproveClosesTheYearAndSendsTheUserBackToTheScreenWithANotice(): void
    {
        $this->readyYear();
        $this->closer();

        $result = $this->jsonOf($this->post('/accounting_periods/year_end/approve', ['year' => $this->yearId(), 'scope' => 'company', 'fingerprint' => $this->fingerprint(), 'confirm' => '1']));

        $this->assertTrue($result['success'], $result['message'] ?? '');
        $this->assertSame(lang('Accounting_periods.year_end_approved'), $result['message']);
        $this->assertSame(base_url('accounting_periods/year_end') . '?year=' . $this->yearId() . '&scope=company', $result['redirect']);
        $this->assertSame(2, $this->countRows('accounting_year_end_closings'));
    }

    public function testPostApproveClosesOneBranchOnly(): void
    {
        $this->readyYear();
        $this->closer();

        $result = $this->jsonOf($this->post('/accounting_periods/year_end/approve', ['year' => $this->yearId(), 'scope' => (string) $this->second, 'fingerprint' => $this->fingerprint($this->second)]));

        $this->assertTrue($result['success']);
        $this->assertSame([(string) $this->second], array_column($this->accountingDb()->table('accounting_year_end_closings')->get()->getResultArray(), 'branch_id'));
    }

    public function testPostApproveRefusesWithoutThePermissionAndWritesNothing(): void
    {
        $this->readyYear();
        $fingerprint = $this->fingerprint();
        $this->closer(null, false);

        $result = $this->jsonOf($this->post('/accounting_periods/year_end/approve', ['year' => $this->yearId(), 'scope' => 'company', 'fingerprint' => $fingerprint]));

        $this->assertFalse($result['success']);
        $this->assertSame(lang('Accounting.error_permission_denied'), $result['message']);
        $this->assertSame(0, $this->countRows('accounting_year_end_closings'));
    }

    public function testPostApproveRefusesAFingerprintThatIsNotTheOneOfTheBooks(): void
    {
        $this->readyYear();
        $this->closer();

        $result = $this->jsonOf($this->post('/accounting_periods/year_end/approve', ['year' => $this->yearId(), 'scope' => 'company', 'fingerprint' => 'stale']));

        $this->assertFalse($result['success']);
        $this->assertSame(lang('Accounting.error_year_end_preview_changed'), $result['message']);
        $this->assertSame(0, $this->countRows('accounting_year_end_closings'));
    }

    public function testPostApproveRefusesTheCompanyToSomeoneLimitedToABranch(): void
    {
        $this->readyYear();
        $fingerprint = $this->fingerprint();
        $this->closer([1]);

        $result = $this->jsonOf($this->post('/accounting_periods/year_end/approve', ['year' => $this->yearId(), 'scope' => 'company', 'fingerprint' => $fingerprint]));

        $this->assertFalse($result['success']);
        $this->assertSame(0, $this->countRows('accounting_year_end_closings'));
    }

    public function testPostApproveIsNotFoundForAScopeThatIsNeitherTheCompanyNorABranchNumber(): void
    {
        $this->readyYear();
        $this->closer();

        $this->expectException(PageNotFoundException::class);

        $this->post('/accounting_periods/year_end/approve', ['year' => $this->yearId(), 'scope' => 'everything', 'fingerprint' => $this->fingerprint()]);
    }

    public function testPostApproveReportsThePeriodsThatAreNotClosedAsTheReason(): void
    {
        $this->twoBranchYear();
        $this->closer();

        $result = $this->jsonOf($this->post('/accounting_periods/year_end/approve', ['year' => $this->yearId(), 'scope' => 'company', 'fingerprint' => $this->fingerprint()]));

        $this->assertFalse($result['success']);
        $this->assertSame(lang('Accounting.error_year_end_period_not_closed', ['2025', '2025-12-31']), $result['message']);
    }

    public function testApproveAndReverseAreNotReachableByAGetRequest(): void
    {
        $this->readyYear();
        $this->closer();
        $closing = (new YearEndClosing())->approve(1, $this->yearId(), $this->fingerprint(1), $this->employeeWithGrants([AccountingGuard::YEAR_END_CLOSE]))[0];

        foreach (['/accounting_periods/year_end/approve?year=' . $this->yearId() . '&scope=company', '/accounting_periods/year_end/reverse/' . $closing] as $url) {
            try {
                $this->get($url);
                $this->fail("A GET to {$url} must change nothing.");
            } catch (PageNotFoundException) {
                $this->addToAssertionCount(1);
            }
        }

        $this->assertSame('active', $this->accountingDb()->table('accounting_year_end_closings')->where('closing_id', $closing)->get()->getRow()->status);
    }

    public function testGetReverseFormAsksForAReason(): void
    {
        $this->readyYear();
        $this->closer();
        $closing = (new YearEndClosing())->approve(1, $this->yearId(), $this->fingerprint(1), $this->employeeWithGrants([AccountingGuard::YEAR_END_CLOSE]))[0];

        $response = $this->get('/accounting_periods/year_end/reverse_form/' . $closing);

        $response->assertStatus(200);
        $response->assertSee('action="' . base_url('accounting_periods/year_end/reverse/' . $closing) . '"');
        $response->assertSee('name="reason"');
        $response->assertSee(lang('Accounting_periods.year_end_reverse_warning'));
    }

    public function testGetReverseFormIsNotFoundWithoutThePermissionForAClosingTakenBackAndForABranchOutsideTheScope(): void
    {
        $this->readyYear();
        $closer  = $this->employeeWithGrants([AccountingGuard::YEAR_END_CLOSE]);
        $run     = new YearEndClosing();
        $closing = $run->approve(1, $this->yearId(), $this->fingerprint(1), $closer)[0];

        $this->closer(null, false);

        foreach ([$closing, 99999] as $id) {
            try {
                $this->get('/accounting_periods/year_end/reverse_form/' . $id);
                $this->fail('The form must be hidden.');
            } catch (PageNotFoundException) {
                $this->addToAssertionCount(1);
            }
        }

        $this->closer([$this->second]);

        try {
            $this->get('/accounting_periods/year_end/reverse_form/' . $closing);
            $this->fail('A closing of another branch must be hidden.');
        } catch (PageNotFoundException) {
            $this->addToAssertionCount(1);
        }

        $this->closer();
        $run->reverse($closing, 'Back', $closer);

        $this->expectException(PageNotFoundException::class);

        $this->get('/accounting_periods/year_end/reverse_form/' . $closing);
    }

    public function testPostReverseTakesTheClosingBackWithItsReason(): void
    {
        $this->readyYear();
        $this->closer();
        $closing = (new YearEndClosing())->approve(1, $this->yearId(), $this->fingerprint(1), $this->employeeWithGrants([AccountingGuard::YEAR_END_CLOSE]))[0];

        $result = $this->jsonOf($this->post('/accounting_periods/year_end/reverse/' . $closing, ['reason' => 'Wrong figures']));

        $this->assertTrue($result['success']);
        $this->assertSame(lang('Accounting_periods.year_end_reversed'), $result['message']);
        $this->assertSame('reversed', $this->accountingDb()->table('accounting_year_end_closings')->where('closing_id', $closing)->get()->getRow()->status);
        $this->assertSame('Wrong figures', $this->accountingDb()->table('accounting_year_end_closings')->where('closing_id', $closing)->get()->getRow()->reverse_reason);
    }

    public function testPostReverseRefusesWithoutAReasonWithoutThePermissionAndOutsideTheScope(): void
    {
        $this->readyYear();
        $closer  = $this->employeeWithGrants([AccountingGuard::YEAR_END_CLOSE]);
        $closing = (new YearEndClosing())->approve(1, $this->yearId(), $this->fingerprint(1), $closer)[0];

        $this->closer();

        $this->assertSame(lang('Accounting.error_reason_required'), $this->jsonOf($this->post('/accounting_periods/year_end/reverse/' . $closing, ['reason' => '  ']))['message']);

        $this->closer(null, false);

        $this->assertSame(lang('Accounting.error_permission_denied'), $this->jsonOf($this->post('/accounting_periods/year_end/reverse/' . $closing, ['reason' => 'Why']))['message']);

        $this->closer([$this->second]);

        $this->assertFalse($this->jsonOf($this->post('/accounting_periods/year_end/reverse/' . $closing, ['reason' => 'Why']))['success']);
        $this->assertSame('active', $this->accountingDb()->table('accounting_year_end_closings')->where('closing_id', $closing)->get()->getRow()->status);
    }

    public function testReopenOfThePeriodsIsRefusedOnTheClosingsScreenWhileTheYearIsClosedOut(): void
    {
        $this->twoBranchYear();
        $closer = $this->employeeWithGrants([AccountingGuard::YEAR_END_CLOSE, AccountingGuard::PERIODS_CLOSE, AccountingGuard::PERIODS_REOPEN, 'accounting_periods']);
        $this->actAs($closer);
        (new ClosedPeriodService())->close(null, '2025-12-31', 'Year', $closer);
        (new YearEndClosing())->approve(null, $this->yearId(), $this->fingerprint(), $closer);

        $result = $this->jsonOf($this->post('/accounting_periods/closings/reopen', ['scope' => 'company', 'reason' => 'Late invoice']));

        $this->assertFalse($result['success']);
        $this->assertSame(lang('Accounting.error_year_end_closed_out', ['2025', '2025-12-31']), $result['message']);
    }
}
