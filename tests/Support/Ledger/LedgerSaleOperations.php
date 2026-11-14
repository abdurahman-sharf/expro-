<?php

namespace Tests\Support\Ledger;

use App\Libraries\Accounting\InvoiceRounding;
use App\Libraries\Accounting\ReturnDocument;
use App\Libraries\Sale_lib;
use App\Libraries\Tax_lib;

/**
 * Sales, as the register and the sale screens drive them: lines rung up on the real register with their
 * discounts and taxes, payments in the mixes a till sees, payments added or taken away later, returns by
 * credit note or by refund, and cancellations.
 */
trait LedgerSaleOperations
{
    /**
     * @var array<int, array{customer: int|null, type: string, total: string, status: string, branch: int, lines: list<array{item: int, location: int, quantity: string}>, origin: int|null, date: string, delivered?: bool}>
     */
    private array $saleBook = [];

    private function resetSales(): void
    {
        $this->saleBook = [];
    }

    private function operationSale(LedgerRandom $random): string
    {
        $branchId   = $random->pick($this->world->branches);
        $location   = $random->pick($this->locationsOfBranch($branchId));
        $isInvoice  = $random->chance(60);
        $customerId = $random->chance($isInvoice ? 8 : 25) ? null : $random->pick(array_keys($this->world->customers));
        $lines      = $this->saleLines($random, $location);
        $discount   = $random->chance(18) ? $this->invoiceDiscountOf($random) : null;
        $date       = $isInvoice && $random->chance(20) ? date('Y-m-d', strtotime('-' . $random->int(0, 70) . ' days')) : null;
        $delivered  = ! ($isInvoice && $random->chance(10));
        $actor      = $random->chance(15) ? $this->world->clerk : $this->world->boss;

        $register = $this->openRegister($isInvoice ? 'sale_invoice' : 'sale', $customerId);

        foreach ($lines as $line) {
            $itemId        = (string) $line['item'];
            $discountValue = $line['discount'];

            $added = $register->add_item($itemId, $location, $line['quantity'], $discountValue, $line['discount_type'], PRICE_MODE_STANDARD, null, null, $line['price']);

            if (! $added) {
                $this->refuse('register_refused_line', 'item ' . $line['item']);
            }
        }

        if ($discount !== null) {
            $register->setInvoiceDiscount($discount['type'], $discount['value']);
        }

        if ($date !== null) {
            $register->setInvoiceDate($date);
        }

        $cart  = $register->get_cart();
        $taxes = (new Tax_lib())->get_taxes($cart);
        $total = $register->get_totals($taxes[0]);

        $plan = $this->paymentPlan($random, $total['total'], $isInvoice, $customerId !== null);

        foreach ($plan as [$type, $amount]) {
            $register->addPayment($type, $amount);
        }

        $total    = $register->get_totals($taxes[0]);
        $payments = $register->getPayments();
        $change   = bcmul($total['amount_due'], '-1');

        if (! $isInvoice && ! $total['payments_cover_total']) {
            $register->addPayment(lang('Sales.cash'), bcadd($total['amount_due'], '0', 2));
            $total    = $register->get_totals($taxes[0]);
            $payments = $register->getPayments();
            $change   = bcmul($total['amount_due'], '-1');
        }

        if (bccomp($change, '0') > 0) {
            $change = bcadd($change, '0', 2);

            if (isset($payments[lang('Sales.cash')])) {
                $payments[lang('Sales.cash')]['cash_refund'] = $change;
            } else {
                $payments[lang('Sales.cash')] = ['payment_type' => lang('Sales.cash'), 'payment_amount' => 0, 'cash_refund' => $change, 'cash_adjustment' => CASH_ADJUSTMENT_FALSE, 'reference_code' => null, 'treasury_id' => 0];
            }
        }

        $this->note(sprintf('sale %s customer=%s branch=%d loc=%d lines=%s%s%s pay=%s total=%s%s', $isInvoice ? 'invoice' : 'pos', $customerId ?? 'walk-in', $branchId, $location, $this->describeLines($lines), $discount === null ? '' : ' invdisc=' . $discount['value'] . ($discount['type'] === PERCENT ? '%' : ''), $date === null ? '' : ' date=' . $date, $this->describePlan($plan), $total['total'], $delivered ? '' : ' undelivered'));

        $status = COMPLETED;
        $items  = $cart;
        $saleId = $this->saleModel->save_value(
            NEW_ENTRY,
            $status,
            $items,
            $customerId ?? NEW_ENTRY,
            $actor,
            'ledger',
            null,
            null,
            null,
            $isInvoice ? SALE_TYPE_INVOICE : SALE_TYPE_POS,
            $payments,
            null,
            $taxes,
            $delivered,
            null,
            $register->getInvoiceDiscount(),
            invoiceDate: $isInvoice ? $register->getInvoiceDate() : null,
        );

        $register->clear_all();

        $this->saleOutcome($saleId);

        $this->saleBook[$saleId] = [
            'customer'  => $customerId,
            'type'      => $isInvoice ? 'invoice' : 'pos',
            'total'     => bcadd($total['total'], '0', 4),
            'status'    => 'completed',
            'branch'    => $branchId,
            'lines'     => array_map(static fn (array $line): array => ['item' => $line['item'], 'location' => $location, 'quantity' => $line['quantity']], $lines),
            'origin'    => null,
            'date'      => $date ?? date('Y-m-d'),
            'delivered' => $delivered,
        ];

        $this->checkSaleAgainstRegister($saleId, $total['total']);

        return sprintf('sale %d %s customer=%s branch=%d loc=%d lines=%s%s%s pay=%s total=%s%s', $saleId, $isInvoice ? 'invoice' : 'pos', $customerId ?? 'walk-in', $branchId, $location, $this->describeLines($lines), $discount === null ? '' : ' invdisc=' . $discount['value'] . ($discount['type'] === PERCENT ? '%' : ''), $date === null ? '' : ' date=' . $date, $this->describePlan($plan), $total['total'], $delivered ? '' : ' undelivered');
    }

