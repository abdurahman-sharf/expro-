<?php

namespace Tests\Controllers;

use App\Libraries\Accounting\EntityAccountService;
use App\Libraries\Accounting\JournalService;
use CodeIgniter\Config\Factories;
use CodeIgniter\Exceptions\PageNotFoundException;
use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\DatabaseTestTrait;
use CodeIgniter\Test\TestResponse;
use Config\Services;
use Tests\Support\AccountingControllerFixtureTrait;
use Tests\Support\PurchaseAccountingFixtureTrait;
use Tests\Support\ReportFixtureTrait;
use Tests\Support\SaleAccountingFixtureTrait;
use Tests\Support\StatementFixtureTrait;

/**
 * The screens of the income statement and the balance sheet: who may open them, which branches each
 * adds up for whom, the levels and the choices of accounts, the links to the statement of each
 * account, the equation the balance sheet checks, and what they say in Arabic.
 */
class AccountingStatementsControllerTest extends CIUnitTestCase
{
    use DatabaseTestTrait;
    use AccountingControllerFixtureTrait;
    use ReportFixtureTrait;
    use StatementFixtureTrait;

    protected $migrate     = true;
    protected $migrateOnce = true;
    protected $refresh     = false;
    protected $namespace   = null;

    private int $third;

    protected function setUp(): void
    {
        parent::setUp();

        $this->resetBranches();
        $this->prepareAccounting();

        $this->second = $this->createBranch('T2', 'Second branch');
        $this->third  = $this->createBranch('T3', 'Third branch');
    }

