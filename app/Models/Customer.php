<?php

namespace App\Models;

use App\Libraries\Accounting\AccountingException;
use App\Libraries\Accounting\EntityAccountService;
use App\Libraries\MasterData\PartyContacts;
use App\Libraries\Portal\PortalAccounts;
use App\Libraries\Numbering\DocumentNumbering;
use App\Libraries\Numbering\DocumentType;
use CodeIgniter\Database\BaseBuilder;
use CodeIgniter\Database\ResultInterface;
use Config\OSPOS;
use stdClass;

/**
 * Customer class
 */
class Customer extends Person
{
    protected $table = 'customers';
    protected $primaryKey = 'person_id';
    protected $useAutoIncrement = false;
    protected $useSoftDeletes = false;
    protected $allowedFields = [
        'account_number',
        'taxable',
        'tax_id',
        'sales_tax_code_id',
        'deleted',
        'discount',
        'discount_type',
        'company_name',
        'package_id',
        'points',
        'date',
        'employee_id',
        'consent',
        'classification_id',
        'manual_status',
        'assigned_employee_id',
        'area',
        'country_code',
        'customer_type',
        'is_government_entity',
        'billing_method'
    ];


    /**
     * Determines if a given person_id is a customer
     */
    public function exists(int $person_id): bool
    {
        $builder = $this->db->table('customers');
        $builder->join('people', 'people.person_id = customers.person_id');
        $builder->where('customers.person_id', $person_id);
        return ($builder->get()->getNumRows() == 1);
    }

    /**
     * Checks if account number exists
     */
    public function check_account_number_exists(string $account_number, string $person_id = ''): bool
    {
        $builder = $this->db->table('customers');
        $builder->where('account_number', $account_number);

        if (!empty($person_id)) {
            $builder->where('person_id !=', $person_id);
        }

        return $builder->get()->getNumRows() > 0;
    }

    public function refusesAccountNumber(string $accountNumber, string $personId = ''): bool
    {
        return $accountNumber !== ''
            && (new DocumentNumbering($this->db))->sequence(DocumentType::Customer)->preventDuplicates
            && $this->check_account_number_exists($accountNumber, $personId);
    }

    /**
     * Gets total of rows
     */
    public function get_total_rows(): int
    {
        $builder = $this->db->table('customers');
        $builder->where('deleted', 0);

        return $builder->countAllResults();
    }

    /**
     * Returns all the customers
     */
    public function get_all(int $limit = 0, int $offset = 0): ResultInterface
    {
        $builder = $this->db->table('customers');
        $builder->join('people', 'customers.person_id = people.person_id');
        $builder->where('deleted', 0);
        $builder->orderBy('last_name', 'asc');

        if ($limit > 0) {
            $builder->limit($limit, $offset);
        }

        return $builder->get();
    }

    /**
     * Gets information about a particular customer
     */
    public function get_info(?int $person_id): object
    {
        $builder = $this->db->table('customers');
        $builder->join('people', 'people.person_id = customers.person_id');
        $builder->where('customers.person_id', $person_id);
        $query = $builder->get();

        return $query->getNumRows() === 1
            ? $query->getRow()
            : $this->getEmptyObject('customers');
    }

    /**
     * Initializes an empty object based on database definitions
     * @param string $table_name
     * @return object
     */
    private function getEmptyObject(string $table_name): object
    {
        // Return an empty base parent object, as $item_id is NOT an item
        $empty_obj = parent::get_info(NEW_ENTRY);

        // Iterate through field definitions to determine how the fields should be initialized
        foreach ($this->db->getFieldData($table_name) as $field) {
            $field_name = $field->name;

            if (in_array($field->type, ['int', 'tinyint', 'decimal'])) {
                $empty_obj->$field_name = ($field->primary_key == 1) ? NEW_ENTRY : 0;
            } else {
                $empty_obj->$field_name = null;
            }
        }

        return $empty_obj;
    }


