<?php

namespace Tests\Libraries\Accounting;

use App\Libraries\Accounting\AuditLog;
use App\Libraries\Accounting\JournalOrigin;
use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\DatabaseTestTrait;
use Config\Database;
use Tests\Support\AccountingFixtureTrait;

class AuditLogTest extends CIUnitTestCase
{
    use DatabaseTestTrait;
    use AccountingFixtureTrait;

    protected $migrate     = true;
    protected $migrateOnce = true;
    protected $refresh     = false;
    protected $namespace   = null;

    private AuditLog $audit;

    public static function setUpBeforeClass(): void
    {
        $seeder = Database::seeder('tests');
        $seeder->call('TestDatabaseBootstrapSeeder');
    }

    protected function setUp(): void
    {
        parent::setUp();

        $this->clearJournals();
        $this->audit = new AuditLog();
    }

    public function testWrite_StoresTheEntryWithItsDetailsAsJson(): void
    {
        $this->audit->write('account', 7, 'updated', 3, JournalOrigin::User, ['name' => 'الصندوق']);

        $row = $this->accountingDb()->table('accounting_audit_log')->get()->getRow();

        $this->assertSame('account', $row->entity_type);
        $this->assertSame('7', (string) $row->entity_id);
        $this->assertSame('updated', $row->action);
        $this->assertSame('3', (string) $row->employee_id);
        $this->assertSame('user', $row->origin);
        $this->assertSame(['name' => 'الصندوق'], json_decode($row->details, true));
    }

    public function testWrite_StoresNoDetailsWhenThereAreNone(): void
    {
        $this->audit->write('settings', null, 'accounting_enabled', null, JournalOrigin::System);

        $row = $this->accountingDb()->table('accounting_audit_log')->get()->getRow();

        $this->assertNull($row->details);
        $this->assertNull($row->entity_id);
        $this->assertNull($row->employee_id);
    }

    public function testSearch_ReturnsEntriesNewestFirstWithTheEmployeeName(): void
    {
        $employee = $this->createEmployee('Sara', 'Nasser');
        $this->audit->write('journal', 1, 'posted', $employee, JournalOrigin::User);
        $this->audit->write('journal', 2, 'reversed', $employee, JournalOrigin::User);

        $result = $this->audit->search([]);

        $this->assertSame(2, $result['total']);
        $this->assertSame(['reversed', 'posted'], array_column($result['rows'], 'action'));
        $this->assertSame('Sara Nasser', $result['rows'][0]->employee_name);
    }

    public function testSearch_FiltersByActionEntityTypeEmployeeAndOrigin(): void
    {
        $first  = $this->createEmployee('First', 'User');
        $second = $this->createEmployee('Second', 'User');
        $this->audit->write('journal', 1, 'posted', $first, JournalOrigin::User);
        $this->audit->write('account', 2, 'created', $second, JournalOrigin::User);
        $this->audit->write('journal', 3, 'posted', $first, JournalOrigin::Ai);

        $this->assertSame(2, $this->audit->search(['action' => 'posted'])['total']);
        $this->assertSame(1, $this->audit->search(['entity_type' => 'account'])['total']);
        $this->assertSame(2, $this->audit->search(['employee_id' => $first])['total']);
        $this->assertSame(1, $this->audit->search(['origin' => 'ai'])['total']);
        $this->assertSame(1, $this->audit->search(['action' => 'posted', 'origin' => 'user'])['total']);
    }

    public function testSearch_FiltersByDateRange(): void
    {
        $this->audit->write('journal', 1, 'posted', null, JournalOrigin::System);
        $this->accountingDb()->table('accounting_audit_log')->update(['created_at' => '2026-01-15 10:00:00'], ['audit_id >' => 0]);

        $this->assertSame(1, $this->audit->search(['date_from' => '2026-01-15', 'date_to' => '2026-01-15'])['total']);
        $this->assertSame(0, $this->audit->search(['date_from' => '2026-01-16'])['total']);
        $this->assertSame(0, $this->audit->search(['date_to' => '2026-01-14'])['total']);
        $this->assertSame(1, $this->audit->search(['date_from' => 'garbage'])['total']);
    }

