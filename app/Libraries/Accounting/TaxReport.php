<?php

namespace App\Libraries\Accounting;

use App\Libraries\Organization\CompanyContext;
use CodeIgniter\Database\BaseConnection;
use Config\Database;
use DateTimeImmutable;

/**
 * The tax of a period, read from the journals that are in the books and not from the sales tables:
 * the tax collected on sales and the tax paid on purchases and expenses are what the journals did
 * to the accounts of tax, a journal that was reversed with its reversal, so that a document that
 * was taken back counts for nothing and the report can be set beside the trial balance of the same
 * branches and days. Each journal is sorted into the part of the report its document belongs to:
 * a sale, a return of a sale, a purchase, a purchase taken back, an expense or a receipt voucher.
 *
 * What the tax was charged on, and the rate, come from the lines of the document the journal was
 * posted for, the way posting reads them, so a document that was never posted has no part in it.
 * For an expense and a receipt voucher, which carry no lines, it comes from the journal itself. A document whose lines
 * no longer say what its journal says, or that is gone with its lines as a deleted purchase is,
 * keeps its tax in the report and leaves its base out, so that a period that was read once is read
 * the same after the document is deleted, and the report still ties to the books. What the
 * accounts of tax hold from anything else, such as a manual journal, an opening balance or a
 * settlement of the tax, is not tax of a document: it is shown beside the report and is no part of
 * what is payable. Only the accrual basis exists. Reading changes nothing, and it is done in one
 * snapshot.
 */
final class TaxReport
{
    use RunsInTransaction;

    private const SCALE = 4;

    private BaseConnection $db;
    private TaxDocuments $documents;
    private AccountingSettings $settings;

    public function __construct(?BaseConnection $db = null)
    {
        $this->db        = $db ?? Database::connect();
        $this->documents = new TaxDocuments($this->db);
        $this->settings  = new AccountingSettings($this->db);
    }

    /**
     * @throws AccountingException
     */
    public function build(ReportScope $scope, ReportPeriod $period, TaxBasis $basis = TaxBasis::Accrual): TaxReportResult
    {
        if ($basis !== TaxBasis::Accrual) {
            throw new AccountingException(AccountingError::TaxBasisUnavailable, 'Only the accrual basis exists.', ['basis' => $basis->value]);
        }

        return $this->transactional(fn (): TaxReportResult => $this->read($scope, $period));
    }

    private function read(ReportScope $scope, ReportPeriod $period): TaxReportResult
    {
        $routed = $this->taxAccounts();
        $rows   = $scope->isEmpty() || $routed['accounts'] === [] ? [] : $this->movements($scope, $period, array_keys($routed['accounts']));

        $parts     = array_fill_keys(array_map(static fn (TaxSection $section): string => $section->value, TaxSection::cases()), ['base' => '0', 'tax' => '0', 'documents' => 0, 'rates' => [], 'discounted' => ['documents' => 0, 'base' => '0', 'tax' => '0']]);
        $ledger    = [];
        $reported  = [];
        $others    = [];
        $pending   = [];
        $lineless  = [];
        $unmatched = 0;

        foreach ($rows as $row) {
            $accountId = (int) $row->account_id;
            $movement  = (string) $row->movement;
            $type      = (string) ($row->origin_type ?? $row->source_type);
            $sourceId  = (int) ($row->origin_id ?? $row->source_id);

            $ledger[$accountId] = bcadd($ledger[$accountId] ?? '0', $movement, self::SCALE);

            if ($type === ExpensePostingBuilder::SOURCE || $type === RevenuePostingBuilder::SOURCE) {
                $section = $type === ExpensePostingBuilder::SOURCE ? TaxSection::Expenses : TaxSection::Revenues;

                $reported[$accountId]                 = bcadd($reported[$accountId] ?? '0', $movement, self::SCALE);
                $lineless[$section->value][$sourceId] = bcadd($lineless[$section->value][$sourceId] ?? '0', $movement, self::SCALE);

                $this->addWithoutLines($parts[$section->value], $movement, (string) $row->total_debit);

                continue;
            }

            if ($type === SalePostingBuilder::SOURCE_SALE || $type === ReceivingPostingBuilder::SOURCE_PURCHASE) {
                $pending[$type][$sourceId][] = $row;

                continue;
            }

            $others[$accountId][(int) $row->journal_id] = true;
        }

        foreach ($lineless as $section => $documents) {
            $parts[$section]['documents'] = count(array_filter($documents, static fn (string $net): bool => bccomp($net, '0', self::SCALE) !== 0));
        }

        foreach ($pending as $type => $documents) {
            $loaded = $type === SalePostingBuilder::SOURCE_SALE ? $this->documents->sales(array_keys($documents)) : $this->documents->purchases(array_keys($documents));

            foreach ($documents as $sourceId => $documentRows) {
                $document = $loaded[$sourceId] ?? null;
                $movement = '0';

                foreach ($documentRows as $row) {
                    $reported[(int) $row->account_id] = bcadd($reported[(int) $row->account_id] ?? '0', (string) $row->movement, self::SCALE);
                    $movement                         = bcadd($movement, (string) $row->movement, self::SCALE);
                }

                $kept = $document === null
                    ? $this->addGone($parts, $type, $documentRows, $movement)
                    : $this->addDocument($parts[$document->section->value], $document, $movement);

                $unmatched += $kept ? 0 : 1;
            }
        }

        return new TaxReportResult(
            $this->sections($parts),
            $this->accountFigures($routed['accounts'], $ledger, $reported, $others),
            $scope,
            $period->from,
            $period->to,
            TaxBasis::Accrual,
            $this->settings->startDate(),
            $scope->isEmpty() ? 0 : $this->legacyDocuments($scope, $period),
            $unmatched,
            $routed['output'],
            $routed['input'],
        );
    }

