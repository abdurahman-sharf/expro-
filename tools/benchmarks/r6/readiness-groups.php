<?php

/**
 * Dev-only: time and statements of each group of the readiness checks.
 */

require __DIR__ . '/boot.php';

use App\Libraries\Accounting\Readiness\BackfillChecks;
use App\Libraries\Accounting\Readiness\ClockChecks;
use App\Libraries\Accounting\Readiness\HistoricalDataChecks;
use App\Libraries\Accounting\Readiness\LedgerChecks;
use App\Libraries\Accounting\Readiness\MigrationChecks;
use App\Libraries\Accounting\Readiness\PurchaseCycleChecks;
use App\Libraries\Accounting\Readiness\RecurringChecks;
use App\Libraries\Accounting\Readiness\RoutingChecks;
use App\Libraries\Accounting\Readiness\SettingsChecks;
use App\Libraries\Accounting\Readiness\StockTrackingChecks;
use App\Libraries\Accounting\Readiness\TieChecks;
use App\Libraries\Accounting\Readiness\Wave3Checks;
use Config\Database;

$db              = Database::connect();
$db->saveQueries = false;
$migrations      = new MigrationChecks($db);
$groups          = [
    'migrations' => $migrations, 'historical_data' => new HistoricalDataChecks($db, $migrations), 'ledger' => new LedgerChecks($db), 'ties' => new TieChecks($db), 'settings' => new SettingsChecks($db),
    'backfill' => new BackfillChecks($db), 'routing' => new RoutingChecks($db), 'recurring' => new RecurringChecks($db), 'purchase_cycle' => new PurchaseCycleChecks($db),
    'stock_tracking' => new StockTrackingChecks($db), 'wave3' => new Wave3Checks($db, $migrations), 'clock' => new ClockChecks($db),
];

$questions = static fn (): int => (int) $db->query("SHOW SESSION STATUS LIKE 'Questions'")->getRow()->Value;

foreach ($groups as $name => $group) {
    $before  = $questions();
    $started = hrtime(true);
    $checks  = $group->run();

    printf("%-16s %7.0f ms %6d statements %3d checks\n", $name, (hrtime(true) - $started) / 1e6, $questions() - $before - 1, count($checks));
}
