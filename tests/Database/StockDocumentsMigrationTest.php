<?php

namespace Tests\Database;

use App\Database\Migrations\StockDocuments;
use CodeIgniter\Database\Exceptions\DatabaseException;
use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\DatabaseTestTrait;
use Config\Database;
use Tests\Support\AccountingFixtureTrait;

class StockDocumentsMigrationTest extends CIUnitTestCase
{
    use DatabaseTestTrait;
    use AccountingFixtureTrait;

    protected $migrate     = true;
    protected $migrateOnce = true;
    protected $refresh     = false;
    protected $namespace   = null;

    private const TABLES      = ['stock_permits', 'stock_permit_lines', 'stocktakes', 'stocktake_lines', 'stock_requests', 'stock_request_lines'];
    private const PERMISSIONS = ['stock_documents', 'stk_permits_manage', 'stk_stocktake_manage', 'stk_stocktake_approve', 'stk_requests_manage', 'stk_requests_approve'];

    public static function setUpBeforeClass(): void
    {
        $seeder = Database::seeder('tests');
        $seeder->call('TestDatabaseBootstrapSeeder');
    }

    protected function tearDown(): void
    {
        (new StockDocuments())->up();

        $db = $this->accountingDb();
        $db->query('SET FOREIGN_KEY_CHECKS = 0');

        foreach (['stock_request_lines', 'stock_requests', 'stocktake_lines', 'stocktakes', 'stock_permit_lines', 'stock_permits'] as $table) {
            $db->table($table)->truncate();
        }

        $db->query('SET FOREIGN_KEY_CHECKS = 1');
        $this->removeInsertedEntities();

        parent::tearDown();
    }

    private function hasTable(string $table): bool
    {
        return $this->accountingDb()->query('SELECT COUNT(*) AS found FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ?', [$this->accountingDb()->prefixTable($table)])->getRow()->found > 0;
    }

    private function permit(string $type = 'add', ?int $toLocation = null, string $status = 'posted'): array
    {
        return ['company_id' => 1, 'branch_id' => 1, 'permit_type' => $type, 'permit_date' => date('Y-m-d'), 'location_id' => 1, 'to_location_id' => $toLocation, 'status' => $status];
    }

    private function refused(callable $insert): void
    {
        try {
            $insert();
            $this->fail('The insert should have been refused.');
        } catch (DatabaseException) {
            $this->addToAssertionCount(1);
        }
    }

    public function testUp_CreatesTheSixTablesAndRegistersTheModuleAndTheFivePermissionsWithoutGrantingThem(): void
    {
        $db = $this->accountingDb();

        foreach (self::TABLES as $table) {
            $this->assertTrue($this->hasTable($table), $table);
        }

        $this->assertSame(['stock_documents'], array_column($db->table('modules')->where('module_id', 'stock_documents')->get()->getResultArray(), 'module_id'));
        $this->assertSame('module_stock_documents', $db->table('modules')->where('module_id', 'stock_documents')->get()->getRow()->name_lang_key);

        $registered = array_column($db->table('permissions')->whereIn('permission_id', self::PERMISSIONS)->orderBy('permission_id')->get()->getResultArray(), 'permission_id');

        $this->assertSame(array_values(array_unique([...self::PERMISSIONS])), array_values(array_intersect(self::PERMISSIONS, $registered)));
        $this->assertCount(6, $registered);
        $this->assertSame(['stock_documents'], array_values(array_unique(array_column($db->table('permissions')->whereIn('permission_id', self::PERMISSIONS)->get()->getResultArray(), 'module_id'))));
        $this->assertSame(0, $db->table('grants')->whereIn('permission_id', self::PERMISSIONS)->countAllResults(), 'nothing is granted to anyone');
    }

    public function testUp_RunsAgainWithoutHarmAndKeepsWhatIsThere(): void
    {
        $db = $this->accountingDb();

        $db->table('stock_permits')->insert($this->permit());

        (new StockDocuments())->up();
        (new StockDocuments())->up();

        $this->assertSame(1, $this->countRows('stock_permits'));
        $this->assertSame(6, $db->table('permissions')->whereIn('permission_id', self::PERMISSIONS)->countAllResults());
        $this->assertSame(1, $db->table('modules')->where('module_id', 'stock_documents')->countAllResults());
    }

