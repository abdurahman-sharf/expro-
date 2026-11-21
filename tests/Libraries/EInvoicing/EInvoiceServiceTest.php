<?php

namespace Tests\Libraries\EInvoicing;

use App\Libraries\Accounting\AccountingError;
use App\Libraries\Accounting\AccountingException;
use App\Libraries\Accounting\AccountingGuard;
use App\Libraries\Accounting\SalesDebitNotes;
use App\Libraries\EInvoicing\EInvoiceError;
use App\Libraries\EInvoicing\EInvoiceException;
use App\Libraries\EInvoicing\EInvoiceKind;
use App\Libraries\EInvoicing\EInvoiceService;
use App\Libraries\EInvoicing\EInvoiceSettings;
use App\Libraries\EInvoicing\EInvoiceSubtype;
use App\Libraries\EInvoicing\EInvoiceTransport;
use App\Libraries\EInvoicing\InvoiceHash;
use App\Libraries\EInvoicing\IssuedEInvoice;
use App\Libraries\EInvoicing\StampVerifier;
use App\Libraries\EInvoicing\SubmissionService;
use App\Libraries\EInvoicing\TransportResult;
use App\Libraries\Numbering\DocumentNumbering;
use App\Libraries\Numbering\DocumentType;
use App\Libraries\Organization\BranchError;
use App\Libraries\Organization\BranchException;
use App\Libraries\Accounting\CustomerAdvances;
use App\Libraries\Tax\LineVat;
use App\Libraries\Tax\VatCategoryTotal;
use App\Libraries\Tax\VatLineResult;
use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\DatabaseTestTrait;
use Config\Database;
use Tests\Support\AccountingFixtureTrait;
use Tests\Support\ConcurrentDbRaceTrait;
use Tests\Support\DatabaseFingerprintTrait;
use Tests\Support\EInvoiceFixtureTrait;
use Tests\Support\EInvoiceSaleFixtureTrait;
use Tests\Support\EmployeeFixtureTrait;
use Tests\Support\ItemFixtureTrait;
use Tests\Support\SaleAccountingFixtureTrait;
use Tests\Support\SaudiSaleFixtureTrait;
use Tests\Support\WorkerRequestTrait;

/**
 * Issuing the documents: the counter and the chain of hashes, the document that is stored once and never
 * changed, the credit note that refers to its invoice, the tie to the journals, the permission, the branch,
 * the transport that sends nothing, and the rule that nothing of it touches the books.
 */
class EInvoiceServiceTest extends CIUnitTestCase
{
    use DatabaseTestTrait;
    use AccountingFixtureTrait;
    use EmployeeFixtureTrait;
    use ItemFixtureTrait;
    use SaleAccountingFixtureTrait;
    use EInvoiceFixtureTrait;
    use EInvoiceSaleFixtureTrait;
    use SaudiSaleFixtureTrait;
    use ConcurrentDbRaceTrait;
    use DatabaseFingerprintTrait;
    use WorkerRequestTrait;

    protected $migrate     = true;
    protected $migrateOnce = true;
    protected $refresh     = false;
    protected $namespace   = null;

    private EInvoiceService $service;
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
        $this->configureEInvoicing();

