<?php

namespace App\Libraries\Assets;

use App\Libraries\Accounting\AccountingError;
use App\Libraries\Accounting\AccountingException;
use App\Libraries\Accounting\AccountingSettings;
use App\Libraries\Accounting\RoutingKey;
use App\Libraries\Accounting\RoutingResolver;
use App\Libraries\Numbering\DocumentNumbering;
use App\Libraries\Organization\CompanyContext;
use CodeIgniter\Database\BaseConnection;
use DateTimeImmutable;

/**
 * What a form of an asset says, checked and cut to what the tables hold. The rules of the form of the
 * reference system are here: the fields each method takes (a method that does not take a field has it
 * cleared), the first depreciation date that defaults to the day of going into service plus a period,
 * and the rules of the units of an asset (a serial number or a barcode each, both unique, an employee
 * when the asset is with one). A field the form does not say how to default is derived as the notes
 * say.
 */
final class AssetInput
{
    public const TERM_FIELDS = [
        'branch_id', 'purchase_date', 'in_service_date', 'cost', 'salvage', 'method', 'period_length', 'period_interval', 'amount_per_period',
        'percentage', 'cost_per_unit', 'total_units', 'first_depreciation_date', 'end_date', 'cost_account_id', 'accumulated_account_id',
        'expense_account_id', 'funding', 'treasury_id', 'opening_accumulated', 'opening_through',
    ];

    private const AMOUNT_PATTERN = '/^\d{1,11}(\.\d{1,4})?$/';
    private const LIFE_MAX       = 1200;
    private const QUANTITY_MAX   = 10000;
    private const TEXT_MAX       = 255;
    private const SCALE          = 4;

    public function __construct(
        private readonly BaseConnection $db,
        private readonly DepreciationSchedule $schedule,
        private readonly AccountingSettings $settings,
        private readonly RoutingResolver $routing,
    ) {
    }

    /**
     * @param array<string, mixed> $input
     *
     * @return array{row: array<string, mixed>, units: list<array<string, mixed>>}
     *
     * @throws AccountingException
     */
    public function prepare(array $input, ?object $existing): array
    {
        $name = $this->text($input['name'] ?? '', 'name', self::TEXT_MAX, true);
        $code = DocumentNumbering::typed(trim((string) ($input['code'] ?? '')));

        if (mb_strlen($code) > 64) {
            $this->fail('code');
        }

        $purchase  = $this->date($input['purchase_date'] ?? '', 'purchase_date');
        $inService = $this->date($input['in_service_date'] ?? '', 'in_service_date');
        $method    = AssetMethod::tryFrom(trim((string) ($input['method'] ?? ''))) ?? $this->fail('method');
        $cost      = $this->amount($input['cost'] ?? '', 'cost', false);
        $salvage   = $method === AssetMethod::None ? bcadd('0', '0', self::SCALE) : $this->amount($input['salvage'] ?? '0', 'salvage', true);

        if (bccomp($salvage, $cost, self::SCALE) > 0) {
            $this->fail('salvage');
        }

        $base   = bcsub($cost, $salvage, self::SCALE);
        $life   = $this->lifeMonths($input);
        $period = $this->period($input, $method);

        $category = $this->category($input['category_id'] ?? null);
        $funding  = $this->funding($input);
        $opening  = $funding === 'opening' || $funding === 'none';

        $accumulated = $opening ? $this->amount($input['opening_accumulated'] ?? '0', 'opening_accumulated', true) : $this->noOpeningAmount($input);

        if ($method === AssetMethod::None && bccomp($accumulated, '0', self::SCALE) > 0) {
            $this->fail('opening_accumulated');
        }

        if (bccomp($accumulated, $base, self::SCALE) > 0) {
            $this->fail('opening_accumulated');
        }

        $through = $opening ? $this->openingThrough() : null;

        $terms = $this->terms($input, $method, $period, $life, $inService, $purchase, $opening, $base);

        $row = [
            'name'                    => $name,
            'code'                    => $code,
            'category_id'             => $category === null ? null : (int) $category->category_id,
            'description'             => $this->text($input['description'] ?? '', 'description', 1000, false),
            'supplier_id'             => $this->supplier($input['supplier_id'] ?? null),
            'purchase_reference'      => $this->text($input['purchase_reference'] ?? '', 'purchase_reference', 100, false),
            'cost_center_id'          => $this->costCenter($input['cost_center_id'] ?? null),
            'purchase_date'           => $purchase,
            'in_service_date'         => $inService,
            'cost'                    => $cost,
            'salvage'                 => $salvage,
            'useful_life_months'      => $life,
            'method'                  => $method->value,
            'period_length'           => $terms['period_length'],
            'period_interval'         => $terms['period_interval'],
            'amount_per_period'       => $terms['amount_per_period'],
            'percentage'              => $terms['percentage'],
            'cost_per_unit'           => $terms['cost_per_unit'],
            'unit_name'               => $terms['unit_name'],
            'total_units'             => $terms['total_units'],
            'first_depreciation_date' => $terms['first_depreciation_date'],
            'end_date'                => $terms['end_date'],
            'cost_account_id'         => $this->accountOf($input['cost_account_id'] ?? null, $category?->cost_account_id, RoutingKey::AssetCost, AssetAccountRules::COST),
            'accumulated_account_id'  => $method->depreciates() ? $this->accountOf($input['accumulated_account_id'] ?? null, $category?->accumulated_account_id, RoutingKey::AssetAccumulated, AssetAccountRules::ACCUMULATED) : null,
            'expense_account_id'      => $method->depreciates() ? $this->accountOf($input['expense_account_id'] ?? null, $category?->expense_account_id, RoutingKey::AssetExpense, AssetAccountRules::EXPENSE) : null,
            'funding'                 => $funding,
            'treasury_id'             => $funding === 'treasury' ? $this->treasury($input['treasury_id'] ?? null) : null,
            'opening_accumulated'     => $accumulated,
            'opening_through'         => $through,
        ];

        if ($row['accumulated_account_id'] !== null && $row['accumulated_account_id'] === $row['cost_account_id']) {
            throw new AccountingException(AccountingError::AssetAccountInvalid, 'The accumulated depreciation cannot be kept in the cost account.', ['name' => AssetAccountRules::ACCUMULATED, 'code' => (string) $row['cost_account_id']]);
        }

        [$quantity, $tracked, $units] = $this->units($input, $existing);

        $row['quantity']    = $quantity;
        $row['track_units'] = $tracked ? 1 : 0;

        return ['row' => $row, 'units' => $units];
    }

