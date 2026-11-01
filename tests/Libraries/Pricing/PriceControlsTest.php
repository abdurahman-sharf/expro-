<?php

namespace Tests\Libraries\Pricing;

use App\Libraries\Accounting\AccountingError;
use App\Libraries\Accounting\AccountingGuard;
use App\Libraries\Pricing\PriceControls;
use App\Libraries\Pricing\PriceRule;
use App\Libraries\Pricing\SalesControlSettings;
use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\DatabaseTestTrait;
use Config\Database;
use Tests\Support\AccountingFixtureTrait;
use Tests\Support\EmployeeFixtureTrait;
use Tests\Support\ItemFixtureTrait;

class PriceControlsTest extends CIUnitTestCase
{
    use DatabaseTestTrait;
    use AccountingFixtureTrait;
    use EmployeeFixtureTrait;
    use ItemFixtureTrait;

    protected $migrate     = true;
    protected $migrateOnce = true;
    protected $refresh     = false;
    protected $namespace   = null;

    private PriceControls $controls;

    public static function setUpBeforeClass(): void
    {
        $seeder = Database::seeder('tests');
        $seeder->call('TestDatabaseBootstrapSeeder');
    }

    protected function setUp(): void
    {
        parent::setUp();

        $this->controls = new PriceControls();
    }

    protected function tearDown(): void
    {
        foreach ([SalesControlSettings::MINIMUM_PRICE, SalesControlSettings::MAX_DISCOUNT_ON] as $key) {
            $this->setConfigValue($key, '0');
        }

        $this->setConfigValue(SalesControlSettings::MAX_DISCOUNT, '');
        $this->setConfigValue(SalesControlSettings::EXEMPT_CATEGORIES, '[]');
        $this->accountingDb()->table('items')->update(['minimum_price' => 0, 'max_discount_percent' => null]);
        $this->accountingDb()->table('accounting_audit_log')->whereIn('action', [PriceControls::AUDIT_OVERRIDE, PriceControls::AUDIT_REFUSED])->delete();

        parent::tearDown();
    }

    private function item(string $minimum = '0', ?string $ceiling = null, string $category = 'Test Category'): int
    {
        $itemId = $this->createTestItem();

        $this->accountingDb()->table('items')->where('item_id', $itemId)->update(['minimum_price' => $minimum, 'max_discount_percent' => $ceiling, 'category' => $category]);

        return $itemId;
    }

    /**
     * @return array<string, mixed>
     */
    private function line(int $itemId, string $price, string $discount = '0', int $type = PERCENT, string $quantity = '1', ?string $share = null, int $number = 0): array
    {
        return ['item_id' => $itemId, 'line' => $number, 'quantity' => $quantity, 'price' => $price, 'discount' => $discount, 'discount_type' => $type] + ($share === null ? [] : ['invoice_discount_share' => $share]);
    }

    private function minimumOn(): void
    {
        $this->setConfigValue(SalesControlSettings::MINIMUM_PRICE, '1');
    }

    private function ceilingOn(?string $shop = null): void
    {
        $this->setConfigValue(SalesControlSettings::MAX_DISCOUNT_ON, '1');
        $this->setConfigValue(SalesControlSettings::MAX_DISCOUNT, $shop ?? '');
    }

    public function testIsActive_IsOffUntilAControlIsTurnedOn(): void
    {
        $this->assertFalse($this->controls->isActive());

        $this->minimumOn();

        $this->assertTrue($this->controls->isActive());

        $this->setConfigValue(SalesControlSettings::MINIMUM_PRICE, '0');
        $this->ceilingOn('10');

        $this->assertTrue($this->controls->isActive());
    }

    public function testViolationsOf_FindsNothingWhileBothControlsAreOffEvenForAnItemWithAMinimumAndACeiling(): void
    {
        $item = $this->item('90', '5');

        $this->assertSame([], $this->controls->violationsOf([$this->line($item, '10', '50')]));
    }

    public function testViolationsOf_RefusesAPriceUnderTheMinimumAndNamesWhatTheLineSellsFor(): void
    {
        $this->minimumOn();

        $item  = $this->item('90');
        $found = $this->controls->violationsOf([$this->line($item, '89.99', number: 4)]);

        $this->assertCount(1, $found);
        $this->assertSame(PriceRule::BelowMinimum, $found[0]->rule);
        $this->assertSame(4, $found[0]->line);
        $this->assertSame($item, $found[0]->itemId);
        $this->assertSame('89.9900', $found[0]->net);
        $this->assertSame('90.0000', $found[0]->bound);
    }

