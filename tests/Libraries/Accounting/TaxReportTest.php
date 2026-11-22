<?php

namespace Tests\Libraries\Accounting;

use App\Libraries\Accounting\AccountingError;
use App\Libraries\Accounting\AccountingException;
use App\Libraries\Accounting\AccountingGuard;
use App\Libraries\Accounting\CustomerAdvances;
use App\Libraries\Accounting\InvoiceDiscount;
use App\Libraries\Accounting\JournalService;
use App\Libraries\Accounting\ReportPeriod;
use App\Libraries\Accounting\ReportScope;
use App\Libraries\Accounting\RevenueVouchers;
use App\Libraries\Accounting\RoutingKey;
use App\Libraries\Accounting\SalesDebitNotes;
use App\Libraries\Accounting\TaxAccountFigures;
use App\Libraries\Accounting\TaxBasis;
use App\Libraries\Accounting\TaxReport;
use App\Libraries\Accounting\TaxReportResult;
use App\Libraries\Accounting\TaxSection;
use App\Libraries\Accounting\TrialBalance;
use App\Libraries\HeaderCharges;
use App\Libraries\ShippingTaxOption;
use CodeIgniter\Database\Query;
use CodeIgniter\Events\Events;
use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\DatabaseTestTrait;
use Config\Database;
use Tests\Support\PreviewJournalFixtureTrait;
use Tests\Support\AccountingFixtureTrait;
use Tests\Support\EInvoiceSaleFixtureTrait;
use Tests\Support\EmployeeFixtureTrait;
use Tests\Support\ExpenseAccountingFixtureTrait;
use Tests\Support\ItemFixtureTrait;
use Tests\Support\PurchaseAccountingFixtureTrait;
use Tests\Support\ReportFixtureTrait;
use Tests\Support\SaleAccountingFixtureTrait;
use Tests\Support\SaudiSaleFixtureTrait;
use Tests\Support\StatementFixtureTrait;
use Tests\Support\TaxReportFixtureTrait;

/**
 * The tax of a period from the journals in the books: what the sales, the returns, the purchases
 * and the expenses did to the accounts of tax, the base and the rate they were charged on, the
 * period, the branches, the documents that were taken back, what the books hold that is not tax of
 * a document, the days before the books began, and the ties to the trial balance.
 */
class TaxReportTest extends CIUnitTestCase
{
    use DatabaseTestTrait;
    use PreviewJournalFixtureTrait;
    use AccountingFixtureTrait;
    use EmployeeFixtureTrait;
    use ExpenseAccountingFixtureTrait;
    use ItemFixtureTrait;
    use ReportFixtureTrait;
    use SaleAccountingFixtureTrait;
    use PurchaseAccountingFixtureTrait, EInvoiceSaleFixtureTrait {
        PurchaseAccountingFixtureTrait::debitNoteOf insteadof EInvoiceSaleFixtureTrait;
    }
    use SaudiSaleFixtureTrait;
    use StatementFixtureTrait;
    use TaxReportFixtureTrait;

    private const END = '2035-12-31';

    protected $migrate     = true;
    protected $migrateOnce = true;
    protected $refresh     = false;
    protected $namespace   = null;

    private TaxReport $taxes;

    public static function setUpBeforeClass(): void
    {
        $seeder = Database::seeder('tests');
        $seeder->call('TestDatabaseBootstrapSeeder');
    }

    protected function setUp(): void
    {
        parent::setUp();

        $this->startTaxBooks();

        $this->taxes = new TaxReport();
    }

    protected function tearDown(): void
    {
        $this->leaveTheSaudiRules();
        $this->stopTaxBooks();

        parent::tearDown();
    }

    private function report(?string $from = null, string $to = self::END, ?ReportScope $scope = null): TaxReportResult
    {
        return $this->taxes->build($scope ?? ReportScope::resolve(null, null), ReportPeriod::of($from, $to));
    }

    /**
     * @param list<int> $branches
     */
    private function scopeOf(array $branches): ReportScope
    {
        return ReportScope::resolve($branches, null);
    }

    private function figures(TaxReportResult $report, TaxSection $section): array
    {
        $figures = $report->section($section);

        return ['base' => $figures->base, 'tax' => $figures->tax, 'documents' => $figures->documents];
    }

    /**
     * What the trial balance of the same branches and days says an account did: credit minus
     * debit.
     */
    private function trialMovement(string $code, ReportScope $scope, ?string $from = null, string $to = self::END): string
    {
        foreach ((new TrialBalance())->build($scope, ReportPeriod::of($from, $to), true)->rows as $row) {
            if ($row->code === $code) {
                return bcsub($row->credit, $row->debit, 4);
            }
        }

        return '0.0000';
    }

    private function receive(string $amount, string $tax, string $date = '2026-10-20', ?int $revenueId = null): int
    {
        return (new RevenueVouchers())->save(['date' => $date, 'amount' => $amount, 'tax_amount' => $tax, 'payment_type' => 'Cash', 'description' => 'Stand rent'], $revenueId, $this->clerkId);
    }

    private function accountOf(TaxReportResult $report, string $code): TaxAccountFigures
    {
        foreach ($report->accounts as $account) {
            if ($account->code === $code) {
                return $account;
            }
        }

        $this->fail('The report has no account ' . $code);
    }

    public function testBuild_ReproducesTheFiguresObservedInTheReferenceSystem(): void
    {
        $this->observedScenario();

        $report = $this->report();

        $this->assertSame(['base' => '2210.0000', 'tax' => '331.5000', 'documents' => 1], $this->figures($report, TaxSection::SalesInvoices));
        $this->assertSame(['base' => '-220.0000', 'tax' => '-33.0000', 'documents' => 1], $this->figures($report, TaxSection::SalesReturns));
        $this->assertSame('298.5000', $report->output());
        $this->assertSame('1990.0000', $report->outputBase());
        $this->assertSame('0.0000', $report->input());
        $this->assertSame('298.5000', $report->net());
        $this->assertSame('298.5000', $this->accountOf($report, '215001')->movement, 'The balance of the account of tax collected is the tax of sales less that of returns');
    }

    public function testBuild_SplitsTheTaxInTheFivePartsWithTheSignsOfWhatItDoesToTheTaxOwed(): void
    {
        $invoice = $this->sell('1000.00', '150.00');
        $this->takeBack($invoice, '100.00', '15.00');
        $purchase = $this->buyTaxed('10', '100', '15');
        $this->giveBackTaxed($purchase, '2', '15');
        $this->spend('230.00', '30.00');

        $report = $this->report();

        $this->assertSame(['base' => '1000.0000', 'tax' => '150.0000', 'documents' => 1], $this->figures($report, TaxSection::SalesInvoices));
        $this->assertSame(['base' => '-100.0000', 'tax' => '-15.0000', 'documents' => 1], $this->figures($report, TaxSection::SalesReturns));
        $this->assertSame(['base' => '-1000.0000', 'tax' => '-150.0000', 'documents' => 1], $this->figures($report, TaxSection::Purchases));
        $this->assertSame(['base' => '200.0000', 'tax' => '30.0000', 'documents' => 1], $this->figures($report, TaxSection::PurchaseReturns));
        $this->assertSame(['base' => '-200.0000', 'tax' => '-30.0000', 'documents' => 1], $this->figures($report, TaxSection::Expenses));
        $this->assertSame('135.0000', $report->output());
        $this->assertSame('150.0000', $report->input(), 'The tax paid on purchases and expenses less the tax of what went back');
        $this->assertSame('-15.0000', $report->net(), 'More was paid than collected, so it is refundable');
    }

