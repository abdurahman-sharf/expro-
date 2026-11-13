<?php

namespace Tests\Libraries\Accounting\Ai\Rules;

use App\Libraries\Accounting\Ai\Finding;
use App\Libraries\Accounting\Ai\Rules\BalanceJumpRule;
use App\Libraries\Accounting\EntityAccountService;
use App\Libraries\Accounting\JournalService;
use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\DatabaseTestTrait;
use Config\Database;
use Tests\Support\AiFixtureTrait;

/**
 * A customer who owes much more than a few days ago: the balance of the customer in a branch rose, in the last
 * days, by at least the amount and by at least the percent of the thresholds.
 *
 * @internal
 */
final class BalanceJumpRuleTest extends CIUnitTestCase
{
    use DatabaseTestTrait;
    use AiFixtureTrait;

    protected $migrate     = true;
    protected $migrateOnce = true;
    protected $refresh     = false;
    protected $namespace   = null;
    private BalanceJumpRule $rule;
    private EntityAccountService $entities;
    private int $customer;
    private int $account;

    public static function setUpBeforeClass(): void
    {
        $seeder = Database::seeder('tests');
        $seeder->call('TestDatabaseBootstrapSeeder');
    }

    protected function setUp(): void
    {
        parent::setUp();

        $this->startAgent();
        $this->rule     = new BalanceJumpRule();
        $this->entities = new EntityAccountService();
        $this->customer = $this->insertCustomer('Omar', 'Khaled');
        $this->entities->fill();
        $this->account = (int) $this->entities->accountFor('customer', $this->customer);
    }

    protected function tearDown(): void
    {
        $this->stopAgent();

        parent::tearDown();
    }

    private function owes(string $date, string $amount, ?int $branchId = null): int
    {
        return $this->manualJournal($date, $this->account, $this->accountId('sales_revenue'), $amount, 'Owes', null, $branchId);
    }

    private function pays(string $date, string $amount): int
    {
        return $this->manualJournal($date, $this->cashAccount(), $this->account, $amount, 'Pays');
    }

    public function testDetect_FlagsACustomerWhoseBalanceRoseByMoreThanTheAmountAndThePercent(): void
    {
        $this->owes('2026-09-01', '10000.00');
        $last = $this->owes('2026-10-20', '15000.00');

        $findings = $this->rule->detect($this->ruleContext());

        $this->assertCount(1, $findings);

        $finding = $findings[0];

        $this->assertSame('AI-BALANCE-JUMP', $finding->rule);
        $this->assertSame(Finding::MEDIUM, $finding->severity);
        $this->assertSame($this->customer . '|1|' . $last, $finding->key);
        $this->assertSame('customer', $finding->subjectType);
        $this->assertSame($this->customer, $finding->subjectId);
        $this->assertSame(1, $finding->branchId);
        $this->assertSame('2026-11-08', $finding->occurredOn);
        $this->assertSame('10000.0000', $finding->templateArgs['prior']);
        $this->assertSame('25000.0000', $finding->templateArgs['balance']);
        $this->assertSame('15000.0000', $finding->templateArgs['increase']);
        $this->assertSame(30, $finding->templateArgs['days']);
        $this->assertSame('balance', $finding->evidence[0]['type']);
        $this->assertSame('2026-10-09', $finding->evidence[0]['since']);
        $this->assertSame('journal', $finding->evidence[1]['type']);
        $this->assertSame($last, $finding->evidence[1]['journal_id']);
        $this->assertSame('15000.0000', $finding->evidence[1]['debit']);
        $this->assertCount(2, $finding->evidence);
    }

    public function testDetect_FlagsACustomerWhoOwedNothingBeforeAndRoseByExactlyTheAmount(): void
    {
        $this->owes('2026-10-20', '5000.00');

        $this->assertCount(1, $this->rule->detect($this->ruleContext()));
    }

    public function testDetect_FlagsNothingBelowTheAmount(): void
    {
        $this->owes('2026-10-20', '4999.99');

        $this->assertSame([], $this->rule->detect($this->ruleContext()));
    }

