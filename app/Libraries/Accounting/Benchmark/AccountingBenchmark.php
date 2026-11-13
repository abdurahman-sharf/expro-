<?php

namespace App\Libraries\Accounting\Benchmark;

use App\Libraries\Accounting\AgingBuckets;
use App\Libraries\Accounting\CustomerLedger;
use App\Libraries\Accounting\EntityAccountService;
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
use App\Libraries\Accounting\ReturnDocument;
use App\Libraries\Accounting\TrialBalance;
use App\Models\Item;
use App\Models\Sale;
use Closure;
use CodeIgniter\Database\BaseConnection;
use Config\Database;
use RuntimeException;
use Throwable;

/**
 * Times the work an owner waits for on a data set it generates itself: the sale save with its posting, the
 * readiness checks, the trial balance, the aged ledger and the report families. Everything it writes (the
 * customers, the items, the sales, their journals and stock movements) is written inside one transaction
 * that is rolled back at the end, so no row of any table is left behind; the counters that give out numbers
 * (an auto-increment) move on and are not given back. It is a tool for a copy of the data or a development
 * database and never for a production one: while it runs it holds locks and writes a great deal to the log of
 * the database. The measurements are recorded, never asserted against.
 */
final class AccountingBenchmark
{
    private const ITEMS         = 30;
    private const MAX_CUSTOMERS = 400;
    private const CUSTOMERS_FOR = 20;
    private const LOCATION_ID   = 1;
    private const PAYING_EVERY  = 2;
    private const PARTIAL_EVERY = 7;

    private BaseConnection $db;

    /**
     * @param (Closure(BaseConnection): void)|null $afterMeasuring Work done inside the transaction after the measurements, which a test uses to leave a row behind and see that it is noticed.
     */
    public function __construct(?BaseConnection $db = null, private readonly ?Closure $afterMeasuring = null)
    {
        $this->db = $db ?? Database::connect();
    }

    /**
     * @return array{environment: array<string, mixed>, documents: int, rolled_back: bool, rows_left: array<string, int>, results: list<array<string, mixed>>}
     *
     * @throws RuntimeException When the books are not ready to be sold into, or the work could not be undone.
     */
    public function run(int $documents): array
    {
        $employeeId = $this->employee();
        $before     = $this->rowCounts();
        $results    = [];

        $this->db->saveQueries = false;
        $this->db->transBegin();

        try {
            $customers = $this->customers(max(10, min(self::MAX_CUSTOMERS, intdiv($documents, self::CUSTOMERS_FOR))), $employeeId);
            $items     = $this->items();

            $results[] = $this->sales($documents, $customers, $items, $employeeId);

            foreach ($this->measurements() as $name => $work) {
                $results[] = $this->measure($name, $work);
            }

            $this->afterMeasuring === null || ($this->afterMeasuring)($this->db);
        } finally {
            $this->db->transRollback();
            $this->db->resetTransStatus();
        }

        $left = [];

        foreach ($this->rowCounts() as $table => $now) {
            $difference = $now - ($before[$table] ?? 0);

            if ($difference !== 0) {
                $left[$table] = $difference;
            }
        }

        if ($left !== []) {
            throw new RuntimeException('The benchmark could not undo its writes: ' . json_encode($left));
        }

        return [
            'environment' => $this->environment(),
            'documents'   => $documents,
            'rolled_back' => true,
            'rows_left'   => $left,
            'results'     => $results,
        ];
    }

