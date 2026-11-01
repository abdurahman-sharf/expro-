<?php

namespace Tests\Support;

use App\Libraries\Accounting\AccountingGuard;
use App\Libraries\Accounting\Backfill\BackfillBatch;
use App\Libraries\Accounting\Backfill\BackfillPlan;
use App\Libraries\Accounting\Backfill\BackfillService;
use App\Libraries\Accounting\Backfill\BackfillStatus;
use App\Libraries\Accounting\JournalDraft;
use App\Libraries\Accounting\JournalLineDraft;
use App\Libraries\Accounting\OpeningBalanceService;
use App\Libraries\Accounting\OpeningStock;
use App\Libraries\Accounting\OpeningStockStatus;
use App\Libraries\Accounting\PostingService;
use App\Libraries\Accounting\TreasuryService;

/**
 * What a test of the retroactive posting needs: the books on from the first of 2020 with the opening
 * balances of the default branch posted, documents saved while accounting was off, an employee who
 * may run a batch, and a way to read what a batch wrote. A class that uses it also uses
 * AccountingFixtureTrait, EmployeeFixtureTrait, ItemFixtureTrait, SaleAccountingFixtureTrait and
 * PurchaseAccountingFixtureTrait.
 */
trait BackfillFixtureTrait
{
    protected BackfillService $backfill;
    protected int $backfillManager;

    /**
     * @var list<int>
     */
    private array $backfillCategories = [];

    protected function startBackfill(bool $openingPosted = true, bool $enabled = true): void
    {
        $this->startBuying();

        $this->backfillManager = $this->employeeWithGrants([AccountingGuard::BACKFILL_MANAGE, AccountingGuard::OPENING_MANAGE]);
        $this->backfill        = new BackfillService();

        $this->setConfigValue('accounting_backfill_enabled', $enabled ? '1' : '0');

        if ($openingPosted) {
            $this->postOpening();
        }
    }

    protected function stopBackfill(): void
    {
        $this->releaseOpenTransactions();

        $db = $this->accountingDb();
        $db->query('SET FOREIGN_KEY_CHECKS = 0');

        foreach (['accounting_backfill_items', 'accounting_backfill_batches', 'expenses', 'cash_up'] as $table) {
            $db->table($table)->truncate();
        }

        if ($this->backfillCategories !== []) {
            $db->table('expense_categories')->whereIn('expense_category_id', $this->backfillCategories)->delete();
        }

        $db->query('SET FOREIGN_KEY_CHECKS = 1');
        $this->backfillCategories = [];
        $this->setConfigValue('accounting_backfill_enabled', '0');
        $this->stopBuying();
    }

    protected function postOpening(int $branchId = 1, string $amount = '1000.00'): int
    {
        return (new OpeningBalanceService())->post($branchId, [
            ['account_id' => (string) $this->accountId('cash'), 'description' => 'Cash', 'debit' => $amount, 'credit' => ''],
            ['account_id' => (string) $this->accountId('retained_earnings'), 'description' => '', 'debit' => '', 'credit' => $amount],
        ], false, $this->backfillManager);
    }

    /**
     * Runs what saves documents with accounting off, as it was before the books followed them.
     *
     * @template T
     *
     * @param callable(): T $work
     *
     * @return T
     */
    protected function whileOff(callable $work): mixed
    {
        $this->setConfigValue('accounting_enabled', '0');

        try {
            return $work();
        } finally {
            $this->setConfigValue('accounting_enabled', '1');
        }
    }

