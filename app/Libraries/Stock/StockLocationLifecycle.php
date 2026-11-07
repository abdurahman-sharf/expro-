<?php

namespace App\Libraries\Stock;

use App\Libraries\Accounting\AccountingError;
use App\Libraries\Accounting\AccountingException;
use App\Libraries\Accounting\AuditLog;
use App\Libraries\Accounting\JournalOrigin;
use App\Libraries\Accounting\RunsInTransaction;
use App\Libraries\Accounting\StockLedger;
use App\Libraries\Organization\CompanyContext;
use App\Models\Employee;
use CodeIgniter\Database\BaseConnection;
use Config\Database;

/**
 * The status and the primary flag of a stock location. A location that holds stock, is named in an open
 * document, or is the only active one is never made inactive, and the primary location is never made
 * inactive or removed, so stock and the sub-account of a location are never left without a place. The
 * company has exactly one primary location, and moving the flag is a change of the whole company that
 * is checked, locked and written to the audit log. Whoever may save the stock locations of the
 * settings (the `config` permission) may use it; the system itself, a null employee, always may.
 */
final class StockLocationLifecycle
{
    use RunsInTransaction;

    public const PERMISSION      = 'config';
    private const OPEN_DOCUMENTS = [
        'suspended_sales'     => 'SELECT COUNT(DISTINCT s.sale_id) AS n FROM {sales} s JOIN {sales_items} si ON si.sale_id = s.sale_id WHERE s.sale_status = %d AND si.item_location = ?',
        'sales_orders'        => "SELECT COUNT(DISTINCT o.order_id) AS n FROM {sales_orders} o JOIN {sales_order_lines} l ON l.order_id = o.order_id WHERE o.status IN ('open', 'approved') AND l.item_location = ?",
        'recurring_schedules' => "SELECT COUNT(DISTINCT r.schedule_id) AS n FROM {recurring_schedules} r JOIN {recurring_schedule_lines} l ON l.schedule_id = r.schedule_id WHERE r.status IN ('active', 'paused') AND l.item_location = ?",
        'stock_requests'      => "SELECT COUNT(*) AS n FROM {stock_requests} q WHERE q.status IN ('requested', 'approved') AND (q.from_location_id = ? OR q.to_location_id = ?)",
        'stocktakes'          => "SELECT COUNT(*) AS n FROM {stocktakes} t WHERE t.status = 'counting' AND t.location_id = ?",
    ];

    private BaseConnection $db;
    private Employee $employee;
    private AuditLog $audit;

    public function __construct(?BaseConnection $db = null, ?Employee $employee = null)
    {
        $this->db       = $db ?? Database::connect();
        $this->employee = $employee ?? model(Employee::class);
        $this->audit    = new AuditLog($this->db);
    }

    public function primaryId(): ?int
    {
        $row = $this->db->table('stock_locations l')
            ->select('l.location_id')
            ->join('branches b', 'b.branch_id = l.branch_id')
            ->where(['l.deleted' => 0, 'l.is_primary' => 1, 'b.company_id' => CompanyContext::id()])
            ->get()
            ->getRow();

        return $row === null ? null : (int) $row->location_id;
    }

    /**
     * What stands in the way of making a location inactive or removing it, by the reason, or an empty
     * list when nothing does.
     *
     * @return array<string, int>
     */
    public function blockers(int $locationId): array
    {
        $blockers = [];

        $shelves = (int) $this->db->table('item_quantities')->where('location_id', $locationId)->where('quantity <>', 0)->countAllResults();

        if ($shelves > 0) {
            $blockers['shelves'] = $shelves;
        }

        if ($this->holdsValue($locationId)) {
            $blockers['books'] = 1;
        }

        foreach (self::OPEN_DOCUMENTS as $kind => $query) {
            $count = $this->countOpen($query, $locationId);

            if ($count > 0) {
                $blockers[$kind] = $count;
            }
        }

        return $blockers;
    }