    /**
     * @return array<string, callable(): int> The work of each measurement, which returns the rows or checks it covered.
     */
    private function measurements(): array
    {
        $scope = ReportScope::resolve(null, null);
        $today = date('Y-m-d');
        $work  = [
            'readiness'          => static fn (): int => count((new ReadinessRunner())->run()->checks),
            'trial_balance'      => static fn (): int => count((new TrialBalance())->build($scope, ReportPeriod::of(null, $today))->rows),
            'aged_ledger'        => static fn (): int => count((new CustomerLedger())->agedLedger($today, AgingBuckets::DEFAULT_PERIOD)),
            'sales_revenue'      => static fn (): int => (new RevenueReport())->build($scope, RevenueGroup::Customer, false, null, $today, $today, allDates: true)->documents,
            'sales_revenue_rows' => static fn (): int => (new RevenueReport())->build($scope, RevenueGroup::Customer, true, null, $today, $today, allDates: true)->documents,
            'clients_sales'      => static fn (): int => (new ClientsSalesReport())->build($scope, null, true, false, null, $today)->documents,
            'item_sales'         => static fn (): int => (new ItemSalesReport())->build($scope, ItemGroup::Item, false, null, $today, $today, allDates: true)->documents,
            'item_profit'        => static fn (): int => (new ProfitReport())->build($scope, ItemGroup::Item, false, null, $today, allDates: true)->documents,
            'payments'           => static fn (): int => (new PaymentsReport())->build($scope, PaymentGroup::Customer, false, null, $today, allDates: true)->documents,
            'net_position'       => static fn (): int => (new NetPositionReport())->build($scope, false, null, $today)->documents,
        ];

        foreach (FamilyReportCatalog::all() as $key => $definition) {
            $work['family_' . $key] = static fn (): int => count((new ($definition['class'])())->build(new ReportInput($scope, null, [], $today))->blocks);
        }

        return $work;
    }

    /**
     * @param callable(): int $work
     *
     * @return array<string, mixed>
     */
    private function measure(string $name, callable $work): array
    {
        $queries = $this->questions();
        $started = hrtime(true);
        $covered = null;
        $failure = null;

        try {
            $covered = $work();
        } catch (Throwable $exception) {
            $failure = $exception::class . ': ' . $exception->getMessage();
        }

        $elapsed = (hrtime(true) - $started) / 1_000_000;

        return array_filter([
            'name'    => $name,
            'ms'      => round($elapsed, 2),
            'queries' => $this->questions() - $queries - 1,
            'covered' => $covered,
            'failed'  => $failure,
        ], static fn (mixed $value): bool => $value !== null);
    }

    /**
     * @param list<int>                                         $customers
     * @param list<array{id: int, price: string, cost: string}> $items
     *
     * @return array<string, mixed>
     */
    private function sales(int $documents, array $customers, array $items, int $employeeId): array
    {
        $model   = model(Sale::class);
        $timings = [];
        $queries = 0;
        $refused = 0;
        $failure = null;
        $started = hrtime(true);

        for ($index = 0; $index < $documents; $index++) {
            $lines     = [];
            $total     = '0';
            $lineCount = 1 + $index % 3;

            for ($line = 0; $line < $lineCount; $line++) {
                $item     = $items[($index + $line) % count($items)];
                $quantity = (string) (1 + ($index + $line) % 4);
                $total    = bcadd($total, bcmul($quantity, $item['price'], 2), 2);
                $lines[]  = [
                    'item_id'       => $item['id'],
                    'line'          => $line,
                    'description'   => 'Benchmark item',
                    'serialnumber'  => '',
                    'quantity'      => $quantity,
                    'discount'      => '0',
                    'discount_type' => PERCENT,
                    'cost_price'    => $item['cost'],
                    'price'         => $item['price'],
                    'item_location' => self::LOCATION_ID,
                    'print_option'  => 0,
                ];
            }

            $payments = [];

            if ($index % self::PAYING_EVERY === 0) {
                $payments[] = ['payment_type' => lang('Sales.cash'), 'payment_amount' => $total, 'cash_refund' => '0', 'cash_adjustment' => 0, 'reference_code' => null];
            } elseif ($index % self::PARTIAL_EVERY === 0) {
                $payments[] = ['payment_type' => lang('Sales.cash'), 'payment_amount' => bcdiv($total, '2', 2), 'cash_refund' => '0', 'cash_adjustment' => 0, 'reference_code' => null];
            }

            $status     = COMPLETED;
            $salesTaxes = [[], []];
            $before     = $this->questions();
            $one        = hrtime(true);
            $saleId     = $model->save_value(NEW_ENTRY, $status, $lines, $customers[$index % count($customers)], $employeeId, 'benchmark', null, null, null, SALE_TYPE_INVOICE, $payments, null, $salesTaxes, true, null, null, ReturnDocument::CreditNote);

            $timings[] = (hrtime(true) - $one) / 1_000_000;
            $queries += $this->questions() - $before - 1;

            if ($saleId <= 0) {
                $refused++;
                $failure ??= 'the sale save refused document ' . ($index + 1) . ' (' . $status . ')';
            }
        }

        sort($timings);

        $count = max(1, count($timings));

        return array_filter([
            'name'                 => 'sale_save_with_posting',
            'ms'                   => round((hrtime(true) - $started) / 1_000_000, 2),
            'documents'            => $documents,
            'refused'              => $refused,
            'ms_per_document'      => round(array_sum($timings) / $count, 2),
            'ms_median'            => round($timings[intdiv($count, 2)] ?? 0.0, 2),
            'ms_p95'               => round($timings[(int) floor($count * 0.95) - ($count > 1 ? 1 : 0)] ?? 0.0, 2),
            'ms_max'               => round($timings[$count - 1] ?? 0.0, 2),
            'queries'              => $queries,
            'queries_per_document' => round($queries / $count, 1),
            'failed'               => $failure,
        ], static fn (mixed $value): bool => $value !== null);
    }

