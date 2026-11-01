<?php

namespace Tests\Controllers;

use App\Models\Price_list;
use CodeIgniter\Exceptions\PageNotFoundException;
use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\DatabaseTestTrait;
use CodeIgniter\Test\FeatureTestTrait;
use Config\Database;
use Tests\Support\AccountingFixtureTrait;
use Tests\Support\EmployeeFixtureTrait;
use Tests\Support\ItemFixtureTrait;

/**
 * The screen of the price lists: the list, the form with a name and a status, the changes as POSTs,
 * the deactivation of a list that is in use and the refusal to delete it.
 */
class Price_listsControllerTest extends CIUnitTestCase
{
    use DatabaseTestTrait;
    use FeatureTestTrait;
    use AccountingFixtureTrait;
    use EmployeeFixtureTrait;
    use ItemFixtureTrait;

    protected $migrate     = true;
    protected $migrateOnce = true;
    protected $refresh     = false;
    protected $namespace   = null;

    private Price_list $lists;

    public static function setUpBeforeClass(): void
    {
        $seeder = Database::seeder('tests');
        $seeder->call('TestDatabaseBootstrapSeeder');
    }

    protected function setUp(): void
    {
        parent::setUp();

        $this->lists = model(Price_list::class);
    }

    protected function tearDown(): void
    {
        $db = $this->accountingDb();

        $db->table('customers')->update(['price_list_id' => null]);
        $db->table('price_list_items')->where('price_list_id >', 0)->delete();
        $db->table('price_lists')->where('price_list_id >', 0)->delete();
        $db->table('accounting_audit_log')->where('entity_type', 'price_list')->delete();

        $this->removeInsertedEntities();

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

    public function testGetIndex_ShowsTheListToWhoeverHoldsTheModuleWhileAccountingIsOff(): void
    {
        $this->setConfigValue('accounting_enabled', '0');
        $this->actAsWith(['price_lists']);

        $view = $this->get('/price_lists');

        $view->assertStatus(200);
        $view->assertSee(lang('Price_lists.new'));
    }

    public function testGetIndex_RedirectsAUserWithoutTheModuleGrant(): void
    {
        $this->actAsWith(['receipt_vouchers']);

        $this->get('/price_lists')->assertRedirect();
    }

    public function testPostSave_RedirectsAUserWithoutTheModuleGrantAndCreatesNothing(): void
    {
        $this->actAsWith(['customers']);

        $this->post('/price_lists/save', ['name' => 'Sneaky', 'status' => '1'])->assertRedirect();

        $this->assertSame([], $this->lists->allLists());
    }

    public function testGetSearch_ListsTheListsWithTheirStatusAndCountsAndFiltersThem(): void
    {
        $this->actAsWith(['price_lists']);

        $wholesale = $this->lists->saveList('Wholesale', true, null, null);
        $this->lists->saveList('Retired', false, null, null);

        $this->lists->saveItemPrices($this->createTestItem(), [$wholesale => '4'], null);
        $this->lists->assignTo($this->insertCustomer(), $wholesale, null);

        $all   = json_decode($this->get('/price_lists/search?sort=name&order=asc')->getJSON(), true);
        $found = json_decode($this->get('/price_lists/search?search=whole')->getJSON(), true);

        $this->assertSame(2, $all['total']);
        $this->assertSame(['Retired', 'Wholesale'], array_column($all['rows'], 'name'));
        $this->assertSame([0, 1], array_column($all['rows'], 'customers'));
        $this->assertSame([0, 1], array_column($all['rows'], 'items'));
        $this->assertStringContainsString(lang('Price_lists.inactive'), $all['rows'][0]['status']);
        $this->assertStringContainsString(lang('Price_lists.active'), $all['rows'][1]['status']);
        $this->assertSame(['Wholesale'], array_column($found['rows'], 'name'));
    }

    public function testGetView_ShowsAnEmptyFormForANewListAndTheNameAndStatusOfAnExistingOne(): void
    {
        $this->actAsWith(['price_lists']);

        $listId = $this->lists->saveList('Wholesale', false, null, null);

        $new = $this->get('/price_lists/view');

        $new->assertStatus(200);
        $new->assertSee('name="name"');
        $new->assertSee('name="status"');
        $new->assertSee(lang('Price_lists.prices_on_item_form'));

        $edit = $this->get('/price_lists/view/' . $listId);

        $edit->assertSee('value="Wholesale"');
        $this->assertMatchesRegularExpression('/<option value="0"[^>]*selected/', $edit->getBody());
    }

    public function testGetView_IsNotFoundForAListThatDoesNotExist(): void
    {
        $this->actAsWith(['price_lists']);

        $this->expectException(PageNotFoundException::class);

        $this->get('/price_lists/view/987654');
    }

    public function testPostSave_CreatesAListAndEditsItAndWritesBothInTheAuditLog(): void
    {
        $manager = $this->actAsWith(['price_lists']);

        $created = $this->postJson('/price_lists/save', ['name' => 'Wholesale', 'status' => '1']);

        $this->assertTrue($created['success']);
        $this->assertSame(lang('Price_lists.successful_adding'), $created['message']);

        $listId = (int) $created['id'];

        $edited = $this->postJson('/price_lists/save/' . $listId, ['name' => 'Wholesale plus', 'status' => '0']);

        $this->assertTrue($edited['success']);
        $this->assertSame(lang('Price_lists.successful_updating'), $edited['message']);
        $this->assertSame('Wholesale plus', $this->lists->getList($listId)->name);
        $this->assertSame(0, (int) $this->lists->getList($listId)->status);

        $actions = array_column($this->accountingDb()->table('accounting_audit_log')->where('entity_type', 'price_list')->orderBy('audit_id', 'ASC')->get()->getResultArray(), 'employee_id', 'action');

        $this->assertSame([$manager, $manager], [(int) $actions['price_list_created'], (int) $actions['price_list_changed']]);
    }

    public function testPostSave_DefaultsToActiveWhenNoStatusIsSent(): void
    {
        $this->actAsWith(['price_lists']);

        $created = $this->postJson('/price_lists/save', ['name' => 'Wholesale']);

        $this->assertSame(1, (int) $this->lists->getList((int) $created['id'])->status);
    }

    public function testPostSave_SaysWhyAListWasRefusedAndSavesNothing(): void
    {
        $this->actAsWith(['price_lists']);
        $this->lists->saveList('Wholesale', true, null, null);

        $empty     = $this->postJson('/price_lists/save', ['name' => '  ', 'status' => '1']);
        $duplicate = $this->postJson('/price_lists/save', ['name' => 'wholesale', 'status' => '1']);

        $this->assertFalse($empty['success']);
        $this->assertSame(lang('Accounting.error_price_list_name_invalid'), $empty['message']);
        $this->assertFalse($duplicate['success']);
        $this->assertSame(lang('Accounting.error_price_list_name_taken'), $duplicate['message']);
        $this->assertCount(1, $this->lists->allLists());
    }

    public function testPostSave_IsNotFoundForAListThatDoesNotExist(): void
    {
        $this->actAsWith(['price_lists']);

        $this->expectException(PageNotFoundException::class);

        $this->post('/price_lists/save/987654', ['name' => 'Ghost', 'status' => '1']);
    }

    public function testPostStatus_DeactivatesAListThatIsInUseAndActivatesItAgain(): void
    {
        $this->actAsWith(['price_lists']);

        $listId = $this->lists->saveList('Wholesale', true, null, null);

        $this->lists->assignTo($this->insertCustomer(), $listId, null);

        $off = $this->postJson("/price_lists/status/{$listId}/0");

        $this->assertTrue($off['success']);
        $this->assertSame(lang('Price_lists.successful_deactivated'), $off['message']);
        $this->assertSame(0, (int) $this->lists->getList($listId)->status);

        $on = $this->postJson("/price_lists/status/{$listId}/1");

        $this->assertSame(lang('Price_lists.successful_activated'), $on['message']);
        $this->assertSame(1, (int) $this->lists->getList($listId)->status);
    }

    public function testPostDelete_DeletesAnUnusedListAndRefusesOneThatIsInUse(): void
    {
        $this->actAsWith(['price_lists']);

        $free = $this->lists->saveList('Free', true, null, null);
        $held = $this->lists->saveList('Held', true, null, null);

        $this->lists->assignTo($this->insertCustomer(), $held, null);

        $refused = $this->postJson('/price_lists/delete', ['ids' => [(string) $held]]);

        $this->assertFalse($refused['success']);
        $this->assertSame(lang('Accounting.error_price_list_in_use'), $refused['message']);
        $this->assertNotNull($this->lists->getList($held));

        $deleted = $this->postJson('/price_lists/delete', ['ids' => [(string) $free]]);

        $this->assertTrue($deleted['success']);
        $this->assertSame(lang('Price_lists.successful_deleted', [1]), $deleted['message']);
        $this->assertNull($this->lists->getList($free));
    }

    public function testGetRow_GivesTheRowOfAListAndIsNotFoundForOneThatDoesNotExist(): void
    {
        $this->actAsWith(['price_lists']);

        $listId = $this->lists->saveList('Wholesale', true, null, null);
        $row    = json_decode($this->get('/price_lists/row/' . $listId)->getJSON(), true);

        $this->assertSame('Wholesale', $row['name']);
        $this->assertSame($listId, $row['price_list_id']);
        $this->assertStringContainsString('price_lists/view/' . $listId, $row['edit']);

        $this->expectException(PageNotFoundException::class);

        $this->get('/price_lists/row/987654');
    }
}
