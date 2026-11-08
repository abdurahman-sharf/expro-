<?php

namespace Tests\Database;

use App\Database\Migrations\PurchaseCycleDocuments;
use App\Models\Employee;
use CodeIgniter\Database\Exceptions\DatabaseException;
use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\DatabaseTestTrait;
use Config\Database;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Support\AccountingFixtureTrait;
use Tests\Support\EmployeeFixtureTrait;
use Tests\Support\ItemFixtureTrait;

class PurchaseCycleDocumentsMigrationTest extends CIUnitTestCase
{
    use DatabaseTestTrait;
    use AccountingFixtureTrait;
    use EmployeeFixtureTrait;
    use ItemFixtureTrait;

    protected $migrate     = true;
    protected $migrateOnce = true;
    protected $refresh     = false;
    protected $namespace   = null;

    private const TABLES = ['purchase_requests', 'purchase_request_lines', 'quotation_requests', 'quotation_request_lines', 'supplier_quotations', 'supplier_quotation_lines', 'purchase_orders', 'purchase_order_lines', 'purchase_order_receivings', 'purchase_order_receiving_lines'];

    private const PERMISSIONS = [
        'purchase_requests'   => ['purchase_requests', 'pr_add', 'pr_edit', 'pr_delete', 'pr_view_all', 'pr_convert'],
        'quotation_requests'  => ['quotation_requests', 'qr_add', 'qr_edit', 'qr_delete', 'qr_view_all', 'qr_convert'],
        'supplier_quotations' => ['supplier_quotations', 'sq_add', 'sq_edit', 'sq_delete', 'sq_view_all', 'sq_convert'],
        'purchase_orders'     => ['purchase_orders', 'po_add', 'po_edit', 'po_delete', 'po_view_all', 'po_convert'],
    ];

    private ?int $supplierId = null;
    private ?int $itemId     = null;

    public static function setUpBeforeClass(): void
    {
        $seeder = Database::seeder('tests');
        $seeder->call('TestDatabaseBootstrapSeeder');
    }

    protected function tearDown(): void
    {
        (new PurchaseCycleDocuments())->up();

        $this->clearTables();
        $this->accountingDb()->table('receivings')->where('comment', 'migration-test')->delete();
        $this->removeInsertedEntities();

        parent::tearDown();
    }

    private function clearTables(): void
    {
        $db = $this->accountingDb();
        $db->query('SET FOREIGN_KEY_CHECKS = 0');

        foreach (array_reverse(self::TABLES) as $table) {
            $db->table($table)->truncate();
        }

        $db->query('SET FOREIGN_KEY_CHECKS = 1');
    }

    private function hasTable(string $table): bool
    {
        return $this->accountingDb()->query('SELECT COUNT(*) AS found FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ?', [$this->accountingDb()->prefixTable($table)])->getRow()->found > 0;
    }

    private function supplier(): int
    {
        return $this->supplierId ??= $this->insertSupplier();
    }

    private function item(): int
    {
        return $this->itemId ??= $this->createTestItem();
    }

    /**
     * @return array{0: string, 1: array<string, mixed>}
     */
    private function documentRow(string $table, string $status = 'open'): array
    {
        $row = ['company_id' => 1, 'branch_id' => 1, 'location_id' => 1, 'document_date' => '2026-10-20', 'status' => $status];

        if ($table !== 'purchase_requests') {
            $row['supplier_id'] = $this->supplier();
        }

        return [$table, $row];
    }

    private function document(string $table, array $override = []): int
    {
        [$table, $row] = $this->documentRow($table);

        $this->accountingDb()->table($table)->insert([...$row, ...$override]);

        return (int) $this->accountingDb()->insertID();
    }

    private function idColumn(string $table): string
    {
        return ['purchase_requests' => 'request_id', 'quotation_requests' => 'rfq_id', 'supplier_quotations' => 'quotation_id', 'purchase_orders' => 'order_id'][$table];
    }

