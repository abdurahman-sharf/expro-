<?php

namespace Tests\Support;

use App\Libraries\Accounting\AccountingError;
use App\Libraries\Accounting\AccountingException;
use App\Libraries\Accounting\AccountingGuard;
use App\Libraries\Accounting\AccountService;
use App\Libraries\Accounting\DefaultChart;
use App\Libraries\Organization\BranchGuard;
use App\Libraries\Accounting\JournalDraft;
use App\Libraries\Accounting\JournalEntryType;
use App\Libraries\Accounting\JournalLineDraft;
use CodeIgniter\Database\BaseConnection;
use CodeIgniter\Database\Exceptions\DatabaseException;
use Config\Database;

trait AccountingFixtureTrait
{
    use EmployeeFixtureTrait;

    /** @var list<int> */
    private array $insertedPeople = [];
    /** @var list<int> */
    private array $insertedLocations = [];

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
            'customer_credit_applications',
            'supplier_credit_applications',
            'supplier_advances',
            'treasury_transfers',
            'accounting_stock_entries',
            'accounting_allocations',
            'accounting_journal_attachments',
            'accounting_journal_lines',
            'accounting_journals',
            'accounting_posting_failures',
            'accounting_audit_log',
            'accounting_closed_periods',
            'accounting_opening_drafts',
        ] as $table) {
            $db->table($table)->truncate();
        }

        $this->removeTreasuries();
        $db->query('SET FOREIGN_KEY_CHECKS = 1');
        $db->table('accounting_sequences')->where('series', 'manual')->update(['last_number' => 0]);

        $this->resetBranches();
        $this->installFiscalYears();
    }

    /**
     * Takes the treasuries a test made out of the books: the accounts opened for them, and the main
     * treasury's hold on the account every payment goes to, so the next test starts without one.
     */
    private function removeTreasuries(): void
    {
        $db = $this->accountingDb();

        $db->table('employees')->update(['default_treasury_id' => null]);
        $db->table('treasury_scope_members')->truncate();
        $db->table('treasuries')->truncate();
        $db->table('accounting_accounts')->where('entity_type', 'treasury')->where('system_key IS NULL', null, false)->delete();
        $db->table('accounting_accounts')->where('entity_type', 'treasury')->update(['entity_type' => null, 'entity_id' => null]);

        foreach ($db->table('accounting_accounts')->select('account_id')->whereIn('system_key', ['treasuries', 'banks'])->get()->getResult() as $parent) {
            $db->table('accounting_sequences')->where('series', 'sub:' . $parent->account_id)->delete();
        }
    }

    /**
     * One fiscal year per calendar year from 2020 to 2035, so a test can post on any date it
     * likes. A test about fiscal years starts from reinstallChart() and replaces them.
     */
    protected function installFiscalYears(): void
    {
        $rows = [];

        for ($year = 2020; $year <= 2035; $year++) {
            $rows[] = ['company_id' => 1, 'name' => (string) $year, 'start_date' => "{$year}-01-01", 'end_date' => "{$year}-12-31"];
        }

        $db = $this->accountingDb();

        $db->table('accounting_fiscal_years')
            ->where('company_id', 1)
            ->groupStart()->where('start_date <', '2020-01-01')->orWhere('start_date >', '2035-12-31')->groupEnd()
            ->delete();
        $db->table('accounting_fiscal_years')->ignore(true)->insertBatch($rows);
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
        $db->table('accounting_sequences')->like('series', 'sub:', 'after')->delete();

        (new DefaultChart($db))->install();
        $this->installFiscalYears();
    }

    /**
     * Leaves only the default branch, which every test starts from.
     */
    protected function resetBranches(): void
    {
        $db = $this->accountingDb();
        $db->query('SET FOREIGN_KEY_CHECKS = 0');

        $db->table('employee_branches')->where('branch_id !=', 1)->delete();
        $db->table('stock_locations')->update(['branch_id' => 1]);
        $db->table('branches')->where('branch_id !=', 1)->delete();
        $db->table('branches')->where('branch_id', 1)->update(['active' => 1, 'is_default' => 1, 'code' => 'HQ']);

        $db->query('SET FOREIGN_KEY_CHECKS = 1');
    }

    protected function createBranch(string $code = 'B2', string $name = 'Second branch', bool $active = true): int
    {
        $db = $this->accountingDb();
        $db->table('branches')->insert(['company_id' => 1, 'code' => $code, 'name' => $name, 'active' => $active ? 1 : 0]);

        return (int) $db->insertID();
    }

    /**
     * Closes a scope through a date by writing the row directly, so a test about posting needs
     * no permission or service to set it up. A null branch closes the company.
     */
    protected function closeThrough(string $date, ?int $branchId = null): void
    {
        $this->accountingDb()->table('accounting_closed_periods')->insert([
            'company_id' => 1,
            'branch_id'  => $branchId,
            'date_from'  => '2020-01-01',
            'date_to'    => $date,
            'active'     => 1,
        ]);
    }

    /**
     * A second connection to the test database, standing for a request running at the same
     * time. It gives up on a lock after a second instead of waiting out the server's default,
     * and with $debug false it behaves as production does: a failed query returns false.
     */
    protected function openSecondConnection(bool $debug = true): BaseConnection
    {
        $config            = (new Database())->tests;
        $config['DBDebug'] = $debug;

        $connection = Database::connect($config, false);
        $connection->query('SET SESSION innodb_lock_wait_timeout = 1');

        return $connection;
    }

    /**
     * Starts a transaction on the connection and reads once, which fixes the snapshot that its
     * later plain reads see.
     */
    protected function startWithSnapshot(BaseConnection $connection): void
    {
        $connection->resetTransStatus();
        $connection->transStart();
        $connection->query('SELECT COUNT(*) FROM ' . $connection->prefixTable('accounting_closed_periods'));
    }

    /**
     * The action must stop on a lock another connection holds: either the accounting layer
     * reports the write as failed or the database reports the wait as timed out.
     */
    protected function assertWaitsForLock(callable $action): void
    {
        try {
            $action();
        } catch (AccountingException $exception) {
            $this->assertSame(AccountingError::WriteFailed, $exception->error);

            return;
        } catch (DatabaseException $exception) {
            $this->assertStringContainsString('Lock wait timeout', $exception->getMessage());

            return;
        }

        $this->fail('Expected the request to wait for a lock.');
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

    protected function insertPerson(string $firstName, string $lastName): int
    {
        $db = $this->accountingDb();

        $db->table('people')->insert([
            'first_name'   => $firstName,
            'last_name'    => $lastName,
            'phone_number' => '',
            'email'        => '',
            'address_1'    => '',
            'address_2'    => '',
            'city'         => '',
            'state'        => '',
            'zip'          => '',
            'country'      => '',
            'comments'     => '',
        ]);

        return $this->insertedPeople[] = (int) $db->insertID();
    }

    /**
     * Removes the customers, suppliers and locations the helpers wrote, and the accounts that
     * were opened for them, so the next test starts without entities of its own.
     */
    protected function removeInsertedEntities(): void
    {
        $db = $this->accountingDb();
        $this->releaseOpenTransactions();
        $this->clearJournals();

        if ($this->insertedPeople !== []) {
            $db->table('accounting_accounts')->whereIn('entity_type', ['customer', 'supplier'])->whereIn('entity_id', $this->insertedPeople)->delete();
            $db->table('customers')->whereIn('person_id', $this->insertedPeople)->delete();
            $db->table('suppliers')->whereIn('person_id', $this->insertedPeople)->delete();
            $db->table('people')->whereIn('person_id', $this->insertedPeople)->delete();
        }

        if ($this->insertedLocations !== []) {
            $db->table('accounting_accounts')->where('entity_type', 'stock_location')->whereIn('entity_id', $this->insertedLocations)->delete();
            $db->table('stock_locations')->whereIn('location_id', $this->insertedLocations)->delete();
        }

        $this->insertedPeople    = [];
        $this->insertedLocations = [];
    }

    /**
     * Writes the rows directly so the entity hooks of the models stay out of the test.
     */
    protected function insertCustomer(string $firstName = 'Omar', string $lastName = 'Khaled', ?string $company = null): int
    {
        $personId = $this->insertPerson($firstName, $lastName);

        $this->accountingDb()->table('customers')->insert(['person_id' => $personId, 'company_name' => $company, 'employee_id' => 1]);

        return $personId;
    }

    protected function insertSupplier(string $company = 'Gulf Supplies', string $firstName = 'Sara', string $lastName = 'Nasser'): int
    {
        $personId = $this->insertPerson($firstName, $lastName);

        $this->accountingDb()->table('suppliers')->insert(['person_id' => $personId, 'company_name' => $company, 'agency_name' => '', 'category' => 0]);

        return $personId;
    }

    protected function insertStockLocation(string $name = 'Warehouse'): int
    {
        $db = $this->accountingDb();

        $db->table('stock_locations')->insert(['location_name' => $name, 'deleted' => 0, 'branch_id' => 1]);

        return $this->insertedLocations[] = (int) $db->insertID();
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
     * An employee holding exactly the given permissions and nothing else. Without branches the
     * employee may work in every branch. With a list of branch ids the employee is limited to
     * those, and the first one is the default.
     *
     * @param list<string>   $permissionIds
     * @param list<int>|null $branchIds
     */
    protected function employeeWithGrants(array $permissionIds, ?array $branchIds = null): int
    {
        if ($branchIds === null) {
            $permissionIds[] = BranchGuard::ALL_BRANCHES;
        }

        $personId = $this->createEmployee(grants: array_map(
            static fn (string $permissionId): array => ['permission_id' => $permissionId, 'menu_group' => 'home'],
            array_values(array_unique($permissionIds)),
        ));

        foreach ($branchIds ?? [] as $index => $branchId) {
            $this->accountingDb()->table('employee_branches')->insert(['person_id' => $personId, 'branch_id' => $branchId, 'is_default' => $index === 0 ? 1 : 0]);
        }

        return $personId;
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
        JournalEntryType $entryType = JournalEntryType::General,
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
            entryType: $entryType,
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