    public function testBuild_CountsTheVatOfAnAdvanceReceivedUnderTheSaudiRulesAsTaxOfTheSales(): void
    {
        $this->underTheSaudiRules();

        $receiver = $this->employeeWithGrants([AccountingGuard::RECEIVABLES_MANAGE, AccountingGuard::TREASURIES_MANAGE]);

        (new CustomerAdvances())->receive($this->customerId, '115.00', date('Y-m-d'), 'Cash', null, '', '', $receiver);

        $report = $this->report();

        $this->assertSame(['base' => '100.0000', 'tax' => '15.0000', 'documents' => 1], $this->figures($report, TaxSection::SalesInvoices), 'the VAT is owed when the advance is received');
        $this->assertSame('15.0000', $report->output());
        $this->assertTrue($report->ties());

        foreach ($report->accounts as $account) {
            $this->assertSame(0, $account->otherJournals, 'no journal of the VAT of advances is left out of the parts');
        }
    }

    public function testBuild_AddsUpToTheSameAsTheTrialBalanceOfTheSameBranchesAndDays(): void
    {
        $invoice = $this->sell('1000.00', '150.00');
        $this->takeBack($invoice, '100.00', '15.00');
        $purchase = $this->buyTaxed('10', '100', '15');
        $this->giveBackTaxed($purchase, '2', '15');
        $this->spend('230.00', '30.00');

        $scope  = ReportScope::resolve(null, null);
        $report = $this->report();

        $this->assertSame($this->trialMovement('215001', $scope), $this->accountOf($report, '215001')->movement);
        $this->assertSame($this->trialMovement('215002', $scope), $this->accountOf($report, '215002')->movement);
        $this->assertSame($report->output(), $this->trialMovement('215001', $scope), 'The tax collected the report adds up is what the account holds');
        $this->assertSame($report->input(), bcsub('0', $this->trialMovement('215002', $scope), 4), 'The tax paid the report adds up is what the account holds');
        $this->assertSame($report->net(), bcadd($this->trialMovement('215001', $scope), $this->trialMovement('215002', $scope), 4), 'What is payable is the balance of the accounts of tax together');
        $this->assertTrue($report->ties());

        foreach ($report->accounts as $account) {
            $this->assertSame('0.0000', $account->other());
            $this->assertSame(0, $account->otherJournals);
        }
    }

    public function testBuild_CountsAPurchaseOfStockWithInputTaxAtTheRateOfItsLines(): void
    {
        $item = $this->item('0.00');

        $this->assertGreaterThan(0, $this->buy([$this->purchaseLine($item, 0, '10', '100', '15'), $this->purchaseLine($item, 1, '5', '40', '5')]));

        $report = $this->report();

        $this->assertSame(['base' => '-1200.0000', 'tax' => '-160.0000', 'documents' => 1], $this->figures($report, TaxSection::Purchases));
        $this->assertSame('-160.0000', $this->accountOf($report, '215002')->movement);
        $this->assertSame('0.0000', $report->output());
        $this->assertSame('-160.0000', $report->net());
    }

    public function testBuild_ShowsAnExpenseWithTaxAsInputTaxAndTakesTheLastVersionOfAnEditedOne(): void
    {
        $id = $this->spend('230.00', '30.00');

        $this->assertSame(['base' => '-200.0000', 'tax' => '-30.0000', 'documents' => 1], $this->figures($this->report(), TaxSection::Expenses));

        $this->assertNotNull($this->saveExpense($this->expenseData('345.00', '45.00'), $id));

        $report = $this->report();

        $this->assertSame(['base' => '-300.0000', 'tax' => '-45.0000', 'documents' => 1], $this->figures($report, TaxSection::Expenses));
        $this->assertSame('-45.0000', $this->accountOf($report, '215002')->movement, 'The journal of the old version was reversed with a reversal');
        $this->assertTrue($report->ties());
    }

    public function testBuild_LeavesOutAnExpenseWithNoTaxAndOneThatWasDeleted(): void
    {
        $this->spend('100.00', '0');
        $deleted = $this->spend('115.00', '15.00');

        $this->assertTrue($this->expenses->delete_list([$deleted], $this->clerkId));

        $report = $this->report();

        $this->assertSame(['base' => '0.0000', 'tax' => '0.0000', 'documents' => 0], $this->figures($report, TaxSection::Expenses));
        $this->assertSame('0.0000', $this->accountOf($report, '215002')->movement);
        $this->assertTrue($report->ties());
    }

    public function testBuild_ShowsMoneyThatCameBackFromAnExpenseAsAMinusOfTheTaxPaid(): void
    {
        $this->spend('230.00', '30.00');
        $this->spend('-115.00', '-15.00');

        $report = $this->report();

        $this->assertSame(['base' => '-100.0000', 'tax' => '-15.0000', 'documents' => 2], $this->figures($report, TaxSection::Expenses));
        $this->assertSame('-15.0000', $this->accountOf($report, '215002')->movement);
    }

    public function testBuild_ShowsAReceiptVoucherWithTaxAsOutputTaxAndTakesTheLastVersionOfAnEditedOne(): void
    {
        $id = $this->receive('115.00', '15.00');

        $report = $this->report();

        $this->assertSame(['base' => '100.0000', 'tax' => '15.0000', 'documents' => 1], $this->figures($report, TaxSection::Revenues));
        $this->assertSame('15.0000', $report->output());
        $this->assertSame('100.0000', $report->outputBase());
        $this->assertSame('15.0000', $report->net());
        $this->assertSame(0, $this->accountOf($report, '215001')->otherJournals, 'A voucher is tax of a document, not another journal');

        $this->receive('230.00', '30.00', '2026-10-20', $id);

        $report = $this->report();

        $this->assertSame(['base' => '200.0000', 'tax' => '30.0000', 'documents' => 1], $this->figures($report, TaxSection::Revenues));
        $this->assertSame('30.0000', $this->accountOf($report, '215001')->movement, 'The journal of the old version was reversed with a reversal');
        $this->assertTrue($report->ties());
    }

    public function testBuild_LeavesOutAReceiptVoucherWithNoTaxAndOneThatWasDeleted(): void
    {
        $this->receive('100.00', '0');
        $deleted = $this->receive('115.00', '15.00');

        (new RevenueVouchers())->delete([$deleted], $this->clerkId);

        $report = $this->report();

        $this->assertSame(['base' => '0.0000', 'tax' => '0.0000', 'documents' => 0], $this->figures($report, TaxSection::Revenues));
        $this->assertSame('0.0000', $report->net());
        $this->assertTrue($report->ties());
    }

    public function testBuild_AddsTheTaxOfReceiptVouchersToTheTaxOfSalesInWhatIsPayable(): void
    {
        $this->sell('100.00', '15.00');
        $this->receive('115.00', '15.00');
        $this->spend('230.00', '30.00');

        $report = $this->report();

        $this->assertSame('30.0000', $report->output());
        $this->assertSame('30.0000', $report->input());
        $this->assertSame('0.0000', $report->net());
        $this->assertSame('15.0000', $report->section(TaxSection::Revenues)->tax);
        $this->assertTrue($report->ties());
    }

    public function testBuild_CountsAReceiptVoucherOfAnotherBranchOnlyInTheScopeThatHoldsIt(): void
    {
        $treasury = $this->treasury('Branch drawer', $this->second);

        (new RevenueVouchers())->save(['date' => '2026-10-20', 'amount' => '115.00', 'tax_amount' => '15.00', 'payment_type' => 'Cash', 'treasury_id' => $treasury, 'description' => 'Second branch'], null, $this->clerkId);

        $this->assertSame('0.0000', $this->report(null, self::END, $this->scopeOf([1]))->output());
        $this->assertSame('15.0000', $this->report(null, self::END, $this->scopeOf([$this->second]))->output());
    }

