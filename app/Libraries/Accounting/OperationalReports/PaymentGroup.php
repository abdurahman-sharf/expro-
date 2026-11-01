<?php

namespace App\Libraries\Accounting\OperationalReports;

/**
 * What the payments report is grouped by: the customer, the employee who made out the invoice, the employee
 * who took the payment, the method it was paid by, the treasury or other account the money went into, or a
 * calendar period. The method and the treasury are independent of each other.
 */
enum PaymentGroup: string
{
    case Customer  = 'customer';
    case User      = 'user';
    case Collector = 'collector';
    case Method    = 'method';
    case Treasury  = 'treasury';
    case Day       = 'day';
    case Week      = 'week';
    case Month     = 'month';
    case Year      = 'year';

    public function period(): ?PeriodGroup
    {
        return PeriodGroup::tryFrom($this->value);
    }

    public function languageKey(): string
    {
        return 'Accounting_operational_reports.group_' . $this->value;
    }
}
