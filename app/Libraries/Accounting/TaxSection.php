<?php

namespace App\Libraries\Accounting;

/**
 * The parts of the tax report, in the order the reference system lists them. The tax of each part
 * is signed by what it does to the tax owed: the tax of sales and of what suppliers take back adds
 * to it, and the tax of returned sales, of purchases and of expenses takes from it.
 */
enum TaxSection: string
{
    case SalesInvoices   = 'sales';
    case SalesReturns    = 'sales_returns';
    case Purchases       = 'purchases';
    case PurchaseReturns = 'purchase_returns';
    case Expenses        = 'expenses';

    public function isOutput(): bool
    {
        return $this === self::SalesInvoices || $this === self::SalesReturns;
    }

    public function hasLines(): bool
    {
        return $this !== self::Expenses;
    }
}
