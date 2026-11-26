<?php

namespace Tests\Libraries\Accounting;

use App\Libraries\Accounting\BooksSnapshot;
use App\Libraries\Accounting\JournalDraft;
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
    private function snapshot(string $debit = '100.0000', string $stock = '50.0000', string $branchDebit = ''): array
    {
        return ['companies' => [1 => [
            'accounts' => [7 => ['code' => '1201', 'name' => 'Cash', 'debit' => $debit, 'credit' => '0.0000', 'branches' => [1 => ['debit' => $branchDebit === '' ? $debit : $branchDebit, 'credit' => '0.0000']]]],
            'totals'   => ['debit' => $debit, 'credit' => '100.0000'],
            'stock'    => [1 => ['account_id' => 9, 'account_balance' => $stock, 'movements_value' => '50.0000', 'unattributed' => bcsub($stock, '50', 4)]],
        ]]];
    }

    /**
     * @return array<string, mixed>
     */
    private function books(): array
    {
        return (new BooksSnapshot())->take()['companies'][1];
    }

    public function testTake_AddsUpWhatEachAccountWasDebitedAndCreditedOverThePostedJournals(): void
    {
        $posting = new PostingService();
        $posting->post($this->balancedDraft('sale', 9101, 1, '100.00'));
        $posting->post($this->balancedDraft('sale', 9102, 1, '15.50'));

        $books = $this->books();

        $this->assertSame(['115.5000', '0.0000'], [$books['accounts'][$this->accountId('cash')]['debit'], $books['accounts'][$this->accountId('cash')]['credit']]);
        $this->assertSame(['0.0000', '115.5000'], [$books['accounts'][$this->accountId('sales_revenue')]['debit'], $books['accounts'][$this->accountId('sales_revenue')]['credit']]);
        $this->assertSame(['debit' => '115.5000', 'credit' => '115.5000'], $books['totals']);
    }

    public function testTake_SplitsWhatEachAccountWasDebitedAndCreditedByBranch(): void
    {
        $second  = $this->createBranch('B2');
        $posting = new PostingService();
        $posting->post($this->balancedDraft('sale', 9105, 1, '100.00'));

        $draft = $this->balancedDraft('sale', 9106, 1, '40.00');
        $posting->post(new JournalDraft(
            date: $draft->date,
            lines: $draft->lines,
            sourceType: $draft->sourceType,
            sourceId: $draft->sourceId,
            revision: $draft->revision,
            employeeId: $draft->employeeId,
            currencyCode: 'SAR',
            branchId: $second,
        ));

        $cash = $this->books()['accounts'][$this->accountId('cash')];

        $this->assertSame('140.0000', $cash['debit']);
        $this->assertSame([1 => ['debit' => '100.0000', 'credit' => '0.0000'], $second => ['debit' => '40.0000', 'credit' => '0.0000']], $cash['branches']);
    }

    public function testTake_LeavesOutAccountsThatNothingWasPostedTo(): void
    {
        (new PostingService())->post($this->balancedDraft('sale', 9103));

        $this->assertSame([$this->accountId('cash'), $this->accountId('sales_revenue')], array_keys($this->books()['accounts']));
    }

    public function testTake_SetsTheAccountOfEachStockLocationBesideItsValuedMovements(): void
    {
        $itemId = $this->item('10.00');

        (new StockLedger())->record('purchase', 9104, JournalEntryType::General, 1, '2026-10-24', 1, [new StockMovement($itemId, self::LOCATION_ID, '5', '50.00')], null);

        $snapshot = (new BooksSnapshot())->take();
        $location = $snapshot['companies'][1]['stock'][self::LOCATION_ID];

        $this->assertSame(['0.0000', '50.0000', '-50.0000'], [$location['account_balance'], $location['movements_value'], $location['unattributed']]);
        $this->assertSame(['1/' . self::LOCATION_ID], BooksSnapshot::untiedStock($snapshot));
    }

    public function testTakeAll_HoldsTheBooksOfEveryCompanyUnderItsId(): void
    {
        (new PostingService())->post($this->balancedDraft('sale', 9107, 1, '100.00'));

        $snapshot = (new BooksSnapshot())->takeAll();

        $this->assertSame(array_map('intval', array_column($this->accountingDb()->table('companies')->select('company_id')->orderBy('company_id')->get()->getResultArray(), 'company_id')), array_keys($snapshot['companies']));
        $this->assertSame((new BooksSnapshot())->take()['companies'][1], $snapshot['companies'][1]);
    }

    public function testDifferences_AcceptsTwoSnapshotsThatAgreeWhateverTheCodeAndTheNameOfAnAccount(): void
    {
        $after                                                   = $this->snapshot();
        $after['companies'][1]['accounts'][7]['code']            = '120101';
        $after['companies'][1]['accounts'][7]['name']            = 'Main cash';
        $after['companies'][1]['accounts'][7]['debit']           = '100';
        $after['companies'][1]['stock'][1]['unattributed']       = '0';

        $this->assertSame([], BooksSnapshot::differences($this->snapshot(), $after));
    }

    public function testDifferences_RejectsAnAccountWhoseDebitChanged(): void
    {
        $this->assertSame(
            [
                ['company' => 1, 'what' => 'totals', 'id' => 'all', 'field' => 'debit', 'before' => '100.0000', 'after' => '90.0000'],
                ['company' => 1, 'what' => 'account', 'id' => 7, 'field' => 'debit', 'before' => '100.0000', 'after' => '90.0000'],
                ['company' => 1, 'what' => 'account_branch', 'id' => '7/1', 'field' => 'debit', 'before' => '100.0000', 'after' => '90.0000'],
            ],
            BooksSnapshot::differences($this->snapshot(), $this->snapshot('90.0000')),
        );
    }

    public function testDifferences_RejectsAnAmountThatMovedFromOneBranchToAnotherWhileTheAccountStandsTheSame(): void
    {
        $after                                                 = $this->snapshot('100.0000', '50.0000', '60.0000');
        $after['companies'][1]['accounts'][7]['branches'][2] = ['debit' => '40.0000', 'credit' => '0.0000'];

        $this->assertSame(
            [
                ['company' => 1, 'what' => 'account_branch', 'id' => '7/1', 'field' => 'debit', 'before' => '100.0000', 'after' => '60.0000'],
                ['company' => 1, 'what' => 'account_branch', 'id' => '7/2', 'field' => 'debit', 'before' => null, 'after' => '40.0000'],
                ['company' => 1, 'what' => 'account_branch', 'id' => '7/2', 'field' => 'credit', 'before' => null, 'after' => '0.0000'],
            ],
            BooksSnapshot::differences($this->snapshot(), $after),
        );
    }

    public function testDifferences_RejectsAnAccountThatOnlyOneSnapshotHas(): void
    {
        $after = $this->snapshot();
        unset($after['companies'][1]['accounts'][7]);

        $this->assertSame(
            ['account:debit', 'account:credit', 'account_branch:debit', 'account_branch:credit'],
            array_map(static fn (array $difference): string => $difference['what'] . ':' . $difference['field'], BooksSnapshot::differences($this->snapshot(), $after)),
        );
    }

    public function testDifferences_RejectsACompanyThatOnlyOneSnapshotHas(): void
    {
        $after                 = $this->snapshot();
        $after['companies'][2] = $after['companies'][1];

        $this->assertSame([2], array_values(array_unique(array_column(BooksSnapshot::differences($this->snapshot(), $after), 'company'))));
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
        $this->assertSame(['1/1'], BooksSnapshot::untiedStock($this->snapshot('100.0000', '40.0000')));
    }
}
