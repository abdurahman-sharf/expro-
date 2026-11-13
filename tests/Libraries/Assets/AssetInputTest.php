<?php

namespace Tests\Libraries\Assets;

use App\Libraries\Accounting\AccountingError;
use App\Libraries\Accounting\AccountingException;
use App\Libraries\Accounting\AccountingSettings;
use App\Libraries\Accounting\Currencies;
use App\Libraries\Accounting\RoutingResolver;
use App\Libraries\Assets\AssetInput;
use App\Libraries\Assets\DepreciationSchedule;
use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\DatabaseTestTrait;
use Config\Database;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Support\AccountingFixtureTrait;
use Tests\Support\AssetFixtureTrait;

/**
 * What the form of an asset says, cut to the row the tables hold: the first depreciation date, the
 * fields each method takes, the accounts, what a category starts an asset from, how an old asset comes
 * in and the rules of the units.
 *
 * @internal
 */
final class AssetInputTest extends CIUnitTestCase
{
    use DatabaseTestTrait;
    use AccountingFixtureTrait;
    use AssetFixtureTrait;

    protected $migrate     = true;
    protected $migrateOnce = true;
    protected $refresh     = false;
    protected $namespace;
    private AssetInput $input;

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

        $db          = Database::connect();
        $this->input = new AssetInput($db, new DepreciationSchedule((new Currencies($db))->baseDecimals()), new AccountingSettings($db), new RoutingResolver($db));
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

    /**
     * @param array<string, mixed> $overrides
     *
     * @return array{row: array<string, mixed>, units: list<array<string, mixed>>}
     */
    private function prepare(array $overrides = [], ?object $existing = null): array
    {
        return $this->input->prepare($this->assetInput($overrides), $existing);
    }

    public function testPrepare_CutsTheFormToTheRowTheTablesHold(): void
    {
        $row = $this->prepare(['name' => '  Office desk  ', 'salvage' => '500'])['row'];

        $this->assertSame('Office desk', $row['name']);
        $this->assertSame('12000.0000', $row['cost']);
        $this->assertSame('500.0000', $row['salvage']);
        $this->assertSame('fixed', $row['method']);
        $this->assertSame('1000.0000', $row['amount_per_period']);
        $this->assertSame('treasury', $row['funding']);
        $this->assertSame($this->assetTreasury, $row['treasury_id']);
        $this->assertSame($this->accountId('fixed_assets'), $row['cost_account_id']);
        $this->assertSame($this->accountId('accumulated_depreciation'), $row['accumulated_account_id']);
        $this->assertSame($this->accountId('depreciation_expense'), $row['expense_account_id']);
        $this->assertSame(1, $row['quantity']);
        $this->assertSame(0, $row['track_units']);
        $this->assertNull($row['opening_through']);
    }

    /**
     * @return array<string, array{string, string, string, string}>
     */
    public static function firstDateProvider(): array
    {
        return [
            'a month clamps to the month end'     => ['2026-01-31', '1', 'month', '2026-02-28'],
            'thirteen months clamp the same way'  => ['2026-01-31', '13', 'month', '2027-02-28'],
            'days add the period'                 => ['2026-01-15', '10', 'day', '2026-01-25'],
            'weeks add seven days for each'       => ['2026-01-15', '2', 'week', '2026-01-29'],
        ];
    }

    #[DataProvider('firstDateProvider')]
    public function testPrepare_DefaultsTheFirstDepreciationDateToTheDayOfGoingIntoServicePlusAPeriod(string $service, string $length, string $interval, string $expected): void
    {
        $row = $this->prepare(['purchase_date' => $service, 'in_service_date' => $service, 'period_length' => $length, 'period_interval' => $interval])['row'];

        $this->assertSame($expected, $row['first_depreciation_date']);
    }

    public function testPrepare_KeepsTheFirstDepreciationDateTheFormGivesAndRefusesOneBeforeTheServiceDate(): void
    {
        $this->assertSame('2026-03-15', $this->prepare(['first_depreciation_date' => '2026-03-15'])['row']['first_depreciation_date']);

        $exception = $this->assertRefused(AccountingError::AssetFirstDateInvalid, fn () => $this->prepare(['first_depreciation_date' => '2025-12-31']));

        $this->assertSame('2025-12-31', $exception->context['date']);
    }

