<?php

namespace Tests\Database;

use App\Database\Migrations\EInvoiceUnitInvoiceTypes;
use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\DatabaseTestTrait;
use Config\Database;

class EInvoiceUnitInvoiceTypesMigrationTest extends CIUnitTestCase
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
        (new EInvoiceUnitInvoiceTypes())->up();

        parent::tearDown();
    }

    public function testUp_GivesEveryUnitBothKindsOfInvoicesAndCanRunAgain(): void
    {
        (new EInvoiceUnitInvoiceTypes())->down();
        (new EInvoiceUnitInvoiceTypes())->up();
        (new EInvoiceUnitInvoiceTypes())->up();

        $this->assertSame('1100', (string) Database::connect()->table('einvoice_units')->where('is_default', 1)->get()->getRow()->invoice_types);
    }
}
