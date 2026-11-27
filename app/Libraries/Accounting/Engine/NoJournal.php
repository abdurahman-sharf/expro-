<?php

namespace App\Libraries\Accounting\Engine;

use RuntimeException;

/**
 * A line found no account and its rule says the document then posts no journal at all, as a payment
 * to a way of paying whose routing is cancelled posts nothing. It never leaves the engine.
 */
final class NoJournal extends RuntimeException
{
}
