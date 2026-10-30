<?php

namespace Tests\Libraries\Accounting\Readiness;

use App\Libraries\Accounting\Readiness\CheckResult;
use App\Libraries\Accounting\Readiness\CheckStatus;
use App\Libraries\Accounting\Readiness\LedgerChecks;
use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\DatabaseTestTrait;
use Config\Database;
use Tests\Support\AccountingFixtureTrait;
use Tests\Support\DatabaseFingerprintTrait;

/**
 * The books as the readiness report reads them: journals that do not balance or hold no lines,
 * posted journals against the period locks and the fiscal years, documents posted twice, and the
 * counters of numbers. Each fixture writes the rows directly, which is how a broken book comes to
 * exist, because the services refuse to write one.
 */
class LedgerChecksTest extends CIUnitTestCase
{
    use DatabaseTestTrait;
    use AccountingFixtureTrait;
    use DatabaseFingerprintTrait;

    protected $migrate     = true;
    protected $migrateOnce = true;
    protected $refresh     = false;
    protected $namespace   = null;

    private LedgerChecks $checks;

    public static function setUpBeforeClass(): void
    {
        $seeder = Database::seeder('tests');
        $seeder->call('TestDatabaseBootstrapSeeder');
    }

    protected function setUp(): void
    {
        parent::setUp();

        $this->reinstallChart();
        $this->checks = new LedgerChecks($this->accountingDb());
    }

    protected function tearDown(): void
    {
        $db = $this->accountingDb();

        $db->table('document_numbers')->truncate();
        $db->table('document_sequences')->truncate();
        $this->clearJournals();

        parent::tearDown();
    }

    private function check(string $id): CheckResult
    {
        foreach ($this->checks->run() as $result) {
            if ($result->id === $id) {
                return $result;
            }
        }

        $this->fail("No check {$id}.");
    }

    /**
     * @param array<string, mixed>          $journal
     * @param list<array{string, string}>|null $lines Debit and credit of each line, a balanced pair of 100.00 when null.
     */
    private function journal(array $journal = [], ?array $lines = null): int
    {
        $db = $this->accountingDb();

        $db->table('accounting_journals')->insert([
            'company_id'    => 1,
            'branch_id'     => 1,
            'series'        => 'manual',
            'journal_date'  => '2026-10-21',
            'status'        => 'posted',
            'source_type'   => 'manual',
            'source_id'     => null,
            'entry_type'    => 'general',
            'revision'      => 1,
            'origin'        => 'user',
            'currency_code' => 'SAR',
            'total_debit'   => '100.0000',
            'total_credit'  => '100.0000',
            'posted_at'     => date('Y-m-d H:i:s'),
            ...$journal,
        ]);

        $journalId = (int) $db->insertID();

        foreach ($lines ?? [['100.00', '0'], ['0', '100.00']] as $index => [$debit, $credit]) {
            $db->table('accounting_journal_lines')->insert([
                'journal_id' => $journalId,
                'line_no'    => $index + 1,
                'account_id' => $this->accountId($index % 2 === 0 ? 'cash' : 'sales_revenue'),
                'debit'      => $debit,
                'credit'     => $credit,
            ]);
        }

        return $journalId;
    }

    private function closure(string $through, ?int $branchId = null, string $createdAt = '2026-01-01 00:00:00', int $active = 1): void
    {
        $this->accountingDb()->table('accounting_closed_periods')->insert(['company_id' => 1, 'branch_id' => $branchId, 'date_from' => '2020-01-01', 'date_to' => $through, 'active' => $active, 'created_at' => $createdAt]);
    }

    public function testRun_GivesTheFiveChecksWithTheirReferences(): void
    {
        $results = $this->checks->run();

        $this->assertSame(['journals_unbalanced', 'journals_without_lines', 'period_locks', 'source_keys', 'numbering'], array_map(static fn (CheckResult $result): string => $result->id, $results));
        $this->assertSame(['LEDGER-BALANCE', 'LEDGER-LINES', 'LEDGER-PERIODS', 'LEDGER-SOURCE-KEYS', 'WP8-NUMBERING'], array_map(static fn (CheckResult $result): string => $result->ref, $results));
    }

