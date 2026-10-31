<?php

namespace App\Libraries\Accounting;

/**
 * Whether the trial balance counts the journals that close a year. Before shows the accounts of the
 * year with what they hold, which is what the trial balance reads without the option. After counts
 * the closing, so those accounts stand at nothing and the retained earnings hold their result.
 */
enum TrialBalanceClosing: string
{
    case Before = 'before';
    case After  = 'after';

    public static function fromRequest(?string $value): self
    {
        return self::tryFrom((string) $value) ?? self::Before;
    }
}
