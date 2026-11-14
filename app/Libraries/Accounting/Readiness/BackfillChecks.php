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
use App\Libraries\Accounting\FiscalYearService;
use App\Libraries\Accounting\JournalStatus;
use App\Libraries\Accounting\PaymentType;
use App\Libraries\Accounting\ReceivingPostingBuilder;
use App\Libraries\Accounting\SalePostingBuilder;
use App\Libraries\Accounting\SupplierAdvances;
use App\Libraries\Organization\CompanyContext;

/**
 * Where the retroactive posting stands: whether a batch may be approved, which is only when the
 * owner turned the posting on, the start date of the books is set and every branch has posted its
 * opening balances, which hold what happened before the books began; the batches that are open; the
 * previews that no open batch holds, which must not exist; how many documents have no journal and how many
 * of them fall outside every fiscal year; and what customers and suppliers carry in documents that
 * the books do not hold and no opening balance covers. It reads and changes nothing.
 */
final class BackfillChecks extends CheckGroup
{
    public function run(): array
    {
        return [
            $this->check('backfill', 'A6-BACKFILL', 'Retroactive posting of past documents', ['accounting_backfill_batches.families', 'accounting_backfill_items', 'accounting_journals.backfill_batch_id', 'accounting_stock_entries', 'branches', 'sales.invoice_date', 'receivings.invoice_date', 'expenses.branch_id', 'cash_up.branch_id'], fn (): CheckResult => $this->backfill()),
            $this->check('legacy_balances', 'LEGACY-BALANCES-OUTSIDE-BOOKS', 'Customer and supplier balances of old documents that are not in the books', ['customer_openings', 'supplier_advances', 'sales_payments', 'receivings_items', 'accounting_journals.source_type'], fn (): CheckResult => $this->legacyBalances()),
        ];
    }

