<?php

namespace Tests\Libraries\Purchases;

use App\Libraries\Accounting\PaymentState;
use App\Libraries\Accounting\ReceivingType;
use App\Libraries\Accounting\ReturnState;
use App\Libraries\Numbering\DocumentType;
use App\Libraries\Purchases\PurchaseInvoiceFilters;
use App\Libraries\Purchases\PurchaseInvoiceList;
use App\Libraries\Purchases\PurchaseInvoiceRow;
use App\Libraries\Roles\RoleService;
use CodeIgniter\Database\Query;
use CodeIgniter\Events\Events;
use Tests\Support\FamilyReportTestCase;

/**
 * The purchase invoices and the returns to suppliers as a list that is read a page at a time.
 *
 * @internal
 */
final class PurchaseInvoiceListTest extends FamilyReportTestCase
{
    private PurchaseInvoiceList $list;

    protected function setUp(): void
    {
        parent::setUp();

        $this->list = new PurchaseInvoiceList();
    }

    /**
     * @param list<PurchaseInvoiceRow> $rows
     *
     * @return list<int>
     */
    private function idsOf(array $rows): array
    {
        $ids = array_map(static fn (PurchaseInvoiceRow $row): int => $row->receivingId, $rows);
        sort($ids);

        return $ids;
    }

    /**
     * @param array<string, mixed> $filters
     *
     * @return list<PurchaseInvoiceRow>
     */
    private function listed(array $filters = [], ?array $branchIds = null, string $sort = 'date', string $order = 'desc', ?PurchaseInvoiceList $list = null): array
    {
        return ($list ?? $this->list)->search($branchIds, PurchaseInvoiceFilters::fromInput($filters), $sort, $order, 100, 0, $this->employeeId)->rows;
    }

    private function rowOf(int $receivingId): PurchaseInvoiceRow
    {
        foreach ($this->listed() as $row) {
            if ($row->receivingId === $receivingId) {
                return $row;
            }
        }

        $this->fail("The purchase {$receivingId} is not in the list");
    }

    /**
     * @param callable(): void $then
     */
    private function withBooks(bool $enabled, callable $then): void
    {
        $previous = $this->accountingDb()->table('app_config')->where('key', 'accounting_enabled')->get()->getRow();

        $this->setConfigValue('accounting_enabled', $enabled ? '1' : '0');

        try {
            $then();
        } finally {
            $this->setConfigValue('accounting_enabled', $previous === null ? '0' : (string) $previous->value);
        }
    }

    public function testSearch_ListsReceiveAndReturnDocumentsAndNeverTransfersOrOpeningBalances(): void
    {
        $ids = $this->market();

        $this->assertSame([$ids['big'], $ids['small'], $ids['note'], $ids['other']], $this->idsOf($this->listed()));

        $this->accountingDb()->table('receivings')->where('receiving_id', $ids['big'])->update(['receiving_type' => 'requisition']);
        $this->accountingDb()->table('receivings')->where('receiving_id', $ids['other'])->update(['receiving_type' => 'opening']);

        $this->assertSame([$ids['small'], $ids['note']], $this->idsOf($this->listed()));
    }

    public function testSearch_PagesInSqlAndCountsTheWholeFilter(): void
    {
        $ids   = $this->market();
        $every = [$ids['big'], $ids['small'], $ids['note'], $ids['other']];
        $found = [];

        foreach ([0, 2] as $offset) {
            $page = $this->list->search(null, new PurchaseInvoiceFilters(), 'receiving_id', 'asc', 2, $offset, $this->employeeId);

            $this->assertSame(4, $page->total);
            $this->assertCount(2, $page->rows);

            array_push($found, ...$this->idsOf($page->rows));
        }

        sort($found);

        $this->assertSame($every, $found);
    }

