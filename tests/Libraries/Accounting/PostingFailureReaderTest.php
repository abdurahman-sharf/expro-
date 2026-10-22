<?php

namespace Tests\Libraries\Accounting;

use App\Libraries\Accounting\PostingFailureReader;
use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\DatabaseTestTrait;
use Config\Database;
use Tests\Support\AccountingFixtureTrait;

class PostingFailureReaderTest extends CIUnitTestCase
{
    use DatabaseTestTrait;
    use AccountingFixtureTrait;

    protected $migrate     = true;
    protected $migrateOnce = true;
    protected $refresh     = false;
    protected $namespace   = null;

    private PostingFailureReader $reader;

    public static function setUpBeforeClass(): void
    {
        $seeder = Database::seeder('tests');
        $seeder->call('TestDatabaseBootstrapSeeder');
    }

    protected function setUp(): void
    {
        parent::setUp();

        $this->clearJournals();
        $this->reader = new PostingFailureReader();
    }

    protected function tearDown(): void
    {
        $this->resetBranches();

        parent::tearDown();
    }

    private function failure(string $createdAt, string $source = 'sale', ?int $sourceId = 10, ?int $branchId = 1, ?int $employeeId = null, string $code = 'period_closed'): void
    {
        $this->accountingDb()->table('accounting_posting_failures')->insert([
            'source_type' => $source,
            'source_id'   => $sourceId,
            'revision'    => 1,
            'error_code'  => $code,
            'message'     => 'The date is in a closed period.',
            'employee_id' => $employeeId,
            'branch_id'   => $branchId,
            'created_at'  => $createdAt,
        ]);
    }

    public function testSearch_ListsTheNewestFailureFirst(): void
    {
        $this->failure('2026-10-01 09:00:00', sourceId: 1);
        $this->failure('2026-10-03 09:00:00', sourceId: 3);
        $this->failure('2026-10-02 09:00:00', sourceId: 2);

        $result = $this->reader->search([]);

        $this->assertSame(3, $result['total']);
        $this->assertSame([3, 2, 1], array_map(static fn (object $row): int => (int) $row->source_id, $result['rows']));
        $this->assertSame([1, 2, 3], array_map(static fn (object $row): int => (int) $row->source_id, $this->reader->search([], 'asc')['rows']));
    }

    public function testSearch_NamesTheBranchAndTheEmployee(): void
    {
        $employee = $this->employeeWithGrants(['sales']);
        $this->failure('2026-10-01 09:00:00', employeeId: $employee);

        $row = $this->reader->search([])['rows'][0];

        $this->assertSame('HQ', $row->branch_code);
        $this->assertNotSame('', trim((string) $row->employee_name));
        $this->assertSame('period_closed', $row->error_code);
    }

    public function testSearch_FiltersByDateSourceAndBranch(): void
    {
        $other = $this->createBranch('B2');
        $this->failure('2026-10-01 09:00:00', sourceId: 1);
        $this->failure('2026-10-05 09:00:00', sourceId: 2, branchId: $other);
        $this->failure('2026-10-09 23:30:00', source: 'receiving', sourceId: 3);

        $this->assertSame([3, 2], $this->sourceIds($this->reader->search(['date_from' => '2026-10-02'])));
        $this->assertSame([2, 1], $this->sourceIds($this->reader->search(['date_to' => '2026-10-08'])));
        $this->assertSame([3], $this->sourceIds($this->reader->search(['date_from' => '2026-10-09', 'date_to' => '2026-10-09'])));
        $this->assertSame([3], $this->sourceIds($this->reader->search(['source_type' => 'receiving'])));
        $this->assertSame([2], $this->sourceIds($this->reader->search(['branch_id' => $other])));
    }

    public function testSearch_IgnoresADateThatIsNotADate(): void
    {
        $this->failure('2026-10-01 09:00:00');

        $this->assertSame(1, $this->reader->search(['date_from' => 'yesterday', 'date_to' => '31/12/2026'])['total']);
    }

    public function testSearch_ShowsAReaderOnlyTheFailuresOfTheirBranches(): void
    {
        $other = $this->createBranch('B2');
        $this->failure('2026-10-01 09:00:00', sourceId: 1);
        $this->failure('2026-10-02 09:00:00', sourceId: 2, branchId: $other);
        $this->failure('2026-10-03 09:00:00', sourceId: 3, branchId: null);

        $this->assertSame([1, 2, 3], $this->sourceIds($this->reader->search([], 'asc', 25, 0, null)));
        $this->assertSame([2], $this->sourceIds($this->reader->search([], 'asc', 25, 0, [$other])));
        $this->assertSame([], $this->sourceIds($this->reader->search([], 'asc', 25, 0, [])));
    }

    public function testSearch_PagesTheRowsAndCapsThePageSize(): void
    {
        for ($i = 1; $i <= 5; $i++) {
            $this->failure("2026-10-0{$i} 09:00:00", sourceId: $i);
        }

        $page = $this->reader->search([], 'asc', 2, 2);

        $this->assertSame(5, $page['total']);
        $this->assertSame([3, 4], $this->sourceIds($page));
        $this->assertCount(5, $this->reader->search([], 'asc', 100000)['rows']);
    }

    public function testSources_ListsTheKindsOfDocumentThatFailedWithinTheReadersBranches(): void
    {
        $other = $this->createBranch('B2');
        $this->failure('2026-10-01 09:00:00');
        $this->failure('2026-10-02 09:00:00', source: 'receiving', branchId: $other);

        $this->assertSame(['receiving', 'sale'], $this->reader->sources());
        $this->assertSame(['receiving'], $this->reader->sources([$other]));
        $this->assertSame([], $this->reader->sources([]));
    }

    /**
     * @param array{total: int, rows: list<object>} $result
     *
     * @return list<int>
     */
    private function sourceIds(array $result): array
    {
        return array_map(static fn (object $row): int => (int) $row->source_id, $result['rows']);
    }
}
