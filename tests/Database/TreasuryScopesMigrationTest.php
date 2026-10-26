<?php

namespace Tests\Database;

use App\Database\Migrations\TreasuryScopes;
use CodeIgniter\Database\Exceptions\DatabaseException;
use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\DatabaseTestTrait;
use Config\Database;
use Tests\Support\AccountingFixtureTrait;

class TreasuryScopesMigrationTest extends CIUnitTestCase
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

    protected function tearDown(): void
    {
        $this->clearJournals();

        (new TreasuryScopes())->up();

        parent::tearDown();
    }

    private function hasColumn(string $column): bool
    {
        return $this->accountingDb()->query(
            'SELECT COUNT(*) AS found FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ?',
            [$this->accountingDb()->prefixTable('treasuries'), $column],
        )->getRow()->found > 0;
    }

    private function hasTable(): bool
    {
        return $this->accountingDb()->query(
            'SELECT COUNT(*) AS found FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ?',
            [$this->accountingDb()->prefixTable('treasury_scope_members')],
        )->getRow()->found > 0;
    }

    private function treasury(string $name = 'Drawer'): int
    {
        $this->accountingDb()->table('treasuries')->insert(['company_id' => 1, 'branch_id' => 1, 'name' => $name, 'kind' => 'cash']);

        return (int) $this->accountingDb()->insertID();
    }

    public function testUp_GivesEveryTreasuryTheScopeEverybodyForDepositsAndWithdrawals(): void
    {
        $this->assertTrue($this->hasColumn('deposit_scope'));
        $this->assertTrue($this->hasColumn('withdraw_scope'));

        $row = $this->accountingDb()->table('treasuries')->where('treasury_id', $this->treasury())->get()->getRow();

        $this->assertSame(['all', 'all'], [$row->deposit_scope, $row->withdraw_scope]);
    }

    public function testUp_KeepsTheScopeOfTreasuriesThatExistedWhenItRunsAgain(): void
    {
        $id = $this->treasury();

        $this->accountingDb()->table('treasuries')->where('treasury_id', $id)->update(['deposit_scope' => 'staff']);
        $this->accountingDb()->table('treasury_scope_members')->insert(['treasury_id' => $id, 'direction' => 'deposit', 'member_id' => 7]);

        (new TreasuryScopes())->up();

        $this->assertSame('staff', $this->accountingDb()->table('treasuries')->where('treasury_id', $id)->get()->getRow()->deposit_scope);
        $this->assertSame(1, $this->accountingDb()->table('treasury_scope_members')->where('treasury_id', $id)->countAllResults());
    }

    public function testUp_AddsTheColumnsToATreasuryTableThatLacksThemWithoutLosingATreasury(): void
    {
        $id        = $this->treasury('Old drawer');
        $migration = new TreasuryScopes();

        try {
            $migration->down();

            $this->assertFalse($this->hasColumn('deposit_scope'));
            $this->assertSame(1, $this->accountingDb()->table('treasuries')->where('treasury_id', $id)->countAllResults());

            $migration->up();

            $row = $this->accountingDb()->table('treasuries')->where('treasury_id', $id)->get()->getRow();

            $this->assertSame(['all', 'all'], [$row->deposit_scope, $row->withdraw_scope], 'A treasury that existed before is open to everybody');
        } finally {
            $migration->up();
        }
    }

    public function testUp_RefusesADirectionThatIsNotDepositOrWithdraw(): void
    {
        $id = $this->treasury();

        $this->expectException(DatabaseException::class);

        $this->accountingDb()->table('treasury_scope_members')->insert(['treasury_id' => $id, 'direction' => 'transfer', 'member_id' => 1]);
    }

    public function testUp_RefusesTheSameMemberTwiceInOneDirectionButNotInTheOther(): void
    {
        $id = $this->treasury();

        $this->accountingDb()->table('treasury_scope_members')->insert(['treasury_id' => $id, 'direction' => 'deposit', 'member_id' => 5]);
        $this->accountingDb()->table('treasury_scope_members')->insert(['treasury_id' => $id, 'direction' => 'withdraw', 'member_id' => 5]);

        $this->expectException(DatabaseException::class);

        $this->accountingDb()->table('treasury_scope_members')->insert(['treasury_id' => $id, 'direction' => 'deposit', 'member_id' => 5]);
    }

    public function testUp_TakesTheMembersAwayWithTheirTreasury(): void
    {
        $id = $this->treasury();

        $this->accountingDb()->table('treasury_scope_members')->insert(['treasury_id' => $id, 'direction' => 'deposit', 'member_id' => 5]);
        $this->accountingDb()->table('treasuries')->where('treasury_id', $id)->delete();

        $this->assertSame(0, $this->accountingDb()->table('treasury_scope_members')->where('treasury_id', $id)->countAllResults());
    }

    public function testDown_TakesTheColumnsAndTheTableAway(): void
    {
        $migration = new TreasuryScopes();

        try {
            $migration->down();

            $this->assertFalse($this->hasColumn('deposit_scope'));
            $this->assertFalse($this->hasColumn('withdraw_scope'));
            $this->assertFalse($this->hasTable());

            $migration->down();
        } finally {
            $migration->up();
        }

        $this->assertTrue($this->hasColumn('deposit_scope'));
        $this->assertTrue($this->hasTable());
    }
}
