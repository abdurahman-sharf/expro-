<?php

namespace Tests\Database;

use App\Database\Migrations\AdvanceVat;
use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\DatabaseTestTrait;
use Config\Database;
use RuntimeException;

class AdvanceVatMigrationTest extends CIUnitTestCase
{
    use DatabaseTestTrait;

    protected $migrate     = true;
    protected $migrateOnce = true;
    protected $refresh     = false;
    protected $namespace   = null;

    public static function setUpBeforeClass(): void
    {
        Database::seeder('tests')->call('TestDatabaseBootstrapSeeder');
    }

    protected function tearDown(): void
    {
        $db = Database::connect();

        if ($db->tableExists($db->prefixTable('customer_advance_vat'), false)) {
            $db->table('customer_advance_vat')->truncate();
        }

        (new AdvanceVat())->up();

        parent::tearDown();
    }

    public function testUp_CreatesTheTablesAndIsSafeToRunAgain(): void
    {
        (new AdvanceVat())->up();

        $db = Database::connect();

        $this->assertTrue($db->tableExists($db->prefixTable('customer_advance_vat'), false));
        $this->assertTrue($db->tableExists($db->prefixTable('customer_advance_vat_uses'), false));
    }

    public function testDown_RefusesOnceAnAdvanceCarriesVat(): void
    {
        Database::connect()->table('customer_advance_vat')->insert(['advance_id' => 1, 'percent' => '15.000', 'gross' => '115.00', 'taxable' => '100.00', 'vat' => '15.00', 'calc_version' => 1]);

        $this->expectException(RuntimeException::class);

        (new AdvanceVat())->down();
    }

    public function testDown_DropsTheTables(): void
    {
        (new AdvanceVat())->down();

        $db = Database::connect();

        $this->assertFalse($db->tableExists($db->prefixTable('customer_advance_vat'), false));
        $this->assertFalse($db->tableExists($db->prefixTable('customer_advance_vat_uses'), false));
    }
}