    public function testDetect_FlagsExactlyThePercentAndNotBelowIt(): void
    {
        $this->owes('2026-09-01', '10000.00');
        $this->owes('2026-10-20', '9999.99');

        $this->assertSame([], $this->rule->detect($this->ruleContext()));

        $this->owes('2026-10-21', '0.01');

        $this->assertCount(1, $this->rule->detect($this->ruleContext()));
    }

    public function testDetect_FlagsNothingWhenTheRiseIsSmallAgainstWhatWasOwed(): void
    {
        $this->owes('2026-09-01', '100000.00');
        $this->owes('2026-10-20', '6000.00');

        $this->assertSame([], $this->rule->detect($this->ruleContext()));

        $this->setThreshold('jump_percent', '6');

        $this->assertCount(1, $this->rule->detect($this->ruleContext()));
    }

    public function testDetect_CountsACustomerInCreditAsOwingNothingBefore(): void
    {
        $this->pays('2026-09-01', '3000.00');
        $this->owes('2026-10-20', '6000.00');

        $this->assertSame([], $this->rule->detect($this->ruleContext()));

        $this->owes('2026-10-21', '3000.00');

        $findings = $this->rule->detect($this->ruleContext());

        $this->assertCount(1, $findings);
        $this->assertSame('0.0000', $findings[0]->templateArgs['prior']);
        $this->assertSame('6000.0000', $findings[0]->templateArgs['increase']);
    }

    public function testDetect_FlagsNothingWhenTheRiseWasReversed(): void
    {
        $journal = $this->owes('2026-10-20', '15000.00');
        (new JournalService())->reverse($journal, '2026-10-21', 'Wrong', null);

        $this->assertSame([], $this->rule->detect($this->ruleContext()));
    }

    public function testDetect_FlagsNothingWhenTheCustomerPaidItBack(): void
    {
        $this->owes('2026-10-20', '15000.00');
        $this->pays('2026-10-25', '15000.00');

        $this->assertSame([], $this->rule->detect($this->ruleContext()));
    }

    public function testDetect_FlagsNothingWhenAPaymentWasTakenBack(): void
    {
        $payment = $this->pays('2026-10-20', '5000.00');
        (new JournalService())->reverse($payment, '2026-10-21', 'Wrong', null);

        $this->assertSame([], $this->rule->detect($this->ruleContext()));
    }

    public function testDetect_ShowsAJournalThatWasReversedAmongTheMovementsOfTheDays(): void
    {
        $reversed = $this->owes('2026-10-20', '6000.00');
        (new JournalService())->reverse($reversed, '2026-10-21', 'Wrong', null);
        $this->owes('2026-10-22', '7000.00');

        $findings = $this->rule->detect($this->ruleContext());

        $this->assertCount(1, $findings);
        $this->assertSame('7000.0000', $findings[0]->templateArgs['increase']);
        $this->assertContains($reversed, array_column($findings[0]->evidence, 'journal_id'));
    }

    public function testDetect_ShowsTheLargestMovementsOfTheDaysFirstAndNoMoreThanTen(): void
    {
        $this->owes('2026-09-01', '1000.00');

        for ($index = 1; $index <= 11; $index++) {
            $this->owes('2026-10-' . (10 + $index), '1000.00');
        }

        $largest = $this->owes('2026-10-25', '4000.00');

        $evidence = $this->rule->detect($this->ruleContext())[0]->evidence;

        $this->assertCount(11, $evidence);
        $this->assertSame($largest, $evidence[1]['journal_id']);
        $this->assertSame('4000.0000', $evidence[1]['debit']);
    }

    public function testDetect_ShowsOnlyTheMovementsOfTheBranchAndTheDaysAsEvidence(): void
    {
        $branch = $this->createBranch();

        $before = $this->owes('2026-09-01', '1000.00');
        $inside = $this->owes('2026-10-20', '6000.00');
        $other  = $this->owes('2026-10-21', '9000.00', $branch);

        $findings = $this->rule->detect($this->ruleContext([1]));

        $this->assertCount(1, $findings);
        $this->assertSame([$inside], array_column(array_slice($findings[0]->evidence, 1), 'journal_id'));
        $this->assertNotContains($before, array_column($findings[0]->evidence, 'journal_id'));
        $this->assertNotContains($other, array_column($findings[0]->evidence, 'journal_id'));
    }

