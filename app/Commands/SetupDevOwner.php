<?php

namespace App\Commands;

use App\Libraries\Setup\DevOwner;
use CodeIgniter\CLI\BaseCommand;
use CodeIgniter\CLI\CLI;
use RuntimeException;

class SetupDevOwner extends BaseCommand
{
    protected $group       = 'Setup';
    protected $name        = 'setup:dev-owner';
    protected $usage       = 'setup:dev-owner [--username admin] [--password <password>]';
    protected $description = 'Makes the owner of an empty development or test installation without the setup code. Refuses to run outside the development and testing environments.';
    protected $options     = [
        '--username' => 'The username of the owner. Defaults to admin.',
        '--password' => 'The password of the owner. Defaults to the DEV_OWNER_PASSWORD variable.',
    ];

    public function run(array $params): int
    {
        $username = (string) (CLI::getOption('username') ?? 'admin');
        $password = (string) (CLI::getOption('password') ?? env('DEV_OWNER_PASSWORD', ''));

        try {
            (new DevOwner())->create($username, $password);
        } catch (RuntimeException $failure) {
            CLI::error($failure->getMessage());

            return EXIT_ERROR;
        }

        CLI::write("The owner {$username} is made and the installation is set up.", 'green');

        return EXIT_SUCCESS;
    }
}
