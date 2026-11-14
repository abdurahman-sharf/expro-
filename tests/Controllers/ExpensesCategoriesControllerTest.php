<?php

namespace Tests\Controllers;

use App\Libraries\Accounting\AccountingGuard;
use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\DatabaseTestTrait;
use CodeIgniter\Test\FeatureTestTrait;
use Config\Database;
use Config\OSPOS;
use Tests\Support\AccountingFixtureTrait;
use Tests\Support\EmployeeFixtureTrait;
use Tests\Support\ExpenseAccountingFixtureTrait;

/**
 * The expense category screen with accounting on and off: the account a category is charged to is
 * chosen only by who may change the routing, is saved together with the rest of the category or not
 * at all, and does not exist while accounting is off.
 */
class ExpensesCategoriesControllerTest extends CIUnitTestCase
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
    private function actAsWith(array $permissions, ?array $branchIds = null): int
    {
        $personId = $this->employeeWithGrants(['expenses_categories', ...$permissions], $branchIds);

        $this->withSession(['person_id' => $personId, 'menu_group' => 'home']);

        return $personId;
    }

    /**
     * @return array<string, mixed>
     */
    private function save(array $form, int $categoryId = NEW_ENTRY): array
    {
        return json_decode($this->post('/expenses_categories/save/' . $categoryId, $form)->getJSON(), true);
    }

    private function row(int $categoryId): object
    {
        return $this->accountingDb()->table('expense_categories')->where('expense_category_id', $categoryId)->get()->getRow();
    }

    private function nameOf(): string
    {
        return 'Category ' . uniqid();
    }

    public function testPostSave_GivesTheCategoryTheAccountToWhoMayChangeTheRouting(): void
    {
        $this->actAsWith([AccountingGuard::ROUTING_MANAGE]);

        $account = $this->customAccount('5', '5991', 'Marketing', false);
        $result  = $this->save(['category_name' => $this->nameOf(), 'category_description' => '', 'account_id' => (string) $account]);

        $this->assertTrue($result['success']);
        $this->assertSame($account, (int) $this->row((int) $result['id'])->account_id);

        $this->categoryIds[] = (int) $result['id'];
    }

    public function testPostSave_RefusesTheAccountOfAnEmployeeWhoMayNotChangeTheRoutingAndSavesNothing(): void
    {
        $this->actAsWith([]);

        $before  = $this->countRows('expense_categories');
        $account = $this->customAccount('5', '5992', 'Marketing', false);
        $result  = $this->save(['category_name' => $this->nameOf(), 'category_description' => '', 'account_id' => (string) $account]);

        $this->assertFalse($result['success']);
        $this->assertSame(lang('Accounting.error_permission_denied'), $result['message']);
        $this->assertSame($before, $this->countRows('expense_categories'));
    }

    public function testPostSave_RefusesAnAccountFromAnEmployeeWhoWorksInSomeBranchesOnlyAndSavesNothing(): void
    {
        $this->actAsWith([AccountingGuard::ROUTING_MANAGE], [1]);

        $before  = $this->countRows('expense_categories');
        $account = $this->customAccount('5', '5986', 'Marketing', false);
        $result  = $this->save(['category_name' => $this->nameOf(), 'category_description' => '', 'account_id' => (string) $account]);

        $this->assertFalse($result['success']);
        $this->assertSame($before, $this->countRows('expense_categories'));
    }

    public function testPostSave_SavesTheCategoryOfAnEmployeeWhoLeavesTheAccountAsItIs(): void
    {
        $this->actAsWith([]);

        $account  = $this->customAccount('5', '5993', 'Marketing', false);
        $category = $this->category('Ads', $account);
        $result   = $this->save(['category_name' => 'Renamed ' . uniqid(), 'category_description' => 'new words', 'account_id' => (string) $account], $category);

        $this->assertTrue($result['success']);
        $this->assertSame([$account, 'new words'], [(int) $this->row($category)->account_id, $this->row($category)->category_description]);
    }

    public function testPostSave_KeepsTheAccountWhenTheFormDoesNotCarryOne(): void
    {
        $this->actAsWith([AccountingGuard::ROUTING_MANAGE]);

        $account  = $this->customAccount('5', '5994', 'Marketing', false);
        $category = $this->category('Ads', $account);

        $this->assertTrue($this->save(['category_name' => 'Renamed ' . uniqid(), 'category_description' => ''], $category)['success']);
        $this->assertSame($account, (int) $this->row($category)->account_id);
    }

    public function testPostSave_RefusesAnAccountThatCannotTakeExpensesAndKeepsTheCategoryAsItWas(): void
    {
        $this->actAsWith([AccountingGuard::ROUTING_MANAGE]);

        $category = $this->category('Ads');
        $name     = $this->row($category)->category_name;
        $result   = $this->save(['category_name' => 'Renamed ' . uniqid(), 'category_description' => '', 'account_id' => (string) $this->accountId('sales_revenue')], $category);

        $this->assertFalse($result['success']);
        $this->assertSame(lang('Accounting.error_expense_category_account_invalid'), $result['message']);
        $this->assertSame([$name, null], [$this->row($category)->category_name, $this->row($category)->account_id]);
    }

    public function testPostSave_IgnoresTheAccountWhileAccountingIsOff(): void
    {
        $this->actAsWith([AccountingGuard::ROUTING_MANAGE]);
        $this->setConfigValue('accounting_enabled', '0');

        $account = $this->customAccount('5', '5995', 'Marketing', false);
        $result  = $this->save(['category_name' => $this->nameOf(), 'category_description' => '', 'account_id' => (string) $account]);

        $this->assertTrue($result['success']);
        $this->assertNull($this->row((int) $result['id'])->account_id);

        $this->categoryIds[] = (int) $result['id'];
    }

    public function testGetView_OffersTheAccountsToWhoMayChangeTheRouting(): void
    {
        $this->actAsWith([AccountingGuard::ROUTING_MANAGE]);

        $response = $this->get('/expenses_categories/view/-1');

        $response->assertSee(lang('Expenses_categories.account'));
        $response->assertSee(lang('Expenses_categories.account_desc'));
        $response->assertSee('541 - ');
        $response->assertDontSee('511 - ');
    }

    public function testGetView_ShowsTheAccountButLocksItForAnEmployeeWhoMayNotChangeTheRouting(): void
    {
        $this->actAsWith([]);

        $response = $this->get('/expenses_categories/view/-1');

        $response->assertSee(lang('Expenses_categories.account_locked'));
        $response->assertSee('disabled');
    }

    public function testGetView_ShowsNoAccountWhileAccountingIsOff(): void
    {
        $this->actAsWith([AccountingGuard::ROUTING_MANAGE]);
        $this->setConfigValue('accounting_enabled', '0');

        $this->get('/expenses_categories/view/-1')->assertDontSee(lang('Expenses_categories.account_default'));
        $this->assertStringNotContainsString('id="account_id"', $this->get('/expenses_categories/view/-1')->getBody());
    }

    private function accountSelect(string $body): string
    {
        $this->assertSame(1, preg_match('#<select name="account_id".*?</select>#s', $body, $matches), 'The form has no account field.');

        return $matches[0];
    }

    public function testGetView_EscapesTheNamesOfTheAccountsOffered(): void
    {
        $this->actAsWith([AccountingGuard::ROUTING_MANAGE]);

        $this->customAccount('5', '5993', '<i>Marketing</i>', false);

        $select = $this->accountSelect($this->get('/expenses_categories/view/-1')->response()->getBody());

        $this->assertStringContainsString('5993 - &lt;i&gt;Marketing&lt;/i&gt;', $select);
        $this->assertStringNotContainsString('<i>Marketing</i>', $select);
    }

    public function testGetView_SelectsTheAccountTheCategoryHasAndTheDefaultForOneThatHasNone(): void
    {
        $this->actAsWith([AccountingGuard::ROUTING_MANAGE]);

        $account = $this->customAccount('5', '5994', 'Utilities', false);
        $chosen  = $this->category('Power', $account);
        $plain   = $this->category('Plain');

        $this->assertStringContainsString('<option value="' . $account . '" selected>', $this->accountSelect($this->get('/expenses_categories/view/' . $chosen)->getBody()));
        $this->assertStringNotContainsString('<option value="0" selected>', $this->accountSelect($this->get('/expenses_categories/view/' . $chosen)->getBody()));
        $this->assertStringContainsString('<option value="0" selected>', $this->accountSelect($this->get('/expenses_categories/view/' . $plain)->getBody()));
    }
}
