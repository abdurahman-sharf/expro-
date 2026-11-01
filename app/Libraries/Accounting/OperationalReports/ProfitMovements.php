<?php

namespace App\Libraries\Accounting\OperationalReports;

use App\Libraries\Accounting\JournalEntryType;
use App\Libraries\Accounting\ReportScope;
use App\Libraries\Accounting\SaleAmounts;
use App\Libraries\Accounting\SalePostingBuilder;
use CodeIgniter\Database\BaseConnection;
use Config\Database;

/**
 * The movements of stock that the sales of the books made, valued as the books valued them. A movement is
 * the item of a sale document that left a stock location, or came back to it, on the day of the cost
 * journal; one that was taken back is not read, nor is a service, which never moves. What the document
 * sold the item for is read from its lines, in proportion to the units that moved when only some of them
 * did, and the cost is the value of the movement, so that the cost of a period is what the journals of
 * the cost of sales hold for it, a return costing what the sale it takes back cost.
 */
final class ProfitMovements
{
    private BaseConnection $db;
    private ReportDocuments $documents;

    public function __construct(?BaseConnection $db = null, ?ReportDocuments $documents = null)
    {
        $this->db        = $db ?? Database::connect();
        $this->documents = $documents ?? new ReportDocuments($this->db);
    }

    /**
     * @return list<ProfitRow> Oldest first.
     */
    public function read(ReportScope $scope, ?string $from, string $to, ?int $customerId = null, ?int $employeeId = null, ?int $itemId = null, string $category = ''): array
    {
        if ($scope->isEmpty()) {
            return [];
        }

        $movements = $this->movements($scope, $from, $to, $itemId);

        if ($movements === []) {
            return [];
        }

        $documents = $this->documents->byIds($scope, array_map(static fn (object $movement): int => (int) $movement->sale_id, $movements), true);
        $items     = $this->items(array_map(static fn (object $movement): int => (int) $movement->item_id, $movements));
        $rows      = [];

        foreach ($movements as $movement) {
            $document = $documents[(int) $movement->sale_id] ?? null;
            $item     = $items[(int) $movement->item_id] ?? ['name' => '', 'number' => '', 'category' => ''];

            if ($document === null || ($customerId !== null && $document->customerId !== $customerId) || ($employeeId !== null && $document->employeeId !== $employeeId) || ($category !== '' && $item['category'] !== $category)) {
                continue;
            }

            $row = $this->row($document, $movement, $item);

            if ($row !== null) {
                $rows[] = $row;
            }
        }

        usort($rows, static fn (ProfitRow $a, ProfitRow $b): int => [$a->date, $a->saleId, $a->itemId] <=> [$b->date, $b->saleId, $b->itemId]);

        return $rows;
    }

