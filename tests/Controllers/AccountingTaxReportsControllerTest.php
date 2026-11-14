<?php

namespace Tests\Controllers;

use App\Libraries\Accounting\RoutingKey;
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
use Tests\Support\SaleAccountingFixtureTrait;
use Tests\Support\StatementFixtureTrait;
use Tests\Support\TaxReportFixtureTrait;

/**
 * The screen of the tax report and its CSV: who may open them, which branches each adds up for
 * whom, the basis that is refused, the days before the books began, and what comes out in the file.
 *
 * @internal
 */
final class AccountingTaxReportsControllerTest extends CIUnitTestCase
{
    use DatabaseTestTrait;
    use AccountingControllerFixtureTrait;
    use ExpenseAccountingFixtureTrait;
    use ItemFixtureTrait;
    use SaleAccountingFixtureTrait;
    use PurchaseAccountingFixtureTrait;
    use StatementFixtureTrait;
    use TaxReportFixtureTrait;

    private const SCREEN = '/accounting_reports/tax';

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

    private function sectionCell(TestResponse $response, string $section, string $cell): string
    {
        preg_match('#<tbody data-section="' . $section . '">.*?data-cell="' . $cell . '"[^>]*><bdi dir="ltr">([^<]*)</bdi>#s', $response->getBody(), $found);

        return $found[1] ?? '';
    }

