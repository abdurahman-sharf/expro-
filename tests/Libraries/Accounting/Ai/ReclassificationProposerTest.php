<?php

namespace Tests\Libraries\Accounting\Ai;

use App\Libraries\Accounting\Ai\ProposalService;
use App\Libraries\Accounting\Ai\ReclassificationProposer;
use App\Libraries\Accounting\JournalDraft;
use App\Libraries\Accounting\JournalLineDraft;
use App\Libraries\Accounting\JournalService;
use App\Libraries\Accounting\PostingService;
use App\Libraries\Accounting\RoutingKey;
use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\DatabaseTestTrait;
use Config\Database;
use DateTimeImmutable;
use Tests\Support\AiFixtureTrait;

/**
 * The reclassification suggestion: a manual journal that put an amount on the default account for other expenses
 * or other revenues, with a text that earlier manual journals put on one account again and again.
 *
 * @internal
 */
final class ReclassificationProposerTest extends CIUnitTestCase
{
    use DatabaseTestTrait;
    use AiFixtureTrait;

    protected $migrate     = true;
    protected $migrateOnce = true;
    protected $refresh     = false;
    protected $namespace   = null;
    private ReclassificationProposer $proposer;
    private DateTimeImmutable $today;

    public static function setUpBeforeClass(): void
    {
        $seeder = Database::seeder('tests');
        $seeder->call('TestDatabaseBootstrapSeeder');
    }

    protected function setUp(): void
    {
        parent::setUp();

        $this->startAgent();
        $this->proposer = new ReclassificationProposer();
        $this->today    = new DateTimeImmutable('2026-10-25');
    }

    protected function tearDown(): void
    {
        $this->stopAgent();

        parent::tearDown();
    }

    private function maintenance(): int
    {
        return $this->accountIdByCode('5204');
    }

    private function history(string $text, int $account, int $times, string $startDate = '2026-09-01'): void
    {
        for ($index = 0; $index < $times; $index++) {
            $date = date('Y-m-d', strtotime($startDate . ' +' . $index . ' days'));

            $this->manualJournal($date, $account, $this->cashAccount(), '100.00', $text);
        }
    }

    public function testPropose_SuggestsMovingTheAmountToTheAccountTheSameTextWentToBefore(): void
    {
        $this->history('Gulf Cleaning', $this->maintenance(), 3);
        $subject = $this->manualJournal('2026-10-20', $this->otherExpenses(), $this->cashAccount(), '250.00', 'Gulf Cleaning');

        $drafts = $this->proposer->propose($this->today, null);

        $this->assertCount(1, $drafts);

        $draft = $drafts[0];

        $this->assertSame('reclassify', $draft->kind);
        $this->assertSame('rules:reclassify', $draft->proposer);
        $this->assertSame('2026-10-25', $draft->date);
        $this->assertSame(1, $draft->branchId);
        $this->assertSame([$subject], $draft->subjectJournals);
        $this->assertSame($subject . '|1', $draft->dedupeKey);
        $this->assertCount(2, $draft->lines);
        $this->assertSame($this->maintenance(), $draft->lines[0]->accountId);
        $this->assertSame('250.0000', $draft->lines[0]->debit);
        $this->assertSame($this->otherExpenses(), $draft->lines[1]->accountId);
        $this->assertSame('250.0000', $draft->lines[1]->credit);
        $this->assertSame(3, $draft->templateArgs['uses']);
        $this->assertSame(3, $draft->templateArgs['total']);
        $this->assertSame(100, $draft->templateArgs['share']);
        $this->assertStringContainsString('Gulf Cleaning', $draft->description);
    }

