<?php

namespace App\Libraries\MasterData;

use App\Libraries\Accounting\AccountingError;
use App\Libraries\Accounting\AccountingException;
use App\Libraries\Accounting\AuditLog;
use App\Libraries\Accounting\JournalOrigin;
use App\Libraries\Organization\BranchGuard;
use App\Models\Employee;
use CodeIgniter\Database\BaseConnection;
use Config\Database;

/**
 * The fields of a customer that the books never read: the classification, the manual status of the
 * follow-up, the employee the customer is assigned to, the area, the country, the type, the government flag
 * and the billing method. A value that is not one of the allowed ones is refused with a named reason before
 * the customer is saved; an employee can be assigned only from the branches the one who assigns works in;
 * and every change is written to the audit log. Changing any of them touches no document and no journal.
 */
final class CustomerFields
{
    public const AREA_MAX = 100;
    public const FIELDS   = ['classification_id', 'manual_status', 'assigned_employee_id', 'area', 'country_code', 'customer_type', 'is_government_entity', 'billing_method'];
    public const MARKER   = 'master_data';

    private BaseConnection $db;
    private Employee $employee;

    public function __construct(?BaseConnection $db = null, ?Employee $employee = null)
    {
        $this->db       = $db ?? Database::connect();
        $this->employee = $employee ?? model(Employee::class);
    }

    /**
     * What the customer should hold after the form was saved, as the columns of the customer. The form
     * names every field, so a field that was left empty clears it.
     *
     * @param array<string, mixed> $posted
     *
     * @return array<string, int|string|null>
     *
     * @throws AccountingException
     */
    public function read(array $posted, ?object $current, ?int $viewerId, string $email): array
    {
        $held    = static fn (string $field): mixed => $current->{$field} ?? null;
        $columns = [
            'classification_id'    => $this->classification($this->text($posted, 'classification_id'), $held('classification_id')),
            'manual_status'        => $this->manualStatus($this->text($posted, 'manual_status')),
            'assigned_employee_id' => $this->assignedEmployee($this->text($posted, 'assigned_employee_id'), $held('assigned_employee_id'), $viewerId),
            'area'                 => $this->area($this->text($posted, 'area')),
            'country_code'         => self::countryCode($this->text($posted, 'country_code')),
            'customer_type'        => $this->customerType($this->text($posted, 'customer_type')),
            'is_government_entity' => empty($posted['is_government_entity']) ? 0 : 1,
            'billing_method'       => $this->billingMethod($this->text($posted, 'billing_method'), $email),
        ];

        return $columns;
    }

    /**
     * Writes what changed to the audit log, when anything did.
     *
     * @param array<string, int|string|null> $after
     */
    public function audit(int $customerId, ?object $before, array $after, ?int $employeeId): void
    {
        $changes = [];

        foreach (self::FIELDS as $field) {
            $was = $before->{$field} ?? self::neutral($field);
            $was = $was === '' ? null : $was;

            if ((string) $was !== (string) $after[$field]) {
                $changes[$field] = ['from' => $was, 'to' => $after[$field]];
            }
        }

        if ($changes !== []) {
            (new AuditLog($this->db))->write('customer', $customerId, 'customer_master_data_changed', $employeeId, JournalOrigin::User, ['changes' => $changes]);
        }
    }

    /**
     * The employees one may assign a customer to: everyone for an employee of every branch, and for one
     * limited to some branches, the employees who work in at least one of them.
     *
     * @return array<int, string> The name of each employee, by number.
     */
    public function assignableEmployees(?int $viewerId): array
    {
        $allowed = (new BranchGuard($this->db, $this->employee))->allowedBranchIds($viewerId);

        if ($allowed === []) {
            return [];
        }

        $builder = $this->db->table('employees e')
            ->select("e.person_id, TRIM(CONCAT(p.first_name, ' ', p.last_name)) AS name", false)
            ->join('people p', 'p.person_id = e.person_id')
            ->where('e.deleted', 0);

        if ($allowed !== null) {
            $branches = implode(',', array_map('intval', $allowed));
            $members  = $this->db->prefixTable('employee_branches');
            $grants   = $this->db->prefixTable('grants');

            $builder->where("(e.person_id IN (SELECT person_id FROM {$members} WHERE branch_id IN ({$branches})) OR e.person_id IN (SELECT person_id FROM {$grants} WHERE permission_id = " . $this->db->escape(BranchGuard::ALL_BRANCHES) . '))', null, false);
        }

        $names = [];

        foreach ($builder->orderBy('p.first_name')->orderBy('p.last_name')->get()->getResult() as $row) {
            $names[(int) $row->person_id] = (string) $row->name;
        }

        return $names;
    }

