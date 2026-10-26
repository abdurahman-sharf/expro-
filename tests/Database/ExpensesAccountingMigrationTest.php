<?php

namespace Tests\Database;

use App\Database\Migrations\ExpensesAccounting;
use CodeIgniter\Database\Exceptions\DatabaseException;
use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\DatabaseTestTrait;
use Config\Database;
use Tests\Support\AccountingFixtureTrait;

/**
 * The migration only adds optional columns, their indexes and their keys to the expenses, the
 * cash-ups and the categories, leaves the rows that exist untouched and without a journal, and
 * can be run again.
 */
class ExpensesAccountingMigrationTest extends CIUnitTestCase
{
    use DatabaseTestTrait;
    use AccountingFixtureTrait;

    private const COLUMNS = [
        'expenses'           => ['branch_id', 'treasury_id'],
        'expense_categories' => ['account_id'],
        'cash_up'            => ['branch_id', 'treasury_id', 'expected_amount_cash', 'cash_difference'],
    ];

    private const KEYED = [
        'expenses'           => ['branch_id', 'treasury_id'],
        'expense_categories' => ['account_id'],
        'cash_up'            => ['branch_id', 'treasury_id'],
    ];

    protected $migrate     = true;
    protected $migrateOnce = true;
    protected $refresh     = false;
    protected $namespace   = null;

    /** @var list<int> */
    private array $categories = [];

    public static function setUpBeforeClass(): void
    {
        $seeder = Database::seeder('tests');
        $seeder->call('TestDatabaseBootstrapSeeder');
    }

    protected function tearDown(): void
    {
        $this->releaseOpenTransactions();

        $db = $this->accountingDb();
        $db->query('SET FOREIGN_KEY_CHECKS = 0');
        $db->table('expenses')->truncate();
        $db->table('cash_up')->truncate();

        if ($this->categories !== []) {
            $db->table('expense_categories')->whereIn('expense_category_id', $this->categories)->delete();
        }

        $db->query('SET FOREIGN_KEY_CHECKS = 1');
        $this->categories = [];

        (new ExpensesAccounting())->up();

        parent::tearDown();
    }

    private function hasColumn(string $table, string $column): bool
    {
        return $this->accountingDb()->query(
            'SELECT 1 FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ?',
            [$this->accountingDb()->prefixTable($table), $column],
        )->getRow() !== null;
    }

    private function isNullable(string $table, string $column): bool
    {
        return $this->accountingDb()->query(
            'SELECT IS_NULLABLE AS nullable FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ?',
            [$this->accountingDb()->prefixTable($table), $column],
        )->getRow()->nullable === 'YES';
    }

    private function hasIndex(string $table, string $column): bool
    {
        $prefixed = $this->accountingDb()->prefixTable($table);

        return $this->accountingDb()->query(
            'SELECT 1 FROM information_schema.STATISTICS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND INDEX_NAME = ?',
            [$prefixed, "{$prefixed}_{$column}"],
        )->getRow() !== null;
    }

    private function hasForeignKey(string $table, string $column): bool
    {
        $prefixed = $this->accountingDb()->prefixTable($table);

        return $this->accountingDb()->query(
            'SELECT 1 FROM information_schema.TABLE_CONSTRAINTS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND CONSTRAINT_NAME = ? AND CONSTRAINT_TYPE = ?',
            [$prefixed, "{$prefixed}_fk_{$column}", 'FOREIGN KEY'],
        )->getRow() !== null;
    }

    /**
     * @return array{int, int} The expense and the cash-up written the way they were before the migration.
     */
    private function legacyRows(): array
    {
        $db = $this->accountingDb();

        $db->table('expense_categories')->insert(['category_name' => 'Legacy ' . uniqid(), 'category_description' => '', 'deleted' => 0]);

        $category           = (int) $db->insertID();
        $this->categories[] = $category;
        $employee           = $this->employeeWithGrants([]);

        $db->table('expenses')->insert([
            'date'                => '2026-10-20 10:00:00',
            'supplier_id'         => null,
            'supplier_tax_code'   => '',
            'amount'              => '230.00',
            'tax_amount'          => '30.00',
            'payment_type'        => 'Cash',
            'expense_category_id' => $category,
            'description'         => 'Legacy expense',
            'employee_id'         => $employee,
            'deleted'             => 0,
        ]);

        $expense = (int) $db->insertID();

        $db->table('cash_up')->insert([
            'open_date'            => '2026-10-20 08:00:00',
            'close_date'           => '2026-10-20 18:00:00',
            'open_amount_cash'     => '100',
            'transfer_amount_cash' => '0',
            'closed_amount_cash'   => '90',
            'closed_amount_due'    => '0',
            'closed_amount_card'   => '0',
            'closed_amount_check'  => '0',
            'closed_amount_total'  => '-10',
            'note'                 => 0,
            'description'          => 'Legacy cash-up',
            'open_employee_id'     => $employee,
            'close_employee_id'    => $employee,
            'deleted'              => 0,
        ]);

        return [$expense, (int) $db->insertID()];
    }

