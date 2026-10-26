<?php

namespace Tests\Libraries\Accounting;

use App\Libraries\Accounting\LegacyDiscountBlocked;
use App\Libraries\Accounting\LegacyDiscountFinding;
use App\Libraries\Accounting\LegacyDiscountVerdict;
use App\Libraries\Accounting\LegacyPurchaseDiscount;
use App\Libraries\Accounting\ReceivingLoader;
use App\Libraries\Accounting\ReceivingPostingBuilder;
use App\Libraries\Accounting\RoutingResolver;
use App\Libraries\Accounting\StockLedger;
use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\DatabaseTestTrait;
use Config\Database;
use InvalidArgumentException;
use Tests\Support\AccountingFixtureTrait;
use Tests\Support\EmployeeFixtureTrait;
use Tests\Support\ItemFixtureTrait;
use Tests\Support\PurchaseAccountingFixtureTrait;
use Tests\Support\SaleAccountingFixtureTrait;

/**
 * Telling the fixed discounts that were saved as an amount for the whole line from the ones saved
 * as an amount for every unit: by what the books posted when the purchase reached them, by the
 * date when it never did, and by the operator's decision when nothing else says.
 */
class LegacyPurchaseDiscountTest extends CIUnitTestCase
{
    use DatabaseTestTrait;
    use AccountingFixtureTrait;
    use EmployeeFixtureTrait;
    use ItemFixtureTrait;
    use SaleAccountingFixtureTrait;
    use PurchaseAccountingFixtureTrait;

    protected $migrate     = true;
    protected $migrateOnce = true;
    protected $refresh     = false;
    protected $namespace   = null;

    private const BEFORE_THE_CHANGE = '2026-10-01 09:00:00';

    public static function setUpBeforeClass(): void
    {
        $seeder = Database::seeder('tests');
        $seeder->call('TestDatabaseBootstrapSeeder');
    }

    protected function setUp(): void
    {
        parent::setUp();

        $this->startBuying();
        $this->scan()->clearResolution();
    }

    protected function tearDown(): void
    {
        $this->scan()->clearResolution();
        $this->stopBuying();

        parent::tearDown();
    }

    private function scan(): LegacyPurchaseDiscount
    {
        return new LegacyPurchaseDiscount();
    }

    /**
     * A purchase of ten units at 50 with a discount of 20 that was posted as an amount for the whole
     * line, and then stored the way it was before the change: as a plain fixed discount.
     */
    private function postedForTheWholeLine(string $discount = '20'): int
    {
        $id = $this->buy([$this->purchaseLine($this->item('0.00'), 0, '10', '50', '0', $discount, FIXED_LINE)]);

        $this->assertGreaterThan(0, $id);
        $this->accountingDb()->table('receivings_items')->where('receiving_id', $id)->update(['discount_type' => FIXED]);

        return $id;
    }

    private function postedForEveryUnit(string $discount = '20'): int
    {
        $id = $this->buy([$this->purchaseLine($this->item('0.00'), 0, '10', '50', '0', $discount, FIXED)]);

        $this->assertGreaterThan(0, $id);

        return $id;
    }

    private function neverPosted(string $savedAt): int
    {
        $db = $this->accountingDb();

        $db->table('receivings')->insert(['employee_id' => $this->employeeId, 'comment' => '', 'receiving_time' => $savedAt]);

        $id                   = (int) $db->insertID();
        $this->receivingIds[] = $id;

        $db->table('receivings_items')->insert([
            'receiving_id'       => $id,
            'item_id'            => $this->item('0.00'),
            'line'               => 1,
            'quantity_purchased' => '10',
            'receiving_quantity' => '1',
            'item_cost_price'    => '0.00',
            'item_unit_price'    => '50.00',
            'discount'           => '20.00',
            'discount_type'      => FIXED,
            'item_location'      => 1,
        ]);

        return $id;
    }

    private function verdictOf(int $receivingId): LegacyDiscountVerdict
    {
        $findings = array_values(array_filter($this->scan()->findings(), static fn (LegacyDiscountFinding $finding): bool => $finding->receivingId === $receivingId));

        $this->assertCount(1, $findings);

        return $findings[0]->verdict;
    }

