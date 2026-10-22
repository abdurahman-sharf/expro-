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

    /**
     * The keys the routing screen edits. The other keys belong to purchases, expenses and
     * equity and keep the account the chart gave them until their phases.
     *
     * @return list<self>
     */
    public static function sales(): array
    {
        return [
            self::ReceivablesControl,
            self::SalesRevenue,
            self::SalesReturns,
            self::SalesDiscount,
            self::CostOfGoodsSold,
            self::InventoryStock,
            self::TaxOutput,
            self::CashDifference,
            self::GiftcardLiability,
        ];
    }

    /**
     * @return list<AccountType>
     */
    public function allowedAccountTypes(): array
    {
        return match ($this) {
            self::ReceivablesControl, self::InventoryStock => [AccountType::Asset],
            self::SalesRevenue, self::SalesReturns => [AccountType::Revenue],
            self::SalesDiscount, self::CashDifference => [AccountType::Revenue, AccountType::Expense],
            self::CostOfGoodsSold => [AccountType::Expense],
            self::TaxOutput, self::GiftcardLiability => [AccountType::Liability],
            default => [],
        };
    }

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
