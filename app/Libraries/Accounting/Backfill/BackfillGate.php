<?php

namespace App\Libraries\Accounting\Backfill;

use App\Libraries\Accounting\AccountingSettings;
use App\Libraries\Accounting\JournalStatus;
use App\Libraries\Accounting\OpeningBalanceService;
use App\Libraries\Organization\CompanyContext;
use CodeIgniter\Database\BaseConnection;

/**
 * What puts a document out of the retroactive posting before its journals are even built: it is dated
 * before the books begin, or before the opening balances of its branch, which already hold what
 * happened until then, so that a journal for it would count the same thing twice.
 */
final class BackfillGate
{
    private ?string $startDate;

    /**
     * @var array<int, ?string>
     */
    private array $openings = [];

    public function __construct(private readonly BaseConnection $db)
    {
        $this->startDate = (new AccountingSettings($this->db))->startDate();
    }

    public function refusalOf(string $date, int $branchId): ?BackfillRefusal
    {
        if ($this->startDate !== null && $date < $this->startDate) {
            return BackfillRefusal::BeforeStartDate;
        }

        $opening = $this->openingOf($branchId);

        return $opening !== null && $date < $opening ? BackfillRefusal::BeforeOpening : null;
    }

    /**
     * The day the posted opening balances of a branch are dated on, or null when it has none.
     */
    public function openingOf(int $branchId): ?string
    {
        if (! array_key_exists($branchId, $this->openings)) {
            $row = $this->db->table('accounting_journals')
                ->select('journal_date')
                ->where('company_id', CompanyContext::id())
                ->where('source_type', OpeningBalanceService::SOURCE_TYPE)
                ->where('source_id', $branchId)
                ->where('status', JournalStatus::Posted->value)
                ->get()
                ->getRow();

            $this->openings[$branchId] = $row === null ? null : (string) $row->journal_date;
        }

        return $this->openings[$branchId];
    }
}