    public function testViolationsOf_AcceptsAPriceThatEqualsTheMinimum(): void
    {
        $this->minimumOn();

        $this->assertSame([], $this->controls->violationsOf([$this->line($this->item('90'), '90.00')]));
    }

    public function testViolationsOf_AppliesThePercentDiscountBeforeComparingWithTheMinimum(): void
    {
        $this->minimumOn();

        $item = $this->item('90');

        $this->assertCount(1, $this->controls->violationsOf([$this->line($item, '100', '20')]), '100 less 20% is 80, under 90');
        $this->assertSame([], $this->controls->violationsOf([$this->line($item, '100', '10')]), '100 less 10% is exactly 90');
    }

    public function testViolationsOf_AppliesTheFixedDiscountPerUnitBeforeComparingWithTheMinimum(): void
    {
        $this->minimumOn();

        $item = $this->item('90');

        $this->assertSame([], $this->controls->violationsOf([$this->line($item, '100', '5', FIXED, '3')]), '100 less 5 a unit is 95');
        $this->assertCount(1, $this->controls->violationsOf([$this->line($item, '100', '15', FIXED, '3')]), '100 less 15 a unit is 85');
    }

    public function testViolationsOf_IgnoresAnItemThatHasNoMinimum(): void
    {
        $this->minimumOn();

        $this->assertSame([], $this->controls->violationsOf([$this->line($this->item('0'), '0.01')]));
    }

    public function testViolationsOf_TakesTheShareOfTheDiscountOnTheWholeSaleOffTheUnit(): void
    {
        $this->minimumOn();

        $item = $this->item('90');

        $this->assertSame([], $this->controls->violationsOf([$this->line($item, '100', '0', PERCENT, '2', '0.0000')]));

        $found = $this->controls->violationsOf([$this->line($item, '100', '0', PERCENT, '2', '30.0000')]);

        $this->assertCount(1, $found, 'a share of 30 over 2 units takes 15 off each, leaving 85');
        $this->assertSame('85.0000', $found[0]->net);
    }

    public function testViolationsOf_RefusesAFreeLineOfAnItemThatHasAMinimum(): void
    {
        $this->minimumOn();

        $found = $this->controls->violationsOf([$this->line($this->item('5'), '0')]);

        $this->assertCount(1, $found);
        $this->assertSame('0.0000', $found[0]->net);
    }

    public function testViolationsOf_LeavesOutLinesThatTakeStockBackOrHaveNoQuantity(): void
    {
        $this->minimumOn();
        $this->ceilingOn('1');

        $item = $this->item('90');

        $this->assertSame([], $this->controls->violationsOf([$this->line($item, '10', '50', PERCENT, '-2'), $this->line($item, '10', '50', PERCENT, '0')]));
    }

    public function testViolationsOf_SkipsALineWhoseItemIsNotThere(): void
    {
        $this->minimumOn();

        $this->assertSame([], $this->controls->violationsOf([$this->line(99999999, '1')]));
    }

    public function testViolationsOf_ComparesTheMinimumAtTwoDecimalsSoAThirdOfADiscountIsNotRoundedIntoARefusal(): void
    {
        $this->minimumOn();

        $item = $this->item('6.67');

        $this->assertCount(1, $this->controls->violationsOf([$this->line($item, '10', '33.3334')]), '6.66666 sells under 6.67');
        $this->assertSame([], $this->controls->violationsOf([$this->line($item, '10', '33')]), '6.70 does not');
    }

    public function testViolationsOf_RefusesMoreDiscountThanTheShopAllowsAndAcceptsExactlyThatMuch(): void
    {
        $this->ceilingOn('10');

        $item  = $this->item();
        $found = $this->controls->violationsOf([$this->line($item, '200', '10.01')]);

        $this->assertCount(1, $found);
        $this->assertSame(PriceRule::AboveCeiling, $found[0]->rule);
        $this->assertSame('10.00', $found[0]->bound);
        $this->assertSame([], $this->controls->violationsOf([$this->line($item, '200', '10')]));
        $this->assertSame([], $this->controls->violationsOf([$this->line($item, '200', '0')]));
    }

