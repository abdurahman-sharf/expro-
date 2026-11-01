<?php

namespace Tests\Libraries\Accounting\Readiness;

use App\Libraries\Accounting\EntityAccountService;
use App\Libraries\Accounting\Readiness\CheckResult;
use App\Libraries\Accounting\Readiness\CheckStatus;
use App\Libraries\Accounting\Readiness\RoutingChecks;
use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\DatabaseTestTrait;
use Config\Database;
use Tests\Support\AccountingFixtureTrait;
use Tests\Support\ItemFixtureTrait;

/**
 * What the routing types that look at the item or at the entity of a document need, as the readiness
 * report lists it.
 */
class RoutingChecksTest extends CIUnitTestCase
{
    use DatabaseTestTrait;
    use AccountingFixtureTrait;
    use ItemFixtureTrait;

    protected $migrate     = true;
    protected $migrateOnce = true;
    protected $refresh     = false;
    protected $namespace   = null;

    private RoutingChecks $checks;

    public static function setUpBeforeClass(): void
    {
        $seeder = Database::seeder('tests');
        $seeder->call('TestDatabaseBootstrapSeeder');
    }

    protected function setUp(): void
    {
        parent::setUp();

        $this->reinstallChart();
        $this->checks = new RoutingChecks($this->accountingDb());
    }

    protected function tearDown(): void
    {
        $db = $this->accountingDb();

        if (! $db->tableExists('ospos_item_routing_accounts', false) && $db->tableExists('ospos_item_routing_accounts_hold', false)) {
            $db->query('RENAME TABLE ospos_item_routing_accounts_hold TO ospos_item_routing_accounts');
        }

        $this->removeInsertedEntities();

        parent::tearDown();
    }

    private function check(string $id): CheckResult
    {
        foreach ($this->checks->run() as $result) {
            if ($result->id === $id) {
                return $result;
            }
        }

        $this->fail("No check {$id}.");
    }

    private function route(string $key, string $type): void
    {
        $this->accountingDb()->table('accounting_routing')->where('routing_key', $key)->update(['routing_type' => $type]);
    }

    private function nameAccount(int $itemId, string $key, int $accountId): void
    {
        $this->accountingDb()->table('item_routing_accounts')->replace(['item_id' => $itemId, 'routing_key' => $key, 'account_id' => $accountId]);
    }

    public function testRun_GivesTheThreeChecksWithTheirReferences(): void
    {
        $results = $this->checks->run();

        $this->assertSame(['routing_types', 'item_routing', 'entity_routing'], array_map(static fn (CheckResult $result): string => $result->id, $results));
        $this->assertSame(['WP24-ROUTING-TYPES', 'WP24-ITEM-ROUTING', 'WP24-ENTITY-ROUTING'], array_map(static fn (CheckResult $result): string => $result->ref, $results));
    }

    public function testRun_PassesEveryCheckOnAFreshChart(): void
    {
        foreach ($this->checks->run() as $result) {
            $this->assertSame(CheckStatus::Pass, $result->status, $result->id . ': ' . $result->summary);
        }
    }

    public function testRoutingTypes_FailsAndNamesTheRowsSavedWithATypePostingDoesNotApplyOnThem(): void
    {
        $this->route('inventory.locations', 'per_item');
        $this->route('sales.customers', 'per_item');

        $result = $this->check('routing_types');

        $this->assertSame(CheckStatus::Fail, $result->status);
        $this->assertEqualsCanonicalizing(['inventory.locations is set to per_item', 'sales.customers is set to per_item'], $result->findings);
        $this->assertSame(2, $result->data['rows']);
    }

    public function testRoutingTypes_PassesForTheTypesPostingApplies(): void
    {
        $this->route('sales.revenue', 'per_item');
        $this->route('sales.returns', 'per_item');
        $this->route('sales.customers', 'main_per_entity');

        $this->assertSame(CheckStatus::Pass, $this->check('routing_types')->status);
    }

    public function testItemRouting_PassesAndSaysHowManyRowsArePerItem(): void
    {
        $this->route('sales.revenue', 'per_item');
        $this->route('sales.returns', 'per_item');
        $itemId = $this->createTestItem();
        $this->nameAccount($itemId, 'sales.revenue', $this->customAccount('41', '4101', 'Item revenue'));

        $result = $this->check('item_routing');

        $this->assertSame(CheckStatus::Pass, $result->status);
        $this->assertSame(['sales.revenue', 'sales.returns'], $result->data['per_item_rows']);
        $this->assertSame(['sales.revenue' => 1], $result->data['items_by_row']);
        $this->assertStringContainsString('2 row(s) are set to per item', $result->summary);
    }

