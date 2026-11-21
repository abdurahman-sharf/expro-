<?php

namespace App\Libraries\Tax;

use App\Libraries\Accounting\ReportPeriod;
use App\Libraries\Accounting\ReportScope;
use App\Libraries\Accounting\TaxReport;
use App\Libraries\Accounting\TaxReportResult;
use App\Libraries\Accounting\TaxSection;
use App\Libraries\Accounting\TaxSectionFigures;
use CodeIgniter\Database\BaseConnection;
use Config\Database;

/**
 * The draft of the VAT return of a period, worked out from the books and never sent anywhere (T13, D-16). Its figures
 * come from the tax report, which ties to the ledger: what carries tax goes to the standard-rated boxes (1 and 7),
 * and what carries none is split by the categories the sales were saved with under the Saudi rules into zero-rated
 * sales (3), exports (4) and exempt sales (5), what is out of scope being left out. What carries no tax and was saved
 * before the rules cannot be told apart and is put with the zero-rated sales, counted as estimated. What the books
 * do not hold (sales to citizens, imports through customs or by reverse charge, exempt purchases, corrections of
 * earlier periods and credit carried forward) is typed in by hand. The numbering of the boxes follows the return
 * as it is published (T0 read boxes 1, 4, 8, 9, 10 and 14 in the text).
 */
final class VatReturnDraft
{
    public const MANUAL   = ['2', '8', '9', '11', '14', '15'];
    private const EXPORTS = ['VATEX-SA-32', 'VATEX-SA-33'];

    private BaseConnection $db;

    public function __construct(?BaseConnection $db = null)
    {
        $this->db = $db ?? Database::connect();
    }

    /**
     * @param array<string, array{amount?: string, vat?: string}> $manual By box.
     *
     * @return array{boxes: array<string, array{amount: string, vat: string}>, estimated: string}
     */
    public function build(string $from, string $to, array $manual = []): array
    {
        $report = (new TaxReport($this->db))->build(ReportScope::resolve(null, null), ReportPeriod::of($from, $to));

        return self::boxes($report, $this->categories($from, $to), $manual);
    }

    /**
     * @param array{zero: string, exports: string, exempt: string, outside: string} $categories What the sales under the rules carried at no tax, by kind.
     * @param array<string, array{amount?: string, vat?: string}>                   $manual
     *
     * @return array{boxes: array<string, array{amount: string, vat: string}>, estimated: string}
     */
    public static function boxes(TaxReportResult $report, array $categories, array $manual = []): array
    {
        $boxes = array_fill_keys(array_map('strval', range(1, 16)), ['amount' => '0.00', 'vat' => '0.00']);
        $zero  = '0';

        foreach ([TaxSection::SalesInvoices, TaxSection::SalesReturns, TaxSection::Revenues] as $section) {
            foreach (self::byRate($report->section($section)) as [$rate, $base, $tax]) {
                if ($rate) {
                    $boxes['1'] = self::add($boxes['1'], $base, $tax);
                } else {
                    $zero = bcadd($zero, $base, 4);
                }
            }
        }

        foreach ([TaxSection::Purchases, TaxSection::PurchaseReturns, TaxSection::Expenses] as $section) {
            foreach (self::byRate($report->section($section)) as [$rate, $base, $tax]) {
                $boxes[$rate ? '7' : '10'] = self::add($boxes[$rate ? '7' : '10'], bcsub('0', $base, 4), bcsub('0', $tax, 4));
            }
        }

        $known     = bcadd(bcadd($categories['zero'], $categories['exports'], 4), bcadd($categories['exempt'], $categories['outside'], 4), 4);
        $estimated = bcsub($zero, $known, 4);

        $boxes['3'] = self::add($boxes['3'], bcadd($categories['zero'], $estimated, 4), '0');
        $boxes['4'] = self::add($boxes['4'], $categories['exports'], '0');
        $boxes['5'] = self::add($boxes['5'], $categories['exempt'], '0');

        foreach (self::MANUAL as $box) {
            $boxes[$box] = self::add($boxes[$box], (string) ($manual[$box]['amount'] ?? '0'), (string) ($manual[$box]['vat'] ?? '0'));
        }

        foreach (['1', '2', '3', '4', '5'] as $box) {
            $boxes['6'] = self::add($boxes['6'], $boxes[$box]['amount'], $boxes[$box]['vat']);
        }

        foreach (['7', '8', '9', '10', '11'] as $box) {
            $boxes['12'] = self::add($boxes['12'], $boxes[$box]['amount'], $boxes[$box]['vat']);
        }

        $boxes['13'] = ['amount' => '0.00', 'vat' => bcsub($boxes['6']['vat'], $boxes['12']['vat'], 2)];
        $boxes['16'] = ['amount' => '0.00', 'vat' => bcsub(bcadd($boxes['13']['vat'], $boxes['14']['vat'], 2), $boxes['15']['vat'], 2)];

        return ['boxes' => $boxes, 'estimated' => bcadd($estimated, '0', 2)];
    }

    /**
     * @return list<array{0: bool, 1: string, 2: string}> Whether the rate carries tax, the base and the tax, signed as the report signs them.
     */
    private static function byRate(TaxSectionFigures $figures): array
    {
        if ($figures->rates === []) {
            return bccomp($figures->base, '0', 4) === 0 && bccomp($figures->tax, '0', 4) === 0 ? [] : [[bccomp($figures->tax, '0', 4) !== 0, $figures->base, $figures->tax]];
        }

        return array_map(static fn ($row): array => [bccomp($row->rate, '0', 4) > 0, $row->base, $row->tax], $figures->rates);
    }

    /**
     * @param array{amount: string, vat: string} $box
     *
     * @return array{amount: string, vat: string}
     */
    private static function add(array $box, string $amount, string $vat): array
    {
        return ['amount' => bcadd(bcadd($box['amount'], $amount, 4), '0', 2), 'vat' => bcadd(bcadd($box['vat'], $vat, 4), '0', 2)];
    }

    /**
     * What the sales saved under the rules in the period carried at no tax, by kind, from their categories.
     *
     * @return array{zero: string, exports: string, exempt: string, outside: string}
     */
    private function categories(string $from, string $to): array
    {
        $found = ['zero' => '0', 'exports' => '0', 'exempt' => '0', 'outside' => '0'];

        if (! $this->db->tableExists($this->db->prefixTable('sales_vat_totals'), false)) {
            return $found;
        }

        $rows = $this->db->table('sales_vat_totals t')
            ->select('t.category, t.exemption_code, SUM(t.taxable) AS taxable', false)
            ->join('sales s', 's.sale_id = t.sale_id')
            ->where('s.sale_status', COMPLETED)
            ->whereIn('t.category', ['Z', 'E', 'O'])
            ->where('COALESCE(s.invoice_date, DATE(s.sale_time)) >= ' . $this->db->escape($from), null, false)
            ->where('COALESCE(s.invoice_date, DATE(s.sale_time)) <= ' . $this->db->escape($to), null, false)
            ->groupBy('t.category, t.exemption_code')
            ->get()
            ->getResult();

        foreach ($rows as $row) {
            $kind = match (true) {
                $row->category === 'E'                                       => 'exempt',
                $row->category === 'O'                                       => 'outside',
                in_array((string) $row->exemption_code, self::EXPORTS, true) => 'exports',
                default                                                      => 'zero',
            };

            $found[$kind] = bcadd($found[$kind], (string) $row->taxable, 4);
        }

        return $found;
    }
}
