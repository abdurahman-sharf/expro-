<?php

namespace Tests\Libraries\Assets;

use App\Libraries\Accounting\AccountingError;
use App\Libraries\Accounting\AccountingException;
use App\Libraries\Accounting\AccountingGuard;
use App\Libraries\Accounting\CostCenterService;
use App\Libraries\Accounting\JournalDraft;
use App\Libraries\Accounting\JournalLineDraft;
use App\Libraries\Accounting\JournalService;
use App\Libraries\Accounting\JournalStatus;
use App\Libraries\Accounting\PostingError;
use App\Libraries\Accounting\PostingException;
use App\Libraries\Accounting\PostingService;
use App\Libraries\Accounting\TreasuryService;
use App\Libraries\Assets\AssetDisposals;
use App\Libraries\Assets\AssetSources;
use App\Libraries\Assets\AssetStatus;
use App\Libraries\Organization\BranchException;
use CodeIgniter\Database\BaseConnection;
use CodeIgniter\Database\Exceptions\DatabaseException;
use CodeIgniter\Events\Events;
use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\DatabaseTestTrait;
use Config\Database;
use Tests\Support\AccountingFixtureTrait;
use Tests\Support\AssetFixtureTrait;

/**
 * Selling an asset and writing it off: the journals and the gain or loss they leave, what stops a
 * disposal (depreciation not run, a date that is not possible, a period that is closed, a missing
 * permission) and how a disposal is taken back. A revaluation and a sale on credit are refused.
 *
 * @internal
 */
final class AssetDisposalsTest extends CIUnitTestCase
{
    use DatabaseTestTrait;
    use AccountingFixtureTrait;
    use AssetFixtureTrait;

    protected $migrate     = true;
    protected $migrateOnce = true;
    protected $refresh     = false;
    protected $namespace;
    private AssetDisposals $disposals;
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
        $this->disposals = new AssetDisposals();
        $this->second    = $this->createBranch('T2', 'Second branch');
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

    private function asset(int $assetId): object
    {
        return $this->accountingDb()->table('assets')->where('asset_id', $assetId)->get()->getRow();
    }

    private function operation(int $operationId): object
    {
        return $this->accountingDb()->table('asset_operations')->where('operation_id', $operationId)->get()->getRow();
    }

    /**
     * An asset bought for 12,000 and depreciated by 1,000 for February, March and April.
     */
    private function depreciatedAsset(array $overrides = []): int
    {
        $assetId = $this->enterAsset($overrides);

        foreach (['2026-02', '2026-03', '2026-04'] as $month) {
            $preview = $this->depreciation->preview((int) $this->asset($assetId)->branch_id, $month, $this->assetRunner);

            $this->depreciation->approve((int) $this->asset($assetId)->branch_id, $month, $preview->fingerprint, $this->assetRunner);
        }

        return $assetId;
    }

    /**
     * @return array<int, array{debit: string, credit: string}> By account.
     */
    private function linesByAccount(int $journalId): array
    {
        $by = [];

        foreach ($this->journalLines($journalId) as $line) {
            $by[(int) $line->account_id] = ['debit' => $line->debit, 'credit' => $line->credit];
        }

        return $by;
    }

    public function testSell_PutsTheProceedsInTheTreasuryTakesTheCostAndTheDepreciationOutAndBooksTheGain(): void
    {
        $assetId = $this->depreciatedAsset();

        $operationId = $this->disposals->sell($assetId, ['date' => '2026-05-10', 'proceeds' => '10000', 'treasury_id' => $this->assetTreasury, 'note' => 'To a dealer'], $this->assetClerk);

        $operation = $this->operation($operationId);
        $journal   = $this->journalOf(AssetSources::SALE, $operationId);
        $lines     = $this->linesByAccount((int) $journal->journal_id);

        $this->assertSame('2026-05-10', $journal->journal_date);
        $this->assertSame(JournalStatus::Posted->value, $journal->status);
        $this->assertSame('13000.0000', $journal->total_debit);
        $this->assertSame($journal->total_debit, $journal->total_credit);
        $this->assertSame(['debit' => '10000.0000', 'credit' => '0.0000'], $lines[$this->treasuryAccount($this->assetTreasury)]);
        $this->assertSame(['debit' => '3000.0000', 'credit' => '0.0000'], $lines[$this->accountId('accumulated_depreciation')]);
        $this->assertSame(['debit' => '0.0000', 'credit' => '12000.0000'], $lines[$this->accountId('fixed_assets')]);
        $this->assertSame(['debit' => '0.0000', 'credit' => '1000.0000'], $lines[$this->accountId('asset_gain')]);
        $this->assertSame('1000.0000', $operation->result_amount);
        $this->assertSame('12000.0000', $operation->cost_removed);
        $this->assertSame('3000.0000', $operation->accumulated_removed);
        $this->assertSame('0.0000', $this->accountNet($this->accountId('fixed_assets')), 'the cost is out of the books');
        $this->assertSame('0.0000', $this->accountNet($this->accountId('accumulated_depreciation')), 'and so is what it had depreciated by');
        $this->assertSame('-1000.0000', $this->accountNet($this->accountId('asset_gain')));
        $this->assertSame('-2000.0000', $this->accountNet($this->treasuryAccount($this->assetTreasury)), 'paid 12,000 and received 10,000');
    }

