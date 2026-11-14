<?php

declare(strict_types=1);

namespace Tools\Dev\LegacyRehearsal;

use DateTimeImmutable;
use DateTimeZone;
use mysqli;
use RuntimeException;

final class LegacyDataGenerator
{
    private const SAFE_PREFIX = 'ospos_test_';
    private const FIRST_ACCOUNTING_VERSION = 20261021000000;
    private const FLUSH_EVERY = 1500;
    private const INSERT_CHUNK = 400;
    private const SHOP_ZONE = 'America/New_York';
    private const TAX_SWITCH_DATE = '2025-07-01';
    private const NUMBER_FORMAT_SWITCH_DATE = '2025-03-01';

    private const ARABIC_FIRST = ['محمد', 'أحمد', 'علي', 'عمر', 'خالد', 'يوسف', 'إبراهيم', 'فاطمة', 'عائشة', 'مريم', 'سارة', 'نورة', 'هدى', 'ليلى', 'حسن', 'سلمان', 'ماجد', 'فهد', 'ريم', 'دانة'];
    private const ARABIC_LAST = ['العتيبي', 'القحطاني', 'الغامدي', 'الشمري', 'الدوسري', 'الحربي', 'الزهراني', 'المطيري', 'السبيعي', 'الرشيدي', 'بن سالم', 'النجار', 'الخطيب', 'الحداد', 'العلي'];
    private const ENGLISH_FIRST = ['John', 'Mary', 'James', 'Linda', 'Robert', 'Susan', 'Michael', 'Karen', 'David', 'Nancy', 'Peter', 'Helen', 'Omar', 'Layla', 'Sam', 'Nora', 'Tariq', 'Dina', 'Ali', 'Zaid'];
    private const ENGLISH_LAST = ['Smith', 'Johnson', 'Brown', 'Taylor', 'Wilson', 'Davis', 'Clark', 'Lewis', 'Walker', 'Hall', 'Young', 'King', 'Wright', 'Scott', 'Green'];
    private const CITIES = ['الرياض', 'جدة', 'الدمام', 'مكة', 'المدينة', 'Riyadh', 'Jeddah', 'Khobar', 'Dubai', 'Cairo'];
    private const COMPANY_WORDS = ['Noor', 'Falcon', 'Delta', 'Zahra', 'Gulf', 'Star', 'Palm', 'Oasis', 'Atlas', 'Summit', 'الأمل', 'النخبة', 'الريادة', 'الشرق'];
    private const COMPANY_KINDS = ['Trading', 'Supplies', 'Foods', 'Electronics', 'Distribution', 'Stores', 'للتجارة', 'للتوريدات', 'للمواد الغذائية'];
    private const CATEGORIES = ['Beverages', 'Snacks', 'Dairy', 'Bakery', 'Frozen', 'Cleaning', 'Personal Care', 'Stationery', 'Electronics', 'Phones', 'Accessories', 'Toys', 'Clothing', 'Shoes', 'Kitchen', 'Garden', 'Auto', 'Pet', 'Health', 'Books', 'مشروبات', 'حلويات', 'ادوات منزلية', 'عطور', ''];
    private const ITEM_WORDS = ['Classic', 'Premium', 'Mini', 'Family', 'Deluxe', 'Fresh', 'Gold', 'Basic', 'Smart', 'Eco', 'ممتاز', 'عائلي', 'اقتصادي', 'فاخر'];
    private const ITEM_NOUNS = ['Cola', 'Juice', 'Chips', 'Milk', 'Bread', 'Soap', 'Pen', 'Notebook', 'Charger', 'Case', 'Toy', 'Shirt', 'Pan', 'Lamp', 'Cable', 'Battery', 'Shampoo', 'Cereal', 'Water', 'Coffee', 'ماء', 'عصير', 'شاي', 'صابون', 'كتاب'];
    private const EXPENSE_CATEGORIES = ['Rent', 'Utilities', 'Salaries', 'Transport', 'Maintenance', 'Marketing', 'Office supplies', 'Insurance', 'Bank charges', 'Miscellaneous', 'ايجار', 'Old category'];
    private const EXPENSE_TEXTS = ['Monthly payment', 'Invoice settlement', 'Repair', 'Fuel', 'Cleaning service', 'Ads', 'Stationery', 'Courier', 'دفعة شهرية', 'صيانة'];

    private const PAYMENT_LABELS = [
        'en' => ['cash' => 'Cash', 'debit' => 'Debit Card', 'credit' => 'Credit Card', 'check' => 'Check', 'due' => 'Due', 'giftcard' => 'Gift Card', 'rewards' => 'Reward Points'],
        'ar-EG' => ['cash' => 'نقدى', 'debit' => 'بطاقة خصم', 'credit' => 'بطاقة إئتمانية', 'check' => 'شيك', 'due' => 'مستحق', 'giftcard' => 'بطاقة هدية', 'rewards' => 'نقاط المكافئة'],
    ];

    private DateTimeZone $zone;
    private int $fromTs;
    private int $toTs;
    private string $prefix;

    /** @var array<string, list<list<mixed>>> */
    private array $buffers = [];

    /** @var array<string, list<string>> */
    private array $bufferColumns = [];

    /** @var array<int, array<string, mixed>> */
    private array $items = [];

    /** @var list<int> */
    private array $activeItems = [];

    /** @var array<int, array<int, float>> */
    private array $stock = [];

    /** @var array<int, list<int>> */
    private array $supplierItems = [];

    /** @var list<array{id: int, deleted: bool, taxable: bool, packageId: ?int, discount: float, discountType: int}> */
    private array $customers = [];

    /** @var list<int> */
    private array $suppliers = [];

    /** @var list<int> */
    private array $expenseSuppliers = [];

    /** @var array<int, array<string, mixed>> */
    private array $employees = [];

    /** @var list<int> */
    private array $cashiers = [];

    /** @var list<int> */
    private array $locations = [1, 2, 3];

    /** @var array<int, array{number: string, balance: float}> */
    private array $giftcards = [];

    /** @var array<int, float> */
    private array $packagePercent = [1 => 0.0, 2 => 10.0, 3 => 20.0, 4 => 30.0, 5 => 50.0];

    /** @var array<int, float> */
    private array $customerPoints = [];

    /** @var array<string, array<string, float>> */
    private array $dayTotals = [];

    /** @var list<string> */
    private array $receivingReferences = [];

    private int $nextSaleId = 1;
    private int $nextReceivingId = 1;
    private int $nextItemId = 1;
    private int $invoiceSequence = 0;
    private int $quoteSequence = 0;
    private int $workOrderSequence = 0;
    private int $maxReal = 0;
    private int $saleCounter = 0;

    /** @var array<string, int> */
    private array $stats = [];

    /**
     * @param array<string, mixed> $options
     */
    public function __construct(private mysqli $db, private array $options)
    {
        $this->prefix = (string) $options['prefix'];
        $this->zone = new DateTimeZone(self::SHOP_ZONE);
        $this->fromTs = (new DateTimeImmutable((string) $options['from'] . ' 00:00:00', $this->zone))->getTimestamp();
        $this->toTs = (new DateTimeImmutable((string) $options['to'] . ' 23:59:59', $this->zone))->getTimestamp();
        mt_srand((int) $options['seed']);
    }

    /**
     * @return array<string, int>
     */
    public function run(): array
    {
        $this->assertSafeTarget();
        $this->db->query('SET SESSION time_zone = ' . $this->quote(self::SHOP_ZONE));
        $this->db->query('SET autocommit = 0');

        $this->seedStaff();
        $this->seedLocations();
        $this->seedPeople();
        $this->seedExpenseCategories();
        $this->seedGiftcards();
        $this->seedItems();
        $this->commit();

        $this->processTimeline();
        $this->flushAll();
        $this->seedQuantities();
        $this->seedExpenses();
        $this->seedCashups();
        $this->seedOrphans();
        $this->finalise();
        $this->commit();
        $this->analyse();

        return $this->stats;
    }

