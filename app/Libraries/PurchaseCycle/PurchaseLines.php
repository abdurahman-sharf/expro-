<?php

namespace App\Libraries\PurchaseCycle;

use App\Libraries\Accounting\AccountingError;
use App\Libraries\Accounting\AccountingException;
use App\Libraries\Accounting\TextInput;
use CodeIgniter\Database\BaseConnection;

/**
 * The lines of a purchase cycle document as they were typed, checked and put in the shape they are stored in:
 * an item that exists, a quantity above zero, the price expected, quoted or ordered for one unit, which may
 * be zero, and a note.
 */
final class PurchaseLines
{
    public const MAX_LINES       = 200;
    public const DESCRIPTION_MAX = 255;
    private const QUANTITY       = '/^\d{1,12}(\.\d{1,3})?$/';
    private const MONEY          = '/^\d{1,13}(\.\d{1,2})?$/';

    /**
     * @param mixed $raw The lines as they were typed: item_id, quantity, unit_price and description.
     *
     * @return list<array{item_id: int, description: string, quantity: string, unit_price: string}>
     *
     * @throws AccountingException
     */
    public static function normalise(mixed $raw, BaseConnection $db): array
    {
        if (! is_array($raw) || $raw === []) {
            throw new AccountingException(AccountingError::PurchaseDocumentNoLines, 'The document has no lines.');
        }

        if (count($raw) > self::MAX_LINES) {
            throw new AccountingException(AccountingError::PurchaseDocumentLineInvalid, 'The document has too many lines.', ['line' => self::MAX_LINES + 1, 'max' => self::MAX_LINES]);
        }

        $lines = [];
        $ids   = [];

        foreach (array_values($raw) as $index => $line) {
            $number = $index + 1;

            if (! is_array($line)) {
                throw new AccountingException(AccountingError::PurchaseDocumentLineInvalid, "Line {$number} is not a line.", ['line' => $number]);
            }

            $itemId   = (int) ($line['item_id'] ?? 0);
            $quantity = self::amount($line['quantity'] ?? '', self::QUANTITY, 3, $number);
            $price    = self::amount(($line['unit_price'] ?? '') === '' ? '0' : $line['unit_price'], self::MONEY, 2, $number);
            $text     = TextInput::of($line['description'] ?? null, AccountingError::PurchaseDocumentLineInvalid);

            if ($itemId <= 0 || bccomp($quantity, '0', 3) <= 0 || mb_strlen($text) > self::DESCRIPTION_MAX) {
                throw new AccountingException(AccountingError::PurchaseDocumentLineInvalid, "Line {$number} is not valid.", ['line' => $number]);
            }

            $ids[]   = $itemId;
            $lines[] = ['item_id' => $itemId, 'description' => $text, 'quantity' => $quantity, 'unit_price' => $price];
        }

        $found = array_map('intval', array_column($db->table('items')->select('item_id')->whereIn('item_id', array_values(array_unique($ids)))->where('deleted', 0)->get()->getResultArray(), 'item_id'));

        foreach ($lines as $index => $line) {
            if (! in_array($line['item_id'], $found, true)) {
                throw new AccountingException(AccountingError::PurchaseDocumentLineInvalid, 'The item of line ' . ($index + 1) . ' does not exist.', ['line' => $index + 1]);
            }
        }

        return $lines;
    }

    /**
     * @throws AccountingException
     */
    private static function amount(mixed $value, string $pattern, int $scale, int $line): string
    {
        $text = is_int($value) || is_float($value) ? (string) $value : (is_string($value) ? trim($value) : '');

        if (preg_match($pattern, $text) !== 1) {
            throw new AccountingException(AccountingError::PurchaseDocumentLineInvalid, "A figure of line {$line} is not a number.", ['line' => $line]);
        }

        return bcadd($text, '0', $scale);
    }
}
