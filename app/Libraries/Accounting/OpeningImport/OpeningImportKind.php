<?php

namespace App\Libraries\Accounting\OpeningImport;

use App\Libraries\Accounting\AccountingGuard;
use App\Libraries\Accounting\CustomerOpening;
use App\Libraries\Accounting\SupplierAdvances;

/**
 * Whose opening balances a file holds. Each kind needs the sensitive permission that the form of one
 * balance of the kind needs, and reads a positive amount as the balance the party is more likely to have:
 * what a customer owes us and what we owe a supplier.
 */
enum OpeningImportKind: string
{
    case Customers = 'customers';
    case Suppliers = 'suppliers';

    public function permission(): string
    {
        return $this === self::Customers ? AccountingGuard::OPENING_MANAGE : AccountingGuard::PAYABLES_MANAGE;
    }

    public function sideOf(bool $positive): string
    {
        return match ($this) {
            self::Customers => $positive ? CustomerOpening::OWES_US : CustomerOpening::WE_OWE,
            self::Suppliers => $positive ? SupplierAdvances::WE_OWE : SupplierAdvances::THEY_OWE,
        };
    }

    public function table(): string
    {
        return $this->value;
    }
}
