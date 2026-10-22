<?php

namespace Tests\Libraries\Accounting;

use App\Libraries\Accounting\AccountingError;
use App\Libraries\Accounting\AccountingException;
use App\Libraries\Accounting\EntityAccountService;
use CodeIgniter\Database\BaseConnection;
use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\DatabaseTestTrait;
use Config\Database;
use Tests\Support\AccountingFixtureTrait;

class EntityAccountServiceTest extends CIUnitTestCase
{
    use DatabaseTestTrait;
    use AccountingFixtureTrait;

    protected $migrate     = true;
    protected $migrateOnce = true;
    protected $refresh     = false;
    protected $namespace   = null;
    private EntityAccountService $service;
    private ?BaseConnection $other = null;

    public static function setUpBeforeClass(): void
    {
        $seeder = Database::seeder('tests');
        $seeder->call('TestDatabaseBootstrapSeeder');
    }

    protected function setUp(): void
    {
        parent::setUp();

        $this->reinstallChart();
        $this->setConfigValue('accounting_enabled', '1');
        $this->setConfigValue('accounting_base_currency', 'SAR');
        $this->service = new EntityAccountService();
    }

    protected function tearDown(): void
    {
        $this->releaseOpenTransactions();

        if ($this->other !== null) {
            $this->other->transRollback();
            $this->other->close();
            $this->other = null;
        }

        $this->removeInsertedEntities();
        $this->setConfigValue('accounting_enabled', '0');

        parent::tearDown();
    }

    private function accountRow(int $accountId): object
    {
        return $this->accountingDb()->table('accounting_accounts')->where('account_id', $accountId)->get()->getRow();
    }

    private function assertFailsWith(AccountingError $expected, callable $action): void
    {
        try {
            $action();
            $this->fail("Expected the failure {$expected->value}.");
        } catch (AccountingException $exception) {
            $this->assertSame($expected, $exception->error);
        }
    }

    public function testEnsure_OpensASubAccountUnderTheCustomersMainAccount(): void
    {
        $customerId = $this->insertCustomer();

        $accountId = $this->service->ensure(EntityAccountService::CUSTOMER, $customerId, 'Omar Khaled');
        $account   = $this->accountRow($accountId);

        $this->assertSame('12041001', $account->code);
        $this->assertSame('Omar Khaled', $account->name);
        $this->assertSame((string) $this->accountId('receivables'), (string) $account->parent_id);
        $this->assertSame('asset', $account->account_type);
        $this->assertSame('debit', $account->normal_balance);
        $this->assertSame('0', (string) $account->is_group);
        $this->assertSame('1', (string) $account->is_system);
        $this->assertSame('1', (string) $account->active);
        $this->assertSame('customer', $account->entity_type);
        $this->assertSame((string) $customerId, (string) $account->entity_id);
    }

    public function testEnsure_OpensSuppliersUnderTheSuppliersMainAccountAsLiabilities(): void
    {
        $accountId = $this->service->ensure(EntityAccountService::SUPPLIER, $this->insertSupplier(), 'Gulf Supplies');
        $account   = $this->accountRow($accountId);

        $this->assertSame('2211001', $account->code);
        $this->assertSame('liability', $account->account_type);
        $this->assertSame('credit', $account->normal_balance);
    }

    public function testEnsure_OpensStockLocationsUnderTheStockMainAccount(): void
    {
        $accountId = $this->service->ensure(EntityAccountService::STOCK_LOCATION, $this->insertStockLocation('Back store'), 'Back store');

        $this->assertSame('1203001', $this->accountRow($accountId)->code);
    }

    public function testEnsure_SkipsACodeThatIsAlreadyTaken(): void
    {
        $accountId = $this->service->ensure(EntityAccountService::TREASURY, 7, 'Branch till');

        $this->assertSame('120102', $this->accountRow($accountId)->code);
    }

    public function testEnsure_NumbersTheNextSubAccountOfTheSameParent(): void
    {
        $first  = $this->service->ensure(EntityAccountService::CUSTOMER, $this->insertCustomer(), 'Omar Khaled');
        $second = $this->service->ensure(EntityAccountService::CUSTOMER, $this->insertCustomer('Hind', 'Saad'), 'Hind Saad');

        $this->assertSame('12041001', $this->accountRow($first)->code);
        $this->assertSame('12041002', $this->accountRow($second)->code);
    }

