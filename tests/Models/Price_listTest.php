<?php

namespace Tests\Models;

use App\Libraries\Accounting\AccountingError;
use App\Libraries\Accounting\AccountingException;
use App\Libraries\Pricing\SalesControlSettings;
use App\Models\Price_list;
use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\DatabaseTestTrait;
use Config\Database;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Support\AccountingFixtureTrait;
use Tests\Support\EmployeeFixtureTrait;
use Tests\Support\ItemFixtureTrait;

class Price_listTest extends CIUnitTestCase
{
    use DatabaseTestTrait;
    use AccountingFixtureTrait;
    use EmployeeFixtureTrait;
    use ItemFixtureTrait;

    protected $migrate     = true;
    protected $migrateOnce = true;
    protected $refresh     = false;
    protected $namespace   = null;

    private Price_list $lists;
    private int $employeeId;

    public static function setUpBeforeClass(): void
    {
        $seeder = Database::seeder('tests');
        $seeder->call('TestDatabaseBootstrapSeeder');
    }

    protected function setUp(): void
    {
        parent::setUp();

        $this->lists      = model(Price_list::class);
        $this->employeeId = $this->createEmployee();
    }

    protected function tearDown(): void
    {
        $db = $this->accountingDb();

        $db->table('customers')->update(['price_list_id' => null]);
        $db->table('sales')->update(['price_list_id' => null]);
        $db->table('price_list_items')->where('price_list_id >', 0)->delete();
        $db->table('price_lists')->where('price_list_id >', 0)->delete();
        $db->table('accounting_audit_log')->where('entity_type', 'price_list')->delete();
        $db->table('accounting_audit_log')->where('action', 'customer_price_list_changed')->delete();

        foreach ([SalesControlSettings::PRICE_LISTS, SalesControlSettings::CHANGE_LIST] as $key) {
            $this->setConfigValue($key, '0');
        }

        $this->setConfigValue(SalesControlSettings::DEFAULT_LIST, '');

        $this->removeInsertedEntities();

        parent::tearDown();
    }

    private function list(string $name = 'Wholesale', bool $active = true): int
    {
        return $this->lists->saveList($name, $active, null, $this->employeeId);
    }

