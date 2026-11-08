<?php

namespace Tests\Libraries\Accounting\Ai\Rules;

use App\Libraries\Accounting\Ai\Finding;
use App\Libraries\Accounting\Ai\Rules\BackdatedRule;
use App\Libraries\Accounting\JournalDraft;
use App\Libraries\Accounting\JournalLineDraft;
use App\Libraries\Accounting\PostingService;
use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\DatabaseTestTrait;
use Config\Database;
use Tests\Support\AiFixtureTrait;

/**
 * A journal somebody keyed, dated well before the day it was posted, posted in the last days before a period that
 * holds its date was closed.
 *
 * @internal
 */
final class BackdatedRuleTest extends CIUnitTestCase
{
    use DatabaseTestTrait;
    use AiFixtureTrait;

    protected $migrate     = true;
    protected $migrateOnce = true;
    protected $refresh     = false;
    protected $namespace   = null;
    private BackdatedRule $rule;

    public static function setUpBeforeClass(): void
    {
        $seeder = Database::seeder('tests');
        $seeder->call('TestDatabaseBootstrapSeeder');
    }

    protected function setUp(): void
    {
        parent::setUp();

        $this->startAgent();
        $this->rule = new BackdatedRule();
    }

    protected function tearDown(): void
    {
        $this->stopAgent();

        parent::tearDown();
    }

    private function backdated(string $date, string $postedAt, ?int $branchId = null): int
    {
        return $this->manualJournal($date, $this->rentExpense(), $this->cashAccount(), '10.00', 'Backdated', $postedAt, $branchId);
    }

    public function testDetect_FlagsAJournalDatedFiftyDaysBackAndPostedTwoDaysBeforeTheCloseOfItsPeriod(): void
    {
        $journal = $this->backdated('2026-09-10', '2026-10-30 10:00:00');
        $closing = $this->closedAt('2026-09-30', '2026-11-01 09:00:00');

        $findings = $this->rule->detect($this->ruleContext());

        $this->assertCount(1, $findings);

        $finding = $findings[0];

        $this->assertSame('AI-BACKDATED', $finding->rule);
        $this->assertSame(Finding::HIGH, $finding->severity);
        $this->assertSame($journal . '|' . $closing, $finding->key);
        $this->assertSame($journal, $finding->subjectId);
        $this->assertSame(1, $finding->branchId);
        $this->assertSame('2026-09-10', $finding->occurredOn);
        $this->assertSame(50, $finding->templateArgs['days_back']);
        $this->assertSame('2026-09-30', $finding->templateArgs['lock_until']);
        $this->assertSame('journal', $finding->evidence[0]['type']);
        $this->assertSame(50, $finding->evidence[0]['days_back']);
        $this->assertSame('closing', $finding->evidence[1]['type']);
        $this->assertSame($closing, $finding->evidence[1]['closed_period_id']);
        $this->assertSame(2, $finding->evidence[1]['days_before_lock']);
    }

    public function testDetect_FlagsOnTheLastDayOfTheWindowAndNotBeforeIt(): void
    {
        $inside  = $this->backdated('2026-09-10', '2026-10-29 09:00:00');
        $outside = $this->backdated('2026-09-11', '2026-10-28 08:59:59');
        $this->closedAt('2026-09-30', '2026-11-01 09:00:00');

        $this->assertSame([(string) $inside . '|1'], array_map(static fn (Finding $finding): string => $finding->key, $this->rule->detect($this->ruleContext())));
        $this->assertNotSame($inside, $outside);
    }

    public function testDetect_FlagsOnTheLeastGapAndNotBelowIt(): void
    {
        $enough = $this->backdated('2026-10-23', '2026-10-30 10:00:00');
        $this->backdated('2026-10-24', '2026-10-30 10:00:00');
        $this->closedAt('2026-10-31', '2026-11-01 09:00:00');

        $this->assertSame([$enough . '|1'], array_map(static fn (Finding $finding): string => $finding->key, $this->rule->detect($this->ruleContext())));
    }

    public function testDetect_FlagsNothingForAJournalPostedAfterTheCloseOrDatedAfterIt(): void
    {
        $this->backdated('2026-09-10', '2026-11-02 10:00:00');
        $this->backdated('2026-10-05', '2026-10-30 10:00:00');
        $this->closedAt('2026-09-30', '2026-11-01 09:00:00');

        $this->assertSame([], $this->rule->detect($this->ruleContext()));
    }

    public function testDetect_FlagsNothingWithoutAClosing(): void
    {
        $this->backdated('2026-09-10', '2026-10-30 10:00:00');

        $this->assertSame([], $this->rule->detect($this->ruleContext()));
    }

