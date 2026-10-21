<?php

namespace App\Commands;

use App\Libraries\Accounting\AccountingNotReadyException;
use App\Libraries\Accounting\AccountingSettings;
use App\Models\Appconfig;
use CodeIgniter\CLI\BaseCommand;
use CodeIgniter\CLI\CLI;
use Config\Database;
use DateTimeImmutable;

/**
 * Interim way to configure and enable accounting until its settings screens exist.
 */
class AccountingEnable extends BaseCommand
{
    protected $group       = 'Accounting';
    protected $name        = 'accounting:enable';
    protected $usage       = 'accounting:enable [--currency=SAR] [--start-date=YYYY-MM-DD] [--fiscal-year-end=YYYY-MM-DD]';
    protected $description = 'Sets the base currency and start date, creates the first fiscal year when none exists, and enables accounting once its settings are complete.';
    protected $options     = [
        '--currency'        => 'Three-letter base currency code.',
        '--start-date'      => 'First day accounting covers.',
        '--fiscal-year-end' => 'Last day of the first fiscal year. Defaults to the end of the start date\'s year.',
    ];

    public function run(array $params): void
    {
        $config = model(Appconfig::class);

        $currency = $params['currency'] ?? CLI::getOption('currency');
        $start    = $params['start-date'] ?? CLI::getOption('start-date');
        $end      = $params['fiscal-year-end'] ?? CLI::getOption('fiscal-year-end');

        if (is_string($currency)) {
            if (preg_match('/^[A-Z]{3}$/', $currency) !== 1) {
                CLI::error('The currency must be three capital letters.');

                return;
            }

            $config->save(['accounting_base_currency' => $currency]);
        }

        if (is_string($start)) {
            if (! $this->isDate($start)) {
                CLI::error('The start date must be a valid YYYY-MM-DD date.');

                return;
            }

            $config->save(['accounting_start_date' => $start]);
            $this->createFirstFiscalYear($start, is_string($end) ? $end : null);
        }

        try {
            (new AccountingSettings())->enable();
        } catch (AccountingNotReadyException $exception) {
            CLI::error('Accounting cannot be enabled yet:');

            foreach ($exception->issues as $issue) {
                CLI::write(' - ' . $issue->code->value . ($issue->subject === null ? '' : " ({$issue->subject})"));
            }

            return;
        }

        CLI::write('Accounting is enabled.', 'green');
    }

    private function createFirstFiscalYear(string $start, ?string $end): void
    {
        $db = Database::connect();

        if ($db->table('accounting_fiscal_years')->countAllResults() > 0) {
            return;
        }

        $end ??= substr($start, 0, 4) . '-12-31';

        if (! $this->isDate($end) || $end < $start) {
            CLI::error('The fiscal year end must be a valid date on or after the start date.');

            return;
        }

        $db->table('accounting_fiscal_years')->insert([
            'name'       => substr($start, 0, 4),
            'start_date' => $start,
            'end_date'   => $end,
        ]);

        CLI::write("Created the fiscal year {$start} to {$end}.");
    }

    private function isDate(string $value): bool
    {
        $parsed = DateTimeImmutable::createFromFormat('!Y-m-d', $value);

        return $parsed !== false && $parsed->format('Y-m-d') === $value;
    }
}
