<?php

namespace Tests\Libraries\Accounting;

use App\Libraries\Accounting\AccountingError;
use App\Libraries\Accounting\AccountingException;
use App\Libraries\Accounting\AccountingGuard;
use App\Libraries\Accounting\EntityAccountService;
use App\Libraries\Accounting\JournalDraft;
use App\Libraries\Accounting\JournalLineDraft;
use App\Libraries\Accounting\OpeningStock;
use App\Libraries\Accounting\OpeningStockPlan;
use App\Libraries\Accounting\OpeningStockProblem;
use App\Libraries\Accounting\OpeningStockResult;
use App\Libraries\Accounting\OpeningStockStatus;
use App\Libraries\Accounting\PostingError;
use App\Libraries\Accounting\PostingException;
use App\Libraries\Accounting\PostingService;
use App\Libraries\Accounting\StockLedger;
use App\Libraries\Organization\BranchError;
use App\Libraries\Organization\BranchException;
use CodeIgniter\Database\BaseConnection;
use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\DatabaseTestTrait;
use Config\Database;
use Tests\Support\AccountingFixtureTrait;
use Tests\Support\ConcurrentDbRaceTrait;
use Tests\Support\EmployeeFixtureTrait;
use Tests\Support\ItemFixtureTrait;
use Tests\Support\PurchaseAccountingFixtureTrait;
use Tests\Support\SaleAccountingFixtureTrait;

/**
 * The stock that stood on the shelves before the books began, brought into them once: a debit to
 * the account of the stock location, a credit to the opening balance equity account, and a valued
 * movement for each item. The shelves are never touched, a location is posted once, and what a
 * person has to settle is reported and left alone.
 */
class OpeningStockTest extends CIUnitTestCase
{
    use DatabaseTestTrait;
    use AccountingFixtureTrait;
    use ConcurrentDbRaceTrait;
    use EmployeeFixtureTrait;
    use ItemFixtureTrait;
    use SaleAccountingFixtureTrait;
    use PurchaseAccountingFixtureTrait;

    protected $migrate     = true;
    protected $migrateOnce = true;
    protected $refresh     = false;
    protected $namespace   = null;

    private const START = '2020-01-01';

    private OpeningStock $opening;

    /** @var list<array<string, mixed>> */
    private array $shelfBackup = [];

    public static function setUpBeforeClass(): void
    {
        $seeder = Database::seeder('tests');
        $seeder->call('TestDatabaseBootstrapSeeder');
    }

    protected function setUp(): void
    {
        parent::setUp();

        $this->startBuying();
        $this->opening = new OpeningStock();

        $this->shelfBackup = $this->accountingDb()->table('item_quantities')->get()->getResultArray();
        $this->accountingDb()->table('item_quantities')->emptyTable();
    }

    protected function tearDown(): void
    {
        $this->stopBuying();

        $this->accountingDb()->table('item_quantities')->emptyTable();

        if ($this->shelfBackup !== []) {
            $this->accountingDb()->table('item_quantities')->insertBatch($this->shelfBackup);
        }

        $this->shelfBackup = [];

        parent::tearDown();
    }

    /**
     * Units that stand on the shelves with no movement behind them, as stock does from before
     * the books began.
     */
    private function shelves(int $itemId, string $quantity, int $locationId = self::LOCATION_ID): void
    {
        $this->accountingDb()->table('item_quantities')->replace(['item_id' => $itemId, 'location_id' => $locationId, 'quantity' => $quantity]);
    }

    /**
     * @return array<string, string>
     */
    private function shelfRows(): array
    {
        $rows = [];

        foreach ($this->accountingDb()->table('item_quantities')->orderBy('item_id')->orderBy('location_id')->get()->getResult() as $row) {
            $rows[$row->item_id . ':' . $row->location_id] = bcadd((string) $row->quantity, '0', 3);
        }

        return $rows;
    }

    /**
     * @return array{journals: int, entries: int, inventory: int, audit: int}
     */
    private function counts(): array
    {
        return [
            'journals'  => $this->countRows('accounting_journals'),
            'entries'   => $this->countRows('accounting_stock_entries'),
            'inventory' => $this->countRows('inventory'),
            'audit'     => $this->countRows('accounting_audit_log'),
        ];
    }

    private function onlyResult(array $results): OpeningStockResult
    {
        $this->assertCount(1, $results);

        return $results[0];
    }

    /**
     * @return list<object>
     */
    private function openingJournals(?int $locationId = null): array
    {
        $builder = $this->accountingDb()->table('accounting_journals')->where('source_type', OpeningStock::SOURCE)->orderBy('journal_id');

        if ($locationId !== null) {
            $builder->where('source_id', $locationId);
        }

        return $builder->get()->getResult();
    }

    /**
     * @return list<object>
     */
    private function openingEntries(?int $locationId = null): array
    {
        $builder = $this->accountingDb()->table('accounting_stock_entries')->where('source_type', OpeningStock::SOURCE)->orderBy('entry_id');

        if ($locationId !== null) {
            $builder->where('source_id', $locationId);
        }

        return $builder->get()->getResult();
    }

