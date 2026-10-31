<?php

namespace Tests\Libraries\Accounting;

use App\Libraries\Accounting\AccountingError;
use App\Libraries\Accounting\AccountingException;
use App\Libraries\Accounting\AccountingGuard;
use App\Libraries\Accounting\AccountService;
use App\Libraries\Accounting\RevenueCategories;
use App\Libraries\Accounting\RevenueVouchers;
use App\Libraries\Organization\BranchError;
use App\Libraries\Organization\BranchException;
use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\DatabaseTestTrait;
use Config\Database;
use Tests\Support\AccountingFixtureTrait;
use Tests\Support\EmployeeFixtureTrait;
use Tests\Support\ExpenseAccountingFixtureTrait;

/**
 * The categories of receipt vouchers: a unique name, and an account only whoever may change the
 * routing, in every branch, chooses, from the active revenue accounts that take entries.
 */
class RevenueCategoriesTest extends CIUnitTestCase
{
    use DatabaseTestTrait;
    use AccountingFixtureTrait;
    use EmployeeFixtureTrait;
    use ExpenseAccountingFixtureTrait;

    protected $migrate     = true;
    protected $migrateOnce = true;
    protected $refresh     = false;
    protected $namespace   = null;

    private RevenueCategories $categories;
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

        $this->categories = new RevenueCategories();
        $this->router     = $this->employeeWithGrants([AccountingGuard::ROUTING_MANAGE]);
    }

    protected function tearDown(): void
    {
        $this->stopExpenses();

        parent::tearDown();
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

    private function accountOf(int $categoryId): ?int
    {
        $row = $this->accountingDb()->table('revenue_categories')->where('revenue_category_id', $categoryId)->get()->getRow();

        return $row->account_id === null ? null : (int) $row->account_id;
    }

    public function testSave_CreatesACategoryWithoutAnAccountAndAuditsIt(): void
    {
        $id = $this->categories->save(['category_name' => 'Rent', 'category_description' => 'Stand rent'], null, $this->clerkId);

        $category = $this->categories->find($id);

        $this->assertSame(['Rent', 'Stand rent', null], [$category->category_name, $category->category_description, $category->account_id]);
        $this->assertSame(1, $this->accountingDb()->table('accounting_audit_log')->where(['entity_type' => 'revenue_category', 'entity_id' => $id, 'action' => 'created'])->countAllResults());
    }

    public function testSave_ChecksTheNameAndKeepsItUniqueAmongTheCategoriesInUse(): void
    {
        $id = $this->categories->save(['category_name' => 'Rent'], null, $this->clerkId);

        foreach (['', '   ', str_repeat('x', 256)] as $name) {
            $this->assertSame(AccountingError::RevenueCategoryNameInvalid, $this->refusal(fn () => $this->categories->save(['category_name' => $name], null, $this->clerkId))->error);
        }

        $this->assertSame(AccountingError::RevenueCategoryNameTaken, $this->refusal(fn () => $this->categories->save(['category_name' => 'Rent'], null, $this->clerkId))->error);

        $this->categories->save(['category_name' => 'Rent', 'category_description' => 'Same name, same category'], $id, $this->clerkId);
        $this->categories->delete([$id], $this->clerkId);

        $this->assertGreaterThan($id, $this->categories->save(['category_name' => 'Rent'], null, $this->clerkId));
    }

    public function testSave_RejectsACategoryThatDoesNotExist(): void
    {
        $this->assertSame(AccountingError::RevenueCategoryNotFound, $this->refusal(fn () => $this->categories->save(['category_name' => 'x'], 99999, $this->clerkId))->error);
    }

    public function testSave_GivesTheCategoryARevenueAccountToWhoMayChangeTheRouting(): void
    {
        $account = $this->customAccount('42', '4210', 'Rent income');
        $id      = $this->categories->save(['category_name' => 'Rent', 'account_id' => $account], null, $this->router);

        $this->assertSame($account, $this->accountOf($id));
        $this->assertSame(1, $this->accountingDb()->table('accounting_audit_log')->where(['entity_type' => 'revenue_category', 'entity_id' => $id, 'action' => 'created'])->countAllResults());

        $this->categories->save(['category_name' => 'Rent', 'account_id' => ''], $id, $this->router);

        $this->assertNull($this->accountOf($id));
        $this->assertSame(1, $this->accountingDb()->table('accounting_audit_log')->where(['entity_type' => 'revenue_category', 'entity_id' => $id, 'action' => 'account_changed'])->countAllResults());
    }

    public function testSave_AsksForThePermissionAndEveryBranchBeforeAnAccountIsChosen(): void
    {
        $account = $this->customAccount('42', '4210', 'Rent income');
        $nobody  = $this->employeeWithGrants([]);
        $other   = $this->createBranch();
        $limited = $this->employeeWithGrants([AccountingGuard::ROUTING_MANAGE], [$other]);

        $this->assertSame(AccountingError::PermissionDenied, $this->refusal(fn () => $this->categories->save(['category_name' => 'Rent', 'account_id' => $account], null, $nobody))->error);

        $failure = $this->refusal(fn () => $this->categories->save(['category_name' => 'Rent', 'account_id' => $account], null, $limited));

        $this->assertInstanceOf(BranchException::class, $failure);
        $this->assertSame(BranchError::CompanyScopeNeeded, $failure->error);
        $this->assertSame(0, $this->countRows('revenue_categories'));
    }

    public function testSave_KeepingTheAccountItHasChangesNothingAndAsksForNothing(): void
    {
        $account = $this->customAccount('42', '4210', 'Rent income');
        $id      = $this->categories->save(['category_name' => 'Rent', 'account_id' => $account], null, $this->router);
        $nobody  = $this->employeeWithGrants([]);

        $this->categories->save(['category_name' => 'Rent', 'category_description' => 'New text', 'account_id' => (string) $account], $id, $nobody);
        $this->categories->save(['category_name' => 'Rent again'], $id, $nobody);

        $this->assertSame($account, $this->accountOf($id));
        $this->assertSame('Rent again', $this->categories->find($id)->category_name);
    }

    public function testSave_AcceptsOnlyAnActiveRevenueAccountThatTakesEntriesAndIsNotKeptForAnotherPurpose(): void
    {
        $inactive = $this->customAccount('42', '4215', 'Old income');

        (new AccountService())->deactivate($inactive);

        foreach ([$this->accountIdByCode('42'), $inactive, $this->accountId('cogs'), $this->accountId('sales_revenue'), $this->accountId('tax_output'), 99999] as $accountId) {
            $this->assertSame(AccountingError::RevenueAccountInvalid, $this->refusal(fn () => $this->categories->save(['category_name' => 'x' . $accountId, 'account_id' => $accountId], null, $this->router))->error, 'account ' . $accountId);
        }

        $id = $this->categories->save(['category_name' => 'Other', 'account_id' => $this->accountId('revenues_default')], null, $this->router);

        $this->assertSame($this->accountId('revenues_default'), $this->accountOf($id));
        $this->assertSame(1, $this->countRows('revenue_categories'));
    }

    public function testAccountOptions_ListsTheRevenueAccountsThatTakeEntriesInCodeOrder(): void
    {
        $this->customAccount('42', '4210', 'Rent income');
        $inactive = $this->customAccount('42', '4215', 'Old income');

        (new AccountService())->deactivate($inactive);

        $codes = array_column($this->categories->accountOptions(), 'code');

        $this->assertSame(['4201', '4202', '4210'], $codes);
    }

    public function testDelete_MarksTheCategoriesDeletedAndLeavesTheVouchersTheyCarry(): void
    {
        $id       = $this->categories->save(['category_name' => 'Rent'], null, $this->clerkId);
        $treasury = $this->treasury('Drawer');
        $voucher  = (new RevenueVouchers())->save(['date' => date('Y-m-d'), 'amount' => '10.00', 'payment_type' => 'Cash', 'treasury_id' => $treasury, 'category_id' => $id], null, $this->clerkId);

        $this->categories->delete([$id, 0, -3], $this->clerkId);
        $this->categories->delete([], $this->clerkId);

        $this->assertNull($this->categories->find($id));
        $this->assertSame($id, (int) (new RevenueVouchers())->find($voucher)->revenue_category_id);
        $this->assertSame([], $this->categories->all());
        $this->assertSame(1, $this->accountingDb()->table('accounting_audit_log')->where(['entity_type' => 'revenue_category', 'entity_id' => $id, 'action' => 'deleted'])->countAllResults());
    }

    public function testSearch_FiltersAndSortsAndCutsThePage(): void
    {
        $account = $this->customAccount('42', '4210', 'Rent income');

        $this->categories->save(['category_name' => 'Bravo', 'category_description' => 'second', 'account_id' => $account], null, $this->router);
        $this->categories->save(['category_name' => 'Alpha', 'category_description' => 'first'], null, $this->router);

        $names = static fn (array $result): array => array_map(static fn (object $row): string => $row->category_name, $result['rows']);

        $this->assertSame(['Alpha', 'Bravo'], $names($this->categories->search('', 'category_name', 'asc', 25, 0)));
        $this->assertSame(['Bravo'], $names($this->categories->search('seco', 'category_name', 'asc', 25, 0)));
        $this->assertSame(['Bravo', 'Alpha'], $names($this->categories->search('', 'account', 'desc', 25, 0)));
        $this->assertSame(['Bravo'], $names($this->categories->search('', 'category_name', 'asc', 1, 1)));
        $this->assertSame(2, $this->categories->search('', 'nonsense', 'desc', 1, 0)['total']);
        $this->assertSame('4210', $this->categories->search('Bravo', 'category_name', 'asc', 25, 0)['rows'][0]->account_code);
    }

    public function testAll_ListsTheCategoriesInUseByName(): void
    {
        $this->categories->save(['category_name' => 'Zeta'], null, $this->clerkId);
        $this->categories->save(['category_name' => 'Alpha'], null, $this->clerkId);

        $this->assertSame(['Alpha', 'Zeta'], array_column($this->categories->all(), 'category_name'));
    }

    public function testMayAssign_NeedsThePermissionAndEveryBranch(): void
    {
        $other   = $this->createBranch();
        $limited = $this->employeeWithGrants([AccountingGuard::ROUTING_MANAGE], [$other]);
        $nobody  = $this->employeeWithGrants([]);

        $this->assertTrue($this->categories->mayAssign($this->router));
        $this->assertFalse($this->categories->mayAssign($limited));
        $this->assertFalse($this->categories->mayAssign($nobody));
    }
}
