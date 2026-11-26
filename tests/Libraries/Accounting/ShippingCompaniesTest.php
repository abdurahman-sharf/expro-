<?php

namespace Tests\Libraries\Accounting;

use App\Libraries\Accounting\AccountingError;
use App\Libraries\Accounting\AccountingException;
use App\Libraries\Accounting\AccountingGuard;
use App\Libraries\Accounting\EntityAccountService;
use App\Libraries\Accounting\RoutingKey;
use App\Libraries\Accounting\ShippingCompanies;
use App\Libraries\Organization\BranchException;
use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\DatabaseTestTrait;
use Config\Database;
use Tests\Support\AccountingFixtureTrait;

/**
 * The shipping companies a sale can name for its shipping charge: a name, an optional leaf liability
 * account that no other company uses, an on and off switch, a setting that is off until it is turned
 * on, and the permission and the whole-company scope of whoever changes any of it.
 *
 * @internal
 */
final class ShippingCompaniesTest extends CIUnitTestCase
{
    use DatabaseTestTrait;
    use AccountingFixtureTrait;

    protected $migrate     = true;
    protected $migrateOnce = true;
    protected $refresh     = false;
    protected $namespace   = null;

    private ShippingCompanies $companies;

    public static function setUpBeforeClass(): void
    {
        $seeder = Database::seeder('tests');
        $seeder->call('TestDatabaseBootstrapSeeder');
    }

    protected function setUp(): void
    {
        parent::setUp();

        $this->reinstallChart();
        $this->setConfigValue(ShippingCompanies::ENABLED_KEY, '0');
        $this->companies = new ShippingCompanies();
    }

    protected function tearDown(): void
    {
        $this->releaseOpenTransactions();
        $this->clearJournals();
        $this->setConfigValue(ShippingCompanies::ENABLED_KEY, '0');

        parent::tearDown();
    }

    private function assertFailsWith(AccountingError $expected, callable $action): void
    {
        try {
            $action();
        } catch (AccountingException $exception) {
            $this->assertSame($expected, $exception->error);

            return;
        }

        $this->fail('Expected the failure ' . $expected->value . '.');
    }

    private function payable(string $code = '21121', string $name = 'Owed to Fast Ship'): int
    {
        return $this->customAccount('2112', $code, $name);
    }

    private function manager(?array $branchIds = null): int
    {
        return $this->employeeWithGrants([AccountingGuard::SETTINGS_MANAGE], $branchIds);
    }

    public function testEnabled_IsOffUntilSomeoneTurnsItOnAndTheChangeIsAudited(): void
    {
        $manager = $this->manager();

        $this->assertFalse($this->companies->enabled());

        $this->companies->setEnabled(true, $manager);
        $this->companies->setEnabled(true, $manager);

        $this->assertTrue($this->companies->enabled());

        $entries = $this->accountingDb()->table('accounting_audit_log')->where('entity_type', 'settings')->where('action', 'settings_updated')->get()->getResult();

        $this->assertCount(1, $entries);
        $this->assertSame(['before' => ['shipping_companies' => false], 'after' => ['shipping_companies' => true]], json_decode($entries[0]->details, true));

        $this->companies->setEnabled(false, $manager);

        $this->assertFalse($this->companies->enabled());
    }

    public function testSetEnabled_RequiresThePermissionAndTheWholeCompany(): void
    {
        $this->assertFailsWith(AccountingError::PermissionDenied, fn () => $this->companies->setEnabled(true, $this->employeeWithGrants([])));

        $this->assertFalse($this->companies->enabled());

        $this->expectException(BranchException::class);

        $this->companies->setEnabled(true, $this->manager([1]));
    }

    public function testCreate_AddsACompanyWithoutAnAccountAndWithOne(): void
    {
        $manager = $this->manager();
        $account = $this->payable();

        $plain = $this->companies->create(['name' => '  Fast Ship  '], $manager);
        $owed  = $this->companies->create(['name' => 'Gulf Express', 'account_id' => (string) $account], $manager);

        $this->assertSame('Fast Ship', $this->companies->find($plain)->name);
        $this->assertNull($this->companies->accountOf($plain));
        $this->assertSame($account, $this->companies->accountOf($owed));
        $this->assertSame(['Fast Ship', 'Gulf Express'], array_column($this->companies->all(), 'name'));

        $entry = $this->accountingDb()->table('accounting_audit_log')->where('entity_type', 'shipping_company')->where('entity_id', $owed)->where('action', 'created')->get()->getRow();

        $this->assertSame($manager, (int) $entry->employee_id);
        $this->assertSame($account, json_decode($entry->details, true)['account_id']);
    }