    /**
     * @return list<int>
     */
    private function customers(int $count, int $employeeId): array
    {
        $entities = new EntityAccountService();
        $ids      = [];

        for ($index = 1; $index <= $count; $index++) {
            $this->db->table('people')->insert(['first_name' => 'Benchmark', 'last_name' => 'Customer ' . $index, 'phone_number' => '', 'email' => '', 'address_1' => '', 'address_2' => '', 'city' => '', 'state' => '', 'zip' => '', 'country' => '', 'comments' => '']);

            $personId = (int) $this->db->insertID();

            $this->db->table('customers')->insert(['person_id' => $personId, 'company_name' => null, 'employee_id' => $employeeId]);
            $entities->ensure(EntityAccountService::CUSTOMER, $personId, 'Benchmark Customer ' . $index);

            $ids[] = $personId;
        }

        return $ids;
    }

    /**
     * @return list<array{id: int, price: string, cost: string}>
     */
    private function items(): array
    {
        $model = model(Item::class);
        $items = [];

        for ($index = 1; $index <= self::ITEMS; $index++) {
            $cost  = (string) (5 + $index);
            $price = (string) (10 + $index * 3);
            $data  = [
                'item_id'               => null,
                'name'                  => 'Benchmark item ' . $index,
                'description'           => 'Benchmark item',
                'category'              => 'Benchmark',
                'cost_price'            => $cost,
                'unit_price'            => $price,
                'reorder_level'         => 0,
                'item_number'           => 'BENCH-' . $index . '-' . uniqid(),
                'allow_alt_description' => 0,
                'is_serialized'         => 0,
                'stock_type'            => HAS_STOCK,
                'deleted'               => 0,
            ];

            $model->save_value($data);

            $items[] = ['id' => (int) $data['item_id'], 'price' => $price . '.00', 'cost' => $cost . '.00'];
        }

        return $items;
    }

    private function employee(): int
    {
        $row = $this->db->table('employees')->select('person_id')->where('deleted', 0)->orderBy('person_id', 'ASC')->get()->getRow();

        if ($row === null) {
            throw new RuntimeException('There is no employee to save the sales as.');
        }

        return (int) $row->person_id;
    }

    /**
     * @return array<string, int>
     */
    private function rowCounts(): array
    {
        $counts = [];

        foreach (['sales', 'sales_items', 'sales_payments', 'accounting_journals', 'accounting_journal_lines', 'accounting_accounts', 'items', 'people', 'customers', 'inventory', 'accounting_stock_entries'] as $table) {
            if ($this->db->tableExists($table)) {
                $counts[$table] = $this->db->table($table)->countAllResults();
            }
        }

        return $counts;
    }

    private function questions(): int
    {
        return (int) $this->db->query("SHOW SESSION STATUS LIKE 'Questions'")->getRow()->Value;
    }

    /**
     * @return array<string, mixed>
     */
    private function environment(): array
    {
        $cores = is_readable('/proc/cpuinfo') ? substr_count((string) file_get_contents('/proc/cpuinfo'), 'processor') : null;

        return [
            'php'         => PHP_VERSION,
            'database'    => (string) $this->db->query('SELECT VERSION() AS version')->getRow()->version,
            'cores'       => $cores,
            'memory_peak' => round(memory_get_peak_usage(true) / 1048576, 1) . ' MB',
            'date'        => date('Y-m-d H:i:s'),
        ];
    }
}