    public function testBuild_NetsASaleThatWasCancelledToNothing(): void
    {
        $service = $this->item('0', false);
        $saleId  = $this->save([$this->line($service, 0, '1', '100.00')], [$this->tax($service, 0, '15.00')], [$this->payment(lang('Sales.cash'), '115.00')], SALE_TYPE_POS);

        $this->assertSame('15.0000', $this->report()->output());
        $this->assertTrue($this->sales->delete($saleId, false, true, $this->employeeId));

        $report = $this->report();

        $this->assertSame(['base' => '0.0000', 'tax' => '0.0000', 'documents' => 0], $this->figures($report, TaxSection::SalesInvoices));
        $this->assertSame('0.0000', $report->net());
        $this->assertSame('0.0000', $this->accountOf($report, '215001')->movement);
        $this->assertTrue($report->ties());
        $this->assertSame('reversed', $this->journals($saleId)[0]['status']);
    }

    public function testBuild_CountsTheOriginalAndItsReversalInTheirOwnPeriodsAndZeroTogether(): void
    {
        $service = $this->item('0', false);
        $saleId  = $this->save([$this->line($service, 0, '1', '100.00')], [$this->tax($service, 0, '15.00')], [$this->payment(lang('Sales.cash'), '115.00')], SALE_TYPE_POS);
        $day     = $this->dayOf($saleId);
        $later   = date('Y-m-d', strtotime($day . ' +3 days'));

        $this->assertTrue($this->sales->delete($saleId, false, true, $this->employeeId));

        $reversal = $this->accountingDb()->table('accounting_journals')->where('source_type', 'reversal')->where('reverses_journal_id', $this->journals($saleId)[0]['id'])->get()->getRow();
        $this->accountingDb()->table('accounting_journals')->where('journal_id', $reversal->journal_id)->update(['journal_date' => $later]);

        $before = $this->report($day, $day);
        $after  = $this->report($later, $later);

        $this->assertSame(['base' => '100.0000', 'tax' => '15.0000', 'documents' => 1], $this->figures($before, TaxSection::SalesInvoices));
        $this->assertSame(['base' => '-100.0000', 'tax' => '-15.0000', 'documents' => 1], $this->figures($after, TaxSection::SalesInvoices), 'The reversal is counted with the document it takes back, in its own period');
        $this->assertSame('0.0000', $this->report($day, $later)->net());
    }

    public function testBuild_LeavesOutAJournalThatIsStillADraftOrOnlyAPreview(): void
    {
        $this->observedScenario();
        $this->postManual('215001', '0', '500.00', 'cash', 'draft');
        $this->postManual('215002', '70.00', '0', 'cash', 'preview');

        $report = $this->report();

        $this->assertSame('298.5000', $this->accountOf($report, '215001')->movement, 'A draft is not in the books');
        $this->assertSame('0.0000', $this->accountOf($report, '215002')->movement, 'A preview is not in the books');
        $this->assertSame('298.5000', $report->net());
        $this->assertTrue($report->ties());
    }

    public function testBuild_LeavesOutASaleThatWasSuspendedAndOneThatWasNeverPosted(): void
    {
        $service = $this->item('0', false);

        $this->assertGreaterThan(0, $this->save([$this->line($service, 0, '1', '100.00')], [$this->tax($service, 0, '15.00')], [], SALE_TYPE_POS, -1, true, null, SUSPENDED));
        $this->assertGreaterThan(0, $this->save([$this->line($service, 0, '1', '100.00')], [$this->tax($service, 0, '15.00')], [], SALE_TYPE_QUOTE, -1, true, null, SUSPENDED));

        $report = $this->report();

        $this->assertSame('0.0000', $report->output());
        $this->assertSame(0, $report->section(TaxSection::SalesInvoices)->documents);
    }

    public function testBuild_CountsADocumentOnTheFirstAndTheLastDayOfThePeriod(): void
    {
        $saleId = $this->sell('100.00', '15.00');
        $day    = $this->dayOf($saleId);
        $before = date('Y-m-d', strtotime($day . ' -1 day'));
        $after  = date('Y-m-d', strtotime($day . ' +1 day'));

        $this->assertSame('15.0000', $this->report($day, $day)->output(), 'The one day of the document');
        $this->assertSame('15.0000', $this->report($day, $after)->output(), 'The first day counts');
        $this->assertSame('15.0000', $this->report($before, $day)->output(), 'The last day counts');
        $this->assertSame('0.0000', $this->report($after, self::END)->output(), 'The day after is another period');
        $this->assertSame('0.0000', $this->report($before, $before)->output(), 'The day before is another period');
    }

    public function testBuild_AddsUpTheBranchesOfTheScopeAndNoOthers(): void
    {
        $depot = $this->locationOfBranch($this->second, 'Second depot');

        $this->sell('100.00', '15.00');
        $this->sell('200.00', '30.00', null, $depot);

        $first  = $this->report(scope: $this->scopeOf([1]));
        $second = $this->report(scope: $this->scopeOf([$this->second]));
        $both   = $this->report(scope: $this->scopeOf([1, $this->second]));

        $this->assertSame(['100.0000', '15.0000'], [$first->section(TaxSection::SalesInvoices)->base, $first->output()]);
        $this->assertSame(['200.0000', '30.0000'], [$second->section(TaxSection::SalesInvoices)->base, $second->output()]);
        $this->assertSame(['300.0000', '45.0000'], [$both->section(TaxSection::SalesInvoices)->base, $both->output()]);
        $this->assertSame($this->trialMovement('215001', $this->scopeOf([$this->second])), $this->accountOf($second, '215001')->movement);
        $this->assertTrue($both->scope->isCompany());
    }

    public function testBuild_AddsUpNothingForAScopeOfNoBranch(): void
    {
        $this->sell('100.00', '15.00');

        $report = $this->report(scope: ReportScope::resolve(null, []));

        $this->assertTrue($report->scope->isEmpty());
        $this->assertSame('0.0000', $report->output());
        $this->assertSame(0, $report->section(TaxSection::SalesInvoices)->documents);
        $this->assertSame(0, $report->legacyDocuments);
    }

    public function testBuild_LeavesOutTheJournalsOfAnotherCompany(): void
    {
        $this->sell('100.00', '15.00');

        $db = $this->accountingDb();
        $db->table('companies')->insert(['name' => 'Another company']);
        $company = (int) $db->insertID();

        try {
            $db->table('accounting_journals')->update(['company_id' => $company]);

            $this->assertSame('0.0000', $this->report()->output(), 'The journals of another company are not these');
        } finally {
            $db->table('accounting_journals')->update(['company_id' => 1]);
            $db->table('companies')->where('company_id', $company)->delete();
        }
    }

    public function testBuild_SplitsTheBaseAndTheTaxByTheRateOfTheDocumentLines(): void
    {
        $first  = $this->item('0', false);
        $second = $this->item('0', false);
        $lines  = [$this->line($first, 0, '1', '100.00'), $this->line($second, 1, '2', '50.00')];
        $taxes  = [$this->tax($first, 0, '15.00'), [...$this->tax($second, 1, '5.00'), 'name' => 'Reduced', 'percent' => '5.0000']];

        $this->assertGreaterThan(0, $this->save($lines, $taxes));

        $rows = $this->report()->section(TaxSection::SalesInvoices)->rates;

        $this->assertSame(['15', '5'], array_map(static fn ($row): string => $row->rate, $rows), 'The highest rate first');
        $this->assertSame(['100.0000', '15.0000', 1], [$rows[0]->base, $rows[0]->tax, $rows[0]->documents]);
        $this->assertSame(['100.0000', '5.0000', 1], [$rows[1]->base, $rows[1]->tax, $rows[1]->documents]);
    }

