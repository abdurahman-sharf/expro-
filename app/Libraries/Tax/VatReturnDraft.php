<?php

namespace App\Libraries\Tax;

use App\Libraries\Accounting\ReportPeriod;
use App\Libraries\Accounting\ReportScope;
use App\Libraries\Accounting\TaxDocuments;
use App\Libraries\Accounting\TaxReport;
use App\Libraries\Accounting\TaxReportResult;
use App\Libraries\Accounting\TaxSection;
use App\Libraries\Accounting\TaxSectionFigures;
use CodeIgniter\Database\BaseConnection;
use Config\Database;

/**
 * The draft of the VAT return of a period, worked out from the books and never sent anywhere (T13, D-16). What
 * carries tax comes from the tax report, which ties to the ledger, into the standard-rated boxes (1 and 7), every
 * halala of tax the report holds with it, with or without a rate. What the sales saved under the Saudi rules carried
 * at no tax goes by their categories: private education and healthcare to citizens (2), zero-rated sales (3),
 * exports (4) and exempt sales (5), what is out of scope being left out. What the books do not tell (imports through
 * customs or by reverse charge, zero-rated and exempt purchases, corrections of earlier periods and credit carried
 * forward) is typed in by hand. Box 9 is owed and deducted at once, as the return treats it.
 *
 * The sales saved before the rules that carried no tax are never put in a box by guess: they are counted as not
 * classified, and the return is not approved until someone shares them out among boxes 3, 4 and 5 and out of scope,
 * to the halala, with a note, nor while documents whose lines do not match their journals are not explained, nor
 * while the report does not tie to the ledger (approvalBlockers). Each box is added up at four decimals and rounded
 * once. The numbering of the boxes follows the return as it is published.
 */
final class VatReturnDraft
{
    public const MANUAL           = ['8', '9', '10', '11', '14', '15'];
    public const REVIEW_KINDS     = ['zero', 'exports', 'exempt', 'outside'];
    public const BLOCK_UNREVIEWED = 'unclassified';
    public const BLOCK_UNMATCHED  = 'unmatched';
    public const BLOCK_UNTIED     = 'untied';
    private const EXPORTS         = ['VATEX-SA-32', 'VATEX-SA-33'];
    private const CITIZENS        = ['VATEX-SA-EDU', 'VATEX-SA-HEA'];
    private const SCALE           = 4;

    private BaseConnection $db;

    public function __construct(?BaseConnection $db = null)
    {
        $this->db = $db ?? Database::connect();
    }

    /**
     * @param array<string, array{amount?: string, vat?: string}> $manual By box.
     * @param array<string, string>                               $review How the sales not classified are shared out, by kind, with a `note`, and the `unmatched_note` that explains the documents whose lines do not match their journals.
     *
     * @return array{boxes: array<string, array{amount: string, vat: string}>, unclassified: string, review: array<string, string>, unmatched: int, ties: bool, blockers: list<string>}
     */
    public function build(string $from, string $to, array $manual = [], array $review = []): array
    {
        $report = (new TaxReport($this->db))->build(ReportScope::resolve(null, null), ReportPeriod::of($from, $to));

        return self::boxes($report, $this->categories($from, $to), $manual, $this->unclassified($from, $to), $review);
    }

