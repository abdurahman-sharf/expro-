<?php

namespace Tests\Support;

use App\Libraries\Accounting\AccountingGuard;
use App\Libraries\Accounting\CreditRefunds;
use App\Libraries\Accounting\SupplierAdvances;

/**
 * What a test of the credit held with a supplier needs to be paid it back in cash: an employee who may,
 * an advance that gives the credit, and the refund itself. It goes with PurchaseAccountingFixtureTrait.
 */
trait CreditRefundFixtureTrait
{
    protected function refundsFromSuppliers(): CreditRefunds
    {
        return new CreditRefunds(CreditRefunds::SUPPLIER);
    }

    protected function supplierTreasurer(): int
    {
        return $this->employeeWithGrants([AccountingGuard::PAYABLES_REFUND, AccountingGuard::PAYABLES_MANAGE, AccountingGuard::TREASURIES_MANAGE]);
    }

    protected function supplierRefund(string $amount, ?int $employeeId = null, ?int $treasuryId = null, ?string $date = null, ?string $key = null, ?int $supplierId = null): int
    {
        return $this->refundsFromSuppliers()->refund($supplierId ?? $this->supplierId, $amount, $date ?? date('Y-m-d'), 'Cash', $treasuryId, '', '', $employeeId ?? $this->supplierTreasurer(), $key);
    }

    protected function advanceToSupplier(string $amount, ?int $employeeId = null, ?int $treasuryId = null): int
    {
        return (new SupplierAdvances())->pay($this->supplierId, $amount, date('Y-m-d'), 'Cash', $treasuryId, '', '', $employeeId ?? $this->supplierTreasurer());
    }
}
