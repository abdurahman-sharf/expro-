<?php

namespace Tests\Libraries\Accounting;

use App\Libraries\Accounting\AccountingError;
use App\Libraries\Accounting\AccountingException;
use App\Libraries\Accounting\AccountingGuard;
use App\Libraries\Accounting\ChequeBooks;
use App\Libraries\Accounting\ChequeService;
use App\Libraries\Accounting\ChequeSources;
use App\Libraries\Accounting\ChequeTransition;
use App\Libraries\Accounting\CreditRefunds;
use App\Libraries\Accounting\CreditSource;
use App\Libraries\Accounting\CustomerLock;
use App\Libraries\Accounting\PostingError;
use App\Libraries\Accounting\PostingException;
use App\Libraries\Accounting\SupplierLock;
use App\Libraries\Accounting\TreasuryScopes;
use App\Libraries\Accounting\TreasuryService;
use App\Libraries\Numbering\DocumentNumbering;
use App\Libraries\Numbering\DocumentType;
use App\Libraries\Organization\BranchException;
use CodeIgniter\Events\Events;
use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\DatabaseTestTrait;
use Config\Database;
use Tests\Support\AccountingFixtureTrait;
use Tests\Support\CreditRefundFixtureTrait;
use Tests\Support\CreditSourceFixtureTrait;
use Tests\Support\DatabaseFingerprintTrait;
use Tests\Support\EmployeeFixtureTrait;
use Tests\Support\ItemFixtureTrait;
use Tests\Support\ParityCaseTrait;
use Tests\Support\PurchaseAccountingFixtureTrait;
use Tests\Support\SaleAccountingFixtureTrait;

/**
 * The cheque cycle: a cheque received from a customer or paid to a supplier moves from status to
 * status, each change posting a journal of its own and being taken back by reversing it, and what
 * the party holds in cheques is credit that follows the status.
 */
class ChequeServiceTest extends CIUnitTestCase
{
    use DatabaseTestTrait;
    use AccountingFixtureTrait;
    use CreditRefundFixtureTrait;
    use CreditSourceFixtureTrait;
    use DatabaseFingerprintTrait;
    use EmployeeFixtureTrait;
    use ItemFixtureTrait;
    use ParityCaseTrait;
    use SaleAccountingFixtureTrait;
    use PurchaseAccountingFixtureTrait;

    protected $migrate     = true;
    protected $migrateOnce = true;
    protected $refresh     = false;
    protected $namespace   = null;

    private ChequeService $cheques;
    private int $clerk;
    private int $bank;
    private int $book;
    private string $today;

    public static function setUpBeforeClass(): void
    {
        $seeder = Database::seeder('tests');
        $seeder->call('TestDatabaseBootstrapSeeder');
    }

    protected function setUp(): void
    {
        parent::setUp();

        $this->startBuying();

        $this->cheques = new ChequeService();
        $this->clerk   = $this->employeeWithGrants([AccountingGuard::CHEQUES_MANAGE, AccountingGuard::TREASURIES_MANAGE]);
        $this->bank    = $this->bankAccount();
        $this->book    = (new ChequeBooks())->create(['treasury_id' => $this->bank, 'name' => 'Book 1', 'first_serial' => '800001', 'last_serial' => '800050'], $this->clerk);
        $this->today   = date('Y-m-d');
    }

    protected function tearDown(): void
    {
        $this->releaseOpenTransactions();
        $this->stopBuying();

        $db = $this->accountingDb();
        $db->table('document_numbers')->truncate();
        $db->table('document_sequences')->truncate();

        parent::tearDown();
    }

    private function bankAccount(string $name = 'Bank'): int
    {
        return (new TreasuryService())->create(['name' => $name . ' ' . uniqid(), 'kind' => 'bank', 'bank_name' => 'Gulf Bank', 'account_number' => strtoupper(uniqid('AC')), 'branch_id' => 0], $this->clerk);
    }

    /**
     * @param array<string, mixed> $changes
     *
     * @return array<string, mixed>
     */
    private function input(array $changes = []): array
    {
        return [...[
            'party_id'      => $this->customerId,
            'treasury_id'   => $this->bank,
            'cheque_number' => '710001',
            'amount'        => '100.00',
            'issue_date'    => $this->today,
            'due_date'      => $this->today,
            'drawer_name'   => 'Omar Khaled',
            'description'   => 'Invoice payment',
        ], ...$changes];
    }

    /**
     * @param array<string, mixed> $changes
     *
     * @return array<string, mixed>
     */
    private function paidInput(array $changes = []): array
    {
        return [...[
            'party_id'      => $this->supplierId,
            'book_id'       => $this->book,
            'cheque_number' => '800001',
            'amount'        => '200.00',
            'issue_date'    => $this->today,
            'due_date'      => $this->today,
        ], ...$changes];
    }

    private function received(array $changes = []): int
    {
        return $this->cheques->receive($this->input($changes), $this->clerk);
    }

    private function paid(array $changes = [], ?string $deliverOn = null): int
    {
        return $this->cheques->issue($this->paidInput($changes), $this->clerk, $deliverOn);
    }

    private function bankLedgerAccount(): int
    {
        return (int) (new TreasuryService())->accountOf($this->bank);
    }

    private function refusal(callable $action): AccountingException|PostingException|BranchException
    {
        try {
            $action();
        } catch (AccountingException|PostingException|BranchException $exception) {
            return $exception;
        }

        $this->fail('The change was not refused.');
    }

    /**
     * @return list<string> The lines of the journals of a source of the cheque that stand, as "account=debit/credit", journal by journal.
     */
    private function standingLines(string $source, int $chequeId): array
    {
        $lines = [];

        $journals = $this->accountingDb()->table('accounting_journals')->where(['source_type' => $source, 'source_id' => $chequeId, 'status' => 'posted'])->orderBy('revision')->get()->getResult();

        foreach ($journals as $journal) {
            $lines[] = implode(' ', array_map(
                static fn (object $line): string => $line->account_id . '=' . bcadd($line->debit, '0', 2) . '/' . bcadd($line->credit, '0', 2),
                $this->accountingDb()->table('accounting_journal_lines')->where('journal_id', $journal->journal_id)->orderBy('line_no')->get()->getResult(),
            ));
        }

        return $lines;
    }

    /**
     * @return list<object>
     */
    private function auditOf(int $chequeId): array
    {
        return $this->accountingDb()->table('accounting_audit_log')->where(['entity_type' => 'cheque', 'entity_id' => $chequeId])->orderBy('audit_id')->get()->getResult();
    }

    private function statusOf(int $chequeId): string
    {
        return (string) $this->accountingDb()->table('cheques')->where('cheque_id', $chequeId)->get()->getRow()->status;
    }

    private function countJournalsOf(string $source, int $chequeId, ?string $status = null): int
    {
        $where = ['source_type' => $source, 'source_id' => $chequeId];

        if ($status !== null) {
            $where['status'] = $status;
        }

        return $this->accountingDb()->table('accounting_journals')->where($where)->countAllResults();
    }

    /**
     * @return list<string> Every statement the database ran while the action ran.
     */
    private function queriesOf(callable $action): array
    {
        $queries  = [];
        $listener = static function ($query) use (&$queries): void {
            $queries[] = (string) $query->getQuery();
        };

        Events::on('DBQuery', $listener);

        try {
            $action();
        } finally {
            Events::removeListener('DBQuery', $listener);
        }

        return $queries;
    }

    /**
     * @param list<string> $queries
     */
    private function firstIndexOf(array $queries, callable $match): int
    {
        foreach ($queries as $index => $sql) {
            if ($match($sql)) {
                return $index;
            }
        }

        return -1;
    }

    private function futureDate(int $days): string
    {
        return date('Y-m-d', strtotime("+{$days} days"));
    }

    public function testReceive_PostsNotesReceivableAgainstTheCustomerOnTheIssueDate(): void
    {
        $issued   = date('Y-m-d', strtotime('-3 days'));
        $chequeId = $this->received(['issue_date' => $issued, 'due_date' => $this->futureDate(60)]);

        $this->assertSame([$this->accountId('notes_receivable') . '=100.00/0.00 ' . $this->customerAccount() . '=0.00/100.00'], $this->standingLines(ChequeSources::RECEIVED, $chequeId));

        $journal = $this->accountingDb()->table('accounting_journals')->where(['source_type' => ChequeSources::RECEIVED, 'source_id' => $chequeId])->get()->getRow();

        $this->assertSame($issued, $journal->journal_date);
        $this->assertSame('received', $this->statusOf($chequeId));
        $this->assertSame('-100.00', $this->balance($this->customerAccount()));
        $this->assertSame('100.00', $this->balance($this->accountId('notes_receivable')));
    }

    public function testReceive_TakesTheBranchOfTheBankAndWritesTheFirstEvent(): void
    {
        $branch = $this->createBranch();
        $bank   = (new TreasuryService())->create(['name' => 'Branch bank ' . uniqid(), 'kind' => 'bank', 'bank_name' => 'Gulf Bank', 'account_number' => strtoupper(uniqid('AC')), 'branch_id' => $branch], $this->clerk);

        $chequeId = $this->received(['treasury_id' => $bank]);
        $row      = $this->cheques->find($chequeId);

        $this->assertSame($branch, (int) $row->branch_id);
        $this->assertSame($branch, (int) $this->accountingDb()->table('accounting_journals')->where(['source_type' => ChequeSources::RECEIVED, 'source_id' => $chequeId])->get()->getRow()->branch_id);

        $events = $this->cheques->events($chequeId);

        $this->assertSame(['receive'], array_column($events, 'transition'));
        $this->assertNull($events[0]->from_status);
        $this->assertSame('received', $events[0]->to_status);
    }

    public function testReceive_SettlesTheOpenInvoicesLikeAPaymentAndKeepsTheLedgerTied(): void
    {
        $invoice = $this->invoiceOf('100.00');

        $this->assertSame('100.0000', $this->position()->open());

        $chequeId = $this->received();

        $this->assertSame(['cheque:' . $chequeId . '->' . $invoice . ':100.00'], $this->applications());
        $this->assertSame('0.0000', $this->position()->open());
        $this->assertSame('0.0000', $this->position()->credit());
        $this->assertTies();
    }

