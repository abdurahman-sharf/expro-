<?php

namespace App\Commands;

use App\Libraries\EInvoicing\EInvoiceCredentials;
use App\Libraries\EInvoicing\EInvoiceException;
use App\Libraries\EInvoicing\KeyVault;
use CodeIgniter\CLI\BaseCommand;
use CodeIgniter\CLI\CLI;

class EInvoiceRotateKey extends BaseCommand
{
    protected $group       = 'EInvoicing';
    protected $name        = 'einvoice:rotate_key';
    protected $usage       = 'einvoice:rotate_key <path of the master key file>';
    protected $description = 'Adds a new master key to the key ring file and seals every stored secret of the certificates with it. The old keys stay in the file so that a backup taken before can still be opened; the secrets never leave memory.';

    public function run(array $params): int
    {
        $path = (string) ($params[0] ?? '');

        try {
            KeyVault::fromFile($path);

            $version = 'v' . (count(array_filter(preg_split('/\R/', (string) file_get_contents($path)) ?: [], static fn (string $line): bool => trim($line) !== '')) + 1);

            file_put_contents($path, KeyVault::newKeyLine($version) . "\n", FILE_APPEND | LOCK_EX);

            $count = (new EInvoiceCredentials(KeyVault::fromFile($path)))->rewrapAll();
        } catch (EInvoiceException $failure) {
            CLI::error($failure->getMessage());

            return EXIT_ERROR;
        }

        CLI::write("Master key {$version} added; {$count} certificates sealed with it.", 'green');

        return EXIT_SUCCESS;
    }
}
