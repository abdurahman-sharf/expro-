<?php

namespace Tests\Libraries\Assets;

use App\Libraries\Accounting\AccountingGuard;
use App\Libraries\Assets\AssetFigures;
use App\Libraries\Assets\AssetMethod;
use App\Libraries\Assets\DepreciationPeriod;
use App\Libraries\Assets\DepreciationSchedule;
use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\DatabaseTestTrait;
use Config\Database;
use Tests\Support\AccountingFixtureTrait;
use Tests\Support\AssetFixtureTrait;

/**
 * What an asset has depreciated by, from the opening figure it came with and the runs that stand, and
 * what a period would depreciate it by: a fixed amount from the schedule, the units of a month for the
 * units of production method, never more than is left, and the months no run stands for.
 *
 * @internal
 */
final class AssetFiguresTest extends CIUnitTestCase
{
    use DatabaseTestTrait;
    use AccountingFixtureTrait;
    use AssetFixtureTrait;

    protected $migrate     = true;
    protected $migrateOnce = true;
    protected $refresh     = false;
    protected $namespace;
    private AssetFigures $figures;

    public static function setUpBeforeClass(): void
    {
        $seeder = Database::seeder('tests');
        $seeder->call('TestDatabaseBootstrapSeeder');
    }

    protected function setUp(): void
    {
        parent::setUp();

        $this->clearAssets();
        $this->resetBranches();
        $this->reinstallChart();
        $this->startAssets();

        $this->figures = new AssetFigures(Database::connect(), new DepreciationSchedule(2));
    }

    protected function tearDown(): void
    {
        $this->releaseOpenTransactions();
        $this->clearAssets();
        $this->clearJournals();
        $this->reinstallChart();
        $this->resetBranches();

        parent::tearDown();
    }

    private function row(int $assetId): object
    {
        return $this->accountingDb()->table('assets')->where('asset_id', $assetId)->get()->getRow();
    }

    private function runMonth(string $month): array
    {
        $preview = $this->depreciation->preview(1, $month, $this->assetRunner);

        return $this->depreciation->approve(1, $month, $preview->fingerprint, $this->assetRunner);
    }

    public function testTermsOf_ReadsTheTermsOfAnAssetRow(): void
    {
        $terms = AssetFigures::termsOf($this->row($this->enterAsset(['salvage' => '2000'])));

        $this->assertSame(AssetMethod::Fixed, $terms->method);
        $this->assertSame('12000.0000', $terms->cost);
        $this->assertSame('2000.0000', $terms->salvage);
        $this->assertSame('10000.0000', $terms->base());
        $this->assertSame('1000.0000', $terms->amount);
        $this->assertSame('2026-01-01', $terms->inServiceDate);
        $this->assertFalse($terms->isOpening());
    }

    public function testPosted_GivesTheAmountOfEachMonthThatStandsAndNotThatOfARunTakenBack(): void
    {
        $assetId = $this->enterAsset();
        $this->runMonth('2026-02');
        [$march] = $this->runMonth('2026-03');

        $this->assertSame(['2026-02-01' => '1000.0000', '2026-03-01' => '1000.0000'], $this->figures->posted($assetId));

        $this->depreciation->reverse($march, 'Wrong month', $this->assetRunner);

        $this->assertSame(['2026-02-01' => '1000.0000'], $this->figures->posted($assetId));
        $this->assertSame(['2026-02-01' => '1000.0000'], $this->figures->posted($assetId, true));
    }

    public function testAccumulated_CountsTheOpeningDepreciationAndEveryRunThatStands(): void
    {
        $this->setConfigValue('accounting_start_date', '2026-01-01');
        $opener  = $this->employeeWithGrants([AccountingGuard::ASSETS_MANAGE, AccountingGuard::OPENING_MANAGE]);
        $assetId = $this->enterAsset(['funding' => 'opening', 'treasury_id' => '', 'purchase_date' => '2025-01-01', 'in_service_date' => '2025-01-01', 'opening_accumulated' => '5000'], $opener);

        $this->assertSame('5000.0000', $this->figures->accumulated($this->row($assetId)));

        $this->runMonth('2026-02');

        $this->assertSame('6000.0000', $this->figures->accumulated($this->row($assetId)));
        $this->assertSame('6000.0000', $this->figures->accumulated($this->row($assetId), null, true));
    }

    public function testAccumulated_StopsAtTheDayGiven(): void
    {
        $assetId = $this->enterAsset();
        $this->runMonth('2026-02');
        $this->runMonth('2026-03');

        $this->assertSame('1000.0000', $this->figures->accumulated($this->row($assetId), '2026-02-28'));
        $this->assertSame('1000.0000', $this->figures->accumulated($this->row($assetId), '2026-03-30'), 'a month counts once it has ended');
        $this->assertSame('2000.0000', $this->figures->accumulated($this->row($assetId), '2026-03-31'));
        $this->assertSame('0.0000', $this->figures->accumulated($this->row($assetId), '2026-01-31'));
    }

