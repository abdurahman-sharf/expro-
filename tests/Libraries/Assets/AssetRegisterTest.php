<?php

namespace Tests\Libraries\Assets;

use App\Libraries\Accounting\AccountingError;
use App\Libraries\Accounting\AccountingException;
use App\Libraries\Accounting\AccountingGuard;
use App\Libraries\Accounting\JournalStatus;
use App\Libraries\Accounting\PostingError;
use App\Libraries\Accounting\PostingException;
use App\Libraries\Accounting\TreasuryService;
use App\Libraries\Assets\AssetDisposals;
use App\Libraries\Assets\AssetRegister;
use App\Libraries\Assets\AssetSources;
use App\Libraries\Assets\AssetStatus;
use App\Libraries\Numbering\DocumentNumbering;
use App\Libraries\Numbering\DocumentType;
use App\Libraries\Organization\BranchError;
use App\Libraries\Organization\BranchException;
use CodeIgniter\Events\Events;
use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\DatabaseTestTrait;
use Config\Database;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Support\AccountingFixtureTrait;
use Tests\Support\AssetFixtureTrait;

/**
 * The register of fixed assets: what entering an asset posts, what it refuses, how an asset is changed
 * (the journal is reversed and posted again, the terms lock once a depreciation stands) and how it is
 * taken out of service or deleted.
 *
 * @internal
 */
final class AssetRegisterTest extends CIUnitTestCase
{
    use DatabaseTestTrait;
    use AccountingFixtureTrait;
    use AssetFixtureTrait;

    protected $migrate     = true;
    protected $migrateOnce = true;
    protected $refresh     = false;
    protected $namespace;
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

    public function testCreate_PostsTheJournalOfAnAssetPaidFromATreasury(): void
    {
        $assetId = $this->enterAsset();

        $asset   = $this->asset($assetId);
        $journal = $this->journalOf(AssetSources::ACQUISITION, $assetId);
        $lines   = $this->journalLines((int) $journal->journal_id);

        $this->assertSame('2026-01-01', $journal->journal_date);
        $this->assertSame(JournalStatus::Posted->value, $journal->status);
        $this->assertSame('12000.0000', $journal->total_debit);
        $this->assertCount(2, $lines);
        $this->assertSame($this->accountId('fixed_assets'), (int) $lines[0]->account_id);
        $this->assertSame('12000.0000', $lines[0]->debit);
        $this->assertSame($this->treasuryAccount($this->assetTreasury), (int) $lines[1]->account_id);
        $this->assertSame('12000.0000', $lines[1]->credit);
        $this->assertSame($this->accountId('accumulated_depreciation'), (int) $asset->accumulated_account_id);
        $this->assertSame($this->accountId('depreciation_expense'), (int) $asset->expense_account_id);
        $this->assertSame(AssetStatus::Active->value, $asset->status);
        $this->assertSame('12000.0000', $this->accountNet($this->accountId('fixed_assets')));
        $this->assertSame('-12000.0000', $this->accountNet($this->treasuryAccount($this->assetTreasury)));
    }

    public function testCreate_PostsWhatIsOwedForAnAssetBoughtOnCredit(): void
    {
        $assetId = $this->enterAsset(['funding' => 'payable', 'treasury_id' => '', 'supplier_id' => $this->insertSupplier(), 'purchase_reference' => 'INV-77']);

        $lines = $this->journalLines((int) $this->journalOf(AssetSources::ACQUISITION, $assetId)->journal_id);

        $this->assertSame($this->accountId('other_payables'), (int) $lines[1]->account_id);
        $this->assertSame('12000.0000', $lines[1]->credit);
        $this->assertSame('INV-77', $this->asset($assetId)->purchase_reference);
    }

    public function testCreate_BringsInAnOldAssetWithTheDepreciationItAlreadyHad(): void
    {
        $this->setConfigValue('accounting_start_date', '2026-01-01');
        $opener  = $this->employeeWithGrants([AccountingGuard::ASSETS_MANAGE, AccountingGuard::OPENING_MANAGE]);
        $assetId = $this->enterAsset(['funding' => 'opening', 'treasury_id' => '', 'purchase_date' => '2024-01-01', 'in_service_date' => '2024-01-01', 'opening_accumulated' => '5000'], $opener);

        $asset   = $this->asset($assetId);
        $journal = $this->journalOf(AssetSources::ACQUISITION, $assetId);
        $lines   = $this->journalLines((int) $journal->journal_id);

        $this->assertSame('2026-01-01', $journal->journal_date, 'dated on the day the books start');
        $this->assertSame('2025-12-31', $asset->opening_through, 'the depreciation it had covers the days before the books start');
        $this->assertSame('5000.0000', $asset->opening_accumulated);
        $this->assertSame([$this->accountId('fixed_assets'), $this->accountId('accumulated_depreciation'), $this->accountId('opening_balance')], array_map(static fn (object $line): int => (int) $line->account_id, $lines));
        $this->assertSame(['12000.0000', '0.0000', '0.0000'], array_map(static fn (object $line): string => $line->debit, $lines));
        $this->assertSame(['0.0000', '5000.0000', '7000.0000'], array_map(static fn (object $line): string => $line->credit, $lines));
    }

    public function testCreate_MarksAnOldAssetThatHadDepreciatedByAllItIsToBeAsConsumed(): void
    {
        $this->setConfigValue('accounting_start_date', '2026-01-01');
        $opener  = $this->employeeWithGrants([AccountingGuard::ASSETS_MANAGE, AccountingGuard::OPENING_MANAGE]);
        $assetId = $this->enterAsset(['funding' => 'opening', 'treasury_id' => '', 'salvage' => '2000', 'opening_accumulated' => '10000'], $opener);

        $this->assertSame(AssetStatus::Consumed->value, $this->asset($assetId)->status);
        $this->assertCount(3, $this->journalLines((int) $this->journalOf(AssetSources::ACQUISITION, $assetId)->journal_id), 'cost and accumulated depreciation, and a net of 2,000 for the opening balance');
    }

    public function testCreate_BringsInAnOldAssetWithoutDepreciationAtItsCostAgainstTheOpeningBalance(): void
    {
        $this->setConfigValue('accounting_start_date', '2026-01-01');

        $opener  = $this->employeeWithGrants([AccountingGuard::ASSETS_MANAGE, AccountingGuard::OPENING_MANAGE]);
        $assetId = $this->enterAsset(['funding' => 'opening', 'treasury_id' => '', 'opening_accumulated' => '0'], $opener);

        $lines = $this->journalLines((int) $this->journalOf(AssetSources::ACQUISITION, $assetId)->journal_id);

        $this->assertSame([$this->accountId('fixed_assets'), $this->accountId('opening_balance')], array_map(static fn (object $line): int => (int) $line->account_id, $lines));
        $this->assertSame('12000.0000', $lines[1]->credit);
    }

    public function testCreate_BringsInAnOldAssetDepreciatedByAllItIsWorthAgainstItsAccumulatedDepreciationAlone(): void
    {
        $this->setConfigValue('accounting_start_date', '2026-01-01');

        $opener  = $this->employeeWithGrants([AccountingGuard::ASSETS_MANAGE, AccountingGuard::OPENING_MANAGE]);
        $assetId = $this->enterAsset(['funding' => 'opening', 'treasury_id' => '', 'salvage' => '0', 'opening_accumulated' => '12000'], $opener);

        $lines = $this->journalLines((int) $this->journalOf(AssetSources::ACQUISITION, $assetId)->journal_id);

        $this->assertSame([$this->accountId('fixed_assets'), $this->accountId('accumulated_depreciation')], array_map(static fn (object $line): int => (int) $line->account_id, $lines));
        $this->assertSame('12000.0000', $lines[1]->credit);
    }

