<?php

namespace App\Libraries\Accounting\Readiness;

use App\Libraries\Accounting\AccountingSettings;
use App\Libraries\Accounting\Backfill\BackfillAdapter;
use App\Libraries\Accounting\Backfill\BackfillFamily;
use App\Libraries\Accounting\Backfill\BackfillScope;
use App\Libraries\Accounting\Backfill\BackfillService;
use App\Libraries\Accounting\Backfill\BackfillStatus;
use App\Libraries\Accounting\Backfill\CashupBackfill;
use App\Libraries\Accounting\Backfill\ExpenseBackfill;
use App\Libraries\Accounting\Backfill\PurchaseBackfill;
use App\Libraries\Accounting\Backfill\SaleBackfill;

/**
 * Where the retroactive posting stands: whether a batch may be approved, which is only when the
 * owner turned the posting on, the start date of the books is set and every branch has posted its
 * opening balances, which hold what happened before the books began; the batches that are open; the
 * previews that no open batch holds, which must not exist; and how many documents have no journal.
 * It reads and changes nothing.
 */
final class BackfillChecks extends CheckGroup
{
    public function run(): array
    {
        return [
            $this->check('backfill', 'A6-BACKFILL', 'Retroactive posting of past documents', ['accounting_backfill_batches.families', 'accounting_backfill_items', 'accounting_journals.backfill_batch_id', 'accounting_stock_entries', 'branches', 'sales.invoice_date', 'receivings.invoice_date', 'expenses.branch_id', 'cash_up.branch_id'], fn (): CheckResult => $this->backfill()),
        ];
    }

    private function backfill(): CheckResult
    {
        $settings = new AccountingSettings($this->db);
        $blockers = (new BackfillService($this->db))->approvalBlockers();
        $open     = $this->openBatches();
        $orphans  = $this->orphanPreviews();
        $waiting  = $this->waiting($settings->startDate());
        $data     = [
            'enabled'         => $settings->backfillEnabled(),
            'start_date'      => $settings->startDate(),
            'blockers'        => $blockers,
            'open_batches'    => $open,
            'orphan_previews' => $orphans,
            'waiting'         => $waiting,
        ];

        $findings = [
            'Approving a batch needs accounting_backfill_enabled on, the start date set and the opening balances of every branch the batch writes in posted.',
            'accounting_backfill_enabled is ' . ($data['enabled'] ? 'on' : 'off') . '.',
            ...array_map(static fn (string $blocker): string => 'Not ready to approve: ' . $blocker, $blockers),
            ...array_map(static fn (array $batch): string => sprintf('Batch #%d "%s" is %s: %d ready, %d refused.', $batch['batch_id'], $batch['name'], $batch['status'], $batch['ready'], $batch['refused']), $open),
            ...array_map(static fn (string $family, int $count): string => sprintf('%d %s have no journal.', $count, $family), array_keys($waiting), array_values($waiting)),
        ];

        if ($orphans > 0) {
            return CheckResult::fail($orphans . ' preview journal(s) belong to no open batch: nothing counts them, but nothing should hold them either.', $findings, $data);
        }

        if ($open === [] && ! $data['enabled'] && array_sum($waiting) === 0) {
            return CheckResult::pass('The retroactive posting is off, no batch is open and every document has its journal.', $findings, $data);
        }

        return CheckResult::review(
            sprintf('The retroactive posting is %s; %d batch(es) are open and %d document(s) have no journal.', $data['enabled'] ? 'on' : 'off', count($open), array_sum($waiting)),
            $findings,
            $data,
        );
    }

    /**
     * @return list<array{batch_id: int, name: string, status: string, ready: int, refused: int}>
     */
    private function openBatches(): array
    {
        $rows = $this->db->table('accounting_backfill_batches')
            ->select('batch_id, name, status, ready_count, refused_count')
            ->whereIn('status', array_map(static fn (BackfillStatus $status): string => $status->value, array_filter(BackfillStatus::cases(), static fn (BackfillStatus $status): bool => $status->isActive())))
            ->orderBy('batch_id', 'ASC')
            ->get()
            ->getResult();

        return array_map(static fn (object $row): array => ['batch_id' => (int) $row->batch_id, 'name' => (string) $row->name, 'status' => (string) $row->status, 'ready' => (int) $row->ready_count, 'refused' => (int) $row->refused_count], $rows);
    }

    private function orphanPreviews(): int
    {
        $journals = $this->db->prefixTable('accounting_journals');
        $batches  = $this->db->prefixTable('accounting_backfill_batches');
        $active   = implode(',', array_map(fn (BackfillStatus $status): string => $this->db->escape($status->value), array_filter(BackfillStatus::cases(), static fn (BackfillStatus $status): bool => $status->isActive())));

        return (int) $this->db->query("SELECT COUNT(*) AS found FROM {$journals} j LEFT JOIN {$batches} b ON b.batch_id = j.backfill_batch_id WHERE j.status = 'preview' AND (b.batch_id IS NULL OR b.status NOT IN ({$active}))")->getRow()->found;
    }

    /**
     * @return array<string, int> The documents with no journal from the start date on, by family.
     */
    private function waiting(?string $startDate): array
    {
        $scope  = new BackfillScope($startDate ?? '1970-01-01', '2999-12-31', null, BackfillFamily::ordered());
        $counts = [];

        foreach ($this->adapters() as $adapter) {
            $count = $adapter->waiting($scope);

            if ($count > 0) {
                $counts[$adapter->family()->value] = $count;
            }
        }

        return $counts;
    }

    /**
     * @return list<BackfillAdapter>
     */
    private function adapters(): array
    {
        return [new SaleBackfill($this->db), new PurchaseBackfill($this->db), new ExpenseBackfill($this->db), new CashupBackfill($this->db)];
    }
}
