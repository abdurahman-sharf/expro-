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

    public function testAllowedTypes_OffersShippingCancelledAndAutomaticWithRevenueAccountsOnly(): void
    {
        $this->assertSame([RoutingType::Cancelled, RoutingType::Auto], RoutingKey::SalesShipping->allowedTypes());
        $this->assertSame([AccountType::Revenue], RoutingKey::SalesShipping->allowedAccountTypes());
        $this->assertSame(RoutingType::Auto, RoutingKey::SalesShipping->defaultType());
        $this->assertSame(RoutingType::Auto, RoutingKey::SalesAdjustment->defaultType());
        $this->assertTrue(RoutingKey::SalesAdjustment->applies(RoutingType::Auto));
    }

    public function testDefaultType_CancelsTheDiscountRowsThePurchaseAdjustmentAndProductSales(): void
    {
        $cancelled = array_filter(RoutingKey::cases(), static fn (RoutingKey $key): bool => $key->defaultType() === RoutingType::Cancelled);

        $this->assertEqualsCanonicalizing(
            [RoutingKey::SalesDiscount, RoutingKey::ProductSales, RoutingKey::PurchaseDiscount, RoutingKey::PurchaseAdjustment],
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

        $this->assertEqualsCanonicalizing([RoutingKey::ProductSales, RoutingKey::PurchaseReturns, RoutingKey::InterBranchReceivable, RoutingKey::InterBranchPayable, RoutingKey::AssetCost, RoutingKey::AssetAccumulated, RoutingKey::AssetExpense, RoutingKey::AssetGain, RoutingKey::AssetLoss, RoutingKey::AssetPayable], array_values($optional));
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
        $this->assertSame(['sales_adjustment', 'sales_discount'], RoutingKey::SalesAdjustment->acceptedSystemKeys());
        $this->assertSame(['sales_shipping'], RoutingKey::SalesShipping->acceptedSystemKeys());
    }

    public function testAllowedAccountTypes_PutsInputTaxAmongTheLiabilitiesAndTheAssets(): void
    {
        $this->assertSame([AccountType::Asset, AccountType::Liability], RoutingKey::TaxInput->allowedAccountTypes());
        $this->assertSame([AccountType::Liability], RoutingKey::TaxOutput->allowedAccountTypes());
    }

    public function testApplies_RefusesAutomaticOnlyOnThePurchaseAdjustmentBecauseNothingFeedsIt(): void
    {
        foreach (RoutingKey::cases() as $key) {
            $settlement = $key === RoutingKey::PurchaseAdjustment;

            $this->assertSame(! $settlement, $key->applies(RoutingType::Auto), $key->value);
            $this->assertTrue($key->applies(RoutingType::Cancelled), $key->value);
        }
    }

    public function testPerItemKeys_AreTheRevenueAndTheReturnsRowsOnly(): void
    {
        $this->assertSame([RoutingKey::SalesRevenue, RoutingKey::SalesReturns], RoutingKey::perItemKeys());

        foreach (RoutingKey::cases() as $key) {
            $this->assertSame(in_array($key, [RoutingKey::SalesRevenue, RoutingKey::SalesReturns], true), $key->takesItemAccounts(), $key->value);
        }
    }

    public function testPerItemKeys_AreRowsTheReferenceSystemOffersTheTypeOnAndTakeRevenueAccounts(): void
    {
        foreach (RoutingKey::perItemKeys() as $key) {
            $this->assertContains(RoutingType::PerItem, $key->allowedTypes(), $key->value);
            $this->assertSame([AccountType::Revenue], $key->allowedAccountTypes(), $key->value);
        }
    }

    public function testPerItemKeys_LeaveOutTheStockRowAndTheCostOfSalesRowWhoseTiesAreKeptByLocation(): void
    {
        $this->assertFalse(RoutingKey::StockLocations->takesItemAccounts());
        $this->assertFalse(RoutingKey::CostOfGoodsSold->takesItemAccounts());
        $this->assertFalse(RoutingKey::StockLocations->applies(RoutingType::PerItem));
        $this->assertFalse(RoutingKey::CostOfGoodsSold->applies(RoutingType::PerItem));
        $this->assertNotContains(RoutingType::PerItem, RoutingKey::CostOfGoodsSold->allowedTypes());
    }

    public function testApplies_AppliesPerItemOnTheRowsThatTakeItemAccountsOnly(): void
    {
        foreach (RoutingKey::cases() as $key) {
            $this->assertSame($key->takesItemAccounts(), $key->applies(RoutingType::PerItem), $key->value);
        }
    }

    public function testApplies_AppliesMainPerEntityOnTheEntityRowsOnly(): void
    {
        foreach (RoutingKey::cases() as $key) {
            $this->assertSame($key->isEntity(), $key->applies(RoutingType::MainPerEntity), $key->value);
        }

        $offered = array_filter(RoutingKey::cases(), static fn (RoutingKey $key): bool => in_array(RoutingType::MainPerEntity, $key->allowedTypes(), true));

        $this->assertSame([RoutingKey::Customers], array_values($offered));
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

    public function testAssetKeys_AreOfTheTypesOfTheAccountsOfFixedAssetsAndStartOnTheirOwnAccounts(): void
    {
        $this->assertSame([AccountType::Asset], RoutingKey::AssetCost->allowedAccountTypes());
        $this->assertSame([AccountType::Asset], RoutingKey::AssetAccumulated->allowedAccountTypes());
        $this->assertSame([AccountType::Expense], RoutingKey::AssetExpense->allowedAccountTypes());
        $this->assertSame([AccountType::Revenue, AccountType::Expense], RoutingKey::AssetGain->allowedAccountTypes());
        $this->assertSame([AccountType::Revenue, AccountType::Expense], RoutingKey::AssetLoss->allowedAccountTypes());
        $this->assertSame([AccountType::Liability], RoutingKey::AssetPayable->allowedAccountTypes());
        $this->assertSame(['fixed_assets', 'accumulated_depreciation', 'depreciation_expense', 'asset_gain', 'asset_loss', 'other_payables'], array_map(static fn (RoutingKey $key): ?string => $key->defaultSystemKey(), [RoutingKey::AssetCost, RoutingKey::AssetAccumulated, RoutingKey::AssetExpense, RoutingKey::AssetGain, RoutingKey::AssetLoss, RoutingKey::AssetPayable]));
        $this->assertSame(['assets.cost', 'assets.accumulated', 'assets.expense', 'assets.gain', 'assets.loss', 'assets.payable'], array_map(static fn (RoutingKey $key): string => $key->value, [RoutingKey::AssetCost, RoutingKey::AssetAccumulated, RoutingKey::AssetExpense, RoutingKey::AssetGain, RoutingKey::AssetLoss, RoutingKey::AssetPayable]));
    }

    public function testAssetKeys_AreEditableInOneGroupAndOnlyAutomatic(): void
    {
        foreach ([RoutingKey::AssetCost, RoutingKey::AssetAccumulated, RoutingKey::AssetExpense, RoutingKey::AssetGain, RoutingKey::AssetLoss, RoutingKey::AssetPayable] as $key) {
            $this->assertContains($key, RoutingKey::editable(), $key->value);
            $this->assertSame('other', $key->group(), $key->value);
            $this->assertSame([RoutingType::Auto], $key->allowedTypes(), $key->value);
            $this->assertFalse($key->isEntity(), $key->value);
        }
    }
}
