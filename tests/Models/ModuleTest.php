<?php

namespace Tests\Models;

use App\Libraries\Roles\RoleTablesUnavailableException;
use App\Models\Module;
use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\DatabaseTestTrait;
use Config\Database;
use Tests\Support\EmployeeFixtureTrait;
use Tests\Support\RoleFixtureTrait;

class ModuleTest extends CIUnitTestCase
{
    use DatabaseTestTrait;
    use EmployeeFixtureTrait;
    use RoleFixtureTrait;

    protected $migrate     = true;
    protected $migrateOnce = true;
    protected $refresh     = false;
    protected $namespace   = null;

    private function allowedModuleIds(int $personId): array
    {
        $rows = model(Module::class)->getAllowedModules($personId)->getResult();

        return array_map(static fn(object $row): string => $row->module_id, $rows);
    }

    public function testGetAllowedModules_ReturnsOnlyModulesTheEmployeeHoldsGrantsFor(): void
    {
        $personId = $this->createEmployee(grants: [
            ['permission_id' => 'sales', 'menu_group' => 'home'],
            ['permission_id' => 'items', 'menu_group' => 'office']
        ]);

        $this->assertSame(['items', 'sales'], $this->allowedModuleIds($personId));
    }

    public function testGetAllowedModules_IgnoresTheMenuGroupOfTheGrant(): void
    {
        $personId = $this->createEmployee(grants: [
            ['permission_id' => 'customers', 'menu_group' => 'home'],
            ['permission_id' => 'employees', 'menu_group' => 'office'],
            ['permission_id' => 'reports', 'menu_group' => 'both']
        ]);

        $this->assertSame(['customers', 'reports', 'employees'], $this->allowedModuleIds($personId));
    }

    public function testGetAllowedModules_OrdersModulesBySort(): void
    {
        $personId = $this->createEmployee(grants: [
            ['permission_id' => 'config', 'menu_group' => 'office'],
            ['permission_id' => 'home', 'menu_group' => 'home'],
            ['permission_id' => 'sales', 'menu_group' => 'home']
        ]);

        $this->assertSame(['home', 'sales', 'config'], $this->allowedModuleIds($personId));
    }

    public function testGetAllowedModules_ExcludesTheOfficeModule(): void
    {
        $personId = $this->createEmployee(grants: [
            ['permission_id' => 'office', 'menu_group' => 'home'],
            ['permission_id' => 'sales', 'menu_group' => 'home']
        ]);

        $this->assertSame(['sales'], $this->allowedModuleIds($personId));
    }

    private function setAccountingEnabled(string $value): void
    {
        Database::connect()->table('app_config')->replace(['key' => 'accounting_enabled', 'value' => $value]);
    }

    public function testGetAllowedModules_HidesTheAccountingModulesWhileAccountingIsDisabled(): void
    {
        $this->setAccountingEnabled('0');

        $personId = $this->createEmployee(grants: [
            ['permission_id' => 'sales', 'menu_group' => 'home'],
            ['permission_id' => 'accounting_chart', 'menu_group' => 'home'],
            ['permission_id' => 'accounting_journals', 'menu_group' => 'home'],
        ]);

        $this->assertSame(['sales'], $this->allowedModuleIds($personId));
    }

    public function testGetAllowedModules_ShowsTheAccountingModulesOnceAccountingIsEnabled(): void
    {
        $this->setAccountingEnabled('1');

        try {
            $personId = $this->createEmployee(grants: [
                ['permission_id' => 'sales', 'menu_group' => 'home'],
                ['permission_id' => 'accounting_journals', 'menu_group' => 'home'],
                ['permission_id' => 'accounting_chart', 'menu_group' => 'home'],
            ]);

            $this->assertSame(['sales', 'accounting_chart', 'accounting_journals'], $this->allowedModuleIds($personId));
        } finally {
            $this->setAccountingEnabled('0');
        }
    }

    public function testGetAllowedModules_ReturnsNothingWithoutGrants(): void
    {
        $personId = $this->createEmployee();

        $this->assertSame([], $this->allowedModuleIds($personId));
    }

    public function testGetAllowedModules_KeepsTheSettingsModuleVisibleWhileAccountingIsDisabled(): void
    {
        $this->setAccountingEnabled('0');

        $personId = $this->createEmployee(grants: [
            ['permission_id' => 'accounting_settings', 'menu_group' => 'home'],
            ['permission_id' => 'accounting_periods', 'menu_group' => 'home'],
            ['permission_id' => 'accounting_chart', 'menu_group' => 'home'],
        ]);

        $this->assertSame(['accounting_settings'], $this->allowedModuleIds($personId));
    }

    public function testGetAllowedModules_ShowsThePeriodsModuleOnceAccountingIsEnabled(): void
    {
        $this->setAccountingEnabled('1');

        try {
            $personId = $this->createEmployee(grants: [
                ['permission_id' => 'accounting_periods', 'menu_group' => 'home'],
                ['permission_id' => 'accounting_settings', 'menu_group' => 'home'],
            ]);

            $this->assertSame(['accounting_settings', 'accounting_periods'], array_values(array_intersect(['accounting_settings', 'accounting_periods'], $this->allowedModuleIds($personId))));
        } finally {
            $this->setAccountingEnabled('0');
        }
    }

    public function testGetAllowedModules_RefusesWhenTheRolesCannotBeReadInsteadOfOfferingTheOwnGrantsOnly(): void
    {
        $personId = $this->employeeWithRoles([$this->createRole(['items'])], ['sales']);

        $this->withUnreadableTable('role_permissions', function () use ($personId): void {
            try {
                $this->allowedModuleIds($personId);
                $this->fail('Roles that could not be read were taken for roles that give nothing.');
            } catch (RoleTablesUnavailableException) {
                $this->addToAssertionCount(1);
            }
        });
    }
}
