<?php

namespace Tests\Support;

use Config\Database;
use Config\OSPOS;

/**
 * Business in two branches on one day, for the tests of the operational reports: a sale and a
 * purchase in the first branch, a sale and a purchase in a second one, and a sale in the first
 * that was saved before branches existed, with no branch written on it. A class that uses it also
 * uses AccountingFixtureTrait, EmployeeFixtureTrait, ItemFixtureTrait, SaleAccountingFixtureTrait
 * and PurchaseAccountingFixtureTrait, and has called startBuying().
 *
 * What the first branch sold comes to 217.00 (207.00 of the sale, 10.00 of the old one) and what
 * the second sold to 126.00. The first bought 5 units at 20.00 and the second 7 at 30.00.
 */
trait ReportBranchFixtureTrait
{
    protected string $reportDay = '2031-03-14';
    protected int $branchB;
    protected int $storeB;
    protected int $customerB;
    protected int $supplierB;

    /**
     * @var array{a: int, b: int}
     */
    protected array $reportItems;

    /**
     * What the two items are called, which no other run of the tests has used.
     *
     * @var array{a: string, b: string}
     */
    protected array $reportNames;

    /**
     * @var array{a: int, b: int, old: int}
     */
    protected array $reportSales;

    /**
     * @var array{a: int, b: int}
     */
    protected array $reportReceivings;

    protected function businessInTwoBranches(): void
    {
        $db = $this->accountingDb();

        Database::connect()->query('SELECT 1');
        config(OSPOS::class);
        helper(['locale', 'number']);

        $this->branchB   = $this->createBranch('R' . substr(md5(uniqid('', true)), 0, 8), 'Report branch B');
        $this->storeB    = $this->locationOfBranch($this->branchB, 'ReportStoreB' . substr(md5(uniqid('', true)), 0, 6));
        $this->customerB = $this->anotherCustomer('Bilal', 'ReportBuyerB');
        $this->supplierB = $this->anotherSupplier('ReportSupplierB');

        $itemA = $this->item('40.00');
        $itemB = $this->item('30.00');

        $tag = substr(md5(uniqid('', true)), 0, 6);

        $this->reportNames = ['a' => "Report item A {$tag}", 'b' => "Report item B {$tag}"];

        $db->table('items')->where('item_id', $itemA)->update(['name' => $this->reportNames['a'], 'category' => 'ReportCatA', 'supplier_id' => $this->supplierId, 'reorder_level' => '100']);
        $db->table('items')->where('item_id', $itemB)->update(['name' => $this->reportNames['b'], 'category' => 'ReportCatB', 'supplier_id' => $this->supplierB, 'reorder_level' => '100']);

        $this->reportItems = ['a' => $itemA, 'b' => $itemB];

        $receivingA = $this->buy([$this->purchaseLine($itemA, 0, '5', '20')], $this->supplierId);
        $receivingB = $this->buy([$this->purchaseLine($itemB, 0, '7', '30', '0', '0', PERCENT, $this->storeB)], $this->supplierB);

        $this->assertGreaterThan(0, $receivingA);
        $this->assertGreaterThan(0, $receivingB);

        $this->reportReceivings = ['a' => $receivingA, 'b' => $receivingB];

        $saleA = $this->save(
            [$this->line($itemA, 0, '2', '100', '40.00', '10')],
            [array_merge($this->tax($itemA, 0, '27.00'), ['name' => 'VAT-A'])],
            [$this->payment(lang('Sales.cash'), '207.00')],
            SALE_TYPE_POS,
            $this->customerId,
        );
        $saleB = $this->save(
            [$this->line($itemB, 0, '3', '50', '30.00', '20', PERCENT, $this->storeB)],
            [array_merge($this->tax($itemB, 0, '6.00'), ['name' => 'VAT-B', 'percent' => '5.0000'])],
            [$this->payment(lang('Sales.debit'), '126.00')],
            SALE_TYPE_POS,
            $this->customerB,
        );

        $this->assertGreaterThan(0, $saleA);
        $this->assertGreaterThan(0, $saleB);

        $time = $this->reportDay . ' 10:00:00';

        $db->table('sales')->whereIn('sale_id', [$saleA, $saleB])->update(['sale_time' => $time]);
        $db->table('receivings')->whereIn('receiving_id', [$receivingA, $receivingB])->update(['receiving_time' => $this->reportDay . ' 09:00:00']);

        foreach ([[$saleA, '27.00', '180.00', 'VAT-A', '15.0000'], [$saleB, '6.00', '120.00', 'VAT-B', '5.0000']] as [$saleId, $amount, $basis, $name, $rate]) {
            $db->table('sales_taxes')->insert([
                'sale_id'        => $saleId, 'tax_type' => 1, 'tax_group' => $name, 'sale_tax_basis' => $basis, 'sale_tax_amount' => $amount,
                'print_sequence' => 0, 'name' => $name, 'tax_rate' => $rate, 'rounding_code' => 0,
            ]);
        }

        $this->reportSales = ['a' => $saleA, 'b' => $saleB, 'old' => $this->oldSale($itemA)];
    }

