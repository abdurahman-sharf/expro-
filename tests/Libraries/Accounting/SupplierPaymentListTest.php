<?php

namespace Tests\Libraries\Accounting;

use App\Libraries\Accounting\AccountingGuard;
use App\Libraries\Accounting\SupplierAdvances;
use App\Libraries\Accounting\SupplierPaymentList;
use App\Libraries\Accounting\TreasuryService;
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
use App\Libraries\Roles\RoleService;

/**
 * The payments to suppliers as documents: the ones made against a purchase after it was saved and
 * the ones that belong to no purchase, each with its number, the day it is posted on, who made it,
 * where the money came from and where it stands, cut down to the branches the reader works in.
 */
class SupplierPaymentListTest extends CIUnitTestCase
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

    private SupplierPaymentList $payments;
    private SupplierAdvances $advances;
    private int $clerk;

    public static function setUpBeforeClass(): void
    {
        $seeder = Database::seeder('tests');
        $seeder->call('TestDatabaseBootstrapSeeder');
    }

    protected function setUp(): void
    {
        parent::setUp();

        $this->startBuying();

        $this->payments = new SupplierPaymentList();
        $this->advances = new SupplierAdvances();
        $this->clerk    = $this->employeeWithGrants([AccountingGuard::PAYABLES_MANAGE, AccountingGuard::TREASURIES_MANAGE]);
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
        return $this->payments->search($branchIds, $filters, $sort, $order, 100, 0)['rows'];
    }

    /**
     * @param list<object> $rows
     *
     * @return list<string>
     */
    private function keysOf(array $rows): array
    {
        return array_map(static fn (object $row): string => $row->kind . ':' . $row->source_id, $rows);
    }

    private function payOnDate(int $purchase, string $amount, string $date, ?string $type = null): int
    {
        $this->assertTrue($this->purchases->update($purchase, ['payments' => [
            ['payment_id' => NEW_ENTRY, 'payment_type' => $type ?? lang('Sales.cash'), 'payment_amount' => $amount, 'employee_id' => $this->employeeId, 'payment_status' => 'completed', 'reference_code' => '', 'payment_date' => $date],
        ]]));

        return (int) $this->accountingDb()->table('receivings_payments')->selectMax('payment_id', 'last')->where('receiving_id', $purchase)->get()->getRow()->last;
    }

    private function advance(string $amount, string $date, ?int $supplierId = null, string $reference = ''): int
    {
        return $this->advances->pay($supplierId ?? $this->supplierId, $amount, $date, lang('Sales.cash'), null, $reference, '', $this->clerk);
    }

    private function sequenceOn(string $prefix = 'SP-'): void
    {
        (new DocumentNumbering())->configure(DocumentType::SupplierPayment, ['enabled' => true, 'prefix' => $prefix, 'padding' => 3]);
    }

    public function testSearch_ListsThePaymentsOfPurchasesAndThePaymentsWithoutOneNewestFirst(): void
    {
        $purchase = $this->purchaseOf('100.00');
        $first    = $this->payOnDate($purchase, '30.00', $this->daysAgo(4));
        $advance  = $this->advance('50.00', $this->daysAgo(2));
        $second   = $this->payOnDate($purchase, '20.00', $this->daysAgo(1));

        $result = $this->payments->search(null);

        $this->assertSame(3, $result['total']);
        $this->assertSame(['purchase:' . $second, 'advance:' . $advance, 'purchase:' . $first], $this->keysOf($result['rows']));

        $row = $result['rows'][0];

        $this->assertSame($purchase, (int) $row->receiving_id);
        $this->assertSame($this->supplierId, (int) $row->supplier_id);
        $this->assertSame('Gulf Supplies', $row->supplier_name);
        $this->assertSame('20.00', bcadd($row->amount, '0', 2));
        $this->assertSame(lang('Sales.cash'), $row->payment_type);
        $this->assertSame('completed', $row->status);
        $this->assertSame(1, (int) $row->branch_id);
        $this->assertSame($this->daysAgo(1), $row->doc_date);

        $this->assertNull($result['rows'][1]->receiving_id, 'A payment without a purchase names none');
    }

    public function testSearch_ListsNothingWhileNothingWasPaid(): void
    {
        $this->purchaseOf('100.00', null, lang('Sales.cash'));

        $this->assertSame(['total' => 0, 'rows' => []], $this->payments->search(null));
    }

    public function testSearch_LeavesOutTheOpeningBalancesAndThePaymentMadeAtOnce(): void
    {
        $this->advances->openingBalance($this->supplierId, SupplierAdvances::THEY_OWE, '20.00', $this->daysAgo(3), '', $this->clerk);
        $this->purchaseOf('100.00', null, lang('Sales.cash'));

        $this->assertSame([], $this->rows());
    }

    public function testSearch_NumbersAPaymentInItsSequenceAndShowsTheIdOfOneSavedBeforeThereWereNumbers(): void
    {
        $purchase = $this->purchaseOf('100.00');
        $legacy   = $this->payOnDate($purchase, '10.00', $this->daysAgo(3));

        $this->sequenceOn();

        $numbered = $this->payOnDate($purchase, '20.00', $this->daysAgo(2));
        $advance  = $this->advance('30.00', $this->daysAgo(1));

        $byKey = [];

        foreach ($this->rows() as $row) {
            $byKey[$row->kind . ':' . $row->source_id] = [$row->number, (int) $row->numbered];
        }

        $this->assertSame([(string) $legacy, 0], $byKey['purchase:' . $legacy], 'A payment saved before there were numbers shows its id');
        $this->assertSame(['SP-001', 1], $byKey['purchase:' . $numbered]);
        $this->assertSame(['SP-002', 1], $byKey['advance:' . $advance]);
    }

    public function testSearch_ShowsTheDateEnteredAndTheDayAPaymentSavedBeforeDatesWereEnteredWasSaved(): void
    {
        $purchase = $this->purchaseOf('100.00');
        $entered  = $this->payOnDate($purchase, '10.00', $this->daysAgo(3));
        $legacy   = $this->payOnDate($purchase, '20.00', $this->daysAgo(6));

        $this->accountingDb()->table('receivings_payments')->where('payment_id', $legacy)->update(['payment_date' => null, 'payment_time' => $this->daysAgo(6) . ' 09:30:00']);

        $byKey = [];

        foreach ($this->rows() as $row) {
            $byKey[$row->kind . ':' . $row->source_id] = $row;
        }

        $this->assertSame([$this->daysAgo(3), 1], [$byKey['purchase:' . $entered]->doc_date, (int) $byKey['purchase:' . $entered]->date_entered]);
        $this->assertSame([$this->daysAgo(6), 0, $this->daysAgo(6) . ' 09:30:00'], [$byKey['purchase:' . $legacy]->doc_date, (int) $byKey['purchase:' . $legacy]->date_entered, $byKey['purchase:' . $legacy]->saved_at]);
        $this->assertSame(date('Y-m-d'), substr((string) $byKey['purchase:' . $entered]->saved_at, 0, 10), 'The time it was saved is kept apart from the date');
    }

    public function testSearch_ShowsMoneyTheSupplierGaveBackAsANegativeAmount(): void
    {
        $purchase = $this->purchaseOf('100.00');
        $this->payOnDate($purchase, '100.00', $this->daysAgo(3));
        $refund = $this->payOnDate($purchase, '-30.00', $this->daysAgo(1));

        $rows = $this->rows(null, ['kind' => SupplierPaymentList::REFUND]);

        $this->assertSame(['purchase:' . $refund], $this->keysOf($rows));
        $this->assertSame('-30.00', bcadd($rows[0]->amount, '0', 2));
    }

    public function testSearch_NamesTheEmployeeWhoPaidAndTheTreasuryItCameFrom(): void
    {
        $drawer   = (new TreasuryService())->create(['name' => 'Payment drawer', 'kind' => 'cash'], $this->clerk);
        $buyer    = $this->createEmployee(first_name: 'Huda', last_name: 'Saleh');
        $purchase = $this->purchaseOf('100.00');

        $this->assertTrue($this->purchases->update($purchase, ['payments' => [
            ['payment_id' => NEW_ENTRY, 'payment_type' => lang('Sales.cash'), 'payment_amount' => '25.00', 'employee_id' => $buyer, 'payment_status' => 'completed', 'reference_code' => 'R-1', 'treasury_id' => $drawer],
        ]]));

        $advance = $this->advances->pay($this->supplierId, '40.00', $this->daysAgo(1), lang('Sales.cash'), $drawer, 'ADV-1', '', $this->clerk);

        $byKey = [];

        foreach ($this->rows() as $row) {
            $byKey[$row->kind] = $row;
        }

        $this->assertSame(['Huda Saleh', 'Payment drawer', 'R-1'], [$byKey['purchase']->employee_name, $byKey['purchase']->treasury_name, $byKey['purchase']->reference_code]);
        $this->assertSame($advance, (int) $byKey['advance']->source_id);
        $this->assertSame($this->clerk, (int) $byKey['advance']->employee_id, 'A payment without a purchase was made by whoever entered it');
        $this->assertNotSame('', $byKey['advance']->employee_name);
        $this->assertSame(['Payment drawer', 'ADV-1'], [$byKey['advance']->treasury_name, $byKey['advance']->reference_code]);
    }

    public function testSearch_ShowsTheStatusOfAPaymentAndOfACancelledOne(): void
    {
        $purchase = $this->purchaseOf('100.00');
        $draft    = $this->payPurchase($purchase, '10.00', null, 'draft');
        $advance  = $this->advance('50.00', $this->daysAgo(1));

        $this->advances->cancel($advance, 'wrong supplier', $this->clerk);

        $statuses = [];

        foreach ($this->rows() as $row) {
            $statuses[$row->kind . ':' . $row->source_id] = $row->status;
        }

        $this->assertSame(['purchase:' . $draft => 'draft', 'advance:' . $advance => 'cancelled'], $statuses);
        $this->assertSame(['advance:' . $advance], $this->keysOf($this->rows(null, ['status' => 'cancelled'])));
        $this->assertSame(['purchase:' . $draft], $this->keysOf($this->rows(null, ['status' => 'draft'])));
    }

    public function testSearch_CutsTheRowsDownToTheBranchesGiven(): void
    {
        $branch   = $this->createBranch('B2');
        $location = $this->locationOfBranch($branch);
        $item     = $this->item('0.00');
        $other    = $this->buy([$this->purchaseLine($item, 0, '1', '100.00', '0', '0', PERCENT, $location)]);
        $own      = $this->purchaseOf('100.00');

        $ownPayment   = $this->payOnDate($own, '10.00', $this->daysAgo(2));
        $otherPayment = $this->payOnDate($other, '20.00', $this->daysAgo(1));
        $advance      = $this->advance('30.00', $this->daysAgo(3));

        $this->assertSame(['purchase:' . $otherPayment, 'purchase:' . $ownPayment, 'advance:' . $advance], $this->keysOf($this->rows(null)), 'Every branch');
        $this->assertSame(['purchase:' . $ownPayment, 'advance:' . $advance], $this->keysOf($this->rows([1])));
        $this->assertSame(['purchase:' . $otherPayment], $this->keysOf($this->rows([$branch])));
        $this->assertSame(['purchase:' . $otherPayment, 'purchase:' . $ownPayment, 'advance:' . $advance], $this->keysOf($this->rows([1, $branch])));
        $this->assertSame([], $this->rows([]), 'Someone who works in no branch reads nothing');
        $this->assertSame(0, $this->payments->search([])['total']);
        $this->assertSame(1, $this->payments->search([$branch])['total'], 'The count follows the same cut');
        $this->assertSame([$branch => 'B2'], [(int) $this->rows([$branch])[0]->branch_id => $this->rows([$branch])[0]->branch_code]);
    }

    public function testSearch_FiltersByDateWayStatusTreasuryEmployeeSupplierAndKind(): void
    {
        $other    = $this->anotherSupplier('Najd Trading');
        $drawer   = (new TreasuryService())->create(['name' => 'Filter drawer', 'kind' => 'cash'], $this->clerk);
        $buyer    = $this->createEmployee(first_name: 'Huda', last_name: 'Saleh');
        $purchase = $this->purchaseOf('500.00');
        $second   = $this->purchaseOf('500.00', $other);

        $cash     = $this->payOnDate($purchase, '10.00', $this->daysAgo(10));
        $transfer = $this->payOnDate($purchase, '20.00', $this->daysAgo(5), lang('Sales.bank_transfer'));
        $theirs   = $this->payOnDate($second, '30.00', $this->daysAgo(3));
        $advance  = $this->advances->pay($this->supplierId, '40.00', $this->daysAgo(1), 'Cash', $drawer, '', '', $this->clerk);
        $byBuyer  = $this->purchases->update($purchase, ['payments' => [
            ['payment_id' => NEW_ENTRY, 'payment_type' => lang('Sales.cash'), 'payment_amount' => '5.00', 'employee_id' => $buyer, 'payment_status' => 'review', 'reference_code' => '', 'payment_date' => $this->daysAgo(7)],
        ]]);

        $this->assertTrue($byBuyer);

        $reviewed = (int) $this->accountingDb()->table('receivings_payments')->where('payment_status', 'review')->get()->getRow()->payment_id;

        $this->assertSame(['purchase:' . $transfer, 'purchase:' . $reviewed, 'purchase:' . $cash], $this->keysOf($this->rows(null, ['supplier_id' => $this->supplierId, 'date_to' => $this->daysAgo(4)])));
        $this->assertSame(['advance:' . $advance, 'purchase:' . $theirs, 'purchase:' . $transfer], $this->keysOf($this->rows(null, ['date_from' => $this->daysAgo(5)])));
        $this->assertSame(['purchase:' . $reviewed], $this->keysOf($this->rows(null, ['date_from' => $this->daysAgo(7), 'date_to' => $this->daysAgo(6)])));
        $this->assertSame(['purchase:' . $transfer], $this->keysOf($this->rows(null, ['payment_type' => lang('Sales.bank_transfer')])));
        $this->assertSame(['purchase:' . $reviewed], $this->keysOf($this->rows(null, ['status' => 'review'])));
        $this->assertSame(['advance:' . $advance], $this->keysOf($this->rows(null, ['treasury_id' => $drawer])));
        $this->assertSame(['purchase:' . $reviewed], $this->keysOf($this->rows(null, ['employee_id' => $buyer])));
        $this->assertSame(['purchase:' . $theirs], $this->keysOf($this->rows(null, ['supplier_id' => $other])));
        $this->assertSame(['advance:' . $advance], $this->keysOf($this->rows(null, ['kind' => SupplierPaymentList::ADVANCE])));
        $this->assertSame(4, count($this->rows(null, ['kind' => SupplierPaymentList::PURCHASE])));
        $this->assertSame(['purchase:' . $transfer], $this->keysOf($this->rows(null, ['payment_type' => lang('Sales.bank_transfer'), 'supplier_id' => $this->supplierId, 'kind' => 'purchase', 'status' => 'completed'])), 'Filters narrow together');
    }

    public function testSearch_SearchesTheNumberTheSupplierTheReferenceAndThePurchase(): void
    {
        $this->sequenceOn();

        $other    = $this->anotherSupplier('Najd Trading');
        $purchase = $this->purchaseOf('100.00');
        $second   = $this->purchaseOf('100.00', $other);
        $one      = $this->payOnDate($purchase, '10.00', $this->daysAgo(2));
        $two      = $this->payOnDate($second, '20.00', $this->daysAgo(1));
        $advance  = $this->advance('30.00', $this->daysAgo(3), null, 'CHQ-7788');

        $this->assertSame(['purchase:' . $two], $this->keysOf($this->rows(null, ['search' => 'SP-002'])), 'By number');
        $this->assertSame(['purchase:' . $two], $this->keysOf($this->rows(null, ['search' => 'najd'])), 'By supplier');
        $this->assertSame(['advance:' . $advance], $this->keysOf($this->rows(null, ['search' => 'CHQ-77'])), 'By reference');
        $this->assertSame(['purchase:' . $one], $this->keysOf($this->rows(null, ['search' => 'RECV ' . $purchase])), 'By the receiving number of the purchase');
        $this->assertSame([], $this->rows(null, ['search' => 'nothing like this']));
    }

    public function testSearch_SearchesTheNumberOfThePurchaseWhenItHasOne(): void
    {
        (new DocumentNumbering())->configure(DocumentType::Purchase, ['enabled' => true, 'prefix' => 'PO-', 'padding' => 4]);

        $purchase = $this->purchaseOf('100.00');
        $payment  = $this->payOnDate($purchase, '10.00', $this->daysAgo(1));

        $row = $this->rows(null, ['search' => 'PO-0001'])[0];

        $this->assertSame('purchase:' . $payment, $row->kind . ':' . $row->source_id);
        $this->assertSame('PO-0001', $row->purchase_number);
    }

    public function testSearch_TakesALikeCharacterAsTextAndNotAsAPattern(): void
    {
        $purchase = $this->purchaseOf('100.00');
        $this->payOnDate($purchase, '10.00', $this->daysAgo(1));
        $this->advance('30.00', $this->daysAgo(2), null, '100% paid');

        $this->assertSame(1, count($this->rows(null, ['search' => '%'])), 'Only the reference with a percent sign');
        $this->assertSame(0, count($this->rows(null, ['search' => '_'])));
    }

    public function testSearch_SortsByTheColumnsItOffersAndOnlyByThose(): void
    {
        $purchase = $this->purchaseOf('500.00');
        $other    = $this->anotherSupplier('Najd Trading');
        $second   = $this->purchaseOf('500.00', $other);
        $a        = $this->payOnDate($purchase, '30.00', $this->daysAgo(5));
        $b        = $this->payOnDate($second, '10.00', $this->daysAgo(3));
        $c        = $this->payOnDate($purchase, '20.00', $this->daysAgo(1));

        $this->assertSame(['purchase:' . $b, 'purchase:' . $c, 'purchase:' . $a], $this->keysOf($this->rows(null, [], 'amount', 'asc')));
        $this->assertSame(['purchase:' . $a, 'purchase:' . $c, 'purchase:' . $b], $this->keysOf($this->rows(null, [], 'amount', 'desc')));
        $this->assertSame(['purchase:' . $c, 'purchase:' . $a, 'purchase:' . $b], $this->keysOf($this->rows(null, [], 'supplier_name', 'asc')));
        $this->assertSame(['purchase:' . $b, 'purchase:' . $c, 'purchase:' . $a], $this->keysOf($this->rows(null, [], 'supplier_name', 'desc')), 'Payments of one supplier follow each other newest first');
        $this->assertSame(['purchase:' . $a, 'purchase:' . $b, 'purchase:' . $c], $this->keysOf($this->rows(null, [], 'number', 'asc')));
        $this->assertSame(['purchase:' . $a, 'purchase:' . $b, 'purchase:' . $c], $this->keysOf($this->rows(null, [], 'date', 'asc')));

        $injected = $this->rows(null, [], 'd.doc_date; DROP TABLE ospos_receivings_payments', 'asc; DROP TABLE ospos_receivings');

        $this->assertSame(['purchase:' . $c, 'purchase:' . $b, 'purchase:' . $a], $this->keysOf($injected), 'What is not a column of the list sorts by date, newest first');
        $this->assertSame(3, $this->countRows('receivings_payments'));
    }

    public function testSearch_SortsByTheNumberTheSequenceGaveWhateverItsPrefix(): void
    {
        $purchase = $this->purchaseOf('500.00');

        $this->sequenceOn('Z-');

        $first = $this->payOnDate($purchase, '10.00', $this->daysAgo(1));

        $this->sequenceOn('A-');

        $second = $this->payOnDate($purchase, '10.00', $this->daysAgo(5));
        $third  = $this->payOnDate($purchase, '10.00', $this->daysAgo(3));

        $this->assertSame(['purchase:' . $first, 'purchase:' . $second, 'purchase:' . $third], $this->keysOf($this->rows(null, [], 'number', 'asc')));
        $this->assertSame(['purchase:' . $third, 'purchase:' . $second, 'purchase:' . $first], $this->keysOf($this->rows(null, [], 'number', 'desc')));
    }

    public function testSearch_PagesTheResultAndCountsItWhole(): void
    {
        $purchase = $this->purchaseOf('500.00');
        $keys     = [];

        foreach ([5, 4, 3, 2, 1] as $days) {
            $keys[] = 'purchase:' . $this->payOnDate($purchase, '10.00', $this->daysAgo($days));
        }

        $keys = array_reverse($keys);

        $page = $this->payments->search(null, [], 'date', 'desc', 2, 2);

        $this->assertSame(5, $page['total']);
        $this->assertSame([$keys[2], $keys[3]], $this->keysOf($page['rows']));
        $this->assertSame([$keys[4]], $this->keysOf($this->payments->search(null, [], 'date', 'desc', 2, 4)['rows']));
        $this->assertSame(1, count($this->payments->search(null, [], 'date', 'desc', 0, -5)['rows']), 'A page is at least one row and never starts before the first');
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
            'arrays for text'         => [['search' => ['a'], 'payment_type' => ['Cash'], 'status' => ['draft'], 'kind' => ['advance']]],
            'arrays for ids'          => [['supplier_id' => [1], 'treasury_id' => [1], 'employee_id' => [1]]],
            'zero and negative ids'   => [['supplier_id' => '0', 'treasury_id' => '-4', 'employee_id' => 'abc']],
            'an unknown kind'         => [['kind' => 'something']],
        ];
    }

    /**
     * @param array<string, mixed> $filters
     */
    #[DataProvider('filtersOfTheWrongShapeProvider')]
    public function testSearch_LeavesOutAFilterThatIsNotWhatItTakes(array $filters): void
    {
        $purchase = $this->purchaseOf('100.00');
        $this->payOnDate($purchase, '10.00', $this->daysAgo(1));
        $this->advance('30.00', $this->daysAgo(2));

        $this->assertCount(2, $this->rows(null, $filters));
    }

    public function testPaymentTypesAndPayers_ListWhatTheFiltersOffer(): void
    {
        $buyer    = $this->createEmployee(first_name: 'Huda', last_name: 'Saleh');
        $purchase = $this->purchaseOf('100.00');

        $this->payOnDate($purchase, '10.00', $this->daysAgo(2), lang('Sales.bank_transfer'));
        $this->advance('30.00', $this->daysAgo(2));
        $this->assertTrue($this->purchases->update($purchase, ['payments' => [
            ['payment_id' => NEW_ENTRY, 'payment_type' => lang('Sales.cash'), 'payment_amount' => '5.00', 'employee_id' => $buyer, 'payment_status' => 'completed', 'reference_code' => ''],
        ]]));

        $types = $this->payments->paymentTypes();

        $this->assertCount(2, $types);
        $this->assertEqualsCanonicalizing([lang('Sales.bank_transfer'), lang('Sales.cash')], $types);

        $payers = $this->payments->payers(null);

        $this->assertContains($buyer, array_map(static fn (object $payer): int => $payer->employee_id, $payers));
        $this->assertContains($this->clerk, array_map(static fn (object $payer): int => $payer->employee_id, $payers));
        $this->assertSame([], $this->payments->payers([]));
    }

    private function ownRecordsOnly(int $employeeId): int
    {
        (new RoleService())->setRecordScope($employeeId, 'own', null);

        return $employeeId;
    }

    public function testSearch_ListsOnlyThePaymentsAnEmployeeWhoSeesTheirOwnRecordsMade(): void
    {
        $purchase = $this->purchaseOf('500.00');
        $byBuyer  = $this->payOnDate($purchase, '100.00', $this->daysAgo(2));
        $byClerk  = $this->advance('50.00', $this->daysAgo(1));

        $clerkSees = $this->payments->search(null, [], 'date', 'desc', 100, 0, $this->ownRecordsOnly($this->clerk))['rows'];

        $this->assertSame(['advance:' . $byClerk], $this->keysOf($clerkSees));
        $this->assertSame(1, $this->payments->search(null, [], 'date', 'desc', 100, 0, $this->clerk)['total']);

        $everyone = $this->payments->search(null, [], 'date', 'desc', 100, 0, $this->employeeWithGrants([]))['rows'];

        $this->assertEqualsCanonicalizing(['purchase:' . $byBuyer, 'advance:' . $byClerk], $this->keysOf($everyone));
    }

    public function testSearch_CutsTheListToTheEmployeeOfTheSessionWhenNoViewerIsGiven(): void
    {
        $purchase = $this->purchaseOf('500.00');

        $this->payOnDate($purchase, '100.00', $this->daysAgo(2));

        $byClerk = $this->advance('50.00', $this->daysAgo(1));

        $this->ownRecordsOnly($this->clerk);

        $_SESSION['person_id'] = $this->clerk;

        try {
            $this->assertSame(['advance:' . $byClerk], $this->keysOf($this->rows()));
        } finally {
            unset($_SESSION['person_id']);
        }
    }

    public function testSearch_KeepsTheFilterOfAnotherEmployeeFromWideningTheListOfAnOwnRecordsEmployee(): void
    {
        $this->advance('50.00', $this->daysAgo(1));

        $own = $this->ownRecordsOnly($this->clerk);

        $this->assertSame([], $this->payments->search(null, ['employee_id' => (string) $this->employeeId], 'date', 'desc', 100, 0, $own)['rows']);
        $this->assertCount(1, $this->payments->search(null, ['employee_id' => (string) $own], 'date', 'desc', 100, 0, $own)['rows']);
    }

    public function testPayers_ListsOnlyTheEmployeeWhoSeesTheirOwnRecords(): void
    {
        $purchase = $this->purchaseOf('500.00');

        $this->payOnDate($purchase, '100.00', $this->daysAgo(2));
        $this->advance('50.00', $this->daysAgo(1));

        $own = $this->ownRecordsOnly($this->clerk);

        $this->assertSame([$own], array_map(static fn (object $payer): int => $payer->employee_id, $this->payments->payers(null, $own)));
        $this->assertGreaterThanOrEqual(2, count($this->payments->payers(null, $this->employeeWithGrants([]))));
    }
}