    private function line(string $table, int $documentId, array $override = []): int
    {
        $lines = ['purchase_requests' => 'purchase_request_lines', 'quotation_requests' => 'quotation_request_lines', 'supplier_quotations' => 'supplier_quotation_lines', 'purchase_orders' => 'purchase_order_lines'][$table];

        $this->accountingDb()->table($lines)->insert([$this->idColumn($table) => $documentId, 'line_no' => 1, 'item_id' => $this->item(), 'quantity' => '1', 'unit_price' => '1', ...$override]);

        return (int) $this->accountingDb()->insertID();
    }

    private function assertRefused(callable $write, string $why): void
    {
        try {
            $write();
            $this->fail($why);
        } catch (DatabaseException) {
            $this->addToAssertionCount(1);
        }
    }

    /**
     * @return array<string, array{0: string}>
     */
    public static function documentTableProvider(): array
    {
        return ['purchase requests' => ['purchase_requests'], 'quotation requests' => ['quotation_requests'], 'supplier quotations' => ['supplier_quotations'], 'purchase orders' => ['purchase_orders']];
    }

    public function testUp_CreatesTheTablesAndRegistersTheFourModulesWithTheirPermissionsAndGrantsNothing(): void
    {
        foreach (self::TABLES as $table) {
            $this->assertTrue($this->hasTable($table), $table);
        }

        $db = $this->accountingDb();

        foreach (self::PERMISSIONS as $moduleId => $permissions) {
            $this->assertSame(1, $db->table('modules')->where('module_id', $moduleId)->countAllResults(), $moduleId);
            $this->assertSame('module_' . $moduleId, $db->table('modules')->where('module_id', $moduleId)->get()->getRow()->name_lang_key);
            $this->assertGreaterThan(0, (int) $db->table('modules')->where('module_id', $moduleId)->get()->getRow()->sort);
            $this->assertEqualsCanonicalizing($permissions, array_column($db->table('permissions')->select('permission_id')->where('module_id', $moduleId)->get()->getResultArray(), 'permission_id'), $moduleId);
            $this->assertSame(0, $db->table('grants')->whereIn('permission_id', $permissions)->countAllResults(), 'nothing is granted to anybody');
        }
    }

    public function testUp_IsSafeToRunAgainAndKeepsWhatIsThere(): void
    {
        $requestId = $this->document('purchase_requests');
        $this->line('purchase_requests', $requestId);

        (new PurchaseCycleDocuments())->up();
        (new PurchaseCycleDocuments())->up();

        $this->assertSame(1, $this->countRows('purchase_requests'));
        $this->assertSame(1, $this->countRows('purchase_request_lines'));
        $this->assertSame(4, $this->accountingDb()->table('modules')->whereIn('module_id', array_keys(self::PERMISSIONS))->countAllResults());
        $this->assertSame(24, $this->accountingDb()->table('permissions')->whereIn('module_id', array_keys(self::PERMISSIONS))->countAllResults());
    }

    public function testModules_NeverStartWithTheIdOfAnotherModuleAndNoOtherModuleStartsWithTheirs(): void
    {
        $others = array_column($this->accountingDb()->table('modules')->select('module_id')->whereNotIn('module_id', array_keys(self::PERMISSIONS))->get()->getResultArray(), 'module_id');

        foreach (array_keys(self::PERMISSIONS) as $module) {
            foreach ($others as $other) {
                $this->assertFalse(str_starts_with($module, (string) $other), "{$module} would pass the module check of {$other}");
                $this->assertFalse(str_starts_with((string) $other, $module), "{$other} would pass the module check of {$module}");
            }
        }
    }

    public function testModules_AnEmployeeWhoHoldsTheCyclePermissionsHasNoModuleOfAnotherAreaAndTheModuleNeedsItsOwnGrant(): void
    {
        $employee = model(Employee::class);
        $all      = array_merge(...array_values(self::PERMISSIONS));
        $holder   = $this->employeeWithGrants($all);
        $others   = array_column($this->accountingDb()->table('modules')->select('module_id')->whereNotIn('module_id', array_keys(self::PERMISSIONS))->get()->getResultArray(), 'module_id');

        foreach ($others as $other) {
            $this->assertFalse($employee->has_module_grant((string) $other, $holder), "the permissions of the cycle must not open {$other}");
        }

        foreach (self::PERMISSIONS as $module => $permissions) {
            $onlyActions = $this->employeeWithGrants(array_slice($permissions, 1));

            $this->assertFalse($employee->has_module_grant($module, $onlyActions), "{$module}: the sub-permissions alone do not open the module");
            $this->assertTrue($employee->has_module_grant($module, $this->employeeWithGrants([$module])), "{$module}: the grant of the module opens it");
            $this->assertTrue($employee->has_module_grant($module, $holder), $module);
        }
    }

