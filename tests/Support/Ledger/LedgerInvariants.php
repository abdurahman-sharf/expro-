<?php

namespace Tests\Support\Ledger;

use App\Libraries\Accounting\BalanceSheet;
use App\Libraries\Accounting\CustomerLedger;
use App\Libraries\Accounting\Readiness\CheckGroup;
use App\Libraries\Accounting\Readiness\CheckStatus;
use App\Libraries\Accounting\Readiness\LedgerChecks;
use App\Libraries\Accounting\Readiness\PurchaseCycleChecks;
use App\Libraries\Accounting\Readiness\StockTrackingChecks;
use App\Libraries\Accounting\Readiness\TieChecks;
use App\Libraries\Accounting\ReportPeriod;
use App\Libraries\Accounting\ReportScope;
use App\Libraries\Accounting\StockLedger;
use App\Libraries\Accounting\SupplierLedger;
use App\Libraries\Accounting\TaxReport;
use App\Libraries\Accounting\TrialBalance;
use CodeIgniter\Database\BaseConnection;
use Config\Database;

/**
 * What must hold in the books whatever was done to them, asked of the database in SQL of its own wherever
 * that is possible, and of the application's reports and tie checks beside it. Every method returns the
 * problems it found as plain sentences, none when the books hold.
 */
final class LedgerInvariants
{
    private const TOLERANCE = '0.00005';

    /** @var array<string, int> How many times each invariant was asked. */
    public array $asked = [];

    private BaseConnection $db;

    private string $journals;

    private string $lines;

    private string $accounts;

    public function __construct()
    {
        $this->db       = Database::connect();
        $this->journals = $this->db->prefixTable('accounting_journals');
        $this->lines    = $this->db->prefixTable('accounting_journal_lines');
        $this->accounts = $this->db->prefixTable('accounting_accounts');
    }

    /**
     * @return list<string>
     */
    public function all(bool $ties = true): array
    {
        $problems = [];

        foreach ([
            'journals_balance'            => fn (): array => $this->journalsBalance(),
            'lines_wellformed'            => fn (): array => $this->linesWellFormed(),
            'accounts_and_periods'        => fn (): array => $this->accountsAndFiscalYears(),
            'no_drafts'                   => fn (): array => $this->noPreviewsOrDrafts(),
            'source_keys_unique'          => fn (): array => $this->sourceKeysUnique(),
            'reversal_pairs'              => fn (): array => $this->reversalPairs(),
            'trial_balance_zero'          => fn (): array => $this->trialBalanceIsZero(),
            'reports_balance'             => fn (): array => $this->reportsBalance(),
            'documents_have_journals'     => fn (): array => $this->documentsHaveJournals(),
            'sale_totals'                 => fn (): array => $this->saleTotalsMatchJournals(),
            'sale_payments'               => fn (): array => $this->salePaymentsMatchJournals(),
            'credit_applications'         => fn (): array => $this->creditApplicationsWithinSources(),
            'stock_quantities'            => fn (): array => $this->stockQuantitiesMatchMovements(),
            'stock_value'                 => fn (): array => $this->stockValueMatchesInventoryAccounts(),
            'tax_accounts'                => fn (): array => $this->taxAccountsMatchTaxRows(),
        ] as $name => $check) {
            $this->asked[$name] = ($this->asked[$name] ?? 0) + 1;

            foreach ($check() as $problem) {
                $problems[] = $name . ': ' . $problem;
            }
        }

        if ($ties) {
            foreach ($this->readinessTies() as $problem) {
                $problems[] = 'readiness: ' . $problem;
            }

            $this->asked['readiness'] = ($this->asked['readiness'] ?? 0) + 1;
        }

        return $problems;
    }

