<?php

namespace App\Libraries\Documents;

use App\Libraries\Accounting\Money;
use App\Libraries\Accounting\SaleAmounts;
use App\Libraries\Tax\VatRegistration;
use DateTimeImmutable;
use DateTimeZone;
use ValueError;

/**
 * Whether a printed document carries the code of the first phase of electronic invoicing, and what it
 * holds. Only an invoice, a tax invoice and a credit note carry one, never a quote, a work order, a
 * purchase invoice or a voucher, nor one printed for a sale that was not issued (set aside or cancelled),
 * and none carries one while the tax number of the seller is not entered: a document without it is
 * printed without a code and the screen says why. A document in a foreign currency carries none either,
 * since the code holds the amounts in the currency of the shop, and no document of a business its owner
 * said is not registered for VAT, which issues no tax invoice and has no tax number to state. The
 * figures are the ones the document shows: they are read, never worked out again, and a credit note
 * shows them as the amounts it takes back.
 */
final class DocumentQr
{
    public const TYPES     = ['invoice', 'tax_invoice', 'credit_note'];
    private const DECIMALS = 2;

    public function __construct(private readonly SimplifiedInvoiceQr $builder = new SimplifiedInvoiceQr())
    {
    }

    public static function applies(string $type): bool
    {
        return in_array($type, self::TYPES, true);
    }

    /**
     * @param array<string, mixed> $sale   The sale as the documents are given it: total, taxes, header_charges, sale_time_raw, invoice_date_raw, sale_status, sale_type and currency_code, and, for a sale whose VAT was worked out once, total_with_vat (BT-112), which the code holds instead of what the register charged. A sale whose electronic document was issued gives its code as einvoice_qr, which is printed as it was issued.
     * @param array<string, mixed> $config The settings of the shop: company and tax_id, and vat_registration, the stored value of its VatRegistration.
     */
    public function forDocument(string $type, array $sale, array $config): QrResult
    {
        if (! self::applies($type) || ! self::issued($sale) || ($config['vat_registration'] ?? null) === VatRegistration::NotRegistered->value) {
            return QrResult::none();
        }

        $issued = trim((string) ($sale['einvoice_qr'] ?? ''));

        if ($issued !== '') {
            return new QrResult($issued);
        }

        $vatNumber = trim((string) ($config['tax_id'] ?? ''));

        if ($vatNumber === '') {
            return new QrResult(null, DocumentError::QrVatNumberMissing);
        }

        if (trim((string) ($sale['currency_code'] ?? '')) !== '') {
            return new QrResult(null, DocumentError::QrForeignCurrency);
        }

        $sellerName = trim((string) ($config['company'] ?? ''));

        try {
            return new QrResult($this->builder->encode(
                $sellerName,
                $vatNumber,
                self::timestamp((string) ($sale['sale_time_raw'] ?? ''), isset($sale['invoice_date_raw']) ? (string) $sale['invoice_date_raw'] : null),
                self::amount((string) ($sale['total_with_vat'] ?? $sale['total'] ?? '0')),
                self::amount(self::taxOf($sale)),
            ));
        } catch (DocumentException $exception) {
            return new QrResult(null, $exception->error);
        } catch (ValueError) {
            return new QrResult(null, DocumentError::QrAmountMalformed);
        }
    }

    /**
     * Whether the sale is an invoice or a return that was issued: a sale that is only set aside or was
     * cancelled, and a quote or a work order, are not, so a document printed for them carries no code.
     *
     * @param array<string, mixed> $sale
     */
    private static function issued(array $sale): bool
    {
        return (int) ($sale['sale_status'] ?? COMPLETED) === COMPLETED
            && ! in_array((int) ($sale['sale_type'] ?? SALE_TYPE_INVOICE), [SALE_TYPE_QUOTE, SALE_TYPE_WORK_ORDER], true);
    }

    /**
     * The day of the document and the time the sale was saved, as universal time.
     */
    public static function timestamp(string $saleTime, ?string $invoiceDate): string
    {
        $time = strtotime($saleTime);

        if ($time === false) {
            return '';
        }

        $day   = $invoiceDate !== null && preg_match('/^\d{4}-\d{2}-\d{2}$/', $invoiceDate) === 1 ? $invoiceDate : date('Y-m-d', $time);
        $local = new DateTimeImmutable($day . ' ' . date('H:i:s', $time));

        return $local->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d\TH:i:s\Z');
    }

    /**
     * @param array<string, mixed> $sale
     *
     * @return numeric-string
     */
    private static function taxOf(array $sale): string
    {
        $tax = '0';

        foreach ($sale['taxes'] ?? [] as $row) {
            $tax = bcadd($tax, SaleAmounts::absolute((string) ($row['sale_tax_amount'] ?? '0')), SaleAmounts::SCALE);
        }

        $shipping = (string) ($sale['header_charges']['shipping_tax'] ?? '0');

        return bcadd($tax, SaleAmounts::absolute($shipping), SaleAmounts::SCALE);
    }

    private static function amount(string $amount): string
    {
        return Money::round(SaleAmounts::absolute($amount), self::DECIMALS);
    }
}
