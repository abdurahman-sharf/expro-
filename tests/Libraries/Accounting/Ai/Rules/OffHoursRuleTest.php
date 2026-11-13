<?php

namespace Tests\Libraries\Accounting\Ai\Rules;

use App\Libraries\Accounting\Ai\Finding;
use App\Libraries\Accounting\Ai\Rules\OffHoursRule;
use App\Libraries\Accounting\JournalDraft;
use App\Libraries\Accounting\JournalLineDraft;
use App\Libraries\Accounting\PostingService;
use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\DatabaseTestTrait;
use Config\Database;
use Tests\Support\AiFixtureTrait;

/**
 * A manual journal posted on a weekend or at night in a branch whose manual journals are nearly all posted on
 * working days in the daytime: positive cases, and the negative ones where the branch has no usual pattern,
 * posts at those hours often, or the journal is not a manual one.
 *
 * @internal
 */
final class OffHoursRuleTest extends CIUnitTestCase
{
    use DatabaseTestTrait;
    use AiFixtureTrait;

    protected $migrate     = true;
    protected $migrateOnce = true;
    protected $refresh     = false;
    protected $namespace   = null;

    private OffHoursRule $rule;

    public static function setUpBeforeClass(): void
    {
        $seeder = Database::seeder('tests');
        $seeder->call('TestDatabaseBootstrapSeeder');
    }

    protected function setUp(): void
    {
        parent::setUp();

        $this->startAgent();
        $this->rule = new OffHoursRule();
    }

    protected function tearDown(): void
    {
        $this->stopAgent();

        parent::tearDown();
    }

    private function workingDays(int $count, ?int $branchId = null): void
    {
        for ($index = 0; $index < $count; $index++) {
            $day = 5 + ($index % 4);

            $this->manualJournal('2026-10-0' . $day, $this->rentExpense(), $this->cashAccount(), '10.00', 'Daytime ' . $index, '2026-10-0' . $day . ' 10:00:00', $branchId);
        }
    }

    private function journalPostedAt(string $postedAt, ?int $branchId = null): int
    {
        return $this->manualJournal('2026-10-12', $this->rentExpense(), $this->cashAccount(), '10.00', 'Odd hour', $postedAt, $branchId);
    }

    public function testDetect_FlagsAJournalPostedOnAFridayInABranchThatPostsOnWorkingDays(): void
    {
        $this->workingDays(25);
        $odd = $this->journalPostedAt('2026-10-09 11:00:00');

        $findings = $this->rule->detect($this->ruleContext());

        $this->assertCount(1, $findings);

        $finding = $findings[0];

        $this->assertSame('AI-OFFHOURS', $finding->rule);
        $this->assertSame(Finding::LOW, $finding->severity);
        $this->assertSame((string) $odd, $finding->key);
        $this->assertSame(1, $finding->branchId);
        $this->assertSame('journal', $finding->subjectType);
        $this->assertSame($odd, $finding->subjectId);
        $this->assertSame('2026-10-12', $finding->occurredOn);
        $this->assertSame('2026-10-09 11:00:00', $finding->templateArgs['posted_at']);
        $this->assertSame(25, $finding->templateArgs['history']);
        $this->assertSame('0.0', $finding->templateArgs['share']);
        $this->assertSame('journal', $finding->evidence[0]['type']);
        $this->assertSame($odd, $finding->evidence[0]['journal_id']);
        $this->assertSame('pattern', $finding->evidence[1]['type']);
        $this->assertSame(25, $finding->evidence[1]['journals']);
        $this->assertSame(0, $finding->evidence[1]['off_hours']);
    }

    public function testDetect_FlagsAJournalPostedAtNightAndOneInTheEarlyMorning(): void
    {
        $this->workingDays(25);
        $late  = $this->journalPostedAt('2026-10-06 22:00:00');
        $early = $this->journalPostedAt('2026-10-07 05:59:59');
        $this->journalPostedAt('2026-10-07 06:00:00');
        $this->journalPostedAt('2026-10-07 21:59:59');

        $this->assertSame([(string) $late, (string) $early], array_map(static fn (Finding $finding): string => $finding->key, $this->rule->detect($this->ruleContext())));
    }

    public function testDetect_FlagsNothingWhenTheBranchHasTooFewJournalsToHaveAPattern(): void
    {
        $this->workingDays(19);
        $this->journalPostedAt('2026-10-09 11:00:00');

        $this->assertSame([], $this->rule->detect($this->ruleContext()));

        $this->workingDays(1);

        $this->assertCount(1, $this->rule->detect($this->ruleContext()));
    }

    public function testDetect_FlagsNothingWhenTheBranchOftenPostsOutsideWorkingHours(): void
    {
        $this->workingDays(25);

        for ($index = 0; $index < 5; $index++) {
            $this->journalPostedAt('2026-10-09 1' . $index . ':00:00');
        }

        $this->assertSame([], $this->rule->detect($this->ruleContext()));
    }

