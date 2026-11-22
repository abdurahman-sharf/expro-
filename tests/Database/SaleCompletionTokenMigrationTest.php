<?php

namespace Tests\Database;

use App\Database\Migrations\SaleCompletionToken;
use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\DatabaseTestTrait;
use Config\Database;
use RuntimeException;

class SaleCompletionTokenMigrationTest extends CIUnitTestCase
{
    use DatabaseTestTrait;

    protected $migrate     = true;
    protected $migrateOnce = true;
    protected $refresh     = false;
    protected $namespace   = null;

    private ?int $saleId = null;

    public static function setUpBeforeClass(): void
    {
        Database::seeder('tests')->call('TestDatabaseBootstrapSeeder');
    }

    private function hasColumn(string $table, string $column): bool
    {
        $db = Database::connect();

        return $db->query('SELECT 1 FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ?', [$db->prefixTable($table), $column])->getRow() !== null;
    }

    protected function tearDown(): void
    {
        $db = Database::connect();

        if ($this->saleId !== null) {
            $db->table('sales')->where('sale_id', $this->saleId)->delete();
            $this->saleId = null;
        }

        (new SaleCompletionToken())->up();

        parent::tearDown();
    }

    public function testUp_AddsTheTokenAndIsSafeToRunAgain(): void
    {
        (new SaleCompletionToken())->up();

        $this->assertTrue($this->hasColumn('sales', 'completion_token'));
    }

    public function testDown_RefusesOnceASaleCarriesAToken(): void
    {
        $db = Database::connect();

        $db->table('sales')->insert(['employee_id' => 1, 'comment' => '', 'sale_status' => COMPLETED, 'completion_token' => 'migration-test']);
        $this->saleId = (int) $db->insertID();

        $this->expectException(RuntimeException::class);

        (new SaleCompletionToken())->down();
    }

    public function testDown_RemovesTheToken(): void
    {
        (new SaleCompletionToken())->down();

        $this->assertFalse($this->hasColumn('sales', 'completion_token'));
    }
}
