<?php

/**
 * Renders the cases of the check that the printed code of a simplified tax invoice can be read by a scanner:
 * the picture as a PNG file, the A4 tax invoice and credit note through the real template and the real PDF
 * engine (with the page of the screen as a file of HTML, to be looked at in a browser), and the block of the
 * code alone on pages as wide as a thermal roll of 80 and of 58 millimetres. It writes the manifest that
 * tools/dev/qr_decode_check.py reads. The settings of the shop are given to the template as the document
 * layer gets them; the only read of the database is one setting of the template (the second language of the
 * documents), so any database that a run of PHPUnit has seeded will do, and nothing is written to it. A series
 * of pictures of texts of every size that a symbol of level M holds, up to the largest text a document can
 * carry, is written beside them to check the encoder itself.
 *
 * Run from the root of the repository: MYSQL_DB_NAME=<seeded database> php tools/dev/qr_render_cases.php <output directory>
 */

use App\Libraries\Documents\DocumentQr;
use App\Libraries\Documents\QrImage;
use App\Libraries\Pdf_lib;
use CodeIgniter\Config\Factories;
use Config\OSPOS;

chdir(dirname(__DIR__, 2));
require 'vendor/codeigniter4/framework/system/Test/bootstrap.php';

$directory = $argv[1] ?? null;

if ($directory === null) {
    fwrite(STDERR, "Usage: php tools/dev/qr_render_cases.php <output directory>\n");
    exit(2);
}

if (! is_dir($directory) && ! mkdir($directory, 0775, true)) {
    fwrite(STDERR, "The directory cannot be made.\n");
    exit(2);
}

date_default_timezone_set('Asia/Riyadh');

const VAT_NUMBER = '300000000000003';
const SALE_TIME  = '2026-10-22 12:47:29';
const UTC_TIME   = '2026-10-22T09:47:29Z';

$cases = [
    'plain'    => ['seller' => 'ACME Trading', 'total' => '115.00', 'tax' => '15.00', 'type' => 'tax_invoice'],
    'arabic'   => ['seller' => 'شركة الأول برو للتجارة والمقاولات', 'total' => '1725.00', 'tax' => '225.00', 'type' => 'tax_invoice'],
    'arabic50' => ['seller' => 'مؤسسة النخبة الذهبية للتجارة العامة والمقاولات ش.م', 'total' => '57.50', 'tax' => '7.50', 'type' => 'tax_invoice'],
    'latin50'  => ['seller' => 'International Trading And Contracting Company KSA.', 'total' => '23.00', 'tax' => '3.00', 'type' => 'tax_invoice'],
    'large'    => ['seller' => 'شركة الأول برو', 'total' => '987654321.50', 'tax' => '128824.37', 'type' => 'tax_invoice'],
    'credit'   => ['seller' => 'شركة الأول برو للتجارة', 'total' => '230.00', 'tax' => '30.00', 'type' => 'credit_note'],
    'refund'   => ['seller' => 'شركة الأول برو للتجارة', 'total' => '57.50', 'tax' => '7.50', 'type' => 'credit_note', 'refund' => true],
];

foreach (['Arabic50' => $cases['arabic50']['seller'], 'Latin50' => $cases['latin50']['seller']] as $label => $seller) {
    if (mb_strlen($seller) !== 50) {
        fwrite(STDERR, $label . ' seller has ' . mb_strlen($seller) . " characters, not 50.\n");
        exit(2);
    }
}

$manifest = [];
$pdfs     = new Pdf_lib();
$images   = new QrImage();
$css      = (string) file_get_contents(FCPATH . 'assets/css/document.css');

