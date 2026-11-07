<?php

namespace App\Commands;

use App\Libraries\Accounting\AccountingException;
use App\Libraries\Accounting\AccountingMessage;
use App\Libraries\Recurring\GenerationReport;
use App\Libraries\Recurring\RecurringGenerator;
use App\Libraries\Recurring\RunResult;
use CodeIgniter\CLI\BaseCommand;
use CodeIgniter\CLI\CLI;

class AccountingGenerateRecurring extends BaseCommand
{
    protected $group       = 'Accounting';
    protected $name        = 'accounting:generate_recurring';
    protected $usage       = 'accounting:generate_recurring [--schedule ID] [--cap N] [--date YYYY-MM-DD]';
    protected $description = 'Makes the invoices and the expenses of the dates of the active schedules that have come, in order, up to a cap for each schedule. It is safe to run again and to run twice at once: a date has one document. A date that is refused (a closed period, no stock, a credit limit) is recorded with its reason and the schedule goes on. Exits 0 when nothing was refused and every schedule is caught up, 1 otherwise, and 7 for an option it refuses.';
    protected $options     = [
        '--schedule' => 'Only this schedule.',
        '--cap'      => 'The most dates made for one schedule in one run (default 12).',
        '--date'     => 'The day to count from instead of today: a day that is not after today.',
    ];

    public function run(array $params): int
    {
        $schedule = $this->option($params, 'schedule');
        $cap      = $this->option($params, 'cap');
        $date     = $this->option($params, 'date');

        if (($schedule !== null && ! ctype_digit($schedule)) || ($cap !== null && (! ctype_digit($cap) || (int) $cap < 1))) {
            CLI::error('The schedule and the cap are whole numbers, the cap at least 1.');

            return EXIT_USER_INPUT;
        }

        if ($date !== null && (preg_match('/^\d{4}-\d{2}-\d{2}$/', $date) !== 1 || $date > date('Y-m-d'))) {
            CLI::error('The date is a day, YYYY-MM-DD, that is not after today.');

            return EXIT_USER_INPUT;
        }

        try {
            $report = (new RecurringGenerator())->run($date, $schedule === null ? null : (int) $schedule, $cap === null ? RecurringGenerator::DEFAULT_CAP : (int) $cap);
        } catch (AccountingException $failure) {
            CLI::error(AccountingMessage::of($failure));

            return EXIT_ERROR;
        }

        $this->print($report);

        return $report->refused() === 0 && $report->capped === [] && $report->count(RunResult::BUSY) === 0 ? EXIT_SUCCESS : EXIT_ERROR;
    }

    private function print(GenerationReport $report): void
    {
        CLI::write('RECURRING DOCUMENTS as of ' . $report->today);

        foreach ($report->results as $result) {
            $line = match ($result->outcome) {
                RunResult::GENERATED => sprintf('schedule %d, %s: made %s', $result->scheduleId, $result->dueDate, $result->saleId !== null ? 'sale ' . $result->saleId : 'expense ' . $result->expenseId),
                RunResult::REFUSED   => sprintf('schedule %d, %s: REFUSED (%s) %s', $result->scheduleId, $result->dueDate, $result->refusal?->error, $result->refusal?->words()),
                RunResult::EXISTING  => sprintf('schedule %d, %s: already made', $result->scheduleId, $result->dueDate),
                RunResult::ENDED     => sprintf('schedule %d: ended', $result->scheduleId),
                default              => sprintf('schedule %d: busy, left for the next run', $result->scheduleId),
            };

            CLI::write($line, $result->outcome === RunResult::REFUSED ? 'red' : ($result->outcome === RunResult::BUSY ? 'yellow' : null));
        }

        foreach ($report->capped as $scheduleId) {
            CLI::write(sprintf('schedule %d: more dates are due than the cap, run again to go on', $scheduleId), 'yellow');
        }

        CLI::write(sprintf('RESULT: %d made, %d refused, %d already made, %d ended, %d busy, %d over the cap.', $report->generated(), $report->refused(), $report->count(RunResult::EXISTING), $report->count(RunResult::ENDED), $report->count(RunResult::BUSY), count($report->capped)));
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
}
