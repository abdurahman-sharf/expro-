<?php

namespace App\Commands;

use App\Libraries\Accounting\Ai\AnomalyScanner;
use App\Libraries\Accounting\AccountingException;
use App\Libraries\Accounting\AccountingMessage;
use CodeIgniter\CLI\BaseCommand;
use CodeIgniter\CLI\CLI;
use DateTimeImmutable;

class AccountingAiScan extends BaseCommand
{
    protected $group       = 'Accounting';
    protected $name        = 'accounting:ai_scan';
    protected $usage       = 'accounting:ai_scan [--since YYYY-MM-DD] [--date YYYY-MM-DD]';
    protected $description = 'Runs the rules of the anomaly scan over the books and keeps what they find as findings for a person to look at. It only reads the books and writes the findings and its own line in the audit log: it never posts and never blocks. A finding is kept once however often the scan runs, and one that a person decided stays as decided. It refuses, naming the reason, while the AI agent is switched off or accounting is off. Exits 0 when it ran, 1 when it was refused, and 7 for an option it refuses.';
    protected $options     = [
        '--since' => 'The first day to look at instead of the days of the lookback setting back from today.',
        '--date'  => 'The day to count as today.',
    ];

    public function run(array $params): int
    {
        $since = $this->option($params, 'since');
        $date  = $this->option($params, 'date');

        if (($since !== null && ! $this->isDay($since)) || ($date !== null && ! $this->isDay($date))) {
            CLI::error('The dates are days, YYYY-MM-DD.');

            return EXIT_USER_INPUT;
        }

        try {
            $report = (new AnomalyScanner())->scan(null, $date === null ? null : new DateTimeImmutable($date), $since);
        } catch (AccountingException $failure) {
            CLI::error(AccountingMessage::of($failure));

            return EXIT_ERROR;
        }

        CLI::write('AI SCAN since ' . $report->since);

        foreach ($report->created as $rule => $created) {
            CLI::write(sprintf('%s: %d new, %d already known', $rule, $created, $report->existing[$rule] ?? 0));
        }

        CLI::write(sprintf('RESULT: %d new, %d already known.', $report->createdCount(), $report->existingCount()));

        return EXIT_SUCCESS;
    }

    /**
     * @param array<int|string, string|null> $params
     */
    private function option(array $params, string $name): ?string
    {
        $value = $params[$name] ?? CLI::getOption($name);

        foreach (array_keys($params) as $key) {
            if (is_string($key) && str_starts_with($key, $name . '=')) {
                $value = substr($key, strlen($name) + 1);
            }
        }

        return is_string($value) && $value !== '' ? $value : null;
    }

    private function isDay(string $date): bool
    {
        $parsed = DateTimeImmutable::createFromFormat('!Y-m-d', $date);

        return $parsed !== false && $parsed->format('Y-m-d') === $date;
    }
}
