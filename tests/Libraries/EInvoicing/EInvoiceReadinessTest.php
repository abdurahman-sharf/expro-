<?php

namespace Tests\Libraries\EInvoicing;

use App\Libraries\EInvoicing\EInvoiceReadiness;
use App\Libraries\EInvoicing\EInvoiceSettings;
use App\Libraries\EInvoicing\ReadinessCheck;
use App\Libraries\Tax\VatClassification;
use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\DatabaseTestTrait;
use Config\Database;
use Tests\Support\AccountingFixtureTrait;
use Tests\Support\EInvoiceFixtureTrait;
use Tests\Support\EInvoiceSaleFixtureTrait;
use Tests\Support\EmployeeFixtureTrait;
use Tests\Support\ItemFixtureTrait;

/**
 * What the readiness check says: the seller, the classification of a line without tax, the key, and the
 * customers that are businesses and cannot receive a tax invoice yet.
 */
class EInvoiceReadinessTest extends CIUnitTestCase
{
    use DatabaseTestTrait;
    use AccountingFixtureTrait;
    use EmployeeFixtureTrait;
    use ItemFixtureTrait;
    use EInvoiceFixtureTrait;
    use EInvoiceSaleFixtureTrait;

    protected $migrate     = true;
    protected $migrateOnce = true;
    protected $refresh     = false;
    protected $namespace   = null;

    private EInvoiceReadiness $readiness;

    public static function setUpBeforeClass(): void
    {
        $seeder = Database::seeder('tests');
        $seeder->call('TestDatabaseBootstrapSeeder');
    }

    protected function setUp(): void
    {
        parent::setUp();

        $this->reinstallChart();
        $this->setConfigValue('accounting_enabled', '1');
        $this->configureEInvoicing();

        $this->readiness = new EInvoiceReadiness();
    }

    protected function tearDown(): void
    {
        $this->accountingDb()->table('customer_einvoice_parties')->truncate();
        $this->restoreEInvoicingConfig();
        $this->setConfigValue('accounting_enabled', '0');
        $this->removeTemporaryDirectories();
        $this->removeInsertedEntities();

        parent::tearDown();
    }

    private function statusOf(string $id): string
    {
        foreach ($this->readiness->checks() as $check) {
            if ($check->id === $id) {
                return $check->status;
            }
        }

        $this->fail('No check named ' . $id);
    }

    public function testChecks_PassForASellerThatIsCompleteWhileTheLayerIsOn(): void
    {
        foreach (['accounting', 'enabled', 'seller_name', 'seller_vat', 'seller_identification', 'seller_address', 'customers'] as $id) {
            $this->assertSame(ReadinessCheck::PASS, $this->statusOf($id), $id);
        }
    }

    public function testChecks_FailForAMissingSellerTaxNumber(): void
    {
        $this->configureEInvoicing(['tax_id' => '']);

        $this->assertSame(ReadinessCheck::FAIL, $this->statusOf('seller_vat'));

        $this->configureEInvoicing(['tax_id' => '12345']);

        $this->assertSame(ReadinessCheck::FAIL, $this->statusOf('seller_vat'));
        $this->assertSame('12345', $this->detail('seller_vat'));
    }

    private function detail(string $id): string
    {
        foreach ($this->readiness->checks() as $check) {
            if ($check->id === $id) {
                return $check->detail;
            }
        }

        return '';
    }

    public function testChecks_FailForAnIncompleteSeller(): void
    {
        $this->configureEInvoicing(['company' => '']);
        $this->assertSame(ReadinessCheck::FAIL, $this->statusOf('seller_name'));

        $this->configureEInvoicing(['einvoice_seller_identification_type' => 'NAT']);
        $this->assertSame(ReadinessCheck::FAIL, $this->statusOf('seller_identification'));

        $this->configureEInvoicing(['einvoice_seller_district' => '']);
        $this->assertSame(ReadinessCheck::FAIL, $this->statusOf('seller_address'));
    }