    /**
     * Gets stats about a particular customer
     */
    public function get_stats(int $customerId, ?array $branchIds = null): ?stdClass
    {
        $dbPrefix = $this->db->getPrefix();
        $totalsDecimals = totals_decimals();
        $quantityDecimals = quantity_decimals();

        // Temp Table
        $builder = $this->db->table('sales');
        $builder->select('sales.sale_id AS sale_id, AVG(`' . $dbPrefix . 'sales_items`.`discount`) AS avg_discount, SUM(`' . $dbPrefix . 'sales_items`.`quantity_purchased`) AS quantity');
        $builder->join('sales_items', 'sales_items.sale_id = sales.sale_id');
        $builder->where('sales.customer_id', $customerId);
        $this->limitSalesToBranches($builder, $branchIds);
        $builder->groupBy('sale_id');
        $selectQuery = $builder->getCompiledSelect();

        $sql = 'CREATE TEMPORARY TABLE IF NOT EXISTS ' . $this->db->prefixTable('sales_items_temp');
        $sql .= ' (INDEX(sale_id)) ENGINE=MEMORY (' . $selectQuery . ')';
        $this->db->query($sql);

        // Get data
        $builder = $this->db->table('sales');
        $builder->select([
            'SUM(sales_payments.payment_amount - sales_payments.cash_refund) AS total',
            'MIN(sales_payments.payment_amount - sales_payments.cash_refund) AS min',
            'MAX(sales_payments.payment_amount - sales_payments.cash_refund) AS max',
            'AVG(sales_payments.payment_amount - sales_payments.cash_refund) AS average',
            "ROUND(AVG(sales_items_temp.avg_discount), $totalsDecimals) AS avg_discount",
            "ROUND(SUM(sales_items_temp.quantity), $quantityDecimals) AS quantity"
        ]);
        $builder->join('sales_payments AS sales_payments', 'sales.sale_id = sales_payments.sale_id');
        $builder->join('sales_items_temp AS sales_items_temp', 'sales.sale_id = sales_items_temp.sale_id');
        $builder->where('sales.customer_id', $customerId);
        $builder->where('sales.sale_status', COMPLETED);
        $this->limitSalesToBranches($builder, $branchIds);
        $builder->groupBy('sales.customer_id');

        $stat = $builder->get()->getRow();

        // Drop Temp Table
        $sql = 'DROP TEMPORARY TABLE IF EXISTS ' . $this->db->prefixTable('sales_items_temp');
        $this->db->query($sql);

        return $stat;
    }

    /**
     * Gets information about multiple customers
     */
    public function get_multiple_info(array $person_ids): ResultInterface
    {
        $builder = $this->db->table('customers');
        $builder->join('people', 'people.person_id = customers.person_id');
        $builder->whereIn('customers.person_id', $person_ids);
        $builder->orderBy('last_name', 'asc');

        return $builder->get();
    }

    /**
     * Checks if customer email exists
     */
    public function check_email_exists(string $email, string $customer_id = ''): bool
    {
        // If the email is empty return like it is not existing
        if (empty($email)) {
            return false;
        }

        $builder = $this->db->table('customers');
        $builder->join('people', 'people.person_id = customers.person_id');
        $builder->where('people.email', $email);
        $builder->where('customers.deleted', 0);

        if (!empty($customer_id)) {
            $builder->where('customers.person_id !=', $customer_id);
        }

        return ($builder->get()->getNumRows() == 1);    // TODO: ===
    }