    public function testDetect_UsesTheWindowAndTheGapOfTheSettings(): void
    {
        $this->backdated('2026-09-10', '2026-10-25 10:00:00');
        $this->closedAt('2026-09-30', '2026-11-01 09:00:00');

        $this->assertSame([], $this->rule->detect($this->ruleContext()));

        $this->setThreshold('lock_window_days', '7');

        $this->assertCount(1, $this->rule->detect($this->ruleContext()));

        $this->setThreshold('backdate_days', '60');

        $this->assertSame([], $this->rule->detect($this->ruleContext()));
    }

    public function testDetect_ReadsTheClosingOfTheBranchOfTheJournalAndNotOfAnother(): void
    {
        $branch = $this->createBranch();
        $this->backdated('2026-09-10', '2026-10-30 10:00:00');
        $this->closedAt('2026-09-30', '2026-11-01 09:00:00', $branch);

        $this->assertSame([], $this->rule->detect($this->ruleContext()));

        $this->closedAt('2026-09-30', '2026-11-01 09:00:00', 1);

        $this->assertCount(1, $this->rule->detect($this->ruleContext()));
    }

    public function testDetect_ReadsOnlyTheClosingsOfTheCompanyOfTheJournal(): void
    {
        $this->backdated('2026-09-10', '2026-10-30 10:00:00');

        $db = $this->accountingDb();
        $db->table('companies')->insert(['name' => 'Another company']);
        $company = (int) $db->insertID();

        $db->table('accounting_closed_periods')->insert(['company_id' => $company, 'date_from' => '2020-01-01', 'date_to' => '2026-09-30', 'active' => 1, 'created_at' => '2026-11-01 09:00:00']);

        try {
            $this->assertSame([], $this->rule->detect($this->ruleContext()));
        } finally {
            $db->table('accounting_closed_periods')->where('company_id', $company)->delete();
            $db->table('companies')->where('company_id', $company)->delete();
        }
    }

    public function testDetect_ReadsExpensesButNotTheJournalsOfSales(): void
    {
        $posting = new PostingService();

        foreach (['expense' => 1, 'sale' => 2] as $source => $id) {
            $posting->post(new JournalDraft(
                date: '2026-09-10',
                lines: [new JournalLineDraft($this->rentExpense(), debit: '10.00'), new JournalLineDraft($this->cashAccount(), credit: '10.00')],
                sourceType: $source,
                sourceId: $id,
                branchId: 1,
            ));
        }

        $this->accountingDb()->table('accounting_journals')->update(['posted_at' => '2026-10-30 10:00:00']);
        $this->closedAt('2026-09-30', '2026-11-01 09:00:00');

        $findings = $this->rule->detect($this->ruleContext());

        $this->assertCount(1, $findings);
        $this->assertSame('expense', $this->accountingDb()->table('accounting_journals')->where('journal_id', $findings[0]->subjectId)->get()->getRow()->source_type);
    }

    public function testDetect_GivesAFindingForEachClosingThatHoldsTheJournal(): void
    {
        $this->backdated('2026-09-10', '2026-10-30 10:00:00');
        $this->closedAt('2026-09-30', '2026-11-01 09:00:00');
        $this->closedAt('2026-10-31', '2026-11-01 10:00:00');

        $this->assertCount(2, $this->rule->detect($this->ruleContext()));
    }

    public function testDetect_LooksOnlyAtClosingsMadeSinceTheStartOfTheScan(): void
    {
        $this->backdated('2026-09-10', '2026-10-30 10:00:00');
        $this->closedAt('2026-09-30', '2026-11-01 09:00:00');

        $this->assertCount(1, $this->rule->detect($this->ruleContext(null, '2026-11-08', '2026-11-01')));
        $this->assertSame([], $this->rule->detect($this->ruleContext(null, '2026-11-08', '2026-11-02')));
    }

    public function testDetect_KeepsToTheBranchesGiven(): void
    {
        $branch = $this->createBranch();
        $this->backdated('2026-09-10', '2026-10-30 10:00:00', $branch);
        $this->closedAt('2026-09-30', '2026-11-01 09:00:00');

        $this->assertCount(1, $this->rule->detect($this->ruleContext([$branch])));
        $this->assertSame([], $this->rule->detect($this->ruleContext([1])));
        $this->assertSame([], $this->rule->detect($this->ruleContext([])));
    }

    public function testDetect_GivesTheSameFindingsEveryTime(): void
    {
        $this->backdated('2026-09-10', '2026-10-30 10:00:00');
        $this->closedAt('2026-09-30', '2026-11-01 09:00:00');

        $this->assertEquals($this->rule->detect($this->ruleContext()), $this->rule->detect($this->ruleContext()));
        $this->assertSame('AI-BACKDATED', $this->rule->code());
    }
}