    public function testReceive_HoldsWhatTheInvoicesDoNotTakeAsCredit(): void
    {
        $this->invoiceOf('100.00');

        $chequeId = $this->received(['amount' => '150.00']);

        $credits = $this->position()->credits;

        $this->assertSame([CreditSource::CHEQUE, $chequeId, '150.0000', '50.0000'], [$credits[0]->type, $credits[0]->sourceId, $credits[0]->amount, $credits[0]->available()]);
        $this->assertTies();
    }

    public function testReceive_RejectsAnAmountThatIsNotMoreThanZero(): void
    {
        foreach (['0', '0.00', '-5', 'abc', '', '1.00001'] as $amount) {
            $this->assertSame(AccountingError::ChequeAmountInvalid, $this->refusal(fn () => $this->received(['amount' => $amount]))->error, "amount {$amount}");
        }

        $this->assertSame(0, $this->countRows('cheques'));
    }

    public function testReceive_RejectsADueDateBeforeTheIssueDate(): void
    {
        $this->assertSame(AccountingError::ChequeDateInvalid, $this->refusal(fn () => $this->received(['issue_date' => $this->today, 'due_date' => date('Y-m-d', strtotime('-1 day'))]))->error);
    }

    public function testReceive_RejectsAnIssueDateAfterToday(): void
    {
        $this->assertSame(AccountingError::ChequeDateInvalid, $this->refusal(fn () => $this->received(['issue_date' => $this->futureDate(1), 'due_date' => $this->futureDate(1)]))->error);
    }

    public function testReceive_RejectsADateThatIsNotADate(): void
    {
        $this->assertSame(AccountingError::InvalidDate, $this->refusal(fn () => $this->received(['issue_date' => '2026-02-30']))->error);
    }

    public function testReceive_RequiresTheNumberOfTheCheque(): void
    {
        $this->assertSame(AccountingError::ChequeNumberRequired, $this->refusal(fn () => $this->received(['cheque_number' => '  ']))->error);
        $this->assertSame(AccountingError::ChequeNumberRequired, $this->refusal(fn () => $this->received(['cheque_number' => str_repeat('9', 65)]))->error);
    }

    public function testReceive_RejectsACustomerThatDoesNotExist(): void
    {
        $this->assertSame(AccountingError::ChequePartyMissing, $this->refusal(fn () => $this->received(['party_id' => 999999]))->error);
    }

    public function testReceive_RejectsATreasuryThatIsDeactivatedOrMissing(): void
    {
        (new TreasuryService())->deactivate($this->bank, $this->clerk);

        $this->assertSame(AccountingError::TreasuryInactive, $this->refusal(fn () => $this->received())->error);
        $this->assertSame(AccountingError::TreasuryNotFound, $this->refusal(fn () => $this->received(['treasury_id' => 999999]))->error);
    }

    public function testReceive_RejectsACashTreasuryBecauseAChequeIsCollectedIntoABankAccount(): void
    {
        $cash = (new TreasuryService())->create(['name' => 'Drawer ' . uniqid(), 'kind' => 'cash'], $this->clerk);

        $this->assertSame(AccountingError::ChequeTreasuryInvalid, $this->refusal(fn () => $this->received(['treasury_id' => $cash]))->error);
        $this->assertSame(0, $this->countRows('cheques'));
        $this->assertSame(0, $this->countRows('accounting_journals'));
    }

    public function testUpdate_RejectsMovingAReceivedChequeToACashTreasury(): void
    {
        $cash     = (new TreasuryService())->create(['name' => 'Drawer ' . uniqid(), 'kind' => 'cash'], $this->clerk);
        $chequeId = $this->received();

        $this->assertSame(AccountingError::ChequeTreasuryInvalid, $this->refusal(fn () => $this->cheques->update($chequeId, $this->input(['treasury_id' => $cash]), $this->clerk))->error);
        $this->assertSame($this->bank, (int) $this->cheques->find($chequeId)->treasury_id);
    }

    public function testCollect_AcceptsAChequeWhoseTreasuryIsNoBankAnyMoreBecauseItWasEnteredBeforeTheRule(): void
    {
        $chequeId = $this->received();

        $this->accountingDb()->table('treasuries')->where('treasury_id', $this->bank)->update(['kind' => 'cash']);

        $this->cheques->collect($chequeId, $this->today, null, $this->clerk);

        $this->assertSame('collected', $this->statusOf($chequeId));
    }

    public function testReceive_RequiresThePermissionToChangeCheques(): void
    {
        $nobody = $this->employeeWithGrants([]);

        $this->assertSame(AccountingError::PermissionDenied, $this->refusal(fn () => $this->cheques->receive($this->input(), $nobody))->error);
        $this->assertSame(0, $this->countRows('cheques'));
    }

    public function testReceive_RequiresTheBranchOfTheBank(): void
    {
        $other  = $this->createBranch();
        $worker = $this->employeeWithGrants([AccountingGuard::CHEQUES_MANAGE], [$other]);

        $this->assertInstanceOf(BranchException::class, $this->refusal(fn () => $this->cheques->receive($this->input(), $worker)));
        $this->assertSame(0, $this->countRows('cheques'));
    }

    public function testReceive_AssignsANumberFromTheSequenceOfReceivedCheques(): void
    {
        $manager = $this->employeeWithGrants(['config']);

        (new DocumentNumbering())->configure(DocumentType::ReceivedCheque, ['enabled' => true, 'prefix' => 'RC-', 'padding' => 4], $manager);

        $first  = $this->received();
        $second = $this->received(['cheque_number' => '710002']);

        $this->assertSame([$first => 'RC-0001', $second => 'RC-0002'], (new DocumentNumbering())->numbers(DocumentType::ReceivedCheque, 'cheques', [$first, $second]));
    }

    public function testReceive_WritesNothingWhenThePeriodIsClosed(): void
    {
        $this->closeThrough($this->today);

        $failure = $this->refusal(fn () => $this->received());

        $this->assertInstanceOf(PostingException::class, $failure);
        $this->assertSame(PostingError::PeriodClosed, $failure->error);
        $this->assertSame(0, $this->countRows('cheques'));
        $this->assertSame(0, $this->countRows('cheque_events'));
        $this->assertSame(1, $this->accountingDb()->table('accounting_posting_failures')->where('source_type', ChequeSources::RECEIVED)->countAllResults());
    }

    public function testDeposit_MovesTheChequeFromNotesReceivableToFundsUnderCollection(): void
    {
        $chequeId = $this->received();

        $this->cheques->deposit($chequeId, $this->today, $this->clerk);

        $this->assertSame([$this->accountId('cheques_under_collection') . '=100.00/0.00 ' . $this->accountId('notes_receivable') . '=0.00/100.00'], $this->standingLines(ChequeSources::DEPOSITED, $chequeId));
        $this->assertSame('deposited', $this->statusOf($chequeId));
        $this->assertSame('0.00', $this->balance($this->accountId('notes_receivable')));
        $this->assertSame('100.00', $this->balance($this->accountId('cheques_under_collection')));
        $this->assertSame('0.00', $this->balance($this->bankLedgerAccount()));
    }

    public function testDeposit_IsAcceptedBeforeTheDueDate(): void
    {
        $chequeId = $this->received(['due_date' => $this->futureDate(60)]);

        $this->cheques->deposit($chequeId, $this->today, $this->clerk);

        $this->assertSame('deposited', $this->statusOf($chequeId));
    }

    public function testDeposit_RejectsAChequeThatIsNotReceivedAnyMore(): void
    {
        $chequeId = $this->received();

        $this->cheques->deposit($chequeId, $this->today, $this->clerk);

        $this->assertSame(AccountingError::ChequeStatusInvalid, $this->refusal(fn () => $this->cheques->deposit($chequeId, $this->today, $this->clerk))->error);
        $this->assertSame(1, $this->countJournalsOf(ChequeSources::DEPOSITED, $chequeId));
    }

    public function testDeposit_RejectsADateBeforeTheChequeWasIssuedOrAfterToday(): void
    {
        $chequeId = $this->received(['issue_date' => date('Y-m-d', strtotime('-5 days')), 'due_date' => date('Y-m-d', strtotime('-5 days'))]);

        $this->assertSame(AccountingError::ChequeDateInvalid, $this->refusal(fn () => $this->cheques->deposit($chequeId, date('Y-m-d', strtotime('-6 days')), $this->clerk))->error);
        $this->assertSame(AccountingError::ChequeDateInvalid, $this->refusal(fn () => $this->cheques->deposit($chequeId, $this->futureDate(1), $this->clerk))->error);
        $this->assertSame('received', $this->statusOf($chequeId));
    }

    public function testCollect_DebitsTheBankAndCreditsNotesReceivableWithoutADeposit(): void
    {
        $chequeId = $this->received();

        $this->cheques->collect($chequeId, $this->today, null, $this->clerk);

        $this->assertSame([$this->bankLedgerAccount() . '=100.00/0.00 ' . $this->accountId('notes_receivable') . '=0.00/100.00'], $this->standingLines(ChequeSources::COLLECTED, $chequeId));
        $this->assertSame('collected', $this->statusOf($chequeId));
        $this->assertSame('100.00', $this->balance($this->bankLedgerAccount()));
        $this->assertSame('0.00', $this->balance($this->accountId('notes_receivable')));
        $this->assertSame('100.00', $this->assertTreasuryTies());
    }

    private function assertTreasuryTies(): string
    {
        $treasuries = new TreasuryService();
        $balance    = bcadd($treasuries->position($this->bank)->balance, '0', 2);

        $this->assertSame($this->balance((int) $treasuries->accountOf($this->bank)), $balance);

        return $balance;
    }

    public function testCollect_RejectsADateBeforeTheDueDate(): void
    {
        $chequeId = $this->received(['due_date' => $this->futureDate(60)]);

        $failure = $this->refusal(fn () => $this->cheques->collect($chequeId, $this->today, null, $this->clerk));

        $this->assertSame(AccountingError::ChequeCollectBeforeDue, $failure->error);
        $this->assertSame('received', $this->statusOf($chequeId));
        $this->assertSame(0, $this->countJournalsOf(ChequeSources::COLLECTED, $chequeId));
    }

    public function testCollect_AcceptsTheDueDateThatIsStillToCome(): void
    {
        $due      = $this->futureDate(60);
        $chequeId = $this->received(['due_date' => $due]);

        $this->cheques->collect($chequeId, $due, null, $this->clerk);

        $journal = $this->accountingDb()->table('accounting_journals')->where(['source_type' => ChequeSources::COLLECTED, 'source_id' => $chequeId])->get()->getRow();

        $this->assertSame($due, $journal->journal_date);
        $this->assertSame('collected', $this->statusOf($chequeId));
    }

