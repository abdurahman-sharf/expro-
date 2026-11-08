<?php

namespace App\Libraries\Stock;

use App\Libraries\Accounting\AccountingError;
use App\Libraries\Accounting\AccountingException;
use App\Libraries\Accounting\AccountingGuard;
use App\Libraries\Accounting\LockedRead;
use App\Models\Item_quantity;
use CodeIgniter\Database\BaseConnection;
use Config\Database;

/**
 * Stock that is counted by serial number or by batch. A serial number is one unit with a state (in stock,
 * sold, returned, removed) that moves along that machine only: it is on hand once, it is sold once, and a
 * return puts the very unit back. A batch is a quantity with an expiry date in each stock location, and
 * what is taken from it is the batch that expires first unless a batch is picked by hand, and never a
 * batch that has expired unless the setting allows it. Every change of a tracked item goes through here
 * and changes the shelf of the item itself, so the quantity of an item in a location always equals the
 * sum of its batches there, or the serial numbers on hand there; any other way of changing the shelf of a
 * tracked item is refused by name. Each line of a document records the serial numbers and the batches it
 * moved, so that taking the document back puts exactly those back. The cost of an item stays its average:
 * a serial number and a batch carry no cost of their own, which is an assumption of the design.
 */
final class StockTracking
{
    public const SALE         = 'sale';
    public const ISSUE        = 'issue';
    public const RECEIVE      = 'receive';
    public const PUT_BACK     = 'putback';
    public const TRANSFER_OUT = 'transfer_out';
    public const TRANSFER_IN  = 'transfer_in';

    private static bool $applying = false;
    private BaseConnection $db;
    private AccountingGuard $guard;
    private StockOptions $options;
    private ItemStockProfile $profiles;

    public function __construct(?BaseConnection $db = null, ?AccountingGuard $guard = null)
    {
        $this->db       = $db ?? Database::connect();
        $this->guard    = $guard ?? new AccountingGuard();
        $this->options  = new StockOptions($this->db, $this->guard);
        $this->profiles = new ItemStockProfile($this->db, $this->guard);
    }

    public function typeOf(int $itemId): TrackingType
    {
        return $this->profiles->trackingOf($itemId);
    }

    public function handles(int $itemId): bool
    {
        return $this->typeOf($itemId) !== TrackingType::None;
    }

    /**
     * Refuses a change of stock that has no serial number or batch to name, for the items that are
     * tracked.
     *
     * @param list<int> $itemIds
     *
     * @throws AccountingException
     */
    public function requireUntracked(array $itemIds): void
    {
        foreach (array_values(array_unique($itemIds)) as $itemId) {
            if ($this->handles($itemId)) {
                throw new AccountingException(AccountingError::TrackedPathUnsupported, 'A tracked item changes only with serial numbers or batches.', ['name' => $this->nameOf($itemId)]);
            }
        }
    }

    /**
     * The last defence of the shelf of a tracked item: whatever is not the tracking itself that changes it
     * is refused.
     *
     * @throws AccountingException
     */
    public static function assertShelfMayChange(int $itemId, BaseConnection $db): void
    {
        if (self::$applying) {
            return;
        }

        $enabled = $db->table('app_config')->select('value')->where('key', StockOptions::TRACKING)->get()->getRow();

        if ($enabled === null || (string) $enabled->value !== '1') {
            return;
        }

        $profile = $db->table('item_stock_profiles')->select('tracking_type')->where('item_id', $itemId)->get()->getRow();

        if ($profile === null || (string) $profile->tracking_type === TrackingType::None->value) {
            return;
        }

        $item = $db->table('items')->select('name')->where('item_id', $itemId)->get()->getRow();

        throw new AccountingException(AccountingError::TrackedPathUnsupported, 'A tracked item changes only with serial numbers or batches.', ['name' => $item === null ? (string) $itemId : (string) $item->name]);
    }

    /**
     * Brings units in: serial numbers that were not on hand, or quantities into batches.
     *
     * @throws AccountingException
     */
    public function receive(string $source, int $sourceId, int $line, int $itemId, int $locationId, string $quantity, TrackingEntry $entry): void
    {
        match ($this->typeOf($itemId)) {
            TrackingType::Serial => $this->receiveSerials($source, $sourceId, $line, $itemId, $locationId, $quantity, $entry, self::RECEIVE),
            TrackingType::Batch  => $this->receiveBatches($source, $sourceId, $line, $itemId, $locationId, $quantity, $entry, self::RECEIVE),
            default              => throw new AccountingException(AccountingError::TrackedEntryNotAllowed, 'The item is not tracked.', ['line' => $line, 'name' => $this->nameOf($itemId)]),
        };

        $this->shelf($itemId, $locationId, $quantity);
        $this->assertTied($itemId, $locationId);
    }

