<?php

namespace Tests\Libraries\Accounting;

use App\Libraries\Accounting\AccountingError;
use App\Libraries\Accounting\AccountingException;
use App\Libraries\Accounting\InvoiceDiscount;
use App\Libraries\Accounting\JournalDraft;
use App\Libraries\Accounting\JournalLineDraft;
use App\Libraries\Accounting\JournalService;
use App\Libraries\Accounting\PostingService;
use App\Libraries\Accounting\ReportPeriod;
use App\Libraries\Accounting\ReportScope;
use App\Libraries\Accounting\RoutingKey;
use App\Libraries\Accounting\TaxAccountFigures;
use App\Libraries\Accounting\TaxBasis;
use App\Libraries\Accounting\TaxReport;
use App\Libraries\Accounting\TaxReportResult;
use App\Libraries\Accounting\TaxSection;
use App\Libraries\Accounting\TrialBalance;
use App\Models\Expense;
use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\DatabaseTestTrait;
use Config\Database;
use Tests\Support\AccountingFixtureTrait;
use Tests\Support\EmployeeFixtureTrait;
use Tests\Support\ExpenseAccountingFixtureTrait;
use Tests\Support\ItemFixtureTrait;
use Tests\Support\PurchaseAccountingFixtureTrait;
use Tests\Support\ReportFixtureTrait;
use Tests\Support\SaleAccountingFixtureTrait;
use Tests\Support\StatementFixtureTrait;

/**
 * The tax of a period from the journals in the books: what the sales, the returns, the purchases
 * and the expenses did to the accounts of tax, the base and the rate they were charged on, the
 * period, the branches, the documents that were taken back, what the books hold that is not tax of
 * a document, the days before the books began, and the ties to the trial balance.
 */
class TaxReportTest extends CIUnitTestCase
{
    use DatabaseTestTrait;
    use AccountingFixtureTrait;
    use EmployeeFixtureTrait;
    use ExpenseAccountingFixtureTrait;
    use ItemFixtureTrait;
    use ReportFixtureTrait;
    use SaleAccountingFixtureTrait;
    use PurchaseAccountingFixtureTrait;
    use StatementFixtureTrait;

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

        $this->clearExpenseRows();
        $this->resetBranches();
        $this->startBooks();
        $this->workInEveryBranch();

