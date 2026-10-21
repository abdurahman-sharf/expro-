<?php

namespace Tests\Libraries\Accounting;

use App\Libraries\Accounting\AccountType;
use App\Libraries\Accounting\DefaultChart;
use App\Libraries\Accounting\NormalBalance;
use App\Libraries\Accounting\RoutingKey;
use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\DatabaseTestTrait;
use Config\Database;
use Tests\Support\AccountingFixtureTrait;

class DefaultChartTest extends CIUnitTestCase
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

    public function testInstall_IsInstalledByTheMigration(): void
    {
        $this->assertGreaterThan(30, $this->countRows('accounting_accounts'));
        $this->assertSame(count(RoutingKey::cases()), $this->countRows('accounting_routing'));
    }

    public function testInstall_DoesNothingWhenTheChartAlreadyHasAccounts(): void
    {
        $before = $this->countRows('accounting_accounts');

        $installed = (new DefaultChart($this->accountingDb()))->install();

        $this->assertFalse($installed);
        $this->assertSame($before, $this->countRows('accounting_accounts'));
    }

    public function testInstall_CreatesTheFiveRootGroupsAsSystemAccounts(): void
    {
        $roots = $this->accountingDb()->table('accounting_accounts')->where('parent_id', null)->orderBy('code')->get()->getResult();

        $this->assertSame(
            ['asset', 'liability', 'equity', 'revenue', 'expense'],
            array_column($roots, 'account_type'),
        );

        foreach ($roots as $root) {
            $this->assertSame('1', (string) $root->is_group);
            $this->assertSame('1', (string) $root->is_system);
        }
    }

    public function testInstall_GivesEveryAccountTheNormalBalanceOfItsTypeUnlessContra(): void
    {
        $contraCodes = ['1202', '4102', '4103'];

        foreach ($this->accountingDb()->table('accounting_accounts')->get()->getResult() as $account) {
            $expected = AccountType::from($account->account_type)->normalBalance();

            if (in_array($account->code, $contraCodes, true)) {
                $expected = $expected === NormalBalance::Debit ? NormalBalance::Credit : NormalBalance::Debit;
            }

            $this->assertSame($expected->value, $account->normal_balance, "Account {$account->code}");
        }
    }

    public function testInstall_KeepsEveryChildInTheTypeOfItsParent(): void
    {
        $accounts = [];

        foreach ($this->accountingDb()->table('accounting_accounts')->get()->getResult() as $account) {
            $accounts[(int) $account->account_id] = $account;
        }

        foreach ($accounts as $account) {
            if ($account->parent_id === null) {
                continue;
            }

            $parent = $accounts[(int) $account->parent_id];

            $this->assertSame($parent->account_type, $account->account_type, "Account {$account->code}");
            $this->assertSame('1', (string) $parent->is_group, "Parent of {$account->code} must be a group");
        }
    }

    public function testInstall_MapsEveryRoutingKeyToAnActivePostingAccount(): void
    {
        foreach (RoutingKey::cases() as $key) {
            $row = $this->accountingDb()->table('accounting_routing r')
                ->select('a.is_group, a.active, a.system_key')
                ->join('accounting_accounts a', 'a.account_id = r.account_id')
                ->where('r.routing_key', $key->value)
                ->get()
                ->getRow();

            $this->assertNotNull($row, $key->value);
            $this->assertSame('0', (string) $row->is_group, $key->value);
            $this->assertSame('1', (string) $row->active, $key->value);
            $this->assertSame($key->defaultSystemKey(), $row->system_key, $key->value);
        }
    }

    public function testInstall_RebuildsTheChartOnAnEmptyInstall(): void
    {
        $db = $this->accountingDb();
        $db->query('SET FOREIGN_KEY_CHECKS = 0');
        $db->table('accounting_routing')->truncate();
        $db->table('accounting_accounts')->truncate();
        $db->query('SET FOREIGN_KEY_CHECKS = 1');

        $installed = (new DefaultChart($db))->install();

        $this->assertTrue($installed);
        $this->assertGreaterThan(30, $this->countRows('accounting_accounts'));
        $this->assertSame(count(RoutingKey::cases()), $this->countRows('accounting_routing'));
    }
}