    /**
     * Posts the opening stock of a stock location from shelves that hold the units given of the items given and
     * nothing else, as it is posted from the shelves on the day it is, and puts the shelves back as they were.
     *
     * @param list<int> $itemIds
     */
    protected function postOpeningStockOf(array $itemIds, string $quantity = '50', int $locationId = 1): void
    {
        $db     = $this->accountingDb();
        $backup = $db->table('item_quantities')->get()->getResultArray();

        $db->table('item_quantities')->emptyTable();

        foreach ($itemIds as $itemId) {
            $db->table('item_quantities')->insert(['item_id' => $itemId, 'location_id' => $locationId, 'quantity' => $quantity]);
        }

        try {
            $results = (new OpeningStock())->post([$locationId], null);

            $this->assertSame(OpeningStockStatus::Posted, $results[0]->plan->status);
        } finally {
            $db->table('item_quantities')->emptyTable();

            if ($backup !== []) {
                $db->table('item_quantities')->insertBatch($backup);
            }
        }
    }

    /**
     * Gives the opening stock of every location the day it was posted on, as one that was posted before the
     * documents of a test were saved, or after.
     */
    protected function openingStockWasPostedAt(string $postedAt): void
    {
        $this->accountingDb()->table('accounting_journals')->where('source_type', OpeningStock::SOURCE)->update(['posted_at' => $postedAt]);
    }

    /**
     * Gives a sale and its payments the day they were saved on.
     */
    protected function dateSale(int $saleId, string $date): int
    {
        $db = $this->accountingDb();

        $db->table('sales')->where('sale_id', $saleId)->update(['sale_time' => $date . ' 12:00:00', 'invoice_date' => null]);
        $db->table('sales_payments')->where('sale_id', $saleId)->update(['payment_time' => $date . ' 12:00:00']);

        return $saleId;
    }

    protected function datePurchase(int $receivingId, string $date): int
    {
        $db = $this->accountingDb();

        $db->table('receivings')->where('receiving_id', $receivingId)->update(['receiving_time' => $date . ' 12:00:00', 'invoice_date' => null]);
        $db->table('receivings_payments')->where('receiving_id', $receivingId)->update(['payment_time' => $date . ' 12:00:00']);

        return $receivingId;
    }

    /**
     * An invoice of the reference lines saved while accounting was off, with a payment when given.
     *
     * @param list<array<string, mixed>> $payments
     */
    protected function legacyInvoice(string $date = '2026-03-10', array $payments = [], ?int $customerId = null): int
    {
        return $this->whileOff(function () use ($date, $payments, $customerId): int {
            $cycle  = $this->referenceInvoice();
            $saleId = $this->save($cycle['items'], $cycle['taxes'], $payments, SALE_TYPE_INVOICE, $customerId ?? -1);

            return $this->dateSale($saleId, $date);
        });
    }

    protected function legacyPurchase(string $total = '500', string $date = '2026-03-10', ?string $paymentType = null): int
    {
        return $this->whileOff(function () use ($total, $date, $paymentType): int {
            $receivingId = $this->purchaseOf($total, null, $paymentType);

            return $this->datePurchase($receivingId, $date);
        });
    }

    protected function legacyExpense(string $amount = '230', string $tax = '30', string $date = '2026-03-12', ?int $branchId = null): int
    {
        $db = $this->accountingDb();

        $db->table('expense_categories')->insert(['category_name' => 'Backfill ' . uniqid(), 'category_description' => '', 'deleted' => 0, 'account_id' => null]);

        $categoryId                 = (int) $db->insertID();
        $this->backfillCategories[] = $categoryId;

        $db->table('expenses')->insert([
            'date'                => $date . ' 10:00:00',
            'amount'              => $amount,
            'tax_amount'          => $tax,
            'payment_type'        => 'Cash',
            'expense_category_id' => $categoryId,
            'description'         => 'Legacy expense',
            'employee_id'         => $this->employeeId,
            'deleted'             => 0,
            'branch_id'           => $branchId,
        ]);

        return (int) $db->insertID();
    }

