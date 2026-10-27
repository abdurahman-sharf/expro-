<?php

namespace Tests\Controllers;

use App\Controllers\Items;
use App\Libraries\Organization\BranchGuard;
use CodeIgniter\Database\Config;
use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\DatabaseTestTrait;
use CodeIgniter\Test\FeatureTestTrait;
use App\Models\Item;
use App\Models\Item_quantity;
use App\Models\Inventory;
use App\Models\Item_taxes;
use App\Models\Attribute;
use App\Models\Stock_location;
use App\Models\Supplier;
use ReflectionClass;
use ReflectionMethod;
use Tests\Support\AccountingFixtureTrait;
use Tests\Support\EmployeeFixtureTrait;
use Tests\Support\ItemFixtureTrait;
use Tests\Support\PurchaseAccountingFixtureTrait;
use Tests\Support\SaleAccountingFixtureTrait;

class ItemsControllerTest extends CIUnitTestCase
{
    use DatabaseTestTrait;
    use FeatureTestTrait;
    use AccountingFixtureTrait;
    use EmployeeFixtureTrait;
    use ItemFixtureTrait;
    use SaleAccountingFixtureTrait;
    use PurchaseAccountingFixtureTrait;

    protected $migrate = true;
    protected $migrateOnce = true;
    protected $seedOnce = true;
    protected $refresh = false;
    protected $namespace = null;

    private static $doneBootstrap = false;

    protected $item;
    protected $item_quantity;
    protected $inventory;
    protected $item_taxes;
    protected $attribute;
    protected $stock_location;
    protected $supplier;

    protected function setUp(): void
    {
        if (self::$doneBootstrap === false) {
            Config::seeder($this->DBGroup)->call('App\Database\Seeds\TestDatabaseBootstrapSeeder');
            Config::connect($this->DBGroup)->close();

            self::$doneBootstrap = true;
        }

        parent::setUp();

        helper('importfile');
        helper('attribute');

        $this->item = model(Item::class);
        $this->item_quantity = model(Item_quantity::class);
        $this->inventory = model(Inventory::class);
        $this->item_taxes = model(Item_taxes::class);
        $this->attribute = model(Attribute::class);
        $this->stock_location = model(Stock_location::class);
        $this->supplier = model(Supplier::class);
    }

    protected function tearDown(): void
    {
        $this->stopBuying();

        parent::tearDown();
    }

    /**
     * An employee of the items screens, who works in every branch unless some are named.
     *
     * @param list<int>|null $branchIds
     */
    protected function createItemsEmployee(?array $branchIds = null): int
    {
        $personId = $this->createEmployee();

        $grants = [
            ['person_id' => $personId, 'permission_id' => 'items', 'menu_group' => 'home'],
            ['person_id' => $personId, 'permission_id' => 'items_stock', 'menu_group' => 'home'],
        ];

        if ($branchIds === null) {
            $grants[] = ['person_id' => $personId, 'permission_id' => BranchGuard::ALL_BRANCHES, 'menu_group' => '--'];
        }

        $db = Config::connect($this->DBGroup);
        $db->table('grants')->insertBatch($grants);

        foreach ($branchIds ?? [] as $index => $branchId) {
            $db->table('employee_branches')->insert(['person_id' => $personId, 'branch_id' => $branchId, 'is_default' => $index === 0 ? 1 : 0]);
        }

        return $personId;
    }

    protected function loginAsItemsEmployee(int $personId): void
    {
        $this->withSession([
            'person_id'  => $personId,
            'menu_group' => 'home',
        ]);
    }

    protected function baseItemPostData(): array
    {
        return [
            'name'          => 'Test Item ' . uniqid(),
            'category'      => 'Test Category',
            'description'   => 'Test Item',
            'cost_price'    => '1.00',
            'unit_price'    => '5.00',
            'reorder_level' => '0',
            'receiving_quantity' => '0',
            'quantity_1'    => '0',
            'item_type'     => (string) ITEM,
            'tax_percents'  => ['5'],
        ];
    }

    /**
     * Regression test: `tax_names[]` containing `<`/`>`
     * (the stored-XSS vector) must be rejected by postSave.
     */
    public function testPostSaveRejectsMaliciousTaxName(): void
    {
        $employeeId = $this->createItemsEmployee();
        $this->loginAsItemsEmployee($employeeId);

        $postData = $this->baseItemPostData();
        $postData['tax_names'] = ['<svg onload=alert(1)>'];

        $response = $this->post('/items/save', $postData);

        $response->assertStatus(200);
        $result = json_decode($response->getJSON(), true);
        $this->assertFalse($result['success']);
    }

    /**
     * Legitimate unicode tax names must not be rejected by the XSS guard.
     */
    public function testPostSaveAcceptsUnicodeTaxName(): void
    {
        $employeeId = $this->createItemsEmployee();
        $this->loginAsItemsEmployee($employeeId);

        $postData = $this->baseItemPostData();
        $postData['tax_names'] = ["Impôt, incl."];

        $response = $this->post('/items/save', $postData);

        $response->assertStatus(200);
        $result = json_decode($response->getJSON(), true);
        $this->assertTrue($result['success']);
    }

    /**
     * Apostrophes are common in real tax names (e.g. possessives) and must not be rejected.
     */
    public function testPostSaveAcceptsApostropheInTaxName(): void
    {
        $employeeId = $this->createItemsEmployee();
        $this->loginAsItemsEmployee($employeeId);

        $postData = $this->baseItemPostData();
        $postData['tax_names'] = ["O'Brien's Tax"];

        $response = $this->post('/items/save', $postData);

        $response->assertStatus(200);
        $result = json_decode($response->getJSON(), true);
        $this->assertTrue($result['success']);
    }

    public function testPostSave_KeepsTheCostOfAnItemThatIsPostedAsItWasShown(): void
    {
        $employeeId = $this->createItemsEmployee();
        $this->loginAsItemsEmployee($employeeId);

        $itemId = $this->createTestItem();
        $this->accountingDb()->table('items')->where('item_id', $itemId)->update(['cost_price' => '71.428571']);

        $postData               = $this->baseItemPostData();
        $postData['cost_price'] = '71.43';
        $postData['tax_names']  = ['VAT'];

        $result = json_decode($this->post('/items/save/' . $itemId, $postData)->getJSON(), true);

        $this->assertTrue($result['success']);
        $this->assertSame('71.428571', (string) $this->accountingDb()->table('items')->where('item_id', $itemId)->get()->getRow()->cost_price);
    }

    public function testPostSave_TakesACostThatWasChanged(): void
    {
        $employeeId = $this->createItemsEmployee();
        $this->loginAsItemsEmployee($employeeId);

        $itemId = $this->createTestItem();
        $this->accountingDb()->table('items')->where('item_id', $itemId)->update(['cost_price' => '71.428571']);

        $postData               = $this->baseItemPostData();
        $postData['cost_price'] = '80.00';
        $postData['tax_names']  = ['VAT'];

        $result = json_decode($this->post('/items/save/' . $itemId, $postData)->getJSON(), true);

        $this->assertTrue($result['success']);
        $this->assertSame('80.000000', (string) $this->accountingDb()->table('items')->where('item_id', $itemId)->get()->getRow()->cost_price);
    }

    public function testPostBulkUpdateRejectsMaliciousTaxName(): void
    {
        $employeeId = $this->createItemsEmployee();
        $this->loginAsItemsEmployee($employeeId);

        $response = $this->post('/items/bulkupdate', [
            'item_ids'     => '1',
            'tax_names'    => ['<svg onload=alert(1)>'],
            'tax_percents' => ['5'],
        ]);

        $response->assertStatus(200);
        $result = json_decode($response->getJSON(), true);
        $this->assertFalse($result['success']);
    }

    public function testPostBulkUpdateAcceptsUnicodeTaxName(): void
    {
        $employeeId = $this->createItemsEmployee();
        $this->loginAsItemsEmployee($employeeId);

        $response = $this->post('/items/bulkupdate', [
            'item_ids'     => '1',
            'tax_names'    => ["Impôt, incl."],
            'tax_percents' => ['5'],
        ]);

        $response->assertStatus(200);
        $result = json_decode($response->getJSON(), true);
        $this->assertTrue($result['success']);
    }

    /**
     * Regression test: a negative tax percent must be
     * rejected by postSave so it can never be persisted to items_taxes.
     */
    public function testPostSave_RejectsNegativeTaxPercent(): void
    {
        $employeeId = $this->createItemsEmployee();
        $this->loginAsItemsEmployee($employeeId);

        $postData = $this->baseItemPostData();
        $postData['tax_names'] = ['VAT'];
        $postData['tax_percents'] = ['-200'];

        $response = $this->post('/items/save', $postData);

        $response->assertStatus(200);
        $result = json_decode($response->getJSON(), true);
        $this->assertFalse($result['success']);
    }

    /**
     * Boundary: a zero tax percent is valid (>= 0) and must not be rejected.
     */
    public function testPostSave_AcceptsZeroTaxPercent(): void
    {
        $employeeId = $this->createItemsEmployee();
        $this->loginAsItemsEmployee($employeeId);

        $postData = $this->baseItemPostData();
        $postData['tax_names'] = ['VAT'];
        $postData['tax_percents'] = ['0'];

        $response = $this->post('/items/save', $postData);

        $response->assertStatus(200);
        $result = json_decode($response->getJSON(), true);
        $this->assertTrue($result['success']);
    }

    /**
     * Regression test: a negative tax percent must be
     * rejected by postBulkUpdate so it can never be persisted.
     */
    public function testPostBulkUpdate_RejectsNegativeTaxPercent(): void
    {
        $employeeId = $this->createItemsEmployee();
        $this->loginAsItemsEmployee($employeeId);

        $response = $this->post('/items/bulkupdate', [
            'item_ids'     => '1',
            'tax_names'    => ['VAT'],
            'tax_percents' => ['-50'],
        ]);

        $response->assertStatus(200);
        $result = json_decode($response->getJSON(), true);
        $this->assertFalse($result['success']);
    }

    /**
     * Regression test: a CSV row with a negative Tax N Percent must be
     * rejected by save_tax_data (not silently dropped), so postImportCsvFile
     * fails the row instead of importing the item without its tax.
     */
    public function testSaveTaxData_RejectsNegativePercent(): void
    {
        $controller = (new \ReflectionClass(\App\Controllers\Items::class))->newInstanceWithoutConstructor();
        $method = new \ReflectionMethod($controller, 'save_tax_data');
        $method->setAccessible(true);

        $row = [
            'Tax 1 Name'    => 'VAT',
            'Tax 1 Percent' => '-5',
            'Tax 2 Name'    => '',
            'Tax 2 Percent' => '',
        ];

        $this->assertFalse($method->invoke($controller, $row, ['item_id' => 1]));
    }