    private function operationSalePayment(LedgerRandom $random): string
    {
        $saleId = $this->pickSale($random, ['invoice']);

        if ($saleId === null) {
            return $this->operationSale($random);
        }

        $sale   = $this->saleBook[$saleId];
        $amount = $random->weighted(['partial' => 40, 'exact' => 25, 'excess' => 20, 'small' => 15]);
        $due    = $this->openAmountOfSale($saleId);
        $value  = match ($amount) {
            'partial' => bcmul($due, (string) ($random->int(10, 90) / 100), 2),
            'exact'   => bcadd($due, '0', 2),
            'excess'  => bcadd(bcadd($due, '0', 2), $random->money(30000), 2),
            default   => $random->money(2000),
        };

        if (bccomp($value, '0', 2) <= 0) {
            $value = '0.01';
        }

        $type  = $random->pick([lang('Sales.cash'), lang('Sales.debit'), lang('Sales.credit'), lang('Sales.bank_transfer'), lang('Sales.check')]);
        $actor = $random->chance(15) ? $this->world->clerk : $this->world->boss;

        $this->note(sprintf('payment on sale %d (%s) %s of %s open %s', $saleId, $sale['type'], $type, $value, $due));

        $saved = $this->saleModel->update($saleId, ['comment' => 'ledger payment', 'payments' => [
            ['payment_id' => NEW_ENTRY, 'payment_type' => $type, 'payment_amount' => $value, 'cash_refund' => 0, 'cash_adjustment' => 0, 'employee_id' => $actor, 'reference_code' => null],
        ]]);

        if (! $saved) {
            $this->refuseSale('update');
        }

        return sprintf('payment on sale %d (%s) %s of %s open %s', $saleId, $sale['type'], $type, $value, $due);
    }