    public static function neutral(string $field): int|string|null
    {
        return match ($field) {
            'is_government_entity' => 0,
            'billing_method'       => BillingMethod::Print->value,
            default                => null,
        };
    }

    /**
     * An ISO 3166 alpha-2 code in capitals, or null for none.
     *
     * @throws AccountingException
     */
    public static function countryCode(string $typed): ?string
    {
        if ($typed === '') {
            return null;
        }

        $code = strtoupper($typed);

        if (preg_match('/^[A-Z]{2}$/', $code) !== 1) {
            throw new AccountingException(AccountingError::CountryCodeInvalid, 'A country code is two letters.', ['code' => $typed]);
        }

        return $code;
    }

    /**
     * @param array<string, mixed> $posted
     */
    private function text(array $posted, string $field): string
    {
        $value = $posted[$field] ?? '';

        return is_scalar($value) ? trim((string) $value) : '';
    }

    private function classification(string $typed, mixed $held): ?int
    {
        if ($typed === '' || $typed === '0') {
            return null;
        }

        if (! ctype_digit($typed) || ! (new NamedLookup(LookupKind::CustomerClassification, $this->db))->mayBeGiven((int) $typed, $held === null ? null : (int) $held)) {
            throw new AccountingException(AccountingError::CustomerClassificationInvalid, 'The classification does not exist or is inactive.');
        }

        return (int) $typed;
    }

    private function manualStatus(string $typed): ?string
    {
        if ($typed === '') {
            return null;
        }

        if (CustomerStatus::tryFrom($typed) === null) {
            throw new AccountingException(AccountingError::CustomerStatusInvalid, 'The status is not one of the five.');
        }

        return $typed;
    }

    private function assignedEmployee(string $typed, mixed $held, ?int $viewerId): ?int
    {
        if ($typed === '' || $typed === '0') {
            return null;
        }

        if (! ctype_digit($typed) || ((int) $typed !== (int) $held && ! array_key_exists((int) $typed, $this->assignableEmployees($viewerId)))) {
            throw new AccountingException(AccountingError::CustomerEmployeeInvalid, 'The employee does not exist or works in no branch of the one who assigns.');
        }

        return (int) $typed;
    }

    private function area(string $typed): ?string
    {
        if (mb_strlen($typed) > self::AREA_MAX) {
            throw new AccountingException(AccountingError::CustomerAreaInvalid, 'An area is at most 100 characters.', ['max' => self::AREA_MAX]);
        }

        return $typed === '' ? null : $typed;
    }

    private function customerType(string $typed): ?string
    {
        if ($typed === '') {
            return null;
        }

        if (CustomerType::tryFrom($typed) === null) {
            throw new AccountingException(AccountingError::CustomerTypeInvalid, 'The type is individual or commercial.');
        }

        return $typed;
    }

    private function billingMethod(string $typed, string $email): string
    {
        $method = $typed === '' ? BillingMethod::Print : BillingMethod::tryFrom($typed);

        if ($method === null) {
            throw new AccountingException(AccountingError::CustomerBillingInvalid, 'The billing method is print or e-mail.');
        }

        if ($method === BillingMethod::Email && trim($email) === '') {
            throw new AccountingException(AccountingError::CustomerEmailRequired, 'Billing by e-mail needs the e-mail address of the customer.');
        }

        return $method->value;
    }
}
