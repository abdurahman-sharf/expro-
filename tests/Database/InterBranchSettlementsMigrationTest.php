<?php

namespace Tests\Database;

use App\Database\Migrations\InterBranchSettlements;
use App\Libraries\Accounting\AccountingGuard;
use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\DatabaseTestTrait;
use Config\Database;
use Tests\Support\AccountingFixtureTrait;
use Throwable;

class InterBranchSettlementsMigrationTest extends CIUnitTestCase
{
    use DatabaseTestTrait;
    use AccountingFixtureTrait;

    protected $migrate     = true;
    protected $migrateOnce = true;
    protected $refresh     = false;
    protected $namespace   = null;

    private const TABLES = ['interbranch_settlements', 'accounting_interbranch_legs', 'interbranch_pair_locks'];

    public static function setUpBeforeClass(): void
    {
        $seeder = Database::seeder('tests');
        $seeder->call('TestDatabaseBootstrapSeeder');
    }

    protected function tearDown(): void
    {
        (new InterBranchSettlements())->up();

        parent::tearDown();
    }

    private function hasTable(string $table): bool
    {
        return $this->accountingDb()->query(
            'SELECT COUNT(*) AS found FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ?',
            [$this->accountingDb()->prefixTable($table)],
        )->getRow()->found > 0;
    }

    private function permission(): ?object
    {
        return $this->accountingDb()->table('permissions')->where('permission_id', AccountingGuard::INTERBRANCH_SETTLE)->get()->getRow();
    }

    public function testUp_CreatesTheTablesOfSettlementsOfTheRecordOfTheOtherBranchAndOfTheLocks(): void
    {
        foreach (self::TABLES as $table) {
            $this->assertTrue($this->hasTable($table), $table);
        }
    }

    public function testUp_RegistersThePermissionToSettleUnderTheTreasuriesScreenAndGrantsItToNobody(): void
    {
        $permission = $this->permission();

        $this->assertNotNull($permission);
        $this->assertSame('accounting_treasuries', $permission->module_id);
        $this->assertSame(0, $this->accountingDb()->table('grants')->where('permission_id', AccountingGuard::INTERBRANCH_SETTLE)->countAllResults());
    }

    public function testUp_IsSafeToRunAgain(): void
    {
        (new InterBranchSettlements())->up();
        (new InterBranchSettlements())->up();

        foreach (self::TABLES as $table) {
            $this->assertTrue($this->hasTable($table), $table);
        }

        $this->assertSame(1, $this->accountingDb()->table('permissions')->where('permission_id', AccountingGuard::INTERBRANCH_SETTLE)->countAllResults());
    }

    public function testUp_TouchesNoJournalAndLeavesTheTransfersThatExistWithNoRecordOfTheirOwn(): void
    {
        $journals  = $this->countRows('accounting_journals');
        $transfers = $this->countRows('treasury_transfers');

        (new InterBranchSettlements())->down();
        (new InterBranchSettlements())->up();

        $this->assertSame([$journals, $transfers], [$this->countRows('accounting_journals'), $this->countRows('treasury_transfers')]);
        $this->assertSame(0, $this->countRows('accounting_interbranch_legs'));
        $this->assertSame(0, $this->countRows('interbranch_settlements'));
    }

    public function testUp_ShutsOutTwoSettlementsWithTheSameKeyAndAPairInTheWrongOrder(): void
    {
        $db = $this->accountingDb();

        $db->table('interbranch_settlements')->truncate();

        $row = ['company_id' => 1, 'branch_id' => 1, 'counterpart_branch_id' => $this->createBranch('MG' . random_int(10, 99)), 'kind' => 'netting', 'settle_date' => '2026-10-31', 'request_key' => 'same'];

        $this->assertTrue($db->table('interbranch_settlements')->insert($row));

        $exception = null;

        try {
            $db->table('interbranch_settlements')->insert($row);
        } catch (Throwable $caught) {
            $exception = $caught;
        }

        $this->assertNotNull($exception, 'The same key twice');

        $exception = null;

        try {
            $db->table('interbranch_settlements')->insert(['branch_id' => (int) $row['counterpart_branch_id'], 'counterpart_branch_id' => 1] + $row + ['request_key' => null]);
        } catch (Throwable $caught) {
            $exception = $caught;
        }

        $this->assertNotNull($exception, 'A pair is kept with the lower branch first');

        $db->table('interbranch_settlements')->truncate();
    }

    public function testDown_RemovesTheTablesAndThePermission(): void
    {
        (new InterBranchSettlements())->down();

        foreach (self::TABLES as $table) {
            $this->assertFalse($this->hasTable($table), $table);
        }

        $this->assertNull($this->permission());
    }
}
