<?php

namespace App\Commands;

use App\Libraries\Accounting\AccountingException;
use App\Libraries\Accounting\PostingException;
use App\Libraries\Accounting\PurchaseCostReclass;
use CodeIgniter\CLI\BaseCommand;
use CodeIgniter\CLI\CLI;
use Config\Database;

class AccountingReclassifyPurchaseCosts extends BaseCommand
{
    protected $group       = 'Accounting';
    protected $name        = 'accounting:reclassify_purchase_costs';
    protected $usage       = 'accounting:reclassify_purchase_costs [--post --employee ID]';
    protected $description = 'Lists the purchase journals that put lines not kept in stock on the cost of sales, and what is still to move for each branch out of those dated in the fiscal year open today. Without --post it reads only. With --post it writes, dated today, one journal for each branch that moves that amount from the cost of sales to the account of purchases. The journals listed are never changed, and posting again moves only what was not moved yet. Exits 0, 1 when the journal could not be posted, and 7 when --post names no employee that exists.';
    protected $options     = [
        '--post'     => 'Post the journal that moves what is pending. Leave it out to only list.',
        '--employee' => 'The id of the employee who posts the journal, written on it and in the audit log. Needed with --post.',
    ];

    /**
     * @param array<int|string, string|null> $params
     */
    public function run(array $params): int
    {
        $reclass = new PurchaseCostReclass();
        $today   = date('Y-m-d');
        $rows    = $reclass->affected($today);

        if ($rows === []) {
            CLI::write('No purchase journal put a line on the cost of sales.', 'green');

            return EXIT_SUCCESS;
        }

        CLI::table(
            array_map(static fn (array $row): array => [$row['journal_id'], $row['number'], $row['date'], $row['branch_id'], $row['receiving_id'], $row['amount'], $row['in_open_year'] ? 'yes' : 'no'], $rows),
            ['journal_id', 'number', 'date', 'branch_id', 'receiving_id', 'amount', 'in_open_year'],
        );

        $pending = $reclass->pending($today);

        foreach ($pending as $branchId => $amount) {
            CLI::write("Branch {$branchId}: {$amount} still to move.");
        }

        if ($pending === []) {
            CLI::write('Nothing is pending in the fiscal year open today.', 'green');

            return EXIT_SUCCESS;
        }

        if (! array_key_exists('post', $params) && CLI::getOption('post') === null) {
            CLI::write('Nothing was written. Run again with --post to move what is pending.');

            return EXIT_SUCCESS;
        }

        $employee = $params['employee'] ?? CLI::getOption('employee');
        $employee = is_string($employee) && ctype_digit($employee) ? (int) $employee : 0;

        if ($employee === 0 || Database::connect()->table('employees')->where(['person_id' => $employee, 'deleted' => 0])->countAllResults() === 0) {
            CLI::error('Name the employee who posts the journal with --employee ID, the id of an employee that exists. Nothing was written.');

            return EXIT_USER_INPUT;
        }

        try {
            $posted = $reclass->post($today, $employee);
        } catch (AccountingException|PostingException $failure) {
            CLI::error('The journal could not be posted: ' . $failure->getMessage());

            return EXIT_ERROR;
        }

        foreach ($posted as $branchId => $journalId) {
            CLI::write("Branch {$branchId}: journal {$journalId} posted.", 'green');
        }

        return EXIT_SUCCESS;
    }
}