        $this->service = new EInvoiceService();
        $this->clerk   = $this->employeeWithGrants([AccountingGuard::EINVOICING_MANAGE]);
    }

    protected function tearDown(): void
    {
        $this->leaveTheSaudiRules();
        $this->accountingDb()->table('sales_items_vat')->truncate();
        $this->accountingDb()->table('sales_vat_totals')->truncate();
        $this->accountingDb()->table('customer_advance_vat_uses')->truncate();
        $this->accountingDb()->table('customer_advance_vat')->truncate();
        $this->accountingDb()->table('company_tax_profile')->update(['ksa_mode' => 0, 'ksa_mode_effective_at' => null]);
        $this->clearEInvoices();
        $this->restoreEInvoicingConfig();
        $this->accountingDb()->table('document_numbers')->truncate();
        $this->accountingDb()->table('document_sequences')->truncate();
        $this->accountingDb()->table('app_config')->where('key', 'accounting_debit_note')->delete();
        $this->removeTemporaryDirectories();
        $this->stopSelling();

        parent::tearDown();
    }

    private function refusal(callable $action): EInvoiceException
    {
        try {
            $action();
        } catch (EInvoiceException $failure) {
            return $failure;
        }

        $this->fail('The document was not refused.');
    }

    private function counter(): array
    {
        $row = $this->accountingDb()->table('einvoice_counters')->where('company_id', 1)->get()->getRowArray();

        return ['icv' => (int) $row['last_icv'], 'hash' => (string) $row['last_hash']];
    }

    private function issue(?int $saleId = null, ?EInvoiceSubtype $subtype = null): IssuedEInvoice
    {
        return $this->service->issueSale($saleId ?? $this->referenceSale(), $subtype, '', $this->clerk);
    }

    private function useKeys(): void
    {
        $this->skipWithoutTheCurve();

        $pair = $this->generateKeyPair();

        $this->configureEInvoicing(['einvoice_key_path' => $pair['key'], 'einvoice_certificate_path' => $pair['certificate']]);
    }

    public function testIssueSale_StoresTheDocumentWithTheFirstCounterAndTheFirstPreviousHash(): void
    {
        $document = $this->issue();

        $this->assertSame(1, $document->icv);
        $this->assertSame(InvoiceHash::FIRST, $document->previousHash);
        $this->assertSame(EInvoiceKind::Invoice, $document->kind);
        $this->assertSame('sale', $document->sourceType);
        $this->assertSame('816.50', $document->taxInclusive);
        $this->assertSame('106.50', $document->taxAmount);
        $this->assertSame('710.00', $document->taxExclusive);
        $this->assertFalse($document->signed);
        $this->assertMatchesRegularExpression('/^[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/', $document->uuid);
        $this->assertSame(['icv' => 1, 'hash' => $document->hash], $this->counter());
    }

    public function testIssueSale_StoresTheFileAndItsHashAsTheStandardTakesIt(): void
    {
        $document = $this->issue();

        $this->assertSame(InvoiceHash::of($document->xml), $document->hash);
        $this->assertStringContainsString('<cbc:ID>ICV</cbc:ID><cbc:UUID>1</cbc:UUID>', $document->xml);
        $this->assertStringContainsString('<cbc:UUID>' . $document->uuid . '</cbc:UUID>', $document->xml);
        $this->assertStringContainsString($document->previousHash, $document->xml);
        $this->assertStringContainsString($document->qr, $document->xml);
        $this->assertNotSame('', $document->qr);
    }

    public function testIssueSale_ChainsTheHashOfEachDocumentToTheNextAndCountsWithoutGaps(): void
    {
        $first  = $this->issue();
        $second = $this->issue($this->referenceSale());
        $third  = $this->issue($this->counterSale());

        $this->assertSame([1, 2, 3], [$first->icv, $second->icv, $third->icv]);
        $this->assertSame(InvoiceHash::FIRST, $first->previousHash);
        $this->assertSame($first->hash, $second->previousHash);
        $this->assertSame($second->hash, $third->previousHash);
        $this->assertSame(['icv' => 3, 'hash' => $third->hash], $this->counter());
        $this->assertCount(3, array_unique([$first->hash, $second->hash, $third->hash]));
    }

    public function testIssueSale_IssuesASaleOnceAndGivesTheSameDocumentTheSecondTime(): void
    {
        $saleId = $this->referenceSale();
        $first  = $this->issue($saleId);
        $again  = $this->issue($saleId);

        $this->assertSame($first->documentId, $again->documentId);
        $this->assertSame($first->xml, $again->xml);
        $this->assertSame(1, $this->counter()['icv']);
        $this->assertSame(1, $this->countRows('einvoice_documents'));
        $this->assertSame(1, $this->countRows('einvoice_transport_log'));
    }

    public function testIssueSale_NeverChangesTheStoredDocumentWhenTheBooksChangeAfterwards(): void
    {
        $saleId = $this->referenceSale();
        $first  = $this->issue($saleId);

        $this->assertTrue($this->service->inSync($first));

        $cycle = $this->referenceInvoice();
        $this->save($cycle['items'], $cycle['taxes'], [], SALE_TYPE_INVOICE, -1, true, null, COMPLETED, $saleId);

        $this->accountingDb()->table('accounting_journal_lines')->where('journal_id', $this->standing($saleId, 'revenue')[0]['id'])->limit(1)->update(['debit' => '900.00']);

        $stored = $this->service->find($first->documentId);

        $this->assertSame($first->xml, $stored->xml);
        $this->assertSame($first->hash, $stored->hash);
        $this->assertSame($first->taxInclusive, $stored->taxInclusive);
        $this->assertFalse($this->service->inSync($stored));
        $this->assertSame($first->documentId, $this->issue($saleId)->documentId);
    }

    public function testSource_HoldsNoStatementThatChangesOrDeletesAnIssuedDocument(): void
    {
        foreach (glob(APPPATH . 'Libraries/EInvoicing/*.php') ?: [] as $file) {
            $source = (string) file_get_contents($file);

            $this->assertDoesNotMatchRegularExpression("/table\\('einvoice_documents'\\)[^;]*->(update|delete|replace|truncate|updateBatch|upsert)\\(/s", $source, basename($file));
            $this->assertDoesNotMatchRegularExpression('/(UPDATE|DELETE FROM|REPLACE INTO|TRUNCATE)\s+`?\{?\$?[a-z_>\-]*einvoice_documents/i', $source, basename($file));
        }
    }

    public function testIssueSale_IssuesTheCreditNoteOfAReturnReferringToTheInvoiceItTakesBack(): void
    {
        $cycle   = $this->referenceInvoice();
        $invoice = $this->save($cycle['items'], $cycle['taxes']);
        $this->nameItem($cycle['cream'], 'Cream');
        $this->nameItem($cycle['perfume'], 'Perfume');
        $return = $this->perfumeReturn($invoice, $cycle['perfume']);

        $issued = $this->issue($invoice);
        $note   = $this->service->issueSale($return, null, 'In case of goods or services refund.', $this->clerk);

        $this->assertSame(EInvoiceKind::CreditNote, $note->kind);
        $this->assertSame($issued->documentId, $note->originalDocumentId);
        $this->assertSame($issued->subtype, $note->subtype);
        $this->assertSame('In case of goods or services refund.', $note->reason);
        $this->assertSame('253.00', $note->taxInclusive);
        $this->assertSame(2, $note->icv);
        $this->assertSame($issued->hash, $note->previousHash);
        $this->assertStringContainsString('<cac:BillingReference><cac:InvoiceDocumentReference><cbc:ID>' . $issued->number . '</cbc:ID>', $note->xml);
        $this->assertStringContainsString('<cbc:InvoiceTypeCode name="0200000">381</cbc:InvoiceTypeCode>', $note->xml);
        $this->assertStringContainsString('<cbc:InstructionNote>In case of goods or services refund.</cbc:InstructionNote>', $note->xml);
    }

    public function testIssueSale_RefusesACreditNoteWhoseInvoiceWasNeverIssuedAndLeavesTheCounterAlone(): void
    {
        $cycle   = $this->referenceInvoice();
        $invoice = $this->save($cycle['items'], $cycle['taxes']);
        $return  = $this->perfumeReturn($invoice, $cycle['perfume']);

        $refusal = $this->refusal(fn () => $this->service->issueSale($return, null, 'a reason', $this->clerk));

        $this->assertSame(EInvoiceError::OriginalNotIssued, $refusal->error);
        $this->assertSame(0, $this->counter()['icv']);
        $this->assertSame(0, $this->countRows('einvoice_documents'));
    }

    public function testIssueSale_RefusesACreditNoteWithoutAReason(): void
    {
        $cycle   = $this->referenceInvoice();
        $invoice = $this->save($cycle['items'], $cycle['taxes']);
        $return  = $this->perfumeReturn($invoice, $cycle['perfume']);

        $this->issue($invoice);

        $this->assertSame(EInvoiceError::ReasonRequired, $this->refusal(fn () => $this->service->issueSale($return, null, '', $this->clerk))->error);
        $this->assertSame(1, $this->counter()['icv']);
    }

    public function testIssueDebitNote_IssuesANoteThatRefersToAnIssuedInvoice(): void
    {
        $this->setConfigValue('accounting_debit_note', '1');

        $invoice = $this->issue();
        $clerk   = $this->employeeWithGrants([AccountingGuard::DEBIT_NOTES_MANAGE, AccountingGuard::EINVOICING_MANAGE]);
        $noteId  = (new SalesDebitNotes())->create(['customer_id' => $this->customerId, 'date' => $this->daysAgo(1), 'amount' => '200', 'tax_percent' => '15', 'note' => 'Freight recovered'], $clerk);

        $note = $this->service->issueDebitNote($noteId, 'Amendment of the supply price which leads to the change of VAT', $invoice->documentId, $clerk);

        $this->assertSame(EInvoiceKind::DebitNote, $note->kind);
        $this->assertSame('sales_debit_note', $note->sourceType);
        $this->assertSame($invoice->documentId, $note->originalDocumentId);
        $this->assertSame('230.00', $note->taxInclusive);
        $this->assertSame(2, $note->icv);
        $this->assertSame($invoice->hash, $note->previousHash);
        $this->assertStringContainsString('<cbc:InvoiceTypeCode name="0200000">383</cbc:InvoiceTypeCode>', $note->xml);
        $this->assertSame($note->documentId, $this->service->issueDebitNote($noteId, 'again', $invoice->documentId, $clerk)->documentId);
    }

    public function testIssueDebitNote_RefusesANoteWhoseTaxTheJournalOfTheNoteDoesNotHoldAndStoresNothing(): void
    {
        $this->setConfigValue('accounting_debit_note', '1');

        $invoice = $this->issue();
        $clerk   = $this->employeeWithGrants([AccountingGuard::DEBIT_NOTES_MANAGE, AccountingGuard::EINVOICING_MANAGE]);
        $noteId  = (new SalesDebitNotes())->create(['customer_id' => $this->customerId, 'date' => $this->daysAgo(1), 'amount' => '200', 'tax_percent' => '15', 'note' => 'Freight recovered'], $clerk);
        $journal = $this->accountingDb()->table('accounting_journals')->where(['source_type' => SalesDebitNotes::SOURCE, 'source_id' => $noteId])->get()->getRow();

        $this->accountingDb()->table('accounting_journal_lines')->where(['journal_id' => (int) $journal->journal_id, 'credit' => '30.0000'])->update(['credit' => '29.0000']);

        $refusal = $this->refusal(fn () => $this->service->issueDebitNote($noteId, 'Amendment of the supply price which leads to the change of VAT', $invoice->documentId, $clerk));

        $this->assertSame('journal tax', $refusal->context['rule']);
        $this->assertSame(1, $this->countRows('einvoice_documents'));
        $this->assertSame(1, $this->counter()['icv']);
    }

    public function testIssueDebitNote_RefusesANoteThatRefersToNothingOrToAnotherDebitNote(): void
    {
        $this->setConfigValue('accounting_debit_note', '1');

        $clerk  = $this->employeeWithGrants([AccountingGuard::DEBIT_NOTES_MANAGE, AccountingGuard::EINVOICING_MANAGE]);
        $notes  = new SalesDebitNotes();
        $first  = $notes->create(['customer_id' => $this->customerId, 'date' => $this->daysAgo(1), 'amount' => '200', 'tax_percent' => '15', 'note' => 'a'], $clerk);
        $second = $notes->create(['customer_id' => $this->customerId, 'date' => $this->daysAgo(1), 'amount' => '50', 'tax_percent' => '15', 'note' => 'b'], $clerk);

        $this->assertSame(EInvoiceError::OriginalNotIssued, $this->refusal(fn () => $this->service->issueDebitNote($first, 'reason', 987654, $clerk))->error);

        $invoice   = $this->issue();
        $debitNote = $this->service->issueDebitNote($first, 'reason', $invoice->documentId, $clerk);

        $this->assertSame(EInvoiceError::OriginalNotIssued, $this->refusal(fn () => $this->service->issueDebitNote($second, 'reason', $debitNote->documentId, $clerk))->error);
    }

    public function testIssueSale_TiesTheDocumentToTheJournalsOfTheSale(): void
    {
        $document = $this->issue();
        $journal  = $this->standing((int) $document->sourceId, 'revenue')[0];
        $lines    = array_map(static fn (string $line): array => explode(':', $line), $journal['lines']);
        $debit    = array_sum(array_map(static fn (array $line): float => (float) $line[1], $lines));

        $this->assertSame('816.50', number_format($debit, 2, '.', ''));
        $this->assertSame($document->taxInclusive, number_format($debit, 2, '.', ''));
        $this->assertContains((string) $this->customerAccount() . ':816.50:0.00', $journal['lines']);
    }

    public function testIssueSale_RefusesASaleWhoseJournalIsNoLongerPosted(): void
    {
        $saleId = $this->referenceSale();

        $this->accountingDb()->table('accounting_journals')->where(['source_type' => 'sale', 'source_id' => $saleId])->update(['status' => 'reversed']);

        $this->assertSame(EInvoiceError::SourceNotPosted, $this->refusal(fn () => $this->issue($saleId))->error);
        $this->assertSame(0, $this->counter()['icv']);
    }

    public function testIssueSale_RefusesADocumentThatDoesNotTieToTheJournalAndStoresNothing(): void
    {
        $saleId  = $this->referenceSale();
        $journal = $this->standing($saleId, 'revenue')[0];

        $this->accountingDb()->table('accounting_journal_lines')->where(['journal_id' => $journal['id'], 'account_id' => $this->customerAccount()])->update(['debit' => '816.51']);

        $refusal = $this->refusal(fn () => $this->issue($saleId));

        $this->assertSame(EInvoiceError::TotalsDoNotTie, $refusal->error);
        $this->assertSame('journal total', $refusal->context['rule']);
        $this->assertSame(0, $this->countRows('einvoice_documents'));
        $this->assertSame(0, $this->counter()['icv']);
    }

    public function testIssueSale_StatesTheRoundingThatTheJournalOfASaudiSalePutsOnTheAccountOfTaxRounding(): void
    {
        $vat   = new LineVat(null, 'S', '15.000', null, null, 'VAT');
        $items = [];
        $taxes = [];

        for ($line = 0; $line < 10; $line++) {
            $itemId  = $this->item('0', false);
            $items[] = $this->line($itemId, $line, '1', '0.99');
            $taxes[] = [...$this->taxRow($itemId, $line, 'VAT', '15.0000', 0, '0.129000'), 'vat' => $vat, 'vat_amounts' => new VatLineResult('0.86', '0.13', '0.99', '0.129000', 'S|15.000|')];
        }

        $status     = COMPLETED;
        $salesTaxes = [[], $taxes, ['S|15.000|' => new VatCategoryTotal('S', '15.000', null, null, '8.60', '1.29', true)]];
        $saleId     = $this->sales->save_value(NEW_ENTRY, $status, $items, $this->customerId, $this->employeeId, 'worked once', null, null, null, SALE_TYPE_INVOICE, [], null, $salesTaxes, true);
        $this->saleIds[] = $saleId;

        $this->assertSame([$this->customerAccount() . ':9.90:0.00', $this->accountId('sales_revenue') . ':0.00:8.60', $this->accountId('tax_output') . ':0.00:1.29', $this->accountId('tax_rounding') . ':0.00:0.01'], $this->standing($saleId, 'revenue')[0]['lines']);

        $issued = $this->issue($saleId);

        $this->assertSame(['8.60', '1.29', '9.89'], [$issued->taxExclusive, $issued->taxAmount, $issued->taxInclusive]);
        $this->assertStringContainsString('<cbc:PayableRoundingAmount currencyID="SAR">0.01</cbc:PayableRoundingAmount><cbc:PayableAmount currencyID="SAR">9.90</cbc:PayableAmount>', $issued->xml);
    }

    public function testIssueSale_IssuesASaleWithoutItsVatWorkedOnceWhenTheRoundingAccountIsNotRouted(): void
    {
        $saleId = $this->referenceSale();
        $this->accountingDb()->table('accounting_routing')->where('routing_key', 'tax.rounding')->delete();

        try {
            $this->assertSame($saleId, $this->issue($saleId)->sourceId);
        } finally {
            $this->accountingDb()->table('accounting_routing')->insert(['routing_key' => 'tax.rounding', 'routing_type' => 'auto', 'account_id' => $this->accountId('tax_rounding')]);
        }
    }

    private function advanceOf(string $amount): int
    {
        $receiver = $this->employeeWithGrants([AccountingGuard::RECEIVABLES_MANAGE, AccountingGuard::TREASURIES_MANAGE]);

        return (new CustomerAdvances())->receive($this->customerId, $amount, date('Y-m-d'), 'Cash', null, '', '', $receiver);
    }

    public function testIssueAdvance_IssuesThePrepaymentInvoiceOfAnAdvanceReceivedUnderTheSaudiRules(): void
    {
        $this->accountingDb()->table('company_tax_profile')->update(['ksa_mode' => 1, 'ksa_mode_effective_at' => '2020-01-01 00:00:00']);

        $advanceId = $this->advanceOf('115.00');
        $issued    = $this->service->issueAdvance($advanceId, null, $this->clerk);

        $this->assertSame(EInvoiceKind::Prepayment, $issued->kind);
        $this->assertSame(['100.00', '15.00', '115.00'], [$issued->taxExclusive, $issued->taxAmount, $issued->taxInclusive]);
        $this->assertStringContainsString('>386</cbc:InvoiceTypeCode>', $issued->xml);
        $this->assertSame($issued->documentId, $this->service->issueAdvance($advanceId, null, $this->clerk)->documentId, 'issued once');
    }

    public function testIssueAdvance_RefusesAnAdvanceThatCarriesNoVat(): void
    {
        $advanceId = $this->advanceOf('115.00');

        $this->assertSame(EInvoiceError::SourceNotIssuable, $this->refusal(fn () => $this->service->issueAdvance($advanceId, null, $this->clerk))->error);
    }

    public function testIssueSale_TakesTheAdvancesItWasPaidByOffWhatIsDue(): void
    {
        $this->accountingDb()->table('company_tax_profile')->update(['ksa_mode' => 1, 'ksa_mode_effective_at' => '2020-01-01 00:00:00']);

        $advanceId = $this->advanceOf('115.00');
        $prepaid   = $this->service->issueAdvance($advanceId, null, $this->clerk);
        $itemId    = $this->item('0', false);
        $vat       = new LineVat(null, 'S', '15.000', null, null, 'VAT');
        $status    = COMPLETED;
        $taxes     = [[], [[...$this->taxRow($itemId, 0, 'VAT', '15.0000', 1, '30.000000'), 'vat' => $vat, 'vat_amounts' => new VatLineResult('200.00', '30.00', '230.00', '30.000000', 'S|15.000|')]], ['S|15.000|' => new VatCategoryTotal('S', '15.000', null, null, '200.00', '30.00', false)]];
        $items     = [$this->line($itemId, 0, '1', '200.00')];
        $saleId    = $this->sales->save_value(NEW_ENTRY, $status, $items, $this->customerId, $this->employeeId, 'final', null, null, null, SALE_TYPE_INVOICE, [], null, $taxes, true);
        $this->saleIds[] = $saleId;

        $issued = $this->issue($saleId);

        $this->assertSame(['200.00', '30.00', '230.00'], [$issued->taxExclusive, $issued->taxAmount, $issued->taxInclusive]);
        $this->assertStringContainsString('<cbc:PrepaidAmount currencyID="SAR">115.00</cbc:PrepaidAmount>', $issued->xml);
        $this->assertStringContainsString('<cbc:PayableAmount currencyID="SAR">115.00</cbc:PayableAmount>', $issued->xml);
        $this->assertStringContainsString('<cbc:ID>' . $prepaid->number . '</cbc:ID><cbc:IssueDate>' . $prepaid->issueDate . '</cbc:IssueDate>', $issued->xml);
    }

    public function testIssueForCompletedSale_IssuesTheSimplifiedInvoiceOfASaleAtTheRegisterWithoutAPermission(): void
    {
        $saleId = $this->saudiSaleOf('100.00', null, [$this->payment('Cash', '115.00')], SALE_TYPE_POS);
        $issued = $this->service->issueForCompletedSale($saleId, $this->employeeWithGrants([]));

        $this->assertSame(EInvoiceKind::Invoice, $issued->kind);
        $this->assertSame(EInvoiceSubtype::Simplified, $issued->subtype);
        $this->assertSame('POS ' . $saleId, $issued->number, 'a sale at the register is numbered as its receipt');
        $this->assertSame(['100.00', '15.00', '115.00'], [$issued->taxExclusive, $issued->taxAmount, $issued->taxInclusive]);
    }

    public function testIssueForCompletedSale_RefusesASaleThatAlreadyHasADocument(): void
    {
        $saleId = $this->saudiSaleOf('100.00', null, [$this->payment('Cash', '115.00')], SALE_TYPE_POS);

        $this->service->issueForCompletedSale($saleId);

        $this->assertSame(EInvoiceError::AlreadyIssued, $this->refusal(fn () => $this->service->issueForCompletedSale($saleId))->error);
        $this->assertSame(1, $this->accountingDb()->table('einvoice_documents')->where(['source_type' => EInvoiceService::SOURCE_SALE, 'source_id' => $saleId])->countAllResults());
    }

    public function testIssueForCompletedSale_RefersTheCreditNoteOfAReturnToThePrintedNumberOfASaleMadeBeforeTheSaudiRules(): void
    {
        $cycle    = $this->referenceInvoice();
        $saleId   = $this->save($cycle['items'], $cycle['taxes'], [$this->payment('Cash', '816.50')], SALE_TYPE_POS, null);
        $returnId = $this->perfumeReturn($saleId, $cycle['perfume']);

        $this->accountingDb()->table('sales')->where('sale_id', $saleId)->update(['sale_time' => '2019-06-01 10:00:00']);
        $this->accountingDb()->table('company_tax_profile')->update(['ksa_mode' => 1, 'ksa_mode_effective_at' => '2020-01-01 00:00:00']);

        $issued = $this->service->issueForCompletedSale($returnId);
        $row    = $this->accountingDb()->table('einvoice_documents')->where('document_id', $issued->documentId)->get()->getRow();

        $this->assertSame(EInvoiceKind::CreditNote, $issued->kind);
        $this->assertSame(EInvoiceSubtype::Simplified, $issued->subtype, 'the kind of the sale it takes back');
        $this->assertNull($issued->originalDocumentId);
        $this->assertSame(['POS ' . $saleId, (string) $saleId], [$row->original_reference, (string) $row->original_sale_id]);
        $this->assertStringContainsString('<cbc:ID>POS ' . $saleId . '</cbc:ID>', $issued->xml);
    }

    public function testIssueForCompletedSale_RefusesAReturnOfASaleMadeUnderTheSaudiRulesThatHasNoDocument(): void
    {
        $cycle    = $this->referenceInvoice();
        $saleId   = $this->save($cycle['items'], $cycle['taxes'], [$this->payment('Cash', '816.50')], SALE_TYPE_POS, null);
        $returnId = $this->perfumeReturn($saleId, $cycle['perfume']);

        $this->accountingDb()->table('company_tax_profile')->update(['ksa_mode' => 1, 'ksa_mode_effective_at' => '2020-01-01 00:00:00']);

        $this->assertSame(EInvoiceError::OriginalNotIssued, $this->refusal(fn () => $this->service->issueForCompletedSale($returnId))->error);
    }

    public function testIssueForCompletedSale_RefusesAReturnThatTakesBackNoSale(): void
    {
        $cycle    = $this->referenceInvoice();
        $saleId   = $this->save($cycle['items'], $cycle['taxes'], [$this->payment('Cash', '816.50')], SALE_TYPE_POS, null);
        $returnId = $this->perfumeReturn($saleId, $cycle['perfume']);

        $this->accountingDb()->table('sales')->where('sale_id', $returnId)->update(['return_of_sale_id' => null]);

        $this->assertSame(EInvoiceError::ReturnWithoutOrigin, $this->refusal(fn () => $this->service->issueForCompletedSale($returnId))->error);
    }

    public function testAssertIssuable_RequiresTheBuyerOfAStandardInvoice(): void
    {
        $customerId = $this->makeBusinessCustomer();

        $this->service->assertIssuable($customerId, SALE_TYPE_INVOICE, null);

        $this->accountingDb()->table('customer_einvoice_parties')->where('person_id', $customerId)->delete();

        $this->assertSame(EInvoiceError::BuyerAddressIncomplete, $this->refusal(fn () => $this->service->assertIssuable($customerId, SALE_TYPE_INVOICE, null))->error);
        $this->service->assertIssuable(null, SALE_TYPE_POS, null);
    }

    public function testIssueSale_QueuesTheDocumentOnceTheSecondPhaseIsOnAndKeepsTheCounterOfTheUnit(): void
    {
        $first = $this->issue();
        $row   = $this->accountingDb()->table('einvoice_documents')->where('document_id', $first->documentId)->get()->getRow();

        $this->assertSame(0, $this->accountingDb()->table('einvoice_outbox')->where('document_id', $first->documentId)->countAllResults(), 'a document of the first phase is not sent');
        $this->assertNotNull($row->unit_id);

        $this->configureEInvoicing([EInvoiceSettings::PHASE_TWO => '1']);

        $second = $this->issue($this->referenceSale());
        $unitId = $this->accountingDb()->table('einvoice_documents')->where('document_id', $second->documentId)->get()->getRow()->unit_id;
        $queued = $this->accountingDb()->table('einvoice_outbox')->where('document_id', $second->documentId)->get()->getRow();
        $unit   = $this->accountingDb()->table('einvoice_unit_counters')->where(['unit_id' => $unitId, 'environment' => EInvoiceService::LIVE])->get()->getRow();

        $this->assertSame([SubmissionService::PENDING, EInvoiceService::LIVE], [$queued->status, $queued->environment]);
        $this->assertSame([(string) $second->icv, $second->hash], [(string) $unit->last_icv, $unit->last_hash], 'the unit holds the chain as it stands');
    }

    public function testIssueSale_RefusesATaxOfTheDocumentThatTheJournalDoesNotHold(): void
    {
        $saleId  = $this->referenceSale();
        $journal = $this->standing($saleId, 'revenue')[0];
        $taxLine = $this->accountingDb()->table('accounting_journal_lines')->where(['journal_id' => $journal['id']])->where('credit', '106.5000')->get()->getRow();

        $this->accountingDb()->table('accounting_journal_lines')->where('line_id', $taxLine->line_id)->update(['credit' => '106.4000']);

        $this->assertSame('journal tax', $this->refusal(fn () => $this->issue($saleId))->context['rule']);
    }

    public function testIssueSale_RefusesRevenueOfTheDocumentThatTheJournalDoesNotHold(): void
    {
        $saleId  = $this->referenceSale();
        $journal = $this->standing($saleId, 'revenue')[0];
        $revenue = $this->accountingDb()->table('accounting_journal_lines')->where(['journal_id' => $journal['id'], 'credit' => '710.0000'])->get()->getRow();

        $this->accountingDb()->table('accounting_journal_lines')->where('line_id', $revenue->line_id)->update(['credit' => '715.0000']);

        $this->assertSame('journal revenue', $this->refusal(fn () => $this->issue($saleId))->context['rule']);
    }

    public function testIssueSale_RequiresThePermissionToManageElectronicInvoicing(): void
    {
        $saleId = $this->referenceSale();
        $other  = $this->employeeWithGrants([AccountingGuard::JOURNALS_POST]);

        try {
            $this->service->issueSale($saleId, null, '', $other);
            $this->fail('A document was issued without the permission.');
        } catch (AccountingException $failure) {
            $this->assertSame(AccountingError::PermissionDenied, $failure->error);
        }

        $this->assertSame(0, $this->countRows('einvoice_documents'));
        $this->assertSame(1, $this->service->issueSale($saleId, null, '', null)->icv);
    }

    public function testIssueSale_RefusesWhileTheLayerIsOffAndWhileAccountingIsOff(): void
    {
        $saleId = $this->referenceSale();

        $this->configureEInvoicing([EInvoiceSettings::ENABLED => '0']);

        $this->assertSame(EInvoiceError::Disabled, $this->refusal(fn () => $this->issue($saleId))->error);

        $this->configureEInvoicing();
        $this->setConfigValue('accounting_enabled', '0');

        $this->assertSame(EInvoiceError::AccountingDisabled, $this->refusal(fn () => $this->issue($saleId))->error);
        $this->assertSame(0, $this->countRows('einvoice_documents'));

        $this->setConfigValue('accounting_enabled', '1');
    }

    public function testIssueSale_RefusesASaleOfABranchTheEmployeeDoesNotWorkInAndIssuesItToSomeoneWhoDoes(): void
    {
        $this->configureEInvoicing(['einvoice_exempt_category' => 'Z', 'einvoice_exempt_reason_code' => 'VATEX-SA-32']);

        $other   = $this->createBranch('B2');
        $saleId  = $this->invoiceInBranch($other, '115.00');
        $limited = $this->employeeWithGrants([AccountingGuard::EINVOICING_MANAGE], [1]);
        $inside  = $this->employeeWithGrants([AccountingGuard::EINVOICING_MANAGE], [$other]);

        try {
            $this->service->issueSale($saleId, null, '', $limited);
            $this->fail('A document of another branch was issued.');
        } catch (BranchException $failure) {
            $this->assertSame(BranchError::NotAllowed, $failure->error);
        }

        $this->assertSame(0, $this->countRows('einvoice_documents'));
        $this->assertSame(0, $this->counter()['icv']);

        $document = $this->service->issueSale($saleId, null, '', $inside);

        $this->assertSame($other, $document->branchId);
        $this->assertSame(1, $this->counter()['icv']);
    }

    public function testIssueSale_RollsTheCounterBackWhenTheDocumentIsRefused(): void
    {
        $this->configureEInvoicing(['einvoice_seller_city' => '']);

        $this->assertSame(EInvoiceError::SellerAddressIncomplete, $this->refusal(fn () => $this->issue())->error);
        $this->assertSame(['icv' => 0, 'hash' => ''], $this->counter());

        $this->configureEInvoicing();

        $this->assertSame(1, $this->issue()->icv);
    }

    public function testIssueSale_SignsADocumentWhenAKeyAndACertificateAreInPlace(): void
    {
        $this->useKeys();

        $document = $this->issue($this->counterSale());

        $this->assertTrue($document->signed);
        $this->assertSame(['signed' => true, 'document' => true, 'properties' => true, 'signature' => true], (new StampVerifier())->verify($document->xml));
        $this->assertSame(InvoiceHash::of($document->xml), $document->hash);
        $this->assertSame($document->hash, $this->counter()['hash']);
        $this->assertSame(EInvoiceSubtype::Simplified, $document->subtype);
    }

    public function testIssueSale_ChainsAfterASignedDocumentWithItsHash(): void
    {
        $this->useKeys();

        $first  = $this->issue($this->counterSale());
        $second = $this->issue($this->counterSale());

        $this->assertSame($first->hash, $second->previousHash);
        $this->assertTrue((new StampVerifier())->verify($second->xml)['signature']);
    }

    public function testIssueSale_RefusesAKeyPathInsideTheApplicationEvenWhenTheSettingWasWrittenPastTheScreen(): void
    {
        $pair = $this->generateKeyPair();

        $this->configureEInvoicing(['einvoice_key_path' => WRITEPATH . 'stolen_key.pem', 'einvoice_certificate_path' => $pair['certificate']]);

        $refusal = $this->refusal(fn () => $this->issue($this->counterSale()));

        $this->assertSame(EInvoiceError::KeyPathUnsafe, $refusal->error);
        $this->assertSame(0, $this->countRows('einvoice_documents'));
        $this->assertSame(0, $this->counter()['icv']);
    }

    public function testIssueSale_RefusesWhenOnlyTheKeyOrOnlyTheCertificateIsNamedInsteadOfIssuingUnsigned(): void
    {
        $this->skipWithoutTheCurve();

        $pair = $this->generateKeyPair();

        $this->configureEInvoicing(['einvoice_key_path' => $pair['key']]);

        $this->assertSame(EInvoiceError::KeyPathUnsafe, $this->refusal(fn () => $this->issue($this->counterSale()))->error);
        $this->assertSame(0, $this->countRows('einvoice_documents'));
    }

    public function testIssueSale_WritesTheCodeOfTheFirstPhaseWhenNoKeyIsInPlace(): void
    {
        $document = $this->issue($this->counterSale());
        $bytes    = (string) base64_decode($document->qr, true);

        $this->assertSame(1, ord($bytes[0]));
        $this->assertStringContainsString(self::SELLER_VAT, $bytes);
        $this->assertStringNotContainsString('UBLExtensions', $document->xml);
    }

    public function testIssueSale_RecordsThatNothingWasSubmittedThroughTheNullTransport(): void
    {
        $document = $this->issue();
        $log      = $this->accountingDb()->table('einvoice_transport_log')->where('document_id', $document->documentId)->get()->getResult();

        $this->assertCount(1, $log);
        $this->assertSame('null', $log[0]->transport);
        $this->assertSame('not_submitted', $log[0]->status);
        $this->assertSame('not_submitted', $this->service->submissionOf($document->documentId));
    }

    public function testSubmit_AsksTheTransportAndRecordsWhatItSaysAsOneMoreRowOfTheLog(): void
    {
        $transport = new class () implements EInvoiceTransport {
            /**
             * @var list<int>
             */
            public array $asked = [];

            public function name(): string
            {
                return 'fake';
            }

            public function submit(IssuedEInvoice $document): TransportResult
            {
                $this->asked[] = $document->documentId;

                return new TransportResult('reported', 'accepted by the fake');
            }
        };

        $service  = new EInvoiceService(null, null, null, $transport);
        $document = $service->issueSale($this->referenceSale(), null, '', $this->clerk);

        $this->assertSame('reported', $service->submissionOf($document->documentId));
        $this->assertSame('reported', $service->submit($document->documentId, $this->clerk)->status);
        $this->assertSame([$document->documentId, $document->documentId], $transport->asked);
        $this->assertSame(2, $this->countRows('einvoice_transport_log'));
        $this->assertSame('not_submitted', $this->service->submit($document->documentId, $this->clerk)->status);
        $this->assertSame('not_submitted', $this->service->submissionOf($document->documentId));

        $entry = $this->accountingDb()->table('accounting_audit_log')->where(['entity_type' => 'einvoice_document', 'entity_id' => $document->documentId, 'action' => 'submission_recorded'])->orderBy('audit_id', 'ASC')->get()->getRow();

        $this->assertSame($this->clerk, (int) $entry->employee_id);
        $this->assertSame('fake', json_decode((string) $entry->details, true)['transport']);
    }

    public function testSubmit_RequiresThePermissionAndTheBranchAndNamesNothingTheDocumentDoesNotHave(): void
    {
        $document = $this->issue();
        $other    = $this->employeeWithGrants([AccountingGuard::JOURNALS_POST]);

        try {
            $this->service->submit($document->documentId, $other);
            $this->fail('A submission was recorded without the permission.');
        } catch (AccountingException $failure) {
            $this->assertSame(AccountingError::PermissionDenied, $failure->error);
        }

        $this->assertSame(EInvoiceError::DocumentNotFound, $this->refusal(fn () => $this->service->submit(987654321, $this->clerk))->error);
    }

    public function testDefaultTransport_IsTheOneThatSendsNothing(): void
    {
        $this->assertSame('null', EInvoiceService::defaultTransport()->name());
        $this->assertSame(TransportResult::NOT_SUBMITTED, EInvoiceService::defaultTransport()->submit($this->issue())->status);
    }

    public function testXml_GivesTheDocumentToSomeoneWithThePermissionInItsBranchOnly(): void
    {
        $document = $this->issue();
        $other    = $this->employeeWithGrants([AccountingGuard::JOURNALS_POST]);
        $branch   = $this->createBranch('B3');
        $limited  = $this->employeeWithGrants([AccountingGuard::EINVOICING_MANAGE], [$branch]);

        $this->assertSame($document->xml, $this->service->xml($document->documentId, $this->clerk)->xml);

        try {
            $this->service->xml($document->documentId, $other);
            $this->fail('The file was given without the permission.');
        } catch (AccountingException $failure) {
            $this->assertSame(AccountingError::PermissionDenied, $failure->error);
        }

        $this->expectException(BranchException::class);

        $this->service->xml($document->documentId, $limited);
    }

    public function testXml_RefusesADocumentThatDoesNotExist(): void
    {
        $this->assertSame(EInvoiceError::DocumentNotFound, $this->refusal(fn () => $this->service->xml(987654321, $this->clerk))->error);
    }

    public function testIssuedIn_ListsTheDocumentsOfTheBranchesTheReaderMaySeeNewestFirst(): void
    {
        $first  = $this->issue();
        $second = $this->issue($this->counterSale());

        $this->assertSame([$second->documentId, $first->documentId], array_map(static fn (object $row): int => (int) $row->document_id, $this->service->issuedIn(null)));
        $this->assertSame([], $this->service->issuedIn([]));
        $this->assertSame([], $this->service->issuedIn([987654]));
        $this->assertCount(2, $this->service->issuedIn([1]));
    }

    public function testIssueSale_RefusesTheSaleOfAnotherEmployeeToSomeoneWhoSeesTheirOwnRecordsAsIfItDidNotExist(): void
    {
        $saleId = $this->referenceSale();
        $own    = $this->seesOwnRecordsOnly($this->employeeWithGrants([AccountingGuard::EINVOICING_MANAGE]));

        $foreign = $this->refusal(fn () => $this->service->issueSale($saleId, null, '', $own));
        $missing = $this->refusal(fn () => $this->service->issueSale(987654321, null, '', $own));

        $this->assertSame(EInvoiceError::SourceNotFound, $foreign->error);
        $this->assertSame($missing->error, $foreign->error);
        $this->assertSame(0, $this->countRows('einvoice_documents'));
        $this->assertSame(0, $this->counter()['icv']);
    }

    public function testIssueSale_IssuesTheSaleOfTheEmployeeWhoSeesTheirOwnRecordsOnly(): void
    {
        $own    = $this->seesOwnRecordsOnly($this->employeeWithGrants([AccountingGuard::EINVOICING_MANAGE]));
        $saleId = $this->ownedBy($this->referenceSale(), $own);

        $document = $this->service->issueSale($saleId, null, '', $own);

        $this->assertSame($saleId, $document->sourceId);
        $this->assertSame(1, $this->counter()['icv']);
    }

    public function testIssueSale_IssuesTheSaleOfAnotherEmployeeToWhoDoesNotSeeTheirOwnRecordsOnly(): void
    {
        $saleId = $this->referenceSale();
        $wide   = $this->employeeWithGrants([AccountingGuard::EINVOICING_MANAGE]);

        $this->assertSame($saleId, $this->service->issueSale($saleId, null, '', $wide)->sourceId);
    }

    public function testIssueDebitNote_RefusesTheNoteOfAnotherEmployeeToSomeoneWhoSeesTheirOwnRecordsAsIfItDidNotExist(): void
    {
        $invoice = $this->issue();
        $own     = $this->seesOwnRecordsOnly($this->employeeWithGrants([AccountingGuard::EINVOICING_MANAGE]));
        $noteId  = $this->debitNoteOf($this->clerk);

        $foreign = $this->refusal(fn () => $this->service->issueDebitNote($noteId, 'reason', $invoice->documentId, $own));
        $missing = $this->refusal(fn () => $this->service->issueDebitNote(987654321, 'reason', $invoice->documentId, $own));

        $this->assertSame(EInvoiceError::SourceNotFound, $foreign->error);
        $this->assertSame($missing->error, $foreign->error);
        $this->assertSame(1, $this->countRows('einvoice_documents'));
    }

    public function testIssueDebitNote_IssuesTheNoteOfTheEmployeeWhoSeesTheirOwnRecordsOnly(): void
    {
        $own     = $this->seesOwnRecordsOnly($this->employeeWithGrants([AccountingGuard::EINVOICING_MANAGE]));
        $invoice = $this->service->issueSale($this->ownedBy($this->referenceSale(), $own), null, '', $own);
        $noteId  = $this->debitNoteOf($own);

        $note = $this->service->issueDebitNote($noteId, 'reason', $invoice->documentId, $own);

        $this->assertSame($invoice->documentId, $note->originalDocumentId);
    }

    public function testIssueDebitNote_RefusesADocumentOfAnotherEmployeeToSomeoneWhoSeesTheirOwnRecordsAsIfItDidNotExist(): void
    {
        $own     = $this->seesOwnRecordsOnly($this->employeeWithGrants([AccountingGuard::EINVOICING_MANAGE]));
        $foreign = $this->issue();
        $noteId  = $this->debitNoteOf($own);

        $this->assertSame(EInvoiceError::OriginalNotIssued, $this->refusal(fn () => $this->service->issueDebitNote($noteId, 'reason', $foreign->documentId, $own))->error);
        $this->assertSame(EInvoiceError::OriginalNotIssued, $this->refusal(fn () => $this->service->issueDebitNote($noteId, 'reason', 987654321, $own))->error);
        $this->assertSame(1, $this->countRows('einvoice_documents'));
    }

    public function testIssueDebitNote_RefusesADocumentOfAnotherCustomerAndStoresNothing(): void
    {
        $other   = $this->makeBusinessCustomer($this->anotherCustomer('Layla', 'Hassan'), '322222222222223', 'Other Co');
        $invoice = $this->issue($this->referenceSale($other));
        $noteId  = $this->debitNoteOf($this->clerk);

        $refusal = $this->refusal(fn () => $this->service->issueDebitNote($noteId, 'reason', $invoice->documentId, $this->clerk));

        $this->assertSame(EInvoiceError::OriginalOfAnotherCustomer, $refusal->error);
        $this->assertSame(1, $this->countRows('einvoice_documents'));
        $this->assertSame(1, $this->counter()['icv']);
        $this->assertNull($this->service->documentOf(EInvoiceService::SOURCE_NOTE, $noteId));
    }

    public function testIssueDebitNote_RefusesAnInvoiceOfTheCounterThatHasNoCustomer(): void
    {
        $invoice = $this->issue($this->counterSale());
        $noteId  = $this->debitNoteOf($this->clerk);

        $this->assertSame(EInvoiceError::OriginalOfAnotherCustomer, $this->refusal(fn () => $this->service->issueDebitNote($noteId, 'reason', $invoice->documentId, $this->clerk))->error);
    }

    public function testIssueDebitNote_RefusesADocumentOfABranchTheEmployeeDoesNotWorkInAsIfItDidNotExist(): void
    {
        $other   = $this->createBranch('B2');
        $invoice = $this->issue();
        $noteId  = $this->debitNoteOf($this->clerk);
        $limited = $this->employeeWithGrants([AccountingGuard::EINVOICING_MANAGE], [1]);

        $this->accountingDb()->table('einvoice_documents')->where('document_id', $invoice->documentId)->update(['branch_id' => $other]);

        $this->assertSame(EInvoiceError::OriginalNotIssued, $this->refusal(fn () => $this->service->issueDebitNote($noteId, 'reason', $invoice->documentId, $limited))->error);
        $this->assertSame(1, $this->countRows('einvoice_documents'));

        $this->accountingDb()->table('einvoice_documents')->where('document_id', $invoice->documentId)->update(['branch_id' => 1]);

        $this->assertSame($invoice->documentId, $this->service->issueDebitNote($noteId, 'reason', $invoice->documentId, $limited)->originalDocumentId);
    }

    public function testIssueDebitNote_AcceptsACreditNoteOfTheSameCustomerAsTheDocumentItRefersTo(): void
    {
        $cycle   = $this->referenceInvoice();
        $invoice = $this->issue($this->save($cycle['items'], $cycle['taxes']));
        $this->nameItem($cycle['cream'], 'Cream');
        $this->nameItem($cycle['perfume'], 'Perfume');
        $credit = $this->service->issueSale($this->perfumeReturn($invoice->sourceId, $cycle['perfume']), null, 'In case of goods or services refund.', $this->clerk);
        $noteId = $this->debitNoteOf($this->clerk);

        $this->assertSame($credit->documentId, $this->service->issueDebitNote($noteId, 'reason', $credit->documentId, $this->clerk)->originalDocumentId);
    }

    public function testXml_RefusesTheFileOfAnotherEmployeeToSomeoneWhoSeesTheirOwnRecordsAsIfItDidNotExist(): void
    {
        $document = $this->issue();
        $own      = $this->seesOwnRecordsOnly($this->employeeWithGrants([AccountingGuard::EINVOICING_MANAGE]));

        $foreign = $this->refusal(fn () => $this->service->xml($document->documentId, $own));
        $missing = $this->refusal(fn () => $this->service->xml(987654321, $own));

        $this->assertSame(EInvoiceError::DocumentNotFound, $foreign->error);
        $this->assertSame($missing->error, $foreign->error);
        $this->assertSame($document->xml, $this->service->xml($document->documentId, $this->employeeWithGrants([AccountingGuard::EINVOICING_MANAGE]))->xml);
    }

    public function testXml_GivesTheFileOfTheirOwnSaleAndTheirOwnDebitNoteToSomeoneWhoSeesTheirOwnRecordsOnly(): void
    {
        $own     = $this->seesOwnRecordsOnly($this->employeeWithGrants([AccountingGuard::EINVOICING_MANAGE]));
        $invoice = $this->service->issueSale($this->ownedBy($this->referenceSale(), $own), null, '', $own);
        $note    = $this->service->issueDebitNote($this->debitNoteOf($own), 'reason', $invoice->documentId, $own);
        $foreign = $this->service->issueDebitNote($this->debitNoteOf($this->clerk, '10'), 'reason', $invoice->documentId, $this->clerk);

        $this->assertSame($invoice->xml, $this->service->xml($invoice->documentId, $own)->xml);
        $this->assertSame($note->xml, $this->service->xml($note->documentId, $own)->xml);
        $this->assertSame(EInvoiceError::DocumentNotFound, $this->refusal(fn () => $this->service->xml($foreign->documentId, $own))->error);
    }

    public function testIssuedIn_ListsOnlyTheDocumentsOfTheirOwnRecordsToSomeoneWhoSeesTheirOwnRecordsOnly(): void
    {
        $own     = $this->seesOwnRecordsOnly($this->employeeWithGrants([AccountingGuard::EINVOICING_MANAGE]));
        $foreign = $this->issue();
        $mine    = $this->service->issueSale($this->ownedBy($this->referenceSale(), $own), null, '', $own);
        $note    = $this->service->issueDebitNote($this->debitNoteOf($own), 'reason', $mine->documentId, $own);
        $other   = $this->service->issueDebitNote($this->debitNoteOf($this->clerk, '10'), 'reason', $foreign->documentId, $this->clerk);

        $listed = static fn (array $rows): array => array_map(static fn (object $row): int => (int) $row->document_id, $rows);

        $this->assertSame([$note->documentId, $mine->documentId], $listed($this->service->issuedIn(null, 100, $own)));
        $this->assertSame(2, $this->service->countIssued(null, $own));
        $this->assertSame([$note->documentId], $listed($this->service->issuedIn(null, 1, $own)));
        $this->assertSame([$mine->documentId], $listed($this->service->issuedIn(null, 1, $own, 1)));
        $this->assertSame([$other->documentId, $note->documentId, $mine->documentId, $foreign->documentId], $listed($this->service->issuedIn(null, 100)));
        $this->assertSame(4, $this->service->countIssued(null));
        $this->assertSame(0, $this->service->countIssued([]));
    }

    public function testIssueSale_AssignsTheCountersWithoutGapsOrRepeatsWhenTwoRequestsRunInTwoConnections(): void
    {
        $first  = $this->referenceSale();
        $second = $this->counterSale();

        [$one, $two] = $this->raceTwoProcesses('issueEInvoice', [(string) $first], [(string) $second]);

        $this->assertTrue($one);
        $this->assertTrue($two);

        $rows = $this->accountingDb()->table('einvoice_documents')->orderBy('icv', 'ASC')->get()->getResult();

        $this->assertCount(2, $rows);
        $this->assertSame([1, 2], [(int) $rows[0]->icv, (int) $rows[1]->icv]);
        $this->assertSame(InvoiceHash::FIRST, $rows[0]->previous_hash);
        $this->assertSame($rows[0]->invoice_hash, $rows[1]->previous_hash);
        $this->assertSame(['icv' => 2, 'hash' => $rows[1]->invoice_hash], $this->counter());
    }

    public function testIssueSale_IssuesOneDocumentWhenTwoConnectionsAskForTheSameSale(): void
    {
        $saleId = $this->referenceSale();

        [$one, $two] = $this->raceTwoProcesses('issueEInvoice', [(string) $saleId], [(string) $saleId]);

        $this->assertTrue($one);
        $this->assertTrue($two);
        $this->assertSame(1, $this->countRows('einvoice_documents'));
        $this->assertSame(1, $this->counter()['icv']);
    }

    public function testIssueSale_ChangesNoJournalNoNumberNoStockMovementAndNoTotal(): void
    {
        $saleId  = $this->referenceSale();
        $before  = $this->databaseFingerprint();
        $journal = $this->journals($saleId);

        $this->issue($saleId);

        $after = $this->databaseFingerprint();

        foreach ($before as $table => $print) {
            if (in_array($table, ['ospos_einvoice_counters', 'ospos_einvoice_unit_counters', 'ospos_einvoice_documents', 'ospos_einvoice_transport_log', 'ospos_accounting_audit_log'], true)) {
                continue;
            }

            $this->assertSame($print, $after[$table], 'The table ' . $table . ' changed when a document was issued.');
        }

        $this->assertSame($journal, $this->journals($saleId));
    }

    /**
     * Everything the books hold of a sale a scenario saves, without the numbers the database gave the rows:
     * its journals, its header, its lines and their taxes, its payments, its document numbers, its stock
     * movements in the stock of the shop and in the books, and the totals those add up to. The items are
     * named by their place in the sale, because every run makes new ones, and a number is told from the
     * last number its series had before the run, because a series goes on from the sales of an earlier run.
     *
     * @param array<string, int> $bases The last number of each series (by its prefix) before the run.
     *
     * @return array<string, mixed>
     */
    private function bookOf(int $saleId, array $bases = []): array
    {
        $db    = $this->accountingDb();
        $items = [];
        $place = static function (mixed $itemId) use (&$items): string {
            return 'item ' . ($items[(int) $itemId] ??= count($items) + 1);
        };

        $journals = [];

        foreach ($this->journals($saleId) as $journal) {
            $journals[] = ['entry' => $journal['entry'], 'status' => $journal['status'], 'revision' => $journal['revision'], 'date' => $journal['date'], 'lines' => $journal['lines']];
        }

        $lines = [];
        $sold  = '0';

        foreach ($db->table('sales_items')->where('sale_id', $saleId)->orderBy('line', 'ASC')->get()->getResultArray() as $line) {
            $lines[] = [$place($line['item_id']), $line['line'], $line['quantity_purchased'], $line['item_cost_price'], $line['item_unit_price'], $line['discount'], $line['discount_type'], $line['item_location'], $line['invoice_discount_share']];
            $sold    = bcadd($sold, bcmul((string) $line['quantity_purchased'], (string) $line['item_unit_price'], 6), 6);
        }

        $taxes = [];
        $tax   = '0';

        foreach ($db->table('sales_items_taxes')->where('sale_id', $saleId)->orderBy('line', 'ASC')->orderBy('name', 'ASC')->get()->getResultArray() as $row) {
            $taxes[] = [$place($row['item_id']), $row['line'], $row['name'], $row['percent'], $row['tax_type'], $row['item_tax_amount']];
            $tax     = bcadd($tax, (string) $row['item_tax_amount'], 4);
        }

        $payments = [];
        $paid     = '0';

        foreach ($db->table('sales_payments')->where('sale_id', $saleId)->orderBy('payment_id', 'ASC')->get()->getResultArray() as $row) {
            $payments[] = [$row['payment_type'], $row['payment_amount'], $row['cash_refund'], $row['payment_status'], $row['treasury_id']];
            $paid       = bcadd($paid, (string) $row['payment_amount'], 2);
        }

        $numbers = array_map(
            fn (array $row): array => [$row['doc_type'], $this->relativeNumber((string) $row['number'], $bases)],
            $db->table('document_numbers')->where(['source_table' => 'sales', 'source_id' => $saleId])->get()->getResultArray(),
        );

        $moves = array_map(
            static fn (array $row): array => [$place($row['trans_items']), $row['trans_location'], $row['trans_inventory'], str_replace((string) $saleId, '#', (string) $row['trans_comment'])],
            $db->table('inventory')->like('trans_comment', 'POS ' . $saleId, 'after')->orderBy('trans_id', 'ASC')->get()->getResultArray(),
        );

        $stock = array_map(
            static fn (array $row): array => [$row['entry_type'], $row['revision'], $row['line_no'], $row['kind'], $row['entry_date'], $place($row['item_id']), $row['location_id'], $row['quantity'], $row['value']],
            $db->table('accounting_stock_entries')->where(['source_type' => 'sale', 'source_id' => $saleId])->orderBy('entry_type', 'ASC')->orderBy('revision', 'ASC')->orderBy('line_no', 'ASC')->get()->getResultArray(),
        );

        $header                   = $db->table('sales')->select('sale_type, sale_status, customer_id, employee_id, branch_id, invoice_number, quote_number, work_order_number, invoice_discount_type, invoice_discount, invoice_date, due_date, return_document, delivered')->where('sale_id', $saleId)->get()->getRowArray();
        $header['invoice_number'] = $header['invoice_number'] === null ? null : $this->relativeNumber((string) $header['invoice_number'], $bases);

        return [
            'journals' => $journals,
            'header'   => $header,
            'lines'    => $lines,
            'taxes'    => $taxes,
            'payments' => $payments,
            'numbers'  => $numbers,
            'moves'    => $moves,
            'stock'    => $stock,
            'totals'   => ['sold' => $sold, 'tax' => $tax, 'paid' => $paid],
        ];
    }

    /**
     * @return array<string, int>
     */
    private function numberBases(): array
    {
        $bases = [];

        foreach ($this->accountingDb()->table('document_numbers')->select('number')->get()->getResultArray() as $row) {
            [$prefix, $digits] = $this->splitNumber((string) $row['number']);

            $bases[$prefix] = max($bases[$prefix] ?? 0, $digits);
        }

        return $bases;
    }

    /**
     * @return array{string, int}
     */
    private function splitNumber(string $number): array
    {
        preg_match('/^(.*?)(\d+)$/', $number, $found);

        return [$found[1] ?? $number, (int) ($found[2] ?? 0)];
    }

    /**
     * @param array<string, int> $bases
     */
    private function relativeNumber(string $number, array $bases): string
    {
        [$prefix, $digits] = $this->splitNumber($number);

        return $prefix . ($digits - ($bases[$prefix] ?? 0));
    }

    public function testIssueSale_LeavesTheBooksOfTheSamePostingsAsTheyAreWhenTheLayerIsOffAndWhenItIsOn(): void
    {
        $manager = $this->employeeWithGrants([DocumentNumbering::PERMISSION]);
        $numbers = new DocumentNumbering();

        $numbers->configure(DocumentType::SalesInvoice, ['enabled' => true, 'prefix' => 'INV-', 'padding' => 4], $manager);
        $numbers->configure(DocumentType::CreditNote, ['enabled' => true, 'prefix' => 'CN-', 'padding' => 4], $manager);

        $post = function (): array {
            $cycle   = $this->referenceInvoice();
            $invoice = $this->save($cycle['items'], $cycle['taxes'], [$this->payment('Cash', '300.00')]);
            $return  = $this->perfumeReturn($invoice, $cycle['perfume']);
            $counter = $this->save($cycle['items'], $cycle['taxes'], [$this->payment('Cash', '816.50')], SALE_TYPE_INVOICE, null);

            return [$invoice, $return, $counter];
        };

        $this->configureEInvoicing([EInvoiceSettings::ENABLED => '0']);

        $before = $this->numberBases();
        $off    = array_map(fn (int $id): array => $this->bookOf($id, $before), $post());

        $this->configureEInvoicing();

        $before = $this->numberBases();
        $ids    = $post();
        $on     = array_map(fn (int $id): array => $this->bookOf($id, $before), $ids);

        $this->service->issueSale($ids[0], null, '', $this->clerk);
        $this->service->issueSale($ids[1], null, 'In case of goods or services refund.', $this->clerk);
        $this->service->issueSale($ids[2], null, '', $this->clerk);

        $this->assertSame($off, $on);
        $this->assertSame($on, array_map(fn (int $id): array => $this->bookOf($id, $before), $ids));
        $this->assertSame(3, $this->countRows('einvoice_documents'));
    }

    public function testIssueSale_FingerprintsTheNumbersTheStockMovementsAndTheTotalsOfASaleBeforeComparingThem(): void
    {
        $manager = $this->employeeWithGrants([DocumentNumbering::PERMISSION]);

        (new DocumentNumbering())->configure(DocumentType::SalesInvoice, ['enabled' => true, 'prefix' => 'INV-', 'padding' => 4], $manager);

        $book = $this->bookOf($this->referenceSale(null, [$this->payment('Cash', '816.50')]));

        $this->assertNotSame([], $book['numbers']);
        $this->assertSame(['sales_invoice', 'INV-1'], $book['numbers'][0]);
        $this->assertStringStartsWith('INV-', (string) $book['header']['invoice_number']);
        $this->assertNotSame([], $book['moves']);
        $this->assertNotSame([], $book['stock']);
        $this->assertSame('816.50', $book['totals']['paid']);
        $this->assertSame('106.5000', $book['totals']['tax']);
    }

    public function testIssueSale_CreatesTheCounterRowOfTheCompanyWhenItIsNotThere(): void
    {
        $this->accountingDb()->table('einvoice_counters')->where('company_id', 1)->delete();

        $document = $this->issue();

        $this->assertSame(1, $document->icv);
        $this->assertSame(['icv' => 1, 'hash' => $document->hash], $this->counter());
    }

    public function testIssueSale_WritesTheAuditEntryOfTheDocumentWithItsCounterAndHash(): void
    {
        $document = $this->issue();

        $entry   = $this->accountingDb()->table('accounting_audit_log')->where(['entity_type' => 'einvoice_document', 'entity_id' => $document->documentId, 'action' => 'issued'])->get()->getRow();
        $details = json_decode((string) $entry->details, true);

        $this->assertSame($this->clerk, (int) $entry->employee_id);
        $this->assertSame(1, (int) $entry->branch_id);
        $this->assertSame(1, $details['icv']);
        $this->assertSame($document->hash, $details['hash']);
        $this->assertSame('sale:' . $document->sourceId, $details['source']);
    }

    public function testIssueSale_WaitsForTheLockOfTheCounterBeforeItTakesTheNextNumber(): void
    {
        $saleId = $this->referenceSale();
        $db     = $this->accountingDb();

        $db->transBegin();
        $db->query('SELECT last_icv FROM ' . $db->prefixTable('einvoice_counters') . ' WHERE company_id = 1 FOR UPDATE');

        try {
            $request = $this->startWorkerRequest('issueEInvoice', (string) $saleId);

            $this->waitUntilARequestWaitsForARowLock();

            $this->assertSame(0, $this->countRows('einvoice_documents'));
        } finally {
            $db->transCommit();
        }

        $finished = $this->finishWorkerRequest($request);

        $this->assertSame('1', $finished['output'], $finished['error']);
        $this->assertSame(1, $this->countRows('einvoice_documents'));
        $this->assertSame(1, $this->counter()['icv']);
    }

    public function testIssueDebitNote_RefusesANoteOfABranchTheEmployeeDoesNotWorkInAndStoresNothing(): void
    {
        $this->setConfigValue('accounting_debit_note', '1');

        $other   = $this->createBranch('B2');
        $invoice = $this->issue();
        $clerk   = $this->employeeWithGrants([AccountingGuard::DEBIT_NOTES_MANAGE]);
        $noteId  = (new SalesDebitNotes())->create(['customer_id' => $this->customerId, 'date' => $this->daysAgo(1), 'amount' => '100', 'tax_percent' => '15', 'note' => 'Freight', 'branch_id' => $other], $clerk);
        $limited = $this->employeeWithGrants([AccountingGuard::EINVOICING_MANAGE], [1]);

        try {
            $this->service->issueDebitNote($noteId, 'a reason', $invoice->documentId, $limited);
            $this->fail('A document of another branch was issued.');
        } catch (BranchException $failure) {
            $this->assertSame(BranchError::NotAllowed, $failure->error);
        }

        $this->assertSame(1, $this->countRows('einvoice_documents'));
    }

    public function testIssueDebitNote_RequiresThePermissionAndTheLayerAndAnExistingNote(): void
    {
        $this->setConfigValue('accounting_debit_note', '1');

        $invoice = $this->issue();
        $other   = $this->employeeWithGrants([AccountingGuard::JOURNALS_POST]);

        try {
            $this->service->issueDebitNote(1, 'reason', $invoice->documentId, $other);
            $this->fail('A debit note was issued without the permission.');
        } catch (AccountingException $failure) {
            $this->assertSame(AccountingError::PermissionDenied, $failure->error);
        }

        $this->assertSame(EInvoiceError::SourceNotFound, $this->refusal(fn () => $this->service->issueDebitNote(987654321, 'reason', $invoice->documentId, $this->clerk))->error);

        $this->configureEInvoicing([EInvoiceSettings::ENABLED => '0']);

        $this->assertSame(EInvoiceError::Disabled, $this->refusal(fn () => $this->service->issueDebitNote(1, 'reason', $invoice->documentId, $this->clerk))->error);
    }
}
