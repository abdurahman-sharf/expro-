<?php

namespace Tests\Controllers;

use App\Libraries\Accounting\EntityAccountService;
use CodeIgniter\Exceptions\PageNotFoundException;
use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\DatabaseTestTrait;
use CodeIgniter\Test\TestResponse;
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
}
