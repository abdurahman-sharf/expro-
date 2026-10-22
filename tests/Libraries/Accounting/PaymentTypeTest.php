<?php

namespace Tests\Libraries\Accounting;

use App\Libraries\Accounting\PaymentType;
use App\Libraries\Accounting\RoutingKey;
use CodeIgniter\Test\CIUnitTestCase;
use Config\Services;

class PaymentTypeTest extends CIUnitTestCase
{
    protected function tearDown(): void
    {
        Services::language()->setLocale('en');

        parent::tearDown();
    }

    public function testFromLabel_RecognisesTheNameOfEveryTypeInTheLanguageInUse(): void
    {
        foreach (PaymentType::cases() as $type) {
            $this->assertSame($type, PaymentType::fromLabel(lang($type->languageKey())), $type->value);
        }
    }

    public function testFromLabel_IgnoresTheCardNumberOfAGiftCardPayment(): void
    {
        $this->assertSame(PaymentType::Giftcard, PaymentType::fromLabel(lang('Sales.giftcard') . ':000123'));
    }

    public function testFromLabel_RecognisesEnglishNamesWhileAnotherLanguageIsInUse(): void
    {
        Services::language()->setLocale('ar-EG');

        $this->assertSame(PaymentType::Cash, PaymentType::fromLabel('Cash'));
        $this->assertSame(PaymentType::Check, PaymentType::fromLabel('Check'));
        $this->assertSame(PaymentType::Cash, PaymentType::fromLabel(lang('Sales.cash')));
    }

    public function testFromLabel_IgnoresTheCaseOfTheName(): void
    {
        $this->assertSame(PaymentType::Due, PaymentType::fromLabel(strtoupper(lang('Sales.due'))));
    }

    public function testFromLabel_RejectsANameNoTypeHas(): void
    {
        $this->assertNull(PaymentType::fromLabel('Barter'));
        $this->assertNull(PaymentType::fromLabel(''));
        $this->assertNull(PaymentType::fromLabel(':123'));
    }

    public function testFromRoutingKey_ReadsThePaymentKeysAndNothingElse(): void
    {
        $this->assertSame(PaymentType::Cash, PaymentType::fromRoutingKey('payment.cash'));
        $this->assertSame(PaymentType::BankTransfer, PaymentType::fromRoutingKey(PaymentType::BankTransfer->routingKey()));
        $this->assertNull(PaymentType::fromRoutingKey('payment.barter'));
        $this->assertNull(PaymentType::fromRoutingKey(RoutingKey::SalesRevenue->value));
    }

    public function testLockedRoutingKey_LocksOnlyCreditSalesAndGiftCards(): void
    {
        $locked = array_filter(PaymentType::cases(), static fn (PaymentType $type): bool => $type->isLocked());

        $this->assertSame([PaymentType::Due, PaymentType::Giftcard], array_values($locked));
        $this->assertSame(RoutingKey::Customers, PaymentType::Due->lockedRoutingKey());
        $this->assertSame(RoutingKey::GiftcardLiability, PaymentType::Giftcard->lockedRoutingKey());
    }

    public function testDefaultSystemKey_StartsEveryOtherTypeOnCashAndRewardsOnTheDiscount(): void
    {
        $this->assertNull(PaymentType::Due->defaultSystemKey());
        $this->assertNull(PaymentType::Giftcard->defaultSystemKey());
        $this->assertSame('sales_discount', PaymentType::Rewards->defaultSystemKey());

        foreach ([PaymentType::Cash, PaymentType::Debit, PaymentType::Credit, PaymentType::Check, PaymentType::BankTransfer, PaymentType::Upi, PaymentType::Wallet] as $type) {
            $this->assertSame('cash', $type->defaultSystemKey(), $type->value);
        }
    }

    public function testAllowedAccountTypes_GivesALockedTypeNoChoice(): void
    {
        $this->assertSame([], PaymentType::Due->allowedAccountTypes());
        $this->assertSame([], PaymentType::Giftcard->acceptedSystemKeys());
        $this->assertNotSame([], PaymentType::Cash->allowedAccountTypes());
    }
}
