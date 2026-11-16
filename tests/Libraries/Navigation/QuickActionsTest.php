<?php

namespace Tests\Libraries\Navigation;

use App\Libraries\Navigation\QuickActions;
use App\Libraries\Navigation\SidebarMenu;
use App\Libraries\Roles\RoleGrants;
use App\Models\Module;
use CodeIgniter\Language\Language;
use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\DatabaseTestTrait;
use Config\Database;
use Config\OSPOS;
use Tests\Support\AccountingFixtureTrait;
use Tests\Support\NavigationShapesTrait;
use Tests\Support\RoleFixtureTrait;

/**
 * @internal
 */
final class QuickActionsTest extends CIUnitTestCase
{
    use AccountingFixtureTrait;
    use DatabaseTestTrait;
    use NavigationShapesTrait;
    use RoleFixtureTrait;

    private const MENU_PAGE = [
        'pos'                 => 'sales',
        'add_purchase'        => 'receivings',
        'add_item'            => 'items',
        'add_supplier'        => 'suppliers',
        'add_customer'        => 'customers',
        'add_quote'           => 'sales',
        'add_expense'         => 'expenses',
        'add_user'            => 'employees',
        'profit_report'       => 'income_statement',
        'stock_report'        => 'reports',
        'add_receipt_voucher' => 'receipt_vouchers',
        'add_journal'         => 'accounting_journals',
        'sales_invoices'      => 'sales_log',
        'drafts_quotes'       => 'sales_documents',
        'purchase_invoices'   => 'purchase_invoices',
        'customer_receipts'   => 'receivables_payments',
        'supplier_payments'   => 'payables_payments',
        'cashups'             => 'cashups',
        'chart_tree'          => 'accounting_chart',
    ];

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

    /**
     * @return list<string>
     */
    private function shortcutsOf(int $personId, bool $accountingOn = true, bool $invoiceOn = true): array
    {
        $shortcuts = [];

        $this->withAccounting($accountingOn, function () use ($personId, $accountingOn, $invoiceOn, &$shortcuts): void {
            $modules              = model(Module::class)->getAllowedModules($personId)->getResult();
            [$held, $permissions] = navigationGrants($personId);

            $shortcuts = array_column(QuickActions::visible(
                array_map(static fn (object $module): string => $module->module_id, $modules),
                $held,
                $permissions,
                $accountingOn,
                $invoiceOn,
            ), 'id');
        });

        return $shortcuts;
    }

