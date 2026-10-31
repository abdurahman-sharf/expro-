<?php

/**
 * Standalone CLI worker used by ConcurrentDbRaceTrait::raceTwoProcesses().
 * Bootstraps CodeIgniter in its own process so the model method call below
 * runs on a genuinely separate DB connection from any sibling worker,
 * exercising real concurrent access instead of simulating it sequentially.
 *
 * Usage: php RaceWorker.php <method> <arg> [<arg> ...]
 */

use App\Libraries\Accounting\AccountingException;
use App\Libraries\Accounting\OpeningBalanceService;
use App\Libraries\Accounting\YearEndClosing;
use App\Libraries\Numbering\DocumentNumbering;
use App\Libraries\Numbering\DocumentType;
use Config\Database;

$root = dirname(__DIR__, 2);

chdir($root);

$loader     = require $root . '/vendor/autoload.php';
$vendorHome = dirname((string) realpath($root . '/vendor'));

// A git worktree shares the vendor directory of the main checkout, whose class map points App\
// at the main checkout. The worker must run the code of the checkout it sits in.
if ($vendorHome !== $root) {
    $classMap = [];

    foreach ($loader->getClassMap() as $class => $file) {
        $path = (string) realpath($file);

        if (str_starts_with($path, $vendorHome . '/app/') && is_file($root . substr($path, strlen($vendorHome)))) {
            $classMap[$class] = $root . substr($path, strlen($vendorHome));
        }
    }

    $loader->addClassMap($classMap);
    $loader->setPsr4('App\\', [$root . '/app']);
}

require $root . '/vendor/codeigniter4/framework/system/Test/bootstrap.php';

date_default_timezone_set('America/New_York');

// A checkout whose vendor directory is another checkout's gets that checkout's classes from Composer, so
// the worker puts the application of the checkout it runs in first and races the code under test.
spl_autoload_register(static function (string $class): void {
    if (! str_starts_with($class, 'App\\')) {
        return;
    }

    $file = APPPATH . str_replace('\\', '/', substr($class, 4)) . '.php';

    if (is_file($file)) {
        require_once $file;
    }
}, true, true);

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
    'assignNumber' => (static function (array $args): bool {
        $db = Database::connect();

        try {
            $db->transStart();
            $number = (new DocumentNumbering($db))->assign(DocumentType::from((string) $args[0]), (string) $args[1], (int) $args[2]);
            usleep((int) $args[3] * 1000);
            $db->transComplete();

            return $number !== null && $db->transStatus();
        } catch (Throwable) {
            return false;
        }
    })($args),
    'yearEndClose' => (static function (array $args): bool {
        try {
            $service  = new YearEndClosing();
            $branchId = $args[2] === 'company' ? null : (int) $args[2];
            $preview  = $service->preview($branchId, (int) $args[1], (int) $args[0]);

            $service->approve($branchId, (int) $args[1], $preview->fingerprint, (int) $args[0]);

            return true;
        } catch (Throwable) {
            return false;
        }
    })($args),
    'yearEndCloseOutcome' => (static function (array $args): never {
        $service  = new YearEndClosing();
        $branchId = $args[2] === 'company' ? null : (int) $args[2];

        try {
            $preview = $service->preview($branchId, (int) $args[1], (int) $args[0]);
        } catch (Throwable $exception) {
            echo 'preview ' . $exception->getMessage();

            exit(0);
        }

        try {
            $service->approve($branchId, (int) $args[1], $preview->fingerprint, (int) $args[0]);

            echo 'closed';
        } catch (AccountingException $exception) {
            echo $exception->error->value;
        } catch (Throwable $exception) {
            echo get_class($exception) . ' ' . $exception->getMessage();
        }

        exit(0);
    })($args),
    'reverseOpeningBalanceOutcome' => (static function (array $args): never {
        try {
            (new OpeningBalanceService())->reverse((int) $args[0], (string) $args[1], 'race', (int) $args[2]);

            echo 'reversed';
        } catch (AccountingException $exception) {
            echo $exception->error->value;
        } catch (Throwable $exception) {
            echo get_class($exception) . ' ' . $exception->getMessage();
        }

        exit(0);
    })($args),
    'editPurchaseLines' => model(App\Models\Receiving::class)->updateLines((int) $args[0], [App\Libraries\Accounting\ReceivingLineEdit::changing((int) $args[1], 0, ['quantity' => (string) $args[2]])], (int) $args[3]),
    'deletePurchase' => model(App\Models\Receiving::class)->delete_value((int) $args[0], (int) $args[1]),
    'payPurchase' => model(App\Models\Receiving::class)->update((int) $args[0], ['payments' => [
        ['payment_id' => NEW_ENTRY, 'payment_type' => 'Cash', 'payment_amount' => (string) $args[1], 'employee_id' => (int) $args[2], 'payment_status' => 'completed', 'reference_code' => ''],
    ]]),
    default => throw new InvalidArgumentException("Unknown race method: {$method}"),
};

echo $result ? '1' : '0';
