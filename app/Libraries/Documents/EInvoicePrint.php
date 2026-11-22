<?php

namespace App\Libraries\Documents;

use App\Libraries\EInvoicing\UblInvoiceXml;
use App\Libraries\Organization\CompanyContext;
use CodeIgniter\Database\BaseConnection;
use Config\Database;
use DOMDocument;
use DOMNode;
use DOMXPath;

/**
 * What the printout of a document issued under the Saudi rules shows, read from the file that was issued and never
 * worked out again, so that the paper says what the electronic invoice says (T7): its kind, number and days, the tax
 * numbers of the two parties, the document a note corrects and why, every line with its VAT, the VAT of each category
 * and the totals. A tax invoice ZATCA cleared is printed from the file it cleared, with the code ZATCA put in it, and a
 * document issued again in place of a refused one stands for its sale. A sale without an issued document has nothing
 * here, and is printed as it always was.
 */
final class EInvoicePrint
{
    private const NAMESPACES = [
        'ubl' => 'urn:oasis:names:specification:ubl:schema:xsd:Invoice-2',
        'cbc' => 'urn:oasis:names:specification:ubl:schema:xsd:CommonBasicComponents-2',
        'cac' => 'urn:oasis:names:specification:ubl:schema:xsd:CommonAggregateComponents-2',
    ];
    private const TITLES = [
        '381' => 'credit_note',
        '383' => 'debit_note',
        '386' => 'prepayment',
    ];

    private BaseConnection $db;

    public function __construct(?BaseConnection $db = null)
    {
        $this->db = $db ?? Database::connect();
    }

    /**
     * @return array<string, mixed>|null
     */
    public function forSale(int $saleId): ?array
    {
        return $this->forSource('sale', $saleId);
    }

    /**
     * @return array<string, mixed>|null
     */
    public function forSource(string $sourceType, int $sourceId): ?array
    {
        if (! $this->db->tableExists($this->db->prefixTable('einvoice_documents'), false)) {
            return null;
        }

        $row = $this->db->table('einvoice_documents')->select('document_id, xml, qr_text')->where(['company_id' => CompanyContext::id(), 'source_type' => $sourceType, 'source_id' => $sourceId])->get()->getRow();

        for ($depth = 0; $row !== null && $depth < 20; $depth++) {
            $again = $this->db->table('einvoice_documents')->select('document_id, xml, qr_text')->where(['company_id' => CompanyContext::id(), 'source_type' => 'reissue', 'source_id' => (int) $row->document_id])->get()->getRow();

            if ($again === null) {
                break;
            }

            $row = $again;
        }

        if ($row === null) {
            return null;
        }

        $cleared = $this->db->tableExists($this->db->prefixTable('einvoice_cleared_documents'), false) ? $this->db->table('einvoice_cleared_documents')->select('xml, qr_text')->where('document_id', (int) $row->document_id)->get()->getRow() : null;
        $xml     = $cleared === null ? (string) $row->xml : (string) $cleared->xml;
        $print   = self::fromXml($xml);

        if ($print === null) {
            return null;
        }

        $qr = $cleared === null ? (string) ($row->qr_text ?? '') : (trim((string) ($cleared->qr_text ?? '')) !== '' ? (string) $cleared->qr_text : self::qrOf($xml));

        return [...$print, 'qr' => $qr];
    }

    /**
     * The code a file carries (KSA-14), as ZATCA writes it in the file it cleared.
     */
    private static function qrOf(string $xml): string
    {
        $document = new DOMDocument();

        if (! @$document->loadXML($xml)) {
            return '';
        }

        $xpath = new DOMXPath($document);
        $xpath->registerNamespace('cac', UblInvoiceXml::NS_CAC);
        $xpath->registerNamespace('cbc', UblInvoiceXml::NS_CBC);

        return trim((string) $xpath->evaluate("string(//cac:AdditionalDocumentReference[cbc:ID='QR']/cac:Attachment/cbc:EmbeddedDocumentBinaryObject)"));
    }

