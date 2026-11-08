<?php

namespace App\Libraries\Accounting\Readiness;

use App\Libraries\Stock\Bundles;
use App\Libraries\Stock\StockOptions;
use App\Libraries\Stock\TrackingTies;

/**
 * Whether the quantities kept in units, bundles and tracking add up: the shelf of every tracked item in
 * every stock location equals the serial numbers on hand there or the sum of its batches, no batch that
 * has expired is waiting on a shelf unnoticed, and the kits that are meant to be bundles follow the rules
 * of a bundle. It reads and changes nothing.
 */
final class StockTrackingChecks extends CheckGroup
{
    private const LISTED = 50;

    public function run(): array
    {
        return [
            $this->check('stock_tracking', 'WP42-TRACKING', 'Tracked stock ties to its serial numbers and batches', ['item_stock_profiles', 'stock_serials', 'stock_batch_quantities', 'stock_batches'], fn (): CheckResult => $this->tracking()),
            $this->check('stock_bundles', 'WP42-BUNDLES', 'Kits follow the rules of a bundle and units are switched on where lines use them', ['item_kits', 'unit_templates'], fn (): CheckResult => $this->bundles()),
        ];
    }

    private function tracking(): CheckResult
    {
        $tracked = (int) $this->db->table('item_stock_profiles')->whereIn('tracking_type', ['serial', 'batch'])->countAllResults();

        if ($tracked === 0) {
            return CheckResult::pass('No item is tracked by serial number or by batch.', [], ['tracked_items' => 0]);
        }

        $rows = (new TrackingTies($this->db))->untied(self::LISTED);

        $expired = $this->db->table('stock_batch_quantities q')
            ->select('b.item_id, b.batch_number, b.expiry_date, q.location_id, q.quantity')
            ->join('stock_batches b', 'b.batch_id = q.batch_id')
            ->where('q.quantity >', 0)
            ->where('b.expiry_date <', date('Y-m-d'))
            ->orderBy('b.expiry_date', 'ASC')
            ->limit(self::LISTED)
            ->get()
            ->getResult();

        $data = ['tracked_items' => $tracked, 'untied' => count($rows), 'expired_on_hand' => count($expired), 'tracking_enabled' => (new StockOptions($this->db))->trackingEnabled()];

        if ($rows !== []) {
            return CheckResult::fail(count($rows) . ' item(s) hold a quantity their serial numbers or batches do not account for.', array_map(static fn (object $row): string => sprintf('Item #%d "%s" in location #%d: the shelf holds %s and its %s add up to %s.', $row->item_id, $row->name, $row->location_id, bcadd((string) $row->shelf, '0', 3), $row->tracking_type === 'serial' ? 'serial numbers on hand' : 'batches', bcadd((string) $row->tracked, '0', 3)), $rows), $data);
        }

        if ($expired !== []) {
            return CheckResult::review(count($expired) . ' batch(es) have expired and still stand on a shelf.', array_map(static fn (object $row): string => sprintf('Item #%d batch %s expired on %s with %s in location #%d.', $row->item_id, $row->batch_number, $row->expiry_date, bcadd((string) $row->quantity, '0', 3), $row->location_id), $expired), $data);
        }

        return CheckResult::pass($tracked . ' tracked item(s), every shelf equals its serial numbers or batches and no expired batch is waiting.', [], $data);
    }

    private function bundles(): CheckResult
    {
        $options  = new StockOptions($this->db);
        $findings = [];

        if ($options->bundlesEnabled()) {
            foreach ((new Bundles($this->db))->violations() as $kit) {
                $findings[] = sprintf('Kit #%d "%s" does not follow the rules of a bundle (%s).', $kit['kit_id'], $kit['name'], $kit['problem']);
            }
        }

        $unitLines = 0;

        foreach (['sales_items', 'receivings_items', 'stock_permit_lines'] as $table) {
            if ($this->schema->missing([$table . '.unit_id']) === []) {
                $unitLines += (int) $this->db->table($table)->where('unit_id IS NOT NULL', null, false)->countAllResults();
            }
        }

        $data = ['bundles_enabled' => $options->bundlesEnabled(), 'units_enabled' => $options->unitsEnabled(), 'kits_off_the_rules' => count($findings), 'lines_in_units' => $unitLines];

        if ($findings !== []) {
            return CheckResult::review(count($findings) . ' kit(s) do not follow the rules of a bundle.', array_slice($findings, 0, self::LISTED), $data);
        }

        if ($unitLines > 0 && ! $options->unitsEnabled()) {
            return CheckResult::review($unitLines . ' document line(s) were entered in a larger unit while the units setting is off: new lines in units are refused until it is turned on.', [], $data);
        }

        return CheckResult::pass('Kits follow the rules of a bundle where the setting asks for it, and units are on wherever lines use them.', [], $data);
    }
}
