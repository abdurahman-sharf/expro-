<?php

namespace Tests\Database;

use App\Database\Migrations\CrossBranchPayments;
use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\DatabaseTestTrait;
use Config\Database;
use Tests\Support\AccountingFixtureTrait;

class CrossBranchPaymentsMigrationTest extends CIUnitTestCase
{
    use DatabaseTestTrait;
    use AccountingFixtureTrait;

    protected $migrate     = true;
    protected $migrateOnce = true;
    protected $refresh     = false;
    protected $namespace   = null;

    private const TABLES = ['sales_payments', 'receivings_payments', 'expenses'];

    public static function setUpBeforeClass(): void
    {
        $seeder = Database::seeder('tests');
        $seeder->call('TestDatabaseBootstrapSeeder');
    }

    protected function tearDown(): void
    {
        (new CrossBranchPayments())->up();

        parent::tearDown();
    }

    private function hasColumn(string $table): bool
    {
        return $this->accountingDb()->query(
            'SELECT COUNT(*) AS found FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ?',
            [$this->accountingDb()->prefixTable($table), 'treasury_branch_id'],
        )->getRow()->found > 0;
    }

    private function hasIndex(string $table): bool
    {
        return $this->accountingDb()->query(
            'SELECT COUNT(*) AS found FROM information_schema.STATISTICS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND INDEX_NAME = ?',
            [$this->accountingDb()->prefixTable($table), $this->accountingDb()->prefixTable($table . '_treasury_branch')],
        )->getRow()->found > 0;
    }

    private function hasForeignKey(string $table): bool
    {
        return $this->accountingDb()->query(
            'SELECT COUNT(*) AS found FROM information_schema.TABLE_CONSTRAINTS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND CONSTRAINT_NAME = ? AND CONSTRAINT_TYPE = ?',
            [$this->accountingDb()->prefixTable($table), $this->accountingDb()->prefixTable($table . '_fk_treasury_branch'), 'FOREIGN KEY'],
        )->getRow()->found > 0;
    }

    public function testUp_AddsTheBranchOfTheTreasuryWithItsIndexAndItsKeyToTheTablesOfWhatWasPaid(): void
    {
        foreach (self::TABLES as $table) {
            $this->assertTrue($this->hasColumn($table), $table);
            $this->assertTrue($this->hasIndex($table), $table);
            $this->assertTrue($this->hasForeignKey($table), $table);
        }
    }

    public function testUp_IsSafeToRunAgain(): void
    {
        (new CrossBranchPayments())->up();
        (new CrossBranchPayments())->up();

        foreach (self::TABLES as $table) {
            $this->assertTrue($this->hasColumn($table) && $this->hasIndex($table) && $this->hasForeignKey($table), $table);
        }
    }

    public function testUp_FinishesWhatWasStoppedHalfWay(): void
    {
        $payments = $this->accountingDb()->prefixTable('sales_payments');

        $this->accountingDb()->query("ALTER TABLE `{$payments}` DROP FOREIGN KEY `{$payments}_fk_treasury_branch`");

        $this->assertFalse($this->hasForeignKey('sales_payments'));

        (new CrossBranchPayments())->up();

        $this->assertTrue($this->hasForeignKey('sales_payments'));

        $this->accountingDb()->query("ALTER TABLE `{$payments}` DROP FOREIGN KEY `{$payments}_fk_treasury_branch`");
        $this->accountingDb()->query("ALTER TABLE `{$payments}` DROP KEY `{$payments}_treasury_branch`");

        (new CrossBranchPayments())->up();

        $this->assertTrue($this->hasIndex('sales_payments'));
        $this->assertTrue($this->hasForeignKey('sales_payments'));
    }

    public function testUp_LeavesAPaymentThatExistsWithNoBranchOfATreasuryAndTouchesNoJournal(): void
    {
        $migration = new CrossBranchPayments();
        $journals  = $this->countRows('accounting_journals');

        $migration->down();

        $this->assertFalse($this->hasColumn('sales_payments'), 'The state before the migration');

        $this->accountingDb()->table('sales')->insert(['sale_time' => '2026-10-21 10:00:00', 'employee_id' => 1, 'comment' => 'migration', 'sale_status' => COMPLETED, 'sale_type' => SALE_TYPE_POS]);

        $saleId = (int) $this->accountingDb()->insertID();

        $this->accountingDb()->table('sales_payments')->insert(['sale_id' => $saleId, 'payment_type' => 'Cash', 'payment_amount' => '10.00', 'cash_refund' => '0', 'employee_id' => 1, 'reference_code' => '']);

        $paymentId = (int) $this->accountingDb()->insertID();

        $migration->up();

        $this->assertNull($this->accountingDb()->table('sales_payments')->where('payment_id', $paymentId)->get()->getRow()->treasury_branch_id);
        $this->assertSame($journals, $this->countRows('accounting_journals'));

        $this->accountingDb()->table('sales_payments')->where('payment_id', $paymentId)->delete();
        $this->accountingDb()->table('sales')->where('sale_id', $saleId)->delete();
    }

    public function testDown_RemovesTheColumnWithItsIndexAndItsKeyFromEachTable(): void
    {
        (new CrossBranchPayments())->down();

        foreach (self::TABLES as $table) {
            $this->assertFalse($this->hasColumn($table), $table);
            $this->assertFalse($this->hasIndex($table), $table);
            $this->assertFalse($this->hasForeignKey($table), $table);
        }
    }
}
