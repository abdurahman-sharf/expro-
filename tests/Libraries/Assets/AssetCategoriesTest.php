<?php

namespace Tests\Libraries\Assets;

use App\Libraries\Accounting\AccountingError;
use App\Libraries\Accounting\AccountingException;
use App\Libraries\Accounting\AccountingGuard;
use App\Libraries\Organization\BranchError;
use App\Libraries\Organization\BranchException;
use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\DatabaseTestTrait;
use Config\Database;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Support\AccountingFixtureTrait;
use Tests\Support\AssetFixtureTrait;

/**
 * The categories of assets: the accounts, method and life an asset starts from, that a name is kept to
 * one category, that a category assets use is switched off and not deleted, and who may change them.
 *
 * @internal
 */
final class AssetCategoriesTest extends CIUnitTestCase
{
    use DatabaseTestTrait;
    use AccountingFixtureTrait;
    use AssetFixtureTrait;

    protected $migrate     = true;
    protected $migrateOnce = true;
    protected $refresh     = false;
    protected $namespace;

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

    public function testSave_KeepsTheNameTheAccountsTheMethodAndTheLife(): void
    {
        $cost = $this->customAccount('111', '111010', 'Vehicles cost');

        $id = $this->categories->save(['name' => 'Vehicles', 'cost_account_id' => $cost, 'method' => 'declining', 'life_months' => '60'], $this->assetClerk);

        $category = $this->categories->find($id);

        $this->assertSame('Vehicles', $category->name);
        $this->assertSame($cost, (int) $category->cost_account_id);
        $this->assertNull($category->accumulated_account_id);
        $this->assertSame('declining', $category->method);
        $this->assertSame(60, (int) $category->life_months);
        $this->assertSame(1, (int) $category->active);
        $this->assertNotNull($this->accountingDb()->table('accounting_audit_log')->where(['entity_type' => 'asset_category', 'entity_id' => $id, 'action' => 'asset_category_created'])->get()->getRow());
    }

    public function testSave_ChangesACategoryAndKeepsItsName(): void
    {
        $id = $this->categories->save(['name' => 'Furniture'], $this->assetClerk);

        $this->categories->save(['name' => 'Furniture', 'method' => 'fixed', 'life_months' => '36'], $this->assetClerk, $id);

        $this->assertSame('fixed', $this->categories->find($id)->method);
        $this->assertSame(1, $this->countRows('asset_categories'));
    }

    public function testSave_RequiresThePermissionToManageTheRegister(): void
    {
        $this->assertRefused(AccountingError::PermissionDenied, fn () => $this->categories->save(['name' => 'Furniture'], $this->employeeWithGrants([])));
        $this->assertSame(0, $this->countRows('asset_categories'));
    }

    /**
     * @return array<string, array{array<string, mixed>}>
     */
    public static function invalidInputProvider(): array
    {
        return [
            'no name'                  => [['name' => '  ']],
            'a name of 101 characters' => [['name' => str_repeat('x', 101)]],
        ];
    }

    /**
     * @param array<string, mixed> $input
     */
    #[DataProvider('invalidInputProvider')]
    public function testSave_RejectsANameThatIsNotAName(array $input): void
    {
        $this->assertRefused(AccountingError::AssetCategoryInvalid, fn () => $this->categories->save($input, $this->assetClerk));
    }

    public function testSave_RejectsAMethodAndALifeThatAreNotValid(): void
    {
        $this->assertSame('method', $this->assertRefused(AccountingError::AssetFieldInvalid, fn () => $this->categories->save(['name' => 'A', 'method' => 'sum_of_years'], $this->assetClerk))->context['name']);

        foreach (['0', '1201', 'x', '-5'] as $life) {
            $this->assertSame('life_months', $this->assertRefused(AccountingError::AssetFieldInvalid, fn () => $this->categories->save(['name' => 'A', 'life_months' => $life], $this->assetClerk))->context['name'], $life);
        }

        $this->assertSame(0, $this->countRows('asset_categories'));
    }

    public function testSave_RefusesANameAnotherCategoryHasButNotItsOwn(): void
    {
        $first = $this->categories->save(['name' => 'Furniture'], $this->assetClerk);

        $this->assertRefused(AccountingError::AssetCategoryInvalid, fn () => $this->categories->save(['name' => 'Furniture'], $this->assetClerk));

        $this->categories->save(['name' => 'Furniture', 'life_months' => '12'], $this->assetClerk, $first);

        $this->assertSame(1, $this->countRows('asset_categories'));
    }

    public function testSave_RefusesAnAccountOfTheWrongKind(): void
    {
        $group = $this->accountIdByCode('111001');

        $this->assertRefused(AccountingError::AssetAccountInvalid, fn () => $this->categories->save(['name' => 'A', 'cost_account_id' => $group], $this->assetClerk));
        $this->assertRefused(AccountingError::AssetAccountInvalid, fn () => $this->categories->save(['name' => 'A', 'expense_account_id' => $this->accountId('fixed_assets')], $this->assetClerk));
        $this->assertRefused(AccountingError::AssetAccountInvalid, fn () => $this->categories->save(['name' => 'A', 'accumulated_account_id' => $this->accountId('cogs')], $this->assetClerk));
    }