    public function testChecks_WarnWhileTheLayerIsOffAndFailWhileAccountingIsOff(): void
    {
        $this->configureEInvoicing([EInvoiceSettings::ENABLED => '0']);

        $this->assertSame(ReadinessCheck::WARN, $this->statusOf('enabled'));

        $this->setConfigValue('accounting_enabled', '0');

        $this->assertSame(ReadinessCheck::FAIL, $this->statusOf('accounting'));
    }

    public function testChecks_WarnAboutALineWithoutTaxUntilItIsClassified(): void
    {
        $this->assertSame(ReadinessCheck::WARN, $this->statusOf('exemption'));

        $this->configureEInvoicing(['einvoice_exempt_category' => 'Z', 'einvoice_exempt_reason_code' => 'VATEX-SA-32']);

        $this->assertSame(ReadinessCheck::PASS, $this->statusOf('exemption'));
    }

    public function testChecks_WarnsOfUnclassifiedItemsAndFailsItemsWithSeveralTaxesInSaudiMode(): void
    {
        $db     = $this->accountingDb();
        $itemId = $this->createTestItem();
        $db->table('items_taxes')->insertBatch([['item_id' => $itemId, 'name' => 'Tax A', 'percent' => '10'], ['item_id' => $itemId, 'name' => 'Tax B', 'percent' => '5']]);
        $profile = $db->table('company_tax_profile')->get()->getRowArray();

        try {
            $this->assertSame(ReadinessCheck::WARN, $this->statusOf('classification'));
            $this->assertSame((string) (new VatClassification())->unclassifiedCount(), $this->detail('classification'));
            $this->assertSame(ReadinessCheck::WARN, $this->statusOf('ambiguous_taxes'));

            $db->table('company_tax_profile')->update(['ksa_mode' => 1, 'ksa_mode_effective_at' => '2020-01-01 00:00:00']);

            $this->assertSame(ReadinessCheck::FAIL, $this->statusOf('ambiguous_taxes'));
        } finally {
            $db->table('company_tax_profile')->update(['ksa_mode' => $profile['ksa_mode'], 'ksa_mode_effective_at' => $profile['ksa_mode_effective_at']]);
            $db->table('items_taxes')->where('item_id', $itemId)->delete();
            $db->table('item_quantities')->where('item_id', $itemId)->delete();
            $db->table('inventory')->where('trans_items', $itemId)->delete();
            $db->table('items')->where('item_id', $itemId)->delete();
        }
    }

    public function testChecks_SayThatNothingIsSentBecauseNoTransportIsInPlace(): void
    {
        $this->assertSame(ReadinessCheck::WARN, $this->statusOf('transport'));
        $this->assertSame('null', $this->detail('transport'));
    }

    public function testChecks_WarnThatDocumentsAreUnsignedWhileNoKeyIsNamedAndPassWithAKeyThatLoads(): void
    {
        $this->assertSame(ReadinessCheck::WARN, $this->statusOf('signing'));
        $this->assertSame('not_configured', $this->detail('signing'));

        $this->skipWithoutTheCurve();

        $pair = $this->generateKeyPair();

        $this->configureEInvoicing(['einvoice_key_path' => $pair['key'], 'einvoice_certificate_path' => $pair['certificate']]);

        $this->assertSame(ReadinessCheck::PASS, $this->statusOf('signing'));
    }

    public function testChecks_FailForAKeyThatCannotBeUsedAndSayWhy(): void
    {
        $this->configureEInvoicing(['einvoice_key_path' => WRITEPATH . 'key.pem', 'einvoice_certificate_path' => WRITEPATH . 'cert.pem']);

        $this->assertSame(ReadinessCheck::FAIL, $this->statusOf('signing'));
        $this->assertSame('key_path_unsafe', $this->detail('signing'));
    }

