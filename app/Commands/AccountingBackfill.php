<?php

namespace App\Commands;

use App\Libraries\Accounting\AccountingException;
use App\Libraries\Accounting\Backfill\BackfillService;
use App\Libraries\Organization\BranchException;
use CodeIgniter\CLI\BaseCommand;
use CodeIgniter\CLI\CLI;
use CodeIgniter\Database\BaseConnection;
use Config\Database;
use Throwable;

class AccountingBackfill extends BaseCommand
{
    private const DEFAULT_LIMIT = 100;

    protected $group       = 'Accounting';
    protected $name        = 'accounting:backfill';
    protected $usage       = 'accounting:backfill --from YYYY-MM-DD --to YYYY-MM-DD [--branch ID] [--families sales,purchases,expenses,cashups] [--chunk N] [--limit N]';
    protected $description = 'Dry run of the retroactive posting, read-only: prints as JSON the documents of the range that have no journal, what each would post as the live posting posts it, why the others are refused, and the fingerprint a preview of the same range would get. Nothing is written, not even a preview; a batch is made and approved from the periods screen. Exits 0, 1 when it could not run and 7 for an option it refuses.';
    protected $options     = [
        '--from'     => 'First day of the range (Y-m-d).',
        '--to'       => 'Last day of the range (Y-m-d).',
        '--branch'   => 'Only the documents of this branch, the whole company when it is left out.',
        '--families' => 'The families to look at, separated by commas (default every one).',
        '--chunk'    => 'Documents read at a time (default 200).',
        '--limit'    => 'How many documents to list in the output (default 100, 0 for none). The summary and the fingerprint always cover every document.',
    ];

    public function run(array $params): int
    {
        $from  = $this->option($params, 'from');
        $to    = $this->option($params, 'to');
        $limit = $this->option($params, 'limit') ?? (string) self::DEFAULT_LIMIT;

        if ($from === null || $to === null || ! ctype_digit($limit)) {
            CLI::error('Give the range with --from and --to, and a whole number for --limit.');

            return EXIT_USER_INPUT;
        }

        $families = $this->option($params, 'families');
        $db       = Database::connect();
        $readOnly = $this->begin($db);

        try {
            $report = (new BackfillService($db))->dryRun([
                'from'       => $from,
                'to'         => $to,
                'branch_id'  => $this->option($params, 'branch'),
                'families'   => $families === null ? null : array_values(array_filter(array_map('trim', explode(',', $families)))),
                'chunk_size' => $this->option($params, 'chunk'),
            ], null, (int) $limit);
        } catch (AccountingException|BranchException $refusal) {
            CLI::error($refusal->getMessage());

            return EXIT_USER_INPUT;
        } catch (Throwable $failure) {
            CLI::error('The dry run could not run: ' . $failure->getMessage());

            return EXIT_ERROR;
        } finally {
            $db->transRollback();
            $db->resetTransStatus();
        }

        CLI::write((string) json_encode(['read_only' => $readOnly] + $report, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));

        return EXIT_SUCCESS;
    }

    private function option(array $params, string $name): ?string
    {
        $value = $params[$name] ?? CLI::getOption($name);

        return is_string($value) && $value !== '' ? $value : null;
    }

    /**
     * Opens the transaction the run reads inside and says whether the database refuses a write in it, which is
     * asked of the database with a write that changes nothing and not assumed from the statement that was sent.
     */
    private function begin(BaseConnection $db): bool
    {
        try {
            $db->query('SET TRANSACTION ISOLATION LEVEL REPEATABLE READ, READ ONLY');
        } catch (Throwable) {
        }

        $db->resetTransStatus();
        $db->transStart();

        try {
            $written = $db->query('UPDATE ' . $db->prefixTable('app_config') . " SET `value` = `value` WHERE `key` = ''");
        } catch (Throwable) {
            $written = false;
        }

        $db->resetTransStatus();

        return $written === false;
    }
}
