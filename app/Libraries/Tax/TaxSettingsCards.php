<?php

namespace App\Libraries\Tax;

/**
 * The cards of the center of the tax settings (§6.2): each opens the screen where a setting is kept today, and is shown
 * only to whoever holds the module of that screen. The center keeps nothing of its own, and every old link still works.
 */
final class TaxSettingsCards
{
    /**
     * @var list<array{id: string, module: string, path: string}>
     */
    public const CARDS = [
        ['id' => 'company', 'module' => 'config', 'path' => 'config'],
        ['id' => 'vat_mode', 'module' => 'accounting_einvoicing', 'path' => 'accounting_einvoicing'],
        ['id' => 'classes', 'module' => 'taxes', 'path' => 'vat_classes'],
        ['id' => 'definitions', 'module' => 'taxes', 'path' => 'tax_definitions'],
        ['id' => 'legacy', 'module' => 'taxes', 'path' => 'taxes'],
        ['id' => 'accounts', 'module' => 'accounting_routing', 'path' => 'accounting_routing'],
        ['id' => 'invoices', 'module' => 'config', 'path' => 'config'],
        ['id' => 'einvoicing', 'module' => 'accounting_einvoicing', 'path' => 'accounting_einvoicing'],
        ['id' => 'zatca', 'module' => 'accounting_einvoicing', 'path' => 'accounting_zatca'],
        ['id' => 'reports', 'module' => 'accounting_reports', 'path' => 'accounting_reports/tax'],
    ];

    /**
     * @param list<string> $held The modules the employee holds.
     *
     * @return list<array{id: string, module: string, path: string}>
     */
    public static function visibleFor(array $held): array
    {
        return array_values(array_filter(self::CARDS, static fn (array $card): bool => in_array($card['module'], $held, true)));
    }
}
