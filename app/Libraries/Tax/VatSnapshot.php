<?php

namespace App\Libraries\Tax;

use App\Libraries\Accounting\AccountingError;
use App\Libraries\Accounting\AccountingException;
use CodeIgniter\Database\BaseConnection;
use Config\Database;

/**
 * Keeps and reads what the VAT of a sale came to when it was saved under the Saudi rules: each category of the
 * sale with its taxable amount and its VAT, and, next to the class of each line, its net, VAT and amount with VAT.
 * A sale saved without them is read the way it always was.
 */
class VatSnapshot
{
    private BaseConnection $db;
    private ?bool $installed = null;
    private ?bool $charges   = null;

    public function __construct(?BaseConnection $db = null)
    {
        $this->db = $db ?? Database::connect();
    }

    public function isInstalled(): bool
    {
        return $this->installed ??= $this->db->tableExists($this->db->prefixTable('sales_vat_totals'), false);
    }

    /**
     * Writes the categories of a sale, in the transaction of the sale.
     *
     * @param array<string, VatCategoryTotal> $categories
     *
     * @throws AccountingException
     */
    public function record(int $saleId, array $categories): void
    {
        foreach ($categories as $category) {
            $saved = $this->db->table('sales_vat_totals')->replace([
                'sale_id'        => $saleId,
                'category'       => $category->category,
                'percent'        => $category->percent,
                'exemption_code' => $category->exemptionCode ?? '',
                'exemption_text' => $category->exemptionText,
                'taxable'        => $category->taxable,
                'vat'            => $category->vat,
                ...($this->hasCharges() ? ['charges' => $category->charges] : []),
                'included'     => $category->included ? 1 : 0,
                'calc_version' => VatCalculator::VERSION,
            ]);

            if ($saved === false) {
                throw new AccountingException(AccountingError::WriteFailed, 'The VAT of a sale could not be saved.');
            }
        }
    }

    /**
     * The categories a sale was saved with, by category key; nothing for a sale saved without them.
     *
     * @return array<string, VatCategoryTotal>
     */
    public function totalsOf(int $saleId): array
    {
        if (! $this->isInstalled()) {
            return [];
        }

        $totals = [];

        foreach ($this->db->table('sales_vat_totals')->where('sale_id', $saleId)->orderBy('category')->orderBy('percent', 'DESC')->get()->getResult() as $row) {
            $total                 = $this->total($row);
            $totals[$total->key()] = $total;
        }

        return $totals;
    }

    /**
     * BT-112 of a sale saved with its categories: their taxable amounts and their VAT, without the rounding by which
     * the customer paid what the register charged; null for a sale saved without them.
     */
    public function totalWithVatOf(int $saleId): ?string
    {
        $totals = $this->totalsOf($saleId);

        if ($totals === []) {
            return null;
        }

        $sum = '0.00';

        foreach ($totals as $total) {
            $sum = bcadd($sum, bcadd(ltrim($total->taxable, '-'), ltrim($total->vat, '-'), 2), 2);
        }

        return $sum;
    }

    /**
     * The categories of several sales, by sale and then by category key; a sale saved without them is not there.
     *
     * @param list<int> $saleIds
     *
     * @return array<int, array<string, VatCategoryTotal>>
     */
    public function totalsOfSales(array $saleIds): array
    {
        if ($saleIds === [] || ! $this->isInstalled()) {
            return [];
        }

        $totals = [];

        foreach ($this->db->table('sales_vat_totals')->whereIn('sale_id', $saleIds)->get()->getResult() as $row) {
            $total                                      = $this->total($row);
            $totals[(int) $row->sale_id][$total->key()] = $total;
        }

        return $totals;
    }

    /**
     * The net, VAT and amount with VAT each line of a sale was saved with, by item and line; nothing for lines saved
     * without them.
     *
     * @return array<string, VatLineResult>
     */
    public function linesOf(int $saleId): array
    {
        if (! $this->isInstalled()) {
            return [];
        }

        $lines = [];

        foreach ($this->db->table('sales_items_vat')->where('sale_id', $saleId)->where('calc_version IS NOT NULL', null, false)->get()->getResult() as $row) {
            $lines[$row->item_id . ':' . $row->line] = new VatLineResult(
                bcadd((string) $row->net, '0', 2),
                bcadd((string) $row->vat, '0', 2),
                bcadd((string) $row->gross, '0', 2),
                bcmul((string) $row->net, bcdiv((string) $row->percent, '100', 10), 6),
                VatCalculator::categoryKey((string) $row->category, (string) $row->percent, $row->exemption_code === null || $row->exemption_code === '' ? null : (string) $row->exemption_code),
            );
        }

        return $lines;
    }

