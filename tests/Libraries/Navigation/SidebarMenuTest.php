<?php

namespace Tests\Libraries\Navigation;

use App\Libraries\Navigation\SidebarMenu;
use App\Libraries\Organization\BranchGuard;
use App\Libraries\Roles\RoleGrants;
use App\Models\Employee;
use App\Models\Module;
use CodeIgniter\Language\Language;
use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\DatabaseTestTrait;
use Config\Database;
use Tests\Support\AccountingFixtureTrait;
use Tests\Support\NavigationShapesTrait;
use Tests\Support\RoleFixtureTrait;

/**
 * @internal
 */
final class SidebarMenuTest extends CIUnitTestCase
{
    use AccountingFixtureTrait;
    use DatabaseTestTrait;
    use NavigationShapesTrait;
    use RoleFixtureTrait;

    protected $migrate     = true;
    protected $migrateOnce = true;
    protected $refresh     = false;
    protected $namespace;

    public static function setUpBeforeClass(): void
    {
        Database::seeder('tests')->call('TestDatabaseBootstrapSeeder');
    }

    protected function setUp(): void
    {
        parent::setUp();

        helper('navigation');
        RoleGrants::flush();
    }

    protected function tearDown(): void
    {
        session()->remove('person_id');

        parent::tearDown();
    }

    private function itemIds(array $sections): array
    {
        $ids = [];

        foreach ($sections as $section) {
            foreach ($section['groups'] as $group) {
                array_push($ids, ...array_column($group['items'], 'id'));
            }
        }

        return $ids;
    }

    private function activeIds(array $sections): array
    {
        $ids = [];

        foreach ($sections as $section) {
            foreach ($section['groups'] as $group) {
                foreach ($group['items'] as $item) {
                    if ($item['active']) {
                        $ids[] = $item['id'];
                    }
                }
            }
        }

        return $ids;
    }

    private function sectionKeys(array $sections): array
    {
        return array_column($sections, 'key');
    }

    private function definedPages(): array
    {
        $pages = [];

        foreach (SidebarMenu::definition() as $section) {
            foreach ($section['groups'] as $group) {
                array_push($pages, ...$group['items']);
            }
        }

        return $pages;
    }

    /**
     * @return list<string>
     */
    private function shownFor(int $personId, string $path = 'home'): array
    {
        $modules = model(Module::class)->getAllowedModules($personId)->getResult();

        return $this->itemIds(navigationSections($modules, $path, $personId));
    }

    /**
     * What the pages themselves ask for: the gate of the module, the permission some pages add, the other modules some need.
     *
     * @return list<string>
     */
    private function openableBy(int $personId): array
    {
        $employee = model(Employee::class);
        $ids      = [];

        foreach ($this->definedPages() as $page) {
            if (! $employee->has_module_grant($page['module'], $personId)) {
                continue;
            }

            if (isset($page['permission']) && ! $employee->has_grant($page['permission'], $personId)) {
                continue;
            }

            if (($page['company'] ?? false) && ! (new BranchGuard())->isUnrestricted($personId)) {
                continue;
            }

            foreach ($page['also'] ?? [] as $other) {
                if (! $employee->has_module_grant($other, $personId)) {
                    continue 2;
                }
            }

            $ids[] = $page['id'];
        }

        return $ids;
    }

    public function testHoldsModule_RequiresAPermissionOfTheModuleWhenItHasSome(): void
    {
        $permissions = ['customers' => ['customers_manage']];

        $this->assertFalse(SidebarMenu::holdsModule('customers', ['customers'], $permissions));
        $this->assertTrue(SidebarMenu::holdsModule('customers', ['customers', 'customers_manage'], $permissions));
        $this->assertFalse(SidebarMenu::holdsModule('customers', ['customers_manage'], $permissions));
    }

    public function testHoldsModule_OpensAModuleWithoutPermissionsOfItsOwnByItsGrant(): void
    {
        $this->assertTrue(SidebarMenu::holdsModule('home', ['home'], []));
        $this->assertFalse(SidebarMenu::holdsModule('home', ['sales'], []));
    }

