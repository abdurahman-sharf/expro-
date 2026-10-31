<?php

namespace Tests\Libraries\Accounting;

use App\Libraries\Accounting\UndeliveredReturns;
use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\DatabaseTestTrait;
use Config\Database;
use Tests\Support\AccountingFixtureTrait;
use Tests\Support\EmployeeFixtureTrait;
use Tests\Support\ItemFixtureTrait;
use Tests\Support\SaleAccountingFixtureTrait;

/**
 * What came back of an invoice before its goods were delivered, and what a delivery still has to
 * take out of each line once it is taken off.
 */
class UndeliveredReturnsTest extends CIUnitTestCase
{
    use DatabaseTestTrait;
    use AccountingFixtureTrait;
    use EmployeeFixtureTrait;
    use ItemFixtureTrait;
    use SaleAccountingFixtureTrait;

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

        $this->startSelling();
    }

    protected function tearDown(): void
    {
        $this->stopSelling();

        parent::tearDown();
    }

    public function testUnitsOf_SumsTheUnitsOfTheCompletedReturnsOfTheSaleByItem(): void
    {
        $first   = $this->item('60.00');
        $second  = $this->item('20.00');
        $invoice = $this->save([$this->line($first, 0, '5', '100', '60.00'), $this->line($second, 1, '3', '50', '20.00')], [], [], SALE_TYPE_INVOICE, -1, false);

        $this->save([$this->line($first, 0, '-1', '100', '60.00')], [], [], SALE_TYPE_RETURN, -1, true, $invoice);
        $this->save([$this->line($first, 0, '-2', '100', '60.00'), $this->line($second, 1, '-3', '50', '20.00')], [], [], SALE_TYPE_RETURN, -1, true, $invoice);

        $this->assertEquals([$first => '3.000', $second => '3.000'], (new UndeliveredReturns())->unitsOf($invoice));
    }

    public function testUnitsOf_IgnoresReturnsThatAreNotCompleteAndReturnsOfOtherSales(): void
    {
        $item    = $this->item('60.00');
        $invoice = $this->save([$this->line($item, 0, '5', '100', '60.00')], [], [], SALE_TYPE_INVOICE, -1, false);
        $other   = $this->save([$this->line($item, 0, '5', '100', '60.00')], [], [], SALE_TYPE_INVOICE, -1, false);

        $this->save([$this->line($item, 0, '-1', '100', '60.00')], [], [], SALE_TYPE_RETURN, -1, true, $invoice, SUSPENDED);
        $this->save([$this->line($item, 0, '-2', '100', '60.00')], [], [], SALE_TYPE_RETURN, -1, true, $other);

        $this->assertSame([], (new UndeliveredReturns())->unitsOf($invoice));
        $this->assertEquals([$item => '2.000'], (new UndeliveredReturns())->unitsOf($other));
    }

    public function testRemaining_TakesTheReturnedUnitsOffTheLinesOfTheItemInOrder(): void
    {
        [$first, $back] = UndeliveredReturns::remaining(7, '2', [7 => '3.000']);
        [$second, $back] = UndeliveredReturns::remaining(7, '4', $back);
        [$third, $back] = UndeliveredReturns::remaining(7, '4', $back);

        $this->assertSame(['0.000', '3.000', '4.000'], [$first, $second, $third]);
        $this->assertSame(['7' => '0.000'], array_map('strval', $back));
    }

    public function testRemaining_LeavesALineOfAnItemNothingCameBackOfWhole(): void
    {
        [$units, $back] = UndeliveredReturns::remaining(9, '-2', [7 => '3.000']);

        $this->assertSame('2.000', $units);
        $this->assertSame([7 => '3.000'], $back);
    }

    public function testNeverDelivered_IsTrueOnlyForAnExistingSaleWhoseGoodsDidNotLeave(): void
    {
        $item      = $this->item('60.00');
        $waiting   = $this->save([$this->line($item, 0, '1', '100', '60.00')], [], [], SALE_TYPE_INVOICE, -1, false);
        $delivered = $this->save([$this->line($item, 0, '1', '100', '60.00')]);
        $check     = new UndeliveredReturns();

        $this->assertTrue($check->neverDelivered($waiting));
        $this->assertFalse($check->neverDelivered($delivered));
        $this->assertFalse($check->neverDelivered(null));
        $this->assertFalse($check->neverDelivered(999999));

        $this->sales->confirm_delivery($waiting, $this->employeeId);

        $this->assertFalse($check->neverDelivered($waiting));
    }
}
