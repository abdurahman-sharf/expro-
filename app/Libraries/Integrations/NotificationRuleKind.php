<?php

namespace App\Libraries\Integrations;

enum NotificationRuleKind: string
{
    case InvoiceOverdue = 'invoice_overdue';
    case LowStock       = 'low_stock';
    case ChequeDue      = 'cheque_due';

    public function languageKey(): string
    {
        return 'Integrations.rule_kind_' . $this->value;
    }

    public function noticeKey(): string
    {
        return 'Integrations.notice_' . $this->value;
    }

    public function takesThreshold(): bool
    {
        return $this !== self::LowStock;
    }

    public function lowest(): int
    {
        return $this === self::InvoiceOverdue ? 1 : 0;
    }

    public function highest(): int
    {
        return $this === self::InvoiceOverdue ? 365 : 90;
    }
}
