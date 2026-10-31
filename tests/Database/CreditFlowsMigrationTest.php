<?php

namespace Tests\Database;

use App\Database\Migrations\CreditFlows;
use CodeIgniter\Database\Exceptions\DatabaseException;
use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\DatabaseTestTrait;
use Config\Database;
use Tests\Support\AccountingFixtureTrait;
use Tests\Support\EmployeeFixtureTrait;
use Tests\Support\ItemFixtureTrait;
use Tests\Support\PurchaseAccountingFixtureTrait;
use Tests\Support\SaleAccountingFixtureTrait;

class CreditFlowsMigrationTest extends CIUnitTestCase
{
    use DatabaseTestTrait;
    use AccountingFixtureTrait;
    use EmployeeFixtureTrait;
    use ItemFixtureTrait;
    use SaleAccountingFixtureTrait;
    use PurchaseAccountingFixtureTrait;

    private const PERMISSIONS = [
        'acc_receivables_manage' => 'accounting_receivables',
        'acc_receivables_refund' => 'accounting_receivables',
        'acc_payables_refund'    => 'accounting_payables',
        'sales_return_override'  => 'sales',
    ];

    private const TABLES = ['customer_advances', 'customer_credit_refunds', 'customer_credit_refund_sources', 'supplier_credit_refunds', 'supplier_credit_refund_sources', 'sale_return_overrides'];

    protected $migrate     = true;
    protected $migrateOnce = true;
    protected $refresh     = false;
    protected $namespace   = null;

    public static function setUpBeforeClass(): void
    {
        $seeder = Database::seeder('tests');
        $seeder->call('TestDatabaseBootstrapSeeder');
    }

    protected function setUp(): void
    {
        parent::setUp();

        $this->startBuying();
    }

    protected function tearDown(): void
    {
        $this->stopBuying();

        (new CreditFlows())->up();

        parent::tearDown();
    }

    private function hasTable(string $table): bool
    {
        return $this->accountingDb()->query(
            'SELECT COUNT(*) AS found FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ?',
            [$this->accountingDb()->prefixTable($table)],
        )->getRow()->found > 0;
    }

    private function advance(string $amount = '10.00', string $status = 'completed', ?string $key = null): void
    {
        $this->accountingDb()->table('customer_advances')->insert(['company_id' => 1, 'branch_id' => 1, 'customer_id' => $this->customerId, 'amount' => $amount, 'advance_date' => '2026-10-25', 'status' => $status, 'request_key' => $key]);
    }

    private function refund(string $party, string $amount = '10.00', string $status = 'completed', ?string $key = null): int
    {
        $partyId = $party === 'customer' ? $this->customerId : $this->supplierId;

        $this->accountingDb()->table($party . '_credit_refunds')->insert(['company_id' => 1, 'branch_id' => 1, $party . '_id' => $partyId, 'amount' => $amount, 'refund_date' => '2026-10-25', 'status' => $status, 'request_key' => $key]);

        return (int) $this->accountingDb()->insertID();
    }

    public function testUp_CreatesEveryTableAndRegistersEachPermissionUnderItsModule(): void
    {
        foreach (self::TABLES as $table) {
            $this->assertTrue($this->hasTable($table), $table);
        }

        foreach (self::PERMISSIONS as $permission => $module) {
            $row = $this->accountingDb()->table('permissions')->where('permission_id', $permission)->get()->getRow();

            $this->assertNotNull($row, $permission);
            $this->assertSame($module, $row->module_id, $permission);
        }
    }

    public function testUp_GrantsTheNewPermissionsToNoOne(): void
    {
        foreach (array_keys(self::PERMISSIONS) as $permission) {
            $this->assertSame(0, $this->accountingDb()->table('grants')->where('permission_id', $permission)->countAllResults(), $permission);
        }
    }

    public function testUp_AcceptsACompletedAndACancelledDocumentAndNothingElse(): void
    {
        $this->advance('10.00', 'completed');
        $this->advance('20.00', 'cancelled');
        $this->refund('customer', '5.00', 'completed');
        $this->refund('supplier', '5.00', 'cancelled');

        $this->assertSame(2, $this->countRows('customer_advances'));
        $this->assertSame(1, $this->countRows('customer_credit_refunds'));
        $this->assertSame(1, $this->countRows('supplier_credit_refunds'));

        foreach ([fn () => $this->advance('10.00', 'paid'), fn () => $this->refund('customer', '10.00', 'paid'), fn () => $this->refund('supplier', '10.00', 'paid')] as $insert) {
            try {
                $insert();
                $this->fail('A row with a status that is neither was accepted.');
            } catch (DatabaseException) {
                $this->addToAssertionCount(1);
            }
        }
    }

