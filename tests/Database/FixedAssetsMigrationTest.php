<?php

namespace Tests\Database;

use App\Database\Migrations\AssetAccounts;
use App\Database\Migrations\FixedAssets;
use App\Libraries\Accounting\DefaultChart;
use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\DatabaseTestTrait;
use Config\Database;
use RuntimeException;
use Tests\Support\AccountingFixtureTrait;
use Tests\Support\AssetFixtureTrait;

/**
 * The two migrations of fixed assets: the tables, the module and the permissions that nobody is granted,
 * the leaf accounts and the routing rows in a chart that exists already, and that both are safe to run
 * again and leave what the owner chose alone.
 *
 * @internal
 */
final class FixedAssetsMigrationTest extends CIUnitTestCase
{
    use DatabaseTestTrait;
    use AccountingFixtureTrait;
    use AssetFixtureTrait;

    protected $migrate     = true;
    protected $migrateOnce = true;
    protected $refresh     = false;
    protected $namespace   = null;

    private const TABLES      = ['asset_categories', 'assets', 'asset_units', 'asset_depreciation_runs', 'asset_depreciations', 'asset_operations', 'asset_usage'];
    private const PERMISSIONS = ['accounting_assets', 'acc_assets_manage', 'acc_assets_depreciate'];
    private const KEYS        = ['fixed_assets' => 'assets.cost', 'accumulated_depreciation' => 'assets.accumulated', 'depreciation_expense' => 'assets.expense', 'asset_gain' => 'assets.gain', 'asset_loss' => 'assets.loss'];

    public static function setUpBeforeClass(): void
    {
        $seeder = Database::seeder('tests');
        $seeder->call('TestDatabaseBootstrapSeeder');
    }

    protected function setUp(): void
    {
        parent::setUp();

        $this->clearAssets();
        $this->reinstallChart();
    }

    protected function tearDown(): void
    {
        (new FixedAssets())->up();
        (new AssetAccounts())->up();

        $this->clearAssets();
        $this->reinstallChart();

        parent::tearDown();
    }

    private function hasTable(string $table): bool
    {
        return $this->accountingDb()->query('SELECT COUNT(*) AS found FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ?', [$this->accountingDb()->prefixTable($table)])->getRow()->found > 0;
    }

    private function accountOf(string $systemKey): ?object
    {
        return $this->accountingDb()->table('accounting_accounts')->where('system_key', $systemKey)->get()->getRow();
    }

    private function routedTo(string $routingKey): ?string
    {
        $row = $this->accountingDb()->table('accounting_routing')->where('routing_key', $routingKey)->get()->getRow();

        return $row === null || $row->account_id === null ? null : (string) $row->account_id;
    }

    /**
     * A chart as it was before the migration: without the accounts and their routing rows, and the account
     * of capital gains and losses without a key.
     */
    private function chartBefore(): void
    {
        (new AssetAccounts())->down();

        $this->accountingDb()->table('accounting_routing')->where('routing_key', 'assets.payable')->delete();
        $this->accountingDb()->table('accounting_accounts')->where('code', '4202')->update(['system_key' => null, 'is_system' => 0]);
    }

    public function testUp_CreatesTheTablesAndRegistersTheModuleAndThePermissionsWithoutGrantingThem(): void
    {
        foreach (self::TABLES as $table) {
            $this->assertTrue($this->hasTable($table), $table);
        }

        $db = $this->accountingDb();

        $this->assertSame(['accounting_assets'], array_column($db->table('modules')->where('module_id', 'accounting_assets')->get()->getResultArray(), 'module_id'));
        $this->assertSame(['acc_assets_depreciate', 'acc_assets_manage', 'accounting_assets'], array_column($db->table('permissions')->whereIn('permission_id', self::PERMISSIONS)->orderBy('permission_id')->get()->getResultArray(), 'permission_id'));
        $this->assertSame(0, $db->table('grants')->whereIn('permission_id', self::PERMISSIONS)->countAllResults());
        $this->assertSame(['accounting_assets', 'accounting_assets', 'accounting_assets'], array_column($db->table('permissions')->whereIn('permission_id', self::PERMISSIONS)->get()->getResultArray(), 'module_id'));
    }

    public function testUp_ChangesNothingWhenItRunsAgain(): void
    {
        $modules     = $this->countRows('modules');
        $permissions = $this->countRows('permissions');
        $accounts    = $this->countRows('accounting_accounts');
        $routing     = $this->countRows('accounting_routing');

        (new FixedAssets())->up();
        (new AssetAccounts())->up();

        $this->assertSame([$modules, $permissions, $accounts, $routing], [$this->countRows('modules'), $this->countRows('permissions'), $this->countRows('accounting_accounts'), $this->countRows('accounting_routing')]);
    }

