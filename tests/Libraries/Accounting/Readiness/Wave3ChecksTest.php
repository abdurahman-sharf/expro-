<?php

namespace Tests\Libraries\Accounting\Readiness;

use App\Libraries\Accounting\AccountingGuard;
use App\Libraries\Accounting\Readiness\CheckResult;
use App\Libraries\Accounting\Readiness\CheckStatus;
use App\Libraries\Accounting\Readiness\MigrationChecks;
use App\Libraries\Accounting\Readiness\Wave3Checks;
use App\Libraries\Accounting\SupplierAdvances;
use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\DatabaseTestTrait;
use Config\Database;
use Tests\Support\AccountingFixtureTrait;
use Tests\Support\DatabaseFingerprintTrait;
use Tests\Support\EmployeeFixtureTrait;
use Tests\Support\ItemFixtureTrait;
use Tests\Support\PurchaseAccountingFixtureTrait;
use Tests\Support\SaleAccountingFixtureTrait;

/**
 * The two data facts that the reports of WP9 and WP13 left to a query by hand: the document of a return of
 * sale, and the date of the opening balances of a supplier. Each is built from the real documents and the
 * columns are then moved the way an old database would hold them.
 */
final class Wave3ChecksTest extends CIUnitTestCase
{
    use DatabaseTestTrait;
    use AccountingFixtureTrait;
    use EmployeeFixtureTrait;
    use ItemFixtureTrait;
    use SaleAccountingFixtureTrait;
    use PurchaseAccountingFixtureTrait;
    use DatabaseFingerprintTrait;

    protected $migrate     = true;
    protected $migrateOnce = true;
    protected $refresh     = false;
    protected $namespace   = null;

    private Wave3Checks $checks;
    private SupplierAdvances $advances;
    private int $clerk;

    public static function setUpBeforeClass(): void
    {
        $seeder = Database::seeder('tests');
        $seeder->call('TestDatabaseBootstrapSeeder');
    }

    protected function setUp(): void
    {
        parent::setUp();

        $this->startBuying();

        $db             = $this->accountingDb();
        $this->checks   = new Wave3Checks($db, new MigrationChecks($db));
        $this->advances = new SupplierAdvances();
        $this->clerk    = $this->employeeWithGrants([AccountingGuard::PAYABLES_MANAGE]);
    }

    protected function tearDown(): void
    {
        $db = $this->accountingDb();

        if (! $db->tableExists('ospos_supplier_advances', false) && $db->tableExists('ospos_supplier_advances_hold', false)) {
            $db->query('RENAME TABLE ospos_supplier_advances_hold TO ospos_supplier_advances');
        }

        if (! $db->query('SHOW COLUMNS FROM ospos_sales LIKE ?', ['return_document'])->getNumRows() && $db->query('SHOW COLUMNS FROM ospos_sales LIKE ?', ['return_document_hold'])->getNumRows()) {
            $db->query('ALTER TABLE ospos_sales RENAME COLUMN return_document_hold TO return_document');
        }

        $db->query('SET FOREIGN_KEY_CHECKS = 0');
        $db->table('supplier_credit_applications')->truncate();
        $db->table('supplier_advances')->truncate();
        $db->table('receivings')->where('employee_id', $this->clerk)->delete();
        $db->query('SET FOREIGN_KEY_CHECKS = 1');

        $this->stopBuying();

        parent::tearDown();
    }

    private function check(string $id): CheckResult
    {
        foreach ($this->checks->run() as $result) {
            if ($result->id === $id) {
                return $result;
            }
        }

        $this->fail("No check {$id}.");
    }

    private function tomorrow(): string
    {
        return date('Y-m-d', strtotime('+1 day'));
    }

    public function testRun_GivesTheTwoChecksWithTheirReferences(): void
    {
        $results = $this->checks->run();

        $this->assertSame(['return_document', 'supplier_opening_dates'], array_map(static fn (CheckResult $result): string => $result->id, $results));
        $this->assertSame(['WP9-RETURN-DOCUMENT', 'WP13-SUPPLIER-OPENING'], array_map(static fn (CheckResult $result): string => $result->ref, $results));
    }