    public function testRun_PassesEveryCheckOnBooksThatAreInOrder(): void
    {
        $this->journal(['number' => '000001']);
        $this->accountingDb()->table('accounting_sequences')->where('series', 'manual')->update(['last_number' => 1]);

        foreach ($this->checks->run() as $result) {
            $this->assertSame(CheckStatus::Pass, $result->status, $result->id . ': ' . $result->summary);
        }
    }

    public function testJournalsUnbalanced_FailsAndListsAJournalWhoseLinesDoNotBalance(): void
    {
        $journalId = $this->journal(['number' => '000007', 'source_type' => 'sale', 'source_id' => 12], [['100.00', '0'], ['0', '90.00']]);

        $result = $this->check('journals_unbalanced');

        $this->assertSame(CheckStatus::Fail, $result->status);
        $this->assertSame(["journal #{$journalId} (000007, sale 12) dated 2026-10-21: lines debit 100.0000 and credit 90.0000"], $result->findings);
        $this->assertSame(1, $result->data['count']);
        $this->assertFalse($result->data['truncated']);
    }

    public function testJournalsUnbalanced_FailsWhenTheHeaderTotalsDifferFromTheLines(): void
    {
        $journalId = $this->journal(['total_debit' => '120.0000'], [['100.00', '0'], ['0', '100.00']]);

        $result = $this->check('journals_unbalanced');

        $this->assertSame(CheckStatus::Fail, $result->status);
        $this->assertStringContainsString("journal #{$journalId}", $result->findings[0]);
        $this->assertStringContainsString('header says debit 120.0000 and credit 100.0000', $result->findings[0]);
    }

    public function testJournalsUnbalanced_FailsWhenTheHeaderCreditDiffersFromTheLines(): void
    {
        $this->journal(['total_credit' => '80.0000']);

        $this->assertSame(CheckStatus::Fail, $this->check('journals_unbalanced')->status);
    }

    public function testJournalsUnbalanced_CountsAReversedJournalToo(): void
    {
        $this->journal(['status' => 'reversed'], [['100.00', '0'], ['0', '10.00']]);

        $this->assertSame(CheckStatus::Fail, $this->check('journals_unbalanced')->status);
    }

    public function testJournalsUnbalanced_LeavesADraftAlone(): void
    {
        $this->journal(['status' => 'draft'], [['100.00', '0'], ['0', '10.00']]);

        $this->assertSame(CheckStatus::Pass, $this->check('journals_unbalanced')->status);
    }

    public function testJournalsUnbalanced_LeavesAJournalWithoutLinesToTheOtherCheck(): void
    {
        $this->journal([], []);

        $this->assertSame(CheckStatus::Pass, $this->check('journals_unbalanced')->status);
    }

    public function testJournalsUnbalanced_IgnoresTheJournalsOfAnotherCompany(): void
    {
        $db = $this->accountingDb();
        $db->table('companies')->insert(['name' => 'Another company']);
        $other = (int) $db->insertID();

        try {
            $this->journal(['company_id' => $other], [['100.00', '0'], ['0', '10.00']]);

            $this->assertSame(CheckStatus::Pass, $this->check('journals_unbalanced')->status);
        } finally {
            $this->clearJournals();
            $db->table('companies')->where('company_id', $other)->delete();
        }
    }

    public function testJournalsWithoutLines_FailsAndListsAPostedJournalThatHasNone(): void
    {
        $journalId = $this->journal(['number' => '000009', 'source_type' => 'purchase', 'source_id' => 4], []);

        $result = $this->check('journals_without_lines');

        $this->assertSame(CheckStatus::Fail, $result->status);
        $this->assertSame(["journal #{$journalId} (000009, purchase 4) dated 2026-10-21 has no lines"], $result->findings);
    }

    public function testJournalsWithoutLines_CountsAReversedJournalAndLeavesADraftAlone(): void
    {
        $this->journal(['status' => 'draft'], []);
        $this->assertSame(CheckStatus::Pass, $this->check('journals_without_lines')->status);

        $this->journal(['status' => 'reversed'], []);
        $this->assertSame(CheckStatus::Fail, $this->check('journals_without_lines')->status);
    }

    public function testJournalsWithoutLines_PassesWhenEveryJournalHasLines(): void
    {
        $this->journal();

        $this->assertSame(CheckStatus::Pass, $this->check('journals_without_lines')->status);
    }