    /**
     * Inserts or updates a customer
     */
    public function save_customer(array &$person_data, array &$customer_data, int $customer_id = NEW_ENTRY): bool
    {
        $success = false;
        $this->db->transStart();

        if (parent::save_value($person_data, $customer_id)) {
            $builder = $this->db->table('customers');
            if ($customer_id == NEW_ENTRY || !$customer_id || !$this->exists($customer_id)) {
                $customer_data['person_id'] = $person_data['person_id'];
                $success = $builder->insert($customer_data);

                if ($success && ($customer_data['account_number'] ?? '') === '') {
                    try {
                        $number = (new DocumentNumbering($this->db))->assign(DocumentType::Customer, 'customers', (int) $customer_data['person_id']);
                    } catch (AccountingException $failure) {
                        log_message('error', 'Numbering customer ' . $customer_data['person_id'] . ' failed: ' . $failure->getMessage());
                        $this->db->transRollback();

                        return false;
                    }

                    $customer_data['account_number'] = $number?->number ?? $customer_data['account_number'] ?? null;
                }
            } else {
                $builder->where('person_id', $customer_id);
                $success = $builder->update($customer_data);
            }
        }

        $this->db->transComplete();

        $success &= $this->db->transStatus();

        if ($success) {
            (new EntityAccountService())->sync(EntityAccountService::CUSTOMER, (int) ($person_data['person_id'] ?? $customer_id));
        }

        return $success;
    }

    /**
     * Updates reward points value
     */
    public function update_reward_points_value(int $customer_id, int $value): void
    {
        $builder = $this->db->table('customers');
        $builder->where('person_id', $customer_id);
        $builder->update(['points' => $value]);
    }

    /**
     * Atomically adjusts a customer's reward points by a signed delta, failing if a
     * negative delta would take the balance below zero
     */
    public function adjustRewardPoints(int $customerId, float $delta): bool
    {
        $builder = $this->db->table('customers');
        $builder->where('person_id', $customerId);

        if ($delta < 0) {
            $builder->where('points >=', abs($delta));
        }

        $operator = $delta >= 0 ? '+' : '-';
        $builder->set('points', 'points ' . $operator . ' ' . $this->db->escape(abs($delta)), false);

        return $builder->update() && $this->db->affectedRows() > 0;
    }

    /**
     * @param $customer_id
     * @param bool $purge
     * @return bool
     */
    public function delete($customer_id = null, bool $purge = false): bool
    {
        $result = true;
        $config = config(OSPOS::class)->settings;

        // If privacy enforcement is selected scramble customer data
        if ($config['enforce_privacy']) {
            $builder = $this->db->table('people');
            $builder->where('person_id', $customer_id);
            $result &= $builder->update([
                'first_name'   => $customer_id,
                'last_name'    => $customer_id,
                'phone_number' => '',
                'email'        => '',
                'gender'       => null,
                'address_1'    => '',
                'address_2'    => '',
                'city'         => '',
                'state'        => '',
                'zip'          => '',
                'country'      => '',
                'comments'     => ''
            ]);

            $builder = $this->db->table('customers');
            $builder->where('person_id', $customer_id);
            $result &= $builder->update([
                'consent'           => 0,
                'company_name'      => null,
                'account_number'    => null,
                'tax_id'            => '',
                'taxable'           => 0,
                'discount'          => 0.00,
                'discount_type'     => 0,
                'package_id'        => null,
                'points'            => null,
                'sales_tax_code_id' => null,
                'deleted'           => 1
            ]);
        } else {
            $builder = $this->db->table('customers');
            $builder->where('person_id', $customer_id);

            $result &= $builder->update(['deleted' => 1]);
        }

        if ($result) {
            (new EntityAccountService())->syncDeleted(EntityAccountService::CUSTOMER, (int) $customer_id, (bool) $config['enforce_privacy']);
            (new PartyContacts($this->db))->hideWithParty(PartyContacts::CUSTOMER, (int) $customer_id, (bool) $config['enforce_privacy'], (int) session()->get('person_id') ?: null);
            (new PortalAccounts($this->db))->closeForCustomer((int) $customer_id, (bool) $config['enforce_privacy'], (int) session()->get('person_id') ?: null);
        }

        return $result;
    }

    /**
     * Deletes a list of customers
     */
    public function delete_list(array $person_ids): bool
    {
        $builder = $this->db->table('customers');
        $builder->whereIn('person_id', $person_ids);

        $result = $builder->update(['deleted' => 1]);

        if ($result) {
            foreach ($person_ids as $personId) {
                (new EntityAccountService())->syncDeleted(EntityAccountService::CUSTOMER, (int) $personId);
                (new PartyContacts($this->db))->hideWithParty(PartyContacts::CUSTOMER, (int) $personId, false, (int) session()->get('person_id') ?: null);
                (new PortalAccounts($this->db))->closeForCustomer((int) $personId, false, (int) session()->get('person_id') ?: null);
            }
        }

        return $result;
    }

