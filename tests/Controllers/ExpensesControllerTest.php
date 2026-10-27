<?php

namespace Tests\Controllers;

use App\Libraries\Accounting\AccountingGuard;
use App\Libraries\Accounting\TreasuryScopes;
use App\Libraries\Accounting\TreasuryService;
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
use Tests\Support\ExpenseAccountingFixtureTrait;

/**
 * The expense screens with accounting on and off: what a saved expense puts in the books, the
 * account it is charged to, the treasury and the branch it is stamped with, what is refused and
 * said in words, and that nothing changes while accounting is off; and the number an expense is
 * given by the numbering, which shows in the list, in the row that is refreshed after an edit and
 * on the form, and nothing of it shows while the sequence is off.
 */
class ExpensesControllerTest extends CIUnitTestCase
{
    use DatabaseTestTrait;
    use FeatureTestTrait;
    use AccountingFixtureTrait;
    use EmployeeFixtureTrait;
    use ExpenseAccountingFixtureTrait;

    protected $migrate     = true;
    protected $migrateOnce = true;
    protected $refresh     = false;
    protected $namespace   = null;

    public static function setUpBeforeClass(): void
    {
        $seeder = Database::seeder('tests');
        $seeder->call('TestDatabaseBootstrapSeeder');
    }

    protected function setUp(): void
    {
        parent::setUp();

        config(OSPOS::class)->update_settings();
        $this->clearNumbering();
        $this->startExpenses();
    }

    protected function tearDown(): void
    {
        $this->stopExpenses();
        $this->clearNumbering();

        parent::tearDown();
    }

    private function clearNumbering(): void
    {
        $db = $this->accountingDb();

        $db->table('document_numbers')->truncate();
        $db->table('document_sequences')->truncate();
    }

    /**
     * @param list<string>   $permissions
     * @param list<int>|null $branchIds
     */
    private function actAsWith(array $permissions = ['expenses', 'expenses_categories'], ?array $branchIds = null): int
    {
        $personId = $this->employeeWithGrants($permissions, $branchIds);

        $this->withSession(['person_id' => $personId, 'menu_group' => 'home']);

        return $personId;
    }

    /**
     * The administrator on the screens with the books off, the way the screens of the numbering
     * were first shown, before the books followed expenses.
     */
    private function actAsAdministratorWithoutBooks(): void
    {
        $this->setConfigValue('accounting_enabled', '0');
        $this->withSession(['person_id' => 1, 'menu_group' => 'office']);
    }

    /**
     * @param array<string, mixed> $overrides
     *
     * @return array<string, mixed>
     */
    private function form(array $overrides = []): array
    {
        $settings = config(OSPOS::class)->settings;

        return array_merge([
            'date'                => date($settings['dateformat'] . ' ' . $settings['timeformat'], strtotime('2026-10-20 10:00:00')),
            'supplier_id'         => '',
            'supplier_tax_code'   => '',
            'amount'              => '230.00',
            'tax_amount'          => '30.00',
            'payment_type'        => 'Cash',
            'expense_category_id' => (string) $this->category(),
            'description'         => 'From the screen',
        ], $overrides);
    }

    /**
     * @return array<string, mixed>
     */
    private function save(array $form, int $expenseId = NEW_ENTRY): array
    {
        return json_decode($this->post('/expenses/save/' . $expenseId, $form)->getJSON(), true);
    }

    private function savedRow(int $expenseId): object
    {
        return $this->accountingDb()->table('expenses')->where('expense_id', $expenseId)->get()->getRow();
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
            'expense_category_id' => $this->category(),
            'description'         => 'Expenses screen',
            'employee_id'         => 1,
            'deleted'             => 0,
        ];

        $this->assertTrue(model(Expense::class)->save_value($data));

        return (int) $data['expense_id'];
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function rowsOfTheList(): array
    {
        $response = $this->get('/expenses/search', ['search' => '', 'limit' => '100', 'offset' => '0', 'order' => 'desc', 'start_date' => '2000-01-01', 'end_date' => '2100-01-01']);

        return json_decode($response->getJSON(), true)['rows'];
    }