    public function testPrepare_ClearsTheFieldsAMethodDoesNotTake(): void
    {
        $none = $this->prepare(['method' => 'none', 'salvage' => '500', 'percentage' => '10', 'cost_per_unit' => '2', 'first_depreciation_date' => '2026-03-01'])['row'];

        $this->assertSame('0.0000', $none['salvage']);
        $this->assertNull($none['accumulated_account_id']);
        $this->assertNull($none['expense_account_id']);
        $this->assertNull($none['amount_per_period']);
        $this->assertNull($none['percentage']);
        $this->assertNull($none['first_depreciation_date']);

        $units = $this->prepare(['method' => 'units', 'cost_per_unit' => '2.5', 'amount_per_period' => '1000', 'percentage' => '10', 'unit_name' => 'km', 'total_units' => '4800'])['row'];

        $this->assertNull($units['amount_per_period']);
        $this->assertNull($units['percentage']);
        $this->assertNull($units['first_depreciation_date']);
        $this->assertSame('2.500000', $units['cost_per_unit']);
        $this->assertSame('km', $units['unit_name']);
        $this->assertSame('4800.00', $units['total_units']);

        $declining = $this->prepare(['method' => 'declining', 'percentage' => '20', 'amount_per_period' => '1000'])['row'];

        $this->assertNull($declining['amount_per_period']);
        $this->assertSame('20.0000', $declining['percentage']);
    }

    public function testPrepare_SpreadsWhatIsToBeDepreciatedOverTheLifeWhenTheFormGivesTheLifeAndNoAmount(): void
    {
        $row = $this->prepare(['amount_per_period' => '', 'useful_life_value' => '2', 'useful_life_unit' => 'years', 'salvage' => '1200'])['row'];

        $this->assertSame(24, $row['useful_life_months']);
        $this->assertSame('450.0000', $row['amount_per_period'], '(12,000 less 1,200) over 24 months');
        $this->assertSame('2028-01-01', $row['end_date']);
    }

    public function testPrepare_RefusesAFixedAssetWithNeitherAnAmountNorALife(): void
    {
        $this->assertSame('amount_per_period', $this->assertRefused(AccountingError::AssetFieldInvalid, fn () => $this->prepare(['amount_per_period' => '']))->context['name']);
    }

    /**
     * @return array<string, array{array<string, mixed>, string}>
     */
    public static function invalidFieldProvider(): array
    {
        return [
            'no name'                       => [['name' => ' '], 'name'],
            'a cost of zero'                => [['cost' => '0'], 'cost'],
            'a cost that is not a number'   => [['cost' => 'abc'], 'cost'],
            'a cost with five decimals'     => [['cost' => '1.00001'], 'cost'],
            'a salvage above the cost'      => [['salvage' => '12001'], 'salvage'],
            'a date that does not exist'    => [['purchase_date' => '2026-02-30'], 'purchase_date'],
            'no service date'               => [['in_service_date' => ''], 'in_service_date'],
            'a method that is not one'      => [['method' => 'sum_of_years'], 'method'],
            'a percentage above 100'        => [['method' => 'declining', 'percentage' => '101'], 'percentage'],
            'a period of zero'              => [['period_length' => '0'], 'period_length'],
            'an interval that is not one'   => [['period_interval' => 'year'], 'period_interval'],
            'a quantity of zero'            => [['quantity' => '0'], 'quantity'],
            'an end date before the first'  => [['first_depreciation_date' => '2026-03-01', 'end_date' => '2026-02-01'], 'end_date'],
            'no cost for a unit of use'     => [['method' => 'units', 'cost_per_unit' => ''], 'cost_per_unit'],
            'a life of 1201 months'         => [['useful_life_value' => '1201', 'useful_life_unit' => 'months'], 'useful_life_months'],
        ];
    }

    /**
     * @param array<string, mixed> $overrides
     */
    #[DataProvider('invalidFieldProvider')]
    public function testPrepare_RejectsAFieldThatIsNotValid(array $overrides, string $field): void
    {
        $this->assertSame($field, $this->assertRefused(AccountingError::AssetFieldInvalid, fn () => $this->prepare($overrides))->context['name']);
    }

    public function testPrepare_StartsANewAssetFromTheMethodAndTheLifeOfItsCategory(): void
    {
        $category = $this->categories->save(['name' => 'Vehicles', 'method' => 'declining', 'life_months' => '60'], $this->assetClerk);

        $row = $this->prepare(['category_id' => $category, 'method' => '', 'percentage' => '20', 'useful_life_value' => '', 'amount_per_period' => ''])['row'];

        $this->assertSame('declining', $row['method']);
        $this->assertSame(60, $row['useful_life_months']);
        $this->assertSame('2031-01-01', $row['end_date']);
    }

