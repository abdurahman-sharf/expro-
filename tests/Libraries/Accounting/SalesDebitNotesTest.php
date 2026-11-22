<?php

namespace Tests\Libraries\Accounting;

use App\Libraries\Accounting\AccountingError;
use App\Libraries\Accounting\AccountingException;
use App\Libraries\Accounting\AccountingGuard;
use App\Libraries\Accounting\CreditSettlement;
use App\Libraries\Accounting\CustomerLock;
use App\Libraries\Accounting\CustomerLedger;
use App\Libraries\Accounting\CustomerOpening;
use App\Libraries\Accounting\JournalService;
use App\Libraries\Accounting\PostingError;
use App\Libraries\Accounting\PostingException;
use App\Libraries\Accounting\SalesDebitNotes;
use App\Libraries\EInvoicing\EInvoiceError;
use App\Libraries\EInvoicing\EInvoiceException;
use App\Libraries\EInvoicing\EInvoiceKind;
use App\Libraries\EInvoicing\EInvoiceService;
use App\Libraries\Pricing\SalesControlSettings;
use App\Libraries\Numbering\DocumentNumbering;
use App\Libraries\Numbering\DocumentType;
use App\Libraries\Organization\BranchException;
use App\Libraries\Organization\BranchGuard;
use App\Libraries\Roles\RoleService;
use CodeIgniter\Events\Events;
use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\DatabaseTestTrait;
use Config\Database;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Support\AccountingFixtureTrait;
use Tests\Support\EInvoiceSaleFixtureTrait;
use Tests\Support\EmployeeFixtureTrait;
use Tests\Support\ItemFixtureTrait;
use Tests\Support\RoleFixtureTrait;
use Tests\Support\SaleAccountingFixtureTrait;
use Tests\Support\SaudiSaleFixtureTrait;

/**
 * The sales debit note: a document that raises what a customer owes without an invoice, with one journal
 * against the account of the customer, an open item of the customer dated by its day that credit is spent
 * on in the order of the invoices, taken back by reversing its journal and never edited or deleted.
 */
class SalesDebitNotesTest extends CIUnitTestCase
{
    use DatabaseTestTrait;
    use AccountingFixtureTrait;
    use EmployeeFixtureTrait;
    use ItemFixtureTrait;
    use RoleFixtureTrait;
    use SaleAccountingFixtureTrait;
    use EInvoiceSaleFixtureTrait;
    use SaudiSaleFixtureTrait;

    protected $migrate     = true;
    protected $migrateOnce = true;
    protected $refresh     = false;
    protected $namespace   = null;

    private SalesDebitNotes $notes;
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
        $this->setConfigValue('accounting_debit_note', '1');

