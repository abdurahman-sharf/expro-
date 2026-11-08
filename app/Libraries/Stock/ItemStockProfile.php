<?php

namespace App\Libraries\Stock;

use App\Libraries\Accounting\AccountingError;
use App\Libraries\Accounting\AccountingException;
use App\Libraries\Accounting\AccountingGuard;
use App\Libraries\Accounting\AuditLog;
use App\Libraries\Accounting\JournalOrigin;
use App\Libraries\Accounting\LockedRead;
use App\Libraries\Accounting\PostingException;
use App\Libraries\Accounting\RunsInTransaction;
use CodeIgniter\Database\BaseConnection;
use Config\Database;

/**
 * What an item says about its quantities: the unit template it may be entered in and how it is tracked.
 * An item with no profile row has one unit and no tracking, which is every legacy item. The tracking type
 * of an item changes only while nothing of it is on hand, because stock that no serial number or batch
 * accounts for would break the rule that the batches of an item add up to what the location holds.
 */
final class ItemStockProfile
{
    use RunsInTransaction;

    private BaseConnection $db;
    private AccountingGuard $guard;
    private StockOptions $options;
    private AuditLog $audit;

    public function __construct(?BaseConnection $db = null, ?AccountingGuard $guard = null)
    {
        $this->db      = $db ?? Database::connect();
        $this->guard   = $guard ?? new AccountingGuard();
        $this->options = new StockOptions($this->db, $this->guard);
        $this->audit   = new AuditLog($this->db);
    }

    public function get(int $itemId): ItemProfile
    {
        $row = $this->db->table('item_stock_profiles')->where('item_id', $itemId)->get()->getRow();

        return $row === null
            ? new ItemProfile($itemId, null, TrackingType::None)
            : new ItemProfile($itemId, $row->unit_template_id === null ? null : (int) $row->unit_template_id, TrackingType::tryFrom((string) $row->tracking_type) ?? TrackingType::None);
    }

    /**
     * The way an item is tracked as the documents see it: none while the setting is off.
     */
    public function trackingOf(int $itemId): TrackingType
    {
        return $this->options->trackingEnabled() ? $this->get($itemId)->tracking : TrackingType::None;
    }

    /**
     * Saves what a screen posts for an item: the template when one was posted and is not the one the item has,
     * the tracking type when one was posted and is not the one it has, all or nothing. What does not change
     * asks for no permission and no setting, so an employee who may change only one of them saves that one.
     *
     * @throws AccountingException|PostingException
     */
    public function saveProfile(int $itemId, bool $templatePosted, ?int $templateId, ?TrackingType $tracking, ?int $employeeId = null): void
    {
        $before          = $this->get($itemId);
        $templateChanges = $templatePosted && $templateId !== $before->unitTemplateId;
        $trackingChanges = $tracking !== null && $tracking !== $before->tracking;

        if (! $templateChanges && ! $trackingChanges) {
            return;
        }

        $this->transactional(function () use ($itemId, $templateChanges, $templateId, $trackingChanges, $tracking, $employeeId): void {
            if ($templateChanges) {
                $this->setTemplate($itemId, $templateId, $employeeId);
            }

            if ($trackingChanges && $tracking !== null) {
                $this->setTracking($itemId, $tracking, $employeeId);
            }
        });
    }

    /**
     * @throws AccountingException|PostingException
     */
    public function setTemplate(int $itemId, ?int $templateId, ?int $employeeId = null): void
    {
        $this->guard->require(StockUnitPermissions::UNITS_MANAGE, $employeeId);

        if (! $this->options->unitsEnabled()) {
            throw new AccountingException(AccountingError::UnitsOff, 'Multiple units are switched off.');
        }

        $this->transactional(function () use ($itemId, $templateId, $employeeId): void {
            $item = $this->lockedItem($itemId);

            if ($templateId !== null) {
                $template = $this->db->table('unit_templates')->where('template_id', $templateId)->get()->getRow();

                if ($template === null || (! (bool) $template->active && $this->get($itemId)->unitTemplateId !== $templateId)) {
                    throw new AccountingException(AccountingError::UnitTemplateNotFound, 'The unit template does not exist or is not active.', ['id' => $templateId]);
                }
            }

            $before = $this->get($itemId);

            $this->save($itemId, $templateId, $before->tracking, $employeeId);
            $this->audit->write('item_stock_profile', $itemId, 'item_unit_template_set', $employeeId, JournalOrigin::User, ['name' => (string) $item->name, 'before' => $before->unitTemplateId, 'after' => $templateId]);
        });
    }

    /**
     * @throws AccountingException|PostingException
     */
    public function setTracking(int $itemId, TrackingType $type, ?int $employeeId = null): void
    {
        $this->guard->require(StockUnitPermissions::TRACKING_MANAGE, $employeeId);

        if (! $this->options->trackingEnabled()) {
            throw new AccountingException(AccountingError::TrackingOff, 'Tracking is switched off.');
        }

        $this->transactional(function () use ($itemId, $type, $employeeId): void {
            $item   = $this->lockedItem($itemId);
            $before = $this->get($itemId);

            if ($before->tracking === $type) {
                return;
            }

            if ((int) $item->stock_type !== HAS_STOCK || (int) $item->item_type !== ITEM || $this->holdsStock($itemId)) {
                throw new AccountingException(AccountingError::TrackingChangeWithStock, 'Only an item that keeps stock and holds none changes how it is tracked.', ['name' => (string) $item->name]);
            }

            $this->save($itemId, $before->unitTemplateId, $type, $employeeId);
            $this->audit->write('item_stock_profile', $itemId, 'item_tracking_set', $employeeId, JournalOrigin::User, ['name' => (string) $item->name, 'before' => $before->tracking->value, 'after' => $type->value]);
        });
    }

    public function holdsStock(int $itemId): bool
    {
        $shelves = $this->db->table('item_quantities')->where('item_id', $itemId)->where('quantity <>', 0)->countAllResults();
        $serials = $this->db->table('stock_serials')->where('item_id', $itemId)->whereIn('state', ['in_stock', 'returned'])->countAllResults();
        $batches = $this->db->table('stock_batch_quantities q')->join('stock_batches b', 'b.batch_id = q.batch_id')->where('b.item_id', $itemId)->where('q.quantity <>', 0)->countAllResults();

        return $shelves + $serials + $batches > 0;
    }

    private function save(int $itemId, ?int $templateId, TrackingType $tracking, ?int $employeeId): void
    {
        $this->db->query(
            'INSERT INTO ' . $this->db->prefixTable('item_stock_profiles') . ' (item_id, unit_template_id, tracking_type, updated_by, updated_at) VALUES (?, ?, ?, ?, NOW())
             ON DUPLICATE KEY UPDATE unit_template_id = VALUES(unit_template_id), tracking_type = VALUES(tracking_type), updated_by = VALUES(updated_by), updated_at = VALUES(updated_at)',
            [$itemId, $templateId, $tracking->value, $employeeId],
        );
    }

    /**
     * @throws AccountingException
     */
    private function lockedItem(int $itemId): object
    {
        $item = LockedRead::row($this->db, 'SELECT item_id, name, stock_type, item_type, deleted FROM ' . $this->db->prefixTable('items') . ' WHERE item_id = ? FOR UPDATE', [$itemId]);

        if ($item === null || (int) $item->deleted === 1) {
            throw new AccountingException(AccountingError::StockPermitLinesInvalid, 'The item does not exist.', ['line' => 0]);
        }

        return $item;
    }
}