    /**
     * The accounts the tax is routed to, which is where the books hold it today.
     *
     * @return array{accounts: array<int, array{code: string, name: string, roles: list<string>}>, output: bool, input: bool}
     */
    private function taxAccounts(): array
    {
        $routes = [];

        foreach ($this->db->table('accounting_routing r')
            ->select('r.routing_key, a.account_id, a.code, a.name')
            ->join('accounting_accounts a', 'a.account_id = r.account_id')
            ->where('r.company_id', CompanyContext::id())
            ->whereIn('r.routing_key', [RoutingKey::TaxOutput->value, RoutingKey::TaxInput->value])
            ->get()
            ->getResult() as $route) {
            $routes[$route->routing_key] = $route;
        }

        $accounts = [];
        $routed   = ['output' => false, 'input' => false];

        foreach ([RoutingKey::TaxOutput->value => 'output', RoutingKey::TaxInput->value => 'input'] as $key => $role) {
            if (! isset($routes[$key])) {
                continue;
            }

            $accountId = (int) $routes[$key]->account_id;
            $accounts[$accountId] ??= ['code' => (string) $routes[$key]->code, 'name' => (string) $routes[$key]->name, 'roles' => []];
            $accounts[$accountId]['roles'][] = $role;
            $routed[$role]                   = true;
        }

        uasort($accounts, static fn (array $first, array $second): int => strcmp($first['code'], $second['code']));

        return ['accounts' => $accounts, 'output' => $routed['output'], 'input' => $routed['input']];
    }

    /**
     * What each journal did to each account of tax, with the document it belongs to: its own, or
     * the one it reverses.
     *
     * @param list<int> $accountIds
     *
     * @return list<object>
     */
    private function movements(ReportScope $scope, ReportPeriod $period, array $accountIds): array
    {
        $builder = $this->db->table('accounting_journal_lines l')
            ->select('j.journal_id, j.source_type, j.source_id, j.total_debit, o.source_type AS origin_type, o.source_id AS origin_id, l.account_id, SUM(l.credit - l.debit) AS movement', false)
            ->join('accounting_journals j', 'j.journal_id = l.journal_id')
            ->join('accounting_journals o', 'o.journal_id = j.reverses_journal_id', 'left')
            ->where('j.company_id', CompanyContext::id())
            ->whereIn('j.status', [JournalStatus::Posted->value, JournalStatus::Reversed->value])
            ->whereIn('j.branch_id', $scope->ids)
            ->whereIn('l.account_id', $accountIds)
            ->where('j.journal_date <=', $period->to)
            ->groupBy('j.journal_id, j.source_type, j.source_id, j.total_debit, o.source_type, o.source_id, l.account_id');

        if ($period->from !== null) {
            $builder->where('j.journal_date >=', $period->from);
        }

        return $builder->get()->getResult();
    }