    public function testHoldsModule_IgnoresTheCaseOfTheIds(): void
    {
        $permissions = ['Customers' => ['Customers_Manage']];

        $this->assertTrue(SidebarMenu::holdsModule('Customers', ['customers', 'customers_manage'], $permissions));
    }

    public function testDefinition_GivesEveryPageAUniqueId(): void
    {
        $ids = array_column($this->definedPages(), 'id');

        $this->assertSame($ids, array_values(array_unique($ids)));
    }

    public function testDefinition_NamesTheSectionsOfTheMenuInOrder(): void
    {
        $this->assertSame(['home', 'parties', 'purchases', 'sales', 'vouchers', 'expenses', 'accounts', 'ledger', 'inventory', 'reports', 'settings'], array_keys(SidebarMenu::definition()));
    }

    public function testDefinition_NamesEveryPageAndSectionInArabic(): void
    {
        $language = new Language('ar-EG');
        $keys     = array_map(static fn (string $section): string => "Module.nav_section_{$section}", array_keys(SidebarMenu::definition()));

        foreach (SidebarMenu::definition() as $section) {
            foreach ($section['groups'] as $group) {
                $group['label'] === null || $keys[] = $group['label'];

                foreach ($group['items'] as $item) {
                    $keys[] = $item['label'];
                }
            }
        }

        foreach (array_unique($keys) as $key) {
            $line = $language->getLine($key);

            $this->assertNotSame($key, $line, $key);
            $this->assertMatchesRegularExpression('/\p{Arabic}/u', $line, $key);
        }
    }

    public function testDefinition_PointsEveryPageAtARouteOfAnExistingController(): void
    {
        foreach ($this->definedPages() as $page) {
            [$controller, $method] = $this->handlerOf($page['path']);

            $this->assertTrue(class_exists($controller), "{$page['path']}: {$controller}");
            $this->assertTrue(method_exists($controller, $method), "{$page['path']}: {$controller}::{$method}");
        }
    }

    public function testDefinition_ReachesEveryModuleOfTheApplication(): void
    {
        $unlisted = array_values(array_diff($this->everyModuleId(), SidebarMenu::modules(), ['office']));

        $this->assertSame([], $unlisted);
    }

    public function testCompanyModules_ListsTheModulesWhoseFirstPageNeedsTheWholeCompany(): void
    {
        $this->assertSame(['accounting_receivables', 'accounting_payables'], SidebarMenu::companyModules());
    }

    public function testTilesBySection_PlacesEveryModuleOfTheMenuOnce(): void
    {
        $placed = array_merge(...array_values(SidebarMenu::tilesBySection()));

        $this->assertEqualsCanonicalizing(SidebarMenu::modules(), $placed);
        $this->assertSame($placed, array_values(array_unique($placed)));
        $this->assertContains('accounting_reports', SidebarMenu::tilesBySection()['reports']);
        $this->assertNotContains('accounting_reports', SidebarMenu::tilesBySection()['ledger']);
    }

    public function testSections_OmitsASectionWhenThePersonMayOpenNothingInIt(): void
    {
        $this->assertSame([], SidebarMenu::sections([], [], [], 'home'));
        $this->assertSame(['sales'], $this->sectionKeys(SidebarMenu::sections(['sales'], ['sales'], [], 'home')));
    }

    public function testSections_OffersAModuleWithPermissionsOfItsOwnOnlyWithOneOfThem(): void
    {
        $permissions = ['customers' => ['customers_manage']];

        $this->assertSame([], SidebarMenu::sections(['customers'], ['customers'], $permissions, 'home'));
        $this->assertSame(['customers'], $this->itemIds(SidebarMenu::sections(['customers'], ['customers', 'customers_manage'], $permissions, 'home')));
    }

