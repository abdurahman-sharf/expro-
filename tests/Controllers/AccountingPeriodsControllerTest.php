<?php

namespace Tests\Controllers;

use App\Libraries\Accounting\AccountingGuard;
use App\Libraries\Accounting\FiscalYearService;
use App\Libraries\Accounting\JournalService;
use App\Libraries\Navigation\SidebarMenu;
use CodeIgniter\Exceptions\PageNotFoundException;
use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\DatabaseTestTrait;
use Tests\Support\AccountingControllerFixtureTrait;
use Tests\Support\NoAccessAssertionTrait;

class AccountingPeriodsControllerTest extends CIUnitTestCase
{
    use DatabaseTestTrait;
    use AccountingControllerFixtureTrait;
    use NoAccessAssertionTrait;

    protected $migrate     = true;
    protected $migrateOnce = true;
    protected $refresh     = false;
    protected $namespace   = null;

    protected function setUp(): void
    {
        parent::setUp();

        $this->prepareAccounting();
        $this->setConfigValue('accounting_auto_fiscal_year', '1');
        $this->accountingDb()->table('accounting_fiscal_years')->where('company_id', 1)->delete();
    }

    protected function tearDown(): void
    {
        $this->releaseOpenTransactions();

        parent::tearDown();
    }

    /**
     * @param list<int>|null $branchIds
     */
    private function manager(?array $branchIds = null): int
    {
        $personId = $this->employeeWithGrants(['accounting_periods', AccountingGuard::PERIODS_MANAGE], $branchIds);
        $this->actAs($personId);

        return $personId;
    }

    private function year(string $name, string $start, string $end): int
    {
        return (new FiscalYearService())->create(['name' => $name, 'start_date' => $start, 'end_date' => $end]);
    }

    public function testGetIndex_ShowsTheScreenToAUserWithTheModuleGrant(): void
    {
        $this->actAs($this->employeeWithGrants(['accounting_periods']));

        $response = $this->get('/accounting_periods');

        $response->assertStatus(200);
        $response->assertSee('id="table"');
        $response->assertDontSee('data-href="accounting_periods/view"');
    }

    public function testGetIndex_ShowsOnlyTheTabsOfThePagesTheEmployeeMayOpen(): void
    {
        $this->actAs($this->employeeWithGrants(['accounting_periods']));

        $html = $this->get('/accounting_periods')->getBody();

        $this->assertMatchesRegularExpression('~href="[^"]*/accounting_periods/closings"~', $html);
        $this->assertMatchesRegularExpression('~href="[^"]*/accounting_periods/opening_import"~', $html);

        foreach (['backfill', 'year_end', 'opening'] as $tab) {
            $this->assertDoesNotMatchRegularExpression('~href="[^"]*/accounting_periods/' . $tab . '"~', $html, $tab);
        }
    }

    public function testGetIndex_ShowsEveryTabToWhoHoldsThePermissionsOfTheGatedPages(): void
    {
        $this->actAs($this->employeeWithGrants(['accounting_periods', AccountingGuard::BACKFILL_MANAGE, AccountingGuard::YEAR_END_CLOSE, AccountingGuard::OPENING_MANAGE]));

        $html = $this->get('/accounting_periods')->getBody();

        foreach (['closings', 'opening', 'opening_import', 'year_end', 'backfill'] as $tab) {
            $this->assertMatchesRegularExpression('~href="[^"]*/accounting_periods/' . $tab . '"~', $html, $tab);
        }
    }

    public function testTheGatedPages_AnswerExactlyWhomTheMenuOffersThemTo(): void
    {
        $gated = [];

        foreach (SidebarMenu::definition() as $section) {
            foreach ($section['groups'] as $group) {
                foreach ($group['items'] as $page) {
                    if ($page['module'] === 'accounting_periods' && isset($page['permission'])) {
                        $gated[$page['path']] = $page['permission'];
                    }
                }
            }
        }

        $this->assertSame(['accounting_periods/backfill', 'accounting_periods/year_end', 'accounting_periods/opening'], array_keys($gated));

        foreach ($gated as $path => $permission) {
            $this->actAs($this->employeeWithGrants(['accounting_periods']));
            $this->assertNoAccess($this->get('/' . $path), 'accounting_periods', $permission);

            $this->actAs($this->employeeWithGrants(['accounting_periods', $permission]));
            $this->get('/' . $path)->assertStatus(200);
        }
    }

