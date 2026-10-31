<?php

namespace Tests\Libraries\Accounting;

use App\Libraries\Accounting\ChequeDirection;
use App\Libraries\Accounting\ChequeList;
use App\Libraries\Accounting\JournalDraft;
use App\Libraries\Accounting\JournalLineDraft;
use App\Libraries\Accounting\PostingService;
use App\Libraries\Accounting\RoutingKey;
use App\Libraries\Accounting\TreasuryService;
use App\Libraries\Numbering\DocumentNumbering;
use App\Libraries\Numbering\DocumentType;
use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\DatabaseTestTrait;
use Config\Database;
use Tests\Support\AccountingFixtureTrait;
use Tests\Support\ChequeFixtureTrait;
use Tests\Support\EmployeeFixtureTrait;
use Tests\Support\ItemFixtureTrait;
use Tests\Support\PurchaseAccountingFixtureTrait;
use Tests\Support\SaleAccountingFixtureTrait;

/**
 * What the cheque screens read: the list of the cheques of one direction with its filters and its
 * branches, and the report whose totals by status are tied to the accounts the cheque cycle posts to.
 */
class ChequeListTest extends CIUnitTestCase
{
    use DatabaseTestTrait;
    use AccountingFixtureTrait;
    use ChequeFixtureTrait;
    use EmployeeFixtureTrait;
    use ItemFixtureTrait;
    use SaleAccountingFixtureTrait;
    use PurchaseAccountingFixtureTrait;

    protected $migrate     = true;
    protected $migrateOnce = true;
    protected $refresh     = false;
    protected $namespace   = null;

    private ChequeList $list;
    private string $today;

    public static function setUpBeforeClass(): void
    {
        $seeder = Database::seeder('tests');
        $seeder->call('TestDatabaseBootstrapSeeder');
    }

    protected function setUp(): void
    {
        parent::setUp();

        $this->startBuying();
        $this->startCheques();

        $this->list  = new ChequeList();
        $this->today = date('Y-m-d');
    }

    protected function tearDown(): void
    {
        $this->releaseOpenTransactions();
        $this->stopBuying();

        $db = $this->accountingDb();
        $db->table('document_numbers')->truncate();
        $db->table('document_sequences')->truncate();

        parent::tearDown();
    }

    /**
     * @param array<string, mixed> $filters
     *
     * @return list<int>
     */
    private function found(ChequeDirection $direction, array $filters = [], ?array $branchIds = null, string $sort = 'cheque_id', string $order = 'asc'): array
    {
        return array_map(static fn (object $row): int => (int) $row->cheque_id, $this->list->search($direction, $branchIds, $filters, $sort, $order, 100, 0)['rows']);
    }

    private function bankInBranch(int $branchId): int
    {
        return (new TreasuryService())->create(['name' => 'Branch bank ' . uniqid(), 'kind' => 'bank', 'bank_name' => 'Gulf Bank', 'account_number' => strtoupper(uniqid('AC')), 'branch_id' => $branchId], $this->chequeClerk);
    }

    private function numberTheCheques(): void
    {
        $manager   = $this->employeeWithGrants(['config']);
        $numbering = new DocumentNumbering();

        $numbering->configure(DocumentType::ReceivedCheque, ['enabled' => true, 'prefix' => 'RC-', 'padding' => 4], $manager);
        $numbering->configure(DocumentType::PaidCheque, ['enabled' => true, 'prefix' => 'PC-', 'padding' => 4], $manager);
    }

    private function daysFromToday(int $days): string
    {
        return date('Y-m-d', strtotime(($days >= 0 ? '+' : '') . $days . ' days'));
    }

    public function testSearch_ListsTheChequesOfOneDirectionWithTheirPartyBankBranchBookAndNumber(): void
    {
        $this->numberTheCheques();

        $received = $this->receiveCheque('100.00', $this->today);
        $paid     = $this->issueCheque('200.00', $this->today);
        $result   = $this->list->search(ChequeDirection::Received, null, [], 'cheque_id', 'asc', 25, 0);

        $this->assertSame(1, $result['total']);
        $this->assertSame($received, (int) $result['rows'][0]->cheque_id);
        $this->assertSame('Omar Khaled', $result['rows'][0]->party_name);
        $this->assertSame('HQ', $result['rows'][0]->branch_code);
        $this->assertNotEmpty($result['rows'][0]->treasury_name);
        $this->assertSame('RC-0001', $result['rows'][0]->document_number);
        $this->assertNull($result['rows'][0]->book_name);

        $paidResult = $this->list->search(ChequeDirection::Paid, null, [], 'cheque_id', 'asc', 25, 0);

        $this->assertSame(1, $paidResult['total']);
        $this->assertSame($paid, (int) $paidResult['rows'][0]->cheque_id);
        $this->assertSame('Gulf Supplies', $paidResult['rows'][0]->party_name);
        $this->assertSame('PC-0001', $paidResult['rows'][0]->document_number);
        $this->assertSame('Cheque book', $paidResult['rows'][0]->book_name);
    }

