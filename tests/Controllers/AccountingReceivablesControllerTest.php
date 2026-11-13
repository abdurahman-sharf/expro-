<?php

namespace Tests\Controllers;

use App\Libraries\Accounting\AccountingGuard;
use App\Libraries\Accounting\AgingBuckets;
use App\Libraries\Accounting\CreditRefunds;
use App\Libraries\Accounting\Currencies;
use App\Libraries\Accounting\CustomerAdvances;
use App\Libraries\Accounting\CustomerLedger;
use App\Libraries\Accounting\CustomerOpening;
use App\Libraries\Accounting\JournalDraft;
use App\Libraries\Accounting\JournalLineDraft;
use App\Libraries\Accounting\PostingService;
use App\Libraries\Accounting\TreasuryService;
use App\Libraries\MasterData\PartyContacts;
use App\Libraries\Numbering\DocumentNumbering;
use App\Libraries\Numbering\DocumentType;
use App\Libraries\Organization\BranchGuard;
use App\Libraries\Pricing\SalesControlSettings;
use CodeIgniter\Database\Query;
use CodeIgniter\Events\Events;
use CodeIgniter\Exceptions\PageNotFoundException;
use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\DatabaseTestTrait;
use CodeIgniter\Test\FeatureTestTrait;
use CodeIgniter\Test\TestResponse;
use Config\Database;
use Tests\Support\AccountingFixtureTrait;
use Tests\Support\ChequeFixtureTrait;
use Tests\Support\EmployeeFixtureTrait;
use Tests\Support\ItemFixtureTrait;
use Tests\Support\SaleAccountingFixtureTrait;
use Tests\Support\CsvResponseTrait;

/**
 * The screen of customer balances and aging: who may open it, what it lists, and that what it
 * shows is what the books hold.
 */
class AccountingReceivablesControllerTest extends CIUnitTestCase
{
    use DatabaseTestTrait;
    use FeatureTestTrait;
    use AccountingFixtureTrait;
    use ChequeFixtureTrait;
    use EmployeeFixtureTrait;
    use ItemFixtureTrait;
    use SaleAccountingFixtureTrait;
    use CsvResponseTrait;

    protected $migrate     = true;
    protected $migrateOnce = true;
    protected $refresh     = false;
    protected $namespace   = null;

    public static function setUpBeforeClass(): void
    {
        $seeder = Database::seeder('tests');
        $seeder->call('TestDatabaseBootstrapSeeder');
    }

    protected function setUp(): void
    {
        parent::setUp();

        $this->startSelling();
    }

    protected function tearDown(): void
    {
        $this->accountingDb()->table('document_numbers')->truncate();
        $this->accountingDb()->table('document_sequences')->truncate();
        $this->accountingDb()->table('giftcards')->where('giftcard_number', 7774321)->delete();
        $this->accountingDb()->query('DELETE FROM ' . $this->accountingDb()->prefixTable('party_contacts'));
        $this->accountingDb()->table('accounting_audit_log')->whereIn('action', ['party_contacts_changed', 'party_contacts_hidden'])->delete();
        $this->setConfigValue('accounting_customer_opening', '0');

        $this->stopSelling();

        parent::tearDown();
    }

    /**
     * @param list<int>|null $branchIds
     */
    private function viewer(?array $branchIds = null): int
    {
        $personId = $this->employeeWithGrants(['accounting_receivables'], $branchIds);

        $this->withSession(['person_id' => $personId, 'menu_group' => 'home']);

        return $personId;
    }

    /**
     * @return array<string, mixed>
     */
    private function rowsOf(string $query = ''): array
    {
        return json_decode($this->get('/accounting_receivables/search' . $query)->getJSON(), true);
    }

    /**
     * @return array<string, mixed>
     */
    private function paymentsOf(string $query = ''): array
    {
        return json_decode($this->get('/accounting_receivables/payments/search' . $query)->getJSON(), true);
    }

    /**
     * @return array<string, mixed>
     */
    private function creditNotesOf(string $query = ''): array
    {
        return json_decode($this->get('/accounting_receivables/credit_notes/search' . $query)->getJSON(), true);
    }

    private function switchOn(DocumentType $type, string $prefix, int $padding = 3): void
    {
        (new DocumentNumbering())->configure($type, ['enabled' => true, 'prefix' => $prefix, 'padding' => $padding, 'next_number' => 1]);
    }

    public function testGetIndex_ShowsTheTableToWhoeverHoldsTheModule(): void
    {
        $this->viewer();

        $response = $this->get('/accounting_receivables');

        $response->assertStatus(200);
        $response->assertSee('accounting_receivables');
        $response->assertSee(lang('Accounting_receivables.note'));
    }

    public function testGetIndex_RedirectsAUserWithoutTheModuleGrant(): void
    {
        $this->withSession(['person_id' => $this->employeeWithGrants(['accounting_chart']), 'menu_group' => 'home']);

        $this->get('/accounting_receivables')->assertRedirect();
    }

    public function testGetIndex_IsNotFoundWhileAccountingIsOff(): void
    {
        $this->viewer();
        $this->setConfigValue('accounting_enabled', '0');

        $this->expectException(PageNotFoundException::class);

        $this->get('/accounting_receivables');
    }

    public function testGetIndex_IsNotFoundForSomeoneLimitedToABranch(): void
    {
        $this->viewer([1]);

        $this->expectException(PageNotFoundException::class);

        $this->get('/accounting_receivables');
    }

    public function testGetSearch_ListsTheCustomerWithWhatTheyOweAndHowOldItIs(): void
    {
        $old = $this->invoiceOf('70.00');
        $this->invoiceOf('30.00');
        $this->accountingDb()->table('sales')->where('sale_id', $old)->update(['sale_time' => date('Y-m-d 10:00:00', strtotime('-45 days'))]);
        $this->viewer();

        $result = $this->rowsOf();

        $this->assertSame(1, $result['total']);
        $this->assertCount(1, $result['rows']);

        $row = $result['rows'][0];

        $this->assertSame($this->customerId, $row['customer_id']);
        $this->assertSame('Omar Khaled', $row['customer_name']);
        $this->assertStringContainsString('100.00', $row['balance']);
        $this->assertStringContainsString('100.00', $row['open']);
        $this->assertStringContainsString('0.00', $row['credit']);
        $this->assertStringContainsString('30.00', $row['age_today']);
        $this->assertStringContainsString('70.00', $row['age_31_60']);
        $this->assertStringContainsString(lang('Accounting_receivables.agrees_yes'), $row['agrees']);
        $this->assertStringContainsString('accounting_receivables/customer/' . $this->customerId, $row['edit']);
    }

    public function testGetSearch_ShowsCreditAsANegativeBalance(): void
    {
        $invoice = $this->invoiceOf('100.00');
        $this->creditNoteOf($invoice, '250.00');
        $this->viewer();

        $row = $this->rowsOf()['rows'][0];

        $this->assertStringContainsString('-150.00', $row['balance']);
        $this->assertStringContainsString('150.00', $row['credit']);
        $this->assertStringContainsString('0.00', $row['open']);
    }

    public function testGetSearch_LeavesOutACustomerWhoseAccountIsEmpty(): void
    {
        $this->anotherCustomer();
        $this->invoiceOf('100.00', null, [$this->payment(lang('Sales.cash'), '100.00')]);
        $this->viewer();

        $this->assertSame(['total' => 0, 'rows' => []], $this->rowsOf());
    }

    public function testGetSearch_FiltersByNameAndSortsByBalance(): void
    {
        $other = $this->anotherCustomer('Hind', 'Saad');

        $this->invoiceOf('100.00');
        $this->invoiceOf('300.00', $other);
        $this->viewer();

        $this->assertSame(['Hind Saad', 'Omar Khaled'], array_column($this->rowsOf('?sort=balance&order=desc')['rows'], 'customer_name'));
        $this->assertSame(['Omar Khaled', 'Hind Saad'], array_column($this->rowsOf('?sort=balance&order=asc')['rows'], 'customer_name'));
        $this->assertSame(['Hind Saad', 'Omar Khaled'], array_column($this->rowsOf('?sort=customer_name&order=asc')['rows'], 'customer_name'));
        $this->assertSame(['Hind Saad'], array_column($this->rowsOf('?search=hind')['rows'], 'customer_name'));
    }

    public function testGetSearch_PagesTheResult(): void
    {
        $other = $this->anotherCustomer('Hind', 'Saad');

        $this->invoiceOf('100.00');
        $this->invoiceOf('300.00', $other);
        $this->viewer();

        $page = $this->rowsOf('?sort=balance&order=desc&limit=1&offset=1');

        $this->assertSame(2, $page['total']);
        $this->assertSame(['Omar Khaled'], array_column($page['rows'], 'customer_name'));
    }

    public function testGetSearch_WarnsOfWhatAManualJournalPutOnTheAccountOfTheCustomer(): void
    {
        $this->invoiceOf('100.00');

        (new PostingService())->post(new JournalDraft(
            date: date('Y-m-d'),
            lines: [new JournalLineDraft($this->customerAccount(), debit: '25.00'), new JournalLineDraft($this->accountId('sales_revenue'), credit: '25.00')],
            sourceType: JournalDraft::SOURCE_MANUAL,
            employeeId: $this->employeeId,
            currencyCode: 'SAR',
        ));
        $this->viewer();

        $row = $this->rowsOf()['rows'][0];

        $this->assertStringContainsString('125.00', $row['balance']);
        $this->assertStringContainsString('100.00', $row['open']);
        $this->assertStringContainsString('25.00', $row['agrees']);
        $this->assertStringNotContainsString(lang('Accounting_receivables.agrees_yes'), $row['agrees']);
    }

    public function testGetSearch_IsNotFoundForSomeoneLimitedToABranch(): void
    {
        $this->viewer([1]);

        $this->expectException(PageNotFoundException::class);

        $this->get('/accounting_receivables/search');
    }

    public function testGetCustomer_ListsTheInvoicesTheCreditAndWhatWasAppliedToWhat(): void
    {
        $first = $this->invoiceOf('100.00');
        $this->creditNoteOf($first, '250.00');
        $this->invoiceOf('40.00');
        $this->viewer();

        $response = $this->get('/accounting_receivables/customer/' . $this->customerId);

        $response->assertStatus(200);
        $response->assertSee('Omar Khaled');
        $response->assertSee('id="receivable_aging"');
        $response->assertSee('id="receivable_invoices"');
        $response->assertSee('id="receivable_credits"');
        $response->assertSee('id="receivable_history"');
        $response->assertSee('POS ' . $first);
        $response->assertSee(lang('Accounting_receivables.source_credit_note'));
        $response->assertSee(lang('Accounting_receivables.agrees_yes'));
        $response->assertSee(lang('Sales.invoice_paid'));
        $response->assertSee(lang('Sales.invoice_returned_full'));
    }

    public function testGetCustomer_ShowsTheContactsOfTheCustomerInTheHeaderWithTheValuesEscaped(): void
    {
        $this->invoiceOf('100.00');
        (new PartyContacts())->replace(PartyContacts::CUSTOMER, $this->customerId, [
            ['first_name' => '<b>Sara</b>', 'last_name' => 'Nasser', 'email' => 'sara@example.com', 'phone' => '011 123 4567', 'mobile' => '0500000000', 'position' => 'Buyer'],
            ['first_name' => 'Hind', 'position' => 'Accountant'],
        ], 1);
        $this->viewer();

        $body = $this->get('/accounting_receivables/customer/' . $this->customerId)->getBody();

        $this->assertStringContainsString('party-contacts', $body);
        $this->assertStringContainsString('&lt;b&gt;Sara&lt;/b&gt;', $body);
        $this->assertStringNotContainsString('<b>Sara</b>', $body);
        $this->assertStringContainsString('sara@example.com', $body);
        $this->assertStringContainsString('Accountant', $body);
    }

    public function testGetCustomer_ShowsNoContactSectionForACustomerWhoseContactsAreHiddenOrNone(): void
    {
        $this->invoiceOf('100.00');
        $contacts = new PartyContacts();
        $contacts->replace(PartyContacts::CUSTOMER, $this->customerId, [['first_name' => 'Gone']], 1);
        $contacts->replace(PartyContacts::CUSTOMER, $this->customerId, [], 1);
        $this->viewer();

        $body = $this->get('/accounting_receivables/customer/' . $this->customerId)->getBody();

        $this->assertStringNotContainsString('party-contacts', $body);
        $this->assertStringNotContainsString('Gone', $body);
    }

    public function testGetCustomer_ShowsAChequeAsCreditOfItsOwnKindWithALinkToTheChequeAndWhatItSettled(): void
    {
        $this->startCheques();

        (new DocumentNumbering())->configure(DocumentType::ReceivedCheque, ['enabled' => true, 'prefix' => 'RC-', 'padding' => 4], $this->employeeWithGrants(['config']));

        $invoice = $this->invoiceOf('60.00');
        $cheque  = $this->receiveCheque('100.00', date('Y-m-d'));

        $this->viewer();

        $body = $this->get('/accounting_receivables/customer/' . $this->customerId)->getBody();

        $this->assertStringContainsString(lang('Accounting_cheques.credit_source'), $body);
        $this->assertStringContainsString('accounting_cheques/view/' . $cheque, $body);
        $this->assertStringContainsString('>RC-0001</a>', $body, 'The credit is named by the number of the cheque');
        $this->assertStringContainsString('<bdi dir="ltr">RC-0001</bdi>', $body, 'And so is what it settled');
        $this->assertStringNotContainsString('CHQ ' . $cheque, $body);
        $this->assertStringNotContainsString('sales/receipt/' . $cheque . '"', $body);
        $this->assertStringContainsString('POS ' . $invoice, $body);
        $this->assertStringContainsString(lang('Accounting_receivables.agrees_yes'), $body);
    }

    public function testGetCustomer_NamesARefundInvoiceByItsDocumentAmongTheCreditAndTheHistory(): void
    {
        $invoice = $this->invoiceOf('100.00');
        $refund  = $this->refundInvoiceOf($invoice, '30.00');
        $note    = $this->creditNoteOf($invoice, '20.00');
        $this->viewer();

        $response = $this->get('/accounting_receivables/customer/' . $this->customerId);
        $body     = $response->getBody();

        $response->assertStatus(200);
        $this->assertGreaterThanOrEqual(2, substr_count($body, lang('Numbering.type_refund_invoice')));
        $this->assertGreaterThanOrEqual(2, substr_count($body, lang('Accounting_receivables.source_credit_note')));
        $this->assertMatchesRegularExpression('/' . preg_quote(lang('Numbering.type_refund_invoice'), '/') . '\s*<a [^>]*sales\/receipt\/' . $refund . '"/', $body);
        $this->assertMatchesRegularExpression('/' . preg_quote(lang('Accounting_receivables.source_credit_note'), '/') . '\s*<a [^>]*sales\/receipt\/' . $note . '"/', $body);
    }

