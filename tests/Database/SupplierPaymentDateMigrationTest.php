<?php

namespace Tests\Database;

use App\Database\Migrations\SupplierPaymentDate;
use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\DatabaseTestTrait;
use Config\Database;
use Tests\Support\AccountingFixtureTrait;

class SupplierPaymentDateMigrationTest extends CIUnitTestCase
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
        (new SupplierPaymentDate())->up();

        parent::tearDown();
    }

    private function column(): ?object
    {
        return $this->accountingDb()->query(
            'SELECT DATA_TYPE, IS_NULLABLE, COLUMN_DEFAULT FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ?',
            [$this->accountingDb()->prefixTable('receivings_payments'), 'payment_date'],
        )->getRow();
    }

    public function testUp_AddsADateThatMayBeEmptyNextToTheTimeThePaymentWasSaved(): void
    {
        $column = $this->column();

        $this->assertNotNull($column);
        $this->assertSame('date', $column->DATA_TYPE);
        $this->assertSame('YES', $column->IS_NULLABLE);
        $this->assertContains($column->COLUMN_DEFAULT, [null, 'NULL'], 'It has no default: a payment saved without a date has none');
    }

    public function testUp_IsSafeToRunAgain(): void
    {
        (new SupplierPaymentDate())->up();
        (new SupplierPaymentDate())->up();

        $this->assertNotNull($this->column());
    }

    public function testUp_LeavesAnExistingPaymentWithNoDateAndItsTimeAsItWas(): void
    {
        $migration = new SupplierPaymentDate();
        $db        = $this->accountingDb();

        $migration->down();

        $this->assertNull($this->column(), 'The state before the migration');

        $db->table('receivings')->insert(['receiving_time' => '2026-10-21 10:00:00', 'supplier_id' => null, 'employee_id' => 1, 'comment' => 'migration', 'reference' => 'M-1', 'payment_type' => null]);
        $receivingId = (int) $db->insertID();

        $db->table('receivings_payments')->insert(['receiving_id' => $receivingId, 'payment_type' => 'Cash', 'payment_amount' => '10.00', 'payment_time' => '2026-10-22 11:15:00']);
        $paymentId = (int) $db->insertID();

        $migration->up();

        $payment = $db->table('receivings_payments')->where('payment_id', $paymentId)->get()->getRow();

        $this->assertNull($payment->payment_date);
        $this->assertSame('2026-10-22 11:15:00', $payment->payment_time);

        $db->table('receivings_payments')->where('payment_id', $paymentId)->delete();
        $db->table('receivings')->where('receiving_id', $receivingId)->delete();
    }

    public function testDown_RemovesTheColumnAndTakingItDownAgainChangesNothing(): void
    {
        (new SupplierPaymentDate())->down();

        $this->assertNull($this->column());

        (new SupplierPaymentDate())->down();

        $this->assertNull($this->column());
    }
}