    private function turnOnShipping(string $percent = '15'): void
    {
        $this->setConfigValue('accounting_sales_shipping', '1');
        $this->setConfigValue('accounting_shipping_tax_percent', $percent);
    }

    public function testBuild_MatchesAnInvoiceWhoseShippingIsTaxedAndCountsTheShippingInItsBaseAndRate(): void
    {
        $this->turnOnShipping();

        $service = $this->item('0', false);

        $this->assertGreaterThan(0, $this->save([$this->line($service, 0, '1', '100.00')], [$this->tax($service, 0, '15.00')], headerCharges: new HeaderCharges(shipping: '20', shippingTaxOption: ShippingTaxOption::Added, shippingTaxPercent: '15')));

        $report = $this->report();
        $rows   = $report->section(TaxSection::SalesInvoices)->rates;

        $this->assertSame(['base' => '120.0000', 'tax' => '18.0000', 'documents' => 1], $this->figures($report, TaxSection::SalesInvoices));
        $this->assertSame(['120.0000', '18.0000', 1], [$rows[0]->base, $rows[0]->tax, $rows[0]->documents]);
        $this->assertSame(0, $report->unmatchedDocuments);
        $this->assertTrue($report->ties());
    }

    public function testBuild_MatchesAnInvoiceOfFreeGoodsWithTaxedShipping(): void
    {
        $this->turnOnShipping();

        $service = $this->item('0', false);

        $this->assertGreaterThan(0, $this->save([$this->line($service, 0, '1', '0')], [], headerCharges: new HeaderCharges(shipping: '20', shippingTaxOption: ShippingTaxOption::Added, shippingTaxPercent: '15')));

        $report = $this->report();

        $this->assertSame(['base' => '20.0000', 'tax' => '3.0000', 'documents' => 1], $this->figures($report, TaxSection::SalesInvoices));
        $this->assertSame(0, $report->unmatchedDocuments);
        $this->assertTrue($report->ties());
    }

    public function testBuild_MatchesACreditNoteWhoseShippingIsTaxedInThePartOfReturns(): void
    {
        $this->turnOnShipping();

        $invoice = $this->sell('1000.00', '150.00');
        $service = $this->item('0', false);

        $this->assertGreaterThan(0, $this->save([$this->line($service, 0, '-1', '100.00')], [$this->tax($service, 0, '-15.00')], [], SALE_TYPE_RETURN, -1, true, $invoice, headerCharges: new HeaderCharges(shipping: '20', shippingTaxOption: ShippingTaxOption::Added, shippingTaxPercent: '15')));

        $report = $this->report();

        $this->assertSame(['base' => '-120.0000', 'tax' => '-18.0000', 'documents' => 1], $this->figures($report, TaxSection::SalesReturns));
        $this->assertSame(0, $report->unmatchedDocuments);
        $this->assertTrue($report->ties());
    }

    public function testBuild_ShowsTheOutputTaxOfASalesDebitNoteAmongTheSalesInvoicesWithItsBaseAndRate(): void
    {
        $this->setConfigValue('accounting_debit_note', '1');

        $clerk = $this->employeeWithGrants([AccountingGuard::DEBIT_NOTES_MANAGE]);

        (new SalesDebitNotes())->create(['customer_id' => $this->customerId, 'date' => date('Y-m-d'), 'amount' => '1000', 'tax_percent' => '15'], $clerk);

        $report = $this->report();
        $rows   = $report->section(TaxSection::SalesInvoices)->rates;

        $this->assertSame(['base' => '1000.0000', 'tax' => '150.0000', 'documents' => 1], $this->figures($report, TaxSection::SalesInvoices));
        $this->assertSame(['15', '1000.0000', '150.0000', 1], [$rows[0]->rate, $rows[0]->base, $rows[0]->tax, $rows[0]->documents]);
        $this->assertSame('150.0000', $report->output());
        $this->assertSame(0, $report->unmatchedDocuments);
        $this->assertSame(0, $this->accountOf($report, '215001')->otherJournals);
        $this->assertTrue($report->ties());
    }

    public function testBuild_NetsASalesDebitNoteThatWasTakenBackToNothing(): void
    {
        $this->setConfigValue('accounting_debit_note', '1');

        $clerk = $this->employeeWithGrants([AccountingGuard::DEBIT_NOTES_MANAGE]);
        $notes = new SalesDebitNotes();
        $id    = $notes->create(['customer_id' => $this->customerId, 'date' => date('Y-m-d'), 'amount' => '1000', 'tax_percent' => '15'], $clerk);

        $notes->reverse($id, 'raised by mistake', $clerk);

        $report = $this->report();

        $this->assertSame(['base' => '0.0000', 'tax' => '0.0000', 'documents' => 0], $this->figures($report, TaxSection::SalesInvoices));
        $this->assertSame('0.0000', $report->net());
        $this->assertSame(0, $report->unmatchedDocuments);
        $this->assertTrue($report->ties());
    }

    public function testBuild_AddsTheTaxOfADebitNoteToTheTaxOfTheInvoicesInWhatIsPayable(): void
    {
        $this->setConfigValue('accounting_debit_note', '1');

        $clerk = $this->employeeWithGrants([AccountingGuard::DEBIT_NOTES_MANAGE]);

        $this->sell('100.00', '15.00');
        (new SalesDebitNotes())->create(['customer_id' => $this->customerId, 'date' => date('Y-m-d'), 'amount' => '200', 'tax_percent' => '15'], $clerk);

        $report = $this->report();

        $this->assertSame(['base' => '300.0000', 'tax' => '45.0000', 'documents' => 2], $this->figures($report, TaxSection::SalesInvoices));
        $this->assertSame('45.0000', $report->net());
    }

    public function testBuild_SplitsAPurchaseByTheRateOfItsLines(): void
    {
        $item = $this->item('0.00');

        $this->assertGreaterThan(0, $this->buy([$this->purchaseLine($item, 0, '10', '100', '15'), $this->purchaseLine($item, 1, '5', '40', '5')]));
        $this->assertGreaterThan(0, $this->buy([$this->purchaseLine($item, 0, '1', '100', '15')]));

        $rows = $this->report()->section(TaxSection::Purchases)->rates;

        $this->assertSame(['15', '5'], array_map(static fn ($row): string => $row->rate, $rows));
        $this->assertSame(['-1100.0000', '-165.0000', 2], [$rows[0]->base, $rows[0]->tax, $rows[0]->documents]);
        $this->assertSame(['-200.0000', '-10.0000', 1], [$rows[1]->base, $rows[1]->tax, $rows[1]->documents]);
    }

    public function testBuild_GivesAnExpenseNoRateBecauseItCarriesNone(): void
    {
        $this->spend('230.00', '30.00');

        $this->assertSame([], $this->report()->section(TaxSection::Expenses)->rates);
    }

    public function testBuild_CountsOnlyTheLinesThatCarryTax(): void
    {
        $taxed  = $this->item('0', false);
        $exempt = $this->item('0', false);

        $this->assertGreaterThan(0, $this->save([$this->line($taxed, 0, '1', '100.00'), $this->line($exempt, 1, '1', '40.00')], [$this->tax($taxed, 0, '15.00')]));

        $report = $this->report();

        $this->assertSame(['base' => '100.0000', 'tax' => '15.0000', 'documents' => 1], $this->figures($report, TaxSection::SalesInvoices), 'The line without tax is not what the tax was charged on');
        $this->assertSame('100.0000', $report->section(TaxSection::SalesInvoices)->rates[0]->base);
    }