    public function testCollect_RejectsAMonthThatEndsBeforeTheDueDateAfterADeposit(): void
    {
        $chequeId = $this->received(['due_date' => $this->futureDate(30)]);

        $this->cheques->deposit($chequeId, $this->today, $this->clerk);

        $this->assertSame(AccountingError::ChequeCollectBeforeDue, $this->refusal(fn () => $this->cheques->collect($chequeId, $this->futureDate(10), '0', $this->clerk))->error);
        $this->assertSame('deposited', $this->statusOf($chequeId));
    }

    public function testCollect_NeedsTheFeesAfterADepositAndPostsThemToTheFeesAccount(): void
    {
        $chequeId = $this->received();

        $this->cheques->deposit($chequeId, $this->today, $this->clerk);

        $this->assertSame(AccountingError::ChequeFeesRequired, $this->refusal(fn () => $this->cheques->collect($chequeId, $this->today, null, $this->clerk))->error);
        $this->assertSame('deposited', $this->statusOf($chequeId));

        $this->cheques->collect($chequeId, $this->today, '2.50', $this->clerk);

        $this->assertSame([$this->bankLedgerAccount() . '=97.50/0.00 ' . $this->accountId('cheque_deposit_fees') . '=2.50/0.00 ' . $this->accountId('cheques_under_collection') . '=0.00/100.00'], $this->standingLines(ChequeSources::DEPOSIT_COLLECTED, $chequeId));
        $this->assertSame('collected', $this->statusOf($chequeId));
        $this->assertSame('97.50', $this->assertTreasuryTies());
        $this->assertSame('0.00', $this->balance($this->accountId('cheques_under_collection')));
        $this->assertSame('2.50', $this->balance($this->accountId('cheque_deposit_fees')));
    }

    public function testCollect_AcceptsNoFeesAtAllAfterADeposit(): void
    {
        $chequeId = $this->received();

        $this->cheques->deposit($chequeId, $this->today, $this->clerk);
        $this->cheques->collect($chequeId, $this->today, '0', $this->clerk);

        $this->assertSame([$this->bankLedgerAccount() . '=100.00/0.00 ' . $this->accountId('cheques_under_collection') . '=0.00/100.00'], $this->standingLines(ChequeSources::DEPOSIT_COLLECTED, $chequeId));
    }

    public function testCollect_RejectsFeesThatAreNegativeOrMoreThanTheCheque(): void
    {
        $chequeId = $this->received();

        $this->cheques->deposit($chequeId, $this->today, $this->clerk);

        foreach (['-1', '100.01', 'abc'] as $fees) {
            $this->assertSame(AccountingError::ChequeFeesInvalid, $this->refusal(fn () => $this->cheques->collect($chequeId, $this->today, $fees, $this->clerk))->error, "fees {$fees}");
        }

        $this->assertSame('deposited', $this->statusOf($chequeId));
    }

    public function testCollect_RejectsFeesOnAChequeThatWasNotDeposited(): void
    {
        $chequeId = $this->received();

        $this->assertSame(AccountingError::ChequeFeesInvalid, $this->refusal(fn () => $this->cheques->collect($chequeId, $this->today, '1.00', $this->clerk))->error);
        $this->assertSame('received', $this->statusOf($chequeId));
    }

    public function testCollect_RejectsAnEmployeeWhoMayNotDepositIntoTheBank(): void
    {
        $chequeId = $this->received();
        $clerk    = $this->employeeWithGrants([AccountingGuard::CHEQUES_MANAGE]);

        $other = $this->employeeWithGrants([]);

        (new TreasuryScopes())->apply($this->bank, (new TreasuryScopes())->validated(['deposit_scope' => TreasuryScopes::STAFF, 'deposit_members' => [$other]]), null);

        $failure = $this->refusal(fn () => $this->cheques->collect($chequeId, $this->today, null, $clerk));

        $this->assertSame(AccountingError::TreasuryDepositDenied, $failure->error);
        $this->assertSame('received', $this->statusOf($chequeId));
        $this->assertSame(0, $this->countJournalsOf(ChequeSources::COLLECTED, $chequeId));
    }

    public function testReject_GivesTheCustomerBackAndTheInvoicesTooWhileReceived(): void
    {
        $invoice  = $this->invoiceOf('100.00');
        $chequeId = $this->received();

        $this->assertSame('0.0000', $this->position()->open());

        $this->cheques->reject($chequeId, $this->today, $this->clerk, 'Bounced');

        $this->assertSame([$this->customerAccount() . '=100.00/0.00 ' . $this->accountId('notes_receivable') . '=0.00/100.00'], $this->standingLines(ChequeSources::REJECTED, $chequeId));
        $this->assertSame('rejected', $this->statusOf($chequeId));
        $this->assertSame('100.00', $this->balance($this->customerAccount()));
        $this->assertSame('0.00', $this->balance($this->accountId('notes_receivable')));
        $this->assertSame([], $this->applications());
        $this->assertSame('100.0000', $this->position()->open());
        $this->assertSame($invoice, $this->position()->invoices[0]->saleId);
        $this->assertTies();
    }

    public function testReject_PostsTwoJournalsAfterADepositThatClearBothAccountsOfTheCheque(): void
    {
        $chequeId = $this->received();

        $this->cheques->deposit($chequeId, $this->today, $this->clerk);
        $this->cheques->reject($chequeId, $this->today, $this->clerk);

        $this->assertSame([
            $this->customerAccount() . '=100.00/0.00 ' . $this->accountId('notes_receivable') . '=0.00/100.00',
            $this->accountId('notes_receivable') . '=100.00/0.00 ' . $this->accountId('cheques_under_collection') . '=0.00/100.00',
        ], $this->standingLines(ChequeSources::DEPOSIT_REJECTED, $chequeId));
        $this->assertSame('rejected', $this->statusOf($chequeId));
        $this->assertSame('0.00', $this->balance($this->customerAccount()));
        $this->assertSame('0.00', $this->balance($this->accountId('cheques_under_collection')));
        $this->assertSame('0.00', $this->balance($this->accountId('notes_receivable')));
    }

    public function testUndo_ReversesTheJournalOfTheLastChangeInsteadOfDeletingIt(): void
    {
        $chequeId = $this->received();

        $this->cheques->collect($chequeId, $this->today, null, $this->clerk);
        $this->cheques->undo($chequeId, $this->clerk);

        $this->assertSame('received', $this->statusOf($chequeId));
        $this->assertSame('0.00', $this->balance($this->bankLedgerAccount()));
        $this->assertSame('100.00', $this->balance($this->accountId('notes_receivable')));
        $this->assertSame(1, $this->countJournalsOf(ChequeSources::COLLECTED, $chequeId, 'reversed'));
        $this->assertSame(1, $this->accountingDb()->table('accounting_journals')->where('source_type', 'reversal')->countAllResults());
        $this->assertSame([], $this->standingLines(ChequeSources::COLLECTED, $chequeId));

        $events = $this->cheques->events($chequeId);

        $this->assertNotNull($events[1]->undone_at);
        $this->assertNull($events[0]->undone_at);
    }

    public function testUndo_GoesBackToDepositedFromACollectionAfterADeposit(): void
    {
        $chequeId = $this->received();

        $this->cheques->deposit($chequeId, $this->today, $this->clerk);
        $this->cheques->collect($chequeId, $this->today, '2.50', $this->clerk);
        $this->cheques->undo($chequeId, $this->clerk);

        $this->assertSame('deposited', $this->statusOf($chequeId));
        $this->assertSame('0.00', $this->balance($this->bankLedgerAccount()));
        $this->assertSame('0.00', $this->balance($this->accountId('cheque_deposit_fees')));
        $this->assertSame('100.00', $this->balance($this->accountId('cheques_under_collection')));
        $this->assertSame('0.0000', $this->accountingDb()->table('cheques')->where('cheque_id', $chequeId)->get()->getRow()->deposit_fees);
    }

    public function testUndo_ReversesBothJournalsOfARejectionAfterADeposit(): void
    {
        $chequeId = $this->received();

        $this->cheques->deposit($chequeId, $this->today, $this->clerk);
        $this->cheques->reject($chequeId, $this->today, $this->clerk);
        $this->cheques->undo($chequeId, $this->clerk);

        $this->assertSame('deposited', $this->statusOf($chequeId));
        $this->assertSame(2, $this->countJournalsOf(ChequeSources::DEPOSIT_REJECTED, $chequeId, 'reversed'));
        $this->assertSame('-100.00', $this->balance($this->customerAccount()));
        $this->assertSame('100.00', $this->balance($this->accountId('cheques_under_collection')));
    }

    public function testUndo_PutsTheInvoicesBackOnTheChequeWhenARejectionIsUndone(): void
    {
        $invoice  = $this->invoiceOf('100.00');
        $chequeId = $this->received();

        $this->cheques->reject($chequeId, $this->today, $this->clerk);
        $this->cheques->undo($chequeId, $this->clerk);

        $this->assertSame(['cheque:' . $chequeId . '->' . $invoice . ':100.00'], $this->applications());
        $this->assertTies();
    }

    public function testUndo_RejectsTheEntryOfTheChequeItself(): void
    {
        $chequeId = $this->received();

        $this->assertSame(AccountingError::ChequeNothingToUndo, $this->refusal(fn () => $this->cheques->undo($chequeId, $this->clerk))->error);
        $this->assertSame('received', $this->statusOf($chequeId));
    }

    public function testUndo_LetsTheSameChangeBePostedAgainAsTheNextRevision(): void
    {
        $chequeId = $this->received();

        $this->cheques->deposit($chequeId, $this->today, $this->clerk);
        $this->cheques->undo($chequeId, $this->clerk);
        $this->cheques->deposit($chequeId, $this->today, $this->clerk);

        $this->assertSame(['1:reversed', '2:posted'], array_map(
            static fn (object $journal): string => $journal->revision . ':' . $journal->status,
            $this->accountingDb()->table('accounting_journals')->where(['source_type' => ChequeSources::DEPOSITED, 'source_id' => $chequeId])->orderBy('revision')->get()->getResult(),
        ));
        $this->assertSame('deposited', $this->statusOf($chequeId));
    }