    /**
     * @return array<string, string> The number each expense of the list shows, by expense, for the
     *                               expenses of October 2026, the deleted ones when asked.
     */
    private function numbersOfTheList(bool $deleted = false): array
    {
        $query = '?search=&start_date=2026-10-01&end_date=2026-10-31&limit=25&offset=0&sort=expense_id&order=asc' . ($deleted ? '&filters[]=is_deleted' : '');
        $rows  = json_decode($this->get('/expenses/search' . $query)->getJSON(), true)['rows'];

        return array_column(array_filter($rows, static fn (array $row): bool => $row['expense_id'] !== '-'), 'expense_number', 'expense_id');
    }

    public function testPostSave_PostsTheJournalAndStampsTheBranchWhenAccountingIsOn(): void
    {
        $this->actAsWith();

        $result = $this->save($this->form());

        $this->assertTrue($result['success']);
        $this->assertSame(
            [$this->accountId('expenses_default') . '=200.00/0.00', $this->accountId('tax_input') . '=30.00/0.00', $this->accountId('cash') . '=0.00/230.00'],
            $this->postedLines('expense', (int) $result['id']),
        );
        $this->assertSame(1, (int) $this->savedRow((int) $result['id'])->branch_id);
    }

    public function testPostSave_ChargesTheRoutedExpensesAccountWhenTheCategoryHasNoAccount(): void
    {
        $this->actAsWith();

        $result = $this->save($this->form(['tax_amount' => '0']));

        $this->assertSame($this->accountId('expenses_default') . '=230.00/0.00', $this->postedLines('expense', (int) $result['id'])[0]);
    }

    public function testPostSave_ChargesTheAccountOfTheCategoryWhenItHasOne(): void
    {
        $this->actAsWith();

        $account = $this->customAccount('5', '5992', 'Utilities', false);
        $result  = $this->save($this->form(['tax_amount' => '0', 'expense_category_id' => (string) $this->category('Power', $account)]));

        $this->assertSame($account . '=230.00/0.00', $this->postedLines('expense', (int) $result['id'])[0]);
    }

    public function testPostSave_RequiresAnAccountForTheCategoryWhenTheSettingIsOn(): void
    {
        $this->actAsWith();
        $this->setConfigValue('accounting_expense_account_required', '1');

        $result = $this->save($this->form());

        $this->assertFalse($result['success']);
        $this->assertSame(lang('Accounting.error_expense_category_account_required'), $result['message']);
        $this->assertSame(0, $this->countRows('expenses'));
    }

    public function testPostSave_PaysFromTheTreasuryAskedFor(): void
    {
        $this->actAsWith();

        $treasury = $this->treasury();
        $result   = $this->save($this->form(['treasury_id' => (string) $treasury]));

        $this->assertSame($treasury, (int) $this->savedRow((int) $result['id'])->treasury_id);
        $this->assertSame('-230.00', $this->assertTreasuryTiesToItsAccount($treasury));
    }

    public function testPostSave_SaysInWordsThatTheDateIsInAClosedPeriod(): void
    {
        $this->actAsWith();
        $this->closeThrough('2026-10-31');

        $result = $this->save($this->form());

        $this->assertFalse($result['success']);
        $this->assertStringContainsString('2026-10-31', $result['message']);
        $this->assertSame([0, 0], [$this->countRows('expenses'), $this->countRows('accounting_journals')]);
    }

    public function testPostSave_SaysInWordsThatTheEmployeeMayNotWithdrawFromTheTreasury(): void
    {
        $worker   = $this->actAsWith();
        $treasury = $this->treasury();

        $this->restrictTreasury($treasury, TreasuryScopes::WITHDRAW, [$this->clerkId]);

        $result = $this->save($this->form(['treasury_id' => (string) $treasury]));

        $this->assertFalse($result['success']);
        $this->assertStringContainsString(explode('{0}', lang('Accounting.error_treasury_withdraw_denied'))[0], $result['message']);
        $this->assertNotSame($worker, $this->clerkId);
        $this->assertSame(0, $this->countRows('expenses'));
    }

    public function testPostSave_SaysInWordsThatTheBranchIsNotOneTheEmployeeWorksIn(): void
    {
        $second = $this->createBranch('B2');

        $this->actAsWith(['expenses', 'expenses_categories'], [1]);

        $result = $this->save($this->form(['treasury_id' => (string) $this->treasury('Remote', $second)]));

        $this->assertFalse($result['success']);
        $this->assertNotSame(lang('Expenses.error_adding_updating'), $result['message']);
        $this->assertSame(0, $this->countRows('expenses'));
    }

