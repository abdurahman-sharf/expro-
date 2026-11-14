<?php

namespace Tools\Benchmarks\R6;

use App\Libraries\Accounting\EntityAccountService;
use App\Libraries\Accounting\ReturnDocument;
use App\Libraries\Accounting\TreasuryService;
use App\Libraries\Numbering\DocumentNumbering;
use App\Libraries\Numbering\DocumentType;
use App\Libraries\Organization\BranchGuard;
use App\Libraries\Organization\BranchService;
use App\Models\Customer;
use App\Models\Employee;
use App\Models\Expense;
use App\Models\Item;
use App\Models\Receiving;
use App\Models\Sale;
use App\Models\Supplier;
use CodeIgniter\Database\BaseConnection;
use CodeIgniter\Events\Events;
use Config\Database;
use DateTimeImmutable;
use DateTimeZone;
use R6Clock;
use RuntimeException;

/**
 * Builds a small-to-medium trading business through the real services, one simulated day at a time, on
 * a database whose name starts with ospos_test_ (3 branches, 3,000 items, 2,000 customers, 200 suppliers,
 * about 164 sales documents a day, 16 purchases and 14 expenses a day). The clock of the application
 * is moved to each day (R6Clock), so the journals, due dates and numbers are the ones a business of that
 * age would hold. Development tool only.
 */
final class Generator
{
    public const START = '2024-11-15';

    private const CUSTOMERS     = 2000;
    private const SUPPLIERS     = 200;
    private const ITEMS         = 3000;
    private const SALES_PER_DAY = 164;
    private const BUYS_PER_DAY  = 16;
    private const SPENDS_PER_DAY = 14;
    private const VAT           = '0.15';
    private const STATE_KEY     = 'r6_state';

    private BaseConnection $db;
    private Sale $sales;
    private Receiving $purchases;
    private Expense $expenses;

    /** @var array<string, mixed> */
    private array $state;

    /** @var list<array{item_id: int, price: string, cost: string, branchStock: array<int, int>}> */
    private array $items = [];

    /** @var list<int> */
    private array $customers = [];

    /** @var list<int> */
    private array $suppliers = [];

    /** @var list<int> */
    private array $categories = [];

    /** @var array<int, array{employee: int, location: int, branch: int}> */
    private array $branches = [];

    /** @var list<float> */
    private array $itemWeights = [];

    /** @var list<float> */
    private array $customerWeights = [];

    /** @var array<string, int> */
    private array $failures = [];

    /** @var list<float> */
    private array $saleTimings = [];

    private DateTimeZone $zone;

    private string $clockTime = '09:00:00';

    public function __construct()
    {
        $this->db = Database::connect();
        $this->db->saveQueries = false;

        if (! str_starts_with($this->db->getDatabase(), 'ospos_test_')) {
            throw new RuntimeException('The generator only writes to databases whose name starts with ospos_test_.');
        }

        if (getenv('R6_DEBUG') === '1') {
            Events::on('DBQuery', static function (\CodeIgniter\Database\Query $query): void {
                if ($query->hasError()) {
                    fwrite(STDERR, 'DB ERROR ' . $query->getErrorCode() . ': ' . $query->getErrorMessage() . ' IN ' . substr(preg_replace('/\s+/', ' ', $query->getQuery()), 0, 300) . "\n");
                }
            });
        }

        $this->zone      = new DateTimeZone(date_default_timezone_get());
        $this->sales     = model(Sale::class);
        $this->purchases = model(Receiving::class);
        $this->expenses  = model(Expense::class);
    }