    public function testSearch_FiltersByStatusPartyDatesAndText(): void
    {
        $this->numberTheCheques();

        $hind      = $this->anotherCustomer();
        $first     = $this->chequeService->receive(['party_id' => $this->customerId, 'treasury_id' => $this->chequeBank, 'cheque_number' => 'A-1', 'amount' => '10.00', 'issue_date' => $this->daysFromToday(-20), 'due_date' => $this->daysFromToday(10), 'drawer_name' => 'Zeinab Mansour', 'description' => 'Rent of the shop'], $this->chequeClerk);
        $second    = $this->chequeService->receive(['party_id' => $hind, 'treasury_id' => $this->chequeBank, 'cheque_number' => 'B-2', 'amount' => '20.00', 'issue_date' => $this->daysFromToday(-5), 'due_date' => $this->daysFromToday(-1), 'drawer_name' => 'Karim', 'description' => 'Goods'], $this->chequeClerk);
        $this->chequeService->deposit($second, $this->today, $this->chequeClerk);

        $this->assertSame([$first, $second], $this->found(ChequeDirection::Received));
        $this->assertSame([$second], $this->found(ChequeDirection::Received, ['status' => 'deposited']));
        $this->assertSame([$first, $second], $this->found(ChequeDirection::Received, ['status' => 'not-a-status']));
        $this->assertSame([$first, $second], $this->found(ChequeDirection::Received, ['status' => '']));
        $this->assertSame([$second], $this->found(ChequeDirection::Received, ['party_id' => $hind]));
        $this->assertSame([$second], $this->found(ChequeDirection::Received, ['date_from' => $this->daysFromToday(-10)]));
        $this->assertSame([$first], $this->found(ChequeDirection::Received, ['date_to' => $this->daysFromToday(-10)]));
        $this->assertSame([$first], $this->found(ChequeDirection::Received, ['due_from' => $this->today]));
        $this->assertSame([$second], $this->found(ChequeDirection::Received, ['due_to' => $this->today]));
        $this->assertSame([$first, $second], $this->found(ChequeDirection::Received, ['due_from' => $this->daysFromToday(-1)]));
        $this->assertSame([$first, $second], $this->found(ChequeDirection::Received, ['date_from' => 'yesterday', 'due_to' => '2026-13-45']));
        $this->assertSame([$first], $this->found(ChequeDirection::Received, ['search' => 'A-1']));
        $this->assertSame([$first], $this->found(ChequeDirection::Received, ['search' => 'Zeinab']));
        $this->assertSame([$first], $this->found(ChequeDirection::Received, ['search' => 'Rent of']));
        $this->assertSame([$second], $this->found(ChequeDirection::Received, ['search' => 'Hind']));
        $this->assertSame([$second], $this->found(ChequeDirection::Received, ['search' => 'Saad']));
        $this->assertSame([$first], $this->found(ChequeDirection::Received, ['search' => 'Khaled']));
        $this->assertSame([], $this->found(ChequeDirection::Received, ['search' => 'nothing like this']));

        $this->assertSame([$second], $this->found(ChequeDirection::Received, ['search' => 'RC-0002']));
    }

    public function testSearch_SearchesTheCompanyNameOfASupplierAndOfACustomer(): void
    {
        $company = $this->accountingDb()->table('customers')->where('person_id', $this->customerId)->update(['company_name' => 'Delta Trading']);
        $paid    = $this->issueCheque('50.00', $this->today);
        $cheque  = $this->receiveCheque('25.00', $this->today);

        $this->assertTrue($company);
        $this->assertSame([$cheque], $this->found(ChequeDirection::Received, ['search' => 'Delta']));
        $this->assertSame('Delta Trading', $this->list->search(ChequeDirection::Received, null, [], 'cheque_id', 'asc', 25, 0)['rows'][0]->party_name);
        $this->assertSame([$paid], $this->found(ChequeDirection::Paid, ['search' => 'Gulf Supplies']));
    }

