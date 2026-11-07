<?php

namespace Tests\Libraries\MasterData;

use App\Libraries\Accounting\AccountingError;
use App\Libraries\Accounting\AccountingException;
use App\Libraries\MasterData\ItemFields;
use App\Libraries\MasterData\ItemStatus;
use App\Libraries\MasterData\LookupKind;
use App\Libraries\MasterData\NamedLookup;
use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\DatabaseTestTrait;
use Config\Database;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Support\AccountingFixtureTrait;
use Tests\Support\ItemFixtureTrait;

/**
 * @internal
 */
final class ItemFieldsTest extends CIUnitTestCase
{
    use DatabaseTestTrait;
    use AccountingFixtureTrait;
    use ItemFixtureTrait;

    protected $migrate     = true;
    protected $migrateOnce = true;
    protected $refresh     = false;
    protected $namespace;

    /** @var list<int> */
    private array $itemIds = [];

    public static function setUpBeforeClass(): void
    {
        $seeder = Database::seeder('tests');
        $seeder->call('TestDatabaseBootstrapSeeder');
    }

    protected function tearDown(): void
    {
        $db = $this->accountingDb();

        foreach ($this->itemIds as $itemId) {
            $db->table('item_quantities')->where('item_id', $itemId)->delete();
            $db->table('items')->where('item_id', $itemId)->delete();
        }

        $this->itemIds = [];

        $db->query('DELETE FROM ' . $db->prefixTable('item_brands'));
        $db->table('accounting_audit_log')->where('action', 'item_master_data_changed')->delete();

        parent::tearDown();
    }

    private function refusalOf(callable $action): ?AccountingError
    {
        try {
            $action();
        } catch (AccountingException $failure) {
            return $failure->error;
        }

        return null;
    }

    public function testRead_ReturnsTheColumnsOfEveryFieldThatWasGiven(): void
    {
        $brand = (new NamedLookup(LookupKind::ItemBrand))->save('Acme', true, null, 1);

        $this->assertSame(['brand_id' => $brand, 'status' => 2, 'supplier_code' => 'S-1'], (new ItemFields())->read(['brand_id' => (string) $brand, 'status' => '2', 'supplier_code' => ' S-1 '], null));
    }

    public function testRead_ClearsEveryFieldTheFormLeftEmptyAndReadsNoStatusAsActive(): void
    {
        $this->assertSame(['brand_id' => null, 'status' => 0, 'supplier_code' => null], (new ItemFields())->read([], null));
        $this->assertSame(['brand_id' => null, 'status' => 0, 'supplier_code' => null], (new ItemFields())->read(['brand_id' => '0', 'status' => '', 'supplier_code' => ''], null));
    }

    public function testRead_ReturnsOnlyTheFieldsThatWereGivenWhenItIsPartial(): void
    {
        $this->assertSame(['status' => 1], (new ItemFields())->read(['status' => 'inactive'], null, true));
        $this->assertSame([], (new ItemFields())->read([], null, true));
    }

    /**
     * @return array<string, array{0: string, 1: ItemStatus}>
     */
    public static function statusWordProvider(): array
    {
        return [
            'number 0' => ['0', ItemStatus::Active], 'number 1' => ['1', ItemStatus::Inactive], 'number 2' => ['2', ItemStatus::Suspended],
            'word active' => ['active', ItemStatus::Active], 'word inactive' => [' Inactive ', ItemStatus::Inactive], 'word suspended' => ['SUSPENDED', ItemStatus::Suspended],
        ];
    }

    #[DataProvider('statusWordProvider')]
    public function testStatusOf_ReadsTheThreeStatusesAsNumbersAndAsWords(string $typed, ItemStatus $expected): void
    {
        $this->assertSame($expected, ItemFields::statusOf($typed));
    }

    public function testStatusOf_RejectsAnythingElse(): void
    {
        foreach (['3', '-1', 'frozen', '1.0', 'on'] as $typed) {
            $this->assertSame(AccountingError::ItemStatusInvalid, $this->refusalOf(fn () => ItemFields::statusOf($typed)), $typed);
        }
    }