    public function testPrepare_KeepsTheMethodAndTheLifeTheFormGivesOverThoseOfTheCategory(): void
    {
        $category = $this->categories->save(['name' => 'Vehicles', 'method' => 'declining', 'life_months' => '60'], $this->assetClerk);

        $row = $this->prepare(['category_id' => $category, 'method' => 'fixed', 'useful_life_value' => '24', 'useful_life_unit' => 'months'])['row'];

        $this->assertSame('fixed', $row['method']);
        $this->assertSame(24, $row['useful_life_months']);
    }

    public function testPrepare_StartsNoExistingAssetFromItsCategory(): void
    {
        $category = $this->categories->save(['name' => 'Vehicles', 'method' => 'declining', 'life_months' => '60'], $this->assetClerk);
        $assetId  = $this->enterAsset(['category_id' => $category]);
        $existing = $this->accountingDb()->table('assets')->where('asset_id', $assetId)->get()->getRow();

        $row = $this->prepare(['category_id' => $category, 'useful_life_value' => ''], $existing)['row'];

        $this->assertNull($row['useful_life_months'], 'an asset that is changed is not started again');
        $this->assertNull($row['end_date']);

        $this->assertRefused(AccountingError::AssetFieldInvalid, fn () => $this->prepare(['category_id' => $category, 'method' => ''], $existing));
    }

    public function testPrepare_RefusesAMissingMethodWhenTheCategoryHasNoneEither(): void
    {
        $category = $this->categories->save(['name' => 'Furniture'], $this->assetClerk);

        $this->assertSame('method', $this->assertRefused(AccountingError::AssetFieldInvalid, fn () => $this->prepare(['category_id' => $category, 'method' => '']))->context['name']);
    }

    public function testPrepare_RefusesACategoryThatIsSwitchedOffOrDoesNotExist(): void
    {
        $category = $this->categories->save(['name' => 'Furniture'], $this->assetClerk);

        $this->categories->setActive($category, false, $this->assetClerk);

        $this->assertRefused(AccountingError::AssetCategoryNotFound, fn () => $this->prepare(['category_id' => $category]));
        $this->assertRefused(AccountingError::AssetCategoryNotFound, fn () => $this->prepare(['category_id' => 999999]));
    }

    public function testPrepare_TakesTheAccountChosenThenThatOfTheCategoryThenTheRouting(): void
    {
        $categoryCost = $this->customAccount('111', '111010', 'Vehicles cost');
        $chosenCost   = $this->customAccount('111', '111011', 'Chosen cost');
        $category     = $this->categories->save(['name' => 'Vehicles', 'cost_account_id' => $categoryCost], $this->assetClerk);

        $this->assertSame($this->accountId('fixed_assets'), $this->prepare()['row']['cost_account_id'], 'the routing');
        $this->assertSame($categoryCost, $this->prepare(['category_id' => $category])['row']['cost_account_id'], 'the category');
        $this->assertSame($chosenCost, $this->prepare(['category_id' => $category, 'cost_account_id' => $chosenCost])['row']['cost_account_id'], 'the account chosen');
    }

    public function testPrepare_RefusesAnAccountThatCannotBeThatOfAnAsset(): void
    {
        $group = $this->accountIdByCode('111001');

        $this->assertRefused(AccountingError::AssetAccountInvalid, fn () => $this->prepare(['cost_account_id' => $group]));
        $this->assertRefused(AccountingError::AssetAccountInvalid, fn () => $this->prepare(['accumulated_account_id' => $this->accountId('cogs')]));
        $this->assertRefused(AccountingError::AssetAccountInvalid, fn () => $this->prepare(['accumulated_account_id' => $this->accountId('fixed_assets')]));
    }

    public function testPrepare_RefusesAnAccumulatedDepreciationKeptInTheCostAccount(): void
    {
        $exception = $this->assertRefused(AccountingError::AssetAccountInvalid, fn () => $this->prepare(['accumulated_account_id' => $this->accountId('fixed_assets')]));

        $this->assertSame('accumulated', $exception->context['name']);
    }

