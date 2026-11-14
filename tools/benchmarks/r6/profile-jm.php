<?php

/**
 * Dev-only: splits the time and memory of the journal movements report into its parts on the database it is given.
 */

require __DIR__ . '/boot.php';

use App\Libraries\Accounting\FamilyReports\JournalLines;
use App\Libraries\Accounting\FamilyReports\JournalMovementsReport;
use App\Libraries\Accounting\FamilyReports\ReportInput;
use App\Libraries\Accounting\FamilyReports\ReportRow;
use App\Libraries\Accounting\ReportPeriod;
use App\Libraries\Accounting\ReportScope;
use Config\Database;

$db              = Database::connect();
$db->saveQueries = false;
$scope           = ReportScope::resolve(null, null);
$today           = date('Y-m-d');
$from            = getenv('R6_FROM') ?: '2024-11-01';

$mark = static function (string $label, float $since): void {
    fwrite(STDOUT, sprintf("%-34s %8.0f ms   mem %6.1f MB   peak %6.1f MB\n", $label, (microtime(true) - $since) * 1000, memory_get_usage() / 1048576, memory_get_peak_usage() / 1048576));
};

$t     = microtime(true);
$lines = (new JournalLines())->read($scope, ReportPeriod::of($from, $today), null, null, null, false, null);
$mark('JournalLines::read (' . count($lines) . ' lines)', $t);

$t    = microtime(true);
$rows = [];

foreach ($lines as $line) {
    $rows[] = new ReportRow(['date' => (string) $line->journal_date, 'number' => (string) $line->number, 'account' => (string) $line->account_name, 'code' => (string) $line->account_code, 'description' => (string) $line->journal_description, 'source' => (string) $line->source_type, 'centers' => '', 'debit' => bcadd((string) $line->debit, '0', 4), 'credit' => bcadd((string) $line->credit, '0', 4)]);
}

$mark('ReportRow objects', $t);

$t      = microtime(true);
$all    = [];
$blocks = 0;
$byJournal = [];

foreach ($lines as $index => $line) {
    $byJournal['j' . $line->journal_id][] = $rows[$index];
}

foreach ($byJournal as $set) {
    $all = [...$all, ...$set];
    $blocks++;
}

$mark("spread loop ({$blocks} blocks)", $t);

$t   = microtime(true);
$all = [];

foreach ($byJournal as $set) {
    foreach ($set as $row) {
        $all[] = $row;
    }
}

$mark('append loop (the linear form)', $t);

$t     = microtime(true);
$table = (new JournalMovementsReport())->build(new ReportInput($scope, null, ['from' => $from, 'to' => $today], $today));
$mark('whole build (' . count($table->blocks) . ' blocks)', $t);