    /**
     * @param array<string, mixed>                        $input
     * @param array{length: int, interval: AssetInterval} $period
     *
     * @return array{period_length: int, period_interval: string, amount_per_period: ?string, percentage: ?string, cost_per_unit: ?string, unit_name: string, total_units: ?string, first_depreciation_date: ?string, end_date: ?string}
     *
     * @throws AccountingException
     */
    private function terms(array $input, AssetMethod $method, array $period, ?int $life, string $inService, string $purchase, bool $opening, string $base): array
    {
        $terms = [
            'period_length'           => 1,
            'period_interval'         => AssetInterval::Month->value,
            'amount_per_period'       => null,
            'percentage'              => null,
            'cost_per_unit'           => null,
            'unit_name'               => '',
            'total_units'             => null,
            'first_depreciation_date' => null,
            'end_date'                => null,
        ];

        if ($method === AssetMethod::None) {
            return $terms;
        }

        $end = $this->optionalDate($input['end_date'] ?? '', 'end_date') ?? ($life === null ? null : DepreciationSchedule::addInterval($inService, AssetInterval::Month, $life));

        if ($method === AssetMethod::Units) {
            $cost = $this->amount($input['cost_per_unit'] ?? '', 'cost_per_unit', false, 6);

            $units = trim((string) ($input['total_units'] ?? ''));

            if ($units !== '' && (preg_match('/^\d{1,13}(\.\d{1,2})?$/', $units) !== 1 || bccomp($units, '0', 2) <= 0)) {
                $this->fail('total_units');
            }

            if ($end !== null && $end < $inService) {
                $this->fail('end_date');
            }

            return [...$terms, 'cost_per_unit' => $cost, 'unit_name' => $this->text($input['unit_name'] ?? '', 'unit_name', 100, false), 'total_units' => $units === '' ? null : bcadd($units, '0', 2), 'end_date' => $end];
        }

        $terms['period_length']   = $period['length'];
        $terms['period_interval'] = $period['interval']->value;

        $first = $this->optionalDate($input['first_depreciation_date'] ?? '', 'first_depreciation_date') ?? DepreciationSchedule::defaultFirstDate($inService, $period['length'], $period['interval']);

        if ($first < $inService || (! $opening && $first < $purchase)) {
            throw new AccountingException(AccountingError::AssetFirstDateInvalid, 'The first depreciation cannot be before the asset went into service or was bought.', ['date' => $first]);
        }

        if ($end !== null && $end < $first) {
            $this->fail('end_date');
        }

        $terms['first_depreciation_date'] = $first;
        $terms['end_date']                = $end;

        if ($method === AssetMethod::Declining) {
            $percentage = trim((string) ($input['percentage'] ?? ''));

            if (preg_match('/^\d{1,3}(\.\d{1,4})?$/', $percentage) !== 1 || bccomp($percentage, '0', self::SCALE) <= 0 || bccomp($percentage, '100', self::SCALE) > 0) {
                $this->fail('percentage');
            }

            $terms['percentage'] = bcadd($percentage, '0', self::SCALE);

            return $terms;
        }

        $amount = trim((string) ($input['amount_per_period'] ?? ''));

        if ($amount === '') {
            $amount = $this->derivedAmount($base, $life, $period) ?? $this->fail('amount_per_period');
        }

        $terms['amount_per_period'] = $this->amount($amount, 'amount_per_period', false);

        return $terms;
    }