    /**
     * @return list<string> "account:debit:credit" of each line.
     */
    private function linesOf(int $journalId): array
    {
        return array_map(
            static fn (object $line): string => $line->account_id . ':' . $line->debit . ':' . $line->credit,
            $this->accountingDb()->table('accounting_journal_lines')->select('account_id, debit, credit')->where('journal_id', $journalId)->orderBy('line_no', 'ASC')->get()->getResult(),
        );
    }

    private function assertRefusedWith(AccountingError $error, callable $work): void
    {
        try {
            $work();
        } catch (AccountingException $failure) {
            $this->assertSame($error, $failure->error);

            return;
        }

        $this->fail('The refusal ' . $error->value . ' was expected.');
    }

    /**
     * @param list<OpeningStockProblem> $problems
     */
    private function assertFindings(array $problems, OpeningStockPlan $plan): void
    {
        $this->assertSame(array_map(static fn (OpeningStockProblem $problem): string => $problem->value, $problems), array_map(static fn ($finding): string => $finding->problem->value, $plan->findings));
    }

    private function secondBranchLocation(string $name = 'Branch store'): array
    {
        $branch   = $this->createBranch('O' . random_int(10, 99), 'Opening branch');
        $location = $this->anotherLocation($name);
        $this->accountingDb()->table('stock_locations')->where('location_id', $location)->update(['branch_id' => $branch]);

        return [$branch, $location];
    }

    public function testPost_PostsADebitToTheLocationAndACreditToTheOpeningEquityAndAValuedMovementForEachItem(): void
    {
        $rice  = $this->item('50.00');
        $sugar = $this->item('12.50');
        $this->shelves($rice, '10');
        $this->shelves($sugar, '4');

        $result = $this->onlyResult($this->opening->post([self::LOCATION_ID], null));

        $this->assertSame(OpeningStockStatus::Posted, $result->plan->status);
        $this->assertSame(1, $result->revision);
        $this->assertSame('550.0000', $result->plan->value());
        $this->assertSame('14.000', $result->plan->units());
        $this->assertSame([$this->stockAccount() . ':550.0000:0.0000', $this->accountId('opening_balance') . ':0.0000:550.0000'], $this->linesOf($result->journalId));

        $entries = $this->openingEntries();

        $this->assertCount(2, $entries);
        $this->assertSame([(string) $rice, '10.000', '500.0000', 'posting', (string) $result->journalId], [(string) $entries[0]->item_id, $entries[0]->quantity, $entries[0]->value, $entries[0]->kind, (string) $entries[0]->journal_id]);
        $this->assertSame([(string) $sugar, '4.000', '50.0000', 'posting', (string) $result->journalId], [(string) $entries[1]->item_id, $entries[1]->quantity, $entries[1]->value, $entries[1]->kind, (string) $entries[1]->journal_id]);
        $this->assertSame(['quantity' => '10.000', 'value' => '500.00'], $this->stockOf($rice));
        $this->assertStockTies();
    }

    public function testPost_LeavesTheQuantitiesOnTheShelvesAndTheInventoryLogAsTheyWere(): void
    {
        $this->shelves($this->item('50.00'), '10');
        $this->shelves($this->item('12.50'), '4');
        $this->shelves($this->item('3.00'), '-2');

        $shelves   = $this->shelfRows();
        $inventory = $this->countRows('inventory');

        $this->opening->post([self::LOCATION_ID], null);

        $this->assertSame($shelves, $this->shelfRows());
        $this->assertSame($inventory, $this->countRows('inventory'));
    }

    public function testPost_DatesTheJournalAndTheMovementsOnTheAccountingStartDate(): void
    {
        $this->shelves($this->item('50.00'), '10');

        $result = $this->onlyResult($this->opening->post([self::LOCATION_ID], null));

        $this->assertSame(self::START, $this->accountingDb()->table('accounting_journals')->where('journal_id', $result->journalId)->get()->getRow()->journal_date);
        $this->assertSame(self::START, $this->openingEntries()[0]->entry_date);
    }

    public function testPost_PostsEachLocationInItsOwnJournalAndAccountWithItsOwnBranch(): void
    {
        [$branch, $store] = $this->secondBranchLocation();
        $rice             = $this->item('50.00');
        $oil              = $this->item('20.00');
        $this->shelves($rice, '10');
        $this->shelves($rice, '3', $store);
        $this->shelves($oil, '5', $store);

        $results = $this->opening->post([$store, self::LOCATION_ID], null);

        $this->assertSame([self::LOCATION_ID, $store], array_map(static fn (OpeningStockResult $result): int => $result->plan->locationId, $results));
        $this->assertCount(2, $this->openingJournals());
        $this->assertSame([$this->stockAccount() . ':500.0000:0.0000', $this->accountId('opening_balance') . ':0.0000:500.0000'], $this->linesOf($results[0]->journalId));
        $this->assertSame([$this->stockAccountOf($store) . ':250.0000:0.0000', $this->accountId('opening_balance') . ':0.0000:250.0000'], $this->linesOf($results[1]->journalId));
        $this->assertSame(1, (int) $this->openingJournals(self::LOCATION_ID)[0]->branch_id);
        $this->assertSame($branch, (int) $this->openingJournals($store)[0]->branch_id);
        $this->assertSame($branch, (int) $this->openingEntries($store)[0]->branch_id);
        $this->assertSame(['quantity' => '3.000', 'value' => '150.00'], $this->stockOf($rice, $store));
        $this->assertSame(['quantity' => '10.000', 'value' => '500.00'], $this->stockOf($rice));
        $this->assertStockTies();
    }

