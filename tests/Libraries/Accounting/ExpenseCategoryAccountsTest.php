<?php

namespace Tests\Libraries\Accounting;

use App\Libraries\Accounting\AccountingError;
use App\Libraries\Accounting\AccountingException;
use App\Libraries\Accounting\AccountingGuard;
use App\Libraries\Accounting\ExpenseCategoryAccounts;
use App\Libraries\Organization\BranchError;
use App\Libraries\Organization\BranchException;
use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\DatabaseTestTrait;
use Config\Database;
use Tests\Support\AccountingFixtureTrait;
use Tests\Support\EmployeeFixtureTrait;
use Tests\Support\ExpenseAccountingFixtureTrait;

/**
 * The account an expense category is charged to: only an employee who may change the routing, for
 * every branch, chooses it; only an active expense account that takes entries and is not kept for
 * another purpose is accepted; every change is audited.
 */
class ExpenseCategoryAccountsTest extends CIUnitTestCase
{
    use DatabaseTestTrait;
    use AccountingFixtureTrait;
    use EmployeeFixtureTrait;
    use ExpenseAccountingFixtureTrait;

    protected $migrate     = true;
    protected $migrateOnce = true;
    protected $refresh     = false;
    protected $namespace   = null;

    private ExpenseCategoryAccounts $accounts;
    private int $router;

    public static function setUpBeforeClass(): void
    {
        $seeder = Database::seeder('tests');
        $seeder->call('TestDatabaseBootstrapSeeder');
    }

    protected function setUp(): void
    {
        parent::setUp();

        $this->startExpenses();

        $this->accounts = new ExpenseCategoryAccounts();
        $this->router   = $this->employeeWithGrants([AccountingGuard::ROUTING_MANAGE]);
    }

    protected function tearDown(): void
    {
        $this->stopExpenses();

        parent::tearDown();
    }

    private function accountOf(int $categoryId): ?int
    {
        $row = $this->accountingDb()->table('expense_categories')->where('expense_category_id', $categoryId)->get()->getRow();

        return $row->account_id === null ? null : (int) $row->account_id;
    }

    private function refusal(callable $action): AccountingException|BranchException
    {
        try {
            $action();
        } catch (AccountingException|BranchException $exception) {
            return $exception;
        }

        $this->fail('The change was not refused.');
    }

    public function testAssign_GivesTheCategoryAnExpenseAccount(): void
    {
        $account  = $this->customAccount('5', '5993', 'Marketing', false);
        $category = $this->category();

        $this->accounts->assign($category, (string) $account, $this->router);

        $this->assertSame($account, $this->accountOf($category));
    }

    public function testAssign_AcceptsTheAccountOfExpensesTheRoutingNames(): void
    {
        $category = $this->category();

        $this->accounts->assign($category, $this->accountId('expenses_default'), $this->router);

        $this->assertSame($this->accountId('expenses_default'), $this->accountOf($category));
    }

    public function testAssign_TakesTheAccountAwayWithAnEmptyValue(): void
    {
        $account  = $this->customAccount('5', '5994', 'Marketing', false);
        $category = $this->category('Ads', $account);

        $this->accounts->assign($category, '', $this->router);

        $this->assertNull($this->accountOf($category));
    }

    public function testAssign_AuditsEveryChange(): void
    {
        $account  = $this->customAccount('5', '5995', 'Marketing', false);
        $category = $this->category();

        $this->accounts->assign($category, $account, $this->router);
        $this->accounts->assign($category, '', $this->router);

        $rows = $this->accountingDb()->table('accounting_audit_log')->where(['entity_type' => 'expense_category', 'entity_id' => $category])->orderBy('audit_id')->get()->getResult();

        $this->assertSame(['account_changed', 'account_changed'], array_column($rows, 'action'));
        $this->assertSame(['before' => null, 'after' => $account], json_decode($rows[0]->details, true));
        $this->assertSame(['before' => $account, 'after' => null], json_decode($rows[1]->details, true));
        $this->assertSame($this->router, (int) $rows[0]->employee_id);
    }

    public function testAssign_WritesNothingWhenTheAccountIsTheOneTheCategoryHas(): void
    {
        $account  = $this->customAccount('5', '5996', 'Marketing', false);
        $category = $this->category('Ads', $account);
        $outsider = $this->employeeWithGrants([]);

        $this->accounts->assign($category, $account, $outsider);

        $this->assertSame(0, $this->accountingDb()->table('accounting_audit_log')->where('entity_type', 'expense_category')->countAllResults());
    }

