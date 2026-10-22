<?php

namespace App\Commands;

use App\Libraries\Accounting\AccountingSettings;
use CodeIgniter\CLI\BaseCommand;
use CodeIgniter\CLI\CLI;

class AccountingDisable extends BaseCommand
{
    protected $group       = 'Accounting';
    protected $name        = 'accounting:disable';
    protected $usage       = 'accounting:disable';
    protected $description = 'Turns accounting off. Posted journals are kept.';

    public function run(array $params): void
    {
        (new AccountingSettings())->disable();

        CLI::write('Accounting is disabled.', 'green');
    }
}