    public function testPost_KeepsTheMovementsOfGoodsThatCostNothingAndPostsNoJournal(): void
    {
        $free = $this->item('0.00');
        $this->shelves($free, '8');

        $result = $this->onlyResult($this->opening->post([self::LOCATION_ID], null));

        $this->assertSame(OpeningStockStatus::Posted, $result->plan->status);
        $this->assertNull($result->journalId);
        $this->assertSame([], $this->openingJournals());
        $this->assertSame(['quantity' => '8.000', 'value' => '0.00'], $this->stockOf($free));
        $this->assertNull($this->openingEntries()[0]->journal_id);
        $this->assertStockTies();

        $again = $this->onlyResult($this->opening->post([self::LOCATION_ID], null));

        $this->assertSame(OpeningStockStatus::AlreadyPosted, $again->plan->status);
        $this->assertCount(1, $this->openingEntries());
    }

    public function testPost_LeavesOutZeroStockAndItemsThatAreNotStock(): void
    {
        $this->shelves($this->item('50.00'), '0');
        $this->shelves($this->item('50.00', false), '6');

        $result = $this->onlyResult($this->opening->post([self::LOCATION_ID], null));

        $this->assertSame(OpeningStockStatus::NothingToPost, $result->plan->status);
        $this->assertSame([], $this->openingJournals());
        $this->assertSame([], $this->openingEntries());
    }

    public function testPost_ReportsNegativeStockAndLeavesItOutWhilePostingTheRest(): void
    {
        $owed = $this->item('3.00');
        $rice = $this->item('50.00');
        $this->shelves($owed, '-3');
        $this->shelves($rice, '10');

        $result = $this->onlyResult($this->opening->post([self::LOCATION_ID], null));

        $this->assertSame(OpeningStockStatus::Posted, $result->plan->status);
        $this->assertFindings([OpeningStockProblem::NegativeStock], $result->plan);
        $this->assertSame($owed, $result->plan->findings[0]->itemId);
        $this->assertSame('-3.000', $result->plan->findings[0]->amount);
        $this->assertSame(['quantity' => '0.000', 'value' => '0.00'], $this->stockOf($owed));
        $this->assertSame('-3.000', $this->onHand($owed));
        $this->assertCount(1, $this->openingEntries());
        $this->assertStockTies();
    }

    public function testPost_AloneNegativeStockIsNothingToPostAndIsReported(): void
    {
        $owed = $this->item('3.00');
        $this->shelves($owed, '-3');

        $result = $this->onlyResult($this->opening->post([self::LOCATION_ID], null));

        $this->assertSame(OpeningStockStatus::NothingToPost, $result->plan->status);
        $this->assertFindings([OpeningStockProblem::NegativeStock], $result->plan);
        $this->assertSame([], $this->openingEntries());
    }

    public function testPost_ReportsMovementsThatAddUpToMoreThanTheShelvesHoldAndLeavesThemAlone(): void
    {
        $item = $this->item('0.00');
        $this->assertGreaterThan(0, $this->buy([$this->purchaseLine($item, 0, '10', '50')]));
        $this->accountingDb()->table('item_quantities')->where('item_id', $item)->update(['quantity' => '7']);

        $result = $this->onlyResult($this->opening->post([self::LOCATION_ID], null));

        $this->assertSame(OpeningStockStatus::NothingToPost, $result->plan->status);
        $this->assertFindings([OpeningStockProblem::MovementsExceedShelves], $result->plan);
        $this->assertSame('3.000', $result->plan->findings[0]->amount);
        $this->assertSame([], $this->openingEntries());
        $this->assertSame('7.000', $this->onHand($item));
    }

    public function testPost_PostsOnlyTheUnitsNoMovementAccountsForAndNeverCountsAPostedPurchaseAgain(): void
    {
        $item = $this->item('0.00');
        $this->assertGreaterThan(0, $this->buy([$this->purchaseLine($item, 0, '10', '50')]));
        $this->accountingDb()->table('items')->where('item_id', $item)->update(['cost_price' => '50.00']);
        $this->accountingDb()->table('item_quantities')->where('item_id', $item)->update(['quantity' => '17']);

        $result = $this->onlyResult($this->opening->post([self::LOCATION_ID], null));

        $this->assertSame('7.000', $result->plan->units());
        $this->assertSame('350.0000', $result->plan->value());
        $this->assertSame(['quantity' => '17.000', 'value' => '850.00'], $this->stockOf($item));
        $this->assertSame('17.000', $this->onHand($item));
        $this->assertStockTies();
    }

