<?php

namespace Tests\Libraries\Accounting;

use App\Libraries\Accounting\InvoiceDiscount;
use App\Libraries\Accounting\ReceivingLine;
use App\Libraries\Accounting\ReturnDiscountShares;
use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\DatabaseTestTrait;
use Config\Database;
use Tests\Support\AccountingFixtureTrait;
use Tests\Support\EmployeeFixtureTrait;
use Tests\Support\ItemFixtureTrait;
use Tests\Support\PurchaseAccountingFixtureTrait;
use Tests\Support\SaleAccountingFixtureTrait;

/**
 * What the lines of a return take of the discount on the whole purchase they give goods back from,
 * counting what earlier returns took, so that all that comes back of an item takes exactly what the
 * purchase gave it.
 */
class ReturnDiscountSharesTest extends CIUnitTestCase
{
    use DatabaseTestTrait;
    use AccountingFixtureTrait;
    use EmployeeFixtureTrait;
    use ItemFixtureTrait;
    use SaleAccountingFixtureTrait;
    use PurchaseAccountingFixtureTrait;

    protected $migrate     = true;
    protected $migrateOnce = true;
    protected $refresh     = false;
    protected $namespace   = null;

    public static function setUpBeforeClass(): void
    {
        $seeder = Database::seeder('tests');
        $seeder->call('TestDatabaseBootstrapSeeder');
    }

    protected function setUp(): void
    {
        parent::setUp();

        $this->startBuying();
    }

    protected function tearDown(): void
    {
        $this->stopBuying();

        parent::tearDown();
    }

    /**
     * @param list<array{int, string, string}> $goods Item, signed quantity and price of each line.
     *
     * @return list<ReceivingLine>
     */
    private function returning(array $goods): array
    {
        $lines = [];

        foreach ($goods as $index => [$item, $quantity, $price]) {
            $lines[] = ReceivingLine::ofCart($this->purchaseLine($item, $index, $quantity, $price, '15'));
        }

        return $lines;
    }

    public function testForReturn_GivesNothingWhenThePurchaseHadNoDiscount(): void
    {
        $item   = $this->item('0.00');
        $origin = $this->buy([$this->purchaseLine($item, 0, '10', '100', '15')]);

        $this->assertSame(['0.0000'], (new ReturnDiscountShares())->forReturn($origin, $this->returning([[$item, '-4', '100']])));
    }

    public function testForReturn_TakesTheShareOfTheUnitsThatGoBack(): void
    {
        $item   = $this->item('0.00');
        $origin = $this->buy([$this->purchaseLine($item, 0, '10', '100', '15')], -1, null, null, InvoiceDiscount::of(FIXED, '100'));

        $this->assertSame(['40.0000'], (new ReturnDiscountShares())->forReturn($origin, $this->returning([[$item, '-4', '100']])));
        $this->assertSame(['100.0000'], (new ReturnDiscountShares())->forReturn($origin, $this->returning([[$item, '-10', '100']])), 'Everything that was bought takes everything that was given');
    }

    public function testForReturn_GivesTheLastReturnWhatTheEarlierOnesLeft(): void
    {
        $item   = $this->item('0.00');
        $origin = $this->buy([$this->purchaseLine($item, 0, '3', '10')], -1, null, null, InvoiceDiscount::of(FIXED, '10'));

        $this->assertGreaterThan(0, $this->buy([$this->purchaseLine($item, 0, '-1', '10')], -1, null, $origin));
        $this->assertGreaterThan(0, $this->buy([$this->purchaseLine($item, 0, '-1', '10')], -1, null, $origin));

        $this->assertSame(['3.3400'], (new ReturnDiscountShares())->forReturn($origin, $this->returning([[$item, '-1', '10']])), 'Ten less 3.33 and 3.33');
    }

    public function testForReturn_NeverTakesMoreThanIsLeftOfTheDiscount(): void
    {
        $item   = $this->item('0.00');
        $origin = $this->buy([$this->purchaseLine($item, 0, '4', '25')], -1, null, null, InvoiceDiscount::of(FIXED, '10'));

        $this->assertGreaterThan(0, $this->buy([$this->purchaseLine($item, 0, '-3', '25')], -1, null, $origin));

        $this->assertSame(['2.5000'], array_map(static fn (string $share): string => bcadd($share, '0', 4), (new ReturnDiscountShares())->forReturn($origin, $this->returning([[$item, '-9', '25']]))), 'What is asked for beyond the purchase is the limit of the return to judge; the share is only what is left');
    }

