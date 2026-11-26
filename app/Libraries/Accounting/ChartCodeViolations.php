<?php

namespace App\Libraries\Accounting;

use App\Libraries\Organization\CompanyContext;
use CodeIgniter\Database\BaseConnection;
use Config\Database;

/**
 * The accounts of the chart whose code does not start with the code of their parent and go on after
 * it. New accounts and new codes are held to that rule; this lists the accounts that were there
 * before it, for somebody to give them a code under their parent. It reads and changes nothing.
 */
final class ChartCodeViolations
{
    private BaseConnection $db;

    public function __construct(?BaseConnection $db = null)
    {
        $this->db = $db ?? Database::connect();
    }

    /**
     * @return list<array{account_id: int, code: string, name: string, parent_code: string, parent_name: string, has_journal_lines: bool}>
     */
    public function list(): array
    {
        $accounts = $this->db->prefixTable('accounting_accounts');
        $lines    = $this->db->prefixTable('accounting_journal_lines');

        $rows = $this->db->query(
            "SELECT a.account_id, a.code, a.name, p.code AS parent_code, p.name AS parent_name,
                    EXISTS (SELECT 1 FROM {$lines} l WHERE l.account_id = a.account_id) AS has_lines
             FROM {$accounts} a
             JOIN {$accounts} p ON p.account_id = a.parent_id
             WHERE a.company_id = ?
               AND (CHAR_LENGTH(a.code) <= CHAR_LENGTH(p.code) OR LEFT(a.code, CHAR_LENGTH(p.code)) <> p.code)
             ORDER BY a.code, a.account_id",
            [CompanyContext::id()],
        )->getResult();

        return array_map(static fn (object $row): array => [
            'account_id'        => (int) $row->account_id,
            'code'              => (string) $row->code,
            'name'              => (string) $row->name,
            'parent_code'       => (string) $row->parent_code,
            'parent_name'       => (string) $row->parent_name,
            'has_journal_lines' => (int) $row->has_lines === 1,
        ], $rows);
    }
}