    /**
     * Regression test: an attribute definition whose
     * `definition_name` contains HTML must be entity-escaped when rendered in the
     * items attributes dropdown, not emitted as a live (executable) tag.
     */
    public function testGetAttributesEscapesMaliciousDefinitionName(): void
    {
        $employeeId = $this->createItemsEmployee();
        $this->loginAsItemsEmployee($employeeId);

        $payload = '<img src=x onerror=alert(1)>';

        $definitionData = [
            'definition_name' => $payload,
            'definition_type' => TEXT,
            'definition_flags' => 0,
            'deleted'         => 0,
        ];
        $this->assertTrue($this->attribute->saveDefinition($definitionData));
        $this->assertNotEmpty($definitionData['definition_id']);

        $response = $this->get('/items/attributes/1');
        $output = (string) $response->getBody();

        // A live, unescaped tag in the dropdown label is the stored-XSS sink.
        $this->assertStringNotContainsString($payload, $output);
        // The payload must be present only in entity-escaped form.
        $this->assertStringContainsString('&lt;img src=x onerror=alert(1)&gt;', $output);
    }

    public function testGenerateCsvHeaderBasic(): void
    {
        $stockLocations = ['Warehouse'];
        $attributes = [];

        $csv = generate_import_items_csv($stockLocations, $attributes);

        $this->assertStringContainsString('Id,Barcode,"Item Name"', $csv);
        $this->assertStringContainsString('Category,"Supplier ID"', $csv);
        $this->assertStringContainsString('"Cost Price","Unit Price"', $csv);
        $this->assertStringContainsString('"Tax 1 Name","Tax 1 Percent"', $csv);
        $this->assertStringContainsString('"Tax 2 Name","Tax 2 Percent"', $csv);
        $this->assertStringContainsString('"Reorder Level"', $csv);
        $this->assertStringContainsString('Description,"Allow Alt Description"', $csv);
        $this->assertStringContainsString('"Item has Serial Number"', $csv);
        $this->assertStringContainsString('Image,HSN', $csv);
        $this->assertStringContainsString('"location_Warehouse"', $csv);
        $this->assertStringContainsString("\xEF\xBB\xBF", $csv);
    }

    public function testGenerateCsvHeaderMultipleLocations(): void
    {
        $stockLocations = ['Warehouse', 'Store', 'Backroom'];
        $attributes = [];

        $csv = generate_import_items_csv($stockLocations, $attributes);

        $this->assertStringContainsString('"location_Warehouse"', $csv);
        $this->assertStringContainsString('"location_Store"', $csv);
        $this->assertStringContainsString('"location_Backroom"', $csv);
    }

    public function testGenerateCsvHeaderWithAttributes(): void
    {
        $stockLocations = ['Warehouse'];
        $attributes = ['Color', 'Size', 'Weight'];

        $csv = generate_import_items_csv($stockLocations, $attributes);

        $this->assertStringContainsString('"attribute_Color"', $csv);
        $this->assertStringContainsString('"attribute_Size"', $csv);
        $this->assertStringContainsString('"attribute_Weight"', $csv);
    }

    public function testGenerateStockLocationHeaders(): void
    {
        $locations = ['Warehouse', 'Store'];

        $headers = generate_stock_location_headers($locations);

        $this->assertEquals(',"location_Warehouse","location_Store"', $headers);
    }

    public function testGenerateAttributeHeaders(): void
    {
        $attributes = ['Color', 'Size'];

        $headers = generate_attribute_headers($attributes);

        $this->assertEquals(',"attribute_Color","attribute_Size"', $headers);
    }

    public function testGenerateAttributeHeadersRemovesNegativeOneIndex(): void
    {
        $attributes = [-1 => 'None', 'Color' => 'Color'];
        unset($attributes[-1]);

        $headers = generate_attribute_headers($attributes);

        $this->assertStringContainsString('"attribute_Color"', $headers);
    }

    public function testGetCsvFileBasic(): void
    {
        $csvContent = "Id,Barcode,\"Item Name\",Category,\"Supplier ID\",\"Cost Price\",\"Unit Price\",\"Tax 1 Name\",\"Tax 1 Percent\",\"Tax 2 Name\",\"Tax 2 Percent\",\"Reorder Level\",Description,\"Allow Alt Description\",\"Item has Serial Number\",Image,HSN\n";
        $csvContent .= ",ITEM001,Test Item,Electronics,1,10.00,15.00,,,,,5,Test Description,0,0,,HSN001\n";

        $tempFile = tempnam(sys_get_temp_dir(), 'csv_test_');
        file_put_contents($tempFile, $csvContent);

        $rows = get_csv_file($tempFile);

        $this->assertCount(1, $rows);
        $this->assertEquals('', $rows[0]['Id']);
        $this->assertEquals('ITEM001', $rows[0]['Barcode']);
        $this->assertEquals('Test Item', $rows[0]['Item Name']);
        $this->assertEquals('Electronics', $rows[0]['Category']);

        unlink($tempFile);
    }

    public function testGetCsvFileWithBom(): void
    {
        $bom = pack('CCC', 0xef, 0xbb, 0xbf);
        $csvContent = $bom . "Id,\"Item Name\",Category\n";
        $csvContent .= "1,Test Item,Electronics\n";

        $tempFile = tempnam(sys_get_temp_dir(), 'csv_test_bom_');
        file_put_contents($tempFile, $csvContent);

        $rows = get_csv_file($tempFile);

        $this->assertCount(1, $rows);
        $this->assertEquals('1', $rows[0]['Id']);
        $this->assertEquals('Test Item', $rows[0]['Item Name']);

        unlink($tempFile);
    }

    public function testGetCsvFileMultipleRows(): void
    {
        $csvContent = "Id,\"Item Name\",Category\n";
        $csvContent .= "1,Item One,Cat A\n";
        $csvContent .= "2,Item Two,Cat B\n";
        $csvContent .= "3,Item Three,Cat C\n";

        $tempFile = tempnam(sys_get_temp_dir(), 'csv_test_multi_');
        file_put_contents($tempFile, $csvContent);

        $rows = get_csv_file($tempFile);

        $this->assertCount(3, $rows);
        $this->assertEquals('Item One', $rows[0]['Item Name']);
        $this->assertEquals('Item Two', $rows[1]['Item Name']);
        $this->assertEquals('Item Three', $rows[2]['Item Name']);

        unlink($tempFile);
    }

    public function testRequiredHeadersDetectedForTemplate(): void
    {
        $csvContent = 'Id,Barcode,"Item Name",Category,"Supplier ID","Cost Price","Unit Price","Tax 1 Name","Tax 1 Percent","Tax 2 Name","Tax 2 Percent","Reorder Level",Description,"Allow Alt Description","Item has Serial Number",Image,HSN' . "\n";
        $csvContent .= ",ITEM001,Test Item,Electronics,1,10.00,15.00,,,,,5,Test Description,0,0,,HSN001\n";

        $tempFile = tempnam(sys_get_temp_dir(), 'csv_test_headers_ok_');
        file_put_contents($tempFile, $csvContent);

        $rows = get_csv_file($tempFile);

        $this->assertTrue(csvImportHasRequiredItemHeaders($rows, [], []));

        unlink($tempFile);
    }

    public function testMissingIdHeaderIsRejected(): void
    {
        $csvContent = "Barcode,\"Item Name\",Category\n";
        $csvContent .= "ITEM001,Test Item,Electronics\n";

        $tempFile = tempnam(sys_get_temp_dir(), 'csv_test_headers_bad_');
        file_put_contents($tempFile, $csvContent);

        $rows = get_csv_file($tempFile);

        $this->assertFalse(csvImportHasRequiredItemHeaders($rows, [], []));

        unlink($tempFile);
    }

    public function testMissingSupplierIdHeaderIsRejected(): void
    {
        $csvContent = 'Id,Barcode,"Item Name",Category,"Cost Price","Unit Price","Tax 1 Name","Tax 1 Percent","Tax 2 Name","Tax 2 Percent","Reorder Level",Description,"Allow Alt Description","Item has Serial Number",Image,HSN' . "\n";
        $csvContent .= ",ITEM001,Test Item,Electronics,10.00,15.00,,,,,5,Test Description,0,0,,HSN001\n";

        $tempFile = tempnam(sys_get_temp_dir(), 'csv_test_headers_no_supplier_');
        file_put_contents($tempFile, $csvContent);

        $rows = get_csv_file($tempFile);

        $this->assertFalse(csvImportHasRequiredItemHeaders($rows, [], []));

        unlink($tempFile);
    }

    public function testMissingAttributeColumnIsRejected(): void
    {
        $csvContent = 'Id,Barcode,"Item Name",Category,"Supplier ID","Cost Price","Unit Price","Tax 1 Name","Tax 1 Percent","Tax 2 Name","Tax 2 Percent","Reorder Level",Description,"Allow Alt Description","Item has Serial Number",Image,HSN,"location_Warehouse"' . "\n";
        $csvContent .= ",ITEM001,Test Item,Electronics,1,10.00,15.00,,,,,5,Test Description,0,0,,HSN001,100\n";

        $tempFile = tempnam(sys_get_temp_dir(), 'csv_test_headers_no_attribute_');
        file_put_contents($tempFile, $csvContent);

        $rows = get_csv_file($tempFile);

        $this->assertFalse(csvImportHasRequiredItemHeaders($rows, ['Warehouse'], ['Color']));

        unlink($tempFile);
    }

    public function testEmptyCsvHasNoRequiredHeaders(): void
    {
        $this->assertFalse(csvImportHasRequiredItemHeaders([], [], []));
    }

    public function testBomExists(): void
    {
        $bom = pack('CCC', 0xef, 0xbb, 0xbf);
        $contentWithBom = $bom . "test content";

        $tempFile = tempnam(sys_get_temp_dir(), 'bom_test_');
        file_put_contents($tempFile, $contentWithBom);

        $handle = fopen($tempFile, 'r');
        $result = bom_exists($handle);
        fclose($handle);

        $this->assertTrue($result);
        unlink($tempFile);
    }

    public function testBomNotExists(): void
    {
        $contentWithoutBom = "test content without BOM";

        $tempFile = tempnam(sys_get_temp_dir(), 'no_bom_test_');
        file_put_contents($tempFile, $contentWithoutBom);

        $handle = fopen($tempFile, 'r');
        $result = bom_exists($handle);
        fclose($handle);

        $this->assertFalse($result);
        unlink($tempFile);
    }

