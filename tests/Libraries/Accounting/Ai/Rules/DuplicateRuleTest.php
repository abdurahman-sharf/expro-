<?php

namespace Tests\Libraries\Accounting\Ai\Rules;

use App\Libraries\Accounting\Ai\Finding;
use App\Libraries\Accounting\Ai\Rules\DuplicateRule;
use App\Libraries\Accounting\JournalDraft;
use App\Libraries\Accounting\JournalLineDraft;
use App\Libraries\Accounting\JournalService;
use App\Libraries\Accounting\PostingService;
use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\DatabaseTestTrait;
use Config\Database;
use Tests\Support\AiFixtureTrait;

/**
 * The same amounts on the same accounts, keyed twice within a few days: a manual journal, an expense or a revenue,
 * and never a journal that a sale made.
 *
 * @internal
 */
final class DuplicateRuleTest extends CIUnitTestCase
{
    use DatabaseTestTrait;
    use AiFixtureTrait;

    protected $migrate     = true;
    protected $migrateOnce = true;
    protected $refresh     = false;
    protected $namespace   = null;

    private DuplicateRule $rule;

    public static function setUpBeforeClass(): void
    {
        $seeder = Database::seeder('tests');
        $seeder->call('TestDatabaseBootstrapSeeder');
    }

    protected function setUp(): void
    {
        parent::setUp();

        $this->startAgent();
        $this->rule = new DuplicateRule();
    }

    protected function tearDown(): void
    {
        $this->stopAgent();

        parent::tearDown();
    }

    private function keyed(string $date, string $amount = '500.00', ?int $debit = null, ?int $branchId = null): int
    {
        return $this->manualJournal($date, $debit ?? $this->rentExpense(), $this->cashAccount(), $amount, 'Keyed', null, $branchId);
    }