    public function testSections_RequiresTheSalesReportsPermissionForTheSalesLogAndTheDocuments(): void
    {
        $without = $this->itemIds(SidebarMenu::sections(['sales'], ['sales'], [], 'home'));
        $with    = $this->itemIds(SidebarMenu::sections(['sales'], ['sales', 'reports_sales'], [], 'home'));

        $this->assertSame(['sales'], $without);
        $this->assertSame(['sales_log', 'sales_documents', 'sales'], $with);
    }

    public function testSections_OffersIntegrationsOnlyToWhoAlsoHoldsTheSettingsModule(): void
    {
        $this->assertSame([], SidebarMenu::sections(['integrations'], ['integrations'], [], 'home'));
        $this->assertSame([], SidebarMenu::sections(['integrations', 'config'], ['integrations'], [], 'home'));
        $this->assertSame(['config', 'integrations'], $this->itemIds(SidebarMenu::sections(['integrations', 'config'], ['integrations', 'config'], [], 'home')));
    }

    public function testSections_OffersTheBalancesAndAgedDebtsOnlyToWhoWorksForTheWholeCompany(): void
    {
        $modules = ['accounting_receivables', 'accounting_payables'];
        $held    = ['accounting_receivables', 'accounting_payables'];
        $kept    = ['payables_debit_notes', 'receivables_payments', 'payables_payments'];
        $whole   = ['accounting_receivables', 'receivables_payments', 'receivables_credit_notes', 'receivables_aged', 'accounting_payables', 'payables_payments', 'payables_debit_notes', 'payables_aged'];

        $this->assertSame($kept, $this->itemIds(SidebarMenu::sections($modules, $held, [], 'home')));
        $this->assertEqualsCanonicalizing($whole, $this->itemIds(SidebarMenu::sections($modules, [...$held, BranchGuard::ALL_BRANCHES], [], 'home')));
    }

    public function testSections_StartsPurchasesWithTheInvoicesAndKeepsTheRegisterApart(): void
    {
        $sections = SidebarMenu::sections(['reports', 'receivings', 'purchase_orders'], ['reports', 'reports_receivings', 'receivings', 'purchase_orders'], ['reports' => ['reports_receivings']], 'home');
        $groups   = $sections[0]['groups'];

        $this->assertSame('purchases', $sections[0]['key']);
        $this->assertSame([lang('Module.nav_group_purchase_invoices'), lang('Module.nav_group_purchase_new'), lang('Module.nav_group_purchase_cycle')], array_column($groups, 'label'));
        $this->assertSame(['purchase_invoices'], array_column($groups[0]['items'], 'id'));
        $this->assertSame(['receivings'], array_column($groups[1]['items'], 'id'));
        $this->assertSame(base_url('reports/detailed_receivings'), $groups[0]['items'][0]['url']);
    }

    public function testSections_OffersThePurchaseInvoicesOnlyWithThePurchaseReportsPermission(): void
    {
        $permissions = ['reports' => ['reports_receivings', 'reports_sales']];

        $this->assertNotContains('purchase_invoices', $this->itemIds(SidebarMenu::sections(['reports'], ['reports', 'reports_sales'], $permissions, 'home')));
        $this->assertContains('purchase_invoices', $this->itemIds(SidebarMenu::sections(['reports'], ['reports', 'reports_receivings'], $permissions, 'home')));
    }

    public function testSections_SeparatesTheSalesInvoicesFromThePointOfSale(): void
    {
        $sections = SidebarMenu::sections(['sales', 'customer_orders'], ['sales', 'reports_sales', 'customer_orders'], [], 'home');
        $groups   = $sections[0]['groups'];

        $this->assertSame('sales', $sections[0]['key']);
        $this->assertSame([lang('Module.nav_group_sales_invoices'), lang('Module.nav_group_pos'), lang('Module.nav_group_sales_orders')], array_column($groups, 'label'));
        $this->assertSame(['sales_log', 'sales_documents'], array_column($groups[0]['items'], 'id'));
        $this->assertSame(['sales'], array_column($groups[1]['items'], 'id'));
        $this->assertSame(base_url('sales/manage'), $groups[0]['items'][0]['url']);
        $this->assertSame(base_url('sales'), $groups[1]['items'][0]['url']);
    }

