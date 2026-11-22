<?php

namespace Tests\Database;

use App\Database\Migrations\VatReturnReview;
use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\DatabaseTestTrait;
use Config\Database;

class VatReturnReviewMigrationTest extends CIUnitTestCase
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
        (new VatReturnReview())->up();

        parent::tearDown();
    }

    private function hasManual(): bool
    {
        return Database::connect()->query('SELECT 1 FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ?', [Database::connect()->prefixTable('vat_returns'), 'manual'])->getRow() !== null;
    }

    public function testUp_AddsWhatWasTypedBesideTheBoxesAndCanRunAgain(): void
    {
        (new VatReturnReview())->up();
        (new VatReturnReview())->up();

        $this->assertTrue($this->hasManual());
    }

    public function testDown_TakesItAwayAndCanRunAgain(): void
    {
        (new VatReturnReview())->down();
        (new VatReturnReview())->down();

        $this->assertFalse($this->hasManual());
    }
}