    public function setup(): void
    {
        $this->at(self::START . ' 08:00:00');
        $this->grantAdministrator();
        $this->enableNumbering();

        $this->db->table('accounting_fiscal_years')->ignore(true)->insertBatch([
            ['company_id' => 1, 'name' => '2025', 'start_date' => '2025-01-01', 'end_date' => '2025-12-31'],
            ['company_id' => 1, 'name' => '2026', 'start_date' => '2026-01-01', 'end_date' => '2026-12-31'],
            ['company_id' => 1, 'name' => '2027', 'start_date' => '2027-01-01', 'end_date' => '2027-12-31'],
        ]);

        $branchIds = [1];
        $service   = new BranchService($this->db);
        $branchIds[] = $service->create(['code' => 'RYD', 'name' => 'Riyadh North'], 1);
        $branchIds[] = $service->create(['code' => 'JED', 'name' => 'Jeddah'], 1);

        $this->db->table('branches')->where('branch_id', 1)->update(['name' => 'Head office']);
        $this->db->table('stock_locations')->where('location_id', 1)->update(['location_name' => 'Main store']);

        $locationIds = [1];

        foreach ([2, 3] as $index) {
            $this->db->table('stock_locations')->insert(['location_name' => 'Store ' . $branchIds[$index - 1], 'deleted' => 0, 'branch_id' => $branchIds[$index - 1]]);

            $locationId    = (int) $this->db->insertID();
            $locationIds[] = $locationId;

            (new EntityAccountService())->ensure(EntityAccountService::STOCK_LOCATION, $locationId, 'Store ' . $index);
        }

        $employeeModel = model(Employee::class);
        $employees     = [1];

        foreach ([2, 3] as $index) {
            $person   = ['first_name' => 'Cashier', 'last_name' => 'Branch' . $index, 'email' => 'cashier' . $index . '@example.test', 'phone_number' => ''];
            $employee = ['username' => 'cashier' . $index, 'password' => password_hash('password123', PASSWORD_DEFAULT), 'hash_version' => 2, 'language_code' => 'en', 'language' => 'english'];
            $grants   = array_map(static fn (string $id): array => ['permission_id' => $id, 'menu_group' => 'home'], ['sales', 'receivings', 'customers', 'items', 'suppliers', 'expenses']);

            if (! $employeeModel->save_employee($person, $employee, $grants, NEW_ENTRY)) {
                throw new RuntimeException('Could not create the cashier of branch ' . $index);
            }

            $personId    = (int) $person['person_id'];
            $employees[] = $personId;

            $this->db->table('employee_branches')->insert(['person_id' => $personId, 'branch_id' => $branchIds[$index - 1], 'is_default' => 1]);
        }

        $this->db->table('grants')->ignore(true)->insert(['permission_id' => BranchGuard::ALL_BRANCHES, 'person_id' => 1, 'menu_group' => 'home']);

        foreach ($branchIds as $index => $branchId) {
            $this->branches[$index] = ['employee' => $employees[$index], 'location' => $locationIds[$index], 'branch' => $branchId];
        }

        $this->createTreasuries($employees, $branchIds);
        $this->createCategories();
        $this->createSuppliers();
        $this->createCustomers();
        $this->createItems();

        $this->state = ['day' => self::START, 'documents' => 0, 'purchases' => 0, 'expenses' => 0, 'payments' => 0, 'pending' => [], 'pendingBuys' => [], 'recent' => [], 'branches' => $this->branches, 'seed' => 6, 'opened' => false];
        $this->saveState();
    }

    /**
     * @return array<string, mixed>
     */
    public function run(int $targetDocuments, ?callable $report = null): array
    {
        $this->loadState();
        $this->at($this->state['day'] . ' 07:00:00');

        if (! $this->state['opened']) {
            $this->openStock();
            $this->state['opened'] = true;
            $this->saveState();
        }

        $started  = microtime(true);
        $startDoc = $this->state['documents'];

        while ($this->state['documents'] < $targetDocuments) {
            $this->oneDay($targetDocuments);

            $this->state['day'] = (new DateTimeImmutable($this->state['day'], $this->zone))->modify('+1 day')->format('Y-m-d');
            $this->saveState();
            (static function (): void {
                static::$performanceLog = [];
            })->bindTo(null, Events::class)();

            if ($report !== null) {
                $report($this->state, microtime(true) - $started, $this->state['documents'] - $startDoc);
            }
        }

        sort($this->saleTimings);
        $count = count($this->saleTimings);

        return [
            'documents'  => $this->state['documents'],
            'day'        => $this->state['day'],
            'failures'   => $this->failures,
            'sale_saves' => $count,
            'ms_p50'     => $count > 0 ? round($this->saleTimings[intdiv($count, 2)], 2) : null,
            'ms_p95'     => $count > 0 ? round($this->saleTimings[(int) floor($count * 0.95)], 2) : null,
            'ms_max'     => $count > 0 ? round($this->saleTimings[$count - 1], 2) : null,
        ];
    }

    private function oneDay(int $targetDocuments): void
    {
        $day      = (string) $this->state['day'];
        $dayIndex = (int) ((new DateTimeImmutable($day, $this->zone))->getTimestamp() - (new DateTimeImmutable(self::START, $this->zone))->getTimestamp()) / 86400;

        mt_srand($this->state['seed'] * 100000 + $dayIndex);

        $sales = max(60, (int) round(self::SALES_PER_DAY * (0.8 + mt_rand(0, 40) / 100)));
        $buys  = max(4, (int) round(self::BUYS_PER_DAY * (0.7 + mt_rand(0, 60) / 100)));
        $spend = max(3, (int) round(self::SPENDS_PER_DAY * (0.7 + mt_rand(0, 60) / 100)));

        $this->at($day . ' 08:00:00');
        $this->payDue($day);

        for ($index = 0; $index < $buys; $index++) {
            $this->at($day . ' ' . sprintf('%02d:%02d:00', 8 + intdiv($index * 4, $buys), mt_rand(0, 59)));
            $this->purchase();
        }

        for ($index = 0; $index < $spend; $index++) {
            $this->at($day . ' ' . sprintf('%02d:%02d:00', 9 + intdiv($index * 8, $spend), mt_rand(0, 59)));
            $this->expense();
        }

        for ($index = 0; $index < $sales && $this->state['documents'] < $targetDocuments; $index++) {
            $this->at($day . ' ' . sprintf('%02d:%02d:%02d', 8 + intdiv($index * 13, $sales), mt_rand(0, 59), mt_rand(0, 59)));
            $this->sale();
        }
    }

