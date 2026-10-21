<?php

namespace App\Libraries\Accounting;

use App\Libraries\Organization\CompanyContext;
use CodeIgniter\Database\BaseConnection;

final class JournalNumbering
{
    public function __construct(private BaseConnection $db)
    {
    }

    /**
     * Takes the next number of the company's manual series. The row is locked until the
     * surrounding transaction ends, so manual numbers never have gaps or repeats.
     *
     * @throws PostingException
     */
    public function nextManual(?int $companyId = null): string
    {
        $companyId ??= CompanyContext::id();
        $table = $this->db->prefixTable('accounting_sequences');
        $row   = LockedRead::row($this->db, "SELECT last_number FROM {$table} WHERE company_id = ? AND series = ? FOR UPDATE", [$companyId, 'manual']);

        if ($row === null) {
            throw new PostingException(PostingError::WriteFailed, 'The manual journal number series is missing.');
        }

        $next = (int) $row->last_number + 1;
        $this->db->table('accounting_sequences')->where('company_id', $companyId)->where('series', 'manual')->update(['last_number' => $next]);

        return sprintf('%06d', $next);
    }
}