    public function testPropose_ShowsTheFrequencyTableAsEvidence(): void
    {
        $this->history('Gulf Cleaning', $this->maintenance(), 3);
        $this->history('Gulf Cleaning', $this->electricityExpense(), 1, '2026-09-10');
        $this->setThreshold('reclass_min_share', '70');
        $subject = $this->manualJournal('2026-10-20', $this->otherExpenses(), $this->cashAccount(), '250.00', 'Gulf Cleaning');

        $evidence = $this->proposer->propose($this->today, null)[0]->evidence;

        $this->assertSame('journal', $evidence[0]['type']);
        $this->assertSame($subject, $evidence[0]['journal_id']);
        $this->assertSame('history', $evidence[1]['type']);
        $this->assertStringStartsWith('5204', $evidence[1]['account']);
        $this->assertSame(3, $evidence[1]['uses']);
        $this->assertSame(75, $evidence[1]['share']);
        $this->assertStringStartsWith('5202', $evidence[2]['account']);
        $this->assertSame(1, $evidence[2]['uses']);
        $this->assertSame(25, $evidence[2]['share']);
        $this->assertCount(3, $evidence);
    }

    public function testPropose_TreatsTheSameWordsInOtherCaseAndSpacingAsOneText(): void
    {
        $this->history('GULF   cleaning', $this->maintenance(), 2);
        $this->manualJournal('2026-10-20', $this->otherExpenses(), $this->cashAccount(), '250.00', '  gulf cleaning ');

        $this->assertCount(1, $this->proposer->propose($this->today, null));
    }

    public function testPropose_ReadsTheTextOfTheLineBeforeTheTextOfTheJournal(): void
    {
        $this->history('Gulf Cleaning', $this->maintenance(), 2);
        $this->manualJournal('2026-10-20', $this->otherExpenses(), $this->cashAccount(), '250.00', 'Something else', null, null, 'Gulf Cleaning');

        $this->assertCount(1, $this->proposer->propose($this->today, null));
    }

    public function testPropose_SuggestsNothingForATextThatWentNowhereElse(): void
    {
        $this->manualJournal('2026-10-20', $this->otherExpenses(), $this->cashAccount(), '250.00', 'Never seen before');
        $this->history('Another text', $this->maintenance(), 3);

        $this->assertSame([], $this->proposer->propose($this->today, null));
    }

    public function testPropose_SuggestsNothingForAJournalWithoutText(): void
    {
        $this->history('', $this->maintenance(), 3);
        $this->manualJournal('2026-10-20', $this->otherExpenses(), $this->cashAccount(), '250.00', '');

        $this->assertSame([], $this->proposer->propose($this->today, null));
    }

    public function testPropose_NeedsTheMinimumNumberOfEarlierUses(): void
    {
        $this->history('Gulf Cleaning', $this->maintenance(), 1);
        $this->manualJournal('2026-10-20', $this->otherExpenses(), $this->cashAccount(), '250.00', 'Gulf Cleaning');

        $this->assertSame([], $this->proposer->propose($this->today, null));

        $this->setThreshold('reclass_min_count', '1');

        $this->assertCount(1, $this->proposer->propose($this->today, null));
    }

    public function testPropose_NeedsTheMinimumShareOfTheEarlierUsesOnOneAccount(): void
    {
        $this->history('Gulf Cleaning', $this->maintenance(), 3);
        $this->history('Gulf Cleaning', $this->electricityExpense(), 2, '2026-09-10');
        $this->manualJournal('2026-10-20', $this->otherExpenses(), $this->cashAccount(), '250.00', 'Gulf Cleaning');

        $this->assertSame([], $this->proposer->propose($this->today, null));

        $this->setThreshold('reclass_min_share', '60');

        $this->assertSame($this->maintenance(), $this->proposer->propose($this->today, null)[0]->lines[0]->accountId);
    }

    public function testPropose_CountsOnlyPostedJournalsThatWereNotReversed(): void
    {
        $this->history('Gulf Cleaning', $this->maintenance(), 3);
        $this->manualJournal('2026-10-20', $this->otherExpenses(), $this->cashAccount(), '250.00', 'Gulf Cleaning');

        $first = (int) $this->accountingDb()->table('accounting_journals')->orderBy('journal_id')->get()->getRow()->journal_id;
        (new JournalService())->reverse($first, '2026-10-02', 'Wrong', null);
        $this->setThreshold('reclass_min_count', '3');

        $this->assertSame([], $this->proposer->propose($this->today, null));
    }

