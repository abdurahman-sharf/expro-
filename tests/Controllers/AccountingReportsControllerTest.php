<?php

namespace Tests\Controllers;

use App\Libraries\Accounting\EntityAccountService;
use App\Libraries\Accounting\JournalDraft;
use App\Libraries\Accounting\JournalLineDraft;
use App\Libraries\Accounting\PostingService;
use CodeIgniter\Exceptions\PageNotFoundException;
use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\DatabaseTestTrait;
use CodeIgniter\Test\TestResponse;
use DOMDocument;
use DOMXPath;
use Tests\Support\AccountingControllerFixtureTrait;
use Tests\Support\ReportFixtureTrait;

/**
 * The screens of the financial reports: who may open them, which branches each adds up for whom,
 * what the three forms of the trial balance show, and the statement of an account with its pages.
 */
class AccountingReportsControllerTest extends CIUnitTestCase
{
    use DatabaseTestTrait;
    use AccountingControllerFixtureTrait;
    use ReportFixtureTrait;

    protected $migrate     = true;
    protected $migrateOnce = true;
    protected $refresh     = false;
    protected $namespace   = null;

    private int $second;
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

    private function codeOf(string $systemKey): string
    {
        return (string) $this->accountingDb()->table('accounting_accounts')->where('account_id', $this->accountId($systemKey))->get()->getRow()->code;
    }

    private function branchCode(int $branchId): string
    {
        return (string) $this->accountingDb()->table('branches')->where('branch_id', $branchId)->get()->getRow()->code;
    }

    private function rowsOf(TestResponse $response): int
    {
        return substr_count($response->getBody(), '<tr>');
    }

    /**
     * @return list<string> The balance after each line of the statement on the screen.
     */
    private function balancesOf(TestResponse $response): array
    {
        preg_match_all('#<td class="text-end"><bdi dir="ltr">([^<]*)</bdi></td>\s*</tr>#', $response->getBody(), $found);

        return $found[1];
    }

    private function codes(string ...$codes): string
    {
        return implode(lang('Accounting_reports.branch_separator'), $codes);
    }

    private function accountRowsOf(TestResponse $response): int
    {
        return substr_count($response->getBody(), 'data-account="');
    }

    public function testGetIndex_ShowsTheTrialBalanceToWhoeverHoldsTheModule(): void
    {
        $this->threeBranches();
        $this->viewer();

        $response = $this->get('/accounting_reports');

        $response->assertStatus(200);
        $response->assertSee('id="trial_balance_table"');
        $response->assertSee('<bdi dir="ltr">' . $this->amountOf('7777.00') . '</bdi>');
        $response->assertSee('id="report_balanced"');
        $response->assertSee(lang('Accounting_reports.scope_company'));
        $response->assertDontSee('id="report_error"');
    }

    public function testGetIndex_RedirectsAUserWithoutTheModuleGrant(): void
    {
        $this->actAs($this->employeeWithGrants(['accounting_payables']));

        $this->get('/accounting_reports')->assertRedirect();
    }

    public function testGetIndex_IsNotFoundWhileAccountingIsOff(): void
    {
        $this->prepareAccounting(false);
        $this->viewer();

        $this->expectException(PageNotFoundException::class);

        $this->get('/accounting_reports');
    }

    public function testGetIndex_ShowsTheColumnsOfTheFormAsked(): void
    {
        $this->cashSale('2026-10-05', 1, '100.00');
        $this->viewer();

        $balances = $this->get('/accounting_reports?type=balances');
        $totals   = $this->get('/accounting_reports?type=totals_balances');
        $movement = $this->get('/accounting_reports?type=movement');

        $balances->assertSee(lang('Accounting_reports.closing_debit'));
        $balances->assertDontSee(lang('Accounting_reports.period_debit'));
        $balances->assertDontSee(lang('Accounting_reports.opening_debit'));

        $totals->assertSee(lang('Accounting_reports.period_debit'));
        $totals->assertSee(lang('Accounting_reports.closing_credit'));
        $totals->assertDontSee(lang('Accounting_reports.opening_debit'));

        $movement->assertSee(lang('Accounting_reports.opening_debit'));
        $movement->assertSee(lang('Accounting_reports.period_credit'));
        $movement->assertSee(lang('Accounting_reports.closing_debit'));
    }

    public function testGetIndex_FallsBackToTheTotalsAndBalancesForAFormItDoesNotKnow(): void
    {
        $this->viewer();

        $response = $this->get('/accounting_reports?type=nonsense');

        $response->assertStatus(200);
        $response->assertSee(lang('Accounting_reports.period_debit'));
        $response->assertDontSee(lang('Accounting_reports.opening_debit'));
    }

    public function testGetIndex_AddsUpTheBranchesAskedFor(): void
    {
        $this->threeBranches();
        $this->viewer();

        $response = $this->get('/accounting_reports?branches[]=1&branches[]=' . $this->second);

        $response->assertSee($this->amountOf('3333.00'));
        $response->assertDontSee($this->amountOf('4444.00'));
        $response->assertDontSee($this->amountOf('7777.00'));
        $response->assertSee(lang('Accounting_reports.scope_selected', [$this->codes($this->branchCode(1), 'T2')]));
    }

    public function testGetIndex_CutsWhatIsAskedDownToTheBranchesTheEmployeeWorksIn(): void
    {
        $this->threeBranches();
        $this->viewer([1, $this->second]);

        $response = $this->get('/accounting_reports?branches[]=1&branches[]=' . $this->second . '&branches[]=' . $this->third);

        $response->assertSee($this->amountOf('3333.00'));
        $response->assertDontSee($this->amountOf('4444.00'));
        $response->assertDontSee($this->amountOf('7777.00'));
        $response->assertDontSee('T3');
    }

    public function testGetIndex_SaysTheTotalIsOfTheBranchesTheEmployeeWorksIn(): void
    {
        $this->threeBranches();
        $this->viewer([1, $this->second]);

        $response = $this->get('/accounting_reports');

        $response->assertSee(lang('Accounting_reports.scope_partial', [$this->codes($this->branchCode(1), 'T2')]));
        $response->assertSee('HQ, T2');
        $response->assertSee('class="alert alert-warning" id="report_scope"');
        $response->assertDontSee(lang('Accounting_reports.scope_company'));
        $response->assertSee($this->amountOf('3333.00'));
    }

    public function testGetIndex_SaysNothingOfBranchesToWhoWorksInOne(): void
    {
        $this->threeBranches();
        $this->viewer([1]);

        $response = $this->get('/accounting_reports');

        $response->assertSee($this->amountOf('1111.00'));
        $response->assertDontSee($this->amountOf('2222.00'));
        $response->assertDontSee('id="report_scope"');
        $response->assertDontSee('name="branches[]"');
    }

    public function testGetIndex_AddsUpNothingForABranchOnlyOthersWorkIn(): void
    {
        $this->threeBranches();
        $this->viewer([1]);

        $response = $this->get('/accounting_reports?branches[]=' . $this->second);

        $response->assertSee('id="report_no_branches"');
        $response->assertDontSee($this->amountOf('2222.00'));
        $response->assertDontSee($this->amountOf('1111.00'));
    }

