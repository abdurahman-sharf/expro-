<?php

namespace Tests\Database;

use App\Database\Migrations\VatSnapshotCharges;
use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\DatabaseTestTrait;
use Config\Database;

class VatSnapshotChargesMigrationTest extends CIUnitTestCase
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
        (new VatSnapshotCharges())->up();

        parent::tearDown();
    }

    private function hasCharges(): bool
    {
        return in_array('charges', array_map(static fn (object $field): string => $field->name, Database::connect()->getFieldData('sales_vat_totals')), true);
    }

    public function testUp_AddsTheChargesOfACategoryAndIsSafeToRunAgain(): void
    {
        (new VatSnapshotCharges())->up();

        $this->assertTrue($this->hasCharges());
    }

    public function testDown_DropsTheColumn(): void
    {
        (new VatSnapshotCharges())->down();

        $this->assertFalse($this->hasCharges());
    }
}
