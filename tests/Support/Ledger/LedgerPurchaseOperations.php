<?php

namespace Tests\Support\Ledger;

/**
 * Purchases, as the receiving screens save them: goods bought on credit or paid for, payments added later,
 * returns of what was bought, and cancellations.
 */
trait LedgerPurchaseOperations
{
    /** @var array<int, array{supplier: int, type: string, status: string, branch: int, lines: list<array{item: int, location: int, quantity: string, price: string}>, origin: int|null}> */
    private array $purchaseBook = [];

    private function resetPurchases(): void
    {
        $this->purchaseBook = [];
    }

    private function operationPurchase(LedgerRandom $random): string
    {
        $branchId   = $random->pick($this->world->branches);
        $location   = $random->pick($this->locationsOfBranch($branchId));
        $supplierId = $random->pick(array_keys($this->world->suppliers));
        $stock      = array_keys(array_filter($this->world->items, static fn (array $item): bool => $item['stock']));
        $services   = array_keys(array_filter($this->world->items, static fn (array $item): bool => ! $item['stock']));
        $lines      = [];
        $used       = [];

        for ($index = $random->int(1, 3); $index > 0; $index--) {
            $itemId = $random->chance(88) ? $random->pick($stock) : $random->pick($services);

            if (isset($used[$itemId])) {
                continue;
            }

            $used[$itemId] = true;
            $number        = count($lines);
            $discountKind  = $random->weighted(['none' => 65, 'percent' => 20, 'fixed' => 15]);
            $price         = $random->chance(60) ? bcmul($this->world->items[$itemId]['cost'] === '0.00' ? '1.00' : $this->world->items[$itemId]['cost'], (string) ($random->int(70, 140) / 100), 2) : $random->price();
            $quantity      = $random->chance(60) ? (string) $random->int(1, 40) : $random->quantity();
            $discount      = '0';
            $type          = PERCENT;

            if ($discountKind === 'percent') {
                $discount = $random->percent();
            } elseif ($discountKind === 'fixed') {
                $discount = bcmul($price, (string) ($random->int(1, 40) / 100), 2);
                $type     = FIXED;
            }

            if (bccomp($price, '0', 2) <= 0) {
                $price = '1.00';
            }

            $lines[] = [
                'item_id'            => $itemId,
                'line'               => $number,
                'description'        => 'Ledger',
                'serialnumber'       => '',
                'quantity'           => $quantity,
                'receiving_quantity' => '1',
                'discount'           => $discount,
                'discount_type'      => $type,
                'price'              => $price,
                'item_location'      => $location,
                'tax_percent'        => $random->pick(['0', '0', '15', '5', '7.5']),
            ];
        }

        if ($lines === []) {
            $this->refuse('generator_empty');
        }

        $payment = $random->weighted(['due' => 45, 'cash' => 25, 'debit' => 15, 'bank_transfer' => 15]);
        $label   = lang('Sales.' . $payment);
        $date    = $random->chance(15) ? date('Y-m-d', strtotime('-' . $random->int(0, 60) . ' days')) : null;
        $actor   = $this->world->boss;

        $this->note(sprintf('purchase supplier=%d branch=%d loc=%d pay=%s%s lines=[%s]', $supplierId, $branchId, $location, $payment, $date === null ? '' : ' date=' . $date, $this->describePurchaseLines($lines)));

        $receivingId = $this->purchaseModel->save_value($lines, $supplierId, $actor, 'ledger', 'REF-' . $this->position, $label, NEW_ENTRY, null, null, $date);

        $this->purchaseOutcome($receivingId);

        $this->purchaseBook[$receivingId] = [
            'supplier' => $supplierId,
            'type'     => 'receive',
            'status'   => 'completed',
            'branch'   => $branchId,
            'lines'    => array_map(static fn (array $line): array => ['item' => $line['item_id'], 'location' => $line['item_location'], 'quantity' => $line['quantity'], 'price' => $line['price']], $lines),
            'origin'   => null,
        ];

        return sprintf('purchase %d supplier=%d branch=%d loc=%d pay=%s%s lines=[%s]', $receivingId, $supplierId, $branchId, $location, $payment, $date === null ? '' : ' date=' . $date, $this->describePurchaseLines($lines));
    }

    /**
     * @param list<array<string, mixed>> $lines
     */
    private function describePurchaseLines(array $lines): string
    {
        return implode(' ', array_map(static fn (array $line): string => 'i' . $line['item_id'] . 'x' . $line['quantity'] . '@' . $line['price'] . ($line['discount'] === '0' ? '' : '-' . $line['discount'] . ($line['discount_type'] === PERCENT ? '%' : 'f')) . ($line['tax_percent'] === '0' ? '' : '+' . $line['tax_percent'] . 't'), $lines));
    }

