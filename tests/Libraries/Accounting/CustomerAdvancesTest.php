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
use App\Libraries\Numbering\DocumentNumbering;
use App\Libraries\Numbering\DocumentType;
use App\Libraries\Organization\BranchError;
use App\Libraries\Organization\BranchException;
use App\Libraries\EInvoicing\EInvoiceException;
use App\Libraries\EInvoicing\EInvoiceKind;
use App\Libraries\EInvoicing\EInvoiceService;
use App\Libraries\Tax\AdvanceVat;
use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\DatabaseTestTrait;
use Config\Database;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Support\AccountingFixtureTrait;
use Tests\Support\EInvoiceSaleFixtureTrait;
use Tests\Support\EmployeeFixtureTrait;
use Tests\Support\ItemFixtureTrait;
use Tests\Support\ParityCaseTrait;
use Tests\Support\SaleAccountingFixtureTrait;
use Tests\Support\SaudiSaleFixtureTrait;
use App\Libraries\Roles\RoleService;
use App\Libraries\Accounting\OperationsHold;
use App\Libraries\Accounting\PostingError;

/**
 * Money a customer pays with no invoice behind it: one journal that debits the treasury and credits
 * the account of the customer, a credit that is spent on the invoices of the customer, the oldest
 * first, and an advance that is cancelled by reversing its journal and never edited or deleted.
 */
class CustomerAdvancesTest extends CIUnitTestCase
{
    use DatabaseTestTrait;
    use AccountingFixtureTrait;
    use EmployeeFixtureTrait;
    use ItemFixtureTrait;
    use ParityCaseTrait;
    use SaleAccountingFixtureTrait;
    use EInvoiceSaleFixtureTrait;
    use SaudiSaleFixtureTrait;

    protected $migrate     = true;
    protected $migrateOnce = true;
    protected $refresh     = false;
    protected $namespace   = null;

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

