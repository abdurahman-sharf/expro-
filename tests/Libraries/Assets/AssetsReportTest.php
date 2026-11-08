<?php

namespace Tests\Libraries\Assets;

use App\Libraries\Accounting\AccountingGuard;
use App\Libraries\Accounting\JournalDraft;
use App\Libraries\Accounting\JournalLineDraft;
use App\Libraries\Accounting\PostingService;
use App\Libraries\Accounting\ReportPeriod;
use App\Libraries\Accounting\ReportScope;
use App\Libraries\Accounting\TrialBalance;
use App\Libraries\Assets\AssetDisposals;
use App\Libraries\Assets\AssetsFilter;
use App\Libraries\Assets\AssetsReport;
use App\Libraries\Assets\AssetStatus;
use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\DatabaseTestTrait;
use Config\Database;
use Tests\Support\AccountingFixtureTrait;
use Tests\Support\AssetFixtureTrait;

/**
 * The assets report: its filters, its groupings, the statuses, the branches of its scope, and that what
 * the register has on the books ties to the books, so that cost less accumulated depreciation is the
 * balance of the two accounts in the trial balance.
 *
 * @internal
 */
final class AssetsReportTest extends CIUnitTestCase
{
    use DatabaseTestTrait;
    use AccountingFixtureTrait;
    use AssetFixtureTrait;

    protected $migrate     = true;
    protected $migrateOnce = true;
    protected $refresh     = false;
    protected $namespace;
    private AssetsReport $report;
    private int $second;

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
        $this->report = new AssetsReport();
        $this->second = $this->createBranch('T2', 'Second branch');
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

    private function company(): ReportScope
    {
        return ReportScope::resolve(null, null);
    }

    private function runMonth(string $month, int $branchId = 1): void
    {
        $preview = $this->depreciation->preview($branchId, $month, $this->assetRunner);

        $this->depreciation->approve($branchId, $month, $preview->fingerprint, $this->assetRunner);
    }

    /**
     * @return array<string, object>
     */
    private function rowsByCode(\App\Libraries\Assets\AssetsReportResult $result): array
    {
        $rows = [];

        foreach ($result->groups as $group) {
            foreach ($group->rows as $row) {
                $rows[$row->code] = $row;
            }
        }

        return $rows;
    }

    private function trialClosing(string $code, string $to = '2026-12-31'): string
    {
        foreach ((new TrialBalance())->build($this->company(), ReportPeriod::of(null, $to))->rows as $row) {
            if ($row->code === $code) {
                return $row->closing();
            }
        }

        return '0.0000';
    }

    public function testBuild_ListsTheAssetsWithTheirFiguresAndTotals(): void
    {
        $this->enterAsset(['code' => 'A-1']);
        $this->enterAsset(['code' => 'A-2', 'name' => 'Chair', 'cost' => '6000', 'amount_per_period' => '500', 'purchase_date' => '2026-01-10', 'in_service_date' => '2026-01-10']);
        $this->runMonth('2026-02');
        $this->runMonth('2026-03');

        $result = $this->report->build($this->company(), new AssetsFilter(asOf: '2026-03-31', depreciationFrom: '2026-03-01', depreciationTo: '2026-03-31'));
        $rows   = $this->rowsByCode($result);

        $this->assertSame(2, $result->count());
        $this->assertSame('12000.0000', $rows['A-1']->cost);
        $this->assertSame('2000.0000', $rows['A-1']->accumulated);
        $this->assertSame('10000.0000', $rows['A-1']->bookValue);
        $this->assertSame('1000.0000', $rows['A-1']->depreciation, 'only March is in the range');
        $this->assertSame('1000.0000', $rows['A-2']->accumulated);
        $this->assertSame('Office desk', $rows['A-1']->name);
        $this->assertSame('HQ', $rows['A-1']->branchCode);
        $this->assertSame(AssetStatus::Active, $rows['A-1']->status);
        $this->assertSame('18000.0000', $result->cost);
        $this->assertSame('3000.0000', $result->accumulated);
        $this->assertSame('15000.0000', $result->bookValue);
        $this->assertSame('1500.0000', $result->depreciation);
    }