    public function testCreate_EntersAnAssetTheBooksHoldAlreadyWithoutAJournal(): void
    {
        $this->setConfigValue('accounting_start_date', '2026-01-01');
        $assetId = $this->enterAsset(['funding' => 'none', 'treasury_id' => '', 'opening_accumulated' => '3000']);

        $this->assertNull($this->journalOf(AssetSources::ACQUISITION, $assetId));
        $this->assertSame('2025-12-31', $this->asset($assetId)->opening_through);
        $this->assertSame('0.0000', $this->accountNet($this->accountId('fixed_assets')));
    }

    public function testCreate_RequiresTheOpeningStartDateForAnOldAsset(): void
    {
        $this->accountingDb()->table('app_config')->where('key', 'accounting_start_date')->delete();
        $opener = $this->employeeWithGrants([AccountingGuard::ASSETS_MANAGE, AccountingGuard::OPENING_MANAGE]);

        $this->assertRefused(AccountingError::OpeningStartDateMissing, fn () => $this->enterAsset(['funding' => 'opening', 'treasury_id' => ''], $opener));
    }

    public function testCreate_RequiresThePermissionToManageTheRegister(): void
    {
        $nobody = $this->employeeWithGrants([]);

        $this->assertRefused(AccountingError::PermissionDenied, fn () => $this->enterAsset([], $nobody));
        $this->assertSame(0, $this->countRows('assets'));
    }

    public function testCreate_RequiresTheOpeningPermissionForAnAssetBroughtInAsAnOpeningBalance(): void
    {
        $this->setConfigValue('accounting_start_date', '2026-01-01');

        $this->assertRefused(AccountingError::PermissionDenied, fn () => $this->enterAsset(['funding' => 'opening', 'treasury_id' => '']));
        $this->assertSame(0, $this->countRows('assets'));
    }

    public function testCreate_RefusesATreasuryOfAnotherBranch(): void
    {
        $this->assertRefused(AccountingError::AssetTreasuryBranch, fn () => $this->enterAsset(['branch_id' => $this->second]));
        $this->assertSame(0, $this->countRows('assets'));
    }

    public function testCreate_RefusesABranchOutsideTheScopeOfTheEmployee(): void
    {
        $limited = $this->employeeWithGrants([AccountingGuard::ASSETS_MANAGE], [1]);

        try {
            $this->register->create($this->assetInput(['branch_id' => $this->second, 'funding' => 'payable', 'treasury_id' => '']), $limited);
            $this->fail('The employee works in branch 1 only.');
        } catch (BranchException $exception) {
            $this->assertSame(0, $this->countRows('assets'));
        }
    }

    public function testCreate_PostsInTheBranchOfTheAsset(): void
    {
        $assetId = $this->enterAsset(['branch_id' => $this->second, 'funding' => 'payable', 'treasury_id' => '']);

        $this->assertSame($this->second, (int) $this->journalOf(AssetSources::ACQUISITION, $assetId)->branch_id);
        $this->assertSame('12000.0000', $this->accountNet($this->accountId('fixed_assets'), $this->second));
        $this->assertSame('0.0000', $this->accountNet($this->accountId('fixed_assets'), 1));
    }

    public function testCreate_RefusesAPeriodThatIsClosedAndSavesNothing(): void
    {
        $this->closeThrough('2026-01-31');

        try {
            $this->enterAsset();
            $this->fail('January is closed.');
        } catch (PostingException $exception) {
            $this->assertSame(PostingError::PeriodClosed, $exception->error);
        }

        $this->assertSame(0, $this->countRows('assets'));
        $this->assertSame(0, $this->countRows('accounting_journals'));
        $this->assertSame(1, $this->accountingDb()->table('accounting_posting_failures')->where('source_type', AssetSources::ACQUISITION)->countAllResults(), 'the refusal is recorded');
    }

    public function testCreate_RefusesACodeAnotherAssetHas(): void
    {
        $this->enterAsset(['code' => 'DESK-1']);

        $refusal = $this->assertRefused(AccountingError::AssetCodeTaken, fn () => $this->enterAsset(['code' => 'DESK-1', 'name' => 'Second desk']));

        $this->assertStringContainsString('DESK-1', $refusal->getMessage());
        $this->assertStringContainsString('another asset', $refusal->getMessage());
        $this->assertSame(1, $this->countRows('assets'));
    }

    public function testCreate_GivesAnAssetWithoutACodeItsOwnNumberWhenTheNumberingIsOff(): void
    {
        $first  = $this->enterAsset();
        $second = $this->enterAsset(['name' => 'Chair']);

        $this->assertSame(str_pad((string) $first, 6, '0', STR_PAD_LEFT), $this->asset($first)->code);
        $this->assertSame(str_pad((string) $second, 6, '0', STR_PAD_LEFT), $this->asset($second)->code);
    }

    public function testCreate_GivesAnAssetWithoutACodeTheNextOfTheNumberingWhenItIsOn(): void
    {
        (new DocumentNumbering())->configure(DocumentType::FixedAsset, ['enabled' => true, 'prefix' => 'FA-', 'padding' => 4]);

        $first  = $this->enterAsset();
        $second = $this->enterAsset(['name' => 'Chair']);
        $typed  = $this->enterAsset(['name' => 'Lamp', 'code' => 'LAMP']);

        $this->assertSame('FA-0001', $this->asset($first)->code);
        $this->assertSame('FA-0002', $this->asset($second)->code);
        $this->assertSame('LAMP', $this->asset($typed)->code, 'a code that was typed stays');
        $this->assertSame('FA-0003', $this->register->suggestCode());
    }

    public function testSuggestCode_OffersTheNextSixDigitNumberWhileTheNumberingIsOff(): void
    {
        $this->assertSame('000001', $this->register->suggestCode());

        $this->enterAsset(['code' => '000007']);

        $this->assertSame('000008', $this->register->suggestCode());
    }

    /**
     * @return array<string, array{array<string, mixed>, string}>
     */
    public static function invalidFieldProvider(): array
    {
        return [
            'no name'                      => [['name' => ''], 'name'],
            'a zero cost'                  => [['cost' => '0'], 'cost'],
            'a cost with five decimals'    => [['cost' => '10.12345'], 'cost'],
            'a salvage above the cost'     => [['salvage' => '12001'], 'salvage'],
            'a method that is not one'     => [['method' => 'sum_of_years'], 'method'],
            'no purchase date'             => [['purchase_date' => ''], 'purchase_date'],
            'an impossible service date'   => [['in_service_date' => '2026-02-30'], 'in_service_date'],
            'a period of zero'             => [['period_length' => '0'], 'period_length'],
            'an interval that is not one'  => [['period_interval' => 'year'], 'period_interval'],
            'no amount for the fixed way'  => [['amount_per_period' => ''], 'amount_per_period'],
            'a zero amount'                => [['amount_per_period' => '0'], 'amount_per_period'],
            'a percentage above 100'       => [['method' => 'declining', 'percentage' => '101'], 'percentage'],
            'no percentage'                => [['method' => 'declining', 'percentage' => ''], 'percentage'],
            'a zero percentage'            => [['method' => 'declining', 'percentage' => '0'], 'percentage'],
            'no cost per unit'             => [['method' => 'units', 'cost_per_unit' => ''], 'cost_per_unit'],
            'a quantity of zero'           => [['quantity' => '0'], 'quantity'],
            'a life of years nobody lives' => [['useful_life_value' => '200', 'useful_life_unit' => 'years'], 'useful_life_months'],
            'an end date before the first' => [['end_date' => '2026-01-15'], 'end_date'],
            'a supplier that is none'      => [['funding' => 'payable', 'treasury_id' => '', 'supplier_id' => 999999], 'supplier_id'],
            'a cost center that is none'   => [['cost_center_id' => 999999], 'cost_center_id'],
        ];
    }

