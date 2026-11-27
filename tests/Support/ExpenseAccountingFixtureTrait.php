<?php

namespace Tests\Support;

use App\Libraries\Accounting\TreasuryScopes;
use App\Libraries\Accounting\TreasuryService;
use App\Models\Cashup;
use App\Models\Expense;
use App\Models\Expense_category;
use CodeIgniter\Events\Events;

/**
 * What a test needs to put expenses and cash-ups through the real models with accounting on, and
 * to read back what they posted. A class that uses it also uses AccountingFixtureTrait and
 * EmployeeFixtureTrait.
 */
trait ExpenseAccountingFixtureTrait
{
    protected Expense $expenses;
    protected Cashup $cashups;
    protected int $clerkId;

    /** @var list<int> */
    protected array $categoryIds = [];
    /** @var list<int> */
    protected array $saleRowIds = [];

    protected bool $expensesStarted = false;

    /**
     * Installs the chart, turns accounting on from the first of 2020, and gives the tests an
     * employee who works in every branch.
     */
    protected function startExpenses(): void
    {
        $this->clearExpenseRows();
        $this->reinstallChart();
        $this->setConfigValue('accounting_base_currency', 'SAR');
        $this->setConfigValue('accounting_start_date', '2020-01-01');
        $this->setConfigValue('accounting_expense_account_required', '0');
        $this->setConfigValue('accounting_enabled', '1');

        $this->accountingDb()->table('accounting_posting_shadow_diffs')->where('operation', 'expense')->delete();

        $this->expenses        = model(Expense::class);
        $this->cashups         = model(Cashup::class);
        $this->clerkId         = $this->employeeWithGrants([]);
        $this->expensesStarted = true;
    }

    protected function stopExpenses(): void
    {
        if (! $this->expensesStarted) {
            return;
        }

        $this->expensesStarted = false;

        $diffs = $this->accountingDb()->table('accounting_posting_shadow_diffs')->where('operation', 'expense')->get()->getResultArray();

        $this->accountingDb()->table('accounting_posting_shadow_diffs')->where('operation', 'expense')->delete();
        $this->assertSame([], $diffs, 'The posting engine built another journal than the expense posted');

        $this->setConfigValue('accounting_enabled', '0');
        $this->setConfigValue('accounting_expense_account_required', '0');
        $this->clearExpenseRows();
        $this->clearJournals();
    }

    /**
     * Takes every expense, cash-up, category and sale the tests wrote out of the database, which
     * releases the treasuries and the accounts they pointed at.
     */
    protected function clearExpenseRows(): void
    {
        $db = $this->accountingDb();
        $this->releaseOpenTransactions();
        $db->query('SET FOREIGN_KEY_CHECKS = 0');
        $db->table('expenses')->truncate();
        $db->table('cash_up')->truncate();

        foreach ($this->saleRowIds as $saleId) {
            $db->table('sales_payments')->where('sale_id', $saleId)->delete();
            $db->table('sales')->where('sale_id', $saleId)->delete();
        }

        if ($this->categoryIds !== []) {
            $db->table('expense_categories')->whereIn('expense_category_id', $this->categoryIds)->delete();
        }

        $db->query('SET FOREIGN_KEY_CHECKS = 1');
        $this->categoryIds = [];
        $this->saleRowIds  = [];
    }

    protected function category(string $name = 'Rent', ?int $accountId = null): int
    {
        $db = $this->accountingDb();

        $db->table('expense_categories')->insert(['category_name' => $name . ' ' . uniqid(), 'category_description' => '', 'deleted' => 0, 'account_id' => $accountId]);

        return $this->categoryIds[] = (int) $db->insertID();
    }

    /**
     * The columns of an expense as the controller builds them: the amount is what was paid, tax
     * included, and the tax is the part of it that is tax.
     *
     * @return array<string, mixed>
     */
    protected function expenseData(string $amount, string $tax = '0', string $payment = 'Cash', ?int $categoryId = null, string $date = '2026-10-20 10:00:00', ?int $employeeId = null): array
    {
        return [
            'date'                => $date,
            'supplier_id'         => null,
            'supplier_tax_code'   => '',
            'amount'              => $amount,
            'tax_amount'          => $tax,
            'payment_type'        => $payment,
            'expense_category_id' => $categoryId ?? $this->category(),
            'description'         => 'Test expense',
            'employee_id'         => $employeeId ?? $this->clerkId,
            'deleted'             => 0,
        ];
    }

    /**
     * Saves an expense through the model and returns its id, or null when the books refused it.
     *
     * @param array<string, mixed> $data
     */
    protected function saveExpense(array $data, int $expenseId = NEW_ENTRY, ?int $actorId = null): ?int
    {
        $saved = $this->expenses->save_value($data, $expenseId, $actorId ?? $this->clerkId);

        return $saved ? (int) ($expenseId === NEW_ENTRY ? $data['expense_id'] : $expenseId) : null;
    }

    /**
     * The columns of a cash-up as the controller builds them. The cash that was counted is the
     * closed cash.
     *
     * @return array<string, mixed>
     */
    protected function cashupData(string $counted, string $open = '100', string $transfer = '0', string $openDate = '2026-10-20 08:00:00', string $closeDate = '2026-10-20 18:00:00', ?int $employeeId = null): array
    {
        $employeeId ??= $this->clerkId;

        return [
            'open_date'            => $openDate,
            'close_date'           => $closeDate,
            'open_amount_cash'     => $open,
            'transfer_amount_cash' => $transfer,
            'closed_amount_cash'   => $counted,
            'closed_amount_due'    => '0',
            'closed_amount_card'   => '0',
            'closed_amount_check'  => '0',
            'closed_amount_total'  => bcsub($counted, $open, 2),
            'note'                 => 0,
            'description'          => 'Test cash-up',
            'open_employee_id'     => $employeeId,
            'close_employee_id'    => $employeeId,
            'deleted'              => 0,
        ];
    }

