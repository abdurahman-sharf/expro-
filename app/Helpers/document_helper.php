<?php

use App\Libraries\Documents\DocumentQr;
use App\Libraries\Documents\DocumentTerms;
use App\Libraries\Documents\QrImage;
use App\Libraries\Documents\QrResult;
use App\Libraries\Integrations\EntityTranslations;
use App\Libraries\Integrations\TranslationKind;
use App\Libraries\Tax\CompanyTaxProfile;
use App\Libraries\Tax\VatRegistration;
use Config\OSPOS;

/**
 * Document types rendered by the shared document template and exported to PDF.
 *
 * @return list<string>
 */
function documentTypes(): array
{
    return ['invoice', 'tax_invoice', 'quote', 'work_order', 'credit_note'];
}

/**
 * The slips of a sale: documents that go with the goods and carry no price.
 *
 * @return list<string>
 */
function slipTypes(): array
{
    return ['packing_slip', 'pick_list', 'shipping_label'];
}

/**
 * Whether the business is registered for VAT, as its owner said it: what the document is given, or what the tax
 * profile holds.
 *
 * @param array<string, mixed> $sale
 */
function documentVatRegistration(array $sale): VatRegistration
{
    $given = $sale['vat_registration'] ?? null;

    return is_string($given) && VatRegistration::tryFrom($given) !== null ? VatRegistration::from($given) : (new CompanyTaxProfile())->current()->registration;
}

/**
 * Describes what a document shows: its title, numbering, meta rows and optional columns and blocks. A business its
 * owner said is not registered for VAT issues no tax invoice: asked for one, it prints the plain invoice.
 *
 * @param string $type One of documentTypes().
 * @param array  $sale Sale data as built by the Sales controller.
 *
 * @return array{type: string, title: string, number: string, meta: array<int, array{0: string, 1: string}>, partyLabel: string, showHsn: bool, showPrices: bool, showUnitDiscount: bool, showPayments: bool, showSignature: bool, showQr: bool, absolute: bool}
 */
function documentProfile(string $type, array $sale): array
{
    if ($type === 'tax_invoice' && documentVatRegistration($sale) === VatRegistration::NotRegistered) {
        $type = 'invoice';
    }

    $showPrices = $type !== 'work_order' || ($sale['print_price_info'] ?? true);
    $isInvoice  = in_array($type, ['invoice', 'tax_invoice'], true);

    [$title, $numberLabel, $number, $dateLabel] = match ($type) {
        'quote'       => [lang('Sales.quote'), lang('Sales.quote_number'), (string) ($sale['quote_number'] ?? ''), lang('Common.date')],
        'work_order'  => [lang('Sales.work_order'), lang('Sales.work_order_number'), (string) ($sale['work_order_number'] ?? ''), lang('Common.date')],
        'tax_invoice' => [lang('Sales.tax_invoice'), lang('Sales.invoice_number'), (string) ($sale['invoice_number'] ?? ''), lang('Common.date')],
        'credit_note' => documentReturnLabels($sale),
        default       => [lang('Sales.invoice'), lang('Sales.invoice_number'), (string) ($sale['invoice_number'] ?? ''), lang('Common.date')],
    };

    $meta = [
        [$numberLabel, $number],
        [$dateLabel, (string) ($sale['transaction_date'] ?? '')],
    ];

    if (! empty($sale['due_date']) && $isInvoice) {
        $meta[] = [lang('Sales.due_date'), (string) $sale['due_date']];
    }

    if ($showPrices) {
        $meta[] = [lang($isInvoice ? 'Sales.invoice_total' : 'Sales.total'), to_currency(documentFigure($type === 'credit_note', (string) ($sale['total'] ?? 0)))];
    }

    if ($showPrices && $isInvoice && (float) ($sale['amount_due'] ?? 0) > 0) {
        $meta[] = [lang('Sales.amount_due'), to_currency((string) $sale['amount_due'])];
    }

    return [
        'type'             => $type,
        'title'            => $title,
        'number'           => $number,
        'meta'             => $meta,
        'partyLabel'       => lang($type === 'credit_note' ? 'Documents.credit_note_to' : 'Sales.customer'),
        'showHsn'          => ! empty($sale['include_hsn']) && $isInvoice,
        'showPrices'       => $showPrices,
        'showUnitDiscount' => $showPrices && (float) ($sale['discount'] ?? 0) > 0,
        'showPayments'     => $showPrices && $isInvoice,
        'showSignature'    => in_array($type, ['tax_invoice', 'work_order'], true),
        'showQr'           => DocumentQr::applies($type),
        'absolute'         => $type === 'credit_note',
    ];
}

/**
 * The title, the labels of the number and of the date, and the number of a return as it was issued: a
 * credit note, or a refund invoice when that is what the return was issued as.
 *
 * @param array<string, mixed> $sale
 *
 * @return array{0: string, 1: string, 2: string, 3: string}
 */
