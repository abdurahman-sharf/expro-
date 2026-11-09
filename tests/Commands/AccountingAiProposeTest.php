<?php

namespace Tests\Commands;

use App\Libraries\Accounting\Ai\ProposalService;
use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\DatabaseTestTrait;
use CodeIgniter\Test\StreamFilterTrait;
use Config\Database;
use Tests\Support\AiFixtureTrait;

/**
 * The command that runs the proposers from cron: the proposals it makes and posts none of, that a second run makes
 * nothing new, what it lists when a suggestion is refused, and that it is refused while the agent is off.
 *
 * @internal
 */
final class AccountingAiProposeTest extends CIUnitTestCase
{
    use DatabaseTestTrait;
    use StreamFilterTrait;
    use AiFixtureTrait;

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

        $this->startAgent();

        foreach (['2026-08-05', '2026-09-05', '2026-10-05'] as $date) {
            $this->manualJournal($date, $this->rentExpense(), $this->cashAccount(), '2000.00', 'Office rent');
        }
    }

    protected function tearDown(): void
    {
        $this->stopAgent();

        parent::tearDown();
    }

    /**
     * @param array<string, string|null> $params
     *
     * @return array{int, string}
     */
    private function propose(array $params = ['date' => '2026-11-25']): array
    {
        $this->resetStreamFilterBuffer();
        $exit = service('commands')->run('accounting:ai_propose', $params);

        return [$exit, $this->getStreamFilterBuffer()];
    }

    public function testRun_MakesTheProposalsPostsNothingAndSaysSoWithExitZero(): void
    {
        $before = $this->journalCount();

        [$exit, $output] = $this->propose();

        $this->assertSame(0, $exit);
        $this->assertStringContainsString('rules:recurring: 1 made, 0 standing already', $output);
        $this->assertStringContainsString('RESULT: 1 made, 0 standing already, 0 refused, 0 expired.', $output);
        $this->assertSame(1, $this->countRows('accounting_ai_proposals'));
        $this->assertSame($before, $this->journalCount());
        $this->assertSame(0, (int) $this->accountingDb()->table('accounting_journals')->where('source_type', ProposalService::SOURCE)->countAllResults());
    }

    public function testRun_TakesTheDayGivenAsTodayAndNotTheDayItRuns(): void
    {
        [$exit, $output] = $this->propose(['date' => '2026-12-25']);

        $this->assertSame(0, $exit);
        $this->assertStringContainsString('RESULT: 0 made, 0 standing already, 0 refused, 0 expired.', $output);
        $this->assertSame(0, $this->countRows('accounting_ai_proposals'));
    }

    public function testRun_MakesNothingNewWhenItIsRunAgain(): void
    {
        $this->propose();
        [$exit, $output] = $this->propose();

        $this->assertSame(0, $exit);
        $this->assertStringContainsString('RESULT: 0 made, 1 standing already, 0 refused, 0 expired.', $output);
        $this->assertSame(1, $this->countRows('accounting_ai_proposals'));
    }

    public function testRun_ExpiresTheProposalsPastTheirTimeAndMakesThemAgain(): void
    {
        $this->propose();
        $this->accountingDb()->table('accounting_ai_proposals')->update(['expires_at' => '2020-01-01 00:00:00']);

        [, $output] = $this->propose();

        $this->assertStringContainsString('RESULT: 1 made, 0 standing already, 0 refused, 1 expired.', $output);
        $this->assertSame(['expired', 'proposed'], array_column($this->accountingDb()->table('accounting_ai_proposals')->orderBy('proposal_id')->get()->getResultArray(), 'status'));
    }

    public function testRun_ListsASuggestionTheChecksOfAManualJournalRefuseAndExitsWithOne(): void
    {
        $this->closeThrough('2026-11-30');

        [$exit, $output] = $this->propose();

        $this->assertSame(1, $exit);
        $this->assertStringContainsString('REFUSED', $output);
        $this->assertStringContainsString('1 refused', $output);
        $this->assertSame(0, $this->countRows('accounting_ai_proposals'));
    }

    public function testRun_IsRefusedWithExitOneAndTheReasonWhileTheAgentIsOff(): void
    {
        $this->setConfigValue('accounting_ai_enabled', '0');

        [$exit, $output] = $this->propose();

        $this->assertSame(1, $exit);
        $this->assertStringContainsString(lang('Accounting.error_ai_disabled'), $output);
        $this->assertSame(0, $this->countRows('accounting_ai_proposals'));
    }

    public function testRun_RefusesADateThatIsNotADayWithExitSeven(): void
    {
        foreach (['yesterday', '2026-13-01', '2026-02-30'] as $date) {
            [$exit, $output] = $this->propose(['date' => $date]);

            $this->assertSame(7, $exit);
            $this->assertStringContainsString('YYYY-MM-DD', $output);
        }

        $this->assertSame(0, $this->countRows('accounting_ai_proposals'));
    }
}