    public function testBuild_TiesTheRegisterToTheTrialBalanceOfTheTwoAccounts(): void
    {
        $this->enterAsset(['code' => 'A-1']);
        $this->enterAsset(['code' => 'A-2', 'name' => 'Chair', 'cost' => '6000', 'amount_per_period' => '500']);
        $this->runMonth('2026-02');
        $this->runMonth('2026-03');

        $result = $this->report->build($this->company(), new AssetsFilter(asOf: '2026-03-31'));

        $this->assertTrue($result->ties());
        $this->assertCount(2, $result->tie);
        $this->assertSame('18000.0000', $result->cost);

        $cost         = $this->trialClosing('111006', '2026-03-31');
        $accumulated  = $this->trialClosing('112006', '2026-03-31');

        $this->assertSame('18000.0000', $cost);
        $this->assertSame('-3000.0000', $accumulated);
        $this->assertSame($result->bookValue, bcadd($cost, $accumulated, 4), 'cost less accumulated depreciation is the balance of the two accounts');
    }

    public function testBuild_KeepsTheTieThroughAReversalAnOpeningAssetAndASale(): void
    {
        $this->setConfigValue('accounting_start_date', '2026-01-01');
        $opener = $this->employeeWithGrants([AccountingGuard::ASSETS_MANAGE, AccountingGuard::OPENING_MANAGE]);

        $this->enterAsset(['code' => 'A-1']);
        $this->enterAsset(['code' => 'OLD', 'name' => 'Old press', 'funding' => 'opening', 'treasury_id' => '', 'purchase_date' => '2024-01-01', 'in_service_date' => '2024-01-01', 'opening_accumulated' => '5000'], $opener);
        $sold = $this->enterAsset(['code' => 'A-3', 'name' => 'Van', 'cost' => '3000', 'amount_per_period' => '100']);

        $this->runMonth('2026-02');
        $this->runMonth('2026-03');

        [$march] = array_map(static fn (object $run): int => (int) $run->run_id, array_slice($this->depreciation->runs($this->assetRunner, '2026-03'), 0, 1));

        $this->depreciation->reverse($march, 'Wrong', $this->assetRunner);
        $this->runMonth('2026-03');

        (new AssetDisposals())->sell($sold, ['date' => '2026-04-10', 'proceeds' => '2500', 'treasury_id' => $this->assetTreasury], $this->assetClerk);

        $result = $this->report->build($this->company(), new AssetsFilter(asOf: '2026-04-30'));

        $this->assertTrue($result->ties(), json_encode($result->tie));
        $this->assertSame('24000.0000', $result->cost, 'the van is out of the books');
        $this->assertSame(bcadd($this->trialClosing('111006', '2026-04-30'), $this->trialClosing('112006', '2026-04-30'), 4), $result->bookValue);
    }

    public function testBuild_ShowsASoldAssetAtItsFiguresOnTheDayAndLeavesItOutOfTheTotals(): void
    {
        $this->enterAsset(['code' => 'A-1']);
        $sold = $this->enterAsset(['code' => 'A-2', 'name' => 'Van', 'cost' => '3000', 'amount_per_period' => '100']);

        $this->runMonth('2026-02');

        (new AssetDisposals())->sell($sold, ['date' => '2026-03-05', 'proceeds' => '2800', 'treasury_id' => $this->assetTreasury], $this->assetClerk);

        $result = $this->report->build($this->company(), new AssetsFilter(asOf: '2026-03-31'));
        $rows   = $this->rowsByCode($result);

        $this->assertFalse($rows['A-2']->onTheBooks);
        $this->assertSame('3000.0000', $rows['A-2']->cost);
        $this->assertSame('100.0000', $rows['A-2']->accumulated);
        $this->assertSame(AssetStatus::Sold, $rows['A-2']->status);
        $this->assertSame('2026-03-05', $rows['A-2']->disposedAt);
        $this->assertSame('12000.0000', $result->cost);
        $this->assertTrue($result->ties());

        $before = $this->report->build($this->company(), new AssetsFilter(asOf: '2026-03-01'));

        $this->assertTrue($this->rowsByCode($before)['A-2']->onTheBooks, 'the day before the sale it was still on the books');
        $this->assertSame('15000.0000', $before->cost);
        $this->assertTrue($before->ties());
    }

