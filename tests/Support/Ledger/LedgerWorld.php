<?php

namespace Tests\Support\Ledger;

use App\Libraries\Accounting\AccountingGuard;
use App\Libraries\Accounting\ChequeBooks;
use App\Libraries\Accounting\Currencies;
use App\Libraries\Accounting\CustomerOpening;
use App\Libraries\Accounting\DefaultChart;
use App\Libraries\Accounting\EntityAccountService;
use App\Libraries\Accounting\SupplierAdvances;
use App\Libraries\Accounting\TreasuryService;
use App\Models\Employee;
use App\Models\Item;
use CodeIgniter\Database\BaseConnection;
use Config\Database;
use RuntimeException;

/**
 * The master data of one seed: the branches and their stock locations, the people who work, the
 * customers and suppliers, with and without a balance they start from, and the items with the taxes they
 * carry. It is built through the same models and services the screens use.
 */
final class LedgerWorld
{
    public const TAXES = [
        'none'   => [],
        'vat'    => ['VAT15' => '15'],
        'two'    => ['VAT15' => '15', 'SVC5' => '5'],
        'oddvat' => ['VAT7' => '7.5'],
        'inc'    => ['VATI15' => '15'],
        'inc2'   => ['VATI15' => '15', 'SVCI2' => '2.5'],
        'mixed'  => ['VATI15' => '15', 'SVC5' => '5'],
    ];

    public int $boss;

    public int $clerk;

    /** @var list<int> */
    public array $branches = [1];

    /** @var array<int, int> The branch of each stock location. */
    public array $locations = [1 => 1];

    /** @var array<int, array{taxable: bool, limit: string, opening: string|null}> */
    public array $customers = [];

    /** @var array<int, array{opening: string|null}> */
    public array $suppliers = [];

    /** @var array<int, array{stock: bool, price: string, cost: string, taxes: string}> */
    public array $items = [];

    /** @var array<int, int> The branch of each treasury. */
    public array $treasuries = [];

    public int $bankTreasury = 0;

    public int $chequeBook = 0;

    public int $expenseCategory = 0;

    public int $revenueAccount = 0;

    public bool $foreign = false;

    private BaseConnection $db;

    public function __construct(private readonly int $seed)
    {
        $this->db = Database::connect();
    }

    public function build(): void
    {
        $random = new LedgerRandom($this->seed, 0);

        $this->settings($random);
        $this->people();
        $this->branchesAndLocations($random);
        $this->taxes();
        $this->customersAndSuppliers($random);
        $this->treasuryBooks();
        $this->dollars();
        $this->products($random);
    }

    public function setConfig(string $key, string $value): void
    {
        $this->db->table('app_config')->replace(['key' => $key, 'value' => $value]);
    }

    private function settings(LedgerRandom $random): void
    {
        $this->setConfig('accounting_base_currency', 'SAR');
        $this->setConfig('accounting_start_date', '2020-01-01');
        $this->setConfig('accounting_enabled', '1');
        $this->setConfig('accounting_customer_opening', '1');
        $this->setConfig('stock_transfer_permit_enabled', '1');
        $this->setConfig('negative_stock_option', $random->chance(50) ? 'refuse' : 'allow');

        $this->db->query('DELETE FROM ' . $this->db->prefixTable('accounting_fiscal_years') . ' WHERE company_id = 1');

        $rows = [];

        for ($year = 2020; $year <= 2035; $year++) {
            $rows[] = ['company_id' => 1, 'name' => (string) $year, 'start_date' => "{$year}-01-01", 'end_date' => "{$year}-12-31"];
        }

        $this->db->table('accounting_fiscal_years')->ignore(true)->insertBatch($rows);
    }

    private function people(): void
    {
        $this->boss  = $this->employee('Boss', true);
        $this->clerk = $this->employee('Clerk', false);
    }

