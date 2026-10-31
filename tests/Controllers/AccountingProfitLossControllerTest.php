<?php

namespace Tests\Controllers;

use App\Libraries\Accounting\ProfitAndLoss;
use CodeIgniter\Exceptions\PageNotFoundException;
use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\DatabaseTestTrait;
use CodeIgniter\Test\TestResponse;
use DOMDocument;
use DOMXPath;
use Tests\Support\AccountingControllerFixtureTrait;
use Tests\Support\ExpenseAccountingFixtureTrait;
use Tests\Support\ItemFixtureTrait;
use Tests\Support\PurchaseAccountingFixtureTrait;
use Tests\Support\ReportFixtureTrait;
use Tests\Support\SaleAccountingFixtureTrait;
use Tests\Support\StatementFixtureTrait;
use Tests\Support\TaxReportFixtureTrait;

/**
 * The screen of the profit and loss and its CSV: who may open them, the basis the report says it has
 * and the bases that are refused, the columns, the levels, which branches each adds up for whom, the
 * documents that are paid and the ones that are not, and what comes out in the file.
 *
 * @internal
 */
final class AccountingProfitLossControllerTest extends CIUnitTestCase
{
    use DatabaseTestTrait;
    use AccountingControllerFixtureTrait;
    use ExpenseAccountingFixtureTrait;
    use ItemFixtureTrait;
    use PurchaseAccountingFixtureTrait;
    use ReportFixtureTrait;
    use SaleAccountingFixtureTrait;
    use StatementFixtureTrait;
    use TaxReportFixtureTrait;

    private const SCREEN = '/accounting_statements/profit_loss';

    protected $migrate     = true;
    protected $migrateOnce = true;
    protected $refresh     = false;
    protected $namespace;

    protected function setUp(): void
    {
        parent::setUp();

        $this->startTaxBooks();
    }

    protected function tearDown(): void
    {
        $this->stopTaxBooks();

        parent::tearDown();
    }

    /**
     * @param list<int>|null $branchIds
     * @param list<string>   $extra
     */
    private function viewer(?array $branchIds = null, array $extra = []): int
    {
        $personId = $this->employeeWithGrants(['accounting_reports', ...$extra], $branchIds);

        $this->actAs($personId);

        return $personId;
    }

    private function amountOf(string $value): string
    {
        return to_currency_no_money($value);
    }

    private function pageOf(TestResponse $response): DOMXPath
    {
        $document = new DOMDocument();

        libxml_use_internal_errors(true);
        $document->loadHTML('<?xml encoding="UTF-8">' . $response->getBody());
        libxml_clear_errors();

        return new DOMXPath($document);
    }

    /**
     * @return list<string> The text of the cells that match.
     */
    private function texts(TestResponse $response, string $query): array
    {
        $texts = [];

        foreach ($this->pageOf($response)->query($query) as $node) {
            $texts[] = trim($node->textContent);
        }

        return $texts;
    }

    /**
     * @return list<list<string>>
     */
    private function csvRows(TestResponse $response): array
    {
        $body = $response->response()->getBody();

        $this->assertStringStartsWith("\xEF\xBB\xBF", $body);

        $handle = fopen('php://temp', 'r+b');
        fwrite($handle, substr($body, 3));
        rewind($handle);

        $rows = [];

        while (($row = fgetcsv($handle, 0, ',', '"', '')) !== false) {
            $rows[] = $row;
        }

        fclose($handle);

        return $rows;
    }

    /**
     * @param list<list<string>> $rows
     *
     * @return list<string>|null
     */
    private function rowStartingWith(array $rows, string $cell): ?array
    {
        foreach ($rows as $row) {
            if (($row[0] ?? null) === $cell) {
                return $row;
            }
        }

        return null;
    }

    /**
     * @return list<string> The values of the options that match.
     */
    private function values(TestResponse $response, string $query): array
    {
        $values = [];

        foreach ($this->pageOf($response)->query($query) as $node) {
            $values[] = $node->getAttribute('value');
        }

        return $values;
    }

