<?php

namespace Tests\Database;

use App\Database\Migrations\EInvoiceNotificationKinds;
use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\DatabaseTestTrait;
use Config\Database;
use RuntimeException;
use Throwable;

class EInvoiceNotificationKindsMigrationTest extends CIUnitTestCase
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
        Database::connect()->table('notification_rules')->where('name LIKE', 'migration test%')->delete();
        (new EInvoiceNotificationKinds())->up();

        parent::tearDown();
    }

    private function checksAreKept(): bool
    {
        return Database::connect()->query("SELECT 1 FROM information_schema.TABLES WHERE TABLE_SCHEMA = 'information_schema' AND TABLE_NAME = 'CHECK_CONSTRAINTS'")->getRow() !== null;
    }

    private function insert(string $kind): bool
    {
        try {
            return Database::connect()->table('notification_rules')->insert(['company_id' => 1, 'kind' => $kind, 'name' => 'migration test ' . $kind, 'active' => 1]);
        } catch (Throwable) {
            return false;
        }
    }

    public function testUp_TakesTheKindsOfZatcaAndRefusesAnyOtherKindAndCanRunAgain(): void
    {
        (new EInvoiceNotificationKinds())->up();

        $this->assertTrue($this->insert('einvoice_certificate_expiry'));
        $this->assertTrue($this->insert('einvoice_reporting_late'));
        $this->assertSame(! $this->checksAreKept(), $this->insert('something_else'), 'the check names the kinds, where the database keeps checks (not MySQL 5.7)');
    }

    public function testDown_RefusesWhileARuleOfAKindOfZatcaExists(): void
    {
        $this->insert('einvoice_reporting_late');

        $this->expectException(RuntimeException::class);

        (new EInvoiceNotificationKinds())->down();
    }

    public function testDown_GivesBackTheThreeKindsWhenNoRuleOfZatcaExists(): void
    {
        (new EInvoiceNotificationKinds())->down();

        $this->assertTrue($this->insert('cheque_due'));
        $this->assertSame(! $this->checksAreKept(), $this->insert('einvoice_reporting_late'));
    }
}