    public function testUndo_TakesBackAJournalOfAClosedPeriodOnTodayInstead(): void
    {
        $day      = date('Y-m-d', strtotime('-20 days'));
        $chequeId = $this->received(['issue_date' => $day, 'due_date' => $day]);

        $this->cheques->collect($chequeId, $day, null, $this->clerk);
        $this->closeThrough(date('Y-m-d', strtotime('-10 days')));
        $this->cheques->undo($chequeId, $this->clerk);

        $reversal = $this->accountingDb()->table('accounting_journals')->where('source_type', 'reversal')->get()->getRow();

        $this->assertSame($this->today, $reversal->journal_date);
        $this->assertSame('received', $this->statusOf($chequeId));
        $this->assertSame('0.00', $this->balance($this->bankLedgerAccount()));
    }

    public function testDeposit_RejectsAClosedPeriodAndLeavesTheChequeReceived(): void
    {
        $chequeId = $this->received();

        $this->closeThrough($this->today);

        $failure = $this->refusal(fn () => $this->cheques->deposit($chequeId, $this->today, $this->clerk));

        $this->assertInstanceOf(PostingException::class, $failure);
        $this->assertSame(PostingError::PeriodClosed, $failure->error);
        $this->assertSame('received', $this->statusOf($chequeId));
        $this->assertSame(1, $this->countRows('cheque_events'));
    }

    public function testDeposit_RequiresTheBranchOfTheCheque(): void
    {
        $chequeId = $this->received();
        $other    = $this->createBranch();
        $worker   = $this->employeeWithGrants([AccountingGuard::CHEQUES_MANAGE], [$other]);

        $this->assertInstanceOf(BranchException::class, $this->refusal(fn () => $this->cheques->deposit($chequeId, $this->today, $worker)));
        $this->assertSame('received', $this->statusOf($chequeId));
    }

    public function testDeposit_RequiresThePermissionToChangeCheques(): void
    {
        $chequeId = $this->received();
        $nobody   = $this->employeeWithGrants([]);

        $this->assertSame(AccountingError::PermissionDenied, $this->refusal(fn () => $this->cheques->deposit($chequeId, $this->today, $nobody))->error);
        $this->assertSame('received', $this->statusOf($chequeId));
    }

    public function testUpdate_ChangesTheAmountByReversingTheReceiptAndPostingAgain(): void
    {
        $this->invoiceOf('100.00');

        $chequeId = $this->received();

        $this->cheques->update($chequeId, $this->input(['amount' => '60.00']), $this->clerk);

        $this->assertSame([$this->accountId('notes_receivable') . '=60.00/0.00 ' . $this->customerAccount() . '=0.00/60.00'], $this->standingLines(ChequeSources::RECEIVED, $chequeId));
        $this->assertSame(['1:reversed', '2:posted'], array_map(
            static fn (object $journal): string => $journal->revision . ':' . $journal->status,
            $this->accountingDb()->table('accounting_journals')->where(['source_type' => ChequeSources::RECEIVED, 'source_id' => $chequeId])->orderBy('revision')->get()->getResult(),
        ));
        $this->assertSame('40.00', $this->balance($this->customerAccount()));
        $this->assertSame('40.0000', $this->position()->open());
        $this->assertTies();
    }

    public function testUpdate_ChangesTheBankAndTheBranchOfAChequeThatIsStillReceived(): void
    {
        $branch = $this->createBranch();
        $bank   = (new TreasuryService())->create(['name' => 'Branch bank ' . uniqid(), 'kind' => 'bank', 'bank_name' => 'Gulf Bank', 'account_number' => strtoupper(uniqid('AC')), 'branch_id' => $branch], $this->clerk);

        $chequeId = $this->received();

        $this->cheques->update($chequeId, $this->input(['treasury_id' => $bank]), $this->clerk);

        $this->assertSame([$bank, $branch], [(int) $this->cheques->find($chequeId)->treasury_id, (int) $this->cheques->find($chequeId)->branch_id]);
        $this->assertSame($branch, (int) $this->accountingDb()->table('accounting_journals')->where(['source_type' => ChequeSources::RECEIVED, 'source_id' => $chequeId, 'status' => 'posted'])->get()->getRow()->branch_id);
    }

    public function testUpdate_RequiresTheBranchOfTheNewBankWhenTheBankChanges(): void
    {
        $branch   = $this->createBranch();
        $bank     = (new TreasuryService())->create(['name' => 'Branch bank ' . uniqid(), 'kind' => 'bank', 'bank_name' => 'Gulf Bank', 'account_number' => strtoupper(uniqid('AC')), 'branch_id' => $branch], $this->clerk);
        $chequeId = $this->received();
        $home     = (int) $this->cheques->find($chequeId)->branch_id;
        $worker   = $this->employeeWithGrants([AccountingGuard::CHEQUES_MANAGE], [$home]);

        $this->assertInstanceOf(BranchException::class, $this->refusal(fn () => $this->cheques->update($chequeId, $this->input(['treasury_id' => $bank]), $worker)));
        $this->assertSame([$this->bank, $home], [(int) $this->cheques->find($chequeId)->treasury_id, (int) $this->cheques->find($chequeId)->branch_id]);
    }

    public function testUpdate_ChangesNothingInTheBooksWhenOnlyTheTextChanges(): void
    {
        $chequeId = $this->received();

        $this->cheques->update($chequeId, $this->input(['description' => 'Another text', 'endorsed_to' => 'Supplier X']), $this->clerk);

        $this->assertSame(1, $this->countJournalsOf(ChequeSources::RECEIVED, $chequeId));
        $this->assertSame(['Another text', 'Supplier X'], [$this->cheques->find($chequeId)->description, $this->cheques->find($chequeId)->endorsed_to]);
    }

    public function testUpdate_LocksTheFinancialFieldsAfterTheFirstChangeButNotTheText(): void
    {
        $chequeId = $this->received();

        $this->cheques->deposit($chequeId, $this->today, $this->clerk);

        foreach (['amount' => '90.00', 'cheque_number' => '710009', 'due_date' => $this->futureDate(5), 'issue_date' => date('Y-m-d', strtotime('-1 day'))] as $field => $value) {
            $this->assertSame(AccountingError::ChequeLocked, $this->refusal(fn () => $this->cheques->update($chequeId, $this->input([$field => $value]), $this->clerk))->error, $field);
        }

        $this->cheques->update($chequeId, $this->input(['description' => 'After the deposit', 'endorsed_to' => 'Someone']), $this->clerk);

        $this->assertSame('After the deposit', $this->cheques->find($chequeId)->description);
        $this->assertSame(1, $this->countJournalsOf(ChequeSources::RECEIVED, $chequeId));
    }

    public function testUpdate_RejectsAChangeOfTheCustomerEvenInTheFirstStatus(): void
    {
        $chequeId = $this->received();

        $this->assertSame(AccountingError::ChequeLocked, $this->refusal(fn () => $this->cheques->update($chequeId, $this->input(['party_id' => $this->anotherCustomer()]), $this->clerk))->error);
        $this->assertSame($this->customerId, (int) $this->cheques->find($chequeId)->party_id);
    }

    public function testDelete_ReversesTheReceiptSoNoJournalIsLeftOrphaned(): void
    {
        $this->invoiceOf('100.00');

        $chequeId = $this->received();

        $this->cheques->delete($chequeId, $this->clerk);

        $this->assertSame('100.00', $this->balance($this->customerAccount()));
        $this->assertSame('0.00', $this->balance($this->accountId('notes_receivable')));
        $this->assertSame(1, $this->countJournalsOf(ChequeSources::RECEIVED, $chequeId, 'reversed'));
        $this->assertSame(0, $this->countJournalsOf(ChequeSources::RECEIVED, $chequeId, 'posted'));
        $this->assertSame('1', (string) $this->accountingDb()->table('cheques')->where('cheque_id', $chequeId)->get()->getRow()->deleted);
        $this->assertSame([], $this->applications());
        $this->assertSame('100.0000', $this->position()->open());
        $this->assertTies();
        $this->assertSame(AccountingError::ChequeNotFound, $this->refusal(fn () => $this->cheques->deposit($chequeId, $this->today, $this->clerk))->error);
    }

    public function testDeposit_AuditsEveryChangeOfTheChequeWithItsDateAndFees(): void
    {
        $chequeId = $this->received();

        $this->cheques->update($chequeId, ['description' => 'Corrected'], $this->clerk);
        $this->cheques->deposit($chequeId, $this->today, $this->clerk);
        $this->cheques->collect($chequeId, $this->today, '2.50', $this->clerk);
        $this->cheques->undo($chequeId, $this->clerk);
        $this->cheques->reject($chequeId, $this->today, $this->clerk);

        $entries = $this->auditOf($chequeId);

        $this->assertSame(['created', 'updated', 'cheque_deposited', 'cheque_collected', 'cheque_undone', 'cheque_rejected'], array_column($entries, 'action'));
        $this->assertSame([$this->clerk], array_values(array_unique(array_map(static fn (object $entry): int => (int) $entry->employee_id, $entries))));

        $collected = json_decode((string) $entries[3]->details, true);

        $this->assertSame(ChequeTransition::DepositCollect->value, $collected['transition']);
        $this->assertSame($this->today, $collected['date']);
        $this->assertSame('2.5000', $collected['fees']);
        $this->assertSame(ChequeTransition::DepositCollect->value, json_decode((string) $entries[4]->details, true)['transition']);
        $this->assertSame('deposited', json_decode((string) $entries[4]->details, true)['to']);
    }

    public function testDelete_AuditsTheDeletionAndTheStepsOfAPaidCheque(): void
    {
        $received = $this->received();

        $this->cheques->delete($received, $this->clerk);

        $this->assertSame(['created', 'deleted'], array_column($this->auditOf($received), 'action'));

        $paid = $this->paid();

        $this->cheques->deliver($paid, $this->today, $this->clerk);
        $this->cheques->collect($paid, $this->today, null, $this->clerk);

        $this->assertSame(['created', 'cheque_delivered', 'cheque_collected'], array_column($this->auditOf($paid), 'action'));
    }

    public function testDelete_RejectsAChequeThatHasMovedOn(): void
    {
        $chequeId = $this->received();

        $this->cheques->deposit($chequeId, $this->today, $this->clerk);

        $this->assertSame(AccountingError::ChequeDeleteLocked, $this->refusal(fn () => $this->cheques->delete($chequeId, $this->clerk))->error);
        $this->assertSame(0, $this->countJournalsOf(ChequeSources::RECEIVED, $chequeId, 'reversed'));
    }

