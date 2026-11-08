<?php

namespace App\Libraries\EInvoicing;

use LogicException;

/**
 * The UBL 2.1 file of a document, a candidate built from the structure the standard publishes (the XML
 * implementation standard, version 1.2, and its data dictionary). It is pure: it reads nothing and writes
 * nothing, and gives the same bytes for the same document. The elements come in the order of the UBL
 * Invoice schema, and the text holds no whitespace between elements, so that taking out the extensions, the
 * code and the signature leaves the bytes of the document as they were before they were put in, which is
 * what the hash of the document is taken over.
 */
final class UblInvoiceXml
{
    public const NS_INVOICE = 'urn:oasis:names:specification:ubl:schema:xsd:Invoice-2';
    public const NS_CAC     = 'urn:oasis:names:specification:ubl:schema:xsd:CommonAggregateComponents-2';
    public const NS_CBC     = 'urn:oasis:names:specification:ubl:schema:xsd:CommonBasicComponents-2';
    public const NS_EXT     = 'urn:oasis:names:specification:ubl:schema:xsd:CommonExtensionComponents-2';
    public const PROFILE    = 'reporting:1.0';
    public const CASH_BUYER = 'Cash customer';

    /**
     * @param string      $qr         The text of the code (KSA-14).
     * @param string|null $extensions The UBL extensions with the stamp, when the document is signed.
     */
    public function build(EInvoiceDocument $document, string $qr, ?string $extensions = null): string
    {
        if ($document->uuid === '' || $document->icv < 1 || $document->previousHash === '') {
            throw new LogicException('The document has no identifier, counter or previous hash yet.');
        }

        $parts = [
            $extensions ?? '',
            $this->el('cbc:ProfileID', self::PROFILE),
            $this->el('cbc:ID', $document->number),
            $this->el('cbc:UUID', $document->uuid),
            $this->el('cbc:IssueDate', $document->issueDate),
            $this->el('cbc:IssueTime', $document->issueTime),
            $this->el('cbc:InvoiceTypeCode', $document->kind->typeCode(), ['name' => $document->subtype->transactionCode()]),
            $this->el('cbc:DocumentCurrencyCode', $document->currency),
            $this->el('cbc:TaxCurrencyCode', 'SAR'),
            $this->billingReference($document),
            $this->el('cac:AdditionalDocumentReference', null, [], $this->el('cbc:ID', 'ICV') . $this->el('cbc:UUID', (string) $document->icv)),
            $this->attachment('PIH', $document->previousHash),
            $this->attachment('QR', $qr),
            $extensions === null ? '' : $this->signatureReference(),
            $this->supplier($document->seller),
            $this->customer($document->buyer),
            $this->el('cac:Delivery', null, [], $this->el('cbc:ActualDeliveryDate', $document->supplyDate)),
            $this->paymentMeans($document),
            $this->taxTotals($document),
            $this->legalMonetaryTotal($document),
            ...array_map(fn (EInvoiceLine $line): string => $this->line($document, $line), $document->lines),
        ];

        $root = $this->el('Invoice', null, [], implode('', $parts), true);

        return '<?xml version="1.0" encoding="UTF-8"?>' . $root;
    }

    private function billingReference(EInvoiceDocument $document): string
    {
        if ($document->billingReference === null) {
            return '';
        }

        return $this->el('cac:BillingReference', null, [], $this->el('cac:InvoiceDocumentReference', null, [], $this->el('cbc:ID', $document->billingReference)));
    }

    private function attachment(string $id, string $text): string
    {
        return $this->el('cac:AdditionalDocumentReference', null, [], $this->el('cbc:ID', $id) . $this->el('cac:Attachment', null, [], $this->el('cbc:EmbeddedDocumentBinaryObject', $text, ['mimeCode' => 'text/plain'])));
    }

    private function signatureReference(): string
    {
        return $this->el('cac:Signature', null, [], $this->el('cbc:ID', 'urn:oasis:names:specification:ubl:signature:Invoice') . $this->el('cbc:SignatureMethod', 'urn:oasis:names:specification:ubl:dsig:enveloped:xades'));
    }