    public function testStatus_HidesAnItemFromNewDocumentsWhenItIsInactiveOrSuspendedAndNeverWhenActive(): void
    {
        $this->assertFalse(ItemStatus::Active->isHidden());
        $this->assertTrue(ItemStatus::Inactive->isHidden());
        $this->assertTrue(ItemStatus::Suspended->isHidden());
        $this->assertSame([0, 1, 2], array_keys(ItemStatus::options()));
    }

    public function testRead_RejectsABrandThatDoesNotExistOrIsInactiveButAcceptsTheOneTheItemHolds(): void
    {
        $lookup   = new NamedLookup(LookupKind::ItemBrand);
        $inactive = $lookup->save('Old', false, null, 1);

        $this->assertSame(AccountingError::ItemBrandInvalid, $this->refusalOf(fn () => (new ItemFields())->read(['brand_id' => '987654'], null)));
        $this->assertSame(AccountingError::ItemBrandInvalid, $this->refusalOf(fn () => (new ItemFields())->read(['brand_id' => (string) $inactive], null)));
        $this->assertSame(AccountingError::ItemBrandInvalid, $this->refusalOf(fn () => (new ItemFields())->read(['brand_id' => 'x'], null)));
        $this->assertSame($inactive, (new ItemFields())->read(['brand_id' => (string) $inactive], (object) ['brand_id' => $inactive])['brand_id']);
    }

    public function testRead_AcceptsASupplierCodeOfOneHundredCharactersAndRejectsOneMore(): void
    {
        $this->assertSame(100, mb_strlen((string) (new ItemFields())->read(['supplier_code' => str_repeat('ب', 100)], null)['supplier_code']));
        $this->assertSame(AccountingError::ItemFieldTooLong, $this->refusalOf(fn () => (new ItemFields())->read(['supplier_code' => str_repeat('ب', 101)], null)));
    }

    public function testAudit_WritesWhatChangedAndNothingWhenNothingDid(): void
    {
        $itemId = $this->itemIds[] = $this->createTestItem();
        $fields = new ItemFields();

        $fields->audit($itemId, (object) ['brand_id' => null, 'status' => 0, 'supplier_code' => 'A'], ['brand_id' => null, 'status' => 2, 'supplier_code' => 'A'], 9);
        $fields->audit($itemId, (object) ['brand_id' => null, 'status' => 0, 'supplier_code' => null], ['brand_id' => null, 'status' => 0, 'supplier_code' => null], 9);

        $rows = $this->accountingDb()->table('accounting_audit_log')->where(['entity_type' => 'item', 'entity_id' => $itemId, 'action' => 'item_master_data_changed'])->get()->getResult();

        $this->assertCount(1, $rows);
        $this->assertSame(9, (int) $rows[0]->employee_id);
        $this->assertSame(['status' => ['from' => 0, 'to' => 2]], json_decode((string) $rows[0]->details, true)['changes']);
    }

    public function testAudit_TreatsABrandOfZeroAsNoBrandAndANewItemAsStartingFromTheNeutralValues(): void
    {
        $itemId = $this->itemIds[] = $this->createTestItem();
        $fields = new ItemFields();

        $fields->audit($itemId, (object) ['brand_id' => 0, 'status' => 0, 'supplier_code' => null], ['brand_id' => null, 'status' => 0, 'supplier_code' => null], 9);
        $this->assertSame(0, $this->accountingDb()->table('accounting_audit_log')->where(['entity_type' => 'item', 'entity_id' => $itemId])->countAllResults());

        $fields->audit($itemId, null, ['brand_id' => null, 'status' => 1, 'supplier_code' => 'N'], 9);

        $this->assertSame(['status', 'supplier_code'], array_keys(json_decode((string) $this->accountingDb()->table('accounting_audit_log')->where(['entity_type' => 'item', 'entity_id' => $itemId])->get()->getRow()->details, true)['changes']));
    }
}
