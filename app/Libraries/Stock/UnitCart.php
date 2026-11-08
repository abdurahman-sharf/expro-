<?php

namespace App\Libraries\Stock;

use App\Libraries\Accounting\AccountingError;
use App\Libraries\Accounting\AccountingException;
use CodeIgniter\Database\BaseConnection;
use Config\Database;

/**
 * What the registers do with a unit on a cart line. The line keeps base units, which is what its total,
 * its taxes and everything after it read, and remembers the unit it is shown in with the quantity and the
 * price as entered. Choosing a unit shows the line in it; typing in a line that has a unit is typing in
 * that unit, and the line is converted back to base units when it is stored. When the base quantity
 * changes by other means (the same item scanned again) the line is shown in its unit again if the
 * quantity still divides exactly, and goes back to base units if it does not.
 */
final class UnitCart
{
    private const KEYS = ['unit_id', 'unit_factor', 'unit_quantity', 'unit_price'];

    private UnitTemplates $templates;
    private UnitLines $units;

    public function __construct(?BaseConnection $db = null)
    {
        $db ??= Database::connect();
        $this->templates = new UnitTemplates($db);
        $this->units     = new UnitLines($db);
    }

    /**
     * @param array<string, mixed> $line
     *
     * @return array<string, mixed>
     */
    public function clear(array $line): array
    {
        foreach (self::KEYS as $key) {
            unset($line[$key]);
        }

        return $line;
    }

    /**
     * @param array<string, mixed> $line
     */
    public function hasUnit(array $line): bool
    {
        return isset($line['unit_id']) && $line['unit_id'] !== '' && $line['unit_id'] !== null;
    }

    /**
     * Shows a line in a unit of its item's template, or in base units for a null unit.
     *
     * @param array<string, mixed> $line
     *
     * @return array<string, mixed>
     *
     * @throws AccountingException
     */
    public function switchTo(array $line, ?int $unitId): array
    {
        if ($unitId === null) {
            return $this->clear($line);
        }

        $unit = $this->templates->unitOfItem((int) $line['item_id'], $unitId);

        if ($unit === null) {
            throw new AccountingException(AccountingError::UnitNotOfItem, 'The unit is not a unit of the template of the item.', ['name' => (string) ($line['name'] ?? $line['item_id'])]);
        }

        return $this->present($line, $unit);
    }

    /**
     * Converts the quantity and the price typed in the unit of the line to base units.
     *
     * @param array<string, mixed> $line
     * @param numeric-string       $enteredQuantity
     * @param numeric-string       $enteredPrice
     *
     * @return array<string, mixed>
     *
     * @throws AccountingException
     */
    public function enter(array $line, string $enteredQuantity, string $enteredPrice): array
    {
        $converted = $this->units->convert((int) $line['item_id'], (int) $line['unit_id'], $enteredQuantity, $enteredPrice, (int) ($line['line'] ?? 0));

        $line['quantity']      = $converted->baseQuantity;
        $line['price']         = $converted->basePrice;
        $line['unit_factor']   = $converted->factor;
        $line['unit_quantity'] = bcadd($enteredQuantity, '0', UnitLine::QUANTITY_SCALE);
        $line['unit_price']    = $converted->enteredPrice;

        return $line;
    }

    /**
     * Shows a line whose base quantity changed in its unit again, or in base units when it no longer
     * divides exactly.
     *
     * @param array<string, mixed> $line
     *
     * @return array<string, mixed>
     */
    public function refresh(array $line): array
    {
        if (! $this->hasUnit($line)) {
            return $line;
        }

        try {
            return $this->switchTo($line, (int) $line['unit_id']);
        } catch (AccountingException) {
            return $this->clear($line);
        }
    }

    /**
     * @param array<string, mixed> $line
     *
     * @return array<string, mixed>
     *
     * @throws AccountingException
     */
    private function present(array $line, TemplateUnit $unit): array
    {
        $entered = bcdiv((string) $line['quantity'], $unit->factor, UnitLine::QUANTITY_SCALE);

        if (bccomp(bcmul($entered, $unit->factor, 8), bcadd((string) $line['quantity'], '0', 8), 8) !== 0) {
            throw new AccountingException(AccountingError::UnitQuantityInexact, 'The quantity does not divide exactly into this unit.', ['line' => (int) ($line['line'] ?? 0)]);
        }

        $line['unit_id']       = $unit->unitId;
        $line['unit_factor']   = $unit->factor;
        $line['unit_quantity'] = $entered;
        $line['unit_price']    = bcadd(bcmul((string) $line['price'], $unit->factor, 8), '0', UnitLine::COST_SCALE);

        return $line;
    }
}