    private function supplier(EInvoiceParty $party): string
    {
        return $this->el('cac:AccountingSupplierParty', null, [], $this->party($party));
    }

    private function customer(EInvoiceParty $party): string
    {
        return $this->el('cac:AccountingCustomerParty', null, [], $this->party($party));
    }

    private function party(EInvoiceParty $party): string
    {
        $identification = $party->identificationType === null || $party->identificationNumber === ''
            ? ''
            : $this->el('cac:PartyIdentification', null, [], $this->el('cbc:ID', $party->identificationNumber, ['schemeID' => $party->identificationType->value]));

        $tax = $party->vatNumber === ''
            ? ''
            : $this->el('cac:PartyTaxScheme', null, [], $this->el('cbc:CompanyID', $party->vatNumber) . $this->el('cac:TaxScheme', null, [], $this->el('cbc:ID', 'VAT')));

        return $this->el('cac:Party', null, [], $identification . $this->address($party->address) . $tax . $this->el('cac:PartyLegalEntity', null, [], $this->el('cbc:RegistrationName', $party->name)));
    }

    private function address(?EInvoiceAddress $address): string
    {
        if ($address === null) {
            return '';
        }

        return $this->el('cac:PostalAddress', null, [], implode('', [
            $this->el('cbc:StreetName', $address->street),
            $this->el('cbc:AdditionalStreetName', $address->additionalStreet),
            $this->el('cbc:BuildingNumber', $address->buildingNumber),
            $this->el('cbc:PlotIdentification', $address->additionalNumber),
            $this->el('cbc:CitySubdivisionName', $address->district),
            $this->el('cbc:CityName', $address->city),
            $this->el('cbc:PostalZone', $address->postalCode),
            $this->el('cbc:CountrySubentity', $address->province),
            $this->el('cac:Country', null, [], $this->el('cbc:IdentificationCode', $address->countryCode)),
        ]));
    }

    private function paymentMeans(EInvoiceDocument $document): string
    {
        $codes = $document->paymentMeansCodes;

        if ($codes === [] && $document->kind->isNote()) {
            $codes = ['10'];
        }

        $means = [];

        foreach ($codes as $index => $code) {
            $note = $index === 0 && $document->kind->isNote() ? $this->el('cbc:InstructionNote', $document->reason) : '';

            $means[] = $this->el('cac:PaymentMeans', null, [], $this->el('cbc:PaymentMeansCode', $code) . $note);
        }

        return implode('', $means);
    }

    private function taxTotals(EInvoiceDocument $document): string
    {
        $subtotals = array_map(fn (EInvoiceTaxSubtotal $subtotal): string => $this->el('cac:TaxSubtotal', null, [], implode('', [
            $this->amount('cbc:TaxableAmount', $subtotal->taxable, $document->currency),
            $this->amount('cbc:TaxAmount', $subtotal->taxAmount, $document->currency),
            $this->el('cac:TaxCategory', null, [], implode('', [
                $this->el('cbc:ID', $subtotal->category),
                $this->el('cbc:Percent', $subtotal->rate),
                $this->el('cbc:TaxExemptionReasonCode', $subtotal->exemptionCode),
                $this->el('cbc:TaxExemptionReason', $subtotal->exemptionText),
                $this->el('cac:TaxScheme', null, [], $this->el('cbc:ID', 'VAT')),
            ])),
        ])), $document->taxSubtotals);

        return $this->el('cac:TaxTotal', null, [], $this->amount('cbc:TaxAmount', $document->taxAmount, $document->currency))
            . $this->el('cac:TaxTotal', null, [], $this->amount('cbc:TaxAmount', $document->taxAmount, $document->currency) . implode('', $subtotals));
    }