    public function testItemRouting_FailsForAPerItemRowWithoutAnAccountToFallBackTo(): void
    {
        $this->route('sales.revenue', 'per_item');
        $this->accountingDb()->table('accounting_routing')->where('routing_key', 'sales.revenue')->update(['account_id' => null]);

        $result = $this->check('item_routing');

        $this->assertSame(CheckStatus::Fail, $result->status);
        $this->assertSame(['sales.revenue is set to per item and has no account to fall back to: routing_account_required'], $result->findings);
    }

    public function testItemRouting_FailsForAPerItemRowWhoseFallbackAccountWasDeactivated(): void
    {
        $this->route('sales.returns', 'per_item');
        $this->accountingDb()->table('accounting_accounts')->where('system_key', 'sales_returns')->update(['active' => 0]);

        $result = $this->check('item_routing');

        $this->assertSame(CheckStatus::Fail, $result->status);
        $this->assertStringContainsString('routing_account_inactive', $result->findings[0]);
    }

    public function testItemRouting_FailsForAnItemThatNamesAnAccountThatCannotTakeEntriesOnAPerItemRow(): void
    {
        $this->route('sales.revenue', 'per_item');
        $itemId  = $this->createTestItem();
        $account = $this->customAccount('41', '4101', 'Item revenue');
        $this->nameAccount($itemId, 'sales.revenue', $account);
        $this->accountingDb()->table('accounting_accounts')->where('account_id', $account)->update(['is_group' => 1]);

        $result = $this->check('item_routing');

        $this->assertSame(CheckStatus::Fail, $result->status);
        $this->assertStringContainsString("item {$itemId} (Test Item) names the account 4101 for sales.revenue: routing_account_not_postable", $result->findings[0]);
        $this->assertSame(1, $result->data['problems']);
    }

    public function testItemRouting_OnlyReviewsAnItemWhoseRowIsNotPerItem(): void
    {
        $itemId  = $this->createTestItem();
        $account = $this->customAccount('41', '4101', 'Item revenue');
        $this->nameAccount($itemId, 'sales.revenue', $account);
        $this->accountingDb()->table('accounting_accounts')->where('account_id', $account)->update(['active' => 0]);

        $result = $this->check('item_routing');

        $this->assertSame(CheckStatus::Review, $result->status);
        $this->assertStringContainsString('the row is not set to per item', $result->findings[0]);
    }

    public function testItemRouting_IgnoresADeletedItem(): void
    {
        $this->route('sales.revenue', 'per_item');
        $itemId  = $this->createTestItem();
        $account = $this->customAccount('41', '4101', 'Item revenue');
        $this->nameAccount($itemId, 'sales.revenue', $account);
        $this->accountingDb()->table('accounting_accounts')->where('account_id', $account)->update(['active' => 0]);
        $this->accountingDb()->table('items')->where('item_id', $itemId)->update(['deleted' => 1]);

        $this->assertSame(CheckStatus::Pass, $this->check('item_routing')->status);
    }

    public function testItemRouting_IsSkippedWhileTheMigrationHasNotRun(): void
    {
        $db = $this->accountingDb();
        $db->query('SET FOREIGN_KEY_CHECKS = 0');
        $db->query('RENAME TABLE ospos_item_routing_accounts TO ospos_item_routing_accounts_hold');
        $db->query('SET FOREIGN_KEY_CHECKS = 1');

        $result = $this->check('item_routing');

        $this->assertSame(CheckStatus::Skipped, $result->status);
        $this->assertSame(['item_routing_accounts'], $result->data['missing']);
    }

    public function testEntityRouting_FailsWhileARowNamingTheAccountPerEntityHasACustomerWithoutOne(): void
    {
        $this->route('sales.customers', 'main_per_entity');
        $this->insertCustomer();

        $result = $this->check('entity_routing');

        $this->assertSame(CheckStatus::Fail, $result->status);
        $this->assertSame(['sales.customers names the account per entity and 1 customer(s) have none: a document for one of them stops'], $result->findings);
        $this->assertSame(1, $result->data['missing']['customer']);
    }

    public function testEntityRouting_PassesOnceTheAccountsAreOpened(): void
    {
        $this->route('sales.customers', 'main_per_entity');
        $this->insertCustomer();
        (new EntityAccountService())->fill();

        $this->assertSame(CheckStatus::Pass, $this->check('entity_routing')->status);
    }

    public function testEntityRouting_LeavesTheMissingAccountsOfAnAutomaticRowToTheOtherChecks(): void
    {
        $this->insertCustomer();

        $this->assertSame(CheckStatus::Pass, $this->check('entity_routing')->status);
    }
}