    public function testSearch_DatesADocumentByItsTypedDayOrElseTheDayItWasSaved(): void
    {
        $typed = $this->purchaseDated('100.00', '2026-01-15');
        $saved = $this->purchaseOf('60.00');

        $this->assertSame('2026-01-15', $this->rowOf($typed)->date);
        $this->assertSame($this->today(), $this->rowOf($saved)->date);
        $this->assertSame([$typed], $this->idsOf($this->listed(['date_from' => '2026-01-01', 'date_to' => '2026-01-31'])));
        $this->assertSame([$saved], $this->idsOf($this->listed(['date_from' => $this->today(), 'date_to' => $this->today()])));
    }

    public function testSearch_NumbersADocumentByItsFallbackWhenNoSequenceGaveItANumber(): void
    {
        $ids = $this->market();

        $this->assertSame(DocumentType::Purchase->fallback($ids['big']), $this->rowOf($ids['big'])->number);
        $this->assertSame(DocumentType::DebitNote->fallback($ids['note']), $this->rowOf($ids['note'])->number);
        $this->assertSame(DocumentType::Purchase->fallback($ids['small']), $this->rowOf($ids['note'])->originNumber);
    }

    public function testSearch_SearchesSupplierAndNumberAndTakesWildcardsLiterally(): void
    {
        $ids = $this->market();

        $this->assertSame([$ids['other']], $this->idsOf($this->listed(['search' => 'Najd'])));
        $this->assertSame([$ids['big']], $this->idsOf($this->listed(['search' => 'RECV ' . $ids['big']])));
        $this->assertSame([], $this->idsOf($this->listed(['search' => '%'])));
        $this->assertSame([], $this->idsOf($this->listed(['search' => '_'])));
    }

    public function testSearch_CutsDownToTheBranchesGiven(): void
    {
        $ids      = $this->market();
        $branchId = (int) $this->accountingDb()->table('receivings')->where('receiving_id', $ids['big'])->get()->getRow()->branch_id;
        $every    = [$ids['big'], $ids['small'], $ids['note'], $ids['other']];

        $this->assertSame($every, $this->idsOf($this->listed([], [$branchId])));
        $this->assertSame([], $this->listed([], []));
        $this->assertSame([], $this->listed([], [$branchId + 1000]));
    }

    public function testSearch_ShowsAnOwnOnlyEmployeeJustTheirOwnDocuments(): void
    {
        $ids    = $this->market();
        $author = $this->employeeId;
        $item   = $this->item('0.00');

        $this->workInEveryBranch();

        $other = $this->employeeId;
        $mine  = $this->buy([$this->purchaseLine($item, 0, '1', '60.00')]);

        (new RoleService())->setRecordScope($author, 'own', null);

        $own      = $this->list->search(null, new PurchaseInvoiceFilters(), 'receiving_id', 'asc', 100, 0, $author)->rows;
        $everyone = $this->list->search(null, new PurchaseInvoiceFilters(), 'receiving_id', 'asc', 100, 0, $other)->rows;

        $this->assertSame([$ids['big'], $ids['small'], $ids['note'], $ids['other']], $this->idsOf($own));
        $this->assertSame([$ids['big'], $ids['small'], $ids['note'], $ids['other'], $mine], $this->idsOf($everyone));
        $this->assertNull($this->list->row(null, $mine, $author));
        $this->assertNull($this->list->row(null, 987654, $author));
    }

    public function testSearch_FallsBackToTheDefaultOrderForAColumnOutsideTheWhitelist(): void
    {
        $this->market();

        $default = $this->idsOf($this->list->search(null, new PurchaseInvoiceFilters(), 'date', 'desc', 100, 0, $this->employeeId)->rows);
        $hostile = $this->list->search(null, new PurchaseInvoiceFilters(), 'receiving_id; DROP TABLE ospos_receivings', 'sideways', 100, 0, $this->employeeId);

        $this->assertSame($default, $this->idsOf($hostile->rows));
        $this->assertSame(4, $hostile->total);
    }

    public function testSearch_GivesTheTotalWithTaxAsThePrintedInvoiceDoes(): void
    {
        $item = $this->item('0.00');
        $id   = $this->buy([$this->purchaseLine($item, 0, '2', '100.00', '15')]);

        $this->assertAmount('230.00', $this->rowOf($id)->total);
        $this->assertAmount('230.00', $this->rowOf($id)->due);
    }

