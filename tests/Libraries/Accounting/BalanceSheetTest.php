<?php

namespace Tests\Libraries\Accounting;

use App\Libraries\Accounting\AccountingError;
use App\Libraries\Accounting\AccountingException;
use App\Libraries\Accounting\BalanceSheet;
use App\Libraries\Accounting\BalanceSheetReport;
use App\Libraries\Accounting\CustomerLedger;
use App\Libraries\Accounting\EntityAccountService;
use App\Libraries\Accounting\IncomeStatement;
use App\Libraries\Accounting\JournalService;
use App\Libraries\Accounting\ReportPeriod;
use App\Libraries\Accounting\ReportScope;
use App\Libraries\Accounting\StatementDisplay;
use App\Libraries\Accounting\StatementLine;
use App\Libraries\Accounting\StatementSection;
use App\Libraries\Accounting\StockLedger;
use App\Libraries\Accounting\TreasuryService;
use App\Libraries\Accounting\TrialBalance;
use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\DatabaseTestTrait;
use Config\Database;
use Tests\Support\AccountingFixtureTrait;
use Tests\Support\EmployeeFixtureTrait;
use Tests\Support\ItemFixtureTrait;
use Tests\Support\PurchaseAccountingFixtureTrait;
use Tests\Support\ReportFixtureTrait;
use Tests\Support\SaleAccountingFixtureTrait;
use Tests\Support\StatementFixtureTrait;

/**
 * Where the assets, the liabilities and the equity stand on a day, read from the journals that are
 * in the books: the equation with the result of the years that were not closed, the signs by
 * section, the levels, the branches, and the ties to the trial balance and to the ledgers of
 * customers, suppliers, stock and treasuries.
 */
class BalanceSheetTest extends CIUnitTestCase
{
    use DatabaseTestTrait;
    use AccountingFixtureTrait;
    use EmployeeFixtureTrait;
    use ItemFixtureTrait;
    use ReportFixtureTrait;
    use SaleAccountingFixtureTrait;
    use PurchaseAccountingFixtureTrait;
    use StatementFixtureTrait;

    protected $migrate     = true;
    protected $migrateOnce = true;
    protected $refresh     = false;
    protected $namespace   = null;

    private BalanceSheet $sheets;

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