    public function testPost_StockSoldAfterTheBooksBeganStillPostsTheUnitsThatWereThere(): void
    {
        $item = $this->item('60.00');
        $this->shelves($item, '10');

        $this->assertGreaterThan(0, $this->save([$this->line($item, 0, '3', '100', '60.00')]));
        $this->assertSame('7.000', $this->onHand($item));

        $result = $this->onlyResult($this->opening->post([self::LOCATION_ID], null));

        $this->assertSame('10.000', $result->plan->units());
        $this->assertSame(['quantity' => '7.000', 'value' => '420.00'], $this->stockOf($item));
        $this->assertSame('7.000', $this->onHand($item));
        $this->assertStockTies();
    }

    public function testPost_RunAgainPostsNothingAndLeavesEverythingAsItWas(): void
    {
        $this->shelves($this->item('50.00'), '10');

        $first = $this->onlyResult($this->opening->post([self::LOCATION_ID], null));
        $after = $this->counts();

        $second = $this->onlyResult($this->opening->post([self::LOCATION_ID], null));
        $third  = $this->onlyResult($this->opening->post([self::LOCATION_ID, self::LOCATION_ID], null));

        $this->assertSame(OpeningStockStatus::Posted, $first->plan->status);
        $this->assertSame(OpeningStockStatus::AlreadyPosted, $second->plan->status);
        $this->assertSame(OpeningStockStatus::AlreadyPosted, $third->plan->status);
        $this->assertNull($second->journalId);
        $this->assertSame($after, $this->counts());
        $this->assertSame('500.00', $this->balance($this->stockAccount()));
        $this->assertStockTies();
    }

    public function testPost_UnitsThatAppearAfterTheOpeningAreBlockedAndNeverAddedSilently(): void
    {
        $item = $this->item('50.00');
        $this->shelves($item, '10');
        $this->opening->post([self::LOCATION_ID], null);

        $this->accountingDb()->table('item_quantities')->where('item_id', $item)->update(['quantity' => '14']);
        $counts = $this->counts();

        $plan = $this->opening->plan(self::LOCATION_ID, null);

        $this->assertSame(OpeningStockStatus::Blocked, $plan->status);
        $this->assertFindings([OpeningStockProblem::PostedBefore], $plan);
        $this->assertSame('200.0000', $plan->findings[0]->amount);

        $result = $this->onlyResult($this->opening->post([self::LOCATION_ID], null));

        $this->assertSame(OpeningStockStatus::Blocked, $result->plan->status);
        $this->assertSame($counts, $this->counts());
        $this->assertSame('500.00', $this->balance($this->stockAccount()));
        $this->assertSame(['quantity' => '10.000', 'value' => '500.00'], $this->stockOf($item));
    }

    public function testPost_RefusesToCountTwiceWhatAJournalAlreadyPutOnTheAccount(): void
    {
        $this->shelves($this->item('50.00'), '10');
        (new PostingService())->post(new JournalDraft(
            date: self::START,
            lines: [new JournalLineDraft($this->stockAccount(), debit: '100.00'), new JournalLineDraft($this->accountId('opening_balance'), credit: '100.00')],
            sourceType: JournalDraft::SOURCE_MANUAL,
        ));
        $counts = $this->counts();

        $plan = $this->opening->plan(self::LOCATION_ID, null);

        $this->assertSame(OpeningStockStatus::Blocked, $plan->status);
        $this->assertFindings([OpeningStockProblem::AccountHoldsValue], $plan);
        $this->assertSame('100.0000', $plan->findings[0]->amount);

        $this->assertSame(OpeningStockStatus::Blocked, $this->onlyResult($this->opening->post([self::LOCATION_ID], null))->plan->status);
        $this->assertSame($counts, $this->counts());
        $this->assertSame([], $this->openingEntries());
    }

    public function testPost_BlocksALocationWhoseAccountIsMissingWithoutFailingTheRest(): void
    {
        $store = $this->anotherLocation('Bare store');
        $rice  = $this->item('50.00');
        $this->shelves($rice, '10');
        $this->shelves($rice, '4', $store);
        $this->accountingDb()->table('accounting_accounts')->where('entity_type', EntityAccountService::STOCK_LOCATION)->where('entity_id', $store)->delete();

        $results = $this->opening->post([self::LOCATION_ID, $store], null);

        $this->assertSame([OpeningStockStatus::Posted, OpeningStockStatus::Blocked], array_map(static fn (OpeningStockResult $result): OpeningStockStatus => $result->plan->status, $results));
        $this->assertFindings([OpeningStockProblem::AccountMissing], $results[1]->plan);
        $this->assertCount(1, $this->openingJournals());
    }

    public function testPlans_ListEveryLocationOfTheBranchThatIsInUseAndWriteNothing(): void
    {
        [, $store] = $this->secondBranchLocation('Elsewhere');
        $this->shelves($this->item('50.00'), '10');
        $this->shelves($this->item('50.00'), '2', $store);
        $gone = $this->anotherLocation('Closed store');
        $this->accountingDb()->table('stock_locations')->where('location_id', $gone)->update(['deleted' => 1]);
        $counts = $this->counts();

        $plans = $this->opening->plans(1, null);

        $this->assertContains(self::LOCATION_ID, array_map(static fn (OpeningStockPlan $plan): int => $plan->locationId, $plans));
        $this->assertNotContains($store, array_map(static fn (OpeningStockPlan $plan): int => $plan->locationId, $plans));
        $this->assertNotContains($gone, array_map(static fn (OpeningStockPlan $plan): int => $plan->locationId, $plans));
        $this->assertSame(OpeningStockStatus::Postable, $plans[array_search(self::LOCATION_ID, array_map(static fn (OpeningStockPlan $plan): int => $plan->locationId, $plans), true)]->status);
        $this->assertSame($counts, $this->counts());
    }