    public function testImportItemBasicFields(): void
    {
        $itemData = [
            'item_id' => null,
            'name' => 'CSV Imported Item',
            'description' => 'Description from CSV',
            'category' => 'Electronics',
            'cost_price' => 10.50,
            'unit_price' => 25.99,
            'reorder_level' => 5,
            'supplier_id' => null,
            'item_number' => 'CSV-ITEM-001',
            'allow_alt_description' => 0,
            'is_serialized' => 0,
            'deleted' => 0
        ];

        $this->assertTrue($this->item->save_value($itemData));

        $row = $this->db->table('items')
            ->where('item_number', $itemData['item_number'])
            ->get()
            ->getRow();

        $this->assertNotNull($row);
        $this->assertIsInt((int) $row->item_id);
        $this->assertGreaterThan(0, (int) $row->item_id);

        $savedItem = $this->item->get_info((int) $row->item_id);
        $this->assertEquals('CSV Imported Item', $savedItem->name);
        $this->assertEquals('Description from CSV', $savedItem->description);
        $this->assertEquals('Electronics', $savedItem->category);
        $this->assertEquals(10.50, (float) $savedItem->cost_price);
        $this->assertEquals(25.99, (float) $savedItem->unit_price);
    }

    public function testImportItemWithQuantity(): void
    {
        $itemData = [
            'item_id' => null,
            'name' => 'Item With Quantity',
            'category' => 'Test Category',
            'cost_price' => 5.00,
            'unit_price' => 10.00,
            'reorder_level' => 2,
            'deleted' => 0
        ];

        $this->assertTrue($this->item->save_value($itemData));

        $locationId = 1;
        $quantity = 100;

        $itemQuantityData = [
            'item_id' => $itemData['item_id'],
            'location_id' => $locationId,
            'quantity' => $quantity
        ];

        $result = $this->item_quantity->save_value($itemQuantityData, $itemData['item_id'], $locationId);
        $this->assertTrue($result);

        $savedQuantity = $this->item_quantity->get_item_quantity($itemData['item_id'], $locationId);
        $this->assertEquals($quantity, $savedQuantity->quantity);
    }

    public function testImportItemCreatesInventoryRecord(): void
    {
        $itemData = [
            'item_id' => null,
            'item_number' => '1234567890',
            'name' => 'Item With Inventory',
            'category' => 'Test',
            'cost_price' => 5.00,
            'unit_price' => 10.00,
            'deleted' => 0
        ];

        $this->assertTrue($this->item->save_value($itemData));

        $inventoryData = [
            'trans_inventory' => 50,
            'trans_items' => $itemData['item_id'],
            'trans_location' => 1,
            'trans_comment' => 'CSV Import',
            'trans_user' => 1
        ];

        $transId = $this->inventory->insert($inventoryData);

        $this->assertIsInt($transId);
        $this->assertGreaterThan(0, $transId);

        $inventoryRecords = $this->inventory->get_inventory_data_for_item($itemData['item_id'], 1);
        $this->assertGreaterThanOrEqual(1, $inventoryRecords->getNumRows());
    }

    public function testImportItemWithTaxes(): void
    {
        $itemData = [
            'item_id' => null,
            'item_number' => '1234567890',
            'name' => 'Taxable Item',
            'category' => 'Test',
            'cost_price' => 100.00,
            'unit_price' => 150.00,
            'deleted' => 0
        ];

        $this->assertTrue($this->item->save_value($itemData));

        $taxesData = [
            ['name' => 'VAT', 'percent' => 20],
            ['name' => 'GST', 'percent' => 10]
        ];

        $result = $this->item_taxes->save_value($taxesData, $itemData['item_id']);
        $this->assertTrue($result);

        $savedTaxes = $this->item_taxes->get_info($itemData['item_id']);

        $taxNames = array_column($savedTaxes, 'name');
        $this->assertContains('VAT', $taxNames);
        $this->assertContains('GST', $taxNames);
    }

    public function testImportMultipleItemsFromSimulatedCsv(): void
    {
        $csvData = [
            [
                'Id' => '',
                'Barcode' => 'ITEM-A',
                'Item Name' => 'First Item',
                'Category' => 'Category A',
                'Supplier ID' => '',
                'Cost Price' => '10.00',
                'Unit Price' => '20.00',
                'Tax 1 Name' => '',
                'Tax 1 Percent' => '',
                'Tax 2 Name' => '',
                'Tax 2 Percent' => '',
                'Reorder Level' => '5',
                'Description' => 'First item description',
                'Allow Alt Description' => '0',
                'Item has Serial Number' => '0',
                'Image' => '',
                'HSN' => '',
                'location_Warehouse' => '100'
            ],
            [
                'Id' => '',
                'Barcode' => 'ITEM-B',
                'Item Name' => 'Second Item',
                'Category' => 'Category B',
                'Supplier ID' => '',
                'Cost Price' => '15.00',
                'Unit Price' => '30.00',
                'Tax 1 Name' => '',
                'Tax 1 Percent' => '',
                'Tax 2 Name' => '',
                'Tax 2 Percent' => '',
                'Reorder Level' => '10',
                'Description' => 'Second item description',
                'Allow Alt Description' => '0',
                'Item has Serial Number' => '0',
                'Image' => '',
                'HSN' => '',
                'location_Warehouse' => '50'
            ]
        ];

        foreach ($csvData as $row) {
            $itemData = [
                'item_id' => (int)$row['Id'] ?: null,
                'name' => $row['Item Name'],
                'description' => $row['Description'],
                'category' => $row['Category'],
                'cost_price' => (float)$row['Cost Price'],
                'unit_price' => (float)$row['Unit Price'],
                'reorder_level' => (int)$row['Reorder Level'],
                'item_number' => $row['Barcode'] ?: null,
                'allow_alt_description' => empty($row['Allow Alt Description']) ? '0' : '1',
                'is_serialized' => empty($row['Item has Serial Number']) ? '0' : '1',
                'deleted' => false
            ];

            $this->assertTrue($this->item->save_value($itemData));
        }

        $item1 = $this->item->get_info_by_id_or_number('ITEM-A');
        $this->assertEquals('First Item', $item1->name);
        $this->assertEquals(10.00, (float) $item1->cost_price);

        $item2 = $this->item->get_info_by_id_or_number('ITEM-B');
        $this->assertEquals('Second Item', $item2->name);
        $this->assertEquals(15.00, (float) $item2->cost_price);
    }

    public function testImportUpdateExistingItem(): void
    {
        $originalData = [
            'item_id' => null,
            'item_number' => '1234567890',
            'name' => 'Original Name',
            'category' => 'Original Category',
            'cost_price' => 10.00,
            'unit_price' => 20.00,
            'deleted' => 0
        ];

        $this->assertTrue($this->item->save_value($originalData));

        $updatedData = [
            'item_id' => $originalData['item_id'],
            'name' => 'Updated Name',
            'category' => 'Updated Category',
            'cost_price' => 15.00,
            'unit_price' => 30.00,
            'description' => 'New description',
            'reorder_level' => 10,
            'deleted' => 0
        ];

        $this->assertTrue($this->item->save_value($updatedData, $updatedData['item_id']));

        $updatedItem = $this->item->get_info($updatedData['item_id']);
        $this->assertEquals('Updated Name', $updatedItem->name);
        $this->assertEquals('Updated Category', $updatedItem->category);
        $this->assertEquals(15.00, (float)$updatedItem->cost_price);
        $this->assertEquals(30.00, (float)$updatedItem->unit_price);
    }

    public function testImportDeleteAttributeFromExistingItem(): void
    {
        $originalData = [
            'item_id' => null,
            'item_number' => '1234567890',
            'name' => 'Original Name',
            'category' => 'Original Category',
            'cost_price' => '10.00',
            'unit_price' => '20.00',
            'deleted' => 0
        ];

        $this->assertTrue($this->item->save_value($originalData));

        $definitionData = [
            'definition_name' => 'Color',
            'definition_type' => TEXT,
            'definition_flags' => 0,
            'deleted' => 0
        ];

        $this->assertTrue($this->attribute->saveDefinition($definitionData));

        //Assign attribute to item
        $attributeValue = 'Red';
        $attributeId = $this->attribute->saveAttributeValue(//need to properly assign an attribute link before this is going to work
            $attributeValue,
            $definitionData['definition_id'],
            $originalData['item_id'],
            false,
            TEXT
        );
        $this->assertGreaterThan(0, $attributeId, 'Attribute fixture must exist before testing _DELETE_');

        //Mock CSV import
        $updatedItemData = ['item_id' => $originalData['item_id']];
        $attributeDefinitionData = [$definitionData];
        $updatedValues = ['Color' => '_DELETE_'];

        $saveSuccess = $this->attribute->saveCSVRowAttributeData($updatedValues, $updatedItemData, $attributeDefinitionData);
        $this->assertTrue($saveSuccess);

        $resultingAttribute = $this->attribute->getAttributeValue($originalData['item_id'], $definitionData['definition_id']);
        $this->assertEquals(NEW_ENTRY, $resultingAttribute->attribute_id);
    }

    public function testImportItemWithAttributeText(): void
    {
        $itemData = [
            'item_id' => null,
            'item_number' => '1234567890',
            'name' => 'Item With Attribute',
            'category' => 'Test',
            'cost_price' => 10.00,
            'unit_price' => 20.00,
            'deleted' => 0
        ];

        $this->assertTrue($this->item->save_value($itemData));

        $definitionData = [
            'definition_name' => 'Color',
            'definition_type' => TEXT,
            'definition_flags' => 0,
            'deleted' => 0
        ];

        $this->assertTrue($this->attribute->saveDefinition($definitionData));
        $this->assertNotEmpty($definitionData['definition_id']);
        $definitionId = $definitionData['definition_id'];

        $this->assertNotNull($definitionId);


        $attributeValue = 'Red';
        $attributeId = $this->attribute->saveAttributeValue(
            $attributeValue,
            $definitionId,
            $itemData['item_id'],
            false,
            TEXT
        );

        $this->assertNotFalse($attributeId);

        $savedValue = $this->attribute->getAttributeValue($itemData['item_id'], $definitionId);
        $this->assertEquals('Red', $savedValue->attribute_value);
    }

    public function testImportItemWithAttributeDropdown(): void
    {
        $itemData = [
            'item_id' => null,
            'item_number' => '1234567890',
            'name' => 'Item With Dropdown',
            'category' => 'Test',
            'cost_price' => 10.00,
            'unit_price' => 20.00,
            'deleted' => 0
        ];

        $this->assertTrue($this->item->save_value($itemData));

        // Mock Attribute DROPDOWN
        $definitionData = [
            'definition_name' => 'Size',
            'definition_type' => DROPDOWN,
            'definition_flags' => 0,
            'deleted' => 0
        ];
        $this->assertTrue($this->attribute->saveDefinition($definitionData));
        $definitionId = $definitionData['definition_id'];

        // Mock Attribute DROPDOWN Values
        $dropdownValues = ['Small', 'Medium', 'Large'];
        foreach ($dropdownValues as $value) {
            $this->attribute->saveAttributeValue(
                $value,
                $definitionId,
                false,
                false,
                DROPDOWN
            );
        }

        // Save dropdown attribute value for item
        $attributeValue = 'Medium';
        $attributeId = $this->attribute->saveAttributeValue(
            $attributeValue,
            $definitionId,
            $itemData['item_id'],
            false,
            DROPDOWN
        );

        $this->assertNotFalse($attributeId);

        $savedValue = $this->attribute->getAttributeValue($itemData['item_id'], $definitionId);
        $this->assertEquals('Medium', $savedValue->attribute_value);
    }

