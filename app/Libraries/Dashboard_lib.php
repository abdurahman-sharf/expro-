<?php

namespace App\Libraries;

use App\Libraries\Organization\BranchGuard;
use App\Libraries\Roles\RecordScope;
use App\Models\Reports\Inventory_low;
use App\Models\Reports\Summary_expenses_categories;
use App\Models\Reports\Summary_items;
use App\Models\Reports\Summary_sales;
use DateTimeImmutable;
use Throwable;

/**
 * Figures shown on the home page, taken from the report models so they match the reports.
 */
class Dashboard_lib
{
    public const TREND_DAYS     = 14;
    private const HISTORY_DAYS  = 60;
    private const CACHE_SECONDS = 120;

    private DateTimeImmutable $today;
    private ?RecordScope $recordScope = null;

    public function __construct(?DateTimeImmutable $today = null)
    {
        $this->today = $today ?? new DateTimeImmutable('today');
    }

    /**
     * @return array{today: array{total: float, count: int, average: float, delta: ?float}, week: array{total: float, delta: ?float}, month: array{total: float, delta: ?float}, series: array{labels: list<string>, values: list<float>}}|null
     */
    public function sales(): ?array
    {
        return $this->guarded('sales', function (?int $viewerId): array {
            $rows   = model(Summary_sales::class)->getData($this->range($this->today->modify('-' . (self::HISTORY_DAYS - 1) . ' days'), $this->today, $viewerId));
            $totals = [];
            $counts = [];

            foreach ($rows as $row) {
                $totals[$row['sale_date']] = (float) $row['total'];
                $counts[$row['sale_date']] = (int) $row['sales'];
            }

            $day = static fn (DateTimeImmutable $date): string => $date->format('Y-m-d');
            $sum = static function (array $values, DateTimeImmutable $from, DateTimeImmutable $to) use ($day): float {
                $total = 0.0;

                for ($date = $from; $date <= $to; $date = $date->modify('+1 day')) {
                    $total += $values[$day($date)] ?? 0.0;
                }

                return $total;
            };

            $today      = $this->today;
            $todayKey   = $day($today);
            $todayTotal = $totals[$todayKey] ?? 0.0;
            $todayCount = $counts[$todayKey] ?? 0;

            $monthStart         = $today->modify('first day of this month');
            $previousMonthStart = $monthStart->modify('-1 month');
            $previousMonthEnd   = min($previousMonthStart->modify('+' . ((int) $today->format('j') - 1) . ' days'), $previousMonthStart->modify('last day of this month'));

            $labels = [];
            $values = [];

            for ($offset = self::TREND_DAYS - 1; $offset >= 0; $offset--) {
                $date     = $today->modify("-{$offset} days");
                $labels[] = $date->format('m/d');
                $values[] = $totals[$day($date)] ?? 0.0;
            }

            return [
                'today' => [
                    'total'   => $todayTotal,
                    'count'   => $todayCount,
                    'average' => $todayCount > 0 ? $todayTotal / $todayCount : 0.0,
                    'delta'   => $this->delta($todayTotal, $totals[$day($today->modify('-1 day'))] ?? 0.0),
                ],
                'week' => [
                    'total' => $sum($totals, $today->modify('-6 days'), $today),
                    'delta' => $this->delta($sum($totals, $today->modify('-6 days'), $today), $sum($totals, $today->modify('-13 days'), $today->modify('-7 days'))),
                ],
                'month' => [
                    'total' => $sum($totals, $monthStart, $today),
                    'delta' => $this->delta($sum($totals, $monthStart, $today), $sum($totals, $previousMonthStart, $previousMonthEnd)),
                ],
                'series' => ['labels' => $labels, 'values' => $values],
            ];
        });
    }

