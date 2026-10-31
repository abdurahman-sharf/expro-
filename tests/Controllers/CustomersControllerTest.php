<?php

namespace Tests\Controllers;

use App\Libraries\Accounting\AccountingGuard;
use App\Libraries\Accounting\CustomerLedger;
use App\Libraries\Accounting\CustomerOpening;
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

        $this->setConfigValue('accounting_enabled', '0');
        $this->setConfigValue('accounting_customer_opening', '0');

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
        $this->get('/customers/view/-1')->assertDontSee(lang('Customers.account_number_automatic', ['1']));
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

    public function testPostCheckAccountNumber_AcceptsATakenNumberWhenTheOptionIsOff(): void
    {
        $this->switchOn('C', 5, false);
        $this->save(['account_number' => 'ACC-1']);

        $answer = json_decode($this->post('/customers/checkAccountNumber', ['account_number' => 'ACC-1', 'person_id' => '-1'])->getJSON(), true);

        $this->assertTrue($answer);
    }

    public function testPostCheckAccountNumber_RefusesATakenNumberAgainWhenTheOptionIsSwitchedOnAgain(): void
    {
        $this->switchOn('C', 5, false);
        $this->save(['account_number' => 'ACC-1']);
        (new DocumentNumbering())->configure(DocumentType::Customer, ['prevent_duplicates' => true]);

        $answer = json_decode($this->post('/customers/checkAccountNumber', ['account_number' => 'ACC-1', 'person_id' => '-1'])->getJSON(), true);

        $this->assertFalse($answer);
    }

    /**
     * @param list<string> $accountNumbers
     *
     * @return array{success: bool, message: string}
     */
    private function importCustomers(array $accountNumbers): array
    {
        $file = tempnam(sys_get_temp_dir(), 'customers_');
        $rows = [['First Name', 'Last Name', 'Gender', 'Consent', 'Email', 'Phone', 'Address 1', 'Address 2', 'City', 'State', 'Zip', 'Country', 'Comments', 'Company', 'Account Number', 'Discount', 'Discount Type', 'Taxable']];

        foreach ($accountNumbers as $accountNumber) {
            $rows[] = ['Imported', 'K' . uniqid(), '1', '1', 'csv.' . uniqid() . '@test.com', '', '', '', '', '', '', '', '', '', $accountNumber, '', '', ''];
        }

        $handle = fopen($file, 'w');

        foreach ($rows as $row) {
            fputcsv($handle, $row);
        }

        fclose($handle);

        $_FILES['file_path'] = ['name' => 'customers.csv', 'type' => 'text/csv', 'tmp_name' => $file, 'error' => UPLOAD_ERR_OK, 'size' => filesize($file)];

        try {
            $result = json_decode($this->post('/customers/importCsvFile')->getJSON(), true);
        } finally {
            unset($_FILES['file_path']);
            unlink($file);
        }

        foreach ($accountNumbers as $accountNumber) {
            foreach ($this->accountingDb()->table('customers')->select('person_id')->where('account_number', $accountNumber)->get()->getResult() as $customer) {
                $this->customerIds[] = (int) $customer->person_id;
            }
        }

        return $result;
    }

    private function customersWithNumber(string $accountNumber): int
    {
        return $this->accountingDb()->table('customers')->where('account_number', $accountNumber)->countAllResults();
    }

    public function testPostImportCsvFile_RejectsARowWhoseAccountNumberAnotherCustomerHasEvenWithANewEmail(): void
    {
        $this->save(['account_number' => 'ACC-1']);

        $result = $this->importCustomers(['ACC-1']);

        $this->assertFalse($result['success']);
        $this->assertSame(1, $this->customersWithNumber('ACC-1'));
    }

    public function testPostImportCsvFile_RejectsTheSecondRowOfTheFileThatRepeatsAnAccountNumber(): void
    {
        $result = $this->importCustomers(['ACC-2', 'ACC-2']);

        $this->assertFalse($result['success']);
        $this->assertSame(1, $this->customersWithNumber('ACC-2'));
    }

    public function testPostImportCsvFile_AcceptsARowWhoseAccountNumberAnotherCustomerHasWhenTheOptionIsOff(): void
    {
        $this->switchOn('C', 5, false);
        $this->save(['account_number' => 'ACC-1']);

        $result = $this->importCustomers(['ACC-1']);

        $this->assertTrue($result['success']);
        $this->assertSame(2, $this->customersWithNumber('ACC-1'));
    }

    public function testPostImportCsvFile_AcceptsRowsWithDifferentAccountNumbers(): void
    {
        $result = $this->importCustomers(['ACC-3', 'ACC-4']);

        $this->assertTrue($result['success']);
        $this->assertSame(1, $this->customersWithNumber('ACC-3'));
        $this->assertSame(1, $this->customersWithNumber('ACC-4'));
    }

    private function startAccountingWithCustomerOpening(): void
    {
        $this->reinstallChart();
        $this->setConfigValue('accounting_base_currency', 'SAR');
        $this->setConfigValue('accounting_start_date', '2020-01-01');
        $this->setConfigValue('accounting_enabled', '1');
        $this->setConfigValue('accounting_customer_opening', '1');
        $this->withSession(['person_id' => $this->employeeWithGrants(['customers', AccountingGuard::OPENING_MANAGE]), 'menu_group' => 'office']);
    }

    private function openingOf(int $customerId): ?object
    {
        return (new CustomerOpening())->standingFor($customerId);
    }

    public function testGetView_OffersTheOpeningBalanceOnTheFormOfANewCustomerWhileAccountingAndTheSettingAreOn(): void
    {
        $this->startAccountingWithCustomerOpening();

        $form = $this->get('/customers/view/-1');

        $form->assertSee('id="opening_amount"');
        $form->assertSee('id="opening_date"');
        $form->assertSee(lang('Customers.opening_owes_us'));
    }

    public function testGetView_OffersNoOpeningBalanceWhileTheSettingIsOffAccountingIsOffOrTheEmployeeDoesNotManageOpeningBalances(): void
    {
        $this->startAccountingWithCustomerOpening();
        $this->setConfigValue('accounting_customer_opening', '0');

        $this->get('/customers/view/-1')->assertDontSee('id="opening_amount"');

        $this->setConfigValue('accounting_customer_opening', '1');
        $this->setConfigValue('accounting_enabled', '0');

        $this->get('/customers/view/-1')->assertDontSee('id="opening_amount"');

        $this->setConfigValue('accounting_enabled', '1');
        $this->withSession(['person_id' => $this->employeeWithGrants(['customers']), 'menu_group' => 'office']);

        $this->get('/customers/view/-1')->assertDontSee('id="opening_amount"');
    }

    public function testGetView_OffersNoOpeningBalanceOnTheFormOfACustomerThatExists(): void
    {
        $this->startAccountingWithCustomerOpening();
        $created = $this->save();

        $this->get('/customers/view/' . $created['id'])->assertDontSee('id="opening_amount"');
    }

    public function testPostSave_EntersTheOpeningBalanceTypedOnTheFormOfANewCustomerWithItsDate(): void
    {
        $this->startAccountingWithCustomerOpening();
        $date = date('Y-m-d', strtotime('-20 days'));

        $result = $this->save(['opening_side' => 'owes_us', 'opening_amount' => '750.00', 'opening_date' => $date]);

        $this->assertTrue($result['success']);
        $this->assertStringNotContainsString(lang('Customers.opening_not_entered', ['']), $result['message']);

        $opening = $this->openingOf($result['id']);

        $this->assertNotNull($opening);
        $this->assertSame(['owes_us', '750.0000', $date], [$opening->side, $opening->amount, $opening->opening_date]);

        $position = (new CustomerLedger())->position($result['id']);

        $this->assertSame('750.0000', $position->open());
        $this->assertTrue($position->agrees());
    }

    public function testPostSave_EntersNothingWhenNoAmountIsTyped(): void
    {
        $this->startAccountingWithCustomerOpening();

        $result = $this->save(['opening_side' => 'owes_us', 'opening_amount' => '  ', 'opening_date' => date('Y-m-d')]);

        $this->assertTrue($result['success']);
        $this->assertNull($this->openingOf($result['id']));
        $this->assertSame(0, $this->countRows('customer_openings'));
    }

    public function testPostSave_SavesTheCustomerAndSaysWhyTheOpeningBalanceWasNotEnteredWhenTheBooksRefuseIt(): void
    {
        $this->startAccountingWithCustomerOpening();
        $this->closeThrough(date('Y-m-d', strtotime('-10 days')));

        $result = $this->save(['opening_side' => 'owes_us', 'opening_amount' => '750.00', 'opening_date' => date('Y-m-d', strtotime('-20 days'))]);

        $this->assertTrue($result['success'], 'The customer is saved all the same');
        $this->assertNull($this->openingOf($result['id']));
        $this->assertSame(0, $this->countRows('customer_openings'));
        $this->assertSame(1, $this->accountingDb()->table('customers')->where('person_id', $result['id'])->countAllResults());
        $this->assertStringContainsString(substr(lang('Customers.opening_not_entered', ['x']), 0, 20), $result['message']);
    }

    public function testPostSave_IgnoresAnOpeningBalanceWhileTheSettingIsOffAndWithoutThePermission(): void
    {
        $this->startAccountingWithCustomerOpening();
        $this->setConfigValue('accounting_customer_opening', '0');

        $off = $this->save(['opening_side' => 'owes_us', 'opening_amount' => '10.00', 'opening_date' => date('Y-m-d')]);

        $this->assertTrue($off['success']);
        $this->assertNull($this->openingOf($off['id']));

        $this->setConfigValue('accounting_customer_opening', '1');
        $this->withSession(['person_id' => $this->employeeWithGrants(['customers']), 'menu_group' => 'office']);

        $denied = $this->save(['opening_side' => 'owes_us', 'opening_amount' => '10.00', 'opening_date' => date('Y-m-d')]);

        $this->assertTrue($denied['success']);
        $this->assertNull($this->openingOf($denied['id']));
        $this->assertSame(0, $this->countRows('customer_openings'));
    }

    public function testPostSave_NeverEntersAnOpeningBalanceForACustomerThatIsEdited(): void
    {
        $this->startAccountingWithCustomerOpening();
        $created = $this->save();

        $edited = $this->save(['opening_side' => 'owes_us', 'opening_amount' => '10.00', 'opening_date' => date('Y-m-d')], (int) $created['id']);

        $this->assertTrue($edited['success']);
        $this->assertNull($this->openingOf((int) $created['id']));
    }
}
