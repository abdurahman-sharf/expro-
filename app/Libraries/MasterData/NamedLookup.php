<?php

namespace App\Libraries\MasterData;

use App\Libraries\Accounting\AccountingError;
use App\Libraries\Accounting\AccountingException;
use App\Libraries\Accounting\AuditLog;
use App\Libraries\Accounting\JournalOrigin;
use App\Libraries\Accounting\RunsInTransaction;
use CodeIgniter\Database\BaseBuilder;
use CodeIgniter\Database\BaseConnection;
use Config\Database;

/**
 * A short list of names that a record may carry, such as the classifications of customers or the brands
 * of products. A name is one to 100 characters and unique; a name that a record carries is made inactive,
 * never deleted, and an inactive name is not given to a record again, while a record that holds it keeps
 * it. Every change is written to the audit log.
 */
final class NamedLookup
{
    use RunsInTransaction;

    public const NAME_MAX = 100;

    private BaseConnection $db;
    private AuditLog $audit;

    public function __construct(private readonly LookupKind $kind, ?BaseConnection $db = null)
    {
        $this->db    = $db ?? Database::connect();
        $this->audit = new AuditLog($this->db);
    }

    public function get(int $id): ?object
    {
        return $this->query()->where('l.' . $this->kind->idColumn(), $id)->get()->getRow();
    }

    /**
     * @return list<object>
     */
    public function all(bool $activeOnly = false): array
    {
        $builder = $this->query()->orderBy('l.name', 'ASC');

        if ($activeOnly) {
            $builder->where('l.status', 1);
        }

        return $builder->get()->getResult();
    }

    /**
     * @return array<int, string> The names that may be given to a record, by number.
     */
    public function options(): array
    {
        $options = [];

        foreach ($this->all(true) as $row) {
            $options[(int) $row->{$this->kind->idColumn()}] = (string) $row->name;
        }

        return $options;
    }

    /**
     * @return array{total: int, rows: list<object>}
     */
    public function search(string $search, string $sort, string $order, int $limit, int $offset): array
    {
        $builder = $this->query();

        if ($search !== '') {
            $builder->like('l.name', $search);
        }

        $total   = $builder->countAllResults(false);
        $columns = [$this->kind->idColumn() => 'l.' . $this->kind->idColumn(), 'name' => 'l.name', 'status' => 'l.status', 'uses' => 'uses'];
        $rows    = $builder->orderBy($columns[$sort] ?? 'l.name', strtolower($order) === 'desc' ? 'DESC' : 'ASC')->limit($limit, $offset)->get()->getResult();

        return ['total' => $total, 'rows' => $rows];
    }

    /**
     * @throws AccountingException
     */
    public function save(string $name, bool $active, ?int $id, ?int $employeeId): int
    {
        $name = trim($name);

        if ($name === '' || mb_strlen($name) > self::NAME_MAX) {
            throw new AccountingException(AccountingError::LookupNameInvalid, 'A name is one to 100 characters.');
        }

        return $this->transactional(function () use ($name, $active, $id, $employeeId): int {
            $table  = $this->kind->table();
            $column = $this->kind->idColumn();
            $before = null;

            if ($id !== null) {
                $before = $this->db->query('SELECT name, status FROM ' . $this->db->prefixTable($table) . " WHERE {$column} = ? FOR UPDATE", [$id])->getRow();

                if ($before === null) {
                    throw new AccountingException(AccountingError::LookupNotFound, 'The entry does not exist.', ['id' => $id]);
                }
            }

            if ($this->db->table($table)->where('name', $name)->where($column . ' !=', $id ?? 0)->countAllResults() > 0) {
                throw new AccountingException(AccountingError::LookupNameTaken, 'Another entry has this name.', ['name' => $name]);
            }

            if ($id === null) {
                $this->db->table($table)->insert(['name' => $name, 'status' => $active ? 1 : 0, 'created_by' => $employeeId]);
                $id = (int) $this->db->insertID();

                $this->audit->write($this->kind->value, $id, $this->kind->value . '_created', $employeeId, JournalOrigin::User, ['name' => $name, 'active' => $active]);
            } elseif ($before->name !== $name || (int) $before->status !== ($active ? 1 : 0)) {
                $this->db->table($table)->where($column, $id)->update(['name' => $name, 'status' => $active ? 1 : 0]);

                $this->audit->write($this->kind->value, $id, $this->kind->value . '_changed', $employeeId, JournalOrigin::User, ['before' => ['name' => $before->name, 'active' => (int) $before->status === 1], 'after' => ['name' => $name, 'active' => $active]]);
            }

            return $id;
        });
    }

    /**
     * @throws AccountingException
     */
    public function setStatus(int $id, bool $active, ?int $employeeId): void
    {
        $this->transactional(function () use ($id, $active, $employeeId): void {
            $row = $this->db->query('SELECT name, status FROM ' . $this->db->prefixTable($this->kind->table()) . " WHERE {$this->kind->idColumn()} = ? FOR UPDATE", [$id])->getRow();

            if ($row === null) {
                throw new AccountingException(AccountingError::LookupNotFound, 'The entry does not exist.', ['id' => $id]);
            }

            if ((int) $row->status === ($active ? 1 : 0)) {
                return;
            }

            $this->db->table($this->kind->table())->where($this->kind->idColumn(), $id)->update(['status' => $active ? 1 : 0]);
            $this->audit->write($this->kind->value, $id, $this->kind->value . ($active ? '_activated' : '_deactivated'), $employeeId, JournalOrigin::User, ['name' => $row->name]);
        });
    }

    /**
     * @param list<int> $ids
     *
     * @throws AccountingException
     */
    public function delete(array $ids, ?int $employeeId): void
    {
        $this->transactional(function () use ($ids, $employeeId): void {
            foreach ($ids as $id) {
                $row = $this->get($id);

                if ($row === null) {
                    throw new AccountingException(AccountingError::LookupNotFound, 'The entry does not exist.', ['id' => $id]);
                }

                if ((int) $row->uses > 0) {
                    throw new AccountingException(AccountingError::LookupInUse, 'An entry that a record carries is made inactive, not deleted.', ['name' => $row->name, 'count' => (int) $row->uses]);
                }

                $this->db->table($this->kind->table())->where($this->kind->idColumn(), $id)->delete();
                $this->audit->write($this->kind->value, $id, $this->kind->value . '_deleted', $employeeId, JournalOrigin::User, ['name' => $row->name]);
            }
        });
    }

    /**
     * Whether a record may be given the entry: it exists and is active, or it is the entry the record
     * already holds.
     */
    public function mayBeGiven(int $id, ?int $held): bool
    {
        $row = $this->get($id);

        return $row !== null && ((int) $row->status === 1 || $id === $held);
    }

    private function query(): BaseBuilder
    {
        [$table, $column] = $this->kind->reference();

        return $this->db->table($this->kind->table() . ' l')->select('l.*, (SELECT COUNT(*) FROM ' . $this->db->prefixTable($table) . ' r WHERE r.' . $column . ' = l.' . $this->kind->idColumn() . ') AS uses', false);
    }
}