    public function testBuild_LeavesAnAssetOffTheBooksBeforeItWasBought(): void
    {
        $this->enterAsset(['code' => 'A-1', 'purchase_date' => '2026-06-01', 'in_service_date' => '2026-06-01']);

        $result = $this->report->build($this->company(), new AssetsFilter(asOf: '2026-03-31'));

        $this->assertFalse($this->rowsByCode($result)['A-1']->onTheBooks);
        $this->assertSame('0.0000', $result->cost);
        $this->assertTrue($result->ties());
    }

    public function testBuild_FiltersByStatus(): void
    {
        $this->enterAsset(['code' => 'A-1']);
        $idle = $this->enterAsset(['code' => 'A-2', 'name' => 'Idle']);
        $this->register->setActive($idle, false, $this->assetClerk);
        $gone = $this->enterAsset(['code' => 'A-3', 'name' => 'Gone']);
        (new AssetDisposals())->writeOff($gone, ['date' => '2026-01-20'], $this->assetClerk);

        $all = $this->report->build($this->company(), new AssetsFilter(asOf: '2026-03-31'));

        $this->assertSame(['A-1', 'A-2', 'A-3'], array_keys($this->rowsByCode($all)));
        $this->assertSame(['A-2'], array_keys($this->rowsByCode($this->report->build($this->company(), new AssetsFilter(statuses: [AssetStatus::Inactive])))));
        $this->assertSame(['A-1', 'A-3'], array_keys($this->rowsByCode($this->report->build($this->company(), new AssetsFilter(statuses: [AssetStatus::Active, AssetStatus::WrittenOff])))));
        $this->assertSame([], array_keys($this->rowsByCode($this->report->build($this->company(), new AssetsFilter(statuses: [AssetStatus::Sold])))));
    }

    public function testBuild_FiltersByTheEmployeeTheAssetIsWith(): void
    {
        $employee = $this->employeeWithGrants([]);

        $this->enterAsset(['code' => 'A-1', 'units' => [['serial_number' => 'S-1', 'used_by' => 'employee', 'employee_id' => $employee]]]);
        $this->enterAsset(['code' => 'A-2', 'name' => 'Chair']);

        $result = $this->report->build($this->company(), new AssetsFilter(employeeId: $employee));

        $this->assertSame(['A-1'], array_keys($this->rowsByCode($result)));
        $this->assertSame(['A-1', 'A-2'], array_keys($this->rowsByCode($this->report->build($this->company(), new AssetsFilter()))));
    }

    public function testBuild_FiltersByTheDateOfPurchase(): void
    {
        $this->enterAsset(['code' => 'A-1', 'purchase_date' => '2026-01-05', 'in_service_date' => '2026-01-05']);
        $this->enterAsset(['code' => 'A-2', 'name' => 'Chair', 'purchase_date' => '2026-02-05', 'in_service_date' => '2026-02-05']);
        $this->enterAsset(['code' => 'A-3', 'name' => 'Lamp', 'purchase_date' => '2026-03-05', 'in_service_date' => '2026-03-05']);

        $this->assertSame(['A-2', 'A-3'], array_keys($this->rowsByCode($this->report->build($this->company(), new AssetsFilter(purchaseFrom: '2026-02-01')))));
        $this->assertSame(['A-1', 'A-2'], array_keys($this->rowsByCode($this->report->build($this->company(), new AssetsFilter(purchaseTo: '2026-02-28')))));
        $this->assertSame(['A-2'], array_keys($this->rowsByCode($this->report->build($this->company(), new AssetsFilter(purchaseFrom: '2026-02-05', purchaseTo: '2026-02-05')))), 'both ends are in');
    }

