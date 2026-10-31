<?php

namespace Tests\Libraries\Roles;

use App\Libraries\Roles\DefaultLocations;
use App\Libraries\Roles\RoleError;
use App\Libraries\Roles\RoleException;
use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\DatabaseTestTrait;
use Config\Database;
use Tests\Support\EmployeeFixtureTrait;
use Tests\Support\RoleFixtureTrait;

/**
 * @internal
 */
final class DefaultLocationsTest extends CIUnitTestCase
{
    use DatabaseTestTrait;
    use EmployeeFixtureTrait;
    use RoleFixtureTrait;

    protected $migrate     = true;
    protected $migrateOnce = true;
    protected $refresh     = false;
    protected $namespace;
    private DefaultLocations $defaults;

    public static function setUpBeforeClass(): void
    {
        Database::seeder('tests')->call('TestDatabaseBootstrapSeeder');
    }

    protected function setUp(): void
    {
        parent::setUp();

        $this->defaults = new DefaultLocations();
    }

    private function location(string $name = 'Depot'): int
    {
        $this->roleDb()->table('stock_locations')->insert(['location_name' => $name . ' ' . uniqid()]);

        return (int) $this->roleDb()->insertID();
    }

    private function refusal(callable $action): RoleException
    {
        try {
            $action();
        } catch (RoleException $exception) {
            return $exception;
        }

        $this->fail('The change was not refused.');
    }

    private function editor(): int
    {
        return $this->createEmployee(grants: [['permission_id' => 'employees', 'menu_group' => 'home']]);
    }

    public function testOfIsNullForAnEmployeeWhoWasGivenNoLocation(): void
    {
        $this->assertNull($this->defaults->of($this->createEmployee()));
        $this->assertNull($this->defaults->of(0));
        $this->assertNull($this->defaults->of(999999));
    }

    public function testSetStoresTheLocationOfAnEmployeeAndAuditsTheChange(): void
    {
        $employee = $this->createEmployee();
        $location = $this->location();

        $this->defaults->set($employee, $location, $this->editor());

        $this->assertSame($location, $this->defaults->of($employee));
        $this->assertSame(1, $this->roleDb()->table('accounting_audit_log')->where(['entity_type' => 'employee', 'entity_id' => $employee, 'action' => 'default_location_set'])->countAllResults());
    }

    public function testSetReplacesThePreviousDefaultOfTheSameEmployee(): void
    {
        $employee = $this->createEmployee();
        $first    = $this->location('First');
        $second   = $this->location('Second');

        $this->defaults->set($employee, $first, null);
        $this->defaults->set($employee, $second, null);

        $this->assertSame($second, $this->defaults->of($employee));
        $this->assertSame(1, $this->roleDb()->table('employee_default_locations')->where('person_id', $employee)->countAllResults(), 'An employee has one default at most');
    }

    public function testSetRemovesTheDefaultWhenNoLocationIsGiven(): void
    {
        $employee = $this->createEmployee();

        $this->defaults->set($employee, $this->location(), null);
        $this->defaults->set($employee, null, null);

        $this->assertNull($this->defaults->of($employee));
    }

    public function testSetWritesNoEntryWhenTheLocationStaysTheSame(): void
    {
        $employee = $this->createEmployee();
        $location = $this->location();

        $this->defaults->set($employee, $location, null);
        $this->defaults->set($employee, $location, null);

        $this->assertSame(1, $this->roleDb()->table('accounting_audit_log')->where(['entity_type' => 'employee', 'entity_id' => $employee, 'action' => 'default_location_set'])->countAllResults());
    }

    public function testSetRejectsALocationThatDoesNotExistOrWasDeleted(): void
    {
        $employee = $this->createEmployee();
        $deleted  = $this->location('Gone');

        $this->roleDb()->table('stock_locations')->where('location_id', $deleted)->update(['deleted' => 1]);

        $this->assertSame(RoleError::LocationNotFound, $this->refusal(fn () => $this->defaults->set($employee, 999999, null))->error);
        $this->assertSame(RoleError::LocationNotFound, $this->refusal(fn () => $this->defaults->set($employee, $deleted, null))->error);
        $this->assertNull($this->defaults->of($employee));
    }

    public function testSetRejectsAnEmployeeThatDoesNotExistOrWasDeleted(): void
    {
        $deleted = $this->createEmployee();

        $this->roleDb()->table('employees')->where('person_id', $deleted)->update(['deleted' => 1]);

        $this->assertSame(RoleError::EmployeeNotFound, $this->refusal(fn () => $this->defaults->set(999999, $this->location(), null))->error);
        $this->assertSame(RoleError::EmployeeNotFound, $this->refusal(fn () => $this->defaults->set($deleted, $this->location(), null))->error);
    }

    public function testSetRequiresThePermissionToChangeEmployees(): void
    {
        $employee = $this->createEmployee();
        $plain    = $this->createEmployee();

        $this->assertSame(RoleError::PermissionDenied, $this->refusal(fn () => $this->defaults->set($employee, $this->location(), $plain))->error);
        $this->assertNull($this->defaults->of($employee));
    }

    public function testOfIgnoresALocationThatWasDeletedAfterwards(): void
    {
        $employee = $this->createEmployee();
        $location = $this->location();

        $this->defaults->set($employee, $location, null);
        $this->roleDb()->table('stock_locations')->where('location_id', $location)->update(['deleted' => 1]);

        $this->assertNull($this->defaults->of($employee), 'An employee whose location was deleted starts where they always did');
    }
}