    public function testReturnDocument_PassesWhenEveryReturnIsACreditNote(): void
    {
        $invoiceId = $this->invoiceOf('100.00');
        $this->creditNoteOf($invoiceId, '30.00');

        $result = $this->check('return_document');

        $this->assertSame(CheckStatus::Pass, $result->status);
        $this->assertSame(0, $result->data['with_document']);
    }

    public function testReturnDocument_PassesOnADatabaseWithNoReturn(): void
    {
        $this->assertSame(CheckStatus::Pass, $this->check('return_document')->status);
    }

    public function testReturnDocument_ReviewsARefundInvoiceIssuedSinceTheMigration(): void
    {
        $invoiceId = $this->invoiceOf('100.00');
        $refundId  = $this->refundInvoiceOf($invoiceId, '30.00');

        $result = $this->check('return_document');

        $this->assertSame(CheckStatus::Review, $result->status);
        $this->assertSame(1, $result->data['with_document']);
        $this->assertSame(1, $result->data['refund_invoices']);
        $this->assertSame(0, $result->data['dated_before_the_migration']);
        $this->assertStringContainsString("sale #{$refundId}, return of sale #{$invoiceId}", $result->findings[0]);
    }

    public function testReturnDocument_NamesTheRefundInvoicesDatedBeforeTheMigration(): void
    {
        $invoiceId = $this->invoiceOf('100.00');
        $refundId  = $this->refundInvoiceOf($invoiceId, '30.00');
        $this->accountingDb()->table('sales')->where('sale_id', $refundId)->update(['sale_time' => '2020-01-05 10:00:00']);

        $result = $this->check('return_document');

        $this->assertSame(CheckStatus::Review, $result->status);
        $this->assertSame(1, $result->data['dated_before_the_migration']);
        $this->assertStringContainsString('1 of them are dated before the migration RefundInvoice ran', $result->findings[0]);
    }

    public function testReturnDocument_FailsForASaleThatIsNotAReturnButCarriesADocument(): void
    {
        $invoiceId = $this->invoiceOf('100.00');
        $this->accountingDb()->table('sales')->where('sale_id', $invoiceId)->update(['return_document' => 'refund_invoice']);

        $result = $this->check('return_document');

        $this->assertSame(CheckStatus::Fail, $result->status);
        $this->assertSame(1, $result->data['not_returns']);
        $this->assertStringContainsString("sale #{$invoiceId} (type " . SALE_TYPE_INVOICE . ') carries the document refund_invoice', $result->findings[0]);
    }

    public function testReturnDocument_IsSkippedBeforeTheMigrationOfTheRefundInvoice(): void
    {
        $this->accountingDb()->query('ALTER TABLE ospos_sales RENAME COLUMN return_document TO return_document_hold');

        $result = $this->check('return_document');

        $this->assertSame(CheckStatus::Skipped, $result->status);
        $this->assertSame(['sales.return_document'], $result->data['missing']);
    }

    public function testSupplierOpening_PassesForOpeningBalancesDatedUpToToday(): void
    {
        $this->advances->openingBalance($this->supplierId, SupplierAdvances::WE_OWE, '1000.00', date('Y-m-d'), '', $this->clerk);
        $this->advances->openingBalance($this->supplierId, SupplierAdvances::THEY_OWE, '250.00', date('Y-m-d'), '', $this->clerk);
        $this->advances->openingBalance($this->supplierId, SupplierAdvances::THEY_OWE, '75.00', '2026-01-01', '', $this->clerk);

        $result = $this->check('supplier_opening_dates');

        $this->assertSame(CheckStatus::Pass, $result->status);
        $this->assertSame(0, $result->data['owed_to_supplier']);
        $this->assertSame(0, $result->data['owed_by_supplier']);
    }

