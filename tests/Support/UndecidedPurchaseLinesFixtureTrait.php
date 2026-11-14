<?php

namespace Tests\Support;

use App\Libraries\Accounting\LegacyPurchaseDiscount;

/**
 * Purchase lines with a fixed discount over several units that nothing tells apart as saved before
 * or after the discount became an amount for every unit, with the migration that needs the
 * operator's decision about them taken out of the history. A class that uses it also uses
 * AccountingFixtureTrait, EmployeeFixtureTrait, ItemFixtureTrait and MigrationHistoryFixtureTrait,
 * and calls removeUndecidedPurchaseLines() when each test ends.
 */
trait UndecidedPurchaseLinesFixtureTrait
{
    private const DISCOUNT_MIGRATION = '20261029000000';

    /** @var list<int> */
    private array $undecidedPurchaseIds = [];

    protected function leaveUndecidedPurchaseLines(): void
    {
        (new LegacyPurchaseDiscount())->clearResolution();

        $this->savePurchaseLineAt('2026-10-01 09:00:00');
        $this->savePurchaseLineAt(date('Y-m-d H:i:s'));
        $this->forgetMigration(self::DISCOUNT_MIGRATION);
    }

    protected function savePurchaseLineAt(string $savedAt): void
    {
        $db = $this->accountingDb();

        $db->table('receivings')->insert(['employee_id' => $this->employeeWithGrants([]), 'comment' => '', 'receiving_time' => $savedAt]);

        $receivingId                  = (int) $db->insertID();
        $this->undecidedPurchaseIds[] = $receivingId;

        $db->table('receivings_items')->insert([
            'receiving_id'       => $receivingId,
            'item_id'            => $this->createTestItem(HAS_STOCK),
            'line'               => 1,
            'quantity_purchased' => '10',
            'receiving_quantity' => '1',
            'item_cost_price'    => '1.00',
            'item_unit_price'    => '50.00',
            'discount'           => '20.00',
            'discount_type'      => FIXED,
            'item_location'      => 1,
        ]);
    }

    protected function removeUndecidedPurchaseLines(): void
    {
        $db = $this->accountingDb();
        $db->query('SET FOREIGN_KEY_CHECKS = 0');

        foreach ($this->undecidedPurchaseIds as $receivingId) {
            $db->table('receivings_items')->where('receiving_id', $receivingId)->delete();
            $db->table('receivings')->where('receiving_id', $receivingId)->delete();
        }

        $db->query('SET FOREIGN_KEY_CHECKS = 1');
        $this->undecidedPurchaseIds = [];

        (new LegacyPurchaseDiscount())->clearResolution();
    }
}
