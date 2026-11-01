<?php

namespace Tests\Libraries\Accounting;

use App\Libraries\Accounting\AccountingError;
use App\Libraries\Accounting\AccountingException;
use App\Libraries\Accounting\AccountingGuard;
use App\Libraries\Accounting\AccountService;
use App\Libraries\Accounting\EntityAccountService;
use App\Libraries\Accounting\RoutingKey;
use App\Libraries\Accounting\TaxDefinitions;
use App\Libraries\Organization\BranchError;
use App\Libraries\Organization\BranchException;
use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\DatabaseTestTrait;
use Config\Database;
use Tests\Support\AccountingFixtureTrait;

/**
 * The tax list: a unique name that does not change, a mode of its own or none, and a liability account
 * of its own that only whoever may change the routing, in every branch, chooses, from the active
 * liability accounts that take entries and are not kept for another purpose.
 *
 * @internal
 */
final class TaxDefinitionsTest extends CIUnitTestCase
{
    use DatabaseTestTrait;
    use AccountingFixtureTrait;

    protected $migrate     = true;
    protected $migrateOnce = true;
    protected $refresh     = false;
    protected $namespace;
    private TaxDefinitions $taxes;
    private int $router;
    private int $clerk;

    public static function setUpBeforeClass(): void
    {
        $seeder = Database::seeder('tests');
        $seeder->call('TestDatabaseBootstrapSeeder');
    }

    protected function setUp(): void
    {
        parent::setUp();

        $this->reinstallChart();

        $this->taxes  = new TaxDefinitions();
        $this->router = $this->employeeWithGrants([AccountingGuard::ROUTING_MANAGE]);
        $this->clerk  = $this->employeeWithGrants([]);
    }

    protected function tearDown(): void
    {
        $this->accountingDb()->table('tax_definitions')->truncate();
        $this->accountingDb()->table('accounting_audit_log')->where('entity_type', TaxDefinitions::ENTITY)->delete();
        $this->removeInsertedEntities();

        parent::tearDown();
    }

    private function refusal(callable $action): AccountingException|BranchException
    {
        try {
            $action();
        } catch (AccountingException|BranchException $exception) {
            return $exception;
        }

        $this->fail('The change was not refused.');
    }

    private function row(int $id): object
    {
        return $this->accountingDb()->table('tax_definitions')->where('tax_definition_id', $id)->get()->getRow();
    }

    private function audited(int $id, string $action): int
    {
        return $this->accountingDb()->table('accounting_audit_log')->where(['entity_type' => TaxDefinitions::ENTITY, 'entity_id' => $id, 'action' => $action])->countAllResults();
    }

    public function testSave_CreatesATaxWithNoModeAndNoAccountAndAuditsIt(): void
    {
        $id = $this->taxes->save(['tax_name' => 'VAT'], null, $this->clerk);

        $row = $this->row($id);

        $this->assertSame(['VAT', null, null], [$row->tax_name, $row->tax_included, $row->liability_account_id]);
        $this->assertSame(1, $this->audited($id, 'created'));
    }

    public function testSave_ChecksTheNameAndKeepsItUniqueWhateverItsCase(): void
    {
        $this->taxes->save(['tax_name' => 'VAT'], null, $this->clerk);

        foreach (['', '   ', str_repeat('x', 256)] as $name) {
            $this->assertSame(AccountingError::TaxNameInvalid, $this->refusal(fn () => $this->taxes->save(['tax_name' => $name], null, $this->clerk))->error);
        }

        $this->assertSame(AccountingError::TaxNameTaken, $this->refusal(fn () => $this->taxes->save(['tax_name' => 'vat'], null, $this->clerk))->error);
        $this->assertSame(1, $this->countRows('tax_definitions'));
    }

    public function testSave_KeepsTheNameOfATaxWhateverTheInputSays(): void
    {
        $id = $this->taxes->save(['tax_name' => 'VAT'], null, $this->clerk);

        $this->taxes->save(['tax_name' => 'Renamed', 'tax_mode' => TaxDefinitions::INCLUDED], $id, $this->clerk);

        $this->assertSame('VAT', $this->row($id)->tax_name);
    }