    protected function legacyCashup(string $counted = '900', string $date = '2026-03-12', ?int $branchId = null): int
    {
        $db = $this->accountingDb();

        $db->table('cash_up')->insert([
            'open_date'            => $date . ' 08:00:00',
            'close_date'           => $date . ' 18:00:00',
            'open_amount_cash'     => '1000',
            'transfer_amount_cash' => '0',
            'closed_amount_cash'   => $counted,
            'closed_amount_card'   => '0',
            'closed_amount_check'  => '0',
            'closed_amount_total'  => $counted,
            'closed_amount_due'    => '0',
            'description'          => 'Legacy cash-up',
            'open_employee_id'     => $this->employeeId,
            'close_employee_id'    => $this->employeeId,
            'deleted'              => 0,
            'branch_id'            => $branchId,
        ]);

        return (int) $db->insertID();
    }

    /**
     * A batch over the range, made by the manager, and previewed to the end unless said otherwise.
     *
     * @param array<string, mixed> $input
     */
    protected function previewed(array $input = [], bool $preview = true): BackfillBatch
    {
        $batchId = $this->backfill->create($input + ['name' => 'Batch ' . uniqid(), 'from' => '2026-01-01', 'to' => '2026-12-31'], $this->backfillManager);

        return $preview ? $this->backfill->preview($batchId, $this->backfillManager) : $this->backfill->batch($batchId, $this->backfillManager);
    }

    protected function approved(BackfillBatch $batch): BackfillBatch
    {
        return $this->backfill->approve($batch->id, (string) $batch->fingerprint, $this->backfillManager);
    }

    /**
     * The journals a batch wrote, as "source:id:entry:status" with their lines as "account:debit:credit".
     *
     * @return list<array{id: int, key: string, status: string, date: string, number: ?string, lines: list<string>}>
     */
    protected function batchJournals(int $batchId): array
    {
        $db   = $this->accountingDb();
        $rows = [];

        foreach ($db->table('accounting_journals')->where('backfill_batch_id', $batchId)->orderBy('journal_id', 'ASC')->get()->getResult() as $journal) {
            $rows[] = [
                'id'     => (int) $journal->journal_id,
                'key'    => $journal->source_type . ':' . $journal->source_id . ':' . $journal->entry_type . ':' . $journal->revision,
                'status' => $journal->status,
                'date'   => $journal->journal_date,
                'number' => $journal->number,
                'lines'  => array_map(
                    static fn (object $line): string => $line->account_id . ':' . bcadd($line->debit, '0', 2) . ':' . bcadd($line->credit, '0', 2),
                    $db->table('accounting_journal_lines')->select('account_id, debit, credit')->where('journal_id', $journal->journal_id)->orderBy('line_no', 'ASC')->get()->getResult(),
                ),
            ];
        }

        return $rows;
    }

    /**
     * The state of the books a report may not move while a batch is only previewed: the balance of
     * every account, with every journal and every stock movement counted.
     *
     * @return array<string, mixed>
     */
    protected function booksSnapshot(): array
    {
        $db = $this->accountingDb();

        return [
            'balances' => array_map(
                static fn (object $row): string => $row->account_id . '=' . bcadd((string) $row->net, '0', 4),
                $db->query(
                    'SELECT l.account_id, SUM(l.debit - l.credit) AS net FROM ' . $db->prefixTable('accounting_journal_lines') . ' l JOIN ' . $db->prefixTable('accounting_journals') . ' j ON j.journal_id = l.journal_id WHERE j.status IN (?, ?) GROUP BY l.account_id ORDER BY l.account_id',
                    ['posted', 'reversed'],
                )->getResult(),
            ),
            'journals' => $db->table('accounting_journals')->whereIn('status', ['posted', 'reversed'])->countAllResults(),
            'stock'    => $db->table('accounting_stock_entries')->countAllResults(),
        ];
    }

    /**
     * Writes a preview journal of a batch directly, for what must prove that something ignores it.
     *
     * @param list<JournalLineDraft> $lines
     */
    protected function insertPreview(int $batchId, array $lines, string $date = '2026-03-10', int $sourceId = 990001, string $source = 'sale', int $branchId = 1): int
    {
        return (new PostingService())->postPreview(new JournalDraft(
            date: $date,
            lines: $lines,
            sourceType: $source,
            sourceId: $sourceId,
            employeeId: $this->backfillManager,
            description: 'Preview',
            branchId: $branchId,
        ), $batchId);
    }

