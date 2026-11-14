<?php

/**
 * Dev-only: runs one piece of work with the statements recorded, prints the statements that took the longest with their EXPLAIN, and how
 * many statements share one shape (the N+1 candidates).
 *
 *   MYSQL_DB_NAME=ospos_test_r6_t10k CI_ENVIRONMENT=production php tools/benchmarks/r6/slowest.php NAME
 */

require __DIR__ . '/boot.php';

use App\Libraries\Accounting\CustomerLedger;
use App\Libraries\Accounting\OperationalReports\NetPositionReport;
use App\Libraries\Accounting\ReportScope;
use App\Models\Sale;
use Config\Database;

$name  = $argv[1] ?? '';
$db    = Database::connect();
$today = date('Y-m-d');
$scope = ReportScope::resolve(null, null);

$work = [
    'receivables_search' => static fn () => (new CustomerLedger())->customersWithBalance('', 'balance', 'desc', 25, 0),
    'net_position'       => static fn () => (new NetPositionReport())->build($scope, false, null, $today),
    'sales_search'       => static function () use ($today): void {
        $sale    = model(Sale::class);
        $filters = ['sale_type' => 'all', 'location_id' => 'all', 'start_date' => '2024-01-01', 'end_date' => '2099-12-31', 'only_cash' => false, 'only_due' => false, 'only_check' => false, 'selected_customer' => false, 'only_creditcard' => false, 'only_debit' => false, 'only_bank_transfer' => false, 'only_wallet' => false, 'only_invoices' => false, 'is_valid_receipt' => false];
        $sale->search('', $filters, 25, 0, 'sale_id', 'desc');
        $sale->get_found_rows('', $filters);
        $sale->getPaymentsSummary('', $filters);
    },
];

if (! isset($work[$name])) {
    fwrite(STDERR, "unknown {$name}\n");
    exit(2);
}

$recorded = [];

CodeIgniter\Events\Events::on('DBQuery', static function (CodeIgniter\Database\Query $query) use (&$recorded): void {
    $recorded[] = [$query->getQuery(), (float) $query->getDuration(8)];
});

$work[$name]();
$queries = [];
$timed   = [];
$shapes  = [];

foreach ($recorded as $index => [$sqlText, $duration]) {
    $queries[$index] = $sqlText;
    $sql             = $queries[$index];
    $shape = preg_replace(['/\d+/', "/'[^']*'/", '/\s+/'], ['?', '?', ' '], (string) $sql);
    $shape = substr((string) $shape, 0, 140);

    $shapes[$shape] = ($shapes[$shape] ?? 0) + 1;
    $timed[$index]  = $duration;
}

arsort($timed);
printf("%d statements\n\nrepeated shapes:\n", count($queries));
arsort($shapes);

foreach (array_slice($shapes, 0, 4, true) as $shape => $count) {
    printf("%6d x %s\n", $count, $shape);
}

echo "\nslowest:\n";

foreach (array_slice($timed, 0, 3, true) as $index => $seconds) {
    $sql = (string) $queries[$index];

    printf("\n%.0f ms  %s\n", $seconds * 1000, substr(preg_replace('/\s+/', ' ', $sql), 0, 600));

    if (stripos(ltrim($sql), 'select') === 0) {
        foreach ($db->query('EXPLAIN ' . $sql)->getResultArray() as $row) {
            echo '   ' . implode(' | ', array_map(static fn ($value): string => (string) $value, array_intersect_key($row, array_flip(['table', 'type', 'possible_keys', 'key', 'rows', 'Extra'])))) . "\n";
        }
    }
}
