<?php

namespace Tests\Libraries\Accounting;

use App\Libraries\Accounting\AccountingGuard;
use App\Libraries\Accounting\CustomerLedger;
use App\Libraries\Accounting\EntityAccountService;
use App\Libraries\Accounting\JournalDraft;
use App\Libraries\Accounting\JournalLineDraft;
use App\Libraries\Accounting\JournalService;
use App\Libraries\Accounting\PostingService;
use App\Libraries\Accounting\ReportPeriod;
use App\Libraries\Accounting\ReportScope;
use App\Libraries\Accounting\StockLedger;
use App\Libraries\Accounting\TreasuryService;
use App\Libraries\Accounting\TreasuryTransfers;
use App\Libraries\Accounting\TrialBalance;
use App\Libraries\Accounting\TrialBalanceReport;
use App\Libraries\Accounting\TrialBalanceRow;
use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\DatabaseTestTrait;
use Config\Database;
use Tests\Support\AccountingFixtureTrait;
use Tests\Support\EmployeeFixtureTrait;
use Tests\Support\ItemFixtureTrait;
use Tests\Support\PurchaseAccountingFixtureTrait;
use Tests\Support\ReportFixtureTrait;
use Tests\Support\SaleAccountingFixtureTrait;

/**
 * What the accounts of the chart hold, read from the journals that are in the books: the shape of
 * the rows, the period, the branches, the accounts of customers and the checks the report makes.
 */
class TrialBalanceTest extends CIUnitTestCase
{
    use DatabaseTestTrait;
    use AccountingFixtureTrait;
    use EmployeeFixtureTrait;
    use ItemFixtureTrait;
    use ReportFixtureTrait;
    use SaleAccountingFixtureTrait;
    use PurchaseAccountingFixtureTrait;

    protected $migrate     = true;
    protected $migrateOnce = true;
    protected $refresh     = false;
    protected $namespace   = null;

