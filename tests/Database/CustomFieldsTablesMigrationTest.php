<?php

namespace Tests\Database;

use App\Database\Migrations\CustomFieldsTables;
use App\Libraries\Documents\CustomFields;
use CodeIgniter\Database\Exceptions\DatabaseException;
use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\DatabaseTestTrait;
use Config\Database;
use RuntimeException;
use Tests\Support\DatabaseFingerprintTrait;

class CustomFieldsTablesMigrationTest extends CIUnitTestCase
{
    use DatabaseTestTrait;
    use DatabaseFingerprintTrait;

    protected $migrate     = true;
    protected $migrateOnce = true;
    protected $refresh     = false;
    protected $namespace   = null;

    public static function setUpBeforeClass(): void
    {
        $seeder = Database::seeder('tests');
        $seeder->call('TestDatabaseBootstrapSeeder');
    }

    protected function tearDown(): void
    {
        $db = Database::connect();
        $db->query('SET FOREIGN_KEY_CHECKS = 0');
        $db->table('custom_field_values')->truncate();
        $db->table('custom_fields')->truncate();
        $db->query('SET FOREIGN_KEY_CHECKS = 1');

        (new CustomFieldsTables())->up();

        parent::tearDown();
    }

    private function hasTable(string $table): bool
    {
        $db = Database::connect();

        return $db->query('SELECT COUNT(*) AS found FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ?', [$db->prefixTable($table)])->getRow()->found > 0;
    }

    private function refused(callable $action): bool
    {
        try {
            $action();
        } catch (DatabaseException) {
            return true;
        }

        return false;
    }

    public function testUp_CreatesTheTwoTablesTheModuleAndThePermissionWithoutGrantingAnything(): void
    {
        (new CustomFieldsTables())->down();

        $this->assertFalse($this->hasTable('custom_fields'));
        $this->assertFalse($this->hasTable('custom_field_values'));
        $this->assertSame(0, Database::connect()->table('modules')->where('module_id', 'custom_fields')->countAllResults());

        (new CustomFieldsTables())->up();

        $db = Database::connect();

        $this->assertTrue($this->hasTable('custom_fields'));
        $this->assertTrue($this->hasTable('custom_field_values'));
        $this->assertSame('module_custom_fields', $db->table('modules')->where('module_id', 'custom_fields')->get()->getRow()->name_lang_key);
        $this->assertSame('custom_fields', $db->table('permissions')->where('permission_id', CustomFields::PERMISSION)->get()->getRow()->module_id);
        $this->assertSame(0, $db->table('grants')->whereIn('permission_id', ['custom_fields', CustomFields::PERMISSION])->countAllResults());
    }

    public function testUp_IsSafeToRunAgainAndChangesNothingTheSecondTime(): void
    {
        $before = $this->databaseFingerprint();

        (new CustomFieldsTables())->up();
        (new CustomFieldsTables())->up();

        $this->assertDatabaseUnchanged($before, 'Running the migration again changed a row.');
    }

    public function testUp_TouchesNoJournalNoSaleAndNoItem(): void
    {
        $before = $this->databaseFingerprint();

        (new CustomFieldsTables())->down();
        (new CustomFieldsTables())->up();

        $after = $this->databaseFingerprint();

        foreach (['accounting_journals', 'accounting_journal_lines', 'sales', 'sales_items', 'items', 'receivings', 'expenses'] as $table) {
            $this->assertSame($before[Database::connect()->prefixTable($table)] ?? null, $after[Database::connect()->prefixTable($table)] ?? null, $table);
        }
    }

    public function testTable_RefusesAnEntityAndATypeItDoesNotKnow(): void
    {
        $db = Database::connect();

        $this->assertTrue($this->refused(fn () => $db->table('custom_fields')->insert(['entity' => 'customer', 'name' => 'x', 'field_type' => 'text'])));
        $this->assertTrue($this->refused(fn () => $db->table('custom_fields')->insert(['entity' => 'item', 'name' => 'x', 'field_type' => 'map'])));
        $this->assertTrue($this->refused(fn () => $db->table('custom_fields')->insert(['entity' => 'item', 'name' => 'x', 'field_type' => 'text', 'required' => 2])));
    }

    public function testTable_RefusesTwoFieldsOfTheSameKindWithTheSameNameWhateverTheCase(): void
    {
        $db = Database::connect();
        $db->table('custom_fields')->insert(['entity' => 'item', 'name' => 'Colour', 'field_type' => 'text']);

        $this->assertTrue($this->refused(fn () => $db->table('custom_fields')->insert(['entity' => 'item', 'name' => 'colour', 'field_type' => 'text'])));
        $this->assertFalse($this->refused(fn () => $db->table('custom_fields')->insert(['entity' => 'sale', 'name' => 'Colour', 'field_type' => 'text'])));
    }

    public function testTable_RefusesAValueForAFieldThatDoesNotExistAndTheDeletionOfAFieldThatHoldsOne(): void
    {
        $db = Database::connect();

        $this->assertTrue($this->refused(fn () => $db->table('custom_field_values')->insert(['field_id' => 987654, 'entity_id' => 1, 'field_value' => 'x'])));

        $db->table('custom_fields')->insert(['entity' => 'item', 'name' => 'Colour', 'field_type' => 'text']);
        $fieldId = (int) $db->insertID();
        $db->table('custom_field_values')->insert(['field_id' => $fieldId, 'entity_id' => 1, 'field_value' => 'Red']);

        $this->assertTrue($this->refused(fn () => $db->table('custom_fields')->where('field_id', $fieldId)->delete()));
    }

    public function testDown_RefusesToDropDefinitionsThatAreEntered(): void
    {
        Database::connect()->table('custom_fields')->insert(['entity' => 'item', 'name' => 'Colour', 'field_type' => 'text']);

        try {
            (new CustomFieldsTables())->down();
            $this->fail('The migration dropped what the shop entered.');
        } catch (RuntimeException) {
            $this->assertTrue($this->hasTable('custom_fields'));
        }
    }
}
