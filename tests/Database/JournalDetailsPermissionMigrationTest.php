<?php

namespace Tests\Database;

use App\Database\Migrations\JournalDetailsPermission;
use App\Libraries\Accounting\AccountingGuard;
use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\DatabaseTestTrait;
use Config\Database;
use Tests\Support\AccountingFixtureTrait;

class JournalDetailsPermissionMigrationTest extends CIUnitTestCase
{
    use DatabaseTestTrait;
    use AccountingFixtureTrait;

    protected $migrate     = true;
    protected $migrateOnce = true;
    protected $refresh     = false;
    protected $namespace   = null;

    public static function setUpBeforeClass(): void
    {
        $seeder = Database::seeder('tests');
        $seeder->call('TestDatabaseBootstrapSeeder');
    }

    protected function setUp(): void
    {
        parent::setUp();

        $migration = new JournalDetailsPermission();
        $migration->down();
        $migration->up();
    }

    private function grantsOf(string $permissionId): int
    {
        return $this->accountingDb()->table('grants')->where('permission_id', $permissionId)->countAllResults();
    }

    public function testUp_RegistersThePermissionUnderTheJournalsModule(): void
    {
        $permission = $this->accountingDb()->table('permissions')->where('permission_id', AccountingGuard::JOURNALS_EDIT)->get()->getRow();

        $this->assertNotNull($permission);
        $this->assertSame('accounting_journals', $permission->module_id);
        $this->assertSame('acc_journals_edit_details', AccountingGuard::JOURNALS_EDIT);
    }

    public function testUp_GrantsNothingToAnyone(): void
    {
        $migration = new JournalDetailsPermission();
        $holder    = $this->employeeWithGrants([AccountingGuard::JOURNALS_EDIT, 'accounting_journals', AccountingGuard::JOURNALS_ALL, AccountingGuard::JOURNALS_POST]);

        try {
            $migration->down();
            $this->assertSame(0, $this->grantsOf(AccountingGuard::JOURNALS_EDIT));

            $migration->up();
            $this->assertSame(0, $this->grantsOf(AccountingGuard::JOURNALS_EDIT));
            $this->assertSame(1, $this->accountingDb()->table('grants')->where('person_id', $holder)->where('permission_id', AccountingGuard::JOURNALS_POST)->countAllResults());
        } finally {
            $migration->up();
        }
    }

    public function testUp_IsSafeToRunTwice(): void
    {
        $before = $this->countRows('permissions');

        (new JournalDetailsPermission())->up();

        $this->assertSame($before, $this->countRows('permissions'));
        $this->assertSame(1, $this->accountingDb()->table('permissions')->where('permission_id', AccountingGuard::JOURNALS_EDIT)->countAllResults());
    }

    public function testUp_KeepsThePermissionAwayFromTheModuleName(): void
    {
        $this->assertStringStartsNotWith('accounting_journals', AccountingGuard::JOURNALS_EDIT);
    }

    public function testDown_RemovesThePermissionAndItsGrantsAndUpBringsItBack(): void
    {
        $holder    = $this->employeeWithGrants([AccountingGuard::JOURNALS_EDIT, 'accounting_journals']);
        $migration = new JournalDetailsPermission();

        try {
            $migration->down();

            $this->assertSame(0, $this->accountingDb()->table('permissions')->where('permission_id', AccountingGuard::JOURNALS_EDIT)->countAllResults());
            $this->assertSame(0, $this->grantsOf(AccountingGuard::JOURNALS_EDIT));
            $this->assertSame(1, $this->accountingDb()->table('grants')->where('person_id', $holder)->where('permission_id', 'accounting_journals')->countAllResults());
        } finally {
            $migration->up();
        }

        $this->assertSame(1, $this->accountingDb()->table('permissions')->where('permission_id', AccountingGuard::JOURNALS_EDIT)->countAllResults());
    }
}
