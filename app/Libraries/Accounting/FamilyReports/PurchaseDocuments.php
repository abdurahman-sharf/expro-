<?php

namespace App\Libraries\Accounting\FamilyReports;

use App\Libraries\Accounting\EntityAccountService;
use App\Libraries\Accounting\JournalEntryType;
use App\Libraries\Accounting\JournalStatus;
use App\Libraries\Accounting\PaymentState;
use App\Libraries\Accounting\PurchaseFigures;
use App\Libraries\Accounting\ReceivingAmounts;
use App\Libraries\Accounting\ReceivingLine;
use App\Libraries\Accounting\ReceivingPostingBuilder;
use App\Libraries\Accounting\ReceivingType;
use App\Libraries\Accounting\ReportPeriod;
use App\Libraries\Accounting\ReportScope;
use App\Libraries\Accounting\SaleAmounts;
use App\Libraries\Accounting\SupplierLedger;
use App\Libraries\Numbering\DocumentNumbering;
use App\Libraries\Numbering\DocumentType;
use App\Libraries\Organization\CompanyContext;
use App\Libraries\Purchases\PurchaseLines;
use App\Libraries\Roles\RecordScope;
use CodeIgniter\Database\BaseConnection;
use Config\Database;

/**
 * The purchases and the returns to suppliers of a period as one list, which every report of purchases
 * is built from, so that there is one meaning of what was bought: the invoices less the returns, a
 * return being what the books call a debit note. Each document is worth what its lines come to with
 * their discounts and tax, the way its journal was written, and says what the journal that stands
 * for it holds, so that the two can be set side by side.
 */
final class PurchaseDocuments
{
    private BaseConnection $db;

    public function __construct(?BaseConnection $db = null)
    {
        $this->db = $db ?? Database::connect();
    }

    /**
     * @param array{supplier_id?: int, employee_id?: int} $filters
     *
     * @return list<PurchaseDocument>
     */
    public function read(ReportScope $scope, ReportPeriod $period, ?int $viewerId = null, array $filters = []): array
    {
        $rows = $this->rows($scope, $period, $viewerId, $filters);

        if ($rows === []) {
            return [];
        }

        $ids     = array_map(static fn (object $row): int => (int) $row->receiving_id, $rows);
        $lines   = $this->lines($ids);
        $numbers = (new DocumentNumbering($this->db))->numbers(DocumentType::Purchase, 'receivings', $ids);
        $notes   = (new DocumentNumbering($this->db))->numbers(DocumentType::DebitNote, 'receivings', $ids);
        $figures = $this->figures($rows);

        $documents = [];

        foreach ($rows as $row) {
            $id     = (int) $row->receiving_id;
            $type   = ReceivingType::from((string) $row->receiving_type);
            $parts  = $lines[$id] ?? ['lines' => [], 'purchase' => []];
            $totals = ReceivingAmounts::totals($parts['purchase']);
            $gross  = '0';
            $off    = '0';

            foreach ($parts['purchase'] as $line) {
                $gross = bcadd($gross, ReceivingAmounts::gross($line), SaleAmounts::SCALE);
                $off   = bcadd($off, bcadd(ReceivingAmounts::discount($line), ReceivingAmounts::invoiceDiscount($line), SaleAmounts::SCALE), SaleAmounts::SCALE);
            }

            $supplierId = $row->supplier_id === null ? null : (int) $row->supplier_id;
            $figure     = $supplierId === null ? null : ($figures[$supplierId][$id] ?? null);
            $settled    = $type === ReceivingType::Return ? '0.0000' : ($supplierId === null ? $totals['total'] : ($figure === null ? '0.0000' : $figure->settled()));
            $state      = $type === ReceivingType::Return ? null : ($supplierId === null ? PaymentState::Paid : $figure?->paymentState());

            $documents[] = new PurchaseDocument(
                $id,
                $type === ReceivingType::Return ? ($notes[$id] ?? DocumentType::DebitNote->fallback($id)) : ($numbers[$id] ?? DocumentType::Purchase->fallback($id)),
                (string) $row->doc_date,
                $type,
                $supplierId,
                (string) $row->supplier_name,
                (string) ($row->tax_id ?? ''),
                $row->employee_id === null ? null : (int) $row->employee_id,
                trim((string) $row->employee_name),
                $row->branch_id === null ? null : (int) $row->branch_id,
                (string) ($row->branch_code ?? ''),
                bcadd($gross, '0', SaleAmounts::SCALE),
                bcadd($off, '0', SaleAmounts::SCALE),
                $totals['tax'],
                $totals['total'],
                bcadd($settled, '0', SaleAmounts::SCALE),
                $row->journal_total === null ? null : bcadd((string) $row->journal_total, '0', SaleAmounts::SCALE),
                $this->withRounding($parts['lines'], $type, bcsub($totals['total'], bcadd($totals['net'], $totals['tax'], SaleAmounts::SCALE), SaleAmounts::SCALE)),
                $state,
                $row->currency_code === null || $row->currency_code === '' ? null : (string) $row->currency_code,
            );
        }

        return $documents;
    }

