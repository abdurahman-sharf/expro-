<?php

namespace Tests\Libraries\Accounting\Bank;

use App\Libraries\Accounting\AccountingError;
use App\Libraries\Accounting\AccountingException;
use App\Libraries\Accounting\AccountingGuard;
use App\Libraries\Accounting\Bank\BankAccess;
use App\Libraries\Accounting\Bank\BankReconciliation;
use App\Libraries\Accounting\Bank\StatementMapping;
use App\Libraries\Accounting\TreasuryService;
use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\DatabaseTestTrait;
use Config\Database;
use Tests\Support\AccountingFixtureTrait;
use Tests\Support\BankFixtureTrait;
use Tests\Support\DatabaseFingerprintTrait;
use Tests\Support\EmployeeFixtureTrait;

/**
 * Who may reconcile which bank account: the permission, the branches the employee works in, the scope
 * of the treasury, and that no operation gets round them. An account the employee may not see is an
 * account that is not found, whether it exists or not.
 */
class BankAccessTest extends CIUnitTestCase
{
    use DatabaseTestTrait;
    use AccountingFixtureTrait;
    use EmployeeFixtureTrait;
    use BankFixtureTrait;
    use DatabaseFingerprintTrait;

    protected $migrate     = true;
    protected $migrateOnce = true;
    protected $refresh     = false;
    protected $namespace   = null;

    private BankAccess $access;

    public static function setUpBeforeClass(): void
    {
        $seeder = Database::seeder('tests');
        $seeder->call('TestDatabaseBootstrapSeeder');
    }

    protected function setUp(): void
    {
        parent::setUp();

        $this->startBanking();
        $this->access = new BankAccess();
    }

    protected function tearDown(): void
    {
        $this->stopBanking();

        parent::tearDown();
    }

    private function refusal(callable $action): AccountingException
    {
        try {
            $action();
        } catch (AccountingException $exception) {
            return $exception;
        }

        $this->fail('The operation was not refused.');
    }

    private function reconciler(?array $branchIds = null): int
    {
        return $this->employeeWithGrants([AccountingGuard::BANK_RECONCILE], $branchIds);
    }

    public function testBankAccount_RequiresThePermissionToReconcile(): void
    {
        $manager = $this->employeeWithGrants([AccountingGuard::TREASURIES_MANAGE, 'accounting_treasuries']);

        $this->assertSame(AccountingError::PermissionDenied, $this->refusal(fn () => $this->access->bankAccount($this->bankId, $manager))->error);
        $this->assertSame($this->bankId, (int) $this->access->bankAccount($this->bankId, $this->reconciler())->treasury_id);
    }

    public function testBankAccount_IsOpenToTheSystemItself(): void
    {
        $this->assertSame($this->bankId, (int) $this->access->bankAccount($this->bankId, null)->treasury_id);
        $this->assertCount(1, $this->access->bankAccounts(null));
    }

    public function testBankAccount_RejectsABankAccountOfABranchTheEmployeeDoesNotWorkIn(): void
    {
        $second   = $this->createBranch('B2', 'Second branch');
        $inSecond = $this->openBank('Second branch bank', $second);
        $inFirst  = $this->reconciler([1]);
        $inBoth   = $this->reconciler([1, $second]);

        $this->assertSame(AccountingError::BankAccountNotFound, $this->refusal(fn () => $this->access->bankAccount($inSecond, $inFirst))->error);
        $this->assertSame($this->bankId, (int) $this->access->bankAccount($this->bankId, $inFirst)->treasury_id);
        $this->assertSame($inSecond, (int) $this->access->bankAccount($inSecond, $inBoth)->treasury_id);
        $this->assertSame($inSecond, (int) $this->access->bankAccount($inSecond, $this->reconciler())->treasury_id);
    }

