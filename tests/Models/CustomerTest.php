<?php

namespace Tests\Models;

use App\Libraries\Accounting\EntityAccountService;
use App\Libraries\Numbering\DocumentNumbering;
use App\Libraries\Numbering\DocumentType;
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
        $this->accountingDb()->table('document_numbers')->truncate();
        $this->accountingDb()->table('document_sequences')->truncate();
        $this->accountingDb()->table('accounting_audit_log')->where('entity_type', 'document_numbering')->delete();
        $this->removeInsertedEntities();
        $this->setConfigValue('accounting_enabled', '0');

        parent::tearDown();
    }

    /**
     * @param array<string, mixed> $extra
     */
    private function saveCustomer(string $firstName, string $lastName, ?string $company = null, int $customerId = -1, array $extra = []): int
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
        $customer = ['company_name' => $company, 'employee_id' => 1, ...$extra];

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

    private function accountNumberOf(int $customerId): ?string
    {
        return $this->accountingDb()->table('customers')->where('person_id', $customerId)->get()->getRow()->account_number;
    }

    private function switchOn(string $prefix = 'C', int $padding = 5, ?int $next = 1): void
    {
        (new DocumentNumbering())->configure(DocumentType::Customer, ['enabled' => true, 'prefix' => $prefix, 'padding' => $padding, 'next_number' => $next]);
    }

    public function testSaveCustomer_GivesNoAccountNumberWhileTheSequenceIsOff(): void
    {
        $customerId = $this->saveCustomer('Omar', 'Khaled');

        $this->assertNull($this->accountNumberOf($customerId));
        $this->assertSame(0, $this->countRows('document_numbers'));
    }

    public function testSaveCustomer_GivesANewCustomerTheNextNumberWhenNoneIsTyped(): void
    {
        $this->switchOn();

        $first  = $this->saveCustomer('Omar', 'Khaled');
        $second = $this->saveCustomer('Sara', 'Nasser', extra: ['account_number' => '']);

        $this->assertSame('C00001', $this->accountNumberOf($first));
        $this->assertSame('C00002', $this->accountNumberOf($second));
        $this->assertSame(3, (new DocumentNumbering())->sequence(DocumentType::Customer)->nextNumber);
    }

    public function testSaveCustomer_HandsTheNumberBackToTheCaller(): void
    {
        $this->switchOn();

        $person   = ['first_name' => 'Omar', 'last_name' => 'Khaled', 'gender' => null, 'phone_number' => '', 'email' => '', 'address_1' => '', 'address_2' => '', 'city' => '', 'state' => '', 'zip' => '', 'country' => '', 'comments' => ''];
        $customer = ['company_name' => null, 'employee_id' => 1, 'account_number' => null];

        $this->assertTrue((bool) model(Customer::class)->save_customer($person, $customer));
        $this->insertedPeople[] = (int) $person['person_id'];

        $this->assertSame('C00001', $customer['account_number']);
    }

    public function testSaveCustomer_KeepsAnAccountNumberTypedByHand(): void
    {
        $this->switchOn();

        $customerId = $this->saveCustomer('Omar', 'Khaled', extra: ['account_number' => 'VIP-7']);

        $this->assertSame('VIP-7', $this->accountNumberOf($customerId));
        $this->assertSame(0, $this->countRows('document_numbers'));
        $this->assertSame(1, (new DocumentNumbering())->sequence(DocumentType::Customer)->nextNumber);
    }

    public function testSaveCustomer_DoesNotRenumberACustomerThatExists(): void
    {
        $before = $this->saveCustomer('Omar', 'Khaled');

        $this->switchOn();

        $this->saveCustomer('Omar', 'Al-Khaled', null, $before);
        $other = $this->saveCustomer('Sara', 'Nasser');

        $this->assertNull($this->accountNumberOf($before));
        $this->assertSame('C00001', $this->accountNumberOf($other));
    }

    public function testSaveCustomer_KeepsTheNumberWhenTheCustomerIsEdited(): void
    {
        $this->switchOn();
        $customerId = $this->saveCustomer('Omar', 'Khaled');

        $this->saveCustomer('Omar', 'Al-Khaled', null, $customerId, ['account_number' => 'C00001']);

        $this->assertSame('C00001', $this->accountNumberOf($customerId));
        $this->assertSame(2, (new DocumentNumbering())->sequence(DocumentType::Customer)->nextNumber);
    }

    public function testSaveCustomer_SkipsANumberAnotherCustomerAlreadyHas(): void
    {
        $this->switchOn();
        $this->saveCustomer('Typed', 'ByHand', extra: ['account_number' => 'C00001']);

        $customerId = $this->saveCustomer('Omar', 'Khaled');

        $this->assertSame('C00002', $this->accountNumberOf($customerId));
    }

    public function testSaveCustomer_NumbersTheCustomerWhileAccountingIsOffToo(): void
    {
        $this->setConfigValue('accounting_enabled', '0');
        $this->switchOn();

        $customerId = $this->saveCustomer('Omar', 'Khaled');

        $this->assertSame('C00001', $this->accountNumberOf($customerId));
    }

    public function testSaveCustomer_RefusesTheCustomerAndLeavesNothingWhenNoNumberCanBeGiven(): void
    {
        $this->switchOn('C', 0, 1);
        $db = $this->accountingDb();
        $db->table('document_sequences')->where('doc_type', 'customer')->update(['next_number' => 4294967294]);
        $db->table('customers')->where('person_id', $this->saveCustomer('Typed', 'ByHand', extra: ['account_number' => 'C4294967294']))->update(['account_number' => 'C4294967294']);

        $people = $this->countRows('people');
        $person = ['first_name' => 'Omar', 'last_name' => 'Khaled', 'gender' => null, 'phone_number' => '', 'email' => '', 'address_1' => '', 'address_2' => '', 'city' => '', 'state' => '', 'zip' => '', 'country' => '', 'comments' => ''];
        $customer = ['company_name' => null, 'employee_id' => 1, 'account_number' => null];

        $this->assertFalse((bool) model(Customer::class)->save_customer($person, $customer));
        $this->assertSame($people, $this->countRows('people'));
    }

    public function testCheckAccountNumberExists_SeesTheNumberEvenWhenSeveralCustomersAlreadyShareIt(): void
    {
        $first  = $this->saveCustomer('One', 'Customer', extra: ['account_number' => 'DUP-1']);
        $second = $this->saveCustomer('Two', 'Customer', extra: ['account_number' => 'DUP-1']);
        $model  = model(Customer::class);

        $this->assertTrue($model->check_account_number_exists('DUP-1'));
        $this->assertTrue($model->check_account_number_exists('DUP-1', (string) $first));
        $this->assertFalse($model->check_account_number_exists('DUP-2'));
        $this->assertGreaterThan($first, $second);
    }
}