    public function testPlan_ShowsWhatTheOpeningStockThatStandsPostedAndNothingOnceItIsReversed(): void
    {
        $rice  = $this->item('50.00');
        $sugar = $this->item('12.50');
        $this->shelves($rice, '10');
        $this->shelves($sugar, '4');

        $before = $this->opening->plan(self::LOCATION_ID, null);

        $this->assertSame([], $before->standing);

        $this->opening->post([self::LOCATION_ID], null);
        $posted = $this->opening->plan(self::LOCATION_ID, null);

        $this->assertSame(OpeningStockStatus::AlreadyPosted, $posted->status);
        $this->assertSame([], $posted->lines);
        $this->assertSame([$rice, $sugar], array_map(static fn ($line): int => $line->itemId, $posted->standing));
        $this->assertSame(['500.0000', '50.0000'], array_map(static fn ($line): string => $line->value, $posted->standing));
        $this->assertSame('14.000', $posted->postedUnits());
        $this->assertSame('550.0000', $posted->postedValue());

        $this->opening->reverse(self::LOCATION_ID, self::START, 'Counted again', null);
        $reversed = $this->opening->plan(self::LOCATION_ID, null);

        $this->assertSame([], $reversed->standing);
        $this->assertSame('0', $reversed->postedValue());
        $this->assertSame('550.0000', $reversed->value());
    }

    public function testPost_AClosedPeriodOfTheSecondLocationUndoesTheFirstLocationToo(): void
    {
        [$branch, $store] = $this->secondBranchLocation();
        $rice             = $this->item('50.00');
        $this->shelves($rice, '10');
        $this->shelves($rice, '4', $store);
        $this->closeThrough(self::START, $branch);
        $counts = $this->counts();

        try {
            $this->opening->post([self::LOCATION_ID, $store], null);
            $this->fail('A closed period must refuse the opening stock.');
        } catch (PostingException $failure) {
            $this->assertSame(PostingError::PeriodClosed, $failure->error);
        }

        $this->assertSame([], $this->openingJournals());
        $this->assertSame([], $this->openingEntries());
        $this->assertSame($counts['journals'], $this->countRows('accounting_journals'));
        $this->assertSame(1, $this->accountingDb()->table('accounting_posting_failures')->where('source_type', OpeningStock::SOURCE)->where('error_code', 'period_closed')->countAllResults(), 'The refusal is written down after the rollback');
        $this->assertSame(OpeningStockStatus::Postable, $this->opening->plan(self::LOCATION_ID, null)->status);
        $this->assertStockTies();
    }

    public function testPost_ACancelledOpeningEquityAccountUndoesTheRunAndLeavesNothing(): void
    {
        $this->shelves($this->item('50.00'), '10');
        $this->accountingDb()->table('accounting_routing')->where('routing_key', 'equity.opening')->update(['account_id' => null]);

        try {
            $this->opening->post([self::LOCATION_ID], null);
            $this->fail('The opening balance equity account is needed.');
        } catch (PostingException $failure) {
            $this->assertSame(PostingError::RoutingAccountUnusable, $failure->error);
        }

        $this->assertSame([], $this->openingJournals());
        $this->assertSame([], $this->openingEntries());
        $this->assertSame(1, $this->accountingDb()->table('accounting_posting_failures')->where('source_type', OpeningStock::SOURCE)->where('error_code', PostingError::RoutingAccountUnusable->value)->countAllResults());
    }

    public function testPost_NeedsAccountingToBeOnAndAStartDate(): void
    {
        $this->shelves($this->item('50.00'), '10');

        $this->setConfigValue('accounting_start_date', '');
        $this->assertRefusedWith(AccountingError::OpeningStartDateMissing, fn () => $this->opening->post([self::LOCATION_ID], null));

        $this->setConfigValue('accounting_start_date', self::START);
        $this->setConfigValue('accounting_enabled', '0');
        $this->assertRefusedWith(AccountingError::OpeningStockAccountingOff, fn () => $this->opening->post([self::LOCATION_ID], null));

        $this->assertSame([], $this->openingEntries());
    }

    public function testPost_RefusesALocationThatDoesNotExistOrIsNotInUse(): void
    {
        $gone = $this->anotherLocation('Closed store');
        $this->accountingDb()->table('stock_locations')->where('location_id', $gone)->update(['deleted' => 1]);

        $this->assertRefusedWith(AccountingError::OpeningStockLocationNotFound, fn () => $this->opening->post([self::LOCATION_ID, 9999999], null));
        $this->assertRefusedWith(AccountingError::OpeningStockLocationNotFound, fn () => $this->opening->post([$gone], null));
        $this->assertSame([], $this->opening->post([], null));
    }

