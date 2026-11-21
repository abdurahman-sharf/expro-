<?php

namespace Tests\Support;

use App\Libraries\EInvoicing\EInvoiceAddress;
use App\Libraries\EInvoicing\EInvoiceCharge;
use App\Libraries\EInvoicing\EInvoiceDocument;
use App\Libraries\EInvoicing\EInvoiceKind;
use App\Libraries\EInvoicing\EInvoiceLine;
use App\Libraries\EInvoicing\EInvoiceParty;
use App\Libraries\EInvoicing\EInvoicePrepayment;
use App\Libraries\EInvoicing\EInvoiceSubtype;
use App\Libraries\EInvoicing\EInvoiceTaxSubtotal;
use App\Libraries\EInvoicing\PartyIdentificationType;

/**
 * The samples of the matrix of the plan (TAX2 §5.3) as documents, each consistent with the rules of the standard as
 * Expro reads them, so that the official validator can be run on them offline (T5). The chain samples carry their
 * counters, and the broken samples are made wrong on purpose and must be refused.
 */
trait EInvoiceMatrixTrait
{
    use EInvoiceFixtureTrait;

    /**
     * @return array<string, EInvoiceDocument> By the identifier of the sample in the plan.
     */
    protected function matrix(): array
    {
        $buyer    = $this->sampleBuyer();
        $crn      = new EInvoiceParty('Buyer Trading', '', PartyIdentificationType::CommercialRegistration, '1010101010', new EInvoiceAddress('King Fahd Rd', '4321', 'Olaya', 'Riyadh', '11564', 'SA'));
        $abroad   = new EInvoiceParty('Gulf Importers', '', PartyIdentificationType::TaxIdentification, '100200300', new EInvoiceAddress('Sheikh Zayed Rd', '10', 'Trade Centre', 'Dubai', '00000', 'AE'));
        $standard = ['S', '15.00'];
        $samples  = [
            'S-01' => $this->sample(EInvoiceKind::Invoice, EInvoiceSubtype::Simplified, [$this->row(1, 'Book', '2.00', '3.0000', '0.00', '6.00', $standard, '0.90')]),
            'S-02' => $this->sample(EInvoiceKind::Invoice, EInvoiceSubtype::Simplified, [
                $this->row(1, 'Laptop', '1.00', '100.0000', '0.00', '100.00', $standard, '15.00'),
                $this->row(2, 'Exported parts', '1.00', '50.0000', '0.00', '50.00', ['Z', '0.00'], '0.00'),
                $this->row(3, 'Account fee', '1.00', '30.0000', '0.00', '30.00', ['E', '0.00'], '0.00'),
            ], [new EInvoiceTaxSubtotal('Z', '0.00', '50.00', '0.00', 'VATEX-SA-32', 'Export of goods'), new EInvoiceTaxSubtotal('E', '0.00', '30.00', '0.00', 'VATEX-SA-29', 'Financial services mentioned in Article 29 of the VAT Regulations')]),
            'S-03' => $this->sample(EInvoiceKind::Invoice, EInvoiceSubtype::Simplified, [$this->row(1, 'Chair', '2.00', '50.0000', '10.00', '90.00', $standard, '13.50')]),
            'S-04' => $this->sample(EInvoiceKind::Invoice, EInvoiceSubtype::Simplified, [
                $this->row(1, 'Sticker', '1.00', '0.1000', '0.00', '0.10', $standard, '0.02'),
                $this->row(2, 'Sticker', '1.00', '0.1000', '0.00', '0.10', $standard, '0.02'),
                $this->row(3, 'Sticker', '1.00', '0.1000', '0.00', '0.10', $standard, '0.02'),
            ], [], '0.05'),
            'S-06' => $this->sample(EInvoiceKind::CreditNote, EInvoiceSubtype::Simplified, [$this->row(1, 'Book', '1.00', '3.0000', '0.00', '3.00', $standard, '0.45')]),
            'S-07' => $this->sample(EInvoiceKind::DebitNote, EInvoiceSubtype::Simplified, [$this->row(1, 'Price increase', '1.00', '10.0000', '0.00', '10.00', $standard, '1.50')]),
            'S-08' => $this->sample(EInvoiceKind::Invoice, EInvoiceSubtype::Simplified, [$this->row(1, 'Saffron', '1.235', '2.5000', '0.00', '3.09', $standard, '0.46')]),
            'S-09' => $this->sample(EInvoiceKind::Invoice, EInvoiceSubtype::Simplified, [$this->row(1, 'Gift', '1.00', '10.0000', '10.00', '0.00', $standard, '0.00')]),
            'B-01' => $this->sample(EInvoiceKind::Invoice, EInvoiceSubtype::Standard, [$this->row(1, 'Server', '1.00', '100.0000', '0.00', '100.00', $standard, '15.00')], [], null, $buyer),
            'B-02' => $this->sample(EInvoiceKind::Invoice, EInvoiceSubtype::Standard, [$this->row(1, 'Server', '1.00', '100.0000', '0.00', '100.00', $standard, '15.00')], [], null, $crn),
            'B-03' => $this->sample(EInvoiceKind::Invoice, EInvoiceSubtype::Standard, [$this->row(1, 'Exported goods', '1.00', '100.0000', '0.00', '100.00', ['Z', '0.00'], '0.00')], [new EInvoiceTaxSubtotal('Z', '0.00', '100.00', '0.00', 'VATEX-SA-32', 'Export of goods')], null, $abroad),
            'B-04' => $this->sample(EInvoiceKind::Invoice, EInvoiceSubtype::Standard, [$this->row(1, 'Loan fee', '1.00', '100.0000', '0.00', '100.00', ['E', '0.00'], '0.00')], [new EInvoiceTaxSubtotal('E', '0.00', '100.00', '0.00', 'VATEX-SA-29', 'Financial services mentioned in Article 29 of the VAT Regulations')], null, $buyer),
            'B-05' => $this->sample(EInvoiceKind::Invoice, EInvoiceSubtype::Standard, [$this->row(1, 'Fine', '1.00', '100.0000', '0.00', '100.00', ['O', '0.00'], '0.00')], [new EInvoiceTaxSubtotal('O', '0.00', '100.00', '0.00', 'VATEX-SA-OOS', 'Not subject to VAT')], null, $buyer),
            'B-06' => $this->amended($this->sample(EInvoiceKind::Invoice, EInvoiceSubtype::Standard, [$this->row(1, 'Consulting', '1.00', '100.0000', '0.00', '100.00', $standard, '15.00')], [], null, $buyer), ['currency' => 'USD', 'taxAmountInSar' => '56.25']),
            'B-07' => $this->amended($this->sample(EInvoiceKind::Invoice, EInvoiceSubtype::Standard, [$this->row(1, 'Desk', '1.00', '100.0000', '0.00', '100.00', $standard, '15.00')], [], null, $buyer), [
                'taxSubtotals' => [new EInvoiceTaxSubtotal('S', '15.00', '110.00', '16.50')],
                'taxExclusive' => '110.00',
                'taxAmount'    => '16.50',
                'taxInclusive' => '126.50',
                'charges'      => [new EInvoiceCharge('10.00', 'S', '15.00')],
            ]),
            'B-08' => $this->sample(EInvoiceKind::Prepayment, EInvoiceSubtype::Standard, [$this->row(1, 'Advance payment', '1.00', '100.0000', '0.00', '100.00', $standard, '15.00')], [], null, $buyer),
            'B-09' => $this->amended($this->sample(EInvoiceKind::Invoice, EInvoiceSubtype::Standard, [$this->row(1, 'Project', '1.00', '200.0000', '0.00', '200.00', $standard, '30.00')], [], null, $buyer), [
                'prepayments' => [new EInvoicePrepayment('ADV-1', '2022-08-01', '09:00:00', 'S', '15.00', '100.00', '15.00')],
            ]),
            'B-10' => $this->sample(EInvoiceKind::CreditNote, EInvoiceSubtype::Standard, [$this->row(1, 'Server', '1.00', '100.0000', '0.00', '100.00', $standard, '15.00')], [], null, $buyer),
            'B-11' => $this->sample(EInvoiceKind::DebitNote, EInvoiceSubtype::Standard, [$this->row(1, 'Price increase', '1.00', '20.0000', '0.00', '20.00', $standard, '3.00')], [], null, $buyer),
            'B-12' => $this->sample(EInvoiceKind::Invoice, EInvoiceSubtype::Standard, [$this->row(1, 'قلم حبر «أزرق» & <ممحاة> "صغيرة" — طقم مدرسي كامل للصف الأول الابتدائي', '1.00', '100.0000', '0.00', '100.00', $standard, '15.00')], [], null, $buyer),
        ];

        foreach ($this->chain() as $key => $document) {
            $samples[$key] = $document;
        }

        return $samples;
    }

