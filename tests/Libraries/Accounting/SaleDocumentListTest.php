<?php

namespace Tests\Libraries\Accounting;

use App\Libraries\Accounting\QuoteLifecycle;
use App\Libraries\Accounting\QuoteStatus;
use App\Libraries\Accounting\SaleDocumentList;
use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\DatabaseTestTrait;
use Config\Database;
use Tests\Support\AccountingFixtureTrait;
use Tests\Support\EmployeeFixtureTrait;
use Tests\Support\ItemFixtureTrait;
use Tests\Support\SaleAccountingFixtureTrait;

/**
 * The two lists of the documents that wait: draft invoices and quotes, what each is called, what it
 * comes to, and which of them a reader of some branches sees.
 */
class SaleDocumentListTest extends CIUnitTestCase
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

    private SaleDocumentList $list;

    public static function setUpBeforeClass(): void
    {
        $seeder = Database::seeder('tests');
        $seeder->call('TestDatabaseBootstrapSeeder');
    }

    protected function setUp(): void
    {
        parent::setUp();

        $this->startSelling();

        $this->list = new SaleDocumentList($this->accountingDb());
    }

    protected function tearDown(): void
    {
        $this->stopSelling();

        parent::tearDown();
    }

    /**
     * @param list<object> $rows
     *
     * @return list<int>
     */
    private function ids(array $rows): array
    {
        return array_map(static fn (object $row): int => (int) $row->sale_id, $rows);
    }

    private function inBranch(int $branchId, string $type = 'draft'): int
    {
        $service = $this->item('0', false);
        $lines   = [$this->line($service, 0, '1', '100', '0', '0', PERCENT, $this->branchLocation($branchId))];

        return $type === 'draft'
            ? $this->draftInvoice($lines)
            : $this->quote(null, $lines);
    }

    public function testDrafts_ListsAtMostTheNewestTwoHundredSoThatTheOldestOneIsLeftOut(): void
    {
        $ids = $this->suspendedRows(SaleDocumentList::LIMIT + 1, SALE_TYPE_INVOICE);

        $listed = $this->ids($this->list->drafts(null));

        $this->assertCount(200, $listed);
        $this->assertSame(max($ids), $listed[0]);
        $this->assertSame($ids[1], $listed[199]);
        $this->assertNotContains($ids[0], $listed);
    }

    public function testDrafts_ListsEveryDraftWhileThereAreNoMoreThanTheLimit(): void
    {
        $ids = $this->suspendedRows(SaleDocumentList::LIMIT, SALE_TYPE_INVOICE);

        $this->assertEqualsCanonicalizing($ids, $this->ids($this->list->drafts(null)));
    }

    public function testQuotes_ListsAtMostTheNewestTwoHundredSoThatTheOldestOneIsLeftOut(): void
    {
        $ids = $this->suspendedRows(SaleDocumentList::LIMIT + 1, SALE_TYPE_QUOTE);

        $listed = $this->ids($this->list->quotes(null));

        $this->assertCount(200, $listed);
        $this->assertNotContains($ids[0], $listed);
        $this->assertContains($ids[1], $listed);
    }

    public function testDrafts_ListsTheSuspendedInvoicesNewestFirstAndNothingElse(): void
    {
        $cycle     = $this->referenceInvoice();
        $first     = $this->draftInvoice($cycle['items']);
        $second    = $this->draftInvoice($cycle['items']);
        $cancelled = $this->draftInvoice($cycle['items']);

        $this->sales->update_sale_status($cancelled, CANCELED);
        $this->save($cycle['items'], $cycle['taxes']);
        $this->quote();
        $this->save([$this->line($this->item('0', false), 0, '1', '50')], [], [$this->payment(lang('Sales.cash'), '50.00')], SALE_TYPE_POS, -1, true, null, SUSPENDED);

        $this->assertSame([$second, $first], $this->ids($this->list->drafts(null)));
    }

    public function testDrafts_NamesEachByItsTemporaryNumberOrByTheNumberTypedOnIt(): void
    {
        $cycle = $this->referenceInvoice();
        $plain = $this->draftInvoice($cycle['items']);
        $typed = $this->draftInvoice($cycle['items']);

        $this->accountingDb()->table('sales')->where('sale_id', $typed)->update(['invoice_number' => 'HAND-9']);

        $labels = array_column($this->list->drafts(null), 'label', 'sale_id');

        $this->assertSame('draft-' . $plain, $labels[$plain]);
        $this->assertSame('HAND-9', $labels[$typed]);
    }

    public function testDrafts_ShowsWhatTheLinesComeToAfterTheirDiscountsAndTheDiscountOnTheWholeAndTheNamesOfTheCustomerAndTheEmployee(): void
    {
        $cycle = $this->discountedReferenceInvoice();
        $draft = $this->save($cycle['items'], [], [], SALE_TYPE_INVOICE, -1, true, null, SUSPENDED, NEW_ENTRY, $cycle['discount']);
        $row   = $this->list->drafts(null)[0];

        $this->assertSame($draft, (int) $row->sale_id);
        $this->assertSame('639.00', bcadd((string) $row->subtotal, '0', 2), '740 less 30 of the lines and 71 of the discount on the whole');
        $this->assertSame('Omar Khaled', $row->customer_name);
        $this->assertNotSame('', trim((string) $row->employee_name));
        $this->assertSame('HQ', $row->branch_code);
    }

    public function testDrafts_ShowsTheSubtotalOfALineWithAFixedDiscountPerUnit(): void
    {
        $service = $this->item('0', false);

        $this->draftInvoice([$this->line($service, 0, '3', '100', '0', '10', FIXED)]);

        $this->assertSame('270.00', bcadd((string) $this->list->drafts(null)[0]->subtotal, '0', 2));
    }

    public function testDrafts_ShowsANilSubtotalForADraftWithNoLines(): void
    {
        $draft = $this->draftInvoice([$this->line($this->item('0', false), 0, '1', '10')]);

        $this->accountingDb()->table('sales_items')->where('sale_id', $draft)->delete();

        $this->assertSame('0.00', bcadd((string) $this->list->drafts(null)[0]->subtotal, '0', 2));
    }

    public function testDrafts_ListsOnlyTheBranchesOfTheReader(): void
    {
        $second = $this->createBranch();
        $inHq   = $this->draftInvoice();
        $inTwo  = $this->inBranch($second);

        $this->assertEqualsCanonicalizing([$inHq, $inTwo], $this->ids($this->list->drafts(null)), 'every branch');
        $this->assertSame([$inTwo], $this->ids($this->list->drafts([$second])));
        $this->assertSame([$inHq], $this->ids($this->list->drafts([1])));
        $this->assertEqualsCanonicalizing([$inHq, $inTwo], $this->ids($this->list->drafts([1, $second])));
        $this->assertSame([], $this->list->drafts([]), 'a reader of no branch sees none');
    }

    public function testQuotes_ListsTheSuspendedQuotesNewestFirstAndNothingElse(): void
    {
        $cycle     = $this->referenceInvoice();
        $first     = $this->quote(null, $cycle['items']);
        $second    = $this->quote(QuoteStatus::Draft, $cycle['items']);
        $cancelled = $this->quote();

        $this->sales->update_sale_status($cancelled, CANCELED);
        $this->draftInvoice($cycle['items']);
        $this->save($cycle['items'], $cycle['taxes']);

        $this->assertSame([$second, $first], $this->ids($this->list->quotes(null)));
    }

    public function testQuotes_NamesEachByItsNumberItsTemporaryNumberWhileADraftOrItsIdWhenALegacyQuoteHasNone(): void
    {
        $numbered = $this->quote();
        $draft    = $this->quote(QuoteStatus::Draft);
        $legacy   = $this->quote();

        $this->accountingDb()->table('sales')->where('sale_id', $numbered)->update(['quote_number' => 'Q26000042']);
        $this->accountingDb()->table('sales')->where('sale_id', $legacy)->update(['quote_status' => null]);

        $labels = array_column($this->list->quotes(null), 'label', 'sale_id');

        $this->assertSame('Q26000042', $labels[$numbered]);
        $this->assertSame('draft-' . $draft, $labels[$draft]);
        $this->assertSame('#' . $legacy, $labels[$legacy]);
    }

    public function testQuotes_ReadsEachStateAndAQuoteWithNoStateAsOpen(): void
    {
        $open     = $this->quote();
        $legacy   = $this->quote();
        $approved = $this->quote();

        $this->accountingDb()->table('sales')->where('sale_id', $legacy)->update(['quote_status' => null]);
        $this->accountingDb()->table('sales')->where('sale_id', $approved)->update(['quote_status' => QuoteStatus::Approved->value]);

        $states = array_column($this->list->quotes(null), 'status', 'sale_id');

        $this->assertSame(QuoteStatus::Open, $states[$open]);
        $this->assertSame(QuoteStatus::Open, $states[$legacy]);
        $this->assertSame(QuoteStatus::Approved, $states[$approved]);
    }

    public function testQuotes_CanBeNarrowedToOneStateAndOpenAlsoTakesTheQuotesThatHaveNone(): void
    {
        $open     = $this->quote();
        $legacy   = $this->quote();
        $approved = $this->quote();
        $draft    = $this->quote(QuoteStatus::Draft);

        $this->accountingDb()->table('sales')->where('sale_id', $legacy)->update(['quote_status' => null]);
        $this->accountingDb()->table('sales')->where('sale_id', $approved)->update(['quote_status' => QuoteStatus::Approved->value]);

        $this->assertEqualsCanonicalizing([$open, $legacy], $this->ids($this->list->quotes(null, QuoteStatus::Open)));
        $this->assertSame([$approved], $this->ids($this->list->quotes(null, QuoteStatus::Approved)));
        $this->assertSame([$draft], $this->ids($this->list->quotes(null, QuoteStatus::Draft)));
        $this->assertSame([], $this->list->quotes(null, QuoteStatus::Ordered));
    }

    public function testQuotes_NamesTheInvoiceMadeFromAConvertedQuoteOnceItIsIssued(): void
    {
        $this->allowEmployeeEverywhere();

        $cycle = $this->referenceInvoice();
        $quote = $this->quote(null, $cycle['items']);
        $other = $this->quote(null, $cycle['items']);

        $this->save($cycle['items'], [], [], SALE_TYPE_INVOICE, -1, true, null, SUSPENDED, NEW_ENTRY, null, quoteSourceId: $quote);

        $invoices = array_column($this->list->quotes(null), 'invoice_sale_id', 'sale_id');

        $this->assertNull($invoices[$quote], 'a draft is not the invoice yet');

        $invoice = $this->save($cycle['items'], $cycle['taxes'], [], SALE_TYPE_INVOICE, -1, true, null, COMPLETED, NEW_ENTRY, null, quoteSourceId: $other);

        $invoices = array_column($this->list->quotes(null), 'invoice_sale_id', 'sale_id');

        $this->assertSame($invoice, (int) $invoices[$other]);
        $this->assertSame(QuoteStatus::Converted, (new QuoteLifecycle())->statusOf($other));
    }

    public function testQuotes_ListsOnlyTheBranchesOfTheReader(): void
    {
        $second = $this->createBranch();
        $inHq   = $this->quote();
        $inTwo  = $this->inBranch($second, 'quote');

        $this->assertEqualsCanonicalizing([$inHq, $inTwo], $this->ids($this->list->quotes(null)));
        $this->assertSame([$inTwo], $this->ids($this->list->quotes([$second])));
        $this->assertSame([$inHq], $this->ids($this->list->quotes([1])));
        $this->assertSame([], $this->list->quotes([]));
    }

    public function testDrafts_WritesNothingToTheSalesItReads(): void
    {
        $this->draftInvoice();
        $this->quote();

        $before = $this->accountingDb()->table('sales')->get()->getResultArray();

        $this->list->drafts(null);
        $this->list->quotes(null);

        $this->assertSame($before, $this->accountingDb()->table('sales')->get()->getResultArray());
    }
}
