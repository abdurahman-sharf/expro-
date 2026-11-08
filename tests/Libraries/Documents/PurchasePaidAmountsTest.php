<?php

namespace Tests\Libraries\Documents;

use App\Libraries\Documents\PurchasePaidAmounts;
use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\DatabaseTestTrait;
use Config\Database;
use Tests\Support\AccountingFixtureTrait;
use Tests\Support\EmployeeFixtureTrait;
use Tests\Support\ItemFixtureTrait;
use Tests\Support\PurchaseAccountingFixtureTrait;
use Tests\Support\SaleAccountingFixtureTrait;

/**
 * What a purchase invoice says was paid and is still due: the figures of the account of the supplier
 * while the books hold the purchase, and what the purchase itself says when they do not.
 */
class PurchasePaidAmountsTest extends CIUnitTestCase
{
    use DatabaseTestTrait;
    use AccountingFixtureTrait;
    use EmployeeFixtureTrait;
    use ItemFixtureTrait;
    use PurchaseAccountingFixtureTrait;
    use SaleAccountingFixtureTrait;

    protected $migrate     = true;
    protected $migrateOnce = true;
    protected $refresh     = false;
    protected $namespace   = null;

    private PurchasePaidAmounts $amounts;

    public static function setUpBeforeClass(): void
    {
        $seeder = Database::seeder('tests');
        $seeder->call('TestDatabaseBootstrapSeeder');
    }

    protected function setUp(): void
    {
        parent::setUp();

        $this->startBuying();

        $this->amounts = new PurchasePaidAmounts();
    }

    protected function tearDown(): void
    {
        $this->stopBuying();

        parent::tearDown();
    }

    /**
     * @return array{paid: string, due: string}
     */
    private function read(int $receivingId, string $total, bool $foreign = false, ?int $supplierId = null, ?string $paymentType = null): array
    {
        $row = $this->accountingDb()->table('receivings')->where('receiving_id', $receivingId)->get()->getRow();

        return $this->amounts->read($receivingId, $supplierId ?? ($row->supplier_id === null ? null : (int) $row->supplier_id), $paymentType ?? $row->payment_type, $foreign, $total);
    }

    public function testRead_CountsAPurchasePaidWhenItWasSavedAsPaidInFull(): void
    {
        $receiving = $this->purchaseOf('500.00', $this->supplierId, lang('Sales.cash'));

        $figures = $this->read($receiving, '500.00');

        $this->assertSame('500.0000', $figures['paid']);
        $this->assertSame('0.0000', $figures['due']);
        $this->assertSame('0.0000', $this->supplierPosition()->open());
    }

    public function testRead_LeavesAPurchaseSavedOnCreditDueUntilItIsPaid(): void
    {
        $receiving = $this->purchaseOf('500.00', $this->supplierId);

        $this->assertSame(['paid' => '0.0000', 'due' => '500.0000'], $this->read($receiving, '500.00'));

        $this->payPurchase($receiving, '200.00');

        $this->assertSame(['paid' => '200.0000', 'due' => '300.0000'], $this->read($receiving, '500.00'));
    }

    public function testRead_IgnoresAPaymentThatIsNotCompleted(): void
    {
        $receiving = $this->purchaseOf('500.00', $this->supplierId);
        $this->payPurchase($receiving, '200.00');
        $this->payPurchase($receiving, '150.00', null, 'draft');

        $this->assertSame(['paid' => '200.0000', 'due' => '300.0000'], $this->read($receiving, '500.00'));
    }

    public function testRead_CountsTheCreditAppliedToAPurchaseAsSettledAsTheAccountOfTheSupplierDoes(): void
    {
        $first = $this->purchaseOf('115.00', $this->supplierId, lang('Sales.cash'));
        $this->debitNoteOf($first, '115.00');
        $second = $this->purchaseOf('115.00', $this->supplierId);

        $figures = $this->read($second, '115.00');
        $open    = $this->supplierPosition()->purchase($second);

        $this->assertSame($open->settled(), $figures['paid']);
        $this->assertSame($open->open(), $figures['due']);
        $this->assertSame(['paid' => '115.0000', 'due' => '0.0000'], $figures);
    }

