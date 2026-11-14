<?php

/**
 * Dev-only timing of one service call per process (so that the peak memory is the call's own).
 *
 *   MYSQL_DB_NAME=ospos_test_r6_t10k CI_ENVIRONMENT=production R6_NOW="2025-01-15 20:00:00" php -d memory_limit=256M tools/benchmarks/r6/cli-bench.php NAME
 *
 * Prints one JSON line: name, ms, peak_mb (the whole process, bootstrap included), the number of statements, the rows covered,
 * the failure when there was one, the load average and the memory limit.
 */

require __DIR__ . '/boot.php';

use App\Libraries\Accounting\AgingBuckets;
use App\Libraries\Accounting\CustomerLedger;
use App\Libraries\Accounting\FamilyReports\FamilyReportCatalog;
use App\Libraries\Accounting\FamilyReports\ReportInput;
use App\Libraries\Accounting\OperationalReports\ClientsSalesReport;
use App\Libraries\Accounting\OperationalReports\ItemGroup;
use App\Libraries\Accounting\OperationalReports\ItemSalesReport;
use App\Libraries\Accounting\OperationalReports\NetPositionReport;
use App\Libraries\Accounting\OperationalReports\PaymentGroup;
use App\Libraries\Accounting\OperationalReports\PaymentsReport;
use App\Libraries\Accounting\OperationalReports\ProfitReport;
use App\Libraries\Accounting\OperationalReports\RevenueGroup;
use App\Libraries\Accounting\OperationalReports\RevenueReport;
use App\Libraries\Accounting\Readiness\ReadinessRunner;
use App\Libraries\Accounting\ReportPeriod;
use App\Libraries\Accounting\ReportScope;
use App\Libraries\Accounting\SupplierLedger;
use App\Libraries\Accounting\TrialBalance;
use App\Libraries\Accounting\YearEndClosing;
use Config\Database;

$name  = $argv[1] ?? '';
$db    = Database::connect();
$db->saveQueries = false;
$today = date('Y-m-d');
$from  = getenv('R6_FROM') ?: '2024-11-01';
$scope = ReportScope::resolve(null, null);

$questions = static fn (): int => (int) $db->query("SHOW SESSION STATUS LIKE 'Questions'")->getRow()->Value;

$work = [
    'readiness'     => static fn (): int => count((new ReadinessRunner())->run()->checks),
    'trial_balance' => static fn (): int => count((new TrialBalance())->build($scope, ReportPeriod::of($from, $today))->rows),
    'aged_ledger_customers' => static fn (): int => count((new CustomerLedger())->agedLedger($today, AgingBuckets::DEFAULT_PERIOD)),
    'aged_ledger_suppliers' => static fn (): int => count((new SupplierLedger())->agedLedger($today, AgingBuckets::DEFAULT_PERIOD)),
    'sales_revenue' => static fn (): int => (new RevenueReport())->build($scope, RevenueGroup::Customer, false, null, $today, $today, allDates: true)->documents,
    'sales_revenue_rows' => static fn (): int => (new RevenueReport())->build($scope, RevenueGroup::Customer, true, null, $today, $today, allDates: true)->documents,
    'clients_sales' => static fn (): int => (new ClientsSalesReport())->build($scope, null, true, false, null, $today)->documents,
    'item_sales'    => static fn (): int => (new ItemSalesReport())->build($scope, ItemGroup::Item, false, null, $today, $today, allDates: true)->documents,
    'item_profit'   => static fn (): int => (new ProfitReport())->build($scope, ItemGroup::Item, false, null, $today, allDates: true)->documents,
    'payments'      => static fn (): int => (new PaymentsReport())->build($scope, PaymentGroup::Customer, false, null, $today, allDates: true)->documents,
    'net_position'  => static fn (): int => (new NetPositionReport())->build($scope, false, null, $today)->documents,
    'year_end_preview' => static function () use ($db): int {
        $service = new YearEndClosing($db);
        $years   = $service->years(1);

        if ($years === []) {
            return 0;
        }

        return count($service->preview(null, (int) $years[0]->fiscal_year_id, 1)->branches);
    },
];

foreach (FamilyReportCatalog::all() as $key => $definition) {
    $work['family_' . $key] = static fn (): int => count((new ($definition['class'])())->build(new ReportInput($scope, null, ['from' => $from, 'to' => $today], $today))->blocks);
}

if (! isset($work[$name])) {
    fwrite(STDERR, "unknown measurement {$name}\n");
    exit(2);
}

$before  = $questions();
$started = hrtime(true);
$covered = null;
$failure = null;

try {
    $covered = $work[$name]();
} catch (Throwable $exception) {
    $failure = $exception::class . ': ' . substr($exception->getMessage(), 0, 200);
}

echo json_encode([
    'name'      => $name,
    'ms'        => round((hrtime(true) - $started) / 1_000_000),
    'peak_mb'   => round(memory_get_peak_usage(false) / 1048576, 1),
    'questions' => $questions() - $before - 1,
    'covered'   => $covered,
    'failed'    => $failure,
    'limit'     => ini_get('memory_limit'),
    'load'      => round(sys_getloadavg()[0], 2),
    'env'       => ENVIRONMENT,
]), "\n";