    /**
     * What the cost-of-sales journals of the books that stand hold in these branches and days on the
     * accounts of expense, which is what the cost of the movements has to come to.
     *
     * @return numeric-string
     */
    public function ledgerCost(ReportScope $scope, ?string $from, string $to): string
    {
        if ($scope->isEmpty()) {
            return Amounts::ZERO;
        }

        $bindings = [SalePostingBuilder::SOURCE_SALE, JournalEntryType::Cogs->value, 'posted', 'expense', $to];
        $where    = 'j.journal_date <= ?';

        if ($from !== null) {
            $where .= ' AND j.journal_date >= ?';
            $bindings[] = $from;
        }

        $row = $this->db->query(
            'SELECT COALESCE(SUM(l.debit - l.credit), 0) AS cost
             FROM ' . $this->db->prefixTable('accounting_journals') . ' j
             JOIN ' . $this->db->prefixTable('accounting_journal_lines') . ' l ON l.journal_id = j.journal_id
             JOIN ' . $this->db->prefixTable('accounting_accounts') . ' a ON a.account_id = l.account_id
             WHERE j.source_type = ? AND j.entry_type = ? AND j.status = ? AND a.account_type = ? AND j.branch_id IN (' . implode(',', array_map('intval', $scope->ids)) . ") AND {$where}",
            $bindings,
        )->getRow();

        return bcadd((string) $row->cost, '0', SaleAmounts::SCALE);
    }

    /**
     * @return list<object>
     */
    private function movements(ReportScope $scope, ?string $from, string $to, ?int $itemId): array
    {
        $entries  = $this->db->prefixTable('accounting_stock_entries');
        $where    = ['e.source_type = ?', 'e.entry_type = ?', "e.kind = 'posting'", 'r.entry_id IS NULL', 'e.entry_date <= ?', 'e.branch_id IN (' . implode(',', array_map('intval', $scope->ids)) . ')'];
        $bindings = [SalePostingBuilder::SOURCE_SALE, JournalEntryType::Cogs->value, $to];

        if ($from !== null) {
            $where[]    = 'e.entry_date >= ?';
            $bindings[] = $from;
        }

        if ($itemId !== null) {
            $where[]    = 'e.item_id = ?';
            $bindings[] = $itemId;
        }

        return $this->db->query(
            "SELECT e.source_id AS sale_id, e.item_id, e.entry_date, SUM(e.quantity) AS quantity, SUM(e.value) AS value
             FROM {$entries} e
             LEFT JOIN {$entries} r ON r.source_type = e.source_type AND r.source_id = e.source_id AND r.entry_type = e.entry_type AND r.revision = e.revision AND r.line_no = e.line_no AND r.kind = 'reversal'
             WHERE " . implode(' AND ', $where) . '
             GROUP BY e.source_id, e.item_id, e.entry_date
             ORDER BY e.entry_date ASC, e.source_id ASC, e.item_id ASC',
            $bindings,
        )->getResult();
    }

    /**
     * @param array{name: string, number: string, category: string} $item
     */
    private function row(ReportDocument $document, object $movement, array $item): ?ProfitRow
    {
        $sign     = $document->kind->sign();
        $decimals = totals_decimals();
        $lines    = [];

        foreach ($document->lines as $saleLine) {
            if ($saleLine->itemId === (int) $movement->item_id && $saleLine->hasStock) {
                $lines[] = ReportLine::of($saleLine, $sign, $decimals);
            }
        }

        if ($lines === []) {
            return null;
        }

        $moved  = ltrim((string) $movement->quantity, '-');
        $sold   = ltrim(Amounts::sum(array_map(static fn (ReportLine $line): string => $line->quantity, $lines)), '-');
        $share  = bccomp($sold, '0', 3) === 0 || bccomp($moved, $sold, 3) >= 0 ? '1' : bcdiv($moved, $sold, 8);
        $value  = Amounts::sum(array_map(static fn (ReportLine $line): string => $line->value, $lines));
        $reduce = Amounts::sum(array_map(static fn (ReportLine $line): string => $line->discount, $lines));

        return new ProfitRow(
            saleId: $document->id,
            kind: $document->kind,
            number: $document->number,
            date: (string) $movement->entry_date,
            branchId: $document->branchId,
            customerId: $document->customerId,
            customerName: $document->customerName,
            employeeId: $document->employeeId,
            employeeName: $document->employeeName,
            itemId: (int) $movement->item_id,
            itemName: $item['name'],
            itemNumber: $item['number'],
            category: $item['category'],
            quantity: Amounts::signed($moved, $sign),
            cost: Amounts::negate(bcadd((string) $movement->value, '0', SaleAmounts::SCALE)),
            salePrice: Amounts::add($this->scaled($value, $share), $this->scaled($reduce, $share)),
            discount: $this->scaled($reduce, $share),
        );
    }

    /**
     * @return numeric-string
     */
    private function scaled(string $amount, string $share): string
    {
        return $share === '1' ? $amount : bcadd(bcmul($amount, $share, 8), '0', SaleAmounts::SCALE);
    }

    /**
     * @param list<int> $itemIds
     *
     * @return array<int, array{name: string, number: string, category: string}>
     */
    private function items(array $itemIds): array
    {
        $items = [];

        foreach (array_chunk(array_values(array_unique($itemIds)), 400) as $chunk) {
            foreach ($this->db->table('items')->select('item_id, name, item_number, category')->whereIn('item_id', $chunk)->get()->getResult() as $item) {
                $items[(int) $item->item_id] = ['name' => (string) $item->name, 'number' => (string) ($item->item_number ?? ''), 'category' => (string) ($item->category ?? '')];
            }
        }

        return $items;
    }
}
