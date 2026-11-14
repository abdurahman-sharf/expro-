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
use App\Libraries\Accounting\Backfill\BackfillService;
use App\Libraries\Accounting\ChequeService;
use App\Libraries\Accounting\CreditRefunds;
use App\Libraries\Accounting\CrossBranchPayments;
use App\Libraries\Accounting\CustomerAdvances;
use App\Libraries\Accounting\CustomerOpening;
use App\Libraries\Accounting\InterBranchSettlements;
use App\Libraries\Accounting\JournalDraft;
use App\Libraries\Accounting\JournalLineDraft;
use App\Libraries\Accounting\OpeningBalanceService;
use App\Libraries\Accounting\OpeningStock;
use App\Libraries\Accounting\OpeningStockResult;
use App\Libraries\Accounting\OpeningStockStatus;
use App\Libraries\Accounting\ReceivingLineEdit;
use App\Libraries\Accounting\SalePostingBuilder;
use App\Libraries\Accounting\SupplierAdvances;
use App\Libraries\Accounting\YearEndClosing;
use App\Libraries\EInvoicing\EInvoiceService;
use App\Libraries\Stock\StockPermit;
use App\Libraries\Stock\StockRequest;
use App\Libraries\Stock\Stocktake;
use App\Libraries\Numbering\DocumentNumbering;
use App\Libraries\Numbering\DocumentType;
use App\Libraries\PurchaseCycle\PurchaseDocumentKind;
use App\Libraries\PurchaseCycle\PurchaseDocuments;
use App\Libraries\PurchaseCycle\PurchaseOrderReceipts;
use App\Libraries\Recurring\RecurringGenerator;
use App\Models\Cashup;
use App\Models\Customer;
use App\Models\Expense;
use App\Models\Giftcard;
use App\Models\Item_quantity;
use App\Models\Receiving;
use App\Models\Sale;
use Config\Database;

$root = dirname(__DIR__, 2);

chdir($root);

$loader     = require $root . '/vendor/autoload.php';
$vendorHome = dirname((string) realpath($root . '/vendor'));

// Composer builds its class map and its prefix paths from the real path of the vendor directory it is read
// from. A vendor directory that is a link to another checkout therefore points App\ at that checkout, and
// the worker would race the code of another checkout than the one the test runs in.
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

if (APPPATH !== (string) realpath($root . '/app') . '/' || ! str_starts_with((string) realpath((string) $loader->findFile(Sale::class)), $root . '/app/')) {
    fwrite(STDERR, 'The race worker would load the application from outside ' . $root . "\n");

    exit(3);
}

$method = $argv[1];
$args   = array_slice($argv, 2);

$readyFile = getenv('RACE_READY_FILE');
$goFile    = getenv('RACE_GO_FILE');

file_put_contents($readyFile, '1');

while (! file_exists($goFile)) {
    usleep(1000);
}

$attempt = static function (callable $action, string $outcomeFile): bool {
    try {
        $action();
        $outcome = 'done';
    } catch (AccountingException $exception) {
        $outcome = $exception->error->value;
    } catch (Throwable $exception) {
        $outcome = get_class($exception) . ' ' . $exception->getMessage();
    }

    file_put_contents($outcomeFile, $outcome);

    return $outcome === 'done';
};

$attemptModel = static function (callable $action, string $outcomeFile): bool {
    try {
        $outcome = $action() === true ? 'done' : 'refused';
    } catch (Throwable $exception) {
        $outcome = get_class($exception) . ' ' . $exception->getMessage();
    }

    file_put_contents($outcomeFile, $outcome);

    return $outcome === 'done';
};

