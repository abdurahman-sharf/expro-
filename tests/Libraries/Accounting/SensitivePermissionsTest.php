<?php

namespace Tests\Libraries\Accounting;

use App\Libraries\Accounting\AccountingGuard;
use App\Libraries\Accounting\SensitivePermissions;
use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\DatabaseTestTrait;
use Config\Database;
use Tests\Support\AccountingFixtureTrait;

class SensitivePermissionsTest extends CIUnitTestCase
{
    use DatabaseTestTrait;
    use AccountingFixtureTrait;

    protected $migrate     = true;
    protected $migrateOnce = true;
    protected $refresh     = false;
    protected $namespace   = null;

    private SensitivePermissions $service;

    public static function setUpBeforeClass(): void
    {
        $seeder = Database::seeder('tests');
        $seeder->call('TestDatabaseBootstrapSeeder');
    }

    protected function setUp(): void
    {
        parent::setUp();

        $this->clearJournals();
        $this->service = new SensitivePermissions();
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function holdersById(): array
    {
        $byId = [];

        foreach ($this->service->holders() as $holder) {
            $byId[$holder['person_id']] = $holder;
        }

        return $byId;
    }

    public function testIsSensitive_NamesThePermissionsThatCannotBeUndoneByOrdinaryUsers(): void
    {
        $sensitive = [
            AccountingGuard::JOURNALS_EDIT,
            AccountingGuard::SETTINGS_MANAGE,
            AccountingGuard::PERIODS_CLOSE,
            AccountingGuard::PERIODS_REOPEN,
            AccountingGuard::OPENING_MANAGE,
            AccountingGuard::ROUTING_MANAGE,
            AccountingGuard::TREASURIES_MANAGE,
            AccountingGuard::PAYABLES_MANAGE,
            AccountingGuard::INTERBRANCH_SETTLE,
        ];

        foreach ($sensitive as $permissionId) {
            $this->assertTrue(SensitivePermissions::isSensitive($permissionId), $permissionId);
        }

        foreach ([AccountingGuard::PERIODS_MANAGE, AccountingGuard::JOURNALS_POST, AccountingGuard::JOURNALS_REVERSE, AccountingGuard::JOURNALS_DRAFT, 'accounting_periods', 'accounting_treasuries', 'accounting_payables'] as $permissionId) {
            $this->assertFalse(SensitivePermissions::isSensitive($permissionId), $permissionId);
        }
    }

    public function testIsSensitive_CoversEveryPermissionOfTheGuardThatMovesMoneyOrChangesTheBooksSetup(): void
    {
        $this->assertSame(
            [
                AccountingGuard::JOURNALS_EDIT,
                AccountingGuard::SETTINGS_MANAGE,
                AccountingGuard::PERIODS_CLOSE,
                AccountingGuard::PERIODS_REOPEN,
                AccountingGuard::OPENING_MANAGE,
                AccountingGuard::ROUTING_MANAGE,
                AccountingGuard::TREASURIES_MANAGE,
                AccountingGuard::PAYABLES_MANAGE,
                AccountingGuard::INTERBRANCH_SETTLE,
            ],
            AccountingGuard::SENSITIVE,
        );
    }

    public function testHeldBy_ListsOnlyTheSensitivePermissionsTheEmployeeHolds(): void
    {
        $employee = $this->employeeWithGrants([AccountingGuard::PERIODS_CLOSE, AccountingGuard::OPENING_MANAGE, AccountingGuard::JOURNALS_POST, 'accounting_periods']);

        $this->assertSame([AccountingGuard::PERIODS_CLOSE, AccountingGuard::OPENING_MANAGE], $this->service->heldBy($employee));
        $this->assertSame([], $this->service->heldBy($this->employeeWithGrants(['accounting_periods'])));
    }

    public function testHeldBy_ListsThePermissionToEditJournalTextAmongWhatAnEmployeeHolds(): void
    {
        $editor = $this->employeeWithGrants([AccountingGuard::JOURNALS_EDIT, AccountingGuard::JOURNALS_DRAFT]);

        $this->assertSame([AccountingGuard::JOURNALS_EDIT], $this->service->heldBy($editor));
        $this->assertSame([AccountingGuard::JOURNALS_EDIT], $this->holdersById()[$editor]['permissions']);
    }

    public function testHolders_ListsActiveEmployeesWithWhatTheyHoldAndWhereTheyMayWork(): void
    {
        $second   = $this->createBranch('B2');
        $everyone = $this->employeeWithGrants([AccountingGuard::SETTINGS_MANAGE, AccountingGuard::PERIODS_REOPEN]);
        $limited  = $this->employeeWithGrants([AccountingGuard::PERIODS_CLOSE], [$second]);
        $ordinary = $this->employeeWithGrants([AccountingGuard::JOURNALS_POST]);

        $holders = $this->holdersById();

        $this->assertSame([AccountingGuard::SETTINGS_MANAGE, AccountingGuard::PERIODS_REOPEN], $holders[$everyone]['permissions']);
        $this->assertTrue($holders[$everyone]['all_branches']);
        $this->assertSame([], $holders[$everyone]['branches']);

        $this->assertSame([AccountingGuard::PERIODS_CLOSE], $holders[$limited]['permissions']);
        $this->assertFalse($holders[$limited]['all_branches']);
        $this->assertSame(['B2'], $holders[$limited]['branches']);

        $this->assertArrayNotHasKey($ordinary, $holders);
    }

    public function testHolders_ListsWhoHoldsTheTreasuriesAndPayablesPermissions(): void
    {
        $manager = $this->employeeWithGrants([AccountingGuard::TREASURIES_MANAGE, AccountingGuard::PAYABLES_MANAGE]);

        $this->assertSame([AccountingGuard::TREASURIES_MANAGE, AccountingGuard::PAYABLES_MANAGE], $this->service->heldBy($manager));
        $this->assertSame([AccountingGuard::TREASURIES_MANAGE, AccountingGuard::PAYABLES_MANAGE], $this->holdersById()[$manager]['permissions']);
    }

    public function testHolders_LeavesOutDeletedEmployees(): void
    {
        $gone = $this->employeeWithGrants([AccountingGuard::PERIODS_CLOSE]);
        $this->accountingDb()->table('employees')->where('person_id', $gone)->update(['deleted' => 1]);

        $this->assertArrayNotHasKey($gone, $this->holdersById());
    }

    public function testHolders_DoesNotListTheAutomaticGrantsOfTheMigration(): void
    {
        $configHolder = $this->employeeWithGrants(['config', 'accounting_periods', 'accounting_settings', AccountingGuard::PERIODS_MANAGE]);

        $this->assertArrayNotHasKey($configHolder, $this->holdersById());
    }

    public function testRecordChange_WritesOneEntryForEachPermissionGivenOrTakenAway(): void
    {
        $target = $this->employeeWithGrants([]);

        $this->service->recordChange(1, $target, [AccountingGuard::PERIODS_CLOSE, AccountingGuard::OPENING_MANAGE], [AccountingGuard::OPENING_MANAGE, AccountingGuard::PERIODS_REOPEN]);

        $entries = $this->accountingDb()->table('accounting_audit_log')->where('entity_type', 'employee_permission')->where('entity_id', $target)->orderBy('audit_id')->get()->getResult();

        $this->assertCount(2, $entries);
        $this->assertSame('permission_granted', $entries[0]->action);
        $this->assertSame(AccountingGuard::PERIODS_REOPEN, json_decode($entries[0]->details, true)['permission']);
        $this->assertSame('permission_revoked', $entries[1]->action);
        $this->assertSame(AccountingGuard::PERIODS_CLOSE, json_decode($entries[1]->details, true)['permission']);
        $this->assertSame('1', (string) $entries[0]->employee_id);
        $this->assertSame('user', $entries[0]->origin);
    }

    public function testRecordChange_WritesNothingWhenNothingChanged(): void
    {
        $target = $this->employeeWithGrants([]);

        $this->service->recordChange(1, $target, [AccountingGuard::PERIODS_CLOSE], [AccountingGuard::PERIODS_CLOSE]);
        $this->service->recordChange(1, $target, [], []);

        $this->assertSame(0, $this->accountingDb()->table('accounting_audit_log')->where('entity_type', 'employee_permission')->countAllResults());
    }
}
