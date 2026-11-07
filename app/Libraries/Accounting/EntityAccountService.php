<?php

namespace App\Libraries\Accounting;

use App\Libraries\Organization\CompanyContext;
use CodeIgniter\Database\BaseBuilder;
use CodeIgniter\Database\BaseConnection;
use Config\Database;
use Throwable;

/**
 * One sub-account for every customer, supplier and stock location, opened under the main
 * account its routing row names. The sub-account carries the entity, so a journal line posted
 * to it is known to belong to that entity without anyone repeating it.
 */
final class EntityAccountService
{
    use RunsInTransaction;

    public const CUSTOMER       = 'customer';
    public const SUPPLIER       = 'supplier';
    public const STOCK_LOCATION = 'stock_location';
    public const TREASURY       = 'treasury';
    private const KEYS          = [
        self::CUSTOMER       => RoutingKey::Customers,
        self::SUPPLIER       => RoutingKey::Suppliers,
        self::STOCK_LOCATION => RoutingKey::StockLocations,
        self::TREASURY       => RoutingKey::Treasuries,
    ];
    private const CODE_DIGITS = [
        self::CUSTOMER       => 3,
        self::SUPPLIER       => 3,
        self::STOCK_LOCATION => 3,
        self::TREASURY       => 2,
    ];
    private const BANK_DIGITS = 3;
    private const CHUNK       = 200;
    private const NAME_MAX    = 255;

    private BaseConnection $db;
    private AuditLog $audit;

    public function __construct(?BaseConnection $db = null)
    {
        $this->db    = $db ?? Database::connect();
        $this->audit = new AuditLog($this->db);
    }

    /**
     * The kinds of entity that exist in the application today.
     *
     * @return list<string>
     */
    public static function kinds(): array
    {
        return [self::CUSTOMER, self::SUPPLIER, self::STOCK_LOCATION];
    }

    public function accountFor(string $kind, int $entityId): ?int
    {
        $row = $this->db->table('accounting_accounts')
            ->select('account_id')
            ->where('company_id', CompanyContext::id())
            ->where('entity_type', $kind)
            ->where('entity_id', $entityId)
            ->get()
            ->getRow();

        return $row === null ? null : (int) $row->account_id;
    }

    /**
     * The entity a sub-account belongs to, or null for any other account.
     *
     * @return array{kind: string, id: int}|null
     */
    public function entityOf(int $accountId): ?array
    {
        $row = $this->db->table('accounting_accounts')->select('entity_type, entity_id')->where('account_id', $accountId)->get()->getRow();

        return $row === null || $row->entity_type === null ? null : ['kind' => (string) $row->entity_type, 'id' => (int) $row->entity_id];
    }

    /**
     * Whether new entities of this kind get a sub-account of their own: while the routing row is
     * automatic, and while it resolves to the account of the entity, which is that sub-account. A
     * row that lets each entity name its account leaves them alone.
     */
    public function isAutomatic(string $kind): bool
    {
        $key = self::KEYS[$kind] ?? null;

        if ($key === null) {
            return false;
        }

        $row = $this->db->table('accounting_routing')
            ->select('routing_type')
            ->where('company_id', CompanyContext::id())
            ->where('routing_key', $key->value)
            ->get()
            ->getRow();

        return in_array($row === null ? $key->defaultType() : RoutingType::tryFrom((string) $row->routing_type), [RoutingType::Auto, RoutingType::MainPerEntity], true);
    }

    /**
     * The sub-account of the entity, opened now when it has none. A bank account of the treasuries
     * is opened under the main account of the banks instead of the one of the cash treasuries.
     *
     * @throws AccountingException
     */
    public function ensure(string $kind, int $entityId, string $name, ?int $employeeId = null, ?RoutingKey $under = null): int
    {
        $existing = $this->accountFor($kind, $entityId);

        if ($existing !== null) {
            return $existing;
        }

        return $this->transactional(function () use ($kind, $entityId, $name, $employeeId, $under): int {
            $parent = $this->mainAccount($kind, $under);
            $last   = $this->lockSequence($parent);

            $existing = $this->lockedAccountFor($kind, $entityId);

            if ($existing !== null) {
                return $existing;
            }

            $code = $this->nextCode($parent, $this->digits($kind, $under), $last);
            $name = $this->uniqueName($this->normalizeName($name, $code), (int) $parent->account_id, $code);

            $this->db->table('accounting_accounts')->insert([
                'company_id'     => CompanyContext::id(),
                'parent_id'      => (int) $parent->account_id,
                'code'           => $code,
                'name'           => $name,
                'account_type'   => $parent->account_type,
                'normal_balance' => $parent->normal_balance,
                'is_group'       => 0,
                'is_system'      => 1,
                'active'         => 1,
                'entity_type'    => $kind,
                'entity_id'      => $entityId,
            ]);

            $accountId = (int) $this->db->insertID();

            $this->audit->write('account', $accountId, 'created', $employeeId, JournalOrigin::System, [
                'code'        => $code,
                'name'        => $name,
                'parent_id'   => (int) $parent->account_id,
                'entity_type' => $kind,
                'entity_id'   => $entityId,
            ]);

            return $accountId;
        });
    }