    public function testSupplierOpening_ReviewsAnOpeningOwedToTheSupplierDatedAfterToday(): void
    {
        $receivingId = $this->advances->openingBalance($this->supplierId, SupplierAdvances::WE_OWE, '1000.00', '2026-01-01', '', $this->clerk);
        $this->accountingDb()->table('receivings')->where('receiving_id', $receivingId)->update(['receiving_time' => $this->tomorrow() . ' 00:00:00']);

        $result = $this->check('supplier_opening_dates');

        $this->assertSame(CheckStatus::Review, $result->status);
        $this->assertSame(1, $result->data['owed_to_supplier']);
        $this->assertSame(0, $result->data['owed_by_supplier']);
        $this->assertSame("purchase #{$receivingId}: opening balance owed to supplier #{$this->supplierId} dated " . $this->tomorrow(), $result->findings[0]);
    }

    public function testSupplierOpening_ReviewsAnOpeningOwedByTheSupplierDatedAfterToday(): void
    {
        $advanceId = $this->advances->openingBalance($this->supplierId, SupplierAdvances::THEY_OWE, '250.00', '2026-01-01', '', $this->clerk);
        $this->accountingDb()->table('supplier_advances')->where('advance_id', $advanceId)->update(['advance_date' => $this->tomorrow()]);

        $result = $this->check('supplier_opening_dates');

        $this->assertSame(CheckStatus::Review, $result->status);
        $this->assertSame(0, $result->data['owed_to_supplier']);
        $this->assertSame(1, $result->data['owed_by_supplier']);
        $this->assertSame("advance #{$advanceId}: opening balance owed by supplier #{$this->supplierId} dated " . $this->tomorrow(), $result->findings[0]);
    }

    public function testSupplierOpening_LeavesAnAdvancePaymentDatedAfterTodayAlone(): void
    {
        $this->accountingDb()->table('supplier_advances')->insert([
            'company_id'   => 1,
            'branch_id'    => 1,
            'supplier_id'  => $this->supplierId,
            'kind'         => 'payment',
            'amount'       => '10.00',
            'advance_date' => $this->tomorrow(),
        ]);

        $this->assertSame(CheckStatus::Pass, $this->check('supplier_opening_dates')->status);
    }

    public function testSupplierOpening_LeavesAPurchaseDatedAfterTodayAloneWhenItIsNotAnOpeningBalance(): void
    {
        $this->accountingDb()->table('receivings')->insert([
            'receiving_time' => $this->tomorrow() . ' 00:00:00',
            'supplier_id'    => $this->supplierId,
            'employee_id'    => $this->clerk,
            'comment'        => '',
            'receiving_type' => 'receive',
            'branch_id'      => 1,
        ]);

        $this->assertSame(CheckStatus::Pass, $this->check('supplier_opening_dates')->status);
    }

    public function testSupplierOpening_IsSkippedBeforeTheMigrationOfTheSupplierAdvances(): void
    {
        $this->accountingDb()->query('RENAME TABLE ospos_supplier_advances TO ospos_supplier_advances_hold');

        $result = $this->check('supplier_opening_dates');

        $this->assertSame(CheckStatus::Skipped, $result->status);
        $this->assertSame(['supplier_advances.kind', 'supplier_advances.advance_date'], $result->data['missing']);
    }

    public function testRun_ChangesNoRow(): void
    {
        $invoiceId = $this->invoiceOf('100.00');
        $this->refundInvoiceOf($invoiceId, '30.00');
        $receivingId = $this->advances->openingBalance($this->supplierId, SupplierAdvances::WE_OWE, '1000.00', '2026-01-01', '', $this->clerk);
        $this->accountingDb()->table('receivings')->where('receiving_id', $receivingId)->update(['receiving_time' => $this->tomorrow() . ' 00:00:00']);
        $before = $this->databaseFingerprint();

        $this->checks->run();

        $this->assertDatabaseUnchanged($before);
    }
}
