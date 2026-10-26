<?php

namespace App\Commands;

use App\Libraries\Accounting\LockOutcome;
use App\Libraries\Accounting\NamedLock;
use CodeIgniter\CLI\BaseCommand;
use CodeIgniter\CLI\CLI;
use Config\Database;

class AccountingCheckLocks extends BaseCommand
{
    protected $group       = 'Accounting';
    protected $name        = 'accounting:check_locks';
    protected $usage       = 'accounting:check_locks';
    protected $description = 'Checks that the connection to the database keeps named locks, which is what stops two changes to one customer or supplier from spending the same credit twice.';

    public function run(array $params): int
    {
        $outcome = (new NamedLock(Database::connect(), 1))->probe();

        if ($outcome === LockOutcome::Taken) {
            CLI::write('The connection to the database keeps named locks.', 'green');

            return EXIT_SUCCESS;
        }

        CLI::error('The connection to the database does not keep named locks (' . $outcome->value . '). Changes to customers and suppliers are refused until the application talks to the database without a connection pooler or proxy that shares connections.');

        return EXIT_ERROR;
    }
}
