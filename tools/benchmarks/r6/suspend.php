<?php

/**
 * Dev-only: leaves N suspended sales (saved with the status of a suspended sale, as the register's suspend button does) on the database.
 *
 *   MYSQL_DB_NAME=ospos_test_r6_t10k CI_ENVIRONMENT=development R6_NOW="2025-01-15 12:00:00" php tools/benchmarks/r6/suspend.php 150
 */

require __DIR__ . '/boot.php';

use App\Libraries\Accounting\ReturnDocument;
use App\Models\Sale;
use Config\Database;

$count = (int) ($argv[1] ?? 100);
$db    = Database::connect();
$sales = model(Sale::class);
$items = $db->table('items')->select('item_id, unit_price, cost_price')->where('deleted', 0)->limit(400)->get()->getResult();
$custs = array_map('intval', array_column($db->table('customers')->select('person_id')->limit(300)->get()->getResultArray(), 'person_id'));
$made  = 0;

mt_srand(77);

for ($index = 0; $index < $count; $index++) {
    $lines = [];

    for ($line = 0; $line < mt_rand(1, 5); $line++) {
        $item    = $items[mt_rand(0, count($items) - 1)];
        $lines[] = ['item_id' => $item->item_id, 'line' => $line, 'description' => 's', 'serialnumber' => '', 'quantity' => (string) mt_rand(1, 3), 'discount' => '0', 'discount_type' => PERCENT, 'cost_price' => (string) $item->cost_price, 'price' => (string) $item->unit_price, 'item_location' => 1, 'print_option' => 0];
    }

    $status = SUSPENDED;
    $taxes  = [[], []];
    $id     = $sales->save_value(NEW_ENTRY, $status, $lines, $custs[mt_rand(0, count($custs) - 1)], 1, 'suspended', null, null, null, SALE_TYPE_INVOICE, [], null, $taxes, true, null, null, ReturnDocument::CreditNote);

    $made += $id > 0 ? 1 : 0;
}

echo "suspended: {$made} of {$count}\n";