    public function testGetIndex_IsNotFoundWhileAccountingIsDisabled(): void
    {
        $this->prepareAccounting(false);
        $this->actAs($this->employeeWithGrants(['accounting_periods']));

        $this->expectException(PageNotFoundException::class);

        $this->get('/accounting_periods');
    }

    public function testGetIndex_RedirectsAUserWithoutTheModuleGrant(): void
    {
        $this->actAs($this->employeeWithGrants(['accounting_chart']));

        $this->get('/accounting_periods')->assertRedirect();
    }

    public function testGetIndex_OffersTheAddButtonOnlyToSomeoneWhoManagesYearsWithEveryBranch(): void
    {
        $this->manager();

        $this->get('/accounting_periods')->assertSee('data-href="accounting_periods/view"');

        $this->manager([1]);

        $this->get('/accounting_periods')->assertDontSee('data-href="accounting_periods/view"');
    }

    public function testGetIndex_WarnsBeforeTheLastYearEndsWhenAutomaticCreationIsOff(): void
    {
        $this->setConfigValue('accounting_auto_fiscal_year', '0');
        $this->year('Soon', date('Y-m-d', strtotime('-300 days')), date('Y-m-d', strtotime('+20 days')));
        $this->manager();

        $this->get('/accounting_periods')->assertSee('acc-year-notice');

        $this->setConfigValue('accounting_auto_fiscal_year', '1');

        $this->get('/accounting_periods')->assertDontSee('acc-year-notice');
    }

    public function testGetSearch_ListsTheYearsWithTheirStateAndPostedJournals(): void
    {
        $thisYear = (int) date('Y');
        $this->year('past', ($thisYear - 1) . '-01-01', ($thisYear - 1) . '-12-31');
        $this->year('current', $thisYear . '-01-01', $thisYear . '-12-31');
        (new JournalService())->postNew(['date' => $thisYear . '-02-02'] + $this->journalInput());
        $this->manager();

        $rows = $this->jsonOf($this->get('/accounting_periods/search'))['rows'];

        $this->assertSame(['past', 'current'], array_column($rows, 'name'));
        $this->assertSame(1, $rows[1]['journal_count']);
        $this->assertStringContainsString(lang('Accounting_periods.state_current'), $rows[1]['status']);
    }

    public function testGetSearch_OffersDeleteOnlyForAnEdgeYearWithoutJournals(): void
    {
        $this->year('2025', '2025-01-01', '2025-12-31');
        $this->year('2026', '2026-01-01', '2026-12-31');
        $this->year('2027', '2027-01-01', '2027-12-31');
        $this->manager();

        $rows = $this->jsonOf($this->get('/accounting_periods/search'))['rows'];

        $this->assertStringContainsString('data-acc-action="delete"', $rows[0]['edit']);
        $this->assertStringNotContainsString('data-acc-action="delete"', $rows[1]['edit']);
        $this->assertStringContainsString('data-acc-action="delete"', $rows[2]['edit']);
    }

    public function testGetSearch_EscapesTheName(): void
    {
        $this->year('<script>alert(1)</script>', '2026-01-01', '2026-12-31');
        $this->manager();

        $row = $this->jsonOf($this->get('/accounting_periods/search'))['rows'][0];

        $this->assertStringNotContainsString('<script>', $row['status'] . $row['edit']);
    }

    public function testGetView_ShowsTheSuggestedValuesForANewYear(): void
    {
        $this->year('2026', '2026-01-01', '2026-12-31');
        $this->manager();

        $response = $this->get('/accounting_periods/view');

        $response->assertSee('value="2027"');
        $response->assertSee('value="2027-01-01"');
        $response->assertSee('value="2027-12-31"');
    }

