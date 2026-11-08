<?php

namespace Tests\Helpers;

use CodeIgniter\Database\Config;
use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\DatabaseTestTrait;
use Tests\Support\AccountingFixtureTrait;

class NavigationHelperTest extends CIUnitTestCase
{
    use DatabaseTestTrait;
    use AccountingFixtureTrait;

    protected $migrate     = true;
    protected $migrateOnce = true;
    protected $seedOnce    = true;
    protected $refresh     = false;
    protected $namespace   = null;

    private static bool $doneBootstrap = false;

    protected function setUp(): void
    {
        if (self::$doneBootstrap === false) {
            Config::seeder($this->DBGroup)->call('App\Database\Seeds\TestDatabaseBootstrapSeeder');
            Config::connect($this->DBGroup)->close();

            self::$doneBootstrap = true;
        }

        parent::setUp();

        helper('navigation');
    }

    protected function tearDown(): void
    {
        session()->remove('person_id');

        parent::tearDown();
    }

    private function modules(string ...$moduleIds): array
    {
        return array_map(static fn(string $moduleId): object => (object) ['module_id' => $moduleId], $moduleIds);
    }

    private function itemIds(array $groups): array
    {
        return array_map(
            static fn(array $group): array => array_column($group['items'], 'id'),
            $groups
        );
    }

    public function testNavigationGroups_PlacesModulesInTheirGroupsInOrder(): void
    {
        $groups = navigationGroups($this->modules('config', 'sales', 'home', 'items', 'receivings'), 'sales');

        $this->assertSame([['home'], ['sales'], ['receivings'], ['items'], ['config']], $this->itemIds($groups));
    }

    public function testNavigationGroups_OmitsGroupsWithoutAllowedModules(): void
    {
        $groups = navigationGroups($this->modules('reports'), 'reports');

        $this->assertCount(1, $groups);
        $this->assertSame(['reports'], array_column($groups[0]['items'], 'id'));
    }

    public function testNavigationGroups_AppendsUnknownModulesToAdministration(): void
    {
        $groups = navigationGroups($this->modules('employees', 'custom_module'), 'home');

        $this->assertSame([['employees', 'custom_module']], $this->itemIds($groups));
    }

    public function testNavigationGroups_MarksOnlyTheActiveModule(): void
    {
        $groups = navigationGroups($this->modules('sales', 'items'), 'items');

        $active = [];
        foreach ($groups as $group) {
            foreach ($group['items'] as $item) {
                $active[$item['id']] = $item['active'];
            }
        }

        $this->assertSame(['sales' => false, 'items' => true], $active);
    }

    public function testNavigationGroups_UsesTheFallbackIconForUnknownModules(): void
    {
        $groups = navigationGroups($this->modules('custom_module'), 'home');

        $this->assertSame(NAVIGATION_FALLBACK_ICON, $groups[0]['items'][0]['icon']);
    }

    public function testNavigationGroups_LinksToTheModuleRoot(): void
    {
        $groups = navigationGroups($this->modules('customers'), 'home');

        $this->assertSame(base_url('customers'), $groups[0]['items'][0]['url']);
    }

    public function testNavigationActiveModule_ReturnsHomeForTheRootPath(): void
    {
        $this->assertSame('home', navigationActiveModule(''));
    }

    public function testNavigationActiveModule_ReturnsTheFirstSegment(): void
    {
        $this->assertSame('items', navigationActiveModule('items'));
    }

    public function testNavigationPageTitle_ReturnsTheModuleName(): void
    {
        $this->assertSame(lang('Module.sales'), navigationPageTitle('sales'));
    }

    public function testNavigationPageTitle_ReturnsEmptyForNonModuleControllers(): void
    {
        $this->assertSame('', navigationPageTitle('tax_codes'));
    }

    public function testSettingsSections_ListsEverySectionOnce(): void
    {
        $ids = [];

        foreach (settingsSections() as [, $sections]) {
            foreach ($sections as [$id]) {
                $ids[] = $id;
            }
        }

        $this->assertCount(13, $ids);
        $this->assertSame($ids, array_values(array_unique($ids)));
    }

