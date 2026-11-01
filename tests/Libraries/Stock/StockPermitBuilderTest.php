<?php

namespace Tests\Libraries\Stock;

use App\Libraries\Accounting\JournalEntryType;
use App\Libraries\Accounting\JournalLineDraft;
use App\Libraries\Accounting\JournalOrigin;
use App\Libraries\Accounting\PostingError;
use App\Libraries\Accounting\PostingException;
use App\Libraries\Accounting\ReceivingPostingBuilder;
use App\Libraries\Accounting\RoutingResolver;
use App\Libraries\Accounting\StockLedger;
use App\Libraries\Stock\StockPermitBuilder;
use App\Libraries\Stock\StockPermitDocument;
use App\Libraries\Stock\StockPermitLine;
use App\Libraries\Stock\StockPermitSources;
use App\Libraries\Stock\StockPermitType;
use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\DatabaseTestTrait;
use Config\Database;
use Tests\Support\AccountingFixtureTrait;
use Tests\Support\EmployeeFixtureTrait;
use Tests\Support\ItemFixtureTrait;
use Tests\Support\PurchaseAccountingFixtureTrait;
use Tests\Support\SaleAccountingFixtureTrait;
use Tests\Support\ShelfFixtureTrait;
use Tests\Support\StockDocumentFixtureTrait;

/**
 * The plan of a stock permit, without writing anything: the journal and the valued movements of an add,
 * an issue and a transfer, the sources and descriptions they carry, and the permit that is worth nothing.
 */
class StockPermitBuilderTest extends CIUnitTestCase
{
    use DatabaseTestTrait;
    use AccountingFixtureTrait;
    use EmployeeFixtureTrait;
    use ItemFixtureTrait;
    use PurchaseAccountingFixtureTrait;
    use SaleAccountingFixtureTrait;
    use ShelfFixtureTrait;
    use StockDocumentFixtureTrait;

    protected $migrate     = true;
    protected $migrateOnce = true;
    protected $refresh     = false;
    protected $namespace   = null;

    private StockPermitBuilder $builder;

    public static function setUpBeforeClass(): void
    {
        $seeder = Database::seeder('tests');
        $seeder->call('TestDatabaseBootstrapSeeder');
    }

    protected function setUp(): void
    {
        parent::setUp();

        $this->startStockDocuments();

        $resolver      = new RoutingResolver();
        $ledger        = new StockLedger();
        $this->builder = new StockPermitBuilder($resolver, $ledger, new ReceivingPostingBuilder($resolver, $ledger));
    }

    protected function tearDown(): void
    {
        $this->stopStockDocuments();
        $this->stopBuying();

        parent::tearDown();
    }

    /**
     * @param list<StockPermitLine> $lines
     */
    private function document(StockPermitType $type, array $lines, ?int $toLocation = null, ?int $toBranch = null, ?int $account = null): StockPermitDocument
    {
        return new StockPermitDocument(
            permitId: 77,
            type: $type,
            date: '2026-10-30',
            branchId: 1,
            toBranchId: $toBranch,
            locationId: self::LOCATION_ID,
            toLocationId: $toLocation,
            accountId: $account,
            label: '000077',
            lines: $lines,
            branchCodes: [1 => 'HQ', (int) $toBranch => 'B2'],
        );
    }

    /**
     * @return array<string, string>
     */
    private function lines(?object $draft): array
    {
        return array_map(static fn (JournalLineDraft $line): string => $line->accountId . ':' . bcadd($line->debit, '0', 2) . ':' . bcadd($line->credit, '0', 2), $draft?->lines ?? []);
    }

    public function testPlan_AnAddPermitPutsEachLineInAtItsCostAndCreditsTheAutomaticAccountOnce(): void
    {
        $first  = $this->item('0.00');
        $second = $this->item('0.00');

        $plan = $this->builder->plan($this->document(StockPermitType::Add, [
            new StockPermitLine($first, 1, '2.000', '150.000000', '0'),
            new StockPermitLine($second, 2, '3.000', '10.500000', '0'),
        ]), 4, 9);

        $this->assertSame([$this->stockAccount() . ':331.50:0.00', $this->accountId('other_payables') . ':0.00:331.50'], $this->lines($plan->journal));
        $this->assertSame(['2.000:300.0000', '3.000:31.5000'], array_map(static fn ($movement): string => $movement->quantity . ':' . $movement->value, $plan->movements));
        $this->assertSame([$first, $second], array_map(static fn ($movement): int => $movement->itemId, $plan->movements));
        $this->assertNull($plan->receipt);
        $this->assertSame([], $plan->receiptMovements);

        $journal = $plan->journal;

        $this->assertSame(StockPermitSources::PERMIT, $journal->sourceType);
        $this->assertSame(77, $journal->sourceId);
        $this->assertSame(4, $journal->revision);
        $this->assertSame(9, $journal->employeeId);
        $this->assertSame(1, $journal->branchId);
        $this->assertSame('2026-10-30', $journal->date);
        $this->assertSame('Stock add permit #000077', $journal->description);
        $this->assertSame(JournalOrigin::System, $journal->origin);
        $this->assertSame(JournalEntryType::General, $journal->entryType);
    }