function documentReturnLabels(array $sale): array
{
    if (empty($sale['creditNoteNumber']) && ! empty($sale['refundInvoiceNumber'])) {
        return [lang('Numbering.type_refund_invoice'), lang('Documents.refund_invoice_number'), (string) $sale['refundInvoiceNumber'], lang('Documents.refund_invoice_date')];
    }

    return [lang('Sales.credit_note'), lang('Documents.credit_note_number'), (string) ($sale['creditNoteNumber'] ?? ''), lang('Documents.credit_note_date')];
}

/**
 * Describes what a slip of a sale shows: its title and the blocks of text it carries, a label and a
 * text each. The slips carry no price and no tax: they go with the goods.
 *
 * @param array<string, mixed> $sale
 *
 * @return array{type: string, title: string, number: string, meta: array<int, array{0: string, 1: string}>, blocks: list<array{0: string, 1: string}>}
 */
function slipProfile(string $type, array $sale, array $config): array
{
    $number    = (string) ($sale['invoice_number'] ?? '');
    $customer  = trim((string) ($sale['customer_info'] ?? ''));
    $shipTo    = trim((string) ($sale['ship_to'] ?? '')) !== '' ? trim((string) $sale['ship_to']) : $customer;
    $company   = trim(implode("\n", array_filter([(string) ($config['company'] ?? ''), documentCompanyInfo($config)], static fn (string $line): bool => $line !== '')));
    $locations = [];

    foreach ($sale['cart'] ?? [] as $line) {
        if (! empty($line['stock_name'])) {
            $locations[(string) $line['stock_name']] = true;
        }
    }

    $blocks = match ($type) {
        'pick_list'      => [[lang('Documents.warehouse'), implode("\n", array_keys($locations))]],
        'shipping_label' => [[lang('Documents.from'), $company], [lang('Documents.ship_to'), $shipTo]],
        default          => [[lang('Documents.invoice_to'), $customer], [lang('Documents.ship_to'), $shipTo]],
    };

    $meta = [
        [lang('Sales.invoice_number'), $number !== '' ? $number : (string) ($sale['sale_id'] ?? '')],
        [lang('Common.date'), (string) ($sale['transaction_date'] ?? '')],
    ];

    if ((string) ($sale['employee'] ?? '') !== '') {
        $meta[] = [lang('Sales.employee'), (string) $sale['employee']];
    }

    return [
        'type'   => $type,
        'title'  => lang('Documents.' . $type),
        'number' => $number !== '' ? $number : (string) ($sale['sale_id'] ?? ''),
        'meta'   => $meta,
        'blocks' => array_values(array_filter($blocks, static fn (array $block): bool => trim($block[1]) !== '')),
    ];
}

/**
 * Describes what the purchase invoice of a receiving shows: its title (a debit note when the goods went back
 * to the supplier), its number and the rows next to it.
 *
 * @param array<string, mixed> $receiving The data of the receiving as the Receivings controller builds it.
 *
 * @return array{type: string, title: string, number: string, meta: array<int, array{0: string, 1: string}>, partyLabel: string, showPayments: bool, absolute: bool}
 */
function purchaseDocumentProfile(array $receiving): array
{
    $isReturn = ($receiving['purchase_type'] ?? 'receive') === 'return';
    $number   = (string) ($receiving['documentNumber'] ?? '');
    $day      = (string) ($receiving['invoice_date'] ?? '') !== '' ? (string) $receiving['invoice_date'] : (string) ($receiving['transaction_time'] ?? '');
    $total    = documentFigure($isReturn, (string) ($receiving['total_with_tax'] ?? $receiving['total'] ?? 0));

    $meta = [
        [lang($isReturn ? 'Documents.debit_note_number' : 'Documents.purchase_number'), $number !== '' ? $number : (string) ($receiving['receiving_id'] ?? '')],
        [lang('Common.date'), $day],
    ];

    if ((string) ($receiving['due_date'] ?? '') !== '') {
        $meta[] = [lang('Sales.due_date'), (string) $receiving['due_date']];
    }

    if ((string) ($receiving['reference'] ?? '') !== '') {
        $meta[] = [lang('Receivings.reference'), (string) $receiving['reference']];
    }

    $meta[] = [lang('Sales.total'), to_currency($total)];

    return [
        'type'         => 'purchase_invoice',
        'title'        => lang($isReturn ? 'Receivings.debit_note' : 'Documents.purchase_invoice'),
        'number'       => $number !== '' ? $number : (string) ($receiving['receiving_id'] ?? ''),
        'meta'         => $meta,
        'partyLabel'   => lang('Suppliers.supplier'),
        'showPayments' => ! $isReturn,
        'absolute'     => $isReturn,
    ];
}