    public function testBuild_TakesTheBaseOfALineAfterItsDiscountAndWithoutTheTaxInsideItsPrice(): void
    {
        $discounted = $this->item('0', false);
        $included   = $this->item('0', false);
        $lines      = [$this->line($discounted, 0, '4', '75', '0', '10'), $this->line($included, 1, '1', '115.00')];
        $taxes      = [$this->tax($discounted, 0, '40.50'), [...$this->tax($included, 1, '15.00'), 'tax_type' => 0]];

        $this->assertGreaterThan(0, $this->save($lines, $taxes));

        $this->assertSame(['base' => '370.0000', 'tax' => '55.5000', 'documents' => 1], $this->figures($this->report(), TaxSection::SalesInvoices), '270 after the discount and 100 of a price of 115 with 15 inside it');
    }

    public function testBuild_TakesTheBaseAfterTheDiscountOnTheWholeInvoice(): void
    {
        $cycle = $this->discountedReferenceInvoice();

        $this->assertGreaterThan(0, $this->save($cycle['items'], $cycle['taxes'], [], SALE_TYPE_INVOICE, -1, true, null, COMPLETED, NEW_ENTRY, $cycle['discount']));
        $this->assertGreaterThan(0, $this->sell('100.00', '15.00'));

        $figures = $this->report()->section(TaxSection::SalesInvoices);

        $this->assertSame(['739.0000', '110.8500', 2], [$figures->base, $figures->tax, $figures->documents], 'The discounted invoice is 639 of goods and 95.85 of tax');
        $this->assertSame(['639.0000', '95.8500', 1], [$figures->discountedBase, $figures->discountedTax, $figures->discountedDocuments]);
    }

    public function testBuild_TakesTheBaseAfterAFixedDiscountOnTheWholeInvoice(): void
    {
        $cycle = $this->discountedReferenceInvoice(false);

        $this->assertGreaterThan(0, $this->save($cycle['items'], $cycle['taxes'], [], SALE_TYPE_INVOICE, -1, true, null, COMPLETED, NEW_ENTRY, $cycle['discount']));

        $figures = $this->report()->section(TaxSection::SalesInvoices);

        $this->assertSame(['690.0000', '103.5000'], [$figures->base, $figures->tax]);
        $this->assertSame(['690.0000', '103.5000', 1], [$figures->discountedBase, $figures->discountedTax, $figures->discountedDocuments]);
    }

    public function testBuild_TakesTheBaseAfterTheDiscountOnTheWholePurchase(): void
    {
        $item = $this->item('0.00');
        $discount = InvoiceDiscount::of(PERCENT, '10');

        $this->assertGreaterThan(0, $this->buy([$this->purchaseLine($item, 0, '10', '100', '15')], -1, null, null, $discount));

        $figures = $this->report()->section(TaxSection::Purchases);

        $this->assertSame(['-900.0000', '-135.0000', 1], [$figures->base, $figures->tax, $figures->documents]);
        $this->assertSame(['-900.0000', '-135.0000', 1], [$figures->discountedBase, $figures->discountedTax, $figures->discountedDocuments]);
    }

    public function testBuild_TakesTheBaseFromTheLinesAndAllowsACentAgainstTheSalesAccount(): void
    {
        $this->sell('10.10', '1.515');

        $report  = $this->report();
        $base    = $report->section(TaxSection::SalesInvoices)->base;
        $revenue = (string) $this->accountingDb()->query(
            'SELECT SUM(credit - debit) AS movement FROM ' . $this->accountingDb()->prefixTable('accounting_journal_lines') . ' WHERE account_id = ?',
            [$this->accountId('sales_revenue')],
        )->getRow()->movement;

        $this->assertSame('10.1000', $base);
        $this->assertSame('1.5150', $report->section(TaxSection::SalesInvoices)->tax);
        $this->assertSame('1.5150', $this->accountOf($report, '215001')->movement, 'The tax is exactly what the account holds');
        $this->assertSame('10.1050', bcadd($revenue, '0', 4), 'The sales account holds the rest of the rounded total after the tax');
        $this->assertSame('0.0050', bcsub($revenue, $base, 4), 'The base is read from the lines, so it differs from the sales account by the rounding, here half a cent');
    }

    public function testBuild_ShowsWhatTheAccountsOfTaxHoldFromAManualJournalApartFromTheDocuments(): void
    {
        $this->observedScenario();
        $manual = $this->postManual('215001', '100.00', '0');

        $report  = $this->report();
        $account = $this->accountOf($report, '215001');

        $this->assertSame('198.5000', $account->movement);
        $this->assertSame('298.5000', $account->reported);
        $this->assertSame('-100.0000', $account->other());
        $this->assertSame(1, $account->otherJournals);
        $this->assertFalse($account->ties());
        $this->assertFalse($report->ties());
        $this->assertSame('298.5000', $report->net(), 'A payment of the tax to the authority is not tax of a document');
        $this->assertGreaterThan(0, $manual);
    }

    public function testBuild_SaysTheReportDoesNotTieWhenAManualJournalAndItsReversalLeaveTheBalanceAlone(): void
    {
        $this->observedScenario();
        $manual = $this->postManual('215001', '100.00', '0');
        (new JournalService())->reverse($manual, '2026-10-22', 'Taken back');

        $account = $this->accountOf($this->report(), '215001');

        $this->assertSame('0.0000', $account->other());
        $this->assertSame(2, $account->otherJournals, 'The two journals are still not tax of a document');
        $this->assertFalse($account->ties());
    }

    public function testBuild_LeavesTheBaseOutOfADocumentWhoseLinesNoLongerSayWhatItsJournalSays(): void
    {
        $service = $this->item('0', false);
        $saleId  = $this->save([$this->line($service, 0, '1', '100.00')], [$this->tax($service, 0, '15.00')]);

        $this->accountingDb()->table('sales_items_taxes')->where('sale_id', $saleId)->update(['item_tax_amount' => '9.00']);

        $report = $this->report();

        $this->assertSame(['base' => '0.0000', 'tax' => '15.0000', 'documents' => 1], $this->figures($report, TaxSection::SalesInvoices), 'The tax is what the journal holds, and the base is not guessed');
        $this->assertSame(1, $report->unmatchedDocuments);
        $this->assertTrue($report->ties());
    }

    public function testBuild_KeepsTheTaxOfASaleThatIsGoneAndLeavesItsBaseOut(): void
    {
        $saleId = $this->sell('100.00', '15.00');

        $this->accountingDb()->query('SET FOREIGN_KEY_CHECKS = 0');
        $this->accountingDb()->table('sales_items_taxes')->where('sale_id', $saleId)->delete();
        $this->accountingDb()->table('sales_items')->where('sale_id', $saleId)->delete();
        $this->accountingDb()->table('sales')->where('sale_id', $saleId)->delete();
        $this->accountingDb()->query('SET FOREIGN_KEY_CHECKS = 1');

        $report  = $this->report();
        $account = $this->accountOf($report, '215001');

        $this->assertSame(['base' => '0.0000', 'tax' => '15.0000', 'documents' => 1], $this->figures($report, TaxSection::SalesInvoices), 'The tax is what the journal holds, and the base is not guessed');
        $this->assertSame('15.0000', $report->output());
        $this->assertSame(1, $report->unmatchedDocuments);
        $this->assertSame('0.0000', $account->other());
        $this->assertSame(0, $account->otherJournals);
        $this->assertTrue($report->ties());
    }

