<?php

namespace Tests\Support\Ledger;

use App\Libraries\Accounting\ChequeService;
use App\Libraries\Accounting\CreditRefunds;
use App\Libraries\Accounting\CreditSettlement;
use App\Libraries\Accounting\CustomerAdvances;
use App\Libraries\Accounting\RevenueVouchers;
use App\Libraries\Accounting\SupplierAdvances;
use App\Libraries\Accounting\SupplierSettlement;
use App\Libraries\Accounting\TreasuryTransfers;
use App\Models\Cashup;
use App\Models\Expense;

/**
 * Money that moves outside a sale or a purchase: advances and refunds, expenses, cash-ups, receipt vouchers,
 * transfers between treasuries and cheques.
 */
trait LedgerMoneyOperations
{
    /**
     * @var array<int, array{customer: int, status: string}>
     */
    private array $advanceBook = [];

    /**
     * @var array<int, array{supplier: int, status: string}>
     */
    private array $supplierAdvanceBook = [];

    /**
     * @var array<int, array{party: string, status: string}>
     */
    private array $refundBook = [];

    /**
     * @var array<int, string>
     */
    private array $expenseBook = [];

    /**
     * @var array<int, string>
     */
    private array $voucherBook = [];

    /**
     * @var array<int, string>
     */
    private array $transferBook = [];

    /**
     * @var array<int, array{status: string, direction: string}>
     */
    private array $chequeBook = [];

    private function resetMoney(): void
    {
        $this->advanceBook         = [];
        $this->supplierAdvanceBook = [];
        $this->refundBook          = [];
        $this->expenseBook         = [];
        $this->voucherBook         = [];
        $this->transferBook        = [];
        $this->chequeBook          = [];
    }

    private function operationCustomerAdvance(LedgerRandom $random): string
    {
        $customerId = $random->pick(array_keys($this->world->customers));
        $amount     = $random->money(300000);
        $type       = $random->pick(['Cash', 'Cash', lang('Sales.debit')]);
        $date       = date('Y-m-d', strtotime('-' . $random->int(0, 40) . ' days'));

        $this->note(sprintf('customer advance customer=%d %s of %s on %s', $customerId, $type, $amount, $date));

        $advanceId = (new CustomerAdvances())->receive($customerId, $amount, $date, $type, null, '', '', $this->world->boss, 'ledger-' . $this->seed . '-' . $this->position);

        $this->advanceBook[$advanceId] = ['customer' => $customerId, 'status' => 'completed'];

        return sprintf('customer advance %d customer=%d %s of %s on %s', $advanceId, $customerId, $type, $amount, $date);
    }

    private function operationCustomerAdvanceCancel(LedgerRandom $random): string
    {
        $candidates = array_keys(array_filter($this->advanceBook, static fn (array $advance): bool => $advance['status'] === 'completed'));

        if ($candidates === []) {
            return $this->operationCustomerAdvance($random);
        }

        $advanceId = $random->pick($candidates);

        $this->note('cancel customer advance ' . $advanceId);

        (new CustomerAdvances())->cancel($advanceId, 'ledger', $this->world->boss);

        $this->advanceBook[$advanceId]['status'] = 'cancelled';

        return 'cancel customer advance ' . $advanceId;
    }

    private function operationCustomerRefund(LedgerRandom $random): string
    {
        $customerId = $random->pick(array_keys($this->world->customers));
        $refunds    = new CreditRefunds(CreditRefunds::CUSTOMER);
        $available  = $refunds->available($customerId);

        if (bccomp($available, '0', 2) <= 0) {
            return $this->operationCustomerAdvance($random);
        }

        $amount = $random->chance(60) ? bcmul($available, (string) ($random->int(10, 100) / 100), 2) : bcadd($available, '0.01', 2);

        if (bccomp($amount, '0', 2) <= 0) {
            $amount = '0.01';
        }

        $this->note(sprintf('customer refund customer=%d of %s (available %s)', $customerId, $amount, $available));

        $refundId = $refunds->refund($customerId, $amount, date('Y-m-d'), 'Cash', null, '', '', $this->world->boss, 'ledger-refund-' . $this->seed . '-' . $this->position);

        $this->refundBook[$refundId] = ['party' => CreditRefunds::CUSTOMER, 'status' => 'completed'];

        return sprintf('customer refund %d customer=%d of %s (available %s)', $refundId, $customerId, $amount, $available);
    }