    public function testPostSave_UsesTheLoggedInEmployeeToDecideWhatIsAllowedWhoeverTheExpenseIsFor(): void
    {
        $limited = $this->actAsWith(['expenses', 'expenses_categories', 'employees'], [1]);
        $second  = $this->createBranch('B2');

        $result = $this->save($this->form(['employee_id' => (string) $this->clerkId, 'treasury_id' => (string) $this->treasury('Remote', $second)]));

        $this->assertFalse($result['success']);
        $this->assertNotSame($limited, $this->clerkId);
    }

    public function testPostSave_ReversesAndPostsAgainWhenAnExpenseIsEdited(): void
    {
        $this->actAsWith();

        $category = (string) $this->category();
        $created  = $this->save($this->form(['expense_category_id' => $category]));
        $edited   = $this->save($this->form(['expense_category_id' => $category, 'amount' => '115.00', 'tax_amount' => '15.00']), (int) $created['id']);

        $this->assertTrue($edited['success']);
        $this->assertSame(['reversed', 'posted'], array_column($this->journalsOfSource('expense', (int) $created['id']), 'status'));
        $this->assertSame('-115.00', $this->balanceOf($this->accountId('cash')));
    }

    public function testPostSave_ChangesNothingInTheBooksWhenAccountingIsOff(): void
    {
        $this->actAsWith();
        $this->setConfigValue('accounting_enabled', '0');

        $result = $this->save($this->form(['treasury_id' => (string) $this->treasury()]));
        $row    = $this->savedRow((int) $result['id']);

        $this->assertTrue($result['success']);
        $this->assertSame(0, $this->countRows('accounting_journals'));
        $this->assertSame([null, null], [$row->branch_id, $row->treasury_id]);
    }

    public function testPostSave_RefusesAnEmployeeWithoutTheExpensesModule(): void
    {
        $this->actAsWith(['cashups']);

        $this->post('/expenses/save/-1', $this->form())->assertRedirect();
        $this->assertSame(0, $this->countRows('expenses'));
    }

    public function testPostDelete_ReversesTheJournalsOfTheExpenses(): void
    {
        $this->actAsWith();

        $first  = (int) $this->save($this->form())['id'];
        $second = (int) $this->save($this->form(['amount' => '40.00', 'tax_amount' => '0']))['id'];

        $response = json_decode($this->post('/expenses/delete', ['ids' => [(string) $first, (string) $second]])->getJSON(), true);

        $this->assertTrue($response['success']);
        $this->assertSame('0.00', $this->balanceOf($this->accountId('cash')));
        $this->assertSame(2, $this->accountingDb()->table('expenses')->where('deleted', 1)->countAllResults());
    }

    public function testPostDelete_SaysInWordsThatTheEmployeeMayNotDepositBackIntoTheTreasury(): void
    {
        $this->actAsWith();

        $treasury = $this->treasury();
        $expense  = (int) $this->save($this->form(['treasury_id' => (string) $treasury]))['id'];

        $this->restrictTreasury($treasury, TreasuryScopes::DEPOSIT, [$this->clerkId]);

        $response = json_decode($this->post('/expenses/delete', ['ids' => [(string) $expense]])->getJSON(), true);

        $this->assertFalse($response['success']);
        $this->assertStringContainsString(explode('{0}', lang('Accounting.error_treasury_deposit_denied'))[0], $response['message']);
        $this->assertSame(0, $this->accountingDb()->table('expenses')->where('deleted', 1)->countAllResults());
    }

    public function testGetSearch_ShowsWhichExpensesAreInTheBooksAndFiltersTheOnesThatAreNot(): void
    {
        $this->actAsWith();
        $this->setConfigValue('accounting_enabled', '0');

        $legacy = (int) $this->save($this->form())['id'];

        $this->setConfigValue('accounting_enabled', '1');

        $posted = (int) $this->save($this->form())['id'];
        $query  = '?search=&start_date=2026-10-01&end_date=2026-10-31&limit=25&offset=0&sort=expense_id&order=asc';
        $rows   = json_decode($this->get('/expenses/search' . $query)->getJSON(), true)['rows'];
        $byId   = array_column($rows, 'ledger_state', 'expense_id');

        $this->assertSame(lang('Expenses.ledger_unposted'), $byId[$legacy]);
        $this->assertSame(lang('Expenses.ledger_posted'), $byId[$posted]);

        $only = json_decode($this->get('/expenses/search' . $query . '&filters[]=only_unposted')->getJSON(), true);

        $this->assertSame([(string) $legacy], array_values(array_filter(array_column($only['rows'], 'expense_id'), static fn ($id): bool => $id !== '-')));
    }

