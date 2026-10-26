<?php

namespace Tests\Database;

use App\Database\Migrations\LegacyFixedPurchaseDiscount;
use App\Database\Migrations\ReceivingCostPrecision;
use App\Libraries\Accounting\LegacyDiscountBlocked;
use App\Libraries\Accounting\LegacyPurchaseDiscount;
use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\DatabaseTestTrait;
use Config\Database;
use Tests\Support\AccountingFixtureTrait;
use Tests\Support\ItemFixtureTrait;

class LegacyFixedPurchaseDiscountMigrationTest extends CIUnitTestCase
{
    use DatabaseTestTrait;
    use AccountingFixtureTrait;
    use ItemFixtureTrait;

    protected $migrate     = true;
    protected $migrateOnce = true;
    protected $refresh     = false;
    protected $namespace   = null;

    /**
     * @var list<int>
     */
    private array $receivingIds = [];

    public static function setUpBeforeClass(): void
    {
        $seeder = Database::seeder('tests');
        $seeder->call('TestDatabaseBootstrapSeeder');
    }

    protected function tearDown(): void
    {
        $db = $this->accountingDb();
        $db->query('SET FOREIGN_KEY_CHECKS = 0');

        foreach ($this->receivingIds as $receivingId) {
            $db->table('receivings_items')->where('receiving_id', $receivingId)->delete();
            $db->table('receivings')->where('receiving_id', $receivingId)->delete();
        }

        $db->query('SET FOREIGN_KEY_CHECKS = 1');
        $this->receivingIds = [];

        (new LegacyPurchaseDiscount())->clearResolution();
        (new ReceivingCostPrecision())->up();

        parent::tearDown();
    }

    private function scale(): int
    {
        return (int) $this->accountingDb()->query(
            'SELECT NUMERIC_SCALE AS scale FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ?',
            [$this->accountingDb()->prefixTable('receivings_items'), 'item_cost_price'],
        )->getRow()->scale;
    }

    /**
     * @return int The receiving, with one line of an item.
     */
    private function receiveWith(string $quantity, string $discount, int $discountType, string $perPack = '1', string $cost = '1.00', string $savedAt = '2026-10-01 09:00:00'): int
    {
        $db = $this->accountingDb();

        $db->table('receivings')->insert(['employee_id' => $this->employeeWithGrants([]), 'comment' => '', 'receiving_time' => $savedAt]);

        $receivingId          = (int) $db->insertID();
        $this->receivingIds[] = $receivingId;

        $db->table('receivings_items')->insert([
            'receiving_id'       => $receivingId,
            'item_id'            => $this->createTestItem(HAS_STOCK),
            'line'               => 1,
            'quantity_purchased' => $quantity,
            'receiving_quantity' => $perPack,
            'item_cost_price'    => $cost,
            'item_unit_price'    => '50.00',
            'discount'           => $discount,
            'discount_type'      => $discountType,
            'item_location'      => 1,
        ]);

        return $receivingId;
    }

    private function discountTypeOf(int $receivingId): int
    {
        return (int) $this->accountingDb()->table('receivings_items')->where('receiving_id', $receivingId)->get()->getRow()->discount_type;
    }

    public function testUp_MarksAFixedDiscountOfSeveralUnitsAsOneForTheWholeLine(): void
    {
        $several = $this->receiveWith('10', '100.00', FIXED);
        $packs   = $this->receiveWith('2', '5.00', FIXED, '12');

        (new LegacyFixedPurchaseDiscount())->up();

        $this->assertSame(FIXED_LINE, $this->discountTypeOf($several));
        $this->assertSame(FIXED_LINE, $this->discountTypeOf($packs));
    }

