<?php

namespace Tests\Libraries\Accounting;

use App\Libraries\Accounting\JournalDraft;
use App\Libraries\Accounting\JournalLineDraft;
use App\Libraries\Accounting\PostingService;
use App\Libraries\Accounting\YearEndEntries;
use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\DatabaseTestTrait;
use Config\Database;
use Tests\Support\AccountingFixtureTrait;
use Tests\Support\EmployeeFixtureTrait;
use Tests\Support\ReportFixtureTrait;

/**
 * Which journals of the books are the ones that close a year: those whose type is year_end, the one
 * definition, and the reversals of them, which a period leaves out with them.
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

        return array_map(static fn (object $row): int => (int) $row->journal_id, YearEndEntries::leaveOut($builder, $this->accountingDb(), $alias)->orderBy("{$alias}.journal_id")->get()->getResult());
    }

    private function journalOf(string $sourceType, string $entryType = 'general'): int
    {
        $journalId = $this->cashSale('2026-10-05', 1, '10.00');

        $this->accountingDb()->table('accounting_journals')->where('journal_id', $journalId)->update(['source_type' => $sourceType, 'entry_type' => $entryType, 'source_id' => $journalId]);

        return $journalId;
    }

    private function closingOf(): int
    {
        return $this->journalOf(YearEndEntries::SOURCE_TYPE, YearEndEntries::ENTRY_TYPE);
    }

    private function reversalOf(int $journalId): int
    {
        $journal = $this->accountingDb()->table('accounting_journals')->where('journal_id', $journalId)->get()->getRow();
        $lines   = array_map(
            static fn (object $line): JournalLineDraft => new JournalLineDraft((int) $line->account_id, debit: $line->credit, credit: $line->debit),
            $this->accountingDb()->table('accounting_journal_lines')->where('journal_id', $journalId)->get()->getResult(),
        );

        return (new PostingService())->post(new JournalDraft(
            date: '2027-01-05',
            lines: $lines,
            sourceType: JournalDraft::SOURCE_REVERSAL,
            sourceId: $journalId,
            description: 'Reopen the year',
            currencyCode: 'SAR',
            reversesJournalId: $journalId,
            branchId: (int) $journal->branch_id,
        ))->journalId;
    }

    private function reverseThrough(int $journalId, int $reversesJournalId): void
    {
        $this->accountingDb()->table('accounting_journals')->where('journal_id', $journalId)->update(['reverses_journal_id' => $reversesJournalId]);
    }

    public function testMarks_ReturnsTrueForAJournalWhoseTypeIsYearEnd(): void
    {
        $this->assertTrue(YearEndEntries::marks(YearEndEntries::ENTRY_TYPE, 'sale'));
    }

    public function testMarks_ReturnsTrueForAJournalWhoseSourceIsYearEnd(): void
    {
        $this->assertTrue(YearEndEntries::marks('general', YearEndEntries::SOURCE_TYPE));
    }

    public function testMarks_ReturnsFalseForEveryOtherJournal(): void
    {
        foreach ([['general', 'manual'], ['revenue', 'sale'], ['cogs', 'sale'], ['general', 'reversal'], ['general', 'year_end_note'], ['general', 'opening']] as [$type, $source]) {
            $this->assertFalse(YearEndEntries::marks($type, $source), "{$type} {$source}");
        }
    }

    public function testLeaveOut_KeepsAJournalWhoseSourceIsYearEndWhenItsTypeIsNot(): void
    {
        $plain = $this->journalOf('sale');
        $named = $this->journalOf(YearEndEntries::SOURCE_TYPE);

        $this->assertSame([$plain, $named], $this->kept(), 'The type is the one definition of a closing, not the source');
    }

    public function testLeaveOut_ExcludesAJournalWhoseSourceAndTypeAreYearEnd(): void
    {
        $plain   = $this->journalOf('sale');
        $closing = $this->closingOf();

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

    public function testLeaveOut_ExcludesTheReversalOfAJournalThatClosesAYear(): void
    {
        $plain    = $this->journalOf('sale');
        $closing  = $this->closingOf();
        $reversal = $this->reversalOf($closing);

        $this->assertSame([$plain], $this->kept());
        $this->assertNotContains($reversal, $this->kept());
    }

    public function testLeaveOut_ExcludesTheReversalOfAClosingWhoseTypeIsYearEnd(): void
    {
        $plain    = $this->journalOf('sale');
        $closing  = $this->journalOf('sale', YearEndEntries::ENTRY_TYPE);
        $reversal = $this->reversalOf($closing);

        $this->assertSame([$plain], $this->kept());
        $this->assertNotContains($reversal, $this->kept());
    }

    public function testLeaveOut_KeepsTheReversalOfAJournalThatDoesNotCloseAYear(): void
    {
        $plain    = $this->journalOf('sale');
        $reversal = $this->reversalOf($plain);
        $other    = $this->journalOf('manual');

        $this->assertSame([$plain, $reversal, $other], $this->kept());
    }

    public function testLeaveOut_ExcludesEveryReversalDownAChainFromAClosing(): void
    {
        $plain    = $this->journalOf('sale');
        $closing  = $this->closingOf();
        $first    = $this->reversalOf($closing);
        $second   = $this->journalOf('reversal');
        $third    = $this->journalOf('reversal');
        $ordinary = $this->journalOf('reversal');
        $deeper   = $this->journalOf('reversal');

        $this->reverseThrough($second, $first);
        $this->reverseThrough($third, $second);
        $this->reverseThrough($deeper, $ordinary);
        $this->reverseThrough($ordinary, $plain);

        $this->assertSame([$plain, $ordinary, $deeper], $this->kept(), 'A chain of reversals that starts at a closing is left out whole, and one that starts anywhere else is kept');
    }

    public function testLeaveOut_ExcludesTheReversalOfAClosingThatWasReplaced(): void
    {
        $plain    = $this->journalOf('sale');
        $first    = $this->closingOf();
        $reversal = $this->reversalOf($first);
        $second   = $this->closingOf();

        $this->assertSame([$plain], $this->kept());
        $this->assertNotContains($reversal, $this->kept());
        $this->assertNotContains($second, $this->kept());
    }

    public function testLeaveOut_EndsOnJournalsThatReverseEachOther(): void
    {
        $plain   = $this->journalOf('sale');
        $closing = $this->closingOf();
        $loop    = $this->journalOf('reversal');
        $back    = $this->journalOf('reversal');

        $this->reverseThrough($loop, $closing);
        $this->reverseThrough($back, $loop);
        $this->reverseThrough($closing, $back);

        $this->assertSame([$plain], $this->kept());
    }

    public function testLeaveOut_ReadsTheAliasItIsGiven(): void
    {
        $plain = $this->journalOf('sale');
        $this->closingOf();

        $this->assertSame([$plain], $this->kept('journal'));
    }
}