        $this->expenses        = model(Expense::class);
        $this->clerkId         = $this->employeeWithGrants([]);
        $this->expensesStarted = true;
        $this->taxes           = new TaxReport();
    }

    protected function tearDown(): void
    {
        $this->setConfigValue('accounting_start_date', '2020-01-01');
        $this->stopExpenses();
        $this->stopBuying();
        $this->clearJournals();
        $this->reinstallChart();
        $this->resetBranches();

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
     * An invoice of a service for the net amount, with the tax on it, left on the customer.
     */
    private function sell(string $net, string $tax, ?int $customerId = null, int $locationId = self::LOCATION_ID): int
    {
        $service = $this->item('0', false);
        $saleId  = $this->save([$this->line($service, 0, '1', $net, '0', '0', PERCENT, $locationId)], $tax === '0' ? [] : [$this->tax($service, 0, $tax)], [], SALE_TYPE_INVOICE, $customerId ?? -1);

        $this->assertGreaterThan(0, $saleId, 'The sale of ' . $net . ' was not saved');

        return $saleId;
    }

    /**
     * A credit note of a service for the net amount, with the tax of it, taken from an invoice.
     */
    private function takeBack(int $invoiceId, string $net, string $tax): int
    {
        $service = $this->item('0', false);
        $returnId = $this->save([$this->line($service, 0, '-1', $net)], [$this->tax($service, 0, '-' . $tax)], [], SALE_TYPE_RETURN, -1, true, $invoiceId);

        $this->assertGreaterThan(0, $returnId, 'The return of ' . $net . ' was not saved');

        return $returnId;
    }

    /**
     * A purchase of stock at the price, with tax at the rate, left on the supplier.
     */
    private function buyTaxed(string $quantity, string $price, string $rate, ?int $supplierId = null): int
    {
        $item        = $this->item('0.00');
        $receivingId = $this->buy([$this->purchaseLine($item, 0, $quantity, $price, $rate)], $supplierId ?? -1);

        $this->assertGreaterThan(0, $receivingId, 'The purchase was not saved');

        return $receivingId;
    }

    private function giveBackTaxed(int $receivingId, string $quantity, string $rate): int
    {
        $line     = $this->accountingDb()->table('receivings_items')->where('receiving_id', $receivingId)->get()->getRow();
        $returnId = $this->buy([$this->purchaseLine((int) $line->item_id, 0, '-' . $quantity, (string) $line->item_unit_price, $rate)], -1, null, $receivingId);

        $this->assertGreaterThan(0, $returnId, 'The purchase return was not saved');

        return $returnId;
    }

    private function spend(string $gross, string $tax, string $date = '2026-10-20 10:00:00'): int
    {
        $id = $this->saveExpense($this->expenseData($gross, $tax, 'Cash', null, $date));

        $this->assertNotNull($id, 'The expense of ' . $gross . ' was not saved');

        return $id;
    }

    private function dayOf(int $saleId): string
    {
        return $this->journals($saleId)[0]['date'];
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

    private function accountOf(TaxReportResult $report, string $code): TaxAccountFigures
    {
        foreach ($report->accounts as $account) {
            if ($account->code === $code) {
                return $account;
            }
        }

        $this->fail('The report has no account ' . $code);
    }

    /**
     * The sales and the return the reference system was read on: 2,210 of sales with 331.50 of
     * tax and 220 of returns with 33.00 of tax, so 298.50 is owed.
     *
     * @return array{invoice: int, return: int}
     */
    private function observedScenario(): array
    {
        $invoice = $this->sell('2210.00', '331.50');

        return ['invoice' => $invoice, 'return' => $this->takeBack($invoice, '220.00', '33.00')];
    }

    private function postManual(string $account, string $debit, string $credit, string $counter = 'cash', string $status = 'posted'): int
    {
        $journalId = (new PostingService())->post(new JournalDraft(
            date: '2026-10-21',
            lines: [
                new JournalLineDraft($this->accountIdByCode($account), debit: $debit, credit: $credit),
                new JournalLineDraft($this->accountId($counter), debit: $credit, credit: $debit),
            ],
            sourceType: 'manual',
            sourceId: null,
            employeeId: 1,
            currencyCode: 'SAR',
        ))->journalId;

        if ($status !== 'posted') {
            $this->accountingDb()->table('accounting_journals')->where('journal_id', $journalId)->update(['status' => $status]);
        }

        return $journalId;
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
        $revenue = bcsub('0', $this->balance($this->accountId('sales_revenue')), 4);

        $this->assertSame('10.1000', $report->section(TaxSection::SalesInvoices)->base);
        $this->assertSame('1.5150', $report->section(TaxSection::SalesInvoices)->tax);
        $this->assertSame('1.5150', $this->accountOf($report, '215001')->movement, 'The tax is exactly what the account holds');
        $this->assertLessThanOrEqual(0, bccomp(bcsub($revenue, $report->section(TaxSection::SalesInvoices)->base, 4), '0.01', 4), 'The sales account is the rest of the rounded total, so it may differ from the base by a cent');
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

    public function testBuild_ShowsATaxOfADocumentThatIsGoneAsNotTheTaxOfADocument(): void
    {
        $saleId = $this->sell('100.00', '15.00');

        $this->accountingDb()->query('SET FOREIGN_KEY_CHECKS = 0');
        $this->accountingDb()->table('sales_items_taxes')->where('sale_id', $saleId)->delete();
        $this->accountingDb()->table('sales_items')->where('sale_id', $saleId)->delete();
        $this->accountingDb()->table('sales')->where('sale_id', $saleId)->delete();
        $this->accountingDb()->query('SET FOREIGN_KEY_CHECKS = 1');

        $report  = $this->report();
        $account = $this->accountOf($report, '215001');

        $this->assertSame('0.0000', $report->output());
        $this->assertSame('15.0000', $account->other());
        $this->assertSame(1, $account->otherJournals);
        $this->assertFalse($report->ties());
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

    public function testFromRequest_AcceptsTheAccrualBasisAndNoBasisAndRejectsAnyOther(): void
    {
        $this->assertSame(TaxBasis::Accrual, TaxBasis::fromRequest(''));
        $this->assertSame(TaxBasis::Accrual, TaxBasis::fromRequest(' accrual '));
        $this->assertSame(TaxBasis::Cash, TaxBasis::fromRequest('cash'));

        try {
            TaxBasis::fromRequest('paid');
            $this->fail('A basis that does not exist was taken');
        } catch (AccountingException $exception) {
            $this->assertSame(AccountingError::TaxBasisUnavailable, $exception->error);
        }
    }

    public function testBuild_RejectsADayThatIsNotADateAndAPeriodThatEndsBeforeItStarts(): void
    {
        foreach ([['2026-02-30', null, AccountingError::InvalidDate], ['2026-10-01', '2026-10-31', AccountingError::ReportPeriodInvalid]] as [$to, $from, $error]) {
            try {
                $this->report($from, $to);
                $this->fail('A period that is not one was taken');
            } catch (AccountingException $exception) {
                $this->assertSame($error, $exception->error);
            }
        }
    }

    public function testBuild_SaysTheBooksBeganOnTheStartDateAndCountsTheDocumentsBeforeItThatCarryTax(): void
    {
        $this->setConfigValue('accounting_start_date', '2026-10-01');
        $this->saveLegacySale('2026-09-15 10:00:00', '100.00', '15.00');
        $this->saveLegacyPurchase('2026-09-20 10:00:00');
        $this->saveLegacyExpense('2026-09-25 10:00:00', '23.00', '3.00');
        $this->saveLegacyExpense('2026-09-26 10:00:00', '50.00', '0');
        $this->saveLegacyExpense('2026-10-02 10:00:00', '115.00', '15.00');

        $report = $this->report();

        $this->assertSame('2026-10-01', $report->startDate);
        $this->assertSame(3, $report->legacyDocuments, 'A sale, a purchase and an expense with tax, before the day the books began');
        $this->assertTrue($report->reachesBeforeBooks());
        $this->assertSame('0.0000', $report->net(), 'No journal holds them');
        $this->assertSame(2, $this->report('2026-09-16', '2026-09-30')->legacyDocuments, 'The purchase and the expense with tax of the later days of September');
    }

    public function testBuild_CountsTheDocumentsBeforeTheBooksBeganOnlyInThePeriodAsked(): void
    {
        $this->setConfigValue('accounting_start_date', '2026-10-01');
        $this->saveLegacySale('2026-09-15 10:00:00', '100.00', '15.00');
        $this->saveLegacySale('2026-09-30 23:59:59', '100.00', '15.00');

        $this->assertSame(2, $this->report('2026-09-15', '2026-09-30')->legacyDocuments);
        $this->assertSame(1, $this->report('2026-09-30', '2026-09-30')->legacyDocuments, 'The last day before the books began');
        $this->assertSame(1, $this->report('2026-09-15', '2026-09-15')->legacyDocuments, 'The first day');
        $this->assertSame(0, $this->report('2026-10-01', '2026-10-31')->legacyDocuments, 'The period begins on the first day of the books');
        $this->assertFalse($this->report('2026-10-01', '2026-10-31')->reachesBeforeBooks());
    }

    public function testBuild_CountsTheDocumentsBeforeTheBooksBeganOfTheBranchesOfTheScope(): void
    {
        $this->setConfigValue('accounting_start_date', '2026-10-01');
        $this->saveLegacySale('2026-09-15 10:00:00', '100.00', '15.00', $this->second);
        $this->saveLegacySale('2026-09-16 10:00:00', '100.00', '15.00', 1);
        $this->saveLegacyExpense('2026-09-17 10:00:00', '23.00', '3.00', null);

        $this->assertSame(1, $this->report(scope: $this->scopeOf([$this->second]))->legacyDocuments);
        $this->assertSame(2, $this->report(scope: $this->scopeOf([1]))->legacyDocuments, 'An expense with no branch belongs to the default branch');
        $this->assertSame(3, $this->report()->legacyDocuments);
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

    private function saveLegacySale(string $time, string $net, string $tax, int $branchId = 1): int
    {
        $db      = $this->accountingDb();
        $service = $this->item('0', false);

        $db->table('sales')->insert(['sale_time' => $time, 'employee_id' => $this->employeeId, 'comment' => '', 'sale_status' => COMPLETED, 'sale_type' => SALE_TYPE_INVOICE, 'branch_id' => $branchId]);
        $saleId = (int) $db->insertID();

        $db->table('sales_items')->insert(['sale_id' => $saleId, 'item_id' => $service, 'line' => 0, 'quantity_purchased' => '1', 'item_cost_price' => '0', 'item_unit_price' => $net, 'discount' => '0', 'discount_type' => 0, 'item_location' => 1, 'print_option' => 0]);
        $db->table('sales_items_taxes')->insert([...$this->tax($service, 0, $tax), 'sale_id' => $saleId]);

        return $this->saleRowIds[] = $saleId;
    }

    private function saveLegacyPurchase(string $time): void
    {
        $db   = $this->accountingDb();
        $item = $this->item('0.00');

        $db->table('receivings')->insert(['receiving_time' => $time, 'supplier_id' => $this->supplierId, 'employee_id' => $this->employeeId, 'comment' => '', 'receiving_type' => 'receive', 'branch_id' => 1]);
        $receivingId = (int) $db->insertID();

        $db->table('receivings_items')->insert(['receiving_id' => $receivingId, 'item_id' => $item, 'line' => 0, 'quantity_purchased' => '1', 'item_cost_price' => '0', 'item_unit_price' => '100', 'discount' => '0', 'discount_type' => 0, 'item_location' => 1, 'receiving_quantity' => '1', 'tax_percent' => '15']);

        $this->receivingIds[] = $receivingId;
    }

    private function saveLegacyExpense(string $time, string $gross, string $tax, ?int $branchId = null): void
    {
        $this->accountingDb()->table('expenses')->insert([
            'date'                => $time,
            'amount'              => $gross,
            'tax_amount'          => $tax,
            'payment_type'        => 'Cash',
            'expense_category_id' => $this->category(),
            'description'         => 'Legacy expense',
            'employee_id'         => $this->clerkId,
            'deleted'             => 0,
            'branch_id'           => $branchId,
        ]);
    }
}
