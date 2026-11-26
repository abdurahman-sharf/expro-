<?php

namespace App\Commands;

use App\Libraries\Accounting\TaxedDraftJournals;
use CodeIgniter\CLI\BaseCommand;
use CodeIgniter\CLI\CLI;

class AccountingReportTaxedDraftJournals extends BaseCommand
{
    private const COLUMNS = ['journal_id', 'date', 'branch_id', 'description', 'debit', 'credit', 'tax_on_debit', 'tax_on_credit', 'difference_when_posted'];

    protected $group       = 'Accounting';
    protected $name        = 'accounting:report_taxed_draft_journals';
    protected $usage       = 'accounting:report_taxed_draft_journals';
    protected $description = 'Lists the drafts of manual journals that carry a tax on a line: date, branch, the debit and credit as typed, the tax posting adds on each side, and the difference the journal will be out by when it is posted (debit less credit, 0 when it will balance). Reads only and changes nothing.';

    /**
     * @param array<int|string, string|null> $params
     */
    public function run(array $params): int
    {
        $drafts = (new TaxedDraftJournals())->list();

        if ($drafts === []) {
            CLI::write('No draft carries a tax on a line.', 'green');

            return EXIT_SUCCESS;
        }

        CLI::table(array_map('array_values', $drafts), self::COLUMNS);

        $out = count(array_filter($drafts, static fn (array $draft): bool => bccomp($draft['difference_when_posted'], '0', 4) !== 0));

        CLI::write(count($drafts) . " draft(s) carry a tax on a line, {$out} of them will not balance when posted. Nothing was changed.");

        return EXIT_SUCCESS;
    }
}
