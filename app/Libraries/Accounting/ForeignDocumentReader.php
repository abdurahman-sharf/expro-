<?php

namespace App\Libraries\Accounting;

use CodeIgniter\Database\BaseConnection;
use Config\Database;

/**
 * The documents in a currency other than the base that fall in the days of a report, read from the
 * stored sales and purchases, for the reports that add up the amounts of documents as they were typed
 * and so would add amounts of two currencies. A document with no currency is in the base currency.
 */
final class ForeignDocumentReader
{
    private BaseConnection $db;

    public function __construct(?BaseConnection $db = null)
    {
        $this->db = $db ?? Database::connect();
    }

    /**
     * @return list<ForeignDocument> The completed sales, returns and quotes in the days, oldest first.
     */
    public function sales(string $from, string $to, bool $withTime): array
    {
        $builder = $this->db->table('sales')->select('sale_id, invoice_number, currency_code')->where('currency_code IS NOT NULL', null, false)->where('currency_code !=', '')->where('sale_status', COMPLETED);

        $this->between($builder, 'sale_time', $from, $to, $withTime);

        return array_map(
            static fn (object $row): ForeignDocument => new ForeignDocument(trim((string) $row->invoice_number) !== '' ? (string) $row->invoice_number : 'POS ' . $row->sale_id, (string) $row->currency_code),
            $builder->orderBy('sale_time', 'ASC')->orderBy('sale_id', 'ASC')->get()->getResult(),
        );
    }

    /**
     * @return list<ForeignDocument> The purchases and returns in the days, oldest first.
     */
    public function receivings(string $from, string $to, bool $withTime): array
    {
        $builder = $this->db->table('receivings')->select('receiving_id, currency_code')->where('currency_code IS NOT NULL', null, false)->where('currency_code !=', '');

        $this->between($builder, 'receiving_time', $from, $to, $withTime);

        return array_map(
            static fn (object $row): ForeignDocument => new ForeignDocument('RECV ' . $row->receiving_id, (string) $row->currency_code),
            $builder->orderBy('receiving_time', 'ASC')->orderBy('receiving_id', 'ASC')->get()->getResult(),
        );
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
