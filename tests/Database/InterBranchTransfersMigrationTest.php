<?php

namespace Tests\Database;

use App\Database\Migrations\InterBranchTransfers;
use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\DatabaseTestTrait;
use Config\Database;
use Tests\Support\AccountingFixtureTrait;

class InterBranchTransfersMigrationTest extends CIUnitTestCase
{
    use DatabaseTestTrait;
    use AccountingFixtureTrait;

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

        $this->reinstallChart();
    }

    protected function tearDown(): void
    {
        $this->reinstallChart();

        (new InterBranchTransfers())->up();

        parent::tearDown();
    }

    private function hasColumn(): bool
    {
        return $this->accountingDb()->query(
            'SELECT COUNT(*) AS found FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ?',
            [$this->accountingDb()->prefixTable('treasury_transfers'), 'to_branch_id'],
        )->getRow()->found > 0;
    }

    private function accountOf(string $systemKey): ?object
    {
        return $this->accountingDb()->table('accounting_accounts')->where('system_key', $systemKey)->get()->getRow();
    }

    private function parentCode(object $account): string
    {
        return (string) $this->accountingDb()->table('accounting_accounts')->where('account_id', $account->parent_id)->get()->getRow()->code;
    }

    private function routedTo(string $routingKey): ?string
    {
        $row = $this->accountingDb()->table('accounting_routing')->where('routing_key', $routingKey)->get()->getRow();

        return $row === null || $row->account_id === null ? null : (string) $row->account_id;
    }

    /**
     * A chart as it was before the migration: without the two accounts, their routing rows and the column.
     */
    private function chartBefore(): void
    {
        (new InterBranchTransfers())->down();
    }

    public function testUp_OpensTheTwoAccountsInAChartThatHasNoneAndPointsTheRoutingAtThem(): void
    {
        $this->chartBefore();

        $this->assertNull($this->accountOf('branches_receivable'));
        $this->assertFalse($this->hasColumn());

        (new InterBranchTransfers())->up();

        $receivable = $this->accountOf('branches_receivable');
        $payable    = $this->accountOf('branches_payable');

        $this->assertTrue($this->hasColumn());
        $this->assertSame(['12044', '1204', 'asset', 'debit'], [$receivable->code, $this->parentCode($receivable), $receivable->account_type, $receivable->normal_balance]);
        $this->assertSame(['2214', '211', 'liability', 'credit'], [$payable->code, $this->parentCode($payable), $payable->account_type, $payable->normal_balance]);
        $this->assertSame((string) $receivable->account_id, $this->routedTo('branches.receivable'));
        $this->assertSame((string) $payable->account_id, $this->routedTo('branches.payable'));
        $this->assertSame('1', (string) $receivable->is_system);
    }

    public function testUp_ChangesNothingWhenItRunsAgain(): void
    {
        $this->chartBefore();

        (new InterBranchTransfers())->up();

        $accounts = $this->countRows('accounting_accounts');
        $routing  = $this->countRows('accounting_routing');

        (new InterBranchTransfers())->up();

        $this->assertSame($accounts, $this->countRows('accounting_accounts'));
        $this->assertSame($routing, $this->countRows('accounting_routing'));
    }

    public function testUp_TakesTheNextFreeCodeWhenTheCodeIsTaken(): void
    {
        $this->chartBefore();

        $parent = $this->accountingDb()->table('accounting_accounts')->where('code', '1204')->get()->getRow();

        $this->accountingDb()->table('accounting_accounts')->insert(['parent_id' => $parent->account_id, 'code' => '12044', 'name' => 'Taken', 'account_type' => 'asset', 'normal_balance' => 'debit', 'is_group' => 0, 'is_system' => 0, 'active' => 1]);

        (new InterBranchTransfers())->up();

        $this->assertSame('12045', $this->accountOf('branches_receivable')->code);
    }

    public function testUp_LeavesTheChartAloneWhenItHasNoAccountsYet(): void
    {
        $this->chartBefore();
        $this->accountingDb()->query('SET FOREIGN_KEY_CHECKS = 0');
        $this->accountingDb()->table('accounting_routing')->truncate();
        $this->accountingDb()->table('accounting_accounts')->truncate();
        $this->accountingDb()->query('SET FOREIGN_KEY_CHECKS = 1');

        (new InterBranchTransfers())->up();

        $this->assertSame(0, $this->countRows('accounting_accounts'));
        $this->assertTrue($this->hasColumn());
    }
}
