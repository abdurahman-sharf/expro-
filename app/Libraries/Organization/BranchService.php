<?php

namespace App\Libraries\Organization;

use App\Libraries\Accounting\AuditLog;
use App\Libraries\Accounting\JournalOrigin;
use App\Libraries\Accounting\RunsInTransaction;
use CodeIgniter\Database\BaseConnection;
use Config\Database;
use DateTimeImmutable;

final class BranchService
{
    use RunsInTransaction;

    private const CODE_PATTERN = '/^[A-Z0-9][A-Z0-9\-]{0,15}$/';
    private const NAME_MAX     = 255;
    private const ADDRESS_MAX  = 255;
    private const SHORT_MAX    = 64;

    private BaseConnection $db;
    private BranchGuard $guard;
    private AuditLog $audit;

    public function __construct(?BaseConnection $db = null, ?BranchGuard $guard = null)
    {
        $this->db    = $db ?? Database::connect();
        $this->guard = $guard ?? new BranchGuard($this->db);
        $this->audit = new AuditLog($this->db);
    }

    /**
     * @param array{code?: string, name?: string, address?: string, phone?: string, tax_registration?: string, opened_on?: string} $input
     *
     * @throws BranchException
     */
    public function create(array $input, ?int $employeeId = null): int
    {
        $this->requireManage($employeeId);
        $this->guard->requireCompanyScope($employeeId);

        $fields = $this->normalize($input);

        $this->assertCodeFree($fields['code'], null);

        return $this->transactional(function () use ($fields, $employeeId): int {
            $this->db->table('branches')->insert($fields + ['company_id' => CompanyContext::id(), 'is_default' => 0, 'active' => 1]);

            $branchId = (int) $this->db->insertID();

            $this->audit->write('branch', $branchId, 'created', $employeeId, JournalOrigin::User, ['code' => $fields['code'], 'name' => $fields['name']]);

            return $branchId;
        });
    }

    /**
     * The code of a branch that already has records cannot change, because it is printed and quoted.
     *
     * @param array{code?: string, name?: string, address?: string, phone?: string, tax_registration?: string, opened_on?: string} $input
     *
     * @throws BranchException
     */
    public function update(int $branchId, array $input, ?int $employeeId = null): void
    {
        $this->requireManage($employeeId);

        $branch = $this->requireBranch($branchId);

        $this->guard->requireBranch($branchId, $employeeId);

        $fields  = $this->normalize($input + (array) $branch);
        $changes = [];

        foreach ($fields as $key => $value) {
            if ((string) ($branch->{$key} ?? '') !== (string) ($value ?? '')) {
                $changes[$key] = $value;
            }
        }

        if ($changes === []) {
            return;
        }

        if (isset($changes['code'])) {
            if ($this->references($branchId) !== []) {
                throw new BranchException(BranchError::CodeLocked, 'The code of a branch with records cannot change.');
            }

            $this->assertCodeFree($changes['code'], $branchId);
        }

        $this->transactional(function () use ($branchId, $changes, $branch, $employeeId): void {
            $this->db->table('branches')->where('branch_id', $branchId)->update($changes);

            $this->audit->write('branch', $branchId, 'updated', $employeeId, JournalOrigin::User, [
                'before' => array_intersect_key((array) $branch, $changes),
                'after'  => $changes,
            ]);
        });
    }

    /**
     * @throws BranchException
     */
    public function deactivate(int $branchId, ?int $employeeId = null): void
    {
        $this->requireManage($employeeId);

        $branch = $this->requireBranch($branchId);

        $this->guard->requireBranch($branchId, $employeeId);

        if ((int) $branch->is_default === 1) {
            throw new BranchException(BranchError::DefaultLocked, 'The default branch cannot be deactivated.');
        }

        if ((int) $branch->active === 0) {
            throw new BranchException(BranchError::AlreadyInactive, 'The branch is already inactive.');
        }

        if ($this->db->table('accounting_journals')->where('branch_id', $branchId)->where('status', 'draft')->countAllResults() > 0) {
            throw new BranchException(BranchError::HasDrafts, 'The branch has draft journals that could no longer be posted.');
        }

        $this->setActive($branchId, 0, 'deactivated', $employeeId);
    }

    /**
     * @throws BranchException
     */
    public function activate(int $branchId, ?int $employeeId = null): void
    {
        $this->requireManage($employeeId);

        $branch = $this->requireBranch($branchId);

        $this->guard->requireBranch($branchId, $employeeId);

        if ((int) $branch->active === 1) {
            throw new BranchException(BranchError::AlreadyActive, 'The branch is already active.');
        }

        $this->setActive($branchId, 1, 'activated', $employeeId);
    }

