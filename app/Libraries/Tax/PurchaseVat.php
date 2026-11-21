<?php

namespace App\Libraries\Tax;

use CodeIgniter\Database\BaseConnection;
use Config\Database;

/**
 * Whether a purchase is read by the Saudi VAT rule: the mark it was saved with (PurchaseVatVersion), and whether a
 * purchase saved now gets it. A purchase without the mark is read as it always was.
 */
final class PurchaseVat
{
    public const VERSION = 1;

    private BaseConnection $db;
    private ?bool $installed = null;

    public function __construct(?BaseConnection $db = null)
    {
        $this->db = $db ?? Database::connect();
    }

    public function isInstalled(): bool
    {
        return $this->installed ??= $this->db->query(
            'SELECT 1 FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ?',
            [$this->db->prefixTable('receivings'), 'vat_calc_version'],
        )->getRow() !== null;
    }

    /**
     * The column the header of a purchase saved now carries: the mark while the Saudi rules apply, nothing otherwise.
     *
     * @return array<string, int>
     */
    public function columnsForNew(): array
    {
        return $this->isInstalled() && (new CompanyTaxProfile($this->db))->isSaudiMode() ? ['vat_calc_version' => self::VERSION] : [];
    }

    /**
     * The receivings that carry the mark, among these.
     *
     * @param list<int> $receivingIds
     *
     * @return array<int, true>
     */
    public function markedAmong(array $receivingIds): array
    {
        if ($receivingIds === [] || ! $this->isInstalled()) {
            return [];
        }

        $marked = [];

        foreach ($this->db->table('receivings')->select('receiving_id')->whereIn('receiving_id', $receivingIds)->where('vat_calc_version IS NOT NULL', null, false)->get()->getResult() as $row) {
            $marked[(int) $row->receiving_id] = true;
        }

        return $marked;
    }
}