    /**
     * @return list<string>
     */
    private function menuPagesOf(int $personId): array
    {
        $modules = model(Module::class)->getAllowedModules($personId)->getResult();
        $ids     = [];

        foreach (navigationSections($modules, 'home', $personId) as $section) {
            foreach ($section['groups'] as $group) {
                array_push($ids, ...array_column($group['items'], 'id'));
            }
        }

        return $ids;
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

    private function withInvoiceMode(bool $on, callable $then): void
    {
        $settings = config(OSPOS::class)->settings;
        $before   = $settings['invoice_enable'] ?? null;

        config(OSPOS::class)->settings['invoice_enable'] = $on ? 1 : 0;

        try {
            $then();
        } finally {
            config(OSPOS::class)->settings['invoice_enable'] = $before;
        }
    }

    public function testCatalog_GivesEveryShortcutAUniqueId(): void
    {
        $ids = array_column(QuickActions::catalog(), 'id');

        $this->assertSame($ids, array_values(array_unique($ids)));
        $this->assertSame(array_keys(self::MENU_PAGE), $ids);
    }

    public function testCatalog_PinsTwelveShortcutsInOrderUntilThePersonChoosesTheirOwn(): void
    {
        $defaults = array_filter(array_column(QuickActions::catalog(), 'default', 'id'));

        asort($defaults);

        $this->assertSame(range(1, QuickActions::MAX_PINNED), array_values($defaults));
        $this->assertSame(['pos', 'add_purchase', 'add_item', 'add_supplier', 'add_customer', 'add_quote', 'add_expense', 'add_user', 'profit_report', 'stock_report', 'add_receipt_voucher', 'add_journal'], array_keys($defaults));
    }

    public function testCatalog_NamesEveryShortcutInArabic(): void
    {
        $language = new Language('ar-EG');

        foreach (QuickActions::catalog() as $action) {
            $line = $language->getLine($action['label']);

            $this->assertNotSame($action['label'], $line, $action['id']);
            $this->assertMatchesRegularExpression('/\p{Arabic}/u', $line, $action['id']);
        }
    }

    public function testCatalog_PointsEveryShortcutAtARouteOfAnExistingController(): void
    {
        foreach (QuickActions::catalog() as $action) {
            [$controller, $method] = $this->handlerOf($action['url']);

            $this->assertTrue(class_exists($controller), "{$action['url']}: {$controller}");
            $this->assertTrue(method_exists($controller, $method), "{$action['url']}: {$controller}::{$method}");
        }
    }

    public function testCatalog_OpensEveryShortcutInOneOfThreeWays(): void
    {
        foreach (QuickActions::catalog() as $action) {
            $this->assertContains($action['kind'], [QuickActions::PAGE, QuickActions::DIALOG, QuickActions::QUOTE], $action['id']);
        }
    }

    public function testVisible_OffersNothingToAnEmployeeWithoutGrants(): void
    {
        $this->assertSame([], $this->shortcutsOf($this->employeeHolding([])));
    }

    public function testVisible_OffersEveryShortcutToAnEmployeeWithEveryPermission(): void
    {
        $personId = $this->employeeHolding($this->everyPermissionId());

        $this->assertSame(array_keys(self::MENU_PAGE), $this->shortcutsOf($personId));
    }

    public function testVisible_OffersTheQuoteOnlyWhileTheInvoiceModeIsOn(): void
    {
        $personId = $this->employeeHolding($this->everyPermissionId());

        $this->assertContains('add_quote', $this->shortcutsOf($personId, true, true));
        $this->assertNotContains('add_quote', $this->shortcutsOf($personId, true, false));
        $this->assertContains('pos', $this->shortcutsOf($personId, true, false));
    }

    public function testVisible_LeavesOutTheShortcutsOfTheBooksWhileAccountingIsOff(): void
    {
        $personId = $this->employeeHolding($this->everyPermissionId());
        $shown    = $this->shortcutsOf($personId, false);

        foreach (['profit_report', 'add_receipt_voucher', 'add_journal', 'chart_tree', 'customer_receipts', 'supplier_payments'] as $id) {
            $this->assertNotContains($id, $shown, $id);
        }

        foreach (['pos', 'add_purchase', 'add_item', 'add_customer', 'add_expense', 'stock_report'] as $id) {
            $this->assertContains($id, $shown, $id);
        }
    }

    public function testVisible_OffersTheStockReportOnlyWithThePermissionOfTheInventoryReports(): void
    {
        $own     = $this->ownPermissions();
        $without = array_values(array_diff($own['reports'], ['reports_inventory']));

        $this->assertContains('reports_inventory', $own['reports']);
        $this->assertNotContains('stock_report', $this->shortcutsOf($this->employeeHolding(['reports', ...$without])));
        $this->assertContains('stock_report', $this->shortcutsOf($this->employeeHolding(['reports', 'reports_inventory'])));
    }

    public function testVisible_OffersTheNewJournalOnlyWithThePermissionToDraftJournals(): void
    {
        $this->assertNotContains('add_journal', $this->shortcutsOf($this->employeeHolding(['accounting_journals'])));
        $this->assertContains('add_journal', $this->shortcutsOf($this->employeeHolding(['accounting_journals', 'acc_journals_draft'])));
    }

    public function testVisible_OffersTheSalesAndPurchaseLogsOnlyWithTheReportsPermissionOfEach(): void
    {
        $sales = $this->shortcutsOf($this->employeeHolding(['sales', 'reports_sales', ...$this->ownPermissions()['sales']]));
        $plain = $this->shortcutsOf($this->employeeHolding(['sales', ...$this->ownPermissions()['sales']]));

        $this->assertContains('sales_invoices', $sales);
        $this->assertContains('drafts_quotes', $sales);
        $this->assertNotContains('sales_invoices', $plain);
        $this->assertNotContains('drafts_quotes', $plain);
        $this->assertContains('pos', $plain);
        $this->assertContains('purchase_invoices', $this->shortcutsOf($this->employeeHolding(['reports', 'reports_receivings', ...$this->ownPermissions()['reports']])));
        $this->assertNotContains('purchase_invoices', $this->shortcutsOf($this->employeeHolding(['reports', ...array_diff($this->ownPermissions()['reports'], ['reports_receivings'])])));
    }

    public function testVisible_AsksTheGrantOfTheModuleThroughTheRolesOfTheEmployeeToo(): void
    {
        $own = $this->ownPermissions();

        $this->assertNotContains('pos', $this->shortcutsOf($this->employeeWithRoles([$this->createRole(['sales'])])));
        $this->assertContains('pos', $this->shortcutsOf($this->employeeWithRoles([$this->createRole(['sales', $own['sales'][0]])])));
    }

    public function testVisible_ShowsAShortcutOnlyWhereTheMenuShowsThePageItOpens(): void
    {
        $this->withAccounting(true, function (): void {
            $this->withInvoiceMode(true, function (): void {
                foreach ($this->employeesOfEveryShape() as $shape => $personId) {
                    $menu = $this->menuPagesOf($personId);

                    foreach ($this->shortcutsOf($personId) as $id) {
                        $this->assertContains(self::MENU_PAGE[$id], $menu, "{$shape}: {$id}");
                    }
                }
            });
        });
    }

    public function testVisible_ShowsAShortcutWheneverTheMenuShowsItsPageWhenThePageAsksNothingMore(): void
    {
        $this->withAccounting(true, function (): void {
            $this->withInvoiceMode(true, function (): void {
                $alone = array_diff(array_keys(self::MENU_PAGE), ['stock_report', 'add_journal']);

                foreach ($this->employeesOfEveryShape() as $shape => $personId) {
                    $menu  = $this->menuPagesOf($personId);
                    $shown = $this->shortcutsOf($personId);

                    foreach ($alone as $id) {
                        $this->assertSame(in_array(self::MENU_PAGE[$id], $menu, true), in_array($id, $shown, true), "{$shape}: {$id}");
                    }
                }
            });
        });
    }

    public function testCatalog_AsksTheCompanyScopeOfTheMenuPageEachShortcutOpensWhenItAsksIt(): void
    {
        $pages = array_column($this->definedPages(), null, 'id');

        foreach (QuickActions::catalog() as $action) {
            $page = $pages[self::MENU_PAGE[$action['id']]];

            $this->assertSame($page['company'] ?? false, $action['company'] ?? false, $action['id']);
            $this->assertSame($page['also'] ?? [], $action['also'] ?? [], $action['id']);
        }
    }

    public function testQuickActionsFor_ListsTheShortcutsOfThePersonGiven(): void
    {
        $this->withAccounting(true, function (): void {
            $this->withInvoiceMode(true, function (): void {
                $personId = $this->employeeHolding(['sales', 'customers', ...$this->ownPermissions()['sales']]);
                $modules  = model(Module::class)->getAllowedModules($personId)->getResult();

                $this->assertSame(['pos', 'add_customer', 'add_quote'], array_column(quickActionsFor($modules, $personId), 'id'));
            });
        });
    }

    public function testQuickActionsFor_LeavesTheQuoteOutWhileTheInvoiceModeIsOff(): void
    {
        $this->withAccounting(true, function (): void {
            $this->withInvoiceMode(false, function (): void {
                $personId = $this->employeeHolding(['sales', 'customers', ...$this->ownPermissions()['sales']]);
                $modules  = model(Module::class)->getAllowedModules($personId)->getResult();

                $this->assertSame(['pos', 'add_customer'], array_column(quickActionsFor($modules, $personId), 'id'));
            });
        });
    }

    public function testSupportUrl_OpensTheFixedWhatsAppNumberWithTheMessage(): void
    {
        $this->assertSame('966530882752', SUPPORT_WHATSAPP);
        $this->assertSame('https://wa.me/966530882752?text=' . rawurlencode('مرحبا، أحتاج دعما'), QuickActions::supportUrl('مرحبا، أحتاج دعما'));
    }
}
