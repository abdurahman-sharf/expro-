<?php

namespace App\Commands;

use App\Libraries\Accounting\BooksSnapshot;
use CodeIgniter\CLI\BaseCommand;
use CodeIgniter\CLI\CLI;

class AccountingBooksSnapshot extends BaseCommand
{
    protected $group       = 'Accounting';
    protected $name        = 'accounting:books_snapshot';
    protected $usage       = 'accounting:books_snapshot FILE [--all-companies]';
    protected $description = 'Writes to FILE, as JSON, what every account was debited and credited over the posted journals, in all and in each branch, their totals, and for each stock location what its account stands at beside what its valued movements add up to. Reads the database and writes only the file. Take one before a migration and one after, then give both to accounting:books_compare. Put the file name before the option. Exits 0 when the file was written and 7 when no file is named or it cannot be written.';
    protected $options     = [
        '--all-companies' => 'Take the books of every company instead of the one every request works in.',
    ];

    /**
     * @param array<int|string, string|null> $params
     */
    public function run(array $params): int
    {
        $file = $params[0] ?? null;

        if (! is_string($file) || $file === '') {
            CLI::error('Name the file to write the snapshot to.');

            return EXIT_USER_INPUT;
        }

        $books    = new BooksSnapshot();
        $snapshot = array_key_exists('all-companies', $params) || CLI::getOption('all-companies') !== null ? $books->takeAll() : $books->take();

        if (@file_put_contents($file, json_encode($snapshot, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR)) === false) {
            CLI::error("The file {$file} could not be written.");

            return EXIT_USER_INPUT;
        }

        foreach ($snapshot['companies'] as $companyId => $company) {
            CLI::write(sprintf(
                'Company %d: %d account(s) with movements, debit %s, credit %s, %d stock location(s), %d of them not tied.',
                $companyId,
                count($company['accounts']),
                $company['totals']['debit'],
                $company['totals']['credit'],
                count($company['stock']),
                count(BooksSnapshot::untiedStock(['companies' => [$companyId => $company]])),
            ));
        }

        return EXIT_SUCCESS;
    }
}