    public function testJournalsWithoutLines_IgnoresTheJournalsOfAnotherCompany(): void
    {
        $db = $this->accountingDb();
        $db->table('companies')->insert(['name' => 'Another company']);
        $other = (int) $db->insertID();

        try {
            $this->journal(['company_id' => $other], []);

            $this->assertSame(CheckStatus::Pass, $this->check('journals_without_lines')->status);
        } finally {
            $this->clearJournals();
            $db->table('companies')->where('company_id', $other)->delete();
        }
    }

    public function testPeriodLocks_FailsForAJournalPostedAfterThePeriodWasClosed(): void
    {
        $this->closure('2026-10-31', null, '2026-11-01 08:00:00');
        $journalId = $this->journal(['number' => '000003', 'posted_at' => '2026-11-02 10:00:00']);

        $result = $this->check('period_locks');

        $this->assertSame(CheckStatus::Fail, $result->status);
        $this->assertSame(["journal #{$journalId} (000003) dated 2026-10-21 in branch 1 was posted at 2026-11-02 10:00:00 into a period closed through 2026-10-31"], $result->findings);
        $this->assertSame(1, $result->data['closed']);
        $this->assertSame(0, $result->data['outside_fiscal_years']);
    }

    public function testPeriodLocks_PassesForAJournalPostedBeforeThePeriodWasClosed(): void
    {
        $this->closure('2026-10-31', null, '2026-11-01 08:00:00');
        $this->journal(['posted_at' => '2026-10-22 10:00:00']);

        $this->assertSame(CheckStatus::Pass, $this->check('period_locks')->status);
    }

    public function testPeriodLocks_PassesForAJournalDatedAfterTheClosing(): void
    {
        $this->closure('2026-10-31', null, '2026-11-01 08:00:00');
        $this->journal(['journal_date' => '2026-11-05', 'posted_at' => '2026-11-06 10:00:00']);

        $this->assertSame(CheckStatus::Pass, $this->check('period_locks')->status);
    }

    public function testPeriodLocks_CountsAJournalDatedOnTheLastDayClosed(): void
    {
        $this->closure('2026-10-21', null, '2026-11-01 08:00:00');
        $this->journal(['posted_at' => '2026-11-02 10:00:00']);

        $this->assertSame(CheckStatus::Fail, $this->check('period_locks')->status);
    }

    public function testPeriodLocks_LeavesAReopenedClosingAlone(): void
    {
        $this->closure('2026-10-31', null, '2026-11-01 08:00:00', 0);
        $this->journal(['posted_at' => '2026-11-02 10:00:00']);

        $this->assertSame(CheckStatus::Pass, $this->check('period_locks')->status);
    }

    public function testPeriodLocks_AppliesTheClosingOfABranchToThatBranchOnly(): void
    {
        $second = $this->createBranch('B2');
        $this->closure('2026-10-31', $second, '2026-11-01 08:00:00');

        $this->journal(['branch_id' => 1, 'posted_at' => '2026-11-02 10:00:00']);
        $this->assertSame(CheckStatus::Pass, $this->check('period_locks')->status);

        $this->journal(['branch_id' => $second, 'posted_at' => '2026-11-02 10:00:00']);
        $this->assertSame(CheckStatus::Fail, $this->check('period_locks')->status);
    }

    public function testPeriodLocks_AppliesTheClosingOfTheCompanyToEveryBranch(): void
    {
        $second = $this->createBranch('B2');
        $this->closure('2026-10-31', null, '2026-11-01 08:00:00');
        $this->journal(['branch_id' => $second, 'posted_at' => '2026-11-02 10:00:00']);

        $this->assertSame(CheckStatus::Fail, $this->check('period_locks')->status);
    }

    public function testPeriodLocks_ListsAJournalOnceWhenSeveralClosingsCoverIt(): void
    {
        $this->closure('2026-10-31', null, '2026-11-01 08:00:00');
        $this->closure('2026-12-31', null, '2027-01-01 08:00:00');
        $this->journal(['posted_at' => '2027-01-02 10:00:00']);

        $result = $this->check('period_locks');

        $this->assertCount(1, $result->findings);
        $this->assertStringContainsString('closed through 2026-12-31', $result->findings[0]);
    }

    public function testPeriodLocks_FailsForAJournalOutsideEveryFiscalYear(): void
    {
        $journalId = $this->journal(['journal_date' => '2040-03-01', 'number' => '000005']);

        $result = $this->check('period_locks');

        $this->assertSame(CheckStatus::Fail, $result->status);
        $this->assertSame(["journal #{$journalId} (000005) dated 2040-03-01 in branch 1 is outside every fiscal year"], $result->findings);
        $this->assertSame(1, $result->data['outside_fiscal_years']);
        $this->assertSame(0, $result->data['closed']);
    }