    private function legalMonetaryTotal(EInvoiceDocument $document): string
    {
        return $this->el('cac:LegalMonetaryTotal', null, [], implode('', [
            $this->amount('cbc:LineExtensionAmount', $document->lineExtension, $document->currency),
            $this->amount('cbc:TaxExclusiveAmount', $document->taxExclusive, $document->currency),
            $this->amount('cbc:TaxInclusiveAmount', $document->taxInclusive, $document->currency),
            $this->amount('cbc:AllowanceTotalAmount', '0', $document->currency),
            $this->amount('cbc:PrepaidAmount', '0', $document->currency),
            $this->amount('cbc:PayableAmount', $document->taxInclusive, $document->currency),
        ]));
    }

    private function line(EInvoiceDocument $document, EInvoiceLine $line): string
    {
        $allowance = bccomp($line->allowance, '0', 2) > 0
            ? $this->el('cac:AllowanceCharge', null, [], $this->el('cbc:ChargeIndicator', 'false') . $this->el('cbc:AllowanceChargeReason', 'discount') . $this->amount('cbc:Amount', $line->allowance, $document->currency))
            : '';

        return $this->el('cac:InvoiceLine', null, [], implode('', [
            $this->el('cbc:ID', (string) $line->id),
            $this->el('cbc:InvoicedQuantity', $line->quantity, ['unitCode' => $line->unitCode]),
            $this->amount('cbc:LineExtensionAmount', $line->netAmount, $document->currency),
            $allowance,
            $this->el('cac:TaxTotal', null, [], $this->amount('cbc:TaxAmount', $line->vatAmount, $document->currency) . $this->amount('cbc:RoundingAmount', $line->amountWithVat, $document->currency)),
            $this->el('cac:Item', null, [], $this->el('cbc:Name', $line->name) . $this->el('cac:ClassifiedTaxCategory', null, [], $this->el('cbc:ID', $line->vatCategory) . $this->el('cbc:Percent', $line->vatRate) . $this->el('cac:TaxScheme', null, [], $this->el('cbc:ID', 'VAT')))),
            $this->el('cac:Price', null, [], $this->price($line->priceNet, $document->currency)),
        ]));
    }

    private function price(string $price, string $currency): string
    {
        return $this->el('cbc:PriceAmount', $this->trimmed($price, 2, 4), ['currencyID' => $currency]);
    }

    private function amount(string $name, string $amount, string $currency): string
    {
        return $this->el($name, bcadd($amount, '0', 2), ['currencyID' => $currency]);
    }

    private function trimmed(string $value, int $minimum, int $maximum): string
    {
        $fixed = bcadd($value, '0', $maximum);
        [$whole, $fraction] = explode('.', $fixed) + [1 => ''];

        return $whole . '.' . str_pad(rtrim($fraction, '0'), $minimum, '0');
    }

    /**
     * @param array<string, string> $attributes
     */
    private function el(string $name, ?string $text, array $attributes = [], string $children = '', bool $root = false): string
    {
        if ($text === null && $children === '') {
            return '';
        }

        if ($text === '') {
            return '';
        }

        $attributeText = '';

        if ($root) {
            $attributeText = ' xmlns="' . self::NS_INVOICE . '" xmlns:cac="' . self::NS_CAC . '" xmlns:cbc="' . self::NS_CBC . '" xmlns:ext="' . self::NS_EXT . '"';
        }

        foreach ($attributes as $attribute => $value) {
            $attributeText .= ' ' . $attribute . '="' . self::escape($value, true) . '"';
        }

        return '<' . $name . $attributeText . '>' . ($text === null ? $children : self::escape($text)) . '</' . $name . '>';
    }

    public static function escape(string $text, bool $attribute = false): string
    {
        $clean = preg_replace('/[^\x{9}\x{A}\x{D}\x{20}-\x{D7FF}\x{E000}-\x{FFFD}\x{10000}-\x{10FFFF}]/u', '', $text) ?? '';

        return htmlspecialchars($clean, ENT_XML1 | ($attribute ? ENT_QUOTES : ENT_NOQUOTES), 'UTF-8');
    }
}
