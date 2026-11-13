<?php

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

/**
 * The display order of four modules that shared a sort value with an older one: the currencies, the
 * extra fields, the sales orders and the units of stock each move to a value no module holds, and the
 * older module of each pair stays where it was. Only the position of a module changes, in every list that
 * orders the modules by this value (the permission lists, the tiles of the home screen and the navigation
 * menu), where two modules on one value were shown in an order the database leaves open; no permission,
 * grant, journal or document is touched. A module that is no longer on the tied value, or whose new value
 * is held by another module, is left alone, so the migration is safe to run again.
 */
class UniqueModuleSortValues extends Migration
{
    private const MOVES = [
        'accounting_currencies' => [132, 133],
        'custom_fields'         => [134, 135],
        'customer_orders'       => [140, 143],
        'stock_units'           => [141, 144],
    ];

    public function up(): void
    {
        foreach (self::MOVES as $moduleId => [$tied, $free]) {
            $held = $this->db->table('modules')->where('sort', $free)->where('module_id !=', $moduleId)->countAllResults() > 0;

            if (! $held) {
                $this->set($moduleId, $tied, $free);
            }
        }
    }

    public function down(): void
    {
        foreach (self::MOVES as $moduleId => [$tied, $free]) {
            $this->set($moduleId, $free, $tied);
        }
    }

    private function set(string $moduleId, int $from, int $to): void
    {
        $this->db->table('modules')->where(['module_id' => $moduleId, 'sort' => $from])->update(['sort' => $to]);
    }
}
