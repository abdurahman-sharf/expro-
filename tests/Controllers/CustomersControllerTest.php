<?php

namespace Tests\Controllers;

use App\Libraries\Numbering\DocumentNumbering;
use App\Libraries\Numbering\DocumentType;
use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\DatabaseTestTrait;
use CodeIgniter\Test\FeatureTestTrait;
use Config\Database;
use Config\OSPOS;
use Tests\Support\AccountingFixtureTrait;
use Tests\Support\EmployeeFixtureTrait;

/**
 * The customer form and what it saves: the account number a customer is given by the numbering,
 * the one typed by hand, and the refusal of a number another customer already has.
 */
class CustomersControllerTest extends CIUnitTestCase
{
    use DatabaseTestTrait;
    use FeatureTestTrait;
    use AccountingFixtureTrait;
    use EmployeeFixtureTrait;

    protected $migrate     = true;
    protected $migrateOnce = true;
    protected $refresh     = false;
    protected $namespace   = null;

    /** @var list<int> */
    private array $customerIds = [];

    public static function setUpBeforeClass(): void
    {
        $seeder = Database::seeder('tests');
        $seeder->call('TestDatabaseBootstrapSeeder');
    }

    protected function setUp(): void
    {
        parent::setUp();

        $this->releaseOpenTransactions();
        $this->withSession(['person_id' => 1, 'menu_group' => 'office']);
    }

    protected function tearDown(): void
    {
        $db = $this->accountingDb();
        $db->table('document_numbers')->truncate();
        $db->table('document_sequences')->truncate();
        $db->table('accounting_audit_log')->where('entity_type', 'document_numbering')->delete();

        $this->insertedPeople = array_merge($this->insertedPeople, $this->customerIds);
        $this->customerIds    = [];
        $this->removeInsertedEntities();

        parent::tearDown();
    }

    private function switchOn(string $prefix = 'C', int $padding = 5, bool $preventDuplicates = true): void
    {
        (new DocumentNumbering())->configure(DocumentType::Customer, ['enabled' => true, 'prefix' => $prefix, 'padding' => $padding, 'next_number' => 1, 'prevent_duplicates' => $preventDuplicates]);
    }

    /**
     * @param array<string, string> $extra
     *
     * @return array<string, mixed>
     */
    private function save(array $extra = [], int $customerId = NEW_ENTRY): array
    {
        $settings = config(OSPOS::class)->settings;
        $unique   = uniqid();

        $response = $this->post('/customers/save/' . $customerId, [
            'first_name'   => 'Omar',
            'last_name'    => 'K' . $unique,
            'email'        => 'omar.' . $unique . '@test.com',
            'phone_number' => '',
            'address_1'    => '',
            'address_2'    => '',
            'city'         => '',
            'state'        => '',
            'zip'          => '',
            'country'      => '',
            'comments'     => '',
            'date'         => date(($settings['dateformat'] ?? 'm/d/Y') . ' ' . ($settings['timeformat'] ?? 'H:i:s')),
            'employee_id'  => '1',
            'tax_id'       => '',
            'taxable'      => '1',
            ...$extra,
        ]);

        $result = json_decode($response->getJSON(), true);

        if (($result['success'] ?? false) && $customerId === NEW_ENTRY) {
            $this->customerIds[] = (int) $result['id'];
        }

        return $result;
    }

    private function accountNumberOf(int $customerId): ?string
    {
        return $this->accountingDb()->table('customers')->where('person_id', $customerId)->get()->getRow()->account_number;
    }

    public function testPostSave_LeavesTheAccountNumberEmptyWhileTheSequenceIsOff(): void
    {
        $result = $this->save(['account_number' => '']);

        $this->assertTrue($result['success']);
        $this->assertNull($this->accountNumberOf($result['id']));
        $this->assertSame(0, $this->countRows('document_numbers'));
    }

    public function testPostSave_GivesANewCustomerTheNextAutomaticNumberWhenNoneIsTyped(): void
    {
        $this->switchOn();

        $first  = $this->save(['account_number' => '']);
        $second = $this->save(['account_number' => '']);

        $this->assertSame('C00001', $this->accountNumberOf($first['id']));
        $this->assertSame('C00002', $this->accountNumberOf($second['id']));
    }

