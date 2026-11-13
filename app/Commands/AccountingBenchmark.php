<?php

namespace App\Commands;

use App\Libraries\Accounting\AccountingSettings;
use App\Libraries\Accounting\Benchmark\AccountingBenchmark as Benchmark;
use CodeIgniter\CLI\BaseCommand;
use CodeIgniter\CLI\CLI;
use Config\Database;
use Throwable;

class AccountingBenchmark extends BaseCommand
{
    private const DEFAULT_DOCUMENTS = 2000;
    private const MAX_DOCUMENTS     = 200000;

    protected $group       = 'Accounting';
    protected $name        = 'accounting:benchmark';
    protected $usage       = 'accounting:benchmark --confirm DATABASE [--documents N] [--machine TEXT]';
    protected $description = 'A development tool, never for a production database: generates N sales documents, saves them through the real sale save with its posting, times the readiness checks, the trial balance, the aged ledger and the report families on them, prints a JSON table of timings and query counts, and rolls every write back. Exits 0 when it ran, 1 when it could not, and 7 for an option it refuses.';
    protected $options     = [
        '--confirm'   => 'The name of the database it is connected to, typed again. Without it, or with another name, nothing runs.',
        '--documents' => 'How many sales documents to generate (default 2000).',
        '--machine'   => 'A text that describes the machine (for example "4 cores, MariaDB local"), kept in the output.',
    ];

    public function run(array $params): int
    {
        if ($this->environment() === 'production') {
            CLI::error('The benchmark never runs where CI_ENVIRONMENT is production.');

            return EXIT_USER_INPUT;
        }

        $database = Database::connect()->getDatabase();

        if (($params['confirm'] ?? CLI::getOption('confirm')) !== $database) {
            CLI::error("Pass --confirm {$database} to say that this is a copy of the data or a development database. It rolls back what it writes, but it holds locks and fills the log of the database while it runs.");

            return EXIT_USER_INPUT;
        }

        $documents = $this->documents($params);

        if ($documents === null) {
            CLI::error('The number of documents is a whole number from 1 to ' . self::MAX_DOCUMENTS . '.');

            return EXIT_USER_INPUT;
        }

        if (! (new AccountingSettings())->isEnabled()) {
            CLI::error('Turn accounting on first (php spark accounting:enable): the benchmark saves sales with their posting.');

            return EXIT_ERROR;
        }

        try {
            $result = (new Benchmark())->run($documents);
        } catch (Throwable $exception) {
            CLI::error($exception->getMessage());

            return EXIT_ERROR;
        }

        $machine = $params['machine'] ?? CLI::getOption('machine');

        if (is_string($machine) && $machine !== '') {
            $result['environment']['machine'] = $machine;
        }

        CLI::write(json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));

        return EXIT_SUCCESS;
    }

    protected function environment(): string
    {
        return ENVIRONMENT;
    }

    /**
     * @param array<int|string, mixed> $params
     */
    private function documents(array $params): ?int
    {
        $given = $params['documents'] ?? CLI::getOption('documents');

        if ($given === null) {
            return self::DEFAULT_DOCUMENTS;
        }

        return is_string($given) && ctype_digit($given) && (int) $given >= 1 && (int) $given <= self::MAX_DOCUMENTS ? (int) $given : null;
    }
}