    /**
     * Every posted journal has its debit equal to its credit, and the totals it carries are its lines.
     *
     * @return list<string>
     */
    public function journalsBalance(): array
    {
        $rows = $this->db->query(
            "SELECT j.journal_id, j.source_type, j.total_debit, j.total_credit, COALESCE(SUM(l.debit), 0) AS debit, COALESCE(SUM(l.credit), 0) AS credit, COUNT(l.line_id) AS line_count
             FROM {$this->journals} j LEFT JOIN {$this->lines} l ON l.journal_id = j.journal_id
             WHERE j.status IN ('posted', 'reversed')
             GROUP BY j.journal_id, j.source_type, j.total_debit, j.total_credit
             HAVING ABS(SUM(l.debit) - SUM(l.credit)) > " . self::TOLERANCE . ' OR ABS(j.total_debit - COALESCE(SUM(l.debit), 0)) > ' . self::TOLERANCE . ' OR ABS(j.total_credit - COALESCE(SUM(l.credit), 0)) > ' . self::TOLERANCE . ' OR COUNT(l.line_id) < 2 LIMIT 5',
        )->getResult();

        return array_map(static fn (object $row): string => "journal {$row->journal_id} ({$row->source_type}) debit {$row->debit} credit {$row->credit} header {$row->total_debit}/{$row->total_credit}, {$row->line_count} lines", $rows);
    }

    /**
     * @return list<string>
     */
    public function linesWellFormed(): array
    {
        $rows = $this->db->query(
            "SELECT l.line_id, l.journal_id, l.debit, l.credit FROM {$this->lines} l JOIN {$this->journals} j ON j.journal_id = l.journal_id
             WHERE j.status IN ('posted', 'reversed') AND (l.debit < 0 OR l.credit < 0 OR (l.debit > 0 AND l.credit > 0) OR (l.debit = 0 AND l.credit = 0)) LIMIT 5",
        )->getResult();

        return array_map(static fn (object $row): string => "line {$row->line_id} of journal {$row->journal_id} holds debit {$row->debit} and credit {$row->credit}", $rows);
    }

    /**
     * Posted lines sit on accounts that exist and take postings, and every journal is dated inside a fiscal year.
     *
     * @return list<string>
     */
    public function accountsAndFiscalYears(): array
    {
        $problems = [];
        $years    = $this->db->prefixTable('accounting_fiscal_years');

        $rows = $this->db->query(
            "SELECT l.line_id, l.journal_id, l.account_id, a.is_group FROM {$this->lines} l JOIN {$this->journals} j ON j.journal_id = l.journal_id LEFT JOIN {$this->accounts} a ON a.account_id = l.account_id
             WHERE j.status IN ('posted', 'reversed') AND (a.account_id IS NULL OR a.is_group = 1 OR a.company_id <> j.company_id) LIMIT 5",
        )->getResult();

        foreach ($rows as $row) {
            $problems[] = "line {$row->line_id} of journal {$row->journal_id} sits on account {$row->account_id}, which is " . ($row->is_group === null ? 'missing' : 'a group or of another company');
        }

        $rows = $this->db->query(
            "SELECT j.journal_id, j.journal_date, j.source_type FROM {$this->journals} j WHERE j.status IN ('posted', 'reversed') AND NOT EXISTS (SELECT 1 FROM {$years} f WHERE f.company_id = j.company_id AND j.journal_date BETWEEN f.start_date AND f.end_date) LIMIT 5",
        )->getResult();

        foreach ($rows as $row) {
            $problems[] = "journal {$row->journal_id} ({$row->source_type}) is dated {$row->journal_date}, outside every fiscal year";
        }

        $rows = $this->db->query("SELECT journal_id FROM {$this->journals} WHERE status = 'posted' AND (number IS NULL OR number = '') LIMIT 5")->getResult();

        foreach ($rows as $row) {
            $problems[] = "posted journal {$row->journal_id} has no number";
        }

        return $problems;
    }

    /**
     * @return list<string>
     */
    public function noPreviewsOrDrafts(): array
    {
        $row = $this->db->query("SELECT COUNT(*) AS found FROM {$this->journals} WHERE status IN ('draft', 'preview')")->getRow();

        return (int) $row->found === 0 ? [] : ["{$row->found} journals stand as drafts or previews, and no operation of a run makes one"];
    }