    public function testSearch_PagesTheResults(): void
    {
        for ($i = 1; $i <= 5; $i++) {
            $this->audit->write('journal', $i, 'posted', null, JournalOrigin::System);
        }

        $page = $this->audit->search([], 'asc', 2, 3);

        $this->assertSame(5, $page['total']);
        $this->assertSame(['4', '5'], array_map('strval', array_column($page['rows'], 'entity_id')));
    }

    public function testKnown_NamesEveryActionAndEveryKindOfRecordTheLanguageFileHas(): void
    {
        $actions  = AuditLog::known('action');
        $entities = AuditLog::known('entity');

        $this->assertContains('period_closed', $actions);
        $this->assertContains('permission_granted', $actions);
        $this->assertContains('year_end_reversed', $actions);
        $this->assertContains('employee_permission', $entities);
        $this->assertContains('role', $entities);
        $this->assertNotContains('', $actions);
        $this->assertSame($actions, array_values(array_unique($actions)));

        $sorted = $actions;
        sort($sorted);
        $this->assertSame($sorted, $actions);
    }

    public function testActions_ListsTheDistinctActionsInOrder(): void
    {
        $this->audit->write('journal', 1, 'posted', null, JournalOrigin::System);
        $this->audit->write('journal', 2, 'posted', null, JournalOrigin::System);
        $this->audit->write('account', 3, 'created', null, JournalOrigin::System);

        $this->assertSame(['created', 'posted'], $this->audit->actions());
    }

    public function testSearch_ListsOnlyTheEntriesOfTheGivenBranches(): void
    {
        $second = $this->createBranch('B2');

        $this->audit->write('journal', 1, 'posted', null, JournalOrigin::User, [], 1);
        $this->audit->write('journal', 2, 'posted', null, JournalOrigin::User, [], $second);
        $this->audit->write('settings', null, 'accounting_enabled', null, JournalOrigin::System);

        $this->assertSame([2], array_map(static fn (object $row): int => (int) $row->entity_id, $this->audit->search([], branchIds: [$second])['rows']));
        $this->assertSame(3, $this->audit->search([])['total']);
    }

    public function testSearch_HidesEntriesOfTheWholeCompanyFromReadersOfSomeBranches(): void
    {
        $this->audit->write('settings', null, 'accounting_enabled', null, JournalOrigin::System);

        $this->assertSame(0, $this->audit->search([], branchIds: [1])['total']);
        $this->assertSame(1, $this->audit->search([], branchIds: null)['total']);
    }

    public function testSearch_ReturnsNothingForAnEmptyListOfBranches(): void
    {
        $this->audit->write('journal', 1, 'posted', null, JournalOrigin::User, [], 1);

        $this->assertSame(0, $this->audit->search([], branchIds: [])['total']);
    }

    public function testSearch_FiltersByBranch(): void
    {
        $second = $this->createBranch('B2');

        $this->audit->write('journal', 1, 'posted', null, JournalOrigin::User, [], 1);
        $this->audit->write('journal', 2, 'posted', null, JournalOrigin::User, [], $second);

        $result = $this->audit->search(['branch_id' => $second]);

        $this->assertSame(1, $result['total']);
        $this->assertSame($second, (int) $result['rows'][0]->branch_id);
    }

    /**
     * @return list<int>
     */
    private function entriesWritten(int $count, string $action = 'posted'): array
    {
        $rows = [];

        for ($index = 1; $index <= $count; $index++) {
            $rows[] = ['entity_type' => 'journal', 'entity_id' => $index, 'action' => $action, 'origin' => 'system'];
        }

        foreach (array_chunk($rows, 500) as $chunk) {
            $this->accountingDb()->table('accounting_audit_log')->insertBatch($chunk);
        }

        return array_map(static fn (array $row): int => (int) $row['audit_id'], $this->accountingDb()->table('accounting_audit_log')->select('audit_id')->orderBy('audit_id', 'ASC')->get()->getResultArray());
    }

    public function testStream_WalksEveryEntryOfTheFiltersOnceInTheOrderAsked(): void
    {
        $ids = $this->entriesWritten(1203);

        $this->audit->write('journal', 9999, 'reversed', null, JournalOrigin::System);

        $all  = [];
        $only = 0;

        foreach ($this->audit->stream([], 'desc') as $entry) {
            $all[] = (int) $entry->audit_id;
        }

        foreach ($this->audit->stream(['action' => 'reversed'], 'asc') as $entry) {
            $only++;
        }

        $this->assertCount(1204, $all);
        $this->assertSame($all, array_values(array_unique($all)));
        $this->assertSame(array_reverse([...$ids, $this->audit->latestId()]), $all);
        $this->assertSame(1, $only);
    }

