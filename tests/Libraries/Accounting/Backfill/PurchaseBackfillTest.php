<?php

namespace Tests\Libraries\Accounting\Backfill;

use App\Libraries\Accounting\PostingService;
use App\Libraries\Accounting\Backfill\BackfillDocument;
use App\Libraries\Accounting\Backfill\BackfillFamily;
use App\Libraries\Accounting\Backfill\BackfillPlan;
use App\Libraries\Accounting\Backfill\BackfillRefusal;
use App\Libraries\Accounting\Backfill\BackfillScope;
use App\Libraries\Accounting\Backfill\PurchaseBackfill;
use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\DatabaseTestTrait;
use Config\Database;
use Tests\Support\AccountingFixtureTrait;
use Tests\Support\BackfillFixtureTrait;
use Tests\Support\EmployeeFixtureTrait;
use Tests\Support\ItemFixtureTrait;
use Tests\Support\PurchaseAccountingFixtureTrait;
use Tests\Support\SaleAccountingFixtureTrait;

/**
 * @internal
 */
final class PurchaseBackfillTest extends CIUnitTestCase
{
    use DatabaseTestTrait;
    use AccountingFixtureTrait;
    use BackfillFixtureTrait;
    use EmployeeFixtureTrait;
    use ItemFixtureTrait;
    use PurchaseAccountingFixtureTrait;
    use SaleAccountingFixtureTrait;

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

