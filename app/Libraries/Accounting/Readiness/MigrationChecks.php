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
        'PerTaxInclusive'                  => [self::HEAVY, 'adds sales_items_taxes.liability_account_id (a nullable column and its index on a large table) and creates the tax list; down() refuses once a tax row holds an account'],
        'InvoiceHeaderCharges'             => [self::DATA, 'adds seven nullable columns to sales and opens the adjustments and shipping accounts of an existing chart with their routing rows (the adjustments row moves off discounts allowed when nobody had changed it)'],
        'SalesDebitNotes'                  => [self::HEAVY, 'creates sales_debit_notes and adds customer_credit_applications.debit_note_id, widening the one-target rule of an application from two kinds of target to three, which rebuilds that table'],
        'PricingAndCreditControls'         => [self::HEAVY, 'adds nullable or neutral columns to customers (price list, credit limit, credit days), sales (price list) and items (minimum price, highest discount); creates price_lists and price_list_items'],
        'ChequesAndRevenues'               => [self::DATA, 'opens the leaf accounts of notes receivable, notes payable, funds under collection and deposit fees in an existing chart and points four routing rows at them'],
        'BankReconciliation'               => [self::HEAVY, 'adds the nullable treasuries.bank_code; creates the bank statement, statement line, match and reconciliation tables, which no existing row refers to'],
        'DocumentCurrencyColumns'          => [self::HEAVY, 'adds nullable currency and rate columns to sales, sales_payments, receivings, receivings_payments, expenses and revenues, treasuries.currency_code, three nullable columns to treasury_transfers and accounting_journal_lines.currency_code; down() refuses once a row carries a currency'],
        'FxDifferenceAccounts'             => [self::DATA, 'opens the accounts of exchange gains and losses in an existing chart and points the two routing rows fx.gain and fx.loss at them'],
        'CustomerMasterData'               => [self::HEAVY, 'adds neutral or empty columns to customers (classification, manual status, assigned employee, area, country code, type, government flag, billing method) and suppliers.country_code; creates customer_classifications; down() refuses once a value is stored'],
        'ProductMasterData'                => [self::HEAVY, 'adds neutral or empty columns to items (brand, status, supplier code) on the largest master table; creates item_brands; down() refuses once a value is stored'],
        'StockLocationStatus'              => [self::DATA, 'adds the status and the primary flag to stock_locations and marks the location with the lowest number primary; a unique key keeps one live primary location'],
        'ShippingCompanies'                => [self::HEAVY, 'creates shipping_companies and adds the nullable sales.shipping_company_id with its index and key on a large table; down() refuses once a sale names a company'],
        'StockUnitsAndTracking'            => [self::HEAVY, 'adds nullable unit columns and a shape check to sales_items, receivings_items and stock_permit_lines; creates the unit templates, item profiles, serial numbers, batches and the tracking record of each line; down() refuses once a line carries a unit or a serial number or a batch is on record'],
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
