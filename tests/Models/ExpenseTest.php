<?php

namespace Tests\Models;

use App\Libraries\Numbering\DocumentNumbering;
use App\Libraries\Numbering\DocumentType;
use App\Models\Expense;
use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\DatabaseTestTrait;
use Config\Database;
use Tests\Support\AccountingFixtureTrait;
use Tests\Support\EmployeeFixtureTrait;

class ExpenseTest extends CIUnitTestCase
{
    use DatabaseTestTrait;
    use AccountingFixtureTrait;
    use EmployeeFixtureTrait;

    protected $migrate     = true;
    protected $migrateOnce = true;
    protected $refresh     = false;
    protected $namespace   = null;

    private int $categoryId;
    private int $employeeId;

    /** @var list<int> */
    private array $expenseIds = [];

    public static function setUpBeforeClass(): void
    {
        $seeder = Database::seeder('tests');
        $seeder->call('TestDatabaseBootstrapSeeder');
    }

    protected function setUp(): void
    {
        parent::setUp();

        $this->releaseOpenTransactions();
        $db = $this->accountingDb();
        $db->table('expense_categories')->insert(['category_name' => 'Expense test ' . uniqid(), 'category_description' => '', 'deleted' => 0]);

        $this->categoryId = (int) $db->insertID();
        $this->employeeId = $this->createEmployee();
    }

    protected function tearDown(): void
    {
        $db = $this->accountingDb();
        $db->table('document_numbers')->truncate();
        $db->table('document_sequences')->truncate();
        $db->table('accounting_audit_log')->where('entity_type', 'document_numbering')->delete();

        if ($this->expenseIds !== []) {
            $db->table('expenses')->whereIn('expense_id', $this->expenseIds)->delete();
        }

        $db->table('expense_categories')->where('expense_category_id', $this->categoryId)->delete();

        parent::tearDown();
    }

    /**
     * @return array<string, mixed>
     */
    private function expenseData(string $amount = '25.00'): array
    {
        return [
            'date'                => date('Y-m-d H:i:s'),
            'amount'              => $amount,
            'tax_amount'          => '0.00',
            'payment_type'        => 'Cash',
            'expense_category_id' => $this->categoryId,
            'description'         => 'Expense test',
            'employee_id'         => $this->employeeId,
            'deleted'             => 0,
        ];
    }

    private function saveExpense(string $amount = '25.00', int $expenseId = NEW_ENTRY): int
    {
        $data = $this->expenseData($amount);

        $this->assertTrue(model(Expense::class)->save_value($data, $expenseId));

        $id = (int) ($data['expense_id'] ?? $expenseId);

        if (! in_array($id, $this->expenseIds, true)) {
            $this->expenseIds[] = $id;
        }

        return $id;
    }

    private function numberOf(int $expenseId): ?string
    {
        return (new DocumentNumbering())->numbers(DocumentType::Expense, 'expenses', [$expenseId])[$expenseId] ?? null;
    }

    public function testSaveValue_GivesNoNumberWhileTheSequenceIsOff(): void
    {
        $expenseId = $this->saveExpense();

        $this->assertNull($this->numberOf($expenseId));
        $this->assertSame(0, $this->countRows('document_numbers'));
    }

    public function testSaveValue_HandsTheIdOfTheNewExpenseBack(): void
    {
        $data = $this->expenseData();

        $this->assertTrue(model(Expense::class)->save_value($data));

        $this->expenseIds[] = (int) $data['expense_id'];

        $this->assertGreaterThan(0, (int) $data['expense_id']);
    }

    public function testSaveValue_NumbersANewExpenseWhenTheSequenceIsOn(): void
    {
        (new DocumentNumbering())->configure(DocumentType::Expense, ['enabled' => true, 'prefix' => 'EXP-', 'padding' => 5]);

        $first  = $this->saveExpense();
        $second = $this->saveExpense('30.00');

        $this->assertSame('EXP-00001', $this->numberOf($first));
        $this->assertSame('EXP-00002', $this->numberOf($second));
    }

    public function testSaveValue_ContinuesFromTheExpensesThatAlreadyShowTheirId(): void
    {
        $before = $this->saveExpense();

        $sequence = (new DocumentNumbering())->configure(DocumentType::Expense, ['enabled' => true]);
        $after    = $this->saveExpense();

        $this->assertGreaterThan($before, $sequence->nextNumber);
        $this->assertSame((string) $sequence->nextNumber, $this->numberOf($after));
        $this->assertNull($this->numberOf($before));
    }

    public function testSaveValue_KeepsTheNumberWhenTheExpenseIsEdited(): void
    {
        (new DocumentNumbering())->configure(DocumentType::Expense, ['enabled' => true, 'prefix' => 'EXP-', 'padding' => 5]);
        $expenseId = $this->saveExpense();

        $this->saveExpense('99.00', $expenseId);

        $this->assertSame('EXP-00001', $this->numberOf($expenseId));
        $this->assertSame(2, (new DocumentNumbering())->sequence(DocumentType::Expense)->nextNumber);
    }

    public function testSaveValue_KeepsTheNumberOfADeletedExpenseAndNeverGivesItAgain(): void
    {
        (new DocumentNumbering())->configure(DocumentType::Expense, ['enabled' => true, 'prefix' => 'EXP-', 'padding' => 5]);
        $first = $this->saveExpense();

        $this->assertTrue(model(Expense::class)->delete_list([$first]));

        $second = $this->saveExpense();

        $this->assertSame('EXP-00001', $this->numberOf($first));
        $this->assertSame('EXP-00002', $this->numberOf($second));
    }

    public function testSaveValue_RefusesTheExpenseAndLeavesNothingWhenNoNumberCanBeGiven(): void
    {
        $db = $this->accountingDb();
        (new DocumentNumbering())->configure(DocumentType::Expense, ['enabled' => true, 'prefix' => 'EXP-']);
        $db->table('document_sequences')->where('doc_type', 'expense')->update(['next_number' => 4294967294]);
        $db->table('document_numbers')->insert(['company_id' => 1, 'doc_type' => 'expense', 'source_table' => 'expenses', 'source_id' => 999999999, 'sequence_number' => 4294967294, 'number' => 'EXP-4294967294']);

        $before = $this->countRows('expenses');
        $data   = $this->expenseData();

        $this->assertFalse(model(Expense::class)->save_value($data));
        $this->assertSame($before, $this->countRows('expenses'));
        $this->assertSame(1, $this->countRows('document_numbers'));
    }
}