    public function testCustomersWithoutIdentification_ListsBusinessesFirstAndSaysWhatIsWrong(): void
    {
        $individual = $this->insertCustomer('Layla', 'Hassan');
        $company    = $this->insertCustomer('Omar', 'Khaled', 'Khaled Trading');
        $badVat     = $this->insertCustomer('Sara', 'Nasser', 'Nasser Co');

        $this->accountingDb()->table('customers')->where('person_id', $company)->update(['customer_type' => 'commercial']);
        $this->accountingDb()->table('customers')->where('person_id', $badVat)->update(['tax_id' => '12345']);

        $listing = $this->readiness->customersWithoutIdentification(10);
        $byId    = [];

        foreach ($listing['rows'] as $row) {
            $byId[$row['person_id']] = $row;
        }

        $this->assertTrue($byId[$company]['business']);
        $this->assertSame('no_identification', $byId[$company]['problem']);
        $this->assertSame('Khaled Trading', $byId[$company]['name']);
        $this->assertTrue($byId[$badVat]['business']);
        $this->assertSame('vat_invalid', $byId[$badVat]['problem']);
        $this->assertFalse($byId[$individual]['business']);
        $this->assertGreaterThanOrEqual(2, $listing['businesses']);
        $this->assertGreaterThanOrEqual($listing['businesses'] + 1, $listing['total']);

        $firstIndividual = array_search(false, array_column($listing['rows'], 'business'), true);
        $lastBusiness    = array_key_last(array_filter($listing['rows'], static fn (array $row): bool => $row['business']));

        $this->assertLessThan($firstIndividual, $lastBusiness);
    }

    public function testCustomersWithoutIdentification_LeavesOutACustomerWithAValidTaxNumberOrAnIdentification(): void
    {
        $withVat = $this->insertCustomer('Ali', 'Vat', 'Vat Co');
        $withId  = $this->insertCustomer('Ali', 'Identified');

        $this->accountingDb()->table('customers')->where('person_id', $withVat)->update(['tax_id' => '311111111111113']);
        $this->accountingDb()->table('customer_einvoice_parties')->insert(['person_id' => $withId, 'identification_type' => 'NAT', 'identification_number' => '1098765432']);

        $ids = array_column($this->readiness->customersWithoutIdentification(500)['rows'], 'person_id');

        $this->assertNotContains($withVat, $ids);
        $this->assertNotContains($withId, $ids);
    }

    public function testCustomersWithoutIdentification_ListsACustomerWithAnInvalidTaxNumberAlthoughItHasAnIdentification(): void
    {
        $customer = $this->insertCustomer('Sara', 'Nasser', 'Sara Co');

        $this->accountingDb()->table('customers')->where('person_id', $customer)->update(['tax_id' => '12345']);
        $this->accountingDb()->table('customer_einvoice_parties')->insert(['person_id' => $customer, 'identification_type' => 'CRN', 'identification_number' => '1010010000']);

        $rows = array_column($this->readiness->customersWithoutIdentification(500)['rows'], null, 'person_id');

        $this->assertArrayHasKey($customer, $rows);
        $this->assertSame('vat_invalid', $rows[$customer]['problem']);
        $this->assertTrue($rows[$customer]['business']);
    }

    public function testCustomersWithoutIdentification_ReadsABlankedTaxNumberAsTheDocumentDoes(): void
    {
        $padded = $this->insertCustomer('Ali', 'Padded', 'Padded Co');
        $blank  = $this->insertCustomer('Ali', 'Blank', 'Blank Co');

        $this->accountingDb()->table('customers')->where('person_id', $padded)->update(['tax_id' => '  311111111111113 ']);
        $this->accountingDb()->table('customers')->where('person_id', $blank)->update(['tax_id' => '   ']);

        $rows = array_column($this->readiness->customersWithoutIdentification(500)['rows'], null, 'person_id');

        $this->assertArrayNotHasKey($padded, $rows);
        $this->assertSame('no_identification', $rows[$blank]['problem']);
    }

    public function testCustomersWithoutIdentification_LeavesOutADeletedCustomer(): void
    {
        $gone = $this->insertCustomer('Gone', 'Customer', 'Gone Co');

        $this->accountingDb()->table('customers')->where('person_id', $gone)->update(['customer_type' => 'commercial', 'deleted' => 1]);

        $this->assertNotContains($gone, array_column($this->readiness->customersWithoutIdentification(500)['rows'], 'person_id'));
    }