    public function testSell_BooksTheLossWhenTheProceedsAreBelowTheBookValue(): void
    {
        $assetId = $this->depreciatedAsset();

        $operationId = $this->disposals->sell($assetId, ['date' => '2026-05-10', 'proceeds' => '8000', 'treasury_id' => $this->assetTreasury], $this->assetClerk);

        $lines = $this->linesByAccount((int) $this->journalOf(AssetSources::SALE, $operationId)->journal_id);

        $this->assertSame(['debit' => '1000.0000', 'credit' => '0.0000'], $lines[$this->accountId('asset_loss')]);
        $this->assertArrayNotHasKey($this->accountId('asset_gain'), $lines);
        $this->assertSame('-1000.0000', $this->operation($operationId)->result_amount);
        $this->assertSame('1000.0000', $this->accountNet($this->accountId('asset_loss')));
    }

    public function testSell_AtTheBookValuePostsNeitherGainNorLoss(): void
    {
        $assetId = $this->depreciatedAsset();

        $operationId = $this->disposals->sell($assetId, ['date' => '2026-05-10', 'proceeds' => '9000', 'treasury_id' => $this->assetTreasury], $this->assetClerk);

        $this->assertCount(3, $this->journalLines((int) $this->journalOf(AssetSources::SALE, $operationId)->journal_id));
        $this->assertSame('0.0000', $this->operation($operationId)->result_amount);
    }

    public function testSell_MarksTheAssetSoldAndKeepsItInTheRegister(): void
    {
        $assetId = $this->depreciatedAsset();

        $operationId = $this->disposals->sell($assetId, ['date' => '2026-05-10', 'proceeds' => '9000', 'treasury_id' => $this->assetTreasury], $this->assetClerk);

        $asset = $this->asset($assetId);

        $this->assertSame(AssetStatus::Sold->value, $asset->status);
        $this->assertSame('2026-05-10', $asset->disposed_at);
        $this->assertSame(0, (int) $asset->deleted);
        $this->assertSame('active', $this->operation($operationId)->status);
        $this->assertNotNull($this->accountingDb()->table('accounting_audit_log')->where(['entity_type' => 'asset_operation', 'entity_id' => $operationId, 'action' => 'asset_sold'])->get()->getRow());

        $preview = $this->depreciation->preview(1, '2026-05', $this->assetRunner);

        $this->assertFalse($preview->canApprove(), 'a sold asset is not depreciated');
    }

    public function testSell_RefusesASaleOnCreditToACustomer(): void
    {
        $assetId = $this->depreciatedAsset();

        $this->assertRefused(AccountingError::AssetSaleOnCredit, fn () => $this->disposals->sell($assetId, ['date' => '2026-05-10', 'proceeds' => '9000', 'customer_id' => $this->insertCustomer()], $this->assetClerk));
        $this->assertSame(AssetStatus::Active->value, $this->asset($assetId)->status);
        $this->assertSame(0, $this->countRows('asset_operations'));
    }

    public function testSell_RefusesProceedsThatAreNotAnAmountAboveZero(): void
    {
        $assetId = $this->depreciatedAsset();

        foreach (['', '0', '-5', 'abc', '10.12345'] as $proceeds) {
            $this->assertRefused(AccountingError::AssetProceedsInvalid, fn () => $this->disposals->sell($assetId, ['date' => '2026-05-10', 'proceeds' => $proceeds, 'treasury_id' => $this->assetTreasury], $this->assetClerk));
        }

        $this->assertSame(0, $this->countRows('asset_operations'));
    }

    public function testSell_RefusesATreasuryThatIsMissingSwitchedOffOrOfAnotherBranch(): void
    {
        $assetId = $this->depreciatedAsset();
        $other   = (new TreasuryService())->create(['name' => 'Other branch cash ' . uniqid(), 'kind' => 'cash', 'branch_id' => $this->second], $this->assetClerk);
        $off     = (new TreasuryService())->create(['name' => 'Closed cash ' . uniqid(), 'kind' => 'cash', 'branch_id' => 1], $this->assetClerk);

        $this->accountingDb()->table('treasuries')->where('treasury_id', $off)->update(['active' => 0]);

        foreach ([null, 999999, $other, $off] as $treasury) {
            $this->assertRefused(AccountingError::AssetProceedsInvalid, fn () => $this->disposals->sell($assetId, ['date' => '2026-05-10', 'proceeds' => '9000', 'treasury_id' => $treasury], $this->assetClerk));
        }

        $this->assertSame(0, $this->countRows('asset_operations'));
    }

    public function testSell_RequiresThePermissionToManageTheRegister(): void
    {
        $assetId = $this->depreciatedAsset();

        $this->assertRefused(AccountingError::PermissionDenied, fn () => $this->disposals->sell($assetId, ['date' => '2026-05-10', 'proceeds' => '9000', 'treasury_id' => $this->assetTreasury], $this->employeeWithGrants([])));
        $this->assertSame(AssetStatus::Active->value, $this->asset($assetId)->status);
    }