    public function testGetIndex_TellsThereIsNothingToAddUpWhenNoBranchAskedForIsTheirs(): void
    {
        $this->threeBranches();
        $this->viewer([1, $this->second]);

        $response = $this->get('/accounting_reports?branches[]=' . $this->third);

        $response->assertSee('id="report_no_branches"');
        $response->assertDontSee($this->amountOf('4444.00'));
    }

    public function testGetIndex_OffersTheBranchesToWhoWorksInSeveral(): void
    {
        $this->viewer([1, $this->second]);

        $response = $this->get('/accounting_reports');

        $response->assertSee('id="f_branches"');
        $response->assertSee('name="branches[]"');
        $response->assertSee('T2 - Second branch');
        $response->assertDontSee('T3 - Third branch');
    }

    public function testGetIndex_TellsWhatIsLeftBetweenTheBranchesOnlyForTheWholeCompany(): void
    {
        $this->postOn('2026-10-05', 1, [[$this->accountId('branches_receivable'), '50.00', '0'], [$this->accountId('cash'), '0', '50.00']]);

        $this->viewer();
        $company = $this->get('/accounting_reports');

        $this->viewer([1, $this->second]);
        $partial = $this->get('/accounting_reports');

        $company->assertSee('id="report_inter_branch_difference"');
        $company->assertSee($this->amountOf('50.00'));
        $partial->assertDontSee('id="report_inter_branch_difference"');
        $partial->assertDontSee('id="report_inter_branch_ok"');
    }

    public function testGetIndex_SaysTheBranchesOffsetWhenTheyDo(): void
    {
        $this->postOn('2026-10-05', 1, [[$this->accountId('branches_receivable'), '50.00', '0'], [$this->accountId('cash'), '0', '50.00']]);
        $this->postOn('2026-10-05', $this->second, [[$this->accountId('cash'), '50.00', '0'], [$this->accountId('branches_payable'), '0', '50.00']]);
        $this->viewer();

        $response = $this->get('/accounting_reports');

        $response->assertSee('id="report_inter_branch_ok"');
        $response->assertDontSee('id="report_inter_branch_difference"');
    }

    public function testGetIndex_FoldsTheAccountOfACustomerUnlessAsked(): void
    {
        $customer = $this->insertCustomer('Omar', 'Khaled');
        $entity   = (new EntityAccountService())->ensure(EntityAccountService::CUSTOMER, $customer, 'Omar Khaled');

        $this->postOn('2026-10-05', 1, [[$entity, '250.00', '0'], [$this->accountId('sales_revenue'), '0', '250.00']]);
        $this->viewer();

        $folded   = $this->get('/accounting_reports');
        $expanded = $this->get('/accounting_reports?entities=1');

        $folded->assertDontSee('Omar Khaled');
        $folded->assertSee($this->amountOf('250.00'));
        $expanded->assertSee('Omar Khaled');
    }

    public function testGetIndex_LeavesOutAccountsThatHoldNothingUnlessAsked(): void
    {
        $this->cashSale('2026-10-05', 1, '100.00');
        $this->viewer();

        $without = $this->get('/accounting_reports');
        $with    = $this->get('/accounting_reports?empty=1');

        $this->assertGreaterThan($this->accountRowsOf($without), $this->accountRowsOf($with));
        $without->assertSee('data-account="' . $this->codeOf('cash') . '"');
        $without->assertDontSee('data-account="' . $this->codeOf('cogs') . '"');
        $with->assertSee('data-account="' . $this->codeOf('cogs') . '"');
    }

    public function testGetIndex_ReadsThePeriodAsked(): void
    {
        $this->cashSale('2026-10-05', 1, '1111.00');
        $this->cashSale('2026-10-15', 1, '500.00');
        $this->viewer();

        $response = $this->get('/accounting_reports?type=movement&from=2026-10-10&to=2026-10-31');

        $response->assertSee($this->amountOf('1111.00'));
        $response->assertSee($this->amountOf('500.00'));
        $response->assertSee($this->amountOf('1611.00'));
        $response->assertSee(lang('Accounting_reports.period_between', [to_date(strtotime('2026-10-10')), to_date(strtotime('2026-10-31'))]));
    }

    public function testGetIndex_AddsUpNothingAfterTheEndOfThePeriod(): void
    {
        $this->cashSale('2026-10-05', 1, '1111.00');
        $this->cashSale('2026-11-05', 1, '2222.00');
        $this->viewer();

        $response = $this->get('/accounting_reports?to=2026-10-31');

        $response->assertSee($this->amountOf('1111.00'));
        $response->assertDontSee($this->amountOf('2222.00'));
        $response->assertDontSee($this->amountOf('3333.00'));
    }

    public function testGetIndex_ShowsAMessageForADayThatIsNotOneAndStillShowsTheReport(): void
    {
        $this->cashSale('2026-10-05', 1, '1111.00');
        $this->viewer();

        $response = $this->get('/accounting_reports?from=2026-13-45');

        $response->assertStatus(200);
        $response->assertSee('id="report_error"');
        $response->assertSee(lang('Accounting.error_invalid_date'));
        $response->assertSee('id="trial_balance_table"');
        $response->assertSee('2026-13-45');
    }

    public function testGetIndex_ShowsAMessageForAStartAfterTheEnd(): void
    {
        $this->viewer();

        $response = $this->get('/accounting_reports?from=2026-10-10&to=2026-10-01');

        $response->assertSee('id="report_error"');
        $response->assertSee(lang('Accounting.error_report_period_invalid'));
    }

    public function testGetIndex_SaysTheTrialBalanceDoesNotBalanceWhenAJournalWasChangedOutsideTheBooks(): void
    {
        $this->cashSale('2026-10-05', 1, '100.00');
        $this->viewer();

        $this->get('/accounting_reports')->assertSee('id="report_balanced"');

        $this->accountingDb()->query('UPDATE ' . $this->accountingDb()->prefixTable('accounting_journal_lines') . ' SET debit = debit + 5 LIMIT 1');

        $response = $this->get('/accounting_reports');

        $response->assertSee('id="report_unbalanced"');
        $response->assertDontSee('id="report_balanced"');
    }

    public function testGetIndex_LinksEachAccountToItsStatement(): void
    {
        $this->cashSale('2026-10-05', 1, '100.00');
        $this->viewer();

        $response = $this->get('/accounting_reports');

        $response->assertSee('accounting_reports/ledger?account=' . $this->accountId('cash'));
    }

    public function testGetIndex_KeepsTheBranchesAndTheDaysAskedForInTheLinksToStatements(): void
    {
        $this->cashSale('2026-10-05', $this->second, '100.00');
        $this->viewer();

        $response = $this->get('/accounting_reports?type=movement&branches[]=' . $this->second . '&from=2026-10-01&to=2026-10-31');

        $response->assertSee('branches%5B0%5D=' . $this->second);
        $response->assertSee('from=2026-10-01');
        $response->assertSee('to=2026-10-31');
    }

