<?php

namespace Tests\Libraries\Accounting;

use App\Libraries\Accounting\AccountingError;
use App\Libraries\Accounting\AccountingException;
use App\Libraries\Accounting\AccountingGuard;
use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\DatabaseTestTrait;
use Config\Database;
use Tests\Support\AccountingFixtureTrait;

class AccountingGuardTest extends CIUnitTestCase
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

    public function testHas_AllowsTheSystemItself(): void
    {
        $this->assertTrue((new AccountingGuard())->has(AccountingGuard::JOURNALS_POST, null));
    }

    public function testHas_ReflectsTheGrantsOfTheEmployee(): void
    {
        $employee = $this->employeeWithGrants(['accounting_journals', AccountingGuard::JOURNALS_DRAFT]);
        $guard    = new AccountingGuard();

        $this->assertTrue($guard->has(AccountingGuard::JOURNALS_DRAFT, $employee));
        $this->assertFalse($guard->has(AccountingGuard::JOURNALS_POST, $employee));
    }

    public function testRequire_ThrowsAPermissionErrorWithoutTheGrant(): void
    {
        $employee = $this->employeeWithGrants(['accounting_journals']);

        try {
            (new AccountingGuard())->require(AccountingGuard::JOURNALS_REVERSE, $employee);
            $this->fail('The missing permission should have been refused.');
        } catch (AccountingException $exception) {
            $this->assertSame(AccountingError::PermissionDenied, $exception->error);
            $this->assertSame(AccountingGuard::JOURNALS_REVERSE, $exception->context['permission']);
        }
    }

    public function testRequire_AcceptsAnEmployeeWithTheGrant(): void
    {
        $employee = $this->employeeWithGrants([AccountingGuard::JOURNALS_AUDIT]);

        (new AccountingGuard())->require(AccountingGuard::JOURNALS_AUDIT, $employee);

        $this->addToAssertionCount(1);
    }

    public function testSensitive_IncludesTheDepreciationOfAssetsAndNotTheChangeOfTheRegister(): void
    {
        $this->assertContains(AccountingGuard::ASSETS_DEPRECIATE, AccountingGuard::SENSITIVE);
        $this->assertNotContains(AccountingGuard::ASSETS_MANAGE, AccountingGuard::SENSITIVE);
        $this->assertSame('acc_assets_depreciate', AccountingGuard::ASSETS_DEPRECIATE);
        $this->assertSame('acc_assets_manage', AccountingGuard::ASSETS_MANAGE);
    }

    public function testSensitive_IncludesThePermissionThatManagesTheRegistrationWithZatca(): void
    {
        $this->assertContains(AccountingGuard::EINVOICING_ONBOARD, AccountingGuard::SENSITIVE);
        $this->assertSame('acc_einvoicing_onboard', AccountingGuard::EINVOICING_ONBOARD);
    }
}