    public function testGetCustomer_LinksTheListsOfThePaymentsAndTheCreditNotesOfTheCustomer(): void
    {
        $this->invoiceOf('100.00');
        $this->viewer();

        $response = $this->get('/accounting_receivables/customer/' . $this->customerId);

        $response->assertSee('id="customer_payments_link"');
        $response->assertSee('accounting_receivables/payments?customer_id=' . $this->customerId);
        $response->assertSee('accounting_receivables/credit_notes?customer_id=' . $this->customerId);
    }

    public function testGetPayments_StartsFilteredByTheCustomerNamedInTheLinkAndIgnoresNonsense(): void
    {
        $this->viewer();

        $this->assertMatchesRegularExpression('/id="f_customer_id" value="' . $this->customerId . '"/', $this->get('/accounting_receivables/payments?customer_id=' . $this->customerId)->getBody());
        $this->assertMatchesRegularExpression('/id="f_customer_id" value=""/', $this->get('/accounting_receivables/payments?customer_id=-4')->getBody());
        $this->assertMatchesRegularExpression('/id="f_customer_id" value=""/', $this->get('/accounting_receivables/payments?customer_id=abc')->getBody());
    }

    public function testGetPaymentsSearch_ShowsAForeignPaymentInItsCurrencyAndBooksItInTheBase(): void
    {
        $currencies = new Currencies();
        $currencies->setActive('USD', true);
        $currencies->rates()->add(['currency_code' => 'USD', 'rate' => '3.75', 'effective_date' => $this->daysAgo(30)]);
        $currencies->rates()->add(['currency_code' => 'USD', 'rate' => '3.60', 'effective_date' => $this->daysAgo(5)]);
        $this->grantInvoiceDate();

        $service = $this->item('0', false);

        $this->sales->useCurrency('USD');

        $saleId = $this->save([$this->line($service, 0, '1', '100')], [], [], SALE_TYPE_INVOICE, -1, true, null, COMPLETED, NEW_ENTRY, null, invoiceDate: $this->daysAgo(10));

        $this->addPayment($saleId, '100.00');
        $this->viewer();

        $rows = $this->paymentsOf()['rows'];

        $this->assertCount(1, $rows);
        $this->assertStringContainsString('100.00 USD', strip_tags($rows[0]['amount']), 'the amount is in the currency of the payment');
        $this->assertStringContainsString('360.00', strip_tags($rows[0]['posted']), 'the books hold it in the base');
        $this->assertStringContainsString(lang('Accounting_receivables.tie_booked'), strip_tags($rows[0]['posted']));
    }

    public function testGetPaymentsSearch_ShowsAPaymentOfASaleInTheBaseWithNoCurrencyCode(): void
    {
        $this->invoiceOf('100.00', null, [$this->payment(lang('Sales.cash'), '60.00')]);
        $this->viewer();

        $rows = $this->paymentsOf()['rows'];

        $this->assertStringNotContainsString('USD', strip_tags($rows[0]['amount']));
        $this->assertStringContainsString(lang('Accounting_receivables.tie_booked'), strip_tags($rows[0]['posted']));
    }

    public function testGetPaymentsSearch_NeverAsksForMoreThanTheLargestPage(): void
    {
        $this->invoiceOf('100.00', null, [$this->payment(lang('Sales.cash'), '10.00')]);
        $this->viewer();

        $asked    = [];
        $listener = static function (Query $query) use (&$asked): void {
            if (str_contains($query->getQuery(), ' OFFSET ')) {
                $asked[] = $query->getQuery();
            }
        };
        Events::on('DBQuery', $listener);

        try {
            $this->paymentsOf('?limit=100000');
            $this->creditNotesOf('?limit=100000');
        } finally {
            Events::removeListener('DBQuery', $listener);
        }

        $this->assertCount(2, $asked);

        foreach ($asked as $sql) {
            $this->assertMatchesRegularExpression('/LIMIT 500 OFFSET 0/', $sql);
        }
    }

    public function testGetCustomer_KeepsTheReleasedApplicationsInTheHistory(): void
    {
        $first  = $this->invoiceOf('70.00');
        $second = $this->invoiceOf('230.00');
        $paid   = $this->addPayment($second, '300.00');

        $this->removePayment($second, $paid);
        $this->viewer();

        $response = $this->get('/accounting_receivables/customer/' . $this->customerId);

        $response->assertSee('POS ' . $first);
        $this->assertSame(1, $this->accountingDb()->table('customer_credit_applications')->where('released_at IS NOT NULL')->countAllResults());
        $response->assertDontSee(lang('Accounting_receivables.no_history'));
    }

    public function testGetCustomer_IsNotFoundForACustomerWithoutAnAccount(): void
    {
        $without = $this->insertCustomer('Nora', 'Fahd');
        $this->viewer();

        $this->expectException(PageNotFoundException::class);

        $this->get('/accounting_receivables/customer/' . $without);
    }

    public function testGetCustomer_IsNotFoundForSomeoneLimitedToABranch(): void
    {
        $this->viewer([1]);

        $this->expectException(PageNotFoundException::class);

        $this->get('/accounting_receivables/customer/' . $this->customerId);
    }

    public function testGetSearch_ShowsWhatIsOverdueAndTheSixBucketsOfTheAging(): void
    {
        $this->invoiceDated('1.00', $this->daysAgo(0));
        $this->invoiceDated('2.00', $this->daysAgo(10), 30);
        $this->invoiceDated('4.00', $this->daysAgo(45), 10);
        $this->invoiceDated('8.00', $this->daysAgo(75));
        $this->invoiceDated('16.00', $this->daysAgo(100), 5);
        $this->invoiceDated('32.00', $this->daysAgo(130));
        $this->viewer();

        $row = $this->rowsOf()['rows'][0];

        $this->assertStringContainsString('1.00', $row['age_today']);
        $this->assertStringContainsString('2.00', $row['age_1_30']);
        $this->assertStringContainsString('4.00', $row['age_31_60']);
        $this->assertStringContainsString('8.00', $row['age_61_90']);
        $this->assertStringContainsString('16.00', $row['age_91_120']);
        $this->assertStringContainsString('32.00', $row['age_over_120']);
        $this->assertStringContainsString('60.00', $row['overdue']);
        $this->assertStringContainsString('63.00', $row['balance']);
    }

    public function testGetSearch_DoesNotCountAnInvoiceDueTodayAsOverdue(): void
    {
        $this->invoiceDated('40.00', $this->daysAgo(10), 10);
        $this->viewer();

        $row = $this->rowsOf()['rows'][0];

        $this->assertStringContainsString('0.00', $row['overdue']);
        $this->assertStringNotContainsString('40.00', $row['overdue']);
        $this->assertStringContainsString('40.00', $row['age_1_30']);
    }

    public function testGetIndex_OffersTheAgedLedgerAndTheBalanceReport(): void
    {
        $this->viewer();

        $response = $this->get('/accounting_receivables');

        $response->assertSee(base_url('accounting_receivables/aged_ledger'));
        $response->assertSee(base_url('accounting_receivables/balances'));
    }

    public function testGetCustomer_ShowsTheDueDateAndMarksTheInvoiceThatIsOverdue(): void
    {
        $late = $this->invoiceDated('40.00', $this->daysAgo(10), 4);
        $this->invoiceDated('25.00', $this->daysAgo(2), 30);
        $this->viewer();

        $response = $this->get('/accounting_receivables/customer/' . $this->customerId);

        $response->assertSee('POS ' . $late);
        $response->assertSee(lang('Accounting_receivables.due_date'));
        $response->assertSee(to_date(strtotime($this->daysAgo(6))));
        $response->assertSee(to_date(strtotime('+28 days')));
        $response->assertSee('id="statement_overdue"');
        $this->assertSame(1, substr_count($response->getBody(), lang('Accounting_receivables.overdue_days', [6])));
        $this->assertSame(1, substr_count($response->getBody(), 'text-bg-danger'));
    }

    public function testGetCustomer_ReadsThePositionOfADayOfThePast(): void
    {
        $first  = $this->invoiceDated('100.00', $this->daysAgo(3));
        $second = $this->invoiceDated('30.00', $this->daysAgo(1));
        $this->viewer();

        $response = $this->get('/accounting_receivables/customer/' . $this->customerId . '?to=' . $this->daysAgo(2));

        $response->assertStatus(200);
        $response->assertSee('POS ' . $first);
        $response->assertDontSee('POS ' . $second);
        $response->assertSee(to_currency_no_money('100.00'));
        $response->assertDontSee(to_currency_no_money('130.00'));
    }

    public function testGetCustomer_SaysWhyADayThatIsNotADayWasNotUsedAndReadsToday(): void
    {
        $this->invoiceDated('100.00', $this->daysAgo(3));
        $this->viewer();

        $response = $this->get('/accounting_receivables/customer/' . $this->customerId . '?to=2026-02-30');

        $response->assertStatus(200);
        $response->assertSee('id="report_error"');
        $response->assertSee(to_currency_no_money('100.00'));
    }

    public function testGetAgedLedger_AppliesTheCreditToTheOldestInvoiceAndTotalsEveryBucket(): void
    {
        $this->invoiceDated('100.00', $this->daysAgo(5));
        $this->invoiceDated('200.00', $this->daysAgo(3));
        $this->invoiceDated('50.00', $this->daysAgo(0));
        $paid = $this->invoiceDated('120.00', $this->daysAgo(1));
        $this->addPayment($paid, '120.00');
        $this->viewer();

        $response = $this->get('/accounting_receivables/aged_ledger?period=1');
        $body     = $response->getBody();

        $response->assertStatus(200);
        $response->assertSee('id="aged_ledger_table"');
        $response->assertSee('Omar Khaled');
        $response->assertSee(lang('Accounting_receivables.bucket_days', ['3 - 3']));
        $response->assertSee(lang('Accounting_receivables.bucket_days', ['4+']));
        $response->assertSee('accounting_receivables/customer/' . $this->customerId . '?to=' . date('Y-m-d'));

        $totals = substr($body, (int) strpos($body, 'id="aged_totals"'));

        foreach (['50.00', '120.00', '180.00', '350.00'] as $figure) {
            $this->assertStringContainsString(to_currency_no_money($figure), $totals);
        }

        $this->assertSame('350.0000', bcadd((new CustomerLedger())->position($this->customerId)->accountBalance, '0', 4));
    }

    public function testGetAgedLedger_EscapesTheNameOfTheCustomer(): void
    {
        $this->invoiceOf('100.00', $this->anotherCustomer('<b>Bold</b>', 'Name'));
        $this->viewer();

        $response = $this->get('/accounting_receivables/aged_ledger');

        $response->assertSee('&lt;b&gt;Bold&lt;/b&gt; Name');
        $response->assertDontSee('<b>Bold</b>');
    }

    public function testGetAgedLedger_ReadsTheDayAndTheLengthOfTheBucketsAsTyped(): void
    {
        $this->invoiceDated('100.00', $this->daysAgo(25));
        $this->viewer();

        $response = $this->get('/accounting_receivables/aged_ledger?period=10&to=' . $this->daysAgo(10));

        $response->assertSee(lang('Accounting_receivables.bucket_days', ['11 - 20']));
        $response->assertSee(lang('Accounting_receivables.bucket_days', ['40+']));
        $response->assertSee(lang('Accounting_receivables.aged_as_of', [to_date(strtotime($this->daysAgo(10)))]));
        $response->assertSee(to_currency_no_money('100.00'));
        $response->assertSee('value="10"');
    }

    public function testGetAgedLedger_LeavesOutWhatIsDatedAfterTheDay(): void
    {
        $this->invoiceDated('100.00', $this->daysAgo(2));
        $this->viewer();

        $response = $this->get('/accounting_receivables/aged_ledger?to=' . $this->daysAgo(5));

        $response->assertSee(lang('Accounting_receivables.aged_empty'));
        $response->assertDontSee('id="aged_totals"');
    }

    public function testGetAgedLedger_SaysWhyADayThatIsNotADayWasNotUsed(): void
    {
        $this->invoiceDated('100.00', $this->daysAgo(2));
        $this->viewer();

        $response = $this->get('/accounting_receivables/aged_ledger?to=tomorrow');

        $response->assertStatus(200);
        $response->assertSee('id="report_error"');
        $response->assertSee(to_currency_no_money('100.00'));
    }

    public function testGetAgedLedger_KeepsTheLengthOfTheBucketsWithinTheLimit(): void
    {
        $this->invoiceDated('100.00', $this->daysAgo(2));
        $this->viewer();

        $response = $this->get('/accounting_receivables/aged_ledger?period=99999');

        $response->assertStatus(200);
        $response->assertSee('max="' . AgingBuckets::MAX_PERIOD . '"');
        $response->assertSee('value="' . AgingBuckets::DEFAULT_PERIOD . '"');
    }

    public function testGetAgedLedger_RedirectsAUserWithoutTheModuleGrant(): void
    {
        $this->withSession(['person_id' => $this->employeeWithGrants(['accounting_chart']), 'menu_group' => 'home']);

        $this->get('/accounting_receivables/aged_ledger')->assertRedirect();
    }

    public function testGetAgedLedger_IsNotFoundWhileAccountingIsOff(): void
    {
        $this->viewer();
        $this->setConfigValue('accounting_enabled', '0');

        $this->expectException(PageNotFoundException::class);

        $this->get('/accounting_receivables/aged_ledger');
    }

    public function testGetAgedLedger_IsNotFoundForSomeoneLimitedToABranch(): void
    {
        $this->viewer([1]);

        $this->expectException(PageNotFoundException::class);

        $this->get('/accounting_receivables/aged_ledger');
    }

    public function testGetBalances_ShowsTheColumnsOfTheReferenceSystemAndTheyReconcile(): void
    {
        $first  = $this->invoiceOf('1000.00');
        $second = $this->invoiceOf('1541.50');

        $this->addPayment($first, '1000.00');
        $this->addPayment($second, '563.50');
        $this->creditNoteOf($second, '253.00');
        $this->viewer();

        $response = $this->get('/accounting_receivables/balances');

        $response->assertStatus(200);
        $response->assertSee('id="balances_table"');
        $response->assertSee('id="balances_totals"');

        foreach (['col_before', 'col_sales', 'col_returns', 'col_net', 'col_payments', 'col_settlements', 'balance'] as $column) {
            $response->assertSee(lang('Accounting_receivables.' . $column));
        }

        foreach (['2541.50', '1563.50', '-253.00', '725.00'] as $figure) {
            $response->assertSee(to_currency_no_money($figure));
        }

        $response->assertDontSee(lang('Accounting_receivables.col_other'));
    }

    public function testGetBalances_EscapesTheNameOfTheCustomer(): void
    {
        $this->invoiceOf('100.00', $this->anotherCustomer('<b>Bold</b>', 'Name'));
        $this->viewer();

        $response = $this->get('/accounting_receivables/balances');

        $response->assertSee('&lt;b&gt;Bold&lt;/b&gt; Name');
        $response->assertDontSee('<b>Bold</b>');
    }