    /**
     * Only a branch nobody ever used can be deleted. Anything else is deactivated.
     *
     * @throws BranchException
     */
    public function delete(int $branchId, ?int $employeeId = null): void
    {
        $this->requireManage($employeeId);
        $this->guard->requireCompanyScope($employeeId);

        $branch = $this->requireBranch($branchId);

        if ((int) $branch->is_default === 1) {
            throw new BranchException(BranchError::DefaultLocked, 'The default branch cannot be deleted.');
        }

        if ($this->references($branchId) !== []) {
            throw new BranchException(BranchError::InUse, 'The branch has records, locations or employees.', ['references' => $this->references($branchId)]);
        }

        $this->transactional(function () use ($branchId, $branch, $employeeId): void {
            $this->db->table('branches')->where('branch_id', $branchId)->delete();

            $this->audit->write('branch', $branchId, 'deleted', $employeeId, JournalOrigin::User, ['code' => $branch->code, 'name' => $branch->name]);
        });
    }

    /**
     * Makes the given stock locations belong to the branch. A location the branch held and the
     * list leaves out goes back to the default branch, so a location never has no branch.
     *
     * @param list<int> $locationIds
     *
     * @throws BranchException
     */
    public function assignLocations(int $branchId, array $locationIds, ?int $employeeId = null): void
    {
        $this->requireManage($employeeId);
        $this->guard->requireCompanyScope($employeeId);

        $branch      = $this->requireBranch($branchId);
        $locationIds = array_values(array_unique(array_map('intval', $locationIds)));

        if ($locationIds !== []) {
            $found = $this->db->table('stock_locations')->whereIn('location_id', $locationIds)->where('deleted', 0)->countAllResults();

            if ($found !== count($locationIds)) {
                throw new BranchException(BranchError::LocationNotFound, 'A stock location does not exist.');
            }
        }

        $defaultId = $this->guard->companyDefaultBranchId();

        $this->transactional(function () use ($branchId, $branch, $locationIds, $defaultId, $employeeId): void {
            if ($locationIds !== []) {
                $this->db->table('stock_locations')->whereIn('location_id', $locationIds)->update(['branch_id' => $branchId]);
            }

            if ((int) $branch->is_default !== 1) {
                $builder = $this->db->table('stock_locations')->where('branch_id', $branchId);

                if ($locationIds !== []) {
                    $builder->whereNotIn('location_id', $locationIds);
                }

                $builder->update(['branch_id' => $defaultId]);
            }

            $this->audit->write('branch', $branchId, 'locations_assigned', $employeeId, JournalOrigin::User, ['locations' => $locationIds]);
        });
    }

    public function find(int $branchId): ?object
    {
        return $this->db->table('branches')->where('branch_id', $branchId)->where('company_id', CompanyContext::id())->get()->getRow();
    }

    /**
     * Branches the employee may see, with the numbers the list shows.
     *
     * @return list<object>
     */
    public function all(?int $employeeId = null): array
    {
        $branches  = $this->db->prefixTable('branches');
        $locations = $this->db->prefixTable('stock_locations');
        $members   = $this->db->prefixTable('employee_branches');
        $employees = $this->db->prefixTable('employees');
        $journals  = $this->db->prefixTable('accounting_journals');
        $allowed   = $this->guard->allowedBranchIds($employeeId);

        if ($allowed === []) {
            return [];
        }

        $where  = 'b.company_id = ?';
        $params = [CompanyContext::id()];

        if ($allowed !== null) {
            $where .= ' AND b.branch_id IN (' . implode(',', array_map('intval', $allowed)) . ')';
        }

        return $this->db->query(
            "SELECT b.*,
                (SELECT COUNT(*) FROM {$locations} l WHERE l.branch_id = b.branch_id AND l.deleted = 0) AS location_count,
                (SELECT COUNT(*) FROM {$members} m JOIN {$employees} e ON e.person_id = m.person_id WHERE m.branch_id = b.branch_id AND e.deleted = 0) AS employee_count,
                (SELECT COUNT(*) FROM {$journals} j WHERE j.branch_id = b.branch_id) AS journal_count
             FROM {$branches} b
             WHERE {$where}
             ORDER BY b.is_default DESC, b.code ASC",
            $params,
        )->getResult();
    }

    /**
     * Stock locations with the branch each one belongs to, for the assignment list.
     *
     * @return list<object>
     */
    public function locations(): array
    {
        return $this->db->table('stock_locations l')
            ->select('l.location_id, l.location_name, l.branch_id, b.code AS branch_code, b.name AS branch_name')
            ->join('branches b', 'b.branch_id = l.branch_id')
            ->where('l.deleted', 0)
            ->orderBy('l.location_name', 'ASC')
            ->get()
            ->getResult();
    }