    public function testBuild_KeepsTheTaxOfASalesDebitNoteWhoseRowIsGoneInThePartOfInvoicesAndLeavesItsBaseOut(): void
    {
        $this->setConfigValue('accounting_debit_note', '1');

        $clerk = $this->employeeWithGrants([AccountingGuard::DEBIT_NOTES_MANAGE]);
        $id    = (new SalesDebitNotes())->create(['customer_id' => $this->customerId, 'date' => date('Y-m-d'), 'amount' => '1000', 'tax_percent' => '15'], $clerk);

        $this->accountingDb()->query('SET FOREIGN_KEY_CHECKS = 0');
        $this->accountingDb()->table('sales_debit_notes')->where('debit_note_id', $id)->delete();
        $this->accountingDb()->query('SET FOREIGN_KEY_CHECKS = 1');

        $report = $this->report();

        $this->assertSame(['base' => '0.0000', 'tax' => '150.0000', 'documents' => 1], $this->figures($report, TaxSection::SalesInvoices));
        $this->assertSame(1, $report->unmatchedDocuments);
        $this->assertTrue($report->ties());
    }

    public function testBuild_NetsAPurchaseDeletedInTheApplicationToNothingAndStillTies(): void
    {
        $receivingId = $this->buyTaxed('1', '100', '15');

        $this->assertTrue($this->purchases->delete_value($receivingId, $this->employeeId), (string) $this->purchases->accountingFailure()?->getMessage());
        $this->assertSame(0, $this->accountingDb()->table('receivings')->where('receiving_id', $receivingId)->countAllResults(), 'The purchase is gone with its lines');

        $report  = $this->report();
        $account = $this->accountOf($report, '215002');

        $this->assertSame(['base' => '0.0000', 'tax' => '0.0000', 'documents' => 0], $this->figures($report, TaxSection::Purchases));
        $this->assertSame('0.0000', $report->net());
        $this->assertSame(0, $report->unmatchedDocuments, 'A document that nets to nothing is not one whose base is left out');
        $this->assertSame(0, $account->otherJournals, 'The journal of a deleted purchase and its reversal are tax of a document, not other journals');
        $this->assertTrue($report->ties());
        $this->assertSame($this->trialMovement('215002', ReportScope::resolve(null, null)), $account->movement);
    }

    public function testBuild_ReadsAClosedPeriodTheSameWhenItsPurchaseIsDeletedLater(): void
    {
        $receivingId = $this->buyTaxed('1', '100', '15');
        $original    = $this->purchaseJournals($receivingId)[0]['id'];

        $this->accountingDb()->table('accounting_journals')->where('journal_id', $original)->update(['journal_date' => '2026-09-10']);

        $september = $this->report('2026-09-01', '2026-09-30');
        $this->assertSame(['base' => '-100.0000', 'tax' => '-15.0000', 'documents' => 1], $this->figures($september, TaxSection::Purchases));

        $this->assertTrue($this->purchases->delete_value($receivingId, $this->employeeId), (string) $this->purchases->accountingFailure()?->getMessage());

        $reversal = $this->accountingDb()->table('accounting_journals')->where('reverses_journal_id', $original)->get()->getRow();
        $this->accountingDb()->table('accounting_journals')->where('journal_id', $reversal->journal_id)->update(['journal_date' => '2026-10-12']);

        $everyone  = ReportScope::resolve(null, null);
        $afterward = $this->report('2026-09-01', '2026-09-30');
        $october   = $this->report('2026-10-01', '2026-10-31');

        $this->assertSame(['base' => '0.0000', 'tax' => '-15.0000', 'documents' => 1], $this->figures($afterward, TaxSection::Purchases), 'The tax filed for September is still there, the base is not known');
        $this->assertSame('-15.0000', $afterward->net());
        $this->assertSame(1, $afterward->unmatchedDocuments);
        $this->assertTrue($afterward->ties());
        $this->assertSame($this->trialMovement('215002', $everyone, '2026-09-01', '2026-09-30'), $this->accountOf($afterward, '215002')->movement);

        $this->assertSame(['base' => '0.0000', 'tax' => '15.0000', 'documents' => 1], $this->figures($october, TaxSection::Purchases), 'The reversal is the purchase taken back, in the part of purchases');
        $this->assertSame(['base' => '0.0000', 'tax' => '0.0000', 'documents' => 0], $this->figures($october, TaxSection::PurchaseReturns));
        $this->assertTrue($october->ties());
        $this->assertSame($this->trialMovement('215002', $everyone, '2026-10-01', '2026-10-31'), $this->accountOf($october, '215002')->movement);

        $this->assertSame('0.0000', $this->report('2026-09-01', '2026-10-31')->net());
    }

    public function testBuild_KeepsTheTaxOfAPurchaseReturnDeletedLaterInThePartOfReturns(): void
    {
        $receivingId = $this->buyTaxed('10', '100', '15');
        $returnId    = $this->giveBackTaxed($receivingId, '2', '15');
        $original    = $this->purchaseJournals($returnId)[0]['id'];

        $this->accountingDb()->table('accounting_journals')->where('journal_id', $original)->update(['journal_date' => '2026-09-10']);

        $this->assertTrue($this->purchases->delete_value($returnId, $this->employeeId), (string) $this->purchases->accountingFailure()?->getMessage());

        $reversal = $this->accountingDb()->table('accounting_journals')->where('reverses_journal_id', $original)->get()->getRow();
        $this->accountingDb()->table('accounting_journals')->where('journal_id', $reversal->journal_id)->update(['journal_date' => '2026-10-12']);

        $september = $this->report('2026-09-10', '2026-09-10');
        $october   = $this->report('2026-10-12', '2026-10-12');

        $this->assertSame(['base' => '0.0000', 'tax' => '30.0000', 'documents' => 1], $this->figures($september, TaxSection::PurchaseReturns));
        $this->assertSame(['base' => '0.0000', 'tax' => '-30.0000', 'documents' => 1], $this->figures($october, TaxSection::PurchaseReturns));
        $this->assertSame(['base' => '0.0000', 'tax' => '0.0000', 'documents' => 0], $this->figures($september, TaxSection::Purchases));
        $this->assertSame(1, $september->unmatchedDocuments);
        $this->assertSame(1, $october->unmatchedDocuments);
        $this->assertTrue($september->ties());
        $this->assertTrue($october->ties());
    }

    public function testBuild_ReadsTheAccountsTheTaxIsRoutedToAndNotAFixedCode(): void
    {
        $account = $this->customAccount('21', '2199', 'Output tax of the shop');

        $this->accountingDb()->table('accounting_routing')->where('routing_key', RoutingKey::TaxOutput->value)->update(['account_id' => $account]);

        $this->sell('100.00', '15.00');

        $report = $this->report();

        $this->assertSame('15.0000', $report->output());
        $this->assertSame('15.0000', $this->accountOf($report, '2199')->movement);
        $this->assertSame(['output'], $this->accountOf($report, '2199')->roles);
        $this->assertSame(['input'], $this->accountOf($report, '215002')->roles);
        $this->assertSame(['215002', '2199'], array_map(static fn (TaxAccountFigures $account): string => $account->code, $report->accounts), 'In the order of the codes');
    }

    public function testBuild_ReadsOnlyTheRoutingOfItsOwnCompany(): void
    {
        $db = $this->accountingDb();
        $db->table('companies')->insert(['name' => 'Another company']);
        $company = (int) $db->insertID();

        $db->table('accounting_accounts')->insert(['company_id' => $company, 'code' => '99', 'name' => 'Foreign tax', 'account_type' => 'liability', 'normal_balance' => 'credit', 'is_group' => 0]);
        $foreign = (int) $db->insertID();
        $db->table('accounting_routing')->insert(['company_id' => $company, 'routing_key' => RoutingKey::TaxOutput->value, 'routing_type' => 'auto', 'account_id' => $foreign]);

        try {
            $report = $this->report();

            $this->assertSame(['215001', '215002'], array_map(static fn (TaxAccountFigures $account): string => $account->code, $report->accounts));
        } finally {
            $db->table('accounting_routing')->where('company_id', $company)->delete();
            $db->table('accounting_accounts')->where('company_id', $company)->delete();
            $db->table('companies')->where('company_id', $company)->delete();
        }
    }