    public function testAssign_RefusesAnEmployeeWithoutThePermissionToChangeTheRouting(): void
    {
        $account  = $this->customAccount('5', '5997', 'Marketing', false);
        $category = $this->category();
        $outsider = $this->employeeWithGrants([]);

        $failure = $this->refusal(fn () => $this->accounts->assign($category, $account, $outsider));

        $this->assertSame(AccountingError::PermissionDenied, $failure->error);
        $this->assertNull($this->accountOf($category));
    }

    public function testAssign_RefusesAnEmployeeWhoDoesNotWorkInEveryBranch(): void
    {
        $account = $this->customAccount('5', '5998', 'Marketing', false);
        $limited = $this->employeeWithGrants([AccountingGuard::ROUTING_MANAGE], [1]);
        $cat     = $this->category();

        $failure = $this->refusal(fn () => $this->accounts->assign($cat, $account, $limited));

        $this->assertSame(BranchError::CompanyScopeNeeded, $failure->error);
        $this->assertNull($this->accountOf($cat));
    }

    public function testAssign_RefusesAnAccountThatIsNotAnExpenseAccount(): void
    {
        $category = $this->category();

        foreach (['sales_revenue', 'cash', 'payables'] as $systemKey) {
            $failure = $this->refusal(fn () => $this->accounts->assign($category, $this->accountId($systemKey), $this->router));

            $this->assertSame(AccountingError::ExpenseCategoryAccountInvalid, $failure->error, $systemKey);
        }

        $this->assertNull($this->accountOf($category));
    }

    public function testAssign_RefusesAnExpenseAccountKeptForAnotherPurpose(): void
    {
        $category = $this->category();

        foreach (['cogs', 'sales_discount'] as $systemKey) {
            $failure = $this->refusal(fn () => $this->accounts->assign($category, $this->accountId($systemKey), $this->router));

            $this->assertSame(AccountingError::ExpenseCategoryAccountInvalid, $failure->error, $systemKey);
        }
    }

    public function testAssign_RefusesAGroupAnInactiveAccountAndOneThatDoesNotExist(): void
    {
        $category = $this->category();
        $inactive = $this->customAccount('5', '5999', 'Closed', false);

        $this->accountingDb()->table('accounting_accounts')->where('account_id', $inactive)->update(['active' => 0]);

        foreach ([$this->accountIdByCode('52'), $inactive, 987654] as $account) {
            $failure = $this->refusal(fn () => $this->accounts->assign($category, $account, $this->router));

            $this->assertSame(AccountingError::ExpenseCategoryAccountInvalid, $failure->error);
        }
    }

    public function testAssign_DoesNothingForACategoryThatDoesNotExist(): void
    {
        $this->accounts->assign(987654, $this->accountId('expenses_default'), $this->router);

        $this->assertSame(0, $this->accountingDb()->table('accounting_audit_log')->where('entity_type', 'expense_category')->countAllResults());
    }

    public function testOptions_ListsOnlyExpenseAccountsThatTakeEntriesInCodeOrder(): void
    {
        $custom = $this->customAccount('5', '5990', 'Marketing', false);
        $codes  = array_column($this->accounts->options(), 'code');

        $this->assertContains('541', $codes);
        $this->assertContains('5201', $codes);
        $this->assertContains('5990', $codes);
        $this->assertNotContains('511', $codes);
        $this->assertNotContains('513', $codes);
        $this->assertNotContains('52', $codes);
        $this->assertNotContains('411', $codes);
        $this->assertContains($custom, array_map(static fn (object $option): int => (int) $option->account_id, $this->accounts->options()));

        $sorted = $codes;

        sort($sorted, SORT_STRING);

        $this->assertSame($sorted, $codes);
    }

    public function testMayAssign_IsForWhoCanChangeTheRoutingInEveryBranch(): void
    {
        $this->assertTrue($this->accounts->mayAssign($this->router));
        $this->assertFalse($this->accounts->mayAssign($this->employeeWithGrants([])));
        $this->assertFalse($this->accounts->mayAssign($this->employeeWithGrants([AccountingGuard::ROUTING_MANAGE], [1])));
    }
}
