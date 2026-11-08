<?php

namespace Tests\Controllers;

use App\Libraries\MasterData\LookupKind;
use App\Libraries\MasterData\NamedLookup;
use CodeIgniter\Exceptions\PageNotFoundException;
use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\DatabaseTestTrait;
use CodeIgniter\Test\FeatureTestTrait;
use Config\Database;
use Tests\Support\AccountingFixtureTrait;
use Tests\Support\EmployeeFixtureTrait;

/**
 * The screen of the classifications of customers. It is the screen of a short list of names that
 * the brands of products share, so the refusals and the table are proved here and the module that guards
 * the screen of the brands in its own test.
 *
 * @internal
 */
final class Customer_classificationsControllerTest extends CIUnitTestCase
{
    use DatabaseTestTrait;
    use FeatureTestTrait;
    use AccountingFixtureTrait;
    use EmployeeFixtureTrait;

    protected $migrate     = true;
    protected $migrateOnce = true;
    protected $refresh     = false;
    protected $namespace;

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

        $db->table('customers')->update(['classification_id' => null]);
        $db->query('DELETE FROM ' . $db->prefixTable('customer_classifications'));
        $db->table('accounting_audit_log')->where('entity_type', 'customer_classification')->delete();
        $this->removeInsertedEntities();

        parent::tearDown();
    }

    private function lookup(): NamedLookup
    {
        return new NamedLookup(LookupKind::CustomerClassification);
    }

    /**
     * @return array<string, mixed>
     */
    private function answer(string $path, array $post = []): array
    {
        return json_decode($this->post($path, $post)->getJSON(), true);
    }

    public function testGetIndex_ShowsTheTableAndTheButtonToAddAClassification(): void
    {
        $page = $this->get('/customer_classifications');

        $page->assertStatus(200);
        $page->assertSee(lang('Lookups.customer_classification_new'));
        $this->assertStringContainsString('<h2 id="lookup_title" class="h5 float-start mb-0">' . lang('Lookups.customer_classification_title') . '</h2>', $page->getBody());
        $this->assertStringContainsString('customer_classifications', $page->getBody());
    }

    public function testPostSave_AddsAClassificationAndUpdatesIt(): void
    {
        $added = $this->answer('/customer_classifications/save', ['name' => 'Wholesale', 'status' => '1']);

        $this->assertTrue($added['success']);
        $this->assertSame('Wholesale', $this->lookup()->get((int) $added['id'])->name);

        $updated = $this->answer('/customer_classifications/save/' . $added['id'], ['name' => 'Retail', 'status' => '0']);

        $this->assertTrue($updated['success']);
        $this->assertSame(['Retail', '0'], [$this->lookup()->get((int) $added['id'])->name, (string) $this->lookup()->get((int) $added['id'])->status]);
    }

    public function testPostSave_RejectsABlankNameAndANameAnotherClassificationHas(): void
    {
        $this->lookup()->save('Wholesale', true, null, 1);

        $blank = $this->answer('/customer_classifications/save', ['name' => '  ', 'status' => '1']);
        $taken = $this->answer('/customer_classifications/save', ['name' => 'Wholesale', 'status' => '1']);

        $this->assertFalse($blank['success']);
        $this->assertFalse($taken['success']);
        $this->assertStringContainsString('Wholesale', $taken['message']);
        $this->assertCount(1, $this->lookup()->all());
    }

    public function testPostSave_TreatsAnyStatusButZeroAsActive(): void
    {
        $added = $this->answer('/customer_classifications/save', ['name' => 'Wholesale', 'status' => '5']);

        $this->assertSame('1', (string) $this->lookup()->get((int) $added['id'])->status);
    }

    public function testPostStatus_DeactivatesAndActivatesAClassification(): void
    {
        $id = $this->lookup()->save('Wholesale', true, null, 1);

        $this->assertTrue($this->answer("/customer_classifications/status/{$id}/0")['success']);
        $this->assertSame('0', (string) $this->lookup()->get($id)->status);
        $this->assertTrue($this->answer("/customer_classifications/status/{$id}/1")['success']);
        $this->assertSame('1', (string) $this->lookup()->get($id)->status);
    }

    public function testPostStatus_NotFoundForAClassificationThatDoesNotExist(): void
    {
        $this->expectException(PageNotFoundException::class);

        $this->post('/customer_classifications/status/987654/0');
    }

    public function testPostDelete_RemovesAnUnusedClassificationAndRefusesOneACustomerCarries(): void
    {
        $free = $this->lookup()->save('Free', true, null, 1);
        $held = $this->lookup()->save('Held', true, null, 1);
        $this->accountingDb()->table('customers')->where('person_id', $this->insertCustomer())->update(['classification_id' => $held]);

        $refused = $this->answer('/customer_classifications/delete', ['ids' => [(string) $free, (string) $held]]);

        $this->assertFalse($refused['success']);
        $this->assertStringContainsString('Held', $refused['message']);
        $this->assertNotNull($this->lookup()->get($free));

        $removed = $this->answer('/customer_classifications/delete', ['ids' => [(string) $free]]);

        $this->assertTrue($removed['success']);
        $this->assertNull($this->lookup()->get($free));
    }

    public function testGetSearch_ListsTheClassificationsWithTheNumberOfCustomersThatCarryThem(): void
    {
        $held = $this->lookup()->save('Held', true, null, 1);
        $this->lookup()->save('Alone', false, null, 1);
        $this->accountingDb()->table('customers')->where('person_id', $this->insertCustomer())->update(['classification_id' => $held]);

        $found = json_decode($this->get('/customer_classifications/search', ['search' => '', 'sort' => 'name', 'order' => 'asc', 'limit' => '10', 'offset' => '0'])->getJSON(), true);

        $this->assertSame(2, $found['total']);
        $this->assertSame(['Alone', 'Held'], array_column($found['rows'], 'name'));
        $this->assertSame([0, 1], array_column($found['rows'], 'uses'));
        $this->assertStringContainsString(lang('Lookups.inactive'), $found['rows'][0]['status']);
    }

    public function testGetSearch_NarrowsByNameAndKeepsToTheLimitAndTheOffset(): void
    {
        foreach (['Alpha', 'Beta', 'Gamma'] as $name) {
            $this->lookup()->save($name, true, null, 1);
        }

        $named = json_decode($this->get('/customer_classifications/search', ['search' => 'amm', 'limit' => '10', 'offset' => '0'])->getJSON(), true);
        $paged = json_decode($this->get('/customer_classifications/search', ['search' => '', 'sort' => 'name', 'order' => 'asc', 'limit' => '1', 'offset' => '1'])->getJSON(), true);

        $this->assertSame(['Gamma'], array_column($named['rows'], 'name'));
        $this->assertSame(3, $paged['total']);
        $this->assertSame(['Beta'], array_column($paged['rows'], 'name'));
    }

    public function testGetView_ShowsTheFormOfANewClassificationAndOfOneThatExists(): void
    {
        $id = $this->lookup()->save('Wholesale', false, null, 1);

        $this->get('/customer_classifications/view')->assertStatus(200);
        $this->get('/customer_classifications/view/' . $id)->assertSee('Wholesale');
    }

    public function testGetView_NotFoundForAClassificationThatDoesNotExist(): void
    {
        $this->expectException(PageNotFoundException::class);

        $this->get('/customer_classifications/view/987654');
    }

    public function testGetRow_ReturnsTheRowOfOneClassification(): void
    {
        $id = $this->lookup()->save('Wholesale', true, null, 1);

        $row = json_decode($this->get('/customer_classifications/row/' . $id)->getJSON(), true);

        $this->assertSame(['Wholesale', 0], [$row['name'], $row['uses']]);
    }

    public function testGetRow_NotFoundForAClassificationThatDoesNotExist(): void
    {
        $this->expectException(PageNotFoundException::class);

        $this->get('/customer_classifications/row/987654');
    }

    public function testTheScreen_RedirectsAnEmployeeWhoDoesNotHoldTheCustomersModule(): void
    {
        $stranger = $this->createEmployee(grants: [['permission_id' => 'items', 'menu_group' => 'home']]);
        $this->withSession(['person_id' => $stranger, 'menu_group' => 'home']);

        $this->assertTrue($this->get('/customer_classifications')->isRedirect());
        $this->assertTrue($this->post('/customer_classifications/save', ['name' => 'Sneaky', 'status' => '1'])->isRedirect());
        $this->assertSame([], $this->lookup()->all());
    }

    public function testTheScreen_IsOpenToAnEmployeeWhoHoldsTheCustomersModule(): void
    {
        $manager = $this->createEmployee(grants: [['permission_id' => 'customers', 'menu_group' => 'home']]);
        $this->withSession(['person_id' => $manager, 'menu_group' => 'home']);

        $result = $this->answer('/customer_classifications/save', ['name' => 'Wholesale', 'status' => '1']);

        $this->assertTrue($result['success']);
        $this->assertSame($manager, (int) $this->accountingDb()->table('customer_classifications')->get()->getRow()->created_by);
    }
}