    public function testDelete_AcceptsAChequeAfterItsLastChangeIsUndone(): void
    {
        $chequeId = $this->received();

        $this->cheques->deposit($chequeId, $this->today, $this->clerk);
        $this->cheques->undo($chequeId, $this->clerk);
        $this->cheques->delete($chequeId, $this->clerk);

        $this->assertSame('0.00', $this->balance($this->accountId('notes_receivable')));
        $this->assertSame('0.00', $this->balance($this->accountId('cheques_under_collection')));
    }

    public function testIssue_PostsNothing(): void
    {
        $journals = $this->countRows('accounting_journals');
        $chequeId = $this->paid();

        $this->assertSame($journals, $this->countRows('accounting_journals'));
        $this->assertSame('issued', $this->statusOf($chequeId));
        $this->assertSame($this->book, (int) $this->cheques->find($chequeId)->book_id);
        $this->assertSame($this->bank, (int) $this->cheques->find($chequeId)->treasury_id);
    }

    public function testIssue_RequiresABookOfAnActiveBankWithTheNumberInItsRange(): void
    {
        $this->assertSame(AccountingError::ChequeBookRequired, $this->refusal(fn () => $this->paid(['book_id' => 0]))->error);
        $this->assertSame(AccountingError::ChequeBookNotFound, $this->refusal(fn () => $this->paid(['book_id' => 99999]))->error);
        $this->assertSame(AccountingError::ChequeNumberOutOfBook, $this->refusal(fn () => $this->paid(['cheque_number' => '800051']))->error);
        $this->assertSame(AccountingError::ChequeNumberOutOfBook, $this->refusal(fn () => $this->paid(['cheque_number' => '799999']))->error);
        $this->assertSame(AccountingError::ChequeNumberOutOfBook, $this->refusal(fn () => $this->paid(['cheque_number' => 'ABC']))->error);

        (new ChequeBooks())->update($this->book, ['active' => false], $this->clerk);

        $this->assertSame(AccountingError::ChequeBookInactive, $this->refusal(fn () => $this->paid())->error);
        $this->assertSame(0, $this->countRows('cheques'));
    }

    public function testIssue_RejectsANumberAnotherChequeOfTheBookAlreadyHas(): void
    {
        $this->paid();

        $this->assertSame(AccountingError::ChequeNumberTaken, $this->refusal(fn () => $this->paid())->error);
        $this->assertSame(1, $this->countRows('cheques'));
    }

    public function testIssue_RejectsANumberThatIsTheSameSerialWrittenWithLeadingZeros(): void
    {
        $this->paid(['cheque_number' => '800001']);

        foreach (['0800001', '000800001'] as $number) {
            $this->assertSame(AccountingError::ChequeNumberTaken, $this->refusal(fn () => $this->paid(['cheque_number' => $number, 'party_id' => $this->supplierId]))->error, $number);
        }

        $this->assertSame(1, $this->countRows('cheques'));
    }

    public function testIssue_AcceptsTheSerialAfterTheOneAnotherChequeHas(): void
    {
        $this->paid(['cheque_number' => '800001']);

        $second = $this->paid(['cheque_number' => '0800002']);

        $this->assertSame('0800002', $this->cheques->find($second)->cheque_number, 'The number is kept as it was written');
    }

    public function testIssue_LocksTheBookBeforeItLooksForTheSerialAndWritesTheCheque(): void
    {
        $queries = $this->queriesOf(fn () => $this->paid());

        $lock  = $this->firstIndexOf($queries, static fn (string $sql): bool => str_contains($sql, 'FOR UPDATE') && str_contains($sql, 'ospos_cheque_books'));
        $check = $this->firstIndexOf($queries, static fn (string $sql): bool => str_contains($sql, 'CAST(cheque_number AS UNSIGNED)'));
        $write = $this->firstIndexOf($queries, static fn (string $sql): bool => stripos($sql, 'INSERT INTO') !== false && str_contains($sql, 'ospos_cheques'));

        $this->assertGreaterThanOrEqual(0, $lock, 'The book is locked');
        $this->assertGreaterThan($lock, $check, 'The serial is looked for under the lock');
        $this->assertGreaterThan($check, $write);
    }

    public function testUpdate_RejectsTheSameSerialWrittenWithLeadingZerosAndAcceptsTheOwnNumberRewritten(): void
    {
        $this->paid(['cheque_number' => '800002']);

        $chequeId = $this->paid();

        $this->assertSame(AccountingError::ChequeNumberTaken, $this->refusal(fn () => $this->cheques->update($chequeId, $this->paidInput(['cheque_number' => '0800002']), $this->clerk))->error);

        $this->cheques->update($chequeId, $this->paidInput(['cheque_number' => '0800001']), $this->clerk);

        $this->assertSame('0800001', $this->cheques->find($chequeId)->cheque_number);
    }

    public function testCollect_RejectsABankThatWasDeactivatedWhileTheChequeWasOnTheWay(): void
    {
        $other    = $this->bankAccount('Closing bank');
        $chequeId = $this->received(['treasury_id' => $other]);

        (new TreasuryService())->deactivate($other, $this->clerk);

        $this->assertSame(AccountingError::TreasuryInactive, $this->refusal(fn () => $this->cheques->collect($chequeId, $this->today, null, $this->clerk))->error);
        $this->assertSame('received', $this->statusOf($chequeId));
        $this->assertSame(0, $this->countJournalsOf(ChequeSources::COLLECTED, $chequeId));

        $this->cheques->deposit($chequeId, $this->today, $this->clerk);

        $this->assertSame(AccountingError::TreasuryInactive, $this->refusal(fn () => $this->cheques->collect($chequeId, $this->today, '0', $this->clerk))->error);
        $this->assertSame('deposited', $this->statusOf($chequeId));

        $this->cheques->reject($chequeId, $this->today, $this->clerk);

        $this->assertSame('rejected', $this->statusOf($chequeId), 'A rejection posts nothing to the bank');
    }

    public function testCollect_RejectsADeactivatedBankOfAPaidCheque(): void
    {
        $other = $this->bankAccount('Closing bank');
        $book  = (new ChequeBooks())->create(['treasury_id' => $other, 'name' => 'Closing book', 'first_serial' => '900001', 'last_serial' => '900010'], $this->clerk);

        $chequeId = $this->cheques->issue($this->paidInput(['book_id' => $book, 'cheque_number' => '900001']), $this->clerk, $this->today);

        (new TreasuryService())->deactivate($other, $this->clerk);

        $this->assertSame(AccountingError::TreasuryInactive, $this->refusal(fn () => $this->cheques->collect($chequeId, $this->today, null, $this->clerk))->error);
        $this->assertSame('delivered', $this->statusOf($chequeId));
        $this->assertSame(0, $this->countJournalsOf(ChequeSources::PAID_COLLECTED, $chequeId));
    }

    public function testIssue_AllowsTheNumberOfADeletedCheque(): void
    {
        $first = $this->paid();

        $this->cheques->delete($first, $this->clerk);

        $second = $this->paid();

        $this->assertGreaterThan($first, $second);
    }

    public function testIssue_RejectsASupplierThatDoesNotExistAndAPartyOfTheOtherKind(): void
    {
        $this->assertSame(AccountingError::ChequePartyMissing, $this->refusal(fn () => $this->paid(['party_id' => 999999]))->error);
        $this->assertSame(AccountingError::ChequePartyMissing, $this->refusal(fn () => $this->paid(['party_id' => $this->customerId]))->error);
    }

    public function testIssue_RequiresThePermissionAndTheBranchOfTheBank(): void
    {
        $nobody = $this->employeeWithGrants([]);
        $other  = $this->createBranch();
        $worker = $this->employeeWithGrants([AccountingGuard::CHEQUES_MANAGE], [$other]);

        $this->assertSame(AccountingError::PermissionDenied, $this->refusal(fn () => $this->cheques->issue($this->paidInput(), $nobody))->error);
        $this->assertInstanceOf(BranchException::class, $this->refusal(fn () => $this->cheques->issue($this->paidInput(), $worker)));
        $this->assertSame(0, $this->countRows('cheques'));
    }

    public function testDeliver_DebitsTheSupplierAndCreditsNotesPayable(): void
    {
        $purchase = $this->purchaseOf('500.00');
        $chequeId = $this->paid();

        $this->cheques->deliver($chequeId, $this->today, $this->clerk);

        $this->assertSame([$this->supplierAccount() . '=200.00/0.00 ' . $this->accountId('notes_payable') . '=0.00/200.00'], $this->standingLines(ChequeSources::DELIVERED, $chequeId));
        $this->assertSame('delivered', $this->statusOf($chequeId));
        $this->assertSame('-300.00', $this->balance($this->supplierAccount()));
        $this->assertSame('-200.00', $this->balance($this->accountId('notes_payable')));
        $this->assertSame(['cheque:' . $chequeId . '->' . $purchase . ':200.00'], $this->supplierApplications());
        $this->assertSupplierTies();
    }

    public function testIssue_DeliversInTheSameTransactionWhenGivenADeliveryDay(): void
    {
        $chequeId = $this->paid([], $this->today);

        $this->assertSame('delivered', $this->statusOf($chequeId));
        $this->assertSame(['issue', 'deliver'], array_column($this->cheques->events($chequeId), 'transition'));
        $this->assertSame(1, $this->countJournalsOf(ChequeSources::DELIVERED, $chequeId, 'posted'));
    }

    public function testIssue_WritesNothingWhenGivenADeliveryDayInAClosedPeriod(): void
    {
        $this->closeThrough($this->today);

        $failure = $this->refusal(fn () => $this->paid([], $this->today));

        $this->assertSame(PostingError::PeriodClosed, $failure->error);
        $this->assertSame(0, $this->countRows('cheques'));
    }

    public function testCollect_DebitsNotesPayableAndCreditsTheBankOfAPaidChequeWithoutLookingAtItsBalance(): void
    {
        $chequeId = $this->paid([], $this->today);

        $this->cheques->collect($chequeId, $this->today, null, $this->clerk);

        $this->assertSame([$this->accountId('notes_payable') . '=200.00/0.00 ' . $this->bankLedgerAccount() . '=0.00/200.00'], $this->standingLines(ChequeSources::PAID_COLLECTED, $chequeId));
        $this->assertSame('collected', $this->statusOf($chequeId));
        $this->assertSame('-200.00', $this->assertTreasuryTies());
        $this->assertSame('0.00', $this->balance($this->accountId('notes_payable')));
    }

