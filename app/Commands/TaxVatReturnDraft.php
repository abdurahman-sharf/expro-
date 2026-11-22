<?php

namespace App\Commands;

use App\Libraries\Tax\VatReturnDraft;
use CodeIgniter\CLI\BaseCommand;
use CodeIgniter\CLI\CLI;

class TaxVatReturnDraft extends BaseCommand
{
    protected $group       = 'Tax';
    protected $name        = 'tax:vat_return_draft';
    protected $usage       = 'tax:vat_return_draft <from YYYY-MM-DD> <to YYYY-MM-DD>';
    protected $description = 'Works out the draft of the VAT return of a period from the books, box by box, for an accountant to review, with what is not classified and what keeps it from being approved. Nothing is saved and nothing is sent to ZATCA.';

    public function run(array $params): int
    {
        [$from, $to] = [(string) ($params[0] ?? ''), (string) ($params[1] ?? '')];

        if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $from) !== 1 || preg_match('/^\d{4}-\d{2}-\d{2}$/', $to) !== 1 || strcmp($from, $to) > 0) {
            CLI::error('Give the first and the last day of the period as YYYY-MM-DD.');

            return EXIT_ERROR;
        }

        $draft = (new VatReturnDraft())->build($from, $to);

        foreach ($draft['boxes'] as $box => $figures) {
            CLI::write(sprintf('%3s %16s %16s%s', $box, $figures['amount'], $figures['vat'], in_array((string) $box, VatReturnDraft::MANUAL, true) ? '  (typed by hand)' : ''));
        }

        CLI::write('Not classified (sales without tax saved before the Saudi rules, in no box until reviewed): ' . $draft['unclassified']);
        CLI::write('Documents whose lines do not match their journals: ' . $draft['unmatched']);
        CLI::write($draft['blockers'] === [] ? 'Nothing keeps this draft from being approved once the books are closed.' : 'To review before approving: ' . implode(', ', $draft['blockers']) . '.', $draft['blockers'] === [] ? 'green' : 'yellow');

        return EXIT_SUCCESS;
    }
}