    public function testCustomersWithoutIdentification_PagesTheList(): void
    {
        $this->insertCustomer('Page', 'One');
        $this->insertCustomer('Page', 'Two');

        $all  = $this->readiness->customersWithoutIdentification(500);
        $page = $this->readiness->customersWithoutIdentification(1, 1);

        $this->assertCount(1, $page['rows']);
        $this->assertSame($all['rows'][1]['person_id'], $page['rows'][0]['person_id']);
        $this->assertSame($all['total'], $page['total']);
    }

    public function testChecks_FailWhileABusinessCustomerHasNoIdentificationAndPassOnceItHasOne(): void
    {
        $company = $this->insertCustomer('Omar', 'Khaled', 'Khaled Trading');

        $this->accountingDb()->table('customers')->where('person_id', $company)->update(['customer_type' => 'commercial']);

        $this->assertSame(ReadinessCheck::FAIL, $this->statusOf('customers'));

        $this->accountingDb()->table('customer_einvoice_parties')->insert(['person_id' => $company, 'identification_type' => 'CRN', 'identification_number' => '1010010000']);

        $this->assertSame(ReadinessCheck::PASS, $this->statusOf('customers'));
    }

    public function testChecks_FailWhileABusinessCustomerHasAnInvalidTaxNumberWhateverItsIdentification(): void
    {
        $company = $this->insertCustomer('Sara', 'Nasser', 'Sara Co');

        $this->accountingDb()->table('customers')->where('person_id', $company)->update(['tax_id' => '12345']);
        $this->accountingDb()->table('customer_einvoice_parties')->insert(['person_id' => $company, 'identification_type' => 'CRN', 'identification_number' => '1010010000']);

        $this->assertSame(ReadinessCheck::FAIL, $this->statusOf('customers'));
        $this->assertSame('1', $this->detail('customers'));

        $this->accountingDb()->table('customers')->where('person_id', $company)->update(['tax_id' => '311111111111113']);

        $this->assertSame(ReadinessCheck::PASS, $this->statusOf('customers'));
    }

    public function testReadiness_ChangesNothing(): void
    {
        $this->insertCustomer('Omar', 'Khaled', 'Khaled Trading');

        $before = $this->accountingDb()->table('customers')->countAllResults();
        $this->readiness->checks();
        $this->readiness->customersWithoutIdentification(10);

        $this->assertSame($before, $this->accountingDb()->table('customers')->countAllResults());
        $this->assertSame(0, $this->accountingDb()->table('customer_einvoice_parties')->countAllResults());
    }

    public function testChecks_FailsWhileASaleCompletedUnderTheSaudiRulesLacksItsDocument(): void
    {
        $db      = $this->accountingDb();
        $profile = $db->table('company_tax_profile')->get()->getRowArray();

        $this->assertNotContains('history', array_map(static fn (ReadinessCheck $check): string => $check->id, $this->readiness->checks()), 'nothing to audit while the rules were never turned on');

        $db->table('sales')->insert(['sale_time' => '2026-11-21 10:00:00', 'employee_id' => 1, 'comment' => '', 'sale_status' => COMPLETED, 'sale_type' => SALE_TYPE_POS, 'branch_id' => 1]);
        $saleId = (int) $db->insertID();

        try {
            $db->table('company_tax_profile')->update(['ksa_mode' => 1, 'ksa_mode_effective_at' => '2020-01-01 00:00:00']);

            $this->assertSame(ReadinessCheck::FAIL, $this->statusOf('history'));
        } finally {
            $db->table('company_tax_profile')->update(['ksa_mode' => $profile['ksa_mode'], 'ksa_mode_effective_at' => $profile['ksa_mode_effective_at']]);
            $db->table('sales')->where('sale_id', $saleId)->delete();
        }
    }
}
