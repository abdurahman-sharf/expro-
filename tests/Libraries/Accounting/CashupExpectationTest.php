<?php

namespace Tests\Libraries\Accounting;

use App\Libraries\Accounting\CashupExpectation;
use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\DatabaseTestTrait;
use Config\Database;
use Config\OSPOS;
use Tests\Support\AccountingFixtureTrait;
use Tests\Support\EmployeeFixtureTrait;
use Tests\Support\ExpenseAccountingFixtureTrait;

/**
 * The cash a drawer should hold when it is counted: what it was opened with and what was moved into
 * it, plus the cash payments of the sales of its branch, less the cash paid out for its expenses,
 * in the time between opening and closing.
 */
class CashupExpectationTest extends CIUnitTestCase
{
    use DatabaseTestTrait;
    use AccountingFixtureTrait;
    use EmployeeFixtureTrait;
    use ExpenseAccountingFixtureTrait;

    protected $migrate     = true;
    protected $migrateOnce = true;
    protected $refresh     = false;
    protected $namespace   = null;

    private CashupExpectation $expectation;

    public static function setUpBeforeClass(): void
    {
        $seeder = Database::seeder('tests');
        $seeder->call('TestDatabaseBootstrapSeeder');
    }

    protected function setUp(): void
    {
        parent::setUp();

        $this->startExpenses();

        $this->expectation = new CashupExpectation();
    }

    protected function tearDown(): void
    {
        $this->stopExpenses();

        parent::tearDown();
    }

    private function expected(int $branchId = 1, string $open = '100', string $transfer = '0'): string
    {
        return $this->expectation->expected($branchId, '2026-10-20 08:00:00', '2026-10-20 18:00:00', $open, $transfer);
    }

    private function insertExpense(string $amount, string $payment = 'Cash', ?int $branchId = 1, int $deleted = 0, string $date = '2026-10-20 13:00:00', ?int $treasuryBranchId = null): void
    {
        $this->accountingDb()->table('expenses')->insert([
            'date'                => $date,
            'amount'              => $amount,
            'payment_type'        => $payment,
            'expense_category_id' => $this->category(),
            'description'         => 'Direct',
            'employee_id'         => $this->clerkId,
            'deleted'             => $deleted,
            'branch_id'           => $branchId,
            'treasury_branch_id'  => $treasuryBranchId,
        ]);
    }

    public function testExpected_IsTheOpeningCashAndWhatWasMovedInWhenNothingHappened(): void
    {
        $this->assertSame('130.0000', $this->expected(1, '100', '30'));
        $this->assertSame('70.0000', $this->expected(1, '100', '-30'));
    }

    public function testExpected_AddsTheCashPaymentsOfTheSalesOfTheBranch(): void
    {
        $this->sellFor('250.00');
        $this->sellFor('50.00');

        $this->assertSame('400.0000', $this->expected());
    }

    public function testExpected_TakesTheCashRefundedOffTheCashThatCameIn(): void
    {
        $this->sellFor('250.00', 'Cash', '2026-10-20 12:00:00', 1, 'completed', '20.00');

        $this->assertSame('330.0000', $this->expected());
    }

    public function testExpected_LeavesOutPaymentsThatAreNotCash(): void
    {
        $this->sellFor('250.00', 'Debit Card');
        $this->sellFor('90.00', 'Due');

        $this->assertSame('100.0000', $this->expected());
    }

    public function testExpected_LeavesOutPaymentsThatAreNotCompleted(): void
    {
        $this->sellFor('250.00', 'Cash', '2026-10-20 12:00:00', 1, 'draft');
        $this->sellFor('40.00', 'Cash', '2026-10-20 12:00:00', 1, 'failed');

        $this->assertSame('100.0000', $this->expected());
    }

    public function testExpected_LeavesOutTheSalesOfAnotherBranch(): void
    {
        $second = $this->createBranch('B2');

        $this->sellFor('250.00', 'Cash', '2026-10-20 12:00:00', $second);

        $this->assertSame('100.0000', $this->expected(1));
        $this->assertSame('350.0000', $this->expected($second));
    }

    public function testExpected_CountsACashPaymentIntoTheTreasuryOfAnotherBranchInTheDrawerThatHoldsIt(): void
    {
        $second = $this->createBranch('B2');
        $saleId = $this->sellFor('250.00');

        $this->accountingDb()->table('sales_payments')->where('sale_id', $saleId)->update(['treasury_branch_id' => $second]);

        $this->assertSame('100.0000', $this->expected(1), 'The sale is of the first branch but the money is in the drawer of the second');
        $this->assertSame('350.0000', $this->expected($second));
    }

    public function testExpected_CountsEachCashPaymentOfASaleInTheDrawerThatHoldsIt(): void
    {
        $second = $this->createBranch('B2');
        $saleId = $this->sellFor('250.00');

        $this->accountingDb()->table('sales_payments')->insert([
            'sale_id'            => $saleId,
            'payment_type'       => 'Cash',
            'payment_amount'     => '60.00',
            'cash_refund'        => '0',
            'employee_id'        => $this->clerkId,
            'reference_code'     => '',
            'payment_status'     => 'completed',
            'treasury_branch_id' => $second,
        ]);

        $this->assertSame('350.0000', $this->expected(1));
        $this->assertSame('160.0000', $this->expected($second));
    }