    public function testGetBalances_ShowsTheColumnForWhatNoOtherColumnExplainsOnlyWhenThereIsSuchAJournal(): void
    {
        $this->invoiceOf('100.00');

        (new PostingService())->post(new JournalDraft(
            date: date('Y-m-d'),
            lines: [new JournalLineDraft($this->customerAccount(), debit: '25.00'), new JournalLineDraft($this->accountId('sales_revenue'), credit: '25.00')],
            sourceType: JournalDraft::SOURCE_MANUAL,
            employeeId: $this->employeeId,
            currencyCode: 'SAR',
        ));
        $this->viewer();

        $response = $this->get('/accounting_receivables/balances');

        $response->assertSee(lang('Accounting_receivables.col_other'));
        $response->assertSee(to_currency_no_money('125.00'));
    }

    public function testGetBalances_CarriesWhatPrecedesTheStartIntoTheBalanceBefore(): void
    {
        $this->invoiceDated('100.00', $this->daysAgo(5));
        $this->invoiceDated('30.00', $this->daysAgo(1));
        $this->viewer();

        $response = $this->get('/accounting_receivables/balances?from=' . $this->daysAgo(2) . '&to=' . date('Y-m-d'));

        $response->assertSee(lang('Accounting_receivables.balances_period', [to_date(strtotime($this->daysAgo(2))), to_date(time())]));
        $response->assertSee(to_currency_no_money('100.00'));
        $response->assertSee(to_currency_no_money('130.00'));
    }

    public function testGetBalances_RefusesAPeriodThatEndsBeforeItStartsAndReadsToday(): void
    {
        $this->invoiceOf('100.00');
        $this->viewer();

        $response = $this->get('/accounting_receivables/balances?from=' . date('Y-m-d', strtotime('+3 days')) . '&to=' . $this->daysAgo(3));

        $response->assertStatus(200);
        $response->assertSee('id="report_error"');
        $response->assertSee(to_currency_no_money('100.00'));
    }

    public function testGetBalances_HidesACustomerWithNoMovementAndNoBalanceUnlessAskedToShowIt(): void
    {
        $settled = $this->anotherCustomer('Nora', 'Fahd');
        $paid    = $this->invoiceOf('40.00', $settled);

        $this->addPayment($paid, '40.00');
        $this->invoiceOf('100.00');
        $this->viewer();

        $tomorrow = date('Y-m-d', strtotime('+1 day'));
        $query    = '?from=' . $tomorrow . '&to=' . $tomorrow;

        $hidden = $this->get('/accounting_receivables/balances' . $query);

        $hidden->assertSee('Omar Khaled');
        $hidden->assertDontSee('Nora Fahd');
        $this->get('/accounting_receivables/balances' . $query . '&empty=1')->assertSee('Nora Fahd');
    }

    public function testGetBalances_RedirectsAUserWithoutTheModuleGrant(): void
    {
        $this->withSession(['person_id' => $this->employeeWithGrants(['accounting_chart']), 'menu_group' => 'home']);

        $this->get('/accounting_receivables/balances')->assertRedirect();
    }

    public function testGetBalances_IsNotFoundWhileAccountingIsOff(): void
    {
        $this->viewer();
        $this->setConfigValue('accounting_enabled', '0');

        $this->expectException(PageNotFoundException::class);

        $this->get('/accounting_receivables/balances');
    }

    public function testGetBalances_IsNotFoundForSomeoneLimitedToABranch(): void
    {
        $this->viewer([1]);

        $this->expectException(PageNotFoundException::class);

        $this->get('/accounting_receivables/balances');
    }

    public function testGetRow_GivesTheRowOfOneCustomer(): void
    {
        $this->invoiceOf('100.00');
        $this->viewer();

        $row = json_decode($this->get('/accounting_receivables/row/' . $this->customerId)->getJSON(), true);

        $this->assertSame('Omar Khaled', $row['customer_name']);
        $this->assertStringContainsString('100.00', $row['balance']);
    }

    // ---------------------------------------------------------------------------------------
    // The tabs of the customer documents
    // ---------------------------------------------------------------------------------------

    public function testGetIndex_OffersTheListsOfPaymentsAndOfCreditNotesNextToTheBalances(): void
    {
        $this->viewer();

        $response = $this->get('/accounting_receivables');

        $response->assertSee(lang('Accounting_receivables.payments_tab'));
        $response->assertSee(lang('Accounting_receivables.credit_notes_tab'));
        $response->assertSee('accounting_receivables/payments');
        $response->assertSee('accounting_receivables/credit_notes');
    }

    public function testGetSearch_ShowsWhatEachCustomerWasReturnedAsReturnsAndAdjustments(): void
    {
        $invoice = $this->invoiceOf('300.00');
        $this->refundInvoiceOf($invoice, '25.00');
        $this->creditNoteOf($invoice, '40.00');
        $this->creditNoteOf($invoice, '10.00');
        $this->viewer();

        $row = $this->rowsOf()['rows'][0];

        $this->assertStringContainsString('25.00', $row['returns']);
        $this->assertStringContainsString('50.00', $row['adjustments']);
        $this->assertStringContainsString('225.00', $row['balance']);
        $this->assertStringContainsString(lang('Accounting_receivables.agrees_yes'), $row['agrees']);

        $single = json_decode($this->get('/accounting_receivables/row/' . $this->customerId)->getJSON(), true);

        $this->assertSame($row['returns'], $single['returns']);
        $this->assertSame($row['adjustments'], $single['adjustments']);
    }

    // ---------------------------------------------------------------------------------------
    // The list of the payments of the customers
    // ---------------------------------------------------------------------------------------

    public function testGetPayments_ShowsTheListWithItsFiltersToWhoeverHoldsTheModule(): void
    {
        $this->viewer();

        $response = $this->get('/accounting_receivables/payments');

        $response->assertStatus(200);
        $response->assertSee('id="payment_filters"');
        $response->assertSee('id="f_date_from"');
        $response->assertSee('id="f_customer"');
        $response->assertSee('id="f_way"');
        $response->assertSee('id="f_employee"');
        $response->assertSee('id="f_status"');
        $response->assertSee('id="payments_totals"');
        $response->assertSee(lang('Accounting_receivables.payments_note'));
        $response->assertDontSee('id="payments_partial"');
        $response->assertDontSee('id="f_branch"');
    }

    public function testGetPayments_RedirectsAUserWithoutTheModuleGrant(): void
    {
        $this->withSession(['person_id' => $this->employeeWithGrants(['accounting_chart']), 'menu_group' => 'home']);

        $this->get('/accounting_receivables/payments')->assertRedirect();
        $this->get('/accounting_receivables/payments/search')->assertRedirect();
    }

    public function testGetPayments_IsNotFoundWhileAccountingIsOff(): void
    {
        $this->viewer();
        $this->setConfigValue('accounting_enabled', '0');

        $this->expectException(PageNotFoundException::class);

        $this->get('/accounting_receivables/payments');
    }

    public function testGetPaymentsSearch_IsNotFoundWhileAccountingIsOff(): void
    {
        $this->viewer();
        $this->setConfigValue('accounting_enabled', '0');

        $this->expectException(PageNotFoundException::class);

        $this->get('/accounting_receivables/payments/search');
    }

    public function testGetPaymentsSearch_ListsAPaymentWithItsNumberItsCustomerItsSaleItsWayItsCollectorAndItsStatus(): void
    {
        $saleId = $this->invoiceOf('100.00', null, [$this->payment(lang('Sales.cash'), '60.00')]);
        $this->viewer();

        $result = $this->paymentsOf();
        $row    = $result['rows'][0];
        $id     = $this->accountingDb()->table('sales_payments')->where('sale_id', $saleId)->get()->getRow()->payment_id;

        $this->assertSame(1, $result['total']);
        $this->assertSame((int) $id, $row['payment_id']);
        $this->assertStringContainsString('>' . $id . '</a>', $row['number']);
        $this->assertStringContainsString('sales/receipt/' . $saleId, $row['number']);
        $this->assertStringContainsString('Omar Khaled', $row['customer_name']);
        $this->assertStringContainsString('accounting_receivables/customer/' . $this->customerId, $row['customer_name']);
        $this->assertStringContainsString('POS ' . $saleId, $row['sale']);
        $this->assertStringContainsString('60.00', $row['amount']);
        $this->assertStringContainsString('60.00', $row['posted']);
        $this->assertStringContainsString(lang('Accounting_receivables.tie_booked'), $row['posted']);
        $this->assertStringContainsString(lang('Sales.cash'), $row['way']);
        $this->assertSame('', $row['treasury']);
        $this->assertSame('Temp Employee', $row['collected_by']);
        $this->assertStringContainsString(lang('Sales.payment_status_completed'), $row['status']);
        $this->assertArrayNotHasKey('branch', $row);
        $this->assertStringContainsString('60.00', $result['sum_amount']);
        $this->assertStringContainsString('60.00', $result['sum_posted']);
    }

    public function testGetPaymentsSearch_ShowsTheNumberOfTheSequenceAndTheIdOfAPaymentThatPredatesIt(): void
    {
        $before = $this->invoiceOf('100.00', null, [$this->payment(lang('Sales.cash'), '10.00')]);
        $this->switchOn(DocumentType::CustomerPayment, 'PAY-', 4);
        $after = $this->invoiceOf('100.00', null, [$this->payment(lang('Sales.cash'), '20.00')]);
        $this->viewer();

        $rows = [];

        foreach ($this->paymentsOf()['rows'] as $row) {
            $rows[$row['payment_id']] = $row['number'];
        }

        $beforeId = (int) $this->accountingDb()->table('sales_payments')->where('sale_id', $before)->get()->getRow()->payment_id;
        $afterId  = (int) $this->accountingDb()->table('sales_payments')->where('sale_id', $after)->get()->getRow()->payment_id;

        $this->assertStringContainsString('>PAY-0001</a>', $rows[$afterId]);
        $this->assertStringContainsString('>' . $beforeId . '</a>', $rows[$beforeId]);
        $this->assertStringNotContainsString('PAY-', $rows[$beforeId]);
    }

    public function testGetPaymentsSearch_NamesTheTreasuryTheMoneyWentInto(): void
    {
        $this->accountingDb()->table('grants')->insert(['person_id' => $this->employeeId, 'permission_id' => BranchGuard::ALL_BRANCHES, 'menu_group' => 'home']);

        $treasuries = new TreasuryService();
        $drawer     = $treasuries->create(['name' => 'Front drawer', 'kind' => 'cash']);

        $treasuries->setDefaultFor($this->employeeId, $drawer);
        $this->invoiceOf('100.00', null, [$this->payment(lang('Sales.cash'), '100.00')]);
        $this->viewer();

        $this->assertSame('Front drawer', $this->paymentsOf()['rows'][0]['treasury']);
    }

    public function testGetPaymentsSearch_TiesTheTotalsOfTheListToTheJournalsOfThePayments(): void
    {
        $first = $this->invoiceOf('816.50', null, [
            $this->payment(lang('Sales.cash'), '300.00'),
            $this->payment(lang('Sales.check'), '163.50'),
        ]);
        $other = $this->anotherCustomer();
        $this->invoiceOf('50.00', $other, [$this->payment(lang('Sales.cash'), '50.00')]);
        $this->addPayment($first, '25.00');
        $this->viewer();

        $result = $this->paymentsOf();
        $paid   = $this->accountingDb()->query('SELECT COALESCE(SUM(total_debit), 0) AS total FROM ' . $this->accountingDb()->prefixTable('accounting_journals') . " WHERE source_type = 'sale_payment' AND status = 'posted'")->getRow()->total;

        $this->assertSame(4, $result['total']);
        $this->assertSame(strip_tags($result['sum_amount']), strip_tags($result['sum_posted']));
        $this->assertStringContainsString(number_format((float) $paid, 2, '.', ''), $result['sum_amount']);

        foreach ($result['rows'] as $row) {
            $this->assertStringContainsString(lang('Accounting_receivables.tie_booked'), $row['posted']);
        }
    }

    public function testGetPaymentsSearch_SaysWhenAPaymentIsNotInTheBooksOrDiffersFromWhatItsJournalPosted(): void
    {
        $saleId = $this->invoiceOf('100.00');
        $this->addPayment($saleId, '40.00');
        $held = (int) $this->accountingDb()->table('sales_payments')->where('sale_id', $saleId)->get()->getRow()->payment_id;
        $this->assertTrue($this->sales->update($saleId, ['comment' => 'held', 'payments' => [['payment_id' => NEW_ENTRY, 'payment_type' => lang('Sales.cash'), 'payment_amount' => '15.00', 'cash_refund' => 0, 'cash_adjustment' => 0, 'employee_id' => $this->employeeId, 'reference_code' => null, 'payment_status' => 'incomplete']]]));
        $this->accountingDb()->table('sales_payments')->where('payment_id', $held)->update(['payment_amount' => '45.00']);
        $this->viewer();

        $rows = [];

        foreach ($this->paymentsOf()['rows'] as $row) {
            $rows[$row['payment_id']] = $row;
        }

        $this->assertCount(2, $rows);
        $this->assertStringContainsString(lang('Accounting_receivables.tie_differs'), $rows[$held]['posted']);
        $this->assertStringContainsString(lang('Accounting_receivables.tie_unbooked'), $rows[max(array_keys($rows))]['posted']);
        $this->assertStringContainsString(lang('Sales.payment_status_incomplete'), $rows[max(array_keys($rows))]['status']);
    }

    public function testGetPaymentsSearch_ShowsARefundAsANegativeAmount(): void
    {
        $invoice = $this->invoiceOf('100.00', null, [$this->payment(lang('Sales.cash'), '100.00')]);
        $service = $this->item('0', false);
        $this->save([$this->line($service, 0, '-1', '30')], [], [$this->payment(lang('Sales.cash'), '-30.00')], SALE_TYPE_RETURN, -1, true, $invoice);
        $this->viewer();

        $result = $this->paymentsOf('?sort=amount&order=asc');

        $this->assertStringContainsString('-30.00', $result['rows'][0]['amount']);
        $this->assertStringContainsString('-30.00', $result['rows'][0]['posted']);
        $this->assertStringContainsString('70.00', $result['sum_amount']);
    }

    public function testGetPaymentsSearch_ShowsASaleWithoutACustomerAsASaleWithoutACustomer(): void
    {
        $service = $this->item('0', false);
        $this->save([$this->line($service, 0, '1', '25')], [], [$this->payment(lang('Sales.cash'), '25.00')], SALE_TYPE_POS, null);
        $this->viewer();

        $row = $this->paymentsOf()['rows'][0];

        $this->assertSame(lang('Accounting_receivables.counter_customer'), $row['customer_name']);
        $this->assertStringContainsString('25.00', $row['posted']);
    }