        $this->advances = new CustomerAdvances();
        $this->clerk    = $this->employeeWithGrants([AccountingGuard::RECEIVABLES_MANAGE, AccountingGuard::TREASURIES_MANAGE]);
    }

    protected function tearDown(): void
    {
        $this->leaveTheSaudiRules();

        $db = $this->accountingDb();
        $db->table('document_numbers')->truncate();
        $db->table('document_sequences')->truncate();
        $db->table('accounting_audit_log')->where('entity_type', 'document_numbering')->delete();

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

    private function receive(string $amount = '100.00', ?string $date = null, ?int $treasuryId = null, ?int $employeeId = null, ?string $key = null, ?int $customerId = null): int
    {
        return $this->advances->receive($customerId ?? $this->customerId, $amount, $date ?? date('Y-m-d'), 'Cash', $treasuryId, '', '', $employeeId ?? $this->clerk, $key);
    }

    private function numberOfAdvance(int $advanceId): ?string
    {
        return (new DocumentNumbering())->numbers(DocumentType::CustomerPayment, 'customer_advances', [$advanceId])[$advanceId] ?? null;
    }

    public function testReceive_DebitsTheTreasuryAndCreditsTheAccountOfTheCustomerWithoutTouchingAnyOtherAccount(): void
    {
        $advanceId = $this->advances->receive($this->customerId, '300.00', date('Y-m-d'), 'Cash', null, 'RCPT1', 'on account', $this->clerk);

        $this->assertSame([$this->accountId('cash') . '=300.00/0.00', $this->customerAccount() . '=0.00/300.00'], $this->linesOf(CustomerAdvances::SOURCE, $advanceId));
        $this->assertSame('-300.00', $this->balance($this->customerAccount()));

        $row = $this->accountingDb()->table('customer_advances')->where('advance_id', $advanceId)->get()->getRow();

        $this->assertSame(['completed', 'RCPT1', 'on account'], [$row->status, $row->reference_code, $row->note]);
        $this->assertSame('300.0000', $this->position()->credit());
        $this->assertSame(CreditSource::CUSTOMER_ADVANCE, $this->position()->credits[0]->type);
        $this->assertTies();
    }

    public function testReceive_DiffersFromTheReferenceSystemByKeepingTheCreditOnTheAccountOfTheCustomerAndSpendingItByTheOrderOfCredit(): void
    {
        $case    = $this->referenceCase('T-10', 'receivable', 'advance-payment-is-credit-on-the-account-of-the-customer', 'deliberate_difference');
        $invoice = $this->invoiceOf('60.00');

        $this->receive($case['inputs']['advance']);

        $journal = $this->accountingDb()->table('accounting_journals')->where(['source_type' => CustomerAdvances::SOURCE, 'status' => 'posted'])->get()->getRow();
        $touched = array_column($this->accountingDb()->table('accounting_journal_lines')->select('account_id')->where('journal_id', $journal->journal_id)->get()->getResultArray(), 'account_id');

        $this->assertSameAsFixture($case['expected'], [
            'customer_account_touched'         => in_array((string) $this->customerAccount(), $touched, true),
            'advance_liability_credited'       => $this->accountingDb()->table('accounting_accounts')->like('code', '218', 'after')->countAllResults() > 0,
            'applied_to_open_invoices_at_once' => bcadd($this->position()->invoice($invoice)->applied, '0', 2) === '60.00',
        ], 'advance payment');
        $this->assertTies();
    }

    public function testReceive_ChargesVatOnAnAdvanceReceivedUnderTheSaudiRules(): void
    {
        $this->underTheSaudiRules();

        $advanceId = $this->receive('115.00');
        $vat       = (new AdvanceVat())->of($advanceId);

        $this->assertSame(['115.00', '100.00', '15.00', '15.000'], [$vat->gross, $vat->taxable, $vat->vat, $vat->percent]);
        $this->assertSame([$this->accountId('tax_advances') . '=15.00/0.00', $this->accountId('tax_output') . '=0.00/15.00'], $this->linesOf(AdvanceVat::SOURCE, $advanceId));
        $this->assertContains($this->customerAccount() . '=0.00/115.00', $this->linesOf(CustomerAdvances::SOURCE, $advanceId), 'the customer keeps the whole advance as credit');

        $prepayment = (new EInvoiceService())->documentOf(EInvoiceService::SOURCE_ADVANCE, $advanceId);

        $this->assertNotNull($prepayment, 'the prepayment invoice is issued with the receipt');
        $this->assertSame(EInvoiceKind::Prepayment, $prepayment->kind);
        $this->assertSame(['100.00', '15.00', '115.00'], [$prepayment->taxExclusive, $prepayment->taxAmount, $prepayment->taxInclusive]);
    }

    public function testReceive_IssuesThePrepaymentInvoiceOfAnAdvanceThatDoesNotSplitExactlyWithItsRounding(): void
    {
        $this->underTheSaudiRules();

        $advanceId  = $this->receive('100.01');
        $vat        = (new AdvanceVat())->of($advanceId);
        $prepayment = (new EInvoiceService())->documentOf(EInvoiceService::SOURCE_ADVANCE, $advanceId);

        $this->assertSame(['100.01', '86.97', '13.05'], [$vat->gross, $vat->taxable, $vat->vat], 'the VAT is the taxable amount times the rate (BR-KSA-79)');
        $this->assertSame('100.02', $prepayment->taxInclusive);
        $this->assertStringContainsString('<cbc:PayableRoundingAmount currencyID="SAR">-0.01</cbc:PayableRoundingAmount>', $prepayment->xml);
        $this->assertStringContainsString('<cbc:PayableAmount currencyID="SAR">100.01</cbc:PayableAmount>', $prepayment->xml);
    }

    public function testReceive_LetsAnInvoiceThatTakesAnAdvanceThatDoesNotSplitExactlyAskWhatIsStillOwed(): void
    {
        $this->underTheSaudiRules();
        $this->receive('11.54');

        $xml = (new EInvoiceService())->documentOf(EInvoiceService::SOURCE_SALE, $this->saudiSaleOf('100.00'))->xml;

        $this->assertStringContainsString('<cbc:PrepaidAmount currencyID="SAR">11.53</cbc:PrepaidAmount>', $xml, 'the taxable amount and VAT of the advance (BR-KSA-80)');
        $this->assertStringContainsString('<cbc:PayableAmount currencyID="SAR">103.46</cbc:PayableAmount>', $xml, '115.00 less the 11.54 the customer paid ahead');
    }

    public function testReceive_IssuesThePrepaymentInvoiceOfAnAdvanceTooSmallToCarryVat(): void
    {
        $this->underTheSaudiRules();

        $prepayment = (new EInvoiceService())->documentOf(EInvoiceService::SOURCE_ADVANCE, $this->receive('0.03'));

        $this->assertSame(['0.03', '0.00', '0.03'], [$prepayment->taxExclusive, $prepayment->taxAmount, $prepayment->taxInclusive]);
    }

    public function testReceive_KeepsWhatAnIssuedInvoiceTakesOfTheAdvanceWhenItsCreditWouldBeReleased(): void
    {
        $this->underTheSaudiRules();

        $advanceId   = $this->receive('115.00');
        $this->saudiSaleOf('200.00');
        $application = $this->accountingDb()->table('customer_credit_applications')->where(['source_type' => CreditSource::CUSTOMER_ADVANCE, 'source_id' => $advanceId])->where('released_at IS NULL', null, false)->get()->getRow();

        try {
            (new AdvanceVat())->released((int) $application->application_id, $this->clerk);
            $this->fail('A prepayment the issued invoice states was given back.');
        } catch (AccountingException $refusal) {
            $this->assertSame(AccountingError::AdvanceTakenByIssuedInvoice, $refusal->error);
        }

        $this->assertSame(0, (int) $this->accountingDb()->table('customer_advance_vat_uses')->where('application_id', $application->application_id)->get()->getRow()->released);
    }

    public function testReceive_UndoesTheAdvanceWhenItsPrepaymentInvoiceCannotBeIssued(): void
    {
        $this->underTheSaudiRules();
        $this->configureEInvoicing(['einvoice_seller_street' => '']);

        $before = $this->accountingDb()->table('customer_advances')->countAllResults();

        $this->expectException(EInvoiceException::class);

        try {
            $this->receive('115.00');
        } finally {
            $this->assertSame($before, $this->accountingDb()->table('customer_advances')->countAllResults(), 'no advance without its document');
        }
    }

    public function testReceive_ChargesNoVatOnWhatAnOpenInvoiceTakesAtOnce(): void
    {
        $this->invoiceOf('46.00');
        $this->underTheSaudiRules();

        $vat = (new AdvanceVat())->of($this->receive('115.00'));

        $this->assertSame(['69.00', '60.00', '9.00'], [$vat->gross, $vat->taxable, $vat->vat]);
    }

    public function testReceive_GivesBackTheVatOfTheAdvanceWhenAnInvoiceTakesItLater(): void
    {
        $this->underTheSaudiRules();

        $advanceId = $this->receive('115.00');
        $invoiceId = $this->saudiSaleOf('200.00');
        $uses      = (new AdvanceVat())->usesOf($invoiceId);

        $this->assertCount(1, $uses);
        $this->assertSame(['115.00', '100.00', '15.00'], [$uses[0]->gross, $uses[0]->taxable, $uses[0]->vat]);

        $application = $this->accountingDb()->table('customer_credit_applications')->where(['source_type' => CreditSource::CUSTOMER_ADVANCE, 'source_id' => $advanceId])->where('released_at IS NULL', null, false)->get()->getRow();

        $this->assertSame([$this->accountId('tax_output') . '=15.00/0.00', $this->accountId('tax_advances') . '=0.00/15.00'], $this->linesOf(AdvanceVat::SOURCE_USE, (int) $application->application_id));
        $this->assertStringContainsString('<cbc:PrepaidAmount currencyID="SAR">115.00</cbc:PrepaidAmount>', (new EInvoiceService())->documentOf(EInvoiceService::SOURCE_SALE, $invoiceId)->xml, 'the invoice issued with the sale takes the advance off what is due');
    }

    public function testCancel_GivesBackTheVatOfTheAdvanceByACreditNoteOfItsPrepaymentInvoice(): void
    {
        $this->underTheSaudiRules();

        $advanceId = $this->receive('115.00');

        $this->advances->cancel($advanceId, 'Paid by mistake', $this->clerk);

        $documents  = new EInvoiceService();
        $prepayment = $documents->documentOf(EInvoiceService::SOURCE_ADVANCE, $advanceId);
        $return     = $this->accountingDb()->table('customer_advance_vat_returns')->where('advance_id', $advanceId)->get()->getRow();
        $note       = $documents->documentOf(EInvoiceService::SOURCE_RETURN, (int) $return->return_id);

        $this->assertSame('1', (string) (new AdvanceVat())->of($advanceId)->cancelled);
        $this->assertSame(EInvoiceKind::CreditNote, $note->kind);
        $this->assertSame(['100.00', '15.00', '115.00'], [$note->taxExclusive, $note->taxAmount, $note->taxInclusive]);
        $this->assertSame($prepayment->documentId, $note->originalDocumentId);
        $this->assertStringContainsString('<cbc:ID>' . $prepayment->number . '</cbc:ID>', $note->xml);
        $this->assertSame([$this->accountId('tax_output') . '=15.00/0.00', $this->accountId('tax_advances') . '=0.00/15.00'], $this->linesOf(AdvanceVat::SOURCE_RETURN, (int) $return->return_id));
    }

    public function testCancel_RefusesAnAdvanceThatAnIssuedInvoiceTakes(): void
    {
        $this->underTheSaudiRules();

        $advanceId = $this->receive('115.00');

        $this->saudiSaleOf('40.00');

        try {
            $this->advances->cancel($advanceId, 'Paid by mistake', $this->clerk);
            $this->fail('The advance was cancelled.');
        } catch (AccountingException $refusal) {
            $this->assertSame(AccountingError::AdvanceTakenByIssuedInvoice, $refusal->error);
        }

        $this->assertSame('0', (string) (new AdvanceVat())->of($advanceId)->cancelled);
    }

    public function testReceive_ChargesNoVatOutsideTheSaudiRules(): void
    {
        $this->assertNull((new AdvanceVat())->of($this->receive('115.00')));
    }

    public function testReceive_DebitsTheTreasuryItWasPaidIntoAndTakesItsBranch(): void
    {
        $treasuries = new TreasuryService();
        $drawer     = $treasuries->create(['name' => 'Receipts drawer', 'kind' => 'cash'], $this->clerk);

        $advanceId = $this->receive('120.00', null, $drawer);

        $this->assertSame([$treasuries->accountOf($drawer) . '=120.00/0.00', $this->customerAccount() . '=0.00/120.00'], $this->linesOf(CustomerAdvances::SOURCE, $advanceId));
        $this->assertSame('120.0000', $treasuries->position($drawer)->balance);
        $this->assertSame($drawer, (int) $this->accountingDb()->table('customer_advances')->where('advance_id', $advanceId)->get()->getRow()->treasury_id);
        $this->assertSame(1, (int) $this->accountingDb()->table('customer_advances')->where('advance_id', $advanceId)->get()->getRow()->branch_id);
    }

    public function testReceive_RefusesATreasuryTheEmployeeMayNotDepositInto(): void
    {
        $treasuries = new TreasuryService();
        $other      = $this->employeeWithGrants([]);
        $drawer     = $treasuries->create(['name' => 'Locked drawer', 'kind' => 'cash', 'deposit_scope' => 'staff', 'deposit_members' => [$other]], $this->clerk);

        $refusal = $this->refusal(fn () => $this->receive('120.00', null, $drawer));

        $this->assertSame(AccountingError::TreasuryDepositDenied, $refusal->error);
        $this->assertSame('Locked drawer', $refusal->context['name']);
        $this->assertSame(0, $this->countRows('customer_advances'));
        $this->assertSame('0.0000', $treasuries->position($drawer)->balance);
        $this->assertSame('0.00', $this->balance($this->customerAccount()));
    }

    public function testReceive_AcceptsATreasuryTheEmployeeIsAllowedToDepositInto(): void
    {
        $treasuries = new TreasuryService();
        $drawer     = $treasuries->create(['name' => 'Own drawer', 'kind' => 'cash', 'deposit_scope' => 'staff', 'deposit_members' => [$this->clerk]], $this->clerk);

        $this->receive('120.00', null, $drawer);

        $this->assertSame('120.0000', $treasuries->position($drawer)->balance);
    }

    public function testReceive_DoesNotLetTheWithdrawScopeStandInForTheDepositScope(): void
    {
        $treasuries = new TreasuryService();
        $other      = $this->employeeWithGrants([]);
        $drawer     = $treasuries->create(['name' => 'Pay-out only drawer', 'kind' => 'cash', 'withdraw_scope' => 'staff', 'withdraw_members' => [$other]], $this->clerk);

        $this->receive('120.00', null, $drawer);

        $this->assertSame('120.0000', $treasuries->position($drawer)->balance);
    }

    public function testReceive_AppliesTheScopeOfTheMainTreasuryWhenNoTreasuryIsNamed(): void
    {
        $treasuries = new TreasuryService();
        $other      = $this->employeeWithGrants([]);
        $main       = $treasuries->ensureMain();

        $treasuries->update($main, ['name' => 'Main', 'kind' => 'cash', 'deposit_scope' => 'staff', 'deposit_members' => [$other]], $this->clerk);

        $refusal = $this->refusal(fn () => $this->receive('120.00'));

        $this->assertSame(AccountingError::TreasuryDepositDenied, $refusal->error);
        $this->assertSame(0, $this->countRows('customer_advances'));
    }

    public function testReceive_AppliesTheScopeOfTheTreasuryTheWayOfPaymentIsRoutedToWhenNoTreasuryIsNamed(): void
    {
        $treasuries = new TreasuryService();
        $other      = $this->employeeWithGrants([]);
        $bank       = $treasuries->create(['name' => 'Routed bank', 'kind' => 'bank', 'bank_name' => 'Gulf Bank', 'account_number' => '1234567890', 'deposit_scope' => 'staff', 'deposit_members' => [$other]], $this->clerk);

        $this->accountingDb()->table('accounting_routing')->where('routing_key', 'payment.cash')->update(['account_id' => $treasuries->accountOf($bank)]);

        $refusal = $this->refusal(fn () => $this->receive('120.00'));

        $this->assertSame(AccountingError::TreasuryDepositDenied, $refusal->error);
        $this->assertSame('Routed bank', $refusal->context['name']);
        $this->assertSame(0, $this->countRows('customer_advances'));
    }

    public function testReceive_LetsTheBooksReceiveWhateverTheScopeOfTheTreasury(): void
    {
        $treasuries = new TreasuryService();
        $other      = $this->employeeWithGrants([]);
        $drawer     = $treasuries->create(['name' => 'Locked drawer', 'kind' => 'cash', 'deposit_scope' => 'staff', 'deposit_members' => [$other]], $this->clerk);

        $this->advances->receive($this->customerId, '120.00', date('Y-m-d'), 'Cash', $drawer);

        $this->assertSame('120.0000', $treasuries->position($drawer)->balance);
    }

    public function testCancel_RefusesAnEmployeeWhoMayNotWithdrawFromTheTreasuryTheAdvanceWentInto(): void
    {
        $treasuries = new TreasuryService();
        $other      = $this->employeeWithGrants([]);
        $drawer     = $treasuries->create(['name' => 'Receipts drawer', 'kind' => 'cash', 'withdraw_scope' => 'staff', 'withdraw_members' => [$other]], $this->clerk);
        $advanceId  = $this->receive('120.00', null, $drawer);

        $refusal = $this->refusal(fn () => $this->advances->cancel($advanceId, 'paid twice', $this->clerk));

        $this->assertSame(AccountingError::TreasuryWithdrawDenied, $refusal->error);
        $this->assertSame('120.0000', $treasuries->position($drawer)->balance);
        $this->assertSame('completed', $this->accountingDb()->table('customer_advances')->where('advance_id', $advanceId)->get()->getRow()->status);
        $this->assertSame('120.0000', $this->position()->credit());
    }

    public function testCancel_AcceptsAnEmployeeWhoMayWithdrawFromTheTreasuryAndTheBooksAlways(): void
    {
        $treasuries = new TreasuryService();
        $other      = $this->employeeWithGrants([]);
        $drawer     = $treasuries->create(['name' => 'Receipts drawer', 'kind' => 'cash', 'withdraw_scope' => 'staff', 'withdraw_members' => [$this->clerk]], $this->clerk);
        $first      = $this->receive('120.00', null, $drawer);
        $second     = $this->receive('80.00', null, $drawer);

        $this->advances->cancel($first, 'paid twice', $this->clerk);

        $treasuries->update($drawer, ['name' => 'Receipts drawer', 'kind' => 'cash', 'withdraw_scope' => 'staff', 'withdraw_members' => [$other]], $this->clerk);
        $this->advances->cancel($second, 'paid twice');

        $this->assertSame('0.0000', $treasuries->position($drawer)->balance);
    }

    public function testReceive_IsSpentOnTheOpenInvoicesOfTheCustomerTheOldestFirst(): void
    {
        $older = $this->invoiceDated('100.00', $this->daysAgo(10));
        $newer = $this->invoiceDated('100.00', $this->daysAgo(2));

        $advanceId = $this->receive('130.00');

        $this->assertSame(['customer_advance:' . $advanceId . '->' . $older . ':100.00', 'customer_advance:' . $advanceId . '->' . $newer . ':30.00'], $this->applications());
        $this->assertSame('70.00', bcadd($this->position()->invoice($newer)->open(), '0', 2));
        $this->assertSame('0.00', bcadd($this->position()->credit(), '0', 2));
        $this->assertTies();
    }

    public function testReceive_WaitsForTheInvoiceThatComesAfterItAndSpendsItThen(): void
    {
        $advanceId = $this->receive('100.00');

        $this->assertSame([], $this->applications());

        $invoice = $this->invoiceOf('60.00');

        $this->assertSame(['customer_advance:' . $advanceId . '->' . $invoice . ':60.00'], $this->applications());
        $this->assertSame('40.00', bcadd($this->position()->credit(), '0', 2));
        $this->assertSame('-40.00', $this->balance($this->customerAccount()));
        $this->assertTies();
    }

    public function testReceive_WritesNoJournalForTheApplicationToAnInvoice(): void
    {
        $this->invoiceOf('60.00');

        $before = $this->countJournals();

        $this->receive('100.00');

        $this->assertSame($before + 1, $this->countJournals());
    }

    public function testCancel_ReversesTheJournalAndGivesBackWhatWasSpentOnInvoices(): void
    {
        $invoice   = $this->invoiceOf('60.00');
        $advanceId = $this->receive('100.00');

        $this->assertSame('0.00', bcadd($this->position()->invoice($invoice)->open(), '0', 2));

        $this->advances->cancel($advanceId, 'taken by mistake', $this->clerk);

        $this->assertSame('60.00', bcadd($this->position()->invoice($invoice)->open(), '0', 2));
        $this->assertSame('0.00', bcadd($this->position()->credit(), '0', 2));
        $this->assertSame('60.00', $this->balance($this->customerAccount()));
        $this->assertSame([], $this->applications());
        $this->assertSame('0.00', $this->balance($this->accountId('cash')));
        $this->assertTies();

        $history = $this->accountingDb()->table('customer_credit_applications')->where('source_type', CreditSource::CUSTOMER_ADVANCE)->get()->getResult();

        $this->assertCount(1, $history);
        $this->assertNotNull($history[0]->released_at);
    }

    public function testCancel_KeepsTheAdvanceAndBothJournalsAndDeletesNothing(): void
    {
        $advanceId = $this->receive('100.00');

        $this->advances->cancel($advanceId, 'taken by mistake', $this->clerk);

        $row = $this->accountingDb()->table('customer_advances')->where('advance_id', $advanceId)->get()->getRow();

        $this->assertSame(['cancelled', 'taken by mistake', (string) $this->clerk], [$row->status, $row->cancel_reason, (string) $row->cancelled_by]);
        $this->assertNotNull($row->cancelled_at);
        $this->assertSame(['reversed', 'posted'], array_column($this->accountingDb()->table('accounting_journals')->select('status')->whereIn('source_type', [CustomerAdvances::SOURCE, 'reversal'])->orderBy('journal_id')->get()->getResultArray(), 'status'));
    }

    public function testCancel_RefusesAnAdvanceThatWasCancelledAndOneThatIsNotThere(): void
    {
        $advanceId = $this->receive('100.00');

        $this->advances->cancel($advanceId, 'twice', $this->clerk);

        $second = $this->refusal(fn () => $this->advances->cancel($advanceId, 'twice', $this->clerk));

        $this->assertSame(AccountingError::AdvanceAlreadyCancelled, $second->error);
        $this->assertSame(['advance_id' => $advanceId], $second->context);
        $this->assertSame(AccountingError::AdvanceNotFound, $this->refusal(fn () => $this->advances->cancel(999999, 'twice', $this->clerk))->error);
        $this->assertSame(2, $this->countRows('accounting_journals'));
    }

    public function testCancel_RequiresAReason(): void
    {
        $advanceId = $this->receive('100.00');

        foreach (['', '   ', str_repeat('x', 256)] as $reason) {
            $this->assertSame(AccountingError::ReasonRequired, $this->refusal(fn () => $this->advances->cancel($advanceId, $reason, $this->clerk))->error);
        }

        $this->assertSame('completed', $this->accountingDb()->table('customer_advances')->where('advance_id', $advanceId)->get()->getRow()->status);
    }

    public function testReceive_RequiresThePermissionAndABranchTheEmployeeWorksIn(): void
    {
        $outsider = $this->employeeWithGrants([]);

        $this->assertSame(AccountingError::PermissionDenied, $this->refusal(fn () => $this->receive('10', null, null, $outsider))->error);

        $other   = $this->createBranch('B2');
        $limited = $this->employeeWithGrants([AccountingGuard::RECEIVABLES_MANAGE], [$other]);
        $drawer  = (new TreasuryService())->create(['name' => 'Branch one drawer', 'kind' => 'cash', 'branch_id' => 1], $this->clerk);

        $this->assertOutsideTheBranch(fn () => $this->receive('10', null, $drawer, $limited));

        $this->assertSame(0, $this->countRows('customer_advances'));
    }

    public function testCancel_RequiresThePermissionAndABranchTheEmployeeWorksIn(): void
    {
        $advanceId = $this->receive('100.00');
        $outsider  = $this->employeeWithGrants([]);

        $this->assertSame(AccountingError::PermissionDenied, $this->refusal(fn () => $this->advances->cancel($advanceId, 'no right', $outsider))->error);

        $other   = $this->createBranch('B2');
        $limited = $this->employeeWithGrants([AccountingGuard::RECEIVABLES_MANAGE], [$other]);

        $this->assertOutsideTheBranch(fn () => $this->advances->cancel($advanceId, 'other branch', $limited));

        $this->assertSame('completed', $this->accountingDb()->table('customer_advances')->where('advance_id', $advanceId)->get()->getRow()->status);
    }

    public function testReceive_IsRefusedInAClosedPeriodAndSavesNothing(): void
    {
        $this->closeThrough(date('Y-m-d'));

        $exception = $this->refusal(fn () => $this->receive('10'));

        $this->assertStringContainsString(date('Y-m-d'), $exception->getMessage());
        $this->assertSame(0, $this->countRows('customer_advances'));
        $this->assertSame('0.00', $this->balance($this->customerAccount()));
    }

    public function testReceive_RefusesTheLastDayOfAClosedPeriodAndAcceptsTheNextOne(): void
    {
        $this->closeThrough($this->daysAgo(5));

        $this->assertInstanceOf(PostingException::class, $this->refusal(fn () => $this->receive('10', $this->daysAgo(5))));

        $advanceId = $this->receive('10', $this->daysAgo(4));

        $this->assertGreaterThan(0, $advanceId);
    }

    public function testCancel_ReversesOnTodayWhenThePeriodOfTheAdvanceIsClosedInsteadOfRewritingIt(): void
    {
        $advanceId = $this->receive('100.00', $this->daysAgo(10));

        $this->closeThrough($this->daysAgo(5));
        $this->advances->cancel($advanceId, 'taken by mistake', $this->clerk);

        $reversal = $this->accountingDb()->table('accounting_journals')->where('source_type', 'reversal')->get()->getRow();

        $this->assertSame(date('Y-m-d'), $reversal->journal_date);
        $this->assertSame('0.00', $this->balance($this->customerAccount()));
    }

    public function testReceive_RefusesWhatCannotBeReceived(): void
    {
        foreach (['0', '0.00', '-5', 'ten', '', '1e3', '12345678901234', '1.23456'] as $amount) {
            $this->assertSame(AccountingError::AdvanceAmountInvalid, $this->refusal(fn () => $this->receive($amount))->error, "amount {$amount}");
        }

        foreach ([lang('Sales.due'), lang('Sales.giftcard'), lang('Sales.rewards'), 'Barter'] as $type) {
            $this->assertSame(AccountingError::CreditPaymentUnknown, $this->refusal(fn () => $this->advances->receive($this->customerId, '10', date('Y-m-d'), $type, null, '', '', $this->clerk))->error, "type {$type}");
        }

        $this->assertSame(AccountingError::AdvanceCustomerMissing, $this->refusal(fn () => $this->receive('10', null, null, null, null, 99999999))->error);

        $this->accountingDb()->table('customers')->where('person_id', $this->customerId)->update(['deleted' => 1]);

        try {
            $this->assertSame(AccountingError::AdvanceCustomerMissing, $this->refusal(fn () => $this->receive('10'))->error);
        } finally {
            $this->accountingDb()->table('customers')->where('person_id', $this->customerId)->update(['deleted' => 0]);
        }

        $this->assertSame(0, $this->countRows('customer_advances'));
    }

    public function testReceive_RefusesADeactivatedTreasury(): void
    {
        $treasuries = new TreasuryService();
        $drawer     = $treasuries->create(['name' => 'Closed drawer', 'kind' => 'cash'], $this->clerk);

        $treasuries->deactivate($drawer, $this->clerk);

        $this->assertSame(AccountingError::TreasuryInactive, $this->refusal(fn () => $this->receive('10', null, $drawer))->error);
        $this->assertSame(AccountingError::TreasuryNotFound, $this->refusal(fn () => $this->receive('10', null, 99999))->error);
        $this->assertSame(0, $this->countRows('customer_advances'));
    }

    public function testReceive_PostsOnTheDateEnteredAndKeepsTheTimeItWasSavedSeparately(): void
    {
        $date      = $this->daysAgo(5);
        $advanceId = $this->receive('75.00', $date);

        $journal = $this->accountingDb()->table('accounting_journals')->where(['source_type' => CustomerAdvances::SOURCE, 'source_id' => $advanceId])->get()->getRow();
        $row     = $this->accountingDb()->table('customer_advances')->where('advance_id', $advanceId)->get()->getRow();

        $this->assertSame($date, $journal->journal_date);
        $this->assertSame($date, $row->advance_date);
        $this->assertSame($this->databaseToday(), substr((string) $row->created_at, 0, 10), 'The time it was saved is the clock of the database, whichever zone it keeps');
    }

    public function testReceive_RefusesADateAfterTodayAndSavesNothing(): void
    {
        $tomorrow = date('Y-m-d', strtotime('+1 day'));

        $this->assertSame(AccountingError::PaymentDateInFuture, $this->refusal(fn () => $this->receive('10', $tomorrow))->error);
        $this->assertSame(0, $this->countRows('customer_advances'));
        $this->assertSame(0, $this->countJournals());
    }

    /**
     * @return array<string, array{string}>
     */
    public static function datesThatAreNotADay(): array
    {
        return ['empty' => [''], 'text' => ['soon'], 'impossible' => ['2026-02-30'], 'wrong order' => ['20-10-2026']];
    }

    #[DataProvider('datesThatAreNotADay')]
    public function testReceive_RefusesADateThatIsNotADay(string $date): void
    {
        $this->assertSame(AccountingError::InvalidDate, $this->refusal(fn () => $this->receive('10', $date))->error);
        $this->assertSame(0, $this->countRows('customer_advances'));
    }

    public function testReceive_RefusesADateBeforeTheDayTheBooksStartAndAcceptsThatDay(): void
    {
        $start = $this->daysAgo(30);

        $this->setConfigValue('accounting_start_date', $start);

        $this->assertSame(AccountingError::PaymentDateBeforeBooks, $this->refusal(fn () => $this->receive('10', $this->daysAgo(31)))->error);
        $this->assertGreaterThan(0, $this->receive('10', $start));
    }

    public function testReceive_SavesOneAdvanceWhenTheFormIsSentTwiceWithTheSameKey(): void
    {
        $first  = $this->receive('100.00', null, null, null, 'form-123');
        $second = $this->receive('100.00', null, null, null, 'form-123');

        $this->assertSame($first, $second);
        $this->assertSame(1, $this->countRows('customer_advances'));
        $this->assertSame(1, $this->accountingDb()->table('accounting_journals')->where('source_type', CustomerAdvances::SOURCE)->countAllResults());
        $this->assertSame('-100.00', $this->balance($this->customerAccount()));
        $this->assertSame(1, $this->accountingDb()->table('accounting_audit_log')->where(['entity_type' => 'customer_advance', 'action' => 'created'])->countAllResults());
    }

    public function testReceive_SavesTwoAdvancesWhenTheKeysDifferAndWhenThereIsNoKey(): void
    {
        $this->receive('100.00', null, null, null, 'form-1');
        $this->receive('100.00', null, null, null, 'form-2');
        $this->receive('100.00');
        $this->receive('100.00');

        $this->assertSame(4, $this->countRows('customer_advances'));
        $this->assertSame('-400.00', $this->balance($this->customerAccount()));
    }

    public function testReceive_RefusesAKeyThatWasUsedForAnotherAmountOrAnotherCustomer(): void
    {
        $this->receive('100.00', null, null, null, 'form-9');

        $this->assertSame(AccountingError::WriteFailed, $this->refusal(fn () => $this->receive('101.00', null, null, null, 'form-9'))->error);
        $this->assertSame(AccountingError::WriteFailed, $this->refusal(fn () => $this->receive('100.00', null, null, null, 'form-9', $this->anotherCustomer()))->error);
        $this->assertSame(1, $this->countRows('customer_advances'));
    }

    public function testReceive_AnswersAFormSentAgainAfterTheAdvanceWasCancelledWithTheCancelledAdvanceAndPostsNothingNew(): void
    {
        $first = $this->receive('100.00', null, null, null, 'form-5');

        $this->advances->cancel($first, 'mistake', $this->clerk);

        $this->assertSame($first, $this->receive('100.00', null, null, null, 'form-5'));
        $this->assertSame('0.00', $this->balance($this->customerAccount()));
        $this->assertSame(1, $this->countRows('customer_advances'));
    }

    public function testReceive_WritesTheAuditEntriesOfTheAdvanceAndOfItsCancellation(): void
    {
        $advanceId = $this->receive('100.00');

        $this->advances->cancel($advanceId, 'taken by mistake', $this->clerk);

        $entries = $this->accountingDb()->table('accounting_audit_log')->where(['entity_type' => 'customer_advance', 'entity_id' => $advanceId])->orderBy('audit_id')->get()->getResult();

        $this->assertSame(['created', 'cancelled'], array_column($entries, 'action'));
        $this->assertSame((string) $this->clerk, (string) $entries[0]->employee_id);
        $this->assertSame($this->customerId, json_decode((string) $entries[0]->details, true)['customer_id']);
        $this->assertSame('taken by mistake', json_decode((string) $entries[1]->details, true)['reason']);
    }

    public function testReceive_RollsBackTheAdvanceWhenTheBooksRefuseItSoNothingIsLeft(): void
    {
        $this->accountingDb()->table('accounting_accounts')->where('account_id', $this->accountId('cash'))->update(['active' => 0]);

        $this->assertInstanceOf(PostingException::class, $this->refusal(fn () => $this->receive('10')));
        $this->assertSame(0, $this->countRows('customer_advances'));
        $this->assertSame(0, $this->countRows('document_numbers'));
    }

    public function testReceive_CountsFromTheDayItWasDatedAndLeavesNothingOnceItIsCancelledBecauseItsReversalTakesItsDay(): void
    {
        $advanceId = $this->receive('100.00', $this->daysAgo(5));

        $this->assertSame('100.00', bcadd($this->position(null, $this->daysAgo(3))->credit(), '0', 2));
        $this->assertSame('0.00', bcadd($this->position(null, $this->daysAgo(6))->credit(), '0', 2));

        $this->advances->cancel($advanceId, 'taken by mistake', $this->clerk);

        foreach ([$this->daysAgo(3), $this->daysAgo(6), date('Y-m-d')] as $day) {
            $this->assertSame('0.00', bcadd($this->position(null, $day)->credit(), '0', 2), $day);
            $this->assertTrue($this->position(null, $day)->agrees(), $day);
        }
    }

    public function testCancel_KeepsTheAdvanceInThePastWhenItsPeriodIsClosedBecauseTheReversalIsDatedToday(): void
    {
        $advanceId = $this->receive('100.00', $this->daysAgo(5));

        $this->closeThrough($this->daysAgo(4));
        $this->advances->cancel($advanceId, 'taken by mistake', $this->clerk);

        $this->assertSame('100.00', bcadd($this->position(null, $this->daysAgo(3))->credit(), '0', 2));
        $this->assertSame('0.00', bcadd($this->position()->credit(), '0', 2));

        foreach ([$this->daysAgo(3), date('Y-m-d')] as $day) {
            $this->assertTrue($this->position(null, $day)->agrees(), $day);
        }
    }

    public function testReceive_GivesNoNumberWhileTheSequenceIsOff(): void
    {
        $advanceId = $this->receive('50.00');

        $this->assertNull($this->numberOfAdvance($advanceId));
        $this->assertSame(0, $this->countRows('document_numbers'));
    }

    public function testReceive_NumbersTheAdvanceInTheSequenceOfCustomerPayments(): void
    {
        (new DocumentNumbering())->configure(DocumentType::CustomerPayment, ['enabled' => true, 'prefix' => 'RC-', 'padding' => 3]);

        $first  = $this->receive('50.00');
        $second = $this->receive('60.00');

        $this->assertSame('RC-001', $this->numberOfAdvance($first));
        $this->assertSame('RC-002', $this->numberOfAdvance($second));
    }

    public function testReceive_SharesTheSequenceWithThePaymentsMadeOnInvoices(): void
    {
        (new DocumentNumbering())->configure(DocumentType::CustomerPayment, ['enabled' => true, 'prefix' => 'RC-', 'padding' => 3]);

        $invoice   = $this->invoiceOf('100.00', null, [$this->payment(lang('Sales.cash'), '100.00')]);
        $paymentId = (int) $this->accountingDb()->table('sales_payments')->where('sale_id', $invoice)->get()->getRow()->payment_id;
        $advanceId = $this->receive('60.00');

        $this->assertSame('RC-001', (new DocumentNumbering())->numbers(DocumentType::CustomerPayment, 'sales_payments', [$paymentId])[$paymentId]);
        $this->assertSame('RC-002', $this->numberOfAdvance($advanceId));
    }

    public function testReceive_LeavesNoNumberBehindWhenTheBooksRefuseAnAdvanceThatWasNumbered(): void
    {
        (new DocumentNumbering())->configure(DocumentType::CustomerPayment, ['enabled' => true, 'prefix' => 'RC-', 'padding' => 3]);

        $first = $this->receive('50.00', $this->daysAgo(2));
        $this->closeThrough($this->daysAgo(1));

        $refusal = $this->refusal(fn () => $this->receive('60.00', $this->daysAgo(1)));

        $this->assertInstanceOf(PostingException::class, $refusal);
        $this->assertSame(1, $this->countRows('document_numbers'));
        $this->assertSame(2, (new DocumentNumbering())->sequence(DocumentType::CustomerPayment)->nextNumber);
        $this->assertSame('RC-001', $this->numberOfAdvance($first));
    }

    public function testForCustomer_ListsTheAdvancesNewestFirstWithTheirTreasuriesAndNumbers(): void
    {
        (new DocumentNumbering())->configure(DocumentType::CustomerPayment, ['enabled' => true, 'prefix' => 'RC-', 'padding' => 3]);

        $drawer = (new TreasuryService())->create(['name' => 'Receipts drawer', 'kind' => 'cash'], $this->clerk);
        $first  = $this->receive('50.00');
        $second = $this->receive('60.00', null, $drawer);

        $this->receive('70.00', null, null, null, null, $this->anotherCustomer());

        $list = $this->advances->forCustomer($this->customerId);

        $this->assertSame([$second, $first], array_map(static fn (object $row): int => (int) $row->advance_id, $list));
        $this->assertSame(['Receipts drawer', null], array_map(static fn (object $row): ?string => $row->treasury_name, $list));
        $this->assertSame(['RC-002', 'RC-001'], array_map(static fn (object $row): string => $row->number, $list));
    }

    public function testBranchOf_TellsWhereAnAdvanceBelongsAndNullWhenThereIsNone(): void
    {
        $advanceId = $this->receive('50.00');

        $this->assertSame(1, $this->advances->branchOf($advanceId));
        $this->assertNull($this->advances->branchOf(999999));
    }

    public function testReceive_NeverMovesTheCreditOfOneCustomerToAnother(): void
    {
        $other = $this->anotherCustomer();

        $this->invoiceOf('80.00', $other);
        $this->receive('50.00');

        $this->assertSame('80.00', bcadd($this->position($other)->open(), '0', 2));
        $this->assertSame('50.00', bcadd($this->position()->credit(), '0', 2));
        $this->assertSame([], $this->applications($other));
        $this->assertTies();
        $this->assertTies($other);
    }

    private function refundOfTheCredit(string $amount): int
    {
        return (new CreditRefunds(CreditRefunds::CUSTOMER))->refund($this->customerId, $amount, date('Y-m-d'), 'Cash', null, '', '', $this->refunder());
    }

    private function cancelRefund(int $refundId, string $reason): void
    {
        (new CreditRefunds(CreditRefunds::CUSTOMER))->cancel($refundId, $reason, $this->refunder());
    }

    private function refunder(): int
    {
        return $this->employeeWithGrants([AccountingGuard::RECEIVABLES_MANAGE, AccountingGuard::RECEIVABLES_REFUND]);
    }

    private function freeCredit(): string
    {
        return bcadd($this->position()->credit(), '0', 2);
    }

    public function testCancel_RefusesTheAdvanceWhoseCreditWasPaidBackUntilTheRefundIsCancelled(): void
    {
        $advanceId = $this->receive('100.00');
        $refundId  = $this->refundOfTheCredit('60.00');

        $refusal = $this->refusal(fn () => $this->advances->cancel($advanceId, 'taken by mistake', $this->clerk));

        $this->assertSame(AccountingError::CreditRefunded, $refusal->error);
        $this->assertSame('completed', $this->accountingDb()->table('customer_advances')->where('advance_id', $advanceId)->get()->getRow()->status);
        $this->assertSame('40.00', $this->freeCredit());

        $this->cancelRefund($refundId, 'paid twice');
        $this->advances->cancel($advanceId, 'taken by mistake', $this->clerk);

        $this->assertSame('0.00', $this->freeCredit());
        $this->assertSame('0.00', $this->balance($this->customerAccount()));
        $this->assertTies();
    }

    public function testCancel_LeavesTheRestOfAnAdvanceToBeCancelledWhenOnlyPartOfItWasPaidBack(): void
    {
        $first  = $this->receive('100.00', $this->daysAgo(3));
        $second = $this->receive('50.00', $this->daysAgo(1));

        $this->refundOfTheCredit('80.00');

        $this->assertSame(AccountingError::CreditRefunded, $this->refusal(fn () => $this->advances->cancel($first, 'mistake', $this->clerk))->error);

        $this->advances->cancel($second, 'mistake', $this->clerk);

        $this->assertSame('20.00', $this->freeCredit());
        $this->assertTies();
    }

    public function testCancel_RefusesAnotherEmployeesAdvanceAsOneThatDoesNotExistForAnEmployeeWhoSeesTheirOwnRecords(): void
    {
        $advance = $this->receive();
        $second  = $this->employeeWithGrants([AccountingGuard::RECEIVABLES_MANAGE, AccountingGuard::TREASURIES_MANAGE]);

        (new RoleService())->setRecordScope($second, 'own', null);

        $this->assertSame(AccountingError::AdvanceNotFound, $this->refusal(fn () => $this->advances->cancel($advance, 'by a stranger', $second))->error);
        $this->assertNull($this->advances->branchOf($advance, $second), 'The form of the cancellation is not there for another employee advance');
        $this->assertNotNull($this->advances->branchOf($advance, $this->clerk));
        $this->assertNotSame('cancelled', (string) $this->accountingDb()->table('customer_advances')->select('status')->where('advance_id', $advance)->get()->getRow()->status);
    }

    public function testCancel_CancelsTheOwnAdvanceOfAnEmployeeWhoSeesTheirOwnRecords(): void
    {
        $second  = $this->employeeWithGrants([AccountingGuard::RECEIVABLES_MANAGE, AccountingGuard::TREASURIES_MANAGE]);
        $advance = $this->receive('100.00', null, null, $second);

        (new RoleService())->setRecordScope($second, 'own', null);

        $this->advances->cancel($advance, 'entered twice', $second);

        $this->assertSame('cancelled', (string) $this->accountingDb()->table('customer_advances')->select('status')->where('advance_id', $advance)->get()->getRow()->status);
    }

    public function testReceive_RefusesWhileOperationsAreFrozen(): void
    {
        $this->setConfigValue(OperationsHold::KEY, json_encode(['since' => '2026-11-16 10:00:00', 'by' => 1, 'reason' => 'Fixing the routing']));

        try {
            $this->assertSame(PostingError::OperationsHeld, $this->refusal(fn () => $this->receive())->error);
        } finally {
            $this->setConfigValue(OperationsHold::KEY, '');
        }
    }
}
