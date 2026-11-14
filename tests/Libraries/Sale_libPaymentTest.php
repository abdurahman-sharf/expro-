<?php

namespace Tests\Libraries;

use App\Libraries\Sale_lib;
use App\Models\Attribute;
use App\Models\Customer;
use App\Models\Dinner_table;
use App\Models\Item;
use App\Models\Item_kit_items;
use App\Models\Item_quantity;
use App\Models\Item_taxes;
use App\Models\Sale;
use App\Models\Stock_location;
use CodeIgniter\Config\Factories;
use CodeIgniter\Test\CIUnitTestCase;
use Config\OSPOS;

class Sale_libPaymentTest extends CIUnitTestCase
{
    private Sale_lib $saleLib;

    protected int $priorBcscale;

    protected function setUp(): void
    {
        parent::setUp();

        // The app sets a global bcscale() from app config on config load
        // (see app/Events/Load_config.php), and whichever value a prior test
        // class triggered leaks into bcadd/bcmul here. Pin a known scale so the
        // accumulation math in these tests is deterministic regardless of suite
        // order; restore it in tearDown.
        $this->priorBcscale = bcscale();
        bcscale(0);

        // Inject mock OSPOS config so Sale_lib constructor and helpers don't need real settings
        $ospos           = new OSPOS();
        $ospos->settings = [
            'cash_rounding_code' => '',
            'cash_decimals'      => 2,
            'currency_decimals'  => 2,
            'tax_decimals'       => 2,
            'quantity_decimals'  => 2,
        ];
        Factories::injectMock('config', OSPOS::class, $ospos);

        // Inject stub models so model() calls in Sale_lib::__construct() skip DB
        $stubMethods = ['__construct'];
        foreach ([
            Attribute::class,
            Customer::class,
            Dinner_table::class,
            Item::class,
            Item_kit_items::class,
            Item_quantity::class,
            Item_taxes::class,
            Sale::class,
            Stock_location::class,
        ] as $modelClass) {
            $mock = $this->getMockBuilder($modelClass)
                ->disableOriginalConstructor()
                ->getMock();
            Factories::injectMock('models', $modelClass, $mock);
        }

        session()->destroy();
        $this->saleLib = new Sale_lib();
    }

    protected function tearDown(): void
    {
        bcscale($this->priorBcscale);
        Factories::reset();
        parent::tearDown();
    }

    // ========== getPayments / setPayments ==========

    public function testGetPaymentsReturnsEmptyArrayInitially(): void
    {
        $payments = $this->saleLib->getPayments();
        $this->assertIsArray($payments);
        $this->assertEmpty($payments);
    }

    public function testSetPaymentsPersistsToSession(): void
    {
        $data = [
            'cash' => [
                'payment_type'    => 'cash',
                'payment_amount'  => '10.00',
                'cash_refund'     => 0,
                'cash_adjustment' => CASH_ADJUSTMENT_FALSE,
                'reference_code'  => null,
            ]
        ];
        $this->saleLib->setPayments($data);
        $this->assertSame($data, $this->saleLib->getPayments());
    }

    // ========== addPayment ==========

    public function testAddPaymentCreatesNewEntry(): void
    {
        $this->saleLib->addPayment('credit', '25.00', 'ABC123');

        $payments = $this->saleLib->getPayments();
        $this->assertArrayHasKey('credit', $payments);
        $this->assertSame('credit', $payments['credit']['payment_type']);
        $this->assertSame('25.00', $payments['credit']['payment_amount']);
        $this->assertSame(0, $payments['credit']['cash_refund']);
        $this->assertSame(CASH_ADJUSTMENT_FALSE, $payments['credit']['cash_adjustment']);
    }

    public function testAddPaymentStoresReferenceCode(): void
    {
        $this->saleLib->addPayment('debit', '50.00', 'REF9876');

        $payments = $this->saleLib->getPayments();
        $this->assertSame('REF9876', $payments['debit']['reference_code']);
    }