    private function sale(): void
    {
        $roll   = mt_rand(1, 1000);
        $branch = $this->pick([50, 30, 20]);
        $place  = $this->branches[$branch];

        if ($roll <= 30 && $this->state['recent'] !== []) {
            $this->saleReturn();

            return;
        }

        $credit   = $roll > 710;
        $customer = $credit || mt_rand(1, 100) <= 40 ? $this->customers[$this->weighted($this->customerWeights)] : -1;
        $lines    = $this->saleLines($place['location'], $branch);

        if ($lines === []) {
            return;
        }

        [$items, $taxes, $total] = $this->priced($lines);

        $payments = [];

        if (! $credit) {
            $type       = mt_rand(1, 100) <= 60 ? 'Sales.cash' : (mt_rand(1, 100) <= 70 ? 'Sales.debit' : 'Sales.credit');
            $payments[] = ['payment_type' => lang($type), 'payment_amount' => $total, 'cash_refund' => '0', 'cash_adjustment' => 0, 'reference_code' => null];
        } elseif (mt_rand(1, 100) <= 10) {
            $payments[] = ['payment_type' => lang('Sales.cash'), 'payment_amount' => bcmul($total, '0.3', 2), 'cash_refund' => '0', 'cash_adjustment' => 0, 'reference_code' => null];
        }

        $status    = COMPLETED;
        $salesTaxes = [[], $taxes];
        $started   = hrtime(true);
        $saleId    = $this->sales->save_value(NEW_ENTRY, $status, $items, $customer, $place['employee'], 'r6', null, null, null, $credit ? SALE_TYPE_INVOICE : SALE_TYPE_POS, $payments, null, $salesTaxes, true, null, null, ReturnDocument::CreditNote, null, $credit ? 30 : null);

        $this->saleTimings[] = (hrtime(true) - $started) / 1_000_000;

        if ($saleId <= 0) {
            $this->failed('sale', $saleId);

            return;
        }

        $this->state['documents']++;

        foreach ($lines as $line) {
            $this->items[$line['index']]['branchStock'][$branch] -= (int) $line['quantity'];
        }

        if ($credit) {
            $paid = $payments === [] ? '0' : $payments[0]['payment_amount'];
            $left = bcsub($total, $paid, 2);
            $mode = mt_rand(1, 100);
            $day  = (string) $this->state['day'];

            if ($mode <= 55) {
                $this->state['pending'][$this->afterDays($day, mt_rand(3, 60))][] = ['sale' => $saleId, 'amount' => $left, 'employee' => $place['employee'], 'branch' => $branch];
            } elseif ($mode <= 70) {
                $half = bcdiv($left, '2', 2);
                $this->state['pending'][$this->afterDays($day, mt_rand(3, 20))][] = ['sale' => $saleId, 'amount' => $half, 'employee' => $place['employee'], 'branch' => $branch];
                $this->state['pending'][$this->afterDays($day, mt_rand(21, 70))][] = ['sale' => $saleId, 'amount' => bcsub($left, $half, 2), 'employee' => $place['employee'], 'branch' => $branch];
            }

            if ($customer > 0) {
                $this->state['recent'][] = ['sale' => $saleId, 'customer' => $customer, 'branch' => $branch, 'lines' => array_slice($lines, 0, 3), 'day' => $day];
            }
        }

        if (count($this->state['recent']) > 400) {
            $this->state['recent'] = array_slice($this->state['recent'], -300);
        }
    }

    private function saleReturn(): void
    {
        $origin = $this->state['recent'][mt_rand(0, count($this->state['recent']) - 1)];
        $line   = $origin['lines'][0];
        $place  = $this->branches[$origin['branch']];

        $returned = [[...$line, 'quantity' => '-1', 'line' => 0]];
        [$items, $taxes] = $this->priced($returned);
        $status    = COMPLETED;
        $salesTaxes = [[], $taxes];
        $saleId    = $this->sales->save_value(NEW_ENTRY, $status, $items, (int) $origin['customer'], $place['employee'], 'r6 return', null, null, null, SALE_TYPE_RETURN, [], null, $salesTaxes, true, (int) $origin['sale'], null, ReturnDocument::CreditNote);

        if ($saleId <= 0) {
            $this->failed('return', $saleId);

            return;
        }

        $this->state['documents']++;
        $this->items[$line['index']]['branchStock'][$origin['branch']] += 1;
    }

