<?php

/**
 * Dev-only: one simulated cashier that saves sales, purchases, payments and expenses through the real models for a number of
 * seconds, and writes what it saw (saves, refusals, exceptions, timings) as JSON. Several of these run side by side on one
 * ospos_test_ database to look for deadlocks, lock waits, duplicated numbers and broken ties.
 *
 *   MYSQL_DB_NAME=ospos_test_r6_t10k CI_ENVIRONMENT=development R6_NOW="2025-01-16 10:00:00" php tools/benchmarks/r6/worker.php WORKER_ID SECONDS OUT.json
 */

require __DIR__ . '/boot.php';

use App\Libraries\Accounting\ReturnDocument;
use App\Models\Expense;
use App\Models\Receiving;
use App\Models\Sale;
use Config\Database;

$workerId = (int) ($argv[1] ?? 1);
$seconds  = (int) ($argv[2] ?? 60);
$out      = $argv[3] ?? null;

mt_srand(9000 + $workerId);

$db              = Database::connect();
$db->saveQueries = false;
$sales           = model(Sale::class);
$purchases       = model(Receiving::class);
$expenses        = model(Expense::class);

$items     = $db->table('items')->select('item_id, unit_price, cost_price')->where('deleted', 0)->get()->getResult();
$customers = array_map('intval', array_column($db->table('customers')->select('person_id')->get()->getResultArray(), 'person_id'));
$suppliers = array_map('intval', array_column($db->table('suppliers')->select('person_id')->get()->getResultArray(), 'person_id'));
$places    = $db->query('SELECT e.person_id AS employee, l.location_id AS location, l.branch_id AS branch FROM ' . $db->prefixTable('employees') . ' e JOIN ' . $db->prefixTable('employee_branches') . ' b ON b.person_id = e.person_id JOIN ' . $db->prefixTable('stock_locations') . ' l ON l.branch_id = b.branch_id')->getResultArray();
$places[]  = ['employee' => 1, 'location' => 1, 'branch' => 1];
$categories = array_map('intval', array_column($db->table('expense_categories')->select('expense_category_id')->get()->getResultArray(), 'expense_category_id'));
$openInvoices = [];

$stat = ['worker' => $workerId, 'saved' => 0, 'refused' => [], 'exceptions' => [], 'ms' => [], 'payments' => 0, 'purchases' => 0, 'expenses' => 0, 'ids' => []];

$note = static function (array &$bucket, string $key): void {
    $bucket[$key] = ($bucket[$key] ?? 0) + 1;
};

$status = static function (string $like) use ($db): int {
    return (int) $db->query("SHOW GLOBAL STATUS LIKE '{$like}'")->getRow()->Value;
};

$killAfter = (int) (getenv('R6_KILL_AFTER_QUERIES') ?: 0);

if ($killAfter > 0) {
    $counted = 0;

    \CodeIgniter\Events\Events::on('DBQuery', static function () use (&$counted, $killAfter): void {
        if (++$counted >= $killAfter) {
            posix_kill(posix_getpid(), SIGKILL);
        }
    });
}

$deadlocksBefore = $status('Innodb_deadlocks');
$waitsBefore     = $status('Innodb_row_lock_waits');
$deadline        = microtime(true) + $seconds;
$started         = microtime(true);

