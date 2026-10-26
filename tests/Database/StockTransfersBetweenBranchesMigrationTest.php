<?php

namespace Tests\Database;

use App\Database\Migrations\StockTransfersBetweenBranches;
use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\DatabaseTestTrait;
use Config\Database;
use Tests\Support\AccountingFixtureTrait;

class StockTransfersBetweenBranchesMigrationTest extends CIUnitTestCase
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

    protected function tearDown(): void
    {
        (new StockTransfersBetweenBranches())->up();

        parent::tearDown();
    }

    private function hasColumn(): bool
    {
        return $this->accountingDb()->query(
            'SELECT COUNT(*) AS found FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ?',
            [$this->accountingDb()->prefixTable('receivings'), 'to_branch_id'],
        )->getRow()->found > 0;
    }

    private function hasIndex(): bool
    {
        return $this->accountingDb()->query(
            'SELECT COUNT(*) AS found FROM information_schema.STATISTICS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND INDEX_NAME = ?',
            [$this->accountingDb()->prefixTable('receivings'), $this->accountingDb()->prefixTable('receivings_to_branch')],
        )->getRow()->found > 0;
    }

    private function hasForeignKey(): bool
    {
        return $this->accountingDb()->query(
            'SELECT COUNT(*) AS found FROM information_schema.TABLE_CONSTRAINTS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND CONSTRAINT_NAME = ? AND CONSTRAINT_TYPE = ?',
            [$this->accountingDb()->prefixTable('receivings'), $this->accountingDb()->prefixTable('receivings_fk_to_branch'), 'FOREIGN KEY'],
        )->getRow()->found > 0;
    }

    public function testUp_AddsTheBranchTheStockGoesToWithItsIndexAndItsKey(): void
    {
        $this->assertTrue($this->hasColumn());
        $this->assertTrue($this->hasIndex());
        $this->assertTrue($this->hasForeignKey());
    }

    public function testUp_IsSafeToRunAgain(): void
    {
        (new StockTransfersBetweenBranches())->up();
        (new StockTransfersBetweenBranches())->up();

        $this->assertTrue($this->hasColumn());
        $this->assertTrue($this->hasIndex());
        $this->assertTrue($this->hasForeignKey());
    }

    public function testUp_FinishesWhatWasStoppedHalfWay(): void
    {
        $migration  = new StockTransfersBetweenBranches();
        $receivings = $this->accountingDb()->prefixTable('receivings');

        $this->accountingDb()->query("ALTER TABLE `{$receivings}` DROP FOREIGN KEY `{$receivings}_fk_to_branch`");

        $this->assertFalse($this->hasForeignKey());

        $migration->up();

        $this->assertTrue($this->hasForeignKey());

        $this->accountingDb()->query("ALTER TABLE `{$receivings}` DROP FOREIGN KEY `{$receivings}_fk_to_branch`");
        $this->accountingDb()->query("ALTER TABLE `{$receivings}` DROP KEY `{$receivings}_to_branch`");

        $migration->up();

        $this->assertTrue($this->hasIndex());
        $this->assertTrue($this->hasForeignKey());
    }

    public function testUp_LeavesAnExistingPurchaseWithNoBranchItGoesTo(): void
    {
        $this->accountingDb()->table('receivings')->insert(['receiving_time' => '2026-10-21 10:00:00', 'supplier_id' => null, 'employee_id' => 1, 'comment' => 'migration', 'reference' => 'M-1', 'payment_type' => null]);
        $id = (int) $this->accountingDb()->insertID();

        (new StockTransfersBetweenBranches())->up();

        $this->assertNull($this->accountingDb()->table('receivings')->where('receiving_id', $id)->get()->getRow()->to_branch_id);

        $this->accountingDb()->table('receivings')->where('receiving_id', $id)->delete();
    }

    public function testDown_RemovesTheColumnWithItsIndexAndItsKey(): void
    {
        (new StockTransfersBetweenBranches())->down();

        $this->assertFalse($this->hasColumn());
        $this->assertFalse($this->hasIndex());
        $this->assertFalse($this->hasForeignKey());

        (new StockTransfersBetweenBranches())->down();

        $this->assertFalse($this->hasColumn(), 'Taking it down again changes nothing');
    }
}
