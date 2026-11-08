<?php

namespace App\Libraries\Accounting;

use App\Libraries\InvoiceDiscount;
use App\Libraries\Roles\RecordScope;
use CodeIgniter\Database\BaseConnection;
use Config\Database;

/**
 * The documents that wait to be issued or answered, as two lists: the draft invoices and the quotes
 * that are still being worked on, a page of each at a time. Nothing is written. A draft is known by
 * its temporary number and a quote by its number, or by its temporary one while it is a draft. What
 * a document comes to is what its lines sell for after their discounts, without tax: a draft has no
 * tax until it is issued.
 */
final class SaleDocumentList
{
    public const PAGE_SIZE = 50;

    private const DRAFTS = 's.sale_type = ' . SALE_TYPE_INVOICE;

    private BaseConnection $db;

    public function __construct(?BaseConnection $db = null)
    {
        $this->db = $db ?? Database::connect();
    }

    /**
     * @param list<int>|null $branchIds The branches the reader works in, null for every branch.
     * @param int|null       $viewerId  The reader, whose own documents are all that is listed when they see their own records only.
     * @param int            $page      The page, from 1.
     *
     * @return list<object> Newest first, each with its label, customer, employee, branch and subtotal.
     */
    public function drafts(?array $branchIds, ?int $viewerId = null, int $page = 1): array
    {
        $rows = $this->read(self::DRAFTS, $branchIds, $viewerId, $page);

        foreach ($rows as $row) {
            $row->label = $row->invoice_number !== null && $row->invoice_number !== '' ? (string) $row->invoice_number : DraftInvoice::label((int) $row->sale_id);
        }

        return $rows;
    }

    /**
     * @param list<int>|null $branchIds
     *
     * @return list<object> Newest first, each with its label, state, customer, employee, branch, subtotal and the invoice made from it.
     */
    public function quotes(?array $branchIds, ?QuoteStatus $only = null, ?int $viewerId = null, int $page = 1): array
    {
        $rows = $this->read($this->quoteCondition($only), $branchIds, $viewerId, $page);

        foreach ($rows as $row) {
            $status      = QuoteStatus::fromStored($row->quote_status);
            $row->status = $status;
            $row->label  = $row->quote_number !== null && $row->quote_number !== ''
                ? (string) $row->quote_number
                : ($status === QuoteStatus::Draft ? DraftInvoice::label((int) $row->sale_id) : '#' . (int) $row->sale_id);
        }

        return $rows;
    }

    /**
     * @param list<int>|null $branchIds
     */
    public function countDrafts(?array $branchIds, ?int $viewerId = null): int
    {
        return $this->count(self::DRAFTS, $branchIds, $viewerId);
    }

    /**
     * @param list<int>|null $branchIds
     */
    public function countQuotes(?array $branchIds, ?QuoteStatus $only = null, ?int $viewerId = null): int
    {
        return $this->count($this->quoteCondition($only), $branchIds, $viewerId);
    }

    public static function pages(int $total): int
    {
        return max(1, intdiv($total + self::PAGE_SIZE - 1, self::PAGE_SIZE));
    }

    private function quoteCondition(?QuoteStatus $only): string
    {
        $condition = 's.sale_type = ' . SALE_TYPE_QUOTE;

        if ($only !== null) {
            $condition .= $only === QuoteStatus::Open ? ' AND (s.quote_status IS NULL OR s.quote_status = ' . QuoteStatus::Open->value . ')' : ' AND s.quote_status = ' . $only->value;
        }

        return $condition;
    }

    /**
     * @param list<int>|null $branchIds
     */
    private function scope(?array $branchIds, ?int $viewerId): string
    {
        $scope = $branchIds === null ? '' : ' AND s.branch_id IN (' . implode(', ', array_map('intval', $branchIds)) . ')';
        $mine  = (new RecordScope($this->db))->limit($viewerId);

        return $scope . ($mine === null ? '' : ' AND s.employee_id = ' . $mine);
    }

    /**
     * @param list<int>|null $branchIds
     */
    private function count(string $condition, ?array $branchIds, ?int $viewerId): int
    {
        if ($branchIds === []) {
            return 0;
        }

        return (int) $this->db->query(
            'SELECT COUNT(*) AS total FROM ' . $this->db->prefixTable('sales') . ' s WHERE s.sale_status = ' . SUSPENDED . " AND {$condition}" . $this->scope($branchIds, $viewerId),
        )->getRow()->total;
    }

    /**
     * @param list<int>|null $branchIds
     *
     * @return list<object>
     */
    private function read(string $condition, ?array $branchIds, ?int $viewerId, int $page): array
    {
        if ($branchIds === []) {
            return [];
        }

        $sales    = $this->db->prefixTable('sales');
        $items    = $this->db->prefixTable('sales_items');
        $branches = $this->db->prefixTable('branches');
        $people   = $this->db->prefixTable('people');
        $decimals = totals_decimals();
        $share    = InvoiceDiscount::sqlShare('i');
        $line     = 'CASE WHEN i.discount_type = ' . PERCENT
            . " THEN i.quantity_purchased * i.item_unit_price - ROUND(i.quantity_purchased * i.item_unit_price * i.discount / 100, {$decimals})"
            . " ELSE i.quantity_purchased * (i.item_unit_price - i.discount) END - {$share}";
        $scope  = $this->scope($branchIds, $viewerId);
        $offset = (max(1, $page) - 1) * self::PAGE_SIZE;

        return $this->db->query(
            "SELECT s.sale_id, s.sale_time, s.customer_id, s.branch_id, s.invoice_number, s.quote_number, s.quote_status, b.code AS branch_code,
                    TRIM(CONCAT(cp.first_name, ' ', cp.last_name)) AS customer_name,
                    TRIM(CONCAT(ep.first_name, ' ', ep.last_name)) AS employee_name,
                    ROUND(COALESCE(SUM({$line}), 0), {$decimals}) AS subtotal,
                    (SELECT MIN(made.sale_id) FROM {$sales} made WHERE made.source_quote_id = s.sale_id AND made.sale_status = " . COMPLETED . ") AS invoice_sale_id
             FROM {$sales} s
             JOIN {$branches} b ON b.branch_id = s.branch_id
             LEFT JOIN {$people} cp ON cp.person_id = s.customer_id
             LEFT JOIN {$people} ep ON ep.person_id = s.employee_id
             LEFT JOIN {$items} i ON i.sale_id = s.sale_id
             WHERE s.sale_status = " . SUSPENDED . " AND {$condition}{$scope}
             GROUP BY s.sale_id, s.sale_time, s.customer_id, s.branch_id, s.invoice_number, s.quote_number, s.quote_status, b.code, customer_name, employee_name
             ORDER BY s.sale_id DESC
             LIMIT " . self::PAGE_SIZE . " OFFSET {$offset}",
        )->getResult();
    }
}