    public function testAddPaymentNullReferenceCodeStoredAsNull(): void
    {
        $this->saleLib->addPayment('cash', '15.00');

        $payments = $this->saleLib->getPayments();
        $this->assertNull($payments['cash']['reference_code']);
    }

    public function testAddPaymentAccumulatesAmountForExistingId(): void
    {
        $this->saleLib->addPayment('credit', '10.00', 'REF001');
        $this->saleLib->addPayment('credit', '5.00', 'REF001');

        $payments = $this->saleLib->getPayments();
        // bcadd strips trailing zeros: '10.00' + '5.00' = '15'
        $this->assertSame('15', $payments['credit']['payment_amount']);
    }

    public function testAddPaymentCashAdjustmentFlagStored(): void
    {
        $this->saleLib->addPayment('cash_adjustment', '0.05', null, CASH_ADJUSTMENT_TRUE);

        $payments = $this->saleLib->getPayments();
        $this->assertSame(CASH_ADJUSTMENT_TRUE, $payments['cash_adjustment']['cash_adjustment']);
    }

    public function testAddPaymentMultipleDistinctTypesAllStored(): void
    {
        $this->saleLib->addPayment('credit', '30.00', 'REF1');
        $this->saleLib->addPayment('debit', '20.00', 'REF2');

        $payments = $this->saleLib->getPayments();
        $this->assertCount(2, $payments);
        $this->assertArrayHasKey('credit', $payments);
        $this->assertArrayHasKey('debit', $payments);
    }

    // ========== edit_payment ==========

    public function testEditPaymentUpdatesAmount(): void
    {
        $this->saleLib->addPayment('credit', '10.00', 'REF001');
        $result = $this->saleLib->edit_payment('credit', 99.99);

        $this->assertTrue($result);
        $payments = $this->saleLib->getPayments();
        $this->assertSame(99.99, $payments['credit']['payment_amount']);
    }

    public function testEditPaymentReturnsFalseForMissingId(): void
    {
        $result = $this->saleLib->edit_payment('nonexistent', 10.00);
        $this->assertFalse($result);
    }

    // ========== delete_payment ==========

    public function testDeletePaymentRemovesEntry(): void
    {
        $this->saleLib->addPayment('credit', '25.00', 'REF999');
        $this->saleLib->delete_payment('credit');

        $payments = $this->saleLib->getPayments();
        $this->assertArrayNotHasKey('credit', $payments);
    }

    public function testDeletePaymentLeavesOtherEntriesIntact(): void
    {
        $this->saleLib->addPayment('credit', '25.00', 'REF1');
        $this->saleLib->addPayment('debit', '10.00', 'REF2');
        $this->saleLib->delete_payment('credit');

        $payments = $this->saleLib->getPayments();
        $this->assertArrayNotHasKey('credit', $payments);
        $this->assertArrayHasKey('debit', $payments);
    }

    // ========== the name of a payment type is stored in the language of the moment ==========

    public function testCashPaymentKey_FindsTheCashPaymentWhateverLanguageItsNameWasStoredIn(): void
    {
        $this->saleLib->addPayment('نقدى', '50.00');
        $this->saleLib->addPayment('Debit Card', '20.00', 'REF1');

        $this->assertSame('نقدى', $this->saleLib->cashPaymentKey($this->saleLib->getPayments()));
    }

    public function testCashPaymentKey_IgnoresACashAdjustmentAndAPaymentThatIsNotCash(): void
    {
        $this->saleLib->addPayment('تعديل الصندوق', '0.05', null, CASH_ADJUSTMENT_TRUE);
        $this->saleLib->addPayment('بطاقة خصم', '20.00', 'REF1');

        $this->assertNull($this->saleLib->cashPaymentKey($this->saleLib->getPayments()));
    }

