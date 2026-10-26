<?php

namespace Tests\Controllers;

use App\Libraries\Accounting\AccountingGuard;
use App\Libraries\Accounting\TreasuryScopes;
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
 * said in words, and that nothing changes while accounting is off.
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
        $this->startExpenses();
    }

    protected function tearDown(): void
    {
        $this->stopExpenses();

        parent::tearDown();
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
        $limited = $this->actAsWith(['expenses', 'expenses_categories'], [1]);
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

    public function testGetView_OffersNoTreasuryWhileAccountingIsOff(): void
    {
        $this->actAsWith();
        $this->treasury('Open drawer');
        $this->setConfigValue('accounting_enabled', '0');

        $this->get('/expenses/view/-1')->assertDontSee('Open drawer');
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
}