    public function suggestCode(): string
    {
        $number = $this->db->table('branches')->where('company_id', CompanyContext::id())->countAllResults() + 1;

        while ($this->db->table('branches')->where('company_id', CompanyContext::id())->where('code', 'BR' . $number)->countAllResults() > 0) {
            $number++;
        }

        return 'BR' . $number;
    }

    /**
     * What ties the branch to other records, by table.
     *
     * @return array<string, int>
     */
    public function references(int $branchId): array
    {
        $counts = [
            'journals'       => $this->db->table('accounting_journals')->where('branch_id', $branchId)->countAllResults(),
            'audit'          => $this->db->table('accounting_audit_log')->where('branch_id', $branchId)->countAllResults(),
            'failures'       => $this->db->table('accounting_posting_failures')->where('branch_id', $branchId)->countAllResults(),
            'closed_periods' => $this->db->table('accounting_closed_periods')->where('branch_id', $branchId)->countAllResults(),
            'proposals'      => $this->db->table('accounting_ai_proposals')->where('branch_id', $branchId)->countAllResults(),
            'locations'      => $this->db->table('stock_locations')->where('branch_id', $branchId)->countAllResults(),
            'employees'      => $this->db->table('employee_branches')->where('branch_id', $branchId)->countAllResults(),
        ];

        return array_filter($counts, static fn (int $count): bool => $count > 0);
    }

    private function setActive(int $branchId, int $active, string $action, ?int $employeeId): void
    {
        $this->transactional(function () use ($branchId, $active, $action, $employeeId): void {
            $this->db->table('branches')->where('branch_id', $branchId)->update(['active' => $active]);
            $this->audit->write('branch', $branchId, $action, $employeeId, JournalOrigin::User);
        });
    }

    private function requireManage(?int $employeeId): void
    {
        if (! $this->guard->has(BranchGuard::BRANCHES_MANAGE, $employeeId)) {
            throw new BranchException(BranchError::PermissionDenied, 'Missing permission ' . BranchGuard::BRANCHES_MANAGE . '.');
        }
    }

    private function requireBranch(int $branchId): object
    {
        $branch = $this->find($branchId);

        if ($branch === null) {
            throw new BranchException(BranchError::NotFound, "Branch {$branchId} does not exist.", ['branch_id' => $branchId]);
        }

        return $branch;
    }

    private function assertCodeFree(string $code, ?int $exceptBranchId): void
    {
        $builder = $this->db->table('branches')->where('company_id', CompanyContext::id())->where('code', $code);

        if ($exceptBranchId !== null) {
            $builder->where('branch_id !=', $exceptBranchId);
        }

        if ($builder->countAllResults() > 0) {
            throw new BranchException(BranchError::CodeTaken, 'The code is already used.', ['code' => $code]);
        }
    }

    /**
     * @param array<string, mixed> $input
     *
     * @return array{code: string, name: string, address: ?string, phone: ?string, tax_registration: ?string, opened_on: ?string}
     */
    private function normalize(array $input): array
    {
        $code = strtoupper(trim((string) ($input['code'] ?? '')));
        $name = trim((string) ($input['name'] ?? ''));

        if (preg_match(self::CODE_PATTERN, $code) !== 1) {
            throw new BranchException(BranchError::InvalidCode, 'The code must be 1 to 16 letters, digits or dashes.', ['code' => $code]);
        }

        if ($name === '' || mb_strlen($name) > self::NAME_MAX) {
            throw new BranchException(BranchError::InvalidName, 'The name is required and must not exceed 255 characters.');
        }

        $openedOn = trim((string) ($input['opened_on'] ?? ''));

        if ($openedOn !== '') {
            $date = DateTimeImmutable::createFromFormat('!Y-m-d', $openedOn);

            if ($date === false || $date->format('Y-m-d') !== $openedOn) {
                throw new BranchException(BranchError::InvalidDate, 'The opening date is not valid.');
            }
        }

        return [
            'code'             => $code,
            'name'             => $name,
            'address'          => $this->optional($input['address'] ?? null, self::ADDRESS_MAX),
            'phone'            => $this->optional($input['phone'] ?? null, self::SHORT_MAX),
            'tax_registration' => $this->optional($input['tax_registration'] ?? null, self::SHORT_MAX),
            'opened_on'        => $openedOn === '' ? null : $openedOn,
        ];
    }

    private function optional(mixed $value, int $max): ?string
    {
        $text = trim((string) $value);

        return $text === '' ? null : mb_substr($text, 0, $max);
    }
}
