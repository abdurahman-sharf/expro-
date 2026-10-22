<?php

namespace App\Libraries\Accounting;

/**
 * How a routing row decides the account of its posting line. The numbers are the ones the
 * reference system shows next to the same choices.
 */
enum RoutingType: string
{
    case Auto          = 'auto';
    case PerItem       = 'per_item';
    case Cancelled     = 'cancelled';
    case MainPerEntity = 'main_per_entity';

    /**
     * Auto uses the row's account, and for a party, stock location or treasury it opens one
     * sub-account of that account for every entity. PerItem lets each product or entity name
     * its own account. Cancelled writes no line for the row, so the amount nets into the
     * line it belongs to. MainPerEntity lets each customer name its own main account.
     */
    public function number(): int
    {
        return match ($this) {
            self::Auto          => 1,
            self::PerItem       => 2,
            self::Cancelled     => 3,
            self::MainPerEntity => 4,
        };
    }
}