    private function totalCell(TestResponse $response, string $total, string $cell): string
    {
        preg_match('#data-total="' . $total . '"[^>]*>.*?data-cell="' . $cell . '"[^>]*><bdi dir="ltr">([^<]*)</bdi>#s', $response->getBody(), $found);

        return $found[1] ?? '';
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
     * @param list<list<string>> $rows
     *
     * @return list<string> The row that says whether the net is payable or refundable.
     */
    private function netRowOf(array $rows): array
    {
        $first = array_search(lang('Accounting_tax_reports.row_total'), array_column($rows, 0), true);

        $this->assertNotFalse($first);

        return $rows[$first + 2];
    }

    private function accountNameOf(string $code): string
    {
        return (string) $this->accountingDb()->table('accounting_accounts')->select('name')->where('code', $code)->get()->getRow()->name;
    }

    private function accountCell(TestResponse $response, int $accountId, string $cell): string
    {
        preg_match('#data-account="' . $accountId . '">.*?data-cell="' . $cell . '"[^>]*><bdi dir="ltr">([^<]*)</bdi>#s', $response->getBody(), $found);

        return $found[1] ?? '';
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

    public function testGetIndex_ShowsTheTaxOfTheBooksToWhoeverHoldsTheModule(): void
    {
        $this->observedScenario();
        $this->viewer();

        $response = $this->get(self::SCREEN);

        $response->assertStatus(200);
        $response->assertSee('id="tax_report"');
        $response->assertSee('id="tax_table"');
        $this->assertSame($this->amountOf('2210.00'), $this->sectionCell($response, 'sales', 'base'));
        $this->assertSame($this->amountOf('331.50'), $this->sectionCell($response, 'sales', 'tax'));
        $this->assertSame($this->amountOf('-220.00'), $this->sectionCell($response, 'sales_returns', 'base'));
        $this->assertSame($this->amountOf('-33.00'), $this->sectionCell($response, 'sales_returns', 'tax'));
        $this->assertSame($this->amountOf('0.00'), $this->sectionCell($response, 'purchases', 'tax'));
        $this->assertSame($this->amountOf('298.50'), $this->totalCell($response, 'output', 'tax'));
        $this->assertSame($this->amountOf('1990.00'), $this->totalCell($response, 'output', 'base'));
        $this->assertSame($this->amountOf('298.50'), $this->totalCell($response, 'net', 'tax'));
        $response->assertSee('data-direction="payable"');
        $response->assertSee(lang('Accounting_tax_reports.net_payable'));
        $response->assertSee('id="report_ties"');
        $response->assertSee('data-status="ties"');
        $response->assertDontSee('id="report_error"');
        $response->assertDontSee('id="report_differs"');
        $response->assertDontSee('id="report_legacy"');
        $response->assertSee(lang('Accounting_reports.scope_company'));
        $response->assertSee('window.print()');
        $response->assertSee('id="export_csv"');
        $response->assertSee('id="report_basis"');
    }

    public function testGetIndex_ShowsEverySectionWithItsOwnSignAndTheRefundableTaxWhenMoreWasPaid(): void
    {
        $invoice = $this->sell('1000.00', '150.00');
        $this->takeBack($invoice, '100.00', '15.00');
        $purchase = $this->buyTaxed('10', '100', '15');
        $this->giveBackTaxed($purchase, '2', '15');
        $this->spend('230.00', '30.00');
        $this->viewer();

        $response = $this->get(self::SCREEN);

        $this->assertSame($this->amountOf('-1000.00'), $this->sectionCell($response, 'purchases', 'base'));
        $this->assertSame($this->amountOf('-150.00'), $this->sectionCell($response, 'purchases', 'tax'));
        $this->assertSame($this->amountOf('200.00'), $this->sectionCell($response, 'purchase_returns', 'base'));
        $this->assertSame($this->amountOf('30.00'), $this->sectionCell($response, 'purchase_returns', 'tax'));
        $this->assertSame($this->amountOf('-200.00'), $this->sectionCell($response, 'expenses', 'base'));
        $this->assertSame($this->amountOf('-30.00'), $this->sectionCell($response, 'expenses', 'tax'));
        $this->assertSame($this->amountOf('135.00'), $this->totalCell($response, 'output', 'tax'));
        $this->assertSame($this->amountOf('150.00'), $this->totalCell($response, 'input', 'tax'));
        $this->assertSame($this->amountOf('-15.00'), $this->totalCell($response, 'net', 'tax'));
        $response->assertSee('data-direction="refundable"');
        $response->assertSee(lang('Accounting_tax_reports.net_refundable'));
    }

    public function testGetIndex_RequiresTheModuleGrant(): void
    {
        $this->observedScenario();
        $this->actAs($this->employeeWithGrants(['accounting_payables']));

        $this->get(self::SCREEN)->assertRedirect();
    }

    public function testGetIndex_RequiresAccountingToBeOn(): void
    {
        $this->viewer();
        $this->setConfigValue('accounting_enabled', '0');

        $this->expectException(PageNotFoundException::class);

        $this->get(self::SCREEN);
    }

    public function testGetIndex_AddsUpTheBranchesAskedFor(): void
    {
        $depot = $this->locationOfBranch($this->second, 'Second depot');

        $this->sell('100.00', '15.00');
        $this->sell('200.00', '30.00', null, $depot);
        $this->viewer();

        $response = $this->get(self::SCREEN . '?branches[]=' . $this->second);

        $this->assertSame($this->amountOf('200.00'), $this->sectionCell($response, 'sales', 'base'));
        $this->assertSame($this->amountOf('30.00'), $this->totalCell($response, 'output', 'tax'));
        $response->assertSee(lang('Accounting_reports.scope_selected', ['T2']));

        $all = $this->get(self::SCREEN);

        $this->assertSame($this->amountOf('300.00'), $this->sectionCell($all, 'sales', 'base'));
        $this->assertSame($this->amountOf('45.00'), $this->totalCell($all, 'output', 'tax'));
    }

    public function testGetIndex_CutsWhatIsAskedDownToTheBranchesTheEmployeeWorksIn(): void
    {
        $depot = $this->locationOfBranch($this->second, 'Second depot');

        $this->sell('100.00', '15.00');
        $this->sell('200.00', '30.00', null, $depot);
        $this->viewer([1]);

        $response = $this->get(self::SCREEN . '?branches[]=1&branches[]=' . $this->second);

        $this->assertSame($this->amountOf('100.00'), $this->sectionCell($response, 'sales', 'base'));
        $this->assertSame($this->amountOf('15.00'), $this->totalCell($response, 'output', 'tax'));
    }

    public function testGetIndex_ShowsWhoWorksInSomeBranchesThatTheTotalIsOfThoseAndNotOfTheCompany(): void
    {
        $this->sell('100.00', '15.00');
        $third = $this->createBranch('T3', 'Third branch');
        $this->viewer([1, $this->second]);

        $partial = $this->get(self::SCREEN);

        $partial->assertSee('<p class="alert alert-warning" id="report_scope"');
        $partial->assertSee(lang('Accounting_reports.scope_partial', [implode(lang('Accounting_reports.branch_separator'), ['HQ', 'T2'])]));
        $partial->assertDontSee(lang('Accounting_reports.scope_company'));

        $this->viewer([1, $third]);

        $this->get(self::SCREEN)->assertSee(lang('Accounting_reports.scope_partial', [implode(lang('Accounting_reports.branch_separator'), ['HQ', 'T3'])]));
    }

    public function testGetIndex_ShowsNoBranchChoiceAndNoScopeNoteToWhoWorksInOneBranch(): void
    {
        $depot = $this->locationOfBranch($this->second, 'Second depot');

        $this->sell('100.00', '15.00');
        $this->sell('200.00', '30.00', null, $depot);
        $this->viewer([$this->second]);

        $response = $this->get(self::SCREEN);

        $response->assertDontSee('id="f_branches"');
        $response->assertDontSee('id="report_scope"');
        $this->assertSame($this->amountOf('200.00'), $this->sectionCell($response, 'sales', 'base'));
    }

    public function testGetIndex_OffersTheBranchesToWhoReadsMoreThanOne(): void
    {
        $this->viewer();

        $this->get(self::SCREEN)->assertSee('id="f_branches"');
    }

    public function testGetIndex_ShowsNothingToAddUpToWhoWorksInNoBranch(): void
    {
        $this->sell('100.00', '15.00');
        $this->viewer([]);

        $response = $this->get(self::SCREEN);

        $response->assertSee('id="report_no_branches"');
        $this->assertSame($this->amountOf('0.00'), $this->totalCell($response, 'net', 'tax'));
    }

    public function testGetIndex_ShowsTheCashBasisAsDisabledAndSaysWhy(): void
    {
        $this->viewer();

        $response = $this->get(self::SCREEN);

        $response->assertSee('<option value="accrual" selected>');
        $response->assertSee('<option value="cash" disabled>');
        $response->assertSee('<option value="partial" disabled>');
        $response->assertSee('id="basis_note_cash"');
        $response->assertSee(lang('Accounting_tax_reports.basis_cash_note'));
    }

    public function testGetIndex_RefusesARequestForAnotherBasisInWordsAndShowsNoFigures(): void
    {
        $this->observedScenario();
        $this->viewer();

        foreach (['cash', 'partial', 'nonsense'] as $basis) {
            $response = $this->get(self::SCREEN . '?basis=' . $basis);

            $response->assertStatus(200);
            $response->assertSee('id="report_error"');
            $response->assertSee(lang('Accounting.error_tax_basis_unavailable'));
            $response->assertDontSee('id="tax_table"');
            $response->assertDontSee('id="export_csv"');
        }
    }

    public function testGetIndex_RefusesABasisNamedAsAListAndShowsNoFigures(): void
    {
        $this->observedScenario();
        $this->viewer();

        foreach (['basis[]=cash', 'basis[0]=partial', 'basis[]=accrual&basis[]=cash'] as $query) {
            $response = $this->get(self::SCREEN . '?' . $query);

            $response->assertStatus(200);
            $response->assertSee('id="report_error"');
            $response->assertSee(lang('Accounting.error_tax_basis_unavailable'));
            $response->assertDontSee('id="tax_table"');
        }
    }

    public function testGetIndex_AcceptsTheAccrualBasisWhenItIsAskedFor(): void
    {
        $this->observedScenario();
        $this->viewer();

        $response = $this->get(self::SCREEN . '?basis=accrual');

        $response->assertSee('id="tax_table"');
        $response->assertDontSee('id="report_error"');
    }

    public function testGetIndex_TakesThePeriodFromTheFiltersAndShowsItBack(): void
    {
        $saleId = $this->sell('100.00', '15.00');
        $day    = $this->dayOf($saleId);
        $before = date('Y-m-d', strtotime($day . ' -1 day'));
        $after  = date('Y-m-d', strtotime($day . ' +1 day'));
        $this->viewer();

        $inside  = $this->get(self::SCREEN . '?from=' . $day . '&to=' . $day);
        $outside = $this->get(self::SCREEN . '?from=' . $after . '&to=' . $after);
        $earlier = $this->get(self::SCREEN . '?to=' . $before);

        $this->assertSame($this->amountOf('15.00'), $this->totalCell($inside, 'output', 'tax'));
        $inside->assertSee('id="f_from" name="from" class="form-control form-control-sm" value="' . $day . '"');
        $inside->assertSee('id="report_period"');
        $this->assertSame($this->amountOf('0.00'), $this->totalCell($outside, 'output', 'tax'));
        $this->assertSame($this->amountOf('0.00'), $this->totalCell($earlier, 'output', 'tax'));
    }

    public function testGetIndex_ShowsADayThatIsNotADateAsAnErrorAndAddsUpTheDefaultPeriod(): void
    {
        $this->sell('100.00', '15.00');
        $this->viewer();

        $response = $this->get(self::SCREEN . '?to=2026-02-30');

        $response->assertSee('id="report_error"');
        $response->assertSee(lang('Accounting.error_invalid_date'));
        $response->assertSee('value="2026-02-30"');
        $this->assertSame($this->amountOf('15.00'), $this->totalCell($response, 'output', 'tax'));
    }

    public function testGetIndex_ShowsAPeriodThatEndsBeforeItStartsAsAnError(): void
    {
        $this->viewer();

        $response = $this->get(self::SCREEN . '?from=2026-10-31&to=2026-10-01');

        $response->assertSee('id="report_error"');
        $response->assertSee(lang('Accounting.error_report_period_invalid'));
    }

    public function testGetIndex_ShowsASaleThatWasCancelledAsNothing(): void
    {
        $service = $this->item('0', false);
        $saleId  = $this->save([$this->line($service, 0, '1', '100.00')], [$this->tax($service, 0, '15.00')], [$this->payment(lang('Sales.cash'), '115.00')], SALE_TYPE_POS);

        $this->assertTrue($this->sales->delete($saleId, false, true, $this->employeeId));
        $this->viewer();

        $response = $this->get(self::SCREEN);

        $this->assertSame($this->amountOf('0.00'), $this->sectionCell($response, 'sales', 'tax'));
        $this->assertSame($this->amountOf('0.00'), $this->totalCell($response, 'net', 'tax'));
        $response->assertSee('id="report_ties"');
    }

    public function testGetIndex_SaysTheBooksBeganOnTheStartDateAndHowManyDocumentsBeforeItAreLeftOut(): void
    {
        $this->setConfigValue('accounting_start_date', '2026-10-01');
        $this->saveLegacySale('2026-09-15 10:00:00', '100.00', '15.00');
        $this->saveLegacyPurchase('2026-09-20 10:00:00');
        $this->viewer();

        $response = $this->get(self::SCREEN . '?from=2026-10-01');

        $response->assertSee('id="report_books_start"');
        $response->assertSee(lang('Accounting_tax_reports.start_note', [to_date(strtotime('2026-10-01'))]));
        $response->assertDontSee('id="report_legacy"');
        $response->assertDontSee(lang('Accounting_tax_reports.start_reaches'));

        $before = $this->get(self::SCREEN);

        $before->assertSee('id="report_legacy"');
        $before->assertSee(lang('Accounting_tax_reports.legacy_documents', [2]));
        $before->assertSee(lang('Accounting_tax_reports.start_reaches'));
        $before->assertSee('alert alert-warning');
    }

    public function testGetIndex_ShowsWhatTheAccountsHoldFromAManualJournalApartFromTheTax(): void
    {
        $this->observedScenario();
        $this->postManual('215001', '100.00', '0');
        $this->viewer();

        $response = $this->get(self::SCREEN);
        $account  = $this->accountIdByCode('215001');

        $this->assertSame($this->amountOf('198.50'), $this->accountCell($response, $account, 'movement'));
        $this->assertSame($this->amountOf('298.50'), $this->accountCell($response, $account, 'reported'));
        $this->assertSame($this->amountOf('-100.00'), $this->accountCell($response, $account, 'other'));
        $response->assertSee('data-status="differs"');
        $response->assertSee('id="report_differs"');
        $response->assertDontSee('id="report_ties"');
        $this->assertSame($this->amountOf('298.50'), $this->totalCell($response, 'net', 'tax'));
    }

    public function testGetIndex_ShowsTheRatesAndTheDocumentsThatTookADiscountOnTheWhole(): void
    {
        $cycle = $this->discountedReferenceInvoice();

        $this->assertGreaterThan(0, $this->save($cycle['items'], $cycle['taxes'], [], SALE_TYPE_INVOICE, -1, true, null, COMPLETED, NEW_ENTRY, $cycle['discount']));
        $this->viewer();

        $response = $this->get(self::SCREEN);

        $response->assertSee('data-rate="15"');
        $response->assertSee('data-row="discounted"');
        $response->assertSee(lang('Accounting_tax_reports.discounted_row'));
        $response->assertSee(lang('Accounting_tax_reports.rate_row', ['15']));
        $this->assertSame($this->amountOf('639.00'), $this->sectionCell($response, 'sales', 'base'));
        $this->assertSame($this->amountOf('95.85'), $this->sectionCell($response, 'sales', 'tax'));
    }

    public function testGetIndex_SaysWhenNoAccountIsRoutedForTheTax(): void
    {
        $this->sell('100.00', '15.00');
        $this->accountingDb()->table('accounting_routing')->whereIn('routing_key', [RoutingKey::TaxOutput->value, RoutingKey::TaxInput->value])->delete();
        $this->viewer();

        $response = $this->get(self::SCREEN);

        $response->assertSee('id="report_not_routed_output"');
        $response->assertSee('id="report_not_routed_input"');
        $response->assertDontSee('id="tax_accounts"');
    }

    public function testGetIndex_SaysHowManyDocumentsHaveLinesThatNoLongerSayWhatTheirJournalSays(): void
    {
        $service = $this->item('0', false);
        $saleId  = $this->save([$this->line($service, 0, '1', '100.00')], [$this->tax($service, 0, '15.00')]);

        $this->accountingDb()->table('sales_items_taxes')->where('sale_id', $saleId)->update(['item_tax_amount' => '9.00']);
        $this->viewer();

        $response = $this->get(self::SCREEN);

        $response->assertSee('id="report_unmatched"');
        $response->assertSee(lang('Accounting_tax_reports.unmatched', [1]));
        $this->assertSame($this->amountOf('15.00'), $this->sectionCell($response, 'sales', 'tax'));
    }

    public function testGetIndex_LinksEachTaxAccountToItsStatementWithTheDaysAndBranchesAsked(): void
    {
        $this->observedScenario();
        $this->viewer();

        $response = $this->get(self::SCREEN . '?from=2026-01-01&to=2035-12-31&branches[]=1');

        $response->assertSee('accounting_reports/ledger?account=' . $this->accountIdByCode('215001') . '&amp;from=2026-01-01&amp;to=2035-12-31&amp;branches%5B0%5D=1');
        $response->assertSee('accounting_reports/ledger?account=' . $this->accountIdByCode('215002'));

        $plain = $this->get(self::SCREEN);

        $plain->assertSee('accounting_reports/ledger?account=' . $this->accountIdByCode('215001') . '&amp;to=' . date('Y-m-d') . '"');
    }

    public function testGetIndex_LinksTheCsvWithTheFiltersAsked(): void
    {
        $this->observedScenario();
        $this->viewer();

        $plain = $this->get(self::SCREEN);
        $asked = $this->get(self::SCREEN . '?from=2026-01-01&to=2035-12-31&branches[]=1');

        $plain->assertSee('href="' . base_url('accounting_reports/tax/csv') . '"');
        $asked->assertSee('tax/csv?from=2026-01-01&amp;to=2035-12-31&amp;branches%5B0%5D=1');
    }

    public function testGetIndex_IsReachedFromTheTrialBalanceAndTheLedger(): void
    {
        $this->viewer();

        $this->get('/accounting_reports')->assertSee('id="tax_report_link"');
        $this->get('/accounting_reports/ledger')->assertSee('id="tax_report_link"');
    }

    public function testGetIndex_EscapesTheNamesAndTheCodesOfTheAccounts(): void
    {
        $this->observedScenario();
        $this->accountingDb()->table('accounting_accounts')->where('code', '215001')->update(['name' => '<script>alert(1)</script>']);
        $this->accountingDb()->table('accounting_accounts')->where('code', '215002')->update(['code' => '<b>215002</b>']);
        $this->viewer();

        $body = $this->get(self::SCREEN)->response()->getBody();

        $this->assertStringNotContainsString('<script>alert(1)</script>', $body);
        $this->assertStringContainsString('&lt;script&gt;alert(1)&lt;/script&gt;', $body);
        $this->assertStringNotContainsString('<b>215002</b>', $body);
        $this->assertStringContainsString('&lt;b&gt;215002&lt;/b&gt;', $body);
    }

    public function testGetIndex_EscapesWhatTheRequestAndTheBranchesPutInThePage(): void
    {
        $this->accountingDb()->table('branches')->where('branch_id', $this->second)->update(['code' => 'T<u>2', 'name' => '<i>Annex</i>']);
        $this->createBranch('T3', 'Third branch');
        $this->viewer([1, $this->second]);

        $from  = '"><script>alert(1)</script>';
        $to    = '"><img src=x onerror=alert(2)>';
        $xpath = $this->pageOf($this->get(self::SCREEN . '?' . http_build_query(['from' => $from, 'to' => $to])));

        $this->assertSame($from, $xpath->query('//input[@id="f_from"]')->item(0)->getAttribute('value'), 'The day that is not one is shown back as it was typed');
        $this->assertSame($to, $xpath->query('//input[@id="f_to"]')->item(0)->getAttribute('value'));
        $this->assertSame(0, $xpath->query('//script[contains(., "alert(1)")]')->length, 'A day is never a script');
        $this->assertSame(0, $xpath->query('//img[@src="x"]')->length, 'A day never closes its attribute');

        $this->assertSame(0, $xpath->query('//fieldset[@id="f_branches"]//u | //fieldset[@id="f_branches"]//i')->length, 'A branch to choose is text');
        $this->assertStringContainsString('T<u>2 - <i>Annex</i>', $xpath->query('//fieldset[@id="f_branches"]')->item(0)->textContent);

        $this->assertSame(0, $xpath->query('//p[@id="report_scope"]//u')->length, 'The partial scope names its branches as text');
        $this->assertStringContainsString('HQ, T<u>2', $xpath->query('//p[@id="report_scope"]')->item(0)->textContent);
    }

    public function testGetIndex_EscapesTheBranchesOfASelectedScope(): void
    {
        $this->accountingDb()->table('branches')->where('branch_id', $this->second)->update(['code' => 'T<u>2']);
        $this->createBranch('T3', 'Third branch');
        $this->viewer();

        $xpath = $this->pageOf($this->get(self::SCREEN . '?branches[]=1&branches[]=' . $this->second));

        $this->assertSame(0, $xpath->query('//p[@id="report_scope"]//u')->length);
        $this->assertStringContainsString('HQ, T<u>2', $xpath->query('//p[@id="report_scope"]')->item(0)->textContent);
    }

    public function testGetIndex_SaysTheBooksHoldNoTaxWhenThePeriodHasNone(): void
    {
        $this->viewer();

        $response = $this->get(self::SCREEN);

        $response->assertSee('id="report_empty"');
        $response->assertSee(lang('Accounting_tax_reports.empty'));
        $response->assertSee('id="tax_table"');
    }

    public function testGetIndex_SaysNothingOfAnEmptyPeriodWhenThereIsTaxOrNoBranchOrNoAccount(): void
    {
        $this->sell('100.00', '15.00');
        $this->viewer();

        $this->get(self::SCREEN)->assertDontSee('id="report_empty"');

        $this->get(self::SCREEN . '?to=2000-01-01')->assertSee('id="report_empty"');

        $this->viewer([]);
        $this->get(self::SCREEN)->assertDontSee('id="report_empty"');

        $this->viewer();
        $this->accountingDb()->table('accounting_routing')->whereIn('routing_key', [RoutingKey::TaxOutput->value, RoutingKey::TaxInput->value])->delete();
        $this->get(self::SCREEN . '?to=2000-01-01')->assertDontSee('id="report_empty"');
    }

    public function testGetCsv_GivesThePartsTheRatesAndTheTotalsOfTheReport(): void
    {
        $invoice = $this->sell('1000.00', '150.00');
        $this->takeBack($invoice, '100.00', '15.00');
        $this->buyTaxed('10', '100', '15');
        $this->spend('230.00', '30.00');
        $this->viewer();

        $response = $this->get(self::SCREEN . '/csv');

        $response->assertStatus(200);
        $this->assertStringContainsString('text/csv', $response->response()->getHeaderLine('Content-Type'));
        $this->assertStringContainsString('attachment; filename="tax_report_' . date('Y-m-d') . '.csv"', $response->response()->getHeaderLine('Content-Disposition'));

        $rows = $this->csvRows($response);
        $part = static fn (string $section): string => lang('Accounting_tax_reports.section_' . $section);

        $this->assertSame(lang('Accounting_tax_reports.col_type'), $rows[0][0]);
        $this->assertCount(6, $rows[0]);
        $this->assertContains([lang('Accounting_tax_reports.row_part'), $part('sales'), '', '1', '1000.0000', '150.0000'], $rows);
        $this->assertContains([lang('Accounting_tax_reports.row_rate'), $part('sales'), '15', '1', '1000.0000', '150.0000'], $rows);
        $this->assertContains([lang('Accounting_tax_reports.row_part'), $part('sales_returns'), '', '1', '-100.0000', '-15.0000'], $rows);
        $this->assertContains([lang('Accounting_tax_reports.row_rate'), $part('purchases'), '15', '1', '-1000.0000', '-150.0000'], $rows);
        $this->assertContains([lang('Accounting_tax_reports.row_part'), $part('purchase_returns'), '', '0', '0.0000', '0.0000'], $rows);
        $this->assertContains([lang('Accounting_tax_reports.row_part'), $part('expenses'), '', '1', '-200.0000', '-30.0000'], $rows);

        $total = lang('Accounting_tax_reports.row_total');
        $first = array_search($total, array_column($rows, 0), true);

        $this->assertNotFalse($first);
        $this->assertSame([$total, lang('Accounting_tax_reports.total_output'), '', '', '900.0000', '135.0000'], $rows[$first]);
        $this->assertSame([$total, lang('Accounting_tax_reports.total_input'), '', '', '1200.0000', '180.0000'], $rows[$first + 1]);
        $this->assertSame([$total, lang('Accounting_tax_reports.row_total_company') . ': ' . lang('Accounting_tax_reports.net_refundable'), '', '', '', '-45.0000'], $rows[$first + 2]);
    }

    public function testGetCsv_CarriesTheTieOfEachAccountOfTaxAndTheBasisWhenTheBooksAgree(): void
    {
        $this->observedScenario();
        $this->viewer();

        $rows = $this->csvRows($this->get(self::SCREEN . '/csv'));

        $this->assertContains([lang('Accounting_tax_reports.account'), lang('Accounting_tax_reports.col_movement'), lang('Accounting_tax_reports.col_reported'), lang('Accounting_tax_reports.col_other'), lang('Accounting_tax_reports.col_other_count'), lang('Accounting_tax_reports.col_status')], $rows);
        $this->assertContains(['215001 ' . $this->accountNameOf('215001'), '298.5000', '298.5000', '0.0000', '0', lang('Accounting_tax_reports.agrees')], $rows);
        $this->assertContains(['215002 ' . $this->accountNameOf('215002'), '0.0000', '0.0000', '0.0000', '0', lang('Accounting_tax_reports.agrees')], $rows);
        $this->assertContains([lang('Accounting_tax_reports.basis'), lang('Accounting_tax_reports.basis_accrual')], $rows);
        $this->assertContains([lang('Accounting_tax_reports.csv_legacy'), '0'], $rows);
        $this->assertContains([lang('Accounting_tax_reports.csv_unmatched'), '0'], $rows);
    }

    public function testGetCsv_SaysWhatTheNetLeavesOutSoThatAFileTakenToAReturnIsNotClean(): void
    {
        $this->setConfigValue('accounting_start_date', '2026-10-01');
        $this->saveLegacySale('2026-09-15 10:00:00', '100.00', '15.00');
        $this->saveLegacyPurchase('2026-09-20 10:00:00');
        $this->observedScenario();
        $this->postManual('215001', '100.00', '0');

        $service = $this->item('0', false);
        $saleId  = $this->save([$this->line($service, 0, '1', '100.00')], [$this->tax($service, 0, '15.00')]);
        $this->accountingDb()->table('sales_items_taxes')->where('sale_id', $saleId)->update(['item_tax_amount' => '9.00']);
        $this->viewer();

        $rows = $this->csvRows($this->get(self::SCREEN . '/csv'));

        $this->assertContains(['215001 ' . $this->accountNameOf('215001'), '213.5000', '313.5000', '-100.0000', '1', lang('Accounting_tax_reports.differs')], $rows);
        $this->assertContains([lang('Accounting_tax_reports.csv_start'), '2026-10-01'], $rows);
        $this->assertContains([lang('Accounting_tax_reports.csv_legacy'), '2'], $rows);
        $this->assertContains([lang('Accounting_tax_reports.csv_unmatched'), '1'], $rows);
    }

    public function testGetCsv_SaysWhenNoAccountIsRoutedForTheTax(): void
    {
        $this->sell('100.00', '15.00');
        $this->accountingDb()->table('accounting_routing')->whereIn('routing_key', [RoutingKey::TaxOutput->value, RoutingKey::TaxInput->value])->delete();
        $this->viewer();

        $rows = $this->csvRows($this->get(self::SCREEN . '/csv'));

        $this->assertContains([lang('Accounting_tax_reports.not_routed_output')], $rows);
        $this->assertContains([lang('Accounting_tax_reports.not_routed_input')], $rows);
    }

    public function testGetCsv_LeavesOutTheLinesAboutRoutingWhenBothAccountsAreRouted(): void
    {
        $this->sell('100.00', '15.00');
        $this->viewer();

        $rows = $this->csvRows($this->get(self::SCREEN . '/csv'));

        $this->assertNotContains([lang('Accounting_tax_reports.not_routed_output')], $rows);
        $this->assertNotContains([lang('Accounting_tax_reports.not_routed_input')], $rows);
    }

    public function testGetCsv_AddsARowForTheDocumentsThatTookADiscountOnTheWhole(): void
    {
        $cycle = $this->discountedReferenceInvoice();

        $this->assertGreaterThan(0, $this->save($cycle['items'], $cycle['taxes'], [], SALE_TYPE_INVOICE, -1, true, null, COMPLETED, NEW_ENTRY, $cycle['discount']));
        $this->sell('100.00', '15.00');
        $this->viewer();

        $rows = $this->csvRows($this->get(self::SCREEN . '/csv'));

        $this->assertContains([lang('Accounting_tax_reports.row_discounted'), lang('Accounting_tax_reports.section_sales'), '', '1', '639.0000', '95.8500'], $rows);
        $this->assertSame(1, count(array_filter($rows, static fn (array $row): bool => $row[0] === lang('Accounting_tax_reports.row_discounted'))), 'One row, for the part that has such documents');
    }

    public function testGetCsv_RefusesAnotherBasisAndAnInvalidPeriodInWords(): void
    {
        $this->observedScenario();
        $this->viewer();

        $basis = $this->get(self::SCREEN . '/csv?basis=cash');

        $basis->assertStatus(422);
        $this->assertSame(lang('Accounting.error_tax_basis_unavailable'), $basis->response()->getBody());

        $period = $this->get(self::SCREEN . '/csv?from=2026-10-31&to=2026-10-01');

        $period->assertStatus(422);
        $this->assertSame(lang('Accounting.error_report_period_invalid'), $period->response()->getBody());
    }

    public function testGetCsv_RefusesABasisNamedAsAListAndGivesNoFile(): void
    {
        $this->observedScenario();
        $this->viewer();

        foreach (['basis[]=cash', 'basis[0]=partial'] as $query) {
            $response = $this->get(self::SCREEN . '/csv?' . $query);

            $response->assertStatus(422);
            $this->assertSame(lang('Accounting.error_tax_basis_unavailable'), $response->response()->getBody());
        }
    }

    public function testGetCsv_RequiresTheModuleGrant(): void
    {
        $this->observedScenario();
        $this->actAs($this->employeeWithGrants(['accounting_payables']));

        $this->get(self::SCREEN . '/csv')->assertRedirect();
    }

    public function testGetCsv_RequiresAccountingToBeOn(): void
    {
        $this->viewer();
        $this->setConfigValue('accounting_enabled', '0');

        $this->expectException(PageNotFoundException::class);

        $this->get(self::SCREEN . '/csv');
    }

    public function testGetCsv_CutsWhatIsAskedDownToTheBranchesTheEmployeeWorksInAndSaysTheTotalIsNotTheCompanys(): void
    {
        $depot = $this->locationOfBranch($this->second, 'Second depot');

        $this->sell('100.00', '15.00');
        $this->sell('200.00', '30.00', null, $depot);
        $this->viewer([1]);

        $rows = $this->csvRows($this->get(self::SCREEN . '/csv?branches[]=1&branches[]=' . $this->second));
        $net  = $this->netRowOf($rows);

        $this->assertSame(['100.0000', '15.0000'], [$rows[1][4], $rows[1][5]]);
        $this->assertSame(lang('Accounting_tax_reports.row_total_branches') . ': ' . lang('Accounting_tax_reports.net_payable'), $net[1]);
        $this->assertSame('15.0000', $net[5]);
    }

    public function testGetCsv_CarriesAScopeWithNoBranchSoItDoesNotWidenIt(): void
    {
        $this->sell('100.00', '15.00');
        $this->viewer([1]);

        $rows = $this->csvRows($this->get(self::SCREEN . '/csv?branches[0]=0'));

        $this->assertSame('0.0000', $this->netRowOf($rows)[5]);
        $this->get(self::SCREEN . '?branches[]=99')->assertSee('tax/csv?branches%5B0%5D=0');
    }
}
