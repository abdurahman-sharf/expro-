<?php

namespace Tests\Libraries\Accounting;

use App\Libraries\Accounting\YearEndEntries;
use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\DatabaseTestTrait;
use Config\Database;
use Tests\Support\AccountingFixtureTrait;
use Tests\Support\EmployeeFixtureTrait;
use Tests\Support\ReportFixtureTrait;

/**
 * Which journals of the books are the ones that close a year: by their source, or by their type.
 */
class YearEndEntriesTest extends CIUnitTestCase
{
    use DatabaseTestTrait;
    use AccountingFixtureTrait;
    use EmployeeFixtureTrait;
    use ReportFixtureTrait;

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

        $this->resetBranches();
        $this->reinstallChart();
        $this->setConfigValue('accounting_base_currency', 'SAR');
    }

    protected function tearDown(): void
    {
        $this->clearJournals();
        $this->reinstallChart();
        $this->resetBranches();

        parent::tearDown();
    }

    /**
     * @return list<int> The journals that are left once the ones that close a year are left out.
     */
    private function kept(string $alias = 'j'): array
    {
        $builder = $this->accountingDb()->table("accounting_journals {$alias}")->select("{$alias}.journal_id");

        return array_map(static fn (object $row): int => (int) $row->journal_id, YearEndEntries::leaveOut($builder, $alias)->orderBy("{$alias}.journal_id")->get()->getResult());
    }

    private function journalOf(string $sourceType, string $entryType = 'general'): int
    {
        $journalId = $this->cashSale('2026-10-05', 1, '10.00');

        $this->accountingDb()->table('accounting_journals')->where('journal_id', $journalId)->update(['source_type' => $sourceType, 'entry_type' => $entryType, 'source_id' => $journalId]);

        return $journalId;
    }

    public function testLeaveOut_ExcludesAJournalWhoseSourceIsYearEnd(): void
    {
        $plain   = $this->journalOf('sale');
        $closing = $this->journalOf(YearEndEntries::SOURCE_TYPE);

        $this->assertSame([$plain], $this->kept());
        $this->assertNotContains($closing, $this->kept());
    }

    public function testLeaveOut_ExcludesAJournalWhoseTypeIsYearEnd(): void
    {
        $plain   = $this->journalOf('sale');
        $closing = $this->journalOf('sale', YearEndEntries::ENTRY_TYPE);

        $this->assertSame([$plain], $this->kept());
        $this->assertNotContains($closing, $this->kept());
    }

    public function testLeaveOut_KeepsEveryOtherJournal(): void
    {
        $journals = [
            $this->journalOf('manual'),
            $this->journalOf('sale', 'revenue'),
            $this->journalOf('sale', 'cogs'),
            $this->journalOf('opening'),
            $this->journalOf('reversal'),
            $this->journalOf('year_end_note'),
        ];

        $this->assertSame($journals, $this->kept());
    }

    public function testLeaveOut_ReadsTheAliasItIsGiven(): void
    {
        $plain = $this->journalOf('sale');
        $this->journalOf(YearEndEntries::SOURCE_TYPE);

        $this->assertSame([$plain], $this->kept('journal'));
    }
}