    public function testSearch_ListsDeletedChequesOnlyWhenAskedAndOnlyThoseOfTheBranchesTheReaderMaySee(): void
    {
        $branch = $this->createBranch();
        $bank   = $this->bankInBranch($branch);
        $first  = $this->receiveCheque('10.00', $this->today);
        $other  = $this->chequeService->receive(['party_id' => $this->customerId, 'treasury_id' => $bank, 'cheque_number' => 'BR-1', 'amount' => '30.00', 'issue_date' => $this->today, 'due_date' => $this->today], $this->chequeClerk);
        $gone   = $this->receiveCheque('20.00', $this->today);

        $this->chequeService->delete($gone, $this->chequeClerk);

        $this->assertSame([$first, $other], $this->found(ChequeDirection::Received));
        $this->assertSame([$gone], $this->found(ChequeDirection::Received, ['deleted' => true]));
        $this->assertSame([$first], $this->found(ChequeDirection::Received, [], [1]));
        $this->assertSame([$other], $this->found(ChequeDirection::Received, [], [$branch]));
        $this->assertSame([$first, $other], $this->found(ChequeDirection::Received, [], [1, $branch]));
        $this->assertSame([], $this->found(ChequeDirection::Received, [], []));
        $this->assertSame(['total' => 0, 'rows' => []], $this->list->search(ChequeDirection::Received, [], [], 'cheque_id', 'asc', 25, 0));
    }

    public function testSearch_SortsByTheColumnAskedAndCutsThePage(): void
    {
        $small = $this->receiveCheque('10.00', $this->daysFromToday(-3));
        $big   = $this->receiveCheque('90.00', $this->daysFromToday(-2));
        $mid   = $this->receiveCheque('50.00', $this->daysFromToday(-1));

        $this->assertSame([$small, $mid, $big], $this->found(ChequeDirection::Received, [], null, 'amount', 'asc'));
        $this->assertSame([$big, $mid, $small], $this->found(ChequeDirection::Received, [], null, 'amount', 'DESC'));
        $this->assertSame([$mid, $big, $small], $this->found(ChequeDirection::Received, [], null, 'issue_date', 'desc'));
        $this->assertSame([$mid, $big, $small], $this->found(ChequeDirection::Received, [], null, 'unknown column; DROP', 'desc'));

        $page = $this->list->search(ChequeDirection::Received, null, [], 'amount', 'asc', 1, 1);

        $this->assertSame(3, $page['total']);
        $this->assertSame([$mid], array_map(static fn (object $row): int => (int) $row->cheque_id, $page['rows']));
    }

    public function testReport_TotalsTheChequesByStatusAndTiesThemToTheAccountsOfTheCycle(): void
    {
        $this->receiveCheque('100.00', $this->today);

        $deposited = $this->receiveCheque('50.00', $this->today);
        $collected = $this->receiveCheque('30.00', $this->today);
        $rejected  = $this->receiveCheque('7.00', $this->today);

        $this->chequeService->deposit($deposited, $this->today, $this->chequeClerk);
        $this->chequeService->collect($collected, $this->today, null, $this->chequeClerk);
        $this->chequeService->reject($rejected, $this->today, $this->chequeClerk);

        $report = $this->list->report(ChequeDirection::Received, null, []);

        $this->assertSame(4, count($report['rows']));
        $this->assertFalse($report['truncated']);
        $this->assertSame(['received' => '100.0000', 'deposited' => '50.0000', 'collected' => '30.0000', 'rejected' => '7.0000'], $report['totals']);
        $this->assertSame('187.0000', $report['total']);
        $this->assertSame([RoutingKey::NotesReceivable->value, RoutingKey::ChequesUnderCollection->value], array_column($report['ties'], 'key'));
        $this->assertSame(['100.0000', '50.0000'], array_column($report['ties'], 'cheques'));
        $this->assertSame(['100.0000', '50.0000'], array_column($report['ties'], 'ledger'));
        $this->assertSame([true, true], array_column($report['ties'], 'agrees'));
        $this->assertSame('120601', $report['ties'][0]['account']->code);
    }