    public function testGetPaymentsSearch_FiltersByDatesCustomerWayEmployeeStatusAndSearch(): void
    {
        $other = $this->anotherCustomer('Hind', 'Saad');
        $mine  = $this->invoiceOf('100.00');
        $hers  = $this->invoiceOf('100.00', $other);
        $cash  = $this->addPayment($mine, '10.00');
        $check = $this->addPayment($hers, '20.00', lang('Sales.check'));
        $this->accountingDb()->table('sales_payments')->where('payment_id', $cash)->update(['payment_time' => date('Y-m-d 10:00:00', strtotime('-40 days'))]);
        $collector = $this->createEmployee('Layla', 'Hamad');
        $later     = $this->addPaymentFrom($hers, $collector, '5.00');
        $this->viewer();

        $ids = fn (array $result): array => array_column($result['rows'], 'payment_id');

        $this->assertEqualsCanonicalizing([$check, $later], $ids($this->paymentsOf('?date_from=' . date('Y-m-d', strtotime('-10 days')))));
        $this->assertSame([$cash], $ids($this->paymentsOf('?date_to=' . date('Y-m-d', strtotime('-10 days')))));
        $this->assertEqualsCanonicalizing([$check, $later], $ids($this->paymentsOf('?customer_id=' . $other)));
        $this->assertEqualsCanonicalizing([$check, $later], $ids($this->paymentsOf('?customer=hind')));
        $this->assertSame([$check], $ids($this->paymentsOf('?way=check')));
        $this->assertSame([$later], $ids($this->paymentsOf('?employee_id=' . $collector)));
        $this->assertSame([], $ids($this->paymentsOf('?status=failed')));
        $this->assertEqualsCanonicalizing([$cash, $check, $later], $ids($this->paymentsOf('?status=completed')));
        $this->assertSame([$cash], $ids($this->paymentsOf('?search=omar')));
        $this->assertContains($cash, $ids($this->paymentsOf('?search=' . $cash)));
        $this->assertSame(3, $this->paymentsOf('?way=nonsense')['total']);
    }

    public function testGetPaymentsSearch_PagesAndSortsTheResultAndClampsTheSizeOfAPage(): void
    {
        $sale = $this->invoiceOf('100.00');
        $a    = $this->addPayment($sale, '10.00');
        $b    = $this->addPayment($sale, '20.00');
        $c    = $this->addPayment($sale, '30.00');
        $this->viewer();

        $page = $this->paymentsOf('?sort=number&order=asc&limit=2&offset=1');

        $this->assertSame(3, $page['total']);
        $this->assertSame([$b, $c], array_column($page['rows'], 'payment_id'));
        $this->assertSame([$c, $b, $a], array_column($this->paymentsOf('?sort=amount&order=desc')['rows'], 'payment_id'));
        $this->assertSame(3, count($this->paymentsOf('?limit=100000')['rows']));
        $this->assertSame(1, count($this->paymentsOf('?limit=-5&offset=-9')['rows']));
        $this->assertSame(3, count($this->paymentsOf('?limit=0')['rows']));
    }

    public function testGetPaymentsSearch_ShowsEveryBranchToSomeoneWhoWorksInAllOfThemWithTheBranchColumn(): void
    {
        $branch = $this->createBranch('B2', 'Second branch');
        $here   = $this->addPayment($this->invoiceOf('100.00'), '10.00');
        $there  = $this->addPayment($this->invoiceInBranch($branch, '100.00'), '20.00');
        $this->viewer();

        $result = $this->paymentsOf();
        $byId   = [];

        foreach ($result['rows'] as $row) {
            $byId[$row['payment_id']] = $row;
        }

        $this->assertEqualsCanonicalizing([$here, $there], array_keys($byId));
        $this->assertSame('HQ - ' . $this->accountingDb()->table('branches')->where('branch_id', 1)->get()->getRow()->name, $byId[$here]['branch']);
        $this->assertSame('B2 - Second branch', $byId[$there]['branch']);
        $this->assertSame([$there], array_column($this->paymentsOf('?branch_id=' . $branch)['rows'], 'payment_id'));
        $this->get('/accounting_receivables/payments')->assertSee('id="f_branch"');
    }

    public function testGetPaymentsSearch_ShowsSomeoneLimitedToABranchOnlyThePaymentsOfTheirBranches(): void
    {
        $branch = $this->createBranch('B2', 'Second branch');
        $third  = $this->createBranch('B3', 'Third branch');
        $here   = $this->addPayment($this->invoiceOf('100.00'), '10.00');
        $there  = $this->addPayment($this->invoiceInBranch($branch, '100.00'), '20.00');
        $away   = $this->addPayment($this->invoiceInBranch($third, '500.00'), '400.00');
        $this->viewer([1, $branch]);

        $result = $this->paymentsOf();

        $this->assertEqualsCanonicalizing([$here, $there], array_column($result['rows'], 'payment_id'));
        $this->assertSame(2, $result['total']);
        $this->assertStringContainsString('30.00', $result['sum_amount']);
        $this->assertStringNotContainsString('430.00', $result['sum_amount']);
        $this->assertSame([], array_column($this->paymentsOf('?branch_id=' . $third)['rows'], 'payment_id'));
        $this->assertNotContains($away, array_column($this->paymentsOf('?search=' . $away)['rows'], 'payment_id'));
        $this->assertSame([$there], array_column($this->paymentsOf('?branch_id=' . $branch)['rows'], 'payment_id'));
        $this->assertArrayHasKey('branch', $result['rows'][0]);
    }

    public function testGetPayments_TellsSomeoneLimitedToSomeBranchesThatTheListIsPartial(): void
    {
        $branch = $this->createBranch('B2', 'Second branch');
        $this->createBranch('B3', 'Third branch');
        $this->viewer([1, $branch]);

        $response = $this->get('/accounting_receivables/payments');

        $response->assertStatus(200);
        $response->assertSee('id="payments_partial"');
        $response->assertSee(lang('Accounting_receivables.payments_partial'));
        $response->assertDontSee('accounting_receivables/credit_notes');
        $response->assertSee('nav-link py-1 active');
        $this->assertDoesNotMatchRegularExpression('/<a class="nav-link py-1" href="[^"]*">/', $response->getBody());
    }

    public function testGetPayments_OffersAsCollectorsOnlyThePeopleWhoCollectedInTheBranchesOfTheViewer(): void
    {
        $branch = $this->createBranch('B2', 'Second branch');
        $other  = $this->createEmployee('Layla', 'Hamad');
        $this->addPayment($this->invoiceOf('100.00'), '10.00');
        $this->addPaymentFrom($this->invoiceInBranch($branch, '100.00'), $other, '20.00');
        $this->viewer([1]);

        $body = $this->get('/accounting_receivables/payments')->getBody();

        $this->assertStringContainsString('Temp Employee', $body);
        $this->assertStringNotContainsString('Layla Hamad', $body);
    }

    public function testGetPaymentsSearch_ListsNothingForSomeoneWhoseBranchesHoldNoPayment(): void
    {
        $branch = $this->createBranch('B2', 'Second branch');
        $this->addPayment($this->invoiceOf('100.00'), '10.00');
        $this->viewer([$branch]);

        $this->assertSame(0, $this->paymentsOf()['total']);
        $this->assertSame([], $this->paymentsOf()['rows']);
    }

    public function testGetPaymentsSearch_LinksNoCustomerStatementForSomeoneLimitedToABranch(): void
    {
        $this->createBranch('B2', 'Second branch');
        $this->addPayment($this->invoiceOf('100.00'), '10.00');
        $this->viewer([1]);

        $row = $this->paymentsOf()['rows'][0];

        $this->assertStringContainsString('Omar Khaled', $row['customer_name']);
        $this->assertStringNotContainsString('accounting_receivables/customer/', $row['customer_name']);
    }

    // ---------------------------------------------------------------------------------------
    // The list of credit notes and refund invoices
    // ---------------------------------------------------------------------------------------

    public function testGetCreditNotes_ShowsTheListWithItsFiltersToWhoeverHoldsTheModuleForTheCompany(): void
    {
        $this->viewer();

        $response = $this->get('/accounting_receivables/credit_notes');

        $response->assertStatus(200);
        $response->assertSee('id="credit_note_filters"');
        $response->assertSee('id="f_document"');
        $response->assertSee(lang('Accounting_receivables.credit_notes_note'));
        $response->assertSee(lang('Numbering.type_credit_note'));
        $response->assertSee(lang('Numbering.type_refund_invoice'));
    }

    public function testGetCreditNotes_RedirectsAUserWithoutTheModuleGrant(): void
    {
        $this->withSession(['person_id' => $this->employeeWithGrants(['accounting_chart']), 'menu_group' => 'home']);

        $this->get('/accounting_receivables/credit_notes')->assertRedirect();
        $this->get('/accounting_receivables/credit_notes/search')->assertRedirect();
    }

    public function testGetCreditNotes_IsNotFoundWhileAccountingIsOff(): void
    {
        $this->viewer();
        $this->setConfigValue('accounting_enabled', '0');

        $this->expectException(PageNotFoundException::class);

        $this->get('/accounting_receivables/credit_notes');
    }

    public function testGetCreditNotes_IsNotFoundForSomeoneLimitedToABranchBecauseTheCreditCrossesBranches(): void
    {
        $this->viewer([1]);

        $this->expectException(PageNotFoundException::class);

        $this->get('/accounting_receivables/credit_notes');
    }

    public function testGetCreditNotesSearch_IsNotFoundForSomeoneLimitedToABranch(): void
    {
        $this->viewer([1]);

        $this->expectException(PageNotFoundException::class);

        $this->get('/accounting_receivables/credit_notes/search');
    }

    public function testGetCreditNotesSearch_ListsACreditNoteAndARefundInvoiceWithTheirNumbersInvoicesAndWhatIsOpen(): void
    {
        $this->switchOn(DocumentType::CreditNote, 'CN-', 4);
        $this->switchOn(DocumentType::RefundInvoice, 'RI-', 4);

        $invoice = $this->invoiceOf('100.00', null, [$this->payment(lang('Sales.cash'), '70.00')]);
        $this->accountingDb()->table('sales')->where('sale_id', $invoice)->update(['invoice_number' => 'INV-77']);
        $note   = $this->creditNoteOf($invoice, '50.00');
        $refund = $this->refundInvoiceOf($invoice, '10.00');
        $this->viewer();

        $result = $this->creditNotesOf('?sort=number&order=asc');
        $rows   = [];

        foreach ($result['rows'] as $row) {
            $rows[$row['sale_id']] = $row;
        }

        $this->assertSame(2, $result['total']);
        $this->assertStringContainsString('>CN-0001</a>', $rows[$note]['number']);
        $this->assertSame(lang('Numbering.type_credit_note'), $rows[$note]['document']);
        $this->assertStringContainsString('INV-77', $rows[$note]['origin']);
        $this->assertStringContainsString('sales/receipt/' . $invoice, $rows[$note]['origin']);
        $this->assertStringContainsString('Omar Khaled', $rows[$note]['customer_name']);
        $this->assertStringContainsString('50.00', $rows[$note]['amount']);
        $this->assertStringContainsString('30.00', $rows[$note]['settled']);
        $this->assertStringContainsString('20.00', $rows[$note]['open']);
        $this->assertStringContainsString('>RI-0001</a>', $rows[$refund]['number']);
        $this->assertSame(lang('Numbering.type_refund_invoice'), $rows[$refund]['document']);
        $this->assertStringContainsString('10.00', $rows[$refund]['amount']);
        $this->assertArrayNotHasKey('branch', $rows[$note]);
    }

    public function testGetCreditNotesSearch_ShowsTheReceiptNumberOfAReturnThatHasNoNumberAndNoInvoiceNumber(): void
    {
        $invoice = $this->invoiceOf('100.00');
        $note    = $this->creditNoteOf($invoice, '20.00');
        $this->viewer();

        $row = $this->creditNotesOf()['rows'][0];

        $this->assertStringContainsString('>POS ' . $note . '</a>', $row['number']);
        $this->assertStringContainsString('>POS ' . $invoice . '</a>', $row['origin']);
    }

    public function testGetCreditNotesSearch_SaysNoOriginalInvoiceAndNoSettlementForAReturnTheBooksDoNotHold(): void
    {
        $service = $this->item('0', false);
        $this->setConfigValue('accounting_enabled', '0');
        $invoice = $this->save([$this->line($service, 0, '1', '100')]);
        $this->save([$this->line($service, 0, '-1', '40')], [], [], SALE_TYPE_RETURN, -1, true, $invoice);
        $this->save([$this->line($service, 0, '-1', '25')], [], [], SALE_TYPE_RETURN, null);
        $this->setConfigValue('accounting_enabled', '1');
        $this->viewer();

        $rows = $this->creditNotesOf('?sort=number&order=asc')['rows'];

        $this->assertCount(2, $rows);
        $this->assertStringContainsString('40.00', $rows[0]['amount']);
        $this->assertStringContainsString(lang('Accounting_receivables.not_in_books'), $rows[0]['settled']);
        $this->assertSame('', $rows[0]['open']);
        $this->assertSame(lang('Accounting_receivables.no_original'), $rows[1]['origin']);
        $this->assertSame(lang('Accounting_receivables.counter_customer'), $rows[1]['customer_name']);
    }

    public function testGetCreditNotesSearch_FiltersByDocumentCustomerDatesAndSearchAndPages(): void
    {
        $other  = $this->anotherCustomer('Hind', 'Saad');
        $mine   = $this->invoiceOf('100.00');
        $hers   = $this->invoiceOf('100.00', $other);
        $note   = $this->creditNoteOf($mine, '10.00');
        $refund = $this->refundInvoiceOf($hers, '20.00', $other);
        $this->accountingDb()->table('sales')->where('sale_id', $note)->update(['sale_time' => date('Y-m-d 10:00:00', strtotime('-30 days'))]);
        $this->viewer();

        $ids = fn (array $result): array => array_column($result['rows'], 'sale_id');

        $this->assertSame([$refund], $ids($this->creditNotesOf('?document=refund_invoice')));
        $this->assertSame([$note], $ids($this->creditNotesOf('?document=credit_note')));
        $this->assertSame([$refund], $ids($this->creditNotesOf('?customer_id=' . $other)));
        $this->assertSame([$refund], $ids($this->creditNotesOf('?customer=saad')));
        $this->assertSame([$refund], $ids($this->creditNotesOf('?date_from=' . date('Y-m-d', strtotime('-5 days')))));
        $this->assertSame([$note], $ids($this->creditNotesOf('?date_to=' . date('Y-m-d', strtotime('-5 days')))));
        $this->assertSame([$refund], $ids($this->creditNotesOf('?search=' . $refund)));
        $this->assertSame([$refund, $note], $ids($this->creditNotesOf('?sort=number&order=desc')));
        $this->assertSame([$note], $ids($this->creditNotesOf('?sort=number&order=asc&limit=1&offset=0')));
        $this->assertSame(2, $this->creditNotesOf('?limit=1')['total']);
    }

    public function testGetCreditNotesSearch_ShowsTheBranchOfEachReturnToSomeoneWhoWorksInAllBranches(): void
    {
        $branch  = $this->createBranch('B2', 'Second branch');
        $service = $this->item('0', false);
        $here    = $this->invoiceOf('100.00');
        $there   = $this->invoiceInBranch($branch, '100.00');
        $first   = $this->creditNoteOf($here, '10.00');
        $second  = $this->save([$this->line($service, 0, '-1', '20', '0', '0', PERCENT, $this->branchLocation($branch))], [], [], SALE_TYPE_RETURN, -1, true, $there);
        $this->viewer();

        $rows = [];

        foreach ($this->creditNotesOf()['rows'] as $row) {
            $rows[$row['sale_id']] = $row;
        }

        $this->assertStringStartsWith('HQ - ', $rows[$first]['branch']);
        $this->assertSame('B2 - Second branch', $rows[$second]['branch']);
        $this->assertSame([$second], array_column($this->creditNotesOf('?branch_id=' . $branch)['rows'], 'sale_id'));
    }