    public function testRead_ReadsTheFiguresOfTheAccountAndNotTheHeaderWhileTheBooksHoldThePurchase(): void
    {
        $receiving = $this->purchaseOf('500.00', $this->supplierId, lang('Sales.cash'));
        $this->accountingDb()->table('receivings')->where('receiving_id', $receiving)->update(['payment_type' => lang('Sales.due')]);

        $this->assertSame(['paid' => '500.0000', 'due' => '0.0000'], $this->read($receiving, '500.00'));
    }

    public function testRead_ReadsThePurchaseItselfWhenTheBooksAreOff(): void
    {
        $paid = $this->purchaseOf('500.00', $this->supplierId, lang('Sales.cash'));
        $due  = $this->purchaseOf('300.00', $this->supplierId);
        $this->setConfigValue('accounting_enabled', '0');

        try {
            $this->assertSame(['paid' => '500.0000', 'due' => '0.0000'], $this->read($paid, '500.00'));
            $this->assertSame(['paid' => '0.0000', 'due' => '300.0000'], $this->read($due, '300.00'));
        } finally {
            $this->setConfigValue('accounting_enabled', '1');
        }
    }

    public function testRead_ReadsThePurchaseItselfAndNotTheOldFiguresOfTheAccountWhenTheBooksAreOff(): void
    {
        $receiving = $this->purchaseOf('500.00', $this->supplierId, lang('Sales.cash'));
        $this->accountingDb()->table('receivings')->where('receiving_id', $receiving)->update(['payment_type' => lang('Sales.due')]);

        $this->assertSame(['paid' => '500.0000', 'due' => '0.0000'], $this->read($receiving, '500.00'), 'With the books on, the account says it was paid.');

        $this->setConfigValue('accounting_enabled', '0');

        try {
            $this->assertSame(['paid' => '0.0000', 'due' => '500.0000'], $this->read($receiving, '500.00'), 'With the books off, only the purchase itself is read.');
        } finally {
            $this->setConfigValue('accounting_enabled', '1');
        }
    }

    public function testRead_IgnoresAPaymentThatIsNotCompletedWhenItReadsThePurchaseItself(): void
    {
        $receiving = $this->purchaseOf('500.00', $this->supplierId);
        $this->payPurchase($receiving, '200.00');
        $this->payPurchase($receiving, '150.00', null, 'draft');

        $this->assertSame(['paid' => '200.0000', 'due' => '300.0000'], $this->read($receiving, '500.00', true));
    }

    public function testRead_ReadsThePurchaseItselfWhenItHasNoSupplier(): void
    {
        $receiving = $this->buy([$this->purchaseLine($this->item('0.00'), 0, '1', '80.00')], null, lang('Sales.cash'));

        $this->assertGreaterThan(0, $receiving);
        $this->assertSame(['paid' => '80.0000', 'due' => '0.0000'], $this->read($receiving, '80.00', false, null));
    }

    public function testRead_ReadsThePurchaseItselfWhenItIsInAForeignCurrencyWhoseBooksAreInTheBaseCurrency(): void
    {
        $partly = $this->purchaseOf('500.00', $this->supplierId);
        $this->payPurchase($partly, '125.00');
        $cash = $this->purchaseOf('500.00', $this->supplierId);

        $this->assertSame(['paid' => '125.0000', 'due' => '375.0000'], $this->read($partly, '500.00', true));
        $this->assertSame(['paid' => '500.0000', 'due' => '0.0000'], $this->read($cash, '500.00', true, null, lang('Sales.cash')));
    }

    public function testRead_NeverShowsAnAmountDueBelowZeroForAnOverpaidPurchase(): void
    {
        $receiving = $this->purchaseOf('100.00', $this->supplierId);
        $this->payPurchase($receiving, '130.00');
        $this->setConfigValue('accounting_enabled', '0');

        try {
            $this->assertSame(['paid' => '130.0000', 'due' => '0.0000'], $this->read($receiving, '100.00'));
        } finally {
            $this->setConfigValue('accounting_enabled', '1');
        }
    }

    public function testRead_TreatsAPaymentTypeThatMovesNoMoneyAsNotPaid(): void
    {
        $receiving = $this->purchaseOf('100.00', $this->supplierId);

        $this->assertSame(['paid' => '0.0000', 'due' => '100.0000'], $this->read($receiving, '100.00', true, null, lang('Sales.giftcard')));
        $this->assertSame(['paid' => '0.0000', 'due' => '100.0000'], $this->read($receiving, '100.00', true, null, 'something nobody knows'));
    }
}