    protected function emptyBatch(): int
    {
        return $this->backfill->create(['name' => 'Empty ' . uniqid(), 'from' => '2019-01-01', 'to' => '2019-01-02'], $this->backfillManager);
    }

    protected function statusOf(int $batchId): BackfillStatus
    {
        return $this->backfill->batch($batchId, $this->backfillManager)->status;
    }

    protected function treasuryIn(int $branchId, string $name = 'Backfill drawer'): int
    {
        return (new TreasuryService())->create(['name' => $name . ' ' . uniqid(), 'kind' => 'cash', 'branch_id' => $branchId]);
    }

    /**
     * Starts a method of tests/Support/RaceWorker.php in a process of its own and returns at once, so that a
     * test can hold a lock on this connection while the worker runs into it.
     *
     * @param list<string> $args
     *
     * @return array{process: resource, output: resource, files: list<string>}
     */
    protected function startWorker(string $method, array $args): array
    {
        $ready = tempnam(sys_get_temp_dir(), 'worker_ready_');
        $go    = tempnam(sys_get_temp_dir(), 'worker_go_');

        unlink($ready);
        file_put_contents($go, '1');

        $environment = array_merge(array_filter($_SERVER, static fn ($value): bool => is_scalar($value)), ['RACE_READY_FILE' => $ready, 'RACE_GO_FILE' => $go]);
        $process     = proc_open([PHP_BINARY, ROOTPATH . 'tests/Support/RaceWorker.php', $method, ...$args], [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, ROOTPATH, $environment);

        fclose($pipes[0]);
        fclose($pipes[2]);

        return ['process' => $process, 'output' => $pipes[1], 'files' => [$ready, $go]];
    }

    /**
     * Waits for the process to end and returns what it printed.
     *
     * @param array{process: resource, output: resource, files: list<string>} $worker
     */
    protected function finishWorker(array $worker): string
    {
        $output = (string) stream_get_contents($worker['output']);

        fclose($worker['output']);
        proc_close($worker['process']);

        foreach ($worker['files'] as $file) {
            if (is_file($file)) {
                unlink($file);
            }
        }

        return trim($output);
    }

    /**
     * Waits until an operation of the database is waiting for a row lock another transaction holds.
     */
    protected function waitForALockWait(int $seconds = 30): void
    {
        $until = microtime(true) + $seconds;

        do {
            if ((int) $this->accountingDb()->query("SHOW GLOBAL STATUS LIKE 'Innodb_row_lock_current_waits'")->getRow()->Value > 0) {
                return;
            }

            usleep(20000);
        } while (microtime(true) < $until);

        $this->fail('No transaction waited for a lock.');
    }

    /**
     * Lets a statement that would fail on a key through, for what a test breaks on purpose.
     */
    protected function withoutKeys(callable $work): void
    {
        $db = $this->accountingDb();

        $db->query('SET FOREIGN_KEY_CHECKS = 0');

        try {
            $work();
        } finally {
            $db->query('SET FOREIGN_KEY_CHECKS = 1');
        }
    }

    /**
     * The lines a plan would post, by journal as "source:entry|account:debit:credit,...", for comparing with
     * what the live posting of the same document holds.
     *
     * @return list<string>
     */
    protected function plannedLines(BackfillPlan $plan): array
    {
        $lines = [];

        foreach ($plan->steps as $step) {
            if ($step->draft === null) {
                continue;
            }

            $lines[] = $step->draft->sourceType . ':' . $step->draft->entryType->value . '|' . implode(',', array_map(
                static fn (JournalLineDraft $line): string => $line->accountId . ':' . bcadd($line->debit, '0', 2) . ':' . bcadd($line->credit, '0', 2),
                $step->draft->lines,
            ));
        }

        return $lines;
    }
}
