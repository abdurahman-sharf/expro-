<?php

namespace Tests\Models;

use App\Libraries\Accounting\AccountingError;
use App\Libraries\Accounting\AccountingException;
use App\Libraries\Accounting\AccountingGuard;
use App\Models\Expense_category;
use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\DatabaseTestTrait;
use Config\Database;
use Tests\Support\AccountingFixtureTrait;
use Tests\Support\EmployeeFixtureTrait;
use Tests\Support\ExpenseAccountingFixtureTrait;

/**
 * The account of an expense category as the category model saves it: together with the rest of the
 * category or not at all, only by who may change the routing, and not at all while accounting is off.
 */
class Expense_categoryTest extends CIUnitTestCase
{
    use DatabaseTestTrait;
    use AccountingFixtureTrait;
    use EmployeeFixtureTrait;
    use ExpenseAccountingFixtureTrait;

    protected $migrate     = true;
    protected $migrateOnce = true;
    protected $refresh     = false;
    protected $namespace   = null;

    private Expense_category $categories;
    private int $routerId;

    public static function setUpBeforeClass(): void
    {
        $seeder = Database::seeder('tests');
        $seeder->call('TestDatabaseBootstrapSeeder');
    }

    protected function setUp(): void
    {
        parent::setUp();

        $this->startExpenses();

        $this->categories = model(Expense_category::class);
        $this->routerId   = $this->employeeWithGrants([AccountingGuard::ROUTING_MANAGE]);
    }

    protected function tearDown(): void
    {
        $this->stopExpenses();

        parent::tearDown();
    }

    /**
     * @param array<string, mixed> $data
     */
    private function save(array $data, int $categoryId = NEW_ENTRY, ?int $employeeId = null): ?int
    {
        $saved = $this->categories->save_value($data, $categoryId, $employeeId ?? $this->routerId);

        if ($saved && $categoryId === NEW_ENTRY) {
            $this->categoryIds[] = (int) $data['expense_category_id'];
        }

        return $saved ? (int) $data['expense_category_id'] : null;
    }

    private function accountOf(int $categoryId): ?int
    {
        $row = $this->accountingDb()->table('expense_categories')->where('expense_category_id', $categoryId)->get()->getRow();

        return $row->account_id === null ? null : (int) $row->account_id;
    }

    public function testSaveValue_SavesTheAccountWithTheCategoryWhenAccountingIsOn(): void
    {
        $account = $this->customAccount('5', '5981', 'Marketing', false);

        $categoryId = $this->save(['category_name' => 'Ads ' . uniqid(), 'category_description' => '', 'account_id' => $account]);

        $this->assertNotNull($categoryId);
        $this->assertSame($account, $this->accountOf($categoryId));
        $this->assertNull($this->categories->accountingFailure());
    }

    public function testSaveValue_IgnoresTheAccountWhileAccountingIsOff(): void
    {
        $this->setConfigValue('accounting_enabled', '0');

        $account    = $this->customAccount('5', '5982', 'Marketing', false);
        $categoryId = $this->save(['category_name' => 'Ads ' . uniqid(), 'category_description' => '', 'account_id' => $account]);

        $this->assertNotNull($categoryId);
        $this->assertNull($this->accountOf($categoryId));
        $this->assertSame(0, $this->accountingDb()->table('accounting_audit_log')->where('entity_type', 'expense_category')->countAllResults());
    }

    public function testSaveValue_KeepsTheAccountOfACategoryWhenNoAccountIsSent(): void
    {
        $account  = $this->customAccount('5', '5983', 'Marketing', false);
        $category = $this->category('Ads', $account);

        $this->assertSame($category, $this->save(['category_name' => 'Renamed ' . uniqid(), 'category_description' => 'words'], $category));
        $this->assertSame($account, $this->accountOf($category));
    }

    public function testSaveValue_RollsTheCategoryBackAndSaysWhyWhenTheAccountIsRefused(): void
    {
        $category = $this->category('Ads');
        $name     = $this->accountingDb()->table('expense_categories')->where('expense_category_id', $category)->get()->getRow()->category_name;

        $this->assertNull($this->save(['category_name' => 'Renamed ' . uniqid(), 'category_description' => '', 'account_id' => $this->accountId('sales_revenue')], $category));

        $failure = $this->categories->accountingFailure();

        $this->assertInstanceOf(AccountingException::class, $failure);
        $this->assertSame(AccountingError::ExpenseCategoryAccountInvalid, $failure->error);
        $this->assertSame($name, $this->accountingDb()->table('expense_categories')->where('expense_category_id', $category)->get()->getRow()->category_name);
    }

    public function testSaveValue_RefusesTheAccountOfAnEmployeeWhoMayNotChangeTheRouting(): void
    {
        $account = $this->customAccount('5', '5984', 'Marketing', false);
        $before  = $this->countRows('expense_categories');

        $this->assertNull($this->save(['category_name' => 'Ads ' . uniqid(), 'category_description' => '', 'account_id' => $account], NEW_ENTRY, $this->employeeWithGrants([])));
        $this->assertSame(AccountingError::PermissionDenied, $this->categories->accountingFailure()->error);
        $this->assertSame($before, $this->countRows('expense_categories'));
    }
}
