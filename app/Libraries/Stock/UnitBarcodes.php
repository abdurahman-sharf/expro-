<?php

namespace App\Libraries\Stock;

use App\Libraries\Accounting\AccountingError;
use App\Libraries\Accounting\AccountingException;
use App\Libraries\Accounting\AccountingGuard;
use App\Libraries\Accounting\AuditLog;
use App\Libraries\Accounting\JournalOrigin;
use CodeIgniter\Database\BaseConnection;
use Config\Database;

/**
 * A barcode for each unit of an item. Scanning one enters its item in the unit it names (a carton barcode
 * enters a carton). A barcode belongs to one item and one unit for good and may not be the number of any
 * item, so that a scan never means two things. The reference system asks for a factor with every barcode
 * row of a product, which here is the unit the barcode names, and a barcode with no unit is another way
 * to scan the base unit.
 */
final class UnitBarcodes
{
    public const MAX = 255;

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

    /**
     * @throws AccountingException
     */
    public function assign(int $itemId, ?int $unitId, string $barcode, ?int $employeeId = null): int
    {
        $this->guard->require(StockUnitPermissions::UNITS_MANAGE, $employeeId);

        if (! $this->options->unitsEnabled()) {
            throw new AccountingException(AccountingError::UnitsOff, 'Multiple units are switched off.');
        }

        $barcode = trim($barcode);
        $item    = $this->db->table('items')->select('item_id, name')->where(['item_id' => $itemId, 'deleted' => 0])->get()->getRow();

        if ($barcode === '' || mb_strlen($barcode) > self::MAX || $item === null) {
            throw new AccountingException(AccountingError::UnitBarcodeTaken, 'The barcode is empty or too long.', ['code' => $barcode]);
        }

        if ($unitId !== null && (new UnitTemplates($this->db, $this->guard))->unitOfItem($itemId, $unitId) === null) {
            throw new AccountingException(AccountingError::UnitNotOfItem, 'The unit is not a unit of the template of the item.', ['name' => (string) $item->name]);
        }

        if ($this->db->table('item_unit_barcodes')->where('barcode', $barcode)->countAllResults() > 0 || $this->db->table('items')->where('item_number', $barcode)->countAllResults() > 0) {
            throw new AccountingException(AccountingError::UnitBarcodeTaken, 'The barcode already belongs to an item or a unit.', ['code' => $barcode]);
        }

        $this->db->table('item_unit_barcodes')->insert(['item_id' => $itemId, 'unit_id' => $unitId, 'barcode' => $barcode]);

        $barcodeId = (int) $this->db->insertID();

        $this->audit->write('item_unit_barcode', $barcodeId, 'unit_barcode_assigned', $employeeId, JournalOrigin::User, ['item_id' => $itemId, 'unit_id' => $unitId, 'barcode' => $barcode]);

        return $barcodeId;
    }

    /**
     * @throws AccountingException
     */
    public function remove(int $barcodeId, ?int $employeeId = null): void
    {
        $this->guard->require(StockUnitPermissions::UNITS_MANAGE, $employeeId);

        $row = $this->db->table('item_unit_barcodes')->where('barcode_id', $barcodeId)->get()->getRow();

        if ($row === null) {
            return;
        }

        $this->db->table('item_unit_barcodes')->where('barcode_id', $barcodeId)->delete();
        $this->audit->write('item_unit_barcode', $barcodeId, 'unit_barcode_removed', $employeeId, JournalOrigin::User, ['item_id' => (int) $row->item_id, 'barcode' => (string) $row->barcode]);
    }

    /**
     * The item and the unit a scanned code names, or null for a code that is none of the barcodes.
     *
     * @return array{itemId: int, unitId: ?int}|null
     */
    public function resolve(string $code): ?array
    {
        if (! $this->options->unitsEnabled()) {
            return null;
        }

        $row = $this->db->table('item_unit_barcodes b')->select('b.item_id, b.unit_id')->join('items i', 'i.item_id = b.item_id')->where(['b.barcode' => trim($code), 'i.deleted' => 0])->get()->getRow();

        return $row === null ? null : ['itemId' => (int) $row->item_id, 'unitId' => $row->unit_id === null ? null : (int) $row->unit_id];
    }

    /**
     * @return list<object>
     */
    public function ofItem(int $itemId): array
    {
        return $this->db->table('item_unit_barcodes')->where('item_id', $itemId)->orderBy('barcode_id', 'ASC')->get()->getResult();
    }
}