    public function testSections_LinksThePostingOfTheJournalsToTheJournalsOpenOnTheirDrafts(): void
    {
        $sections = SidebarMenu::sections(['accounting_journals'], ['accounting_journals', 'acc_journals_post'], [], 'home');
        $urls     = array_merge(...array_map(static fn (array $group): array => array_column($group['items'], 'url', 'id'), $sections[0]['groups']));

        $this->assertSame(base_url('accounting_journals'), $urls['accounting_journals']);
        $this->assertSame(base_url('accounting_journals') . '?status[]=draft', $urls['post_journals']);
    }

    public function testSections_OffersThePostingOfTheJournalsOnlyWithThePostPermission(): void
    {
        $this->assertNotContains('post_journals', $this->itemIds(SidebarMenu::sections(['accounting_journals'], ['accounting_journals', 'acc_journals_draft'], [], 'home')));
        $this->assertContains('post_journals', $this->itemIds(SidebarMenu::sections(['accounting_journals'], ['accounting_journals', 'acc_journals_post'], [], 'home')));
    }

    public function testSections_MarksThePostingViewOfTheJournalsOnlyWhenTheQueryAsksForIt(): void
    {
        $held = ['accounting_journals', 'acc_journals_post'];

        $this->assertSame(['post_journals'], $this->activeIds(SidebarMenu::sections(['accounting_journals'], $held, [], 'accounting_journals', 'status%5B%5D=draft')));
        $this->assertSame(['post_journals'], $this->activeIds(SidebarMenu::sections(['accounting_journals'], $held, [], 'accounting_journals', 'status[]=posted&status[]=draft')));
        $this->assertSame(['accounting_journals'], $this->activeIds(SidebarMenu::sections(['accounting_journals'], $held, [], 'accounting_journals', '')));
        $this->assertSame(['accounting_journals'], $this->activeIds(SidebarMenu::sections(['accounting_journals'], $held, [], 'accounting_journals', 'status[]=posted')));
        $this->assertSame(['accounting_journals'], $this->activeIds(SidebarMenu::sections(['accounting_journals'], $held, [], 'accounting_journals/new', '')));
    }

    public function testSections_OffersTheCollectedInvoicesWithTheSalesReportsPermissionAndMarksThemByTheirQuery(): void
    {
        $modules = ['accounting_reports'];

        $this->assertNotContains('collected_invoices', $this->itemIds(SidebarMenu::sections($modules, ['accounting_reports'], [], 'home')));

        $sections = SidebarMenu::sections($modules, ['accounting_reports', 'reports_sales'], [], 'accounting_reports/net_position', 'status[]=paid');

        $this->assertContains('collected_invoices', $this->itemIds($sections));
        $this->assertSame(['collected_invoices'], $this->activeIds($sections));
    }

    public function testSections_NamesTheEveryPostingAndClosingPageApartInTheirOwnGroup(): void
    {
        $held     = ['accounting_journals', 'acc_journals_post', 'accounting_periods', 'acc_backfill_manage', 'acc_year_end_close', 'acc_opening_manage'];
        $sections = SidebarMenu::sections(['accounting_journals', 'accounting_periods'], $held, [], 'home');
        $ledger   = array_values(array_filter($sections, static fn (array $section): bool => $section['key'] === 'ledger'))[0];
        $posting  = array_values(array_filter($ledger['groups'], static fn (array $group): bool => $group['label'] === lang('Module.nav_group_posting')))[0];

        $this->assertSame(['post_journals', 'backfill', 'accounting_periods', 'closings', 'year_end', 'opening'], array_column($posting['items'], 'id'));
        $this->assertCount(6, array_unique(array_column($posting['items'], 'label')));
    }

