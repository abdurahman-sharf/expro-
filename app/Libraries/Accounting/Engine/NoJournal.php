<?php

namespace App\Libraries\Accounting\Engine;

use RuntimeException;

/**
 * A line reached a place of its rule that posts no journal, under a condition the facts hold, as a payment
 * left on credit posts nothing. It never leaves the engine.
 */
final class NoJournal extends RuntimeException
{
}
