<?php

namespace Tests\Database;

use App\Database\Migrations\StockLocationStatus;
use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\DatabaseTestTrait;
use Config\Database;
use RuntimeException;
use Tests\Support\AccountingFixtureTrait;
use Throwable;

/**
 * @internal
 */
final class StockLocationStatusMigrationTest extends CIUnitTestCase
{
    use DatabaseTestTrait;
    use AccountingFixtureTrait;

    protected $migrate     = true;
    protected $migrateOnce = true;
    protected $refresh     = false;
    protected $namespace;

    public static function setUpBeforeClass(): void
    {
        $seeder = Database::seeder('tests');
        $seeder->call('TestDatabaseBootstrapSeeder');
    }

    protected function tearDown(): void
    {
        (new StockLocationStatus())->up();

        $db = $this->accountingDb();
        $db->query('UPDATE ' . $db->prefixTable('stock_locations') . ' SET status = 1, is_primary = 0');
        $db->query('UPDATE ' . $db->prefixTable('stock_locations') . ' SET is_primary = 1 WHERE location_id = 1');
        $this->removeInsertedEntities();

        parent::tearDown();
    }

    /**
     * @return array{type: string, nullable: string, default: ?string}|null
     */
    private function column(string $column): ?array
    {
        $row = $this->accountingDb()->query(
            'SELECT COLUMN_TYPE AS type, IS_NULLABLE AS nullable, COLUMN_DEFAULT AS dflt FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ?',
            [$this->accountingDb()->prefixTable('stock_locations'), $column],
        )->getRow();

        if ($row === null) {
            return null;
        }

        return ['type' => (string) preg_replace('/^((?:small|medium|big|tiny)?int)\(\d+\)/', '$1', strtolower((string) $row->type)), 'nullable' => (string) $row->nullable, 'default' => in_array($row->dflt, [null, 'NULL'], true) ? null : $row->dflt];
    }

    private function primaryIds(): array
    {
        return array_map('intval', array_column($this->accountingDb()->table('stock_locations')->select('location_id')->where(['deleted' => 0, 'is_primary' => 1])->get()->getResultArray(), 'location_id'));
    }

    public function testUp_AddsAnActiveStatusAndANotPrimaryFlagThatChangeNothingForTheLocationsThatExist(): void
    {
        $this->assertSame(['type' => 'tinyint', 'nullable' => 'NO', 'default' => '1'], $this->column('status'));
        $this->assertSame(['type' => 'tinyint', 'nullable' => 'NO', 'default' => '0'], $this->column('is_primary'));
        $this->assertNotNull($this->column('primary_slot'));
    }

    public function testUp_MarksTheFirstLocationPrimaryAndLeavesTheOthersActiveAndNotPrimary(): void
    {
        $second = $this->insertStockLocation('Second');
        $migration = new StockLocationStatus();

        $migration->down();
        $migration->up();

        $this->assertSame([1], $this->primaryIds());
        $this->assertSame('1', (string) $this->accountingDb()->table('stock_locations')->where('location_id', $second)->get()->getRow()->status);
        $this->assertSame('0', (string) $this->accountingDb()->table('stock_locations')->where('location_id', $second)->get()->getRow()->is_primary);
    }

    public function testUp_ChangesNothingElseAboutALocationThatExists(): void
    {
        $this->insertStockLocation('Quiet');
        $before    = $this->accountingDb()->table('stock_locations')->select('location_id, location_name, deleted, branch_id')->orderBy('location_id')->get()->getResultArray();
        $migration = new StockLocationStatus();

        $migration->down();
        $migration->up();

        $this->assertSame($before, $this->accountingDb()->table('stock_locations')->select('location_id, location_name, deleted, branch_id')->orderBy('location_id')->get()->getResultArray());
    }

    public function testUp_IsSafeToRunTwiceAndKeepsAPrimaryThatWasMoved(): void
    {
        $second = $this->insertStockLocation('Second');
        $db     = $this->accountingDb();
        $db->table('stock_locations')->update(['is_primary' => 0]);
        $db->table('stock_locations')->where('location_id', $second)->update(['is_primary' => 1]);

        (new StockLocationStatus())->up();
        (new StockLocationStatus())->up();

        $this->assertSame([$second], $this->primaryIds());
    }

    public function testUp_RefusesASecondLivePrimaryLocationAtTheDatabase(): void
    {
        $second = $this->insertStockLocation('Second');

        $this->expectException(Throwable::class);

        $this->accountingDb()->table('stock_locations')->where('location_id', $second)->update(['is_primary' => 1]);
    }

    public function testUp_LetsADeletedLocationKeepThePrimaryFlagWithoutCountingAsASecondPrimary(): void
    {
        $second = $this->insertStockLocation('Second');
        $db     = $this->accountingDb();

        $db->table('stock_locations')->where('location_id', $second)->update(['is_primary' => 1, 'deleted' => 1]);

        $this->assertSame([1], $this->primaryIds());
    }

    public function testDown_RemovesTheColumnsWhenNothingWasChanged(): void
    {
        (new StockLocationStatus())->down();

        $this->assertNull($this->column('status'));
        $this->assertNull($this->column('is_primary'));
        $this->assertNull($this->column('primary_slot'));
    }

    public function testDown_RefusesWhileALocationIsInactive(): void
    {
        $second = $this->insertStockLocation('Second');
        $this->accountingDb()->table('stock_locations')->where('location_id', $second)->update(['status' => 0]);

        try {
            (new StockLocationStatus())->down();
            $this->fail('down() dropped a status that was changed.');
        } catch (RuntimeException $refusal) {
            $this->assertNotNull($this->column('status'));
        }
    }

    public function testDown_RefusesOnceThePrimaryWasMovedToAnotherLocation(): void
    {
        $second = $this->insertStockLocation('Second');
        $db     = $this->accountingDb();
        $db->table('stock_locations')->update(['is_primary' => 0]);
        $db->table('stock_locations')->where('location_id', $second)->update(['is_primary' => 1]);

        try {
            (new StockLocationStatus())->down();
            $this->fail('down() dropped a primary flag that was moved.');
        } catch (RuntimeException $refusal) {
            $this->assertNotNull($this->column('is_primary'));
        }
    }
}
