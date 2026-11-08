<?php

namespace Tests\Support;

use App\Libraries\Accounting\AccountingGuard;
use App\Libraries\Stock\ItemStockProfile;
use App\Libraries\Stock\StockOptions;
use App\Libraries\Stock\StockPermissions;
use App\Libraries\Stock\StockUnitPermissions;
use App\Libraries\Stock\UnitLines;
use App\Libraries\Stock\UnitTemplates;

/**
 * What a test needs to use multiple units, bundles and tracking through the real services: an employee
 * who holds their permissions and the accounting settings permission, a way to switch each setting on,
 * a template of cartons, and a clean-up of the tables the units and the tracking leave behind. A class
 * that uses it also uses AccountingFixtureTrait, EmployeeFixtureTrait and ItemFixtureTrait.
 */
trait StockUnitsFixtureTrait
{
    protected int $unitManagerId;

    protected function startStockUnits(): void
    {
        $this->unitManagerId = $this->employeeWithGrants([...StockUnitPermissions::ALL, StockUnitPermissions::MODULE, AccountingGuard::SETTINGS_MANAGE, ...StockPermissions::ALL, StockPermissions::MODULE, 'items_stock']);
    }

    protected function stopStockUnits(): void
    {
        $db = $this->accountingDb();
        $this->releaseOpenTransactions();

        $db->query('SET FOREIGN_KEY_CHECKS = 0');

        foreach (['stock_tracking_lines', 'stock_batch_quantities', 'stock_batches', 'stock_serials', 'item_unit_barcodes', 'item_stock_profiles', 'unit_template_units', 'unit_templates'] as $table) {
            $db->table($table)->truncate();
        }

        $db->query('SET FOREIGN_KEY_CHECKS = 1');
        $db->table('app_config')->whereIn('key', [StockOptions::UNITS, StockOptions::BUNDLES, StockOptions::TRACKING, StockOptions::EXPIRED_POLICY, StockOptions::NEGATIVE_STOCK])->delete();
        $db->table('accounting_audit_log')->whereIn('entity_type', ['unit_template', 'item_stock_profile', 'item_unit_barcode'])->delete();
    }

    protected function switchOn(string ...$flags): void
    {
        $keys = ['units' => StockOptions::UNITS, 'bundles' => StockOptions::BUNDLES, 'tracking' => StockOptions::TRACKING];

        foreach ($flags as $flag) {
            $this->setConfigValue($keys[$flag], '1');
        }
    }

    /**
     * @param list<array{name: string, factor: string, small_name: string}>|null $units
     */
    protected function template(string $name = 'Cartons', ?array $units = null): int
    {
        $this->switchOn('units');

        return (new UnitTemplates())->create([
            'name'            => $name,
            'main_unit_name'  => 'Piece',
            'main_small_name' => 'pc',
            'units'           => $units ?? [['name' => 'Carton', 'factor' => '12', 'small_name' => 'ctn']],
        ], $this->unitManagerId);
    }

    protected function unitIdOf(int $templateId, string $name = 'Carton'): int
    {
        foreach ((new UnitTemplates())->find($templateId)->units as $unit) {
            if ($unit->name === $name) {
                return $unit->unitId;
            }
        }

        $this->fail("The template has no unit named {$name}.");
    }

    /**
     * An item that keeps stock at the cost given and may be entered in cartons of twelve.
     *
     * @return array{item: int, template: int, carton: int}
     */
    protected function cartonItem(string $cost = '5.00'): array
    {
        $this->startStockUnits();

        $templateId = $this->template();
        $itemId     = $this->createTestItem();

        $this->accountingDb()->table('items')->where('item_id', $itemId)->update(['cost_price' => $cost]);
        (new ItemStockProfile())->setTemplate($itemId, $templateId, $this->unitManagerId);

        return ['item' => $itemId, 'template' => $templateId, 'carton' => $this->unitIdOf($templateId)];
    }

    /**
     * A cart line turned into a line entered in a unit, as the register and the purchase screen keep it.
     *
     * @param array<string, mixed> $line
     *
     * @return array<string, mixed>
     */
    protected function inUnit(array $line, int $unitId, string $enteredQuantity, string $enteredPrice): array
    {
        $unit = (new UnitLines())->convert((int) $line['item_id'], $unitId, $enteredQuantity, $enteredPrice, (int) $line['line']);

        return [...$line, 'quantity' => $unit->baseQuantity, 'price' => $unit->basePrice, 'unit_id' => $unitId, 'unit_factor' => $unit->factor, 'unit_quantity' => $enteredQuantity, 'unit_price' => $enteredPrice];
    }
}