    private function assertSafeTarget(): void
    {
        $name = (string) $this->db->query('SELECT DATABASE() AS name')->fetch_assoc()['name'];

        if (! str_starts_with($name, self::SAFE_PREFIX)) {
            throw new RuntimeException("Refusing to run: database '{$name}' does not start with '" . self::SAFE_PREFIX . "'.");
        }

        if ($this->tableExists('accounting_journals') || $this->tableExists('accounting_accounts')) {
            throw new RuntimeException('Refusing to run: the accounting tables exist, this is not a legacy schema.');
        }

        foreach (['sales', 'items', 'customers', 'receivings'] as $table) {
            $rows = (int) $this->db->query("SELECT COUNT(*) AS c FROM `{$this->prefix}{$table}`")->fetch_assoc()['c'];

            if ($rows > 0) {
                throw new RuntimeException("Refusing to run: `{$this->prefix}{$table}` already has {$rows} row(s); start from the migrated empty legacy schema.");
            }
        }

        $latest = (int) $this->db->query("SELECT MAX(version) AS v FROM `{$this->prefix}migrations`")->fetch_assoc()['v'];

        if ($latest >= self::FIRST_ACCOUNTING_VERSION) {
            throw new RuntimeException("Refusing to run: migration {$latest} is from the accounting layer.");
        }
    }

    private function tableExists(string $table): bool
    {
        $found = $this->db->query(
            "SELECT COUNT(*) AS c FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = '{$this->prefix}{$table}'",
        )->fetch_assoc();

        return (int) $found['c'] > 0;
    }

    private function int(int $min, int $max): int
    {
        return mt_rand($min, $max);
    }

    private function unit(): float
    {
        return mt_rand() / mt_getrandmax();
    }

    private function chance(float $percent): bool
    {
        return $this->unit() * 100 < $percent;
    }

    /**
     * @param list<mixed> $values
     */
    private function pick(array $values): mixed
    {
        return $values[mt_rand(0, count($values) - 1)];
    }

    /**
     * @param array<string, int|float> $weights
     */
    private function weighted(array $weights): string
    {
        $total = array_sum($weights);
        $roll = $this->unit() * $total;

        foreach ($weights as $key => $weight) {
            $roll -= $weight;

            if ($roll <= 0) {
                return (string) $key;
            }
        }

        return (string) array_key_last($weights);
    }

    private function skewIndex(int $count, float $power = 2.0): int
    {
        return min($count - 1, (int) floor($count * ($this->unit() ** $power)));
    }

    private function money(float $value): string
    {
        return number_format($value, 2, '.', '');
    }

    private function truncate4(float $value): float
    {
        $scaled = round($value * 10000, 6);

        return ($scaled >= 0 ? floor($scaled) : ceil($scaled)) / 10000;
    }

    private function roundHalfUp(float $value, int $decimals = 2): float
    {
        return round($value, $decimals, PHP_ROUND_HALF_UP);
    }

    private function localTime(int $timestamp): string
    {
        return (new DateTimeImmutable('@' . $timestamp))->setTimezone($this->zone)->format('Y-m-d H:i:s');
    }

    private function businessTimestamp(float $skew = 0.55): int
    {
        $span = $this->toTs - $this->fromTs;
        $day = (int) floor(($span / 86400) * ($this->unit() ** $skew));
        $base = (new DateTimeImmutable('@' . ($this->fromTs + $day * 86400)))->setTimezone($this->zone);
        $hour = $this->int(8, 21);

        return $base->setTime($hour, $this->int(0, 59), $this->int(0, 59))->getTimestamp();
    }

    private function quote(mixed $value): string
    {
        if ($value === null) {
            return 'NULL';
        }

        if (is_int($value)) {
            return (string) $value;
        }

        if (is_float($value)) {
            return rtrim(rtrim(number_format($value, 6, '.', ''), '0'), '.') ?: '0';
        }

        if (is_bool($value)) {
            return $value ? '1' : '0';
        }

        return "'" . $this->db->real_escape_string((string) $value) . "'";
    }

    /**
     * @param list<string> $columns
     * @param list<list<mixed>> $rows
     */
    private function insert(string $table, array $columns, array $rows, string $verb = 'INSERT'): void
    {
        if ($rows === []) {
            return;
        }

        $head = "{$verb} INTO `{$this->prefix}{$table}` (`" . implode('`, `', $columns) . '`) VALUES ';

        foreach (array_chunk($rows, self::INSERT_CHUNK) as $chunk) {
            $values = [];

            foreach ($chunk as $row) {
                $values[] = '(' . implode(', ', array_map(fn (mixed $value): string => $this->quote($value), $row)) . ')';
            }

            $this->db->query($head . implode(', ', $values));
        }

        $this->stats[$table] = ($this->stats[$table] ?? 0) + count($rows);
    }

    /**
     * @param list<string> $columns
     * @param list<mixed> $row
     */
    private function buffer(string $table, array $columns, array $row): void
    {
        $this->bufferColumns[$table] = $columns;
        $this->buffers[$table][] = $row;
    }

    private function flushAll(): void
    {
        $order = ['items', 'items_taxes', 'sales', 'receivings', 'sales_items', 'sales_items_taxes', 'sales_taxes', 'sales_payments', 'sales_reward_points', 'customers_points', 'receivings_items', 'inventory'];

        foreach ($order as $table) {
            if (isset($this->buffers[$table])) {
                $this->insert($table, $this->bufferColumns[$table], $this->buffers[$table]);
                unset($this->buffers[$table]);
            }
        }

        $this->commit();
    }

    private function commit(): void
    {
        $this->db->commit();
    }

    private function languageOf(int $employeeId): string
    {
        return (string) ($this->employees[$employeeId]['language'] ?? 'en');
    }

    private function label(int $employeeId, string $type): string
    {
        return self::PAYMENT_LABELS[$this->languageOf($employeeId)][$type];
    }

    private function seedStaff(): void
    {
        $hash = crypt('rehearsal-only', '$2y$10$' . 'abcdefghijklmnopqrstuu');
        $staff = [
            2 => ['manager1', 'Mona', 'Manager', 'en'],
            3 => ['cashier1', 'Samir', 'Cashier', 'ar-EG'],
            4 => ['cashier2', 'Dina', 'Cashier', 'en'],
            5 => ['cashier3', 'Waleed', 'Cashier', 'en'],
            6 => ['stock1', 'Hana', 'Stock', 'en'],
        ];
        $this->employees[1] = ['language' => 'en'];
        $people = [];
        $accounts = [];

        foreach ($staff as $id => [$username, $first, $last, $language]) {
            $this->employees[$id] = ['language' => $language];
            $people[] = [$first, $last, null, '', $username . '@example.com', '', '', '', '', '', '', '', $id];
            $accounts[] = [$username, $hash, $id, 0, 2, null, $language];
        }

        $this->insert('people', ['first_name', 'last_name', 'gender', 'phone_number', 'email', 'address_1', 'address_2', 'city', 'state', 'zip', 'country', 'comments', 'person_id'], $people);
        $this->insert('employees', ['username', 'password', 'person_id', 'deleted', 'hash_version', 'language', 'language_code'], $accounts);
        $this->cashiers = [3, 4, 5, 3, 4, 2, 1];

        $permissions = array_column($this->query("SELECT permission_id FROM `{$this->prefix}permissions`"), 'permission_id');
        $grants = [];

        foreach ($permissions as $permission) {
            $grants[] = [$permission, 2, 'home'];

            if (in_array($permission, ['home', 'sales', 'sales_stock', 'customers', 'items', 'items_stock', 'cashups', 'giftcards', 'receivings', 'receivings_stock'], true)) {
                foreach ([3, 4, 5] as $cashier) {
                    $grants[] = [$permission, $cashier, 'home'];
                }
            }

            if (in_array($permission, ['home', 'items', 'items_stock', 'receivings', 'receivings_stock', 'suppliers'], true)) {
                $grants[] = [$permission, 6, 'home'];
            }
        }

        $this->insert('grants', ['permission_id', 'person_id', 'menu_group'], $grants, 'INSERT IGNORE');
    }