    public function testBankAccount_SaysTheSameForACashTreasuryAnUnknownIdAndAnAccountOutOfScope(): void
    {
        $second   = $this->createBranch('B2', 'Second branch');
        $cash     = $this->bankTreasuries->create(['name' => 'Cash box', 'kind' => 'cash', 'branch_id' => 0], $this->bankClerk);
        $outside  = $this->openBank('Other branch bank', $second);
        $employee = $this->reconciler([1]);

        $errors = array_map(
            fn (int $id): array => (function () use ($id, $employee): array {
                $exception = $this->refusal(fn () => $this->access->bankAccount($id, $employee));

                return [$exception->error, $exception->getMessage() === "Bank account {$id} does not exist."];
            })(),
            [$cash, 987654, $outside],
        );

        $this->assertSame([[AccountingError::BankAccountNotFound, true], [AccountingError::BankAccountNotFound, true], [AccountingError::BankAccountNotFound, true]], $errors);
    }

    public function testBankAccount_FollowsTheDepositAndWithdrawScopeOfTheTreasury(): void
    {
        $outsider = $this->reconciler();
        $insider  = $this->reconciler();
        $depositor = $this->reconciler();
        $drawer   = $this->reconciler();
        $bank     = $this->bankTreasuries->create([
            'name' => 'Restricted bank', 'kind' => 'bank', 'bank_code' => 'SA_RAJHI', 'account_number' => 'SA00000000000000000001', 'branch_id' => 0,
            'deposit_scope' => 'staff', 'deposit_members' => [$depositor, $insider],
            'withdraw_scope' => 'staff', 'withdraw_members' => [$drawer, $insider],
        ], $this->bankClerk);

        $this->assertSame(AccountingError::BankAccountNotFound, $this->refusal(fn () => $this->access->bankAccount($bank, $outsider))->error);
        $this->assertSame($bank, (int) $this->access->bankAccount($bank, $depositor)->treasury_id);
        $this->assertSame($bank, (int) $this->access->bankAccount($bank, $drawer)->treasury_id);
        $this->assertSame($bank, (int) $this->access->bankAccount($bank, $insider)->treasury_id);
        $this->assertSame($bank, (int) $this->access->bankAccount($bank, null)->treasury_id);
    }

    public function testBankAccount_FollowsAScopeOfBranches(): void
    {
        $second   = $this->createBranch('B2', 'Second branch');
        $bank     = $this->bankTreasuries->create([
            'name' => 'Branch scoped bank', 'kind' => 'bank', 'bank_code' => 'SA_RAJHI', 'account_number' => 'SA00000000000000000002', 'branch_id' => 0,
            'deposit_scope' => 'branches', 'deposit_members' => [$second],
            'withdraw_scope' => 'branches', 'withdraw_members' => [$second],
        ], $this->bankClerk);
        $inSecond = $this->reconciler([1, $second]);
        $inFirst  = $this->reconciler([1]);

        $this->assertSame($bank, (int) $this->access->bankAccount($bank, $inSecond)->treasury_id);
        $this->assertSame(AccountingError::BankAccountNotFound, $this->refusal(fn () => $this->access->bankAccount($bank, $inFirst))->error);
    }

    public function testBankAccounts_ListsOnlyTheAccountsTheEmployeeMaySeeAndNoneWithoutThePermission(): void
    {
        $second = $this->createBranch('B2', 'Second branch');
        $other  = $this->openBank('Second branch bank', $second);
        $this->bankTreasuries->create(['name' => 'Cash box', 'kind' => 'cash', 'branch_id' => 0], $this->bankClerk);

        $this->assertEqualsCanonicalizing([$this->bankId, $other], array_map(static fn (object $treasury): int => (int) $treasury->treasury_id, $this->access->bankAccounts($this->reconciler())));
        $this->assertSame([$this->bankId], array_map(static fn (object $treasury): int => (int) $treasury->treasury_id, $this->access->bankAccounts($this->reconciler([1]))));
        $this->assertSame([], $this->access->bankAccounts($this->employeeWithGrants([AccountingGuard::TREASURIES_MANAGE])));
        $this->assertSame([], $this->access->bankAccounts($this->reconciler([])));
    }

    public function testCanSee_IsFalseForACashTreasury(): void
    {
        $cash = $this->bankTreasuries->find($this->bankTreasuries->create(['name' => 'Cash box', 'kind' => 'cash', 'branch_id' => 0], $this->bankClerk));

        $this->assertFalse($this->access->canSee($cash, null));
        $this->assertTrue($this->access->canSee($this->bankTreasuries->find($this->bankId), null));
    }