    public function testGetSearch_ShowsNoLedgerColumnWhileAccountingIsOff(): void
    {
        $this->actAsWith();
        $this->save($this->form());
        $this->setConfigValue('accounting_enabled', '0');

        $rows = json_decode($this->get('/expenses/search?search=&start_date=2026-10-01&end_date=2026-10-31&limit=25&offset=0&sort=expense_id&order=asc')->getJSON(), true)['rows'];

        $this->assertArrayNotHasKey('ledger_state', $rows[0]);
    }

    public function testGetIndex_OffersTheFilterOfWhatIsNotInTheBooksOnlyWithAccountingOn(): void
    {
        $this->actAsWith();

        $this->get('/expenses')->assertSee(lang('Expenses.unposted_filter'));

        $this->setConfigValue('accounting_enabled', '0');

        $this->get('/expenses')->assertDontSee(lang('Expenses.unposted_filter'));
    }

    public function testGetView_OffersOnlyTheTreasuriesTheEmployeeMayWithdrawFrom(): void
    {
        $this->actAsWith();

        $this->treasury('Open drawer');

        $locked = $this->treasury('Locked drawer');

        $this->restrictTreasury($locked, TreasuryScopes::WITHDRAW, [$this->clerkId]);

        $response = $this->get('/expenses/view/-1');

        $response->assertSee('Open drawer');
        $response->assertDontSee('Locked drawer');
    }

    public function testGetView_OffersOnlyTheTreasuriesOfTheBranchesTheEmployeeWorksIn(): void
    {
        $this->actAsWith(['expenses', 'expenses_categories'], [1]);

        $second = $this->createBranch('B2');

        $this->treasury('Home drawer', 1);
        $this->treasury('Far drawer', $second);

        $response = $this->get('/expenses/view/-1');

        $response->assertSee('Home drawer');
        $response->assertDontSee('Far drawer');
    }

    public function testGetView_OffersNoTreasuryWhileAccountingIsOff(): void
    {
        $this->actAsWith();
        $this->treasury('Open drawer');
        $this->setConfigValue('accounting_enabled', '0');

        $this->get('/expenses/view/-1')->assertDontSee('Open drawer');
        $this->assertStringNotContainsString('id="treasury_id"', $this->get('/expenses/view/-1')->getBody());
    }

    public function testGetIndex_ShowsTheLedgerColumnOnlyWithAccountingOn(): void
    {
        $this->actAsWith();

        $this->assertStringContainsString('ledger_state', $this->get('/expenses')->getBody());

        $this->setConfigValue('accounting_enabled', '0');

        $this->assertStringNotContainsString('ledger_state', $this->get('/expenses')->getBody());
    }

    public function testGetView_OffersTheTreasuryAnExpenseAlreadyUsesEvenWhenItWasLockedLater(): void
    {
        $this->actAsWith();

        $treasury = $this->treasury('Used drawer');
        $expense  = (int) $this->save($this->form(['treasury_id' => (string) $treasury]))['id'];
        $this->treasury('Another drawer');

        $this->restrictTreasury($treasury, TreasuryScopes::WITHDRAW, [$this->clerkId]);

        $response = $this->get('/expenses/view/' . $expense);

        $response->assertSee('Used drawer');
        $response->assertSee('Another drawer');
    }

    public function testPostSave_NeverGivesAnExpenseSavedBeforeTheBooksFollowedItAJournal(): void
    {
        $this->actAsWith();
        $this->setConfigValue('accounting_enabled', '0');

        $category = (string) $this->category();
        $legacy   = (int) $this->save($this->form(['expense_category_id' => $category]))['id'];

        $this->setConfigValue('accounting_enabled', '1');

        $edited = $this->save($this->form(['expense_category_id' => $category, 'amount' => '77.00', 'tax_amount' => '0']), $legacy);

        $this->assertTrue($edited['success']);
        $this->assertSame(0, $this->countRows('accounting_journals'));
        $this->assertNull($this->savedRow($legacy)->branch_id);
    }