    private function postedSupplierAmount(int $receivingId): string
    {
        $journal = $this->accountingDb()->table('accounting_journals')->where(['source_type' => 'purchase', 'source_id' => $receivingId, 'status' => 'posted'])->get()->getRow();
        $line    = $this->accountingDb()->table('accounting_journal_lines')->where(['journal_id' => $journal->journal_id, 'account_id' => $this->supplierAccount()])->get()->getRow();

        return bcadd(bcadd((string) $line->debit, (string) $line->credit, 2), '0', 2);
    }

    private function typeOf(int $receivingId): int
    {
        return (int) $this->accountingDb()->table('receivings_items')->where('receiving_id', $receivingId)->get()->getRow()->discount_type;
    }

    public function testFindings_ReadsAPostedPurchaseThatFitsTheDiscountForTheWholeLineAsLegacy(): void
    {
        $id = $this->postedForTheWholeLine();
        $this->assertSame(LegacyDiscountVerdict::LegacyByBooks, $this->verdictOf($id));
    }

    public function testFindings_ReadsAPostedPurchaseThatFitsTheDiscountForEveryUnitAsSavedAfterTheChange(): void
    {
        $this->assertSame(LegacyDiscountVerdict::PerUnitByBooks, $this->verdictOf($this->postedForEveryUnit()));
    }

    public function testFindings_ReadsAPostedReturnByWhatItPostedToTheSupplier(): void
    {
        $itemId = $this->item('0.00');
        $origin = $this->buy([$this->purchaseLine($itemId, 0, '10', '50', '0', '20', FIXED_LINE)]);
        $return = $this->buy([$this->purchaseLine($itemId, 0, '-4', '50', '0', '8', FIXED_LINE)], -1, null, $origin);

        $this->assertGreaterThan(0, $return);
        $this->accountingDb()->table('receivings_items')->where('receiving_id', $return)->update(['discount_type' => FIXED]);

        $this->assertSame(LegacyDiscountVerdict::LegacyByBooks, $this->verdictOf($return));
    }

    public function testFindings_ReadsAPurchaseThatNeverReachedTheBooksByWhenItWasSaved(): void
    {
        $this->assertSame(LegacyDiscountVerdict::LegacyByDate, $this->verdictOf($this->neverPosted(self::BEFORE_THE_CHANGE)));
    }

    public function testFindings_LeavesAPurchaseThatNeverReachedTheBooksAndWasSavedAfterTheChangeUndetermined(): void
    {
        $id = $this->neverPosted(date('Y-m-d H:i:s'));

        $this->assertSame(LegacyDiscountVerdict::Undetermined, $this->verdictOf($id));
    }

    public function testFindings_LeavesAPostedPurchaseThatFitsNeitherReadingUndetermined(): void
    {
        $id = $this->postedForTheWholeLine();
        $this->accountingDb()->table('receivings_items')->where('receiving_id', $id)->update(['discount' => '30.00']);

        $this->assertSame(LegacyDiscountVerdict::Undetermined, $this->verdictOf($id));
    }

    public function testFindings_FallsBackOnTheDateWhenBothReadingsPostTheSame(): void
    {
        $id = $this->buy([$this->purchaseLine($this->item('0.00'), 0, '1.05', '50', '0', '0.01', FIXED)]);

        $this->assertGreaterThan(0, $id);

        $finding = array_values(array_filter($this->scan()->findings(), static fn (LegacyDiscountFinding $finding): bool => $finding->receivingId === $id))[0];

        $this->assertSame(LegacyDiscountVerdict::Undetermined, $finding->verdict);
        $this->assertStringContainsString('fits both readings', $finding->reason);
    }

