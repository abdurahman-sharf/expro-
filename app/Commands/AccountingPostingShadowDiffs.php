<?php

namespace App\Commands;

use App\Libraries\Accounting\Engine\ShadowComparison;
use CodeIgniter\CLI\BaseCommand;
use CodeIgniter\CLI\CLI;

class AccountingPostingShadowDiffs extends BaseCommand
{
    protected $group       = 'Accounting';
    protected $name        = 'accounting:posting_shadow_diffs';
    protected $usage       = 'accounting:posting_shadow_diffs [OPERATION] [--limit N]';
    protected $description = 'Lists, newest first, the documents whose journal the posting engine would have built differently while their operation is posted in shadow mode: the document, the lines only the code posted, the lines only the engine built (each as account|side|amount|branch|cost center), and a failure of the engine. Reads only. Exits 0 when there is none and 1 when there is at least one.';
    protected $options     = [
        '--limit' => 'How many to list (default 100).',
    ];

    /**
     * @param array<int|string, string|null> $params
     */
    public function run(array $params): int
    {
        $operation = isset($params[0]) && $params[0] !== '' ? $params[0] : null;
        $limit     = $params['limit'] ?? CLI::getOption('limit');
        $limit     = is_string($limit) && ctype_digit($limit) && (int) $limit > 0 ? (int) $limit : 100;
        $diffs     = (new ShadowComparison())->recorded($operation, $limit);

        if ($diffs === []) {
            CLI::write('The engine agreed with every journal posted in shadow mode.', 'green');

            return EXIT_SUCCESS;
        }

        foreach ($diffs as $diff) {
            CLI::write(sprintf('#%d %s %s %s revision %d, branch %s, %s', $diff['diff_id'], $diff['operation'], $diff['source_type'], $diff['source_id'] ?? '-', $diff['revision'], $diff['branch_id'] ?? '-', $diff['created_at']), 'yellow');

            foreach (['only_legacy' => 'only the code', 'only_engine' => 'only the engine'] as $key => $label) {
                foreach ($diff['differences'][$key] ?? [] as $line) {
                    CLI::write("  {$label}: {$line}");
                }
            }

            if (isset($diff['differences']['engine_failed'])) {
                CLI::write('  the engine failed: ' . $diff['differences']['engine_failed']);
            }
        }

        CLI::write(count($diffs) . ' difference(s).');

        return EXIT_ERROR;
    }
}
