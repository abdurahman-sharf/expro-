<?php

namespace App\Libraries\Accounting;

/**
 * Which accounts a statement lists: all of them, those that had a journal line in the period, or
 * those that stand at something. The totals never change with the choice.
 */
enum StatementDisplay: string
{
    case All          = 'all';
    case WithActivity = 'activity';
    case HideZero     = 'hide_zero';

    public static function fromRequest(?string $value): self
    {
        return self::tryFrom((string) $value) ?? self::All;
    }
}