    /**
     * Takes units out for a sale or a stock permit.
     *
     * @throws AccountingException
     */
    public function issue(string $source, int $sourceId, int $line, int $itemId, int $locationId, string $quantity, TrackingEntry $entry, string $date, string $movement, ?int $employeeId = null): void
    {
        match ($this->typeOf($itemId)) {
            TrackingType::Serial => $this->issueSerials($source, $sourceId, $line, $itemId, $locationId, $quantity, $entry, $movement),
            TrackingType::Batch  => $this->issueBatches($source, $sourceId, $line, $itemId, $locationId, $quantity, $entry, $date, $movement, $employeeId, $movement === self::SALE),
            default              => throw new AccountingException(AccountingError::TrackedEntryNotAllowed, 'The item is not tracked.', ['line' => $line, 'name' => $this->nameOf($itemId)]),
        };

        $this->shelf($itemId, $locationId, bcsub('0', $quantity, 3));
        $this->assertTied($itemId, $locationId);
    }

    /**
     * Puts back what a customer returns: the serial numbers that were sold, or quantities into the
     * batches the sale took them from.
     *
     * @throws AccountingException
     */
    public function putBack(string $source, int $sourceId, int $line, int $itemId, int $locationId, string $quantity, TrackingEntry $entry, ?int $originSaleId): void
    {
        match ($this->typeOf($itemId)) {
            TrackingType::Serial => $this->putBackSerials($source, $sourceId, $line, $itemId, $locationId, $quantity, $entry, $originSaleId),
            TrackingType::Batch  => $this->putBackBatches($source, $sourceId, $line, $itemId, $locationId, $quantity, $entry, $originSaleId),
            default              => throw new AccountingException(AccountingError::TrackedEntryNotAllowed, 'The item is not tracked.', ['line' => $line, 'name' => $this->nameOf($itemId)]),
        };

        $this->shelf($itemId, $locationId, $quantity);
        $this->assertTied($itemId, $locationId);
    }

    /**
     * Moves units from one stock location to another, the same serial numbers and the same batches.
     *
     * @throws AccountingException
     */
    public function transfer(string $source, int $sourceId, int $line, int $itemId, int $fromLocation, int $toLocation, string $quantity, TrackingEntry $entry, ?int $employeeId = null): void
    {
        match ($this->typeOf($itemId)) {
            TrackingType::Serial => $this->transferSerials($source, $sourceId, $line, $itemId, $fromLocation, $toLocation, $quantity, $entry),
            TrackingType::Batch  => $this->transferBatches($source, $sourceId, $line, $itemId, $fromLocation, $toLocation, $quantity, $entry, $employeeId),
            default              => throw new AccountingException(AccountingError::TrackedEntryNotAllowed, 'The item is not tracked.', ['line' => $line, 'name' => $this->nameOf($itemId)]),
        };

        $this->shelf($itemId, $fromLocation, bcsub('0', $quantity, 3));
        $this->shelf($itemId, $toLocation, $quantity);
        $this->assertTied($itemId, $fromLocation);
        $this->assertTied($itemId, $toLocation);
    }

    /**
     * Takes back everything the lines of a document moved, newest first, each exactly as it was, and
     * changes the shelves back. A serial number or a batch that has moved on since refuses it.
     *
     * @return list<int> The items whose shelves it changed back, which the document must not change again.
     *
     * @throws AccountingException
     */
    public function reverseSource(string $source, int $sourceId): array
    {
        $open = LockedRead::rows(
            $this->db,
            'SELECT t.* FROM ' . $this->db->prefixTable('stock_tracking_lines') . ' t
             LEFT JOIN ' . $this->db->prefixTable('stock_tracking_lines') . ' r ON r.reversal_of = t.tracking_id
             WHERE t.source_type = ? AND t.source_id = ? AND t.reversal_of IS NULL AND r.tracking_id IS NULL
             ORDER BY t.tracking_id DESC FOR UPDATE',
            [$source, $sourceId],
        );

        foreach ($open as $row) {
            $row->serial_id !== null ? $this->reverseSerial($row) : $this->reverseBatch($row);

            $this->db->table('stock_tracking_lines')->insert([
                'source_type' => $source,
                'source_id'   => $sourceId,
                'line_no'     => (int) $row->line_no,
                'movement'    => (string) $row->movement,
                'item_id'     => (int) $row->item_id,
                'location_id' => (int) $row->location_id,
                'serial_id'   => $row->serial_id,
                'batch_id'    => $row->batch_id,
                'quantity'    => bcsub('0', (string) $row->quantity, 3),
                'reversal_of' => (int) $row->tracking_id,
            ]);

            $this->shelf((int) $row->item_id, (int) $row->location_id, bcsub('0', (string) $row->quantity, 3));
        }

        foreach (array_unique(array_map(static fn (object $row): string => $row->item_id . ':' . $row->location_id, $open)) as $pair) {
            [$itemId, $locationId] = array_map('intval', explode(':', $pair));

            $this->assertTied($itemId, $locationId);
        }

        return array_values(array_unique(array_map(static fn (object $row): int => (int) $row->item_id, $open)));
    }