    public function testCollect_RejectsADateBeforeTheDueDateOfAPaidCheque(): void
    {
        $chequeId = $this->paid(['due_date' => $this->futureDate(30)], $this->today);

        $this->assertSame(AccountingError::ChequeCollectBeforeDue, $this->refusal(fn () => $this->cheques->collect($chequeId, $this->today, null, $this->clerk))->error);
        $this->assertSame('delivered', $this->statusOf($chequeId));
    }

    public function testCollect_RejectsAnEmployeeWhoMayNotWithdrawFromTheBankOfAPaidCheque(): void
    {
        $chequeId = $this->paid([], $this->today);
        $clerk    = $this->employeeWithGrants([AccountingGuard::CHEQUES_MANAGE]);
        $other    = $this->employeeWithGrants([]);

        (new TreasuryScopes())->apply($this->bank, (new TreasuryScopes())->validated(['withdraw_scope' => TreasuryScopes::STAFF, 'withdraw_members' => [$other]]), null);

        $this->assertSame(AccountingError::TreasuryWithdrawDenied, $this->refusal(fn () => $this->cheques->collect($chequeId, $this->today, null, $clerk))->error);
        $this->assertSame('delivered', $this->statusOf($chequeId));
    }

    public function testReject_GivesTheSupplierBackWhatDeliveryTookForAPaidCheque(): void
    {
        $this->purchaseOf('500.00');

        $chequeId = $this->paid([], $this->today);

        $this->cheques->reject($chequeId, $this->today, $this->clerk);

        $this->assertSame([$this->accountId('notes_payable') . '=200.00/0.00 ' . $this->supplierAccount() . '=0.00/200.00'], $this->standingLines(ChequeSources::PAID_REJECTED, $chequeId));
        $this->assertSame('rejected', $this->statusOf($chequeId));
        $this->assertSame('-500.00', $this->balance($this->supplierAccount()));
        $this->assertSame([], $this->supplierApplications());
        $this->assertSupplierTies();
    }

    public function testUndo_ReversesTheJournalOfADeliveryAndTheCreditGoesBack(): void
    {
        $this->purchaseOf('500.00');

        $chequeId = $this->paid([], $this->today);

        $this->cheques->undo($chequeId, $this->clerk);

        $this->assertSame('issued', $this->statusOf($chequeId));
        $this->assertSame('-500.00', $this->balance($this->supplierAccount()));
        $this->assertSame(1, $this->countJournalsOf(ChequeSources::DELIVERED, $chequeId, 'reversed'));
        $this->assertSame([], $this->supplierApplications());
        $this->assertSupplierTies();
    }

    public function testCancel_IsOnlyForAnIssuedChequeAndPostsNothing(): void
    {
        $chequeId = $this->paid();
        $journals = $this->countRows('accounting_journals');

        $this->cheques->cancel($chequeId, $this->clerk);

        $this->assertSame('cancelled', $this->statusOf($chequeId));
        $this->assertSame($journals, $this->countRows('accounting_journals'));

        $this->cheques->undo($chequeId, $this->clerk);

        $this->assertSame('issued', $this->statusOf($chequeId));

        $this->cheques->deliver($chequeId, $this->today, $this->clerk);

        $this->assertSame(AccountingError::ChequeStatusInvalid, $this->refusal(fn () => $this->cheques->cancel($chequeId, $this->clerk))->error);
    }

    public function testDelete_AcceptsAPaidChequeOnlyWhileIssued(): void
    {
        $chequeId = $this->paid([], $this->today);

        $this->assertSame(AccountingError::ChequeDeleteLocked, $this->refusal(fn () => $this->cheques->delete($chequeId, $this->clerk))->error);

        $this->cheques->undo($chequeId, $this->clerk);
        $this->cheques->delete($chequeId, $this->clerk);

        $this->assertSame('0.00', $this->balance($this->accountId('notes_payable')));
        $this->assertSame('0.00', $this->balance($this->supplierAccount()));
    }

    public function testUpdate_ChangesTheBookAndTheNumberOfAnIssuedChequeWithoutPosting(): void
    {
        $second   = (new ChequeBooks())->create(['treasury_id' => $this->bankAccount('Second bank'), 'name' => 'Book 2', 'first_serial' => '900001', 'last_serial' => '900010'], $this->clerk);
        $chequeId = $this->paid();
        $journals = $this->countRows('accounting_journals');

        $this->cheques->update($chequeId, $this->paidInput(['book_id' => $second, 'cheque_number' => '900005', 'amount' => '250.00']), $this->clerk);

        $row = $this->cheques->find($chequeId);

        $this->assertSame([$second, '900005', '250.0000'], [(int) $row->book_id, $row->cheque_number, $row->amount]);
        $this->assertSame($journals, $this->countRows('accounting_journals'));
        $this->assertSame(AccountingError::ChequeNumberOutOfBook, $this->refusal(fn () => $this->cheques->update($chequeId, $this->paidInput(['book_id' => $second, 'cheque_number' => '800001']), $this->clerk))->error);
    }

    public function testUpdate_LocksTheAmountOfADeliveredPaidCheque(): void
    {
        $chequeId = $this->paid([], $this->today);

        $this->assertSame(AccountingError::ChequeLocked, $this->refusal(fn () => $this->cheques->update($chequeId, $this->paidInput(['amount' => '210.00']), $this->clerk))->error);
    }

    public function testUndo_LeavesNoOrphanJournalAndBalancesEveryOne(): void
    {
        $this->invoiceOf('300.00');
        $this->purchaseOf('300.00');

        $received = $this->received(['amount' => '120.00']);
        $paid     = $this->paid(['amount' => '80.00'], $this->today);

        $this->cheques->deposit($received, $this->today, $this->clerk);
        $this->cheques->collect($received, $this->today, '1.25', $this->clerk);
        $this->cheques->collect($paid, $this->today, null, $this->clerk);
        $this->cheques->undo($received, $this->clerk);
        $this->cheques->reject($received, $this->today, $this->clerk);

        foreach ($this->accountingDb()->table('accounting_journals')->get()->getResult() as $journal) {
            $this->assertSame(bcadd($journal->total_debit, '0', 4), bcadd($journal->total_credit, '0', 4), 'journal ' . $journal->journal_id);
        }

        $this->assertTies();
        $this->assertSupplierTies();
        $this->assertSame('0.00', $this->balance($this->accountId('notes_receivable')));
        $this->assertSame('0.00', $this->balance($this->accountId('cheques_under_collection')));
        $this->assertSame('0.00', $this->balance($this->accountId('notes_payable')));
        $this->assertSame('-80.00', $this->assertTreasuryTies());
    }

    public function testFind_ReadsTheNamesTheScreensShow(): void
    {
        $chequeId = $this->received();
        $row      = $this->cheques->find($chequeId);

        $this->assertSame(['Omar Khaled', 'HQ'], [$row->party_name, $row->branch_code]);
        $this->assertNull($this->cheques->find(99999));
        $this->assertSame(['deposit', 'collect', 'reject', 'edit', 'delete'], $this->cheques->actionsOf($row));
    }

    /**
     * @return array<string, int>
     */
    private function roles(): array
    {
        return [
            'notes_receivable' => $this->accountId('notes_receivable'),
            'under_collection' => $this->accountId('cheques_under_collection'),
            'deposit_fees'     => $this->accountId('cheque_deposit_fees'),
            'notes_payable'    => $this->accountId('notes_payable'),
            'customer'         => $this->customerAccount(),
            'supplier'         => $this->supplierAccount(),
            'bank'             => $this->bankLedgerAccount(),
        ];
    }

    /**
     * @return list<array{role: string, debit: string, credit: string}> The lines of the journals of a source of the cheque that stand, journal by journal in the order they were posted, flattened by journal.
     */
    private function journalLines(string $source, int $chequeId, int $index = 0): array
    {
        $journals = $this->accountingDb()->table('accounting_journals')->where(['source_type' => $source, 'source_id' => $chequeId, 'status' => 'posted'])->orderBy('revision')->get()->getResult();

        $this->assertArrayHasKey($index, $journals, "No journal {$index} stands for {$source}");

        return $this->roleLines((int) $journals[$index]->journal_id, $this->roles());
    }

    public function testReceive_WritesWhatTheReferenceSystemWroteForAReceivedChequeAndUndoReversesWhereItDeletes(): void
    {
        $case = $this->referenceCase('T-54', 'received_cheque', 'a-received-cheque-from-receipt-to-collection-or-rejection', 'differs');

        $direct   = $this->received(['cheque_number' => '710001']);
        $bounced  = $this->received(['cheque_number' => '710002']);
        $deposits = $this->received(['cheque_number' => '710003']);
        $failed   = $this->received(['cheque_number' => '710004']);

        $this->cheques->collect($direct, $this->today, null, $this->clerk);
        $this->cheques->reject($bounced, $this->today, $this->clerk);
        $this->cheques->deposit($deposits, $this->today, $this->clerk);
        $this->cheques->collect($deposits, $this->today, '2.50', $this->clerk);
        $this->cheques->deposit($failed, $this->today, $this->clerk);
        $this->cheques->reject($failed, $this->today, $this->clerk);

        $actual = [
            'received'                     => $this->journalLines(ChequeSources::RECEIVED, $direct),
            'collected_directly'           => $this->journalLines(ChequeSources::COLLECTED, $direct),
            'rejected_when_received'       => $this->journalLines(ChequeSources::REJECTED, $bounced),
            'deposited'                    => $this->journalLines(ChequeSources::DEPOSITED, $deposits),
            'collected_after_deposit'      => $this->journalLines(ChequeSources::DEPOSIT_COLLECTED, $deposits),
            'rejected_after_deposit_first' => $this->journalLines(ChequeSources::DEPOSIT_REJECTED, $failed, 0),
            'rejected_after_deposit_second' => $this->journalLines(ChequeSources::DEPOSIT_REJECTED, $failed, 1),
        ];

        $this->assertSameAsFixture($case['expected']['journals'], $actual, 'the journals of a received cheque');

        $this->cheques->undo($direct, $this->clerk);

        $reversed = $this->accountingDb()->table('accounting_journals')->where(['source_type' => ChequeSources::COLLECTED, 'source_id' => $direct])->get()->getRow();

        $this->assertSame('reversed', $reversed->status);
        $this->assertSame($case['expected']['deleted_only_while'], 'received');
        $this->assertSame(AccountingError::ChequeDeleteLocked, $this->refusal(fn () => $this->cheques->delete($deposits, $this->clerk))->error);
        $this->assertSame('reverses the journal of the step', $case['expected']['undo_of_a_step']);
    }

