<?php

namespace Tests\Database;

use App\Database\Migrations\CustomerOpenings;
use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\DatabaseTestTrait;
use Config\Database;
use Tests\Support\AccountingFixtureTrait;
use Tests\Support\EmployeeFixtureTrait;
use Tests\Support\ItemFixtureTrait;
use Tests\Support\SaleAccountingFixtureTrait;

class CustomerOpeningsMigrationTest extends CIUnitTestCase
{
    use DatabaseTestTrait;
    use AccountingFixtureTrait;
    use EmployeeFixtureTrait;
    use ItemFixtureTrait;
    use SaleAccountingFixtureTrait;

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
        (new CustomerOpenings())->up();
        $this->stopSelling();

        parent::tearDown();
    }

    private function hasTable(): bool
    {
        return $this->accountingDb()->query(
            'SELECT COUNT(*) AS found FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ?',
            [$this->accountingDb()->prefixTable('customer_openings')],
        )->getRow()->found > 0;
    }

    private function hasColumn(): bool
    {
        return $this->accountingDb()->query(
            'SELECT COUNT(*) AS found FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ?',
            [$this->accountingDb()->prefixTable('customer_credit_applications'), 'opening_id'],
        )->getRow()->found > 0;
    }

    private function invoiceIsRequired(): bool
    {
        return $this->accountingDb()->query(
            'SELECT IS_NULLABLE FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ?',
            [$this->accountingDb()->prefixTable('customer_credit_applications'), 'invoice_sale_id'],
        )->getRow()->IS_NULLABLE === 'NO';
    }

    private function hasConstraint(string $table, string $constraint, string $type): bool
    {
        return $this->accountingDb()->query(
            'SELECT COUNT(*) AS found FROM information_schema.TABLE_CONSTRAINTS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND CONSTRAINT_NAME = ? AND CONSTRAINT_TYPE = ?',
            [$this->accountingDb()->prefixTable($table), $this->accountingDb()->prefixTable($constraint), $type],
        )->getRow()->found > 0;
    }

    public function testUp_CreatesTheTableOfOpeningBalancesAndLetsAnApplicationPointAtOne(): void
    {
        $this->assertTrue($this->hasTable());
        $this->assertTrue($this->hasColumn());
        $this->assertFalse($this->invoiceIsRequired());
        $this->assertTrue($this->hasConstraint('customer_credit_applications', 'customer_credit_applications_fk_opening', 'FOREIGN KEY'));
        $this->assertTrue($this->hasConstraint('customer_credit_applications', 'customer_credit_applications_target', 'CHECK'));
        $this->assertTrue($this->hasConstraint('customer_openings', 'customer_openings_standing', 'UNIQUE'));
        $this->assertTrue($this->hasConstraint('customer_openings', 'customer_openings_revision', 'UNIQUE'));
    }

    public function testUp_IsSafeToRunAgain(): void
    {
        (new CustomerOpenings())->up();
        (new CustomerOpenings())->up();

        $this->assertTrue($this->hasTable());
        $this->assertTrue($this->hasColumn());
        $this->assertFalse($this->invoiceIsRequired());
        $this->assertTrue($this->hasConstraint('customer_credit_applications', 'customer_credit_applications_target', 'CHECK'));
    }

    public function testUp_FinishesWhatWasStoppedHalfWay(): void
    {
        $applications = $this->accountingDb()->prefixTable('customer_credit_applications');

        $this->accountingDb()->query("ALTER TABLE `{$applications}` DROP CONSTRAINT `{$applications}_target`");
        $this->accountingDb()->query("ALTER TABLE `{$applications}` DROP FOREIGN KEY `{$applications}_fk_opening`");

        (new CustomerOpenings())->up();

        $this->assertTrue($this->hasConstraint('customer_credit_applications', 'customer_credit_applications_fk_opening', 'FOREIGN KEY'));
        $this->assertTrue($this->hasConstraint('customer_credit_applications', 'customer_credit_applications_target', 'CHECK'));

        $this->accountingDb()->query("ALTER TABLE `{$applications}` MODIFY COLUMN `invoice_sale_id` INT(10) NOT NULL");

        (new CustomerOpenings())->up();

        $this->assertFalse($this->invoiceIsRequired());
    }

    public function testUp_LeavesAnApplicationThatExistsOnItsInvoice(): void
    {
        $this->startSelling();
        $migration = new CustomerOpenings();
        $invoice   = $this->invoiceOf('100.00');

        $migration->down();

        $this->assertFalse($this->hasColumn(), 'The state before the migration');

        $this->accountingDb()->table('customer_credit_applications')->insert(['customer_id' => $this->customerId, 'invoice_sale_id' => $invoice, 'source_type' => 'credit_note', 'source_id' => 1, 'amount' => '5.0000']);

        $migration->up();

        $row = $this->accountingDb()->table('customer_credit_applications')->get()->getRow();

        $this->assertSame([$invoice, null], [(int) $row->invoice_sale_id, $row->opening_id]);
    }

    public function testDown_RemovesTheTableAndTheTargetAndMakesTheInvoiceRequiredAgain(): void
    {
        (new CustomerOpenings())->down();

        $this->assertFalse($this->hasTable());
        $this->assertFalse($this->hasColumn());
        $this->assertTrue($this->invoiceIsRequired());

        (new CustomerOpenings())->down();

        $this->assertFalse($this->hasTable(), 'Taking it down again changes nothing');
    }

    public function testDown_DropsWhatWasSpentOnAnOpeningBalanceBeforeMakingTheInvoiceRequired(): void
    {
        $this->startSelling();
        $invoice = $this->invoiceOf('100.00');

        $this->accountingDb()->table('customer_openings')->insert(['company_id' => 1, 'branch_id' => 1, 'customer_id' => $this->customerId, 'revision' => 1, 'side' => 'owes_us', 'amount' => '10.0000', 'opening_date' => date('Y-m-d')]);
        $opening = (int) $this->accountingDb()->insertID();

        $this->accountingDb()->table('customer_credit_applications')->insertBatch([
            ['customer_id' => $this->customerId, 'invoice_sale_id' => $invoice, 'opening_id' => null, 'source_type' => 'credit_note', 'source_id' => 1, 'amount' => '5.0000'],
            ['customer_id' => $this->customerId, 'invoice_sale_id' => null, 'opening_id' => $opening, 'source_type' => 'credit_note', 'source_id' => 1, 'amount' => '3.0000'],
        ]);

        (new CustomerOpenings())->down();

        $rows = $this->accountingDb()->table('customer_credit_applications')->get()->getResult();

        $this->assertCount(1, $rows, 'What was spent on an opening balance goes with it; what was spent on an invoice stays');
        $this->assertSame($invoice, (int) $rows[0]->invoice_sale_id);
        $this->assertTrue($this->invoiceIsRequired());
    }
}