    /**
     * The amount of a period that spreads what is to be depreciated over the life when the form gives
     * the life and no amount: only for months, which a life is counted in.
     *
     * @param array{length: int, interval: AssetInterval} $period
     */
    private function derivedAmount(string $base, ?int $life, array $period): ?string
    {
        if ($life === null || $period['interval'] !== AssetInterval::Month) {
            return null;
        }

        $periods = max(1, intdiv($life, $period['length']));

        return bcdiv($base, (string) $periods, 4);
    }

    /**
     * @param array<string, mixed> $input
     *
     * @return array{length: int, interval: AssetInterval}
     */
    private function period(array $input, AssetMethod $method): array
    {
        if (! $method->isScheduled()) {
            return ['length' => 1, 'interval' => AssetInterval::Month];
        }

        $length = trim((string) ($input['period_length'] ?? ''));
        $length = $length === '' ? '1' : $length;

        if (preg_match('/^\d{1,3}$/', $length) !== 1 || (int) $length < 1) {
            $this->fail('period_length');
        }

        return ['length' => (int) $length, 'interval' => AssetInterval::tryFrom(trim((string) ($input['period_interval'] ?? '')) ?: AssetInterval::Month->value) ?? $this->fail('period_interval')];
    }

    /**
     * @param array<string, mixed> $input
     */
    private function lifeMonths(array $input): ?int
    {
        $months = trim((string) ($input['useful_life_months'] ?? ''));
        $value  = trim((string) ($input['useful_life_value'] ?? ''));

        if ($months === '' && $value === '') {
            return null;
        }

        if ($months === '') {
            if (preg_match('/^\d{1,4}$/', $value) !== 1) {
                $this->fail('useful_life_value');
            }

            $months = (string) ((int) $value * (trim((string) ($input['useful_life_unit'] ?? 'months')) === 'years' ? 12 : 1));
        }

        if (preg_match('/^\d{1,5}$/', $months) !== 1 || (int) $months < 1 || (int) $months > self::LIFE_MAX) {
            $this->fail('useful_life_months');
        }

        return (int) $months;
    }

    /**
     * @param array<string, mixed> $input
     */
    private function funding(array $input): string
    {
        $funding = trim((string) ($input['funding'] ?? ''));

        if (! in_array($funding, ['treasury', 'payable', 'opening', 'none'], true)) {
            throw new AccountingException(AccountingError::AssetFundingInvalid, 'The asset is paid from a treasury, owed, brought in as an opening balance, or already in the books.', ['name' => $funding]);
        }

        return $funding;
    }

    /**
     * @param array<string, mixed> $input
     *
     * @throws AccountingException
     */
    private function noOpeningAmount(array $input): string
    {
        if (bccomp($this->amount($input['opening_accumulated'] ?? '0', 'opening_accumulated', true), '0', self::SCALE) > 0) {
            throw new AccountingException(AccountingError::AssetFundingInvalid, 'Only an asset brought in as an opening balance has depreciation already.', ['name' => 'opening_accumulated']);
        }

        return bcadd('0', '0', self::SCALE);
    }

