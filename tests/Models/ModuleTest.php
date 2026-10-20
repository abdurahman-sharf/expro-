<?php

namespace Tests\Models;

use App\Models\Module;
use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\DatabaseTestTrait;
use Tests\Support\EmployeeFixtureTrait;

class ModuleTest extends CIUnitTestCase
{
    use DatabaseTestTrait;
    use EmployeeFixtureTrait;

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

    public function testGetAllowedModules_ReturnsNothingWithoutGrants(): void
    {
        $personId = $this->createEmployee();

        $this->assertSame([], $this->allowedModuleIds($personId));
    }
}