    private function threeMonths(): void
    {
        $cash = $this->accountId('cash');

        $this->postOn('2026-08-10', 1, [[$cash, '100.00', '0'], [$this->accountId('sales_revenue'), '0', '100.00']]);
        $this->postOn('2026-08-12', 1, [[$this->accountId('cogs'), '40.00', '0'], [$cash, '0', '40.00']]);
        $this->postOn('2026-09-20', 1, [[$this->accountId('sales_returns'), '30.00', '0'], [$cash, '0', '30.00']]);
        $this->postOn('2026-10-05', 1, [[$cash, '400.00', '0'], [$this->accountId('sales_revenue'), '0', '400.00']]);
        $this->postOn('2026-10-07', 1, [[$this->accountIdByCode('545'), '75.00', '0'], [$cash, '0', '75.00']]);
    }

    public function testGetIndex_ShowsTheProfitAndLossByAccrualInMonthsAndSaysItsBasis(): void
    {
        $this->threeMonths();
        $this->viewer();

        $response = $this->get(self::SCREEN . '?from=2026-08-01&to=2026-10-31');

        $response->assertStatus(200);
        $response->assertSee('id="profit_summary"');
        $response->assertSee('data-basis="accrual"');
        $response->assertSee(lang('Accounting_profit_loss.basis_accrual'));
        $response->assertSee(lang('Accounting_profit_loss.basis_note_accrual'));
        $this->assertSame(['2026-08', '2026-09', '2026-10'], $this->texts($response, '//table[@id="profit_summary"]/thead/th[@data-period] | //table[@id="profit_summary"]/thead/tr/th[@data-period]'));
        $this->assertSame([$this->amountOf('60.00'), $this->amountOf('-30.00'), $this->amountOf('325.00'), $this->amountOf('355.00')], $this->texts($response, '//tr[@data-summary="profit"]/td'));
        $this->assertSame([$this->amountOf('100.00'), $this->amountOf('-30.00'), $this->amountOf('400.00'), $this->amountOf('470.00')], $this->texts($response, '//tr[@data-summary="revenue"]/td'));
        $this->assertSame([$this->amountOf('40.00'), $this->amountOf('0.00'), $this->amountOf('75.00'), $this->amountOf('115.00')], $this->texts($response, '//tr[@data-summary="expenses"]/td'));
        $response->assertDontSee('id="report_error"');
    }

    public function testGetIndex_ShowsTheMatrixOfAccountsAndColumnsWithATotalColumn(): void
    {
        $this->threeMonths();
        $this->viewer();

        $response = $this->get(self::SCREEN . '?from=2026-08-01&to=2026-10-31&level=3');

        $this->assertSame([$this->amountOf('100.00'), $this->amountOf('0.00'), $this->amountOf('400.00'), $this->amountOf('500.00')], $this->texts($response, '//table[@id="profit_matrix"]//tr[@data-account="411"]/td[contains(@class, "text-end")]'));
        $this->assertSame([$this->amountOf('0.00'), $this->amountOf('-30.00'), $this->amountOf('0.00'), $this->amountOf('-30.00')], $this->texts($response, '//table[@id="profit_matrix"]//tr[@data-account="412"]/td[contains(@class, "text-end")]'), 'The returns of sales read as a minus inside the revenue');
        $response->assertSee('accounting_reports/ledger?account=' . $this->accountId('sales_revenue'));
    }

    public function testGetIndex_KeepsTheTotalsWhateverTheLevelAndOffersTheLevelsOfTheChart(): void
    {
        $this->threeMonths();
        $this->viewer();

        $shallow = $this->get(self::SCREEN . '?from=2026-08-01&to=2026-10-31&level=1');
        $deep    = $this->get(self::SCREEN . '?from=2026-08-01&to=2026-10-31&level=5');

        $this->assertSame($this->texts($shallow, '//tr[@data-summary]/td'), $this->texts($deep, '//tr[@data-summary]/td'));
        $this->assertLessThan(substr_count($deep->getBody(), 'data-account="'), substr_count($shallow->getBody(), 'data-account="') + 1);
        $shallow->assertSee('<option value="1" selected>');
        $this->assertSame(['1', '2', '3'], $this->values($deep, '//select[@id="f_level"]/option'), 'The levels of the chart the revenue and the expenses reach');
    }