    public function testSell_HidesAnAssetOfABranchTheEmployeeDoesNotWorkIn(): void
    {
        $assetId = $this->enterAsset(['branch_id' => $this->second, 'funding' => 'payable', 'treasury_id' => '']);
        $limited = $this->employeeWithGrants([AccountingGuard::ASSETS_MANAGE], [1]);

        $this->assertRefused(AccountingError::AssetNotFound, fn () => $this->disposals->writeOff($assetId, ['date' => '2026-05-10'], $limited));
        $this->assertSame(AssetStatus::Active->value, $this->asset($assetId)->status);
    }

    public function testSell_RefusesAnAssetThatWasDisposedOfAlready(): void
    {
        $assetId = $this->depreciatedAsset();

        $this->disposals->writeOff($assetId, ['date' => '2026-05-10'], $this->assetClerk);

        $this->assertRefused(AccountingError::AssetStatusInvalid, fn () => $this->disposals->sell($assetId, ['date' => '2026-05-11', 'proceeds' => '9000', 'treasury_id' => $this->assetTreasury], $this->assetClerk));
        $this->assertSame(1, $this->countRows('asset_operations'));
    }

    public function testSell_RefusesAnAssetThatIsDueDepreciationForEarlierMonths(): void
    {
        $assetId = $this->enterAsset();

        $exception = $this->assertRefused(AccountingError::AssetDepreciationPending, fn () => $this->disposals->sell($assetId, ['date' => '2026-04-10', 'proceeds' => '9000', 'treasury_id' => $this->assetTreasury], $this->assetClerk));

        $this->assertSame('2026-02, 2026-03', $exception->context['name']);
        $this->assertSame(AssetStatus::Active->value, $this->asset($assetId)->status);

        foreach (['2026-02', '2026-03'] as $month) {
            $preview = $this->depreciation->preview(1, $month, $this->assetRunner);

            $this->depreciation->approve(1, $month, $preview->fingerprint, $this->assetRunner);
        }

        $this->assertGreaterThan(0, $this->disposals->sell($assetId, ['date' => '2026-04-10', 'proceeds' => '9000', 'treasury_id' => $this->assetTreasury], $this->assetClerk));
    }

    public function testSell_RefusesADateBeforeADepreciationThatStands(): void
    {
        $assetId = $this->depreciatedAsset();

        $this->assertRefused(AccountingError::AssetDisposalDateInvalid, fn () => $this->disposals->sell($assetId, ['date' => '2026-03-20', 'proceeds' => '9000', 'treasury_id' => $this->assetTreasury], $this->assetClerk));
        $this->assertSame(0, $this->countRows('asset_operations'));
    }

    public function testSell_RefusesADateBeforeServiceAfterTodayOrThatIsNotADate(): void
    {
        $assetId = $this->enterAsset();

        foreach (['2025-12-31', date('Y-m-d', strtotime('+1 day')), '2026-02-30', '', 'soon'] as $date) {
            $this->assertRefused(AccountingError::AssetDisposalDateInvalid, fn () => $this->disposals->writeOff($assetId, ['date' => $date], $this->assetClerk));
        }

        $this->assertSame(0, $this->countRows('asset_operations'));
    }

    public function testSell_RefusesAPeriodThatIsClosedSavesNothingAndRecordsTheRefusal(): void
    {
        $assetId = $this->depreciatedAsset();

        $this->closeThrough('2026-05-31');

        try {
            $this->disposals->sell($assetId, ['date' => '2026-05-10', 'proceeds' => '9000', 'treasury_id' => $this->assetTreasury], $this->assetClerk);
            $this->fail('May is closed.');
        } catch (PostingException $exception) {
            $this->assertSame(PostingError::PeriodClosed, $exception->error);
        }

        $this->assertSame(AssetStatus::Active->value, $this->asset($assetId)->status);
        $this->assertSame(0, $this->countRows('asset_operations'));
        $this->assertSame('12000.0000', $this->accountNet($this->accountId('fixed_assets')));
        $this->assertSame(1, $this->accountingDb()->table('accounting_posting_failures')->where('source_type', AssetSources::SALE)->countAllResults());
    }

    public function testWriteOff_BooksTheBookValueAsALoss(): void
    {
        $assetId = $this->depreciatedAsset();

        $operationId = $this->disposals->writeOff($assetId, ['date' => '2026-05-10', 'note' => 'Stolen'], $this->assetClerk);

        $lines = $this->linesByAccount((int) $this->journalOf(AssetSources::WRITE_OFF, $operationId)->journal_id);

        $this->assertSame(['debit' => '3000.0000', 'credit' => '0.0000'], $lines[$this->accountId('accumulated_depreciation')]);
        $this->assertSame(['debit' => '9000.0000', 'credit' => '0.0000'], $lines[$this->accountId('asset_loss')]);
        $this->assertSame(['debit' => '0.0000', 'credit' => '12000.0000'], $lines[$this->accountId('fixed_assets')]);
        $this->assertSame('-9000.0000', $this->operation($operationId)->result_amount);
        $this->assertSame('Stolen', $this->operation($operationId)->note);
        $this->assertSame(AssetStatus::WrittenOff->value, $this->asset($assetId)->status);
        $this->assertSame('0.0000', $this->accountNet($this->accountId('fixed_assets')));
        $this->assertSame('9000.0000', $this->accountNet($this->accountId('asset_loss')));
    }