    public function testImportItemQuantityZero(): void
    {
        $itemData = [
            'item_id' => null,
            'name' => 'Item Zero Quantity',
            'category' => 'Test',
            'cost_price' => 5.00,
            'unit_price' => 10.00,
            'deleted' => 0
        ];

        $this->assertTrue($this->item->save_value($itemData));

        $locationId = 1;

        $itemQuantityData = [
            'item_id' => $itemData['item_id'],
            'location_id' => $locationId,
            'quantity' => 0
        ];

        $result = $this->item_quantity->save_value($itemQuantityData, $itemData['item_id'], $locationId);
        $this->assertTrue($result);

        $savedQuantity = $this->item_quantity->get_item_quantity($itemData['item_id'], $locationId);
        $this->assertEquals(0, (int)$savedQuantity->quantity);
    }

    public function testImportItemWithNegativeReorderLevel(): void
    {
        $itemData = [
            'item_id' => null,
            'name' => 'Item Negative Reorder',
            'category' => 'Test',
            'cost_price' => 5.00,
            'unit_price' => 10.00,
            'reorder_level' => -1,
            'deleted' => 0
        ];

        $this->assertTrue($this->item->save_value($itemData));

        $savedItem = $this->item->get_info($itemData['item_id']);
        $this->assertEquals(-1, (int)$savedItem->reorder_level);
    }

    public function testImportItemPriceRoundingBoundaries(): void
    {
        $cases = [
            [
                'cost_price' => 10.004,
                'unit_price' => 25.004,
                'expected_cost_price' => '10.00',
                'expected_unit_price' => '25.00',
            ],
            [
                'cost_price' => 10.005,
                'unit_price' => 25.005,
                'expected_cost_price' => '10.01',
                'expected_unit_price' => '25.01',
            ],
            [
                'cost_price' => 10.006,
                'unit_price' => 25.006,
                'expected_cost_price' => '10.01',
                'expected_unit_price' => '25.01',
            ],
        ];

        foreach ($cases as $case) {
            $itemData = [
                'item_id' => null,
                'name' => 'Rounding Boundary Item ' . $case['cost_price'],
                'category' => 'Test',
                'cost_price' => $case['cost_price'],
                'unit_price' => $case['unit_price'],
                'deleted' => 0
            ];

            $this->assertTrue($this->item->save_value($itemData));

            $savedItem = $this->item->get_info($itemData['item_id']);

            $this->assertSame(
                $case['expected_cost_price'],
                number_format((float) $savedItem->cost_price, 2, '.', ''),
                'Cost price should be rounded correctly at the 3rd decimal boundary'
            );

            $this->assertSame(
                $case['expected_unit_price'],
                number_format((float) $savedItem->unit_price, 2, '.', ''),
                'Unit price should be rounded correctly at the 3rd decimal boundary'
            );
        }
    }

    public function testImportItemWithHsnCode(): void
    {
        $itemData = [
            'item_id' => null,
            'name' => 'Item With HSN',
            'category' => 'Test',
            'cost_price' => 10.00,
            'unit_price' => 20.00,
            'hsn_code' => '8471',
            'deleted' => 0
        ];

        $this->assertTrue($this->item->save_value($itemData));

        $savedItem = $this->item->get_info($itemData['item_id']);
        $this->assertEquals('8471', $savedItem->hsn_code);
    }

    public function testImportItemQuantityMultipleLocations(): void
    {
        $itemData = [
            'item_id' => null,
            'name' => 'Item Multi Location',
            'category' => 'Test',
            'cost_price' => 10.00,
            'unit_price' => 20.00,
            'deleted' => 0
        ];

        $this->assertTrue($this->item->save_value($itemData));

        $locations = [
            'Warehouse' => 100,
            'Store' => 50,
            'Backroom' => 25,
        ];

        $locationIds = [];

        foreach ($locations as $locationName => $quantity) {
            $locationData = [
                'location_name' => $locationName,
                'deleted' => 0
            ];

            $this->assertTrue($this->stock_location->save_value($locationData, NEW_ENTRY));
            $this->assertNotEmpty($locationData['location_id']);

            $locationIds[$locationName] = $locationData['location_id'];

            $result = $this->item_quantity->save_value(
                [
                    'item_id' => $itemData['item_id'],
                    'location_id' => $locationData['location_id'],
                    'quantity' => $quantity
                ],
                $itemData['item_id'],
                $locationData['location_id']
            );

            $this->assertTrue($result);
        }

        $warehouseQuantity = $this->item_quantity->get_item_quantity($itemData['item_id'], $locationIds['Warehouse']);
        $this->assertEquals(100, (int) $warehouseQuantity->quantity);

        $storeQuantity = $this->item_quantity->get_item_quantity($itemData['item_id'], $locationIds['Store']);
        $this->assertEquals(50, (int) $storeQuantity->quantity);

        $backroomQuantity = $this->item_quantity->get_item_quantity($itemData['item_id'], $locationIds['Backroom']);
        $this->assertEquals(25, (int) $backroomQuantity->quantity);
    }

    public function testCsvImportQuantityValidationNumeric(): void
    {
        $csvData = [
            'Id' => '',
            'Barcode' => 'VALID-ITEM',
            'Item Name' => 'Valid Item',
            'Category' => 'Test',
            'Cost Price' => '10.00',
            'Unit Price' => '20.00',
            'location_Warehouse' => '100'
        ];

        $this->assertTrue(is_numeric($csvData['location_Warehouse']));
        $this->assertTrue(is_numeric($csvData['Cost Price']));
        $this->assertTrue(is_numeric($csvData['Unit Price']));
    }

    public function testCsvImportEmptyBarcodeAllowed(): void
    {
        $itemData = [
            'item_id' => null,
            'name' => 'Item Without Barcode',
            'category' => 'Test',
            'cost_price' => 10.00,
            'unit_price' => 20.00,
            'item_number' => null,
            'deleted' => 0
        ];

        $this->assertTrue($this->item->save_value($itemData));

        $this->assertIsInt($itemData['item_id']);
        $this->assertGreaterThan(0, $itemData['item_id']);

        $savedItem = $this->item->get_info($itemData['item_id']);
        $this->assertEquals('Item Without Barcode', $savedItem->name);
    }

    public function testCsvImportItemExistsCheck(): void
    {
        $itemData = [
            'item_id' => null,
            'name' => 'Existing Item',
            'category' => 'Test',
            'cost_price' => 10.00,
            'unit_price' => 20.00,
            'deleted' => 0
        ];

        $this->assertTrue($this->item->save_value($itemData));

        $exists = $this->item->exists($itemData['item_id']);
        $this->assertTrue($exists);

        $notExists = $this->item->exists(999999);
        $this->assertFalse($notExists);
    }

    public function testFullCsvImportFlowSimulated(): void
    {
        $csvRow = [
            'Id' => '',
            'Barcode' => 'FULL-TEST-001',
            'Item Name' => 'Complete Test Item',
            'Category' => 'Electronics',
            'Supplier ID' => '',
            'Cost Price' => '50.00',
            'Unit Price' => '100.00',
            'Tax 1 Name' => 'VAT',
            'Tax 1 Percent' => '20',
            'Tax 2 Name' => '',
            'Tax 2 Percent' => '',
            'Reorder Level' => '10',
            'Description' => 'A complete test item for CSV import',
            'Allow Alt Description' => '1',
            'Item has Serial Number' => '0',
            'Image' => '',
            'HSN' => '84713020'
        ];

        $itemData = [
            'item_id' => (int)$csvRow['Id'] ?: null,
            'name' => $csvRow['Item Name'],
            'description' => $csvRow['Description'],
            'category' => $csvRow['Category'],
            'cost_price' => (float)$csvRow['Cost Price'],
            'unit_price' => (float)$csvRow['Unit Price'],
            'reorder_level' => (int)$csvRow['Reorder Level'],
            'item_number' => $csvRow['Barcode'] ?: null,
            'allow_alt_description' => empty($csvRow['Allow Alt Description']) ? '0' : '1',
            'is_serialized' => empty($csvRow['Item has Serial Number']) ? '0' : '1',
            'hsn_code' => $csvRow['HSN'],
            'deleted' => 0
        ];

        $this->assertTrue($this->item->save_value($itemData));

        $taxesData = [];
        if (is_numeric($csvRow['Tax 1 Percent']) && $csvRow['Tax 1 Name'] !== '') {
            $taxesData[] = ['name' => $csvRow['Tax 1 Name'], 'percent' => $csvRow['Tax 1 Percent']];
        }
        if (is_numeric($csvRow['Tax 2 Percent']) && $csvRow['Tax 2 Name'] !== '') {
            $taxesData[] = ['name' => $csvRow['Tax 2 Name'], 'percent' => $csvRow['Tax 2 Percent']];
        }

        if (!empty($taxesData)) {
            $this->item_taxes->save_value($taxesData, $itemData['item_id']);
        }

        $locationId = 1;
        $quantity = 75;

        $quantityData = [
            'item_id' => $itemData['item_id'],
            'location_id' => $locationId,
            'quantity' => $quantity
        ];
        $this->item_quantity->save_value($quantityData, $itemData['item_id'], $locationId);

        $inventoryData = [
            'trans_inventory' => $quantity,
            'trans_items' => $itemData['item_id'],
            'trans_location' => $locationId,
            'trans_comment' => 'CSV import quantity',
            'trans_user' => 1
        ];
        $this->inventory->insert($inventoryData);

        $savedItem = $this->item->get_info($itemData['item_id']);
        $this->assertEquals('Complete Test Item', $savedItem->name);
        $this->assertEquals('Electronics', $savedItem->category);
        $this->assertEquals(50.00, (float)$savedItem->cost_price);
        $this->assertEquals(100.00, (float)$savedItem->unit_price);
        $this->assertEquals('84713020', $savedItem->hsn_code);

        $savedQuantity = $this->item_quantity->get_item_quantity($itemData['item_id'], $locationId);
        $this->assertEquals($quantity, (int)$savedQuantity->quantity);

        $savedTaxes = $this->item_taxes->get_info($itemData['item_id']);
        $this->assertCount(1, $savedTaxes);
        $this->assertEquals('VAT', $savedTaxes[0]['name']);
        $this->assertEquals(20, (float)$savedTaxes[0]['percent']);

        $inventoryRecords = $this->inventory->get_inventory_data_for_item($itemData['item_id'], $locationId);
        $this->assertGreaterThanOrEqual(1, $inventoryRecords->getNumRows());
    }

