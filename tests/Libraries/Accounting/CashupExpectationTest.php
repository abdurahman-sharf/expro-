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
use Tests\Support\ItemFixtureTrait;

/**
 * The cash a drawer should hold when it is counted: what it was opened with and what was moved into
 * it, plus the cash that came into the drawer of its branch, less the cash that went out of it,
 * in the time between opening and closing.
 */
class CashupExpectationTest extends CIUnitTestCase
{
    use DatabaseTestTrait;
    use AccountingFixtureTrait;
    use EmployeeFixtureTrait;
    use ExpenseAccountingFixtureTrait;
    use ItemFixtureTrait;

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
        $this->removePurchaseRows();
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

    private function insertVoucher(string $amount, string $payment = 'Cash', int $branchId = 1, int $deleted = 0, string $date = '2026-10-20', ?int $treasuryBranchId = null): void
    {
        $this->accountingDb()->table('revenues')->insert([
            'date'               => $date,
            'amount'             => $amount,
            'payment_type'       => $payment,
            'description'        => 'Voucher',
            'employee_id'        => $this->clerkId,
            'deleted'            => $deleted,
            'branch_id'          => $branchId,
            'treasury_branch_id' => $treasuryBranchId,
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

    private function sellInDollars(string $amount, string $saleRate, ?string $paymentRate, string $refund = '0'): int
    {
        $saleId = $this->sellFor($amount, 'Cash', '2026-10-20 12:00:00', 1, 'completed', $refund);
        $db     = $this->accountingDb();

        $db->table('sales')->where('sale_id', $saleId)->update(['currency_code' => 'USD', 'currency_rate' => $saleRate]);
        $db->table('sales_payments')->where('sale_id', $saleId)->update(['currency_code' => $paymentRate === null ? null : 'USD', 'currency_rate' => $paymentRate]);

        return $saleId;
    }

    public function testExpected_CountsACashPaymentOfAForeignSaleAtTheRateOfThePaymentInTheBase(): void
    {
        $this->sellInDollars('100.00', '3.75000000', '3.60000000');

        $this->assertSame('460.0000', $this->expected());
    }

    public function testExpected_CountsAForeignPaymentThatHasNoRateOfItsOwnAtTheRateOfTheSale(): void
    {
        $this->sellInDollars('100.00', '3.75000000', null);

        $this->assertSame('475.0000', $this->expected());
    }

    public function testExpected_RoundsTheForeignCashAndTakesTheRefundOffInTheForeignCurrencyFirst(): void
    {
        $this->sellInDollars('33.33', '3.75000000', '3.60000000');
        $this->sellInDollars('100.00', '3.75000000', '3.60000000', '10.00');

        $this->assertSame('543.9900', $this->expected());
    }

    public function testExpected_AddsForeignAndBaseCashPaymentsInTheBase(): void
    {
        $this->sellInDollars('100.00', '3.75000000', '3.60000000');
        $this->sellFor('50.00');

        $this->assertSame('510.0000', $this->expected());
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

    public function testExpected_CountsTheCashReceiptVouchersOfTheBranch(): void
    {
        $this->insertVoucher('50.00');
        $this->insertVoucher('12.50');

        $this->assertSame('162.5000', $this->expected());
    }

    public function testExpected_LeavesOutVouchersThatAreNotCashDeletedOfAnotherBranchOrOfOtherDays(): void
    {
        $second = $this->createBranch('B2');

        $this->insertVoucher('30.00', 'Debit Card');
        $this->insertVoucher('30.00', 'Cash', 1, 1);
        $this->insertVoucher('30.00', 'Cash', $second);
        $this->insertVoucher('30.00', 'Cash', 1, 0, '2026-10-19');
        $this->insertVoucher('30.00', 'Cash', 1, 0, '2026-10-21');

        $this->assertSame('100.0000', $this->expected());
        $this->assertSame('130.0000', $this->expected($second), 'The cash voucher of the second branch is its own');
    }

    public function testExpected_CountsAVoucherInTheDrawerOfTheTreasuryThatHoldsTheCash(): void
    {
        $second = $this->createBranch('B2');

        $this->insertVoucher('30.00', 'Cash', 1, 0, '2026-10-20', $second);

        $this->assertSame('100.0000', $this->expected(1), 'The drawer of the first branch holds nothing of it');
        $this->assertSame('130.0000', $this->expected($second));
    }

    public function testExpected_CountsEveryDayOfAVoucherFromOpeningToClosing(): void
    {
        $this->insertVoucher('10.00', 'Cash', 1, 0, '2026-10-18');
        $this->insertVoucher('20.00', 'Cash', 1, 0, '2026-10-19');
        $this->insertVoucher('40.00', 'Cash', 1, 0, '2026-10-21');
        $this->insertVoucher('80.00', 'Cash', 1, 0, '2026-10-22');

        $this->assertSame('160.0000', $this->expectation->expected(1, '2026-10-19 08:00:00', '2026-10-21 18:00:00', '100', '0'));
    }

    public function testExpected_CountsAVoucherOfTheDayOfTheCashUpWhenTheSettingsAskForTimes(): void
    {
        $settings = config(OSPOS::class)->settings;

        config(OSPOS::class)->settings['date_or_time_format'] = '1';

        try {
            $this->insertVoucher('25.00', 'Cash', 1, 0, '2026-10-20');

            $this->assertSame('125.0000', $this->expected());
        } finally {
            config(OSPOS::class)->settings = $settings;
        }
    }

    public function testExpected_CombinesSalesExpensesAndVouchers(): void
    {
        $this->sellFor('300.00');
        $this->insertExpense('45.00');
        $this->insertVoucher('20.00');

        $this->assertSame('375.0000', $this->expected(1, '100', '0'));
    }

    private ?int $purchasedItemId = null;
    private ?int $customerId = null;
    private ?int $supplierId = null;

    /** @var list<int> */
    private array $purchaseRowIds = [];

    private function removePurchaseRows(): void
    {
        $db = $this->accountingDb();

        foreach ($this->purchaseRowIds as $receivingId) {
            $db->table('receivings_payments')->where('receiving_id', $receivingId)->delete();
            $db->table('receivings_items')->where('receiving_id', $receivingId)->delete();
            $db->table('receivings')->where('receiving_id', $receivingId)->delete();
        }

        $db->query('SET FOREIGN_KEY_CHECKS = 0');

        foreach (['customer_advances', 'customer_credit_refunds', 'supplier_advances', 'supplier_credit_refunds'] as $table) {
            $db->table($table)->truncate();
        }

        $db->query('SET FOREIGN_KEY_CHECKS = 1');

        $this->purchaseRowIds = [];
    }

    private function customer(): int
    {
        return $this->customerId ??= $this->insertCustomer();
    }

    private function supplier(): int
    {
        return $this->supplierId ??= $this->insertSupplier();
    }

    private function insertPurchase(string $quantity, string $price, string $payment, string $tax = '0', string $time = '2026-10-20 10:00:00', string $type = 'receive', int $branchId = 1, ?string $currency = null, ?string $rate = null): int
    {
        $db = $this->accountingDb();

        $db->table('receivings')->insert([
            'receiving_time' => $time,
            'supplier_id'    => $this->supplier(),
            'employee_id'    => $this->clerkId,
            'comment'        => '',
            'payment_type'   => $payment,
            'receiving_type' => $type,
            'branch_id'      => $branchId,
            'currency_code'  => $currency,
            'currency_rate'  => $rate,
        ]);

        $receivingId = (int) $db->insertID();

        $db->table('receivings_items')->insert([
            'receiving_id'       => $receivingId,
            'item_id'            => $this->purchasedItemId ??= $this->createTestItem(),
            'line'               => 0,
            'description'        => '',
            'serialnumber'       => '',
            'quantity_purchased' => $type === 'return' ? '-' . $quantity : $quantity,
            'receiving_quantity' => '1',
            'item_cost_price'    => '1',
            'item_unit_price'    => $price,
            'discount'           => '0',
            'discount_type'      => PERCENT,
            'item_location'      => 1,
            'tax_percent'        => $tax,
        ]);

        return $this->purchaseRowIds[] = $receivingId;
    }

    private function insertSupplierPayment(int $receivingId, string $amount, string $type = 'Cash', string $date = '2026-10-20', string $status = 'completed', ?int $treasuryBranchId = null, ?string $currency = null, ?string $rate = null): void
    {
        $this->accountingDb()->table('receivings_payments')->insert([
            'receiving_id'       => $receivingId,
            'payment_type'       => $type,
            'payment_amount'     => $amount,
            'payment_time'       => '2026-10-25 09:00:00',
            'payment_date'       => $date,
            'employee_id'        => $this->clerkId,
            'payment_status'     => $status,
            'treasury_branch_id' => $treasuryBranchId,
            'currency_code'      => $currency,
            'currency_rate'      => $rate,
        ]);
    }

    /**
     * @param array<string, mixed> $more
     */
    private function insertCashDocument(string $table, string $dateColumn, string $amount, ?string $payment = 'Cash', int $branchId = 1, string $status = 'completed', string $date = '2026-10-20', array $more = []): void
    {
        $this->accountingDb()->table($table)->insert([
            $dateColumn    => $date,
            'branch_id'    => $branchId,
            'amount'       => $amount,
            'payment_type' => $payment,
            'status'       => $status,
        ] + $more);
    }

    public function testExpected_TakesOffTheCashPurchasesOfTheBranchWithTheirTax(): void
    {
        $this->insertPurchase('3', '100', 'Cash', '15');
        $this->insertPurchase('1', '20.50', 'Cash');

        $this->assertSame('634.5000', $this->expected(1, '1000'));
    }

    public function testExpected_LeavesOutAPurchaseThatWasNotPaidInCashAtOnce(): void
    {
        $this->insertPurchase('3', '100', 'Due');
        $this->insertPurchase('3', '100', 'Bank Transfer');

        $this->assertSame('1000.0000', $this->expected(1, '1000'));
    }

    public function testExpected_LeavesOutAPurchaseOfAnotherBranchOrOfAnotherDayAndCountsItInTheBranchItIsOf(): void
    {
        $second = $this->createBranch('B2');

        $this->insertPurchase('3', '100', 'Cash', '0', '2026-10-19 10:00:00');
        $this->insertPurchase('3', '100', 'Cash', '0', '2026-10-21 10:00:00');
        $this->insertPurchase('1', '40', 'Cash', '0', '2026-10-20 10:00:00', 'receive', $second);

        $this->assertSame('1000.0000', $this->expected(1, '1000'));
        $this->assertSame('960.0000', $this->expected($second, '1000'));
    }

    public function testExpected_LeavesOutATransferBetweenLocationsAndAPurchaseWithoutASupplier(): void
    {
        $this->insertPurchase('3', '100', 'Cash', '0', '2026-10-20 10:00:00', 'requisition');
        $withoutSupplier = $this->insertPurchase('3', '100', 'Cash');

        $this->accountingDb()->table('receivings')->where('receiving_id', $withoutSupplier)->update(['supplier_id' => null]);

        $this->assertSame('1000.0000', $this->expected(1, '1000'));
    }

    public function testExpected_AddsWhatASupplierPaidBackForAPurchaseReturnedForCash(): void
    {
        $this->insertPurchase('5', '100', 'Due');
        $this->insertPurchase('2', '100', 'Cash', '0', '2026-10-20 11:00:00', 'return');

        $this->assertSame('1200.0000', $this->expected(1, '1000'));
    }

    public function testExpected_CountsAForeignCashPurchaseInTheBaseAtItsRate(): void
    {
        $this->insertPurchase('1', '100', 'Cash', '0', '2026-10-20 10:00:00', 'receive', 1, 'USD', '3.75000000');

        $this->assertSame('625.0000', $this->expected(1, '1000'));
    }

    public function testExpected_CountsAPurchaseOfTheDayOfTheCashUpOnlyFromOpeningToClosingWhenTheSettingsAskForTimes(): void
    {
        $settings = config(OSPOS::class)->settings;

        config(OSPOS::class)->settings['date_or_time_format'] = '1';

        try {
            $this->insertPurchase('1', '10', 'Cash', '0', '2026-10-20 07:00:00');
            $this->insertPurchase('1', '20', 'Cash', '0', '2026-10-20 09:00:00');
            $this->insertPurchase('1', '40', 'Cash', '0', '2026-10-20 19:00:00');

            $this->assertSame('980.0000', $this->expected(1, '1000'));
        } finally {
            config(OSPOS::class)->settings = $settings;
        }
    }

    public function testExpected_TakesOffTheCashPaymentsToSuppliersAndAddsWhatASupplierGaveBack(): void
    {
        $receivingId = $this->insertPurchase('10', '100', 'Due');

        $this->insertSupplierPayment($receivingId, '300.00');
        $this->insertSupplierPayment($receivingId, '120.00', 'Bank Transfer');
        $this->insertSupplierPayment($receivingId, '-25.00');
        $this->insertSupplierPayment($receivingId, '60.00', 'Cash', '2026-10-20', 'incomplete');

        $this->assertSame('725.0000', $this->expected(1, '1000'));
    }

    public function testExpected_CountsASupplierPaymentOnTheDayItIsDatedAndInTheDrawerThatPaidIt(): void
    {
        $receivingId = $this->insertPurchase('10', '100', 'Due');
        $second      = $this->createBranch('B2');

        $this->insertSupplierPayment($receivingId, '30.00', 'Cash', '2026-10-19');
        $this->insertSupplierPayment($receivingId, '50.00', 'Cash', '2026-10-21');
        $this->insertSupplierPayment($receivingId, '70.00', 'Cash', '2026-10-20', 'completed', $second);

        $this->assertSame('1000.0000', $this->expected(1, '1000'), 'The money left the drawer of the second branch');
        $this->assertSame('930.0000', $this->expected($second, '1000'));
        $this->assertSame('920.0000', $this->expectation->expected(1, '2026-10-19 08:00:00', '2026-10-21 18:00:00', '1000', '0'));
    }

    public function testExpected_CountsAForeignSupplierPaymentAtTheRateOfItsOwnOrOfThePurchase(): void
    {
        $receivingId = $this->insertPurchase('10', '100', 'Due', '0', '2026-10-20 10:00:00', 'receive', 1, 'USD', '3.75000000');

        $this->insertSupplierPayment($receivingId, '10.00', 'Cash', '2026-10-20', 'completed', null, 'USD', '3.60000000');
        $this->insertSupplierPayment($receivingId, '20.00');

        $this->assertSame('889.0000', $this->expected(1, '1000'));
    }

    public function testExpected_TakesOffTheCashRefundedToCustomersAndPaidAheadToSuppliers(): void
    {
        $customer = $this->customer();
        $supplier = $this->supplier();

        $this->insertCashDocument('customer_credit_refunds', 'refund_date', '40.00', 'Cash', 1, 'completed', '2026-10-20', ['customer_id' => $customer]);
        $this->insertCashDocument('customer_credit_refunds', 'refund_date', '99.00', 'Cash', 1, 'cancelled', '2026-10-20', ['customer_id' => $customer]);
        $this->insertCashDocument('customer_credit_refunds', 'refund_date', '99.00', 'Bank Transfer', 1, 'completed', '2026-10-20', ['customer_id' => $customer]);
        $this->insertCashDocument('customer_credit_refunds', 'refund_date', '99.00', 'Cash', 1, 'completed', '2026-10-21', ['customer_id' => $customer]);
        $this->insertCashDocument('supplier_advances', 'advance_date', '15.00', 'Cash', 1, 'completed', '2026-10-20', ['supplier_id' => $supplier]);
        $this->insertCashDocument('supplier_advances', 'advance_date', '99.00', 'Cash', 1, 'cancelled', '2026-10-20', ['supplier_id' => $supplier]);
        $this->insertCashDocument('supplier_advances', 'advance_date', '99.00', null, 1, 'completed', '2026-10-20', ['supplier_id' => $supplier, 'kind' => 'opening']);

        $this->assertSame('45.0000', $this->expected(1, '100'));
    }

    public function testExpected_AddsTheCashCustomersPaidAheadAndSuppliersRefunded(): void
    {
        $customer = $this->customer();
        $supplier = $this->supplier();

        $this->insertCashDocument('customer_advances', 'advance_date', '60.00', 'Cash', 1, 'completed', '2026-10-20', ['customer_id' => $customer]);
        $this->insertCashDocument('customer_advances', 'advance_date', '99.00', 'Cash', 1, 'cancelled', '2026-10-20', ['customer_id' => $customer]);
        $this->insertCashDocument('supplier_credit_refunds', 'refund_date', '25.00', 'Cash', 1, 'completed', '2026-10-20', ['supplier_id' => $supplier]);
        $this->insertCashDocument('supplier_credit_refunds', 'refund_date', '99.00', 'Cash', $this->createBranch('B2'), 'completed', '2026-10-20', ['supplier_id' => $supplier]);

        $this->assertSame('185.0000', $this->expected(1, '100'));
    }

    public function testExpected_CombinesEveryCashThatCameInAndEveryCashThatWentOutOfTheDrawer(): void
    {
        $this->sellFor('400.00');
        $this->insertVoucher('20.00');
        $this->insertExpense('45.00');
        $this->insertPurchase('2', '100', 'Cash');
        $this->insertSupplierPayment($this->insertPurchase('3', '100', 'Due'), '150.00');
        $this->insertCashDocument('customer_credit_refunds', 'refund_date', '35.00', 'Cash', 1, 'completed', '2026-10-20', ['customer_id' => $this->customer()]);

        $this->assertSame('90.0000', $this->expected(1, '100'));
    }
}
