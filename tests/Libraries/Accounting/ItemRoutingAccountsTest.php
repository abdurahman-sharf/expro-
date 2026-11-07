<?php

namespace Tests\Libraries\Accounting;

use App\Libraries\Accounting\AccountingError;
use App\Libraries\Accounting\AccountingException;
use App\Libraries\Accounting\AccountingGuard;
use App\Libraries\Accounting\ItemRoutingAccounts;
use App\Libraries\Accounting\RoutingException;
use App\Libraries\Accounting\RoutingKey;
use App\Libraries\Organization\BranchException;
use CodeIgniter\Database\Query;
use CodeIgniter\Events\Events;
use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\DatabaseTestTrait;
use Config\Database;
use Tests\Support\AccountingFixtureTrait;
use Tests\Support\ItemFixtureTrait;
use Throwable;

/**
 * @internal
 */
final class ItemRoutingAccountsTest extends CIUnitTestCase
{
    use DatabaseTestTrait;
    use AccountingFixtureTrait;
    use ItemFixtureTrait;

    protected $migrate     = true;
    protected $migrateOnce = true;
    protected $refresh     = false;
    protected $namespace;
    private ItemRoutingAccounts $accounts;
    private int $manager;
    private int $itemId;

    public static function setUpBeforeClass(): void
    {
        $seeder = Database::seeder('tests');
        $seeder->call('TestDatabaseBootstrapSeeder');
    }

    protected function setUp(): void
    {
        parent::setUp();

        $this->reinstallChart();
        $this->accounts = new ItemRoutingAccounts();
        $this->manager  = $this->employeeWithGrants([AccountingGuard::ROUTING_MANAGE]);
        $this->itemId   = $this->createTestItem();
    }

    protected function tearDown(): void
    {
        $this->accountingDb()->table('items')->where('item_id', $this->itemId)->update(['deleted' => 0]);
        $this->removeInsertedEntities();

        parent::tearDown();
    }

    private function revenue(string $code = '4101', string $name = 'Item revenue'): int
    {
        return $this->customAccount('41', $code, $name);
    }

    /**
     * @return list<object>
     */
    private function auditRows(): array
    {
        return $this->accountingDb()->table('accounting_audit_log')->where('action', 'item_routing_accounts_changed')->orderBy('audit_id', 'ASC')->get()->getResult();
    }

    /**
     * @param array<string, int|string|null> $input
     */
    private function assertRefused(AccountingError $expected, string $key, array $input): void
    {
        try {
            $this->accounts->save($this->itemId, $input, $this->manager);
            $this->fail("The row {$key} should have been refused.");
        } catch (RoutingException $exception) {
            $this->assertSame([$key => $expected], $exception->errors);
        }

        $this->assertSame([], $this->accounts->ofItem($this->itemId));
    }

    public function testSave_NamesAnAccountForAnItemAndAuditsIt(): void
    {
        $account = $this->revenue();

        $this->accounts->save($this->itemId, ['sales.revenue' => $account], $this->manager);

        $named = $this->accounts->ofItem($this->itemId);

        $this->assertSame([RoutingKey::SalesRevenue->value], array_keys($named));
        $this->assertSame($account, (int) $named['sales.revenue']->account_id);

        $audit = $this->auditRows();

        $this->assertCount(1, $audit);
        $this->assertSame('item', $audit[0]->entity_type);
        $this->assertSame($this->itemId, (int) $audit[0]->entity_id);
        $this->assertSame($this->manager, (int) $audit[0]->employee_id);

        $details = json_decode($audit[0]->details, true);

        $this->assertSame('sales.revenue', $details['key']);
        $this->assertNull($details['before']);
        $this->assertSame('4101', $details['after']['code']);
    }

    public function testSave_ChangesTheAccountOfAnItemAndAuditsBothAccounts(): void
    {
        $first  = $this->revenue('4101', 'First');
        $second = $this->revenue('4102', 'Second');
        $this->accounts->save($this->itemId, ['sales.returns' => $first], $this->manager);
        $this->accounts->save($this->itemId, ['sales.returns' => (string) $second], $this->manager);

        $this->assertSame($second, (int) $this->accounts->ofItem($this->itemId)['sales.returns']->account_id);

        $details = json_decode($this->auditRows()[1]->details, true);

        $this->assertSame('4101', $details['before']['code']);
        $this->assertSame('4102', $details['after']['code']);
    }

