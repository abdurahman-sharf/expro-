<?php

namespace App\Libraries\Accounting;

use App\Libraries\Organization\CompanyContext;
use CodeIgniter\Database\BaseConnection;
use Config\Database;

/**
 * The branches a financial report adds up. A report is for one branch, for several, or for the
 * company as a whole, and the whole needs every branch. Someone limited to some branches gets the
 * total of those, and is told it is partial. The employee never gets a branch outside their own,
 * whatever the request names.
 */
final readonly class ReportScope
{
    /**
     * @param list<int>          $ids   The branches the report adds up, in the order of their ids.
     * @param array<int, string> $codes The code of each of them.
     */
    private function __construct(
        public array $ids,
        public array $codes,
        public ReportScopeKind $kind,
    ) {
    }

    /**
     * @param list<int>|null $requested The branches asked for, none for all that may be seen.
     * @param list<int>|null $allowed   The branches the employee works in, null for every branch.
     */
    public static function resolve(?array $requested, ?array $allowed, ?BaseConnection $db = null): self
    {
        $db ??= Database::connect();
        $codes = [];

        foreach ($db->table('branches')->select('branch_id, code')->where('company_id', CompanyContext::id())->orderBy('branch_id', 'ASC')->get()->getResult() as $branch) {
            $codes[(int) $branch->branch_id] = (string) $branch->code;
        }

        $all     = array_keys($codes);
        $visible = $allowed === null ? $all : array_values(array_intersect($all, $allowed));
        $asked   = array_values(array_unique(array_map('intval', $requested ?? [])));
        $chosen  = $asked === [] ? $visible : array_values(array_intersect($visible, $asked));

        $kind = match (true) {
            $chosen !== [] && $chosen === $all        => ReportScopeKind::Company,
            $allowed !== null && $chosen === $visible => ReportScopeKind::Partial,
            default                                   => ReportScopeKind::Selected,
        };

        return new self($chosen, array_intersect_key($codes, array_flip($chosen)), $kind);
    }

    public function isEmpty(): bool
    {
        return $this->ids === [];
    }

    public function isCompany(): bool
    {
        return $this->kind === ReportScopeKind::Company;
    }
}