    /**
     * Moves the sub-account of an entity under another main account, with a code of that account.
     * Only the place in the chart changes: the account keeps its identity, so no journal line moves
     * and its balance stays what it was. Returns false when it already stands there.
     *
     * @throws AccountingException
     */
    public function relocate(string $kind, int $entityId, RoutingKey $under, ?int $employeeId = null): bool
    {
        $account = $this->accountRow($kind, $entityId);

        if ($account === null) {
            return false;
        }

        $parent = $this->mainAccount($kind, $under);

        if ((int) $account->parent_id === (int) $parent->account_id) {
            return false;
        }

        return $this->transactional(function () use ($account, $parent, $kind, $under, $employeeId): bool {
            $last = $this->lockSequence($parent);
            $row  = LockedRead::row($this->db, 'SELECT * FROM ' . $this->db->prefixTable('accounting_accounts') . ' WHERE account_id = ? FOR UPDATE', [$account->account_id]);

            if ($row === null || (int) $row->parent_id === (int) $parent->account_id) {
                return false;
            }

            $code = $this->nextCode($parent, $this->digits($kind, $under), $last);
            $name = $this->uniqueName((string) $row->name, (int) $parent->account_id, $code, (int) $row->account_id);

            $this->db->table('accounting_accounts')->where('account_id', $row->account_id)->update([
                'parent_id' => (int) $parent->account_id,
                'code'      => $code,
                'name'      => $name,
            ]);

            $this->audit->write('account', (int) $row->account_id, 'moved', $employeeId, JournalOrigin::System, [
                'before' => ['parent_id' => (int) $row->parent_id, 'code' => $row->code, 'name' => $row->name],
                'after'  => ['parent_id' => (int) $parent->account_id, 'code' => $code, 'name' => $name],
            ]);

            return true;
        });
    }

    /**
     * Follows the entity's name. A name another account under the same main account already
     * has gets the code after it, so the two can be told apart.
     */
    public function rename(string $kind, int $entityId, string $name, ?int $employeeId = null): void
    {
        $account = $this->accountRow($kind, $entityId);

        if ($account === null) {
            return;
        }

        $name = $this->uniqueName($this->normalizeName($name, $account->code), (int) $account->parent_id, $account->code, (int) $account->account_id);

        if ($name === $account->name) {
            return;
        }

        $this->transactional(function () use ($account, $name, $employeeId): void {
            $this->db->table('accounting_accounts')->where('account_id', $account->account_id)->update(['name' => $name]);
            $this->audit->write('account', (int) $account->account_id, 'updated', $employeeId, JournalOrigin::System, ['before' => ['name' => $account->name], 'after' => ['name' => $name]]);
        });
    }

    /**
     * An entity that was deleted keeps its account for the history. The account stops taking
     * new entries, and with $anonymize its name goes too, because the name was the person's.
     */
    public function retire(string $kind, int $entityId, bool $anonymize, ?int $employeeId = null): void
    {
        $account = $this->accountRow($kind, $entityId);

        if ($account === null) {
            return;
        }

        $changes = ['active' => 0];

        if ($anonymize) {
            $changes['name'] = $account->code;
        }

        $this->transactional(function () use ($account, $changes, $employeeId): void {
            $this->db->table('accounting_accounts')->where('account_id', $account->account_id)->update($changes);
            $this->audit->write('account', (int) $account->account_id, 'deactivated', $employeeId, JournalOrigin::System, ['entity_type' => $account->entity_type, 'entity_id' => (int) $account->entity_id]);
        });
    }

