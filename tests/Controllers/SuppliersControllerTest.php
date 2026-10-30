<?php

namespace Tests\Controllers;

use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\DatabaseTestTrait;
use CodeIgniter\Test\FeatureTestTrait;
use Config\Database;
use Tests\Support\AccountingFixtureTrait;

/**
 * The supplier form: what it keeps besides the name, and in particular the commercial registry number that
 * the purchase documents show beside the tax number.
 */
class SuppliersControllerTest extends CIUnitTestCase
{
    use DatabaseTestTrait;
    use FeatureTestTrait;
    use AccountingFixtureTrait;

    protected $migrate     = true;
    protected $migrateOnce = true;
    protected $refresh     = false;
    protected $namespace   = null;

    /** @var list<int> */
    private array $supplierIds = [];

    public static function setUpBeforeClass(): void
    {
        $seeder = Database::seeder('tests');
        $seeder->call('TestDatabaseBootstrapSeeder');
    }

    protected function setUp(): void
    {
        parent::setUp();

        $this->setConfigValue('accounting_enabled', '0');
        $this->withSession(['person_id' => $this->createEmployee(grants: [['permission_id' => 'suppliers', 'menu_group' => 'home']]), 'menu_group' => 'home']);
    }

    protected function tearDown(): void
    {
        if ($this->supplierIds !== []) {
            $db = $this->accountingDb();

            $db->query('SET FOREIGN_KEY_CHECKS = 0');
            $db->table('suppliers')->whereIn('person_id', $this->supplierIds)->delete();
            $db->table('people')->whereIn('person_id', $this->supplierIds)->delete();
            $db->query('SET FOREIGN_KEY_CHECKS = 1');
        }

        $this->supplierIds = [];

        parent::tearDown();
    }

    /**
     * @param array<string, string> $more
     *
     * @return array<string, string>
     */
    private function form(array $more = []): array
    {
        return $more + [
            'first_name'   => 'Sara',
            'last_name'    => 'Nasser',
            'phone_number' => '',
            'address_1'    => '',
            'address_2'    => '',
            'city'         => '',
            'state'        => '',
            'zip'          => '',
            'country'      => '',
            'comments'     => '',
            'company_name' => 'Registry Supplies ' . uniqid(),
            'agency_name'  => '',
            'category'     => (string) GOODS_SUPPLIER,
            'tax_id'       => '300123456700003',
            'email'        => '',
        ];
    }

    private function saveSupplier(array $form, int $supplierId = NEW_ENTRY): int
    {
        $result = json_decode($this->post('/suppliers/save/' . $supplierId, $form)->getJSON(), true);

        $this->assertTrue($result['success'], (string) ($result['message'] ?? ''));

        $this->supplierIds[] = (int) $result['id'];

        return (int) $result['id'];
    }

    private function supplierRow(int $supplierId): object
    {
        return $this->accountingDb()->table('suppliers')->where('person_id', $supplierId)->get()->getRow();
    }

    public function testPostSave_SavesTheCommercialRegistryBesideTheTaxNumber(): void
    {
        $supplierId = $this->saveSupplier($this->form(['commercial_registry' => '1010123456']));

        $row = $this->supplierRow($supplierId);

        $this->assertSame('1010123456', $row->commercial_registry);
        $this->assertSame('300123456700003', $row->tax_id);
    }

    public function testPostSave_KeepsARegistryNumberThatHasLettersAndSeparators(): void
    {
        $supplierId = $this->saveSupplier($this->form(['commercial_registry' => '  CR-1010/123 ']));

        $this->assertSame('CR-1010/123', $this->supplierRow($supplierId)->commercial_registry);
    }

    public function testPostSave_CutsARegistryNumberToTheSixtyFourCharactersTheColumnKeeps(): void
    {
        $supplierId = $this->saveSupplier($this->form(['commercial_registry' => str_repeat('7', 70)]));

        $this->assertSame(str_repeat('7', 64), $this->supplierRow($supplierId)->commercial_registry);
    }

    public function testPostSave_LeavesAnEmptyRegistryForASupplierWhoseFormGaveNone(): void
    {
        $supplierId = $this->saveSupplier($this->form(['commercial_registry' => '']));

        $this->assertSame('', $this->supplierRow($supplierId)->commercial_registry);
    }

    public function testPostSave_ClearsTheRegistryWhenTheFormEmptiesIt(): void
    {
        $supplierId = $this->saveSupplier($this->form(['commercial_registry' => '1010123456']));

        $this->saveSupplier($this->form(['commercial_registry' => '']), $supplierId);

        $this->assertSame('', $this->supplierRow($supplierId)->commercial_registry);
    }

    public function testPostSave_LeavesTheRegistryAloneWhenTheFormDoesNotCarryIt(): void
    {
        $supplierId = $this->saveSupplier($this->form(['commercial_registry' => '1010123456']));

        $this->saveSupplier($this->form(['company_name' => 'Renamed Supplies ' . uniqid()]), $supplierId);

        $this->assertSame('1010123456', $this->supplierRow($supplierId)->commercial_registry);
    }

    public function testGetView_ShowsTheRegistryFieldBesideTheTaxNumber(): void
    {
        $supplierId = $this->saveSupplier($this->form(['commercial_registry' => '1010123456']));

        $response = $this->get('/suppliers/view/' . $supplierId);

        $response->assertSeeInField('commercial_registry', '1010123456');
        $response->assertSeeInField('tax_id', '300123456700003');
        $response->assertSee(lang('Suppliers.commercial_registry'));
    }

    public function testGetView_ShowsAnEmptyRegistryFieldForANewSupplier(): void
    {
        $response = $this->get('/suppliers/view');

        $response->assertSeeElement('#commercial_registry');
        $response->assertSeeInField('commercial_registry', '');
    }
}
