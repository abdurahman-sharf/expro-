<?php

namespace Tests\Database;

use App\Database\Migrations\BranchesFoundation;
use App\Libraries\Accounting\PostingService;
use CodeIgniter\Database\Exceptions\DatabaseException;
use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\DatabaseTestTrait;
use Config\Database;
use Tests\Support\AccountingFixtureTrait;

class BranchesFoundationMigrationTest extends CIUnitTestCase
{
    use DatabaseTestTrait;
    use AccountingFixtureTrait;

    private const NEW_TABLES = ['branches', 'companies', 'employee_branches'];

    private const COMPANY_TABLES = [
        'accounting_accounts',
        'accounting_backfill_batches',
        'accounting_closed_periods',
        'accounting_cost_centers',
        'accounting_fiscal_years',
        'accounting_journals',
        'accounting_routing',
        'accounting_sequences',
    ];

    private const BRANCH_TABLES = [
        'accounting_ai_proposals',
        'accounting_audit_log',
        'accounting_closed_periods',
        'accounting_journals',
        'accounting_posting_failures',
        'stock_locations',
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

    private function hasColumn(string $table, string $column): bool
    {
        return $this->accountingDb()->query(
            'SELECT COUNT(*) AS found FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ?',
            [$this->accountingDb()->prefixTable($table), $column],
        )->getRow()->found > 0;
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

    public function testUp_CreatesTheDefaultCompanyAndBranch(): void
    {
        $company = $this->accountingDb()->table('companies')->get()->getRow();
        $branch  = $this->accountingDb()->table('branches')->get()->getRow();

        $this->assertSame('1', (string) $company->company_id);
        $this->assertSame('HQ', $branch->code);
        $this->assertSame('1', (string) $branch->is_default);
        $this->assertSame('1', (string) $branch->company_id);
    }

    public function testUp_PutsEveryExistingStockLocationInTheDefaultBranch(): void
    {
        $this->assertGreaterThan(0, $this->countRows('stock_locations'));
        $this->assertSame(0, $this->accountingDb()->table('stock_locations')->where('branch_id !=', 1)->countAllResults());
    }

    public function testUp_GivesTheExistingAdminTheDefaultBranchAndEveryBranch(): void
    {
        $membership = $this->accountingDb()->table('employee_branches')->where('person_id', 1)->get()->getRow();

        $this->assertSame('1', (string) $membership->branch_id);
        $this->assertSame('1', (string) $membership->is_default);
        $this->assertSame(1, $this->accountingDb()->table('grants')->where('person_id', 1)->where('permission_id', 'org_all_branches')->countAllResults());
    }

    public function testUp_GrantsTheBranchScreensToHoldersOfTheConfigModule(): void
    {
        foreach (['branches', 'org_branches_manage', 'org_scope_manage'] as $permissionId) {
            $this->assertSame(1, $this->accountingDb()->table('grants')->where('person_id', 1)->where('permission_id', $permissionId)->countAllResults(), $permissionId);
        }
    }

    public function testUp_RegistersTheBranchesModuleAndItsPermissions(): void
    {
        $module = $this->accountingDb()->table('modules')->where('module_id', 'branches')->get()->getRow();

        $this->assertSame('module_branches', $module->name_lang_key);
        $this->assertSame('85', (string) $module->sort);

        foreach (['org_branches_manage', 'org_scope_manage', 'org_all_branches'] as $permissionId) {
            $permission = $this->accountingDb()->table('permissions')->where('permission_id', $permissionId)->get()->getRow();

            $this->assertSame('branches', $permission->module_id, $permissionId);
        }
    }

    public function testUp_AddsTheCompanyAndBranchColumnsToTheAccountingTables(): void
    {
        foreach (self::COMPANY_TABLES as $table) {
            $this->assertTrue($this->hasColumn($table, 'company_id'), "{$table}.company_id");
        }

        foreach (self::BRANCH_TABLES as $table) {
            $this->assertTrue($this->hasColumn($table, 'branch_id'), "{$table}.branch_id");
        }
    }

    public function testUp_LetsTwoCompaniesReuseAnAccountCodeButNotOneCompany(): void
    {
        $this->reinstallChart();
        $db = $this->accountingDb();
        $db->table('companies')->insert(['company_id' => 2, 'name' => 'Second company']);

        try {
            $db->table('accounting_accounts')->insert([
                'company_id' => 2, 'code' => '1', 'name' => 'Assets', 'account_type' => 'asset', 'normal_balance' => 'debit', 'is_group' => 1,
            ]);
            $this->assertSame(2, $db->table('accounting_accounts')->where('code', '1')->countAllResults());


            try {
                $db->query('INSERT INTO ' . $db->prefixTable('accounting_accounts') . " (company_id, code, name, account_type, normal_balance) VALUES (2, '1', 'Again', 'asset', 'debit')");
                $this->fail('The code should be unique inside one company.');
            } catch (DatabaseException $exception) {
                $this->assertStringContainsString('company_code', $exception->getMessage());
            }
        } finally {
            $db->query('SET FOREIGN_KEY_CHECKS = 0');
            $db->table('accounting_accounts')->where('company_id', 2)->delete();
            $db->table('companies')->where('company_id', 2)->delete();
            $db->query('SET FOREIGN_KEY_CHECKS = 1');
        }
    }

    public function testUp_IsSafeToRunTwice(): void
    {
        $counts = [
            'branches'          => $this->countRows('branches'),
            'companies'         => $this->countRows('companies'),
            'employee_branches' => $this->countRows('employee_branches'),
            'grants'            => $this->countRows('grants'),
            'permissions'       => $this->countRows('permissions'),
        ];

        (new BranchesFoundation())->up();

        foreach ($counts as $table => $count) {
            $this->assertSame($count, $this->countRows($table), $table);
        }
    }

    public function testDown_ThenUpKeepsEveryExistingJournalAndPutsItInTheDefaultBranch(): void
    {
        $this->reinstallChart();
        $this->setConfigValue('accounting_base_currency', 'SAR');
        $journalId = (new PostingService())->post($this->balancedDraft())->journalId;
        $before    = $this->tableNames();
        $migration = new BranchesFoundation();

        try {
            $migration->down();

            $this->assertSame(array_values(array_diff($before, $this->prefixedNew())), $this->tableNames());
            $this->assertFalse($this->hasColumn('accounting_journals', 'branch_id'));
            $this->assertFalse($this->hasColumn('stock_locations', 'branch_id'));
            $this->assertSame(0, $this->accountingDb()->table('modules')->where('module_id', 'branches')->countAllResults());
            $this->assertSame(1, $this->accountingDb()->table('accounting_journals')->where('journal_id', $journalId)->countAllResults());
        } finally {
            $migration->up();
        }

        $this->assertSame($before, $this->tableNames());

        $journal = $this->accountingDb()->table('accounting_journals')->where('journal_id', $journalId)->get()->getRow();
        $this->assertSame('1', (string) $journal->branch_id);
        $this->assertSame('1', (string) $journal->company_id);
        $this->assertSame(1, $this->accountingDb()->table('grants')->where('person_id', 1)->where('permission_id', 'org_all_branches')->countAllResults());
    }

    /**
     * @return list<string>
     */
    private function prefixedNew(): array
    {
        $prefix = $this->accountingDb()->getPrefix();

        return array_map(static fn (string $table): string => $prefix . $table, self::NEW_TABLES);
    }
}