    /**
     * @param array<string, mixed> $data
     */
    protected function saveCashup(array $data, int $cashupId = NEW_ENTRY, ?int $actorId = null): ?int
    {
        $saved = $this->cashups->save_value($data, $cashupId, $actorId ?? $this->clerkId);

        return $saved ? (int) ($cashupId === NEW_ENTRY ? $data['cashup_id'] : $cashupId) : null;
    }

    /**
     * A completed sale with one payment, written straight to the rows a cash-up reads.
     */
    protected function sellFor(string $amount, string $paymentType = 'Cash', string $time = '2026-10-20 12:00:00', int $branchId = 1, string $status = 'completed', string $refund = '0', int $saleType = SALE_TYPE_POS): int
    {
        $db = $this->accountingDb();

        $db->table('sales')->insert([
            'sale_time'   => $time,
            'employee_id' => $this->clerkId,
            'comment'     => '',
            'sale_status' => COMPLETED,
            'sale_type'   => $saleType,
            'branch_id'   => $branchId,
        ]);

        $saleId = (int) $db->insertID();

        $db->table('sales_payments')->insert([
            'sale_id'        => $saleId,
            'payment_type'   => $paymentType,
            'payment_amount' => $amount,
            'cash_refund'    => $refund,
            'employee_id'    => $this->clerkId,
            'reference_code' => '',
            'payment_status' => $status,
        ]);

        return $this->saleRowIds[] = $saleId;
    }

    /**
     * The journal lines of a posted journal of a source, as "account=debit/credit" in line order.
     *
     * @return list<string>
     */
    protected function postedLines(string $source, int $sourceId): array
    {
        $journal = $this->accountingDb()->table('accounting_journals')->where(['source_type' => $source, 'source_id' => $sourceId, 'status' => 'posted'])->get()->getRow();

        $this->assertNotNull($journal, "No journal stands for {$source} {$sourceId}");

        return $this->linesOfJournal((int) $journal->journal_id);
    }

    /**
     * @return list<string>
     */
    protected function linesOfJournal(int $journalId): array
    {
        return array_map(
            static fn (object $line): string => $line->account_id . '=' . bcadd($line->debit, '0', 2) . '/' . bcadd($line->credit, '0', 2),
            $this->accountingDb()->table('accounting_journal_lines')->where('journal_id', $journalId)->orderBy('line_no')->get()->getResult(),
        );
    }

    /**
     * @return list<object> Every journal of a source, oldest first.
     */
    protected function journalsOfSource(string $source, int $sourceId): array
    {
        return $this->accountingDb()->table('accounting_journals')->where(['source_type' => $source, 'source_id' => $sourceId])->orderBy('journal_id')->get()->getResult();
    }

    /**
     * What an account holds: debits less credits of the journals that are in the books.
     */
    protected function balanceOf(int $accountId): string
    {
        $row = $this->accountingDb()->table('accounting_journal_lines l')
            ->select('COALESCE(SUM(l.debit), 0) - COALESCE(SUM(l.credit), 0) AS balance', false)
            ->join('accounting_journals j', 'j.journal_id = l.journal_id')
            ->whereIn('j.status', ['posted', 'reversed'])
            ->where('l.account_id', $accountId)
            ->get()
            ->getRow();

        return bcadd((string) $row->balance, '0', 2);
    }

    protected function treasury(string $name = 'Shop drawer', ?int $branchId = null): int
    {
        return (new TreasuryService())->create(['name' => $name . ' ' . uniqid(), 'kind' => 'cash', 'branch_id' => $branchId ?? 0]);
    }

    /**
     * Limits who may withdraw from the treasury, or who may deposit into it, to the employees
     * given.
     *
     * @param list<int> $employeeIds
     */
    protected function restrictTreasury(int $treasuryId, string $direction, array $employeeIds): void
    {
        $scopes = new TreasuryScopes();

        $scopes->apply($treasuryId, $scopes->validated([$direction . '_scope' => TreasuryScopes::STAFF, $direction . '_members' => $employeeIds]), null);
    }

    /**
     * The treasury balance and the balance of its account, which must be the same figure.
     */
    protected function assertTreasuryTiesToItsAccount(int $treasuryId): string
    {
        $treasuries = new TreasuryService();
        $balance    = $treasuries->position($treasuryId)->balance;

        $this->assertSame($this->balanceOf((int) $treasuries->accountOf($treasuryId)), bcadd($balance, '0', 2));

        return bcadd($balance, '0', 2);
    }

    /**
     * @return list<string> Every statement the database ran while the action ran.
     */
    protected function queriesOf(callable $action): array
    {
        $queries  = [];
        $listener = static function ($query) use (&$queries): void {
            $queries[] = (string) $query->getQuery();
        };

        Events::on('DBQuery', $listener);

        try {
            $action();
        } finally {
            Events::removeListener('DBQuery', $listener);
        }

        return $queries;
    }

    /**
     * @param list<string> $queries
     */
    protected function firstIndexOf(array $queries, callable $match): int
    {
        foreach ($queries as $index => $sql) {
            if ($match($sql)) {
                return $index;
            }
        }

        return PHP_INT_MAX;
    }
}
