<?php

namespace Tests\Libraries\Accounting\Backfill;

use App\Libraries\Accounting\Backfill\BackfillDocument;
use App\Libraries\Accounting\Backfill\BackfillFamily;
use App\Libraries\Accounting\Backfill\BackfillPlan;
use App\Libraries\Accounting\Backfill\BackfillRefusal;
use App\Libraries\Accounting\Backfill\BackfillScope;
use App\Libraries\Accounting\Backfill\SaleBackfill;
use App\Libraries\Accounting\OpeningStock;
use App\Libraries\Accounting\PostingService;
use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\DatabaseTestTrait;
use Config\Database;
use Tests\Support\AccountingFixtureTrait;
use Tests\Support\BackfillFixtureTrait;
use Tests\Support\EmployeeFixtureTrait;
use Tests\Support\ItemFixtureTrait;
use Tests\Support\PreviewJournalFixtureTrait;
use Tests\Support\PurchaseAccountingFixtureTrait;
use Tests\Support\SaleAccountingFixtureTrait;

/**
 * @internal
 */
final class SaleBackfillTest extends CIUnitTestCase
{
    use DatabaseTestTrait;
    use AccountingFixtureTrait;
    use BackfillFixtureTrait;
    use EmployeeFixtureTrait;
    use ItemFixtureTrait;
    use PreviewJournalFixtureTrait;
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