    /**
     * @param array<string, mixed> $overrides
     */
    #[DataProvider('invalidFieldProvider')]
    public function testCreate_RejectsAFieldThatIsNotValid(array $overrides, string $field): void
    {
        $exception = $this->assertRefused(AccountingError::AssetFieldInvalid, fn () => $this->enterAsset($overrides));

        $this->assertSame($field, $exception->context['name']);
        $this->assertSame(0, $this->countRows('assets'));
        $this->assertSame(0, $this->countRows('accounting_journals'));
    }

    public function testCreate_RefusesAFirstDepreciationBeforeTheAssetWentIntoService(): void
    {
        $this->assertRefused(AccountingError::AssetFirstDateInvalid, fn () => $this->enterAsset(['in_service_date' => '2026-03-01', 'first_depreciation_date' => '2026-02-01']));
    }

    public function testCreate_RefusesAFirstDepreciationBeforeTheAssetWasBought(): void
    {
        $this->assertRefused(AccountingError::AssetFirstDateInvalid, fn () => $this->enterAsset(['purchase_date' => '2026-03-01', 'in_service_date' => '2026-01-01', 'first_depreciation_date' => '2026-02-01']));
    }

    public function testCreate_DefaultsTheFirstDepreciationToTheServiceDatePlusAPeriod(): void
    {
        $assetId = $this->enterAsset(['in_service_date' => '2026-01-31', 'purchase_date' => '2026-01-31']);

        $this->assertSame('2026-02-28', $this->asset($assetId)->first_depreciation_date);
    }

    public function testCreate_TakesTheLifeInYearsAndSpreadsTheBaseOverItWhenNoAmountIsGiven(): void
    {
        $assetId = $this->enterAsset(['amount_per_period' => '', 'useful_life_value' => '2', 'useful_life_unit' => 'years']);
        $asset   = $this->asset($assetId);

        $this->assertSame(24, (int) $asset->useful_life_months);
        $this->assertSame('500.0000', $asset->amount_per_period);
        $this->assertSame('2028-01-01', $asset->end_date, 'the depreciation ends when the life does');
    }

    public function testCreate_ClearsTheFieldsAMethodDoesNotTake(): void
    {
        $none = $this->asset($this->enterAsset(['method' => 'none', 'salvage' => '500', 'amount_per_period' => '900', 'percentage' => '10']));

        $this->assertSame('0.0000', $none->salvage);
        $this->assertNull($none->amount_per_period);
        $this->assertNull($none->percentage);
        $this->assertNull($none->accumulated_account_id);
        $this->assertNull($none->expense_account_id);
        $this->assertNull($none->first_depreciation_date);

        $units = $this->asset($this->enterAsset(['method' => 'units', 'name' => 'Press', 'cost_per_unit' => '2.5', 'total_units' => '4800', 'unit_name' => 'hours', 'amount_per_period' => '900']));

        $this->assertNull($units->amount_per_period);
        $this->assertNull($units->first_depreciation_date);
        $this->assertSame('2.500000', $units->cost_per_unit);
        $this->assertSame('4800.00', $units->total_units);

        $declining = $this->asset($this->enterAsset(['method' => 'declining', 'name' => 'Van', 'percentage' => '20', 'amount_per_period' => '900']));

        $this->assertNull($declining->amount_per_period);
        $this->assertSame('20.0000', $declining->percentage);
    }

    public function testCreate_TakesTheAccountsOfTheCategoryUnlessTheFormNamesOthers(): void
    {
        $cost        = $this->customAccount('111', '111010', 'Vehicles cost');
        $accumulated = $this->customAccount('112', '112010', 'Vehicles accumulated');
        $expense     = $this->customAccount('53', '5310', 'Vehicles depreciation');
        $category    = $this->categories->save(['name' => 'Vehicles', 'cost_account_id' => $cost, 'accumulated_account_id' => $accumulated, 'expense_account_id' => $expense], $this->assetClerk);

        $fromCategory = $this->asset($this->enterAsset(['category_id' => $category]));
        $ownCost      = $this->customAccount('111', '111011', 'Own cost');
        $named        = $this->asset($this->enterAsset(['category_id' => $category, 'cost_account_id' => $ownCost, 'name' => 'Truck']));

        $this->assertSame([$cost, $accumulated, $expense], [(int) $fromCategory->cost_account_id, (int) $fromCategory->accumulated_account_id, (int) $fromCategory->expense_account_id]);
        $this->assertSame([$ownCost, $accumulated, $expense], [(int) $named->cost_account_id, (int) $named->accumulated_account_id, (int) $named->expense_account_id]);
        $this->assertSame('12000.0000', $this->accountNet($cost));
    }

    public function testCreate_RefusesAnAccountAnAssetCannotUse(): void
    {
        $group    = $this->accountIdByCode('111001');
        $customer = $this->accountId('receivables');

        $this->assertRefused(AccountingError::AssetAccountInvalid, fn () => $this->enterAsset(['cost_account_id' => $group]));
        $this->assertRefused(AccountingError::AssetAccountInvalid, fn () => $this->enterAsset(['cost_account_id' => $this->accountId('depreciation_expense')]));
        $this->assertRefused(AccountingError::AssetAccountInvalid, fn () => $this->enterAsset(['expense_account_id' => $this->accountId('fixed_assets')]));
        $this->assertRefused(AccountingError::AssetAccountInvalid, fn () => $this->enterAsset(['cost_account_id' => $this->treasuryAccount($this->assetTreasury)]));
        $this->assertRefused(AccountingError::AssetAccountInvalid, fn () => $this->enterAsset(['accumulated_account_id' => $this->accountId('fixed_assets')]));
        $this->assertNotSame(0, $customer);
        $this->assertSame(0, $this->countRows('assets'));
    }

    public function testCreate_RefusesAnOpeningAmountOnAnAssetBoughtNow(): void
    {
        $this->assertRefused(AccountingError::AssetFundingInvalid, fn () => $this->enterAsset(['opening_accumulated' => '100']));
    }

    public function testCreate_RefusesAnOpeningDepreciationAboveWhatIsToBeDepreciated(): void
    {
        $this->setConfigValue('accounting_start_date', '2026-01-01');
        $opener = $this->employeeWithGrants([AccountingGuard::ASSETS_MANAGE, AccountingGuard::OPENING_MANAGE]);

        $exception = $this->assertRefused(AccountingError::AssetFieldInvalid, fn () => $this->enterAsset(['funding' => 'opening', 'treasury_id' => '', 'salvage' => '2000', 'opening_accumulated' => '10001'], $opener));

        $this->assertSame('opening_accumulated', $exception->context['name']);
    }