    private function backfill(): CheckResult
    {
        $settings = new AccountingSettings($this->db);
        $blockers = (new BackfillService($this->db))->approvalBlockers();
        $open     = $this->openBatches();
        $orphans  = $this->orphanPreviews();
        $waiting  = $this->waiting($settings->startDate());
        $outside  = $this->outsideFiscalYears();
        $data     = [
            'enabled'             => $settings->backfillEnabled(),
            'start_date'          => $settings->startDate(),
            'blockers'            => $blockers,
            'open_batches'        => $open,
            'orphan_previews'     => $orphans,
            'waiting'             => $waiting,
            'outside_fiscal_year' => $outside,
        ];

        $findings = [
            'Approving a batch needs accounting_backfill_enabled on, the start date set and the opening balances of every branch the batch writes in posted.',
            'accounting_backfill_enabled is ' . ($data['enabled'] ? 'on' : 'off') . '.',
            ...array_map(static fn (string $blocker): string => 'Not ready to approve: ' . $blocker, $blockers),
            ...array_map(static fn (array $batch): string => sprintf('Batch #%d "%s" is %s: %d ready, %d refused.', $batch['batch_id'], $batch['name'], $batch['status'], $batch['ready'], $batch['refused']), $open),
            ...array_map(static fn (string $family, int $count): string => sprintf('%d %s have no journal.', $count, $family), array_keys($waiting), array_values($waiting)),
            ...($outside === null ? [] : [sprintf('%d document(s) with no journal fall outside every fiscal year (dated %s to %s): create the fiscal years %s to %s (php spark accounting:enable creates them, or the button on the screen of the batch), or a batch refuses them as outside_fiscal_year.', $outside['documents'], $outside['from'], $outside['to'], $outside['years'][0], end($outside['years']))]),
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
     * The documents with no journal that are dated after the last fiscal year, which no batch can post until the years
     * that cover them exist, with the days of the first and the last of them and the names of the years that are missing.
     *
     * @return array{documents: int, from: string, to: string, years: list<string>}|null
     */
    private function outsideFiscalYears(): ?array
    {
        $last = $this->db->table('accounting_fiscal_years')->selectMax('end_date', 'last_day')->where('company_id', CompanyContext::id())->get()->getRow();

        if ($last === null || $last->last_day === null) {
            return null;
        }

        $scope     = new BackfillScope(date('Y-m-d', strtotime($last->last_day . ' +1 day')), '2999-12-31', null, BackfillFamily::ordered());
        $documents = 0;
        $latest    = null;

        foreach ($this->adapters() as $adapter) {
            $count = $adapter->waiting($scope);

            if ($count > 0) {
                $documents += $count;
                $day        = $adapter->latest($scope);
                $latest     = $latest === null || ($day !== null && $day > $latest) ? $day : $latest;
            }
        }

        if ($documents === 0 || $latest === null) {
            return null;
        }

        $years = array_column((new FiscalYearService($this->db))->missingThrough($latest), 'name');

        return $years === [] ? null : ['documents' => $documents, 'from' => $scope->from, 'to' => $latest, 'years' => $years];
    }

    private function legacyBalances(): CheckResult
    {
        $settings = new AccountingSettings($this->db);
        $start    = $settings->startDate();
        $enabled  = $settings->isEnabled();
        $day      = $start ?? '0000-00-00';
        $data     = [
            'enabled'    => $enabled,
            'start_date' => $start,
            'customers'  => $this->tally($this->customerDebts(), $day),
            'suppliers'  => $this->tally($this->supplierDebts(), $day),
        ];

        $findings = [
            'A document that no journal stands for leaves the balance of its customer or supplier outside the books. What was dated before the start date belongs in an opening balance (the import of opening balances of customers and suppliers enters them all at once); what was dated since belongs in a batch of the retroactive posting.',
            'Customers: the credit their old sales left on them, by the payments of the type credit; positive is what they owe us. Suppliers: the value of the lines of their old purchases that were left on credit, before tax and before the discount of the invoice; positive is what we owe them. A party that has an opening balance dated on or after a document is counted without that document.',
        ];

        $meaning = ['customers' => 'owed by them, %s owed to them', 'suppliers' => 'owed to them, %s owed by them'];

        foreach (['customers', 'suppliers'] as $who) {
            foreach (['before' => 'dated before the start date: import their opening balances', 'since' => 'dated since the start date: run the retroactive posting'] as $period => $remedy) {
                $tally = $data[$who][$period];

                if ($tally['parties'] > 0) {
                    $findings[] = sprintf('%d %s carry a net %s in %d document(s) %s (%s ' . $meaning[$who] . ').', $tally['parties'], $who, $tally['net'], $tally['documents'], $remedy, $tally['positive'], $tally['negative']);
                }
            }
        }

        $parties = $data['customers']['before']['parties'] + $data['customers']['since']['parties'] + $data['suppliers']['before']['parties'] + $data['suppliers']['since']['parties'];

        if ($parties === 0) {
            return CheckResult::pass($enabled ? 'No customer or supplier carries a balance in old documents that the books do not hold.' : 'Accounting is off, and no customer or supplier carries a balance in old documents.', $findings, $data);
        }

        if (! $enabled) {
            return CheckResult::pass(sprintf('Accounting is off. When it is turned on, %d customer(s) and supplier(s) will carry balances in old documents that the books do not hold: enter their opening balances or post the documents.', $parties), $findings, $data);
        }

        return CheckResult::review(
            sprintf('%d customer(s) and supplier(s) carry balances in old documents that the books do not hold. Import their opening balances (documents before the start date) or run the retroactive posting (documents since it).', $parties),
            $findings,
            $data,
        );
    }

    /**
     * What each party owes or is owed by the documents that no journal stands for, one row for each document that left a balance.
     *
     * @param list<array{party: int, day: string, amount: string}> $debts
     *
     * @return array{before: array{parties: int, documents: int, positive: string, negative: string, net: string}, since: array{parties: int, documents: int, positive: string, negative: string, net: string}}
     */
    private function tally(array $debts, string $start): array
    {
        $net       = ['before' => [], 'since' => []];
        $documents = ['before' => 0, 'since' => 0];

        foreach ($debts as $debt) {
            $period = $debt['day'] < $start ? 'before' : 'since';

            $net[$period][$debt['party']] = bcadd($net[$period][$debt['party']] ?? '0', $debt['amount'], 4);
            $documents[$period]++;
        }

        $result = [];

        foreach ($net as $period => $parties) {
            $parties = array_filter($parties, static fn (string $amount): bool => bccomp($amount, '0', 4) !== 0);
            $positive = '0.0000';
            $negative = '0.0000';

            foreach ($parties as $amount) {
                if (bccomp($amount, '0', 4) > 0) {
                    $positive = bcadd($positive, $amount, 4);
                } else {
                    $negative = bcadd($negative, $amount, 4);
                }
            }

            $result[$period] = ['parties' => count($parties), 'documents' => $documents[$period], 'positive' => $positive, 'negative' => $negative, 'net' => bcadd($positive, $negative, 4)];
        }

        return $result;
    }

    /**
     * @return list<array{party: int, day: string, amount: string}> Positive: the customer owes us.
     */
    private function customerDebts(): array
    {
        $credit = $this->labelsOf('sales_payments', 'payment_type');

        if ($credit === []) {
            return [];
        }

        $sales    = $this->db->prefixTable('sales');
        $payments = $this->db->prefixTable('sales_payments');
        $journals = $this->db->prefixTable('accounting_journals');
        $openings = $this->db->prefixTable('customer_openings');
        $people   = $this->db->prefixTable('customers');

        $rows = $this->db->query(
            "SELECT s.customer_id AS party, COALESCE(s.invoice_date, DATE(s.sale_time)) AS day, SUM(p.payment_amount) AS amount
             FROM {$sales} s
             JOIN {$payments} p ON p.sale_id = s.sale_id AND p.payment_type IN (" . $this->quoted($credit) . ")
             JOIN {$people} c ON c.person_id = s.customer_id AND c.deleted = 0
             WHERE s.sale_status = ? AND s.sale_type IN (?, ?, ?) AND s.customer_id IS NOT NULL
               AND NOT EXISTS (SELECT 1 FROM {$journals} j WHERE j.source_type = ? AND j.source_id = s.sale_id AND j.status <> ?)
               AND NOT EXISTS (SELECT 1 FROM {$openings} o WHERE o.customer_id = s.customer_id AND o.status = 'posted' AND o.opening_date >= COALESCE(s.invoice_date, DATE(s.sale_time)))
             GROUP BY s.sale_id, s.customer_id, day
             HAVING amount <> 0",
            [COMPLETED, SALE_TYPE_POS, SALE_TYPE_INVOICE, SALE_TYPE_RETURN, SalePostingBuilder::SOURCE_SALE, JournalStatus::Preview->value],
        )->getResult();

        return array_map(static fn (object $row): array => ['party' => (int) $row->party, 'day' => (string) $row->day, 'amount' => bcadd((string) $row->amount, '0', 4)], $rows);
    }

    /**
     * @return list<array{party: int, day: string, amount: string}> Positive: we owe the supplier.
     */
    private function supplierDebts(): array
    {
        $credit = $this->labelsOf('receivings', 'payment_type');

        if ($credit === []) {
            return [];
        }

        $receivings = $this->db->prefixTable('receivings');
        $lines      = $this->db->prefixTable('receivings_items');
        $journals   = $this->db->prefixTable('accounting_journals');
        $advances   = $this->db->prefixTable('supplier_advances');
        $suppliers  = $this->db->prefixTable('suppliers');
        $day        = 'COALESCE(r.invoice_date, DATE(r.receiving_time))';

        $rows = $this->db->query(
            "SELECT r.supplier_id AS party, {$day} AS day,
                    SUM(i.quantity_purchased * i.item_unit_price * (1 - IF(i.discount_type = 0, i.discount / 100, 0)) - IF(i.discount_type = 0, 0, i.discount * IF(i.discount_type = 1, i.quantity_purchased, 1))) AS amount
             FROM {$receivings} r
             JOIN {$lines} i ON i.receiving_id = r.receiving_id
             JOIN {$suppliers} s ON s.person_id = r.supplier_id AND s.deleted = 0
             WHERE r.receiving_type IN ('receive', 'return') AND r.supplier_id IS NOT NULL AND r.payment_type IN (" . $this->quoted($credit) . ")
               AND NOT EXISTS (SELECT 1 FROM {$journals} j WHERE j.source_type = ? AND j.source_id = r.receiving_id AND j.status <> ?)
               AND NOT EXISTS (SELECT 1 FROM {$advances} a WHERE a.supplier_id = r.supplier_id AND a.kind = ? AND a.status <> 'cancelled' AND a.advance_date >= {$day})
               AND NOT EXISTS (
                   SELECT 1 FROM {$receivings} o JOIN {$journals} oj ON oj.source_type = ? AND oj.source_id = o.receiving_id AND oj.status = ?
                   WHERE o.supplier_id = r.supplier_id AND o.receiving_type = ? AND DATE(o.receiving_time) >= {$day}
               )
             GROUP BY r.receiving_id, r.supplier_id, day
             HAVING amount <> 0",
            [ReceivingPostingBuilder::SOURCE_PURCHASE, JournalStatus::Preview->value, SupplierAdvances::OPENING, ReceivingPostingBuilder::SOURCE_PURCHASE, JournalStatus::Posted->value, SupplierAdvances::OPENING_TYPE],
        )->getResult();

        return array_map(static fn (object $row): array => ['party' => (int) $row->party, 'day' => (string) $row->day, 'amount' => bcadd((string) $row->amount, '0', 4)], $rows);
    }

    /**
     * The ways of writing "on credit" that a column holds, in whatever language they were saved in: each text the column holds that names the type.
     *
     * @return list<string>
     */
    private function labelsOf(string $table, string $column): array
    {
        $labels = [];

        foreach ($this->db->table($table)->distinct()->select($column)->where("{$column} IS NOT NULL", null, false)->get()->getResult() as $row) {
            if (PaymentType::fromLabel((string) $row->{$column}) === PaymentType::Due) {
                $labels[] = (string) $row->{$column};
            }
        }

        return $labels;
    }

    /**
     * @param list<string> $values
     */
    private function quoted(array $values): string
    {
        return implode(',', array_map(fn (string $value): string => $this->db->escape($value), $values));
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