    public function testSave_TakesTheAccountAwayWhenTheValueIsEmpty(): void
    {
        $this->accounts->save($this->itemId, ['sales.revenue' => $this->revenue(), 'sales.returns' => $this->revenue('4102', 'Returns')], $this->manager);
        $this->accounts->save($this->itemId, ['sales.revenue' => ''], $this->manager);

        $this->assertSame(['sales.returns'], array_keys($this->accounts->ofItem($this->itemId)));

        $details = json_decode($this->auditRows()[2]->details, true);

        $this->assertSame('4101', $details['before']['code']);
        $this->assertNull($details['after']);
    }

    public function testSave_LeavesTheRowsItIsNotGivenAsTheyWere(): void
    {
        $this->accounts->save($this->itemId, ['sales.revenue' => $this->revenue(), 'sales.returns' => $this->revenue('4102', 'Returns')], $this->manager);
        $this->accounts->save($this->itemId, ['sales.returns' => null], $this->manager);

        $this->assertSame(['sales.revenue'], array_keys($this->accounts->ofItem($this->itemId)));
    }

    public function testSave_WritesNothingWhenNothingChanges(): void
    {
        $account = $this->revenue();
        $this->accounts->save($this->itemId, ['sales.revenue' => $account], $this->manager);
        $this->accounts->save($this->itemId, ['sales.revenue' => $account, 'sales.returns' => ''], $this->manager);

        $this->assertCount(1, $this->auditRows());
    }

    public function testSave_RequiresTheRoutingPermission(): void
    {
        $viewer = $this->employeeWithGrants(['accounting_routing']);

        try {
            $this->accounts->save($this->itemId, ['sales.revenue' => $this->revenue()], $viewer);
            $this->fail('An employee without the permission should be refused.');
        } catch (AccountingException $exception) {
            $this->assertSame(AccountingError::PermissionDenied, $exception->error);
        }

        $this->assertSame([], $this->accounts->ofItem($this->itemId));
        $this->assertSame([], $this->auditRows());
    }

    public function testSave_RequiresAPermissionThatIsSensitiveAndNotGrantedWithTheOthers(): void
    {
        $holder = $this->employeeWithGrants([...$this->allAccountingPermissions(), 'accounting_routing']);

        $this->assertContains(AccountingGuard::ROUTING_MANAGE, AccountingGuard::SENSITIVE);
        $this->assertFalse((new AccountingGuard())->has(AccountingGuard::ROUTING_MANAGE, $holder));
        $this->assertTrue((new AccountingGuard())->has(AccountingGuard::ROUTING_MANAGE, $this->manager));
    }

    public function testSave_RequiresEveryBranch(): void
    {
        $limited = $this->employeeWithGrants([AccountingGuard::ROUTING_MANAGE], [1]);

        try {
            $this->accounts->save($this->itemId, ['sales.revenue' => $this->revenue()], $limited);
            $this->fail('An employee of one branch cannot change what concerns the whole company.');
        } catch (BranchException) {
            $this->assertSame([], $this->accounts->ofItem($this->itemId));
        }
    }

    public function testSave_AllowsTheSystemToNameTheAccounts(): void
    {
        $this->accounts->save($this->itemId, ['sales.revenue' => $this->revenue()]);

        $this->assertCount(1, $this->accounts->ofItem($this->itemId));
        $this->assertNull($this->auditRows()[0]->employee_id);
    }

    public function testSave_RejectsAnItemThatDoesNotExist(): void
    {
        try {
            $this->accounts->save(987654321, ['sales.revenue' => $this->revenue()], $this->manager);
            $this->fail('An account cannot be named for a missing item.');
        } catch (AccountingException $exception) {
            $this->assertSame(AccountingError::ItemRoutingItemNotFound, $exception->error);
        }
    }

    public function testSave_RejectsARowThatTakesNoAccountsOfProducts(): void
    {
        $account = $this->revenue();

        foreach (['sales.cogs', 'sales.product', 'inventory.locations', 'sales.customers', 'purchases.purchases', 'no.such.row'] as $key) {
            $this->assertRefused(AccountingError::ItemRoutingKeyInvalid, $key, [$key => $account]);
        }
    }

    public function testSave_RejectsAGroupAccount(): void
    {
        $this->assertRefused(AccountingError::RoutingAccountNotPostable, 'sales.revenue', ['sales.revenue' => $this->accountIdByCode('41')]);
    }

    public function testSave_RejectsAnInactiveAccount(): void
    {
        $account = $this->revenue();
        $this->accountingDb()->table('accounting_accounts')->where('account_id', $account)->update(['active' => 0]);

        $this->assertRefused(AccountingError::RoutingAccountInactive, 'sales.revenue', ['sales.revenue' => $account]);
    }

