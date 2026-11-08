<?php

namespace Tests\Libraries\Stock;

use App\Libraries\Accounting\AccountingError;
use App\Libraries\Accounting\AccountingException;
use App\Libraries\Stock\NegativeStockGuard;
use App\Libraries\Stock\NegativeStockOption;
use App\Libraries\Stock\StockOptions;
use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\DatabaseTestTrait;
use Config\Database;
use Tests\Support\AccountingFixtureTrait;
use Tests\Support\ConcurrentDbRaceTrait;
use Tests\Support\EmployeeFixtureTrait;
use Tests\Support\ItemFixtureTrait;
use Tests\Support\PurchaseAccountingFixtureTrait;
use Tests\Support\SaleAccountingFixtureTrait;
use Tests\Support\ShelfFixtureTrait;
use Tests\Support\StockDocumentFixtureTrait;
use Tests\Support\StockUnitsFixtureTrait;

/**
 * @internal
 */
final class NegativeStockGuardTest extends CIUnitTestCase
{
    use DatabaseTestTrait;
    use AccountingFixtureTrait;
    use ConcurrentDbRaceTrait;
    use EmployeeFixtureTrait;
    use ItemFixtureTrait;
    use PurchaseAccountingFixtureTrait;
    use SaleAccountingFixtureTrait;
    use ShelfFixtureTrait;
    use StockDocumentFixtureTrait;
    use StockUnitsFixtureTrait;

    protected $migrate     = true;
    protected $migrateOnce = true;
    protected $refresh     = false;
    protected $namespace;

    public static function setUpBeforeClass(): void
    {
        $seeder = Database::seeder('tests');
        $seeder->call('TestDatabaseBootstrapSeeder');
    }

    protected function setUp(): void
    {
        parent::setUp();

        $this->startStockDocuments();
        $this->readyStockUnits();
    }

    protected function tearDown(): void
    {
        $this->stopStockUnits();
        $this->stopStockDocuments();
        $this->stopBuying();

        parent::tearDown();
    }

    private function option(NegativeStockOption $option): void
    {
        (new StockOptions())->update(['negative_stock' => $option->value], $this->unitManagerId);
    }

    private function refusedWith(AccountingError $error, callable $action): void
    {
        try {
            $action();
            $this->fail('The sale should have been refused with ' . $error->value . '.');
        } catch (AccountingException $exception) {
            $this->assertSame($error, $exception->error);
        }
    }

    private function sellPieces(int $itemId, string $quantity, bool $delivered = true, int $type = SALE_TYPE_POS): int
    {
        return $this->save([$this->line($itemId, 0, $quantity, '10.00', '5.00')], [], $type === SALE_TYPE_POS ? [$this->payment(lang('Sales.cash'), bcmul($quantity, '10.00', 2))] : [], $type, delivered: $delivered);
    }

    public function testAssertSale_AllowsASaleBelowNothingByDefaultAsTheSystemAlwaysDid(): void
    {
        $itemId = $this->stocked('3', '5');

        $this->assertGreaterThan(0, $this->sellPieces($itemId, '5'));
        $this->assertSame('-2.000', $this->onHand($itemId));
    }

    public function testAssertSale_RefusesASaleOfMoreThanTheLocationHoldsWhenTheOptionRefuses(): void
    {
        $itemId = $this->stocked('3', '5');

        $this->option(NegativeStockOption::Refuse);

        $this->assertSame(ACCOUNTING_FAILED, $this->sellPieces($itemId, '4'));
        $this->assertSame(AccountingError::NegativeStockRefused, $this->sales->accountingFailure()?->error);
        $this->assertSame('3.000', $this->onHand($itemId));
        $this->assertSame(0, $this->accountingDb()->table('sales_items')->where('item_id', $itemId)->countAllResults());
        $this->assertBooksTie();
    }

    public function testAssertSale_AllowsASaleOfExactlyWhatTheLocationHolds(): void
    {
        $itemId = $this->stocked('3', '5');

        $this->option(NegativeStockOption::Refuse);

        $this->assertGreaterThan(0, $this->sellPieces($itemId, '3'));
        $this->assertSame('0.000', $this->onHand($itemId));
    }

    public function testAssertSale_AcceptsAReturn(): void
    {
        $itemId = $this->stocked('3', '5');
        $saleId = $this->sellPieces($itemId, '3');

        $this->option(NegativeStockOption::Refuse);

        $this->assertGreaterThan(0, $this->save([$this->line($itemId, 0, '-2', '10.00', '5.00')], [], [], SALE_TYPE_RETURN, origin: $saleId));
        $this->assertSame('2.000', $this->onHand($itemId));
    }

    public function testAssertSale_CountsWhatInvoicesNotYetDeliveredPromiseWhenTheOptionIsBelowAvailable(): void
    {
        $itemId = $this->stocked('10', '5');

        $this->assertGreaterThan(0, $this->sellPieces($itemId, '8', false, SALE_TYPE_INVOICE));
        $this->option(NegativeStockOption::RefuseAvailable);

        $this->assertSame(ACCOUNTING_FAILED, $this->sellPieces($itemId, '5'));
        $this->assertSame(AccountingError::NegativeStockRefused, $this->sales->accountingFailure()?->error);
        $this->assertSame('10.000', $this->onHand($itemId));

        $this->option(NegativeStockOption::Refuse);

        $this->assertGreaterThan(0, $this->sellPieces($itemId, '5'), 'on hand alone leaves five, so the second option lets it through');
    }

