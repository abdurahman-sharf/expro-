<?php

namespace App\Models;

use App\Libraries\Accounting\AccountingError;
use App\Libraries\Accounting\AccountingException;
use App\Libraries\Accounting\AuditLog;
use App\Libraries\Accounting\JournalOrigin;
use App\Libraries\Pricing\SalesControlSettings;
use CodeIgniter\Database\BaseBuilder;
use CodeIgniter\Model;
use Throwable;

/**
 * The price lists and the price of an item in each. A list sets the price of a line when a sale is
 * rung up for a customer who has it; the price is copied to the line, so changing a list never moves
 * the price of a sale that was already saved. A list that a customer holds or that a sale was priced
 * from is deactivated, never deleted.
 */
class Price_list extends Model
{
    public const NAME_MAX = 100;

    protected $table            = 'price_lists';
    protected $primaryKey       = 'price_list_id';
    protected $useAutoIncrement = true;
    protected $useSoftDeletes   = false;
    protected $allowedFields    = ['name', 'status', 'created_by'];

    public function getList(int $priceListId): ?object
    {
        return $this->listsQuery()->where('l.price_list_id', $priceListId)->get()->getRow();
    }

    /**
     * @return list<object>
     */
    public function allLists(bool $activeOnly = false): array
    {
        $builder = $this->listsQuery()->orderBy('l.name', 'ASC');

        if ($activeOnly) {
            $builder->where('l.status', 1);
        }

        return $builder->get()->getResult();
    }

    /**
     * @return array{total: int, rows: list<object>}
     */
    public function search(string $search, string $sort, string $order, int $limit, int $offset): array
    {
        $builder = $this->listsQuery();

        if ($search !== '') {
            $builder->like('l.name', $search);
        }

        $total   = $builder->countAllResults(false);
        $columns = ['price_list_id' => 'l.price_list_id', 'name' => 'l.name', 'status' => 'l.status', 'customers' => 'customers', 'items' => 'items'];
        $rows    = $builder->orderBy($columns[$sort] ?? 'l.name', strtolower($order) === 'desc' ? 'DESC' : 'ASC')->limit($limit, $offset)->get()->getResult();

        return ['total' => $total, 'rows' => $rows];
    }

    /**
     * @throws AccountingException
     */
    public function saveList(string $name, bool $active, ?int $priceListId, ?int $employeeId): int
    {
        $name = trim($name);

        if ($name === '' || mb_strlen($name) > self::NAME_MAX) {
            throw new AccountingException(AccountingError::PriceListNameInvalid, 'The name of a price list is one to 100 characters.');
        }

        $audit = new AuditLog($this->db);

        $this->db->transStart();

        $before = null;

        if ($priceListId !== null) {
            $before = $this->db->query('SELECT name, status FROM ' . $this->db->prefixTable('price_lists') . ' WHERE price_list_id = ? FOR UPDATE', [$priceListId])->getRow();

            if ($before === null) {
                $this->db->transRollback();

                throw new AccountingException(AccountingError::PriceListNotFound, 'The price list does not exist.', ['id' => $priceListId]);
            }
        }

        if ($this->db->table('price_lists')->where('name', $name)->where('price_list_id !=', $priceListId ?? 0)->countAllResults() > 0) {
            $this->db->transRollback();

            throw new AccountingException(AccountingError::PriceListNameTaken, 'Another price list has this name.', ['name' => $name]);
        }

        if ($priceListId === null) {
            $this->db->table('price_lists')->insert(['name' => $name, 'status' => $active ? 1 : 0, 'created_by' => $employeeId]);
            $priceListId = (int) $this->db->insertID();

            $audit->write('price_list', $priceListId, 'price_list_created', $employeeId, JournalOrigin::User, ['name' => $name, 'active' => $active]);
        } elseif ($before->name !== $name || (int) $before->status !== ($active ? 1 : 0)) {
            $this->db->table('price_lists')->where('price_list_id', $priceListId)->update(['name' => $name, 'status' => $active ? 1 : 0]);

            $audit->write('price_list', $priceListId, 'price_list_changed', $employeeId, JournalOrigin::User, ['before' => ['name' => $before->name, 'active' => (int) $before->status === 1], 'after' => ['name' => $name, 'active' => $active]]);
        }

        $this->db->transComplete();

        return $priceListId;
    }

