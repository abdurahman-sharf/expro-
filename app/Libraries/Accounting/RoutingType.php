<?php

namespace App\Libraries\Accounting;

/**
 * How a routing row decides the account of its posting line. The numbers identify the choices in
 * the routing screen.
 *
 * Auto uses the account of the row, and for a party, stock location or treasury it opens one
 * sub-account of that account for every entity. PerItem lets each product name its own account on
 * the rows that take one, and an item without its own falls back to the account of the row.
 * Cancelled writes no line for the row, so the amount nets into the line it belongs to.
 * MainPerEntity resolves the row to the account of the entity the document is for. Which rows a
 * type applies to is decided by RoutingKey::applies().
 */
enum RoutingType: string
{
    case Auto          = 'auto';
    case PerItem       = 'per_item';
    case Cancelled     = 'cancelled';
    case MainPerEntity = 'main_per_entity';

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
