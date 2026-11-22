<?php

namespace Tests\Libraries\Documents;

use App\Libraries\Documents\EInvoicePrint;
use App\Libraries\EInvoicing\DocumentStamper;
use App\Libraries\EInvoicing\EInvoiceDocument;
use App\Libraries\EInvoicing\EInvoiceKind;
use App\Libraries\EInvoicing\EInvoicePrepayment;
use App\Libraries\EInvoicing\EInvoiceSubtype;
use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\DatabaseTestTrait;
use Config\Database;
use Tests\Support\EInvoiceMatrixTrait;

class EInvoicePrintTest extends CIUnitTestCase
{
    use DatabaseTestTrait;
    use EInvoiceMatrixTrait;

    protected $migrate     = true;
    protected $migrateOnce = true;
    protected $refresh     = false;
    protected $namespace   = null;

    public static function setUpBeforeClass(): void
    {
        Database::seeder('tests')->call('TestDatabaseBootstrapSeeder');
    }

    protected function tearDown(): void
    {
        Database::connect()->table('einvoice_cleared_documents')->truncate();
        Database::connect()->table('einvoice_documents')->where('source_id >=', 960000)->delete();
        Database::connect()->table('einvoice_documents')->where('source_type', 'reissue')->delete();

        parent::tearDown();
    }

    private function stored(EInvoiceDocument $document, string $sourceType, int $sourceId, int $icv): int
    {
        $stamped = (new DocumentStamper())->unsigned($document);
        $db      = Database::connect();

        $db->table('einvoice_documents')->insert(['company_id' => 1, 'branch_id' => 1, 'source_type' => $sourceType, 'source_id' => $sourceId, 'doc_kind' => $document->kind->value, 'subtype' => $document->subtype->value, 'document_number' => $document->number, 'uuid' => sprintf('00000000-0000-4000-8000-%012d', $icv), 'icv' => $icv, 'previous_hash' => 'x', 'invoice_hash' => $stamped->hash, 'issue_date' => $document->issueDate, 'issue_time' => $document->issueTime, 'tax_exclusive' => $document->taxExclusive, 'tax_amount' => $document->taxAmount, 'tax_inclusive' => $document->taxInclusive, 'xml' => $stamped->xml, 'qr_text' => $stamped->qr]);

        return (int) $db->insertID();
    }

    private function printOf(EInvoiceDocument $document): array
    {
        $print = EInvoicePrint::fromXml((new DocumentStamper())->unsigned($document)->xml);

        $this->assertNotNull($print);

        return $print;
    }

    public function testFromXml_ReadsWhatTheIssuedFileSaysLineByLine(): void
    {
        $document = $this->matrix()['S-01'];
        $print    = $this->printOf($document);

        $this->assertSame($document->subtype === EInvoiceSubtype::Standard ? 'standard' : 'simplified', $print['title']);
        $this->assertSame([$document->number, $document->issueDate, $document->issueTime], [$print['number'], $print['issue_date'], $print['issue_time']]);
        $this->assertSame($document->seller->vatNumber, $print['seller']['vat']);
        $this->assertCount(count($document->lines), $print['lines']);

        foreach ($document->lines as $index => $line) {
            $printed = $print['lines'][$index];

            $this->assertSame([$line->netAmount, $line->vatAmount, $line->amountWithVat], [$printed['net'], $printed['vat'], $printed['gross']], 'the line prints the VAT the file states');
        }

        $this->assertCount(count($document->taxSubtotals), $print['categories']);
        $this->assertSame([$document->taxExclusive, $document->taxAmount, $document->taxInclusive, $document->payable()], [$print['totals']['exclusive'], $print['totals']['vat'], $print['totals']['inclusive'], $print['totals']['payable']]);
    }

    public function testFromXml_ReadsTheOriginalAndTheReasonOfACreditNote(): void
    {
        $document = $this->matrix()['B-10'];
        $print    = $this->printOf($document);

        $this->assertSame(EInvoiceKind::CreditNote, $document->kind);
        $this->assertSame('credit_note', $print['title']);
        $this->assertSame([(string) $document->billingReference, $document->reason], [$print['original'], $print['reason']]);
        $this->assertNotSame('', $print['buyer']['vat'] . $print['buyer']['name']);
    }

    public function testFromXml_ReadsThePrepaymentsAnInvoiceTakesOff(): void
    {
        $base       = $this->matrix()['S-03'];
        $prepayment = new EInvoicePrepayment('ADV-7', '2026-11-01', '10:00:00', 'S', '15.00', '10.00', '1.50');
        $document   = new EInvoiceDocument(...[...get_object_vars($base), 'prepayments' => [$prepayment]]);
        $print      = $this->printOf($document);
        $line       = $print['lines'][count($print['lines']) - 1];

        $this->assertSame(['ADV-7', '10.00', '1.50'], [$line['prepayment'], $line['prepaid'], $line['prepaidVat']]);
        $this->assertSame([$document->prepaid(), $document->payable()], [$print['totals']['prepaid'], $print['totals']['payable']]);
    }

    public function testFromXml_GivesNothingForAFileThatCannotBeRead(): void
    {
        $this->assertNull(EInvoicePrint::fromXml(''));
        $this->assertNull(EInvoicePrint::fromXml('<not-an-invoice'));
        $this->assertNull(EInvoicePrint::fromXml('<?xml version="1.0"?><Invoice/>'));
    }

    public function testForSource_PrintsTheFileZatcaClearedWithItsCodeAndTheDocumentIssuedInPlaceOfARefusedOne(): void
    {
        $standard = $this->matrix()['B-01'];
        $issued   = $this->stored($standard, 'sale', 960001, 960001);
        $cleared  = str_replace((new DocumentStamper())->unsigned($standard)->qr, 'ZATCA-CLEARED-CODE', (new DocumentStamper())->unsigned($standard)->xml);

        Database::connect()->table('einvoice_cleared_documents')->insert(['document_id' => $issued, 'xml' => $cleared, 'qr_text' => null, 'cleared_at' => date('Y-m-d H:i:s')]);

        $print = (new EInvoicePrint())->forSource('sale', 960001);

        $this->assertSame('ZATCA-CLEARED-CODE', $print['qr'], 'a tax invoice shows the code ZATCA gave it at clearance');
        $this->assertSame($standard->taxInclusive, $print['totals']['inclusive']);

        $simplified = $this->matrix()['S-01'];
        $refused    = $this->stored($simplified, 'sale', 960002, 960002);

        $this->stored($this->amended($simplified, ['number' => 'SME00010-AGAIN']), 'reissue', $refused, 960003);

        $this->assertSame('SME00010-AGAIN', (new EInvoicePrint())->forSource('sale', 960002)['number'], 'the document issued again stands for its sale');
    }
}
