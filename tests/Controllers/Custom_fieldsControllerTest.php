<?php

namespace Tests\Controllers;

use App\Libraries\Documents\CustomFields;
use CodeIgniter\Exceptions\PageNotFoundException;
use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\DatabaseTestTrait;
use CodeIgniter\Test\FeatureTestTrait;
use Config\Database;
use Tests\Support\AccountingFixtureTrait;
use Tests\Support\EmployeeFixtureTrait;

/**
 * The screen of the extra fields: who may open it, who may change a definition, what a definition
 * refused says, and that the names typed are shown as text.
 */
class Custom_fieldsControllerTest extends CIUnitTestCase
{
    use DatabaseTestTrait;
    use FeatureTestTrait;
    use AccountingFixtureTrait;
    use EmployeeFixtureTrait;

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

        $this->wipe();
    }

    protected function tearDown(): void
    {
        $this->wipe();
        $this->removeInsertedEntities();

        parent::tearDown();
    }

    private function wipe(): void
    {
        $db = Database::connect();
        $db->query('SET FOREIGN_KEY_CHECKS = 0');
        $db->table('custom_field_values')->truncate();
        $db->table('custom_fields')->truncate();
        $db->query('SET FOREIGN_KEY_CHECKS = 1');
        $db->table('accounting_audit_log')->where('entity_type', 'custom_field')->delete();
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

    private function manager(): int
    {
        return $this->actAsWith(['custom_fields', CustomFields::PERMISSION]);
    }

    /**
     * @return array<string, mixed>
     */
    private function postJson(string $url, array $data = []): array
    {
        return json_decode($this->post($url, $data)->getJSON(), true);
    }

    public function testGetIndex_ShowsTheScreenAndTheNewFieldButtonToAnEmployeeWhoMayManageTheFields(): void
    {
        $this->manager();

        $response = $this->get('/custom_fields');

        $response->assertStatus(200);
        $response->assertSee(lang('Custom_fields.new'));
        $response->assertSee(lang('Custom_fields.manage_note'));
    }

    public function testGetIndex_ShowsNoNewFieldButtonToAnEmployeeWhoHoldsOnlyTheModule(): void
    {
        $this->actAsWith(['custom_fields']);

        $response = $this->get('/custom_fields');

        $response->assertStatus(200);
        $response->assertDontSee(lang('Custom_fields.new'));
    }

    public function testGetIndex_RedirectsAnEmployeeWithoutTheModule(): void
    {
        $this->actAsWith(['expenses']);

        $this->get('/custom_fields')->assertRedirect();
    }

    public function testGetSearch_ListsEveryFieldOfBothKindsWithTheOnesPutOutOfUseAndSearchesByName(): void
    {
        $this->manager();
        $fields = new CustomFields();
        $colour = $fields->create('item', ['name' => 'Colour', 'type' => 'text'], null);
        $fields->create('sale', ['name' => 'Reference', 'type' => 'text', 'required' => '1', 'printable' => '1'], null);
        $fields->setActive($colour, false, null);

        $all  = json_decode($this->get('/custom_fields/search')->getJSON(), true);
        $some = json_decode($this->get('/custom_fields/search', ['search' => 'refer'])->getJSON(), true);

        $this->assertSame(2, $all['total']);
        $this->assertSame(['Colour', 'Reference'], array_column($all['rows'], 'name'));
        $this->assertSame(lang('Custom_fields.entity_item'), $all['rows'][0]['entity']);
        $this->assertStringContainsString(lang('Custom_fields.inactive'), $all['rows'][0]['status']);
        $this->assertSame(lang('Common.yes'), $all['rows'][1]['required']);
        $this->assertSame(1, $some['total']);
    }

    public function testPostSave_CreatesAFieldAndAuditsItWhenTheEmployeeHoldsThePermission(): void
    {
        $personId = $this->manager();

        $result = $this->postJson('/custom_fields/save', ['entity' => 'item', 'name' => 'Colour', 'type' => 'select', 'options' => "Red\nBlue", 'required' => '1', 'printable' => '1']);

        $this->assertTrue($result['success']);
        $this->assertSame(lang('Custom_fields.successful_adding'), $result['message']);

        $field = (new CustomFields())->find($result['id']);

        $this->assertSame('Colour', $field->name);
        $this->assertSame(['Red', 'Blue'], $field->options);
        $this->assertSame($personId, (int) Database::connect()->table('accounting_audit_log')->where('action', 'custom_field_created')->get()->getRow()->employee_id);
    }

    public function testPostSave_RefusesAnEmployeeWhoHoldsOnlyTheModuleAndCreatesNothing(): void
    {
        $this->actAsWith(['custom_fields']);

        $result = $this->postJson('/custom_fields/save', ['entity' => 'item', 'name' => 'Colour', 'type' => 'text']);

        $this->assertFalse($result['success']);
        $this->assertSame(lang('Documents.error_permission_denied'), $result['message']);
        $this->assertSame([], (new CustomFields())->definitions('item', false));
    }

    public function testPostSave_RedirectsAnEmployeeWithoutTheModuleAndCreatesNothing(): void
    {
        $this->actAsWith([CustomFields::PERMISSION]);

        $this->post('/custom_fields/save', ['entity' => 'item', 'name' => 'Colour', 'type' => 'text'])->assertRedirect();

        $this->assertSame([], (new CustomFields())->definitions('item', false));
    }

    public function testPostSave_SaysWhyAFieldIsRefused(): void
    {
        $this->manager();
        (new CustomFields())->create('item', ['name' => 'Colour', 'type' => 'text'], null);

        $name     = $this->postJson('/custom_fields/save', ['entity' => 'item', 'name' => '', 'type' => 'text']);
        $taken    = $this->postJson('/custom_fields/save', ['entity' => 'item', 'name' => 'colour', 'type' => 'text']);
        $type     = $this->postJson('/custom_fields/save', ['entity' => 'item', 'name' => 'Size', 'type' => 'map']);
        $options  = $this->postJson('/custom_fields/save', ['entity' => 'item', 'name' => 'Size', 'type' => 'select', 'options' => '']);
        $entity   = $this->postJson('/custom_fields/save', ['entity' => 'customer', 'name' => 'Size', 'type' => 'text']);

        $this->assertSame(lang('Documents.error_custom_field_name'), $name['message']);
        $this->assertSame(lang('Documents.error_custom_field_name_taken'), $taken['message']);
        $this->assertSame(lang('Documents.error_custom_field_type'), $type['message']);
        $this->assertSame(lang('Documents.error_custom_field_options'), $options['message']);
        $this->assertSame(lang('Documents.error_custom_field_entity'), $entity['message']);
        $this->assertCount(1, (new CustomFields())->definitions('item', false));
    }

    public function testPostSave_UpdatesAFieldAndRefusesToChangeTheTypeOfOneThatHoldsAValue(): void
    {
        $this->manager();
        $fields = new CustomFields();
        $id     = $fields->create('item', ['name' => 'Weight', 'type' => 'text'], null);

        $renamed = $this->postJson('/custom_fields/save/' . $id, ['name' => 'Net weight', 'type' => 'text', 'printable' => '1']);

        $this->assertTrue($renamed['success']);
        $this->assertSame('Net weight', $fields->find($id)->name);
        $this->assertTrue($fields->find($id)->printable);

        $fields->save('item', 5, [$id => 'heavy']);
        $changed = $this->postJson('/custom_fields/save/' . $id, ['name' => 'Net weight', 'type' => 'number']);

        $this->assertFalse($changed['success']);
        $this->assertSame(lang('Documents.error_custom_field_type_locked'), $changed['message']);
    }

    public function testPostSave_SwitchesRequiredAndPrintableOffWhenTheFormSendsThemUnticked(): void
    {
        $this->manager();
        $fields = new CustomFields();
        $id     = $fields->create('sale', ['name' => 'PO number', 'type' => 'text', 'required' => '1', 'printable' => '1'], null);

        $result = $this->postJson('/custom_fields/save/' . $id, ['name' => 'PO number', 'type' => 'text', 'required' => '0', 'printable' => '0']);

        $this->assertTrue($result['success']);
        $this->assertFalse($fields->find($id)->required);
        $this->assertFalse($fields->find($id)->printable);
        $this->assertSame(1, Database::connect()->table('accounting_audit_log')->where('action', 'custom_field_updated')->countAllResults());
    }

    public function testGetView_SendsTheTwoFlagsAsOffWhenTheirBoxesAreUnticked(): void
    {
        $this->manager();
        $id = (new CustomFields())->create('sale', ['name' => 'PO number', 'type' => 'text', 'required' => '1'], null);

        $body = $this->get('/custom_fields/view/' . $id)->getBody();

        $this->assertStringContainsString('<input type="hidden" name="required" value="0">', $body);
        $this->assertStringContainsString('<input type="hidden" name="printable" value="0">', $body);
        $this->assertMatchesRegularExpression('/name="required" id="required" value="1"[^>]*checked/', $body);
        $this->assertDoesNotMatchRegularExpression('/name="printable" id="printable" value="1"[^>]*checked/', $body);
    }

    public function testPostSave_IsNotFoundForAFieldThatDoesNotExist(): void
    {
        $this->manager();

        $this->expectException(PageNotFoundException::class);

        $this->post('/custom_fields/save/987654', ['name' => 'x', 'type' => 'text']);
    }

    public function testPostStatus_SwitchesAFieldOffAndOnAndRefusesAnEmployeeWithoutThePermission(): void
    {
        $this->actAsWith(['custom_fields']);
        $fields = new CustomFields();
        $id     = $fields->create('item', ['name' => 'Colour', 'type' => 'text'], null);

        $refused = $this->postJson('/custom_fields/status/' . $id . '/0');

        $this->assertFalse($refused['success']);
        $this->assertTrue($fields->find($id)->active);

        $this->manager();

        $this->assertTrue($this->postJson('/custom_fields/status/' . $id . '/0')['success']);
        $this->assertFalse($fields->find($id)->active);
        $this->assertTrue($this->postJson('/custom_fields/status/' . $id . '/1')['success']);
        $this->assertTrue($fields->find($id)->active);
    }

    public function testGetView_ShowsTheFormOfAFieldAndEscapesItsNameAndOptions(): void
    {
        $this->manager();
        $id = (new CustomFields())->create('item', ['name' => '"><script>alert(1)</script>', 'type' => 'select', 'options' => "<b>one</b>\ntwo"], null);

        $body = $this->get('/custom_fields/view/' . $id)->getBody();

        $this->assertStringNotContainsString('<script>alert(1)</script>', $body);
        $this->assertStringNotContainsString('<b>one</b>', $body);
        $this->assertStringContainsString('&lt;b&gt;one&lt;/b&gt;', $body);
    }

    public function testGetView_ShowsTheEmptyFormOfANewField(): void
    {
        $this->manager();

        $response = $this->get('/custom_fields/view');

        $response->assertStatus(200);
        $response->assertSee(lang('Custom_fields.type_locked_note'));
        $this->assertStringContainsString('name="entity"', $response->getBody());
    }

    public function testGetView_IsNotFoundForAFieldThatDoesNotExist(): void
    {
        $this->manager();

        $this->expectException(PageNotFoundException::class);

        $this->get('/custom_fields/view/987654');
    }
}