    public function testWriteOff_ChargesTheLossToTheCostCenterOfTheAsset(): void
    {
        $center  = (new CostCenterService())->create(['code' => 'CC1', 'name' => 'Workshop']);
        $assetId = $this->depreciatedAsset(['cost_center_id' => $center]);

        $operationId = $this->disposals->writeOff($assetId, ['date' => '2026-05-10'], $this->assetClerk);

        $lossAccount = $this->accountId('asset_loss');

        foreach ($this->journalLines((int) $this->journalOf(AssetSources::WRITE_OFF, $operationId)->journal_id) as $line) {
            $this->assertSame((int) $line->account_id === $lossAccount ? $center : null, $line->cost_center_id === null ? null : (int) $line->cost_center_id);
        }
    }

    public function testWriteOff_OfAnAssetThatIsDepreciatedByAllItIsWorthPostsNoLoss(): void
    {
        $assetId = $this->enterAsset(['cost' => '2000', 'amount_per_period' => '1000']);

        foreach (['2026-02', '2026-03'] as $month) {
            $preview = $this->depreciation->preview(1, $month, $this->assetRunner);

            $this->depreciation->approve(1, $month, $preview->fingerprint, $this->assetRunner);
        }

        $operationId = $this->disposals->writeOff($assetId, ['date' => '2026-05-10'], $this->assetClerk);

        $this->assertCount(2, $this->journalLines((int) $this->journalOf(AssetSources::WRITE_OFF, $operationId)->journal_id));
        $this->assertSame('0.0000', $this->accountNet($this->accountId('asset_loss')));
        $this->assertSame('consumed', $this->operation($operationId)->previous_status);
    }

    public function testWriteOff_OfAnAssetThatWasNeverDepreciatedTakesAllItsCostAsALoss(): void
    {
        $assetId = $this->enterAsset(['method' => 'none']);

        $operationId = $this->disposals->writeOff($assetId, ['date' => '2026-05-10'], $this->assetClerk);

        $this->assertCount(2, $this->journalLines((int) $this->journalOf(AssetSources::WRITE_OFF, $operationId)->journal_id));
        $this->assertSame('12000.0000', $this->accountNet($this->accountId('asset_loss')));
    }

    public function testRevalue_IsRefusedByName(): void
    {
        $assetId = $this->depreciatedAsset();

        $this->assertRefused(AccountingError::AssetRevaluationRefused, fn () => $this->disposals->revalue($assetId, ['value' => '20000'], $this->assetClerk));
        $this->assertRefused(AccountingError::PermissionDenied, fn () => $this->disposals->revalue($assetId, ['value' => '20000'], $this->employeeWithGrants([])));
        $this->assertSame(1, $this->accountingDb()->table('accounting_journals')->where('source_type', AssetSources::ACQUISITION)->countAllResults() + $this->countRows('asset_operations') * 0);
        $this->assertSame('12000.0000', $this->asset($assetId)->cost);
    }

    public function testReverse_TakesTheDisposalBackAndPutsTheAssetBackAsItWas(): void
    {
        $assetId = $this->depreciatedAsset();

        $operationId = $this->disposals->sell($assetId, ['date' => '2026-05-10', 'proceeds' => '10000', 'treasury_id' => $this->assetTreasury], $this->assetClerk);
        $reversal    = $this->disposals->reverse($operationId, 'Buyer backed out', $this->assetClerk);

        $operation = $this->operation($operationId);

        $this->assertSame('reversed', $operation->status);
        $this->assertNull($operation->active_key);
        $this->assertSame($reversal, (int) $operation->reversal_journal_id);
        $this->assertSame(JournalStatus::Reversed->value, $this->accountingDb()->table('accounting_journals')->where('journal_id', $operation->journal_id)->get()->getRow()->status);
        $this->assertSame('2026-05-10', $this->accountingDb()->table('accounting_journals')->where('journal_id', $reversal)->get()->getRow()->journal_date);
        $this->assertSame(AssetStatus::Active->value, $this->asset($assetId)->status);
        $this->assertNull($this->asset($assetId)->disposed_at);
        $this->assertSame('12000.0000', $this->accountNet($this->accountId('fixed_assets')));
        $this->assertSame('-3000.0000', $this->accountNet($this->accountId('accumulated_depreciation')));
        $this->assertSame('0.0000', $this->accountNet($this->accountId('asset_gain')));
        $this->assertSame('-12000.0000', $this->accountNet($this->treasuryAccount($this->assetTreasury)));

        $again = $this->disposals->writeOff($assetId, ['date' => '2026-05-11'], $this->assetClerk);

        $this->assertGreaterThan($operationId, $again, 'an asset whose disposal was taken back is disposed of again');
    }