    public function testImportCsvInvalidStockLocationColumn(): void
    {
        $csvRow = [
            'Id' => '',
            'Item Name' => 'Test Item Invalid Location',
            'Category' => 'Test',
            'Cost Price' => '10.00',
            'Unit Price' => '20.00',
            'location_NonExistentLocation' => '100'
        ];

        $allowedLocations = [1 => 'Warehouse'];

        $locationColumnsInCsv = [];
        foreach (array_keys($csvRow) as $key) {
            if (str_starts_with($key, 'location_')) {
                $locationColumnsInCsv[$key] = substr($key, 9);
            }
        }

        $invalidLocations = [];
        foreach ($locationColumnsInCsv as $column => $locationName) {
            if (!in_array($locationName, $allowedLocations)) {
                $invalidLocations[] = $locationName;
            }
        }

        $this->assertNotEmpty($invalidLocations, 'Should detect invalid location in CSV');
        $this->assertContains('NonExistentLocation', $invalidLocations);
    }

    public function testImportCsvValidStockLocationColumn(): void
    {
        $csvRow = [
            'Id' => '',
            'Item Name' => 'Test Item Valid Location',
            'Category' => 'Test',
            'Cost Price' => '10.00',
            'Unit Price' => '20.00',
            'location_Warehouse' => '100'
        ];

        $allowedLocations = [1 => 'Warehouse'];

        $locationColumnsInCsv = [];
        foreach (array_keys($csvRow) as $key) {
            if (str_starts_with($key, 'location_')) {
                $locationColumnsInCsv[$key] = substr($key, 9);
            }
        }

        $invalidLocations = [];
        foreach ($locationColumnsInCsv as $column => $locationName) {
            if (!in_array($locationName, $allowedLocations)) {
                $invalidLocations[] = $locationName;
            }
        }

        $this->assertEmpty($invalidLocations, 'Should have no invalid locations');
    }

    public function testImportCsvMixedValidAndInvalidLocations(): void
    {
        $csvRow = [
            'Id' => '',
            'Item Name' => 'Test Item Mixed Locations',
            'Category' => 'Test',
            'Cost Price' => '10.00',
            'Unit Price' => '20.00',
            'location_Warehouse' => '100',
            'location_InvalidLocation' => '50'
        ];

        $allowedLocations = [1 => 'Warehouse', 2 => 'Store'];

        $locationColumnsInCsv = [];
        foreach (array_keys($csvRow) as $key) {
            if (str_starts_with($key, 'location_')) {
                $locationColumnsInCsv[$key] = substr($key, 9);
            }
        }

        $invalidLocations = [];
        foreach ($locationColumnsInCsv as $column => $locationName) {
            if (!in_array($locationName, $allowedLocations)) {
                $invalidLocations[] = $locationName;
            }
        }

        $this->assertCount(1, $invalidLocations, 'Should have exactly one invalid location');
        $this->assertContains('InvalidLocation', $invalidLocations);
    }

    public function testValidateCsvStockLocations(): void
    {
        $csvContent = "Id,\"Item Name\",Category,\"Cost Price\",\"Unit Price\",\"location_Warehouse\",\"location_FakeLocation\"\n";
        $csvContent .= ",Test Item,Test,10.00,20.00,100,50\n";

        $tempFile = tempnam(sys_get_temp_dir(), 'csv_location_test_');
        file_put_contents($tempFile, $csvContent);

        $rows = get_csv_file($tempFile);
        $this->assertCount(1, $rows);

        $row = $rows[0];
        $this->assertArrayHasKey('location_Warehouse', $row);
        $this->assertArrayHasKey('location_FakeLocation', $row);

        unlink($tempFile);
    }

    public function testImportItemQuantityOnlyForValidLocations(): void
    {
        $itemData = [
            'item_id' => null,
            'name' => 'Item Location Test',
            'category' => 'Test',
            'cost_price' => 10.00,
            'unit_price' => 20.00,
            'deleted' => 0
        ];

        $this->assertTrue($this->item->save_value($itemData));

        $uniqueId = uniqid();
        $locations = ['Warehouse' . $uniqueId, 'Store' . $uniqueId];

        $allowedLocations = [];
        foreach ($locations as $locationName) {
            $currentLocation = ['location_name' => $locationName, 'deleted' => 0];
            $this->assertTrue($this->stock_location->save_value($currentLocation, NEW_ENTRY));
            $allowedLocations[$currentLocation['location_id']] = $locationName;
        }

        $csvRowSimulated = [
            'location_Warehouse' . $uniqueId => '100',
            'location_Store' . $uniqueId => '50',
            'location_NonExistent' => '25'
        ];

        foreach ($allowedLocations as $locationId => $locationName) {
            $columnName = "location_$locationName";
            if (isset($csvRowSimulated[$columnName]) || $csvRowSimulated[$columnName] === '0') {
                $quantityData = [
                    'item_id' => $itemData['item_id'],
                    'location_id' => $locationId,
                    'quantity' => (int)$csvRowSimulated[$columnName]
                ];
                $this->item_quantity->save_value($quantityData, $itemData['item_id'], $locationId);
            }
        }

        $warehouseId = array_search('Warehouse' . $uniqueId, $allowedLocations, true);
        $warehouseQuantity = $this->item_quantity->get_item_quantity($itemData['item_id'], $warehouseId);
        $this->assertEquals(100, (int)$warehouseQuantity->quantity);

        $storeId = array_search('Store'. $uniqueId, $allowedLocations, true);
        $storeQuantity = $this->item_quantity->get_item_quantity($itemData['item_id'], $storeId);
        $this->assertEquals(50, (int)$storeQuantity->quantity);

        $result = $this->item_quantity->exists($itemData['item_id'], 999);
        $this->assertFalse($result, 'Should not have quantity for non-existent location');
    }

    public function testDetectCsvLocationColumns(): void
    {
        $row = [
            'Id' => '',
            'Item Name' => 'Test',
            'location_Warehouse' => '100',
            'location_Store' => '50',
            'attribute_Color' => 'Red'
        ];

        $locationColumns = [];
        foreach (array_keys($row) as $key) {
            if (str_starts_with($key, 'location_')) {
                $locationColumns[$key] = substr($key, 9);
            }
        }

        $this->assertCount(2, $locationColumns);
        $this->assertArrayHasKey('location_Warehouse', $locationColumns);
        $this->assertArrayHasKey('location_Store', $locationColumns);
        $this->assertEquals('Warehouse', $locationColumns['location_Warehouse']);
        $this->assertEquals('Store', $locationColumns['location_Store']);
    }

    /**
     * Ten units bought at 50, so the books hold them at 500.00, and an employee who may edit items.
     */
    private function itemInTheBooks(): int
    {
        $this->startBuying();

        $itemId = $this->item('0.00');
        $this->assertGreaterThan(0, $this->buy([$this->purchaseLine($itemId, 0, '10', '50')]));
        $this->employeeId = $this->createItemsEmployee();
        $this->loginAsItemsEmployee($this->employeeId);

        return $itemId;
    }

    /**
     * @return list<object>
     */
    private function adjustmentJournals(): array
    {
        return $this->accountingDb()->table('accounting_journals')->where('source_type', 'stock_adjustment')->get()->getResult();
    }

    private function inventoryLog(int $itemId): array
    {
        return $this->accountingDb()->table('inventory')->where('trans_items', $itemId)->where('trans_comment NOT LIKE', 'RECV %')->get()->getResult();
    }

    /**
     * The controller method that saves the quantities of a row of the items file, which no
     * request reaches without a file to upload.
     *
     * @param array<string, mixed> $row
     */
    private function importQuantities(array $row, int $itemId, int $employeeId): bool
    {
        $reflection = new ReflectionClass(Items::class);
        $controller = $reflection->newInstanceWithoutConstructor();

        foreach (['item_quantity' => $this->item_quantity, 'inventory' => $this->inventory] as $name => $model) {
            $reflection->getProperty($name)->setValue($controller, $model);
        }

        return $reflection->getMethod('save_inventory_quantities')->invoke($controller, $row, ['item_id' => $itemId], [1 => 'stock'], $employeeId);
    }

    public function testPostSaveInventory_PostsWhatWasAddedToTheBooks(): void
    {
        $itemId = $this->itemInTheBooks();

        $response = $this->post('/items/saveInventory/' . $itemId, ['stock_location' => '1', 'newquantity' => '2', 'trans_comment' => 'Found two']);

        $this->assertTrue(json_decode($response->getJSON(), true)['success']);
        $this->assertSame('12.000', $this->onHand($itemId));
        $this->assertSame(['quantity' => '12.000', 'value' => '600.00'], $this->stockOf($itemId));
        $this->assertCount(1, $this->adjustmentJournals());
        $this->assertStockTies();
    }

    public function testPostSaveInventory_PostsWhatWasTakenOffAtWhatAUnitIsWorth(): void
    {
        $itemId = $this->itemInTheBooks();

        $response = $this->post('/items/saveInventory/' . $itemId, ['stock_location' => '1', 'newquantity' => '-4', 'trans_comment' => 'Broken']);

        $this->assertTrue(json_decode($response->getJSON(), true)['success']);
        $this->assertSame(['quantity' => '6.000', 'value' => '300.00'], $this->stockOf($itemId));
        $this->assertStockTies();
    }

    public function testPostSaveInventory_RefusedByTheBooksLeavesTheShelvesAndTheLogAsTheyWere(): void
    {
        $itemId = $this->itemInTheBooks();
        $log    = count($this->inventoryLog($itemId));
        $this->closeThrough(date('Y-m-d'));

        $result = json_decode($this->post('/items/saveInventory/' . $itemId, ['stock_location' => '1', 'newquantity' => '2', 'trans_comment' => 'Found two'])->getJSON(), true);

        $this->assertFalse($result['success']);
        $this->assertStringContainsString('period closed through', $result['message']);
        $this->assertSame('10.000', $this->onHand($itemId));
        $this->assertSame($log, count($this->inventoryLog($itemId)));
        $this->assertSame([], $this->adjustmentJournals());
    }

    public function testPostSaveInventory_KeepsNoBooksWhileAccountingIsOff(): void
    {
        $itemId = $this->createTestItem();
        $this->loginAsItemsEmployee($this->createItemsEmployee());

        $result = json_decode($this->post('/items/saveInventory/' . $itemId, ['stock_location' => '1', 'newquantity' => '2', 'trans_comment' => 'Found two'])->getJSON(), true);

        $this->assertTrue($result['success']);
        $this->assertSame([], $this->adjustmentJournals());
        $this->assertSame(0, $this->countRows('accounting_stock_entries'));
    }

