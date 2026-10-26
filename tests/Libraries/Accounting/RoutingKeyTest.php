<?php

namespace Tests\Libraries\Accounting;

use App\Libraries\Accounting\AccountType;
use App\Libraries\Accounting\RoutingKey;
use App\Libraries\Accounting\RoutingType;
use CodeIgniter\Test\CIUnitTestCase;
use PHPUnit\Framework\Attributes\DataProvider;

class RoutingKeyTest extends CIUnitTestCase
{
    /**
     * The types each row of the reference system offers, by the numbers it shows, in its order.
     * Read from the open dropdowns of a fresh account (evidence ev_8c1f06a3a7b07e8b).
     *
     * @return array<string, array{RoutingKey, list<int>}>
     */
    public static function observedTypesProvider(): array
    {
        return [
            'customers'            => [RoutingKey::Customers, [1, 2, 4]],
            'sales'                => [RoutingKey::SalesRevenue, [1, 2, 3]],
            'sales returns'        => [RoutingKey::SalesReturns, [1, 2, 3]],
            'discount allowed'     => [RoutingKey::SalesDiscount, [3, 1]],
            'product sales'        => [RoutingKey::ProductSales, [3, 2]],
            'sales settlement'     => [RoutingKey::SalesAdjustment, [3, 1, 2]],
            'cost of sales'        => [RoutingKey::CostOfGoodsSold, [3, 1]],
            'suppliers'            => [RoutingKey::Suppliers, [1, 2]],
            'purchases'            => [RoutingKey::Purchases, [1, 2, 3]],
            'purchase returns'     => [RoutingKey::PurchaseReturns, [1, 2, 3]],
            'discount received'    => [RoutingKey::PurchaseDiscount, [3, 1]],
            'purchase settlement'  => [RoutingKey::PurchaseAdjustment, [3, 1, 2]],
            'stock locations'      => [RoutingKey::StockLocations, [1, 2]],
            'treasuries'           => [RoutingKey::Treasuries, [1, 2]],
            'retained earnings'    => [RoutingKey::RetainedEarnings, [1]],
        ];
    }

    /**
     * @param list<int> $numbers
     */
    #[DataProvider('observedTypesProvider')]
    public function testAllowedTypes_OffersTheTypesTheReferenceSystemOffers(RoutingKey $key, array $numbers): void
    {
        $this->assertSame($numbers, array_map(static fn (RoutingType $type): int => $type->number(), $key->allowedTypes()));
    }

    public function testDefaultType_CancelsTheDiscountAndSettlementRowsAndProductSales(): void
    {
        $cancelled = array_filter(RoutingKey::cases(), static fn (RoutingKey $key): bool => $key->defaultType() === RoutingType::Cancelled);

        $this->assertEqualsCanonicalizing(
            [RoutingKey::SalesDiscount, RoutingKey::ProductSales, RoutingKey::SalesAdjustment, RoutingKey::PurchaseDiscount, RoutingKey::PurchaseAdjustment],
            array_values($cancelled),
        );
    }

    public function testDefaultType_IsAlwaysOneOfTheAllowedTypes(): void
    {
        foreach (RoutingKey::cases() as $key) {
            $this->assertContains($key->defaultType(), $key->allowedTypes(), $key->value);
        }
    }

    public function testEditable_LeavesOutTheAccountsTheChartKeepsForLaterPhases(): void
    {
        $editable = RoutingKey::editable();

        foreach ([RoutingKey::CashDefault, RoutingKey::TaxInput, RoutingKey::ExpensesDefault, RoutingKey::OpeningBalance] as $key) {
            $this->assertNotContains($key, $editable, $key->value);
        }

        $this->assertContains(RoutingKey::GiftcardLiability, $editable);
        $this->assertContains(RoutingKey::TaxOutput, $editable);
    }

    public function testInGroup_CoversEveryEditableKeyOnce(): void
    {
        $seen = [];

        foreach (RoutingKey::GROUPS as $group) {
            foreach (RoutingKey::inGroup($group) as $key) {
                $this->assertSame($group, $key->group());
                $seen[] = $key;
            }
        }

        $this->assertEqualsCanonicalizing(RoutingKey::editable(), $seen);
    }

