<?php

namespace Tests\Libraries\Accounting\Ai\Rules;

use App\Libraries\Accounting\Ai\Finding;
use App\Libraries\Accounting\Ai\Rules\ReversalChainRule;
use App\Libraries\Accounting\JournalDraft;
use App\Libraries\Accounting\JournalLineDraft;
use App\Libraries\Accounting\JournalService;
use App\Libraries\Accounting\PostingService;
use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\DatabaseTestTrait;
use Config\Database;
use Tests\Support\AiFixtureTrait;

/**
 * A document whose journal was reversed at least as many times as the threshold.
 *
 * @internal
 */
final class ReversalChainRuleTest extends CIUnitTestCase
{
    use DatabaseTestTrait;
    use AiFixtureTrait;

    protected $migrate     = true;
    protected $migrateOnce = true;
    protected $refresh     = false;
    protected $namespace   = null;
    private ReversalChainRule $rule;

    public static function setUpBeforeClass(): void
    {
        $seeder = Database::seeder('tests');
        $seeder->call('TestDatabaseBootstrapSeeder');
    }

    protected function setUp(): void
    {
        parent::setUp();

        $this->startAgent();
        $this->rule = new ReversalChainRule();
    }

    protected function tearDown(): void
    {
        $this->stopAgent();

        parent::tearDown();
    }

    /**
     * Posts a document and takes its journal back as many times as asked, each time under a new revision.
     *
     * @return list<int> The journals that were reversed.
     */
    private function chain(string $source, int $document, int $reversals, ?int $branchId = 1, string $postedAt = '2026-10-20 10:00:00'): array
    {
        $posting  = new PostingService();
        $journals = [];

        for ($revision = 1; $revision <= $reversals; $revision++) {
            $id = $posting->post(new JournalDraft(
                date: '2026-10-1' . $revision,
                lines: [new JournalLineDraft($this->rentExpense(), debit: '10.00'), new JournalLineDraft($this->cashAccount(), credit: '10.00')],
                sourceType: $source,
                sourceId: $document,
                revision: $revision,
                branchId: $branchId,
            ))->journalId;

            (new JournalService())->reverseAutomatic($id, '2026-10-2' . $revision, 'Changed', null);
            $journals[] = $id;
        }

        $this->accountingDb()->table('accounting_journals')->where('source_type', 'reversal')->update(['posted_at' => $postedAt]);

        return $journals;
    }

    public function testDetect_FlagsADocumentWhoseJournalWasReversedThreeTimes(): void
    {
        $journals = $this->chain('expense', 5, 3);

        $findings = $this->rule->detect($this->ruleContext());

        $this->assertCount(1, $findings);

        $finding = $findings[0];

        $this->assertSame('AI-REVERSAL-CHAIN', $finding->rule);
        $this->assertSame(Finding::MEDIUM, $finding->severity);
        $this->assertSame('expense|5|3', $finding->key);
        $this->assertSame('expense', $finding->subjectType);
        $this->assertSame(5, $finding->subjectId);
        $this->assertSame(1, $finding->branchId);
        $this->assertSame(3, $finding->templateArgs['reversals']);
        $this->assertSame(5, $finding->templateArgs['document']);
        $this->assertCount(6, $finding->evidence);
        $this->assertSame($journals[0], $finding->evidence[0]['journal_id']);
        $this->assertSame('reversed', $finding->evidence[0]['status']);
        $this->assertSame($journals[0], $finding->evidence[1]['reverses']);
    }

    public function testDetect_FlagsNothingBelowTheThresholdAndFlagsAtItWhenItIsLowered(): void
    {
        $this->chain('expense', 5, 2);

        $this->assertSame([], $this->rule->detect($this->ruleContext()));

        $this->setThreshold('reversal_chain', '2');

        $this->assertCount(1, $this->rule->detect($this->ruleContext()));
    }

    public function testDetect_DoesNotAddUpTheReversalsOfDifferentDocuments(): void
    {
        $this->chain('expense', 5, 2);
        $this->chain('expense', 6, 2);
        $this->chain('revenue', 5, 2);

        $this->assertSame([], $this->rule->detect($this->ruleContext()));
    }

