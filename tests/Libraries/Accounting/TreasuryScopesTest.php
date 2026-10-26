<?php

namespace Tests\Libraries\Accounting;

use App\Libraries\Accounting\AccountingError;
use App\Libraries\Accounting\AccountingException;
use App\Libraries\Accounting\AccountingGuard;
use App\Libraries\Accounting\PaymentType;
use App\Libraries\Accounting\TreasuryScopes;
use App\Libraries\Accounting\TreasuryService;
use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\DatabaseTestTrait;
use Config\Database;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Support\AccountingFixtureTrait;
use Tests\Support\EmployeeFixtureTrait;
use Tests\Support\ItemFixtureTrait;
use Tests\Support\ParityFixture;
use Tests\Support\PurchaseAccountingFixtureTrait;
use Tests\Support\SaleAccountingFixtureTrait;

/**
 * Who may put money into a treasury and who may take money out of it: everybody, a list of
 * employees, or the employees of a list of branches, apart for deposits and for withdrawals, and
 * apart from the permission to manage treasuries.
 */
class TreasuryScopesTest extends CIUnitTestCase
{
    use DatabaseTestTrait;
    use AccountingFixtureTrait;
    use EmployeeFixtureTrait;
    use ItemFixtureTrait;
    use SaleAccountingFixtureTrait;
    use PurchaseAccountingFixtureTrait;

    protected $migrate     = true;
    protected $migrateOnce = true;
    protected $refresh     = false;
    protected $namespace   = null;

    private TreasuryService $treasuries;
    private TreasuryScopes $scopes;

    public static function setUpBeforeClass(): void
    {
        $seeder = Database::seeder('tests');
        $seeder->call('TestDatabaseBootstrapSeeder');
    }

    protected function setUp(): void
    {
        parent::setUp();

        $this->startBuying();
        $this->treasuries = new TreasuryService();
        $this->scopes     = new TreasuryScopes();
    }

    protected function tearDown(): void
    {
        $this->stopBuying();

        parent::tearDown();
    }

    private function manager(): int
    {
        return $this->employeeWithGrants([AccountingGuard::TREASURIES_MANAGE]);
    }

    private function drawer(array $scopes = [], string $name = 'Drawer'): int
    {
        return $this->treasuries->create(['name' => $name, 'kind' => 'cash'] + $scopes, $this->manager());
    }

    private function refusal(callable $action): AccountingException
    {
        try {
            $action();
        } catch (AccountingException $exception) {
            return $exception;
        }

        $this->fail('The change was not refused.');
    }

    public function testAllows_LetsEverybodyUseATreasuryThatWasGivenNoScope(): void
    {
        $id = $this->drawer();

        $this->assertSame(['mode' => 'all', 'members' => [], 'exists' => true], $this->scopes->scopeOf($id, TreasuryScopes::DEPOSIT));
        $this->assertSame(['mode' => 'all', 'members' => [], 'exists' => true], $this->scopes->scopeOf($id, TreasuryScopes::WITHDRAW));
        $this->assertTrue($this->scopes->allows($id, TreasuryScopes::DEPOSIT, $this->employeeWithGrants([])));
        $this->assertTrue($this->scopes->allows($id, TreasuryScopes::WITHDRAW, $this->employeeWithGrants([])));
    }

    /**
     * @return array<string, array{array<string, mixed>}>
     */
    public static function referenceDefaultScopes(): array
    {
        return ParityFixture::cases('T-24', 'treasury_scope');
    }

    /**
     * @param array<string, mixed> $case
     */
    #[DataProvider('referenceDefaultScopes')]
    public function testScopeOf_StartsAsTheReferenceSystemDoesWithEverybodyForBothDirections(array $case): void
    {
        $id = $this->drawer();

        $this->assertSame($case['expected'], [
            'deposit'  => $this->scopes->scopeOf($id, TreasuryScopes::DEPOSIT)['mode'],
            'withdraw' => $this->scopes->scopeOf($id, TreasuryScopes::WITHDRAW)['mode'],
        ]);
        $this->assertSame($case['reference'], $case['expected']);
    }

    /**
     * @return array<string, array{array<string, mixed>}>
     */
    public static function referenceScopeKinds(): array
    {
        return ParityFixture::cases('T-24', 'treasury_scope_kinds');
    }

