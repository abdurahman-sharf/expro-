<?php

use Config\OSPOS;

/**
 * Document types rendered by the shared document template and exported to PDF.
 *
 * @return list<string>
 */
function documentTypes(): array
{
    return ['invoice', 'tax_invoice', 'quote', 'work_order'];
}

/**
 * Describes what a document shows: its title, numbering, meta rows and optional columns and blocks.
 *
 * @param string $type One of documentTypes().
 * @param array  $sale Sale data as built by the Sales controller.
 *
 * @return array{type: string, title: string, number: string, meta: array<int, array{0: string, 1: string}>, showHsn: bool, showPrices: bool, showUnitDiscount: bool, showPayments: bool, showSignature: bool}
 */
function documentProfile(string $type, array $sale): array
{
    $showPrices = $type !== 'work_order' || ($sale['print_price_info'] ?? true);

    [$title, $numberLabel, $number] = match ($type) {
        'quote'       => [lang('Sales.quote'), lang('Sales.quote_number'), (string) ($sale['quote_number'] ?? '')],
        'work_order'  => [lang('Sales.work_order'), lang('Sales.work_order_number'), (string) ($sale['work_order_number'] ?? '')],
        'tax_invoice' => [lang('Sales.tax_invoice'), lang('Sales.invoice_number'), (string) ($sale['invoice_number'] ?? '')],
        default       => [lang('Sales.invoice'), lang('Sales.invoice_number'), (string) ($sale['invoice_number'] ?? '')],
    };

    $meta = [
        [$numberLabel, $number],
        [lang('Common.date'), (string) ($sale['transaction_date'] ?? '')],
    ];

    if ($showPrices) {
        $meta[] = [lang(in_array($type, ['invoice', 'tax_invoice'], true) ? 'Sales.invoice_total' : 'Sales.total'), to_currency((string) ($sale['total'] ?? 0))];
    }

    if ($showPrices && in_array($type, ['invoice', 'tax_invoice'], true) && (float) ($sale['amount_due'] ?? 0) > 0) {
        $meta[] = [lang('Sales.amount_due'), to_currency((string) $sale['amount_due'])];
    }

    return [
        'type'             => $type,
        'title'            => $title,
        'number'           => $number,
        'meta'             => $meta,
        'showHsn'          => ! empty($sale['include_hsn']) && in_array($type, ['invoice', 'tax_invoice'], true),
        'showPrices'       => $showPrices,
        'showUnitDiscount' => $showPrices && (float) ($sale['discount'] ?? 0) > 0,
        'showPayments'     => $showPrices && in_array($type, ['invoice', 'tax_invoice'], true),
        'showSignature'    => in_array($type, ['tax_invoice', 'work_order'], true),
    ];
}

/**
 * Source of the company logo: a URL on screen, a local file in PDF files where remote access is blocked.
 *
 * @param array<string, mixed> $config
 */
function documentLogoSource(array $config, string $context): string
{
    $logo = (string) ($config['company_logo'] ?? '');

    if ($logo === '') {
        return '';
    }

    if ($context === 'pdf') {
        $path = FCPATH . 'uploads/' . basename($logo);

        return is_file($path) ? $path : '';
    }

    return base_url('uploads/' . rawurlencode(basename($logo)));
}

/**
 * Keeps amounts and numbers in left-to-right order inside right-to-left text.
 */
function documentIsolate(string $text): string
{
    return '<span dir="ltr">' . esc($text) . '</span>';
}

/**
 * Escapes a table cell, and keeps numeric values (amounts, dates, quantities) left-to-right.
 */
function documentCell(string $text): string
{
    $isNumeric = preg_match('/\d/', $text) === 1 && preg_match_all('/\p{L}/u', $text) <= 3;

    return $isNumeric ? documentIsolate($text) : nl2br(esc($text));
}

/**
 * Barcode (SVG markup) as an image source usable on screen and in PDF files.
 */
function documentBarcodeSource(string $barcode): string
{
    if ($barcode === '') {
        return '';
    }

    return 'data:image/svg+xml;base64,' . base64_encode(preg_replace('/<!DOCTYPE[^>]*>/i', '', $barcode));
}

/**
 * @return array<string, mixed>
 */
function documentConfig(): array
{
    return config(OSPOS::class)->settings;
}