    public function testDetect_GivesANewFindingWhenTheChainGrows(): void
    {
        $this->chain('expense', 5, 3);

        $this->assertSame(['expense|5|3'], array_map(static fn (Finding $finding): string => $finding->key, $this->rule->detect($this->ruleContext())));

        (new PostingService())->post(new JournalDraft(
            date: '2026-10-25',
            lines: [new JournalLineDraft($this->rentExpense(), debit: '10.00'), new JournalLineDraft($this->cashAccount(), credit: '10.00')],
            sourceType: 'expense',
            sourceId: 5,
            revision: 4,
            branchId: 1,
        ));
        $fourth = (int) $this->accountingDb()->table('accounting_journals')->where(['source_type' => 'expense', 'revision' => 4])->get()->getRow()->journal_id;
        (new JournalService())->reverseAutomatic($fourth, '2026-10-26', 'Changed', null);
        $this->accountingDb()->table('accounting_journals')->where('source_type', 'reversal')->update(['posted_at' => '2026-10-26 10:00:00']);

        $this->assertSame(['expense|5|4'], array_map(static fn (Finding $finding): string => $finding->key, $this->rule->detect($this->ruleContext())));
    }

    public function testDetect_LeavesOutTheManualJournalsThatHaveNoDocument(): void
    {
        for ($index = 0; $index < 3; $index++) {
            $id = $this->manualJournal('2026-10-1' . $index, $this->rentExpense(), $this->cashAccount(), '10.00', 'Manual ' . $index);
            (new JournalService())->reverse($id, '2026-10-2' . $index, 'Wrong', null);
        }

        $this->accountingDb()->table('accounting_journals')->where('source_type', 'reversal')->update(['posted_at' => '2026-10-20 10:00:00']);

        $this->assertSame([], $this->rule->detect($this->ruleContext()));
    }

    public function testDetect_ShowsTheChainAsEvidenceUpToTwentyJournals(): void
    {
        $posting = new PostingService();

        for ($revision = 1; $revision <= 11; $revision++) {
            $id = $posting->post(new JournalDraft(
                date: '2026-10-10',
                lines: [new JournalLineDraft($this->rentExpense(), debit: '10.00'), new JournalLineDraft($this->cashAccount(), credit: '10.00')],
                sourceType: 'expense',
                sourceId: 5,
                revision: $revision,
                branchId: 1,
            ))->journalId;

            (new JournalService())->reverseAutomatic($id, '2026-10-12', 'Changed', null);
        }

        $this->accountingDb()->table('accounting_journals')->where('source_type', 'reversal')->update(['posted_at' => '2026-10-20 10:00:00']);

        $findings = $this->rule->detect($this->ruleContext());

        $this->assertCount(1, $findings);
        $this->assertCount(20, $findings[0]->evidence);
        $this->assertSame(11, $findings[0]->templateArgs['reversals']);
    }

    public function testDetect_LeavesOutTheClosingOfAYear(): void
    {
        $this->chain('expense', 5, 3);
        $this->accountingDb()->table('accounting_journals')->where('source_type', 'expense')->update(['entry_type' => 'year_end']);

        $this->assertSame([], $this->rule->detect($this->ruleContext()));
    }

    public function testDetect_LooksOnlyAtChainsReversedSinceTheStartOfTheScan(): void
    {
        $this->chain('expense', 5, 3, 1, '2026-10-20 10:00:00');

        $this->assertCount(1, $this->rule->detect($this->ruleContext(null, '2026-11-08', '2026-10-20')));
        $this->assertSame([], $this->rule->detect($this->ruleContext(null, '2026-11-08', '2026-10-21')));
    }

    public function testDetect_KeepsToTheBranchesGiven(): void
    {
        $branch = $this->createBranch();
        $this->chain('expense', 5, 3, $branch);

        $this->assertCount(1, $this->rule->detect($this->ruleContext([$branch])));
        $this->assertSame([], $this->rule->detect($this->ruleContext([1])));
        $this->assertSame([], $this->rule->detect($this->ruleContext([])));
    }

    public function testDetect_GivesTheSameFindingsEveryTime(): void
    {
        $this->chain('expense', 5, 3);

        $this->assertEquals($this->rule->detect($this->ruleContext()), $this->rule->detect($this->ruleContext()));
        $this->assertSame('AI-REVERSAL-CHAIN', $this->rule->code());
    }
}