    #[DataProvider('documentTableProvider')]
    public function testStatus_IsRefusedWhenItIsNoneOfTheFourTheCodeStores(string $table): void
    {
        foreach (['open', 'sent', 'approved', 'cancelled'] as $status) {
            $this->accountingDb()->table($table)->insert($this->documentRow($table, $status)[1]);
        }

        foreach (['converted', 'received', 'partly_received', 'draft', ''] as $status) {
            $this->assertRefused(fn () => $this->accountingDb()->table($table)->insert($this->documentRow($table, $status)[1]), "the status '{$status}' is not stored");
        }

        $this->assertSame(4, $this->countRows($table));
    }

    #[DataProvider('documentTableProvider')]
    public function testLines_RefuseAQuantityOfZeroOrLessANegativePriceAndTwoLinesOfTheSameNumber(string $table): void
    {
        $documentId = $this->document($table);

        $this->line($table, $documentId, ['quantity' => '0.001', 'unit_price' => '0']);

        $this->assertRefused(fn () => $this->line($table, $documentId, ['line_no' => 2, 'quantity' => '0']), 'a quantity of zero');
        $this->assertRefused(fn () => $this->line($table, $documentId, ['line_no' => 2, 'quantity' => '-1']), 'a negative quantity');
        $this->assertRefused(fn () => $this->line($table, $documentId, ['line_no' => 2, 'unit_price' => '-0.01']), 'a negative price');
        $this->assertRefused(fn () => $this->line($table, $documentId, ['line_no' => 1]), 'two lines with the number 1');
    }

    #[DataProvider('documentTableProvider')]
    public function testForeignKeys_RefuseWhatAnotherRowStillRefersTo(string $table): void
    {
        $documentId = $this->document($table);
        $this->line($table, $documentId);
        $db = $this->accountingDb();

        $this->assertRefused(fn () => $db->table($table)->where($this->idColumn($table), $documentId)->delete(), 'a document with lines is not deleted');
        $this->assertRefused(fn () => $db->table('items')->where('item_id', $this->item())->delete(), 'an item on a line is not deleted');
        $this->assertRefused(fn () => $this->document($table, ['location_id' => 999999]), 'a stock location that does not exist');
        $this->assertRefused(fn () => $this->document($table, ['branch_id' => 999999]), 'a branch that does not exist');
        $this->assertRefused(fn () => $this->document($table, ['company_id' => 999999]), 'a company that does not exist');

        if ($table !== 'purchase_requests') {
            $this->assertRefused(fn () => $this->document($table, ['supplier_id' => 999999]), 'a supplier that does not exist');
            $this->assertRefused(fn () => $db->table('suppliers')->where('person_id', $this->supplier())->delete(), 'a supplier of a document is not deleted');
        }
    }

    public function testSourceColumns_RefuseADocumentThatDoesNotExistAndKeepTheOneAnotherWasMadeFrom(): void
    {
        $db      = $this->accountingDb();
        $request = $this->document('purchase_requests');
        $rfq     = $this->document('quotation_requests', ['source_request_id' => $request]);
        $quote   = $this->document('supplier_quotations', ['source_rfq_id' => $rfq]);
        $order   = $this->document('purchase_orders', ['source_quotation_id' => $quote]);

        $this->assertRefused(fn () => $this->document('quotation_requests', ['source_request_id' => 999999]), 'a request that does not exist');
        $this->assertRefused(fn () => $this->document('supplier_quotations', ['source_rfq_id' => 999999]), 'a quotation request that does not exist');
        $this->assertRefused(fn () => $this->document('purchase_orders', ['source_quotation_id' => 999999]), 'a quotation that does not exist');
        $this->assertRefused(fn () => $db->table('purchase_requests')->where('request_id', $request)->delete(), 'a request something was made from');
        $this->assertRefused(fn () => $db->table('quotation_requests')->where('rfq_id', $rfq)->delete(), 'a quotation request something was made from');
        $this->assertRefused(fn () => $db->table('supplier_quotations')->where('quotation_id', $quote)->delete(), 'a quotation something was made from');
        $this->assertGreaterThan(0, $order);
    }