    /**
     * @return array<string, mixed>
     */
    private function expenseRow(int $expenseId): array
    {
        $row = $this->accountingDb()->table('expenses')->where('expense_id', $expenseId)->get()->getRowArray();

        unset($row['branch_id'], $row['treasury_id']);

        return $row;
    }

    /**
     * @return array<string, mixed>
     */
    private function cashupRow(int $cashupId): array
    {
        $row = $this->accountingDb()->table('cash_up')->where('cashup_id', $cashupId)->get()->getRowArray();

        unset($row['branch_id'], $row['treasury_id'], $row['expected_amount_cash'], $row['cash_difference']);

        return $row;
    }

    public function testUp_AddsEveryColumnAsOptionalWithoutADefaultValue(): void
    {
        foreach (self::COLUMNS as $table => $columns) {
            foreach ($columns as $column) {
                $this->assertTrue($this->hasColumn($table, $column), "{$table}.{$column} is missing");
                $this->assertTrue($this->isNullable($table, $column), "{$table}.{$column} must stay optional");
            }
        }
    }

    public function testUp_KeysTheBranchTheTreasuryAndTheAccountToTheirTables(): void
    {
        foreach (self::KEYED as $table => $columns) {
            foreach ($columns as $column) {
                $this->assertTrue($this->hasIndex($table, $column), "{$table}.{$column} has no index");
                $this->assertTrue($this->hasForeignKey($table, $column), "{$table}.{$column} has no key");
            }
        }
    }

    public function testUp_RefusesAnExpenseOfABranchThatDoesNotExist(): void
    {
        [$expense] = $this->legacyRows();

        $this->expectException(DatabaseException::class);

        $this->accountingDb()->table('expenses')->where('expense_id', $expense)->update(['branch_id' => 987654]);
    }

    public function testUp_RefusesACategoryOfAnAccountThatDoesNotExist(): void
    {
        $this->legacyRows();

        $this->expectException(DatabaseException::class);

        $this->accountingDb()->table('expense_categories')->where('expense_category_id', $this->categories[0])->update(['account_id' => 987654]);
    }

    public function testUp_LeavesTheRowsThatExistAsTheyAreWithoutBranchTreasuryOrJournal(): void
    {
        (new ExpensesAccounting())->down();

        [$expense, $cashup] = $this->legacyRows();

        $expenseBefore = $this->expenseRow($expense);
        $cashupBefore  = $this->cashupRow($cashup);
        $journals      = $this->countRows('accounting_journals');

        (new ExpensesAccounting())->up();

        $this->assertSame($expenseBefore, $this->expenseRow($expense));
        $this->assertSame($cashupBefore, $this->cashupRow($cashup));
        $this->assertSame($journals, $this->countRows('accounting_journals'));

        $expenseRow = $this->accountingDb()->table('expenses')->where('expense_id', $expense)->get()->getRow();
        $cashupRow  = $this->accountingDb()->table('cash_up')->where('cashup_id', $cashup)->get()->getRow();

        $this->assertSame([null, null], [$expenseRow->branch_id, $expenseRow->treasury_id]);
        $this->assertSame([null, null, null, null], [$cashupRow->branch_id, $cashupRow->treasury_id, $cashupRow->expected_amount_cash, $cashupRow->cash_difference]);
        $this->assertNull($this->accountingDb()->table('expense_categories')->where('expense_category_id', $this->categories[0])->get()->getRow()->account_id);
    }

    public function testUp_IsSafeToRunTwice(): void
    {
        [$expense, $cashup] = $this->legacyRows();

        $expenseBefore = $this->expenseRow($expense);
        $cashupBefore  = $this->cashupRow($cashup);

        (new ExpensesAccounting())->up();
        (new ExpensesAccounting())->up();

        $this->assertSame($expenseBefore, $this->expenseRow($expense));
        $this->assertSame($cashupBefore, $this->cashupRow($cashup));

        foreach (self::KEYED as $table => $columns) {
            foreach ($columns as $column) {
                $this->assertTrue($this->hasIndex($table, $column));
                $this->assertTrue($this->hasForeignKey($table, $column));
            }
        }
    }

    public function testUp_RestoresAKeyThatWasLostWithoutAddingTheColumnAgain(): void
    {
        $prefixed = $this->accountingDb()->prefixTable('expenses');

        $this->accountingDb()->query("ALTER TABLE `{$prefixed}` DROP FOREIGN KEY `{$prefixed}_fk_treasury_id`");
        $this->assertFalse($this->hasForeignKey('expenses', 'treasury_id'));

        (new ExpensesAccounting())->up();

        $this->assertTrue($this->hasForeignKey('expenses', 'treasury_id'));
    }

    public function testDown_TakesOutWhatUpAddedAndUpPutsItBack(): void
    {
        (new ExpensesAccounting())->down();

        foreach (self::COLUMNS as $table => $columns) {
            foreach ($columns as $column) {
                $this->assertFalse($this->hasColumn($table, $column), "{$table}.{$column} is still there");
            }
        }

        (new ExpensesAccounting())->down();
        (new ExpensesAccounting())->up();

        foreach (self::COLUMNS as $table => $columns) {
            foreach ($columns as $column) {
                $this->assertTrue($this->hasColumn($table, $column), "{$table}.{$column} did not come back");
            }
        }
    }
}
