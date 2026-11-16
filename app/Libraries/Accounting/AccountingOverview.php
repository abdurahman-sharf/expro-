<?php

namespace App\Libraries\Accounting;

use App\Libraries\Organization\BranchGuard;
use App\Libraries\Organization\CompanyContext;
use CodeIgniter\Database\BaseConnection;
use Config\Database;
use DateTimeImmutable;

/**
 * The figures of the overview of the general accounts, each taken from the service that already answers for it and
 * none written: the screen only reads. A figure that no service totals cheaply is not offered.
 */
final class AccountingOverview
{
    public const TREASURIES_SHOWN = 8;
    public const JOURNALS_SHOWN   = 5;

    private BaseConnection $db;
    private ?BranchGuard $branches;

    public function __construct(?BaseConnection $db = null, ?BranchGuard $branches = null)
    {
        $this->db       = $db ?? Database::connect();
        $this->branches = $branches;
    }

    /**
     * The active treasuries the reader may see with their balances, the first few and the total of all of them.
     *
     * @param list<int>|null $branchIds Null for every branch.
     *
     * @return array{rows: list<array{id: int, name: string, kind: string, balance: string}>, total: string, more: int}
     */
    public function treasuries(?array $branchIds): array
    {
        $service = new TreasuryService($this->db, null, $this->branches);
        $all     = $service->all($branchIds, false);
        $rows    = [];
        $total   = '0.0000';

        foreach ($all as $treasury) {
            $balance = $service->position((int) $treasury->treasury_id)->balance;
            $total   = bcadd($total, $balance, SaleAmounts::SCALE);

            if (count($rows) < self::TREASURIES_SHOWN) {
                $rows[] = ['id' => (int) $treasury->treasury_id, 'name' => (string) $treasury->name, 'kind' => (string) $treasury->kind, 'balance' => $balance];
            }
        }

        return ['rows' => $rows, 'total' => $total, 'more' => count($all) - count($rows)];
    }

    /**
     * What every customer owes, or what every supplier is owed: the total of the accounts of the kind, which agrees
     * with the balance report of the kind and with the trial balance.
     *
     * @param string         $kind      EntityAccountService::CUSTOMER or EntityAccountService::SUPPLIER.
     * @param list<int>|null $branchIds Null for every branch.
     */
    public function partyTotal(string $kind, ?array $branchIds): string
    {
        $accountIds = array_column(
            $this->db->table('accounting_accounts')->select('account_id')->where('company_id', CompanyContext::id())->where('entity_type', $kind)->get()->getResultArray(),
            'account_id',
        );
        $balances = (new AccountService($this->db))->balances($branchIds);
        $total    = '0.0000';

        foreach ($accountIds as $accountId) {
            $total = bcadd($total, $balances[(int) $accountId]['balance'] ?? '0', SaleAmounts::SCALE);
        }

        return $total;
    }

    /**
     * The year that covers today, the date the company has closed its books through and the days left of the last year
     * when it is about to end with no year after it.
     *
     * @return array{year: ?object, closed_through: ?string, days_left: ?int}
     */
    public function period(): array
    {
        $years = new FiscalYearService($this->db, null, $this->branches);

        return [
            'year'           => $years->covering((new DateTimeImmutable())->format('Y-m-d')),
            'closed_through' => (new ClosedPeriodService($this->db, null, $this->branches))->cutoff(null),
            'days_left'      => $years->daysUntilLastYearEnds(),
        ];
    }

    /**
     * The latest journals the employee may see, by their date.
     *
     * @return list<object>
     */
    public function latestJournals(?int $employeeId): array
    {
        return (new JournalService($this->db, null, null, $this->branches))->search([], 'journal_date', 'desc', self::JOURNALS_SHOWN, 0, $employeeId)['rows'];
    }
}