    public function testDue_GivesTheAmountOfTheScheduleForTheMonth(): void
    {
        $assetId = $this->enterAsset();

        $this->assertSame('1000.0000', $this->figures->due($this->row($assetId), DepreciationPeriod::fromMonth('2026-02'), []));
        $this->assertSame('0.0000', $this->figures->due($this->row($assetId), DepreciationPeriod::fromMonth('2026-01'), []), 'nothing is due before the first depreciation');
    }

    public function testDue_NeverGivesMoreThanIsLeftOfTheCostLessTheSalvage(): void
    {
        $assetId = $this->enterAsset(['cost' => '2500', 'amount_per_period' => '1000']);

        $this->assertSame('500.0000', $this->figures->due($this->row($assetId), DepreciationPeriod::fromMonth('2026-04'), ['2026-02-01' => '1000.0000', '2026-03-01' => '1000.0000']));
        $this->assertSame('0.0000', $this->figures->due($this->row($assetId), DepreciationPeriod::fromMonth('2026-05'), ['2026-02-01' => '1000.0000', '2026-03-01' => '1000.0000', '2026-04-01' => '500.0000']));
    }

    public function testDue_GivesNothingForAnAssetThatIsNotDepreciated(): void
    {
        $assetId = $this->enterAsset(['method' => 'none']);

        $this->assertSame('0.0000', $this->figures->due($this->row($assetId), DepreciationPeriod::fromMonth('2026-02'), []));
    }

    public function testDue_GivesWhatTheUnitsOfTheMonthComeToAtTheCostOfAUnit(): void
    {
        $assetId = $this->enterAsset(['method' => 'units', 'cost_per_unit' => '2.5', 'amount_per_period' => '']);

        $this->assertSame('0.0000', $this->figures->due($this->row($assetId), DepreciationPeriod::fromMonth('2026-02'), []), 'no usage, no depreciation');

        $this->register->recordUsage($assetId, '2026-02', '120', $this->assetClerk);

        $this->assertSame('300.0000', $this->figures->due($this->row($assetId), DepreciationPeriod::fromMonth('2026-02'), []));
        $this->assertSame('300.0000', $this->figures->due($this->row($assetId), DepreciationPeriod::fromMonth('2026-02'), [], true));
    }

    public function testUsage_GivesTheUnitsOfAMonthOrNothing(): void
    {
        $assetId = $this->enterAsset(['method' => 'units', 'cost_per_unit' => '2.5', 'amount_per_period' => '']);
        $period  = DepreciationPeriod::fromMonth('2026-02');

        $this->assertNull($this->figures->usage($assetId, $period));
        $this->assertNull($this->figures->usage($assetId, $period, true));

        $this->register->recordUsage($assetId, '2026-02', '12.5', $this->assetClerk);

        $this->assertSame('12.50', $this->figures->usage($assetId, $period));
        $this->assertSame('12.50', $this->figures->usage($assetId, $period, true));
        $this->assertNull($this->figures->usage($assetId, DepreciationPeriod::fromMonth('2026-03')));
    }

    public function testMissed_NamesTheMonthsBeforeThePeriodThatNoRunStandsFor(): void
    {
        $assetId = $this->enterAsset();
        $this->runMonth('2026-03');

        $posted = $this->figures->posted($assetId);

        $this->assertSame(['2026-02'], $this->figures->missed($this->row($assetId), DepreciationPeriod::fromMonth('2026-04'), $posted));
        $this->assertSame(['2026-02'], $this->figures->missed($this->row($assetId), DepreciationPeriod::fromMonth('2026-04'), $posted, true));
        $this->assertSame([], $this->figures->missed($this->row($assetId), DepreciationPeriod::fromMonth('2026-02'), $posted));
    }

    public function testMissed_NamesTheMonthsOfUsageThatNoRunStandsForForAnAssetOfUnits(): void
    {
        $assetId = $this->enterAsset(['method' => 'units', 'cost_per_unit' => '2.5', 'amount_per_period' => '']);

        $this->register->recordUsage($assetId, '2026-02', '10', $this->assetClerk);
        $this->register->recordUsage($assetId, '2026-03', '10', $this->assetClerk);
        $this->runMonth('2026-02');

        $posted = $this->figures->posted($assetId);

        $this->assertSame(['2026-03'], $this->figures->missed($this->row($assetId), DepreciationPeriod::fromMonth('2026-04'), $posted));
        $this->assertSame(['2026-03'], $this->figures->missed($this->row($assetId), DepreciationPeriod::fromMonth('2026-04'), $posted, true));
    }
}
