<?php

namespace Tests\Libraries\Accounting\Readiness;

use App\Libraries\Accounting\Readiness\CheckStatus;
use App\Libraries\Accounting\Readiness\MigrationChecks;
use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\DatabaseTestTrait;
use Config\Database;
use Tests\Support\DatabaseFingerprintTrait;
use Tests\Support\MigrationHistoryFixtureTrait;

class MigrationChecksTest extends CIUnitTestCase
{
    use DatabaseTestTrait;
    use DatabaseFingerprintTrait;
    use MigrationHistoryFixtureTrait;

    protected $migrate     = true;
    protected $migrateOnce = true;
    protected $refresh     = false;
    protected $namespace   = null;

    private const FIRST_MIGRATION_OF_THE_RELEASE            = '20261021000000';
    private const ALTERS_ONLY_TABLES_THE_RELEASE_CREATES = ['AccountingPeriods', 'AccountingAccountNameIndex', 'TreasuryScopes', 'RetroactiveBackfill', 'AiAgentLayer', 'VatSnapshot', 'VatSnapshotCharges', 'EInvoicePrepayments', 'UnitCodes', 'EInvoiceIssuance', 'AdvanceVatReturns', 'EInvoiceIntegration', 'VatReturnReview', 'EInvoiceUnitInvoiceTypes', 'EInvoiceNotificationKinds', 'EInvoiceReissue'];

    private MigrationChecks $checks;

    public static function setUpBeforeClass(): void
    {
        $seeder = Database::seeder('tests');
        $seeder->call('TestDatabaseBootstrapSeeder');
    }

    protected function setUp(): void
    {
        parent::setUp();

        $this->checks = new MigrationChecks(Database::connect());
    }

    protected function tearDown(): void
    {
        $db = Database::connect();

        if (! $db->tableExists('ospos_migrations', false) && $db->tableExists('ospos_migrations_hold', false)) {
            $db->query('RENAME TABLE ospos_migrations_hold TO ospos_migrations');
        }

        $db->table('migrations')->where('version', '20300101000000')->delete();
        $this->restoreMigrations();

        parent::tearDown();
    }

    public function testRun_NamesTheCheckAndItsReference(): void
    {
        $result = $this->checks->run()[0];

        $this->assertSame('migrations', $result->id);
        $this->assertSame('MIGRATIONS', $result->ref);
        $this->assertSame('Migration status', $result->title);
    }

    public function testRun_PassesWhenEveryMigrationIsApplied(): void
    {
        $result = $this->checks->run()[0];

        $this->assertSame(CheckStatus::Pass, $result->status);
        $this->assertSame([], $result->data['pending']);
        $this->assertSame(count(glob(APPPATH . 'Database/Migrations/*.php')), $result->data['files']);
    }

    public function testRun_ReviewsWhenAMigrationIsPendingAndListsIt(): void
    {
        $this->forgetMigration('20261029000000');

        $result = $this->checks->run()[0];

        $this->assertSame(CheckStatus::Review, $result->status);
        $this->assertFalse($result->status->blocks());
        $this->assertStringContainsString('1 migration(s) are pending (1 rewrite data, 0 alter large tables)', $result->summary);
        $this->assertStringContainsString('20261029000000 LegacyFixedPurchaseDiscount [DATA]', $result->findings[0]);
    }

    public function testPending_IsEmptyWhenEveryMigrationIsApplied(): void
    {
        $this->assertSame([], $this->checks->pending());
    }

    public function testPending_MarksWhatRewritesDataWhatAltersLargeTablesAndWhatOnlyAdds(): void
    {
        foreach (['20261021000001', '20261021000002', '20261027000000'] as $version) {
            $this->forgetMigration($version);
        }

        $kinds = array_column($this->checks->pending(), 'kind', 'class');

        $this->assertSame(['AccountingModules' => 'additive', 'BranchesFoundation' => 'data', 'CostPrecision' => 'heavy'], $kinds);
    }