        $this->notes = new SalesDebitNotes();
        $this->clerk = $this->employeeWithGrants([AccountingGuard::DEBIT_NOTES_MANAGE]);
    }

    protected function tearDown(): void
    {
        $this->leaveTheSaudiRules();

        $db = $this->accountingDb();
        $db->table('accounting_audit_log')->where('entity_type', 'sales_debit_note')->delete();
        $db->table('document_numbers')->truncate();
        $db->table('document_sequences')->truncate();
        $db->table('app_config')->whereIn('key', ['accounting_debit_note', 'accounting_customer_opening'])->delete();
        $this->setConfigValue(SalesControlSettings::CREDIT_LIMIT, '0');
        $db->table('customers')->update(['credit_limit' => 0]);

        $this->stopSelling();

        parent::tearDown();
    }

    /**
     * @param array<string, mixed> $override
     *
     * @return array<string, mixed>
     */
    private function input(array $override = []): array
    {
        return [...['customer_id' => $this->customerId, 'date' => $this->daysAgo(5), 'amount' => '100', 'tax_percent' => '0', 'note' => 'freight recovered'], ...$override];
    }

    private function raise(array $override = [], ?int $employeeId = null): int
    {
        return $this->notes->create($this->input($override), $employeeId ?? $this->clerk);
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

    private function assertRefusedWith(AccountingError $error, callable $action): void
    {
        $refusal = $this->refusal($action);

        $this->assertInstanceOf(AccountingException::class, $refusal);
        $this->assertSame($error, $refusal->error);
    }

    /**
     * @return list<string> "account=debit/credit" of the lines of the journal of a note.
     */
    private function linesOf(int $debitNoteId, int $scale = 2): array
    {
        $journal = $this->accountingDb()->table('accounting_journals')->where(['source_type' => SalesDebitNotes::SOURCE, 'source_id' => $debitNoteId, 'status' => 'posted'])->get()->getRow();

        $this->assertNotNull($journal);

        return array_map(
            static fn (object $line): string => $line->account_id . '=' . bcadd($line->debit, '0', $scale) . '/' . bcadd($line->credit, '0', $scale),
            $this->accountingDb()->table('accounting_journal_lines')->where('journal_id', $journal->journal_id)->orderBy('line_no')->get()->getResult(),
        );
    }

    private function noteRow(int $debitNoteId): object
    {
        return $this->accountingDb()->table('sales_debit_notes')->where('debit_note_id', $debitNoteId)->get()->getRow();
    }

    /**
     * @return list<string> "source:id->target:amount" of the applications of the customer that stand.
     */
    private function spent(?int $customerId = null): array
    {
        return array_map(
            static fn (object $row): string => $row->source_type . ':' . $row->source_id . '->' . ($row->invoice_sale_id !== null ? 'sale' . $row->invoice_sale_id : ($row->debit_note_id !== null ? 'note' . $row->debit_note_id : 'opening' . $row->opening_id)) . ':' . bcadd($row->amount, '0', 2),
            (new CustomerLedger())->applications($customerId ?? $this->customerId),
        );
    }

    public function testCreate_PostsOneJournalDebitingTheCustomerAndCreditingTheRoutedRevenue(): void
    {
        $id = $this->raise();

        $this->assertSame([$this->customerAccount() . '=100.00/0.00', $this->accountId('sales_revenue') . '=0.00/100.00'], $this->linesOf($id));

        $journal = $this->accountingDb()->table('accounting_journals')->where(['source_type' => SalesDebitNotes::SOURCE, 'source_id' => $id])->get()->getRow();

        $this->assertSame('revenue', $journal->entry_type);
        $this->assertSame($this->daysAgo(5), $journal->journal_date);
        $this->assertSame('1', (string) $journal->branch_id);
        $this->assertSame((string) $this->clerk, (string) $journal->created_by);
        $this->assertSame('100.00', $this->balance($this->customerAccount()));
        $this->assertSame(1, $this->accountingDb()->table('accounting_journals')->where(['source_type' => SalesDebitNotes::SOURCE, 'source_id' => $id])->countAllResults());
    }

    public function testCreate_AddsOutputTaxLikeAnInvoiceDoes(): void
    {
        $id = $this->raise(['tax_percent' => '15']);

        $this->assertSame([$this->customerAccount() . '=115.00/0.00', $this->accountId('sales_revenue') . '=0.00/100.00', $this->accountId('tax_output') . '=0.00/15.00'], $this->linesOf($id));

        $row = $this->noteRow($id);

        $this->assertSame(['100.0000', '15.0000', '15.0000', '115.0000'], [$row->amount, $row->tax_percent === null ? '' : bcadd($row->tax_percent, '0', 4), $row->tax_amount, $row->total]);
    }

    public function testCreate_RoundsTheTotalToTheCentAndKeepsTheTaxAsCalculatedLikeAnInvoice(): void
    {
        $id = $this->raise(['amount' => '0.70', 'tax_percent' => '15']);

        $this->assertSame([$this->customerAccount() . '=0.8100/0.0000', $this->accountId('sales_revenue') . '=0.0000/0.7050', $this->accountId('tax_output') . '=0.0000/0.1050'], $this->linesOf($id, 4));
    }

    public function testCreate_RoundsTheTaxToTheHalalaUnderTheSaudiRules(): void
    {
        $this->underTheSaudiRules();

        $original = (new EInvoiceService())->documentOf(EInvoiceService::SOURCE_SALE, $this->saudiSaleOf('100.00'));
        $id       = $this->raise(['amount' => '33.33', 'tax_percent' => '15', 'original_document' => $original->number]);

        $this->assertSame([$this->customerAccount() . '=38.3300/0.0000', $this->accountId('sales_revenue') . '=0.0000/33.3300', $this->accountId('tax_output') . '=0.0000/5.0000'], $this->linesOf($id, 4));
    }

    public function testCreate_IssuesTheDocumentOfANoteRaisedUnderTheSaudiRules(): void
    {
        $this->underTheSaudiRules();

        $original = (new EInvoiceService())->documentOf(EInvoiceService::SOURCE_SALE, $this->saudiSaleOf('100.00'));
        $id       = $this->raise(['amount' => '20.00', 'tax_percent' => '15', 'original_document' => $original->number, 'note_reason' => 'In case of essential change or amendment in the supply, which leads to the change of the VAT due']);
        $document = (new EInvoiceService())->documentOf(EInvoiceService::SOURCE_NOTE, $id);

        $this->assertNotNull($document, 'the note is issued with its journal');
        $this->assertSame(EInvoiceKind::DebitNote, $document->kind);
        $this->assertSame($original->documentId, $document->originalDocumentId);
        $this->assertSame(['20.00', '3.00', '23.00'], [$document->taxExclusive, $document->taxAmount, $document->taxInclusive]);
        $this->assertSame('In case of essential change or amendment in the supply, which leads to the change of the VAT due', $document->reason);
    }

    public function testCreate_TakesTheAmountToTheHalalaBeforeItsTaxUnderTheSaudiRules(): void
    {
        $this->underTheSaudiRules();

        $original = (new EInvoiceService())->documentOf(EInvoiceService::SOURCE_SALE, $this->saudiSaleOf('100.00'));
        $id       = $this->raise(['amount' => '10.0349', 'tax_percent' => '15', 'original_document' => $original->number, 'note_reason' => 'In case of essential change or amendment in the supply, which leads to the change of the VAT due']);
        $document = (new EInvoiceService())->documentOf(EInvoiceService::SOURCE_NOTE, $id);

        $this->assertNotNull($document, 'the note is issued: its tax is that of the 10.03 it states, not the 1.51 of 10.0349');
        $this->assertSame(['10.03', '1.50', '11.53'], [$document->taxExclusive, $document->taxAmount, $document->taxInclusive]);
        $this->assertSame([$this->customerAccount() . '=11.5300/0.0000', $this->accountId('sales_revenue') . '=0.0000/10.0300', $this->accountId('tax_output') . '=0.0000/1.5000'], $this->linesOf($id, 4));
    }

    public function testCreate_RefusesANoteThatNamesNoIssuedDocumentUnderTheSaudiRules(): void
    {
        $this->underTheSaudiRules();

        $before = $this->accountingDb()->table('sales_debit_notes')->countAllResults();

        foreach (['', 'NO-SUCH-NUMBER'] as $number) {
            try {
                $this->raise(['amount' => '20.00', 'tax_percent' => '15', 'original_document' => $number]);
                $this->fail('The note was raised without its original.');
            } catch (EInvoiceException $refusal) {
                $this->assertSame(EInvoiceError::OriginalNotIssued, $refusal->error);
            }
        }

        $this->assertSame($before, $this->accountingDb()->table('sales_debit_notes')->countAllResults());
    }

    public function testReverse_RefusesANoteWhoseDocumentWasIssued(): void
    {
        $this->underTheSaudiRules();

        $original = (new EInvoiceService())->documentOf(EInvoiceService::SOURCE_SALE, $this->saudiSaleOf('100.00'));
        $id       = $this->raise(['amount' => '20.00', 'tax_percent' => '15', 'original_document' => $original->number]);

        try {
            $this->notes->reverse($id, 'Raised by mistake', $this->clerk);
            $this->fail('The note was taken back.');
        } catch (AccountingException $refusal) {
            $this->assertSame(AccountingError::DebitNoteDocumentIssued, $refusal->error);
        }
    }

    public function testCreate_CreditsTheRevenueOrExpenseAccountTheNoteNames(): void
    {
        $other = $this->accountIdByCode('4201');
        $id    = $this->raise(['account_id' => $other]);

        $this->assertSame([$this->customerAccount() . '=100.00/0.00', $other . '=0.00/100.00'], $this->linesOf($id));
        $this->assertSame((string) $other, (string) $this->noteRow($id)->revenue_account_id);

        $expense = $this->accountIdByCode('541');

        $this->assertSame([$this->customerAccount() . '=100.00/0.00', $expense . '=0.00/100.00'], $this->linesOf($this->raise(['account_id' => $expense])));
    }

    public function testCreate_RefusesAnAccountThatIsNotAnOpenRevenueOrExpenseLeafWithoutAPartyOfItsOwn(): void
    {
        $inactive = $this->accountIdByCode('4202');
        $this->accountingDb()->table('accounting_accounts')->where('account_id', $inactive)->update(['active' => 0]);

        foreach (['a group' => $this->accountIdByCode('41'), 'an asset' => $this->accountId('cash'), 'a liability' => $this->accountId('tax_output'), 'the account of the customer' => $this->customerAccount(), 'an inactive account' => $inactive, 'no such account' => 99999999] as $name => $accountId) {
            $this->assertRefusedWith(AccountingError::DebitNoteAccountInvalid, fn () => $this->raise(['account_id' => $accountId]));
        }

        $this->assertSame(0, $this->countRows('sales_debit_notes'));
        $this->assertSame(0, $this->countJournals());
    }

    public function testCreate_RequiresThePermissionAndLeavesNothing(): void
    {
        $this->assertRefusedWith(AccountingError::PermissionDenied, fn () => $this->raise([], $this->employeeWithGrants([])));
        $this->assertSame(0, $this->countRows('sales_debit_notes'));
        $this->assertSame(0, $this->countJournals());
    }

    public function testCreate_RefusesWhileTheSettingIsOffOrAccountingIsOff(): void
    {
        $this->setConfigValue('accounting_debit_note', '0');

        $this->assertRefusedWith(AccountingError::DebitNoteOff, fn () => $this->raise());

        $this->setConfigValue('accounting_debit_note', '1');
        $this->setConfigValue('accounting_enabled', '0');

        $this->assertRefusedWith(AccountingError::DebitNoteOff, fn () => $this->raise());
        $this->assertSame(0, $this->countRows('sales_debit_notes'));
    }

    /**
     * @return array<string, array{array<string, mixed>, AccountingError}>
     */
    public static function refusedInputProvider(): array
    {
        return [
            'no amount'                 => [['amount' => ''], AccountingError::DebitNoteAmountInvalid],
            'a zero amount'             => [['amount' => '0'], AccountingError::DebitNoteAmountInvalid],
            'a negative amount'         => [['amount' => '-5'], AccountingError::DebitNoteAmountInvalid],
            'an amount that is text'    => [['amount' => 'ten'], AccountingError::DebitNoteAmountInvalid],
            'too many decimals'         => [['amount' => '1.00001'], AccountingError::DebitNoteAmountInvalid],
            'less than half a cent'     => [['amount' => '0.004', 'tax_percent' => '0'], AccountingError::DebitNoteAmountInvalid],
            'less than half a cent with its tax' => [['amount' => '0.0024', 'tax_percent' => '100'], AccountingError::DebitNoteAmountInvalid],
            'a total beyond the books'  => [['amount' => '99999999999.9999', 'tax_percent' => '100'], AccountingError::DebitNoteAmountInvalid],
            'a tax above 100'           => [['tax_percent' => '100.01'], AccountingError::DebitNoteTaxInvalid],
            'a negative tax'            => [['tax_percent' => '-1'], AccountingError::DebitNoteTaxInvalid],
            'a tax that is text'        => [['tax_percent' => 'x'], AccountingError::DebitNoteTaxInvalid],
            'a date that is not a date' => [['date' => '2026-02-30'], AccountingError::InvalidDate],
            'no date'                   => [['date' => ''], AccountingError::InvalidDate],
            'a day to come'             => [['date' => '2999-01-01'], AccountingError::InvalidDate],
            'a note that is too long'   => [['note' => ''], AccountingError::DebitNoteNoteTooLong],
            'no customer'               => [['customer_id' => 0], AccountingError::DebitNoteCustomerMissing],
            'a customer that is gone'   => [['customer_id' => 99999999], AccountingError::DebitNoteCustomerMissing],
        ];
    }

    /**
     * @param array<string, mixed> $override
     */
    #[DataProvider('refusedInputProvider')]
    public function testCreate_RefusesWhatIsNotAnAmountATaxADateANoteOrACustomerAndLeavesNothing(array $override, AccountingError $error): void
    {
        if (($override['note'] ?? null) === '') {
            $override['note'] = str_repeat('n', 501);
        }

        $this->assertRefusedWith($error, fn () => $this->raise($override));
        $this->assertSame(0, $this->countRows('sales_debit_notes'));
        $this->assertSame(0, $this->countJournals());
    }

    public function testCreate_RefusesACustomerWhoWasDeleted(): void
    {
        $this->accountingDb()->table('customers')->where('person_id', $this->customerId)->update(['deleted' => 1]);

        $this->assertRefusedWith(AccountingError::DebitNoteCustomerMissing, fn () => $this->raise());

        $this->accountingDb()->table('customers')->where('person_id', $this->customerId)->update(['deleted' => 0]);
    }

    public function testCreate_RefusesADayInAClosedPeriodAndLeavesNothingOfTheNote(): void
    {
        $this->closeThrough(date('Y-m-d'));

        $failure = $this->refusal(fn () => $this->raise());

        $this->assertInstanceOf(PostingException::class, $failure);
        $this->assertSame(PostingError::PeriodClosed, $failure->error);
        $this->assertSame(0, $this->countRows('sales_debit_notes'));
        $this->assertSame(0, $this->countJournals());
        $this->assertSame(0, $this->countRows('document_numbers'));
    }

    public function testCreate_RefusesABranchTheEmployeeDoesNotWorkInAndLeavesNothing(): void
    {
        $other  = $this->createBranch();
        $worker = $this->employeeWithGrants([AccountingGuard::DEBIT_NOTES_MANAGE], [$other]);

        $this->assertInstanceOf(BranchException::class, $this->refusal(fn () => $this->raise(['branch_id' => 1], $worker)));
        $this->assertSame(0, $this->countRows('sales_debit_notes'));
    }

    public function testCreate_PostsAnotherBranchWhenTheEmployeeWorksThereAndTheJournalCarriesIt(): void
    {
        $other  = $this->createBranch();
        $worker = $this->employeeWithGrants([AccountingGuard::DEBIT_NOTES_MANAGE], [$other]);
        $id     = $this->raise(['branch_id' => $other], $worker);

        $this->assertSame((string) $other, (string) $this->noteRow($id)->branch_id);
        $this->assertSame((string) $other, (string) $this->accountingDb()->table('accounting_journals')->where(['source_type' => SalesDebitNotes::SOURCE, 'source_id' => $id])->get()->getRow()->branch_id);
    }

    /**
     * @return list<string>
     */
    private function locksTakenBy(callable $action): array
    {
        $names    = [];
        $listener = static function ($query) use (&$names): void {
            if (preg_match("/GET_LOCK\('([^']+)'/", (string) $query->getQuery(), $found) === 1) {
                $names[] = $found[1];
            }
        };

        Events::on('DBQuery', $listener);

        try {
            $action();
        } finally {
            Events::removeListener('DBQuery', $listener);
        }

        return $names;
    }

    public function testCreate_TakesTheLockOfTheCustomerWhileItRaisesTheNote(): void
    {
        $name = (new CustomerLock())->nameOf($this->customerId);

        $this->assertContains($name, $this->locksTakenBy(fn () => $this->raise()));
    }

    public function testReverse_TakesTheLockOfTheCustomerWhileItTakesTheNoteBack(): void
    {
        $id   = $this->raise();
        $name = (new CustomerLock())->nameOf($this->customerId);

        $this->assertContains($name, $this->locksTakenBy(fn () => $this->notes->reverse($id, 'raised by mistake', $this->clerk)));
    }

    public function testCreate_TakesNoLockWhenTheNoteIsRefusedBeforeItTouchesTheBooks(): void
    {
        $taken = $this->locksTakenBy(function (): void {
            try {
                $this->raise(['amount' => '0']);
            } catch (AccountingException) {
            }
        });

        $this->assertSame([], $taken);
    }

    public function testCreate_RefusesANoteWhoseRevenueRoutingRowIsCancelledWithTheNamedFailureAndLeavesNothing(): void
    {
        $this->accountingDb()->table('accounting_routing')->where('routing_key', 'sales.revenue')->update(['routing_type' => 'cancelled']);

        $failure = $this->refusal(fn () => $this->raise());

        $this->assertInstanceOf(PostingException::class, $failure);
        $this->assertSame(PostingError::RoutingRowCancelled, $failure->error);
        $this->assertSame('sales.revenue', $failure->context['routing_key']);
        $this->assertSame(0, $this->countRows('sales_debit_notes'));
        $this->assertSame(0, $this->countJournals());
    }

    public function testCreate_RefusesATaxedNoteWhoseOutputTaxAccountIsMissingAndLeavesNothing(): void
    {
        $this->accountingDb()->table('accounting_routing')->where('routing_key', 'tax.output')->update(['account_id' => null]);

        $failure = $this->refusal(fn () => $this->raise(['tax_percent' => '15']));

        $this->assertInstanceOf(PostingException::class, $failure);
        $this->assertSame(PostingError::RoutingAccountUnusable, $failure->error);
        $this->assertSame(0, $this->countRows('sales_debit_notes'));
        $this->assertSame(0, $this->countJournals());
    }

    public function testCreate_PostsANoteThatNamesItsAccountWhileTheRevenueRoutingRowIsCancelled(): void
    {
        $this->accountingDb()->table('accounting_routing')->where('routing_key', 'sales.revenue')->update(['routing_type' => 'cancelled']);

        $account = $this->accountId('sales_adjustment');
        $id      = $this->raise(['account_id' => $account]);

        $this->assertSame([$this->customerAccount() . '=100.00/0.00', $account . '=0.00/100.00'], $this->linesOf($id));
    }

    public function testCreate_AcceptsAnAmountThatRoundsToTheSmallestUnitAndPostsIt(): void
    {
        $id = $this->raise(['amount' => '0.005', 'tax_percent' => '0']);

        $this->assertSame([$this->customerAccount() . '=0.0100/0.0000', $this->accountId('sales_revenue') . '=0.0000/0.0100'], $this->linesOf($id, 4));
        $this->assertSame('0.0100', bcadd($this->noteRow($id)->total, '0', 4));
    }

    public function testCreate_AcceptsTheLargestTotalTheBooksHold(): void
    {
        $id = $this->raise(['amount' => '99999999999.99', 'tax_percent' => '0']);

        $this->assertSame('99999999999.9900', bcadd($this->noteRow($id)->total, '0', 4));
    }

    public function testCreate_NumbersTheNoteInFiveDigitsUntilTheCompanyConfiguresItsSequence(): void
    {
        $id = $this->raise();

        $this->assertSame(str_pad((string) $id, 5, '0', STR_PAD_LEFT), $this->notes->numberOf($id));
        $this->assertNull($this->notes->peekNumber());
        $this->assertSame(5, DocumentType::SalesDebitNote->defaultPadding());
    }

    public function testCreate_TakesTheNumberFromTheSequenceOfSalesDebitNotesWhenItIsOn(): void
    {
        $manager = $this->employeeWithGrants(['config']);

        (new DocumentNumbering())->configure(DocumentType::SalesDebitNote, ['enabled' => true, 'prefix' => 'DN-', 'padding' => 5], $manager);

        $this->assertSame('DN-00001', $this->notes->peekNumber());

        $first  = $this->raise();
        $second = $this->raise();

        $this->assertSame('DN-00001', $this->notes->numberOf($first));
        $this->assertSame('DN-00002', $this->notes->numberOf($second));
        $this->assertSame('DN-00003', $this->notes->peekNumber());
        $this->assertStringContainsString('DN-00001', (string) $this->accountingDb()->table('accounting_journals')->where(['source_type' => SalesDebitNotes::SOURCE, 'source_id' => $first])->get()->getRow()->description);
    }

    public function testCreate_WritesWhoRaisedTheNoteToTheAuditLog(): void
    {
        $id    = $this->raise(['tax_percent' => '15']);
        $entry = $this->accountingDb()->table('accounting_audit_log')->where(['entity_type' => 'sales_debit_note', 'entity_id' => $id, 'action' => 'created'])->get()->getRow();

        $this->assertNotNull($entry);
        $this->assertSame((string) $this->clerk, (string) $entry->employee_id);
        $this->assertSame('115.0000', json_decode((string) $entry->details, true)['total']);
    }

    public function testReverse_ReversesTheJournalMarksTheNoteAndDeletesNothing(): void
    {
        $id = $this->raise(['tax_percent' => '15']);

        $this->notes->reverse($id, 'raised by mistake', $this->clerk);

        $row = $this->noteRow($id);

        $this->assertSame('reversed', $row->status);
        $this->assertSame('raised by mistake', $row->reverse_reason);
        $this->assertSame((string) $this->clerk, (string) $row->reversed_by);
        $this->assertSame('0.00', $this->balance($this->customerAccount()));
        $this->assertSame('0.00', $this->balance($this->accountId('sales_revenue')));
        $this->assertSame('0.00', $this->balance($this->accountId('tax_output')));
        $this->assertSame(2, $this->accountingDb()->table('accounting_journals')->where(['source_type' => SalesDebitNotes::SOURCE, 'source_id' => $id])->orWhere('reverses_journal_id IS NOT NULL', null, false)->countAllResults());
        $this->assertSame(1, $this->countRows('sales_debit_notes'));
        $this->assertNotNull($this->accountingDb()->table('accounting_audit_log')->where(['entity_type' => 'sales_debit_note', 'entity_id' => $id, 'action' => 'reversed'])->get()->getRow());
    }

    public function testReverse_RefusesAReasonThatIsMissingOrLongerThan255Characters(): void
    {
        $id = $this->raise();

        foreach (['', '   ', str_repeat('r', 256)] as $reason) {
            $this->assertRefusedWith(AccountingError::ReasonRequired, fn () => $this->notes->reverse($id, $reason, $this->clerk));
        }

        $this->assertSame('posted', $this->noteRow($id)->status);
        $this->assertSame('100.00', $this->balance($this->customerAccount()));
    }

    public function testReverse_AsksForTheReasonBeforeAnythingElseEvenOfANoteThatIsAlreadyTakenBack(): void
    {
        $id = $this->raise();

        $this->notes->reverse($id, 'once', $this->clerk);

        foreach (['', '  ', str_repeat('r', 256)] as $reason) {
            $this->assertRefusedWith(AccountingError::ReasonRequired, fn () => $this->notes->reverse($id, $reason, $this->clerk));
        }
    }

    public function testReverse_RefusesANoteMarkedTakenBackWithoutTouchingItsJournal(): void
    {
        $id = $this->raise();

        $this->accountingDb()->table('sales_debit_notes')->where('debit_note_id', $id)->update(['status' => 'reversed']);

        $this->assertRefusedWith(AccountingError::DebitNoteReversed, fn () => $this->notes->reverse($id, 'already marked', $this->clerk));
        $this->assertSame('100.00', $this->balance($this->customerAccount()), 'the journal of the note still stands');
        $this->assertSame(1, $this->accountingDb()->table('accounting_journals')->where(['source_type' => SalesDebitNotes::SOURCE, 'source_id' => $id])->countAllResults());
    }

    public function testReverse_RefusesANoteThatIsAlreadyTakenBackOrDoesNotExist(): void
    {
        $id = $this->raise();

        $this->notes->reverse($id, 'once', $this->clerk);

        $this->assertRefusedWith(AccountingError::DebitNoteReversed, fn () => $this->notes->reverse($id, 'twice', $this->clerk));
        $this->assertRefusedWith(AccountingError::DebitNoteNotFound, fn () => $this->notes->reverse(99999999, 'nothing', $this->clerk));
        $this->assertSame('0.00', $this->balance($this->customerAccount()));
    }

    public function testReverse_RequiresThePermissionAndTheBranchOfTheNote(): void
    {
        $id     = $this->raise();
        $other  = $this->createBranch();
        $worker = $this->employeeWithGrants([AccountingGuard::DEBIT_NOTES_MANAGE], [$other]);

        $this->assertRefusedWith(AccountingError::PermissionDenied, fn () => $this->notes->reverse($id, 'no grant', $this->employeeWithGrants([])));
        $this->assertInstanceOf(BranchException::class, $this->refusal(fn () => $this->notes->reverse($id, 'wrong branch', $worker)));
        $this->assertSame('posted', $this->noteRow($id)->status);
    }

    public function testReverse_WorksWhileTheSettingIsOffSoNotesCanAlwaysBeTakenBack(): void
    {
        $id = $this->raise();

        $this->setConfigValue('accounting_debit_note', '0');
        $this->notes->reverse($id, 'setting turned off', $this->clerk);

        $this->assertSame('reversed', $this->noteRow($id)->status);
    }

    public function testReverse_DatesTheReversalOnTheDayOfTheNoteWhenItsPeriodIsOpenAndTodayWhenItWasClosedSince(): void
    {
        $open   = $this->raise(['date' => $this->daysAgo(20)]);
        $closed = $this->raise(['date' => $this->daysAgo(10)]);

        $this->notes->reverse($open, 'open period', $this->clerk);
        $this->closeThrough($this->daysAgo(5));
        $this->notes->reverse($closed, 'closed since', $this->clerk);

        $reversals = $this->accountingDb()->table('accounting_journals')->where('reverses_journal_id IS NOT NULL', null, false)->orderBy('journal_id')->get()->getResult();

        $this->assertSame([$this->daysAgo(20), date('Y-m-d')], array_column($reversals, 'journal_date'));
    }

    public function testPosition_ListsTheNoteAsAnOpenItemDatedByItsDayAndTheFiguresTieToTheAccount(): void
    {
        $id = $this->raise(['date' => $this->daysAgo(45), 'tax_percent' => '15']);

        $position = $this->position();
        $figure   = $position->invoices[0];

        $this->assertCount(1, $position->invoices);
        $this->assertTrue($figure->debitNote);
        $this->assertFalse($figure->opening);
        $this->assertSame(SalesDebitNotes::keyOf($id), $figure->saleId);
        $this->assertSame($this->daysAgo(45), $figure->date);
        $this->assertSame('115.00', bcadd($figure->open(), '0', 2));
        $this->assertSame('115.00', bcadd($position->open(), '0', 2));
        $this->assertTies();
    }

    public function testPosition_AgesTheNoteFromItsOwnDayNotFromTheDayItWasEntered(): void
    {
        $this->raise(['date' => $this->daysAgo(45)]);
        $this->raise(['date' => date('Y-m-d')]);

        $aging = $this->position()->aging(date('Y-m-d'));

        $this->assertSame('100.00', bcadd($aging['31-60'], '0', 2));
        $this->assertSame('100.00', bcadd($aging['today'], '0', 2));
        $this->assertTies();
    }

    public function testPosition_DropsAReversedNoteFromTheOpenItemsAndKeepsTheTie(): void
    {
        $id = $this->raise();

        $this->notes->reverse($id, 'gone', $this->clerk);

        $this->assertSame([], $this->position()->invoices);
        $this->assertTies();
    }

    public function testPosition_ReadsADayOfThePastWithTheNotesThatStoodThen(): void
    {
        $id = $this->raise(['date' => $this->daysAgo(30)]);

        $this->assertSame([], $this->position(null, $this->daysAgo(40))->invoices);
        $this->assertCount(1, $this->position(null, $this->daysAgo(20))->invoices);

        $this->closeThrough($this->daysAgo(25));
        $this->notes->reverse($id, 'later', $this->clerk);

        $this->assertCount(1, $this->position(null, $this->daysAgo(20))->invoices, 'the reversal is dated today, so a day before it the note still stood');
        $this->assertSame([], $this->position()->invoices);
        $this->assertTies();
    }

    private function creditOf(string $amount): int
    {
        $this->setConfigValue('accounting_customer_opening', '1');

        return (new CustomerOpening())->create($this->customerId, CustomerOpening::WE_OWE, $amount, $this->daysAgo(90), 'credit', $this->employeeWithGrants([AccountingGuard::OPENING_MANAGE]));
    }

    public function testSettle_SpendsTheCreditOfTheCustomerOnTheNoteWhenTheNoteIsTheOldestOpenItem(): void
    {
        $note    = $this->raise(['date' => $this->daysAgo(40)]);
        $invoice = $this->invoiceDated('200', $this->daysAgo(10));
        $credit  = $this->creditOf('60');

        $this->assertSame(['opening_credit:' . $credit . '->note' . $note . ':60.00'], $this->spent());
        $this->assertSame('40.00', bcadd($this->position()->invoices[0]->open(), '0', 2));
        $this->assertGreaterThan(0, $invoice);
        $this->assertTies();
    }

    public function testCreate_SpendsTheCreditTheCustomerAlreadyHoldsOnTheNewNoteAtOnce(): void
    {
        $credit = $this->creditOf('60');
        $note   = $this->raise(['amount' => '100']);

        $this->assertSame(['opening_credit:' . $credit . '->note' . $note . ':60.00'], $this->spent());
        $this->assertSame('40.00', bcadd($this->position()->invoices[0]->open(), '0', 2));
        $this->assertTies();
    }

    public function testSettle_KeepsTheNoteOfWhatIsLeftOfAnApplicationWhenTheCreditShrinks(): void
    {
        $credit = $this->creditOf('60');
        $note   = $this->raise(['amount' => '100']);

        $this->assertSame(['opening_credit:' . $credit . '->note' . $note . ':60.00'], $this->spent());

        $journal = $this->accountingDb()->table('accounting_journals')->where(['source_type' => CustomerOpening::SOURCE, 'source_id' => $this->customerId, 'status' => 'posted'])->get()->getRow();

        $this->accountingDb()->table('accounting_journal_lines')->where('journal_id', $journal->journal_id)->update(['debit' => '0.0000']);
        $this->accountingDb()->query('UPDATE ' . $this->accountingDb()->prefixTable('accounting_journal_lines') . " SET credit = '40.0000' WHERE journal_id = ? AND credit > 0", [$journal->journal_id]);
        $this->accountingDb()->query('UPDATE ' . $this->accountingDb()->prefixTable('accounting_journal_lines') . " SET debit = '40.0000' WHERE journal_id = ? AND credit = 0", [$journal->journal_id]);

        (new CreditSettlement())->settle($this->customerId, $this->clerk);

        $this->assertSame(['opening_credit:' . $credit . '->note' . $note . ':40.00'], $this->spent());
    }

    public function testSettle_SpendsTheCreditOnTheOlderInvoiceBeforeTheNewerNote(): void
    {
        $invoice = $this->invoiceDated('200', $this->daysAgo(40));
        $note    = $this->raise(['date' => $this->daysAgo(10)]);
        $credit  = $this->creditOf('60');

        $this->assertSame(['opening_credit:' . $credit . '->sale' . $invoice . ':60.00'], $this->spent());
        $this->assertGreaterThan(0, $note);
        $this->assertTies();
    }

    public function testSettle_SpendsTheCreditOnTheNoteBeforeAnInvoiceOfTheSameDayAndThenOnTheInvoice(): void
    {
        $day     = $this->daysAgo(10);
        $invoice = $this->invoiceDated('50', $day);
        $note    = $this->raise(['date' => $day]);
        $credit  = $this->creditOf('130');

        $this->assertSame(['opening_credit:' . $credit . '->note' . $note . ':100.00', 'opening_credit:' . $credit . '->sale' . $invoice . ':30.00'], $this->spent());
        $this->assertTies();
    }

    public function testSettle_DoesNotMoveCreditThatWasAlreadySpentWhenANewerOldNoteIsRaisedLater(): void
    {
        $invoice = $this->invoiceDated('200', $this->daysAgo(10));
        $credit  = $this->creditOf('60');
        $note    = $this->raise(['date' => $this->daysAgo(40)]);

        $this->assertSame(['opening_credit:' . $credit . '->sale' . $invoice . ':60.00'], $this->spent(), 'what was spent stays where it was spent');
        $this->assertGreaterThan(0, $note);
        $this->assertTies();
    }

    public function testReverse_GivesBackTheCreditThatWasSpentOnTheNoteAndSpendsItElsewhere(): void
    {
        $note    = $this->raise(['date' => $this->daysAgo(40)]);
        $invoice = $this->invoiceDated('200', $this->daysAgo(10));
        $credit  = $this->creditOf('60');

        $this->assertSame(['opening_credit:' . $credit . '->note' . $note . ':60.00'], $this->spent());

        $this->notes->reverse($note, 'undone', $this->clerk);

        $this->assertSame(['opening_credit:' . $credit . '->sale' . $invoice . ':60.00'], $this->spent());
        $this->assertTies();
    }

    public function testSettle_SpendsAPaymentOfTheCustomerOnTheNoteAndTheTripleTieHolds(): void
    {
        $invoice = $this->invoiceDated('200', $this->daysAgo(30), null, null, [$this->payment(lang('Sales.cash'), '50.00')]);
        $note    = $this->raise(['date' => $this->daysAgo(20), 'amount' => '80']);

        $position = $this->position();

        $this->assertSame('230.00', bcadd($position->open(), '0', 2));
        $this->assertSame('230.00', $this->balance($this->customerAccount()));
        $this->assertGreaterThan(0, $invoice);
        $this->assertGreaterThan(0, $note);
        $this->assertTies();
    }

    public function testSearch_ListsTheNotesOfTheBranchesOfTheEmployeeNewestFirstAndFiltersThem(): void
    {
        $first  = $this->raise(['date' => $this->daysAgo(10)]);
        $second = $this->raise(['date' => $this->daysAgo(2)]);
        $other  = $this->createBranch();
        $worker = $this->employeeWithGrants([AccountingGuard::DEBIT_NOTES_MANAGE], [$other]);
        $third  = $this->raise(['branch_id' => $other], $worker);

        $this->notes->reverse($first, 'for the filter', $this->clerk);

        $everything = $this->notes->search([], $this->clerk);

        $this->assertSame(3, $everything['total']);
        $this->assertSame([$second, $third, $first], array_map(static fn (object $row): int => (int) $row->debit_note_id, $everything['rows']));

        $scoped = $this->notes->search([], $worker);

        $this->assertSame([$third], array_map(static fn (object $row): int => (int) $row->debit_note_id, $scoped['rows']));
        $this->assertSame([$first], array_map(static fn (object $row): int => (int) $row->debit_note_id, $this->notes->search(['status' => 'reversed'], $this->clerk)['rows']));
        $this->assertSame([$second], array_map(static fn (object $row): int => (int) $row->debit_note_id, $this->notes->search(['from' => $this->daysAgo(3), 'to' => $this->daysAgo(1), 'status' => 'posted'], $this->clerk)['rows']));
        $this->assertSame(3, $this->notes->search(['search' => 'Omar'], $this->clerk)['total']);
        $this->assertSame(0, $this->notes->search(['search' => 'nobody-by-this-name'], $this->clerk)['total']);
        $this->assertSame(0, $this->notes->search([], $this->employeeWithGrants([], []))['total']);
    }

    public function testReverse_IsTheOnlyWayToTakeBackTheJournalOfANoteSoTheJournalScreenRefusesToReverseIt(): void
    {
        $id      = $this->raise();
        $journal = (int) $this->accountingDb()->table('accounting_journals')->where(['source_type' => SalesDebitNotes::SOURCE, 'source_id' => $id])->get()->getRow()->journal_id;
        $manager = $this->employeeWithGrants(['accounting_journals', AccountingGuard::JOURNALS_DRAFT, AccountingGuard::JOURNALS_POST, AccountingGuard::JOURNALS_REVERSE, AccountingGuard::JOURNALS_ALL]);

        $this->assertRefusedWith(AccountingError::DocumentJournalLocked, fn () => (new JournalService())->reverse($journal, date('Y-m-d'), 'by hand', $manager));
        $this->assertSame('posted', $this->noteRow($id)->status);
        $this->assertSame('100.00', $this->balance($this->customerAccount()));
    }

    public function testKeyOf_NumbersANotePastTheOpeningsAndIdOfKeyTakesItBack(): void
    {
        $this->assertSame(-1_000_000_007, SalesDebitNotes::keyOf(7));
        $this->assertSame(7, SalesDebitNotes::idOfKey(-1_000_000_007));
        $this->assertNull(SalesDebitNotes::idOfKey(-12));
        $this->assertNull(SalesDebitNotes::idOfKey(12));
    }

    public function testCreate_DoesNotHoldANoteToTheCreditLimitOfTheCustomerButTheNextSaleIsRefusedBecauseOfIt(): void
    {
        $this->accountingDb()->table('customers')->where('person_id', $this->customerId)->update(['credit_limit' => '100']);
        $this->setConfigValue(SalesControlSettings::CREDIT_LIMIT, '1');

        $note = $this->raise(['amount' => '300']);

        $this->assertGreaterThan(0, $note, 'A debit note is not a sale, so the limit does not stop it');
        $this->assertSame('300.00', bcadd($this->position()->open(), '0', 2));

        $this->assertSame(ACCOUNTING_FAILED, $this->invoiceOf('10.00'));

        $failure = $this->failure();

        $this->assertSame(AccountingError::CreditLimitExceeded, $failure->error);
        $this->assertSame(['100.00', '310.00'], [$failure->context['limit'], $failure->context['balance']]);
        $this->assertTies();
    }

    private function ownRecordsEmployee(): int
    {
        $employee = $this->employeeWithGrants([AccountingGuard::DEBIT_NOTES_MANAGE]);

        (new RoleService())->setRecordScope($employee, 'own', null);

        return $employee;
    }

    /**
     * @param array{rows: list<object>} $result
     *
     * @return list<int>
     */
    private function idsOf(array $result): array
    {
        $ids = array_map(static fn (object $row): int => (int) $row->debit_note_id, $result['rows']);

        sort($ids);

        return $ids;
    }

    public function testSearch_ListsOnlyTheNotesTheEmployeeRaisedWhenTheEmployeeSeesTheirOwnRecords(): void
    {
        $viewer = $this->ownRecordsEmployee();
        $mine   = $this->raise([], $viewer);
        $theirs = $this->raise();
        $result = $this->notes->search([], $viewer);

        $this->assertSame([$mine], $this->idsOf($result));
        $this->assertSame(1, $result['total'], 'the count and the rows are cut alike');
        $this->assertSame([$mine], $this->idsOf($this->notes->search(['search' => 'Omar'], $viewer)));
        $this->assertSame([$mine, $theirs], $this->idsOf($this->notes->search([], $this->clerk)), 'whoever sees every record still sees every note');
    }

    public function testSearch_ListsOnlyTheOwnNotesWhenARoleOfTheEmployeeSeesTheOwnRecords(): void
    {
        $viewer = $this->employeeWithRoles([$this->createRole(['accounting_debit_notes'], 'own')], [AccountingGuard::DEBIT_NOTES_MANAGE, BranchGuard::ALL_BRANCHES]);
        $mine   = $this->raise([], $viewer);

        $this->raise();

        $this->assertSame([$mine], $this->idsOf($this->notes->search([], $viewer)));
    }

    public function testSearch_ListsNothingToAnEmployeeWhoSeesTheirOwnRecordsAndRaisedNoNote(): void
    {
        $this->raise();

        $result = $this->notes->search([], $this->ownRecordsEmployee());

        $this->assertSame(0, $result['total']);
        $this->assertSame([], $result['rows']);
    }

    public function testSearch_ListsEveryNoteWhenTheSystemAsksWithoutAnEmployee(): void
    {
        $first  = $this->raise();
        $second = $this->raise([], $this->ownRecordsEmployee());

        $this->assertSame([$first, $second], $this->idsOf($this->notes->search([], null)));
    }

    public function testReverse_RefusesAnotherEmployeesNoteAsOneThatDoesNotExistWhenTheEmployeeSeesTheirOwnRecords(): void
    {
        $id       = $this->raise();
        $stranger = $this->ownRecordsEmployee();
        $balance  = $this->balance($this->customerAccount());

        $this->assertRefusedWith(AccountingError::DebitNoteNotFound, fn () => $this->notes->reverse($id, 'by a stranger', $stranger));

        $this->assertSame('posted', $this->noteRow($id)->status);
        $this->assertSame($balance, $this->balance($this->customerAccount()));
        $this->assertSame(0, $this->accountingDb()->table('accounting_audit_log')->where(['entity_type' => 'sales_debit_note', 'entity_id' => $id, 'action' => 'reversed'])->countAllResults());
    }

    public function testReverse_TakesBackTheOwnNoteOfAnEmployeeWhoSeesTheirOwnRecords(): void
    {
        $owner = $this->ownRecordsEmployee();
        $id    = $this->raise([], $owner);

        $this->notes->reverse($id, 'entered twice', $owner);

        $this->assertSame('reversed', $this->noteRow($id)->status);
        $this->assertSame('0.00', $this->balance($this->customerAccount()));
    }

    public function testReverse_TakesBackTheNoteOfAnotherEmployeeWhenTheEmployeeSeesEveryRecord(): void
    {
        $id       = $this->raise([], $this->ownRecordsEmployee());
        $reviewer = $this->employeeWithGrants([AccountingGuard::DEBIT_NOTES_MANAGE]);

        $this->notes->reverse($id, 'checked and refused', $reviewer);

        $this->assertSame('reversed', $this->noteRow($id)->status);
    }

    public function testReverse_RefusesAnotherEmployeesNoteWhenARoleOfTheEmployeeSeesTheOwnRecords(): void
    {
        $id       = $this->raise();
        $stranger = $this->employeeWithRoles([$this->createRole(['accounting_debit_notes'], 'own')], [AccountingGuard::DEBIT_NOTES_MANAGE, BranchGuard::ALL_BRANCHES]);

        $this->assertRefusedWith(AccountingError::DebitNoteNotFound, fn () => $this->notes->reverse($id, 'by a stranger', $stranger));
        $this->assertSame('posted', $this->noteRow($id)->status);
    }

    public function testFind_HidesAnotherEmployeesNoteFromAnEmployeeWhoSeesTheirOwnRecords(): void
    {
        $viewer = $this->ownRecordsEmployee();
        $mine   = $this->raise([], $viewer);
        $theirs = $this->raise();

        $this->assertNull($this->notes->find($theirs, $viewer));
        $this->assertSame($mine, (int) $this->notes->find($mine, $viewer)->debit_note_id);
        $this->assertSame($theirs, (int) $this->notes->find($theirs, $this->clerk)->debit_note_id);
        $this->assertSame($theirs, (int) $this->notes->find($theirs)->debit_note_id, 'the system reads every note');
    }
}