    private function seedLocations(): void
    {
        $this->insert('stock_locations', ['location_id', 'location_name', 'deleted'], [[2, 'Warehouse', 0], [3, 'Showroom', 0], [4, 'Old Store', 1]]);
        $permissions = [];
        $grants = [];

        foreach ([[2, 'Warehouse'], [3, 'Showroom']] as [$id, $name]) {
            foreach (['items', 'sales', 'receivings'] as $module) {
                $permissions[] = [$module . '_' . $name, $module, $id];

                foreach ([1, 2] as $person) {
                    $grants[] = [$module . '_' . $name, $person, 'home'];
                }
            }

            $grants[] = ['sales_' . $name, 3, 'home'];
            $grants[] = ['sales_' . $name, 4, 'home'];
        }

        $this->insert('permissions', ['permission_id', 'module_id', 'location_id'], $permissions);
        $this->insert('grants', ['permission_id', 'person_id', 'menu_group'], $grants);
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function query(string $sql): array
    {
        return $this->db->query($sql)->fetch_all(MYSQLI_ASSOC);
    }

    private function personName(): array
    {
        if ($this->chance(55)) {
            return [$this->pick(self::ARABIC_FIRST), $this->pick(self::ARABIC_LAST)];
        }

        return [$this->pick(self::ENGLISH_FIRST), $this->pick(self::ENGLISH_LAST)];
    }

    private function seedPeople(): void
    {
        $customerCount = (int) $this->options['customers'];
        $supplierCount = (int) $this->options['suppliers'];
        $kinds = array_merge(array_fill(0, $customerCount, 'customer'), array_fill(0, $supplierCount, 'supplier'));
        $order = [];

        foreach ($kinds as $index => $kind) {
            $order[] = [$this->unit(), $kind, $index];
        }

        usort($order, static fn (array $a, array $b): int => $a[0] <=> $b[0]);

        $people = [];
        $customers = [];
        $suppliers = [];
        $personId = 7;
        $customerNumber = 0;
        $supplierNumber = 0;

        foreach ($order as [, $kind]) {
            if ($this->chance(1.5)) {
                $personId++;
            }

            [$first, $last] = $this->personName();
            $isCompany = $kind === 'supplier' || $this->chance(22);
            $company = $isCompany ? $this->pick(self::COMPANY_WORDS) . ' ' . $this->pick(self::COMPANY_KINDS) : null;
            $phone = $this->chance(88) ? '05' . $this->int(10000000, 99999999) : '';
            $email = $this->chance(62) ? strtolower(preg_replace('/[^a-z0-9]/i', '', $first . $last) ?: 'user') . $this->int(1, 999) . '@example.com' : '';
            $people[] = [
                $first,
                $last,
                $this->chance(70) ? $this->int(0, 1) : null,
                $phone,
                $email,
                $this->chance(60) ? $this->int(1, 400) . ' ' . $this->pick(self::CITIES) . ' St' : '',
                '',
                $this->chance(75) ? $this->pick(self::CITIES) : '',
                '',
                $this->chance(50) ? (string) $this->int(10000, 99999) : '',
                $this->chance(80) ? 'SA' : '',
                $this->chance(5) ? 'imported' : '',
                $personId,
            ];

            if ($kind === 'customer') {
                $customerNumber++;
                $deleted = $this->chance(3.2);
                $discountRoll = $this->unit();
                $discount = $discountRoll < 0.86 ? 0.0 : ($discountRoll < 0.95 ? (float) $this->pick([5, 10, 15]) : (float) $this->pick([20, 50]));
                $discountType = $discount >= 20 ? 1 : 0;
                $package = $this->chance(30) ? $this->int(1, 5) : null;
                $taxable = $this->chance(92);
                $customers[] = [
                    $personId,
                    $company,
                    $this->chance(55) ? 'C' . str_pad((string) $customerNumber, 5, '0', STR_PAD_LEFT) : null,
                    $taxable ? 1 : 0,
                    $this->chance(15) ? '3' . $this->int(10000000000, 99999999999) : '',
                    null,
                    $package,
                    null,
                    $deleted ? 1 : 0,
                    $this->money($discount),
                    $discountType,
                    $this->localTime($this->fromTs + $this->int(0, max(1, intdiv($this->toTs - $this->fromTs, 2)))),
                    $this->int(1, 2),
                    $this->int(0, 1),
                ];
                $this->customers[] = [
                    'id' => $personId,
                    'deleted' => $deleted,
                    'taxable' => $taxable,
                    'packageId' => $package,
                    'discount' => $discount,
                    'discountType' => $discountType,
                ];
            } else {
                $supplierNumber++;
                $isCost = $this->chance(5);
                $deleted = $this->chance(5);
                $suppliers[] = [
                    $personId,
                    $company ?? $first,
                    $this->chance(30) ? $first . ' ' . $last : '',
                    $this->chance(70) ? 'SUP-' . str_pad((string) $supplierNumber, 4, '0', STR_PAD_LEFT) : null,
                    $this->chance(60) ? '3' . $this->int(10000000000, 99999999999) : '',
                    $deleted ? 1 : 0,
                    $isCost ? 1 : 0,
                ];

                if (! $isCost) {
                    $this->suppliers[] = $personId;
                } else {
                    $this->expenseSuppliers[] = $personId;
                }
            }

            $personId++;
        }

        $this->insert('people', ['first_name', 'last_name', 'gender', 'phone_number', 'email', 'address_1', 'address_2', 'city', 'state', 'zip', 'country', 'comments', 'person_id'], $people);
        $this->insert('suppliers', ['person_id', 'company_name', 'agency_name', 'account_number', 'tax_id', 'deleted', 'category'], $suppliers);
        $this->insert('customers', ['person_id', 'company_name', 'account_number', 'taxable', 'tax_id', 'sales_tax_code_id', 'package_id', 'points', 'deleted', 'discount', 'discount_type', 'date', 'employee_id', 'consent'], $customers);
        $this->maxReal = $personId;
    }

    private function seedExpenseCategories(): void
    {
        $rows = [];

        foreach (self::EXPENSE_CATEGORIES as $index => $name) {
            $rows[] = [$index + 1, $name, $this->chance(50) ? $name . ' costs' : '', $name === 'Old category' ? 1 : 0];
        }

        $this->insert('expense_categories', ['expense_category_id', 'category_name', 'category_description', 'deleted'], $rows);
    }

    private function seedGiftcards(): void
    {
        $count = (int) $this->options['giftcards'];
        $rows = [];

        for ($index = 1; $index <= $count; $index++) {
            $value = (float) $this->int(5, 100) * 5;
            $number = (string) (1000 + $index);
            $holder = $this->chance(40) ? $this->customers[$this->int(0, count($this->customers) - 1)]['id'] : null;
            $this->giftcards[$index] = ['number' => $number, 'balance' => $value];
            $rows[] = [$this->localTime($this->fromTs + $this->int(0, 86400 * 400)), $index, $number, $this->money($value), $this->chance(4) ? 1 : 0, $holder];
        }

        $this->insert('giftcards', ['record_time', 'giftcard_id', 'giftcard_number', 'value', 'deleted', 'person_id'], $rows);
    }

    private function newItem(int $timestamp, bool $temporary = false): int
    {
        $id = $this->nextItemId++;
        $cost = round(0.5 + (($this->unit() ** 3) * 400), 2);
        $unit = round($cost * (1.08 + $this->unit() * 1.1), 2);
        $roll = $this->unit();

        if (! $temporary) {
            if ($roll < 0.01) {
                $unit = 0.0;
            } elseif ($roll < 0.04) {
                $cost = 0.0;
            } elseif ($roll < 0.06) {
                $unit = round($cost * 0.8, 2);
            }
        }

        $nonStock = $temporary || $this->chance(8);
        $taxRoll = $this->unit();
        $taxes = $taxRoll < 0.07 ? [] : ($taxRoll < 0.10 ? [['VAT', 5.0]] : ($taxRoll < 0.12 ? [['VAT', 15.0], ['Excise', 50.0]] : [['VAT', 15.0]]));
        $pack = ! $temporary && $this->chance(10);
        $supplier = $temporary || $this->chance(15) || $this->suppliers === [] ? null : $this->suppliers[$this->skewIndex(count($this->suppliers), 1.0)];
        $number = $this->chance(90) ? (string) (6001000000000 + $id * 7 + ($this->chance(0.3) ? 0 : 1)) : null;
        $name = $temporary ? 'Temp item ' . $id : $this->pick(self::ITEM_WORDS) . ' ' . $this->pick(self::ITEM_NOUNS) . ' ' . $id;

        $this->items[$id] = [
            'cost' => $cost,
            'price' => $unit,
            'name' => $name,
            'stockType' => $nonStock ? 1 : 0,
            'taxes' => $taxes,
            'receivingQuantity' => $pack ? (float) $this->pick([6, 12, 24]) : 1.0,
            'weighted' => ! $temporary && $this->chance(5),
            'supplier' => $supplier,
            'temporary' => $temporary,
            'deletedFlag' => $temporary || $this->chance(5),
            'createdAt' => $timestamp,
            'tracked' => false,
        ];

        $this->buffer('items', ['name', 'category', 'supplier_id', 'item_number', 'description', 'cost_price', 'unit_price', 'reorder_level', 'receiving_quantity', 'item_id', 'pic_filename', 'allow_alt_description', 'is_serialized', 'deleted', 'stock_type', 'item_type', 'tax_category_id', 'qty_per_pack', 'pack_name', 'low_sell_item_id', 'hsn_code'], [
            $name,
            $temporary ? '' : $this->pick(self::CATEGORIES),
            $supplier,
            $number,
            $this->chance(30) ? 'Imported item' : '',
            $this->money($cost),
            $this->money($unit),
            $this->money((float) $this->pick([0, 0, 5, 10, 20])),
            $this->items[$id]['receivingQuantity'],
            $id,
            null,
            $this->chance(10) ? 1 : 0,
            $this->chance(2) ? 1 : 0,
            $temporary ? 1 : 0,
            $nonStock ? 1 : 0,
            $temporary ? 3 : 0,
            null,
            1,
            $this->chance(80) ? 'Each' : null,
            $this->chance(50) ? 0 : null,
            '',
        ]);

        foreach ($taxes as [$taxName, $percent]) {
            $this->buffer('items_taxes', ['item_id', 'name', 'percent'], [$id, $taxName, $percent]);
        }

        if ($supplier !== null) {
            $this->supplierItems[$supplier][] = $id;
        }

        return $id;
    }

    private function seedItems(): void
    {
        $count = (int) $this->options['items'];
        $this->nextItemId = 1;

        for ($index = 0; $index < $count; $index++) {
            if ($this->chance(2)) {
                $this->nextItemId++;
            }

            $createdAt = $this->chance(75) ? $this->fromTs : $this->businessTimestamp(1.0);
            $this->newItem($createdAt);
        }

        $this->insert('items', $this->bufferColumns['items'], $this->buffers['items']);
        unset($this->buffers['items']);
        $this->insert('items_taxes', $this->bufferColumns['items_taxes'], $this->buffers['items_taxes']);
        unset($this->buffers['items_taxes']);
    }

    private function openingStock(int $itemId, int $timestamp): void
    {
        if ($this->items[$itemId]['stockType'] !== 0) {
            return;
        }

        $locations = [1 => $this->int(0, 150)];

        if ($this->chance(40)) {
            $locations[2] = $this->int(0, 300);
        }

        if ($this->chance(20)) {
            $locations[3] = $this->int(0, 40);
        }

        if ($this->chance(3)) {
            $locations[4] = $this->int(1, 9);
        }

        foreach ($locations as $location => $quantity) {
            $this->stock[$itemId][$location] = (float) $quantity;

            if ($quantity !== 0) {
                $this->movement($itemId, 1, $location, (float) $quantity, 'Manual Edit of Quantity', $timestamp);
            }
        }
    }

    private function movement(int $itemId, int $userId, int $location, float $quantity, string $comment, int $timestamp): void
    {
        $this->buffer('inventory', ['trans_items', 'trans_user', 'trans_date', 'trans_comment', 'trans_location', 'trans_inventory'], [
            $itemId,
            $userId,
            $this->localTime($timestamp),
            $comment,
            $location,
            $this->quantityText($quantity),
        ]);
    }

    private function quantityText(float $quantity): string
    {
        return number_format($quantity, 3, '.', '');
    }

    private function processTimeline(): void
    {
        $events = [];

        foreach ($this->items as $itemId => $item) {
            $events[] = [$item['createdAt'], 0, 'create', $itemId];

            if ($item['deletedFlag'] && ! $item['temporary']) {
                $events[] = [min($this->toTs, $item['createdAt'] + $this->int(86400 * 20, 86400 * 700)), 1, 'delete', $itemId];
            }
        }

        for ($index = 0; $index < (int) $this->options['sales']; $index++) {
            $events[] = [$this->businessTimestamp(), 3, 'sale', $index];
        }

        for ($index = 0; $index < (int) $this->options['receivings']; $index++) {
            $events[] = [$this->businessTimestamp(0.7), 2, 'receiving', $index];
        }

        for ($index = 0; $index < 800; $index++) {
            $events[] = [$this->businessTimestamp(0.8), 4, 'adjust', $index];
        }

        for ($index = 0; $index < 400; $index++) {
            $events[] = [$this->businessTimestamp(0.8), 4, 'price', $index];
        }

        usort($events, static fn (array $a, array $b): int => [$a[0], $a[1]] <=> [$b[0], $b[1]]);

        foreach ($events as [$timestamp, , $kind, $payload]) {
            match ($kind) {
                'create' => $this->activate($payload, $timestamp),
                'delete' => $this->deactivate($payload),
                'sale' => $this->sale($timestamp),
                'receiving' => $this->receiving($timestamp),
                'adjust' => $this->adjust($timestamp),
                'price' => $this->changePrice(),
            };

            if (count($this->buffers['sales'] ?? []) >= self::FLUSH_EVERY || count($this->buffers['receivings'] ?? []) >= self::FLUSH_EVERY) {
                $this->flushAll();
            }
        }
    }

    private function activate(int $itemId, int $timestamp): void
    {
        $this->activeItems[] = $itemId;
        $this->openingStock($itemId, $timestamp);
    }

    private function deactivate(int $itemId): void
    {
        $position = array_search($itemId, $this->activeItems, true);

        if ($position !== false) {
            array_splice($this->activeItems, (int) $position, 1);
        }
    }

    private function changePrice(): void
    {
        if ($this->activeItems === []) {
            return;
        }

        $itemId = $this->activeItems[$this->int(0, count($this->activeItems) - 1)];
        $this->items[$itemId]['price'] = round($this->items[$itemId]['price'] * (0.85 + $this->unit() * 0.4), 2);
    }

    private function adjust(int $timestamp): void
    {
        $stocked = [];

        for ($attempt = 0; $attempt < 20 && count($stocked) < 1; $attempt++) {
            $itemId = $this->activeItems[$this->int(0, count($this->activeItems) - 1)];

            if ($this->items[$itemId]['stockType'] === 0) {
                $stocked[] = $itemId;
            }
        }

        if ($stocked === []) {
            return;
        }

        $itemId = $stocked[0];
        $location = array_key_first($this->stock[$itemId] ?? [1 => 0]) ?? 1;
        $current = $this->stock[$itemId][$location] ?? 0.0;
        $delta = $this->chance(70) ? (float) $this->int(1, 20) : -(float) $this->int(1, min(8, max(1, (int) $current)));
        $this->stock[$itemId][$location] = $current + $delta;
        $this->movement($itemId, $this->pick([1, 2, 6]), $location, $delta, $this->chance(60) ? 'Manual Edit of Quantity' : 'Stock count', $timestamp);
    }

    private function pickItem(): int
    {
        return $this->activeItems[$this->skewIndex(count($this->activeItems), 1.6)];
    }

    private function pickLocation(): int
    {
        return (int) $this->weighted(['1' => 78, '2' => 14, '3' => 8]);
    }

    private function pickCustomer(): array
    {
        $index = $this->chance(50) ? $this->skewIndex(max(1, intdiv(count($this->customers), 10)), 1.3) : $this->skewIndex(count($this->customers), 1.0);

        return $this->customers[$index];
    }

    private function invoiceNumber(int $timestamp): string
    {
        $this->invoiceSequence++;
        $switch = (new DateTimeImmutable(self::NUMBER_FORMAT_SWITCH_DATE . ' 00:00:00', $this->zone))->getTimestamp();

        if ($this->chance(0.4)) {
            return 'INV ' . $this->int(100, 999) . '-' . $this->pick(['A', 'B', 'X']) . $this->invoiceSequence;
        }

        return $timestamp < $switch ? (string) $this->invoiceSequence : 'INV-' . str_pad((string) $this->invoiceSequence, 5, '0', STR_PAD_LEFT);
    }

    private function sale(int $timestamp): void
    {
        $this->saleCounter++;

        if ($this->activeItems === []) {
            return;
        }

        $kind = $this->weighted(['pos' => 76, 'invoice' => 8.5, 'return' => 4.2, 'quote' => 2.4, 'workorder' => 0.8, 'suspended' => 2.4, 'suspended_invoice' => 0.9, 'canceled' => 2.2, 'canceled_invoice' => 0.6]);
        $type = match ($kind) {
            'pos', 'suspended', 'canceled' => 0,
            'invoice', 'suspended_invoice', 'canceled_invoice' => 1,
            'workorder' => 2,
            'quote' => 3,
            'return' => 4,
        };
        $status = match ($kind) {
            'pos', 'invoice', 'return' => 0,
            'quote', 'workorder', 'suspended', 'suspended_invoice' => 1,
            'canceled', 'canceled_invoice' => 2,
        };
        $isReturn = $type === 4;
        $needsCustomer = in_array($kind, ['invoice', 'suspended_invoice', 'canceled_invoice', 'quote', 'workorder'], true);
        $customer = $needsCustomer || $this->chance($isReturn ? 50 : 40) ? $this->pickCustomer() : null;
        $employeeId = $this->pick($this->cashiers);

        if ($this->chance(2)) {
            $this->nextSaleId += $this->int(1, 4);
        }

        $saleId = $this->nextSaleId++;
        $completedFlow = $status === 0 || ($status === 2 && $this->chance(60));
        $lines = $this->saleLines($saleId, $isReturn, $customer, $completedFlow, $employeeId, $timestamp);
        $taxed = $customer === null || $customer['taxable'];
        $taxIncluded = $timestamp >= (new DateTimeImmutable(self::TAX_SWITCH_DATE . ' 00:00:00', $this->zone))->getTimestamp();
        $totals = $this->lineTaxes($saleId, $lines, $taxed, $taxIncluded);

        $invoiceNumber = null;
        $quoteNumber = null;
        $workOrderNumber = null;

        if ($type === 1 && $status !== 1 && ! $this->chance(1)) {
            $invoiceNumber = $this->invoiceNumber($timestamp);
        } elseif ($type === 1 && $status === 1 && $this->chance(25)) {
            $invoiceNumber = $this->invoiceNumber($timestamp);
        }

        if ($type === 3) {
            $this->quoteSequence++;
            $quoteNumber = $this->chance(2) && $this->quoteSequence > 3 ? 'Q' . ($this->quoteSequence - 2) : 'Q' . $this->quoteSequence;
        }

        if ($type === 2) {
            $this->workOrderSequence++;
            $workOrderNumber = 'W' . substr($this->localTime($timestamp), 2, 2) . str_pad((string) $this->workOrderSequence, 6, '0', STR_PAD_LEFT);
        }

        $this->buffer('sales', ['sale_time', 'customer_id', 'employee_id', 'comment', 'invoice_number', 'dinner_table_id', 'sale_id', 'quote_number', 'sale_status', 'work_order_number', 'sale_type'], [
            $this->localTime($timestamp),
            $customer['id'] ?? null,
            $employeeId,
            $this->chance(6) ? $this->pick(['Delivery tomorrow', 'Customer asked for gift wrap', 'Price checked with manager', 'توصيل للمنزل']) : '',
            $invoiceNumber,
            null,
            $saleId,
            $quoteNumber,
            $status,
            $workOrderNumber,
            $type,
        ]);

        if ($status === 0 || ($status === 2 && $completedFlow)) {
            $this->payments($saleId, $kind, $customer, $totals, $employeeId, $timestamp, $isReturn);
        } elseif ($status === 1 && $kind === 'suspended' && $customer !== null && $this->chance(10)) {
            $this->payments($saleId, 'pos', $customer, $totals, $employeeId, $timestamp, false, true);
        }

        if ($status === 2 && $completedFlow) {
            $this->restoreStock($saleId, $lines, $employeeId, $timestamp + $this->int(600, 86400));
        }
    }

    /**
     * @param array<string, mixed>|null $customer
     *
     * @return list<array<string, mixed>>
     */
    private function saleLines(int $saleId, bool $isReturn, ?array $customer, bool $moveStock, int $employeeId, int $timestamp): array
    {
        $count = (int) $this->weighted(['1' => 30, '2' => 25, '3' => 18, '4' => 12, '5' => 7, '6' => 4, '8' => 3, '12' => 1]);
        $lines = [];

        for ($line = 1; $line <= $count; $line++) {
            $itemId = $this->chance(0.4) ? $this->temporaryItem($timestamp) : $this->pickItem();
            $item = $this->items[$itemId];
            $price = $item['price'];

            if ($this->chance(8)) {
                $price = round($price * (0.85 + $this->unit() * 0.25), 2);
            }

            if ($this->chance(0.7)) {
                $price = 0.0;
            }

            $quantity = $item['weighted'] ? round(0.25 + $this->unit() * 3.25, 3) : (float) $this->weighted(['1' => 70, '2' => 14, '3' => 8, '4' => 4, '6' => 3, '12' => 1]);

            if ($isReturn || $this->chance(0.5)) {
                $quantity = -$quantity;
            }

            $discount = 0.0;
            $discountType = 0;
            $customerDiscount = $customer['discount'] ?? 0.0;

            if ($customerDiscount > 0 && $this->chance(60)) {
                $discount = $customerDiscount;
                $discountType = (int) $customer['discountType'];
            } elseif ($this->chance(12)) {
                $discountType = $this->chance(60) ? 0 : 1;
                $discount = $discountType === 0 ? (float) $this->pick([5, 10, 15, 20, 50]) : (float) $this->pick([1, 2, 5, 10]);
            }

            if ($price === 0.0) {
                $discount = 0.0;
            }

            if ($discountType === 1 && $discount > $price) {
                $discount = $price;
            }

            $location = $item['stockType'] === 0 ? $this->pickLocation() : 1;
            $discountAmount = $this->roundHalfUp($discountType === 0 ? $quantity * $price * $discount / 100 : $quantity * $discount, 2);
            $basis = $this->truncate4($quantity * $price) - $discountAmount;
            $lines[] = [
                'item' => $itemId,
                'line' => $line,
                'quantity' => $quantity,
                'price' => $price,
                'discount' => $discount,
                'discountType' => $discountType,
                'location' => $location,
                'basis' => $basis,
                'cost' => $item['cost'],
                'taxes' => $item['taxes'],
                'stockType' => $item['stockType'],
            ];

            $this->buffer('sales_items', ['sale_id', 'item_id', 'description', 'serialnumber', 'line', 'quantity_purchased', 'item_cost_price', 'item_unit_price', 'discount', 'discount_type', 'item_location', 'print_option'], [
                $saleId,
                $itemId,
                $this->chance(3) ? null : $item['name'],
                $this->chance(2) ? null : '',
                $line,
                $this->quantityText($quantity),
                $this->money($item['cost']),
                $this->money($price),
                $this->money($discount),
                $discountType,
                $location,
                $this->chance(85) ? 0 : 3,
            ]);

            if ($moveStock && $item['stockType'] === 0) {
                $this->stock[$itemId][$location] = ($this->stock[$itemId][$location] ?? 0.0) - $quantity;
                $this->movement($itemId, $employeeId, $location, -$quantity, 'POS ' . $saleId, $timestamp);
            }
        }

        return $lines;
    }

    private function temporaryItem(int $timestamp): int
    {
        $itemId = $this->newItem($timestamp, true);
        $this->items[$itemId]['price'] = round(1 + $this->unit() * 80, 2);

        return $itemId;
    }

    /**
     * @param list<array<string, mixed>> $lines
     */
    private function restoreStock(int $saleId, array $lines, int $employeeId, int $timestamp): void
    {
        foreach ($lines as $line) {
            if ($line['stockType'] !== 0) {
                continue;
            }

            $this->stock[$line['item']][$line['location']] = ($this->stock[$line['item']][$line['location']] ?? 0.0) + $line['quantity'];
            $this->movement($line['item'], $employeeId, $line['location'], $line['quantity'], 'Deleting sale ' . $saleId, $timestamp);
        }
    }

    /**
     * @param list<array<string, mixed>> $lines
     *
     * @return array{subtotal: float, tax: float, total: float}
     */
    private function lineTaxes(int $saleId, array $lines, bool $taxed, bool $taxIncluded): array
    {
        $groups = [];
        $subtotal = 0.0;

        foreach ($lines as $line) {
            $subtotal += $line['basis'];

            if (! $taxed) {
                continue;
            }

            $sequence = 0;

            foreach ($line['taxes'] as [$name, $percent]) {
                if ($taxIncluded) {
                    $amount = $this->truncate4($line['basis'] - $this->truncate4($line['basis'] / (1 + $percent / 100)));
                } else {
                    $amount = $this->roundHalfUp($line['basis'] * $percent / 100, 2);
                }

                $this->buffer('sales_items_taxes', ['sale_id', 'item_id', 'line', 'name', 'percent', 'tax_type', 'rounding_code', 'cascade_sequence', 'item_tax_amount', 'sales_tax_code_id', 'jurisdiction_id', 'tax_category_id'], [
                    $saleId,
                    $line['item'],
                    $line['line'],
                    $name,
                    number_format($percent, 4, '.', ''),
                    $taxIncluded ? 0 : 1,
                    0,
                    0,
                    number_format($amount, 4, '.', ''),
                    null,
                    null,
                    null,
                ]);

                if ($amount != 0.0) {
                    $sequence++;
                    $key = $name . '|' . $percent;
                    $groups[$key] ??= ['name' => $name, 'percent' => $percent, 'basis' => 0.0, 'amount' => 0.0, 'sequence' => $sequence];
                    $groups[$key]['basis'] += $line['basis'];
                    $groups[$key]['amount'] += $amount;
                    $sequence++;
                }
            }
        }

        $saleTax = 0.0;
        $skipHeaderRows = $this->chance(0.5);

        foreach ($groups as $group) {
            $rounded = $this->roundHalfUp($group['amount'], 2);
            $saleTax += $rounded;

            if ($skipHeaderRows) {
                continue;
            }

            $this->buffer('sales_taxes', ['sale_id', 'jurisdiction_id', 'tax_category_id', 'tax_type', 'tax_group', 'sale_tax_basis', 'sale_tax_amount', 'print_sequence', 'name', 'tax_rate', 'sales_tax_code_id', 'rounding_code'], [
                $saleId,
                null,
                null,
                $taxIncluded ? 0 : 1,
                $group['name'],
                number_format($group['basis'], 4, '.', ''),
                number_format($rounded, 4, '.', ''),
                $group['sequence'],
                $group['name'],
                number_format($group['percent'], 4, '.', ''),
                null,
                0,
            ]);
        }

        $subtotal = $this->roundHalfUp($subtotal, 2);

        return ['subtotal' => $subtotal, 'tax' => $saleTax, 'total' => $taxIncluded ? $subtotal : $this->roundHalfUp($subtotal + $saleTax, 2)];
    }

    /**
     * @param array<string, mixed>|null $customer
     * @param array{subtotal: float, tax: float, total: float} $totals
     */
    private function payments(int $saleId, string $kind, ?array $customer, array $totals, int $employeeId, int $timestamp, bool $isReturn, bool $partialOnly = false): void
    {
        $total = $totals['total'];
        $rows = [];
        $add = function (string $type, float $amount, float $refund = 0.0, int $adjustment = 0, string $reference = '', ?int $at = null, ?int $employee = null) use (&$rows, $employeeId, $timestamp): void {
            $rows[] = [$type, $amount, $refund, $adjustment, $reference, min($at ?? $timestamp, $this->toTs), $employee ?? $employeeId];
        };
        $cashLabel = $this->label($employeeId, 'cash');

        if ($total === 0.0) {
            if ($this->chance(40)) {
                $add($cashLabel, 0.0);
            }
        } elseif ($isReturn) {
            $roll = $this->unit();

            if ($roll < 0.7) {
                $add($cashLabel, $total);
            } elseif ($roll < 0.9) {
                $add($this->label($employeeId, 'credit'), $total, 0.0, 0, 'RF' . $this->int(100000, 999999));
            } elseif ($customer !== null) {
                $add($this->label($employeeId, 'due'), $total);
            } else {
                $add($cashLabel, $total);
            }
        } elseif ($kind === 'invoice' || $kind === 'canceled_invoice') {
            $roll = $this->unit();

            if ($roll < 0.45 && $customer !== null) {
                $add($this->label($employeeId, 'due'), $total);

                if ($this->chance(40)) {
                    $add($cashLabel, $total, 0.0, 0, '', $timestamp + $this->int(86400 * 3, 86400 * 70), $this->pick($this->cashiers));
                }
            } elseif ($roll < 0.65 && $customer !== null) {
                $paid = $this->roundHalfUp($total * (0.2 + $this->unit() * 0.6), 2);
                $add($cashLabel, $paid);
                $add($this->label($employeeId, 'due'), $this->roundHalfUp($total - $paid, 2));

                if ($this->chance(35)) {
                    $add($this->label($employeeId, 'check'), $this->roundHalfUp($total - $paid, 2), 0.0, 0, 'CHK' . $this->int(1000, 9999), $timestamp + $this->int(86400 * 5, 86400 * 60), $this->pick($this->cashiers));
                }
            } else {
                $this->fullPayment($add, $employeeId, $total);
            }
        } elseif ($partialOnly) {
            $add($cashLabel, $this->roundHalfUp($total * 0.3, 2));
        } else {
            $roll = $this->unit();

            if ($roll < 0.50) {
                $this->fullPayment($add, $employeeId, $total, true);
            } elseif ($roll < 0.78) {
                $add($this->label($employeeId, $this->chance(55) ? 'debit' : 'credit'), $total, 0.0, 0, $this->chance(30) ? 'AUTH' . $this->int(100000, 999999) : '');
            } elseif ($roll < 0.83) {
                $add($this->label($employeeId, 'check'), $total, 0.0, 0, $this->chance(50) ? 'CHK' . $this->int(1000, 9999) : '');
            } elseif ($roll < 0.90) {
                $cash = $this->roundHalfUp($total * (0.2 + $this->unit() * 0.6), 2);
                $add($cashLabel, $cash);
                $add($this->label($employeeId, 'debit'), $this->roundHalfUp($total - $cash, 2));
            } elseif ($roll < 0.93 && $this->giftcards !== []) {
                $this->giftcardPayment($add, $employeeId, $total);
            } elseif ($roll < 0.955 && $customer !== null && $customer['packageId'] !== null && ($this->customerPoints[$customer['id']] ?? 0.0) > 5) {
                $used = min($this->customerPoints[$customer['id']], round($total * 0.5, 2));
                $this->customerPoints[$customer['id']] -= $used;
                $add($this->label($employeeId, 'rewards'), $used);
                $this->fullPayment($add, $employeeId, $this->roundHalfUp($total - $used, 2));
            } elseif ($customer !== null) {
                $paid = $this->roundHalfUp($total * (0.3 + $this->unit() * 0.5), 2);
                $add($cashLabel, $paid);
                $add($this->label($employeeId, 'due'), $this->roundHalfUp($total - $paid, 2));
            } else {
                $this->fullPayment($add, $employeeId, $total, true);
            }
        }

        $quirk = $this->unit() * 100;

        if ($quirk < 0.3 && $rows !== []) {
            $rows = [];
        } elseif ($quirk < 1.1 && $rows !== []) {
            $rows[0][1] = $this->roundHalfUp($rows[0][1] * (0.8 + $this->unit() * 0.4), 2);
        }

        $paidNet = 0.0;

        foreach ($rows as [$type, $amount, $refund, $adjustment, $reference, $at, $employee]) {
            $this->buffer('sales_payments', ['sale_id', 'payment_type', 'payment_amount', 'cash_refund', 'cash_adjustment', 'employee_id', 'payment_time', 'reference_code'], [
                $saleId,
                $type,
                $this->money($amount),
                $this->money($refund),
                $adjustment,
                $this->chance(3) ? null : $employee,
                $this->localTime((int) $at),
                $reference,
            ]);

            $isDue = $type === self::PAYMENT_LABELS['en']['due'] || $type === self::PAYMENT_LABELS['ar-EG']['due'];

            if (! $isDue) {
                $day = substr($this->localTime((int) $at), 0, 10);
                $bucket = $this->bucketOf($type);
                $this->dayTotals[$day][$bucket] = ($this->dayTotals[$day][$bucket] ?? 0.0) + $amount - $refund;
                $paidNet += $amount - $refund;
            } else {
                $day = substr($this->localTime((int) $at), 0, 10);
                $this->dayTotals[$day]['due'] = ($this->dayTotals[$day]['due'] ?? 0.0) + $amount;
            }
        }

        if ($customer !== null && ! $isReturn && $customer['packageId'] !== null && $total > 0) {
            $percent = $this->packagePercent[$customer['packageId']];
            $earned = round($paidNet * $percent / 100, 4);
            $this->customerPoints[$customer['id']] = ($this->customerPoints[$customer['id']] ?? 0.0) + $earned;
            $this->buffer('sales_reward_points', ['sale_id', 'earned', 'used'], [$saleId, $earned, 0]);
            $this->buffer('customers_points', ['person_id', 'package_id', 'sale_id', 'points_earned'], [$customer['id'], $customer['packageId'], $saleId, (int) round($earned)]);
        }
    }

    private function bucketOf(string $type): string
    {
        foreach (['en', 'ar-EG'] as $language) {
            $labels = self::PAYMENT_LABELS[$language];

            if ($type === $labels['cash']) {
                return 'cash';
            }

            if ($type === $labels['check']) {
                return 'check';
            }
        }

        return 'card';
    }

    private function fullPayment(callable $add, int $employeeId, float $total, bool $allowChange = false): void
    {
        $cashLabel = $this->label($employeeId, 'cash');

        if ($total <= 0) {
            return;
        }

        $roll = $this->unit();

        if ($roll < 0.62 || $allowChange) {
            $tendered = $total;

            if ($allowChange && $this->chance(55)) {
                $step = (float) $this->pick([1, 5, 10, 20, 50, 100]);
                $tendered = max($total, ceil($total / $step) * $step);
            }

            if ($this->chance(2.5)) {
                $rounded = round($total * 20) / 20;
                $add($cashLabel, $rounded, 0.0, 1);

                return;
            }

            $add($cashLabel, $this->roundHalfUp($tendered, 2), $this->roundHalfUp($tendered - $total, 2));
        } elseif ($roll < 0.85) {
            $add($this->label($employeeId, 'debit'), $total, 0.0, 0, $this->chance(30) ? 'AUTH' . $this->int(100000, 999999) : '');
        } else {
            $add($this->label($employeeId, 'check'), $total, 0.0, 0, 'CHK' . $this->int(1000, 9999));
        }
    }

    private function giftcardPayment(callable $add, int $employeeId, float $total): void
    {
        $index = $this->int(1, count($this->giftcards));
        $card = $this->giftcards[$index];
        $used = min($card['balance'], $total);

        if ($used <= 0) {
            $this->fullPayment($add, $employeeId, $total, true);

            return;
        }

        $this->giftcards[$index]['balance'] -= $used;
        $add($this->label($employeeId, 'giftcard') . ':' . $card['number'], $this->roundHalfUp($used, 2));

        if ($total - $used > 0.004) {
            $this->fullPayment($add, $employeeId, $this->roundHalfUp($total - $used, 2));
        }
    }

    private function receiving(int $timestamp): void
    {
        if ($this->activeItems === []) {
            return;
        }

        $kind = $this->weighted(['receive' => 92, 'return' => 4, 'requisition' => 4]);
        $supplierId = $this->chance(3) || $kind === 'requisition' ? null : $this->suppliers[$this->skewIndex(count($this->suppliers), 1.2)];
        $employeeId = $this->pick([1, 2, 6, 6]);

        if ($this->chance(1)) {
            $this->nextReceivingId += $this->int(1, 3);
        }

        $receivingId = $this->nextReceivingId++;
        $count = (int) $this->weighted(['1' => 15, '2' => 20, '3' => 20, '4' => 15, '6' => 15, '8' => 10, '12' => 5]);
        $line = 0;
        $used = [];

        for ($index = 0; $index < $count; $index++) {
            $itemId = $this->receivingItem($supplierId);

            if (isset($used[$itemId]) && $kind !== 'requisition') {
                continue;
            }

            $used[$itemId] = true;
            $item = $this->items[$itemId];
            $location = $item['stockType'] === 0 ? (int) $this->weighted(['1' => 40, '2' => 50, '3' => 10]) : 1;
            $quantity = (float) $this->int(2, 120);
            $price = $this->chance(3) ? 0.0 : round(max(0.01, $item['cost'] * (0.92 + $this->unit() * 0.16)), 2);
            $discount = 0.0;
            $discountType = 0;

            if ($this->chance(8)) {
                $discount = (float) $this->pick([2, 5, 10]);
            } elseif ($this->chance(4.5)) {
                $discount = (float) $this->pick([5, 10, 20, 50]);
                $discountType = 1;
            }

            $signed = $kind === 'return' ? -$quantity : $quantity;
            $lines = [[$location, $signed]];

            if ($kind === 'requisition') {
                $other = $location === 1 ? 2 : 1;
                $lines = [[$location, -$quantity], [$other, $quantity]];
            }

            foreach ($lines as [$lineLocation, $lineQuantity]) {
                $line++;
                $this->buffer('receivings_items', ['receiving_id', 'item_id', 'description', 'serialnumber', 'line', 'quantity_purchased', 'item_cost_price', 'item_unit_price', 'discount', 'discount_type', 'item_location', 'receiving_quantity'], [
                    $receivingId,
                    $itemId,
                    $this->chance(5) ? null : mb_substr($item['name'], 0, 30),
                    $this->chance(3) ? null : '',
                    $line,
                    $this->quantityText($lineQuantity),
                    $this->money($item['cost']),
                    $this->money($price),
                    $this->money($discount),
                    $discountType,
                    $lineLocation,
                    $this->quantityText($item['receivingQuantity']),
                ]);

                $received = $lineQuantity * $item['receivingQuantity'];

                if ($item['stockType'] === 0) {
                    $this->stock[$itemId][$lineLocation] = ($this->stock[$itemId][$lineLocation] ?? 0.0) + $received;
                    $this->movement($itemId, $employeeId, $lineLocation, $received, 'RECV ' . $receivingId, $timestamp);
                }
            }

            if ($kind === 'receive' && $item['stockType'] === 0 && abs($price - $item['cost']) > 0.0001) {
                $units = $quantity * $item['receivingQuantity'];
                $before = array_sum($this->stock[$itemId] ?? []) - $units;
                $this->items[$itemId]['cost'] = $before > 0 ? round((($units * $price) + ($before * $item['cost'])) / ($units + $before), 2) : $price;
            }
        }

        $paymentType = match ($kind) {
            'requisition' => null,
            'return' => $this->label($employeeId, 'cash'),
            default => $this->pick([$this->label($employeeId, 'cash'), $this->label($employeeId, 'cash'), $this->label($employeeId, 'cash'), $this->label($employeeId, 'check'), $this->label($employeeId, 'due'), $this->label($employeeId, 'due'), null]),
        };
        $reference = null;

        if ($kind !== 'requisition') {
            if ($this->chance(5) && $this->receivingReferences !== []) {
                $reference = $this->receivingReferences[$this->int(0, count($this->receivingReferences) - 1)];
            } elseif ($this->chance(72)) {
                $reference = 'SI-' . $this->int(10000, 999999);
                $this->receivingReferences[] = $reference;
            }
        }

        $this->buffer('receivings', ['receiving_time', 'supplier_id', 'employee_id', 'comment', 'receiving_id', 'payment_type', 'reference'], [
            $this->localTime($timestamp),
            $supplierId,
            $employeeId,
            $kind === 'requisition' ? 'Requisition' : ($this->chance(8) ? 'Supplier delivery note attached' : ''),
            $receivingId,
            $paymentType,
            $reference,
        ]);
    }

    private function receivingItem(?int $supplierId): int
    {
        if ($supplierId !== null && isset($this->supplierItems[$supplierId]) && $this->chance(60)) {
            $candidates = array_values(array_filter($this->supplierItems[$supplierId], fn (int $itemId): bool => in_array($itemId, $this->activeItems, true)));

            if ($candidates !== []) {
                return $candidates[$this->int(0, count($candidates) - 1)];
            }
        }

        return $this->activeItems[$this->int(0, count($this->activeItems) - 1)];
    }

    private function seedQuantities(): void
    {
        $rows = [];

        foreach ($this->items as $itemId => $item) {
            if ($item['temporary']) {
                continue;
            }

            foreach ([1, 2, 3, 4] as $location) {
                $quantity = $this->stock[$itemId][$location] ?? 0.0;

                if ($item['stockType'] === 0 && $this->chance(2.5) && $quantity != 0.0) {
                    $quantity += (float) $this->int(-5, 9);
                }

                $rows[] = [$itemId, $location, $this->quantityText($item['stockType'] === 0 ? $quantity : 0.0)];
            }
        }

        $this->insert('item_quantities', ['item_id', 'location_id', 'quantity'], $rows);
    }

    private function seedExpenses(): void
    {
        $count = (int) $this->options['expenses'];
        $rows = [];

        for ($index = 0; $index < $count; $index++) {
            $categoryId = $this->int(1, count(self::EXPENSE_CATEGORIES));
            $amount = $this->chance(1) ? 0.0 : round(5 + ($this->unit() ** 2.5) * 4000, 2);
            $tax = $this->chance(30) ? round($amount * 0.15 / 1.15, 2) : null;
            $supplier = $this->chance(30) ? $this->pick($this->expenseSuppliers ?: [null]) : null;
            $rows[] = [
                $this->chance(0.5) ? null : $this->localTime($this->businessTimestamp(0.9)),
                $this->money($amount),
                $this->pick(['Cash', 'Cash', 'Check', 'Debit Card', 'Credit Card']),
                $categoryId,
                $this->pick(self::EXPENSE_TEXTS),
                $this->pick([1, 1, 2, 2, 3]),
                $this->chance(3) ? 1 : 0,
                $tax !== null ? 'TX' . $this->int(100000, 999999) : null,
                $tax !== null ? $this->money($tax) : null,
                $supplier,
            ];
        }

        $this->insert('expenses', ['date', 'amount', 'payment_type', 'expense_category_id', 'description', 'employee_id', 'deleted', 'supplier_tax_code', 'tax_amount', 'supplier_id'], $rows);
    }

    private function seedCashups(): void
    {
        $days = array_keys($this->dayTotals);
        sort($days);
        $days = array_slice($days, -(int) $this->options['cashups']);
        $lastDay = $days === [] ? '' : $days[array_key_last($days)];
        $rows = [];

        foreach ($days as $day) {
            $totals = $this->dayTotals[$day];
            $open = (float) $this->pick([100, 200, 300, 500]);
            $transfer = (float) $this->pick([0, 0, 100, 200, 500]);
            $cash = $open + ($totals['cash'] ?? 0.0) - $transfer;
            $shortage = $this->chance(6) ? -round($this->unit() * 25, 2) : 0.0;
            $card = $totals['card'] ?? 0.0;
            $check = $totals['check'] ?? 0.0;
            $closedCash = round($cash + $shortage, 2);
            $isLast = $day === $lastDay;
            $rows[] = [
                $day . ' 08:' . str_pad((string) $this->int(0, 30), 2, '0', STR_PAD_LEFT) . ':00',
                $isLast && $this->chance(50) ? null : $day . ' 21:' . str_pad((string) $this->int(0, 40), 2, '0', STR_PAD_LEFT) . ':00',
                $this->money($open),
                $this->money($transfer),
                $shortage != 0.0 ? 1 : 0,
                $this->money($closedCash),
                $this->money($card),
                $this->money($check),
                $this->money($closedCash + $card + $check),
                $shortage != 0.0 ? 'Short ' . $this->money(-$shortage) : '',
                $this->pick([3, 4, 5]),
                $this->pick([3, 4, 5]),
                $this->chance(2) ? 1 : 0,
                $this->money($totals['due'] ?? 0.0),
            ];
        }

        $this->insert('cash_up', ['open_date', 'close_date', 'open_amount_cash', 'transfer_amount_cash', 'note', 'closed_amount_cash', 'closed_amount_card', 'closed_amount_check', 'closed_amount_total', 'description', 'open_employee_id', 'close_employee_id', 'deleted', 'closed_amount_due'], $rows);
    }

    private function seedOrphans(): void
    {
        $rows = [];

        for ($index = 0; $index < 8; $index++) {
            $rows[] = [900000 + $index, null, null, 1, 'VAT', '100.0000', '15.0000', 1, 'VAT', '15.0000', null, 0];
        }

        $this->insert('sales_taxes', ['sale_id', 'jurisdiction_id', 'tax_category_id', 'tax_type', 'tax_group', 'sale_tax_basis', 'sale_tax_amount', 'print_sequence', 'name', 'tax_rate', 'sales_tax_code_id', 'rounding_code'], $rows);

        if (! $this->options['fkOrphans']) {
            return;
        }

        $this->db->query('SET FOREIGN_KEY_CHECKS = 0');
        $this->insert('sales_payments', ['sale_id', 'payment_type', 'payment_amount', 'cash_refund', 'cash_adjustment', 'employee_id', 'payment_time', 'reference_code'], [
            [910000, 'Cash', '10.00', '0.00', 0, 1, $this->localTime($this->fromTs + 86400), ''],
            [910001, 'Cash', '25.00', '0.00', 0, 1, $this->localTime($this->fromTs + 86400 * 2), ''],
        ]);
        $this->insert('receivings_items', ['receiving_id', 'item_id', 'description', 'serialnumber', 'line', 'quantity_purchased', 'item_cost_price', 'item_unit_price', 'discount', 'discount_type', 'item_location', 'receiving_quantity'], [
            [920000, 1, null, '', 1, '3.000', '1.00', '1.00', '0.00', 0, 1, '1.000'],
        ]);
        $this->db->query('SET FOREIGN_KEY_CHECKS = 1');
    }

    private function finalise(): void
    {
        foreach ($this->items as $itemId => $item) {
            $this->db->query(
                "UPDATE `{$this->prefix}items` SET `cost_price` = " . $this->quote($this->money($item['cost'])) . ', `unit_price` = ' . $this->quote($this->money($item['price'])) . ', `deleted` = ' . ($item['deletedFlag'] ? 1 : 0) . " WHERE `item_id` = {$itemId}",
            );
        }

        foreach ($this->giftcards as $index => $card) {
            $this->db->query("UPDATE `{$this->prefix}giftcards` SET `value` = " . $this->quote($this->money(max(0.0, $card['balance']))) . " WHERE `giftcard_id` = {$index}");
        }

        foreach ($this->customerPoints as $customerId => $points) {
            $this->db->query("UPDATE `{$this->prefix}customers` SET `points` = " . (int) round($points) . " WHERE `person_id` = {$customerId}");
        }

        $config = [
            'company' => 'Rehearsal Trading Co',
            'currency_symbol' => 'SAR',
            'currency_code' => 'SAR',
            'country_codes' => 'sa',
            'tax_included' => '1',
            'customer_reward_enable' => '1',
            'receiving_calculate_average_price' => '1',
            'default_tax_1_name' => 'VAT',
            'default_tax_1_rate' => '15',
            'timezone' => self::SHOP_ZONE,
        ];

        foreach ($config as $key => $value) {
            $this->db->query("UPDATE `{$this->prefix}app_config` SET `value` = " . $this->quote($value) . ' WHERE `key` = ' . $this->quote($key));
        }

        $this->stats['last_sale_id'] = $this->nextSaleId - 1;
        $this->stats['last_receiving_id'] = $this->nextReceivingId - 1;
    }

    private function analyse(): void
    {
        foreach (['sales', 'sales_items', 'sales_items_taxes', 'sales_taxes', 'sales_payments', 'receivings', 'receivings_items', 'inventory', 'items', 'customers', 'item_quantities'] as $table) {
            $this->db->query("ANALYZE TABLE `{$this->prefix}{$table}`")->free();
        }
    }
}
