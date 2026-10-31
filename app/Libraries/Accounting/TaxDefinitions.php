<?php

namespace App\Libraries\Accounting;

use App\Libraries\Organization\BranchException;
use App\Libraries\Organization\BranchGuard;
use CodeIgniter\Database\BaseConnection;
use Config\Database;

/**
 * The tax list: each tax is known by its name, which is what the taxes of an item carry, and may
 * have a mode of its own (the tax is inside the price or on top of it) and a liability account of its
 * own. A tax without a mode follows the default mode of the shop and one without an account goes to
 * the account of tax collected the routing names. Which account a tax goes to is a routing decision,
 * so it asks for the permission to change the routing and for every branch, and a change is audited.
 * The name of a tax does not change, because the items hold it. A document keeps the mode and the
 * account its taxes were worked out with, so nothing here touches a document that was saved.
 */
final class TaxDefinitions
{
    use RunsInTransaction;

    public const ENTITY    = 'tax_definition';
    public const DEFAULT   = '';
    public const INCLUDED  = 'included';
    public const EXCLUDED  = 'excluded';
    private const NAME_MAX = 255;

    private BaseConnection $db;
    private AccountingGuard $guard;
    private BranchGuard $branches;
    private AuditLog $audit;

    public function __construct(?BaseConnection $db = null, ?AccountingGuard $guard = null, ?BranchGuard $branches = null)
    {
        $this->db       = $db ?? Database::connect();
        $this->guard    = $guard ?? new AccountingGuard();
        $this->branches = $branches ?? new BranchGuard($this->db);
        $this->audit    = new AuditLog($this->db);
    }

    /**
     * Saves a tax. The mode and the account are changed only when the input names them, and a value
     * the tax already has changes nothing and asks for nothing.
     *
     * @param array{tax_name?: string, tax_mode?: string, account_id?: int|string|null} $input
     *
     * @return int The tax.
     *
     * @throws AccountingException|BranchException
     */
    public function save(array $input, ?int $definitionId = null, ?int $employeeId = null): int
    {
        $stored = $definitionId === null ? null : $this->stored($definitionId);
        $name   = $stored === null ? trim((string) ($input['tax_name'] ?? '')) : (string) $stored->tax_name;

        if ($name === '' || mb_strlen($name) > self::NAME_MAX) {
            throw new AccountingException(AccountingError::TaxNameInvalid, 'A tax has a name of 1 to 255 characters.');
        }

        if ($stored === null && $this->db->table('tax_definitions')->where('tax_name', $name)->countAllResults() > 0) {
            throw new AccountingException(AccountingError::TaxNameTaken, 'Another tax has this name.');
        }

        $currentMode = $stored === null || $stored->tax_included === null ? null : (int) $stored->tax_included === 1;
        $mode        = array_key_exists('tax_mode', $input) ? $this->mode($input['tax_mode']) : $currentMode;

        $currentAccount = $stored === null || $stored->liability_account_id === null ? null : (int) $stored->liability_account_id;
        $accountId      = array_key_exists('account_id', $input) ? $this->accountId($input['account_id']) : $currentAccount;

        if ($accountId !== $currentAccount) {
            $this->guard->require(AccountingGuard::ROUTING_MANAGE, $employeeId);
            $this->branches->requireCompanyScope($employeeId);

            if ($accountId !== null) {
                $this->assertUsable($accountId);
            }
        }

        return $this->transactional(function () use ($name, $stored, $definitionId, $mode, $currentMode, $accountId, $currentAccount, $employeeId): int {
            $row = ['tax_name' => $name, 'tax_included' => $mode === null ? null : (int) $mode, 'liability_account_id' => $accountId];

            if ($stored === null) {
                $this->db->table('tax_definitions')->insert($row);

                $definitionId = (int) $this->db->insertID();

                $this->audit->write(self::ENTITY, $definitionId, 'created', $employeeId, JournalOrigin::User, ['name' => $name, 'included' => $mode, 'account' => $accountId]);

                return $definitionId;
            }

            $this->db->table('tax_definitions')->where('tax_definition_id', $definitionId)->update($row);

            if ($mode !== $currentMode) {
                $this->audit->write(self::ENTITY, (int) $definitionId, 'mode_changed', $employeeId, JournalOrigin::User, ['name' => $name, 'before' => $currentMode, 'after' => $mode]);
            }

            if ($accountId !== $currentAccount) {
                $this->audit->write(self::ENTITY, (int) $definitionId, 'account_changed', $employeeId, JournalOrigin::User, ['name' => $name, 'before' => $currentAccount, 'after' => $accountId]);
            }

            return (int) $definitionId;
        });
    }

