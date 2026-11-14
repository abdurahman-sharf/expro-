<?php

namespace App\Libraries\Accounting;

use CodeIgniter\Database\BaseConnection;
use Config\Database;

/**
 * The documents in a currency other than the base that a legacy report adds up, read from the stored
 * sales and purchases with the limits the report applies to its own figures (branches, kind of sale,
 * stock location), so the note under its header never names a document the report does not hold. A
 * document with no currency is in the base currency.
 */
final class ForeignDocumentReader
{
    private BaseConnection $db;

    public function __construct(?BaseConnection $db = null)
    {
        $this->db = $db ?? Database::connect();
    }

    /**
     * @param list<int>|null $branchIds Null reads every branch and an empty list none.
     * @param string         $saleType  The kind of sale of the legacy reports: complete, sales, quotes, work_orders, canceled or returns.
     *
     * @return list<ForeignDocument> The sales in the days, oldest first.
     */
    public function sales(string $from, string $to, bool $withTime, ?array $branchIds, string $saleType = 'all', string $locationId = 'all'): array
    {
        $builder = $this->db->table('sales AS sales')->select('sales.sale_id, sales.invoice_number, sales.currency_code')->where('sales.currency_code IS NOT NULL', null, false)->where('sales.currency_code !=', '');

        $this->ofTheSaleType($builder, $saleType);
        $this->ofTheBranches($builder, 'sales.branch_id', $branchIds);

        if ($locationId !== 'all') {
            $builder->where('EXISTS (SELECT 1 FROM ' . $this->db->prefixTable('sales_items') . ' AS scoped_items WHERE scoped_items.sale_id = sales.sale_id AND scoped_items.item_location = ' . $this->db->escape($locationId) . ')', null, false);
        }

        $this->between($builder, 'sales.sale_time', $from, $to, $withTime);

        return array_map(
            static fn (object $row): ForeignDocument => new ForeignDocument(trim((string) $row->invoice_number) !== '' ? (string) $row->invoice_number : 'POS ' . $row->sale_id, (string) $row->currency_code),
            $builder->orderBy('sales.sale_time', 'ASC')->orderBy('sales.sale_id', 'ASC')->get()->getResult(),
        );
    }

    /**
     * @param list<int>|null $branchIds Null reads every branch and an empty list none.
     *
     * @return list<ForeignDocument> The purchases and returns in the days, oldest first.
     */
    public function receivings(string $from, string $to, bool $withTime, ?array $branchIds, string $locationId = 'all'): array
    {
        $builder = $this->db->table('receivings AS receivings')->select('receivings.receiving_id, receivings.currency_code')->where('receivings.currency_code IS NOT NULL', null, false)->where('receivings.currency_code !=', '');

        if ($branchIds !== null) {
            $list = implode(', ', array_map('intval', $branchIds));

            $branchIds === [] ? $builder->where('1 = 0', null, false) : $builder->where("(receivings.branch_id IN ({$list}) OR receivings.to_branch_id IN ({$list}))", null, false);
        }

        if ($locationId !== 'all') {
            $builder->where('EXISTS (SELECT 1 FROM ' . $this->db->prefixTable('receivings_items') . ' AS scoped_items WHERE scoped_items.receiving_id = receivings.receiving_id AND scoped_items.item_location = ' . $this->db->escape($locationId) . ')', null, false);
        }

        $this->between($builder, 'receivings.receiving_time', $from, $to, $withTime);

        return array_map(
            static fn (object $row): ForeignDocument => new ForeignDocument('RECV ' . $row->receiving_id, (string) $row->currency_code),
            $builder->orderBy('receivings.receiving_time', 'ASC')->orderBy('receivings.receiving_id', 'ASC')->get()->getResult(),
        );
    }

    private function ofTheSaleType(object $builder, string $saleType): void
    {
        match ($saleType) {
            'complete'    => $builder->where('sales.sale_status', COMPLETED)->whereIn('sales.sale_type', [SALE_TYPE_POS, SALE_TYPE_INVOICE, SALE_TYPE_RETURN]),
            'sales'       => $builder->where('sales.sale_status', COMPLETED)->whereIn('sales.sale_type', [SALE_TYPE_POS, SALE_TYPE_INVOICE]),
            'quotes'      => $builder->where('sales.sale_status', SUSPENDED)->where('sales.sale_type', SALE_TYPE_QUOTE),
            'work_orders' => $builder->where('sales.sale_status', SUSPENDED)->where('sales.sale_type', SALE_TYPE_WORK_ORDER),
            'canceled'    => $builder->where('sales.sale_status', CANCELED),
            'returns'     => $builder->where('sales.sale_status', COMPLETED)->where('sales.sale_type', SALE_TYPE_RETURN),
            default       => null,
        };
    }

    /**
     * @param list<int>|null $branchIds
     */
    private function ofTheBranches(object $builder, string $column, ?array $branchIds): void
    {
        if ($branchIds === null) {
            return;
        }

        $branchIds === [] ? $builder->where('1 = 0', null, false) : $builder->whereIn($column, array_map('intval', $branchIds));
    }

    private function between(object $builder, string $column, string $from, string $to, bool $withTime): void
    {
        if ($withTime) {
            $builder->where("{$column} BETWEEN " . $this->db->escape($from) . ' AND ' . $this->db->escape($to), null, false);

            return;
        }

        $builder->where("DATE({$column}) BETWEEN " . $this->db->escape($from) . ' AND ' . $this->db->escape($to), null, false);
    }
}
