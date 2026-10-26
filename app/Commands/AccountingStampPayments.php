<?php

namespace App\Commands;

use App\Libraries\Accounting\PaymentTreasuryStamping;
use CodeIgniter\CLI\BaseCommand;
use CodeIgniter\CLI\CLI;

class AccountingStampPayments extends BaseCommand
{
    protected $group       = 'Accounting';
    protected $name        = 'accounting:stamp_payments';
    protected $usage       = 'accounting:stamp_payments [--apply]';
    protected $description = 'Lists the payments of sales and purchases that name no treasury although their journal went into one. With --apply it stamps each with that treasury, so that changing the routing of a way of payment later does not move it: only the treasury of the payment changes, no journal line and no balance.';
    protected $options     = [
        '--apply' => 'Stamp the payments.',
    ];

    public function run(array $params): int
    {
        $stamping = new PaymentTreasuryStamping();

        if (array_key_exists('apply', $params) || CLI::getOption('apply') !== null) {
            foreach ($stamping->stamp() as $row) {
                CLI::write("{$row['table']} #{$row['payment_id']}: treasury {$row['treasury_id']}.");
            }
        }

        $plan = $stamping->plan();

        CLI::write(count($plan['stamp']) . ' payment(s) have no treasury although their journal went into one.');

        foreach ($plan['stamp'] as $row) {
            CLI::write("  {$row['table']} #{$row['payment_id']} would take treasury {$row['treasury_id']}");
        }

        CLI::write(count($plan['skipped']) . ' payment(s) went into a treasury and cannot be stamped.');

        foreach ($plan['skipped'] as $row) {
            CLI::write("  {$row['table']} #{$row['payment_id']}: {$row['reason']}");
        }

        return $plan['stamp'] === [] ? EXIT_SUCCESS : EXIT_ERROR;
    }
}