    public function testPeriodLocks_LeavesADraftOutsideEveryFiscalYearAlone(): void
    {
        $this->journal(['journal_date' => '2040-03-01', 'status' => 'draft']);

        $this->assertSame(CheckStatus::Pass, $this->check('period_locks')->status);
    }

    public function testPeriodLocks_ListsBothKindsTogether(): void
    {
        $this->closure('2026-10-31', null, '2026-11-01 08:00:00');
        $this->journal(['posted_at' => '2026-11-02 10:00:00']);
        $this->journal(['journal_date' => '2040-03-01']);

        $result = $this->check('period_locks');

        $this->assertCount(2, $result->findings);
        $this->assertSame(1, $result->data['closed']);
        $this->assertSame(1, $result->data['outside_fiscal_years']);
    }

    public function testSourceKeys_FailsWhenOneDocumentStandsAsTwoPostedJournals(): void
    {
        $first  = $this->journal(['source_type' => 'sale', 'source_id' => 12, 'revision' => 1]);
        $second = $this->journal(['source_type' => 'sale', 'source_id' => 12, 'revision' => 2]);

        $result = $this->check('source_keys');

        $this->assertSame(CheckStatus::Fail, $result->status);
        $this->assertSame(["sale 12 (general) stands as 2 posted journals: {$first},{$second}"], $result->findings);
        $this->assertSame(1, $result->data['documents']);
    }

    public function testSourceKeys_PassesForADocumentThatWasEditedAndPostedAgain(): void
    {
        $this->journal(['source_type' => 'sale', 'source_id' => 12, 'revision' => 1, 'status' => 'reversed']);
        $this->journal(['source_type' => 'sale', 'source_id' => 12, 'revision' => 2]);

        $this->assertSame(CheckStatus::Pass, $this->check('source_keys')->status);
    }

    public function testSourceKeys_PassesForTheRevenueAndTheCostOfOneSale(): void
    {
        $this->journal(['source_type' => 'sale', 'source_id' => 12, 'entry_type' => 'revenue']);
        $this->journal(['source_type' => 'sale', 'source_id' => 12, 'entry_type' => 'cogs']);

        $this->assertSame(CheckStatus::Pass, $this->check('source_keys')->status);
    }

    public function testSourceKeys_PassesForManyManualJournalsAndAReversalOfEach(): void
    {
        $one = $this->journal(['number' => '000001']);
        $two = $this->journal(['number' => '000002']);
        $this->journal(['source_type' => 'reversal', 'source_id' => $one, 'reverses_journal_id' => $one, 'status' => 'posted']);
        $this->journal(['source_type' => 'reversal', 'source_id' => $two, 'reverses_journal_id' => $two, 'status' => 'posted']);

        $this->assertSame(CheckStatus::Pass, $this->check('source_keys')->status);
    }

    public function testSourceKeys_LeavesADraftAlone(): void
    {
        $this->journal(['source_type' => 'sale', 'source_id' => 12, 'revision' => 1]);
        $this->journal(['source_type' => 'sale', 'source_id' => 12, 'revision' => 2, 'status' => 'draft']);

        $this->assertSame(CheckStatus::Pass, $this->check('source_keys')->status);
    }

    public function testSourceKeys_FailsWhenAJournalWasReversedTwice(): void
    {
        $original = $this->journal(['status' => 'reversed']);
        $first    = $this->journal(['source_type' => 'reversal', 'source_id' => $original, 'reverses_journal_id' => $original, 'revision' => 1]);
        $second   = $this->journal(['source_type' => 'reversal', 'source_id' => $original, 'reverses_journal_id' => $original, 'revision' => 2]);

        $result = $this->check('source_keys');

        $this->assertSame(CheckStatus::Fail, $result->status);
        $this->assertSame(["journal #{$original} was reversed 2 times by journals {$first},{$second}"], $result->findings);
        $this->assertSame(1, $result->data['reversed_twice']);
    }

