<?php

namespace Tests\Libraries\Accounting;

use App\Libraries\Accounting\BooksSnapshot;
use App\Libraries\Accounting\JournalEntryType;
use App\Libraries\Accounting\PostingService;
use App\Libraries\Accounting\StockLedger;
use App\Libraries\Accounting\StockMovement;
use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\DatabaseTestTrait;
use Config\Database;
use Tests\Support\AccountingFixtureTrait;
use Tests\Support\EmployeeFixtureTrait;
use Tests\Support\ItemFixtureTrait;
use Tests\Support\PurchaseAccountingFixtureTrait;
use Tests\Support\SaleAccountingFixtureTrait;

class BooksSnapshotTest extends CIUnitTestCase
{
    use DatabaseTestTrait;
    use AccountingFixtureTrait;
    use EmployeeFixtureTrait;
    use ItemFixtureTrait;
    use PurchaseAccountingFixtureTrait;
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

        $this->startBuying();
    }

    protected function tearDown(): void
    {
        $this->stopBuying();

        parent::tearDown();
    }

    /**
     * @return array<string, mixed>
     */
    private function snapshot(string $debit = '100.0000', string $stock = '50.0000'): array
    {
        return [
            'accounts' => [7 => ['code' => '1201', 'name' => 'Cash', 'debit' => $debit, 'credit' => '0.0000']],
            'totals'   => ['debit' => $debit, 'credit' => '100.0000'],
            'stock'    => [1 => ['account_id' => 9, 'account_balance' => $stock, 'movements_value' => '50.0000', 'unattributed' => bcsub($stock, '50', 4)]],
        ];
    }

    public function testTake_AddsUpWhatEachAccountWasDebitedAndCreditedOverThePostedJournals(): void
    {
        $posting = new PostingService();
        $posting->post($this->balancedDraft('sale', 9101, 1, '100.00'));
        $posting->post($this->balancedDraft('sale', 9102, 1, '15.50'));

        $snapshot = (new BooksSnapshot())->take();

        $this->assertSame(['debit' => '115.5000', 'credit' => '0.0000'], array_intersect_key($snapshot['accounts'][$this->accountId('cash')], ['debit' => 1, 'credit' => 1]));
        $this->assertSame(['debit' => '0.0000', 'credit' => '115.5000'], array_intersect_key($snapshot['accounts'][$this->accountId('sales_revenue')], ['debit' => 1, 'credit' => 1]));
        $this->assertSame(['debit' => '115.5000', 'credit' => '115.5000'], $snapshot['totals']);
    }

    public function testTake_LeavesOutAccountsThatNothingWasPostedTo(): void
    {
        (new PostingService())->post($this->balancedDraft('sale', 9103));

        $this->assertSame([$this->accountId('cash'), $this->accountId('sales_revenue')], array_keys((new BooksSnapshot())->take()['accounts']));
    }

    public function testTake_SetsTheAccountOfEachStockLocationBesideItsValuedMovements(): void
    {
        $itemId = $this->item('10.00');

        (new StockLedger())->record('purchase', 9104, JournalEntryType::General, 1, '2026-10-24', 1, [new StockMovement($itemId, self::LOCATION_ID, '5', '50.00')], null);

        $snapshot = (new BooksSnapshot())->take();

        $this->assertSame(['0.0000', '50.0000', '-50.0000'], [
            $snapshot['stock'][self::LOCATION_ID]['account_balance'],
            $snapshot['stock'][self::LOCATION_ID]['movements_value'],
            $snapshot['stock'][self::LOCATION_ID]['unattributed'],
        ]);
        $this->assertSame([self::LOCATION_ID], BooksSnapshot::untiedStock($snapshot));
    }

    public function testDifferences_AcceptsTwoSnapshotsThatAgreeWhateverTheCodeAndTheNameOfAnAccount(): void
    {
        $after                             = $this->snapshot();
        $after['accounts'][7]['code']      = '120101';
        $after['accounts'][7]['name']      = 'Main cash';
        $after['accounts'][7]['debit']     = '100';
        $after['stock'][1]['unattributed'] = '0';

        $this->assertSame([], BooksSnapshot::differences($this->snapshot(), $after));
    }

    public function testDifferences_RejectsAnAccountWhoseDebitChanged(): void
    {
        $this->assertSame(
            [
                ['what' => 'totals', 'id' => 'all', 'field' => 'debit', 'before' => '100.0000', 'after' => '90.0000'],
                ['what' => 'account', 'id' => 7, 'field' => 'debit', 'before' => '100.0000', 'after' => '90.0000'],
            ],
            BooksSnapshot::differences($this->snapshot(), $this->snapshot('90.0000')),
        );
    }

    public function testDifferences_RejectsAnAccountThatOnlyOneSnapshotHas(): void
    {
        $after = $this->snapshot();
        unset($after['accounts'][7]);

        $this->assertSame(
            [
                ['what' => 'account', 'id' => 7, 'field' => 'debit', 'before' => '100.0000', 'after' => null],
                ['what' => 'account', 'id' => 7, 'field' => 'credit', 'before' => '0.0000', 'after' => null],
            ],
            BooksSnapshot::differences($this->snapshot(), $after),
        );
    }

    public function testDifferences_RejectsAStockLocationWhoseAccountMovedAwayFromItsMovements(): void
    {
        $differences = BooksSnapshot::differences($this->snapshot(), $this->snapshot('100.0000', '40.0000'));

        $this->assertSame(['account_balance', 'unattributed'], array_column($differences, 'field'));
        $this->assertSame(['stock_location', 'stock_location'], array_column($differences, 'what'));
    }

    public function testUntiedStock_AcceptsALocationWhoseAccountStandsAtItsMovements(): void
    {
        $this->assertSame([], BooksSnapshot::untiedStock($this->snapshot()));
        $this->assertSame([1], BooksSnapshot::untiedStock($this->snapshot('100.0000', '40.0000')));
    }
}