    public function testGetView_RequiresTheExpensesModule(): void
    {
        $this->actAsWith(['cashups']);

        $this->get('/expenses/view/-1')->assertRedirect();
    }

    public function testPostSave_KeepsAnAccountRequiringSettingFromBlockingAnEditOfTheSameCategory(): void
    {
        $this->actAsWith([AccountingGuard::SETTINGS_MANAGE, 'expenses', 'expenses_categories']);

        $category = (string) $this->category();
        $created  = $this->save($this->form(['expense_category_id' => $category]));

        $this->setConfigValue('accounting_expense_account_required', '1');

        $edited = $this->save($this->form(['expense_category_id' => $category, 'description' => 'Edited']), (int) $created['id']);

        $this->assertTrue($edited['success']);
    }

    public function testGetIndex_AddsTheNumberColumnOnlyWhileTheSequenceIsOn(): void
    {
        $this->actAsAdministratorWithoutBooks();

        $this->get('/expenses')->assertDontSee('expense_number');

        $this->switchOn();

        $this->get('/expenses')->assertSee('expense_number');
    }

    public function testGetSearch_ListsTheNumberOfEachExpenseThatHasOne(): void
    {
        $this->actAsAdministratorWithoutBooks();

        $before = $this->expense();

        $this->switchOn();

        $after = $this->expense();

        $rows = array_column($this->rowsOfTheList(), null, 'expense_id');

        $this->assertSame('', $rows[$before]['expense_number']);
        $this->assertSame('EXP-00001', $rows[$after]['expense_number']);
    }

    public function testGetRow_ShowsTheNumberOfTheExpenseThatIsRefreshed(): void
    {
        $this->actAsAdministratorWithoutBooks();
        $this->switchOn();
        $expenseId = $this->expense();

        $row = json_decode($this->get('/expenses/row/' . $expenseId)->getJSON(), true);

        $this->assertSame('EXP-00001', $row['expense_number']);
    }

    public function testGetView_ShowsTheNumberOnTheFormOfTheExpense(): void
    {
        $this->actAsAdministratorWithoutBooks();
        $this->switchOn();
        $expenseId = $this->expense();

        $response = $this->get('/expenses/view/' . $expenseId);

        $response->assertSee(lang('Numbering.document_number'));
        $response->assertSee('EXP-00001');
    }

    public function testGetView_ShowsNoNumberWhileTheSequenceIsOff(): void
    {
        $this->actAsAdministratorWithoutBooks();
        $expenseId = $this->expense();

        $this->get('/expenses/view/' . $expenseId)->assertDontSee(lang('Numbering.document_number'));
    }

    public function testPostSave_NumbersTheExpenseThatTheFormAdds(): void
    {
        $this->actAsAdministratorWithoutBooks();
        $this->switchOn();
        $settings = config(OSPOS::class)->settings;

        $response = $this->post('/expenses/save/-1', [
            'date'                => date(($settings['dateformat'] ?? 'm/d/Y') . ' ' . ($settings['timeformat'] ?? 'H:i:s')),
            'amount'              => '12.00',
            'tax_amount'          => '0',
            'payment_type'        => 'Cash',
            'expense_category_id' => (string) $this->category(),
            'description'         => 'From the form',
            'employee_id'         => '1',
        ]);

        $result = json_decode($response->getJSON(), true);

        $this->assertTrue($result['success']);
        $this->assertSame('EXP-00001', (new DocumentNumbering())->numbers(DocumentType::Expense, 'expenses', [(int) $result['id']])[(int) $result['id']]);
    }

    public function testPostSave_NumbersAndPostsTheExpenseThatTheFormAddsWhenAccountingIsOn(): void
    {
        $this->actAsWith();
        $this->switchOn();

        $result = $this->save($this->form());

        $this->assertTrue($result['success']);
        $this->assertSame('EXP-00001', (new DocumentNumbering())->label(DocumentType::Expense, 'expenses', (int) $result['id']));
        $this->assertSame(
            [$this->accountId('expenses_default') . '=200.00/0.00', $this->accountId('tax_input') . '=30.00/0.00', $this->accountId('cash') . '=0.00/230.00'],
            $this->postedLines('expense', (int) $result['id']),
        );
    }