    public function testIsEntity_MarksTheMainAccountsOfCustomersSuppliersLocationsTreasuriesAndBanks(): void
    {
        $entities = array_filter(RoutingKey::cases(), static fn (RoutingKey $key): bool => $key->isEntity());

        $this->assertEqualsCanonicalizing(
            [RoutingKey::Customers, RoutingKey::Suppliers, RoutingKey::StockLocations, RoutingKey::Treasuries, RoutingKey::Banks],
            array_values($entities),
        );
        $this->assertSame('customer', RoutingKey::Customers->entityKind());
        $this->assertNull(RoutingKey::SalesRevenue->entityKind());
    }

    public function testCancelMeansNone_AppliesToProductSalesAndCostOfSalesOnly(): void
    {
        $this->assertTrue(RoutingKey::ProductSales->cancelMeansNone());
        $this->assertTrue(RoutingKey::CostOfGoodsSold->cancelMeansNone());
        $this->assertFalse(RoutingKey::SalesDiscount->cancelMeansNone());
    }

    public function testAccountRequired_LetsProductSalesPurchaseReturnsAndTheTwoRowsBetweenBranchesStayEmpty(): void
    {
        $optional = array_filter(RoutingKey::cases(), static fn (RoutingKey $key): bool => ! $key->accountRequired());

        $this->assertEqualsCanonicalizing([RoutingKey::ProductSales, RoutingKey::PurchaseReturns, RoutingKey::InterBranchReceivable, RoutingKey::InterBranchPayable], array_values($optional));
    }

    public function testDefaultSystemKey_PointsEveryRequiredRowAtAnAccount(): void
    {
        foreach (RoutingKey::cases() as $key) {
            if ($key->accountRequired()) {
                $this->assertNotNull($key->defaultSystemKey(), $key->value);
            }
        }
    }

    public function testAcceptedSystemKeys_GiveTheEmptyRowsTheirClosestAccount(): void
    {
        $this->assertSame(['sales_revenue'], RoutingKey::ProductSales->acceptedSystemKeys());
        $this->assertSame(['purchases'], RoutingKey::PurchaseReturns->acceptedSystemKeys());
        $this->assertSame(['sales_discount'], RoutingKey::SalesAdjustment->acceptedSystemKeys());
    }

    public function testAllowedAccountTypes_PutsInputTaxAmongTheLiabilitiesAndTheAssets(): void
    {
        $this->assertSame([AccountType::Asset, AccountType::Liability], RoutingKey::TaxInput->allowedAccountTypes());
        $this->assertSame([AccountType::Liability], RoutingKey::TaxOutput->allowedAccountTypes());
    }

    public function testApplies_RefusesAutomaticOnlyOnTheSettlementRowsBecauseNothingFeedsThem(): void
    {
        foreach (RoutingKey::cases() as $key) {
            foreach (RoutingType::cases() as $type) {
                $settlement = in_array($key, [RoutingKey::SalesAdjustment, RoutingKey::PurchaseAdjustment], true) && $type === RoutingType::Auto;

                $this->assertSame(! $settlement && $type->isImplemented(), $key->applies($type), "{$key->value} {$type->value}");
            }
        }
    }

    public function testCounterCustomers_IsAnAssetAccountOfItsOwnWithTheOnlyTypeThatPostsIt(): void
    {
        $this->assertSame('counter_customers', RoutingKey::CounterCustomers->defaultSystemKey());
        $this->assertSame([AccountType::Asset], RoutingKey::CounterCustomers->allowedAccountTypes());
        $this->assertSame([RoutingType::Auto], RoutingKey::CounterCustomers->allowedTypes());
        $this->assertFalse(RoutingKey::CounterCustomers->isEntity());
        $this->assertContains(RoutingKey::CounterCustomers, RoutingKey::inGroup('other'));
    }

    public function testInterBranchRows_AreAnAssetAndALiabilityAccountOfTheTreasuriesFamilyWithTheOnlyTypeThatPostsThem(): void
    {
        $this->assertSame([AccountType::Asset], RoutingKey::InterBranchReceivable->allowedAccountTypes());
        $this->assertSame([AccountType::Liability], RoutingKey::InterBranchPayable->allowedAccountTypes());

        foreach ([RoutingKey::InterBranchReceivable, RoutingKey::InterBranchPayable] as $key) {
            $this->assertSame([RoutingType::Auto], $key->allowedTypes());
            $this->assertFalse($key->isEntity());
            $this->assertContains($key, RoutingKey::inGroup('treasuries'));
            $this->assertContains($key, RoutingKey::editable());
        }

        $this->assertSame('branches_receivable', RoutingKey::InterBranchReceivable->defaultSystemKey());
        $this->assertSame('branches_payable', RoutingKey::InterBranchPayable->defaultSystemKey());
    }
}
