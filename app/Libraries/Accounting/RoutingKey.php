<?php

namespace App\Libraries\Accounting;

enum RoutingKey: string
{
    case CashDefault        = 'cash.default';
    case CashDifference     = 'cash.difference';
    case ReceivablesControl = 'receivables.control';
    case PayablesControl    = 'payables.control';
    case SalesRevenue       = 'sales.revenue';
    case SalesReturns       = 'sales.returns';
    case SalesDiscount      = 'sales.discount';
    case CostOfGoodsSold    = 'sales.cogs';
    case InventoryStock     = 'inventory.stock';
    case TaxOutput          = 'tax.output';
    case TaxInput           = 'tax.input';
    case ExpensesDefault    = 'expenses.default';
    case GiftcardLiability  = 'giftcards.liability';
    case OpeningBalance     = 'equity.opening';
    case RetainedEarnings   = 'equity.retained';

    public function defaultSystemKey(): string
    {
        return match ($this) {
            self::CashDefault        => 'cash',
            self::CashDifference     => 'cash_difference',
            self::ReceivablesControl => 'receivables',
            self::PayablesControl    => 'payables',
            self::SalesRevenue       => 'sales_revenue',
            self::SalesReturns       => 'sales_returns',
            self::SalesDiscount      => 'sales_discount',
            self::CostOfGoodsSold    => 'cogs',
            self::InventoryStock     => 'inventory',
            self::TaxOutput          => 'tax_output',
            self::TaxInput           => 'tax_input',
            self::ExpensesDefault    => 'expenses_default',
            self::GiftcardLiability  => 'giftcard_liability',
            self::OpeningBalance     => 'opening_balance',
            self::RetainedEarnings   => 'retained_earnings',
        };
    }
}