    public function testStream_LeavesOutWhatIsWrittenWhileItGoesOn(): void
    {
        $this->entriesWritten(1200);

        $seen    = [];
        $written = false;

        foreach ($this->audit->stream([], 'desc') as $entry) {
            $seen[] = (int) $entry->audit_id;

            if (! $written) {
                $this->audit->write('journal', 5001, 'posted', null, JournalOrigin::System);
                $this->audit->write('journal', 5002, 'posted', null, JournalOrigin::System);
                $this->audit->write('journal', 5003, 'posted', null, JournalOrigin::System);

                $written = true;
            }
        }

        $this->assertCount(1200, $seen);
        $this->assertSame($seen, array_values(array_unique($seen)));
        $this->assertSame(1203, $this->audit->search([])['total']);
    }

    public function testStream_ReadsOnlyTheEntriesOfTheBranchesOfTheReader(): void
    {
        $second = $this->createBranch('B2');

        $this->audit->write('journal', 1, 'posted', null, JournalOrigin::User, [], 1);
        $this->audit->write('journal', 2, 'posted', null, JournalOrigin::User, [], $second);

        $this->assertSame([2], array_map(static fn (object $row): int => (int) $row->entity_id, iterator_to_array($this->audit->stream([], 'desc', [$second]), false)));
        $this->assertSame([], iterator_to_array($this->audit->stream([], 'desc', []), false));
    }

    public function testSearch_StopsAtTheEntryItIsToldIsTheLast(): void
    {
        $this->audit->write('journal', 1, 'posted', null, JournalOrigin::System);
        $this->audit->write('journal', 2, 'posted', null, JournalOrigin::System);

        $last = $this->audit->latestId();

        $this->audit->write('journal', 3, 'posted', null, JournalOrigin::System);

        $this->assertSame(3, $this->audit->search([])['total']);
        $this->assertSame(2, $this->audit->search(['max_audit_id' => $last])['total']);
    }

    public function testLatestId_IsZeroForAnEmptyLogAndTheNewestEntryOtherwise(): void
    {
        $this->assertSame(0, $this->audit->latestId());

        $this->audit->write('journal', 1, 'posted', null, JournalOrigin::System);

        $this->assertSame((int) $this->audit->search([])['rows'][0]->audit_id, $this->audit->latestId());
    }

    public function testEntities_ListsTheDistinctKindsOfRecordsOfTheGivenBranches(): void
    {
        $second = $this->createBranch('B2');

        $this->audit->write('treasury_transfer', 1, 'created', null, JournalOrigin::User, [], 1);
        $this->audit->write('treasury_transfer', 2, 'created', null, JournalOrigin::User, [], 1);
        $this->audit->write('supplier_advance', 3, 'created', null, JournalOrigin::User, [], $second);

        $this->assertSame(['supplier_advance', 'treasury_transfer'], $this->audit->entities());
        $this->assertSame(['supplier_advance'], $this->audit->entities([$second]));
        $this->assertSame([], $this->audit->entities([]));
    }

    public function testActions_ListsOnlyTheActionsOfTheGivenBranches(): void
    {
        $second = $this->createBranch('B2');

        $this->audit->write('journal', 1, 'posted', null, JournalOrigin::User, [], 1);
        $this->audit->write('journal', 2, 'reversed', null, JournalOrigin::User, [], $second);

        $this->assertSame(['reversed'], $this->audit->actions([$second]));
        $this->assertSame(['posted', 'reversed'], $this->audit->actions());
    }

    public function testEmployees_ListsOnlyThePeopleOfTheGivenBranches(): void
    {
        $second = $this->createBranch('B2');
        $sara   = $this->createEmployee('Sara', 'Nasser');
        $omar   = $this->createEmployee('Omar', 'Hadi');

        $this->audit->write('journal', 1, 'posted', $sara, JournalOrigin::User, [], 1);
        $this->audit->write('journal', 2, 'posted', $omar, JournalOrigin::User, [], $second);

        $this->assertSame([$omar], array_map(static fn (object $row): int => (int) $row->employee_id, $this->audit->employees([$second])));
        $this->assertCount(2, $this->audit->employees());
    }
}
