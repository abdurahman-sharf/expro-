<?php

namespace Tests\Database;

use App\Database\Migrations\QuoteLifecycle;
use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\DatabaseTestTrait;
use Config\Database;
use RuntimeException;
use Tests\Support\AccountingFixtureTrait;
use Tests\Support\EmployeeFixtureTrait;

class QuoteLifecycleMigrationTest extends CIUnitTestCase
{
    use DatabaseTestTrait;
    use AccountingFixtureTrait;
    use EmployeeFixtureTrait;

    private const COLUMNS = ['quote_status', 'source_quote_id'];

    protected $migrate     = true;
    protected $migrateOnce = true;
    protected $refresh     = false;
    protected $namespace   = null;

    /** @var list<int> */
    private array $saleIds = [];

    public static function setUpBeforeClass(): void
    {
        $seeder = Database::seeder('tests');
        $seeder->call('TestDatabaseBootstrapSeeder');
    }

    protected function tearDown(): void
    {
        $this->accountingDb()->table('sales')->whereIn('sale_id', $this->saleIds ?: [0])->delete();
        $this->saleIds = [];

        (new QuoteLifecycle())->up();

        parent::tearDown();
    }

    /**
     * @return array{type: string, nullable: string, default: ?string}|null
     */
    private function column(string $column): ?array
    {
        $row = $this->accountingDb()->query(
            'SELECT COLUMN_TYPE AS type, IS_NULLABLE AS nullable, COLUMN_DEFAULT AS dflt FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ?',
            [$this->accountingDb()->prefixTable('sales'), $column],
        )->getRow();

        if ($row === null) {
            return null;
        }

        $type = (string) preg_replace('/^((?:tiny|small|medium|big)?int)\(\d+\)/', '$1', strtolower((string) $row->type));

        return ['type' => $type, 'nullable' => (string) $row->nullable, 'default' => in_array($row->dflt, [null, 'NULL'], true) ? null : $row->dflt];
    }

    private function hasIndex(): bool
    {
        return $this->accountingDb()->query(
            'SELECT 1 FROM information_schema.STATISTICS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND INDEX_NAME = ?',
            [$this->accountingDb()->prefixTable('sales'), $this->accountingDb()->prefixTable('sales') . '_source_quote'],
        )->getRow() !== null;
    }

    private function hasNoColumn(): bool
    {
        foreach (self::COLUMNS as $column) {
            if ($this->column($column) !== null) {
                return false;
            }
        }

        return true;
    }

    private function oldSale(): int
    {
        $this->accountingDb()->table('sales')->insert(['sale_time' => '2026-10-21 10:00:00', 'customer_id' => null, 'employee_id' => $this->createEmployee(), 'comment' => 'before the quote lifecycle existed', 'invoice_number' => null, 'sale_status' => SUSPENDED, 'sale_type' => SALE_TYPE_QUOTE, 'quote_number' => 'Q-' . uniqid()]);

        return $this->saleIds[] = (int) $this->accountingDb()->insertID();
    }

    public function testUp_AddsTheStateOfAQuoteAndTheQuoteAnInvoiceCameFromAsColumnsThatMayBeEmpty(): void
    {
        $this->assertSame(['type' => 'tinyint', 'nullable' => 'YES', 'default' => null], $this->column('quote_status'));
        $this->assertSame(['type' => 'int', 'nullable' => 'YES', 'default' => null], $this->column('source_quote_id'));
        $this->assertTrue($this->hasIndex());
    }

    public function testUp_IsSafeToRunAgain(): void
    {
        (new QuoteLifecycle())->up();
        (new QuoteLifecycle())->up();

        $this->assertNotNull($this->column('quote_status'));
        $this->assertNotNull($this->column('source_quote_id'));
        $this->assertTrue($this->hasIndex());
    }

    public function testUp_FinishesWhatWasStoppedHalfWay(): void
    {
        $sales = $this->accountingDb()->prefixTable('sales');

        $this->accountingDb()->query("ALTER TABLE `{$sales}` DROP KEY `{$sales}_source_quote`");
        $this->accountingDb()->query("ALTER TABLE `{$sales}` DROP COLUMN `source_quote_id`");

        $this->assertNull($this->column('source_quote_id'));

        (new QuoteLifecycle())->up();

        $this->assertNotNull($this->column('source_quote_id'));
        $this->assertTrue($this->hasIndex());
    }

    public function testUp_LeavesAQuoteThatExistsExactlyAsItWasWithNoState(): void
    {
        $migration = new QuoteLifecycle();

        $migration->down();

        $this->assertTrue($this->hasNoColumn(), 'The state before the migration');

        $saleId = $this->oldSale();
        $before = $this->accountingDb()->table('sales')->where('sale_id', $saleId)->get()->getRowArray();

        $migration->up();

        $after = $this->accountingDb()->table('sales')->where('sale_id', $saleId)->get()->getRowArray();

        foreach (self::COLUMNS as $column) {
            $this->assertNull($after[$column], 'sales.' . $column);
        }

        $this->assertSame($before, array_diff_key($after, array_flip(self::COLUMNS)));
    }

    public function testDown_RemovesTheColumnsAndTheIndexWhenNothingCarriesAStateAndTakingItDownAgainChangesNothing(): void
    {
        (new QuoteLifecycle())->down();

        $this->assertTrue($this->hasNoColumn());
        $this->assertFalse($this->hasIndex());

        (new QuoteLifecycle())->down();

        $this->assertTrue($this->hasNoColumn());
    }

    public function testDown_RefusesWhileAQuoteCarriesAState(): void
    {
        $saleId = $this->oldSale();

        $this->accountingDb()->table('sales')->where('sale_id', $saleId)->update(['quote_status' => 4]);

        $this->expectException(RuntimeException::class);

        try {
            (new QuoteLifecycle())->down();
        } finally {
            $this->assertNotNull($this->column('quote_status'));
        }
    }

    public function testDown_RefusesWhileAnInvoiceNamesTheQuoteItCameFrom(): void
    {
        $saleId = $this->oldSale();

        $this->accountingDb()->table('sales')->where('sale_id', $saleId)->update(['source_quote_id' => $saleId]);

        $this->expectException(RuntimeException::class);

        try {
            (new QuoteLifecycle())->down();
        } finally {
            $this->assertNotNull($this->column('source_quote_id'));
        }
    }
}
