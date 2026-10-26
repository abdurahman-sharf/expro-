<?php

namespace App\Commands;

use App\Libraries\Accounting\LegacyDiscountFinding;
use App\Libraries\Accounting\LegacyDiscountVerdict;
use App\Libraries\Accounting\LegacyPurchaseDiscount;
use CodeIgniter\CLI\BaseCommand;
use CodeIgniter\CLI\CLI;
use InvalidArgumentException;

class AccountingPurchaseDiscount extends BaseCommand
{
    protected $group       = 'Accounting';
    protected $name        = 'accounting:purchase_discount';
    protected $usage       = 'accounting:purchase_discount [scan | resolve --all-legacy | --none-legacy | --before "YYYY-MM-DD HH:MM:SS" | resolve --clear]';
    protected $description = 'Lists the fixed discounts of purchase lines the migration LegacyFixedPurchaseDiscount would mark as an amount for the whole line, and records what to do with the ones it cannot tell apart. It changes no purchase.';
    protected $options     = [
        '--all-legacy'  => 'resolve: every undecided line was saved as an amount for the whole line.',
        '--none-legacy' => 'resolve: every undecided line was saved as an amount for every unit.',
        '--before'      => 'resolve: undecided lines saved before this moment were saved as an amount for the whole line, the rest for every unit.',
        '--clear'       => 'resolve: forget the recorded decision.',
    ];

    public function run(array $params): int
    {
        $scan   = new LegacyPurchaseDiscount();
        $action = $params[0] ?? 'scan';

        if ($action === 'resolve') {
            $code = $this->resolve($scan, $params);

            if ($code !== EXIT_SUCCESS) {
                return $code;
            }
        } elseif ($action !== 'scan') {
            CLI::error('The action must be scan or resolve.');

            return EXIT_USER_INPUT;
        }

        return $this->report($scan);
    }

    /**
     * @param array<int|string, string|null> $params
     */
    private function resolve(LegacyPurchaseDiscount $scan, array $params): int
    {
        $chosen = array_filter([
            LegacyPurchaseDiscount::ALL    => $this->given($params, 'all-legacy'),
            LegacyPurchaseDiscount::NONE   => $this->given($params, 'none-legacy'),
            LegacyPurchaseDiscount::BEFORE => $this->given($params, 'before'),
            'clear'                        => $this->given($params, 'clear'),
        ]);

        if (count($chosen) !== 1) {
            CLI::error('Give exactly one of --all-legacy, --none-legacy, --before and --clear.');

            return EXIT_USER_INPUT;
        }

        $kind = (string) array_key_first($chosen);

        if ($kind === 'clear') {
            $scan->clearResolution();
            CLI::write('The recorded decision was removed.');

            return EXIT_SUCCESS;
        }

        try {
            $before = $params['before'] ?? CLI::getOption('before');
            $scan->resolve($kind, is_string($before) ? $before : null);
        } catch (InvalidArgumentException $exception) {
            CLI::error($exception->getMessage());

            return EXIT_USER_INPUT;
        }

        CLI::write('The decision was recorded: ' . $this->describe($scan->resolution()) . '.', 'green');

        return EXIT_SUCCESS;
    }

    /**
     * @param array<int|string, string|null> $params
     */
    private function given(array $params, string $option): bool
    {
        return array_key_exists($option, $params) || CLI::getOption($option) !== null;
    }

    private function report(LegacyPurchaseDiscount $scan): int
    {
        $findings = $scan->findings();
        $plan     = $scan->plan();

        if ($findings === []) {
            CLI::write('No purchase line carries a fixed discount over several units: the migration has nothing to mark.', 'green');

            return EXIT_SUCCESS;
        }

        $counts = array_count_values(array_map(static fn (LegacyDiscountFinding $finding): string => $finding->verdict->value, $findings));

        CLI::write(count($findings) . ' purchase line(s) carry a fixed discount over several units:');

        foreach (LegacyDiscountVerdict::cases() as $verdict) {
            CLI::write(sprintf('  %-18s %d', $verdict->value, $counts[$verdict->value] ?? 0));
        }

        $window = LegacyPurchaseDiscount::window($findings);

        CLI::write('Latest saved for the whole line, by the books: ' . ($window['wholeLine'] ?? 'none') . '.');
        CLI::write('Earliest saved for every unit, by the books: ' . ($window['everyUnit'] ?? 'none') . '.');

        if ($window['overlap']) {
            CLI::write('The books show purchases saved both ways in the same period, so no single moment separates them: decide the undecided lines one by one.', 'yellow');
        }

        CLI::write('Recorded decision: ' . $this->describe($scan->resolution()) . '.');
        CLI::write(sprintf('The migration would mark %d line(s), leave %d as they are and stop on %d.', count($plan['mark']), count($plan['keep']), count($plan['blocked'])));

        $undecided = array_filter($findings, static fn (LegacyDiscountFinding $finding): bool => $finding->verdict === LegacyDiscountVerdict::Undetermined);

        if ($undecided !== []) {
            CLI::newLine();
            CLI::write('Lines no evidence decides:', 'yellow');

            foreach ($undecided as $finding) {
                CLI::write(sprintf('  purchase #%d line %d, item %d, %s units, discount %s, saved %s: %s', $finding->receivingId, $finding->line, $finding->itemId, $finding->units, $finding->discount, $finding->savedAt, $finding->reason));
            }
        }

        if ($plan['blocked'] === []) {
            return EXIT_SUCCESS;
        }

        CLI::newLine();
        CLI::error('The migration will stop until the lines above are decided. Decide with "resolve" once the moment this database first ran the discount for every unit is known.');

        return EXIT_ERROR;
    }

    /**
     * @param array{kind: string, before: ?string}|null $resolution
     */
    private function describe(?array $resolution): string
    {
        return match ($resolution['kind'] ?? null) {
            LegacyPurchaseDiscount::ALL    => 'every undecided line is for the whole line',
            LegacyPurchaseDiscount::NONE   => 'every undecided line is for every unit',
            LegacyPurchaseDiscount::BEFORE => 'undecided lines saved before ' . $resolution['before'] . ' are for the whole line, the rest for every unit',
            default                        => 'none',
        };
    }
}
