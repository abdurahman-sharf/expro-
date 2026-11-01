<?php

namespace Tests\Libraries\Stock;

use App\Libraries\Accounting\AccountingError;
use App\Libraries\Accounting\AccountingException;
use App\Libraries\Accounting\StockLedger;
use App\Libraries\Stock\StockPermit;
use App\Libraries\Stock\StockPermitSources;
use App\Libraries\Stock\StockPermitType;
use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\DatabaseTestTrait;
use Config\Database;
use Tests\Support\AccountingFixtureTrait;
use Tests\Support\EmployeeFixtureTrait;
use Tests\Support\ItemFixtureTrait;
use Tests\Support\PurchaseAccountingFixtureTrait;
use Tests\Support\SaleAccountingFixtureTrait;
use Tests\Support\StockDocumentFixtureTrait;

/**
 * The numbered stock permits: an add permit, an issue permit and a transfer between two stock
 * locations, with the journals and valued movements they post through the builders that already post
 * stock, the shelves they move, and how they are corrected and cancelled.
 */
class StockPermitTest extends CIUnitTestCase
{
    use DatabaseTestTrait;
    use AccountingFixtureTrait;
    use EmployeeFixtureTrait;
    use ItemFixtureTrait;
    use PurchaseAccountingFixtureTrait;
    use SaleAccountingFixtureTrait;
    use StockDocumentFixtureTrait;

    protected $migrate     = true;
    protected $migrateOnce = true;
    protected $refresh     = false;
    protected $namespace   = null;

    private StockPermit $permits;

    public static function setUpBeforeClass(): void
    {
        $seeder = Database::seeder('tests');
        $seeder->call('TestDatabaseBootstrapSeeder');
    }

    protected function setUp(): void
    {
        parent::setUp();

        $this->startStockDocuments();
        $this->permits = new StockPermit();
    }

    protected function tearDown(): void
    {
        $this->stopStockDocuments();
        $this->stopBuying();

        parent::tearDown();
    }

    /**
     * @param list<array{item_id: int, quantity: string, unit_cost?: string}> $lines
     *
     * @return array<string, mixed>
     */
    private function input(StockPermitType $type, array $lines, array $more = []): array
    {
        return [
            'type'        => $type->value,
            'date'        => date('Y-m-d'),
            'location_id' => self::LOCATION_ID,
            'note'        => '',
            'lines'       => $lines,
            ...$more,
        ];
    }

    private function add(int $itemId, string $quantity, string $cost, array $more = []): int
    {
        return $this->permits->create($this->input(StockPermitType::Add, [['item_id' => $itemId, 'quantity' => $quantity, 'unit_cost' => $cost]], $more), $this->keeperId);
    }

    private function issue(int $itemId, string $quantity, array $more = []): int
    {
        return $this->permits->create($this->input(StockPermitType::Issue, [['item_id' => $itemId, 'quantity' => $quantity]], $more), $this->keeperId);
    }

    private function refusedWith(AccountingError $error, callable $action): void
    {
        try {
            $action();
            $this->fail('The change should have been refused with ' . $error->value . '.');
        } catch (AccountingException $exception) {
            $this->assertSame($error, $exception->error);
        }
    }

    public function testCreate_AddPermitPutsTheUnitsInTheLocationAtTheCostTypedAndCreditsTheAutomaticAccount(): void
    {
        $itemId = $this->stocked('10', '50');

        $permitId = $this->add($itemId, '2', '150');

        $journals = $this->journalsOfSource(StockPermitSources::PERMIT, $permitId);

        $this->assertCount(1, $journals);
        $this->assertSame([$this->stockAccount() . ':300.00:0.00', $this->accountId('other_payables') . ':0.00:300.00'], $this->linesOfJournal((int) $journals[0]->journal_id));
        $this->assertSame('12.000', $this->onHand($itemId));
        $this->assertSame(['quantity' => '12.000', 'value' => '800.00'], $this->stockOf($itemId));
        $this->assertBooksTie();
    }
}