    public function testUp_KeepsTheAssetsWhenItRunsAgain(): void
    {
        $this->startAssets();
        $assetId = $this->enterAsset();

        (new FixedAssets())->up();

        $this->assertSame(1, $this->accountingDb()->table('assets')->where('asset_id', $assetId)->countAllResults());
    }

    public function testDown_RefusesOnceAnAssetExists(): void
    {
        $this->startAssets();
        $this->enterAsset();

        try {
            (new FixedAssets())->down();
            $this->fail('An asset exists.');
        } catch (RuntimeException $exception) {
            $this->assertTrue($this->hasTable('assets'));
            $this->assertSame(1, $this->countRows('assets'));
        }
    }

    public function testDown_DropsTheTablesTheModuleAndThePermissionsWhenNoAssetExists(): void
    {
        (new FixedAssets())->down();

        foreach (self::TABLES as $table) {
            $this->assertFalse($this->hasTable($table), $table);
        }

        $this->assertSame(0, $this->accountingDb()->table('modules')->where('module_id', 'accounting_assets')->countAllResults());
        $this->assertSame(0, $this->accountingDb()->table('permissions')->whereIn('permission_id', self::PERMISSIONS)->countAllResults());

        (new FixedAssets())->up();

        foreach (self::TABLES as $table) {
            $this->assertTrue($this->hasTable($table), $table);
        }
    }

    public function testAssetsTable_RefusesTwoLiveAssetsWithOneCodeButLetsADeletedOneGoFree(): void
    {
        $this->startAssets();
        $first = $this->enterAsset(['code' => 'DESK']);

        $this->accountingDb()->table('assets')->where('asset_id', $first)->update(['deleted' => 1]);

        $this->assertGreaterThan($first, $this->enterAsset(['code' => 'DESK']));

        $this->expectException(\Throwable::class);

        $this->accountingDb()->table('assets')->insert([...(array) $this->accountingDb()->table('assets')->where('code', 'DESK')->where('deleted', 0)->get()->getRow(), 'asset_id' => null, 'code_slot' => null]);
    }

    public function testAssetAccounts_OpensTheLeafAccountsInAChartThatHasNoneAndRoutesThem(): void
    {
        $this->chartBefore();

        foreach (self::KEYS as $systemKey => $routing) {
            $this->assertNull($systemKey === 'asset_gain' ? null : $this->accountOf($systemKey), $systemKey);
        }

        (new AssetAccounts())->up();

        $expected = [
            'fixed_assets'             => ['111006', '111', 'asset', 'debit'],
            'accumulated_depreciation' => ['112006', '112', 'asset', 'credit'],
            'depreciation_expense'     => ['5301', '53', 'expense', 'debit'],
            'asset_gain'               => ['4202', '42', 'revenue', 'credit'],
            'asset_loss'               => ['5420', '54', 'expense', 'debit'],
        ];

        foreach ($expected as $systemKey => [$code, $parent, $type, $normal]) {
            $account = $this->accountOf($systemKey);

            $this->assertSame([$code, '0', '1', $type, $normal], [$account->code, (string) $account->is_group, (string) $account->is_system, $account->account_type, $account->normal_balance], $systemKey);
            $this->assertSame($parent, $this->accountingDb()->table('accounting_accounts')->where('account_id', $account->parent_id)->get()->getRow()->code, $systemKey);
            $this->assertSame((string) $account->account_id, $this->routedTo(self::KEYS[$systemKey]), $systemKey);
        }

        $this->assertSame((string) $this->accountId('other_payables'), $this->routedTo('assets.payable'), 'what is owed for an asset goes to the account of other payables');
    }

    public function testAssetAccounts_AdoptsTheAccountOfCapitalGainsAndLossesAsTheGainOnADisposal(): void
    {
        $this->chartBefore();

        $legacy = (int) $this->accountingDb()->table('accounting_accounts')->where('code', '4202')->get()->getRow()->account_id;

        (new AssetAccounts())->up();

        $this->assertSame($legacy, (int) $this->accountOf('asset_gain')->account_id);
        $this->assertSame((string) $legacy, $this->routedTo('assets.gain'));
    }

