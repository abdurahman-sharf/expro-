<?php

namespace Tests\Libraries\Accounting\Readiness;

use App\Libraries\Accounting\Readiness\CheckResult;
use App\Libraries\Accounting\Readiness\CheckStatus;
use App\Libraries\Accounting\Readiness\PurchaseCycleChecks;
use App\Libraries\PurchaseCycle\PurchaseDocumentKind;
use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\DatabaseTestTrait;
use Config\Database;
use Tests\Support\AccountingFixtureTrait;
use Tests\Support\EmployeeFixtureTrait;
use Tests\Support\ItemFixtureTrait;
use Tests\Support\PurchaseAccountingFixtureTrait;
use Tests\Support\PurchaseCycleFixtureTrait;
use Tests\Support\SaleAccountingFixtureTrait;

/**
 * The readiness check of the purchase cycle: a document whose stored status the code cannot leave fails it, documents that nobody can
 * change while the cycle is off and orders received beyond their lines are for review, and the check only reads.
 */
final class PurchaseCycleChecksTest extends CIUnitTestCase
{
    use DatabaseTestTrait;
    use AccountingFixtureTrait;
    use EmployeeFixtureTrait;
    use ItemFixtureTrait;
    use PurchaseAccountingFixtureTrait;
    use PurchaseCycleFixtureTrait;
    use SaleAccountingFixtureTrait;

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
        $this->startCycle();
    }

    protected function tearDown(): void
    {
        $this->restoreStatusChecks();
        $this->stopCycle();
        $this->stopBuying();

        parent::tearDown();
    }

    private function outcome(): CheckResult
    {
        $results = (new PurchaseCycleChecks($this->accountingDb()))->run();

        $this->assertCount(1, $results);
        $this->assertSame(['purchase_cycle', 'WP39-PURCHASE-CYCLE'], [$results[0]->id, $results[0]->ref]);

        return $results[0];
    }

    private function dropStatusCheck(PurchaseDocumentKind $kind): void
    {
        $table = $this->accountingDb()->prefixTable($kind->table());

        $this->accountingDb()->query("ALTER TABLE `{$table}` DROP CONSTRAINT `{$table}_status`");
    }

    private function restoreStatusChecks(): void
    {
        $this->clearCycle();

        $db = $this->accountingDb();

        foreach (PurchaseDocumentKind::cases() as $kind) {
            $table = $db->prefixTable($kind->table());
            $found = $db->query('SELECT COUNT(*) AS found FROM information_schema.TABLE_CONSTRAINTS WHERE CONSTRAINT_SCHEMA = DATABASE() AND TABLE_NAME = ? AND CONSTRAINT_NAME = ?', [$table, $table . '_status'])->getRow()->found;

            if ((int) $found === 0) {
                $db->query("ALTER TABLE `{$table}` ADD CONSTRAINT `{$table}_status` CHECK (`status` IN ('open', 'sent', 'approved', 'cancelled'))");
            }
        }
    }

    public function testRun_PassesWhenThereIsNoDocumentAndWhenEveryDocumentCanBeLeft(): void
    {
        $this->assertSame(CheckStatus::Pass, $this->outcome()->status);

        foreach (PurchaseDocumentKind::cases() as $kind) {
            $this->makeDocument($kind);
        }

        $this->documentsOf(PurchaseDocumentKind::Request)->convert($this->makeDocument(PurchaseDocumentKind::Request), $this->clerk);

        $result = $this->outcome();

        $this->assertSame(CheckStatus::Pass, $result->status);
        $this->assertSame(6, $result->data['documents']);
        $this->assertSame(0, $result->data['stuck']);
    }

    public function testRun_FailsForADocumentWhoseStoredStatusNothingMovesItOutOfAndNamesIt(): void
    {
        $documentId = $this->makeDocument(PurchaseDocumentKind::Order);

        $this->dropStatusCheck(PurchaseDocumentKind::Order);
        $this->accountingDb()->table('purchase_orders')->where('order_id', $documentId)->update(['status' => 'stuck']);

        $result = $this->outcome();

        $this->assertSame(CheckStatus::Fail, $result->status);
        $this->assertSame(1, $result->data['stuck']);
        $this->assertStringContainsString('purchase_order #' . $documentId, implode("\n", $result->findings));
        $this->assertStringContainsString('"stuck"', implode("\n", $result->findings));
    }

    public function testRun_FailsForAStatusThatIsReadFromTheDocumentsAndWasStoredByMistake(): void
    {
        $documentId = $this->makeDocument(PurchaseDocumentKind::Request);

        $this->dropStatusCheck(PurchaseDocumentKind::Request);
        $this->accountingDb()->table('purchase_requests')->where('request_id', $documentId)->update(['status' => 'converted']);

        $this->assertSame(CheckStatus::Fail, $this->outcome()->status, 'converted is read from the documents that stand and is never stored');
    }

    public function testRun_PutsTheOpenDocumentsOfASwitchedOffCycleUpForReview(): void
    {
        $this->makeDocument(PurchaseDocumentKind::Request);
        $this->makeDocument(PurchaseDocumentKind::Order);
        $cancelled = $this->makeDocument(PurchaseDocumentKind::Order);

        $this->documentsOf(PurchaseDocumentKind::Order)->cancel($cancelled, 'no', $this->clerk);
        $this->setConfigValue('enable_purchase_cycle', '0');

        $result = $this->outcome();

        $this->assertSame(CheckStatus::Review, $result->status);
        $this->assertSame(2, $result->data['waiting_while_off']);
        $this->assertStringContainsString('2 open document(s)', implode("\n", $result->findings));

        $this->setConfigValue('enable_purchase_cycle', '1');

        $this->assertSame(CheckStatus::Pass, $this->outcome()->status);
    }

    public function testRun_PutsAnOrderReceivedBeyondItsLinesUpForReview(): void
    {
        $this->setConfigValue('purchase_order_allow_excess', '1');

        $orderId = $this->makeDocument(PurchaseDocumentKind::Order);

        $this->assertGreaterThan(0, $this->purchases->save_value([$this->purchaseLine($this->goods, 0, '12', '4.00')], $this->supplierId, $this->clerk, 'test', 'REF-1', lang('Sales.due'), NEW_ENTRY, null, null, null, null, $orderId));
        $this->receivingIds[] = (int) $this->accountingDb()->table('receivings')->selectMax('receiving_id', 'last')->get()->getRow()->last;

        $result = $this->outcome();

        $this->assertSame(CheckStatus::Review, $result->status);
        $this->assertSame(1, $result->data['received_beyond']);
        $this->assertStringContainsString('Purchase order #' . $orderId . ' line 1 was received for 12.000 of the 10.000 ordered', implode("\n", $result->findings));
    }

    public function testRun_PutsAReceivingOfAnotherSupplierThanItsOrderUpForReview(): void
    {
        $orderId = $this->makeDocument(PurchaseDocumentKind::Order);

        $this->assertGreaterThan(0, $this->purchases->save_value([$this->purchaseLine($this->goods, 0, '2', '4.00')], $this->supplierId, $this->clerk, 'test', 'REF-2', lang('Sales.due'), NEW_ENTRY, null, null, null, null, $orderId));

        $receivingId          = (int) $this->accountingDb()->table('receivings')->selectMax('receiving_id', 'last')->get()->getRow()->last;
        $this->receivingIds[] = $receivingId;

        $this->accountingDb()->table('receivings')->where('receiving_id', $receivingId)->update(['supplier_id' => $this->anotherSupplier()]);

        $result = $this->outcome();

        $this->assertSame(CheckStatus::Review, $result->status);
        $this->assertSame(1, $result->data['receivings_of_another_supplier']);
    }

    public function testRun_ChangesNothing(): void
    {
        $documentId = $this->makeDocument(PurchaseDocumentKind::Order);
        $before     = [$this->bookCounts(), $this->countRows('purchase_orders'), json_encode($this->accountingDb()->table('purchase_orders')->where('order_id', $documentId)->get()->getRowArray()), $this->countRows('accounting_audit_log')];

        $this->outcome();

        $this->assertSame($before, [$this->bookCounts(), $this->countRows('purchase_orders'), json_encode($this->accountingDb()->table('purchase_orders')->where('order_id', $documentId)->get()->getRowArray()), $this->countRows('accounting_audit_log')]);
    }
}
