<?php

namespace App\Libraries\Stock;

use App\Libraries\Accounting\AccountingError;
use App\Libraries\Accounting\AccountingException;
use App\Libraries\Accounting\AccountingGuard;
use App\Libraries\Organization\BranchException;
use App\Libraries\Organization\BranchGuard;
use CodeIgniter\Database\BaseConnection;
use Config\Database;

/**
 * Who may change the stock of a location: an employee who works in the branch the location belongs to
 * and holds the items permission of that location, as they must to change it from the items screens. It
 * is asked here, in the service, and not only by the screen, so that no request gets round it.
 */
final class StockAccess
{
    private BaseConnection $db;
    private BranchGuard $branches;
    private AccountingGuard $guard;

    public function __construct(?BaseConnection $db = null, ?BranchGuard $branches = null, ?AccountingGuard $guard = null)
    {
        $this->db       = $db ?? Database::connect();
        $this->branches = $branches ?? new BranchGuard($this->db);
        $this->guard    = $guard ?? new AccountingGuard();
    }

    /**
     * The stock location, which must exist and not be deleted.
     *
     * @throws AccountingException
     */
    public function location(int $locationId): object
    {
        $location = $locationId > 0 ? $this->db->table('stock_locations')->select('location_id, location_name, branch_id, status')->where(['location_id' => $locationId, 'deleted' => 0])->get()->getRow() : null;

        if ($location === null) {
            throw new AccountingException(AccountingError::StockPermitLocationInvalid, 'The stock location does not exist.', ['location_id' => $locationId]);
        }

        return $location;
    }

    /**
     * A document that is new names only an active location: an inactive one holds no stock and no value
     * and is left out of every list, so nothing may put stock back into it.
     *
     * @throws AccountingException
     */
    public function assertActive(int $locationId): void
    {
        $location = $this->location($locationId);

        if ((int) $location->status !== 1) {
            throw new AccountingException(AccountingError::StockLocationNotActive, 'No stock document is made for an inactive stock location.', ['name' => (string) $location->location_name]);
        }
    }

    /**
     * @throws AccountingException|BranchException
     */
    public function assertMayChange(int $locationId, int $employeeId): object
    {
        $location = $this->location($locationId);

        $this->branches->requireBranch((int) $location->branch_id, $employeeId);

        $permission = $this->db->table('permissions')->select('permission_id')->where(['location_id' => $locationId, 'module_id' => 'items'])->get()->getRow();

        if ($permission === null || ! $this->guard->has((string) $permission->permission_id, $employeeId)) {
            throw new AccountingException(AccountingError::StockLocationDenied, 'The employee may not change the stock of this location.', ['location_id' => $locationId, 'name' => (string) $location->location_name]);
        }

        return $location;
    }
}
