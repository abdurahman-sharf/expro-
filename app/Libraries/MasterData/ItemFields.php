<?php

namespace App\Libraries\MasterData;

use App\Libraries\Accounting\AccountingError;
use App\Libraries\Accounting\AccountingException;
use App\Libraries\Accounting\AuditLog;
use App\Libraries\Accounting\JournalOrigin;
use CodeIgniter\Database\BaseConnection;
use Config\Database;

/**
 * The fields of a product that the books never read: the brand, the status and the code the supplier gives
 * the product. A value that is not allowed is refused with a named reason before the item is saved, and
 * every change is written to the audit log. A status other than active only hides the product from the
 * lists of new documents: the item, its stock and its history are not touched.
 */
final class ItemFields
{
    public const SUPPLIER_CODE_MAX = 100;
    public const FIELDS            = ['brand_id', 'status', 'supplier_code'];
    public const MARKER            = 'master_data';

    private BaseConnection $db;

    public function __construct(?BaseConnection $db = null)
    {
        $this->db = $db ?? Database::connect();
    }

    /**
     * What the item should hold after the form was saved, as the columns of the item. The form names every
     * field, so a field that was left empty clears it; with $partial only the fields that were given are
     * returned, which is how an import row leaves the cells it did not fill alone.
     *
     * @param array<string, mixed> $posted
     *
     * @return array<string, int|string|null>
     *
     * @throws AccountingException
     */
    public function read(array $posted, ?object $current, bool $partial = false): array
    {
        $columns = [];

        if (! $partial || array_key_exists('brand_id', $posted)) {
            $columns['brand_id'] = $this->brand($this->text($posted, 'brand_id'), $current->brand_id ?? null);
        }

        if (! $partial || array_key_exists('status', $posted)) {
            $columns['status'] = $this->status($this->text($posted, 'status'));
        }

        if (! $partial || array_key_exists('supplier_code', $posted)) {
            $columns['supplier_code'] = $this->supplierCode($this->text($posted, 'supplier_code'));
        }

        return $columns;
    }

    /**
     * @param array<string, int|string|null> $after
     */
    public function audit(int $itemId, ?object $before, array $after, ?int $employeeId): void
    {
        $changes = [];

        foreach (self::FIELDS as $field) {
            if (! array_key_exists($field, $after)) {
                continue;
            }

            $was = $before->{$field} ?? self::neutral($field);
            $was = $was === '' || ($field === 'brand_id' && (int) $was === 0) ? null : $was;

            if ((string) $was !== (string) $after[$field]) {
                $changes[$field] = ['from' => $was, 'to' => $after[$field]];
            }
        }

        if ($changes !== []) {
            (new AuditLog($this->db))->write('item', $itemId, 'item_master_data_changed', $employeeId, JournalOrigin::User, ['changes' => $changes]);
        }
    }

    public static function neutral(string $field): int|string|null
    {
        return $field === 'status' ? ItemStatus::Active->value : null;
    }

    /**
     * The status a typed word or number stands for: a number 0, 1 or 2, or the word active, inactive or suspended.
     *
     * @throws AccountingException
     */
    public static function statusOf(string $typed): ItemStatus
    {
        $typed = strtolower(trim($typed));

        foreach (ItemStatus::cases() as $status) {
            if ($typed === (string) $status->value || $typed === strtolower($status->name)) {
                return $status;
            }
        }

        throw new AccountingException(AccountingError::ItemStatusInvalid, 'The status is active, inactive or suspended.');
    }

    /**
     * @param array<string, mixed> $posted
     */
    private function text(array $posted, string $field): string
    {
        $value = $posted[$field] ?? '';

        return is_scalar($value) ? trim((string) $value) : '';
    }

    private function brand(string $typed, mixed $held): ?int
    {
        if ($typed === '' || $typed === '0') {
            return null;
        }

        $held = $held === null || (int) $held === 0 ? null : (int) $held;

        if (! ctype_digit($typed) || ! (new NamedLookup(LookupKind::ItemBrand, $this->db))->mayBeGiven((int) $typed, $held)) {
            throw new AccountingException(AccountingError::ItemBrandInvalid, 'The brand does not exist or is inactive.');
        }

        return (int) $typed;
    }

    private function status(string $typed): int
    {
        return $typed === '' ? ItemStatus::Active->value : self::statusOf($typed)->value;
    }

    private function supplierCode(string $typed): ?string
    {
        if (mb_strlen($typed) > self::SUPPLIER_CODE_MAX) {
            throw new AccountingException(AccountingError::ItemFieldTooLong, 'The supplier code is at most 100 characters.', ['max' => self::SUPPLIER_CODE_MAX, 'name' => lang('Items.supplier_code')]);
        }

        return $typed === '' ? null : $typed;
    }
}
