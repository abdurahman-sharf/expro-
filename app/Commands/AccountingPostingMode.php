<?php

namespace App\Commands;

use App\Libraries\Accounting\AccountingMessage;
use App\Libraries\Accounting\Engine\PostingMode;
use App\Libraries\Accounting\Engine\PostingModes;
use App\Libraries\Accounting\PostingException;
use CodeIgniter\CLI\BaseCommand;
use CodeIgniter\CLI\CLI;
use Config\Database;

class AccountingPostingMode extends BaseCommand
{
    protected $group       = 'Accounting';
    protected $name        = 'accounting:posting_mode';
    protected $usage       = 'accounting:posting_mode [OPERATION MODE --employee ID]';
    protected $description = 'Without arguments, lists the mode each operation is posted in. With an operation and a mode (legacy, shadow or engine), switches that operation and writes the audit log in the name of the employee given. legacy posts the journal of the code of the document; shadow posts that journal and records where the engine would differ; engine posts the journal of the engine. An operation with a gap the engine does not take over yet is refused the engine. Exits 0, and 7 for a mode, an employee or a switch it refuses.';
    protected $options     = [
        '--employee' => 'The id of the employee who switches the mode. Needed to switch.',
    ];

    /**
     * @param array<int|string, string|null> $params
     */
    public function run(array $params): int
    {
        $modes = new PostingModes();

        if (! isset($params[0])) {
            foreach ($modes->all() as $operation => $mode) {
                CLI::write("{$operation}: {$mode->value}");
            }

            CLI::write('Any other operation: legacy.');

            return EXIT_SUCCESS;
        }

        $mode     = PostingMode::tryFrom((string) ($params[1] ?? ''));
        $employee = $params['employee'] ?? CLI::getOption('employee');
        $employee = is_string($employee) && ctype_digit($employee) ? (int) $employee : 0;

        if ($mode === null || preg_match('/^[a-z][a-z0-9_]{0,31}$/', (string) $params[0]) !== 1) {
            CLI::error('Name an operation and a mode: legacy, shadow or engine. Nothing was changed.');

            return EXIT_USER_INPUT;
        }

        if ($employee === 0 || Database::connect()->table('employees')->where(['person_id' => $employee, 'deleted' => 0])->countAllResults() === 0) {
            CLI::error('Name the employee who switches the mode with --employee ID, the id of an employee that exists. Nothing was changed.');

            return EXIT_USER_INPUT;
        }

        try {
            $modes->set((string) $params[0], $mode, $employee);
        } catch (PostingException $refused) {
            CLI::error(rtrim(AccountingMessage::of($refused), '.') . '. Nothing was changed.');

            return EXIT_USER_INPUT;
        }
        CLI::write("{$params[0]}: {$mode->value}", 'green');

        return EXIT_SUCCESS;
    }
}
