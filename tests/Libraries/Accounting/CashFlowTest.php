<?php

namespace Tests\Libraries\Accounting;

use App\Libraries\Accounting\AccountingGuard;
use App\Libraries\Accounting\CashFlow;
use App\Libraries\Accounting\CashFlowLineResult;
use App\Libraries\Accounting\CashFlowReport;
use App\Libraries\Accounting\CashFlowSettings;
use App\Libraries\Accounting\EntityAccountService;
use App\Libraries\Accounting\ReportPeriod;
use App\Libraries\Accounting\ReportScope;
use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\DatabaseTestTrait;
use Config\Database;
use Tests\Support\AccountingFixtureTrait;
use Tests\Support\ParityCaseTrait;
use Tests\Support\ReportFixtureTrait;
use Tests\Support\StatementFixtureTrait;

/**
 * The cash flow by the indirect method: each line from the change of its accounts, the parts, the
 * accounts no line takes from set out under other changes so that the total is the change of the
 * treasuries and banks whichever accounts moved, the days, the branches and the settings.
 */
final class CashFlowTest extends CIUnitTestCase
{
    use DatabaseTestTrait;
    use AccountingFixtureTrait;
    use ParityCaseTrait;
    use ReportFixtureTrait;
    use StatementFixtureTrait;

    protected $migrate     = true;
    protected $migrateOnce = true;
    protected $refresh     = false;
    protected $namespace   = null;

    private CashFlow $flow;
    private int $customer;
    private int $supplier;
    private int $stock;

    public static function setUpBeforeClass(): void
    {
        $seeder = Database::seeder('tests');
        $seeder->call('TestDatabaseBootstrapSeeder');
    }

    protected function setUp(): void
    {
        parent::setUp();

        $this->resetBranches();
        $this->reinstallChart();
        $this->setConfigValue('accounting_base_currency', 'SAR');
        $this->setConfigValue(CashFlowSettings::KEY, '');

        $this->second   = $this->createBranch('T2', 'Second branch');
        $this->flow     = new CashFlow();
        $this->customer = (new EntityAccountService())->ensure(EntityAccountService::CUSTOMER, $this->insertCustomer(), 'Omar Khaled');
        $this->supplier = (new EntityAccountService())->ensure(EntityAccountService::SUPPLIER, $this->insertSupplier(), 'Gulf Supplies');
        $this->stock    = $this->customAccount('1203', '120301', 'Stock of the warehouse');
    }

    protected function tearDown(): void
    {
        $this->setConfigValue(CashFlowSettings::KEY, '');
        $this->removeInsertedEntities();
        $this->clearJournals();
        $this->reinstallChart();
        $this->resetBranches();

        parent::tearDown();
    }

    private function flow(?string $from = '2026-10-01', string $to = '2026-10-31', ?ReportScope $scope = null): CashFlowReport
    {
        return $this->flow->build($scope ?? ReportScope::resolve(null, null), ReportPeriod::of($from, $to));
    }

    /**
     * @return array<string, string> What each line came to, by its key.
     */
    private function lines(CashFlowReport $report): array
    {
        $found = [];

        foreach ($report->lines as $lines) {
            foreach ($lines as $line) {
                $found[$line->key] = $line->amount;
            }
        }

        return $found;
    }

    /**
     * Capital, a sale on credit with its tax, a purchase of stock on credit with its tax, and what
     * the customer and the supplier then paid: every account a default line takes from moves, and so
     * do the two accounts of tax that none does.
     */
    private function books(int $branch = 1): void
    {
        $cash = $this->accountId('cash');

        $this->postOn('2026-10-01', $branch, [[$cash, '100000.00', '0'], [$this->accountIdByCode('31'), '0', '100000.00']]);
        $this->postOn('2026-10-03', $branch, [[$this->customer, '1150.00', '0'], [$this->accountId('sales_revenue'), '0', '1000.00'], [$this->accountId('tax_output'), '0', '150.00']]);
        $this->postOn('2026-10-04', $branch, [[$this->stock, '600.00', '0'], [$this->accountId('tax_input'), '90.00', '0'], [$this->supplier, '0', '690.00']]);
        $this->postOn('2026-10-10', $branch, [[$cash, '400.00', '0'], [$this->customer, '0', '400.00']]);
        $this->postOn('2026-10-12', $branch, [[$this->supplier, '200.00', '0'], [$cash, '0', '200.00']]);
        $this->postOn('2026-10-15', $branch, [[$this->accountIdByCode('545'), '75.00', '0'], [$cash, '0', '75.00']]);
    }