    /**
     * The reference system also offers roles, and the application has no roles to offer, so that
     * scope is refused here and the scopes that are kept are the others.
     *
     * @param array<string, mixed> $case
     */
    #[DataProvider('referenceScopeKinds')]
    public function testValidated_DiffersFromTheReferenceSystemByHavingNoScopeOfRoles(array $case): void
    {
        $this->assertSame('differs', $case['classification']);
        $this->assertContains('roles', $case['reference']['scopes']);
        $this->assertSame($case['expected']['scopes'], TreasuryScopes::MODES);

        foreach ($case['expected']['refused'] as $kind) {
            $this->assertSame(AccountingError::TreasuryScopeInvalid, $this->refusal(fn () => $this->scopes->validated(['deposit_scope' => $kind, 'deposit_members' => [1]]))->error, $kind);
        }

        $this->assertSame(array_values(array_diff($case['reference']['scopes'], $case['expected']['refused'])), $case['expected']['scopes']);
    }

    public function testAllows_AlwaysLetsTheBooksActWithoutAnEmployee(): void
    {
        $other = $this->employeeWithGrants([]);
        $id    = $this->drawer(['deposit_scope' => 'staff', 'deposit_members' => [$other], 'withdraw_scope' => 'staff', 'withdraw_members' => [$other]]);

        $this->assertTrue($this->scopes->allows($id, TreasuryScopes::DEPOSIT, null));
        $this->assertTrue($this->scopes->allows($id, TreasuryScopes::WITHDRAW, null));
    }

    public function testAllows_RefusesATreasuryThatDoesNotExist(): void
    {
        $this->assertFalse($this->scopes->allows(999999, TreasuryScopes::DEPOSIT, $this->employeeWithGrants([])));
    }

    public function testAllows_LetsOnlyTheEmployeesNamedUseATreasuryOfTheStaffScope(): void
    {
        $named   = $this->employeeWithGrants([]);
        $another = $this->employeeWithGrants([]);
        $id      = $this->drawer(['deposit_scope' => 'staff', 'deposit_members' => [$named]]);

        $this->assertTrue($this->scopes->allows($id, TreasuryScopes::DEPOSIT, $named));
        $this->assertFalse($this->scopes->allows($id, TreasuryScopes::DEPOSIT, $another));
    }

    public function testAllows_KeepsDepositAndWithdrawalApart(): void
    {
        $depositor  = $this->employeeWithGrants([]);
        $withdrawer = $this->employeeWithGrants([]);
        $id         = $this->drawer(['deposit_scope' => 'staff', 'deposit_members' => [$depositor], 'withdraw_scope' => 'staff', 'withdraw_members' => [$withdrawer]]);

        $this->assertTrue($this->scopes->allows($id, TreasuryScopes::DEPOSIT, $depositor));
        $this->assertFalse($this->scopes->allows($id, TreasuryScopes::WITHDRAW, $depositor));
        $this->assertTrue($this->scopes->allows($id, TreasuryScopes::WITHDRAW, $withdrawer));
        $this->assertFalse($this->scopes->allows($id, TreasuryScopes::DEPOSIT, $withdrawer));
    }

    public function testAllows_LetsTheEmployeesOfTheBranchesNamedUseATreasuryOfTheBranchScope(): void
    {
        $second   = $this->createBranch('B2');
        $third    = $this->createBranch('B3');
        $inSecond = $this->employeeWithGrants([], [$second]);
        $inThird  = $this->employeeWithGrants([], [$third]);
        $both     = $this->employeeWithGrants([], [$third, $second]);
        $everyone = $this->employeeWithGrants([]);
        $id       = $this->drawer(['withdraw_scope' => 'branches', 'withdraw_members' => [$second]]);

        $this->assertTrue($this->scopes->allows($id, TreasuryScopes::WITHDRAW, $inSecond));
        $this->assertFalse($this->scopes->allows($id, TreasuryScopes::WITHDRAW, $inThird));
        $this->assertTrue($this->scopes->allows($id, TreasuryScopes::WITHDRAW, $both));
        $this->assertTrue($this->scopes->allows($id, TreasuryScopes::WITHDRAW, $everyone), 'Who works in every branch works in the one named');
        $this->assertTrue($this->scopes->allows($id, TreasuryScopes::DEPOSIT, $inThird), 'Deposits were not limited');
    }

    public function testAllows_RefusesEveryoneWhenAListIsEmpty(): void
    {
        $employee = $this->employeeWithGrants([]);
        $id       = $this->drawer();

        $this->accountingDb()->table('treasuries')->where('treasury_id', $id)->update(['deposit_scope' => 'staff', 'withdraw_scope' => 'branches']);

        $this->assertFalse($this->scopes->allows($id, TreasuryScopes::DEPOSIT, $employee));
        $this->assertFalse($this->scopes->allows($id, TreasuryScopes::WITHDRAW, $employee));
    }

