<?php

namespace Tests\Controllers;

use App\Libraries\Accounting\AccountingGuard;
use App\Libraries\Accounting\JournalDraft;
use App\Libraries\Accounting\JournalLineDraft;
use App\Libraries\Accounting\PostingService;
use App\Libraries\Accounting\SupplierAdvances;
use App\Libraries\Accounting\TreasuryService;
use App\Libraries\Numbering\DocumentNumbering;
use App\Libraries\Numbering\DocumentType;
use CodeIgniter\Exceptions\PageNotFoundException;
use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\DatabaseTestTrait;
use CodeIgniter\Test\FeatureTestTrait;
use Config\Database;
use Tests\Support\AccountingFixtureTrait;
use Tests\Support\ChequeFixtureTrait;
use Tests\Support\EmployeeFixtureTrait;
use Tests\Support\ItemFixtureTrait;
use Tests\Support\PurchaseAccountingFixtureTrait;
use Tests\Support\SaleAccountingFixtureTrait;

/**
 * The screen of supplier balances and aging: who may open it, what it lists, and that what it
 * shows is what the books hold.
 */
class AccountingPayablesControllerTest extends CIUnitTestCase
{
    use DatabaseTestTrait;
    use FeatureTestTrait;
    use AccountingFixtureTrait;
    use ChequeFixtureTrait;
    use EmployeeFixtureTrait;
    use ItemFixtureTrait;
    use SaleAccountingFixtureTrait;
    use PurchaseAccountingFixtureTrait;

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

