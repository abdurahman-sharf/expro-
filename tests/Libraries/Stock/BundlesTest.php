<?php

namespace Tests\Libraries\Stock;

use App\Libraries\Accounting\AccountingError;
use App\Libraries\Accounting\AccountingException;
use App\Libraries\Stock\Bundles;
use App\Libraries\Stock\StockOptions;
use App\Models\Item_kit;
use App\Models\Item_kit_items;
use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\DatabaseTestTrait;
use Config\Database;
use Tests\Support\AccountingFixtureTrait;
use Tests\Support\EmployeeFixtureTrait;
use Tests\Support\ItemFixtureTrait;
use Tests\Support\StockUnitsFixtureTrait;

/**
 * @internal
 */
final class BundlesTest extends CIUnitTestCase
{
    use DatabaseTestTrait;
    use AccountingFixtureTrait;
    use EmployeeFixtureTrait;
    use ItemFixtureTrait;
    use StockUnitsFixtureTrait;

    protected $migrate     = true;
    protected $migrateOnce = true;
    protected $refresh     = false;
    protected $namespace;
    private Bundles $bundles;

    public static function setUpBeforeClass(): void
    {
        $seeder = Database::seeder('tests');
        $seeder->call('TestDatabaseBootstrapSeeder');
    }

    protected function setUp(): void
    {
        parent::setUp();

        $this->startStockUnits();
        $this->bundles = new Bundles();
    }

    protected function tearDown(): void
    {
        $db = $this->accountingDb();

        $db->query('SET FOREIGN_KEY_CHECKS = 0');
        $db->table('item_kit_items')->truncate();
        $db->table('item_kits')->truncate();
        $db->query('SET FOREIGN_KEY_CHECKS = 1');

        $this->stopStockUnits();
        $this->removeInsertedEntities();

        parent::tearDown();
    }

    private function pricedProduct(bool $keepsStock = false, int $type = ITEM_KIT): int
    {
        $itemId = $this->createTestItem($keepsStock ? HAS_STOCK : HAS_NO_STOCK);

        $this->accountingDb()->table('items')->where('item_id', $itemId)->update(['item_type' => $type]);

        return $itemId;
    }

    /**
     * @param list<int> $components
     */
    private function kit(string $name, ?int $pricedItemId, array $components, int $priceOption = PRICE_OPTION_KIT): int
    {
        $data = ['name' => $name, 'item_id' => $pricedItemId, 'kit_discount' => 0, 'kit_discount_type' => 0, 'price_option' => $priceOption, 'print_option' => 0, 'description' => ''];

        $this->assertTrue(model(Item_kit::class)->save_value($data));

        $rows = array_map(static fn (int $itemId, int $index): array => ['item_id' => $itemId, 'quantity' => 1, 'kit_sequence' => $index], $components, array_keys($components));

        $this->assertTrue(model(Item_kit_items::class)->save_value($rows, (int) $data['item_kit_id']));

        return (int) $data['item_kit_id'];
    }

    private function refusedWith(AccountingError $error, callable $action): void
    {
        try {
            $action();
            $this->fail('The bundle should have been refused with ' . $error->value . '.');
        } catch (AccountingException $exception) {
            $this->assertSame($error, $exception->error);
        }
    }

    public function testAssertValidAcceptsAKitOfOrdinaryComponentsWhoseProductKeepsNoStockAndCarriesThePrice(): void
    {
        $this->switchOn('bundles');

        $this->bundles->assertValid($this->pricedProduct(), [$this->createTestItem(), $this->createTestItem()], PRICE_OPTION_KIT);
        $this->addToAssertionCount(1);
    }

    public function testAssertValidRejectsABundleThatContainsItself(): void
    {
        $this->switchOn('bundles');

        $priced = $this->pricedProduct();

        $this->refusedWith(AccountingError::BundleContainsItself, fn () => $this->bundles->assertValid($priced, [$this->createTestItem(), $priced], PRICE_OPTION_KIT));
    }