foreach ($cases as $name => $case) {
    $config           = new OSPOS();
    $config->settings = [
        'number_locale'             => 'en_US',
        'currency_symbol'           => 'SAR',
        'currency_decimals'         => 2,
        'quantity_decimals'         => 0,
        'tax_decimals'              => 2,
        'thousands_separator'       => '1',
        'company'                   => $case['seller'],
        'tax_id'                    => VAT_NUMBER,
        'receipt_show_company_name' => '1',
        'language'                  => 'ar-EG',
        'language_code'             => 'ar-EG',
    ];
    Factories::injectMock('config', OSPOS::class, $config);
    helper(['locale', 'document', 'url']);

    $sign = $case['type'] === 'credit_note' ? '-' : '';
    $sale = [
        'config'              => $config->settings,
        'company_info'        => "Riyadh\nTax number: " . VAT_NUMBER,
        'customer_info'       => 'Customer',
        'invoice_number'      => 'INV-0001',
        'creditNoteNumber'    => isset($case['refund']) ? '' : 'CN-0001',
        'refundInvoiceNumber' => isset($case['refund']) ? 'RI-0001' : '',
        'transaction_date'    => '22/10/2026',
        'sale_time_raw'       => SALE_TIME,
        'invoice_date_raw'    => null,
        'sale_status'         => COMPLETED,
        'sale_type'           => SALE_TYPE_INVOICE,
        'cart'                => [[
            'name' => 'Item', 'quantity' => $sign . '1', 'price' => $sign . $case['total'], 'discount' => '0', 'discount_type' => 0,
            'discounted_total' => $sign . $case['total'], 'is_serialized' => 0, 'allow_alt_description' => 0, 'item_id' => 1, 'print_option' => PRINT_YES,
        ]],
        'subtotal'            => $sign . bcsub($case['total'], $case['tax'], 2),
        'taxes'               => [['tax_rate' => 15, 'tax_group' => 'VAT', 'sale_tax_amount' => $sign . $case['tax'], 'tax_type' => 1]],
        'header_charges'      => [],
        'total'               => $sign . $case['total'],
        'payments'            => [],
        'amount_change'       => 0,
    ];

    $expected = ['seller' => $case['seller'], 'vat' => VAT_NUMBER, 'time' => UTC_TIME, 'total' => $case['total'], 'tax' => $case['tax']];
    $payload  = (string) (new DocumentQr())->forDocument($case['type'], $sale, $config->settings)->payload;

    file_put_contents($directory . '/' . $name . '.png', $images->png($payload));
    file_put_contents($directory . '/' . $name . '-a4.pdf', $pdfs->render(view('documents/pdf', ['type' => $case['type'], 'sale' => $sale]), ['title' => $name]));

    $screen = view('documents/document', ['type' => $case['type'], 'context' => 'screen', 'sale' => $sale]);
    file_put_contents($directory . '/' . $name . '-screen.html', '<html><head><meta charset="utf-8"><style>' . $css . '</style></head><body>' . $screen . '</body></html>');

    $files = ['png' => $name . '.png', 'a4' => $name . '-a4.pdf'];

    foreach (['thermal80' => [80, QrImage::SIZE_THERMAL_80_MM], 'thermal58' => [58, QrImage::SIZE_THERMAL_58_MM]] as $roll => [$width, $preferredMm]) {
        $block = view('documents/qr', ['qr' => (new DocumentQr())->forDocument($case['type'], $sale, $config->settings), 'context' => 'pdf', 'preferredMm' => $preferredMm]);
        $html  = '<html><head><style>' . $css . '</style></head><body><div class="doc">' . $block . '</div></body></html>';
        $pdf   = $pdfs->render($html, ['title' => $name, 'format' => [$width, 120], 'margins' => ['top' => 4, 'right' => 3, 'bottom' => 4, 'left' => 3]]);

        file_put_contents($directory . '/' . $name . '-' . $roll . '.pdf', $pdf);
        $files[$roll] = $name . '-' . $roll . '.pdf';
    }

    $manifest[] = ['name' => $name, 'payload' => $payload, 'expected' => $expected, 'files' => $files, 'modules' => $images->modulesAcross($payload), 'a4Mm' => $images->sizeMm($payload, QrImage::SIZE_A4_MM)];
}

mt_srand(7);

foreach ([14, 15, 26, 27, 42, 43, 62, 63, 84, 85, 106, 107, 122, 123, 152, 153, 180, 181, 213, 214, 251, 252, 287, 288, 331, 332, 362, 363, 412, 413, 450, 451, 504, 505, 560] as $length) {
    $text = '';

    while (strlen($text) < $length) {
        $text .= base64_encode(pack('N', mt_rand()));
    }

    $text = substr($text, 0, $length);
    $name = sprintf('sweep-%03d', $length);

    file_put_contents($directory . '/' . $name . '.png', $images->png($text));
    $manifest[] = ['name' => $name, 'payload' => $text, 'expected' => null, 'files' => ['png' => $name . '.png'], 'modules' => $images->modulesAcross($text), 'a4Mm' => $images->sizeMm($text, QrImage::SIZE_A4_MM)];
}

file_put_contents($directory . '/manifest.json', json_encode($manifest, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
fwrite(STDOUT, count($manifest) . ' cases written to ' . $directory . "\n");