    /**
     * Samples broken on purpose (C-02), which the official validator must refuse: a category tax that is not its
     * taxable amount times its rate, and a line that does not add up to its quantity times its price.
     *
     * @return array<string, EInvoiceDocument>
     */
    protected function brokenMatrix(): array
    {
        $base = $this->matrix()['S-01'];

        return [
            'C-02-category-tax' => $this->amended($base, ['taxSubtotals' => [new EInvoiceTaxSubtotal('S', '15.00', '6.00', '0.91')], 'taxAmount' => '0.91', 'taxInclusive' => '6.91']),
            'C-02-line-net'     => $this->amended($base, ['lines' => [$this->row(1, 'Book', '2.00', '3.0000', '0.00', '6.50', ['S', '15.00'], '0.98')]]),
        ];
    }

    /**
     * Three documents in a row (C-01): counters 1, 2 and 3, the first on the first previous hash. The previous hash of
     * the next two is set by the caller once the document before it is stamped.
     *
     * @return array<string, EInvoiceDocument>
     */
    protected function chain(): array
    {
        $chain = [];

        foreach ([1, 2, 3] as $counter) {
            $chain['C-01-' . $counter] = $this->amended($this->sample(EInvoiceKind::Invoice, EInvoiceSubtype::Simplified, [$this->row(1, 'Coffee', '1.00', '10.0000', '0.00', '10.00', ['S', '15.00'], '1.50')]), ['number' => 'C01-' . $counter, 'icv' => $counter, 'uuid' => sprintf('6f0c2b9e-1d3a-4c5b-8e7f-%012d', $counter)]);
        }

        return $chain;
    }

