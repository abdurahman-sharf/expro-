<?php

namespace Tests\Libraries\Accounting;

use App\Libraries\Accounting\AccountingGuard;
use App\Libraries\Accounting\CreditSource;
use App\Libraries\Accounting\DebitNoteList;
use App\Libraries\Accounting\SupplierAdvances;
use App\Libraries\Numbering\DocumentNumbering;
use App\Libraries\Numbering\DocumentType;
use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\DatabaseTestTrait;
use Config\Database;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Support\AccountingFixtureTrait;
use Tests\Support\EmployeeFixtureTrait;
use Tests\Support\ItemFixtureTrait;
use Tests\Support\PurchaseAccountingFixtureTrait;
use Tests\Support\SaleAccountingFixtureTrait;

/**
 * The returns of goods to suppliers as debit notes: their number, the purchase they take goods
 * back from, what they come to, what the supplier paid back at once and what of the rest was
 * applied against what the supplier is owed, cut down to the branches the reader works in. The
 * figures are the ones the account of the supplier holds.
 */
class DebitNoteListTest extends CIUnitTestCase
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

    private DebitNoteList $notes;

    public static function setUpBeforeClass(): void
    {
        $seeder = Database::seeder('tests');
        $seeder->call('TestDatabaseBootstrapSeeder');
    }

    protected function setUp(): void
    {
        parent::setUp();

        $this->startBuying();

        $this->notes = new DebitNoteList();
    }

    protected function tearDown(): void
    {
        $this->stopBuying();

        $db = $this->accountingDb();
        $db->table('document_numbers')->truncate();
        $db->table('document_sequences')->truncate();
        $db->table('accounting_audit_log')->where('entity_type', 'document_numbering')->delete();

        parent::tearDown();
    }

    private function daysAgo(int $days): string
    {
        return date('Y-m-d', strtotime("-{$days} days"));
    }

    /**
     * @param array<string, mixed> $filters
     *
     * @return list<object>
     */
    private function rows(?array $branchIds = null, array $filters = [], string $sort = 'date', string $order = 'desc'): array
    {
        return $this->notes->search($branchIds, $filters, $sort, $order, 100, 0)['rows'];
    }

    /**
     * @param list<object> $rows
     *
     * @return list<int>
     */
    private function idsOf(array $rows): array
    {
        return array_map(static fn (object $row): int => (int) $row->receiving_id, $rows);
    }

    /**
     * A return that gives back what the worth says of a purchase of one unit and has the supplier
     * pay it back in cash at once.
     */
    private function cashDebitNoteOf(int $receivingId, string $worth): int
    {
        $line  = $this->accountingDb()->table('receivings_items')->where('receiving_id', $receivingId)->get()->getRow();
        $units = bcdiv($worth, (string) $line->item_unit_price, 3);
        $id    = $this->buy([$this->purchaseLine((int) $line->item_id, 0, '-' . $units, (string) $line->item_unit_price)], -1, lang('Sales.cash'), $receivingId);

        $this->assertGreaterThan(0, $id);

        return $id;
    }

    public function testSearch_ListsEveryReturnToASupplierAsADebitNoteAndNothingElse(): void
    {
        $purchase = $this->purchaseOf('100.00');
        $note     = $this->debitNoteOf($purchase, '40.00');

        $this->payPurchase($purchase, '10.00');

        $advances = new SupplierAdvances();
        $clerk    = $this->employeeWithGrants([AccountingGuard::PAYABLES_MANAGE]);

        $advances->pay($this->supplierId, '5.00', $this->daysAgo(1), lang('Sales.cash'), null, '', '', $clerk);

        $result = $this->notes->search(null);

        $this->assertSame(1, $result['total']);
        $this->assertSame([$note], $this->idsOf($result['rows']));

        $row = $result['rows'][0];

        $this->assertSame($purchase, (int) $row->return_of_receiving_id);
        $this->assertSame($this->supplierId, (int) $row->supplier_id);
        $this->assertSame('Gulf Supplies', $row->supplier_name);
        $this->assertSame($this->employeeId, (int) $row->employee_id);
        $this->assertSame(1, (int) $row->branch_id);
        $this->assertSame('40.00', bcadd($row->amount, '0', 2));
    }

    public function testSearch_ListsNothingWhileThereIsNoReturn(): void
    {
        $this->purchaseOf('100.00');

        $this->assertSame(['total' => 0, 'rows' => []], $this->notes->search(null));
    }

    public function testSearch_ShowsTheReceivingNumberOfAReturnSavedBeforeThereWereNumbers(): void
    {
        $purchase = $this->purchaseOf('100.00');
        $note     = $this->debitNoteOf($purchase, '40.00');

        $row = $this->rows()[0];

        $this->assertSame(['RECV ' . $note, 'RECV ' . $purchase, 0], [$row->number, $row->origin_number, (int) $row->numbered]);
    }

    public function testSearch_NumbersADebitNoteInItsSequenceAndNamesThePurchaseByItsNumber(): void
    {
        (new DocumentNumbering())->configure(DocumentType::DebitNote, ['enabled' => true, 'prefix' => 'DN-', 'padding' => 3]);
        (new DocumentNumbering())->configure(DocumentType::Purchase, ['enabled' => true, 'prefix' => 'PO-', 'padding' => 4]);

        $purchase = $this->purchaseOf('100.00');
        $first    = $this->debitNoteOf($purchase, '10.00');
        $second   = $this->debitNoteOf($purchase, '20.00');

        $byId = [];

        foreach ($this->rows() as $row) {
            $byId[(int) $row->receiving_id] = [$row->number, $row->origin_number, (int) $row->numbered];
        }

        $this->assertSame(['DN-001', 'PO-0001', 1], $byId[$first]);
        $this->assertSame(['DN-002', 'PO-0001', 1], $byId[$second]);
    }

    public function testSearch_ShowsWhatWasAppliedAgainstThePurchaseTheDebitNoteCameFrom(): void
    {
        $purchase = $this->purchaseOf('100.00');
        $note     = $this->debitNoteOf($purchase, '40.00');

        $row = $this->rows()[0];

        $this->assertSame($note, (int) $row->receiving_id);
        $this->assertSame(['40.00', '0.00', '40.00', '0.00', DebitNoteList::APPLIED], [bcadd($row->amount, '0', 2), bcadd($row->refunded, '0', 2), bcadd($row->applied, '0', 2), bcadd($row->held, '0', 2), $row->state]);
        $this->assertSupplierTies();
    }

    public function testSearch_ShowsWhatTheSupplierPaidBackInCashAndNothingToApply(): void
    {
        $purchase = $this->purchaseOf('100.00', null, lang('Sales.cash'));
        $this->cashDebitNoteOf($purchase, '40.00');

        $row = $this->rows()[0];

        $this->assertSame(['40.00', '40.00', '0.00', '0.00', DebitNoteList::SETTLED_IN_CASH], [bcadd($row->amount, '0', 2), bcadd($row->refunded, '0', 2), bcadd($row->applied, '0', 2), bcadd($row->held, '0', 2), $row->state]);
        $this->assertSupplierTies();
    }

    public function testSearch_ShowsTheCreditHeldWithTheSupplierAndWhatTheNextPurchasesTookOfIt(): void
    {
        $purchase = $this->purchaseOf('100.00', null, lang('Sales.cash'));
        $note     = $this->debitNoteOf($purchase, '40.00');

        $figures = fn (): array => array_map(static fn (string $figure): string => bcadd($figure, '0', 2), [$this->rows()[0]->applied, $this->rows()[0]->held]);

        $this->assertSame(['0.00', '40.00'], $figures());
        $this->assertSame(DebitNoteList::HELD, $this->rows()[0]->state);

        $this->purchaseOf('30.00');

        $this->assertSame(['30.00', '10.00'], $figures());
        $this->assertSame(DebitNoteList::PARTLY_APPLIED, $this->rows()[0]->state);

        $this->purchaseOf('50.00');

        $this->assertSame(['40.00', '0.00'], $figures());
        $this->assertSame(DebitNoteList::APPLIED, $this->rows()[0]->state);
        $this->assertSame([$note], $this->idsOf($this->rows()));
        $this->assertSupplierTies();
    }

    public function testSearch_DoesNotCountWhatWasReleasedWhenThePurchaseItWasAppliedToWasDeleted(): void
    {
        $purchase = $this->purchaseOf('100.00', null, lang('Sales.cash'));
        $this->debitNoteOf($purchase, '40.00');
        $later = $this->purchaseOf('30.00');

        $this->assertSame('30.00', bcadd($this->rows()[0]->applied, '0', 2));
        $this->assertTrue($this->purchases->delete_value($later, $this->employeeId));

        $row = $this->rows()[0];

        $this->assertSame(['0.00', '40.00', DebitNoteList::HELD], [bcadd($row->applied, '0', 2), bcadd($row->held, '0', 2), $row->state]);
        $this->assertSupplierTies();
    }

    public function testSearch_DoesNotCountWhatWasReleasedWhenThePurchaseItWasAppliedToWasPaidInCashInstead(): void
    {
        $purchase = $this->purchaseOf('100.00', null, lang('Sales.cash'));
        $this->debitNoteOf($purchase, '40.00');
        $later = $this->purchaseOf('30.00');

        $this->assertSame('30.00', bcadd($this->rows()[0]->applied, '0', 2));

        $this->payPurchase($later, '30.00');

        $this->assertSame(1, $this->accountingDb()->table('supplier_credit_applications')->where('released_at IS NOT NULL')->countAllResults(), 'The credit was taken back from the purchase and the application kept as released');

        $row = $this->rows()[0];

        $this->assertSame(['0.00', '40.00', DebitNoteList::HELD], [bcadd($row->applied, '0', 2), bcadd($row->held, '0', 2), $row->state]);
        $this->assertSupplierTies();
    }

    public function testSearch_AgreesWithTheCreditTheLedgerOfTheSupplierHoldsForEveryDebitNote(): void
    {
        $paid  = $this->purchaseOf('100.00', null, lang('Sales.cash'));
        $open  = $this->purchaseOf('200.00');
        $first = $this->debitNoteOf($paid, '40.00');
        $cash  = $this->cashDebitNoteOf($paid, '10.00');
        $third = $this->debitNoteOf($open, '25.00');

        $sources = [];

        foreach ($this->supplierPosition()->credits as $source) {
            if ($source->type === CreditSource::DEBIT_NOTE) {
                $sources[$source->sourceId] = $source;
            }
        }

        $this->assertCount(3, $this->rows());

        foreach ($this->rows() as $row) {
            $source = $sources[(int) $row->receiving_id];

            $this->assertSame(bcadd($source->amount, '0', 2), bcadd(bcsub($row->amount, $row->refunded, 4), '0', 2), 'What the ledger holds as the credit of note ' . $row->receiving_id);
            $this->assertSame(bcadd($source->applied, '0', 2), bcadd($row->applied, '0', 2), 'What the ledger says was applied of note ' . $row->receiving_id);
        }

        $this->assertSame([$third, $cash, $first], $this->idsOf($this->rows()), 'Newest first');
        $this->assertSupplierTies();
    }

    public function testSearch_ComesToTheTotalOfTheLinesWithTheirTaxAndDiscount(): void
    {
        $item     = $this->item('0.00');
        $purchase = $this->buy([$this->purchaseLine($item, 0, '10', '50.00', '15', '10', PERCENT)]);

        $this->assertGreaterThan(0, $purchase);

        $note = $this->buy([$this->purchaseLine($item, 0, '-4', '50.00', '15', '10', PERCENT)], -1, null, $purchase);

        $this->assertGreaterThan(0, $note);
        $this->assertSame('207.00', bcadd($this->rows()[0]->amount, '0', 2), '4 units at 50 less 10% is 180, and 15% tax on it is 27');
        $this->assertSupplierTies();
    }

    public function testSearch_CutsTheRowsDownToTheBranchesGiven(): void
    {
        $branch   = $this->createBranch('B2');
        $location = $this->locationOfBranch($branch);
        $item     = $this->item('0.00');
        $other    = $this->buy([$this->purchaseLine($item, 0, '1', '100.00', '0', '0', PERCENT, $location)]);
        $own      = $this->purchaseOf('100.00');

        $otherNote = $this->buy([$this->purchaseLine($item, 0, '-0.2', '100.00', '0', '0', PERCENT, $location)], -1, null, $other);
        $ownNote   = $this->debitNoteOf($own, '10.00');

        $this->assertGreaterThan(0, $otherNote);
        $this->assertSame([$ownNote, $otherNote], $this->idsOf($this->rows(null)), 'Every branch');
        $this->assertSame([$ownNote], $this->idsOf($this->rows([1])));
        $this->assertSame([$otherNote], $this->idsOf($this->rows([$branch])));
        $this->assertSame([], $this->rows([]), 'Someone who works in no branch reads nothing');
        $this->assertSame(1, $this->notes->search([$branch])['total'], 'The count follows the same cut');
        $this->assertSame('B2', $this->rows([$branch])[0]->branch_code);
    }

    public function testSearch_FiltersByDateSupplierAndEmployee(): void
    {
        $other    = $this->anotherSupplier('Najd Trading');
        $purchase = $this->purchaseOf('100.00');
        $second   = $this->purchaseOf('100.00', $other);
        $first    = $this->debitNoteOf($purchase, '10.00');
        $theirs   = $this->debitNoteOf($second, '20.00', $other);

        $this->accountingDb()->table('receivings')->where('receiving_id', $first)->update(['receiving_time' => $this->daysAgo(8) . ' 10:00:00']);

        $buyer = $this->createEmployee(first_name: 'Huda', last_name: 'Saleh');

        $this->accountingDb()->table('receivings')->where('receiving_id', $theirs)->update(['employee_id' => $buyer]);

        $this->assertSame([$first], $this->idsOf($this->rows(null, ['supplier_id' => $this->supplierId])));
        $this->assertSame([$theirs], $this->idsOf($this->rows(null, ['supplier_id' => $other])));
        $this->assertSame([$theirs], $this->idsOf($this->rows(null, ['date_from' => $this->daysAgo(5)])));
        $this->assertSame([$first], $this->idsOf($this->rows(null, ['date_to' => $this->daysAgo(5)])));
        $this->assertSame([$first], $this->idsOf($this->rows(null, ['date_from' => $this->daysAgo(8), 'date_to' => $this->daysAgo(8)])), 'Both ends of a range are in it');
        $this->assertSame([$theirs], $this->idsOf($this->rows(null, ['employee_id' => $buyer])));
        $this->assertSame([], $this->rows(null, ['supplier_id' => $other, 'employee_id' => $this->employeeId]), 'Filters narrow together');
    }

    public function testSearch_SearchesTheNumberThePurchaseAndTheSupplier(): void
    {
        (new DocumentNumbering())->configure(DocumentType::DebitNote, ['enabled' => true, 'prefix' => 'DN-', 'padding' => 3]);

        $other    = $this->anotherSupplier('Najd Trading');
        $purchase = $this->purchaseOf('100.00');
        $second   = $this->purchaseOf('100.00', $other);
        $one      = $this->debitNoteOf($purchase, '10.00');
        $two      = $this->debitNoteOf($second, '20.00', $other);

        $this->assertSame([$two], $this->idsOf($this->rows(null, ['search' => 'DN-002'])), 'By number');
        $this->assertSame([$two], $this->idsOf($this->rows(null, ['search' => 'najd'])), 'By supplier');
        $this->assertSame([$one], $this->idsOf($this->rows(null, ['search' => 'RECV ' . $purchase])), 'By the number of the purchase it takes goods back from');
        $this->assertSame([], $this->rows(null, ['search' => 'nothing like this']));
    }

    public function testSearch_FindsAReturnSavedBeforeThereWereNumbersByItsReceivingNumber(): void
    {
        $purchase = $this->purchaseOf('100.00');
        $note     = $this->debitNoteOf($purchase, '10.00');

        $this->assertSame([$note], $this->idsOf($this->rows(null, ['search' => 'RECV ' . $note])));
    }

    public function testSearch_TakesALikeCharacterAsTextAndNotAsAPattern(): void
    {
        $other    = $this->anotherSupplier('Fifty% Trading');
        $purchase = $this->purchaseOf('100.00');
        $second   = $this->purchaseOf('100.00', $other);

        $this->debitNoteOf($purchase, '10.00');

        $theirs = $this->debitNoteOf($second, '20.00', $other);

        $this->assertSame([$theirs], $this->idsOf($this->rows(null, ['search' => '%'])), 'Only the name with a percent sign');
        $this->assertSame(0, count($this->rows(null, ['search' => '_'])));
    }

    public function testSearch_SortsByDateNumberAndSupplierOnly(): void
    {
        $other    = $this->anotherSupplier('Najd Trading');
        $purchase = $this->purchaseOf('100.00');
        $second   = $this->purchaseOf('100.00', $other);
        $a        = $this->debitNoteOf($purchase, '10.00');
        $b        = $this->debitNoteOf($second, '20.00', $other);
        $c        = $this->debitNoteOf($purchase, '30.00');

        $this->accountingDb()->table('receivings')->where('receiving_id', $a)->update(['receiving_time' => $this->daysAgo(8) . ' 10:00:00']);
        $this->accountingDb()->table('receivings')->where('receiving_id', $b)->update(['receiving_time' => $this->daysAgo(4) . ' 10:00:00']);

        $this->assertSame([$c, $b, $a], $this->idsOf($this->rows(null, [], 'date', 'desc')));
        $this->assertSame([$a, $b, $c], $this->idsOf($this->rows(null, [], 'date', 'asc')));
        $this->assertSame([$a, $b, $c], $this->idsOf($this->rows(null, [], 'number', 'asc')));
        $this->assertSame([$c, $a, $b], $this->idsOf($this->rows(null, [], 'supplier_name', 'asc')));

        $injected = $this->rows(null, [], 'r.receiving_time; DROP TABLE ospos_receivings', 'asc; DROP TABLE ospos_receivings');

        $this->assertSame([$c, $b, $a], $this->idsOf($injected), 'What is not a column of the list sorts by date, newest first');
        $this->assertGreaterThan(0, $this->countRows('receivings'));
    }

    public function testSearch_PagesTheResultAndCountsItWhole(): void
    {
        $purchase = $this->purchaseOf('500.00');
        $ids      = [];

        foreach (['10.00', '20.00', '30.00', '40.00', '50.00'] as $worth) {
            $ids[] = $this->debitNoteOf($purchase, $worth);
        }

        $ids  = array_reverse($ids);
        $page = $this->notes->search(null, [], 'number', 'desc', 2, 2);

        $this->assertSame(5, $page['total']);
        $this->assertSame([$ids[2], $ids[3]], $this->idsOf($page['rows']));
        $this->assertSame([$ids[4]], $this->idsOf($this->notes->search(null, [], 'number', 'desc', 2, 4)['rows']));
    }

    /**
     * @return array<string, array{array<string, mixed>}>
     */
    public static function filtersOfTheWrongShapeProvider(): array
    {
        return [
            'dates that are not days' => [['date_from' => '2026-13-45', 'date_to' => 'tomorrow']],
            'a day that does not exist, from' => [['date_from' => '2026-02-30']],
            'a day that does not exist, to'   => [['date_to' => '2026-02-30']],
            'arrays for text'         => [['search' => ['a']]],
            'arrays for ids'          => [['supplier_id' => [1], 'employee_id' => [1]]],
            'zero and negative ids'   => [['supplier_id' => '0', 'employee_id' => '-4']],
        ];
    }

    /**
     * @param array<string, mixed> $filters
     */
    #[DataProvider('filtersOfTheWrongShapeProvider')]
    public function testSearch_LeavesOutAFilterThatIsNotWhatItTakes(array $filters): void
    {
        $purchase = $this->purchaseOf('100.00');
        $this->debitNoteOf($purchase, '10.00');

        $this->assertCount(1, $this->rows(null, $filters));
    }

    /**
     * @return array<string, array{string, string, string, string}>
     */
    public static function stateProvider(): array
    {
        return [
            'worth nothing'          => ['0.0000', '0.0000', '0.0000', DebitNoteList::NOTHING],
            'paid back in cash'      => ['40.0000', '0.0000', '0.0000', DebitNoteList::SETTLED_IN_CASH],
            'held'                   => ['40.0000', '40.0000', '0.0000', DebitNoteList::HELD],
            'partly applied'         => ['40.0000', '40.0000', '15.0000', DebitNoteList::PARTLY_APPLIED],
            'applied'                => ['40.0000', '40.0000', '40.0000', DebitNoteList::APPLIED],
            'half paid back, held'   => ['40.0000', '20.0000', '0.0000', DebitNoteList::HELD],
            'half paid back, all of the rest applied' => ['40.0000', '20.0000', '20.0000', DebitNoteList::APPLIED],
        ];
    }

    #[DataProvider('stateProvider')]
    public function testStateOf_TellsWhereADebitNoteStandsAgainstWhatTheSupplierIsOwed(string $amount, string $credit, string $applied, string $state): void
    {
        $this->assertSame($state, DebitNoteList::stateOf($amount, $credit, $applied));
    }
}