    public function testPrepare_ClosesTheDepreciationAnOldAssetHadOverTheDayBeforeTheBooksStart(): void
    {
        $this->setConfigValue('accounting_start_date', '2026-01-01');

        $row = $this->prepare(['funding' => 'opening', 'treasury_id' => '', 'opening_accumulated' => '4000'])['row'];

        $this->assertSame('4000.0000', $row['opening_accumulated']);
        $this->assertSame('2025-12-31', $row['opening_through']);
        $this->assertNull($row['treasury_id']);
    }

    public function testPrepare_AllowsDepreciationAlreadyHadOnlyForAnAssetBroughtInAsOldOrAsAlreadyInTheBooks(): void
    {
        $this->setConfigValue('accounting_start_date', '2026-01-01');

        $this->assertRefused(AccountingError::AssetFundingInvalid, fn () => $this->prepare(['opening_accumulated' => '100']));
        $this->assertRefused(AccountingError::AssetFundingInvalid, fn () => $this->prepare(['funding' => 'payable', 'treasury_id' => '', 'opening_accumulated' => '100']));
        $this->assertSame('100.0000', $this->prepare(['funding' => 'none', 'treasury_id' => '', 'opening_accumulated' => '100'])['row']['opening_accumulated']);
    }

    public function testPrepare_RefusesDepreciationAlreadyHadAboveWhatIsToBeDepreciatedOrForAnAssetThatIsNot(): void
    {
        $this->setConfigValue('accounting_start_date', '2026-01-01');

        $this->assertSame('opening_accumulated', $this->assertRefused(AccountingError::AssetFieldInvalid, fn () => $this->prepare(['funding' => 'opening', 'treasury_id' => '', 'salvage' => '2000', 'opening_accumulated' => '10001']))->context['name']);
        $this->assertSame('opening_accumulated', $this->assertRefused(AccountingError::AssetFieldInvalid, fn () => $this->prepare(['funding' => 'opening', 'treasury_id' => '', 'method' => 'none', 'opening_accumulated' => '1']))->context['name']);
    }

    public function testPrepare_RefusesAnOpeningAssetWhileTheStartDateOfTheBooksIsNotSet(): void
    {
        $this->accountingDb()->table('app_config')->where('key', 'accounting_start_date')->delete();

        $this->assertRefused(AccountingError::OpeningStartDateMissing, fn () => $this->prepare(['funding' => 'opening', 'treasury_id' => '']));
    }

    public function testPrepare_RefusesAFundingThatIsNotOneAndATreasuryThatIsNotActiveOrNotGiven(): void
    {
        $this->assertRefused(AccountingError::AssetFundingInvalid, fn () => $this->prepare(['funding' => 'gift']));
        $this->assertRefused(AccountingError::AssetFundingInvalid, fn () => $this->prepare(['treasury_id' => '']));
        $this->assertRefused(AccountingError::AssetFundingInvalid, fn () => $this->prepare(['treasury_id' => 999999]));
    }

    public function testPrepare_RefusesASupplierAndACostCenterThatDoNotExist(): void
    {
        $this->assertSame('supplier_id', $this->assertRefused(AccountingError::AssetFieldInvalid, fn () => $this->prepare(['supplier_id' => 999999]))->context['name']);
        $this->assertSame('cost_center_id', $this->assertRefused(AccountingError::AssetFieldInvalid, fn () => $this->prepare(['cost_center_id' => 999999]))->context['name']);
    }

    public function testPrepare_MakesOneRowForEachUnitOfAnAssetThatDefinesItsUnits(): void
    {
        $prepared = $this->prepare(['track_units' => '1', 'quantity' => '2', 'units' => [['serial_number' => ' S-1 '], ['barcode' => 'B-2', 'physical_location' => 'Floor 2']]]);

        $this->assertSame(2, $prepared['row']['quantity']);
        $this->assertSame(1, $prepared['row']['track_units']);
        $this->assertSame(['S-1', null], array_column($prepared['units'], 'serial_number'));
        $this->assertSame([null, 'B-2'], array_column($prepared['units'], 'barcode'));
        $this->assertSame([1, 2], array_column($prepared['units'], 'position'));
        $this->assertSame('Floor 2', $prepared['units'][1]['physical_location']);
    }

