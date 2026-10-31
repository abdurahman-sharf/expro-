<?php

namespace Tests\Libraries\Accounting;

use App\Libraries\Accounting\AccountingError;
use App\Libraries\Accounting\AccountingException;
use App\Libraries\Accounting\AccountingGuard;
use App\Libraries\Accounting\CreditRefunds;
use App\Libraries\Accounting\CreditSource;
use App\Libraries\Accounting\CustomerAdvances;
use App\Libraries\Accounting\PostingException;
use App\Libraries\Accounting\TreasuryService;
use App\Libraries\Organization\BranchException;
use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\DatabaseTestTrait;
use Config\Database;
use Tests\Support\AccountingFixtureTrait;
use Tests\Support\EmployeeFixtureTrait;
use Tests\Support\ItemFixtureTrait;
use Tests\Support\SaleAccountingFixtureTrait;

/**
 * Paying a customer back the credit they hold: a journal that debits their account and credits the
 * treasury, taken from named credits so that what is left to spend is what the account still
 * holds, and a credit that was paid back cannot shrink until the refund is cancelled.
 */
class CreditRefundsTest extends CIUnitTestCase
{
    use DatabaseTestTrait;
    use AccountingFixtureTrait;
    use EmployeeFixtureTrait;
    use ItemFixtureTrait;
    use SaleAccountingFixtureTrait;

    protected $migrate     = true;
    protected $migrateOnce = true;
    protected $refresh     = false;
    protected $namespace   = null;

    private CreditRefunds $refunds;
    private CustomerAdvances $advances;
    private int $clerk;

    public static function setUpBeforeClass(): void
    {
        $seeder = Database::seeder('tests');
        $seeder->call('TestDatabaseBootstrapSeeder');
    }

    protected function setUp(): void
    {
        parent::setUp();

        $this->startSelling();

        $this->refunds  = new CreditRefunds(CreditRefunds::CUSTOMER);
        $this->advances = new CustomerAdvances();
        $this->clerk    = $this->employeeWithGrants([AccountingGuard::RECEIVABLES_MANAGE, AccountingGuard::RECEIVABLES_REFUND, AccountingGuard::TREASURIES_MANAGE]);
    }

