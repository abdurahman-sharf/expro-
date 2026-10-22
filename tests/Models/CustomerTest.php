<?php

namespace Tests\Models;

use App\Libraries\Accounting\EntityAccountService;
use App\Models\Customer;
use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\DatabaseTestTrait;
use Config\Database;
use Config\OSPOS;
use Tests\Support\AccountingFixtureTrait;

class CustomerTest extends CIUnitTestCase
{
    use DatabaseTestTrait;
    use AccountingFixtureTrait;

    protected $migrate     = true;
    protected $migrateOnce = true;
    protected $refresh     = false;
    protected $namespace   = null;
    private EntityAccountService $entities;
    private mixed $privacy;

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
        $this->entities = new EntityAccountService();
        $this->privacy  = config(OSPOS::class)->settings['enforce_privacy'] ?? null;
    }

    protected function tearDown(): void
    {
        config(OSPOS::class)->settings['enforce_privacy'] = $this->privacy;
        $this->removeInsertedEntities();
        $this->setConfigValue('accounting_enabled', '0');

        parent::tearDown();
    }

    private function saveCustomer(string $firstName, string $lastName, ?string $company = null, int $customerId = -1): int
    {
        $person = [
            'first_name'   => $firstName,
            'last_name'    => $lastName,
            'gender'       => null,
            'phone_number' => '',
            'email'        => '',
            'address_1'    => '',
            'address_2'    => '',
            'city'         => '',
            'state'        => '',
            'zip'          => '',
            'country'      => '',
            'comments'     => '',
        ];
        $customer = ['company_name' => $company, 'employee_id' => 1];

        $this->assertTrue((bool) model(Customer::class)->save_customer($person, $customer, $customerId));

        $personId = (int) ($person['person_id'] ?? $customerId);

        if (! in_array($personId, $this->insertedPeople, true)) {
            $this->insertedPeople[] = $personId;
        }

        return $personId;
    }

    private function accountOf(int $customerId): ?object
    {
        $accountId = $this->entities->accountFor(EntityAccountService::CUSTOMER, $customerId);

        return $accountId === null ? null : $this->accountingDb()->table('accounting_accounts')->where('account_id', $accountId)->get()->getRow();
    }

    public function testSaveCustomer_OpensASubAccountForANewCustomerWhileAccountingIsOn(): void
    {
        $customerId = $this->saveCustomer('Omar', 'Khaled');

        $account = $this->accountOf($customerId);

        $this->assertNotNull($account);
        $this->assertSame('Omar Khaled', $account->name);
        $this->assertSame('12041001', $account->code);
    }

    public function testSaveCustomer_NamesTheAccountAfterTheCompanyWhenThereIsOne(): void
    {
        $customerId = $this->saveCustomer('Omar', 'Khaled', 'Riyadh Trading');

        $this->assertSame('Riyadh Trading', $this->accountOf($customerId)->name);
    }

    public function testSaveCustomer_OpensNoAccountWhileAccountingIsOff(): void
    {
        $this->setConfigValue('accounting_enabled', '0');

        $customerId = $this->saveCustomer('Omar', 'Khaled');

        $this->assertNull($this->accountOf($customerId));
    }

    public function testSaveCustomer_RenamesTheAccountWhenTheCustomerIsRenamed(): void
    {
        $customerId = $this->saveCustomer('Omar', 'Khaled');

        $this->saveCustomer('Omar', 'Al-Khaled', null, $customerId);

        $this->assertSame('Omar Al-Khaled', $this->accountOf($customerId)->name);
        $this->assertSame(1, $this->accountingDb()->table('accounting_accounts')->where('entity_type', 'customer')->countAllResults());
    }

    public function testSaveCustomer_StillSavesTheCustomerWhenTheAccountCannotBeOpened(): void
    {
        $this->accountingDb()->table('accounting_routing')->where('routing_key', 'sales.customers')->update(['account_id' => null]);

        $customerId = $this->saveCustomer('Omar', 'Khaled');

        $this->assertSame(1, $this->accountingDb()->table('customers')->where('person_id', $customerId)->countAllResults());
        $this->assertNull($this->accountOf($customerId));
    }

    public function testDelete_RetiresTheAccountAndKeepsTheName(): void
    {
        $customerId = $this->saveCustomer('Omar', 'Khaled');
        config(OSPOS::class)->settings['enforce_privacy'] = false;

        model(Customer::class)->delete($customerId);

        $account = $this->accountOf($customerId);
        $this->assertSame('0', (string) $account->active);
        $this->assertSame('Omar Khaled', $account->name);
    }

    public function testDelete_ReplacesTheAccountNameWithItsCodeWhenPrivacyIsEnforced(): void
    {
        $customerId = $this->saveCustomer('Omar', 'Khaled');
        config(OSPOS::class)->settings['enforce_privacy'] = true;

        model(Customer::class)->delete($customerId);

        $account = $this->accountOf($customerId);
        $this->assertSame('0', (string) $account->active);
        $this->assertSame($account->code, $account->name);
    }

    public function testDeleteList_RetiresTheAccountOfEveryDeletedCustomer(): void
    {
        $first  = $this->saveCustomer('Omar', 'Khaled');
        $second = $this->saveCustomer('Hind', 'Saad');
        $kept   = $this->saveCustomer('Sara', 'Nasser');

        model(Customer::class)->delete_list([$first, $second]);

        $this->assertSame('0', (string) $this->accountOf($first)->active);
        $this->assertSame('0', (string) $this->accountOf($second)->active);
        $this->assertSame('1', (string) $this->accountOf($kept)->active);
    }

    public function testSaveCustomer_OpensTheAccountAgainForACustomerWhoComesBack(): void
    {
        $customerId = $this->saveCustomer('Omar', 'Khaled');
        config(OSPOS::class)->settings['enforce_privacy'] = false;
        model(Customer::class)->delete($customerId);
        $this->accountingDb()->table('customers')->where('person_id', $customerId)->update(['deleted' => 0]);

        $this->saveCustomer('Omar', 'Khaled', null, $customerId);

        $this->assertSame('1', (string) $this->accountOf($customerId)->active);
    }
}