    public function testSave_RejectsATaxThatDoesNotExist(): void
    {
        $this->assertSame(AccountingError::TaxDefinitionNotFound, $this->refusal(fn () => $this->taxes->save(['tax_name' => 'x'], 99999, $this->clerk))->error);
    }

    public function testSave_StoresTheModeAsIncludedExcludedOrDefaultAndAuditsEachChange(): void
    {
        $id = $this->taxes->save(['tax_name' => 'VAT', 'tax_mode' => TaxDefinitions::INCLUDED], null, $this->clerk);

        $this->assertSame('1', (string) $this->row($id)->tax_included);

        $this->taxes->save(['tax_mode' => TaxDefinitions::EXCLUDED], $id, $this->clerk);
        $this->assertSame('0', (string) $this->row($id)->tax_included);

        $this->taxes->save(['tax_mode' => TaxDefinitions::DEFAULT], $id, $this->clerk);
        $this->assertNull($this->row($id)->tax_included);

        $this->assertSame(2, $this->audited($id, 'mode_changed'));
    }

    public function testSave_AuditsNothingWhenTheModeStaysAsItWas(): void
    {
        $id = $this->taxes->save(['tax_name' => 'VAT', 'tax_mode' => TaxDefinitions::INCLUDED], null, $this->clerk);

        $this->taxes->save(['tax_mode' => TaxDefinitions::INCLUDED], $id, $this->clerk);
        $this->taxes->save([], $id, $this->clerk);

        $this->assertSame(0, $this->audited($id, 'mode_changed'));
        $this->assertSame('1', (string) $this->row($id)->tax_included);
    }

    public function testSave_RejectsAModeThatIsNeitherDefaultIncludedNorExcluded(): void
    {
        foreach (['yes', '1', 'Included', null, 7] as $mode) {
            $this->assertSame(AccountingError::TaxModeInvalid, $this->refusal(fn () => $this->taxes->save(['tax_name' => 'VAT', 'tax_mode' => $mode], null, $this->clerk))->error);
        }

        $this->assertSame(0, $this->countRows('tax_definitions'));
    }

    public function testSave_GivesTheTaxALiabilityAccountToWhoMayChangeTheRouting(): void
    {
        $account = $this->customAccount('215', '215003', 'Tax on services');
        $id      = $this->taxes->save(['tax_name' => 'Service', 'account_id' => $account], null, $this->router);

        $this->assertSame((string) $account, (string) $this->row($id)->liability_account_id);

        $this->taxes->save(['account_id' => ''], $id, $this->router);

        $this->assertNull($this->row($id)->liability_account_id);
        $this->assertSame(1, $this->audited($id, 'account_changed'));
    }

    public function testSave_AsksForThePermissionAndEveryBranchBeforeAnAccountIsChosen(): void
    {
        $account = $this->customAccount('215', '215003', 'Tax on services');
        $other   = $this->createBranch();
        $limited = $this->employeeWithGrants([AccountingGuard::ROUTING_MANAGE], [$other]);

        $this->assertSame(AccountingError::PermissionDenied, $this->refusal(fn () => $this->taxes->save(['tax_name' => 'Service', 'account_id' => $account], null, $this->clerk))->error);

        $failure = $this->refusal(fn () => $this->taxes->save(['tax_name' => 'Service', 'account_id' => $account], null, $limited));

        $this->assertInstanceOf(BranchException::class, $failure);
        $this->assertSame(BranchError::CompanyScopeNeeded, $failure->error);
        $this->assertSame(0, $this->countRows('tax_definitions'));
    }

    public function testSave_ChangesTheModeOfATaxWithoutAskingForTheRoutingPermission(): void
    {
        $account = $this->customAccount('215', '215003', 'Tax on services');
        $id      = $this->taxes->save(['tax_name' => 'Service', 'account_id' => $account], null, $this->router);

        $this->taxes->save(['tax_mode' => TaxDefinitions::INCLUDED, 'account_id' => (string) $account], $id, $this->clerk);

        $this->assertSame('1', (string) $this->row($id)->tax_included);
        $this->assertSame((string) $account, (string) $this->row($id)->liability_account_id);
    }

