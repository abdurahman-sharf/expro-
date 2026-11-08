<?php

namespace App\Libraries\Documents;

use App\Libraries\Accounting\Money;
use App\Libraries\Accounting\SaleAmounts;
use DateTimeImmutable;
use DateTimeZone;
use ValueError;

/**
 * Whether a printed document carries the code of the first phase of electronic invoicing, and what it
 * holds. Only an invoice, a tax invoice and a credit note carry one, never a quote, a work order, a
 * purchase invoice or a voucher, and none carries one while the tax number of the seller is not
 * entered: a document without it is printed without a code and the screen says why, where the reference
 * system prints a code with an empty tax number. The figures are the ones the document shows: they are
 * read, never worked out again, and a credit note shows them as the amounts it takes back.
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
     * @param array<string, mixed> $sale   The sale as the documents are given it: total, taxes, header_charges, sale_time_raw and invoice_date_raw.
     * @param array<string, mixed> $config The settings of the shop: company and tax_id.
     */
    public function forDocument(string $type, array $sale, array $config): QrResult
    {
        if (! self::applies($type)) {
            return QrResult::none();
        }

        $vatNumber = trim((string) ($config['tax_id'] ?? ''));

        if ($vatNumber === '') {
            return new QrResult(null, DocumentError::QrVatNumberMissing);
        }

        $sellerName = trim((string) ($config['company'] ?? ''));

        if ($sellerName === '') {
            return new QrResult(null, DocumentError::QrSellerMissing);
        }

        try {
            return new QrResult($this->builder->encode(
                $sellerName,
                $vatNumber,
                self::timestamp((string) ($sale['sale_time_raw'] ?? ''), isset($sale['invoice_date_raw']) ? (string) $sale['invoice_date_raw'] : null),
                self::amount((string) ($sale['total'] ?? '0')),
                self::amount(self::taxOf($sale)),
            ));
        } catch (DocumentException $exception) {
            return new QrResult(null, $exception->error);
        } catch (ValueError) {
            return new QrResult(null, DocumentError::QrAmountMalformed);
        }
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
