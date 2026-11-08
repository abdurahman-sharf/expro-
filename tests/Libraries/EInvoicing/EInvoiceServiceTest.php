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
use App\Libraries\EInvoicing\TransportResult;
use App\Libraries\Organization\BranchError;
use App\Libraries\Organization\BranchException;
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

    public function testIssueSale_RefusesASaleOfABranchTheEmployeeDoesNotWorkIn(): void
    {
        $other    = $this->createBranch('B2');
        $location = $this->branchLocation($other);
        $saleId   = $this->invoiceInBranch($other, '115.00');
        $limited  = $this->employeeWithGrants([AccountingGuard::EINVOICING_MANAGE], [1]);
        $inside   = $this->employeeWithGrants([AccountingGuard::EINVOICING_MANAGE], [$other]);

        try {
            $this->service->issueSale($saleId, null, '', $limited);
            $this->fail('A document of another branch was issued.');
        } catch (BranchException $failure) {
            $this->assertSame(BranchError::NotAllowed, $failure->error);
        }

        $this->assertSame(0, $this->countRows('einvoice_documents'));
        $this->assertGreaterThan(0, $location);
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
            /** @var list<int> */
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
            if (in_array($table, ['ospos_einvoice_counters', 'ospos_einvoice_documents', 'ospos_einvoice_transport_log', 'ospos_accounting_audit_log'], true)) {
                continue;
            }

            $this->assertSame($print, $after[$table], 'The table ' . $table . ' changed when a document was issued.');
        }

        $this->assertSame($journal, $this->journals($saleId));
    }

    /**
     * Everything the books hold of the sales a scenario saves, without the numbers the database gave them.
     *
     * @return list<array<string, mixed>>
     */
    private function bookOf(int $saleId): array
    {
        $rows = [];

        foreach ($this->journals($saleId) as $journal) {
            $rows[] = ['entry' => $journal['entry'], 'status' => $journal['status'], 'revision' => $journal['revision'], 'date' => $journal['date'], 'lines' => $journal['lines']];
        }

        $sale = $this->saleRow($saleId);

        $rows[] = ['total_row' => [$sale->sale_type, $sale->sale_status, $sale->customer_id]];

        return $rows;
    }

    public function testIssueSale_LeavesTheBooksOfTheSamePostingsAsTheyAreWhenTheLayerIsOffAndWhenItIsOn(): void
    {
        $post = function (): array {
            $cycle   = $this->referenceInvoice();
            $invoice = $this->save($cycle['items'], $cycle['taxes'], [$this->payment('Cash', '300.00')]);
            $return  = $this->perfumeReturn($invoice, $cycle['perfume']);
            $counter = $this->save($cycle['items'], $cycle['taxes'], [$this->payment('Cash', '816.50')], SALE_TYPE_INVOICE, null);

            return [$invoice, $return, $counter];
        };

        $this->configureEInvoicing([EInvoiceSettings::ENABLED => '0']);

        $off = array_map(fn (int $id): array => $this->bookOf($id), $post());

        $this->configureEInvoicing();

        $ids = $post();
        $on  = array_map(fn (int $id): array => $this->bookOf($id), $ids);

        $this->service->issueSale($ids[0], null, '', $this->clerk);
        $this->service->issueSale($ids[1], null, 'In case of goods or services refund.', $this->clerk);
        $this->service->issueSale($ids[2], null, '', $this->clerk);

        $this->assertSame($off, $on);
        $this->assertSame($on, array_map(fn (int $id): array => $this->bookOf($id), $ids));
        $this->assertSame(3, $this->countRows('einvoice_documents'));
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
