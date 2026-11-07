<?php

namespace Tests\Controllers;

use App\Libraries\MasterData\LookupKind;
use App\Libraries\MasterData\NamedLookup;
use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\DatabaseTestTrait;
use CodeIgniter\Test\FeatureTestTrait;
use Config\Database;
use Tests\Support\AccountingFixtureTrait;
use Tests\Support\EmployeeFixtureTrait;
use Tests\Support\ItemFixtureTrait;

/**
 * The screen of the brands of products: the module that guards it, and the refusal to delete a brand an
 * item carries. The rest of the screen is the one the classifications of customers use.
 *
 * @internal
 */
final class Item_brandsControllerTest extends CIUnitTestCase
{
    use DatabaseTestTrait;
    use FeatureTestTrait;
    use AccountingFixtureTrait;
    use EmployeeFixtureTrait;
    use ItemFixtureTrait;

    protected $migrate     = true;
    protected $migrateOnce = true;
    protected $refresh     = false;
    protected $namespace;

    /** @var list<int> */
    private array $itemIds = [];

    public static function setUpBeforeClass(): void
    {
        $seeder = Database::seeder('tests');
        $seeder->call('TestDatabaseBootstrapSeeder');
    }

    protected function setUp(): void
    {
        parent::setUp();

        $this->withSession(['person_id' => 1, 'menu_group' => 'office']);
    }

    protected function tearDown(): void
    {
        $db = $this->accountingDb();

        $db->table('items')->update(['brand_id' => null]);

        foreach ($this->itemIds as $itemId) {
            $db->table('item_quantities')->where('item_id', $itemId)->delete();
            $db->table('items')->where('item_id', $itemId)->delete();
        }

        $this->itemIds = [];

        $db->query('DELETE FROM ' . $db->prefixTable('item_brands'));
        $db->table('accounting_audit_log')->where('entity_type', 'item_brand')->delete();

        parent::tearDown();
    }

    private function lookup(): NamedLookup
    {
        return new NamedLookup(LookupKind::ItemBrand);
    }

    /**
     * @return array<string, mixed>
     */
    private function answer(string $path, array $post = []): array
    {
        return json_decode($this->post($path, $post)->getJSON(), true);
    }

    public function testGetIndex_ShowsTheTableAndTheButtonToAddABrand(): void
    {
        $page = $this->get('/item_brands');

        $page->assertStatus(200);
        $page->assertSee(lang('Lookups.item_brand_new'));
    }

    public function testPostSave_AddsABrandAndWritesItToTheAuditLog(): void
    {
        $added = $this->answer('/item_brands/save', ['name' => 'Acme', 'status' => '1']);

        $this->assertTrue($added['success']);
        $this->assertSame('Acme', $this->lookup()->get((int) $added['id'])->name);
        $this->assertSame(1, $this->accountingDb()->table('accounting_audit_log')->where(['entity_type' => 'item_brand', 'entity_id' => $added['id'], 'action' => 'lookup_created'])->countAllResults());
    }

    public function testPostDelete_RefusesABrandAnItemCarriesAndRemovesAFreeOne(): void
    {
        $free   = $this->lookup()->save('Free', true, null, 1);
        $held   = $this->lookup()->save('Held', true, null, 1);
        $itemId = $this->itemIds[] = $this->createTestItem();
        $this->accountingDb()->table('items')->where('item_id', $itemId)->update(['brand_id' => $held]);

        $refused = $this->answer('/item_brands/delete', ['ids' => [(string) $held]]);

        $this->assertFalse($refused['success']);
        $this->assertNotNull($this->lookup()->get($held));
        $this->assertTrue($this->answer('/item_brands/delete', ['ids' => [(string) $free]])['success']);
        $this->assertNull($this->lookup()->get($free));
    }

    public function testGetSearch_ListsTheBrandsWithTheNumberOfItemsThatCarryThem(): void
    {
        $held   = $this->lookup()->save('Held', true, null, 1);
        $itemId = $this->itemIds[] = $this->createTestItem();
        $this->accountingDb()->table('items')->where('item_id', $itemId)->update(['brand_id' => $held]);

        $found = json_decode($this->get('/item_brands/search', ['search' => '', 'sort' => 'uses', 'order' => 'desc', 'limit' => '10', 'offset' => '0'])->getJSON(), true);

        $this->assertSame([['Held', 1]], array_map(static fn (array $row): array => [$row['name'], $row['uses']], $found['rows']));
    }

    public function testTheScreen_RedirectsAnEmployeeWhoDoesNotHoldTheItemsModuleEvenWithTheCustomersModule(): void
    {
        $stranger = $this->createEmployee(grants: [['permission_id' => 'customers', 'menu_group' => 'home']]);
        $this->withSession(['person_id' => $stranger, 'menu_group' => 'home']);

        $this->assertTrue($this->get('/item_brands')->isRedirect());
        $this->assertTrue($this->post('/item_brands/save', ['name' => 'Sneaky', 'status' => '1'])->isRedirect());
        $this->assertSame([], $this->lookup()->all());
    }

    public function testTheScreen_IsOpenToAnEmployeeWhoHoldsTheItemsModule(): void
    {
        $manager = $this->createEmployee(grants: [['permission_id' => 'items', 'menu_group' => 'home'], ['permission_id' => 'items_stock', 'menu_group' => 'home']]);
        $this->withSession(['person_id' => $manager, 'menu_group' => 'home']);

        $this->assertTrue($this->answer('/item_brands/save', ['name' => 'Acme', 'status' => '1'])['success']);
    }
}