    public function testSearch_ReadsPaidAndDueFromTheLedgerWhileTheBooksHoldThePurchase(): void
    {
        $id = $this->purchaseOf('100.00');

        $this->payPurchase($id, '40.00');

        $row     = $this->rowOf($id);
        $figures = $this->supplierPosition()->purchase($id);

        $this->assertNotNull($figures);
        $this->assertAmount($figures->settled(), $row->paid);
        $this->assertAmount($figures->open(), $row->due);
        $this->assertAmount('40.00', $row->paid);
        $this->assertAmount('60.00', $row->due);
        $this->assertSame(PaymentState::Partial, $row->state);
    }

    public function testSearch_ReadsPaidAndDueFromThePurchaseWhenTheBooksAreOff(): void
    {
        $cash = $this->purchaseOf('100.00', null, lang('Sales.cash'));
        $due  = $this->purchaseOf('100.00');

        $this->payPurchase($due, '40.00');

        $this->withBooks(false, function () use ($cash, $due): void {
            $this->assertSame(PaymentState::Paid, $this->rowOf($cash)->state);
            $this->assertAmount('100.00', $this->rowOf($cash)->paid);
            $this->assertSame(PaymentState::Partial, $this->rowOf($due)->state);
            $this->assertAmount('40.00', $this->rowOf($due)->paid);
            $this->assertAmount('60.00', $this->rowOf($due)->due);
        });
    }

    public function testSearch_TouchesNoAccountingTableWhileTheBooksAreOff(): void
    {
        $this->purchaseOf('100.00');

        $this->withBooks(false, function (): void {
            $asked    = [];
            $listener = static function (Query $query) use (&$asked): void {
                $asked[] = $query->getQuery();
            };
            Events::on('DBQuery', $listener);

            try {
                $this->list->search(null, new PurchaseInvoiceFilters(payment: 'unpaid'), 'paid', 'asc', 25, 0, $this->employeeId);
                $this->list->sums(null, new PurchaseInvoiceFilters(), $this->employeeId);
            } finally {
                Events::removeListener('DBQuery', $listener);
            }

            $this->assertNotSame([], $asked);

            foreach ($asked as $sql) {
                $this->assertDoesNotMatchRegularExpression('/(FROM|JOIN)\s+`?[a-z_]*accounting_/i', $sql, 'The list read an accounting table while the books are off');
            }
        });
    }

    public function testSearch_FiltersByPaymentStateBeforePaging(): void
    {
        $unpaid  = $this->purchaseOf('100.00');
        $partial = $this->purchaseOf('100.00');
        $paid    = $this->purchaseOf('100.00', null, lang('Sales.cash'));

        $this->payPurchase($partial, '30.00');

        foreach (['unpaid' => $unpaid, 'partial' => $partial, 'paid' => $paid] as $state => $expected) {
            $page = $this->list->search(null, PurchaseInvoiceFilters::fromInput(['payment' => $state]), 'date', 'desc', 1, 0, $this->employeeId);

            $this->assertSame(1, $page->total, $state);
            $this->assertSame([$expected], $this->idsOf($page->rows), $state);
        }
    }

    public function testSearch_CountsWhatWasPaidBeyondTheTotalAmongThePaid(): void
    {
        $id = $this->purchaseOf('100.00');

        $this->payPurchase($id, '150.00');

        $this->assertSame(PaymentState::Excess, $this->rowOf($id)->state);
        $this->assertSame([$id], $this->idsOf($this->listed(['payment' => 'paid'])));
        $this->assertSame([], $this->idsOf($this->listed(['payment' => 'partial'])));
        $this->assertSame([], $this->idsOf($this->listed(['payment' => 'unpaid'])));
    }

    public function testSearch_LeavesReturnsOutOfEveryPaymentStateFilter(): void
    {
        $ids = $this->market();

        foreach (PurchaseInvoiceFilters::PAYMENTS as $state) {
            $this->assertNotContains($ids['note'], $this->idsOf($this->listed(['payment' => $state])), $state);
        }
    }

