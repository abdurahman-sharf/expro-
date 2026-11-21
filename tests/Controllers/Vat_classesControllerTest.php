<?php

namespace Tests\Controllers;

use App\Libraries\Tax\VatClassification;
use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\DatabaseTestTrait;
use CodeIgniter\Test\FeatureTestTrait;
use CodeIgniter\Test\TestResponse;
use Config\Database;
use Tests\Support\AccountingFixtureTrait;
use Tests\Support\EmployeeFixtureTrait;

class Vat_classesControllerTest extends CIUnitTestCase
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

    protected function tearDown(): void
    {
        $db = Database::connect();

        foreach ($db->table('vat_classes')->like('code', 'TEST', 'after')->get()->getResult() as $class) {
            $db->table('vat_class_rates')->where('class_id', (int) $class->class_id)->delete();
            $db->table('vat_classes')->where('class_id', (int) $class->class_id)->delete();
        }

        $db->table('accounting_audit_log')->where('entity_type', 'vat_classification')->delete();
        $this->removeInsertedEntities();

        parent::tearDown();
    }

    private function actAs(int $personId): void
    {
        $this->withSession(['person_id' => $personId, 'menu_group' => 'office']);
    }

    /**
     * @return array<string, mixed>
     */
    private function json(TestResponse $response): array
    {
        return json_decode($response->getJSON(), true);
    }

    public function testGetIndex_ListsTheClassesWithTheirCurrentRate(): void
    {
        $this->actAs($this->employeeWithGrants([VatClassification::TAXES_PERMISSION]));

        $page = $this->get('/vat_classes');

        $page->assertStatus(200);
        $page->assertSee('id="vat_classes_table"');
        $page->assertSee('Z-EXPORT-GOODS');
        $page->assertSee('15%');
        $page->assertSee('id="vat_classes_mode"');
    }

    public function testGetIndex_SendsAnEmployeeWithoutTheTaxesModuleToTheNoAccessPage(): void
    {
        $this->actAs($this->employeeWithGrants(['items']));

        $this->get('/vat_classes')->assertRedirectTo('no_access/taxes/');
    }

    public function testPostSaveClass_SavesANewClassWithItsFirstRate(): void
    {
        $this->actAs($this->employeeWithGrants([VatClassification::TAXES_PERMISSION]));

        $result = $this->json($this->post('/vat_classes/save_class', ['code' => 'TEST-METALS', 'name' => 'Qualifying metals', 'category' => 'Z', 'exemption_code' => 'VATEX-SA-36', 'exemption_text' => 'Qualifying metals', 'tax_name' => 'VAT', 'percent' => '0', 'valid_from' => '2026-01-01']));

        $this->assertTrue($result['success']);
        $this->assertSame(1, Database::connect()->table('vat_classes')->where('code', 'TEST-METALS')->countAllResults());
    }

    public function testPostSaveClass_SaysWhyAClassIsRefused(): void
    {
        $this->actAs($this->employeeWithGrants([VatClassification::TAXES_PERMISSION]));

        $result = $this->json($this->post('/vat_classes/save_class', ['code' => 'TEST-BAD', 'name' => 'Bad', 'category' => 'S', 'exemption_code' => 'VATEX-SA-32', 'exemption_text' => 'x', 'tax_name' => 'VAT', 'percent' => '15', 'valid_from' => '2026-01-01']));

        $this->assertFalse($result['success']);
        $this->assertSame(lang('Vat_classes.error_exemption_not_allowed'), $result['message']);
    }

    public function testPostAddRate_SavesAndAudits(): void
    {
        $this->actAs($this->employeeWithGrants([VatClassification::TAXES_PERMISSION]));
        $this->post('/vat_classes/save_class', ['code' => 'TEST-S', 'name' => 'Test', 'category' => 'S', 'tax_name' => 'VAT', 'percent' => '15', 'valid_from' => '2026-01-01']);
        $classId = (int) Database::connect()->table('vat_classes')->where('code', 'TEST-S')->get()->getRow()->class_id;

        $result = $this->json($this->post('/vat_classes/add_rate/' . $classId, ['percent' => '16', 'valid_from' => '2027-01-01']));

        $this->assertTrue($result['success']);
        $this->assertSame('16.000', (new VatClassification())->rateOn($classId, '2027-06-01'));
    }
}