    /**
     * @throws AccountingException
     */
    public function setStatus(int $priceListId, bool $active, ?int $employeeId): void
    {
        $list = $this->getList($priceListId);

        if ($list === null) {
            throw new AccountingException(AccountingError::PriceListNotFound, 'The price list does not exist.', ['id' => $priceListId]);
        }

        if ((int) $list->status === ($active ? 1 : 0)) {
            return;
        }

        $this->db->transStart();
        $this->db->table('price_lists')->where('price_list_id', $priceListId)->update(['status' => $active ? 1 : 0]);
        (new AuditLog($this->db))->write('price_list', $priceListId, $active ? 'price_list_activated' : 'price_list_deactivated', $employeeId, JournalOrigin::User, ['name' => $list->name]);
        $this->db->transComplete();
    }

    /**
     * @param list<int> $priceListIds
     *
     * @throws AccountingException
     */
    public function deleteLists(array $priceListIds, ?int $employeeId): void
    {
        $audit = new AuditLog($this->db);

        $this->db->transStart();

        try {
            foreach ($priceListIds as $priceListId) {
                $list = $this->getList($priceListId);

                if ($list === null) {
                    throw new AccountingException(AccountingError::PriceListNotFound, 'The price list does not exist.', ['id' => $priceListId]);
                }

                if ((int) $list->customers > 0 || (int) $list->sales > 0) {
                    throw new AccountingException(AccountingError::PriceListInUse, 'A price list that is in use is deactivated, not deleted.', ['name' => $list->name]);
                }

                $this->db->table('price_list_items')->where('price_list_id', $priceListId)->delete();
                $this->db->table('price_lists')->where('price_list_id', $priceListId)->delete();

                $audit->write('price_list', $priceListId, 'price_list_deleted', $employeeId, JournalOrigin::User, ['name' => $list->name, 'prices' => (int) $list->items]);
            }
        } catch (Throwable $failure) {
            $this->db->transRollback();

            throw $failure;
        }

        $this->db->transComplete();
    }

    public function priceOf(int $priceListId, int $itemId): ?string
    {
        $row = $this->db->table('price_list_items')->select('price')->where(['price_list_id' => $priceListId, 'item_id' => $itemId])->get()->getRow();

        return $row === null ? null : self::asLinePrice((string) $row->price);
    }

    /**
     * A stored price as a line carries it: with two decimals, and with more only when it needs them.
     */
    public static function asLinePrice(string $stored): string
    {
        [$whole, $fraction] = array_pad(explode('.', $stored, 2), 2, '');

        return $whole . '.' . str_pad(rtrim($fraction, '0'), 2, '0');
    }

    /**
     * What the item sells for in each list that prices it.
     *
     * @return array<int, string> By the number of the list.
     */
    public function pricesOfItem(int $itemId): array
    {
        $prices = [];

        foreach ($this->db->table('price_list_items')->where('item_id', $itemId)->get()->getResult() as $row) {
            $prices[(int) $row->price_list_id] = self::asLinePrice((string) $row->price);
        }

        return $prices;
    }

    /**
     * Puts the price of an item in the lists as the form gave them: a price sets it, an empty entry
     * takes the item out of the list so that it sells at its own price again. Lists the form did not
     * mention keep what they have.
     *
     * @param array<int|string, string|null> $prices By the number of the list.
     *
     * @throws AccountingException
     */
    public function saveItemPrices(int $itemId, array $prices, ?int $employeeId): void
    {
        $changes = [];

        $this->db->transStart();

        try {
            foreach ($prices as $priceListId => $price) {
                $priceListId = (int) $priceListId;
                $price       = $price === null ? '' : trim($price);

                if ($this->getList($priceListId) === null) {
                    throw new AccountingException(AccountingError::PriceListNotFound, 'The price list does not exist.', ['id' => $priceListId]);
                }

                $stored = $this->priceOf($priceListId, $itemId);

                if ($price === '') {
                    if ($stored !== null) {
                        $this->db->table('price_list_items')->where(['price_list_id' => $priceListId, 'item_id' => $itemId])->delete();
                        $changes[$priceListId] = ['from' => $stored, 'to' => null];
                    }

                    continue;
                }

                if (preg_match('/^\d{1,11}(\.\d{1,4})?$/', $price) !== 1) {
                    throw new AccountingException(AccountingError::PriceInvalid, 'A price is a number that is not negative.');
                }

                $price = bcadd($price, '0', 4);

                if ($stored === null) {
                    $this->db->table('price_list_items')->insert(['price_list_id' => $priceListId, 'item_id' => $itemId, 'price' => $price]);
                    $changes[$priceListId] = ['from' => null, 'to' => $price];
                } elseif (bccomp($stored, $price, 4) !== 0) {
                    $this->db->table('price_list_items')->where(['price_list_id' => $priceListId, 'item_id' => $itemId])->update(['price' => $price]);
                    $changes[$priceListId] = ['from' => $stored, 'to' => $price];
                }
            }

            if ($changes !== []) {
                (new AuditLog($this->db))->write('price_list', null, 'price_list_prices_changed', $employeeId, JournalOrigin::User, ['item_id' => $itemId, 'lists' => $changes]);
            }
        } catch (Throwable $failure) {
            $this->db->transRollback();

            throw $failure;
        }

        $this->db->transComplete();
    }

