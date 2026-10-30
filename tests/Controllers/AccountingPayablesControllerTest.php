<?php

namespace Tests\Controllers;

use App\Libraries\Accounting\AccountingGuard;
use App\Libraries\Accounting\JournalDraft;
use App\Libraries\Accounting\JournalLineDraft;
use App\Libraries\Accounting\PostingService;
use App\Libraries\Accounting\SupplierAdvances;
use App\Libraries\Accounting\TreasuryService;
use CodeIgniter\Exceptions\PageNotFoundException;
use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\DatabaseTestTrait;
use CodeIgniter\Test\FeatureTestTrait;
use Config\Database;
use Tests\Support\AccountingFixtureTrait;
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

        $totals = substr($body, (int) strpos($body, 'id="aged_totals"'));

        foreach (['50.00', '120.00', '180.00', '350.00'] as $figure) {
            $this->assertStringContainsString(to_currency_no_money($figure), $totals);
        }

        $this->assertSame('350.00', $this->owed());
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
}
