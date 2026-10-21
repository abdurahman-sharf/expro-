<?php

namespace App\Libraries\Accounting;

use App\Libraries\Organization\CompanyContext;
use CodeIgniter\Database\BaseConnection;
use CodeIgniter\Database\ResultInterface;

/**
 * The rows posting and closing queue on. Posting holds the company row and its branch row
 * shared, so postings never wait for each other. Closing holds the row of its own scope
 * exclusively, so it waits for postings already running and holds back new ones for the
 * moment it takes to write. The order is always company first, then branch, so two requests
 * can never wait for each other.
 *
 * The locks last until the surrounding transaction ends. Outside a transaction they end at
 * once and protect nothing.
 */
final class PeriodLocks
{
    private const COMPANY = 0;

    public function __construct(private BaseConnection $db)
    {
    }

    public function shareForPosting(int $branchId): void
    {
        $this->take(self::COMPANY, false);
        $this->take($branchId, false);
    }

    public function holdCompany(): void
    {
        $this->take(self::COMPANY, true);
    }

    public function holdBranch(int $branchId): void
    {
        $this->take(self::COMPANY, false);
        $this->take($branchId, true);
    }

    private function take(int $branchId, bool $exclusive): void
    {
        $table     = $this->db->prefixTable('accounting_period_locks');
        $companyId = CompanyContext::id();
        $mode      = $exclusive ? 'FOR UPDATE' : 'LOCK IN SHARE MODE';
        $select    = "SELECT branch_id FROM {$table} WHERE company_id = ? AND branch_id = ? {$mode}";

        if ($this->run($select, [$companyId, $branchId])->getRow() !== null) {
            return;
        }

        $this->run("INSERT IGNORE INTO {$table} (company_id, branch_id) VALUES (?, ?)", [$companyId, $branchId]);
        $this->run($select, [$companyId, $branchId]);
    }

    /**
     * A lock that cannot be had, because another request holds it for too long or the server
     * chose this one to give up, must stop the work. Without debugging the connection returns
     * false instead of throwing, and that must not be mistaken for an empty answer.
     *
     * @param list<int> $params
     *
     * @throws AccountingException
     */
    private function run(string $sql, array $params): bool|ResultInterface
    {
        $result = $this->db->query($sql, $params);

        if ($result === false) {
            throw new AccountingException(AccountingError::WriteFailed, 'The period lock could not be taken.');
        }

        return $result;
    }
}
