<?php

namespace Tests\Libraries\Accounting;

use App\Libraries\Accounting\AccountingError;
use App\Libraries\Accounting\AccountingException;
use App\Libraries\Accounting\AccountingGuard;
use App\Libraries\Accounting\ChequeBooks;
use App\Libraries\Accounting\ChequeService;
use App\Libraries\Accounting\TreasuryService;
use App\Libraries\Organization\BranchException;
use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\DatabaseTestTrait;
use Config\Database;
use Tests\Support\AccountingFixtureTrait;
use Tests\Support\EmployeeFixtureTrait;
use Tests\Support\ItemFixtureTrait;
use Tests\Support\PurchaseAccountingFixtureTrait;
use Tests\Support\SaleAccountingFixtureTrait;

/**
 * The cheque books of the bank accounts: a book belongs to an active bank account and to its
 * branch, has a range of serial numbers, and is put out of use, not deleted, once it wrote cheques.
 */
class ChequeBooksTest extends CIUnitTestCase
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

    private ChequeBooks $books;
    private int $clerk;
    private int $bank;

    public static function setUpBeforeClass(): void
    {
        $seeder = Database::seeder('tests');
        $seeder->call('TestDatabaseBootstrapSeeder');
    }

    protected function setUp(): void
    {
        parent::setUp();

        $this->startBuying();

        $this->books = new ChequeBooks();
        $this->clerk = $this->employeeWithGrants([AccountingGuard::CHEQUES_MANAGE, AccountingGuard::TREASURIES_MANAGE]);
        $this->bank  = $this->bankAccount();
    }

    protected function tearDown(): void
    {
        $this->releaseOpenTransactions();
        $this->stopBuying();

        parent::tearDown();
    }

    private function bankAccount(?int $branchId = null): int
    {
        return (new TreasuryService())->create(['name' => 'Bank ' . uniqid(), 'kind' => 'bank', 'bank_name' => 'Gulf Bank', 'account_number' => strtoupper(uniqid('AC')), 'branch_id' => $branchId ?? 0], $this->clerk);
    }

    /**
     * @param array<string, mixed> $changes
     *
     * @return array<string, mixed>
     */
    private function input(array $changes = []): array
    {
        return [...['treasury_id' => $this->bank, 'name' => 'Book 1', 'first_serial' => '100', 'last_serial' => '199'], ...$changes];
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

    public function testCreate_StoresTheBookInTheBranchOfItsBankAndAuditsIt(): void
    {
        $branch = $this->createBranch();
        $bank   = $this->bankAccount($branch);
        $id     = $this->books->create($this->input(['treasury_id' => $bank]), $this->clerk);
        $book   = $this->books->find($id);

        $this->assertSame([$branch, $bank, 'Book 1', '100', '199', '1'], [(int) $book->branch_id, (int) $book->treasury_id, $book->name, (string) $book->first_serial, (string) $book->last_serial, (string) $book->active]);
        $this->assertSame(1, $this->accountingDb()->table('accounting_audit_log')->where(['entity_type' => 'cheque_book', 'entity_id' => $id, 'action' => 'created'])->countAllResults());
    }

    public function testCreate_RequiresAnActiveBankAccountNotACashTreasury(): void
    {
        $cash = (new TreasuryService())->create(['name' => 'Drawer ' . uniqid(), 'kind' => 'cash'], $this->clerk);

        $this->assertSame(AccountingError::ChequeTreasuryInvalid, $this->refusal(fn () => $this->books->create($this->input(['treasury_id' => $cash]), $this->clerk))->error);
        $this->assertSame(AccountingError::ChequeTreasuryInvalid, $this->refusal(fn () => $this->books->create($this->input(['treasury_id' => 99999]), $this->clerk))->error);

        (new TreasuryService())->deactivate($this->bank, $this->clerk);

        $this->assertSame(AccountingError::ChequeTreasuryInvalid, $this->refusal(fn () => $this->books->create($this->input(), $this->clerk))->error);
        $this->assertSame(0, $this->countRows('cheque_books'));
    }

    public function testCreate_RequiresANameAndARangeWhoseFirstSerialIsNotAfterTheLast(): void
    {
        $this->assertSame(AccountingError::InvalidName, $this->refusal(fn () => $this->books->create($this->input(['name' => ' ']), $this->clerk))->error);
        $this->assertSame(AccountingError::InvalidName, $this->refusal(fn () => $this->books->create($this->input(['name' => str_repeat('a', 101)]), $this->clerk))->error);

        foreach ([['first_serial' => '200', 'last_serial' => '100'], ['first_serial' => 'abc', 'last_serial' => '9'], ['first_serial' => '', 'last_serial' => '9'], ['first_serial' => '1', 'last_serial' => '1234567890123456789']] as $range) {
            $this->assertSame(AccountingError::ChequeBookInvalid, $this->refusal(fn () => $this->books->create($this->input($range), $this->clerk))->error, json_encode($range));
        }

        $this->assertSame(0, $this->countRows('cheque_books'));
    }

    public function testCreate_AcceptsABookOfASingleCheque(): void
    {
        $this->assertGreaterThan(0, $this->books->create($this->input(['first_serial' => '7', 'last_serial' => '7']), $this->clerk));
    }

    public function testCreate_RequiresThePermissionAndTheBranchOfTheBank(): void
    {
        $nobody = $this->employeeWithGrants([]);
        $other  = $this->createBranch();
        $worker = $this->employeeWithGrants([AccountingGuard::CHEQUES_MANAGE], [$other]);

        $this->assertSame(AccountingError::PermissionDenied, $this->refusal(fn () => $this->books->create($this->input(), $nobody))->error);
        $this->assertInstanceOf(BranchException::class, $this->refusal(fn () => $this->books->create($this->input(), $worker)));
        $this->assertSame(0, $this->countRows('cheque_books'));
    }

    public function testCreate_RejectsARangeThatOverlapsAnotherBookOfTheSameBankAccount(): void
    {
        $this->books->create($this->input(), $this->clerk);

        foreach ([['100', '199'], ['150', '250'], ['50', '100'], ['199', '250'], ['0120', '130'], ['1', '1000']] as [$first, $last]) {
            $this->assertSame(AccountingError::ChequeBookInvalid, $this->refusal(fn () => $this->books->create($this->input(['name' => 'Book 2', 'first_serial' => $first, 'last_serial' => $last]), $this->clerk))->error, $first . '-' . $last);
        }

        $this->assertSame(1, $this->countRows('cheque_books'));
    }

    public function testCreate_AcceptsAnAdjacentRangeAndTheSameRangeOfAnotherBankAccount(): void
    {
        $this->books->create($this->input(), $this->clerk);
        $this->books->create($this->input(['name' => 'Book 2', 'first_serial' => '200', 'last_serial' => '299']), $this->clerk);
        $this->books->create($this->input(['name' => 'Book 0', 'first_serial' => '1', 'last_serial' => '99']), $this->clerk);
        $this->books->create($this->input(['treasury_id' => $this->bankAccount(), 'name' => 'Other bank']), $this->clerk);

        $this->assertSame(4, $this->countRows('cheque_books'));
    }

    public function testUpdate_RejectsAMovedRangeThatOverlapsAnotherBookAndAcceptsOneThatDoesNot(): void
    {
        $this->books->create($this->input(), $this->clerk);

        $second = $this->books->create($this->input(['name' => 'Book 2', 'first_serial' => '200', 'last_serial' => '299']), $this->clerk);

        $this->assertSame(AccountingError::ChequeBookInvalid, $this->refusal(fn () => $this->books->update($second, ['first_serial' => '150'], $this->clerk))->error);
        $this->assertSame('200', (string) $this->books->find($second)->first_serial);

        $this->books->update($second, ['last_serial' => '350'], $this->clerk);

        $this->assertSame('350', (string) $this->books->find($second)->last_serial, 'A book does not overlap itself');
    }

    public function testUpdate_PutsABookOutOfUseWhoseRangeAlreadyOverlapsAnother(): void
    {
        $this->books->create($this->input(), $this->clerk);

        $this->accountingDb()->table('cheque_books')->insert(['company_id' => 1, 'branch_id' => 1, 'treasury_id' => $this->bank, 'name' => 'Old overlap', 'first_serial' => '150', 'last_serial' => '250', 'created_by' => $this->clerk]);

        $overlap = (int) $this->accountingDb()->insertID();

        $this->books->update($overlap, ['name' => 'Old overlap, retired', 'active' => false], $this->clerk);

        $this->assertSame(['Old overlap, retired', '0'], [$this->books->find($overlap)->name, (string) $this->books->find($overlap)->active]);
        $this->assertSame(AccountingError::ChequeBookInvalid, $this->refusal(fn () => $this->books->update($overlap, ['first_serial' => '160'], $this->clerk))->error);
    }

    public function testUpdate_RenamesTheBookAndPutsItOutOfUse(): void
    {
        $id = $this->books->create($this->input(), $this->clerk);

        $this->books->update($id, ['name' => 'Renamed', 'active' => false], $this->clerk);

        $book = $this->books->find($id);

        $this->assertSame(['Renamed', '0'], [$book->name, (string) $book->active]);
        $this->assertSame([], $this->books->all(null, true));
        $this->assertCount(1, $this->books->all());
    }

    public function testUpdate_KeepsEveryChequeWrittenOutOfTheBookInsideItsRange(): void
    {
        $id = $this->books->create($this->input(), $this->clerk);

        (new ChequeService())->issue(['party_id' => $this->supplierId, 'book_id' => $id, 'cheque_number' => '150', 'amount' => '10.00', 'issue_date' => date('Y-m-d'), 'due_date' => date('Y-m-d')], $this->clerk);

        $this->assertSame(AccountingError::ChequeBookInvalid, $this->refusal(fn () => $this->books->update($id, ['first_serial' => '151'], $this->clerk))->error);
        $this->assertSame(AccountingError::ChequeBookInvalid, $this->refusal(fn () => $this->books->update($id, ['last_serial' => '149'], $this->clerk))->error);

        $this->books->update($id, ['first_serial' => '150', 'last_serial' => '150'], $this->clerk);

        $this->assertSame(['150', '150'], [(string) $this->books->find($id)->first_serial, (string) $this->books->find($id)->last_serial]);
    }

    public function testUpdate_RequiresThePermissionTheBranchAndABookThatExists(): void
    {
        $id     = $this->books->create($this->input(), $this->clerk);
        $nobody = $this->employeeWithGrants([]);
        $other  = $this->createBranch();
        $worker = $this->employeeWithGrants([AccountingGuard::CHEQUES_MANAGE], [$other]);

        $this->assertSame(AccountingError::PermissionDenied, $this->refusal(fn () => $this->books->update($id, ['name' => 'x'], $nobody))->error);
        $this->assertInstanceOf(BranchException::class, $this->refusal(fn () => $this->books->update($id, ['name' => 'x'], $worker)));
        $this->assertSame(AccountingError::ChequeBookNotFound, $this->refusal(fn () => $this->books->update(99999, ['name' => 'x'], $this->clerk))->error);
        $this->assertSame('Book 1', $this->books->find($id)->name);
    }

    public function testDelete_RemovesABookThatWroteNothingAndRefusesOneThatDid(): void
    {
        $empty = $this->books->create($this->input(), $this->clerk);
        $used  = $this->books->create($this->input(['name' => 'Used', 'first_serial' => '200', 'last_serial' => '299']), $this->clerk);

        (new ChequeService())->issue(['party_id' => $this->supplierId, 'book_id' => $used, 'cheque_number' => '200', 'amount' => '10.00', 'issue_date' => date('Y-m-d'), 'due_date' => date('Y-m-d')], $this->clerk);

        $this->books->delete($empty, $this->clerk);

        $this->assertSame(AccountingError::ChequeBookNotFound, $this->refusal(fn () => $this->books->find($empty))->error);
        $this->assertSame(AccountingError::ChequeBookInUse, $this->refusal(fn () => $this->books->delete($used, $this->clerk))->error);
        $this->assertSame(1, $this->countRows('cheque_books'));
    }

    public function testDelete_RequiresThePermissionAndTheBranch(): void
    {
        $id     = $this->books->create($this->input(), $this->clerk);
        $nobody = $this->employeeWithGrants([]);
        $other  = $this->createBranch();
        $worker = $this->employeeWithGrants([AccountingGuard::CHEQUES_MANAGE], [$other]);

        $this->assertSame(AccountingError::PermissionDenied, $this->refusal(fn () => $this->books->delete($id, $nobody))->error);
        $this->assertInstanceOf(BranchException::class, $this->refusal(fn () => $this->books->delete($id, $worker)));
        $this->assertSame(1, $this->countRows('cheque_books'));
    }

    public function testAll_ListsTheBooksOfTheBranchesTheReaderMaySeeWithTheChequesWritten(): void
    {
        $branch = $this->createBranch();
        $first  = $this->books->create($this->input(), $this->clerk);
        $second = $this->books->create($this->input(['treasury_id' => $this->bankAccount($branch), 'name' => 'Branch book']), $this->clerk);

        (new ChequeService())->issue(['party_id' => $this->supplierId, 'book_id' => $first, 'cheque_number' => '100', 'amount' => '10.00', 'issue_date' => date('Y-m-d'), 'due_date' => date('Y-m-d')], $this->clerk);

        $this->assertSame([$second, $first], array_map(static fn (object $book): int => (int) $book->book_id, $this->books->all()));
        $this->assertSame([$first], array_map(static fn (object $book): int => (int) $book->book_id, $this->books->all([1])));
        $this->assertSame([], $this->books->all([]));
        $this->assertSame(['0', '1'], array_map(static fn (object $book): string => (string) $book->written, $this->books->all()));
    }

    public function testUpdate_AuditsTheChangeOfABook(): void
    {
        $id = $this->books->create($this->input(), $this->clerk);

        $this->books->update($id, ['name' => 'Renamed'], $this->clerk);

        $this->assertSame(1, $this->accountingDb()->table('accounting_audit_log')->where(['entity_type' => 'cheque_book', 'entity_id' => $id, 'action' => 'updated'])->countAllResults());
    }

    public function testDelete_AuditsTheDeletionOfABook(): void
    {
        $id = $this->books->create($this->input(), $this->clerk);

        $this->books->delete($id, $this->clerk);

        $this->assertSame(1, $this->accountingDb()->table('accounting_audit_log')->where(['entity_type' => 'cheque_book', 'entity_id' => $id, 'action' => 'deleted'])->countAllResults());
    }

    public function testAll_DoesNotCountAChequeThatWasDeletedAsWrittenOutOfABook(): void
    {
        $id     = $this->books->create($this->input(), $this->clerk);
        $cheque = (new ChequeService())->issue(['party_id' => $this->supplierId, 'book_id' => $id, 'cheque_number' => '100', 'amount' => '10.00', 'issue_date' => date('Y-m-d'), 'due_date' => date('Y-m-d')], $this->clerk);

        $this->assertSame('1', (string) $this->books->all()[0]->written);

        (new ChequeService())->delete($cheque, $this->clerk);

        $this->assertSame('0', (string) $this->books->all()[0]->written);
    }

    public function testUpdate_IgnoresAChequeThatWasDeletedWhenTheRangeNarrows(): void
    {
        $id     = $this->books->create($this->input(), $this->clerk);
        $cheque = (new ChequeService())->issue(['party_id' => $this->supplierId, 'book_id' => $id, 'cheque_number' => '150', 'amount' => '10.00', 'issue_date' => date('Y-m-d'), 'due_date' => date('Y-m-d')], $this->clerk);

        (new ChequeService())->delete($cheque, $this->clerk);

        $this->books->update($id, ['first_serial' => '160', 'last_serial' => '170'], $this->clerk);

        $this->assertSame(['160', '170'], [(string) $this->books->find($id)->first_serial, (string) $this->books->find($id)->last_serial]);
    }
}
