<?php

namespace App\Libraries\Accounting;

/**
 * What a purchase document does to stock: it brings goods in from a supplier, sends them back
 * to one, or moves them between stock locations.
 */
enum ReceivingType: string
{
    case Receive     = 'receive';
    case Return      = 'return';
    case Requisition = 'requisition';

    /**
     * The kind of a document from the signs of its lines: goods only coming in, only going out,
     * or both ways, which is stock moving from one location to another.
     *
     * @param list<string> $quantities The signed quantities of the lines.
     */
    public static function ofQuantities(array $quantities): self
    {
        $in  = false;
        $out = false;

        foreach ($quantities as $quantity) {
            $sign = bccomp($quantity, '0', 3);

            $in  = $in  || $sign > 0;
            $out = $out || $sign < 0;
        }

        return match (true) {
            $in && $out => self::Requisition,
            $out        => self::Return,
            default     => self::Receive,
        };
    }
}
