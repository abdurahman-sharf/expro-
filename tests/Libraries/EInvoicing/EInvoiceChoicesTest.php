<?php

namespace Tests\Libraries\EInvoicing;

use App\Libraries\Accounting\AccountingGuard;
use App\Libraries\EInvoicing\EInvoiceChoices;
use App\Libraries\EInvoicing\EInvoiceKind;
use App\Libraries\EInvoicing\EInvoiceService;
use App\Libraries\Numbering\DocumentNumbering;
use App\Libraries\Numbering\DocumentType;
use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\DatabaseTestTrait;
use Config\Database;
use Tests\Support\AccountingFixtureTrait;
use Tests\Support\EInvoiceFixtureTrait;
use Tests\Support\EInvoiceSaleFixtureTrait;
use Tests\Support\EmployeeFixtureTrait;
use Tests\Support\ItemFixtureTrait;
use Tests\Support\SaleAccountingFixtureTrait;

/**
 * What the form of a debit note document offers: the notes that wait for a document and the documents they
 * may refer to, cut to the branches and to the records of whoever opens it.
 *
 * @internal
 */
final class EInvoiceChoicesTest extends CIUnitTestCase
{
    use DatabaseTestTrait;
    use AccountingFixtureTrait;
    use EmployeeFixtureTrait;
    use ItemFixtureTrait;
    use SaleAccountingFixtureTrait;
    use EInvoiceFixtureTrait;
    use EInvoiceSaleFixtureTrait;

    protected $migrate     = true;
    protected $migrateOnce = true;
    protected $refresh     = false;
    protected $namespace;
    private EInvoiceChoices $choices;
    private EInvoiceService $service;

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

        $this->choices = new EInvoiceChoices();
        $this->service = new EInvoiceService();
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
        $this->resetBranches();