        $this->startBuying();
    }

    protected function tearDown(): void
    {
        $this->stopBuying();

        $db = $this->accountingDb();
        $db->table('document_numbers')->truncate();
        $db->table('document_sequences')->truncate();
        $db->table('accounting_audit_log')->where('entity_type', 'document_numbering')->delete();

        parent::tearDown();
    }

    /**
     * @param list<int>|null $branchIds
     */
    private function viewer(?array $branchIds = null): int
    {
        $personId = $this->employeeWithGrants(['accounting_payables'], $branchIds);

        $this->withSession(['person_id' => $personId, 'menu_group' => 'home']);

        return $personId;
    }

    /**
     * @return array<string, mixed>
     */
    private function rowsOf(string $query = ''): array
    {
        return json_decode($this->get('/accounting_payables/search' . $query)->getJSON(), true);
    }

    public function testGetIndex_ShowsTheTableToWhoeverHoldsTheModule(): void
    {
        $this->viewer();

        $response = $this->get('/accounting_payables');

        $response->assertStatus(200);
        $response->assertSee('accounting_payables');
        $response->assertSee(lang('Accounting_payables.note'));
    }

    public function testGetIndex_RedirectsAUserWithoutTheModuleGrant(): void
    {
        $this->withSession(['person_id' => $this->employeeWithGrants(['accounting_receivables']), 'menu_group' => 'home']);

        $this->get('/accounting_payables')->assertRedirect();
    }

    public function testGetIndex_IsNotFoundWhileAccountingIsOff(): void
    {
        $this->viewer();
        $this->setConfigValue('accounting_enabled', '0');

        $this->expectException(PageNotFoundException::class);

        $this->get('/accounting_payables');
    }

    public function testGetIndex_IsNotFoundForSomeoneLimitedToABranch(): void
    {
        $this->viewer([1]);

        $this->expectException(PageNotFoundException::class);

        $this->get('/accounting_payables');
    }

    public function testGetSearch_ListsTheSupplierWithWhatIsOwedToThemAndHowOldItIs(): void
    {
        $old = $this->purchaseOf('70.00');
        $this->purchaseOf('30.00');
        $this->accountingDb()->table('receivings')->where('receiving_id', $old)->update(['receiving_time' => date('Y-m-d 10:00:00', strtotime('-45 days'))]);
        $this->viewer();

        $result = $this->rowsOf();

        $this->assertSame(1, $result['total']);
        $this->assertCount(1, $result['rows']);

        $row = $result['rows'][0];

        $this->assertSame($this->supplierId, $row['supplier_id']);
        $this->assertSame('Gulf Supplies', $row['supplier_name']);
        $this->assertStringContainsString('100.00', $row['balance']);
        $this->assertStringContainsString('100.00', $row['open']);
        $this->assertStringContainsString('0.00', $row['credit']);
        $this->assertStringContainsString('30.00', $row['age_today']);
        $this->assertStringContainsString('70.00', $row['age_31_60']);
        $this->assertStringContainsString(lang('Accounting_payables.agrees_yes'), $row['agrees']);
        $this->assertStringContainsString('accounting_payables/supplier/' . $this->supplierId, $row['edit']);
    }

    public function testGetSearch_ShowsCreditHeldWithTheSupplierAsANegativeBalance(): void
    {
        $purchase = $this->purchaseOf('100.00', null, lang('Sales.cash'));
        $this->debitNoteOf($purchase, '40.00');
        $this->viewer();

        $row = $this->rowsOf()['rows'][0];

        $this->assertStringContainsString('-40.00', $row['balance']);
        $this->assertStringContainsString('40.00', $row['credit']);
        $this->assertStringContainsString('0.00', $row['open']);
    }

    public function testGetSearch_LeavesOutASupplierWhoseAccountIsEmpty(): void
    {
        $this->anotherSupplier();
        $this->purchaseOf('100.00', null, lang('Sales.cash'));
        $this->viewer();

        $this->assertSame(['total' => 0, 'rows' => []], $this->rowsOf());
    }

    public function testGetSearch_FiltersByNameAndSortsByBalance(): void
    {
        $other = $this->anotherSupplier('Najd Trading');

        $this->purchaseOf('100.00');
        $this->purchaseOf('300.00', $other);
        $this->viewer();

        $this->assertSame(['Najd Trading', 'Gulf Supplies'], array_column($this->rowsOf('?sort=balance&order=desc')['rows'], 'supplier_name'));
        $this->assertSame(['Gulf Supplies', 'Najd Trading'], array_column($this->rowsOf('?sort=balance&order=asc')['rows'], 'supplier_name'));
        $this->assertSame(['Gulf Supplies', 'Najd Trading'], array_column($this->rowsOf('?sort=supplier_name&order=asc')['rows'], 'supplier_name'));
        $this->assertSame(['Najd Trading'], array_column($this->rowsOf('?search=najd')['rows'], 'supplier_name'));
    }

    public function testGetSearch_PagesTheResult(): void
    {
        $other = $this->anotherSupplier('Najd Trading');

        $this->purchaseOf('100.00');
        $this->purchaseOf('300.00', $other);
        $this->viewer();

        $page = $this->rowsOf('?sort=balance&order=desc&limit=1&offset=1');

        $this->assertSame(2, $page['total']);
        $this->assertSame(['Gulf Supplies'], array_column($page['rows'], 'supplier_name'));
    }

    public function testGetSearch_WarnsOfWhatAManualJournalPutOnTheAccountOfTheSupplier(): void
    {
        $this->purchaseOf('100.00');

        (new PostingService())->post(new JournalDraft(
            date: date('Y-m-d'),
            lines: [new JournalLineDraft($this->accountId('cash'), debit: '25.00'), new JournalLineDraft($this->supplierAccount(), credit: '25.00')],
            sourceType: JournalDraft::SOURCE_MANUAL,
            employeeId: $this->employeeId,
            currencyCode: 'SAR',
        ));
        $this->viewer();

        $row = $this->rowsOf()['rows'][0];

        $this->assertStringContainsString('125.00', $row['balance']);
        $this->assertStringContainsString('100.00', $row['open']);
        $this->assertStringContainsString('25.00', $row['agrees']);
        $this->assertStringNotContainsString(lang('Accounting_payables.agrees_yes'), $row['agrees']);
    }

    public function testGetSearch_IsNotFoundForSomeoneLimitedToABranch(): void
    {
        $this->viewer([1]);

        $this->expectException(PageNotFoundException::class);

        $this->get('/accounting_payables/search');
    }

    public function testGetSupplier_ListsThePurchasesTheCreditAndWhatWasAppliedToWhat(): void
    {
        $first = $this->purchaseOf('100.00');
        $this->debitNoteOf($first, '100.00');
        $this->purchaseOf('40.00');
        $this->viewer();

        $response = $this->get('/accounting_payables/supplier/' . $this->supplierId);

        $response->assertStatus(200);
        $response->assertSee('Gulf Supplies');
        $response->assertSee('id="payable_aging"');
        $response->assertSee('id="payable_purchases"');
        $response->assertSee('id="payable_credits"');
        $response->assertSee('id="payable_history"');
        $response->assertSee('RECV ' . $first);
        $response->assertSee(lang('Accounting_payables.source_debit_note'));
        $response->assertSee(lang('Accounting_payables.agrees_yes'));
        $response->assertSee(lang('Sales.invoice_paid'));
        $response->assertSee(lang('Sales.invoice_returned_full'));
    }

    public function testGetSupplier_ShowsAChequeAsCreditOfItsOwnKindWithALinkToTheCheque(): void
    {
        $this->startCheques();

        (new DocumentNumbering())->configure(DocumentType::PaidCheque, ['enabled' => true, 'prefix' => 'PC-', 'padding' => 4], $this->employeeWithGrants(['config']));

        $purchase = $this->purchaseOf('70.00');
        $cheque   = $this->issueCheque('100.00', date('Y-m-d'), date('Y-m-d'));

        $this->viewer();

        $body = $this->get('/accounting_payables/supplier/' . $this->supplierId)->getBody();

        $this->assertStringContainsString(lang('Accounting_cheques.credit_source'), $body);
        $this->assertStringContainsString('accounting_cheques/view/' . $cheque, $body);
        $this->assertStringContainsString('>PC-0001</a>', $body, 'The credit is named by the number of the cheque');
        $this->assertStringContainsString('<bdi dir="ltr">PC-0001</bdi>', $body, 'And so is what it settled');
        $this->assertStringNotContainsString('CHQ ' . $cheque, $body);
        $this->assertStringContainsString('RECV ' . $purchase, $body);
        $this->assertStringNotContainsString('receivings/receipt/' . $cheque . '"', $body);
    }

    public function testGetSupplier_KeepsTheReleasedApplicationsInTheHistory(): void
    {
        $first   = $this->purchaseOf('70.00');
        $second  = $this->purchaseOf('230.00');
        $payment = $this->payPurchase($second, '300.00');

        $this->assertTrue($this->changePayment($second, $payment, ['payment_amount' => '0']));
        $this->viewer();

        $response = $this->get('/accounting_payables/supplier/' . $this->supplierId);

        $response->assertSee('RECV ' . $first);
        $this->assertSame(1, $this->accountingDb()->table('supplier_credit_applications')->where('released_at IS NOT NULL')->countAllResults());
        $response->assertDontSee(lang('Accounting_payables.no_history'));
    }

    public function testGetSupplier_IsNotFoundForASupplierWithoutAnAccount(): void
    {
        $without = $this->insertSupplier('Bare Supplier');
        $this->viewer();

        $this->expectException(PageNotFoundException::class);

        $this->get('/accounting_payables/supplier/' . $without);
    }

    public function testGetSupplier_IsNotFoundForSomeoneLimitedToABranch(): void
    {
        $this->viewer([1]);

        $this->expectException(PageNotFoundException::class);

        $this->get('/accounting_payables/supplier/' . $this->supplierId);
    }

    public function testGetSearch_ShowsWhatIsOverdueAndTheSixBucketsOfTheAging(): void
    {
        $this->purchaseDated('1.00', $this->daysAgo(0));
        $this->purchaseDated('2.00', $this->daysAgo(10), 30);
        $this->purchaseDated('4.00', $this->daysAgo(45), 10);
        $this->purchaseDated('8.00', $this->daysAgo(75));
        $this->purchaseDated('16.00', $this->daysAgo(100), 5);
        $this->purchaseDated('32.00', $this->daysAgo(130));
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

    public function testGetSearch_DoesNotCountAPurchaseDueTodayAsOverdue(): void
    {
        $this->purchaseDated('40.00', $this->daysAgo(10), 10);
        $this->viewer();

        $row = $this->rowsOf()['rows'][0];

        $this->assertStringNotContainsString('40.00', $row['overdue']);
        $this->assertStringContainsString('40.00', $row['age_1_30']);
    }

    public function testGetIndex_OffersTheAgedLedgerAndTheBalanceReport(): void
    {
        $this->viewer();

        $response = $this->get('/accounting_payables');

        $response->assertSee(base_url('accounting_payables/aged_ledger'));
        $response->assertSee(base_url('accounting_payables/balances'));
    }

    public function testGetSupplier_ShowsTheDueDateAndMarksThePurchaseThatIsOverdue(): void
    {
        $late = $this->purchaseDated('40.00', $this->daysAgo(10), 4);
        $this->purchaseDated('25.00', $this->daysAgo(2), 30);
        $this->viewer();

        $response = $this->get('/accounting_payables/supplier/' . $this->supplierId);

        $response->assertSee('RECV ' . $late);
        $response->assertSee(lang('Accounting_payables.due_date'));
        $response->assertSee(to_date(strtotime($this->daysAgo(6))));
        $response->assertSee(to_date(strtotime('+28 days')));
        $response->assertSee('id="statement_overdue"');
        $this->assertSame(1, substr_count($response->getBody(), lang('Accounting_payables.overdue_days', [6])));
        $this->assertSame(1, substr_count($response->getBody(), 'text-bg-danger'));
    }

    public function testGetSupplier_ReadsThePositionOfADayOfThePast(): void
    {
        $first  = $this->purchaseDated('100.00', $this->daysAgo(3));
        $second = $this->purchaseDated('30.00', $this->daysAgo(1));
        $this->viewer();

        $response = $this->get('/accounting_payables/supplier/' . $this->supplierId . '?to=' . $this->daysAgo(2));

        $response->assertStatus(200);
        $response->assertSee('RECV ' . $first);
        $response->assertDontSee('RECV ' . $second);
        $response->assertDontSee(to_currency_no_money('130.00'));
    }

    public function testGetSupplier_SaysWhyADayThatIsNotADayWasNotUsedAndReadsToday(): void
    {
        $this->purchaseDated('100.00', $this->daysAgo(3));
        $this->viewer();

        $response = $this->get('/accounting_payables/supplier/' . $this->supplierId . '?to=2026-02-30');

        $response->assertStatus(200);
        $response->assertSee('id="report_error"');
        $response->assertSee(to_currency_no_money('100.00'));
    }

    public function testGetAgedLedger_AppliesThePaymentToTheOldestPurchaseAndTotalsEveryBucket(): void
    {
        $this->purchaseDated('100.00', $this->daysAgo(5));
        $this->purchaseDated('200.00', $this->daysAgo(3));
        $this->purchaseDated('50.00', $this->daysAgo(0));
        $paid = $this->purchaseDated('120.00', $this->daysAgo(1));
        $this->payPurchase($paid, '120.00');
        $this->viewer();

        $response = $this->get('/accounting_payables/aged_ledger?period=1');
        $body     = $response->getBody();

        $response->assertStatus(200);
        $response->assertSee('id="aged_ledger_table"');
        $response->assertSee('Gulf Supplies');
        $response->assertSee(lang('Accounting_payables.bucket_days', ['3 - 3']));
        $response->assertSee(lang('Accounting_payables.bucket_days', ['4+']));
        $response->assertSee('accounting_payables/supplier/' . $this->supplierId . '?to=' . date('Y-m-d'));

        $totals = substr($body, (int) strpos($body, 'id="aged_totals"'));

        foreach (['50.00', '120.00', '180.00', '350.00'] as $figure) {
            $this->assertStringContainsString(to_currency_no_money($figure), $totals);
        }

        $this->assertSame('350.00', $this->owed());
    }

    public function testGetAgedLedger_EscapesTheNameOfTheSupplier(): void
    {
        $this->purchaseOf('100.00', $this->anotherSupplier('<b>Bold</b> Co'));
        $this->viewer();

        $response = $this->get('/accounting_payables/aged_ledger');

        $response->assertSee('&lt;b&gt;Bold&lt;/b&gt; Co');
        $response->assertDontSee('<b>Bold</b>');
    }

    public function testGetAgedLedger_ReadsTheDayAndTheLengthOfTheBucketsAsTyped(): void
    {
        $this->purchaseDated('100.00', $this->daysAgo(25));
        $this->viewer();

        $response = $this->get('/accounting_payables/aged_ledger?period=10&to=' . $this->daysAgo(10));

        $response->assertSee(lang('Accounting_payables.bucket_days', ['11 - 20']));
        $response->assertSee(lang('Accounting_payables.bucket_days', ['40+']));
        $response->assertSee(lang('Accounting_payables.aged_as_of', [to_date(strtotime($this->daysAgo(10)))]));
        $response->assertSee(to_currency_no_money('100.00'));
    }

    public function testGetAgedLedger_LeavesOutWhatIsDatedAfterTheDay(): void
    {
        $this->purchaseDated('100.00', $this->daysAgo(2));
        $this->viewer();

        $response = $this->get('/accounting_payables/aged_ledger?to=' . $this->daysAgo(5));

        $response->assertSee(lang('Accounting_payables.aged_empty'));
        $response->assertDontSee('id="aged_totals"');
    }

    public function testGetAgedLedger_SaysWhyADayThatIsNotADayWasNotUsed(): void
    {
        $this->purchaseDated('100.00', $this->daysAgo(2));
        $this->viewer();

        $response = $this->get('/accounting_payables/aged_ledger?to=tomorrow');

        $response->assertStatus(200);
        $response->assertSee('id="report_error"');
        $response->assertSee(to_currency_no_money('100.00'));
    }

    public function testGetAgedLedger_RedirectsAUserWithoutTheModuleGrant(): void
    {
        $this->withSession(['person_id' => $this->employeeWithGrants(['accounting_chart']), 'menu_group' => 'home']);

        $this->get('/accounting_payables/aged_ledger')->assertRedirect();
    }

    public function testGetAgedLedger_IsNotFoundWhileAccountingIsOff(): void
    {
        $this->viewer();
        $this->setConfigValue('accounting_enabled', '0');

        $this->expectException(PageNotFoundException::class);

        $this->get('/accounting_payables/aged_ledger');
    }

    public function testGetAgedLedger_IsNotFoundForSomeoneLimitedToABranch(): void
    {
        $this->viewer([1]);

        $this->expectException(PageNotFoundException::class);

        $this->get('/accounting_payables/aged_ledger');
    }

    public function testGetBalances_ShowsTheColumnsOfTheReferenceSystemAndTheyReconcile(): void
    {
        $first  = $this->purchaseOf('1000.00');
        $second = $this->purchaseOf('1500.00');

        $this->payPurchase($first, '1000.00');
        $this->payPurchase($second, '500.00');
        $this->debitNoteOf($second, '300.00');
        $this->viewer();

        $response = $this->get('/accounting_payables/balances');

        $response->assertStatus(200);
        $response->assertSee('id="balances_table"');
        $response->assertSee('id="balances_totals"');

        foreach (['col_before', 'col_sales', 'col_returns', 'col_net', 'col_payments', 'col_settlements', 'balance'] as $column) {
            $response->assertSee(lang('Accounting_payables.' . $column));
        }

        foreach (['2500.00', '1500.00', '-300.00', '700.00'] as $figure) {
            $response->assertSee(to_currency_no_money($figure));
        }

        $response->assertDontSee(lang('Accounting_payables.col_other'));
    }

    public function testGetBalances_EscapesTheNameOfTheSupplier(): void
    {
        $this->purchaseOf('100.00', $this->anotherSupplier('<b>Bold</b> Co'));
        $this->viewer();

        $response = $this->get('/accounting_payables/balances');

        $response->assertSee('&lt;b&gt;Bold&lt;/b&gt; Co');
        $response->assertDontSee('<b>Bold</b>');
    }

    public function testGetBalances_ShowsTheColumnForWhatNoOtherColumnExplainsOnlyWhenThereIsSuchAJournal(): void
    {
        $this->purchaseOf('100.00');

        (new PostingService())->post(new JournalDraft(
            date: date('Y-m-d'),
            lines: [new JournalLineDraft($this->accountId('cash'), debit: '25.00'), new JournalLineDraft($this->supplierAccount(), credit: '25.00')],
            sourceType: JournalDraft::SOURCE_MANUAL,
            employeeId: $this->employeeId,
            currencyCode: 'SAR',
        ));
        $this->viewer();

        $response = $this->get('/accounting_payables/balances');

        $response->assertSee(lang('Accounting_payables.col_other'));
        $response->assertSee(to_currency_no_money('125.00'));
    }

    public function testGetBalances_CarriesWhatPrecedesTheStartIntoTheBalanceBefore(): void
    {
        $this->purchaseDated('100.00', $this->daysAgo(5));
        $this->purchaseDated('30.00', $this->daysAgo(1));
        $this->viewer();

        $response = $this->get('/accounting_payables/balances?from=' . $this->daysAgo(2) . '&to=' . date('Y-m-d'));

        $response->assertSee(lang('Accounting_payables.balances_period', [to_date(strtotime($this->daysAgo(2))), to_date(time())]));
        $response->assertSee(to_currency_no_money('100.00'));
        $response->assertSee(to_currency_no_money('130.00'));
    }

    public function testGetBalances_RefusesAPeriodThatEndsBeforeItStartsAndReadsToday(): void
    {
        $this->purchaseOf('100.00');
        $this->viewer();

        $response = $this->get('/accounting_payables/balances?from=' . date('Y-m-d', strtotime('+3 days')) . '&to=' . $this->daysAgo(3));

        $response->assertStatus(200);
        $response->assertSee('id="report_error"');
        $response->assertSee(to_currency_no_money('100.00'));
    }

    public function testGetBalances_HidesASupplierWithNoMovementAndNoBalanceUnlessAskedToShowIt(): void
    {
        $settled = $this->anotherSupplier('Najd Trading');
        $paid    = $this->purchaseOf('40.00', $settled);

        $this->payPurchase($paid, '40.00');
        $this->purchaseOf('100.00');
        $this->viewer();

        $tomorrow = date('Y-m-d', strtotime('+1 day'));
        $query    = '?from=' . $tomorrow . '&to=' . $tomorrow;

        $hidden = $this->get('/accounting_payables/balances' . $query);

        $hidden->assertSee('Gulf Supplies');
        $hidden->assertDontSee('Najd Trading');
        $this->get('/accounting_payables/balances' . $query . '&empty=1')->assertSee('Najd Trading');
    }

    public function testGetBalances_RedirectsAUserWithoutTheModuleGrant(): void
    {
        $this->withSession(['person_id' => $this->employeeWithGrants(['accounting_chart']), 'menu_group' => 'home']);

        $this->get('/accounting_payables/balances')->assertRedirect();
    }

    public function testGetBalances_IsNotFoundWhileAccountingIsOff(): void
    {
        $this->viewer();
        $this->setConfigValue('accounting_enabled', '0');

        $this->expectException(PageNotFoundException::class);

        $this->get('/accounting_payables/balances');
    }

    public function testGetBalances_IsNotFoundForSomeoneLimitedToABranch(): void
    {
        $this->viewer([1]);

        $this->expectException(PageNotFoundException::class);

        $this->get('/accounting_payables/balances');
    }

    public function testGetRow_GivesTheRowOfOneSupplier(): void
    {
        $this->purchaseOf('100.00');
        $this->viewer();

        $row = json_decode($this->get('/accounting_payables/row/' . $this->supplierId)->getJSON(), true);

        $this->assertSame('Gulf Supplies', $row['supplier_name']);
        $this->assertStringContainsString('100.00', $row['balance']);
    }

    /**
     * @param list<int>|null $branchIds
     */
    private function clerk(?array $branchIds = null): int
    {
        $personId = $this->employeeWithGrants(['accounting_payables', AccountingGuard::PAYABLES_MANAGE, AccountingGuard::TREASURIES_MANAGE], $branchIds);

        $this->withSession(['person_id' => $personId, 'menu_group' => 'home']);

        return $personId;
    }

    /**
     * @return array<string, mixed>
     */
    private function jsonOf($response): array
    {
        return json_decode($response->getJSON(), true);
    }

    public function testGetSupplier_OffersThePaymentAndOpeningButtonsToWhoMayManage(): void
    {
        $this->clerk();

        $response = $this->get('/accounting_payables/supplier/' . $this->supplierId);

        $response->assertStatus(200);
        $response->assertSee('accounting_payables/payment_form/' . $this->supplierId);
        $response->assertSee('accounting_payables/opening_form/' . $this->supplierId);
        $response->assertSee('id="payable_advances"');
    }

    public function testGetSupplier_OffersNoButtonToWhoMayOnlyRead(): void
    {
        $this->viewer();

        $response = $this->get('/accounting_payables/supplier/' . $this->supplierId);

        $response->assertStatus(200);
        $response->assertDontSee('accounting_payables/payment_form/');
        $response->assertDontSee('accounting_payables/opening_form/');
        $response->assertSee('id="payable_advances"');
    }

    public function testGetPaymentForm_ShowsTheFieldsAndOffersTreasuriesOnlyWhenThereIsAChoice(): void
    {
        $manager = $this->clerk();

        $alone = $this->get('/accounting_payables/payment_form/' . $this->supplierId);

        $alone->assertStatus(200);
        $alone->assertSee('name="amount"');
        $alone->assertSee('name="advance_date"');
        $alone->assertSee('name="payment_type"');
        $alone->assertDontSee('name="treasury_id"');

        (new TreasuryService())->create(['name' => 'Advance drawer', 'kind' => 'cash'], $manager);

        $this->get('/accounting_payables/payment_form/' . $this->supplierId)->assertSee('name="treasury_id"');
    }

    public function testGetPaymentForm_OffersOnlyTheTreasuriesTheManagerMayWithdrawFrom(): void
    {
        $manager = $this->clerk();
        $other   = $this->employeeWithGrants([]);

        (new TreasuryService())->create(['name' => 'Open drawer', 'kind' => 'cash'], $manager);
        (new TreasuryService())->create(['name' => 'Locked drawer', 'kind' => 'cash', 'withdraw_scope' => 'staff', 'withdraw_members' => [$other]], $manager);

        $response = $this->get('/accounting_payables/payment_form/' . $this->supplierId);

        $response->assertSee('Open drawer');
        $response->assertDontSee('Locked drawer');
    }

    public function testPostPayment_RefusesATreasuryTheManagerMayNotWithdrawFromAndSaysWhich(): void
    {
        $manager = $this->clerk();
        $other   = $this->employeeWithGrants([]);
        $locked  = (new TreasuryService())->create(['name' => 'Locked drawer', 'kind' => 'cash', 'withdraw_scope' => 'staff', 'withdraw_members' => [$other]], $manager);

        $result = $this->jsonOf($this->post('/accounting_payables/payment/' . $this->supplierId, ['amount' => '50', 'advance_date' => date('Y-m-d'), 'payment_type' => 'Cash', 'treasury_id' => $locked]));

        $this->assertFalse($result['success']);
        $this->assertStringContainsString('Locked drawer', $result['message']);
        $this->assertSame(0, $this->countRows('supplier_advances'));
    }

    public function testGetPaymentForm_IsNotFoundWithoutThePermissionToManage(): void
    {
        $this->viewer();

        $this->expectException(PageNotFoundException::class);

        $this->get('/accounting_payables/payment_form/' . $this->supplierId);
    }

    public function testPostPayment_EntersTheAdvanceAndSpendsItOnTheOpenPurchase(): void
    {
        $purchase = $this->purchaseOf('300.00');
        $this->clerk();

        $result = $this->jsonOf($this->post('/accounting_payables/payment/' . $this->supplierId, [
            'amount'         => '120.00',
            'advance_date'   => date('Y-m-d'),
            'payment_type'   => 'Cash',
            'reference_code' => 'R-1',
            'note'           => 'on account',
        ]));

        $this->assertTrue($result['success']);
        $this->assertSame(lang('Accounting_payables.payment_done'), $result['message']);
        $this->assertSame('180.00', $this->owed());
        $this->assertSame('180.00', bcadd($this->supplierPosition()->purchase($purchase)->open(), '0', 2));
        $this->assertSupplierTies();
    }

    public function testPostPayment_SaysWhyItWasRefused(): void
    {
        $this->clerk();

        $result = $this->jsonOf($this->post('/accounting_payables/payment/' . $this->supplierId, ['amount' => '0', 'advance_date' => date('Y-m-d'), 'payment_type' => 'Cash']));

        $this->assertFalse($result['success']);
        $this->assertSame(lang('Accounting.error_advance_amount_invalid'), $result['message']);
        $this->assertSame(0, $this->countRows('supplier_advances'));
    }

    public function testPostPayment_IsRefusedToWhoHoldsNoPermissionToManage(): void
    {
        $this->viewer();

        $result = $this->jsonOf($this->post('/accounting_payables/payment/' . $this->supplierId, ['amount' => '10', 'advance_date' => date('Y-m-d'), 'payment_type' => 'Cash']));

        $this->assertFalse($result['success']);
        $this->assertSame(lang('Accounting.error_permission_denied'), $result['message']);
        $this->assertSame(0, $this->countRows('supplier_advances'));
    }

    public function testPostOpening_EntersWhatWeOweAsAPurchaseAndWhatTheyOweAsCredit(): void
    {
        $this->clerk();

        $owed = $this->jsonOf($this->post('/accounting_payables/opening/' . $this->supplierId, ['side' => 'we_owe', 'amount' => '500.00', 'advance_date' => '2026-01-01', 'note' => 'old books']));

        $this->assertTrue($owed['success']);
        $this->receivingIds[] = (int) $owed['id'];
        $this->assertSame('500.00', $this->owed());
        $this->assertTrue($this->supplierPosition()->purchase((int) $owed['id'])->opening);

        $owes = $this->jsonOf($this->post('/accounting_payables/opening/' . $this->supplierId, ['side' => 'they_owe', 'amount' => '200.00', 'advance_date' => '2026-01-01']));

        $this->assertTrue($owes['success']);
        $this->assertSame('300.00', $this->owed());
        $this->assertSupplierTies();

        $statement = $this->get('/accounting_payables/supplier/' . $this->supplierId);

        $statement->assertSee(lang('Accounting_payables.opening_balance'));
        $statement->assertSee(lang('Accounting_payables.source_advance_opening'));
    }

    public function testPostOpening_RefusesASideThatIsNeither(): void
    {
        $this->clerk();

        $result = $this->jsonOf($this->post('/accounting_payables/opening/' . $this->supplierId, ['side' => 'none', 'amount' => '5', 'advance_date' => '2026-01-01']));

        $this->assertFalse($result['success']);
        $this->assertSame(lang('Accounting.error_advance_kind_invalid'), $result['message']);
    }

    public function testPostCancel_CancelsAnAdvanceAndAnOpeningBalanceWithAReason(): void
    {
        $manager   = $this->clerk();
        $advances  = new SupplierAdvances();
        $advanceId = $advances->pay($this->supplierId, '70.00', date('Y-m-d'), 'Cash', null, '', '', $manager);
        $opening   = $advances->openingBalance($this->supplierId, SupplierAdvances::WE_OWE, '90.00', '2026-01-01', '', $manager);
        $this->receivingIds[] = $opening;

        $form = $this->get('/accounting_payables/cancel_form/supplier_advance/' . $advanceId);

        $form->assertStatus(200);
        $form->assertSee('name="reason"');

        $withoutReason = $this->jsonOf($this->post('/accounting_payables/cancel/supplier_advance/' . $advanceId, ['reason' => ' ']));

        $this->assertFalse($withoutReason['success']);

        $this->assertTrue($this->jsonOf($this->post('/accounting_payables/cancel/supplier_advance/' . $advanceId, ['reason' => 'paid twice']))['success']);
        $this->assertTrue($this->jsonOf($this->post('/accounting_payables/cancel/opening/' . $opening, ['reason' => 'entered twice']))['success']);
        $this->assertSame('0.00', $this->owed());
        $this->assertSupplierTies();
    }

    public function testGetCancelForm_IsNotFoundForWhatIsNeitherAnAdvanceNorAnOpeningBalance(): void
    {
        $this->clerk();

        $this->expectException(PageNotFoundException::class);

        $this->get('/accounting_payables/cancel_form/purchase/' . $this->purchaseOf('10.00'));
    }

    public function testPostPayment_IsNotFoundForSomeoneLimitedToABranch(): void
    {
        $this->clerk([1]);

        $this->expectException(PageNotFoundException::class);

        $this->post('/accounting_payables/payment/' . $this->supplierId, ['amount' => '10', 'advance_date' => date('Y-m-d'), 'payment_type' => 'Cash']);
    }
    // ---------------------------------------------------------------------------------------
    // The date of a payment without a purchase
    // ---------------------------------------------------------------------------------------

    private function daysAgo(int $days): string
    {
        return date('Y-m-d', strtotime("-{$days} days"));
    }

    public function testGetPaymentForm_LimitsTheDateToTodayAndSaysThePaymentIsPostedOnIt(): void
    {
        $this->clerk();

        $body = $this->get('/accounting_payables/payment_form/' . $this->supplierId)->getBody();

        $this->assertMatchesRegularExpression('/name="advance_date"[^>]*max="' . date('Y-m-d') . '"[^>]*value="' . date('Y-m-d') . '"/', $body);
        $this->assertStringContainsString(lang('Accounting_payables.date_note'), $body);
    }

    public function testPostPayment_PostsOnTheDateEnteredAndRefusesOneAfterToday(): void
    {
        $this->clerk();

        $result = $this->jsonOf($this->post('/accounting_payables/payment/' . $this->supplierId, ['amount' => '120.00', 'advance_date' => $this->daysAgo(4), 'payment_type' => 'Cash']));

        $this->assertTrue($result['success']);
        $this->assertSame($this->daysAgo(4), $this->accountingDb()->table('accounting_journals')->where(['source_type' => 'supplier_advance', 'source_id' => $result['id']])->get()->getRow()->journal_date);

        $tomorrow = date('Y-m-d', strtotime('+1 day'));
        $refused  = $this->jsonOf($this->post('/accounting_payables/payment/' . $this->supplierId, ['amount' => '10.00', 'advance_date' => $tomorrow, 'payment_type' => 'Cash']));

        $this->assertFalse($refused['success']);
        $this->assertSame(lang('Accounting.error_payment_date_in_future', [$tomorrow]), $refused['message']);
        $this->assertSame(1, $this->countRows('supplier_advances'));
    }

    public function testPostPayment_SaysWhichPeriodIsClosedWhenTheDateIsInIt(): void
    {
        $this->clerk();
        $this->closeThrough($this->daysAgo(10));

        $result = $this->jsonOf($this->post('/accounting_payables/payment/' . $this->supplierId, ['amount' => '10.00', 'advance_date' => $this->daysAgo(12), 'payment_type' => 'Cash']));

        $this->assertFalse($result['success']);
        $this->assertStringContainsString($this->daysAgo(12), $result['message']);
        $this->assertStringContainsString($this->daysAgo(10), $result['message']);
        $this->assertSame(0, $this->countRows('supplier_advances'));
    }

    // ---------------------------------------------------------------------------------------
    // The payments to suppliers as documents
    // ---------------------------------------------------------------------------------------

    /**
     * A second branch with a stock location, and a purchase made in it.
     *
     * @return array{branch: int, purchase: int}
     */
    private function purchaseInAnotherBranch(string $code = 'B2'): array
    {
        $branch   = $this->createBranch($code);
        $location = $this->locationOfBranch($branch);
        $item     = $this->item('0.00');
        $purchase = $this->buy([$this->purchaseLine($item, 0, '1', '100.00', '0', '0', PERCENT, $location)]);

        $this->assertGreaterThan(0, $purchase);

        return ['branch' => $branch, 'purchase' => $purchase];
    }

    private function payOnDate(int $purchase, string $amount, string $date, ?string $type = null, ?int $employeeId = null, array $more = []): int
    {
        $this->assertTrue($this->purchases->update($purchase, ['payments' => [
            array_merge(['payment_id' => NEW_ENTRY, 'payment_type' => $type ?? lang('Sales.cash'), 'payment_amount' => $amount, 'employee_id' => $employeeId ?? $this->employeeId, 'payment_status' => 'completed', 'reference_code' => '', 'payment_date' => $date], $more),
        ]]));

        return (int) $this->accountingDb()->table('receivings_payments')->selectMax('payment_id', 'last')->where('receiving_id', $purchase)->get()->getRow()->last;
    }

    /**
     * @return array<string, mixed>
     */
    private function paymentsOf(string $query = ''): array
    {
        return $this->jsonOf($this->get('/accounting_payables/payments/search' . $query));
    }

    /**
     * @param array<string, mixed> $result
     *
     * @return list<string>
     */
    private function keysOfRows(array $result): array
    {
        return array_column($result['rows'], 'doc_key');
    }

    public function testGetPayments_ShowsTheFiltersAndTheTableToWhoeverHoldsTheModule(): void
    {
        $this->viewer();

        $response = $this->get('/accounting_payables/payments');

        $response->assertStatus(200);
        $response->assertSee('accounting_payables/payments');
        $response->assertSee(lang('Accounting_payables.payments_note'));
        $response->assertSee('id="payment_filters"');

        foreach (['f_search', 'f_date_from', 'f_date_to', 'f_kind', 'f_way', 'f_status'] as $filter) {
            $response->assertSee('id="' . $filter . '"');
        }

        $response->assertSee('accounting_payables/debit_notes');
    }

    public function testGetPayments_OffersTheTreasuryAndTheEmployeeFiltersOnlyWhenThereIsSomethingToChooseFrom(): void
    {
        $manager = $this->clerk();

        $empty = $this->get('/accounting_payables/payments')->getBody();

        $this->assertStringNotContainsString('id="f_payer"', $empty);
        $this->assertStringNotContainsString('id="f_treasury"', $empty);

        (new TreasuryService())->create(['name' => 'Filter drawer', 'kind' => 'cash'], $manager);

        $purchase = $this->purchaseOf('100.00');
        $this->payOnDate($purchase, '10.00', date('Y-m-d'));

        $body = $this->get('/accounting_payables/payments')->getBody();

        $this->assertStringContainsString('id="f_payer"', $body);
        $this->assertStringContainsString('id="f_treasury"', $body);
        $this->assertStringContainsString('Filter drawer', $body);
    }

    public function testGetPayments_RedirectsAUserWithoutTheModuleGrant(): void
    {
        $this->withSession(['person_id' => $this->employeeWithGrants(['accounting_receivables']), 'menu_group' => 'home']);

        $this->get('/accounting_payables/payments')->assertRedirect();
        $this->get('/accounting_payables/payments/search')->assertRedirect();
    }

    public function testGetPayments_IsNotFoundWhileAccountingIsOff(): void
    {
        $this->viewer();
        $this->setConfigValue('accounting_enabled', '0');

        $this->expectException(PageNotFoundException::class);

        $this->get('/accounting_payables/payments');
    }

    public function testGetPaymentsSearch_IsNotFoundWhileAccountingIsOff(): void
    {
        $this->viewer();
        $this->setConfigValue('accounting_enabled', '0');

        $this->expectException(PageNotFoundException::class);

        $this->get('/accounting_payables/payments/search');
    }

    public function testGetPayments_OpensForSomeoneLimitedToABranchWhileTheBalancesStayClosedToThem(): void
    {
        $this->viewer([1]);

        $payments = $this->get('/accounting_payables/payments');
        $notes    = $this->get('/accounting_payables/debit_notes');

        $payments->assertStatus(200);
        $notes->assertStatus(200);
        $wayBack = 'href="' . base_url('accounting_payables') . '"><span class="bi bi-list-ul"';

        $this->assertStringNotContainsString($wayBack, $payments->getBody(), 'No way back to a screen that is closed to them');
        $this->assertStringNotContainsString($wayBack, $notes->getBody());

        $this->expectException(PageNotFoundException::class);

        $this->get('/accounting_payables');
    }

    public function testGetPayments_SaysTheFiguresOfSomeoneWhoWorksInSomeBranchesOnlyArePartial(): void
    {
        $second = $this->createBranch('B2');
        $this->createBranch('B3');
        $this->viewer([1, $second]);

        $body = $this->get('/accounting_payables/payments')->getBody();

        $this->assertStringContainsString('id="scope_label"', $body);
        $this->assertStringContainsString('B2', $body);
        $this->assertStringNotContainsString('B3 -', $body, 'A branch the employee does not work in is not offered');
    }

    public function testGetPayments_SaysNothingOfBranchesToSomeoneWhoWorksInOne(): void
    {
        $this->createBranch('B2');
        $this->viewer([1]);

        $response = $this->get('/accounting_payables/payments');

        $response->assertDontSee('id="scope_label"');
        $response->assertDontSee('id="f_branches"');
    }

    public function testGetPayments_ShowsTheBranchColumnOnlyToSomeoneWhoWorksInMoreThanOne(): void
    {
        $this->viewer();

        $this->assertStringNotContainsString('"field":"branch_code"', $this->get('/accounting_payables/payments')->getBody());

        $this->createBranch('B2');

        $this->assertStringContainsString('"field":"branch_code"', $this->get('/accounting_payables/payments')->getBody());
    }

    public function testGetPaymentsSearch_ListsEachPaymentWithItsNumberDateSupplierPurchaseAmountWayTreasuryPayerAndStatus(): void
    {
        (new DocumentNumbering())->configure(DocumentType::SupplierPayment, ['enabled' => true, 'prefix' => 'SP-', 'padding' => 3]);
        (new DocumentNumbering())->configure(DocumentType::Purchase, ['enabled' => true, 'prefix' => 'PO-', 'padding' => 4]);

        $transfer = lang('Sales.bank_transfer');
        $paid     = lang('Sales.payment_status_completed');
        $manager  = $this->clerk();
        $drawer   = (new TreasuryService())->create(['name' => 'Payment drawer', 'kind' => 'cash'], $manager);
        $buyer    = $this->createEmployee(first_name: 'Huda', last_name: 'Saleh');
        $purchase = $this->purchaseOf('300.00');
        $payment  = $this->payOnDate($purchase, '120.00', $this->daysAgo(3), $transfer, $buyer, ['treasury_id' => $drawer, 'reference_code' => 'TR-9']);
        $advance  = (new SupplierAdvances())->pay($this->supplierId, '70.00', $this->daysAgo(1), lang('Sales.cash'), null, 'ADV-2', '', $manager);

        $result = $this->paymentsOf();

        $this->assertSame(2, $result['total']);
        $this->assertSame(['advance:' . $advance, 'purchase:' . $payment], $this->keysOfRows($result));

        [$onAdvance, $onPurchase] = $result['rows'];

        $this->assertStringContainsString('SP-002', $onAdvance['number']);
        $this->assertSame($this->formatDay($this->daysAgo(1)), $onAdvance['date']);
        $this->assertSame('', $onAdvance['purchase']);
        $this->assertSame(lang('Accounting_payables.kind_advance'), $onAdvance['kind']);
        $this->assertSame($this->cell('70.00'), $onAdvance['amount']);
        $this->assertSame('ADV-2', $onAdvance['reference']);

        $this->assertStringContainsString('SP-001', $onPurchase['number']);
        $this->assertSame($this->formatDay($this->daysAgo(3)), $onPurchase['date']);
        $this->assertSame('Gulf Supplies', $onPurchase['supplier_name']);
        $this->assertStringContainsString('PO-0001', $onPurchase['purchase']);
        $this->assertSame(lang('Accounting_payables.kind_purchase'), $onPurchase['kind']);
        $this->assertSame($this->cell('120.00'), $onPurchase['amount']);
        $this->assertSame($transfer, $onPurchase['way']);
        $this->assertSame('Payment drawer', $onPurchase['treasury']);
        $this->assertSame('Huda Saleh', $onPurchase['paid_by']);
        $this->assertSame('TR-9', $onPurchase['reference']);
        $this->assertStringContainsString($paid, $onPurchase['status']);
        $this->assertSame('HQ', $onPurchase['branch_code']);
    }

    private function cell(string $amount): string
    {
        return '<bdi dir="ltr">' . $amount . '</bdi>';
    }

    private function formatDay(string $day): string
    {
        return to_date(strtotime($day));
    }

    public function testGetPaymentsSearch_ShowsTheIdOfAPaymentSavedBeforeThereWereNumbersAsItsNumber(): void
    {
        $purchase = $this->purchaseOf('300.00');
        $payment  = $this->payOnDate($purchase, '120.00', date('Y-m-d'));
        $this->viewer();

        $row = $this->paymentsOf()['rows'][0];

        $this->assertStringContainsString('>' . $payment . '<', $row['number']);
        $this->assertStringContainsString('RECV ' . $purchase, $row['purchase']);
    }

    public function testGetPaymentsSearch_ShowsMoneyTheSupplierGaveBackAsNegative(): void
    {
        $purchase = $this->purchaseOf('300.00');
        $this->payOnDate($purchase, '300.00', $this->daysAgo(3));
        $refund = $this->payOnDate($purchase, '-80.00', $this->daysAgo(1));
        $this->viewer();

        $rows = $this->paymentsOf('?kind=refund')['rows'];

        $this->assertSame(['purchase:' . $refund], array_column($rows, 'doc_key'));
        $this->assertSame($this->cell('-80.00'), $rows[0]['amount']);
        $this->assertSame(lang('Accounting_payables.kind_refund'), $rows[0]['kind']);
    }

    public function testGetPaymentsSearch_ShowsTheDayAndTimeAPaymentWithNoDateWasSaved(): void
    {
        $purchase = $this->purchaseOf('300.00');
        $legacy   = $this->payOnDate($purchase, '100.00', $this->daysAgo(6));
        $entered  = $this->payOnDate($purchase, '50.00', $this->daysAgo(2));

        $this->accountingDb()->table('receivings_payments')->where('payment_id', $legacy)->update(['payment_date' => null, 'payment_time' => $this->daysAgo(6) . ' 09:30:00']);
        $this->viewer();

        $byKey = array_column($this->paymentsOf()['rows'], null, 'doc_key');

        $this->assertSame(to_datetime(strtotime($this->daysAgo(6) . ' 09:30:00')), $byKey['purchase:' . $legacy]['date'], 'It shows the time it was saved');
        $this->assertSame($this->formatDay($this->daysAgo(2)), $byKey['purchase:' . $entered]['date'], 'One with a date shows the date');
    }

    public function testGetPaymentsSearch_ShowsACancelledPaymentWithoutAPurchaseAndAPaymentThatIsNotCompleted(): void
    {
        $manager = $this->clerk();
        $advance = (new SupplierAdvances())->pay($this->supplierId, '70.00', date('Y-m-d'), lang('Sales.cash'), null, '', '', $manager);
        (new SupplierAdvances())->cancel($advance, 'twice', $manager);
        $purchase = $this->purchaseOf('300.00');
        $draft    = $this->payPurchase($purchase, '10.00', null, 'draft');

        $byKey = array_column($this->paymentsOf()['rows'], null, 'doc_key');

        $this->assertStringContainsString(lang('Accounting_payables.status_cancelled'), $byKey['advance:' . $advance]['status']);
        $this->assertStringContainsString(lang('Sales.payment_status_draft'), $byKey['purchase:' . $draft]['status']);
        $this->assertSame(['advance:' . $advance], $this->keysOfRows($this->paymentsOf('?status=cancelled')));
    }

    public function testGetPaymentsSearch_CutsTheRowsDownToTheBranchesTheEmployeeWorksIn(): void
    {
        ['branch' => $branch, 'purchase' => $other] = $this->purchaseInAnotherBranch();

        $own          = $this->purchaseOf('100.00');
        $ownPayment   = $this->payOnDate($own, '10.00', $this->daysAgo(2));
        $otherPayment = $this->payOnDate($other, '20.00', $this->daysAgo(1));

        $this->viewer([1]);

        $this->assertSame(['purchase:' . $ownPayment], $this->keysOfRows($this->paymentsOf()));
        $this->assertSame([], $this->keysOfRows($this->paymentsOf('?branches[]=' . $branch)), 'Naming only a branch of someone else reads nothing and not everything');
        $this->assertSame(['purchase:' . $ownPayment], $this->keysOfRows($this->paymentsOf('?branches[]=1&branches[]=' . $branch)));
        $this->assertSame(1, $this->paymentsOf()['total']);

        $this->viewer([$branch]);

        $this->assertSame(['purchase:' . $otherPayment], $this->keysOfRows($this->paymentsOf()));

        $this->viewer([1, $branch]);

        $this->assertSame(['purchase:' . $otherPayment, 'purchase:' . $ownPayment], $this->keysOfRows($this->paymentsOf()));
    }

    public function testGetPaymentsSearch_LetsSomeoneWhoWorksInEveryBranchChooseBranches(): void
    {
        ['branch' => $branch, 'purchase' => $other] = $this->purchaseInAnotherBranch();

        $own          = $this->purchaseOf('100.00');
        $ownPayment   = $this->payOnDate($own, '10.00', $this->daysAgo(2));
        $otherPayment = $this->payOnDate($other, '20.00', $this->daysAgo(1));

        $this->viewer();

        $this->assertSame(['purchase:' . $otherPayment, 'purchase:' . $ownPayment], $this->keysOfRows($this->paymentsOf()));
        $this->assertSame(['purchase:' . $otherPayment], $this->keysOfRows($this->paymentsOf('?branches[]=' . $branch)));
        $this->assertSame(['purchase:' . $ownPayment], $this->keysOfRows($this->paymentsOf('?branches[]=1')));
        $this->assertSame([], $this->keysOfRows($this->paymentsOf('?branches[]=999999')), 'A branch that is not there reads nothing and not everything');
    }

    public function testGetPaymentsSearch_ReadsNothingForSomeoneWhoWorksInNoBranch(): void
    {
        $purchase = $this->purchaseOf('100.00');
        $this->payOnDate($purchase, '10.00', date('Y-m-d'));
        $this->viewer([]);

        $this->assertSame(['total' => 0, 'rows' => []], $this->paymentsOf());
    }

    public function testGetPaymentsSearch_FiltersByWhatTheFormSendsAndTakesAnArrayAsNothing(): void
    {
        $label    = lang('Sales.bank_transfer');
        $other    = $this->anotherSupplier('Najd Trading');
        $purchase = $this->purchaseOf('500.00');
        $second   = $this->purchaseOf('500.00', $other);
        $cash     = $this->payOnDate($purchase, '10.00', $this->daysAgo(10));
        $transfer = $this->payOnDate($purchase, '20.00', $this->daysAgo(5), $label);
        $theirs   = $this->payOnDate($second, '30.00', $this->daysAgo(3));
        $this->viewer();

        $this->assertSame(['purchase:' . $transfer], $this->keysOfRows($this->paymentsOf('?payment_type=' . rawurlencode($label))));
        $this->assertSame(['purchase:' . $theirs], $this->keysOfRows($this->paymentsOf('?supplier_id=' . $other)));
        $this->assertSame(['purchase:' . $theirs, 'purchase:' . $transfer], $this->keysOfRows($this->paymentsOf('?date_from=' . $this->daysAgo(5))));
        $this->assertSame(['purchase:' . $cash], $this->keysOfRows($this->paymentsOf('?date_to=' . $this->daysAgo(6))));
        $this->assertSame(['purchase:' . $theirs], $this->keysOfRows($this->paymentsOf('?search=najd')));
        $this->assertSame(3, $this->paymentsOf('?supplier_id[]=1&search[]=x&status[]=draft')['total'], 'A filter that is not text is left out');
    }

    public function testGetPaymentsSearch_PagesAndSortsTheResultAndKeepsAtLeastOneRowToAPage(): void
    {
        $purchase = $this->purchaseOf('500.00');
        $a        = $this->payOnDate($purchase, '30.00', $this->daysAgo(5));
        $b        = $this->payOnDate($purchase, '10.00', $this->daysAgo(3));
        $c        = $this->payOnDate($purchase, '20.00', $this->daysAgo(1));
        $this->viewer();

        $page = $this->paymentsOf('?sort=date&order=desc&limit=1&offset=1');

        $this->assertSame(3, $page['total']);
        $this->assertSame(['purchase:' . $b], $this->keysOfRows($page));
        $this->assertSame(['purchase:' . $b, 'purchase:' . $c, 'purchase:' . $a], $this->keysOfRows($this->paymentsOf('?sort=amount&order=asc')));
        $this->assertCount(1, $this->paymentsOf('?limit=-5')['rows']);
        $this->assertSame(['purchase:' . $c, 'purchase:' . $b, 'purchase:' . $a], $this->keysOfRows($this->paymentsOf('?sort=1%3BDROP&order=up')), 'What is not a column sorts by date');
    }

    public function testGetPaymentsSearch_NeverReturnsMoreThanTheLargestPageWhateverLimitIsAsked(): void
    {
        $purchase = $this->purchaseOf('1000.00');
        $rows     = [];

        for ($i = 0; $i < 501; ++$i) {
            $rows[] = ['receiving_id' => $purchase, 'payment_type' => lang('Sales.cash'), 'payment_amount' => '1.00', 'payment_date' => date('Y-m-d'), 'employee_id' => $this->employeeId, 'payment_status' => 'completed'];
        }

        $this->accountingDb()->table('receivings_payments')->insertBatch($rows);
        $this->viewer();

        $result = $this->paymentsOf('?limit=100000');

        $this->assertSame(501, $result['total']);
        $this->assertCount(500, $result['rows']);
        $this->assertCount(25, $this->paymentsOf('?limit=0')['rows'], 'Without a limit a page is the usual size');
    }

    public function testGetPaymentsSearch_LinksAPaymentOnAPurchaseToItsFormForWhoMayOpenIt(): void
    {
        $purchase = $this->purchaseOf('300.00');
        $this->payOnDate($purchase, '120.00', date('Y-m-d'));
        $manager = $this->clerk();

        $this->assertSame('', $this->paymentsOf()['rows'][0]['edit'], 'Without the receivings module there is nothing to open');

        $this->accountingDb()->table('grants')->insert(['person_id' => $manager, 'permission_id' => 'receivings', 'menu_group' => 'home']);

        $this->assertStringContainsString('receivings/edit/' . $purchase, $this->paymentsOf()['rows'][0]['edit']);
    }

    public function testGetPaymentsSearch_LinksAPaymentWithoutAPurchaseToTheStatementOnlyForSomeoneWhoSeesBalances(): void
    {
        $manager = $this->clerk();
        (new SupplierAdvances())->pay($this->supplierId, '70.00', date('Y-m-d'), lang('Sales.cash'), null, '', '', $manager);

        $this->assertStringContainsString('accounting_payables/supplier/' . $this->supplierId, $this->paymentsOf()['rows'][0]['edit']);

        $this->clerk([1]);

        $this->assertSame('', $this->paymentsOf()['rows'][0]['edit'], 'The statement is closed to someone limited to a branch');
    }

    public function testGetPayments_OffersTheSupplierFilterFromTheStatementAndTheLinksBack(): void
    {
        $this->viewer();

        $this->assertStringContainsString('id="f_supplier" value="' . $this->supplierId . '"', $this->get('/accounting_payables/payments?supplier_id=' . $this->supplierId)->getBody());
        $this->assertStringContainsString('id="f_supplier" value=""', $this->get('/accounting_payables/payments?supplier_id=0')->getBody());
        $this->assertStringContainsString('href="' . base_url('accounting_payables') . '"><span class="bi bi-list-ul"', $this->get('/accounting_payables/payments')->getBody());
        $this->assertStringContainsString('href="' . base_url('accounting_payables') . '"><span class="bi bi-list-ul"', $this->get('/accounting_payables/debit_notes')->getBody());
    }

    public function testGetSupplier_LinksToThePaymentsAndTheDebitNotesOfTheSupplier(): void
    {
        $this->viewer();

        $body = $this->get('/accounting_payables/supplier/' . $this->supplierId)->getBody();

        $this->assertStringContainsString('accounting_payables/payments?supplier_id=' . $this->supplierId, $body);
        $this->assertStringContainsString('accounting_payables/debit_notes?supplier_id=' . $this->supplierId, $body);
    }

    public function testGetIndex_LinksToThePaymentsAndTheDebitNotes(): void
    {
        $this->viewer();

        $body = $this->get('/accounting_payables')->getBody();

        $this->assertStringContainsString('accounting_payables/payments', $body);
        $this->assertStringContainsString('accounting_payables/debit_notes', $body);
    }

    // ---------------------------------------------------------------------------------------
    // The debit notes
    // ---------------------------------------------------------------------------------------

    /**
     * @return array<string, mixed>
     */
    private function debitNotesOf(string $query = ''): array
    {
        return $this->jsonOf($this->get('/accounting_payables/debit_notes/search' . $query));
    }

    public function testGetDebitNotes_ShowsTheFiltersAndTheTableToWhoeverHoldsTheModule(): void
    {
        $this->viewer();

        $response = $this->get('/accounting_payables/debit_notes');

        $response->assertStatus(200);
        $response->assertSee('accounting_payables/debit_notes');
        $response->assertSee(lang('Accounting_payables.debit_notes_note'));
        $response->assertSee('id="debit_note_filters"');
        $response->assertSee('accounting_payables/payments');
    }

    public function testGetDebitNotes_IsRefusedToWhoHoldsNoGrantAndWhileAccountingIsOff(): void
    {
        $this->withSession(['person_id' => $this->employeeWithGrants(['accounting_receivables']), 'menu_group' => 'home']);

        $this->get('/accounting_payables/debit_notes')->assertRedirect();
        $this->get('/accounting_payables/debit_notes/search')->assertRedirect();

        $this->viewer();
        $this->setConfigValue('accounting_enabled', '0');

        $this->expectException(PageNotFoundException::class);

        $this->get('/accounting_payables/debit_notes/search');
    }

    public function testGetDebitNotesSearch_ListsEachDebitNoteWithItsNumberPurchaseSupplierAmountAndWhatWasAppliedAgainstTheBalance(): void
    {
        (new DocumentNumbering())->configure(DocumentType::DebitNote, ['enabled' => true, 'prefix' => 'DN-', 'padding' => 3]);

        $purchase = $this->purchaseOf('100.00');
        $note     = $this->debitNoteOf($purchase, '40.00');
        $this->viewer();

        $result = $this->debitNotesOf();

        $this->assertSame(1, $result['total']);

        $row = $result['rows'][0];

        $this->assertSame($note, $row['receiving_id']);
        $this->assertStringContainsString('DN-001', $row['number']);
        $this->assertStringContainsString('RECV ' . $purchase, $row['origin']);
        $this->assertSame('Gulf Supplies', $row['supplier_name']);
        $this->assertSame([$this->cell('40.00'), $this->cell('0.00'), $this->cell('40.00'), $this->cell('0.00')], [$row['amount'], $row['refunded'], $row['applied'], $row['held']]);
        $this->assertStringContainsString(lang('Accounting_payables.state_applied'), $row['state']);
        $this->assertSame($this->formatDay(date('Y-m-d')), $row['date']);
        $this->assertNotSame('', $row['employee']);
        $this->assertSame('HQ', $row['branch_code']);
    }

    public function testGetDebitNotesSearch_ShowsAReturnSavedBeforeThereWereNumbersByItsReceivingNumberAndTheCreditStillHeld(): void
    {
        $purchase = $this->purchaseOf('100.00', null, lang('Sales.cash'));
        $note     = $this->debitNoteOf($purchase, '40.00');
        $this->viewer();

        $row = $this->debitNotesOf()['rows'][0];

        $this->assertStringContainsString('RECV ' . $note, $row['number']);
        $this->assertSame([$this->cell('40.00'), $this->cell('0.00'), $this->cell('0.00'), $this->cell('40.00')], [$row['amount'], $row['refunded'], $row['applied'], $row['held']]);
        $this->assertStringContainsString(lang('Accounting_payables.state_held'), $row['state']);
    }

    public function testGetDebitNotesSearch_CutsTheRowsDownToTheBranchesTheEmployeeWorksIn(): void
    {
        ['branch' => $branch, 'purchase' => $other] = $this->purchaseInAnotherBranch();

        $line      = $this->accountingDb()->table('receivings_items')->where('receiving_id', $other)->get()->getRow();
        $otherNote = $this->buy([$this->purchaseLine((int) $line->item_id, 0, '-0.2', '100.00', '0', '0', PERCENT, (int) $line->item_location)], -1, null, $other);
        $own       = $this->purchaseOf('100.00');
        $ownNote   = $this->debitNoteOf($own, '10.00');

        $this->assertGreaterThan(0, $otherNote);

        $this->viewer([1]);

        $this->assertSame([$ownNote], array_column($this->debitNotesOf()['rows'], 'receiving_id'));
        $this->assertSame([], array_column($this->debitNotesOf('?branches[]=' . $branch)['rows'], 'receiving_id'), 'Naming only a branch of someone else reads nothing and not everything');
        $this->assertSame([$ownNote], array_column($this->debitNotesOf('?branches[]=1&branches[]=' . $branch)['rows'], 'receiving_id'));

        $this->viewer([$branch]);

        $this->assertSame([$otherNote], array_column($this->debitNotesOf()['rows'], 'receiving_id'));

        $this->viewer();

        $this->assertSame([$ownNote, $otherNote], array_column($this->debitNotesOf()['rows'], 'receiving_id'));
        $this->assertSame([$otherNote], array_column($this->debitNotesOf('?branches[]=' . $branch)['rows'], 'receiving_id'));

        $this->viewer([]);

        $this->assertSame(['total' => 0, 'rows' => []], $this->debitNotesOf());
    }

    public function testGetDebitNotesSearch_FiltersByWhatTheFormSendsAndPages(): void
    {
        $other    = $this->anotherSupplier('Najd Trading');
        $purchase = $this->purchaseOf('100.00');
        $second   = $this->purchaseOf('100.00', $other);
        $first    = $this->debitNoteOf($purchase, '10.00');
        $theirs   = $this->debitNoteOf($second, '20.00', $other);

        $this->accountingDb()->table('receivings')->where('receiving_id', $first)->update(['receiving_time' => $this->daysAgo(8) . ' 10:00:00']);
        $this->viewer();

        $this->assertSame([$theirs], array_column($this->debitNotesOf('?supplier_id=' . $other)['rows'], 'receiving_id'));
        $this->assertSame([$theirs], array_column($this->debitNotesOf('?date_from=' . $this->daysAgo(5))['rows'], 'receiving_id'));
        $this->assertSame([$first], array_column($this->debitNotesOf('?date_to=' . $this->daysAgo(5))['rows'], 'receiving_id'));
        $this->assertSame([$theirs], array_column($this->debitNotesOf('?search=najd')['rows'], 'receiving_id'));
        $this->assertSame([$first], array_column($this->debitNotesOf('?search=' . rawurlencode('RECV ' . $first))['rows'], 'receiving_id'));
        $this->assertSame(2, $this->debitNotesOf('?supplier_id[]=1&search[]=x')['total'], 'A filter that is not text is left out');

        $page = $this->debitNotesOf('?sort=date&order=desc&limit=1&offset=1');

        $this->assertSame(2, $page['total']);
        $this->assertSame([$first], array_column($page['rows'], 'receiving_id'));
        $this->assertCount(1, $this->debitNotesOf('?limit=-5')['rows']);
    }

    public function testGetDebitNotesSearch_LinksADebitNoteToItsFormForWhoMayOpenIt(): void
    {
        $purchase = $this->purchaseOf('100.00');
        $note     = $this->debitNoteOf($purchase, '10.00');
        $manager  = $this->clerk();

        $this->assertSame('', $this->debitNotesOf()['rows'][0]['edit']);

        $this->accountingDb()->table('grants')->insert(['person_id' => $manager, 'permission_id' => 'receivings', 'menu_group' => 'home']);

        $this->assertStringContainsString('receivings/edit/' . $note, $this->debitNotesOf()['rows'][0]['edit']);
    }

    /**
     * @return array{int, int, string}
     */
    private function booksOfTheSupplier(): array
    {
        return [$this->countRows('accounting_journals'), $this->countRows('supplier_credit_applications'), $this->owed()];
    }

    public function testGetPaymentsSearch_ChangesNothingInTheBooksAndLeavesEverySupplierTie(): void
    {
        $purchase = $this->purchaseOf('300.00');
        $this->payOnDate($purchase, '100.00', $this->daysAgo(2));
        $this->debitNoteOf($purchase, '50.00');
        $this->viewer();

        $before = $this->booksOfTheSupplier();

        $this->paymentsOf();
        $this->get('/accounting_payables/payments');

        $this->assertSame($before, $this->booksOfTheSupplier(), 'Reading changes nothing');
        $this->assertSupplierTies();
    }

    public function testGetDebitNotesSearch_ChangesNothingInTheBooksAndLeavesEverySupplierTie(): void
    {
        $purchase = $this->purchaseOf('300.00');
        $this->payOnDate($purchase, '100.00', $this->daysAgo(2));
        $this->debitNoteOf($purchase, '50.00');
        $this->viewer();

        $before = $this->booksOfTheSupplier();

        $this->debitNotesOf();
        $this->get('/accounting_payables/debit_notes');

        $this->assertSame($before, $this->booksOfTheSupplier(), 'Reading changes nothing');
        $this->assertSupplierTies();
    }

    public function testGetDebitNotesSearch_ShowsWhatAReturnComesToAndLeavesTheCreditCellsBlankWhenTheAccountOfTheSupplierDoesNotHoldIt(): void
    {
        $purchase = $this->purchaseOf('100.00', null, lang('Sales.cash'));

        $this->setConfigValue('accounting_enabled', '0');

        $note = $this->debitNoteOf($purchase, '40.00');

        $this->setConfigValue('accounting_enabled', '1');
        $this->viewer();

        $row = $this->debitNotesOf()['rows'][0];

        $this->assertSame($note, $row['receiving_id']);
        $this->assertSame([$this->cell('40.00'), '', '', ''], [$row['amount'], $row['refunded'], $row['applied'], $row['held']]);
        $this->assertStringContainsString(esc(lang('Accounting_payables.state_not_in_account')), $row['state']);
        $this->assertStringContainsString('text-bg-secondary', $row['state'], 'It is not something to chase');
        $this->assertStringNotContainsString('text-bg-warning', $row['state']);
    }

    public function testGetPayments_OffersTheTreasuriesAndThePayersOfTheBranchesTheReaderWorksInOnly(): void
    {
        $manager = $this->clerk();
        $buyer   = $this->createEmployee(first_name: 'Huda', last_name: 'Ownbranch');
        $other   = $this->createEmployee(first_name: 'Zaid', last_name: 'Otherbranch');

        ['branch' => $branch, 'purchase' => $theirs] = $this->purchaseInAnotherBranch();

        (new TreasuryService())->create(['name' => 'Branch one drawer', 'kind' => 'cash'], $manager);
        (new TreasuryService())->create(['name' => 'Branch two drawer', 'kind' => 'cash', 'branch_id' => $branch], $manager);

        $own = $this->purchaseOf('100.00');

        $this->payOnDate($own, '10.00', date('Y-m-d'), null, $buyer);
        $this->payOnDate($theirs, '10.00', date('Y-m-d'), null, $other);

        $this->viewer([1]);

        $limited = $this->get('/accounting_payables/payments')->getBody();

        $this->assertStringContainsString('Branch one drawer', $limited);
        $this->assertStringContainsString('Huda Ownbranch', $limited);
        $this->assertStringNotContainsString('Branch two drawer', $limited, 'The treasuries of a branch the reader does not work in are not offered');
        $this->assertStringNotContainsString('Zaid Otherbranch', $limited, 'Neither are the employees who paid in it');

        $this->viewer();

        $everyone = $this->get('/accounting_payables/payments')->getBody();

        foreach (['Branch one drawer', 'Branch two drawer', 'Huda Ownbranch', 'Zaid Otherbranch'] as $name) {
            $this->assertStringContainsString($name, $everyone);
        }
    }

    public function testGetPayments_NamesTheSupplierTheListIsCutToAndOffersToShowEverySupplier(): void
    {
        $this->viewer();

        $without = $this->get('/accounting_payables/payments')->getBody();

        $this->assertStringNotContainsString('id="supplier_filter"', $without);

        $body = $this->get('/accounting_payables/payments?supplier_id=' . $this->supplierId)->getBody();

        $this->assertStringContainsString('id="supplier_filter"', $body);
        $this->assertStringContainsString(lang('Accounting_payables.supplier_filter', ['Gulf Supplies']), $body);
        $this->assertStringContainsString('id="supplier_filter_clear"', $body);
        $this->assertStringContainsString('id="f_supplier" value="' . $this->supplierId . '"', $body);
        $this->assertStringContainsString(lang('Accounting_payables.supplier_filter', ['#987654']), $this->get('/accounting_payables/payments?supplier_id=987654')->getBody(), 'A supplier that has no account is shown by its number');
    }

    public function testGetPayments_DoesNotNameTheSupplierToSomeoneLimitedToABranch(): void
    {
        $this->viewer([1]);

        $body = $this->get('/accounting_payables/payments?supplier_id=' . $this->supplierId)->getBody();

        $this->assertStringContainsString(lang('Accounting_payables.supplier_filter', ['#' . $this->supplierId]), $body);
        $this->assertStringNotContainsString('Gulf Supplies', $body);
    }

    public function testGetDebitNotes_NamesTheSupplierTheListIsCutToAndOffersToShowEverySupplier(): void
    {
        $this->viewer();

        $this->assertStringNotContainsString('id="supplier_filter"', $this->get('/accounting_payables/debit_notes')->getBody());

        $body = $this->get('/accounting_payables/debit_notes?supplier_id=' . $this->supplierId)->getBody();

        $this->assertStringContainsString('id="supplier_filter"', $body);
        $this->assertStringContainsString(lang('Accounting_payables.supplier_filter', ['Gulf Supplies']), $body);
        $this->assertStringContainsString('id="supplier_filter_clear"', $body);
        $this->assertStringContainsString('id="f_supplier" value="' . $this->supplierId . '"', $body);

        $this->viewer([1]);

        $limited = $this->get('/accounting_payables/debit_notes?supplier_id=' . $this->supplierId)->getBody();

        $this->assertStringContainsString(lang('Accounting_payables.supplier_filter', ['#' . $this->supplierId]), $limited);
        $this->assertStringNotContainsString('Gulf Supplies', $limited);
    }
}