    /**
     * Every operation of the reconciliation, run by someone who may not do it, as a list of calls that
     * each take the employee. The books and the tables of the reconciliation must stay as they were.
     *
     * @return array<string, callable(BankReconciliation, int, int, int, int, int): mixed>
     */
    private function operations(): array
    {
        return [
            'import'           => fn (BankReconciliation $bank, int $account, int $employee) => $bank->import($account, 'x.csv', "H\n2026-03-05,Intruder,5.00,,Z\n", StatementMapping::standard(), $employee),
            'statements'       => fn (BankReconciliation $bank, int $account, int $employee) => $bank->statements($account, $employee),
            'lines'            => fn (BankReconciliation $bank, int $account, int $employee) => $bank->lines($account, $employee),
            'suggestions'      => fn (BankReconciliation $bank, int $account, int $employee) => $bank->suggestions($account, $employee),
            'lines to match'   => fn (BankReconciliation $bank, int $account, int $employee, int $line) => $bank->linesToMatch($account, $line, $employee),
            'match'            => fn (BankReconciliation $bank, int $account, int $employee, int $line, int $journalLine) => $bank->match($account, $line, [$journalLine], $employee),
            'unmatch'          => fn (BankReconciliation $bank, int $account, int $employee, int $line, int $journalLine, int $match) => $bank->unmatch($account, $match, $employee),
            'matches'          => fn (BankReconciliation $bank, int $account, int $employee) => $bank->matches($account, $employee),
            'report'           => fn (BankReconciliation $bank, int $account, int $employee) => $bank->report($account, '2026-03-31', '100.00', $employee),
            'close'            => fn (BankReconciliation $bank, int $account, int $employee) => $bank->close($account, '2026-03-31', '100.00', $employee),
            'closings'         => fn (BankReconciliation $bank, int $account, int $employee) => $bank->closings($account, $employee),
            'delete statement' => fn (BankReconciliation $bank, int $account, int $employee, int $line, int $journalLine, int $match, int $statement) => $bank->deleteStatement($statement, $employee),
        ];
    }

    /**
     * @return array{int, int, int, int}
     */
    private function populatedAccount(): array
    {
        $line = $this->bookLine('100.00', '2026-03-10');
        $this->importStatement($this->statementText([['2026-03-10', 'Deposit A', '100.00', '', 'A'], ['2026-03-11', 'Deposit B', '50.00', '', 'B']]));
        $statementLine = $this->statementLineId('Deposit A');
        $matchId       = $this->bank->match($this->bankId, $statementLine, [$line], $this->bankClerk);
        $open          = $this->bookLine('50.00', '2026-03-11');

        return [$statementLine, $open, $matchId, (int) $this->accountingDb()->table('bank_statements')->get()->getRow()->statement_id];
    }

    public function testOperations_AreRefusedToSomeoneWithoutThePermissionAndChangeNothing(): void
    {
        [$line, $open, $match, $statement] = $this->populatedAccount();
        $employee                          = $this->employeeWithGrants([AccountingGuard::TREASURIES_MANAGE, 'accounting_treasuries']);
        $before                            = $this->databaseFingerprint();

        foreach ($this->operations() as $name => $operation) {
            $this->assertSame(AccountingError::PermissionDenied, $this->refusal(fn () => $operation($this->bank, $this->bankId, $employee, $line, $open, $match, $statement))->error, $name);
        }

        $this->assertSame(AccountingError::PermissionDenied, $this->refusal(fn () => $this->bank->reopen(1, 'Why', $employee))->error);
        $this->assertSame(AccountingError::PermissionDenied, $this->refusal(fn () => $this->bank->setWindowDays(3, $employee))->error);
        $this->assertDatabaseUnchanged($before);
    }

