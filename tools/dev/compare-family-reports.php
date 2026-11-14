<?php

/**
 * Dev only: proves on a database with real volume that the family reports that read their rows in chunks
 * give the table they gave when they read every row at once. Each report is built both ways for the same
 * periods and filters and the CSV rows of the two are compared byte for byte.
 *
 *   MYSQL_DB_NAME=ospos_test_f8_big CI_ENVIRONMENT=production php -d memory_limit=4G tools/dev/compare-family-reports.php [from to]
 *
 * The frozen copies of the reports are the ones the tests compare with (tests/Support/LegacyFamilyReports).
 * The database must be one the tests may use: its name starts with ospos_test_.
 */

require dirname(__DIR__) . '/benchmarks/r6/boot.php';

use App\Libraries\Accounting\FamilyReports\JournalMovementsReport;
use App\Libraries\Accounting\FamilyReports\JournalsReport;
use App\Libraries\Accounting\FamilyReports\ProductBalanceReport;
use App\Libraries\Accounting\FamilyReports\ReportCsv;
use App\Libraries\Accounting\FamilyReports\ReportInput;
use App\Libraries\Accounting\FamilyReports\StockAgingReport;
use App\Libraries\Accounting\FamilyReports\StockMovementsReport;
use App\Libraries\Accounting\FamilyReports\StockOperationsReport;
use App\Libraries\Accounting\FamilyReports\StockTurnoverReport;
use App\Libraries\Accounting\FamilyReports\StreamsReport;
use App\Libraries\Accounting\ReportScope;
use Config\Database;
use Tests\Support\LegacyFamilyReports\LegacyJournalMovementsReport;
use Tests\Support\LegacyFamilyReports\LegacyJournalsReport;
use Tests\Support\LegacyFamilyReports\LegacyProductBalanceReport;
use Tests\Support\LegacyFamilyReports\LegacyStockAgingReport;
use Tests\Support\LegacyFamilyReports\LegacyStockMovementsReport;
use Tests\Support\LegacyFamilyReports\LegacyStockOperationsReport;
use Tests\Support\LegacyFamilyReports\LegacyStockTurnoverReport;

require_once dirname(__DIR__, 2) . '/vendor/autoload.php';

Database::connect()->saveQueries = false;

$today = date('Y-m-d');
$last  = Database::connect()->query('SELECT MAX(journal_date) AS last FROM ' . Database::connect()->prefixTable('accounting_journals'))->getRow()->last ?? $today;
$from  = $argv[1] ?? date('Y-m-d', strtotime($last . ' -45 days'));
$to    = $argv[2] ?? $last;
$mid   = date('Y-m-d', strtotime($to . ' -20 days'));

$pairs = [
    'journal_movements' => [new LegacyJournalMovementsReport(), new JournalMovementsReport(), [['group' => 'journal'], ['group' => 'day'], ['group' => 'month'], ['group' => 'year'], ['group' => 'account'], ['group' => 'journal', 'source' => 'sale'], ['group' => 'day', 'without_cost_center' => '1']]],
    'journals'          => [new LegacyJournalsReport(), new JournalsReport(), [[], ['source' => 'sale'], ['search' => 'a']]],
    'stock_movements'   => [new LegacyStockMovementsReport(), new StockMovementsReport(), [['view' => 'plain'], ['view' => 'valued'], ['view' => 'valued', 'category' => '']]],
    'stock_aging'       => [new LegacyStockAgingReport(), new StockAgingReport(), [[], ['split' => '1'], ['search' => 'a']]],
    'product_balance'   => [new LegacyProductBalanceReport(), new ProductBalanceReport(), [[], ['empty' => '1']]],
    'stock_turnover'    => [new LegacyStockTurnoverReport(), new StockTurnoverReport(), [['group' => 'item'], ['group' => 'category']]],
    'stock_operations'  => [new LegacyStockOperationsReport(), new StockOperationsReport(), [[]]],
];

$periods = ['period' => ['from' => $from, 'to' => $to], 'shorter' => ['from' => $mid, 'to' => $to], 'to only' => ['to' => $mid]];
$scope   = ReportScope::resolve(null, null);
$failed  = 0;

foreach ($pairs as $name => [$old, $new, $variants]) {
    foreach ($periods as $periodName => $period) {
        foreach ($variants as $variant) {
            $input = new ReportInput($scope, null, [...$period, ...$variant], $today);

            $started = microtime(true);
            $oldRows = implode("\n", array_map(static fn (array $row): string => implode("\t", $row), iterator_to_array(ReportCsv::rows($old->build($input), null), false)));
            $oldTime = microtime(true) - $started;

            $started = microtime(true);
            $source  = $new instanceof StreamsReport ? $new->stream($input) : $new->build($input);
            $newRows = implode("\n", array_map(static fn (array $row): string => implode("\t", $row), iterator_to_array(ReportCsv::rows($source, null), false)));
            $newTime = microtime(true) - $started;

            $same = $oldRows === $newRows;
            $failed += $same ? 0 : 1;

            printf("%-18s %-8s %-60s %s  %6d bytes  old %.1fs  new %.1fs\n", $name, $periodName, json_encode($variant), $same ? 'IDENTICAL' : 'DIFFERENT', strlen($oldRows), $oldTime, $newTime);
        }
    }
}

exit($failed === 0 ? 0 : 1);