    public function testPropose_MovesAnAmountOfTheRevenueSideTheOtherWay(): void
    {
        $otherRevenue = $this->accountIdByCode('4201');
        $capital      = $this->accountIdByCode('4202');

        for ($index = 0; $index < 2; $index++) {
            $this->manualJournal('2026-09-0' . ($index + 1), $this->cashAccount(), $capital, '100.00', 'Scrap sale');
        }

        $this->manualJournal('2026-10-20', $this->cashAccount(), $otherRevenue, '300.00', 'Scrap sale');

        $draft = $this->proposer->propose($this->today, null)[0];

        $this->assertSame($otherRevenue, $draft->lines[0]->accountId);
        $this->assertSame('300.0000', $draft->lines[0]->debit);
        $this->assertSame($capital, $draft->lines[1]->accountId);
        $this->assertSame('300.0000', $draft->lines[1]->credit);
    }

    public function testPropose_DoesNotCountAnAccountOfAnotherTypeOrASideThatIsNotTheSame(): void
    {
        $this->history('Gulf Cleaning', $this->cashAccount(), 3);

        for ($index = 0; $index < 3; $index++) {
            $this->manualJournal('2026-09-0' . ($index + 1), $this->cashAccount(), $this->maintenance(), '100.00', 'Gulf Cleaning');
        }

        $this->manualJournal('2026-10-20', $this->otherExpenses(), $this->cashAccount(), '250.00', 'Gulf Cleaning');

        $this->assertSame([], $this->proposer->propose($this->today, null));
    }

    public function testPropose_LooksOnlyAtHistoryThatCameBeforeTheSubject(): void
    {
        $this->manualJournal('2026-10-20', $this->otherExpenses(), $this->cashAccount(), '250.00', 'Gulf Cleaning');
        $this->history('Gulf Cleaning', $this->maintenance(), 3, '2026-10-21');

        $this->assertSame([], $this->proposer->propose($this->today, null));
    }

    public function testPropose_LooksOnlyAtSubjectsOfTheLookback(): void
    {
        $this->history('Gulf Cleaning', $this->maintenance(), 3);
        $this->manualJournal('2026-10-20', $this->otherExpenses(), $this->cashAccount(), '250.00', 'Gulf Cleaning');

        $this->assertCount(1, $this->proposer->propose($this->today, null));

        $this->setThreshold('lookback_days', '5');

        $this->assertCount(1, $this->proposer->propose($this->today, null));

        $this->setThreshold('lookback_days', '4');

        $this->assertSame([], $this->proposer->propose($this->today, null));
    }

    public function testPropose_SuggestsNothingForASubjectThatWasReversed(): void
    {
        $this->history('Gulf Cleaning', $this->maintenance(), 3);
        $subject = $this->manualJournal('2026-10-20', $this->otherExpenses(), $this->cashAccount(), '250.00', 'Gulf Cleaning');

        (new JournalService())->reverse($subject, '2026-10-21', 'Wrong', null);

        $this->assertSame([], $this->proposer->propose($this->today, null));
    }

    public function testPropose_IgnoresHistoryThatIsNeitherOfManualJournalsNorOfTheAgent(): void
    {
        foreach (['2026-09-01', '2026-09-02', '2026-09-03'] as $index => $date) {
            (new PostingService())->post(new JournalDraft(
                date: $date,
                lines: [new JournalLineDraft($this->maintenance(), debit: '100.00', description: 'Gulf Cleaning'), new JournalLineDraft($this->cashAccount(), credit: '100.00')],
                sourceType: 'expense',
                sourceId: 300 + $index,
                description: 'Gulf Cleaning',
                branchId: 1,
            ));
        }

        $this->manualJournal('2026-10-20', $this->otherExpenses(), $this->cashAccount(), '250.00', 'Gulf Cleaning');

        $this->assertSame([], $this->proposer->propose($this->today, null));
    }

    public function testPropose_SuggestsNothingWhenTheBooksRouteNeitherDefaultAccount(): void
    {
        $this->history('Gulf Cleaning', $this->maintenance(), 3);
        $this->manualJournal('2026-10-20', $this->otherExpenses(), $this->cashAccount(), '250.00', 'Gulf Cleaning');
        $this->accountingDb()->table('accounting_routing')->whereIn('routing_key', [RoutingKey::ExpensesDefault->value, RoutingKey::RevenuesDefault->value])->delete();

        $this->assertSame([], $this->proposer->propose($this->today, null));
    }