    /**
     * @return list<string>
     */
    public function sourceKeysUnique(): array
    {
        $rows = $this->db->query(
            "SELECT source_type, source_id, entry_type, COUNT(*) AS found FROM {$this->journals} WHERE status IN ('posted', 'reversed') AND source_id IS NOT NULL AND source_type <> 'reversal' GROUP BY source_type, source_id, entry_type HAVING COUNT(*) > COUNT(DISTINCT revision) OR SUM(status = 'posted') > 1 LIMIT 5",
        )->getResult();

        return array_map(static fn (object $row): string => "{$row->source_type} {$row->source_id} ({$row->entry_type}) has {$row->found} journals and more than one of them stands", $rows);
    }

    /**
     * A journal that was reversed has exactly one reversal, the reversal mirrors it line for line, and the two net to nothing.
     *
     * @return list<string>
     */
    public function reversalPairs(): array
    {
        $problems = [];

        $rows = $this->db->query(
            "SELECT o.journal_id, COUNT(r.journal_id) AS reversals FROM {$this->journals} o LEFT JOIN {$this->journals} r ON r.reverses_journal_id = o.journal_id WHERE o.status = 'reversed' GROUP BY o.journal_id HAVING COUNT(r.journal_id) <> 1 LIMIT 5",
        )->getResult();

        foreach ($rows as $row) {
            $problems[] = "journal {$row->journal_id} is reversed and has {$row->reversals} reversals";
        }

        $rows = $this->db->query(
            "SELECT r.journal_id AS reversal, o.journal_id AS original, o.status FROM {$this->journals} r JOIN {$this->journals} o ON o.journal_id = r.reverses_journal_id WHERE o.status <> 'reversed' OR r.status NOT IN ('posted', 'reversed') OR r.branch_id <> o.branch_id LIMIT 5",
        )->getResult();

        foreach ($rows as $row) {
            $problems[] = "journal {$row->reversal} reverses {$row->original}, which is {$row->status}, or lies in another branch";
        }

        $rows = $this->db->query(
            "SELECT o.journal_id AS original, r.journal_id AS reversal, l.account_id, SUM(l.debit - l.credit) AS net
             FROM {$this->journals} r JOIN {$this->journals} o ON o.journal_id = r.reverses_journal_id JOIN {$this->lines} l ON l.journal_id IN (o.journal_id, r.journal_id)
             GROUP BY o.journal_id, r.journal_id, l.account_id HAVING ABS(SUM(l.debit - l.credit)) > " . self::TOLERANCE . ' LIMIT 5',
        )->getResult();

        foreach ($rows as $row) {
            $problems[] = "journal {$row->original} and its reversal {$row->reversal} leave {$row->net} on account {$row->account_id}";
        }

        $rows = $this->db->query(
            "SELECT o.journal_id AS original, r.journal_id AS reversal FROM {$this->journals} r JOIN {$this->journals} o ON o.journal_id = r.reverses_journal_id
             WHERE (SELECT COUNT(*) FROM {$this->lines} WHERE journal_id = o.journal_id) <> (SELECT COUNT(*) FROM {$this->lines} WHERE journal_id = r.journal_id) LIMIT 5",
        )->getResult();

        foreach ($rows as $row) {
            $problems[] = "the reversal {$row->reversal} does not mirror {$row->original} line for line";
        }

        return $problems;
    }