    public function testGetIndex_LinksToTheIncomeStatementAndTheBalanceSheet(): void
    {
        $this->viewer();

        $response = $this->get('/accounting_reports');

        $response->assertSee('id="link_income_statement" href="' . base_url('accounting_statements/income_statement') . '"');
        $response->assertSee('id="link_balance_sheet" href="' . base_url('accounting_statements/balance_sheet') . '"');
    }

    public function testGetLedger_LinksToTheIncomeStatementAndTheBalanceSheet(): void
    {
        $this->viewer();

        $response = $this->get('/accounting_reports/ledger');

        $response->assertSee('id="link_income_statement" href="' . base_url('accounting_statements/income_statement') . '"');
        $response->assertSee('id="link_balance_sheet" href="' . base_url('accounting_statements/balance_sheet') . '"');
    }

    public function testGetLedger_ShowsNothingUntilAnAccountIsChosen(): void
    {
        $this->cashSale('2026-10-05', 1, '100.00');
        $this->viewer();

        $response = $this->get('/accounting_reports/ledger');

        $response->assertStatus(200);
        $response->assertSee('id="ledger_none_selected"');
        $response->assertDontSee('id="ledger_table"');
    }

    public function testGetLedger_RedirectsAUserWithoutTheModuleGrant(): void
    {
        $this->actAs($this->employeeWithGrants(['accounting_payables']));

        $this->get('/accounting_reports/ledger')->assertRedirect();
    }

    public function testGetLedger_ShowsTheLinesOfAnAccountWithTheBalanceAfterEach(): void
    {
        $this->cashSale('2026-10-05', 1, '1111.00');
        $this->postOn('2026-10-15', 1, [[$this->accountId('cogs'), '300.00', '0'], [$this->accountId('cash'), '0', '300.00']]);
        $this->viewer();

        $response = $this->get('/accounting_reports/ledger?account=' . $this->accountId('cash'));

        $response->assertStatus(200);
        $response->assertSee('id="ledger_table"');
        $response->assertSee('id="ledger_summary"');
        $this->assertSame(3, $this->rowsOf($response), 'One heading row and the two lines');
        $this->assertSame([$this->amountOf('1111.00'), $this->amountOf('811.00')], $this->balancesOf($response), 'The balance after each line');
    }

    public function testGetLedger_StartsFromWhereTheAccountStoodWhenThePeriodOpened(): void
    {
        $this->cashSale('2026-09-20', 1, '1000.00');
        $this->cashSale('2026-10-05', 1, '250.00');
        $this->viewer();

        $response = $this->get('/accounting_reports/ledger?account=' . $this->accountId('cash') . '&from=2026-10-01');

        $response->assertSee($this->amountOf('1000.00'));
        $response->assertSee($this->amountOf('1250.00'));
        $this->assertSame(2, $this->rowsOf($response), 'One heading row and the line of the period');
    }

    public function testGetLedger_IsNotFoundForAnAccountThatDoesNotExist(): void
    {
        $this->viewer();

        $this->expectException(PageNotFoundException::class);

        $this->get('/accounting_reports/ledger?account=99999999');
    }

    public function testGetLedger_AddsUpOnlyTheBranchesTheEmployeeWorksIn(): void
    {
        $this->cashSale('2026-10-05', 1, '1111.00');
        $this->cashSale('2026-10-06', $this->second, '2222.00');
        $this->viewer([1]);
        $cash = $this->accountId('cash');

        $own   = $this->get('/accounting_reports/ledger?account=' . $cash);
        $other = $this->get('/accounting_reports/ledger?account=' . $cash . '&branches[]=' . $this->second);

        $own->assertSee($this->amountOf('1111.00'));
        $own->assertDontSee($this->amountOf('2222.00'));
        $own->assertDontSee($this->amountOf('3333.00'));
        $other->assertSee('id="report_no_branches"');
        $other->assertDontSee($this->amountOf('2222.00'));
    }

    public function testGetLedger_SaysWhichBranchEachLineBelongsToToWhoWorksInSeveral(): void
    {
        $this->cashSale('2026-10-05', 1, '1111.00');
        $this->cashSale('2026-10-06', $this->second, '2222.00');
        $this->viewer([1, $this->second]);

        $response = $this->get('/accounting_reports/ledger?account=' . $this->accountId('cash'));

        $response->assertSee('<th scope="col">' . lang('Accounting_reports.ledger_branch') . '</th>');
        $response->assertSee('>T2<');
        $response->assertSee(lang('Accounting_reports.scope_partial', [$this->codes($this->branchCode(1), 'T2')]));
    }

    public function testGetLedger_LinksTheJournalOnlyToWhoMayOpenJournals(): void
    {
        $journalId = $this->cashSale('2026-10-05', 1, '100.00');

        $this->viewer();
        $without = $this->get('/accounting_reports/ledger?account=' . $this->accountId('cash'));

        $this->viewer(extra: ['accounting_journals']);
        $with = $this->get('/accounting_reports/ledger?account=' . $this->accountId('cash'));

        $without->assertDontSee('accounting_journals/view/' . $journalId);
        $with->assertSee('accounting_journals/view/' . $journalId);
    }

    public function testGetLedger_MarksTheReversalOfAJournal(): void
    {
        $journalId = $this->cashSale('2026-10-05', 1, '100.00');
        $this->reverseOn($journalId, '2026-10-06');
        $this->viewer();

        $response = $this->get('/accounting_reports/ledger?account=' . $this->accountId('cash'));

        $response->assertSee('(' . lang('Accounting.source_reversal') . ')');
        $this->assertSame(3, $this->rowsOf($response));
    }

    public function testGetLedger_ReadsTheAccountsUnderAGroupWithTheirNames(): void
    {
        $this->cashSale('2026-10-05', 1, '100.00');
        $this->viewer();

        $response = $this->get('/accounting_reports/ledger?account=' . $this->accountIdByCode('1201'));

        $response->assertSee('id="ledger_accounts_counted"');
        $response->assertSee('<th scope="col">' . lang('Accounting_reports.account') . '</th>');
        $response->assertSee('<td><bdi dir="ltr">120101</bdi> ');
    }

    public function testGetLedger_PagesALongStatementWithTheBalanceGoingOnFromPageToPage(): void
    {
        foreach (range(1, 102) as $line) {
            $this->cashSale('2026-10-05', 1, '1.00');
        }

        $this->viewer();
        $cash = $this->accountId('cash');

        $first  = $this->get('/accounting_reports/ledger?account=' . $cash);
        $second = $this->get('/accounting_reports/ledger?account=' . $cash . '&page=2');

        $this->assertSame(101, $this->rowsOf($first), 'One heading row and a hundred lines');
        $first->assertSee('rel="next"');
        $first->assertDontSee('rel="prev"');
        $this->assertSame([$this->amountOf('1.00'), $this->amountOf('100.00')], [$this->balancesOf($first)[0], $this->balancesOf($first)[99]]);

        $this->assertSame(3, $this->rowsOf($second), 'One heading row and the two lines left');
        $second->assertSee('rel="prev"');
        $second->assertDontSee('rel="next"');
        $this->assertSame([$this->amountOf('101.00'), $this->amountOf('102.00')], $this->balancesOf($second));
        $second->assertSee('account=' . $cash);
    }