    public function testSave_RejectsAnAccountOfAnotherType(): void
    {
        $this->assertRefused(AccountingError::RoutingAccountTypeInvalid, 'sales.revenue', ['sales.revenue' => $this->accountId('cash')]);
    }

    public function testSave_RejectsAnAccountTheChartReservesForAnotherRow(): void
    {
        $this->assertRefused(AccountingError::RoutingAccountReserved, 'sales.revenue', ['sales.revenue' => $this->accountId('sales_returns')]);
        $this->assertRefused(AccountingError::RoutingAccountReserved, 'sales.returns', ['sales.returns' => $this->accountId('sales_revenue')]);
    }

    public function testSave_RejectsAnAccountOfACustomerOrALocation(): void
    {
        $account = $this->revenue();
        $this->accountingDb()->table('accounting_accounts')->where('account_id', $account)->update(['entity_type' => 'customer', 'entity_id' => 999999]);

        try {
            $this->assertRefused(AccountingError::RoutingAccountReserved, 'sales.revenue', ['sales.revenue' => $account]);
        } finally {
            $this->accountingDb()->table('accounting_accounts')->where('account_id', $account)->update(['entity_type' => null, 'entity_id' => null]);
        }
    }

    public function testSave_RejectsAnAccountThatDoesNotExistOrIsNotANumber(): void
    {
        $this->assertRefused(AccountingError::AccountNotFound, 'sales.revenue', ['sales.revenue' => 987654321]);
        $this->assertRefused(AccountingError::AccountNotFound, 'sales.revenue', ['sales.revenue' => 'abc']);
        $this->assertRefused(AccountingError::AccountNotFound, 'sales.revenue', ['sales.revenue' => '0']);
    }

    public function testSave_RejectsAnAccountOfAnotherCompany(): void
    {
        $db      = $this->accountingDb();
        $account = $this->revenue();
        $db->table('companies')->insert(['company_id' => 2, 'name' => 'Second company']);

        try {
            $db->table('accounting_accounts')->where('account_id', $account)->update(['company_id' => 2]);

            $this->assertRefused(AccountingError::AccountNotFound, 'sales.revenue', ['sales.revenue' => $account]);
        } finally {
            $db->table('accounting_accounts')->where('account_id', $account)->update(['company_id' => 1]);
            $db->table('companies')->where('company_id', 2)->delete();
        }
    }

    public function testSave_ReportsEveryProblemTogetherAndWritesNothing(): void
    {
        try {
            $this->accounts->save($this->itemId, ['sales.revenue' => $this->revenue(), 'sales.returns' => $this->accountId('cash'), 'sales.cogs' => $this->revenue('4102', 'Other')], $this->manager);
            $this->fail('The rows should have been refused.');
        } catch (RoutingException $exception) {
            $this->assertSame(['sales.returns' => AccountingError::RoutingAccountTypeInvalid, 'sales.cogs' => AccountingError::ItemRoutingKeyInvalid], $exception->errors);
        }

        $this->assertSame([], $this->accounts->ofItem($this->itemId));
        $this->assertSame([], $this->auditRows());
    }

    public function testForItems_ReturnsTheAccountsOfTheGivenItemsForOneRow(): void
    {
        $other   = $this->createTestItem();
        $revenue = $this->revenue();
        $returns = $this->revenue('4102', 'Returns');
        $this->accounts->save($this->itemId, ['sales.revenue' => $revenue, 'sales.returns' => $returns], $this->manager);
        $this->accounts->save($other, ['sales.revenue' => $revenue], $this->manager);

        $byItem = $this->accounts->forItems(RoutingKey::SalesRevenue, [$this->itemId, $other, 987654321]);

        $this->assertEqualsCanonicalizing([$this->itemId, $other], array_keys($byItem));
        $this->assertSame($revenue, (int) $byItem[$this->itemId]->account_id);
        $this->assertSame([], $this->accounts->forItems(RoutingKey::SalesRevenue, []));
        $this->assertSame([$this->itemId], array_keys($this->accounts->forItems(RoutingKey::SalesReturns, [$this->itemId, $other])));
    }