    /**
     * What the journals that stand for the documents of the scope and the period hold, the returns
     * taken away: the figure the net purchases of the reports are set beside.
     *
     * @param array{supplier_id?: int, employee_id?: int} $filters
     *
     * @return numeric-string
     */
    public function booked(ReportScope $scope, ReportPeriod $period, ?int $viewerId = null, array $filters = []): string
    {
        $total = '0';

        foreach ($this->rows($scope, $period, $viewerId, $filters) as $row) {
            if ($row->journal_total === null) {
                continue;
            }

            $total = $row->receiving_type === ReceivingType::Return->value
                ? bcsub($total, (string) $row->journal_total, SaleAmounts::SCALE)
                : bcadd($total, (string) $row->journal_total, SaleAmounts::SCALE);
        }

        return bcadd($total, '0', SaleAmounts::SCALE);
    }

    /**
     * @param list<PurchaseDocument> $documents
     *
     * @return numeric-string
     */
    public static function net(array $documents): string
    {
        $net = '0';

        foreach ($documents as $document) {
            $net = bcadd($net, $document->signed($document->total), SaleAmounts::SCALE);
        }

        return bcadd($net, '0', SaleAmounts::SCALE);
    }

    /**
     * @param list<PurchaseDocument> $documents
     */
    public static function unposted(array $documents): int
    {
        return count(array_filter($documents, static fn (PurchaseDocument $document): bool => $document->journalTotal === null));
    }

    /**
     * @param array<string, mixed> $filters
     *
     * @return list<object>
     */
    private function rows(ReportScope $scope, ReportPeriod $period, ?int $viewerId, array $filters): array
    {
        if ($scope->isEmpty()) {
            return [];
        }

        $journals = $this->db->prefixTable('accounting_journals');
        $lines    = $this->db->prefixTable('accounting_journal_lines');
        $day      = 'COALESCE(r.invoice_date, DATE(r.receiving_time))';
        $where    = [ScopeSql::branches('r.branch_id', $scope), 'r.receiving_type IN (?, ?)', "{$day} >= ?", "{$day} <= ?"];
        $bindings = [
            EntityAccountService::SUPPLIER, CompanyContext::id(), CompanyContext::id(), ReceivingPostingBuilder::SOURCE_PURCHASE, JournalEntryType::General->value, JournalStatus::Posted->value,
            ReceivingType::Receive->value, ReceivingType::Return->value, $period->from ?? '1000-01-01', $period->to,
        ];

        [$mine, $mineBindings] = (new RecordScope($this->db))->condition('r.employee_id', $viewerId ?? RecordScope::sessionEmployee());

        if ($mine !== '') {
            $where[]  = $mine;
            $bindings = [...$bindings, ...$mineBindings];
        }

        foreach (['supplier_id' => 'r.supplier_id', 'employee_id' => 'r.employee_id'] as $name => $column) {
            if (($filters[$name] ?? 0) > 0) {
                $where[]    = "{$column} = ?";
                $bindings[] = (int) $filters[$name];
            }
        }

        return $this->db->query(
            "SELECT r.receiving_id, r.receiving_type, r.supplier_id, r.employee_id, r.branch_id, COALESCE(r.invoice_date, DATE(r.receiving_time)) AS doc_date,
                    CASE WHEN j.journal_id IS NULL THEN NULL WHEN sa.account_id IS NULL THEN j.total_debit
                         ELSE (SELECT ABS(COALESCE(SUM(l.credit - l.debit), 0)) FROM {$lines} l WHERE l.journal_id = j.journal_id AND l.account_id = sa.account_id) END AS journal_total,
                    s.tax_id, b.code AS branch_code, r.currency_code,
                    COALESCE(NULLIF(s.company_name, ''), TRIM(CONCAT(COALESCE(sp.first_name, ''), ' ', COALESCE(sp.last_name, '')))) AS supplier_name,
                    TRIM(CONCAT(COALESCE(ep.first_name, ''), ' ', COALESCE(ep.last_name, ''))) AS employee_name
             FROM " . $this->db->prefixTable('receivings') . ' r
             LEFT JOIN ' . $this->db->prefixTable('accounting_accounts') . " sa ON sa.entity_type = ? AND sa.entity_id = r.supplier_id AND sa.company_id = ?
             LEFT JOIN {$journals} j ON j.company_id = ? AND j.source_type = ? AND j.source_id = r.receiving_id AND j.entry_type = ? AND j.status = ?
             LEFT JOIN " . $this->db->prefixTable('suppliers') . ' s ON s.person_id = r.supplier_id
             LEFT JOIN ' . $this->db->prefixTable('people') . ' sp ON sp.person_id = r.supplier_id
             LEFT JOIN ' . $this->db->prefixTable('people') . ' ep ON ep.person_id = r.employee_id
             LEFT JOIN ' . $this->db->prefixTable('branches') . ' b ON b.branch_id = r.branch_id
             WHERE ' . implode(' AND ', $where) . '
             ORDER BY doc_date ASC, r.receiving_id ASC',
            $bindings,
        )->getResult();
    }