    public function testPending_MarksEverySensitiveMigrationWithItsKind(): void
    {
        $expected = [
            '20261021000002' => ['BranchesFoundation', 'data'],
            '20261022000000' => ['AccountingRouting', 'data'],
            '20261023000000' => ['SalesAccounting', 'heavy'],
            '20261024000000' => ['SalesReceivables', 'heavy'],
            '20261025000000' => ['PurchasesAccounting', 'data'],
            '20261027000000' => ['CostPrecision', 'heavy'],
            '20261028000000' => ['Treasuries', 'heavy'],
            '20261029000000' => ['LegacyFixedPurchaseDiscount', 'data'],
            '20261029000001' => ['ReceivingCostPrecision', 'heavy'],
            '20261031000000' => ['BankAccounts', 'data'],
            '20261102000000' => ['InterBranchTransfers', 'data'],
            '20261103000000' => ['StockTransfersBetweenBranches', 'heavy'],
            '20261120000000' => ['ExpensesAccounting', 'heavy'],
            '20261220000000' => ['PurchaseInvoiceDiscount', 'heavy'],
            '20261240000000' => ['SalesInvoiceDiscount', 'heavy'],
            '20270200000001' => ['CrossBranchPayments', 'heavy'],
        ];

        foreach (array_keys($expected) as $version) {
            $this->forgetMigration((string) $version);
        }

        $kinds = [];

        foreach ($this->checks->pending() as $migration) {
            $kinds[$migration['version']] = [$migration['class'], $migration['kind']];
        }

        $this->assertSame($expected, $kinds);
    }

    public function testPending_ClassifiesEveryMigrationOfTheReleaseThatAltersATable(): void
    {
        $unclassified = [];

        foreach ($this->checks->history() as $row) {
            $this->forgetMigration($row['version']);
        }

        foreach ($this->checks->pending() as $migration) {
            if ($migration['version'] < self::FIRST_MIGRATION_OF_THE_RELEASE || $migration['kind'] !== MigrationChecks::ADDITIVE || in_array($migration['class'], self::ALTERS_ONLY_TABLES_THE_RELEASE_CREATES, true)) {
                continue;
            }

            $source = (string) file_get_contents(APPPATH . "Database/Migrations/{$migration['version']}_{$migration['class']}.php");

            if (str_contains($source, 'ALTER TABLE') || str_contains($source, 'addColumn') || str_contains($source, 'modifyColumn')) {
                $unclassified[] = $migration['version'] . ' ' . $migration['class'];
            }
        }

        $this->assertSame([], $unclassified, 'These migrations alter a table and would be printed as ADDITIVE: classify each in MigrationChecks::SENSITIVE (HEAVY when it alters a table that holds business rows), or list it here when it alters only tables the release creates.');
    }

    public function testPending_ClassifiesTheMigrationThatOpensAccountsOfFixedAssetsAsDataWithItsNote(): void
    {
        $this->forgetMigration('20273500000001');

        $pending = $this->checks->pending();

        $this->assertSame(['20273500000001', 'AssetAccounts', MigrationChecks::DATA], [$pending[0]['version'], $pending[0]['class'], $pending[0]['kind']]);
        $this->assertStringContainsString('4202', $pending[0]['note']);
        $this->assertStringContainsString('assets.cost', $pending[0]['note']);
    }

    public function testPending_ClassifiesEveryMigrationThatOpensAccountsInAnExistingChartAsData(): void
    {
        $unclassified = [];

        foreach ($this->checks->history() as $row) {
            $this->forgetMigration($row['version']);
        }

        foreach ($this->checks->pending() as $migration) {
            if ($migration['version'] < self::FIRST_MIGRATION_OF_THE_RELEASE || $migration['kind'] !== MigrationChecks::ADDITIVE) {
                continue;
            }

            $source = (string) file_get_contents(APPPATH . "Database/Migrations/{$migration['version']}_{$migration['class']}.php");

            if (str_contains($source, "table('accounting_accounts')->insert(")) {
                $unclassified[] = $migration['version'] . ' ' . $migration['class'];
            }
        }

        $this->assertSame([], $unclassified, 'These migrations write accounts into a chart that exists and would be printed as ADDITIVE: classify each in MigrationChecks::SENSITIVE as DATA.');
    }

