<?php

namespace Tests\Libraries\Accounting;

use App\Libraries\Accounting\AccountingError;
use App\Libraries\Accounting\AccountingException;
use App\Libraries\Accounting\AccountingGuard;
use App\Libraries\Accounting\JournalDraft;
use App\Libraries\Accounting\JournalLineDraft;
use App\Libraries\Accounting\PaymentType;
use App\Libraries\Accounting\PostingService;
use App\Libraries\Accounting\TreasuryScopes;
use App\Libraries\Accounting\TreasuryService;
use App\Libraries\Roles\RoleService;
use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\DatabaseTestTrait;
use Config\Database;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Support\AccountingFixtureTrait;
use Tests\Support\EmployeeFixtureTrait;
use Tests\Support\ItemFixtureTrait;
use Tests\Support\ParityFixture;
use Tests\Support\PurchaseAccountingFixtureTrait;
use Tests\Support\RoleFixtureTrait;
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
    use RoleFixtureTrait;
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
     * @param array<string, mixed> $case
     */
    #[DataProvider('referenceScopeKinds')]
    public function testValidated_MatchesTheReferenceSystemInOfferingTheScopeOfRoles(array $case): void
    {
        $this->assertSame('exact', $case['classification']);
        $this->assertContains('roles', $case['reference']['scopes']);
        $this->assertSame($case['reference']['scopes'], TreasuryScopes::MODES);
        $this->assertSame($case['expected']['scopes'], TreasuryScopes::MODES);

        $role = $this->createRole(['accounting_treasuries']);

        foreach (TreasuryScopes::MODES as $kind) {
            $input = $kind === 'all' ? ['deposit_scope' => $kind] : ['deposit_scope' => $kind, 'deposit_members' => [$kind === 'roles' ? $role : ($kind === 'staff' ? $this->employeeWithGrants([]) : $this->createBranch('R' . substr(uniqid(), -6)))]];

            $this->assertSame($kind, $this->scopes->validated($input)['deposit']['mode'], $kind);
        }
    }

    public function testValidated_AcceptsARoleThatCarriesTheTreasuryPermissionAndRefusesOneThatDoesNotExist(): void
    {
        $role = $this->createRole(['accounting_treasuries']);

        $this->assertSame(['deposit' => ['mode' => 'roles', 'members' => [$role]]], $this->scopes->validated(['deposit_scope' => 'roles', 'deposit_members' => [(string) $role]]));
        $this->assertSame(AccountingError::TreasuryScopeInvalid, $this->refusal(fn () => $this->scopes->validated(['deposit_scope' => 'roles', 'deposit_members' => [$role, 999999]]))->error);
        $this->assertSame(AccountingError::TreasuryScopeInvalid, $this->refusal(fn () => $this->scopes->validated(['withdraw_scope' => 'roles', 'withdraw_members' => []]))->error);
    }

    public function testApply_StoresTheRolesOfAScopeAndAuditsTheChange(): void
    {
        $first  = $this->createRole(['accounting_treasuries']);
        $second = $this->createRole(['sales']);
        $id     = $this->drawer(['deposit_scope' => 'roles', 'deposit_members' => [$second, $first]]);

        $this->assertSame(['mode' => 'roles', 'members' => [$first, $second], 'exists' => true], $this->scopes->scopeOf($id, TreasuryScopes::DEPOSIT));
        $this->assertSame(['mode' => 'all', 'members' => [], 'exists' => true], $this->scopes->scopeOf($id, TreasuryScopes::WITHDRAW));
        $this->assertSame(1, $this->accountingDb()->table('accounting_audit_log')->where(['entity_type' => 'treasury', 'entity_id' => $id, 'action' => 'scope_changed'])->countAllResults());
    }

    public function testAllows_LetsTheEmployeesWhoHoldARoleOfTheScopeUseTheTreasuryAndNobodyElse(): void
    {
        $role   = $this->createRole(['accounting_treasuries']);
        $holder = $this->employeeWithRoles([$role]);
        $other  = $this->employeeWithRoles([$this->createRole(['sales'])]);
        $none   = $this->employeeWithGrants([]);
        $id     = $this->drawer(['deposit_scope' => 'roles', 'deposit_members' => [$role], 'withdraw_scope' => 'roles', 'withdraw_members' => [$role]]);

        foreach ([TreasuryScopes::DEPOSIT, TreasuryScopes::WITHDRAW] as $direction) {
            $this->assertTrue($this->scopes->allows($id, $direction, $holder), $direction);
            $this->assertFalse($this->scopes->allows($id, $direction, $other), $direction);
            $this->assertFalse($this->scopes->allows($id, $direction, $none), $direction);
            $this->assertTrue($this->scopes->allows($id, $direction, null), 'The books act without an employee');
        }
    }

    public function testAllows_FollowsTheRolesAnEmployeeIsGivenAndLosesAndThoseThatGoInactive(): void
    {
        $role     = $this->createRole(['accounting_treasuries']);
        $employee = $this->employeeWithGrants([]);
        $id       = $this->drawer(['deposit_scope' => 'roles', 'deposit_members' => [$role]]);

        $this->assertFalse($this->scopes->allows($id, TreasuryScopes::DEPOSIT, $employee));

        (new RoleService())->assign($employee, [$role], null);

        $this->assertTrue($this->scopes->allows($id, TreasuryScopes::DEPOSIT, $employee));

        (new RoleService())->update($role, ['name' => 'Gone quiet ' . uniqid(), 'active' => false], null);

        $this->assertFalse($this->scopes->allows($id, TreasuryScopes::DEPOSIT, $employee), 'An inactive role gives nobody the treasury');

        (new RoleService())->update($role, ['name' => 'Back again ' . uniqid(), 'active' => true], null);
        (new RoleService())->assign($employee, [], null);

        $this->assertFalse($this->scopes->allows($id, TreasuryScopes::DEPOSIT, $employee));
    }

    public function testAllows_RefusesEveryoneForARolesScopeAndDoesNotFailBeforeTheMigrationHasCreatedTheTables(): void
    {
        $role     = $this->createRole(['accounting_treasuries']);
        $employee = $this->employeeWithRoles([$role]);
        $id       = $this->drawer(['deposit_scope' => 'roles', 'deposit_members' => [$role]]);

        $this->assertTrue($this->scopes->allows($id, TreasuryScopes::DEPOSIT, $employee));

        $this->withoutRoleTables(function () use ($id, $employee): void {
            $this->assertFalse($this->scopes->allows($id, TreasuryScopes::DEPOSIT, $employee));
            $this->assertTrue($this->scopes->allows($id, TreasuryScopes::DEPOSIT, null), 'The books act without an employee');
        });

        $this->assertTrue($this->scopes->allows($id, TreasuryScopes::DEPOSIT, $employee));
    }

    public function testAllows_RefusesAnEmployeeWhoHoldsTheRoleOnlyAfterTheyWereDeleted(): void
    {
        $role     = $this->createRole(['accounting_treasuries']);
        $employee = $this->employeeWithRoles([$role]);
        $id       = $this->drawer(['deposit_scope' => 'roles', 'deposit_members' => [$role]]);

        $this->accountingDb()->table('employees')->where('person_id', $employee)->update(['deleted' => 1]);

        $this->assertFalse($this->scopes->allows($id, TreasuryScopes::DEPOSIT, $employee));
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

        $this->accountingDb()->table('treasuries')->where('treasury_id', $id)->update(['deposit_scope' => 'groups']);

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

    public function testAssertRoutedAllows_AppliesTheScopeOfTheMainTreasuryToAPaymentThatNamesNoTreasury(): void
    {
        $named = $this->employeeWithGrants([]);
        $other = $this->employeeWithGrants([]);
        $main  = $this->treasuries->ensureMain();

        $this->treasuries->update($main, ['name' => 'Main', 'kind' => 'cash', 'deposit_scope' => 'staff', 'deposit_members' => [$named]], $this->manager());

        $this->scopes->assertRoutedAllows(PaymentType::Cash, TreasuryScopes::DEPOSIT, $named);
        $this->assertSame(AccountingError::TreasuryDepositDenied, $this->refusal(fn () => $this->scopes->assertRoutedAllows(PaymentType::Cash, TreasuryScopes::DEPOSIT, $other))->error);
        $this->scopes->assertRoutedAllows(PaymentType::Cash, TreasuryScopes::WITHDRAW, $other);
        $this->scopes->assertRoutedAllows(PaymentType::Cash, TreasuryScopes::DEPOSIT, null);
        $this->addToAssertionCount(3);
    }

    public function testAssertRoutedAllows_LeavesAPaymentAloneWhenItsTypeIsRoutedToAnAccountNoTreasuryOwns(): void
    {
        $other = $this->employeeWithGrants([]);
        $main  = $this->treasuries->ensureMain();
        $named = $this->employeeWithGrants([]);

        $this->treasuries->update($main, ['name' => 'Main', 'kind' => 'cash', 'deposit_scope' => 'staff', 'deposit_members' => [$named]], $this->manager());
        $this->accountingDb()->table('accounting_routing')->where('routing_key', 'payment.bank_transfer')->update(['account_id' => $this->accountId('receivables')]);

        $this->scopes->assertRoutedAllows(PaymentType::BankTransfer, TreasuryScopes::DEPOSIT, $other);
        $this->assertSame(AccountingError::TreasuryDepositDenied, $this->refusal(fn () => $this->scopes->assertRoutedAllows(PaymentType::Cash, TreasuryScopes::DEPOSIT, $other))->error);
    }

    public function testAssertRoutedAllows_AppliesTheScopeOfWhicheverTreasuryTheTypeIsRoutedTo(): void
    {
        $manager = $this->manager();
        $named   = $this->employeeWithGrants([]);
        $other   = $this->employeeWithGrants([]);
        $bank    = $this->treasuries->create(['name' => 'Routed bank', 'kind' => 'bank', 'bank_name' => 'Gulf Bank', 'account_number' => '1234567890', 'deposit_scope' => 'staff', 'deposit_members' => [$named], 'withdraw_scope' => 'staff', 'withdraw_members' => [$named]], $manager);

        $this->accountingDb()->table('accounting_routing')->where('routing_key', 'payment.bank_transfer')->update(['account_id' => $this->treasuries->accountOf($bank)]);

        $this->scopes->assertRoutedAllows(PaymentType::BankTransfer, TreasuryScopes::DEPOSIT, $named);
        $this->scopes->assertRoutedAllows(PaymentType::Cash, TreasuryScopes::DEPOSIT, $other);

        $deposit  = $this->refusal(fn () => $this->scopes->assertRoutedAllows(PaymentType::BankTransfer, TreasuryScopes::DEPOSIT, $other));
        $withdraw = $this->refusal(fn () => $this->scopes->assertRoutedAllows(PaymentType::BankTransfer, TreasuryScopes::WITHDRAW, $other));

        $this->assertSame(AccountingError::TreasuryDepositDenied, $deposit->error);
        $this->assertSame(AccountingError::TreasuryWithdrawDenied, $withdraw->error);
        $this->assertSame('Routed bank', $deposit->context['name']);
        $this->addToAssertionCount(2);
    }

    public function testAssertRoutedAllows_AppliesTheScopeOfACashTreasuryTheTypeIsRoutedTo(): void
    {
        $manager = $this->manager();
        $other   = $this->employeeWithGrants([]);
        $drawer  = $this->treasuries->create(['name' => 'Routed drawer', 'kind' => 'cash', 'deposit_scope' => 'staff', 'deposit_members' => [$manager]], $manager);

        $this->accountingDb()->table('accounting_routing')->where('routing_key', 'payment.debit')->update(['account_id' => $this->treasuries->accountOf($drawer)]);

        $this->assertSame(AccountingError::TreasuryDepositDenied, $this->refusal(fn () => $this->scopes->assertRoutedAllows(PaymentType::Debit, TreasuryScopes::DEPOSIT, $other))->error);
        $this->scopes->assertRoutedAllows(PaymentType::Debit, TreasuryScopes::DEPOSIT, $manager);
        $this->addToAssertionCount(1);
    }

    public function testAssertRoutedAllows_AsksNoTreasuryOnceTheTypeIsRoutedAwayFromAllOfThem(): void
    {
        $manager = $this->manager();
        $other   = $this->employeeWithGrants([]);
        $bank    = $this->treasuries->create(['name' => 'Routed bank', 'kind' => 'bank', 'bank_name' => 'Gulf Bank', 'account_number' => '1234567890', 'deposit_scope' => 'staff', 'deposit_members' => [$manager]], $manager);

        $this->accountingDb()->table('accounting_routing')->where('routing_key', 'payment.bank_transfer')->update(['account_id' => $this->treasuries->accountOf($bank)]);

        $this->assertSame(AccountingError::TreasuryDepositDenied, $this->refusal(fn () => $this->scopes->assertRoutedAllows(PaymentType::BankTransfer, TreasuryScopes::DEPOSIT, $other))->error);

        $this->accountingDb()->table('accounting_routing')->where('routing_key', 'payment.bank_transfer')->update(['account_id' => $this->accountId('receivables')]);

        $this->scopes->assertRoutedAllows(PaymentType::BankTransfer, TreasuryScopes::DEPOSIT, $other);
        $this->addToAssertionCount(1);
    }

    private function journalBetween(int $debitAccount, int $creditAccount, string $amount = '30.00'): int
    {
        return (new PostingService())->post(new JournalDraft(
            date: '2026-10-21',
            lines: [new JournalLineDraft($debitAccount, debit: $amount), new JournalLineDraft($creditAccount, credit: $amount)],
            sourceType: 'opening',
            sourceId: random_int(1000, 9999),
            currencyCode: 'SAR',
        ))->journalId;
    }

    public function testAssertMayReverse_AsksTheWithdrawalScopeOfTheTreasuryAJournalPutMoneyInto(): void
    {
        $named  = $this->employeeWithGrants([]);
        $other  = $this->employeeWithGrants([]);
        $drawer = $this->drawer(['withdraw_scope' => 'staff', 'withdraw_members' => [$named]]);
        $in     = $this->journalBetween((int) $this->treasuries->accountOf($drawer), $this->accountId('opening_balance'));

        $this->scopes->assertMayReverse($in, $named);
        $this->assertSame(AccountingError::TreasuryWithdrawDenied, $this->refusal(fn () => $this->scopes->assertMayReverse($in, $other))->error);
    }

    public function testAssertMayReverse_AsksTheDepositScopeOfTheTreasuryAJournalTookMoneyOutOf(): void
    {
        $named  = $this->employeeWithGrants([]);
        $other  = $this->employeeWithGrants([]);
        $drawer = $this->drawer(['deposit_scope' => 'staff', 'deposit_members' => [$named]]);
        $out    = $this->journalBetween($this->accountId('opening_balance'), (int) $this->treasuries->accountOf($drawer));

        $this->scopes->assertMayReverse($out, $named);
        $this->assertSame(AccountingError::TreasuryDepositDenied, $this->refusal(fn () => $this->scopes->assertMayReverse($out, $other))->error);
    }

    public function testAssertMayReverse_AsksBothTreasuriesOfAJournalBetweenTwoOfThem(): void
    {
        $named = $this->employeeWithGrants([]);
        $other = $this->employeeWithGrants([]);
        $from  = $this->drawer(['deposit_scope' => 'staff', 'deposit_members' => [$named]], 'From');
        $to    = $this->drawer(['withdraw_scope' => 'staff', 'withdraw_members' => [$named]], 'To');
        $moved = $this->journalBetween((int) $this->treasuries->accountOf($to), (int) $this->treasuries->accountOf($from));

        $this->scopes->assertMayReverse($moved, $named);

        $refusal = $this->refusal(fn () => $this->scopes->assertMayReverse($moved, $other));

        $this->assertContains($refusal->error, [AccountingError::TreasuryWithdrawDenied, AccountingError::TreasuryDepositDenied]);
    }

    public function testAssertMayReverse_AsksNothingOfAJournalThatTouchesNoTreasuryAndNothingOfTheBooks(): void
    {
        $named   = $this->employeeWithGrants([]);
        $drawer  = $this->drawer(['withdraw_scope' => 'staff', 'withdraw_members' => [$named]]);
        $plain   = $this->journalBetween($this->accountId('sales_revenue'), $this->accountId('opening_balance'));
        $treated = $this->journalBetween((int) $this->treasuries->accountOf($drawer), $this->accountId('opening_balance'));

        $this->scopes->assertMayReverse($plain, $this->employeeWithGrants([]));
        $this->scopes->assertMayReverse($treated, null);
        $this->addToAssertionCount(2);
    }

    private function draftBetween(int $debitAccount, int $creditAccount, string $amount): JournalDraft
    {
        return new JournalDraft(
            date: '2026-10-21',
            lines: [new JournalLineDraft($debitAccount, debit: $amount), new JournalLineDraft($creditAccount, credit: $amount)],
            sourceType: 'opening',
            sourceId: random_int(1000, 9999),
            currencyCode: 'SAR',
        );
    }

    public function testAssertMayReplace_AsksTheWithdrawalScopeWhenAJournalIsReplacedByOneThatPutsLessIntoTheTreasury(): void
    {
        $named  = $this->employeeWithGrants([]);
        $other  = $this->employeeWithGrants([]);
        $drawer = $this->drawer(['withdraw_scope' => 'staff', 'withdraw_members' => [$named]]);
        $in     = $this->journalBetween((int) $this->treasuries->accountOf($drawer), $this->accountId('opening_balance'), '100.00');
        $less   = $this->draftBetween((int) $this->treasuries->accountOf($drawer), $this->accountId('opening_balance'), '60.00');

        $this->scopes->assertMayReplace($in, $less, $named);
        $this->assertSame(AccountingError::TreasuryWithdrawDenied, $this->refusal(fn () => $this->scopes->assertMayReplace($in, $less, $other))->error);
    }

    public function testAssertMayReplace_AsksTheDepositScopeWhenAJournalIsReplacedByOneThatPutsMoreIntoTheTreasury(): void
    {
        $named  = $this->employeeWithGrants([]);
        $other  = $this->employeeWithGrants([]);
        $drawer = $this->drawer(['deposit_scope' => 'staff', 'deposit_members' => [$named]]);
        $in     = $this->journalBetween((int) $this->treasuries->accountOf($drawer), $this->accountId('opening_balance'), '60.00');
        $more   = $this->draftBetween((int) $this->treasuries->accountOf($drawer), $this->accountId('opening_balance'), '100.00');

        $this->scopes->assertMayReplace($in, $more, $named);
        $this->assertSame(AccountingError::TreasuryDepositDenied, $this->refusal(fn () => $this->scopes->assertMayReplace($in, $more, $other))->error);
    }

    public function testAssertMayReplace_AsksTheWithdrawalScopeWhenTheReplacementUsesNoTreasuryAtAll(): void
    {
        $named  = $this->employeeWithGrants([]);
        $other  = $this->employeeWithGrants([]);
        $drawer = $this->drawer(['withdraw_scope' => 'staff', 'withdraw_members' => [$named]]);
        $in     = $this->journalBetween((int) $this->treasuries->accountOf($drawer), $this->accountId('opening_balance'));
        $plain  = $this->draftBetween($this->accountId('receivables'), $this->accountId('opening_balance'), '30.00');

        $this->scopes->assertMayReplace($in, $plain, $named);
        $this->scopes->assertMayReplace($in, null, $named);
        $this->assertSame(AccountingError::TreasuryWithdrawDenied, $this->refusal(fn () => $this->scopes->assertMayReplace($in, $plain, $other))->error);
        $this->assertSame(AccountingError::TreasuryWithdrawDenied, $this->refusal(fn () => $this->scopes->assertMayReplace($in, null, $other))->error);
    }

    public function testAssertMayReplace_AsksBothTreasuriesWhenTheMoneyMovesFromOneToTheOther(): void
    {
        $named = $this->employeeWithGrants([]);
        $other = $this->employeeWithGrants([]);
        $from  = $this->drawer(['withdraw_scope' => 'staff', 'withdraw_members' => [$named]], 'From');
        $to    = $this->drawer(['deposit_scope' => 'staff', 'deposit_members' => [$named]], 'To');
        $in    = $this->journalBetween((int) $this->treasuries->accountOf($from), $this->accountId('opening_balance'));
        $moved = $this->draftBetween((int) $this->treasuries->accountOf($to), $this->accountId('opening_balance'), '30.00');

        $this->scopes->assertMayReplace($in, $moved, $named);

        $refusal = $this->refusal(fn () => $this->scopes->assertMayReplace($in, $moved, $other));

        $this->assertContains($refusal->error, [AccountingError::TreasuryWithdrawDenied, AccountingError::TreasuryDepositDenied]);
    }

    /**
     * A draft of a branch that has no treasury of its own on its lines.
     */
    private function draftOf(int $debitAccount, int $creditAccount, string $amount): JournalDraft
    {
        return new JournalDraft(
            date: '2026-10-21',
            lines: [new JournalLineDraft($debitAccount, debit: $amount), new JournalLineDraft($creditAccount, credit: $amount)],
            sourceType: 'opening',
            sourceId: random_int(1000, 9999),
            currencyCode: 'SAR',
        );
    }

    public function testAssertMayReplaceAll_AsksTheWithdrawalScopeWhenTheJournalsOfAPairArePutBackByOnesThatPutLessIntoTheTreasury(): void
    {
        $named  = $this->employeeWithGrants([]);
        $other  = $this->employeeWithGrants([]);
        $drawer = $this->drawer(['withdraw_scope' => 'staff', 'withdraw_members' => [$named]]);
        $own    = $this->journalBetween($this->accountId('branches_receivable'), $this->accountId('opening_balance'), '100.00');
        $in     = $this->journalBetween((int) $this->treasuries->accountOf($drawer), $this->accountId('branches_payable'), '100.00');
        $less   = [$this->draftOf($this->accountId('branches_receivable'), $this->accountId('opening_balance'), '60.00'), $this->draftOf((int) $this->treasuries->accountOf($drawer), $this->accountId('branches_payable'), '60.00')];

        $this->scopes->assertMayReplaceAll([$own, $in], $less, $named);
        $this->assertSame(AccountingError::TreasuryWithdrawDenied, $this->refusal(fn () => $this->scopes->assertMayReplaceAll([$own, $in], $less, $other))->error);
    }

    public function testAssertMayReplaceAll_AsksTheDepositScopeWhenTheReplacementsPutMoreIntoTheTreasury(): void
    {
        $named  = $this->employeeWithGrants([]);
        $other  = $this->employeeWithGrants([]);
        $drawer = $this->drawer(['deposit_scope' => 'staff', 'deposit_members' => [$named]]);
        $in     = $this->journalBetween((int) $this->treasuries->accountOf($drawer), $this->accountId('branches_payable'), '60.00');
        $more   = [$this->draftOf((int) $this->treasuries->accountOf($drawer), $this->accountId('branches_payable'), '100.00')];

        $this->scopes->assertMayReplaceAll([$in], $more, $named);
        $this->assertSame(AccountingError::TreasuryDepositDenied, $this->refusal(fn () => $this->scopes->assertMayReplaceAll([$in], $more, $other))->error);
    }

    public function testAssertMayReplaceAll_AsksTheWithdrawalScopeWhenNothingTakesThePlaceOfTheJournals(): void
    {
        $named  = $this->employeeWithGrants([]);
        $other  = $this->employeeWithGrants([]);
        $drawer = $this->drawer(['withdraw_scope' => 'staff', 'withdraw_members' => [$named]]);
        $in     = $this->journalBetween((int) $this->treasuries->accountOf($drawer), $this->accountId('branches_payable'), '60.00');

        $this->scopes->assertMayReplaceAll([$in], [], $named);
        $this->assertSame(AccountingError::TreasuryWithdrawDenied, $this->refusal(fn () => $this->scopes->assertMayReplaceAll([$in], [], $other))->error);
    }

    public function testAssertMayReplaceAll_AsksNothingWhenTheTreasuryHoldsTheSameAfterwardsAndNothingOfTheBooks(): void
    {
        $named  = $this->employeeWithGrants([]);
        $other  = $this->employeeWithGrants([]);
        $drawer = $this->drawer(['deposit_scope' => 'staff', 'deposit_members' => [$named], 'withdraw_scope' => 'staff', 'withdraw_members' => [$named]]);
        $in     = $this->journalBetween((int) $this->treasuries->accountOf($drawer), $this->accountId('branches_payable'), '60.00');
        $same   = [$this->draftOf((int) $this->treasuries->accountOf($drawer), $this->accountId('opening_balance'), '60.00')];

        $this->scopes->assertMayReplaceAll([$in], $same, $other);
        $this->scopes->assertMayReplaceAll([$in], [], null);
        $this->addToAssertionCount(1);
    }

    public function testAssertMayReplace_AsksNothingWhenTheTreasuryHoldsTheSameAfterwardsAndNothingOfTheBooks(): void
    {
        $named  = $this->employeeWithGrants([]);
        $drawer = $this->drawer(['deposit_scope' => 'staff', 'deposit_members' => [$named], 'withdraw_scope' => 'staff', 'withdraw_members' => [$named]]);
        $in     = $this->journalBetween((int) $this->treasuries->accountOf($drawer), $this->accountId('opening_balance'));
        $same   = $this->draftBetween((int) $this->treasuries->accountOf($drawer), $this->accountId('sales_revenue'), '30.00');
        $less   = $this->draftBetween((int) $this->treasuries->accountOf($drawer), $this->accountId('opening_balance'), '10.00');

        $this->scopes->assertMayReplace($in, $same, $this->employeeWithGrants([]));
        $this->scopes->assertMayReplace($in, $less, null);
        $this->addToAssertionCount(2);
    }

    public function testAssertMayPost_AsksTheDepositScopeOfWhatTheLinesDebitAndLeavesWhatTheyCreditToTheWithdrawalScope(): void
    {
        $named  = $this->employeeWithGrants([]);
        $other  = $this->employeeWithGrants([]);
        $drawer = $this->drawer(['deposit_scope' => 'staff', 'deposit_members' => [$named]]);
        $into   = $this->draftBetween((int) $this->treasuries->accountOf($drawer), $this->accountId('opening_balance'), '30.00');
        $out    = $this->draftBetween($this->accountId('opening_balance'), (int) $this->treasuries->accountOf($drawer), '30.00');

        $this->scopes->assertMayPost($into->lines, $named);
        $this->assertSame(AccountingError::TreasuryDepositDenied, $this->refusal(fn () => $this->scopes->assertMayPost($into->lines, $other))->error);
        $this->scopes->assertMayPost($out->lines, $other);
        $this->addToAssertionCount(1);
    }

    public function testAssertMayPost_AsksTheWithdrawalScopeOfWhatTheLinesCredit(): void
    {
        $named  = $this->employeeWithGrants([]);
        $other  = $this->employeeWithGrants([]);
        $drawer = $this->drawer(['withdraw_scope' => 'staff', 'withdraw_members' => [$named]]);
        $out    = $this->draftBetween($this->accountId('opening_balance'), (int) $this->treasuries->accountOf($drawer), '30.00');

        $this->scopes->assertMayPost($out->lines, $named);
        $this->assertSame(AccountingError::TreasuryWithdrawDenied, $this->refusal(fn () => $this->scopes->assertMayPost($out->lines, $other))->error);
    }

    public function testAssertMayPost_AsksBothTreasuriesOfLinesBetweenTwoOfThem(): void
    {
        $named = $this->employeeWithGrants([]);
        $other = $this->employeeWithGrants([]);
        $from  = $this->drawer(['withdraw_scope' => 'staff', 'withdraw_members' => [$named]], 'From');
        $to    = $this->drawer(['deposit_scope' => 'staff', 'deposit_members' => [$named]], 'To');
        $moved = $this->draftBetween((int) $this->treasuries->accountOf($to), (int) $this->treasuries->accountOf($from), '30.00');

        $this->scopes->assertMayPost($moved->lines, $named);

        $refusal = $this->refusal(fn () => $this->scopes->assertMayPost($moved->lines, $other));

        $this->assertContains($refusal->error, [AccountingError::TreasuryWithdrawDenied, AccountingError::TreasuryDepositDenied]);
    }

    public function testAssertMayPost_AsksNothingOfLinesThatTouchNoTreasuryAndNothingOfTheBooks(): void
    {
        $named  = $this->employeeWithGrants([]);
        $drawer = $this->drawer(['deposit_scope' => 'staff', 'deposit_members' => [$named], 'withdraw_scope' => 'staff', 'withdraw_members' => [$named]]);
        $plain  = $this->draftBetween($this->accountId('sales_revenue'), $this->accountId('opening_balance'), '30.00');
        $into   = $this->draftBetween((int) $this->treasuries->accountOf($drawer), $this->accountId('opening_balance'), '30.00');

        $this->scopes->assertMayPost($plain->lines, $this->employeeWithGrants([]));
        $this->scopes->assertMayPost($into->lines, null);
        $this->addToAssertionCount(2);
    }

    public function testOwnerOfAccount_NamesTheTreasuryWhoseAccountItIsAndNobodyForAnyOther(): void
    {
        $drawer = $this->drawer();
        $main   = $this->treasuries->ensureMain();

        $this->assertSame($drawer, $this->scopes->ownerOfAccount((int) $this->treasuries->accountOf($drawer)));
        $this->assertSame($main, $this->scopes->ownerOfAccount($this->accountId('cash')));
        $this->assertNull($this->scopes->ownerOfAccount($this->accountId('receivables')));
        $this->assertNull($this->scopes->ownerOfAccount(999999));
    }

    public function testAssertRoutedAllows_LeavesEveryPaymentAloneWhileThereIsNoMainTreasury(): void
    {
        $this->scopes->assertRoutedAllows(PaymentType::Cash, TreasuryScopes::DEPOSIT, $this->employeeWithGrants([]));
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
            'a scope that is not known'        => [['deposit_scope' => 'groups', 'deposit_members' => [1]]],
            'roles without any'                => [['deposit_scope' => 'roles', 'deposit_members' => []]],
            'a role that does not exist'       => [['deposit_scope' => 'roles', 'deposit_members' => [999999]]],
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