    private function payDue(string $day): void
    {
        $due = $this->state['pending'][$day] ?? [];
        unset($this->state['pending'][$day]);

        foreach ($due as $index => $payment) {
            $this->at($day . ' ' . sprintf('%02d:%02d:00', 10 + intdiv($index * 6, max(1, count($due))), mt_rand(0, 59)));

            $type = mt_rand(1, 100) <= 55 ? lang('Sales.cash') : lang('Sales.debit');

            try {
                $paid = $this->sales->update($payment['sale'], ['comment' => 'r6 paid', 'payments' => [
                    ['payment_id' => NEW_ENTRY, 'payment_type' => $type, 'payment_amount' => $payment['amount'], 'cash_refund' => 0, 'cash_adjustment' => 0, 'employee_id' => $payment['employee'], 'reference_code' => null],
                ]]);
            } catch (\Throwable $failure) {
                $paid = false;
                $this->failed('payment ' . $failure::class, 0);
            }

            if ($paid) {
                $this->state['payments']++;
            } else {
                $this->failed('payment', 0);
            }
        }

        $dueBuys = $this->state['pendingBuys'][$day] ?? [];
        unset($this->state['pendingBuys'][$day]);

        foreach ($dueBuys as $index => $payment) {
            $this->at($day . ' ' . sprintf('%02d:%02d:00', 11 + intdiv($index * 5, max(1, count($dueBuys))), mt_rand(0, 59)));

            try {
                $paid = $this->purchases->update($payment['receiving'], ['comment' => 'r6 paid', 'payments' => [
                    ['payment_id' => NEW_ENTRY, 'payment_type' => lang('Sales.cash'), 'payment_amount' => $payment['amount'], 'employee_id' => $payment['employee'], 'payment_status' => 'completed', 'reference_code' => ''],
                ]]);
            } catch (\Throwable $failure) {
                $paid = false;
                $this->failed('purchase payment ' . $failure::class, 0);
            }

            $paid ? $this->state['payments']++ : $this->failed('purchase payment', 0);
        }
    }

    private function purchase(): void
    {
        $branch   = $this->pick([60, 25, 15]);
        $place    = $this->branches[$branch];
        $supplier = $this->suppliers[mt_rand(0, count($this->suppliers) - 1)];
        $count    = mt_rand(2, 9);
        $lines    = [];
        $total    = '0';
        $used     = [];

        for ($line = 0; $line < $count; $line++) {
            $index = $this->weightedItem($place['location'], $branch);

            if (isset($used[$index])) {
                continue;
            }

            $used[$index] = true;
            $item         = $this->items[$index];
            $quantity     = (string) mt_rand(20, 150);
            $price        = bcmul($item['cost'], (string) (0.97 + mt_rand(0, 6) / 100), 2);
            $total        = bcadd($total, bcmul($quantity, $price, 2), 2);
            $lines[]      = [
                'item_id' => $item['item_id'], 'line' => count($lines), 'description' => 'r6', 'serialnumber' => '', 'quantity' => $quantity, 'receiving_quantity' => '1',
                'discount' => '0', 'discount_type' => PERCENT, 'price' => $price, 'item_location' => $place['location'], 'tax_percent' => '0', 'index' => $index,
            ];
        }

        $dbLines = array_map(static function (array $line): array {
            unset($line['index']);

            return $line;
        }, $lines);

        $due    = mt_rand(1, 100) <= 70;
        $result = $this->purchases->save_value($dbLines, $supplier, $place['employee'], 'r6', 'REF-' . mt_rand(100000, 999999), $due ? lang('Sales.due') : lang('Sales.cash'), NEW_ENTRY, null, null, null, $due ? 30 : null);

        if ($result <= 0) {
            $this->failed('purchase', $result);

            return;
        }

        $this->state['purchases']++;

        foreach ($lines as $line) {
            $this->items[$line['index']]['branchStock'][$branch] += (int) $line['quantity'];
        }

        if ($due && mt_rand(1, 100) <= 70) {
            $this->state['pendingBuys'][$this->afterDays((string) $this->state['day'], mt_rand(10, 45))][] = ['receiving' => $result, 'amount' => $total, 'employee' => $place['employee']];
        }
    }

    private function expense(): void
    {
        $amount = (string) mt_rand(20, 2500);
        $tax    = mt_rand(1, 100) <= 50 ? bcmul($amount, '0.1304', 2) : '0';
        $data   = [
            'date'                => $this->state['day'] . ' ' . $this->clockTime,
            'supplier_id'         => null,
            'supplier_tax_code'   => '',
            'amount'              => $amount,
            'tax_amount'          => $tax,
            'payment_type'        => mt_rand(1, 100) <= 70 ? 'Cash' : 'Debit Card',
            'expense_category_id' => $this->categories[mt_rand(0, count($this->categories) - 1)],
            'description'         => 'R6 expense ' . mt_rand(1, 99999),
            'employee_id'         => 1,
            'deleted'             => 0,
        ];

        if ($this->expenses->save_value($data, NEW_ENTRY, 1)) {
            $this->state['expenses']++;
        } else {
            $this->failed('expense', 0);
        }
    }

