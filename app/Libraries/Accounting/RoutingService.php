<?php

namespace App\Libraries\Accounting;

use App\Libraries\Organization\BranchException;
use App\Libraries\Organization\BranchGuard;
use App\Libraries\Organization\CompanyContext;
use CodeIgniter\Database\BaseConnection;
use Config\Database;

/**
 * Which account each kind of sales posting uses. The rows are company-wide: one routing for
 * every branch. Reading is open to the screen, writing needs the routing permission and every
 * branch, and every change is audited.
 */
final class RoutingService
{
    use RunsInTransaction;

    private const USAGE_WINDOW = '-1 year';

    private BaseConnection $db;
    private AuditLog $audit;
    private ?AccountingGuard $guard;
    private ?BranchGuard $branches;

    public function __construct(?BaseConnection $db = null, ?AccountingGuard $guard = null, ?BranchGuard $branches = null)
    {
        $this->db       = $db ?? Database::connect();
        $this->audit    = new AuditLog($this->db);
        $this->guard    = $guard;
        $this->branches = $branches;
    }

    /**
     * The rows of the screen: the sales accounts first, then one row for each payment type.
     *
     * @return list<RoutingItem>
     */
    public function items(): array
    {
        $routes   = $this->routes();
        $defaults = $this->defaultAccounts();
        $required = $this->requiredPaymentTypes();
        $items    = [];

        foreach (RoutingKey::sales() as $key) {
            $default = $defaults[$key->defaultSystemKey()] ?? null;

            $items[] = $this->item(
                key: $key->value,
                group: 'sales',
                label: $key->value,
                route: $routes[$key->value] ?? null,
                default: $default,
                required: true,
                locked: false,
                allowedTypes: array_map(static fn (AccountType $type): string => $type->value, $key->allowedAccountTypes()),
            );
        }

        foreach (PaymentType::cases() as $type) {
            $lockedKey = $type->lockedRoutingKey();
            $isLocked  = $lockedKey !== null;
            $default   = $isLocked ? null : ($defaults[(string) $type->defaultSystemKey()] ?? null);
            $route     = $routes[$isLocked ? $lockedKey->value : $type->routingKey()] ?? null;

            $items[] = $this->item(
                key: $type->routingKey(),
                group: 'payments',
                label: $type->value,
                route: $route,
                default: $default,
                required: $isLocked || in_array($type, $required, true),
                locked: $isLocked,
                allowedTypes: array_map(static fn (AccountType $accountType): string => $accountType->value, $type->allowedAccountTypes()),
                lockedTo: $isLocked ? $lockedKey->value : null,
            );
        }

        return $items;
    }

    /**
     * Saves every row sent, or none: each row is checked first and the problems come back
     * together. A row that is not sent is left as it is.
     *
     * @param array<string, int|string|null> $input Account id by routing key. An empty value clears an optional payment row.
     *
     * @throws AccountingException|BranchException
     */
    public function save(array $input, ?int $employeeId = null): void
    {
        $this->guard ??= new AccountingGuard();
        $this->branches ??= new BranchGuard($this->db);

        $this->guard->require(AccountingGuard::ROUTING_MANAGE, $employeeId);
        $this->branches->requireCompanyScope($employeeId);

        $routes   = $this->routes();
        $accounts = $this->accountsById();
        $required = $this->requiredPaymentTypes();
        $errors   = [];
        $changes  = [];

        foreach ($input as $key => $value) {
            $key        = (string) $key;
            $accountId  = $this->accountId($value);
            $salesKey   = RoutingKey::tryFrom($key);
            $paymentKey = PaymentType::fromRoutingKey($key);

            if ($salesKey !== null && in_array($salesKey, RoutingKey::sales(), true)) {
                $problem = $this->problem($accountId, $accounts, true, $salesKey->allowedAccountTypes(), [$salesKey->defaultSystemKey()]);
            } elseif ($paymentKey !== null && ! $paymentKey->isLocked()) {
                $problem = $this->problem($accountId, $accounts, in_array($paymentKey, $required, true), $paymentKey->allowedAccountTypes(), $paymentKey->acceptedSystemKeys());
            } else {
                $problem = AccountingError::RoutingKeyUnknown;
            }

            if ($problem !== null) {
                $errors[$key] = $problem;

                continue;
            }

            $current = isset($routes[$key]) && $routes[$key]->account_id !== null ? (int) $routes[$key]->account_id : null;

            if ($current !== $accountId) {
                $changes[$key] = ['before' => $current, 'after' => $accountId];
            }
        }

        if ($errors !== []) {
            throw new RoutingException($errors);
        }

        if ($changes === []) {
            return;
        }

        $this->transactional(function () use ($changes, $accounts, $employeeId): void {
            $companyId = CompanyContext::id();

            foreach ($changes as $key => $change) {
                $exists = $this->db->table('accounting_routing')->where('company_id', $companyId)->where('routing_key', $key)->countAllResults() > 0;

                if ($exists) {
                    $this->db->table('accounting_routing')->where('company_id', $companyId)->where('routing_key', $key)->update(['account_id' => $change['after']]);
                } else {
                    $this->db->table('accounting_routing')->insert(['company_id' => $companyId, 'routing_key' => $key, 'account_id' => $change['after']]);
                }

                $this->audit->write('routing', null, 'routing_changed', $employeeId, JournalOrigin::User, [
                    'key'    => $key,
                    'before' => $this->describeAccount($change['before'], $accounts),
                    'after'  => $this->describeAccount($change['after'], $accounts),
                ]);
            }
        });
    }