    public function testSave_AcceptsOnlyAnActiveLiabilityAccountThatTakesEntriesAndIsNotKeptForAnotherPurpose(): void
    {
        $inactive = $this->customAccount('215', '215004', 'Old tax');

        (new AccountService())->deactivate($inactive);

        $asset = $this->customAccount('11', '1110', 'A custom asset');

        foreach ([$asset, $this->accountIdByCode('215'), $inactive, $this->accountId('cogs'), $this->accountId('sales_revenue'), $this->accountId('tax_input'), $this->accountId('giftcard_liability'), $this->accountId('notes_payable'), 99999] as $accountId) {
            $this->assertSame(AccountingError::TaxLiabilityAccountInvalid, $this->refusal(fn () => $this->taxes->save(['tax_name' => 'x' . $accountId, 'account_id' => $accountId], null, $this->router))->error, 'account ' . $accountId);
        }

        $id = $this->taxes->save(['tax_name' => 'Output', 'account_id' => $this->accountId('tax_output')], null, $this->router);

        $this->assertSame((string) $this->accountId('tax_output'), (string) $this->row($id)->liability_account_id);
        $this->assertSame(1, $this->countRows('tax_definitions'));
    }

    public function testAccountOptions_ListsTheLiabilityAccountsThatTakeEntriesInCodeOrder(): void
    {
        $this->customAccount('215', '215010', 'Tax on services');
        $this->customAccount('215', '215005', 'Tax on food');
        $inactive = $this->customAccount('215', '215004', 'Old tax');

        (new AccountService())->deactivate($inactive);

        $codes = array_column($this->taxes->accountOptions(), 'code');

        $this->assertSame(['215001', '215005', '215010'], array_values(array_intersect($codes, ['215001', '215004', '215005', '215010'])));
        $this->assertNotContains('215004', $codes);
        $this->assertNotContains('215002', $codes, 'the account of tax paid is kept for that');
    }

    public function testAccountOptions_LeavesOutTheSubAccountsOfSuppliersCustomersAndStockLocations(): void
    {
        $supplier = (new EntityAccountService())->ensure(EntityAccountService::SUPPLIER, $this->insertSupplier(), 'Gulf Supplies');
        $codeOf   = fn (int $accountId): string => (string) $this->accountingDb()->table('accounting_accounts')->where('account_id', $accountId)->get()->getRow()->code;

        $this->assertSame('liability', (string) $this->accountingDb()->table('accounting_accounts')->where('account_id', $supplier)->get()->getRow()->account_type, 'a supplier sub-account is of liability');
        $this->assertNotContains($codeOf($supplier), array_column($this->taxes->accountOptions(), 'code'));
        $this->assertSame(AccountingError::TaxLiabilityAccountInvalid, $this->refusal(fn () => $this->taxes->save(['tax_name' => 'Supplier', 'account_id' => $supplier], null, $this->router))->error);
        $this->assertSame(0, $this->countRows('tax_definitions'));
    }

    public function testAccountOptions_LeavesOutACustomAccountTheRoutingGivesToAnotherKey(): void
    {
        $account  = $this->customAccount('215', '215006', 'Gift cards of the shop');
        $original = (int) $this->accountingDb()->table('accounting_routing')->where('routing_key', RoutingKey::GiftcardLiability->value)->get()->getRow()->account_id;

        $this->accountingDb()->table('accounting_routing')->where('routing_key', RoutingKey::GiftcardLiability->value)->update(['account_id' => $account]);

        try {
            $this->assertNotContains('215006', array_column($this->taxes->accountOptions(), 'code'));
            $this->assertSame(AccountingError::TaxLiabilityAccountInvalid, $this->refusal(fn () => $this->taxes->save(['tax_name' => 'Gift', 'account_id' => $account], null, $this->router))->error);
        } finally {
            $this->accountingDb()->table('accounting_routing')->where('routing_key', RoutingKey::GiftcardLiability->value)->update(['account_id' => $original]);
        }
    }

    public function testAccountOptions_KeepsACustomAccountTheRoutingGivesToTheTaxCollected(): void
    {
        $account  = $this->customAccount('215', '215007', 'Tax collected elsewhere');
        $original = (int) $this->accountingDb()->table('accounting_routing')->where('routing_key', RoutingKey::TaxOutput->value)->get()->getRow()->account_id;

        $this->accountingDb()->table('accounting_routing')->where('routing_key', RoutingKey::TaxOutput->value)->update(['account_id' => $account]);

        try {
            $this->assertContains('215007', array_column($this->taxes->accountOptions(), 'code'));
        } finally {
            $this->accountingDb()->table('accounting_routing')->where('routing_key', RoutingKey::TaxOutput->value)->update(['account_id' => $original]);
        }
    }