    public function testEnsure_ReturnsTheExistingAccountOfTheSameEntity(): void
    {
        $customerId = $this->insertCustomer();

        $first  = $this->service->ensure(EntityAccountService::CUSTOMER, $customerId, 'Omar Khaled');
        $second = $this->service->ensure(EntityAccountService::CUSTOMER, $customerId, 'Another name');

        $this->assertSame($first, $second);
        $this->assertSame(1, $this->accountingDb()->table('accounting_accounts')->where('entity_type', 'customer')->countAllResults());
    }

    public function testEnsure_AddsTheCodeToANameAnotherSubAccountAlreadyHas(): void
    {
        $this->service->ensure(EntityAccountService::CUSTOMER, $this->insertCustomer(), 'Omar Khaled');
        $second = $this->service->ensure(EntityAccountService::CUSTOMER, $this->insertCustomer(), 'Omar Khaled');

        $this->assertSame('Omar Khaled (12041002)', $this->accountRow($second)->name);
    }

    public function testEnsure_NamesAnAccountAfterItsCodeWhenTheEntityHasNoName(): void
    {
        $accountId = $this->service->ensure(EntityAccountService::CUSTOMER, $this->insertCustomer(), '   ');

        $this->assertSame('12041001', $this->accountRow($accountId)->name);
    }

    public function testEnsure_RecordsWhoOpenedTheAccountInTheAuditTrail(): void
    {
        $accountId = $this->service->ensure(EntityAccountService::CUSTOMER, $this->insertCustomer(), 'Omar Khaled', 9);

        $entry   = $this->accountingDb()->table('accounting_audit_log')->where('entity_type', 'account')->where('entity_id', $accountId)->where('action', 'created')->get()->getRow();
        $details = json_decode($entry->details, true);

        $this->assertSame('9', (string) $entry->employee_id);
        $this->assertSame('system', $entry->origin);
        $this->assertSame('customer', $details['entity_type']);
    }

    public function testEnsure_RefusesWhenTheMainAccountIsNotAGroup(): void
    {
        $this->accountingDb()->table('accounting_routing')->where('routing_key', 'sales.customers')->update(['account_id' => $this->accountId('cash')]);

        $this->assertFailsWith(AccountingError::EntityMainAccountInvalid, fn () => $this->service->ensure(EntityAccountService::CUSTOMER, $this->insertCustomer(), 'Omar Khaled'));
    }

    public function testEnsure_RefusesWhenTheMainAccountIsInactive(): void
    {
        $this->accountingDb()->table('accounting_accounts')->where('system_key', 'receivables')->update(['active' => 0]);

        $this->assertFailsWith(AccountingError::EntityMainAccountInvalid, fn () => $this->service->ensure(EntityAccountService::CUSTOMER, $this->insertCustomer(), 'Omar Khaled'));
    }

    public function testEnsure_RefusesAKindThatHasNoAccounts(): void
    {
        $this->assertFailsWith(AccountingError::EntityKindUnknown, fn () => $this->service->ensure('employee', 1, 'Someone'));
    }

    public function testEnsure_LeavesNoHalfOpenedAccountWhenItFails(): void
    {
        $this->accountingDb()->table('accounting_routing')->where('routing_key', 'sales.customers')->update(['account_id' => null]);

        try {
            $this->service->ensure(EntityAccountService::CUSTOMER, $this->insertCustomer(), 'Omar Khaled');
        } catch (AccountingException) {
        }

        $this->assertSame(0, $this->accountingDb()->table('accounting_accounts')->where('entity_type', 'customer')->countAllResults());
    }

    public function testEnsure_WaitsForAnotherRequestOpeningAnAccountUnderTheSameParent(): void
    {
        $this->other = $this->openSecondConnection();

        $this->accountingDb()->resetTransStatus();
        $this->accountingDb()->transStart();
        $this->service->ensure(EntityAccountService::CUSTOMER, $this->insertCustomer(), 'Omar Khaled');

        $second = $this->insertCustomer('Hind', 'Saad');

        $this->assertWaitsForLock(fn () => (new EntityAccountService($this->other))->ensure(EntityAccountService::CUSTOMER, $second, 'Hind Saad'));

        $this->accountingDb()->transComplete();

        $accountId = (new EntityAccountService($this->other))->ensure(EntityAccountService::CUSTOMER, $second, 'Hind Saad');

        $this->assertSame('12041002', $this->accountRow($accountId)->code);
    }

