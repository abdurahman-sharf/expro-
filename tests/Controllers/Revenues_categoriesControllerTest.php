<?php

namespace Tests\Controllers;

use App\Libraries\Accounting\AccountingGuard;
use App\Libraries\Accounting\RevenueCategories;
use CodeIgniter\Exceptions\PageNotFoundException;
use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\DatabaseTestTrait;
use CodeIgniter\Test\FeatureTestTrait;
use Config\Database;
use Tests\Support\AccountingFixtureTrait;
use Tests\Support\EmployeeFixtureTrait;
use Tests\Support\ExpenseAccountingFixtureTrait;

/**
 * The screens of the revenue categories: the list, the form with the account of the category that
 * only whoever may change the routing can choose, and the changes as POSTs.
 */
class Revenues_categoriesControllerTest extends CIUnitTestCase
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

        $this->startExpenses();
    }

    protected function tearDown(): void
    {
        $this->releaseOpenTransactions();
        $this->stopExpenses();

        parent::tearDown();
    }

    /**
     * @param list<string> $permissions
     */
    private function actAsWith(array $permissions): int
    {
        $personId = $this->employeeWithGrants($permissions);

        $this->withSession(['person_id' => $personId, 'menu_group' => 'home']);

        return $personId;
    }

    /**
     * @return array<string, mixed>
     */
    private function postJson(string $url, array $data = []): array
    {
        return json_decode($this->post($url, $data)->getJSON(), true);
    }

    public function testGetIndex_ShowsTheListToWhoeverHoldsTheModule(): void
    {
        $this->actAsWith(['revenues_categories']);

        $this->get('/revenues_categories')->assertStatus(200)->assertSee(lang('Revenues_categories.new'));
    }

    public function testGetIndex_RedirectsAUserWithoutTheModuleGrantAndIsNotFoundWhileAccountingIsOff(): void
    {
        $this->actAsWith(['revenues']);

        $this->get('/revenues_categories')->assertRedirect();

        $this->actAsWith(['revenues_categories']);
        $this->setConfigValue('accounting_enabled', '0');

        $this->expectException(PageNotFoundException::class);

        $this->get('/revenues_categories');
    }

    public function testGetSearch_ListsTheCategoriesWithTheirAccountsAndFiltersThem(): void
    {
        $this->actAsWith(['revenues_categories']);

        $categories = new RevenueCategories();
        $account    = $this->customAccount('42', '4210', 'Rent income');

        $categories->save(['category_name' => 'Rent', 'account_id' => $account], null, $this->employeeWithGrants([AccountingGuard::ROUTING_MANAGE]));
        $categories->save(['category_name' => 'Commissions'], null, $this->clerkId);

        $all  = json_decode($this->get('/revenues_categories/search?sort=category_name&order=asc')->getJSON(), true);
        $rent = json_decode($this->get('/revenues_categories/search?search=Rent')->getJSON(), true);

        $this->assertSame(2, $all['total']);
        $this->assertSame(['Commissions', 'Rent'], array_column($all['rows'], 'category_name'));
        $this->assertSame(lang('Revenues_categories.account_default'), $all['rows'][0]['account']);
        $this->assertSame('4210 - Rent income', $all['rows'][1]['account']);
        $this->assertSame(['Rent'], array_column($rent['rows'], 'category_name'));
    }

    public function testGetView_OffersTheAccountsOnlyToWhoMayChangeTheRouting(): void
    {
        $this->customAccount('42', '4210', 'Rent income');

        $this->actAsWith(['revenues_categories']);

        $locked = $this->get('/revenues_categories/view');

        $locked->assertSee('name="category_name"');
        $locked->assertSee('disabled');
        $locked->assertSee(lang('Revenues_categories.account_locked'));

        $this->actAsWith(['revenues_categories', AccountingGuard::ROUTING_MANAGE]);

        $open = $this->get('/revenues_categories/view');

        $open->assertSee('4210 - Rent income');
        $open->assertSee(lang('Revenues_categories.account_desc'));
    }

    public function testPostSave_SavesAndChangesACategoryAndSaysInWordsWhyItRefuses(): void
    {
        $this->actAsWith(['revenues_categories']);

        $added = $this->postJson('/revenues_categories/save', ['category_name' => 'Rent', 'category_description' => 'Stand rent']);

        $this->assertTrue($added['success']);
        $this->assertSame(lang('Revenues_categories.successful_adding'), $added['message']);

        $changed = $this->postJson('/revenues_categories/save/' . $added['id'], ['category_name' => 'Rents', 'category_description' => '']);

        $this->assertTrue($changed['success']);
        $this->assertSame('Rents', (new RevenueCategories())->find($added['id'])->category_name);

        $taken = $this->postJson('/revenues_categories/save', ['category_name' => 'Rents']);

        $this->assertFalse($taken['success']);
        $this->assertSame(lang('Accounting.error_revenue_category_name_taken'), $taken['message']);

        $empty = $this->postJson('/revenues_categories/save', ['category_name' => ' ']);

        $this->assertSame(lang('Accounting.error_revenue_category_name_invalid'), $empty['message']);
    }

    public function testPostSave_RefusesAnAccountWithoutThePermissionToChangeTheRouting(): void
    {
        $account = $this->customAccount('42', '4210', 'Rent income');

        $this->actAsWith(['revenues_categories']);

        $refused = $this->postJson('/revenues_categories/save', ['category_name' => 'Rent', 'account_id' => $account]);

        $this->assertFalse($refused['success']);
        $this->assertSame(lang('Accounting.error_permission_denied'), $refused['message']);
        $this->assertSame(0, $this->countRows('revenue_categories'));

        $this->actAsWith(['revenues_categories', AccountingGuard::ROUTING_MANAGE]);

        $this->assertTrue($this->postJson('/revenues_categories/save', ['category_name' => 'Rent', 'account_id' => $account])['success']);
    }

    public function testPostDelete_DeletesTheCategoriesAndAGetNeverDoes(): void
    {
        $this->actAsWith(['revenues_categories']);

        $id = (new RevenueCategories())->save(['category_name' => 'Rent'], null, $this->clerkId);

        try {
            $this->get('/revenues_categories/delete');
            $this->fail('A GET deleted categories');
        } catch (PageNotFoundException) {
            $this->addToAssertionCount(1);
        }

        $this->assertNotNull((new RevenueCategories())->find($id));

        $deleted = $this->postJson('/revenues_categories/delete', ['ids' => [$id]]);

        $this->assertTrue($deleted['success']);
        $this->assertNull((new RevenueCategories())->find($id));
    }

    public function testGetRow_ShowsOneCategoryAndIsNotFoundForADeletedOne(): void
    {
        $this->actAsWith(['revenues_categories']);

        $id = (new RevenueCategories())->save(['category_name' => 'Rent'], null, $this->clerkId);

        $this->assertSame('Rent', json_decode($this->get('/revenues_categories/row/' . $id)->getJSON(), true)['category_name']);

        (new RevenueCategories())->delete([$id], $this->clerkId);

        $this->expectException(PageNotFoundException::class);

        $this->get('/revenues_categories/row/' . $id);
    }
}