    /**
     * What the shelf of an item in a location holds and what its serial numbers or batches add up to.
     *
     * @return array{shelf: string, tracked: string}
     */
    public function tie(int $itemId, int $locationId): array
    {
        $shelf = $this->db->table('item_quantities')->select('quantity')->where(['item_id' => $itemId, 'location_id' => $locationId])->get()->getRow();

        if ($this->typeOf($itemId) === TrackingType::Serial) {
            $tracked = (string) $this->db->table('stock_serials')->where(['item_id' => $itemId, 'location_id' => $locationId])->whereIn('state', ['in_stock', 'returned'])->countAllResults();
        } else {
            $row     = $this->db->table('stock_batch_quantities q')->select('COALESCE(SUM(q.quantity), 0) AS total', false)->join('stock_batches b', 'b.batch_id = q.batch_id')->where(['b.item_id' => $itemId, 'q.location_id' => $locationId])->get()->getRow();
            $tracked = (string) $row->total;
        }

        return ['shelf' => bcadd($shelf === null ? '0' : (string) $shelf->quantity, '0', 3), 'tracked' => bcadd($tracked, '0', 3)];
    }

    /**
     * @throws AccountingException
     */
    private function assertTied(int $itemId, int $locationId): void
    {
        $tie = $this->tie($itemId, $locationId);

        if (bccomp($tie['shelf'], $tie['tracked'], 3) !== 0) {
            throw new AccountingException(AccountingError::TrackedPathUnsupported, 'The shelf of a tracked item must equal its serial numbers or batches.', ['name' => $this->nameOf($itemId)]);
        }
    }

    private function shelf(int $itemId, int $locationId, string $delta): void
    {
        self::$applying = true;

        try {
            model(Item_quantity::class)->changeQuantity($itemId, $locationId, (float) $delta);
        } finally {
            self::$applying = false;
        }
    }

    private function record(string $source, int $sourceId, int $line, string $movement, int $itemId, int $locationId, ?int $serialId, ?int $batchId, string $quantity): void
    {
        $this->db->table('stock_tracking_lines')->insert([
            'source_type' => $source,
            'source_id'   => $sourceId,
            'line_no'     => $line,
            'movement'    => $movement,
            'item_id'     => $itemId,
            'location_id' => $locationId,
            'serial_id'   => $serialId,
            'batch_id'    => $batchId,
            'quantity'    => bcadd($quantity, '0', 3),
        ]);
    }

    /**
     * @return list<string>
     *
     * @throws AccountingException
     */
    private function serialsFor(int $itemId, int $line, string $quantity, TrackingEntry $entry): array
    {
        $whole = bccomp($quantity, bcadd($quantity, '0', 0), 3) === 0;

        if (! $whole || bccomp($quantity, '0', 3) <= 0 || count($entry->serials) !== (int) $quantity || $entry->batches !== []) {
            throw new AccountingException(AccountingError::TrackedEntryRequired, 'One serial number is needed for each unit.', ['line' => $line, 'name' => $this->nameOf($itemId)]);
        }

        foreach ($entry->serials as $serial) {
            if ($serial === '' || mb_strlen($serial) > TrackingEntry::SERIAL_MAX) {
                throw new AccountingException(AccountingError::SerialInvalid, 'A serial number is empty or too long.', ['line' => $line]);
            }
        }

        if (count(array_unique($entry->serials)) !== count($entry->serials)) {
            throw new AccountingException(AccountingError::SerialInvalid, 'A serial number is repeated in the line.', ['line' => $line]);
        }

        return $entry->serials;
    }

