<?php

namespace App\Libraries\Accounting;

/**
 * Which accounts a statement of a main account draws a block for: those with a line in the period,
 * every account under it, or those that stand at something, which keeps an account with no line in
 * the period when it stood at something when the period opened.
 */
enum LedgerDisplay: string
{
    case Activity       = 'activity';
    case All            = 'all';
    case NonZeroBalance = 'balance';

    public static function fromRequest(mixed $value): self
    {
        return is_string($value) ? (self::tryFrom($value) ?? self::Activity) : self::Activity;
    }
}