    private function operationSalePaymentEdit(LedgerRandom $random): string
    {
        $rows = $this->db->query(
            "SELECT p.* FROM {$this->db->prefixTable('sales_payments')} p JOIN {$this->db->prefixTable('sales')} s ON s.sale_id = p.sale_id WHERE s.sale_status = 0 AND s.sale_type = 1 AND p.payment_status = 'completed' AND p.payment_type NOT LIKE ? ORDER BY p.payment_id",
            [lang('Sales.due') . '%'],
        )->getResultArray();

        if ($rows === []) {
            return $this->operationSalePayment($random);
        }

        $row    = $random->pick($rows);
        $saleId = (int) $row['sale_id'];
        $mode   = $random->weighted(['amount' => 40, 'remove' => 30, 'type' => 30]);
        $actor  = $random->chance(15) ? $this->world->clerk : $this->world->boss;
        $type   = (string) $row['payment_type'];
        $amount = bcadd((string) $row['payment_amount'], '0', 2);

        if ($mode === 'amount') {
            $amount = bcmul($amount, (string) ($random->int(20, 200) / 100), 2);

            if (bccomp($amount, (string) $row['cash_refund'], 2) < 0) {
                $amount = bcadd((string) $row['cash_refund'], '0.01', 2);
                $this->observe('F11-the-model-accepts-a-payment-edit-that-leaves-the-amount-below-the-change-given-and-the-run-keeps-the-amount-at-the-change', 'payment ' . $row['payment_id'] . ' of sale ' . $saleId);
            }
        } elseif ($mode === 'remove') {
            $amount = '0';
        } else {
            $type = $random->pick([lang('Sales.cash'), lang('Sales.debit'), lang('Sales.credit'), lang('Sales.bank_transfer')]);
        }

        $this->note(sprintf('%s payment %d of sale %d (%s %s) -> %s %s', $mode, $row['payment_id'], $saleId, $row['payment_type'], $row['payment_amount'], $type, $amount));

        $saved = $this->saleModel->update($saleId, ['comment' => 'ledger payment edit', 'payments' => [
            ['payment_id' => (int) $row['payment_id'], 'payment_type' => $type, 'payment_amount' => $amount, 'cash_refund' => $mode === 'remove' ? 0 : (string) $row['cash_refund'], 'cash_adjustment' => 0, 'employee_id' => $actor, 'reference_code' => null],
        ]]);

        if (! $saved) {
            $this->refuseSale('payment_edit');
        }

        return sprintf('%s payment %d of sale %d (%s %s) -> %s %s', $mode, $row['payment_id'], $saleId, $row['payment_type'], $row['payment_amount'], $type, $amount);
    }

