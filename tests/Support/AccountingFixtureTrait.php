<?php

namespace Tests\Support;

use App\Libraries\Accounting\JournalDraft;
use App\Libraries\Accounting\JournalLineDraft;
use CodeIgniter\Database\BaseConnection;
use Config\Database;

trait AccountingFixtureTrait
{
    protected function accountingDb(): BaseConnection
    {
        return Database::connect();
    }

    protected function setConfigValue(string $key, string $value): void
    {
        $this->accountingDb()->table('app_config')->replace(['key' => $key, 'value' => $value]);
    }

    protected function clearJournals(): void
    {
        $db = $this->accountingDb();
        $this->releaseOpenTransactions();
        $db->query('SET FOREIGN_KEY_CHECKS = 0');

        foreach ([
            'accounting_allocations',
            'accounting_journal_lines',
            'accounting_journals',
            'accounting_posting_failures',
            'accounting_audit_log',
        ] as $table) {
            $db->table($table)->truncate();
        }

        $db->query('SET FOREIGN_KEY_CHECKS = 1');
        $db->table('accounting_sequences')->where('series', 'manual')->update(['last_number' => 0]);
    }

    protected function releaseOpenTransactions(): void
    {
        $db = $this->accountingDb();

        while ($db->transDepth > 0) {
            $db->transRollback();
        }

        $db->resetTransStatus();
    }

    protected function accountId(string $systemKey): int
    {
        $row = $this->accountingDb()->table('accounting_accounts')->where('system_key', $systemKey)->get()->getRow();

        return (int) $row->account_id;
    }

    protected function accountIdByCode(string $code): int
    {
        $row = $this->accountingDb()->table('accounting_accounts')->where('code', $code)->get()->getRow();

        return (int) $row->account_id;
    }

    protected function countRows(string $table): int
    {
        return $this->accountingDb()->table($table)->countAllResults();
    }

    protected function balancedDraft(
        string $sourceType = 'sale',
        ?int $sourceId = 1,
        int $revision = 1,
        string $amount = '100.00',
    ): JournalDraft {
        return new JournalDraft(
            date: '2026-10-21',
            lines: [
                new JournalLineDraft($this->accountId('cash'), debit: $amount),
                new JournalLineDraft($this->accountId('sales_revenue'), credit: $amount),
            ],
            sourceType: $sourceType,
            sourceId: $sourceId,
            revision: $revision,
            employeeId: 1,
            currencyCode: 'SAR',
        );
    }

    /**
     * @param list<JournalLineDraft> $lines
     */
    protected function draftWithLines(array $lines, string $date = '2026-10-21'): JournalDraft
    {
        return new JournalDraft(date: $date, lines: $lines, sourceType: 'sale', sourceId: 1, employeeId: 1, currencyCode: 'SAR');
    }
}
