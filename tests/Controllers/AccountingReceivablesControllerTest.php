<?php

namespace Tests\Controllers;

use App\Libraries\Accounting\AgingBuckets;
use App\Libraries\Accounting\CustomerLedger;
use App\Libraries\Accounting\JournalDraft;
use App\Libraries\Accounting\JournalLineDraft;
use App\Libraries\Accounting\PostingService;
use CodeIgniter\Exceptions\PageNotFoundException;
use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\DatabaseTestTrait;
use CodeIgniter\Test\FeatureTestTrait;
use Config\Database;
use Tests\Support\AccountingFixtureTrait;
use Tests\Support\EmployeeFixtureTrait;
use Tests\Support\ItemFixtureTrait;
use Tests\Support\SaleAccountingFixtureTrait;

/**
 * The screen of customer balances and aging: who may open it, what it lists, and that what it
 * shows is what the books hold.
 */
class AccountingReceivablesControllerTest extends CIUnitTestCase
{
    use DatabaseTestTrait;
    use FeatureTestTrait;
    use AccountingFixtureTrait;
    use EmployeeFixtureTrait;
    use ItemFixtureTrait;
    use SaleAccountingFixtureTrait;

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

        $totals = substr($body, (int) strpos($body, 'id="aged_totals"'));

        foreach (['50.00', '120.00', '180.00', '350.00'] as $figure) {
            $this->assertStringContainsString(to_currency_no_money($figure), $totals);
        }

        $this->assertSame('350.0000', bcadd((new CustomerLedger())->position($this->customerId)->accountBalance, '0', 4));
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
}
