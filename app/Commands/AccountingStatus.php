<?php

namespace App\Commands;

use App\Libraries\Accounting\AccountingSettings;
use CodeIgniter\CLI\BaseCommand;
use CodeIgniter\CLI\CLI;

class AccountingStatus extends BaseCommand
{
    protected $group       = 'Accounting';
    protected $name        = 'accounting:status';
    protected $usage       = 'accounting:status';
    protected $description = 'Shows whether accounting is enabled and what is still missing before it can be enabled.';

    public function run(array $params): void
    {
        $settings = new AccountingSettings();

        CLI::write('Accounting is ' . ($settings->isEnabled() ? 'enabled' : 'disabled') . '.');

        $issues = $settings->readiness();

        if ($issues === []) {
            CLI::write('All required settings are complete.', 'green');

            return;
        }

        CLI::write('Missing before it can be enabled:', 'yellow');

        foreach ($issues as $issue) {
            CLI::write(' - ' . $issue->code->value . ($issue->subject === null ? '' : " ({$issue->subject})"));
        }
    }
}
