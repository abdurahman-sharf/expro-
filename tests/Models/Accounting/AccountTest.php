<?php

namespace Tests\Models\Accounting;

use App\Models\Accounting\Account;
use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\DatabaseTestTrait;
use Config\Database;

class AccountTest extends CIUnitTestCase
{
    use DatabaseTestTrait;

    protected $migrate     = true;
    protected $migrateOnce = true;
    protected $refresh     = false;
    protected $namespace   = null;

    public static function setUpBeforeClass(): void
    {
        $seeder = Database::seeder('tests');
        $seeder->call('TestDatabaseBootstrapSeeder');
    }

    public function testFindBySystemKey_ReturnsTheSystemAccount(): void
    {
        $account = model(Account::class)->findBySystemKey('cash');

        $this->assertNotNull($account);
        $this->assertSame('120101', $account->code);
        $this->assertSame('asset', $account->account_type);
        $this->assertSame('debit', $account->normal_balance);
    }

    public function testFindBySystemKey_ReturnsNullForAnUnknownKey(): void
    {
        $this->assertNull(model(Account::class)->findBySystemKey('no_such_key'));
    }
}
