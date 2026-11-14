<?php

namespace App\Commands;

use App\Libraries\Accounting\Readiness\CheckDifference;
use App\Libraries\Accounting\Readiness\ComparisonResult;
use App\Libraries\Accounting\Readiness\ReadinessComparison;
use CodeIgniter\CLI\BaseCommand;
use CodeIgniter\CLI\CLI;
use InvalidArgumentException;

class AccountingReadinessCompare extends BaseCommand
{
    private const DEFAULT_LIMIT = 20;
    private const FLAG_OPTIONS  = ['json', 'no-header'];

    protected $group       = 'Accounting';
    protected $name        = 'accounting:readiness_compare';
    protected $usage       = 'accounting:readiness_compare BEFORE.json AFTER.json [--json] [--no-header] [--limit N]';
    protected $description = 'Compares the report taken before the migrations with the one taken after (both from accounting:readiness --json --no-header) and flags every value of a check\'s data that changed, a check that turned to FAIL or ERROR, and a purchase discount count that the migration did not match. Reads two files and no database. Put the two file names before the options. Exits 0 when nothing is flagged, 1 when something is, and 7 for a file or an option it refuses. A second report that still says pre-migration, and two files that are one report, count as flagged: they cannot show what the migrations changed.';
    protected $options     = [
        '--json'  => 'Print the comparison as JSON and nothing else (add spark\'s own --no-header, or its banner comes first).',
        '--limit' => 'How many changed values to print for each check (default 20, 0 for all). The JSON has all of them.',
    ];

    /**
     * @param array<int|string, string|null> $params
     */
    public function run(array $params): int
    {
        $files = array_values(array_filter($params, static fn (mixed $key): bool => is_int($key), ARRAY_FILTER_USE_KEY));
        $limit = $this->limit($params);

        foreach (self::FLAG_OPTIONS as $flag) {
            if (($params[$flag] ?? null) !== null) {
                CLI::error(sprintf('--%s took "%s" for its value, and that file name is lost: put the two file names first and the options after them (accounting:readiness_compare readiness-before.json readiness-after.json --json --no-header).', $flag, $params[$flag]));

                return EXIT_USER_INPUT;
            }
        }

        if ($limit === null) {
            CLI::error('The limit must be a whole number, 0 for all.');

            return EXIT_USER_INPUT;
        }

        if (count($files) !== 2) {
            CLI::error('Name the report taken before the migrations and the one taken after, then the options: accounting:readiness_compare readiness-before.json readiness-after.json');

            return EXIT_USER_INPUT;
        }

        try {
            $before = $this->read((string) $files[0]);
            $after  = $this->read((string) $files[1]);
        } catch (InvalidArgumentException $refusal) {
            CLI::error($refusal->getMessage());

            return EXIT_USER_INPUT;
        }

        $result = ReadinessComparison::compare($before, $after);

        if (array_key_exists('json', $params) || CLI::getOption('json') !== null) {
            CLI::write(json_encode([...$result->toArray(), 'before' => $this->described($before), 'after' => $this->described($after)], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));

            return $result->flagged() ? EXIT_ERROR : EXIT_SUCCESS;
        }

        $this->printReport($result, $before, $after, $limit, (string) $files[0], (string) $files[1]);

        return $result->flagged() ? EXIT_ERROR : EXIT_SUCCESS;
    }

    /**
     * @return array<string, mixed>
     *
     * @throws InvalidArgumentException
     */
    private function read(string $path): array
    {
        if (! is_file($path) || ! is_readable($path)) {
            throw new InvalidArgumentException("{$path} is not a file that can be read.");
        }

        try {
            return ReadinessComparison::decode((string) file_get_contents($path));
        } catch (InvalidArgumentException $refusal) {
            throw new InvalidArgumentException("{$path}: " . $refusal->getMessage());
        }
    }

    /**
     * @param array<string, mixed> $report
     *
     * @return array<string, mixed>
     */
    private function described(array $report): array
    {
        return ['database' => $report['database'] ?? null, 'generated_at' => $report['generated_at'] ?? null, 'phase' => $report['phase'] ?? null];
    }