    private function operationSaleReturn(LedgerRandom $random): string
    {
        $saleId = $this->pickSale($random, ['invoice', 'pos']);

        if ($saleId === null) {
            return $this->operationSale($random);
        }

        $sale     = $this->saleBook[$saleId];
        $stored   = $this->db->table('sales_items')->where('sale_id', $saleId)->orderBy('line')->get()->getResultArray();
        $document = $random->chance(25) ? ReturnDocument::RefundInvoice : ReturnDocument::CreditNote;
        $actor    = $random->chance(15) ? $this->world->clerk : $this->world->boss;
        $taken    = [];
        $register = $this->openRegister('return', $sale['customer']);

        foreach ($stored as $row) {
            if ($random->chance(35)) {
                continue;
            }

            $fraction = $random->pick(['1', '0.5', '0.25', '1', '0.333', '0.1']);
            $quantity = bcmul((string) $row['quantity_purchased'], $fraction, 3);

            if (bccomp($quantity, '0', 3) <= 0) {
                $quantity = (string) $row['quantity_purchased'];
            }

            $itemId        = (string) $row['item_id'];
            $discountValue = (string) $row['discount'];
            $added         = $register->add_item($itemId, (int) $row['item_location'], '-' . $quantity, $discountValue, (int) $row['discount_type'], PRICE_MODE_STANDARD, null, null, (string) $row['item_unit_price']);

            if (! $added) {
                continue;
            }

            $taken[] = $row['item_id'] . 'x' . $quantity;
        }

        if ($taken === []) {
            $register->clear_all();

            return 'return of sale ' . $saleId . ' skipped: no line taken';
        }

        $register->set_return_origin($saleId);
        $register->setReturnDocument($document);

        $cart  = $register->get_cart();
        $taxes = (new Tax_lib())->get_taxes($cart);
        $total = $register->get_totals($taxes[0]);

        $payments = [];
        $refund   = $document === ReturnDocument::RefundInvoice ? false : $random->chance(40);

        if ($refund && bccomp($total['total'], '0', 4) !== 0) {
            $payments[lang('Sales.cash')] = ['payment_type' => lang('Sales.cash'), 'payment_amount' => bcadd($total['total'], '0', 2), 'cash_refund' => 0, 'cash_adjustment' => 0, 'reference_code' => null];
        }

        $this->note(sprintf('return of sale %d %s items=%s%s total=%s', $saleId, $document->value, implode(',', $taken), $refund ? ' refunded' : '', $total['total']));

        $status   = COMPLETED;
        $items    = $cart;
        $returnId = $this->saleModel->save_value(NEW_ENTRY, $status, $items, $sale['customer'] ?? NEW_ENTRY, $actor, 'ledger return', null, null, null, SALE_TYPE_RETURN, $payments, null, $taxes, true, $saleId, null, $document);

        $register->clear_all();

        $this->saleOutcome($returnId);

        $this->saleBook[$returnId] = [
            'customer' => $sale['customer'],
            'type'     => 'return',
            'total'    => bcadd($total['total'], '0', 4),
            'status'   => 'completed',
            'branch'   => $sale['branch'],
            'lines'    => [],
            'origin'   => $saleId,
            'date'     => date('Y-m-d'),
        ];

        $this->checkSaleAgainstRegister($returnId, $total['total']);

        return sprintf('return %d of sale %d %s items=%s%s total=%s', $returnId, $saleId, $document->value, implode(',', $taken), $refund ? ' refunded' : '', $total['total']);
    }

    private function operationSaleDeliver(LedgerRandom $random): string
    {
        $candidates = array_keys(array_filter($this->saleBook, static fn (array $sale): bool => $sale['status'] === 'completed' && $sale['type'] === 'invoice' && ($sale['delivered'] ?? true) === false));

        $withRevenue = array_values(array_filter($candidates, fn (int $saleId): bool => $this->db->table('accounting_journals')->where(['source_type' => 'sale', 'source_id' => $saleId, 'entry_type' => 'revenue', 'status' => 'posted'])->countAllResults() > 0));

        if ($withRevenue !== [] || $candidates !== []) {
            foreach (array_diff($candidates, $withRevenue) as $saleId) {
                $this->observe('F9-delivery-of-an-invoice-with-no-revenue-journal-takes-stock-and-posts-no-cost-so-the-run-leaves-it-undelivered', 'sale ' . $saleId);
            }
        }

        if ($withRevenue === []) {
            return $this->operationSale($random);
        }

        $saleId = $random->pick($withRevenue);

        $this->note('confirm delivery of sale ' . $saleId);

        $result = $this->saleModel->confirm_delivery($saleId, $this->world->boss);

        if ($result <= 0) {
            $this->refuseSale('deliver', $result);
        }

        $this->saleBook[$saleId]['delivered'] = true;

        return 'confirm delivery of sale ' . $saleId;
    }

