<?php

namespace App\Commands;

use App\Libraries\Accounting\AccountingException;
use App\Libraries\Accounting\EntityAccountService;
use CodeIgniter\CLI\BaseCommand;
use CodeIgniter\CLI\CLI;

/**
 * Opens the sub-accounts that customers, suppliers and stock locations created before
 * accounting was set up do not have yet. Safe to run again: it only creates what is missing.
 */
class AccountingFillAccounts extends BaseCommand
{
    protected $group       = 'Accounting';
    protected $name        = 'accounting:fill_accounts';
    protected $usage       = 'accounting:fill_accounts';
    protected $description = 'Opens a sub-account for every customer, supplier and stock location that has none.';

    public function run(array $params): void
    {
        try {
            $created = (new EntityAccountService())->fill();
        } catch (AccountingException $exception) {
            CLI::error('The accounts could not be opened: ' . $exception->getMessage());

            return;
        }

        if ($created === []) {
            CLI::write('No routing row is automatic, so nothing was opened.');

            return;
        }

        foreach ($created as $kind => $count) {
            CLI::write("{$kind}: {$count} account(s) opened.");
        }

        CLI::write('Done.', 'green');
    }
}