    public function testGetIndex_OffersTheThreeKindsOfColumnsAndShowsTheOneChosen(): void
    {
        $this->threeMonths();
        $this->viewer();

        $quarterly = $this->get(self::SCREEN . '?from=2026-08-01&to=2026-10-31&period=quarterly');
        $yearly    = $this->get(self::SCREEN . '?from=2026-08-01&to=2026-10-31&period=yearly');

        $this->assertSame(['Q3 2026', 'Q4 2026'], $this->texts($quarterly, '//table[@id="profit_summary"]//th[@data-period]'));
        $this->assertSame(['2026'], $this->texts($yearly, '//table[@id="profit_summary"]//th[@data-period]'));
        $quarterly->assertSee('<option value="quarterly" selected>');
        $this->assertSame([$this->amountOf('355.00')], array_slice($this->texts($yearly, '//tr[@data-summary="profit"]/td'), 1));
    }

    public function testGetIndex_ShowsTheCashBasisAndSaysItsBasisAndAssumptions(): void
    {
        $this->viewer();
        $this->customerPays('400.00');

        $response = $this->get(self::SCREEN . '?basis=cash&from=2026-10-01&to=2026-10-31');

        $response->assertStatus(200);
        $response->assertSee('data-basis="cash"');
        $response->assertSee(lang('Accounting_profit_loss.basis_note_cash'));
        $response->assertSee('id="report_cash_note"');
        $response->assertSee(lang('Accounting_profit_loss.cash_assumptions'));
        $response->assertSee('id="report_ties"');
        $this->assertSame([$this->amountOf('400.00')], $this->texts($response, '//tr[@data-line="customers"]/td[@data-cell="amount"]'));
        $this->assertSame([$this->amountOf('400.00')], $this->texts($response, '//tr[@id="cash_profit"]/td'));
        $response->assertDontSee('id="profit_summary"');
        $response->assertDontSee('id="report_error"');
    }

    public function testGetIndex_ReadsPaymentAsAnotherNameOfTheCashBasis(): void
    {
        $this->viewer();

        $response = $this->get(self::SCREEN . '?basis=payment');

        $response->assertSee('data-basis="cash"');
        $response->assertSee('<option value="cash" selected>');
    }

    public function testGetIndex_RefusesTheBasesThatAreNotBuiltByNameAndShowsNoReport(): void
    {
        $this->viewer();

        foreach (['amount', 'paid', 'gross', 'basis[]=cash&basis[]=accrual'] as $basis) {
            $response = $this->get(self::SCREEN . '?' . (str_contains($basis, '=') ? $basis : 'basis=' . $basis));

            $response->assertStatus(200);
            $response->assertSee('id="report_error"');
            $response->assertSee(lang('Accounting.error_profit_basis_unavailable'));
            $response->assertDontSee('id="profit_summary"');
            $response->assertDontSee('id="cash_profit_table"');
            $response->assertDontSee('id="report_basis"');
        }
    }

    public function testGetIndex_CountsWhatTheDocumentsWerePaidOnTheCashBasisAndWhatTheyWereWorthOnTheAccrualBasis(): void
    {
        $sale = $this->sell('1000.00', '150.00');
        $day  = $this->dayOf($sale);

        $this->addPayment($sale, '400.00');

        $purchase = $this->buyTaxed('1', '600.00', '15');
        $this->payPurchase($purchase, '200.00');
        $this->saveExpense($this->expenseData('230.00', '30.00', 'Cash', null, $day . ' 10:00:00'));
        $this->viewer();

        $accrual = $this->get(self::SCREEN . "?from={$day}&to={$day}");
        $cash    = $this->get(self::SCREEN . "?basis=cash&from={$day}&to={$day}");

        $this->assertSame([$this->amountOf('1000.00'), $this->amountOf('1000.00')], $this->texts($accrual, '//tr[@data-summary="revenue"]/td'), 'The invoice is revenue on the day it is issued whether or not it was paid');
        $this->assertSame([$this->amountOf('200.00'), $this->amountOf('200.00')], $this->texts($accrual, '//tr[@data-summary="expenses"]/td'));

        $this->assertSame([$this->amountOf('400.00')], $this->texts($cash, '//tr[@data-line="customers"]/td[@data-cell="amount"]'), 'Only what the customer paid');
        $this->assertSame([$this->amountOf('200.00')], $this->texts($cash, '//tr[@data-line="suppliers"]/td[@data-cell="amount"]'));
        $this->assertSame([$this->amountOf('30.00')], $this->texts($cash, '//tr[@data-line="tax_input"]/td[@data-cell="amount"]'), 'The tax of the expense that was paid, not the tax of the purchase that was not');
        $this->assertSame([$this->amountOf('-30.00')], $this->texts($cash, '//tr[@id="cash_profit"]/td'));
        $this->assertSame([$this->amountOf('-30.00')], $this->texts($cash, '//tr[@data-total="cash_change"]/td'), 'The profit and the change of the treasuries are the same here');
        $cash->assertSee('id="report_ties"');
    }

