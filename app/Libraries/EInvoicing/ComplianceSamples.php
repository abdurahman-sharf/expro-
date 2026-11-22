<?php

namespace App\Libraries\EInvoicing;

/**
 * The documents a unit sends for the compliance checks of its onboarding, before ZATCA gives it a production
 * certificate: an invoice, a credit note and a debit note of each kind of invoice the unit issues (`1100`: tax
 * invoices and simplified ones), from the seller of the company to a buyer made up for the checks, chained among
 * themselves from the first hash. They are not invoices: no sale, no counter of the company and no book is touched.
 */
final class ComplianceSamples
{
    /**
     * @param string $invoiceTypes The kinds of invoices of the unit as its request names them: tax invoices first, then simplified ones.
     * @param string $moment       When the samples are issued, local time as Y-m-d H:i:s.
     *
     * @return list<EInvoiceDocument>
     */
    public function documents(EInvoiceParty $seller, string $invoiceTypes, string $moment): array
    {
        $subtypes = [];

        if (($invoiceTypes[0] ?? '0') === '1') {
            $subtypes[] = EInvoiceSubtype::Standard;
        }

        if (($invoiceTypes[1] ?? '0') === '1') {
            $subtypes[] = EInvoiceSubtype::Simplified;
        }

        $documents = [];
        $previous  = InvoiceHash::FIRST;
        $counter   = 0;

        foreach ($subtypes as $subtype) {
            $invoice = null;

            foreach ([EInvoiceKind::Invoice, EInvoiceKind::CreditNote, EInvoiceKind::DebitNote] as $kind) {
                $counter++;
                $document = $this->document($seller, $subtype, $kind, $counter, $moment, $invoice?->number)->chained(self::uuid(), $counter, $previous);
                $invoice ??= $document;
                $previous    = InvoiceHash::of((new UblInvoiceXml())->build($document, 'compliance'));
                $documents[] = $document;
            }
        }

        return $documents;
    }

    private function document(EInvoiceParty $seller, EInvoiceSubtype $subtype, EInvoiceKind $kind, int $counter, string $moment, ?string $original): EInvoiceDocument
    {
        $buyer = $subtype === EInvoiceSubtype::Standard
            ? new EInvoiceParty('Compliance check buyer', '399999999800003', null, '', new EInvoiceAddress('King Fahd Rd', '4321', 'Olaya', 'Riyadh', '11564', 'SA'))
            : new EInvoiceParty(UblInvoiceXml::CASH_BUYER);

        return new EInvoiceDocument(
            kind: $kind,
            subtype: $subtype,
            number: 'CMP-' . $counter,
            issueDate: substr($moment, 0, 10),
            issueTime: substr($moment, 11, 8),
            supplyDate: substr($moment, 0, 10),
            seller: $seller,
            buyer: $buyer,
            lines: [new EInvoiceLine(1, 'Compliance check', '1.00', 'PCE', '100.0000', '0.00', '100.00', 'S', '15.00', '15.00', '115.00')],
            taxSubtotals: [new EInvoiceTaxSubtotal('S', '15.00', '100.00', '15.00')],
            lineExtension: '100.00',
            taxExclusive: '100.00',
            taxAmount: '15.00',
            taxInclusive: '115.00',
            paymentMeansCodes: ['10'],
            reason: $kind->isNote() ? EInvoiceRules::NOTE_REASONS[1] : '',
            billingReference: $kind->isNote() ? $original : null,
        );
    }

    private static function uuid(): string
    {
        $bytes    = random_bytes(16);
        $bytes[6] = chr((ord($bytes[6]) & 0x0F) | 0x40);
        $bytes[8] = chr((ord($bytes[8]) & 0x3F) | 0x80);

        return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($bytes), 4));
    }
}
