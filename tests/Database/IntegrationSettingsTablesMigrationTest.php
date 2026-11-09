<?php

namespace Tests\Database;

use App\Database\Migrations\IntegrationSettingsTables;
use App\Libraries\Accounting\AccountingGuard;
use App\Libraries\Integrations\IntegrationAccess;
use CodeIgniter\Database\Exceptions\DatabaseException;
use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\DatabaseTestTrait;
use Config\Database;
use RuntimeException;
use Tests\Support\DatabaseFingerprintTrait;
use Tests\Support\IntegrationFixtureTrait;

class IntegrationSettingsTablesMigrationTest extends CIUnitTestCase
{
    use DatabaseTestTrait;
    use DatabaseFingerprintTrait;
    use IntegrationFixtureTrait;

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

        $this->wipeIntegration();
    }

    protected function tearDown(): void
    {
        $this->wipeIntegration();
        (new IntegrationSettingsTables())->up();

        parent::tearDown();
    }

    private function hasTable(string $table): bool
    {
        $db = Database::connect();

        return $db->query('SELECT COUNT(*) AS found FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ?', [$db->prefixTable($table)])->getRow()->found > 0;
    }

    private function refused(callable $action): bool
    {
        try {
            $action();
        } catch (DatabaseException) {
            return true;
        }

        return false;
    }

    public function testUp_CreatesTheTablesTheModulesAndThePermissionsWithoutGrantingAnything(): void
    {
        (new IntegrationSettingsTables())->down();

        foreach (['payment_methods', 'entity_translations', 'api_keys', 'api_rate_windows', 'notification_rules', 'notifications', 'notification_reads'] as $table) {
            $this->assertFalse($this->hasTable($table), $table);
        }

        (new IntegrationSettingsTables())->up();

        $db = Database::connect();

        foreach (['payment_methods', 'entity_translations', 'api_keys', 'api_rate_windows', 'notification_rules', 'notifications', 'notification_reads'] as $table) {
            $this->assertTrue($this->hasTable($table), $table);
        }

        $this->assertSame('module_integrations', $db->table('modules')->where('module_id', IntegrationAccess::MODULE)->get()->getRow()->name_lang_key);
        $this->assertSame('module_notifications', $db->table('modules')->where('module_id', IntegrationAccess::NOTIFICATIONS_MODULE)->get()->getRow()->name_lang_key);
        $this->assertSame(IntegrationAccess::MODULE, $db->table('permissions')->where('permission_id', IntegrationAccess::MANAGE)->get()->getRow()->module_id);
        $this->assertSame(IntegrationAccess::MODULE, $db->table('permissions')->where('permission_id', AccountingGuard::API_KEYS_MANAGE)->get()->getRow()->module_id);
        $this->assertSame(0, $db->table('grants')->whereIn('permission_id', [IntegrationAccess::MODULE, IntegrationAccess::NOTIFICATIONS_MODULE, IntegrationAccess::MANAGE, AccountingGuard::API_KEYS_MANAGE])->countAllResults());
    }

    public function testUp_RegistersFourPermissionsAndGivesTheModulesASortNoOtherModuleHolds(): void
    {
        $db   = Database::connect();
        $ours = [IntegrationAccess::MODULE, IntegrationAccess::NOTIFICATIONS_MODULE];

        $this->assertEqualsCanonicalizing(
            [IntegrationAccess::MODULE, IntegrationAccess::MANAGE, AccountingGuard::API_KEYS_MANAGE, IntegrationAccess::NOTIFICATIONS_MODULE],
            array_column($db->table('permissions')->select('permission_id')->whereIn('module_id', $ours)->get()->getResultArray(), 'permission_id'),
        );

        $sorts = array_map('intval', array_column($db->table('modules')->select('sort')->whereIn('module_id', $ours)->get()->getResultArray(), 'sort'));

        $this->assertCount(2, array_unique($sorts), 'The two modules differ.');
        $this->assertSame(0, $db->table('modules')->whereIn('sort', $sorts)->whereNotIn('module_id', $ours)->countAllResults(), 'No other module is ordered where these are.');
    }

    public function testUp_IsSafeToRunAgainAndChangesNothingTheSecondTime(): void
    {
        $before = $this->databaseFingerprint();

        (new IntegrationSettingsTables())->up();
        (new IntegrationSettingsTables())->up();

        $this->assertDatabaseUnchanged($before, 'Running the migration again changed a row.');
    }

    public function testUp_TouchesNoJournalNoSaleAndNoItem(): void
    {
        $before = $this->databaseFingerprint();

        (new IntegrationSettingsTables())->down();
        (new IntegrationSettingsTables())->up();

        $after = $this->databaseFingerprint();

        foreach (['accounting_journals', 'accounting_journal_lines', 'sales', 'sales_items', 'sales_payments', 'items', 'receivings', 'expenses', 'customers', 'suppliers'] as $table) {
            $this->assertSame($before[Database::connect()->prefixTable($table)] ?? null, $after[Database::connect()->prefixTable($table)] ?? null, $table);
        }
    }

    public function testTable_RefusesAWayOfPaymentThatIsNotManagedAndAFlagThatIsNotAFlag(): void
    {
        $db = Database::connect();

        $this->assertTrue($this->refused(fn () => $db->table('payment_methods')->insert(['method_code' => 'due', 'enabled' => 1])));
        $this->assertTrue($this->refused(fn () => $db->table('payment_methods')->insert(['method_code' => 'cash', 'enabled' => 2])));
        $this->assertFalse($this->refused(fn () => $db->table('payment_methods')->insert(['method_code' => 'wallet', 'enabled' => 0])));
        $this->assertTrue($this->refused(fn () => $db->table('payment_methods')->insert(['method_code' => 'wallet', 'enabled' => 1])));
    }

    public function testTable_RefusesAKindOfRecordItDoesNotKnowAndTwoNamesForOneRecordInOneLanguage(): void
    {
        $db = Database::connect();

        $this->assertTrue($this->refused(fn () => $db->table('entity_translations')->insert(['entity_kind' => 'sale', 'entity_key' => '1', 'language' => 'en', 'name' => 'x'])));
        $this->assertFalse($this->refused(fn () => $db->table('entity_translations')->insert(['entity_kind' => 'item', 'entity_key' => '1', 'language' => 'en', 'name' => 'x'])));
        $this->assertTrue($this->refused(fn () => $db->table('entity_translations')->insert(['entity_kind' => 'item', 'entity_key' => '1', 'language' => 'en', 'name' => 'y'])));
        $this->assertFalse($this->refused(fn () => $db->table('entity_translations')->insert(['entity_kind' => 'item', 'entity_key' => '1', 'language' => 'de-DE', 'name' => 'y'])));
    }

    public function testTable_RefusesTwoKeysWithOnePrefixAndARateOutsideTheRange(): void
    {
        $db   = Database::connect();
        $base = ['company_id' => 1, 'key_prefix' => 'aabbccdd', 'secret_hash' => str_repeat('a', 64), 'name' => 'k', 'scopes' => 'read:customers'];

        $this->assertFalse($this->refused(fn () => $db->table('api_keys')->insert($base)));
        $this->assertTrue($this->refused(fn () => $db->table('api_keys')->insert($base)));
        $this->assertTrue($this->refused(fn () => $db->table('api_keys')->insert([...$base, 'key_prefix' => '11223344', 'rate_limit' => 0])));
        $this->assertTrue($this->refused(fn () => $db->table('api_keys')->insert([...$base, 'key_prefix' => '11223344', 'rate_limit' => 6001])));
    }

    public function testTable_KeepsOneOpenNotificationForARuleAndASubjectAndManyClosedOnes(): void
    {
        $db = Database::connect();
        $db->table('notification_rules')->insert(['kind' => 'low_stock', 'name' => 'Low']);
        $ruleId = (int) $db->insertID();

        $this->assertFalse($this->refused(fn () => $db->table('notifications')->insert(['rule_id' => $ruleId, 'subject_key' => 'stock:1:1', 'open_key' => 'stock:1:1'])));
        $this->assertTrue($this->refused(fn () => $db->table('notifications')->insert(['rule_id' => $ruleId, 'subject_key' => 'stock:1:1', 'open_key' => 'stock:1:1'])));
        $this->assertFalse($this->refused(fn () => $db->table('notifications')->insert(['rule_id' => $ruleId, 'subject_key' => 'stock:1:1', 'open_key' => null, 'resolved_at' => '2027-01-01 00:00:00'])));
        $this->assertFalse($this->refused(fn () => $db->table('notifications')->insert(['rule_id' => $ruleId, 'subject_key' => 'stock:1:1', 'open_key' => null, 'resolved_at' => '2027-01-02 00:00:00'])));
        $this->assertTrue($this->refused(fn () => $db->table('notification_rules')->where('rule_id', $ruleId)->delete()));
    }

    public function testDown_RefusesToDropKeysThatAreEntered(): void
    {
        Database::connect()->table('api_keys')->insert(['company_id' => 1, 'key_prefix' => 'aabbccdd', 'secret_hash' => str_repeat('a', 64), 'name' => 'k', 'scopes' => 'read:customers']);

        try {
            (new IntegrationSettingsTables())->down();
            $this->fail('The migration dropped what the shop entered.');
        } catch (RuntimeException) {
            $this->assertTrue($this->hasTable('api_keys'));
        }
    }
}