    public function testPostSave_ConsumesNoNumberWhenTheBooksRefuseTheExpense(): void
    {
        $this->actAsWith();
        $this->switchOn();
        $this->closeThrough('2026-10-31');

        $refused = $this->save($this->form());

        $this->assertFalse($refused['success']);
        $this->assertStringContainsString('2026-10-31', $refused['message']);
        $this->assertSame([0, 0], [$this->countRows('expenses'), $this->countRows('document_numbers')]);

        $this->accountingDb()->table('accounting_closed_periods')->update(['active' => 0]);

        $saved = $this->save($this->form());

        $this->assertTrue($saved['success']);
        $this->assertSame('EXP-00001', (new DocumentNumbering())->label(DocumentType::Expense, 'expenses', (int) $saved['id']));
    }

    public function testPostSave_SaysInWordsThatNoNumberCanBeGivenAndLeavesNothingBehind(): void
    {
        $this->actAsWith();
        $this->switchOn();

        $this->accountingDb()->table('document_sequences')->where('doc_type', 'expense')->update(['next_number' => 4294967294]);
        $this->accountingDb()->table('document_numbers')->insert(['company_id' => 1, 'doc_type' => 'expense', 'source_table' => 'expenses', 'source_id' => 999999999, 'sequence_number' => 4294967294, 'number' => 'EXP-4294967294']);

        $result = $this->save($this->form());

        $this->assertFalse($result['success']);
        $this->assertSame(lang('Accounting.error_numbering_exhausted'), $result['message']);
        $this->assertSame([0, 0], [$this->countRows('expenses'), $this->countRows('accounting_journals')]);
    }

    public function testPostSave_KeepsTheNumberAndPostsAgainWhenTheFormEditsANumberedExpense(): void
    {
        $this->actAsWith();
        $this->switchOn();

        $category = (string) $this->category();
        $created  = $this->save($this->form(['expense_category_id' => $category]));
        $edited   = $this->save($this->form(['expense_category_id' => $category, 'amount' => '115.00', 'tax_amount' => '15.00']), (int) $created['id']);

        $this->assertTrue($edited['success']);
        $this->assertSame('EXP-00001', (new DocumentNumbering())->label(DocumentType::Expense, 'expenses', (int) $created['id']));
        $this->assertSame(1, $this->countRows('document_numbers'));
        $this->assertSame(['reversed', 'posted'], array_column($this->journalsOfSource('expense', (int) $created['id']), 'status'));
    }

    public function testPostDelete_KeepsTheNumbersOfTheDeletedExpensesInTheList(): void
    {
        $this->actAsWith();
        $this->switchOn();

        $first  = (int) $this->save($this->form())['id'];
        $second = (int) $this->save($this->form(['amount' => '40.00', 'tax_amount' => '0']))['id'];

        $response = json_decode($this->post('/expenses/delete', ['ids' => [(string) $first, (string) $second]])->getJSON(), true);

        $this->assertTrue($response['success']);
        $this->assertSame([$first => 'EXP-00001', $second => 'EXP-00002'], $this->numbersOfTheList(true));
        $this->assertSame([], $this->numbersOfTheList());
        $this->assertSame('0.00', $this->balanceOf($this->accountId('cash')));
    }

    public function testGetSearch_ShowsTheNumberBesideTheLedgerStateWhileAccountingIsOn(): void
    {
        $this->actAsWith();
        $this->switchOn();

        $expenseId = (int) $this->save($this->form())['id'];
        $index     = $this->get('/expenses')->getBody();
        $row       = array_column(json_decode($this->get('/expenses/search?search=&start_date=2026-10-01&end_date=2026-10-31&limit=25&offset=0&sort=expense_id&order=asc')->getJSON(), true)['rows'], null, 'expense_id')[$expenseId];

        $this->assertStringContainsString('expense_number', $index);
        $this->assertStringContainsString('ledger_state', $index);
        $this->assertSame(['EXP-00001', lang('Expenses.ledger_posted')], [$row['expense_number'], $row['ledger_state']]);
    }

