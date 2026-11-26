<?php

namespace App\Libraries\Accounting;

/**
 * The state of a quote (an estimate), kept apart from the status of the sale that holds it. There
 * are eight, set from the list and the view of a quote and never from its
 * form. A quote that was saved before the states existed has none stored and is read as open.
 * Sent, viewed and replied need the quote to leave the system by mail or by a shared link, which
 * this system does not do, so an employee sets them by hand; converted is set only by saving the
 * invoice the quote was converted into.
 */
enum QuoteStatus: int
{
    case Draft     = -1;
    case Open      = 0;
    case Sent      = 1;
    case Viewed    = 2;
    case Replied   = 3;
    case Approved  = 4;
    case Converted = 5;
    case Ordered   = 6;

    public static function fromStored(int|string|null $value): self
    {
        return $value === null || $value === '' ? self::Open : (self::tryFrom((int) $value) ?? self::Open);
    }

    /**
     * What a quote that is saved again is left with: the state it has, except that a draft may be
     * issued, so that saving it again as a quote opens it. A quote that is new takes the one asked.
     */
    public static function afterSave(?self $stored, self $requested): self
    {
        if ($stored === null) {
            return $requested;
        }

        return $stored === self::Draft ? $requested : $stored;
    }

    public function languageKey(): string
    {
        return 'Sales.quote_status_' . strtolower($this->name);
    }

    /**
     * The states an employee may move a quote to from its list or its page.
     *
     * @return list<self>
     */
    public static function manual(): array
    {
        return [self::Open, self::Sent, self::Viewed, self::Replied, self::Approved, self::Ordered];
    }

    /**
     * The states a quote may be moved to from this one. Drafts are issued (opened) and cannot be
     * approved or sent before; what the customer has not answered yet may be set among the states
     * of waiting, or approved; an approved quote is reopened or ordered, and an ordered one goes back
     * to approved. A converted quote is final.
     *
     * @return list<self>
     */
    public function next(): array
    {
        return match ($this) {
            self::Draft => [self::Open],
            self::Open, self::Sent, self::Viewed, self::Replied => array_values(array_filter([self::Open, self::Sent, self::Viewed, self::Replied, self::Approved], fn (self $state): bool => $state !== $this)),
            self::Approved  => [self::Open, self::Ordered],
            self::Ordered   => [self::Approved],
            self::Converted => [],
        };
    }

    public function canMoveTo(self $to): bool
    {
        return in_array($to, $this->next(), true);
    }

    /**
     * Whether an invoice may be made from a quote in this state.
     */
    public function canConvert(): bool
    {
        return $this !== self::Draft && $this !== self::Converted;
    }

    /**
     * Whether the quote may still be changed, cancelled or moved: a converted quote is the record of
     * the invoice it produced.
     */
    public function isLocked(): bool
    {
        return $this === self::Converted;
    }

    /**
     * Whether the customer has answered a quote that this state asks the employee to confirm before
     * an invoice is made from it.
     */
    public function isAccepted(): bool
    {
        return $this === self::Approved || $this === self::Ordered;
    }
}