    public function testGetPaymentsSearch_TiesAnEditedPaymentToItsNewJournalAndNotToTheOneItReversed(): void
    {
        $saleId    = $this->invoiceOf('100.00');
        $paymentId = $this->addPayment($saleId, '40.00');

        $this->assertTrue($this->sales->update($saleId, ['comment' => 'corrected', 'payments' => [
            ['payment_id' => $paymentId, 'payment_type' => lang('Sales.cash'), 'payment_amount' => '50.00', 'cash_refund' => 0, 'cash_adjustment' => 0, 'employee_id' => $this->employeeId, 'reference_code' => null],
        ]]));
        $this->viewer();

        $statuses = array_column($this->accountingDb()->table('accounting_journals')->select('status')->where(['source_type' => 'sale_payment', 'source_id' => $paymentId])->orderBy('journal_id')->get()->getResultArray(), 'status');
        $result   = $this->paymentsOf();
        $row      = $result['rows'][0];

        $this->assertSame(['reversed', 'posted'], $statuses);
        $this->assertSame(1, $result['total']);
        $this->assertStringContainsString('50.00', $row['amount']);
        $this->assertStringContainsString('50.00', $row['posted']);
        $this->assertStringNotContainsString('90.00', $row['posted']);
        $this->assertStringContainsString(lang('Accounting_receivables.tie_booked'), $row['posted']);
        $this->assertSame(strip_tags($result['sum_amount']), strip_tags($result['sum_posted']));
        $this->assertStringNotContainsString('90.00', $result['sum_posted']);
    }

    public function testGetPaymentsSearch_SaysDiffersWhenTheJournalOfAPaymentIsOffByLessThanACent(): void
    {
        $saleId = $this->invoiceOf('100.00');
        $large  = $this->addPayment($saleId, '40.00');
        $small  = $this->addPayment($saleId, '0.01');
        $this->accountingDb()->table('accounting_journals')->where(['source_type' => 'sale_payment', 'source_id' => $large])->update(['total_debit' => '40.0050']);
        $this->accountingDb()->table('accounting_journals')->where(['source_type' => 'sale_payment', 'source_id' => $small])->update(['total_debit' => '0.0050']);
        $this->viewer();

        $rows = [];

        foreach ($this->paymentsOf()['rows'] as $row) {
            $rows[$row['payment_id']] = $row;
        }

        $this->assertStringContainsString(lang('Accounting_receivables.tie_differs'), $rows[$large]['posted']);
        $this->assertStringContainsString(lang('Accounting_receivables.tie_differs'), $rows[$small]['posted']);
    }

    public function testGetPaymentsSearch_EscapesWhatStaffTypedInEveryCellOfTheList(): void
    {
        $customer   = ['<img src=x onerror=alert(1)>', 'O\'Hara & "Sons"'];
        $collector  = ['<script>alert(2)</script>', 'Nur & "Co"'];
        $drawerName = '<i>Drawer</i> & "Safe"';

        $this->renamePerson($this->customerId, $customer[0], $customer[1]);
        $this->renamePerson($this->employeeId, $collector[0], $collector[1]);

        $branch = $this->createBranch('<u>B2</u>', 'Second & "Branch"');
        $this->accountingDb()->table('grants')->insert(['person_id' => $this->employeeId, 'permission_id' => BranchGuard::ALL_BRANCHES, 'menu_group' => 'home']);

        $treasuries = new TreasuryService();
        $drawer     = $treasuries->create(['name' => $drawerName, 'kind' => 'cash', 'branch_id' => $branch]);

        $treasuries->setDefaultFor($this->employeeId, $drawer);

        $saleId = $this->invoiceInBranch($branch, '100.00');
        $this->accountingDb()->table('sales')->where('sale_id', $saleId)->update(['invoice_number' => '<b>INV</b>-1']);

        $paymentId = $this->addPayment($saleId, '60.00', lang('Sales.check'));
        $this->accountingDb()->table('sales_payments')->where('payment_id', $paymentId)->update(['reference_code' => '<b>CHQ</b>']);
        $this->accountingDb()->table('document_numbers')->insert(['company_id' => 1, 'doc_type' => DocumentType::CustomerPayment->value, 'source_table' => 'sales_payments', 'source_id' => $paymentId, 'sequence_number' => 1, 'number' => '<b>PAY</b>-1']);
        $this->viewer();

        $row = $this->paymentsOf()['rows'][0];

        $this->assertSame($paymentId, $row['payment_id']);
        $this->assertEscaped($customer[0] . ' ' . $customer[1], $row['customer_name']);
        $this->assertEscaped($collector[0] . ' ' . $collector[1], $row['collected_by']);
        $this->assertEscaped($drawerName, $row['treasury']);
        $this->assertEscaped('<b>INV</b>-1', $row['sale']);
        $this->assertEscaped('<b>CHQ</b>', $row['way']);
        $this->assertEscaped('<b>PAY</b>-1', $row['number']);
        $this->assertEscaped('<u>B2</u> - Second & "Branch"', $row['branch']);

        $this->viewer([$branch]);

        $limited = $this->paymentsOf()['rows'][0];

        $this->assertEscaped($customer[0] . ' ' . $customer[1], $limited['customer_name']);
        $this->assertStringNotContainsString('accounting_receivables/customer/', $limited['customer_name']);
    }

    public function testGetPaymentsSearch_NamesTheWayOfPaymentWithItsGiftCardAndItsReferenceAndEscapesWhatItCannotName(): void
    {
        $this->accountingDb()->table('giftcards')->insert(['giftcard_number' => 7774321, 'value' => 500, 'deleted' => 0, 'person_id' => null]);

        $sale  = $this->invoiceOf('300.00');
        $card  = $this->addPayment($sale, '30.00', lang('Sales.giftcard') . ':7774321');
        $check = $this->addPayment($sale, '20.00', lang('Sales.check'));
        $odd   = $this->addPayment($sale, '10.00');
        $this->accountingDb()->table('sales_payments')->where('payment_id', $card)->update(['payment_type' => lang('Sales.giftcard') . ':7774321']);
        $this->accountingDb()->table('sales_payments')->where('payment_id', $check)->update(['reference_code' => '<b>CHQ</b>']);
        $this->accountingDb()->table('sales_payments')->where('payment_id', $odd)->update(['payment_type' => '<u>Weird</u>:<i>1</i>']);
        $this->viewer();

        $ways = [];

        foreach ($this->paymentsOf()['rows'] as $row) {
            $ways[$row['payment_id']] = $row['way'];
        }

        $this->assertSame(esc(lang('Sales.giftcard') . ':7774321'), $ways[$card]);
        $this->assertSame(esc(lang('Sales.check')) . ' <bdi dir="ltr">' . esc('<b>CHQ</b>') . '</bdi>', $ways[$check]);
        $this->assertSame(esc('<u>Weird</u>:<i>1</i>'), $ways[$odd]);
    }

    public function testGetCreditNotesSearch_EscapesWhatStaffTypedInEveryCellOfTheList(): void
    {
        $customer = ['<img src=x onerror=alert(1)>', 'O\'Hara & "Sons"'];

        $this->renamePerson($this->customerId, $customer[0], $customer[1]);

        $branch  = $this->createBranch('<u>B2</u>', 'Second & "Branch"');
        $service = $this->item('0', false);
        $invoice = $this->invoiceInBranch($branch, '100.00');
        $this->accountingDb()->table('sales')->where('sale_id', $invoice)->update(['invoice_number' => '<b>INV</b>-1']);

        $return = $this->save([$this->line($service, 0, '-1', '20', '0', '0', PERCENT, $this->branchLocation($branch))], [], [], SALE_TYPE_RETURN, -1, true, $invoice);
        $this->accountingDb()->table('document_numbers')->insert(['company_id' => 1, 'doc_type' => DocumentType::CreditNote->value, 'source_table' => 'sales', 'source_id' => $return, 'sequence_number' => 1, 'number' => '<b>CN</b>-1']);
        $this->viewer();

        $row = $this->creditNotesOf()['rows'][0];

        $this->assertSame($return, $row['sale_id']);
        $this->assertEscaped($customer[0] . ' ' . $customer[1], $row['customer_name']);
        $this->assertEscaped('<b>INV</b>-1', $row['origin']);
        $this->assertEscaped('<b>CN</b>-1', $row['number']);
        $this->assertEscaped('<u>B2</u> - Second & "Branch"', $row['branch']);
    }

    private function assertEscaped(string $typed, string $cell): void
    {
        $this->assertStringContainsString(esc($typed), $cell);
        $this->assertStringNotContainsString($typed, $cell);
    }

    private function renamePerson(int $personId, string $firstName, string $lastName): void
    {
        $this->accountingDb()->table('people')->where('person_id', $personId)->update(['first_name' => $firstName, 'last_name' => $lastName]);
    }

    private function addPaymentFrom(int $saleId, int $employeeId, string $amount): int
    {
        $this->assertTrue($this->sales->update($saleId, ['comment' => 'paid', 'payments' => [
            ['payment_id' => NEW_ENTRY, 'payment_type' => lang('Sales.cash'), 'payment_amount' => $amount, 'cash_refund' => 0, 'cash_adjustment' => 0, 'employee_id' => $employeeId, 'reference_code' => null],
        ]]));

        return (int) $this->accountingDb()->table('sales_payments')->selectMax('payment_id', 'last')->where('sale_id', $saleId)->get()->getRow()->last;
    }

    /**
     * @param list<int>|null $branchIds
     */
    private function openingManager(?array $branchIds = null): int
    {
        $personId = $this->employeeWithGrants(['accounting_receivables', AccountingGuard::OPENING_MANAGE], $branchIds);

        $this->withSession(['person_id' => $personId, 'menu_group' => 'home']);

        return $personId;
    }

    /**
     * @return array<string, mixed>
     */
    private function openingPost(string $path, array $data): array
    {
        return json_decode($this->post($path, $data)->getJSON(), true);
    }

    private function enterOpening(string $amount = '1000.00', string $side = 'owes_us'): int
    {
        return (new CustomerOpening())->create($this->customerId, $side, $amount, $this->daysAgo(30), 'before the books', null);
    }

    public function testGetCustomer_OffersTheOpeningBalanceOnlyToWhoManagesOpeningBalancesWhileTheSettingIsOn(): void
    {
        $url = '/accounting_receivables/customer/' . $this->customerId;
        $this->setConfigValue('accounting_customer_opening', '1');
        $this->viewer();

        $this->assertStringNotContainsString('customer_opening_new', $this->get($url)->getBody());

        $this->openingManager();
        $this->setConfigValue('accounting_customer_opening', '0');

        $off = $this->get($url)->getBody();

        $this->assertStringNotContainsString('customer_opening_new', $off);
        $this->assertStringNotContainsString('id="receivable_openings"', $off);

        $this->setConfigValue('accounting_customer_opening', '1');

        $on = $this->get($url)->getBody();

        $this->assertStringContainsString('customer_opening_new', $on);
        $this->assertStringContainsString('accounting_receivables/opening_form/' . $this->customerId, $on);
    }

    public function testGetOpeningForm_ShowsTheFormToWhoManagesOpeningBalancesWhileTheSettingIsOn(): void
    {
        $this->openingManager();
        $this->setConfigValue('accounting_customer_opening', '1');

        $form = $this->get('/accounting_receivables/opening_form/' . $this->customerId);

        $form->assertStatus(200);
        $form->assertSee('customer_opening_form');
        $form->assertSee('name="opening_date"');
        $form->assertSee('owes_us');
        $form->assertDontSee('name="reason"');
    }

    public function testGetOpeningForm_IsNotFoundForACustomerWithoutAnAccountAndForOneWhoHasAnOpeningBalance(): void
    {
        $this->openingManager();
        $this->setConfigValue('accounting_customer_opening', '1');
        $without = $this->insertCustomer('Nora', 'Fahd');

        try {
            $this->get('/accounting_receivables/opening_form/' . $without);
            $this->fail('A customer without an account must be hidden.');
        } catch (PageNotFoundException) {
            $this->addToAssertionCount(1);
        }

        $this->enterOpening();

        $this->expectException(PageNotFoundException::class);

        $this->get('/accounting_receivables/opening_form/' . $this->customerId);
    }

    public function testGetOpeningForm_IsNotFoundWithoutThePermission(): void
    {
        $this->viewer();
        $this->setConfigValue('accounting_customer_opening', '1');

        $this->expectException(PageNotFoundException::class);

        $this->get('/accounting_receivables/opening_form/' . $this->customerId);
    }

    public function testGetOpeningForm_IsNotFoundWhileTheSettingIsOff(): void
    {
        $this->openingManager();
        $this->setConfigValue('accounting_customer_opening', '0');

        $this->expectException(PageNotFoundException::class);

        $this->get('/accounting_receivables/opening_form/' . $this->customerId);
    }

    public function testGetOpeningForm_IsNotFoundForSomeoneLimitedToABranch(): void
    {
        $this->openingManager([1]);
        $this->setConfigValue('accounting_customer_opening', '1');

        $this->expectException(PageNotFoundException::class);

        $this->get('/accounting_receivables/opening_form/' . $this->customerId);
    }

    public function testPostOpening_EntersTheOpeningBalanceAndTheStatementShowsItAsAnInvoiceAndInTheOpeningsList(): void
    {
        $this->openingManager();
        $this->setConfigValue('accounting_customer_opening', '1');

        $done = $this->openingPost('/accounting_receivables/opening/' . $this->customerId, ['side' => 'owes_us', 'amount' => '1000.00', 'opening_date' => $this->daysAgo(45), 'note' => 'carried over']);

        $this->assertTrue($done['success']);
        $this->assertSame(lang('Accounting_receivables.opening_done'), $done['message']);
        $this->assertSame('1000.00', $this->balance($this->customerAccount()));

        $body = $this->get('/accounting_receivables/customer/' . $this->customerId)->getBody();

        $this->assertStringContainsString('id="receivable_openings"', $body);
        $this->assertStringContainsString('carried over', $body);
        $this->assertStringContainsString(lang('Accounting_receivables.source_opening'), $body);
        $this->assertStringContainsString(lang('Accounting_receivables.agrees_yes'), $body);
        $this->assertStringContainsString('opening_edit_form/' . $done['id'], $body);
        $this->assertStringContainsString('opening_cancel_form/' . $done['id'], $body);
        $this->assertStringNotContainsString('customer_opening_new', $body, 'A customer who has one cannot be given another');

        $row = $this->rowsOf()['rows'][0];

        $this->assertSame($this->customerId, (int) $row['customer_id']);
        $this->assertStringContainsString('1,000.00', strip_tags($row['balance']));
        $this->assertStringContainsString('1,000.00', strip_tags($row['age_31_60']));
    }