    private function operationSaleCancel(LedgerRandom $random): string
    {
        $saleId = $this->pickSale($random, ['invoice', 'pos', 'return']);

        if ($saleId === null) {
            return $this->operationSale($random);
        }

        $actor = $random->chance(15) ? $this->world->clerk : $this->world->boss;

        $this->note(sprintf('cancel sale %d (%s)', $saleId, $this->saleBook[$saleId]['type']));

        if (! $this->saleModel->delete($saleId, false, true, $actor)) {
            $this->refuseSale('delete');
        }

        $this->saleBook[$saleId]['status'] = 'cancelled';

        return sprintf('cancel sale %d (%s)', $saleId, $this->saleBook[$saleId]['type']);
    }

    private function openRegister(string $mode, ?int $customerId): Sale_lib
    {
        $register = new Sale_lib();
        $register->clear_all();
        $register->set_mode($mode);

        if ($customerId !== null) {
            $register->set_customer($customerId);
        }

        return $register;
    }

    /**
     * @return list<int>
     */
    private function locationsOfBranch(int $branchId): array
    {
        return array_keys(array_filter($this->world->locations, static fn (int $branch): bool => $branch === $branchId));
    }

    /**
     * @return list<array{item: int, quantity: string, price: string, discount: string, discount_type: int}>
     */
    private function saleLines(LedgerRandom $random, int $location): array
    {
        $onHand = [];

        foreach ($this->db->table('item_quantities')->where('location_id', $location)->where('quantity >', 0)->get()->getResultArray() as $row) {
            $onHand[(int) $row['item_id']] = (string) $row['quantity'];
        }

        $services = array_keys(array_filter($this->world->items, static fn (array $item): bool => ! $item['stock']));
        $all      = array_keys($this->world->items);
        $lines    = [];
        $used     = [];

        for ($index = $random->int(1, 4); $index > 0; $index--) {
            $pool   = $onHand !== [] && $random->chance(80) ? array_keys($onHand) : ($random->chance(30) ? $services : $all);
            $itemId = $random->pick($pool);

            if (isset($used[$itemId])) {
                continue;
            }

            $used[$itemId] = true;
            $quantity      = $random->quantity();

            if (isset($onHand[$itemId]) && $random->chance(85) && bccomp($quantity, $onHand[$itemId], 3) > 0) {
                $quantity = bcdiv($onHand[$itemId], (string) $random->int(1, 3), 3);

                if (bccomp($quantity, '0.001', 3) < 0) {
                    $quantity = '0.001';
                }
            }

            $price        = $random->chance(40) ? $this->world->items[$itemId]['price'] : $random->price();
            $discountKind = $random->weighted(['none' => 55, 'percent' => 25, 'fixed' => 20]);
            $discount     = '0';
            $type         = PERCENT;

            if ($discountKind === 'percent') {
                $discount = $random->percent();
            } elseif ($discountKind === 'fixed') {
                $type     = FIXED;
                $discount = bcmul($price, (string) ($random->int(1, 50) / 100), 2);
            }

            if (bccomp($discount, '0', 2) === 0) {
                $discount = '0';
                $type     = PERCENT;
            }

            $lines[] = ['item' => $itemId, 'quantity' => $quantity, 'price' => $price, 'discount' => $discount, 'discount_type' => $type];
        }

        return $lines === [] ? [['item' => $all[0], 'quantity' => '1', 'price' => $this->world->items[$all[0]]['price'], 'discount' => '0', 'discount_type' => PERCENT]] : $lines;
    }

    /**
     * @return array{type: int, value: string}
     */
    private function invoiceDiscountOf(LedgerRandom $random): array
    {
        return $random->chance(50)
            ? ['type' => PERCENT, 'value' => $random->percent()]
            : ['type' => FIXED, 'value' => $random->money(5000)];
    }

