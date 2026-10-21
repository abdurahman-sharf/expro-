<?php

namespace App\Libraries\Accounting;

use CodeIgniter\Database\BaseConnection;

final class JournalNumbering
{
    public function __construct(private BaseConnection $db)
    {
    }

    /**
     * Takes the next number of the manual series. The row is locked until the
     * surrounding transaction ends, so manual numbers never have gaps or repeats.
     *
     * @throws PostingException
     */
    public function nextManual(): string
    {
        $table = $this->db->prefixTable('accounting_sequences');
        $row   = $this->db->query("SELECT last_number FROM {$table} WHERE series = ? FOR UPDATE", ['manual'])->getRow();

        if ($row === null) {
            throw new PostingException(PostingError::WriteFailed, 'The manual journal number series is missing.');
        }

        $next = (int) $row->last_number + 1;
        $this->db->table('accounting_sequences')->where('series', 'manual')->update(['last_number' => $next]);

        return sprintf('%06d', $next);
    }
}