    public function testIsCashPayment_RecognisesCashAndItsAdjustmentInEveryLanguage(): void
    {
        $this->assertTrue($this->saleLib->isCashPayment('Cash'));
        $this->assertTrue($this->saleLib->isCashPayment('نقدى'));
        $this->assertTrue($this->saleLib->isCashPayment('Cash Adjustment'));
        $this->assertTrue($this->saleLib->isCashPayment('تعديل الصندوق'));
        $this->assertTrue($this->saleLib->isCashPayment('anything', true));
        $this->assertFalse($this->saleLib->isCashPayment('بطاقة هدية:123'));
        $this->assertFalse($this->saleLib->isCashPayment('Check'));
    }

    public function testDeletePayment_TakesTheCashAdjustmentWithTheCashPaymentWhateverLanguageTheCashWasStoredIn(): void
    {
        config(OSPOS::class)->settings['cash_decimals'] = 0;
        $this->saleLib->addPayment('نقدى', '50.00');
        $this->saleLib->addPayment('Cash Adjustment', '0.40', null, CASH_ADJUSTMENT_TRUE);
        $this->saleLib->addPayment('Debit Card', '20.00', 'REF1');

        $this->saleLib->delete_payment('نقدى');

        $this->assertSame(['Debit Card'], array_keys($this->saleLib->getPayments()));
    }

    public function testDeletePayment_TakesTheCashPaymentWithItsAdjustmentWhateverLanguageTheAdjustmentWasStoredIn(): void
    {
        config(OSPOS::class)->settings['cash_decimals'] = 0;
        $this->saleLib->addPayment('Cash', '50.00');
        $this->saleLib->addPayment('تعديل الصندوق', '0.40', null, CASH_ADJUSTMENT_TRUE);
        $this->saleLib->addPayment('Debit Card', '20.00', 'REF1');

        $this->saleLib->delete_payment('تعديل الصندوق');

        $this->assertSame(['Debit Card'], array_keys($this->saleLib->getPayments()));
    }

    // ========== the request key and the token of the sale ==========

    public function testClaimRequestKey_AcceptsAKeyOnceAndRejectsItWhenItIsSubmittedAgain(): void
    {
        $key = str_repeat('a', 32);

        $this->assertTrue($this->saleLib->claimRequestKey($key));
        $this->assertFalse($this->saleLib->claimRequestKey($key));
        $this->assertTrue($this->saleLib->claimRequestKey(str_repeat('b', 32)));
    }

    public function testClaimRequestKey_AcceptsAFormThatCarriesNoWellFormedKey(): void
    {
        $this->assertTrue($this->saleLib->claimRequestKey(null));
        $this->assertTrue($this->saleLib->claimRequestKey(''));
        $this->assertTrue($this->saleLib->claimRequestKey('not a key'));
        $this->assertTrue($this->saleLib->claimRequestKey('not a key'));
    }

    public function testClaimRequestKey_StillRejectsAKeyAfterTheSaleWasCleared(): void
    {
        $key = str_repeat('c', 32);
        $this->saleLib->claimRequestKey($key);

        $this->saleLib->clear_all();

        $this->assertFalse($this->saleLib->claimRequestKey($key));
    }

    public function testIsRegisterToken_AcceptsTheTokenOfTheSaleAndRejectsTheOneOfASaleThatEnded(): void
    {
        $token = $this->saleLib->registerToken();

        $this->assertTrue($this->saleLib->isRegisterToken($token));
        $this->assertSame($token, $this->saleLib->registerToken());

        $this->saleLib->clear_all();

        $this->assertFalse($this->saleLib->isRegisterToken($token));
        $this->assertNotSame($token, $this->saleLib->registerToken());
    }

    public function testKeepsTypedValue_RejectsAValueTypedBeforeTheOneKept(): void
    {
        $this->assertTrue($this->saleLib->keepsTypedValue('comment', '200'));
        $this->assertFalse($this->saleLib->keepsTypedValue('comment', '100'));
        $this->assertTrue($this->saleLib->keepsTypedValue('comment', '200'));
        $this->assertTrue($this->saleLib->keepsTypedValue('comment', '300'));
        $this->assertTrue($this->saleLib->keepsTypedValue('invoice_number', '100'));
        $this->assertTrue($this->saleLib->keepsTypedValue('comment', null));
    }
}
