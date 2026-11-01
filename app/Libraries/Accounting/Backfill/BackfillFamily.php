<?php

namespace App\Libraries\Accounting\Backfill;

enum BackfillFamily: string
{
    case Sales     = 'sales';
    case Purchases = 'purchases';
    case Expenses  = 'expenses';
    case Cashups   = 'cashups';

    public function rank(): int
    {
        return match ($this) {
            self::Sales     => 1,
            self::Purchases => 2,
            self::Expenses  => 3,
            self::Cashups   => 4,
        };
    }

    /**
     * @return list<self> Every family in the order a batch walks them.
     */
    public static function ordered(): array
    {
        $cases = self::cases();

        usort($cases, static fn (self $a, self $b): int => $a->rank() <=> $b->rank());

        return $cases;
    }
}
