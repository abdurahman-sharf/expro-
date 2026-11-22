<?php

namespace Tests\Database;

use App\Database\Migrations\PurchaseVatVersion;
use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\DatabaseTestTrait;
use Config\Database;
use RuntimeException;

class PurchaseVatVersionMigrationTest extends CIUnitTestCase
{
    use DatabaseTestTrait;

    protected $migrate     = true;
    protected $migrateOnce = true;
    protected $refresh     = false;
    protected $namespace   = null;

    private ?int $receivingId = null;

    public static function setUpBeforeClass(): void
    {
        $seeder = Database::seeder('tests');
        $seeder->call('TestDatabaseBootstrapSeeder');
    }

    protected function tearDown(): void
    {
        if ($this->receivingId !== null) {
            Database::connect()->table('receivings')->where('receiving_id', $this->receivingId)->delete();
        }

        (new PurchaseVatVersion())->up();

        parent::tearDown();
    }

    private function hasColumn(): bool
    {
        return in_array('vat_calc_version', array_map(static fn (object $field): string => $field->name, Database::connect()->getFieldData('receivings')), true);
    }

    public function testUp_AddsTheMarkAndLeavesEveryPurchaseWithoutIt(): void
    {
        $this->assertTrue($this->hasColumn());
        $this->assertSame(0, Database::connect()->table('receivings')->where('vat_calc_version IS NOT NULL', null, false)->countAllResults());
    }

    public function testUp_IsSafeToRunAgain(): void
    {
        (new PurchaseVatVersion())->up();

        $this->assertTrue($this->hasColumn());
    }

    public function testDown_RefusesOnceAPurchaseCarriesTheMark(): void
    {
        $db = Database::connect();
        $db->table('receivings')->insert(['receiving_time' => '2026-11-21 10:00:00', 'employee_id' => 1, 'comment' => '', 'reference' => '', 'vat_calc_version' => 1]);
        $this->receivingId = (int) $db->insertID();

        $this->expectException(RuntimeException::class);

        (new PurchaseVatVersion())->down();
    }

    public function testDown_DropsTheColumn(): void
    {
        (new PurchaseVatVersion())->down();

        $this->assertFalse($this->hasColumn());
    }
}