    /**
     * The books add up to nothing as a whole and in every branch, assets equal liabilities, equity and the result,
     * and the application's trial balance and balance sheet say the same.
     *
     * @return list<string>
     */
    public function trialBalanceIsZero(): array
    {
        $problems = [];

        $row = $this->db->query("SELECT COALESCE(SUM(l.debit), 0) AS debit, COALESCE(SUM(l.credit), 0) AS credit FROM {$this->lines} l JOIN {$this->journals} j ON j.journal_id = l.journal_id WHERE j.status IN ('posted', 'reversed')")->getRow();

        if (bccomp((string) $row->debit, (string) $row->credit, 4) !== 0) {
            $problems[] = "debit {$row->debit} and credit {$row->credit} of every posted journal differ";
        }

        $rows = $this->db->query(
            "SELECT j.branch_id, SUM(l.debit) AS debit, SUM(l.credit) AS credit FROM {$this->lines} l JOIN {$this->journals} j ON j.journal_id = l.journal_id WHERE j.status IN ('posted', 'reversed') GROUP BY j.branch_id HAVING ABS(SUM(l.debit) - SUM(l.credit)) > " . self::TOLERANCE,
        )->getResult();

        foreach ($rows as $branch) {
            $problems[] = "branch {$branch->branch_id} does not balance on its own: {$branch->debit} against {$branch->credit}";
        }

        $types = [];

        foreach ($this->db->query(
            "SELECT a.account_type, SUM(l.debit - l.credit) AS net FROM {$this->lines} l JOIN {$this->journals} j ON j.journal_id = l.journal_id JOIN {$this->accounts} a ON a.account_id = l.account_id WHERE j.status IN ('posted', 'reversed') GROUP BY a.account_type",
        )->getResult() as $type) {
            $types[$type->account_type] = (string) $type->net;
        }

        $assets      = $types['asset'] ?? '0';
        $liabilities = bcsub('0', $types['liability'] ?? '0', 4);
        $equity      = bcsub('0', $types['equity'] ?? '0', 4);
        $result      = bcsub(bcsub('0', $types['revenue'] ?? '0', 4), $types['expense'] ?? '0', 4);

        if (bccomp($assets, bcadd(bcadd($liabilities, $equity, 4), $result, 4), 4) !== 0) {
            $problems[] = "assets {$assets} differ from liabilities {$liabilities} + equity {$equity} + result {$result}";
        }

        return $problems;
    }

    /**
     * @return list<string>
     */
    public function reportsBalance(): array
    {
        $problems = [];
        $scope    = ReportScope::resolve(null, null, $this->db);
        $today    = date('Y-m-d', strtotime('+1 day'));
        $trial    = (new TrialBalance($this->db))->build($scope, ReportPeriod::of(null, $today));

        if (! $trial->balances()) {
            $problems[] = 'the trial balance of the application does not balance';
        }

        $sheet = (new BalanceSheet($this->db))->build($scope, $today);

        if (! $sheet->balances()) {
            $problems[] = sprintf('the balance sheet does not balance: assets %s, liabilities %s, equity %s, unclosed result %s', $sheet->assets->total, $sheet->liabilities->total, $sheet->equity->total, $sheet->unclosedResult);
        }

        return $problems;
    }

    /**
     * Every completed sale and purchase has the journal that stands for it and a cancelled one has none that stands.
     *
     * @return list<string>
     */
    public function documentsHaveJournals(): array
    {
        $problems = [];
        $sales    = $this->db->prefixTable('sales');

        foreach ($this->saleFigures() as $row) {
            if (bccomp($this->abs($row['document']), '0.005', 4) >= 0 && bccomp($row['posted'], '0', 4) === 0) {
                $problems[] = "completed sale {$row['sale_id']} (type {$row['sale_type']}) comes to {$row['document']} and has no standing revenue journal";
            }
        }

        $rows = $this->db->query(
            "SELECT s.sale_id, s.sale_status FROM {$sales} s JOIN {$this->journals} j ON j.source_type = 'sale' AND j.source_id = s.sale_id AND j.status = 'posted' WHERE s.sale_status <> 0 LIMIT 5",
        )->getResult();

        foreach ($rows as $row) {
            $problems[] = "sale {$row->sale_id} has status {$row->sale_status} and a journal of it still stands";
        }

        $rows = $this->db->query(
            "SELECT p.payment_id, p.sale_id FROM {$this->db->prefixTable('sales_payments')} p JOIN {$sales} s ON s.sale_id = p.sale_id JOIN {$this->journals} j ON j.source_type = 'sale_payment' AND j.source_id = p.payment_id AND j.status = 'posted' WHERE s.sale_status <> 0 OR p.payment_status <> 'completed' LIMIT 5",
        )->getResult();

        foreach ($rows as $row) {
            $problems[] = "payment {$row->payment_id} of sale {$row->sale_id} was cancelled or removed and its journal still stands";
        }

        $receivings = $this->db->prefixTable('receivings');
        $rows       = $this->db->query(
            "SELECT r.receiving_id FROM {$receivings} r WHERE r.receiving_type IN ('receive', 'return') AND EXISTS (SELECT 1 FROM {$this->db->prefixTable('receivings_items')} i WHERE i.receiving_id = r.receiving_id AND i.item_unit_price * i.quantity_purchased <> 0)
             AND NOT EXISTS (SELECT 1 FROM {$this->journals} j WHERE j.source_type = 'purchase' AND j.source_id = r.receiving_id AND j.status = 'posted') AND r.receiving_id NOT IN (SELECT source_id FROM {$this->journals} WHERE source_type = 'purchase' AND status = 'reversed') LIMIT 5",
        )->getResult();

        foreach ($rows as $row) {
            $problems[] = "purchase {$row->receiving_id} has no standing journal and was not cancelled";
        }

        return $problems;
    }