    private function operationSupplierAdvance(LedgerRandom $random): string
    {
        $supplierId = $random->pick(array_keys($this->world->suppliers));
        $amount     = $random->money(300000);
        $date       = date('Y-m-d', strtotime('-' . $random->int(0, 40) . ' days'));

        $this->note(sprintf('supplier advance supplier=%d of %s on %s', $supplierId, $amount, $date));

        $advanceId = (new SupplierAdvances())->pay($supplierId, $amount, $date, 'Cash', null, '', '', $this->world->boss);

        $this->supplierAdvanceBook[$advanceId] = ['supplier' => $supplierId, 'status' => 'completed'];

        return sprintf('supplier advance %d supplier=%d of %s on %s', $advanceId, $supplierId, $amount, $date);
    }

    private function operationSupplierAdvanceCancel(LedgerRandom $random): string
    {
        $candidates = array_keys(array_filter($this->supplierAdvanceBook, static fn (array $advance): bool => $advance['status'] === 'completed'));

        if ($candidates === []) {
            return $this->operationSupplierAdvance($random);
        }

        $advanceId = $random->pick($candidates);

        $this->note('cancel supplier advance ' . $advanceId);

        (new SupplierAdvances())->cancel($advanceId, 'ledger', $this->world->boss);

        $this->supplierAdvanceBook[$advanceId]['status'] = 'cancelled';

        return 'cancel supplier advance ' . $advanceId;
    }

    private function operationSupplierRefund(LedgerRandom $random): string
    {
        $supplierId = $random->pick(array_keys($this->world->suppliers));
        $refunds    = new CreditRefunds(CreditRefunds::SUPPLIER);
        $available  = $refunds->available($supplierId);

        if (bccomp($available, '0', 2) <= 0) {
            return $this->operationSupplierAdvance($random);
        }

        $amount = bcmul($available, (string) ($random->int(10, 100) / 100), 2);

        if (bccomp($amount, '0', 2) <= 0) {
            $amount = '0.01';
        }

        $this->note(sprintf('supplier refund supplier=%d of %s (available %s)', $supplierId, $amount, $available));

        $refundId = $refunds->refund($supplierId, $amount, date('Y-m-d'), 'Cash', null, '', '', $this->world->boss, 'ledger-srefund-' . $this->seed . '-' . $this->position);

        $this->refundBook[$refundId] = ['party' => CreditRefunds::SUPPLIER, 'status' => 'completed'];

        return sprintf('supplier refund %d supplier=%d of %s (available %s)', $refundId, $supplierId, $amount, $available);
    }

    private function operationSettle(LedgerRandom $random): string
    {
        $customerId = $random->pick(array_keys($this->world->customers));
        $supplierId = $random->pick(array_keys($this->world->suppliers));

        $this->note(sprintf('settle customer=%d supplier=%d', $customerId, $supplierId));

        $before = $this->countApplications();

        (new CreditSettlement())->settle($customerId, $this->world->boss);
        (new SupplierSettlement())->settle($supplierId, $this->world->boss);

        $once = $this->countApplications();

        (new CreditSettlement())->settle($customerId, $this->world->boss);
        (new SupplierSettlement())->settle($supplierId, $this->world->boss);

        $this->expect($this->countApplications() === $once, sprintf('settling customer %d and supplier %d a second time changed the credit applications again (%s)', $customerId, $supplierId, $once));

        return sprintf('settle customer=%d supplier=%d applications %s -> %s', $customerId, $supplierId, $before, $once);
    }

    private function countApplications(): string
    {
        $customer = $this->db->query('SELECT COUNT(*) AS found, COALESCE(SUM(amount), 0) AS total FROM ' . $this->db->prefixTable('customer_credit_applications') . ' WHERE released_at IS NULL')->getRow();
        $supplier = $this->db->query('SELECT COUNT(*) AS found, COALESCE(SUM(amount), 0) AS total FROM ' . $this->db->prefixTable('supplier_credit_applications'))->getRow();

        return $customer->found . ':' . $customer->total . '/' . $supplier->found . ':' . $supplier->total;
    }

    private function operationExpense(LedgerRandom $random): string
    {
        $amount = $random->money(200000);
        $tax    = $random->chance(40) ? bcmul($amount, (string) ($random->pick([15, 5, 7.5]) / 100 / 1.15), 2) : '0';
        $date   = date('Y-m-d', strtotime('-' . $random->int(0, 50) . ' days')) . ' 10:00:00';
        $branch = $random->pick($this->world->branches);

        $data = [
            'date'                => $date,
            'supplier_id'         => null,
            'supplier_tax_code'   => '',
            'amount'              => $amount,
            'tax_amount'          => $tax,
            'payment_type'        => $random->pick(['Cash', 'Cash', lang('Sales.debit')]),
            'expense_category_id' => $this->world->expenseCategory,
            'description'         => 'ledger expense',
            'employee_id'         => $this->world->boss,
            'deleted'             => 0,
            'branch_id'           => $branch,
        ];

        $this->note(sprintf('expense branch=%d amount=%s tax=%s on %s', $branch, $amount, $tax, $date));

        $saved = model(Expense::class)->save_value($data, NEW_ENTRY, $this->world->boss);

        if (! $saved) {
            $failure = model(Expense::class)->accountingFailure();
            $domain  = $failure === null ? null : $this->refusalOf($failure);

            if ($domain !== null) {
                throw $domain;
            }

            $this->refuse('expense_false', $failure === null ? '' : $failure->getMessage());
        }

        $expenseId = (int) $data['expense_id'];

        $this->expenseBook[$expenseId] = 'completed';

        return sprintf('expense %d branch=%d amount=%s tax=%s on %s', $expenseId, $branch, $amount, $tax, $date);
    }