    /**
     * The postable accounts a row of this kind may point at, by code.
     *
     * @param list<string> $types Account types as stored.
     *
     * @return list<object{account_id: int, code: string, name: string}>
     */
    public function candidates(array $types): array
    {
        if ($types === []) {
            return [];
        }

        return $this->db->table('accounting_accounts')
            ->select('account_id, code, name')
            ->where('company_id', CompanyContext::id())
            ->where('is_group', 0)
            ->where('active', 1)
            ->whereIn('account_type', $types)
            ->orderBy('code', 'ASC')
            ->get()
            ->getResult();
    }

    /**
     * The payment types accounting needs a routing for: the ones the sales screen offers, the
     * ones a sale of the last year used, and the ones that are always offered.
     *
     * @return list<PaymentType>
     */
    public function requiredPaymentTypes(): array
    {
        $types = [];

        foreach ([PaymentType::Giftcard, PaymentType::Rewards, ...$this->offeredPaymentTypes()] as $type) {
            $types[$type->value] = $type;
        }

        foreach (array_keys($this->usedPaymentLabels()) as $label) {
            $type = PaymentType::fromLabel((string) $label);

            if ($type !== null) {
                $types[$type->value] = $type;
            }
        }

        return array_values($types);
    }

    /**
     * What stops accounting from being turned on: a payment type in use with no usable account,
     * and a stock location sold from that belongs to no active branch.
     *
     * @return list<ReadinessIssue>
     */
    public function blockingIssues(): array
    {
        $issues = [];

        foreach ($this->items() as $item) {
            if ($item->group !== 'payments' || $item->locked) {
                continue;
            }

            if ($item->status === RoutingItem::MISSING) {
                $issues[] = new ReadinessIssue(ReadinessCode::PaymentTypeUnrouted, $item->label);
            } elseif ($item->status === RoutingItem::INVALID) {
                $issues[] = new ReadinessIssue(ReadinessCode::RoutingAccountInvalid, $item->key);
            }
        }

        foreach ($this->soldFromLocationsWithoutBranch() as $name) {
            $issues[] = new ReadinessIssue(ReadinessCode::StockLocationWithoutBranch, $name);
        }

        return $issues;
    }

    /**
     * Things worth knowing that do not stop accounting from being turned on.
     *
     * @return list<ReadinessIssue>
     */
    public function notices(?string $startDate): array
    {
        $notices = [];

        foreach (array_keys($this->usedPaymentLabels()) as $label) {
            if (PaymentType::fromLabel($label) === null) {
                $notices[] = new ReadinessIssue(ReadinessCode::PaymentTypeUnknown, $label);
            }
        }

        $taxes = $this->db->table('sales_items_taxes t')
            ->select('t.name, t.percent')
            ->join('sales s', 's.sale_id = t.sale_id')
            ->where('s.sale_status', COMPLETED)
            ->where('s.sale_time >=', date('Y-m-d H:i:s', strtotime(self::USAGE_WINDOW)))
            ->groupBy('t.name, t.percent')
            ->get()
            ->getNumRows();

        if ($taxes > 1) {
            $notices[] = new ReadinessIssue(ReadinessCode::TaxOutputSharedAccount, (string) $taxes);
        }

        if ($startDate !== null) {
            $earlier = $this->db->table('sales')
                ->where('sale_status', COMPLETED)
                ->where('sale_time <', $startDate . ' 00:00:00')
                ->countAllResults();

            if ($earlier > 0) {
                $notices[] = new ReadinessIssue(ReadinessCode::SalesBeforeStartDate, (string) $earlier);
            }
        }

        return $notices;
    }

    /**
     * @return array<string, object> Routing row and its account, by routing key.
     */
    private function routes(): array
    {
        $rows = $this->db->table('accounting_routing r')
            ->select('r.routing_key, r.account_id, a.code, a.name, a.is_group, a.active')
            ->join('accounting_accounts a', 'a.account_id = r.account_id', 'left')
            ->where('r.company_id', CompanyContext::id())
            ->get()
            ->getResult();

        $byKey = [];

        foreach ($rows as $row) {
            $byKey[$row->routing_key] = $row;
        }

        return $byKey;
    }

    /**
     * @return array<string, object{account_id: int, code: string, name: string}> By system key.
     */
    private function defaultAccounts(): array
    {
        $rows = $this->db->table('accounting_accounts')
            ->select('account_id, code, name, system_key')
            ->where('company_id', CompanyContext::id())
            ->where('system_key IS NOT NULL')
            ->get()
            ->getResult();

        $byKey = [];

        foreach ($rows as $row) {
            $byKey[$row->system_key] = $row;
        }

        return $byKey;
    }