    /**
     * @return array{title: string, number: string, issue_date: string, issue_time: string, supply_date: string, currency: string, seller: array{name: string, vat: string}, buyer: array{name: string, vat: string}, original: string, reason: string, lines: list<array<string, bool|string>>, categories: list<array<string, string>>, totals: array<string, string>}|null Null when the file cannot be read.
     */
    public static function fromXml(string $xml): ?array
    {
        $dom = new DOMDocument();

        if (trim($xml) === '' || ! $dom->loadXML($xml, LIBXML_NONET | LIBXML_NOERROR | LIBXML_NOWARNING)) {
            return null;
        }

        $path = new DOMXPath($dom);

        foreach (self::NAMESPACES as $prefix => $namespace) {
            $path->registerNamespace($prefix, $namespace);
        }

        $text = static fn (string $query, ?DOMNode $context = null): string => trim((string) $path->evaluate('string(' . $query . ')', $context));
        $type = $text('/ubl:Invoice/cbc:InvoiceTypeCode');

        if ($type === '') {
            return null;
        }

        $lines = [];

        foreach ($path->query('/ubl:Invoice/cac:InvoiceLine') ?: [] as $line) {
            $lines[] = [
                'id'         => $text('cbc:ID', $line),
                'name'       => $text('cac:Item/cbc:Name', $line),
                'quantity'   => $text('cbc:InvoicedQuantity', $line),
                'price'      => $text('cac:Price/cbc:PriceAmount', $line),
                'net'        => $text('cbc:LineExtensionAmount', $line),
                'vat'        => $text('cac:TaxTotal/cbc:TaxAmount', $line),
                'gross'      => $text('cac:TaxTotal/cbc:RoundingAmount', $line),
                'category'   => $text('cac:Item/cac:ClassifiedTaxCategory/cbc:ID', $line),
                'percent'    => $text('cac:Item/cac:ClassifiedTaxCategory/cbc:Percent', $line),
                'prepayment' => $text('cac:DocumentReference/cbc:ID', $line),
                'prepaid'    => $text('cac:TaxTotal/cac:TaxSubtotal/cbc:TaxableAmount', $line),
                'prepaidVat' => $text('cac:TaxTotal/cac:TaxSubtotal/cbc:TaxAmount', $line),
            ];
        }

        $categories = [];
        $vat        = '';
        $vatInSar   = '';

        foreach ($path->query('/ubl:Invoice/cac:TaxTotal') ?: [] as $total) {
            $subtotals = $path->query('cac:TaxSubtotal', $total);

            if ($subtotals === false || $subtotals->length === 0) {
                $vatInSar = $text('cbc:TaxAmount', $total);

                continue;
            }

            $vat = $text('cbc:TaxAmount', $total);

            foreach ($subtotals as $subtotal) {
                $categories[] = [
                    'category'  => $text('cac:TaxCategory/cbc:ID', $subtotal),
                    'percent'   => $text('cac:TaxCategory/cbc:Percent', $subtotal),
                    'taxable'   => $text('cbc:TaxableAmount', $subtotal),
                    'vat'       => $text('cbc:TaxAmount', $subtotal),
                    'exemption' => $text('cac:TaxCategory/cbc:TaxExemptionReason', $subtotal),
                ];
            }
        }

        $party = static fn (string $role): array => [
            'name' => $text('/ubl:Invoice/cac:' . $role . '/cac:Party/cac:PartyLegalEntity/cbc:RegistrationName'),
            'vat'  => $text('/ubl:Invoice/cac:' . $role . '/cac:Party/cac:PartyTaxScheme/cbc:CompanyID'),
        ];
        $money = static fn (string $name): string => $text('/ubl:Invoice/cac:LegalMonetaryTotal/cbc:' . $name);
        $name  = trim((string) $path->evaluate('string(/ubl:Invoice/cbc:InvoiceTypeCode/@name)'));

        return [
            'title'       => self::TITLES[$type] ?? (str_starts_with($name, '01') ? 'standard' : 'simplified'),
            'number'      => $text('/ubl:Invoice/cbc:ID'),
            'issue_date'  => $text('/ubl:Invoice/cbc:IssueDate'),
            'issue_time'  => $text('/ubl:Invoice/cbc:IssueTime'),
            'supply_date' => $text('/ubl:Invoice/cac:Delivery/cbc:ActualDeliveryDate'),
            'currency'    => $text('/ubl:Invoice/cbc:DocumentCurrencyCode'),
            'seller'      => $party('AccountingSupplierParty'),
            'buyer'       => $party('AccountingCustomerParty'),
            'original'    => $text('/ubl:Invoice/cac:BillingReference/cac:InvoiceDocumentReference/cbc:ID'),
            'reason'      => $text('/ubl:Invoice/cac:PaymentMeans/cbc:InstructionNote'),
            'lines'       => $lines,
            'categories'  => $categories,
            'totals'      => [
                'lines'     => $money('LineExtensionAmount'),
                'exclusive' => $money('TaxExclusiveAmount'),
                'vat'       => $vat,
                'vatInSar'  => $vatInSar,
                'inclusive' => $money('TaxInclusiveAmount'),
                'charges'   => $money('ChargeTotalAmount'),
                'prepaid'   => $money('PrepaidAmount'),
                'rounding'  => $money('PayableRoundingAmount'),
                'payable'   => $money('PayableAmount'),
            ],
        ];
    }
}
