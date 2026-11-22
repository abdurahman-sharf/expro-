<?php

namespace Tests\Database;

use App\Database\Migrations\EInvoiceIntegration;
use App\Database\Migrations\EInvoiceUnitInvoiceTypes;
use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\DatabaseTestTrait;
use Config\Database;
use RuntimeException;

class EInvoiceIntegrationMigrationTest extends CIUnitTestCase
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

        if ($db->tableExists($db->prefixTable('einvoice_credentials'), false)) {
            $db->table('einvoice_credentials')->truncate();
        }

        $db->table('einvoice_counters')->update(['last_icv' => 0, 'last_hash' => '']);
        (new EInvoiceIntegration())->up();
        (new EInvoiceUnitInvoiceTypes())->up();

        parent::tearDown();
    }

    public function testUp_GivesEachCompanyAUnitThatTakesOverItsCounterToTheFigure(): void
    {
        $db = Database::connect();

        (new EInvoiceIntegration())->down();
        $db->table('einvoice_counters')->replace(['company_id' => 1, 'last_icv' => 41, 'last_hash' => 'hash-of-41']);
        (new EInvoiceIntegration())->up();
        (new EInvoiceIntegration())->up();

        $unit    = $db->table('einvoice_units')->where(['company_id' => 1, 'is_default' => 1])->get()->getResult();
        $counter = $db->table('einvoice_unit_counters')->where('unit_id', $unit[0]->unit_id)->get()->getRow();

        $this->assertCount(1, $unit, 'one unit, however often it runs');
        $this->assertSame(['production', '41', 'hash-of-41'], [$counter->environment, (string) $counter->last_icv, $counter->last_hash]);
    }

    public function testDown_RefusesOnceACertificateIsStored(): void
    {
        Database::connect()->table('einvoice_credentials')->insert(['unit_id' => 1, 'environment' => 'sandbox', 'kind' => 'compliance']);

        $this->expectException(RuntimeException::class);

        (new EInvoiceIntegration())->down();
    }
}
