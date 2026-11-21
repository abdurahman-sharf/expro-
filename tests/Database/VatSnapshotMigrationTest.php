<?php

namespace Tests\Database;

use App\Database\Migrations\VatSnapshot;
use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\DatabaseTestTrait;
use Config\Database;
use RuntimeException;

class VatSnapshotMigrationTest extends CIUnitTestCase
{
    use DatabaseTestTrait;

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
        if ($this->hasTable('sales_vat_totals')) {
            Database::connect()->table('sales_vat_totals')->truncate();
        }

        (new VatSnapshot())->up();

        parent::tearDown();
    }

    private function hasTable(string $table): bool
    {
        $db = Database::connect();

        return $db->query('SELECT COUNT(*) AS found FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ?', [$db->prefixTable($table)])->getRow()->found > 0;
    }

    /**
     * @return list<string>
     */
    private function columnsOf(string $table): array
    {
        return array_map(static fn (object $field): string => $field->name, Database::connect()->getFieldData($table));
    }

    public function testUp_AddsTheAmountsOfALineAndLeavesThemEmptyForTheLinesSavedBefore(): void
    {
        foreach (['net', 'vat', 'gross', 'calc_version'] as $column) {
            $this->assertContains($column, $this->columnsOf('sales_items_vat'));
        }

        $this->assertTrue($this->hasTable('sales_vat_totals'));
    }

    public function testUp_IsSafeToRunAgain(): void
    {
        (new VatSnapshot())->up();

        $this->assertContains('net', $this->columnsOf('sales_items_vat'));
    }

    public function testDown_RefusesOnceASaleWasSavedWithItsVatWorkedOnce(): void
    {
        Database::connect()->table('sales_vat_totals')->insert(['sale_id' => 1, 'category' => 'S', 'percent' => '15.000', 'taxable' => '100.00', 'vat' => '15.00', 'calc_version' => 1]);

        $this->expectException(RuntimeException::class);

        (new VatSnapshot())->down();
    }

    public function testDown_DropsTheTableAndTheColumns(): void
    {
        (new VatSnapshot())->down();

        $this->assertFalse($this->hasTable('sales_vat_totals'));
        $this->assertNotContains('net', $this->columnsOf('sales_items_vat'));
    }
}