    /**
     * Brings the account of a saved entity in line with it: opens it, renames it, and opens it
     * again if the entity came back. Used by the screens that save entities, which must not
     * fail because of accounting.
     */
    public function sync(string $kind, int $entityId): void
    {
        try {
            if (! (new AccountingSettings($this->db))->isEnabled() || ! $this->isAutomatic($kind)) {
                return;
            }

            $name = $this->entityName($kind, $entityId);

            if ($name === null) {
                return;
            }

            $account = $this->accountRow($kind, $entityId);

            if ($account === null) {
                $this->ensure($kind, $entityId, $name);

                return;
            }

            if ((int) $account->active === 0) {
                $this->transactional(function () use ($account): void {
                    $this->db->table('accounting_accounts')->where('account_id', $account->account_id)->update(['active' => 1]);
                    $this->audit->write('account', (int) $account->account_id, 'activated', null, JournalOrigin::System);
                });
            }

            $this->rename($kind, $entityId, $name);
        } catch (Throwable $exception) {
            log_message('error', "Accounting entity account could not be synced for {$kind} {$entityId}: " . $exception->getMessage());
        }
    }

    public function syncDeleted(string $kind, int $entityId, bool $anonymize = false): void
    {
        try {
            if ((new AccountingSettings($this->db))->isEnabled()) {
                $this->retire($kind, $entityId, $anonymize);
            }
        } catch (Throwable $exception) {
            log_message('error', "Accounting entity account could not be retired for {$kind} {$entityId}: " . $exception->getMessage());
        }
    }

    /**
     * The entities that have no sub-account yet, for the kinds whose routing is automatic.
     *
     * @return array<string, int> Count by kind.
     */
    public function missingCounts(): array
    {
        $counts = [];

        foreach (self::kinds() as $kind) {
            if ($this->isAutomatic($kind)) {
                $counts[$kind] = $this->missingBuilder($kind)->countAllResults();
            }
        }

        return array_filter($counts);
    }

    /**
     * Opens the sub-accounts every existing entity lacks, a chunk at a time, so it can be
     * repeated safely and never holds one long transaction.
     *
     * @return array<string, int> Created by kind.
     *
     * @throws AccountingException
     */
    public function fill(?int $employeeId = null): array
    {
        $created = [];

        foreach (self::kinds() as $kind) {
            if (! $this->isAutomatic($kind)) {
                continue;
            }

            $created[$kind] = 0;

            do {
                $rows = $this->missingBuilder($kind)->select($this->nameSelect($kind) . ' AS entity_name, ' . $this->idColumn($kind) . ' AS entity_id', false)->orderBy($this->idColumn($kind), 'ASC')->limit(self::CHUNK)->get()->getResult();

                foreach ($rows as $row) {
                    $this->ensure($kind, (int) $row->entity_id, (string) $row->entity_name, $employeeId);
                    $created[$kind]++;
                }
            } while (count($rows) === self::CHUNK);
        }

        return $created;
    }

    private function accountRow(string $kind, int $entityId): ?object
    {
        return $this->db->table('accounting_accounts')
            ->where('company_id', CompanyContext::id())
            ->where('entity_type', $kind)
            ->where('entity_id', $entityId)
            ->get()
            ->getRow();
    }

    /**
     * @throws AccountingException
     */
    private function mainAccount(string $kind, ?RoutingKey $under = null): object
    {
        $key = $under ?? self::KEYS[$kind] ?? null;

        if ($key === null) {
            throw new AccountingException(AccountingError::EntityKindUnknown, "There are no accounts for {$kind}.", ['kind' => $kind]);
        }

        $row = $this->db->table('accounting_routing r')
            ->select('a.account_id, a.code, a.account_type, a.normal_balance, a.is_group, a.active')
            ->join('accounting_accounts a', 'a.account_id = r.account_id')
            ->where('r.company_id', CompanyContext::id())
            ->where('r.routing_key', $key->value)
            ->get()
            ->getRow();

        if ($row === null || (int) $row->is_group !== 1 || (int) $row->active === 0) {
            throw new AccountingException(AccountingError::EntityMainAccountInvalid, "The main account for {$kind} is missing or not usable.", ['kind' => $kind]);
        }

        return $row;
    }

    /**
     * Takes the counter of the parent's sub-accounts. It is a locked row of the sequences table,
     * one per parent, held until the transaction ends, so two requests opening accounts under
     * the same parent never run side by side.
     */
    private function lockSequence(object $parent): int
    {
        $companyId = CompanyContext::id();
        $table     = $this->db->prefixTable('accounting_sequences');

        $this->db->table('accounting_sequences')->ignore(true)->insert(['company_id' => $companyId, 'series' => 'sub:' . $parent->account_id, 'last_number' => 0]);

        $row = LockedRead::row($this->db, "SELECT last_number FROM {$table} WHERE company_id = ? AND series = ? FOR UPDATE", [$companyId, 'sub:' . $parent->account_id]);

        return (int) ($row->last_number ?? 0);
    }