$result = match ($method) {
    'giftcard' => model(Giftcard::class)
        ->decrementGiftcardValue((string) $args[0], (float) $args[1]),
    'rewardPoints' => model(Customer::class)
        ->adjustRewardPoints((int) $args[0], (float) $args[1]),
    'itemQuantity' => model(Item_quantity::class)
        ->changeQuantity((int) $args[0], (int) $args[1], (float) $args[2]),
    'confirmDelivery' => model(Sale::class)
        ->confirm_delivery((int) $args[0], (int) $args[1]) > 0,
    'issueDraft' => model(Sale::class)
        ->issueDraft((int) $args[0], (int) $args[1]) > 0,
    'cancelSale' => model(Sale::class)
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

        return model(Sale::class)->save_value(NEW_ENTRY, $status, $items, (int) $args[0], (int) $args[3], 'race', null, null, null, SALE_TYPE_INVOICE, [], null, $taxes) > 0;
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

        return model(Sale::class)->save_value(NEW_ENTRY, $status, $items, (int) $args[5] > 0 ? (int) $args[5] : NEW_ENTRY, (int) $args[6], 'race', null, null, null, SALE_TYPE_RETURN, $payments, null, $taxes, true, (int) $args[0]) > 0;
    })($args),
    'issueEInvoice' => (static function (array $args): bool {
        try {
            (new EInvoiceService())->issueSale((int) $args[0], null, '', null);

            return true;
        } catch (Throwable) {
            return false;
        }
    })($args),
    'recurringRun' => (static fn (array $args): bool => (new RecurringGenerator())->run((string) $args[0], (int) $args[1])->generated() > 0)($args),
    'orderInvoice' => (static function (array $args): bool {
        $status = COMPLETED;
        $items  = [[
            'item_id'       => (int) $args[2],
            'line'          => 0,
            'description'   => 'Race',
            'serialnumber'  => '',
            'quantity'      => (string) $args[1],
            'discount'      => '0',
            'discount_type' => 0,
            'cost_price'    => '0',
            'price'         => '50.00',
            'item_location' => 1,
            'print_option'  => 0,
        ]];
        $taxes = [[], []];

        return model(Sale::class)->save_value(NEW_ENTRY, $status, $items, (int) $args[3], (int) $args[4], 'race', null, null, null, SALE_TYPE_INVOICE, [], null, $taxes, true, orderSourceId: (int) $args[0]) > 0;
    })($args),
    'openingStock' => (static function (array $args): bool {
        $results = (new OpeningStock())->post(array_map('intval', explode(',', (string) $args[0])), null);

        return count(array_filter($results, static fn (OpeningStockResult $result): bool => $result->plan->status === OpeningStockStatus::Posted)) > 0;
    })($args),
    'reverseOpeningStock' => (static function (array $args): bool {
        try {
            (new OpeningStock())->reverse((int) $args[0], (string) $args[1], 'race', null);

            return true;
        } catch (Throwable) {
            return false;
        }
    })($args),
    'customerOpening' => (static function (array $args): bool {
        try {
            (new CustomerOpening())->create((int) $args[0], CustomerOpening::OWES_US, (string) $args[1], (string) $args[2], 'race', null);

            return true;
        } catch (Throwable) {
            return false;
        }
    })($args),
    'reviseCustomerOpening' => (static function (array $args): bool {
        try {
            (new CustomerOpening())->revise((int) $args[0], CustomerOpening::OWES_US, (string) $args[1], (string) $args[2], 'race', 'race', null);

            return true;
        } catch (Throwable) {
            return false;
        }
    })($args),
    'repriceOpeningStock' => (static function (array $args): bool {
        try {
            (new OpeningStock())->repostAtCurrentCost((int) $args[0], 'race', (string) $args[1], null);

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

        return model(Receiving::class)->save_value($lines, (int) $args[0], (int) $args[4], 'race', 'REF', 'Due', NEW_ENTRY, isset($args[5]) ? (int) $args[5] : null) > 0;
    })($args),
    'backfillApprove' => (static function (array $args): bool {
        try {
            (new BackfillService())->approve((int) $args[0], (string) $args[1], (int) $args[2]);

            return true;
        } catch (Throwable) {
            return false;
        }
    })($args),
    'backfillCreate' => (static function (array $args): bool {
        try {
            (new BackfillService())->create(['name' => 'Race ' . uniqid(), 'from' => (string) $args[0], 'to' => (string) $args[1]], (int) $args[2]);

            return true;
        } catch (Throwable) {
            return false;
        }
    })($args),
    'issuePermit' => $attempt(static fn () => (new StockPermit())->create([
        'type'        => 'issue',
        'date'        => date('Y-m-d'),
        'location_id' => 1,
        'lines'       => [['item_id' => (int) $args[0], 'quantity' => (string) $args[1]]],
    ], (int) $args[2]), (string) $args[3]),
    'issueSerialPermit' => $attempt(static fn () => (new StockPermit())->create([
        'type'        => 'issue',
        'date'        => date('Y-m-d'),
        'location_id' => 1,
        'lines'       => [['item_id' => (int) $args[0], 'quantity' => '1', 'tracking' => ['serials' => [(string) $args[1]]]]],
    ], (int) $args[2]), (string) $args[3]),
    'settleBranches' => $attempt(static fn () => (new InterBranchSettlements())->settle((int) $args[0], (int) $args[1], (string) $args[2], (int) $args[3], (int) $args[4], '', null, (int) $args[5]), (string) $args[6]),
    'sellUnits' => (static function (array $args): bool {
        $status = COMPLETED;
        $items  = [[
            'item_id'       => (int) $args[0],
            'line'          => 0,
            'description'   => 'Race',
            'serialnumber'  => '',
            'quantity'      => (string) $args[1],
            'discount'      => '0',
            'discount_type' => 0,
            'cost_price'    => '0',
            'price'         => '10.00',
            'item_location' => 1,
            'print_option'  => 0,
        ]];
        $taxes  = [[], []];
        $result = model(Sale::class)->save_value(NEW_ENTRY, $status, $items, -1, (int) $args[2], 'race', null, null, null, SALE_TYPE_POS, [['payment_type' => 'Cash', 'payment_amount' => '10.00', 'cash_refund' => '0', 'cash_adjustment' => 0, 'reference_code' => null]], null, $taxes, true);

        file_put_contents((string) $args[3], $result > 0 ? 'done' : 'refused');

        return $result > 0;
    })($args),
    'sellSerial' => (static function (array $args): bool {
        $status = COMPLETED;
        $items  = [[
            'item_id'       => (int) $args[0],
            'line'          => 0,
            'description'   => 'Race',
            'serialnumber'  => (string) $args[1],
            'quantity'      => '1',
            'discount'      => '0',
            'discount_type' => 0,
            'cost_price'    => '0',
            'price'         => '10.00',
            'item_location' => 1,
            'print_option'  => 0,
        ]];
        $taxes  = [[], []];
        $result = model(Sale::class)->save_value(NEW_ENTRY, $status, $items, -1, (int) $args[2], 'race', null, null, null, SALE_TYPE_POS, [['payment_type' => 'Cash', 'payment_amount' => '10.00', 'cash_refund' => '0', 'cash_adjustment' => 0, 'reference_code' => null]], null, $taxes, true);

        file_put_contents((string) $args[3], $result > 0 ? 'done' : 'refused');

        return $result > 0;
    })($args),
    'cancelPermit' => $attempt(static fn () => (new StockPermit())->cancel((int) $args[0], 'race', (int) $args[1]), (string) $args[2]),
    'fulfilRequest' => $attempt(static fn () => (new StockRequest())->fulfil((int) $args[0], (int) $args[1]), (string) $args[2]),
    'approveStocktake' => $attempt(static fn () => (new Stocktake())->approve((int) $args[0], (int) $args[1]), (string) $args[2]),
    'addStocktakeItems' => $attempt(static fn () => (new Stocktake())->addItems((int) $args[0], [(int) $args[1]], (int) $args[2]), (string) $args[3]),
    'createStocktake' => $attempt(static fn () => (new Stocktake())->create(['location_id' => (int) $args[0], 'date' => (string) $args[1], 'item_ids' => [(int) $args[2]]], (int) $args[3]), (string) $args[4]),
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
            echo $exception::class . ' ' . $exception->getMessage();
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
            echo $exception::class . ' ' . $exception->getMessage();
        }

        exit(0);
    })($args),
    'orderReceive' => (static function (array $args): bool {
        $lines = [[
            'item_id'            => (int) $args[2],
            'line'               => 0,
            'description'        => 'Race',
            'serialnumber'       => '',
            'quantity'           => (string) $args[1],
            'receiving_quantity' => 1,
            'discount'           => 0,
            'discount_type'      => 0,
            'price'              => '4.00',
            'item_location'      => 1,
            'tax_percent'        => 0,
        ]];

        return model(Receiving::class)->save_value($lines, (int) $args[3], (int) $args[4], 'race', 'REF', 'Due', NEW_ENTRY, null, null, null, null, (int) $args[0]) > 0;
    })($args),
    'orderRecord' => (static function (array $args): bool {
        try {
            (new PurchaseOrderReceipts())->recordReceiving((int) $args[0], (int) $args[1], [['item_id' => (int) $args[3], 'quantity' => (string) $args[2], 'receiving_quantity' => 1]], (int) $args[4], (int) $args[5], 1);

            return true;
        } catch (Throwable) {
            return false;
        }
    })($args),
    'convertDocument' => (static function (array $args): bool {
        try {
            (new PurchaseDocuments(PurchaseDocumentKind::from((string) $args[0])))->convert((int) $args[1], (int) $args[2]);

            return true;
        } catch (Throwable) {
            return false;
        }
    })($args),
    'editPurchaseLines' => model(Receiving::class)->updateLines((int) $args[0], [ReceivingLineEdit::changing((int) $args[1], 0, ['quantity' => (string) $args[2]])], (int) $args[3]),
    'deletePurchase'    => model(Receiving::class)->delete_value((int) $args[0], (int) $args[1]),
    'payPurchase'       => model(Receiving::class)->update((int) $args[0], ['payments' => [
        ['payment_id' => NEW_ENTRY, 'payment_type' => 'Cash', 'payment_amount' => (string) $args[1], 'employee_id' => (int) $args[2], 'payment_status' => 'completed', 'reference_code' => ''],
    ]]),
    'loadedFrom' => (static function (): never {
        echo (string) (new ReflectionClass(Sale::class))->getFileName();

        exit(0);
    })(),
    'creditRace' => $attempt(static function () use ($args): void {
        match ($args[0]) {
            'refund'                => (new CreditRefunds((string) $args[1]))->refund((int) $args[2], (string) $args[3], (string) $args[4], 'Cash', null, '', '', (int) $args[5], $args[6] === '' ? null : (string) $args[6]),
            'cancelRefund'          => (new CreditRefunds((string) $args[1]))->cancel((int) $args[2], 'race', (int) $args[3]),
            'cancelCustomerAdvance' => (new CustomerAdvances())->cancel((int) $args[1], 'race', (int) $args[2]),
            'cancelSupplierAdvance' => (new SupplierAdvances())->cancel((int) $args[1], 'race', (int) $args[2]),
            'cancelOpening'         => (new CustomerOpening())->cancel((int) $args[1], 'race', (int) $args[2]),
            'rejectCheque'          => (new ChequeService())->reject((int) $args[1], date('Y-m-d'), (int) $args[2]),
        };
    }, (string) $args[array_key_last($args)]),
    'saveExpense'           => $attemptModel(static function () use ($args): bool {
        $data = [
            'date'                => '2026-10-20 10:00:00',
            'supplier_id'         => null,
            'supplier_tax_code'   => '',
            'amount'              => (string) $args[1],
            'tax_amount'          => '0',
            'payment_type'        => 'Cash',
            'expense_category_id' => (int) $args[2],
            'description'         => 'Race expense',
            'employee_id'         => (int) $args[3],
            'deleted'             => 0,
        ];

        return model(Expense::class)->save_value($data, (int) $args[0], (int) $args[3]);
    }, (string) $args[4]),
    'deleteExpense' => $attemptModel(static fn (): bool => model(Expense::class)->delete_list([(int) $args[0]], (int) $args[1]), (string) $args[2]),
    'saveCashup'    => $attemptModel(static function () use ($args): bool {
        $data = [
            'open_date'            => '2026-10-20 08:00:00',
            'close_date'           => '2026-10-20 18:00:00',
            'open_amount_cash'     => '100',
            'transfer_amount_cash' => '0',
            'closed_amount_cash'   => (string) $args[1],
            'closed_amount_due'    => '0',
            'closed_amount_card'   => '0',
            'closed_amount_check'  => '0',
            'closed_amount_total'  => bcsub((string) $args[1], '100', 2),
            'note'                 => 0,
            'description'          => 'Race cash-up',
            'open_employee_id'     => (int) $args[2],
            'close_employee_id'    => (int) $args[2],
            'deleted'              => 0,
        ];

        return model(Cashup::class)->save_value($data, (int) $args[0], (int) $args[2]);
    }, (string) $args[3]),
    'deleteCashup'      => $attemptModel(static fn (): bool => model(Cashup::class)->delete_list([(int) $args[0]], (int) $args[1]), (string) $args[2]),
    'postCrossBranch'   => $attempt(static function () use ($args): void {
        $payments = new CrossBranchPayments();
        $amount   = (string) $args[1];
        $treasury = (int) $args[7];
        $customer = (int) $args[6];

        $draft = new JournalDraft(
            date: '2026-10-21',
            lines: $args[2] === '1'
                ? [new JournalLineDraft($treasury, debit: $amount), new JournalLineDraft($customer, credit: $amount)]
                : [new JournalLineDraft($customer, debit: $amount), new JournalLineDraft($treasury, credit: $amount)],
            sourceType: SalePostingBuilder::SOURCE_PAYMENT,
            sourceId: (int) $args[0],
            description: 'Race payment #' . $args[0],
            branchId: (int) $args[5],
        );

        $payments->post($payments->drafts($draft, (int) $args[3], (int) $args[4]));
    }, (string) $args[8]),
    default => throw new InvalidArgumentException("Unknown race method: {$method}"),
};

echo $result ? '1' : '0';