    public function testBuild_ComputesEachLineFromTheChangeOfItsAccountsInThePeriod(): void
    {
        $this->books();

        $report = $this->flow();

        $this->assertSame([
            'net_income'           => '925.0000',
            'depreciation_expense' => '0.0000',
            'account_receivable'   => '-750.0000',
            'account_payable'      => '490.0000',
            'inventory'            => '-600.0000',
            'assets_selling'       => '0.0000',
            'assets_purchases'     => '0.0000',
            'common_shares'        => '100000.0000',
        ], $this->lines($report), 'Net income 1,000 less rent 75; receivables rose by 750 and so are minus; stock rose by 600');
        $this->assertSame(['operating' => '65.0000', 'investing' => '0.0000', 'financing' => '100000.0000'], $report->totals);
    }

    public function testBuild_DrawsTheLinesInThePartsAndTheOrderOfTheReferenceSystem(): void
    {
        $report = $this->flow();

        $this->assertSame(['net_income', 'depreciation_expense', 'account_receivable', 'account_payable', 'inventory'], array_map(static fn (CashFlowLineResult $line): string => $line->key, $report->lines['operating']));
        $this->assertSame(['assets_selling', 'assets_purchases'], array_map(static fn (CashFlowLineResult $line): string => $line->key, $report->lines['investing']));
        $this->assertSame(['common_shares'], array_map(static fn (CashFlowLineResult $line): string => $line->key, $report->lines['financing']));
    }

    public function testBuild_EndsAtTheChangeOfTheTreasuryAndBankAccountsWhateverAccountsMoved(): void
    {
        $this->books();

        $report = $this->flow();

        $this->assertSame(['215001' => '150.0000', '215002' => '-90.0000'], array_column($report->otherItems, 'amount', 'code'), 'The tax has no line, so it is set out under other changes');
        $this->assertSame('60.0000', $report->other);
        $this->assertSame('100125.0000', $report->totalChange, 'Net income and the changes of the accounts, and the other changes');
        $this->assertSame('0.0000', $report->cashOpening);
        $this->assertSame('100125.0000', $report->cashClosing, 'The treasuries hold 100,000 and 400, less 200 and 75');
        $this->assertTrue($report->ties());
    }

    public function testBuild_AddsUpAcrossAdjacentPeriods(): void
    {
        $this->books();

        $whole  = $this->flow('2026-10-01', '2026-10-31');
        $first  = $this->flow('2026-10-01', '2026-10-09');
        $second = $this->flow('2026-10-10', '2026-10-31');

        foreach ($this->lines($whole) as $key => $amount) {
            $this->assertSame($amount, bcadd($this->lines($first)[$key], $this->lines($second)[$key], 4), "Line {$key}");
        }

        $this->assertSame($whole->other, bcadd($first->other, $second->other, 4));
        $this->assertSame($whole->totalChange, bcadd($first->totalChange, $second->totalChange, 4));
        $this->assertSame($first->cashClosing, $second->cashOpening, 'The second period opens with what the first closed with');
        $this->assertTrue($first->ties());
        $this->assertTrue($second->ties());
    }

    public function testBuild_CountsTheFirstAndTheLastDayOfTheRange(): void
    {
        $this->postOn('2026-10-04', 1, [[$this->accountId('cash'), '1.00', '0'], [$this->accountIdByCode('4201'), '0', '1.00']]);
        $this->postOn('2026-10-05', 1, [[$this->accountId('cash'), '20.00', '0'], [$this->accountIdByCode('4201'), '0', '20.00']]);
        $this->postOn('2026-10-06', 1, [[$this->accountId('cash'), '300.00', '0'], [$this->accountIdByCode('4201'), '0', '300.00']]);
        $this->postOn('2026-10-07', 1, [[$this->accountId('cash'), '4000.00', '0'], [$this->accountIdByCode('4201'), '0', '4000.00']]);

        $report = $this->flow('2026-10-05', '2026-10-06');

        $this->assertSame('320.0000', $this->lines($report)['net_income']);
        $this->assertSame('1.0000', $report->cashOpening);
        $this->assertSame('321.0000', $report->cashClosing);
    }