    public function testFindings_IgnoresWhatMeansTheSameEitherWay(): void
    {
        $itemId  = $this->item('0.00');
        $oneUnit = $this->buy([$this->purchaseLine($itemId, 0, '1', '50', '0', '5', FIXED)]);
        $oneBack = $this->buy([$this->purchaseLine($itemId, 0, '-1', '50', '0', '5', FIXED)], -1, null, $oneUnit);
        $percent = $this->buy([$this->purchaseLine($itemId, 0, '10', '50', '0', '5', PERCENT)]);
        $none    = $this->buy([$this->purchaseLine($itemId, 0, '10', '50', '0', '0', FIXED)]);
        $marked  = $this->buy([$this->purchaseLine($itemId, 0, '10', '50', '0', '5', FIXED_LINE)]);

        $seen = array_map(static fn (LegacyDiscountFinding $finding): int => $finding->receivingId, $this->scan()->findings());

        $this->assertSame([], array_values(array_intersect([$oneUnit, $oneBack, $percent, $none, $marked], $seen)));
    }

    public function testWindow_NamesTheLatestWholeLineAndTheEarliestEveryUnitTheBooksShow(): void
    {
        $legacy  = $this->postedForTheWholeLine();
        $perUnit = $this->postedForEveryUnit();

        $this->accountingDb()->table('receivings')->where('receiving_id', $legacy)->update(['receiving_time' => '2026-10-20 10:00:00']);
        $this->accountingDb()->table('receivings')->where('receiving_id', $perUnit)->update(['receiving_time' => '2026-10-27 10:00:00']);

        $this->assertSame(['wholeLine' => '2026-10-20 10:00:00', 'everyUnit' => '2026-10-27 10:00:00', 'overlap' => false], LegacyPurchaseDiscount::window($this->scan()->findings()));
    }

    public function testWindow_SaysSoWhenTheBooksShowBothKindsInTheSamePeriod(): void
    {
        $legacy  = $this->postedForTheWholeLine();
        $perUnit = $this->postedForEveryUnit();

        $this->accountingDb()->table('receivings')->where('receiving_id', $legacy)->update(['receiving_time' => '2026-10-27 10:00:00']);
        $this->accountingDb()->table('receivings')->where('receiving_id', $perUnit)->update(['receiving_time' => '2026-10-20 10:00:00']);

        $this->assertTrue(LegacyPurchaseDiscount::window($this->scan()->findings())['overlap']);
    }

    public function testWindow_IsEmptyWhenTheBooksShowNothing(): void
    {
        $this->neverPosted(self::BEFORE_THE_CHANGE);

        $this->assertSame(['wholeLine' => null, 'everyUnit' => null, 'overlap' => false], LegacyPurchaseDiscount::window($this->scan()->findings()));
    }

    public function testPlan_StopsOnALineNothingDecidesUntilTheOperatorDecides(): void
    {
        $stored = $this->neverPosted(date('Y-m-d H:i:s'));

        $this->assertSame([$stored], array_map(static fn (LegacyDiscountFinding $finding): int => $finding->receivingId, $this->scan()->plan()['blocked']));

        foreach ([[LegacyPurchaseDiscount::ALL, null, 'mark'], [LegacyPurchaseDiscount::NONE, null, 'keep'], [LegacyPurchaseDiscount::BEFORE, '2999-01-01 00:00:00', 'mark'], [LegacyPurchaseDiscount::BEFORE, '2000-01-01 00:00:00', 'keep']] as [$kind, $before, $bucket]) {
            $this->scan()->resolve($kind, $before);

            $plan = $this->scan()->plan();

            $this->assertSame([], $plan['blocked'], $kind . ' ' . $before);
            $this->assertContains($stored, array_map(static fn (LegacyDiscountFinding $finding): int => $finding->receivingId, $plan[$bucket]), $kind . ' ' . $before);
        }
    }

    public function testApply_MarksWhatItCanShowAndChangesNothingWhileALineIsLeftUndecided(): void
    {
        $legacy    = $this->postedForTheWholeLine();
        $perUnit   = $this->postedForEveryUnit();
        $undecided = $this->neverPosted(date('Y-m-d H:i:s'));

        try {
            $this->scan()->apply();
            $this->fail('A line that nothing decides must stop the marking.');
        } catch (LegacyDiscountBlocked $blocked) {
            $this->assertContains($undecided, array_map(static fn (LegacyDiscountFinding $finding): int => $finding->receivingId, $blocked->blocked));
            $this->assertStringContainsString('nothing was changed', $blocked->getMessage());
        }

        $this->assertSame(FIXED, $this->typeOf($legacy));
        $this->assertSame(FIXED, $this->typeOf($perUnit));
        $this->assertSame(FIXED, $this->typeOf($undecided));

        $this->scan()->resolve(LegacyPurchaseDiscount::NONE);
        $this->scan()->apply();

        $this->assertSame(FIXED_LINE, $this->typeOf($legacy));
        $this->assertSame(FIXED, $this->typeOf($perUnit));
        $this->assertSame(FIXED, $this->typeOf($undecided));
    }