    public function testReport_TiesThePaidChequesToNotesPayableOnTheCreditSide(): void
    {
        $this->issueCheque('200.00', $this->today);

        $delivered = $this->issueCheque('80.00', $this->today);
        $cancelled = $this->issueCheque('15.00', $this->today);

        $this->chequeService->deliver($delivered, $this->today, $this->chequeClerk);
        $this->chequeService->cancel($cancelled, $this->chequeClerk);

        $report = $this->list->report(ChequeDirection::Paid, null, []);

        $this->assertSame(['issued' => '200.0000', 'delivered' => '80.0000', 'collected' => '0.0000', 'rejected' => '0.0000', 'cancelled' => '15.0000'], $report['totals']);
        $this->assertSame('295.0000', $report['total']);
        $this->assertCount(1, $report['ties']);
        $this->assertSame(RoutingKey::NotesPayable->value, $report['ties'][0]['key']);
        $this->assertSame('80.0000', $report['ties'][0]['ledger']);
        $this->assertSame('80.0000', $report['ties'][0]['cheques']);
        $this->assertTrue($report['ties'][0]['agrees']);
    }

    public function testReport_CountsReversedJournalsAndSaysWhenTheAccountsDiffer(): void
    {
        $cheque = $this->receiveCheque('100.00', $this->today);

        $this->chequeService->deposit($cheque, $this->today, $this->chequeClerk);
        $this->chequeService->undo($cheque, $this->chequeClerk);

        $report = $this->list->report(ChequeDirection::Received, null, []);

        $this->assertSame(['100.0000', '0.0000'], array_column($report['ties'], 'ledger'));
        $this->assertSame([true, true], array_column($report['ties'], 'agrees'));

        (new PostingService())->post(new JournalDraft(
            date: $this->today,
            lines: [new JournalLineDraft($this->accountId('notes_receivable'), debit: '5.00'), new JournalLineDraft($this->accountId('cash'), credit: '5.00')],
            sourceType: 'manual',
        ));

        $differs = $this->list->report(ChequeDirection::Received, null, []);

        $this->assertSame('105.0000', $differs['ties'][0]['ledger']);
        $this->assertSame('100.0000', $differs['ties'][0]['cheques']);
        $this->assertFalse($differs['ties'][0]['agrees']);
        $this->assertTrue($differs['ties'][1]['agrees']);
    }

    public function testReport_ReadsOnlyTheChequesAndTheJournalsOfTheBranchesTheReaderMaySee(): void
    {
        $branch = $this->createBranch();
        $bank   = $this->bankInBranch($branch);

        $this->receiveCheque('100.00', $this->today);
        $this->chequeService->receive(['party_id' => $this->customerId, 'treasury_id' => $bank, 'cheque_number' => 'BR-1', 'amount' => '30.00', 'issue_date' => $this->today, 'due_date' => $this->today], $this->chequeClerk);

        $everything = $this->list->report(ChequeDirection::Received, null, []);
        $headOffice = $this->list->report(ChequeDirection::Received, [1], []);
        $branchOnly = $this->list->report(ChequeDirection::Received, [$branch], []);
        $nothing    = $this->list->report(ChequeDirection::Received, [], []);

        $this->assertSame('130.0000', $everything['total']);
        $this->assertSame('130.0000', $everything['ties'][0]['ledger']);
        $this->assertSame('100.0000', $headOffice['total']);
        $this->assertSame('100.0000', $headOffice['ties'][0]['ledger']);
        $this->assertSame('100.0000', $headOffice['ties'][0]['cheques']);
        $this->assertSame('30.0000', $branchOnly['total']);
        $this->assertSame('30.0000', $branchOnly['ties'][0]['ledger']);
        $this->assertSame('30.0000', $branchOnly['ties'][0]['cheques']);
        $this->assertTrue($branchOnly['ties'][0]['agrees']);
        $this->assertSame(['rows' => [], 'truncated' => false, 'totals' => [], 'total' => '0.0000', 'ties' => []], $nothing);
    }

    public function testReport_AppliesTheFiltersToTheRowsAndTheTotalsButNotToTheTies(): void
    {
        $first  = $this->receiveCheque('100.00', $this->daysFromToday(-9));
        $second = $this->receiveCheque('40.00', $this->today);

        $report = $this->list->report(ChequeDirection::Received, null, ['date_from' => $this->daysFromToday(-3)]);

        $this->assertSame([$second], array_map(static fn (object $row): int => (int) $row->cheque_id, $report['rows']));
        $this->assertSame('40.0000', $report['total']);
        $this->assertSame('140.0000', $report['ties'][0]['ledger']);
        $this->assertSame('140.0000', $report['ties'][0]['cheques']);
        $this->assertSame([$first, $second], array_map(static fn (object $row): int => (int) $row->cheque_id, $this->list->report(ChequeDirection::Received, null, [])['rows']));
    }