    public function testDefinition_NamesNoPostingOrClosingPageByAGenericName(): void
    {
        $language = new Language('ar-EG');
        $labels   = [];

        foreach ($this->definedPages() as $page) {
            if (in_array($page['id'], ['post_journals', 'backfill', 'closings', 'year_end', 'opening'], true)) {
                $labels[$page['id']] = $language->getLine($page['label']);
            }
        }

        $this->assertCount(5, $labels);
        $this->assertNotContains('الترحيل', $labels);
        $this->assertStringContainsString('القيود', $labels['post_journals']);
        $this->assertStringContainsString('بأثر رجعي', $labels['backfill']);
        $this->assertStringContainsString('الفترات', $labels['closings']);
        $this->assertStringContainsString('السنة', $labels['year_end']);
    }

    public function testOpens_AppliesTheModuleGateThePermissionTheCompanyScopeAndTheOtherModules(): void
    {
        $page = ['module' => 'sales', 'permission' => 'reports_sales', 'company' => true, 'also' => ['customers']];
        $held = ['sales', 'reports_sales', BranchGuard::ALL_BRANCHES, 'customers'];

        $this->assertTrue(SidebarMenu::opens($page, ['sales', 'customers'], $held, []));
        $this->assertFalse(SidebarMenu::opens($page, ['sales'], $held, []));
        $this->assertFalse(SidebarMenu::opens($page, ['sales', 'customers'], array_diff($held, ['reports_sales']), []));
        $this->assertFalse(SidebarMenu::opens($page, ['sales', 'customers'], array_diff($held, [BranchGuard::ALL_BRANCHES]), []));
        $this->assertFalse(SidebarMenu::opens($page, ['sales', 'customers'], array_diff($held, ['customers']), []));
    }

    public function testSections_AppendsAModuleTheMenuDoesNotNameToTheLastGroupOfSettings(): void
    {
        $sections = SidebarMenu::sections(['config', 'custom_module'], ['config', 'custom_module'], [], 'home');
        $settings = end($sections);

        $this->assertSame('settings', $settings['key']);
        $this->assertSame(['config'], array_column($settings['groups'][0]['items'], 'id'));
        $this->assertSame(['custom_module'], array_column(end($settings['groups'])['items'], 'id'));
    }

    public function testSections_MarksTheMostSpecificPageAsTheActiveOne(): void
    {
        $modules = ['accounting_receivables', 'sales'];
        $held    = ['accounting_receivables', 'sales', 'reports_sales', BranchGuard::ALL_BRANCHES];

        $this->assertSame(['receivables_aged'], $this->activeIds(SidebarMenu::sections($modules, $held, [], 'accounting_receivables/aged_ledger')));
        $this->assertSame(['accounting_receivables'], $this->activeIds(SidebarMenu::sections($modules, $held, [], 'accounting_receivables/view/12')));
        $this->assertSame(['sales_log'], $this->activeIds(SidebarMenu::sections($modules, $held, [], 'sales/manage')));
        $this->assertSame(['sales'], $this->activeIds(SidebarMenu::sections($modules, $held, [], 'sales/receipt/5')));
    }

    public function testSections_MarksTheStartPageForTheRootPath(): void
    {
        $this->assertSame(['home'], $this->activeIds(SidebarMenu::sections(['home', 'sales'], ['home', 'sales'], [], '')));
        $this->assertSame(['home'], $this->activeIds(SidebarMenu::sections(['home', 'sales'], ['home', 'sales'], [], 'home')));
    }

    public function testSections_OpensOnlyTheSectionOfTheActivePage(): void
    {
        $sections = SidebarMenu::sections(['home', 'sales', 'items'], ['home', 'sales', 'items'], [], 'items/manage');
        $open     = array_column(array_filter($sections, static fn (array $section): bool => $section['active']), 'key');

        $this->assertSame(['inventory'], array_values($open));
    }

    public function testSections_OpensTheReportsSectionForAReportPageTheMenuDoesNotName(): void
    {
        $sections = SidebarMenu::sections(['reports'], ['reports'], [], 'accounting_statements/something_else');

        $this->assertSame([], $this->activeIds($sections));
        $this->assertTrue($sections[0]['active']);
    }