    public function testReverse_PutsAConsumedAssetBackAsConsumed(): void
    {
        $assetId = $this->enterAsset(['cost' => '2000', 'amount_per_period' => '1000']);

        foreach (['2026-02', '2026-03'] as $month) {
            $preview = $this->depreciation->preview(1, $month, $this->assetRunner);

            $this->depreciation->approve(1, $month, $preview->fingerprint, $this->assetRunner);
        }

        $operationId = $this->disposals->writeOff($assetId, ['date' => '2026-05-10'], $this->assetClerk);

        $this->disposals->reverse($operationId, 'Wrong asset', $this->assetClerk);

        $this->assertSame(AssetStatus::Consumed->value, $this->asset($assetId)->status);
    }

    public function testReverse_RefusesAPeriodThatIsClosedAndKeepsTheDisposal(): void
    {
        $assetId = $this->depreciatedAsset();

        $operationId = $this->disposals->writeOff($assetId, ['date' => '2026-05-10'], $this->assetClerk);

        $this->closeThrough('2026-05-31');

        try {
            $this->disposals->reverse($operationId, 'Too late', $this->assetClerk);
            $this->fail('May is closed.');
        } catch (PostingException $exception) {
            $this->assertSame(PostingError::PeriodClosed, $exception->error);
        }

        $this->assertSame('active', $this->operation($operationId)->status);
        $this->assertSame(AssetStatus::WrittenOff->value, $this->asset($assetId)->status);
    }

    public function testReverse_RequiresAReasonThePermissionAndAnOperationThatStands(): void
    {
        $assetId     = $this->depreciatedAsset();
        $operationId = $this->disposals->writeOff($assetId, ['date' => '2026-05-10'], $this->assetClerk);

        $this->assertRefused(AccountingError::ReasonRequired, fn () => $this->disposals->reverse($operationId, ' ', $this->assetClerk));
        $this->assertRefused(AccountingError::PermissionDenied, fn () => $this->disposals->reverse($operationId, 'No right', $this->employeeWithGrants([])));
        $this->assertRefused(AccountingError::AssetOperationNotFound, fn () => $this->disposals->reverse(999999, 'Never', $this->assetClerk));

        $this->disposals->reverse($operationId, 'Once', $this->assetClerk);

        $this->assertRefused(AccountingError::AssetOperationReversed, fn () => $this->disposals->reverse($operationId, 'Twice', $this->assetClerk));
    }

    public function testReverse_RefusesADisposalOfABranchOutsideTheScopeOfTheEmployee(): void
    {
        $assetId     = $this->enterAsset(['branch_id' => $this->second, 'funding' => 'payable', 'treasury_id' => '']);
        $operationId = $this->disposals->writeOff($assetId, ['date' => '2026-01-20'], $this->assetClerk);
        $limited     = $this->employeeWithGrants([AccountingGuard::ASSETS_MANAGE], [1]);

        try {
            $this->disposals->reverse($operationId, 'Not mine', $limited);
            $this->fail('The employee works in branch 1 only.');
        } catch (BranchException $exception) {
            $this->assertSame('active', $this->operation($operationId)->status);
        }
    }

    public function testSell_DisposesOfAnAssetOnceWhenTwoRequestsTryAtTheSameTime(): void
    {
        $assetId = $this->depreciatedAsset();

        $this->other = $this->openSecondConnection();
        $this->startWithSnapshot($this->other);

        $this->disposals->sell($assetId, ['date' => '2026-05-10', 'proceeds' => '9000', 'treasury_id' => $this->assetTreasury], $this->assetClerk);

        $this->assertRefused(AccountingError::AssetStatusInvalid, fn () => (new AssetDisposals($this->other))->sell($assetId, ['date' => '2026-05-10', 'proceeds' => '9000', 'treasury_id' => $this->assetTreasury], $this->assetClerk));
        $this->assertSame(1, $this->countRows('asset_operations'));
        $this->assertSame(1, $this->accountingDb()->table('accounting_journals')->where('source_type', AssetSources::SALE)->countAllResults());
    }

    public function testOperations_ListsTheDisposalsOfTheBranchesTheEmployeeMaySee(): void
    {
        $mine  = $this->depreciatedAsset();
        $other = $this->enterAsset(['branch_id' => $this->second, 'funding' => 'payable', 'treasury_id' => '', 'name' => 'Chair']);

        $this->disposals->writeOff($mine, ['date' => '2026-05-10'], $this->assetClerk);
        $this->disposals->writeOff($other, ['date' => '2026-01-20'], $this->assetClerk);

        $limited = $this->employeeWithGrants([AccountingGuard::ASSETS_MANAGE], [1]);

        $this->assertCount(2, $this->disposals->operations($this->assetClerk));
        $this->assertSame([$mine], array_map(static fn (object $operation): int => (int) $operation->asset_id, $this->disposals->operations($limited)));
        $this->assertCount(1, $this->disposals->operations($this->assetClerk, $other));
    }

