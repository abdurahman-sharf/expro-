<?php

namespace Tests\Libraries;

use App\Libraries\Accounting\LegacyPurchaseDiscount;
use App\Libraries\MigrationPreflight;
use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\DatabaseTestTrait;
use Config\Database;
use Tests\Support\AccountingFixtureTrait;
use Tests\Support\DatabaseFingerprintTrait;
use Tests\Support\EmployeeFixtureTrait;
use Tests\Support\ItemFixtureTrait;
use Tests\Support\MigrationHistoryFixtureTrait;
use Tests\Support\UndecidedPurchaseLinesFixtureTrait;

/**
 * What a web request checks before it starts the migrations, so that it stops with the command to
 * run while the database is still whole, and not half migrated at the one migration that needs the
 * operator.
 */
class MigrationPreflightTest extends CIUnitTestCase
{
    use DatabaseTestTrait;
    use AccountingFixtureTrait;
    use EmployeeFixtureTrait;
    use ItemFixtureTrait;
    use MigrationHistoryFixtureTrait;
    use DatabaseFingerprintTrait;
    use UndecidedPurchaseLinesFixtureTrait;

    protected $migrate     = true;
    protected $migrateOnce = true;
    protected $refresh     = false;
    protected $namespace   = null;

    public static function setUpBeforeClass(): void
    {
        $seeder = Database::seeder('tests');
        $seeder->call('TestDatabaseBootstrapSeeder');
    }

    protected function setUp(): void
    {
        parent::setUp();

        (new LegacyPurchaseDiscount())->clearResolution();
    }

    protected function tearDown(): void
    {
        $this->restoreMigrations();
        $this->removeUndecidedPurchaseLines();

        parent::tearDown();
    }

    public function testRefusal_NamesTheCommandThatDecidesWhenPurchaseLinesAreUndecidedAndTheMigrationIsPending(): void
    {
        $this->leaveUndecidedPurchaseLines();

        $refusal = (new MigrationPreflight())->refusal();

        $this->assertNotNull($refusal);
        $this->assertStringContainsString('php spark accounting:purchase_discount resolve', $refusal);
        $this->assertStringContainsString('Nothing was migrated', $refusal);
        $this->assertStringContainsString('1 purchase line(s)', $refusal);
    }

    public function testRefusal_NamesNoPurchaseAndNoLineToAVisitorOfTheLoginPage(): void
    {
        $this->leaveUndecidedPurchaseLines();

        $this->assertDoesNotMatchRegularExpression('/purchase #\d|line \d+ saved|20\d\d-\d\d-\d\d/', (string) (new MigrationPreflight())->refusal());
    }

    public function testRefusal_IsNullOnceTheDecisionIsRecorded(): void
    {
        $this->leaveUndecidedPurchaseLines();
        (new LegacyPurchaseDiscount())->resolve(LegacyPurchaseDiscount::NONE);

        $this->assertNull((new MigrationPreflight())->refusal());
    }

    public function testRefusal_IsNullWhenTheDiscountMigrationWasApplied(): void
    {
        $this->savePurchaseLineAt('2026-10-01 09:00:00');
        $this->savePurchaseLineAt(date('Y-m-d H:i:s'));

        $this->assertNull((new MigrationPreflight())->refusal());
    }

    public function testRefusal_IsNullWhenNoPurchaseLineIsLeftUndecided(): void
    {
        $this->forgetMigration(self::DISCOUNT_MIGRATION);

        $this->assertNull((new MigrationPreflight())->refusal());
    }

    public function testRefusal_WritesNothing(): void
    {
        $this->leaveUndecidedPurchaseLines();
        $before = $this->databaseFingerprint();

        (new MigrationPreflight())->refusal();

        $this->assertDatabaseUnchanged($before);
    }
}