    public function testAssertValidRejectsAComponentThatIsTheProductOfAnotherBundle(): void
    {
        $this->switchOn('bundles');

        $inner = $this->pricedProduct();

        $this->kit('Inner', $inner, [$this->createTestItem()]);

        $this->refusedWith(AccountingError::BundleContainsBundle, fn () => $this->bundles->assertValid($this->pricedProduct(), [$inner], PRICE_OPTION_KIT));
    }

    public function testAssertValidRejectsAComponentOfTheKitType(): void
    {
        $this->switchOn('bundles');

        $this->refusedWith(AccountingError::BundleContainsBundle, fn () => $this->bundles->assertValid($this->pricedProduct(), [$this->pricedProduct()], PRICE_OPTION_KIT));
    }

    public function testAssertValidRejectsAProductThatKeepsItsOwnStock(): void
    {
        $this->switchOn('bundles');

        $this->refusedWith(AccountingError::BundleKeepsStock, fn () => $this->bundles->assertValid($this->pricedProduct(true), [$this->createTestItem()], PRICE_OPTION_KIT));
    }

    public function testAssertValidRejectsAKitWithNoPricedProductOrWithAPriceOptionOtherThanTheOneOfTheKit(): void
    {
        $this->switchOn('bundles');

        $this->refusedWith(AccountingError::BundleShapeInvalid, fn () => $this->bundles->assertValid(null, [$this->createTestItem()], PRICE_OPTION_KIT));
        $this->refusedWith(AccountingError::BundleShapeInvalid, fn () => $this->bundles->assertValid($this->pricedProduct(), [$this->createTestItem()], PRICE_OPTION_ALL));
        $this->refusedWith(AccountingError::BundleShapeInvalid, fn () => $this->bundles->assertValid($this->createTestItem(HAS_NO_STOCK), [$this->createTestItem()], PRICE_OPTION_KIT));
    }

    public function testAssertValidAsksNothingOfAKitWhileTheBundlesSettingIsOff(): void
    {
        $priced = $this->pricedProduct(true);

        $this->bundles->assertValid($priced, [$priced], PRICE_OPTION_ALL);
        $this->addToAssertionCount(1);
    }

    public function testViolationsListsTheKitsThatBreakTheRulesOfABundle(): void
    {
        $good = $this->kit('Good', $this->pricedProduct(), [$this->createTestItem()]);
        $bad  = $this->kit('Bad', $this->pricedProduct(true), [$this->createTestItem()]);

        $this->switchOn('bundles');

        $found = $this->bundles->violations();

        $this->assertSame([$bad], array_column($found, 'kit_id'));
        $this->assertNotContains($good, array_column($found, 'kit_id'));
        $this->assertSame(AccountingError::BundleKeepsStock->value, $found[0]['problem']);
    }

    public function testSaveValueOfTheKitItemsRefusesANestedBundleAndKeepsWhatWasSaved(): void
    {
        $inner = $this->pricedProduct();
        $outer = $this->pricedProduct();
        $part  = $this->createTestItem();

        $this->kit('Inner', $inner, [$part]);
        $kitId = $this->kit('Outer', $outer, [$part]);

        $this->switchOn('bundles');

        $model = model(Item_kit_items::class);
        $rows  = [['item_id' => $inner, 'quantity' => 1, 'kit_sequence' => 0]];

        $this->assertFalse($model->save_value($rows, $kitId));
        $this->assertSame(AccountingError::BundleContainsBundle, $model->bundleRefusal()?->error);
        $this->assertSame([$part], array_map('intval', array_column($model->get_info($kitId), 'item_id')));
    }

    public function testSaveValueOfTheKitItemsKeepsSavingKitsAsBeforeWhileTheSettingIsOff(): void
    {
        $inner = $this->pricedProduct();
        $outer = $this->kit('Outer', $this->pricedProduct(true), [$this->createTestItem()], PRICE_OPTION_ALL);
        $model = model(Item_kit_items::class);
        $rows  = [['item_id' => $inner, 'quantity' => 1, 'kit_sequence' => 0]];

        $this->assertTrue($model->save_value($rows, $outer));
        $this->assertNull($model->bundleRefusal());
    }

    public function testOptionsBundlesAreOffUntilSomeoneSwitchesThemOn(): void
    {
        $this->assertFalse((new StockOptions())->bundlesEnabled());
    }
}