    public function testBuild_StartsOnTheFirstOfJanuaryOfTheYearOfTheLastDayWithoutAFirstDay(): void
    {
        $this->postOn('2025-12-31', 1, [[$this->accountId('cash'), '999.00', '0'], [$this->accountIdByCode('4201'), '0', '999.00']]);
        $this->postOn('2026-02-10', 1, [[$this->accountId('cash'), '100.00', '0'], [$this->accountIdByCode('4201'), '0', '100.00']]);

        $report = $this->flow(null, '2026-10-31');

        $this->assertSame('2026-01-01', $report->from);
        $this->assertSame('100.0000', $this->lines($report)['net_income']);
        $this->assertSame('999.0000', $report->cashOpening);
    }

    public function testBuild_HasZerosForARangeWithNothingInIt(): void
    {
        $report = $this->flow();

        $this->assertSame(array_fill_keys(array_keys($this->lines($report)), '0.0000'), $this->lines($report));
        $this->assertSame([], $report->otherItems);
        $this->assertSame(['0.0000', '0.0000', '0.0000'], [$report->totalChange, $report->cashOpening, $report->cashClosing]);
        $this->assertTrue($report->ties());
    }

    public function testBuild_ReadsOnlyTheBranchesOfTheScopeAndTiesInEveryOne(): void
    {
        $this->books();
        $this->books($this->second);
        $this->postOn('2026-10-20', $this->second, [[$this->accountId('cash'), '9.00', '0'], [$this->accountIdByCode('4201'), '0', '9.00']]);

        $first = $this->flow(scope: ReportScope::resolve([1], null));
        $other = $this->flow(scope: ReportScope::resolve([$this->second], null));
        $both  = $this->flow();
        $none  = $this->flow(scope: ReportScope::resolve(null, []));

        $this->assertSame('100125.0000', $first->totalChange);
        $this->assertSame('100134.0000', $other->totalChange);
        $this->assertSame('200259.0000', $both->totalChange);
        $this->assertSame('0.0000', $none->totalChange);

        foreach ([$first, $other, $both, $none] as $report) {
            $this->assertTrue($report->ties());
        }
    }

    public function testBuild_LeavesOutTheJournalsThatCloseAYear(): void
    {
        $this->postOn('2026-10-05', 1, [[$this->accountId('cash'), '100.00', '0'], [$this->accountIdByCode('4201'), '0', '100.00']]);
        $this->postYearEnd('2026-10-31', 1, [[$this->accountIdByCode('4201'), '100.00', '0'], [$this->accountId('retained_earnings'), '0', '100.00']]);

        $report = $this->flow();

        $this->assertSame('100.0000', $this->lines($report)['net_income'], 'The year reads the same after it is closed');
        $this->assertSame([], $report->otherItems);
        $this->assertTrue($report->ties());
    }

    public function testBuild_ReadsAReversedJournalWithItsReversalSoTheyLeaveNothing(): void
    {
        $journal = $this->postOn('2026-10-05', 1, [[$this->accountId('cash'), '100.00', '0'], [$this->accountIdByCode('4201'), '0', '100.00']]);
        $this->reverseOn($journal, '2026-10-06');

        $report = $this->flow();

        $this->assertSame('0.0000', $this->lines($report)['net_income']);
        $this->assertSame('0.0000', $report->totalChange);
        $this->assertTrue($report->ties());
    }

    public function testBuild_AddsBackTheDepreciationAndSetsOutTheTwoAccountsItMovesWithoutChangingTheTotal(): void
    {
        $expense      = $this->customAccount('53', '53001', 'Depreciation of furniture');
        $accumulation = $this->customAccount('112001', '1120011', 'Accumulated depreciation of furniture');

        $this->postOn('2026-10-05', 1, [[$expense, '120.00', '0'], [$accumulation, '0', '120.00']]);

        $report = $this->flow();
        $lines  = $this->lines($report);

        $this->assertSame(['-120.0000', '120.0000'], [$lines['net_income'], $lines['depreciation_expense']]);
        $this->assertSame(['1120011' => '120.0000', '53001' => '-120.0000'], array_column($report->otherItems, 'amount', 'code'));
        $this->assertSame('0.0000', $report->totalChange, 'The depreciation moves no money');
        $this->assertTrue($report->ties());
    }