    public function testSupplierColumn_IsAbsentFromARequestOptionalOnAQuotationRequestAndRequiredOnTheOthers(): void
    {
        $db     = $this->accountingDb();
        $column = static fn (string $table) => $db->query('SELECT IS_NULLABLE FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ?', [$db->prefixTable($table), 'supplier_id'])->getRow();

        $this->assertNull($column('purchase_requests'));
        $this->assertSame('YES', $column('quotation_requests')->IS_NULLABLE);
        $this->assertSame('NO', $column('supplier_quotations')->IS_NULLABLE);
        $this->assertSame('NO', $column('purchase_orders')->IS_NULLABLE);
    }

    public function testReceivingLinks_GoWhenTheReceivingGoesAndRefuseAnOrderOrALineTheyStillReferTo(): void
    {
        $db       = $this->accountingDb();
        $employee = $this->createEmployee();
        $orderId  = $this->document('purchase_orders');
        $lineId   = $this->line('purchase_orders', $orderId);

        $db->table('receivings')->insert(['employee_id' => $employee, 'comment' => 'migration-test', 'receiving_type' => 'receive', 'branch_id' => 1, 'supplier_id' => $this->supplier()]);

        $receivingId = (int) $db->insertID();

        $db->table('purchase_order_receivings')->insert(['receiving_id' => $receivingId, 'order_id' => $orderId]);
        $db->table('purchase_order_receiving_lines')->insert(['receiving_id' => $receivingId, 'order_line_id' => $lineId, 'quantity' => '1']);

        $this->assertRefused(fn () => $db->table('purchase_order_receiving_lines')->insert(['receiving_id' => $receivingId, 'order_line_id' => $lineId, 'quantity' => '0']), 'a quantity of zero');
        $this->assertRefused(fn () => $db->table('purchase_order_receiving_lines')->insert(['receiving_id' => $receivingId, 'order_line_id' => 999999, 'quantity' => '1']), 'a line that does not exist');
        $this->assertRefused(fn () => $db->table('purchase_order_receivings')->insert(['receiving_id' => 999999, 'order_id' => $orderId]), 'a receiving that does not exist');
        $this->assertRefused(fn () => $db->table('purchase_order_lines')->where('line_id', $lineId)->delete(), 'a line something was received from');
        $this->assertRefused(fn () => $db->table('purchase_orders')->where('order_id', $orderId)->delete(), 'an order something was received from');

        $db->table('receivings')->where('receiving_id', $receivingId)->delete();

        $this->assertSame(0, $this->countRows('purchase_order_receivings'), 'the units go back to the order when the receiving is deleted');
        $this->assertSame(0, $this->countRows('purchase_order_receiving_lines'));
    }

    public function testDown_TakesTheTablesTheModulesTheirPermissionsAndTheGrantsAway(): void
    {
        $holder = $this->employeeWithGrants(array_merge(...array_values(self::PERMISSIONS)));

        $migration = new PurchaseCycleDocuments();

        try {
            $migration->down();

            foreach (self::TABLES as $table) {
                $this->assertFalse($this->hasTable($table), $table);
            }

            $this->assertSame(0, $this->accountingDb()->table('modules')->whereIn('module_id', array_keys(self::PERMISSIONS))->countAllResults());
            $this->assertSame(0, $this->accountingDb()->table('permissions')->whereIn('module_id', array_keys(self::PERMISSIONS))->countAllResults());
            $this->assertSame(0, $this->accountingDb()->table('grants')->where('person_id', $holder)->whereIn('permission_id', array_merge(...array_values(self::PERMISSIONS)))->countAllResults());
        } finally {
            $migration->up();
        }

        $this->assertTrue($this->hasTable('purchase_orders'));
    }
}