    public function testGetIndex_CutsWhatIsAskedDownToTheBranchesTheEmployeeWorksIn(): void
    {
        $this->cashSale('2026-10-05', 1, '100.00');
        $this->cashSale('2026-10-06', $this->second, '30.00');
        $this->viewer([1]);

        $response = $this->get(self::SCREEN . '?from=2026-10-01&to=2026-10-31&branches[]=1&branches[]=' . $this->second);

        $this->assertSame([$this->amountOf('100.00'), $this->amountOf('100.00')], $this->texts($response, '//tr[@data-summary="revenue"]/td'));
        $response->assertDontSee($this->amountOf('130.00'));
    }

    public function testGetIndex_SaysTheTotalIsOfTheBranchesTheEmployeeWorksInAndOffersTheBranchesToWhoWorksInSeveral(): void
    {
        $this->createBranch('T3', 'Third branch');
        $this->cashSale('2026-10-05', 1, '100.00');
        $this->viewer([1, $this->second]);

        $response = $this->get(self::SCREEN . '?from=2026-10-01&to=2026-10-31');

        $first = (string) $this->accountingDb()->table('branches')->where('branch_id', 1)->get()->getRow()->code;

        $response->assertSee('id="report_scope"');
        $response->assertSee(lang('Accounting_reports.scope_partial', [implode(lang('Accounting_reports.branch_separator'), [$first, 'T2'])]));
        $response->assertSee('id="f_branches"');
    }

    public function testGetIndex_AddsUpTheBranchesAskedForOnTheCashBasisToo(): void
    {
        $this->postOn('2026-10-05', 1, [[$this->accountId('cash'), '100.00', '0'], [$this->accountIdByCode('4201'), '0', '100.00']]);
        $this->postOn('2026-10-06', $this->second, [[$this->accountId('cash'), '7.00', '0'], [$this->accountIdByCode('4201'), '0', '7.00']]);
        $this->viewer();

        $response = $this->get(self::SCREEN . '?basis=cash&from=2026-10-01&to=2026-10-31&branches[]=' . $this->second);

        $this->assertSame([$this->amountOf('7.00')], $this->texts($response, '//tr[@id="cash_profit"]/td'));
    }

    public function testGetIndex_ShowsAMessageForADayThatIsNotOneAndForAStartAfterTheEndAndStillShowsAReport(): void
    {
        $this->viewer();

        foreach (['from=2026-02-31&to=2026-03-01', 'from=2026-10-31&to=2026-10-01'] as $query) {
            $response = $this->get(self::SCREEN . '?' . $query);

            $response->assertStatus(200);
            $response->assertSee('id="report_error"');
            $response->assertSee('id="profit_summary"');
        }
    }

    public function testGetIndex_RefusesARangeOfMoreColumnsThanTheReportDrawsInWords(): void
    {
        $this->viewer();

        $response = $this->get(self::SCREEN . '?from=2020-01-01&to=2026-12-31&period=monthly');

        $response->assertStatus(200);
        $response->assertSee(lang('Accounting.error_report_too_many_periods', [ProfitAndLoss::MAX_PERIODS]));
        $response->assertDontSee('id="profit_summary"');
    }

    public function testGetIndex_RedirectsAUserWithoutTheModuleGrantAndIsNotFoundWhileAccountingIsOff(): void
    {
        $this->actAs($this->employeeWithGrants(['accounting_payables']));

        $this->get(self::SCREEN)->assertRedirect();

        $this->setConfigValue('accounting_enabled', '0');
        $this->viewer();

        $this->expectException(PageNotFoundException::class);

        $this->get(self::SCREEN);
    }

    public function testGetIndex_LinksTheOtherStatementsAndTheFileWithTheFiltersItWasAsked(): void
    {
        $this->viewer();

        $response = $this->get(self::SCREEN . '?basis=cash&from=2026-10-01&to=2026-10-31');

        $response->assertSee('id="link_income_statement" href="' . base_url('accounting_statements/income_statement') . '"');
        $response->assertSee('id="link_cash_flow" href="' . base_url('accounting_statements/cash_flow') . '"');
        $response->assertSee('profit_loss/csv?basis=cash&amp;from=2026-10-01&amp;to=2026-10-31');
    }

