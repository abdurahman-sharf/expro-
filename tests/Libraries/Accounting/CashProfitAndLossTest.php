<?php

namespace Tests\Libraries\Accounting;

use App\Libraries\Accounting\CashProfitAndLoss;
use App\Libraries\Accounting\CashProfitAndLossReport;
use App\Libraries\Accounting\CashProfitLine;
use App\Libraries\Accounting\EntityAccountService;
use App\Libraries\Accounting\ProfitBasis;
use App\Libraries\Accounting\ReportPeriod;
use App\Libraries\Accounting\ReportScope;
use App\Libraries\Accounting\TrialBalance;
use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\DatabaseTestTrait;
use Config\Database;
use Tests\Support\AccountingFixtureTrait;
use Tests\Support\ParityCaseTrait;
use Tests\Support\ReportFixtureTrait;
use Tests\Support\StatementFixtureTrait;

/**
 * The profit and loss by cash: what customers paid, what was paid to suppliers, expenses and the tax
 * on them counted on the day money moved through a treasury or a bank, the other movements kept out
 * of the profit, and the profit and those movements adding up to what the treasuries and banks gained.
 */
final class CashProfitAndLossTest extends CIUnitTestCase
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

    private CashProfitAndLoss $statement;
    private int $customer;
    private int $supplier;
    private int $bank;

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

        $this->second    = $this->createBranch('T2', 'Second branch');
        $this->statement = new CashProfitAndLoss();
        $this->customer  = (new EntityAccountService())->ensure(EntityAccountService::CUSTOMER, $this->insertCustomer(), 'Omar Khaled');
        $this->supplier  = (new EntityAccountService())->ensure(EntityAccountService::SUPPLIER, $this->insertSupplier(), 'Gulf Supplies');
        $this->bank      = $this->customAccount('1202', '120201', 'Bank account');
    }

    protected function tearDown(): void
    {
        $this->removeInsertedEntities();
        $this->clearJournals();
        $this->reinstallChart();
        $this->resetBranches();

        parent::tearDown();
    }

    private function cash(?string $from = null, string $to = '2026-12-31', ?ReportScope $scope = null): CashProfitAndLossReport
    {
        return $this->statement->build($scope ?? ReportScope::resolve(null, null), ReportPeriod::of($from, $to));
    }

    /**
     * @param list<CashProfitLine> $lines
     *
     * @return array<string, string> What each line adds, by the code of its account or the name of its kind.
     */
    private function amounts(array $lines): array
    {
        $found = [];

        foreach ($lines as $line) {
            $found[$line->code ?? $line->kind] = $line->amount;
        }

        return $found;
    }

    private function everyKindOfMovement(): void
    {
        $cash = $this->accountId('cash');

        $this->postOn('2026-10-01', 1, [[$this->customer, '1150.00', '0'], [$this->accountId('sales_revenue'), '0', '1000.00'], [$this->accountId('tax_output'), '0', '150.00']]);
        $this->postOn('2026-10-02', 1, [[$cash, '100000.00', '0'], [$this->accountIdByCode('31'), '0', '100000.00']]);
        $this->postOn('2026-10-03', 1, [[$this->accountId('purchases'), '600.00', '0'], [$this->accountId('tax_input'), '90.00', '0'], [$this->supplier, '0', '690.00']]);
        $this->postOn('2026-10-05', 1, [[$cash, '400.00', '0'], [$this->customer, '0', '400.00']]);
        $this->postOn('2026-10-06', 1, [[$this->supplier, '200.00', '0'], [$cash, '0', '200.00']]);
        $this->postOn('2026-10-06', 1, [[$cash, '50.00', '0'], [$this->supplier, '0', '50.00']]);
        $this->postOn('2026-10-07', 1, [[$this->accountIdByCode('545'), '200.00', '0'], [$this->accountId('tax_input'), '30.00', '0'], [$cash, '0', '230.00']]);
        $this->postOn('2026-10-08', 1, [[$cash, '1150.00', '0'], [$this->accountIdByCode('4201'), '0', '1000.00'], [$this->accountId('tax_output'), '0', '150.00']]);
        $this->postOn('2026-10-09', 1, [[$this->customer, '25.00', '0'], [$cash, '0', '25.00']]);
        $this->postOn('2026-10-10', 1, [[$this->bank, '70.00', '0'], [$cash, '0', '70.00']]);
    }

    public function testBuild_CountsOnTheDayOfPaymentWhatCustomersPaidAndWhatWasPaidToSuppliers(): void
    {
        $this->everyKindOfMovement();

        $report = $this->cash();

        $this->assertSame(['customers' => '375.0000', '4201' => '1000.0000'], $this->amounts($report->revenueLines), 'Receipts net of what was paid back, and the revenue that came in as money');
        $this->assertSame(['suppliers' => '150.0000', 'tax_input' => '30.0000', '545' => '200.0000'], $this->amounts($report->expenseLines), 'Payments net of what suppliers paid back, the tax paid on expenses, and the expense');
        $this->assertSame(['1375.0000', '380.0000', '995.0000'], [$report->totalRevenue, $report->totalExpenses, $report->profit]);
        $this->assertSame(ProfitBasis::Cash, $report->basis);
    }

    public function testBuild_KeepsCapitalAndTheTaxCollectedOutOfTheProfitAndListsThemAsOtherMovements(): void
    {
        $this->everyKindOfMovement();

        $report = $this->cash();

        $this->assertSame(['215001' => '150.0000', '31' => '100000.0000'], $this->amounts($report->otherLines), 'In the order of the codes');
        $this->assertSame('100150.0000', $report->otherTotal);
    }

    public function testBuild_AddsUpWithTheOtherMovementsToTheChangeOfTheTreasuriesAndTheBanks(): void
    {
        $this->everyKindOfMovement();

        $report = $this->cash();
        $books  = (new TrialBalance())->build(ReportScope::resolve(null, null), ReportPeriod::of(null, '2026-12-31'));
        $held   = '0.0000';

        foreach ($books->rows as $row) {
            if (in_array($row->code, ['1201', '1202'], true)) {
                $held = bcadd($held, $row->closing(), 4);
            }
        }

        $this->assertSame('101145.0000', $held, 'What the treasuries and the banks hold in the books');
        $this->assertSame($held, $report->cashChange);
        $this->assertSame($held, bcadd($report->profit, $report->otherTotal, 4));
        $this->assertTrue($report->ties());
    }

    public function testBuild_TiesForEveryChoiceOfBranchesAndOfDays(): void
    {
        $this->everyKindOfMovement();
        $this->postOn('2026-10-12', $this->second, [[$this->accountId('cash'), '33.00', '0'], [$this->accountIdByCode('4201'), '0', '33.00']]);
        $this->postOn('2026-11-02', $this->second, [[$this->accountIdByCode('545'), '12.00', '0'], [$this->accountId('cash'), '0', '12.00']]);

        foreach ([null, [1], [$this->second]] as $branches) {
            foreach ([[null, '2026-12-31'], ['2026-10-06', '2026-10-31'], ['2026-11-01', '2026-11-30']] as [$from, $to]) {
                $report = $this->cash($from, $to, ReportScope::resolve($branches, null));

                $this->assertTrue($report->ties(), 'Branches ' . json_encode($branches) . " from {$from} to {$to}");
            }
        }
    }

    public function testBuild_CountsADocumentOnTheDayItWasPaidAndNotTheDayItWasIssued(): void
    {
        $this->postOn('2026-10-01', 1, [[$this->customer, '500.00', '0'], [$this->accountId('sales_revenue'), '0', '500.00']]);
        $this->postOn('2026-11-05', 1, [[$this->accountId('cash'), '300.00', '0'], [$this->customer, '0', '300.00']]);

        $october  = $this->cash('2026-10-01', '2026-10-31');
        $november = $this->cash('2026-11-01', '2026-11-30');

        $this->assertSame('0.0000', $october->totalRevenue, 'Issued in October and not yet paid');
        $this->assertSame('300.0000', $november->totalRevenue);
        $this->assertSame('300.0000', $november->profit);
    }

    public function testBuild_NetsAPaymentAndItsReversalAndCountsNothingOfAPaymentThatWasTakenBack(): void
    {
        $journal = $this->postOn('2026-10-05', 1, [[$this->accountId('cash'), '400.00', '0'], [$this->customer, '0', '400.00']]);
        $this->reverseOn($journal, '2026-10-06');

        $report = $this->cash();

        $this->assertSame('0.0000', $report->totalRevenue);
        $this->assertSame('0.0000', $report->cashChange);
        $this->assertTrue($report->ties());
    }

    public function testBuild_LeavesATransferBetweenTwoTreasuriesOutOfTheProfitAndOutOfTheOtherMovements(): void
    {
        $this->postOn('2026-10-10', 1, [[$this->bank, '70.00', '0'], [$this->accountId('cash'), '0', '70.00']]);

        $report = $this->cash();

        $this->assertSame([], $report->otherLines);
        $this->assertSame(['0.0000', '0.0000', '0.0000'], [$report->profit, $report->otherTotal, $report->cashChange], 'Money moved from one treasury to another is not gained');
    }

    public function testBuild_CountsWhatWasPaidThroughABankAsWhatWasPaidThroughATreasury(): void
    {
        $this->postOn('2026-10-05', 1, [[$this->supplier, '120.00', '0'], [$this->bank, '0', '120.00']]);
        $this->postOn('2026-10-06', 1, [[$this->bank, '80.00', '0'], [$this->accountId('counter_customers'), '0', '80.00']]);

        $report = $this->cash();

        $this->assertSame(['customers' => '80.0000'], $this->amounts($report->revenueLines), 'A counter customer is a customer');
        $this->assertSame('120.0000', $this->amounts($report->expenseLines)['suppliers']);
        $this->assertSame('-40.0000', $report->profit);
        $this->assertTrue($report->ties());
    }

    public function testBuild_ReadsOnlyTheBranchesOfTheScope(): void
    {
        $this->postOn('2026-10-05', 1, [[$this->accountId('cash'), '100.00', '0'], [$this->customer, '0', '100.00']]);
        $this->postOn('2026-10-06', $this->second, [[$this->accountId('cash'), '7.00', '0'], [$this->customer, '0', '7.00']]);

        $first = $this->cash(scope: ReportScope::resolve([1], null));
        $other = $this->cash(scope: ReportScope::resolve([$this->second], null));
        $none  = $this->cash(scope: ReportScope::resolve(null, []));

        $this->assertSame(['100.0000', '7.0000'], [$first->totalRevenue, $other->totalRevenue]);
        $this->assertSame(['0.0000', '0.0000', '0.0000'], [$none->totalRevenue, $none->cashChange, $none->profit]);
        $this->assertTrue($none->ties());
    }

    public function testBuild_CountsTheFirstAndTheLastDayOfTheRange(): void
    {
        $this->postOn('2026-10-04', 1, [[$this->accountId('cash'), '1.00', '0'], [$this->customer, '0', '1.00']]);
        $this->postOn('2026-10-05', 1, [[$this->accountId('cash'), '20.00', '0'], [$this->customer, '0', '20.00']]);
        $this->postOn('2026-10-06', 1, [[$this->accountId('cash'), '300.00', '0'], [$this->customer, '0', '300.00']]);
        $this->postOn('2026-10-07', 1, [[$this->accountId('cash'), '4000.00', '0'], [$this->customer, '0', '4000.00']]);

        $this->assertSame('320.0000', $this->cash('2026-10-05', '2026-10-06')->totalRevenue);
    }

    public function testBuild_LeavesOutTheJournalsThatCloseAYear(): void
    {
        $this->postOn('2026-10-05', 1, [[$this->accountId('cash'), '100.00', '0'], [$this->accountIdByCode('4201'), '0', '100.00']]);
        $this->postYearEnd('2026-12-31', 1, [[$this->accountIdByCode('4201'), '100.00', '0'], [$this->accountId('retained_earnings'), '0', '100.00']]);

        $report = $this->cash();

        $this->assertSame(['100.0000', '100.0000'], [$report->totalRevenue, $report->cashChange]);
        $this->assertTrue($report->ties());
    }

    public function testBuild_LeavesOutAJournalOfTheClosingOfAYearEvenWhenItTouchesATreasury(): void
    {
        $this->postOn('2026-10-05', 1, [[$this->accountId('cash'), '100.00', '0'], [$this->accountIdByCode('4201'), '0', '100.00']]);
        $this->postYearEnd('2026-12-31', 1, [[$this->accountId('cash'), '40.00', '0'], [$this->accountId('retained_earnings'), '0', '40.00']]);

        $report = $this->cash();

        $this->assertSame(['100.0000', '100.0000'], [$report->totalRevenue, $report->cashChange]);
        $this->assertSame([], $report->otherLines);
        $this->assertTrue($report->ties());
    }

    public function testBuild_ReadsAClosedPeriodAsAnyOther(): void
    {
        $this->postOn('2026-10-05', 1, [[$this->accountId('cash'), '100.00', '0'], [$this->customer, '0', '100.00']]);
        $this->closeThrough('2026-10-31');

        $report = $this->cash('2026-10-01', '2026-10-31');

        $this->assertSame('100.0000', $report->totalRevenue);
        $this->assertTrue($report->ties());
    }

    public function testBuild_HasZerosAndTheSameLinesForABookWithNoMoney(): void
    {
        $report = $this->cash();

        $this->assertSame(['customers' => '0.0000'], $this->amounts($report->revenueLines));
        $this->assertSame(['suppliers' => '0.0000', 'tax_input' => '0.0000'], $this->amounts($report->expenseLines));
        $this->assertSame([], $report->otherLines);
        $this->assertTrue($report->ties());
    }

    public function testBuild_ReproducesTheSalesOnThePaymentBasisAsWhatTheCustomersPaid(): void
    {
        $case = $this->referenceCase('T-68', 'cash_profit', 'sales-on-the-payment-basis-are-what-the-customers-paid', 'exact');

        foreach ($case['inputs']['customer_payments'] as $index => $amount) {
            $this->postOn('2026-10-' . sprintf('%02d', $index + 1), 1, [[$this->accountId('cash'), $amount, '0'], [$this->customer, '0', $amount]]);
        }

        $report = $this->cash('2026-10-01', '2026-10-31');

        $this->assertSameAsFixture($case['expected'], ['sales' => $this->amounts($report->revenueLines)['customers']], 'T-68 sales on the payment basis are what the customers paid');
    }

    public function testBuild_CountsThePurchasesAsThePaymentsToSuppliersNetOfWhatTheyPaidBackWhereTheReferenceSystemFigureIsNotExplained(): void
    {
        $case = $this->referenceCase('T-68', 'cash_profit', 'purchases-on-the-payment-basis-are-the-payments-to-suppliers-net-of-returns', 'differs');

        $this->postOn('2026-10-05', 1, [[$this->supplier, $case['inputs']['supplier_payments'], '0'], [$this->accountId('cash'), '0', $case['inputs']['supplier_payments']]]);
        $this->postOn('2026-10-06', 1, [[$this->accountId('cash'), $case['inputs']['returns_received'], '0'], [$this->supplier, '0', $case['inputs']['returns_received']]]);

        $report = $this->cash('2026-10-01', '2026-10-31');

        $this->assertSameAsFixture($case['expected'], ['purchases' => $this->amounts($report->expenseLines)['suppliers']], 'T-68 purchases on the payment basis');
        $this->assertTrue($report->ties());
    }
}