    public function testBuild_TakesTheInvestingLinesFromTheAccountsOfTheFixedAssets(): void
    {
        $asset = $this->customAccount('111001', '1110011', 'Office desk');

        $this->postOn('2026-10-05', 1, [[$asset, '500.00', '0'], [$this->accountId('cash'), '0', '500.00']]);
        $this->postOn('2026-10-06', 1, [[$this->accountId('cash'), '200.00', '0'], [$asset, '0', '200.00']]);

        $report = $this->flow();

        $this->assertSame(['200.0000', '-500.0000'], [$this->lines($report)['assets_selling'], $this->lines($report)['assets_purchases']]);
        $this->assertSame('-300.0000', $report->totals['investing']);
        $this->assertSame('-300.0000', $report->totalChange);
        $this->assertTrue($report->ties());
    }

    public function testBuild_LeavesOutAHiddenLineAndKeepsItsAccountsInTheTotalAsOtherChanges(): void
    {
        $this->books();
        $this->hideLine('account_receivable');

        $report = $this->flow();

        $this->assertArrayNotHasKey('account_receivable', $this->lines($report));
        $this->assertSame('100125.0000', $report->totalChange, 'Hiding a line moves its accounts to the other changes and not out of the total');
        $code = (string) $this->accountingDb()->table('accounting_accounts')->where('account_id', $this->customer)->get()->getRow()->code;

        $this->assertSame('-750.0000', array_column($report->otherItems, 'amount', 'code')[$code], 'The account of the customer is among the other changes');
        $this->assertTrue($report->ties());
    }

    public function testBuild_TakesAnAddedLineFromItsAccountsByItsFormula(): void
    {
        $this->postOn('2026-10-05', 1, [[$this->accountId('cash'), '300.00', '0'], [$this->accountId('other_payables'), '0', '300.00']]);
        $this->postOn('2026-10-06', 1, [[$this->accountId('other_payables'), '100.00', '0'], [$this->accountId('cash'), '0', '100.00']]);

        $before = $this->flow();

        $this->assertSame('200.0000', $before->other, 'Without a line the account is among the other changes');

        $this->addLine('Other creditors', [$this->accountId('other_payables')], 'credits_in_period', 'financing');

        $report = $this->flow();
        $added  = array_values(array_filter($report->lines['financing'], static fn (CashFlowLineResult $line): bool => $line->custom));

        $this->assertCount(1, $added);
        $this->assertSame(['Other creditors', '300.0000'], [$added[0]->title, $added[0]->amount], 'The formula takes only what was credited');
        $this->assertSame('-100.0000', $report->other, 'The line takes more than the account changed by, and the other changes make up the rest');
        $this->assertSame('200.0000', $report->totalChange);
        $this->assertTrue($report->ties());
    }

    public function testBuild_SaysItDoesNotTieWhenAJournalWasChangedOutsideTheBooks(): void
    {
        $this->books();

        $this->assertTrue($this->flow()->ties());

        $this->accountingDb()->query('UPDATE ' . $this->accountingDb()->prefixTable('accounting_journal_lines') . ' SET debit = debit + 5 WHERE account_id = ' . $this->accountId('cash') . ' LIMIT 1');

        $this->assertFalse($this->flow()->ties(), 'A journal that does not balance leaves the lines and the money apart');
    }

    private function hideLine(string $key): void
    {
        $manager = $this->employeeWithGrants([AccountingGuard::SETTINGS_MANAGE]);

        (new CashFlowSettings())->save(['lines' => [['key' => $key, 'visible' => '0']]], $manager);
    }

    /**
     * @param list<int> $accountIds
     */
    private function addLine(string $title, array $accountIds, string $formula, string $section): void
    {
        $manager = $this->employeeWithGrants([AccountingGuard::SETTINGS_MANAGE]);

        (new CashFlowSettings())->save(['lines' => [['key' => '', 'title' => $title, 'accounts' => $accountIds, 'formula' => $formula, 'section' => $section]]], $manager);
    }