    protected function tearDown(): void
    {
        $this->stopSelling();

        parent::tearDown();
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
     * @return list<string> The lines of the journal that stands for a source, as "account=debit/credit".
     */
    private function linesOf(string $source, int $sourceId): array
    {
        $journal = $this->accountingDb()->table('accounting_journals')->where(['source_type' => $source, 'source_id' => $sourceId, 'status' => 'posted'])->get()->getRow();

        $this->assertNotNull($journal, 'No journal stands for ' . $source . ' ' . $sourceId);

        return array_map(
            static fn (object $line): string => $line->account_id . '=' . bcadd($line->debit, '0', 2) . '/' . bcadd($line->credit, '0', 2),
            $this->accountingDb()->table('accounting_journal_lines')->where('journal_id', $journal->journal_id)->orderBy('line_no')->get()->getResult(),
        );
    }

    private function refund(string $amount = '50.00', ?string $date = null, ?int $treasuryId = null, ?int $employeeId = null, ?string $key = null, ?int $customerId = null): int
    {
        return $this->refunds->refund($customerId ?? $this->customerId, $amount, $date ?? date('Y-m-d'), 'Cash', $treasuryId, '', '', $employeeId ?? $this->clerk, $key);
    }

    private function advance(string $amount = '200.00', ?string $date = null, ?int $treasuryId = null): int
    {
        return $this->advances->receive($this->customerId, $amount, $date ?? date('Y-m-d'), 'Cash', $treasuryId, '', '', $this->clerk);
    }

    private function free(): string
    {
        return bcadd($this->position()->credit(), '0', 2);
    }

    public function testRefund_DebitsTheCustomerAndCreditsTheTreasuryTheMoneyLeavesBy(): void
    {
        $invoice = $this->invoiceOf('100.00');

        $this->creditNoteOf($invoice, '250.00');

        $refundId = $this->refund('100.00');

        $this->assertSame([$this->customerAccount() . '=100.00/0.00', $this->accountId('cash') . '=0.00/100.00'], $this->linesOf(CreditRefunds::CUSTOMER_SOURCE, $refundId));
        $this->assertSame('50.00', $this->free());
        $this->assertSame('-50.00', $this->balance($this->customerAccount()));
        $this->assertSame('-100.00', $this->balance($this->accountId('cash')));
        $this->assertTies();
    }

    public function testRefund_TiesTheTreasuryItWentThroughToItsPosition(): void
    {
        $treasuries = new TreasuryService();
        $drawer     = $treasuries->create(['name' => 'Cash drawer', 'kind' => 'cash'], $this->clerk);

        $this->advance('200.00', null, $drawer);
        $refundId = $this->refund('120.00', null, $drawer);

        $this->assertSame([$this->customerAccount() . '=120.00/0.00', $treasuries->accountOf($drawer) . '=0.00/120.00'], $this->linesOf(CreditRefunds::CUSTOMER_SOURCE, $refundId));
        $this->assertSame('80.0000', $treasuries->position($drawer)->balance);
        $this->assertSame($this->balance($treasuries->accountOf($drawer)), bcadd($treasuries->position($drawer)->balance, '0', 2));
        $this->assertSame($drawer, (int) $this->accountingDb()->table('customer_credit_refunds')->where('refund_id', $refundId)->get()->getRow()->treasury_id);
        $this->assertSame('80.00', $this->free());
        $this->assertTies();
    }

    public function testRefund_RefusesATreasuryTheEmployeeMayNotWithdrawFrom(): void
    {
        $treasuries = new TreasuryService();
        $other      = $this->employeeWithGrants([]);
        $drawer     = $treasuries->create(['name' => 'Locked drawer', 'kind' => 'cash', 'withdraw_scope' => 'staff', 'withdraw_members' => [$other]], $this->clerk);

        $this->advance();

        $refusal = $this->refusal(fn () => $this->refund('50.00', null, $drawer));

        $this->assertSame(AccountingError::TreasuryWithdrawDenied, $refusal->error);
        $this->assertSame('Locked drawer', $refusal->context['name']);
        $this->assertSame(0, $this->countRows('customer_credit_refunds'));
        $this->assertSame('200.00', $this->free());
    }

    public function testRefund_AcceptsATreasuryTheEmployeeIsAllowedToWithdrawFrom(): void
    {
        $treasuries = new TreasuryService();
        $drawer     = $treasuries->create(['name' => 'Own drawer', 'kind' => 'cash', 'withdraw_scope' => 'staff', 'withdraw_members' => [$this->clerk]], $this->clerk);

        $this->advance();
        $this->refund('50.00', null, $drawer);

        $this->assertSame('-50.0000', $treasuries->position($drawer)->balance);
    }

    public function testRefund_AppliesTheScopeOfTheMainTreasuryWhenNoTreasuryIsNamed(): void
    {
        $treasuries = new TreasuryService();
        $other      = $this->employeeWithGrants([]);
        $main       = $treasuries->ensureMain();

        $this->advance();
        $treasuries->update($main, ['name' => 'Main', 'kind' => 'cash', 'withdraw_scope' => 'staff', 'withdraw_members' => [$other]], $this->clerk);

        $this->assertSame(AccountingError::TreasuryWithdrawDenied, $this->refusal(fn () => $this->refund('50.00'))->error);
        $this->assertSame(0, $this->countRows('customer_credit_refunds'));
    }

    public function testRefund_AppliesTheScopeOfTheTreasuryTheWayOfPaymentIsRoutedToWhenNoTreasuryIsNamed(): void
    {
        $treasuries = new TreasuryService();
        $other      = $this->employeeWithGrants([]);
        $bank       = $treasuries->create(['name' => 'Routed bank', 'kind' => 'bank', 'bank_name' => 'Gulf Bank', 'account_number' => '1234567890', 'withdraw_scope' => 'staff', 'withdraw_members' => [$other]], $this->clerk);

        $this->advance();
        $this->accountingDb()->table('accounting_routing')->where('routing_key', 'payment.cash')->update(['account_id' => $treasuries->accountOf($bank)]);

        $refusal = $this->refusal(fn () => $this->refund('50.00'));

        $this->assertSame(AccountingError::TreasuryWithdrawDenied, $refusal->error);
        $this->assertSame('Routed bank', $refusal->context['name']);
    }

    public function testRefund_DoesNotLetTheDepositScopeStandInForTheWithdrawScope(): void
    {
        $treasuries = new TreasuryService();
        $other      = $this->employeeWithGrants([]);
        $drawer     = $treasuries->create(['name' => 'Receipts only', 'kind' => 'cash', 'deposit_scope' => 'staff', 'deposit_members' => [$other]], $this->clerk);

        $this->advance('200.00', null, null);
        $this->refund('50.00', null, $drawer);

        $this->assertSame('-50.0000', $treasuries->position($drawer)->balance);
    }

    public function testRefund_LetsTheBooksRefundWhateverTheScopeOfTheTreasury(): void
    {
        $treasuries = new TreasuryService();
        $other      = $this->employeeWithGrants([]);
        $drawer     = $treasuries->create(['name' => 'Locked drawer', 'kind' => 'cash', 'withdraw_scope' => 'staff', 'withdraw_members' => [$other]], $this->clerk);

        $this->advance();
        $this->refunds->refund($this->customerId, '50.00', date('Y-m-d'), 'Cash', $drawer);

        $this->assertSame('-50.0000', $treasuries->position($drawer)->balance);
    }

    public function testRefund_RequiresItsOwnPermissionAndNotTheOneThatReceivesAdvances(): void
    {
        $this->advance();

        $receiver = $this->employeeWithGrants([AccountingGuard::RECEIVABLES_MANAGE]);
        $outsider = $this->employeeWithGrants([]);

        $this->assertSame(AccountingError::PermissionDenied, $this->refusal(fn () => $this->refund('50.00', null, null, $receiver))->error);
        $this->assertSame(AccountingError::PermissionDenied, $this->refusal(fn () => $this->refund('50.00', null, null, $outsider))->error);
        $this->assertSame(0, $this->countRows('customer_credit_refunds'));
        $this->assertSame('200.00', $this->free());
    }

    public function testRefund_RefusesABranchTheEmployeeDoesNotWorkIn(): void
    {
        $this->advance();

        $other   = $this->createBranch('B2');
        $limited = $this->employeeWithGrants([AccountingGuard::RECEIVABLES_REFUND], [$other]);
        $drawer  = (new TreasuryService())->create(['name' => 'Branch one drawer', 'kind' => 'cash', 'branch_id' => 1], $this->clerk);

        $this->refusal(fn () => $this->refund('50.00', null, $drawer, $limited));

        $this->assertSame(0, $this->countRows('customer_credit_refunds'));
        $this->assertSame('200.00', $this->free());
    }

    public function testRefund_TakesTheBranchOfTheTreasuryItWentThrough(): void
    {
        $other  = $this->createBranch('B2');
        $drawer = (new TreasuryService())->create(['name' => 'Branch two drawer', 'kind' => 'cash', 'branch_id' => $other], $this->clerk);

        $this->advance();

        $refundId = $this->refund('50.00', null, $drawer);
        $journal  = $this->accountingDb()->table('accounting_journals')->where(['source_type' => CreditRefunds::CUSTOMER_SOURCE, 'source_id' => $refundId])->get()->getRow();

        $this->assertSame($other, (int) $this->accountingDb()->table('customer_credit_refunds')->where('refund_id', $refundId)->get()->getRow()->branch_id);
        $this->assertSame($other, (int) $journal->branch_id);
    }

    public function testRefund_IsRefusedInAClosedPeriodAndSavesNothing(): void
    {
        $this->advance();
        $this->closeThrough(date('Y-m-d'));

        $exception = $this->refusal(fn () => $this->refund('50.00'));

        $this->assertStringContainsString(date('Y-m-d'), $exception->getMessage());
        $this->assertSame(0, $this->countRows('customer_credit_refunds'));
        $this->assertSame(0, $this->countRows('customer_credit_refund_sources'));
        $this->assertSame('-200.00', $this->balance($this->customerAccount()));
        $this->assertSame('200.00', $this->free());
    }

    public function testCancel_ReversesTodayWhenThePeriodOfTheRefundIsClosedInsteadOfRewritingIt(): void
    {
        $this->advance('200.00', $this->daysAgo(10));

        $refundId = $this->refund('50.00', $this->daysAgo(8));

        $this->closeThrough($this->daysAgo(5));
        $this->refunds->cancel($refundId, 'paid twice', $this->clerk);

        $reversal = $this->accountingDb()->table('accounting_journals')->where('source_type', 'reversal')->get()->getRow();

        $this->assertSame(date('Y-m-d'), $reversal->journal_date);
        $this->assertSame('-200.00', $this->balance($this->customerAccount()));
        $this->assertSame('200.00', $this->free());
    }

    public function testRefund_RefusesMoreThanTheCreditThatIsFreeAndAcceptsExactlyThat(): void
    {
        $this->advance('100.00');
        $this->invoiceOf('60.00');

        $this->assertSame('40.00', $this->free());

        $refusal = $this->refusal(fn () => $this->refund('40.01'));

        $this->assertSame(AccountingError::RefundExceedsCredit, $refusal->error);
        $this->assertSame('40.00', $refusal->context['max']);
        $this->assertSame(0, $this->countRows('customer_credit_refunds'));

        $this->refund('40.00');

        $this->assertSame('0.00', $this->free());
        $this->assertTies();
    }

    public function testRefund_RefusesACustomerWithoutCredit(): void
    {
        $this->invoiceOf('100.00');

        $this->assertSame(AccountingError::RefundExceedsCredit, $this->refusal(fn () => $this->refund('1.00'))->error);
    }

    public function testRefund_RefusesWhatCannotBePaid(): void
    {
        $this->advance();

        foreach (['0', '-5', 'ten', '', '1e3'] as $amount) {
            $this->assertSame(AccountingError::AdvanceAmountInvalid, $this->refusal(fn () => $this->refund($amount))->error, "amount {$amount}");
        }

        foreach ([lang('Sales.due'), lang('Sales.giftcard'), lang('Sales.rewards'), 'Barter'] as $type) {
            $this->assertSame(AccountingError::CreditPaymentUnknown, $this->refusal(fn () => $this->refunds->refund($this->customerId, '10', date('Y-m-d'), $type, null, '', '', $this->clerk))->error, "type {$type}");
        }

        $this->assertSame(AccountingError::RefundPartyMissing, $this->refusal(fn () => $this->refund('10', null, null, null, null, 99999999))->error);
        $this->assertSame(AccountingError::PaymentDateInFuture, $this->refusal(fn () => $this->refund('10', date('Y-m-d', strtotime('+1 day'))))->error);
        $this->assertSame(AccountingError::InvalidDate, $this->refusal(fn () => $this->refund('10', '2026-02-30'))->error);
        $this->assertSame(0, $this->countRows('customer_credit_refunds'));
    }

    public function testRefund_TakesTheCreditOfTheOldestSourcesFirstAndSaysHowMuchOfEach(): void
    {
        $invoice = $this->invoiceOf('10.00', null, [$this->payment(lang('Sales.cash'), '10.00')]);
        $note    = $this->creditNoteOf($invoice, '50.00', null);
        $advance = $this->advance('100.00', $this->daysAgo(3));

        $this->accountingDb()->table('sales')->where('sale_id', $note)->update(['sale_time' => $this->daysAgo(1) . ' 10:00:00']);
        $this->accountingDb()->table('accounting_journals')->where(['source_type' => 'sale', 'source_id' => $note])->update(['journal_date' => $this->daysAgo(1)]);

        $refundId = $this->refund('120.00');

        $rows = $this->accountingDb()->table('customer_credit_refund_sources')->where('refund_id', $refundId)->orderBy('source_id')->get()->getResult();
        $by   = [];

        foreach ($rows as $row) {
            $by[$row->source_type . ':' . $row->source_id] = bcadd($row->amount, '0', 2);
        }

        $this->assertSame([CreditSource::CUSTOMER_ADVANCE . ':' . $advance => '100.00', CreditSource::CREDIT_NOTE . ':' . $note => '20.00'], $by);
        $this->assertSame('30.00', $this->free());
        $this->assertTies();
    }

    public function testRefund_TakesOnlyCreditThatExistedOnItsDate(): void
    {
        $this->advance('100.00', $this->daysAgo(2));

        $refusal = $this->refusal(fn () => $this->refund('50.00', $this->daysAgo(4)));

        $this->assertSame(AccountingError::RefundExceedsCredit, $refusal->error);
        $this->assertSame('0.00', $refusal->context['max']);

        $this->refund('50.00', $this->daysAgo(2));

        $this->assertSame('50.00', $this->free());
    }

    public function testRefund_KeepsTheTieOfTheCustomerOnEveryDayInThePast(): void
    {
        $this->advance('100.00', $this->daysAgo(6));
        $this->refund('30.00', $this->daysAgo(3));

        foreach ([$this->daysAgo(7), $this->daysAgo(6), $this->daysAgo(4), $this->daysAgo(3), date('Y-m-d')] as $day) {
            $this->assertTrue($this->position(null, $day)->agrees(), $day);
        }

        $this->assertSame('100.00', bcadd($this->position(null, $this->daysAgo(4))->credit(), '0', 2));
        $this->assertSame('70.00', bcadd($this->position(null, $this->daysAgo(3))->credit(), '0', 2));
    }

    public function testRefund_LeavesTheNextInvoiceWithOnlyWhatTheCreditThatIsLeftCovers(): void
    {
        $this->advance('100.00');
        $this->refund('70.00');

        $invoice = $this->invoiceOf('50.00');

        $this->assertSame(['customer_advance:' . $this->accountingDb()->table('customer_advances')->get()->getRow()->advance_id . '->' . $invoice . ':30.00'], $this->applications());
        $this->assertSame('20.00', bcadd($this->position()->invoice($invoice)->open(), '0', 2));
        $this->assertTies();
    }

    public function testRefund_WritesTheAuditEntriesOfTheRefundAndOfItsCancellation(): void
    {
        $this->advance();

        $refundId = $this->refund('50.00');

        $this->refunds->cancel($refundId, 'paid twice', $this->clerk);

        $entries = $this->accountingDb()->table('accounting_audit_log')->where(['entity_type' => 'customer_refund', 'entity_id' => $refundId])->orderBy('audit_id')->get()->getResult();

        $this->assertSame(['created', 'cancelled'], array_column($entries, 'action'));
        $this->assertSame((string) $this->clerk, (string) $entries[0]->employee_id);
        $this->assertSame('50.0000', json_decode((string) $entries[0]->details, true)['amount']);
        $this->assertSame('paid twice', json_decode((string) $entries[1]->details, true)['reason']);
    }

    public function testCancel_ReversesTheJournalAndGivesTheCreditBack(): void
    {
        $this->advance('100.00');

        $refundId = $this->refund('60.00');

        $this->assertSame('40.00', $this->free());

        $this->refunds->cancel($refundId, 'paid twice', $this->clerk);

        $this->assertSame('100.00', $this->free());
        $this->assertSame('-100.00', $this->balance($this->customerAccount()));
        $this->assertSame('100.00', $this->balance($this->accountId('cash')));
        $this->assertTies();

        $row = $this->accountingDb()->table('customer_credit_refunds')->where('refund_id', $refundId)->get()->getRow();

        $this->assertSame(['cancelled', 'paid twice', (string) $this->clerk], [$row->status, $row->cancel_reason, (string) $row->cancelled_by]);
        $this->assertSame(1, $this->countRows('customer_credit_refunds'));
        $this->assertSame(['reversed', 'posted'], array_column($this->accountingDb()->table('accounting_journals')->select('status')->whereIn('source_type', [CreditRefunds::CUSTOMER_SOURCE, 'reversal'])->orderBy('journal_id')->get()->getResultArray(), 'status'));
    }

    public function testCancel_SpendsTheCreditItGivesBackOnTheInvoiceThatWaitedForIt(): void
    {
        $this->advance('100.00');

        $refundId = $this->refund('100.00');
        $invoice  = $this->invoiceOf('80.00');

        $this->assertSame('80.00', bcadd($this->position()->invoice($invoice)->open(), '0', 2));

        $this->refunds->cancel($refundId, 'paid twice', $this->clerk);

        $this->assertSame('0.00', bcadd($this->position()->invoice($invoice)->open(), '0', 2));
        $this->assertSame('20.00', $this->free());
        $this->assertTies();
    }

    public function testCancel_RefusesAnEmployeeWhoMayNotDepositIntoTheTreasuryTheRefundCameFrom(): void
    {
        $treasuries = new TreasuryService();
        $other      = $this->employeeWithGrants([]);
        $drawer     = $treasuries->create(['name' => 'Refund drawer', 'kind' => 'cash', 'deposit_scope' => 'staff', 'deposit_members' => [$this->clerk]], $this->clerk);

        $this->advance('200.00', null, $drawer);

        $refundId = $this->refund('50.00', null, $drawer);

        $treasuries->update($drawer, ['name' => 'Refund drawer', 'kind' => 'cash', 'deposit_scope' => 'staff', 'deposit_members' => [$other]], $this->clerk);

        $this->assertSame(AccountingError::TreasuryDepositDenied, $this->refusal(fn () => $this->refunds->cancel($refundId, 'paid twice', $this->clerk))->error);
        $this->assertSame('completed', $this->accountingDb()->table('customer_credit_refunds')->where('refund_id', $refundId)->get()->getRow()->status);
        $this->assertSame('150.00', $this->free());
    }

    public function testCancel_RefusesARefundThatWasCancelledAndOneThatIsNotThere(): void
    {
        $this->advance();

        $refundId = $this->refund('50.00');

        $this->refunds->cancel($refundId, 'twice', $this->clerk);

        $this->assertSame(AccountingError::RefundAlreadyCancelled, $this->refusal(fn () => $this->refunds->cancel($refundId, 'twice', $this->clerk))->error);
        $this->assertSame(AccountingError::RefundNotFound, $this->refusal(fn () => $this->refunds->cancel(999999, 'twice', $this->clerk))->error);
        $this->assertSame('200.00', $this->free());
    }

    public function testCancel_RequiresAReasonThePermissionAndABranchTheEmployeeWorksIn(): void
    {
        $this->advance();

        $refundId = $this->refund('50.00');
        $outsider = $this->employeeWithGrants([]);

        foreach (['', '   ', str_repeat('x', 256)] as $reason) {
            $this->assertSame(AccountingError::ReasonRequired, $this->refusal(fn () => $this->refunds->cancel($refundId, $reason, $this->clerk))->error);
        }

        $this->assertSame(AccountingError::PermissionDenied, $this->refusal(fn () => $this->refunds->cancel($refundId, 'no right', $outsider))->error);

        $other   = $this->createBranch('B2');
        $limited = $this->employeeWithGrants([AccountingGuard::RECEIVABLES_REFUND], [$other]);

        $this->refusal(fn () => $this->refunds->cancel($refundId, 'other branch', $limited));

        $this->assertSame('completed', $this->accountingDb()->table('customer_credit_refunds')->where('refund_id', $refundId)->get()->getRow()->status);
    }

    public function testRefund_SavesOneRefundWhenTheFormIsSentTwiceWithTheSameKey(): void
    {
        $this->advance();

        $first  = $this->refund('50.00', null, null, null, 'refund-form-1');
        $second = $this->refund('50.00', null, null, null, 'refund-form-1');

        $this->assertSame($first, $second);
        $this->assertSame(1, $this->countRows('customer_credit_refunds'));
        $this->assertSame(1, $this->accountingDb()->table('accounting_journals')->where('source_type', CreditRefunds::CUSTOMER_SOURCE)->countAllResults());
        $this->assertSame('150.00', $this->free());
    }

    public function testRefund_RefusesAKeyThatWasUsedForAnotherAmount(): void
    {
        $this->advance();
        $this->refund('50.00', null, null, null, 'refund-form-2');

        $this->assertSame(AccountingError::WriteFailed, $this->refusal(fn () => $this->refund('60.00', null, null, null, 'refund-form-2'))->error);
        $this->assertSame(1, $this->countRows('customer_credit_refunds'));
    }

    public function testRefund_ForbidsDeletingThePaymentWhoseExcessWasPaidBackAndChangesNothing(): void
    {
        $invoice = $this->invoiceOf('230.00');
        $paid    = $this->addPayment($invoice, '300.00');

        $this->refund('70.00');

        $journals = $this->countJournals();
        $before   = $this->accountingDb()->table('sales_payments')->where('payment_id', $paid)->get()->getRow();

        $this->assertFalse($this->sales->update($invoice, ['comment' => 'removed', 'payments' => [
            ['payment_id' => $paid, 'payment_type' => lang('Sales.cash'), 'payment_amount' => '0', 'cash_refund' => 0, 'cash_adjustment' => 0, 'employee_id' => $this->employeeId, 'reference_code' => null],
        ]]));

        $failure = $this->failure();

        $this->assertInstanceOf(AccountingException::class, $failure);
        $this->assertSame(AccountingError::CreditRefunded, $failure->error);
        $this->assertSame($journals, $this->countJournals());
        $this->assertEquals($before, $this->accountingDb()->table('sales_payments')->where('payment_id', $paid)->get()->getRow());
        $this->assertSame('0.00', $this->free());
        $this->assertTies();
    }

    public function testRefund_AllowsDeletingThePaymentOnceTheRefundIsCancelled(): void
    {
        $invoice  = $this->invoiceOf('230.00');
        $paid     = $this->addPayment($invoice, '300.00');
        $refundId = $this->refund('70.00');

        $this->refunds->cancel($refundId, 'paid twice', $this->clerk);
        $this->removePayment($invoice, $paid);

        $this->assertSame('230.00', bcadd($this->position()->invoice($invoice)->open(), '0', 2));
        $this->assertSame('0.00', $this->free());
        $this->assertTies();
    }

    public function testRefund_ForbidsCancellingTheAdvanceWhoseCreditWasPaidBackUntilTheRefundIsCancelled(): void
    {
        $advanceId = $this->advance('100.00');
        $refundId  = $this->refund('60.00');

        $refusal = $this->refusal(fn () => $this->advances->cancel($advanceId, 'taken by mistake', $this->clerk));

        $this->assertSame(AccountingError::CreditRefunded, $refusal->error);
        $this->assertSame('completed', $this->accountingDb()->table('customer_advances')->where('advance_id', $advanceId)->get()->getRow()->status);
        $this->assertSame('40.00', $this->free());

        $this->refunds->cancel($refundId, 'paid twice', $this->clerk);
        $this->advances->cancel($advanceId, 'taken by mistake', $this->clerk);

        $this->assertSame('0.00', $this->free());
        $this->assertSame('0.00', $this->balance($this->customerAccount()));
        $this->assertTies();
    }

    public function testRefund_LeavesTheRestOfAnAdvanceToBeCancelledWhenOnlyPartOfItWasPaidBack(): void
    {
        $first  = $this->advance('100.00', $this->daysAgo(3));
        $second = $this->advance('50.00', $this->daysAgo(1));

        $this->refund('80.00');

        $this->assertSame(AccountingError::CreditRefunded, $this->refusal(fn () => $this->advances->cancel($first, 'mistake', $this->clerk))->error);

        $this->advances->cancel($second, 'mistake', $this->clerk);

        $this->assertSame('20.00', $this->free());
        $this->assertTies();
    }

    public function testForParty_ListsTheRefundsNewestFirstWithTheirTreasuriesAndTheirCredits(): void
    {
        $drawer = (new TreasuryService())->create(['name' => 'Refund drawer', 'kind' => 'cash'], $this->clerk);

        $this->advance('300.00');

        $first  = $this->refund('50.00');
        $second = $this->refund('70.00', null, $drawer);

        $list = $this->refunds->forParty($this->customerId);

        $this->assertSame([$second, $first], array_map(static fn (object $row): int => (int) $row->refund_id, $list));
        $this->assertSame(['Refund drawer', null], array_map(static fn (object $row): ?string => $row->treasury_name, $list));
        $this->assertSame(['70.0000'], array_map(static fn (object $source): string => $source->amount, $list[0]->sources));
        $this->assertSame([], $this->refunds->forParty($this->anotherCustomer()));
    }

    public function testBranchOfAndAvailable_TellWhereARefundBelongsAndWhatCreditIsFree(): void
    {
        $this->advance('100.00');

        $refundId = $this->refund('30.00');

        $this->assertSame(1, $this->refunds->branchOf($refundId));
        $this->assertNull($this->refunds->branchOf(999999));
        $this->assertSame('70.0000', $this->refunds->available($this->customerId));
    }

    public function testRefund_NeverTakesTheCreditOfAnotherCustomer(): void
    {
        $other = $this->anotherCustomer();

        $this->advance('100.00');

        $this->assertSame(AccountingError::RefundExceedsCredit, $this->refusal(fn () => $this->refund('10.00', null, null, null, null, $other))->error);
        $this->assertSame('100.00', $this->free());
    }
}