    private function receiveSerials(string $source, int $sourceId, int $line, int $itemId, int $locationId, string $quantity, TrackingEntry $entry, string $movement): void
    {
        foreach ($this->serialsFor($itemId, $line, $quantity, $entry) as $serial) {
            $this->db->table('stock_serials')->ignore(true)->insert([
                'item_id'         => $itemId,
                'serial_number'   => $serial,
                'state'           => 'in_stock',
                'location_id'     => $locationId,
                'received_source' => $source,
                'received_id'     => $sourceId,
            ]);

            $serialId = $this->db->affectedRows() === 1 ? (int) $this->db->insertID() : $this->reviveSerial($itemId, $serial, $locationId, $source, $sourceId);

            $this->record($source, $sourceId, $line, $movement, $itemId, $locationId, $serialId, null, '1');
        }
    }

    /**
     * @throws AccountingException
     */
    private function reviveSerial(int $itemId, string $serial, int $locationId, string $source, int $sourceId): int
    {
        $row = LockedRead::row($this->db, 'SELECT serial_id, state FROM ' . $this->db->prefixTable('stock_serials') . ' WHERE item_id = ? AND serial_number = ? FOR UPDATE', [$itemId, $serial]);

        if ($row === null || (string) $row->state !== 'removed') {
            throw new AccountingException(AccountingError::SerialDuplicate, 'The serial number is already on record.', ['code' => $serial]);
        }

        $this->db->table('stock_serials')->where(['serial_id' => (int) $row->serial_id, 'state' => 'removed'])->update([
            'state'           => 'in_stock',
            'location_id'     => $locationId,
            'received_source' => $source,
            'received_id'     => $sourceId,
            'sold_source'     => null,
            'sold_id'         => null,
            'updated_at'      => date('Y-m-d H:i:s'),
        ]);

        return (int) $row->serial_id;
    }

