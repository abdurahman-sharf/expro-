<?php

namespace App\Libraries\SalesOrders;

/**
 * Where a sales order stands. Open, approved and cancelled are stored; partly invoiced and invoiced
 * are never stored, they are what the quantities of the invoices that were issued from the order
 * say, so an invoice that is cancelled puts the order back where it was without anyone having to
 * remember to. The reference system's statuses and the way an order is converted were not observed,
 * so this table is the conservative choice of this system, not a copy.
 */
enum SalesOrderStatus: string
{
    case Open           = 'open';
    case Approved       = 'approved';
    case PartlyInvoiced = 'partly_invoiced';
    case Invoiced       = 'invoiced';
    case Cancelled      = 'cancelled';

    public function languageKey(): string
    {
        return 'Sales_orders.status_' . $this->value;
    }

    public function isStored(): bool
    {
        return in_array($this, [self::Open, self::Approved, self::Cancelled], true);
    }

    /**
     * An invoice may be made from an order its customer has approved, and from one that was invoiced in part.
     */
    public function canConvert(): bool
    {
        return $this === self::Approved || $this === self::PartlyInvoiced;
    }

    /**
     * Whether the lines of the order may still be changed: nothing was invoiced from it and it is not cancelled.
     */
    public function isEditable(): bool
    {
        return $this === self::Open || $this === self::Approved;
    }

    /**
     * The states an employee may move the order to by hand. Cancelling asks for a reason and has its own action.
     *
     * @return list<self>
     */
    public function manualNext(): array
    {
        return match ($this) {
            self::Open     => [self::Approved],
            self::Approved => [self::Open],
            default        => [],
        };
    }

    public function canCancel(): bool
    {
        return $this !== self::Cancelled && $this !== self::Invoiced;
    }
}