    public function testForReturn_GivesNothingToAnItemThePurchaseDidNotHave(): void
    {
        $item   = $this->item('0.00');
        $other  = $this->item('0.00');
        $origin = $this->buy([$this->purchaseLine($item, 0, '10', '100')], -1, null, null, InvoiceDiscount::of(FIXED, '100'));

        $this->assertSame(['0.0000', '40.0000'], (new ReturnDiscountShares())->forReturn($origin, $this->returning([[$other, '-1', '100'], [$item, '-4', '100']])));
    }

    public function testForReturn_SplitsWhatAnItemTakesOverItsLinesInProportionToWhatTheyCost(): void
    {
        $item   = $this->item('0.00');
        $origin = $this->buy([$this->purchaseLine($item, 0, '10', '100')], -1, null, null, InvoiceDiscount::of(FIXED, '100'));

        $this->assertSame(['20.0000', '20.0000'], (new ReturnDiscountShares())->forReturn($origin, $this->returning([[$item, '-2', '100'], [$item, '-2', '100']])));
        $this->assertSame(['10.0000', '30.0000'], (new ReturnDiscountShares())->forReturn($origin, $this->returning([[$item, '-1', '100'], [$item, '-3', '100']])));
    }

    public function testForReturn_HoldsAShareToWhatItsLineCostsWhenTheReturnIsPricedBelowTheShare(): void
    {
        $item   = $this->item('0.00');
        $origin = $this->buy([$this->purchaseLine($item, 0, '10', '100')], -1, null, null, InvoiceDiscount::of(FIXED, '500'));

        $this->assertSame(['10.0000'], (new ReturnDiscountShares())->forReturn($origin, $this->returning([[$item, '-2', '5']])), 'The two units cost 10 on this return, and the share of 100 is held to that');
    }

    public function testForReturn_LeavesOutTheReturnThatIsAlreadyStored(): void
    {
        $item   = $this->item('0.00');
        $origin = $this->buy([$this->purchaseLine($item, 0, '10', '100')], -1, null, null, InvoiceDiscount::of(FIXED, '100'));
        $return = $this->buy([$this->purchaseLine($item, 0, '-4', '100')], -1, null, $origin);

        $this->assertSame(['40.0000'], (new ReturnDiscountShares())->forReturn($origin, $this->returning([[$item, '-4', '100']]), $return));
        $this->assertSame(['40.0000'], (new ReturnDiscountShares())->forReturn($origin, $this->returning([[$item, '-4', '100']]), $return), 'Asked again it says the same');
        $this->assertSame(['60.0000'], (new ReturnDiscountShares())->forReturn($origin, $this->returning([[$item, '-6', '100']])), 'A second return, not yet stored, takes the rest');
    }

    public function testForReturn_PoolsWhatTwoLinesOfTheSameItemTookByItem(): void
    {
        $item   = $this->item('0.00');
        $origin = $this->buy([$this->purchaseLine($item, 0, '5', '100'), $this->purchaseLine($item, 1, '5', '50')], -1, null, null, InvoiceDiscount::of(FIXED, '75'));

        $kept = array_map(static fn (object $line): string => bcadd((string) $line->invoice_discount_share, '0', 4), $this->accountingDb()->table('receivings_items')->select('invoice_discount_share')->where('receiving_id', $origin)->orderBy('line', 'ASC')->get()->getResult());

        $this->assertSame(['50.0000', '25.0000'], $kept);
        $this->assertSame(['37.5000'], (new ReturnDiscountShares())->forReturn($origin, $this->returning([[$item, '-5', '100']])), 'Five of ten units take half of the 75 given for the item');
    }

    public function testForReturn_HoldsTheSharesToWhatIsLeftWhenRoundingEachReturnUpWouldTakeMoreThanTheDiscount(): void
    {
        $item   = $this->item('0.00');
        $origin = $this->buy([$this->purchaseLine($item, 0, '10', '1')], -1, null, null, InvoiceDiscount::of(FIXED, '0.05'));
        $shares = [];

        foreach (['3', '3', '3', '1'] as $units) {
            $return   = $this->buy([$this->purchaseLine($item, 0, '-' . $units, '1')], -1, null, $origin);
            $shares[] = bcadd((string) $this->accountingDb()->table('receivings_items')->where('receiving_id', $return)->get()->getRow()->invoice_discount_share, '0', 4);
        }

        $this->assertSame(['0.0200', '0.0200', '0.0100', '0.0000'], $shares, 'A third of 0.05 is 0.0167, which rounds up to 0.02 each time; the third return is held to the 0.01 that is left');
        $this->assertSame('0.05', bcadd(array_reduce($shares, static fn (string $sum, string $share): string => bcadd($sum, $share, 4), '0'), '0', 2));
    }
}