    private function refused(callable $change): AccountingException
    {
        try {
            $change();
        } catch (AccountingException $refusal) {
            return $refusal;
        }

        $this->fail('The change should have been refused.');
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function audit(string $action): array
    {
        return $this->accountingDb()->table('accounting_audit_log')->where('action', $action)->orderBy('audit_id', 'ASC')->get()->getResultArray();
    }

    public function testSaveList_CreatesAnActiveListAndWritesItInTheAuditLog(): void
    {
        $listId = $this->list();

        $row = $this->lists->getList($listId);

        $this->assertSame('Wholesale', $row->name);
        $this->assertSame(1, (int) $row->status);
        $this->assertSame(0, (int) $row->customers);
        $this->assertSame(0, (int) $row->items);

        $entries = $this->audit('price_list_created');

        $this->assertCount(1, $entries);
        $this->assertSame($listId, (int) $entries[0]['entity_id']);
        $this->assertSame($this->employeeId, (int) $entries[0]['employee_id']);
        $this->assertSame(['name' => 'Wholesale', 'active' => true], json_decode($entries[0]['details'], true));
    }

    public function testSaveList_CreatesAnInactiveListWhenAskedTo(): void
    {
        $this->assertSame(0, (int) $this->lists->getList($this->list('Retired', false))->status);
    }

    public function testSaveList_ChangesTheNameAndTheStatusAndWritesBeforeAndAfter(): void
    {
        $listId = $this->list();

        $this->lists->saveList('Wholesale plus', false, $listId, $this->employeeId);

        $row = $this->lists->getList($listId);

        $this->assertSame('Wholesale plus', $row->name);
        $this->assertSame(0, (int) $row->status);
        $this->assertSame(['before' => ['name' => 'Wholesale', 'active' => true], 'after' => ['name' => 'Wholesale plus', 'active' => false]], json_decode($this->audit('price_list_changed')[0]['details'], true));
    }

    public function testSaveList_WritesNothingWhenNothingChanged(): void
    {
        $listId = $this->list();

        $this->lists->saveList('Wholesale', true, $listId, $this->employeeId);

        $this->assertSame([], $this->audit('price_list_changed'));
    }

    public function testSaveList_RejectsAnEmptyNameAndANameOfMoreThan100Characters(): void
    {
        foreach (['', '   ', str_repeat('n', 101)] as $name) {
            $this->assertSame(AccountingError::PriceListNameInvalid, $this->refused(fn () => $this->lists->saveList($name, true, null, $this->employeeId))->error);
        }

        $this->assertSame([], $this->lists->allLists());
        $this->assertGreaterThan(0, $this->list(str_repeat('n', 100)));
    }

    public function testSaveList_RejectsANameAnotherListHasWhateverTheCase(): void
    {
        $this->list('Wholesale');
        $other = $this->list('Retail');

        $this->assertSame(AccountingError::PriceListNameTaken, $this->refused(fn () => $this->list('WHOLESALE'))->error);
        $this->assertSame(AccountingError::PriceListNameTaken, $this->refused(fn () => $this->lists->saveList('wholesale', true, $other, $this->employeeId))->error);
        $this->assertSame('Retail', $this->lists->getList($other)->name);
        $this->assertCount(2, $this->lists->allLists());
    }

    public function testSaveList_RejectsAListThatDoesNotExist(): void
    {
        $this->assertSame(AccountingError::PriceListNotFound, $this->refused(fn () => $this->lists->saveList('Ghost', true, 987654, $this->employeeId))->error);
    }

    public function testSetStatus_DeactivatesAndActivatesAndWritesEach(): void
    {
        $listId = $this->list();

        $this->lists->setStatus($listId, false, $this->employeeId);

        $this->assertSame(0, (int) $this->lists->getList($listId)->status);
        $this->assertCount(1, $this->audit('price_list_deactivated'));

        $this->lists->setStatus($listId, true, $this->employeeId);

        $this->assertSame(1, (int) $this->lists->getList($listId)->status);
        $this->assertCount(1, $this->audit('price_list_activated'));
    }

    public function testSetStatus_WritesNothingWhenTheStatusIsAlreadyThat(): void
    {
        $listId = $this->list();

        $this->lists->setStatus($listId, true, $this->employeeId);

        $this->assertSame([], $this->audit('price_list_activated'));
    }

    public function testSetStatus_RejectsAListThatDoesNotExist(): void
    {
        $this->assertSame(AccountingError::PriceListNotFound, $this->refused(fn () => $this->lists->setStatus(987654, false, $this->employeeId))->error);
    }

    public function testDeleteLists_DeletesAnUnusedListWithItsPricesAndWritesIt(): void
    {
        $listId = $this->list();
        $item   = $this->createTestItem();

        $this->lists->saveItemPrices($item, [$listId => '9.50'], $this->employeeId);
        $this->lists->deleteLists([$listId], $this->employeeId);

        $this->assertNull($this->lists->getList($listId));
        $this->assertSame(0, $this->accountingDb()->table('price_list_items')->where('price_list_id', $listId)->countAllResults());
        $this->assertSame(['name' => 'Wholesale', 'prices' => 1], json_decode($this->audit('price_list_deleted')[0]['details'], true));
    }

    public function testDeleteLists_RefusesAListAssignedToACustomerAndKeepsIt(): void
    {
        $listId     = $this->list();
        $customerId = $this->insertCustomer();

        $this->lists->assignTo($customerId, $listId, $this->employeeId);

        $this->assertSame(AccountingError::PriceListInUse, $this->refused(fn () => $this->lists->deleteLists([$listId], $this->employeeId))->error);
        $this->assertNotNull($this->lists->getList($listId));
    }

    public function testDeleteLists_RefusesAListASaleWasPricedFromAndKeepsIt(): void
    {
        $listId = $this->list();

        $this->accountingDb()->table('sales')->insert(['sale_time' => '2026-10-21 10:00:00', 'customer_id' => null, 'employee_id' => $this->employeeId, 'comment' => 'priced from a list', 'invoice_number' => null, 'price_list_id' => $listId]);
        $saleId = (int) $this->accountingDb()->insertID();

        try {
            $this->assertSame(AccountingError::PriceListInUse, $this->refused(fn () => $this->lists->deleteLists([$listId], $this->employeeId))->error);
            $this->assertNotNull($this->lists->getList($listId));
        } finally {
            $this->accountingDb()->table('sales')->where('sale_id', $saleId)->delete();
        }
    }

    public function testDeleteLists_DeletesNoneWhenOneOfThemIsInUse(): void
    {
        $free       = $this->list('Free');
        $held       = $this->list('Held');
        $customerId = $this->insertCustomer();

        $this->lists->assignTo($customerId, $held, $this->employeeId);

        $this->refused(fn () => $this->lists->deleteLists([$free, $held], $this->employeeId));

        $this->assertNotNull($this->lists->getList($free), 'the free list stays when the other one is refused');
        $this->assertSame([], $this->audit('price_list_deleted'));
    }

    public function testDeleteLists_RejectsAListThatDoesNotExist(): void
    {
        $this->assertSame(AccountingError::PriceListNotFound, $this->refused(fn () => $this->lists->deleteLists([987654], $this->employeeId))->error);
    }

    public function testSaveItemPrices_SetsChangesAndRemovesThePriceOfAnItemInEachList(): void
    {
        $wholesale = $this->list('Wholesale');
        $retail    = $this->list('Retail');
        $item      = $this->createTestItem();

        $this->lists->saveItemPrices($item, [$wholesale => '8.5', $retail => '9'], $this->employeeId);

        $this->assertSame([$wholesale => '8.50', $retail => '9.00'], $this->lists->pricesOfItem($item));

        $this->lists->saveItemPrices($item, [$wholesale => '7.25', $retail => ''], $this->employeeId);

        $this->assertSame([$wholesale => '7.25'], $this->lists->pricesOfItem($item));
        $this->assertSame('7.25', $this->lists->priceOf($wholesale, $item));
        $this->assertNull($this->lists->priceOf($retail, $item));
    }

    public function testSaveItemPrices_KeepsThePricesInTheListsTheFormDidNotMention(): void
    {
        $wholesale = $this->list('Wholesale');
        $retail    = $this->list('Retail');
        $item      = $this->createTestItem();

        $this->lists->saveItemPrices($item, [$wholesale => '8', $retail => '9'], $this->employeeId);
        $this->lists->saveItemPrices($item, [$wholesale => '6'], $this->employeeId);

        $this->assertSame([$wholesale => '6.00', $retail => '9.00'], $this->lists->pricesOfItem($item));
    }

    public function testSaveItemPrices_WritesWhatChangedOfTheItemInOneEntry(): void
    {
        $wholesale = $this->list('Wholesale');
        $item      = $this->createTestItem();

        $this->lists->saveItemPrices($item, [$wholesale => '8'], $this->employeeId);
        $this->lists->saveItemPrices($item, [$wholesale => '8.0000'], $this->employeeId);
        $this->lists->saveItemPrices($item, [$wholesale => '7'], $this->employeeId);

        $entries = $this->audit('price_list_prices_changed');

        $this->assertCount(2, $entries, 'an entry that changes nothing is not written');
        $this->assertSame(['item_id' => $item, 'lists' => [(string) $wholesale => ['from' => '8.00', 'to' => '7.0000']]], json_decode($entries[1]['details'], true));
    }

    /**
     * @return array<string, array{0: string}>
     */
    public static function invalidPriceProvider(): array
    {
        return ['negative' => ['-1'], 'not a number' => ['cheap'], 'five decimals' => ['1.23456'], 'with a sign' => ['+5'], 'with a comma' => ['1,5']];
    }

    #[DataProvider('invalidPriceProvider')]
    public function testSaveItemPrices_RejectsAPriceThatIsNotANumberThatIsNotNegativeAndSavesNothingOfTheRest(string $price): void
    {
        $wholesale = $this->list('Wholesale');
        $retail    = $this->list('Retail');
        $item      = $this->createTestItem();

        $this->assertSame(AccountingError::PriceInvalid, $this->refused(fn () => $this->lists->saveItemPrices($item, [$wholesale => '5', $retail => $price], $this->employeeId))->error);
        $this->assertSame([], $this->lists->pricesOfItem($item));
        $this->assertSame([], $this->audit('price_list_prices_changed'));
    }

    public function testSaveItemPrices_AcceptsAPriceOfZeroAndOneWithFourDecimals(): void
    {
        $wholesale = $this->list('Wholesale');
        $item      = $this->createTestItem();

        $this->lists->saveItemPrices($item, [$wholesale => '0'], $this->employeeId);

        $this->assertSame('0.00', $this->lists->priceOf($wholesale, $item));

        $this->lists->saveItemPrices($item, [$wholesale => '12.3456'], $this->employeeId);

        $this->assertSame('12.3456', $this->lists->priceOf($wholesale, $item));
    }

    public function testSaveItemPrices_RejectsAListThatDoesNotExist(): void
    {
        $item = $this->createTestItem();

        $this->assertSame(AccountingError::PriceListNotFound, $this->refused(fn () => $this->lists->saveItemPrices($item, [987654 => '5'], $this->employeeId))->error);
    }

    public function testAsLinePrice_GivesTwoDecimalsAndMoreOnlyWhenThePriceNeedsThem(): void
    {
        $this->assertSame('12.50', Price_list::asLinePrice('12.5000'));
        $this->assertSame('10.00', Price_list::asLinePrice('10.0000'));
        $this->assertSame('0.00', Price_list::asLinePrice('0.0000'));
        $this->assertSame('12.3456', Price_list::asLinePrice('12.3456'));
        $this->assertSame('12.345', Price_list::asLinePrice('12.3450'));
    }

    public function testEffectiveListId_PricesNothingWhileThePriceListsAreOff(): void
    {
        $listId     = $this->list();
        $customerId = $this->insertCustomer();

        $this->lists->assignTo($customerId, $listId, $this->employeeId);
        $this->setConfigValue(SalesControlSettings::DEFAULT_LIST, (string) $listId);

        $this->assertNull($this->lists->effectiveListId($customerId, $listId));
    }

    public function testEffectiveListId_TakesTheListOfTheCustomerThenTheDefaultList(): void
    {
        $this->setConfigValue(SalesControlSettings::PRICE_LISTS, '1');

        $own        = $this->list('Own');
        $default    = $this->list('Default');
        $customerId = $this->insertCustomer();
        $bare       = $this->insertCustomer('Bare', 'Customer');

        $this->lists->assignTo($customerId, $own, $this->employeeId);
        $this->setConfigValue(SalesControlSettings::DEFAULT_LIST, (string) $default);

        $this->assertSame($own, $this->lists->effectiveListId($customerId));
        $this->assertSame($default, $this->lists->effectiveListId($bare));
        $this->assertSame($default, $this->lists->effectiveListId(null));
        $this->assertSame($default, $this->lists->effectiveListId(-1));
    }

    public function testEffectiveListId_PricesFromNoListWhenThereIsNeitherACustomerListNorADefault(): void
    {
        $this->setConfigValue(SalesControlSettings::PRICE_LISTS, '1');

        $this->list();

        $this->assertNull($this->lists->effectiveListId($this->insertCustomer()));
    }

    public function testEffectiveListId_FallsBackWhenTheListOfTheCustomerIsNotActive(): void
    {
        $this->setConfigValue(SalesControlSettings::PRICE_LISTS, '1');

        $own        = $this->list('Own');
        $default    = $this->list('Default');
        $customerId = $this->insertCustomer();

        $this->lists->assignTo($customerId, $own, $this->employeeId);
        $this->lists->setStatus($own, false, $this->employeeId);

        $this->assertNull($this->lists->effectiveListId($customerId));

        $this->setConfigValue(SalesControlSettings::DEFAULT_LIST, (string) $default);

        $this->assertSame($default, $this->lists->effectiveListId($customerId));

        $this->lists->setStatus($default, false, $this->employeeId);

        $this->assertNull($this->lists->effectiveListId($customerId));
    }

    public function testEffectiveListId_TakesTheListChosenOnTheSaleOnlyWhenTheSettingLetsASaleChangeItsList(): void
    {
        $this->setConfigValue(SalesControlSettings::PRICE_LISTS, '1');

        $own        = $this->list('Own');
        $chosen     = $this->list('Chosen');
        $customerId = $this->insertCustomer();

        $this->lists->assignTo($customerId, $own, $this->employeeId);

        $this->assertSame($own, $this->lists->effectiveListId($customerId, $chosen), 'a sale may not change its list while the setting is off');

        $this->setConfigValue(SalesControlSettings::CHANGE_LIST, '1');

        $this->assertSame($chosen, $this->lists->effectiveListId($customerId, $chosen));

        $this->lists->setStatus($chosen, false, $this->employeeId);

        $this->assertSame($own, $this->lists->effectiveListId($customerId, $chosen), 'a list that is not active is not chosen');
    }

    public function testEffectiveListId_PricesFromNoListWhenTheSaleChoseNoneEvenForACustomerWithAListAndADefaultList(): void
    {
        $this->setConfigValue(SalesControlSettings::PRICE_LISTS, '1');

        $own        = $this->list('Own');
        $default    = $this->list('Default');
        $customerId = $this->insertCustomer();

        $this->lists->assignTo($customerId, $own, $this->employeeId);
        $this->setConfigValue(SalesControlSettings::DEFAULT_LIST, (string) $default);

        $this->assertSame($own, $this->lists->effectiveListId($customerId, 0), 'a sale may not change its list while the setting is off');

        $this->setConfigValue(SalesControlSettings::CHANGE_LIST, '1');

        $this->assertNull($this->lists->effectiveListId($customerId, 0));
        $this->assertSame($own, $this->lists->effectiveListId($customerId, null), 'nothing chosen is not the choice of none');
    }

    public function testAssignTo_AssignsAListWritesItAndTakesItAwayWithNoListOrZero(): void
    {
        $listId     = $this->list();
        $customerId = $this->insertCustomer();

        $this->lists->assignTo($customerId, $listId, $this->employeeId);

        $this->assertSame($listId, $this->lists->assignedTo($customerId));
        $this->assertSame(1, (int) $this->lists->getList($listId)->customers);

        $entry = $this->audit('customer_price_list_changed')[0];

        $this->assertSame('customer', $entry['entity_type']);
        $this->assertSame($customerId, (int) $entry['entity_id']);
        $this->assertSame(['before' => null, 'after' => $listId], json_decode($entry['details'], true));

        $this->lists->assignTo($customerId, 0, $this->employeeId);

        $this->assertNull($this->lists->assignedTo($customerId));

        $this->lists->assignTo($customerId, $listId, $this->employeeId);
        $this->lists->assignTo($customerId, null, $this->employeeId);

        $this->assertNull($this->lists->assignedTo($customerId));
        $this->assertCount(4, $this->audit('customer_price_list_changed'));
    }

    public function testAssignTo_WritesNothingWhenTheCustomerHasThatListAlready(): void
    {
        $listId     = $this->list();
        $customerId = $this->insertCustomer();

        $this->lists->assignTo($customerId, $listId, $this->employeeId);
        $this->lists->assignTo($customerId, $listId, $this->employeeId);
        $this->lists->assignTo($this->insertCustomer('Other', 'One'), null, $this->employeeId);

        $this->assertCount(1, $this->audit('customer_price_list_changed'));
    }

    public function testAssignTo_RefusesAListThatIsNotActiveButLetsACustomerKeepOne(): void
    {
        $listId     = $this->list();
        $customerId = $this->insertCustomer();

        $this->lists->assignTo($customerId, $listId, $this->employeeId);
        $this->lists->setStatus($listId, false, $this->employeeId);
        $this->lists->assignTo($customerId, $listId, $this->employeeId);

        $this->assertSame($listId, $this->lists->assignedTo($customerId));

        $newcomer = $this->insertCustomer('New', 'Comer');

        $this->assertSame(AccountingError::PriceListInactive, $this->refused(fn () => $this->lists->assignTo($newcomer, $listId, $this->employeeId))->error);
        $this->assertNull($this->lists->assignedTo($newcomer));
    }

    public function testAssignTo_RejectsAListThatDoesNotExist(): void
    {
        $this->assertSame(AccountingError::PriceListNotFound, $this->refused(fn () => $this->lists->assignTo($this->insertCustomer(), 987654, $this->employeeId))->error);
    }

    public function testCheckAssignable_RefusesAListThatIsGoneOrNotActiveAndAcceptsNoneTheListTheCustomerHasAndAnActiveOne(): void
    {
        $active     = $this->list('Active');
        $retired    = $this->list('Retired');
        $customerId = $this->insertCustomer();

        $this->lists->assignTo($customerId, $retired, $this->employeeId);
        $this->lists->setStatus($retired, false, $this->employeeId);

        $this->lists->checkAssignable($customerId, null);
        $this->lists->checkAssignable($customerId, 0);
        $this->lists->checkAssignable($customerId, $retired);
        $this->lists->checkAssignable($customerId, $active);

        $newcomer = $this->insertCustomer('New', 'Comer');

        $this->assertSame(AccountingError::PriceListInactive, $this->refused(fn () => $this->lists->checkAssignable($newcomer, $retired))->error);
        $this->assertSame(AccountingError::PriceListNotFound, $this->refused(fn () => $this->lists->checkAssignable($newcomer, 987654))->error);
        $this->assertSame(AccountingError::PriceListNotFound, $this->refused(fn () => $this->lists->checkAssignable(NEW_ENTRY, 987654))->error);
        $this->assertNull($this->lists->assignedTo($newcomer));
        $this->assertCount(1, $this->audit('customer_price_list_changed'));
    }

    public function testSearch_CountsTheCustomersAndTheItemsOfEachListAndFindsByName(): void
    {
        $wholesale  = $this->list('Wholesale');
        $this->list('Retail');
        $customerId = $this->insertCustomer();
        $item       = $this->createTestItem();

        $this->lists->assignTo($customerId, $wholesale, $this->employeeId);
        $this->lists->saveItemPrices($item, [$wholesale => '4'], $this->employeeId);

        $all = $this->lists->search('', 'name', 'asc', 25, 0);

        $this->assertSame(2, $all['total']);
        $this->assertSame(['Retail', 'Wholesale'], array_map(static fn (object $row): string => $row->name, $all['rows']));
        $this->assertSame([0, 1], array_map(static fn (object $row): int => (int) $row->customers, $all['rows']));
        $this->assertSame([0, 1], array_map(static fn (object $row): int => (int) $row->items, $all['rows']));

        $found = $this->lists->search('whole', 'name', 'desc', 25, 0);

        $this->assertSame(1, $found['total']);
        $this->assertSame('Wholesale', $found['rows'][0]->name);

        $paged = $this->lists->search('', 'customers', 'desc', 1, 0);

        $this->assertSame(2, $paged['total']);
        $this->assertSame('Wholesale', $paged['rows'][0]->name);
    }

    public function testAllLists_CanBeLimitedToTheActiveOnes(): void
    {
        $this->list('Active one');
        $this->list('Retired one', false);

        $this->assertSame(['Active one', 'Retired one'], array_map(static fn (object $row): string => $row->name, $this->lists->allLists()));
        $this->assertSame(['Active one'], array_map(static fn (object $row): string => $row->name, $this->lists->allLists(true)));
    }
}