        parent::tearDown();
    }

    /**
     * @param list<array<string, mixed>> $rows
     *
     * @return list<int>
     */
    private function idsOf(array $rows, string $key): array
    {
        return array_map(static fn (array $row): int => (int) $row[$key], $rows);
    }

    public function testNotesWaitingListsTheNotesThatStandAndHaveNoDocumentNewestFirstWithTheirNumberAndCustomer(): void
    {
        $invoice = $this->service->issueSale($this->referenceSale(), null, '', null);
        $first   = $this->debitNoteOf($this->employeeId, '200');
        $second  = $this->debitNoteOf($this->employeeId, '50');

        $notes = $this->choices->notesWaiting(null);

        $this->assertSame([$second, $first], $this->idsOf($notes, 'debit_note_id'));
        $this->assertSame($this->customerId, $notes[0]['customer_id']);
        $this->assertSame('Omar Khaled', $notes[0]['customer']);
        $this->assertSame('57.5000', $notes[0]['total']);
        $this->assertSame(DocumentType::SalesDebitNote->fallback($second), $notes[0]['number']);

        $this->service->issueDebitNote($second, 'reason', $invoice->documentId, null);

        $this->assertSame([$first], $this->idsOf($this->choices->notesWaiting(null), 'debit_note_id'));
    }

    public function testNotesWaitingNamesTheNoteByItsDocumentNumberWhenTheSeriesIsOnAndNamesTheCompanyOfTheCustomer(): void
    {
        $manager = $this->employeeWithGrants([DocumentNumbering::PERMISSION]);

        (new DocumentNumbering())->configure(DocumentType::SalesDebitNote, ['enabled' => true, 'prefix' => 'DN-', 'padding' => 4, 'next_number' => 1005], $manager);
        $this->accountingDb()->table('customers')->where('person_id', $this->customerId)->update(['company_name' => 'Khaled Trading']);

        $noteId = $this->debitNoteOf($this->employeeId);
        $notes  = $this->choices->notesWaiting(null);

        $this->assertSame([$noteId], $this->idsOf($notes, 'debit_note_id'));
        $this->assertSame('DN-1005', $notes[0]['number']);
        $this->assertSame('Khaled Trading', $notes[0]['customer']);
    }

    public function testNotesWaitingLeavesOutANoteThatWasTakenBack(): void
    {
        $noteId = $this->debitNoteOf($this->employeeId);

        $this->accountingDb()->table('sales_debit_notes')->where('debit_note_id', $noteId)->update(['status' => 'reversed']);

        $this->assertSame([], $this->choices->notesWaiting(null));
    }

    public function testNotesWaitingListsOnlyTheNotesOfTheBranchesTheEmployeeWorksIn(): void
    {
        $other   = $this->createBranch('B2');
        $inFirst = $this->debitNoteOf($this->employeeId);
        $inOther = $this->debitNoteOf($this->employeeId, '10', null, $other);
        $limited = $this->employeeWithGrants([AccountingGuard::EINVOICING_MANAGE], [$other]);
        $nowhere = $this->employeeWithGrants([AccountingGuard::EINVOICING_MANAGE], []);
        $wide    = $this->employeeWithGrants([AccountingGuard::EINVOICING_MANAGE]);

        $this->assertSame([$inOther], $this->idsOf($this->choices->notesWaiting($limited), 'debit_note_id'));
        $this->assertSame([$inOther, $inFirst], $this->idsOf($this->choices->notesWaiting($wide), 'debit_note_id'));
        $this->assertSame([], $this->choices->notesWaiting($nowhere));
    }

    public function testNotesWaitingListsOnlyTheNotesTheyMadeToSomeoneWhoSeesTheirOwnRecordsOnly(): void
    {
        $own     = $this->seesOwnRecordsOnly($this->employeeWithGrants([AccountingGuard::EINVOICING_MANAGE]));
        $mine    = $this->debitNoteOf($own);
        $foreign = $this->debitNoteOf($this->employeeId);

        $this->assertSame([$mine], $this->idsOf($this->choices->notesWaiting($own), 'debit_note_id'));
        $this->assertSame([$foreign, $mine], $this->idsOf($this->choices->notesWaiting(null), 'debit_note_id'));
    }

    public function testNotesWaitingStopsAtTheLimitWithTheNewestNotes(): void
    {
        $this->debitNoteOf($this->employeeId, '10');
        $second = $this->debitNoteOf($this->employeeId, '20');
        $third  = $this->debitNoteOf($this->employeeId, '30');

        $this->assertSame([$third, $second], $this->idsOf($this->choices->notesWaiting(null, 2), 'debit_note_id'));
    }

    public function testOriginalsOfListsTheInvoicesAndTheCreditNotesOfTheCustomersNewestFirst(): void
    {
        $cycle   = $this->referenceInvoice();
        $invoice = $this->service->issueSale($this->save($cycle['items'], $cycle['taxes']), null, '', null);
        $this->nameItem($cycle['cream'], 'Cream');
        $this->nameItem($cycle['perfume'], 'Perfume');
        $credit = $this->service->issueSale($this->perfumeReturn($invoice->sourceId, $cycle['perfume']), null, 'In case of goods or services refund.', null);
        $note   = $this->service->issueDebitNote($this->debitNoteOf($this->employeeId), 'reason', $invoice->documentId, null);

        $originals = $this->choices->originalsOf([$this->customerId], null);

        $this->assertSame([$credit->documentId, $invoice->documentId], $this->idsOf($originals, 'document_id'));
        $this->assertSame([EInvoiceKind::CreditNote, EInvoiceKind::Invoice], array_column($originals, 'kind'));
        $this->assertSame($this->customerId, $originals[0]['customer_id']);
        $this->assertNotContains($note->documentId, $this->idsOf($originals, 'document_id'));
    }

    public function testOriginalsOfLeavesOutTheDocumentsOfOtherCustomersAndOfTheCounter(): void
    {
        $other = $this->makeBusinessCustomer($this->anotherCustomer('Layla', 'Hassan'), '322222222222223', 'Other Co');
        $mine  = $this->service->issueSale($this->referenceSale(), null, '', null);

        $this->service->issueSale($this->referenceSale($other), null, '', null);
        $this->service->issueSale($this->counterSale(), null, '', null);

        $this->assertSame([$mine->documentId], $this->idsOf($this->choices->originalsOf([$this->customerId], null), 'document_id'));
        $this->assertSame([], $this->choices->originalsOf([], null));
        $this->assertCount(2, $this->choices->originalsOf([$this->customerId, $other, $other], null));
    }

    public function testOriginalsOfListsOnlyTheDocumentsOfTheBranchesTheEmployeeWorksIn(): void
    {
        $other    = $this->createBranch('B2');
        $document = $this->service->issueSale($this->referenceSale(), null, '', null);
        $limited  = $this->employeeWithGrants([AccountingGuard::EINVOICING_MANAGE], [$other]);

        $this->assertSame([$document->documentId], $this->idsOf($this->choices->originalsOf([$this->customerId], null), 'document_id'));
        $this->assertSame([], $this->choices->originalsOf([$this->customerId], $limited));

        $this->accountingDb()->table('einvoice_documents')->where('document_id', $document->documentId)->update(['branch_id' => $other]);

        $this->assertSame([$document->documentId], $this->idsOf($this->choices->originalsOf([$this->customerId], $limited), 'document_id'));
    }

    public function testOriginalsOfListsOnlyTheDocumentsOfTheirOwnSalesToSomeoneWhoSeesTheirOwnRecordsOnly(): void
    {
        $own     = $this->seesOwnRecordsOnly($this->employeeWithGrants([AccountingGuard::EINVOICING_MANAGE]));
        $foreign = $this->service->issueSale($this->referenceSale(), null, '', null);
        $mine    = $this->service->issueSale($this->ownedBy($this->referenceSale(), $own), null, '', null);

        $this->assertSame([$mine->documentId], $this->idsOf($this->choices->originalsOf([$this->customerId], $own), 'document_id'));
        $this->assertSame([$mine->documentId, $foreign->documentId], $this->idsOf($this->choices->originalsOf([$this->customerId], null), 'document_id'));
    }

    public function testOriginalsOfStopsAtTheLimitWithTheNewestDocuments(): void
    {
        $this->service->issueSale($this->referenceSale(), null, '', null);

        $newest = $this->service->issueSale($this->referenceSale(), null, '', null);

        $this->assertSame([$newest->documentId], $this->idsOf($this->choices->originalsOf([$this->customerId], null, 1), 'document_id'));
    }
}