    /**
     * @return list<array{0: string, 1: string}> The way of payment and the amount, in the mixes a till sees.
     */
    private function paymentPlan(LedgerRandom $random, string $total, bool $isInvoice, bool $hasCustomer): array
    {
        $types = [lang('Sales.cash'), lang('Sales.debit'), lang('Sales.credit'), lang('Sales.bank_transfer'), lang('Sales.check')];
        $whole = InvoiceRounding::roundToCents($this->absolute($total));
        $whole = bcadd($whole, '0', 2);
        $mix   = $isInvoice
            ? $random->weighted(['none' => 25, 'partial' => 25, 'exact' => 25, 'over' => 10, 'split' => 15])
            : $random->weighted(['exact' => 55, 'over' => 25, 'split' => 20]);

        if (bccomp($whole, '0', 2) <= 0) {
            return [];
        }

        if (! $hasCustomer && $isInvoice && in_array($mix, ['none', 'partial'], true)) {
            $mix = 'exact';
        }

        return match ($mix) {
            'none'    => [],
            'partial' => [[$random->pick($types), bcmul($whole, (string) ($random->int(5, 95) / 100), 2)]],
            'exact'   => [[$random->pick($types), $whole]],
            'over'    => [[lang('Sales.cash'), bcadd($whole, (string) $random->int(1, 50), 2)]],
            default   => $this->splitPlan($random, $types, $whole),
        };
    }

    /**
     * @param list<string> $types
     *
     * @return list<array{0: string, 1: string}>
     */
    private function splitPlan(LedgerRandom $random, array $types, string $whole): array
    {
        $first = bcmul($whole, (string) ($random->int(10, 90) / 100), 2);

        return [[$random->pick($types), $first], [lang('Sales.cash'), bcsub($whole, $first, 2)]];
    }

    /**
     * @param list<array{0: string, 1: string}> $plan
     */
    private function describePlan(array $plan): string
    {
        return $plan === [] ? 'none' : implode('+', array_map(static fn (array $part): string => $part[0] . ':' . $part[1], $plan));
    }

    /**
     * @param list<array{item: int, quantity: string, price: string, discount: string, discount_type: int}> $lines
     */
    private function describeLines(array $lines): string
    {
        return '[' . implode(' ', array_map(static fn (array $line): string => 'i' . $line['item'] . 'x' . $line['quantity'] . '@' . $line['price'] . ($line['discount'] === '0' ? '' : '-' . $line['discount'] . ($line['discount_type'] === PERCENT ? '%' : 'f')), $lines)) . ']';
    }

    private function pickSale(LedgerRandom $random, array $types): ?int
    {
        $candidates = array_keys(array_filter($this->saleBook, static fn (array $sale): bool => $sale['status'] === 'completed' && in_array($sale['type'], $types, true)));

        return $candidates === [] ? null : $random->pick($candidates);
    }

    private function openAmountOfSale(int $saleId): string
    {
        $sale = $this->saleBook[$saleId];
        $paid = $this->db->query(
            "SELECT COALESCE(SUM(payment_amount - cash_refund), 0) AS paid FROM {$this->db->prefixTable('sales_payments')} WHERE sale_id = ? AND payment_status = 'completed' AND payment_type NOT LIKE ?",
            [$saleId, lang('Sales.due') . '%'],
        )->getRow()->paid;

        $open = bcsub(bcadd($sale['total'], '0', 2), (string) $paid, 2);

        return bccomp($open, '0', 2) < 0 ? '0.00' : $open;
    }

    private function saleOutcome(int $saleId): void
    {
        if ($saleId > 0) {
            return;
        }

        $this->refuseSale('save', $saleId);
    }

    private function refuseSale(string $what, int $code = 0): never
    {
        $failure = $this->saleModel->accountingFailure();

        if ($failure !== null) {
            $domain = $this->refusalOf($failure);

            if ($domain !== null) {
                throw $domain;
            }

            $this->refuse('failure', $failure::class . ': ' . $failure->getMessage());
        }

        $this->refuse('sale_' . $what . '_' . ($code === 0 ? 'false' : (string) $code));
    }