    public function testAllows_RefusesEveryoneWhenTheScopeIsNotOneThatIsKnown(): void
    {
        $id = $this->drawer();

        $this->accountingDb()->table('treasuries')->where('treasury_id', $id)->update(['deposit_scope' => 'roles']);

        $this->assertFalse($this->scopes->allows($id, TreasuryScopes::DEPOSIT, $this->employeeWithGrants([])));
    }

    public function testAllows_DoesNotDependOnThePermissionToManageTreasuries(): void
    {
        $named   = $this->employeeWithGrants([]);
        $manager = $this->manager();
        $id      = $this->drawer(['deposit_scope' => 'staff', 'deposit_members' => [$named], 'withdraw_scope' => 'staff', 'withdraw_members' => [$named]]);

        $this->assertTrue($this->scopes->allows($id, TreasuryScopes::DEPOSIT, $named), 'A member without the permission to manage may deposit');
        $this->assertFalse($this->scopes->allows($id, TreasuryScopes::DEPOSIT, $manager), 'The permission to manage gives no deposit');
        $this->assertFalse($this->scopes->allows($id, TreasuryScopes::WITHDRAW, $manager), 'The permission to manage gives no withdrawal');
    }

    public function testAssertAllows_SaysWhichTreasuryAndWhichDirectionWasRefused(): void
    {
        $named = $this->employeeWithGrants([]);
        $id    = $this->drawer(['deposit_scope' => 'staff', 'deposit_members' => [$named], 'withdraw_scope' => 'staff', 'withdraw_members' => [$named]], 'Front drawer');
        $other = $this->employeeWithGrants([]);

        $deposit  = $this->refusal(fn () => $this->scopes->assertAllows($id, TreasuryScopes::DEPOSIT, $other));
        $withdraw = $this->refusal(fn () => $this->scopes->assertAllows($id, TreasuryScopes::WITHDRAW, $other));

        $this->assertSame(AccountingError::TreasuryDepositDenied, $deposit->error);
        $this->assertSame(AccountingError::TreasuryWithdrawDenied, $withdraw->error);
        $this->assertSame('Front drawer', $deposit->context['name']);
        $this->assertSame($id, $deposit->context['treasury_id']);

        $this->scopes->assertAllows($id, TreasuryScopes::DEPOSIT, $named);
        $this->addToAssertionCount(1);
    }

    public function testAssertMainAllows_AppliesTheScopeOfTheMainTreasuryToAPaymentThatNamesNoTreasury(): void
    {
        $named = $this->employeeWithGrants([]);
        $other = $this->employeeWithGrants([]);
        $main  = $this->treasuries->ensureMain();

        $this->treasuries->update($main, ['name' => 'Main', 'kind' => 'cash', 'deposit_scope' => 'staff', 'deposit_members' => [$named]], $this->manager());

        $this->scopes->assertMainAllows(PaymentType::Cash, TreasuryScopes::DEPOSIT, $named);
        $this->assertSame(AccountingError::TreasuryDepositDenied, $this->refusal(fn () => $this->scopes->assertMainAllows(PaymentType::Cash, TreasuryScopes::DEPOSIT, $other))->error);
        $this->scopes->assertMainAllows(PaymentType::Cash, TreasuryScopes::WITHDRAW, $other);
        $this->scopes->assertMainAllows(PaymentType::Cash, TreasuryScopes::DEPOSIT, null);
        $this->addToAssertionCount(3);
    }

    public function testAssertMainAllows_LeavesAPaymentAloneWhenItsTypeDoesNotGoToTheAccountOfTheMainTreasury(): void
    {
        $other = $this->employeeWithGrants([]);
        $main  = $this->treasuries->ensureMain();
        $named = $this->employeeWithGrants([]);

        $this->treasuries->update($main, ['name' => 'Main', 'kind' => 'cash', 'deposit_scope' => 'staff', 'deposit_members' => [$named]], $this->manager());
        $this->accountingDb()->table('accounting_routing')->where('routing_key', 'payment.bank_transfer')->update(['account_id' => $this->accountId('receivables')]);

        $this->scopes->assertMainAllows(PaymentType::BankTransfer, TreasuryScopes::DEPOSIT, $other);
        $this->assertSame(AccountingError::TreasuryDepositDenied, $this->refusal(fn () => $this->scopes->assertMainAllows(PaymentType::Cash, TreasuryScopes::DEPOSIT, $other))->error);
    }

