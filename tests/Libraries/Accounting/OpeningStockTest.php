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
use App\Libraries\Accounting\StockAdjustment;
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

        $this->assertSame(OpeningStockStatus::PartiallyPosted, $result->plan->status);
        $this->assertFindings([OpeningStockProblem::NegativeStock], $result->plan);
        $this->assertSame($owed, $result->plan->findings[0]->itemId);
        $this->assertSame('-3.000', $result->plan->findings[0]->amount);
        $this->assertSame(['quantity' => '0.000', 'value' => '0.00'], $this->stockOf($owed));
        $this->assertSame('-3.000', $this->onHand($owed));
        $this->assertCount(1, $this->openingEntries());
        $this->assertStockTies();
    }

    public function testPost_AloneNegativeStockNeedsAPersonAndIsNeverShownAsNothingToPost(): void
    {
        $owed = $this->item('3.00');
        $this->shelves($owed, '-3');

        $result = $this->onlyResult($this->opening->post([self::LOCATION_ID], null));

        $this->assertSame(OpeningStockStatus::Blocked, $result->plan->status);
        $this->assertFalse($result->written());
        $this->assertFindings([OpeningStockProblem::NegativeStock], $result->plan);
        $this->assertSame([], $this->openingEntries());
    }

    public function testPost_ReportsMovementsThatAddUpToMoreThanTheShelvesHoldAndLeavesThemAlone(): void
    {
        $item = $this->item('0.00');
        $this->assertGreaterThan(0, $this->buy([$this->purchaseLine($item, 0, '10', '50')]));
        $this->accountingDb()->table('item_quantities')->where('item_id', $item)->update(['quantity' => '7']);

        $result = $this->onlyResult($this->opening->post([self::LOCATION_ID], null));

        $this->assertSame(OpeningStockStatus::Blocked, $result->plan->status);
        $this->assertFindings([OpeningStockProblem::MovementsExceedShelves], $result->plan);
        $this->assertSame('3.000', $result->plan->findings[0]->amount);
        $this->assertSame([], $this->openingEntries());
        $this->assertSame('7.000', $this->onHand($item));
    }

    /**
     * The location of the case: rice that can be posted, an item whose shelves are negative, and
     * an item bought for ten whose shelves hold seven.
     *
     * @return array{rice: int, owed: int, short: int}
     */
    private function locationWithItemsLeftOut(): array
    {
        $rice  = $this->item('50.00');
        $owed  = $this->item('3.00');
        $short = $this->item('0.00');

        $this->shelves($rice, '10');
        $this->shelves($owed, '-3');
        $this->assertGreaterThan(0, $this->buy([$this->purchaseLine($short, 0, '10', '20')]));
        $this->accountingDb()->table('item_quantities')->where('item_id', $short)->where('location_id', self::LOCATION_ID)->update(['quantity' => '7']);

        return ['rice' => $rice, 'owed' => $owed, 'short' => $short];
    }

    /**
     * Every journal, journal line and stock movement as it stands, keyed by its id, with the
     * shelves and the size of the inventory log.
     *
     * @return array{journals: array<int, array<string, mixed>>, lines: array<int, array<string, mixed>>, entries: array<int, array<string, mixed>>, shelves: array<string, string>, log: int}
     */
    private function history(): array
    {
        return [
            'journals' => array_column($this->accountingDb()->table('accounting_journals')->get()->getResultArray(), null, 'journal_id'),
            'lines'    => array_column($this->accountingDb()->table('accounting_journal_lines')->get()->getResultArray(), null, 'line_id'),
            'entries'  => array_column($this->accountingDb()->table('accounting_stock_entries')->get()->getResultArray(), null, 'entry_id'),
            'shelves'  => $this->shelfRows(),
            'log'      => $this->countRows('inventory'),
        ];
    }

    /**
     * Nothing that stood before is gone or different, except that the journals named may have
     * moved from posted to reversed, which is the whole of what reversing does to a journal.
     *
     * @param array{journals: array<int, array<string, mixed>>, lines: array<int, array<string, mixed>>, entries: array<int, array<string, mixed>>, shelves: array<string, string>, log: int} $before
     * @param list<int>                                                                                                                                                                      $reversed
     */
    private function assertHistoryKept(array $before, array $after, array $reversed = []): void
    {
        foreach ($before['journals'] as $id => $journal) {
            $this->assertArrayHasKey($id, $after['journals'], "Journal {$id} was deleted");

            $now = $after['journals'][$id];

            if (in_array($id, $reversed, true)) {
                $this->assertSame(['status' => 'posted'], ['status' => $journal['status']]);
                $this->assertSame('reversed', $now['status'], "Journal {$id} is marked reversed");
                unset($journal['status'], $now['status'], $journal['updated_at'], $now['updated_at']);
            }

            $this->assertSame($journal, $now, "Journal {$id} changed");
        }

        foreach ($before['lines'] as $id => $line) {
            $this->assertSame($line, $after['lines'][$id] ?? null, "Journal line {$id} changed or was deleted");
        }

        foreach ($before['entries'] as $id => $entry) {
            $this->assertSame($entry, $after['entries'][$id] ?? null, "Stock movement {$id} changed or was deleted");
        }

        $this->assertSame($before['shelves'], $after['shelves'], 'The shelves are not touched');
        $this->assertSame($before['log'], $after['log'], 'The inventory log is not touched');
    }

    public function testPlan_ALocationPostedWithItemsLeftOutIsNeverShownAsDoneAndKeepsListingThem(): void
    {
        $items = $this->locationWithItemsLeftOut();

        $result = $this->onlyResult($this->opening->post([self::LOCATION_ID], null));

        $this->assertSame(OpeningStockStatus::PartiallyPosted, $result->plan->status);
        $this->assertTrue($result->written());

        foreach ([$this->opening->plan(self::LOCATION_ID, null), $this->opening->plans(1, null)[0]] as $plan) {
            $this->assertSame(OpeningStockStatus::PartiallyPosted, $plan->status, 'Not Posted and not AlreadyPosted while an item is left out');
            $this->assertSame(1, $plan->activeRevision);
            $this->assertFindings([OpeningStockProblem::NegativeStock, OpeningStockProblem::MovementsExceedShelves], $plan);
            $this->assertSame([$items['owed'], $items['short']], array_map(static fn ($finding): ?int => $finding->itemId, $plan->findings));
            $this->assertSame(['-3.000', '3.000'], array_map(static fn ($finding): string => $finding->amount, $plan->findings));
            $this->assertSame('500.0000', $plan->postedValue(), 'What was posted stays visible');
            $this->assertSame(['-9.0000', '-60.0000'], array_map(static fn ($line): string => $line->value, $plan->differences), 'What is still left out, at the cost of each item');
        }

        $this->assertFalse($this->opening->plan(self::LOCATION_ID, null)->canSettleDifferences(), 'The opening that stands is reversed first');
        $this->assertSame('700.00', $this->balance($this->stockAccount()), '500 of opening stock and the 200 of the purchase');
    }

    public function testPlan_ALocationWithItemsLeftOutStaysPartiallyPostedWhenPostedAgainAndPostsNothingMore(): void
    {
        $this->locationWithItemsLeftOut();
        $this->opening->post([self::LOCATION_ID], null);
        $counts = $this->counts();

        $again = $this->onlyResult($this->opening->post([self::LOCATION_ID], null));

        $this->assertFalse($again->written());
        $this->assertSame(OpeningStockStatus::PartiallyPosted, $again->plan->status);
        $this->assertSame($counts, $this->counts());
        $this->assertCount(1, $this->openingJournals());
    }

    public function testPlan_ACountAdjustmentDoesNotCureAnItemLeftOutBecauseItMovesTheShelvesAndTheMovementsTogether(): void
    {
        $owed = $this->item('3.00');
        $this->shelves($owed, '-3');
        $before = $this->opening->plan(self::LOCATION_ID, null);

        $this->accountingDb()->table('item_quantities')->where('item_id', $owed)->update(['quantity' => '0']);
        (new StockAdjustment())->post($owed, self::LOCATION_ID, '3.000', null, 900001);
        $after = $this->opening->plan(self::LOCATION_ID, null);

        $this->assertSame(['-3.000'], array_map(static fn ($line): string => $line->units, $before->differences));
        $this->assertSame(['-3.000'], array_map(static fn ($line): string => $line->units, $after->differences), 'The difference the books have to take in has not moved');
        $this->assertSame(OpeningStockStatus::Blocked, $after->status);
        $this->assertFindings([OpeningStockProblem::MovementsExceedShelves], $after);
    }

    public function testPost_SettlingTheDifferencesPostsTheItemsLeftOutAsNegativeLinesWithAReasonAndCountsNothingTwice(): void
    {
        $items = $this->locationWithItemsLeftOut();
        $this->assertRefusedWith(AccountingError::ReasonRequired, fn () => $this->opening->post([self::LOCATION_ID], null, true, '  '));
        $this->assertSame([], $this->openingJournals());
        $shelves = $this->shelfRows();

        $result = $this->onlyResult($this->opening->post([self::LOCATION_ID], null, true, 'Counted: the shelves are right'));

        $this->assertSame(OpeningStockStatus::Posted, $result->plan->status);
        $this->assertSame([$this->stockAccount() . ':431.0000:0.0000', $this->accountId('opening_balance') . ':0.0000:431.0000'], $this->linesOf($result->journalId), '500 for the rice less 9 and 60 for the two items left out');

        $entries = $this->openingEntries();

        $this->assertSame([$items['rice'], $items['owed'], $items['short']], array_map(static fn (object $entry): int => (int) $entry->item_id, $entries));
        $this->assertSame(['10.000', '-3.000', '-3.000'], array_map(static fn (object $entry): string => $entry->quantity, $entries));
        $this->assertSame(['500.0000', '-9.0000', '-60.0000'], array_map(static fn (object $entry): string => $entry->value, $entries));
        $this->assertSame($shelves, $this->shelfRows(), 'The shelves are not touched');
        $this->assertSame('-3.000', $this->onHand($items['owed']));
        $this->assertStockTies();

        $plan = $this->opening->plan(self::LOCATION_ID, null);

        $this->assertSame(OpeningStockStatus::AlreadyPosted, $plan->status);
        $this->assertSame([], $plan->findings);
        $this->assertSame([], $plan->differences);
        $this->assertSame('431.0000', $plan->postedValue());

        $audit = json_decode($this->accountingDb()->table('accounting_audit_log')->where('entity_type', 'opening_stock')->get()->getRow()->details, true);

        $this->assertSame([true, 'Counted: the shelves are right'], [$audit['differences_settled'], $audit['reason']]);

        $counts = $this->counts();
        $this->assertFalse($this->onlyResult($this->opening->post([self::LOCATION_ID], null, true, 'Again'))->written());
        $this->assertSame($counts, $this->counts());
    }

    public function testPost_SettlingTheDifferencesOfALocationWithNothingElseToPostPostsTheirNegativeValueAgainstTheEquity(): void
    {
        $this->shelves($this->item('3.00'), '-3');

        $result = $this->onlyResult($this->opening->post([self::LOCATION_ID], null, true, 'Sold before the books began'));

        $this->assertSame(OpeningStockStatus::Posted, $result->plan->status);
        $this->assertSame([$this->accountId('opening_balance') . ':9.0000:0.0000', $this->stockAccount() . ':0.0000:9.0000'], $this->linesOf($result->journalId));
        $this->assertSame('-9.00', $this->balance($this->stockAccount()));
        $this->assertStockTies();
    }

    public function testPost_TheWayToTakeInTheDifferencesOfALocationPostedWithoutThemIsToReverseItAndPostItAgainSettled(): void
    {
        $items = $this->locationWithItemsLeftOut();
        $first = $this->onlyResult($this->opening->post([self::LOCATION_ID], null));

        $refused = $this->onlyResult($this->opening->post([self::LOCATION_ID], null, true, 'Without reversing first'));

        $this->assertFalse($refused->written(), 'The opening that stands is not added to');
        $this->assertSame(OpeningStockStatus::PartiallyPosted, $refused->plan->status);

        $this->opening->reverse(self::LOCATION_ID, self::START, 'Taking in the items left out', null);
        $this->assertSame('200.00', $this->balance($this->stockAccount()), 'The reversal takes back the 500 the first posting put and leaves the purchase');

        $second = $this->onlyResult($this->opening->post([self::LOCATION_ID], null, true, 'Counted: the shelves are right'));

        $this->assertSame(2, $second->revision);
        $this->assertSame(OpeningStockStatus::Posted, $second->plan->status);
        $this->assertSame('631.00', $this->balance($this->stockAccount()), 'The purchase of 200 and 431 of opening stock: the rice is counted once, not twice');
        $this->assertSame(['reversed', 'posted'], array_map(static fn (object $journal): string => $journal->status, $this->openingJournals()));
        $this->assertSame([$first->journalId, $second->journalId], array_map(static fn (object $journal): int => (int) $journal->journal_id, $this->openingJournals()), 'The first opening is not deleted');
        $this->assertSame(['quantity' => '10.000', 'value' => '500.00'], $this->stockOf($items['rice']));
        $this->assertSame(['quantity' => '-3.000', 'value' => '-9.00'], $this->stockOf($items['owed']));
        $this->assertStockTies();
        $this->assertSame(OpeningStockStatus::AlreadyPosted, $this->opening->plan(self::LOCATION_ID, null)->status);
    }

    public function testPost_SettlingTheDifferencesLeavesOutALocationWhoseAccountHoldsValueThatNoMovementAccountsFor(): void
    {
        $this->shelves($this->item('50.00'), '10');
        $this->shelves($this->item('3.00'), '-3');
        (new PostingService())->post(new JournalDraft(
            date: self::START,
            lines: [new JournalLineDraft($this->stockAccount(), debit: '100.00'), new JournalLineDraft($this->accountId('opening_balance'), credit: '100.00')],
            sourceType: JournalDraft::SOURCE_MANUAL,
        ));
        $counts = $this->counts();

        $result = $this->onlyResult($this->opening->post([self::LOCATION_ID], null, true, 'Counted'));

        $this->assertSame(OpeningStockStatus::Blocked, $result->plan->status);
        $this->assertFalse($result->plan->canSettleDifferences());
        $this->assertSame($counts, $this->counts());
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
        $this->assertSame('500.00', $this->balance($this->stockAccount()), 'A cost edited after the reversal is not taken: the units go back at the cost they were posted at');
        $this->assertSame(['quantity' => '10.000', 'value' => '500.00'], $this->stockOf($item));
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

    public function testReverse_ThenSettlingAPartiallyPostedLocationKeepsEveryEarlierJournalAndMovementAndCountsNothingTwice(): void
    {
        $items = $this->locationWithItemsLeftOut();
        $first = $this->onlyResult($this->opening->post([self::LOCATION_ID], null));

        $this->assertGreaterThan(0, $this->buy([$this->purchaseLine($items['rice'], 0, '10', '50')]));
        $this->assertGreaterThan(0, $this->save([$this->line($items['rice'], 0, '3', '100', '50.00')]));
        $this->assertSame('17.000', $this->onHand($items['rice']));
        $this->assertStockTies();

        $before = $this->history();
        $stock  = $this->balance($this->stockAccount());
        $equity = $this->balance($this->accountId('opening_balance'));
        $rice   = $this->stockOf($items['rice']);

        $this->opening->reverse(self::LOCATION_ID, self::START, 'Taking in the items left out', null);

        $plan = $this->opening->plan(self::LOCATION_ID, null);

        $this->assertSame(['10.000'], array_map(static fn ($line): string => $line->units, $plan->lines), 'Only the units no movement accounts for: not the purchase and not the sale');
        $this->assertSame(['-3.000', '-3.000'], array_map(static fn ($line): string => $line->units, $plan->differences));

        $second = $this->onlyResult($this->opening->post([self::LOCATION_ID], null, true, 'Counted: the shelves are right'));
        $after  = $this->history();

        $this->assertSame(2, $second->revision);
        $this->assertSame(OpeningStockStatus::Posted, $second->plan->status);
        $this->assertHistoryKept($before, $after, [$first->journalId]);

        $newJournals = array_diff_key($after['journals'], $before['journals']);
        $newEntries  = array_diff_key($after['entries'], $before['entries']);

        $this->assertCount(2, $newJournals, 'The reversal and the second opening, nothing else');
        $this->assertSame([$first->journalId], array_values(array_filter(array_map(static fn (array $journal): ?int => $journal['reverses_journal_id'] === null ? null : (int) $journal['reverses_journal_id'], $newJournals))));
        $this->assertSame([OpeningStock::SOURCE], array_values(array_unique(array_column($newEntries, 'source_type'))), 'No purchase or sale movement was taken back or written again');
        $this->assertSame(['reversal:1:1', 'posting:2:3'], array_map(
            static fn (string $key): string => $key,
            [
                'reversal:1:' . count(array_filter($newEntries, static fn (array $entry): bool => $entry['kind'] === 'reversal' && (int) $entry['revision'] === 1)),
                'posting:2:' . count(array_filter($newEntries, static fn (array $entry): bool => $entry['kind'] === 'posting' && (int) $entry['revision'] === 2)),
            ],
        ));

        $this->assertSame(bcsub($stock, '69.00', 2), $this->balance($this->stockAccount()), 'Only the two items left out are added: 9 and 60 less');
        $this->assertSame(bcadd($equity, '69.00', 2), $this->balance($this->accountId('opening_balance')), 'The equity gives up the same 69 and no more');
        $this->assertSame($rice, $this->stockOf($items['rice']), 'The rice is counted once: the purchase and the sale stand and the opening is back as it was');
        $this->assertSame('17.000', $this->onHand($items['rice']));
        $this->assertStockTies();
        $this->assertSame(OpeningStockStatus::AlreadyPosted, $this->opening->plan(self::LOCATION_ID, null)->status);
    }

    public function testReverse_ThenPostingAgainKeepsTheCostTheOpeningWasPostedAtWhenLaterPurchasesMovedTheCostOfTheItem(): void
    {
        $item = $this->item('50.00');
        $this->shelves($item, '10');
        $first = $this->onlyResult($this->opening->post([self::LOCATION_ID], null));

        $this->assertGreaterThan(0, $this->buy([$this->purchaseLine($item, 0, '10', '70')]));
        $this->assertSame('60.0000', $this->itemCostOf($item), 'The cost the item carries has moved from 50 to 60');
        $this->assertSame('1200.00', $this->stockOf($item)['value']);
        $before = $this->history();

        $this->opening->reverse(self::LOCATION_ID, self::START, 'Posting again', null);
        $plan = $this->opening->plan(self::LOCATION_ID, null);

        $this->assertSame(['500.0000'], array_map(static fn ($line): string => $line->value, $plan->lines), 'The units are back at the cost they were posted at, not at the cost the later purchase made');

        $second = $this->onlyResult($this->opening->post([self::LOCATION_ID], null));
        $after  = $this->history();

        $this->assertSame(2, $second->revision);
        $this->assertHistoryKept($before, $after, [$first->journalId]);
        $this->assertSame([$this->stockAccount() . ':500.0000:0.0000', $this->accountId('opening_balance') . ':0.0000:500.0000'], $this->linesOf($second->journalId));
        $this->assertSame(['quantity' => '20.000', 'value' => '1200.00'], $this->stockOf($item), 'What the stock is worth has not moved by posting the opening again');
        $this->assertSame('1200.00', $this->balance($this->stockAccount()));
        $this->assertSame('-500.00', $this->balance($this->accountId('opening_balance')));
        $this->assertSame('60.0000', $this->itemCostOf($item), 'Posting does not touch the cost of the item');
        $this->assertStockTies();
    }

    public function testReverse_ThenPostingAgainOfStockSoldSinceTakesInTheUnitsThatStoodThereAndNotTheOnesThatLeft(): void
    {
        $item = $this->item('50.00');
        $this->shelves($item, '10');
        $first = $this->onlyResult($this->opening->post([self::LOCATION_ID], null));

        $this->assertGreaterThan(0, $this->save([$this->line($item, 0, '8', '100', '50.00')]));
        $this->assertSame(['quantity' => '2.000', 'value' => '100.00'], $this->stockOf($item));
        $before = $this->history();

        $this->opening->reverse(self::LOCATION_ID, self::START, 'Posting again', null);

        $this->assertSame(['quantity' => '-8.000', 'value' => '-400.00'], $this->stockOf($item), 'With the opening taken back only the sale is left');
        $this->assertSame(['10.000'], array_map(static fn ($line): string => $line->units, $this->opening->plan(self::LOCATION_ID, null)->lines));

        $second = $this->onlyResult($this->opening->post([self::LOCATION_ID], null));

        $this->assertSame(OpeningStockStatus::Posted, $second->plan->status);
        $this->assertHistoryKept($before, $this->history(), [$first->journalId]);
        $this->assertSame(['quantity' => '2.000', 'value' => '100.00'], $this->stockOf($item));
        $this->assertSame('2.000', $this->onHand($item));
        $this->assertSame('100.00', $this->balance($this->stockAccount()), '500 of opening stock less the 400 the sale took out');
        $this->assertStockTies();
    }

    public function testReverse_ThenPostingAgainValuesTheDifferencesAtTheCostTheyWerePostedAt(): void
    {
        $owed = $this->item('3.00');
        $this->shelves($owed, '-3');
        $first = $this->onlyResult($this->opening->post([self::LOCATION_ID], null, true, 'Sold before the books began'));

        $this->assertSame('-9.00', $this->stockOf($owed)['value']);
        $this->assertGreaterThan(0, $this->buy([$this->purchaseLine($owed, 0, '10', '9')]));
        $this->assertSame('9.0000', $this->itemCostOf($owed), 'The purchase has moved the cost of the item from 3 to 9');
        $before = $this->history();

        $this->opening->reverse(self::LOCATION_ID, self::START, 'Posting again', null);
        $second = $this->onlyResult($this->opening->post([self::LOCATION_ID], null, true, 'Posting again'));

        $this->assertSame([$this->accountId('opening_balance') . ':9.0000:0.0000', $this->stockAccount() . ':0.0000:9.0000'], $this->linesOf($second->journalId), '3 units at 3, not 3 units at 9');
        $this->assertHistoryKept($before, $this->history(), [$first->journalId]);
        $this->assertSame(['quantity' => '7.000', 'value' => '81.00'], $this->stockOf($owed));
        $this->assertStockTies();
    }

    public function testReverse_TwiceOverAPartiallyPostedLocationLeavesTheBooksAsTheFirstTimeDidAndKeepsEveryRevision(): void
    {
        $items = $this->locationWithItemsLeftOut();
        $this->opening->post([self::LOCATION_ID], null);
        $this->opening->reverse(self::LOCATION_ID, self::START, 'Taking in the items left out', null);
        $second = $this->onlyResult($this->opening->post([self::LOCATION_ID], null, true, 'Counted'));
        $stock  = $this->balance($this->stockAccount());
        $equity = $this->balance($this->accountId('opening_balance'));
        $before = $this->history();

        $this->opening->reverse(self::LOCATION_ID, self::START, 'Once more', null);
        $third = $this->onlyResult($this->opening->post([self::LOCATION_ID], null, true, 'Counted again'));

        $this->assertSame(3, $third->revision);
        $this->assertHistoryKept($before, $this->history(), [$second->journalId]);
        $this->assertSame($stock, $this->balance($this->stockAccount()));
        $this->assertSame($equity, $this->balance($this->accountId('opening_balance')));
        $this->assertSame(['reversed', 'reversed', 'posted'], array_map(static fn (object $journal): string => $journal->status, array_values(array_filter($this->openingJournals(), static fn (object $journal): bool => (int) $journal->source_id === self::LOCATION_ID))));
        $this->assertSame(['quantity' => '10.000', 'value' => '500.00'], $this->stockOf($items['rice']));
        $this->assertSame(['quantity' => '-3.000', 'value' => '-9.00'], $this->stockOf($items['owed']));
        $this->assertStockTies();
        $this->assertSame(OpeningStockStatus::AlreadyPosted, $this->opening->plan(self::LOCATION_ID, null)->status);
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