    public function testCreate_RefusesAFundingThatIsNotOneOfTheFour(): void
    {
        $this->assertRefused(AccountingError::AssetFundingInvalid, fn () => $this->enterAsset(['funding' => 'barter']));
        $this->assertRefused(AccountingError::AssetFundingInvalid, fn () => $this->enterAsset(['treasury_id' => '']));
    }

    public function testCreate_RequiresASerialOrABarcodeForEachUnitOfAnAssetThatDefinesItsUnits(): void
    {
        $units = [['serial_number' => 'S-1'], ['serial_number' => '', 'barcode' => '']];

        $this->assertRefused(AccountingError::AssetUnitIdentityRequired, fn () => $this->enterAsset(['quantity' => '2', 'track_units' => '1', 'units' => $units]));
        $this->assertSame(0, $this->countRows('assets'));
    }

    public function testCreate_RequiresOneRowForEachUnitOfAnAssetThatDefinesItsUnits(): void
    {
        $this->assertRefused(AccountingError::AssetUnitsMismatch, fn () => $this->enterAsset(['quantity' => '3', 'track_units' => '1', 'units' => [['serial_number' => 'S-1'], ['serial_number' => 'S-2']]]));
        $this->assertRefused(AccountingError::AssetUnitsMismatch, fn () => $this->enterAsset(['quantity' => '3', 'units' => [['serial_number' => 'S-1'], ['serial_number' => 'S-2']]]));
    }

    public function testCreate_KeepsTheUnitsOfAnAssetAndTheirCustody(): void
    {
        $employee = $this->employeeWithGrants([]);
        $storage  = $this->insertStockLocation('Fixed store');
        $assetId  = $this->enterAsset(['quantity' => '3', 'track_units' => '1', 'units' => [
            ['serial_number' => 'S-1', 'used_by' => 'employee', 'employee_id' => $employee, 'physical_location' => 'Floor 2'],
            ['serial_number' => '', 'barcode' => 'B-2', 'used_by' => 'storage', 'stock_location_id' => $storage],
            ['serial_number' => 'S-3', 'barcode' => 'B-3'],
        ]]);

        $units = $this->register->find($assetId)->units;

        $this->assertCount(3, $units);
        $this->assertSame(['employee', 'storage', 'not_assigned'], array_map(static fn (object $unit): string => $unit->used_by, $units));
        $this->assertSame($employee, (int) $units[0]->employee_id);
        $this->assertSame($storage, (int) $units[1]->stock_location_id);
        $this->assertNull($units[1]->serial_number);
        $this->assertSame('Floor 2', $units[0]->physical_location);
        $this->assertSame(3, (int) $this->asset($assetId)->quantity);
    }

    public function testCreate_RefusesASerialNumberThatIsUsedAlreadyInTheFormOrByAnotherAsset(): void
    {
        $this->enterAsset(['units' => [['serial_number' => 'S-1']]]);

        $this->assertRefused(AccountingError::AssetSerialTaken, fn () => $this->enterAsset(['name' => 'Other', 'units' => [['serial_number' => 's-1']]]));
        $this->assertRefused(AccountingError::AssetSerialTaken, fn () => $this->enterAsset(['name' => 'Other', 'quantity' => '2', 'track_units' => '1', 'units' => [['serial_number' => 'N-1'], ['serial_number' => 'n-1']]]));
        $this->assertSame(1, $this->countRows('assets'));
    }

    public function testCreate_RefusesABarcodeThatIsUsedAlready(): void
    {
        $this->enterAsset(['units' => [['barcode' => 'B-1']]]);

        $this->assertRefused(AccountingError::AssetBarcodeTaken, fn () => $this->enterAsset(['name' => 'Other', 'units' => [['barcode' => 'B-1']]]));
    }

    public function testCreate_RequiresTheEmployeeWhenTheAssetIsAssignedToOne(): void
    {
        $this->assertRefused(AccountingError::AssetCustodyEmployee, fn () => $this->enterAsset(['units' => [['serial_number' => 'S-1', 'used_by' => 'employee']]]));
        $this->assertRefused(AccountingError::AssetCustodyEmployee, fn () => $this->enterAsset(['units' => [['serial_number' => 'S-1', 'used_by' => 'employee', 'employee_id' => 999999]]]));
    }

    public function testCreate_RequiresTheStorageWhenTheAssetIsKeptInStorage(): void
    {
        $this->assertRefused(AccountingError::AssetCustodyStorage, fn () => $this->enterAsset(['units' => [['serial_number' => 'S-1', 'used_by' => 'storage']]]));
    }

    public function testCreate_RefusesAUnitTheDatabaseKeysWouldCatchToo(): void
    {
        $this->enterAsset(['units' => [['serial_number' => 'S-9']]]);

        $this->assertSame(1, $this->accountingDb()->table('asset_units')->where('serial_number', 'S-9')->countAllResults());
        foreach (['_serial', '_barcode'] as $key) {
            $this->assertGreaterThan(0, $this->accountingDb()->query('SELECT COUNT(*) AS n FROM information_schema.STATISTICS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND INDEX_NAME = ? AND NON_UNIQUE = 0', [$this->accountingDb()->prefixTable('asset_units'), $this->accountingDb()->prefixTable('asset_units') . $key])->getRow()->n, $key);
        }
    }

    public function testUpdate_ReversesTheJournalAndPostsItAgainWhenTheCostChanges(): void
    {
        $assetId = $this->enterAsset();
        $first   = $this->journalOf(AssetSources::ACQUISITION, $assetId);

        $this->register->update($assetId, $this->assetInput(['cost' => '15000', 'code' => $this->asset($assetId)->code]), $this->assetClerk);

        $second = $this->journalOf(AssetSources::ACQUISITION, $assetId, 2);

        $this->assertSame(JournalStatus::Reversed->value, $this->accountingDb()->table('accounting_journals')->where('journal_id', $first->journal_id)->get()->getRow()->status);
        $this->assertSame(JournalStatus::Posted->value, $second->status);
        $this->assertSame('15000.0000', $second->total_debit);
        $this->assertSame('15000.0000', $this->accountNet($this->accountId('fixed_assets')));
        $this->assertSame('-15000.0000', $this->accountNet($this->treasuryAccount($this->assetTreasury)));
        $this->assertSame('15000.0000', $this->asset($assetId)->cost);
    }

    public function testUpdate_LeavesTheJournalAloneWhenNothingThatItHoldsChanges(): void
    {
        $assetId = $this->enterAsset();

        $this->register->update($assetId, $this->assetInput(['name' => 'Desk with drawers', 'description' => 'Oak', 'code' => $this->asset($assetId)->code]), $this->assetClerk);

        $this->assertSame(1, $this->accountingDb()->table('accounting_journals')->where('source_type', AssetSources::ACQUISITION)->countAllResults());
        $this->assertSame('Desk with drawers', $this->asset($assetId)->name);
    }

    public function testUpdate_MovesTheJournalToTheOtherTreasuryWhenItChanges(): void
    {
        $assetId = $this->enterAsset();
        $other   = (new TreasuryService())->create(['name' => 'Second cash ' . uniqid(), 'kind' => 'cash', 'branch_id' => 1], $this->assetClerk);

        $this->register->update($assetId, $this->assetInput(['treasury_id' => $other, 'code' => $this->asset($assetId)->code]), $this->assetClerk);

        $this->assertSame('0.0000', $this->accountNet($this->treasuryAccount($this->assetTreasury)));
        $this->assertSame('-12000.0000', $this->accountNet($this->treasuryAccount($other)));
    }