    public function testAccountFor_FindsTheSubAccountOfAnEntity(): void
    {
        $customerId = $this->insertCustomer();
        $accountId  = $this->service->ensure(EntityAccountService::CUSTOMER, $customerId, 'Omar Khaled');

        $this->assertSame($accountId, $this->service->accountFor(EntityAccountService::CUSTOMER, $customerId));
        $this->assertNull($this->service->accountFor(EntityAccountService::SUPPLIER, $customerId));
        $this->assertNull($this->service->accountFor(EntityAccountService::CUSTOMER, 999999));
    }

    public function testEntityOf_NamesTheEntityOfASubAccountAndNothingForOtherAccounts(): void
    {
        $customerId = $this->insertCustomer();
        $accountId  = $this->service->ensure(EntityAccountService::CUSTOMER, $customerId, 'Omar Khaled');

        $this->assertSame(['kind' => 'customer', 'id' => $customerId], $this->service->entityOf($accountId));
        $this->assertNull($this->service->entityOf($this->accountId('cash')));
        $this->assertNull($this->service->entityOf(999999));
    }

    public function testIsAutomatic_IsTrueOnlyWhileTheRoutingTypeIsAutomatic(): void
    {
        $this->assertTrue($this->service->isAutomatic(EntityAccountService::CUSTOMER));

        $this->accountingDb()->table('accounting_routing')->where('routing_key', 'sales.customers')->update(['routing_type' => 'main_per_entity']);

        $this->assertFalse($this->service->isAutomatic(EntityAccountService::CUSTOMER));
        $this->assertFalse($this->service->isAutomatic('employee'));
    }

    public function testRename_FollowsTheEntityAndKeepsTheCode(): void
    {
        $customerId = $this->insertCustomer();
        $accountId  = $this->service->ensure(EntityAccountService::CUSTOMER, $customerId, 'Omar Khaled');

        $this->service->rename(EntityAccountService::CUSTOMER, $customerId, 'Omar Al-Khaled');

        $account = $this->accountRow($accountId);
        $this->assertSame('Omar Al-Khaled', $account->name);
        $this->assertSame('12041001', $account->code);
        $this->assertSame(1, $this->accountingDb()->table('accounting_audit_log')->where('entity_id', $accountId)->where('action', 'updated')->countAllResults());
    }

    public function testRename_WritesNothingWhenTheNameIsTheSame(): void
    {
        $customerId = $this->insertCustomer();
        $accountId  = $this->service->ensure(EntityAccountService::CUSTOMER, $customerId, 'Omar Khaled');

        $this->service->rename(EntityAccountService::CUSTOMER, $customerId, 'Omar Khaled');

        $this->assertSame(0, $this->accountingDb()->table('accounting_audit_log')->where('entity_id', $accountId)->where('action', 'updated')->countAllResults());
    }

    public function testRename_IgnoresAnEntityWithoutAnAccount(): void
    {
        $this->service->rename(EntityAccountService::CUSTOMER, $this->insertCustomer(), 'Anyone');

        $this->assertSame(0, $this->accountingDb()->table('accounting_accounts')->where('entity_type', 'customer')->countAllResults());
    }

    public function testRetire_StopsTheAccountTakingNewEntriesAndKeepsItsName(): void
    {
        $customerId = $this->insertCustomer();
        $accountId  = $this->service->ensure(EntityAccountService::CUSTOMER, $customerId, 'Omar Khaled');

        $this->service->retire(EntityAccountService::CUSTOMER, $customerId, false);

        $account = $this->accountRow($accountId);
        $this->assertSame('0', (string) $account->active);
        $this->assertSame('Omar Khaled', $account->name);
    }