    public function testBuild_TiesOneAccountThatBothKindsOfTaxAreRoutedTo(): void
    {
        $this->accountingDb()->table('accounting_routing')->where('routing_key', RoutingKey::TaxOutput->value)->update(['account_id' => $this->accountIdByCode('215002')]);

        $this->sell('100.00', '15.00');
        $this->buyTaxed('1', '40', '15');

        $report = $this->report();

        $this->assertCount(1, $report->accounts);
        $this->assertSame(['output', 'input'], $report->accounts[0]->roles);
        $this->assertSame('15.0000', $report->output());
        $this->assertSame('6.0000', $report->input());
        $this->assertSame('9.0000', $report->accounts[0]->movement);
        $this->assertTrue($report->ties());
    }

    public function testBuild_SaysNoAccountIsRoutedWhenTheRoutingNamesNone(): void
    {
        $this->sell('100.00', '15.00');
        $this->accountingDb()->table('accounting_routing')->whereIn('routing_key', [RoutingKey::TaxOutput->value, RoutingKey::TaxInput->value])->delete();

        $report = $this->report();

        $this->assertFalse($report->outputRouted);
        $this->assertFalse($report->inputRouted);
        $this->assertSame([], $report->accounts);
        $this->assertSame('0.0000', $report->net());
        $this->assertFalse($report->ties());
    }

    public function testBuild_RefusesTheCashBasisAndNamesTheError(): void
    {
        $this->observedScenario();

        try {
            $this->taxes->build(ReportScope::resolve(null, null), ReportPeriod::of(null, self::END), TaxBasis::Cash);
            $this->fail('The cash basis was answered with the accrual basis');
        } catch (AccountingException $exception) {
            $this->assertSame(AccountingError::TaxBasisUnavailable, $exception->error);
            $this->assertSame('cash', $exception->context['basis']);
        }
    }

    public function testBuild_ReadsOneSnapshotWhileAnotherRequestChangesTheLinesOfADocument(): void
    {
        $service = $this->item('0', false);
        $saleId  = $this->save([$this->line($service, 0, '1', '100.00')], [$this->tax($service, 0, '15.00')]);
        $reader  = $this->openSecondConnection();
        $changed = false;

        $listener = function (Query $query) use (&$changed, $saleId): void {
            if ($changed || ! str_contains((string) $query, '`ospos_sales`')) {
                return;
            }

            $changed = true;
            $this->accountingDb()->table('sales_items_taxes')->where('sale_id', $saleId)->update(['item_tax_amount' => '9.00']);
        };

        Events::on('DBQuery', $listener);

        try {
            $report = (new TaxReport($reader))->build(ReportScope::resolve(null, null), ReportPeriod::of(null, self::END));
        } finally {
            Events::removeListener('DBQuery', $listener);
        }

        $this->assertTrue($changed, 'Another request changed the lines while the report was being read');
        $this->assertSame(['base' => '100.0000', 'tax' => '15.0000', 'documents' => 1], $this->figures($report, TaxSection::SalesInvoices), 'What was read of the journals and of the lines is of one moment');
        $this->assertSame(0, $report->unmatchedDocuments);
        $this->assertSame(1, $this->report()->unmatchedDocuments, 'The next report sees the change');
    }

    public function testBuild_WritesNothingAndLeavesNoTransactionOpen(): void
    {
        $this->observedScenario();
        $this->spend('230.00', '30.00');

        $tables = ['accounting_journals', 'accounting_journal_lines', 'accounting_audit_log', 'accounting_posting_failures', 'accounting_stock_entries', 'sales', 'sales_items_taxes', 'receivings', 'expenses', 'accounting_routing', 'accounting_accounts'];
        $before = array_map(fn (string $table): int => $this->countRows($table), $tables);

        $queries = $this->queriesOf(fn (): TaxReportResult => $this->report());

        $this->assertSame($before, array_map(fn (string $table): int => $this->countRows($table), $tables));
        $this->assertNotEmpty($queries);

        foreach ($queries as $sql) {
            $this->assertDoesNotMatchRegularExpression('/^\s*(INSERT|UPDATE|DELETE|REPLACE|TRUNCATE|ALTER|CREATE|DROP)\b/i', $sql);
        }

        $this->assertSame(0, $this->accountingDb()->transDepth);
    }

    public function testBuild_ReadsADocumentOfAClosedPeriodAsBefore(): void
    {
        $this->observedScenario();

        $before = $this->report();

        $this->closeThrough(self::END);
        $this->closeThrough(self::END, 1);

        $after = $this->report();

        $this->assertEquals($before, $after);
        $this->assertSame('298.5000', $after->net());
    }

    public function testBuild_SaysTheBooksBeganOnTheStartDateAndCountsTheDocumentsBeforeItThatCarryTax(): void
    {
        $this->setConfigValue('accounting_start_date', '2026-10-01');
        $this->saveLegacySale('2026-09-15 10:00:00', '100.00', '15.00');
        $this->saveLegacyPurchase('2026-09-20 10:00:00');
        $this->saveLegacyExpense('2026-09-25 10:00:00', '23.00', '3.00');
        $this->saveLegacyExpense('2026-10-02 10:00:00', '115.00', '15.00');

        $report = $this->report();

        $this->assertSame('2026-10-01', $report->startDate);
        $this->assertSame(3, $report->legacyDocuments, 'A sale, a purchase and an expense with tax, before the day the books began');
        $this->assertTrue($report->reachesBeforeBooks());
        $this->assertSame('0.0000', $report->net(), 'No journal holds them');
        $this->assertSame(2, $this->report('2026-09-16', '2026-09-30')->legacyDocuments, 'The purchase and the expense of the later days of September');
    }

    public function testBuild_LeavesOutOfTheDocumentsBeforeTheBooksTheOnesThatCarryNoTaxOrAreNotDocumentsOfTax(): void
    {
        $this->setConfigValue('accounting_start_date', '2026-10-01');
        $this->saveLegacySale('2026-09-15 10:00:00', '100.00', '0');
        $this->saveLegacySale('2026-09-15 10:00:00', '100.00', '15.00', 1, SUSPENDED);
        $this->saveLegacySale('2026-09-15 10:00:00', '100.00', '15.00', 1, CANCELED);
        $this->saveLegacySale('2026-09-15 10:00:00', '100.00', '15.00', 1, COMPLETED, SALE_TYPE_QUOTE);
        $this->saveLegacyPurchase('2026-09-20 10:00:00', '0');
        $this->saveLegacyPurchase('2026-09-20 10:00:00', '15', 'requisition');
        $this->saveLegacyExpense('2026-09-25 10:00:00', '50.00', '0');
        $this->saveLegacyExpense('2026-09-25 10:00:00', '50.00', null);
        $this->saveLegacyExpense('2026-09-25 10:00:00', '115.00', '15.00', null, 1);

        $this->assertSame(0, $this->report()->legacyDocuments);

        $this->saveLegacySale('2026-09-16 10:00:00', '200.00', '30.00', 1, COMPLETED, SALE_TYPE_RETURN);

        $this->assertSame(1, $this->report()->legacyDocuments, 'A return with tax counts as a document');
    }