    /**
     * An expense and a receipt voucher have no lines, so what the tax was charged on is the journal
     * less the tax in it.
     *
     * @param array{base: string, tax: string, documents: int, rates: array<string, array{base: string, tax: string, documents: int}>, discounted: array{documents: int, base: string, tax: string}} $part
     */
    private function addWithoutLines(array &$part, string $movement, string $totalDebit): void
    {
        $sign = bccomp($movement, '0', self::SCALE);

        if ($sign === 0) {
            return;
        }

        $base = bcsub($totalDebit, SaleAmounts::absolute($movement), self::SCALE);

        $part['base'] = bcadd($part['base'], self::signed($base, $sign), self::SCALE);
        $part['tax']  = bcadd($part['tax'], $movement, self::SCALE);
    }

    /**
     * A purchase deleted in the application is gone from the tables with its lines, but its journal
     * and the reversal of it stay in the books. They are still the tax of a document: the part comes
     * from the way the original journal moved the tax, which a reversal moved the other way, and the
     * base is not known.
     *
     * @param array<string, array{base: string, tax: string, documents: int, rates: array<string, array{base: string, tax: string, documents: int}>, discounted: array{documents: int, base: string, tax: string}}> $parts
     * @param list<object>                                                                                                                                                                                          $rows
     *
     * @return bool False when something of the document is left in the period.
     */
    private function addGone(array &$parts, string $type, array $rows, string $movement): bool
    {
        if (bccomp($movement, '0', self::SCALE) === 0) {
            return true;
        }

        $owed = '0';

        foreach ($rows as $row) {
            $owed = bcadd($owed, $row->origin_type === null ? (string) $row->movement : bcsub('0', (string) $row->movement, self::SCALE), self::SCALE);
        }

        $adds = bccomp($owed, '0', self::SCALE) >= 0;

        $section = $type === SalePostingBuilder::SOURCE_SALE
            ? ($adds ? TaxSection::SalesInvoices : TaxSection::SalesReturns)
            : ($adds ? TaxSection::PurchaseReturns : TaxSection::Purchases);

        $parts[$section->value]['documents']++;
        $parts[$section->value]['tax'] = bcadd($parts[$section->value]['tax'], $movement, self::SCALE);

        return false;
    }

    /**
     * @param array{base: string, tax: string, documents: int, rates: array<string, array{base: string, tax: string, documents: int}>, discounted: array{documents: int, base: string, tax: string}} $part
     *
     * @return bool Whether the lines of the document say what its journals say.
     */
    private function addDocument(array &$part, TaxDocument $document, string $movement): bool
    {
        $sign = bccomp($movement, '0', self::SCALE);

        if ($sign === 0) {
            return true;
        }

        $part['documents']++;
        $part['tax'] = bcadd($part['tax'], $movement, self::SCALE);

        if (bccomp(SaleAmounts::absolute($movement), $document->tax, self::SCALE) !== 0) {
            return false;
        }

        $part['base'] = bcadd($part['base'], self::signed($document->base, $sign), self::SCALE);

        foreach ($document->rates as $rate => $figures) {
            $row = $part['rates'][$rate] ?? ['base' => '0', 'tax' => '0', 'documents' => 0];

            $part['rates'][$rate] = [
                'base'      => bcadd($row['base'], self::signed($figures['base'], $sign), self::SCALE),
                'tax'       => bcadd($row['tax'], self::signed($figures['tax'], $sign), self::SCALE),
                'documents' => $row['documents'] + 1,
            ];
        }

        if ($document->discounted) {
            $part['discounted'] = [
                'documents' => $part['discounted']['documents'] + 1,
                'base'      => bcadd($part['discounted']['base'], self::signed($document->base, $sign), self::SCALE),
                'tax'       => bcadd($part['discounted']['tax'], $movement, self::SCALE),
            ];
        }

        return true;
    }

    /**
     * @param array<string, array{base: string, tax: string, documents: int, rates: array<string, array{base: string, tax: string, documents: int}>, discounted: array{documents: int, base: string, tax: string}}> $parts
     *
     * @return list<TaxSectionFigures>
     */
    private function sections(array $parts): array
    {
        $sections = [];

        foreach (TaxSection::cases() as $section) {
            $part  = $parts[$section->value];
            $rates = [];

            foreach ($part['rates'] as $rate => $figures) {
                $rates[] = new TaxRateRow((string) $rate, bcadd($figures['base'], '0', self::SCALE), bcadd($figures['tax'], '0', self::SCALE), $figures['documents']);
            }

            usort($rates, static fn (TaxRateRow $first, TaxRateRow $second): int => bccomp($second->rate, $first->rate, self::SCALE));

            $sections[] = new TaxSectionFigures(
                $section,
                bcadd($part['base'], '0', self::SCALE),
                bcadd($part['tax'], '0', self::SCALE),
                $part['documents'],
                $rates,
                $part['discounted']['documents'],
                bcadd($part['discounted']['base'], '0', self::SCALE),
                bcadd($part['discounted']['tax'], '0', self::SCALE),
            );
        }

        return $sections;
    }