    public function testAssertMainAllows_LeavesEveryonePaymentAloneWhileThereIsNoMainTreasury(): void
    {
        $this->scopes->assertMainAllows(PaymentType::Cash, TreasuryScopes::DEPOSIT, $this->employeeWithGrants([]));
        $this->addToAssertionCount(1);
    }

    public function testUsable_KeepsTheTreasuriesTheEmployeeMayUseInTheDirection(): void
    {
        $named = $this->employeeWithGrants([]);
        $open  = $this->drawer([], 'Open drawer');
        $shut  = $this->drawer(['deposit_scope' => 'staff', 'deposit_members' => [$this->employeeWithGrants([])]], 'Shut drawer');
        $all   = array_values(array_filter($this->treasuries->all(), static fn (object $treasury): bool => (int) $treasury->is_main === 0));

        $this->assertSame([$open], array_map(static fn (object $treasury): int => (int) $treasury->treasury_id, $this->scopes->usable($all, TreasuryScopes::DEPOSIT, $named)));
        $this->assertSame([$open, $shut], array_map(static fn (object $treasury): int => (int) $treasury->treasury_id, $this->scopes->usable($all, TreasuryScopes::WITHDRAW, $named)));
    }

    /**
     * @return array<string, array{array<string, mixed>}>
     */
    public static function invalidScopes(): array
    {
        return [
            'a scope that is not known'        => [['deposit_scope' => 'roles', 'deposit_members' => [1]]],
            'a scope that is not text'         => [['deposit_scope' => ['all']]],
            'employees without any'            => [['deposit_scope' => 'staff', 'deposit_members' => []]],
            'employees left out'               => [['withdraw_scope' => 'staff']],
            'branches without any'             => [['withdraw_scope' => 'branches', 'withdraw_members' => []]],
            'an employee that does not exist'  => [['deposit_scope' => 'staff', 'deposit_members' => [999999]]],
            'a branch that does not exist'     => [['withdraw_scope' => 'branches', 'withdraw_members' => [999999]]],
            'a member that is not a number'    => [['deposit_scope' => 'staff', 'deposit_members' => ['x']]],
            'a member that is a negative number' => [['deposit_scope' => 'staff', 'deposit_members' => ['-1']]],
        ];
    }

    /**
     * @param array<string, mixed> $input
     */
    #[DataProvider('invalidScopes')]
    public function testValidated_RefusesAScopeThatNamesNobodyOrSomebodyWhoDoesNotExist(array $input): void
    {
        $this->assertSame(AccountingError::TreasuryScopeInvalid, $this->refusal(fn () => $this->scopes->validated($input))->error);
    }

    public function testValidated_RefusesAnEmployeeThatWasDeleted(): void
    {
        $gone = $this->employeeWithGrants([]);

        $this->accountingDb()->table('employees')->where('person_id', $gone)->update(['deleted' => 1]);

        $this->assertSame(AccountingError::TreasuryScopeInvalid, $this->refusal(fn () => $this->scopes->validated(['deposit_scope' => 'staff', 'deposit_members' => [$gone]]))->error);
    }

    public function testValidated_TakesTheDirectionsItIsGivenAndLeavesTheOtherOut(): void
    {
        $employee = $this->employeeWithGrants([]);

        $this->assertSame([], $this->scopes->validated([]));
        $this->assertSame(['withdraw' => ['mode' => 'all', 'members' => []]], $this->scopes->validated(['withdraw_scope' => 'all', 'withdraw_members' => [$employee]]));
        $this->assertSame(['deposit' => ['mode' => 'staff', 'members' => [$employee]]], $this->scopes->validated(['deposit_scope' => 'staff', 'deposit_members' => [(string) $employee, $employee]]));
    }

