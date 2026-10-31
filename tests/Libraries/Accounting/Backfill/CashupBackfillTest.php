<?php

namespace Tests\Libraries\Accounting\Backfill;

use App\Libraries\Accounting\PostingService;
use App\Libraries\Accounting\Backfill\BackfillDocument;
use App\Libraries\Accounting\Backfill\BackfillFamily;
use App\Libraries\Accounting\Backfill\BackfillPlan;
use App\Libraries\Accounting\Backfill\BackfillRefusal;
use App\Libraries\Accounting\Backfill\BackfillScope;
use App\Libraries\Accounting\Backfill\CashupBackfill;
use App\Models\Cashup;
use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\DatabaseTestTrait;
use Config\Database;
use Tests\Support\AccountingFixtureTrait;
use Tests\Support\BackfillFixtureTrait;
use Tests\Support\EmployeeFixtureTrait;
use Tests\Support\ExpenseAccountingFixtureTrait;
use Tests\Support\ItemFixtureTrait;
use Tests\Support\PurchaseAccountingFixtureTrait;
use Tests\Support\SaleAccountingFixtureTrait;

/**
 * @internal
 */
final class CashupBackfillTest extends CIUnitTestCase
{
    use DatabaseTestTrait;
    use AccountingFixtureTrait;
    use BackfillFixtureTrait;
    use EmployeeFixtureTrait;
    use ExpenseAccountingFixtureTrait;
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

        $this->cashups = model(Cashup::class);
        $this->clerkId = $this->employeeWithGrants([]);
    }

    protected function tearDown(): void
    {
        $this->clearExpenseRows();
        $this->stopBackfill();

        parent::tearDown();
    }

    private function adapter(): CashupBackfill
    {
        return new CashupBackfill($this->accountingDb());
    }

    private function plan(int $cashupId): BackfillPlan
    {
        $row = $this->accountingDb()->table('cash_up')->where('cashup_id', $cashupId)->get()->getRow();

        return $this->adapter()->plan(new BackfillDocument(BackfillFamily::Cashups, $cashupId, substr((string) $row->close_date, 0, 10), (int) ($row->branch_id ?? 1)), null, 0);
    }

    public function testPlan_MakesTheJournalTheLivePostingMakesOfTheSameCashup(): void
    {
        $legacy = $this->legacyCashup('900', '2026-03-12');
        $live   = $this->saveCashup($this->cashupData('900', '1000', '0', '2026-03-12 08:00:00', '2026-03-12 18:00:00'));

        $this->assertNotNull($live);
        $this->assertSame(['cashup:general|' . implode(',', array_map(static fn (string $line): string => str_replace(['=', '/'], ':', $line), $this->postedLines('cashup', $live)))], $this->plannedLines($this->plan($legacy)));
    }

    public function testPlan_CountsTheExpectedCashAndTheDifferenceOfACashupThatWasNeverStamped(): void
    {
        $legacy = $this->legacyCashup('900', '2026-03-12');
        $plan   = $this->plan($legacy);

        $this->assertSame('cash_up', $plan->stamp['table']);
        $this->assertSame('1000.0000', bcadd($plan->stamp['columns']['expected_amount_cash'], '0', 4));
        $this->assertSame('-100.0000', bcadd($plan->stamp['columns']['cash_difference'], '0', 4));
        $this->assertSame(1, $plan->stamp['columns']['branch_id']);
    }

    public function testPlan_RefusesACashupThatCountedExactlyWhatWasExpected(): void
    {
        $legacy = $this->legacyCashup('1000', '2026-03-12');

        $this->assertSame(BackfillRefusal::ZeroTotal, $this->plan($legacy)->refusal);
    }

    public function testPlan_RefusesACashupBeforeTheStartDateOrInAClosedPeriod(): void
    {
        $legacy = $this->legacyCashup();

        $this->closeThrough('2026-03-31');
        $this->assertSame(BackfillRefusal::PeriodClosed, $this->plan($legacy)->refusal);

        $this->setConfigValue('accounting_start_date', '2026-06-01');
        $this->assertSame(BackfillRefusal::BeforeStartDate, $this->plan($legacy)->refusal);
    }

    public function testCandidates_LeavesOutACashupThatCountedNothingOrWasDeleted(): void
    {
        $counted = $this->legacyCashup('900');
        $open    = $this->legacyCashup('0');
        $deleted = $this->legacyCashup('800');

        $this->accountingDb()->table('cash_up')->where('cashup_id', $deleted)->update(['deleted' => 1]);

        $found = array_map(static fn (BackfillDocument $document): int => $document->id, $this->adapter()->candidates(new BackfillScope('2026-01-01', '2026-12-31', null, [BackfillFamily::Cashups]), 0, 10, 0));

        $this->assertSame([$counted], $found);
        $this->assertGreaterThan(0, $open);
    }

    public function testPlan_RefusesACashupThatIsNoLongerCountedOrIsGone(): void
    {
        $legacy = $this->legacyCashup('900');

        $this->accountingDb()->table('cash_up')->where('cashup_id', $legacy)->update(['closed_amount_cash' => '0', 'closed_amount_card' => '0']);

        $this->assertSame(BackfillRefusal::DocumentChanged, $this->adapter()->plan(new BackfillDocument(BackfillFamily::Cashups, $legacy, '2026-03-12', 1), null, 0)->refusal);
        $this->assertSame(BackfillRefusal::DocumentChanged, $this->adapter()->plan(new BackfillDocument(BackfillFamily::Cashups, 987654, '2026-03-12', 1), null, 0)->refusal);
    }

    public function testPlan_RefusesACashupCountedInATreasuryThatIsGone(): void
    {
        $legacy = $this->legacyCashup('900');

        $this->withoutKeys(fn () => $this->accountingDb()->table('cash_up')->where('cashup_id', $legacy)->update(['treasury_id' => 987654, 'branch_id' => 1]));

        $this->assertSame(BackfillRefusal::TreasuryMissing, $this->plan($legacy)->refusal);
    }

    public function testPlan_KeepsTheDifferenceOfACashupThatWasStampedAndDoesNotStampItAgain(): void
    {
        $legacy = $this->legacyCashup('900', '2026-03-12', 1);

        $this->accountingDb()->table('cash_up')->where('cashup_id', $legacy)->update(['expected_amount_cash' => '1000', 'cash_difference' => '-100']);

        $plan = $this->plan($legacy);

        $this->assertSame([], $plan->stamp);
        $this->assertSame(['cashup:general|' . $this->accountId('cash_difference') . ':100.00:0.00,' . $this->accountId('cash') . ':0.00:100.00'], $this->plannedLines($plan));
    }

    public function testPlan_RefusesACashupWhoseJournalIsInTheBooksAlready(): void
    {
        $legacy = $this->legacyCashup('900');

        (new PostingService())->post($this->balancedDraft('cashup', $legacy));

        $this->assertSame(BackfillRefusal::PartlyPosted, $this->plan($legacy)->refusal);
    }
}
