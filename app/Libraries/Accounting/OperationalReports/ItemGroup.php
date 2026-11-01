<?php

namespace App\Libraries\Accounting\OperationalReports;

/**
 * What the item reports are grouped by: the item, its category, the customer, the employee who entered the
 * document, or a calendar period. The brand is not offered, because an item has none, and neither is the
 * salesperson, which a sale does not carry.
 */
enum ItemGroup: string
{
    case Item     = 'item';
    case Category = 'category';
    case Customer = 'customer';
    case User     = 'user';
    case Day      = 'day';
    case Week     = 'week';
    case Month    = 'month';
    case Year     = 'year';

    public function period(): ?PeriodGroup
    {
        return PeriodGroup::tryFrom($this->value);
    }

    public function permission(): string
    {
        return match ($this) {
            self::Category => 'reports_categories',
            self::Customer => 'reports_customers',
            self::User     => 'reports_employees',
            default        => 'reports_items',
        };
    }

    public function languageKey(): string
    {
        return 'Accounting_operational_reports.group_' . $this->value;
    }
}