    public function testUpdate_LocksTheTermsOnceADepreciationStands(): void
    {
        $assetId = $this->enterAsset();
        $code    = $this->asset($assetId)->code;

        $this->standDepreciation($assetId);

        $exception = $this->assertRefused(AccountingError::AssetTermsLocked, fn () => $this->register->update($assetId, $this->assetInput(['cost' => '13000', 'code' => $code]), $this->assetClerk));

        $this->assertStringContainsString('cost', $exception->context['name']);
        $this->assertSame('12000.0000', $this->asset($assetId)->cost);
        $this->assertSame(1, $this->accountingDb()->table('accounting_journals')->where('source_type', AssetSources::ACQUISITION)->countAllResults());
    }

    public function testUpdate_ChangesWhatTouchesNoBalanceEvenWhenADepreciationStands(): void
    {
        $assetId = $this->enterAsset();
        $code    = $this->asset($assetId)->code;

        $this->standDepreciation($assetId);

        $this->register->update($assetId, $this->assetInput(['name' => 'Renamed', 'description' => 'Moved', 'code' => $code, 'units' => [['serial_number' => 'S-5']]]), $this->assetClerk);

        $this->assertSame('Renamed', $this->asset($assetId)->name);
        $this->assertSame('S-5', $this->register->find($assetId)->units[0]->serial_number);
    }

    public function testUpdate_RefusesAnAssetThatWasSoldOrWrittenOff(): void
    {
        $assetId = $this->enterAsset();

        $this->accountingDb()->table('assets')->where('asset_id', $assetId)->update(['status' => 'sold']);

        $this->assertRefused(AccountingError::AssetStatusInvalid, fn () => $this->register->update($assetId, $this->assetInput(['name' => 'Renamed', 'code' => $this->asset($assetId)->code]), $this->assetClerk));
    }

    public function testUpdate_RequiresThePermissionToManageTheRegister(): void
    {
        $assetId = $this->enterAsset();

        $this->assertRefused(AccountingError::PermissionDenied, fn () => $this->register->update($assetId, $this->assetInput(['name' => 'Renamed']), $this->employeeWithGrants([])));
        $this->assertSame('Office desk', $this->asset($assetId)->name);
    }

    public function testUpdate_RefusesACodeAnotherAssetHas(): void
    {
        $first  = $this->enterAsset(['code' => 'A-1']);
        $second = $this->enterAsset(['code' => 'A-2', 'name' => 'Chair']);

        $this->assertRefused(AccountingError::AssetCodeTaken, fn () => $this->register->update($second, $this->assetInput(['code' => 'A-1']), $this->assetClerk));
        $this->assertNotSame($first, $second);
    }

    public function testUpdate_HidesAnAssetOfABranchTheEmployeeDoesNotWorkIn(): void
    {
        $assetId = $this->enterAsset(['branch_id' => $this->second, 'funding' => 'payable', 'treasury_id' => '']);
        $limited = $this->employeeWithGrants([AccountingGuard::ASSETS_MANAGE], [1]);

        $this->assertRefused(AccountingError::AssetNotFound, fn () => $this->register->update($assetId, $this->assetInput(['name' => 'Renamed']), $limited));
        $this->assertRefused(AccountingError::AssetNotFound, fn () => $this->register->find($assetId, $limited));
    }

    public function testSetActive_TakesAnAssetOutOfServiceAndPutsItBack(): void
    {
        $assetId = $this->enterAsset();

        $this->register->setActive($assetId, false, $this->assetClerk);
        $this->assertSame(AssetStatus::Inactive->value, $this->asset($assetId)->status);

        $this->assertRefused(AccountingError::AssetStatusInvalid, fn () => $this->register->setActive($assetId, false, $this->assetClerk));

        $this->register->setActive($assetId, true, $this->assetClerk);
        $this->assertSame(AssetStatus::Active->value, $this->asset($assetId)->status);
        $this->assertSame(2, $this->accountingDb()->table('accounting_audit_log')->where('entity_type', 'asset')->whereIn('action', ['asset_activated', 'asset_deactivated'])->countAllResults());
    }

    public function testSetActive_RequiresThePermissionToManageTheRegister(): void
    {
        $assetId = $this->enterAsset();

        $this->assertRefused(AccountingError::PermissionDenied, fn () => $this->register->setActive($assetId, false, $this->employeeWithGrants([])));
    }

    public function testDelete_TakesAnAssetEnteredByMistakeOutWithItsJournalAndItsUnits(): void
    {
        $assetId = $this->enterAsset(['units' => [['serial_number' => 'S-1']]]);

        $this->register->delete($assetId, $this->assetClerk);

        $this->assertSame('0.0000', $this->accountNet($this->accountId('fixed_assets')));
        $this->assertSame('0.0000', $this->accountNet($this->treasuryAccount($this->assetTreasury)));
        $this->assertSame(0, $this->accountingDb()->table('asset_units')->countAllResults());
        $this->assertSame(1, (int) $this->asset($assetId)->deleted);
        $this->assertRefused(AccountingError::AssetNotFound, fn () => $this->register->find($assetId));

        $again = $this->enterAsset(['code' => $this->asset($assetId)->code, 'units' => [['serial_number' => 'S-1']]]);

        $this->assertNotSame($assetId, $again, 'the code and the serial number are free again');
    }

    public function testDelete_RefusesAnAssetADepreciationStandsOn(): void
    {
        $assetId = $this->enterAsset();

        $this->standDepreciation($assetId);

        $this->assertRefused(AccountingError::AssetHasHistory, fn () => $this->register->delete($assetId, $this->assetClerk));
        $this->assertSame(0, (int) $this->asset($assetId)->deleted);
    }

    public function testDelete_RefusesAnAssetThatWasSoldOrWrittenOff(): void
    {
        $assetId = $this->enterAsset();

        $this->accountingDb()->table('assets')->where('asset_id', $assetId)->update(['status' => 'written_off']);

        $this->assertRefused(AccountingError::AssetHasHistory, fn () => $this->register->delete($assetId, $this->assetClerk));
    }

    public function testDelete_RequiresThePermissionToManageTheRegister(): void
    {
        $assetId = $this->enterAsset();

        $this->assertRefused(AccountingError::PermissionDenied, fn () => $this->register->delete($assetId, $this->employeeWithGrants([])));
        $this->assertSame(0, (int) $this->asset($assetId)->deleted);
    }

    public function testDelete_TakesTheJournalOfAClosedPeriodBackOnTheDayItIsDeletedAndLeavesThePeriodAlone(): void
    {
        $assetId = $this->enterAsset();
        $journal = $this->journalOf(AssetSources::ACQUISITION, $assetId);

        $this->closeThrough('2026-01-31');
        $this->register->delete($assetId, $this->assetClerk);

        $this->assertSame('2026-01-01', $this->accountingDb()->table('accounting_journals')->where('journal_id', $journal->journal_id)->get()->getRow()->journal_date);
        $this->assertSame(date('Y-m-d'), $this->accountingDb()->table('accounting_journals')->where('reverses_journal_id', $journal->journal_id)->get()->getRow()->journal_date);
        $this->assertSame('0.0000', $this->accountNet($this->accountId('fixed_assets')));
    }