    private function issueSerials(string $source, int $sourceId, int $line, int $itemId, int $locationId, string $quantity, TrackingEntry $entry, string $movement): void
    {
        $state = $movement === self::SALE ? 'sold' : 'removed';

        foreach ($this->serialsFor($itemId, $line, $quantity, $entry) as $serial) {
            $this->db->query(
                'UPDATE ' . $this->db->prefixTable('stock_serials') . ' SET state = ?, location_id = NULL, sold_source = ?, sold_id = ?, updated_at = NOW()
                 WHERE item_id = ? AND serial_number = ? AND state IN (\'in_stock\', \'returned\') AND location_id = ?',
                [$state, $source, $sourceId, $itemId, $serial, $locationId],
            );

            if ($this->db->affectedRows() !== 1) {
                throw new AccountingException(AccountingError::SerialNotAvailable, 'The serial number is not on hand in this stock location.', ['code' => $serial]);
            }

            $this->record($source, $sourceId, $line, $movement, $itemId, $locationId, $this->serialIdOf($itemId, $serial), null, '-1');
        }
    }

    private function putBackSerials(string $source, int $sourceId, int $line, int $itemId, int $locationId, string $quantity, TrackingEntry $entry, ?int $originSaleId): void
    {
        foreach ($this->serialsFor($itemId, $line, $quantity, $entry) as $serial) {
            $origin = $originSaleId === null ? '' : ' AND sold_source = \'sale\' AND sold_id = ' . (int) $originSaleId;

            $this->db->query(
                'UPDATE ' . $this->db->prefixTable('stock_serials') . ' SET state = \'returned\', location_id = ?, updated_at = NOW()
                 WHERE item_id = ? AND serial_number = ? AND state = \'sold\'' . $origin,
                [$locationId, $itemId, $serial],
            );

            if ($this->db->affectedRows() !== 1) {
                throw new AccountingException(AccountingError::SerialNotSold, 'The serial number was not sold.', ['code' => $serial]);
            }

            $this->record($source, $sourceId, $line, self::PUT_BACK, $itemId, $locationId, $this->serialIdOf($itemId, $serial), null, '1');
        }
    }

    private function transferSerials(string $source, int $sourceId, int $line, int $itemId, int $fromLocation, int $toLocation, string $quantity, TrackingEntry $entry): void
    {
        foreach ($this->serialsFor($itemId, $line, $quantity, $entry) as $serial) {
            $this->db->query(
                'UPDATE ' . $this->db->prefixTable('stock_serials') . ' SET location_id = ?, updated_at = NOW()
                 WHERE item_id = ? AND serial_number = ? AND state IN (\'in_stock\', \'returned\') AND location_id = ?',
                [$toLocation, $itemId, $serial, $fromLocation],
            );

            if ($this->db->affectedRows() !== 1) {
                throw new AccountingException(AccountingError::SerialNotAvailable, 'The serial number is not on hand in this stock location.', ['code' => $serial]);
            }

            $serialId = $this->serialIdOf($itemId, $serial);

            $this->record($source, $sourceId, $line, self::TRANSFER_OUT, $itemId, $fromLocation, $serialId, null, '-1');
            $this->record($source, $sourceId, $line, self::TRANSFER_IN, $itemId, $toLocation, $serialId, null, '1');
        }
    }

    private function serialIdOf(int $itemId, string $serial): int
    {
        return (int) $this->db->table('stock_serials')->select('serial_id')->where(['item_id' => $itemId, 'serial_number' => $serial])->get()->getRow()->serial_id;
    }

    /**
     * @return list<array{number: string, expiry: ?string, quantity: string}>
     *
     * @throws AccountingException
     */
    private function batchesFor(int $itemId, int $line, string $quantity, TrackingEntry $entry, bool $expiryRequired): array
    {
        $merged = [];

        foreach ($entry->batches as $batch) {
            $number = $batch['number'];
            $expiry = $batch['expiry'];
            $amount = $batch['quantity'];

            $validDate = $expiry === null || (preg_match('/^\d{4}-\d{2}-\d{2}$/', $expiry) === 1 && date('Y-m-d', (int) strtotime($expiry)) === $expiry);

            if ($number === '' || mb_strlen($number) > TrackingEntry::BATCH_MAX || ! $validDate || ($expiryRequired && $expiry === null) || preg_match('/^\d{1,9}(\.\d{1,3})?$/', $amount) !== 1 || bccomp($amount, '0', 3) <= 0) {
                throw new AccountingException(AccountingError::BatchInvalid, 'A batch needs a number, an expiry date and a quantity above zero.', ['line' => $line]);
            }

            $merged[$number] = isset($merged[$number])
                ? ['number' => $number, 'expiry' => $expiry ?? $merged[$number]['expiry'], 'quantity' => bcadd($merged[$number]['quantity'], $amount, 3)]
                : ['number' => $number, 'expiry' => $expiry, 'quantity' => bcadd($amount, '0', 3)];
        }

        $total = '0';

        foreach ($merged as $batch) {
            $total = bcadd($total, $batch['quantity'], 3);
        }

        if ($merged !== [] && bccomp($total, bcadd($quantity, '0', 3), 3) !== 0) {
            throw new AccountingException(AccountingError::TrackedEntryRequired, 'The batches must add up to the quantity of the line.', ['line' => $line, 'name' => $this->nameOf($itemId)]);
        }

        return array_values($merged);
    }

    private function receiveBatches(string $source, int $sourceId, int $line, int $itemId, int $locationId, string $quantity, TrackingEntry $entry, string $movement): void
    {
        $batches = $this->batchesFor($itemId, $line, $quantity, $entry, true);

        if ($batches === [] || $entry->serials !== []) {
            throw new AccountingException(AccountingError::TrackedEntryRequired, 'A batch with its expiry date is needed for the units.', ['line' => $line, 'name' => $this->nameOf($itemId)]);
        }

        foreach ($batches as $batch) {
            $this->db->table('stock_batches')->ignore(true)->insert(['item_id' => $itemId, 'batch_number' => $batch['number'], 'expiry_date' => $batch['expiry']]);

            $row = LockedRead::row($this->db, 'SELECT batch_id, expiry_date FROM ' . $this->db->prefixTable('stock_batches') . ' WHERE item_id = ? AND batch_number = ? FOR UPDATE', [$itemId, $batch['number']]);

            if ($row === null || (string) $row->expiry_date !== $batch['expiry']) {
                throw new AccountingException(AccountingError::BatchExpiryMismatch, 'The batch is on record with another expiry date.', ['code' => $batch['number']]);
            }

            $this->db->query(
                'INSERT INTO ' . $this->db->prefixTable('stock_batch_quantities') . ' (batch_id, location_id, quantity) VALUES (?, ?, ?) ON DUPLICATE KEY UPDATE quantity = quantity + VALUES(quantity)',
                [(int) $row->batch_id, $locationId, $batch['quantity']],
            );

            $this->record($source, $sourceId, $line, $movement, $itemId, $locationId, null, (int) $row->batch_id, $batch['quantity']);
        }
    }

    private function issueBatches(string $source, int $sourceId, int $line, int $itemId, int $locationId, string $quantity, TrackingEntry $entry, string $date, string $movement, ?int $employeeId, bool $checkExpiry): void
    {
        $picks = $this->picksFor($itemId, $locationId, $line, $quantity, $entry, $date, $employeeId, $checkExpiry);

        foreach ($picks as $pick) {
            $this->takeFromBatch($pick['batch_id'], $locationId, $pick['quantity'], $pick['number'], $itemId);
            $this->record($source, $sourceId, $line, $movement, $itemId, $locationId, null, $pick['batch_id'], bcsub('0', $pick['quantity'], 3));
        }
    }

    /**
     * The batches a quantity is taken from: the ones picked by hand, which asks the permission and refuses
     * an expired batch when the setting says so, or else the batches that expire first, which leave out the
     * batches that have expired when the setting refuses them.
     *
     * @return list<array{batch_id: int, number: string, quantity: string}>
     *
     * @throws AccountingException
     */
    private function picksFor(int $itemId, int $locationId, int $line, string $quantity, TrackingEntry $entry, string $date, ?int $employeeId, bool $checkExpiry): array
    {
        $refuseExpired = $checkExpiry && $this->options->expiredSalesRefused();

        if ($entry->serials !== []) {
            throw new AccountingException(AccountingError::TrackedEntryRequired, 'A batch item is taken by batch.', ['line' => $line, 'name' => $this->nameOf($itemId)]);
        }

        if ($entry->batches !== []) {
            if (! $this->guard->has(StockUnitPermissions::BATCH_MANUAL_PICK, $employeeId)) {
                throw new AccountingException(AccountingError::BatchManualPickDenied, 'Picking a batch by hand needs its permission.');
            }

            $picks = [];

            foreach ($this->batchesFor($itemId, $line, $quantity, $entry, false) as $batch) {
                $row = $this->db->table('stock_batches')->select('batch_id, expiry_date')->where(['item_id' => $itemId, 'batch_number' => $batch['number']])->get()->getRow();

                if ($row === null) {
                    throw new AccountingException(AccountingError::BatchInvalid, 'The batch does not exist.', ['line' => $line]);
                }

                if ($refuseExpired && (string) $row->expiry_date < $date) {
                    throw new AccountingException(AccountingError::BatchExpired, 'The batch has expired.', ['code' => $batch['number'], 'date' => (string) $row->expiry_date]);
                }

                $picks[] = ['batch_id' => (int) $row->batch_id, 'number' => $batch['number'], 'quantity' => $batch['quantity']];
            }

            if ($picks === []) {
                throw new AccountingException(AccountingError::TrackedEntryRequired, 'The batches must add up to the quantity of the line.', ['line' => $line, 'name' => $this->nameOf($itemId)]);
            }

            return $picks;
        }

        $rows = LockedRead::rows(
            $this->db,
            'SELECT b.batch_id, b.batch_number, q.quantity FROM ' . $this->db->prefixTable('stock_batches') . ' b
             JOIN ' . $this->db->prefixTable('stock_batch_quantities') . ' q ON q.batch_id = b.batch_id AND q.location_id = ?
             WHERE b.item_id = ? AND q.quantity > 0' . ($refuseExpired ? ' AND b.expiry_date >= ?' : '') . '
             ORDER BY b.expiry_date ASC, b.batch_id ASC FOR UPDATE',
            $refuseExpired ? [$locationId, $itemId, $date] : [$locationId, $itemId],
        );

        $left  = bcadd($quantity, '0', 3);
        $picks = [];

        foreach ($rows as $row) {
            if (bccomp($left, '0', 3) <= 0) {
                break;
            }

            $take    = bccomp((string) $row->quantity, $left, 3) < 0 ? bcadd((string) $row->quantity, '0', 3) : $left;
            $picks[] = ['batch_id' => (int) $row->batch_id, 'number' => (string) $row->batch_number, 'quantity' => $take];
            $left    = bcsub($left, $take, 3);
        }

        if (bccomp($left, '0', 3) > 0) {
            throw new AccountingException(AccountingError::BatchInsufficient, 'There is not enough in unexpired batches.', ['name' => $this->nameOf($itemId)]);
        }

        return $picks;
    }

    private function takeFromBatch(int $batchId, int $locationId, string $quantity, string $number, int $itemId): void
    {
        $this->db->query(
            'UPDATE ' . $this->db->prefixTable('stock_batch_quantities') . ' SET quantity = quantity - ? WHERE batch_id = ? AND location_id = ? AND quantity >= ?',
            [$quantity, $batchId, $locationId, $quantity],
        );

        if ($this->db->affectedRows() !== 1) {
            throw new AccountingException(AccountingError::BatchInsufficient, 'There is not enough in the batch.', ['name' => $this->nameOf($itemId), 'code' => $number]);
        }
    }

    private function putIntoBatch(int $batchId, int $locationId, string $quantity): void
    {
        $this->db->query(
            'INSERT INTO ' . $this->db->prefixTable('stock_batch_quantities') . ' (batch_id, location_id, quantity) VALUES (?, ?, ?) ON DUPLICATE KEY UPDATE quantity = quantity + VALUES(quantity)',
            [$batchId, $locationId, $quantity],
        );
    }

    private function putBackBatches(string $source, int $sourceId, int $line, int $itemId, int $locationId, string $quantity, TrackingEntry $entry, ?int $originSaleId): void
    {
        $targets = [];

        if ($entry->batches !== []) {
            foreach ($this->batchesFor($itemId, $line, $quantity, $entry, false) as $batch) {
                $row = $this->db->table('stock_batches')->select('batch_id')->where(['item_id' => $itemId, 'batch_number' => $batch['number']])->get()->getRow();

                if ($row === null) {
                    throw new AccountingException(AccountingError::BatchInvalid, 'The batch does not exist.', ['line' => $line]);
                }

                $targets[] = ['batch_id' => (int) $row->batch_id, 'quantity' => $batch['quantity']];
            }
        } elseif ($originSaleId !== null) {
            $targets = $this->batchesOfSale($itemId, $originSaleId, $quantity);
        }

        if ($targets === [] || bccomp(array_reduce($targets, static fn (string $sum, array $target): string => bcadd($sum, $target['quantity'], 3), '0'), bcadd($quantity, '0', 3), 3) !== 0) {
            throw new AccountingException(AccountingError::TrackedEntryRequired, 'The batches the goods go back into are needed.', ['line' => $line, 'name' => $this->nameOf($itemId)]);
        }

        foreach ($targets as $target) {
            $this->putIntoBatch($target['batch_id'], $locationId, $target['quantity']);
            $this->record($source, $sourceId, $line, self::PUT_BACK, $itemId, $locationId, null, $target['batch_id'], $target['quantity']);
        }
    }

    /**
     * The batches the sale took an item from, in the order it took them, less what earlier returns of it
     * put back, as far as a quantity goes.
     *
     * @return list<array{batch_id: int, quantity: string}>
     */
    private function batchesOfSale(int $itemId, int $saleId, string $quantity): array
    {
        $lines = $this->db->prefixTable('stock_tracking_lines');
        $sales = $this->db->prefixTable('sales');

        $taken = $this->db->query(
            "SELECT t.batch_id, SUM(-t.quantity) AS taken FROM {$lines} t
             WHERE t.source_type = 'sale' AND t.source_id = ? AND t.item_id = ? AND t.movement = 'sale' AND t.batch_id IS NOT NULL AND t.reversal_of IS NULL
               AND NOT EXISTS (SELECT 1 FROM {$lines} r WHERE r.reversal_of = t.tracking_id)
             GROUP BY t.batch_id ORDER BY MIN(t.tracking_id)",
            [$saleId, $itemId],
        )->getResult();

        $back = [];

        foreach ($this->db->query(
            "SELECT t.batch_id, SUM(t.quantity) AS back FROM {$lines} t JOIN {$sales} s ON s.sale_id = t.source_id AND t.source_type = 'sale'
             WHERE s.return_of_sale_id = ? AND t.item_id = ? AND t.movement = 'putback' AND t.batch_id IS NOT NULL AND t.reversal_of IS NULL
               AND NOT EXISTS (SELECT 1 FROM {$lines} r WHERE r.reversal_of = t.tracking_id)
             GROUP BY t.batch_id",
            [$saleId, $itemId],
        )->getResult() as $row) {
            $back[(int) $row->batch_id] = (string) $row->back;
        }

        $left    = bcadd($quantity, '0', 3);
        $targets = [];

        foreach ($taken as $row) {
            $room = bcsub((string) $row->taken, $back[(int) $row->batch_id] ?? '0', 3);

            if (bccomp($left, '0', 3) <= 0 || bccomp($room, '0', 3) <= 0) {
                continue;
            }

            $put       = bccomp($room, $left, 3) < 0 ? $room : $left;
            $targets[] = ['batch_id' => (int) $row->batch_id, 'quantity' => bcadd($put, '0', 3)];
            $left      = bcsub($left, $put, 3);
        }

        return $targets;
    }

    private function transferBatches(string $source, int $sourceId, int $line, int $itemId, int $fromLocation, int $toLocation, string $quantity, TrackingEntry $entry, ?int $employeeId): void
    {
        foreach ($this->picksFor($itemId, $fromLocation, $line, $quantity, $entry, date('Y-m-d'), $employeeId, false) as $pick) {
            $this->takeFromBatch($pick['batch_id'], $fromLocation, $pick['quantity'], $pick['number'], $itemId);
            $this->putIntoBatch($pick['batch_id'], $toLocation, $pick['quantity']);
            $this->record($source, $sourceId, $line, self::TRANSFER_OUT, $itemId, $fromLocation, null, $pick['batch_id'], bcsub('0', $pick['quantity'], 3));
            $this->record($source, $sourceId, $line, self::TRANSFER_IN, $itemId, $toLocation, null, $pick['batch_id'], $pick['quantity']);
        }
    }

    private function reverseSerial(object $row): void
    {
        $serialId   = (int) $row->serial_id;
        $locationId = (int) $row->location_id;
        $onHand     = '\'in_stock\', \'returned\'';
        $table      = $this->db->prefixTable('stock_serials');

        $ran = match ((string) $row->movement) {
            self::RECEIVE     => $this->db->query("UPDATE {$table} SET state = 'removed', location_id = NULL, updated_at = NOW() WHERE serial_id = ? AND state IN ({$onHand}) AND location_id = ?", [$serialId, $locationId]),
            self::SALE        => $this->db->query("UPDATE {$table} SET state = 'in_stock', location_id = ?, sold_source = NULL, sold_id = NULL, updated_at = NOW() WHERE serial_id = ? AND state = 'sold' AND sold_source = ? AND sold_id = ?", [$locationId, $serialId, (string) $row->source_type, (int) $row->source_id]),
            self::ISSUE       => $this->db->query("UPDATE {$table} SET state = 'in_stock', location_id = ?, sold_source = NULL, sold_id = NULL, updated_at = NOW() WHERE serial_id = ? AND state = 'removed'", [$locationId, $serialId]),
            self::PUT_BACK    => $this->db->query("UPDATE {$table} SET state = 'sold', location_id = NULL, updated_at = NOW() WHERE serial_id = ? AND state = 'returned' AND location_id = ?", [$serialId, $locationId]),
            self::TRANSFER_IN => $this->db->query("UPDATE {$table} SET location_id = ?, updated_at = NOW() WHERE serial_id = ? AND state IN ({$onHand}) AND location_id = ?", [$this->transferOrigin($row), $serialId, $locationId]),
            default           => null,
        };

        if ($ran !== null && $this->db->affectedRows() !== 1) {
            throw new AccountingException(AccountingError::SerialMoved, 'The serial number has moved since.', ['code' => (string) $this->db->table('stock_serials')->select('serial_number')->where('serial_id', $serialId)->get()->getRow()->serial_number]);
        }
    }

    private function transferOrigin(object $row): int
    {
        $out = $this->db->table('stock_tracking_lines')->select('location_id')->where(['source_type' => (string) $row->source_type, 'source_id' => (int) $row->source_id, 'line_no' => (int) $row->line_no, 'serial_id' => (int) $row->serial_id, 'movement' => self::TRANSFER_OUT])->orderBy('tracking_id', 'DESC')->get()->getRow();

        return $out === null ? (int) $row->location_id : (int) $out->location_id;
    }

    private function reverseBatch(object $row): void
    {
        $batchId    = (int) $row->batch_id;
        $locationId = (int) $row->location_id;
        $quantity   = bcadd((string) $row->quantity, '0', 3);
        $itemId     = (int) $row->item_id;

        if (bccomp($quantity, '0', 3) > 0) {
            $this->takeFromBatch($batchId, $locationId, $quantity, (string) $this->db->table('stock_batches')->select('batch_number')->where('batch_id', $batchId)->get()->getRow()->batch_number, $itemId);

            return;
        }

        $this->putIntoBatch($batchId, $locationId, bcsub('0', $quantity, 3));
    }

    private function nameOf(int $itemId): string
    {
        $item = $this->db->table('items')->select('name')->where('item_id', $itemId)->get()->getRow();

        return $item === null ? (string) $itemId : (string) $item->name;
    }
}