    public function testCandidates_ListsOpenRevenueAccountsThatTakeEntriesAndAreNotReserved(): void
    {
        $open     = $this->revenue('4101', 'Open');
        $inactive = $this->revenue('4102', 'Inactive');
        $group    = $this->customAccount('41', '4103', 'Group', true);
        $this->accountingDb()->table('accounting_accounts')->where('account_id', $inactive)->update(['active' => 0]);

        $ids = array_map(static fn (object $row): int => (int) $row->account_id, $this->accounts->candidates(RoutingKey::SalesRevenue));

        $this->assertContains($open, $ids);
        $this->assertContains($this->accountId('sales_revenue'), $ids);
        $this->assertNotContains($inactive, $ids);
        $this->assertNotContains($group, $ids);
        $this->assertNotContains($this->accountId('sales_returns'), $ids);
        $this->assertNotContains($this->accountId('cogs'), $ids);
        $this->assertContains($this->accountId('sales_returns'), array_map(static fn (object $row): int => (int) $row->account_id, $this->accounts->candidates(RoutingKey::SalesReturns)));
    }

    public function testCandidates_LeavesOutTheAccountsOfAnotherCompany(): void
    {
        $db      = $this->accountingDb();
        $account = $this->revenue('4101', 'Other company revenue');
        $db->table('companies')->insert(['company_id' => 2, 'name' => 'Second company']);

        try {
            $db->table('accounting_accounts')->where('account_id', $account)->update(['company_id' => 2]);

            $ids = array_map(static fn (object $row): int => (int) $row->account_id, $this->accounts->candidates(RoutingKey::SalesRevenue));

            $this->assertNotContains($account, $ids);
            $this->assertContains($this->accountId('sales_revenue'), $ids);
        } finally {
            $db->table('accounting_accounts')->where('account_id', $account)->update(['company_id' => 1]);
            $db->table('companies')->where('company_id', 2)->delete();
        }
    }

    public function testCandidates_ListsNothingForARowThatTakesNoAccountsOfProducts(): void
    {
        $this->assertSame([], $this->accounts->candidates(RoutingKey::CostOfGoodsSold));
        $this->assertSame([], $this->accounts->candidates(RoutingKey::StockLocations));
    }

    public function testProblems_ListsTheItemsNamingAnAccountThatCannotTakeEntriesAnyMore(): void
    {
        $inactive = $this->revenue('4101', 'Gone');
        $fine     = $this->revenue('4102', 'Fine');
        $group    = $this->revenue('4103', 'Turned group');
        $other    = $this->createTestItem();
        $this->accounts->save($this->itemId, ['sales.revenue' => $inactive, 'sales.returns' => $fine], $this->manager);
        $this->accounts->save($other, ['sales.revenue' => $group], $this->manager);
        $this->accountingDb()->table('accounting_accounts')->where('account_id', $inactive)->update(['active' => 0]);
        $this->accountingDb()->table('accounting_accounts')->where('account_id', $group)->update(['is_group' => 1]);

        $problems = $this->accounts->problems();

        $this->assertSame([$this->itemId, $other], array_column($problems, 'item_id'));
        $this->assertSame(['routing_account_inactive', 'routing_account_not_postable'], array_column($problems, 'problem'));
        $this->assertSame(['4101', '4103'], array_column($problems, 'code'));
        $this->assertSame(['sales.revenue', 'sales.revenue'], array_column($problems, 'routing_key'));
    }

    public function testProblems_ListsADeletedItemBecauseTheDocumentsThatHoldItArePostedWithItsAccount(): void
    {
        $account = $this->revenue();
        $this->accounts->save($this->itemId, ['sales.revenue' => $account], $this->manager);
        $this->accountingDb()->table('accounting_accounts')->where('account_id', $account)->update(['active' => 0]);
        $this->accountingDb()->table('items')->where('item_id', $this->itemId)->update(['deleted' => 1]);

        $problems = $this->accounts->problems();

        $this->assertSame([$this->itemId], array_column($problems, 'item_id'));
        $this->assertSame([true], array_column($problems, 'deleted'));
    }

    public function testProblems_MarksAnItemThatIsNotDeletedAsSuch(): void
    {
        $account = $this->revenue();
        $this->accounts->save($this->itemId, ['sales.revenue' => $account], $this->manager);
        $this->accountingDb()->table('accounting_accounts')->where('account_id', $account)->update(['active' => 0]);

        $this->assertSame([false], array_column($this->accounts->problems(), 'deleted'));
    }

    public function testProblems_ReportsARowThatNoLongerTakesAccountsOfProducts(): void
    {
        $this->accountingDb()->table('item_routing_accounts')->insert(['item_id' => $this->itemId, 'routing_key' => 'sales.cogs', 'account_id' => $this->accountId('cogs')]);

        $problems = $this->accounts->problems();

        $this->assertSame(['item_routing_key_invalid'], array_column($problems, 'problem'));
    }

