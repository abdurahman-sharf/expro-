<?php

namespace Tests\Models;

use App\Models\Dinner_table;
use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\DatabaseTestTrait;
use Config\Database;

/**
 * @internal
 */
final class Dinner_tableTest extends CIUnitTestCase
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

    public function testOccupy_AcceptsASaleWithoutATable(): void
    {
        $this->assertTrue(model(Dinner_table::class)->occupy(null));
    }

    public function testRelease_AcceptsASaleWithoutATable(): void
    {
        $this->assertTrue(model(Dinner_table::class)->release(null));
    }
}