    public function testGetLedger_OffersNoNextPageForExactlyOnePageAndOffersItForOneLineMore(): void
    {
        foreach (range(1, 100) as $line) {
            $this->cashSale('2026-10-05', 1, '1.00');
        }

        $this->viewer();
        $address = '/accounting_reports/ledger?account=' . $this->accountId('cash');

        $this->get($address)->assertDontSee('rel="next"');

        $this->cashSale('2026-10-05', 1, '1.00');

        $this->get($address)->assertSee('rel="next"');
        $this->assertSame([$this->amountOf('101.00')], $this->balancesOf($this->get($address . '&page=2')));
    }

    public function testGetLedger_ShowsTheLastPageForAPageBeyondIt(): void
    {
        foreach (range(1, 102) as $line) {
            $this->cashSale('2026-10-05', 1, '1.00');
        }

        $this->viewer();
        $cash = $this->accountId('cash');

        foreach (['9', '9223372036854775807', '99999999999999999999'] as $page) {
            $response = $this->get('/accounting_reports/ledger?account=' . $cash . '&page=' . $page);

            $response->assertStatus(200);
            $response->assertDontSee('id="ledger_empty"');
            $response->assertSee('rel="prev"');
            $this->assertSame([$this->amountOf('101.00'), $this->amountOf('102.00')], $this->balancesOf($response), 'Page ' . $page);
        }
    }

    public function testGetLedger_ShowsAMessageForADayThatIsNotOne(): void
    {
        $this->viewer();

        $response = $this->get('/accounting_reports/ledger?account=' . $this->accountId('cash') . '&to=2026-02-31');

        $response->assertStatus(200);
        $response->assertSee('id="report_error"');
        $response->assertSee(lang('Accounting.error_invalid_date'));
        $response->assertSee('value="2026-02-31"');
        $response->assertSee('<option value="' . $this->accountId('cash') . '" selected>');
    }

    public function testGetLedger_OffersTheAccountOfAPartyOnlyWhenItIsTheOneChosen(): void
    {
        $customer = $this->insertCustomer('Omar', 'Khaled');
        $entity   = (new EntityAccountService())->ensure(EntityAccountService::CUSTOMER, $customer, 'Omar Khaled');

        $this->postOn('2026-10-05', 1, [[$entity, '250.00', '0'], [$this->accountId('sales_revenue'), '0', '250.00']]);
        $this->viewer();

        $none   = $this->get('/accounting_reports/ledger');
        $chosen = $this->get('/accounting_reports/ledger?account=' . $entity);

        $none->assertDontSee('Omar Khaled');
        $chosen->assertSee('Omar Khaled');
        $chosen->assertSee($this->amountOf('250.00'));
    }

    public function testGetIndex_ReadsAnAddressThatNamesAnOptionMoreThanOnceAsNamingNone(): void
    {
        $this->cashSale('2026-10-05', 1, '1111.00');
        $this->viewer();

        $response = $this->get('/accounting_reports?type[]=movement&from[]=2026-10-01&to[]=2026-10-31&entities[]=1&empty[]=1');

        $response->assertStatus(200);
        $response->assertDontSee('id="report_error"');
        $response->assertSee($this->amountOf('1111.00'));
    }

    public function testGetLedger_ReadsAnAddressThatNamesAnOptionMoreThanOnceAsNamingNone(): void
    {
        $this->cashSale('2026-10-05', 1, '1111.00');
        $this->viewer();

        $response = $this->get('/accounting_reports/ledger?account=' . $this->accountId('cash') . '&from[]=2026-10-01&to[]=2026-10-31&page[]=2&branches[]=1');

        $response->assertStatus(200);
        $response->assertDontSee('id="report_error"');
        $this->assertSame([$this->amountOf('1111.00')], $this->balancesOf($response));
    }

    public function testGetIndex_AddsUpABranchThatWasDeactivatedAndOffersItToBeChosen(): void
    {
        $this->threeBranches();
        $this->accountingDb()->table('branches')->where('branch_id', $this->third)->update(['active' => 0]);
        $this->viewer();

        $everything = $this->get('/accounting_reports');

        $everything->assertSee($this->amountOf('7777.00'));
        $everything->assertSee(lang('Accounting_reports.scope_company'));
        $everything->assertSee('T3 - Third branch (' . lang('Accounting_reports.branch_inactive') . ')');

        $applied = $this->get('/accounting_reports?branches[]=1&branches[]=' . $this->second . '&branches[]=' . $this->third);

        $applied->assertSee($this->amountOf('7777.00'));
        $applied->assertSee(lang('Accounting_reports.scope_company'));
    }

    public function testGetIndex_SaysTheTotalIsPartialToWhoWorksInAnActiveAndADeactivatedBranch(): void
    {
        $this->threeBranches();
        $this->accountingDb()->table('branches')->where('branch_id', $this->third)->update(['active' => 0]);
        $this->viewer([1, $this->third]);

        $response = $this->get('/accounting_reports');

        $response->assertSee(lang('Accounting_reports.scope_partial', [$this->codes($this->branchCode(1), 'T3')]));
        $response->assertSee('name="branches[]"');
        $response->assertSee($this->amountOf('5555.00'));
        $response->assertDontSee($this->amountOf('7777.00'));
    }

    public function testGetIndex_KeepsNothingToAddUpInTheLinksToStatements(): void
    {
        $this->cashSale('2026-10-05', $this->second, '2222.00');
        $this->cashSale('2026-10-05', 1, '1111.00');
        $this->viewer([1]);

        $response = $this->get('/accounting_reports?empty=1&branches[]=' . $this->second);

        $response->assertSee('branches%5B0%5D=0');

        $followed = $this->get('/accounting_reports/ledger?account=' . $this->accountId('cash') . '&branches[0]=0');

        $followed->assertSee('id="report_no_branches"');
        $followed->assertDontSee($this->amountOf('1111.00'));
        $followed->assertDontSee($this->amountOf('2222.00'));
    }

    public function testGetIndex_SaysNoWhoseFiguresWhenThereIsNothingToAddUp(): void
    {
        $this->viewer([1, $this->second]);

        $response = $this->get('/accounting_reports?branches[]=' . $this->third);

        $response->assertSee('id="report_no_branches"');
        $response->assertDontSee('id="report_scope"');
    }