    public function testPostOpening_RefusesWithoutThePermissionAndWhileTheSettingIsOff(): void
    {
        $this->viewer();
        $this->setConfigValue('accounting_customer_opening', '1');

        $denied = $this->openingPost('/accounting_receivables/opening/' . $this->customerId, ['side' => 'owes_us', 'amount' => '10.00', 'opening_date' => date('Y-m-d')]);

        $this->assertFalse($denied['success']);
        $this->assertSame(0, $this->countRows('customer_openings'));

        $this->openingManager();
        $this->setConfigValue('accounting_customer_opening', '0');

        $off = $this->openingPost('/accounting_receivables/opening/' . $this->customerId, ['side' => 'owes_us', 'amount' => '10.00', 'opening_date' => date('Y-m-d')]);

        $this->assertFalse($off['success']);
        $this->assertSame(lang('Accounting.error_customer_opening_off'), $off['message']);
        $this->assertSame(0, $this->countRows('customer_openings'));
    }

    public function testPostOpening_IsNotFoundForSomeoneLimitedToABranchAndSavesNothing(): void
    {
        $this->setConfigValue('accounting_customer_opening', '1');
        $this->openingManager([1]);

        try {
            $this->post('/accounting_receivables/opening/' . $this->customerId, ['side' => 'owes_us', 'amount' => '10.00', 'opening_date' => date('Y-m-d')]);
            $this->fail('Someone limited to a branch must not enter an opening balance of the company.');
        } catch (PageNotFoundException) {
            $this->addToAssertionCount(1);
        }

        $this->assertSame(0, $this->countRows('customer_openings'));
    }

    public function testPostOpening_ReportsWhatTheBooksRefuseInTheLanguageInUse(): void
    {
        $this->openingManager();
        $this->setConfigValue('accounting_customer_opening', '1');
        $this->closeThrough($this->daysAgo(10));

        $closed = $this->openingPost('/accounting_receivables/opening/' . $this->customerId, ['side' => 'owes_us', 'amount' => '10.00', 'opening_date' => $this->daysAgo(20)]);
        $zero   = $this->openingPost('/accounting_receivables/opening/' . $this->customerId, ['side' => 'owes_us', 'amount' => '0', 'opening_date' => $this->daysAgo(5)]);

        $this->assertFalse($closed['success']);
        $this->assertFalse($zero['success']);
        $this->assertSame(0, $this->countRows('customer_openings'));

        $this->assertTrue($this->openingPost('/accounting_receivables/opening/' . $this->customerId, ['side' => 'owes_us', 'amount' => '10.00', 'opening_date' => $this->daysAgo(5)])['success']);

        $again = $this->openingPost('/accounting_receivables/opening/' . $this->customerId, ['side' => 'owes_us', 'amount' => '10.00', 'opening_date' => $this->daysAgo(5)]);

        $this->assertFalse($again['success']);
        $this->assertSame(lang('Accounting.error_customer_opening_exists'), $again['message']);
        $this->assertSame(1, $this->countRows('customer_openings'));
    }

    public function testPostOpeningEdit_PostsANewRevisionWithAReasonAndKeepsTheOldOneInTheList(): void
    {
        $this->openingManager();
        $this->setConfigValue('accounting_customer_opening', '1');
        $first = $this->enterOpening('1000.00');

        $form = $this->get('/accounting_receivables/opening_edit_form/' . $first);

        $form->assertStatus(200);
        $form->assertSee('name="reason"');
        $form->assertSee('value="1000"');

        $missing = $this->openingPost('/accounting_receivables/opening_edit/' . $first, ['side' => 'owes_us', 'amount' => '1100.00', 'opening_date' => $this->daysAgo(30), 'note' => '', 'reason' => '']);

        $this->assertFalse($missing['success']);
        $this->assertSame('1000.00', $this->balance($this->customerAccount()));

        $done = $this->openingPost('/accounting_receivables/opening_edit/' . $first, ['side' => 'owes_us', 'amount' => '1100.00', 'opening_date' => $this->daysAgo(30), 'note' => 'fixed', 'reason' => 'The statement said 1100']);

        $this->assertTrue($done['success']);
        $this->assertSame(lang('Accounting_receivables.opening_revised'), $done['message']);
        $this->assertSame('1100.00', $this->balance($this->customerAccount()));

        $body = $this->get('/accounting_receivables/customer/' . $this->customerId)->getBody();

        $this->assertStringContainsString('The statement said 1100', $body);
        $this->assertStringContainsString(lang('Accounting_receivables.opening_status_reversed'), $body);
        $this->assertStringContainsString(lang('Accounting_receivables.opening_status_posted'), $body);

        try {
            $this->get('/accounting_receivables/opening_edit_form/' . $first);
            $this->fail('A revision that was reversed cannot be changed.');
        } catch (PageNotFoundException) {
            $this->addToAssertionCount(1);
        }
    }

    public function testGetOpeningEditForm_FillsTheSideTheDateAndTheNoteOfTheRevisionThatStands(): void
    {
        $this->openingManager();
        $this->setConfigValue('accounting_customer_opening', '1');
        $date  = $this->daysAgo(12);
        $first = (new CustomerOpening())->create($this->customerId, 'we_owe', '300.00', $date, 'a credit the customer holds', null);

        $form = $this->get('/accounting_receivables/opening_edit_form/' . $first);

        $form->assertStatus(200);
        $form->assertSee('<option value="we_owe" selected>');
        $form->assertDontSee('<option value="owes_us" selected>');
        $form->assertSee('name="opening_date" id="opening_date"');
        $form->assertSee('value="' . $date . '"');
        $form->assertSee('>a credit the customer holds</textarea>');
        $form->assertSee('value="300"');
    }

    public function testGetOpeningForm_StartsOnTheCustomerOwingUsWithAnEmptyAmountAndNote(): void
    {
        $this->openingManager();
        $this->setConfigValue('accounting_customer_opening', '1');

        $form = $this->get('/accounting_receivables/opening_form/' . $this->customerId);

        $form->assertSee('<option value="owes_us" selected>');
        $form->assertDontSee('<option value="we_owe" selected>');
        $form->assertSee('></textarea>');
        $form->assertSee('name="opening_date" id="opening_date" class="form-control form-control-sm ltr-input" dir="ltr" max="' . date('Y-m-d') . '" value="' . date('Y-m-d') . '"');
        $form->assertSee('inputmode="decimal" autocomplete="off" value=""');
    }

    public function testGetOpeningEditForm_IsNotFoundWithoutThePermissionWhileTheSettingIsOffAndForANumberThatIsNotThere(): void
    {
        $this->setConfigValue('accounting_customer_opening', '1');
        $first = $this->enterOpening();

        $this->viewer();

        try {
            $this->get('/accounting_receivables/opening_edit_form/' . $first);
            $this->fail('Without the permission the form is hidden.');
        } catch (PageNotFoundException) {
            $this->addToAssertionCount(1);
        }

        $this->openingManager();

        try {
            $this->get('/accounting_receivables/opening_edit_form/9999999');
            $this->fail('A number that is not there is hidden.');
        } catch (PageNotFoundException) {
            $this->addToAssertionCount(1);
        }

        $this->setConfigValue('accounting_customer_opening', '0');

        $this->expectException(PageNotFoundException::class);

        $this->get('/accounting_receivables/opening_edit_form/' . $first);
    }

    public function testPostOpeningCancel_ReversesTheOpeningWithAReasonAndLeavesItInTheListCancelled(): void
    {
        $this->openingManager();
        $this->setConfigValue('accounting_customer_opening', '1');
        $first = $this->enterOpening('1000.00');

        $this->get('/accounting_receivables/opening_cancel_form/' . $first)->assertSee('customer_opening_cancel_form');

        $missing = $this->openingPost('/accounting_receivables/opening_cancel/' . $first, ['reason' => '']);

        $this->assertFalse($missing['success']);
        $this->assertSame('posted', $this->accountingDb()->table('customer_openings')->where('opening_id', $first)->get()->getRow()->status);

        $done = $this->openingPost('/accounting_receivables/opening_cancel/' . $first, ['reason' => 'Entered twice']);

        $this->assertTrue($done['success']);
        $this->assertSame(lang('Accounting_receivables.opening_cancelled'), $done['message']);
        $this->assertSame('0.00', $this->balance($this->customerAccount()));

        $body = $this->get('/accounting_receivables/customer/' . $this->customerId)->getBody();

        $this->assertStringContainsString('Entered twice', $body);
        $this->assertStringContainsString('customer_opening_new', $body, 'With the opening cancelled another can be entered');

        $again = $this->openingPost('/accounting_receivables/opening_cancel/' . $first, ['reason' => 'Again']);

        $this->assertFalse($again['success']);
    }

    public function testPostOpeningCancel_WorksWhileTheSettingIsOffButNeedsThePermission(): void
    {
        $this->setConfigValue('accounting_customer_opening', '1');
        $first = $this->enterOpening();
        $this->setConfigValue('accounting_customer_opening', '0');

        $this->viewer();

        try {
            $this->get('/accounting_receivables/opening_cancel_form/' . $first);
            $this->fail('Without the permission the form is hidden.');
        } catch (PageNotFoundException) {
            $this->addToAssertionCount(1);
        }

        $this->assertFalse($this->openingPost('/accounting_receivables/opening_cancel/' . $first, ['reason' => 'No right'])['success']);

        $this->openingManager();

        $this->assertTrue($this->openingPost('/accounting_receivables/opening_cancel/' . $first, ['reason' => 'Switching it off'])['success']);
    }

    public function testGetCustomer_NamesTheOpeningBalanceInTheInvoicesAndTheHistoryOfWhatWasSpentOnIt(): void
    {
        $this->setConfigValue('accounting_customer_opening', '1');
        $opening = $this->enterOpening('500.00');
        $this->invoiceDated('100.00', $this->daysAgo(2), null, null, [$this->payment(lang('Sales.cash'), '300.00')]);
        $this->viewer();

        $body = $this->get('/accounting_receivables/customer/' . $this->customerId)->getBody();

        $this->assertStringContainsString(lang('Accounting_receivables.source_opening') . ' <bdi dir="ltr">#' . $opening . '</bdi>', $body);
        $this->assertStringContainsString(lang('Accounting_receivables.agrees_yes'), $body);
        $this->assertStringNotContainsString('sales/receipt/-', $body, 'An opening balance has no receipt to link to');
    }

    public function testGetCustomer_ShowsTheCreditAnOpeningBalanceHoldsAmongTheCreditsWithoutALinkToAReceipt(): void
    {
        $this->setConfigValue('accounting_customer_opening', '1');
        $opening = $this->enterOpening('50.00', 'we_owe');
        $this->viewer();

        $body = $this->get('/accounting_receivables/customer/' . $this->customerId)->getBody();

        $this->assertMatchesRegularExpression('/' . preg_quote(lang('Accounting_receivables.source_opening'), '/') . '\s*<bdi dir="ltr">#' . $opening . '<\/bdi>/', substr($body, (int) strpos($body, 'id="receivable_credits"')));
        $this->assertStringNotContainsString('sales/receipt/' . $opening, $body);
        $this->assertStringContainsString(lang('Accounting_receivables.agrees_yes'), $body);
    }

    public function testGetOpeningEditForm_IsNotFoundForSomeoneLimitedToABranch(): void
    {
        $this->setConfigValue('accounting_customer_opening', '1');
        $first = $this->enterOpening();
        $this->openingManager([1]);

        $this->expectException(PageNotFoundException::class);

        $this->get('/accounting_receivables/opening_edit_form/' . $first);
    }

    public function testGetOpeningCancelForm_IsNotFoundForSomeoneLimitedToABranch(): void
    {
        $this->setConfigValue('accounting_customer_opening', '1');
        $first = $this->enterOpening();
        $this->openingManager([1]);

        $this->expectException(PageNotFoundException::class);

        $this->get('/accounting_receivables/opening_cancel_form/' . $first);
    }

    public function testPostOpeningEdit_IsNotFoundForSomeoneLimitedToABranchAndChangesNothing(): void
    {
        $this->setConfigValue('accounting_customer_opening', '1');
        $first = $this->enterOpening();
        $this->openingManager([1]);

        try {
            $this->post('/accounting_receivables/opening_edit/' . $first, ['side' => 'owes_us', 'amount' => '5.00', 'opening_date' => date('Y-m-d'), 'note' => '', 'reason' => 'Why']);
            $this->fail('Someone limited to a branch must not change an opening balance of the company.');
        } catch (PageNotFoundException) {
            $this->addToAssertionCount(1);
        }

        $this->assertSame('posted', $this->accountingDb()->table('customer_openings')->where('opening_id', $first)->get()->getRow()->status);
        $this->assertSame(1, $this->countRows('customer_openings'));
    }

    public function testPostOpeningCancel_IsNotFoundForSomeoneLimitedToABranchAndChangesNothing(): void
    {
        $this->setConfigValue('accounting_customer_opening', '1');
        $first = $this->enterOpening();
        $this->openingManager([1]);

        try {
            $this->post('/accounting_receivables/opening_cancel/' . $first, ['reason' => 'Why']);
            $this->fail('Someone limited to a branch must not cancel an opening balance of the company.');
        } catch (PageNotFoundException) {
            $this->addToAssertionCount(1);
        }

        $this->assertSame('posted', $this->accountingDb()->table('customer_openings')->where('opening_id', $first)->get()->getRow()->status);
        $this->assertSame(1, $this->countRows('customer_openings'));
    }

    public function testPostOpeningEdit_RefusesWithoutThePermissionAndChangesNothing(): void
    {
        $this->setConfigValue('accounting_customer_opening', '1');
        $first = $this->enterOpening();
        $this->viewer();

        $edit = $this->openingPost('/accounting_receivables/opening_edit/' . $first, ['side' => 'owes_us', 'amount' => '5.00', 'opening_date' => date('Y-m-d'), 'note' => '', 'reason' => 'Why']);

        $this->assertFalse($edit['success']);
        $this->assertSame('posted', $this->accountingDb()->table('customer_openings')->where('opening_id', $first)->get()->getRow()->status);
        $this->assertSame(1, $this->countRows('customer_openings'));
    }

    public function testPostOpeningCancel_RefusesWithoutThePermissionAndChangesNothing(): void
    {
        $this->setConfigValue('accounting_customer_opening', '1');
        $first = $this->enterOpening();
        $this->viewer();

        $cancel = $this->openingPost('/accounting_receivables/opening_cancel/' . $first, ['reason' => 'Why']);

        $this->assertFalse($cancel['success']);
        $this->assertSame('posted', $this->accountingDb()->table('customer_openings')->where('opening_id', $first)->get()->getRow()->status);
        $this->assertSame(1, $this->countRows('customer_openings'));
    }

    // ---------------------------------------------------------------------------------------
    // Advance payments and cash refunds of the credit of a customer
    // ---------------------------------------------------------------------------------------

    /**
     * An employee who sees the balances, takes advance payments and pays credit back, with the right
     * to open treasuries so that a test can name one.
     *
     * @param list<string>   $extra
     * @param list<int>|null $branchIds
     */
    private function teller(array $extra = [AccountingGuard::RECEIVABLES_MANAGE, AccountingGuard::RECEIVABLES_REFUND, AccountingGuard::TREASURIES_MANAGE], ?array $branchIds = null): int
    {
        $personId = $this->employeeWithGrants(['accounting_receivables', ...$extra], $branchIds);

        $this->withSession(['person_id' => $personId, 'menu_group' => 'home']);

        return $personId;
    }

