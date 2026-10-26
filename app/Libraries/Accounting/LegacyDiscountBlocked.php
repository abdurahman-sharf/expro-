<?php

namespace App\Libraries\Accounting;

use RuntimeException;

final class LegacyDiscountBlocked extends RuntimeException
{
    private const SHOWN = 10;

    /**
     * @param list<LegacyDiscountFinding> $blocked
     */
    public function __construct(public readonly array $blocked)
    {
        $lines = array_map(
            static fn (LegacyDiscountFinding $finding): string => "purchase #{$finding->receivingId} line {$finding->line} saved {$finding->savedAt}: {$finding->reason}",
            array_slice($blocked, 0, self::SHOWN),
        );

        if (count($blocked) > self::SHOWN) {
            $lines[] = '... and ' . (count($blocked) - self::SHOWN) . ' more';
        }

        parent::__construct(
            count($blocked) . ' purchase line(s) with a fixed discount cannot be told apart as saved before or after the discount became an amount for every unit, so nothing was changed. '
            . implode('; ', $lines) . '. Run "php spark accounting:purchase_discount" to see them, then record the decision with "php spark accounting:purchase_discount resolve".',
        );
    }
}