    public function testMayAssign_IsForWhoHoldsTheRoutingPermissionInEveryBranch(): void
    {
        $other   = $this->createBranch();
        $limited = $this->employeeWithGrants([AccountingGuard::ROUTING_MANAGE], [$other]);

        $this->assertTrue($this->taxes->mayAssign($this->router));
        $this->assertFalse($this->taxes->mayAssign($this->clerk));
        $this->assertFalse($this->taxes->mayAssign($limited));
    }

    public function testDelete_RemovesTheTaxesAndAuditsWhatEachWas(): void
    {
        $first  = $this->taxes->save(['tax_name' => 'VAT', 'tax_mode' => TaxDefinitions::INCLUDED], null, $this->clerk);
        $second = $this->taxes->save(['tax_name' => 'Service'], null, $this->clerk);

        $this->taxes->delete([$first, 0, -3], $this->clerk);
        $this->taxes->delete([], $this->clerk);

        $this->assertNull($this->taxes->find($first));
        $this->assertNotNull($this->taxes->find($second));
        $this->assertSame(1, $this->audited($first, 'deleted'));
        $this->assertSame(0, $this->audited($second, 'deleted'));
    }

    public function testDelete_RequiresThePermissionToChangeTheRoutingForATaxThatHasAnAccountAndDeletesNothingOfTheBatch(): void
    {
        $account = $this->customAccount('215', '215003', 'Tax on services');
        $routed  = $this->taxes->save(['tax_name' => 'Service', 'account_id' => $account], null, $this->router);
        $plain   = $this->taxes->save(['tax_name' => 'VAT'], null, $this->clerk);

        $this->assertSame(AccountingError::PermissionDenied, $this->refusal(fn () => $this->taxes->delete([$plain, $routed], $this->clerk))->error);

        $this->assertNotNull($this->taxes->find($routed));
        $this->assertNotNull($this->taxes->find($plain), 'the batch is refused as a whole');
        $this->assertSame(0, $this->audited($routed, 'deleted'));
        $this->assertSame((string) $account, (string) $this->row($routed)->liability_account_id);
    }

    public function testDelete_RequiresEveryBranchForATaxThatHasAnAccount(): void
    {
        $account = $this->customAccount('215', '215003', 'Tax on services');
        $routed  = $this->taxes->save(['tax_name' => 'Service', 'account_id' => $account], null, $this->router);
        $other   = $this->createBranch();
        $limited = $this->employeeWithGrants([AccountingGuard::ROUTING_MANAGE], [$other]);

        $failure = $this->refusal(fn () => $this->taxes->delete([$routed], $limited));

        $this->assertInstanceOf(BranchException::class, $failure);
        $this->assertSame(BranchError::CompanyScopeNeeded, $failure->error);
        $this->assertNotNull($this->taxes->find($routed));
    }

    public function testDelete_DeletesATaxThatHasAnAccountForWhoMayChangeTheRouting(): void
    {
        $account = $this->customAccount('215', '215003', 'Tax on services');
        $routed  = $this->taxes->save(['tax_name' => 'Service', 'account_id' => $account], null, $this->router);

        $this->taxes->delete([$routed], $this->router);

        $this->assertNull($this->taxes->find($routed));
        $this->assertSame(1, $this->audited($routed, 'deleted'));
    }

    public function testSearch_FindsTaxesByNameInTheOrderAsked(): void
    {
        $this->taxes->save(['tax_name' => 'VAT'], null, $this->clerk);
        $this->taxes->save(['tax_name' => 'Service charge'], null, $this->clerk);

        $all = $this->taxes->search('', 'tax_name', 'asc', 25, 0);
        $one = $this->taxes->search('vat', 'tax_name', 'asc', 25, 0);

        $this->assertSame(2, $all['total']);
        $this->assertSame(['Service charge', 'VAT'], array_column($all['rows'], 'tax_name'));
        $this->assertSame(1, $one['total']);
    }
}