    private function operationPurchasePayment(LedgerRandom $random): string
    {
        $receivingId = $this->pickPurchase($random, ['receive']);

        if ($receivingId === null) {
            return $this->operationPurchase($random);
        }

        $total = $this->purchaseTotal($receivingId);
        $paid  = $this->purchasePaid($receivingId);
        $open  = bcsub($total, $paid, 2);
        $kind  = $random->weighted(['partial' => 40, 'exact' => 30, 'excess' => 15, 'small' => 15]);
        $value = match ($kind) {
            'partial' => bcmul(bccomp($open, '0', 2) > 0 ? $open : $total, (string) ($random->int(10, 90) / 100), 2),
            'exact'   => bccomp($open, '0', 2) > 0 ? $open : '0.01',
            'excess'  => bcadd(bccomp($open, '0', 2) > 0 ? $open : '0', $random->money(20000), 2),
            default   => $random->money(1500),
        };

        if (bccomp($value, '0', 2) <= 0) {
            $value = '0.01';
        }

        $type = $random->pick([lang('Sales.cash'), lang('Sales.debit'), lang('Sales.bank_transfer')]);

        $this->note(sprintf('payment on purchase %d %s of %s (total %s paid %s)', $receivingId, $type, $value, $total, $paid));

        $saved = $this->purchaseModel->update($receivingId, ['comment' => 'ledger payment', 'payments' => [
            ['payment_id' => NEW_ENTRY, 'payment_type' => $type, 'payment_amount' => $value, 'employee_id' => $this->world->boss, 'payment_status' => 'completed', 'reference_code' => ''],
        ]]);

        if (! $saved) {
            $this->refusePurchase('update');
        }

        return sprintf('payment on purchase %d %s of %s (total %s paid %s)', $receivingId, $type, $value, $total, $paid);
    }

    private function operationPurchasePaymentEdit(LedgerRandom $random): string
    {
        $rows = $this->db->query(
            "SELECT p.* FROM {$this->db->prefixTable('receivings_payments')} p JOIN {$this->db->prefixTable('receivings')} r ON r.receiving_id = p.receiving_id WHERE p.payment_status = 'completed' AND r.receiving_type = 'receive' ORDER BY p.payment_id",
        )->getResultArray();

        if ($rows === []) {
            return $this->operationPurchasePayment($random);
        }

        $row         = $random->pick($rows);
        $receivingId = (int) $row['receiving_id'];
        $mode        = $random->weighted(['amount' => 45, 'remove' => 30, 'type' => 25]);
        $type        = (string) $row['payment_type'];
        $amount      = bcadd((string) $row['payment_amount'], '0', 2);

        if ($mode === 'amount') {
            $amount = bcmul($amount, (string) ($random->int(20, 200) / 100), 2);
        } elseif ($mode === 'remove') {
            $amount = '0';
        } else {
            $type = $random->pick([lang('Sales.cash'), lang('Sales.debit'), lang('Sales.bank_transfer')]);
        }

        $this->note(sprintf('%s payment %d of purchase %d (%s %s) -> %s %s', $mode, $row['payment_id'], $receivingId, $row['payment_type'], $row['payment_amount'], $type, $amount));

        $saved = $this->purchaseModel->update($receivingId, ['comment' => 'ledger payment edit', 'payments' => [
            ['payment_id' => (int) $row['payment_id'], 'payment_type' => $type, 'payment_amount' => $amount, 'payment_status' => 'completed', 'employee_id' => $this->world->boss, 'reference_code' => ''],
        ]]);

        if (! $saved) {
            $this->refusePurchase('payment_edit');
        }

        return sprintf('%s payment %d of purchase %d (%s %s) -> %s %s', $mode, $row['payment_id'], $receivingId, $row['payment_type'], $row['payment_amount'], $type, $amount);
    }

