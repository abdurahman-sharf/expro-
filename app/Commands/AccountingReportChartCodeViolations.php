<?php

namespace App\Commands;

use App\Libraries\Accounting\ChartCodeViolations;
use CodeIgniter\CLI\BaseCommand;
use CodeIgniter\CLI\CLI;

class AccountingReportChartCodeViolations extends BaseCommand
{
    protected $group       = 'Accounting';
    protected $name        = 'accounting:report_chart_code_violations';
    protected $usage       = 'accounting:report_chart_code_violations';
    protected $description = 'Lists the accounts whose code does not start with the code of their parent and go on after it: code, name, parent, and whether the account has journal lines. Reads only and changes nothing. Exits 0 when there is none and 1 when there is at least one.';

    /**
     * @param array<int|string, string|null> $params
     */
    public function run(array $params): int
    {
        $violations = (new ChartCodeViolations())->list();

        if ($violations === []) {
            CLI::write('The code of every account starts with the code of its parent.', 'green');

            return EXIT_SUCCESS;
        }

        CLI::table(
            array_map(static fn (array $row): array => [$row['code'], $row['name'], $row['parent_code'] . ' ' . $row['parent_name'], $row['has_journal_lines'] ? 'yes' : 'no'], $violations),
            ['code', 'name', 'parent', 'has_journal_lines'],
        );
        CLI::write(count($violations) . ' account(s) have a code that does not start with the code of their parent. Nothing was changed.');

        return EXIT_ERROR;
    }
}
