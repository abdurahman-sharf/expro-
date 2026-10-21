<?php

namespace Tests\Support;

use App\Libraries\Accounting\AccountingGuard;
use App\Libraries\Accounting\AccountService;
use App\Libraries\Accounting\DefaultChart;
use App\Libraries\Accounting\JournalDraft;
use App\Libraries\Accounting\JournalLineDraft;
use CodeIgniter\Database\BaseConnection;
use Config\Database;

trait AccountingFixtureTrait
{
    use EmployeeFixtureTrait;

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

    /**
     * Empties every accounting table and installs the default chart again, so a test starts
     * from the chart the migration creates.
     */
    protected function reinstallChart(): void
    {
        $db = $this->accountingDb();
        $this->clearJournals();
        $db->query('SET FOREIGN_KEY_CHECKS = 0');

        foreach (['accounting_routing', 'accounting_cost_centers', 'accounting_accounts', 'accounting_fiscal_years'] as $table) {
            $db->table($table)->truncate();
        }

        $db->query('SET FOREIGN_KEY_CHECKS = 1');

        (new DefaultChart($db))->install();
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

    /**
     * Adds a custom posting account under the account with the given code.
     */
    protected function customAccount(string $parentCode = '11', string $code = '1110', string $name = 'Custom account', bool $isGroup = false): int
    {
        return (new AccountService())->create([
            'parent_id' => $this->accountIdByCode($parentCode),
            'code'      => $code,
            'name'      => $name,
            'is_group'  => $isGroup,
        ]);
    }

    /**
     * An employee holding exactly the given permissions and nothing else.
     *
     * @param list<string> $permissionIds
     */
    protected function employeeWithGrants(array $permissionIds): int
    {
        return $this->createEmployee(grants: array_map(
            static fn (string $permissionId): array => ['permission_id' => $permissionId, 'menu_group' => 'home'],
            $permissionIds,
        ));
    }

    /**
     * @return list<string>
     */
    protected function allAccountingPermissions(): array
    {
        return [
            'accounting_chart',
            'accounting_journals',
            'accounting_cost_centers',
            AccountingGuard::CHART_MANAGE,
            AccountingGuard::COST_CENTERS_MANAGE,
            AccountingGuard::JOURNALS_ALL,
            AccountingGuard::JOURNALS_DRAFT,
            AccountingGuard::JOURNALS_POST,
            AccountingGuard::JOURNALS_REVERSE,
            AccountingGuard::JOURNALS_AUDIT,
        ];
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
     * Input for JournalService with a balanced cash and sales pair.
     *
     * @return array{date: string, description: string, lines: list<array<string, mixed>>}
     */
    protected function journalInput(string $amount = '100.00', string $description = 'Test journal'): array
    {
        return [
            'date'        => '2026-10-21',
            'description' => $description,
            'lines'       => [
                ['account_id' => $this->accountId('cash'), 'debit' => $amount, 'credit' => ''],
                ['account_id' => $this->accountId('sales_revenue'), 'debit' => '', 'credit' => $amount],
            ],
        ];
    }

    /**
     * @param list<JournalLineDraft> $lines
     */
    protected function draftWithLines(array $lines, string $date = '2026-10-21'): JournalDraft
    {
        return new JournalDraft(date: $date, lines: $lines, sourceType: 'sale', sourceId: 1, employeeId: 1, currencyCode: 'SAR');
    }
}