    public function testPostSave_PostsAQuantityEditedOnTheItem(): void
    {
        $itemId = $this->itemInTheBooks();

        $postData = array_merge($this->baseItemPostData(), ['tax_names' => ['VAT'], 'tax_percents' => ['0'], 'quantity_1' => '13', 'cost_price' => '50.00']);
        $result   = json_decode($this->post('/items/save/' . $itemId, $postData)->getJSON(), true);

        $this->assertTrue($result['success']);
        $this->assertSame('13.000', $this->onHand($itemId));
        $this->assertSame(['quantity' => '13.000', 'value' => '650.00'], $this->stockOf($itemId));
        $this->assertCount(1, $this->adjustmentJournals());
        $this->assertStockTies();
    }

    public function testPostSave_PostsNothingWhenTheQuantityIsNotEdited(): void
    {
        $itemId = $this->itemInTheBooks();

        $postData = array_merge($this->baseItemPostData(), ['tax_names' => ['VAT'], 'tax_percents' => ['0'], 'quantity_1' => '10', 'name' => 'Renamed', 'cost_price' => '50.00']);
        $result   = json_decode($this->post('/items/save/' . $itemId, $postData)->getJSON(), true);

        $this->assertTrue($result['success']);
        $this->assertSame([], $this->adjustmentJournals());
    }

    public function testPostSave_RefusedByTheBooksKeepsTheItemAsItWas(): void
    {
        $itemId = $this->itemInTheBooks();
        $name   = $this->item->get_info($itemId)->name;
        $this->closeThrough(date('Y-m-d'));

        $postData = array_merge($this->baseItemPostData(), ['tax_names' => ['VAT'], 'tax_percents' => ['0'], 'quantity_1' => '13', 'name' => 'Renamed', 'cost_price' => '50.00']);
        $result   = json_decode($this->post('/items/save/' . $itemId, $postData)->getJSON(), true);

        $this->assertFalse($result['success']);
        $this->assertSame($name, $this->item->get_info($itemId)->name);
        $this->assertSame('10.000', $this->onHand($itemId));
        $this->assertSame([], $this->adjustmentJournals());
    }

    public function testPostSave_PostsTheOpeningQuantityOfANewItemAtItsCost(): void
    {
        $this->startBuying();
        $this->loginAsItemsEmployee($this->createItemsEmployee());

        $postData = array_merge($this->baseItemPostData(), ['tax_names' => ['VAT'], 'tax_percents' => ['0'], 'quantity_1' => '5', 'cost_price' => '8.00']);
        $result   = json_decode($this->post('/items/save', $postData)->getJSON(), true);

        $this->assertTrue($result['success']);
        $this->assertSame(['quantity' => '5.000', 'value' => '40.00'], $this->stockOf((int) $result['id']));
        $this->assertStockTies();
    }

    public function testSaveInventoryQuantities_PostsTheDifferenceAndNotTheQuantityOfTheFile(): void
    {
        $itemId = $this->itemInTheBooks();

        $this->assertTrue($this->importQuantities(['Id' => (string) $itemId, 'location_stock' => '14'], $itemId, $this->employeeId));

        $this->assertSame('14.000', $this->onHand($itemId));
        $this->assertSame(['quantity' => '14.000', 'value' => '700.00'], $this->stockOf($itemId));
        $this->assertSame('4.000', (string) $this->inventoryLog($itemId)[count($this->inventoryLog($itemId)) - 1]->trans_inventory);
        $this->assertStockTies();
    }

    public function testSaveInventoryQuantities_RefusedByTheBooksReportsFailure(): void
    {
        $itemId = $this->itemInTheBooks();
        $this->closeThrough(date('Y-m-d'));

        $this->assertFalse($this->importQuantities(['Id' => (string) $itemId, 'location_stock' => '14'], $itemId, $this->employeeId));
        $this->assertSame([], $this->adjustmentJournals());
    }

    public function testPostDelete_TakesTheStockOfTheItemOffTheBooks(): void
    {
        $itemId = $this->itemInTheBooks();

        $result = json_decode($this->post('/items/delete', ['ids' => [(string) $itemId]])->getJSON(), true);

        $this->assertTrue($result['success']);
        $this->assertSame(1, (int) $this->item->get_info($itemId)->deleted);
        $this->assertSame('0.000', $this->onHand($itemId));
        $this->assertSame('0.00', $this->stockOf($itemId)['value']);
        $this->assertCount(1, $this->adjustmentJournals());
        $this->assertStockTies();
    }

    public function testPostDelete_RefusedByTheBooksLeavesTheItemAndItsStock(): void
    {
        $itemId = $this->itemInTheBooks();
        $this->closeThrough(date('Y-m-d'));

        $result = json_decode($this->post('/items/delete', ['ids' => [(string) $itemId]])->getJSON(), true);

        $this->assertFalse($result['success']);
        $this->assertStringContainsString(lang('Items.cannot_be_deleted'), $result['message']);
        $this->assertSame(0, (int) $this->item->get_info($itemId)->deleted);
        $this->assertSame('10.000', $this->onHand($itemId));
        $this->assertSame([], $this->adjustmentJournals());
    }

    public function testValidateLocationNamesCaseSensitivity(): void
    {
        $allowedLocations = [1 => 'Warehouse', 2 => 'Store'];

        $csvLocationName = 'warehouse';

        $isValid = in_array($csvLocationName, $allowedLocations);
        $this->assertFalse($isValid, 'Location names should be case-sensitive');

        $csvLocationName = 'Warehouse';
        $isValid = in_array($csvLocationName, $allowedLocations);
        $this->assertTrue($isValid, 'Valid location name should pass validation');
    }

    /**
     * A stock location in a branch, with the items permission for it given to the employee
     * named, or to nobody.
     */
    private function storeIn(int $branchId, ?int $personId, string $label = 'store'): int
    {
        $db   = $this->accountingDb();
        $name = $label . substr(md5(uniqid('', true)), 0, 8);

        $locationId = $this->locationOfBranch($branchId, $name);

        $db->table('permissions')->insert(['permission_id' => "items_{$name}", 'module_id' => 'items', 'location_id' => $locationId]);

        if ($personId !== null) {
            $db->table('grants')->insert(['permission_id' => "items_{$name}", 'person_id' => $personId, 'menu_group' => 'home']);
        }

        return $locationId;
    }

    /**
     * An item with ten units in the stock of the first branch, a second branch with a store of
     * its own, and an employee who works in the first branch only and may use both stores.
     *
     * @return array{item: int, store: int, branch: int, limited: int}
     */
    private function itemWithAStoreInAnotherBranch(): array
    {
        $itemId  = $this->itemInTheBooks();
        $branch  = $this->createBranch('B' . substr(md5(uniqid('', true)), 0, 6), 'Second branch');
        $limited = $this->createItemsEmployee([1]);
        $store   = $this->storeIn($branch, $limited);

        $this->loginAsItemsEmployee($limited);

        return ['item' => $itemId, 'store' => $store, 'branch' => $branch, 'limited' => $limited];
    }

    public function testPostSaveInventory_RefusesALocationOfABranchTheEmployeeDoesNotWorkInAndChangesNothing(): void
    {
        ['item' => $itemId, 'store' => $store] = $this->itemWithAStoreInAnotherBranch();
        $log      = count($this->inventoryLog($itemId));
        $journals = count($this->adjustmentJournals());

        $result = json_decode($this->post('/items/saveInventory/' . $itemId, ['stock_location' => (string) $store, 'newquantity' => '5', 'trans_comment' => 'Found five'])->getJSON(), true);

        $this->assertFalse($result['success']);
        $this->assertSame(lang('Items.stock_location_not_allowed'), $result['message']);
        $this->assertSame('0.000', $this->onHand($itemId, $store));
        $this->assertSame($log, count($this->inventoryLog($itemId)));
        $this->assertCount($journals, $this->adjustmentJournals());
    }

    public function testPostSaveInventory_RefusesALocationOfAnotherBranchWhileAccountingIsOff(): void
    {
        ['item' => $itemId, 'store' => $store] = $this->itemWithAStoreInAnotherBranch();
        $this->setConfigValue('accounting_enabled', '0');
        $log = count($this->inventoryLog($itemId));

        $result = json_decode($this->post('/items/saveInventory/' . $itemId, ['stock_location' => (string) $store, 'newquantity' => '5', 'trans_comment' => 'Found five'])->getJSON(), true);

        $this->assertFalse($result['success']);
        $this->assertSame('0.000', $this->onHand($itemId, $store));
        $this->assertSame($log, count($this->inventoryLog($itemId)));
    }

    public function testPostSaveInventory_PostsInTheBranchOfTheLocationForAnEmployeeConfinedToIt(): void
    {
        ['item' => $itemId] = $this->itemWithAStoreInAnotherBranch();

        $result = json_decode($this->post('/items/saveInventory/' . $itemId, ['stock_location' => '1', 'newquantity' => '2', 'trans_comment' => 'Found two'])->getJSON(), true);

        $this->assertTrue($result['success']);
        $this->assertSame('12.000', $this->onHand($itemId));
        $this->assertSame([1], array_map(static fn (object $journal): int => (int) $journal->branch_id, $this->adjustmentJournals()));
        $this->assertStockTies();
    }

    public function testPostSaveInventory_PostsInTheBranchOfTheLocationForAnEmployeeOfEveryBranch(): void
    {
        ['item' => $itemId, 'store' => $store, 'branch' => $branch] = $this->itemWithAStoreInAnotherBranch();
        $everyBranch = $this->createItemsEmployee();
        $this->accountingDb()->table('grants')->insert(['permission_id' => $this->accountingDb()->table('permissions')->where('location_id', $store)->get()->getRow()->permission_id, 'person_id' => $everyBranch, 'menu_group' => 'home']);
        $this->loginAsItemsEmployee($everyBranch);
        $this->accountingDb()->table('items')->where('item_id', $itemId)->update(['cost_price' => '10.00']);

        $result = json_decode($this->post('/items/saveInventory/' . $itemId, ['stock_location' => (string) $store, 'newquantity' => '3', 'trans_comment' => 'Found three'])->getJSON(), true);

        $this->assertTrue($result['success']);
        $this->assertSame('3.000', $this->onHand($itemId, $store));
        $this->assertSame([$branch], array_map(static fn (object $journal): int => (int) $journal->branch_id, $this->adjustmentJournals()));
    }

    public function testPostSaveInventory_RefusesALocationInTheBranchOfTheEmployeeThatTheyHoldNoItemsPermissionOn(): void
    {
        $itemId = $this->itemInTheBooks();
        $store  = $this->storeIn(1, null);
        $log    = count($this->inventoryLog($itemId));

        $result = json_decode($this->post('/items/saveInventory/' . $itemId, ['stock_location' => (string) $store, 'newquantity' => '5', 'trans_comment' => 'Found five'])->getJSON(), true);

        $this->assertFalse($result['success']);
        $this->assertSame('0.000', $this->onHand($itemId, $store));
        $this->assertSame($log, count($this->inventoryLog($itemId)));
    }