    public function testGetCsv_GivesTheSummaryAndTheMatrixOfTheScreenAndSaysTheBasisInTheFile(): void
    {
        $this->threeMonths();
        $this->viewer();

        $response = $this->get(self::SCREEN . '/csv?from=2026-08-01&to=2026-10-31&level=3');

        $response->assertStatus(200);
        $this->assertStringContainsString('text/csv', $response->response()->getHeaderLine('Content-Type'));
        $this->assertStringContainsString('filename="profit_loss_accrual_2026-08-01_2026-10-31.csv"', $response->response()->getHeaderLine('Content-Disposition'));

        $rows = $this->csvRows($response);

        $this->assertSame([lang('Accounting_profit_loss.basis'), lang('Accounting_profit_loss.basis_accrual')], $rows[0], 'The basis is the first thing the file says');
        $this->assertSame(['60.0000', '-30.0000', '325.0000', '355.0000'], array_slice($this->rowStartingWith($rows, lang('Accounting_profit_loss.total_profit')), 1));
        $this->assertSame(['100.0000', '0.0000', '400.0000', '500.0000'], array_slice($this->rowStartingWith($rows, '411 ' . $this->accountNameOf('411')), 1));
        $this->assertSame([lang('Accounting_profit_loss.summary'), '2026-08', '2026-09', '2026-10', lang('Accounting_profit_loss.total')], $this->rowStartingWith($rows, lang('Accounting_profit_loss.summary')));
    }

    private function accountNameOf(string $code): string
    {
        return (string) $this->accountingDb()->table('accounting_accounts')->select('name')->where('code', $code)->get()->getRow()->name;
    }

    public function testGetCsv_GivesTheCashBasisWithItsOtherMovementsAndTheCheck(): void
    {
        $this->postOn('2026-10-02', 1, [[$this->accountId('cash'), '1000.00', '0'], [$this->accountIdByCode('31'), '0', '1000.00']]);
        $this->postOn('2026-10-03', 1, [[$this->accountId('cash'), '115.00', '0'], [$this->accountIdByCode('4201'), '0', '100.00'], [$this->accountId('tax_output'), '0', '15.00']]);
        $this->viewer();

        $rows = $this->csvRows($this->get(self::SCREEN . '/csv?basis=payment&from=2026-10-01&to=2026-10-31'));

        $this->assertSame([lang('Accounting_profit_loss.basis'), lang('Accounting_profit_loss.basis_cash')], $rows[0]);
        $this->assertSame('100.0000', $this->rowStartingWith($rows, '4201 ' . $this->accountNameOf('4201'))[1]);
        $this->assertSame('100.0000', $this->rowStartingWith($rows, lang('Accounting_profit_loss.total_profit'))[1]);
        $this->assertSame('1015.0000', $this->rowStartingWith($rows, lang('Accounting_profit_loss.other_total'))[1]);
        $this->assertSame('1115.0000', $this->rowStartingWith($rows, lang('Accounting_profit_loss.cash_change'))[1]);
        $this->assertNotNull($this->rowStartingWith($rows, lang('Accounting_profit_loss.ties')));
    }

    public function testGetCsv_RefusesTheBasesThatAreNotBuiltInWordsAndSavesNoFile(): void
    {
        $this->viewer();

        foreach (['amount', 'paid', 'other'] as $basis) {
            $response = $this->get(self::SCREEN . '/csv?basis=' . $basis);

            $response->assertStatus(422);
            $this->assertSame(lang('Accounting.error_profit_basis_unavailable'), $response->response()->getBody());
        }

        $response = $this->get(self::SCREEN . '/csv?basis[]=cash&basis[]=accrual');

        $response->assertStatus(422);
    }

    public function testGetCsv_RefusesAPeriodThatEndsBeforeItStartsAndARangeThatIsTooLong(): void
    {
        $this->viewer();

        $this->get(self::SCREEN . '/csv?from=2026-10-31&to=2026-10-01')->assertStatus(422);

        $response = $this->get(self::SCREEN . '/csv?from=2020-01-01&to=2026-12-31');

        $response->assertStatus(422);
        $this->assertSame(lang('Accounting.error_report_too_many_periods', [ProfitAndLoss::MAX_PERIODS]), $response->response()->getBody());
    }

