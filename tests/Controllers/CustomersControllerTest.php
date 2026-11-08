<?php

namespace Tests\Controllers;

use App\Libraries\Accounting\AccountingGuard;
use App\Libraries\Accounting\CreditLimit;
use App\Libraries\Accounting\CustomerLedger;
use App\Libraries\Accounting\CustomerOpening;
use App\Libraries\Accounting\EntityAccountService;
use App\Libraries\MasterData\CustomerStatus;
use App\Libraries\MasterData\CustomerType;
use App\Libraries\MasterData\LookupKind;
use App\Libraries\MasterData\NamedLookup;
use App\Libraries\Numbering\DocumentNumbering;
use App\Libraries\Numbering\DocumentType;
use App\Libraries\Pricing\SalesControlSettings;
use App\Models\Price_list;
use App\Models\Sale;
use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\DatabaseTestTrait;
use CodeIgniter\Test\FeatureTestTrait;
use Config\Database;
use Config\OSPOS;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Support\AccountingFixtureTrait;
use Tests\Support\EmployeeFixtureTrait;
use Tests\Support\ItemFixtureTrait;

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
    use ItemFixtureTrait;

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

    public function testGetView_ShowsTheNameOfAListAsTextAndNeverAsMarkup(): void
    {
        $this->setConfigValue(SalesControlSettings::PRICE_LISTS, '1');

        model(Price_list::class)->saveList('<b>Gold</b> & <i>VIP</i>', true, null, null);
        $this->actAsEmployeeWith(['price_lists']);

        $body = $this->get('/customers/view/' . $this->insertCustomer())->getBody();

        $this->assertStringContainsString('&lt;b&gt;Gold&lt;/b&gt; &amp; &lt;i&gt;VIP&lt;/i&gt;', $body);
        $this->assertStringNotContainsString('<b>Gold</b>', $body);

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

    public function testPostSave_SavesNoNewCustomerAndEntersNoOpeningBalanceWhenTheCreditTermsAreRefused(): void
    {
        $this->startAccountingWithCustomerOpening();
        $this->setConfigValue(SalesControlSettings::CREDIT_LIMIT, '1');
        $this->setConfigValue(SalesControlSettings::CREDIT_PERIOD, '1');
        $this->withSession(['person_id' => $this->employeeWithGrants(['customers', AccountingGuard::OPENING_MANAGE, AccountingGuard::RECEIVABLES_MANAGE]), 'menu_group' => 'office']);

        $customers = $this->countRows('customers');
        $people    = $this->countRows('people');

        foreach ([['credit_limit' => '-100'], ['credit_limit' => 'abc'], ['credit_period_days' => '70000'], ['credit_period_days' => 'abc']] as $invalid) {
            $result = $this->save(['opening_side' => 'owes_us', 'opening_amount' => '5000.00', 'opening_date' => date('Y-m-d'), ...$invalid]);

            $this->assertFalse($result['success'], json_encode($invalid));
            $this->assertSame(lang('Accounting.error_credit_terms_invalid'), $result['message']);
            $this->assertSame(NEW_ENTRY, $result['id']);
        }

        $this->assertSame([$customers, $people, 0], [$this->countRows('customers'), $this->countRows('people'), $this->countRows('customer_openings')]);

        $this->resetCustomerControls();
    }

    public function testPostSave_SavesNoNewCustomerWhenTheListTheFormNamesIsNotActive(): void
    {
        $this->setConfigValue(SalesControlSettings::PRICE_LISTS, '1');

        $listId = model(Price_list::class)->saveList('Retired', false, null, null);

        $this->actAsEmployeeWith(['price_lists']);

        $customers = $this->countRows('customers');
        $result    = $this->save(['price_list_id' => (string) $listId]);

        $this->assertFalse($result['success']);
        $this->assertSame(lang('Accounting.error_price_list_inactive'), $result['message']);
        $this->assertSame($customers, $this->countRows('customers'));

        $this->resetCustomerControls();
    }

    public function testPostSave_ChangesNothingOfACustomerThatExistsWhenTheCreditTermsAreRefused(): void
    {
        $this->setConfigValue('accounting_enabled', '1');
        $this->setConfigValue(SalesControlSettings::CREDIT_LIMIT, '1');

        $customerId = $this->insertCustomer('Omar', 'Khaled');

        $this->actAsEmployeeWith([AccountingGuard::RECEIVABLES_MANAGE]);

        $result = $this->save(['first_name' => 'Changed', 'credit_limit' => 'big'], $customerId);

        $this->assertFalse($result['success']);
        $this->assertSame('Omar', $this->accountingDb()->table('people')->where('person_id', $customerId)->get()->getRow()->first_name);

        $this->resetCustomerControls();
    }

    public function testPostSave_SavesTheCustomerAndTheOpeningBalanceAndSaysWhyTheListWasNotGivenWhenItWasDeactivatedWhileTheCustomerWasSaved(): void
    {
        $this->startAccountingWithCustomerOpening();
        $this->setConfigValue(SalesControlSettings::PRICE_LISTS, '1');

        $listId = model(Price_list::class)->saveList('Racing', true, null, null);

        $this->withSession(['person_id' => $this->employeeWithGrants(['customers', 'price_lists', AccountingGuard::OPENING_MANAGE]), 'menu_group' => 'office']);

        $db      = $this->accountingDb();
        $trigger = $db->prefixTable('wp36_deactivate_list');

        $db->query('CREATE TRIGGER ' . $trigger . ' AFTER INSERT ON ' . $db->prefixTable('customers') . ' FOR EACH ROW UPDATE ' . $db->prefixTable('price_lists') . ' SET status = 0');

        try {
            $result = $this->save(['price_list_id' => (string) $listId, 'opening_side' => 'owes_us', 'opening_amount' => '300.00', 'opening_date' => date('Y-m-d')]);
        } finally {
            $db->query('DROP TRIGGER IF EXISTS ' . $trigger);
        }

        $this->assertTrue($result['success'], 'the customer is saved, and the form says what was left out');
        $this->assertStringContainsString(lang('Customers.pricing_not_saved', [lang('Accounting.error_price_list_inactive')]), $result['message']);
        $this->assertNull(model(Price_list::class)->assignedTo((int) $result['id']));
        $this->assertSame('300.0000', $this->openingOf((int) $result['id'])?->amount, 'the opening balance is entered all the same');

        $this->resetCustomerControls();
    }

    // ========== master data: classification, status, employee, area, country, type, billing ==========

    /** @var list<int> */
    private array $masterItems = [];

    private function classification(string $name, bool $active = true): int
    {
        return (new NamedLookup(LookupKind::CustomerClassification))->save($name, $active, null, 1);
    }

    /**
     * @param array<string, string> $extra
     *
     * @return array<string, mixed>
     */
    private function saveMaster(array $extra = [], int $customerId = NEW_ENTRY, array $form = []): array
    {
        return $this->save(['master_data' => '1'] + $extra + $form, $customerId);
    }

    private function customerRow(int $customerId): array
    {
        return $this->accountingDb()->table('customers')->where('person_id', $customerId)->get()->getRowArray();
    }

    /**
     * @param array<string, string> $query
     *
     * @return array{total: int, rows: list<array<string, mixed>>}
     */
    private function directory(array $query = []): array
    {
        return json_decode($this->get('/customers/search', $query + ['search' => '', 'limit' => '500', 'offset' => '0', 'order' => 'asc', 'sort' => 'people.person_id'])->getJSON(), true);
    }

    /**
     * @param array<string, string> $query
     *
     * @return list<int>
     */
    private function directoryIds(array $query = []): array
    {
        return array_map('intval', array_column($this->directory($query)['rows'], 'people.person_id'));
    }

    private function cleanMaster(): void
    {
        $db = $this->accountingDb();

        $db->table('customers')->update(['classification_id' => null, 'assigned_employee_id' => null]);
        $db->query('DELETE FROM ' . $db->prefixTable('customer_classifications'));
        $db->table('accounting_audit_log')->whereIn('entity_type', ['customer', 'customer_classification'])->delete();

        foreach ($this->masterItems as $itemId) {
            $db->table('item_quantities')->where('item_id', $itemId)->delete();
            $db->table('items')->where('item_id', $itemId)->delete();
        }

        $this->masterItems = [];
    }

    public function testPostSave_SavesTheMasterDataOfANewCustomerAndAuditsIt(): void
    {
        $classification = $this->classification('Wholesale');
        $employee       = $this->employeeWithGrants(['customers']);

        $result = $this->saveMaster([
            'classification_id'    => (string) $classification,
            'manual_status'        => 'requires_follow_up',
            'assigned_employee_id' => (string) $employee,
            'area'                 => 'Olaya',
            'country_code'         => 'sa',
            'customer_type'        => 'commercial',
            'is_government_entity' => '1',
            'billing_method'       => 'email',
        ]);

        $this->assertTrue($result['success']);

        $row = $this->customerRow($result['id']);

        $this->assertSame([$classification, 'requires_follow_up', $employee, 'Olaya', 'SA', 'commercial', 1, 'email'], [(int) $row['classification_id'], $row['manual_status'], (int) $row['assigned_employee_id'], $row['area'], $row['country_code'], $row['customer_type'], (int) $row['is_government_entity'], $row['billing_method']]);

        $audit = $this->accountingDb()->table('accounting_audit_log')->where(['entity_type' => 'customer', 'entity_id' => $result['id'], 'action' => 'customer_master_data_changed'])->get()->getRow();

        $this->assertNotNull($audit);
        $this->assertSame(1, (int) $audit->employee_id);
        $this->cleanMaster();
    }

    public function testPostSave_ChangesTheMasterDataOfACustomerAndClearsWhatTheFormLeavesEmpty(): void
    {
        $first  = $this->classification('Retail');
        $result = $this->saveMaster(['classification_id' => (string) $first, 'manual_status' => 'served', 'area' => 'Malaz', 'customer_type' => 'individual']);

        $this->saveMaster(['classification_id' => '', 'manual_status' => 'archived', 'area' => '', 'customer_type' => ''], (int) $result['id']);

        $row = $this->customerRow((int) $result['id']);

        $this->assertSame([null, 'archived', null, null], [$row['classification_id'], $row['manual_status'], $row['area'], $row['customer_type']]);
        $this->assertSame(2, $this->accountingDb()->table('accounting_audit_log')->where(['entity_type' => 'customer', 'entity_id' => $result['id'], 'action' => 'customer_master_data_changed'])->countAllResults());
        $this->cleanMaster();
    }

    public function testPostSave_LeavesTheMasterDataAloneWhenTheFormDoesNotCarryIt(): void
    {
        $result = $this->saveMaster(['manual_status' => 'interested', 'area' => 'Olaya', 'customer_type' => 'commercial', 'is_government_entity' => '1']);

        $this->save(['first_name' => 'Omar'], (int) $result['id']);

        $row = $this->customerRow((int) $result['id']);

        $this->assertSame(['interested', 'Olaya', 'commercial', '1'], [$row['manual_status'], $row['area'], $row['customer_type'], (string) $row['is_government_entity']]);
        $this->assertSame(1, $this->accountingDb()->table('accounting_audit_log')->where(['entity_type' => 'customer', 'entity_id' => $result['id']])->countAllResults(), 'Only the first save changed anything');
        $this->cleanMaster();
    }

    /**
     * @return array<string, array{0: string, 1: string}>
     */
    public static function invalidMasterValueProvider(): array
    {
        return [
            'a classification that does not exist' => ['classification_id', '987654'],
            'a status outside the five'            => ['manual_status', 'vip'],
            'an employee that does not exist'      => ['assigned_employee_id', '987654'],
            'an area over one hundred characters'  => ['area', 'ا'],
            'a country code of three letters'      => ['country_code', 'SAU'],
            'a type outside the two'               => ['customer_type', 'both'],
            'a billing method outside the two'     => ['billing_method', 'fax'],
        ];
    }

    #[DataProvider('invalidMasterValueProvider')]
    public function testPostSave_RejectsAnInvalidMasterValueWithANamedMessageAndSavesNothing(string $field, string $value): void
    {
        $value  = $field === 'area' ? str_repeat($value, 101) : $value;
        $before = $this->countRows('customers');

        $result = $this->saveMaster([$field => $value]);

        $this->assertFalse($result['success']);
        $this->assertNotSame('', $result['message']);
        $this->assertStringNotContainsString('Accounting.error_', $result['message']);
        $this->assertSame($before, $this->countRows('customers'));
    }

    public function testPostSave_RejectsAChangeThatTheValidationRefusesAndKeepsTheCustomerAsItWas(): void
    {
        $result = $this->saveMaster(['manual_status' => 'served', 'area' => 'Olaya']);

        $refused = $this->saveMaster(['manual_status' => 'vip', 'area' => 'Malaz'], (int) $result['id']);

        $this->assertFalse($refused['success']);
        $this->assertSame(['served', 'Olaya'], [$this->customerRow((int) $result['id'])['manual_status'], $this->customerRow((int) $result['id'])['area']]);
        $this->cleanMaster();
    }

    public function testPostSave_RequiresAnEmailForTheEmailBillingMethod(): void
    {
        $refused = $this->saveMaster(['billing_method' => 'email', 'email' => '']);

        $this->assertFalse($refused['success']);
        $this->assertStringContainsString('e-mail', strtolower($refused['message']));

        $accepted = $this->saveMaster(['billing_method' => 'email']);

        $this->assertTrue($accepted['success']);
        $this->assertSame('email', $this->customerRow((int) $accepted['id'])['billing_method']);
        $this->cleanMaster();
    }

    public function testPostSave_AcceptsAnEmptyEmailForPrintBilling(): void
    {
        $result = $this->saveMaster(['billing_method' => 'print', 'email' => '']);

        $this->assertTrue($result['success']);
        $this->assertSame('print', $this->customerRow((int) $result['id'])['billing_method']);
        $this->cleanMaster();
    }

    public function testPostSave_RejectsAnInactiveClassificationForANewCustomerButKeepsOneTheCustomerHolds(): void
    {
        $classification = $this->classification('Retired');
        $result         = $this->saveMaster(['classification_id' => (string) $classification]);
        (new NamedLookup(LookupKind::CustomerClassification))->setStatus($classification, false, 1);

        $this->assertFalse($this->saveMaster(['classification_id' => (string) $classification])['success']);
        $this->assertTrue($this->saveMaster(['classification_id' => (string) $classification, 'area' => 'Olaya'], (int) $result['id'])['success']);
        $this->assertSame($classification, (int) $this->customerRow((int) $result['id'])['classification_id']);
        $this->cleanMaster();
    }

    public function testPostSave_RejectsAnEmployeeOfAnotherBranchForAViewerLimitedToABranch(): void
    {
        $this->resetBranches();
        $branch   = $this->createBranch('B2');
        $other    = $this->createBranch('B3');
        $viewer   = $this->employeeWithGrants(['customers'], [$branch]);
        $stranger = $this->employeeWithGrants(['customers'], [$other]);
        $mate     = $this->employeeWithGrants(['customers'], [$branch]);
        $this->withSession(['person_id' => $viewer, 'menu_group' => 'office']);

        $this->assertFalse($this->saveMaster(['assigned_employee_id' => (string) $stranger])['success']);
        $this->assertTrue($this->saveMaster(['assigned_employee_id' => (string) $mate])['success']);

        $this->resetBranches();
        $this->cleanMaster();
    }

    public function testPostSave_SetsTheCreationDateOnceAndKeepsItWhenTheCustomerIsEdited(): void
    {
        $created = $this->save(['date' => '01/01/2001 00:00:00']);
        $row     = $this->customerRow((int) $created['id']);

        $this->assertSame(date('Y-m-d'), substr($row['date'], 0, 10), 'A new customer is created today whatever the form says');

        $this->accountingDb()->table('customers')->where('person_id', $created['id'])->update(['date' => '2020-05-17 09:30:00']);

        $this->save(['date' => date('m/d/Y H:i:s')], (int) $created['id']);

        $this->assertSame('2020-05-17 09:30:00', $this->customerRow((int) $created['id'])['date']);
    }

    public function testPostSave_SavesACustomerWhoseFormCarriesNoDateAtAll(): void
    {
        $response = $this->post('/customers/save/-1', [
            'first_name'   => 'Nada',
            'last_name'    => 'Z' . uniqid(),
            'email'        => '',
            'phone_number' => '',
            'address_1'    => '',
            'address_2'    => '',
            'city'         => '',
            'state'        => '',
            'zip'          => '',
            'country'      => '',
            'comments'     => '',
            'employee_id'  => '1',
            'tax_id'       => '',
            'taxable'      => '1',
        ]);
        $result = json_decode($response->getJSON(), true);

        $this->assertTrue($result['success'], (string) ($result['message'] ?? ''));
        $this->customerIds[] = (int) $result['id'];
        $this->assertSame(date('Y-m-d'), substr($this->customerRow((int) $result['id'])['date'], 0, 10));
    }

    public function testPostSave_KeepsThePostedDocumentsAndTheLedgerUntouchedWhenTheMasterDataChanges(): void
    {
        $this->startAccountingWithCustomerOpening();
        $named      = ['first_name' => 'Omar', 'last_name' => 'Fixed' . uniqid()];
        $created    = $this->saveMaster(['customer_type' => 'individual', 'opening_side' => 'owes_us', 'opening_amount' => '750.00', 'opening_date' => date('Y-m-d', strtotime('-20 days'))], NEW_ENTRY, $named);
        $customerId = (int) $created['id'];
        $this->assertTrue($created['success']);

        $invoiceId = $this->postedInvoiceOf($customerId, '100', '40');
        $draftId   = $this->postedInvoiceOf($customerId, '30', null, SUSPENDED);

        try {
            $before   = $this->documentFigures($customerId);
            $classify = $this->classification('Strategic');

            $this->assertGreaterThan(0, $before['journals'], 'the invoice posted its journal');
            $this->assertSame(1, $before['paid'], 'and took a payment');

            $this->assertTrue($this->saveMaster(['customer_type' => 'commercial', 'classification_id' => (string) $classify, 'manual_status' => 'archived', 'is_government_entity' => '1'], $customerId, $named)['success']);

            $this->assertSame($before, $this->documentFigures($customerId));
            $this->assertSame('commercial', $this->customerRow($customerId)['customer_type']);
            $this->assertSame(COMPLETED, (int) $this->accountingDb()->table('sales')->where('sale_id', $invoiceId)->get()->getRow()->sale_status);
            $this->assertSame(SUSPENDED, (int) $this->accountingDb()->table('sales')->where('sale_id', $draftId)->get()->getRow()->sale_status);
        } finally {
            $this->removeSales([$invoiceId, $draftId]);
            $this->cleanMaster();
        }
    }

    /**
     * @return int The sale the customer owes, with a cash payment when one is given.
     */
    private function postedInvoiceOf(int $customerId, string $price, ?string $paid, int $status = COMPLETED): int
    {
        (new EntityAccountService())->ensure(EntityAccountService::STOCK_LOCATION, 1, 'stock');

        $items    = [['item_id' => $this->masterItems[] = $this->createTestItem(HAS_NO_STOCK), 'line' => 0, 'description' => 'Service', 'serialnumber' => '', 'quantity' => '1', 'discount' => '0', 'discount_type' => PERCENT, 'cost_price' => '0', 'price' => $price, 'item_location' => 1, 'print_option' => 0]];
        $taxes    = [[], []];
        $payments = $paid === null ? [] : [['payment_type' => lang('Sales.cash'), 'payment_amount' => $paid, 'cash_refund' => '0', 'cash_adjustment' => 0, 'reference_code' => null]];
        $saleId   = model(Sale::class)->save_value(NEW_ENTRY, $status, $items, $customerId, (int) session()->get('person_id'), 'test', null, null, null, SALE_TYPE_INVOICE, $payments, null, $taxes, true);

        $this->assertGreaterThan(0, $saleId);

        return $saleId;
    }

    /**
     * @param list<int> $saleIds
     */
    private function removeSales(array $saleIds): void
    {
        $db = $this->accountingDb();
        $db->query('SET FOREIGN_KEY_CHECKS = 0');

        foreach ($saleIds as $saleId) {
            foreach (['sales_payments', 'sales_items_taxes', 'sales_items', 'sales_taxes', 'sales'] as $table) {
                $db->table($table)->where('sale_id', $saleId)->delete();
            }
        }

        $db->query('SET FOREIGN_KEY_CHECKS = 1');
    }

    /**
     * @return array<string, mixed>
     */
    private function documentFigures(int $customerId): array
    {
        $db       = $this->accountingDb();
        $position = (new CustomerLedger())->position($customerId);
        $tables   = ['accounting_journals', 'accounting_journal_lines', 'accounting_accounts', 'customer_openings', 'accounting_stock_entries', 'sales', 'sales_items', 'sales_payments', 'sales_items_taxes'];
        $checksum = [];

        foreach ($tables as $table) {
            $checksum[$table] = $db->query('CHECKSUM TABLE `' . $db->prefixTable($table) . '`')->getRow()->Checksum . '/' . $db->table($table)->countAllResults();
        }

        return [
            'open'     => $position->open(),
            'agrees'   => $position->agrees(),
            'tables'   => $checksum,
            'opening'  => $db->table('customer_openings')->where('customer_id', $customerId)->get()->getResultArray(),
            'sales'    => $db->table('sales')->where('customer_id', $customerId)->orderBy('sale_id')->get()->getResultArray(),
            'journals' => $db->table('accounting_journals')->countAllResults(),
            'paid'     => $db->table('sales_payments')->countAllResults(),
        ];
    }

    public function testGetView_OffersTheMasterDataFieldsWithTheFiveStatusesAndTheTwoTypes(): void
    {
        $this->classification('Retail');

        $page = $this->get('/customers/view/-1');

        foreach (['name="master_data"', 'name="customer_type"', 'name="classification_id"', 'name="manual_status"', 'name="assigned_employee_id"', 'name="area"', 'name="country_code"', 'name="is_government_entity"', 'name="billing_method"'] as $field) {
            $this->assertStringContainsString($field, $page->getBody());
        }

        foreach (CustomerStatus::cases() as $status) {
            $this->assertStringContainsString('value="' . $status->value . '"', $page->getBody());
        }

        $page->assertSee('Retail');
        $this->assertStringContainsString('value="individual"', $page->getBody());
        $this->assertStringContainsString('value="commercial"', $page->getBody());
        $this->cleanMaster();
    }

    public function testGetView_OffersAnInactiveClassificationTheCustomerHoldsButNoOtherInactiveOne(): void
    {
        $held   = $this->classification('Held and retired');
        $loose  = $this->classification('Loose and retired');
        $result = $this->saveMaster(['classification_id' => (string) $held]);
        $lookup = new NamedLookup(LookupKind::CustomerClassification);
        $lookup->setStatus($held, false, 1);
        $lookup->setStatus($loose, false, 1);

        $page = $this->get('/customers/view/' . $result['id']);

        $page->assertSee('Held and retired');
        $page->assertDontSee('Loose and retired');
        $this->cleanMaster();
    }

    public function testGetView_ShowsTheCreationDateOfTheCustomerAsAFieldThatCannotBeTyped(): void
    {
        $result = $this->save();
        $this->accountingDb()->table('customers')->where('person_id', $result['id'])->update(['date' => '2020-05-17 09:30:00']);

        $page = $this->get('/customers/view/' . $result['id']);

        $this->assertStringContainsString('id="datetime"', $page->getBody());
        $this->assertStringContainsString('readonly', $page->getBody());
        $this->assertStringContainsString(esc(to_datetime(strtotime('2020-05-17 09:30:00'))), $page->getBody());
    }

    public function testGetView_OffersOnlyTheEmployeesOfTheViewersBranches(): void
    {
        $this->resetBranches();
        $branch   = $this->createBranch('B2');
        $other    = $this->createBranch('B3');
        $viewer   = $this->employeeWithGrants(['customers'], [$branch]);
        $stranger = $this->employeeWithGrants(['customers'], [$other]);
        $this->accountingDb()->table('people')->where('person_id', $stranger)->update(['first_name' => 'Strangerzed']);
        $this->withSession(['person_id' => $viewer, 'menu_group' => 'office']);

        $page = $this->get('/customers/view/-1');

        $page->assertDontSee('Strangerzed');

        $this->resetBranches();
    }

    public function testGetView_ShowsAnEmployeeOfAnotherBranchTheCustomerIsAssignedToWithoutHisNameAndKeepsHim(): void
    {
        $this->resetBranches();
        $branch   = $this->createBranch('B2');
        $other    = $this->createBranch('B3');
        $viewer   = $this->employeeWithGrants(['customers'], [$branch]);
        $stranger = $this->employeeWithGrants(['customers'], [$other]);
        $this->accountingDb()->table('people')->where('person_id', $stranger)->update(['first_name' => 'Strangerzed']);

        $created = $this->saveMaster(['assigned_employee_id' => (string) $stranger]);
        $this->assertTrue($created['success']);

        $this->withSession(['person_id' => $viewer, 'menu_group' => 'office']);

        $page = $this->get('/customers/view/' . $created['id']);

        $page->assertDontSee('Strangerzed');
        $page->assertSee(lang('Customers.assigned_other_branch'));
        $this->assertMatchesRegularExpression('/<option value="' . $stranger . '" selected(?:="selected")?>' . preg_quote(lang('Customers.assigned_other_branch'), '/') . '</', $page->getBody());
        $this->assertTrue($this->saveMaster(['assigned_employee_id' => (string) $stranger, 'area' => 'Olaya'], (int) $created['id'])['success'], 'saving the form again keeps the employee');
        $this->assertSame($stranger, (int) $this->customerRow((int) $created['id'])['assigned_employee_id']);

        $this->resetBranches();
        $this->cleanMaster();
    }

    public function testGetView_EscapesTheNamesOfTheClassificationAndTheEmployeeInTheirLists(): void
    {
        $hostile  = '</select><img src=x onerror=alert(1)>';
        $employee = $this->employeeWithGrants(['customers']);
        $this->accountingDb()->table('people')->where('person_id', $employee)->update(['first_name' => $hostile]);
        $this->classification($hostile);

        $form = $this->get('/customers/view/-1')->getBody();
        $list = $this->get('/customers')->getBody();

        foreach ([$form, $list] as $body) {
            $this->assertStringNotContainsString('<img src=x', $body);
            $this->assertStringContainsString(esc($hostile), $body);
        }

        $this->cleanMaster();
    }

    public function testGetIndex_OffersTheFiltersOfTheDirectory(): void
    {
        $this->classification('Retail');

        $page = $this->get('/customers');

        foreach (['filter_classification_id', 'filter_manual_status', 'filter_assigned_employee_id', 'filter_customer_type', 'filter_area', 'filter_city', 'filter_country_code', 'filter_created_from', 'filter_created_to'] as $id) {
            $this->assertStringContainsString('id="' . $id . '"', $page->getBody());
        }

        $page->assertSee('Retail');
        $this->cleanMaster();
    }

    public function testGetSearch_NarrowsTheDirectoryByEachFilterAndByTheirCombination(): void
    {
        $gold     = $this->classification('Gold');
        $employee = $this->employeeWithGrants(['customers']);

        $a = $this->saveMaster(['classification_id' => (string) $gold, 'manual_status' => 'interested', 'assigned_employee_id' => (string) $employee, 'area' => 'Olaya', 'country_code' => 'SA', 'customer_type' => 'commercial'], NEW_ENTRY, ['city' => 'Riyadh']);
        $b = $this->saveMaster(['manual_status' => 'served', 'area' => 'Malaz', 'country_code' => 'EG', 'customer_type' => 'individual'], NEW_ENTRY, ['city' => 'Jeddah']);
        $c = $this->saveMaster([], NEW_ENTRY, ['city' => 'Riyadh']);
        $mine = [(int) $a['id'], (int) $b['id'], (int) $c['id']];
        $only = static fn (array $ids): array => array_values(array_intersect($ids, $mine));

        $this->assertSame([$a['id']], $only($this->directoryIds(['classification_id' => (string) $gold])));
        $this->assertSame([$a['id']], $only($this->directoryIds(['manual_status' => 'interested'])));
        $this->assertSame([$a['id']], $only($this->directoryIds(['assigned_employee_id' => (string) $employee])));
        $this->assertSame([$a['id']], $only($this->directoryIds(['area' => 'Olaya'])));
        $this->assertSame([$b['id']], $only($this->directoryIds(['country_code' => 'eg'])));
        $this->assertSame([$b['id']], $only($this->directoryIds(['customer_type' => 'individual'])));
        $this->assertSame([$a['id'], $c['id']], $only($this->directoryIds(['city' => 'Riyadh'])));
        $this->assertSame([$a['id']], $only($this->directoryIds(['city' => 'Riyadh', 'customer_type' => 'commercial'])));
        $this->assertSame($mine, $only($this->directoryIds([])));
        $this->cleanMaster();
    }

    public function testGetSearch_NarrowsTheDirectoryByTheDayTheCustomerWasCreatedInclusively(): void
    {
        $old   = $this->save();
        $newer = $this->save();
        $db    = $this->accountingDb();
        $db->table('customers')->where('person_id', $old['id'])->update(['date' => '2020-03-10 08:00:00']);
        $db->table('customers')->where('person_id', $newer['id'])->update(['date' => '2020-03-20 23:59:59']);
        $mine = [(int) $old['id'], (int) $newer['id']];
        $only = static fn (array $ids): array => array_values(array_intersect($ids, $mine));

        $this->assertSame([(int) $newer['id']], $only($this->directoryIds(['created_from' => '2020-03-20', 'created_to' => '2020-03-20'])));
        $this->assertSame([(int) $old['id']], $only($this->directoryIds(['created_from' => '2020-03-10', 'created_to' => '2020-03-19'])));
        $this->assertSame($mine, $only($this->directoryIds(['created_from' => '2020-03-10', 'created_to' => '2020-03-20'])));
        $this->assertSame($mine, $only($this->directoryIds(['created_from' => 'not a day', 'created_to' => '2020-13-45'])), 'A day that is not a day is left out');
    }

    public function testGetSearch_LeavesOutAFilterValueTheListsDoNotOffer(): void
    {
        $a    = $this->saveMaster(['manual_status' => 'served']);
        $mine = [(int) $a['id']];

        $ids = $this->directoryIds(['manual_status' => 'vip', 'customer_type' => 'both', 'country_code' => 'SAU', 'classification_id' => 'x']);

        $this->assertContains((int) $a['id'], $ids);
        $this->assertSame($mine, array_values(array_intersect($ids, $mine)));
        $this->cleanMaster();
    }

    public function testGetSearch_ShowsTheColumnsOfTheDirectoryAsLabelsAndNames(): void
    {
        $gold     = $this->classification('Gold');
        $employee = $this->employeeWithGrants(['customers']);
        $this->accountingDb()->table('people')->where('person_id', $employee)->update(['first_name' => 'Rania', 'last_name' => 'Assigned']);
        $a = $this->saveMaster(['classification_id' => (string) $gold, 'manual_status' => 'under_review', 'assigned_employee_id' => (string) $employee, 'area' => 'Olaya', 'country_code' => 'SA', 'customer_type' => 'commercial']);

        $row = $this->directory(['classification_id' => (string) $gold])['rows'][0];

        $this->assertSame(['Gold', CustomerStatus::UnderReview->label(), 'Rania Assigned', 'Olaya', 'SA', CustomerType::Commercial->label()], [$row['classification_name'], $row['manual_status'], $row['assigned_employee_name'], $row['area'], $row['country_code'], $row['customer_type']]);
        $this->assertNotSame('', $row['created']);
        $this->assertSame((int) $a['id'], (int) $row['people.person_id']);
        $this->cleanMaster();
    }

    public function testGetSearch_SortsByTheNewColumns(): void
    {
        $token = 'Sorted' . uniqid();
        $a     = $this->saveMaster(['area' => 'Zeta', 'company_name' => $token . ' A'], NEW_ENTRY, ['city' => 'Aswan']);
        $b     = $this->saveMaster(['area' => 'Alpha', 'company_name' => $token . ' B'], NEW_ENTRY, ['city' => 'Tanta']);
        $db    = $this->accountingDb();

        $db->table('customers')->where('person_id', $a['id'])->update(['date' => '2021-06-01 10:00:00']);
        $db->table('customers')->where('person_id', $b['id'])->update(['date' => '2019-02-03 10:00:00']);

        $ids = fn (string $sort, string $order): array => $this->directoryIds(['search' => $token, 'sort' => $sort, 'order' => $order]);
        $ab  = [(int) $a['id'], (int) $b['id']];
        $ba  = [(int) $b['id'], (int) $a['id']];

        $this->assertSame($ba, $ids('area', 'asc'));
        $this->assertSame($ab, $ids('area', 'desc'));
        $this->assertSame($ab, $ids('city', 'asc'));
        $this->assertSame($ba, $ids('city', 'desc'));
        $this->assertSame($ba, $ids('created', 'asc'));
        $this->assertSame($ab, $ids('created', 'desc'));
        $this->cleanMaster();
    }

    private function completedSale(int $customerId, int $branchId, string $amount): void
    {
        $db     = $this->accountingDb();
        $itemId = $this->masterItems[] = $this->createTestItem();

        $db->table('sales')->insert(['customer_id' => $customerId, 'employee_id' => 1, 'comment' => '', 'sale_status' => COMPLETED, 'branch_id' => $branchId]);
        $saleId = (int) $db->insertID();
        $db->table('sales_items')->insert(['sale_id' => $saleId, 'item_id' => $itemId, 'line' => 1, 'quantity_purchased' => 1, 'item_cost_price' => 1, 'item_unit_price' => $amount, 'item_location' => 1]);
        $db->table('sales_payments')->insert(['sale_id' => $saleId, 'payment_type' => 'Cash', 'payment_amount' => $amount, 'employee_id' => 1]);
    }

    private function cleanSales(): void
    {
        $db = $this->accountingDb();

        $db->query('DELETE FROM ' . $db->prefixTable('sales_payments'));
        $db->query('DELETE FROM ' . $db->prefixTable('sales_items'));
        $db->query('DELETE FROM ' . $db->prefixTable('sales'));
    }

    public function testGetSearch_ShowsTheTotalSpentOfEveryBranchToAViewerOfEveryBranchAndOfHisOwnBranchesToAViewerLimitedToThem(): void
    {
        $this->resetBranches();
        $branch = $this->createBranch('B2');
        $other  = $this->createBranch('B3');
        $c      = $this->save();
        $this->completedSale((int) $c['id'], $branch, '30.00');
        $this->completedSale((int) $c['id'], $other, '12.00');

        $total = static fn (array $rows, int $id): string => (string) array_column($rows, 'total', 'people.person_id')[$id];

        $this->assertSame(to_currency(42), $total($this->directory()['rows'], (int) $c['id']));

        $viewer = $this->employeeWithGrants(['customers'], [$branch]);
        $this->withSession(['person_id' => $viewer, 'menu_group' => 'office']);

        $this->assertSame(to_currency(30), $total($this->directory()['rows'], (int) $c['id']));
        $this->assertSame(to_currency(30), (string) json_decode($this->get('/customers/row/' . $c['id'])->getJSON(), true)['total']);

        $form = $this->get('/customers/view/' . $c['id'])->getBody();

        $this->assertStringContainsString('value="' . to_currency_no_money(30) . '"', $form);
        $this->assertStringNotContainsString('value="' . to_currency_no_money(42) . '"', $form);

        $this->cleanSales();
        $this->resetBranches();
        $this->cleanMaster();
    }

    public function testGetSearch_ReturnsNothingWhenAViewerLimitedToABranchFiltersByAnEmployeeOfAnotherBranch(): void
    {
        $this->resetBranches();
        $branch   = $this->createBranch('B2');
        $other    = $this->createBranch('B3');
        $stranger = $this->employeeWithGrants(['customers'], [$other]);
        $a        = $this->saveMaster(['assigned_employee_id' => (string) $stranger]);

        $this->assertSame([(int) $a['id']], array_values(array_intersect($this->directoryIds(['assigned_employee_id' => (string) $stranger]), [(int) $a['id']])));

        $viewer = $this->employeeWithGrants(['customers'], [$branch]);
        $this->withSession(['person_id' => $viewer, 'menu_group' => 'office']);

        $this->assertSame([], $this->directoryIds(['assigned_employee_id' => (string) $stranger]));
        $this->assertSame('', (string) array_column($this->directory()['rows'], 'assigned_employee_name', 'people.person_id')[(int) $a['id']], 'The name of an employee outside the branches stays hidden');

        $this->resetBranches();
        $this->cleanMaster();
    }

    public function testGetRow_ReturnsTheRowOfOneCustomerWithTheDirectoryColumns(): void
    {
        $a = $this->saveMaster(['area' => 'Olaya', 'customer_type' => 'individual']);

        $row = json_decode($this->get('/customers/row/' . $a['id'])->getJSON(), true);

        $this->assertSame([(int) $a['id'], 'Olaya', CustomerType::Individual->label()], [(int) $row['people.person_id'], $row['area'], $row['customer_type']]);
        $this->assertSame([], json_decode($this->get('/customers/row/987654')->getJSON(), true));
        $this->cleanMaster();
    }

    // ========== CSV import and the template: the new columns are appended and optional ==========

    private const IMPORT_BASE_HEADERS = ['First Name', 'Last Name', 'Gender', 'Consent', 'Email', 'Phone', 'Address 1', 'Address 2', 'City', 'State', 'Zip', 'Country', 'Comments', 'Company', 'Account Number', 'Discount', 'Discount Type', 'Taxable'];

    /**
     * Imports the rows as given, each after the name and the e-mail address that make it a customer of its own.
     *
     * @param list<list<string>> $extraCells The cells after the eighteenth of each row.
     *
     * @return array{result: array<string, mixed>, emails: list<string>}
     */
    private function importWithExtraCells(array $extraCells, bool $withExtraHeaders = true): array
    {
        $file   = tempnam(sys_get_temp_dir(), 'customers_');
        $header = $withExtraHeaders ? [...self::IMPORT_BASE_HEADERS, 'Classification', 'Status', 'Assigned Employee', 'Area', 'Country Code', 'Type', 'Government Entity', 'Billing Method'] : self::IMPORT_BASE_HEADERS;
        $rows   = [$header];
        $emails = [];

        foreach ($extraCells as $cells) {
            $emails[] = 'csv.' . uniqid() . '@test.com';
            $rows[]   = ['Imported', 'K' . uniqid(), '1', '1', end($emails), '', '', '', '', '', '', '', '', '', '', '', '', '', ...$cells];
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

        foreach ($emails as $email) {
            foreach ($this->accountingDb()->table('people')->select('person_id')->where('email', $email)->get()->getResult() as $person) {
                $this->customerIds[] = (int) $person->person_id;
            }
        }

        return ['result' => $result, 'emails' => $emails];
    }

    private function customerByEmail(string $email): ?array
    {
        return $this->accountingDb()->table('customers')->join('people', 'people.person_id = customers.person_id')->where('people.email', $email)->get()->getRowArray();
    }

    public function testPostImportCsvFile_ImportsTheAppendedColumnsOfARow(): void
    {
        $gold     = $this->classification('Gold');
        $employee = $this->employeeWithGrants(['customers']);
        $username = $this->accountingDb()->table('employees')->where('person_id', $employee)->get()->getRow()->username;

        $imported = $this->importWithExtraCells([['gold', 'served', $username, 'Olaya', 'sa', 'commercial', 'y', 'print']]);
        $row      = $this->customerByEmail($imported['emails'][0]);

        $this->assertTrue($imported['result']['success']);
        $this->assertSame([$gold, 'served', $employee, 'Olaya', 'SA', 'commercial', 1, 'print'], [(int) $row['classification_id'], $row['manual_status'], (int) $row['assigned_employee_id'], $row['area'], $row['country_code'], $row['customer_type'], (int) $row['is_government_entity'], $row['billing_method']]);
        $this->assertSame(1, $this->accountingDb()->table('accounting_audit_log')->where(['entity_type' => 'customer', 'entity_id' => $row['person_id'], 'action' => 'customer_master_data_changed'])->countAllResults());
        $this->cleanMaster();
    }

    public function testPostImportCsvFile_ImportsAFileWithoutTheAppendedColumnsAsItAlwaysDid(): void
    {
        $imported = $this->importWithExtraCells([[]], false);
        $row      = $this->customerByEmail($imported['emails'][0]);

        $this->assertTrue($imported['result']['success']);
        $this->assertSame([null, null, null, null, null, null, '0', 'print'], [$row['classification_id'], $row['manual_status'], $row['assigned_employee_id'], $row['area'], $row['country_code'], $row['customer_type'], (string) $row['is_government_entity'], $row['billing_method']]);
        $this->assertSame(0, $this->accountingDb()->table('accounting_audit_log')->where(['entity_type' => 'customer', 'entity_id' => $row['person_id']])->countAllResults());
    }

    public function testPostImportCsvFile_ImportsARowWhoseAppendedColumnsAreAllEmpty(): void
    {
        $imported = $this->importWithExtraCells([['', '', '', '', '', '', '', '']]);

        $this->assertTrue($imported['result']['success']);
        $this->assertSame('print', $this->customerByEmail($imported['emails'][0])['billing_method']);
    }

    /**
     * @return array<string, array{0: list<string>}>
     */
    public static function refusedImportCellsProvider(): array
    {
        return [
            'a classification that does not exist' => [['nobody', '', '', '', '', '', '', '']],
            'a status outside the five'            => [['', 'vip', '', '', '', '', '', '']],
            'an employee that does not exist'      => [['', '', 'no-such-user', '', '', '', '', '']],
            'a country code of three letters'      => [['', '', '', '', 'SAU', '', '', '']],
            'a type outside the two'               => [['', '', '', '', '', 'both', '', '']],
            'a billing method outside the two'     => [['', '', '', '', '', '', '', 'fax']],
            'a government flag that is no word'    => [['', '', '', '', '', '', 'maybe', '']],
        ];
    }

    /**
     * @param list<string> $cells
     */
    #[DataProvider('refusedImportCellsProvider')]
    public function testPostImportCsvFile_RefusesARowWhoseAppendedValueIsNotAllowedAndStillImportsTheGoodRow(array $cells): void
    {
        $this->classification('Gold');

        $imported = $this->importWithExtraCells([$cells, ['', '', '', '', '', '', '', '']]);

        $this->assertFalse($imported['result']['success']);
        $this->assertNull($this->customerByEmail($imported['emails'][0]));
        $this->assertNotNull($this->customerByEmail($imported['emails'][1]));
        $this->cleanMaster();
    }

    public function testPostImportCsvFile_ReadsTheGovernmentColumnAsYesOrNoAndNotAsAnyText(): void
    {
        $words    = ['y', 'YES', '1', 'true', '0', 'n', 'No', 'false'];
        $imported = $this->importWithExtraCells(array_map(static fn (string $word): array => ['', '', '', '', '', '', $word, ''], $words));

        $this->assertTrue($imported['result']['success']);

        foreach ($words as $position => $word) {
            $this->assertSame(in_array($word, ['y', 'YES', '1', 'true'], true) ? 1 : 0, (int) $this->customerByEmail($imported['emails'][$position])['is_government_entity'], "the cell says {$word}");
        }

        $this->cleanMaster();
    }

    public function testGetCsv_OffersATemplateThatKeepsTheOldColumnsFirstAndAppendsTheNewOnesAtTheEnd(): void
    {
        $template = (string) file_get_contents(WRITEPATH . 'uploads/importCustomers.csv');
        $header   = str_getcsv(strtok($template, "\n") ?: '');

        $this->assertSame(self::IMPORT_BASE_HEADERS[0], $header[0]);
        $this->assertSame(['Classification', 'Status', 'Assigned Employee', 'Area', 'Country Code', 'Type', 'Government Entity', 'Billing Method'], array_slice($header, 18));
        $this->assertSame(['First Name', 'Last Name', 'Gender', 'Consent', 'Email', 'Phone Number', 'Address 1', 'Address2', 'City', 'State', 'Zip', 'Country', 'Comments', 'Company', 'Account Number', 'Discount', 'Discount_Type', 'Taxable'], array_slice($header, 0, 18));
    }

    public function testPostSave_AcceptsTheLongestValuesOfTheObservedLimitsAndRejectsOneCharacterMore(): void
    {
        foreach (['company_name' => 100, 'city' => 100, 'state' => 100, 'zip' => 20] as $field => $max) {
            $this->assertTrue($this->save([$field => str_repeat('a', $max)])['success'], "{$max} characters of {$field}");

            $refused = $this->save([$field => str_repeat('a', $max + 1)]);

            $this->assertFalse($refused['success'], "{$field} over {$max}");
            $this->assertStringContainsString((string) $max, $refused['message']);
        }
    }

    public function testPostSave_KeepsAValueThatWasLongerBeforeTheLimitWhileItIsNotChanged(): void
    {
        $created = $this->save(['city' => 'short']);
        $long    = str_repeat('c', 150);
        $this->accountingDb()->table('people')->where('person_id', $created['id'])->update(['city' => $long]);

        $this->assertTrue($this->save(['city' => $long, 'comments' => 'edited'], (int) $created['id'])['success']);
        $this->assertFalse($this->save(['city' => $long . 'x'], (int) $created['id'])['success']);
        $this->assertSame($long, $this->accountingDb()->table('people')->where('person_id', $created['id'])->get()->getRow()->city);
    }
}
