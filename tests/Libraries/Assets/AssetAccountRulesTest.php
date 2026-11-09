<?php

namespace Tests\Libraries\Assets;

use App\Libraries\Accounting\AccountingError;
use App\Libraries\Accounting\AccountingException;
use App\Libraries\Assets\AssetAccountRules;
use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\DatabaseTestTrait;
use Config\Database;
use Tests\Support\AccountingFixtureTrait;
use Tests\Support\AssetFixtureTrait;

/**
 * Which accounts an asset or a category may name: a leaf of the company, switched on, that no customer,
 * supplier or treasury owns, of the type its part of the books has.
 *
 * @internal
 */
final class AssetAccountRulesTest extends CIUnitTestCase
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

    private function assertRefused(string $role, int $accountId): AccountingException
    {
        try {
            AssetAccountRules::require(Database::connect(), $accountId, $role);
        } catch (AccountingException $exception) {
            $this->assertSame(AccountingError::AssetAccountInvalid, $exception->error, $exception->getMessage());

            return $exception;
        }

        $this->fail("Account {$accountId} was accepted as the {$role} account.");
    }

    public function testRequire_AcceptsALeafOfTheRightTypeForEachRole(): void
    {
        $db = Database::connect();

        $this->assertSame($this->accountId('fixed_assets'), (int) AssetAccountRules::require($db, $this->accountId('fixed_assets'), AssetAccountRules::COST)->account_id);
        $this->assertSame($this->accountId('accumulated_depreciation'), (int) AssetAccountRules::require($db, $this->accountId('accumulated_depreciation'), AssetAccountRules::ACCUMULATED)->account_id);
        $this->assertSame($this->accountId('depreciation_expense'), (int) AssetAccountRules::require($db, $this->accountId('depreciation_expense'), AssetAccountRules::EXPENSE)->account_id);
    }

    public function testRequire_RejectsAGroupAndNamesTheRoleAndTheCode(): void
    {
        $exception = $this->assertRefused(AssetAccountRules::COST, $this->accountIdByCode('111001'));

        $this->assertSame('cost', $exception->context['name']);
        $this->assertSame('111001', $exception->context['code']);
    }

    public function testRequire_RejectsAnAccountThatIsSwitchedOff(): void
    {
        $this->accountingDb()->table('accounting_accounts')->where('account_id', $this->accountId('fixed_assets'))->update(['active' => 0]);

        $this->assertRefused(AssetAccountRules::COST, $this->accountId('fixed_assets'));
    }

    public function testRequire_RejectsAnAccountOfTheWrongType(): void
    {
        $this->assertRefused(AssetAccountRules::EXPENSE, $this->accountId('fixed_assets'));
        $this->assertRefused(AssetAccountRules::COST, $this->accountId('depreciation_expense'));
        $this->assertRefused(AssetAccountRules::ACCUMULATED, $this->accountId('cogs'));
    }

    public function testRequire_RejectsTheAccountOfATreasuryAndAnAccountThatDoesNotExist(): void
    {
        $this->assertRefused(AssetAccountRules::COST, $this->treasuryAccount($this->assetTreasury));
        $this->assertRefused(AssetAccountRules::COST, 999999);
    }
}
