<?php

namespace Tests\Support;

use App\Models\Sale;
use Config\Database;

/**
 * What a test of the sales reports needs: sales of one line saved through the real sale model with
 * accounting off, dated in a month no other test uses, and removed again so that a later run finds the
 * days empty. A class that uses it also uses EmployeeFixtureTrait and SaleAccountingFixtureTrait, whose
 * dropReportTables clears the temporary tables the reports build, which live as long as the connection.
 */
trait ReportSaleFixtureTrait
{
    /**
     * @param array{type: int, value: string}|null $invoiceDiscount
     * @param list<array<string, mixed>>           $taxes
     */
    protected function reportSale(int $itemId, string $quantity, string $price, ?array $invoiceDiscount, string $day, array $taxes = [], int $type = SALE_TYPE_POS): int
    {
        $status = COMPLETED;
        $items  = [0 => ['item_id' => $itemId, 'line' => 0, 'description' => 'Test Item', 'serialnumber' => '', 'quantity' => $quantity, 'discount' => '0', 'discount_type' => PERCENT, 'cost_price' => '1', 'price' => $price, 'item_location' => 1, 'print_option' => 0]];
        $salesTaxes = [[], $taxes];
        $saleId = model(Sale::class)->save_value(NEW_ENTRY, $status, $items, NEW_ENTRY, $this->createEmployee(), 'report', null, null, null, $type, [], null, $salesTaxes, true, null, $invoiceDiscount);

        Database::connect()->table('sales')->where('sale_id', $saleId)->update(['sale_time' => $day . ' 10:00:00']);

        return $saleId;
    }

    /**
     * @return array<string, mixed>
     */
    protected function reportTax(int $itemId, string $amount): array
    {
        return ['item_id' => $itemId, 'line' => 0, 'name' => 'VAT', 'percent' => '15.0000', 'tax_type' => 1, 'rounding_code' => 0, 'cascade_sequence' => 0, 'item_tax_amount' => $amount, 'sales_tax_code_id' => null, 'tax_category_id' => null, 'jurisdiction_id' => null];
    }

    protected function removeReportSales(string $month): void
    {
        $db  = Database::connect();
        $ids = array_column($db->table('sales')->select('sale_id')->like('sale_time', $month, 'after')->get()->getResultArray(), 'sale_id');

        if ($ids === []) {
            return;
        }

        $db->query('SET FOREIGN_KEY_CHECKS = 0');

        foreach (['sales_payments', 'sales_items_taxes', 'sales_items', 'sales_taxes', 'sales'] as $table) {
            $db->table($table)->whereIn('sale_id', $ids)->delete();
        }

        $db->query('SET FOREIGN_KEY_CHECKS = 1');
    }
}