    public function testExpected_TakesTheCashOfAnExpensePaidOutOfATreasuryOfAnotherBranchOffTheDrawerThatPaidIt(): void
    {
        $second = $this->createBranch('B2');

        $this->insertExpense('30.00', 'Cash', 1, 0, '2026-10-20 13:00:00', $second);
        $this->insertExpense('12.00', 'Cash', $second, 0, '2026-10-20 14:00:00', 1);

        $this->assertSame('88.0000', $this->expected(1), 'The expense of the first branch is paid by the second drawer, the one of the second by the first');
        $this->assertSame('70.0000', $this->expected($second));
    }

    public function testExpected_LeavesOutSalesOutsideTheTimeOfTheCashUp(): void
    {
        $this->sellFor('250.00', 'Cash', '2026-10-19 12:00:00');
        $this->sellFor('250.00', 'Cash', '2026-10-21 12:00:00');
        $this->sellFor('10.00', 'Cash', '2026-10-20 12:00:00');

        $this->assertSame('110.0000', $this->expected());
    }

    public function testExpected_CountsEveryDayFromOpeningToClosingOfACashUpThatSpansSeveralDays(): void
    {
        $this->sellFor('10.00', 'Cash', '2026-10-18 12:00:00');
        $this->sellFor('20.00', 'Cash', '2026-10-19 07:00:00');
        $this->sellFor('40.00', 'Cash', '2026-10-20 12:00:00');
        $this->sellFor('80.00', 'Cash', '2026-10-21 23:00:00');
        $this->sellFor('160.00', 'Cash', '2026-10-22 12:00:00');
        $this->insertExpense('3.00', 'Cash', 1, 0, '2026-10-18 09:00:00');
        $this->insertExpense('5.00', 'Cash', 1, 0, '2026-10-21 09:00:00');
        $this->insertExpense('7.00', 'Cash', 1, 0, '2026-10-22 09:00:00');

        $this->assertSame('235.0000', $this->expectation->expected(1, '2026-10-19 08:00:00', '2026-10-21 18:00:00', '100', '0'));
    }

    public function testExpected_CountsOnlyTheTimeFromOpeningToClosingWhenTheSettingsAskForTimes(): void
    {
        $settings = config(OSPOS::class)->settings;

        config(OSPOS::class)->settings['date_or_time_format'] = '1';

        try {
            $this->sellFor('20.00', 'Cash', '2026-10-19 07:00:00');
            $this->sellFor('30.00', 'Cash', '2026-10-19 09:00:00');
            $this->sellFor('40.00', 'Cash', '2026-10-20 12:00:00');
            $this->sellFor('80.00', 'Cash', '2026-10-21 17:00:00');
            $this->sellFor('160.00', 'Cash', '2026-10-21 23:00:00');
            $this->insertExpense('3.00', 'Cash', 1, 0, '2026-10-19 07:00:00');
            $this->insertExpense('5.00', 'Cash', 1, 0, '2026-10-21 09:00:00');
            $this->insertExpense('7.00', 'Cash', 1, 0, '2026-10-21 19:00:00');

            $this->assertSame('245.0000', $this->expectation->expected(1, '2026-10-19 08:00:00', '2026-10-21 18:00:00', '100', '0'));
        } finally {
            config(OSPOS::class)->settings = $settings;
        }
    }

    public function testExpected_LeavesOutQuotesAndWorkOrdersThatTookNoCash(): void
    {
        $this->sellFor('250.00', 'Cash', '2026-10-20 12:00:00', 1, 'completed', '0', SALE_TYPE_QUOTE);
        $this->sellFor('90.00', 'Cash', '2026-10-20 12:00:00', 1, 'completed', '0', SALE_TYPE_WORK_ORDER);
        $this->sellFor('10.00', 'Cash', '2026-10-20 12:00:00', 1, 'completed', '0', SALE_TYPE_INVOICE);

        $this->assertSame('110.0000', $this->expected());
    }

    public function testExpected_LeavesOutSalesThatAreNotCompleted(): void
    {
        $saleId = $this->sellFor('250.00');

        $this->accountingDb()->table('sales')->where('sale_id', $saleId)->update(['sale_status' => SUSPENDED]);

        $this->assertSame('100.0000', $this->expected());
    }

    public function testExpected_TakesTheCashExpensesOfTheBranchOff(): void
    {
        $this->insertExpense('30.00');
        $this->insertExpense('12.50');

        $this->assertSame('57.5000', $this->expected());
    }

    public function testExpected_LeavesOutExpensesThatAreNotCashDeletedOrOfAnotherBranch(): void
    {
        $second = $this->createBranch('B2');

        $this->insertExpense('30.00', 'Debit Card');
        $this->insertExpense('30.00', 'Due');
        $this->insertExpense('30.00', 'Cash', 1, 1);
        $this->insertExpense('30.00', 'Cash', $second);
        $this->insertExpense('30.00', 'Cash', 1, 0, '2026-10-22 13:00:00');

        $this->assertSame('100.0000', $this->expected());
    }

    public function testExpected_CountsAnExpenseWithoutABranchAsOneOfTheDefaultBranch(): void
    {
        $second = $this->createBranch('B2');

        $this->insertExpense('30.00', 'Cash', null);

        $this->assertSame('70.0000', $this->expected(1));
        $this->assertSame('100.0000', $this->expected($second));
    }

    public function testExpected_AddsBackAnExpenseThatCameBackAsCash(): void
    {
        $this->insertExpense('-20.00');

        $this->assertSame('120.0000', $this->expected());
    }

    public function testExpected_CombinesSalesAndExpenses(): void
    {
        $this->sellFor('300.00');
        $this->insertExpense('45.00');

        $this->assertSame('355.0000', $this->expected(1, '100', '0'));
    }
}