    private function checkSaleAgainstRegister(int $saleId, string $registerTotal): void
    {
        if ($this->db->table('sales')->where('sale_id', $saleId)->where('currency_code IS NOT NULL', null, false)->countAllResults() > 0) {
            return;
        }

        $posted = $this->db->query(
            "SELECT COALESCE(SUM(total_debit), 0) AS total FROM {$this->db->prefixTable('accounting_journals')} WHERE source_type = 'sale' AND source_id = ? AND entry_type = 'revenue' AND status = 'posted'",
            [$saleId],
        )->getRow();

        $shown = InvoiceRounding::roundToCents($this->absolute($registerTotal));

        if (bccomp(bcadd((string) $posted->total, '0', 4), $shown, 4) === 0) {
            return;
        }

        $exact = InvoiceRounding::roundToCents((new LedgerInvariants())->documentTotalOfSale($saleId));

        if (bccomp((string) $posted->total, '0', 4) === 0 && bccomp($exact, '0.02', 4) < 0) {
            $this->observe('F3-sub-cent-document-posts-nothing', sprintf('sale %d: the register total %s shows %s and the books post no revenue journal', $saleId, $registerTotal, $shown));

            return;
        }

        $this->expect(
            bccomp(bcadd((string) $posted->total, '0', 4), $exact, 4) === 0,
            sprintf('sale %d: the register asked the customer for %s (%s to the cent) and the books posted %s, and its saved lines come to %s%s', $saleId, $registerTotal, $shown, $posted->total, $exact, "\n" . $this->dumpSale($saleId)),
        );

        $this->observe('F2-register-total-truncated', sprintf('sale %d: the register total %s shows %s, the books and the saved lines say %s', $saleId, $registerTotal, $shown, $exact));
    }

    private function dumpSale(int $saleId): string
    {
        $rows = [];

        foreach ($this->db->table('sales_items')->where('sale_id', $saleId)->orderBy('line')->get()->getResultArray() as $line) {
            $rows[] = sprintf('  line %d item %d qty %s price %s cost %s discount %s/%d share %s', $line['line'], $line['item_id'], $line['quantity_purchased'], $line['item_unit_price'], $line['item_cost_price'], $line['discount'], $line['discount_type'], $line['invoice_discount_share'] ?? 'null');
        }

        foreach ($this->db->table('sales_items_taxes')->where('sale_id', $saleId)->orderBy('line')->get()->getResultArray() as $tax) {
            $rows[] = sprintf('  tax line %d %s %s%% type %d amount %s', $tax['line'], $tax['name'], $tax['percent'], $tax['tax_type'], $tax['item_tax_amount']);
        }

        $sale   = $this->db->table('sales')->where('sale_id', $saleId)->get()->getRowArray();
        $rows[] = sprintf('  sale type %s status %s origin %s document %s invoice_discount %s/%s', $sale['sale_type'], $sale['sale_status'], $sale['return_of_sale_id'] ?? 'null', $sale['return_document'] ?? 'null', $sale['invoice_discount_type'] ?? 'null', $sale['invoice_discount'] ?? 'null');

        foreach ($this->db->query(
            "SELECT j.journal_id, j.entry_type, j.source_type, j.status, l.account_id, a.system_key, a.entity_type, l.debit, l.credit FROM {$this->db->prefixTable('accounting_journals')} j JOIN {$this->db->prefixTable('accounting_journal_lines')} l ON l.journal_id = j.journal_id JOIN {$this->db->prefixTable('accounting_accounts')} a ON a.account_id = l.account_id WHERE j.source_type = 'sale' AND j.source_id = ? ORDER BY j.journal_id, l.line_no",
            [$saleId],
        )->getResultArray() as $line) {
            $rows[] = sprintf('  journal %d %s %s: %s %s debit %s credit %s', $line['journal_id'], $line['entry_type'], $line['status'], $line['system_key'] ?? $line['entity_type'], $line['account_id'], $line['debit'], $line['credit']);
        }

        return implode("\n", $rows);
    }

    private function absolute(string $number): string
    {
        return str_starts_with($number, '-') ? substr($number, 1) : $number;
    }
}
