<?php

/**
 * Dev only: builds the item sales report both ways, as it was (every document held at once) and as it is
 * (the summary folded while the documents are read), on a database with real volume, and compares the
 * tables. The frozen copy is the one the tests compare with (tests/Support/LegacyOperationalReports).
 *
 *   MYSQL_DB_NAME=ospos_test_f8_big CI_ENVIRONMENT=production php -d memory_limit=4G tools/dev/compare-item-sales-report.php [from to]
 */

require dirname(__DIR__) . '/benchmarks/r6/boot.php';

use App\Libraries\Accounting\OperationalReports\DocumentKind;
use App\Libraries\Accounting\OperationalReports\ItemGroup;
use App\Libraries\Accounting\OperationalReports\ItemSalesReport;
use App\Libraries\Accounting\ReportScope;
use Config\Database;
use Tests\Support\LegacyOperationalReports\LegacyItemSalesReport;

require_once dirname(__DIR__, 2) . '/vendor/autoload.php';

Database::connect()->saveQueries = false;

$last   = Database::connect()->query('SELECT MAX(journal_date) AS last FROM ' . Database::connect()->prefixTable('accounting_journals'))->getRow()->last ?? date('Y-m-d');
$from   = $argv[1] ?? date('Y-m-d', strtotime($last . ' -60 days'));
$to     = $argv[2] ?? $last;
$scope  = ReportScope::resolve(null, null);
$old    = new LegacyItemSalesReport();
$new    = new ItemSalesReport();
$failed = 0;

foreach ([ItemGroup::Item, ItemGroup::Category, ItemGroup::Customer, ItemGroup::User, ItemGroup::Day, ItemGroup::Month] as $group) {
    foreach ([null, DocumentKind::Invoice] as $kind) {
        $started = microtime(true);
        $before  = $old->build($scope, $group, false, $from, $to, $to, $kind);
        $oldTime = microtime(true) - $started;
        $started = microtime(true);
        $after   = $new->build($scope, $group, false, $from, $to, $to, $kind);
        $newTime = microtime(true) - $started;
        $same    = serialize($before) === serialize($after);
        $failed += $same ? 0 : 1;

        printf("%-9s %-9s %s  %d groups  old %.1fs  new %.1fs\n", $group->value, $kind?->value ?? 'all', $same ? 'IDENTICAL' : 'DIFFERENT', count($after->blocks[0]->rows), $oldTime, $newTime);
    }
}

exit($failed === 0 ? 0 : 1);