    public function testPropose_SuggestsForTheExpenseDefaultWhenTheRevenueDefaultIsNotRouted(): void
    {
        $this->history('Gulf Cleaning', $this->maintenance(), 3);
        $this->manualJournal('2026-10-20', $this->otherExpenses(), $this->cashAccount(), '250.00', 'Gulf Cleaning');
        $this->accountingDb()->table('accounting_routing')->where('routing_key', RoutingKey::RevenuesDefault->value)->delete();

        $this->assertCount(1, $this->proposer->propose($this->today, null));
    }

    public function testPropose_SuggestsForTheRevenueDefaultWhenTheExpenseDefaultIsNotRouted(): void
    {
        $otherRevenue = $this->accountIdByCode('4201');
        $capital      = $this->accountIdByCode('4202');

        for ($index = 0; $index < 2; $index++) {
            $this->manualJournal('2026-09-0' . ($index + 1), $this->cashAccount(), $capital, '100.00', 'Scrap sale');
        }

        $this->manualJournal('2026-10-20', $this->cashAccount(), $otherRevenue, '300.00', 'Scrap sale');
        $this->accountingDb()->table('accounting_routing')->where('routing_key', RoutingKey::ExpensesDefault->value)->delete();

        $this->assertCount(1, $this->proposer->propose($this->today, null));
    }

    public function testPropose_CutsTheTextOfTheLineToTwoHundredCharacters(): void
    {
        $text = str_repeat('a', 250);

        $this->history($text, $this->maintenance(), 3);
        $this->manualJournal('2026-10-20', $this->otherExpenses(), $this->cashAccount(), '250.00', $text);

        $lines = $this->proposer->propose($this->today, null)[0]->lines;

        $this->assertSame(200, mb_strlen((string) $lines[0]->description));
        $this->assertSame(200, mb_strlen((string) $lines[1]->description));
    }

    public function testPropose_RoundsTheShareOfTheAccountMostUsed(): void
    {
        $this->history('Gulf Cleaning', $this->maintenance(), 2);
        $this->history('Gulf Cleaning', $this->electricityExpense(), 1, '2026-09-10');
        $this->setThreshold('reclass_min_share', '60');
        $this->manualJournal('2026-10-20', $this->otherExpenses(), $this->cashAccount(), '250.00', 'Gulf Cleaning');

        $draft = $this->proposer->propose($this->today, null)[0];

        $this->assertSame(67, $draft->templateArgs['share']);
        $this->assertSame(67, $draft->evidence[1]['share']);
        $this->assertSame(33, $draft->evidence[2]['share']);
    }

    public function testPropose_PutsTheSuggestionInTheBranchOfTheSubject(): void
    {
        $branch = $this->createBranch();

        $this->history('Gulf Cleaning', $this->maintenance(), 3);
        $this->manualJournal('2026-10-20', $this->otherExpenses(), $this->cashAccount(), '250.00', 'Gulf Cleaning', null, $branch);

        $this->assertSame($branch, $this->proposer->propose($this->today, null)[0]->branchId);
    }

    public function testPropose_IgnoresJournalsThatAreNotManual(): void
    {
        $this->history('Gulf Cleaning', $this->maintenance(), 3);

        (new PostingService())->post(new JournalDraft(
            date: '2026-10-20',
            lines: [new JournalLineDraft($this->otherExpenses(), debit: '250.00', description: 'Gulf Cleaning'), new JournalLineDraft($this->cashAccount(), credit: '250.00')],
            sourceType: 'expense',
            sourceId: 77,
            description: 'Gulf Cleaning',
            branchId: 1,
        ));

        $this->assertSame([], $this->proposer->propose($this->today, null));
    }

    public function testPropose_KeepsToTheBranchesGiven(): void
    {
        $this->history('Gulf Cleaning', $this->maintenance(), 3);
        $this->manualJournal('2026-10-20', $this->otherExpenses(), $this->cashAccount(), '250.00', 'Gulf Cleaning');

        $this->assertCount(1, $this->proposer->propose($this->today, [1]));
        $this->assertSame([], $this->proposer->propose($this->today, [2]));
        $this->assertSame([], $this->proposer->propose($this->today, []));
    }

