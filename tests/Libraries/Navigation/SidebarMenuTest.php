<?php

namespace Tests\Libraries\Navigation;

use App\Libraries\Navigation\SidebarMenu;
use App\Libraries\Organization\BranchGuard;
use App\Libraries\Roles\RoleGrants;
use App\Models\Employee;
use App\Models\Module;
use CodeIgniter\Language\Language;
use CodeIgniter\Router\Router;
use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\DatabaseTestTrait;
use Config\Database;
use Tests\Support\AccountingFixtureTrait;
use Tests\Support\RoleFixtureTrait;

/**
 * @internal
 */
final class SidebarMenuTest extends CIUnitTestCase
{
    use AccountingFixtureTrait;
    use DatabaseTestTrait;
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
     * @return array<string, list<string>> Permission ids of each module that start with the module's id.
     */
    private function ownPermissions(): array
    {
        $own = [];

        foreach (Database::connect()->table('permissions')->select('module_id, permission_id')->get()->getResultArray() as $row) {
            if (str_starts_with(strtolower($row['permission_id']), strtolower($row['module_id']) . '_')) {
                $own[$row['module_id']][] = $row['permission_id'];
            }
        }

        return $own;
    }

    /**
     * @return list<string>
     */
    private function everyModuleId(): array
    {
        return array_column(Database::connect()->table('modules')->select('module_id')->get()->getResultArray(), 'module_id');
    }

    /**
     * @return list<string>
     */
    private function everyPermissionId(): array
    {
        return array_column(Database::connect()->table('permissions')->select('permission_id')->get()->getResultArray(), 'permission_id');
    }

    /**
     * @param list<string> $permissionIds
     */
    private function employeeHolding(array $permissionIds): int
    {
        return $this->createEmployee(grants: array_map(
            static fn (string $permissionId): array => ['permission_id' => $permissionId, 'menu_group' => 'home'],
            array_values(array_unique($permissionIds)),
        ));
    }

    /**
     * @param list<string> $permissionIds
     */
    private function employeeHoldingThroughARole(array $permissionIds): int
    {
        return $this->employeeWithRoles([$this->createRole(array_values(array_diff($permissionIds, RoleGrants::forbidden())))]);
    }

    /**
     * @return array<string, int>
     */
    private function employeesOfEveryShape(): array
    {
        $own     = $this->ownPermissions();
        $modules = $this->everyModuleId();
        $oneEach = [];

        foreach ($modules as $moduleId) {
            $oneEach[] = $moduleId;

            if (isset($own[$moduleId])) {
                $oneEach[] = $own[$moduleId][0];
            }
        }

        $everything = $this->everyPermissionId();
        $balances   = ['accounting_receivables', 'accounting_payables', ...($own['accounting_receivables'] ?? []), ...($own['accounting_payables'] ?? [])];
        $half       = array_slice($modules, 0, intdiv(count($modules), 2));
        $rest       = array_slice($modules, intdiv(count($modules), 2));

        RoleGrants::flush();

        return [
            'no permission at all'                              => $this->employeeHolding([]),
            'the grant of every module and no more'             => $this->employeeHolding($modules),
            'each module with one permission of its own'        => $this->employeeHolding($oneEach),
            'every permission'                                  => $this->employeeHolding($everything),
            'the permissions of a role'                         => $this->employeeHoldingThroughARole($oneEach),
            'half of the modules directly, half through a role' => $this->employeeWithRoles([$this->createRole(array_values(array_diff($rest, RoleGrants::forbidden())))], $half),
            'only the sales cycle'                              => $this->employeeHolding(['sales', 'customers', ...($own['sales'] ?? []), ...($own['customers'] ?? [])]),
            'the balances of a branch'                          => $this->employeeHolding($balances),
            'the balances of the company'                       => $this->employeeHolding([...$balances, BranchGuard::ALL_BRANCHES]),
        ];
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

    private function withAccounting(bool $enabled, callable $then): void
    {
        $previous = $this->accountingDb()->table('app_config')->where('key', 'accounting_enabled')->get()->getRow();

        $this->setConfigValue('accounting_enabled', $enabled ? '1' : '0');

        try {
            $then();
        } finally {
            $previous === null
                ? $this->accountingDb()->table('app_config')->where('key', 'accounting_enabled')->delete()
                : $this->setConfigValue('accounting_enabled', (string) $previous->value);
        }
    }

    private function handlerOf(string $uri): array
    {
        $routes = service('routes', true);
        $routes->loadRoutes();
        $routes->setHTTPVerb('get');

        $request = service('incomingrequest', null, false);
        $request->setMethod('GET');

        $router = new Router($routes, $request);
        $router->handle($uri);

        return [$router->controllerName(), $router->methodName()];
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
        $this->assertSame(['home', 'sales', 'purchases', 'inventory', 'ledger', 'treasury', 'reports', 'settings'], array_keys(SidebarMenu::definition()));
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
        $this->assertSame(['sales', 'sales_log', 'sales_documents'], $with);
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
        $kept    = ['receivables_payments', 'payables_payments', 'payables_debit_notes'];
        $whole   = ['accounting_receivables', 'receivables_payments', 'receivables_credit_notes', 'receivables_aged', 'accounting_payables', 'payables_payments', 'payables_debit_notes', 'payables_aged'];

        $this->assertSame($kept, $this->itemIds(SidebarMenu::sections($modules, $held, [], 'home')));
        $this->assertEqualsCanonicalizing($whole, $this->itemIds(SidebarMenu::sections($modules, [...$held, BranchGuard::ALL_BRANCHES], [], 'home')));
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
