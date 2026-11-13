<?php

namespace App\Commands;

use App\Libraries\Integrations\NotificationEvaluator;
use App\Libraries\Integrations\NotificationRun;
use CodeIgniter\CLI\BaseCommand;
use CodeIgniter\CLI\CLI;
use DateTimeImmutable;

class NotificationsEvaluate extends BaseCommand
{
    protected $group       = 'Integrations';
    protected $name        = 'notifications:evaluate';
    protected $usage       = 'notifications:evaluate [--date YYYY-MM-DD]';
    protected $description = 'Looks for what each active notification rule asks about (invoices overdue by a number of days, items at their reorder level, cheques falling due) and keeps one open in-app notification for each subject: a new subject gets one, a subject that has one gets no second, and one that no longer matches has its notification closed. It is safe to run again and to run twice at once. It sends nothing anywhere: no mail, no message and no call to another server. It does nothing while the notification rules are switched off. Exits 0, and 7 for an option it refuses.';
    protected $options     = [
        '--date' => 'The day to count from instead of today: a day that is not after today.',
    ];

    public function run(array $params): int
    {
        $date = $this->option($params, 'date');

        if ($date !== null && (! $this->isDay($date) || $date > date('Y-m-d'))) {
            CLI::error('The date is a day, YYYY-MM-DD, that is not after today.');

            return EXIT_USER_INPUT;
        }

        $run = (new NotificationEvaluator())->run($date);

        $this->print($run);

        return EXIT_SUCCESS;
    }

    private function print(NotificationRun $run): void
    {
        CLI::write('NOTIFICATIONS as of ' . $run->today);

        if ($run->skipped) {
            CLI::write('The notification rules are switched off: nothing was looked at.', 'yellow');

            return;
        }

        foreach ($run->lines as $line) {
            CLI::write(sprintf('rule %d (%s, %s): %d new, %d closed, %d open%s', $line['rule']->id, $line['rule']->name, $line['rule']->kind->value, $line['created'], $line['resolved'], $line['open'], $line['rule']->active ? '' : ' [switched off]'));
        }

        CLI::write(sprintf('RESULT: %d new, %d closed, %d open. Nothing was sent anywhere.', $run->created(), $run->resolved(), $run->open()));
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