    public function testViolationsOf_ReadsAFixedDiscountAsAPercentOfThePrice(): void
    {
        $this->ceilingOn('10');

        $item = $this->item();

        $this->assertSame([], $this->controls->violationsOf([$this->line($item, '100', '10', FIXED)]), '10 off 100 is 10%');
        $this->assertCount(1, $this->controls->violationsOf([$this->line($item, '100', '11', FIXED)]));
    }

    public function testViolationsOf_CountsTheShareOfTheDiscountOnTheWholeSaleInTheDiscountOfTheLine(): void
    {
        $this->ceilingOn('10');

        $item = $this->item();

        $this->assertCount(1, $this->controls->violationsOf([$this->line($item, '100', '8', PERCENT, '1', '3.0000')]), '8% of the line and 3 of the whole sale make 11%');
        $this->assertSame([], $this->controls->violationsOf([$this->line($item, '100', '8', PERCENT, '1', '2.0000')]));
    }

    public function testViolationsOf_HoldsTheItemToItsOwnHighestDiscountInsteadOfTheShopOne(): void
    {
        $this->ceilingOn('10');

        $generous = $this->item('0', '30');
        $strict   = $this->item('0', '5');

        $this->assertSame([], $this->controls->violationsOf([$this->line($generous, '100', '25')]), 'the item allows more than the shop');
        $this->assertCount(1, $this->controls->violationsOf([$this->line($strict, '100', '6')]), 'the item allows less than the shop');
        $this->assertSame([], $this->controls->violationsOf([$this->line($strict, '100', '5')]));
    }

    public function testViolationsOf_AllowsNoDiscountOnAnItemWhoseHighestDiscountIsZero(): void
    {
        $this->ceilingOn();

        $item = $this->item('0', '0');

        $this->assertCount(1, $this->controls->violationsOf([$this->line($item, '100', '1')]));
        $this->assertSame([], $this->controls->violationsOf([$this->line($item, '100', '0')]));
    }

    public function testViolationsOf_HoldsOnlyTheItemsThatHaveACeilingWhenTheShopSetsNone(): void
    {
        $this->ceilingOn();

        $free = $this->item();
        $held = $this->item('0', '10');

        $this->assertSame([], $this->controls->violationsOf([$this->line($free, '100', '90')]));
        $this->assertCount(1, $this->controls->violationsOf([$this->line($held, '100', '11')]));
    }

    public function testViolationsOf_ExcusesTheCategoriesOfTheListFromTheHighestDiscountOfTheShop(): void
    {
        $this->ceilingOn('10');
        $this->setConfigValue(SalesControlSettings::EXEMPT_CATEGORIES, json_encode(['Services']));

        $service = $this->item('0', null, 'Services');
        $goods   = $this->item('0', null, 'Goods');

        $this->assertSame([], $this->controls->violationsOf([$this->line($service, '100', '60')]));
        $this->assertCount(1, $this->controls->violationsOf([$this->line($goods, '100', '60')]));
    }

    public function testViolationsOf_StillHoldsAnItemOfAnExcludedCategoryToItsOwnHighestDiscount(): void
    {
        $this->ceilingOn('10');
        $this->setConfigValue(SalesControlSettings::EXEMPT_CATEGORIES, json_encode(['Services']));

        $service = $this->item('0', '20', 'Services');

        $this->assertCount(1, $this->controls->violationsOf([$this->line($service, '100', '21')]));
        $this->assertSame([], $this->controls->violationsOf([$this->line($service, '100', '20')]));
    }

    public function testViolationsOf_IgnoresTheCeilingsWhileTheirControlIsOff(): void
    {
        $this->minimumOn();

        $item = $this->item('0', '5');

        $this->assertSame([], $this->controls->violationsOf([$this->line($item, '100', '50')]));
    }

    public function testViolationsOf_ReportsTheMinimumAndTheCeilingOfTheSameLineAndKeepsTheOrderOfTheLines(): void
    {
        $this->minimumOn();
        $this->ceilingOn('10');

        $item  = $this->item('90');
        $found = $this->controls->violationsOf([$this->line($item, '100', '20', PERCENT, '1', null, 7), $this->line($item, '100', '5')]);

        $this->assertSame([PriceRule::BelowMinimum, PriceRule::AboveCeiling], array_map(static fn ($violation): PriceRule => $violation->rule, $found));
        $this->assertSame([7, 7], array_map(static fn ($violation): int => $violation->line, $found));
    }