    private function treasurySelect(string $body): string
    {
        $this->assertSame(1, preg_match('#<select name="treasury_id".*?</select>#s', $body, $matches), 'The form has no treasury field.');

        return $matches[0];
    }

    public function testGetView_EscapesTheNamesOfTheTreasuriesOffered(): void
    {
        $this->actAsWith();

        $this->treasury('<b>Bold</b> drawer');

        $used    = $this->treasury('<i>Used</i> drawer');
        $expense = (int) $this->save($this->form(['treasury_id' => (string) $used]))['id'];

        $this->treasury('Another drawer');
        $this->restrictTreasury($used, TreasuryScopes::WITHDRAW, [$this->clerkId]);

        $select = $this->treasurySelect($this->get('/expenses/view/' . $expense)->getBody());

        $this->assertStringContainsString('&lt;b&gt;Bold&lt;/b&gt; drawer', $select);
        $this->assertStringContainsString('&lt;i&gt;Used&lt;/i&gt; drawer', $select);
        $this->assertStringNotContainsString('<b>Bold</b>', $select);
        $this->assertStringNotContainsString('<i>Used</i>', $select);
    }

    public function testGetView_SelectsTheTreasuryTheExpenseUsesAndTheDefaultForOneThatHasNone(): void
    {
        $this->actAsWith();

        $treasury = $this->treasury('Used drawer');
        $paid     = (int) $this->save($this->form(['treasury_id' => (string) $treasury]))['id'];
        $unpaid   = (int) $this->save($this->form())['id'];

        $this->assertStringContainsString('<option value="' . $treasury . '" selected>', $this->treasurySelect($this->get('/expenses/view/' . $paid)->getBody()));
        $this->assertStringNotContainsString('<option value="0" selected>', $this->treasurySelect($this->get('/expenses/view/' . $paid)->getBody()));
        $this->assertStringContainsString('<option value="0" selected>', $this->treasurySelect($this->get('/expenses/view/' . $unpaid)->getBody()));
    }

    public function testPostSave_KeepsAnExpenseThatHasNoTreasuryWithoutOneWhenTheFormPostsTheDefault(): void
    {
        $category = $this->category();
        $created  = $this->saveExpense($this->expenseData('100.00', '0', 'Cash', $category));
        $editor   = $this->actAsWith();
        $drawer   = $this->treasury('Editor drawer');

        (new TreasuryService())->setDefaultFor($editor, $drawer);

        $this->assertStringContainsString('<option value="0" selected>', $this->treasurySelect($this->get('/expenses/view/' . $created)->getBody()));

        $result = $this->save($this->form(['amount' => '100.00', 'tax_amount' => '0', 'expense_category_id' => (string) $category, 'treasury_id' => '0', 'description' => 'Only the description changed']), $created);

        $this->assertTrue($result['success']);
        $this->assertNull($this->savedRow($created)->treasury_id);
        $this->assertSame(1, count($this->journalsOfSource('expense', $created)));
        $this->assertSame('0.00', $this->assertTreasuryTiesToItsAccount($drawer));
    }

    public function testGetView_OffersNoTreasuryForAnExpenseTheBooksNeverFollowed(): void
    {
        $this->actAsWith();
        $this->treasury('Open drawer');
        $this->setConfigValue('accounting_enabled', '0');

        $legacy = (int) $this->save($this->form())['id'];

        $this->setConfigValue('accounting_enabled', '1');

        $followed = (int) $this->save($this->form())['id'];

        $this->assertStringNotContainsString('id="treasury_id"', $this->get('/expenses/view/' . $legacy)->getBody());
        $this->assertStringContainsString('id="treasury_id"', $this->get('/expenses/view/' . $followed)->getBody());
        $this->assertStringContainsString('id="treasury_id"', $this->get('/expenses/view/-1')->getBody());
    }

    public function testGetView_ListsTheWaysOfPaymentThatUseNoTreasuryForTheForm(): void
    {
        $this->actAsWith();
        $this->treasury('Open drawer');

        $body = $this->get('/expenses/view/-1')->getBody();

        $this->assertStringContainsString('const noTreasuryPayments = ' . json_encode([lang('Sales.due')], JSON_UNESCAPED_UNICODE) . ';', $body);
        $this->assertStringContainsString('id="treasury_field"', $body);
    }
}