    /**
     * Get search suggestions to find customers
     */
    public function get_search_suggestions(string $search, int $limit = 25, bool $unique = true): array
    {
        $suggestions = [];

        $builder = $this->db->table('customers');
        $builder->join('people', 'customers.person_id = people.person_id');
        $builder->groupStart();
        $builder->like('first_name', $search);
        $builder->orLike('last_name', $search);
        $builder->orLike('CONCAT(first_name, " ", last_name)', $search);

        if ($unique) {
            $builder->orLike('email', $search);
            $builder->orLike('phone_number', $search);
            $builder->orLike('company_name', $search);
        }
        $builder->groupEnd();
        $builder->where('deleted', 0);
        $builder->orderBy('last_name', 'asc');

        foreach ($builder->get()->getResult() as $row) {
            $suggestions[] = [
                'value' => $row->person_id,
                'label' => $row->first_name . ' ' . $row->last_name . (!empty($row->company_name) ? ' [' . $row->company_name . ']' : '') . (!empty($row->phone_number) ? ' [' . $row->phone_number . ']' : '')
            ];
        }

        if (!$unique) {
            $builder = $this->db->table('customers');
            $builder->join('people', 'customers.person_id = people.person_id');
            $builder->where('deleted', 0);
            $builder->like('email', $search);
            $builder->orderBy('email', 'asc');

            foreach ($builder->get()->getResult() as $row) {
                $suggestions[] = ['value' => $row->person_id, 'label' => $row->email];
            }

            $builder = $this->db->table('customers');
            $builder->join('people', 'customers.person_id = people.person_id');
            $builder->where('deleted', 0);
            $builder->like('phone_number', $search);
            $builder->orderBy('phone_number', 'asc');

            foreach ($builder->get()->getResult() as $row) {
                $suggestions[] = ['value' => $row->person_id, 'label' => $row->phone_number];
            }

            $builder = $this->db->table('customers');
            $builder->join('people', 'customers.person_id = people.person_id');
            $builder->where('deleted', 0);
            $builder->like('account_number', $search);
            $builder->orderBy('account_number', 'asc');

            foreach ($builder->get()->getResult() as $row) {
                $suggestions[] = ['value' => $row->person_id, 'label' => $row->account_number];
            }

            $builder = $this->db->table('customers');
            $builder->join('people', 'customers.person_id = people.person_id');
            $builder->where('deleted', 0);
            $builder->like('company_name', $search);
            $builder->orderBy('company_name', 'asc');

            foreach ($builder->get()->getResult() as $row) {
                $suggestions[] = ['value' => $row->person_id, 'label' => $row->company_name];
            }
        }

        // Only return $limit suggestions
        if (count($suggestions) > $limit) {
            $suggestions = array_slice($suggestions, 0, $limit);
        }

        return $suggestions;
    }

    /**
     * Gets rows
     *
     * @param array<string, mixed> $filters
     */
    public function get_found_rows(string $search, array $filters = []): int
    {
        return $this->search($search, 0, 0, 'last_name', 'asc', true, $filters);
    }

