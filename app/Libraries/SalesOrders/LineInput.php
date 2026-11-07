<?php

namespace App\Libraries\SalesOrders;

use App\Libraries\Accounting\AccountingError;
use App\Libraries\Accounting\AccountingException;
use CodeIgniter\Database\BaseConnection;

/**
 * The lines of a document that is written before it is an invoice (a sales order, the template of a
 * schedule), checked and put in the shape they are stored in. Every line is sold from one stock
 * location, so a document belongs to one branch, the branch of that location.
 */
final class LineInput
{
    public const MAX_LINES       = 200;
    public const DESCRIPTION_MAX = 255;
    private const QUANTITY       = '/^\d{1,12}(\.\d{1,3})?$/';
    private const MONEY          = '/^\d{1,13}(\.\d{1,2})?$/';

    /**
     * @param mixed $raw The lines as they were typed: item_id, quantity, unit_price, and optionally discount, discount_type (0 percent, 1 fixed) and description.
     *
     * @return list<array{item_id: int, description: string, quantity: string, unit_price: string, discount: string, discount_type: int, item_location: int}>
     *
     * @throws AccountingException
     */
    public static function normalise(mixed $raw, int $locationId, BaseConnection $db, AccountingError $noLines, AccountingError $invalid): array
    {
        if (! is_array($raw) || $raw === []) {
            throw new AccountingException($noLines, 'The document has no lines.');
        }

        if (count($raw) > self::MAX_LINES) {
            throw new AccountingException($invalid, 'The document has too many lines.', ['line' => self::MAX_LINES + 1, 'max' => self::MAX_LINES]);
        }

        $lines = [];
        $ids   = [];

        foreach (array_values($raw) as $index => $line) {
            $number = $index + 1;

            if (! is_array($line)) {
                throw new AccountingException($invalid, "Line {$number} is not a line.", ['line' => $number]);
            }

            $itemId   = (int) ($line['item_id'] ?? 0);
            $quantity = self::amount($line['quantity'] ?? '', self::QUANTITY, 3, $number, $invalid);
            $price    = self::amount($line['unit_price'] ?? '', self::MONEY, 2, $number, $invalid);
            $discount = self::amount(($line['discount'] ?? '') === '' ? '0' : $line['discount'], self::MONEY, 2, $number, $invalid);
            $type     = (int) ($line['discount_type'] ?? 0);
            $text     = trim((string) ($line['description'] ?? ''));

            if ($itemId <= 0 || bccomp($quantity, '0', 3) <= 0 || ! in_array($type, [0, 1], true) || mb_strlen($text) > self::DESCRIPTION_MAX) {
                throw new AccountingException($invalid, "Line {$number} is not valid.", ['line' => $number]);
            }

            if ($type === 0 && bccomp($discount, '100', 2) > 0) {
                throw new AccountingException($invalid, "The discount of line {$number} is more than a hundred percent.", ['line' => $number]);
            }

            if ($type === 1 && bccomp($discount, bcmul($quantity, $price, 2), 2) > 0) {
                throw new AccountingException($invalid, "The discount of line {$number} is more than the line.", ['line' => $number]);
            }

            $ids[]   = $itemId;
            $lines[] = ['item_id' => $itemId, 'description' => $text, 'quantity' => $quantity, 'unit_price' => $price, 'discount' => $discount, 'discount_type' => $type, 'item_location' => $locationId];
        }

        $found = array_map('intval', array_column($db->table('items')->select('item_id')->whereIn('item_id', array_values(array_unique($ids)))->where('deleted', 0)->get()->getResultArray(), 'item_id'));

        foreach ($lines as $index => $line) {
            if (! in_array($line['item_id'], $found, true)) {
                throw new AccountingException($invalid, 'The item of line ' . ($index + 1) . ' does not exist.', ['line' => $index + 1]);
            }
        }

        return $lines;
    }

    /**
     * @throws AccountingException
     */
    private static function amount(mixed $value, string $pattern, int $scale, int $line, AccountingError $invalid): string
    {
        $text = is_int($value) || is_float($value) ? (string) $value : (is_string($value) ? trim($value) : '');

        if (preg_match($pattern, $text) !== 1) {
            throw new AccountingException($invalid, "A figure of line {$line} is not a number.", ['line' => $line]);
        }

        return bcadd($text, '0', $scale);
    }
}
