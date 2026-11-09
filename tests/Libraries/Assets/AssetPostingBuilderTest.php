<?php

namespace Tests\Libraries\Assets;

use App\Libraries\Accounting\JournalLineDraft;
use App\Libraries\Accounting\JournalOrigin;
use App\Libraries\Accounting\PostingError;
use App\Libraries\Accounting\PostingException;
use App\Libraries\Accounting\RoutingKey;
use App\Libraries\Accounting\RoutingResolver;
use App\Libraries\Accounting\RoutingType;
use App\Libraries\Assets\AssetPostingBuilder;
use App\Libraries\Assets\AssetSources;
use App\Libraries\Assets\DepreciationPeriod;
use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\DatabaseTestTrait;
use Config\Database;
use Tests\Support\AccountingFixtureTrait;
use Tests\Support\AssetFixtureTrait;

/**
 * The journals of the register of fixed assets: what buying, depreciating, selling and writing off an
 * asset debit and credit, the lines each leaves out when it has nothing to say, and the refusal of a
 * routing that was cancelled.
 *
 * @internal
 */
final class AssetPostingBuilderTest extends CIUnitTestCase
{
    use DatabaseTestTrait;
    use AccountingFixtureTrait;
    use AssetFixtureTrait;

    protected $migrate     = true;
    protected $migrateOnce = true;
    protected $refresh     = false;
    protected $namespace;
    private AssetPostingBuilder $builder;

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

        $this->builder = new AssetPostingBuilder(new RoutingResolver(Database::connect()));
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

    /**
     * @param array<string, mixed> $overrides
     */
    private function asset(array $overrides = []): object
    {
        return (object) [
            'asset_id'               => 7,
            'code'                   => '000007',
            'name'                   => 'Office desk',
            'branch_id'              => 1,
            'cost'                   => '12000.0000',
            'cost_account_id'        => $this->accountId('fixed_assets'),
            'accumulated_account_id' => $this->accountId('accumulated_depreciation'),
            'expense_account_id'     => $this->accountId('depreciation_expense'),
            'funding'                => 'treasury',
            'treasury_id'            => $this->assetTreasury,
            'opening_accumulated'    => '0.0000',
            'cost_center_id'         => null,
            ...$overrides,
        ];
    }

    /**
     * @param list<JournalLineDraft> $lines
     *
     * @return array<int, array{debit: string, credit: string}> By account.
     */
    private function byAccount(array $lines): array
    {
        $by = [];

        foreach ($lines as $line) {
            $by[$line->accountId] = ['debit' => $line->debit, 'credit' => $line->credit];
        }

        return $by;
    }

    private function failureOf(callable $action): PostingException
    {
        try {
            $action();
        } catch (PostingException $failure) {
            return $failure;
        }

        $this->fail('Expected a posting failure.');
    }

    public function testAcquisition_DebitsTheCostAndCreditsTheTreasuryTheAssetWasPaidFrom(): void
    {
        $draft = $this->builder->acquisition($this->asset(), '2026-01-01', 1, $this->assetClerk);

        $this->assertSame([
            $this->accountId('fixed_assets')           => ['debit' => '12000.0000', 'credit' => '0'],
            $this->treasuryAccount($this->assetTreasury) => ['debit' => '0', 'credit' => '12000.0000'],
        ], $this->byAccount($draft->lines));
    }

    public function testAcquisition_CreditsTheAccountOfWhatIsOwedForAnAssetBoughtOnCredit(): void
    {
        $draft = $this->builder->acquisition($this->asset(['funding' => 'payable', 'treasury_id' => null]), '2026-01-01', 1, $this->assetClerk);

        $this->assertSame('12000.0000', $this->byAccount($draft->lines)[$this->accountId('other_payables')]['credit']);
        $this->assertCount(2, $draft->lines);
    }

    public function testAcquisition_SplitsAnOpeningAssetBetweenItsAccumulatedDepreciationAndTheOpeningBalance(): void
    {
        $draft = $this->builder->acquisition($this->asset(['funding' => 'opening', 'treasury_id' => null, 'opening_accumulated' => '5000.0000']), '2026-01-01', 1, $this->assetClerk);
        $lines = $this->byAccount($draft->lines);

        $this->assertSame('12000.0000', $lines[$this->accountId('fixed_assets')]['debit']);
        $this->assertSame('5000.0000', $lines[$this->accountId('accumulated_depreciation')]['credit']);
        $this->assertSame('7000.0000', $lines[$this->accountId('opening_balance')]['credit']);
    }

