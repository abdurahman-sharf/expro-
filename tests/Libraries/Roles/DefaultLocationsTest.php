<?php

namespace Tests\Libraries\Roles;

use App\Libraries\Roles\DefaultLocations;
use App\Libraries\Roles\RoleError;
use App\Libraries\Roles\RoleException;
use App\Libraries\Roles\RoleTablesUnavailableException;
use CodeIgniter\Database\Exceptions\DatabaseException;
use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\DatabaseTestTrait;
use Config\Database;
use Tests\Support\EmployeeFixtureTrait;
use Tests\Support\FailingTableConnection;
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

    public function testOf_IsNullForAnEmployeeWhoWasGivenNoLocation(): void
    {
        $this->assertNull($this->defaults->of($this->createEmployee()));
        $this->assertNull($this->defaults->of(0));
        $this->assertNull($this->defaults->of(999999));
    }

    public function testOf_IsNullAndDoesNotFailBeforeTheMigrationHasCreatedTheTables(): void
    {
        $employee = $this->createEmployee();
        $location = $this->location();

        $this->defaults->set($employee, $location, null);

        $this->withoutRoleTables(function () use ($employee): void {
            $this->assertNull($this->defaults->of($employee));
        });

        $this->assertSame($location, $this->defaults->of($employee));
    }

    public function testCheck_RejectsALocationThatDoesNotExistOrWasDeletedAndAcceptsNoneOrOneThatIsThere(): void
    {
        $location = $this->location();
        $deleted  = $this->location('Closed');

        $this->roleDb()->table('stock_locations')->where('location_id', $deleted)->update(['deleted' => 1]);

        $this->assertSame(RoleError::LocationNotFound, $this->refusal(fn () => $this->defaults->check(999999))->error);
        $this->assertSame(RoleError::LocationNotFound, $this->refusal(fn () => $this->defaults->check($deleted))->error);

        $this->defaults->check($location);
        $this->defaults->check(null);
        $this->addToAssertionCount(1);
    }

    public function testSet_StoresTheLocationOfAnEmployeeAndAuditsTheChange(): void
    {
        $employee = $this->createEmployee();
        $location = $this->location();

        $this->defaults->set($employee, $location, $this->editor());

        $this->assertSame($location, $this->defaults->of($employee));
        $this->assertSame(1, $this->roleDb()->table('accounting_audit_log')->where(['entity_type' => 'employee', 'entity_id' => $employee, 'action' => 'default_location_set'])->countAllResults());
    }

    public function testSet_ReplacesThePreviousDefaultOfTheSameEmployee(): void
    {
        $employee = $this->createEmployee();
        $first    = $this->location('First');
        $second   = $this->location('Second');

        $this->defaults->set($employee, $first, null);
        $this->defaults->set($employee, $second, null);

        $this->assertSame($second, $this->defaults->of($employee));
        $this->assertSame(1, $this->roleDb()->table('employee_default_locations')->where('person_id', $employee)->countAllResults(), 'An employee has one default at most');
    }

    public function testSet_RemovesTheDefaultWhenNoLocationIsGiven(): void
    {
        $employee = $this->createEmployee();

        $this->defaults->set($employee, $this->location(), null);
        $this->defaults->set($employee, null, null);

        $this->assertNull($this->defaults->of($employee));
    }

    public function testSet_WritesNoEntryWhenTheLocationStaysTheSame(): void
    {
        $employee = $this->createEmployee();
        $location = $this->location();

        $this->defaults->set($employee, $location, null);
        $this->defaults->set($employee, $location, null);

        $this->assertSame(1, $this->roleDb()->table('accounting_audit_log')->where(['entity_type' => 'employee', 'entity_id' => $employee, 'action' => 'default_location_set'])->countAllResults());
    }

    public function testSet_RejectsALocationThatDoesNotExistOrWasDeleted(): void
    {
        $employee = $this->createEmployee();
        $deleted  = $this->location('Gone');

        $this->roleDb()->table('stock_locations')->where('location_id', $deleted)->update(['deleted' => 1]);

        $this->assertSame(RoleError::LocationNotFound, $this->refusal(fn () => $this->defaults->set($employee, 999999, null))->error);
        $this->assertSame(RoleError::LocationNotFound, $this->refusal(fn () => $this->defaults->set($employee, $deleted, null))->error);
        $this->assertNull($this->defaults->of($employee));
    }

    public function testSet_RejectsAnEmployeeThatDoesNotExistOrWasDeleted(): void
    {
        $deleted = $this->createEmployee();

        $this->roleDb()->table('employees')->where('person_id', $deleted)->update(['deleted' => 1]);

        $this->assertSame(RoleError::EmployeeNotFound, $this->refusal(fn () => $this->defaults->set(999999, $this->location(), null))->error);
        $this->assertSame(RoleError::EmployeeNotFound, $this->refusal(fn () => $this->defaults->set($deleted, $this->location(), null))->error);
    }

    public function testSet_RequiresThePermissionToChangeEmployees(): void
    {
        $employee = $this->createEmployee();
        $plain    = $this->createEmployee();

        $this->assertSame(RoleError::PermissionDenied, $this->refusal(fn () => $this->defaults->set($employee, $this->location(), $plain))->error);
        $this->assertNull($this->defaults->of($employee));
    }

    public function testOf_IgnoresALocationThatWasDeletedAfterwards(): void
    {
        $employee = $this->createEmployee();
        $location = $this->location();

        $this->defaults->set($employee, $location, null);
        $this->roleDb()->table('stock_locations')->where('location_id', $location)->update(['deleted' => 1]);

        $this->assertNull($this->defaults->of($employee), 'An employee whose location was deleted starts where they always did');
    }

    private function failingConnection(?string $table = null, bool $throws = false): FailingTableConnection
    {
        $db               = new FailingTableConnection(config('Database')->tests);
        $db->failingTable = $table;
        $db->throws       = $throws;

        return $db;
    }

    private function auditedChanges(int $employee): int
    {
        return $this->roleDb()->table('accounting_audit_log')->where(['entity_type' => 'employee', 'entity_id' => $employee, 'action' => 'default_location_set'])->countAllResults();
    }

    public function testOf_RefusesWhenTheDefaultLocationCannotBeRead(): void
    {
        $employee = $this->createEmployee();

        $this->defaults->set($employee, $this->location(), null);

        foreach ([false, true] as $throws) {
            foreach (['employee_default_locations', 'stock_locations'] as $table) {
                try {
                    (new DefaultLocations($this->failingConnection($table, $throws)))->of($employee);
                    $this->fail("A default location that could not be read ({$table}) was taken for none.");
                } catch (RoleTablesUnavailableException $exception) {
                    $this->assertStringContainsString("The default location of the employee {$employee} could not be read: ", $exception->getMessage());
                    $this->assertSame($throws, $exception->getPrevious() instanceof DatabaseException);
                }
            }
        }
    }

    public function testOf_ReadsThroughAConnectionThatDoesNotFail(): void
    {
        $employee = $this->createEmployee();
        $location = $this->location();

        $this->defaults->set($employee, $location, null);

        $this->assertSame($location, (new DefaultLocations($this->failingConnection()))->of($employee));
    }

    public function testOf_RefusesOnAProductionConnectionWhoseTableCannotBeRead(): void
    {
        $employee = $this->createEmployee();

        $this->defaults->set($employee, $this->location(), null);

        $this->withUnreadableTable('employee_default_locations', function () use ($employee): void {
            try {
                (new DefaultLocations())->of($employee);
                $this->fail('A default location that could not be read was taken for none.');
            } catch (RoleTablesUnavailableException $exception) {
                $this->assertNull($exception->getPrevious());
            }
        });
    }

    public function testSet_DoesNotSeemToClearALocationThatCouldNotBeRead(): void
    {
        $employee = $this->createEmployee();
        $location = $this->location();

        $this->defaults->set($employee, $location, null);

        foreach ([false, true] as $throws) {
            try {
                (new DefaultLocations($this->failingConnection('employee_default_locations', $throws)))->set($employee, null, null);
                $this->fail('Clearing a location that could not be read seemed to succeed.');
            } catch (RoleTablesUnavailableException) {
                $this->assertSame($location, $this->defaults->of($employee));
            }
        }

        $this->assertSame(1, $this->auditedChanges($employee));
    }
}