    public function testPlan_AnAddPermitCreditsTheAccountChosenAndIsWorthNothingAtNoCost(): void
    {
        $itemId  = $this->item('0.00');
        $account = $this->customAccount('211', '2296', 'Chosen');

        $chosen = $this->builder->plan($this->document(StockPermitType::Add, [new StockPermitLine($itemId, 1, '2.000', '5.000000', '0')], null, null, $account), 1);

        $this->assertSame([$this->stockAccount() . ':10.00:0.00', $account . ':0.00:10.00'], $this->lines($chosen->journal));

        $free = $this->builder->plan($this->document(StockPermitType::Add, [new StockPermitLine($itemId, 1, '2.000', '0.000000', '0')], null, null, $account), 1);

        $this->assertNull($free->journal, 'goods that cost nothing have no journal');
        $this->assertCount(1, $free->movements, 'and are still goods');
        $this->assertSame('0.0000', $free->movements[0]->value);
    }

    public function testPlan_AnAddPermitRoundsEachLineToTheScaleOfValues(): void
    {
        $itemId = $this->item('0.00');

        $plan = $this->builder->plan($this->document(StockPermitType::Add, [new StockPermitLine($itemId, 1, '3.000', '0.333333', '0')]), 1);

        $this->assertSame('1.0000', $plan->movements[0]->value);
    }

    public function testPlan_AnIssuePermitTakesEachLineOutAtTheAverageOfTheLocationAndDebitsTheAutomaticAccount(): void
    {
        $itemId = $this->stocked('10', '50');
        $this->assertGreaterThan(0, $this->buy([$this->purchaseLine($itemId, 0, '10', '70')]));
        $this->accountingDb()->table('items')->where('item_id', $itemId)->update(['cost_price' => '99.00']);

        $plan = $this->builder->plan($this->document(StockPermitType::Issue, [new StockPermitLine($itemId, 1, '3.000', '0', '99.00'), new StockPermitLine($itemId, 2, '2.000', '0', '99.00')]), 1);

        $this->assertSame([$this->accountId('other_receivables') . ':300.00:0.00', $this->stockAccount() . ':0.00:300.00'], $this->lines($plan->journal));
        $this->assertSame(['-3.000:-180.0000', '-2.000:-120.0000'], array_map(static fn ($movement): string => $movement->quantity . ':' . $movement->value, $plan->movements));
        $this->assertSame('Stock issue permit #000077', $plan->journal->description);
        $this->assertSame(StockPermitSources::PERMIT, $plan->journal->sourceType);
    }

    public function testPlan_AnIssuePermitUsesTheCostTheItemCarriesWhenTheMovementsDoNotAccountForTheShelves(): void
    {
        $itemId = $this->item('40.00');
        $this->putOnShelf($itemId, '10');

        $plan = $this->builder->plan($this->document(StockPermitType::Issue, [new StockPermitLine($itemId, 1, '2.000', '0', '40.00')]), 1);

        $this->assertSame('-80.0000', $plan->movements[0]->value);
    }

    public function testPlan_AnIssuePermitDebitsTheAccountChosen(): void
    {
        $itemId  = $this->stocked('10', '50');
        $account = $this->customAccount('12', '1296', 'Chosen debtors');

        $plan = $this->builder->plan($this->document(StockPermitType::Issue, [new StockPermitLine($itemId, 1, '1.000', '0', '50.00')], null, null, $account), 1);

        $this->assertSame([$account . ':50.00:0.00', $this->stockAccount() . ':0.00:50.00'], $this->lines($plan->journal));
    }