    public function testGetView_LocksTheDatesOfAYearInTheMiddle(): void
    {
        $this->year('2025', '2025-01-01', '2025-12-31');
        $middle = $this->year('2026', '2026-01-01', '2026-12-31');
        $this->year('2027', '2027-01-01', '2027-12-31');
        $this->manager();

        $response = $this->get('/accounting_periods/view/' . $middle);

        $body = $response->getBody();

        $this->assertMatchesRegularExpression('/id="start_date"[^>]*\sreadonly/', $body);
        $this->assertMatchesRegularExpression('/id="end_date"[^>]*\sreadonly/', $body);
    }

    public function testGetView_IsNotFoundWithoutThePermissionOrForAMissingYear(): void
    {
        $this->actAs($this->employeeWithGrants(['accounting_periods']));

        try {
            $this->get('/accounting_periods/view');
            $this->fail('Expected the form to be hidden.');
        } catch (PageNotFoundException) {
            $this->addToAssertionCount(1);
        }

        $this->manager();

        $this->expectException(PageNotFoundException::class);

        $this->get('/accounting_periods/view/9999');
    }

    public function testPostSave_AddsAYear(): void
    {
        $this->manager();

        $result = $this->jsonOf($this->post('/accounting_periods/save', ['name' => '2026', 'start_date' => '2026-01-01', 'end_date' => '2026-12-31', 'description' => 'First']));

        $this->assertTrue($result['success']);
        $this->assertSame('First', (new FiscalYearService())->find($result['id'])->description);
    }

    public function testPostSave_ReportsAGapAnOverlapAndATakenName(): void
    {
        $this->year('2026', '2026-01-01', '2026-12-31');
        $this->manager();

        $gap     = $this->jsonOf($this->post('/accounting_periods/save', ['name' => '2028', 'start_date' => '2028-01-01', 'end_date' => '2028-12-31']));
        $overlap = $this->jsonOf($this->post('/accounting_periods/save', ['name' => 'X', 'start_date' => '2026-06-01', 'end_date' => '2027-05-31']));
        $taken   = $this->jsonOf($this->post('/accounting_periods/save', ['name' => '2026', 'start_date' => '2027-01-01', 'end_date' => '2027-12-31']));

        $this->assertSame(lang('Accounting.error_fiscal_year_gap', ['2027-01-01']), $gap['message']);
        $this->assertStringContainsString('2026', $overlap['message']);
        $this->assertSame(lang('Accounting.error_fiscal_year_name_taken', ['2026']), $taken['message']);
    }

    public function testPostSave_UpdatesAnExistingYear(): void
    {
        $id = $this->year('2026', '2026-01-01', '2026-12-31');
        $this->manager();

        $result = $this->jsonOf($this->post('/accounting_periods/save/' . $id, ['name' => 'FY26', 'start_date' => '2026-01-01', 'end_date' => '2026-12-31']));

        $this->assertTrue($result['success']);
        $this->assertSame('FY26', (new FiscalYearService())->find($id)->name);
    }

    public function testPostSave_RefusesSomeoneWithoutThePermissionOrLimitedToBranches(): void
    {
        $this->actAs($this->employeeWithGrants(['accounting_periods']));

        $this->assertFalse($this->jsonOf($this->post('/accounting_periods/save', ['name' => '2026', 'start_date' => '2026-01-01', 'end_date' => '2026-12-31']))['success']);

        $this->manager([1]);

        $this->assertFalse($this->jsonOf($this->post('/accounting_periods/save', ['name' => '2026', 'start_date' => '2026-01-01', 'end_date' => '2026-12-31']))['success']);
        $this->assertSame(0, $this->countRows('accounting_fiscal_years'));
    }

    public function testPostDeleteYear_DeletesAnEdgeYearAndRefusesOneWithJournals(): void
    {
        $this->year('2025', '2025-01-01', '2025-12-31');
        $last = $this->year('2026', '2026-01-01', '2026-12-31');
        $this->manager();

        $this->assertTrue($this->jsonOf($this->post('/accounting_periods/delete/' . $last))['success']);
        $this->assertSame(1, $this->countRows('accounting_fiscal_years'));

        (new JournalService())->postNew(['date' => '2025-05-05'] + $this->journalInput());
        $first = (int) $this->accountingDb()->table('accounting_fiscal_years')->get()->getRow()->fiscal_year_id;

        $this->assertFalse($this->jsonOf($this->post('/accounting_periods/delete/' . $first))['success']);
    }
}
