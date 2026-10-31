<?php

namespace App\Libraries\Accounting\Readiness;

/**
 * Which migrations the database has not run yet, in the order they run, with the ones that rewrite
 * data or alter large tables marked, because those are the ones to back up for and to run in a
 * quiet hour. The history is read from the table as it is: the migration runner would create the
 * table when it is missing, and this check never writes.
 */
final class MigrationChecks extends CheckGroup
{
    public const DATA       = 'data';
    public const HEAVY      = 'heavy';
    public const ADDITIVE   = 'additive';
    private const SENSITIVE = [
        'BranchesFoundation'               => [self::DATA, 'gives every employee every branch, puts the stock locations in the default branch, adds company and branch columns to the accounting tables'],
        'AccountingRouting'                => [self::DATA, 'adds sales.branch_id and sets it from the stock location of every sale: the heaviest step on a large sales table'],
        'PurchasesAccounting'              => [self::DATA, 'classifies every purchase (received, returned, moved between locations) and sets its branch'],
        'LegacyFixedPurchaseDiscount'      => [self::DATA, 'marks old fixed purchase-line discounts as an amount for the whole line (E38) and stops with no change while a line is undecided'],
        'BankAccounts'                     => [self::DATA, 'moves the accounts of bank treasuries under 1202: only parent and code change, checked by a fingerprint of the journal lines (E40)'],
        'InterBranchTransfers'             => [self::DATA, 'opens the two accounts between branches in an existing chart and points their routing at them'],
        'SalesAccounting'                  => [self::HEAVY, 'adds two columns to sales'],
        'SalesReceivables'                 => [self::HEAVY, 'adds a column to sales_payments'],
        'CostPrecision'                    => [self::HEAVY, 'widens items.cost_price and sales_items.item_cost_price'],
        'ReceivingCostPrecision'           => [self::HEAVY, 'widens receivings_items.item_cost_price'],
        'Treasuries'                       => [self::HEAVY, 'adds treasury_id to sales_payments and receivings'],
        'StockTransfersBetweenBranches'    => [self::HEAVY, 'adds receivings.to_branch_id'],
        'ExpensesAccounting'               => [self::HEAVY, 'adds nullable columns to expenses and cash_up'],
        'PurchaseInvoiceDiscount'          => [self::HEAVY, 'adds nullable columns to receivings and receivings_items; down() refuses once a purchase carries the discount'],
        'SalesInvoiceDiscount'             => [self::HEAVY, 'adds nullable columns to sales and sales_items'],
        'CrossBranchPayments'              => [self::HEAVY, 'adds treasury_branch_id to sales_payments, receivings_payments and expenses'],
        'RefundInvoice'                    => [self::HEAVY, 'adds sales.return_document'],
        'SupplierPaymentDate'              => [self::HEAVY, 'adds receivings_payments.payment_date'],
        'InvoiceDatesAndPaymentTerms'      => [self::HEAVY, 'adds the invoice date, the payment terms and the due date to sales and receivings'],
        'PurchaseTaxesAndSupplierRegistry' => [self::HEAVY, 'adds the purchase tax columns to items and suppliers.commercial_registry'],
        'CustomerOpenings'                 => [self::HEAVY, 'adds customer_credit_applications.opening_id and lets invoice_sale_id be empty, which rebuilds that table; creates customer_openings'],
        'QuoteLifecycle'                   => [self::HEAVY, 'adds sales.quote_status and sales.source_quote_id; down() refuses once a quote carries a state'],
        'AppConfigValueText'               => [self::HEAVY, 'widens app_config.value from varchar(500) to TEXT: a core table of the application, small, and down() does not narrow the column'],
        'ChequesAndRevenues'               => [self::DATA, 'opens the leaf accounts of notes receivable, notes payable, funds under collection and deposit fees in an existing chart and points four routing rows at them'],
    ];

    public function run(): array
    {
        return [$this->check('migrations', 'MIGRATIONS', 'Migration status', [], fn (): CheckResult => $this->status())];
    }

    /**
     * @return list<array{version: string, class: string, kind: string, note: string}>
     */
    public function pending(): array
    {
        $done    = array_flip(array_column($this->history(), 'version'));
        $pending = [];

        foreach ($this->files() as $version => $class) {
            if (isset($done[(string) $version])) {
                continue;
            }

            [$kind, $note] = self::SENSITIVE[$class] ?? [self::ADDITIVE, ''];

            $pending[] = ['version' => (string) $version, 'class' => $class, 'kind' => $kind, 'note' => $note];
        }

        return $pending;
    }

    /**
     * When a migration was applied, or null when it was not.
     */
    public function appliedAt(string $class): ?string
    {
        $version = array_search($class, $this->files(), true);

        if ($version === false) {
            return null;
        }

        foreach ($this->history() as $row) {
            if ($row['version'] === (string) $version) {
                return $row['time'] ?? 'an unknown time';
            }
        }

        return null;
    }

    /**
     * @return list<array{version: string, time: ?string}>
     */
    public function history(): array
    {
        if (! $this->schema->hasTable('migrations')) {
            return [];
        }

        $rows = [];

        foreach ($this->db->table('migrations')->get()->getResult() as $row) {
            $rows[] = ['version' => (string) $row->version, 'time' => isset($row->time) && ctype_digit((string) $row->time) ? date('Y-m-d H:i:s', (int) $row->time) : null];
        }

        return $rows;
    }

    /**
     * @return array<int|string, string> The class of each migration file of the application, by version, in the order they run.
     */
    private function files(): array
    {
        $files = [];

        foreach (service('migrations', null, null, false)->setNamespace('App')->findMigrations() as $migration) {
            $files[(string) $migration->version] = basename(str_replace('\\', '/', (string) $migration->class));
        }

        ksort($files);

        return $files;
    }

    private function status(): CheckResult
    {
        $pending = $this->pending();
        $files   = $this->files();
        $unknown = array_values(array_diff(array_column($this->history(), 'version'), array_map('strval', array_keys($files))));
        $data    = ['files' => count($files), 'pending' => $pending, 'unknown' => $unknown];

        if ($pending === [] && $unknown === []) {
            return CheckResult::pass(count($files) . ' migration(s) are applied and none is pending.', [], $data);
        }

        $findings = array_map(
            static fn (array $migration): string => $migration['version'] . ' ' . $migration['class'] . ' [' . strtoupper($migration['kind']) . ']' . ($migration['note'] === '' ? '' : ': ' . $migration['note']),
            $pending,
        );

        foreach ($unknown as $version) {
            $findings[] = $version . ' is recorded as applied but this code has no such migration file';
        }

        $kinds = array_count_values(array_column($pending, 'kind'));

        return CheckResult::review(
            sprintf('%d migration(s) are pending (%d rewrite data, %d alter large tables) and %d recorded migration(s) have no file here; they run in this order at the first login after the deploy.', count($pending), $kinds[self::DATA] ?? 0, $kinds[self::HEAVY] ?? 0, count($unknown)),
            $findings,
            $data,
        );
    }
}