    public function testPropose_SuggestsNothingForAnAmountOnAnAccountThatIsNotTheDefault(): void
    {
        $this->history('Gulf Cleaning', $this->maintenance(), 3);
        $this->manualJournal('2026-10-20', $this->rentExpense(), $this->cashAccount(), '250.00', 'Gulf Cleaning');

        $this->assertSame([], $this->proposer->propose($this->today, null));
    }

    public function testPropose_SuggestsNothingForAJournalDatedAfterToday(): void
    {
        $this->history('Gulf Cleaning', $this->maintenance(), 3);
        $this->manualJournal('2026-10-26', $this->otherExpenses(), $this->cashAccount(), '250.00', 'Gulf Cleaning');

        $this->assertSame([], $this->proposer->propose($this->today, null));
    }

    public function testPropose_CountsTheJournalsTheAgentMadeAsFurtherUsesOfTheAccount(): void
    {
        $this->history('Gulf Cleaning', $this->maintenance(), 2);
        $first    = $this->manualJournal('2026-10-20', $this->otherExpenses(), $this->cashAccount(), '250.00', 'Gulf Cleaning');
        $approver = $this->agentEmployee();
        $service  = new ProposalService();

        $service->approve($service->create($this->proposer->propose($this->today, null)[0], $approver), $approver);

        $this->manualJournal('2026-10-22', $this->otherExpenses(), $this->cashAccount(), '90.00', 'Gulf Cleaning');

        $second = array_values(array_filter($this->proposer->propose($this->today, null), static fn ($draft): bool => $draft->subjectJournals !== [$first]));

        $this->assertCount(1, $second);
        $this->assertSame(3, $second[0]->templateArgs['uses']);
        $this->assertSame(3, $second[0]->templateArgs['total']);
    }

    public function testPropose_CountsOnlyAccountsThatAreActive(): void
    {
        $this->history('Gulf Cleaning', $this->maintenance(), 3);
        $this->manualJournal('2026-10-20', $this->otherExpenses(), $this->cashAccount(), '250.00', 'Gulf Cleaning');
        $this->accountingDb()->table('accounting_accounts')->where('account_id', $this->maintenance())->update(['active' => 0]);

        $this->assertSame([], $this->proposer->propose($this->today, null));
    }

    public function testPropose_SuggestsAtExactlyTheMinimumShareAndNotBelowIt(): void
    {
        $this->history('Gulf Cleaning', $this->maintenance(), 4);
        $this->history('Gulf Cleaning', $this->electricityExpense(), 1, '2026-09-10');
        $this->manualJournal('2026-10-20', $this->otherExpenses(), $this->cashAccount(), '250.00', 'Gulf Cleaning');

        $this->assertCount(1, $this->proposer->propose($this->today, null));

        $this->setThreshold('reclass_min_share', '81');

        $this->assertSame([], $this->proposer->propose($this->today, null));
    }

    public function testPropose_ShowsNoMoreThanFiveRowsOfTheFrequencyTable(): void
    {
        $this->setThreshold('reclass_min_count', '1');
        $this->setThreshold('reclass_min_share', '1');

        foreach (['5201', '5202', '5203', '5204', '5205', '5206', '542'] as $index => $code) {
            $this->manualJournal('2026-09-0' . ($index + 1), $this->accountIdByCode($code), $this->cashAccount(), '100.00', 'Gulf Cleaning');
        }

        $this->manualJournal('2026-10-20', $this->otherExpenses(), $this->cashAccount(), '250.00', 'Gulf Cleaning');

        $evidence = $this->proposer->propose($this->today, null)[0]->evidence;

        $this->assertCount(6, $evidence);
        $this->assertSame(['journal', 'history', 'history', 'history', 'history', 'history'], array_column($evidence, 'type'));
    }