    public function testDeliver_WritesWhatTheReferenceSystemWroteForAPaidCheque(): void
    {
        $case = $this->referenceCase('T-55', 'paid_cheque', 'a-paid-cheque-from-issue-to-collection-or-rejection', 'differs');

        $journals = $this->countRows('accounting_journals');
        $collects = $this->paid(['cheque_number' => '800001']);

        $this->assertSame($journals, $this->countRows('accounting_journals'), 'issuing posts nothing');
        $this->assertSame($case['expected']['journal_at_issue'], 'none');

        $this->cheques->deliver($collects, $this->today, $this->clerk);
        $this->cheques->collect($collects, $this->today, null, $this->clerk);

        $rejected = $this->paid(['cheque_number' => '800002'], $this->today);

        $this->cheques->reject($rejected, $this->today, $this->clerk);

        $actual = [
            'delivered' => $this->journalLines(ChequeSources::DELIVERED, $collects),
            'collected' => $this->journalLines(ChequeSources::PAID_COLLECTED, $collects),
            'rejected'  => $this->journalLines(ChequeSources::PAID_REJECTED, $rejected),
        ];

        $this->assertSameAsFixture($case['expected']['journals'], $actual, 'the journals of a paid cheque');
        $this->assertSame($case['expected']['collection_checks_the_balance_of_the_bank'], bccomp($this->balance($this->bankLedgerAccount()), '0', 2) >= 0, 'the bank went below zero without a refusal');
    }

    public function testCollect_KeepsTheDueDateOutOfThePostingsAndChecksTheCollectionDate(): void
    {
        $case = $this->referenceCase('T-56', 'post_dated_cheque', 'the-due-date-of-a-cheque-posts-nothing-and-the-collection-date-is-checked', 'exact');

        $due      = $this->futureDate(60);
        $chequeId = $this->received(['amount' => '60.00', 'due_date' => $due]);
        $received = $this->journalLines(ChequeSources::RECEIVED, $chequeId);

        $this->assertSame($this->today, $this->accountingDb()->table('accounting_journals')->where(['source_type' => ChequeSources::RECEIVED, 'source_id' => $chequeId])->get()->getRow()->journal_date);

        $this->cheques->update($chequeId, $this->input(['amount' => '60.00', 'due_date' => $due, 'endorsed_to' => 'Another party']), $this->clerk);

        $this->assertSame(1, $this->countJournalsOf(ChequeSources::RECEIVED, $chequeId), 'an endorsement is a label that adds no journal');

        $this->cheques->deposit($chequeId, $this->today, $this->clerk);

        $accepted  = $this->statusOf($chequeId) === 'deposited';
        $deposited = $this->journalLines(ChequeSources::DEPOSITED, $chequeId);
        $refused   = $this->refusal(fn () => $this->cheques->collect($chequeId, $this->today, '0', $this->clerk))->error === AccountingError::ChequeCollectBeforeDue;

        $this->cheques->collect($chequeId, $due, '0', $this->clerk);

        $collected = $this->journalLines(ChequeSources::DEPOSIT_COLLECTED, $chequeId);

        $this->assertSame($due, $this->accountingDb()->table('accounting_journals')->where(['source_type' => ChequeSources::DEPOSIT_COLLECTED, 'source_id' => $chequeId])->get()->getRow()->journal_date);

        $this->invoiceOf('80.00');

        $paid = $this->paid(['amount' => '80.00', 'cheque_number' => '800009', 'due_date' => $due], $this->today);

        $actual = [
            'journals' => [
                'received_dated_the_issue_date'                      => $received,
                'deposited_before_the_due_date'                      => $deposited,
                'collected_on_the_due_date_dated_27_12_2026'         => $collected,
                'paid_cheque_delivered_dated_the_issue_date'         => $this->journalLines(ChequeSources::DELIVERED, $paid),
            ],
            'collection_before_the_due_date' => $refused ? 'refused' : 'accepted',
            'deposit_before_the_due_date'    => $accepted ? 'accepted' : 'refused',
            'endorsement'                    => 'a label only',
        ];

        $this->assertSameAsFixture($case['expected'], $actual, 'the due date of a cheque');
        $this->assertSame($this->today, $this->accountingDb()->table('accounting_journals')->where(['source_type' => ChequeSources::DELIVERED, 'source_id' => $paid])->get()->getRow()->journal_date);
    }

    public function testDeposit_RejectsAChequeOfTheOtherDirectionAndAMissingOne(): void
    {
        $received = $this->received();
        $paid     = $this->paid();

        $this->assertSame(AccountingError::ChequeStatusInvalid, $this->refusal(fn () => $this->cheques->deliver($received, $this->today, $this->clerk))->error);
        $this->assertSame(AccountingError::ChequeStatusInvalid, $this->refusal(fn () => $this->cheques->deposit($paid, $this->today, $this->clerk))->error);
        $this->assertSame(AccountingError::ChequeStatusInvalid, $this->refusal(fn () => $this->cheques->cancel($received, $this->clerk))->error);
        $this->assertSame(AccountingError::ChequeNotFound, $this->refusal(fn () => $this->cheques->deposit(99999, $this->today, $this->clerk))->error);
        $this->assertSame(['received', 'issued'], [$this->statusOf($received), $this->statusOf($paid)]);
    }

    public function testCollect_RejectsADateBeforeTheLastChangeOfTheCheque(): void
    {
        $issued   = date('Y-m-d', strtotime('-10 days'));
        $chequeId = $this->received(['issue_date' => $issued, 'due_date' => $issued]);

        $this->cheques->deposit($chequeId, date('Y-m-d', strtotime('-4 days')), $this->clerk);

        $failure = $this->refusal(fn () => $this->cheques->collect($chequeId, date('Y-m-d', strtotime('-6 days')), '0', $this->clerk));

        $this->assertSame(AccountingError::ChequeDateInvalid, $failure->error);
        $this->assertSame('deposited', $this->statusOf($chequeId));

        $this->cheques->collect($chequeId, date('Y-m-d', strtotime('-4 days')), '0', $this->clerk);

        $this->assertSame('collected', $this->statusOf($chequeId));
    }

    public function testUpdate_RejectsAnIssueDateAfterTodayOnAChequeThatIsStillReceived(): void
    {
        $chequeId = $this->received();

        $failure = $this->refusal(fn () => $this->cheques->update($chequeId, $this->input(['issue_date' => $this->futureDate(2), 'due_date' => $this->futureDate(2)]), $this->clerk));

        $this->assertSame(AccountingError::ChequeDateInvalid, $failure->error);
        $this->assertSame($this->today, substr((string) $this->cheques->find($chequeId)->issue_date, 0, 10));
    }

    public function testUpdate_LocksTheBankOfAChequeThatHasMovedOnAndTheBookOfADeliveredOne(): void
    {
        $other    = $this->bankAccount('Another bank');
        $received = $this->received();
        $paid     = $this->paid([], $this->today);
        $second   = (new ChequeBooks())->create(['treasury_id' => $other, 'name' => 'Book 2', 'first_serial' => '900001', 'last_serial' => '900010'], $this->clerk);

        $this->cheques->deposit($received, $this->today, $this->clerk);

        $this->assertSame(AccountingError::ChequeLocked, $this->refusal(fn () => $this->cheques->update($received, $this->input(['treasury_id' => $other]), $this->clerk))->error);
        $this->assertSame(AccountingError::ChequeLocked, $this->refusal(fn () => $this->cheques->update($paid, $this->paidInput(['book_id' => $second, 'cheque_number' => '900001']), $this->clerk))->error);
        $this->assertSame([$this->bank, $this->book], [(int) $this->cheques->find($received)->treasury_id, (int) $this->cheques->find($paid)->book_id]);
    }

    public function testUpdate_RejectsTheNumberAnotherChequeOfTheBookHasAndAnInactiveBook(): void
    {
        $this->paid(['cheque_number' => '800002']);

        $chequeId = $this->paid();
        $other    = (new ChequeBooks())->create(['treasury_id' => $this->bank, 'name' => 'Book 3', 'first_serial' => '700001', 'last_serial' => '700010'], $this->clerk);

        $this->assertSame(AccountingError::ChequeNumberTaken, $this->refusal(fn () => $this->cheques->update($chequeId, $this->paidInput(['cheque_number' => '800002']), $this->clerk))->error);

        (new ChequeBooks())->update($other, ['active' => false], $this->clerk);

        $this->assertSame(AccountingError::ChequeBookInactive, $this->refusal(fn () => $this->cheques->update($chequeId, $this->paidInput(['book_id' => $other, 'cheque_number' => '700001']), $this->clerk))->error);
        $this->assertSame('800001', $this->cheques->find($chequeId)->cheque_number);
    }

    public function testUpdate_RequiresThePermissionTheBranchAndAChequeThatExists(): void
    {
        $chequeId = $this->received();
        $nobody   = $this->employeeWithGrants([]);
        $other    = $this->createBranch();
        $worker   = $this->employeeWithGrants([AccountingGuard::CHEQUES_MANAGE], [$other]);

        $this->assertSame(AccountingError::PermissionDenied, $this->refusal(fn () => $this->cheques->update($chequeId, $this->input(['description' => 'x']), $nobody))->error);
        $this->assertInstanceOf(BranchException::class, $this->refusal(fn () => $this->cheques->update($chequeId, $this->input(['description' => 'x']), $worker)));
        $this->assertSame(AccountingError::ChequeNotFound, $this->refusal(fn () => $this->cheques->update(99999, $this->input(), $this->clerk))->error);
        $this->assertSame('Invoice payment', $this->cheques->find($chequeId)->description);
    }

    public function testUndo_RequiresThePermissionTheBranchAndAChequeThatExists(): void
    {
        $chequeId = $this->received();

        $this->cheques->deposit($chequeId, $this->today, $this->clerk);

        $nobody = $this->employeeWithGrants([]);
        $other  = $this->createBranch();
        $worker = $this->employeeWithGrants([AccountingGuard::CHEQUES_MANAGE], [$other]);

        $this->assertSame(AccountingError::PermissionDenied, $this->refusal(fn () => $this->cheques->undo($chequeId, $nobody))->error);
        $this->assertInstanceOf(BranchException::class, $this->refusal(fn () => $this->cheques->undo($chequeId, $worker)));
        $this->assertSame(AccountingError::ChequeNotFound, $this->refusal(fn () => $this->cheques->undo(99999, $this->clerk))->error);
        $this->assertSame('deposited', $this->statusOf($chequeId));
    }