    /**
     * @return array<int, object>
     */
    private function accountsById(): array
    {
        $rows = $this->db->table('accounting_accounts')
            ->select('account_id, code, name, account_type, is_group, active, system_key')
            ->where('company_id', CompanyContext::id())
            ->get()
            ->getResult();

        $byId = [];

        foreach ($rows as $row) {
            $byId[(int) $row->account_id] = $row;
        }

        return $byId;
    }

    /**
     * @param list<string> $allowedTypes
     */
    private function item(string $key, string $group, string $label, ?object $route, ?object $default, bool $required, bool $locked, array $allowedTypes, ?string $lockedTo = null): RoutingItem
    {
        $mapped = $route !== null && $route->account_id !== null;

        if (! $mapped) {
            $status = $required ? RoutingItem::MISSING : RoutingItem::OPTIONAL;
        } elseif ($route->is_group === null || (int) $route->is_group === 1 || (int) $route->active === 0) {
            $status = RoutingItem::INVALID;
        } else {
            $status = RoutingItem::COMPLETE;
        }

        return new RoutingItem(
            key: $key,
            group: $group,
            label: $label,
            accountId: $mapped ? (int) $route->account_id : null,
            accountCode: $mapped ? (string) $route->code : null,
            accountName: $mapped ? (string) $route->name : null,
            defaultAccountId: $default === null ? null : (int) $default->account_id,
            defaultAccountCode: $default?->code,
            defaultAccountName: $default?->name,
            status: $status,
            required: $required,
            locked: $locked,
            allowedTypes: $allowedTypes,
            lockedTo: $lockedTo,
        );
    }

    /**
     * @param array<int, object> $accounts
     * @param list<AccountType>  $allowedTypes
     * @param list<string>       $acceptedSystemKeys
     */
    private function problem(?int $accountId, array $accounts, bool $required, array $allowedTypes, array $acceptedSystemKeys): ?AccountingError
    {
        if ($accountId === null) {
            return $required ? AccountingError::RoutingAccountRequired : null;
        }

        $account = $accounts[$accountId] ?? null;

        return match (true) {
            $account === null                                                                            => AccountingError::AccountNotFound,
            (int) $account->is_group === 1                                                               => AccountingError::RoutingAccountNotPostable,
            (int) $account->active === 0                                                                 => AccountingError::RoutingAccountInactive,
            ! in_array(AccountType::from($account->account_type), $allowedTypes, true)                   => AccountingError::RoutingAccountTypeInvalid,
            $account->system_key !== null && ! in_array($account->system_key, $acceptedSystemKeys, true) => AccountingError::RoutingAccountReserved,
            default                                                                                      => null,
        };
    }

    private function accountId(mixed $value): ?int
    {
        if (! is_scalar($value) || trim((string) $value) === '' || ! ctype_digit(trim((string) $value))) {
            return null;
        }

        $id = (int) trim((string) $value);

        return $id > 0 ? $id : null;
    }

    /**
     * @param array<int, object> $accounts
     *
     * @return array{account_id: int, code: string, name: string}|null
     */
    private function describeAccount(?int $accountId, array $accounts): ?array
    {
        $account = $accountId === null ? null : ($accounts[$accountId] ?? null);

        return $account === null ? null : ['account_id' => (int) $account->account_id, 'code' => (string) $account->code, 'name' => (string) $account->name];
    }

    /**
     * @return list<PaymentType>
     */
    private function offeredPaymentTypes(): array
    {
        helper('locale');

        $types = [];

        foreach (array_keys(get_payment_options()) as $label) {
            $type = PaymentType::fromLabel((string) $label);

            if ($type !== null) {
                $types[] = $type;
            }
        }

        return $types;
    }

    /**
     * @return array<string, true> The stored payment names of the completed sales of the last year.
     */
    private function usedPaymentLabels(): array
    {
        $rows = $this->db->table('sales_payments p')
            ->distinct()
            ->select('p.payment_type')
            ->join('sales s', 's.sale_id = p.sale_id')
            ->where('s.sale_status', COMPLETED)
            ->where('s.sale_time >=', date('Y-m-d H:i:s', strtotime(self::USAGE_WINDOW)))
            ->get()
            ->getResultArray();

        $labels = [];

        foreach (array_column($rows, 'payment_type') as $label) {
            $name = trim(explode(':', (string) $label, 2)[0]);

            if ($name !== '') {
                $labels[$name] = true;
            }
        }

        return $labels;
    }

    /**
     * @return list<string>
     */
    private function soldFromLocationsWithoutBranch(): array
    {
        $rows = $this->db->table('stock_locations l')
            ->distinct()
            ->select('l.location_name')
            ->join('branches b', 'b.branch_id = l.branch_id', 'left')
            ->join('sales_items i', 'i.item_location = l.location_id')
            ->where('l.deleted', 0)
            ->groupStart()
            ->where('b.branch_id IS NULL')
            ->orWhere('b.active', 0)
            ->groupEnd()
            ->orderBy('l.location_name', 'ASC')
            ->get()
            ->getResultArray();

        return array_column($rows, 'location_name');
    }
}
