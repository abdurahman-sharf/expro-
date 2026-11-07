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