    /**
     * @return array<string, mixed>
     */
    private function bodyOf(TestResponse $response): array
    {
        return json_decode($response->getJSON(), true);
    }

    private function advanceOf(string $amount, int $teller): int
    {
        return (new CustomerAdvances())->receive($this->customerId, $amount, date('Y-m-d'), 'Cash', null, '', '', $teller);
    }

    public function testGetAdvanceForm_OffersTheFieldsAndOnlyTheTreasuriesTheTellerMayDepositInto(): void
    {
        $teller = $this->teller();
        $other  = $this->employeeWithGrants([]);

        $alone = $this->get('/accounting_receivables/advance_form/' . $this->customerId);

        $alone->assertStatus(200);
        $alone->assertSee('name="amount"');
        $alone->assertSee('name="advance_date"');
        $alone->assertSee('name="payment_type"');
        $alone->assertSee('name="request_key"');
        $alone->assertDontSee('name="treasury_id"');

        (new TreasuryService())->create(['name' => 'Open drawer', 'kind' => 'cash'], $teller);
        (new TreasuryService())->create(['name' => 'Locked drawer', 'kind' => 'cash', 'deposit_scope' => 'staff', 'deposit_members' => [$other]], $teller);

        $with = $this->get('/accounting_receivables/advance_form/' . $this->customerId);

        $with->assertSee('name="treasury_id"');
        $with->assertSee('Open drawer');
        $with->assertDontSee('Locked drawer');
    }

    public function testGetAdvanceForm_IsNotFoundWithoutThePermissionToManageAndForSomeoneLimitedToABranch(): void
    {
        $this->viewer();

        try {
            $this->get('/accounting_receivables/advance_form/' . $this->customerId);
            $this->fail('A viewer opened the form.');
        } catch (PageNotFoundException) {
            $this->addToAssertionCount(1);
        }

        $this->teller([AccountingGuard::RECEIVABLES_MANAGE], [1]);

        $this->expectException(PageNotFoundException::class);

        $this->get('/accounting_receivables/advance_form/' . $this->customerId);
    }

    public function testPostAdvance_SavesTheAdvanceAndSpendsItOnTheOpenInvoice(): void
    {
        $invoice = $this->invoiceOf('300.00');
        $this->teller();

        $result = $this->bodyOf($this->post('/accounting_receivables/advance/' . $this->customerId, [
            'amount'         => '120.00',
            'advance_date'   => date('Y-m-d'),
            'payment_type'   => 'Cash',
            'reference_code' => 'R-1',
            'note'           => 'on account',
        ]));

        $this->assertTrue($result['success']);
        $this->assertSame(lang('Accounting_receivables.advance_done'), $result['message']);
        $this->assertSame('180.00', $this->balance($this->customerAccount()));
        $this->assertSame('180.00', bcadd($this->position()->invoice($invoice)->open(), '0', 2));
        $this->assertTies();
    }

    public function testPostAdvance_SavesOneAdvanceWhenTheFormIsSentTwice(): void
    {
        $this->teller();

        $fields = ['amount' => '50.00', 'advance_date' => date('Y-m-d'), 'payment_type' => 'Cash', 'request_key' => 'form-abc'];
        $first  = $this->bodyOf($this->post('/accounting_receivables/advance/' . $this->customerId, $fields));
        $second = $this->bodyOf($this->post('/accounting_receivables/advance/' . $this->customerId, $fields));

        $this->assertTrue($first['success']);
        $this->assertSame($first['id'], $second['id']);
        $this->assertSame(1, $this->countRows('customer_advances'));
        $this->assertSame('-50.00', $this->balance($this->customerAccount()));
    }

    public function testPostAdvance_SaysWhyItWasRefusedAndSavesNothing(): void
    {
        $this->teller();

        $result = $this->bodyOf($this->post('/accounting_receivables/advance/' . $this->customerId, ['amount' => '0', 'advance_date' => date('Y-m-d'), 'payment_type' => 'Cash']));

        $this->assertFalse($result['success']);
        $this->assertSame(lang('Accounting.error_advance_amount_invalid'), $result['message']);
        $this->assertSame(0, $this->countRows('customer_advances'));
    }

    public function testPostAdvance_IsRefusedToWhoHoldsNoPermissionToManage(): void
    {
        $this->viewer();

        $result = $this->bodyOf($this->post('/accounting_receivables/advance/' . $this->customerId, ['amount' => '10', 'advance_date' => date('Y-m-d'), 'payment_type' => 'Cash']));

        $this->assertFalse($result['success']);
        $this->assertSame(lang('Accounting.error_permission_denied'), $result['message']);
        $this->assertSame(0, $this->countRows('customer_advances'));
    }

    public function testPostAdvance_RefusesATreasuryTheTellerMayNotDepositIntoAndSaysWhich(): void
    {
        $teller = $this->teller();
        $other  = $this->employeeWithGrants([]);
        $locked = (new TreasuryService())->create(['name' => 'Locked drawer', 'kind' => 'cash', 'deposit_scope' => 'staff', 'deposit_members' => [$other]], $teller);

        $result = $this->bodyOf($this->post('/accounting_receivables/advance/' . $this->customerId, ['amount' => '50', 'advance_date' => date('Y-m-d'), 'payment_type' => 'Cash', 'treasury_id' => $locked]));

        $this->assertFalse($result['success']);
        $this->assertStringContainsString('Locked drawer', $result['message']);
        $this->assertSame(0, $this->countRows('customer_advances'));
    }

    public function testPostAdvance_IsRefusedInAClosedPeriodAndSavesNothing(): void
    {
        $this->teller();
        $this->closeThrough(date('Y-m-d'));

        $result = $this->bodyOf($this->post('/accounting_receivables/advance/' . $this->customerId, ['amount' => '50', 'advance_date' => date('Y-m-d'), 'payment_type' => 'Cash']));

        $this->assertFalse($result['success']);
        $this->assertStringContainsString(date('Y-m-d'), $result['message']);
        $this->assertSame(0, $this->countRows('customer_advances'));
    }

    public function testPostAdvance_IsNotFoundForSomeoneLimitedToABranch(): void
    {
        $this->teller([AccountingGuard::RECEIVABLES_MANAGE], [1]);

        $this->expectException(PageNotFoundException::class);

        $this->post('/accounting_receivables/advance/' . $this->customerId, ['amount' => '10', 'advance_date' => date('Y-m-d'), 'payment_type' => 'Cash']);
    }

    public function testGetRefundForm_ShowsTheCreditThatIsFreeToPayBack(): void
    {
        $teller = $this->teller();

        $this->advanceOf('200.00', $teller);

        $response = $this->get('/accounting_receivables/refund_form/' . $this->customerId);

        $response->assertStatus(200);
        $response->assertSee('name="refund_date"');
        $response->assertSee('name="request_key"');
        $response->assertSee('id="refund_free"');
        $response->assertSee('200.00');
    }

    public function testGetRefundForm_IsNotFoundForWhoHoldsOnlyThePermissionToTakeAdvances(): void
    {
        $this->teller([AccountingGuard::RECEIVABLES_MANAGE]);

        $this->expectException(PageNotFoundException::class);

        $this->get('/accounting_receivables/refund_form/' . $this->customerId);
    }

    public function testPostRefund_PaysTheCreditBackAndTiesToTheTreasury(): void
    {
        $teller = $this->teller();
        $drawer = (new TreasuryService())->create(['name' => 'Refund drawer', 'kind' => 'cash'], $teller);

        $this->advanceOf('200.00', $teller);

        $result = $this->bodyOf($this->post('/accounting_receivables/refund/' . $this->customerId, ['amount' => '80.00', 'refund_date' => date('Y-m-d'), 'payment_type' => 'Cash', 'treasury_id' => $drawer]));

        $this->assertTrue($result['success']);
        $this->assertSame(lang('Accounting_receivables.refund_done'), $result['message']);
        $this->assertSame('-80.0000', (new TreasuryService())->position($drawer)->balance);
        $this->assertSame('120.00', bcadd($this->position()->credit(), '0', 2));
        $this->assertTies();
    }

    public function testPostRefund_SavesOneRefundWhenTheFormIsSentTwice(): void
    {
        $teller = $this->teller();

        $this->advanceOf('200.00', $teller);

        $fields = ['amount' => '30.00', 'refund_date' => date('Y-m-d'), 'payment_type' => 'Cash', 'request_key' => 'refund-abc'];
        $first  = $this->bodyOf($this->post('/accounting_receivables/refund/' . $this->customerId, $fields));
        $second = $this->bodyOf($this->post('/accounting_receivables/refund/' . $this->customerId, $fields));

        $this->assertSame($first['id'], $second['id']);
        $this->assertSame(1, $this->countRows('customer_credit_refunds'));
    }

    public function testPostRefund_IsRefusedToWhoHoldsOnlyThePermissionToTakeAdvancesAndPaysNothing(): void
    {
        $teller = $this->teller([AccountingGuard::RECEIVABLES_MANAGE]);

        $this->advanceOf('200.00', $teller);

        $result = $this->bodyOf($this->post('/accounting_receivables/refund/' . $this->customerId, ['amount' => '30.00', 'refund_date' => date('Y-m-d'), 'payment_type' => 'Cash']));

        $this->assertFalse($result['success']);
        $this->assertSame(lang('Accounting.error_permission_denied'), $result['message']);
        $this->assertSame(0, $this->countRows('customer_credit_refunds'));
    }

    public function testPostRefund_RefusesMoreThanTheCreditThatIsFreeAndSaysHowMuchIs(): void
    {
        $teller = $this->teller();

        $this->advanceOf('100.00', $teller);

        $result = $this->bodyOf($this->post('/accounting_receivables/refund/' . $this->customerId, ['amount' => '100.01', 'refund_date' => date('Y-m-d'), 'payment_type' => 'Cash']));

        $this->assertFalse($result['success']);
        $this->assertStringContainsString('100.00', $result['message']);
        $this->assertSame(0, $this->countRows('customer_credit_refunds'));
    }

    public function testPostRefund_RefusesATreasuryTheTellerMayNotWithdrawFrom(): void
    {
        $teller = $this->teller();
        $other  = $this->employeeWithGrants([]);
        $locked = (new TreasuryService())->create(['name' => 'Locked drawer', 'kind' => 'cash', 'withdraw_scope' => 'staff', 'withdraw_members' => [$other]], $teller);

        $this->advanceOf('100.00', $teller);

        $result = $this->bodyOf($this->post('/accounting_receivables/refund/' . $this->customerId, ['amount' => '10', 'refund_date' => date('Y-m-d'), 'payment_type' => 'Cash', 'treasury_id' => $locked]));

        $this->assertFalse($result['success']);
        $this->assertStringContainsString('Locked drawer', $result['message']);
        $this->assertSame(0, $this->countRows('customer_credit_refunds'));
    }

    public function testPostCancel_CancelsAnAdvanceAndARefundWithAReason(): void
    {
        $teller    = $this->teller();
        $advanceId = $this->advanceOf('100.00', $teller);
        $refundId  = (new CreditRefunds(CreditRefunds::CUSTOMER))->refund($this->customerId, '40.00', date('Y-m-d'), 'Cash', null, '', '', $teller);

        $form = $this->get('/accounting_receivables/cancel_form/customer_refund/' . $refundId);

        $form->assertStatus(200);
        $form->assertSee('name="reason"');
        $this->assertFalse($this->bodyOf($this->post('/accounting_receivables/cancel/customer_refund/' . $refundId, ['reason' => ' ']))['success']);

        $blocked = $this->bodyOf($this->post('/accounting_receivables/cancel/customer_advance/' . $advanceId, ['reason' => 'paid twice']));

        $this->assertFalse($blocked['success']);
        $this->assertSame(lang('Accounting.error_credit_refunded'), $blocked['message']);

        $this->assertTrue($this->bodyOf($this->post('/accounting_receivables/cancel/customer_refund/' . $refundId, ['reason' => 'paid twice']))['success']);
        $this->assertTrue($this->bodyOf($this->post('/accounting_receivables/cancel/customer_advance/' . $advanceId, ['reason' => 'paid twice']))['success']);
        $this->assertSame('0.00', $this->balance($this->customerAccount()));
        $this->assertTies();
    }

    public function testGetCancelForm_IsNotFoundForWhatIsNeitherAnAdvanceNorARefundAndForAWrongPermission(): void
    {
        $teller = $this->teller([AccountingGuard::RECEIVABLES_MANAGE]);

        $advanceId = $this->advanceOf('100.00', $teller);

        try {
            $this->get('/accounting_receivables/cancel_form/customer_refund/' . $advanceId);
            $this->fail('A refund form was opened with the number of an advance.');
        } catch (PageNotFoundException) {
            $this->addToAssertionCount(1);
        }

        $this->expectException(PageNotFoundException::class);

        $this->get('/accounting_receivables/cancel_form/purchase/' . $advanceId);
    }

    public function testGetCancelForm_OpensARefundForWhoHoldsOnlyThePermissionToRefundAndNotForWhoMayOnlyTakeAdvances(): void
    {
        $teller = $this->teller();

        $this->advanceOf('100.00', $teller);

        $refundId = (new CreditRefunds(CreditRefunds::CUSTOMER))->refund($this->customerId, '10.00', date('Y-m-d'), 'Cash', null, '', '', $teller);

        $this->teller([AccountingGuard::RECEIVABLES_REFUND]);

        $this->get('/accounting_receivables/cancel_form/customer_refund/' . $refundId)->assertSee('name="reason"');

        $this->teller([AccountingGuard::RECEIVABLES_MANAGE]);

        $this->expectException(PageNotFoundException::class);

        $this->get('/accounting_receivables/cancel_form/customer_refund/' . $refundId);
    }

    public function testGetRefundForm_OffersOnlyTheTreasuriesTheTellerMayWithdrawFrom(): void
    {
        $teller = $this->teller();
        $other  = $this->employeeWithGrants([]);

        (new TreasuryService())->create(['name' => 'Open drawer', 'kind' => 'cash'], $teller);
        (new TreasuryService())->create(['name' => 'Locked drawer', 'kind' => 'cash', 'withdraw_scope' => 'staff', 'withdraw_members' => [$other]], $teller);

        $response = $this->get('/accounting_receivables/refund_form/' . $this->customerId);

        $response->assertSee('Open drawer');
        $response->assertDontSee('Locked drawer');
    }

    public function testGetCustomer_ListsTheAdvancesAndRefundsAndOffersTheButtonsOnlyToWhoMayUseThem(): void
    {
        $teller = $this->teller();

        $advanceId = $this->advanceOf('100.00', $teller);

        (new CreditRefunds(CreditRefunds::CUSTOMER))->refund($this->customerId, '25.00', date('Y-m-d'), 'Cash', null, '', '', $teller);

        $full = $this->get('/accounting_receivables/customer/' . $this->customerId);

        $full->assertStatus(200);
        $full->assertSee('id="receivable_advances"');
        $full->assertSee('id="receivable_refunds"');
        $full->assertSee('id="customer_advance_button"');
        $full->assertSee('id="customer_refund_button"');
        $full->assertSee('cancel_form/customer_advance/' . $advanceId);
        $full->assertSee(lang('Accounting_receivables.source_advance'));

        $this->viewer();

        $plain = $this->get('/accounting_receivables/customer/' . $this->customerId);

        $plain->assertSee('id="receivable_advances"');
        $plain->assertDontSee('id="customer_advance_button"');
        $plain->assertDontSee('id="customer_refund_button"');
        $plain->assertDontSee('cancel_form/customer_advance/');
    }