    public function testPost_NeedsThePermissionToManageTheOpeningBalances(): void
    {
        $this->shelves($this->item('50.00'), '10');
        $clerk = $this->employeeWithGrants(['accounting_periods']);

        $this->assertRefusedWith(AccountingError::PermissionDenied, fn () => $this->opening->post([self::LOCATION_ID], $clerk));
        $this->assertRefusedWith(AccountingError::PermissionDenied, fn () => $this->opening->reverse(self::LOCATION_ID, self::START, 'why', $clerk));
        $this->assertSame([], $this->openingEntries());

        $manager = $this->employeeWithGrants(['accounting_periods', AccountingGuard::OPENING_MANAGE]);

        $this->assertSame(OpeningStockStatus::Posted, $this->onlyResult($this->opening->post([self::LOCATION_ID], $manager))->plan->status);
        $this->assertSame($manager, (int) $this->openingJournals()[0]->created_by);
        $this->assertSame('user', $this->openingJournals()[0]->origin);
    }

    public function testPost_RefusesALocationOfABranchTheEmployeeDoesNotWorkInAndPostsNothing(): void
    {
        [$branch, $store] = $this->secondBranchLocation();
        $rice             = $this->item('50.00');
        $this->shelves($rice, '10');
        $this->shelves($rice, '4', $store);
        $manager = $this->employeeWithGrants(['accounting_periods', AccountingGuard::OPENING_MANAGE], [1]);

        try {
            $this->opening->post([self::LOCATION_ID, $store], $manager);
            $this->fail('A branch outside the scope of the employee must be refused.');
        } catch (BranchException $failure) {
            $this->assertSame(BranchError::NotAllowed, $failure->error);
        }

        $this->assertSame([], $this->openingJournals());
        $this->assertSame([], $this->openingEntries());

        $this->assertSame(OpeningStockStatus::Posted, $this->onlyResult($this->opening->post([self::LOCATION_ID], $manager))->plan->status);

        try {
            $this->opening->plans($branch, $manager);
            $this->fail('The plans of another branch must be refused.');
        } catch (BranchException $failure) {
            $this->assertSame(BranchError::NotAllowed, $failure->error);
        }
    }

    public function testPost_WritesTheAuditTrailOfEachPostedLocation(): void
    {
        $this->shelves($this->item('50.00'), '10');

        $this->opening->post([self::LOCATION_ID], null);

        $row = $this->accountingDb()->table('accounting_audit_log')->where('entity_type', 'opening_stock')->get()->getRow();

        $this->assertSame('opening_stock_posted', $row->action);
        $this->assertSame((string) self::LOCATION_ID, (string) $row->entity_id);
        $this->assertSame(500.0, (float) json_decode($row->details, true)['value']);
    }

    public function testReverse_TakesTheJournalAndTheMovementsBackAndKeepsTheShelves(): void
    {
        $item = $this->item('50.00');
        $this->shelves($item, '10');
        $posted = $this->onlyResult($this->opening->post([self::LOCATION_ID], null));
        $shelves = $this->shelfRows();

        $this->opening->reverse(self::LOCATION_ID, self::START, 'Counted again', null);

        $this->assertSame('reversed', $this->accountingDb()->table('accounting_journals')->where('journal_id', $posted->journalId)->get()->getRow()->status);
        $this->assertSame('0.00', $this->balance($this->stockAccount()));
        $this->assertSame(['quantity' => '0.000', 'value' => '0.00'], $this->stockOf($item));
        $this->assertSame(['posting', 'reversal'], array_map(static fn (object $row): string => $row->kind, $this->openingEntries()));
        $this->assertSame($shelves, $this->shelfRows());
        $this->assertSame(OpeningStockStatus::Postable, $this->opening->plan(self::LOCATION_ID, null)->status);
        $this->assertStockTies();
        $this->assertSame('opening_stock_reversed', $this->accountingDb()->table('accounting_audit_log')->where('entity_type', 'opening_stock')->orderBy('audit_id', 'DESC')->get()->getRow()->action);
    }

    public function testReverse_RequiresAReasonAValidDateAndPostedOpeningStock(): void
    {
        $this->shelves($this->item('50.00'), '10');

        $this->assertRefusedWith(AccountingError::OpeningStockNotPosted, fn () => $this->opening->reverse(self::LOCATION_ID, self::START, 'Because', null));

        $this->opening->post([self::LOCATION_ID], null);
        $counts = $this->counts();

        $this->assertRefusedWith(AccountingError::ReasonRequired, fn () => $this->opening->reverse(self::LOCATION_ID, self::START, '  ', null));
        $this->assertRefusedWith(AccountingError::InvalidDate, fn () => $this->opening->reverse(self::LOCATION_ID, '2020-13-45', 'Because', null));
        $this->assertSame($counts, $this->counts());
    }

    public function testReverse_AClosedPeriodRefusesItAndLeavesTheOpeningStanding(): void
    {
        $this->shelves($this->item('50.00'), '10');
        $this->opening->post([self::LOCATION_ID], null);
        $this->closeThrough(self::START);
        $counts = $this->counts();

        try {
            $this->opening->reverse(self::LOCATION_ID, self::START, 'Because', null);
            $this->fail('A closed period must refuse the reversal.');
        } catch (PostingException $failure) {
            $this->assertSame(PostingError::PeriodClosed, $failure->error);
        }

        $this->assertSame($counts['journals'], $this->countRows('accounting_journals'));
        $this->assertSame($counts['entries'], $this->countRows('accounting_stock_entries'));
        $this->assertSame('500.00', $this->balance($this->stockAccount()));
    }

