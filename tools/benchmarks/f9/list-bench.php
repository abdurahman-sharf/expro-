<?php

/**
 * Dev-only: times the three reads of the list of sales (the page, the count and the payments summary) over a range of days, several
 * times in one process, and prints the median. Run it on the same database with the code before and after a change.
 *
 *   MYSQL_DB_NAME=ospos_test_f9_big CI_ENVIRONMENT=development php tools/benchmarks/f9/list-bench.php FROM TO [RUNS]
 */

require dirname(__DIR__) . '/r6/boot.php';

use App\Models\Sale;
use Config\Database;

$from = $argv[1] ?? '2025-08-01';
$to   = $argv[2] ?? '2025-08-31';
$runs = (int) ($argv[3] ?? 7);
$db   = Database::connect();

$db->saveQueries = false;

$filters = ['sale_type' => 'all', 'location_id' => 'all', 'start_date' => $from, 'end_date' => $to, 'only_cash' => false, 'only_due' => false, 'only_check' => false, 'selected_customer' => false, 'only_creditcard' => false, 'only_debit' => false, 'only_bank_transfer' => false, 'only_wallet' => false, 'only_invoices' => false, 'is_valid_receipt' => false];
$sale    = model(Sale::class);
$times   = [];
$rows    = 0;
$found   = 0;

foreach (['sales_search_payments_temp', 'sales_items_taxes_temp'] as $table) {
    $db->query('DROP TEMPORARY TABLE IF EXISTS ' . $db->prefixTable($table));
}

for ($run = 0; $run < $runs; $run++) {
    foreach (['sales_search_payments_temp', 'sales_items_taxes_temp'] as $table) {
        $db->query('DROP TEMPORARY TABLE IF EXISTS ' . $db->prefixTable($table));
    }

    $start = hrtime(true);
    $rows  = count($sale->search('', $filters, 25, 0, 'sale_id', 'desc')->getResultArray());

    foreach (['sales_search_payments_temp', 'sales_items_taxes_temp'] as $table) {
        $db->query('DROP TEMPORARY TABLE IF EXISTS ' . $db->prefixTable($table));
    }

    $found = $sale->get_found_rows('', $filters);
    $sale->getPaymentsSummary('', $filters);
    $times[] = (hrtime(true) - $start) / 1e6;
}

sort($times);

echo json_encode(['from' => $from, 'to' => $to, 'runs' => $runs, 'page_rows' => $rows, 'sales_in_range' => (int) $found, 'median_ms' => round($times[intdiv(count($times), 2)], 1), 'min_ms' => round($times[0], 1), 'max_ms' => round($times[array_key_last($times)], 1)]) . "\n";