        $this->startBackfill();
    }

    protected function tearDown(): void
    {
        $this->stopBackfill();

        parent::tearDown();
    }

    private function adapter(): SaleBackfill
    {
        return new SaleBackfill($this->accountingDb());
    }

    private function plan(int $saleId, ?string $date = null): BackfillPlan
    {
        $row = $this->saleRow($saleId);

        return $this->adapter()->plan(new BackfillDocument(BackfillFamily::Sales, $saleId, $date ?? substr((string) $row->sale_time, 0, 10), (int) $row->branch_id), null, 0);
    }

    private function refusalOf(int $saleId): ?BackfillRefusal
    {
        return $this->plan($saleId)->refusal;
    }

    /**
     * @return list<string>
     */
    private function posted(int $saleId): array
    {
        return array_map(static fn (array $journal): string => $journal['source'] . ':' . $journal['entry'] . '|' . implode(',', $journal['lines']), $this->standing($saleId));
    }

    public function testPlan_MakesTheJournalsTheLivePostingMakesOfTheSameSale(): void
    {
        $payments = [$this->payment(lang('Sales.cash'), '800'), $this->payment(lang('Sales.due'), '16.50')];
        $legacy   = $this->legacyInvoice('2026-03-10', $payments);
        $cycle    = $this->referenceInvoice();
        $live     = $this->save($cycle['items'], $cycle['taxes'], $payments);

        $this->assertNotEmpty($this->posted($live));
        $this->assertSame($this->posted($live), $this->plannedLines($this->plan($legacy)));
    }

    public function testPlan_MakesTheJournalsOfAReturnTheLivePostingMakes(): void
    {
        [$legacyInvoice, $legacyCycle] = $this->whileOff(function (): array {
            $cycle = $this->referenceInvoice();

            return [$this->dateSale($this->save($cycle['items'], $cycle['taxes']), '2026-03-10'), $cycle];
        });
        $liveCycle   = $this->referenceInvoice();
        $liveInvoice = $this->save($liveCycle['items'], $liveCycle['taxes']);

        $legacyReturn = $this->whileOff(fn (): int => $this->dateSale($this->save([$this->line($legacyCycle['cream'], 0, '-1', '75', '40', '10')], [], [], SALE_TYPE_RETURN, -1, true, $legacyInvoice), '2026-03-11'));
        $liveReturn   = $this->save([$this->line($liveCycle['cream'], 0, '-1', '75', '40', '10')], [], [], SALE_TYPE_RETURN, -1, true, $liveInvoice);

        $this->assertGreaterThan(0, $liveReturn);
        $this->assertNotEmpty($this->posted($liveReturn));
        $this->assertSame($this->posted($liveReturn), $this->plannedLines($this->plan($legacyReturn)), $this->plan($legacyReturn)->detail);
    }

    public function testPlan_PostsTheCostOfStockThatWasDeliveredAndNoCostForWhatWasNot(): void
    {
        $delivered = $this->legacyInvoice();
        $undelivered = $this->whileOff(function (): int {
            $cycle = $this->referenceInvoice();

            return $this->dateSale($this->save($cycle['items'], $cycle['taxes'], [], SALE_TYPE_INVOICE, -1, false), '2026-03-10');
        });

        $this->assertCount(2, $this->plannedLines($this->plan($delivered)));
        $this->assertSame(['sale:revenue'], array_map(static fn (string $line): string => explode('|', $line)[0], $this->plannedLines($this->plan($undelivered))));
    }

    public function testPlan_ReadsTheDayOfTheInvoiceWhenItWasTyped(): void
    {
        $saleId = $this->legacyInvoice('2026-03-10');

        $this->accountingDb()->table('sales')->where('sale_id', $saleId)->update(['invoice_date' => '2026-03-02']);

        $dates = array_unique(array_map(static fn ($step): string => $step->draft->date, array_filter($this->plan($saleId)->steps, static fn ($step): bool => $step->draft !== null)));

        $this->assertSame(['2026-03-02'], array_values($dates));
    }

    public function testPlan_RefusesADocumentThatComesToNothing(): void
    {
        $saleId = $this->whileOff(fn (): int => $this->dateSale($this->save([$this->line($this->item('0', false), 0, '1', '0')]), '2026-03-10'));

        $this->assertSame(BackfillRefusal::ZeroTotal, $this->refusalOf($saleId));
    }

    public function testPlan_RefusesADocumentDatedBeforeTheStartDateOrTheOpeningBalances(): void
    {
        $saleId = $this->legacyInvoice('2026-03-10');

        $this->setConfigValue('accounting_start_date', '2026-06-01');
        $this->assertSame(BackfillRefusal::BeforeStartDate, $this->refusalOf($saleId));

        $this->setConfigValue('accounting_start_date', '2020-01-01');
        $this->accountingDb()->table('accounting_journals')->where('source_type', 'opening')->update(['journal_date' => '2026-03-20']);
        $this->assertSame(BackfillRefusal::BeforeOpening, $this->refusalOf($saleId));
    }

    public function testPlan_RefusesASaleOfGoodsWhoseOpeningStockWasPostedAfterItWasSaved(): void
    {
        [$saleId, $cycle] = $this->legacyInvoiceWithItems();

        $this->assertNull($this->refusalOf($saleId));

        $this->postOpeningStockOf([$cycle['cream'], $cycle['perfume']]);

        $this->assertSame(BackfillRefusal::StockOpenedAfter, $this->refusalOf($saleId));
        $this->assertSame([], $this->plan($saleId)->steps);
    }

    public function testPlan_PostsASaleSavedAfterTheOpeningStockOfItsLocation(): void
    {
        [$saleId, $cycle] = $this->legacyInvoiceWithItems();

        $this->postOpeningStockOf([$cycle['cream'], $cycle['perfume']]);
        $this->openingStockWasPostedAt('2026-03-01 00:00:00');

        $this->assertNull($this->refusalOf($saleId));
        $this->assertCount(2, $this->plannedLines($this->plan($saleId)));
    }

    public function testPlan_IgnoresTheOpeningStockOfAnotherLocationAndOneThatWasTakenBack(): void
    {
        [$saleId, $cycle] = $this->legacyInvoiceWithItems();

        $this->postOpeningStockOf([$this->item('10.00')], '5', $this->branchLocation(1, 'Store'));

        $this->assertNull($this->refusalOf($saleId));

        $this->postOpeningStockOf([$cycle['cream'], $cycle['perfume']]);
        $this->assertSame(BackfillRefusal::StockOpenedAfter, $this->refusalOf($saleId));

        (new OpeningStock())->reverse(1, '2026-03-02', 'Posted too early', $this->backfillManager);

        $this->assertNull($this->refusalOf($saleId));
    }

    public function testPlan_LeavesASaleOfServicesAloneWhateverTheOpeningStock(): void
    {
        $saleId = $this->whileOff(fn (): int => $this->dateSale($this->save([$this->line($this->item('0', false), 0, '1', '100')]), '2026-03-10'));

        $this->postOpeningStockOf([$this->item('10.00')], '5');

        $this->assertNull($this->refusalOf($saleId));
    }

    public function testPlan_RefusesAPaymentDatedBeforeTheBooksBeginOrTheOpeningBalancesAlthoughTheInvoiceIsAfter(): void
    {
        $saleId = $this->legacyInvoice('2026-03-10', [$this->payment(lang('Sales.cash'), '816.50')]);

        $this->accountingDb()->table('sales_payments')->where('sale_id', $saleId)->update(['payment_time' => '2026-02-20 10:00:00']);
        $this->setConfigValue('accounting_start_date', '2026-03-01');

        $this->assertSame(BackfillRefusal::BeforeStartDate, $this->refusalOf($saleId));
        $this->assertSame('sale_payment 2026-02-20', $this->plan($saleId)->detail);

        $this->setConfigValue('accounting_start_date', '2020-01-01');
        $this->accountingDb()->table('accounting_journals')->where('source_type', 'opening')->update(['journal_date' => '2026-03-01']);

        $this->assertSame(BackfillRefusal::BeforeOpening, $this->refusalOf($saleId));

        $this->accountingDb()->table('sales_payments')->where('sale_id', $saleId)->update(['payment_time' => '2026-03-01 10:00:00']);

        $this->assertNull($this->refusalOf($saleId));
    }

    /**
     * @return array{0: int, 1: array{items: list<array<string, mixed>>, taxes: list<array<string, mixed>>, cream: int, perfume: int}}
     */
    private function legacyInvoiceWithItems(): array
    {
        return $this->whileOff(function (): array {
            $cycle = $this->referenceInvoice();

            return [$this->dateSale($this->save($cycle['items'], $cycle['taxes']), '2026-03-10'), $cycle];
        });
    }

    public function testPlan_RefusesADocumentOfADeletedCustomer(): void
    {
        $saleId = $this->legacyInvoice('2026-03-10');

        $this->accountingDb()->table('customers')->where('person_id', $this->customerId)->update(['deleted' => 1]);

        $this->assertSame(BackfillRefusal::PartyMissing, $this->refusalOf($saleId));
    }

    public function testPlan_RefusesAReturnWhoseOriginIsMissing(): void
    {
        [$invoice, $return] = $this->whileOff(function (): array {
            $cycle   = $this->referenceInvoice();
            $invoice = $this->dateSale($this->save($cycle['items'], $cycle['taxes']), '2026-03-10');

            return [$invoice, $this->dateSale($this->save([$this->line($cycle['cream'], 0, '-1', '75', '40', '10')], [], [], SALE_TYPE_RETURN, -1, true, $invoice), '2026-03-11')];
        });

        $this->assertNull($this->refusalOf($return), $this->plan($return)->detail);

        $this->accountingDb()->table('sales')->where('sale_id', $return)->update(['return_of_sale_id' => null]);

        $this->assertSame(BackfillRefusal::ReturnOriginMissing, $this->refusalOf($return));
    }

    public function testPlan_RefusesAPaymentIntoATreasuryThatIsGone(): void
    {
        $saleId = $this->legacyInvoice('2026-03-10', [$this->payment(lang('Sales.cash'), '816.50')]);

        $this->withoutKeys(fn () => $this->accountingDb()->table('sales_payments')->where('sale_id', $saleId)->update(['treasury_id' => 987654]));

        $this->assertSame(BackfillRefusal::TreasuryMissing, $this->refusalOf($saleId));
    }

    public function testPlan_RefusesAPaymentIntoATreasuryOfAnotherBranch(): void
    {
        $other  = $this->createBranch();
        $saleId = $this->legacyInvoice('2026-03-10', [$this->payment(lang('Sales.cash'), '816.50')]);

        $this->accountingDb()->table('sales_payments')->where('sale_id', $saleId)->update(['treasury_id' => $this->treasuryIn($other), 'treasury_branch_id' => $other]);

        $this->assertSame(BackfillRefusal::CrossBranchPayment, $this->refusalOf($saleId));
    }

    public function testPlan_RefusesASaleWhosePaymentIsInTheBooksAlready(): void
    {
        $saleId    = $this->legacyInvoice('2026-03-10', [$this->payment(lang('Sales.cash'), '816.50')]);
        $paymentId = (int) $this->accountingDb()->table('sales_payments')->where('sale_id', $saleId)->get()->getRow()->payment_id;

        $this->assertTrue((new PostingService())->post($this->balancedDraft('sale_payment', $paymentId))->created);
        $this->assertSame(BackfillRefusal::PartlyPosted, $this->refusalOf($saleId));
    }

    public function testPlan_RefusesADateOutsideEveryFiscalYearAndAClosedPeriod(): void
    {
        $outside = $this->legacyInvoice('2037-06-10');
        $closed  = $this->legacyInvoice('2026-03-10');

        $this->assertSame(BackfillRefusal::OutsideFiscalYear, $this->refusalOf($outside));

        $this->closeThrough('2026-03-31');
        $this->assertSame(BackfillRefusal::PeriodClosed, $this->refusalOf($closed));
    }

    public function testPlan_HonoursTheClosedPeriodOfTheBranchOfTheDocumentOnly(): void
    {
        $other = $this->createBranch();
        $saleId = $this->legacyInvoice('2026-03-10');

        $this->closeThrough('2026-03-31', $other);
        $this->assertNull($this->refusalOf($saleId));

        $this->closeThrough('2026-03-31', 1);
        $this->assertSame(BackfillRefusal::PeriodClosed, $this->refusalOf($saleId));
    }

    public function testCandidates_ListsTheCompletedSalesInTheRangeThatNoJournalStandsFor(): void
    {
        $legacy = $this->legacyInvoice('2026-03-10');
        $live   = $this->save($this->referenceInvoice()['items'], $this->referenceInvoice()['taxes']);
        $suspended = $this->save($this->referenceInvoice()['items'], [], [], SALE_TYPE_INVOICE, -1, true, null, SUSPENDED);
        $early  = $this->legacyInvoice('2025-12-01');
        $scope  = new BackfillScope('2026-01-01', '2026-12-31', null, [BackfillFamily::Sales]);

        $found = array_map(static fn (BackfillDocument $document): int => $document->id, $this->adapter()->candidates($scope, 0, 50, 0));

        $this->assertContains($legacy, $found);
        $this->assertNotContains($live, $found);
        $this->assertNotContains($suspended, $found);
        $this->assertNotContains($early, $found);
    }

    public function testCandidates_ListsOnlyTheBranchOfTheScope(): void
    {
        $other = $this->createBranch();
        $one   = $this->legacyInvoice('2026-03-10');
        $two   = $this->whileOff(fn (): int => $this->dateSale($this->invoiceInBranch($other, '100'), '2026-03-10'));
        $ids   = static fn (array $documents): array => array_map(static fn (BackfillDocument $document): int => $document->id, $documents);

        $this->assertSame([$two], $ids($this->adapter()->candidates(new BackfillScope('2026-01-01', '2026-12-31', $other, [BackfillFamily::Sales]), 0, 50, 0)));
        $this->assertSame([$one], $ids($this->adapter()->candidates(new BackfillScope('2026-01-01', '2026-12-31', 1, [BackfillFamily::Sales]), 0, 50, 0)));
    }

    public function testCandidates_PagesByIdAfterTheCursor(): void
    {
        $first  = $this->legacyInvoice('2026-03-10');
        $second = $this->legacyInvoice('2026-03-11');
        $scope  = new BackfillScope('2026-01-01', '2026-12-31', null, [BackfillFamily::Sales]);

        $page = $this->adapter()->candidates($scope, $first, 1, 0);

        $this->assertSame([$second], array_map(static fn (BackfillDocument $document): int => $document->id, $page));
        $this->assertSame([$first], array_map(static fn (BackfillDocument $document): int => $document->id, $this->adapter()->candidates($scope, 0, 1, 0)));
    }

    public function testPlan_RefusesASaleThatIsNotCompletedAnyMoreOrIsGone(): void
    {
        $saleId = $this->legacyInvoice('2026-03-10');
        $row    = $this->saleRow($saleId);

        $this->accountingDb()->table('sales')->where('sale_id', $saleId)->update(['sale_status' => SUSPENDED]);
        $this->assertSame(BackfillRefusal::DocumentChanged, $this->adapter()->plan(new BackfillDocument(BackfillFamily::Sales, $saleId, '2026-03-10', (int) $row->branch_id), null, 0)->refusal);

        $this->assertSame(BackfillRefusal::DocumentChanged, $this->adapter()->plan(new BackfillDocument(BackfillFamily::Sales, 987654, '2026-03-10', 1), null, 0)->refusal);
    }

    public function testPlan_ReadsThePreviewsOfItsOwnBatchAsNothingAndThoseOfAnotherAsPartOfTheDocumentBeingInTheBooks(): void
    {
        $saleId    = $this->legacyInvoice('2026-03-10', [$this->payment(lang('Sales.cash'), '816.50')]);
        $paymentId = (int) $this->accountingDb()->table('sales_payments')->where('sale_id', $saleId)->get()->getRow()->payment_id;
        $batchId   = $this->previewBatch();

        $this->previewOfSource('sale_payment', $paymentId, 1);

        $row      = $this->saleRow($saleId);
        $document = new BackfillDocument(BackfillFamily::Sales, $saleId, '2026-03-10', (int) $row->branch_id);

        $this->assertSame(BackfillRefusal::PartlyPosted, $this->adapter()->plan($document, null, 0)->refusal);
        $this->assertSame(BackfillRefusal::PartlyPosted, $this->adapter()->plan($document, null, $batchId + 1)->refusal);
        $this->assertNull($this->adapter()->plan($document, null, $batchId)->refusal);
    }

    public function testCandidates_LeavesOutASaleWhoseStockMovementsAreInTheBooksAlreadyAndOneAnotherBatchHolds(): void
    {
        $held    = $this->legacyInvoice('2026-03-10');
        $moved   = $this->legacyInvoice('2026-03-11');
        $scope   = new BackfillScope('2026-01-01', '2026-12-31', null, [BackfillFamily::Sales]);
        $batchId = $this->previewBatch();

        $this->previewOfSource('sale', $held, 1);
        $this->accountingDb()->table('accounting_stock_entries')->insert(['company_id' => 1, 'branch_id' => 1, 'source_type' => 'sale', 'source_id' => $moved, 'entry_type' => 'cogs', 'revision' => 1, 'line_no' => 1, 'kind' => 'posting', 'entry_date' => '2026-03-11', 'item_id' => $this->accountingDb()->table('sales_items')->where('sale_id', $moved)->get()->getRow()->item_id, 'location_id' => 1, 'quantity' => '-1', 'value' => '-1']);

        $this->assertSame([], $this->adapter()->candidates($scope, 0, 10, 0));
        $this->assertSame([$held], array_map(static fn (BackfillDocument $document): int => $document->id, $this->adapter()->candidates($scope, 0, 10, $batchId)));
        $this->assertSame(1, $this->adapter()->waiting($scope, $batchId));
        $this->assertSame(0, $this->adapter()->waiting($scope));
    }
}
