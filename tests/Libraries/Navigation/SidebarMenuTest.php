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
        $this->assertSame(['home', 'sales', 'customers', 'inventory', 'purchases', 'finance', 'ledger', 'employees', 'reports', 'templates', 'settings'], array_keys(SidebarMenu::definition()));
    }

    public function testDefinition_NamesTheElevenSectionsInArabicAsTheOwnerAsked(): void
    {
        $language = new Language('ar-EG');
        $names    = array_map(static fn (string $key): string => $language->getLine("Module.nav_section_{$key}"), array_keys(SidebarMenu::definition()));

        $this->assertSame(['لوحة التحكم', 'المبيعات', 'العملاء', 'المخزون', 'المشتريات', 'المالية', 'الحسابات العامة', 'الموظفون', 'التقارير', 'القوالب', 'الإعدادات'], $names);
    }

    public function testDefinition_KeepsTheRuleOfEveryPageTheMenuHadBefore(): void
    {
        $before = require __DIR__ . '/../../Support/SidebarMenuBeforeNav4.php';
        $now    = array_column($this->definedPages(), null, 'id');

        $this->assertCount(76, $before);

        foreach ($before as $id => $page) {
            $this->assertArrayHasKey($id, $now, $id);

            foreach (['module', 'path', 'company', 'accounting', 'also', 'query'] as $key) {
                $this->assertSame($page[$key] ?? null, $now[$id][$key] ?? null, "{$id}.{$key}");
            }

            $permission = $id === 'bank_reconciliation' ? 'acc_bank_reconcile' : ($page['permission'] ?? null);

            $this->assertSame($permission, $now[$id]['permission'] ?? null, "{$id}.permission");
        }
    }

    public function testDefinition_AddsOnlyTheElevenPagesThatExistedButWereNotListed(): void
    {
        $before = array_keys(require __DIR__ . '/../../Support/SidebarMenuBeforeNav4.php');
        $added  = array_values(array_diff(array_column($this->definedPages(), 'id'), $before));

        $this->assertEqualsCanonicalizing(['customer_classifications', 'item_brands', 'warehouses', 'journal_new', 'activity_log', 'receipt_templates', 'invoice_templates', 'barcode_labels', 'notification_rules', 'payment_methods', 'api_keys'], $added);
    }

    public function testDefinition_OpensEveryTabItLinksToOnThePageOfTheSettings(): void
    {
        $source = file_get_contents(APPPATH . 'Views/configs/manage.php');

        preg_match_all("/^\\s*'([a-z]+)'\\s*=>\\s*'configs\\//m", $source, $panes);

        $tabs = array_values(array_filter($this->definedPages(), static fn (array $page): bool => isset($page['fragment'])));

        $this->assertCount(4, $tabs);

        foreach ($tabs as $tab) {
            $this->assertSame('config', $tab['path'], $tab['id']);
            $this->assertSame('config', $tab['module'], $tab['id']);
            $this->assertMatchesRegularExpression('/^[a-z]+_tab$/', $tab['fragment'], $tab['id']);
            $this->assertContains(substr($tab['fragment'], 0, -4), $panes[1], $tab['id']);
        }
    }

    public function testDefinition_GivesTheOwnerTheElevenSectionsWithTheirPages(): void
    {
        $modules  = SidebarMenu::modules();
        $held     = [...$modules, ...array_filter(array_column($this->definedPages(), 'permission')), BranchGuard::ALL_BRANCHES];
        $sections = SidebarMenu::sections($modules, $held, [], 'home');
        $counts   = array_map(fn (array $section): int => count($this->itemIds([$section])), $sections);

        $this->assertSame(['home', 'sales', 'customers', 'inventory', 'purchases', 'finance', 'ledger', 'employees', 'reports', 'templates', 'settings'], $this->sectionKeys($sections));
        $this->assertSame([2, 10, 5, 10, 11, 11, 23, 2, 2, 4, 7], $counts);
        $this->assertSame(87, array_sum($counts));
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

    public function testPage_FindsAPageOfTheMenuByItsIdWithTheRuleThatDecidesWhoMayOpenIt(): void
    {
        $page = SidebarMenu::page('year_end');

        $this->assertSame('accounting_periods/year_end', $page['path']);
        $this->assertSame('acc_year_end_close', $page['permission']);
        $this->assertNull(SidebarMenu::page('a_page_the_menu_does_not_name'));
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

    public function testTilesBySection_KeepsTheTilesOfTheSettingsAndTheBalancesWhereTheirOwnSectionHasThem(): void
    {
        $tiles = SidebarMenu::tilesBySection();

        $this->assertSame(['config', 'branches', 'taxes', 'integrations', 'custom_fields'], $tiles['settings']);
        $this->assertSame(['customers', 'accounting_receivables', 'messages'], $tiles['customers']);
        $this->assertSame(['reports', 'accounting_reports'], $tiles['reports']);
        $this->assertSame([], $tiles['templates']);
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
        $this->assertSame(['customers', 'customer_classifications'], $this->itemIds(SidebarMenu::sections(['customers'], ['customers', 'customers_manage'], $permissions, 'home')));
    }

    public function testSections_RequiresTheSalesReportsPermissionForTheSalesLogAndTheDocuments(): void
    {
        $without = $this->itemIds(SidebarMenu::sections(['sales'], ['sales'], [], 'home'));
        $with    = $this->itemIds(SidebarMenu::sections(['sales'], ['sales', 'reports_sales'], [], 'home'));

        $this->assertSame(['sales'], $without);
        $this->assertSame(['sales_log', 'sales', 'sales_documents'], $with);
    }

    public function testSections_OffersIntegrationsOnlyToWhoAlsoHoldsTheSettingsModule(): void
    {
        $this->assertSame([], SidebarMenu::sections(['integrations'], ['integrations'], [], 'home'));
        $this->assertSame([], SidebarMenu::sections(['integrations', 'config'], ['integrations'], [], 'home'));
        $this->assertContains('integrations', $this->itemIds(SidebarMenu::sections(['integrations', 'config'], ['integrations', 'config'], [], 'home')));
        $this->assertNotContains('integrations', $this->itemIds(SidebarMenu::sections(['config'], ['config'], [], 'home')));
    }

    public function testSections_OffersTheBalancesAndAgedDebtsOnlyToWhoWorksForTheWholeCompany(): void
    {
        $modules = ['accounting_receivables', 'accounting_payables'];
        $held    = ['accounting_receivables', 'accounting_payables'];
        $kept    = ['receivables_payments', 'payables_debit_notes', 'payables_payments'];
        $whole   = ['accounting_receivables', 'receivables_payments', 'receivables_credit_notes', 'receivables_aged', 'accounting_payables', 'payables_payments', 'payables_debit_notes', 'payables_aged'];

        $this->assertSame($kept, $this->itemIds(SidebarMenu::sections($modules, $held, [], 'home')));
        $this->assertEqualsCanonicalizing($whole, $this->itemIds(SidebarMenu::sections($modules, [...$held, BranchGuard::ALL_BRANCHES], [], 'home')));
    }

    public function testSections_StartsPurchasesWithTheInvoicesAndTheNewPurchaseTogether(): void
    {
        $sections = SidebarMenu::sections(['reports', 'receivings', 'purchase_orders'], ['reports', 'reports_receivings', 'receivings', 'purchase_orders'], ['reports' => ['reports_receivings']], 'home');
        $groups   = $sections[0]['groups'];

        $this->assertSame('purchases', $sections[0]['key']);
        $this->assertSame([lang('Module.nav_group_purchase_invoices'), lang('Module.nav_group_purchase_cycle')], array_column($groups, 'label'));
        $this->assertSame(['purchase_invoices', 'receivings'], array_column($groups[0]['items'], 'id'));
        $this->assertSame(['purchase_orders'], array_column($groups[1]['items'], 'id'));
        $this->assertSame(base_url('reports/detailed_receivings'), $groups[0]['items'][0]['url']);
    }

    public function testSections_PutsTheSuppliersAndTheirPaymentsAfterThePurchaseCycle(): void
    {
        $modules  = ['purchase_orders', 'suppliers', 'accounting_payables'];
        $sections = SidebarMenu::sections($modules, [...$modules, BranchGuard::ALL_BRANCHES], [], 'home');
        $groups   = $sections[0]['groups'];

        $this->assertSame(['purchases'], $this->sectionKeys($sections));
        $this->assertSame([lang('Module.nav_group_purchase_cycle'), lang('Module.nav_group_suppliers')], array_column($groups, 'label'));
        $this->assertSame(['suppliers', 'accounting_payables', 'payables_aged', 'payables_debit_notes', 'payables_payments'], array_column($groups[1]['items'], 'id'));
    }

    public function testSections_OffersThePurchaseInvoicesOnlyWithThePurchaseReportsPermission(): void
    {
        $permissions = ['reports' => ['reports_receivings', 'reports_sales']];

        $this->assertNotContains('purchase_invoices', $this->itemIds(SidebarMenu::sections(['reports'], ['reports', 'reports_sales'], $permissions, 'home')));
        $this->assertContains('purchase_invoices', $this->itemIds(SidebarMenu::sections(['reports'], ['reports', 'reports_receivings'], $permissions, 'home')));
    }

    public function testSections_ListsTheSalesInvoicesBeforeTheNewSaleAndTheDocuments(): void
    {
        $sections = SidebarMenu::sections(['sales', 'customer_orders'], ['sales', 'reports_sales', 'customer_orders'], [], 'home');

        $this->assertSame(['sales'], $this->sectionKeys($sections));
        $this->assertSame(['sales_log', 'sales', 'sales_documents', 'customer_orders'], $this->itemIds($sections));
        $this->assertSame(base_url('sales/manage'), $sections[0]['groups'][0]['items'][0]['url']);
        $this->assertSame(base_url('sales'), $sections[0]['groups'][0]['items'][1]['url']);
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

    public function testSections_StartsTheBooksWithTheOverviewOfTheGeneralAccounts(): void
    {
        $books = SidebarMenu::definition()['ledger']['groups'][0]['items'];

        $this->assertSame('accounting_overview', $books[0]['id']);
        $this->assertSame('accounting_reports/overview', $books[0]['path']);

        $ledger = array_values(array_filter(SidebarMenu::sections(['accounting_reports'], ['accounting_reports'], [], 'home'), static fn (array $section): bool => $section['key'] === 'ledger'))[0];

        $this->assertSame(['accounting_overview', 'ledger', 'trial_balance'], array_column($ledger['groups'][0]['items'], 'id'));
    }

    public function testSections_OffersTheOverviewToWhoHoldsTheReportsModuleAndMarksItApartFromTheTrialBalance(): void
    {
        $this->assertContains('accounting_overview', $this->itemIds(SidebarMenu::sections(['accounting_reports'], ['accounting_reports'], [], 'home')));
        $this->assertNotContains('accounting_overview', $this->itemIds(SidebarMenu::sections(['accounting_chart'], ['accounting_chart'], [], 'home')));
        $this->assertSame(['accounting_overview'], $this->activeIds(SidebarMenu::sections(['accounting_reports'], ['accounting_reports'], [], 'accounting_reports/overview')));
        $this->assertSame(['trial_balance'], $this->activeIds(SidebarMenu::sections(['accounting_reports'], ['accounting_reports'], [], 'accounting_reports')));
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
        $sections = SidebarMenu::sections(['accounting_treasuries'], ['accounting_treasuries', 'acc_bank_reconcile'], [], 'home');
        $urls     = array_column($sections[0]['groups'][0]['items'], 'url', 'id');

        $this->assertSame(base_url('accounting_treasuries'), $urls['accounting_treasuries']);
        $this->assertSame(base_url('accounting_treasuries/transfers'), $urls['treasury_transfers']);
        $this->assertSame(base_url('accounting_treasuries/bank'), $urls['bank_reconciliation']);
    }

    public function testSections_OffersTheBankReconciliationOnlyWithItsOwnPermission(): void
    {
        $modules = ['accounting_treasuries'];

        $this->assertNotContains('bank_reconciliation', $this->itemIds(SidebarMenu::sections($modules, ['accounting_treasuries'], [], 'home')));
        $this->assertContains('bank_reconciliation', $this->itemIds(SidebarMenu::sections($modules, ['accounting_treasuries', 'acc_bank_reconcile'], [], 'home')));
    }

    public function testSections_MarksOnlyTheSettingsPageOnTheConfigurationPageWhateverTheTab(): void
    {
        $sections = SidebarMenu::sections(['config'], ['config'], [], 'config');

        $this->assertSame(['config'], $this->activeIds($sections));
        $this->assertSame(['inventory', 'templates', 'settings'], $this->sectionKeys($sections));
        $this->assertSame([false, false, true], array_column($sections, 'active'));
    }

    public function testSections_LinksTheTabsOfTheSettingsByTheirFragment(): void
    {
        $sections = SidebarMenu::sections(['config'], ['config'], [], 'home');
        $urls     = array_merge(...array_map(static fn (array $section): array => array_column(array_merge(...array_column($section['groups'], 'items')), 'url', 'id'), $sections));
        $tabs     = array_merge(...array_map(static fn (array $section): array => array_column(array_merge(...array_column($section['groups'], 'items')), 'fragment', 'id'), $sections));

        $this->assertSame(base_url('config') . '#stock_tab', $urls['warehouses']);
        $this->assertSame(base_url('config') . '#receipt_tab', $urls['receipt_templates']);
        $this->assertSame(base_url('config') . '#invoice_tab', $urls['invoice_templates']);
        $this->assertSame(base_url('config') . '#barcode_tab', $urls['barcode_labels']);
        $this->assertSame(base_url('config'), $urls['config']);
        $this->assertSame('stock_tab', $tabs['warehouses']);
        $this->assertNull($tabs['config']);
    }

    public function testSections_RendersASectionWithOnePageAsADirectLink(): void
    {
        $alone = SidebarMenu::sections(['notifications'], ['notifications'], [], 'home');
        $both  = SidebarMenu::sections(['home', 'notifications'], ['home', 'notifications'], [], 'home');

        $this->assertSame([true], array_column($alone, 'direct'));
        $this->assertSame([false], array_column($both, 'direct'));
    }

    public function testSections_NamesTheActivityLogAndKeepsItCurrentOnTheFailuresOfThePosting(): void
    {
        $modules = ['accounting_journals'];
        $held    = ['accounting_journals', 'acc_journals_audit'];

        $this->assertNotContains('activity_log', $this->itemIds(SidebarMenu::sections($modules, ['accounting_journals'], [], 'home')));

        $sections = SidebarMenu::sections($modules, $held, [], 'accounting_journals/failures');

        $this->assertSame(['activity_log'], $this->activeIds($sections));
        $this->assertSame(['reports'], array_column(array_filter($sections, static fn (array $section): bool => $section['active']), 'key'));
    }

    public function testSections_ShowsToEveryKindOfHolderTheSamePagesTheMenuShowedBeforeAndTheListedOnes(): void
    {
        $before     = require __DIR__ . '/../../Support/SidebarMenuBeforeNav4.php';
        $added      = ['customer_classifications', 'item_brands', 'warehouses', 'journal_new', 'activity_log', 'receipt_templates', 'invoice_templates', 'barcode_labels', 'notification_rules', 'payment_methods', 'api_keys'];
        $every      = SidebarMenu::modules();
        $permits    = array_values(array_unique(array_filter(array_column($this->definedPages(), 'permission'))));
        $accounting = ['accounting_chart', 'accounting_journals', 'accounting_periods', 'accounting_reports', 'accounting_treasuries', 'accounting_receivables', 'accounting_payables', 'accounting_cheques', 'accounting_assets'];

        $holders = [
            'owner'               => [$every, [...$every, ...$permits, BranchGuard::ALL_BRANCHES], true],
            'owner without books' => [$every, [...$every, ...$permits, BranchGuard::ALL_BRANCHES], false],
            'company accountant'  => [$accounting, [...$accounting, ...$permits, BranchGuard::ALL_BRANCHES], true],
            'branch accountant'   => [$accounting, [...$accounting, ...$permits], true],
            'cashier'             => [['home', 'sales', 'customers', 'cashups'], ['home', 'sales', 'customers', 'cashups'], true],
            'store keeper'        => [['home', 'items', 'item_kits', 'stock_units', 'stock_documents'], ['home', 'items', 'item_kits', 'stock_units', 'stock_documents'], true],
            'buyer'               => [['home', 'receivings', 'suppliers', 'purchase_orders', 'reports'], ['home', 'receivings', 'suppliers', 'purchase_orders', 'reports', 'reports_receivings'], true],
            'start page only'     => [['home'], ['home'], true],
        ];

        foreach ($holders as $name => [$modules, $held, $accountingOn]) {
            $held     = array_map('strtolower', $held);
            $expected = [];

            foreach ($before as $id => $page) {
                $page['permission'] = $id === 'bank_reconciliation' ? 'acc_bank_reconcile' : ($page['permission'] ?? null);

                if ($page['permission'] === null) {
                    unset($page['permission']);
                }

                if (SidebarMenu::opens($page, $modules, $held, [], $accountingOn)) {
                    $expected[] = $id;
                }
            }

            $shown = array_values(array_diff($this->itemIds(SidebarMenu::sections($modules, $held, [], 'home', '', $accountingOn)), $added));

            $this->assertEqualsCanonicalizing($expected, $shown, $name);
        }
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
