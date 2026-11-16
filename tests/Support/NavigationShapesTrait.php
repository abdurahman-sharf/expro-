<?php

namespace Tests\Support;

use App\Libraries\Organization\BranchGuard;
use App\Libraries\Roles\RoleGrants;
use CodeIgniter\Router\Router;
use Config\Database;

/**
 * The employees of every shape the navigation tests walk through. The class using it also uses AccountingFixtureTrait
 * and RoleFixtureTrait.
 */
trait NavigationShapesTrait
{
    /**
     * @return array<string, list<string>> Permission ids of each module that start with the module's id.
     */
    protected function ownPermissions(): array
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
    protected function everyModuleId(): array
    {
        return array_column(Database::connect()->table('modules')->select('module_id')->get()->getResultArray(), 'module_id');
    }

    /**
     * @return list<string>
     */
    protected function everyPermissionId(): array
    {
        return array_column(Database::connect()->table('permissions')->select('permission_id')->get()->getResultArray(), 'permission_id');
    }

    /**
     * @param list<string> $permissionIds
     */
    protected function employeeHolding(array $permissionIds): int
    {
        return $this->createEmployee(grants: array_map(
            static fn (string $permissionId): array => ['permission_id' => $permissionId, 'menu_group' => 'home'],
            array_values(array_unique($permissionIds)),
        ));
    }

    /**
     * @param list<string> $permissionIds
     */
    protected function employeeHoldingThroughARole(array $permissionIds): int
    {
        return $this->employeeWithRoles([$this->createRole(array_values(array_diff($permissionIds, RoleGrants::forbidden())))]);
    }

    /**
     * @return array<string, int>
     */
    protected function employeesOfEveryShape(): array
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

    protected function withAccounting(bool $enabled, callable $then): void
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

    protected function handlerOf(string $uri): array
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
}