    private function openStock(): void
    {
        $perReceiving = 100;

        foreach ($this->branches as $branch => $place) {
            for ($from = 0; $from < self::ITEMS; $from += $perReceiving) {
                $lines = [];

                for ($index = $from; $index < min(self::ITEMS, $from + $perReceiving); $index++) {
                    $lines[] = [
                        'item_id' => $this->items[$index]['item_id'], 'line' => count($lines), 'description' => 'opening', 'serialnumber' => '', 'quantity' => '300', 'receiving_quantity' => '1',
                        'discount' => '0', 'discount_type' => PERCENT, 'price' => $this->items[$index]['cost'], 'item_location' => $place['location'], 'tax_percent' => '0',
                    ];
                }

                $supplier = $this->suppliers[($from / $perReceiving) % count($this->suppliers)];
                $result   = $this->purchases->save_value($lines, $supplier, $place['employee'], 'opening stock', 'OPEN-' . $branch . '-' . $from, lang('Sales.cash'));

                if ($result <= 0) {
                    throw new RuntimeException('The opening purchase was refused: ' . $this->describe());
                }

                for ($index = $from; $index < min(self::ITEMS, $from + $perReceiving); $index++) {
                    $this->items[$index]['branchStock'][$branch] = 300;
                }
            }
        }
    }

    /**
     * @return list<array{item_id: int, price: string, cost: string, quantity: string, line: int, index: int, discount: string}>
     */
    private function saleLines(int $location, int $branch): array
    {
        $weights = [10, 15, 18, 17, 14, 10, 8, 5, 3];
        $count   = 1 + $this->pick($weights);
        $lines   = [];
        $used    = [];

        for ($line = 0; $line < $count; $line++) {
            $index = $this->weighted($this->itemWeights);

            if (isset($used[$index]) || $this->items[$index]['branchStock'][$branch] < 6) {
                continue;
            }

            $used[$index] = true;
            $quantity     = (string) (1 + $this->pick([55, 25, 12, 5, 3]));
            $discount     = mt_rand(1, 100) <= 10 ? (string) (5 * mt_rand(1, 2)) : '0';
            $lines[]      = ['item_id' => $this->items[$index]['item_id'], 'price' => $this->items[$index]['price'], 'cost' => $this->items[$index]['cost'], 'quantity' => $quantity, 'line' => count($lines), 'index' => $index, 'discount' => $discount, 'location' => $location];
        }

        return $lines;
    }

    /**
     * @param list<array<string, mixed>> $lines
     *
     * @return array{list<array<string, mixed>>, list<array<string, mixed>>, string}
     */
    private function priced(array $lines): array
    {
        $items = [];
        $taxes = [];
        $total = '0';

        foreach ($lines as $position => $line) {
            $net    = bcmul((string) $line['quantity'], (string) $line['price'], 4);
            $net    = bcmul($net, bcsub('1', bcdiv((string) $line['discount'], '100', 6), 6), 2);
            $tax    = bcmul($net, self::VAT, 2);
            $total  = bcadd($total, bcadd($net, $tax, 2), 2);
            $items[] = [
                'item_id' => $line['item_id'], 'line' => $position, 'description' => 'r6', 'serialnumber' => '', 'quantity' => $line['quantity'], 'discount' => $line['discount'],
                'discount_type' => PERCENT, 'cost_price' => $line['cost'], 'price' => $line['price'], 'item_location' => $line['location'], 'print_option' => 0,
            ];
            $taxes[] = [
                'item_id' => $line['item_id'], 'line' => $position, 'name' => 'VAT', 'percent' => '15.0000', 'tax_type' => 1, 'rounding_code' => 0, 'cascade_sequence' => 0,
                'item_tax_amount' => $tax, 'sales_tax_code_id' => null, 'tax_category_id' => null, 'jurisdiction_id' => null,
            ];
        }

        return [$items, $taxes, $total];
    }

    private function grantAdministrator(): void
    {
        $this->db->query('INSERT IGNORE INTO ' . $this->db->prefixTable('grants') . " (permission_id, person_id, menu_group) SELECT permission_id, 1, '--' FROM " . $this->db->prefixTable('permissions') . ' WHERE location_id IS NULL');
    }

    private function enableNumbering(): void
    {
        $numbering = new DocumentNumbering($this->db);

        foreach ([
            [DocumentType::SalesInvoice, 'INV-'], [DocumentType::CreditNote, 'CN-'], [DocumentType::CustomerPayment, 'RCT-'], [DocumentType::Customer, 'C-'],
            [DocumentType::Purchase, 'PUR-'], [DocumentType::SupplierPayment, 'SPAY-'], [DocumentType::Expense, 'EXP-'],
        ] as [$type, $prefix]) {
            $numbering->configure($type, ['enabled' => true, 'prefix' => $prefix, 'padding' => 6], 1);
        }
    }