    public function testApply_ReplacesTheMembersWhenTheScopeChangesKindAndWritesItToTheAuditLog(): void
    {
        $employee = $this->employeeWithGrants([]);
        $id       = $this->drawer(['deposit_scope' => 'staff', 'deposit_members' => [$employee]]);
        $branch   = $this->createBranch('B2');

        $this->treasuries->update($id, ['name' => 'Drawer', 'kind' => 'cash', 'deposit_scope' => 'branches', 'deposit_members' => [$branch]], $this->manager());

        $this->assertSame(['mode' => 'branches', 'members' => [$branch], 'exists' => true], $this->scopes->scopeOf($id, TreasuryScopes::DEPOSIT));
        $this->assertSame(1, $this->accountingDb()->table('treasury_scope_members')->where('treasury_id', $id)->countAllResults(), 'The employee of the old scope went');

        $entry = $this->accountingDb()->table('accounting_audit_log')->where(['entity_type' => 'treasury', 'entity_id' => $id, 'action' => 'scope_changed'])->orderBy('audit_id', 'DESC')->get()->getRow();

        $this->assertNotNull($entry);
        $this->assertStringContainsString('"staff"', (string) $entry->details);
        $this->assertStringContainsString('"branches"', (string) $entry->details);
    }

    public function testApply_WritesNothingToTheAuditLogWhenTheScopeStaysAsItWas(): void
    {
        $employee = $this->employeeWithGrants([]);
        $id       = $this->drawer(['deposit_scope' => 'staff', 'deposit_members' => [$employee]]);
        $before   = $this->accountingDb()->table('accounting_audit_log')->where(['entity_id' => $id, 'action' => 'scope_changed'])->countAllResults();

        $this->treasuries->update($id, ['name' => 'Drawer', 'kind' => 'cash', 'deposit_scope' => 'staff', 'deposit_members' => [$employee]], $this->manager());

        $this->assertSame($before, $this->accountingDb()->table('accounting_audit_log')->where(['entity_id' => $id, 'action' => 'scope_changed'])->countAllResults());
    }

    public function testApply_TakesTheMembersAwayWhenTheScopeGoesBackToEverybody(): void
    {
        $employee = $this->employeeWithGrants([]);
        $id       = $this->drawer(['withdraw_scope' => 'staff', 'withdraw_members' => [$employee]]);

        $this->treasuries->update($id, ['name' => 'Drawer', 'kind' => 'cash', 'withdraw_scope' => 'all'], $this->manager());

        $this->assertSame(0, $this->accountingDb()->table('treasury_scope_members')->where('treasury_id', $id)->countAllResults());
        $this->assertTrue($this->scopes->allows($id, TreasuryScopes::WITHDRAW, $this->employeeWithGrants([])));
    }

    public function testApply_LeavesTheScopesAloneWhenAnUpdateNamesNone(): void
    {
        $employee = $this->employeeWithGrants([]);
        $id       = $this->drawer(['deposit_scope' => 'staff', 'deposit_members' => [$employee]]);

        $this->treasuries->update($id, ['name' => 'Renamed drawer', 'kind' => 'cash'], $this->manager());

        $this->assertSame(['mode' => 'staff', 'members' => [$employee], 'exists' => true], $this->scopes->scopeOf($id, TreasuryScopes::DEPOSIT));
    }

    public function testApply_KeepsTheScopesOfATreasuryWhenOneInvalidScopeRefusesTheChange(): void
    {
        $employee = $this->employeeWithGrants([]);
        $id       = $this->drawer(['deposit_scope' => 'staff', 'deposit_members' => [$employee]]);

        $refusal = $this->refusal(fn () => $this->treasuries->update($id, ['name' => 'Renamed', 'kind' => 'cash', 'deposit_scope' => 'staff', 'deposit_members' => []], $this->manager()));

        $this->assertSame(AccountingError::TreasuryScopeInvalid, $refusal->error);
        $this->assertSame('Drawer', $this->treasuries->find($id)->name);
        $this->assertSame([$employee], $this->scopes->scopeOf($id, TreasuryScopes::DEPOSIT)['members']);
    }

    public function testApply_DeletesTheMembersWithTheTreasury(): void
    {
        $employee = $this->employeeWithGrants([]);
        $id       = $this->drawer(['deposit_scope' => 'staff', 'deposit_members' => [$employee]]);

        $this->treasuries->delete($id, $this->manager());

        $this->assertSame(0, $this->accountingDb()->table('treasury_scope_members')->where('treasury_id', $id)->countAllResults());
    }

    public function testCreate_RefusesAScopeOfAnEmployeeWithoutThePermissionToManageTreasuries(): void
    {
        $employee = $this->employeeWithGrants([]);

        $refusal = $this->refusal(fn () => $this->treasuries->create(['name' => 'Drawer', 'kind' => 'cash', 'deposit_scope' => 'staff', 'deposit_members' => [$employee]], $employee));

        $this->assertSame(AccountingError::PermissionDenied, $refusal->error);
    }
}