    public function testAcquisition_LeavesOutTheOpeningBalanceWhenTheAssetHadDepreciatedByAllItsCost(): void
    {
        $draft = $this->builder->acquisition($this->asset(['funding' => 'opening', 'treasury_id' => null, 'opening_accumulated' => '12000.0000']), '2026-01-01', 1, $this->assetClerk);

        $this->assertSame([$this->accountId('fixed_assets'), $this->accountId('accumulated_depreciation')], array_map(static fn (JournalLineDraft $line): int => $line->accountId, $draft->lines));
    }

    public function testAcquisition_LeavesOutTheAccumulatedDepreciationWhenAnOpeningAssetHadNone(): void
    {
        $draft = $this->builder->acquisition($this->asset(['funding' => 'opening', 'treasury_id' => null]), '2026-01-01', 1, $this->assetClerk);

        $this->assertSame([$this->accountId('fixed_assets'), $this->accountId('opening_balance')], array_map(static fn (JournalLineDraft $line): int => $line->accountId, $draft->lines));
    }

    public function testAcquisition_GivesNoJournalForAnAssetTheBooksHoldAlready(): void
    {
        $this->assertNull($this->builder->acquisition($this->asset(['funding' => 'none', 'treasury_id' => null]), '2026-01-01', 1, $this->assetClerk));
    }

    public function testAcquisition_NamesTheSourceTheBranchTheRevisionTheDateAndWhoMadeIt(): void
    {
        $user   = $this->builder->acquisition($this->asset(['branch_id' => 3]), '2026-02-03', 2, $this->assetClerk);
        $system = $this->builder->acquisition($this->asset(), '2026-02-03', 1, null);

        $this->assertSame(AssetSources::ACQUISITION, $user->sourceType);
        $this->assertSame(7, $user->sourceId);
        $this->assertSame(2, $user->revision);
        $this->assertSame('2026-02-03', $user->date);
        $this->assertSame(3, $user->branchId);
        $this->assertSame($this->assetClerk, $user->employeeId);
        $this->assertSame(JournalOrigin::User, $user->origin);
        $this->assertSame(JournalOrigin::System, $system->origin);
        $this->assertSame('Fixed asset 000007 Office desk', $user->description);
    }

    public function testAcquisition_RefusesTheRoutingOfWhatIsOwedWhenItWasCancelled(): void
    {
        $this->accountingDb()->table('accounting_routing')->where('routing_key', RoutingKey::AssetPayable->value)->update(['routing_type' => RoutingType::Cancelled->value]);

        $failure = $this->failureOf(fn () => $this->builder->acquisition($this->asset(['funding' => 'payable', 'treasury_id' => null]), '2026-01-01', 1, $this->assetClerk));

        $this->assertSame(PostingError::RoutingRowCancelled, $failure->error);
    }

    public function testDepreciation_DebitsTheExpenseAndCreditsTheAccumulatedDepreciationOfEachAssetOfTheBranch(): void
    {
        $other  = $this->asset(['asset_id' => 8, 'code' => '000008', 'name' => 'Chair', 'cost_center_id' => 5]);
        $period = DepreciationPeriod::fromMonth('2026-02');
        $draft  = $this->builder->depreciation(1, $period, [['asset' => $this->asset(), 'amount' => '1000.0000'], ['asset' => $other, 'amount' => '250.0000']], 41, $this->assetRunner);

        $this->assertSame(AssetSources::DEPRECIATION, $draft->sourceType);
        $this->assertSame(41, $draft->sourceId);
        $this->assertSame('2026-02-28', $draft->date);
        $this->assertSame(1, $draft->branchId);
        $this->assertCount(4, $draft->lines);
        $this->assertSame('1000.0000', $draft->lines[0]->debit);
        $this->assertSame('1000.0000', $draft->lines[1]->credit);
        $this->assertSame($this->accountId('depreciation_expense'), $draft->lines[2]->accountId);
        $this->assertSame($this->accountId('accumulated_depreciation'), $draft->lines[3]->accountId);
        $this->assertNull($draft->lines[0]->costCenterId);
        $this->assertSame(5, $draft->lines[2]->costCenterId, 'the cost centre of the asset is charged on the expense line only');
        $this->assertNull($draft->lines[3]->costCenterId);
        $this->assertStringContainsString('000008', (string) $draft->lines[2]->description);
        $this->assertSame('Depreciation 2026-02', $draft->description);
    }

