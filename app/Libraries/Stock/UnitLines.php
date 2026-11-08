<?php

namespace App\Libraries\Stock;

use App\Libraries\Accounting\AccountingError;
use App\Libraries\Accounting\AccountingException;
use CodeIgniter\Database\BaseConnection;
use Config\Database;

/**
 * Where a unit line is built from what was typed and where a saved document checks it again. A cart line
 * entered in a larger unit is converted to base units when it is entered, so that the totals, the taxes,
 * the stock and the cost the rest of the system reads are those of base units. The models that save a
 * document check the line once more before storing it, so that a line made by some other caller cannot
 * carry a unit it did not convert by: the unit must be one of the item's template, the factor the one of
 * the unit, and the quantity and the price in base units exactly what the entered ones convert to.
 */
final class UnitLines
{
    private BaseConnection $db;
    private StockOptions $options;
    private UnitTemplates $templates;

    public function __construct(?BaseConnection $db = null)
    {
        $this->db        = $db ?? Database::connect();
        $this->options   = new StockOptions($this->db);
        $this->templates = new UnitTemplates($this->db);
    }

    /**
     * @param numeric-string $enteredQuantity
     * @param numeric-string $enteredPrice
     *
     * @throws AccountingException
     */
    public function convert(int $itemId, int $unitId, string $enteredQuantity, string $enteredPrice, int $line = 0, int $priceScale = UnitLine::PRICE_SCALE): UnitLine
    {
        if (! $this->options->unitsEnabled()) {
            throw new AccountingException(AccountingError::UnitsOff, 'Multiple units are switched off.');
        }

        $unit = $this->templates->unitOfItem($itemId, $unitId);

        if ($unit === null) {
            throw new AccountingException(AccountingError::UnitNotOfItem, 'The unit is not a unit of the template of the item.', ['name' => $this->nameOf($itemId)]);
        }

        return UnitLine::convert($unitId, $unit->factor, $enteredQuantity, $enteredPrice, $line, $priceScale);
    }

    /**
     * The columns that remember the unit of a line about to be stored, after the line has been checked.
     * A line with no unit has none.
     *
     * @param array<string, mixed> $line      The cart line: the base quantity and price, and the unit it was entered in.
     * @param string               $quantity  The key of the base quantity.
     * @param string               $price     The key of the base price.
     * @param string               $perPack   The key of the units of a pack when the document has packs, or an empty string.
     * @param string               $enteredAs The column that holds the price entered.
     *
     * @return array<string, int|string|null>
     *
     * @throws AccountingException
     */
    public function columnsOf(array $line, int $number, string $quantity, string $price, string $perPack, string $enteredAs, int $priceScale = UnitLine::PRICE_SCALE): array
    {
        $unitId = isset($line['unit_id']) && $line['unit_id'] !== '' ? (int) $line['unit_id'] : null;

        if ($unitId === null) {
            return UnitLine::base('0', '0')->columns($enteredAs);
        }

        if ($perPack !== '' && bccomp((string) ($line[$perPack] ?? '1'), '1', 3) !== 0 && bccomp((string) ($line[$perPack] ?? '1'), '0', 3) !== 0) {
            throw new AccountingException(AccountingError::UnitWithPack, 'A line names a larger unit and a pack.', ['line' => $number]);
        }

        $converted = $this->convert((int) $line['item_id'], $unitId, (string) ($line['unit_quantity'] ?? ''), (string) ($line['unit_price'] ?? ''), $number, $priceScale);

        if (bccomp($converted->factor, bcadd((string) ($line['unit_factor'] ?? '0'), '0', UnitLine::FACTOR_SCALE), UnitLine::FACTOR_SCALE) !== 0
            || bccomp($converted->baseQuantity, bcadd((string) $line[$quantity], '0', UnitLine::QUANTITY_SCALE), UnitLine::QUANTITY_SCALE) !== 0
            || bccomp($converted->basePrice, bcadd((string) $line[$price], '0', $priceScale), $priceScale) !== 0) {
            throw new AccountingException(AccountingError::UnitQuantityInexact, 'The line does not say what its unit converts to.', ['line' => $number]);
        }

        return $converted->columns($enteredAs);
    }

    private function nameOf(int $itemId): string
    {
        $item = $this->db->table('items')->select('name')->where('item_id', $itemId)->get()->getRow();

        return $item === null ? (string) $itemId : (string) $item->name;
    }
}