    /**
     * @param list<int> $employees
     * @param list<int> $branchIds
     */
    private function createTreasuries(array $employees, array $branchIds): void
    {
        $treasuries = new TreasuryService($this->db);

        foreach ($branchIds as $index => $branchId) {
            $treasuryId = $treasuries->create(['name' => 'Cash drawer ' . ($index + 1), 'kind' => TreasuryService::CASH, 'branch_id' => $branchId], 1);

            $this->db->table('employees')->where('person_id', $employees[$index])->update(['default_treasury_id' => $treasuryId]);
        }

        $treasuries->create(['name' => 'Bank account', 'kind' => TreasuryService::BANK, 'branch_id' => 1, 'bank_name' => 'Test Bank', 'account_number' => 'SA0380000000608010167519'], 1);
    }

    private function createCategories(): void
    {
        foreach (['Rent', 'Electricity', 'Salaries', 'Transport', 'Maintenance', 'Marketing', 'Stationery', 'Telecom', 'Insurance', 'Fuel', 'Cleaning', 'Fees'] as $name) {
            $this->db->table('expense_categories')->insert(['category_name' => $name, 'category_description' => '', 'deleted' => 0]);
            $this->categories[] = (int) $this->db->insertID();
        }
    }

    private function createSuppliers(): void
    {
        $model = model(Supplier::class);

        for ($index = 1; $index <= self::SUPPLIERS; $index++) {
            $person   = ['first_name' => $this->firstName($index), 'last_name' => $this->lastName($index * 7), 'phone_number' => '05' . mt_rand(10000000, 99999999), 'email' => 'supplier' . $index . '@example.test', 'address_1' => '', 'address_2' => '', 'city' => 'Riyadh', 'state' => '', 'zip' => '', 'country' => 'SA', 'comments' => ''];
            $supplier = ['company_name' => 'Supplier Co ' . $index, 'agency_name' => '', 'account_number' => null, 'tax_id' => '3' . str_pad((string) $index, 14, '0', STR_PAD_LEFT), 'category' => 0];

            $model->save_supplier($person, $supplier, NEW_ENTRY) || throw new RuntimeException('supplier ' . $index);
            $this->suppliers[] = (int) $person['person_id'];
        }
    }

    private function createCustomers(): void
    {
        $model = model(Customer::class);

        for ($index = 1; $index <= self::CUSTOMERS; $index++) {
            $person   = ['first_name' => $this->firstName($index * 3), 'last_name' => $this->lastName($index), 'phone_number' => '05' . mt_rand(10000000, 99999999), 'email' => 'customer' . $index . '@example.test', 'address_1' => 'Street ' . $index, 'address_2' => '', 'city' => 'Jeddah', 'state' => '', 'zip' => '', 'country' => 'SA', 'comments' => ''];
            $customer = ['company_name' => $index % 5 === 0 ? 'Company ' . $index : null, 'account_number' => null, 'taxable' => 1, 'employee_id' => 1, 'consent' => 1];

            $model->save_customer($person, $customer, NEW_ENTRY) || throw new RuntimeException('customer ' . $index);
            $this->customers[] = (int) $person['person_id'];
        }
    }

    private function createItems(): void
    {
        $model = model(Item::class);
        $kinds = ['Olive oil', 'Basmati rice', 'Sugar', 'Green tea', 'Arabic coffee', 'Mineral water', 'Floor cleaner', 'Tissues', 'USB flash', 'Headset', 'Notebook', 'Pen', 'Detergent', 'Shampoo', 'Soap', 'Biscuits', 'Dates', 'Honey', 'Spices', 'Juice'];

        for ($index = 1; $index <= self::ITEMS; $index++) {
            $price = 5 + (($index * 37) % 480);
            $cost  = (int) round($price * (0.55 + (($index * 13) % 25) / 100));
            $data  = [
                'item_id' => null, 'name' => $kinds[$index % count($kinds)] . ' model ' . $index, 'description' => '', 'category' => 'Category ' . ($index % 30),
                'cost_price' => (string) $cost, 'unit_price' => (string) $price, 'reorder_level' => 5, 'item_number' => '628' . str_pad((string) $index, 10, '0', STR_PAD_LEFT),
                'allow_alt_description' => 0, 'is_serialized' => 0, 'stock_type' => HAS_STOCK, 'deleted' => 0,
            ];

            $model->save_value($data) || throw new RuntimeException('item ' . $index);

            $this->items[] = ['item_id' => (int) $data['item_id'], 'price' => $price . '.00', 'cost' => $cost . '.00', 'branchStock' => [0 => 0, 1 => 0, 2 => 0]];
        }

        $this->initWeights();
    }

    private function initWeights(): void
    {
        $this->itemWeights     = [];
        $this->customerWeights = [];

        $sum = 0.0;

        for ($index = 1; $index <= count($this->items); $index++) {
            $sum += 1 / ($index ** 0.6);
            $this->itemWeights[] = $sum;
        }

        $sum = 0.0;

        for ($index = 1; $index <= self::CUSTOMERS; $index++) {
            $sum += 1 / ($index ** 0.7);
            $this->customerWeights[] = $sum;
        }
    }