    public function testSell_RefusesATreasuryTheEmployeeMayNotPutMoneyInto(): void
    {
        $assetId     = $this->depreciatedAsset();
        $someoneElse = $this->employeeWithGrants([]);
        $treasury    = (new TreasuryService())->create(['name' => 'Locked cash ' . uniqid(), 'kind' => 'cash', 'branch_id' => 1, 'deposit_scope' => 'staff', 'deposit_members' => [$someoneElse]], $this->assetClerk);

        $this->assertRefused(AccountingError::TreasuryDepositDenied, fn () => $this->disposals->sell($assetId, ['date' => '2026-05-10', 'proceeds' => '9000', 'treasury_id' => $treasury], $this->assetClerk));
        $this->assertSame(AssetStatus::Active->value, $this->asset($assetId)->status);
        $this->assertSame(0, $this->countRows('asset_operations'));
    }

    public function testReverse_RefusesATreasuryTheEmployeeMayNotTakeMoneyOutOf(): void
    {
        $assetId  = $this->depreciatedAsset();
        $taker    = $this->employeeWithGrants([AccountingGuard::ASSETS_MANAGE]);
        $treasury = (new TreasuryService())->create(['name' => 'Guarded cash ' . uniqid(), 'kind' => 'cash', 'branch_id' => 1, 'withdraw_scope' => 'staff', 'withdraw_members' => [$taker]], $this->assetClerk);

        $operationId = $this->disposals->sell($assetId, ['date' => '2026-05-10', 'proceeds' => '9000', 'treasury_id' => $treasury], $this->assetClerk);

        $this->assertRefused(AccountingError::TreasuryWithdrawDenied, fn () => $this->disposals->reverse($operationId, 'Not my money', $this->assetClerk));
        $this->assertSame('active', $this->operation($operationId)->status);
        $this->assertSame(AssetStatus::Sold->value, $this->asset($assetId)->status);
        $this->assertSame(0, $this->accountingDb()->table('accounting_journals')->where('source_type', AssetSources::SALE)->where('status', JournalStatus::Reversed->value)->countAllResults());

        $this->disposals->reverse($operationId, 'Mine to take back', $taker);

        $this->assertSame('reversed', $this->operation($operationId)->status);
        $this->assertSame(AssetStatus::Active->value, $this->asset($assetId)->status);
    }

    public function testReverse_AsksNoTreasuryRightForAWriteOffThatMovesNoMoney(): void
    {
        $assetId     = $this->depreciatedAsset();
        $operationId = $this->disposals->writeOff($assetId, ['date' => '2026-05-10'], $this->assetClerk);

        $this->disposals->reverse($operationId, 'No money moved', $this->employeeWithGrants([AccountingGuard::ASSETS_MANAGE]));

        $this->assertSame('reversed', $this->operation($operationId)->status);
    }

    public function testSell_RefusesAnAssetEnteredAsAlreadyInTheBooksWhenTheBooksDoNotHoldItsCost(): void
    {
        $this->setConfigValue('accounting_start_date', '2026-01-01');

        $assetId = $this->enterAsset(['funding' => 'none', 'treasury_id' => '']);

        $exception = $this->assertRefused(AccountingError::AssetNotInBooks, fn () => $this->disposals->sell($assetId, ['date' => '2026-01-20', 'proceeds' => '12000', 'treasury_id' => $this->assetTreasury], $this->assetClerk));

        $this->assertSame((string) $this->asset($assetId)->code, $exception->context['name']);
        $this->assertRefused(AccountingError::AssetNotInBooks, fn () => $this->disposals->writeOff($assetId, ['date' => '2026-01-20'], $this->assetClerk));
        $this->assertSame(AssetStatus::Active->value, $this->asset($assetId)->status);
        $this->assertSame(0, $this->countRows('asset_operations'));
        $this->assertSame('0.0000', $this->accountNet($this->accountId('fixed_assets')));
        $this->assertSame('0.0000', $this->accountNet($this->treasuryAccount($this->assetTreasury)));
    }

    public function testSell_RefusesAnAssetEnteredAsAlreadyInTheBooksWhenOnlyItsAccumulatedDepreciationIsMissing(): void
    {
        $this->setConfigValue('accounting_start_date', '2026-01-01');

        $assetId = $this->enterAsset(['funding' => 'none', 'treasury_id' => '', 'opening_accumulated' => '3000']);

        (new PostingService())->post($this->draftWithLines([
            new JournalLineDraft($this->accountId('fixed_assets'), debit: '12000'),
            new JournalLineDraft($this->accountId('opening_balance'), credit: '12000'),
        ], '2026-01-01'));

        $this->assertRefused(AccountingError::AssetNotInBooks, fn () => $this->disposals->writeOff($assetId, ['date' => '2026-01-20'], $this->assetClerk));
        $this->assertSame(0, $this->countRows('asset_operations'));
    }

