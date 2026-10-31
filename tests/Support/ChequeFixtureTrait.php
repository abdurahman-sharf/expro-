<?php

namespace Tests\Support;

use App\Libraries\Accounting\AccountingGuard;
use App\Libraries\Accounting\ChequeBooks;
use App\Libraries\Accounting\ChequeService;
use App\Libraries\Accounting\TreasuryService;

/**
 * What a test needs to enter cheques through the real service: an employee who changes cheques, a
 * bank account and a cheque book. A class that uses it also uses AccountingFixtureTrait and
 * SaleAccountingFixtureTrait, and PurchaseAccountingFixtureTrait for cheques paid to suppliers.
 */
trait ChequeFixtureTrait
{
    protected ChequeService $chequeService;
    protected int $chequeClerk;
    protected int $chequeBank;
    protected int $chequeBook;

    private int $chequeSerial = 0;

    protected function startCheques(): void
    {
        $this->chequeService = new ChequeService();
        $this->chequeClerk   = $this->employeeWithGrants([AccountingGuard::CHEQUES_MANAGE, AccountingGuard::TREASURIES_MANAGE]);
        $this->chequeBank    = (new TreasuryService())->create(['name' => 'Cheque bank ' . uniqid(), 'kind' => 'bank', 'bank_name' => 'Gulf Bank', 'account_number' => strtoupper(uniqid('AC')), 'branch_id' => 0], $this->chequeClerk);
        $this->chequeBook    = (new ChequeBooks())->create(['treasury_id' => $this->chequeBank, 'name' => 'Cheque book', 'first_serial' => '1', 'last_serial' => '999999'], $this->chequeClerk);
        $this->chequeSerial  = 0;
    }

    protected function receiveCheque(string $amount, string $issued, ?string $due = null, ?int $customerId = null): int
    {
        return $this->chequeService->receive([
            'party_id'      => $customerId ?? $this->customerId,
            'treasury_id'   => $this->chequeBank,
            'cheque_number' => 'R' . ++$this->chequeSerial,
            'amount'        => $amount,
            'issue_date'    => $issued,
            'due_date'      => $due ?? $issued,
        ], $this->chequeClerk);
    }

    protected function issueCheque(string $amount, string $issued, ?string $deliveredOn = null, ?int $supplierId = null): int
    {
        return $this->chequeService->issue([
            'party_id'      => $supplierId ?? $this->supplierId,
            'book_id'       => $this->chequeBook,
            'cheque_number' => (string) (++$this->chequeSerial),
            'amount'        => $amount,
            'issue_date'    => $issued,
            'due_date'      => $issued,
        ], $this->chequeClerk, $deliveredOn);
    }
}