    /**
     * @param array<int, array{code: string, name: string, roles: list<string>}> $routed
     * @param array<int, string>                                                 $ledger
     * @param array<int, string>                                                 $reported
     * @param array<int, array<int, true>>                                       $others
     *
     * @return list<TaxAccountFigures>
     */
    private function accountFigures(array $routed, array $ledger, array $reported, array $others): array
    {
        $figures = [];

        foreach ($routed as $accountId => $account) {
            $figures[] = new TaxAccountFigures(
                $accountId,
                $account['code'],
                $account['name'],
                $account['roles'],
                bcadd($ledger[$accountId] ?? '0', '0', self::SCALE),
                bcadd($reported[$accountId] ?? '0', '0', self::SCALE),
                count($others[$accountId] ?? []),
            );
        }

        return $figures;
    }

    /**
     * The sales, purchases and expenses with tax that fall in the period and before the day the
     * books began, which no journal holds. A document without a branch belongs to the default
     * branch.
     */
    private function legacyDocuments(ReportScope $scope, ReportPeriod $period): int
    {
        $start = $this->settings->startDate();

        if ($start === null) {
            return 0;
        }

        $last  = (new DateTimeImmutable($start))->modify('-1 day')->format('Y-m-d');
        $until = min($period->to, $last);

        if ($period->from !== null && $period->from > $until) {
            return 0;
        }

        $before = (new DateTimeImmutable($until))->modify('+1 day')->format('Y-m-d') . ' 00:00:00';
        $after  = $period->from === null ? null : $period->from . ' 00:00:00';

        return $this->legacySales($scope, $before, $after) + $this->legacyPurchases($scope, $before, $after) + $this->legacyExpenses($scope, $before, $after);
    }

    private function legacySales(ReportScope $scope, string $before, ?string $after): int
    {
        $builder = $this->db->table('sales s')
            ->select('COUNT(DISTINCT s.sale_id) AS total', false)
            ->join('sales_items_taxes t', 't.sale_id = s.sale_id')
            ->where('s.sale_status', COMPLETED)
            ->whereIn('s.sale_type', [SALE_TYPE_POS, SALE_TYPE_INVOICE, SALE_TYPE_RETURN])
            ->whereIn('s.branch_id', $scope->ids)
            ->where('s.sale_time <', $before);

        if ($after !== null) {
            $builder->where('s.sale_time >=', $after);
        }

        return (int) $builder->get()->getRow()->total;
    }

    private function legacyPurchases(ReportScope $scope, string $before, ?string $after): int
    {
        $builder = $this->db->table('receivings r')
            ->select('COUNT(DISTINCT r.receiving_id) AS total', false)
            ->join('receivings_items i', 'i.receiving_id = r.receiving_id')
            ->whereIn('r.receiving_type', [ReceivingType::Receive->value, ReceivingType::Return->value])
            ->where('i.tax_percent >', 0)
            ->whereIn('r.branch_id', $scope->ids)
            ->where('r.receiving_time <', $before);

        if ($after !== null) {
            $builder->where('r.receiving_time >=', $after);
        }

        return (int) $builder->get()->getRow()->total;
    }

    private function legacyExpenses(ReportScope $scope, string $before, ?string $after): int
    {
        $default = $this->db->table('branches')->select('branch_id')->where('company_id', CompanyContext::id())->where('is_default', 1)->get()->getRow();

        $builder = $this->db->table('expenses e')
            ->select('COUNT(e.expense_id) AS total', false)
            ->where('e.deleted', 0)
            ->where('e.tax_amount !=', 0)
            ->where('e.date <', $before)
            ->groupStart()
            ->whereIn('e.branch_id', $scope->ids);

        if ($default !== null && in_array((int) $default->branch_id, $scope->ids, true)) {
            $builder->orWhere('e.branch_id IS NULL', null, false);
        }

        $builder->groupEnd();

        if ($after !== null) {
            $builder->where('e.date >=', $after);
        }

        return (int) $builder->get()->getRow()->total;
    }

    /**
     * @param numeric-string $amount Not negative.
     *
     * @return numeric-string
     */
    private static function signed(string $amount, int $sign): string
    {
        return $sign < 0 ? bcsub('0', $amount, self::SCALE) : bcadd($amount, '0', self::SCALE);
    }
}