    public function testDown_RemovesTheTablesTheModuleAndThePermissionsAndRunsTwiceWithoutHarm(): void
    {
        (new StockDocuments())->down();
        (new StockDocuments())->down();

        foreach (self::TABLES as $table) {
            $this->assertFalse($this->hasTable($table), $table);
        }

        $this->assertSame(0, $this->accountingDb()->table('permissions')->whereIn('permission_id', self::PERMISSIONS)->countAllResults());
        $this->assertSame(0, $this->accountingDb()->table('modules')->where('module_id', 'stock_documents')->countAllResults());
    }

    public function testPermits_AcceptTheThreeKindsAndRefuseAnythingElse(): void
    {
        $db     = $this->accountingDb();
        $second = $this->insertStockLocation('Second');

        $db->table('stock_permits')->insert($this->permit('add'));
        $db->table('stock_permits')->insert($this->permit('issue'));
        $db->table('stock_permits')->insert($this->permit('transfer', $second));

        $this->assertSame(3, $this->countRows('stock_permits'));

        $this->refused(fn () => $db->table('stock_permits')->insert($this->permit('swap')));
        $this->refused(fn () => $db->table('stock_permits')->insert($this->permit('add', 1, 'posted')), 'only a transfer has a second location');
        $this->refused(fn () => $db->table('stock_permits')->insert($this->permit('transfer', null)));
        $this->refused(fn () => $db->table('stock_permits')->insert($this->permit('add', null, 'deleted')));
    }

    public function testPermitLines_RefuseAQuantityOfZeroAndAnUnknownItemAndAPermitThatIsNotThere(): void
    {
        $db = $this->accountingDb();

        $db->table('stock_permits')->insert($this->permit());

        $permitId = (int) $db->insertID();
        $itemId   = (int) $db->table('items')->select('item_id')->limit(1)->get()->getRow()->item_id;

        $db->table('stock_permit_lines')->insert(['permit_id' => $permitId, 'line_no' => 1, 'item_id' => $itemId, 'quantity' => '1.000', 'unit_cost' => '0']);

        $this->refused(fn () => $db->table('stock_permit_lines')->insert(['permit_id' => $permitId, 'line_no' => 2, 'item_id' => $itemId, 'quantity' => '0.000', 'unit_cost' => '0']));
        $this->refused(fn () => $db->table('stock_permit_lines')->insert(['permit_id' => $permitId, 'line_no' => 3, 'item_id' => $itemId, 'quantity' => '1.000', 'unit_cost' => '-1']));
        $this->refused(fn () => $db->table('stock_permit_lines')->insert(['permit_id' => $permitId, 'line_no' => 4, 'item_id' => 999999, 'quantity' => '1.000', 'unit_cost' => '0']));
        $this->refused(fn () => $db->table('stock_permit_lines')->insert(['permit_id' => 999999, 'line_no' => 1, 'item_id' => $itemId, 'quantity' => '1.000', 'unit_cost' => '0']));
        $this->refused(fn () => $db->table('stock_permit_lines')->insert(['permit_id' => $permitId, 'line_no' => 1, 'item_id' => $itemId, 'quantity' => '2.000', 'unit_cost' => '0']), 'a line number is used once');
    }

    public function testStocktakes_KeepOneSlotForALocationAndADayWhileTheyStandAndFreeItWhenCancelled(): void
    {
        $db   = $this->accountingDb();
        $base = ['company_id' => 1, 'branch_id' => 1, 'location_id' => 1, 'count_date' => date('Y-m-d')];

        $db->table('stocktakes')->insert([...$base, 'slot' => '1|' . date('Y-m-d')]);

        $this->refused(fn () => $db->table('stocktakes')->insert([...$base, 'slot' => '1|' . date('Y-m-d')]));

        $db->table('stocktakes')->insert([...$base, 'status' => 'cancelled', 'slot' => null]);
        $db->table('stocktakes')->insert([...$base, 'status' => 'cancelled', 'slot' => null]);

        $this->assertSame(3, $this->countRows('stocktakes'), 'any number of cancelled stocktakes have no slot');
        $this->refused(fn () => $db->table('stocktakes')->insert([...$base, 'status' => 'settled', 'slot' => '1|2020-01-01']));
    }