    /**
     * The receivable a sale posts is what its saved lines, discounts and taxes come to, to the cent the books round.
     *
     * @return list<string>
     */
    public function saleTotalsMatchJournals(): array
    {
        $problems = [];

        foreach ($this->saleFigures() as $row) {
            if (bccomp($row['posted'], '0', 4) === 0 && bccomp($this->abs($row['document']), '0.005', 4) < 0) {
                continue;
            }

            $allowed = bcadd('0.01', bcmul('0.005', (string) $row['lines'], 4), 4);
            $gap     = bcsub($row['posted'], $this->abs($row['document']), 4);

            if (bccomp($this->abs($gap), $allowed, 4) > 0) {
                $problems[] = "sale {$row['sale_id']} (type {$row['sale_type']}) posted a total of {$row['posted']} and its saved lines come to {$row['document']} (lines {$row['lines_total']} + added tax {$row['added_tax']}), a gap of {$gap}";
            }
        }

        return array_slice($problems, 0, 5);
    }

    /**
     * What one completed sale comes to from its saved lines, discounts and taxes, without the rounding of the books.
     */
    public function documentTotalOfSale(int $saleId): string
    {
        $rows = $this->saleFigures($saleId);

        return $rows === [] ? '0.0000' : $this->abs($rows[0]['document']);
    }

    /**
     * Stock locations that hold units valued at less than nothing: every location is valued from its own movements, and
     * a sale costs the goods at the cost of the item in every location together.
     *
     * @return list<string>
     */
    public function locationsValuedBelowNothing(): array
    {
        $entries = $this->db->prefixTable('accounting_stock_entries');
        $rows    = $this->db->query(
            "SELECT item_id, location_id, SUM(quantity) AS quantity, SUM(value) AS value FROM {$entries} GROUP BY item_id, location_id HAVING SUM(quantity) > 0 AND SUM(value) < 0 LIMIT 20",
        )->getResultArray();

        return array_map(static fn (array $row): string => "item {$row['item_id']} in location {$row['location_id']}: {$row['quantity']} units valued at {$row['value']}", $rows);
    }