    public function testSections_IgnoresTheQueryStringAndTheCaseOfThePath(): void
    {
        $this->assertSame(['sales'], $this->activeIds(SidebarMenu::sections(['sales'], ['sales'], [], '/Sales?x=1')));
    }

    public function testSections_LinksEveryPageToItsOwnAddress(): void
    {
        $sections = SidebarMenu::sections(['accounting_treasuries'], ['accounting_treasuries'], [], 'home');
        $urls     = array_column($sections[0]['groups'][0]['items'], 'url', 'id');

        $this->assertSame(base_url('accounting_treasuries'), $urls['accounting_treasuries']);
        $this->assertSame(base_url('accounting_treasuries/transfers'), $urls['treasury_transfers']);
        $this->assertSame(base_url('accounting_treasuries/bank'), $urls['bank_reconciliation']);
    }

    public function testNavigationSections_ShowsExactlyThePagesTheGatesOfTheModulesOpen(): void
    {
        $this->withAccounting(true, function (): void {
            foreach ($this->employeesOfEveryShape() as $shape => $personId) {
                $this->assertSame($this->openableBy($personId), $this->shownFor($personId), $shape);
            }
        });
    }

    public function testNavigationSections_NeverShowsASectionOrAGroupWithoutPages(): void
    {
        $this->withAccounting(true, function (): void {
            foreach ($this->employeesOfEveryShape() as $shape => $personId) {
                $modules = model(Module::class)->getAllowedModules($personId)->getResult();

                foreach (navigationSections($modules, 'home', $personId) as $section) {
                    $this->assertNotSame([], $section['groups'], "{$shape}: {$section['key']}");

                    foreach ($section['groups'] as $group) {
                        $this->assertNotSame([], $group['items'], "{$shape}: {$section['key']}");
                    }
                }
            }
        });
    }

    public function testNavigationSections_ShowsNothingToAnEmployeeWithoutGrants(): void
    {
        $this->assertSame([], $this->shownFor($this->employeeHolding([])));
    }

    public function testNavigationSections_ShowsEveryPageToAnEmployeeWithEveryPermission(): void
    {
        $this->withAccounting(true, function (): void {
            $personId = $this->employeeHolding($this->everyPermissionId());

            $this->assertEqualsCanonicalizing(array_column($this->definedPages(), 'id'), $this->shownFor($personId));
        });
    }

    public function testNavigationSections_HidesTheBooksWhileAccountingIsOff(): void
    {
        $this->withAccounting(false, function (): void {
            $personId = $this->employeeHolding($this->everyPermissionId());
            $shown    = $this->shownFor($personId);
            $books    = ['accounting_chart', 'accounting_journals', 'accounting_receivables', 'accounting_payables', 'accounting_treasuries', 'accounting_cheques', 'income_statement', 'trial_balance'];

            $this->assertSame([], array_values(array_intersect($books, $shown)));
            $this->assertContains('accounting_settings', $shown);
            $this->assertContains('accounting_routing', $shown);
            $this->assertContains('sales', $shown);
        });
    }

    public function testNavigationSections_FollowsTheRolesOfTheEmployee(): void
    {
        $this->withAccounting(true, function (): void {
            $personId = $this->employeeWithRoles([$this->createRole(['home', 'notifications'])]);

            $this->assertSame(['home', 'notifications'], $this->shownFor($personId));
        });
    }

    public function testNavigationSections_AsksARoleForAPermissionOfTheModuleToo(): void
    {
        $this->withAccounting(true, function (): void {
            $own = $this->ownPermissions();

            $this->assertArrayHasKey('sales', $own);
            $this->assertNotContains('sales', $this->shownFor($this->employeeWithRoles([$this->createRole(['sales'])])));
            $this->assertContains('sales', $this->shownFor($this->employeeWithRoles([$this->createRole(['sales', $own['sales'][0]])])));
        });
    }
}
