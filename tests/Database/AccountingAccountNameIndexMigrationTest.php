<?php

namespace Tests\Database;

use App\Database\Migrations\AccountingAccountNameIndex;
use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\DatabaseTestTrait;
use Config\Database;
use Tests\Support\AccountingFixtureTrait;

class AccountingAccountNameIndexMigrationTest extends CIUnitTestCase
{
    use DatabaseTestTrait;
    use AccountingFixtureTrait;

    private const INDEX = 'accounting_accounts_parent_name';

    protected $migrate     = true;
    protected $migrateOnce = true;
    protected $refresh     = false;
    protected $namespace   = null;

    public static function setUpBeforeClass(): void
    {
        $seeder = Database::seeder('tests');
        $seeder->call('TestDatabaseBootstrapSeeder');
    }

    protected function tearDown(): void
    {
        if (! $this->indexColumns()) {
            (new AccountingAccountNameIndex())->up();
        }

        parent::tearDown();
    }

    /**
     * @return list<string>
     */
    private function indexColumns(): array
    {
        $rows = $this->accountingDb()->query(
            'SELECT COLUMN_NAME FROM information_schema.STATISTICS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND INDEX_NAME = ? ORDER BY SEQ_IN_INDEX',
            [$this->accountingDb()->prefixTable('accounting_accounts'), $this->accountingDb()->prefixTable(self::INDEX)],
        )->getResultArray();

        return array_column($rows, 'COLUMN_NAME');
    }

    public function testUp_IndexesTheNameUnderItsParent(): void
    {
        $this->assertSame(['parent_id', 'name'], $this->indexColumns());
    }

    public function testUp_LetsTheNameLookupUseTheIndex(): void
    {
        $table = $this->accountingDb()->prefixTable('accounting_accounts');
        $plan  = $this->accountingDb()->query("EXPLAIN SELECT COUNT(*) FROM {$table} WHERE parent_id = 25 AND name = 'x'")->getRow();

        $this->assertContains($this->accountingDb()->prefixTable(self::INDEX), explode(',', (string) $plan->possible_keys));
    }

    public function testUp_IsSafeToRunTwice(): void
    {
        (new AccountingAccountNameIndex())->up();
        (new AccountingAccountNameIndex())->up();

        $this->assertSame(['parent_id', 'name'], $this->indexColumns());
    }

    public function testDown_ThenUpRemovesAndRestoresTheIndex(): void
    {
        $migration = new AccountingAccountNameIndex();

        $migration->down();
        $this->assertSame([], $this->indexColumns());

        $migration->down();
        $this->assertSame([], $this->indexColumns());

        $migration->up();
        $this->assertSame(['parent_id', 'name'], $this->indexColumns());
    }
}