    /**
     * What every completed sale comes to from its saved lines, discounts and taxes, beside what its revenue journal posted.
     *
     * @return list<array{sale_id: int, sale_type: int, lines: int, lines_total: string, added_tax: string, document: string, posted: string}>
     */
    private function saleFigures(?int $saleId = null): array
    {
        $sales = $this->db->prefixTable('sales');
        $items = $this->db->prefixTable('sales_items');
        $taxes = $this->db->prefixTable('sales_items_taxes');
        $rows  = $this->db->query(
            "SELECT s.sale_id, s.sale_type,
                    (SELECT COALESCE(SUM(i.item_unit_price * i.quantity_purchased - IF(i.discount_type = 0, ROUND(i.item_unit_price * i.quantity_purchased * i.discount / 100, 2), i.discount * i.quantity_purchased) - COALESCE(i.invoice_discount_share, 0)), 0) FROM {$items} i WHERE i.sale_id = s.sale_id) AS lines_total,
                    (SELECT COUNT(*) FROM {$items} i WHERE i.sale_id = s.sale_id) AS line_count,
                    (SELECT COALESCE(SUM(t.item_tax_amount), 0) FROM {$taxes} t WHERE t.sale_id = s.sale_id AND t.tax_type = 1) AS added_tax,
                    (SELECT COALESCE(SUM(j.total_debit), 0) FROM {$this->journals} j WHERE j.source_type = 'sale' AND j.source_id = s.sale_id AND j.entry_type = 'revenue' AND j.status = 'posted') AS posted
             FROM {$sales} s WHERE s.sale_status = 0 AND s.sale_type IN (0, 1, 4)" . ($saleId === null ? '' : ' AND s.sale_id = ' . $saleId),
        )->getResultArray();

        return array_map(fn (array $row): array => [
            'sale_id'     => (int) $row['sale_id'],
            'sale_type'   => (int) $row['sale_type'],
            'lines'       => (int) $row['line_count'],
            'lines_total' => bcadd((string) $row['lines_total'], '0', 4),
            'added_tax'   => bcadd((string) $row['added_tax'], '0', 4),
            'document'    => bcadd(bcadd((string) $row['lines_total'], (string) $row['added_tax'], 4), '0', 4),
            'posted'      => bcadd((string) $row['posted'], '0', 4),
        ], $rows);
    }

    /**
     * What customers paid on a sale, net of the change handed back, is what its payment journals took in.
     *
     * @return list<string>
     */
    public function salePaymentsMatchJournals(): array
    {
        $problems = [];
        $payments = $this->db->prefixTable('sales_payments');
        $sales    = $this->db->prefixTable('sales');
        $due      = lang('Sales.due');

        $rows = $this->db->query(
            "SELECT p.payment_id, p.sale_id, p.payment_type, (p.payment_amount - p.cash_refund) AS net,
                    (SELECT COUNT(*) FROM {$this->journals} j WHERE j.source_type = 'sale_payment' AND j.source_id = p.payment_id AND j.status = 'posted') AS standing,
                    (SELECT COALESCE(SUM(j.total_debit), 0) FROM {$this->journals} j WHERE j.source_type = 'sale_payment' AND j.source_id = p.payment_id AND j.status = 'posted') AS posted
             FROM {$payments} p JOIN {$sales} s ON s.sale_id = p.sale_id
             WHERE s.sale_status = 0 AND p.payment_status = 'completed' AND p.payment_type NOT LIKE ?
             HAVING ABS(net) > 0 AND (standing <> 1 OR ABS(posted - ABS(net)) > 0.005) LIMIT 5",
            [$due . '%'],
        )->getResult();

        foreach ($rows as $row) {
            $problems[] = "payment {$row->payment_id} ({$row->payment_type}) of sale {$row->sale_id} is {$row->net} net and has {$row->standing} standing journals posting {$row->posted}";
        }

        return $problems;
    }