    public function testPostSave_IgnoresTheQuantityOfALocationOfABranchTheEmployeeDoesNotWorkIn(): void
    {
        ['item' => $itemId, 'store' => $store] = $this->itemWithAStoreInAnotherBranch();
        $log = count($this->inventoryLog($itemId));

        $postData = array_merge($this->baseItemPostData(), ['tax_names' => ['VAT'], 'tax_percents' => ['0'], 'quantity_1' => '10', 'quantity_' . $store => '99', 'name' => 'Renamed', 'cost_price' => '50.00']);
        $result   = json_decode($this->post('/items/save/' . $itemId, $postData)->getJSON(), true);

        $this->assertTrue($result['success']);
        $this->assertSame('Renamed', $this->item->get_info($itemId)->name);
        $this->assertSame('0.000', $this->onHand($itemId, $store));
        $this->assertSame('10.000', $this->onHand($itemId));
        $this->assertSame($log, count($this->inventoryLog($itemId)));
        $this->assertSame([], $this->adjustmentJournals());
    }

    public function testPostSave_DoesNotZeroTheStockOfAnotherBranchWhenTheFormLeavesItOut(): void
    {
        ['item' => $itemId, 'store' => $store] = $this->itemWithAStoreInAnotherBranch();
        $this->assertTrue($this->item_quantity->changeQuantity($itemId, $store, 4.0));

        $postData = array_merge($this->baseItemPostData(), ['tax_names' => ['VAT'], 'tax_percents' => ['0'], 'quantity_1' => '10', 'cost_price' => '50.00']);
        $result   = json_decode($this->post('/items/save/' . $itemId, $postData)->getJSON(), true);

        $this->assertTrue($result['success']);
        $this->assertSame('4.000', $this->onHand($itemId, $store));
    }

    public function testGetView_ListsTheStockLocationsOfTheBranchesTheEmployeeWorksInOnly(): void
    {
        ['item' => $itemId, 'store' => $store, 'limited' => $limited] = $this->itemWithAStoreInAnotherBranch();

        foreach (['view' => 'id="quantity_1"', 'inventory' => '<option value="1"', 'countDetails' => '<option value="1"'] as $screen => $listsTheOwnLocation) {
            $this->loginAsItemsEmployee($limited);
            $body = $this->get('/items/' . $screen . '/' . $itemId)->getBody();

            $this->assertStringNotContainsString('quantity_' . $store, $body, $screen);
            $this->assertStringNotContainsString((string) $this->accountingDb()->table('stock_locations')->where('location_id', $store)->get()->getRow()->location_name, $body, $screen);
            $this->assertStringContainsString($listsTheOwnLocation, $body, $screen);
        }

        $this->loginAsItemsEmployee($this->employeeOfEveryBranchWithTheStore($store));

        $this->assertStringContainsString('quantity_' . $store, $this->get('/items/view/' . $itemId)->getBody());
    }

    private function grantTheStore(int $personId, int $store): int
    {
        $this->accountingDb()->table('grants')->insert(['permission_id' => $this->accountingDb()->table('permissions')->where('location_id', $store)->get()->getRow()->permission_id, 'person_id' => $personId, 'menu_group' => 'home']);

        return $personId;
    }

    private function employeeOfEveryBranchWithTheStore(int $store): int
    {
        return $this->grantTheStore($this->createItemsEmployee(), $store);
    }

    public function testGetCountDetails_ListsTheInventoryLogOfTheLocationsOfTheEmployeesBranchesOnly(): void
    {
        ['item' => $itemId, 'store' => $store, 'limited' => $limited] = $this->itemWithAStoreInAnotherBranch();
        $db = $this->accountingDb();

        foreach ([[1, 'Counted on the own shelf'], [$store, 'Found five on the other shelf']] as [$location, $comment]) {
            $db->table('inventory')->insert(['trans_items' => $itemId, 'trans_user' => $limited, 'trans_location' => $location, 'trans_comment' => $comment, 'trans_inventory' => 5, 'trans_date' => date('Y-m-d H:i:s')]);
        }

        $body = $this->get('/items/countDetails/' . $itemId)->getBody();

        $this->assertStringContainsString('Counted on the own shelf', $body);
        $this->assertStringNotContainsString('Found five on the other shelf', $body);

        $this->loginAsItemsEmployee($this->employeeOfEveryBranchWithTheStore($store));
        $body = $this->get('/items/countDetails/' . $itemId)->getBody();

        $this->assertStringContainsString('Counted on the own shelf', $body);
        $this->assertStringContainsString('Found five on the other shelf', $body);
    }

    public function testPostSave_AcceptsANewItemOfAnEmployeeConfinedToABranchAndGivesItAnEmptyShelfInTheOtherBranches(): void
    {
        ['store' => $store, 'branch' => $branch] = $this->itemWithAStoreInAnotherBranch();
        $name     = 'New item ' . uniqid();
        $journals = count($this->adjustmentJournals());

        $result = json_decode($this->post('/items/save', array_merge($this->baseItemPostData(), ['name' => $name, 'tax_names' => ['VAT'], 'tax_percents' => ['0'], 'quantity_1' => '3', 'cost_price' => '8.00']))->getJSON(), true);

        $this->assertTrue($result['success']);

        $itemId = (int) $result['id'];

        $this->assertSame('3.000', $this->onHand($itemId));
        $this->assertSame(1, $this->accountingDb()->table('item_quantities')->where('item_id', $itemId)->where('location_id', $store)->countAllResults());
        $this->assertSame('0.000', $this->onHand($itemId, $store));
        $this->assertSame(0, $this->accountingDb()->table('inventory')->where('trans_items', $itemId)->where('trans_location', $store)->countAllResults());
        $this->assertCount($journals + 1, $this->adjustmentJournals());

        $inTheOtherBranch = $this->grantTheStore($this->createItemsEmployee([$branch]), $store);
        $this->loginAsItemsEmployee($inTheOtherBranch);

        $found = json_decode($this->get('/items/search', ['search' => $name, 'stock_location' => (string) $store, 'limit' => '10', 'offset' => '0', 'order' => 'asc'])->getJSON(), true);

        $this->assertSame(1, $found['total']);
    }

    public function testGetSearch_FallsBackToALocationOfTheEmployeesBranchesWhenAnotherIsAsked(): void
    {
        ['store' => $store] = $this->itemWithAStoreInAnotherBranch();

        $this->get('/items/search', ['search' => '', 'stock_location' => (string) $store, 'limit' => '10', 'offset' => '0', 'order' => 'asc'])->assertStatus(200);

        $this->assertSame('1', (string) session()->get('item_location'));
    }

    public function testGetIndex_IgnoresALocationOfAnotherBranchAskedForInTheUrl(): void
    {
        ['store' => $store, 'limited' => $limited] = $this->itemWithAStoreInAnotherBranch();
        $shelf = $this->storeIn(1, $limited, 'shelf');
        $this->withSession(['person_id' => $limited, 'menu_group' => 'home', 'item_location' => (string) $shelf]);

        $body = $this->get('/items', ['stock_location' => (string) $store])->getBody();

        $this->assertStringContainsString('<option value="' . $shelf . '" selected>', $body);
        $this->assertStringNotContainsString('value="' . $store . '"', $body);
    }

    public function testGetIndex_SelectsALocationOfTheEmployeesBranchesAskedForInTheUrl(): void
    {
        ['limited' => $limited] = $this->itemWithAStoreInAnotherBranch();
        $shelf = $this->storeIn(1, $limited, 'shelf');
        $this->withSession(['person_id' => $limited, 'menu_group' => 'home', 'item_location' => '1']);

        $body = $this->get('/items', ['stock_location' => (string) $shelf])->getBody();

        $this->assertStringContainsString('<option value="' . $shelf . '" selected>', $body);
    }

    public function testPostAdjustment_ReportsAJournalOfABranchTheEmployeeDoesNotWorkInInsteadOfThrowing(): void
    {
        ['item' => $itemId, 'store' => $store, 'limited' => $limited] = $this->itemWithAStoreInAnotherBranch();
        $this->assertTrue($this->item_quantity->changeQuantity($itemId, $store, 4.0));
        $controller = (new ReflectionClass(Items::class))->newInstanceWithoutConstructor();

        $posted = (new ReflectionMethod($controller, 'postAdjustment'))->invoke($controller, $itemId, $store, 4, $limited, 1);

        $this->assertFalse($posted);
        $this->assertStringContainsString(lang('Branches.error_not_allowed'), (new ReflectionMethod($controller, 'adjustmentFailureNote'))->invoke($controller));
        $this->assertSame([], $this->adjustmentJournals());
    }

    public function testGetIndex_SendsAnEmployeeWhoseBranchesHaveNoLocationTheyMayUseToTheNoAccessPage(): void
    {
        ['limited' => $limited] = $this->itemWithAStoreInAnotherBranch();
        $this->accountingDb()->table('grants')->where('person_id', $limited)->where('permission_id', 'items_stock')->delete();
        $this->loginAsItemsEmployee($limited);

        $response = $this->get('/items');

        $response->assertRedirect();
        $this->assertStringContainsString('no_access/items/items_stock', $response->getRedirectUrl());
    }

    public function testGetSearch_SendsAnEmployeeWhoseBranchesHaveNoLocationTheyMayUseToTheNoAccessPage(): void
    {
        ['limited' => $limited] = $this->itemWithAStoreInAnotherBranch();
        $this->accountingDb()->table('grants')->where('person_id', $limited)->where('permission_id', 'items_stock')->delete();
        $this->withSession(['person_id' => $limited, 'menu_group' => 'home']);

        $response = $this->get('/items/search', ['search' => '', 'limit' => '10', 'offset' => '0', 'order' => 'asc']);

        $this->assertSame(302, $response->response()->getStatusCode());
        $this->assertStringContainsString('no_access/items/items_stock', $response->getRedirectUrl());
    }

    public function testPostDelete_RefusesAnItemWithStockInABranchTheEmployeeDoesNotWorkInAndChangesNothing(): void
    {
        ['item' => $itemId, 'store' => $store] = $this->itemWithAStoreInAnotherBranch();
        $this->assertTrue($this->item_quantity->changeQuantity($itemId, $store, 4.0));
        $log = count($this->inventoryLog($itemId));

        $result = json_decode($this->post('/items/delete', ['ids' => [(string) $itemId]])->getJSON(), true);

        $this->assertFalse($result['success']);
        $this->assertStringContainsString(lang('Items.cannot_be_deleted'), $result['message']);
        $this->assertStringContainsString(lang('Branches.error_not_allowed'), $result['message']);
        $this->assertSame(0, (int) $this->item->get_info($itemId)->deleted);
        $this->assertSame('10.000', $this->onHand($itemId));
        $this->assertSame('4.000', $this->onHand($itemId, $store));
        $this->assertSame($log, count($this->inventoryLog($itemId)));
        $this->assertSame([], $this->adjustmentJournals());
    }