    /**
     * Posts the changes of the accounts a cash flow reads as one journal, with the treasury taking
     * what is left: each amount is what the accounts of its kind were credited less what they were
     * debited.
     *
     * @param array<string, string> $netCredit
     */
    private function postNetCredits(array $netCredit, string $date, int $branch = 1): void
    {
        $accounts = [
            'receivables' => $this->customer,
            'payables'    => $this->supplier,
            'inventory'   => $this->stock,
            'shares'      => $this->accountIdByCode('31'),
            'vat'         => $this->accountId('tax_output'),
        ];
        $lines = [];
        $total = '0';

        foreach ($netCredit as $kind => $amount) {
            $account = $kind === 'net_income' ? $this->accountIdByCode(bccomp($amount, '0', 2) >= 0 ? '4201' : '545') : $accounts[$kind];

            $lines[] = [$account, bccomp($amount, '0', 2) < 0 ? bcsub('0', $amount, 2) : '0', bccomp($amount, '0', 2) >= 0 ? $amount : '0'];
            $total   = bcadd($total, $amount, 2);
        }

        if (bccomp($total, '0', 2) !== 0) {
            $lines[] = [$this->accountId('cash'), bccomp($total, '0', 2) > 0 ? $total : '0', bccomp($total, '0', 2) < 0 ? bcsub('0', $total, 2) : '0'];
        }

        $this->postOn($date, $branch, $lines);
    }

    public function testBuild_ReproducesTheLinesTheReferenceSystemShowedFromTheChangesOfTheirAccounts(): void
    {
        $case = $this->referenceCase('T-67', 'cash_flow', 'each-line-is-the-change-of-its-accounts', 'exact');

        $this->postNetCredits($case['inputs']['net_credit'], '2026-10-15');

        $report = $this->flow('2025-10-27', '2026-10-27');

        $this->assertSameAsFixture($case['expected'], ['lines' => $this->lines($report), 'operating_total' => $report->totals['operating']], 'T-67 each line is the change of its accounts');
    }

    public function testBuild_EndsAtTheChangeOfTheTreasuriesWhereTheReferenceSystemEndsApartFromThem(): void
    {
        $case = $this->referenceCase('T-67', 'cash_flow', 'the-total-change-is-the-change-of-the-treasuries', 'deliberate_difference');

        $this->postNetCredits($this->referenceCase('T-67', 'cash_flow', 'each-line-is-the-change-of-its-accounts', 'exact')['inputs']['net_credit'], '2026-10-15');

        $report = $this->flow('2025-10-27', '2026-10-27');

        $this->assertSameAsFixture($case['expected'], ['total_change' => $report->totalChange, 'treasuries_and_banks' => $report->cashClosing, 'other_changes' => $report->other], 'T-67 the total change is the change of the treasuries');
        $this->assertTrue($report->ties());
    }

    public function testBuild_ReproducesTheLinesTheReferenceSystemShowedForTwoAdjacentPeriodsAndTheirSum(): void
    {
        $case = $this->referenceCase('T-67', 'cash_flow', 'the-lines-add-up-across-adjacent-periods', 'exact');

        foreach ($case['inputs']['periods'] as $period) {
            $this->postNetCredits($period['net_credit'], $period['to']);
        }

        [$firstPeriod, $secondPeriod] = $case['inputs']['periods'];

        $first  = $this->flow($firstPeriod['from'], $firstPeriod['to']);
        $second = $this->flow($secondPeriod['from'], $secondPeriod['to']);
        $whole  = $this->flow($firstPeriod['from'], $secondPeriod['to']);
        $keep   = static fn (array $lines, array $keys): array => array_intersect_key($lines, array_flip($keys));

        $this->assertSameAsFixture($case['expected'], [
            'first'  => $keep($this->lines($first), ['net_income', 'account_receivable', 'account_payable', 'inventory']) + ['operating_total' => $first->totals['operating']],
            'second' => $keep($this->lines($second), ['net_income', 'account_receivable', 'account_payable', 'inventory', 'common_shares']),
            'whole'  => $keep($this->lines($whole), ['net_income', 'account_receivable', 'account_payable', 'inventory']),
        ], 'T-67 the lines add up across adjacent periods');

        foreach ($this->lines($whole) as $key => $amount) {
            $this->assertSame($amount, bcadd($this->lines($first)[$key], $this->lines($second)[$key], 4), "Line {$key}");
        }
    }
}
