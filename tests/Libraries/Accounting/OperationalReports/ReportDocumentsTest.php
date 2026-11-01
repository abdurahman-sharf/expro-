<?php

namespace Tests\Libraries\Accounting\OperationalReports;

use App\Libraries\Accounting\OperationalReports\DocumentKind;
use App\Libraries\Accounting\OperationalReports\ReportDocuments;
use App\Libraries\Accounting\ReportScope;
use App\Libraries\Accounting\JournalService;
use App\Libraries\Accounting\AccountingGuard;
use App\Libraries\Accounting\SalesDebitNotes;
use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\DatabaseTestTrait;
use Config\Database;
use Tests\Support\AccountingFixtureTrait;
use Tests\Support\EmployeeFixtureTrait;
use Tests\Support\ItemFixtureTrait;
use Tests\Support\SaleAccountingFixtureTrait;

/**
 * The sales documents that stand in the books: a document is read when its journal stands and falls in the days
 * and the branches, as the sale works out and as its journal holds, whatever a legacy sale without a journal
 * or a closed period would suggest.
 */
class ReportDocumentsTest extends CIUnitTestCase
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

    public static function setUpBeforeClass(): void
    {
        $seeder = Database::seeder('tests');
        $seeder->call('TestDatabaseBootstrapSeeder');
    }

    protected function setUp(): void
    {
        parent::setUp();

        $this->startSelling();
    }

    protected function tearDown(): void
    {
        $this->stopSelling();

        parent::tearDown();
    }

    /**
     * @return list<\App\Libraries\Accounting\OperationalReports\ReportDocument>
     */
    private function read(?ReportScope $scope = null, ?string $from = '2020-01-01', ?string $to = null, array $kinds = []): array
    {
        return (new ReportDocuments())->read($scope ?? ReportScope::resolve(null, null), $from, $to ?? date('Y-m-d'), $kinds);
    }

    public function testRead_GivesEachDocumentTheAmountsItWorksOutToAndTheJournalHolds(): void
    {
        $cycle = $this->referenceInvoice();
        $this->save($cycle['items'], $cycle['taxes'], [], SALE_TYPE_INVOICE, $this->customerId);

        $document = $this->read()[0];

        $this->assertSame(DocumentKind::Invoice, $document->kind);
        $this->assertSame([$document->total, $document->tax, $document->revenue()], [$document->ledgerTotal, $document->ledgerTax, $document->ledgerRevenue]);
        $this->assertSame('816.5000', $document->total);
        $this->assertSame('106.5000', $document->tax);
    }

    public function testRead_CarriesAReturnAsNegativeAmountsAndTellsTheTwoDocumentsApart(): void
    {
        $invoice = $this->invoiceOf('115', $this->customerId);
        $note    = $this->creditNoteOf($invoice, '30', $this->customerId);
        $refund  = $this->refundInvoiceOf($invoice, '20', $this->customerId);

        $byId = [];

        foreach ($this->read() as $document) {
            $byId[$document->id] = $document;
        }

        $this->assertSame([DocumentKind::CreditNote, '-30.0000'], [$byId[$note]->kind, $byId[$note]->total]);
        $this->assertSame([DocumentKind::RefundInvoice, '-20.0000'], [$byId[$refund]->kind, $byId[$refund]->total]);
        $this->assertSame('20.0000', $byId[$invoice]->returnedByRefund);
        $this->assertSame('50.0000', $byId[$invoice]->returnedAll());
        $this->assertSame($invoice, $byId[$note]->originId);
    }

    public function testRead_LeavesOutALegacySaleWithoutAJournalAndAnUnissuedDraft(): void
    {
        $this->invoiceOf('10', $this->customerId);
        $this->accountingDb()->table('sales')->insert(['sale_time' => date('Y-m-d H:i:s'), 'customer_id' => $this->customerId, 'employee_id' => $this->employeeId, 'comment' => 'legacy', 'sale_status' => COMPLETED, 'sale_type' => SALE_TYPE_POS, 'branch_id' => 1]);
        $legacy = (int) $this->accountingDb()->insertID();
        $this->saleIds[] = $legacy;
        $this->draftInvoice(null, $this->customerId);

        $ids = array_map(static fn ($document): int => $document->id, $this->read());

        $this->assertCount(1, $ids);
        $this->assertNotContains($legacy, $ids);
    }

    public function testRead_StillReadsADocumentOfAClosedPeriod(): void
    {
        $this->invoiceOf('10', $this->customerId);
        $this->closeThrough(date('Y-m-d'));

        $this->assertCount(1, $this->read());
    }

    public function testRead_ReadsOnlyTheBranchesOfTheScopeAndTheDaysAsked(): void
    {
        $second = $this->createBranch('DB2', 'Second documents branch');
        $this->invoiceInBranch(1, '10', [], $this->customerId);
        $this->invoiceInBranch($second, '20', [], $this->customerId);

        $this->assertCount(2, $this->read());
        $this->assertSame(['10.0000'], array_map(static fn ($document): string => $document->total, $this->read(ReportScope::resolve([1], null))));
        $this->assertSame(['20.0000'], array_map(static fn ($document): string => $document->total, $this->read(ReportScope::resolve(null, [$second]))));
        $this->assertSame([], $this->read(ReportScope::resolve(null, [])));
        $this->assertSame([], $this->read(null, '2001-01-01', '2001-01-31'));
        $this->assertCount(2, $this->read(null, date('Y-m-d'), date('Y-m-d')));
    }

    public function testRead_NamesTheCustomerTheEmployeeAndTheBranchOfADocument(): void
    {
        $this->invoiceOf('10', $this->customerId);

        $document = $this->read()[0];

        $this->assertSame('Omar Khaled', $document->customerName);
        $this->assertSame('Temp Employee', $document->employeeName);
        $this->assertNotSame('', $document->branchCode);
    }

    public function testByIds_ReadsTheDocumentsGivenWhateverTheirDays(): void
    {
        $first  = $this->invoiceOf('10', $this->customerId);
        $second = $this->invoiceOf('20', $this->customerId);

        $found = (new ReportDocuments())->byIds(ReportScope::resolve(null, null), [$first], true);

        $this->assertSame([$first], array_keys($found));
        $this->assertNotSame([], $found[$first]->lines);
        $this->assertGreaterThan(0, $second);
        $this->assertSame([], (new ReportDocuments())->byIds(ReportScope::resolve(null, []), [$first]));
    }

    public function testReturnsOf_GivesTheReturnsThatStandForAnInvoiceAndOrphanReturnsGiveTheOthers(): void
    {
        $invoice = $this->invoiceOf('115', $this->customerId);
        $note    = $this->creditNoteOf($invoice, '30', $this->customerId);
        $orphan  = $this->save([$this->line($this->item('0', false), 0, '-1', '25')], [], [], SALE_TYPE_RETURN, $this->customerId, true, null, COMPLETED, NEW_ENTRY, null, returnOverrideReason: 'test');
        $reader  = new ReportDocuments();
        $scope   = ReportScope::resolve(null, null);

        $this->assertSame([$note], array_map(static fn ($document): int => $document->id, $reader->returnsOf($scope, [$invoice])[$invoice]));
        $this->assertSame([$orphan], array_map(static fn ($document): int => $document->id, $reader->orphanReturns($scope, '2020-01-01', date('Y-m-d'))));
    }
    public function testRead_LeavesOutASaleThatIsNotCompletedEvenWhileAJournalStandsForIt(): void
    {
        $sale = $this->invoiceOf('10', $this->customerId);

        $this->accountingDb()->table('sales')->where('sale_id', $sale)->update(['sale_status' => SUSPENDED]);

        $this->assertSame([], $this->read());
    }

    public function testRead_CountsOnlyThePaymentsWhoseJournalsStand(): void
    {
        $invoice = $this->invoiceOf('100', $this->customerId);
        $payment = $this->addPayment($invoice, '60');

        $this->assertSame('60.0000', $this->read()[0]->paid());

        $this->removePayment($invoice, $payment);

        $this->assertSame('0.0000', $this->read()[0]->paid());
    }

    public function testRead_CountsOnlyTheReturnsWhoseJournalsStand(): void
    {
        $invoice = $this->invoiceOf('115', $this->customerId, [$this->payment(lang('Sales.cash'), '115')]);
        $note    = $this->creditNoteOf($invoice, '30', $this->customerId);

        $this->assertSame('30.0000', $this->read(null, '2020-01-01', null, [DocumentKind::Invoice])[0]->returnedAll());

        $journal = $this->accountingDb()->table('accounting_journals')->select('journal_id')->where(['source_type' => 'sale', 'source_id' => $note, 'entry_type' => 'revenue'])->get()->getRow();

        (new JournalService())->reverseAutomatic((int) $journal->journal_id, date('Y-m-d'), 'Taken back', null);

        $this->assertSame('0.0000', $this->read(null, '2020-01-01', null, [DocumentKind::Invoice])[0]->returnedAll());
        $this->assertCount(1, $this->read());
    }

    public function testRead_LeavesOutCreditThatWasReleasedFromAnInvoice(): void
    {
        $invoice = $this->invoiceOf('115', $this->customerId, [$this->payment(lang('Sales.cash'), '100')]);
        $this->creditNoteOf($invoice, '57.50', $this->customerId);

        $this->assertSame('115.0000', $this->read(null, '2020-01-01', null, [DocumentKind::Invoice])[0]->paid());

        $this->accountingDb()->table('customer_credit_applications')->update(['released_at' => date('Y-m-d H:i:s')]);

        $this->assertSame('100.0000', $this->read(null, '2020-01-01', null, [DocumentKind::Invoice])[0]->paid());
    }

    public function testRead_ReadsOnlyTheKindsAskedFor(): void
    {
        $invoice = $this->invoiceOf('115', $this->customerId);
        $this->creditNoteOf($invoice, '30', $this->customerId);
        $this->refundInvoiceOf($invoice, '20', $this->customerId);

        $kinds = static fn (array $documents): array => array_map(static fn ($document): string => $document->kind->value, $documents);

        $this->assertSame(['invoice'], $kinds($this->read(null, '2020-01-01', null, [DocumentKind::Invoice])));
        $this->assertSame(['credit_note'], $kinds($this->read(null, '2020-01-01', null, [DocumentKind::CreditNote])));
        $this->assertSame(['refund_invoice'], $kinds($this->read(null, '2020-01-01', null, [DocumentKind::RefundInvoice])));
        $this->assertCount(3, $this->read());
    }
    public function testRead_ReadsADebitNoteOfTheBranchesOfTheScopeOnly(): void
    {
        $this->setConfigValue('accounting_debit_note', '1');

        $second = $this->createBranch('DNB', 'Second debit note branch');
        $clerk  = $this->employeeWithGrants([AccountingGuard::DEBIT_NOTES_MANAGE]);
        $notes  = new SalesDebitNotes();

        $notes->create(['customer_id' => $this->customerId, 'date' => date('Y-m-d'), 'amount' => '10', 'branch_id' => 1], $clerk);
        $notes->create(['customer_id' => $this->customerId, 'date' => date('Y-m-d'), 'amount' => '20', 'branch_id' => $second], $clerk);

        $totals = static fn (array $documents): array => array_map(static fn ($document): string => $document->total, $documents);

        $this->assertSame(['10.0000', '20.0000'], $totals($this->read(null, '2020-01-01', null, [DocumentKind::DebitNote])));
        $this->assertSame(['10.0000'], $totals($this->read(ReportScope::resolve([1], null), '2020-01-01', null, [DocumentKind::DebitNote])));
        $this->assertSame(['20.0000'], $totals($this->read(ReportScope::resolve(null, [$second]), '2020-01-01', null, [DocumentKind::DebitNote])));
        $this->assertSame([], $this->read(ReportScope::resolve(null, []), '2020-01-01', null, [DocumentKind::DebitNote]));

        $this->accountingDb()->table('app_config')->where('key', 'accounting_debit_note')->delete();
    }
}