    public function testDetect_ReadsOnlyTheAccountsOfCustomers(): void
    {
        $supplier = $this->insertSupplier();
        $this->entities->fill();

        $this->manualJournal('2026-10-20', $this->rentExpense(), $this->entities->accountFor('supplier', $supplier), '15000.00', 'Owed');
        $this->manualJournal('2026-10-20', $this->rentExpense(), $this->cashAccount(), '15000.00', 'Rent');

        $this->assertSame([], $this->rule->detect($this->ruleContext()));
    }

    public function testDetect_KeepsEachBranchOfTheCustomerApart(): void
    {
        $branch = $this->createBranch();
        $this->owes('2026-10-20', '3000.00');
        $this->owes('2026-10-21', '3000.00', $branch);

        $this->assertSame([], $this->rule->detect($this->ruleContext()));

        $this->owes('2026-10-22', '3000.00', $branch);

        $findings = $this->rule->detect($this->ruleContext());

        $this->assertCount(1, $findings);
        $this->assertSame($branch, $findings[0]->branchId);
    }

    public function testDetect_ShowsNoMovementDatedAfterTodayAsEvidence(): void
    {
        $inside = $this->owes('2026-10-20', '6000.00');
        $later  = $this->owes('2026-11-09', '500.00');

        $findings = $this->rule->detect($this->ruleContext());

        $this->assertCount(1, $findings);
        $this->assertSame([$inside], array_column(array_slice($findings[0]->evidence, 1), 'journal_id'));
        $this->assertNotContains($later, array_column($findings[0]->evidence, 'journal_id'));
    }

    public function testDetect_CountsWhatWasPostedOnTheDayTheDaysBeginAsWhatWasOwedBefore(): void
    {
        $this->owes('2026-10-09', '10000.00');
        $this->owes('2026-10-20', '6000.00');

        $this->assertSame([], $this->rule->detect($this->ruleContext()));
    }

    public function testDetect_KeysTheFindingByTheLastJournalOfTheDaysAndNotOfTheBooks(): void
    {
        $last = $this->owes('2026-10-20', '15000.00');
        $this->owes('2026-09-01', '10000.00');

        $findings = $this->rule->detect($this->ruleContext());

        $this->assertCount(1, $findings);
        $this->assertSame($this->customer . '|1|' . $last, $findings[0]->key);
    }

    public function testDetect_UsesTheDaysOfTheSettingAsTheLookback(): void
    {
        $this->owes('2026-10-20', '15000.00');

        $this->setThreshold('jump_days', '5');

        $this->assertSame([], $this->rule->detect($this->ruleContext()));
        $this->assertCount(1, $this->rule->detect($this->ruleContext(null, '2026-10-24')));
    }

    public function testDetect_LeavesOutJournalsDatedAfterToday(): void
    {
        $this->owes('2026-11-09', '15000.00');

        $this->assertSame([], $this->rule->detect($this->ruleContext()));
    }

    public function testDetect_UsesTheAmountOfTheSetting(): void
    {
        $this->owes('2026-10-20', '800.00');

        $this->assertSame([], $this->rule->detect($this->ruleContext()));

        $this->setThreshold('jump_min', '800');

        $this->assertCount(1, $this->rule->detect($this->ruleContext()));
    }

    public function testDetect_GivesANewFindingWhenTheCustomerMovesAgain(): void
    {
        $this->owes('2026-10-20', '15000.00');
        $first = $this->rule->detect($this->ruleContext())[0]->key;

        $this->owes('2026-10-25', '1.00');

        $this->assertNotSame($first, $this->rule->detect($this->ruleContext())[0]->key);
    }

    public function testDetect_KeepsToTheBranchesGiven(): void
    {
        $this->owes('2026-10-20', '15000.00');

        $this->assertCount(1, $this->rule->detect($this->ruleContext([1])));
        $this->assertSame([], $this->rule->detect($this->ruleContext([2])));
        $this->assertSame([], $this->rule->detect($this->ruleContext([])));
    }

    public function testDetect_GivesTheSameFindingsEveryTime(): void
    {
        $this->owes('2026-10-20', '15000.00');

        $this->assertEquals($this->rule->detect($this->ruleContext()), $this->rule->detect($this->ruleContext()));
        $this->assertSame('AI-BALANCE-JUMP', $this->rule->code());
    }
}
