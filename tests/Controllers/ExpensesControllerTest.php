<?php

namespace Tests\Controllers;

use App\Libraries\Numbering\DocumentNumbering;
use App\Libraries\Numbering\DocumentType;
use App\Models\Expense;
use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\DatabaseTestTrait;
use CodeIgniter\Test\FeatureTestTrait;
use Config\Database;
use Config\OSPOS;
use Tests\Support\AccountingFixtureTrait;
use Tests\Support\EmployeeFixtureTrait;

/**
 * The expense screens: the number an expense is given by the numbering shows in the list, in the
 * row that is refreshed after an edit and on the form, and nothing of it shows while the sequence
 * is off.
 */
class ExpensesControllerTest extends CIUnitTestCase
{
    use DatabaseTestTrait;
    use FeatureTestTrait;
    use AccountingFixtureTrait;
    use EmployeeFixtureTrait;

    protected $migrate     = true;
    protected $migrateOnce = true;
    protected $refresh     = false;
    protected $namespace   = null;

    private int $categoryId;

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
        $this->withSession(['person_id' => 1, 'menu_group' => 'office']);

        $db = $this->accountingDb();
        $db->table('expense_categories')->insert(['category_name' => 'Expenses screen ' . uniqid(), 'category_description' => '', 'deleted' => 0]);

        $this->categoryId = (int) $db->insertID();
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

    private function switchOn(): void
    {
        (new DocumentNumbering())->configure(DocumentType::Expense, ['enabled' => true, 'prefix' => 'EXP-', 'padding' => 5, 'next_number' => 1]);
    }

    private function expense(): int
    {
        $data = [
            'date'                => date('Y-m-d H:i:s'),
            'amount'              => '25.00',
            'tax_amount'          => '0.00',
            'payment_type'        => 'Cash',
            'expense_category_id' => $this->categoryId,
            'description'         => 'Expenses screen',
            'employee_id'         => 1,
            'deleted'             => 0,
        ];

        $this->assertTrue(model(Expense::class)->save_value($data));

        return $this->expenseIds[] = (int) $data['expense_id'];
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function rowsOfTheList(): array
    {
        $response = $this->get('/expenses/search', ['search' => '', 'limit' => '100', 'offset' => '0', 'order' => 'desc', 'start_date' => '2000-01-01', 'end_date' => '2100-01-01']);

        return json_decode($response->getJSON(), true)['rows'];
    }

    public function testGetIndex_AddsTheNumberColumnOnlyWhileTheSequenceIsOn(): void
    {
        $this->get('/expenses')->assertDontSee('expense_number');

        $this->switchOn();

        $this->get('/expenses')->assertSee('expense_number');
    }

    public function testGetSearch_ListsTheNumberOfEachExpenseThatHasOne(): void
    {
        $before = $this->expense();

        $this->switchOn();

        $after = $this->expense();

        $rows = array_column($this->rowsOfTheList(), null, 'expense_id');

        $this->assertSame('', $rows[$before]['expense_number']);
        $this->assertSame('EXP-00001', $rows[$after]['expense_number']);
    }

    public function testGetRow_ShowsTheNumberOfTheExpenseThatIsRefreshed(): void
    {
        $this->switchOn();
        $expenseId = $this->expense();

        $row = json_decode($this->get('/expenses/row/' . $expenseId)->getJSON(), true);

        $this->assertSame('EXP-00001', $row['expense_number']);
    }

    public function testGetView_ShowsTheNumberOnTheFormOfTheExpense(): void
    {
        $this->switchOn();
        $expenseId = $this->expense();

        $response = $this->get('/expenses/view/' . $expenseId);

        $response->assertSee(lang('Numbering.document_number'));
        $response->assertSee('EXP-00001');
    }

    public function testGetView_ShowsNoNumberWhileTheSequenceIsOff(): void
    {
        $expenseId = $this->expense();

        $this->get('/expenses/view/' . $expenseId)->assertDontSee(lang('Numbering.document_number'));
    }

    public function testPostSave_NumbersTheExpenseThatTheFormAdds(): void
    {
        $this->switchOn();
        $settings = config(OSPOS::class)->settings;

        $response = $this->post('/expenses/save/-1', [
            'date'                => date(($settings['dateformat'] ?? 'm/d/Y') . ' ' . ($settings['timeformat'] ?? 'H:i:s')),
            'amount'              => '12.00',
            'tax_amount'          => '0',
            'payment_type'        => 'Cash',
            'expense_category_id' => (string) $this->categoryId,
            'description'         => 'From the form',
            'employee_id'         => '1',
        ]);

        $result = json_decode($response->getJSON(), true);

        $this->assertTrue($result['success']);

        $this->expenseIds[] = (int) $result['id'];

        $this->assertSame('EXP-00001', (new DocumentNumbering())->numbers(DocumentType::Expense, 'expenses', [(int) $result['id']])[(int) $result['id']]);
    }
}
