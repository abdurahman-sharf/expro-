<?php

/**
 * Standalone CLI worker used by ConcurrentDbRaceTrait::raceTwoProcesses().
 * Bootstraps CodeIgniter in its own process so the model method call below
 * runs on a genuinely separate DB connection from any sibling worker,
 * exercising real concurrent access instead of simulating it sequentially.
 *
 * Usage: php RaceWorker.php <method> <arg> [<arg> ...]
 */

chdir(dirname(__DIR__, 2));

require __DIR__ . '/../../vendor/codeigniter4/framework/system/Test/bootstrap.php';

$method = $argv[1];
$args   = array_slice($argv, 2);

$readyFile = getenv('RACE_READY_FILE');
$goFile    = getenv('RACE_GO_FILE');

file_put_contents($readyFile, '1');

while (!file_exists($goFile)) {
    usleep(1000);
}

$result = match ($method) {
    'giftcard' => model(App\Models\Giftcard::class)
        ->decrementGiftcardValue((string) $args[0], (float) $args[1]),
    'rewardPoints' => model(App\Models\Customer::class)
        ->adjustRewardPoints((int) $args[0], (float) $args[1]),
    'itemQuantity' => model(App\Models\Item_quantity::class)
        ->changeQuantity((int) $args[0], (int) $args[1], (float) $args[2]),
    'confirmDelivery' => model(App\Models\Sale::class)
        ->confirm_delivery((int) $args[0], (int) $args[1]) > 0,
    'cancelSale' => model(App\Models\Sale::class)
        ->delete((int) $args[0], false, true, (int) $args[1]),
    'serviceInvoice' => (static function (array $args): bool {
        $status = COMPLETED;
        $items  = [[
            'item_id'       => (int) $args[1],
            'line'          => 0,
            'description'   => 'Race',
            'serialnumber'  => '',
            'quantity'      => '1',
            'discount'      => '0',
            'discount_type' => 0,
            'cost_price'    => '0',
            'price'         => (string) $args[2],
            'item_location' => 1,
            'print_option'  => 0,
        ]];
        $taxes = [[], []];

        return model(App\Models\Sale::class)->save_value(NEW_ENTRY, $status, $items, (int) $args[0], (int) $args[3], 'race', null, null, null, SALE_TYPE_INVOICE, [], null, $taxes) > 0;
    })($args),
    'returnSale' => (static function (array $args): bool {
        $status = COMPLETED;
        $items  = [[
            'item_id'       => (int) $args[1],
            'line'          => 0,
            'description'   => 'Race',
            'serialnumber'  => '',
            'quantity'      => (string) $args[2],
            'discount'      => '0',
            'discount_type' => 0,
            'cost_price'    => (string) $args[3],
            'price'         => (string) $args[4],
            'item_location' => 1,
            'print_option'  => 0,
        ]];
        $taxes    = [[], []];
        $payments = isset($args[7]) ? [['payment_type' => (string) $args[7], 'payment_amount' => (string) $args[8], 'cash_refund' => '0', 'cash_adjustment' => 0, 'reference_code' => null]] : [];

        return model(App\Models\Sale::class)->save_value(NEW_ENTRY, $status, $items, (int) $args[5] > 0 ? (int) $args[5] : NEW_ENTRY, (int) $args[6], 'race', null, null, null, SALE_TYPE_RETURN, $payments, null, $taxes, true, (int) $args[0]) > 0;
    })($args),
    'openingStock' => (static function (array $args): bool {
        $results = (new App\Libraries\Accounting\OpeningStock())->post(array_map('intval', explode(',', (string) $args[0])), null);

        return count(array_filter($results, static fn (App\Libraries\Accounting\OpeningStockResult $result): bool => $result->plan->status === App\Libraries\Accounting\OpeningStockStatus::Posted)) > 0;
    })($args),
    'reverseOpeningStock' => (static function (array $args): bool {
        try {
            (new App\Libraries\Accounting\OpeningStock())->reverse((int) $args[0], (string) $args[1], 'race', null);

            return true;
        } catch (Throwable) {
            return false;
        }
    })($args),
    'purchase' => (static function (array $args): bool {
        $lines = [[
            'item_id'            => (int) $args[1],
            'line'               => 0,
            'description'        => 'Race',
            'serialnumber'       => '',
            'quantity'           => (string) $args[2],
            'receiving_quantity' => 1,
            'discount'           => 0,
            'discount_type'      => 0,
            'price'              => (string) $args[3],
            'item_location'      => 1,
            'tax_percent'        => 0,
        ]];

        return model(App\Models\Receiving::class)->save_value($lines, (int) $args[0], (int) $args[4], 'race', 'REF', 'Due', NEW_ENTRY, isset($args[5]) ? (int) $args[5] : null) > 0;
    })($args),
    'deletePurchase' => model(App\Models\Receiving::class)->delete_value((int) $args[0], (int) $args[1]),
    'payPurchase' => model(App\Models\Receiving::class)->update((int) $args[0], ['payments' => [
        ['payment_id' => NEW_ENTRY, 'payment_type' => 'Cash', 'payment_amount' => (string) $args[1], 'employee_id' => (int) $args[2], 'payment_status' => 'completed', 'reference_code' => ''],
    ]]),
    default => throw new InvalidArgumentException("Unknown race method: {$method}"),
};

echo $result ? '1' : '0';