    private function operationPurchaseReturn(LedgerRandom $random): string
    {
        $receivingId = $this->pickPurchase($random, ['receive']);

        if ($receivingId === null) {
            return $this->operationPurchase($random);
        }

        $purchase = $this->purchaseBook[$receivingId];
        $stored   = $this->db->table('receivings_items')->where('receiving_id', $receivingId)->orderBy('line')->get()->getResultArray();
        $lines    = [];

        foreach ($stored as $row) {
            if ($random->chance(35)) {
                continue;
            }

            $quantity = bcmul((string) $row['quantity_purchased'], $random->pick(['1', '0.5', '0.25', '0.333', '0.1', '1']), 3);

            if (bccomp($quantity, '0', 3) <= 0) {
                $quantity = (string) $row['quantity_purchased'];
            }

            $shelf = $this->db->table('item_quantities')->where(['item_id' => (int) $row['item_id'], 'location_id' => (int) $row['item_location']])->get()->getRow();
            $held  = $shelf === null ? '0' : (string) $shelf->quantity;

            if ($this->world->items[(int) $row['item_id']]['stock'] && bccomp($quantity, $held, 3) > 0) {
                $quantity = $held;
            }

            if (bccomp($quantity, '0', 3) <= 0) {
                continue;
            }

            $lines[] = [
                'item_id'            => (int) $row['item_id'],
                'line'               => count($lines),
                'description'        => 'Ledger',
                'serialnumber'       => '',
                'quantity'           => '-' . $quantity,
                'receiving_quantity' => (string) $row['receiving_quantity'],
                'discount'           => (string) $row['discount'],
                'discount_type'      => (int) $row['discount_type'],
                'price'              => (string) $row['item_unit_price'],
                'item_location'      => (int) $row['item_location'],
                'tax_percent'        => (string) $row['tax_percent'],
            ];
        }

        if ($lines === []) {
            return 'return of purchase ' . $receivingId . ' skipped: no line taken';
        }

        $label = lang('Sales.' . $random->pick(['due', 'cash']));

        $this->note(sprintf('return of purchase %d pay=%s items=[%s]', $receivingId, $label === lang('Sales.due') ? 'due' : 'cash', $this->describePurchaseLines($lines)));

        $returnId = $this->purchaseModel->save_value($lines, $purchase['supplier'], $this->world->boss, 'ledger return', 'RET-' . $this->position, $label, NEW_ENTRY, $receivingId);

        $this->purchaseOutcome($returnId);

        $this->purchaseBook[$returnId] = ['supplier' => $purchase['supplier'], 'type' => 'return', 'status' => 'completed', 'branch' => $purchase['branch'], 'lines' => [], 'origin' => $receivingId];

        return sprintf('return %d of purchase %d pay=%s items=[%s]', $returnId, $receivingId, $label === lang('Sales.due') ? 'due' : 'cash', implode(' ', array_map(static fn (array $line): string => 'i' . $line['item_id'] . 'x' . $line['quantity'], $lines)));
    }

    private function operationPurchaseCancel(LedgerRandom $random): string
    {
        $receivingId = $this->pickPurchase($random, ['receive', 'return']);

        if ($receivingId === null) {
            return $this->operationPurchase($random);
        }

        $this->note(sprintf('cancel purchase %d (%s)', $receivingId, $this->purchaseBook[$receivingId]['type']));

        if (! $this->purchaseModel->delete_value($receivingId, $this->world->boss)) {
            $this->refusePurchase('delete');
        }

        $this->purchaseBook[$receivingId]['status'] = 'cancelled';

        return sprintf('cancel purchase %d (%s)', $receivingId, $this->purchaseBook[$receivingId]['type']);
    }

    private function pickPurchase(LedgerRandom $random, array $types): ?int
    {
        $candidates = array_keys(array_filter($this->purchaseBook, static fn (array $purchase): bool => $purchase['status'] === 'completed' && in_array($purchase['type'], $types, true)));

        return $candidates === [] ? null : $random->pick($candidates);
    }

    private function purchaseTotal(int $receivingId): string
    {
        $row = $this->db->query(
            "SELECT COALESCE(SUM(j.total_debit), 0) AS total FROM {$this->db->prefixTable('accounting_journals')} j WHERE j.source_type = 'purchase' AND j.source_id = ? AND j.status = 'posted'",
            [$receivingId],
        )->getRow();

        return bcadd((string) $row->total, '0', 2);
    }

    private function purchasePaid(int $receivingId): string
    {
        $row = $this->db->query("SELECT COALESCE(SUM(payment_amount), 0) AS paid FROM {$this->db->prefixTable('receivings_payments')} WHERE receiving_id = ? AND payment_status = 'completed'", [$receivingId])->getRow();

        return bcadd((string) $row->paid, '0', 2);
    }

    private function purchaseOutcome(int $receivingId): void
    {
        if ($receivingId > 0) {
            return;
        }

        $this->refusePurchase('save', $receivingId);
    }

    private function refusePurchase(string $what, int $code = 0): never
    {
        $failure = $this->purchaseModel->accountingFailure();

        if ($failure !== null) {
            $domain = $this->refusalOf($failure);

            if ($domain !== null) {
                throw $domain;
            }

            $this->refuse('failure', $failure::class . ': ' . $failure->getMessage());
        }

        $this->refuse('purchase_' . $what . '_' . ($code === 0 ? 'false' : (string) $code));
    }
}