    public function testSale_PutsTheProceedsInTheTreasuryTakesTheCostAndTheDepreciationOutAndBooksTheGain(): void
    {
        $draft = $this->builder->sale($this->asset(), '2026-05-10', '10000.0000', $this->assetTreasury, '3000.0000', 55, $this->assetClerk);
        $lines = $this->byAccount($draft->lines);

        $this->assertSame('10000.0000', $lines[$this->treasuryAccount($this->assetTreasury)]['debit']);
        $this->assertSame('3000.0000', $lines[$this->accountId('accumulated_depreciation')]['debit']);
        $this->assertSame('12000.0000', $lines[$this->accountId('fixed_assets')]['credit']);
        $this->assertSame('1000.0000', $lines[$this->accountId('asset_gain')]['credit']);
        $this->assertArrayNotHasKey($this->accountId('asset_loss'), $lines);
        $this->assertSame(AssetSources::SALE, $draft->sourceType);
        $this->assertSame(55, $draft->sourceId);
    }

    public function testSale_BooksTheLossWhenTheProceedsAreBelowTheBookValue(): void
    {
        $draft = $this->builder->sale($this->asset(['cost_center_id' => 5]), '2026-05-10', '8000.0000', $this->assetTreasury, '3000.0000', 55, $this->assetClerk);
        $lines = $this->byAccount($draft->lines);

        $this->assertSame('1000.0000', $lines[$this->accountId('asset_loss')]['debit']);
        $this->assertArrayNotHasKey($this->accountId('asset_gain'), $lines);

        $loss = array_values(array_filter($draft->lines, fn (JournalLineDraft $line): bool => $line->accountId === $this->accountId('asset_loss')))[0];

        $this->assertSame(5, $loss->costCenterId);
    }

    public function testSale_PostsNeitherGainNorLossAtTheBookValue(): void
    {
        $draft = $this->builder->sale($this->asset(), '2026-05-10', '9000.0000', $this->assetTreasury, '3000.0000', 55, $this->assetClerk);

        $this->assertCount(3, $draft->lines);
    }

    public function testSale_LeavesOutTheAccumulatedDepreciationOfAnAssetThatHadNone(): void
    {
        $draft = $this->builder->sale($this->asset(), '2026-05-10', '12000.0000', $this->assetTreasury, '0.0000', 55, $this->assetClerk);

        $this->assertSame([$this->treasuryAccount($this->assetTreasury), $this->accountId('fixed_assets')], array_map(static fn (JournalLineDraft $line): int => $line->accountId, $draft->lines));
    }

    public function testSale_RefusesTheRoutingOfTheGainWhenItWasCancelled(): void
    {
        $this->accountingDb()->table('accounting_routing')->where('routing_key', RoutingKey::AssetGain->value)->update(['routing_type' => RoutingType::Cancelled->value]);

        $failure = $this->failureOf(fn () => $this->builder->sale($this->asset(), '2026-05-10', '10000.0000', $this->assetTreasury, '3000.0000', 55, $this->assetClerk));

        $this->assertSame(PostingError::RoutingRowCancelled, $failure->error);
    }

    public function testWriteOff_TakesTheCostAndTheDepreciationOutAndLosesTheBookValue(): void
    {
        $draft = $this->builder->writeOff($this->asset(), '2026-05-10', '3000.0000', 56, $this->assetClerk);
        $lines = $this->byAccount($draft->lines);

        $this->assertSame('3000.0000', $lines[$this->accountId('accumulated_depreciation')]['debit']);
        $this->assertSame('12000.0000', $lines[$this->accountId('fixed_assets')]['credit']);
        $this->assertSame('9000.0000', $lines[$this->accountId('asset_loss')]['debit']);
        $this->assertSame(AssetSources::WRITE_OFF, $draft->sourceType);
    }

    public function testWriteOff_PostsNoLossForAnAssetThatHadDepreciatedByAllItsCost(): void
    {
        $draft = $this->builder->writeOff($this->asset(), '2026-05-10', '12000.0000', 56, $this->assetClerk);

        $this->assertSame([$this->accountId('accumulated_depreciation'), $this->accountId('fixed_assets')], array_map(static fn (JournalLineDraft $line): int => $line->accountId, $draft->lines));
    }
}