    public function testUpdate_RefusesToPostAgainIntoAClosedPeriodAndChangesNothing(): void
    {
        $assetId = $this->enterAsset();
        $code    = $this->asset($assetId)->code;

        $this->closeThrough('2026-01-31');

        try {
            $this->register->update($assetId, $this->assetInput(['cost' => '15000', 'code' => $code]), $this->assetClerk);
            $this->fail('The purchase date is in a closed period.');
        } catch (PostingException $exception) {
            $this->assertSame(PostingError::PeriodClosed, $exception->error);
        }

        $this->assertSame('12000.0000', $this->asset($assetId)->cost);
        $this->assertSame('12000.0000', $this->accountNet($this->accountId('fixed_assets')));
        $this->assertSame(1, $this->accountingDb()->table('accounting_journals')->where('source_type', AssetSources::ACQUISITION)->countAllResults());
    }

    public function testRecordUsage_KeepsTheUnitsAnAssetWasUsedForInAMonth(): void
    {
        $assetId = $this->enterAsset(['method' => 'units', 'cost_per_unit' => '2.5', 'total_units' => '1000', 'amount_per_period' => '']);

        $this->register->recordUsage($assetId, '2026-02', '120', $this->assetClerk);
        $this->register->recordUsage($assetId, '2026-02', '150.5', $this->assetClerk);

        $rows = $this->accountingDb()->table('asset_usage')->where('asset_id', $assetId)->get()->getResult();

        $this->assertCount(1, $rows);
        $this->assertSame('150.50', $rows[0]->units);
        $this->assertSame('2026-02-01', $rows[0]->period_start);
    }

    public function testRecordUsage_RefusesUnitsPastWhatTheAssetHasInAll(): void
    {
        $assetId = $this->enterAsset(['method' => 'units', 'cost_per_unit' => '2.5', 'total_units' => '1000', 'amount_per_period' => '']);

        $this->register->recordUsage($assetId, '2026-02', '900', $this->assetClerk);

        $this->assertRefused(AccountingError::AssetUsageOverTotal, fn () => $this->register->recordUsage($assetId, '2026-03', '101', $this->assetClerk));

        $this->register->recordUsage($assetId, '2026-03', '100', $this->assetClerk);
        $this->register->recordUsage($assetId, '2026-02', '900', $this->assetClerk);

        $this->assertSame(2, $this->countRows('asset_usage'));
    }

    public function testRecordUsage_RefusesAMonthThatIsDepreciatedAlready(): void
    {
        $assetId = $this->enterAsset(['method' => 'units', 'cost_per_unit' => '2.5', 'amount_per_period' => '']);

        $this->standDepreciation($assetId, '2026-02-01');

        $this->assertRefused(AccountingError::AssetUsageLocked, fn () => $this->register->recordUsage($assetId, '2026-02', '10', $this->assetClerk));
    }

    public function testRecordUsage_RefusesAnAssetOfAnotherMethodAndAMonthThatIsNotOne(): void
    {
        $fixed = $this->enterAsset();
        $units = $this->enterAsset(['method' => 'units', 'cost_per_unit' => '2.5', 'amount_per_period' => '', 'name' => 'Press']);

        $this->assertRefused(AccountingError::AssetUsageInvalid, fn () => $this->register->recordUsage($fixed, '2026-02', '10', $this->assetClerk));
        $this->assertRefused(AccountingError::AssetUsageInvalid, fn () => $this->register->recordUsage($units, '2026-13', '10', $this->assetClerk));
        $this->assertRefused(AccountingError::AssetUsageInvalid, fn () => $this->register->recordUsage($units, '2026-02', '-1', $this->assetClerk));
    }

    public function testRecordUsage_RequiresThePermissionToManageTheRegister(): void
    {
        $assetId = $this->enterAsset(['method' => 'units', 'cost_per_unit' => '2.5', 'amount_per_period' => '']);

        $this->assertRefused(AccountingError::PermissionDenied, fn () => $this->register->recordUsage($assetId, '2026-02', '10', $this->employeeWithGrants([])));
        $this->assertSame(0, $this->countRows('asset_usage'));
    }

    public function testFind_GivesWhatTheAssetHasDepreciatedByAndWhatIsLeftOfIt(): void
    {
        $assetId = $this->enterAsset();

        $this->standDepreciation($assetId, '2026-02-01', '1000.0000');

        $asset = $this->register->find($assetId);

        $this->assertSame('1000.0000', $asset->accumulated);
        $this->assertSame('11000.0000', $asset->book_value);
        $this->assertSame('Office desk', $asset->name);
        $this->assertSame('HQ', $asset->branch_code);
    }

    public function testCreate_WritesAnEntryOfTheAuditLog(): void
    {
        $assetId = $this->enterAsset();

        $entry = $this->accountingDb()->table('accounting_audit_log')->where(['entity_type' => 'asset', 'entity_id' => $assetId, 'action' => 'asset_created'])->get()->getRow();

        $this->assertNotNull($entry);
        $this->assertSame($this->assetClerk, (int) $entry->employee_id);
        $this->assertSame(1, (int) $entry->branch_id);
    }

    public function testUpdate_RequiresTheOpeningPermissionToChangeAnAssetIntoAnOpeningBalance(): void
    {
        $this->setConfigValue('accounting_start_date', '2026-01-01');

        $assetId = $this->enterAsset();

        $this->assertRefused(AccountingError::PermissionDenied, fn () => $this->register->update($assetId, $this->assetInput(['funding' => 'opening', 'treasury_id' => '', 'opening_accumulated' => '1000']), $this->assetClerk));

        $this->assertSame('treasury', $this->asset($assetId)->funding);
        $this->assertSame('0.0000', $this->asset($assetId)->opening_accumulated);
    }

    public function testUpdate_RequiresTheOpeningPermissionToChangeTheTermsOfAnOpeningAsset(): void
    {
        $this->setConfigValue('accounting_start_date', '2026-01-01');
        $opener  = $this->employeeWithGrants([AccountingGuard::ASSETS_MANAGE, AccountingGuard::OPENING_MANAGE]);
        $assetId = $this->enterAsset(['funding' => 'opening', 'treasury_id' => '', 'purchase_date' => '2024-01-01', 'in_service_date' => '2024-01-01', 'opening_accumulated' => '4000'], $opener);
        $moved   = $this->assetInput(['funding' => 'payable', 'treasury_id' => '', 'purchase_date' => '2024-01-01', 'in_service_date' => '2024-01-01']);

        $this->assertRefused(AccountingError::PermissionDenied, fn () => $this->register->update($assetId, $moved, $this->assetClerk));

        $this->assertSame('opening', $this->asset($assetId)->funding);
        $this->assertSame(JournalStatus::Posted->value, $this->journalOf(AssetSources::ACQUISITION, $assetId)->status);
        $this->assertNull($this->journalOf(AssetSources::ACQUISITION, $assetId, 2));

        $this->register->update($assetId, $moved, $opener);

        $this->assertSame('payable', $this->asset($assetId)->funding);
        $this->assertSame(JournalStatus::Reversed->value, $this->journalOf(AssetSources::ACQUISITION, $assetId)->status);
    }

