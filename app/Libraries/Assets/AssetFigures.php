<?php

namespace App\Libraries\Assets;

use App\Libraries\Accounting\LockedRead;
use CodeIgniter\Database\BaseConnection;

/**
 * What an asset has depreciated by, from the lines of its runs that stand, and what the periods it has
 * not been run for would depreciate it by. An asset starts from the depreciation it had when it was
 * entered (an old asset's opening figure), and every run that stands adds the line it gave the asset.
 */
final class AssetFigures
{
    private const SCALE = 4;

    public function __construct(private readonly BaseConnection $db, private readonly DepreciationSchedule $schedule)
    {
    }

    public static function termsOf(object $asset): AssetTerms
    {
        return new AssetTerms(
            AssetMethod::from((string) $asset->method),
            bcadd((string) $asset->cost, '0', self::SCALE),
            bcadd((string) $asset->salvage, '0', self::SCALE),
            (string) $asset->in_service_date,
            (int) $asset->period_length,
            AssetInterval::from((string) $asset->period_interval),
            $asset->amount_per_period === null ? null : bcadd((string) $asset->amount_per_period, '0', self::SCALE),
            $asset->percentage === null ? null : bcadd((string) $asset->percentage, '0', self::SCALE),
            $asset->first_depreciation_date === null ? null : (string) $asset->first_depreciation_date,
            $asset->end_date === null ? null : (string) $asset->end_date,
            $asset->cost_per_unit === null ? null : (string) $asset->cost_per_unit,
            bcadd((string) $asset->opening_accumulated, '0', self::SCALE),
            $asset->opening_through === null ? null : (string) $asset->opening_through,
        );
    }

    /**
     * The amount of each period that stands, by the first day of the period.
     *
     * @return array<string, numeric-string>
     */
    public function posted(int $assetId, bool $lock = false): array
    {
        $table = $this->db->prefixTable('asset_depreciations');
        $sql   = "SELECT period_start, amount FROM {$table} WHERE asset_id = ? AND active_key = 1" . ($lock ? ' LOCK IN SHARE MODE' : '');
        $rows  = $lock ? LockedRead::rows($this->db, $sql, [$assetId]) : $this->db->query($sql, [$assetId])->getResult();
        $found = [];

        foreach ($rows as $row) {
            $found[(string) $row->period_start] = bcadd((string) $row->amount, '0', self::SCALE);
        }

        return $found;
    }

    /**
     * What the asset has depreciated by through a day (every run that stands when none is given),
     * counting from what it had when it was entered.
     *
     * @return numeric-string
     */
    public function accumulated(object $asset, ?string $asOf = null, bool $lock = false): string
    {
        $sum = bcadd((string) $asset->opening_accumulated, '0', self::SCALE);

        foreach ($this->posted((int) $asset->asset_id, $lock) as $start => $amount) {
            if ($asOf === null || DepreciationPeriod::containing($start)->end <= $asOf) {
                $sum = bcadd($sum, $amount, self::SCALE);
            }
        }

        return $sum;
    }

    /**
     * The units the asset was used for in a period, or null when none were entered. Under a lock the row
     * is read as it is committed now and not as the snapshot of a transaction that began earlier holds it.
     *
     * @return numeric-string|null
     */
    public function usage(int $assetId, DepreciationPeriod $period, bool $lock = false): ?string
    {
        $table = $this->db->prefixTable('asset_usage');
        $sql   = "SELECT units FROM {$table} WHERE asset_id = ? AND period_start = ?" . ($lock ? ' LOCK IN SHARE MODE' : '');
        $args  = [$assetId, $period->start];
        $row   = $lock ? LockedRead::row($this->db, $sql, $args) : $this->db->query($sql, $args)->getRow();

        return $row === null ? null : bcadd((string) $row->units, '0', 2);
    }

    /**
     * What the asset is to be depreciated by for a period, whether or not it has been run: the events
     * of its schedule that fall in the period, or for the units of production method the units its
     * period was used for at the cost of a unit. Nothing for an asset that is not depreciated, and
     * never more than is left of the cost less the salvage.
     *
     * @param array<string, numeric-string> $posted
     *
     * @return numeric-string
     */
    public function due(object $asset, DepreciationPeriod $period, array $posted, bool $lock = false): string
    {
        $terms  = self::termsOf($asset);
        $zero   = bcadd('0', '0', self::SCALE);
        $amount = match ($terms->method) {
            AssetMethod::Fixed, AssetMethod::Declining => $this->schedule->between($terms, $period->previous()->end, $period->end),
            AssetMethod::Units => $this->unitsDue($asset, $terms, $period, $posted, $lock),
            AssetMethod::None  => $zero,
        };

        $left = bcsub(bcsub($terms->base(), $terms->openingAccumulated, self::SCALE), array_reduce($posted, static fn (string $sum, string $line): string => bcadd($sum, $line, self::SCALE), $zero), self::SCALE);

        if (bccomp($amount, $left, self::SCALE) > 0) {
            $amount = $left;
        }

        return bccomp($amount, '0', self::SCALE) > 0 ? $amount : $zero;
    }

    /**
     * The months before a period that the asset is to be depreciated for and has no run that stands.
     *
     * @param array<string, numeric-string> $posted
     *
     * @return list<string> The months, YYYY-MM.
     */
    public function missed(object $asset, DepreciationPeriod $period, array $posted, bool $lock = false): array
    {
        $terms  = self::termsOf($asset);
        $before = $period->previous()->end;
        $months = [];

        if ($terms->method->isScheduled()) {
            foreach ($this->schedule->events($terms, $before) as $event) {
                $month = DepreciationPeriod::containing($event['date']);

                if (! isset($posted[$month->start])) {
                    $months[$month->label()] = true;
                }
            }
        } elseif ($terms->method === AssetMethod::Units) {
            $table = $this->db->prefixTable('asset_usage');
            $sql   = "SELECT period_start FROM {$table} WHERE asset_id = ? AND units > 0 AND period_start <= ? ORDER BY period_start" . ($lock ? ' LOCK IN SHARE MODE' : '');
            $args  = [(int) $asset->asset_id, $before];

            foreach ($lock ? LockedRead::rows($this->db, $sql, $args) : $this->db->query($sql, $args)->getResult() as $row) {
                if (! isset($posted[(string) $row->period_start])) {
                    $months[substr((string) $row->period_start, 0, 7)] = true;
                }
            }
        }

        return array_keys($months);
    }

    /**
     * @param array<string, numeric-string> $posted
     *
     * @return numeric-string
     */
    private function unitsDue(object $asset, AssetTerms $terms, DepreciationPeriod $period, array $posted, bool $lock): string
    {
        $usage = $this->usage((int) $asset->asset_id, $period, $lock);

        if ($usage === null) {
            return bcadd('0', '0', self::SCALE);
        }

        return $this->schedule->forUnits($terms, $usage, array_reduce($posted, static fn (string $sum, string $line): string => bcadd($sum, $line, self::SCALE), '0'));
    }
}