    /**
     * @param array{citizens?: string, zero: string, exports: string, exempt: string, outside: string} $categories   What the sales under the rules carried at no tax, by kind.
     * @param array<string, array{amount?: string, vat?: string}>                                      $manual
     * @param string                                                                                   $unclassified What the sales saved before the rules carried at no tax.
     * @param array<string, string>                                                                    $review
     *
     * @return array{boxes: array<string, array{amount: string, vat: string}>, unclassified: string, review: array<string, string>, unmatched: int, ties: bool, blockers: list<string>}
     */
    public static function boxes(TaxReportResult $report, array $categories, array $manual = [], string $unclassified = '0', array $review = []): array
    {
        $boxes = array_fill_keys(array_map('strval', range(1, 16)), ['amount' => '0', 'vat' => '0']);

        foreach ([TaxSection::SalesInvoices, TaxSection::SalesReturns, TaxSection::Revenues] as $section) {
            foreach (self::byRate($report->section($section)) as [$taxed, $base, $tax]) {
                if ($taxed) {
                    $boxes['1'] = self::add($boxes['1'], $base, $tax);
                } else {
                    $unclassified = bcadd($unclassified, $base, self::SCALE);
                }
            }
        }

        foreach ([TaxSection::Purchases, TaxSection::PurchaseReturns, TaxSection::Expenses] as $section) {
            foreach (self::byRate($report->section($section)) as [$taxed, $base, $tax]) {
                if ($taxed) {
                    $boxes['7'] = self::add($boxes['7'], bcsub('0', $base, self::SCALE), bcsub('0', $tax, self::SCALE));
                }
            }
        }

        $shared = self::shared($review);

        $boxes['2'] = self::add($boxes['2'], $categories['citizens'] ?? '0', '0');
        $boxes['3'] = self::add($boxes['3'], bcadd($categories['zero'], $shared['zero'], self::SCALE), '0');
        $boxes['4'] = self::add($boxes['4'], bcadd($categories['exports'], $shared['exports'], self::SCALE), '0');
        $boxes['5'] = self::add($boxes['5'], bcadd($categories['exempt'], $shared['exempt'], self::SCALE), '0');

        foreach (self::MANUAL as $box) {
            $boxes[$box] = self::add($boxes[$box], self::number($manual[$box]['amount'] ?? '0'), self::number($manual[$box]['vat'] ?? '0'));
        }

        foreach (['1', '2', '3', '4', '5'] as $box) {
            $boxes['6'] = self::add($boxes['6'], $boxes[$box]['amount'], $boxes[$box]['vat']);
        }

        foreach (['7', '8', '9', '10', '11'] as $box) {
            $boxes['12'] = self::add($boxes['12'], $boxes[$box]['amount'], $boxes[$box]['vat']);
        }

        $boxes['13'] = ['amount' => '0', 'vat' => bcsub(bcadd($boxes['6']['vat'], $boxes['9']['vat'], self::SCALE), $boxes['12']['vat'], self::SCALE)];
        $boxes['16'] = ['amount' => '0', 'vat' => bcsub(bcadd($boxes['13']['vat'], $boxes['14']['vat'], self::SCALE), $boxes['15']['vat'], self::SCALE)];

        $unclassified = VatCalculator::round($unclassified, 2);

        return [
            'boxes'        => array_map(static fn (array $box): array => ['amount' => VatCalculator::round($box['amount'], 2), 'vat' => VatCalculator::round($box['vat'], 2)], $boxes),
            'unclassified' => $unclassified,
            'review'       => [...array_map(static fn (string $amount): string => VatCalculator::round($amount, 2), $shared), 'note' => trim($review['note'] ?? ''), 'unmatched_note' => trim($review['unmatched_note'] ?? '')],
            'unmatched'    => $report->unmatchedDocuments,
            'ties'         => $report->ties(),
            'blockers'     => self::blockers($unclassified, $shared, $review, $report),
        ];
    }

    /**
     * What keeps a draft from being approved: sales not classified that were not shared out to the halala with a
     * note, documents whose lines do not match their journals left unexplained, and a report that does not tie.
     *
     * @param array<string, string> $shared
     * @param array<string, string> $review
     *
     * @return list<string>
     */
    private static function blockers(string $unclassified, array $shared, array $review, TaxReportResult $report): array
    {
        $blockers = [];
        $total    = array_reduce($shared, static fn (string $sum, string $amount): string => bcadd($sum, $amount, self::SCALE), '0');

        if (bccomp($unclassified, '0', 2) !== 0 && (bccomp(VatCalculator::round($total, 2), $unclassified, 2) !== 0 || trim($review['note'] ?? '') === '')) {
            $blockers[] = self::BLOCK_UNREVIEWED;
        }

        if ($report->unmatchedDocuments > 0 && trim($review['unmatched_note'] ?? '') === '') {
            $blockers[] = self::BLOCK_UNMATCHED;
        }

        if (! $report->ties()) {
            $blockers[] = self::BLOCK_UNTIED;
        }

        return $blockers;
    }

    /**
     * @param array<string, string> $review
     *
     * @return array<string, string> The amount shared to each kind.
     */
    private static function shared(array $review): array
    {
        $shared = [];

        foreach (self::REVIEW_KINDS as $kind) {
            $shared[$kind] = self::number($review[$kind] ?? '0');
        }

        return $shared;
    }

