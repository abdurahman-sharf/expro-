<?php

namespace App\Commands;

use App\Libraries\Tax\HistoryAudit;
use CodeIgniter\CLI\BaseCommand;
use CodeIgniter\CLI\CLI;

class TaxAuditHistory extends BaseCommand
{
    protected $group       = 'Tax';
    protected $name        = 'tax:audit_history';
    protected $usage       = 'tax:audit_history [--json]';
    protected $description = 'Lists, without changing anything, what the books hold against the Saudi rules: sales completed under them without a document, returns without an origin, sales without their VAT worked out, advances without a prepayment invoice, and the sales made before the rules.';
    protected $options     = ['--json' => 'Writes the findings as JSON.'];

    public function run(array $params): int
    {
        $findings = (new HistoryAudit())->run();

        if (array_key_exists('json', $params) || CLI::getOption('json') !== null) {
            CLI::write((string) json_encode($findings, JSON_PRETTY_PRINT));
        } elseif ($findings === []) {
            CLI::write('The Saudi rules were never turned on: there is nothing to audit.', 'green');
        } else {
            foreach ($findings as $finding) {
                $line = sprintf('%-40s %-7s %8d  %s', $finding['key'], $finding['kind'], $finding['count'], implode(', ', $finding['sample']));

                CLI::write($line, $finding['kind'] === HistoryAudit::BREACH && $finding['count'] > 0 ? 'red' : null);
            }
        }

        return HistoryAudit::breached($findings) ? EXIT_ERROR : EXIT_SUCCESS;
    }
}
