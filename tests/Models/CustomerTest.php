<?php

namespace Tests\Models;

use App\Libraries\Accounting\AccountingGuard;
use App\Libraries\Accounting\EntityAccountService;
use App\Libraries\MasterData\PartyContacts;
use App\Libraries\Numbering\DocumentNumbering;
use App\Libraries\Numbering\DocumentType;
use App\Libraries\Portal\PortalAccounts;
use App\Models\Customer;
use App\Models\Item;
use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\DatabaseTestTrait;
use Config\Database;
use Config\OSPOS;
use Tests\Support\AccountingFixtureTrait;
use Tests\Support\EmployeeFixtureTrait;
use Tests\Support\UncommittedWriterTrait;

class CustomerTest extends CIUnitTestCase
{
    use DatabaseTestTrait;
    use AccountingFixtureTrait;
    use EmployeeFixtureTrait;
    use UncommittedWriterTrait;

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
        $this->accountingDb()->query('DELETE FROM ' . $this->accountingDb()->prefixTable('party_contacts'));
        $this->accountingDb()->query('DELETE FROM ' . $this->accountingDb()->prefixTable('portal_accounts'));
        $this->accountingDb()->table('accounting_audit_log')->whereIn('entity_type', ['portal_account', 'customer'])->delete();
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

    public function testDelete_HidesTheContactsOfTheCustomerAndKeepsTheirRows(): void
    {
        $customerId = $this->saveCustomer('Omar', 'Khaled');
        config(OSPOS::class)->settings['enforce_privacy'] = false;
        (new PartyContacts())->replace(PartyContacts::CUSTOMER, $customerId, [['first_name' => 'Sara', 'email' => 'sara@example.com']], 1);

        model(Customer::class)->delete($customerId);

        $this->assertSame([], (new PartyContacts())->forParty(PartyContacts::CUSTOMER, $customerId));
        $row = $this->accountingDb()->table('party_contacts')->where('party_id', $customerId)->get()->getRow();
        $this->assertSame(['Sara', 'sara@example.com', '1'], [$row->first_name, $row->email, (string) $row->deleted]);
    }

    public function testDelete_ScramblesTheContactsWhenPrivacyIsEnforced(): void
    {
        $customerId = $this->saveCustomer('Omar', 'Khaled');
        config(OSPOS::class)->settings['enforce_privacy'] = true;
        (new PartyContacts())->replace(PartyContacts::CUSTOMER, $customerId, [['first_name' => 'Sara', 'email' => 'sara@example.com', 'mobile' => '0500000000']], 1);

        model(Customer::class)->delete($customerId);

        $row = $this->accountingDb()->table('party_contacts')->where('party_id', $customerId)->get()->getRow();
        $this->assertSame([(string) $row->contact_id, '', '', ''], [$row->first_name, $row->email, $row->mobile, $row->last_name]);
    }

    public function testDelete_KeepsTheContactsOfAnotherCustomer(): void
    {
        $gone = $this->saveCustomer('Omar', 'Khaled');
        $kept = $this->saveCustomer('Hind', 'Saad');
        config(OSPOS::class)->settings['enforce_privacy'] = false;
        (new PartyContacts())->replace(PartyContacts::CUSTOMER, $gone, [['first_name' => 'Gone']], 1);
        (new PartyContacts())->replace(PartyContacts::CUSTOMER, $kept, [['first_name' => 'Kept']], 1);

        model(Customer::class)->delete($gone);

        $this->assertSame(['Kept'], array_column((new PartyContacts())->forParty(PartyContacts::CUSTOMER, $kept), 'first_name'));
    }

    public function testDeleteList_HidesTheContactsOfEveryDeletedCustomerAndOfNoOther(): void
    {
        $first  = $this->saveCustomer('Omar', 'Khaled');
        $second = $this->saveCustomer('Hind', 'Saad');
        $kept   = $this->saveCustomer('Sara', 'Nasser');

        foreach ([$first, $second, $kept] as $customerId) {
            (new PartyContacts())->replace(PartyContacts::CUSTOMER, $customerId, [['first_name' => 'Contact of ' . $customerId]], 1);
        }

        model(Customer::class)->delete_list([$first, $second]);

        $this->assertSame([], (new PartyContacts())->forParty(PartyContacts::CUSTOMER, $first));
        $this->assertSame([], (new PartyContacts())->forParty(PartyContacts::CUSTOMER, $second));
        $this->assertCount(1, (new PartyContacts())->forParty(PartyContacts::CUSTOMER, $kept));
    }

    public function testDelete_DisablesThePortalAccountAndScramblesItsUserNameWhenPrivacyIsEnforced(): void
    {
        $manager    = $this->employeeWithGrants([AccountingGuard::PORTAL_MANAGE]);
        $customerId = $this->saveCustomer('Omar', 'Khaled');
        config(OSPOS::class)->settings['enforce_privacy'] = true;
        $made = (new PortalAccounts())->create($customerId, 'omar@example.com', $manager);

        model(Customer::class)->delete($customerId);

        $row = (new PortalAccounts())->find($made['account_id']);
        $this->assertSame(['disabled', 'deleted-' . $made['account_id']], [$row->status, $row->username]);
    }