    public function testSourceKeys_IgnoresTheJournalsOfAnotherCompany(): void
    {
        $db = $this->accountingDb();
        $db->table('companies')->insert(['name' => 'Another company']);
        $other = (int) $db->insertID();

        try {
            $this->journal(['company_id' => $other, 'source_type' => 'sale', 'source_id' => 12, 'revision' => 1]);
            $this->journal(['company_id' => $other, 'source_type' => 'sale', 'source_id' => 12, 'revision' => 2]);

            $this->assertSame(CheckStatus::Pass, $this->check('source_keys')->status);
        } finally {
            $this->clearJournals();
            $db->table('companies')->where('company_id', $other)->delete();
        }
    }

    public function testNumbering_PassesWithNoSequenceSwitchedOn(): void
    {
        $result = $this->check('numbering');

        $this->assertSame(CheckStatus::Pass, $result->status);
        $this->assertCount(9, $result->data['sequences']);
        $this->assertFalse($result->data['manual_journals_behind']);
    }

    private function sequence(string $type, bool $enabled, int $next, string $prefix = 'EXP-'): void
    {
        $this->accountingDb()->table('document_sequences')->insert(['company_id' => 1, 'doc_type' => $type, 'enabled' => $enabled ? 1 : 0, 'prefix' => $prefix, 'padding' => 4, 'next_number' => $next, 'prevent_duplicates' => 1]);
    }

    private function issued(string $type, int $sequenceNumber, string $number, int $sourceId = 7): void
    {
        $this->accountingDb()->table('document_numbers')->insert(['company_id' => 1, 'doc_type' => $type, 'source_table' => 'expenses', 'source_id' => $sourceId, 'sequence_number' => $sequenceNumber, 'number' => $number]);
    }

    public function testNumbering_FailsForASwitchedOnSequenceThatWouldGiveANumberAlreadyGiven(): void
    {
        $this->sequence('expense', true, 3);
        $this->issued('expense', 10, 'EXP-0010');

        $result = $this->check('numbering');

        $this->assertSame(CheckStatus::Fail, $result->status);
        $this->assertSame(['expense is switched on and would give EXP-0003 next, but numbers up to 10 are already given'], $result->findings);
        $this->assertTrue($result->data['sequences'][8]['behind']);
    }

    public function testNumbering_PassesForASwitchedOnSequenceThatIsAheadOfTheNumbersGiven(): void
    {
        $this->sequence('expense', true, 11);
        $this->issued('expense', 10, 'EXP-0010');

        $this->assertSame(CheckStatus::Pass, $this->check('numbering')->status);
    }

    public function testNumbering_OnlyNotesASwitchedOffSequenceThatIsBehind(): void
    {
        $this->sequence('expense', false, 1);
        $this->issued('expense', 10, 'EXP-0010');

        $result = $this->check('numbering');

        $this->assertSame(CheckStatus::Pass, $result->status);
        $this->assertSame(['expense is switched off: it rises to 11 on its own when switched on'], $result->findings);
    }

    public function testNumbering_FailsWhenTheManualJournalSeriesIsBehindItsJournals(): void
    {
        $this->journal(['number' => '000007']);

        $result = $this->check('numbering');

        $this->assertSame(CheckStatus::Fail, $result->status);
        $this->assertSame(['the manual journal series stands at 0 but a manual journal already carries number 7'], $result->findings);
        $this->assertTrue($result->data['manual_journals_behind']);
    }

    public function testNumbering_PassesWhenTheManualSeriesHasReachedItsHighestJournal(): void
    {
        $this->journal(['number' => '000007']);
        $this->accountingDb()->table('accounting_sequences')->where('series', 'manual')->update(['last_number' => 7]);

        $this->assertSame(CheckStatus::Pass, $this->check('numbering')->status);
    }

    public function testNumbering_IgnoresJournalNumbersThatAreNotDigitsAndOtherSeries(): void
    {
        $this->journal(['number' => 'A-0099']);
        $this->journal(['series' => 'other', 'number' => '000050']);

        $this->assertSame(CheckStatus::Pass, $this->check('numbering')->status);
    }

    public function testRun_ChangesNoRow(): void
    {
        $this->journal(['number' => '000007'], [['100.00', '0'], ['0', '10.00']]);
        $this->closure('2026-10-31', null, '2026-11-01 08:00:00');
        $this->sequence('expense', true, 3);
        $this->issued('expense', 10, 'EXP-0010');
        $before = $this->databaseFingerprint();

        $this->checks->run();

        $this->assertDatabaseUnchanged($before);
    }
}
