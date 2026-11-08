<?php

namespace Tests\Libraries\Assets;

use App\Libraries\Accounting\AccountingError;
use App\Libraries\Accounting\AccountingException;
use App\Libraries\Accounting\AccountingGuard;
use App\Libraries\Accounting\JournalService;
use App\Libraries\Accounting\JournalStatus;
use App\Libraries\Accounting\PostingError;
use App\Libraries\Accounting\PostingException;
use App\Libraries\Assets\AssetSources;
use App\Libraries\Assets\AssetStatus;
use App\Libraries\Assets\DepreciationBranchStatus;
use App\Libraries\Assets\DepreciationRun;
use App\Libraries\Organization\BranchError;
use App\Libraries\Organization\BranchException;
use CodeIgniter\Database\BaseConnection;
use CodeIgniter\Database\Exceptions\DatabaseException;
use CodeIgniter\Events\Events;
use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\DatabaseTestTrait;
use Config\Database;
use Tests\Support\AccountingFixtureTrait;
use Tests\Support\AssetFixtureTrait;
use Tests\Support\LockOrderTrait;

/**
 * The depreciation of a month: what the preview shows, the one journal each branch gets, that an asset is
 * depreciated once for a month and never past what it is worth less its salvage, what stops a run (a
 * period that is closed, a month that has not ended, a changed preview, a missing permission), and how a
 * run is taken back.
 *
 * @internal
 */
final class DepreciationRunTest extends CIUnitTestCase
{
    use DatabaseTestTrait;
    use AccountingFixtureTrait;
    use AssetFixtureTrait;
    use LockOrderTrait;