    public function testBuild_FiltersByTheAccountAndTheCategory(): void
    {
        $vehicles = $this->customAccount('111', '111010', 'Vehicles cost');
        $category = $this->categories->save(['name' => 'Vehicles', 'cost_account_id' => $vehicles], $this->assetClerk);

        $this->enterAsset(['code' => 'A-1']);
        $this->enterAsset(['code' => 'A-2', 'name' => 'Van', 'category_id' => $category]);

        $this->assertSame(['A-2'], array_keys($this->rowsByCode($this->report->build($this->company(), new AssetsFilter(accountId: $vehicles)))));
        $this->assertSame(['A-1'], array_keys($this->rowsByCode($this->report->build($this->company(), new AssetsFilter(accountId: $this->accountId('fixed_assets'))))));
        $this->assertSame(['A-1', 'A-2'], array_keys($this->rowsByCode($this->report->build($this->company(), new AssetsFilter(accountId: $this->accountId('accumulated_depreciation'))))), 'the accumulated depreciation account of the assets');
        $this->assertSame(['A-2'], array_keys($this->rowsByCode($this->report->build($this->company(), new AssetsFilter(categoryId: $category)))));
        $this->assertSame('Vehicles', $this->rowsByCode($this->report->build($this->company(), new AssetsFilter()))['A-2']->category);
    }

    public function testBuild_GroupsByStatusInTheOrderOfTheStatuses(): void
    {
        $this->enterAsset(['code' => 'A-1']);
        $idle = $this->enterAsset(['code' => 'A-2', 'name' => 'Idle']);
        $this->register->setActive($idle, false, $this->assetClerk);
        $gone = $this->enterAsset(['code' => 'A-3', 'name' => 'Gone']);
        (new AssetDisposals())->writeOff($gone, ['date' => '2026-01-20'], $this->assetClerk);

        $result = $this->report->build($this->company(), new AssetsFilter(group: 'status', asOf: '2026-03-31'));

        $this->assertSame(['active', 'inactive', 'written_off'], array_map(static fn ($group): string => $group->key, $result->groups));
        $this->assertSame('12000.0000', $result->groups[0]->cost);
        $this->assertSame('12000.0000', $result->groups[1]->cost);
        $this->assertSame('0.0000', $result->groups[2]->cost, 'what was written off is not on the books');
        $this->assertSame('24000.0000', $result->cost);
    }

    public function testBuild_GroupsByTheDayOfGoingIntoServiceAndByTheDayOfPurchase(): void
    {
        $this->enterAsset(['code' => 'A-1', 'purchase_date' => '2026-01-05', 'in_service_date' => '2026-01-09']);
        $this->enterAsset(['code' => 'A-2', 'name' => 'Chair', 'purchase_date' => '2026-01-05', 'in_service_date' => '2026-01-20']);
        $this->enterAsset(['code' => 'A-3', 'name' => 'Lamp', 'purchase_date' => '2026-01-03', 'in_service_date' => '2026-01-09']);

        $byService  = $this->report->build($this->company(), new AssetsFilter(group: 'in_service_date'));
        $byPurchase = $this->report->build($this->company(), new AssetsFilter(group: 'purchase_date'));

        $this->assertSame(['2026-01-09', '2026-01-20'], array_map(static fn ($group): string => $group->key, $byService->groups));
        $this->assertCount(2, $byService->groups[0]->rows);
        $this->assertSame('24000.0000', $byService->groups[0]->cost);
        $this->assertSame(['2026-01-03', '2026-01-05'], array_map(static fn ($group): string => $group->key, $byPurchase->groups));
        $this->assertCount(2, $byPurchase->groups[1]->rows);
        $this->assertCount(1, $this->report->build($this->company(), new AssetsFilter(group: 'all'))->groups);
    }

    public function testBuild_ReadsTheAccumulatedDepreciationThroughTheDayAsked(): void
    {
        $this->enterAsset(['code' => 'A-1']);
        $this->runMonth('2026-02');
        $this->runMonth('2026-03');

        $feb = $this->rowsByCode($this->report->build($this->company(), new AssetsFilter(asOf: '2026-02-28')))['A-1'];
        $mar = $this->rowsByCode($this->report->build($this->company(), new AssetsFilter(asOf: '2026-03-31')))['A-1'];
        $mid = $this->rowsByCode($this->report->build($this->company(), new AssetsFilter(asOf: '2026-03-15')))['A-1'];

        $this->assertSame('1000.0000', $feb->accumulated);
        $this->assertSame('2000.0000', $mar->accumulated);
        $this->assertSame('1000.0000', $mid->accumulated, 'March is not run until its last day');
    }