/**
 * An amount as the document shows it: a credit note shows what it takes back as a positive amount,
 * whatever the sign the return carries in the books. Only the sign is dropped, never the figure.
 */
function documentFigure(bool $absolute, mixed $amount): string
{
    $text = trim((string) $amount);

    return $absolute ? ltrim($text, '-') : $text;
}

/**
 * The code of the first phase of electronic invoicing for a document, or why it has none.
 *
 * @param array<string, mixed> $sale
 * @param array<string, mixed> $config
 */
function documentQr(string $type, array $sale, array $config): QrResult
{
    return (new DocumentQr())->forDocument($type, $sale, [...$config, 'vat_registration' => documentVatRegistration([...$sale, ...$config])->value]);
}

/**
 * The picture of the code of a document as the source of an image and the side it is printed at in
 * millimetres, or null when the picture cannot be made and the document prints the text of the code.
 *
 * @return array{source: string, sizeMm: float}|null
 */
function documentQrImage(string $payload, float $preferredMm): ?array
{
    try {
        $image = new QrImage();

        return ['source' => $image->pngDataUri($payload), 'sizeMm' => $image->sizeMm($payload, $preferredMm)];
    } catch (Throwable $exception) {
        log_message('error', 'The picture of the code of a document cannot be made: ' . $exception->getMessage());

        return null;
    }
}

/**
 * The terms and conditions of the family of the document, or an empty text.
 *
 * @param array<string, mixed> $config
 */
function documentTerms(string $type, array $config): string
{
    return DocumentTerms::forType($type, $config);
}

/**
 * The second-language names a document prints beside the first: those of its customer or supplier and of its
 * items, and nothing at all while the setting to print them is off.
 *
 * @param 'customer'|'supplier' $partyKind
 * @param list<int>             $itemIds
 *
 * @return array{party: array{name: string, note: string}|null, items: array<int, string>}
 */
function documentTranslations(string $partyKind, mixed $partyId, array $itemIds): array
{
    return (new EntityTranslations())->forDocument(TranslationKind::from($partyKind), is_numeric($partyId) && (int) $partyId > 0 ? (int) $partyId : null, $itemIds);
}

/**
 * The lines under the name of the shop on a document: its address and phone, its account number and its
 * tax number, as the sales documents show them.
 *
 * @param array<string, mixed> $config
 */
function documentCompanyInfo(array $config): string
{
    $lines = [(string) ($config['address'] ?? ''), (string) ($config['phone'] ?? '')];

    if (! empty($config['account_number'])) {
        $lines[] = lang('Sales.account_number') . ': ' . $config['account_number'];
    }

    if ((string) ($config['tax_id'] ?? '') !== '') {
        $lines[] = lang('Sales.tax_id') . ': ' . $config['tax_id'];
    }

    return implode("\n", array_filter(array_map('trim', $lines), static fn (string $line): bool => $line !== ''));
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
 * The name of the discount on the whole of a sale as the documents print it, with its percentage
 * when it was entered as one.
 *
 * @param array{type: int, value: string}|null $entry
 */
function invoiceDiscountLabel(?array $entry): string
{
    $label = lang('Sales.invoice_discount');

    return $entry !== null && (int) $entry['type'] === PERCENT ? $label . ' (' . to_decimals($entry['value']) . '%)' : $label;
}

/**
 * The rows the charges of the header add to a document, as a label and an amount: the shipping, the tax
 * on it when that tax is on top of the amount, and the adjustment under the label it was typed with.
 *
 * @param array<string, mixed> $charges
 *
 * @return list<array{string, string}>
 */
function headerChargeRows(array $charges): array
{
    $rows = [];

    if (bccomp((string) ($charges['shipping'] ?? '0'), '0', 4) !== 0) {
        $rows[] = [lang('Sales.shipping'), (string) $charges['shipping']];

        if (empty($charges['shipping_tax_included']) && bccomp((string) ($charges['shipping_tax'] ?? '0'), '0', 4) !== 0) {
            $rows[] = [lang('Sales.shipping_tax'), (string) $charges['shipping_tax']];
        }
    }

    if (bccomp((string) ($charges['adjustment'] ?? '0'), '0', 4) !== 0) {
        $label  = trim((string) ($charges['adjustment_label'] ?? ''));
        $rows[] = [$label === '' ? lang('Sales.adjustment') : $label, (string) $charges['adjustment']];
    }

    return $rows;
}

/**
 * A label of the printout of a document issued under the Saudi rules: always in Arabic, and in the language of the
 * reader after it when that is another.
 *
 * @param list<string> $arguments
 */
function einvoiceWords(string $key, array $arguments = []): string
{
    $arabic = lang($key, $arguments, 'ar-EG');
    $reader = lang($key, $arguments);

    return $reader === $arabic || $reader === '' ? $arabic : $arabic . ' / ' . $reader;
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