    /**
     * What is left of each category of a sale once the returns saved against it are taken off, by category key,
     * and what those returns gave back. The taxable amount left is that of the lines: the shipping a category took
     * in is not given back with goods. What the returns gave back is their whole taxable amount and VAT, so that the
     * VAT of the next return is rounded on all that came back (VatCalculator::capReturn). Nothing when a completed
     * return of the sale was saved without its categories, since what it gave back cannot be told by category and a
     * cap worked from the rest would let the next return give back too much.
     *
     * @return array<string, array{taxable: string, vat: string, returned_taxable: string, returned_vat: string}>
     */
    public function leftOf(int $saleId): array
    {
        $left = [];

        foreach ($this->totalsOf($saleId) as $key => $total) {
            $left[$key] = ['taxable' => $total->lines(), 'vat' => $total->vat, 'returned_taxable' => '0.00', 'returned_vat' => '0.00'];
        }

        if ($left === []) {
            return [];
        }

        $untold = $this->db->table('sales AS s')
            ->join('sales_vat_totals AS t', 't.sale_id = s.sale_id', 'left')
            ->where(['s.return_of_sale_id' => $saleId, 's.sale_status' => COMPLETED])
            ->where('t.sale_id IS NULL', null, false)
            ->countAllResults();

        if ($untold > 0) {
            return [];
        }

        $returns = $this->db->table('sales_vat_totals AS t')
            ->select('t.*')
            ->join('sales AS s', 's.sale_id = t.sale_id')
            ->where('s.return_of_sale_id', $saleId)
            ->where('s.sale_status', COMPLETED)
            ->get()
            ->getResult();

        foreach ($returns as $row) {
            $total = $this->total($row);
            $key   = $total->key();

            if (isset($left[$key])) {
                $left[$key]['taxable']          = bcadd($left[$key]['taxable'], $total->lines(), 2);
                $left[$key]['vat']              = bcadd($left[$key]['vat'], (string) $row->vat, 2);
                $left[$key]['returned_taxable'] = bcsub($left[$key]['returned_taxable'], $total->taxable, 2);
                $left[$key]['returned_vat']     = bcsub($left[$key]['returned_vat'], (string) $row->vat, 2);
            }
        }

        return $left;
    }

    /**
     * The categories of a sale whose units a return takes back to the last, with the returns saved before it.
     *
     * @param array<int, string> $returning Units by item the return gives back, positive.
     *
     * @return list<string> Category keys.
     */
    public function exhaustedBy(int $saleId, array $returning): array
    {
        if (! $this->isInstalled()) {
            return [];
        }

        $sold = [];

        foreach ($this->db->table('sales_items AS i')
            ->select('i.item_id, v.category, v.percent, v.exemption_code, SUM(ABS(i.quantity_purchased)) AS units', false)
            ->join('sales_items_vat AS v', 'v.sale_id = i.sale_id AND v.item_id = i.item_id AND v.line = i.line')
            ->where('i.sale_id', $saleId)
            ->groupBy('i.item_id, v.category, v.percent, v.exemption_code')
            ->get()
            ->getResult() as $row) {
            $sold[VatCalculator::categoryKey((string) $row->category, (string) $row->percent, $row->exemption_code === null || $row->exemption_code === '' ? null : (string) $row->exemption_code)][(int) $row->item_id] = (string) $row->units;
        }

        $earlier = [];

        foreach ($this->db->table('sales_items AS i')
            ->select('i.item_id, SUM(ABS(i.quantity_purchased)) AS units', false)
            ->join('sales AS s', 's.sale_id = i.sale_id')
            ->where(['s.return_of_sale_id' => $saleId, 's.sale_status' => COMPLETED, 's.sale_type' => SALE_TYPE_RETURN])
            ->groupBy('i.item_id')
            ->get()
            ->getResult() as $row) {
            $earlier[(int) $row->item_id] = (string) $row->units;
        }

        $exhausted = [];

        foreach ($sold as $key => $items) {
            $last = true;

            foreach ($items as $itemId => $units) {
                if (bccomp(bcadd($earlier[$itemId] ?? '0', $returning[$itemId] ?? '0', 3), $units, 3) < 0) {
                    $last = false;
                }
            }

            if ($last) {
                $exhausted[] = $key;
            }
        }

        return $exhausted;
    }

    private function total(object $row): VatCategoryTotal
    {
        return new VatCategoryTotal(
            (string) $row->category,
            (string) $row->percent,
            (string) $row->exemption_code === '' ? null : (string) $row->exemption_code,
            $row->exemption_text === null ? null : (string) $row->exemption_text,
            bcadd((string) $row->taxable, '0', 2),
            bcadd((string) $row->vat, '0', 2),
            (int) $row->included === 1,
            bcadd((string) ($row->charges ?? '0'), '0', 2),
        );
    }

    public function hasCharges(): bool
    {
        return $this->charges ??= $this->db->query(
            'SELECT 1 FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ?',
            [$this->db->prefixTable('sales_vat_totals'), 'charges'],
        )->getRow() !== null;
    }
}