    public function testPlan_ATransferWithinABranchIsOneJournalThatMovesTheValueFromOneLocationToTheOtherUnderTheSourceOfThePermit(): void
    {
        $second = $this->anotherLocation();
        $itemId = $this->stocked('10', '50');

        $plan = $this->builder->plan($this->document(StockPermitType::Transfer, [new StockPermitLine($itemId, 1, '4.000', '0', '50.00')], $second), 3, 9);

        $this->assertSame([$this->stockAccountOf($second) . ':200.00:0.00', $this->stockAccount() . ':0.00:200.00'], $this->lines($plan->journal));
        $this->assertSame(['-4.000:-200.0000:' . self::LOCATION_ID, '4.000:200.0000:' . $second], array_map(static fn ($movement): string => $movement->quantity . ':' . $movement->value . ':' . $movement->locationId, $plan->movements));
        $this->assertNull($plan->receipt);
        $this->assertSame(StockPermitSources::TRANSFER, $plan->journal->sourceType);
        $this->assertSame(77, $plan->journal->sourceId);
        $this->assertSame(3, $plan->journal->revision);
        $this->assertSame('Stock transfer permit #000077', $plan->journal->description);
    }

    public function testPlan_ATransferBetweenTwoBranchesIsTwoJournalsEachInItsOwnBranchWithTheSourcesAndTheDescriptionsOfThePermit(): void
    {
        $other  = $this->createBranch();
        $store  = $this->locationOfBranch($other, 'Branch store');
        $itemId = $this->stocked('10', '50');

        $plan = $this->builder->plan($this->document(StockPermitType::Transfer, [new StockPermitLine($itemId, 1, '4.000', '0', '50.00')], $store, $other), 2);

        $this->assertSame([$this->accountId('branches_receivable') . ':200.00:0.00', $this->stockAccount() . ':0.00:200.00'], $this->lines($plan->journal));
        $this->assertSame([$this->stockAccountOf($store) . ':200.00:0.00', $this->accountId('branches_payable') . ':0.00:200.00'], $this->lines($plan->receipt));
        $this->assertSame(StockPermitSources::TRANSFER, $plan->journal->sourceType);
        $this->assertSame(StockPermitSources::TRANSFER_RECEIPT, $plan->receipt->sourceType);
        $this->assertSame([1, $other], [$plan->journal->branchId, $plan->receipt->branchId]);
        $this->assertSame(['Stock transfer permit #000077 (to branch B2)', 'Stock transfer permit #000077 (from branch HQ)'], [$plan->journal->description, $plan->receipt->description]);
        $this->assertSame([77, 77], [$plan->journal->sourceId, $plan->receipt->sourceId]);
        $this->assertSame(['-4.000:-200.0000'], array_map(static fn ($movement): string => $movement->quantity . ':' . $movement->value, $plan->movements));
        $this->assertSame(['4.000:200.0000'], array_map(static fn ($movement): string => $movement->quantity . ':' . $movement->value, $plan->receiptMovements));
    }

    public function testPlan_ATransferOfGoodsThatCostNothingHasMovementsAndNoJournal(): void
    {
        $second = $this->anotherLocation();
        $itemId = $this->item('0.00');

        $this->putOnShelf($itemId, '5');

        $plan = $this->builder->plan($this->document(StockPermitType::Transfer, [new StockPermitLine($itemId, 1, '2.000', '0', '0.00')], $second), 1);

        $this->assertNull($plan->journal);
        $this->assertCount(2, $plan->movements);
    }

    public function testPlan_ATransferWithoutADestinationIsRefused(): void
    {
        $itemId = $this->stocked('10', '50');

        try {
            $this->builder->plan($this->document(StockPermitType::Transfer, [new StockPermitLine($itemId, 1, '4.000', '0', '50.00')]), 1);
            $this->fail('A transfer names the location the stock goes to.');
        } catch (PostingException $exception) {
            $this->assertSame(PostingError::PurchaseNotPostable, $exception->error);
        }
    }

    public function testPlan_ATransferSplitsWhatLeavesOverTheLinesThatComeInSoThatTheValuesAgree(): void
    {
        $second = $this->anotherLocation();
        $itemId = $this->stocked('10', '50');
        $this->assertGreaterThan(0, $this->buy([$this->purchaseLine($itemId, 0, '5', '51')]));

        $plan = $this->builder->plan($this->document(StockPermitType::Transfer, [new StockPermitLine($itemId, 1, '1.000', '0', '50.00'), new StockPermitLine($itemId, 2, '2.000', '0', '50.00')], $second), 1);

        $out = '0';
        $in  = '0';

        foreach ($plan->movements as $movement) {
            if (bccomp($movement->quantity, '0', 3) < 0) {
                $out = bcadd($out, $movement->value, 4);
            } else {
                $in = bcadd($in, $movement->value, 4);
            }
        }

        $this->assertSame(bcmul('-1', $out, 4), $in, 'what goes in is what comes out');
    }
}