    public function testRetire_ReplacesTheNameWithTheCodeWhenAskedToAnonymize(): void
    {
        $customerId = $this->insertCustomer();
        $accountId  = $this->service->ensure(EntityAccountService::CUSTOMER, $customerId, 'Omar Khaled');

        $this->service->retire(EntityAccountService::CUSTOMER, $customerId, true);

        $this->assertSame('12041001', $this->accountRow($accountId)->name);
    }

    public function testSync_OpensTheAccountOfASavedEntityWhileAccountingIsOn(): void
    {
        $customerId = $this->insertCustomer('Omar', 'Khaled');

        $this->service->sync(EntityAccountService::CUSTOMER, $customerId);

        $this->assertSame('Omar Khaled', $this->accountRow((int) $this->service->accountFor(EntityAccountService::CUSTOMER, $customerId))->name);
    }

    public function testSync_NamesAnEntityAfterItsCompanyWhenItHasOne(): void
    {
        $customerId = $this->insertCustomer('Omar', 'Khaled', 'Riyadh Trading');
        $supplierId = $this->insertSupplier('Gulf Supplies', 'Sara', 'Nasser');

        $this->service->sync(EntityAccountService::CUSTOMER, $customerId);
        $this->service->sync(EntityAccountService::SUPPLIER, $supplierId);

        $this->assertSame('Riyadh Trading', $this->accountRow((int) $this->service->accountFor(EntityAccountService::CUSTOMER, $customerId))->name);
        $this->assertSame('Gulf Supplies', $this->accountRow((int) $this->service->accountFor(EntityAccountService::SUPPLIER, $supplierId))->name);
    }

    public function testSync_DoesNothingWhileAccountingIsOff(): void
    {
        $this->setConfigValue('accounting_enabled', '0');
        $customerId = $this->insertCustomer();

        $this->service->sync(EntityAccountService::CUSTOMER, $customerId);

        $this->assertNull($this->service->accountFor(EntityAccountService::CUSTOMER, $customerId));
    }

    public function testSync_DoesNothingWhenTheRoutingIsNotAutomatic(): void
    {
        $this->accountingDb()->table('accounting_routing')->where('routing_key', 'sales.customers')->update(['routing_type' => 'per_item']);
        $customerId = $this->insertCustomer();

        $this->service->sync(EntityAccountService::CUSTOMER, $customerId);

        $this->assertNull($this->service->accountFor(EntityAccountService::CUSTOMER, $customerId));
    }

    public function testSync_RenamesTheAccountAndOpensItAgainForAnEntityThatCameBack(): void
    {
        $customerId = $this->insertCustomer('Omar', 'Khaled');
        $accountId  = $this->service->ensure(EntityAccountService::CUSTOMER, $customerId, 'Omar Khaled');
        $this->service->retire(EntityAccountService::CUSTOMER, $customerId, false);
        $this->accountingDb()->table('people')->where('person_id', $customerId)->update(['last_name' => 'Al-Khaled']);

        $this->service->sync(EntityAccountService::CUSTOMER, $customerId);

        $account = $this->accountRow($accountId);
        $this->assertSame('1', (string) $account->active);
        $this->assertSame('Omar Al-Khaled', $account->name);
    }

    public function testSync_NeverThrowsWhenTheAccountCannotBeOpened(): void
    {
        $this->accountingDb()->table('accounting_routing')->where('routing_key', 'sales.customers')->update(['account_id' => null]);
        $customerId = $this->insertCustomer();

        $this->service->sync(EntityAccountService::CUSTOMER, $customerId);

        $this->assertNull($this->service->accountFor(EntityAccountService::CUSTOMER, $customerId));
    }

    public function testSyncDeleted_RetiresTheAccountOnlyWhileAccountingIsOn(): void
    {
        $customerId = $this->insertCustomer();
        $accountId  = $this->service->ensure(EntityAccountService::CUSTOMER, $customerId, 'Omar Khaled');

        $this->setConfigValue('accounting_enabled', '0');
        $this->service->syncDeleted(EntityAccountService::CUSTOMER, $customerId);
        $this->assertSame('1', (string) $this->accountRow($accountId)->active);

        $this->setConfigValue('accounting_enabled', '1');
        $this->service->syncDeleted(EntityAccountService::CUSTOMER, $customerId, true);
        $this->assertSame('0', (string) $this->accountRow($accountId)->active);
    }