    private function weightedItem(int $location, int $branch): int
    {
        for ($try = 0; $try < 30; $try++) {
            $index = $this->weighted($this->itemWeights);

            if ($this->items[$index]['branchStock'][$branch] < 120) {
                return $index;
            }
        }

        return mt_rand(0, count($this->items) - 1);
    }

    /**
     * @param list<int|float> $weights
     */
    private function pick(array $weights): int
    {
        $roll = mt_rand() / mt_getrandmax() * array_sum($weights);

        foreach ($weights as $index => $weight) {
            $roll -= $weight;

            if ($roll <= 0) {
                return $index;
            }
        }

        return count($weights) - 1;
    }

    /**
     * @param list<float> $cumulative
     */
    private function weighted(array $cumulative): int
    {
        $roll = mt_rand() / mt_getrandmax() * $cumulative[count($cumulative) - 1];
        $low  = 0;
        $high = count($cumulative) - 1;

        while ($low < $high) {
            $middle = intdiv($low + $high, 2);

            if ($cumulative[$middle] < $roll) {
                $low = $middle + 1;
            } else {
                $high = $middle;
            }
        }

        return $low;
    }

    private function firstName(int $seed): string
    {
        $names = ['Ahmed', 'Mohammed', 'Khaled', 'Omar', 'Abdullah', 'Fahad', 'Salem', 'Nasser', 'Sara', 'Noura', 'Huda', 'Layla', 'Maha', 'Reem', 'Yousef', 'Ali', 'Hassan', 'Ibrahim', 'Majed', 'Turki'];

        return $names[$seed % count($names)];
    }

    private function lastName(int $seed): string
    {
        $names = ['Alharbi', 'Alotaibi', 'Alqahtani', 'Alghamdi', 'Alzahrani', 'Aldosari', 'Almutairi', 'Alshehri', 'Alsubaie', 'Almalki', 'Alanazi', 'Albalawi', 'Alharthi', 'Alsulami', 'Alhazmi'];

        return $names[$seed % count($names)] . ' ' . ($seed % 97);
    }

    private function afterDays(string $day, int $days): string
    {
        return (new DateTimeImmutable($day, $this->zone))->modify('+' . $days . ' day')->format('Y-m-d');
    }

    private function at(string $moment): void
    {
        $this->db->resetTransStatus();
        $this->clockTime = substr($moment, 11, 8);
        R6Clock::set((new DateTimeImmutable($moment, $this->zone))->getTimestamp());
        R6Clock::setDatabase($this->db);
    }

    private function failed(string $what, int $code): void
    {
        $key = $what . ' ' . $this->describe();

        $this->failures[$key] = ($this->failures[$key] ?? 0) + 1;
    }

    private function describe(): string
    {
        $failure = $this->sales->accountingFailure() ?? $this->purchases->accountingFailure() ?? $this->expenses->accountingFailure();

        $error   = $this->db->error();
        $lastSql = ($error['code'] ?? 0) !== 0 ? ' [db ' . $error['code'] . ': ' . substr((string) $error['message'], 0, 100) . ']' : '';

        return $failure === null ? 'unknown' . $lastSql : substr($failure::class . ':' . $failure->getMessage(), 0, 160) . $lastSql;
    }

    private function saveState(): void
    {
        $table = $this->db->prefixTable('r6_generator_state');

        $this->db->query("CREATE TABLE IF NOT EXISTS {$table} (id TINYINT UNSIGNED NOT NULL PRIMARY KEY, state LONGTEXT NOT NULL)");
        $this->db->query("REPLACE INTO {$table} (id, state) VALUES (1, ?)", [json_encode($this->state, JSON_THROW_ON_ERROR)]);
    }

    private function loadState(): void
    {
        $table = $this->db->prefixTable('r6_generator_state');

        $row = $this->db->query("SHOW TABLES LIKE '{$table}'")->getRow() === null ? null : $this->db->query("SELECT state FROM {$table} WHERE id = 1")->getRow();

        if ($row === null) {
            $this->state = $this->rebuiltState();
            $this->saveState();
        } else {
            $this->state = json_decode((string) $row->state, true, 512, JSON_THROW_ON_ERROR);
        }

        $this->branches = [];

        foreach ($this->state['branches'] as $index => $place) {
            $this->branches[(int) $index] = $place;
        }

        $this->reloadCatalogue();
    }

