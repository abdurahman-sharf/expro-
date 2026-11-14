<?php

namespace Tests\Support\Ledger;

use RuntimeException;

/**
 * An invariant of the books that a scenario found broken, or an operation that failed in a way no
 * business rule explains.
 */
final class LedgerViolation extends RuntimeException
{
}
