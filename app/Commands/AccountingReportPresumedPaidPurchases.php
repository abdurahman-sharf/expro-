<?php

namespace App\Commands;

use App\Libraries\Accounting\PresumedPaidPurchases;
use CodeIgniter\CLI\BaseCommand;
use CodeIgniter\CLI\CLI;

class AccountingReportPresumedPaidPurchases extends BaseCommand
{
    private const COLUMNS = ['receiving_id', 'kind', 'number', 'date', 'supplier', 'amount', 'treasury', 'payment_type'];

    protected $group       = 'Accounting';
    protected $name        = 'accounting:report_presumed_paid_purchases';
    protected $usage       = 'accounting:report_presumed_paid_purchases [--csv]';
    protected $description = 'Lists the purchases the books hold as paid in full and the returns they hold as refunded in full at the moment they were saved, with no payment of their own: kind (purchase or return), number, date, supplier, amount, treasury and way of payment. One saved with the amount left empty was booked this way before an amount had to be typed, and so was one whose typed amount covered it, and the two cannot be told apart, so go through the list by hand. Reads only and corrects nothing.';
    protected $options     = [
        '--csv' => 'Print the list as CSV and nothing else (add spark\'s own --no-header, or its banner comes first).',
    ];

    /**
     * @param array<int|string, string|null> $params
     */
    public function run(array $params): int
    {
        $purchases = (new PresumedPaidPurchases())->list();

        if (array_key_exists('csv', $params) || CLI::getOption('csv') !== null) {
            $out = fopen('php://memory', 'w+b');
            fputcsv($out, self::COLUMNS, ',', '"', '');

            foreach ($purchases as $purchase) {
                fputcsv($out, array_values($purchase), ',', '"', '');
            }

            rewind($out);
            CLI::write(rtrim((string) stream_get_contents($out)));
            fclose($out);

            return EXIT_SUCCESS;
        }

        if ($purchases === []) {
            CLI::write('No purchase or return is held as paid or refunded in full at the moment it was saved.', 'green');

            return EXIT_SUCCESS;
        }

        CLI::table(array_map('array_values', $purchases), self::COLUMNS);
        CLI::write(count($purchases) . ' purchase(s) and return(s) are held as paid or refunded in full at the moment they were saved. Nothing was changed.');

        return EXIT_SUCCESS;
    }
}