    public function testPrepare_RefusesRowsThatDoNotMatchTheQuantityOfAnAssetThatDefinesItsUnits(): void
    {
        $this->assertRefused(AccountingError::AssetUnitsMismatch, fn () => $this->prepare(['track_units' => '1', 'quantity' => '3', 'units' => [['serial_number' => 'S-1']]]));
        $this->assertRefused(AccountingError::AssetUnitsMismatch, fn () => $this->prepare(['quantity' => '3', 'units' => [['serial_number' => 'S-1'], ['serial_number' => 'S-2']]]));
    }

    public function testPrepare_RequiresASerialNumberOrABarcodeForEachUnitAndNamesTheLine(): void
    {
        $exception = $this->assertRefused(AccountingError::AssetUnitIdentityRequired, fn () => $this->prepare(['track_units' => '1', 'quantity' => '2', 'units' => [['serial_number' => 'S-1'], ['physical_location' => 'Floor 2']]]));

        $this->assertSame(2, $exception->context['line']);
    }

    public function testPrepare_RefusesASerialNumberOrABarcodeThatIsRepeatedOrUsedByAnotherAsset(): void
    {
        $this->enterAsset(['track_units' => '1', 'quantity' => '1', 'units' => [['serial_number' => 'S-77', 'barcode' => 'B-77']]]);

        $this->assertRefused(AccountingError::AssetSerialTaken, fn () => $this->prepare(['track_units' => '1', 'quantity' => '2', 'units' => [['serial_number' => 'S-1'], ['serial_number' => 's-1']]]));
        $this->assertRefused(AccountingError::AssetBarcodeTaken, fn () => $this->prepare(['track_units' => '1', 'quantity' => '2', 'units' => [['barcode' => 'B-1'], ['barcode' => 'B-1']]]));
        $this->assertRefused(AccountingError::AssetSerialTaken, fn () => $this->prepare(['track_units' => '1', 'units' => [['serial_number' => 'S-77']]]));
        $this->assertRefused(AccountingError::AssetBarcodeTaken, fn () => $this->prepare(['track_units' => '1', 'units' => [['barcode' => 'B-77']]]));
    }

    public function testPrepare_KeepsTheSerialNumberOfTheAssetBeingChanged(): void
    {
        $assetId  = $this->enterAsset(['track_units' => '1', 'quantity' => '1', 'units' => [['serial_number' => 'S-77']]]);
        $existing = $this->accountingDb()->table('assets')->where('asset_id', $assetId)->get()->getRow();

        $prepared = $this->prepare(['track_units' => '1', 'units' => [['serial_number' => 'S-77']]], $existing);

        $this->assertSame(['S-77'], array_column($prepared['units'], 'serial_number'));
    }

    public function testPrepare_RequiresTheEmployeeOrTheStorageOfAUnitAssignedToOne(): void
    {
        $employee = $this->employeeWithGrants([]);
        $storage  = $this->insertStockLocation('Main store');

        $this->assertRefused(AccountingError::AssetCustodyEmployee, fn () => $this->prepare(['track_units' => '1', 'units' => [['serial_number' => 'S-1', 'used_by' => 'employee']]]));
        $this->assertRefused(AccountingError::AssetCustodyEmployee, fn () => $this->prepare(['track_units' => '1', 'units' => [['serial_number' => 'S-1', 'used_by' => 'employee', 'employee_id' => 999999]]]));
        $this->assertRefused(AccountingError::AssetCustodyStorage, fn () => $this->prepare(['track_units' => '1', 'units' => [['serial_number' => 'S-1', 'used_by' => 'storage']]]));

        $withEmployee = $this->prepare(['track_units' => '1', 'units' => [['serial_number' => 'S-1', 'used_by' => 'employee', 'employee_id' => $employee, 'stock_location_id' => $storage]]])['units'][0];

        $this->assertSame($employee, $withEmployee['employee_id']);
        $this->assertNull($withEmployee['stock_location_id'], 'a unit with an employee is not kept in storage');

        $inStorage = $this->prepare(['track_units' => '1', 'units' => [['serial_number' => 'S-1', 'used_by' => 'storage', 'stock_location_id' => $storage, 'employee_id' => $employee]]])['units'][0];

        $this->assertSame($storage, $inStorage['stock_location_id']);
        $this->assertNull($inStorage['employee_id']);
    }

    public function testPrepare_RefusesACustodyThatIsNotOne(): void
    {
        $this->assertSame('used_by', $this->assertRefused(AccountingError::AssetFieldInvalid, fn () => $this->prepare(['track_units' => '1', 'units' => [['serial_number' => 'S-1', 'used_by' => 'customer']]]))->context['name']);
    }
}