    /**
     * @param list<int> $definitionIds
     */
    public function delete(array $definitionIds, ?int $employeeId = null): void
    {
        $ids = array_values(array_unique(array_filter(array_map('intval', $definitionIds), static fn (int $id): bool => $id > 0)));

        if ($ids === []) {
            return;
        }

        $this->transactional(function () use ($ids, $employeeId): void {
            foreach ($this->db->table('tax_definitions')->whereIn('tax_definition_id', $ids)->get()->getResult() as $row) {
                $this->audit->write(self::ENTITY, (int) $row->tax_definition_id, 'deleted', $employeeId, JournalOrigin::User, [
                    'name'     => $row->tax_name,
                    'included' => $row->tax_included === null ? null : (int) $row->tax_included === 1,
                    'account'  => $row->liability_account_id === null ? null : (int) $row->liability_account_id,
                ]);
            }

            $this->db->table('tax_definitions')->whereIn('tax_definition_id', $ids)->delete();
        });
    }

    public function find(int $definitionId): ?object
    {
        return $this->db->table('tax_definitions d')
            ->select('d.*, a.code AS account_code, a.name AS account_name')
            ->join('accounting_accounts a', 'a.account_id = d.liability_account_id', 'left')
            ->where('d.tax_definition_id', $definitionId)
            ->get()
            ->getRow();
    }

    /**
     * @return array{total: int, rows: list<object>}
     */
    public function search(string $term, string $sort, string $order, int $limit, int $offset): array
    {
        $builder = $this->db->table('tax_definitions d')->join('accounting_accounts a', 'a.account_id = d.liability_account_id', 'left');

        if (trim($term) !== '') {
            $builder->like('d.tax_name', trim($term));
        }

        $total   = $builder->countAllResults(false);
        $columns = ['tax_definition_id' => 'd.tax_definition_id', 'tax_name' => 'd.tax_name', 'tax_mode' => 'd.tax_included', 'account' => 'a.code'];
        $rows    = $builder
            ->select('d.*, a.code AS account_code, a.name AS account_name')
            ->orderBy($columns[$sort] ?? 'd.tax_definition_id', strtolower($order) === 'asc' ? 'ASC' : 'DESC')
            ->limit(max(1, $limit), max(0, $offset))
            ->get()
            ->getResult();

        return ['total' => $total, 'rows' => $rows];
    }

    public function mayAssign(?int $employeeId): bool
    {
        return $this->guard->has(AccountingGuard::ROUTING_MANAGE, $employeeId) && $this->branches->isUnrestricted($employeeId);
    }

    /**
     * The accounts a tax can be credited to, in code order: active accounts of liability that take
     * entries and are not kept for another purpose than the tax collected.
     *
     * @return list<object>
     */
    public function accountOptions(): array
    {
        return $this->db->table('accounting_accounts')
            ->select('account_id, code, name')
            ->where('account_type', AccountType::Liability->value)
            ->where('is_group', 0)
            ->where('active', 1)
            ->groupStart()->where('system_key IS NULL', null, false)->orWhere('system_key', RoutingKey::TaxOutput->defaultSystemKey())->groupEnd()
            ->orderBy('code', 'ASC')
            ->get()
            ->getResult();
    }

    /**
     * @throws AccountingException
     */
    private function stored(int $definitionId): object
    {
        $row = $this->db->table('tax_definitions')->where('tax_definition_id', $definitionId)->get()->getRow();

        if ($row === null) {
            throw new AccountingException(AccountingError::TaxDefinitionNotFound, "Tax {$definitionId} does not exist.", ['tax_id' => $definitionId]);
        }

        return $row;
    }

    /**
     * @throws AccountingException
     */
    private function assertUsable(int $accountId): void
    {
        foreach ($this->accountOptions() as $option) {
            if ((int) $option->account_id === $accountId) {
                return;
            }
        }

        throw new AccountingException(AccountingError::TaxLiabilityAccountInvalid, "Account {$accountId} cannot take the tax of a tax.", ['account_id' => $accountId]);
    }

    /**
     * @throws AccountingException
     */
    private function mode(mixed $value): ?bool
    {
        return match (is_string($value) ? $value : null) {
            self::DEFAULT  => null,
            self::INCLUDED => true,
            self::EXCLUDED => false,
            default        => throw new AccountingException(AccountingError::TaxModeInvalid, 'The mode of a tax is its default, included or excluded.'),
        };
    }

    private function accountId(mixed $value): ?int
    {
        if (! is_scalar($value) || ! ctype_digit(trim((string) $value))) {
            return null;
        }

        $id = (int) trim((string) $value);

        return $id > 0 ? $id : null;
    }
}