    public function testSell_TakesAnAssetEnteredAsAlreadyInTheBooksOutWhenTheBooksHoldIt(): void
    {
        $this->setConfigValue('accounting_start_date', '2026-01-01');

        $assetId = $this->enterAsset(['funding' => 'none', 'treasury_id' => '', 'opening_accumulated' => '3000']);

        (new PostingService())->post($this->draftWithLines([
            new JournalLineDraft($this->accountId('fixed_assets'), debit: '12000'),
            new JournalLineDraft($this->accountId('accumulated_depreciation'), credit: '3000'),
            new JournalLineDraft($this->accountId('opening_balance'), credit: '9000'),
        ], '2026-01-01'));

        $operationId = $this->disposals->sell($assetId, ['date' => '2026-01-20', 'proceeds' => '9000', 'treasury_id' => $this->assetTreasury], $this->assetClerk);

        $this->assertSame('active', $this->operation($operationId)->status);
        $this->assertSame('0.0000', $this->accountNet($this->accountId('fixed_assets')));
        $this->assertSame('0.0000', $this->accountNet($this->accountId('accumulated_depreciation')));
    }

    public function testSell_AsksNoProofOfTheBooksForAnAssetItsOwnJournalBroughtIn(): void
    {
        $assetId = $this->enterAsset();

        $operationId = $this->disposals->sell($assetId, ['date' => '2026-01-20', 'proceeds' => '12000', 'treasury_id' => $this->assetTreasury], $this->assetClerk);

        $this->assertSame('active', $this->operation($operationId)->status);
    }

    public function testSell_TakesTheBranchLockBeforeItLocksTheAsset(): void
    {
        $assetId = $this->depreciatedAsset();

        $this->assertTakesTheBranchLockBeforeTheAsset(fn () => $this->disposals->sell($assetId, ['date' => '2026-05-10', 'proceeds' => '9000', 'treasury_id' => $this->assetTreasury], $this->assetClerk));
    }

    public function testWriteOff_TakesTheBranchLockBeforeItLocksTheAsset(): void
    {
        $assetId = $this->depreciatedAsset();

        $this->assertTakesTheBranchLockBeforeTheAsset(fn () => $this->disposals->writeOff($assetId, ['date' => '2026-05-10'], $this->assetClerk));
    }

    public function testReverse_TakesTheBranchLockBeforeItLocksTheAsset(): void
    {
        $assetId     = $this->depreciatedAsset();
        $operationId = $this->disposals->writeOff($assetId, ['date' => '2026-05-10'], $this->assetClerk);

        $this->assertTakesTheBranchLockBeforeTheAsset(fn () => $this->disposals->reverse($operationId, 'Wrong asset', $this->assetClerk));
    }

    public function testSell_RefusesAnAssetEnteredAsAlreadyInTheBooksWhenTheBooksHoldItInAnotherBranchOnly(): void
    {
        $this->setConfigValue('accounting_start_date', '2026-01-01');
        $assetId = $this->enterAsset(['funding' => 'none', 'treasury_id' => '']);

        (new PostingService())->post(new JournalDraft(
            date: '2026-01-01',
            lines: [new JournalLineDraft($this->accountId('fixed_assets'), debit: '12000'), new JournalLineDraft($this->accountId('opening_balance'), credit: '12000')],
            sourceType: 'sale',
            sourceId: 1,
            employeeId: 1,
            currencyCode: 'SAR',
            branchId: $this->second,
        ));

        $this->assertRefused(AccountingError::AssetNotInBooks, fn () => $this->disposals->writeOff($assetId, ['date' => '2026-01-20'], $this->assetClerk));
    }

    public function testSell_RefusesAnAssetEnteredAsAlreadyInTheBooksWhenTheBooksHoldItOnlyFromALaterDay(): void
    {
        $this->setConfigValue('accounting_start_date', '2026-01-01');
        $assetId = $this->enterAsset(['funding' => 'none', 'treasury_id' => '']);

        (new PostingService())->post($this->draftWithLines([
            new JournalLineDraft($this->accountId('fixed_assets'), debit: '12000'),
            new JournalLineDraft($this->accountId('opening_balance'), credit: '12000'),
        ], '2026-01-25'));

        $this->assertRefused(AccountingError::AssetNotInBooks, fn () => $this->disposals->writeOff($assetId, ['date' => '2026-01-20'], $this->assetClerk));

        $this->disposals->writeOff($assetId, ['date' => '2026-01-25'], $this->assetClerk);

        $this->assertSame(AssetStatus::WrittenOff->value, $this->asset($assetId)->status);
    }

    public function testSell_CountsAJournalThatWasTakenBackAsNotThereForAnAssetEnteredAsAlreadyInTheBooks(): void
    {
        $this->setConfigValue('accounting_start_date', '2026-01-01');
        $assetId = $this->enterAsset(['funding' => 'none', 'treasury_id' => '']);

        $posted = (new PostingService())->post($this->draftWithLines([
            new JournalLineDraft($this->accountId('fixed_assets'), debit: '12000'),
            new JournalLineDraft($this->accountId('opening_balance'), credit: '12000'),
        ], '2026-01-01'));

        (new JournalService())->reverseAutomatic($posted->journalId, '2026-01-10', 'Entered twice', null);

        $this->assertRefused(AccountingError::AssetNotInBooks, fn () => $this->disposals->writeOff($assetId, ['date' => '2026-01-20'], $this->assetClerk));
    }