    public function testSearch_FiltersByPurchaseStatusIncludingTheReturnedOnes(): void
    {
        $ids = $this->market();

        $this->assertSame([$ids['big'], $ids['small'], $ids['other']], $this->idsOf($this->listed(['status' => 'received'])));
        $this->assertSame([$ids['note']], $this->idsOf($this->listed(['status' => 'return'])));
        $this->assertSame([$ids['small']], $this->idsOf($this->listed(['status' => 'has_returns'])));
        $this->assertSame([$ids['small']], $this->idsOf($this->listed(['status' => 'partly_returned'])));
        $this->assertSame([], $this->idsOf($this->listed(['status' => 'fully_returned'])));
        $this->assertSame(ReturnState::Partial, $this->rowOf($ids['small'])->returnState);
        $this->assertSame(ReturnState::None, $this->rowOf($ids['big'])->returnState);
    }

    public function testSearch_MarksOverdueOnlyAfterTheDueDateAndOnlyWhileTheBooksAreOn(): void
    {
        $late   = $this->purchaseDated('100.00', date('Y-m-d', strtotime('-40 days')), 10);
        $notYet = $this->purchaseDated('100.00', date('Y-m-d', strtotime('-2 days')), 30);

        $this->assertGreaterThan(0, $this->rowOf($late)->overdueDays);
        $this->assertSame(0, $this->rowOf($notYet)->overdueDays);
        $this->assertSame([$late], $this->idsOf($this->listed(['payment' => 'overdue'])));

        $this->withBooks(false, function () use ($late): void {
            $this->assertSame(0, $this->rowOf($late)->overdueDays);
            $this->assertSame([], $this->idsOf($this->listed(['payment' => 'overdue'])));
        });
    }

    public function testSearch_RefusesWithTheLimitInsteadOfCuttingTheRowsWhenAFilterNeedsTheFigures(): void
    {
        $this->market();

        $small = new PurchaseInvoiceList(null, null, 2);
        $page  = $small->search(null, PurchaseInvoiceFilters::fromInput(['payment' => 'unpaid']), 'date', 'desc', 25, 0, $this->employeeId);

        $this->assertSame(PurchaseInvoiceList::TOO_MANY, $page->refusal);
        $this->assertSame([], $page->rows);
        $this->assertNull($this->list->search(null, PurchaseInvoiceFilters::fromInput(['payment' => 'unpaid']), 'date', 'desc', 25, 0, $this->employeeId)->refusal);
        $this->assertCount(4, $small->search(null, new PurchaseInvoiceFilters(), 'date', 'desc', 25, 0, $this->employeeId)->rows);
    }

    public function testSearch_SortsByTotalPaidAndDueWithinTheLimit(): void
    {
        $small = $this->purchaseOf('50.00');
        $mid   = $this->purchaseOf('100.00');
        $big   = $this->purchaseOf('200.00');

        $this->payPurchase($mid, '90.00');

        $this->assertSame([$big, $mid, $small], array_map(static fn (PurchaseInvoiceRow $row): int => $row->receivingId, $this->listed([], null, 'total', 'desc')));
        $this->assertSame([$small, $mid, $big], array_map(static fn (PurchaseInvoiceRow $row): int => $row->receivingId, $this->listed([], null, 'total', 'asc')));
        $this->assertSame($mid, $this->listed([], null, 'paid', 'desc')[0]->receivingId);
        $this->assertSame($big, $this->listed([], null, 'due', 'desc')[0]->receivingId);
    }

    public function testSearch_NamesAForeignDocumentAndNeverAddsItToTheBase(): void
    {
        $this->enableDollars();

        $foreign = $this->foreignPurchase('4', '50.00');
        $base    = $this->purchaseOf('100.00');

        $this->assertSame('USD', $this->rowOf($foreign)->currencyCode);
        $this->assertNull($this->rowOf($base)->currencyCode);
        $this->assertAmount('200.00', $this->rowOf($foreign)->due, 'a foreign purchase owes what it came to in its own currency');

        $sums = $this->list->sums(null, new PurchaseInvoiceFilters(), $this->employeeId)->byCurrency;

        $this->assertSame(['', 'USD'], array_keys($sums));
        $this->assertAmount('100.00', $sums['']['net']);
        $this->assertAmount('200.00', $sums['USD']['net']);
    }