    /**
     * A sale as the system saved them before it knew of branches: no branch on it, so it carries the
     * default one.
     */
    private function oldSale(int $itemId): int
    {
        $db = $this->accountingDb();

        $db->table('sales')->insert([
            'sale_time' => $this->reportDay . ' 08:00:00', 'customer_id' => $this->customerId, 'employee_id' => $this->employeeId,
            'comment'   => 'old', 'sale_status' => COMPLETED, 'sale_type' => SALE_TYPE_POS,
        ]);

        $saleId = (int) $db->insertID();

        $db->table('sales_items')->insert([
            'sale_id'         => $saleId, 'item_id' => $itemId, 'line' => 0, 'description' => 'old', 'serialnumber' => '', 'quantity_purchased' => '1',
            'item_cost_price' => '4.00', 'item_unit_price' => '10.00', 'discount' => '0', 'discount_type' => PERCENT, 'item_location' => self::LOCATION_ID,
        ]);

        return $saleId;
    }

    /**
     * Who reads the reports: the employee of the first branch, of the second, of both, of every
     * branch, or one who works in no branch.
     */
    protected function reportEmployee(string $scope): int
    {
        return match ($scope) {
            'a'     => $this->employeeWithGrants([], [1]),
            'b'     => $this->employeeWithGrants([], [$this->branchB]),
            'both'  => $this->employeeWithGrants([], [1, $this->branchB]),
            'none'  => $this->employeeWithGrants([], []),
            default => $this->employeeWithGrants([]),
        };
    }

    /**
     * Makes the employee the one who is logged in, or nobody, which is the system itself, and
     * drops the tables the reports keep for the length of a connection, which a request does not
     * outlive and a test does.
     */
    protected function readingAs(?int $employeeId): void
    {
        $employeeId === null ? session()->remove('person_id') : session()->set('person_id', $employeeId);

        $this->dropEveryReportTable();
    }

    protected function dropEveryReportTable(): void
    {
        $db = $this->accountingDb();

        foreach (['sales_items_temp', 'sales_payments_temp', 'sales_items_taxes_temp', 'sales_report_payments_temp', 'sumpay_taxes_temp', 'sumpay_items_temp', 'sumpay_payments_temp', 'receivings_items_temp'] as $table) {
            $db->query('DROP TEMPORARY TABLE IF EXISTS ' . $db->prefixTable($table));
        }
    }

    protected function forgetTheReportBusiness(): void
    {
        session()->remove('person_id');
        $this->dropEveryReportTable();

        $db = $this->accountingDb();

        if (isset($this->reportSales['old'])) {
            $db->table('sales_items')->where('sale_id', $this->reportSales['old'])->delete();
            $db->table('sales')->where('sale_id', $this->reportSales['old'])->delete();
        }

        $db->table('sales_taxes')->whereIn('sale_id', array_values($this->reportSales ?? []))->delete();
        $db->table('items')->whereIn('item_id', array_values($this->reportItems ?? []))->update(['supplier_id' => null]);
        $db->table('expenses')->where('description', 'report expense')->delete();
        $db->table('expense_categories')->where('category_name', 'ReportExpenses')->delete();
    }

    /**
     * Takes the two items and what is left of their stock out, once the sales and purchases that
     * referred to them are gone.
     */
    protected function removeTheReportItems(): void
    {
        $db = $this->accountingDb();

        $db->query('SET FOREIGN_KEY_CHECKS = 0');

        foreach (array_values($this->reportItems ?? []) as $itemId) {
            $db->table('item_quantities')->where('item_id', $itemId)->delete();
            $db->table('inventory')->where('trans_items', $itemId)->delete();
            $db->table('items')->where('item_id', $itemId)->delete();
        }

        $db->query('SET FOREIGN_KEY_CHECKS = 1');
    }

    /**
     * @return array<string, string>
     */
    protected function saleInputs(string $saleType = 'sales'): array
    {
        return ['start_date' => $this->reportDay, 'end_date' => $this->reportDay, 'sale_type' => $saleType, 'location_id' => 'all'];
    }

    protected function amount(mixed $value): string
    {
        return bcadd((string) ($value ?? '0'), '0', 2);
    }
}
