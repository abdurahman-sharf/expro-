<?php

namespace Tests\Libraries\Documents;

use App\Libraries\Documents\EInvoicePrint;
use App\Libraries\EInvoicing\DocumentStamper;
use App\Libraries\EInvoicing\EInvoiceDocument;
use App\Libraries\EInvoicing\EInvoiceKind;
use App\Libraries\EInvoicing\EInvoicePrepayment;
use App\Libraries\EInvoicing\EInvoiceSubtype;
use CodeIgniter\Test\CIUnitTestCase;
use Tests\Support\EInvoiceMatrixTrait;

class EInvoicePrintTest extends CIUnitTestCase
{
    use EInvoiceMatrixTrait;

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
}