    /**
     * @param list<int> $ids
     *
     * @return array<int, array{lines: list<array{0: ReceivingLine, 1: object}>, purchase: list<ReceivingLine>}>
     */
    private function lines(array $ids): array
    {
        return (new PurchaseLines($this->db))->of($ids);
    }

    /**
     * @param list<array{0: ReceivingLine, 1: object}> $raw
     *
     * @return list<PurchaseLine>
     */
    private function withRounding(array $raw, ReceivingType $type, string $rounding): array
    {
        $lines = [];
        $count = count($raw);
        $taxes = $this->lineTaxes(array_column($raw, 0));

        foreach ($raw as $index => [$line, $row]) {
            $tax   = $taxes[$index];
            $total = bcadd(ReceivingAmounts::net($line), $tax, SaleAmounts::SCALE);

            if ($index === $count - 1) {
                $total = bcadd($total, $rounding, SaleAmounts::SCALE);
            }

            $units = ReceivingAmounts::units($line);
            $sign  = $type === ReceivingType::Return;

            $lines[] = new PurchaseLine(
                (int) $row->item_id,
                (string) ($row->name ?? ''),
                $row->item_number === null || $row->item_number === '' ? null : (string) $row->item_number,
                (string) ($row->category ?? ''),
                $sign ? bcsub('0', $units, 3) : bcadd($units, '0', 3),
                bcadd($line->unitPrice, '0', 2),
                $sign ? bcsub('0', $tax, SaleAmounts::SCALE) : $tax,
                $sign ? bcsub('0', $total, SaleAmounts::SCALE) : bcadd($total, '0', SaleAmounts::SCALE),
            );
        }

        return $lines;
    }

    /**
     * The tax each line shows: its own, and on the last line of each rate of a purchase saved under the Saudi rules,
     * what rounding the tax of the rate to the halala moved, so the lines add up to the tax of the purchase.
     *
     * @param list<ReceivingLine> $lines
     *
     * @return list<numeric-string>
     */
    private function lineTaxes(array $lines): array
    {
        $taxes = array_map(static fn (ReceivingLine $line): string => ReceivingAmounts::tax($line), $lines);

        if ($lines === [] || ! $lines[0]->vatWorkedOnce) {
            return $taxes;
        }

        $last = [];

        foreach ($lines as $index => $line) {
            if (bccomp($line->taxPercent, '0', 3) > 0) {
                $last[bcadd($line->taxPercent, '0', 3)] = $index;
            }
        }

        foreach (ReceivingAmounts::byRate($lines) as $rate => $figures) {
            $summed = '0';

            foreach ($lines as $index => $line) {
                $summed = bccomp($line->taxPercent, '0', 3) > 0 && bcadd($line->taxPercent, '0', 3) === (string) $rate ? bcadd($summed, $taxes[$index], SaleAmounts::SCALE) : $summed;
            }

            $index         = $last[(string) $rate];
            $taxes[$index] = bcadd($taxes[$index], bcsub($figures['tax'], $summed, SaleAmounts::SCALE), SaleAmounts::SCALE);
        }

        return $taxes;
    }

    /**
     * Where each purchase stands, from the ledger of the supplier it was made from.
     *
     * @param list<object> $rows
     *
     * @return array<int, array<int, PurchaseFigures>>
     */
    private function figures(array $rows): array
    {
        $ledger = new SupplierLedger($this->db);
        $found  = [];

        foreach ($rows as $row) {
            if ($row->supplier_id === null || $row->receiving_type === ReceivingType::Return->value) {
                continue;
            }

            $supplierId = (int) $row->supplier_id;

            if (isset($found[$supplierId])) {
                continue;
            }

            $found[$supplierId] = [];

            foreach ($ledger->position($supplierId)->purchases as $purchase) {
                $found[$supplierId][$purchase->receivingId] = $purchase;
            }
        }

        return $found;
    }
}
