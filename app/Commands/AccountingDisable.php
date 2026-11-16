<?php

namespace App\Commands;

use App\Libraries\Accounting\AccountingException;
use App\Libraries\Accounting\AccountingMessage;
use App\Libraries\Accounting\AccountingSettings;
use App\Libraries\Organization\BranchException;
use CodeIgniter\CLI\BaseCommand;
use CodeIgniter\CLI\CLI;

class AccountingDisable extends BaseCommand
{
    protected $group       = 'Accounting';
    protected $name        = 'accounting:disable';
    protected $usage       = 'accounting:disable';
    protected $description = 'Takes back the turning on of accounting, which is possible only until the books hold a journal.';

    public function run(array $params): int
    {
        try {
            (new AccountingSettings())->disable();
        } catch (AccountingException|BranchException $failure) {
            CLI::error(AccountingMessage::of($failure));

            return EXIT_ERROR;
        }

        CLI::write('Accounting is disabled.', 'green');

        return EXIT_SUCCESS;
    }
}