    public function testGetCsv_AddsUpOnlyTheBranchesTheEmployeeWorksInWhateverTheRequestAsks(): void
    {
        $this->cashSale('2026-10-05', 1, '100.00');
        $this->cashSale('2026-10-06', $this->second, '4000.00');
        $this->viewer([1]);

        foreach (['', 'basis=cash&'] as $basis) {
            $rows = $this->csvRows($this->get(self::SCREEN . '/csv?' . $basis . 'from=2026-10-01&to=2026-10-31&branches[]=1&branches[]=' . $this->second));

            $this->assertSame('100.0000', $this->rowStartingWith($rows, lang('Accounting_profit_loss.total_profit'))[count($this->rowStartingWith($rows, lang('Accounting_profit_loss.total_profit'))) - 1], "Basis '{$basis}'");
            $this->assertSame((string) $this->accountingDb()->table('branches')->where('branch_id', 1)->get()->getRow()->code, $this->rowStartingWith($rows, lang('Accounting_reports.csv_branches'))[1]);
        }
    }

    public function testGetCsv_SaysTheTotalIsNotTheCompanysToSomeoneWhoReadsSomeOfTheBranchesInBothBases(): void
    {
        $this->createBranch('T3', 'Third branch');
        $this->cashSale('2026-10-05', 1, '100.00');
        $this->viewer([1, $this->second]);

        $first = (string) $this->accountingDb()->table('branches')->where('branch_id', 1)->get()->getRow()->code;

        foreach (['', 'basis=cash&'] as $basis) {
            $rows = $this->csvRows($this->get(self::SCREEN . '/csv?' . $basis . 'from=2026-10-01&to=2026-10-31'));

            $this->assertNotNull($this->rowStartingWith($rows, lang('Accounting_reports.scope_partial', [implode(lang('Accounting_reports.branch_separator'), [$first, 'T2'])])), "Basis '{$basis}'");
            $this->assertNull($this->rowStartingWith($rows, lang('Accounting_reports.scope_company')));
        }

        $this->viewer();

        foreach (['', 'basis=cash&'] as $basis) {
            $rows = $this->csvRows($this->get(self::SCREEN . '/csv?' . $basis . 'from=2026-10-01&to=2026-10-31'));

            $this->assertNotNull($this->rowStartingWith($rows, lang('Accounting_reports.scope_company')), "Basis '{$basis}'");
        }
    }

    public function testGetIndex_SaysThatTheCashProfitAndTheOtherMovementsDoNotAddUpToWhatTheTreasuriesGainedAndSaysSoInTheFile(): void
    {
        $this->postOn('2026-10-03', 1, [[$this->accountId('cash'), '100.00', '0'], [$this->accountIdByCode('4201'), '0', '100.00']]);
        $this->viewer();

        $address = self::SCREEN . '?basis=cash&from=2026-10-01&to=2026-10-31';

        $this->get($address)->assertSee('id="report_ties"');

        $this->accountingDb()->query('UPDATE ' . $this->accountingDb()->prefixTable('accounting_journal_lines') . ' SET debit = debit + 5 WHERE account_id = ' . $this->accountId('cash') . ' LIMIT 1');

        $screen = $this->get($address);

        $screen->assertSee('id="report_differs"');
        $screen->assertSee(lang('Accounting_profit_loss.differs'));
        $screen->assertDontSee('id="report_ties"');

        $rows = $this->csvRows($this->get(self::SCREEN . '/csv?basis=cash&from=2026-10-01&to=2026-10-31'));

        $this->assertNotNull($this->rowStartingWith($rows, lang('Accounting_profit_loss.differs')));
        $this->assertNull($this->rowStartingWith($rows, lang('Accounting_profit_loss.ties')));
    }

    public function testGetCsv_RedirectsAUserWithoutTheModuleGrantAndIsNotFoundWhileAccountingIsOff(): void
    {
        $this->actAs($this->employeeWithGrants(['accounting_payables']));

        $this->get(self::SCREEN . '/csv')->assertRedirect();

        $this->setConfigValue('accounting_enabled', '0');
        $this->viewer();

        $this->expectException(PageNotFoundException::class);

        $this->get(self::SCREEN . '/csv');
    }

    private function customerPays(string $amount): void
    {
        $this->postOn('2026-10-05', 1, [[$this->accountId('cash'), $amount, '0'], [$this->accountId('counter_customers'), '0', $amount]]);
    }
}