    /**
     * The list a customer is assigned, whether it is active or not.
     */
    public function assignedTo(int $customerId): ?int
    {
        $row = $this->db->table('customers')->select('price_list_id')->where('person_id', $customerId)->get()->getRow();

        return $row === null || $row->price_list_id === null ? null : (int) $row->price_list_id;
    }

    /**
     * The list that prices a sale: the one chosen on the sale when the setting lets a sale change
     * its list, else the one the customer is assigned, else the default list of the shop. A choice
     * of 0 is the choice of no list at all, whatever list the customer has. A list that is not
     * active prices nothing, and with the price lists off no list prices anything.
     */
    public function effectiveListId(?int $customerId, ?int $chosen = null): ?int
    {
        $settings = new SalesControlSettings($this->db);

        if (! $settings->priceListsEnabled()) {
            return null;
        }

        $candidates = [];

        if ($chosen !== null && $settings->changePriceListOnInvoice()) {
            if ($chosen === 0) {
                return null;
            }

            $candidates[] = $chosen;
        }

        if ($customerId !== null && $customerId > 0) {
            $candidates[] = $this->assignedTo($customerId);
        }

        $candidates[] = $settings->defaultPriceListId();

        foreach ($candidates as $candidate) {
            if ($candidate !== null && $this->isActive($candidate)) {
                return $candidate;
            }
        }

        return null;
    }

    /**
     * Whether a list may be given to a customer, so a form can refuse it before it saves anything
     * else. A customer keeps the list it has, active or not, and may always have none.
     *
     * @throws AccountingException
     */
    public function checkAssignable(int $customerId, ?int $priceListId): void
    {
        if ($priceListId === null || $priceListId === 0 || $priceListId === $this->assignedTo($customerId)) {
            return;
        }

        $list = $this->getList($priceListId);

        if ($list === null) {
            throw new AccountingException(AccountingError::PriceListNotFound, 'The price list does not exist.', ['id' => $priceListId]);
        }

        if ((int) $list->status !== 1) {
            throw new AccountingException(AccountingError::PriceListInactive, 'A price list that is not active is not given to a customer.', ['name' => $list->name]);
        }
    }

    /**
     * Assigns a price list to a customer, or takes the customer's list away when the list is null. A
     * list that is not active is not given to anyone new, but the customer who has it keeps it.
     *
     * @throws AccountingException
     */
    public function assignTo(int $customerId, ?int $priceListId, ?int $employeeId): void
    {
        $priceListId = $priceListId === 0 ? null : $priceListId;
        $before      = $this->assignedTo($customerId);

        if ($priceListId === $before) {
            return;
        }

        $this->checkAssignable($customerId, $priceListId);
        $this->db->transStart();
        $this->db->table('customers')->where('person_id', $customerId)->update(['price_list_id' => $priceListId]);
        (new AuditLog($this->db))->write('customer', $customerId, 'customer_price_list_changed', $employeeId, JournalOrigin::User, ['before' => $before, 'after' => $priceListId]);
        $this->db->transComplete();
    }

    public function isActive(int $priceListId): bool
    {
        return $this->db->table('price_lists')->where(['price_list_id' => $priceListId, 'status' => 1])->countAllResults() > 0;
    }

    private function listsQuery(): BaseBuilder
    {
        $prefix = $this->db->DBPrefix;

        return $this->db->table('price_lists l')
            ->select("l.price_list_id, l.name, l.status, l.created_at, (SELECT COUNT(*) FROM {$prefix}customers c WHERE c.price_list_id = l.price_list_id) AS customers, (SELECT COUNT(*) FROM {$prefix}sales s WHERE s.price_list_id = l.price_list_id) AS sales, (SELECT COUNT(*) FROM {$prefix}price_list_items p WHERE p.price_list_id = l.price_list_id) AS items", false);
    }
}
