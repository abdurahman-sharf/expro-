<?php

namespace App\Commands;

use App\Libraries\Accounting\BankAccountPlacement;
use CodeIgniter\CLI\BaseCommand;
use CodeIgniter\CLI\CLI;

class AccountingBankAccounts extends BaseCommand
{
    protected $group       = 'Accounting';
    protected $name        = 'accounting:bank_accounts';
    protected $usage       = 'accounting:bank_accounts [--move]';
    protected $description = 'Lists the bank accounts whose account is not under the main account of the banks and the ones that lack the name of the bank or the account number. With --move it takes their accounts to the banks: only their place in the chart changes, no journal line and no balance.';
    protected $options     = [
        '--move' => 'Take the accounts to the main account of the banks.',
    ];

    public function run(array $params): int
    {
        $placement = new BankAccountPlacement();
        $pending   = $placement->pending();

        if (array_key_exists('move', $params) || CLI::getOption('move') !== null) {
            $placement->openRouting();

            foreach ($placement->move() as $moved) {
                CLI::write("Treasury {$moved['treasury_id']}: account {$moved['from']} moved to {$moved['to']}.");
            }

            $pending = $placement->pending();
        }

        CLI::write(count($pending) . ' bank account(s) are not under the main account of the banks.');

        foreach ($pending as $row) {
            CLI::write("  treasury {$row->treasury_id} ({$row->name}), account {$row->code}");
        }

        $incomplete = $placement->incomplete();

        CLI::write(count($incomplete) . ' bank account(s) lack the name of the bank or the account number.');

        foreach ($incomplete as $row) {
            CLI::write("  treasury {$row->treasury_id} ({$row->name})");
        }

        return $pending === [] ? EXIT_SUCCESS : EXIT_ERROR;
    }
}