    public function testPostDelete_RefusesAnItemWithStockInABranchTheEmployeeDoesNotWorkInWhileAccountingIsOff(): void
    {
        ['item' => $itemId, 'store' => $store] = $this->itemWithAStoreInAnotherBranch();
        $this->assertTrue($this->item_quantity->changeQuantity($itemId, $store, 4.0));
        $this->setConfigValue('accounting_enabled', '0');

        $result = json_decode($this->post('/items/delete', ['ids' => [(string) $itemId]])->getJSON(), true);

        $this->assertFalse($result['success']);
        $this->assertSame(0, (int) $this->item->get_info($itemId)->deleted);
        $this->assertSame('10.000', $this->onHand($itemId));
        $this->assertSame('4.000', $this->onHand($itemId, $store));
    }

    public function testPostDelete_DeletesAnItemWhoseStockIsAllInTheBranchesOfTheEmployeeAndPostsInThem(): void
    {
        $this->itemWithAStoreInAnotherBranch();
        $itemId = $this->item('0.00');

        $this->assertTrue($this->item_quantity->changeQuantity($itemId, 1, 6.0));
        $this->accountingDb()->table('items')->where('item_id', $itemId)->update(['cost_price' => '10.00']);

        $result = json_decode($this->post('/items/delete', ['ids' => [(string) $itemId]])->getJSON(), true);

        $this->assertTrue($result['success']);
        $this->assertSame(1, (int) $this->item->get_info($itemId)->deleted);
        $this->assertSame('0.000', $this->onHand($itemId));
        $this->assertSame([1], array_map(static fn (object $journal): int => (int) $journal->branch_id, $this->adjustmentJournals()));
    }

    public function testPostDelete_LetsAnEmployeeOfEveryBranchDeleteAnItemWithStockInAnyBranch(): void
    {
        ['item' => $itemId, 'store' => $store, 'branch' => $branch] = $this->itemWithAStoreInAnotherBranch();
        $this->assertTrue($this->item_quantity->changeQuantity($itemId, $store, 4.0));
        $this->accountingDb()->table('items')->where('item_id', $itemId)->update(['cost_price' => '10.00']);
        $this->loginAsItemsEmployee($this->createItemsEmployee());

        $result = json_decode($this->post('/items/delete', ['ids' => [(string) $itemId]])->getJSON(), true);

        $this->assertTrue($result['success']);
        $this->assertSame('0.000', $this->onHand($itemId, $store));

        $branches = array_map(static fn (object $journal): int => (int) $journal->branch_id, $this->adjustmentJournals());
        sort($branches);

        $this->assertSame([1, $branch], $branches);
    }

    /**
     * The form of an item with its ten units in the first branch, ticked as deleted.
     *
     * @param array<string, string> $changes
     *
     * @return array<string, mixed>
     */
    private function deletedItemForm(array $changes = []): array
    {
        return array_merge($this->baseItemPostData(), ['tax_names' => ['VAT'], 'tax_percents' => ['0'], 'quantity_1' => '10', 'cost_price' => '50.00', 'is_deleted' => '1'], $changes);
    }

    public function testPostSave_RejectsMarkingDeletedAnItemWithStockInABranchTheEmployeeDoesNotWorkInAndChangesNothing(): void
    {
        ['item' => $itemId, 'store' => $store] = $this->itemWithAStoreInAnotherBranch();
        $this->assertTrue($this->item_quantity->changeQuantity($itemId, $store, 4.0));
        $name = $this->item->get_info($itemId)->name;
        $log  = count($this->inventoryLog($itemId));

        $result = json_decode($this->post('/items/save/' . $itemId, $this->deletedItemForm(['name' => 'Renamed']))->getJSON(), true);

        $this->assertFalse($result['success']);
        $this->assertStringContainsString(lang('Branches.error_not_allowed'), $result['message']);
        $this->assertSame(0, (int) $this->item->get_info($itemId)->deleted);
        $this->assertSame($name, $this->item->get_info($itemId)->name);
        $this->assertSame('10.000', $this->onHand($itemId));
        $this->assertSame('4.000', $this->onHand($itemId, $store));
        $this->assertSame($log, count($this->inventoryLog($itemId)));
        $this->assertSame([], $this->adjustmentJournals());
    }

    public function testPostSave_RejectsMarkingDeletedAnItemWithStockInAnotherBranchWhileAccountingIsOff(): void
    {
        ['item' => $itemId, 'store' => $store] = $this->itemWithAStoreInAnotherBranch();
        $this->assertTrue($this->item_quantity->changeQuantity($itemId, $store, 4.0));
        $this->setConfigValue('accounting_enabled', '0');

        $result = json_decode($this->post('/items/save/' . $itemId, $this->deletedItemForm())->getJSON(), true);

        $this->assertFalse($result['success']);
        $this->assertSame(0, (int) $this->item->get_info($itemId)->deleted);
        $this->assertSame('4.000', $this->onHand($itemId, $store));
    }

    public function testPostSave_AcceptsMarkingDeletedAnItemWhoseStockIsAllInTheBranchesOfTheEmployee(): void
    {
        ['item' => $itemId, 'store' => $store] = $this->itemWithAStoreInAnotherBranch();

        $result = json_decode($this->post('/items/save/' . $itemId, $this->deletedItemForm())->getJSON(), true);

        $this->assertTrue($result['success']);
        $this->assertSame(1, (int) $this->item->get_info($itemId)->deleted);
        $this->assertSame('10.000', $this->onHand($itemId));
        $this->assertSame('0.000', $this->onHand($itemId, $store));
    }

    public function testPostSave_AcceptsMarkingDeletedAnItemWithStockInAnyBranchForAnEmployeeOfEveryBranch(): void
    {
        ['item' => $itemId, 'store' => $store] = $this->itemWithAStoreInAnotherBranch();
        $this->assertTrue($this->item_quantity->changeQuantity($itemId, $store, 4.0));
        $this->loginAsItemsEmployee($this->createItemsEmployee());

        $result = json_decode($this->post('/items/save/' . $itemId, $this->deletedItemForm())->getJSON(), true);

        $this->assertTrue($result['success']);
        $this->assertSame(1, (int) $this->item->get_info($itemId)->deleted);
        $this->assertSame('4.000', $this->onHand($itemId, $store));
    }

    public function testPostSave_AcceptsEditingAnItemThatIsAlreadyDeletedWithStockInAnotherBranch(): void
    {
        ['item' => $itemId, 'store' => $store] = $this->itemWithAStoreInAnotherBranch();
        $this->assertTrue($this->item_quantity->changeQuantity($itemId, $store, 4.0));
        $this->accountingDb()->table('items')->where('item_id', $itemId)->update(['deleted' => 1]);

        $result = json_decode($this->post('/items/save/' . $itemId, $this->deletedItemForm(['name' => 'Renamed while deleted']))->getJSON(), true);

        $this->assertTrue($result['success']);
        $this->assertSame('Renamed while deleted', $this->item->get_info($itemId)->name);
        $this->assertSame(1, (int) $this->item->get_info($itemId)->deleted);
    }

    /**
     * The template of the items file as this employee gets it, as the names of its columns.
     *
     * @return list<string>
     */
    private function importColumnsOfTheEmployee(): array
    {
        $download = $this->get('/items/generateCsvFile')->response();

        ob_start();
        $download->sendBody();
        $file = (string) ob_get_clean();

        $this->assertNotSame('', $file, 'The template is a file with a header line');

        return array_values(array_filter(str_getcsv(trim(substr($file, 3))), 'is_string'));
    }

    /**
     * Sends a file with one row to the import, as a browser would, and answers with what the
     * screen says.
     *
     * @param list<string>          $columns
     * @param array<string, string> $values
     *
     * @return array<string, mixed>
     */
    private function importOneRow(array $columns, array $values): array
    {
        $row  = array_map(static fn (string $column): string => $values[$column] ?? '', $columns);
        $file = tempnam(sys_get_temp_dir(), 'items');
        $handle = fopen($file, 'w');

        fputcsv($handle, $columns);
        fputcsv($handle, $row);
        fclose($handle);

        $_FILES['file_path'] = ['name' => 'items.csv', 'type' => 'text/csv', 'tmp_name' => $file, 'error' => UPLOAD_ERR_OK, 'size' => filesize($file)];

        try {
            return json_decode($this->post('/items/importCsvFile')->getJSON(), true);
        } finally {
            unset($_FILES['file_path']);
            unlink($file);
        }
    }

    public function testPostImportCsvFile_RefusesAColumnOfAStoreInABranchTheEmployeeDoesNotWorkInAndSavesNothing(): void
    {
        ['store' => $store] = $this->itemWithAStoreInAnotherBranch();
        $storeName = $this->accountingDb()->table('stock_locations')->where('location_id', $store)->get()->getRow()->location_name;
        $columns   = $this->importColumnsOfTheEmployee();
        $name      = 'Csv branch item ' . uniqid();

        $this->assertNotContains('location_' . $storeName, $columns, 'The template offers the employee only the stores of their branches');

        $log      = $this->countRows('inventory');
        $journals = count($this->adjustmentJournals());
        $result   = $this->importOneRow([...$columns, 'location_' . $storeName], ['Item Name' => $name, 'Category' => 'Csv', 'Cost Price' => '5', 'Unit Price' => '9', 'location_stock' => '3', 'location_' . $storeName => '2']);

        $this->assertFalse($result['success']);
        $this->assertSame(0, $this->accountingDb()->table('items')->where('name', $name)->countAllResults());
        $this->assertSame($log, $this->countRows('inventory'));
        $this->assertCount($journals, $this->adjustmentJournals());
    }

    public function testPostImportCsvFile_ImportsTheStoresOfTheBranchesTheEmployeeWorksInAndPostsInThem(): void
    {
        ['store' => $store] = $this->itemWithAStoreInAnotherBranch();
        $columns = $this->importColumnsOfTheEmployee();
        $name    = 'Csv own item ' . uniqid();

        $result = $this->importOneRow($columns, ['Item Name' => $name, 'Category' => 'Csv', 'Cost Price' => '5', 'Unit Price' => '9', 'location_stock' => '3']);

        $this->assertTrue($result['success'], json_encode($result));

        $itemId = (int) $this->accountingDb()->table('items')->where('name', $name)->get()->getRow()->item_id;

        $this->assertSame('3.000', $this->onHand($itemId));
        $this->assertSame(1, $this->accountingDb()->table('item_quantities')->where('item_id', $itemId)->where('location_id', $store)->countAllResults());

        $inventoryId = (int) $this->accountingDb()->table('inventory')->where('trans_items', $itemId)->get()->getRow()->trans_id;
        $journal     = $this->accountingDb()->table('accounting_journals')->where('source_type', 'stock_adjustment')->where('source_id', $inventoryId)->get()->getRow();

        $this->assertSame(1, (int) $journal->branch_id);
        $this->assertStockTies();
    }
}