    public function testNavigationSearchIndex_ListsOnlyAllowedModules(): void
    {
        $entries = navigationSearchIndex($this->modules('sales', 'items'), []);

        $this->assertSame(['sales', 'items'], array_map(static fn (array $entry): string => basename($entry['url']), $entries));
        $this->assertSame(['module'], array_values(array_unique(array_column($entries, 'type'))));
    }

    public function testNavigationSearchIndex_AddsPermittedReportsForTheReportsModule(): void
    {
        $entries = navigationSearchIndex($this->modules('reports'), ['reports_items', 'home']);
        $reports = array_values(array_filter($entries, static fn (array $entry): bool => $entry['type'] === 'report'));

        $this->assertCount(2, $reports);
        $this->assertStringEndsWith('reports/graphical_summary_items', $reports[0]['url']);
        $this->assertStringEndsWith('reports/summary_items', $reports[1]['url']);
    }

    public function testNavigationSearchIndex_OmitsReportsWithoutTheReportsModule(): void
    {
        $entries = navigationSearchIndex($this->modules('sales'), ['reports_items']);

        $this->assertNotContains('report', array_column($entries, 'type'));
    }

    public function testNavigationSearchIndex_AddsSettingsSectionsForTheConfigModule(): void
    {
        $entries  = navigationSearchIndex($this->modules('config'), []);
        $settings = array_values(array_filter($entries, static fn (array $entry): bool => $entry['type'] === 'setting'));

        $this->assertCount(13, $settings);
        $this->assertStringEndsWith('config#info_tab', $settings[0]['url']);
    }

    public function testNavigationSearchIndex_OmitsSettingsWithoutTheConfigModule(): void
    {
        $entries = navigationSearchIndex($this->modules('sales'), []);

        $this->assertNotContains('setting', array_column($entries, 'type'));
    }

    public function testNavigationGroups_PutsTheFourDocumentsOfThePurchaseCycleInThePurchasesGroupBeforeTheReceivingsInTheOrderOfTheChain(): void
    {
        $groups = navigationGroups($this->modules('purchase_orders', 'receivings', 'supplier_quotations', 'quotation_requests', 'purchase_requests', 'suppliers'), 'purchase_orders');

        $this->assertSame([['purchase_requests', 'quotation_requests', 'supplier_quotations', 'purchase_orders', 'receivings', 'suppliers']], $this->itemIds($groups));
    }

    public function testNavigationGroups_ShowsOnlyTheDocumentsOfThePurchaseCycleTheEmployeeMayOpenWithAnIconAndTheirOwnAddress(): void
    {
        $groups = navigationGroups($this->modules('quotation_requests'), 'home');

        $this->assertCount(1, $groups);
        $this->assertSame(['quotation_requests'], array_column($groups[0]['items'], 'id'));
        $this->assertSame(base_url('quotation_requests'), $groups[0]['items'][0]['url']);
        $this->assertNotSame(NAVIGATION_FALLBACK_ICON, $groups[0]['items'][0]['icon']);
    }

    public function testNavigationSearchIndex_OffersTheReportsOfTheBooksBehindTheirPermissionAndOnlyWhileAccountingIsOn(): void
    {
        session()->set('person_id', $this->employeeWithGrants([], null));

        $previous = $this->accountingDb()->table('app_config')->where('key', 'accounting_enabled')->get()->getRow();

        $this->setConfigValue('accounting_enabled', '1');

        try {
            $modules = $this->modules('reports');
            $urls    = static fn (array $permissions): array => array_column(array_filter(navigationSearchIndex($modules, $permissions), static fn (array $entry): bool => $entry['type'] === 'report'), 'url');

            $cheques = $urls(['accounting_cheques']);

            $this->assertContains(site_url('accounting_cheques/report/received'), $cheques);
            $this->assertNotContains(site_url('accounting_statements/income_statement'), $cheques);
            $this->assertContains(site_url('accounting_statements/income_statement'), $urls(['accounting_reports']));
            $this->assertContains(site_url('accounting_receivables/aged_ledger'), $urls(['accounting_receivables']));

            $this->setConfigValue('accounting_enabled', '0');

            $this->assertSame([], $urls(['accounting_cheques', 'accounting_reports', 'accounting_receivables']));
        } finally {
            $previous === null
                ? $this->accountingDb()->table('app_config')->where('key', 'accounting_enabled')->delete()
                : $this->setConfigValue('accounting_enabled', (string) $previous->value);
        }
    }
}
