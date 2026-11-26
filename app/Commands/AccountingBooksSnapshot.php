<?php

namespace App\Commands;

use App\Libraries\Accounting\BooksSnapshot;
use CodeIgniter\CLI\BaseCommand;
use CodeIgniter\CLI\CLI;

class AccountingBooksSnapshot extends BaseCommand
{
    protected $group       = 'Accounting';
    protected $name        = 'accounting:books_snapshot';
    protected $usage       = 'accounting:books_snapshot FILE';
    protected $description = 'Writes to FILE, as JSON, what every account was debited and credited over the posted journals, their totals, and for each stock location what its account stands at beside what its valued movements add up to. Reads the database and writes only the file. Take one before a migration and one after, then give both to accounting:books_compare. Exits 0 when the file was written and 7 when no file is named or it cannot be written.';

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

        $snapshot = (new BooksSnapshot())->take();

        if (@file_put_contents($file, json_encode($snapshot, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR)) === false) {
            CLI::error("The file {$file} could not be written.");

            return EXIT_USER_INPUT;
        }

        CLI::write(sprintf(
            '%d account(s) with movements, debit %s, credit %s, %d stock location(s), %d of them not tied.',
            count($snapshot['accounts']),
            $snapshot['totals']['debit'],
            $snapshot['totals']['credit'],
            count($snapshot['stock']),
            count(BooksSnapshot::untiedStock($snapshot)),
        ));

        return EXIT_SUCCESS;
    }
}