    public function testSearch_AsksTheSameFewQueriesWhateverThePageSize(): void
    {
        $this->market();
        $this->purchaseOf('10.00');
        $this->purchaseOf('20.00', $this->anotherSupplier('Third Co'));

        $db        = $this->accountingDb();
        $questions = static fn (): int => (int) $db->query("SHOW SESSION STATUS LIKE 'Questions'")->getRow()->Value;
        $asked     = [];

        foreach ([2, 6] as $size) {
            $before        = $questions();
            $page          = $this->list->search(null, new PurchaseInvoiceFilters(), 'date', 'desc', $size, 0, $this->employeeId);
            $asked[$size]  = $questions() - $before - 1;

            $this->assertCount($size, $page->rows);
        }

        $this->assertLessThanOrEqual($asked[2] + 1, $asked[6]);
        $this->assertLessThanOrEqual(30, $asked[6]);
    }

    public function testSums_AddTheWholeFilterNetOfReturnsPerCurrency(): void
    {
        $this->market();

        $sums = $this->list->sums(null, new PurchaseInvoiceFilters(), $this->employeeId);

        $this->assertNull($sums->refusal);
        $this->assertSame(['' => 4], ['' => $sums->byCurrency['']['count']]);
        $this->assertAmount('395.00', $sums->byCurrency['']['net']);
        $this->assertAmount('50.00', $sums->byCurrency['']['returns']);
    }

    public function testSums_GiveNothingAboveTheLimitInsteadOfAWrongNumber(): void
    {
        $this->market();

        $sums = (new PurchaseInvoiceList(null, null, 2))->sums(null, new PurchaseInvoiceFilters(), $this->employeeId);

        $this->assertSame(PurchaseInvoiceList::SUMS_TOO_MANY, $sums->refusal);
        $this->assertSame([], $sums->byCurrency);
    }

    public function testRow_AnswersTheSameForADocumentOfAnotherBranchAndAMissingOne(): void
    {
        $ids = $this->market();

        $this->assertNotNull($this->list->row(null, $ids['big'], $this->employeeId));
        $this->assertNull($this->list->row([], $ids['big'], $this->employeeId));
        $this->assertNull($this->list->row(null, 987654, $this->employeeId));
        $this->assertNull($this->list->row([], 987654, $this->employeeId));
    }

    public function testSuppliers_OffersOnlySuppliersOfDocumentsTheReaderMaySee(): void
    {
        $ids = $this->market();

        $found = $this->list->suppliers(null, 'Najd', $this->employeeId);

        $this->assertSame([['id' => $ids['second'], 'name' => 'Najd Trading']], $found);
        $this->assertSame([], $this->list->suppliers([], 'Najd', $this->employeeId));
        $this->assertSame([], $this->list->suppliers(null, '%', $this->employeeId));
    }

    public function testAll_RefusesMoreThanAFileMayHold(): void
    {
        $this->market();

        $this->assertCount(4, $this->list->all(null, new PurchaseInvoiceFilters(), 'date', 'desc', $this->employeeId) ?? []);
        $this->assertNull((new PurchaseInvoiceList(null, null, 10000, 3))->all(null, new PurchaseInvoiceFilters(), 'date', 'desc', $this->employeeId));
    }

    public function testSearch_GivesAReturnNoPaymentStateAndKeepsItsTypeAndNegativeTotal(): void
    {
        $ids  = $this->market();
        $note = $this->rowOf($ids['note']);

        $this->assertSame(ReceivingType::Return, $note->type);
        $this->assertNull($note->state);
        $this->assertTrue($note->isReturn());
        $this->assertAmount('50.00', $note->total);
        $this->assertAmount('-50.00', $note->signedTotal());
    }
}