    public function testBuild_LimitsTheReportToTheBranchesOfTheScope(): void
    {
        $this->enterAsset(['code' => 'A-1']);
        $this->enterAsset(['code' => 'A-2', 'name' => 'Chair', 'branch_id' => $this->second, 'funding' => 'payable', 'treasury_id' => '']);

        $limited = ReportScope::resolve(null, [1]);

        $this->assertSame(['A-1'], array_keys($this->rowsByCode($this->report->build($limited, new AssetsFilter()))));
        $this->assertSame(['A-2'], array_keys($this->rowsByCode($this->report->build(ReportScope::resolve([$this->second], null), new AssetsFilter()))));
        $this->assertSame(['A-1', 'A-2'], array_keys($this->rowsByCode($this->report->build($this->company(), new AssetsFilter()))));
        $this->assertSame('12000.0000', $this->report->build($limited, new AssetsFilter())->cost);
        $this->assertTrue($this->report->build($limited, new AssetsFilter())->ties(), 'the tie reads the books of the branches of the scope only');
    }

    public function testBuild_IsEmptyForAScopeWithoutBranches(): void
    {
        $this->enterAsset(['code' => 'A-1']);

        $result = $this->report->build(ReportScope::resolve(null, []), new AssetsFilter());

        $this->assertSame(0, $result->count());
        $this->assertSame([], $result->tie);
        $this->assertSame('0.0000', $result->cost);
    }

    public function testBuild_IgnoresADeletedAsset(): void
    {
        $assetId = $this->enterAsset(['code' => 'A-1']);

        $this->register->delete($assetId, $this->assetClerk);

        $this->assertSame(0, $this->report->build($this->company(), new AssetsFilter())->count());
    }

    public function testTie_ShowsTheDifferenceWhenAManualJournalMovesTheAccount(): void
    {
        $this->enterAsset(['code' => 'A-1']);

        (new PostingService())->post(new JournalDraft(
            date: '2026-02-10',
            lines: [new JournalLineDraft($this->accountId('fixed_assets'), debit: '500'), new JournalLineDraft($this->accountId('other_payables'), credit: '500')],
            sourceType: JournalDraft::SOURCE_MANUAL,
            branchId: 1,
        ));

        $tie  = $this->report->tie($this->company(), '2026-03-31');
        $cost = array_values(array_filter($tie, static fn ($row): bool => $row->kind === 'cost'))[0];

        $this->assertSame('12000.0000', $cost->register);
        $this->assertSame('12500.0000', $cost->ledger);
        $this->assertSame('500.0000', $cost->difference());
        $this->assertFalse($cost->ties());
    }

    public function testTie_ShowsWhatTheBooksLackForAnAssetEnteredWithoutAJournal(): void
    {
        $this->setConfigValue('accounting_start_date', '2026-01-01');
        $this->enterAsset(['code' => 'OLD', 'funding' => 'none', 'treasury_id' => '', 'opening_accumulated' => '3000']);

        $result = $this->report->build($this->company(), new AssetsFilter(asOf: '2026-03-31'));

        $this->assertFalse($result->ties());
        $this->assertSame(['accumulated', 'cost'], array_map(static fn ($row): string => $row->kind, $result->tie));
        $this->assertSame(['3000.0000', '12000.0000'], array_map(static fn ($row): string => $row->register, $result->tie));
        $this->assertSame(['0.0000', '0.0000'], array_map(static fn ($row): string => $row->ledger, $result->tie));
    }

    public function testTie_NamesTheAccountsOfTheCostAndTheAccumulatedDepreciation(): void
    {
        $this->enterAsset(['code' => 'A-1']);

        $tie = $this->report->tie($this->company());

        $this->assertSame(['accumulated', 'cost'], array_map(static fn ($row): string => $row->kind, $tie));
        $this->assertSame(['112006', '111006'], array_map(static fn ($row): string => $row->code, $tie));
        $this->assertSame($this->accountId('fixed_assets'), $tie[1]->accountId);
    }
}
