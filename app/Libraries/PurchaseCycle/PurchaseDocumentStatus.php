<?php

namespace App\Libraries\PurchaseCycle;

/**
 * Where a purchase cycle document stands. Open, sent, approved and cancelled are stored; sent and approved
 * are labels that somebody sets by hand, where the family allows it, and nothing is ever sent to anybody.
 * Converted, partly received and received are never stored: they are read from the documents and the
 * receivings that stand, so a cancelled document or a deleted receiving puts the document back where it
 * was without anyone having to remember to. The statuses and the moves between them are deliberately
 * conservative.
 */
enum PurchaseDocumentStatus: string
{
    case Open           = 'open';
    case Sent           = 'sent';
    case Approved       = 'approved';
    case Converted      = 'converted';
    case PartlyReceived = 'partly_received';
    case Received       = 'received';
    case Cancelled      = 'cancelled';

    public function languageKey(): string
    {
        return 'Purchase_cycle.status_' . $this->value;
    }

    public function isStored(): bool
    {
        return in_array($this, [self::Open, self::Sent, self::Approved, self::Cancelled], true);
    }

    /**
     * Whether the status is one of the three that an employee sets by hand.
     */
    public function isLabel(): bool
    {
        return in_array($this, [self::Open, self::Sent, self::Approved], true);
    }

    /**
     * Whether the next document of the chain may be made from it: it is not cancelled and no document made from it stands. A purchase
     * order that was received in part is received again, by another receiving, until it is received in full.
     */
    public function canConvert(PurchaseDocumentKind $kind): bool
    {
        return $this->isLabel() || ($kind === PurchaseDocumentKind::Order && $this === self::PartlyReceived);
    }

    /**
     * Whether the lines of the document may still be changed.
     */
    public function isEditable(): bool
    {
        return $this->isLabel();
    }

    /**
     * The states an employee may move the document to by hand, any of the other two labels. Cancelling asks for a reason and has its own action.
     *
     * @return list<self>
     */
    public function manualNext(): array
    {
        return $this->isLabel() ? array_values(array_filter([self::Open, self::Sent, self::Approved], fn (self $label): bool => $label !== $this)) : [];
    }

    public function canCancel(): bool
    {
        return $this->isLabel() || $this === self::PartlyReceived;
    }
}
