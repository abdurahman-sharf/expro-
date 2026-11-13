<?php

namespace App\Commands;

use App\Libraries\Accounting\AccountingException;
use App\Libraries\Accounting\AccountingMessage;
use App\Libraries\Accounting\Ai\ProposalRunner;
use CodeIgniter\CLI\BaseCommand;
use CodeIgniter\CLI\CLI;
use DateTimeImmutable;

class AccountingAiPropose extends BaseCommand
{
    protected $group       = 'Accounting';
    protected $name        = 'accounting:ai_propose';
    protected $usage       = 'accounting:ai_propose [--date YYYY-MM-DD]';
    protected $description = 'Expires the proposals that are past their time and runs the local rule-based proposers over the books, turning what they suggest into proposals for a person to approve or reject. It posts nothing: a proposal becomes a journal only when a person approves it. A suggestion made already is left alone, so it is safe to run again. It refuses, naming the reason, while the AI agent is switched off or accounting is off. Exits 0 when it ran with nothing refused, 1 when it was refused or a suggestion failed the checks of a manual journal, and 7 for an option it refuses.';
    protected $options     = [
        '--date' => 'The day to count as today.',
    ];

    public function run(array $params): int
    {
        $date = $params['date'] ?? CLI::getOption('date');

        foreach (array_keys($params) as $key) {
            if (is_string($key) && str_starts_with($key, 'date=')) {
                $date = substr($key, 5);
            }
        }

        $date = is_string($date) && $date !== '' ? $date : null;

        if ($date !== null) {
            $parsed = DateTimeImmutable::createFromFormat('!Y-m-d', $date);

            if ($parsed === false || $parsed->format('Y-m-d') !== $date) {
                CLI::error('The date is a day, YYYY-MM-DD.');

                return EXIT_USER_INPUT;
            }
        }

        try {
            $report = (new ProposalRunner())->run(null, $date === null ? null : new DateTimeImmutable($date));
        } catch (AccountingException $failure) {
            CLI::error(AccountingMessage::of($failure));

            return EXIT_ERROR;
        }

        CLI::write('AI PROPOSERS');

        foreach ($report->created as $proposer => $created) {
            CLI::write(sprintf('%s: %d made, %d standing already', $proposer, $created, $report->duplicates[$proposer] ?? 0));
        }

        foreach ($report->refused as $refusal) {
            CLI::write(sprintf('%s %s: REFUSED %s', $refusal['proposer'], $refusal['key'], $refusal['reason']), 'red');
        }

        CLI::write(sprintf('RESULT: %d made, %d standing already, %d refused, %d expired.', $report->createdCount(), $report->duplicateCount(), count($report->refused), $report->expired));

        return $report->refused === [] ? EXIT_SUCCESS : EXIT_ERROR;
    }
}