    public function testCreate_RejectsABadNameAndADuplicateOne(): void
    {
        $this->companies->create(['name' => 'Fast Ship']);

        $this->assertFailsWith(AccountingError::InvalidName, fn () => $this->companies->create(['name' => '  ']));
        $this->assertFailsWith(AccountingError::InvalidName, fn () => $this->companies->create(['name' => str_repeat('x', 256)]));
        $this->assertFailsWith(AccountingError::NameTaken, fn () => $this->companies->create(['name' => 'fast ship']));
        $this->assertCount(1, $this->companies->all());
        $this->assertSame(255, mb_strlen($this->companies->find($this->companies->create(['name' => str_repeat('x', 255)]))->name));
    }

    public function testCreate_RejectsAnAccountThatIsNotALeafLiabilityThatIsOnAndFree(): void
    {
        $owed     = $this->payable();
        $inactive = $this->payable('22122', 'Closed');

        $this->accountingDb()->table('accounting_accounts')->where('account_id', $inactive)->update(['active' => 0]);
        $this->companies->create(['name' => 'Fast Ship', 'account_id' => $owed]);

        foreach ([$this->accountId('cash'), $this->accountIdByCode('2112'), $inactive, 999999, $owed] as $account) {
            $this->assertFailsWith(AccountingError::ShippingAccountInvalid, fn () => $this->companies->create(['name' => 'Another' . $account, 'account_id' => $account]));
        }

        $this->assertCount(1, $this->companies->all());
    }

    public function testCreate_RejectsTheAccountOfASupplierAReservedAccountAndARoutedOne(): void
    {
        $supplierAccount = (new EntityAccountService())->ensure(EntityAccountService::SUPPLIER, 7, 'Acme Supplies');
        $reserved        = $this->accountId('tax_output');
        $routed          = $this->payable('22123', 'Owed through a routing row');
        $free            = $this->payable('22124', 'Owed to Fast Ship');

        $this->accountingDb()->table('accounting_routing')->where('routing_key', RoutingKey::NotesPayable->value)->update(['account_id' => $routed]);

        foreach ([$supplierAccount, $reserved, $routed] as $account) {
            $this->assertFailsWith(AccountingError::ShippingAccountInvalid, fn () => $this->companies->create(['name' => 'Another' . $account, 'account_id' => $account]));
        }

        $this->assertSame([], $this->companies->all());
        $this->assertSame($free, $this->companies->accountOf($this->companies->create(['name' => 'Fast Ship', 'account_id' => $free])));
    }

    public function testUpdate_RejectsGivingACompanyTheAccountOfASupplier(): void
    {
        $supplierAccount = (new EntityAccountService())->ensure(EntityAccountService::SUPPLIER, 7, 'Acme Supplies');
        $id              = $this->companies->create(['name' => 'Fast Ship']);

        $this->assertFailsWith(AccountingError::ShippingAccountInvalid, fn () => $this->companies->update($id, ['account_id' => $supplierAccount]));
        $this->assertNull($this->companies->accountOf($id));
    }

    public function testCreate_RequiresThePermissionAndTheWholeCompany(): void
    {
        $this->assertFailsWith(AccountingError::PermissionDenied, fn () => $this->companies->create(['name' => 'Fast Ship'], $this->employeeWithGrants([])));
        $this->assertSame([], $this->companies->all());

        $this->expectException(BranchException::class);

        $this->companies->create(['name' => 'Fast Ship'], $this->manager([1]));
    }

    public function testUpdate_ChangesTheNameAndTheAccountAndAuditsWhatWasThere(): void
    {
        $manager = $this->manager();
        $first   = $this->payable();
        $second  = $this->payable('22122', 'Owed to Gulf');
        $id      = $this->companies->create(['name' => 'Fast Ship', 'account_id' => $first], $manager);

        $this->companies->update($id, ['name' => 'Fast Ship Ltd', 'account_id' => $second], $manager);

        $this->assertSame('Fast Ship Ltd', $this->companies->find($id)->name);
        $this->assertSame($second, $this->companies->accountOf($id));

        $entry = $this->accountingDb()->table('accounting_audit_log')->where('entity_type', 'shipping_company')->where('action', 'updated')->get()->getRow();

        $this->assertSame('Fast Ship', json_decode($entry->details, true)['before']['name']);

        $this->companies->update($id, ['account_id' => '']);

        $this->assertNull($this->companies->accountOf($id));
    }

    public function testUpdate_KeepsTheOwnNameAndAccountAndWritesNothingWhenNothingChanged(): void
    {
        $account = $this->payable();
        $id      = $this->companies->create(['name' => 'Fast Ship', 'account_id' => $account]);

        $this->companies->update($id, ['name' => 'Fast Ship', 'account_id' => $account]);
        $this->companies->update($id, []);

        $this->assertSame(0, $this->accountingDb()->table('accounting_audit_log')->where('entity_type', 'shipping_company')->where('action', 'updated')->countAllResults());
    }