    private TrialBalance $report;
    private int $second;

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
        $this->report = new TrialBalance();
    }

    protected function tearDown(): void
    {
        $this->stopBuying();
        $this->clearJournals();
        $this->reinstallChart();
        $this->resetBranches();

        parent::tearDown();
    }

    private function company(): ReportScope
    {
        return ReportScope::resolve(null, null);
    }

    private function build(string $to = '2026-12-31', ?string $from = null, ?ReportScope $scope = null, bool $expand = false, bool $showEmpty = false): TrialBalanceReport
    {
        return $this->report->build($scope ?? $this->company(), ReportPeriod::of($from, $to), $expand, $showEmpty);
    }

    private function row(TrialBalanceReport $report, string $code): ?TrialBalanceRow
    {
        foreach ($report->rows as $row) {
            if ($row->code === $code) {
                return $row;
            }
        }

        return null;
    }

    /**
     * @return list<string>
     */
    private function codes(TrialBalanceReport $report): array
    {
        return array_map(static fn (TrialBalanceRow $row): string => $row->code, $report->rows);
    }

    public function testBuild_ShowsEachAccountThatHoldsSomethingWithWhatItWasDebitedAndCredited(): void
    {
        $this->cashSale('2026-10-05', 1, '100.00');
        $this->cashSale('2026-10-06', 1, '40.00');
        $this->postOn('2026-10-07', 1, [[$this->accountId('cogs'), '30.00', '0'], [$this->accountId('cash'), '0', '30.00']]);

        $report = $this->build();
        $cash   = $this->row($report, '120101');
        $sales  = $this->row($report, '411');
        $cogs   = $this->row($report, '511');

        $this->assertSame(['0.0000', '140.0000', '30.0000', '110.0000'], [$cash->opening, $cash->debit, $cash->credit, $cash->closing()]);
        $this->assertSame(['0.0000', '0.0000', '140.0000', '-140.0000'], [$sales->opening, $sales->debit, $sales->credit, $sales->closing()]);
        $this->assertSame(['30.0000', '-30.0000'], [$cogs->debit, bcsub('0', $cogs->debit, 4)]);
        $this->assertSame('140.0000', $this->row($report, '41')->credit, 'The group above the sales account holds what it holds');
    }

    public function testBuild_GivesAGroupItsOwnLinesAndThoseOfTheAccountsUnderIt(): void
    {
        $this->cashSale('2026-10-05', 1, '100.00');

        $report = $this->build();

        foreach (['1', '12', '1201', '120101'] as $code) {
            $row = $this->row($report, $code);

            $this->assertNotNull($row, "Account {$code} has a row");
            $this->assertSame('100.0000', $row->debit, "Account {$code} holds the 100.00 debited to the cash account");
        }

        $this->assertSame(0, $this->row($report, '1')->depth);
        $this->assertSame(1, $this->row($report, '12')->depth);
        $this->assertSame(2, $this->row($report, '1201')->depth);
        $this->assertSame(3, $this->row($report, '120101')->depth);
        $this->assertTrue($this->row($report, '1201')->isGroup);
        $this->assertFalse($this->row($report, '120101')->isGroup);
    }

    public function testBuild_ListsTheRowsInTheOrderOfTheChart(): void
    {
        $this->cashSale('2026-10-05', 1, '100.00');
        $this->postOn('2026-10-06', 1, [[$this->accountId('cogs'), '30.00', '0'], [$this->accountId('cash'), '0', '30.00']]);

        $codes = $this->codes($this->build());
        $sorted = $codes;

        sort($sorted, SORT_STRING);

        $this->assertSame($sorted, $codes);
        $this->assertLessThan(array_search('411', $codes, true), array_search('120101', $codes, true));
    }

    public function testBuild_AddsUpTheAccountsAtTheTopAndTheTwoSidesAreEqual(): void
    {
        $this->cashSale('2026-10-05', 1, '100.00');
        $this->postOn('2026-10-06', $this->second, [[$this->accountId('cogs'), '30.00', '0'], [$this->accountId('cash'), '0', '30.00']]);

        $report = $this->build();

        $this->assertTrue($report->balances());
        $this->assertSame('130.0000', $report->totals['debit']);
        $this->assertSame('130.0000', $report->totals['credit']);
        $this->assertSame('100.0000', $report->totals['closing_debit'], 'The cash account stands at 70.00 and the cost of sales at 30.00');
        $this->assertSame('100.0000', $report->totals['closing_credit']);
        $this->assertSame('0.0000', $report->totals['opening_debit']);
    }

    public function testBuild_BalancesForEveryChoiceOfBranches(): void
    {
        $this->cashSale('2026-10-05', 1, '100.00');
        $this->cashSale('2026-10-06', $this->second, '55.50');

        foreach ([null, [1], [$this->second], [1, $this->second]] as $branches) {
            $report = $this->build(scope: ReportScope::resolve($branches, null));

            $this->assertTrue($report->balances(), 'A set of branches that does not balance');
        }
    }

    public function testBuild_AddsUpOnlyTheBranchesOfTheScope(): void
    {
        $this->cashSale('2026-10-05', 1, '100.00');
        $this->cashSale('2026-10-06', $this->second, '55.50');

        $first  = $this->build(scope: ReportScope::resolve([1], null));
        $second = $this->build(scope: ReportScope::resolve([$this->second], null));
        $both   = $this->build();

        $this->assertSame('100.0000', $this->row($first, '120101')->debit);
        $this->assertSame('55.5000', $this->row($second, '120101')->debit);
        $this->assertSame('155.5000', $this->row($both, '120101')->debit);
        $this->assertSame('100.0000', $first->totals['debit']);
    }

    public function testBuild_HasNothingToShowForNoBranch(): void
    {
        $this->cashSale('2026-10-05', 1, '100.00');

        $report = $this->build(scope: ReportScope::resolve(null, []));

        $this->assertSame([], $report->rows);
        $this->assertSame('0.0000', $report->totals['debit']);
        $this->assertTrue($report->balances());
    }

    public function testBuild_StartsThePeriodWhereYouSayAndCarriesWhatCameBeforeAsTheOpening(): void
    {
        $this->cashSale('2026-09-30', 1, '70.00');
        $this->cashSale('2026-10-05', 1, '100.00');
        $this->postOn('2026-10-20', 1, [[$this->accountId('cogs'), '30.00', '0'], [$this->accountId('cash'), '0', '30.00']]);
        $this->cashSale('2026-11-02', 1, '999.00');

        $report = $this->build('2026-10-31', '2026-10-01');
        $cash   = $this->row($report, '120101');

        $this->assertSame('2026-10-01', $report->from);
        $this->assertSame('2026-10-31', $report->to);
        $this->assertSame(['70.0000', '100.0000', '30.0000', '140.0000'], [$cash->opening, $cash->debit, $cash->credit, $cash->closing()], 'What came after the period is left out');
        $this->assertTrue($report->balances());
    }

    public function testBuild_CountsTheFirstAndTheLastDayOfThePeriod(): void
    {
        $this->cashSale('2026-09-30', 1, '1.00');
        $this->cashSale('2026-10-01', 1, '2.00');
        $this->cashSale('2026-10-31', 1, '4.00');
        $this->cashSale('2026-11-01', 1, '8.00');

        $cash = $this->row($this->build('2026-10-31', '2026-10-01'), '120101');

        $this->assertSame(['1.0000', '6.0000'], [$cash->opening, $cash->debit]);
    }

    public function testBuild_ReadsEverythingFromTheStartOfTheBooksWithoutAFirstDay(): void
    {
        $this->cashSale('2020-01-01', 1, '5.00');
        $this->cashSale('2026-10-05', 1, '100.00');

        $cash = $this->row($this->build('2026-10-31'), '120101');

        $this->assertSame(['0.0000', '105.0000'], [$cash->opening, $cash->debit]);
    }

    public function testBuild_CountsAReversedJournalWithItsReversalSoTheyLeaveNothingButShowTheMovement(): void
    {
        $journal = $this->cashSale('2026-10-05', 1, '100.00');
        $this->reverseOn($journal, '2026-10-06');

        $report = $this->build();
        $cash   = $this->row($report, '120101');

        $this->assertSame(['100.0000', '100.0000', '0.0000'], [$cash->debit, $cash->credit, $cash->closing()]);
        $this->assertTrue($report->balances());
    }

    public function testBuild_LeavesOutADraftThatWasNeverPosted(): void
    {
        (new JournalService())->saveDraft($this->journalInput('500.00'), null);

        $report = $this->build();

        $this->assertSame([], $report->rows);
    }

    public function testBuild_FoldsTheAccountOfEachCustomerIntoTheAccountItSitsUnderUnlessAskedFor(): void
    {
        $customer = $this->insertCustomer();
        $entity   = (new EntityAccountService())->ensure(EntityAccountService::CUSTOMER, $customer, 'Omar Khaled');

        $this->postOn('2026-10-05', 1, [[$entity, '250.00', '0'], [$this->accountId('sales_revenue'), '0', '250.00']]);

        $folded   = $this->build();
        $expanded = $this->build(expand: true);

        $this->assertNull($this->row($folded, $this->codeOf($entity)));
        $this->assertSame('250.0000', $this->row($folded, '1204')->debit, 'The control account holds what its customers hold');

        $row = $this->row($expanded, $this->codeOf($entity));

        $this->assertNotNull($row);
        $this->assertTrue($row->isEntity);
        $this->assertGreaterThan($this->row($expanded, '1204')->depth, $row->depth, 'The customer is under the control account');
        $this->assertSame($folded->totals, $expanded->totals, 'Asking for the customers changes no total');
    }

    private function codeOf(int $accountId): string
    {
        return (string) $this->accountingDb()->table('accounting_accounts')->select('code')->where('account_id', $accountId)->get()->getRow()->code;
    }

    public function testBuild_ShowsAnAccountThatNeverHeldAnythingOnlyWhenAsked(): void
    {
        $this->cashSale('2026-10-05', 1, '100.00');

        $plain = $this->build();
        $all   = $this->build(showEmpty: true);

        $this->assertNull($this->row($plain, '511'));
        $this->assertNotNull($this->row($all, '511'));
        $this->assertSame('0.0000', $this->row($all, '511')->closing());
        $this->assertGreaterThan(count($plain->rows), count($all->rows));
        $this->assertSame($plain->totals, $all->totals);
    }

    public function testBuild_NeverShowsTheAccountsOfEntitiesAsEmptyRowsWhenTheyAreFolded(): void
    {
        $customer = $this->insertCustomer();

        (new EntityAccountService())->ensure(EntityAccountService::CUSTOMER, $customer, 'Omar Khaled');

        $this->assertSame([], array_filter($this->build(showEmpty: true)->rows, static fn (TrialBalanceRow $row): bool => $row->isEntity));
    }

    public function testBuild_SaysWhatIsLeftBetweenTheBranchesForTheWholeCompanyOnly(): void
    {
        $this->postOn('2026-10-05', 1, [[$this->accountId('branches_receivable'), '50.00', '0'], [$this->accountId('cash'), '0', '50.00']]);

        $this->assertSame('50.0000', $this->build()->interBranchDifference);
        $this->assertNull($this->build(scope: ReportScope::resolve([1], null))->interBranchDifference);
        $this->assertNull($this->build(scope: ReportScope::resolve(null, [1]))->interBranchDifference);
    }

    public function testBuild_SaysNothingIsLeftBetweenTheBranchesWhenTheTwoLegsAreThere(): void
    {
        $this->postOn('2026-10-05', 1, [[$this->accountId('branches_receivable'), '50.00', '0'], [$this->accountId('cash'), '0', '50.00']]);
        $this->postOn('2026-10-05', $this->second, [[$this->accountId('cash'), '50.00', '0'], [$this->accountId('branches_payable'), '0', '50.00']]);

        $this->assertSame('0.0000', $this->build()->interBranchDifference);
    }

    public function testBuild_IgnoresTheJournalsOfAnotherCompany(): void
    {
        $db = $this->accountingDb();

        $db->table('companies')->insert(['name' => 'Another company']);
        $other = (int) $db->insertID();

        try {
            $this->cashSale('2026-10-05', 1, '100.00');
            $db->table('accounting_journals')->update(['company_id' => $other]);

            $this->assertSame([], $this->build()->rows);
        } finally {
            $db->table('accounting_journals')->update(['company_id' => 1]);
            $db->table('companies')->where('company_id', $other)->delete();
        }
    }

    public function testBuild_KeepsTheFourDecimalsTheAmountsAreKeptAt(): void
    {
        $this->cashSale('2026-10-05', 1, '0.0001');
        $this->cashSale('2026-10-06', 1, '1234567.8912');

        $cash = $this->row($this->build(), '120101');

        $this->assertSame('1234567.8913', $cash->debit);
    }

    // ---------------------------------------------------------------------------------------
    // The trial balance and the books of the documents: what it holds is what they say
    // ---------------------------------------------------------------------------------------

    /**
     * Starting to sell installs the chart again, which leaves the default branch only, so the
     * second branch is made after it.
     */
    private function startBooks(): void
    {
        $this->startBuying();

        $this->second = $this->createBranch('T2', 'Second branch');
    }

    private function everything(): TrialBalanceReport
    {
        return $this->build('2099-12-31', expand: true);
    }

    private function rowOf(TrialBalanceReport $report, int $accountId): ?TrialBalanceRow
    {
        return $this->row($report, $this->codeOf($accountId));
    }

    private function parentOf(int $accountId): int
    {
        return (int) $this->accountingDb()->table('accounting_accounts')->select('parent_id')->where('account_id', $accountId)->get()->getRow()->parent_id;
    }

    private function putInTreasury(int $treasuryId, string $amount): void
    {
        (new PostingService())->post(new JournalDraft(
            date: '2026-10-20',
            lines: [new JournalLineDraft((int) (new TreasuryService())->accountOf($treasuryId), debit: $amount), new JournalLineDraft($this->accountId('opening_balance'), credit: $amount)],
            sourceType: 'opening',
            sourceId: random_int(1000, 9999),
            currencyCode: 'SAR',
        ));
    }

    /**
     * Sales and a credit note in two branches, purchases and a debit note, stock bought and moved
     * from the first branch to the second, a transfer of money between the treasuries of the
     * two, and a sale that was cancelled.
     *
     * @return array{treasury: int, depot: int, item: int, other: int}
     */
    private function mixedBooks(): array
    {
        $this->startBooks();

        $customer = $this->anotherCustomer();
        $supplier = $this->anotherSupplier();
        $depot    = $this->locationOfBranch($this->second, 'Second depot');
        $item     = $this->item('0.00');

        $this->workInEveryBranch();
        $manager  = $this->employeeWithGrants([AccountingGuard::TREASURIES_MANAGE]);
        $main     = (new TreasuryService())->ensureMain();
        $there    = (new TreasuryService())->create(['name' => 'Second branch drawer', 'kind' => 'cash', 'branch_id' => $this->second], $manager);

        $this->putInTreasury($main, '500.00');
        (new TreasuryTransfers())->transfer($main, $there, '150.00', '2026-10-21', '', $manager);

        $this->workInBranches(1);
        $invoice = $this->invoiceOf('100.00', $this->customerId);
        $this->invoiceOf('40.00', $customer, [$this->payment(lang('Sales.cash'), '15.00')]);
        $this->creditNoteOf($invoice, '10.00', $this->customerId);

        $purchase = $this->purchaseOf('300.00');
        $this->debitNoteOf($purchase, '60.00');
        $this->payPurchase($purchase, '100.00');
        $this->assertGreaterThan(0, $this->buy([$this->purchaseLine($item, 0, '10', '50')], $supplier));

        $this->workInEveryBranch();
        $this->assertGreaterThan(0, $this->buy([$this->purchaseLine($item, 0, '4', '0', '0', '0', PERCENT, $depot), $this->purchaseLine($item, 1, '-4', '0')], null, null));

        $this->workInBranches($this->second);
        $this->invoiceOf('70.00', $this->customerId, [$this->payment(lang('Sales.cash'), '20.00')]);
        $cancelled = $this->save([$this->line($this->item('0', false), 0, '1', '55.00')], [], [$this->payment(lang('Sales.cash'), '55.00')], SALE_TYPE_POS, $customer);

        $this->assertTrue($this->sales->delete($cancelled, false, true, $this->employeeId));

        return ['treasury' => $there, 'depot' => $depot, 'item' => $item, 'other' => $customer];
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

        $report  = $this->everything();
        $control = $this->rowOf($report, $this->parentOf($this->customerAccount()));
        $sum     = '0.0000';

        foreach ([$this->customerId, $other] as $customerId) {
            $row = $this->rowOf($report, $this->customerAccount($customerId));

            $this->assertNotNull($row);
            $this->assertSame(bcadd((new CustomerLedger())->position($customerId)->accountBalance, '0', 4), $row->closing(), 'The account of the customer and what the ledger of customers says differ');

            $sum = bcadd($sum, $row->closing(), 4);
        }

        $this->assertSame('180.0000', $sum, '100 less 10 and 70 less 20 for the first customer, and 40 for the other');
        $this->assertSame($sum, $control->closing());
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

        $report  = $this->everything();
        $control = $this->rowOf($report, $this->parentOf($this->supplierAccount()));
        $sum     = '0.0000';

        foreach ([$this->supplierId, $other] as $supplierId) {
            $row = $this->rowOf($report, $this->supplierAccount($supplierId));

            $this->assertNotNull($row);
            $this->assertSame(bcsub('0', bcadd($this->supplierPosition($supplierId)->accountBalance, '0', 4), 4), $row->closing(), 'The account of the supplier and what the ledger of suppliers says differ');

            $sum = bcadd($sum, $row->closing(), 4);
        }

        $this->assertSame('-340.0000', $sum, '300 less 60 and 100 for the first supplier, and 200 for the other');
        $this->assertSame($sum, $control->closing());
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

        $report = $this->everything();
        $sum    = '0.0000';

        foreach ((new StockLedger())->reconcile() as $location) {
            $row = $this->rowOf($report, $this->stockAccountOf((int) $location['location_id']));

            if ($row === null) {
                $this->assertSame('0.00', bcadd($location['movements_value'], '0', 2));

                continue;
            }

            $this->assertSame(bcadd($location['movements_value'], '0', 4), $row->closing(), 'Location ' . $location['location_id'] . ': the trial balance and the stock movements differ');

            $sum = bcadd($sum, $row->closing(), 4);
        }

        $this->assertSame('400.0000', $sum, '500 bought, 200 moved out of one location and into the other, 100 sold at cost');
        $this->assertSame($sum, $this->rowOf($report, $this->parentOf($this->stockAccountOf(self::LOCATION_ID)))->closing());
    }

    public function testBuild_HoldsInTheTreasuryAccountsWhatEveryTreasuryHolds(): void
    {
        $this->startBooks();

        $treasuries = new TreasuryService();
        $manager    = $this->employeeWithGrants([AccountingGuard::TREASURIES_MANAGE]);
        $main       = $treasuries->ensureMain();
        $there      = $treasuries->create(['name' => 'Second branch drawer', 'kind' => 'cash', 'branch_id' => $this->second], $manager);

        $this->putInTreasury($main, '500.00');
        (new TreasuryTransfers())->transfer($main, $there, '150.00', '2026-10-21', '', $manager);

        $report = $this->everything();

        foreach ([$main => '350.0000', $there => '150.0000'] as $treasuryId => $expected) {
            $row = $this->rowOf($report, (int) $treasuries->accountOf($treasuryId));

            $this->assertNotNull($row);
            $this->assertSame($expected, $row->closing());
            $this->assertSame(bcadd($treasuries->position($treasuryId)->balance, '0', 4), $row->closing(), 'The account of the treasury and its position differ');
        }
    }

    public function testBuild_LeavesTheTwoAccountsBetweenTheBranchesOffsettingAcrossTheCompany(): void
    {
        $this->mixedBooks();

        $report     = $this->build('2099-12-31');
        $receivable = $this->row($report, $this->codeOf($this->accountId('branches_receivable')));
        $payable    = $this->row($report, $this->codeOf($this->accountId('branches_payable')));

        $this->assertNotNull($receivable);
        $this->assertNotNull($payable);
        $this->assertSame('350.0000', $receivable->closing(), '150 of money and 200 of stock sent out of the first branch');
        $this->assertSame('-350.0000', $payable->closing());
        $this->assertSame('0.0000', $report->interBranchDifference);
    }

    public function testBuild_ShowsEachBranchItsOwnSideOfWhatIsBetweenTheBranches(): void
    {
        $this->mixedBooks();

        $receivable = $this->codeOf($this->accountId('branches_receivable'));
        $payable    = $this->codeOf($this->accountId('branches_payable'));

        $sending   = $this->build('2099-12-31', scope: ReportScope::resolve([1], null));
        $receiving = $this->build('2099-12-31', scope: ReportScope::resolve([$this->second], null));

        $this->assertSame('350.0000', $this->row($sending, $receivable)->closing());
        $this->assertNull($this->row($sending, $payable));
        $this->assertSame('-350.0000', $this->row($receiving, $payable)->closing());
        $this->assertNull($this->row($receiving, $receivable));
        $this->assertNull($sending->interBranchDifference);
        $this->assertNull($receiving->interBranchDifference);
    }

    public function testBuild_BalancesForTheCompanyAndForEveryBranchAndEveryPairOfBranchesAfterAMixedBooks(): void
    {
        $this->mixedBooks();

        $third = $this->createBranch('T3', 'Third branch');

        foreach ([[1], [$this->second], [$third], [1, $this->second], [1, $third], [$this->second, $third], [1, $this->second, $third]] as $branches) {
            $report = $this->build('2099-12-31', scope: ReportScope::resolve($branches, null));

            $this->assertTrue($report->balances(), 'Branches ' . implode(',', $branches) . ': ' . json_encode($report->totals));
            $this->assertSame($report->totals['debit'], $report->totals['credit']);
        }

        $this->assertGreaterThan(0, bccomp($this->build('2099-12-31')->totals['debit'], '0', 4));
    }

    public function testBuild_ReadsTheBooksAsTheyWereOnceADocumentIsCancelledWithItsReversal(): void
    {
        $this->startBooks();

        $this->workInBranches(1);
        $this->invoiceOf('100.00', $this->customerId, [$this->payment(lang('Sales.cash'), '30.00')]);

        $closings = static fn (TrialBalanceReport $report): array => array_column(array_map(static fn (TrialBalanceRow $row): array => [$row->code, $row->closing()], $report->rows), 1, 0);
        $before   = $closings($this->everything());

        $saleId = $this->save([$this->line($this->item('0', false), 0, '1', '55.00')], [], [$this->payment(lang('Sales.cash'), '55.00')], SALE_TYPE_POS);

        $this->assertNotSame($before, $closings($this->everything()));
        $this->assertTrue($this->sales->delete($saleId, false, true, $this->employeeId));

        $after = $this->everything();

        $this->assertSame($before, $closings($after));
        $this->assertTrue($after->balances());
        $this->assertSame($this->row($after, '411')->debit, '55.0000', 'The reversal is in the movement of the sales account');
    }
}
