<?php

namespace Tests\Libraries\Assets;

use App\Libraries\Accounting\TreasuryService;
use App\Libraries\Assets\AssetChoices;
use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\DatabaseTestTrait;
use Config\Database;
use Tests\Support\AccountingFixtureTrait;
use Tests\Support\AssetFixtureTrait;

/**
 * What the forms and the filters of the register offer: the accounts an asset may name, the employees,
 * the suppliers, the storages and the treasuries of the branches the reader works in.
 *
 * @internal
 */
final class AssetChoicesTest extends CIUnitTestCase
{
    use DatabaseTestTrait;
    use AccountingFixtureTrait;
    use AssetFixtureTrait;

    protected $migrate     = true;
    protected $migrateOnce = true;
    protected $refresh     = false;
    protected $namespace;
    private AssetChoices $choices;

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

        $this->choices = new AssetChoices();
    }

    protected function tearDown(): void
    {
        $this->releaseOpenTransactions();
        $this->clearAssets();
        $this->clearJournals();
        $this->reinstallChart();
        $this->resetBranches();
        $this->removeInsertedEntities();

        parent::tearDown();
    }

    /**
     * @param list<object> $rows
     *
     * @return list<int>
     */
    private function ids(array $rows, string $key): array
    {
        return array_map(static fn (object $row): int => (int) $row->{$key}, $rows);
    }

    public function testAccounts_OffersTheLeavesOfFixedAssetsAndOfExpensesButNotGroupsOrOwnedAccounts(): void
    {
        $accounts = $this->choices->accounts();

        $this->assertContains($this->accountId('fixed_assets'), $this->ids($accounts['cost'], 'account_id'));
        $this->assertContains($this->accountId('accumulated_depreciation'), $this->ids($accounts['accumulated'], 'account_id'));
        $this->assertContains($this->accountId('depreciation_expense'), $this->ids($accounts['expense'], 'account_id'));
        $this->assertNotContains($this->accountIdByCode('111001'), $this->ids($accounts['cost'], 'account_id'), 'a group takes no entry');
        $this->assertNotContains($this->treasuryAccount($this->assetTreasury), $this->ids($accounts['cost'], 'account_id'), 'a treasury owns its account');
        $this->assertNotContains($this->accountId('depreciation_expense'), $this->ids($accounts['cost'], 'account_id'));
        $this->assertNotContains($this->accountId('fixed_assets'), $this->ids($accounts['expense'], 'account_id'));
    }

    public function testAccounts_LeavesOutAnAccountThatIsSwitchedOff(): void
    {
        $this->accountingDb()->table('accounting_accounts')->where('account_id', $this->accountId('fixed_assets'))->update(['active' => 0]);

        $this->assertNotContains($this->accountId('fixed_assets'), $this->ids($this->choices->accounts()['cost'], 'account_id'));
    }

    public function testTreasuries_OffersTheActiveOnesOfTheBranchesTheReaderWorksIn(): void
    {
        $second = $this->createBranch('T2', 'Second branch');
        $other  = (new TreasuryService())->create(['name' => 'Second cash ' . uniqid(), 'kind' => 'cash', 'branch_id' => $second], $this->assetClerk);

        $this->assertContains($this->assetTreasury, $this->ids($this->choices->treasuries(null), 'treasury_id'));
        $this->assertContains($other, $this->ids($this->choices->treasuries(null), 'treasury_id'));
        $this->assertContains($this->assetTreasury, $this->ids($this->choices->treasuries([1]), 'treasury_id'));
        $this->assertNotContains($other, $this->ids($this->choices->treasuries([1]), 'treasury_id'));
        $this->assertSame([], $this->choices->treasuries([]));

        $this->accountingDb()->table('treasuries')->where('treasury_id', $this->assetTreasury)->update(['active' => 0]);

        $this->assertNotContains($this->assetTreasury, $this->ids($this->choices->treasuries(null), 'treasury_id'));
    }

    public function testEmployees_OffersTheEmployeesWhoAreNotDeletedByName(): void
    {
        $employee = $this->employeeWithGrants([]);

        $this->assertContains($employee, $this->ids($this->choices->employees(), 'person_id'));

        $this->accountingDb()->table('employees')->where('person_id', $employee)->update(['deleted' => 1]);

        $this->assertNotContains($employee, $this->ids($this->choices->employees(), 'person_id'));
    }

    public function testSuppliers_OffersTheCompanyNameOrElseThePersonAndLeavesOutDeletedOnes(): void
    {
        $supplier = $this->insertSupplier('Gulf Supplies');
        $deleted  = $this->insertSupplier('Old Supplies');

        $this->accountingDb()->table('suppliers')->where('person_id', $deleted)->update(['deleted' => 1]);

        $rows = $this->choices->suppliers();

        $this->assertContains($supplier, $this->ids($rows, 'person_id'));
        $this->assertNotContains($deleted, $this->ids($rows, 'person_id'));
        $this->assertContains('Gulf Supplies', array_map(static fn (object $row): string => (string) $row->name, $rows));
    }

    public function testStorages_OffersTheStockLocationsThatAreNotDeleted(): void
    {
        $kept    = $this->insertStockLocation('Main store');
        $deleted = $this->insertStockLocation('Closed store');

        $this->accountingDb()->table('stock_locations')->where('location_id', $deleted)->update(['deleted' => 1]);

        $this->assertContains($kept, $this->ids($this->choices->storages(), 'location_id'));
        $this->assertNotContains($deleted, $this->ids($this->choices->storages(), 'location_id'));
    }
}