    /**
     * The rows of a section, with whatever tax and base the report holds outside its rates as a row of its own (a
     * deleted purchase, a document whose lines do not match its journal), so that no tax of the ledger is left out.
     *
     * @return list<array{0: bool, 1: string, 2: string}> Whether the row carries tax, the base and the tax, signed as the report signs them.
     */
    private static function byRate(TaxSectionFigures $figures): array
    {
        $rows = array_map(static fn ($row): array => [bccomp($row->tax, '0', self::SCALE) !== 0 || bccomp($row->rate, '0', self::SCALE) > 0, (string) $row->base, (string) $row->tax], $figures->rates);
        $base = bcsub($figures->base, array_reduce($rows, static fn (string $sum, array $row): string => bcadd($sum, $row[1], self::SCALE), '0'), self::SCALE);
        $tax  = bcsub($figures->tax, array_reduce($rows, static fn (string $sum, array $row): string => bcadd($sum, $row[2], self::SCALE), '0'), self::SCALE);

        if (bccomp($base, '0', self::SCALE) !== 0 || bccomp($tax, '0', self::SCALE) !== 0) {
            $rows[] = [bccomp($tax, '0', self::SCALE) !== 0, $base, $tax];
        }

        return $rows;
    }

    /**
     * @param array{amount: string, vat: string} $box
     *
     * @return array{amount: string, vat: string}
     */
    private static function add(array $box, string $amount, string $vat): array
    {
        return ['amount' => bcadd($box['amount'], $amount, self::SCALE), 'vat' => bcadd($box['vat'], $vat, self::SCALE)];
    }

    private static function number(mixed $value): string
    {
        $text = trim((string) $value);

        return is_numeric($text) ? bcadd($text, '0', self::SCALE) : '0';
    }

    /**
     * What the sales saved under the rules in the period carried at no tax, by kind, from their categories, in the
     * base currency.
     *
     * @return array{citizens: string, zero: string, exports: string, exempt: string, outside: string}
     */
    private function categories(string $from, string $to): array
    {
        $found = ['citizens' => '0', 'zero' => '0', 'exports' => '0', 'exempt' => '0', 'outside' => '0'];

        $branches = ReportScope::resolve(null, null)->ids;

        if ($branches === [] || ! $this->db->tableExists($this->db->prefixTable('sales_vat_totals'), false)) {
            return $found;
        }

        $rows = $this->db->table('sales_vat_totals t')
            ->select('t.category, t.exemption_code, SUM(t.taxable * COALESCE(CASE WHEN s.currency_code IS NULL THEN NULL ELSE s.currency_rate END, 1)) AS taxable', false)
            ->join('sales s', 's.sale_id = t.sale_id')
            ->where('s.sale_status', COMPLETED)
            ->whereIn('s.branch_id', $branches)
            ->whereIn('s.sale_type', [SALE_TYPE_POS, SALE_TYPE_INVOICE, SALE_TYPE_RETURN])
            ->whereIn('t.category', ['Z', 'E', 'O'])
            ->where('COALESCE(s.invoice_date, DATE(s.sale_time)) >= ' . $this->db->escape($from), null, false)
            ->where('COALESCE(s.invoice_date, DATE(s.sale_time)) <= ' . $this->db->escape($to), null, false)
            ->groupBy('t.category, t.exemption_code')
            ->get()
            ->getResult();

        foreach ($rows as $row) {
            $kind = match (true) {
                $row->category === 'E'                                        => 'exempt',
                $row->category === 'O'                                        => 'outside',
                in_array((string) $row->exemption_code, self::EXPORTS, true)  => 'exports',
                in_array((string) $row->exemption_code, self::CITIZENS, true) => 'citizens',
                default                                                       => 'zero',
            };

            $found[$kind] = bcadd($found[$kind], (string) $row->taxable, self::SCALE);
        }

        return $found;
    }

    /**
     * What the sales of the period saved before the rules carried at no tax, in the base currency, which nothing in
     * the books classifies.
     */
    private function unclassified(string $from, string $to): string
    {
        $branches = ReportScope::resolve(null, null)->ids;

        if ($branches === []) {
            return '0';
        }

        $saleIds = array_map('intval', array_column($this->db->table('sales s')
            ->select('s.sale_id')
            ->where('s.sale_status', COMPLETED)
            ->whereIn('s.branch_id', $branches)
            ->whereIn('s.sale_type', [SALE_TYPE_POS, SALE_TYPE_INVOICE, SALE_TYPE_RETURN])
            ->where('COALESCE(s.invoice_date, DATE(s.sale_time)) >= ' . $this->db->escape($from), null, false)
            ->where('COALESCE(s.invoice_date, DATE(s.sale_time)) <= ' . $this->db->escape($to), null, false)
            ->get()
            ->getResultArray(), 'sale_id'));

        if ($saleIds === []) {
            return '0';
        }

        return array_reduce((new TaxDocuments($this->db))->untaxedSales($saleIds), static fn (string $sum, string $amount): string => bcadd($sum, $amount, self::SCALE), '0');
    }
}