    public function testPostSave_KeepsAnAccountNumberTypedByHandWhileTheSequenceIsOn(): void
    {
        $this->switchOn();

        $result = $this->save(['account_number' => 'VIP-7']);

        $this->assertSame('VIP-7', $this->accountNumberOf($result['id']));
        $this->assertSame(1, (new DocumentNumbering())->sequence(DocumentType::Customer)->nextNumber);
    }

    public function testPostSave_GivesNoAutomaticNumberToACustomerThatIsEdited(): void
    {
        $created = $this->save(['account_number' => '']);

        $this->switchOn();

        $edited = $this->save(['account_number' => ''], (int) $created['id']);

        $this->assertTrue($edited['success']);
        $this->assertNull($this->accountNumberOf((int) $created['id']));
    }

    public function testPostSave_RefusesAnAccountNumberAnotherCustomerAlreadyHas(): void
    {
        $first  = $this->save(['account_number' => 'ACC-1']);
        $before = $this->countRows('customers');

        $second = $this->save(['account_number' => 'ACC-1']);

        $this->assertTrue($first['success']);
        $this->assertFalse($second['success']);
        $this->assertSame(lang('Customers.account_number_duplicate'), $second['message']);
        $this->assertSame($before, $this->countRows('customers'));
    }

    public function testPostSave_RefusesTheNumberOfAnotherCustomerWhenEditingToo(): void
    {
        $first  = $this->save(['account_number' => 'ACC-1']);
        $second = $this->save(['account_number' => 'ACC-2']);

        $edited = $this->save(['account_number' => 'ACC-1'], (int) $second['id']);

        $this->assertTrue($first['success']);
        $this->assertFalse($edited['success']);
        $this->assertSame('ACC-2', $this->accountNumberOf((int) $second['id']));
    }

    public function testPostSave_KeepsTheOwnAccountNumberWhenTheCustomerIsEdited(): void
    {
        $created = $this->save(['account_number' => 'ACC-1']);

        $edited = $this->save(['account_number' => 'ACC-1'], (int) $created['id']);

        $this->assertTrue($edited['success']);
        $this->assertSame('ACC-1', $this->accountNumberOf((int) $created['id']));
    }

    public function testPostSave_AcceptsAnAccountNumberAnotherCustomerHasWhenTheOptionIsOff(): void
    {
        $this->switchOn('C', 5, false);
        $this->save(['account_number' => 'ACC-1']);

        $second = $this->save(['account_number' => 'ACC-1']);

        $this->assertTrue($second['success']);
        $this->assertSame('ACC-1', $this->accountNumberOf((int) $second['id']));
    }

    public function testPostSave_RefusesTheDuplicateByDefaultBeforeTheSequenceIsEverConfigured(): void
    {
        $this->save(['account_number' => 'ACC-9']);

        $this->assertFalse($this->save(['account_number' => 'ACC-9'])['success']);
    }

    public function testGetView_OffersTheNextAutomaticNumberOnTheFormOfANewCustomer(): void
    {
        $this->switchOn('C', 5);

        $response = $this->get('/customers/view/-1');

        $response->assertSee(lang('Customers.account_number_automatic', ['C00001']));
    }

    public function testGetView_OffersNoAutomaticNumberWhileTheSequenceIsOff(): void
    {
        $this->get('/customers/view/-1')->assertDontSee(lang('Customers.account_number_automatic', ['']));
    }

    public function testGetView_OffersNoAutomaticNumberOnTheFormOfACustomerThatExists(): void
    {
        $created = $this->save(['account_number' => 'ACC-1']);
        $this->switchOn('C', 5);

        $response = $this->get('/customers/view/' . $created['id']);

        $response->assertDontSee(lang('Customers.account_number_automatic', ['C00001']));
        $response->assertSee('value="ACC-1"');
    }

    public function testPostCheckAccountNumber_SaysTheNumberIsTakenEvenWhenSeveralCustomersShareIt(): void
    {
        $first  = $this->insertCustomer('One', 'Customer');
        $second = $this->insertCustomer('Two', 'Customer');
        $this->accountingDb()->table('customers')->whereIn('person_id', [$first, $second])->update(['account_number' => 'DUP-1']);

        $taken = json_decode($this->post('/customers/checkAccountNumber', ['account_number' => 'DUP-1', 'person_id' => '-1'])->getJSON(), true);
        $free  = json_decode($this->post('/customers/checkAccountNumber', ['account_number' => 'DUP-2', 'person_id' => '-1'])->getJSON(), true);

        $this->assertFalse($taken);
        $this->assertTrue($free);
    }
}