    public function testConfirmDelivery_DeliversAnInvoiceWithoutCountingItsOwnPromiseAgainstItself(): void
    {
        $itemId = $this->stocked('10', '5');
        $saleId = $this->sellPieces($itemId, '8', false, SALE_TYPE_INVOICE);

        $this->option(NegativeStockOption::RefuseAvailable);

        $this->assertSame($saleId, $this->sales->confirm_delivery($saleId, $this->employeeId));
        $this->assertSame('2.000', $this->onHand($itemId));
    }

    public function testConfirmDelivery_RefusesADeliveryOfMoreThanTheLocationHoldsWhenTheOptionRefuses(): void
    {
        $itemId = $this->stocked('10', '5');
        $saleId = $this->sellPieces($itemId, '8', false, SALE_TYPE_INVOICE);

        $this->assertGreaterThan(0, $this->sellPieces($itemId, '6'));
        $this->option(NegativeStockOption::Refuse);

        $this->assertSame(ACCOUNTING_FAILED, $this->sales->confirm_delivery($saleId, $this->employeeId));
        $this->assertSame(AccountingError::NegativeStockRefused, $this->sales->accountingFailure()?->error);
        $this->assertSame('4.000', $this->onHand($itemId));
    }

    public function testAssertSale_LeavesAServiceItemAlone(): void
    {
        $itemId = $this->item('0.00', false);

        $this->option(NegativeStockOption::Refuse);

        $this->assertGreaterThan(0, $this->sellPieces($itemId, '3'));
    }

    public function testAssertSale_HoldsTheShelfUnderALockSoTwoSalesOfTheLastUnitNeverBothPass(): void
    {
        $itemId = $this->stocked('1', '5');

        $this->option(NegativeStockOption::Refuse);

        $answers = $this->raceStockWorkers('sellUnits', [(string) $itemId, '1', (string) $this->employeeId], [(string) $itemId, '1', (string) $this->employeeId]);

        $this->assertSame(['done', 'refused'], $answers);
        $this->assertSame('0.000', $this->onHand($itemId));

        $this->accountingDb()->table('inventory')->like('trans_comment', 'POS ', 'after')->delete();
    }

    public function testPromised_AddsUpTheUndeliveredInvoicesOfAnItemInALocation(): void
    {
        $itemId = $this->stocked('20', '5');

        $this->sellPieces($itemId, '3', false, SALE_TYPE_INVOICE);
        $second = $this->sellPieces($itemId, '4', false, SALE_TYPE_INVOICE);

        $guard = new NegativeStockGuard();

        $this->assertSame('7.000', $guard->promised($itemId, self::LOCATION_ID));
        $this->assertSame('3.000', $guard->promised($itemId, self::LOCATION_ID, $second));
        $this->assertSame('0.000', $guard->promised($itemId, 99));
    }

    public function testAssertSale_RefusesByNameWithoutTouchingTheShelfWhenCalledDirectly(): void
    {
        $itemId = $this->stocked('2', '5');

        $this->option(NegativeStockOption::Refuse);

        $this->refusedWith(AccountingError::NegativeStockRefused, static fn () => (new NegativeStockGuard())->assertSale($itemId, self::LOCATION_ID, '3'));
        $this->assertSame('2.000', $this->onHand($itemId));
    }

    public function testAssertSale_ReadsTheShelfUnderALockInTheTransactionOfTheSale(): void
    {
        $itemId = $this->stocked('5', '5');

        $this->option(NegativeStockOption::Refuse);

        $queries = $this->queriesOf(static fn () => (new NegativeStockGuard())->assertSale($itemId, self::LOCATION_ID, '1'));

        $this->assertNotNull($this->firstQuery($queries, ['item_quantities', 'FOR UPDATE']));
    }

    public function testAssertSale_ReadsNothingWhileTheOptionAllowsANegativeStock(): void
    {
        $itemId = $this->stocked('5', '5');

        $queries = $this->queriesOf(static fn () => (new NegativeStockGuard())->assertSale($itemId, self::LOCATION_ID, '99'));

        $this->assertNull($this->firstQuery($queries, ['item_quantities']));
    }

    public function testAssertSale_AcceptsAReturnEvenWhenTheShelfIsAlreadyBelowNothing(): void
    {
        $itemId = $this->stocked('2', '5');
        $saleId = $this->sellPieces($itemId, '5');

        $this->assertSame('-3.000', $this->onHand($itemId));

        $this->option(NegativeStockOption::Refuse);

        $this->assertGreaterThan(0, $this->save([$this->line($itemId, 0, '-2', '10.00', '5.00')], [], [], SALE_TYPE_RETURN, origin: $saleId));
        $this->assertSame('-1.000', $this->onHand($itemId));
    }

    public function testPromised_LeavesOutInvoicesWhoseGoodsHaveLeft(): void
    {
        $itemId = $this->stocked('20', '5');

        $this->sellPieces($itemId, '3', false, SALE_TYPE_INVOICE);
        $this->sellPieces($itemId, '4', true, SALE_TYPE_INVOICE);

        $this->assertSame('3.000', (new NegativeStockGuard())->promised($itemId, self::LOCATION_ID));
    }
}