    public function testStocktakeLines_RefuseANegativeCountAndAllowAnUncountedLine(): void
    {
        $db = $this->accountingDb();

        $db->table('stocktakes')->insert(['company_id' => 1, 'branch_id' => 1, 'location_id' => 1, 'count_date' => date('Y-m-d'), 'slot' => '1|' . date('Y-m-d')]);

        $stocktakeId = (int) $db->insertID();
        $itemId      = (int) $db->table('items')->select('item_id')->limit(1)->get()->getRow()->item_id;

        $db->table('stocktake_lines')->insert(['stocktake_id' => $stocktakeId, 'item_id' => $itemId, 'system_quantity' => '5.000']);

        $this->assertNull($db->table('stocktake_lines')->where('stocktake_id', $stocktakeId)->get()->getRow()->counted_quantity);
        $this->assertSame(1, $db->table('stocktake_lines')->where('stocktake_id', $stocktakeId)->update(['counted_quantity' => '0.000']));
        $this->refused(fn () => $db->table('stocktake_lines')->where('stocktake_id', $stocktakeId)->update(['counted_quantity' => '-1.000']));
    }

    public function testRequests_AcceptTheFourStatesAndRefuseTheSameLocationTwice(): void
    {
        $db     = $this->accountingDb();
        $second = $this->insertStockLocation('Second');
        $base   = ['company_id' => 1, 'branch_id' => 1, 'from_branch_id' => 1, 'from_location_id' => 1, 'to_location_id' => $second, 'request_date' => date('Y-m-d')];

        foreach (['requested', 'approved', 'fulfilled', 'cancelled'] as $status) {
            $db->table('stock_requests')->insert([...$base, 'status' => $status]);
        }

        $this->assertSame(4, $this->countRows('stock_requests'));
        $this->refused(fn () => $db->table('stock_requests')->insert([...$base, 'status' => 'shipped']));
        $this->refused(fn () => $db->table('stock_requests')->insert([...$base, 'to_location_id' => 1]));
    }

    public function testRequestLines_RefuseAQuantityOfZero(): void
    {
        $db     = $this->accountingDb();
        $second = $this->insertStockLocation('Second');

        $db->table('stock_requests')->insert(['company_id' => 1, 'branch_id' => 1, 'from_branch_id' => 1, 'from_location_id' => 1, 'to_location_id' => $second, 'request_date' => date('Y-m-d')]);

        $requestId = (int) $db->insertID();
        $itemId    = (int) $db->table('items')->select('item_id')->limit(1)->get()->getRow()->item_id;

        $db->table('stock_request_lines')->insert(['request_id' => $requestId, 'line_no' => 1, 'item_id' => $itemId, 'quantity' => '1.000']);

        $this->refused(fn () => $db->table('stock_request_lines')->insert(['request_id' => $requestId, 'line_no' => 2, 'item_id' => $itemId, 'quantity' => '0.000']));
    }

    public function testRows_CannotBeDeletedWhileTheyAreReferred(): void
    {
        $db = $this->accountingDb();

        $db->table('stock_permits')->insert($this->permit());

        $permitId = (int) $db->insertID();
        $itemId   = (int) $db->table('items')->select('item_id')->limit(1)->get()->getRow()->item_id;

        $db->table('stock_permit_lines')->insert(['permit_id' => $permitId, 'line_no' => 1, 'item_id' => $itemId, 'quantity' => '1.000', 'unit_cost' => '0']);

        $this->refused(fn () => $db->table('stock_permits')->where('permit_id', $permitId)->delete());
    }
}