    public function testReverse_ThenPostingAgainPostsANewRevisionOnceAndTheOldOneIsNotDeleted(): void
    {
        $item = $this->item('50.00');
        $this->shelves($item, '10');
        $first = $this->onlyResult($this->opening->post([self::LOCATION_ID], null));
        $this->opening->reverse(self::LOCATION_ID, self::START, 'Counted again', null);
        $this->accountingDb()->table('items')->where('item_id', $item)->update(['cost_price' => '55.00']);

        $second = $this->onlyResult($this->opening->post([self::LOCATION_ID], null));
        $third  = $this->onlyResult($this->opening->post([self::LOCATION_ID], null));

        $this->assertSame(2, $second->revision);
        $this->assertSame(OpeningStockStatus::AlreadyPosted, $third->plan->status);
        $this->assertSame([$first->journalId, $second->journalId], array_map(static fn (object $journal): int => (int) $journal->journal_id, $this->openingJournals()));
        $this->assertSame(['reversed', 'posted'], array_map(static fn (object $journal): string => $journal->status, $this->openingJournals()));
        $this->assertSame('550.00', $this->balance($this->stockAccount()));
        $this->assertSame(['quantity' => '10.000', 'value' => '550.00'], $this->stockOf($item));
        $this->assertStockTies();
    }

    public function testReverse_OpeningStockOfGoodsThatCostNothingIsTakenBackThroughItsMovementsAlone(): void
    {
        $free = $this->item('0.00');
        $this->shelves($free, '8');
        $this->opening->post([self::LOCATION_ID], null);

        $this->opening->reverse(self::LOCATION_ID, self::START, 'Counted again', null);

        $this->assertSame(['quantity' => '0.000', 'value' => '0.00'], $this->stockOf($free));
        $this->assertSame(OpeningStockStatus::Postable, $this->opening->plan(self::LOCATION_ID, null)->status);
    }

    public function testPost_TheOpeningStockLetsLaterPurchasesAndReturnsAverageOverTheWholeOfTheStock(): void
    {
        $item = $this->item('50.00');
        $this->shelves($item, '10');
        $this->opening->post([self::LOCATION_ID], null);

        $purchase = $this->buy([$this->purchaseLine($item, 0, '10', '70')]);

        $this->assertGreaterThan(0, $purchase);
        $this->assertSame('60.0000', $this->itemCostOf($item));
        $this->assertSame(['quantity' => '20.000', 'value' => '1200.00'], $this->stockOf($item));
        $this->assertSame('60.00000000', (new StockLedger())->unitCost($item, self::LOCATION_ID, '60.00'));
        $this->assertStockTies();
    }

    /**
     * Another request that holds the row of the stock location, which is what a request posting
     * its opening stock holds until it finishes.
     */
    private function holdTheLocation(int $locationId): BaseConnection
    {
        $other = $this->openSecondConnection(false);
        $other->resetTransStatus();
        $other->transStart();
        $other->query('SELECT location_id FROM ' . $other->prefixTable('stock_locations') . ' WHERE location_id = ? FOR UPDATE', [$locationId]);

        return $other;
    }

    public function testPost_WaitsForARequestThatHoldsTheLocationAndPostsNothingWhenItCannotHaveIt(): void
    {
        $this->shelves($this->item('50.00'), '10');
        $other = $this->holdTheLocation(self::LOCATION_ID);
        $this->accountingDb()->query('SET SESSION innodb_lock_wait_timeout = 1');

        try {
            $this->assertWaitsForLock(fn () => $this->opening->post([self::LOCATION_ID], null));
        } finally {
            $other->transRollback();
            $other->close();
            $this->accountingDb()->query('SET SESSION innodb_lock_wait_timeout = 50');
        }

        $this->assertSame([], $this->openingJournals());
        $this->assertSame([], $this->openingEntries());
        $this->assertSame(OpeningStockStatus::Posted, $this->onlyResult($this->opening->post([self::LOCATION_ID], null))->plan->status);
    }

    public function testReverse_WaitsForARequestThatHoldsTheLocationAndTakesNothingBackWhenItCannotHaveIt(): void
    {
        $this->shelves($this->item('50.00'), '10');
        $this->opening->post([self::LOCATION_ID], null);
        $counts = $this->counts();
        $other  = $this->holdTheLocation(self::LOCATION_ID);
        $this->accountingDb()->query('SET SESSION innodb_lock_wait_timeout = 1');

        try {
            $this->assertWaitsForLock(fn () => $this->opening->reverse(self::LOCATION_ID, self::START, 'Because', null));
        } finally {
            $other->transRollback();
            $other->close();
            $this->accountingDb()->query('SET SESSION innodb_lock_wait_timeout = 50');
        }

        $this->assertSame($counts['journals'], $this->countRows('accounting_journals'));
        $this->assertSame($counts['entries'], $this->countRows('accounting_stock_entries'));
        $this->assertSame('500.00', $this->balance($this->stockAccount()));
    }