    public function testReport_LeavesADeletedChequeOutOfTheRowsTheTotalsAndTheTie(): void
    {
        $this->receiveCheque('100.00', $this->today);

        $gone = $this->receiveCheque('60.00', $this->today);

        $this->chequeService->delete($gone, $this->chequeClerk);

        $report = $this->list->report(ChequeDirection::Received, null, []);

        $this->assertCount(1, $report['rows']);
        $this->assertSame('100.0000', $report['total']);
        $this->assertSame('100.0000', $report['ties'][0]['cheques']);
        $this->assertSame('100.0000', $report['ties'][0]['ledger']);
    }

    public function testReport_CutsTheRowsAtTheLimitAndSaysSoWithoutCountingTheRowsItLeftOut(): void
    {
        $this->receiveCheque('1.00', $this->today);

        $db       = $this->accountingDb();
        $template = $db->table('cheques')->get()->getRowArray();
        $rows     = [];

        unset($template['cheque_id']);

        for ($index = 0; $index < ChequeList::REPORT_LIMIT; $index++) {
            $rows[] = [...$template, 'cheque_number' => 'BULK-' . $index];
        }

        $db->table('cheques')->insertBatch($rows, null, 1000);

        $report = $this->list->report(ChequeDirection::Received, null, []);

        $this->assertTrue($report['truncated']);
        $this->assertCount(ChequeList::REPORT_LIMIT, $report['rows']);
        $this->assertSame('5000.0000', $report['total']);
        $this->assertSame('5000.0000', $report['totals']['received']);
    }

    public function testParties_ListsTheCustomersOrTheSuppliersByNameUpToTheLimit(): void
    {
        $this->anotherCustomer('Aya', 'Zaki');
        $this->anotherCustomer('Aaron', 'Zed');
        $this->accountingDb()->table('customers')->where('person_id', $this->customerId)->update(['deleted' => 1]);

        $customers = $this->list->parties(ChequeDirection::Received, 2000);
        $names     = array_column($customers, 'name');

        $this->assertNotContains('Omar Khaled', $names);
        $this->assertSame(['Aaron Zed', 'Aya Zaki'], array_values(array_intersect($names, ['Aya Zaki', 'Aaron Zed'])));
        $this->assertCount(1, $this->list->parties(ChequeDirection::Received, 1));
        $this->assertContains('Gulf Supplies', array_column($this->list->parties(ChequeDirection::Paid, 2000), 'name'));
        $this->assertNotContains('Aya Zaki', array_column($this->list->parties(ChequeDirection::Paid, 2000), 'name'));
    }

    public function testParties_KeepsOnlyTheNamesThatHaveTheTextTypedAndTakesItAsTextNotAsAPattern(): void
    {
        $this->anotherCustomer('Aya', 'Zaki');
        $this->anotherCustomer('Aaron', 'Zed');

        $names = static fn (array $parties): array => array_column($parties, 'name');

        $this->assertSame(['Aya Zaki'], $names($this->list->parties(ChequeDirection::Received, 20, 'aya')));
        $this->assertSame(['Aya Zaki'], $names($this->list->parties(ChequeDirection::Received, 20, 'a Za')));
        $this->assertSame(['Aaron Zed', 'Aya Zaki'], array_values(array_intersect($names($this->list->parties(ChequeDirection::Received, 20, 'a')), ['Aya Zaki', 'Aaron Zed'])));
        $this->assertSame([], $this->list->parties(ChequeDirection::Received, 20, '%'));
        $this->assertSame([], $this->list->parties(ChequeDirection::Received, 20, 'A_a'));
        $this->assertSame([], $this->list->parties(ChequeDirection::Received, 20, "' OR 1=1 -- "));
        $this->assertSame(['Gulf Supplies'], $names($this->list->parties(ChequeDirection::Paid, 20, 'sup')));
        $this->assertSame([], $this->list->parties(ChequeDirection::Paid, 20, 'Zaki'));
        $this->assertCount(1, $this->list->parties(ChequeDirection::Received, 1, 'a'), 'The limit still holds');
        $this->assertSame($names($this->list->parties(ChequeDirection::Received, 20)), $names($this->list->parties(ChequeDirection::Received, 20, '  ')));
    }
}