    /**
     * Credit is never applied beyond the invoice it settles, and what a customer or supplier is owed is read the same from their books and their documents.
     *
     * @return list<string>
     */
    public function creditApplicationsWithinSources(): array
    {
        $problems = [];
        $applied  = $this->db->prefixTable('customer_credit_applications');

        $rows = $this->db->query("SELECT application_id, amount FROM {$applied} WHERE amount <= 0 LIMIT 5")->getResult();

        foreach ($rows as $row) {
            $problems[] = "credit application {$row->application_id} holds {$row->amount}";
        }

        $rows = $this->db->query(
            "SELECT a.invoice_sale_id, SUM(a.amount) AS applied,
                    (SELECT COALESCE(SUM(j.total_debit), 0) FROM {$this->journals} j WHERE j.source_type = 'sale' AND j.source_id = a.invoice_sale_id AND j.entry_type = 'revenue' AND j.status = 'posted') AS total
             FROM {$applied} a WHERE a.invoice_sale_id IS NOT NULL AND a.released_at IS NULL GROUP BY a.invoice_sale_id HAVING applied > total + 0.00005 LIMIT 5",
        )->getResult();

        foreach ($rows as $row) {
            $problems[] = "invoice {$row->invoice_sale_id} was applied {$row->applied} of credit and is worth {$row->total}";
        }

        foreach ($this->db->table('customers')->select('person_id')->get()->getResultArray() as $customer) {
            $position = (new CustomerLedger($this->db))->position((int) $customer['person_id']);

            foreach ($position->credits as $credit) {
                $left = bcsub(bcsub($credit->amount, $credit->applied, 4), $credit->refunded, 4);

                if (bccomp($left, '-0.00005', 4) < 0) {
                    $problems[] = "customer {$customer['person_id']}: credit {$credit->type} {$credit->sourceId} of {$credit->amount} was applied {$credit->applied} and refunded {$credit->refunded}";
                }
            }

            foreach ($position->invoices as $invoice) {
                if (bccomp($invoice->applied, '0', 4) > 0 && bccomp(bcadd($invoice->paid, $invoice->applied, 4), $invoice->total, 4) > 0) {
                    $problems[] = "customer {$customer['person_id']}: invoice {$invoice->saleId} of {$invoice->total} was paid {$invoice->paid} and applied {$invoice->applied}";
                }
            }
        }

        foreach ($this->db->table('suppliers')->select('person_id')->get()->getResultArray() as $supplier) {
            $position = (new SupplierLedger($this->db))->position((int) $supplier['person_id']);

            foreach ($position->credits as $credit) {
                $left = bcsub(bcsub($credit->amount, $credit->applied, 4), $credit->refunded, 4);

                if (bccomp($left, '-0.00005', 4) < 0) {
                    $problems[] = "supplier {$supplier['person_id']}: credit {$credit->type} {$credit->sourceId} of {$credit->amount} was applied {$credit->applied} and refunded {$credit->refunded}";
                }
            }

            foreach ($position->purchases as $purchase) {
                if (bccomp($purchase->applied, '0', 4) > 0 && bccomp(bcadd($purchase->paid, $purchase->applied, 4), $purchase->total, 4) > 0) {
                    $problems[] = "supplier {$supplier['person_id']}: purchase {$purchase->receivingId} of {$purchase->total} was paid {$purchase->paid} and applied {$purchase->applied}";
                }
            }
        }

        return array_slice($problems, 0, 5);
    }

    /**
     * The quantity an item holds in a location is the sum of its movements, and is not negative where the shop refuses that.
     *
     * @return list<string>
     */
    public function stockQuantitiesMatchMovements(): array
    {
        $problems = [];
        $held     = $this->db->prefixTable('item_quantities');
        $moved    = $this->db->prefixTable('inventory');
        $items    = $this->db->prefixTable('items');

        $rows = $this->db->query(
            "SELECT k.item_id, k.location_id, COALESCE(q.quantity, 0) AS held, k.moved FROM (SELECT trans_items AS item_id, trans_location AS location_id, SUM(trans_inventory) AS moved FROM {$moved} GROUP BY trans_items, trans_location) k
             LEFT JOIN {$held} q ON q.item_id = k.item_id AND q.location_id = k.location_id JOIN {$items} i ON i.item_id = k.item_id AND i.stock_type = 0
             WHERE ABS(COALESCE(q.quantity, 0) - k.moved) > 0.0005 LIMIT 5",
        )->getResult();

        foreach ($rows as $row) {
            $problems[] = "item {$row->item_id} holds {$row->held} in location {$row->location_id} and its movements add up to {$row->moved}";
        }

        $rows = $this->db->query(
            "SELECT q.item_id, q.location_id, q.quantity FROM {$held} q WHERE NOT EXISTS (SELECT 1 FROM {$moved} m WHERE m.trans_items = q.item_id AND m.trans_location = q.location_id) AND q.quantity <> 0 LIMIT 5",
        )->getResult();

        foreach ($rows as $row) {
            $problems[] = "item {$row->item_id} holds {$row->quantity} in location {$row->location_id} with no movement at all";
        }

        $option = (string) ($this->db->table('app_config')->select('value')->where('key', 'negative_stock_option')->get()->getRow()->value ?? 'allow');

        if ($option !== 'allow') {
            $rows = $this->db->query("SELECT item_id, location_id, quantity FROM {$held} WHERE quantity < 0 LIMIT 5")->getResult();

            foreach ($rows as $row) {
                $problems[] = "item {$row->item_id} is at {$row->quantity} in location {$row->location_id} while the shop refuses negative stock";
            }
        }

        $entries = $this->db->prefixTable('accounting_stock_entries');
        $rows    = $this->db->query(
            "SELECT e.item_id, e.location_id, SUM(e.quantity) AS valued, COALESCE(q.quantity, 0) AS held FROM {$entries} e LEFT JOIN {$held} q ON q.item_id = e.item_id AND q.location_id = e.location_id
             GROUP BY e.item_id, e.location_id, q.quantity HAVING ABS(SUM(e.quantity) - COALESCE(q.quantity, 0)) > 0.0005 LIMIT 5",
        )->getResult();

        foreach ($rows as $row) {
            $problems[] = "item {$row->item_id} holds {$row->held} in location {$row->location_id} and its valued movements add up to {$row->valued}";
        }

        return $problems;
    }