    /**
     * @throws AccountingException
     */
    public function deactivate(int $locationId, ?int $employeeId): void
    {
        $this->requirePermission($employeeId);

        $this->transactional(function () use ($locationId, $employeeId): void {
            $locations = $this->lockedLocations();
            $location  = $this->locationOf($locations, $locationId);

            if ((int) $location->status === 0) {
                return;
            }

            $this->assertRetirable($location, $locations);

            $this->db->table('stock_locations')->where('location_id', $locationId)->update(['status' => 0]);
            $this->audit->write('stock_location', $locationId, 'stock_location_deactivated', $employeeId, JournalOrigin::User, ['name' => $location->location_name], (int) $location->branch_id);
        });
    }

    /**
     * @throws AccountingException
     */
    public function activate(int $locationId, ?int $employeeId): void
    {
        $this->requirePermission($employeeId);

        $this->transactional(function () use ($locationId, $employeeId): void {
            $location = $this->locationOf($this->lockedLocations(), $locationId);

            if ((int) $location->status === 1) {
                return;
            }

            $this->db->table('stock_locations')->where('location_id', $locationId)->update(['status' => 1]);
            $this->audit->write('stock_location', $locationId, 'stock_location_activated', $employeeId, JournalOrigin::User, ['name' => $location->location_name], (int) $location->branch_id);
        });
    }

    /**
     * Makes a location the primary one of the company, in place of the one that was. The flag is moved in
     * one transaction, so the company never has two primary locations or none.
     *
     * @throws AccountingException
     */
    public function makePrimary(int $locationId, ?int $employeeId): void
    {
        $this->requirePermission($employeeId);

        $this->transactional(function () use ($locationId, $employeeId): void {
            $locations = $this->lockedLocations();
            $location  = $this->locationOf($locations, $locationId);

            if ((int) $location->status === 0) {
                throw new AccountingException(AccountingError::StockLocationInactive, 'An inactive stock location cannot be the primary one.', ['name' => $location->location_name]);
            }

            if ((int) $location->is_primary === 1) {
                return;
            }

            $previous = null;

            foreach ($locations as $other) {
                if ((int) $other->is_primary === 1) {
                    $previous = $other;
                }
            }

            $this->db->table('stock_locations')->where('is_primary', 1)->where('deleted', 0)->update(['is_primary' => 0]);
            $this->db->table('stock_locations')->where('location_id', $locationId)->update(['is_primary' => 1]);

            $this->audit->write('stock_location', $locationId, 'stock_location_primary_changed', $employeeId, JournalOrigin::User, [
                'from' => $previous === null ? null : ['location_id' => (int) $previous->location_id, 'name' => $previous->location_name],
                'to'   => ['location_id' => $locationId, 'name' => $location->location_name],
            ]);
        });
    }

    /**
     * Makes a location the primary one when the company has none, which is how the first location a
     * company opens becomes it.
     */
    public function ensurePrimary(int $locationId): void
    {
        if ($this->primaryId() !== null) {
            return;
        }

        $this->db->table('stock_locations')->where('location_id', $locationId)->where('deleted', 0)->update(['is_primary' => 1]);
    }

    /**
     * The refusal to remove a location, or nothing when it may go. A removal is held to the same rules as
     * making the location inactive.
     *
     * @throws AccountingException
     */
    public function checkRemoval(int $locationId): void
    {
        $locations = $this->db->query(
            'SELECT l.location_id, l.location_name, l.status, l.is_primary, l.branch_id FROM ' . $this->db->prefixTable('stock_locations') . ' l JOIN ' . $this->db->prefixTable('branches') . ' b ON b.branch_id = l.branch_id WHERE l.deleted = 0 AND b.company_id = ? ORDER BY l.location_id',
            [CompanyContext::id()],
        )->getResult();

        $location = null;

        foreach ($locations as $candidate) {
            if ((int) $candidate->location_id === $locationId) {
                $location = $candidate;
            }
        }

        if ($location === null) {
            return;
        }

        $this->assertRetirable($location, $locations);
    }