    protected function tearDown(): void
    {
        Services::language()->setLocale('en');
        $this->removeInsertedEntities();
        $this->reinstallChart();
        $this->resetBranches();

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

    private function threeBranches(): void
    {
        $this->cashSale('2026-10-05', 1, '1111.00');
        $this->cashSale('2026-10-05', $this->second, '2222.00');
        $this->cashSale('2026-10-05', $this->third, '4444.00');
    }

    private function branchCode(int $branchId): string
    {
        return (string) $this->accountingDb()->table('branches')->where('branch_id', $branchId)->get()->getRow()->code;
    }

    private function codes(string ...$codes): string
    {
        return implode(lang('Accounting_reports.branch_separator'), $codes);
    }

    private function accountRowsOf(TestResponse $response): int
    {
        return substr_count($response->getBody(), 'data-account="');
    }

    /**
     * @return list<string> The codes of the accounts the screen lists, in the order it lists them.
     */
    private function listedCodes(TestResponse $response): array
    {
        preg_match_all('#data-account="([^"]*)"#', $response->getBody(), $found);

        return $found[1];
    }

    private function amountOfLine(TestResponse $response, string $code): ?string
    {
        preg_match('#data-account="' . preg_quote($code, '#') . '">.*?<td class="text-end"><bdi dir="ltr">([^<]*)</bdi></td>\s*</tr>#s', $response->getBody(), $found);

        return $found[1] ?? null;
    }

    private function amountOfRow(TestResponse $response, string $id): ?string
    {
        preg_match('#id="' . preg_quote($id, '#') . '">.*?<td class="text-end"><bdi dir="ltr">([^<]*)</bdi></td>#s', $response->getBody(), $found);

        return $found[1] ?? null;
    }

    private function optionValues(TestResponse $response, string $select): array
    {
        preg_match('#<select id="' . preg_quote($select, '#') . '"[^>]*>(.*?)</select>#s', $response->getBody(), $block);
        preg_match_all('#<option value="([^"]*)"#', $block[1] ?? '', $found);

        return $found[1];
    }

    private function useArabic(int $personId): void
    {
        $this->accountingDb()->table('employees')->where('person_id', $personId)->update(['language_code' => 'ar-EG', 'language' => 'arabic-egypt']);
    }

    // ---------------------------------------------------------------------------------------
    // The income statement
    // ---------------------------------------------------------------------------------------

    public function testGetIncomeStatement_ShowsTheStatementToWhoeverHoldsTheModule(): void
    {
        $this->abScenario();
        $this->viewer();

        $response = $this->get('/accounting_statements/income_statement?level=3');

        $response->assertStatus(200);
        $response->assertSee('id="income_statement_table"');
        $this->assertSame($this->amountOf('1990.00'), $this->amountOfRow($response, 'total_revenue'));
        $this->assertSame($this->amountOf('8280.00'), $this->amountOfRow($response, 'total_expenses'));
        $this->assertSame($this->amountOf('-6290.00'), $this->amountOfRow($response, 'net_income'));
        $response->assertSee(lang('Accounting_statements.net_income'));
        $response->assertSee(lang('Accounting_reports.scope_company'));
        $response->assertDontSee('id="report_error"');
    }

    public function testGetIncomeStatement_IsTheScreenOfTheStatementsAddressToo(): void
    {
        $this->abScenario();
        $this->viewer();

        $this->assertSame($this->amountOf('-6290.00'), $this->amountOfRow($this->get('/accounting_statements'), 'net_income'));
    }

    public function testGetIncomeStatement_RedirectsAUserWithoutTheModuleGrant(): void
    {
        $this->actAs($this->employeeWithGrants(['accounting_payables']));

        $this->get('/accounting_statements/income_statement')->assertRedirect();
    }

    public function testGetIncomeStatement_IsNotFoundWhileAccountingIsOff(): void
    {
        $this->prepareAccounting(false);
        $this->viewer();

        $this->expectException(PageNotFoundException::class);

        $this->get('/accounting_statements/income_statement');
    }

    public function testGetIncomeStatement_HasNoGrossProfitAndPutsTheCostOfSalesAmongTheExpenses(): void
    {
        $this->abScenario();
        $this->viewer();

        $response = $this->get('/accounting_statements/income_statement?level=3');
        $body     = $response->getBody();

        $this->assertStringNotContainsStringIgnoringCase('gross', $body);
        $this->assertMatchesRegularExpression('#id="section_total_expenses">.*data-account="511".*id="total_expenses"#s', $body, 'The cost of sales is a line of the expenses');
        $this->assertDoesNotMatchRegularExpression('#id="section_total_revenue">.*data-account="511".*id="total_revenue"#s', $body);
        $this->assertSame(['4', '41', '411', '412', '42', '4201', '4202', '4203', '5', '51', '511', '512', '513', '52', '5201', '5202', '5203', '5204', '5205', '5206', '53', '54', '541', '542', '543', '544', '545'], $this->listedCodes($response));
    }

    public function testGetIncomeStatement_ShowsSalesReturnsAsANegativeInsideTheRevenue(): void
    {
        $this->abScenario();
        $this->viewer();

        $response = $this->get('/accounting_statements/income_statement?level=3');

        $this->assertSame($this->amountOf('2210.00'), $this->amountOfLine($response, '411'));
        $this->assertSame($this->amountOf('-220.00'), $this->amountOfLine($response, '412'));
        $this->assertSame($this->amountOf('1990.00'), $this->amountOfLine($response, '41'));
    }

    public function testGetIncomeStatement_OffersDepthsAndTheThreeDisplayChoices(): void
    {
        $this->abScenario();
        $this->viewer();

        $response = $this->get('/accounting_statements/income_statement');

        $this->assertSame(['1', '2', '3', '4'], $this->optionValues($response, 'f_level'), 'The default chart is four accounts deep');
        $this->assertSame(['all', 'activity', 'hide_zero'], $this->optionValues($response, 'f_display'));
        $response->assertSee('<option value="2" selected>');
        $this->assertSame(['4', '41', '42', '5', '51', '52', '53', '54'], $this->listedCodes($response), 'The default level lists the groups of two digits under the two roots');
    }

    public function testGetIncomeStatement_OffersAFifthLevelOnlyOnceTheChartHasAccountsThatDeep(): void
    {
        $this->viewer();

        $this->assertNotContains('5', $this->optionValues($this->get('/accounting_statements/income_statement'), 'f_level'));

        (new EntityAccountService())->ensure(EntityAccountService::CUSTOMER, $this->insertCustomer(), 'Omar Khaled');

        $levels = $this->optionValues($this->get('/accounting_statements/income_statement'), 'f_level');

        $this->assertSame(['1', '2', '3', '4', '5'], $levels, 'The chart is cut off at the fifth level');
    }

    public function testGetIncomeStatement_ListsEveryLevelDownToTheOneAsked(): void
    {
        $this->abScenario();
        $this->viewer();

        $this->assertSame(['4', '5'], $this->listedCodes($this->get('/accounting_statements/income_statement?level=1')));
        $this->assertContains('545', $this->listedCodes($this->get('/accounting_statements/income_statement?level=3')));
        $this->assertNotContains('545', $this->listedCodes($this->get('/accounting_statements/income_statement?level=2')));
        $this->assertSame($this->listedCodes($this->get('/accounting_statements/income_statement?level=4')), $this->listedCodes($this->get('/accounting_statements/income_statement?level=99')), 'A level past the deepest is the deepest');
    }

    public function testGetIncomeStatement_FallsBackToTheDefaultsForChoicesItDoesNotKnow(): void
    {
        $this->abScenario();
        $this->viewer();

        $response = $this->get('/accounting_statements/income_statement?level=abc&display=nonsense&entities=yes');

        $response->assertStatus(200);
        $this->assertSame($this->listedCodes($this->get('/accounting_statements/income_statement')), $this->listedCodes($response));
        $response->assertSee('<option value="all" selected>');
        $response->assertDontSee('id="f_entities" name="entities" value="1" checked');
    }

    public function testGetIncomeStatement_ListsOnlyTheAccountsThatMovedOrStandAtSomethingWhenAsked(): void
    {
        $journal = $this->cashSale('2026-10-05', 1, '100.00');
        $this->reverseOn($journal, '2026-10-06');
        $this->postOn('2026-10-07', 1, [[$this->accountIdByCode('545'), '30.00', '0'], [$this->accountId('cash'), '0', '30.00']]);
        $this->viewer();

        $moved  = $this->get('/accounting_statements/income_statement?level=3&display=activity');
        $stands = $this->get('/accounting_statements/income_statement?level=3&display=hide_zero');

        $this->assertSame(['4', '41', '411', '5', '54', '545'], $this->listedCodes($moved), 'The sale that was reversed moved and stands at nothing');
        $this->assertSame(['5', '54', '545'], $this->listedCodes($stands));
        $this->assertSame($this->amountOfRow($moved, 'net_income'), $this->amountOfRow($stands, 'net_income'));
    }

    public function testGetIncomeStatement_SaysTheScopeInTheHeadingAndAddsUpEveryYearWithoutDates(): void
    {
        $this->cashSale('2025-03-05', 1, '100.00');
        $this->cashSale('2026-10-05', 1, '50.00');
        $this->viewer();

        $all   = $this->get('/accounting_statements/income_statement?to=2026-12-31');
        $range = $this->get('/accounting_statements/income_statement?from=2026-01-01&to=2026-12-31');

        $all->assertSee(lang('Accounting_statements.period_all_years', [to_date(strtotime('2026-12-31'))]));
        $this->assertSame($this->amountOf('150.00'), $this->amountOfRow($all, 'total_revenue'), 'Every year without a first day');
        $range->assertSee(lang('Accounting_reports.period_between', [to_date(strtotime('2026-01-01')), to_date(strtotime('2026-12-31'))]));
        $this->assertSame($this->amountOf('50.00'), $this->amountOfRow($range, 'total_revenue'));
        $all->assertSee(lang('Accounting_reports.scope_company'));
    }

    public function testGetIncomeStatement_LeavesOutTheJournalThatClosesAYearAndSaysSo(): void
    {
        $this->abScenario();
        $this->viewer();

        $before = $this->get('/accounting_statements/income_statement?level=3&from=2026-01-01&to=2026-12-31');

        $this->postYearEnd('2026-12-31', 1, [
            [$this->accountId('sales_revenue'), '2210.00', '0'],
            [$this->accountId('sales_returns'), '0', '220.00'],
            [$this->accountId('cogs'), '0', '5280.00'],
            [$this->accountIdByCode('545'), '0', '3000.00'],
            [$this->accountId('retained_earnings'), '6290.00', '0'],
        ]);

        $after = $this->get('/accounting_statements/income_statement?level=3&from=2026-01-01&to=2026-12-31');

        $this->assertSame($this->amountOf('-6290.00'), $this->amountOfRow($after, 'net_income'));
        $this->assertSame($this->listedCodes($before), $this->listedCodes($after));
        $this->assertSame($this->amountOfLine($before, '411'), $this->amountOfLine($after, '411'));
        $after->assertSee(lang('Accounting_statements.nominal_note_income_statement'));
    }

    public function testGetIncomeStatement_AddsUpTheBranchesAskedFor(): void
    {
        $this->threeBranches();
        $this->viewer();

        $response = $this->get('/accounting_statements/income_statement?branches[]=1&branches[]=' . $this->second);

        $this->assertSame($this->amountOf('3333.00'), $this->amountOfRow($response, 'total_revenue'));
        $response->assertSee(lang('Accounting_reports.scope_selected', [$this->codes($this->branchCode(1), 'T2')]));
    }

    public function testGetIncomeStatement_CutsWhatIsAskedDownToTheBranchesTheEmployeeWorksIn(): void
    {
        $this->threeBranches();
        $this->viewer([1, $this->second]);

        $response = $this->get('/accounting_statements/income_statement?branches[]=1&branches[]=' . $this->second . '&branches[]=' . $this->third);

        $this->assertSame($this->amountOf('3333.00'), $this->amountOfRow($response, 'total_revenue'));
        $response->assertDontSee('T3');
    }

    public function testGetIncomeStatement_SaysTheTotalIsOfTheBranchesTheEmployeeWorksIn(): void
    {
        $this->threeBranches();
        $this->viewer([1, $this->second]);

        $response = $this->get('/accounting_statements/income_statement');

        $response->assertSee(lang('Accounting_reports.scope_partial', [$this->codes($this->branchCode(1), 'T2')]));
        $response->assertSee('class="alert alert-warning" id="report_scope"');
        $response->assertDontSee(lang('Accounting_reports.scope_company'));
        $this->assertSame($this->amountOf('3333.00'), $this->amountOfRow($response, 'total_revenue'));
    }

    public function testGetIncomeStatement_SaysNothingOfBranchesToWhoWorksInOne(): void
    {
        $this->threeBranches();
        $this->viewer([1]);

        $response = $this->get('/accounting_statements/income_statement');

        $this->assertSame($this->amountOf('1111.00'), $this->amountOfRow($response, 'total_revenue'));
        $response->assertDontSee('id="report_scope"');
        $response->assertDontSee('name="branches[]"');
    }

    public function testGetIncomeStatement_AddsUpNothingForABranchOnlyOthersWorkIn(): void
    {
        $this->threeBranches();
        $this->viewer([1]);

        $response = $this->get('/accounting_statements/income_statement?branches[]=' . $this->second);

        $response->assertSee('id="report_no_branches"');
        $this->assertSame($this->amountOf('0.00'), $this->amountOfRow($response, 'total_revenue'));
    }

    public function testGetIncomeStatement_OffersTheBranchesToWhoWorksInSeveral(): void
    {
        $this->viewer([1, $this->second]);

        $response = $this->get('/accounting_statements/income_statement');

        $response->assertSee('id="f_branches"');
        $response->assertSee('T2 - Second branch');
        $response->assertDontSee('T3 - Third branch');
    }

    public function testGetIncomeStatement_LinksEachAccountToItsStatementWithTheDaysAndBranchesAskedFor(): void
    {
        $this->cashSale('2026-10-05', $this->second, '100.00');
        $this->viewer();

        $response = $this->get('/accounting_statements/income_statement?level=3&from=2026-10-01&to=2026-10-31&branches[]=' . $this->second);
        $sales    = $this->accountId('sales_revenue');

        $response->assertSee('accounting_reports/ledger?account=' . $sales . '&amp;from=2026-10-01&amp;to=2026-10-31&amp;branches%5B0%5D=' . $this->second);

        $ledger = $this->get('/accounting_reports/ledger?account=' . $sales . '&from=2026-10-01&to=2026-10-31&branches[]=' . $this->second);

        $ledger->assertStatus(200);
        $ledger->assertSee($this->amountOf('100.00'));
    }

    public function testGetIncomeStatement_FoldsTheAccountOfACustomerUnlessAsked(): void
    {
        $customer = (new EntityAccountService())->ensure(EntityAccountService::CUSTOMER, $this->insertCustomer('Omar', 'Khaled'), 'Omar Khaled');

        $this->postOn('2026-10-05', 1, [[$customer, '250.00', '0'], [$this->accountId('sales_revenue'), '0', '250.00']]);
        $this->viewer();

        $this->get('/accounting_statements/income_statement?level=5')->assertDontSee('Omar Khaled');
        $this->assertSame($this->amountOf('250.00'), $this->amountOfRow($this->get('/accounting_statements/income_statement?level=5&entities=1'), 'total_revenue'));
    }

    public function testGetIncomeStatement_ShowsAMessageForADayThatIsNotOneAndStillShowsTheStatement(): void
    {
        $this->abScenario();
        $this->viewer();

        $response = $this->get('/accounting_statements/income_statement?from=2026-13-45');

        $response->assertStatus(200);
        $response->assertSee('id="report_error"');
        $response->assertSee(lang('Accounting.error_invalid_date'));
        $response->assertSee('id="income_statement_table"');
        $response->assertSee('2026-13-45');
    }

    public function testGetIncomeStatement_ShowsAMessageForAStartAfterTheEnd(): void
    {
        $this->viewer();

        $response = $this->get('/accounting_statements/income_statement?from=2026-10-10&to=2026-10-01');

        $response->assertSee('id="report_error"');
        $response->assertSee(lang('Accounting.error_report_period_invalid'));
    }

    public function testGetIncomeStatement_HasAPrintButtonAndKeepsTheFiltersOutOfThePrint(): void
    {
        $this->viewer();

        $response = $this->get('/accounting_statements/income_statement');

        $response->assertSee('window.print()');
        $response->assertSee('class="acc-filters print_hide"');
        $response->assertSee('id="title_bar" class="print_hide btn-toolbar"');
    }

    public function testGetIncomeStatement_SaysTheFigureOfAnAccountThatWasNeverUsedIsNothing(): void
    {
        $this->viewer();

        $response = $this->get('/accounting_statements/income_statement?level=3');

        $this->assertSame($this->amountOf('0.00'), $this->amountOfLine($response, '412'));
        $this->assertSame($this->amountOf('0.00'), $this->amountOfRow($response, 'net_income'));
    }

    public function testGetIncomeStatement_SaysNothingMatchesWhenNoAccountDoes(): void
    {
        $this->viewer();

        $response = $this->get('/accounting_statements/income_statement?level=3&display=hide_zero');

        $response->assertSee('id="total_revenue_empty"');
        $response->assertSee('id="total_expenses_empty"');
        $response->assertSee(lang('Accounting_statements.empty'));
    }

    public function testGetIncomeStatement_RendersInArabicForAnEmployeeWhoUsesIt(): void
    {
        $this->abScenario();
        $this->useArabic($this->viewer());

        $response = $this->get('/accounting_statements/income_statement?level=3');
        $body     = html_entity_decode($response->getBody(), ENT_QUOTES, 'UTF-8');

        $response->assertStatus(200);
        $this->assertStringContainsString('قائمة الدخل', $body);
        $this->assertStringContainsString('الإيرادات', $body);
        $this->assertStringContainsString('المصروفات', $body);
        $this->assertStringContainsString('صافي الدخل', $body);
        $this->assertStringContainsString('مردودات المبيعات', $body);
        $this->assertStringNotContainsString('مجمل', $body, 'No gross profit');
        $this->assertStringNotContainsString('Net income', $body);
        $this->assertStringContainsString('dir="rtl"', $body);
        $this->assertSame($this->amountOf('-6290.00'), $this->amountOfRow($response, 'net_income'));
    }

    // ---------------------------------------------------------------------------------------
    // The balance sheet
    // ---------------------------------------------------------------------------------------

    public function testGetBalanceSheet_ShowsTheEquationWithTheResultOfUnclosedYears(): void
    {
        $this->observedBooks(1, $this->second);
        $this->viewer();

        $response = $this->get('/accounting_statements/balance_sheet?to=2026-12-31');

        $response->assertStatus(200);
        $response->assertSee('id="balance_sheet_table"');
        $this->assertSame($this->amountOf('96991.84'), $this->amountOfRow($response, 'total_assets'));
        $this->assertSame($this->amountOf('268.30'), $this->amountOfRow($response, 'total_liabilities'));
        $this->assertSame($this->amountOf('-3276.46'), $this->amountOfRow($response, 'result_unclosed'));
        $this->assertSame($this->amountOf('96723.54'), $this->amountOfRow($response, 'total_equity'));
        $this->assertSame($this->amountOf('96991.84'), $this->amountOfRow($response, 'total_liabilities_equity'));
        $response->assertSee('id="report_balanced"');
        $response->assertDontSee('id="report_unbalanced"');
        $response->assertSee(lang('Accounting_statements.balance_sheet_as_of', [to_date(strtotime('2026-12-31'))]));
    }

    public function testGetBalanceSheet_RedirectsAUserWithoutTheModuleGrant(): void
    {
        $this->actAs($this->employeeWithGrants(['accounting_payables']));

        $this->get('/accounting_statements/balance_sheet')->assertRedirect();
    }

    public function testGetBalanceSheet_IsNotFoundWhileAccountingIsOff(): void
    {
        $this->prepareAccounting(false);
        $this->viewer();

        $this->expectException(PageNotFoundException::class);

        $this->get('/accounting_statements/balance_sheet');
    }

    public function testGetBalanceSheet_IgnoresTheFirstDayAndReportsThePositionToTheLastDay(): void
    {
        $this->cashSale('2026-10-05', 1, '1111.00');
        $this->cashSale('2026-10-26', 1, '85.00');
        $this->viewer();

        $without = $this->get('/accounting_statements/balance_sheet?to=2026-10-27');
        $with    = $this->get('/accounting_statements/balance_sheet?from=2026-10-25&to=2026-10-27');

        $this->assertSame($this->amountOf('1196.00'), $this->amountOfRow($without, 'total_assets'));
        $this->assertSame($this->amountOfRow($without, 'total_assets'), $this->amountOfRow($with, 'total_assets'), 'A first day does not turn the position into a movement');
        $this->assertSame($this->amountOfRow($without, 'total_liabilities_equity'), $this->amountOfRow($with, 'total_liabilities_equity'));
        $with->assertSee('id="report_balanced"');
        $with->assertSee('id="report_from_ignored"');
        $without->assertDontSee('id="report_from_ignored"');
        $with->assertDontSee('name="from"');
    }

    public function testGetBalanceSheet_ShowsOnlyTheRootTotalsAtDepthOne(): void
    {
        $this->observedBooks(1, $this->second);
        $this->viewer();

        $response = $this->get('/accounting_statements/balance_sheet?level=1');

        $this->assertSame(['1', '2', '3'], $this->listedCodes($response));
        $this->assertSame($this->amountOf('96991.84'), $this->amountOfLine($response, '1'));
        $this->assertSame($this->amountOf('268.30'), $this->amountOfLine($response, '2'));
        $this->assertSame($this->amountOf('100000.00'), $this->amountOfLine($response, '3'));
        $this->assertSame($this->amountOf('-3276.46'), $this->amountOfRow($response, 'result_unclosed'));
    }

    public function testGetBalanceSheet_ShowsAccountThirtyTwoBesideTheResultOfUnclosedYears(): void
    {
        $this->cashSale('2026-10-10', 1, '320.00');
        $this->viewer();

        $open = $this->get('/accounting_statements/balance_sheet?level=2&to=2026-12-31');

        $this->assertContains('32', $this->listedCodes($open));
        $this->assertSame($this->amountOf('0.00'), $this->amountOfLine($open, '32'));
        $this->assertSame($this->amountOf('320.00'), $this->amountOfRow($open, 'result_unclosed'));

        $this->postYearEnd('2026-12-31', 1, [[$this->accountId('sales_revenue'), '320.00', '0'], [$this->accountId('retained_earnings'), '0', '320.00']]);

        $closed = $this->get('/accounting_statements/balance_sheet?level=2&to=2026-12-31');

        $this->assertSame($this->amountOf('320.00'), $this->amountOfLine($closed, '32'));
        $this->assertSame($this->amountOf('0.00'), $this->amountOfRow($closed, 'result_unclosed'));
        $this->assertSame($this->amountOfRow($open, 'total_equity'), $this->amountOfRow($closed, 'total_equity'));
        $closed->assertSee('id="report_balanced"');
    }

    public function testGetBalanceSheet_ShowsADebitBalanceInsideTheLiabilitiesAsNegative(): void
    {
        $debited = (new EntityAccountService())->ensure(EntityAccountService::SUPPLIER, $this->insertSupplier('Najd Co'), 'Najd Co');

        $this->postOn('2026-10-05', 1, [[$this->accountId('purchases'), '355.50', '0'], [$this->accountId('other_payables'), '0', '355.50']]);
        $this->postOn('2026-10-06', 1, [[$debited, '50.00', '0'], [$this->accountId('cash'), '0', '50.00']]);
        $this->postOn('2026-10-07', 1, [[$this->accountId('tax_input'), '37.20', '0'], [$this->accountId('cash'), '0', '37.20']]);
        $this->viewer();

        $response = $this->get('/accounting_statements/balance_sheet?level=5&entities=1');

        $this->assertSame($this->amountOf('-50.00'), $this->amountOfLine($response, $this->codeOfAccount($debited)));
        $this->assertSame($this->amountOf('-37.20'), $this->amountOfLine($response, '215002'));
        $this->assertSame($this->amountOf('268.30'), $this->amountOfRow($response, 'total_liabilities'));
        $response->assertSee('id="report_balanced"');
    }

    private function codeOfAccount(int $accountId): string
    {
        return (string) $this->accountingDb()->table('accounting_accounts')->select('code')->where('account_id', $accountId)->get()->getRow()->code;
    }

    public function testGetBalanceSheet_ListsTheChartDownToTheDeepestLevelOfTheChartOnly(): void
    {
        $this->cashSale('2026-10-05', 1, '10.00');
        $this->viewer();

        $this->assertSame(['1', '2', '3', '4'], $this->optionValues($this->get('/accounting_statements/balance_sheet'), 'f_level'));
        $this->assertContains('111001', $this->listedCodes($this->get('/accounting_statements/balance_sheet?level=4')), 'The fixed assets of the default chart are at level four');
        $this->assertNotContains('111001', $this->listedCodes($this->get('/accounting_statements/balance_sheet?level=3')));

        (new EntityAccountService())->ensure(EntityAccountService::CUSTOMER, $this->insertCustomer(), 'Omar Khaled');

        $this->assertSame(['1', '2', '3', '4', '5'], $this->optionValues($this->get('/accounting_statements/balance_sheet'), 'f_level'));
    }

    public function testGetBalanceSheet_DefaultsToTheThirdLevel(): void
    {
        $this->cashSale('2026-10-05', 1, '10.00');
        $this->viewer();

        $response = $this->get('/accounting_statements/balance_sheet');

        $response->assertSee('<option value="3" selected>');
        $this->assertContains('1201', $this->listedCodes($response));
        $this->assertNotContains('120101', $this->listedCodes($response));
    }

    public function testGetBalanceSheet_HidesAccountsThatStandAtNothingWithoutChangingTheTotals(): void
    {
        $this->observedBooks(1, $this->second);
        $this->viewer();

        $all    = $this->get('/accounting_statements/balance_sheet?level=4');
        $hidden = $this->get('/accounting_statements/balance_sheet?level=4&display=hide_zero');

        $this->assertContains('32', $this->listedCodes($all));
        $this->assertNotContains('32', $this->listedCodes($hidden));
        $this->assertLessThan($this->accountRowsOf($all), $this->accountRowsOf($hidden));
        $this->assertSame($this->amountOfRow($all, 'total_assets'), $this->amountOfRow($hidden, 'total_assets'));
        $this->assertSame($this->amountOfRow($all, 'total_liabilities_equity'), $this->amountOfRow($hidden, 'total_liabilities_equity'));
    }

    public function testGetBalanceSheet_SaysItDoesNotBalanceWhenAJournalWasChangedOutsideTheBooks(): void
    {
        $this->cashSale('2026-10-05', 1, '100.00');
        $this->viewer();

        $this->get('/accounting_statements/balance_sheet')->assertSee('id="report_balanced"');

        $this->accountingDb()->query('UPDATE ' . $this->accountingDb()->prefixTable('accounting_journal_lines') . ' SET debit = debit + 5 LIMIT 1');

        $response = $this->get('/accounting_statements/balance_sheet');

        $response->assertSee('id="report_unbalanced"');
        $response->assertDontSee('id="report_balanced"');
    }

    public function testGetBalanceSheet_BalancesForEveryChoiceOfBranchesTheEmployeeMayRead(): void
    {
        $this->observedBooks(1, $this->second);
        $this->viewer();

        foreach (['', '?branches[]=1', '?branches[]=' . $this->second, '?branches[]=1&branches[]=' . $this->second] as $query) {
            $this->get('/accounting_statements/balance_sheet' . $query)->assertSee('id="report_balanced"');
        }

        $this->viewer([1, $this->second]);
        $partial = $this->get('/accounting_statements/balance_sheet');

        $partial->assertSee('id="report_balanced"');
        $partial->assertSee(lang('Accounting_reports.scope_partial', [$this->codes($this->branchCode(1), 'T2')]));
        $partial->assertSee('class="alert alert-warning" id="report_scope"');
    }

    public function testGetBalanceSheet_AddsUpOnlyTheBranchesOfTheEmployeeAndNothingForOthers(): void
    {
        $this->threeBranches();
        $this->viewer([1]);

        $own   = $this->get('/accounting_statements/balance_sheet');
        $other = $this->get('/accounting_statements/balance_sheet?branches[]=' . $this->second);

        $this->assertSame($this->amountOf('1111.00'), $this->amountOfRow($own, 'total_assets'));
        $own->assertDontSee('id="report_scope"');
        $other->assertSee('id="report_no_branches"');
        $this->assertSame($this->amountOf('0.00'), $this->amountOfRow($other, 'total_assets'));
    }

    public function testGetBalanceSheet_TellsWhatIsLeftBetweenTheBranchesOnlyForTheWholeCompany(): void
    {
        $this->postOn('2026-10-05', 1, [[$this->accountId('branches_receivable'), '50.00', '0'], [$this->accountId('cash'), '0', '50.00']]);

        $this->viewer();
        $company = $this->get('/accounting_statements/balance_sheet');

        $this->viewer([1, $this->second]);
        $partial = $this->get('/accounting_statements/balance_sheet');

        $company->assertSee('id="report_inter_branch_difference"');
        $company->assertSee($this->amountOf('50.00'));
        $partial->assertDontSee('id="report_inter_branch_difference"');
        $partial->assertDontSee('id="report_inter_branch_ok"');
    }

    public function testGetBalanceSheet_SaysTheBranchesOffsetWhenTheyDo(): void
    {
        $this->postOn('2026-10-05', 1, [[$this->accountId('branches_receivable'), '50.00', '0'], [$this->accountId('cash'), '0', '50.00']]);
        $this->postOn('2026-10-05', $this->second, [[$this->accountId('cash'), '50.00', '0'], [$this->accountId('branches_payable'), '0', '50.00']]);
        $this->viewer();

        $response = $this->get('/accounting_statements/balance_sheet');

        $response->assertSee('id="report_inter_branch_ok"');
        $response->assertDontSee('id="report_inter_branch_difference"');
    }

    public function testGetBalanceSheet_LinksEachAccountToItsStatementUpToTheLastDay(): void
    {
        $this->cashSale('2026-10-05', 1, '100.00');
        $this->viewer();

        $response = $this->get('/accounting_statements/balance_sheet?level=4&to=2026-10-31');
        $cash     = $this->accountId('cash');

        $response->assertSee('accounting_reports/ledger?account=' . $cash . '&amp;to=2026-10-31');

        $ledger = $this->get('/accounting_reports/ledger?account=' . $cash . '&to=2026-10-31');

        $ledger->assertStatus(200);
        $ledger->assertSee($this->amountOf('100.00'));
    }

    public function testGetBalanceSheet_ShowsAMessageForADayThatIsNotOneAndStillShowsTheSheet(): void
    {
        $this->cashSale('2026-10-05', 1, '100.00');
        $this->viewer();

        $response = $this->get('/accounting_statements/balance_sheet?to=2026-13-45');

        $response->assertStatus(200);
        $response->assertSee('id="report_error"');
        $response->assertSee(lang('Accounting.error_invalid_date'));
        $response->assertSee('id="balance_sheet_table"');
        $response->assertSee('2026-13-45');
    }

    public function testGetBalanceSheet_LeavesOutDraftsAndPreviews(): void
    {
        $this->cashSale('2026-10-05', 1, '100.00');
        $this->viewer();

        $before = $this->get('/accounting_statements/balance_sheet');

        (new JournalService())->saveDraft($this->journalInput('500.00'), null);

        $preview = $this->cashSale('2026-10-06', 1, '700.00');

        $this->accountingDb()->table('accounting_journals')->where('journal_id', $preview)->update(['status' => 'preview']);

        $after = $this->get('/accounting_statements/balance_sheet');

        $this->assertSame($this->amountOfRow($before, 'total_assets'), $this->amountOfRow($after, 'total_assets'));
        $this->assertSame($this->amountOf('100.00'), $this->amountOfRow($after, 'total_assets'));
    }

    public function testGetBalanceSheet_HasAPrintButtonAndLinksToTheIncomeStatement(): void
    {
        $this->viewer();

        $response = $this->get('/accounting_statements/balance_sheet?to=2026-10-31&branches[]=1');

        $response->assertSee('window.print()');
        $response->assertSee('id="other_statement" href="' . base_url('accounting_statements/income_statement') . '?to=2026-10-31&amp;branches%5B0%5D=1"');
    }

    public function testGetBalanceSheet_RendersInArabicForAnEmployeeWhoUsesIt(): void
    {
        $this->observedBooks(1, $this->second);
        $this->useArabic($this->viewer());

        $response = $this->get('/accounting_statements/balance_sheet?level=2&to=2026-12-31');
        $body     = html_entity_decode($response->getBody(), ENT_QUOTES, 'UTF-8');

        $response->assertStatus(200);
        $this->assertStringContainsString('الميزانية العمومية (المركز المالي) حتى تاريخ', $body);
        $this->assertStringContainsString('الأصول', $body);
        $this->assertStringContainsString('الخصوم', $body);
        $this->assertStringContainsString('رأس المال وحقوق الملكية', $body);
        $this->assertStringContainsString('أرباح / خسائر (غير مرحلة)', $body);
        $this->assertStringContainsString('أرباح وخسائر مرحلة', $body, 'Account 32 beside it');
        $this->assertStringContainsString('الميزانية متوازنة', $body);
        $this->assertStringContainsString('dir="rtl"', $body);
        $this->assertSame($this->amountOf('96991.84'), $this->amountOfRow($response, 'total_assets'));
    }

    // ---------------------------------------------------------------------------------------
    // Reaching them
    // ---------------------------------------------------------------------------------------

    public function testGetIncomeStatement_LinksToTheBalanceSheetAndTheTrialBalance(): void
    {
        $this->viewer();

        $response = $this->get('/accounting_statements/income_statement');

        $response->assertSee('id="other_statement"');
        $response->assertSee('accounting_statements/balance_sheet');
        $response->assertSee('href="' . base_url('accounting_reports') . '"');
    }
}