    public function testUp_LeavesAloneWhatMeansTheSameInBothWays(): void
    {
        $oneUnit = $this->receiveWith('1', '5.00', FIXED);
        $percent = $this->receiveWith('10', '10.00', PERCENT);
        $nothing = $this->receiveWith('10', '0.00', FIXED);
        $onePack = $this->receiveWith('1', '5.00', FIXED, '1');

        (new LegacyFixedPurchaseDiscount())->up();

        $this->assertSame(FIXED, $this->discountTypeOf($oneUnit));
        $this->assertSame(PERCENT, $this->discountTypeOf($percent));
        $this->assertSame(FIXED, $this->discountTypeOf($nothing));
        $this->assertSame(FIXED, $this->discountTypeOf($onePack));
    }

    public function testUp_IsSafeToRunTwice(): void
    {
        $line = $this->receiveWith('10', '100.00', FIXED);

        (new LegacyFixedPurchaseDiscount())->up();
        (new LegacyFixedPurchaseDiscount())->up();

        $this->assertSame(FIXED_LINE, $this->discountTypeOf($line));
        $this->assertSame('100.00', (string) $this->accountingDb()->table('receivings_items')->where('receiving_id', $line)->get()->getRow()->discount);
    }

    public function testUp_StopsAndChangesNothingWhileALineCannotBeToldApart(): void
    {
        $before    = $this->receiveWith('10', '100.00', FIXED);
        $undecided = $this->receiveWith('10', '100.00', FIXED, '1', '1.00', date('Y-m-d H:i:s'));

        try {
            (new LegacyFixedPurchaseDiscount())->up();
            $this->fail('A line nothing decides must stop the migration.');
        } catch (LegacyDiscountBlocked $blocked) {
            $this->assertStringContainsString('php spark accounting:purchase_discount', $blocked->getMessage());
        }

        $this->assertSame(FIXED, $this->discountTypeOf($before));
        $this->assertSame(FIXED, $this->discountTypeOf($undecided));
    }

    public function testUp_MarksWhatTheOperatorDecidedWasSavedBeforeTheMoment(): void
    {
        $early = $this->receiveWith('10', '100.00', FIXED, '1', '1.00', '2026-10-26 08:00:00');
        $late  = $this->receiveWith('10', '100.00', FIXED, '1', '1.00', '2026-10-26 18:00:00');

        (new LegacyPurchaseDiscount())->resolve(LegacyPurchaseDiscount::BEFORE, '2026-10-26 12:00:00');
        (new LegacyFixedPurchaseDiscount())->up();

        $this->assertSame(FIXED_LINE, $this->discountTypeOf($early));
        $this->assertSame(FIXED, $this->discountTypeOf($late));
    }

    public function testDown_GivesTheMarkedLinesTheMeaningOfAFixedDiscountAgain(): void
    {
        $line = $this->receiveWith('10', '100.00', FIXED);

        (new LegacyFixedPurchaseDiscount())->up();
        (new LegacyFixedPurchaseDiscount())->down();

        $this->assertSame(FIXED, $this->discountTypeOf($line));
    }

    public function testReceivingCostPrecisionUp_KeepsEveryCostThatWasThereAndTakesSixDecimals(): void
    {
        (new ReceivingCostPrecision())->down();

        $this->assertSame(2, $this->scale());

        $line = $this->receiveWith('1', '0.00', PERCENT, '1', '71.43');

        (new ReceivingCostPrecision())->up();
        (new ReceivingCostPrecision())->up();

        $this->assertSame(6, $this->scale());
        $this->assertSame('71.430000', (string) $this->accountingDb()->table('receivings_items')->where('receiving_id', $line)->get()->getRow()->item_cost_price);

        $this->accountingDb()->table('receivings_items')->where('receiving_id', $line)->update(['item_cost_price' => '71.428571']);

        $this->assertSame('71.428571', (string) $this->accountingDb()->table('receivings_items')->where('receiving_id', $line)->get()->getRow()->item_cost_price);
    }

    public function testReceivingCostPrecisionDown_BringsTheColumnBackToTwoDecimals(): void
    {
        (new ReceivingCostPrecision())->down();
        (new ReceivingCostPrecision())->down();

        $this->assertSame(2, $this->scale());
    }
}
