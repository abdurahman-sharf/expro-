<?php

namespace App\Commands;

use App\Libraries\Accounting\Ai\AiSettings;
use CodeIgniter\CLI\BaseCommand;
use CodeIgniter\CLI\CLI;

class AccountingAiSwitch extends BaseCommand
{
    protected $group       = 'Accounting';
    protected $name        = 'accounting:ai_switch';
    protected $usage       = 'accounting:ai_switch [status|on|off]';
    protected $description = 'Shows, turns on or turns off the AI agent. Turning it off is the kill switch: it stops the making of proposals, their approval and the scan at once, in every session, and what was recorded stays readable. The change is written to the audit log as made by the system. Exits 0 when it did what was asked and 7 for a word it does not know.';

    public function run(array $params): int
    {
        $word = $params[0] ?? 'status';

        if (! in_array($word, ['status', 'on', 'off'], true)) {
            CLI::error('The word is status, on or off.');

            return EXIT_USER_INPUT;
        }

        $settings = new AiSettings();

        if ($word !== 'status') {
            $settings->setEnabled($word === 'on', null);
        }

        CLI::write('The AI agent is ' . ($settings->isSwitchedOn() ? 'on' : 'off') . '.');

        return EXIT_SUCCESS;
    }
}