    public function testDeleteList_DisablesThePortalAccountOfEveryDeletedCustomer(): void
    {
        $manager = $this->employeeWithGrants([AccountingGuard::PORTAL_MANAGE]);
        $first   = $this->saveCustomer('Omar', 'Khaled');
        $second  = $this->saveCustomer('Hind', 'Saad');
        $kept    = $this->saveCustomer('Sara', 'Nasser');
        $ids     = [];

        foreach (['omar' => $first, 'hind' => $second, 'sara' => $kept] as $name => $customerId) {
            $ids[$customerId] = (new PortalAccounts())->create($customerId, $name, $manager)['account_id'];
        }

        model(Customer::class)->delete_list([$first, $second]);

        $this->assertSame('disabled', (new PortalAccounts())->find($ids[$first])->status);
        $this->assertSame('disabled', (new PortalAccounts())->find($ids[$second])->status);
        $this->assertSame('active', (new PortalAccounts())->find($ids[$kept])->status);
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

    public function testRefusesAccountNumber_RefusesANumberAnotherCustomerHasWhileTheOptionIsOn(): void
    {
        $first = $this->saveCustomer('One', 'Customer', extra: ['account_number' => 'ACC-1']);
        $model = model(Customer::class);

        $this->assertTrue($model->refusesAccountNumber('ACC-1'));
        $this->assertTrue($model->refusesAccountNumber('ACC-1', '-1'));
        $this->assertFalse($model->refusesAccountNumber('ACC-1', (string) $first));
        $this->assertFalse($model->refusesAccountNumber('ACC-2'));
    }

    public function testRefusesAccountNumber_AcceptsANumberAnotherCustomerHasWhenTheOptionIsOff(): void
    {
        $this->saveCustomer('One', 'Customer', extra: ['account_number' => 'ACC-1']);
        (new DocumentNumbering())->configure(DocumentType::Customer, ['prevent_duplicates' => false]);

        $this->assertFalse(model(Customer::class)->refusesAccountNumber('ACC-1'));
    }

    public function testRefusesAccountNumber_AcceptsAnEmptyNumberEvenWhenAnotherCustomerHasOne(): void
    {
        $this->saveCustomer('One', 'Customer', extra: ['account_number' => '']);

        $this->assertSame(1, $this->accountingDb()->table('customers')->where('account_number', '')->countAllResults());
        $this->assertFalse(model(Customer::class)->refusesAccountNumber(''));
    }

    // ========== the directory: filters and the totals of the branches the viewer works in ==========

    /**
     * @param array<string, mixed> $filters
     *
     * @return list<int>
     */
    private function found(array $filters, string $search = ''): array
    {
        return array_map(static fn (object $row): int => (int) $row->person_id, (new Customer())->search($search, 100, 0, 'last_name', 'asc', false, $filters)->getResult());
    }

    public function testSearch_NarrowsByEachFilterAndCountsTheSameRows(): void
    {
        $model = new Customer();
        $a     = $this->insertCustomer('Ann', 'Filterable' . uniqid());
        $b     = $this->insertCustomer('Ben', 'Filterable' . uniqid());
        $db    = $this->accountingDb();

        $db->table('customers')->where('person_id', $a)->update(['manual_status' => 'served', 'area' => 'Olaya', 'country_code' => 'SA', 'customer_type' => 'commercial', 'date' => '2020-03-10 10:00:00']);
        $db->table('customers')->where('person_id', $b)->update(['manual_status' => 'archived', 'area' => 'Malaz', 'country_code' => 'EG', 'customer_type' => 'individual', 'date' => '2020-04-10 10:00:00']);
        $db->table('people')->where('person_id', $a)->update(['city' => 'Riyadh']);
        $db->table('people')->where('person_id', $b)->update(['city' => 'Cairo']);

        foreach ([['manual_status', 'served'], ['area', 'Olaya'], ['country_code', 'SA'], ['customer_type', 'commercial'], ['city', 'Riyadh'], ['created_to', '2020-03-31']] as [$filter, $value]) {
            $this->assertSame([$a], $this->found([$filter => $value], 'Filterable'), $filter);
            $this->assertSame(1, $model->get_found_rows('Filterable', [$filter => $value]), $filter);
        }

        $this->assertSame([$b], $this->found(['created_from' => '2020-04-01'], 'Filterable'));
        $this->assertSame([$a], $this->found(['created_from' => '2020-03-10', 'created_to' => '2020-03-10'], 'Filterable'));
        $this->assertEqualsCanonicalizing([$a, $b], $this->found([], 'Filterable'));
        $this->assertSame(2, $model->get_found_rows('Filterable'));
        $this->assertSame([], $this->found(['manual_status' => 'served', 'area' => 'Malaz'], 'Filterable'));
    }

    public function testSearch_AnswersWithTheNamesOfTheClassificationAndTheAssignedEmployee(): void
    {
        $customerId = $this->insertCustomer('Ann', 'Named' . uniqid());
        $db         = $this->accountingDb();
        $db->table('customer_classifications')->insert(['name' => 'Gold']);
        $classId = (int) $db->insertID();
        $db->table('customers')->where('person_id', $customerId)->update(['classification_id' => $classId, 'assigned_employee_id' => 1]);

        $row = (new Customer())->search('Named', 10, 0, 'last_name', 'asc', false, ['visible_employee_ids' => null])->getRow();

        $this->assertSame('Gold', $row->classification_name);
        $this->assertNotSame('', trim((string) $row->assigned_employee_name));

        $hidden = (new Customer())->search('Named', 10, 0, 'last_name', 'asc', false, ['visible_employee_ids' => []])->getRow();

        $this->assertSame('', trim((string) $hidden->assigned_employee_name));

        $db->table('customers')->where('person_id', $customerId)->update(['classification_id' => null, 'assigned_employee_id' => null]);
        $db->query('DELETE FROM ' . $db->prefixTable('customer_classifications'));
    }

    public function testSearch_ReturnsNoRowForAnEmployeeFilterOutsideTheVisibleOnes(): void
    {
        $customerId = $this->insertCustomer('Ann', 'Hidden' . uniqid());
        $this->accountingDb()->table('customers')->where('person_id', $customerId)->update(['assigned_employee_id' => 1]);

        $this->assertSame([$customerId], $this->found(['assigned_employee_id' => 1, 'visible_employee_ids' => [1]], 'Hidden'));
        $this->assertSame([], $this->found(['assigned_employee_id' => 1, 'visible_employee_ids' => [2]], 'Hidden'));
        $this->assertSame([], $this->found(['assigned_employee_id' => 1, 'visible_employee_ids' => []], 'Hidden'));
        $this->assertSame(0, (new Customer())->get_found_rows('Hidden', ['assigned_employee_id' => 1, 'visible_employee_ids' => [2]]));

        $this->accountingDb()->table('customers')->where('person_id', $customerId)->update(['assigned_employee_id' => null]);
    }

    public function testGetStats_CountsTheSalesOfTheBranchesGivenAndNothingWhenNoBranchIsGiven(): void
    {
        $this->resetBranches();
        $other      = $this->createBranch('B9');
        $customerId = $this->insertCustomer();
        $db         = $this->accountingDb();
        $itemId     = $this->createStatItem();

        foreach ([[1, '30.00'], [$other, '12.00']] as [$branch, $amount]) {
            $db->table('sales')->insert(['customer_id' => $customerId, 'employee_id' => 1, 'comment' => '', 'sale_status' => COMPLETED, 'branch_id' => $branch]);
            $saleId = (int) $db->insertID();
            $db->table('sales_items')->insert(['sale_id' => $saleId, 'item_id' => $itemId, 'line' => 1, 'quantity_purchased' => 1, 'item_cost_price' => 1, 'item_unit_price' => $amount, 'item_location' => 1]);
            $db->table('sales_payments')->insert(['sale_id' => $saleId, 'payment_type' => 'Cash', 'payment_amount' => $amount, 'employee_id' => 1]);
        }

        $model = new Customer();

        $this->assertEquals(42, $model->get_stats($customerId)->total);
        $this->assertEquals(42, $model->get_stats($customerId, null)->total);
        $this->assertEquals(30, $model->get_stats($customerId, [1])->total);
        $this->assertEquals(12, $model->get_stats($customerId, [$other])->total);
        $this->assertNull($model->get_stats($customerId, []));

        $db->query('DELETE FROM ' . $db->prefixTable('sales_payments'));
        $db->query('DELETE FROM ' . $db->prefixTable('sales_items'));
        $db->query('DELETE FROM ' . $db->prefixTable('sales'));
        $db->table('item_quantities')->where('item_id', $itemId)->delete();
        $db->table('items')->where('item_id', $itemId)->delete();
        $this->resetBranches();
    }

    public function testGetStats_ReadsTheSalesOfACustomerWithoutWaitingForASaleThatIsBeingSaved(): void
    {
        $customerId = $this->insertCustomer();
        $itemId     = $this->createStatItem();
        $db         = $this->accountingDb();

        try {
            $stats = $this->whileASaleIsBeingSaved(1, $itemId, $customerId, static fn (): ?object => (new Customer())->get_stats($customerId));

            $this->assertNull($stats);
        } finally {
            $db->query('DROP TEMPORARY TABLE IF EXISTS ' . $db->prefixTable('sales_items_temp'));
            $db->table('item_quantities')->where('item_id', $itemId)->delete();
            $db->table('items')->where('item_id', $itemId)->delete();
        }
    }

    private function createStatItem(): int
    {
        $itemData = ['item_id' => null, 'name' => 'Stat item', 'description' => 'x', 'category' => 'x', 'cost_price' => 1, 'unit_price' => 5, 'reorder_level' => 0, 'item_number' => 'ST-' . uniqid(), 'allow_alt_description' => 0, 'is_serialized' => 0, 'stock_type' => HAS_STOCK, 'deleted' => 0];

        model(Item::class)->save_value($itemData);

        return (int) $itemData['item_id'];
    }
}
