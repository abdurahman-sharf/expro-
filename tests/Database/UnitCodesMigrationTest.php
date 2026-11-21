<?php

namespace Tests\Database;

use App\Database\Migrations\UnitCodes;
use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\DatabaseTestTrait;
use Config\Database;

class UnitCodesMigrationTest extends CIUnitTestCase
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
        (new UnitCodes())->up();

        parent::tearDown();
    }

    private function hasCode(): bool
    {
        return in_array('main_unit_code', array_map(static fn (object $field): string => $field->name, Database::connect()->getFieldData('unit_templates')), true);
    }

    public function testUp_AddsTheCodeOfTheMainUnitAndIsSafeToRunAgain(): void
    {
        (new UnitCodes())->up();

        $this->assertTrue($this->hasCode());
    }

    public function testDown_DropsTheColumn(): void
    {
        (new UnitCodes())->down();

        $this->assertFalse($this->hasCode());
    }
}