    public function testDelete_RequiresThePermissionTheBranchAndAChequeThatExists(): void
    {
        $chequeId = $this->received();

        $nobody = $this->employeeWithGrants([]);
        $other  = $this->createBranch();
        $worker = $this->employeeWithGrants([AccountingGuard::CHEQUES_MANAGE], [$other]);

        $this->assertSame(AccountingError::PermissionDenied, $this->refusal(fn () => $this->cheques->delete($chequeId, $nobody))->error);
        $this->assertInstanceOf(BranchException::class, $this->refusal(fn () => $this->cheques->delete($chequeId, $worker)));
        $this->assertSame(AccountingError::ChequeNotFound, $this->refusal(fn () => $this->cheques->delete(99999, $this->clerk))->error);
        $this->assertSame('received', $this->statusOf($chequeId));
        $this->assertSame(0, (int) $this->cheques->find($chequeId)->deleted);
    }

    public function testDeliver_RejectsAnEmployeeWhoMayNotWithdrawFromNeitherNorDepositIntoTheBankOfAPaidCheque(): void
    {
        $chequeId = $this->paid();
        $clerk    = $this->employeeWithGrants([AccountingGuard::CHEQUES_MANAGE]);

        $this->cheques->deliver($chequeId, $this->today, $clerk);

        $this->assertSame('delivered', $this->statusOf($chequeId), 'Delivery moves no money out of the bank');
    }

    public function testDeposit_TakesTheLocksOfThePartyAndOfTheChequeAndSettlesItsCredit(): void
    {
        $this->invoiceOf('100.00');

        $chequeId = $this->received();
        $queries  = $this->queriesOf(fn () => $this->cheques->deposit($chequeId, $this->today, $this->clerk));

        $lockName = (new CustomerLock())->nameOf($this->customerId);

        $this->assertNotSame([], array_filter($queries, static fn (string $sql): bool => stripos($sql, 'GET_LOCK') !== false && str_contains($sql, $lockName)), 'The customer is locked');
        $this->assertNotSame([], array_filter($queries, static fn (string $sql): bool => stripos($sql, 'RELEASE_LOCK') !== false && str_contains($sql, $lockName)), 'And let go');
        $this->assertNotSame([], array_filter($queries, static fn (string $sql): bool => str_contains($sql, 'FOR UPDATE') && str_contains($sql, 'ospos_cheques')), 'The row of the cheque is locked');
        $this->assertNotSame([], array_filter($queries, static fn (string $sql): bool => str_contains($sql, 'customer_credit_applications')), 'The credit is settled');
    }

    public function testDeliver_TakesTheLockOfTheSupplierOfAPaidCheque(): void
    {
        $chequeId = $this->paid();
        $queries  = $this->queriesOf(fn () => $this->cheques->deliver($chequeId, $this->today, $this->clerk));
        $lockName = (new SupplierLock())->nameOf($this->supplierId);

        $this->assertNotSame([], array_filter($queries, static fn (string $sql): bool => stripos($sql, 'GET_LOCK') !== false && str_contains($sql, $lockName)));
        $this->assertNotSame([], array_filter($queries, static fn (string $sql): bool => str_contains($sql, 'supplier_credit_applications')));
    }

    public function testDeposit_RecordsARefusalOfTheBooksAfterTheTransactionRolledBack(): void
    {
        $chequeId = $this->received();

        $this->closeThrough($this->today);

        $this->assertSame(PostingError::PeriodClosed, $this->refusal(fn () => $this->cheques->deposit($chequeId, $this->today, $this->clerk))->error);
        $this->assertSame(1, $this->accountingDb()->table('accounting_posting_failures')->where(['source_type' => ChequeSources::DEPOSITED, 'source_id' => $chequeId])->countAllResults());
    }

    public function testReceive_WritesTheNumberOfTheChequeOnTheJournalDescription(): void
    {
        $chequeId = $this->received(['cheque_number' => 'AB-77']);

        $journal = $this->accountingDb()->table('accounting_journals')->where(['source_type' => ChequeSources::RECEIVED, 'source_id' => $chequeId])->get()->getRow();

        $this->assertSame('Cheque AB-77 received', $journal->description);
        $this->assertSame('system', $journal->origin);
    }

    public function testActionsOf_SaysWhatEachStatusAllows(): void
    {
        $received = $this->received();
        $paid     = $this->paid();

        $this->assertSame(['deposit', 'collect', 'reject', 'edit', 'delete'], $this->cheques->actionsOf($this->cheques->find($received)));
        $this->assertSame(['deliver', 'cancel', 'edit', 'delete'], $this->cheques->actionsOf($this->cheques->find($paid)));

        $this->cheques->deposit($received, $this->today, $this->clerk);
        $this->cheques->deliver($paid, $this->today, $this->clerk);

        $this->assertSame(['collect', 'reject', 'undo'], $this->cheques->actionsOf($this->cheques->find($received)));
        $this->assertSame(['collect', 'reject', 'undo'], $this->cheques->actionsOf($this->cheques->find($paid)));

        $this->cheques->collect($received, $this->today, '0', $this->clerk);

        $this->assertSame(['undo'], $this->cheques->actionsOf($this->cheques->find($received)));
    }

    public function testBranchOf_SaysTheBranchOfAChequeOrNull(): void
    {
        $chequeId = $this->received();

        $this->assertSame(1, $this->cheques->branchOf($chequeId));
        $this->assertNull($this->cheques->branchOf(99999));
    }

    public function testUpdate_RefusesToLowerTheAmountOfAChequeWhoseCreditWasPaidBackAndAcceptsRaisingIt(): void
    {
        $cheque = $this->received();

        $this->refundCustomerCredit('100.00');

        $journals = $this->countRows('accounting_journals');
        $refusal  = $this->refusal(fn () => $this->cheques->update($cheque, $this->input(['amount' => '60.00']), $this->clerk));

        $this->assertSame(AccountingError::CreditRefunded, $refusal->error);
        $this->assertSame('100.00', bcadd($this->cheques->find($cheque)->amount, '0', 2));
        $this->assertSame($journals, $this->countRows('accounting_journals'));
        $this->assertSame('0.00', $this->freeCustomerCredit());

        $this->cheques->update($cheque, $this->input(['amount' => '100.00']), $this->clerk);
        $this->cheques->update($cheque, $this->input(['amount' => '150.00']), $this->clerk);

        $this->assertSame('50.00', $this->freeCustomerCredit(), 'What the cheque was raised by is credit that is free');
        $this->assertRefundsTie();
        $this->assertTies();
    }

    public function testReject_RefusesAChequeWhoseCreditWasPaidBackEvenAfterItWasDepositedAndAcceptsItOnceTheRefundIsCancelled(): void
    {
        $cheque   = $this->received();
        $refundId = $this->refundCustomerCredit('100.00');

        $this->assertSame(AccountingError::CreditRefunded, $this->refusal(fn () => $this->cheques->reject($cheque, $this->today, $this->clerk))->error);
        $this->assertSame('received', $this->statusOf($cheque));

        $this->cheques->deposit($cheque, $this->today, $this->clerk);

        $this->assertSame(AccountingError::CreditRefunded, $this->refusal(fn () => $this->cheques->reject($cheque, $this->today, $this->clerk))->error);
        $this->assertSame('deposited', $this->statusOf($cheque));
        $this->assertSame('0.00', $this->freeCustomerCredit());
        $this->assertRefundsTie();

        $this->cancelCustomerRefund($refundId);
        $this->cheques->reject($cheque, $this->today, $this->clerk);

        $this->assertSame('rejected', $this->statusOf($cheque));
        $this->assertSame('0.00', $this->freeCustomerCredit());
        $this->assertRefundsTie();
        $this->assertTies();
    }

    public function testDelete_RefusesAChequeWhoseCreditWasPaidBackAndKeepsItWithItsJournal(): void
    {
        $cheque = $this->received();

        $this->refundCustomerCredit('100.00');

        $before = $this->databaseFingerprint();

        $this->assertSame(AccountingError::CreditRefunded, $this->refusal(fn () => $this->cheques->delete($cheque, $this->clerk))->error);
        $this->assertSame('received', $this->statusOf($cheque));
        $this->assertSame(1, $this->countJournalsOf(ChequeTransition::Receive->source(), $cheque, 'posted'));
        $this->assertRefusedChangeLeftNoTrace($before, CreditSource::CHEQUE);
        $this->assertRefundsTie();
        $this->assertTies();
    }

    public function testCollect_LeavesTheCreditOfAChequeThatWasPaidBackAsItWasAndItsUndoDoesToo(): void
    {
        $cheque = $this->received();

        $this->refundCustomerCredit('100.00');
        $this->cheques->collect($cheque, $this->today, null, $this->clerk);

        $this->assertSame('collected', $this->statusOf($cheque));
        $this->assertSame('0.00', $this->freeCustomerCredit());
        $this->assertRefundsTie();
        $this->assertTies();

        $this->cheques->undo($cheque, $this->clerk);

        $this->assertSame('received', $this->statusOf($cheque));
        $this->assertSame('0.00', $this->freeCustomerCredit());
        $this->assertRefundsTie();
        $this->assertTies();
    }

    public function testReject_RefusesAChequeDeliveredToASupplierWhoseCreditWasPaidBackAndSoDoesUndoingItsDelivery(): void
    {
        $cheque    = $this->paid([], $this->today);
        $treasurer = $this->supplierTreasurer();
        $refundId  = $this->supplierRefund('200.00', $treasurer);

        $this->assertSame(AccountingError::CreditRefunded, $this->refusal(fn () => $this->cheques->reject($cheque, $this->today, $this->clerk))->error);
        $this->assertSame(AccountingError::CreditRefunded, $this->refusal(fn () => $this->cheques->undo($cheque, $this->clerk))->error);
        $this->assertSame('delivered', $this->statusOf($cheque));
        $this->assertSame('0.00', $this->freeSupplierCredit());
        $this->assertRefundsTie(CreditRefunds::SUPPLIER);
        $this->assertSupplierTies();

        $this->refundsFromSuppliers()->cancel($refundId, 'paid twice', $treasurer);
        $this->cheques->undo($cheque, $this->clerk);

        $this->assertSame('issued', $this->statusOf($cheque));
        $this->assertSame('0.00', $this->freeSupplierCredit());
        $this->assertRefundsTie(CreditRefunds::SUPPLIER);
        $this->assertSupplierTies();
    }
}