    public function testUpdate_AsksNoOpeningPermissionForWhatTouchesNoBalanceOfAnOpeningAsset(): void
    {
        $this->setConfigValue('accounting_start_date', '2026-01-01');
        $opener  = $this->employeeWithGrants([AccountingGuard::ASSETS_MANAGE, AccountingGuard::OPENING_MANAGE]);
        $assetId = $this->enterAsset(['funding' => 'opening', 'treasury_id' => '', 'purchase_date' => '2024-01-01', 'in_service_date' => '2024-01-01', 'opening_accumulated' => '4000'], $opener);

        $this->register->update($assetId, $this->assetInput(['name' => 'Renamed desk', 'funding' => 'opening', 'treasury_id' => '', 'purchase_date' => '2024-01-01', 'in_service_date' => '2024-01-01', 'opening_accumulated' => '4000']), $this->assetClerk);

        $this->assertSame('Renamed desk', $this->asset($assetId)->name);
        $this->assertSame(JournalStatus::Posted->value, $this->journalOf(AssetSources::ACQUISITION, $assetId)->status);
    }

    public function testDelete_RequiresTheOpeningPermissionToTakeAnOpeningAssetOut(): void
    {
        $this->setConfigValue('accounting_start_date', '2026-01-01');
        $opener  = $this->employeeWithGrants([AccountingGuard::ASSETS_MANAGE, AccountingGuard::OPENING_MANAGE]);
        $assetId = $this->enterAsset(['funding' => 'opening', 'treasury_id' => '', 'purchase_date' => '2024-01-01', 'in_service_date' => '2024-01-01', 'opening_accumulated' => '4000'], $opener);

        $this->assertRefused(AccountingError::PermissionDenied, fn () => $this->register->delete($assetId, $this->assetClerk));

        $this->assertSame(0, (int) $this->asset($assetId)->deleted);
        $this->assertSame(JournalStatus::Posted->value, $this->journalOf(AssetSources::ACQUISITION, $assetId)->status);
        $this->assertSame('12000.0000', $this->accountNet($this->accountId('fixed_assets')));

        $this->register->delete($assetId, $opener);

        $this->assertSame(1, (int) $this->asset($assetId)->deleted);
        $this->assertSame(JournalStatus::Reversed->value, $this->journalOf(AssetSources::ACQUISITION, $assetId)->status);
    }

    public function testCreate_TakesTheBranchLockBeforeItWritesTheAsset(): void
    {
        $this->assertTakesTheBranchLockBeforeTheAsset(fn () => $this->enterAsset());
    }

    public function testUpdate_TakesTheBranchLockBeforeItLocksTheAsset(): void
    {
        $assetId = $this->enterAsset();

        $this->assertTakesTheBranchLockBeforeTheAsset(fn () => $this->register->update($assetId, $this->assetInput(['name' => 'Renamed desk']), $this->assetClerk));
    }

    public function testUpdate_TakesTheLocksOfBothBranchesInTheOrderOfTheirNumbersWhenItMovesTheAsset(): void
    {
        $assetId  = $this->enterAsset(['branch_id' => $this->second, 'funding' => 'payable', 'treasury_id' => '']);
        $locked   = [];
        $listener = static function ($query) use (&$locked): void {
            if (preg_match('/accounting_period_locks WHERE company_id = \d+ AND branch_id = ([1-9]\d*) LOCK IN SHARE MODE/', (string) $query->getQuery(), $found) === 1) {
                $locked[] = (int) $found[1];
            }
        };

        Events::on('DBQuery', $listener);

        try {
            $this->register->update($assetId, $this->assetInput(['branch_id' => 1, 'funding' => 'payable', 'treasury_id' => '']), $this->assetClerk);
        } finally {
            Events::removeListener('DBQuery', $listener);
        }

        $this->assertSame([1, $this->second], array_slice(array_values(array_unique($locked)), 0, 2));
    }

    public function testSetActive_TakesTheBranchLockBeforeItLocksTheAsset(): void
    {
        $assetId = $this->enterAsset();

        $this->assertTakesTheBranchLockBeforeTheAsset(fn () => $this->register->setActive($assetId, false, $this->assetClerk));
    }

    public function testDelete_TakesTheBranchLockBeforeItLocksTheAsset(): void
    {
        $assetId = $this->enterAsset();

        $this->assertTakesTheBranchLockBeforeTheAsset(fn () => $this->register->delete($assetId, $this->assetClerk));
    }

    public function testRecordUsage_TakesTheBranchLockBeforeItLocksTheAsset(): void
    {
        $assetId = $this->enterAsset(['method' => 'units', 'cost_per_unit' => '2.5', 'amount_per_period' => '']);

        $this->assertTakesTheBranchLockBeforeTheAsset(fn () => $this->register->recordUsage($assetId, '2026-02', '10', $this->assetClerk));
    }

    public function testRecordUsage_RefusesAnAssetThatWasWrittenOff(): void
    {
        $assetId = $this->enterAsset(['method' => 'units', 'cost_per_unit' => '2.5', 'amount_per_period' => '']);

        (new AssetDisposals())->writeOff($assetId, ['date' => '2026-01-20'], $this->assetClerk);

        $this->assertRefused(AccountingError::AssetUsageInvalid, fn () => $this->register->recordUsage($assetId, '2026-02', '10', $this->assetClerk));
        $this->assertSame(0, $this->countRows('asset_usage'));
    }

    public function testSetActive_HidesAnAssetOfABranchTheEmployeeDoesNotWorkIn(): void
    {
        $assetId = $this->enterAsset(['branch_id' => $this->second, 'funding' => 'payable', 'treasury_id' => '']);
        $limited = $this->employeeWithGrants([AccountingGuard::ASSETS_MANAGE], [1]);

        $this->assertRefused(AccountingError::AssetNotFound, fn () => $this->register->setActive($assetId, false, $limited));
        $this->assertSame(AssetStatus::Active->value, $this->asset($assetId)->status);
    }

    public function testRecordUsage_HidesAnAssetOfABranchTheEmployeeDoesNotWorkIn(): void
    {
        $assetId = $this->enterAsset(['branch_id' => $this->second, 'funding' => 'payable', 'treasury_id' => '', 'method' => 'units', 'cost_per_unit' => '2.5', 'amount_per_period' => '']);
        $limited = $this->employeeWithGrants([AccountingGuard::ASSETS_MANAGE], [1]);

        $this->assertRefused(AccountingError::AssetNotFound, fn () => $this->register->recordUsage($assetId, '2026-02', '10', $limited));
        $this->assertSame(0, $this->countRows('asset_usage'));
    }

    public function testCreate_RefusesABranchThatIsSwitchedOff(): void
    {
        $this->accountingDb()->table('branches')->where('branch_id', $this->second)->update(['active' => 0]);

        try {
            $this->enterAsset(['branch_id' => $this->second, 'funding' => 'payable', 'treasury_id' => '']);
            $this->fail('The branch is switched off.');
        } catch (BranchException $exception) {
            $this->assertSame(BranchError::Inactive, $exception->error);
        }

        $this->assertSame(0, $this->countRows('assets'));
    }

    public function testUpdate_ChangesAnAssetOfABranchThatWasSwitchedOffAfterItWasEntered(): void
    {
        $assetId = $this->enterAsset(['branch_id' => $this->second, 'funding' => 'payable', 'treasury_id' => '']);

        $this->accountingDb()->table('branches')->where('branch_id', $this->second)->update(['active' => 0]);
        $this->register->update($assetId, $this->assetInput(['branch_id' => $this->second, 'funding' => 'payable', 'treasury_id' => '', 'name' => 'Renamed']), $this->assetClerk);

        $this->assertSame('Renamed', $this->asset($assetId)->name);
    }