    /**
     * The inventory account of a location holds what its valued movements are worth, and a journal and its movements agree.
     *
     * @return list<string>
     */
    public function stockValueMatchesInventoryAccounts(): array
    {
        $problems = [];
        $entries  = $this->db->prefixTable('accounting_stock_entries');

        $rows = $this->db->query(
            "SELECT a.entity_id AS location_id, COALESCE((SELECT SUM(l.debit - l.credit) FROM {$this->lines} l JOIN {$this->journals} j ON j.journal_id = l.journal_id WHERE l.account_id = a.account_id AND j.status IN ('posted', 'reversed')), 0) AS account,
                    COALESCE((SELECT SUM(e.value) FROM {$entries} e WHERE e.location_id = a.entity_id), 0) AS moved
             FROM {$this->accounts} a WHERE a.entity_type = 'stock_location' HAVING ABS(account - moved) > 0.0005 LIMIT 5",
        )->getResult();

        foreach ($rows as $row) {
            $problems[] = "inventory account of location {$row->location_id} holds {$row->account} and its movements are worth {$row->moved}";
        }

        $ledger = new StockLedger($this->db);

        foreach ($ledger->mismatchedJournals() as $mismatch) {
            $problems[] = "journal {$mismatch['journal_id']} puts {$mismatch['journal']} on location {$mismatch['location_id']} and its movements say {$mismatch['movements']}";
        }

        return array_slice($problems, 0, 5);
    }

    /**
     * The tax a sale's journal posts is the tax its saved rows carry, and the application's tax report ties to the tax accounts.
     *
     * @return list<string>
     */
    public function taxAccountsMatchTaxRows(): array
    {
        $problems = [];
        $report   = (new TaxReport($this->db))->build(ReportScope::resolve(null, null, $this->db), ReportPeriod::of(null, date('Y-m-d', strtotime('+1 day'))));

        if (! $report->ties()) {
            $problems[] = 'the tax report does not tie to the tax accounts';
        }

        return $problems;
    }

    /**
     * The tie checks of the readiness report, run on the books as they stand.
     *
     * @return list<string>
     */
    public function readinessTies(): array
    {
        $problems = [];

        foreach ([new TieChecks($this->db), new LedgerChecks($this->db), new StockTrackingChecks($this->db), new PurchaseCycleChecks($this->db)] as $group) {
            /** @var CheckGroup $group */
            foreach ($group->run() as $result) {
                if ($result->status === CheckStatus::Fail || $result->status === CheckStatus::Error) {
                    $problems[] = $result->ref . ' ' . $result->status->value . ': ' . $result->summary . ' ' . implode(' | ', array_slice($result->findings, 0, 3));
                }
            }
        }

        return $problems;
    }

    private function abs(string $number): string
    {
        return str_starts_with($number, '-') ? substr($number, 1) : $number;
    }
}
