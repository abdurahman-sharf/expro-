<?php

namespace Tests\Controllers;

use App\Libraries\Accounting\AccountingGuard;
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

    public function testGetIndex_IsNotFoundWhileAccountingIsOff(): void
    {
        $this->closer();
        $this->setConfigValue('accounting_enabled', '0');

        $this->expectException(PageNotFoundException::class);

        $this->get('/accounting_periods/year_end');
    }

    public function testGetIndex_ShowsTheClosingsButNoPreviewToAUserWithoutThePermission(): void
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

    public function testGetIndex_SelectsTheLatestYearThatHasEnded(): void
    {
        $this->closer();

        $latest = (string) ((int) date('Y') - 1);

        $this->get('/accounting_periods/year_end')->assertSee('value="' . $this->yearId($latest) . '" selected');
    }

    public function testGetIndex_ShowsThePreviewOfTheYearWithTheLinesOfEachBranch(): void
    {
        $this->readyYear();
        $this->closer();

        $response = $this->get('/accounting_periods/year_end?year=' . $this->yearId());

        $response->assertStatus(200);
        $response->assertSee('year_end_preview');
        $response->assertSee('id="year_end_branch_1"');
        $response->assertSee('id="year_end_branch_' . $this->second . '"');
        $response->assertSee(lang('Accounting_periods.year_end_status_ready'));
        $response->assertSee('year-end-retained-row');
        $response->assertSee('1,000.00');
        $response->assertSee(lang('Accounting_periods.year_end_loss'));
        $response->assertSee('year_end_approve');
    }

    public function testGetIndex_OffersTheApproveButtonWithTheFingerprintOfWhatItShows(): void
    {
        $this->readyYear();
        $this->closer();

        $response = $this->get('/accounting_periods/year_end?year=' . $this->yearId() . '&scope=company');

        $response->assertSee('year_end/approve_form?year=' . $this->yearId() . '&amp;scope=company&amp;fingerprint=' . $this->fingerprint());
    }

    public function testGetIndex_ExplainsWhatStopsTheClosingAndOffersNoApproveButton(): void
    {
        $this->twoBranchYear();
        $this->closer();

        $response = $this->get('/accounting_periods/year_end?year=' . $this->yearId());

        $response->assertSee('year-end-blocker');
        $response->assertSee(lang('Accounting.error_year_end_period_not_closed', ['2025', '2025-12-31']));
        $response->assertDontSee('id="year_end_approve"');
    }

    public function testGetIndex_ExplainsThatAClosingTooLargeForAJournalCannotBeApproved(): void
    {
        foreach (['2025-03-10', '2025-03-11'] as $date) {
            $this->postOn($date, 1, [[$this->accountId('cash'), '50000000000.0000', '0'], [$this->accountId('sales_revenue'), '0', '50000000000.0000']]);
        }

        $this->closeThrough('2025-12-31');
        $this->closer();

        $response = $this->get('/accounting_periods/year_end?year=' . $this->yearId() . '&scope=company');

        $response->assertSee('year-end-blocker');
        $response->assertSee('99999999999.9999');
        $response->assertDontSee('id="year_end_approve"');
    }

    public function testGetIndex_SaysWhichBranchHasNothingToCloseAndWhichIsClosedAlready(): void
    {
        $this->postOn('2025-03-10', 1, [[$this->accountId('cash'), '40.00', '0'], [$this->accountId('sales_revenue'), '0', '40.00']]);
        $this->closeThrough('2025-12-31');
        $this->closer();

        $response = $this->get('/accounting_periods/year_end?year=' . $this->yearId());

        $response->assertSee(lang('Accounting_periods.year_end_status_no_activity'));
        $response->assertSee(lang('Accounting_periods.year_end_nothing'));

        (new YearEndClosing())->approve(1, $this->yearId(), $this->fingerprint(1), $this->employeeWithGrants([AccountingGuard::YEAR_END_CLOSE]));

        $response = $this->get('/accounting_periods/year_end?year=' . $this->yearId());

        $response->assertSee(lang('Accounting_periods.year_end_status_already_closed'));
        $response->assertSee(lang('Accounting_periods.year_end_no_ready'));
        $response->assertDontSee('id="year_end_approve"');
    }

    public function testGetIndex_OffersTheCompanyOnlyToSomeoneWithEveryBranch(): void
    {
        $this->readyYear();
        $this->closer([$this->second]);

        $response = $this->get('/accounting_periods/year_end');

        $response->assertDontSee('value="company"');
        $response->assertSee('value="' . $this->second . '"');
        $response->assertSee('id="year_end_branch_' . $this->second . '"');
        $response->assertDontSee('id="year_end_branch_1"');

        $this->expectException(PageNotFoundException::class);

        $this->get('/accounting_periods/year_end?scope=company');
    }

    public function testGetIndex_IsNotFoundForABranchOutsideTheEmployeesScope(): void
    {
        $this->readyYear();
        $this->closer([1]);

        $this->expectException(PageNotFoundException::class);

        $this->get('/accounting_periods/year_end?scope=' . $this->second);
    }

    public function testGetIndex_ShowsTheClosingsOfTheBranchesTheEmployeeMaySeeOnly(): void
    {
        $this->readyYear();
        $closer = $this->employeeWithGrants([AccountingGuard::YEAR_END_CLOSE]);
        (new YearEndClosing())->approve(null, $this->yearId(), $this->fingerprint(), $closer);

        $rows   = $this->accountingDb()->table('accounting_year_end_closings')->get()->getResult();
        $closed = [];

        foreach ($rows as $row) {
            $closed[(int) $row->branch_id] = $row;
        }

        $this->closer([$this->second]);

        $body = $this->get('/accounting_periods/year_end')->getBody();

        $this->assertStringContainsString('accounting_journals/view/' . $closed[$this->second]->journal_id . '"', $body);
        $this->assertStringContainsString('reverse_form/' . $closed[$this->second]->closing_id . '"', $body);
        $this->assertStringNotContainsString('accounting_journals/view/' . $closed[1]->journal_id . '"', $body, 'The closing of the other branch is not listed');
        $this->assertStringNotContainsString('reverse_form/' . $closed[1]->closing_id . '"', $body);

        $this->closer();

        $body = $this->get('/accounting_periods/year_end')->getBody();

        $this->assertStringContainsString('accounting_journals/view/' . $closed[1]->journal_id . '"', $body);
        $this->assertStringContainsString('accounting_journals/view/' . $closed[$this->second]->journal_id . '"', $body);
        $this->assertSame(2, substr_count($body, 'year-end-reverse'));
    }

    public function testGetIndex_ListsTheYearsThatEndedAndMarksTheOnesThatHaveAClosing(): void
    {
        $this->readyYear();
        $this->closer();
        (new YearEndClosing())->approve(1, $this->yearId(), $this->fingerprint(1), $this->employeeWithGrants([AccountingGuard::YEAR_END_CLOSE]));

        $response = $this->get('/accounting_periods/year_end');

        $response->assertSee('2025 (' . lang('Accounting_periods.year_end_closed_mark') . ')');
        $response->assertDontSee('>' . date('Y') . '<');
    }

    public function testGetIndex_MarksOnlyTheYearsClosedInTheBranchesTheEmployeeMaySee(): void
    {
        $this->readyYear();
        $this->closer();
        (new YearEndClosing())->approve(1, $this->yearId(), $this->fingerprint(1), $this->employeeWithGrants([AccountingGuard::YEAR_END_CLOSE]));

        $this->closer([$this->second]);

        $this->get('/accounting_periods/year_end')->assertDontSee('2025 (' . lang('Accounting_periods.year_end_closed_mark') . ')');

        $this->closer([1]);

        $this->get('/accounting_periods/year_end')->assertSee('2025 (' . lang('Accounting_periods.year_end_closed_mark') . ')');
    }

    public function testGetIndex_AddsATabToThePeriodsScreens(): void
    {
        $this->closer();

        foreach (['/accounting_periods', '/accounting_periods/closings', '/accounting_periods/opening'] as $page) {
            $this->get($page)->assertSee('accounting_periods/year_end');
        }
    }

    public function testGetApproveForm_AsksForConfirmationAndCarriesTheFingerprint(): void
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

    public function testGetApproveForm_CarriesTheFingerprintOfThePageEvenWhenTheBooksChangedSince(): void
    {
        $this->readyYear();
        $this->closer();
        $stale = str_repeat('ab', 32);

        $response = $this->get('/accounting_periods/year_end/approve_form?year=' . $this->yearId() . '&scope=company&fingerprint=' . $stale);

        $response->assertStatus(200);
        $response->assertSee('name="fingerprint" value="' . $stale . '"');
        $response->assertDontSee($this->fingerprint());
    }

    public function testGetApproveForm_IsNotFoundWithoutTheFingerprintOfThePreview(): void
    {
        $this->readyYear();
        $this->closer();

        foreach (['', '&fingerprint=', '&fingerprint=short', '&fingerprint=' . str_repeat('G', 64), '&fingerprint[]=' . str_repeat('a', 64)] as $suffix) {
            try {
                $this->get('/accounting_periods/year_end/approve_form?year=' . $this->yearId() . '&scope=company' . $suffix);
                $this->fail("The form must not open for [{$suffix}].");
            } catch (PageNotFoundException) {
                $this->addToAssertionCount(1);
            }
        }
    }

    public function testGetApproveForm_CarriesTheTokenAgainstForgedRequestsWhenTheProtectionIsOn(): void
    {
        $this->readyYear();
        $preview = (new YearEndClosing())->preview(null, $this->yearId(), $this->employeeWithGrants([AccountingGuard::YEAR_END_CLOSE]));

        service('filters')->enableFilters(['csrf'], 'before');

        try {
            $html = view('accounting_periods/year_end_approve_form', ['preview' => $preview, 'scope' => 'company', 'fingerprint' => $preview->fingerprint, 'year_id' => $this->yearId()]);
        } finally {
            Services::resetSingle('filters');
        }

        $this->assertStringContainsString('name="' . csrf_token() . '"', $html);
    }

    public function testGetReverseForm_CarriesTheTokenAgainstForgedRequestsWhenTheProtectionIsOn(): void
    {
        service('filters')->enableFilters(['csrf'], 'before');

        try {
            $html = view('accounting_periods/year_end_reverse_form', ['closing' => (object) ['closing_id' => 5, 'year_name' => '2025', 'branch_code' => 'HQ']]);
        } finally {
            Services::resetSingle('filters');
        }

        $this->assertStringContainsString('name="' . csrf_token() . '"', $html);
    }

    public function testPostApprove_AndPostReverseAreCoveredByTheGlobalProtectionAgainstForgedRequests(): void
    {
        $globals = (new ReflectionClass(Filters::class))->getDefaultProperties()['globals'];

        $this->assertArrayHasKey('csrf', $globals['before']);

        foreach (['accounting_periods/year_end/approve', 'accounting_periods/year_end/reverse/5'] as $uri) {
            $this->assertSame(0, preg_match('#^(' . $globals['before']['csrf']['except'] . ')#', $uri), "{$uri} is not excepted");
        }
    }

    public function testGetApproveForm_IsNotFoundWithoutThePermissionAndWhenThereIsNothingToApprove(): void
    {
        $this->readyYear();
        $this->closer(null, false);

        try {
            $this->get('/accounting_periods/year_end/approve_form?year=' . $this->yearId() . '&scope=company&fingerprint=' . $this->fingerprint());
            $this->fail('The form must be hidden without the permission.');
        } catch (PageNotFoundException) {
            $this->addToAssertionCount(1);
        }

        $this->closer();
        $this->accountingDb()->table('accounting_closed_periods')->truncate();

        $this->expectException(PageNotFoundException::class);

        $this->get('/accounting_periods/year_end/approve_form?year=' . $this->yearId() . '&scope=company&fingerprint=' . $this->fingerprint());
    }

    public function testPostApprove_ClosesTheYearAndSendsTheUserBackToTheScreenWithANotice(): void
    {
        $this->readyYear();
        $this->closer();

        $result = $this->jsonOf($this->post('/accounting_periods/year_end/approve', ['year' => $this->yearId(), 'scope' => 'company', 'fingerprint' => $this->fingerprint(), 'confirm' => '1']));

        $this->assertTrue($result['success'], $result['message'] ?? '');
        $this->assertSame(lang('Accounting_periods.year_end_approved'), $result['message']);
        $this->assertSame(base_url('accounting_periods/year_end') . '?year=' . $this->yearId() . '&scope=company', $result['redirect']);
        $this->assertSame(2, $this->countRows('accounting_year_end_closings'));
    }

    public function testPostApprove_ClosesOneBranchOnly(): void
    {
        $this->readyYear();
        $this->closer();

        $result = $this->jsonOf($this->post('/accounting_periods/year_end/approve', ['year' => $this->yearId(), 'scope' => (string) $this->second, 'fingerprint' => $this->fingerprint($this->second)]));

        $this->assertTrue($result['success']);
        $this->assertSame([(string) $this->second], array_column($this->accountingDb()->table('accounting_year_end_closings')->get()->getResultArray(), 'branch_id'));
    }

    public function testPostApprove_RefusesWithoutThePermissionAndWritesNothing(): void
    {
        $this->readyYear();
        $fingerprint = $this->fingerprint();
        $this->closer(null, false);

        $result = $this->jsonOf($this->post('/accounting_periods/year_end/approve', ['year' => $this->yearId(), 'scope' => 'company', 'fingerprint' => $fingerprint]));

        $this->assertFalse($result['success']);
        $this->assertSame(lang('Accounting.error_permission_denied'), $result['message']);
        $this->assertSame(0, $this->countRows('accounting_year_end_closings'));
    }

    public function testPostApprove_RefusesAFingerprintThatIsNotTheOneOfTheBooks(): void
    {
        $this->readyYear();
        $this->closer();

        $result = $this->jsonOf($this->post('/accounting_periods/year_end/approve', ['year' => $this->yearId(), 'scope' => 'company', 'fingerprint' => 'stale']));

        $this->assertFalse($result['success']);
        $this->assertSame(lang('Accounting.error_year_end_preview_changed'), $result['message']);
        $this->assertSame(0, $this->countRows('accounting_year_end_closings'));
    }

    public function testPostApprove_RefusesAFingerprintThatIsNotText(): void
    {
        $this->readyYear();
        $this->closer();

        $result = $this->jsonOf($this->post('/accounting_periods/year_end/approve', ['year' => $this->yearId(), 'scope' => 'company', 'fingerprint' => ['a']]));

        $this->assertFalse($result['success']);
        $this->assertSame(lang('Accounting.error_year_end_preview_changed'), $result['message']);
        $this->assertSame(0, $this->countRows('accounting_year_end_closings'));
    }

    public function testPostApprove_RefusesTheCompanyToSomeoneLimitedToABranch(): void
    {
        $this->readyYear();
        $fingerprint = $this->fingerprint();
        $this->closer([1]);

        $result = $this->jsonOf($this->post('/accounting_periods/year_end/approve', ['year' => $this->yearId(), 'scope' => 'company', 'fingerprint' => $fingerprint]));

        $this->assertFalse($result['success']);
        $this->assertSame(0, $this->countRows('accounting_year_end_closings'));
    }

    public function testPostApprove_IsNotFoundForAScopeThatIsNeitherTheCompanyNorABranchNumber(): void
    {
        $this->readyYear();
        $this->closer();

        $this->expectException(PageNotFoundException::class);

        $this->post('/accounting_periods/year_end/approve', ['year' => $this->yearId(), 'scope' => 'everything', 'fingerprint' => $this->fingerprint()]);
    }

    public function testPostApprove_ReportsThePeriodsThatAreNotClosedAsTheReason(): void
    {
        $this->twoBranchYear();
        $this->closer();

        $result = $this->jsonOf($this->post('/accounting_periods/year_end/approve', ['year' => $this->yearId(), 'scope' => 'company', 'fingerprint' => $this->fingerprint()]));

        $this->assertFalse($result['success']);
        $this->assertSame(lang('Accounting.error_year_end_period_not_closed', ['2025', '2025-12-31']), $result['message']);
    }

    public function testPostApprove_IsNotReachableByAGetRequest(): void
    {
        $this->readyYear();
        $this->closer();

        try {
            $this->get('/accounting_periods/year_end/approve?year=' . $this->yearId() . '&scope=company&fingerprint=' . $this->fingerprint());
            $this->fail('A GET must change nothing.');
        } catch (PageNotFoundException) {
            $this->addToAssertionCount(1);
        }

        $this->assertSame(0, $this->countRows('accounting_year_end_closings'));
    }

    public function testPostReverse_IsNotReachableByAGetRequest(): void
    {
        $this->readyYear();
        $this->closer();
        $closing = (new YearEndClosing())->approve(1, $this->yearId(), $this->fingerprint(1), $this->employeeWithGrants([AccountingGuard::YEAR_END_CLOSE]))[0];

        try {
            $this->get('/accounting_periods/year_end/reverse/' . $closing . '?reason=Why');
            $this->fail('A GET must change nothing.');
        } catch (PageNotFoundException) {
            $this->addToAssertionCount(1);
        }

        $this->assertSame('active', $this->accountingDb()->table('accounting_year_end_closings')->where('closing_id', $closing)->get()->getRow()->status);
    }

    public function testGetReverseForm_AsksForAReason(): void
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

    public function testGetReverseForm_IsNotFoundWithoutThePermissionForAClosingTakenBackAndForABranchOutsideTheScope(): void
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

    public function testPostReverse_TakesTheClosingBackWithItsReason(): void
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

    public function testPostReverse_RefusesAReasonThatIsNotText(): void
    {
        $this->readyYear();
        $this->closer();
        $closing = (new YearEndClosing())->approve(1, $this->yearId(), $this->fingerprint(1), $this->employeeWithGrants([AccountingGuard::YEAR_END_CLOSE]))[0];

        $result = $this->jsonOf($this->post('/accounting_periods/year_end/reverse/' . $closing, ['reason' => ['Why']]));

        $this->assertFalse($result['success']);
        $this->assertSame(lang('Accounting.error_reason_required'), $result['message']);
        $this->assertSame('active', $this->accountingDb()->table('accounting_year_end_closings')->where('closing_id', $closing)->get()->getRow()->status);
    }

    public function testPostReverse_RefusesWithoutAReasonWithoutThePermissionAndOutsideTheScope(): void
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

    /**
     * @return list<list<string>>
     */
    private function csvRows(\CodeIgniter\Test\TestResponse $response): array
    {
        $body = $response->response()->getBody();

        $this->assertStringStartsWith("\xEF\xBB\xBF", $body);

        $handle = fopen('php://temp', 'r+b');
        fwrite($handle, substr($body, 3));
        rewind($handle);

        $rows = [];

        while (($row = fgetcsv($handle, 0, ',', '"', '')) !== false) {
            $rows[] = $row === [null] ? [] : $row;
        }

        return $rows;
    }

    public function testGetCsv_GivesThePreviewOfTheScreenWithTheLinesAndTheResultOfEachBranch(): void
    {
        $this->readyYear();
        $this->closer();

        $response = $this->get('/accounting_periods/year_end/csv?year=' . $this->yearId() . '&scope=company');

        $response->assertStatus(200);
        $this->assertStringContainsString('attachment; filename="year_end_preview_' . $this->yearId() . '_company.csv"', $response->response()->getHeaderLine('Content-Disposition'));

        $rows = $this->csvRows($response);

        $this->assertSame([lang('Accounting_periods.year_end_title')], $rows[0]);
        $this->assertSame(lang('Accounting_periods.year_end_period', ['2025', '2025-01-01', '2025-12-31']), $rows[1][0]);

        $heads = array_values(array_filter($rows, static fn (array $row): bool => count($row) === 1 && str_contains($row[0], ' (')));

        $this->assertCount(2, $heads, 'A heading for each branch');
        $this->assertContains('1000.0000', array_merge(...$rows));
        $this->assertNotNull(array_values(array_filter($rows, static fn (array $row): bool => ($row[0] ?? '') === lang('Accounting_periods.year_end_loss')))[0] ?? null);
    }

    public function testGetCsv_GivesTheTotalsTheScreenGivesForABranch(): void
    {
        $this->readyYear();
        $this->closer();

        $rows = $this->csvRows($this->get('/accounting_periods/year_end/csv?year=' . $this->yearId() . '&scope=' . $this->second));

        $revenue = array_values(array_filter($rows, static fn (array $row): bool => ($row[0] ?? '') === lang('Accounting_periods.year_end_revenue')));
        $expense = array_values(array_filter($rows, static fn (array $row): bool => ($row[0] ?? '') === lang('Accounting_periods.year_end_expense')));

        $this->assertSame('0.0000', $revenue[0][1]);
        $this->assertSame('500.0000', $expense[0][1]);
        $this->assertStringNotContainsString('1000.0000', implode(',', array_merge(...$rows)), 'The other branch is not in the file');
    }

    public function testGetCsv_DoesNotGiveTheFingerprintThatApprovesTheClosing(): void
    {
        $this->readyYear();
        $this->closer();

        $response = $this->get('/accounting_periods/year_end/csv?year=' . $this->yearId() . '&scope=company');

        $this->assertStringNotContainsString($this->fingerprint(), $response->response()->getBody());
    }

    public function testGetCsv_SaysWhatStopsTheClosingInWords(): void
    {
        $this->twoBranchYear();
        $this->closer();

        $rows = $this->csvRows($this->get('/accounting_periods/year_end/csv?year=' . $this->yearId() . '&scope=company'));

        $this->assertNotNull(array_values(array_filter($rows, static fn (array $row): bool => count($row) === 1 && $row[0] !== '' && ! str_contains($row[0], ' (') && $row[0] !== lang('Accounting_periods.year_end_title')))[0] ?? null);
    }

    public function testGetCsv_IsNotFoundWithoutThePermissionToCloseAndForABranchOutsideTheScopeAndWhileAccountingIsOff(): void
    {
        $this->readyYear();
        $this->closer(null, false);

        try {
            $this->get('/accounting_periods/year_end/csv?year=' . $this->yearId() . '&scope=company');

            $this->fail('A user without the permission is not given the preview');
        } catch (PageNotFoundException) {
            $this->assertTrue(true);
        }

        $this->closer([1]);

        try {
            $this->get('/accounting_periods/year_end/csv?year=' . $this->yearId() . '&scope=' . $this->second);

            $this->fail('A branch outside the scope is not there');
        } catch (PageNotFoundException) {
            $this->assertTrue(true);
        }

        try {
            $this->get('/accounting_periods/year_end/csv?year=' . $this->yearId() . '&scope=company');

            $this->fail('The company is for someone with every branch');
        } catch (PageNotFoundException) {
            $this->assertTrue(true);
        }

        $this->prepareAccounting(false);
        $this->closer();

        $this->expectException(PageNotFoundException::class);

        $this->get('/accounting_periods/year_end/csv?year=' . $this->yearId());
    }

    public function testGetIndex_LinksTheThreeFilesOfThePreviewWithTheYearAndTheScope(): void
    {
        $this->readyYear();
        $this->closer();

        $response = $this->get('/accounting_periods/year_end?year=' . $this->yearId() . '&scope=company');

        $response->assertSee('id="year_end_csv" href="' . site_url('accounting_periods/year_end/csv') . '?year=' . $this->yearId() . '&amp;scope=company"');
        $response->assertSee('id="year_end_csv_xlsx" href="' . site_url('accounting_periods/year_end/xlsx') . '?year=' . $this->yearId() . '&amp;scope=company"');
        $response->assertSee('id="year_end_csv_pdf" href="' . site_url('accounting_periods/year_end/pdf') . '?year=' . $this->yearId() . '&amp;scope=company"');
    }
}