    /**
     * @param array{0: string, 1: string} $category
     */
    private function row(int $id, string $name, string $quantity, string $price, string $allowance, string $net, array $category, string $vat): EInvoiceLine
    {
        return new EInvoiceLine($id, $name, $quantity, 'PCE', $price, $allowance, $net, $category[0], $category[1], $vat, bcadd($net, $vat, 2));
    }

    /**
     * A document of the lines, its standard-rate category worked out from them unless one is given (and the others
     * added), the buyer of the cash customer unless one is given.
     *
     * @param list<EInvoiceLine>        $lines
     * @param list<EInvoiceTaxSubtotal> $others The categories without tax.
     */
    private function sample(EInvoiceKind $kind, EInvoiceSubtype $subtype, array $lines, array $others = [], ?string $standardVat = null, ?EInvoiceParty $buyer = null): EInvoiceDocument
    {
        $base     = $this->simplifiedDocument();
        $taxable  = '0.00';
        $vat      = '0.00';
        $extended = '0.00';

        foreach ($lines as $line) {
            $extended = bcadd($extended, $line->netAmount, 2);

            if ($line->vatCategory === 'S') {
                $taxable = bcadd($taxable, $line->netAmount, 2);
                $vat     = bcadd($vat, $line->vatAmount, 2);
            }
        }

        $vat       = $standardVat ?? $vat;
        $subtotals = bccomp($taxable, '0', 2) === 0 && $others !== [] ? $others : [new EInvoiceTaxSubtotal('S', '15.00', $taxable, $vat), ...$others];

        return $this->amended($base, [
            'kind'             => $kind,
            'subtype'          => $subtype,
            'buyer'            => $buyer ?? $base->buyer,
            'lines'            => $lines,
            'taxSubtotals'     => $subtotals,
            'lineExtension'    => $extended,
            'taxExclusive'     => $extended,
            'taxAmount'        => $vat,
            'taxInclusive'     => bcadd($extended, $vat, 2),
            'reason'           => $kind->isNote() ? 'In case of goods or services refund.' : '',
            'billingReference' => $kind->isNote() ? 'SME00010' : null,
        ]);
    }
}
