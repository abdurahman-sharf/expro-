<?php

namespace Tests\Database;

use App\Database\Migrations\InvoiceDatesAndPaymentTerms;
use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\DatabaseTestTrait;
use Config\Database;
use RuntimeException;
use Tests\Support\AccountingFixtureTrait;
use Tests\Support\EmployeeFixtureTrait;

class InvoiceDatesAndPaymentTermsMigrationTest extends CIUnitTestCase
{
    use DatabaseTestTrait;
    use AccountingFixtureTrait;
    use EmployeeFixtureTrait;

    private const COLUMNS = ['invoice_date', 'payment_terms_days', 'due_date'];

    protected $migrate     = true;
    protected $migrateOnce = true;
    protected $refresh     = false;
    protected $namespace   = null;

    /** @var list<int> */
    private array $saleIds = [];

    /** @var list<int> */
    private array $receivingIds = [];

    public static function setUpBeforeClass(): void
    {
        $seeder = Database::seeder('tests');
        $seeder->call('TestDatabaseBootstrapSeeder');
    }

    protected function tearDown(): void
    {
        $db = $this->accountingDb();

        $db->table('sales')->whereIn('sale_id', $this->saleIds ?: [0])->delete();
        $db->table('receivings')->whereIn('receiving_id', $this->receivingIds ?: [0])->delete();

        $this->saleIds      = [];
        $this->receivingIds = [];

        (new InvoiceDatesAndPaymentTerms())->up();

        parent::tearDown();
    }

    /**
     * @return array{type: string, nullable: string, default: ?string}|null
     */
    private function column(string $table, string $column): ?array
    {
        $row = $this->accountingDb()->query(
            'SELECT COLUMN_TYPE AS type, IS_NULLABLE AS nullable, COLUMN_DEFAULT AS dflt FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ?',
            [$this->accountingDb()->prefixTable($table), $column],
        )->getRow();

        return $row === null ? null : ['type' => strtolower((string) $row->type), 'nullable' => (string) $row->nullable, 'default' => in_array($row->dflt, [null, 'NULL'], true) ? null : $row->dflt];
    }

    private function hasAllColumns(): bool
    {
        foreach (['sales', 'receivings'] as $table) {
            foreach (self::COLUMNS as $column) {
                if ($this->column($table, $column) === null) {
                    return false;
                }
            }
        }

        return true;
    }

    private function hasNoColumn(): bool
    {
        foreach (['sales', 'receivings'] as $table) {
            foreach (self::COLUMNS as $column) {
                if ($this->column($table, $column) !== null) {
                    return false;
                }
            }
        }

        return true;
    }

    private function oldSale(): int
    {
        $this->accountingDb()->table('sales')->insert(['sale_time' => '2026-10-21 10:00:00', 'customer_id' => null, 'employee_id' => $this->createEmployee(), 'comment' => 'before the typed date existed', 'invoice_number' => null]);

        return $this->saleIds[] = (int) $this->accountingDb()->insertID();
    }

    private function oldPurchase(): int
    {
        $this->accountingDb()->table('receivings')->insert(['receiving_time' => '2026-10-21 10:00:00', 'supplier_id' => null, 'employee_id' => 1, 'comment' => 'migration', 'reference' => 'M-' . uniqid(), 'payment_type' => null]);

        return $this->receivingIds[] = (int) $this->accountingDb()->insertID();
    }

    public function testUp_AddsTheTypedDayTheTermsAndTheDueDateAsColumnsThatMayBeEmptyToSalesAndPurchases(): void
    {
        foreach (['sales', 'receivings'] as $table) {
            $this->assertSame(['type' => 'date', 'nullable' => 'YES', 'default' => null], $this->column($table, 'invoice_date'), $table);
            $this->assertSame(['type' => 'smallint(5) unsigned', 'nullable' => 'YES', 'default' => null], $this->column($table, 'payment_terms_days'), $table);
            $this->assertSame(['type' => 'date', 'nullable' => 'YES', 'default' => null], $this->column($table, 'due_date'), $table);
        }
    }

    public function testUp_IsSafeToRunAgain(): void
    {
        (new InvoiceDatesAndPaymentTerms())->up();
        (new InvoiceDatesAndPaymentTerms())->up();

        $this->assertTrue($this->hasAllColumns());
    }

    public function testUp_FinishesWhatWasStoppedHalfWay(): void
    {
        $receivings = $this->accountingDb()->prefixTable('receivings');

        $this->accountingDb()->query("ALTER TABLE `{$receivings}` DROP COLUMN `due_date`");

        $this->assertNull($this->column('receivings', 'due_date'));

        (new InvoiceDatesAndPaymentTerms())->up();

        $this->assertNotNull($this->column('receivings', 'due_date'));
    }

    public function testUp_LeavesASaleAndAPurchaseThatExistExactlyAsTheyWereWithNothingTypedOnThem(): void
    {
        $migration = new InvoiceDatesAndPaymentTerms();

        $migration->down();

        $this->assertTrue($this->hasNoColumn(), 'The state before the migration');

        $saleId     = $this->oldSale();
        $purchaseId = $this->oldPurchase();
        $saleBefore = $this->accountingDb()->table('sales')->where('sale_id', $saleId)->get()->getRowArray();
        $buyBefore  = $this->accountingDb()->table('receivings')->where('receiving_id', $purchaseId)->get()->getRowArray();

        $migration->up();

        $saleAfter = $this->accountingDb()->table('sales')->where('sale_id', $saleId)->get()->getRowArray();
        $buyAfter  = $this->accountingDb()->table('receivings')->where('receiving_id', $purchaseId)->get()->getRowArray();

        foreach (self::COLUMNS as $column) {
            $this->assertNull($saleAfter[$column], 'sales.' . $column);
            $this->assertNull($buyAfter[$column], 'receivings.' . $column);
        }

        $this->assertSame($saleBefore, array_diff_key($saleAfter, array_flip(self::COLUMNS)));
        $this->assertSame($buyBefore, array_diff_key($buyAfter, array_flip(self::COLUMNS)));
    }

    public function testDown_RemovesTheColumnsWhenNoDocumentCarriesATypedDayAndTakingItDownAgainChangesNothing(): void
    {
        $this->oldSale();
        $this->accountingDb()->table('sales')->where('sale_id', $this->saleIds[0])->update(['payment_terms_days' => 30, 'due_date' => '2026-11-20']);

        (new InvoiceDatesAndPaymentTerms())->down();

        $this->assertTrue($this->hasNoColumn());

        (new InvoiceDatesAndPaymentTerms())->down();

        $this->assertTrue($this->hasNoColumn());
    }

    public function testDown_RefusesWhileASaleCarriesATypedDayBecauseItsJournalIsDatedByIt(): void
    {
        $saleId = $this->oldSale();

        $this->accountingDb()->table('sales')->where('sale_id', $saleId)->update(['invoice_date' => '2026-10-15']);

        $this->expectException(RuntimeException::class);

        try {
            (new InvoiceDatesAndPaymentTerms())->down();
        } finally {
            $this->assertTrue($this->hasAllColumns());
        }
    }

    public function testDown_RefusesWhileAPurchaseCarriesATypedDay(): void
    {
        $purchaseId = $this->oldPurchase();

        $this->accountingDb()->table('receivings')->where('receiving_id', $purchaseId)->update(['invoice_date' => '2026-10-15']);

        $this->expectException(RuntimeException::class);

        try {
            (new InvoiceDatesAndPaymentTerms())->down();
        } finally {
            $this->assertTrue($this->hasAllColumns());
        }
    }
}
