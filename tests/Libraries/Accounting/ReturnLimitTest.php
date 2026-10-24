<?php

namespace Tests\Libraries\Accounting;

use App\Libraries\Accounting\PostingError;
use App\Libraries\Accounting\PostingException;
use App\Libraries\Accounting\ReturnLimit;
use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\DatabaseTestTrait;
use Config\Database;
use Tests\Support\AccountingFixtureTrait;
use Tests\Support\EmployeeFixtureTrait;
use Tests\Support\ItemFixtureTrait;
use Tests\Support\PurchaseAccountingFixtureTrait;
use Tests\Support\SaleAccountingFixtureTrait;

/**
 * A return gives back at most what its sale or purchase brought, less what earlier returns gave
 * back. The rows are written straight to the tables, so that nothing but the limit is under test.
 */
class ReturnLimitTest extends CIUnitTestCase
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

    private ReturnLimit $limit;

    /** @var list<int> */
    private array $purchaseRows = [];

    /** @var list<int> */
    private array $saleRows = [];

    public static function setUpBeforeClass(): void
    {
        $seeder = Database::seeder('tests');
        $seeder->call('TestDatabaseBootstrapSeeder');
    }

    protected function setUp(): void
    {
        parent::setUp();

        $this->startBuying();
        $this->limit = new ReturnLimit();
    }

    protected function tearDown(): void
    {
        $db = $this->accountingDb();
        $db->query('SET FOREIGN_KEY_CHECKS = 0');

        foreach ($this->purchaseRows as $receivingId) {
            $db->table('receivings_items')->where('receiving_id', $receivingId)->delete();
            $db->table('receivings')->where('receiving_id', $receivingId)->delete();
        }

        foreach ($this->saleRows as $saleId) {
            $db->table('sales_items')->where('sale_id', $saleId)->delete();
            $db->table('sales')->where('sale_id', $saleId)->delete();
        }

        $db->query('SET FOREIGN_KEY_CHECKS = 1');
        $this->purchaseRows = [];
        $this->saleRows     = [];

        $this->stopBuying();

        parent::tearDown();
    }

    /**
     * @param list<array{int, string, string}> $lines Item, quantity and units in a pack.
     */
    private function purchaseRow(array $lines, string $type = 'receive', ?int $origin = null): int
    {
        $db = $this->accountingDb();

        $db->table('receivings')->insert([
            'supplier_id'            => $this->supplierId,
            'employee_id'            => $this->employeeId,
            'comment'                => 'limit',
            'receiving_type'         => $type,
            'return_of_receiving_id' => $origin,
        ]);
        $receivingId          = (int) $db->insertID();
        $this->purchaseRows[] = $receivingId;

        foreach ($lines as $line => [$itemId, $quantity, $perPack]) {
            $db->table('receivings_items')->insert([
                'receiving_id'       => $receivingId,
                'item_id'            => $itemId,
                'line'               => $line,
                'quantity_purchased' => $quantity,
                'item_cost_price'    => '10.00',
                'item_unit_price'    => '10.00',
                'item_location'      => self::LOCATION_ID,
                'receiving_quantity' => $perPack,
            ]);
        }

        return $receivingId;
    }

    /**
     * @param list<array{int, string}> $lines Item and quantity.
     */
    private function saleRow(array $lines, int $type = SALE_TYPE_INVOICE, ?int $origin = null, int $status = COMPLETED): int
    {
        $db = $this->accountingDb();

        $db->table('sales')->insert([
            'employee_id'       => $this->employeeId,
            'comment'           => 'limit',
            'sale_status'       => $status,
            'sale_type'         => $type,
            'return_of_sale_id' => $origin,
        ]);
        $saleId           = (int) $db->insertID();
        $this->saleRows[] = $saleId;

        foreach ($lines as $line => [$itemId, $quantity]) {
            $db->table('sales_items')->insert([
                'sale_id'            => $saleId,
                'item_id'            => $itemId,
                'line'               => $line,
                'quantity_purchased' => $quantity,
                'item_cost_price'    => '10.00',
                'item_unit_price'    => '10.00',
                'item_location'      => self::LOCATION_ID,
            ]);
        }

        return $saleId;
    }

    private function refusalOf(callable $work): PostingException
    {
        try {
            $work();
        } catch (PostingException $failure) {
            return $failure;
        }

        $this->fail('A return of more than the document brought must be refused.');
    }

    public function testAssertPurchaseReturnWithin_AcceptsAReturnWithinWhatWasBought(): void
    {
        $item   = $this->item('10.00');
        $origin = $this->purchaseRow([[$item, '10', '1']]);
        $return = $this->purchaseRow([[$item, '-4', '1']], 'return', $origin);

        $this->limit->assertPurchaseReturnWithin($return);

        $this->addToAssertionCount(1);
    }

    public function testAssertPurchaseReturnWithin_AcceptsAReturnOfExactlyWhatWasBought(): void
    {
        $item   = $this->item('10.00');
        $origin = $this->purchaseRow([[$item, '10', '1']]);
        $return = $this->purchaseRow([[$item, '-10', '1']], 'return', $origin);

        $this->limit->assertPurchaseReturnWithin($return);

        $this->addToAssertionCount(1);
    }

    public function testAssertPurchaseReturnWithin_RefusesAReturnOfMoreAndSaysHowMuchIsLeft(): void
    {
        $item   = $this->item('10.00');
        $origin = $this->purchaseRow([[$item, '10', '1']]);
        $return = $this->purchaseRow([[$item, '-10.5', '1']], 'return', $origin);

        $failure = $this->refusalOf(fn () => $this->limit->assertPurchaseReturnWithin($return));

        $this->assertSame(PostingError::PurchaseReturnExceedsOrigin, $failure->error);
        $this->assertSame('10.000', $failure->context['max']);
        $this->assertSame($item, $failure->context['item_id']);
        $this->assertSame($origin, $failure->context['origin_id']);
    }

    public function testAssertPurchaseReturnWithin_CountsEarlierReturnsButNotTheReturnItself(): void
    {
        $item   = $this->item('10.00');
        $origin = $this->purchaseRow([[$item, '10', '1']]);
        $this->purchaseRow([[$item, '-6', '1']], 'return', $origin);
        $return = $this->purchaseRow([[$item, '-5', '1']], 'return', $origin);

        $failure = $this->refusalOf(fn () => $this->limit->assertPurchaseReturnWithin($return));

        $this->assertSame('4.000', $failure->context['max']);
    }

    public function testAssertPurchaseReturnWithin_DoesNotCountTheReturnItselfTwice(): void
    {
        $item   = $this->item('10.00');
        $origin = $this->purchaseRow([[$item, '10', '1']]);
        $this->purchaseRow([[$item, '-6', '1']], 'return', $origin);
        $return = $this->purchaseRow([[$item, '-4', '1']], 'return', $origin);

        $this->limit->assertPurchaseReturnWithin($return);

        $this->addToAssertionCount(1);
    }

    public function testAssertPurchaseReturnWithin_CountsThePacksOfEachLineAsTheUnitsInThem(): void
    {
        $item   = $this->item('10.00');
        $origin = $this->purchaseRow([[$item, '2', '12']]);
        $over   = $this->purchaseRow([[$item, '-3', '12']], 'return', $origin);

        $failure = $this->refusalOf(fn () => $this->limit->assertPurchaseReturnWithin($over));

        $this->assertSame('24.000', $failure->context['max']);

        $within = $this->purchaseRow([[$item, '-24', '1']], 'return', $origin);
        $this->accountingDb()->table('receivings_items')->where('receiving_id', $over)->delete();
        $this->accountingDb()->table('receivings')->where('receiving_id', $over)->delete();

        $this->limit->assertPurchaseReturnWithin($within);
        $this->addToAssertionCount(1);
    }

    public function testAssertPurchaseReturnWithin_AddsUpEveryLineOfTheSameItem(): void
    {
        $item   = $this->item('10.00');
        $origin = $this->purchaseRow([[$item, '4', '1'], [$item, '6', '1']]);
        $return = $this->purchaseRow([[$item, '-11', '1']], 'return', $origin);

        $failure = $this->refusalOf(fn () => $this->limit->assertPurchaseReturnWithin($return));

        $this->assertSame('10.000', $failure->context['max']);
    }

    public function testAssertPurchaseReturnWithin_ToleratesTheRoundingOfAThousandth(): void
    {
        $item   = $this->item('10.00');
        $origin = $this->purchaseRow([[$item, '10', '1']]);
        $return = $this->purchaseRow([[$item, '-10.0004', '1']], 'return', $origin);

        $this->limit->assertPurchaseReturnWithin($return);

        $this->addToAssertionCount(1);
    }

    public function testAssertPurchaseReturnWithin_LeavesAnItemThePurchaseDidNotHaveToPosting(): void
    {
        $item   = $this->item('10.00');
        $other  = $this->item('10.00');
        $origin = $this->purchaseRow([[$item, '10', '1']]);
        $return = $this->purchaseRow([[$other, '-50', '1']], 'return', $origin);

        $this->limit->assertPurchaseReturnWithin($return);

        $this->addToAssertionCount(1);
    }

    public function testAssertPurchaseReturnWithin_IsNothingForAPurchaseThatIsNotAReturn(): void
    {
        $item     = $this->item('10.00');
        $purchase = $this->purchaseRow([[$item, '10', '1']]);

        $this->limit->assertPurchaseReturnWithin($purchase);
        $this->limit->assertPurchaseReturnWithin(0);

        $this->addToAssertionCount(1);
    }

    public function testAssertSaleReturnWithin_AcceptsAReturnWithinWhatWasSold(): void
    {
        $item   = $this->item('10.00');
        $origin = $this->saleRow([[$item, '5']]);
        $return = $this->saleRow([[$item, '-5']], SALE_TYPE_RETURN, $origin);

        $this->limit->assertSaleReturnWithin($return);

        $this->addToAssertionCount(1);
    }

    public function testAssertSaleReturnWithin_RefusesAReturnOfMoreAndSaysHowMuchIsLeft(): void
    {
        $item    = $this->item('10.00');
        $origin  = $this->saleRow([[$item, '5']]);
        $this->saleRow([[$item, '-2']], SALE_TYPE_RETURN, $origin);
        $return = $this->saleRow([[$item, '-4']], SALE_TYPE_RETURN, $origin);

        $failure = $this->refusalOf(fn () => $this->limit->assertSaleReturnWithin($return));

        $this->assertSame(PostingError::SaleReturnExceedsOrigin, $failure->error);
        $this->assertSame('3.000', $failure->context['max']);
        $this->assertSame($origin, $failure->context['origin_id']);
    }

    public function testAssertSaleReturnWithin_DoesNotCountReturnsThatAreNotCompleted(): void
    {
        $item   = $this->item('10.00');
        $origin = $this->saleRow([[$item, '5']]);
        $this->saleRow([[$item, '-4']], SALE_TYPE_RETURN, $origin, SUSPENDED);
        $return = $this->saleRow([[$item, '-5']], SALE_TYPE_RETURN, $origin);

        $this->limit->assertSaleReturnWithin($return);

        $this->addToAssertionCount(1);
    }

    public function testAssertSaleReturnWithin_LeavesAnItemTheSaleDidNotHaveToPosting(): void
    {
        $item   = $this->item('10.00');
        $other  = $this->item('10.00');
        $origin = $this->saleRow([[$item, '5']]);
        $return = $this->saleRow([[$other, '-50']], SALE_TYPE_RETURN, $origin);

        $this->limit->assertSaleReturnWithin($return);

        $this->addToAssertionCount(1);
    }

    public function testAssertSaleReturnWithin_IsNothingForASaleThatIsNotAReturn(): void
    {
        $item = $this->item('10.00');
        $sale = $this->saleRow([[$item, '5']]);

        $this->limit->assertSaleReturnWithin($sale);
        $this->limit->assertSaleReturnWithin(0);

        $this->addToAssertionCount(1);
    }
}