    public function testMissingCounts_CountsTheEntitiesWithoutAnAccountByKind(): void
    {
        $this->service->fill();
        $this->insertCustomer();
        $this->insertCustomer('Hind', 'Saad');
        $this->insertStockLocation('Back store');

        $this->assertSame([EntityAccountService::CUSTOMER => 2, EntityAccountService::STOCK_LOCATION => 1], $this->service->missingCounts());
    }

    public function testMissingCounts_LeavesOutEntitiesThatWereDeleted(): void
    {
        $this->service->fill();
        $customerId = $this->insertCustomer();
        $this->accountingDb()->table('customers')->where('person_id', $customerId)->update(['deleted' => 1]);

        $this->assertSame([], $this->service->missingCounts());
    }

    public function testMissingCounts_LeavesOutAKindWhoseRoutingIsNotAutomatic(): void
    {
        $this->service->fill();
        $this->insertCustomer();
        $this->accountingDb()->table('accounting_routing')->where('routing_key', 'sales.customers')->update(['routing_type' => 'per_item']);

        $this->assertSame([], $this->service->missingCounts());
    }

    public function testFill_OpensTheAccountsOfEveryEntityThatHasNone(): void
    {
        $customerId = $this->insertCustomer();
        $supplierId = $this->insertSupplier();
        $locationId = $this->insertStockLocation('Back store');

        $created = $this->service->fill();

        $this->assertSame(1, $created[EntityAccountService::CUSTOMER]);
        $this->assertSame(1, $created[EntityAccountService::SUPPLIER]);
        $this->assertGreaterThanOrEqual(2, $created[EntityAccountService::STOCK_LOCATION]);
        $this->assertNotNull($this->service->accountFor(EntityAccountService::CUSTOMER, $customerId));
        $this->assertNotNull($this->service->accountFor(EntityAccountService::SUPPLIER, $supplierId));
        $this->assertNotNull($this->service->accountFor(EntityAccountService::STOCK_LOCATION, $locationId));
    }

    public function testFill_CanBeRepeatedWithoutCreatingAnythingTwice(): void
    {
        $this->insertCustomer();
        $this->service->fill();
        $before = $this->countRows('accounting_accounts');

        $created = $this->service->fill();

        $this->assertSame(0, $created[EntityAccountService::CUSTOMER]);
        $this->assertSame($before, $this->countRows('accounting_accounts'));
    }

    public function testFill_SkipsEntitiesThatWereDeleted(): void
    {
        $customerId = $this->insertCustomer();
        $this->accountingDb()->table('customers')->where('person_id', $customerId)->update(['deleted' => 1]);

        $this->service->fill();

        $this->assertNull($this->service->accountFor(EntityAccountService::CUSTOMER, $customerId));
    }

    public function testFill_SkipsAKindWhoseRoutingIsNotAutomatic(): void
    {
        $this->insertCustomer();
        $this->accountingDb()->table('accounting_routing')->where('routing_key', 'sales.customers')->update(['routing_type' => 'per_item']);

        $created = $this->service->fill();

        $this->assertArrayNotHasKey(EntityAccountService::CUSTOMER, $created);
    }

    public function testFill_StopsWhenTheMainAccountIsNotUsable(): void
    {
        $this->insertCustomer();
        $this->accountingDb()->table('accounting_routing')->where('routing_key', 'sales.customers')->update(['account_id' => null]);

        $this->assertFailsWith(AccountingError::EntityMainAccountInvalid, fn () => $this->service->fill());
    }

    public function testFill_WorksThroughMoreEntitiesThanOneChunk(): void
    {
        $db = $this->accountingDb();

        for ($i = 0; $i < 205; $i++) {
            $this->insertCustomer('Customer', (string) $i);
        }

        $created = $this->service->fill();

        $this->assertSame(205, $created[EntityAccountService::CUSTOMER]);
        $this->assertSame(205, $db->table('accounting_accounts')->where('entity_type', 'customer')->countAllResults());
        $this->assertSame([], $this->service->missingCounts());
    }

    public function testKinds_ListsTheEntitiesTheApplicationHasToday(): void
    {
        $this->assertSame(['customer', 'supplier', 'stock_location'], EntityAccountService::kinds());
    }
}