    public function testAssetAccounts_DoesNotAdoptThatAccountWhenItIsKeptForAnotherPurpose(): void
    {
        $this->chartBefore();

        $this->accountingDb()->table('accounting_accounts')->where('code', '4202')->update(['system_key' => 'kept_by_the_owner']);

        (new AssetAccounts())->up();

        $this->assertNotSame('4202', $this->accountOf('asset_gain')->code);
        $this->assertSame('kept_by_the_owner', $this->accountingDb()->table('accounting_accounts')->where('code', '4202')->get()->getRow()->system_key);
    }

    public function testAssetAccounts_TakesTheNextFreeCodeWhenTheFirstOneIsTaken(): void
    {
        $this->chartBefore();
        $this->customAccount('111', '111006', 'Taken by the owner');

        (new AssetAccounts())->up();

        $this->assertSame('111007', $this->accountOf('fixed_assets')->code);
        $this->assertSame('Taken by the owner', $this->accountingDb()->table('accounting_accounts')->where('code', '111006')->get()->getRow()->name);
    }

    public function testAssetAccounts_LeavesOutAnAccountWhoseParentGroupIsMissingFromTheChart(): void
    {
        $this->chartBefore();

        $this->accountingDb()->query('SET FOREIGN_KEY_CHECKS = 0');
        $this->accountingDb()->table('accounting_accounts')->where('code', '53')->delete();
        $this->accountingDb()->query('SET FOREIGN_KEY_CHECKS = 1');

        (new AssetAccounts())->up();

        $this->assertNull($this->accountOf('depreciation_expense'));
        $this->assertNotNull($this->accountOf('fixed_assets'));
    }

    public function testAssetAccounts_KeepsTheRoutingTheOwnerChose(): void
    {
        $this->chartBefore();

        $owned = $this->customAccount('111', '111050', 'Owner assets');

        $this->accountingDb()->table('accounting_routing')->insert(['routing_key' => 'assets.cost', 'routing_type' => 'auto', 'account_id' => $owned]);

        (new AssetAccounts())->up();

        $this->assertSame((string) $owned, $this->routedTo('assets.cost'));
    }

    public function testAssetAccounts_DoesNotTouchAChartThatIsNotInstalledYet(): void
    {
        $db = $this->accountingDb();
        $db->query('SET FOREIGN_KEY_CHECKS = 0');
        $db->table('accounting_routing')->truncate();
        $db->table('accounting_accounts')->truncate();
        $db->query('SET FOREIGN_KEY_CHECKS = 1');

        (new AssetAccounts())->up();

        $this->assertSame(0, $this->countRows('accounting_accounts'));
        $this->assertSame(0, $this->countRows('accounting_routing'));
    }

    public function testAssetAccounts_TakenDownKeepsAnAccountThatHoldsJournalLinesAndFreesTheOthers(): void
    {
        $this->startAssets();
        $this->enterAsset();

        (new AssetAccounts())->down();

        $this->assertNotNull($this->accountOf('fixed_assets'), 'the cost account holds the journal of an asset');
        $this->assertNull($this->accountOf('depreciation_expense'));
        $this->assertNull($this->routedTo('assets.cost'));
        $this->assertNull($this->routedTo('assets.payable'));
    }

    public function testInstall_GivesANewChartTheSameAccountsAndRoutingRows(): void
    {
        foreach (self::KEYS as $systemKey => $routing) {
            $account = $this->accountOf($systemKey);

            $this->assertNotNull($account, $systemKey);
            $this->assertSame((string) $account->account_id, $this->routedTo($routing), $routing);
            $this->assertSame('0', (string) $account->is_group, $systemKey);
        }

        $this->assertSame((string) $this->accountId('other_payables'), $this->routedTo('assets.payable'));
        $this->assertTrue((new DefaultChart($this->accountingDb()))->install() === false, 'a chart that has accounts is not installed again');
    }

    public function testUp_LeavesTheJournalsAndTheBalancesOfTheBooksAlone(): void
    {
        $this->startAssets();
        $this->enterAsset();

        $before = [$this->countRows('accounting_journals'), $this->countRows('accounting_journal_lines'), $this->accountNet($this->accountId('fixed_assets')), $this->accountNet($this->treasuryAccount($this->assetTreasury))];

        (new FixedAssets())->up();
        (new AssetAccounts())->up();

        $this->assertSame($before, [$this->countRows('accounting_journals'), $this->countRows('accounting_journal_lines'), $this->accountNet($this->accountId('fixed_assets')), $this->accountNet($this->treasuryAccount($this->assetTreasury))]);
    }
}