    public function testGetIndex_AddsUpFromTheStartOfTheBooksInTheLayoutsThatIgnoreTheFirstDayAndSaysSo(): void
    {
        $this->cashSale('2026-10-05', 1, '1111.00');
        $this->cashSale('2026-10-20', 1, '500.00');
        $this->viewer();

        foreach (['balances', 'totals_balances'] as $type) {
            $response = $this->get('/accounting_reports?type=' . $type . '&from=2026-10-10&to=2026-10-31');

            $response->assertSee('id="report_from_ignored"');
            $response->assertSee($this->amountOf('1611.00'));
            $response->assertSee(lang('Accounting_reports.period_to', [to_date(strtotime('2026-10-31'))]));
        }

        $movement = $this->get('/accounting_reports?type=movement&from=2026-10-10&to=2026-10-31');

        $movement->assertDontSee('id="report_from_ignored"');
        $movement->assertSee($this->amountOf('1111.00'));
    }

    public function testGetIndex_SaysThatRevenueAndExpenseAddUpFromTheStartOfTheBooks(): void
    {
        $this->viewer();

        $this->get('/accounting_reports')->assertSee('id="report_nominal_note"');
    }

    public function testGetIndex_SaysThereIsNothingToShowForAPeriodWithNoJournals(): void
    {
        $this->cashSale('2026-10-05', 1, '1111.00');
        $this->viewer();

        $response = $this->get('/accounting_reports?to=2000-01-01');

        $response->assertSee('id="report_empty"');
        $response->assertDontSee($this->amountOf('1111.00'));
    }

    public function testGetIndex_OffersTheLayoutsAndKeepsTheOneChosen(): void
    {
        $this->viewer();

        $response = $this->get('/accounting_reports?type=movement');

        $response->assertSee('<option value="movement" selected>');
        $response->assertSee('<option value="balances">');
    }