    /**
     * The last day the depreciation an old asset arrives with covers: the day before the books start.
     *
     * @throws AccountingException
     */
    private function openingThrough(): string
    {
        $start = $this->settings->startDate();

        if ($start === null) {
            throw new AccountingException(AccountingError::OpeningStartDateMissing, 'The accounting start date is not set.');
        }

        return (new DateTimeImmutable($start))->modify('-1 day')->format('Y-m-d');
    }

    private function category(mixed $categoryId): ?object
    {
        $id = (int) $categoryId;

        if ($id === 0) {
            return null;
        }

        $category = $this->db->table('asset_categories')->where('company_id', CompanyContext::id())->where('category_id', $id)->where('active', 1)->get()->getRow();

        if ($category === null) {
            throw new AccountingException(AccountingError::AssetCategoryNotFound, "Asset category {$id} does not exist or is switched off.", ['name' => (string) $id]);
        }

        return $category;
    }

    private function supplier(mixed $supplierId): ?int
    {
        $id = (int) $supplierId;

        if ($id === 0) {
            return null;
        }

        if ($this->db->table('suppliers')->where('person_id', $id)->where('deleted', 0)->countAllResults() === 0) {
            $this->fail('supplier_id');
        }

        return $id;
    }

    private function costCenter(mixed $costCenterId): ?int
    {
        $id = (int) $costCenterId;

        if ($id === 0) {
            return null;
        }

        if ($this->db->table('accounting_cost_centers')->where('company_id', CompanyContext::id())->where('cost_center_id', $id)->where('active', 1)->countAllResults() === 0) {
            $this->fail('cost_center_id');
        }

        return $id;
    }

    private function treasury(mixed $treasuryId): int
    {
        $id = (int) $treasuryId;

        if ($id === 0 || $this->db->table('treasuries')->where('company_id', CompanyContext::id())->where('treasury_id', $id)->where('active', 1)->countAllResults() === 0) {
            throw new AccountingException(AccountingError::AssetFundingInvalid, 'An asset paid from a treasury names an active one.', ['name' => 'treasury_id']);
        }

        return $id;
    }

    private function accountOf(mixed $chosen, mixed $fromCategory, RoutingKey $key, string $role): int
    {
        $id = (int) $chosen;

        if ($id === 0 && $fromCategory !== null) {
            $id = (int) $fromCategory;
        }

        if ($id === 0) {
            $id = (int) $this->routing->accountFor($key);
        }

        if ($id === 0) {
            throw new AccountingException(AccountingError::AssetAccountInvalid, "The {$role} account of the asset is not chosen and no routing names one.", ['name' => $role, 'code' => '']);
        }

        AssetAccountRules::require($this->db, $id, $role);

        return $id;
    }

    /**
     * @param array<string, mixed> $input
     *
     * @return array{0: int, 1: bool, 2: list<array<string, mixed>>}
     *
     * @throws AccountingException
     */
    private function units(array $input, ?object $existing): array
    {
        $tracked  = ($input['track_units'] ?? '') === '1' || ($input['track_units'] ?? false) === true || ($input['track_units'] ?? 0) === 1;
        $quantity = trim((string) ($input['quantity'] ?? ''));
        $quantity = $quantity === '' ? '1' : $quantity;

        if (preg_match('/^\d{1,5}$/', $quantity) !== 1 || (int) $quantity < 1 || (int) $quantity > self::QUANTITY_MAX) {
            $this->fail('quantity');
        }

        $raw   = is_array($input['units'] ?? null) ? array_values($input['units']) : [];
        $units = [];
        $seen  = ['serial' => [], 'barcode' => []];

        if (($tracked && count($raw) !== (int) $quantity) || (! $tracked && count($raw) > 1)) {
            throw new AccountingException(AccountingError::AssetUnitsMismatch, 'An asset that defines its units has one row for each unit.', ['count' => (int) $quantity]);
        }

        foreach ($raw as $position => $row) {
            $row     = is_array($row) ? $row : [];
            $serial  = $this->text($row['serial_number'] ?? '', 'serial_number', 255, false);
            $barcode = $this->text($row['barcode'] ?? '', 'barcode', 255, false);

            if ($tracked && $serial === '' && $barcode === '') {
                throw new AccountingException(AccountingError::AssetUnitIdentityRequired, 'Each unit needs a serial number or a barcode.', ['line' => $position + 1]);
            }

            foreach (['serial' => $serial, 'barcode' => $barcode] as $kind => $value) {
                if ($value === '') {
                    continue;
                }

                if (isset($seen[$kind][mb_strtolower($value)]) || $this->taken($kind === 'serial' ? 'serial_number' : 'barcode', $value, $existing)) {
                    throw new AccountingException($kind === 'serial' ? AccountingError::AssetSerialTaken : AccountingError::AssetBarcodeTaken, "The {$kind} {$value} is used already.", ['name' => $value]);
                }

                $seen[$kind][mb_strtolower($value)] = true;
            }

            $units[] = [...$this->custody($row, $position + 1), 'position' => $position + 1, 'serial_number' => $serial === '' ? null : $serial, 'barcode' => $barcode === '' ? null : $barcode, 'physical_location' => $this->text($row['physical_location'] ?? '', 'physical_location', 255, false)];
        }

        return [(int) $quantity, $tracked, $units];
    }

