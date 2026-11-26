<?php

namespace App\Commands;

use App\Libraries\Accounting\BooksSnapshot;
use CodeIgniter\CLI\BaseCommand;
use CodeIgniter\CLI\CLI;
use JsonException;

class AccountingBooksCompare extends BaseCommand
{
    protected $group       = 'Accounting';
    protected $name        = 'accounting:books_compare';
    protected $usage       = 'accounting:books_compare BEFORE.json AFTER.json';
    protected $description = 'Compares two files written by accounting:books_snapshot, company by company, account by account, branch by branch and stock location by stock location, and lists every figure that differs. Reads the two files and no database. Exits 0 when the two agree, 1 when anything differs, and 7 for a file it cannot read.';

    /**
     * @param array<int|string, string|null> $params
     */
    public function run(array $params): int
    {
        $snapshots = [];

        foreach ([0, 1] as $position) {
            $snapshot = $this->read($params[$position] ?? null);

            if ($snapshot === null) {
                CLI::error('Name two snapshot files written by accounting:books_snapshot: the one taken before, then the one taken after.');

                return EXIT_USER_INPUT;
            }

            $snapshots[] = $snapshot;
        }

        $differences = BooksSnapshot::differences($snapshots[0], $snapshots[1]);

        foreach ($differences as $difference) {
            CLI::write(sprintf('company %s %s %s %s: %s -> %s', $difference['company'], $difference['what'], $difference['id'], $difference['field'], $difference['before'] ?? 'absent', $difference['after'] ?? 'absent'));
        }

        if ($differences !== []) {
            CLI::error(count($differences) . ' figure(s) differ.');

            return EXIT_ERROR;
        }

        CLI::write(sprintf('The two snapshots agree on all %d compan%s.', count($snapshots[1]['companies']), count($snapshots[1]['companies']) === 1 ? 'y' : 'ies'), 'green');

        return EXIT_SUCCESS;
    }

    /**
     * @return array<string, mixed>|null
     */
    private function read(mixed $file): ?array
    {
        if (! is_string($file) || ! is_file($file)) {
            return null;
        }

        try {
            $snapshot = json_decode((string) file_get_contents($file), true, 16, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            return null;
        }

        return is_array($snapshot) && is_array($snapshot['companies'] ?? null) && $snapshot['companies'] !== [] ? $snapshot : null;
    }
}