    private function employee(string $name, bool $everything): int
    {
        $personData = ['first_name' => $name, 'last_name' => 'Ledger', 'email' => strtolower($name) . '.ledger@test.com', 'phone_number' => ''];
        $employee   = ['username' => strtolower($name) . '_ledger', 'password' => password_hash('password123', PASSWORD_DEFAULT), 'hash_version' => 2, 'language_code' => 'en', 'language' => 'english'];

        $noGrants = [];

        if (! model(Employee::class)->save_employee($personData, $employee, $noGrants, NEW_ENTRY)) {
            throw new RuntimeException('The employee could not be saved.');
        }

        $personId = (int) $personData['person_id'];

        $keep = $everything ? null : [AccountingGuard::RETURN_OVERRIDE, AccountingGuard::PRICE_OVERRIDE, AccountingGuard::CREDIT_OVERRIDE];

        foreach ($this->db->table('permissions')->select('permission_id')->get()->getResultArray() as $row) {
            if ($keep !== null && in_array($row['permission_id'], $keep, true)) {
                continue;
            }

            $this->db->table('grants')->ignore(true)->insert(['permission_id' => $row['permission_id'], 'person_id' => $personId, 'menu_group' => 'home']);
        }

        $this->db->table('grants')->ignore(true)->insert(['permission_id' => 'org_all_branches', 'person_id' => $personId, 'menu_group' => 'home']);

        return $personId;
    }

    private function branchesAndLocations(LedgerRandom $random): void
    {
        $extraBranches = $this->seed % 3;
        $entities      = new EntityAccountService();

        $entities->ensure(EntityAccountService::STOCK_LOCATION, 1, 'stock');

        for ($index = 0; $index < $extraBranches; $index++) {
            $this->db->table('branches')->insert(['company_id' => 1, 'code' => 'B' . ($index + 2), 'name' => 'Branch ' . ($index + 2), 'active' => 1]);
            $this->branches[] = (int) $this->db->insertID();
        }

        foreach ($this->branches as $branchId) {
            $count = $branchId === 1 ? $random->int(0, 1) : $random->int(1, 2);

            for ($index = 0; $index < $count; $index++) {
                $this->location($branchId, 'Store ' . $branchId . '.' . $index);
            }
        }
    }

    private function location(int $branchId, string $name): int
    {
        $this->db->table('stock_locations')->insert(['location_name' => $name, 'deleted' => 0, 'branch_id' => $branchId]);
        $locationId = (int) $this->db->insertID();

        (new EntityAccountService())->ensure(EntityAccountService::STOCK_LOCATION, $locationId, $name);

        $permission = 'items_' . $name;
        $this->db->table('permissions')->ignore(true)->insert(['permission_id' => $permission, 'module_id' => 'items', 'location_id' => $locationId]);

        foreach ([$this->boss, $this->clerk] as $personId) {
            $this->db->table('grants')->ignore(true)->insert(['permission_id' => $permission, 'person_id' => $personId, 'menu_group' => 'home']);
        }

        return $this->locations[$locationId] = $branchId;
    }

    private function taxes(): void
    {
        $definitions = ['VAT15' => 0, 'SVC5' => 0, 'VAT7' => 0, 'VATI15' => 1, 'SVCI2' => 1];

        foreach ($definitions as $name => $included) {
            $this->db->table('tax_definitions')->insert(['tax_name' => $name, 'tax_included' => $included]);
        }
    }

    private function customersAndSuppliers(LedgerRandom $random): void
    {
        $entities = new EntityAccountService();
        $opening  = new CustomerOpening();
        $advances = new SupplierAdvances();
        $day      = date('Y-m-d', strtotime('-40 days'));

        for ($index = 1; $index <= 5; $index++) {
            $personId = $this->person('Customer' . $index, 'Ledger');
            $taxable  = $index !== 5;
            $limit    = $index === 4 ? '1500.0000' : '0.0000';

            $this->db->table('customers')->insert(['person_id' => $personId, 'company_name' => 'Customer ' . $index, 'employee_id' => 1, 'taxable' => $taxable ? 1 : 0, 'credit_limit' => $limit, 'credit_period_days' => $index === 4 ? 30 : 0]);
            $entities->ensure(EntityAccountService::CUSTOMER, $personId, 'Customer ' . $index);

            $amount = null;

            if ($index === 2 || $index === 3) {
                $amount = $random->money(500000);
                $opening->create($personId, $index === 2 ? CustomerOpening::OWES_US : CustomerOpening::WE_OWE, $amount, $day, '', $this->boss);
            }

            $this->customers[$personId] = ['taxable' => $taxable, 'limit' => $limit, 'opening' => $amount === null ? null : ($index === 2 ? '+' : '-') . $amount];
        }

        for ($index = 1; $index <= 3; $index++) {
            $personId = $this->person('Supplier' . $index, 'Ledger');

            $this->db->table('suppliers')->insert(['person_id' => $personId, 'company_name' => 'Supplier ' . $index, 'agency_name' => '', 'category' => 0]);
            $entities->ensure(EntityAccountService::SUPPLIER, $personId, 'Supplier ' . $index);

            $amount = null;

            if ($index === 2) {
                $amount = $random->money(300000);
                $advances->openingBalance($personId, SupplierAdvances::WE_OWE, $amount, $day, '', $this->boss);
            }

            $this->suppliers[$personId] = ['opening' => $amount];
        }
    }

