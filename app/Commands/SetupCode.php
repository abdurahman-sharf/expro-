<?php

namespace App\Commands;

use App\Libraries\Setup\InstallState;
use CodeIgniter\CLI\BaseCommand;
use CodeIgniter\CLI\CLI;

class SetupCode extends BaseCommand
{
    protected $group       = 'Setup';
    protected $name        = 'setup:code';
    protected $usage       = 'setup:code';
    protected $description = 'Makes a new code for the owner sign-up of an installation that still waits for its owner. Older codes stop working.';

    public function run(array $params): int
    {
        $code = (new InstallState())->renewCode();

        if ($code === null) {
            CLI::error('This installation is not waiting for its owner.');

            return EXIT_ERROR;
        }

        CLI::write("Owner setup code: {$code}", 'green');
        CLI::write('It is also kept in writable/' . InstallState::CODE_FILE . ' and works until the owner account is created.');

        return EXIT_SUCCESS;
    }
}
