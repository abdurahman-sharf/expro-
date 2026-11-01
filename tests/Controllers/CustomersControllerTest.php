<?php

namespace Tests\Controllers;

use App\Libraries\Accounting\AccountingGuard;
use App\Libraries\Accounting\CreditLimit;
use App\Libraries\Accounting\CustomerLedger;
use App\Libraries\Accounting\CustomerOpening;
use App\Libraries\Numbering\DocumentNumbering;
use App\Libraries\Numbering\DocumentType;
use App\Libraries\Pricing\SalesControlSettings;
use App\Models\Price_list;
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

    public function testGetView_OffersNoOpeningBalanceToAnEmployeeLimitedToSomeBranches(): void
    {
        $this->startAccountingWithCustomerOpening();
        $this->withSession(['person_id' => $this->employeeWithGrants(['customers', AccountingGuard::OPENING_MANAGE], [1]), 'menu_group' => 'office']);

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
        $this->assertStringNotContainsString(substr(lang('Customers.opening_not_entered', ['x']), 0, 20), $off['message'], 'Nothing was attempted where the field is not offered');

        $this->setConfigValue('accounting_customer_opening', '1');
        $this->withSession(['person_id' => $this->employeeWithGrants(['customers']), 'menu_group' => 'office']);

        $denied = $this->save(['opening_side' => 'owes_us', 'opening_amount' => '10.00', 'opening_date' => date('Y-m-d')]);

        $this->assertTrue($denied['success']);
        $this->assertNull($this->openingOf($denied['id']));
        $this->assertStringNotContainsString(substr(lang('Customers.opening_not_entered', ['x']), 0, 20), $denied['message'], 'Nothing was attempted where the field is not offered');
        $this->assertSame(0, $this->countRows('customer_openings'));
    }

    public function testPostSave_IgnoresAnOpeningBalanceTypedByAnEmployeeLimitedToSomeBranches(): void
    {
        $this->startAccountingWithCustomerOpening();
        $this->withSession(['person_id' => $this->employeeWithGrants(['customers', AccountingGuard::OPENING_MANAGE], [1]), 'menu_group' => 'office']);

        $result = $this->save(['opening_side' => 'owes_us', 'opening_amount' => '500.00', 'opening_date' => date('Y-m-d')]);

        $this->assertTrue($result['success']);
        $this->assertNull($this->openingOf($result['id']));
        $this->assertSame(0, $this->countRows('customer_openings'));
        $this->assertStringNotContainsString(substr(lang('Customers.opening_not_entered', ['x']), 0, 20), $result['message'], 'Nothing was attempted where the field is not offered');
    }

    public function testPostSave_IgnoresAnOpeningBalanceSentForACustomerThatIsEdited(): void
    {
        $this->startAccountingWithCustomerOpening();
        $created = $this->save();

        $edited = $this->save(['opening_side' => 'owes_us', 'opening_amount' => '10.00', 'opening_date' => date('Y-m-d')], (int) $created['id']);

        $this->assertTrue($edited['success']);
        $this->assertNull($this->openingOf((int) $created['id']));
    }

    // ---------------------------------------------------------------------------------------
    // WP36: the price list, the credit limit and the credit days of a customer
    // ---------------------------------------------------------------------------------------

    private function resetCustomerControls(): void
    {
        $db = $this->accountingDb();

        foreach ([SalesControlSettings::PRICE_LISTS, SalesControlSettings::CREDIT_LIMIT, SalesControlSettings::CREDIT_PERIOD] as $key) {
            $this->setConfigValue($key, '0');
        }

        $db->table('customers')->update(['price_list_id' => null, 'credit_limit' => 0, 'credit_period_days' => 0]);
        $db->table('price_lists')->where('price_list_id >', 0)->delete();
        $db->table('accounting_audit_log')->whereIn('action', ['customer_price_list_changed', 'customer_credit_changed'])->delete();
    }

    /**
     * @param list<string> $permissions
     */
    private function actAsEmployeeWith(array $permissions): int
    {
        $personId = $this->employeeWithGrants(['customers', ...$permissions]);

        $this->withSession(['person_id' => $personId, 'menu_group' => 'office']);

        return $personId;
    }

    public function testGetView_OffersNoPricingAndCreditSectionWhileTheirControlsAreOff(): void
    {
        $customerId = $this->insertCustomer();

        $page = $this->get('/customers/view/' . $customerId);

        $page->assertDontSee('id="customer_pricing_section"');
        $page->assertDontSee('name="credit_limit"');
        $page->assertDontSee('name="price_list_id"');
    }

    public function testGetView_OffersThePriceListsAndLocksTheChoiceForAnEmployeeWithoutThePriceListsModule(): void
    {
        $this->setConfigValue(SalesControlSettings::PRICE_LISTS, '1');

        $lists      = model(Price_list::class);
        $active     = $lists->saveList('Wholesale', true, null, null);
        $retired    = $lists->saveList('Retired', false, null, null);
        $customerId = $this->insertCustomer();

        $this->actAsEmployeeWith(['price_lists']);

        $page = $this->get('/customers/view/' . $customerId);

        $page->assertSee('id="customer_pricing_section"');
        $page->assertSee('<option value="' . $active . '"');
        $page->assertDontSee('<option value="' . $retired . '"');
        $this->assertDoesNotMatchRegularExpression('/<select name="price_list_id"[^>]*disabled/', $page->getBody());

        $this->actAsEmployeeWith([]);

        $this->assertMatchesRegularExpression('/<select name="price_list_id"[^>]*disabled/', $this->get('/customers/view/' . $customerId)->getBody());

        $this->resetCustomerControls();
    }

    public function testGetView_ShowsAnInactiveListTheCustomerStillHolds(): void
    {
        $this->setConfigValue(SalesControlSettings::PRICE_LISTS, '1');

        $lists      = model(Price_list::class);
        $retired    = $lists->saveList('Retired', true, null, null);
        $customerId = $this->insertCustomer();

        $lists->assignTo($customerId, $retired, null);
        $lists->setStatus($retired, false, null);
        $this->actAsEmployeeWith(['price_lists']);

        $page = $this->get('/customers/view/' . $customerId);

        $this->assertStringContainsString('<option value="' . $retired . '" selected>Retired (' . lang('Price_lists.inactive') . ')</option>', $page->getBody());

        $this->resetCustomerControls();
    }

    public function testGetView_OffersTheCreditFieldsOnlyWhileAccountingAndTheirControlsAreOnAndLocksThemForWhoMayNotManageReceivables(): void
    {
        $customerId = $this->insertCustomer();

        $this->accountingDb()->table('customers')->where('person_id', $customerId)->update(['credit_limit' => '750.5', 'credit_period_days' => 45]);
        $this->setConfigValue(SalesControlSettings::CREDIT_LIMIT, '1');
        $this->setConfigValue(SalesControlSettings::CREDIT_PERIOD, '1');

        $this->actAsEmployeeWith([AccountingGuard::RECEIVABLES_MANAGE]);
        $this->get('/customers/view/' . $customerId)->assertDontSee('name="credit_limit"');

        $this->setConfigValue('accounting_enabled', '1');

        $page = $this->get('/customers/view/' . $customerId);

        $page->assertSee('name="credit_limit"');
        $page->assertSee('value="750.50"');
        $page->assertSee('value="45"');
        $this->assertDoesNotMatchRegularExpression('/<input[^>]*name="credit_limit"[^>]*disabled/', $page->getBody());

        $this->actAsEmployeeWith([]);

        $this->assertMatchesRegularExpression('/<input[^>]*name="credit_limit"[^>]*disabled/', $this->get('/customers/view/' . $customerId)->getBody());

        $this->resetCustomerControls();
    }

    public function testGetView_ShowsTheLimitWithoutTheDaysWhenOnlyTheLimitControlIsOn(): void
    {
        $this->setConfigValue('accounting_enabled', '1');
        $this->setConfigValue(SalesControlSettings::CREDIT_LIMIT, '1');
        $this->actAsEmployeeWith([AccountingGuard::RECEIVABLES_MANAGE]);

        $page = $this->get('/customers/view/' . $this->insertCustomer());

        $page->assertSee('name="credit_limit"');
        $page->assertDontSee('name="credit_period_days"');

        $this->resetCustomerControls();
    }

    public function testPostSave_AssignsAPriceListWritesItAndTakesItAwayAgain(): void
    {
        $this->setConfigValue(SalesControlSettings::PRICE_LISTS, '1');

        $listId   = model(Price_list::class)->saveList('Wholesale', true, null, null);
        $employee = $this->actAsEmployeeWith(['price_lists']);

        $result = $this->save(['price_list_id' => (string) $listId]);

        $this->assertTrue($result['success']);

        $customerId = (int) $result['id'];

        $this->assertSame($listId, model(Price_list::class)->assignedTo($customerId));

        $entry = $this->accountingDb()->table('accounting_audit_log')->where('action', 'customer_price_list_changed')->get()->getRow();

        $this->assertSame($customerId, (int) $entry->entity_id);
        $this->assertSame($employee, (int) $entry->employee_id);

        $this->assertTrue($this->save(['price_list_id' => '0'], $customerId)['success']);
        $this->assertNull(model(Price_list::class)->assignedTo($customerId));

        $this->resetCustomerControls();
    }

    public function testPostSave_KeepsTheListOfACustomerWhenTheFormDoesNotSendOne(): void
    {
        $this->setConfigValue(SalesControlSettings::PRICE_LISTS, '1');

        $listId     = model(Price_list::class)->saveList('Wholesale', true, null, null);
        $customerId = $this->insertCustomer();

        model(Price_list::class)->assignTo($customerId, $listId, null);
        $this->actAsEmployeeWith(['price_lists']);

        $this->assertTrue($this->save([], $customerId)['success']);
        $this->assertSame($listId, model(Price_list::class)->assignedTo($customerId));

        $this->resetCustomerControls();
    }

    public function testPostSave_DoesNotAssignAListForAnEmployeeWithoutThePriceListsModuleOrWhileTheListsAreOff(): void
    {
        $listId = model(Price_list::class)->saveList('Wholesale', true, null, null);

        $this->actAsEmployeeWith(['price_lists']);

        $off = $this->save(['price_list_id' => (string) $listId]);

        $this->assertNull(model(Price_list::class)->assignedTo((int) $off['id']), 'the price lists are off');

        $this->setConfigValue(SalesControlSettings::PRICE_LISTS, '1');
        $this->actAsEmployeeWith([]);

        $without = $this->save(['price_list_id' => (string) $listId]);

        $this->assertTrue($without['success']);
        $this->assertNull(model(Price_list::class)->assignedTo((int) $without['id']));

        $this->resetCustomerControls();
    }

    public function testPostSave_SaysWhyAListThatIsNotActiveWasRefused(): void
    {
        $this->setConfigValue(SalesControlSettings::PRICE_LISTS, '1');

        $listId = model(Price_list::class)->saveList('Retired', false, null, null);

        $this->actAsEmployeeWith(['price_lists']);

        $result = $this->save(['price_list_id' => (string) $listId]);

        $this->assertFalse($result['success']);
        $this->assertSame(lang('Accounting.error_price_list_inactive'), $result['message']);

        $this->resetCustomerControls();
    }

    public function testPostSave_SavesTheCreditLimitAndTheDaysForWhoMayManageReceivablesAndWritesThem(): void
    {
        $this->setConfigValue('accounting_enabled', '1');
        $this->setConfigValue(SalesControlSettings::CREDIT_LIMIT, '1');
        $this->setConfigValue(SalesControlSettings::CREDIT_PERIOD, '1');

        $employee = $this->actAsEmployeeWith([AccountingGuard::RECEIVABLES_MANAGE]);
        $result   = $this->save(['credit_limit' => '1250.75', 'credit_period_days' => '30']);

        $this->assertTrue($result['success']);

        $customerId = (int) $result['id'];

        $this->assertSame(['limit' => '1250.7500', 'days' => 30], (new CreditLimit())->termsOf($customerId));

        $entry = $this->accountingDb()->table('accounting_audit_log')->where('action', 'customer_credit_changed')->get()->getRow();

        $this->assertSame($customerId, (int) $entry->entity_id);
        $this->assertSame($employee, (int) $entry->employee_id);

        $this->resetCustomerControls();
    }

    public function testPostSave_KeepsTheDaysWhenOnlyTheLimitControlIsOn(): void
    {
        $this->setConfigValue('accounting_enabled', '1');
        $this->setConfigValue(SalesControlSettings::CREDIT_LIMIT, '1');

        $customerId = $this->insertCustomer();

        $this->accountingDb()->table('customers')->where('person_id', $customerId)->update(['credit_limit' => '10', 'credit_period_days' => 12]);
        $this->actAsEmployeeWith([AccountingGuard::RECEIVABLES_MANAGE]);

        $this->assertTrue($this->save(['credit_limit' => '99', 'credit_period_days' => '77'], $customerId)['success']);
        $this->assertSame(['limit' => '99.0000', 'days' => 12], (new CreditLimit())->termsOf($customerId));

        $this->resetCustomerControls();
    }

    public function testPostSave_KeepsTheLimitWhenOnlyTheDaysControlIsOn(): void
    {
        $this->setConfigValue('accounting_enabled', '1');
        $this->setConfigValue(SalesControlSettings::CREDIT_PERIOD, '1');

        $customerId = $this->insertCustomer();

        $this->accountingDb()->table('customers')->where('person_id', $customerId)->update(['credit_limit' => '10', 'credit_period_days' => 12]);
        $this->actAsEmployeeWith([AccountingGuard::RECEIVABLES_MANAGE]);

        $this->assertTrue($this->save(['credit_limit' => '99', 'credit_period_days' => '77'], $customerId)['success']);
        $this->assertSame(['limit' => '10.0000', 'days' => 77], (new CreditLimit())->termsOf($customerId));

        $this->resetCustomerControls();
    }

    public function testPostSave_DoesNotChangeTheCreditTermsForAnEmployeeWhoMayNotManageReceivables(): void
    {
        $this->setConfigValue('accounting_enabled', '1');
        $this->setConfigValue(SalesControlSettings::CREDIT_LIMIT, '1');

        $customerId = $this->insertCustomer();

        $this->accountingDb()->table('customers')->where('person_id', $customerId)->update(['credit_limit' => '10']);
        $this->actAsEmployeeWith([]);

        $this->assertTrue($this->save(['credit_limit' => '99999'], $customerId)['success']);
        $this->assertSame('10.0000', (new CreditLimit())->termsOf($customerId)['limit']);
        $this->assertSame(0, $this->accountingDb()->table('accounting_audit_log')->where('action', 'customer_credit_changed')->countAllResults());

        $this->resetCustomerControls();
    }

    public function testPostSave_DoesNotChangeTheCreditTermsForAnEmployeeLimitedToSomeBranches(): void
    {
        $this->setConfigValue('accounting_enabled', '1');
        $this->setConfigValue(SalesControlSettings::CREDIT_LIMIT, '1');

        $customerId = $this->insertCustomer();
        $limited    = $this->employeeWithGrants(['customers', AccountingGuard::RECEIVABLES_MANAGE], [1]);

        $this->withSession(['person_id' => $limited, 'menu_group' => 'office']);

        $this->assertTrue($this->save(['credit_limit' => '5'], $customerId)['success']);
        $this->assertSame('0.0000', (new CreditLimit())->termsOf($customerId)['limit'], 'the limit holds for the whole company, so a branch employee does not set it');

        $this->resetCustomerControls();
    }

    public function testPostSave_DoesNotChangeTheCreditTermsWhileAccountingIsOffOrTheirControlIsOff(): void
    {
        $customerId = $this->insertCustomer();

        $this->actAsEmployeeWith([AccountingGuard::RECEIVABLES_MANAGE]);
        $this->setConfigValue(SalesControlSettings::CREDIT_LIMIT, '1');

        $this->assertTrue($this->save(['credit_limit' => '5'], $customerId)['success']);
        $this->assertSame('0.0000', (new CreditLimit())->termsOf($customerId)['limit'], 'accounting is off');

        $this->setConfigValue('accounting_enabled', '1');
        $this->setConfigValue(SalesControlSettings::CREDIT_LIMIT, '0');

        $this->assertTrue($this->save(['credit_limit' => '5'], $customerId)['success']);
        $this->assertSame('0.0000', (new CreditLimit())->termsOf($customerId)['limit'], 'the control is off');

        $this->resetCustomerControls();
    }

    public function testPostSave_SaysWhyCreditTermsThatAreNotNumbersWereRefused(): void
    {
        $this->setConfigValue('accounting_enabled', '1');
        $this->setConfigValue(SalesControlSettings::CREDIT_LIMIT, '1');
        $this->setConfigValue(SalesControlSettings::CREDIT_PERIOD, '1');

        $customerId = $this->insertCustomer();

        $this->actAsEmployeeWith([AccountingGuard::RECEIVABLES_MANAGE]);

        foreach ([['credit_limit' => '-5'], ['credit_limit' => 'big'], ['credit_period_days' => '-1'], ['credit_period_days' => '70000']] as $invalid) {
            $result = $this->save($invalid, $customerId);

            $this->assertFalse($result['success'], json_encode($invalid));
            $this->assertSame(lang('Accounting.error_credit_terms_invalid'), $result['message']);
        }

        $this->assertSame(['limit' => '0.0000', 'days' => 0], (new CreditLimit())->termsOf($customerId));

        $this->resetCustomerControls();
    }
}
