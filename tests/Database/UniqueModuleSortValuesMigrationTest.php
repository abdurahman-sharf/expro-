<?php

namespace Tests\Database;

use App\Database\Migrations\UniqueModuleSortValues;
use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\DatabaseTestTrait;
use Config\Database;
use Tests\Support\AccountingFixtureTrait;

/**
 * @internal
 */
final class UniqueModuleSortValuesMigrationTest extends CIUnitTestCase
{
    use DatabaseTestTrait;
    use AccountingFixtureTrait;

    private const OLDER = ['price_lists' => 132, 'accounting_debit_notes' => 134, 'stock_documents' => 140, 'recurring_invoices' => 141];
    private const MOVED = ['accounting_currencies' => 133, 'custom_fields' => 135, 'customer_orders' => 143, 'stock_units' => 144];

    protected $migrate     = true;
    protected $migrateOnce = true;
    protected $refresh     = false;
    protected $namespace;

    public static function setUpBeforeClass(): void
    {
        $seeder = Database::seeder('tests');
        $seeder->call('TestDatabaseBootstrapSeeder');
    }

    protected function setUp(): void
    {
        parent::setUp();

        (new UniqueModuleSortValues())->up();
    }

    protected function tearDown(): void
    {
        (new UniqueModuleSortValues())->up();
        $this->accountingDb()->table('modules')->where('module_id', 'zz_sort_probe')->delete();

        parent::tearDown();
    }

    /**
     * @return array<string, int>
     */
    private function sorts(): array
    {
        $rows = $this->accountingDb()->table('modules')->select('module_id, sort')->orderBy('module_id')->get()->getResultArray();

        return array_map('intval', array_column($rows, 'sort', 'module_id'));
    }

    public function testUp_GivesEachNewModuleASortValueNoOtherModuleHolds(): void
    {
        $sorts = $this->sorts();

        foreach (self::MOVED as $moduleId => $sort) {
            $this->assertSame($sort, $sorts[$moduleId], $moduleId);
            $this->assertSame([$moduleId], array_keys($sorts, $sort, true), $moduleId . ' still shares its sort value');
        }
    }

    public function testUp_LeavesTheOlderModuleOfEachPairWhereItWas(): void
    {
        $sorts = $this->sorts();

        foreach (self::OLDER as $moduleId => $sort) {
            $this->assertSame($sort, $sorts[$moduleId], $moduleId);
        }
    }

    public function testUp_LeavesNoSortValueSharedExceptTheTwoOlderAccountingModules(): void
    {
        $shared = [];

        foreach (array_count_values($this->sorts()) as $sort => $count) {
            if ($count > 1) {
                $shared[] = $sort;
            }
        }

        $this->assertSame([123], $shared, 'A module shares its display position with another one');
    }

    public function testUp_ChangesNoOtherModuleAndIsSafeToRunAgain(): void
    {
        $before = $this->sorts();

        (new UniqueModuleSortValues())->up();
        (new UniqueModuleSortValues())->up();

        $this->assertSame($before, $this->sorts());
    }

    public function testUp_LeavesAModuleWhoseNewValueAnotherModuleHoldsWhereItIs(): void
    {
        $migration = new UniqueModuleSortValues();

        $migration->down();
        $this->accountingDb()->table('modules')->insert(['module_id' => 'zz_sort_probe', 'name_lang_key' => 'Module.zz_sort_probe', 'desc_lang_key' => 'Module.zz_sort_probe_desc', 'sort' => 133]);
        $migration->up();

        $sorts = $this->sorts();

        $this->assertSame(132, $sorts['accounting_currencies']);
        $this->assertSame(135, $sorts['custom_fields']);
    }

    public function testDown_PutsTheMovedModulesBackOnTheValuesTheyHad(): void
    {
        $migration = new UniqueModuleSortValues();

        $migration->down();

        $sorts = $this->sorts();

        $this->assertSame(132, $sorts['accounting_currencies']);
        $this->assertSame(134, $sorts['custom_fields']);
        $this->assertSame(140, $sorts['customer_orders']);
        $this->assertSame(141, $sorts['stock_units']);
    }
}
