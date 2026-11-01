<?php

namespace App\Models\Reports;

use App\Libraries\InvoiceDiscount;
use Config\OSPOS;

class Summary_taxes extends Summary_report
{
    private array $config;

    public function __construct()
    {
        parent::__construct();
        $this->config = config(OSPOS::class)->settings;
    }

    protected function _get_data_columns(): array
    {
        return [
            ['tax_name'     => lang('Reports.tax_name'), 'sortable' => false],
            ['tax_percent'  => lang('Reports.tax_percent'), 'sorter' => 'number_sorter'],
            ['report_count' => lang('Reports.sales'), 'sorter' => 'number_sorter'],
            ['subtotal'     => lang('Reports.subtotal'), 'sorter' => 'number_sorter'],
            ['tax'          => lang('Reports.tax'), 'sorter' => 'number_sorter'],
            ['total'        => lang('Reports.total'), 'sorter' => 'number_sorter']
        ];
    }

    protected function _where(array $inputs, &$builder): void
    {
        $builder->where('sales.sale_status', COMPLETED);

        if (empty($this->config['date_or_time_format'])) {
            $builder->where('DATE(sales.sale_time) BETWEEN ' . $this->db->escape($inputs['start_date']) . ' AND ' . $this->db->escape($inputs['end_date']));
        } else {
            $builder->where('sales.sale_time BETWEEN ' . $this->db->escape(rawurldecode($inputs['start_date'])) . ' AND ' . $this->db->escape(rawurldecode($inputs['end_date'])));
        }
    }

    public function getData(array $inputs): array
    {
        $decimals = totals_decimals();
        $dbPrefix = $this->db->getPrefix();

        $saleAmount = '(CASE WHEN ' . $dbPrefix . 'sales_items.discount_type = ' . PERCENT
            . " THEN " . $dbPrefix . "sales_items.quantity_purchased * " . $dbPrefix . "sales_items.item_unit_price - ROUND(" . $dbPrefix . "sales_items.quantity_purchased * " . $dbPrefix . "sales_items.item_unit_price * " . $dbPrefix . "sales_items.discount / 100, $decimals)"
            . ' ELSE ' . $dbPrefix . 'sales_items.quantity_purchased * (' . $dbPrefix . "sales_items.item_unit_price - " . $dbPrefix . "sales_items.discount) END - " . InvoiceDiscount::sqlShare($dbPrefix . 'sales_items') . ")";

        $saleTax = "IFNULL(" . $dbPrefix . "sales_items_taxes.item_tax_amount, 0)";

        $saleSubtotal = "(CASE WHEN " . $dbPrefix . "sales_items_taxes.tax_type = 0 THEN ROUND($saleAmount - $saleTax, $decimals) ELSE ROUND($saleAmount, $decimals) END)";
        $saleTaxRounded = "ROUND($saleTax, $decimals)";
        $saleTotal = "($saleSubtotal + $saleTaxRounded)";

        $subqueryBuilder = $this->db->table('sales_items');
        $subqueryBuilder->select(
            "name AS name, "
            . "CONCAT(IFNULL(ROUND(percent, $decimals), 0), '%') AS percent, "
            . "sales.sale_id AS sale_id, "
            . "$saleSubtotal AS subtotal, "
            . "$saleTaxRounded AS tax, "
            . "$saleTotal AS total"
        );

        $subqueryBuilder->join('sales', 'sales_items.sale_id = sales.sale_id', 'inner');
        $subqueryBuilder->join(
            'sales_items_taxes',
            'sales_items.sale_id = sales_items_taxes.sale_id AND sales_items.item_id = sales_items_taxes.item_id AND sales_items.line = sales_items_taxes.line',
            'left outer'
        );

        $subqueryBuilder->where('sale_status', COMPLETED);

        if (empty($this->config['date_or_time_format'])) {
            $subqueryBuilder->where(
                'DATE(' . $dbPrefix . 'sales.sale_time) BETWEEN ' . $this->db->escape($inputs['start_date'])
                . ' AND ' . $this->db->escape($inputs['end_date'])
            );
        } else {
            $subqueryBuilder->where(
                'sales.sale_time BETWEEN ' . $this->db->escape(rawurldecode($inputs['start_date']))
                . ' AND ' . $this->db->escape(rawurldecode($inputs['end_date']))
            );
        }

        $this->limitToBranches($subqueryBuilder, 'sales.branch_id');

        $builder = $this->db->newQuery()->fromSubquery($subqueryBuilder, 'temp_taxes');
        $builder->select(
            "name, percent, COUNT(DISTINCT sale_id) AS count, "
            . "ROUND(SUM(subtotal), $decimals) AS subtotal, "
            . "ROUND(SUM(tax), $decimals) AS tax, "
            . "ROUND(SUM(total), $decimals) AS total"
        );
        $builder->groupBy('percent, name');

        return $builder->get()->getResultArray();
    }

    public function getSummaryData(array $inputs): array
    {
        $decimals = totals_decimals();
        $data = $this->getData($inputs);

        $subtotal = 0;
        $tax = 0;
        $total = 0;
        $count = 0;

        foreach ($data as $row) {
            $subtotal += (float) $row['subtotal'];
            $tax += (float) $row['tax'];
            $total += (float) $row['total'];
            $count += (int) $row['count'];
        }

        return [
            'subtotal' => round($subtotal, $decimals),
            'tax'      => round($tax, $decimals),
            'total'    => round($total, $decimals),
            'count'    => $count
        ];
    }
}