    public function testWriteOff_ReadsTheUsageOfMonthsBeforeTheDisposalUnderAShareLock(): void
    {
        $assetId = $this->enterAsset(['method' => 'units', 'cost_per_unit' => '2.5', 'amount_per_period' => '']);
        $usage   = preg_quote($this->accountingDb()->prefixTable('asset_usage'), '/');

        $this->register->recordUsage($assetId, '2026-01', '10', $this->assetClerk);

        $preview = $this->depreciation->preview(1, '2026-01', $this->assetRunner);

        $this->depreciation->approve(1, '2026-01', $preview->fingerprint, $this->assetRunner);

        $queries = $this->queriesOf(fn () => $this->disposals->writeOff($assetId, ['date' => '2026-03-10'], $this->assetClerk));

        $this->assertCount(1, array_filter($queries, static fn (string $query): bool => preg_match('/SELECT period_start FROM ' . $usage . ' WHERE.*LOCK IN SHARE MODE/', $query) === 1));
    }

    public function testSell_HoldsAnAssetToOneDisposalThatStandsWhateverTheCodeDoes(): void
    {
        $assetId = $this->depreciatedAsset();

        $this->disposals->writeOff($assetId, ['date' => '2026-05-10'], $this->assetClerk);

        $this->expectException(DatabaseException::class);

        $this->accountingDb()->table('asset_operations')->insert(['company_id' => 1, 'branch_id' => 1, 'asset_id' => $assetId, 'kind' => 'write_off', 'operation_date' => '2026-05-11', 'cost_removed' => '1', 'accumulated_removed' => '0', 'result_amount' => '-1', 'previous_status' => 'active', 'status' => 'active', 'active_key' => 1]);
    }

    public function testWriteOff_RequiresThePermissionToManageTheRegister(): void
    {
        $assetId = $this->enterAsset();

        $this->assertRefused(AccountingError::PermissionDenied, fn () => $this->disposals->writeOff($assetId, ['date' => '2026-01-20'], $this->employeeWithGrants([])));
        $this->assertSame(0, $this->countRows('asset_operations'));
        $this->assertSame(AssetStatus::Active->value, $this->asset($assetId)->status);
    }

    public function testReverse_AsksForTheReasonBeforeItLooksForTheDisposal(): void
    {
        $assetId     = $this->depreciatedAsset();
        $operationId = $this->disposals->sell($assetId, ['date' => '2026-05-10', 'proceeds' => '10000', 'treasury_id' => $this->assetTreasury], $this->assetClerk);

        $this->assertRefused(AccountingError::ReasonRequired, fn () => $this->disposals->reverse(999999, '', $this->assetClerk));
        $this->assertRefused(AccountingError::ReasonRequired, fn () => $this->disposals->reverse($operationId, str_repeat('x', 256), $this->assetClerk));
        $this->assertSame('active', $this->operation($operationId)->status);

        $this->disposals->reverse($operationId, str_repeat('x', 255), $this->assetClerk);

        $this->assertSame('reversed', $this->operation($operationId)->status);
    }

    public function testReverse_HoldsTheAssetBeforeItTakesTheDisposalBack(): void
    {
        $assetId     = $this->depreciatedAsset();
        $operationId = $this->disposals->sell($assetId, ['date' => '2026-05-10', 'proceeds' => '10000', 'treasury_id' => $this->assetTreasury], $this->assetClerk);
        $assets      = preg_quote($this->accountingDb()->prefixTable('assets'), '/');

        $queries = $this->queriesOf(fn () => $this->disposals->reverse($operationId, 'Buyer backed out', $this->assetClerk));
        $locks   = array_filter($queries, static fn (string $query): bool => preg_match('/SELECT asset_id FROM ' . $assets . ' WHERE asset_id = \S+ FOR UPDATE/', $query) === 1);

        $this->assertCount(1, $locks);
    }

    public function testReverse_WritesAnEntryOfTheAuditLog(): void
    {
        $assetId     = $this->depreciatedAsset();
        $operationId = $this->disposals->sell($assetId, ['date' => '2026-05-10', 'proceeds' => '10000', 'treasury_id' => $this->assetTreasury], $this->assetClerk);
        $reversal    = $this->disposals->reverse($operationId, 'Buyer backed out', $this->assetClerk);

        $entry = $this->accountingDb()->table('accounting_audit_log')->where(['entity_type' => 'asset_operation', 'entity_id' => $operationId, 'action' => 'asset_disposal_reversed'])->get()->getRow();

        $this->assertNotNull($entry);
        $this->assertSame($this->assetClerk, (int) $entry->employee_id);

        $details = json_decode((string) $entry->details, true);

        $this->assertSame($reversal, $details['reversal_id']);
        $this->assertSame('Buyer backed out', $details['reason']);
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
}