    /**
     * The account of the entity as it is committed now, even for a request whose snapshot is
     * older: another request may have opened it while this one waited for the counter.
     */
    private function lockedAccountFor(string $kind, int $entityId): ?int
    {
        $table = $this->db->prefixTable('accounting_accounts');
        $row   = LockedRead::row($this->db, "SELECT account_id FROM {$table} WHERE company_id = ? AND entity_type = ? AND entity_id = ? LOCK IN SHARE MODE", [CompanyContext::id(), $kind, $entityId]);

        return $row === null ? null : (int) $row->account_id;
    }

    /**
     * The next code below the parent: its own code and a counter of fixed width. A code
     * somebody typed in by hand is skipped.
     */
    private function nextCode(object $parent, int $digits, int $last): string
    {
        $companyId = CompanyContext::id();
        $series    = 'sub:' . $parent->account_id;
        $number    = $last;

        do {
            $number++;
            $code = $parent->code . str_pad((string) $number, $digits, '0', STR_PAD_LEFT);
        } while ($this->db->table('accounting_accounts')->where('company_id', $companyId)->where('code', $code)->countAllResults() > 0);

        $this->db->table('accounting_sequences')->where('company_id', $companyId)->where('series', $series)->update(['last_number' => $number]);

        return $code;
    }

    private function digits(string $kind, ?RoutingKey $under): int
    {
        return $under === RoutingKey::Banks ? self::BANK_DIGITS : self::CODE_DIGITS[$kind];
    }

    private function normalizeName(string $name, string $fallback): string
    {
        $name = trim($name);

        return $name === '' ? $fallback : mb_substr($name, 0, self::NAME_MAX - 24);
    }

    private function uniqueName(string $name, int $parentId, string $code, ?int $exceptAccountId = null): string
    {
        $builder = $this->db->table('accounting_accounts')->where('parent_id', $parentId)->where('name', $name);

        if ($exceptAccountId !== null) {
            $builder->where('account_id !=', $exceptAccountId);
        }

        return $builder->countAllResults() > 0 ? "{$name} ({$code})" : $name;
    }

    private function entityName(string $kind, int $entityId): ?string
    {
        $row = $this->entityBuilder($kind)->select($this->nameSelect($kind) . ' AS entity_name', false)->where($this->idColumn($kind), $entityId)->get()->getRow();

        return $row === null ? null : (string) $row->entity_name;
    }

    private function missingBuilder(string $kind): BaseBuilder
    {
        $accounts = $this->db->prefixTable('accounting_accounts');
        $id       = $this->idColumn($kind);

        return $this->entityBuilder($kind)
            ->where("NOT EXISTS (SELECT 1 FROM {$accounts} acc WHERE acc.company_id = " . (int) CompanyContext::id() . ' AND acc.entity_type = ' . $this->db->escape($kind) . " AND acc.entity_id = {$id})", null, false);
    }

    private function entityBuilder(string $kind): BaseBuilder
    {
        return match ($kind) {
            self::CUSTOMER       => $this->db->table('customers c')->join('people p', 'p.person_id = c.person_id')->where('c.deleted', 0),
            self::SUPPLIER       => $this->db->table('suppliers s')->join('people p', 'p.person_id = s.person_id')->where('s.deleted', 0),
            self::STOCK_LOCATION => $this->db->table('stock_locations l')->where('l.deleted', 0),
        };
    }

    private function idColumn(string $kind): string
    {
        return match ($kind) {
            self::CUSTOMER       => 'c.person_id',
            self::SUPPLIER       => 's.person_id',
            self::STOCK_LOCATION => 'l.location_id',
        };
    }

    private function nameSelect(string $kind): string
    {
        return match ($kind) {
            self::CUSTOMER       => "COALESCE(NULLIF(TRIM(c.company_name), ''), TRIM(CONCAT(p.first_name, ' ', p.last_name)))",
            self::SUPPLIER       => "COALESCE(NULLIF(TRIM(s.company_name), ''), TRIM(CONCAT(p.first_name, ' ', p.last_name)))",
            self::STOCK_LOCATION => 'l.location_name',
        };
    }
}