    private function operationExpenseDelete(LedgerRandom $random): string
    {
        $candidates = array_keys(array_filter($this->expenseBook, static fn (string $status): bool => $status === 'completed'));

        if ($candidates === []) {
            return $this->operationExpense($random);
        }

        $expenseId = $random->pick($candidates);

        $this->note('delete expense ' . $expenseId);

        if (! model(Expense::class)->delete_list([$expenseId], $this->world->boss)) {
            $failure = model(Expense::class)->accountingFailure();
            $domain  = $failure === null ? null : $this->refusalOf($failure);

            if ($domain !== null) {
                throw $domain;
            }

            $this->refuse('expense_delete_false');
        }

        $this->expenseBook[$expenseId] = 'deleted';

        return 'delete expense ' . $expenseId;
    }

    private function operationCashup(LedgerRandom $random): string
    {
        $day     = date('Y-m-d', strtotime('-' . $random->int(0, 30) . ' days'));
        $open    = $random->money(50000);
        $counted = bcadd($open, $random->money(500000), 2);
        $data    = [
            'open_date'            => $day . ' 08:00:00',
            'close_date'           => $day . ' 18:00:00',
            'open_amount_cash'     => $open,
            'transfer_amount_cash' => '0',
            'closed_amount_cash'   => $counted,
            'closed_amount_due'    => '0',
            'closed_amount_card'   => '0',
            'closed_amount_check'  => '0',
            'closed_amount_total'  => bcsub($counted, $open, 2),
            'note'                 => 0,
            'description'          => 'ledger cash-up',
            'open_employee_id'     => $this->world->boss,
            'close_employee_id'    => $this->world->boss,
            'deleted'              => 0,
        ];

        $this->note(sprintf('cash-up on %s open %s counted %s', $day, $open, $counted));

        if (! model(Cashup::class)->save_value($data, NEW_ENTRY, $this->world->boss)) {
            $failure = model(Cashup::class)->accountingFailure();
            $domain  = $failure === null ? null : $this->refusalOf($failure);

            if ($domain !== null) {
                throw $domain;
            }

            $this->refuse('cashup_false');
        }

        return sprintf('cash-up %d on %s open %s counted %s', (int) $data['cashup_id'], $day, $open, $counted);
    }

    private function operationVoucher(LedgerRandom $random): string
    {
        $amount = $random->money(200000);
        $tax    = $random->chance(40) ? bcmul($amount, '0.1304', 2) : '0';
        $date   = date('Y-m-d', strtotime('-' . $random->int(0, 50) . ' days'));

        $input = ['date' => $date, 'amount' => $amount, 'tax_amount' => $tax, 'payment_type' => 'Cash', 'account_id' => $this->world->revenueAccount, 'description' => 'ledger voucher'];

        $this->note(sprintf('voucher amount=%s tax=%s on %s', $amount, $tax, $date));

        $revenueId = (new RevenueVouchers())->save($input, null, $this->world->boss);

        $this->voucherBook[$revenueId] = 'completed';

        return sprintf('voucher %d amount=%s tax=%s on %s', $revenueId, $amount, $tax, $date);
    }

    private function operationVoucherDelete(LedgerRandom $random): string
    {
        $candidates = array_keys(array_filter($this->voucherBook, static fn (string $status): bool => $status === 'completed'));

        if ($candidates === []) {
            return $this->operationVoucher($random);
        }

        $revenueId = $random->pick($candidates);

        $this->note('delete voucher ' . $revenueId);

        (new RevenueVouchers())->delete([$revenueId], $this->world->boss);

        $this->voucherBook[$revenueId] = 'deleted';

        return 'delete voucher ' . $revenueId;
    }