    public function testPropose_CarriesTheCostCenterOfTheLineToBothLinesOfTheMove(): void
    {
        $db = $this->accountingDb();
        $db->table('accounting_cost_centers')->insert(['code' => 'CC1', 'name' => 'Warehouse', 'active' => 1]);
        $center = (int) $db->insertID();

        $this->history('Gulf Cleaning', $this->maintenance(), 3);
        (new JournalService())->postNew([
            'date'        => '2026-10-20',
            'description' => 'Gulf Cleaning',
            'lines'       => [
                ['account_id' => $this->otherExpenses(), 'debit' => '250.00', 'credit' => '', 'description' => '', 'cost_center_id' => $center],
                ['account_id' => $this->cashAccount(), 'debit' => '', 'credit' => '250.00', 'description' => ''],
            ],
        ], null);

        $lines = $this->proposer->propose($this->today, null)[0]->lines;

        $this->assertSame($center, $lines[0]->costCenterId);
        $this->assertSame($center, $lines[1]->costCenterId);
    }

    public function testPropose_LooksAtNoMoreSubjectsThanItIsAllowed(): void
    {
        $this->history('Gulf Cleaning', $this->maintenance(), 3);
        $this->history('Power bill', $this->electricityExpense(), 3, '2026-09-10');
        $this->manualJournal('2026-10-20', $this->otherExpenses(), $this->cashAccount(), '250.00', 'Gulf Cleaning');
        $this->manualJournal('2026-10-21', $this->otherExpenses(), $this->cashAccount(), '80.00', 'Power bill');

        $this->assertCount(2, $this->proposer->propose($this->today, null));
        $this->assertCount(1, (new ReclassificationProposer(null, null, 1))->propose($this->today, null));
    }

    public function testPropose_GivesTheSameSuggestionsEveryTime(): void
    {
        $this->history('Gulf Cleaning', $this->maintenance(), 3);
        $this->manualJournal('2026-10-20', $this->otherExpenses(), $this->cashAccount(), '250.00', 'Gulf Cleaning');

        $this->assertEquals($this->proposer->propose($this->today, null), $this->proposer->propose($this->today, null));
    }

    public function testPropose_GivesDraftsTheServiceAcceptsAndTheApprovalPostsAsTheMoveOfTheAmount(): void
    {
        $this->history('Gulf Cleaning', $this->maintenance(), 3);
        $subject  = $this->manualJournal('2026-10-20', $this->otherExpenses(), $this->cashAccount(), '250.00', 'Gulf Cleaning');
        $approver = $this->agentEmployee();
        $service  = new ProposalService();
        $before   = $this->journalCount();

        $id = $service->create($this->proposer->propose($this->today, null)[0], $approver);

        $this->assertSame($before, $this->journalCount());

        $service->approve($id, $approver);

        $this->assertSame($before + 1, $this->journalCount());
        $this->assertSame('0.00', $this->balanceOn($this->otherExpenses()));
        $this->assertSame('550.00', $this->balanceOn($this->maintenance()));
    }

    public function testPropose_GivesADraftThatIsSupersededWhenTheSubjectJournalWasReversed(): void
    {
        $this->history('Gulf Cleaning', $this->maintenance(), 3);
        $subject  = $this->manualJournal('2026-10-20', $this->otherExpenses(), $this->cashAccount(), '250.00', 'Gulf Cleaning');
        $approver = $this->agentEmployee();
        $service  = new ProposalService();
        $id       = $service->create($this->proposer->propose($this->today, null)[0], $approver);

        (new JournalService())->reverse($subject, '2026-10-21', 'Wrong', null);

        $this->expectExceptionMessage('The books changed since the proposal was made.');

        $service->approve($id, $approver);
    }

    private function balanceOn(int $accountId): string
    {
        $row = $this->accountingDb()->table('accounting_journal_lines l')
            ->select('COALESCE(SUM(l.debit), 0) - COALESCE(SUM(l.credit), 0) AS balance', false)
            ->join('accounting_journals j', 'j.journal_id = l.journal_id')
            ->whereIn('j.status', ['posted', 'reversed'])
            ->where('l.account_id', $accountId)
            ->get()
            ->getRow();

        return bcadd((string) $row->balance, '0', 2);
    }
}