    public function testPending_ClassifiesTheMigrationOfOpeningBalancesOfCustomersAsHeavyWithItsNote(): void
    {
        $this->forgetMigration('20271000000000');

        $pending = $this->checks->pending();

        $this->assertSame(['20271000000000', 'CustomerOpenings', MigrationChecks::HEAVY], [$pending[0]['version'], $pending[0]['class'], $pending[0]['kind']]);
        $this->assertStringContainsString('customer_credit_applications', $pending[0]['note']);
    }

    public function testPending_GivesTheNoteOfAMigrationThatNeedsOne(): void
    {
        $this->forgetMigration('20261031000000');

        $pending = $this->checks->pending();

        $this->assertSame('BankAccounts', $pending[0]['class']);
        $this->assertStringContainsString('1202', $pending[0]['note']);
    }

    public function testPending_ListsTheMigrationsInTheOrderTheyRun(): void
    {
        foreach (['20270200000001', '20261029000000', '20261103000000'] as $version) {
            $this->forgetMigration($version);
        }

        $this->assertSame(['20261029000000', '20261103000000', '20270200000001'], array_column($this->checks->pending(), 'version'));
    }

    public function testRun_CountsTheMigrationsByKindInTheSummary(): void
    {
        foreach (['20261029000000', '20261031000000', '20261027000000', '20261021000001'] as $version) {
            $this->forgetMigration($version);
        }

        $result = $this->checks->run()[0];

        $this->assertStringContainsString('4 migration(s) are pending (2 rewrite data, 1 alter large tables)', $result->summary);
    }

    public function testAppliedAt_GivesTheMomentAMigrationWasApplied(): void
    {
        $this->assertMatchesRegularExpression('/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}$/', (string) $this->checks->appliedAt('LegacyFixedPurchaseDiscount'));
    }

    public function testAppliedAt_IsNullForAMigrationThatIsPending(): void
    {
        $this->forgetMigration('20261029000000');

        $this->assertNull($this->checks->appliedAt('LegacyFixedPurchaseDiscount'));
    }

    public function testAppliedAt_IsNullForAMigrationThatHasNoFile(): void
    {
        $this->assertNull($this->checks->appliedAt('NoSuchMigration'));
    }

    public function testHistory_ReadsTheVersionOfEveryMigrationThatRan(): void
    {
        $versions = array_column($this->checks->history(), 'version');

        $this->assertContains('20261021000000', $versions);
        $this->assertContains('20270200000001', $versions);
    }

    public function testRun_ReviewsAMigrationRecordedAsAppliedThatHasNoFileHere(): void
    {
        Database::connect()->table('migrations')->insert(['version' => '20300101000000', 'class' => 'App\Database\Migrations\FromANewerRelease', 'group' => 'default', 'namespace' => 'App', 'time' => time(), 'batch' => 99]);

        $result = $this->checks->run()[0];

        $this->assertSame(CheckStatus::Review, $result->status);
        $this->assertSame(['20300101000000'], $result->data['unknown']);
        $this->assertStringContainsString('20300101000000 is recorded as applied but this code has no such migration file', implode("\n", $result->findings));
        $this->assertSame([], $this->checks->pending());
    }

    public function testRun_ReadsAMissingHistoryTableAsEveryMigrationPendingAndNeverCreatesIt(): void
    {
        $db = Database::connect();
        $db->query('RENAME TABLE ospos_migrations TO ospos_migrations_hold');

        $result = $this->checks->run()[0];

        $this->assertSame(CheckStatus::Review, $result->status);
        $this->assertCount(count(glob(APPPATH . 'Database/Migrations/*.php')), $result->data['pending']);
        $this->assertFalse($db->tableExists('ospos_migrations', false), 'Reading the history must not create its table.');
    }

    public function testRun_ChangesNoRow(): void
    {
        $this->forgetMigration('20261029000000');
        $before = $this->databaseFingerprint();

        $this->checks->run();

        $this->assertDatabaseUnchanged($before);
    }
}