    public function testSave_RefusesAnAccountThatIsSwitchedOff(): void
    {
        $this->accountingDb()->table('accounting_accounts')->where('account_id', $this->accountId('fixed_assets'))->update(['active' => 0]);

        $this->assertRefused(AccountingError::AssetAccountInvalid, fn () => $this->categories->save(['name' => 'A', 'cost_account_id' => $this->accountId('fixed_assets')], $this->assetClerk));
        $this->assertSame([], $this->categories->all());
    }

    public function testSetActive_SwitchesACategoryOffAndOnAndAnAssetCannotTakeAnOffCategory(): void
    {
        $id = $this->categories->save(['name' => 'Furniture'], $this->assetClerk);

        $this->categories->setActive($id, false, $this->assetClerk);

        $this->assertSame(0, (int) $this->categories->find($id)->active);
        $this->assertSame([], $this->categories->all(true));
        $this->assertCount(1, $this->categories->all());
        $this->assertRefused(AccountingError::AssetCategoryNotFound, fn () => $this->enterAsset(['category_id' => $id]));

        $this->categories->setActive($id, true, $this->assetClerk);

        $this->assertGreaterThan(0, $this->enterAsset(['category_id' => $id]));
    }

    public function testSetActive_RequiresThePermissionAndACategoryThatExists(): void
    {
        $id = $this->categories->save(['name' => 'Furniture'], $this->assetClerk);

        $this->assertRefused(AccountingError::PermissionDenied, fn () => $this->categories->setActive($id, false, $this->employeeWithGrants([])));
        $this->assertRefused(AccountingError::AssetCategoryNotFound, fn () => $this->categories->setActive(999999, false, $this->assetClerk));
        $this->assertSame(1, (int) $this->categories->find($id)->active);
    }

    public function testDelete_RemovesACategoryNoAssetUses(): void
    {
        $id = $this->categories->save(['name' => 'Furniture'], $this->assetClerk);

        $this->categories->delete($id, $this->assetClerk);

        $this->assertNull($this->categories->find($id));
        $this->assertNotNull($this->accountingDb()->table('accounting_audit_log')->where(['entity_type' => 'asset_category', 'entity_id' => $id, 'action' => 'asset_category_deleted'])->get()->getRow());
    }

    public function testDelete_RefusesACategoryAnAssetUsesEvenWhenTheAssetWasDeleted(): void
    {
        $id      = $this->categories->save(['name' => 'Furniture'], $this->assetClerk);
        $assetId = $this->enterAsset(['category_id' => $id]);

        $this->assertSame('Furniture', $this->assertRefused(AccountingError::AssetCategoryInUse, fn () => $this->categories->delete($id, $this->assetClerk))->context['name']);

        $this->register->delete($assetId, $this->assetClerk);

        $this->assertRefused(AccountingError::AssetCategoryInUse, fn () => $this->categories->delete($id, $this->assetClerk));
        $this->assertNotNull($this->categories->find($id));
    }

    public function testDelete_RequiresThePermission(): void
    {
        $id = $this->categories->save(['name' => 'Furniture'], $this->assetClerk);

        $this->assertRefused(AccountingError::PermissionDenied, fn () => $this->categories->delete($id, $this->employeeWithGrants([])));
        $this->assertNotNull($this->categories->find($id));
    }

    public function testSave_RefusesWhoDoesNotWorkInEveryBranchBecauseACategoryServesThemAll(): void
    {
        $id      = $this->categories->save(['name' => 'Furniture'], $this->assetClerk);
        $limited = $this->employeeWithGrants([AccountingGuard::ASSETS_MANAGE], [1]);

        $actions = [
            'new'        => fn () => $this->categories->save(['name' => 'Vehicles'], $limited),
            'change'     => fn () => $this->categories->save(['name' => 'Furniture', 'life_months' => '12'], $limited, $id),
            'switch off' => fn () => $this->categories->setActive($id, false, $limited),
            'delete'     => fn () => $this->categories->delete($id, $limited),
        ];

        foreach ($actions as $name => $action) {
            try {
                $action();
                $this->fail('A branch of their own is not every branch: ' . $name);
            } catch (BranchException $exception) {
                $this->assertSame(BranchError::CompanyScopeNeeded, $exception->error, $name);
            }
        }

        $category = $this->categories->find($id);

        $this->assertSame(1, $this->countRows('asset_categories'));
        $this->assertSame(1, (int) $category->active);
        $this->assertNull($category->life_months);
    }

    public function testAll_CountsADeletedAssetAsNamingItsCategoryButNotAsAnAssetOfTheRegister(): void
    {
        $id      = $this->categories->save(['name' => 'Furniture'], $this->assetClerk);
        $assetId = $this->enterAsset(['category_id' => $id]);

        $this->register->delete($assetId, $this->assetClerk);

        $row = $this->categories->all()[0];

        $this->assertSame(0, (int) $row->asset_count);
        $this->assertSame(1, (int) $row->referenced_count);
    }

    public function testAll_CountsTheAssetsOfEachCategoryAndNamesItsAccounts(): void
    {
        $cost = $this->customAccount('111', '111010', 'Vehicles cost');
        $id   = $this->categories->save(['name' => 'Vehicles', 'cost_account_id' => $cost], $this->assetClerk);

        $this->enterAsset(['category_id' => $id]);
        $this->enterAsset(['category_id' => $id, 'name' => 'Truck']);

        $row = $this->categories->all()[0];

        $this->assertSame(2, (int) $row->asset_count);
        $this->assertSame('111010', $row->cost_code);
        $this->assertSame('Vehicles cost', $row->cost_name);
    }
}
