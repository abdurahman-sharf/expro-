<?php

namespace Tests\Support\Ledger;

use App\Libraries\Accounting\ReturnDocument;

/**
 * Documents in the dollar, in the seeds that switch it on: invoices and purchases dated on days the rate
 * was one or the other, left unpaid, paid in part or paid in full in dollars.
 */
trait LedgerCurrencyOperations
{
    private function operationForeignSale(LedgerRandom $random): string
    {
        if (! $this->world->foreign) {
            return $this->operationSale($random);
        }

        $branchId   = $random->pick($this->world->branches);
        $location   = $random->pick($this->locationsOfBranch($branchId));
        $customerId = $random->pick(array_keys($this->world->customers));
        $itemId     = $random->pick(array_keys($this->world->items));
        $quantity   = (string) $random->int(1, 4);
        $price      = number_format($random->int(100, 50000) / 100, 2, '.', '');
        $date       = date('Y-m-d', strtotime('-' . $random->int(0, 38) . ' days'));
        $total      = bcmul($quantity, $price, 2);
        $paid       = $random->weighted(['none' => 40, 'part' => 30, 'full' => 30]);
        $payments   = match ($paid) {
            'none'  => [],
            'part'  => [[lang('Sales.cash') => ['payment_type' => lang('Sales.cash'), 'payment_amount' => bcmul($total, (string) ($random->int(10, 90) / 100), 2), 'cash_refund' => 0, 'cash_adjustment' => 0, 'reference_code' => null, 'treasury_id' => 0]][lang('Sales.cash')]],
            default => [['payment_type' => lang('Sales.cash'), 'payment_amount' => $total, 'cash_refund' => 0, 'cash_adjustment' => 0, 'reference_code' => null, 'treasury_id' => 0]],
        };
        $cost = (string) $this->db->table('items')->select('cost_price')->where('item_id', $itemId)->get()->getRow()->cost_price;

        $items = [[
            'item_id'       => $itemId,
            'line'          => 0,
            'description'   => 'Ledger',
            'serialnumber'  => '',
            'quantity'      => $quantity,
            'discount'      => '0',
            'discount_type' => PERCENT,
            'cost_price'    => $cost,
            'price'         => $price,
            'item_location' => $location,
            'print_option'  => 0,
        ]];

        $this->note(sprintf('USD invoice customer=%d branch=%d loc=%d i%dx%s@%s on %s paid=%s total=%s', $customerId, $branchId, $location, $itemId, $quantity, $price, $date, $paid, $total));

        $this->saleModel->useCurrency('USD');

        $status = COMPLETED;
        $taxes  = [[], []];
        $saleId = $this->saleModel->save_value(NEW_ENTRY, $status, $items, $customerId, $this->world->boss, 'ledger usd', null, null, null, SALE_TYPE_INVOICE, array_values($payments), null, $taxes, true, null, null, ReturnDocument::CreditNote, $date);

        $this->saleOutcome($saleId);

        $this->saleBook[$saleId] = [
            'customer'  => $customerId,
            'type'      => 'invoice',
            'total'     => bcadd($total, '0', 4),
            'status'    => 'completed',
            'branch'    => $branchId,
            'lines'     => [['item' => $itemId, 'location' => $location, 'quantity' => $quantity]],
            'origin'    => null,
            'date'      => $date,
            'delivered' => true,
        ];

        return sprintf('USD invoice %d customer=%d branch=%d loc=%d i%dx%s@%s on %s paid=%s total=%s', $saleId, $customerId, $branchId, $location, $itemId, $quantity, $price, $date, $paid, $total);
    }

    private function operationForeignPurchase(LedgerRandom $random): string
    {
        if (! $this->world->foreign) {
            return $this->operationPurchase($random);
        }

        $branchId   = $random->pick($this->world->branches);
        $location   = $random->pick($this->locationsOfBranch($branchId));
        $supplierId = $random->pick(array_keys($this->world->suppliers));
        $stock      = array_keys(array_filter($this->world->items, static fn (array $item): bool => $item['stock']));
        $itemId     = $random->pick($stock);
        $quantity   = (string) $random->int(1, 30);
        $price      = number_format($random->int(100, 20000) / 100, 2, '.', '');
        $date       = date('Y-m-d', strtotime('-' . $random->int(0, 38) . ' days'));
        $payment    = $random->chance(60) ? lang('Sales.due') : lang('Sales.cash');
        $lines      = [[
            'item_id'            => $itemId,
            'line'               => 0,
            'description'        => 'Ledger',
            'serialnumber'       => '',
            'quantity'           => $quantity,
            'receiving_quantity' => '1',
            'discount'           => '0',
            'discount_type'      => PERCENT,
            'price'              => $price,
            'item_location'      => $location,
            'tax_percent'        => '0',
        ]];

        $this->note(sprintf('USD purchase supplier=%d branch=%d loc=%d i%dx%s@%s on %s pay=%s', $supplierId, $branchId, $location, $itemId, $quantity, $price, $date, $payment === lang('Sales.due') ? 'due' : 'cash'));

        $this->purchaseModel->useCurrency('USD');

        $receivingId = $this->purchaseModel->save_value($lines, $supplierId, $this->world->boss, 'ledger usd', 'USD-' . $this->position, $payment, NEW_ENTRY, null, null, $date);

        $this->purchaseOutcome($receivingId);

        $this->purchaseBook[$receivingId] = [
            'supplier' => $supplierId,
            'type'     => 'receive',
            'status'   => 'completed',
            'branch'   => $branchId,
            'lines'    => [['item' => $itemId, 'location' => $location, 'quantity' => $quantity, 'price' => $price]],
            'origin'   => null,
        ];

        return sprintf('USD purchase %d supplier=%d branch=%d loc=%d i%dx%s@%s on %s pay=%s', $receivingId, $supplierId, $branchId, $location, $itemId, $quantity, $price, $date, $payment === lang('Sales.due') ? 'due' : 'cash');
    }
}
