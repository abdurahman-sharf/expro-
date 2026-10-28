<?php

namespace Tests\Libraries\Accounting;

use App\Libraries\Accounting\InterBranchLegs;
use App\Libraries\Accounting\JournalDraft;
use App\Libraries\Accounting\JournalLineDraft;
use App\Libraries\Accounting\PostingService;
use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\DatabaseTestTrait;
use Config\Database;
use Tests\Support\AccountingFixtureTrait;

/**
 * The branch on the other side of a journal that is on the accounts between branches, and the
 * journals that stand together because of it.
 */
class InterBranchLegsTest extends CIUnitTestCase
{
    use DatabaseTestTrait;
    use AccountingFixtureTrait;

    protected $migrate     = true;
    protected $migrateOnce = true;
    protected $refresh     = false;
    protected $namespace   = null;

    private InterBranchLegs $legs;
    private int $branch;

    public static function setUpBeforeClass(): void
    {
        $seeder = Database::seeder('tests');
        $seeder->call('TestDatabaseBootstrapSeeder');
    }

    protected function setUp(): void
    {
        parent::setUp();

        $this->reinstallChart();
        $this->setConfigValue('accounting_base_currency', 'SAR');

        $this->legs   = new InterBranchLegs();
        $this->branch = $this->createBranch('B2');
    }

    protected function tearDown(): void
    {
        $this->clearJournals();
        $this->reinstallChart();

        parent::tearDown();
    }

    private function journal(string $systemKey, bool $debit, int $branchId, int $number, string $date = '2026-10-21'): int
    {
        $account = $this->accountId($systemKey);
        $other   = $this->accountId('opening_balance');
        $lines   = $debit
            ? [new JournalLineDraft($account, debit: '10.00'), new JournalLineDraft($other, credit: '10.00')]
            : [new JournalLineDraft($other, debit: '10.00'), new JournalLineDraft($account, credit: '10.00')];

        return (new PostingService())->post(new JournalDraft(date: $date, lines: $lines, sourceType: 'leg_test', sourceId: $number, branchId: $branchId))->journalId;
    }

    /**
     * Two journals of one document, one for each branch, written down as a pair.
     *
     * @return array{int, int}
     */
    private function pair(int $document, int $revision = 1): array
    {
        $own   = $this->journal('branches_receivable', true, 1, $document * 10 + $revision);
        $other = $this->journal('branches_payable', false, $this->branch, $document * 10 + $revision + 5);

        $this->legs->record($own, $this->accountId('branches_receivable'), InterBranchLegs::RECEIVABLE, $this->branch, 'leg_document', $document, $revision);
        $this->legs->record($other, $this->accountId('branches_payable'), InterBranchLegs::PAYABLE, 1, 'leg_document', $document, $revision);

        return [$own, $other];
    }

    public function testRecord_WritesTheBranchOnTheOtherSideOfTheJournalOnceForEachAccount(): void
    {
        [$own] = $this->pair(1);

        $this->legs->record($own, $this->accountId('branches_receivable'), InterBranchLegs::RECEIVABLE, $this->branch, 'leg_document', 1, 1);

        $rows = $this->accountingDb()->table('accounting_interbranch_legs')->where('journal_id', $own)->get()->getResult();

        $this->assertCount(1, $rows);
        $this->assertSame([$this->branch, 'receivable', 'leg_document', 1, 1], [(int) $rows[0]->counterpart_branch_id, $rows[0]->side, $rows[0]->document_type, (int) $rows[0]->document_id, (int) $rows[0]->revision]);
    }

    public function testIsPaired_SaysWhetherAJournalHasARecord(): void
    {
        [$own] = $this->pair(1);

        $alone = $this->journal('branches_receivable', true, 1, 99);

        $this->assertTrue($this->legs->isPaired($own));
        $this->assertFalse($this->legs->isPaired($alone));
        $this->assertFalse($this->legs->isPaired(99999));
    }

    public function testPartnersOf_ListsTheOtherJournalsOfTheSameDocumentAndRevision(): void
    {
        [$own, $other] = $this->pair(1);

        $this->assertSame([$other], array_map(static fn (object $journal): int => (int) $journal->journal_id, $this->legs->partnersOf($own)));
        $this->assertSame([$own], array_map(static fn (object $journal): int => (int) $journal->journal_id, $this->legs->partnersOf($other)));
        $this->assertSame([$this->branch], array_map(static fn (object $journal): int => (int) $journal->branch_id, $this->legs->partnersOf($own)));
    }

    public function testPartnersOf_LeavesOutTheJournalsOfAnotherDocumentOrAnotherRevision(): void
    {
        [$own]     = $this->pair(1, 1);
        [$again]   = $this->pair(1, 2);
        [, $third] = $this->pair(2, 1);

        $partners = array_map(static fn (object $journal): int => (int) $journal->journal_id, $this->legs->partnersOf($own));

        $this->assertNotContains($third, $partners);
        $this->assertCount(1, $partners);
        $this->assertCount(1, $this->legs->partnersOf($again));
    }

    public function testPartnersOf_LeavesOutAJournalThatIsReversedAlready(): void
    {
        [$own, $other] = $this->pair(1);

        $this->accountingDb()->table('accounting_journals')->where('journal_id', $other)->update(['status' => 'reversed']);

        $this->assertSame([], $this->legs->partnersOf($own));
    }

    public function testPartnersOf_IsEmptyForAJournalWithNoRecord(): void
    {
        $this->assertSame([], $this->legs->partnersOf($this->journal('branches_receivable', true, 1, 98)));
    }

    public function testPartnersOf_ListsTheLowerBranchFirst(): void
    {
        $third = $this->createBranch('B3');
        $a     = $this->journal('branches_receivable', true, $this->branch, 51);
        $b     = $this->journal('branches_payable', false, 1, 52);
        $c     = $this->journal('branches_payable', false, $third, 53);

        foreach ([$a, $b, $c] as $journal) {
            $this->legs->record($journal, $this->accountId($journal === $a ? 'branches_receivable' : 'branches_payable'), $journal === $a ? InterBranchLegs::RECEIVABLE : InterBranchLegs::PAYABLE, 1, 'leg_document', 7, 1);
        }

        $this->assertSame([1, $third], array_map(static fn (object $journal): int => (int) $journal->branch_id, $this->legs->partnersOf($a)));
    }
}
