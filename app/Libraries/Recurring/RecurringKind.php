<?php

namespace App\Libraries\Recurring;

enum RecurringKind: string
{
    case Invoice = 'invoice';
    case Expense = 'expense';

    public function moduleId(): string
    {
        return match ($this) {
            self::Invoice => 'recurring_invoices',
            self::Expense => 'recurring_expenses',
        };
    }

    public function managePermission(): string
    {
        return $this->moduleId() . '_manage';
    }

    public function auditEntity(): string
    {
        return 'recurring_' . $this->value;
    }
}