    public function testApply_LeavesTheJournalsOfWhatItMarksAsTheyWereAndTheMarkedLineStillComesToWhatWasPosted(): void
    {
        $itemId = $this->item('0.00');
        $origin = $this->buy([$this->purchaseLine($itemId, 0, '10', '50', '15', '20', FIXED_LINE)]);
        $return = $this->buy([$this->purchaseLine($itemId, 0, '-4', '50', '15', '8', FIXED_LINE)], -1, null, $origin);

        foreach ([$origin, $return] as $id) {
            $this->accountingDb()->table('receivings_items')->where('receiving_id', $id)->update(['discount_type' => FIXED]);
        }

        $before = [$this->purchaseJournals($origin), $this->purchaseJournals($return)];

        $this->assertSame(2, $this->scan()->apply());
        $this->assertSame($before, [$this->purchaseJournals($origin), $this->purchaseJournals($return)]);

        $builder = new ReceivingPostingBuilder(new RoutingResolver(), new StockLedger());
        $loader  = new ReceivingLoader();

        $this->assertSame('552.00', $builder->totals($loader->load($origin))['total']);
        $this->assertSame('220.80', $builder->totals($loader->load($return))['total']);
        $this->assertSame(['552.00', '220.80'], [$this->postedSupplierAmount($origin), $this->postedSupplierAmount($return)]);
    }

    public function testApply_MarksTheUndecidedLinesTheOperatorSaysWereSavedBeforeTheMoment(): void
    {
        $early = $this->neverPosted('2026-10-26 08:00:00');
        $late  = $this->neverPosted('2026-10-26 18:00:00');

        $this->scan()->resolve(LegacyPurchaseDiscount::BEFORE, '2026-10-26 12:00:00');
        $this->scan()->apply();

        $this->assertSame(FIXED_LINE, $this->typeOf($early));
        $this->assertSame(FIXED, $this->typeOf($late));
    }

    public function testApply_IsSafeToRunTwiceAndLeavesWhatIsMarkedAlone(): void
    {
        $legacy = $this->postedForTheWholeLine();

        $this->assertSame(1, $this->scan()->apply());
        $this->assertSame(0, $this->scan()->apply());
        $this->assertSame(FIXED_LINE, $this->typeOf($legacy));
    }

    public function testResolution_ReadsBackWhatWasRecordedAndForgetsIt(): void
    {
        $this->assertNull($this->scan()->resolution());

        $this->scan()->resolve(LegacyPurchaseDiscount::BEFORE, '2026-10-26 12:00:00');
        $this->assertSame(['kind' => LegacyPurchaseDiscount::BEFORE, 'before' => '2026-10-26 12:00:00'], $this->scan()->resolution());

        $this->scan()->resolve(LegacyPurchaseDiscount::ALL);
        $this->assertSame(['kind' => LegacyPurchaseDiscount::ALL, 'before' => null], $this->scan()->resolution());

        $this->scan()->clearResolution();
        $this->assertNull($this->scan()->resolution());
    }

    public function testResolve_RejectsAMomentThatIsNotADateAndADecisionThatDoesNotExist(): void
    {
        foreach ([[LegacyPurchaseDiscount::BEFORE, null], [LegacyPurchaseDiscount::BEFORE, '2026-10-26'], [LegacyPurchaseDiscount::BEFORE, '2026-13-40 00:00:00'], ['sometimes', null]] as [$kind, $before]) {
            try {
                $this->scan()->resolve($kind, $before);
                $this->fail("{$kind} {$before} must be refused.");
            } catch (InvalidArgumentException) {
                $this->assertNull($this->scan()->resolution());
            }
        }
    }
}