    /**
     * @return list<array{name: string, quantity: float, total: float}>|null
     */
    public function topItems(int $limit = 5, int $days = 30): ?array
    {
        return $this->guarded("top_items_{$limit}", function (?int $viewerId) use ($limit, $days): array {
            $rows = model(Summary_items::class)->getData($this->range($this->today->modify('-' . ($days - 1) . ' days'), $this->today, $viewerId));
            usort($rows, static fn (array $first, array $second): int => (float) $second['quantity_purchased'] <=> (float) $first['quantity_purchased']);

            return array_map(static fn (array $row): array => [
                'name'     => (string) $row['name'],
                'quantity' => (float) $row['quantity_purchased'],
                'total'    => (float) $row['total'],
            ], array_slice($rows, 0, $limit));
        });
    }

    /**
     * @return array{count: int, rows: list<array{name: string, quantity: float, reorder_level: float, location: string}>}|null
     */
    public function lowStock(int $limit = 5): ?array
    {
        return $this->guarded("low_stock_{$limit}", static function () use ($limit): array {
            $rows = model(Inventory_low::class)->getData([]);

            return [
                'count' => count($rows),
                'rows'  => array_map(static fn (array $row): array => [
                    'name'          => (string) $row['name'],
                    'quantity'      => (float) $row['quantity'],
                    'reorder_level' => (float) $row['reorder_level'],
                    'location'      => (string) $row['location_name'],
                ], array_slice($rows, 0, $limit)),
            ];
        }, perViewer: false);
    }

    public function expensesThisMonth(): ?float
    {
        if ($this->workingBranchIds() !== null) {
            return null;
        }

        return $this->guarded('expenses_month', function (?int $viewerId): float {
            $summary = model(Summary_expenses_categories::class)->getSummaryData($this->range($this->today->modify('first day of this month'), $this->today, $viewerId));

            return (float) ($summary['expenses_total_amount'] ?? 0);
        });
    }

    private function delta(float $current, float $previous): ?float
    {
        return abs($previous) < 0.005 ? null : ($current - $previous) / abs($previous) * 100;
    }

    /**
     * @return array{start_date: string, end_date: string, sale_type: string, location_id: string, viewer_id: ?int}
     */
    private function range(DateTimeImmutable $start, DateTimeImmutable $end, ?int $viewerId): array
    {
        return [
            'start_date'  => $start->format('Y-m-d') . ' 00:00:00',
            'end_date'    => $end->format('Y-m-d') . ' 23:59:59',
            'sale_type'   => 'complete',
            'location_id' => 'all',
            'viewer_id'   => $viewerId,
        ];
    }

    /**
     * The employee the figures are cut down to: the one logged in when they see their own records only, and nobody
     * for anyone else, whose figures are those of the company and are shared between them.
     */
    private function viewerId(): ?int
    {
        return ($this->recordScope ??= new RecordScope())->limit(RecordScope::sessionEmployee());
    }

    /**
     * @return list<int>|null Null for someone who works in every branch.
     */
    private function workingBranchIds(): ?array
    {
        $employeeId = (int) session()->get('person_id');

        return $employeeId > 0 ? (new BranchGuard())->allowedBranchIds($employeeId) : null;
    }

    /**
     * The figures of the home page are the ones of the branches the employee works in, so what is
     * kept for one set of branches must never be shown to someone who works in another. And what is
     * kept for an employee who sees their own records only is theirs alone, so it is kept under their
     * number and never reaches anyone else.
     */
    private function branchKey(): string
    {
        $branchIds = $this->workingBranchIds();

        if ($branchIds === null) {
            return '';
        }

        sort($branchIds);

        return '_b' . implode('-', $branchIds);
    }

    /**
     * @param callable(?int): mixed $load
     * @param bool                  $perViewer False for a section that holds nothing of any employee.
     */
    private function guarded(string $section, callable $load, bool $perViewer = true): mixed
    {
        try {
            $viewerId = $perViewer ? $this->viewerId() : null;
            $key      = 'dashboard_' . $section . $this->branchKey() . ($viewerId === null ? '' : '_o' . $viewerId) . '_' . $this->today->format('Ymd');

            return cache()->remember($key, self::CACHE_SECONDS, static fn (): mixed => $load($viewerId));
        } catch (Throwable $exception) {
            log_message('error', "Dashboard section {$section} failed: " . $exception->getMessage());

            return null;
        }
    }
}
