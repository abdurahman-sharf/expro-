<?php

namespace Tests\Support;

use CodeIgniter\Database\MySQLi\Builder;

/**
 * What ReadOnlyRefusingConnection builds its queries with: the connection names its builder class
 * after itself.
 */
final class ReadOnlyRefusingBuilder extends Builder
{
}
