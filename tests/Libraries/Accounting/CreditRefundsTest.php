<?php

namespace Tests\Libraries\Accounting;

use App\Libraries\Accounting\AccountingError;
use App\Libraries\Accounting\AccountingException;
use App\Libraries\Accounting\AccountingGuard;
use App\Libraries\Accounting\CreditRefunds;
use App\Libraries\Accounting\CreditSource;
use App\Libraries\Accounting\CustomerAdvances;
use App\Libraries\Accounting\PostingException;
use App\Libraries\Accounting\SupplierAdvances;
use App\Libraries\Accounting\TreasuryService;
use App\Libraries\Organization\BranchError;
use App\Libraries\Organization\BranchException;
use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\DatabaseTestTrait;
use Config\Database;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Support\AccountingFixtureTrait;
use Tests\Support\ChequeFixtureTrait;
use Tests\Support\ConcurrentDbRaceTrait;
use Tests\Support\CreditRefundFixtureTrait;
use Tests\Support\CreditSourceFixtureTrait;
use Tests\Support\EmployeeFixtureTrait;
use Tests\Support\ItemFixtureTrait;
use Tests\Support\PurchaseAccountingFixtureTrait;
use Tests\Support\SaleAccountingFixtureTrait;
use App\Libraries\Roles\RoleService;

/**
 * Paying a customer back the credit they hold and being paid back the credit held with a supplier: a
 * journal that goes through a treasury, taken from named credits so that what is left to spend is what
 * the account still holds, and a refund that is cancelled rather than deleted.
 */
class CreditRefundsTest extends CIUnitTestCase
{
    use DatabaseTestTrait;
    use AccountingFixtureTrait;
    use ChequeFixtureTrait;
    use ConcurrentDbRaceTrait;
    use CreditSourceFixtureTrait;
    use EmployeeFixtureTrait;
    use ItemFixtureTrait;
    use SaleAccountingFixtureTrait;
    use PurchaseAccountingFixtureTrait;
    use CreditRefundFixtureTrait;

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

        $this->startBuying();