    private function treasuryBooks(): void
    {
        $treasuries = new TreasuryService();
        $main       = $treasuries->ensureMain($this->boss);

        $this->treasuries[$main] = 1;

        foreach ($this->branches as $branchId) {
            $id = $treasuries->create(['name' => 'Drawer B' . $branchId, 'kind' => 'cash', 'branch_id' => $branchId], $this->boss);

            $this->treasuries[$id] = $branchId;
        }

        $this->bankTreasury = $treasuries->create(['name' => 'Ledger bank', 'kind' => 'bank', 'bank_code' => 'SA_RIYAD', 'account_number' => 'SA' . str_pad((string) (1000000 + $this->seed), 18, '0', STR_PAD_LEFT), 'branch_id' => 1], $this->boss);
        $this->chequeBook   = (new ChequeBooks())->create(['treasury_id' => $this->bankTreasury, 'name' => 'Ledger cheque book', 'first_serial' => '1', 'last_serial' => '999999'], $this->boss);

        $this->db->table('expense_categories')->insert(['category_name' => 'Ledger expenses', 'category_description' => '', 'deleted' => 0, 'account_id' => null]);
        $this->expenseCategory = (int) $this->db->insertID();

        $this->revenueAccount = (int) $this->db->table('accounting_accounts')->where('system_key', 'revenues_default')->get()->getRow()->account_id;
    }

    private function dollars(): void
    {
        if ($this->seed % 2 !== 0) {
            return;
        }

        $currencies = new Currencies();

        $currencies->setActive('USD', true);
        $currencies->rates()->add(['currency_code' => 'USD', 'rate' => '3.75', 'effective_date' => date('Y-m-d', strtotime('-60 days'))]);
        $currencies->rates()->add(['currency_code' => 'USD', 'rate' => '3.60', 'effective_date' => date('Y-m-d', strtotime('-5 days'))]);

        $this->foreign = true;
    }

    private function person(string $first, string $last): int
    {
        $this->db->table('people')->insert(['first_name' => $first, 'last_name' => $last, 'phone_number' => '', 'email' => '', 'address_1' => '', 'address_2' => '', 'city' => '', 'state' => '', 'zip' => '', 'country' => '', 'comments' => '']);

        return (int) $this->db->insertID();
    }

    private function products(LedgerRandom $random): void
    {
        $model = model(Item::class);
        $count = 10;

        for ($index = 1; $index <= $count; $index++) {
            $stock = $index <= 7;
            $price = $random->price();
            $cost  = $stock ? $this->cost($random, $price) : '0.00';
            $taxes = $random->pick(array_keys(self::TAXES));

            $data = [
                'item_id'               => null,
                'name'                  => 'Item ' . $index,
                'description'           => 'Item ' . $index,
                'category'              => 'Ledger',
                'cost_price'            => $cost,
                'unit_price'            => $price,
                'reorder_level'         => 0,
                'item_number'           => 'LEDGER-' . $index,
                'allow_alt_description' => 0,
                'is_serialized'         => 0,
                'stock_type'            => $stock ? HAS_STOCK : HAS_NO_STOCK,
                'deleted'               => 0,
            ];

            $model->save_value($data);
            $itemId = (int) $data['item_id'];

            foreach (self::TAXES[$taxes] as $name => $percent) {
                $this->db->table('items_taxes')->insert(['item_id' => $itemId, 'name' => $name, 'percent' => $percent]);
            }

            $this->items[$itemId] = ['stock' => $stock, 'price' => $price, 'cost' => $cost, 'taxes' => $taxes];
        }
    }

    private function cost(LedgerRandom $random, string $price): string
    {
        $cost = bcmul($price, (string) ($random->int(20, 90) / 100), 2);

        return bccomp($cost, '0', 2) === 0 ? '0.01' : $cost;
    }
}