    /**
     * Performs a search on customers
     *
     * The filters narrow the directory by what a customer carries: classification_id, manual_status,
     * assigned_employee_id, area, city, country_code, customer_type, created_from and created_to (days), and
     * person_id for one row. visible_employee_ids limits whose name the assigned employee column shows and
     * which employee the directory may be filtered by: null for every employee.
     *
     * @param array<string, mixed> $filters
     */
    public function search(string $search, ?int $rows = 0, ?int $limitFrom = 0, ?string $sort = 'last_name', ?string $order = 'asc', ?bool $countOnly = false, array $filters = [])
    {
        // Set default values
        if ($rows == null) $rows = 0;
        if ($limitFrom == null) $limitFrom = 0;
        if ($sort == null) $sort = 'last_name';
        if ($order == null) $order = 'asc';
        if ($countOnly == null) $countOnly = false;

        $builder = $this->db->table('customers AS customers');

        // get_found_rows case
        if ($countOnly) {
            $builder->select('COUNT(customers.person_id) as count');
        } else {
            $visible = $filters['visible_employee_ids'] ?? null;
            $builder->select("customers.*, people.*, customer_classifications.name AS classification_name, TRIM(CONCAT(assigned_person.first_name, ' ', assigned_person.last_name)) AS assigned_employee_name", false);
            $builder->join('customer_classifications AS customer_classifications', 'customer_classifications.classification_id = customers.classification_id', 'left');
            $builder->join(
                'people AS assigned_person',
                'assigned_person.person_id = customers.assigned_employee_id' . ($visible === null ? '' : ($visible === [] ? ' AND 1 = 0' : ' AND customers.assigned_employee_id IN (' . implode(',', array_map('intval', $visible)) . ')')),
                'left'
            );
        }

        $builder->join('people AS people', 'customers.person_id = people.person_id');
        $builder->groupStart();
        $builder->like('people.first_name', $search);
        $builder->orLike('people.last_name', $search);
        $builder->orLike('people.email', $search);
        $builder->orLike('people.phone_number', $search);
        $builder->orLike('customers.account_number', $search);
        $builder->orLike('customers.company_name', $search);
        $builder->orLike('CONCAT(people.first_name, " ", people.last_name)', $search);    // TODO: Duplicated code.
        $builder->groupEnd();
        $builder->where('customers.deleted', 0);
        $this->applyDirectoryFilters($builder, $filters);

        // get_found_rows case
        if ($countOnly) {
            return $builder->get()->getRow()->count;
        }

        $builder->orderBy(match (true) {
            in_array($sort, ['first_name', 'last_name', 'email', 'phone_number', 'city'], true) => 'people.' . $sort,
            $sort === 'created'                                                                  => 'customers.date',
            default                                                                              => $sort,
        }, $order);

        if ($rows > 0) {
            $builder->limit($rows, $limitFrom);
        }

        return $builder->get();
    }

    /**
     * @param array<string, mixed> $filters
     */
    private function applyDirectoryFilters(BaseBuilder $builder, array $filters): void
    {
        foreach (['classification_id', 'manual_status', 'area', 'country_code', 'customer_type'] as $column) {
            if (isset($filters[$column]) && $filters[$column] !== '') {
                $builder->where('customers.' . $column, $filters[$column]);
            }
        }

        if (isset($filters['city']) && $filters['city'] !== '') {
            $builder->where('people.city', $filters['city']);
        }

        if (isset($filters['assigned_employee_id']) && $filters['assigned_employee_id'] !== '') {
            $visible = $filters['visible_employee_ids'] ?? null;

            if ($visible !== null && ! in_array((int) $filters['assigned_employee_id'], array_map('intval', $visible), true)) {
                $builder->where('1 = 0', null, false);
            } else {
                $builder->where('customers.assigned_employee_id', (int) $filters['assigned_employee_id']);
            }
        }

        if (! empty($filters['created_from'])) {
            $builder->where('DATE(customers.date) >=', $filters['created_from']);
        }

        if (! empty($filters['created_to'])) {
            $builder->where('DATE(customers.date) <=', $filters['created_to']);
        }

        if (isset($filters['person_id'])) {
            $builder->where('customers.person_id', (int) $filters['person_id']);
        }
    }

    /**
     * The totals a customer shows are those of the sales of the branches the viewer works in; null shows
     * every branch.
     *
     * @param list<int>|null $branchIds
     */
    private function limitSalesToBranches(BaseBuilder $builder, ?array $branchIds): void
    {
        if ($branchIds === null) {
            return;
        }

        $branchIds === [] ? $builder->where('1 = 0', null, false) : $builder->whereIn('sales.branch_id', $branchIds);
    }
}