    /**
     * @param array<string, mixed> $row
     *
     * @return array{used_by: string, employee_id: ?int, stock_location_id: ?int}
     */
    private function custody(array $row, int $line): array
    {
        $usedBy = trim((string) ($row['used_by'] ?? 'not_assigned'));

        if (! in_array($usedBy, ['not_assigned', 'employee', 'storage'], true)) {
            $this->fail('used_by');
        }

        $employeeId = (int) ($row['employee_id'] ?? 0);
        $locationId = (int) ($row['stock_location_id'] ?? 0);

        if ($usedBy === 'employee') {
            if ($employeeId === 0 || $this->db->table('employees')->where('person_id', $employeeId)->where('deleted', 0)->countAllResults() === 0) {
                throw new AccountingException(AccountingError::AssetCustodyEmployee, 'An asset with an employee names the employee.', ['line' => $line]);
            }

            return ['used_by' => $usedBy, 'employee_id' => $employeeId, 'stock_location_id' => null];
        }

        if ($usedBy === 'storage') {
            if ($locationId === 0 || $this->db->table('stock_locations')->where('location_id', $locationId)->where('deleted', 0)->countAllResults() === 0) {
                throw new AccountingException(AccountingError::AssetCustodyStorage, 'An asset kept in storage names the storage.', ['line' => $line]);
            }

            return ['used_by' => $usedBy, 'employee_id' => null, 'stock_location_id' => $locationId];
        }

        return ['used_by' => $usedBy, 'employee_id' => null, 'stock_location_id' => null];
    }

    private function taken(string $column, string $value, ?object $existing): bool
    {
        $builder = $this->db->table('asset_units u')
            ->join('assets a', 'a.asset_id = u.asset_id')
            ->where('u.company_id', CompanyContext::id())
            ->where("u.{$column}", $value)
            ->where('a.deleted', 0);

        if ($existing !== null) {
            $builder->where('u.asset_id !=', (int) $existing->asset_id);
        }

        return $builder->countAllResults() > 0;
    }

    private function text(mixed $value, string $field, int $max, bool $required): string
    {
        $text = trim(is_scalar($value) ? (string) $value : '');

        if (($required && $text === '') || mb_strlen($text) > $max) {
            $this->fail($field);
        }

        return $text;
    }

    private function date(mixed $value, string $field): string
    {
        return $this->optionalDate($value, $field) ?? $this->fail($field);
    }

    private function optionalDate(mixed $value, string $field): ?string
    {
        $text = trim(is_scalar($value) ? (string) $value : '');

        if ($text === '') {
            return null;
        }

        $parsed = DateTimeImmutable::createFromFormat('!Y-m-d', $text);

        if ($parsed === false || $parsed->format('Y-m-d') !== $text || $parsed->format('Y') < '1900' || $parsed->format('Y') > '2200') {
            $this->fail($field);
        }

        return $text;
    }

    /**
     * @return numeric-string
     */
    private function amount(mixed $value, string $field, bool $allowZero, int $scale = self::SCALE): string
    {
        $text = trim(is_scalar($value) ? (string) $value : '');

        if ($text === '' && $allowZero) {
            $text = '0';
        }

        $pattern = $scale === 6 ? '/^\d{1,9}(\.\d{1,6})?$/' : self::AMOUNT_PATTERN;

        if (preg_match($pattern, $text) !== 1 || (! $allowZero && bccomp($text, '0', $scale) <= 0)) {
            $this->fail($field);
        }

        return bcadd($text, '0', $scale);
    }

    private function fail(string $field): never
    {
        throw new AccountingException(AccountingError::AssetFieldInvalid, "The value of {$field} is not valid.", ['name' => $field]);
    }
}