    /**
     * The first version kept its state in a TEXT column that cut it at 64 KB: what can still be read of the payments it had scheduled is
     * kept, the rest is taken from the data (the days, the branches, the sales a return can come from).
     *
     * @return array<string, mixed>
     */
    private function rebuiltState(): array
    {
        $old = $this->db->table('app_config')->where('key', self::STATE_KEY)->get()->getRow();

        if ($old === null) {
            throw new RuntimeException('Run the setup first.');
        }

        $pending = [];

        if (preg_match_all('/"(\d{4}-\d{2}-\d{2})":\[((?:\{[^{}]*\},?)+)\]/', (string) $old->value, $matches, PREG_SET_ORDER) > 0) {
            foreach ($matches as $match) {
                $entries = json_decode('[' . rtrim($match[2], ',') . ']', true);

                if (is_array($entries)) {
                    $pending[$match[1]] = $entries;
                }
            }
        }

        $last   = $this->db->query('SELECT DATE(MAX(sale_time)) AS day FROM ' . $this->db->prefixTable('sales'))->getRow()->day;
        $places = $this->db->query('SELECT b.branch_id AS branch, l.location_id AS location, e.person_id AS employee FROM ' . $this->db->prefixTable('branches') . ' b JOIN ' . $this->db->prefixTable('stock_locations') . ' l ON l.branch_id = b.branch_id JOIN ' . $this->db->prefixTable('employees') . ' e ON e.default_treasury_id IN (SELECT treasury_id FROM ' . $this->db->prefixTable('treasuries') . ' t WHERE t.branch_id = b.branch_id) ORDER BY b.branch_id')->getResultArray();

        $branches = [];

        foreach ($places as $index => $place) {
            $branches[$index] = ['employee' => (int) $place['employee'], 'location' => (int) $place['location'], 'branch' => (int) $place['branch']];
        }

        $firstItem = (int) $this->db->table('items')->selectMin('item_id', 'first')->get()->getRow()->first;
        $recent    = [];

        $invoices = $this->db->query('SELECT sale_id, customer_id, branch_id, DATE(sale_time) AS day FROM ' . $this->db->prefixTable('sales') . ' WHERE sale_type = 1 AND customer_id IS NOT NULL AND sale_status = 0 ORDER BY sale_id DESC LIMIT 300')->getResult();

        foreach (array_reverse($invoices) as $invoice) {
            $lines = [];

            foreach ($this->db->query('SELECT item_id, item_unit_price AS price, item_cost_price AS cost, quantity_purchased AS quantity, line, discount, item_location AS location FROM ' . $this->db->prefixTable('sales_items') . ' WHERE sale_id = ? ORDER BY line LIMIT 3', [$invoice->sale_id])->getResultArray() as $line) {
                $lines[] = ['item_id' => (int) $line['item_id'], 'price' => bcadd((string) $line['price'], '0', 2), 'cost' => bcadd((string) $line['cost'], '0', 2), 'quantity' => (string) (int) $line['quantity'], 'line' => (int) $line['line'], 'index' => (int) $line['item_id'] - $firstItem, 'discount' => (string) (int) $line['discount'], 'location' => (int) $line['location']];
            }

            $branchIndex = 0;

            foreach ($branches as $index => $place) {
                if ($place['branch'] === (int) $invoice->branch_id) {
                    $branchIndex = $index;
                }
            }

            $recent[] = ['sale' => (int) $invoice->sale_id, 'customer' => (int) $invoice->customer_id, 'branch' => $branchIndex, 'lines' => $lines, 'day' => $invoice->day];
        }

        return [
            'day'         => (new DateTimeImmutable($last, $this->zone))->modify('+1 day')->format('Y-m-d'),
            'documents'   => (int) $this->db->table('sales')->countAllResults(),
            'purchases'   => (int) $this->db->table('receivings')->countAllResults(),
            'expenses'    => (int) $this->db->table('expenses')->countAllResults(),
            'payments'    => (int) $this->db->table('sales_payments')->countAllResults(),
            'pending'     => $pending,
            'pendingBuys' => [],
            'recent'      => $recent,
            'branches'    => $branches,
            'seed'        => 6,
            'opened'      => true,
        ];
    }

    private function reloadCatalogue(): void
    {
        $this->categories = array_map('intval', array_column($this->db->table('expense_categories')->select('expense_category_id')->orderBy('expense_category_id')->get()->getResultArray(), 'expense_category_id'));
        $this->suppliers  = array_map('intval', array_column($this->db->table('suppliers')->select('person_id')->orderBy('person_id')->get()->getResultArray(), 'person_id'));
        $this->customers  = array_map('intval', array_column($this->db->table('customers')->select('person_id')->orderBy('person_id')->get()->getResultArray(), 'person_id'));
        $this->items      = [];

        $quantities = [];

        foreach ($this->db->table('item_quantities')->select('item_id, location_id, quantity')->get()->getResult() as $row) {
            $quantities[(int) $row->item_id][(int) $row->location_id] = (int) $row->quantity;
        }

        foreach ($this->db->table('items')->select('item_id, unit_price, cost_price')->where('deleted', 0)->orderBy('item_id')->get()->getResult() as $row) {
            $stock = [];

            foreach ($this->branches as $branch => $place) {
                $stock[$branch] = $quantities[(int) $row->item_id][$place['location']] ?? 0;
            }

            $this->items[] = ['item_id' => (int) $row->item_id, 'price' => bcadd((string) $row->unit_price, '0', 2), 'cost' => bcadd((string) $row->cost_price, '0', 2), 'branchStock' => $stock];
        }

        $this->initWeights();
    }
}