    public function testDetect_FlagsWhenTheShareOfTheOthersIsBelowTheThresholdAndNotWhenItIsEqual(): void
    {
        $this->workingDays(18);
        $this->journalPostedAt('2026-10-09 11:00:00');
        $this->journalPostedAt('2026-10-10 11:00:00');
        $this->journalPostedAt('2026-10-09 12:00:00');

        $findings = $this->rule->detect($this->ruleContext());

        $this->assertSame([], array_map(static fn (Finding $finding): string => $finding->key, $findings));

        $this->setThreshold('offhours_share', '11');

        $this->assertCount(3, $this->rule->detect($this->ruleContext()));
    }

    public function testDetect_UsesTheWeekendAndTheNightOfTheSettings(): void
    {
        $this->workingDays(25);
        $friday = $this->journalPostedAt('2026-10-09 11:00:00');
        $sunday = $this->journalPostedAt('2026-10-11 11:00:00');

        $this->assertSame([(string) $friday], array_map(static fn (Finding $finding): string => $finding->key, $this->rule->detect($this->ruleContext())));

        $this->setThreshold('weekend_days', '0');

        $this->assertSame([(string) $sunday], array_map(static fn (Finding $finding): string => $finding->key, $this->rule->detect($this->ruleContext())));

        $this->setThreshold('weekend_days', '');
        $this->setThreshold('night_start', '11');
        $this->setThreshold('night_end', '12');

        $this->assertSame([(string) $friday, (string) $sunday], array_map(static fn (Finding $finding): string => $finding->key, $this->rule->detect($this->ruleContext())));
    }

    public function testDetect_ReadsANightThatDoesNotCrossMidnight(): void
    {
        $this->workingDays(25);
        $inside = $this->journalPostedAt('2026-10-06 02:00:00');
        $this->journalPostedAt('2026-10-06 04:00:00');

        $this->setThreshold('weekend_days', '');
        $this->setThreshold('night_start', '1');
        $this->setThreshold('night_end', '3');

        $this->assertSame([(string) $inside], array_map(static fn (Finding $finding): string => $finding->key, $this->rule->detect($this->ruleContext())));
    }

    public function testDetect_ReadsOnlyManualJournals(): void
    {
        $this->workingDays(25);

        (new PostingService())->post(new JournalDraft(
            date: '2026-10-12',
            lines: [new JournalLineDraft($this->rentExpense(), debit: '10.00'), new JournalLineDraft($this->cashAccount(), credit: '10.00')],
            sourceType: 'expense',
            sourceId: 9,
            branchId: 1,
        ));
        $this->accountingDb()->table('accounting_journals')->where('source_type', 'expense')->update(['posted_at' => '2026-10-09 23:00:00']);

        $this->assertSame([], $this->rule->detect($this->ruleContext()));
    }

    public function testDetect_CountsEachBranchOnItsOwn(): void
    {
        $branch = $this->createBranch();
        $this->workingDays(25);
        $this->journalPostedAt('2026-10-09 11:00:00', $branch);

        $this->assertSame([], $this->rule->detect($this->ruleContext()));
    }

    public function testDetect_LooksOnlyAtJournalsPostedSinceTheStartOfTheScan(): void
    {
        $this->workingDays(25);
        $this->journalPostedAt('2026-10-09 11:00:00');

        $this->assertCount(1, $this->rule->detect($this->ruleContext(null, '2026-11-08', '2026-10-09')));
        $this->assertSame([], $this->rule->detect($this->ruleContext(null, '2026-11-08', '2026-10-10')));
    }

    public function testDetect_ReadsOnlyTheLastYearOfJournals(): void
    {
        $this->workingDays(25);
        $this->journalPostedAt('2026-10-09 11:00:00');

        $this->assertCount(1, $this->rule->detect($this->ruleContext(null, '2027-10-01', '2026-08-01')));
        $this->assertSame([], $this->rule->detect($this->ruleContext(null, '2027-10-20', '2026-08-01')));
    }

    public function testDetect_KeepsToTheBranchesGiven(): void
    {
        $this->workingDays(25);
        $this->journalPostedAt('2026-10-09 11:00:00');

        $this->assertCount(1, $this->rule->detect($this->ruleContext([1])));
        $this->assertSame([], $this->rule->detect($this->ruleContext([2])));
        $this->assertSame([], $this->rule->detect($this->ruleContext([])));
    }

    public function testDetect_IgnoresAJournalThatWasReversed(): void
    {
        $this->workingDays(25);
        $odd = $this->journalPostedAt('2026-10-09 11:00:00');
        $this->accountingDb()->table('accounting_journals')->where('journal_id', $odd)->update(['status' => 'reversed']);

        $this->assertSame([], $this->rule->detect($this->ruleContext()));
    }

    public function testDetect_GivesTheSameFindingsEveryTime(): void
    {
        $this->workingDays(25);
        $this->journalPostedAt('2026-10-09 11:00:00');

        $this->assertEquals($this->rule->detect($this->ruleContext()), $this->rule->detect($this->ruleContext()));
        $this->assertSame('AI-OFFHOURS', $this->rule->code());
    }
}