    /**
     * @throws AccountingException
     */
    private function requirePermission(?int $employeeId): void
    {
        if ($employeeId !== null && ! $this->employee->has_grant(self::PERMISSION, $employeeId)) {
            throw new AccountingException(AccountingError::PermissionDenied, 'Missing permission ' . self::PERMISSION . '.', ['permission' => self::PERMISSION]);
        }
    }

    /**
     * @return list<object>
     */
    private function lockedLocations(): array
    {
        return $this->db->query(
            'SELECT l.location_id, l.location_name, l.status, l.is_primary, l.branch_id FROM ' . $this->db->prefixTable('stock_locations') . ' l JOIN ' . $this->db->prefixTable('branches') . ' b ON b.branch_id = l.branch_id WHERE l.deleted = 0 AND b.company_id = ? ORDER BY l.location_id FOR UPDATE',
            [CompanyContext::id()],
        )->getResult();
    }

    /**
     * @param list<object> $locations
     *
     * @throws AccountingException
     */
    private function locationOf(array $locations, int $locationId): object
    {
        foreach ($locations as $location) {
            if ((int) $location->location_id === $locationId) {
                return $location;
            }
        }

        throw new AccountingException(AccountingError::StockLocationNotFound, 'The stock location does not exist.', ['id' => $locationId]);
    }

    /**
     * @param list<object> $locations
     *
     * @throws AccountingException
     */
    private function assertRetirable(object $location, array $locations): void
    {
        $name = (string) $location->location_name;

        if ((int) $location->is_primary === 1) {
            throw new AccountingException(AccountingError::StockLocationIsPrimary, 'The primary stock location is not made inactive or removed.', ['name' => $name]);
        }

        if ((int) $location->status === 1) {
            $active = count(array_filter($locations, static fn (object $other): bool => (int) $other->status === 1));

            if ($active <= 1) {
                throw new AccountingException(AccountingError::StockLocationLastActive, 'The only active stock location is not made inactive or removed.', ['name' => $name]);
            }
        }

        $blockers = $this->blockers((int) $location->location_id);

        if (isset($blockers['shelves'])) {
            throw new AccountingException(AccountingError::StockLocationHoldsStock, 'The stock location still holds stock.', ['name' => $name, 'count' => $blockers['shelves']]);
        }

        if (isset($blockers['books'])) {
            throw new AccountingException(AccountingError::StockLocationHoldsValue, 'The books still hold a value for the stock location.', ['name' => $name]);
        }

        if ($blockers !== []) {
            throw new AccountingException(AccountingError::StockLocationOpenDocuments, 'The stock location is named in an open document.', ['name' => $name, 'count' => array_sum($blockers)]);
        }
    }

    private function holdsValue(int $locationId): bool
    {
        $entries = $this->db->prefixTable('accounting_stock_entries');
        $row     = $this->db->query("SELECT COALESCE(SUM(quantity), 0) AS quantity, COALESCE(SUM(value), 0) AS value FROM {$entries} WHERE company_id = ? AND location_id = ? FOR UPDATE", [CompanyContext::id(), $locationId])->getRow();

        if (bccomp((string) $row->quantity, '0', 6) !== 0 || bccomp((string) $row->value, '0', 6) !== 0) {
            return true;
        }

        $reconciled = (new StockLedger($this->db))->reconcile([$locationId]);

        return $reconciled !== [] && bccomp((string) $reconciled[0]['account_balance'], '0', 6) !== 0;
    }

    private function countOpen(string $query, int $locationId): int
    {
        $tables = [];
        $sql    = sprintf($query, SUSPENDED);

        preg_match_all('/\{([a-z_]+)\}/', $sql, $found);

        foreach (array_unique($found[1]) as $table) {
            if (! $this->db->tableExists($this->db->prefixTable($table))) {
                return 0;
            }

            $tables['{' . $table . '}'] = $this->db->prefixTable($table);
        }

        $sql      = strtr($sql, $tables);
        $bindings = array_fill(0, substr_count($sql, '?'), $locationId);

        return (int) $this->db->query($sql, $bindings)->getRow()->n;
    }
}