    public function testOperations_AreRefusedAsAccountNotFoundToSomeoneOutsideTheBranchAndChangeNothing(): void
    {
        [$line, $open, $match, $statement] = $this->populatedAccount();
        $second                            = $this->createBranch('B2', 'Second branch');
        $employee                          = $this->reconciler([$second]);
        $before                            = $this->databaseFingerprint();

        foreach ($this->operations() as $name => $operation) {
            $expected = $name === 'delete statement' ? AccountingError::BankStatementNotFound : AccountingError::BankAccountNotFound;

            $this->assertSame($expected, $this->refusal(fn () => $operation($this->bank, $this->bankId, $employee, $line, $open, $match, $statement))->error, $name);
        }

        $this->assertSame(AccountingError::BankReconciliationNotFound, $this->refusal(fn () => $this->bank->reopen(1, 'Why', $employee))->error);
        $this->assertDatabaseUnchanged($before);
    }

    public function testOperations_AreRefusedAsAccountNotFoundToSomeoneOutsideTheScopeOfTheTreasuryAndChangeNothing(): void
    {
        [$line, $open, $match, $statement] = $this->populatedAccount();
        $insider                           = $this->reconciler();
        $outsider                          = $this->reconciler();

        $this->bankTreasuries->update($this->bankId, [
            'name' => 'Bank 1', 'kind' => 'bank', 'bank_code' => 'SA_RIYAD', 'account_number' => (string) $this->bankTreasuries->find($this->bankId)->account_number,
            'deposit_scope' => 'staff', 'deposit_members' => [$insider],
            'withdraw_scope' => 'staff', 'withdraw_members' => [$insider],
        ], $this->bankClerk);

        $before = $this->databaseFingerprint();

        foreach ($this->operations() as $name => $operation) {
            $expected = $name === 'delete statement' ? AccountingError::BankStatementNotFound : AccountingError::BankAccountNotFound;

            $this->assertSame($expected, $this->refusal(fn () => $operation($this->bank, $this->bankId, $outsider, $line, $open, $match, $statement))->error, $name);
        }

        $this->assertDatabaseUnchanged($before);
        $this->assertSame(2, count($this->bank->lines($this->bankId, $insider)['rows']));
    }

    public function testOperations_AreRefusedForAnIdOfAnotherBankAccount(): void
    {
        [$line, $open, $match] = $this->populatedAccount();
        $other                 = $this->openBank();
        $before                = $this->databaseFingerprint();

        $this->assertSame(AccountingError::BankLineNotFound, $this->refusal(fn () => $this->bank->match($other, $line, [$open], $this->bankClerk))->error);
        $this->assertSame(AccountingError::BankMatchNotFound, $this->refusal(fn () => $this->bank->unmatch($other, $match, $this->bankClerk))->error);
        $this->assertSame(AccountingError::BankLineNotFound, $this->refusal(fn () => $this->bank->linesToMatch($other, $line, $this->bankClerk))->error);
        $this->assertDatabaseUnchanged($before);
    }

    public function testBankReconcile_IsNotASensitivePermissionBecauseItMovesNoMoney(): void
    {
        $this->assertNotContains(AccountingGuard::BANK_RECONCILE, AccountingGuard::SENSITIVE);
        $this->assertSame('acc_bank_reconcile', AccountingGuard::BANK_RECONCILE);
        $this->assertSame(1, $this->accountingDb()->table('permissions')->where(['permission_id' => AccountingGuard::BANK_RECONCILE, 'module_id' => 'accounting_treasuries'])->countAllResults());
    }

    public function testLacksBankDetails_FlagsABankAccountOpenedBeforeTheNameAndTheNumberWereAsked(): void
    {
        $legacy = $this->bankTreasuries->create(['name' => 'Legacy bank', 'kind' => 'bank', 'bank_code' => 'SA_RAJHI', 'account_number' => 'SA00000000000000000009', 'branch_id' => 0], $this->bankClerk);

        $this->accountingDb()->table('treasuries')->where('treasury_id', $legacy)->update(['bank_name' => null, 'bank_code' => null, 'account_number' => null]);

        $row = $this->bankTreasuries->find($legacy);

        $this->assertTrue(TreasuryService::lacksBankDetails($row));
        $this->assertFalse(TreasuryService::lacksBankDetails($this->bankTreasuries->find($this->bankId)));
        $this->assertSame($legacy, (int) $this->access->bankAccount($legacy, $this->reconciler())->treasury_id);
    }
}