    private function operationTransfer(LedgerRandom $random): string
    {
        if (count($this->world->treasuries) < 2) {
            return $this->operationVoucher($random);
        }

        $ids    = array_keys($this->world->treasuries);
        $from   = $random->pick($ids);
        $to     = $random->pick(array_values(array_diff($ids, [$from])));
        $amount = $random->money(100000);
        $date   = date('Y-m-d', strtotime('-' . $random->int(0, 30) . ' days'));

        $this->note(sprintf('treasury transfer %d -> %d of %s on %s', $from, $to, $amount, $date));

        $transferId = (new TreasuryTransfers())->transfer($from, $to, $amount, $date, 'ledger', $this->world->boss);

        $this->transferBook[$transferId] = 'completed';

        return sprintf('treasury transfer %d: %d -> %d of %s on %s', $transferId, $from, $to, $amount, $date);
    }

    private function operationTransferCancel(LedgerRandom $random): string
    {
        $candidates = array_keys(array_filter($this->transferBook, static fn (string $status): bool => $status === 'completed'));

        if ($candidates === []) {
            return $this->operationTransfer($random);
        }

        $transferId = $random->pick($candidates);

        $this->note('cancel treasury transfer ' . $transferId);

        (new TreasuryTransfers())->cancel($transferId, 'ledger', $this->world->boss);

        $this->transferBook[$transferId] = 'cancelled';

        return 'cancel treasury transfer ' . $transferId;
    }

    private function operationCheque(LedgerRandom $random): string
    {
        $service = new ChequeService();
        $kind    = $random->weighted(['receive' => 30, 'issue' => 20, 'move' => 50]);
        $open    = array_keys(array_filter($this->chequeBook, static fn (array $cheque): bool => $cheque['status'] !== 'deleted'));

        if ($kind === 'move' && $open === []) {
            $kind = 'receive';
        }

        $serial = $this->position + 1;
        $day    = date('Y-m-d', strtotime('-' . $random->int(0, 20) . ' days'));

        if ($kind === 'receive') {
            $customerId = $random->pick(array_keys($this->world->customers));
            $amount     = $random->money(200000);

            $this->note(sprintf('receive cheque customer=%d of %s on %s', $customerId, $amount, $day));

            $chequeId = $service->receive(['party_id' => $customerId, 'treasury_id' => $this->world->bankTreasury, 'cheque_number' => 'R' . $this->seed . '-' . $serial, 'amount' => $amount, 'issue_date' => $day, 'due_date' => $day], $this->world->boss);

            $this->chequeBook[$chequeId] = ['status' => 'received', 'direction' => 'received'];

            return sprintf('receive cheque %d customer=%d of %s on %s', $chequeId, $customerId, $amount, $day);
        }

        if ($kind === 'issue') {
            $supplierId = $random->pick(array_keys($this->world->suppliers));
            $amount     = $random->money(200000);
            $deliver    = $random->chance(70) ? $day : null;

            $this->note(sprintf('issue cheque supplier=%d of %s on %s%s', $supplierId, $amount, $day, $deliver === null ? '' : ' delivered'));

            $chequeId = $service->issue(['party_id' => $supplierId, 'book_id' => $this->world->chequeBook, 'cheque_number' => (string) $serial, 'amount' => $amount, 'issue_date' => $day, 'due_date' => $day], $this->world->boss, $deliver);

            $this->chequeBook[$chequeId] = ['status' => $deliver === null ? 'issued' : 'delivered', 'direction' => 'issued'];

            return sprintf('issue cheque %d supplier=%d of %s on %s%s', $chequeId, $supplierId, $amount, $day, $deliver === null ? '' : ' delivered');
        }

        $chequeId = $random->pick($open);
        $cheque   = $service->find($chequeId);
        $actions  = $service->actionsOf($cheque);

        $action = $random->pick($actions);

        $this->note(sprintf('cheque %d (%s) action %s', $chequeId, $cheque->status, $action));

        match ($action) {
            'deposit' => $service->deposit($chequeId, date('Y-m-d'), $this->world->boss),
            'collect' => $service->collect($chequeId, date('Y-m-d'), null, $this->world->boss),
            'reject'  => $service->reject($chequeId, date('Y-m-d'), $this->world->boss),
            'deliver' => $service->deliver($chequeId, date('Y-m-d'), $this->world->boss),
            'cancel'  => $service->cancel($chequeId, $this->world->boss),
            'undo'    => $service->undo($chequeId, $this->world->boss),
            'delete'  => $service->delete($chequeId, $this->world->boss),
            default   => null,
        };

        $this->chequeBook[$chequeId]['status'] = $action === 'delete' ? 'deleted' : (string) $service->find($chequeId)->status;

        return sprintf('cheque %d (%s) action %s -> %s', $chequeId, $cheque->status, $action, $this->chequeBook[$chequeId]['status']);
    }
}
