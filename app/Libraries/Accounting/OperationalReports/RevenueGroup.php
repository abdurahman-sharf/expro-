<?php

namespace App\Libraries\Accounting\OperationalReports;

/**
 * What the revenue report is grouped by: a calendar period, the customer, or the employee who entered
 * the document. The salesperson as a dimension of its own is not offered: the sale carries the employee
 * who entered it and no other.
 */
enum RevenueGroup: string
{
    case Day      = 'day';
    case Week     = 'week';
    case Month    = 'month';
    case Year     = 'year';
    case Customer = 'customer';
    case User     = 'user';

    public function period(): ?PeriodGroup
    {
        return PeriodGroup::tryFrom($this->value);
    }

    public function permission(): string
    {
        return match ($this) {
            self::Customer => 'reports_customers',
            self::User     => 'reports_employees',
            default        => 'reports_sales',
        };
    }

    public function languageKey(): string
    {
        return 'Accounting_operational_reports.group_' . $this->value;
    }
}