    /**
     * @param array<string, mixed> $before
     * @param array<string, mixed> $after
     */
    private function printReport(ComparisonResult $result, array $before, array $after, int $limit, string $beforeFile, string $afterFile): void
    {
        CLI::write('READINESS COMPARISON');
        CLI::write($this->line('Before', $beforeFile, $before));
        CLI::write($this->line('After', $afterFile, $after));
        CLI::newLine();

        foreach ($result->blockers as $blocker) {
            CLI::write('BLOCKED  ' . $blocker, 'red');
        }

        foreach ($result->notes as $note) {
            CLI::write('NOTE     ' . $note, 'yellow');
        }

        foreach ($result->differences as $difference) {
            if ($difference->listed()) {
                $this->printDifference($difference, $limit);
            }
        }

        $flagged = $result->flaggedChecks();

        CLI::newLine();

        if ($result->blockers !== []) {
            CLI::write(sprintf('RESULT: THIS COMPARISON PROVES NOTHING (%d reason(s) above). Take the reports again before going on.', count($result->blockers)), 'red');
        }

        if ($flagged !== []) {
            CLI::write(sprintf('RESULT: DIFFERENCES FLAGGED in %d of %d check(s). Look at each before going on: a migration may move an amount between buckets but not change what a party owes.', count($flagged), count($result->differences)), 'red');
        } elseif ($result->blockers === []) {
            CLI::write(
                sprintf('RESULT: NO DIFFERENCE FLAGGED. %d check(s) compared; nothing that a migration is not expected to change differs.', count($result->differences)) . ($result->notes === [] ? '' : ' Read the NOTE line(s) above: they say what this comparison cannot show.'),
                $result->notes === [] ? 'green' : 'yellow',
            );
        }
    }

    /**
     * @param array<string, mixed> $report
     */
    private function line(string $label, string $file, array $report): string
    {
        return sprintf('%-6s %s: database %s, %s, phase %s.', $label, $file, $report['database'] ?? '?', $report['generated_at'] ?? '?', $report['phase'] ?? '?');
    }

    private function printDifference(CheckDifference $difference, int $limit): void
    {
        $label = $difference->flagged() ? 'FLAGGED' : ($difference->notComparable !== null ? 'SKIPPED' : 'EXPECTED');
        $color = $difference->flagged() ? 'red' : 'light_gray';

        CLI::write(sprintf('%-8s %s (%s): %s', $label, $difference->ref === '' ? $difference->id : $difference->ref, $difference->id, $difference->title), $color);

        if ($difference->statusBefore !== $difference->statusAfter) {
            CLI::write('         status ' . ($difference->statusBefore ?? 'absent') . ' -> ' . ($difference->statusAfter ?? 'absent'));
        }

        if ($difference->notComparable !== null) {
            CLI::write('         not compared: ' . $difference->notComparable);
        }

        foreach ($difference->problems as $problem) {
            CLI::write('         ! ' . $problem, 'red');
        }

        $shown = $limit === 0 ? $difference->changes : array_slice($difference->changes, 0, $limit);

        foreach ($shown as $change) {
            CLI::write(sprintf('           - %s: %s -> %s', $change['path'], $this->show($change['before']), $this->show($change['after'])));
        }

        if (count($shown) < count($difference->changes)) {
            CLI::write('           ... and ' . (count($difference->changes) - count($shown)) . ' more (--limit 0 or --json for all)');
        }

        if ($difference->expected !== []) {
            CLI::write('         ' . count($difference->expected) . ' value(s) changed as the migrations are expected to change them: ' . $difference->expected[0]['why'] . '.');
        }
    }

    private function show(mixed $value): string
    {
        return is_scalar($value) || $value === null ? (string) json_encode($value, JSON_UNESCAPED_UNICODE) : '[...]';
    }

    /**
     * @param array<int|string, string|null> $params
     */
    private function limit(array $params): ?int
    {
        $given = array_key_exists('limit', $params);
        $value = $params['limit'] ?? null;

        foreach (array_keys($params) as $key) {
            if (is_string($key) && str_starts_with($key, 'limit=')) {
                $given = true;
                $value = substr($key, strlen('limit='));
            }
        }

        if (! $given) {
            return self::DEFAULT_LIMIT;
        }

        return ctype_digit((string) $value) ? (int) $value : null;
    }
}
