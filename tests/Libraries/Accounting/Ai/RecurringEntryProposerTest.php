<?php

namespace Tests\Libraries\Accounting\Ai;

use App\Libraries\Accounting\Ai\ProposalService;
use App\Libraries\Accounting\Ai\RecurringEntryProposer;
use App\Libraries\Accounting\EntityAccountService;
use App\Libraries\Accounting\JournalDraft;
use App\Libraries\Accounting\JournalLineDraft;
use App\Libraries\Accounting\JournalService;
use App\Libraries\Accounting\PostingService;
use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\DatabaseTestTrait;
use Config\Database;
use DateTimeImmutable;
use Tests\Support\AiFixtureTrait;

/**
 * The suggestion of a missing recurring entry: an expense posted in each of the last months, for about the same
 * amount, and not posted this month although its usual day has passed.
 *
 * @internal
 */
final class RecurringEntryProposerTest extends CIUnitTestCase
{
    use DatabaseTestTrait;
    use AiFixtureTrait;

    protected $migrate     = true;
    protected $migrateOnce = true;
    protected $refresh     = false;
    protected $namespace   = null;
    private RecurringEntryProposer $proposer;
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
        $this->proposer = new RecurringEntryProposer();
        $this->today    = new DateTimeImmutable('2026-11-25');
    }

    protected function tearDown(): void
    {
        $this->stopAgent();

        parent::tearDown();
    }

    /**
     * @param list<string> $dates
     *
     * @return list<int>
     */
    private function rent(array $dates, string $amount = '2000.00', string $text = 'Office rent', ?int $account = null): array
    {
        return array_map(fn (string $date): int => $this->manualJournal($date, $account ?? $this->rentExpense(), $this->cashAccount(), $amount, $text), $dates);
    }

    public function testPropose_RepeatsTheJournalOfTheLastMonthWhenTheExpenseWasSeenInEachOfTheLastThreeMonths(): void
    {
        [, , $october] = $this->rent(['2026-08-05', '2026-09-05', '2026-10-05']);

        $drafts = $this->proposer->propose($this->today, null);

        $this->assertCount(1, $drafts);

        $draft = $drafts[0];

        $this->assertSame('recurring', $draft->kind);
        $this->assertSame('rules:recurring', $draft->proposer);
        $this->assertSame('2026-11-25', $draft->date);
        $this->assertSame(1, $draft->branchId);
        $this->assertSame([$october], $draft->subjectJournals);
        $this->assertCount(2, $draft->lines);
        $this->assertSame($this->rentExpense(), $draft->lines[0]->accountId);
        $this->assertSame('2000.0000', $draft->lines[0]->debit);
        $this->assertSame($this->cashAccount(), $draft->lines[1]->accountId);
        $this->assertSame('2000.0000', $draft->lines[1]->credit);
        $this->assertSame('2026-11', $draft->templateArgs['month']);
        $this->assertSame(3, $draft->templateArgs['months']);
        $this->assertStringContainsString('Office rent', $draft->description);
    }

    public function testPropose_ShowsTheJournalsOfTheMonthsAndTheMonthThatIsMissingAsEvidence(): void
    {
        $ids = $this->rent(['2026-08-05', '2026-09-05', '2026-10-05']);

        $evidence = $this->proposer->propose($this->today, null)[0]->evidence;

        $this->assertCount(4, $evidence);
        $this->assertSame([$ids[0], $ids[1], $ids[2]], array_column(array_slice($evidence, 0, 3), 'journal_id'));
        $this->assertSame('absent', $evidence[3]['type']);
        $this->assertSame('2026-11', $evidence[3]['month']);
        $this->assertSame(0, $evidence[3]['found']);
    }

    public function testPropose_SuggestsNothingWhenTheExpenseWasPostedThisMonth(): void
    {
        $this->rent(['2026-08-05', '2026-09-05', '2026-10-05', '2026-11-04']);

        $this->assertSame([], $this->proposer->propose($this->today, null));
    }

    public function testPropose_SuggestsNothingWhenAMonthOfTheLastThreeIsMissing(): void
    {
        $this->rent(['2026-08-05', '2026-10-05']);

        $this->assertSame([], $this->proposer->propose($this->today, null));
    }

    public function testPropose_SuggestsNothingBeforeTheUsualDayOfTheMonthAndSuggestsOnIt(): void
    {
        $this->rent(['2026-08-05', '2026-09-05', '2026-10-05']);

        $this->assertSame([], $this->proposer->propose(new DateTimeImmutable('2026-11-04'), null));
        $this->assertCount(1, $this->proposer->propose(new DateTimeImmutable('2026-11-05'), null));
    }

    public function testPropose_WaitsForTheLastDayThatWasUsualInAnyOfTheMonths(): void
    {
        $this->rent(['2026-08-05', '2026-09-20', '2026-10-05']);

        $this->assertSame([], $this->proposer->propose(new DateTimeImmutable('2026-11-19'), null));
        $this->assertCount(1, $this->proposer->propose(new DateTimeImmutable('2026-11-20'), null));
    }

    public function testPropose_TakesTheLastDayOfAShortMonthForAUsualDayThatThisMonthLacks(): void
    {
        $this->rent(['2026-11-30', '2026-12-31', '2027-01-31']);

        $this->assertSame([], $this->proposer->propose(new DateTimeImmutable('2027-02-27'), null));
        $this->assertCount(1, $this->proposer->propose(new DateTimeImmutable('2027-02-28'), null));
    }

    public function testPropose_SuggestsNothingWhenTheAmountsVaryMoreThanTheTolerance(): void
    {
        $this->rent(['2026-08-05', '2026-09-05'], '2000.00');
        $this->rent(['2026-10-05'], '2400.00');

        $this->assertSame([], $this->proposer->propose($this->today, null));

        $this->setThreshold('recurring_tolerance', '17');

        $this->assertCount(1, $this->proposer->propose($this->today, null));
    }

    public function testPropose_SuggestsWhenTheAmountsVaryExactlyAsMuchAsTheTolerance(): void
    {
        $this->rent(['2026-08-05', '2026-09-05'], '1000.00');
        $this->rent(['2026-10-05'], '1100.00');

        $this->assertSame('1100.0000', $this->proposer->propose($this->today, null)[0]->lines[0]->debit);
    }

    public function testPropose_UsesTheNumberOfMonthsOfTheSetting(): void
    {
        $this->rent(['2026-09-05', '2026-10-05']);

        $this->assertSame([], $this->proposer->propose($this->today, null));

        $this->setThreshold('recurring_months', '2');

        $this->assertCount(1, $this->proposer->propose($this->today, null));
    }

    public function testPropose_KeepsExpensesOfDifferentTextAndAccountApart(): void
    {
        $this->rent(['2026-08-05', '2026-09-05', '2026-10-05'], '2000.00', 'Office rent');
        $this->rent(['2026-08-06', '2026-09-06', '2026-10-06'], '300.00', 'Warehouse rent');
        $this->rent(['2026-08-07', '2026-09-07', '2026-10-07'], '90.00', 'Power', $this->electricityExpense());
        $this->rent(['2026-11-04'], '2000.00', 'Warehouse rent');

        $amounts = array_map(static fn ($draft): string => $draft->lines[0]->debit, $this->proposer->propose($this->today, null));

        $this->assertSame(['2000.0000', '90.0000'], $amounts);
    }

    public function testPropose_SuggestsNothingWhenTheLastJournalHasMoreThanOneExpenseLine(): void
    {
        $this->rent(['2026-08-05', '2026-09-05']);

        (new PostingService())->post(new JournalDraft(
            date: '2026-10-05',
            lines: [new JournalLineDraft($this->rentExpense(), debit: '2000.00', description: 'Office rent'), new JournalLineDraft($this->electricityExpense(), debit: '50.00', description: 'Office rent'), new JournalLineDraft($this->cashAccount(), credit: '2050.00', description: 'Office rent')],
            sourceType: 'manual',
            description: 'Office rent',
            branchId: 1,
        ));

        $this->assertSame([], $this->proposer->propose($this->today, null));
    }

    public function testPropose_SuggestsNothingWhenTheLastJournalTouchesTheAccountOfACustomer(): void
    {
        $customer = $this->insertCustomer();
        (new EntityAccountService())->fill();
        $account = $this->customerLedgerAccount($customer);

        $this->rent(['2026-08-05', '2026-09-05']);
        $this->manualJournal('2026-10-05', $this->rentExpense(), $account, '2000.00', 'Office rent');

        $this->assertSame([], $this->proposer->propose($this->today, null));
    }

    public function testPropose_SuggestsNothingForAnAccountThatIsNotAnExpense(): void
    {
        $capital = $this->accountIdByCode('4202');

        for ($index = 0; $index < 3; $index++) {
            $this->manualJournal(sprintf('2026-%02d-05', 8 + $index), $capital, $this->cashAccount(), '2000.00', 'Scrap');
        }

        $this->assertSame([], $this->proposer->propose($this->today, null));
    }

    public function testPropose_SuggestsNothingForCreditsOnAnExpenseAccount(): void
    {
        foreach (['2026-08-05', '2026-09-05', '2026-10-05'] as $date) {
            $this->manualJournal($date, $this->cashAccount(), $this->rentExpense(), '2000.00', 'Office rent');
        }

        $this->assertSame([], $this->proposer->propose($this->today, null));
    }

    public function testPropose_SuggestsNothingForTheExpenseLinesOfJournalsThatSalesMade(): void
    {
        foreach (['2026-08-05', '2026-09-05', '2026-10-05'] as $index => $date) {
            (new PostingService())->post(new JournalDraft(
                date: $date,
                lines: [new JournalLineDraft($this->rentExpense(), debit: '2000.00'), new JournalLineDraft($this->cashAccount(), credit: '2000.00')],
                sourceType: 'sale',
                sourceId: 200 + $index,
                branchId: 1,
            ));
        }

        $this->assertSame([], $this->proposer->propose($this->today, null));
    }

    public function testPropose_SuggestsWhenTheAmountsVaryExactlyAsMuchAsTheToleranceAndNotMore(): void
    {
        $this->rent(['2026-08-05', '2026-09-05'], '1000.00');
        $this->rent(['2026-10-05'], '1250.00');

        $this->setThreshold('recurring_tolerance', '20');

        $this->assertCount(1, $this->proposer->propose($this->today, null));

        $this->setThreshold('recurring_tolerance', '19');

        $this->assertSame([], $this->proposer->propose($this->today, null));
    }

    public function testPropose_ReadsNoMoreRowsThanItIsAllowed(): void
    {
        $this->rent(['2026-08-05', '2026-09-05', '2026-10-05']);

        $this->assertCount(1, $this->proposer->propose($this->today, null));
        $this->assertSame([], (new RecurringEntryProposer(null, null, 2))->propose($this->today, null));
    }

    public function testPropose_ReadsTheExpenseDocumentsAsOneExpenseOfTheirAccount(): void
    {
        foreach (['2026-08-05', '2026-09-05', '2026-10-05'] as $index => $date) {
            (new PostingService())->post(new JournalDraft(
                date: $date,
                lines: [new JournalLineDraft($this->rentExpense(), debit: '2000.00'), new JournalLineDraft($this->cashAccount(), credit: '2000.00')],
                sourceType: 'expense',
                sourceId: 100 + $index,
                description: 'Expense #' . (100 + $index),
                branchId: 1,
            ));
        }

        $drafts = $this->proposer->propose($this->today, null);

        $this->assertCount(1, $drafts);
        $this->assertSame('2000.0000', $drafts[0]->lines[0]->debit);
    }

    public function testPropose_IgnoresAJournalThatWasReversed(): void
    {
        $ids = $this->rent(['2026-08-05', '2026-09-05', '2026-10-05']);

        (new JournalService())->reverse($ids[2], '2026-10-06', 'Wrong', null);

        $this->assertSame([], $this->proposer->propose($this->today, null));
    }

    public function testPropose_KeepsToTheBranchesGiven(): void
    {
        $branch = $this->createBranch();

        for ($index = 0; $index < 3; $index++) {
            $this->manualJournal(sprintf('2026-%02d-05', 8 + $index), $this->rentExpense(), $this->cashAccount(), '2000.00', 'Office rent', null, $branch);
        }

        $this->assertCount(1, $this->proposer->propose($this->today, null));
        $this->assertCount(1, $this->proposer->propose($this->today, [$branch]));
        $this->assertSame([], $this->proposer->propose($this->today, [1]));
        $this->assertSame([], $this->proposer->propose($this->today, []));
    }

    public function testPropose_KeysTheSuggestionByTheMonthSoNextMonthIsAnotherOne(): void
    {
        $this->rent(['2026-08-05', '2026-09-05', '2026-10-05']);

        $november = $this->proposer->propose($this->today, null)[0]->dedupeKey;
        $again    = $this->proposer->propose(new DateTimeImmutable('2026-11-26'), null)[0]->dedupeKey;

        $this->assertSame($november, $again);

        $this->rent(['2026-11-05']);

        $december = $this->proposer->propose(new DateTimeImmutable('2026-12-25'), null);

        $this->assertCount(1, $december);
        $this->assertNotSame($november, $december[0]->dedupeKey);
    }

    public function testPropose_GivesDraftsTheServiceAcceptsAndTheApprovalPostsOnceForTheMonth(): void
    {
        $this->rent(['2026-08-05', '2026-09-05', '2026-10-05']);

        $approver = $this->agentEmployee();
        $service  = new ProposalService();
        $draft    = $this->proposer->propose($this->today, null)[0];
        $id       = $service->create($draft, $approver);

        $service->approve($id, $approver);

        $row = $this->accountingDb()->table('accounting_journals')->where(['source_type' => ProposalService::SOURCE, 'source_id' => $id])->get()->getRow();

        $this->assertSame('2026-11-25', $row->journal_date);
        $this->assertSame('ai', $row->origin);
        $this->assertSame([], $this->proposer->propose($this->today, null));
    }

    public function testPropose_CountsTheJournalTheAgentMadeAsTheOccurrenceOfItsMonth(): void
    {
        $this->rent(['2026-08-05', '2026-09-05', '2026-10-05']);

        $approver = $this->agentEmployee();
        $service  = new ProposalService();
        $service->approve($service->create($this->proposer->propose($this->today, null)[0], $approver), $approver);

        $december = $this->proposer->propose(new DateTimeImmutable('2026-12-25'), null);

        $this->assertCount(1, $december);
        $this->assertSame('2000.0000', $december[0]->lines[0]->debit);
        $this->assertSame('Office rent', $december[0]->lines[0]->description);
    }

    public function testPropose_SuggestsNothingWhenTheLastJournalTouchesTheAccountOfASupplier(): void
    {
        $supplier = $this->insertSupplier();
        $entities = new EntityAccountService();
        $entities->fill();

        $this->rent(['2026-08-05', '2026-09-05']);
        $this->manualJournal('2026-10-05', $this->rentExpense(), (int) $entities->accountFor('supplier', $supplier), '2000.00', 'Office rent');

        $this->assertSame([], $this->proposer->propose($this->today, null));
    }

    public function testPropose_SuggestsNothingWhenTheLastJournalTouchesTheAccountOfAStockLocation(): void
    {
        $location = $this->insertStockLocation();
        $entities = new EntityAccountService();
        $entities->fill();

        $this->rent(['2026-08-05', '2026-09-05']);
        $this->manualJournal('2026-10-05', $this->rentExpense(), (int) $entities->accountFor('stock_location', $location), '2000.00', 'Office rent');

        $this->assertSame([], $this->proposer->propose($this->today, null));
    }

    public function testPropose_RepeatsAJournalWhoseOtherLineIsAnExpenseCredit(): void
    {
        $this->rent(['2026-08-05', '2026-09-05']);
        (new PostingService())->post(new JournalDraft(
            date: '2026-10-05',
            lines: [new JournalLineDraft($this->rentExpense(), debit: '2100.00', description: 'Office rent'), new JournalLineDraft($this->cashAccount(), credit: '2000.00', description: 'Office rent'), new JournalLineDraft($this->electricityExpense(), credit: '100.00', description: 'Office rent')],
            sourceType: 'manual',
            description: 'Office rent',
            branchId: 1,
        ));

        $drafts = $this->proposer->propose($this->today, null);

        $this->assertCount(1, $drafts);
        $this->assertCount(3, $drafts[0]->lines);
    }

    public function testPropose_WaitsForTheLastDayOfAMonthEvenWhenAnEarlierDayWasPostedLast(): void
    {
        $this->rent(['2026-08-05', '2026-09-05']);
        $this->rent(['2026-10-20'], '2100.00');
        $this->rent(['2026-10-05'], '2000.00');

        $this->assertSame([], $this->proposer->propose(new DateTimeImmutable('2026-11-19'), null));

        $drafts = $this->proposer->propose(new DateTimeImmutable('2026-11-20'), null);

        $this->assertCount(1, $drafts);
        $this->assertSame('2000.0000', $drafts[0]->lines[0]->debit);
    }

    public function testPropose_TakesTheLastJournalOfAMonthAsTheOneToRepeat(): void
    {
        $this->rent(['2026-08-05', '2026-09-05']);
        $this->rent(['2026-10-05'], '2000.00');
        $this->rent(['2026-10-06'], '2100.00');

        $draft = $this->proposer->propose($this->today, null)[0];

        $this->assertSame('2100.0000', $draft->lines[0]->debit);
        $this->assertSame('2100.0000', $draft->evidence[2]['amount']);
    }

    public function testPropose_CountsOnlyTheRowsOfTheMonthsItLooksAt(): void
    {
        $this->rent(['2026-01-05']);
        $this->rent(['2026-08-05', '2026-09-05', '2026-10-05']);

        $this->assertCount(1, (new RecurringEntryProposer(null, null, 3))->propose($this->today, null));
    }

    public function testPropose_IgnoresAJournalDatedAfterToday(): void
    {
        $this->rent(['2026-08-05', '2026-09-05', '2026-10-05', '2026-11-07']);

        $this->assertCount(1, $this->proposer->propose(new DateTimeImmutable('2026-11-06'), null));
    }

    public function testPropose_CutsTheTextOfTheExpenseToTwoHundredCharacters(): void
    {
        $this->rent(['2026-08-05', '2026-09-05', '2026-10-05'], '2000.00', str_repeat('a', 250));

        $lines = $this->proposer->propose($this->today, null)[0]->lines;

        $this->assertSame(200, mb_strlen((string) $lines[0]->description));
    }

    public function testPropose_NamesAnExpenseThatHasNoTextByItsAccount(): void
    {
        foreach (['2026-08-05', '2026-09-05', '2026-10-05'] as $index => $date) {
            (new PostingService())->post(new JournalDraft(
                date: $date,
                lines: [new JournalLineDraft($this->rentExpense(), debit: '2000.00'), new JournalLineDraft($this->cashAccount(), credit: '2000.00')],
                sourceType: 'expense',
                sourceId: 400 + $index,
                description: 'Expense #' . (400 + $index),
                branchId: 1,
            ));
        }

        $draft = $this->proposer->propose($this->today, null)[0];

        $this->assertStringContainsString('Recurring expense 5201', $draft->description);
    }

    public function testPropose_CountsAnExpenseThatTheAgentMadeFromAnExpenseDocumentAsItsMonth(): void
    {
        foreach (['2026-08-05', '2026-09-05', '2026-10-05'] as $index => $date) {
            (new PostingService())->post(new JournalDraft(
                date: $date,
                lines: [new JournalLineDraft($this->rentExpense(), debit: '2000.00'), new JournalLineDraft($this->cashAccount(), credit: '2000.00')],
                sourceType: 'expense',
                sourceId: 500 + $index,
                description: 'Expense #' . (500 + $index),
                branchId: 1,
            ));
        }

        $approver = $this->agentEmployee();
        $service  = new ProposalService();
        $service->approve($service->create($this->proposer->propose($this->today, null)[0], $approver), $approver);

        $this->assertSame([], $this->proposer->propose($this->today, null));
        $this->assertCount(1, $this->proposer->propose(new DateTimeImmutable('2026-12-25'), null));
    }

    public function testPropose_CarriesTheCostCenterOfTheLinesToTheRepeatedJournal(): void
    {
        $db = $this->accountingDb();
        $db->table('accounting_cost_centers')->insert(['code' => 'CC1', 'name' => 'Shop', 'active' => 1]);
        $center = (int) $db->insertID();

        $this->rent(['2026-08-05', '2026-09-05']);
        (new JournalService())->postNew([
            'date'        => '2026-10-05',
            'description' => 'Office rent',
            'lines'       => [
                ['account_id' => $this->rentExpense(), 'debit' => '2000.00', 'credit' => '', 'description' => '', 'cost_center_id' => $center],
                ['account_id' => $this->cashAccount(), 'debit' => '', 'credit' => '2000.00', 'description' => ''],
            ],
        ], null);

        $lines = $this->proposer->propose($this->today, null)[0]->lines;

        $this->assertSame($center, $lines[0]->costCenterId);
        $this->assertNull($lines[1]->costCenterId);
    }

    public function testPropose_PutsTheTextOfTheExpenseOnlyOnTheLinesThatHaveNone(): void
    {
        $this->rent(['2026-08-05', '2026-09-05']);
        (new JournalService())->postNew([
            'date'        => '2026-10-05',
            'description' => 'Office rent',
            'lines'       => [
                ['account_id' => $this->rentExpense(), 'debit' => '2000.00', 'credit' => '', 'description' => ''],
                ['account_id' => $this->cashAccount(), 'debit' => '', 'credit' => '2000.00', 'description' => 'Paid by cheque'],
            ],
        ], null);

        $lines = $this->proposer->propose($this->today, null)[0]->lines;

        $this->assertSame('Office rent', $lines[0]->description);
        $this->assertSame('Paid by cheque', $lines[1]->description);
    }

    public function testPropose_KeepsEveryCombinationOfBranchAccountAndTextApartInTheGroupsAndTheKeys(): void
    {
        $months = ['2026-08-05', '2026-09-05', '2026-10-05'];
        $branch = $this->createBranch();

        $this->rent($months, '2000.00', 'Office rent');
        $this->rent($months, '300.00', 'Warehouse rent');
        $this->rent($months, '90.00', 'Office rent', $this->electricityExpense());

        foreach ($months as $date) {
            $this->manualJournal($date, $this->rentExpense(), $this->cashAccount(), '2000.00', 'Office rent', null, $branch);
        }

        $drafts = $this->proposer->propose($this->today, null);

        $this->assertCount(4, $drafts);
        $this->assertCount(4, array_unique(array_map(static fn ($draft): string => $draft->dedupeKey, $drafts)));
        $this->assertContains($branch, array_map(static fn ($draft): ?int => $draft->branchId, $drafts));
    }
}