        $this->startBackfill();
    }

    protected function tearDown(): void
    {
        $this->stopBackfill();

        parent::tearDown();
    }

    private function adapter(): PurchaseBackfill
    {
        return new PurchaseBackfill($this->accountingDb());
    }

    private function plan(int $receivingId): BackfillPlan
    {
        $row = $this->accountingDb()->table('receivings')->where('receiving_id', $receivingId)->get()->getRow();

        return $this->adapter()->plan(new BackfillDocument(BackfillFamily::Purchases, $receivingId, substr((string) $row->receiving_time, 0, 10), (int) $row->branch_id), null, 0);
    }

    /**
     * @return list<string>
     */
    private function posted(int $receivingId): array
    {
        return array_map(static fn (array $journal): string => $journal['source'] . ':' . $journal['entry'] . '|' . implode(',', $journal['lines']), array_values(array_filter($this->purchaseJournals($receivingId), static fn (array $journal): bool => $journal['status'] === 'posted')));
    }

    public function testPlan_MakesTheJournalsAndTheMovementsTheLivePostingMakesOfTheSamePurchase(): void
    {
        $legacy = $this->legacyPurchase('500', '2026-03-10', lang('Sales.cash'));
        $live   = $this->purchaseOf('500', null, lang('Sales.cash'));

        $this->assertCount(2, $this->posted($live));
        $this->assertSame($this->posted($live), $this->plannedLines($this->plan($legacy)));

        $stock = array_values(array_filter($this->plan($legacy)->steps, static fn ($step): bool => $step->stock !== null))[0]->stock;

        $this->assertSame('purchase', $stock->source);
        $this->assertSame('500.0000', bcadd($stock->movements[0]->value, '0', 4));
    }

    public function testPlan_PostsAPaymentMadeAgainstThePurchaseSince(): void
    {
        $legacy = $this->legacyPurchase('500');

        $this->whileOff(fn (): int => $this->payPurchase($legacy, '120'));

        $live = $this->purchaseOf('500');

        $this->payPurchase($live, '120');

        $this->assertCount(2, $this->posted($live));
        $this->assertSame($this->posted($live), $this->plannedLines($this->plan($legacy)));
    }

    public function testPlan_LeavesAReturnAndATransferToTheLivePosting(): void
    {
        $origin = $this->purchaseOf('500');
        $return = $this->whileOff(fn (): int => $this->debitNoteOf($origin, '100'));

        $this->assertSame(BackfillRefusal::ValuedByLedger, $this->plan($return)->refusal);
        $this->assertSame('return', $this->plan($return)->detail);
    }

    public function testPlan_RefusesAPurchaseOfADeletedSupplier(): void
    {
        $receivingId = $this->legacyPurchase('500');

        $this->accountingDb()->table('suppliers')->where('person_id', $this->supplierId)->update(['deleted' => 1]);

        $this->assertSame(BackfillRefusal::PartyMissing, $this->plan($receivingId)->refusal);
    }

    public function testPlan_RefusesAPurchaseDatedBeforeTheStartDateAndOneInAClosedPeriod(): void
    {
        $receivingId = $this->legacyPurchase('500', '2026-03-10');

        $this->closeThrough('2026-03-31');
        $this->assertSame(BackfillRefusal::PeriodClosed, $this->plan($receivingId)->refusal);

        $this->setConfigValue('accounting_start_date', '2026-06-01');
        $this->assertSame(BackfillRefusal::BeforeStartDate, $this->plan($receivingId)->refusal);
    }

    public function testPlan_RefusesAPaymentIntoATreasuryThatIsGone(): void
    {
        $receivingId = $this->legacyPurchase('500');

        $this->whileOff(fn (): int => $this->payPurchase($receivingId, '120'));
        $this->withoutKeys(fn () => $this->accountingDb()->table('receivings_payments')->where('receiving_id', $receivingId)->update(['treasury_id' => 987654]));

        $this->assertSame(BackfillRefusal::TreasuryMissing, $this->plan($receivingId)->refusal);
    }

    public function testCandidates_ListsThePurchasesInTheRangeThatNoJournalOrMovementStandsFor(): void
    {
        $legacy = $this->legacyPurchase('500', '2026-03-10');
        $live   = $this->purchaseOf('300');
        $early  = $this->legacyPurchase('500', '2025-12-01');
        $scope  = new BackfillScope('2026-01-01', '2026-12-31', null, [BackfillFamily::Purchases]);

        $found = array_map(static fn (BackfillDocument $document): int => $document->id, $this->adapter()->candidates($scope, 0, 50, 0));

        $this->assertContains($legacy, $found);
        $this->assertNotContains($live, $found);
        $this->assertNotContains($early, $found);
    }

    public function testPlan_RefusesAPurchaseThatIsGone(): void
    {
        $this->assertSame(BackfillRefusal::DocumentChanged, $this->adapter()->plan(new BackfillDocument(BackfillFamily::Purchases, 987654, '2026-03-10', 1), null, 0)->refusal);
    }

    public function testPlan_RefusesAPaymentIntoATreasuryOfAnotherBranch(): void
    {
        $other       = $this->createBranch();
        $receivingId = $this->legacyPurchase('500');

        $this->whileOff(fn (): int => $this->payPurchase($receivingId, '120'));
        $this->accountingDb()->table('receivings_payments')->where('receiving_id', $receivingId)->update(['treasury_id' => $this->treasuryIn($other), 'treasury_branch_id' => $other]);

        $this->assertSame(BackfillRefusal::CrossBranchPayment, $this->plan($receivingId)->refusal);
    }

    public function testCandidates_LeavesOutAPurchaseOfGoodsThatCostNothingThatTheBooksHoldAsMovementsOnly(): void
    {
        $item = $this->item('0.00');
        $free = $this->buy([$this->purchaseLine($item, 0, '5', '0')]);
        $scope = new BackfillScope('2026-01-01', '2026-12-31', null, [BackfillFamily::Purchases]);

        $this->assertGreaterThan(0, $free);
        $this->assertSame(0, $this->accountingDb()->table('accounting_journals')->where('source_type', 'purchase')->where('source_id', $free)->countAllResults());
        $this->assertGreaterThan(0, $this->accountingDb()->table('accounting_stock_entries')->where('source_id', $free)->countAllResults());
        $this->assertNotContains($free, array_map(static fn (BackfillDocument $document): int => $document->id, $this->adapter()->candidates($scope, 0, 50, 0)));
    }

    public function testPlan_RefusesAPurchaseWhoseSupplierPaymentIsInTheBooksAlready(): void
    {
        $receivingId = $this->legacyPurchase('500');
        $paymentId   = $this->whileOff(fn (): int => $this->payPurchase($receivingId, '120'));

        (new PostingService())->post($this->balancedDraft('supplier_payment', $paymentId));

        $this->assertSame(BackfillRefusal::PartlyPosted, $this->plan($receivingId)->refusal);
    }
}