    public function testCounts_CountsTheItemsNamingAnAccountByRowAndLeavesOutDeletedItems(): void
    {
        $other   = $this->createTestItem();
        $gone    = $this->createTestItem();
        $revenue = $this->revenue();
        $this->accounts->save($this->itemId, ['sales.revenue' => $revenue, 'sales.returns' => $this->revenue('4102', 'Returns')], $this->manager);
        $this->accounts->save($other, ['sales.revenue' => $revenue], $this->manager);
        $this->accounts->save($gone, ['sales.revenue' => $revenue], $this->manager);
        $this->accountingDb()->table('items')->where('item_id', $gone)->update(['deleted' => 1]);

        try {
            $counts = $this->accounts->counts();

            ksort($counts);

            $this->assertSame(['sales.returns' => 1, 'sales.revenue' => 2], $counts);
        } finally {
            $this->accountingDb()->table('items')->where('item_id', $gone)->update(['deleted' => 0]);
        }
    }

    public function testSave_AcceptsAnAccountThatCannotTakeEntriesAnyMoreWhileItIsLeftAsItWasAndSavesTheOtherRows(): void
    {
        $gone    = $this->revenue('4101', 'Gone');
        $returns = $this->revenue('4102', 'Returns');
        $this->accounts->save($this->itemId, ['sales.revenue' => $gone], $this->manager);
        $this->accountingDb()->table('accounting_accounts')->where('account_id', $gone)->update(['active' => 0]);

        $this->accounts->save($this->itemId, ['sales.revenue' => (string) $gone, 'sales.returns' => $returns], $this->manager);

        $named = $this->accounts->ofItem($this->itemId);

        $this->assertSame($gone, (int) $named['sales.revenue']->account_id);
        $this->assertSame($returns, (int) $named['sales.returns']->account_id);
        $this->assertCount(2, $this->auditRows());
    }

    public function testSave_RejectsAChangeToAnotherAccountThatCannotTakeEntriesAndKeepsTheOneTheItemHad(): void
    {
        $gone  = $this->revenue('4101', 'Gone');
        $other = $this->revenue('4102', 'Also gone');
        $this->accounts->save($this->itemId, ['sales.revenue' => $gone], $this->manager);
        $this->accountingDb()->table('accounting_accounts')->whereIn('account_id', [$gone, $other])->update(['active' => 0]);

        try {
            $this->accounts->save($this->itemId, ['sales.revenue' => $other], $this->manager);
            $this->fail('An account that cannot take entries cannot be newly named.');
        } catch (RoutingException $exception) {
            $this->assertSame(['sales.revenue' => AccountingError::RoutingAccountInactive], $exception->errors);
        }

        $this->assertSame($gone, (int) $this->accounts->ofItem($this->itemId)['sales.revenue']->account_id);
    }

    public function testSave_AcceptsAnAccountThatAnotherRequestNamedForTheSameRowBetweenTheReadAndTheWrite(): void
    {
        $mine   = $this->revenue('4101', 'Mine');
        $theirs = $this->revenue('4102', 'Theirs');
        $db     = $this->accountingDb();
        $raced  = false;

        $listener = function (Query $query) use (&$raced, $db, $theirs): void {
            if ($raced || ! str_contains($query->getQuery(), 'item_routing_accounts')) {
                return;
            }

            $raced = true;
            $db->table('item_routing_accounts')->insert(['item_id' => $this->itemId, 'routing_key' => 'sales.revenue', 'account_id' => $theirs]);
        };
        Events::on('DBQuery', $listener);

        try {
            $this->accounts->save($this->itemId, ['sales.revenue' => $mine], $this->manager);
        } finally {
            Events::removeListener('DBQuery', $listener);
        }

        $this->assertTrue($raced);
        $this->assertSame(1, $this->countRows('item_routing_accounts'));
        $this->assertSame($mine, (int) $this->accounts->ofItem($this->itemId)['sales.revenue']->account_id);
    }

    public function testSave_RejectsTheDeletionOfAnAccountAnItemNamesAtTheDatabase(): void
    {
        $account = $this->revenue();
        $this->accounts->save($this->itemId, ['sales.revenue' => $account], $this->manager);

        $this->assertSame(1, $this->countRows('item_routing_accounts'));

        $this->accountingDb()->query('SET FOREIGN_KEY_CHECKS = 1');

        try {
            $this->accountingDb()->table('accounting_accounts')->where('account_id', $account)->delete();
            $this->fail('An account an item names cannot be deleted.');
        } catch (Throwable) {
            $this->assertSame(1, $this->countRows('item_routing_accounts'));
        }
    }
}
