<?php

namespace Tests\Database;

use App\Database\Migrations\AccountingFoundation;
use App\Database\Migrations\AccountingPeriods;
use App\Database\Migrations\BranchesFoundation;
use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\DatabaseTestTrait;
use Config\Database;
use Tests\Support\AccountingFixtureTrait;

class AccountingFoundationMigrationTest extends CIUnitTestCase
{
    use DatabaseTestTrait;
    use AccountingFixtureTrait;

    private const TABLES = [
        'accounting_accounts',
        'accounting_ai_proposals',
        'accounting_allocations',
        'accounting_audit_log',
        'accounting_backfill_batches',
        'accounting_closed_periods',
        'accounting_cost_centers',
        'accounting_fiscal_years',
        'accounting_journal_lines',
        'accounting_journals',
        'accounting_posting_failures',
        'accounting_routing',
        'accounting_sequences',
    ];

    protected $migrate     = true;
    protected $migrateOnce = true;
    protected $refresh     = false;
    protected $namespace   = null;

    public static function setUpBeforeClass(): void
    {
        $seeder = Database::seeder('tests');
        $seeder->call('TestDatabaseBootstrapSeeder');
    }

    /**
     * @return list<string>
     */
    private function tableNames(): array
    {
        $rows = $this->accountingDb()
            ->query('SELECT TABLE_NAME AS name FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() ORDER BY TABLE_NAME')
            ->getResultArray();

        return array_column($rows, 'name');
    }

    /**
     * @return list<string>
     */
    private function prefixed(array $tables): array
    {
        $prefix = $this->accountingDb()->getPrefix();
        sort($tables);

        return array_map(static fn (string $table): string => $prefix . $table, $tables);
    }

    public function testUp_CreatesAllTheAccountingTables(): void
    {
        $existing = $this->tableNames();

        foreach ($this->prefixed(self::TABLES) as $table) {
            $this->assertContains($table, $existing);
        }
    }

    public function testUp_SeedsTheDisabledSwitchAndTheDefaultChart(): void
    {
        $switch = $this->accountingDb()->table('app_config')->where('key', 'accounting_enabled')->get()->getRow();

        $this->assertSame('0', $switch->value);
        $this->assertGreaterThan(30, $this->countRows('accounting_accounts'));
    }

    public function testUp_IsSafeToRunTwice(): void
    {
        $accounts = $this->countRows('accounting_accounts');
        $routing  = $this->countRows('accounting_routing');

        (new AccountingFoundation())->up();

        $this->assertSame($accounts, $this->countRows('accounting_accounts'));
        $this->assertSame($routing, $this->countRows('accounting_routing'));
        $this->assertSame(1, $this->accountingDb()->table('app_config')->where('key', 'accounting_enabled')->countAllResults());
    }

    public function testDown_RemovesOnlyTheAccountingTablesAndKeys(): void
    {
        $before    = $this->tableNames();
        $migration = new AccountingFoundation();
        $this->setConfigValue('accounting_start_date', '2026-01-01');

        try {
            $migration->down();

            $this->assertSame(
                array_values(array_diff($before, $this->prefixed(self::TABLES))),
                $this->tableNames(),
            );
            $this->assertSame(0, $this->accountingDb()->table('app_config')->like('key', 'accounting_', 'after')->where('key !=', 'accounting_auto_fiscal_year')->countAllResults());
        } finally {
            $migration->up();
            (new BranchesFoundation())->up();
            (new AccountingPeriods())->up();
        }

        $this->assertSame($before, $this->tableNames());
    }
}