    protected $migrate     = true;
    protected $migrateOnce = true;
    protected $refresh     = false;
    protected $namespace;
    private int $second;
    private ?BaseConnection $other = null;

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
        $this->second = $this->createBranch('T2', 'Second branch');
    }

    protected function tearDown(): void
    {
        if ($this->other !== null) {
            $this->other->transRollback();
            $this->other->close();
            $this->other = null;
        }

        $this->releaseOpenTransactions();
        $this->clearAssets();
        $this->clearJournals();
        $this->reinstallChart();
        $this->resetBranches();

        parent::tearDown();
    }

    private function assertRefused(AccountingError $error, callable $action): AccountingException
    {
        try {
            $action();
        } catch (AccountingException $exception) {
            $this->assertSame($error, $exception->error, $exception->getMessage());

            return $exception;
        }

        $this->fail('Expected the refusal ' . $error->value);
    }

    /**
     * @return list<int> The runs.
     */
    private function runMonth(string $month, ?int $branchId = 1): array
    {
        $preview = $this->depreciation->preview($branchId, $month, $this->assetRunner);

        return $this->depreciation->approve($branchId, $month, $preview->fingerprint, $this->assetRunner);
    }

    private function asset(int $assetId): object
    {
        return $this->accountingDb()->table('assets')->where('asset_id', $assetId)->get()->getRow();
    }

    private function accumulatedNet(): string
    {
        return $this->accountNet($this->accountId('accumulated_depreciation'));
    }

    private function postedRows(int $assetId): int
    {
        return $this->accountingDb()->table('asset_depreciations')->where(['asset_id' => $assetId, 'active_key' => 1])->countAllResults();
    }

    public function testPreview_ShowsWhatTheMonthWouldWriteAndWritesNothing(): void
    {
        $assetId = $this->enterAsset();
        $journals = $this->countRows('accounting_journals');

        $preview = $this->depreciation->preview(1, '2026-02', $this->assetRunner);

        $this->assertSame([], $preview->blockers);
        $this->assertTrue($preview->canApprove());
        $this->assertCount(1, $preview->branches);
        $this->assertSame(DepreciationBranchStatus::Ready, $preview->branches[0]->status);
        $this->assertSame('1000.0000', $preview->branches[0]->total);
        $this->assertSame($assetId, (int) $preview->branches[0]->lines[0]->asset->asset_id);
        $this->assertSame('1000.0000', $preview->branches[0]->lines[0]->amount);
        $this->assertSame('0.0000', $preview->branches[0]->lines[0]->accumulatedBefore);
        $this->assertSame('1000.0000', $preview->branches[0]->lines[0]->accumulatedAfter());
        $this->assertMatchesRegularExpression('/^[0-9a-f]{64}$/', $preview->fingerprint);
        $this->assertSame($journals, $this->countRows('accounting_journals'));
        $this->assertSame(0, $this->countRows('asset_depreciations'));
        $this->assertSame(0, $this->countRows('asset_depreciation_runs'));
    }

    public function testPreview_SaysNothingIsDueForAMonthBeforeTheFirstDepreciation(): void
    {
        $this->enterAsset();

        $preview = $this->depreciation->preview(1, '2026-01', $this->assetRunner);

        $this->assertSame(DepreciationBranchStatus::NothingDue, $preview->branches[0]->status);
        $this->assertFalse($preview->canApprove());
    }

    public function testPreview_RequiresThePermissionToDepreciate(): void
    {
        $this->assertRefused(AccountingError::PermissionDenied, fn () => $this->depreciation->preview(1, '2026-02', $this->assetClerk));
    }

    public function testApprove_RequiresThePermissionToDepreciate(): void
    {
        $this->enterAsset();
        $preview = $this->depreciation->preview(1, '2026-02', $this->assetRunner);

        $this->assertRefused(AccountingError::PermissionDenied, fn () => $this->depreciation->approve(1, '2026-02', $preview->fingerprint, $this->assetClerk));
        $this->assertSame(0, $this->countRows('asset_depreciation_runs'));
    }

    public function testApprove_WritesOneBalancedJournalForTheBranchAndTheMonth(): void
    {
        $first  = $this->enterAsset();
        $second = $this->enterAsset(['name' => 'Chair', 'cost' => '6000', 'amount_per_period' => '500']);

        [$runId] = $this->runMonth('2026-02');

        $run     = $this->accountingDb()->table('asset_depreciation_runs')->where('run_id', $runId)->get()->getRow();
        $journal = $this->accountingDb()->table('accounting_journals')->where('journal_id', $run->journal_id)->get()->getRow();
        $lines   = $this->journalLines((int) $journal->journal_id);

        $this->assertSame(AssetSources::DEPRECIATION, $journal->source_type);
        $this->assertSame($runId, (int) $journal->source_id);
        $this->assertSame('2026-02-28', $journal->journal_date);
        $this->assertSame(1, (int) $journal->branch_id);
        $this->assertSame(JournalStatus::Posted->value, $journal->status);
        $this->assertSame('1500.0000', $journal->total_debit);
        $this->assertSame($journal->total_debit, $journal->total_credit);
        $this->assertCount(4, $lines);
        $this->assertSame([$this->accountId('depreciation_expense'), $this->accountId('accumulated_depreciation'), $this->accountId('depreciation_expense'), $this->accountId('accumulated_depreciation')], array_map(static fn (object $line): int => (int) $line->account_id, $lines));
        $this->assertSame(['1000.0000', '0.0000', '500.0000', '0.0000'], array_map(static fn (object $line): string => $line->debit, $lines));
        $this->assertSame('1500.0000', $run->total);
        $this->assertSame(2, (int) $run->line_count);
        $this->assertSame(1, $this->postedRows($first));
        $this->assertSame(1, $this->postedRows($second));
        $this->assertSame('-1500.0000', $this->accumulatedNet());
        $this->assertSame('1500.0000', $this->accountNet($this->accountId('depreciation_expense')));
        $this->assertSame('1000.0000', $this->register->find($first)->accumulated);
    }

    public function testApprove_WritesAnEntryOfTheAuditLog(): void
    {
        $this->enterAsset();

        [$runId] = $this->runMonth('2026-02');

        $entry = $this->accountingDb()->table('accounting_audit_log')->where(['entity_type' => 'asset_depreciation_run', 'entity_id' => $runId, 'action' => 'asset_depreciation_run'])->get()->getRow();

        $this->assertNotNull($entry);
        $this->assertSame($this->assetRunner, (int) $entry->employee_id);
        $this->assertSame(1, (int) $entry->branch_id);
    }

    public function testApprove_DepreciatesAnAssetOnceForAMonth(): void
    {
        $assetId = $this->enterAsset();
        $preview = $this->depreciation->preview(1, '2026-02', $this->assetRunner);

        $this->depreciation->approve(1, '2026-02', $preview->fingerprint, $this->assetRunner);

        $this->assertRefused(AccountingError::AssetNothingDue, fn () => $this->depreciation->approve(1, '2026-02', $preview->fingerprint, $this->assetRunner));
        $this->assertSame(1, $this->postedRows($assetId));
        $this->assertSame(1, $this->accountingDb()->table('accounting_journals')->where('source_type', AssetSources::DEPRECIATION)->countAllResults());
        $this->assertSame('-1000.0000', $this->accumulatedNet());
    }

    public function testApprove_HoldsAnAssetAndAMonthToOneLineThatStandsWhateverTheCodeDoes(): void
    {
        $assetId = $this->enterAsset();

        [$runId] = $this->runMonth('2026-02');

        $this->expectException(DatabaseException::class);

        $this->accountingDb()->table('asset_depreciations')->insert(['company_id' => 1, 'branch_id' => 1, 'asset_id' => $assetId, 'run_id' => $runId, 'period_start' => '2026-02-01', 'amount' => '1.0000', 'active_key' => 1]);
    }

    public function testApprove_RefusesThePreviewOfAnotherState(): void
    {
        $this->enterAsset();
        $preview = $this->depreciation->preview(1, '2026-02', $this->assetRunner);

        $this->enterAsset(['name' => 'Chair', 'cost' => '6000', 'amount_per_period' => '500']);

        $this->assertRefused(AccountingError::AssetRunChanged, fn () => $this->depreciation->approve(1, '2026-02', $preview->fingerprint, $this->assetRunner));
        $this->assertRefused(AccountingError::AssetRunChanged, fn () => $this->depreciation->approve(1, '2026-02', str_repeat('0', 64), $this->assetRunner));
        $this->assertSame(0, $this->countRows('asset_depreciation_runs'));
        $this->assertSame(0, $this->accountingDb()->table('accounting_journals')->where('source_type', AssetSources::DEPRECIATION)->countAllResults());
    }

    public function testApprove_NeverDepreciatesPastCostLessSalvageAndMarksTheAssetConsumed(): void
    {
        $assetId = $this->enterAsset(['cost' => '10500', 'salvage' => '500', 'amount_per_period' => '3000']);
        $amounts = [];

        foreach (['2026-02', '2026-03', '2026-04', '2026-05'] as $month) {
            $this->runMonth($month);
            $amounts[] = $this->accountingDb()->table('asset_depreciations')->where(['asset_id' => $assetId, 'period_start' => $month . '-01'])->get()->getRow()->amount;
        }

        $this->assertSame(['3000.0000', '3000.0000', '3000.0000', '1000.0000'], $amounts);
        $this->assertSame(AssetStatus::Consumed->value, $this->asset($assetId)->status);
        $this->assertSame('10000.0000', $this->register->find($assetId)->accumulated);
        $this->assertSame('500.0000', $this->register->find($assetId)->book_value);

        $this->assertSame(DepreciationBranchStatus::NothingDue, $this->depreciation->preview(1, '2026-06', $this->assetRunner)->branches[0]->status);
        $this->assertSame('-10000.0000', $this->accumulatedNet());
    }

    public function testApprove_RefusesAMonthThatHasNotEnded(): void
    {
        $this->enterAsset(['purchase_date' => date('Y-m-01', strtotime('-2 months')), 'in_service_date' => date('Y-m-01', strtotime('-2 months'))]);

        $month = date('Y-m');

        $preview = $this->depreciation->preview(1, $month, $this->assetRunner);

        $this->assertSame(AccountingError::AssetPeriodNotEnded, $preview->blockers[0]->error);
        $this->assertFalse($preview->canApprove());
        $this->assertRefused(AccountingError::AssetPeriodNotEnded, fn () => $this->depreciation->approve(1, $month, $preview->fingerprint, $this->assetRunner));
        $this->assertSame(0, $this->countRows('asset_depreciation_runs'));
    }

    public function testApprove_RefusesAMonthThatIsNotAMonth(): void
    {
        foreach (['2026-13', '2026', 'march', '2026-02-01'] as $month) {
            $this->assertRefused(AccountingError::AssetPeriodInvalid, fn () => $this->depreciation->preview(1, $month, $this->assetRunner));
        }
    }

    public function testApprove_RefusesAPeriodThatIsClosedByNameAndWritesNothing(): void
    {
        $assetId = $this->enterAsset();

        $this->closeThrough('2026-02-28', 1);

        $preview = $this->depreciation->preview(1, '2026-02', $this->assetRunner);

        $this->assertSame(DepreciationBranchStatus::Blocked, $preview->branches[0]->status);
        $this->assertSame(AccountingError::AssetPeriodClosed, $preview->blockers[0]->error);
        $this->assertSame('2026-02-28', $preview->blockers[0]->context['until']);
        $this->assertSame('HQ', $preview->blockers[0]->context['scope_name'], 'the branch that closed the period');

        $exception = $this->assertRefused(AccountingError::AssetPeriodClosed, fn () => $this->depreciation->approve(1, '2026-02', $preview->fingerprint, $this->assetRunner));

        $this->assertStringContainsString('2026-02', \App\Libraries\Accounting\AccountingMessage::of($exception));
        $this->assertSame(0, $this->postedRows($assetId));
        $this->assertSame(0, $this->countRows('asset_depreciation_runs'));
    }

    public function testApprove_NamesTheCompanyWhenTheCompanyClosedThePeriod(): void
    {
        $this->enterAsset();

        $this->closeThrough('2026-03-31');

        $preview = $this->depreciation->preview(1, '2026-02', $this->assetRunner);

        $company = (string) $this->accountingDb()->table('companies')->get()->getRow()->name;

        $this->assertSame($company, $preview->blockers[0]->context['scope_name']);
    }

    public function testApprove_RunsAMonthAfterTheClosedOnes(): void
    {
        $this->enterAsset();
        $this->closeThrough('2026-02-28', 1);

        $this->assertCount(1, $this->runMonth('2026-03'));
    }

    public function testApprove_OnlyRunsTheBranchOfTheRunAndLeavesTheOthersAlone(): void
    {
        $this->enterAsset(['branch_id' => $this->second, 'funding' => 'payable', 'treasury_id' => '']);
        $mine = $this->enterAsset(['name' => 'Chair']);

        $this->runMonth('2026-02', 1);

        $this->assertSame(1, $this->postedRows($mine));
        $this->assertSame(1, $this->countRows('asset_depreciations'));
        $this->assertSame('1000.0000', $this->accountNet($this->accountId('depreciation_expense'), 1));
        $this->assertSame('0.0000', $this->accountNet($this->accountId('depreciation_expense'), $this->second));
    }

    public function testApprove_WritesOneJournalForEachBranchWhenTheCompanyIsRun(): void
    {
        $this->enterAsset();
        $this->enterAsset(['branch_id' => $this->second, 'funding' => 'payable', 'treasury_id' => '', 'name' => 'Chair', 'amount_per_period' => '500']);

        $runs = $this->runMonth('2026-02', null);

        $this->assertCount(2, $runs);
        $this->assertSame([1, $this->second], array_map(static fn (object $run): int => (int) $run->branch_id, $this->accountingDb()->table('asset_depreciation_runs')->orderBy('run_id', 'ASC')->get()->getResult()));
        $this->assertSame('1000.0000', $this->accountNet($this->accountId('depreciation_expense'), 1));
        $this->assertSame('500.0000', $this->accountNet($this->accountId('depreciation_expense'), $this->second));
    }

    public function testApprove_TakesTheLocksOfTheBranchesInTheOrderOfTheirNumbers(): void
    {
        $this->enterAsset();
        $this->enterAsset(['branch_id' => $this->second, 'funding' => 'payable', 'treasury_id' => '', 'name' => 'Chair']);

        $preview = $this->depreciation->preview(null, '2026-02', $this->assetRunner);

        $order = $this->periodLocksTakenBy(fn () => $this->depreciation->approve(null, '2026-02', $preview->fingerprint, $this->assetRunner));

        $this->assertSame([1, $this->second], array_slice($order, 0, 2));
    }

    public function testApprove_RefusesARunOfTheCompanyToAnEmployeeOfOneBranch(): void
    {
        $limited = $this->employeeWithGrants([AccountingGuard::ASSETS_DEPRECIATE], [1]);

        try {
            $this->depreciation->preview(null, '2026-02', $limited);
            $this->fail('The company needs every branch.');
        } catch (BranchException $exception) {
            $this->assertSame(0, $this->countRows('asset_depreciation_runs'));
        }
    }

    public function testApprove_RefusesABranchOutsideTheScopeOfTheEmployee(): void
    {
        $this->enterAsset(['branch_id' => $this->second, 'funding' => 'payable', 'treasury_id' => '']);
        $limited = $this->employeeWithGrants([AccountingGuard::ASSETS_DEPRECIATE], [1]);

        try {
            $this->depreciation->preview($this->second, '2026-02', $limited);
            $this->fail('The employee works in branch 1 only.');
        } catch (BranchException $exception) {
            $this->assertSame(0, $this->countRows('asset_depreciation_runs'));
        }

        $this->enterAsset(['name' => 'Chair']);

        $this->assertCount(1, $this->depreciation->approve(1, '2026-02', $this->depreciation->preview(1, '2026-02', $limited)->fingerprint, $limited), 'the branch of the employee');
    }

    public function testPreview_RefusesABranchThatDoesNotExist(): void
    {
        try {
            $this->depreciation->preview(9999, '2026-02', $this->assetRunner);
            $this->fail('There is no branch 9999.');
        } catch (BranchException $exception) {
            $this->assertSame(BranchError::NotFound, $exception->error);
        }
    }

    public function testApprove_LeavesOutAnAssetOutOfServiceOneThatIsNotDepreciatedAndOneThatWasDisposedOf(): void
    {
        $inactive = $this->enterAsset(['name' => 'Idle']);
        $none     = $this->enterAsset(['name' => 'Land', 'method' => 'none']);
        $sold     = $this->enterAsset(['name' => 'Old']);
        $live     = $this->enterAsset(['name' => 'Live']);

        $this->register->setActive($inactive, false, $this->assetClerk);
        $this->accountingDb()->table('assets')->where('asset_id', $sold)->update(['status' => 'sold']);

        $this->runMonth('2026-02');

        $this->assertSame(1, $this->countRows('asset_depreciations'));
        $this->assertSame(1, $this->postedRows($live));
        $this->assertSame(0, $this->postedRows($inactive) + $this->postedRows($none) + $this->postedRows($sold));
    }

    public function testApprove_WritesASecondRunForAnAssetEnteredAfterTheFirstOfTheMonth(): void
    {
        $first = $this->enterAsset();

        [$runOne] = $this->runMonth('2026-02');

        $late = $this->enterAsset(['name' => 'Late desk', 'amount_per_period' => '400']);

        $preview = $this->depreciation->preview(1, '2026-02', $this->assetRunner);

        $this->assertCount(1, $preview->branches[0]->lines, 'only the asset the first run left out');
        $this->assertSame($late, (int) $preview->branches[0]->lines[0]->asset->asset_id);

        [$runTwo] = $this->depreciation->approve(1, '2026-02', $preview->fingerprint, $this->assetRunner);

        $this->assertNotSame($runOne, $runTwo);
        $this->assertSame([1, 2], array_map(static fn (object $run): int => (int) $run->run_number, $this->accountingDb()->table('asset_depreciation_runs')->orderBy('run_id', 'ASC')->get()->getResult()));
        $this->assertSame(1, $this->postedRows($first));
        $this->assertSame(1, $this->postedRows($late));
        $this->assertSame('-1400.0000', $this->accumulatedNet());
    }

    public function testApprove_ShowsTheEarlierMonthsThatWereNotRunAndPostsOnlyTheMonthAsked(): void
    {
        $assetId = $this->enterAsset();

        $preview = $this->depreciation->preview(1, '2026-03', $this->assetRunner);

        $this->assertSame(['2026-02'], $preview->branches[0]->lines[0]->missedMonths);
        $this->assertSame('1000.0000', $preview->branches[0]->lines[0]->amount, 'the amount of March, not of the two months');

        $this->depreciation->approve(1, '2026-03', $preview->fingerprint, $this->assetRunner);

        $this->assertSame(['2026-02'], $this->depreciation->preview(1, '2026-04', $this->assetRunner)->branches[0]->lines[0]->missedMonths);

        $this->runMonth('2026-02');

        $this->assertSame([], $this->depreciation->preview(1, '2026-04', $this->assetRunner)->branches[0]->lines[0]->missedMonths);
        $this->assertSame(2, $this->postedRows($assetId));
        $this->assertSame('2000.0000', $this->register->find($assetId)->accumulated);
    }

    public function testApprove_TakesTheMonthsInAnyOrderToTheSameTotalForTheDecliningMethod(): void
    {
        $assetId = $this->enterAsset(['method' => 'declining', 'percentage' => '10', 'amount_per_period' => '', 'cost' => '12500', 'salvage' => '500']);

        $this->runMonth('2026-03');
        $this->runMonth('2026-02');

        $rows = $this->accountingDb()->table('asset_depreciations')->where('asset_id', $assetId)->orderBy('period_start', 'ASC')->get()->getResult();

        $this->assertSame(['1200.0000', '1080.0000'], array_map(static fn (object $row): string => $row->amount, $rows));
    }

    public function testApprove_DepreciatesAnAssetByTheUnitsItWasUsedFor(): void
    {
        $assetId = $this->enterAsset(['method' => 'units', 'cost_per_unit' => '2.5', 'total_units' => '4800', 'amount_per_period' => '', 'cost' => '12000']);

        $this->assertSame(DepreciationBranchStatus::NothingDue, $this->depreciation->preview(1, '2026-02', $this->assetRunner)->branches[0]->status, 'no usage, no depreciation');

        $this->register->recordUsage($assetId, '2026-02', '120', $this->assetClerk);

        $preview = $this->depreciation->preview(1, '2026-02', $this->assetRunner);

        $this->assertSame('300.0000', $preview->branches[0]->lines[0]->amount);
        $this->assertSame('120.00', $preview->branches[0]->lines[0]->units);

        $this->depreciation->approve(1, '2026-02', $preview->fingerprint, $this->assetRunner);

        $row = $this->accountingDb()->table('asset_depreciations')->where('asset_id', $assetId)->get()->getRow();

        $this->assertSame('300.0000', $row->amount);
        $this->assertSame('120.00', $row->units);
        $this->assertSame('-300.0000', $this->accumulatedNet());
    }

    public function testApprove_CutsTheUnitsOfProductionToWhatIsLeftToDepreciate(): void
    {
        $assetId = $this->enterAsset(['method' => 'units', 'cost_per_unit' => '2.5', 'amount_per_period' => '', 'cost' => '1000']);

        $this->register->recordUsage($assetId, '2026-02', '300', $this->assetClerk);
        $this->register->recordUsage($assetId, '2026-03', '300', $this->assetClerk);
        $this->runMonth('2026-02');
        $this->runMonth('2026-03');

        $amounts = array_map(static fn (object $row): string => $row->amount, $this->accountingDb()->table('asset_depreciations')->where('asset_id', $assetId)->orderBy('period_start', 'ASC')->get()->getResult());

        $this->assertSame(['750.0000', '250.0000'], $amounts);
        $this->assertSame(AssetStatus::Consumed->value, $this->asset($assetId)->status);
    }

    public function testApprove_ContinuesFromTheNextPeriodForAnAssetBroughtInWithItsDepreciation(): void
    {
        $this->setConfigValue('accounting_start_date', '2026-01-01');
        $opener  = $this->employeeWithGrants([AccountingGuard::ASSETS_MANAGE, AccountingGuard::OPENING_MANAGE]);
        $assetId = $this->enterAsset(['funding' => 'opening', 'treasury_id' => '', 'purchase_date' => '2024-01-01', 'in_service_date' => '2024-01-01', 'opening_accumulated' => '5000'], $opener);

        $this->assertSame(DepreciationBranchStatus::NothingDue, $this->depreciation->preview(1, '2025-12', $this->assetRunner)->branches[0]->status, 'what came before the books started is in the opening figure');

        foreach (['2026-01', '2026-02', '2026-03', '2026-04', '2026-05', '2026-06', '2026-07'] as $month) {
            $this->runMonth($month);
        }

        $this->assertSame(DepreciationBranchStatus::NothingDue, $this->depreciation->preview(1, '2026-08', $this->assetRunner)->branches[0]->status);
        $this->assertSame('12000.0000', $this->register->find($assetId)->accumulated);
        $this->assertSame(AssetStatus::Consumed->value, $this->asset($assetId)->status);
        $this->assertSame('-12000.0000', $this->accumulatedNet(), 'the opening 5,000 and the seven months after it');
    }

    public function testApprove_ChargesTheCostCenterOfTheAssetOnTheExpenseLine(): void
    {
        $center  = (new \App\Libraries\Accounting\CostCenterService())->create(['code' => 'CC1', 'name' => 'Workshop']);
        $assetId = $this->enterAsset(['cost_center_id' => $center]);

        [$runId] = $this->runMonth('2026-02');

        $lines = $this->journalLines((int) $this->journalOf(AssetSources::DEPRECIATION, $runId)->journal_id);

        $this->assertSame($center, (int) $lines[0]->cost_center_id);
        $this->assertNull($lines[1]->cost_center_id);
        $this->assertNotSame(0, $assetId);
    }

    public function testApprove_RecordsTheFailureThePostingRefusesAndWritesNothing(): void
    {
        $assetId = $this->enterAsset();

        $this->accountingDb()->table('accounting_accounts')->where('account_id', $this->accountId('accumulated_depreciation'))->update(['active' => 0]);

        try {
            $this->runMonth('2026-02');
            $this->fail('The accumulated depreciation account was switched off.');
        } catch (PostingException $exception) {
            $this->assertSame(PostingError::AccountNotPostable, $exception->error);
        }

        $this->assertSame(0, $this->postedRows($assetId));
        $this->assertSame(0, $this->countRows('asset_depreciation_runs'));
        $this->assertSame(1, $this->accountingDb()->table('accounting_posting_failures')->where('source_type', AssetSources::DEPRECIATION)->countAllResults());
    }

    public function testApprove_RefusesAMonthOutsideEveryFiscalYear(): void
    {
        $this->enterAsset();

        $this->accountingDb()->table('accounting_fiscal_years')->where('name', '2026')->delete();

        try {
            $this->runMonth('2026-02');
            $this->fail('No fiscal year covers the month.');
        } catch (PostingException $exception) {
            $this->assertContains($exception->error, [PostingError::OutsideFiscalYear, PostingError::PeriodClosed]);
        }

        $this->assertSame(0, $this->countRows('asset_depreciation_runs'));
    }

    public function testApprove_WritesOnlyOneRunWhenAnotherRequestRunsTheMonthFirst(): void
    {
        $assetId = $this->enterAsset();
        $preview = $this->depreciation->preview(1, '2026-02', $this->assetRunner);

        $this->other = $this->openSecondConnection();
        $this->startWithSnapshot($this->other);

        $this->depreciation->approve(1, '2026-02', $preview->fingerprint, $this->assetRunner);

        $this->assertRefused(AccountingError::AssetNothingDue, fn () => (new DepreciationRun($this->other))->approve(1, '2026-02', $preview->fingerprint, $this->assetRunner));

        $this->assertSame(1, $this->postedRows($assetId));
        $this->assertSame(1, $this->accountingDb()->table('accounting_journals')->where('source_type', AssetSources::DEPRECIATION)->countAllResults());
        $this->assertSame('-1000.0000', $this->accumulatedNet());
    }

    public function testApprove_WaitsForTheLockOfABranchAnotherRequestHolds(): void
    {
        $this->enterAsset();
        $preview = $this->depreciation->preview(1, '2026-02', $this->assetRunner);

        $this->other = $this->openSecondConnection();
        $this->other->transStart();
        (new \App\Libraries\Accounting\PeriodLocks($this->other))->holdBranch(1);

        $this->assertWaitsForLock(fn () => $this->depreciation->approve(1, '2026-02', $preview->fingerprint, $this->assetRunner));

        $this->assertSame(0, $this->countRows('asset_depreciation_runs'));
    }

    public function testReverse_LowersTheAccumulatedDepreciationExactlyAndFreesTheMonth(): void
    {
        $assetId = $this->enterAsset();

        [$runId] = $this->runMonth('2026-02');

        $journal  = $this->accountingDb()->table('asset_depreciation_runs')->where('run_id', $runId)->get()->getRow()->journal_id;
        $reversal = $this->depreciation->reverse($runId, 'Posted by mistake', $this->assetRunner);

        $run = $this->accountingDb()->table('asset_depreciation_runs')->where('run_id', $runId)->get()->getRow();

        $this->assertSame('reversed', $run->status);
        $this->assertSame($reversal, (int) $run->reversal_journal_id);
        $this->assertSame('Posted by mistake', $run->reverse_reason);
        $this->assertSame(JournalStatus::Reversed->value, $this->accountingDb()->table('accounting_journals')->where('journal_id', $journal)->get()->getRow()->status);
        $this->assertSame('2026-02-28', $this->accountingDb()->table('accounting_journals')->where('journal_id', $reversal)->get()->getRow()->journal_date, 'on the day of the run');
        $this->assertSame('0.0000', $this->accumulatedNet());
        $this->assertSame('0.0000', $this->accountNet($this->accountId('depreciation_expense')));
        $this->assertSame(0, $this->postedRows($assetId));
        $this->assertSame('0.0000', $this->register->find($assetId)->accumulated);

        $this->assertCount(1, $this->runMonth('2026-02'), 'the month is run again');
        $this->assertSame('-1000.0000', $this->accumulatedNet());
        $this->assertSame(1, $this->postedRows($assetId));
    }

    public function testReverse_PutsAConsumedAssetBackInService(): void
    {
        $assetId = $this->enterAsset(['cost' => '2000', 'amount_per_period' => '1000']);

        $this->runMonth('2026-02');
        [$runId] = $this->runMonth('2026-03');

        $this->assertSame(AssetStatus::Consumed->value, $this->asset($assetId)->status);

        $this->depreciation->reverse($runId, 'Wrong month', $this->assetRunner);

        $this->assertSame(AssetStatus::Active->value, $this->asset($assetId)->status);
        $this->assertSame('1000.0000', $this->register->find($assetId)->accumulated);
    }

    public function testReverse_RefusesAPeriodThatIsClosedAndKeepsTheRun(): void
    {
        $assetId = $this->enterAsset();

        [$runId] = $this->runMonth('2026-02');

        $this->closeThrough('2026-02-28');

        try {
            $this->depreciation->reverse($runId, 'Too late', $this->assetRunner);
            $this->fail('February is closed.');
        } catch (PostingException $exception) {
            $this->assertSame(PostingError::PeriodClosed, $exception->error);
        }

        $this->assertSame('active', $this->accountingDb()->table('asset_depreciation_runs')->where('run_id', $runId)->get()->getRow()->status);
        $this->assertSame(1, $this->postedRows($assetId));
        $this->assertSame('-1000.0000', $this->accumulatedNet());
    }

    public function testReverse_RequiresAReasonAndThePermission(): void
    {
        $this->enterAsset();

        [$runId] = $this->runMonth('2026-02');

        $this->assertRefused(AccountingError::ReasonRequired, fn () => $this->depreciation->reverse($runId, '  ', $this->assetRunner));
        $this->assertRefused(AccountingError::ReasonRequired, fn () => $this->depreciation->reverse($runId, str_repeat('x', 256), $this->assetRunner));
        $this->assertRefused(AccountingError::PermissionDenied, fn () => $this->depreciation->reverse($runId, 'No right', $this->assetClerk));
        $this->assertSame('active', $this->accountingDb()->table('asset_depreciation_runs')->where('run_id', $runId)->get()->getRow()->status);
    }

    public function testReverse_RefusesARunThatDoesNotExistOrIsTakenBackAlready(): void
    {
        $this->enterAsset();

        [$runId] = $this->runMonth('2026-02');

        $this->depreciation->reverse($runId, 'Once', $this->assetRunner);

        $this->assertRefused(AccountingError::AssetRunReversed, fn () => $this->depreciation->reverse($runId, 'Twice', $this->assetRunner));
        $this->assertRefused(AccountingError::AssetRunNotFound, fn () => $this->depreciation->reverse(999999, 'Never', $this->assetRunner));
        $this->assertSame(1, $this->accountingDb()->table('accounting_journals')->where('source_type', 'reversal')->countAllResults());
    }

    public function testReverse_RefusesARunOfABranchOutsideTheScopeOfTheEmployee(): void
    {
        $this->enterAsset(['branch_id' => $this->second, 'funding' => 'payable', 'treasury_id' => '']);

        [$runId] = $this->runMonth('2026-02', $this->second);

        $limited = $this->employeeWithGrants([AccountingGuard::ASSETS_DEPRECIATE], [1]);

        try {
            $this->depreciation->reverse($runId, 'Not mine', $limited);
            $this->fail('The employee works in branch 1 only.');
        } catch (BranchException $exception) {
            $this->assertSame('active', $this->accountingDb()->table('asset_depreciation_runs')->where('run_id', $runId)->get()->getRow()->status);
        }
    }

    public function testReverse_RefusesARunWhoseAssetWasDisposedOfSince(): void
    {
        $assetId = $this->enterAsset();

        [$runId] = $this->runMonth('2026-02');

        $this->accountingDb()->table('asset_operations')->insert(['company_id' => 1, 'branch_id' => 1, 'asset_id' => $assetId, 'kind' => 'write_off', 'operation_date' => '2026-03-15', 'cost_removed' => '12000', 'accumulated_removed' => '1000', 'result_amount' => '-11000', 'previous_status' => 'active', 'status' => 'active', 'active_key' => 1]);

        $exception = $this->assertRefused(AccountingError::AssetRunHasDisposal, fn () => $this->depreciation->reverse($runId, 'Too late', $this->assetRunner));

        $this->assertSame($this->accountingDb()->table('assets')->where('asset_id', $assetId)->get()->getRow()->code, $exception->context['name']);
        $this->assertSame('-1000.0000', $this->accumulatedNet());
    }

    public function testReverse_IsNotDoneFromTheJournalScreen(): void
    {
        $this->enterAsset();

        [$runId] = $this->runMonth('2026-02');

        $journalId = (int) $this->accountingDb()->table('asset_depreciation_runs')->where('run_id', $runId)->get()->getRow()->journal_id;
        $reviewer  = $this->employeeWithGrants([AccountingGuard::JOURNALS_REVERSE, AccountingGuard::JOURNALS_ALL]);

        $this->assertRefused(AccountingError::DocumentJournalLocked, fn () => (new JournalService())->reverse($journalId, '2026-02-28', 'Directly', $reviewer));
        $this->assertSame('active', $this->accountingDb()->table('asset_depreciation_runs')->where('run_id', $runId)->get()->getRow()->status);
    }

    public function testRuns_ListsTheRunsOfTheBranchesTheEmployeeMaySeeAndFindsOneWithItsLines(): void
    {
        $this->enterAsset();
        $this->enterAsset(['branch_id' => $this->second, 'funding' => 'payable', 'treasury_id' => '', 'name' => 'Chair']);

        [$mine]  = $this->runMonth('2026-02', 1);
        [$other] = $this->runMonth('2026-02', $this->second);

        $limited = $this->employeeWithGrants([AccountingGuard::ASSETS_DEPRECIATE], [1]);

        $this->assertCount(2, $this->depreciation->runs($this->assetRunner));
        $this->assertSame([$mine], array_map(static fn (object $run): int => (int) $run->run_id, $this->depreciation->runs($limited)));
        $this->assertCount(2, $this->depreciation->runs($this->assetRunner, '2026-02'));
        $this->assertCount(0, $this->depreciation->runs($this->assetRunner, '2026-03'));

        $found = $this->depreciation->find($mine, $limited);

        $this->assertCount(1, $found->lines);
        $this->assertSame('1000.0000', $found->lines[0]->amount);
        $this->assertRefused(AccountingError::AssetRunNotFound, fn () => $this->depreciation->find($other, $limited));
    }

    public function testApprove_NeverPostsMoreThanIsLeftWhateverTheScheduleSays(): void
    {
        $assetId = $this->enterAsset(['cost' => '12000', 'amount_per_period' => '1000']);
        $db      = $this->accountingDb();

        $db->table('asset_depreciation_runs')->insert(['company_id' => 1, 'branch_id' => 1, 'period_start' => '2025-12-01', 'period_end' => '2025-12-31', 'run_number' => 1, 'total' => '11500', 'line_count' => 1, 'fingerprint' => str_repeat('a', 64), 'status' => 'active']);
        $db->table('asset_depreciations')->insert(['company_id' => 1, 'branch_id' => 1, 'asset_id' => $assetId, 'run_id' => (int) $db->insertID(), 'period_start' => '2025-12-01', 'amount' => '11500', 'active_key' => 1]);

        $preview = $this->depreciation->preview(1, '2026-02', $this->assetRunner);

        $this->assertSame('500.0000', $preview->branches[0]->lines[0]->amount, 'only 500 of the 12,000 is left, though the schedule says 1,000');

        $this->depreciation->approve(1, '2026-02', $preview->fingerprint, $this->assetRunner);

        $this->assertSame(DepreciationBranchStatus::NothingDue, $this->depreciation->preview(1, '2026-03', $this->assetRunner)->branches[0]->status);
        $this->assertSame(AssetStatus::Consumed->value, $this->asset($assetId)->status);
    }

    public function testApprove_DepreciatesAnAssetByTheDaysOfAnIntervalOfDays(): void
    {
        $assetId = $this->enterAsset(['cost' => '3650', 'amount_per_period' => '10', 'period_length' => '1', 'period_interval' => 'day', 'in_service_date' => '2026-01-29', 'purchase_date' => '2026-01-29']);

        $this->runMonth('2026-02');

        $this->assertSame('280.0000', $this->accountingDb()->table('asset_depreciations')->where('asset_id', $assetId)->get()->getRow()->amount, 'one amount a day for the 28 days of February');
    }

    public function testApprove_LeavesOutAnAssetThatWasDeleted(): void
    {
        $mistake = $this->enterAsset(['name' => 'Mistake']);
        $live    = $this->enterAsset(['name' => 'Live']);

        $this->register->delete($mistake, $this->assetClerk);

        $preview = $this->depreciation->preview(1, '2026-02', $this->assetRunner);

        $this->assertSame([$live], array_map(static fn (object $line): int => (int) $line->asset->asset_id, $preview->branches[0]->lines));
    }

    public function testApprove_LeavesOutAnAssetThatIsNotInServiceYetWhateverUsageWasEntered(): void
    {
        $press = $this->enterAsset(['name' => 'Press', 'method' => 'units', 'cost_per_unit' => '2', 'amount_per_period' => '', 'in_service_date' => '2026-03-01']);

        $this->register->recordUsage($press, '2026-02', '100', $this->assetClerk);

        $preview = $this->depreciation->preview(1, '2026-02', $this->assetRunner);

        $this->assertSame([], $preview->branches[0]->lines);
        $this->assertSame(DepreciationBranchStatus::NothingDue, $preview->branches[0]->status);
    }

    public function testReverse_RefusesAReasonLongerThanTwoHundredAndFiftyFiveCharacters(): void
    {
        $this->enterAsset();

        [$runId] = $this->runMonth('2026-02');

        $this->assertRefused(AccountingError::ReasonRequired, fn () => $this->depreciation->reverse(999999, '', $this->assetRunner));
        $this->assertRefused(AccountingError::ReasonRequired, fn () => $this->depreciation->reverse($runId, str_repeat('x', 256), $this->assetRunner));
        $this->assertRefused(AccountingError::ReasonRequired, fn () => $this->depreciation->reverse($runId, '   ', $this->assetRunner));
        $this->assertSame('active', $this->accountingDb()->table('asset_depreciation_runs')->where('run_id', $runId)->get()->getRow()->status);

        $this->depreciation->reverse($runId, str_repeat('x', 255), $this->assetRunner);

        $this->assertSame('reversed', $this->accountingDb()->table('asset_depreciation_runs')->where('run_id', $runId)->get()->getRow()->status);
    }

    public function testReverse_WritesAnEntryOfTheAuditLog(): void
    {
        $this->enterAsset();

        [$runId] = $this->runMonth('2026-02');

        $reversal = $this->depreciation->reverse($runId, 'Wrong month', $this->assetRunner);

        $entry = $this->accountingDb()->table('accounting_audit_log')->where(['entity_type' => 'asset_depreciation_run', 'entity_id' => $runId, 'action' => 'asset_depreciation_reversed'])->get()->getRow();

        $this->assertNotNull($entry);
        $this->assertSame($this->assetRunner, (int) $entry->employee_id);

        $details = json_decode((string) $entry->details, true);

        $this->assertSame($reversal, $details['reversal_id']);
        $this->assertSame('Wrong month', $details['reason']);
    }

    public function testApprove_RefusesAPreviewWhoseAmountChangedWhileTheAssetsStayedTheSame(): void
    {
        $press = $this->enterAsset(['name' => 'Press', 'method' => 'units', 'cost_per_unit' => '2', 'amount_per_period' => '']);

        $this->register->recordUsage($press, '2026-02', '100', $this->assetClerk);

        $preview = $this->depreciation->preview(1, '2026-02', $this->assetRunner);

        $this->register->recordUsage($press, '2026-02', '150', $this->assetClerk);

        $this->assertRefused(AccountingError::AssetRunChanged, fn () => $this->depreciation->approve(1, '2026-02', $preview->fingerprint, $this->assetRunner));
        $this->assertSame(0, $this->countRows('asset_depreciation_runs'));
    }

    public function testApprove_ReadsTheAssetsOfTheBranchUnderASharedLockWhereThePreviewTakesNone(): void
    {
        $this->enterAsset();

        $preview = $this->depreciation->preview(1, '2026-02', $this->assetRunner);
        $assets  = preg_quote($this->accountingDb()->prefixTable('assets'), '/');
        $read    = static fn (array $queries): array => array_values(array_filter($queries, static fn (string $query): bool => preg_match('/FROM ' . $assets . ' WHERE company_id = \d+ AND branch_id = \d+ AND deleted = 0/', $query) === 1));

        $previewReads = $read($this->queriesOf(fn () => $this->depreciation->preview(1, '2026-02', $this->assetRunner)));
        $approveReads = $read($this->queriesOf(fn () => $this->depreciation->approve(1, '2026-02', $preview->fingerprint, $this->assetRunner)));

        $this->assertCount(1, $previewReads);
        $this->assertStringNotContainsString('LOCK IN SHARE MODE', $previewReads[0]);
        $this->assertCount(1, $approveReads);
        $this->assertStringContainsString('LOCK IN SHARE MODE', $approveReads[0]);
    }

    public function testApprove_CountsTheDepreciationAnOldAssetArrivedWithAgainstWhatIsLeft(): void
    {
        $this->setConfigValue('accounting_start_date', '2026-01-01');

        $opener  = $this->employeeWithGrants([AccountingGuard::ASSETS_MANAGE, AccountingGuard::OPENING_MANAGE]);
        $assetId = $this->enterAsset(['funding' => 'opening', 'treasury_id' => '', 'purchase_date' => '2024-01-01', 'in_service_date' => '2024-01-01', 'opening_accumulated' => '11000'], $opener);
        $db      = $this->accountingDb();

        $db->table('asset_depreciation_runs')->insert(['company_id' => 1, 'branch_id' => 1, 'period_start' => '2025-12-01', 'period_end' => '2025-12-31', 'run_number' => 1, 'total' => '800', 'line_count' => 1, 'fingerprint' => str_repeat('a', 64), 'status' => 'active']);
        $db->table('asset_depreciations')->insert(['company_id' => 1, 'branch_id' => 1, 'asset_id' => $assetId, 'run_id' => (int) $db->insertID(), 'period_start' => '2025-12-01', 'amount' => '800', 'active_key' => 1]);

        $preview = $this->depreciation->preview(1, '2026-01', $this->assetRunner);

        $this->assertSame('200.0000', $preview->branches[0]->lines[0]->amount, '12,000 less the 11,000 it arrived with less the 800 that stands');
    }

    public function testApprove_ShowsTheEarlierMonthsOfUsageThatWereNotRunForAnAssetOfUnits(): void
    {
        $press = $this->enterAsset(['name' => 'Press', 'method' => 'units', 'cost_per_unit' => '2', 'amount_per_period' => '']);

        $this->register->recordUsage($press, '2026-02', '100', $this->assetClerk);
        $this->register->recordUsage($press, '2026-03', '50', $this->assetClerk);

        $this->assertSame(['2026-02'], $this->depreciation->preview(1, '2026-03', $this->assetRunner)->branches[0]->lines[0]->missedMonths);

        $this->runMonth('2026-02');

        $this->assertSame([], $this->depreciation->preview(1, '2026-03', $this->assetRunner)->branches[0]->lines[0]->missedMonths);
    }

    /**
     * @return list<string> The queries the action runs, in order.
     */
    private function queriesOf(callable $action): array
    {
        $queries  = [];
        $listener = static function ($query) use (&$queries): void {
            $queries[] = (string) $query->getQuery();
        };

        Events::on('DBQuery', $listener);

        try {
            $action();
        } finally {
            Events::removeListener('DBQuery', $listener);
        }

        return $queries;
    }

    /**
     * @param list<string> $queries
     *
     * @return list<int> The branches whose period lock is taken exclusively, in the order they are.
     */
    private function exclusiveLocksIn(array $queries): array
    {
        $branches = [];

        foreach ($queries as $query) {
            if (preg_match('/accounting_period_locks WHERE company_id = \d+ AND branch_id = (\d+) FOR UPDATE/', $query, $found) === 1 && (int) $found[1] !== 0) {
                $branches[] = (int) $found[1];
            }
        }

        return $branches;
    }

    public function testApprove_HoldsTheLockOfEachBranchExclusivelyBeforeItReadsTheAssets(): void
    {
        $this->enterAsset();
        $this->enterAsset(['branch_id' => $this->second, 'funding' => 'payable', 'treasury_id' => '', 'name' => 'Chair']);

        $preview = $this->depreciation->preview(null, '2026-02', $this->assetRunner);
        $assets  = preg_quote($this->accountingDb()->prefixTable('assets'), '/');
        $queries = $this->queriesOf(fn () => $this->depreciation->approve(null, '2026-02', $preview->fingerprint, $this->assetRunner));

        $this->assertSame([1, $this->second], $this->exclusiveLocksIn($queries));

        $firstRead = null;

        foreach ($queries as $index => $query) {
            if (preg_match('/FROM ' . $assets . ' WHERE company_id/', $query) === 1) {
                $firstRead = $index;

                break;
            }
        }

        $lastLock = 0;

        foreach ($queries as $index => $query) {
            if (preg_match('/accounting_period_locks WHERE company_id = \d+ AND branch_id = \d+ FOR UPDATE/', $query) === 1) {
                $lastLock = $index;
            }
        }

        $this->assertNotNull($firstRead);
        $this->assertLessThan($firstRead, $lastLock, 'the locks of every branch of the run are held before the first asset is read');
    }

    public function testReverse_HoldsTheLockOfTheBranchOfTheRunExclusively(): void
    {
        $this->enterAsset();

        [$runId] = $this->runMonth('2026-02');

        $queries = $this->queriesOf(fn () => $this->depreciation->reverse($runId, 'Wrong month', $this->assetRunner));

        $this->assertSame([1], array_values(array_unique($this->exclusiveLocksIn($queries))));
    }
}
