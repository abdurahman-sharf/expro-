<?php

namespace App\Commands;

use App\Libraries\Accounting\Engine\PostingModes;
use App\Libraries\Accounting\Engine\PostingRules;
use CodeIgniter\CLI\BaseCommand;
use CodeIgniter\CLI\CLI;

class AccountingPostingRules extends BaseCommand
{
    protected $group       = 'Accounting';
    protected $name        = 'accounting:posting_rules';
    protected $usage       = 'accounting:posting_rules [OPERATION]';
    protected $description = 'Lists the posting rules of every operation, or of the one named, version by version: the day each version applies from, the mode the operation is posted in, and each line with its side, where its account comes from, the expression of its amount and the rule of the posting matrix it follows. Reads only.';

    /**
     * @param array<int|string, string|null> $params
     */
    public function run(array $params): int
    {
        $operation = isset($params[0]) && $params[0] !== '' ? $params[0] : null;
        $modes     = new PostingModes();
        $sets      = (new PostingRules())->versions($operation);

        if ($sets === []) {
            CLI::write('No posting rules' . ($operation === null ? '' : " for {$operation}") . '.');

            return EXIT_SUCCESS;
        }

        foreach ($sets as $set) {
            CLI::write(sprintf('%s version %d, from %s, mode %s, source %s, %s journal%s', $set->operation, $set->version, $set->effectiveFrom, $modes->of($set->operation)->value, $set->sourceType, $set->entryType->value, $set->crossBranch ? ', split between branches when paid from a treasury of another branch' : ''), 'yellow');
            CLI::table(
                array_map(static fn ($line): array => [$line->lineNo, $line->side, (string) json_encode($line->account, JSON_UNESCAPED_SLASHES), $line->amount . ($line->repeatOver === null ? '' : " for each of {$line->repeatOver}"), $line->matrixRef ?? ''], $set->lines),
                ['line', 'side', 'account', 'amount', 'matrix'],
            );
        }

        return EXIT_SUCCESS;
    }
}