    public function testViolationsOf_NumbersALineByItsPlaceWhenItCarriesNoNumber(): void
    {
        $this->minimumOn();

        $item = $this->item('90');

        $found = $this->controls->violationsOf([$this->line($item, '100'), $this->line($item, '10')]);

        $this->assertSame([2], array_map(static fn ($violation): int => $violation->line, $found));
    }

    public function testToException_NamesTheRuleTheLineAndTheFiguresForTheMinimumPrice(): void
    {
        $this->minimumOn();

        $item      = $this->item('90');
        $exception = $this->controls->violationsOf([$this->line($item, '80', '0', PERCENT, '1', null, 3)])[0]->toException();

        $this->assertSame(AccountingError::PriceBelowMinimum, $exception->error);
        $this->assertSame(3, $exception->context['line']);
        $this->assertSame('90.00', $exception->context['min']);
        $this->assertSame('80.00', $exception->context['net']);
        $this->assertSame('Test Item', $exception->context['name']);
    }

    public function testToException_NamesTheRuleTheLineAndTheFiguresForTheDiscount(): void
    {
        $this->ceilingOn('10');

        $exception = $this->controls->violationsOf([$this->line($this->item(), '100', '25')])[0]->toException();

        $this->assertSame(AccountingError::DiscountAboveCeiling, $exception->error);
        $this->assertSame('10.00', $exception->context['max']);
        $this->assertSame('25.00', $exception->context['percent']);
    }

    public function testMayOverride_IsForWhoeverHoldsThePermissionAndForTheSystem(): void
    {
        $holder = $this->employeeWithGrants([AccountingGuard::PRICE_OVERRIDE]);
        $other  = $this->employeeWithGrants([AccountingGuard::CREDIT_OVERRIDE, 'sales_change_price']);

        $this->assertTrue($this->controls->mayOverride($holder));
        $this->assertFalse($this->controls->mayOverride($other));
        $this->assertTrue($this->controls->mayOverride(null));
    }

    public function testRecordOverride_WritesTheLinesThatWentThroughInTheAuditLogOfTheSale(): void
    {
        $this->minimumOn();

        $employee   = $this->employeeWithGrants([AccountingGuard::PRICE_OVERRIDE]);
        $violations = $this->controls->violationsOf([$this->line($this->item('90'), '80', '0', PERCENT, '1', null, 2)]);

        $this->controls->recordOverride(4242, 1, $employee, $violations);

        $entry = $this->accountingDb()->table('accounting_audit_log')->where('action', PriceControls::AUDIT_OVERRIDE)->get()->getRow();

        $this->assertSame('sale', $entry->entity_type);
        $this->assertSame(4242, (int) $entry->entity_id);
        $this->assertSame($employee, (int) $entry->employee_id);
        $this->assertSame(1, (int) $entry->branch_id);
        $this->assertSame([['rule' => 'below_minimum', 'line' => 2, 'item_id' => $violations[0]->itemId, 'net' => '80.0000', 'bound' => '90.0000', 'percent' => '0.00']], json_decode($entry->details, true)['lines']);
    }

    public function testRecordRefusal_WritesTheRefusedLinesInTheAuditLogWithNoSale(): void
    {
        $this->ceilingOn('10');

        $employee   = $this->employeeWithGrants([]);
        $violations = $this->controls->violationsOf([$this->line($this->item(), '100', '25')]);

        $this->controls->recordRefusal(1, $employee, $violations);

        $entry = $this->accountingDb()->table('accounting_audit_log')->where('action', PriceControls::AUDIT_REFUSED)->get()->getRow();

        $this->assertSame('sale', $entry->entity_type);
        $this->assertNull($entry->entity_id);
        $this->assertSame($employee, (int) $entry->employee_id);
        $this->assertSame('above_ceiling', json_decode($entry->details, true)['lines'][0]['rule']);
        $this->assertSame('25.00', json_decode($entry->details, true)['lines'][0]['percent']);
    }
}
