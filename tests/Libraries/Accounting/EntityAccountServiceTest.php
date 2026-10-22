<?php

namespace Tests\Libraries\Accounting;

use App\Libraries\Accounting\AccountingError;
use App\Libraries\Accounting\AccountingException;
use App\Libraries\Accounting\AccountingGuard;
use App\Libraries\Accounting\AccountingNotReadyException;
use App\Libraries\Accounting\AccountingSettings;
use App\Libraries\Accounting\AccountService;
use App\Libraries\Accounting\EntityAccountService;
use App\Libraries\Accounting\ReadinessCode;
use App\Models\Customer;
use App\Models\Stock_location;
use App\Models\Supplier;
use CodeIgniter\Database\BaseConnection;
use CodeIgniter\Database\Exceptions\DatabaseException;
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
    private ?BaseConnection $other                           = null;
    private ?int $removeLocationPermissionsAfterTheTest = null;

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

        if ($this->removeLocationPermissionsAfterTheTest !== null) {
            $this->removeLocationPermissions($this->removeLocationPermissionsAfterTheTest);
            $this->removeLocationPermissionsAfterTheTest = null;
        }

        session()->remove('person_id');

        if ($this->other !== null) {
            $this->other->transRollback();
            $this->other->close();
            $this->other = null;
        }

        $this->removeInsertedEntities();
        $this->setConfigValue('accounting_enabled', '0');

        parent::tearDown();
    }

    /**
     * @param list<string> $grants
     */
    private function actAsEmployeeWith(array $grants): void
    {
        session()->set('person_id', $this->employeeWithGrants($grants));
    }

    private function removeLocationPermissions(int $locationId): void
    {
        $db            = $this->accountingDb();
        $permissionIds = array_column($db->table('permissions')->select('permission_id')->where('location_id', $locationId)->get()->getResultArray(), 'permission_id');

        if ($permissionIds !== []) {
            $db->table('grants')->whereIn('permission_id', $permissionIds)->delete();
            $db->table('permissions')->whereIn('permission_id', $permissionIds)->delete();
        }
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

    public function testEnsure_ReturnsTheAccountAnotherRequestOpenedMeanwhileInsteadOfFailingOrDuplicating(): void
    {
        $this->other = $this->openSecondConnection();
        $customerId  = $this->insertCustomer();

        $this->startWithSnapshot($this->other);
        $first  = $this->service->ensure(EntityAccountService::CUSTOMER, $customerId, 'Omar Khaled');
        $second = (new EntityAccountService($this->other))->ensure(EntityAccountService::CUSTOMER, $customerId, 'Omar Khaled');

        $this->assertSame($first, $second);
        $this->assertSame(1, $this->accountingDb()->table('accounting_accounts')->where('entity_type', 'customer')->where('entity_id', $customerId)->countAllResults());
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

    public function testIntegration_ACustomerASupplierAndALocationSavedThroughTheModelsEachGetExactlyOneAccount(): void
    {
        $customer = model(Customer::class);
        $supplier = model(Supplier::class);
        $location = model(Stock_location::class);

        $person       = $this->personData('Omar', 'Khaled');
        $customerData = ['company_name' => null, 'employee_id' => 1];
        $customer->save_customer($person, $customerData);
        $customerId = $this->insertedPeople[] = (int) $person['person_id'];

        $person       = $this->personData('Sara', 'Nasser');
        $supplierData = ['company_name' => 'Gulf Supplies', 'agency_name' => '', 'category' => 0];
        $supplier->save_supplier($person, $supplierData);
        $supplierId = $this->insertedPeople[] = (int) $person['person_id'];

        $locationData = ['location_name' => 'Back store'];
        $location->save_value($locationData, -1);
        $locationId = $this->insertedLocations[] = (int) $locationData['location_id'];
        $this->removeLocationPermissionsAfterTheTest = $locationId;

        $codes = [
            $this->accountRow((int) $this->service->accountFor(EntityAccountService::CUSTOMER, $customerId))->code,
            $this->accountRow((int) $this->service->accountFor(EntityAccountService::SUPPLIER, $supplierId))->code,
            $this->accountRow((int) $this->service->accountFor(EntityAccountService::STOCK_LOCATION, $locationId))->code,
        ];

        $this->assertSame(['12041001', '2211001', '1203001'], $codes);

        $person['first_name'] = 'Renamed';
        $customer->save_customer($person, $customerData, $customerId);
        $supplier->save_supplier($person, $supplierData, $supplierId);
        $location->save_value($locationData, $locationId);

        $this->assertSame(1, $this->accountingDb()->table('accounting_accounts')->where('entity_type', 'customer')->where('entity_id', $customerId)->countAllResults());
        $this->assertSame(1, $this->accountingDb()->table('accounting_accounts')->where('entity_type', 'supplier')->where('entity_id', $supplierId)->countAllResults());
        $this->assertSame(1, $this->accountingDb()->table('accounting_accounts')->where('entity_type', 'stock_location')->where('entity_id', $locationId)->countAllResults());
    }

    /**
     * @return array<string, mixed>
     */
    private function personData(string $firstName, string $lastName): array
    {
        return [
            'first_name'   => $firstName,
            'last_name'    => $lastName,
            'gender'       => null,
            'phone_number' => '',
            'email'        => '',
            'address_1'    => '',
            'address_2'    => '',
            'city'         => '',
            'state'        => '',
            'zip'          => '',
            'country'      => '',
            'comments'     => '',
        ];
    }

    public function testIntegration_TheDatabaseItselfRefusesASecondAccountForTheSameEntity(): void
    {
        $customerId = $this->insertCustomer();
        $accountId  = $this->service->ensure(EntityAccountService::CUSTOMER, $customerId, 'Omar Khaled');
        $row        = (array) $this->accountRow($accountId);

        unset($row['account_id']);
        $row['code'] = '12049999';
        $row['name'] = 'A second account';

        $this->expectException(DatabaseException::class);
        $this->expectExceptionMessage('Duplicate entry');

        $this->accountingDb()->table('accounting_accounts')->insert($row);
    }

    public function testIntegration_TwoRequestsOpeningTheSameEntityEndWithOneAccount(): void
    {
        $this->other = $this->openSecondConnection();
        $customerId  = $this->insertCustomer();

        $this->accountingDb()->resetTransStatus();
        $this->accountingDb()->transStart();
        $first = $this->service->ensure(EntityAccountService::CUSTOMER, $customerId, 'Omar Khaled');

        $this->assertWaitsForLock(fn () => (new EntityAccountService($this->other))->ensure(EntityAccountService::CUSTOMER, $customerId, 'Omar Khaled'));

        $this->accountingDb()->transComplete();

        $second = (new EntityAccountService($this->other))->ensure(EntityAccountService::CUSTOMER, $customerId, 'Omar Khaled');

        $this->assertSame($first, $second);
        $this->assertSame(1, $this->accountingDb()->table('accounting_accounts')->where('entity_type', 'customer')->where('entity_id', $customerId)->countAllResults());
    }

    public function testIntegration_AnEmployeeWithoutAccountingPermissionsStillGetsTheAccountOfTheCustomerTheySave(): void
    {
        $this->actAsEmployeeWith(['customers']);

        $person   = $this->personData('Omar', 'Khaled');
        $customer = ['company_name' => null, 'employee_id' => 1];
        model(Customer::class)->save_customer($person, $customer);
        $customerId = $this->insertedPeople[] = (int) $person['person_id'];

        $accountId = $this->service->accountFor(EntityAccountService::CUSTOMER, $customerId);

        $this->assertNotNull($accountId);
        $this->assertSame('system', $this->accountingDb()->table('accounting_audit_log')->where('entity_type', 'account')->where('entity_id', $accountId)->where('action', 'created')->get()->getRow()->origin);
    }

    public function testIntegration_OnlyTheChartManagersMayTouchAnEntityAccountAndEvenTheyMayNotChangeIt(): void
    {
        $accountId = $this->service->ensure(EntityAccountService::CUSTOMER, $this->insertCustomer(), 'Omar Khaled');
        $accounts  = new AccountService();
        $viewer    = $this->employeeWithGrants(['accounting_chart']);
        $manager   = $this->employeeWithGrants(['accounting_chart', AccountingGuard::CHART_MANAGE]);

        foreach ([
            fn (int $by) => $accounts->update($accountId, ['name' => 'Another name'], $by),
            fn (int $by) => $accounts->deactivate($accountId, $by),
            fn (int $by) => $accounts->delete($accountId, $by),
        ] as $change) {
            $this->assertFailsWith(AccountingError::PermissionDenied, fn () => $change($viewer));
            $this->assertFailsWith(AccountingError::SystemAccountLocked, fn () => $change($manager));
        }

        $account = $this->accountRow($accountId);
        $this->assertSame('Omar Khaled', $account->name);
        $this->assertSame('1', (string) $account->active);
    }

    public function testIntegration_EnablingAccountingWaitsForTheAccountsOfTheEntitiesThatExistedBeforeIt(): void
    {
        $this->setConfigValue('accounting_enabled', '0');
        $this->setConfigValue('accounting_start_date', '2026-01-01');
        $customerId = $this->insertCustomer();
        $settings   = new AccountingSettings();

        try {
            $settings->enable();
            $this->fail('Accounting must not be enabled while an entity has no account.');
        } catch (AccountingNotReadyException $exception) {
            $this->assertSame([ReadinessCode::EntityAccountsMissing], array_column($exception->issues, 'code'));
        }

        $this->assertNull($this->service->accountFor(EntityAccountService::CUSTOMER, $customerId));

        $this->service->fill();
        $settings->enable();

        $this->assertTrue($settings->isEnabled());

        $person   = $this->personData('Hind', 'Saad');
        $customer = ['company_name' => null, 'employee_id' => 1];
        model(Customer::class)->save_customer($person, $customer);
        $later = $this->insertedPeople[] = (int) $person['person_id'];

        $this->assertNotNull($this->service->accountFor(EntityAccountService::CUSTOMER, $later));
        $this->assertSame([], $settings->readiness());
    }

    public function testKinds_ListsTheEntitiesTheApplicationHasToday(): void
    {
        $this->assertSame(['customer', 'supplier', 'stock_location'], EntityAccountService::kinds());
    }
}
