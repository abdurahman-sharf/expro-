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
                'included'       => $category->included ? 1 : 0,
                'calc_version'   => VatCalculator::VERSION,
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
     * What is left of each category of a sale once the returns saved against it are taken off, by category key.
     *
     * @return array<string, array{taxable: string, vat: string}>
     */
    public function leftOf(int $saleId): array
    {
        $left = [];

        foreach ($this->totalsOf($saleId) as $key => $total) {
            $left[$key] = ['taxable' => $total->taxable, 'vat' => $total->vat];
        }

        if ($left === []) {
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
            $key = $this->total($row)->key();

            if (isset($left[$key])) {
                $left[$key]['taxable'] = bcadd($left[$key]['taxable'], (string) $row->taxable, 2);
                $left[$key]['vat']     = bcadd($left[$key]['vat'], (string) $row->vat, 2);
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
        );
    }
}