        $this->refunds  = new CreditRefunds(CreditRefunds::CUSTOMER);
        $this->advances = new CustomerAdvances();
        $this->clerk    = $this->employeeWithGrants([AccountingGuard::RECEIVABLES_MANAGE, AccountingGuard::RECEIVABLES_REFUND, AccountingGuard::TREASURIES_MANAGE]);
    }

    protected function tearDown(): void
    {
        $this->stopCreditSources();
        $this->stopBuying();

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

    private function assertOutsideTheBranch(callable $action): void
    {
        $refusal = $this->refusal($action);

        $this->assertInstanceOf(BranchException::class, $refusal);
        $this->assertSame(BranchError::NotAllowed, $refusal->error);
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

        $this->assertOutsideTheBranch(fn () => $this->refund('50.00', null, $drawer, $limited));

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

        foreach (['0', '-5', 'ten', '', '1e3', '1.23456'] as $amount) {
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

    public function testRefund_RefusesTheExcessOfAnInvoiceOnDaysBeforeThePaymentThatOverpaidIt(): void
    {
        $invoice = $this->invoiceDated('100.00', $this->daysAgo(10));

        $this->addPayment($invoice, '150.00');

        $refusal = $this->refusal(fn () => $this->refund('50.00', $this->daysAgo(5)));

        $this->assertSame(AccountingError::RefundExceedsCredit, $refusal->error);
        $this->assertSame('0.00', $refusal->context['max']);
        $this->assertSame(0, $this->countRows('customer_credit_refunds'));

        $this->refund('50.00', date('Y-m-d'));

        foreach ([$this->daysAgo(10), $this->daysAgo(5), $this->daysAgo(1), date('Y-m-d')] as $day) {
            $this->assertTrue($this->position(null, $day)->agrees(), $day);
        }
    }

    public function testRefund_TakesOfAnExcessOnlyWhatThePaymentsBeforeItsDateHadOverpaid(): void
    {
        $invoice = $this->invoiceDated('100.00', $this->daysAgo(10));
        $first   = $this->addPayment($invoice, '120.00');

        $this->accountingDb()->table('sales_payments')->where('payment_id', $first)->update(['payment_time' => $this->daysAgo(8) . ' 10:00:00']);
        $this->accountingDb()->table('accounting_journals')->where(['source_type' => 'sale_payment', 'source_id' => $first])->update(['journal_date' => $this->daysAgo(8)]);
        $this->addPayment($invoice, '30.00');

        $this->assertSame('50.00', $this->free());

        $refusal = $this->refusal(fn () => $this->refund('20.01', $this->daysAgo(5)));

        $this->assertSame(AccountingError::RefundExceedsCredit, $refusal->error);
        $this->assertSame('20.00', $refusal->context['max']);

        $this->refund('20.00', $this->daysAgo(5));

        foreach ([$this->daysAgo(10), $this->daysAgo(8), $this->daysAgo(5), $this->daysAgo(1), date('Y-m-d')] as $day) {
            $this->assertTrue($this->position(null, $day)->agrees(), $day);
        }

        $this->assertSame('30.00', $this->free());
    }

    public function testRefund_TakesOfAnAdvanceOnlyWhatIsFreeNowEvenOnADayBeforeItWasSpent(): void
    {
        $this->advance('100.00', $this->daysAgo(10));

        $invoice = $this->invoiceDated('60.00', $this->daysAgo(8));

        $this->assertSame(['customer_advance:' . $this->accountingDb()->table('customer_advances')->get()->getRow()->advance_id . '->' . $invoice . ':60.00'], $this->applications());
        $this->assertSame('40.00', $this->free());

        $refusal = $this->refusal(fn () => $this->refund('50.00', $this->daysAgo(5)));

        $this->assertSame(AccountingError::RefundExceedsCredit, $refusal->error);
        $this->assertSame('40.00', $refusal->context['max']);
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
            $this->assertSame(AccountingError::ReasonRequired, $this->refusal(fn () => $this->refunds->cancel(999999, $reason, $this->clerk))->error);
        }

        $this->assertSame(AccountingError::PermissionDenied, $this->refusal(fn () => $this->refunds->cancel($refundId, 'no right', $outsider))->error);

        $other   = $this->createBranch('B2');
        $limited = $this->employeeWithGrants([AccountingGuard::RECEIVABLES_REFUND], [$other]);

        $this->assertOutsideTheBranch(fn () => $this->refunds->cancel($refundId, 'other branch', $limited));

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

    public function testRefund_RefusesAKeyThatWasUsedForAnotherCustomerEvenForTheSameAmount(): void
    {
        $other = $this->anotherCustomer();

        $this->advance();
        $this->advances->receive($other, '200.00', date('Y-m-d'), 'Cash', null, '', '', $this->clerk);

        $first = $this->refund('50.00', null, null, null, 'refund-form-3');

        $refusal = $this->refusal(fn () => $this->refund('50.00', null, null, null, 'refund-form-3', $other));

        $this->assertSame(AccountingError::WriteFailed, $refusal->error);
        $this->assertSame($first, $refusal->context['refund_id']);
        $this->assertSame(1, $this->countRows('customer_credit_refunds'));
        $this->assertSame('200.00', bcadd($this->position($other)->credit(), '0', 2));
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

    // ---------------------------------------------------------------------------------------
    // Being paid back the credit held with a supplier
    // ---------------------------------------------------------------------------------------

    public function testRefund_DebitsTheTreasuryAndCreditsTheSupplierWhenTheyPayTheCreditBack(): void
    {
        $this->advanceToSupplier('300.00');

        $refundId = $this->supplierRefund('100.00');
        $journal  = $this->accountingDb()->table('accounting_journals')->where(['source_type' => CreditRefunds::SUPPLIER_SOURCE, 'source_id' => $refundId, 'status' => 'posted'])->get()->getRow();
        $lines    = array_map(
            static fn (object $line): string => $line->account_id . '=' . bcadd($line->debit, '0', 2) . '/' . bcadd($line->credit, '0', 2),
            $this->accountingDb()->table('accounting_journal_lines')->where('journal_id', $journal->journal_id)->orderBy('line_no')->get()->getResult(),
        );

        $this->assertSame([$this->accountId('cash') . '=100.00/0.00', $this->supplierAccount() . '=0.00/100.00'], $lines);
        $this->assertSame('200.00', bcadd($this->supplierPosition()->credit(), '0', 2));
        $this->assertSame('-200.00', $this->owed());
        $this->assertSupplierTies();
    }

    public function testRefund_TiesTheTreasuryItCameThroughToItsPositionForASupplier(): void
    {
        $treasuries = new TreasuryService();
        $drawer     = $treasuries->create(['name' => 'Supplier receipts', 'kind' => 'cash'], $this->supplierTreasurer());

        $this->advanceToSupplier('300.00', null, $drawer);
        $this->supplierRefund('120.00', null, $drawer);

        $this->assertSame('-180.0000', $treasuries->position($drawer)->balance);
        $this->assertSame($this->balance($treasuries->accountOf($drawer)), bcadd($treasuries->position($drawer)->balance, '0', 2));
        $this->assertSupplierTies();
    }

    public function testRefund_RequiresThePayablesRefundPermissionAndNotTheOneOfCustomers(): void
    {
        $this->advanceToSupplier('300.00');

        $customerSide = $this->employeeWithGrants([AccountingGuard::RECEIVABLES_REFUND, AccountingGuard::PAYABLES_MANAGE]);

        $this->assertSame(AccountingError::PermissionDenied, $this->refusal(fn () => $this->supplierRefund('10.00', $customerSide))->error);
        $this->assertSame(0, $this->countRows('supplier_credit_refunds'));
        $this->assertSame('300.00', bcadd($this->supplierPosition()->credit(), '0', 2));
    }

    public function testRefund_RefusesATreasuryTheEmployeeMayNotDepositIntoAndAcceptsOneTheyMay(): void
    {
        $treasurer  = $this->supplierTreasurer();
        $treasuries = new TreasuryService();
        $other      = $this->employeeWithGrants([]);
        $locked     = $treasuries->create(['name' => 'Locked drawer', 'kind' => 'cash', 'deposit_scope' => 'staff', 'deposit_members' => [$other]], $treasurer);
        $own        = $treasuries->create(['name' => 'Own drawer', 'kind' => 'cash', 'deposit_scope' => 'staff', 'deposit_members' => [$treasurer]], $treasurer);

        $this->advanceToSupplier('300.00');

        $refusal = $this->refusal(fn () => $this->supplierRefund('50.00', $treasurer, $locked));

        $this->assertSame(AccountingError::TreasuryDepositDenied, $refusal->error);
        $this->assertSame(0, $this->countRows('supplier_credit_refunds'));

        $this->supplierRefund('50.00', $treasurer, $own);

        $this->assertSame('50.0000', $treasuries->position($own)->balance);
    }

    public function testRefund_DoesNotLetTheWithdrawScopeStandInForTheDepositScope(): void
    {
        $treasurer  = $this->supplierTreasurer();
        $treasuries = new TreasuryService();
        $other      = $this->employeeWithGrants([]);
        $drawer     = $treasuries->create(['name' => 'Pay-out only', 'kind' => 'cash', 'withdraw_scope' => 'staff', 'withdraw_members' => [$other]], $treasurer);

        $this->advanceToSupplier('300.00');
        $this->supplierRefund('50.00', $treasurer, $drawer);

        $this->assertSame('50.0000', $treasuries->position($drawer)->balance);
    }

    public function testRefund_RefusesABranchTheEmployeeDoesNotWorkInForASupplier(): void
    {
        $this->advanceToSupplier('300.00');

        $other   = $this->createBranch('B2');
        $limited = $this->employeeWithGrants([AccountingGuard::PAYABLES_REFUND], [$other]);
        $drawer  = (new TreasuryService())->create(['name' => 'Branch one drawer', 'kind' => 'cash', 'branch_id' => 1], $this->supplierTreasurer());

        $this->assertOutsideTheBranch(fn () => $this->supplierRefund('50.00', $limited, $drawer));

        $this->assertSame(0, $this->countRows('supplier_credit_refunds'));
    }

    public function testRefund_IsRefusedInAClosedPeriodAndSavesNothingForASupplier(): void
    {
        $this->advanceToSupplier('300.00');
        $this->closeThrough(date('Y-m-d'));

        $exception = $this->refusal(fn () => $this->supplierRefund('50.00'));

        $this->assertStringContainsString(date('Y-m-d'), $exception->getMessage());
        $this->assertSame(0, $this->countRows('supplier_credit_refunds'));
        $this->assertSame(0, $this->countRows('supplier_credit_refund_sources'));
        $this->assertSame('300.00', bcadd($this->supplierPosition()->credit(), '0', 2));
    }

    public function testRefund_RefusesMoreThanTheCreditThatIsFreeBecauseWhatWasSpentOnAPurchaseIsNotFree(): void
    {
        $this->advanceToSupplier('100.00');
        $this->purchaseOf('60.00');

        $this->assertSame('40.00', bcadd($this->supplierPosition()->credit(), '0', 2));

        $refusal = $this->refusal(fn () => $this->supplierRefund('40.01'));

        $this->assertSame(AccountingError::RefundExceedsCredit, $refusal->error);
        $this->assertSame('40.00', $refusal->context['max']);

        $this->supplierRefund('40.00');

        $this->assertSame('0.00', bcadd($this->supplierPosition()->credit(), '0', 2));
        $this->assertSupplierTies();
    }

    public function testRefund_TakesTheCreditOfTheOldestSupplierSourcesFirst(): void
    {
        $purchase = $this->purchaseOf('100.00', null, lang('Sales.cash'));
        $note     = $this->debitNoteOf($purchase, '40.00');
        $advance  = (new SupplierAdvances())->pay($this->supplierId, '100.00', $this->daysAgo(3), 'Cash', null, '', '', $this->supplierTreasurer());

        $this->accountingDb()->table('receivings')->where('receiving_id', $note)->update(['receiving_time' => $this->daysAgo(1) . ' 10:00:00']);
        $this->accountingDb()->table('accounting_journals')->where(['source_type' => 'purchase', 'source_id' => $note])->update(['journal_date' => $this->daysAgo(1)]);

        $refundId = $this->supplierRefund('120.00');
        $by       = [];

        foreach ($this->accountingDb()->table('supplier_credit_refund_sources')->where('refund_id', $refundId)->get()->getResult() as $row) {
            $by[$row->source_type . ':' . $row->source_id] = bcadd($row->amount, '0', 2);
        }

        $this->assertEquals(['supplier_advance:' . $advance => '100.00', 'debit_note:' . $note => '20.00'], $by);
        $this->assertSupplierTies();
    }

    public function testCancel_ReversesTheJournalGivesTheCreditBackAndSpendsItOnThePurchaseThatWaited(): void
    {
        $treasurer = $this->supplierTreasurer();

        $this->advanceToSupplier('100.00');

        $refundId = $this->supplierRefund('100.00', $treasurer);
        $purchase = $this->purchaseOf('80.00');

        $this->assertSame('80.00', bcadd($this->supplierPosition()->purchase($purchase)->open(), '0', 2));

        $this->refundsFromSuppliers()->cancel($refundId, 'paid twice', $treasurer);

        $this->assertSame('0.00', bcadd($this->supplierPosition()->purchase($purchase)->open(), '0', 2));
        $this->assertSame('20.00', bcadd($this->supplierPosition()->credit(), '0', 2));
        $this->assertSame('cancelled', $this->accountingDb()->table('supplier_credit_refunds')->where('refund_id', $refundId)->get()->getRow()->status);
        $this->assertSupplierTies();
    }

    public function testCancel_RefusesAnEmployeeWhoMayNotWithdrawFromTheTreasuryTheRefundWentInto(): void
    {
        $treasurer  = $this->supplierTreasurer();
        $treasuries = new TreasuryService();
        $other      = $this->employeeWithGrants([]);
        $drawer     = $treasuries->create(['name' => 'Supplier receipts', 'kind' => 'cash', 'withdraw_scope' => 'staff', 'withdraw_members' => [$treasurer]], $treasurer);

        $this->advanceToSupplier('300.00');

        $refundId = $this->supplierRefund('50.00', $treasurer, $drawer);

        $treasuries->update($drawer, ['name' => 'Supplier receipts', 'kind' => 'cash', 'withdraw_scope' => 'staff', 'withdraw_members' => [$other]], $treasurer);

        $this->assertSame(AccountingError::TreasuryWithdrawDenied, $this->refusal(fn () => $this->refundsFromSuppliers()->cancel($refundId, 'paid twice', $treasurer))->error);
        $this->assertSame('completed', $this->accountingDb()->table('supplier_credit_refunds')->where('refund_id', $refundId)->get()->getRow()->status);
    }

    public function testRefund_SavesOneRefundWhenTheFormIsSentTwiceAndWritesItsAuditEntries(): void
    {
        $treasurer = $this->supplierTreasurer();

        $this->advanceToSupplier('300.00');

        $first  = $this->supplierRefund('50.00', $treasurer, null, null, 'supplier-form-1');
        $second = $this->supplierRefund('50.00', $treasurer, null, null, 'supplier-form-1');

        $this->assertSame($first, $second);
        $this->assertSame(1, $this->countRows('supplier_credit_refunds'));
        $this->assertSame(1, $this->accountingDb()->table('accounting_journals')->where('source_type', CreditRefunds::SUPPLIER_SOURCE)->countAllResults());

        $this->refundsFromSuppliers()->cancel($first, 'paid twice', $treasurer);

        $entries = $this->accountingDb()->table('accounting_audit_log')->where(['entity_type' => 'supplier_refund', 'entity_id' => $first])->orderBy('audit_id')->get()->getResult();

        $this->assertSame(['created', 'cancelled'], array_column($entries, 'action'));
        $this->assertSame((string) $treasurer, (string) $entries[0]->employee_id);
    }

    public function testRefund_NeverTakesTheCreditOfAnotherSupplier(): void
    {
        $other = $this->anotherSupplier();

        $this->advanceToSupplier('100.00');

        $this->assertSame(AccountingError::RefundExceedsCredit, $this->refusal(fn () => $this->refundsFromSuppliers()->refund($other, '10.00', date('Y-m-d'), 'Cash', null, '', '', $this->supplierTreasurer()))->error);
        $this->assertSame(AccountingError::RefundPartyMissing, $this->refusal(fn () => $this->refundsFromSuppliers()->refund(99999999, '10.00', date('Y-m-d'), 'Cash', null, '', '', $this->supplierTreasurer()))->error);
    }

    public function testRefund_RefusesTheExcessOfAPurchaseOnDaysBeforeThePaymentThatOverpaidIt(): void
    {
        $purchase = $this->purchaseDated('100.00', $this->daysAgo(10));

        $this->payPurchase($purchase, '150.00');

        $refusal = $this->refusal(fn () => $this->supplierRefund('50.00', null, null, $this->daysAgo(5)));

        $this->assertSame(AccountingError::RefundExceedsCredit, $refusal->error);
        $this->assertSame('0.00', $refusal->context['max']);
        $this->assertSame(0, $this->countRows('supplier_credit_refunds'));

        $this->supplierRefund('50.00');

        foreach ([$this->daysAgo(10), $this->daysAgo(5), $this->daysAgo(1), date('Y-m-d')] as $day) {
            $this->assertTrue($this->supplierPosition(null, $day)->agrees(), $day);
        }
    }

    public function testRefund_RefusesAKeyThatWasUsedForAnotherSupplierEvenForTheSameAmount(): void
    {
        $other     = $this->anotherSupplier();
        $treasurer = $this->supplierTreasurer();

        $this->advanceToSupplier('200.00', $treasurer);
        (new SupplierAdvances())->pay($other, '200.00', date('Y-m-d'), 'Cash', null, '', '', $treasurer);

        $first = $this->supplierRefund('50.00', $treasurer, null, null, 'supplier-form-3');

        $refusal = $this->refusal(fn () => $this->supplierRefund('50.00', $treasurer, null, null, 'supplier-form-3', $other));

        $this->assertSame(AccountingError::WriteFailed, $refusal->error);
        $this->assertSame($first, $refusal->context['refund_id']);
        $this->assertSame(1, $this->countRows('supplier_credit_refunds'));
        $this->assertSame('200.00', bcadd($this->supplierPosition($other)->credit(), '0', 2));
    }

    public function testRefund_RefusesAKeyThatWasUsedForAnotherAmountForASupplier(): void
    {
        $treasurer = $this->supplierTreasurer();

        $this->advanceToSupplier('200.00', $treasurer);
        $this->supplierRefund('50.00', $treasurer, null, null, 'supplier-form-4');

        $this->assertSame(AccountingError::WriteFailed, $this->refusal(fn () => $this->supplierRefund('60.00', $treasurer, null, null, 'supplier-form-4'))->error);
        $this->assertSame(1, $this->countRows('supplier_credit_refunds'));
    }

    public function testCancel_RefusesAnotherEmployeesRefundAsOneThatDoesNotExistForAnEmployeeWhoSeesTheirOwnRecords(): void
    {
        $this->advance();

        $refund = $this->refund('50.00');
        $second = $this->employeeWithGrants([AccountingGuard::RECEIVABLES_MANAGE, AccountingGuard::RECEIVABLES_REFUND, AccountingGuard::TREASURIES_MANAGE]);

        (new RoleService())->setRecordScope($second, 'own', null);

        $this->assertSame(AccountingError::RefundNotFound, $this->refusal(fn () => $this->refunds->cancel($refund, 'by a stranger', $second))->error);
        $this->assertNull($this->refunds->branchOf($refund, $second));
        $this->assertNotNull($this->refunds->branchOf($refund, $this->clerk));
    }

    #[DataProvider('customerCreditSources')]
    public function testRefund_TakesTheWholeOfTheCreditOfEachSourceAndNamesItInTheRefund(string $source): void
    {
        $id = $this->giveCustomerCredit($source);

        $this->assertSame('100.00', $this->freeCustomerCredit());

        $refundId = $this->refundCustomerCredit('100.00');
        $rows     = $this->accountingDb()->table('customer_credit_refund_sources')->where('refund_id', $refundId)->get()->getResult();

        $this->assertCount(1, $rows);
        $this->assertSame([$source, $id, '100.00'], [$rows[0]->source_type, (int) $rows[0]->source_id, bcadd($rows[0]->amount, '0', 2)]);
        $this->assertSame('0.00', $this->freeCustomerCredit());
        $this->assertRefundsTie();
        $this->assertTies();
    }

    #[DataProvider('customerCreditSources')]
    public function testRefund_RefusesASecondRefundOfACreditOfEachSourceThatWasPaidBackWhole(string $source): void
    {
        $this->giveCustomerCredit($source);
        $this->refundCustomerCredit('100.00');

        $refusal = $this->refusal(fn () => $this->refundCustomerCredit('0.01'));

        $this->assertSame(AccountingError::RefundExceedsCredit, $refusal->error);
        $this->assertSame('0.00', $refusal->context['max']);
        $this->assertSame(1, $this->countRows('customer_credit_refunds'));
        $this->assertRefundsTie();
        $this->assertTies();
    }

    #[DataProvider('customerCreditSources')]
    public function testRefund_NeverLetsAnInvoiceSpendTheCreditOfEachSourceThatWasPaidBack(string $source): void
    {
        $this->giveCustomerCredit($source);
        $this->refundCustomerCredit('100.00');

        $invoice = $this->invoiceDated('100.00', $this->daysAgo(1));

        $this->assertSame('100.00', bcadd($this->position()->invoice($invoice)->open(), '0', 2));
        $this->assertSame([], array_values(array_filter($this->applications(), static fn (string $application): bool => str_contains($application, '->' . $invoice . ':'))));
        $this->assertSame([], $this->accountingDb()->table('customer_credit_applications')->where('invoice_sale_id', $invoice)->get()->getResult());
        $this->assertSame('0.00', $this->freeCustomerCredit());
        $this->assertSame('100.00', $this->balance($this->customerAccount()));
        $this->assertTies();
    }

    #[DataProvider('customerCreditSources')]
    public function testRefund_TakesOnlyWhatIsLeftOfTheCreditOfEachSourceAfterAnInvoiceSpentPartOfIt(string $source): void
    {
        $this->giveCustomerCredit($source);

        $invoice = $this->invoiceDated('40.00', $this->daysAgo(1));

        $this->assertSame('0.00', bcadd($this->position()->invoice($invoice)->open(), '0', 2));
        $this->assertSame('60.00', $this->freeCustomerCredit());

        $refusal = $this->refusal(fn () => $this->refundCustomerCredit('60.01'));

        $this->assertSame(AccountingError::RefundExceedsCredit, $refusal->error);
        $this->assertSame('60.00', $refusal->context['max']);

        $this->refundCustomerCredit('60.00');

        $this->assertSame('0.00', $this->freeCustomerCredit());
        $this->assertSame('0.00', bcadd($this->position()->invoice($invoice)->open(), '0', 2), 'The refund left what was spent on the invoice where it was');
        $this->assertSame(AccountingError::RefundExceedsCredit, $this->refusal(fn () => $this->refundCustomerCredit('0.01'))->error);
        $this->assertRefundsTie();
        $this->assertTies();
    }

    #[DataProvider('customerCreditSources')]
    public function testRefund_LeavesTheNextInvoiceOnlyTheCreditOfEachSourceThatIsLeft(string $source): void
    {
        $this->giveCustomerCredit($source);
        $this->refundCustomerCredit('60.00');

        $invoice = $this->invoiceDated('100.00', $this->daysAgo(1));

        $this->assertSame('60.00', bcadd($this->position()->invoice($invoice)->open(), '0', 2));
        $this->assertSame('0.00', $this->freeCustomerCredit());
        $this->assertRefundsTie();
        $this->assertTies();
    }

    #[DataProvider('customerCreditSources')]
    public function testCancel_GivesTheCreditOfEachSourceBackToTheInvoiceThatWaitedAndNeverTwice(string $source): void
    {
        $this->giveCustomerCredit($source);

        $refundId = $this->refundCustomerCredit('100.00');
        $invoice  = $this->invoiceDated('100.00', $this->daysAgo(1));

        $this->assertSame('100.00', bcadd($this->position()->invoice($invoice)->open(), '0', 2));

        $this->cancelCustomerRefund($refundId);

        $this->assertSame('0.00', bcadd($this->position()->invoice($invoice)->open(), '0', 2), 'The credit that came back was spent on the invoice that waited for it');
        $this->assertSame('0.00', $this->freeCustomerCredit());
        $this->assertSame(AccountingError::RefundAlreadyCancelled, $this->refusal(fn () => $this->cancelCustomerRefund($refundId))->error);
        $this->assertSame(AccountingError::RefundExceedsCredit, $this->refusal(fn () => $this->refundCustomerCredit('0.01'))->error);
        $this->assertRefundsTie();
        $this->assertTies();
    }

    #[DataProvider('customerCreditSources')]
    public function testCancel_LetsTheCreditOfEachSourceBePaidBackAgainOnceItsRefundIsCancelled(string $source): void
    {
        $this->giveCustomerCredit($source);

        $first = $this->refundCustomerCredit('100.00');

        $this->cancelCustomerRefund($first);

        $this->assertSame('100.00', $this->freeCustomerCredit());

        $second = $this->refundCustomerCredit('100.00');

        $this->assertNotSame($first, $second);
        $this->assertSame('0.00', $this->freeCustomerCredit());
        $this->assertSame(2, $this->countRows('customer_credit_refunds'));
        $this->assertRefundsTie();
        $this->assertTies();
    }

    public function testRefund_TakesTheCreditOfAllFiveCustomerSourcesOldestFirstAndNamesEachOne(): void
    {
        $ids = [];

        foreach ([CreditSource::CUSTOMER_ADVANCE, CreditSource::OPENING, CreditSource::CHEQUE, CreditSource::CREDIT_NOTE, CreditSource::INVOICE_EXCESS] as $index => $source) {
            $ids[$source] = $this->giveCustomerCredit($source, '10.00');
        }

        $this->assertSame('50.00', $this->freeCustomerCredit());

        $refundId = $this->refundCustomerCredit('35.00');
        $taken    = [];

        foreach ($this->accountingDb()->table('customer_credit_refund_sources')->where('refund_id', $refundId)->get()->getResult() as $row) {
            $taken[$row->source_type . ':' . $row->source_id] = bcadd($row->amount, '0', 2);
        }

        $this->assertSame('35.00', bcadd(array_reduce($taken, static fn (string $sum, string $amount): string => bcadd($sum, $amount, 2), '0'), '0', 2));
        $this->assertCount(4, $taken, 'Three credits of 10 and half of a fourth, oldest first, ties by id');
        $this->assertSame('15.00', $this->freeCustomerCredit());
        $this->assertRefundsTie();
        $this->assertTies();

        $this->cancelCustomerRefund($refundId);

        $this->assertSame('50.00', $this->freeCustomerCredit());
        $this->assertRefundsTie();
        $this->assertTies();
    }

    #[DataProvider('supplierCreditSources')]
    public function testRefund_TakesTheWholeOfTheCreditOfEachSupplierSourceAndNamesItInTheRefund(string $source): void
    {
        $id = $this->giveSupplierCredit($source);

        $this->assertSame('100.00', $this->freeSupplierCredit());

        $refundId = $this->supplierRefund('100.00');
        $rows     = $this->accountingDb()->table('supplier_credit_refund_sources')->where('refund_id', $refundId)->get()->getResult();

        $this->assertCount(1, $rows);
        $this->assertSame([$source, $id, '100.00'], [$rows[0]->source_type, (int) $rows[0]->source_id, bcadd($rows[0]->amount, '0', 2)]);
        $this->assertSame('0.00', $this->freeSupplierCredit());
        $this->assertRefundsTie(CreditRefunds::SUPPLIER);
        $this->assertSupplierTies();
    }

    #[DataProvider('supplierCreditSources')]
    public function testRefund_RefusesASecondRefundOfACreditOfEachSupplierSourceThatWasPaidBackWhole(string $source): void
    {
        $this->giveSupplierCredit($source);
        $this->supplierRefund('100.00');

        $refusal = $this->refusal(fn () => $this->supplierRefund('0.01'));

        $this->assertSame(AccountingError::RefundExceedsCredit, $refusal->error);
        $this->assertSame('0.00', $refusal->context['max']);
        $this->assertSame(1, $this->countRows('supplier_credit_refunds'));
        $this->assertRefundsTie(CreditRefunds::SUPPLIER);
        $this->assertSupplierTies();
    }

    #[DataProvider('supplierCreditSources')]
    public function testRefund_NeverLetsAPurchaseSpendTheCreditOfEachSupplierSourceThatWasPaidBack(string $source): void
    {
        $this->giveSupplierCredit($source);
        $this->supplierRefund('100.00');

        $purchase = $this->purchaseDated('100.00', $this->daysAgo(1));

        $this->assertSame('100.00', bcadd($this->supplierPosition()->purchase($purchase)->open(), '0', 2));
        $this->assertSame([], $this->supplierApplications());
        $this->assertSame('0.00', $this->freeSupplierCredit());
        $this->assertSupplierTies();
    }

    #[DataProvider('supplierCreditSources')]
    public function testRefund_TakesOnlyWhatIsLeftOfTheCreditOfEachSupplierSourceAfterAPurchaseSpentPartOfIt(string $source): void
    {
        $this->giveSupplierCredit($source);

        $purchase = $this->purchaseDated('40.00', $this->daysAgo(1));

        $this->assertSame('0.00', bcadd($this->supplierPosition()->purchase($purchase)->open(), '0', 2));
        $this->assertSame('60.00', $this->freeSupplierCredit());

        $refusal = $this->refusal(fn () => $this->supplierRefund('60.01'));

        $this->assertSame(AccountingError::RefundExceedsCredit, $refusal->error);
        $this->assertSame('60.00', $refusal->context['max']);

        $this->supplierRefund('60.00');

        $this->assertSame('0.00', $this->freeSupplierCredit());
        $this->assertSame('0.00', bcadd($this->supplierPosition()->purchase($purchase)->open(), '0', 2));
        $this->assertRefundsTie(CreditRefunds::SUPPLIER);
        $this->assertSupplierTies();
    }

    #[DataProvider('supplierCreditSources')]
    public function testCancel_GivesTheCreditOfEachSupplierSourceBackToThePurchaseThatWaited(string $source): void
    {
        $this->giveSupplierCredit($source);

        $refundId = $this->supplierRefund('100.00');
        $purchase = $this->purchaseDated('100.00', $this->daysAgo(1));

        $this->assertSame('100.00', bcadd($this->supplierPosition()->purchase($purchase)->open(), '0', 2));

        $this->refundsFromSuppliers()->cancel($refundId, 'paid twice', $this->supplierTreasurer());

        $this->assertSame('0.00', bcadd($this->supplierPosition()->purchase($purchase)->open(), '0', 2));
        $this->assertSame('0.00', $this->freeSupplierCredit());
        $this->assertRefundsTie(CreditRefunds::SUPPLIER);
        $this->assertSupplierTies();
    }

    /**
     * @return list<string>
     */
    private function refundSession(string $party, int $partyId, string $amount, int $employeeId, string $key = ''): array
    {
        return ['refund', $party, (string) $partyId, $amount, date('Y-m-d'), (string) $employeeId, $key];
    }

    /**
     * @return array<string, array{string, string}> The sources the race has a session for and what takes each one back.
     */
    public static function raceableCustomerSources(): array
    {
        return [
            'an advance payment' => [CreditSource::CUSTOMER_ADVANCE, 'cancelCustomerAdvance'],
            'an opening credit'  => [CreditSource::OPENING, 'cancelOpening'],
            'a received cheque'  => [CreditSource::CHEQUE, 'rejectCheque'],
        ];
    }

    public function testRefund_LetsExactlyOneOfTwoSessionsTakeACreditOnlyOneFitsIn(): void
    {
        $this->giveCustomerCredit(CreditSource::CUSTOMER_ADVANCE);

        $session = $this->refundSession(CreditRefunds::CUSTOMER, $this->customerId, '60.00', $this->creditClerk());
        $answers = $this->raceSessions('creditRace', [$session, $session]);

        sort($answers);

        $this->assertSame(['done', AccountingError::RefundExceedsCredit->value], $answers);
        $this->assertSame(1, $this->countRows('customer_credit_refunds'));
        $this->assertSame('40.00', $this->freeCustomerCredit());
        $this->assertSame('-40.00', $this->balance($this->customerAccount()));
        $this->assertRefundsTie();
        $this->assertTies();
    }

    public function testRefund_LetsExactlyOneOfTwoSessionsTakeACreditOnlyOneFitsInForASupplier(): void
    {
        $treasurer = $this->supplierTreasurer();

        $this->giveSupplierCredit(CreditSource::ADVANCE);

        $session = $this->refundSession(CreditRefunds::SUPPLIER, $this->supplierId, '60.00', $treasurer);
        $answers = $this->raceSessions('creditRace', [$session, $session]);

        sort($answers);

        $this->assertSame(['done', AccountingError::RefundExceedsCredit->value], $answers);
        $this->assertSame(1, $this->countRows('supplier_credit_refunds'));
        $this->assertSame('40.00', $this->freeSupplierCredit());
        $this->assertRefundsTie(CreditRefunds::SUPPLIER);
        $this->assertSupplierTies();
    }

    public function testRefund_LetsTwoOfFourSessionsTakeWhatTwoOfThemFitInAndRefusesTheOthers(): void
    {
        $this->giveCustomerCredit(CreditSource::CUSTOMER_ADVANCE);
        $this->giveCustomerCredit(CreditSource::CHEQUE, '50.00');

        $session = $this->refundSession(CreditRefunds::CUSTOMER, $this->customerId, '70.00', $this->creditClerk());
        $answers = $this->raceSessions('creditRace', [$session, $session, $session, $session]);

        sort($answers);

        $this->assertSame(['done', 'done', AccountingError::RefundExceedsCredit->value, AccountingError::RefundExceedsCredit->value], $answers, 'A credit of 150 pays two refunds of 70 and no third');
        $this->assertSame(2, $this->countRows('customer_credit_refunds'));
        $this->assertSame('10.00', $this->freeCustomerCredit());
        $this->assertRefundsTie();
        $this->assertTies();
    }

    public function testRefund_LetsTwoSessionsBothRefundWhenBothFit(): void
    {
        $this->giveCustomerCredit(CreditSource::CUSTOMER_ADVANCE);

        $session = $this->refundSession(CreditRefunds::CUSTOMER, $this->customerId, '40.00', $this->creditClerk());

        $this->assertSame(['done', 'done'], $this->raceSessions('creditRace', [$session, $session]));
        $this->assertSame(2, $this->countRows('customer_credit_refunds'));
        $this->assertSame('20.00', $this->freeCustomerCredit());
        $this->assertRefundsTie();
        $this->assertTies();
    }

    public function testRefund_SavesOneRefundWhenTwoSessionsSendTheSameFormAtTheSameMoment(): void
    {
        $this->giveCustomerCredit(CreditSource::CUSTOMER_ADVANCE);

        $session = $this->refundSession(CreditRefunds::CUSTOMER, $this->customerId, '60.00', $this->creditClerk(), 'form-race-1');

        $this->assertSame(['done', 'done'], $this->raceSessions('creditRace', [$session, $session]));
        $this->assertSame(1, $this->countRows('customer_credit_refunds'));
        $this->assertSame(1, $this->accountingDb()->table('accounting_journals')->where(['source_type' => CreditRefunds::CUSTOMER_SOURCE, 'status' => 'posted'])->countAllResults());
        $this->assertSame('40.00', $this->freeCustomerCredit());
        $this->assertRefundsTie();
    }

    #[DataProvider('raceableCustomerSources')]
    public function testRefund_LeavesTheRefundOrTheChangeOfTheSourceStandingAndNeverBothWhenTheyRace(string $source, string $takeBack): void
    {
        $id    = $this->giveCustomerCredit($source);
        $actor = $takeBack === 'rejectCheque' ? $this->chequeClerk : $this->creditClerk();

        $answers = $this->raceSessions('creditRace', [
            $this->refundSession(CreditRefunds::CUSTOMER, $this->customerId, '60.00', $this->creditClerk()),
            [$takeBack, (string) $id, (string) $actor],
        ]);

        sort($answers);

        if ($this->countRows('customer_credit_refunds') === 1) {
            $this->assertSame([AccountingError::CreditRefunded->value, 'done'], $answers, 'The refund went first, so the source could not be taken back');
            $this->assertSame('40.00', $this->freeCustomerCredit());
        } else {
            $this->assertSame(['done', AccountingError::RefundExceedsCredit->value], $answers, 'The source was taken back first, so there was nothing to refund');
            $this->assertSame('0.00', $this->freeCustomerCredit());
        }

        $this->assertRefundsTie();
        $this->assertTies();
    }

    public function testRefund_LeavesTheRefundOrTheCancelledAdvanceStandingAndNeverBothForASupplier(): void
    {
        $treasurer = $this->supplierTreasurer();
        $advance   = $this->giveSupplierCredit(CreditSource::ADVANCE);

        $answers = $this->raceSessions('creditRace', [
            $this->refundSession(CreditRefunds::SUPPLIER, $this->supplierId, '60.00', $treasurer),
            ['cancelSupplierAdvance', (string) $advance, (string) $treasurer],
        ]);

        sort($answers);

        if ($this->countRows('supplier_credit_refunds') === 1) {
            $this->assertSame([AccountingError::CreditRefunded->value, 'done'], $answers);
            $this->assertSame('40.00', $this->freeSupplierCredit());
        } else {
            $this->assertSame(['done', AccountingError::RefundExceedsCredit->value], $answers);
            $this->assertSame('0.00', $this->freeSupplierCredit());
        }

        $this->assertRefundsTie(CreditRefunds::SUPPLIER);
        $this->assertSupplierTies();
    }

    public function testCancel_LetsExactlyOneOfTwoSessionsCancelTheSameRefundAndGivesTheCreditBackOnce(): void
    {
        $this->giveCustomerCredit(CreditSource::CUSTOMER_ADVANCE);

        $refundId = $this->refundCustomerCredit('60.00');
        $session  = ['cancelRefund', CreditRefunds::CUSTOMER, (string) $refundId, (string) $this->creditClerk()];
        $answers  = $this->raceSessions('creditRace', [$session, $session]);

        sort($answers);

        $this->assertSame(['done', AccountingError::RefundAlreadyCancelled->value], $answers);
        $this->assertSame('100.00', $this->freeCustomerCredit(), 'The credit came back once');
        $this->assertSame(1, $this->accountingDb()->table('accounting_journals')->where('source_type', 'reversal')->countAllResults(), 'The journal of the refund was reversed once');
        $this->assertRefundsTie();
        $this->assertTies();
    }

    public function testCancel_LetsExactlyOneOfTwoSessionsCancelTheSameRefundOfASupplier(): void
    {
        $treasurer = $this->supplierTreasurer();

        $this->giveSupplierCredit(CreditSource::ADVANCE);

        $refundId = $this->supplierRefund('60.00', $treasurer);
        $session  = ['cancelRefund', CreditRefunds::SUPPLIER, (string) $refundId, (string) $treasurer];
        $answers  = $this->raceSessions('creditRace', [$session, $session]);

        sort($answers);

        $this->assertSame(['done', AccountingError::RefundAlreadyCancelled->value], $answers);
        $this->assertSame('100.00', $this->freeSupplierCredit());
        $this->assertRefundsTie(CreditRefunds::SUPPLIER);
        $this->assertSupplierTies();
    }
}