    public function testBuild_CountsTheDocumentsBeforeTheBooksBeganOnlyInThePeriodAsked(): void
    {
        $this->setConfigValue('accounting_start_date', '2026-10-01');

        foreach (['2026-09-15 10:00:00', '2026-09-30 23:59:59'] as $time) {
            $this->saveLegacySale($time, '100.00', '15.00');
            $this->saveLegacyPurchase($time);
            $this->saveLegacyExpense($time, '23.00', '3.00');
        }

        $this->assertSame(6, $this->report('2026-09-15', '2026-09-30')->legacyDocuments);
        $this->assertSame(3, $this->report('2026-09-30', '2026-09-30')->legacyDocuments, 'The last day before the books began: a sale, a purchase and an expense');
        $this->assertSame(3, $this->report('2026-09-15', '2026-09-15')->legacyDocuments, 'The first day');
        $this->assertSame(0, $this->report('2026-09-16', '2026-09-29')->legacyDocuments, 'The days between');
        $this->assertSame(0, $this->report('2026-10-01', '2026-10-31')->legacyDocuments, 'The period begins on the first day of the books');
        $this->assertFalse($this->report('2026-10-01', '2026-10-31')->reachesBeforeBooks());
        $this->assertSame(6, $this->report(null, '2026-12-31')->legacyDocuments, 'A period with no first day reaches back to the start of time');
    }

    public function testBuild_CountsTheDocumentsBeforeTheBooksBeganOfTheBranchesOfTheScope(): void
    {
        $this->setConfigValue('accounting_start_date', '2026-10-01');
        $this->saveLegacySale('2026-09-15 10:00:00', '100.00', '15.00', $this->second);
        $this->saveLegacySale('2026-09-16 10:00:00', '100.00', '15.00', 1);
        $this->saveLegacyPurchase('2026-09-18 10:00:00', '15', 'receive', $this->second);
        $this->saveLegacyPurchase('2026-09-19 10:00:00', '15', 'receive', 1);
        $this->saveLegacyExpense('2026-09-17 10:00:00', '23.00', '3.00', null);

        $this->assertSame(2, $this->report(scope: $this->scopeOf([$this->second]))->legacyDocuments, 'A sale and a purchase of the second branch');
        $this->assertSame(3, $this->report(scope: $this->scopeOf([1]))->legacyDocuments, 'A sale and a purchase of the first, and an expense with no branch, which belongs to the default branch');
        $this->assertSame(5, $this->report()->legacyDocuments);
    }

    public function testBuild_CountsNoDocumentBeforeTheBooksWhenThereIsNoStartDate(): void
    {
        $this->saveLegacySale('2019-09-15 10:00:00', '100.00', '15.00');
        $this->setConfigValue('accounting_start_date', '');

        $report = $this->report();

        $this->assertNull($report->startDate);
        $this->assertSame(0, $report->legacyDocuments);
        $this->assertFalse($report->reachesBeforeBooks());
    }

    public function testBuild_LeavesOutThePreviewsOfABatch(): void
    {
        $this->sell('100', '15');
        $this->buyTaxed('1', '50', '15');

        $before = $this->report();

        $this->previewEverything();
        $this->assertPreviewsStand();

        $this->assertEquals($before, $this->report());
    }


    public function testBuild_ReadsTheAccountOfATaxThatHasOneOfItsOwnNextToTheRoutedAccountAndTiesBoth(): void
    {
        $account = $this->customAccount('215', '215003', 'Tax on services');
        $item    = $this->item('0', false);

        $this->save([$this->line($item, 0, '1', '100')], [$this->taxRow($item, 0, 'VAT', '15.0000', 1, '15.00'), $this->taxRow($item, 0, 'Service', '5.0000', 1, '5.00', $account)], [], SALE_TYPE_INVOICE);

        $report = $this->report();

        $this->assertSame('20.0000', $report->output());
        $this->assertSame('15.0000', $this->accountOf($report, '215001')->movement);
        $this->assertSame('5.0000', $this->accountOf($report, '215003')->movement);
        $this->assertSame(['output'], $this->accountOf($report, '215003')->roles);
        $this->assertSame(['215001', '215002', '215003'], array_map(static fn (TaxAccountFigures $figures): string => $figures->code, $report->accounts));
        $this->assertTrue($report->ties());
        $this->assertSame('5.0000', $this->trialMovement('215003', ReportScope::resolve(null, null)));
    }

    public function testBuild_CountsTheTaxInsideThePriceAgainstTheNetOfTheLineAndTiesWithTheAccountOfItsOwn(): void
    {
        $account = $this->customAccount('215', '215003', 'Tax on services');
        $item    = $this->item('0', false);

        $this->save([$this->line($item, 0, '1', '115')], [$this->taxRow($item, 0, 'VAT', '15.0000', 0, '15.00', $account)], [], SALE_TYPE_INVOICE);

        $report = $this->report();

        $this->assertSame(['base' => '100.0000', 'tax' => '15.0000', 'documents' => 1], $this->figures($report, TaxSection::SalesInvoices));
        $this->assertSame('15.0000', $this->accountOf($report, '215003')->reported);
        $this->assertTrue($report->ties());
        $this->assertSame(0, $report->unmatchedDocuments);
    }

    public function testBuild_NetsAnInvoiceAndItsCreditNoteToNothingOnTheAccountOfItsOwn(): void
    {
        $account = $this->customAccount('215', '215003', 'Tax on services');
        $item    = $this->item('0', false);
        $invoice = $this->save([$this->line($item, 0, '1', '115')], [$this->taxRow($item, 0, 'VAT', '15.0000', 0, '15.00', $account)], [], SALE_TYPE_INVOICE);

        $this->save([$this->line($item, 0, '-1', '115')], [$this->taxRow($item, 0, 'VAT', '15.0000', 0, '-15.00', $account)], [], SALE_TYPE_RETURN, -1, true, $invoice);

        $report = $this->report();

        $this->assertSame('0.0000', $report->output());
        $this->assertSame('0.0000', $this->accountOf($report, '215003')->movement);
        $this->assertSame('15.0000', $report->section(TaxSection::SalesInvoices)->tax);
        $this->assertSame('-15.0000', $report->section(TaxSection::SalesReturns)->tax);
        $this->assertTrue($report->ties());
    }

    public function testBuild_KeepsTheAccountASaleWasSavedWithInTheReportAfterTheTaxLeftTheList(): void
    {
        $account = $this->customAccount('215', '215003', 'Tax on services');
        $item    = $this->item('0', false);

        $this->save([$this->line($item, 0, '1', '100')], [$this->taxRow($item, 0, 'Gone', '15.0000', 1, '15.00', $account)], [], SALE_TYPE_INVOICE);

        $report = $this->report();

        $this->assertSame('15.0000', $this->accountOf($report, '215003')->movement);
        $this->assertTrue($report->ties());
        $this->assertSame(0, $this->countRows('tax_definitions'));
    }

    public function testBuild_ReadsTheAccountOfATaxOnTheListBeforeAnyDocumentUsesIt(): void
    {
        $account = $this->customAccount('215', '215003', 'Tax on services');

        $this->accountingDb()->table('tax_definitions')->insert(['tax_name' => 'Service', 'tax_included' => null, 'liability_account_id' => $account]);

        try {
            $report = $this->report();

            $this->assertSame('0.0000', $this->accountOf($report, '215003')->movement);
            $this->assertTrue($report->ties());
        } finally {
            $this->accountingDb()->table('tax_definitions')->truncate();
        }
    }

    public function testBuild_ShowsWhatIsPostedByHandToTheAccountOfATaxApartFromTheDocuments(): void
    {
        $account = $this->customAccount('215', '215003', 'Tax on services');
        $item    = $this->item('0', false);

        $this->save([$this->line($item, 0, '1', '100')], [$this->taxRow($item, 0, 'Service', '5.0000', 1, '5.00', $account)], [], SALE_TYPE_INVOICE);
        $this->postManual('215003', '0.00', '2.00');

        $figures = $this->accountOf($this->report(), '215003');

        $this->assertSame('7.0000', $figures->movement);
        $this->assertSame('5.0000', $figures->reported);
        $this->assertSame('2.0000', $figures->other());
        $this->assertFalse($figures->ties());
    }
}