while (microtime(true) < $deadline) {
    $place = $places[mt_rand(0, count($places) - 1)];
    $roll  = mt_rand(1, 100);

    try {
        if ($roll <= 82) {
            $credit   = $roll > 62;
            $customer = $credit || mt_rand(1, 100) <= 40 ? $customers[(int) (count($customers) * (mt_rand() / mt_getrandmax()) ** 2.2)] : -1;
            $lines    = [];
            $taxes    = [];
            $total    = '0';

            for ($line = 0; $line < mt_rand(1, 6); $line++) {
                $item     = $items[mt_rand(0, count($items) - 1)];
                $quantity = (string) mt_rand(1, 3);
                $net      = bcmul($quantity, (string) $item->unit_price, 2);
                $tax      = bcmul($net, '0.15', 2);
                $total    = bcadd($total, bcadd($net, $tax, 2), 2);
                $lines[]  = ['item_id' => $item->item_id, 'line' => $line, 'description' => 'w', 'serialnumber' => '', 'quantity' => $quantity, 'discount' => '0', 'discount_type' => PERCENT, 'cost_price' => (string) $item->cost_price, 'price' => (string) $item->unit_price, 'item_location' => $place['location'], 'print_option' => 0];
                $taxes[]  = ['item_id' => $item->item_id, 'line' => $line, 'name' => 'VAT', 'percent' => '15.0000', 'tax_type' => 1, 'rounding_code' => 0, 'cascade_sequence' => 0, 'item_tax_amount' => $tax, 'sales_tax_code_id' => null, 'tax_category_id' => null, 'jurisdiction_id' => null];
            }

            $payments = $credit ? [] : [['payment_type' => lang('Sales.cash'), 'payment_amount' => $total, 'cash_refund' => '0', 'cash_adjustment' => 0, 'reference_code' => null]];
            $saleStatus = COMPLETED;
            $salesTaxes = [[], $taxes];
            $began      = hrtime(true);
            $id         = $sales->save_value(NEW_ENTRY, $saleStatus, $lines, $customer, $place['employee'], 'w' . $workerId, null, null, null, $credit ? SALE_TYPE_INVOICE : SALE_TYPE_POS, $payments, null, $salesTaxes, true, null, null, ReturnDocument::CreditNote, null, $credit ? 30 : null);
            $stat['ms'][] = round((hrtime(true) - $began) / 1_000_000, 1);

            if ($id > 0) {
                $stat['saved']++;
                $stat['ids'][] = $id;

                if ($credit && $customer > 0 && count($openInvoices) < 50) {
                    $openInvoices[] = [$id, $total, $place['employee']];
                }
            } else {
                $failure = $sales->accountingFailure();
                $note($stat['refused'], $failure === null ? 'code ' . $id : $failure::class . ':' . substr($failure->getMessage(), 0, 80));
            }
        } elseif ($roll <= 90) {
            $supplier = $suppliers[mt_rand(0, count($suppliers) - 1)];
            $lines    = [];

            for ($line = 0; $line < mt_rand(2, 6); $line++) {
                $item    = $items[mt_rand(0, count($items) - 1)];
                $lines[] = ['item_id' => $item->item_id, 'line' => $line, 'description' => 'w', 'serialnumber' => '', 'quantity' => (string) mt_rand(10, 50), 'receiving_quantity' => '1', 'discount' => '0', 'discount_type' => PERCENT, 'price' => (string) $item->cost_price, 'item_location' => $place['location'], 'tax_percent' => '0'];
            }

            $began        = hrtime(true);
            $id           = $purchases->save_value($lines, $supplier, $place['employee'], 'w' . $workerId, 'W' . mt_rand(), lang('Sales.due'), NEW_ENTRY, null, null, null, 30);
            $stat['ms'][] = round((hrtime(true) - $began) / 1_000_000, 1);

            if ($id > 0) {
                $stat['purchases']++;
            } else {
                $failure = $purchases->accountingFailure();
                $note($stat['refused'], 'purchase ' . ($failure === null ? 'code ' . $id : $failure::class . ':' . substr($failure->getMessage(), 0, 80)));
            }
        } elseif ($roll <= 96 && $openInvoices !== []) {
            [$id, $total, $employee] = array_pop($openInvoices);

            $paid = $sales->update($id, ['comment' => 'w paid', 'payments' => [['payment_id' => NEW_ENTRY, 'payment_type' => lang('Sales.cash'), 'payment_amount' => $total, 'cash_refund' => 0, 'cash_adjustment' => 0, 'employee_id' => $employee, 'reference_code' => null]]]);

            if ($paid) {
                $stat['payments']++;
            } else {
                $failure = $sales->accountingFailure();
                $note($stat['refused'], 'payment ' . ($failure === null ? 'unexplained' : $failure::class . ':' . substr($failure->getMessage(), 0, 80)));
            }
        } else {
            $data = ['date' => date('Y-m-d H:i:s'), 'supplier_id' => null, 'supplier_tax_code' => '', 'amount' => (string) mt_rand(20, 900), 'tax_amount' => '0', 'payment_type' => 'Cash', 'expense_category_id' => $categories[0], 'description' => 'w' . $workerId, 'employee_id' => 1, 'deleted' => 0];

            $expenses->save_value($data, NEW_ENTRY, 1) ? $stat['expenses']++ : $note($stat['refused'], 'expense');
        }
    } catch (Throwable $exception) {
        $note($stat['exceptions'], $exception::class . ': ' . substr(preg_replace('/\s+/', ' ', $exception->getMessage()), 0, 140));

        while ($db->transDepth > 0) {
            $db->transRollback();
            $db->transDepth--;
        }
    }

    (static function (): void {
        static::$performanceLog = [];
    })->bindTo(null, \CodeIgniter\Events\Events::class)();
}

sort($stat['ms']);
$count = count($stat['ms']);

$summary = [
    'worker'          => $workerId,
    'seconds'         => round(microtime(true) - $started, 1),
    'sales_saved'     => $stat['saved'],
    'purchases'       => $stat['purchases'],
    'payments'        => $stat['payments'],
    'expenses'        => $stat['expenses'],
    'refused'         => $stat['refused'],
    'exceptions'      => $stat['exceptions'],
    'ms_p50'          => $count ? $stat['ms'][intdiv($count, 2)] : null,
    'ms_p95'          => $count ? $stat['ms'][(int) floor($count * 0.95)] : null,
    'ms_max'          => $count ? $stat['ms'][$count - 1] : null,
    'deadlocks_seen'  => $status('Innodb_deadlocks') - $deadlocksBefore,
    'row_lock_waits'  => $status('Innodb_row_lock_waits') - $waitsBefore,
    'peak_mb'         => round(memory_get_peak_usage(false) / 1048576, 1),
];

$json = json_encode($summary, JSON_PRETTY_PRINT);

$out === null ? print($json . "\n") : file_put_contents($out, $json . "\n");