    public function testGetCustomer_ShowsAnAdvanceByTheSameNumberInTheCreditsAndInTheAdvances(): void
    {
        $teller = $this->teller();

        $this->switchOn(DocumentType::CustomerPayment, 'RC-');
        $this->advanceOf('100.00', $teller);

        $page = $this->get('/accounting_receivables/customer/' . $this->customerId);

        $this->assertSame(2, substr_count($page->getBody(), 'RC-001'));
    }

    public function testGetCustomer_ShowsTheIdOfAnAdvanceReceivedBeforeTheSequenceWasSwitchedOn(): void
    {
        $teller    = $this->teller();
        $advanceId = $this->advanceOf('100.00', $teller);

        $page = $this->get('/accounting_receivables/customer/' . $this->customerId);

        $this->assertSame(2, substr_count($page->getBody(), '#' . $advanceId . '</bdi>') + substr_count($page->getBody(), '>' . $advanceId . '</bdi>'));
    }

    public function testPostAdvance_IsNotFoundWhileAccountingIsOff(): void
    {
        $this->teller();
        $this->setConfigValue('accounting_enabled', '0');

        $this->expectException(PageNotFoundException::class);

        $this->post('/accounting_receivables/advance/' . $this->customerId, ['amount' => '10', 'advance_date' => date('Y-m-d'), 'payment_type' => 'Cash']);
    }

    public function testGetCustomer_ShowsNoCreditLimitAndNoCreditPeriodInTheHeadWhileTheirControlsAreOff(): void
    {
        $this->accountingDb()->table('customers')->where('person_id', $this->customerId)->update(['credit_limit' => '500', 'credit_period_days' => 30]);
        $this->invoiceOf('40.00');
        $this->viewer();

        $page = $this->get('/accounting_receivables/customer/' . $this->customerId);

        $page->assertDontSee('id="statement_credit_limit"');
        $page->assertDontSee('id="statement_credit_period"');

        $this->accountingDb()->table('customers')->update(['credit_limit' => 0, 'credit_period_days' => 0]);
    }

    public function testGetCustomer_ShowsTheCreditLimitAndTheCreditPeriodInTheHeadOfTheStatementWhenTheirControlsAreOn(): void
    {
        $this->accountingDb()->table('customers')->where('person_id', $this->customerId)->update(['credit_limit' => '500', 'credit_period_days' => 30]);
        $this->setConfigValue(SalesControlSettings::CREDIT_LIMIT, '1');
        $this->setConfigValue(SalesControlSettings::CREDIT_PERIOD, '1');
        $this->invoiceOf('40.00');
        $this->viewer();

        $page = $this->get('/accounting_receivables/customer/' . $this->customerId);

        $page->assertSee(lang('Accounting_receivables.credit_limit'));
        $page->assertSee(lang('Accounting_receivables.credit_period'));
        $page->assertSee('500.00', '#statement_credit_limit');
        $page->assertSee(lang('Accounting_receivables.credit_days', [30]), '#statement_credit_period');

        $this->setConfigValue(SalesControlSettings::CREDIT_LIMIT, '0');
        $this->setConfigValue(SalesControlSettings::CREDIT_PERIOD, '0');
        $this->accountingDb()->table('customers')->update(['credit_limit' => 0, 'credit_period_days' => 0]);
    }

    public function testGetCustomer_SaysThereIsNoLimitForACustomerWhoHasNoneWhileTheControlIsOn(): void
    {
        $this->setConfigValue(SalesControlSettings::CREDIT_LIMIT, '1');
        $this->setConfigValue(SalesControlSettings::CREDIT_PERIOD, '1');
        $this->invoiceOf('40.00');
        $this->viewer();

        $page = $this->get('/accounting_receivables/customer/' . $this->customerId);

        $page->assertSee(lang('Accounting_receivables.credit_none'), '#statement_credit_limit');
        $page->assertSee(lang('Accounting_receivables.credit_none'), '#statement_credit_period');

        $this->setConfigValue(SalesControlSettings::CREDIT_LIMIT, '0');
        $this->setConfigValue(SalesControlSettings::CREDIT_PERIOD, '0');
    }

    public function testGetCustomer_ShowsOnlyTheLimitWhenOnlyTheLimitControlIsOn(): void
    {
        $this->setConfigValue(SalesControlSettings::CREDIT_LIMIT, '1');
        $this->invoiceOf('40.00');
        $this->viewer();

        $page = $this->get('/accounting_receivables/customer/' . $this->customerId);

        $page->assertSee('id="statement_credit_limit"');
        $page->assertDontSee('id="statement_credit_period"');

        $this->setConfigValue(SalesControlSettings::CREDIT_LIMIT, '0');
    }

    public function testGetAgedLedgerCsv_GivesTheRowsAndTheTotalsOfTheScreenForTheSameFilters(): void
    {
        $this->invoiceDated('100.00', $this->daysAgo(5));
        $this->invoiceDated('200.00', $this->daysAgo(3));
        $this->invoiceDated('50.00', $this->daysAgo(0));
        $paid = $this->invoiceDated('120.00', $this->daysAgo(1));
        $this->addPayment($paid, '120.00');
        $this->viewer();

        $response = $this->get('/accounting_receivables/aged_ledger/csv?period=1');

        $response->assertStatus(200);
        $this->assertStringContainsString('attachment; filename="aged_ledger_customer_' . date('Y-m-d') . '.csv"', $response->response()->getHeaderLine('Content-Disposition'));

        $rows = $this->csvRows($response);

        $this->assertSame([lang('Accounting_receivables.aged_title')], $rows[0]);
        $this->assertSame(lang('Accounting_receivables.customer'), $rows[2][0]);
        $this->assertContains(lang('Accounting_receivables.bucket_days', ['3 - 3']), $rows[2]);
        $this->assertContains(lang('Accounting_receivables.bucket_days', ['4+']), $rows[2]);
        $this->assertSame('Omar Khaled', $rows[3][0]);

        $totals = end($rows);

        $this->assertSame(lang('Accounting_receivables.totals'), $totals[0]);
        $this->assertSame(['350.0000', '0.0000', '350.0000'], array_slice($totals, -3));

        foreach (['50.0000', '120.0000', '180.0000'] as $bucket) {
            $this->assertContains($bucket, $totals);
        }
    }

    public function testGetAgedLedgerCsv_GivesNoTotalsRowWhenNoCustomerOwesAnything(): void
    {
        $this->viewer();

        $rows = $this->csvRows($this->get('/accounting_receivables/aged_ledger/csv'));

        $this->assertCount(3, $rows);
        $this->assertSame(lang('Accounting_receivables.customer'), $rows[2][0]);
    }

    public function testGetAgedLedgerCsv_ShowsTheNameOfACustomerThatStartsLikeAFormulaAsText(): void
    {
        $this->invoiceOf('100.00', $this->anotherCustomer('=SUM(A1)', 'Name'));
        $this->viewer();

        $names = array_column($this->csvRows($this->get('/accounting_receivables/aged_ledger/csv')), 0);

        $this->assertContains("'=SUM(A1) Name", $names);
        $this->assertNotContains('=SUM(A1) Name', $names);
    }

    public function testGetAgedLedgerCsv_RefusesADayThatIsNotADayInWords(): void
    {
        $this->viewer();

        $response = $this->get('/accounting_receivables/aged_ledger/csv?to=2026-02-30');

        $response->assertStatus(422);
        $this->assertSame(lang('Accounting.error_invalid_date'), $response->response()->getBody());
    }

    public function testGetAgedLedgerCsv_RedirectsAUserWithoutTheModuleGrantIsNotFoundWhileAccountingIsOffAndForSomeoneLimitedToABranch(): void
    {
        $this->withSession(['person_id' => $this->employeeWithGrants(['accounting_chart']), 'menu_group' => 'home']);

        $this->get('/accounting_receivables/aged_ledger/csv')->assertRedirect();

        $this->viewer([1]);

        try {
            $this->get('/accounting_receivables/aged_ledger/csv');

            $this->fail('Someone limited to a branch is not given the figures of the company');
        } catch (PageNotFoundException) {
            $this->assertTrue(true);
        }

        $this->viewer();
        $this->setConfigValue('accounting_enabled', '0');

        $this->expectException(PageNotFoundException::class);

        $this->get('/accounting_receivables/aged_ledger/csv');
    }

    public function testGetBalancesCsv_GivesTheColumnsTheRowsAndTheTotalsOfTheScreen(): void
    {
        $first  = $this->invoiceOf('1000.00');
        $second = $this->invoiceOf('1541.50');

        $this->addPayment($first, '1000.00');
        $this->addPayment($second, '563.50');
        $this->creditNoteOf($second, '253.00');
        $this->viewer();

        $response = $this->get('/accounting_receivables/balances/csv');

        $response->assertStatus(200);

        $rows = $this->csvRows($response);

        $this->assertSame([lang('Accounting_receivables.balances_title')], $rows[0]);
        $this->assertSame([lang('Accounting_receivables.customer'), ...array_map(static fn (string $column): string => lang('Accounting_receivables.' . $column), ['col_before', 'col_sales', 'col_returns', 'col_net', 'col_payments', 'col_settlements', 'balance'])], $rows[2]);
        $this->assertSame(['Omar Khaled', '0.0000', '2541.5000', '0.0000', '2541.5000', '1563.5000', '-253.0000', '725.0000'], $rows[3]);
        $this->assertSame([lang('Accounting_receivables.totals'), '0.0000', '2541.5000', '0.0000', '2541.5000', '1563.5000', '-253.0000', '725.0000'], end($rows));
    }

    public function testGetBalancesCsv_AddsTheColumnForWhatNoOtherColumnExplainsOnlyWhenThereIsSuchAJournal(): void
    {
        $this->invoiceOf('100.00');
        $this->viewer();

        $before = $this->csvRows($this->get('/accounting_receivables/balances/csv'));

        $this->assertNotContains(lang('Accounting_receivables.col_other'), $before[2]);
        $this->assertContains(lang('Accounting_receivables.balance'), $before[2]);

        (new PostingService())->post(new JournalDraft(
            date: date('Y-m-d'),
            lines: [new JournalLineDraft($this->customerAccount(), debit: '25.00'), new JournalLineDraft($this->accountId('sales_revenue'), credit: '25.00')],
            sourceType: JournalDraft::SOURCE_MANUAL,
            employeeId: $this->employeeId,
            currencyCode: 'SAR',
        ));

        $rows = $this->csvRows($this->get('/accounting_receivables/balances/csv'));

        $this->assertContains(lang('Accounting_receivables.col_other'), $rows[2]);
        $this->assertSame('125.0000', end($rows)[count(end($rows)) - 1]);
    }

    public function testGetBalancesCsv_KeepsThePeriodAndTheEmptyChoiceOfTheScreen(): void
    {
        $settled = $this->anotherCustomer('Nora', 'Fahd');
        $paid    = $this->invoiceOf('40.00', $settled);

        $this->addPayment($paid, '40.00');
        $this->invoiceOf('100.00');
        $this->viewer();

        $tomorrow = date('Y-m-d', strtotime('+1 day'));
        $query    = '?from=' . $tomorrow . '&to=' . $tomorrow;

        $hidden = $this->csvRows($this->get('/accounting_receivables/balances/csv' . $query));
        $shown  = $this->csvRows($this->get('/accounting_receivables/balances/csv' . $query . '&empty=1'));

        $this->assertContains('Omar Khaled', array_column($hidden, 0));
        $this->assertNotContains('Nora Fahd', array_column($hidden, 0));
        $this->assertContains('Nora Fahd', array_column($shown, 0));
        $this->assertSame(lang('Accounting_receivables.balances_period', [$tomorrow, $tomorrow]), $hidden[1][0]);
    }

    public function testGetBalancesCsv_RefusesAPeriodThatEndsBeforeItStartsInWords(): void
    {
        $this->viewer();

        $response = $this->get('/accounting_receivables/balances/csv?from=2026-10-31&to=2026-10-01');

        $response->assertStatus(422);
        $this->assertSame(lang('Accounting.error_report_period_invalid'), $response->response()->getBody());
    }

    public function testGetBalancesCsv_RedirectsAUserWithoutTheModuleGrantIsNotFoundWhileAccountingIsOffAndForSomeoneLimitedToABranch(): void
    {
        $this->withSession(['person_id' => $this->employeeWithGrants(['accounting_chart']), 'menu_group' => 'home']);

        $this->get('/accounting_receivables/balances/csv')->assertRedirect();

        $this->viewer([1]);

        try {
            $this->get('/accounting_receivables/balances/csv');

            $this->fail('Someone limited to a branch is not given the figures of the company');
        } catch (PageNotFoundException) {
            $this->assertTrue(true);
        }

        $this->viewer();
        $this->setConfigValue('accounting_enabled', '0');

        $this->expectException(PageNotFoundException::class);

        $this->get('/accounting_receivables/balances/csv');
    }

    public function testGetAgedLedger_LinksTheThreeFilesWithTheFiltersAsRead(): void
    {
        $this->invoiceDated('100.00', $this->daysAgo(5));
        $this->viewer();

        $response = $this->get('/accounting_receivables/aged_ledger?to=2026-10-31&period=15');

        $response->assertSee('id="aged_ledger_csv" href="' . site_url('accounting_receivables/aged_ledger/csv') . '?to=2026-10-31&amp;period=15"');
        $response->assertSee('id="aged_ledger_csv_xlsx" href="' . site_url('accounting_receivables/aged_ledger/xlsx') . '?to=2026-10-31&amp;period=15"');
        $response->assertSee('id="aged_ledger_csv_pdf" href="' . site_url('accounting_receivables/aged_ledger/pdf') . '?to=2026-10-31&amp;period=15"');
    }

    public function testGetBalances_LinksTheThreeFilesWithTheFiltersAsRead(): void
    {
        $this->invoiceOf('100.00');
        $this->viewer();

        $response = $this->get('/accounting_receivables/balances?from=2026-01-01&to=2026-10-31&empty=1');

        $response->assertSee('id="balances_csv" href="' . site_url('accounting_receivables/balances/csv') . '?from=2026-01-01&amp;to=2026-10-31&amp;empty=1"');
        $response->assertSee('id="balances_csv_xlsx" href="' . site_url('accounting_receivables/balances/xlsx') . '?from=2026-01-01&amp;to=2026-10-31&amp;empty=1"');
        $response->assertSee('id="balances_csv_pdf" href="' . site_url('accounting_receivables/balances/pdf') . '?from=2026-01-01&amp;to=2026-10-31&amp;empty=1"');
    }
}
