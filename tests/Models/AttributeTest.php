<?php

namespace Tests\Models;

use App\Models\Attribute;
use App\Models\Item;
use CodeIgniter\Database\BaseConnection;
use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\DatabaseTestTrait;
use Config\Database;
use Tests\Support\AccountingFixtureTrait;
use Tests\Support\TransactionStateTrait;

/**
 * @internal
 */
final class AttributeTest extends CIUnitTestCase
{
    use DatabaseTestTrait;
    use AccountingFixtureTrait;
    use TransactionStateTrait;

    protected $migrate     = true;
    protected $migrateOnce = true;
    protected $refresh     = false;
    protected $namespace   = null;

    private Attribute $attribute;

    public static function setUpBeforeClass(): void
    {
        $seeder = Database::seeder('tests');
        $seeder->call('TestDatabaseBootstrapSeeder');
    }

    protected function setUp(): void
    {
        parent::setUp();

        $this->attribute = model(Attribute::class);
    }

    protected function tearDown(): void
    {
        $this->closeOpenTransaction();

        parent::tearDown();
    }

    private function item(): int
    {
        $data = ['name' => 'Attribute item ' . uniqid(), 'category' => 'Attributes', 'cost_price' => '1.00', 'unit_price' => '2.00', 'deleted' => 0];

        $this->assertTrue(model(Item::class)->save_value($data));

        return (int) $data['item_id'];
    }

    /**
     * @return array{definition_id: int, definition_name: string, definition_type: string, definition_flags: int, deleted: int}
     */
    private function definition(string $type): array
    {
        $data = ['definition_name' => 'Attribute ' . uniqid(), 'definition_type' => $type, 'definition_flags' => 0, 'deleted' => 0];

        $this->assertTrue($this->attribute->saveDefinition($data));

        return $data;
    }

    private function linkedValue(BaseConnection $connection, int $definitionId, int $itemId): ?string
    {
        $row = $connection->table('attribute_links')
            ->select('attribute_values.attribute_value')
            ->join('attribute_values', 'attribute_values.attribute_id = attribute_links.attribute_id')
            ->where('attribute_links.definition_id', $definitionId)
            ->where('attribute_links.item_id', $itemId)
            ->get()
            ->getRow();

        return $row?->attribute_value;
    }

    public function testSaveDefinition_CommitsTheConversionOfADropdownToACheckbox(): void
    {
        $definition = $this->definition(DROPDOWN);
        $large      = $this->item();
        $none       = $this->item();

        $this->assertGreaterThan(0, $this->attribute->saveAttributeValue('Large', $definition['definition_id'], $large, false, DROPDOWN));
        $this->assertGreaterThan(0, $this->attribute->saveAttributeValue('false', $definition['definition_id'], $none, false, DROPDOWN));

        $converted = ['definition_name' => $definition['definition_name'], 'definition_type' => CHECKBOX, 'definition_flags' => 0, 'deleted' => 0];

        $this->assertTrue($this->attribute->saveDefinition($converted, $definition['definition_id']));
        $this->assertNoTransactionIsOpen();

        $other = $this->openSecondConnection(false);

        try {
            $this->assertSame(CHECKBOX, $other->table('attribute_definitions')->where('definition_id', $definition['definition_id'])->get()->getRow()->definition_type);
            $this->assertSame('1', $this->linkedValue($other, $definition['definition_id'], $large));
            $this->assertSame('0', $this->linkedValue($other, $definition['definition_id'], $none));
        } finally {
            $other->close();
        }
    }

    public function testSaveDefinition_CommitsTheConversionOfATextToACheckbox(): void
    {
        $definition = $this->definition(TEXT);
        $yes        = $this->item();
        $no         = $this->item();

        $this->assertGreaterThan(0, $this->attribute->saveAttributeValue('yes', $definition['definition_id'], $yes, false, TEXT));
        $this->assertGreaterThan(0, $this->attribute->saveAttributeValue('false', $definition['definition_id'], $no, false, TEXT));

        $converted = ['definition_name' => $definition['definition_name'], 'definition_type' => CHECKBOX, 'definition_flags' => 0, 'deleted' => 0];

        $this->assertTrue($this->attribute->saveDefinition($converted, $definition['definition_id']));
        $this->assertNoTransactionIsOpen();

        $other = $this->openSecondConnection(false);

        try {
            $this->assertSame(CHECKBOX, $other->table('attribute_definitions')->where('definition_id', $definition['definition_id'])->get()->getRow()->definition_type);
            $this->assertSame('1', $this->linkedValue($other, $definition['definition_id'], $yes));
            $this->assertSame('0', $this->linkedValue($other, $definition['definition_id'], $no));
        } finally {
            $other->close();
        }
    }

    public function testSaveCSVRowAttributeData_ClosesItsTransactionWhenTheLinkCannotBeSaved(): void
    {
        $definition = $this->definition(TEXT);

        $this->assertGreaterThan(0, $this->attribute->saveAttributeValue('Red', $definition['definition_id'], $this->item(), false, TEXT));

        $saved = $this->attribute->saveCSVRowAttributeData([$definition['definition_name'] => 'Red'], ['item_id' => 2147483000], [$definition]);

        $this->assertFalse($saved);
        $this->assertNoTransactionIsOpen();
    }
}
