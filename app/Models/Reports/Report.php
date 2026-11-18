<?php

namespace App\Models\Reports;

use App\Libraries\Organization\BranchGuard;
use App\Libraries\Roles\RecordScope;
use CodeIgniter\Database\BaseBuilder;
use CodeIgniter\HTTP\Response;
use CodeIgniter\Model;

/**
 * @property Response response
 */
abstract class Report extends Model
{
    private bool $readsTheCompany     = false;
    private ?RecordScope $recordScope = null;

    public function __construct()
    {
        parent::__construct();
    }

    /**
     * The branches the figures of a report may be taken from: the ones the logged-in employee works
     * in, and every branch for someone who works in all of them. The system itself, with nobody
     * logged in, is not limited. Null means no limit and an empty list means none at all.
     *
     * Finding them runs queries on the connection the report is being built on, which makes the
     * query builder forget the table aliases of the query in the making, so the aliases are put back.
     *
     * @return list<int>|null
     */
    protected function branchIds(): ?array
    {
        $employeeId = (int) session()->get('person_id');

        if ($employeeId < 1 || $this->readsTheCompany) {
            return null;
        }

        $aliases = $this->db->aliasedTables;

        try {
            return (new BranchGuard())->allowedBranchIds($employeeId);
        } finally {
            $this->db->setAliasedTables($aliases);
        }
    }

    /**
     * Runs a read of the report on the figures of every branch, for a screen whose own records
     * carry no branch and so cannot be limited to one, such as the cash-up, which is closed for the
     * whole company together with the expenses it subtracts.
     */
    public function readingTheCompany(callable $read): mixed
    {
        $this->readsTheCompany = true;

        try {
            return $read();
        } finally {
            $this->readsTheCompany = false;
        }
    }

    protected function limitToBranches(BaseBuilder $builder, string $branchColumn): void
    {
        $branchIds = $this->branchIds();

        if ($branchIds === null) {
            return;
        }

        $branchIds === [] ? $builder->where('1 = 0', null, false) : $builder->whereIn($branchColumn, $branchIds);
    }

    /**
     * Limits the query to the records of the employee named in the inputs, when that employee sees their own
     * records only. A read that names nobody is not limited. Finding out runs queries on the connection the report
     * is being built on, which makes the query builder forget the table aliases of the query in the making, so the
     * aliases are put back.
     *
     * @param array<string, mixed> $inputs
     */
    protected function limitToOwnRecords(BaseBuilder $builder, string $employeeColumn, array $inputs): void
    {
        if (! isset($inputs['viewer_id'])) {
            return;
        }

        $aliases = $this->db->aliasedTables;

        try {
            $cut = ($this->recordScope ??= new RecordScope($this->db))->limit((int) $inputs['viewer_id']);
        } finally {
            $this->db->setAliasedTables($aliases);
        }

        if ($cut !== null) {
            $builder->where($employeeColumn, $cut);
        }
    }

    /**
     * The same limit as a condition to put after the others of a query written by hand.
     */
    protected function branchCondition(string $branchColumn): string
    {
        $branchIds = $this->branchIds();

        return match (true) {
            $branchIds === null => '',
            $branchIds === []   => ' AND 1 = 0',
            default             => " AND {$branchColumn} IN (" . implode(', ', array_map('intval', $branchIds)) . ')',
        };
    }

    /**
     * The inputs of a report with the branches it may be taken from, for the models that build
     * their working tables from them.
     *
     * @param array<string, mixed> $inputs
     *
     * @return array<string, mixed>
     */
    protected function limitedInputs(array $inputs): array
    {
        $inputs['branch_ids'] = $this->branchIds();

        return $inputs;
    }

    /**
     * Returns the column names used for the report
     */
    abstract public function getDataColumns(): array;

    /**
     * Returns all the data to be populated into the report
     */
    abstract public function getData(array $inputs): array;

    /**
     * Returns key=>value pairing of summary data for the report
     */
    abstract public function getSummaryData(array $inputs): array;
}