    private function automatic(string $source, int $id, string $date, string $amount = '500.00'): int
    {
        return (new PostingService())->post(new JournalDraft(
            date: $date,
            lines: [new JournalLineDraft($this->rentExpense(), debit: $amount), new JournalLineDraft($this->cashAccount(), credit: $amount)],
            sourceType: $source,
            sourceId: $id,
            branchId: 1,
        ))->journalId;
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

    public function testDetect_FlagsTheSecondOfTwoManualJournalsOfTheSameAmountsAndAccountsWithinTheDays(): void
    {
        $first  = $this->keyed('2026-10-10');
        $second = $this->keyed('2026-10-12');

        $findings = $this->rule->detect($this->ruleContext());

        $this->assertCount(1, $findings);

        $finding = $findings[0];

        $this->assertSame('AI-DUPLICATE', $finding->rule);
        $this->assertSame(Finding::MEDIUM, $finding->severity);
        $this->assertSame($first . '|' . $second, $finding->key);
        $this->assertSame($second, $finding->subjectId);
        $this->assertSame('2026-10-12', $finding->occurredOn);
        $this->assertSame(1, $finding->branchId);
        $this->assertSame(2, $finding->templateArgs['days']);
        $this->assertSame('500.0000', $finding->templateArgs['amount']);
        $this->assertSame([$first, $second], array_column($finding->evidence, 'journal_id'));
        $this->assertSame(2, $finding->evidence[1]['days_after']);
    }

    public function testDetect_FlagsTheThirdDayAndNotTheFourth(): void
    {
        $this->keyed('2026-10-10');
        $this->keyed('2026-10-13');
        $this->keyed('2026-10-20');
        $this->keyed('2026-10-24');

        $this->assertCount(1, $this->rule->detect($this->ruleContext()));
    }

    public function testDetect_UsesTheNumberOfDaysOfTheSetting(): void
    {
        $this->keyed('2026-10-10');
        $this->keyed('2026-10-14');

        $this->assertSame([], $this->rule->detect($this->ruleContext()));

        $this->setThreshold('duplicate_days', '4');

        $this->assertCount(1, $this->rule->detect($this->ruleContext()));

        $this->setThreshold('duplicate_days', '0');

        $this->assertSame([], $this->rule->detect($this->ruleContext()));
    }

    public function testDetect_FlagsTwoJournalsOfTheSameDayWhenTheDaysAreZero(): void
    {
        $this->keyed('2026-10-10');
        $this->keyed('2026-10-10');

        $this->setThreshold('duplicate_days', '0');

        $this->assertCount(1, $this->rule->detect($this->ruleContext()));
    }

    public function testDetect_FlagsNothingForADifferentAmountADifferentAccountOrADifferentBranch(): void
    {
        $branch = $this->createBranch();

        $this->keyed('2026-10-10', '500.00');
        $this->keyed('2026-10-11', '500.01');
        $this->keyed('2026-10-11', '500.00', $this->electricityExpense());
        $this->keyed('2026-10-11', '500.00', null, $branch);

        $this->assertSame([], $this->rule->detect($this->ruleContext()));
    }

    public function testDetect_FlagsAChainOfThreeAsTwoPairs(): void
    {
        $one   = $this->keyed('2026-10-10');
        $two   = $this->keyed('2026-10-11');
        $three = $this->keyed('2026-10-12');

        $this->assertSame([$one . '|' . $two, $two . '|' . $three], $this->keys($this->rule->detect($this->ruleContext())));
    }

    public function testDetect_ReadsExpensesAndRevenuesToo(): void
    {
        $this->automatic('expense', 1, '2026-10-10');
        $this->automatic('expense', 2, '2026-10-11');

        $this->assertCount(1, $this->rule->detect($this->ruleContext()));
    }

    public function testDetect_LeavesOutTheJournalsOfSales(): void
    {
        $this->automatic('sale', 1, '2026-10-10');
        $this->automatic('sale', 2, '2026-10-11');

        $this->assertSame([], $this->rule->detect($this->ruleContext()));
    }

    public function testDetect_CountsAManualJournalAndAnExpenseOfTheSameShapeAsTheSameThing(): void
    {
        $this->keyed('2026-10-10');
        $this->automatic('expense', 1, '2026-10-11');

        $this->assertCount(1, $this->rule->detect($this->ruleContext()));
    }

    public function testDetect_IgnoresAJournalThatWasReversed(): void
    {
        $first = $this->keyed('2026-10-10');
        $this->keyed('2026-10-11');
        (new JournalService())->reverse($first, '2026-10-12', 'Entered twice', null);

        $this->assertSame([], $this->rule->detect($this->ruleContext()));
    }

    public function testDetect_LooksOnlyAtThePairsThatEndSinceTheStartOfTheScan(): void
    {
        $this->keyed('2026-10-10');
        $this->keyed('2026-10-11');

        $this->assertCount(1, $this->rule->detect($this->ruleContext(null, '2026-11-08', '2026-10-11')));
        $this->assertSame([], $this->rule->detect($this->ruleContext(null, '2026-11-08', '2026-10-12')));
    }

    public function testDetect_KeepsToTheBranchesGiven(): void
    {
        $branch = $this->createBranch();
        $this->keyed('2026-10-10', '500.00', null, $branch);
        $this->keyed('2026-10-11', '500.00', null, $branch);

        $this->assertCount(1, $this->rule->detect($this->ruleContext([$branch])));
        $this->assertSame([], $this->rule->detect($this->ruleContext([1])));
        $this->assertSame([], $this->rule->detect($this->ruleContext([])));
    }

    public function testDetect_GivesTheSameFindingsEveryTime(): void
    {
        $this->keyed('2026-10-10');
        $this->keyed('2026-10-11');

        $this->assertEquals($this->rule->detect($this->ruleContext()), $this->rule->detect($this->ruleContext()));
        $this->assertSame('AI-DUPLICATE', $this->rule->code());
    }
}