        $this->second = $this->createBranch('T2', 'Second branch');
        $this->sheets = new BalanceSheet();
    }

    protected function tearDown(): void
    {
        $this->stopBuying();
        $this->clearJournals();
        $this->reinstallChart();
        $this->resetBranches();

        parent::tearDown();
    }

    private function sheet(string $asOf = '2026-12-31', ?ReportScope $scope = null, ?int $level = null, StatementDisplay $display = StatementDisplay::All, bool $expand = false): BalanceSheetReport
    {
        return $this->sheets->build($scope ?? ReportScope::resolve(null, null), $asOf, $level, $display, $expand);
    }

    private function lineOf(StatementSection $section, string $code): ?StatementLine
    {
        foreach ($section->lines as $line) {
            if ($line->code === $code) {
                return $line;
            }
        }

        return null;
    }

    private function amountOf(StatementSection $section, string $code): ?string
    {
        return $this->lineOf($section, $code)?->amount;
    }

    private function amountOfAccount(StatementSection $section, int $accountId): ?string
    {
        foreach ($section->lines as $line) {
            if ($line->accountId === $accountId) {
                return $line->amount;
            }
        }

        return null;
    }

    private function assertEquation(BalanceSheetReport $sheet, string $message = ''): void
    {
        $this->assertSame($sheet->assets->total, bcadd($sheet->liabilities->total, bcadd($sheet->equity->total, $sheet->unclosedResult, 4), 4), 'Assets = liabilities + equity. ' . $message);
        $this->assertTrue($sheet->balances(), $message);
    }

    private function everything(?ReportScope $scope = null): BalanceSheetReport
    {
        return $this->sheet('2099-12-31', $scope, 5, expand: true);
    }

    public function testBuild_GivesAssetsEqualToLiabilitiesPlusEquityWithTheResultOfUnclosedYears(): void
    {
        $this->observedBooks(1, $this->second);

        $sheet = $this->sheet();

        $this->assertSame('96991.8400', $sheet->assets->total);
        $this->assertSame('268.3000', $sheet->liabilities->total);
        $this->assertSame('100000.0000', $sheet->equity->total, 'The capital');
        $this->assertSame('-3276.4600', $sheet->unclosedResult);
        $this->assertSame('96723.5400', $sheet->equityTotal());
        $this->assertSame('96991.8400', $sheet->liabilitiesAndEquity());
        $this->assertEquation($sheet);
    }

    public function testBuild_BalancesForOneBranchSeveralBranchesAndTheCompany(): void
    {
        $this->observedBooks(1, $this->second);

        $third = $this->createBranch('T3', 'Third branch');

        $this->postOn('2026-10-11', $third, [[$this->accountId('cash'), '70.00', '0'], [$this->accountId('sales_revenue'), '0', '70.00']]);

        $totals = [];

        foreach ([[1], [$this->second], [$third], [1, $this->second], [1, $third], [$this->second, $third], [1, $this->second, $third], null] as $branches) {
            $sheet = $this->sheet(scope: ReportScope::resolve($branches, null));

            $this->assertEquation($sheet, 'Branches ' . json_encode($branches));

            $totals[json_encode($branches)] = $sheet->assets->total;
        }

        $this->assertSame('108112.0100', $totals['[1]'], 'The first branch: cash and stock 108,112.01 against capital 100,000 and a result of 8,112.01');
        $this->assertSame('-11120.1700', $totals['[' . $this->second . ']'], 'The second branch: the stock it used, what it spent and bought on credit, against what it owes');
        $this->assertSame('70.0000', $totals['[' . $third . ']']);
        $this->assertSame(bcadd(bcadd($totals['[1]'], $totals['[' . $this->second . ']'], 4), $totals['[' . $third . ']'], 4), $totals['null'], 'The company is what its branches are');
    }

    public function testBuild_HasNothingToShowForNoBranch(): void
    {
        $this->observedBooks(1, $this->second);

        $sheet = $this->sheet(scope: ReportScope::resolve(null, []));

        $this->assertSame('0.0000', $sheet->assets->total);
        $this->assertSame('0.0000', $sheet->unclosedResult);
        $this->assertTrue($sheet->scope->isEmpty());
        $this->assertTrue($sheet->balances());
    }

    public function testBuild_ShowsADebitBalanceInsideTheLiabilitiesAsNegative(): void
    {
        $entities = new EntityAccountService();
        $owed     = $entities->ensure(EntityAccountService::SUPPLIER, $this->insertSupplier('Al Amal Co'), 'Al Amal Co');
        $debited  = $entities->ensure(EntityAccountService::SUPPLIER, $this->insertSupplier('Najd Co'), 'Najd Co');

        $this->postOn('2026-10-05', 1, [[$this->accountId('purchases'), '355.50', '0'], [$owed, '0', '355.50']]);
        $this->postOn('2026-10-06', 1, [[$debited, '50.00', '0'], [$this->accountId('cash'), '0', '50.00']]);
        $this->postOn('2026-10-07', 1, [[$this->accountId('tax_input'), '37.20', '0'], [$this->accountId('cash'), '0', '37.20']]);
        $this->postOn('2026-10-08', 1, [[$this->accountId('cash'), '1216.80', '0'], [$this->accountId('tax_output'), '0', '1216.80']]);

        $sheet = $this->sheet(level: 5, expand: true);

        $this->assertSame('355.5000', $this->amountOfAccount($sheet->liabilities, $owed));
        $this->assertSame('-50.0000', $this->amountOfAccount($sheet->liabilities, $debited), 'A debit balance inside the liabilities is a minus, not moved to the assets');
        $this->assertSame('305.5000', $this->amountOf($sheet->liabilities, '2211'), 'The payables are reduced by it');
        $this->assertSame('-37.2000', $this->amountOf($sheet->liabilities, '215002'));
        $this->assertSame('1216.8000', $this->amountOf($sheet->liabilities, '215001'));
        $this->assertSame('1179.6000', $this->amountOf($sheet->liabilities, '215'));
        $this->assertSame('1485.1000', $sheet->liabilities->total);
        $this->assertEquation($sheet);
    }

    public function testBuild_ShowsAnAssetThatWasCreditedAsANegative(): void
    {
        $this->postOn('2026-10-05', 1, [[$this->accountId('cash'), '100.00', '0'], [$this->accountId('other_payables'), '0', '100.00']]);
        $this->postOn('2026-10-06', 1, [[$this->accountId('cogs'), '60.00', '0'], [$this->accountId('purchases'), '0', '60.00']]);

        $sheet = $this->sheet(level: 4);

        $this->assertSame('-60.0000', $this->amountOf($sheet->assets, '1210'), 'A credit balance inside the assets is a minus');
        $this->assertSame('40.0000', $this->amountOf($sheet->assets, '12'));
        $this->assertSame('40.0000', $sheet->assets->total);
        $this->assertEquation($sheet);
    }

    public function testBuild_ShowsAccountThirtyTwoBesideTheResultOfUnclosedYears(): void
    {
        $this->cashSale('2026-10-10', 1, '320.00');
        $this->postOn('2026-10-11', 1, [[$this->accountId('cash'), '126.00', '0'], [$this->accountId('other_payables'), '0', '126.00']]);

        $open = $this->sheet('2026-10-21', level: 2);

        $this->assertSame('446.0000', $open->assets->total);
        $this->assertSame('126.0000', $open->liabilities->total);
        $this->assertSame('320.0000', $open->unclosedResult);
        $this->assertSame('0.0000', $this->amountOf($open->equity, '32'), 'Account 32 stands at nothing until a year is closed');
        $this->assertSame('320.0000', $open->equityTotal());
        $this->assertSame('446.0000', $open->liabilitiesAndEquity());
        $this->assertEquation($open);
    }

    public function testBuild_MovesTheResultToAccountThirtyTwoOnceTheYearIsClosedAndKeepsTheTotal(): void
    {
        $this->cashSale('2026-10-10', 1, '320.00');
        $this->postOn('2026-10-11', 1, [[$this->accountId('cash'), '126.00', '0'], [$this->accountId('other_payables'), '0', '126.00']]);

        $before = $this->sheet('2026-12-31', level: 2);

        $this->postYearEnd('2026-12-31', 1, [[$this->accountId('sales_revenue'), '320.00', '0'], [$this->accountId('retained_earnings'), '0', '320.00']]);

        $after = $this->sheet('2026-12-31', level: 2);

        $this->assertSame('320.0000', $before->unclosedResult);
        $this->assertSame('0.0000', $this->amountOf($before->equity, '32'));
        $this->assertSame('0.0000', $after->unclosedResult, 'Nothing is left unclosed');
        $this->assertSame('320.0000', $this->amountOf($after->equity, '32'), 'It moved to account 32');
        $this->assertSame($before->equityTotal(), $after->equityTotal());
        $this->assertSame($before->assets->total, $after->assets->total);
        $this->assertEquation($after);
        $this->assertSame('320.0000', $this->sheet('2026-12-30', level: 2)->unclosedResult, 'Before the day of the closing the result is still unclosed');
    }

    public function testBuild_ReturnsTheResultToTheUnclosedLineWhenTheClosingIsReversedAndKeepsTheTotal(): void
    {
        $this->cashSale('2026-10-10', 1, '320.00');
        $this->postOn('2026-10-11', 1, [[$this->accountId('cash'), '126.00', '0'], [$this->accountId('other_payables'), '0', '126.00']]);

        $before  = $this->sheet('2027-12-31', level: 2);
        $closing = $this->postYearEnd('2026-12-31', 1, [[$this->accountId('sales_revenue'), '320.00', '0'], [$this->accountId('retained_earnings'), '0', '320.00']]);

        $closed = $this->sheet('2027-12-31', level: 2);

        (new JournalService())->reverse($closing, '2027-01-05', 'Reopen the year');

        $after = $this->sheet('2027-12-31', level: 2);

        $this->assertSame('320.0000', $this->amountOf($closed->equity, '32'));
        $this->assertSame('320.0000', $after->unclosedResult, 'The year is open again');
        $this->assertSame('0.0000', $this->amountOf($after->equity, '32'));
        $this->assertEquals($before, $after);
        $this->assertEquation($after);
        $this->assertSame('320.0000', $this->amountOf($this->sheet('2026-12-31', level: 2)->equity, '32'), 'Before the day of the reversal the year is still closed');
    }

    public function testBuild_KeepsTheTotalsWhenTheReversalOfAClosingIsReversedToo(): void
    {
        $this->cashSale('2026-10-10', 1, '320.00');

        $closing  = $this->postYearEnd('2026-12-31', 1, [[$this->accountId('sales_revenue'), '320.00', '0'], [$this->accountId('retained_earnings'), '0', '320.00']]);
        $closed   = $this->sheet('2027-12-31', level: 2);
        $reversal = (new JournalService())->reverse($closing, '2027-01-05', 'Reopen the year');

        $this->reverseAReversal($reversal, '2027-01-06');

        $after = $this->sheet('2027-12-31', level: 2);

        $this->assertEquals($closed, $after, 'Closed, reopened and closed again');
        $this->assertSame('320.0000', $this->amountOf($after->equity, '32'));
        $this->assertEquation($after);
    }

    public function testBuild_AddsTheResultOfTheYearAfterTheOneThatWasClosedToTheUnclosedLine(): void
    {
        $this->cashSale('2026-10-10', 1, '320.00');
        $this->postYearEnd('2026-12-31', 1, [[$this->accountId('sales_revenue'), '320.00', '0'], [$this->accountId('retained_earnings'), '0', '320.00']]);
        $this->cashSale('2027-02-10', 1, '45.00');
        $this->postOn('2027-02-11', 1, [[$this->accountIdByCode('545'), '5.00', '0'], [$this->accountId('cash'), '0', '5.00']]);

        $sheet = $this->sheet('2027-03-31', level: 2);

        $this->assertSame('40.0000', $sheet->unclosedResult, 'The sales and the rent of the new year');
        $this->assertSame('320.0000', $this->amountOf($sheet->equity, '32'));
        $this->assertSame('360.0000', $sheet->equityTotal());
        $this->assertSame('360.0000', $sheet->assets->total);
        $this->assertEquation($sheet);
    }

    public function testBuild_TreatsAJournalOfTypeYearEndLikeOneOfSourceYearEnd(): void
    {
        $this->cashSale('2026-10-10', 1, '320.00');

        $closing = $this->postOn('2026-12-31', 1, [[$this->accountId('sales_revenue'), '320.00', '0'], [$this->accountId('retained_earnings'), '0', '320.00']]);

        $this->accountingDb()->table('accounting_journals')->where('journal_id', $closing)->update(['entry_type' => 'year_end']);

        $sheet = $this->sheet('2026-12-31', level: 2);

        $this->assertSame('0.0000', $sheet->unclosedResult);
        $this->assertSame('320.0000', $this->amountOf($sheet->equity, '32'));
        $this->assertEquation($sheet);
    }

    public function testBuild_IsThePositionOnTheLastDayAndHasNoFirstDay(): void
    {
        $this->cashSale('2026-09-30', 1, '1.00');
        $this->cashSale('2026-10-01', 1, '2.00');
        $this->cashSale('2026-10-31', 1, '4.00');
        $this->cashSale('2026-11-01', 1, '8.00');

        $sheet = $this->sheet('2026-10-31');

        $this->assertSame('2026-10-31', $sheet->asOf);
        $this->assertSame('7.0000', $sheet->assets->total, 'Everything from the start of the books to the last day, the last day included');
        $this->assertSame('7.0000', $sheet->unclosedResult);
        $this->assertSame('1.0000', $this->sheet('2026-09-30')->assets->total);
        $this->assertEquation($sheet);
    }

    public function testBuild_ReturnsToItsPriorFiguresWhenAJournalIsReversed(): void
    {
        $this->observedBooks(1, $this->second);

        $before  = $this->sheet();
        $journal = $this->cashSale('2026-10-12', 1, '700.00');

        $this->assertSame('97691.8400', $this->sheet()->assets->total);

        $this->reverseOn($journal, '2026-10-13');

        $after = $this->sheet();

        $this->assertEquals($before, $after);
        $this->assertEquation($after);
    }

    public function testBuild_ReturnsToItsPriorFiguresWhenASaleIsCancelled(): void
    {
        $this->startBooks();
        $this->workInBranches(1);
        $this->invoiceOf('100.00', $this->customerId, [$this->payment(lang('Sales.cash'), '30.00')]);

        $before = $this->sheet(level: 4);
        $saleId = $this->save([$this->line($this->item('0', false), 0, '1', '55.00')], [], [$this->payment(lang('Sales.cash'), '55.00')], SALE_TYPE_POS);

        $this->assertSame('155.0000', $this->sheet(level: 4)->assets->total);
        $this->assertTrue($this->sales->delete($saleId, false, true, $this->employeeId));

        $after = $this->sheet(level: 4);

        $this->assertEquals($before, $after);
        $this->assertEquation($after);
    }

    public function testBuild_LeavesOutDraftsAndPreviews(): void
    {
        $this->observedBooks(1, $this->second);

        $before  = $this->sheet();
        $journal = $this->cashSale('2026-10-12', 1, '700.00');

        $this->accountingDb()->table('accounting_journals')->where('journal_id', $journal)->update(['status' => 'preview']);

        $this->assertEquals($before, $this->sheet(), 'A preview adds nothing');

        $draft = (new JournalService())->saveDraft($this->journalInput('500.00'), null);

        $this->assertGreaterThan(0, $this->accountingDb()->table('accounting_journal_lines')->where('journal_id', $draft)->countAllResults(), 'The draft has lines that would be counted if drafts were');
        $this->assertEquals($before, $this->sheet(), 'A draft adds nothing');
    }

    public function testBuild_TiesToTheRootsOfTheTrialBalanceForEveryChoiceOfBranches(): void
    {
        $this->mixedBooks();

        $trial = new TrialBalance();

        foreach ([null, [1], [$this->second], [1, $this->second]] as $branches) {
            $scope = ReportScope::resolve($branches, null);
            $roots = [];

            foreach ($trial->build($scope, ReportPeriod::of(null, '2099-12-31'))->rows as $row) {
                $roots[$row->code] = $row->closing();
            }

            $sheet = $this->sheet('2099-12-31', $scope);

            $this->assertSame(bcadd($roots['1'] ?? '0', '0', 4), $sheet->assets->total, 'Assets, branches ' . json_encode($branches));
            $this->assertSame(bcsub('0', $roots['2'] ?? '0', 4), $sheet->liabilities->total, 'Liabilities, branches ' . json_encode($branches));
            $this->assertSame(bcsub('0', $roots['3'] ?? '0', 4), $sheet->equity->total, 'Equity accounts, branches ' . json_encode($branches));
            $this->assertSame(bcsub(bcsub('0', $roots['4'] ?? '0', 4), $roots['5'] ?? '0', 4), $sheet->unclosedResult, 'The result, branches ' . json_encode($branches));
            $this->assertEquation($sheet, 'Branches ' . json_encode($branches));
        }
    }

    public function testBuild_TiesTheResultOfUnclosedYearsToTheIncomeStatementFromTheStartOfTheBooks(): void
    {
        $this->mixedBooks();

        foreach ([null, [1], [$this->second]] as $branches) {
            $scope     = ReportScope::resolve($branches, null);
            $statement = (new IncomeStatement())->build($scope, ReportPeriod::of(null, '2099-12-31'));

            $this->assertSame($statement->netIncome, $this->sheet('2099-12-31', $scope)->unclosedResult, 'Branches ' . json_encode($branches));
        }
    }

    public function testBuild_HoldsInTheReceivablesWhatEveryCustomerOwesAcrossTheBranches(): void
    {
        $this->startBooks();

        $other = $this->anotherCustomer();

        $this->workInBranches(1);
        $first = $this->invoiceOf('100.00', $this->customerId);
        $this->invoiceOf('40.00', $other);
        $this->creditNoteOf($first, '10.00', $this->customerId);

        $this->workInBranches($this->second);
        $this->invoiceOf('70.00', $this->customerId, [$this->payment(lang('Sales.cash'), '20.00')]);

        $sheet = $this->everything();
        $sum   = '0.0000';

        foreach ([$this->customerId, $other] as $customerId) {
            $amount = $this->amountOfAccount($sheet->assets, $this->customerAccount($customerId));

            $this->assertNotNull($amount);
            $this->assertSame(bcadd((new CustomerLedger())->position($customerId)->accountBalance, '0', 4), $amount, 'The balance sheet and the ledger of customers differ');

            $sum = bcadd($sum, $amount, 4);
        }

        $this->assertSame('180.0000', $sum);
        $this->assertSame($sum, $this->amountOf($sheet->assets, '12041'));
    }

    public function testBuild_HoldsInThePayablesWhatIsOwedToEverySupplierAcrossTheBranches(): void
    {
        $this->startBooks();

        $other = $this->anotherSupplier();
        $depot = $this->locationOfBranch($this->second, 'Second depot');
        $item  = $this->item('0.00');

        $first = $this->purchaseOf('300.00');
        $this->debitNoteOf($first, '60.00');
        $this->payPurchase($first, '100.00');

        $this->workInBranches($this->second);
        $this->assertGreaterThan(0, $this->buy([$this->purchaseLine($item, 0, '2', '100.00', '0', '0', PERCENT, $depot)], $other));

        $sheet = $this->everything();
        $sum   = '0.0000';

        foreach ([$this->supplierId, $other] as $supplierId) {
            $amount = $this->amountOfAccount($sheet->liabilities, $this->supplierAccount($supplierId));

            $this->assertNotNull($amount);
            $this->assertSame(bcadd($this->supplierPosition($supplierId)->accountBalance, '0', 4), $amount, 'The balance sheet and the ledger of suppliers differ');

            $sum = bcadd($sum, $amount, 4);
        }

        $this->assertSame('340.0000', $sum, '300 less 60 and 100 for the first supplier, and 200 for the other');
        $this->assertSame($sum, $this->amountOf($sheet->liabilities, '2211'));
    }

    public function testBuild_HoldsInTheStockAccountsWhatTheMovementsOfEveryLocationAreWorth(): void
    {
        $this->startBooks();

        $item  = $this->item('0.00');
        $depot = $this->locationOfBranch($this->second, 'Second depot');

        $this->assertGreaterThan(0, $this->buy([$this->purchaseLine($item, 0, '10', '50')]));
        $this->workInEveryBranch();
        $this->assertGreaterThan(0, $this->buy([$this->purchaseLine($item, 0, '4', '0', '0', '0', PERCENT, $depot), $this->purchaseLine($item, 1, '-4', '0')], null, null));
        $this->assertGreaterThan(0, $this->save([$this->line($item, 0, '2', '80', '50')]));
        $this->assertStockTies();

        $sheet = $this->everything();
        $sum   = '0.0000';

        foreach ((new StockLedger())->reconcile() as $location) {
            $amount = $this->amountOfAccount($sheet->assets, $this->stockAccountOf((int) $location['location_id']));

            if ($amount === null) {
                $this->assertSame('0.00', bcadd($location['movements_value'], '0', 2));

                continue;
            }

            $this->assertSame(bcadd($location['movements_value'], '0', 4), $amount, 'Location ' . $location['location_id'] . ': the balance sheet and the stock movements differ');

            $sum = bcadd($sum, $amount, 4);
        }

        $this->assertSame('400.0000', $sum, '500 bought, 200 moved out of one location and into the other, 100 sold at cost');
        $this->assertSame($sum, $this->amountOf($sheet->assets, '1203'));
    }

    public function testBuild_HoldsInTheTreasuryAccountsWhatEveryTreasuryHolds(): void
    {
        $books      = $this->mixedBooks();
        $treasuries = new TreasuryService();
        $main       = $treasuries->ensureMain();
        $sheet      = $this->everything();

        foreach ([$main, $books['treasury']] as $treasuryId) {
            $amount = $this->amountOfAccount($sheet->assets, (int) $treasuries->accountOf($treasuryId));

            $this->assertNotNull($amount);
            $this->assertSame(bcadd($treasuries->position($treasuryId)->balance, '0', 4), $amount, 'The balance sheet and the position of the treasury differ');
        }

        $this->assertSame('150.0000', $this->amountOfAccount($sheet->assets, (int) $treasuries->accountOf($books['treasury'])));
    }

    public function testBuild_LeavesTheTwoAccountsBetweenTheBranchesOffsettingAcrossTheCompany(): void
    {
        $this->mixedBooks();

        $sheet = $this->sheet('2099-12-31', level: 4);

        $this->assertSame('350.0000', $this->amountOf($sheet->assets, '12044'), '150 of money and 200 of stock sent out of the first branch');
        $this->assertSame('350.0000', $this->amountOf($sheet->liabilities, '2214'));
        $this->assertSame('0.0000', $sheet->interBranchDifference);
    }

    public function testBuild_SaysWhatIsLeftBetweenTheBranchesForTheWholeCompanyOnly(): void
    {
        $this->postOn('2026-10-05', 1, [[$this->accountId('branches_receivable'), '50.00', '0'], [$this->accountId('cash'), '0', '50.00']]);

        $this->assertSame('50.0000', $this->sheet()->interBranchDifference);
        $this->assertNull($this->sheet(scope: ReportScope::resolve([1], null))->interBranchDifference);
        $this->assertNull($this->sheet(scope: ReportScope::resolve(null, [1]))->interBranchDifference);
    }

    public function testBuild_SaysWhatIsLeftBetweenTheBranchesAsOfTheLastDay(): void
    {
        $this->postOn('2026-11-15', 1, [[$this->accountId('branches_receivable'), '50.00', '0'], [$this->accountId('cash'), '0', '50.00']]);

        $this->assertSame('0.0000', $this->sheet('2026-10-31')->interBranchDifference);
        $this->assertSame('50.0000', $this->sheet('2026-11-15')->interBranchDifference);
    }

    public function testBuild_ListsTheChartDownToTheDeepestLevelOfTheChartOnly(): void
    {
        $this->cashSale('2026-10-05', 1, '10.00');

        $sheet = $this->sheet(level: 9);

        $this->assertSame(4, $sheet->deepestLevel, 'The default chart is four accounts deep before there is an account of a customer');
        $this->assertSame(4, $sheet->level);
        $this->assertSame(4, $this->lineOf($sheet->assets, '111001')?->level, 'The fixed assets of the default chart are at level four');
        $this->assertSame(1, $this->lineOf($sheet->assets, '1')?->level);
        $this->assertSame(1, $this->sheet(level: 1)->level);
    }

    public function testBuild_OffersAFifthLevelOnceTheChartHasAccountsOfCustomersAndNeverASixth(): void
    {
        (new EntityAccountService())->ensure(EntityAccountService::CUSTOMER, $this->insertCustomer(), 'Omar Khaled');

        $deep   = $this->customAccount('111001', '1110011', 'Deeper', true);
        $deeper = $this->customAccount('1110011', '11100111', 'Deepest');

        $this->postOn('2026-10-05', 1, [[$deeper, '30.00', '0'], [$this->accountId('other_payables'), '0', '30.00']]);

        $sheet = $this->sheet(level: 9);

        $this->assertSame(5, $sheet->deepestLevel, 'The chart is cut off at the fifth level');
        $this->assertSame(5, $sheet->level);
        $this->assertSame('30.0000', $this->amountOfAccount($sheet->assets, $deep), 'An account below the cut stays in the account above it');
        $this->assertNull($this->amountOfAccount($sheet->assets, $deeper));
        $this->assertSame('30.0000', $sheet->assets->total);
    }

    public function testBuild_ListsOnlyTheAccountsOfTheTopOfTheChartAtLevelOne(): void
    {
        $this->observedBooks(1, $this->second);

        $sheet = $this->sheet(level: 1);

        $this->assertSame(['1'], array_map(static fn (StatementLine $line): string => $line->code, $sheet->assets->lines));
        $this->assertSame(['2'], array_map(static fn (StatementLine $line): string => $line->code, $sheet->liabilities->lines));
        $this->assertSame(['3'], array_map(static fn (StatementLine $line): string => $line->code, $sheet->equity->lines));
        $this->assertSame('96991.8400', $sheet->assets->lines[0]->amount);
        $this->assertSame('268.3000', $sheet->liabilities->lines[0]->amount);
        $this->assertSame('100000.0000', $sheet->equity->lines[0]->amount);
        $this->assertSame('-3276.4600', $sheet->unclosedResult);
    }

    public function testBuild_FoldsTheAccountsOfPartiesUnlessAskedForAndAddsUpTheSame(): void
    {
        $customer = (new EntityAccountService())->ensure(EntityAccountService::CUSTOMER, $this->insertCustomer(), 'Omar Khaled');

        $this->postOn('2026-10-05', 1, [[$customer, '250.00', '0'], [$this->accountId('sales_revenue'), '0', '250.00']]);

        $folded   = $this->sheet(level: 5);
        $expanded = $this->sheet(level: 5, expand: true);

        $this->assertNull($this->amountOfAccount($folded->assets, $customer));
        $this->assertSame('250.0000', $this->amountOf($folded->assets, '12041'));
        $this->assertSame('250.0000', $this->amountOfAccount($expanded->assets, $customer));
        $this->assertSame($folded->assets->total, $expanded->assets->total);
        $this->assertSame($folded->unclosedResult, $expanded->unclosedResult);
    }

    public function testBuild_HidesAccountsThatStandAtNothingWithoutChangingTheTotals(): void
    {
        $this->observedBooks(1, $this->second);

        $all    = $this->sheet(level: 4);
        $hidden = $this->sheet(level: 4, display: StatementDisplay::HideZero);

        $this->assertNotNull($this->lineOf($all->equity, '32'));
        $this->assertNull($this->lineOf($hidden->equity, '32'), 'Account 32 stands at nothing');
        $this->assertNotNull($this->lineOf($hidden->equity, '31'));
        $this->assertLessThan(count($all->assets->lines), count($hidden->assets->lines));
        $this->assertSame($all->assets->total, $hidden->assets->total);
        $this->assertSame($all->liabilities->total, $hidden->liabilities->total);
        $this->assertSame($all->unclosedResult, $hidden->unclosedResult);
        $this->assertEquation($hidden);
    }

    public function testBuild_RejectsADayThatIsNotADate(): void
    {
        try {
            $this->sheet('2026-13-01');
            $this->fail('A day that is not a date was taken');
        } catch (AccountingException $exception) {
            $this->assertSame(AccountingError::InvalidDate, $exception->error);
        }
    }

    public function testBuild_SaysItDoesNotBalanceWhenAJournalWasChangedOutsideTheBooks(): void
    {
        $this->observedBooks(1, $this->second);

        $this->assertTrue($this->sheet()->balances());

        $this->accountingDb()->query('UPDATE ' . $this->accountingDb()->prefixTable('accounting_journal_lines') . ' SET debit = debit + 5 LIMIT 1');

        $this->assertFalse($this->sheet()->balances());
    }
}