    public function testUp_RefusesAnAmountOfNothingAndANegativeOneInEveryTable(): void
    {
        foreach (['0.00', '-5.00'] as $amount) {
            foreach ([fn () => $this->advance($amount), fn () => $this->refund('customer', $amount), fn () => $this->refund('supplier', $amount)] as $insert) {
                try {
                    $insert();
                    $this->fail('The amount ' . $amount . ' was accepted.');
                } catch (DatabaseException) {
                    $this->addToAssertionCount(1);
                }
            }
        }

        $refundId = $this->refund('customer', '10.00');

        foreach (['0.00', '-1.00'] as $amount) {
            try {
                $this->accountingDb()->table('customer_credit_refund_sources')->insert(['refund_id' => $refundId, 'source_type' => 'customer_advance', 'source_id' => 1, 'amount' => $amount]);
                $this->fail('A source of ' . $amount . ' was accepted.');
            } catch (DatabaseException) {
                $this->addToAssertionCount(1);
            }
        }

        $this->assertSame(0, $this->countRows('customer_advances'));
        $this->assertSame(1, $this->countRows('customer_credit_refunds'));
    }

    public function testUp_RefusesToDeleteACustomerOrASupplierThatHasADocument(): void
    {
        $this->advance();
        $this->refund('supplier');

        foreach ([['customers', $this->customerId], ['suppliers', $this->supplierId]] as [$table, $personId]) {
            try {
                $this->accountingDb()->table($table)->where('person_id', $personId)->delete();
                $this->fail('A ' . $table . ' row with a document was deleted.');
            } catch (DatabaseException) {
                $this->addToAssertionCount(1);
            }
        }
    }

    public function testUp_RefusesToDeleteARefundThatHasSources(): void
    {
        $refundId = $this->refund('customer');

        $this->accountingDb()->table('customer_credit_refund_sources')->insert(['refund_id' => $refundId, 'source_type' => 'customer_advance', 'source_id' => 1, 'amount' => '10.00']);

        $this->expectException(DatabaseException::class);

        $this->accountingDb()->table('customer_credit_refunds')->where('refund_id', $refundId)->delete();
    }

    public function testUp_KeepsOneDocumentPerKeyAndLetsAnyNumberOfDocumentsHaveNone(): void
    {
        $this->advance('10.00', 'completed', 'form-1');
        $this->advance('10.00', 'completed', null);
        $this->advance('10.00', 'completed', null);
        $this->refund('customer', '10.00', 'completed', 'form-1');
        $this->refund('supplier', '10.00', 'completed', 'form-1');

        $this->assertSame(3, $this->countRows('customer_advances'));

        foreach ([fn () => $this->advance('10.00', 'completed', 'form-1'), fn () => $this->refund('customer', '10.00', 'completed', 'form-1'), fn () => $this->refund('supplier', '10.00', 'completed', 'form-1')] as $insert) {
            try {
                $insert();
                $this->fail('A second document with the same key was accepted.');
            } catch (DatabaseException) {
                $this->addToAssertionCount(1);
            }
        }
    }

    public function testUp_RefusesAReturnOverrideWithNoReasonAndOneOfASaleThatDoesNotExist(): void
    {
        $service = $this->item('0', false);
        $saleId  = $this->save([$this->line($service, 0, '-1', '10.00')], [], [], SALE_TYPE_RETURN);

        foreach ([[$saleId, ''], [999999, 'reason']] as [$id, $reason]) {
            try {
                $this->accountingDb()->table('sale_return_overrides')->insert(['sale_id' => $id, 'branch_id' => 1, 'employee_id' => $this->employeeId, 'reason' => $reason]);
                $this->fail('The override was accepted.');
            } catch (DatabaseException) {
                $this->addToAssertionCount(1);
            }
        }

        $this->accountingDb()->table('sale_return_overrides')->insert(['sale_id' => $saleId, 'branch_id' => 1, 'employee_id' => $this->employeeId, 'reason' => 'lost the receipt']);

        $this->assertSame(1, $this->countRows('sale_return_overrides'));

        try {
            $this->accountingDb()->table('sale_return_overrides')->insert(['sale_id' => $saleId, 'branch_id' => 1, 'employee_id' => $this->employeeId, 'reason' => 'twice']);
            $this->fail('A second override of the same sale was accepted.');
        } catch (DatabaseException) {
            $this->addToAssertionCount(1);
        }
    }

    public function testUp_IsSafeToRunTwice(): void
    {
        $permissions = $this->countRows('permissions');

        $this->advance();

        (new CreditFlows())->up();

        $this->assertSame($permissions, $this->countRows('permissions'));
        $this->assertSame(1, $this->countRows('customer_advances'));
    }

    public function testDown_TakesTheTablesThePermissionsAndTheirGrantsAway(): void
    {
        $employee  = $this->employeeWithGrants(array_keys(self::PERMISSIONS));
        $migration = new CreditFlows();

        try {
            $migration->down();

            foreach (self::TABLES as $table) {
                $this->assertFalse($this->hasTable($table), $table);
            }

            foreach (array_keys(self::PERMISSIONS) as $permission) {
                $this->assertSame(0, $this->accountingDb()->table('permissions')->where('permission_id', $permission)->countAllResults(), $permission);
                $this->assertSame(0, $this->accountingDb()->table('grants')->where(['person_id' => $employee, 'permission_id' => $permission])->countAllResults(), $permission);
            }
        } finally {
            $migration->up();
        }

        foreach (self::TABLES as $table) {
            $this->assertTrue($this->hasTable($table), $table);
        }
    }
}
