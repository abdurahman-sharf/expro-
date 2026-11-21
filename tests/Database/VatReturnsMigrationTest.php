<?php

namespace Tests\Database;

use App\Database\Migrations\VatReturns;
use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\DatabaseTestTrait;
use Config\Database;
use RuntimeException;

class VatReturnsMigrationTest extends CIUnitTestCase
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
        (new VatReturns())->up();
        Database::connect()->table('vat_returns')->truncate();

        parent::tearDown();
    }

    public function testDown_RefusesOnceAReturnWasApproved(): void
    {
        Database::connect()->table('vat_returns')->insert(['company_id' => 1, 'period_start' => '2026-10-01', 'period_end' => '2026-10-31', 'status' => 'approved', 'boxes' => '{}']);

        $this->expectException(RuntimeException::class);

        (new VatReturns())->down();
    }

    public function testDown_DropsTheDrafts(): void
    {
        (new VatReturns())->down();

        $this->assertFalse(Database::connect()->tableExists(Database::connect()->prefixTable('vat_returns'), false));
    }
}