    public function testGetLedger_SaysEmptyForAnAccountThatNothingTouchedInThePeriod(): void
    {
        $this->cashSale('2026-10-05', 1, '1111.00');
        $this->viewer();

        $response = $this->get('/accounting_reports/ledger?account=' . $this->accountId('cash') . '&from=2026-10-20&to=2026-10-31');

        $response->assertSee('id="ledger_empty"');
        $response->assertSee('id="ledger_summary"');
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
     * @return list<string> The numeric cells of the row of the account, as the screen shows them.
     */
    private function cellsOf(TestResponse $response, string $code): array
    {
        $cells = [];

        foreach ($this->pageOf($response)->query('//tr[@data-account="' . $code . '"]/td[contains(@class, "text-end")]') as $cell) {
            $cells[] = trim($cell->textContent);
        }

        return $cells;
    }

    /**
     * @return list<string> The headings of the table of the trial balance.
     */
    private function headingsOf(TestResponse $response): array
    {
        $headings = [];

        foreach ($this->pageOf($response)->query('//table[@id="trial_balance_table"]/thead/tr/th') as $heading) {
            $headings[] = trim($heading->textContent);
        }

        return $headings;
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
     * @return list<string>|null The first row that starts with the cell.
     */
    private function csvRowStartingWith(array $rows, string $cell): ?array
    {
        foreach ($rows as $row) {
            if (($row[0] ?? null) === $cell) {
                return $row;
            }
        }

        return null;
    }

    private function bookedBy(string $first, string $last): int
    {
        return $this->createEmployee(first_name: $first, last_name: $last);
    }

    private function reviewBooks(): void
    {
        $this->cashSale('2026-10-05', 1, '100.00');
        $this->postOn('2026-10-06', 1, [[$this->accountId('cogs'), '30.00', '0'], [$this->accountId('cash'), '0', '30.00']]);
        $this->cashSale('2026-10-08', 1, '5.00');
    }

    public function testGetIndex_ShowsSixNumericCellsPerAccountInTheReviewLayoutAndNoBalancesGroup(): void
    {
        $this->reviewBooks();
        $this->viewer();

        $response = $this->get('/accounting_reports?type=review_totals&from=2026-10-07&to=2026-10-31');

        $response->assertStatus(200);
        $this->assertSame(
            [$this->amountOf('100.00'), $this->amountOf('30.00'), $this->amountOf('5.00'), '', $this->amountOf('105.00'), $this->amountOf('30.00')],
            $this->cellsOf($response, $this->codeOf('cash')),
            'Before, movement and after, a debit side and a credit side each, the sides added up and not their difference',
        );
        $this->assertCount(8, $this->headingsOf($response), 'Code and account, then the six numeric headings');
        $response->assertSee(lang('Accounting_reports.before_debit'));
        $response->assertSee(lang('Accounting_reports.after_credit'));
        $response->assertDontSee(lang('Accounting_reports.closing_debit'));
        $response->assertDontSee(lang('Accounting_reports.opening_debit'));
    }

    public function testGetIndex_AddsUpTheGrossSidesBeforeAndAfterInTheReviewLayoutAndCallsItByWhatItIs(): void
    {
        $this->reviewBooks();
        $this->viewer();

        $response = $this->get('/accounting_reports?type=review_totals&from=2026-10-07&to=2026-10-31');

        $this->assertSame(
            [$this->amountOf('130.00'), $this->amountOf('130.00'), $this->amountOf('5.00'), $this->amountOf('5.00'), $this->amountOf('135.00'), $this->amountOf('135.00')],
            array_map('trim', array_map(static fn ($cell): string => $cell->textContent, iterator_to_array($this->pageOf($response)->query('//tr[@id="trial_balance_totals"]/td')))),
            'Before and after are the sides added up, and each side adds up to the same on its own',
        );
        $response->assertSee('id="report_review_note"');
        $response->assertSee(lang('Accounting_reports.review_note'));
        $response->assertSee(lang('Accounting_reports.type_review_totals'));
        $response->assertSee('id="report_balanced"');
    }

    public function testGetIndex_StartsTheReviewLayoutFromTheBeginningOfTheBooksWithoutAFirstDay(): void
    {
        $this->reviewBooks();
        $this->viewer();

        $response = $this->get('/accounting_reports?type=review_totals&to=2026-10-31');

        $this->assertSame(['', '', $this->amountOf('105.00'), $this->amountOf('30.00'), $this->amountOf('105.00'), $this->amountOf('30.00')], $this->cellsOf($response, $this->codeOf('cash')));
        $response->assertDontSee('id="report_from_ignored"');
    }

    public function testGetIndex_OffersDepthsOneToFiveAndKeepsTheOneChosen(): void
    {
        $this->viewer();

        $response = $this->get('/accounting_reports?level=3');

        $options = [];

        foreach ($this->pageOf($response)->query('//select[@id="f_level"]/option') as $option) {
            $options[] = $option->getAttribute('value');
        }

        $this->assertSame(['', '1', '2', '3', '4', '5'], $options, 'Every level, and no sixth');
        $response->assertSee('<option value="3" selected>');
    }

    public function testGetIndex_ListsOnlyTheDepthAskedAndKeepsTheTotalsOfTheRoots(): void
    {
        $this->reviewBooks();
        $this->viewer();

        $deep    = $this->get('/accounting_reports');
        $shallow = $this->get('/accounting_reports?level=1');

        $this->assertSame(['1', '4', '5'], $this->accountCodesOf($shallow));
        $this->assertGreaterThan($this->accountRowsOf($shallow), $this->accountRowsOf($deep));
        $this->assertSame($this->totalsOf($deep), $this->totalsOf($shallow), 'The depth changes what is listed and never what is added up');
    }

    /**
     * @return list<string>
     */
    private function accountCodesOf(TestResponse $response): array
    {
        $codes = [];

        foreach ($this->pageOf($response)->query('//table[@id="trial_balance_table"]/tbody/tr[@data-account]') as $row) {
            $codes[] = $row->getAttribute('data-account');
        }

        return $codes;
    }

    /**
     * @return list<string>
     */
    private function totalsOf(TestResponse $response): array
    {
        return array_map(static fn ($cell): string => trim($cell->textContent), iterator_to_array($this->pageOf($response)->query('//tr[@id="trial_balance_totals"]/td')));
    }

    public function testGetIndex_ListsOnlyAccountsWithMovementWhenAsked(): void
    {
        $this->cashSale('2026-10-05', 1, '100.00');
        $this->viewer();

        $response = $this->get('/accounting_reports?display=activity&from=2026-10-10&type=movement');

        $this->assertSame([], $this->accountCodesOf($response), 'Nothing moved from the tenth of October');
        $response->assertSee('<option value="activity" selected>');
    }

    public function testGetIndex_ListsAMainAccountWithTheAccountsUnderItAndSaysTheRunIsFiltered(): void
    {
        $this->reviewBooks();
        $this->viewer();

        $response = $this->get('/accounting_reports?account=' . $this->accountIdByCodeOf('1201'));

        $this->assertSame(['1201', $this->codeOf('cash')], $this->accountCodesOf($response));
        $response->assertSee('id="report_filtered_run"');
        $response->assertDontSee('id="report_balanced"');
        $response->assertDontSee('id="report_unbalanced"');
    }

    public function testGetIndex_ListsOnlyTheAccountsNamedAndSaysTheRunIsFiltered(): void
    {
        $this->reviewBooks();
        $this->viewer();

        $response = $this->get('/accounting_reports?accounts[]=' . $this->accountId('cash') . '&accounts[]=' . $this->accountId('cogs') . '&accounts[]=abc');

        $this->assertSame([$this->codeOf('cash'), $this->codeOf('cogs')], $this->accountCodesOf($response), 'Listed alone, in the order of the chart');
        $response->assertSee('id="report_filtered_run"');
    }

    public function testGetIndex_IgnoresAnAccountOfAnotherCompanyOrOneThatDoesNotExist(): void
    {
        $this->cashSale('2026-10-05', 1, '100.00');
        $this->viewer();

        $response = $this->get('/accounting_reports?accounts[]=987654');

        $this->assertSame([], $this->accountCodesOf($response));
    }

    public function testGetIndex_OffersTheLevelDisplayAndAccountChoicesAndFoldsNoPartyIntoTheList(): void
    {
        $this->viewer();

        $response = $this->get('/accounting_reports');

        $response->assertSee('id="f_level"');
        $response->assertSee('id="f_display"');
        $response->assertSee('id="f_account"');
        $response->assertSee('id="f_accounts"');
        $response->assertSee('<option value="hide_zero">');
    }

    private function accountIdByCodeOf(string $code): int
    {
        return (int) $this->accountingDb()->table('accounting_accounts')->where('code', $code)->get()->getRow()->account_id;
    }

    public function testGetCsv_GivesTheRowsAndTotalsOfTheScreenForTheReviewLayoutWithItsHonestName(): void
    {
        $this->reviewBooks();
        $this->viewer();

        $response = $this->get('/accounting_reports/csv?type=review_totals&from=2026-10-07&to=2026-10-31');

        $response->assertStatus(200);
        $this->assertStringContainsString('text/csv', $response->response()->getHeaderLine('Content-Type'));
        $this->assertStringContainsString('attachment; filename="trial_balance_review_totals_2026-10-07_2026-10-31.csv"', $response->response()->getHeaderLine('Content-Disposition'));

        $rows = $this->csvRows($response);

        $this->assertSame([lang('Accounting_reports.code'), lang('Accounting_reports.account'), lang('Accounting_reports.before_debit'), lang('Accounting_reports.before_credit'), lang('Accounting_reports.period_debit'), lang('Accounting_reports.period_credit'), lang('Accounting_reports.after_debit'), lang('Accounting_reports.after_credit')], $rows[0]);

        $cash = $this->csvRowStartingWith($rows, $this->codeOf('cash'));

        $this->assertSame(['100.0000', '30.0000', '5.0000', '0.0000', '105.0000', '30.0000'], array_slice($cash, 2));
        $this->assertSame(['130.0000', '130.0000', '5.0000', '5.0000', '135.0000', '135.0000'], array_slice($this->csvRowStartingWith($rows, '') ?? [], 2, 6));
        $this->assertSame(lang('Accounting_reports.type_review_totals'), $this->csvRowStartingWith($rows, lang('Accounting_reports.type'))[1]);
        $this->assertNotNull($this->csvRowStartingWith($rows, lang('Accounting_reports.review_note')), 'The file says what the layout is and is not');
        $this->assertNotNull($this->csvRowStartingWith($rows, lang('Accounting_reports.balanced')));
    }

    public function testGetCsv_KeepsTheFiltersTheScreenWasAskedWith(): void
    {
        $this->reviewBooks();
        $this->viewer();

        $screen = $this->get('/accounting_reports?type=movement&from=2026-10-07&to=2026-10-31&accounts[]=' . $this->accountId('cash'));
        $file   = $this->get('/accounting_reports/csv?type=movement&from=2026-10-07&to=2026-10-31&accounts[]=' . $this->accountId('cash'));

        $rows = $this->csvRows($file);

        $this->assertSame($this->accountCodesOf($screen), array_column(array_slice($rows, 1, 1), 0));
        $this->assertNotNull($this->csvRowStartingWith($rows, lang('Accounting_reports.filtered_run')), 'A filtered file says it is not expected to balance');
        $this->assertNull($this->csvRowStartingWith($rows, lang('Accounting_reports.unbalanced')));
        $screen->assertSee('csv?type=movement&amp;from=2026-10-07&amp;to=2026-10-31&amp;accounts%5B0%5D=' . $this->accountId('cash'));
    }

    public function testGetCsv_AddsUpOnlyTheBranchesTheEmployeeWorksInWhateverTheRequestAsks(): void
    {
        $this->threeBranches();
        $this->viewer([1, $this->second]);

        $response = $this->get('/accounting_reports/csv?type=balances&branches[]=1&branches[]=' . $this->second . '&branches[]=' . $this->third);

        $rows = $this->csvRows($response);
        $cash = $this->csvRowStartingWith($rows, $this->codeOf('cash'));

        $this->assertSame('3333.0000', $cash[2], 'The third branch is not theirs, so it is not in the file');
        $this->assertSame($this->branchCode(1) . ' T2', $this->csvRowStartingWith($rows, lang('Accounting_reports.csv_branches'))[1]);
        $this->assertNotNull($this->csvRowStartingWith($rows, lang('Accounting_reports.scope_partial', [$this->codes($this->branchCode(1), 'T2')])));
    }

    public function testGetCsv_RefusesAPeriodThatEndsBeforeItStartsInWords(): void
    {
        $this->viewer();

        $response = $this->get('/accounting_reports/csv?type=movement&from=2026-10-31&to=2026-10-01');

        $response->assertStatus(422);
        $this->assertSame(lang('Accounting.error_report_period_invalid'), $response->response()->getBody());
    }

    public function testGetCsv_RedirectsAUserWithoutTheModuleGrantAndIsNotFoundWhileAccountingIsOff(): void
    {
        $this->actAs($this->employeeWithGrants(['accounting_payables']));

        $this->get('/accounting_reports/csv')->assertRedirect();

        $this->prepareAccounting(false);
        $this->viewer();

        $this->expectException(PageNotFoundException::class);

        $this->get('/accounting_reports/csv');
    }

    public function testGetLedger_KeepsOnlyTheLinesOfTheEmployeeAskedForAndSaysSo(): void
    {
        $first  = $this->bookedBy('Ahmad', 'Ledger');
        $second = $this->bookedBy('Basma', 'Ledger');

        $this->cashSale('2026-10-05', 1, '100.00', $first);
        $this->cashSale('2026-10-06', 1, '25.00', $second);
        $this->viewer();

        $response = $this->get('/accounting_reports/ledger?account=' . $this->accountId('cash') . '&employee=' . $first);

        $this->assertSame([$this->amountOf('100.00')], $this->balancesOf($response));
        $response->assertSee('id="ledger_employee_note"');
        $response->assertSee('Ahmad Ledger');
        $response->assertDontSee('Basma Ledger</td>');
    }

    public function testGetLedger_SaysNothingMatchesForAnEmployeeWithNoLines(): void
    {
        $this->cashSale('2026-10-05', 1, '100.00', $this->bookedBy('Ahmad', 'Ledger'));
        $this->viewer();

        $response = $this->get('/accounting_reports/ledger?account=' . $this->accountId('cash') . '&employee=' . $this->bookedBy('Basma', 'Ledger'));

        $response->assertSee('id="ledger_empty"');
        $response->assertSee(lang('Accounting_reports.ledger_nothing_matches'));
        $response->assertDontSee('id="ledger_table"');
    }

    public function testGetLedger_ReadsAnEmptyOrZeroEmployeeAsEveryone(): void
    {
        $this->cashSale('2026-10-05', 1, '100.00', $this->bookedBy('Ahmad', 'Ledger'));
        $this->viewer();

        foreach (['', '0', 'abc'] as $employee) {
            $response = $this->get('/accounting_reports/ledger?account=' . $this->accountId('cash') . '&employee=' . $employee);

            $this->assertSame([$this->amountOf('100.00')], $this->balancesOf($response), "An employee of '{$employee}' is nobody in particular");
            $response->assertDontSee('id="ledger_employee_note"');
        }
    }

    public function testGetLedger_HidesTheEmployeeColumnOnRequest(): void
    {
        $this->cashSale('2026-10-05', 1, '100.00', $this->bookedBy('Ahmad', 'Ledger'));
        $this->viewer();

        $shown  = $this->get('/accounting_reports/ledger?account=' . $this->accountId('cash'));
        $hidden = $this->get('/accounting_reports/ledger?account=' . $this->accountId('cash') . '&hide_employee=1');

        $shown->assertSee('<th scope="col">' . lang('Accounting_reports.ledger_employee') . '</th>');
        $shown->assertSee('Ahmad Ledger');
        $hidden->assertDontSee('<th scope="col">' . lang('Accounting_reports.ledger_employee') . '</th>');
        $hidden->assertDontSee('Ahmad Ledger</td>');
        $hidden->assertSee('id="f_hide_employee" name="hide_employee" value="1" checked');
    }

    public function testGetLedger_NamesOnlyEmployeesWhoAddedJournalsInTheBranchesOfWhoIsLooking(): void
    {
        $mine   = $this->bookedBy('Ahmad', 'Ledger');
        $theirs = $this->bookedBy('Basma', 'Ledger');

        $this->cashSale('2026-10-05', 1, '10.00', $mine);
        $this->cashSale('2026-10-06', $this->third, '20.00', $theirs);
        $this->viewer([1, $this->second]);

        $response = $this->get('/accounting_reports/ledger?account=' . $this->accountId('cash'));

        $response->assertSee('<option value="' . $mine . '">Ahmad Ledger</option>');
        $response->assertDontSee('Basma Ledger');
    }

    public function testGetLedger_DrawsABlockForEachAccountOfAGroupWithTheGrandTotalAsTheNet(): void
    {
        $till = $this->customAccount('1201', '120102', 'Second till');

        $this->cashSale('2026-10-05', 1, '100.00');
        $this->postOn('2026-10-06', 1, [[$till, '40.00', '0'], [$this->accountId('sales_revenue'), '0', '40.00']]);
        $this->postOn('2026-10-07', 1, [[$this->accountId('cogs'), '30.00', '0'], [$till, '0', '30.00']]);
        $this->viewer();

        $response = $this->get('/accounting_reports/ledger?layout=accounts&account=' . $this->accountIdByCodeOf('1201'));

        $response->assertStatus(200);
        $this->assertSame(2, substr_count($response->getBody(), '<section class="mb-4" data-account="'), 'A block for each account with lines in the period');
        $this->assertSame([$this->amountOf('140.00'), $this->amountOf('30.00'), $this->amountOf('110.00')], array_map(
            static fn ($cell): string => trim($cell->textContent),
            iterator_to_array($this->pageOf($response)->query('//table[@id="ledger_grand_total"]//td')),
        ));
        $response->assertSee('data-block="120102"');
        $response->assertSee(lang('Accounting_reports.ledger_total_net'));
    }

    public function testGetLedger_OffersTheThreeDisplayChoicesAndListsAccountsByTheOneChosen(): void
    {
        $opened = $this->customAccount('1201', '120102', 'Opened earlier');

        $this->postOn('2026-10-02', 1, [[$opened, '75.00', '0'], [$this->accountId('sales_revenue'), '0', '75.00']]);
        $this->cashSale('2026-10-12', 1, '100.00');
        $this->viewer();

        $base = '/accounting_reports/ledger?layout=accounts&from=2026-10-10&to=2026-10-31&account=' . $this->accountIdByCodeOf('1201');

        $activity = $this->get($base . '&display=activity');
        $balance  = $this->get($base . '&display=balance');

        $activity->assertSee('<option value="activity" selected>' . lang('Accounting_reports.ledger_display_activity') . '</option>');
        $activity->assertSee('<option value="all">' . lang('Accounting_reports.ledger_display_all') . '</option>');
        $activity->assertSee('<option value="balance">' . lang('Accounting_reports.ledger_display_balance') . '</option>');
        $this->assertSame(1, substr_count($activity->getBody(), '<section class="mb-4"'));
        $this->assertSame(2, substr_count($balance->getBody(), '<section class="mb-4"'), 'The account that stood at something when the period opened stays');
    }

    public function testGetLedger_RefusesABlockStatementOfMoreBlocksAndLinesThanTheScreenHoldsInWords(): void
    {
        $lines = [[$this->accountId('sales_revenue'), '0', '20.01']];

        for ($index = 0; $index < 2001; $index++) {
            $lines[] = [$this->accountId('cash'), '0.01', '0'];
        }

        $this->postOn('2026-10-05', 1, $lines);
        $this->viewer();

        $response = $this->get('/accounting_reports/ledger?layout=accounts&account=' . $this->accountIdByCodeOf('1201'));

        $response->assertStatus(200);
        $response->assertSee(lang('Accounting.error_ledger_too_large', [2000]));
        $response->assertDontSee('<section class="mb-4"');
    }

    public function testGetLedgerCsv_GivesTheLinesOfTheScreenWithTheOpeningAndTheTotals(): void
    {
        $this->cashSale('2026-09-30', 1, '60.00', $this->bookedBy('Ahmad', 'Ledger'));
        $this->cashSale('2026-10-05', 1, '100.00', $this->bookedBy('Basma', 'Ledger'));
        $this->viewer();

        $response = $this->get('/accounting_reports/ledger/csv?account=' . $this->accountId('cash') . '&from=2026-10-01&to=2026-10-31');

        $response->assertStatus(200);
        $this->assertStringContainsString('filename="account_ledger_' . $this->accountId('cash') . '_2026-10-01_2026-10-31.csv"', $response->response()->getHeaderLine('Content-Disposition'));

        $rows = $this->csvRows($response);

        $this->assertCount(9, $rows[0]);
        $this->assertSame([lang('Accounting_reports.ledger_opening'), '60.0000'], [$rows[1][5], $rows[1][8]]);
        $this->assertSame(['2026-10-05'], [$rows[2][0]]);
        $this->assertSame(['Basma Ledger', '100.0000', '0.0000', '160.0000'], [$rows[2][4], $rows[2][6], $rows[2][7], $rows[2][8]]);
        $this->assertSame(['100.0000', '0.0000', '160.0000'], array_slice(end($rows), 6, 3));
        $this->assertCount(9, end($rows), 'Every line of the file has as many cells as the header');
    }

    public function testGetLedgerCsv_LeavesOutTheEmployeeColumnWhenHiddenAndKeepsTheEmployeeFilter(): void
    {
        $first = $this->bookedBy('Ahmad', 'Ledger');

        $this->cashSale('2026-10-05', 1, '100.00', $first);
        $this->cashSale('2026-10-06', 1, '25.00', $this->bookedBy('Basma', 'Ledger'));
        $this->viewer();

        $response = $this->get('/accounting_reports/ledger/csv?account=' . $this->accountId('cash') . '&hide_employee=1&employee=' . $first);

        $rows = $this->csvRows($response);

        $this->assertCount(8, $rows[0]);
        $this->assertNotContains(lang('Accounting_reports.ledger_employee'), $rows[0]);
        $this->assertCount(4, $rows, 'The header, the opening, one line and the total');
        $this->assertSame(['100.0000', '0.0000', '100.0000'], array_slice($rows[2], 5, 3));
    }

    public function testGetLedgerCsv_GivesABlockForEachAccountWithTheGrandTotalAtTheEnd(): void
    {
        $till = $this->customAccount('1201', '120102', 'Second till');

        $this->cashSale('2026-10-05', 1, '100.00');
        $this->postOn('2026-10-06', 1, [[$till, '40.00', '0'], [$this->accountId('sales_revenue'), '0', '40.00']]);
        $this->viewer();

        $rows = $this->csvRows($this->get('/accounting_reports/ledger/csv?layout=accounts&account=' . $this->accountIdByCodeOf('1201') . '&to=2026-10-31'));

        $this->assertContains('Second till 120102', array_column($rows, 5));
        $last = end($rows);

        $this->assertSame([lang('Accounting_reports.ledger_grand_total'), '140.0000', '0.0000', '140.0000'], [$last[5], $last[6], $last[7], $last[8]]);
        $this->assertCount(9, $last);
    }

    public function testGetLedgerCsv_AddsUpOnlyTheBranchesTheEmployeeWorksIn(): void
    {
        $this->cashSale('2026-10-05', 1, '100.00');
        $this->cashSale('2026-10-06', $this->third, '4000.00');
        $this->viewer([1]);

        $rows = $this->csvRows($this->get('/accounting_reports/ledger/csv?account=' . $this->accountId('cash') . '&branches[]=1&branches[]=' . $this->third));

        $this->assertSame('100.0000', end($rows)[8], 'The branch that is not theirs is not in the file');
    }

    public function testGetLedgerCsv_IsNotFoundWithoutAnAccountOrForOneThatDoesNotExist(): void
    {
        $this->viewer();

        foreach (['', '?account=987654'] as $query) {
            try {
                $this->get('/accounting_reports/ledger/csv' . $query);
                $this->fail('A file with no account to read was given.');
            } catch (PageNotFoundException) {
                $this->addToAssertionCount(1);
            }
        }
    }

    public function testGetLedgerCsv_RefusesAStatementThatIsTooLargeForAFileInWordsAndRedirectsWithoutTheGrant(): void
    {
        $lines = [[$this->accountId('sales_revenue'), '0', '20.01']];

        for ($index = 0; $index < 2001; $index++) {
            $lines[] = [$this->accountId('cash'), '0.01', '0'];
        }

        $this->postOn('2026-10-05', 1, $lines);
        $this->viewer();

        $response = $this->get('/accounting_reports/ledger/csv?layout=accounts&account=' . $this->accountIdByCodeOf('1201'));

        $response->assertStatus(422);
        $this->assertSame(lang('Accounting.error_ledger_too_large', [2000]), $response->response()->getBody());

        $this->actAs($this->employeeWithGrants(['accounting_payables']));

        $this->get('/accounting_reports/ledger/csv?account=' . $this->accountId('cash'))->assertRedirect();
    }

    public function testGetLedgerCsv_RefusesAMergedStatementOfMoreLinesThanAFileHoldsInWords(): void
    {
        $lines = [[$this->accountId('sales_revenue'), '0', '200.01']];

        for ($index = 0; $index < 20_001; $index++) {
            $lines[] = [$this->accountId('cash'), '0.01', '0'];
        }

        $this->postOn('2026-10-05', 1, $lines);
        $this->viewer();

        $response = $this->get('/accounting_reports/ledger/csv?account=' . $this->accountId('cash'));

        $response->assertStatus(422);
        $this->assertSame(lang('Accounting.error_ledger_too_large', [20_000]), $response->response()->getBody());
    }
}