    public function testUpdate_WritesAnEntryOfTheAuditLogWithTheTermsThatChanged(): void
    {
        $assetId = $this->enterAsset();

        $this->register->update($assetId, $this->assetInput(['cost' => '13000']), $this->assetClerk);

        $entry = $this->accountingDb()->table('accounting_audit_log')->where(['entity_type' => 'asset', 'entity_id' => $assetId, 'action' => 'asset_updated'])->get()->getRow();

        $this->assertNotNull($entry);
        $this->assertSame($this->assetClerk, (int) $entry->employee_id);
        $this->assertSame(['terms' => ['cost']], json_decode((string) $entry->details, true));
    }

    public function testDelete_WritesAnEntryOfTheAuditLog(): void
    {
        $assetId = $this->enterAsset();

        $this->register->delete($assetId, $this->assetClerk);

        $entry = $this->accountingDb()->table('accounting_audit_log')->where(['entity_type' => 'asset', 'entity_id' => $assetId, 'action' => 'asset_deleted'])->get()->getRow();

        $this->assertNotNull($entry);
        $this->assertSame($this->assetClerk, (int) $entry->employee_id);
    }

    public function testRecordUsage_WritesAnEntryOfTheAuditLog(): void
    {
        $assetId = $this->enterAsset(['method' => 'units', 'cost_per_unit' => '2.5', 'amount_per_period' => '']);

        $this->register->recordUsage($assetId, '2026-02', '120', $this->assetClerk);

        $entry = $this->accountingDb()->table('accounting_audit_log')->where(['entity_type' => 'asset', 'entity_id' => $assetId, 'action' => 'asset_usage_recorded'])->get()->getRow();

        $this->assertNotNull($entry);
        $this->assertSame(['month' => '2026-02', 'units' => '120.00'], json_decode((string) $entry->details, true));
    }

    public function testCreate_RefusesDepreciationAlreadyHadForAnAssetThatIsNotDepreciated(): void
    {
        $this->setConfigValue('accounting_start_date', '2026-01-01');

        $this->assertRefused(AccountingError::AssetFieldInvalid, fn () => $this->enterAsset(['funding' => 'none', 'treasury_id' => '', 'method' => 'none', 'opening_accumulated' => '1000']));
        $this->assertSame(0, $this->countRows('assets'));
    }

    public function testCreate_RefusesAnEndDateBeforeTheServiceDateForAnAssetOfUnits(): void
    {
        $this->assertRefused(AccountingError::AssetFieldInvalid, fn () => $this->enterAsset(['method' => 'units', 'cost_per_unit' => '2.5', 'amount_per_period' => '', 'in_service_date' => '2026-03-01', 'end_date' => '2026-02-01']));
        $this->assertSame(0, $this->countRows('assets'));
    }

    public function testUpdate_KeepsTheSerialNumberAndTheBarcodeTheAssetHasAlready(): void
    {
        $assetId = $this->enterAsset(['units' => [['serial_number' => 'S-1', 'barcode' => 'B-1']]]);

        $this->register->update($assetId, $this->assetInput(['name' => 'Renamed', 'units' => [['serial_number' => 'S-1', 'barcode' => 'B-1']]]), $this->assetClerk);

        $units = $this->accountingDb()->table('asset_units')->where('asset_id', $assetId)->get()->getResult();

        $this->assertCount(1, $units);
        $this->assertSame('S-1', $units[0]->serial_number);
        $this->assertSame('B-1', $units[0]->barcode);
        $this->assertSame('Renamed', $this->asset($assetId)->name);
    }

    public function testCreate_RefusesACustodyThatIsNotOneOfTheThree(): void
    {
        $this->assertRefused(AccountingError::AssetFieldInvalid, fn () => $this->enterAsset(['units' => [['serial_number' => 'S-1', 'used_by' => 'borrowed']]]));
        $this->assertSame(0, $this->countRows('assets'));
    }

    public function testCreate_RefusesADateBeforeTheYear1900AndAfterTheYear2200(): void
    {
        $this->assertRefused(AccountingError::AssetFieldInvalid, fn () => $this->enterAsset(['purchase_date' => '1899-12-31', 'in_service_date' => '1899-12-31']));
        $this->assertRefused(AccountingError::AssetFieldInvalid, fn () => $this->enterAsset(['purchase_date' => '2201-01-01', 'in_service_date' => '2201-01-01']));
        $this->assertSame(0, $this->countRows('assets'));
    }

    public function testCreate_RefusesACodeLongerThanSixtyFourCharacters(): void
    {
        $this->assertRefused(AccountingError::AssetFieldInvalid, fn () => $this->enterAsset(['code' => str_repeat('A', 65)]));

        $this->enterAsset(['code' => str_repeat('B', 64)]);

        $this->assertSame(1, $this->countRows('assets'));
    }

    public function testCreate_RefusesTotalUnitsThatAreNotANumberAboveZero(): void
    {
        foreach (['0', 'abc', '-5', '1.234'] as $total) {
            $this->assertRefused(AccountingError::AssetFieldInvalid, fn () => $this->enterAsset(['method' => 'units', 'cost_per_unit' => '2.5', 'amount_per_period' => '', 'total_units' => $total]));
        }

        $this->assertSame(0, $this->countRows('assets'));
    }

    /**
     * An active line of a run for the month, as a depreciation would leave it, without the journal.
     */
    private function standDepreciation(int $assetId, string $periodStart = '2026-02-01', string $amount = '1000.0000'): void
    {
        $db = $this->accountingDb();

        $db->table('asset_depreciation_runs')->insert(['company_id' => 1, 'branch_id' => 1, 'period_start' => $periodStart, 'period_end' => date('Y-m-t', strtotime($periodStart)), 'run_number' => 1, 'total' => $amount, 'line_count' => 1, 'fingerprint' => str_repeat('a', 64), 'status' => 'active']);
        $db->table('asset_depreciations')->insert(['company_id' => 1, 'branch_id' => 1, 'asset_id' => $assetId, 'run_id' => (int) $db->insertID(), 'period_start' => $periodStart, 'amount' => $amount, 'active_key' => 1]);
    }

    public function testCreate_RefusesACategoryThatDoesNotExist(): void
    {
        $this->assertRefused(AccountingError::AssetCategoryNotFound, fn () => $this->enterAsset(['category_id' => 999999]));
        $this->assertSame(0, $this->countRows('assets'));
    }

    public function testCreate_RefusesATreasuryTheEmployeeMayNotTakeMoneyFrom(): void
    {
        $someoneElse = $this->employeeWithGrants([]);
        $treasury    = (new TreasuryService())->create(['name' => 'Locked cash ' . uniqid(), 'kind' => 'cash', 'branch_id' => 1, 'withdraw_scope' => 'staff', 'withdraw_members' => [$someoneElse]], $this->assetClerk);

        $this->assertRefused(AccountingError::TreasuryWithdrawDenied, fn () => $this->enterAsset(['treasury_id' => $treasury]));
        $this->assertSame(0, $this->countRows('assets'));
        $this->assertSame(0, $this->countRows('accounting_journals'));
    }
}
