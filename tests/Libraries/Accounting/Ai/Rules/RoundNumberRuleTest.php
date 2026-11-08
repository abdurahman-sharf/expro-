<?php

namespace Tests\Libraries\Accounting\Ai\Rules;

use App\Libraries\Accounting\Ai\Finding;
use App\Libraries\Accounting\Ai\Rules\RoundNumberRule;
use App\Libraries\Accounting\JournalDraft;
use App\Libraries\Accounting\JournalLineDraft;
use App\Libraries\Accounting\JournalService;
use App\Libraries\Accounting\PostingService;
use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\DatabaseTestTrait;
use Config\Database;
use Tests\Support\AiFixtureTrait;

/**
 * A manual journal at or above the threshold that is an exact multiple of the round unit.
 *
 * @internal
 */
final class RoundNumberRuleTest extends CIUnitTestCase
{
    use DatabaseTestTrait;
    use AiFixtureTrait;

    protected $migrate     = true;
    protected $migrateOnce = true;
    protected $refresh     = false;
    protected $namespace   = null;

    private RoundNumberRule $rule;

    public static function setUpBeforeClass(): void
    {
        $seeder = Database::seeder('tests');
        $seeder->call('TestDatabaseBootstrapSeeder');
    }

    protected function setUp(): void
    {
        parent::setUp();

        $this->startAgent();
        $this->rule = new RoundNumberRule();
    }

    protected function tearDown(): void
    {
        $this->stopAgent();

        parent::tearDown();
    }

    private function journalOf(string $amount, ?string $postedAt = '2026-10-10 10:00:00', ?int $branchId = null): int
    {
        return $this->manualJournal('2026-10-10', $this->rentExpense(), $this->cashAccount(), $amount, 'Round', $postedAt, $branchId);
    }

    /**
     * @param list<Finding> $findings
     *
     * @return list<string>
     */
    private function keys(array $findings): array
    {
        return array_map(static fn (Finding $finding): string => $finding->key, $findings);
    }

    public function testDetect_FlagsAManualJournalOfAMultipleOfTheUnitAboveTheThreshold(): void
    {
        $round = $this->journalOf('25000.00');

        $findings = $this->rule->detect($this->ruleContext());

        $this->assertCount(1, $findings);
        $this->assertSame('AI-ROUND', $findings[0]->rule);
        $this->assertSame(Finding::LOW, $findings[0]->severity);
        $this->assertSame((string) $round, $findings[0]->key);
        $this->assertSame($round, $findings[0]->subjectId);
        $this->assertSame(1, $findings[0]->branchId);
        $this->assertSame('2026-10-10', $findings[0]->occurredOn);
        $this->assertSame('25000.0000', $findings[0]->templateArgs['amount']);
        $this->assertSame('1000.0000', $findings[0]->templateArgs['unit']);
        $this->assertSame('25000.0000', $findings[0]->evidence[0]['amount']);
        $this->assertSame($round, $findings[0]->evidence[0]['journal_id']);
    }

    public function testDetect_FlagsTheThresholdItselfAndNotTheAmountBelowIt(): void
    {
        $at    = $this->journalOf('10000.00');
        $below = $this->journalOf('9000.00');

        $this->assertSame([(string) $at], $this->keys($this->rule->detect($this->ruleContext())));
        $this->assertNotSame($at, $below);
    }

    public function testDetect_FlagsNothingForAnAmountThatIsNotAMultipleOfTheUnit(): void
    {
        $this->journalOf('10500.00');
        $this->journalOf('12000.50');
        $this->journalOf('20999.99');

        $this->assertSame([], $this->rule->detect($this->ruleContext()));
    }

    public function testDetect_UsesTheUnitAndTheThresholdOfTheSettings(): void
    {
        $half = $this->journalOf('10500.00');
        $this->journalOf('1500.00');

        $this->setThreshold('round_unit', '500');

        $this->assertSame([(string) $half], $this->keys($this->rule->detect($this->ruleContext())));

        $this->setThreshold('round_min', '1000');

        $this->assertCount(2, $this->rule->detect($this->ruleContext()));
    }

    public function testDetect_ReadsOnlyManualJournalsThatAreInTheBooks(): void
    {
        (new PostingService())->post(new JournalDraft(
            date: '2026-10-10',
            lines: [new JournalLineDraft($this->rentExpense(), debit: '30000.00'), new JournalLineDraft($this->cashAccount(), credit: '30000.00')],
            sourceType: 'expense',
            sourceId: 4,
            branchId: 1,
        ));
        $this->accountingDb()->table('accounting_journals')->where('source_type', 'expense')->update(['posted_at' => '2026-10-10 10:00:00']);

        $reversed = $this->journalOf('40000.00');
        (new JournalService())->reverse($reversed, '2026-10-11', 'Wrong', null);
        $this->accountingDb()->table('accounting_journals')->where('source_type', 'reversal')->update(['posted_at' => '2026-10-11 10:00:00']);

        $this->assertSame([], $this->rule->detect($this->ruleContext()));
    }

    public function testDetect_LooksOnlyAtJournalsPostedSinceTheStartOfTheScan(): void
    {
        $this->journalOf('25000.00', '2026-10-10 10:00:00');

        $this->assertCount(1, $this->rule->detect($this->ruleContext(null, '2026-11-08', '2026-10-10')));
        $this->assertSame([], $this->rule->detect($this->ruleContext(null, '2026-11-08', '2026-10-11')));
    }

    public function testDetect_KeepsToTheBranchesGiven(): void
    {
        $branch = $this->createBranch();
        $this->journalOf('25000.00', '2026-10-10 10:00:00', $branch);

        $this->assertCount(1, $this->rule->detect($this->ruleContext([$branch])));
        $this->assertSame([], $this->rule->detect($this->ruleContext([1])));
        $this->assertSame([], $this->rule->detect($this->ruleContext([])));
        $this->assertSame($branch, $this->rule->detect($this->ruleContext())[0]->branchId);
    }

    public function testDetect_GivesTheSameFindingsEveryTime(): void
    {
        $this->journalOf('25000.00');
        $this->journalOf('11000.00');

        $this->assertEquals($this->rule->detect($this->ruleContext()), $this->rule->detect($this->ruleContext()));
        $this->assertSame('AI-ROUND', $this->rule->code());
    }
}
