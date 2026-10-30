<?php

namespace App\Commands;

use App\Libraries\Accounting\Readiness\CheckResult;
use App\Libraries\Accounting\Readiness\CheckStatus;
use App\Libraries\Accounting\Readiness\ReadinessResult;
use App\Libraries\Accounting\Readiness\ReadinessRunbook;
use App\Libraries\Accounting\Readiness\ReadinessRunner;
use CodeIgniter\CLI\BaseCommand;
use CodeIgniter\CLI\CLI;

class AccountingReadiness extends BaseCommand
{
    private const DEFAULT_LIMIT = 20;

    protected $group       = 'Accounting';
    protected $name        = 'accounting:readiness';
    protected $usage       = 'accounting:readiness [--json] [--limit N] [--apply]';
    protected $description = 'Runs every check of an owner database read-only (historical data, the books, the ties between accounts, the grants, the activation state and the migrations) and ends with the runbook. Exits 0 only when nothing failed and nothing broke, and 1 otherwise.';
    protected $options     = [
        '--json'  => 'Print the report as JSON and nothing else.',
        '--limit' => 'How many findings to print for each check (default 20, 0 for all). The JSON always has all of them.',
        '--apply' => 'After the report, run the existing commands that clear what failed, each with its own apply flag (accounting:stamp_payments --apply and accounting:bank_accounts --move), then report again. Nothing else is ever written. Not with --json.',
    ];

    public function run(array $params): int
    {
        $json  = $this->given($params, 'json');
        $apply = $this->given($params, 'apply');
        $limit = $this->limit($params);

        if ($limit === null) {
            CLI::error('The limit must be a whole number, 0 for all.');

            return EXIT_USER_INPUT;
        }

        if ($json && $apply) {
            CLI::error('--apply prints what the commands it runs print: run it first, then ask for --json.');

            return EXIT_USER_INPUT;
        }

        $runner = new ReadinessRunner();
        $result = $runner->run();

        if ($apply) {
            $result = $this->apply($runner, $result);
        }

        if ($json) {
            CLI::write(json_encode([...$result->toArray(), 'runbook' => ReadinessRunbook::steps($result->pendingMigrations)], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));
        } else {
            $this->print($result, $limit);
        }

        return $result->ready() ? EXIT_SUCCESS : EXIT_ERROR;
    }

    private function apply(ReadinessRunner $runner, ReadinessResult $result): ReadinessResult
    {
        $commands = $result->applicable();

        if ($commands === []) {
            CLI::write('--apply: nothing the existing commands could clear.');

            return $result;
        }

        foreach ($commands as $command) {
            CLI::write('--apply: ' . trim($command['command'] . ' ' . implode(' ', array_map(static fn (string $flag): string => '--' . $flag, array_keys($command['params'])))), 'yellow');
            $this->call($command['command'], $command['params']);
        }

        CLI::newLine();

        return $runner->run();
    }

    private function print(ReadinessResult $result, int $limit): void
    {
        CLI::write('ACCOUNTING READINESS');
        CLI::write(sprintf('Database %s, %s, phase %s.', $result->database, $result->generatedAt, $result->phase()));
        CLI::write($result->readOnly ? 'Every check ran inside a read-only transaction: nothing was written.' : 'The connection could not open a read-only transaction: the checks only read, but the database does not enforce it.', $result->readOnly ? null : 'yellow');
        CLI::newLine();

        foreach ($result->checks as $check) {
            $this->printCheck($check, $limit);
        }

        $counts = $result->counts();

        CLI::write(sprintf(
            'RESULT: %s. %d failed, %d broke, %d for review, %d skipped, %d passed.',
            $result->ready() ? 'READY' : 'NOT READY',
            $counts[CheckStatus::Fail->value],
            $counts[CheckStatus::Error->value],
            $counts[CheckStatus::Review->value],
            $counts[CheckStatus::Skipped->value],
            $counts[CheckStatus::Pass->value],
        ), $result->ready() ? 'green' : 'red');
        CLI::newLine();

        $this->printRunbook($result);
    }

    private function printCheck(CheckResult $check, int $limit): void
    {
        $color = match ($check->status) {
            CheckStatus::Pass => 'green',
            CheckStatus::Fail, CheckStatus::Error => 'red',
            CheckStatus::Review  => 'yellow',
            CheckStatus::Skipped => 'light_gray',
        };

        CLI::write(sprintf('%-8s %s: %s', strtoupper($check->status->value), $check->ref, $check->title), $color);
        CLI::write('         ' . $check->summary);

        $shown = $limit === 0 ? $check->findings : array_slice($check->findings, 0, $limit);

        foreach ($shown as $finding) {
            CLI::write('           - ' . $finding);
        }

        if (count($shown) < count($check->findings)) {
            CLI::write('           ... and ' . (count($check->findings) - count($shown)) . ' more (--limit 0 or --json for all)');
        }

        if ($check->status === CheckStatus::Fail && $check->applyCommand() !== null) {
            CLI::write('         clears with: ' . $check->applyCommand() . ' (or accounting:readiness --apply)');
        }
    }

    private function printRunbook(ReadinessResult $result): void
    {
        CLI::write('RUNBOOK (in this order)');

        foreach (ReadinessRunbook::steps($result->pendingMigrations) as $number => $step) {
            CLI::newLine();
            CLI::write(($number + 1) . '. ' . $step['title']);

            foreach ($step['commands'] as $command) {
                CLI::write('     $ ' . $command);
            }

            foreach ($step['notes'] as $note) {
                CLI::write('     - ' . $note);
            }
        }
    }

    /**
     * @param array<int|string, string|null> $params
     */
    private function given(array $params, string $option): bool
    {
        return array_key_exists($option, $params) || CLI::getOption($option) !== null;
    }

    /**
     * @param array<int|string, string|null> $params
     */
    private function limit(array $params): ?int
    {
        $value = $params['limit'] ?? CLI::getOption('limit');

        foreach ($params as $key => $given) {
            if (is_string($key) && str_starts_with($key, 'limit=')) {
                $value = substr($key, strlen('limit='));
            }
        }

        if ($value === null || $value === true) {
            return self::DEFAULT_LIMIT;
        }

        return ctype_digit((string) $value) ? (int) $value : null;
    }
}