    public function testUpdate_RejectsAnotherCompanysNameAndAccountAndAMissingCompany(): void
    {
        $account = $this->payable();
        $this->companies->create(['name' => 'Fast Ship', 'account_id' => $account]);
        $other = $this->companies->create(['name' => 'Gulf Express']);

        $this->assertFailsWith(AccountingError::NameTaken, fn () => $this->companies->update($other, ['name' => 'Fast Ship']));
        $this->assertFailsWith(AccountingError::ShippingAccountInvalid, fn () => $this->companies->update($other, ['account_id' => $account]));
        $this->assertFailsWith(AccountingError::ShippingCompanyNotFound, fn () => $this->companies->update(999999, ['name' => 'Nobody']));
        $this->assertFailsWith(AccountingError::PermissionDenied, fn () => $this->companies->update($other, ['name' => 'Renamed'], $this->employeeWithGrants([])));
        $this->assertSame('Gulf Express', $this->companies->find($other)->name);
    }

    public function testSetActive_SwitchesACompanyOffAndOnAndRefusesTheSameStateTwice(): void
    {
        $manager = $this->manager();
        $id      = $this->companies->create(['name' => 'Fast Ship'], $manager);

        $this->companies->setActive($id, false, $manager);

        $this->assertSame([], $this->companies->selectable());
        $this->assertCount(1, $this->companies->all());
        $this->assertFailsWith(AccountingError::AlreadyInactive, fn () => $this->companies->setActive($id, false, $manager));

        $this->companies->setActive($id, true, $manager);

        $this->assertCount(1, $this->companies->selectable());
        $this->assertFailsWith(AccountingError::AlreadyActive, fn () => $this->companies->setActive($id, true, $manager));
        $this->assertSame(['deactivated', 'activated'], array_column($this->accountingDb()->table('accounting_audit_log')->where('entity_type', 'shipping_company')->whereIn('action', ['deactivated', 'activated'])->orderBy('audit_id')->get()->getResultArray(), 'action'));
        $this->assertFailsWith(AccountingError::PermissionDenied, fn () => $this->companies->setActive($id, false, $this->employeeWithGrants([])));
        $this->assertFailsWith(AccountingError::ShippingCompanyNotFound, fn () => $this->companies->setActive(999999, false, $manager));
    }

    public function testRequireNameable_AcceptsAnActiveCompanyWhileTheSettingIsOn(): void
    {
        $id = $this->companies->create(['name' => 'Fast Ship']);

        $this->assertFailsWith(AccountingError::ShippingCompaniesOff, fn () => $this->companies->requireNameable($id));

        $this->companies->setEnabled(true);
        $this->companies->requireNameable($id);

        $this->companies->setActive($id, false);

        $this->assertFailsWith(AccountingError::ShippingCompanyInvalid, fn () => $this->companies->requireNameable($id));
        $this->assertFailsWith(AccountingError::ShippingCompanyInvalid, fn () => $this->companies->requireNameable(999999));
    }

    public function testAccountChoices_ListsTheLeafLiabilityAccountsThatAreOn(): void
    {
        $owed     = $this->payable();
        $inactive = $this->payable('22122', 'Closed');

        $this->accountingDb()->table('accounting_accounts')->where('account_id', $inactive)->update(['active' => 0]);

        $choices = $this->companies->accountChoices();

        $this->assertArrayHasKey($owed, $choices);
        $this->assertArrayNotHasKey($inactive, $choices);
        $this->assertArrayNotHasKey($this->accountId('cash'), $choices);
        $this->assertArrayNotHasKey($this->accountIdByCode('2112'), $choices);
        $this->assertSame('22121 - Owed to Fast Ship', $choices[$owed]);
    }

    public function testAccountChoices_LeavesOutSupplierAccountsReservedAccountsAndRoutedOnes(): void
    {
        $supplierAccount = (new EntityAccountService())->ensure(EntityAccountService::SUPPLIER, 7, 'Acme Supplies');
        $routed          = $this->payable('22123', 'Owed through a routing row');
        $free            = $this->payable('22124', 'Owed to Fast Ship');

        $this->accountingDb()->table('accounting_routing')->where('routing_key', RoutingKey::NotesPayable->value)->update(['account_id' => $routed]);

        $choices = $this->companies->accountChoices();

        $this->assertSame([$free], array_keys($choices));
        $this->assertArrayNotHasKey($supplierAccount, $choices);
        $this->assertArrayNotHasKey($this->accountId('tax_output'), $choices);
    }

    public function testAccountOf_IsNothingForACompanyWithoutAnAccountAndForAMissingOne(): void
    {
        $id = $this->companies->create(['name' => 'Fast Ship']);

        $this->assertNull($this->companies->accountOf($id));
        $this->assertNull($this->companies->accountOf(999999));
        $this->assertNull($this->companies->find(999999));
    }
}