    /**
     * A request that posts the opening stock of a location in another process while this one holds
     * the location and has not finished. It is started and left to wait.
     *
     * @return array{process: resource, pipes: array<int, resource>, files: list<string>}
     */
    private function startOpeningStockRequest(string $method, string ...$arguments): array
    {
        $ready = tempnam(sys_get_temp_dir(), 'opening_ready_');
        $go    = tempnam(sys_get_temp_dir(), 'opening_go_');
        unlink($ready);
        unlink($go);

        $environment = array_merge(array_filter($_SERVER, static fn ($value): bool => is_scalar($value)), ['RACE_READY_FILE' => $ready, 'RACE_GO_FILE' => $go]);
        $process     = proc_open([PHP_BINARY, dirname(__DIR__, 2) . '/Support/RaceWorker.php', $method, ...$arguments], [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, ROOTPATH, $environment);

        $this->assertIsResource($process);
        fclose($pipes[0]);

        $deadline = microtime(true) + 10.0;

        while (! file_exists($ready) && microtime(true) < $deadline) {
            usleep(1000);
        }

        $this->assertFileExists($ready, 'The request did not start');
        file_put_contents($go, '1');
        usleep(800000);

        return ['process' => $process, 'pipes' => $pipes, 'files' => [$ready, $go]];
    }

    /**
     * @param array{process: resource, pipes: array<int, resource>, files: list<string>} $request
     *
     * @return array{exit: int, output: string, error: string}
     */
    private function finishOpeningStockRequest(array $request): array
    {
        $output   = '';
        $error    = '';
        $deadline = microtime(true) + 20.0;

        stream_set_blocking($request['pipes'][1], false);
        stream_set_blocking($request['pipes'][2], false);

        do {
            $output .= stream_get_contents($request['pipes'][1]);
            $error  .= stream_get_contents($request['pipes'][2]);
            $status  = proc_get_status($request['process']);
            usleep(5000);
        } while ($status['running'] && microtime(true) < $deadline);

        $output .= stream_get_contents($request['pipes'][1]);
        $error  .= stream_get_contents($request['pipes'][2]);
        $exit    = $status['running'] ? -1 : $status['exitcode'];

        if ($status['running']) {
            proc_terminate($request['process']);
        }

        fclose($request['pipes'][1]);
        fclose($request['pipes'][2]);
        proc_close($request['process']);

        foreach ($request['files'] as $file) {
            if (file_exists($file)) {
                unlink($file);
            }
        }

        return ['exit' => $exit, 'output' => trim($output), 'error' => $error];
    }

    public function testPost_ARequestThatWaitsForTheLocationFindsTheOpeningStockPostedAndPostsNothingMore(): void
    {
        $this->shelves($this->item('50.00'), '10');
        $first = $this->openSecondConnection(false);
        $first->resetTransStatus();
        $first->transStart();

        $posted = (new OpeningStock($first))->post([self::LOCATION_ID], null);

        $this->assertSame(OpeningStockStatus::Posted, $this->onlyResult($posted)->plan->status);

        $second = $this->startOpeningStockRequest('openingStock', (string) self::LOCATION_ID);

        $first->transComplete();
        $first->close();

        $outcome = $this->finishOpeningStockRequest($second);

        $this->assertSame(0, $outcome['exit'], 'The second request must find the first one posted and not fail: ' . $outcome['error']);
        $this->assertSame('0', $outcome['output'], 'The second request posted nothing');
        $this->assertCount(1, $this->openingJournals());
        $this->assertCount(1, $this->openingEntries());
        $this->assertSame('500.00', $this->balance($this->stockAccount()));
        $this->assertStockTies();
    }

    public function testPost_TwoRequestsAtTheSameMomentPostTheLocationOnce(): void
    {
        $this->shelves($this->item('50.00'), '10');
        $this->shelves($this->item('12.50'), '4');

        [$first, $second] = $this->raceTwoProcesses('openingStock', [(string) self::LOCATION_ID], [(string) self::LOCATION_ID]);

        $this->assertSame(1, (int) $first + (int) $second, 'Of two runs at the same moment, one posts and the other finds it posted');
        $this->assertCount(1, $this->openingJournals());
        $this->assertCount(2, $this->openingEntries());
        $this->assertSame('550.00', $this->balance($this->stockAccount()));
        $this->assertStockTies();
    }

    public function testReverse_TwoRequestsAtTheSameMomentTakeTheOpeningStockBackOnce(): void
    {
        $this->shelves($this->item('50.00'), '10');
        $this->opening->post([self::LOCATION_ID], null);

        [$first, $second] = $this->raceTwoProcesses('reverseOpeningStock', [(string) self::LOCATION_ID, self::START], [(string) self::LOCATION_ID, self::START]);

        $this->assertSame(1, (int) $first + (int) $second);
        $this->assertSame(1, $this->accountingDb()->table('accounting_journals')->where('source_type', 'reversal')->countAllResults());
        $this->assertSame(['posting', 'reversal'], array_map(static fn (object $row): string => $row->kind, $this->openingEntries()));
        $this->assertSame('0.00', $this->balance($this->stockAccount()));
    }
}
