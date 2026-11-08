<?php

namespace Tests\Libraries\Stock;

use App\Libraries\Accounting\AccountingError;
use App\Libraries\Accounting\AccountingException;
use App\Libraries\Accounting\EntityAccountService;
use App\Libraries\Accounting\JournalLineDraft;
use App\Libraries\Accounting\PostingService;
use App\Libraries\Accounting\QuoteStatus;
use App\Libraries\Stock\StockLocationLifecycle;
use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\DatabaseTestTrait;
use Config\Database;
use Tests\Support\AccountingFixtureTrait;
use Tests\Support\EmployeeFixtureTrait;
use Tests\Support\ItemFixtureTrait;
use Tests\Support\ShelfFixtureTrait;
use Throwable;

/**
 * @internal
 */
final class StockLocationLifecycleTest extends CIUnitTestCase
{
    use DatabaseTestTrait;
    use AccountingFixtureTrait;
    use EmployeeFixtureTrait;
    use ItemFixtureTrait;
    use ShelfFixtureTrait;

    protected $migrate     = true;
    protected $migrateOnce = true;
    protected $refresh     = false;
    protected $namespace;

    /** @var list<int> */
    private array $itemIds = [];

    /** @var list<array<string, mixed>> */
    private array $locationBackup = [];

    public static function setUpBeforeClass(): void
    {
        $seeder = Database::seeder('tests');
        $seeder->call('TestDatabaseBootstrapSeeder');
    }

    protected function setUp(): void
    {
        parent::setUp();

        $this->releaseOpenTransactions();
        $this->resetBranches();
        $this->locationBackup = $this->accountingDb()->table('stock_locations')->get()->getResultArray();
        $this->emptyShelves();
    }

    protected function tearDown(): void
    {
        $db = $this->accountingDb();
        $this->releaseOpenTransactions();

        $db->table('accounting_audit_log')->where('entity_type', 'stock_location')->delete();
        $db->table('accounting_stock_entries')->delete(['company_id' => 1]);
        foreach (['purchase_order_receiving_lines', 'purchase_order_receivings', 'purchase_order_lines', 'purchase_orders', 'supplier_quotation_lines', 'supplier_quotations', 'quotation_request_lines', 'quotation_requests', 'purchase_request_lines', 'purchase_requests'] as $cycleTable) {
            $db->query('DELETE FROM ' . $db->prefixTable($cycleTable));
        }

        $db->query('DELETE FROM ' . $db->prefixTable('stock_request_lines'));
        $db->query('DELETE FROM ' . $db->prefixTable('stock_requests'));
        $db->query('DELETE FROM ' . $db->prefixTable('stocktakes'));
        $db->query('DELETE FROM ' . $db->prefixTable('recurring_schedule_lines'));
        $db->query('DELETE FROM ' . $db->prefixTable('recurring_schedules'));
        $db->query('DELETE FROM ' . $db->prefixTable('sales_order_invoice_lines'));
        $db->query('DELETE FROM ' . $db->prefixTable('sales_order_invoices'));
        $db->query('DELETE FROM ' . $db->prefixTable('sales_order_lines'));
        $db->query('DELETE FROM ' . $db->prefixTable('sales_orders'));
        $db->query('DELETE FROM ' . $db->prefixTable('sales_items'));
        $db->query('DELETE FROM ' . $db->prefixTable('sales'));

        $this->restoreShelves();
        $this->removeInsertedEntities();

        $db->table('stock_locations')->update(['is_primary' => 0]);

        foreach ($this->locationBackup as $row) {
            $db->table('stock_locations')->where('location_id', $row['location_id'])->update(['status' => $row['status'], 'is_primary' => $row['is_primary'], 'deleted' => $row['deleted']]);
        }

        $this->itemIds = [];
        $this->setConfigValue('accounting_enabled', '0');

        parent::tearDown();
    }

    private function lifecycle(): StockLocationLifecycle
    {
        return new StockLocationLifecycle();
    }

    private function settingsManager(): int
    {
        return $this->employeeWithGrants([StockLocationLifecycle::PERMISSION]);
    }

    private function primaryLocationId(): int
    {
        return (int) $this->lifecycle()->primaryId();
    }

    private function statusOf(int $locationId): int
    {
        return (int) $this->accountingDb()->table('stock_locations')->where('location_id', $locationId)->get()->getRow()->status;
    }

    private function refusalOf(callable $action): ?AccountingError
    {
        try {
            $action();
        } catch (AccountingException $failure) {
            return $failure->error;
        }

        return null;
    }

    /**
     * Two active locations besides the primary one, so that any of them may be made inactive.
     *
     * @return array{int, int}
     */
    private function twoSpareLocations(): array
    {
        return [$this->insertStockLocation('Spare A'), $this->insertStockLocation('Spare B')];
    }

    private function auditCount(string $action, int $locationId): int
    {
        return $this->accountingDb()->table('accounting_audit_log')->where(['entity_type' => 'stock_location', 'entity_id' => $locationId, 'action' => $action])->countAllResults();
    }

    private function suspendSaleAt(int $locationId): int
    {
        $db     = $this->accountingDb();
        $itemId = $this->itemIds[] = $this->createTestItem();

        $db->table('sales')->insert(['sale_status' => SUSPENDED, 'employee_id' => 1, 'comment' => '']);
        $saleId = (int) $db->insertID();
        $db->table('sales_items')->insert(['sale_id' => $saleId, 'item_id' => $itemId, 'line' => 1, 'quantity_purchased' => 1, 'item_cost_price' => 1, 'item_unit_price' => 5, 'item_location' => $locationId]);

        return $saleId;
    }

    /**
     * @return array{int, int} The approved order and its line, which asks for the quantity given of an item at the location.
     */
    private function approvedOrderAt(int $locationId, string $quantity): array
    {
        $db         = $this->accountingDb();
        $customerId = $this->insertCustomer();
        $itemId     = $this->itemIds[] = $this->createTestItem();

        $db->table('sales_orders')->insert(['customer_id' => $customerId, 'order_date' => '2026-10-01', 'status' => 'approved']);
        $orderId = (int) $db->insertID();
        $db->table('sales_order_lines')->insert(['order_id' => $orderId, 'line_no' => 1, 'item_id' => $itemId, 'quantity' => $quantity, 'unit_price' => 5, 'item_location' => $locationId]);

        return [$orderId, (int) $db->insertID()];
    }

    private function invoiceTaking(int $orderId, int $lineId, string $quantity, int $saleStatus): void
    {
        $db = $this->accountingDb();

        $db->table('sales')->insert(['sale_status' => $saleStatus, 'sale_type' => SALE_TYPE_INVOICE, 'employee_id' => 1, 'comment' => '']);
        $saleId = (int) $db->insertID();
        $db->table('sales_order_invoices')->insert(['sale_id' => $saleId, 'order_id' => $orderId]);
        $db->table('sales_order_invoice_lines')->insert(['sale_id' => $saleId, 'order_line_id' => $lineId, 'quantity' => $quantity]);
    }

    public function testDeactivate_MakesAnEmptyLocationInactiveAndWritesItToTheAuditLog(): void
    {
        [$spare] = $this->twoSpareLocations();
        $employeeId = $this->settingsManager();

        $this->lifecycle()->deactivate($spare, $employeeId);

        $this->assertSame(0, $this->statusOf($spare));
        $this->assertSame(1, $this->auditCount('stock_location_deactivated', $spare));
        $this->assertSame($employeeId, (int) $this->accountingDb()->table('accounting_audit_log')->where(['entity_type' => 'stock_location', 'entity_id' => $spare])->get()->getRow()->employee_id);
    }

    public function testDeactivate_RejectsALocationThatHoldsStock(): void
    {
        [$spare] = $this->twoSpareLocations();
        $this->putOnShelf($this->itemIds[] = $this->createTestItem(), '3', $spare);

        $this->assertSame(AccountingError::StockLocationHoldsStock, $this->refusalOf(fn () => $this->lifecycle()->deactivate($spare, null)));
        $this->assertSame(1, $this->statusOf($spare));
        $this->assertSame(0, $this->auditCount('stock_location_deactivated', $spare));
    }

    public function testDeactivate_RejectsALocationWhoseBooksHoldAValue(): void
    {
        [$spare] = $this->twoSpareLocations();
        $itemId  = $this->itemIds[] = $this->createTestItem();

        $this->accountingDb()->table('accounting_stock_entries')->insert(['source_type' => 'receiving', 'source_id' => 1, 'line_no' => 1, 'entry_date' => '2026-10-01', 'item_id' => $itemId, 'location_id' => $spare, 'quantity' => 0, 'value' => 7.5]);

        $this->assertSame(AccountingError::StockLocationHoldsValue, $this->refusalOf(fn () => $this->lifecycle()->deactivate($spare, null)));
        $this->assertSame(1, $this->statusOf($spare));
    }

    public function testDeactivate_RejectsALocationWhoseMovementsHoldAQuantityWithNoValue(): void
    {
        [$spare] = $this->twoSpareLocations();
        $itemId  = $this->itemIds[] = $this->createTestItem();

        $this->accountingDb()->table('accounting_stock_entries')->insert(['source_type' => 'receiving', 'source_id' => 2, 'line_no' => 1, 'entry_date' => '2026-10-01', 'item_id' => $itemId, 'location_id' => $spare, 'quantity' => 2, 'value' => 0]);

        $this->assertSame(AccountingError::StockLocationHoldsValue, $this->refusalOf(fn () => $this->lifecycle()->deactivate($spare, null)));
    }

    public function testDeactivate_RejectsALocationWhoseSubAccountHoldsABalance(): void
    {
        $this->reinstallChart();
        $this->setConfigValue('accounting_enabled', '1');
        $entities = new EntityAccountService();
        [$spare]  = $this->twoSpareLocations();
        $entities->sync(EntityAccountService::STOCK_LOCATION, $spare);
        $accountId = (int) $entities->accountFor(EntityAccountService::STOCK_LOCATION, $spare);
        $this->assertGreaterThan(0, $accountId);

        $journalId = $this->postedJournalOn($accountId, '40.00');

        $this->assertGreaterThan(0, $journalId);
        $this->assertSame(AccountingError::StockLocationHoldsValue, $this->refusalOf(fn () => $this->lifecycle()->deactivate($spare, null)));
        $this->assertSame(1, $this->statusOf($spare));
    }

    public function testDeactivate_RejectsALocationNamedInASuspendedSale(): void
    {
        [$spare] = $this->twoSpareLocations();
        $this->suspendSaleAt($spare);

        $this->assertSame(AccountingError::StockLocationOpenDocuments, $this->refusalOf(fn () => $this->lifecycle()->deactivate($spare, null)));
        $this->assertSame(['suspended_sales' => 1], $this->lifecycle()->blockers($spare));
    }

    public function testDeactivate_AcceptsALocationWhoseOnlySaleIsCompleted(): void
    {
        [$spare] = $this->twoSpareLocations();
        $saleId  = $this->suspendSaleAt($spare);
        $this->accountingDb()->table('sales')->where('sale_id', $saleId)->update(['sale_status' => COMPLETED]);

        $this->lifecycle()->deactivate($spare, null);

        $this->assertSame(0, $this->statusOf($spare));
    }

    public function testDeactivate_RejectsALocationNamedInAnOpenSalesOrderButNotInACancelledOne(): void
    {
        [$spare]    = $this->twoSpareLocations();
        $customerId = $this->insertCustomer();
        $itemId     = $this->itemIds[] = $this->createTestItem();
        $db         = $this->accountingDb();

        $db->table('sales_orders')->insert(['customer_id' => $customerId, 'order_date' => '2026-10-01', 'status' => 'approved']);
        $orderId = (int) $db->insertID();
        $db->table('sales_order_lines')->insert(['order_id' => $orderId, 'line_no' => 1, 'item_id' => $itemId, 'quantity' => 1, 'unit_price' => 5, 'item_location' => $spare]);

        $this->assertSame(AccountingError::StockLocationOpenDocuments, $this->refusalOf(fn () => $this->lifecycle()->deactivate($spare, null)));

        $db->table('sales_orders')->where('order_id', $orderId)->update(['status' => 'cancelled']);

        $this->lifecycle()->deactivate($spare, null);

        $this->assertSame(0, $this->statusOf($spare));
    }

    public function testDeactivate_AcceptsALocationWhoseOnlyQuoteBecameAnInvoice(): void
    {
        [$spare] = $this->twoSpareLocations();
        $saleId  = $this->suspendSaleAt($spare);
        $db      = $this->accountingDb();

        $db->table('sales')->where('sale_id', $saleId)->update(['sale_type' => SALE_TYPE_QUOTE, 'quote_status' => QuoteStatus::Open->value]);

        $this->assertSame(AccountingError::StockLocationOpenDocuments, $this->refusalOf(fn () => $this->lifecycle()->deactivate($spare, null)));

        $db->table('sales')->where('sale_id', $saleId)->update(['quote_status' => QuoteStatus::Converted->value]);

        $this->lifecycle()->deactivate($spare, null);

        $this->assertSame(0, $this->statusOf($spare));
    }

    public function testDeactivate_RejectsALocationNamedInAQuoteInAnyStateButConvertedAndInOneWithNoState(): void
    {
        [$spare] = $this->twoSpareLocations();
        $saleId  = $this->suspendSaleAt($spare);
        $db      = $this->accountingDb();

        foreach ([null, QuoteStatus::Draft->value, QuoteStatus::Open->value, QuoteStatus::Approved->value, QuoteStatus::Ordered->value] as $state) {
            $db->table('sales')->where('sale_id', $saleId)->update(['sale_type' => SALE_TYPE_QUOTE, 'quote_status' => $state]);

            $this->assertSame(AccountingError::StockLocationOpenDocuments, $this->refusalOf(fn () => $this->lifecycle()->deactivate($spare, null)), 'state ' . var_export($state, true));
        }

        $this->assertSame(1, $this->statusOf($spare));
    }

    public function testDeactivate_AcceptsALocationWhoseOnlyOrderLineWasInvoicedInFull(): void
    {
        [$spare]          = $this->twoSpareLocations();
        [$orderId, $line] = $this->approvedOrderAt($spare, '2');

        $this->invoiceTaking($orderId, $line, '2', SUSPENDED);

        $this->assertSame(AccountingError::StockLocationOpenDocuments, $this->refusalOf(fn () => $this->lifecycle()->deactivate($spare, null)), 'a draft invoice has not taken the quantity yet');

        $this->accountingDb()->query('DELETE FROM ' . $this->accountingDb()->prefixTable('sales_order_invoice_lines'));
        $this->invoiceTaking($orderId, $line, '1', COMPLETED);

        $this->assertSame(AccountingError::StockLocationOpenDocuments, $this->refusalOf(fn () => $this->lifecycle()->deactivate($spare, null)), 'half of the line is still to invoice');

        $this->accountingDb()->table('sales_order_invoice_lines')->update(['quantity' => '2']);

        $this->lifecycle()->deactivate($spare, null);

        $this->assertSame(0, $this->statusOf($spare));
    }

    public function testDeactivate_RejectsALocationThatReceivedStockAfterTheSnapshotOfTheCaller(): void
    {
        [$spare] = $this->twoSpareLocations();
        $other   = $this->openSecondConnection();
        $this->startWithSnapshot($other);
        $this->putOnShelf($this->itemIds[] = $this->createTestItem(), '3', $spare);

        try {
            $this->assertSame(AccountingError::StockLocationHoldsStock, $this->refusalOf(fn () => (new StockLocationLifecycle($other))->deactivate($spare, null)));
        } finally {
            $other->transRollback();
            $other->close();
        }

        $this->assertSame(1, $this->statusOf($spare));
    }

    public function testDeactivate_RejectsALocationWhoseBooksReceivedAMovementAfterTheSnapshotOfTheCaller(): void
    {
        [$spare] = $this->twoSpareLocations();
        $itemId  = $this->itemIds[] = $this->createTestItem();
        $other   = $this->openSecondConnection();
        $this->startWithSnapshot($other);

        $this->accountingDb()->table('accounting_stock_entries')->insert(['source_type' => 'receiving', 'source_id' => 5, 'line_no' => 1, 'entry_date' => '2026-10-01', 'item_id' => $itemId, 'location_id' => $spare, 'quantity' => 2, 'value' => 20]);

        try {
            $this->assertSame(AccountingError::StockLocationHoldsValue, $this->refusalOf(fn () => (new StockLocationLifecycle($other))->deactivate($spare, null)));
        } finally {
            $other->transRollback();
            $other->close();
        }

        $this->assertSame(1, $this->statusOf($spare));
    }

    public function testDeactivate_RejectsALocationNamedInADocumentAddedAfterTheSnapshotOfTheCaller(): void
    {
        [$spare] = $this->twoSpareLocations();
        $other   = $this->openSecondConnection();
        $this->startWithSnapshot($other);
        $this->suspendSaleAt($spare);

        try {
            $this->assertSame(AccountingError::StockLocationOpenDocuments, $this->refusalOf(fn () => (new StockLocationLifecycle($other))->deactivate($spare, null)));
        } finally {
            $other->transRollback();
            $other->close();
        }

        $this->assertSame(1, $this->statusOf($spare));
    }

    public function testCheckRemoval_RejectsALocationMadePrimaryAfterTheSnapshotOfTheCaller(): void
    {
        [$spare] = $this->twoSpareLocations();
        $other   = $this->openSecondConnection();
        $this->startWithSnapshot($other);
        $this->lifecycle()->makePrimary($spare, null);

        try {
            $this->assertSame(AccountingError::StockLocationIsPrimary, $this->refusalOf(fn () => (new StockLocationLifecycle($other))->checkRemoval($spare)));
        } finally {
            $other->transRollback();
            $other->close();
        }
    }

    public function testDeactivate_AcceptsALocationWhoseMovementsNetToZero(): void
    {
        [$spare] = $this->twoSpareLocations();
        $itemId  = $this->itemIds[] = $this->createTestItem();
        $db      = $this->accountingDb();

        $db->table('accounting_stock_entries')->insert(['source_type' => 'receiving', 'source_id' => 3, 'line_no' => 1, 'entry_date' => '2026-10-01', 'item_id' => $itemId, 'location_id' => $spare, 'quantity' => 10, 'value' => 100]);
        $db->table('accounting_stock_entries')->insert(['source_type' => 'sale', 'source_id' => 4, 'line_no' => 1, 'entry_date' => '2026-10-02', 'item_id' => $itemId, 'location_id' => $spare, 'quantity' => -10, 'value' => -100]);

        $this->lifecycle()->deactivate($spare, null);

        $this->assertSame(0, $this->statusOf($spare));
    }

    public function testDeactivate_RejectsALocationNamedInAnActiveRecurringScheduleButNotAnEndedOne(): void
    {
        [$spare] = $this->twoSpareLocations();
        $itemId  = $this->itemIds[] = $this->createTestItem();
        $db      = $this->accountingDb();

        $customerId = $this->insertCustomer();

        $db->table('recurring_schedules')->insert(['kind' => 'invoice', 'name' => 'Monthly', 'start_date' => '2026-10-01', 'employee_id' => 1, 'status' => 'paused', 'customer_id' => $customerId, 'issue_mode' => 'draft']);
        $scheduleId = (int) $db->insertID();
        $db->table('recurring_schedule_lines')->insert(['schedule_id' => $scheduleId, 'line_no' => 1, 'item_id' => $itemId, 'quantity' => 1, 'unit_price' => 5, 'item_location' => $spare]);

        $this->assertSame(AccountingError::StockLocationOpenDocuments, $this->refusalOf(fn () => $this->lifecycle()->deactivate($spare, null)));

        $db->table('recurring_schedules')->where('schedule_id', $scheduleId)->update(['status' => 'ended']);

        $this->lifecycle()->deactivate($spare, null);

        $this->assertSame(0, $this->statusOf($spare));
    }

    public function testDeactivate_RejectsALocationNamedInAnOpenStockRequestAsSourceOrDestination(): void
    {
        [$spare, $other] = $this->twoSpareLocations();
        $db              = $this->accountingDb();

        $db->table('stock_requests')->insert(['from_location_id' => $other, 'to_location_id' => $spare, 'request_date' => '2026-10-01', 'status' => 'requested']);
        $requestId = (int) $db->insertID();

        $this->assertSame(AccountingError::StockLocationOpenDocuments, $this->refusalOf(fn () => $this->lifecycle()->deactivate($spare, null)));
        $this->assertSame(AccountingError::StockLocationOpenDocuments, $this->refusalOf(fn () => $this->lifecycle()->deactivate($other, null)));

        $db->table('stock_requests')->where('request_id', $requestId)->update(['status' => 'fulfilled']);

        $this->lifecycle()->deactivate($spare, null);

        $this->assertSame(0, $this->statusOf($spare));
    }

    public function testDeactivate_RejectsALocationNamedInAnOpenPurchaseDocumentAndFreesItWhenTheDocumentIsCancelled(): void
    {
        [$spare] = $this->twoSpareLocations();
        $db      = $this->accountingDb();

        $db->table('purchase_requests')->insert(['location_id' => $spare, 'document_date' => '2026-10-01', 'status' => 'open']);
        $requestId = (int) $db->insertID();

        $this->assertSame(AccountingError::StockLocationOpenDocuments, $this->refusalOf(fn () => $this->lifecycle()->deactivate($spare, null)));

        $db->table('purchase_requests')->where('request_id', $requestId)->update(['status' => 'cancelled']);

        $this->lifecycle()->deactivate($spare, null);

        $this->assertSame(0, $this->statusOf($spare));
    }

    public function testDeactivate_RejectsALocationWithAStocktakeThatIsStillCounting(): void
    {
        [$spare] = $this->twoSpareLocations();
        $db      = $this->accountingDb();

        $db->table('stocktakes')->insert(['location_id' => $spare, 'count_date' => '2026-10-01', 'status' => 'counting']);
        $stocktakeId = (int) $db->insertID();

        $this->assertSame(AccountingError::StockLocationOpenDocuments, $this->refusalOf(fn () => $this->lifecycle()->deactivate($spare, null)));

        $db->table('stocktakes')->where('stocktake_id', $stocktakeId)->update(['status' => 'approved']);

        $this->lifecycle()->deactivate($spare, null);

        $this->assertSame(0, $this->statusOf($spare));
    }

    public function testDeactivate_RejectsThePrimaryLocation(): void
    {
        $this->twoSpareLocations();
        $primary = $this->primaryLocationId();

        $this->assertSame(AccountingError::StockLocationIsPrimary, $this->refusalOf(fn () => $this->lifecycle()->deactivate($primary, null)));
        $this->assertSame(1, $this->statusOf($primary));
    }

    public function testDeactivate_RejectsTheOnlyActiveLocation(): void
    {
        [$spare] = $this->twoSpareLocations();
        $db      = $this->accountingDb();

        $db->table('stock_locations')->where('location_id !=', $spare)->update(['status' => 0, 'is_primary' => 0]);
        $db->table('stock_locations')->where('location_id', $spare)->update(['is_primary' => 0]);

        $this->assertSame(AccountingError::StockLocationLastActive, $this->refusalOf(fn () => $this->lifecycle()->deactivate($spare, null)));
        $this->assertSame(1, $this->statusOf($spare));
    }

    public function testDeactivate_RejectsAnEmployeeWithoutThePermission(): void
    {
        [$spare]  = $this->twoSpareLocations();
        $stranger = $this->employeeWithGrants([]);

        $this->assertSame(AccountingError::PermissionDenied, $this->refusalOf(fn () => $this->lifecycle()->deactivate($spare, $stranger)));
        $this->assertSame(1, $this->statusOf($spare));
    }

    public function testDeactivate_RejectsALocationThatDoesNotExist(): void
    {
        $this->assertSame(AccountingError::StockLocationNotFound, $this->refusalOf(fn () => $this->lifecycle()->deactivate(987654, null)));
    }

    public function testDeactivate_LeavesAnInactiveLocationAloneWithoutANewAuditEntry(): void
    {
        [$spare] = $this->twoSpareLocations();

        $this->lifecycle()->deactivate($spare, null);
        $this->lifecycle()->deactivate($spare, null);

        $this->assertSame(1, $this->auditCount('stock_location_deactivated', $spare));
    }

    public function testDeactivate_KeepsTheSubAccountOfTheLocationAndItsLinkToIt(): void
    {
        $this->reinstallChart();
        $this->setConfigValue('accounting_enabled', '1');
        $entities = new EntityAccountService();
        [$spare]  = $this->twoSpareLocations();
        $entities->sync(EntityAccountService::STOCK_LOCATION, $spare);
        $accountId = (int) $entities->accountFor(EntityAccountService::STOCK_LOCATION, $spare);

        $this->lifecycle()->deactivate($spare, null);

        $this->assertSame($accountId, $entities->accountFor(EntityAccountService::STOCK_LOCATION, $spare));
        $this->assertSame('1', (string) $this->accountingDb()->table('accounting_accounts')->where('account_id', $accountId)->get()->getRow()->active);
    }

    public function testActivate_MakesAnInactiveLocationActiveAgainAndAuditsIt(): void
    {
        [$spare] = $this->twoSpareLocations();
        $this->lifecycle()->deactivate($spare, null);

        $this->lifecycle()->activate($spare, $this->settingsManager());

        $this->assertSame(1, $this->statusOf($spare));
        $this->assertSame(1, $this->auditCount('stock_location_activated', $spare));
    }

    public function testActivate_RejectsAnEmployeeWithoutThePermission(): void
    {
        [$spare] = $this->twoSpareLocations();
        $this->lifecycle()->deactivate($spare, null);

        $this->assertSame(AccountingError::PermissionDenied, $this->refusalOf(fn () => $this->lifecycle()->activate($spare, $this->employeeWithGrants([]))));
        $this->assertSame(0, $this->statusOf($spare));
    }

    public function testMakePrimary_MovesTheFlagToTheLocationAndAuditsTheChange(): void
    {
        [$spare] = $this->twoSpareLocations();
        $before  = $this->primaryLocationId();

        $this->lifecycle()->makePrimary($spare, $this->settingsManager());

        $this->assertSame($spare, $this->primaryLocationId());
        $this->assertSame(1, $this->accountingDb()->table('stock_locations')->where('deleted', 0)->where('is_primary', 1)->countAllResults());
        $this->assertSame(1, $this->auditCount('stock_location_primary_changed', $spare));

        $details = json_decode((string) $this->accountingDb()->table('accounting_audit_log')->where(['entity_type' => 'stock_location', 'action' => 'stock_location_primary_changed'])->get()->getRow()->details, true);

        $this->assertSame($before, $details['from']['location_id']);
        $this->assertSame($spare, $details['to']['location_id']);
    }

    public function testMakePrimary_LetsTheFormerPrimaryLocationBeMadeInactiveAfterwards(): void
    {
        [$spare] = $this->twoSpareLocations();
        $former  = $this->primaryLocationId();

        $this->lifecycle()->makePrimary($spare, null);
        $this->lifecycle()->deactivate($former, null);

        $this->assertSame(0, $this->statusOf($former));
        $this->assertSame($spare, $this->primaryLocationId());
    }

    public function testMakePrimary_RejectsAnInactiveLocation(): void
    {
        [$spare, $other] = $this->twoSpareLocations();
        $this->lifecycle()->deactivate($spare, null);
        $before = $this->primaryLocationId();

        $this->assertSame(AccountingError::StockLocationInactive, $this->refusalOf(fn () => $this->lifecycle()->makePrimary($spare, null)));
        $this->assertSame($before, $this->primaryLocationId());
        $this->assertNotSame($other, $this->primaryLocationId());
    }

    public function testMakePrimary_RejectsAnEmployeeWithoutThePermissionAndKeepsThePrimary(): void
    {
        [$spare] = $this->twoSpareLocations();
        $before  = $this->primaryLocationId();

        $this->assertSame(AccountingError::PermissionDenied, $this->refusalOf(fn () => $this->lifecycle()->makePrimary($spare, $this->employeeWithGrants([]))));
        $this->assertSame($before, $this->primaryLocationId());
        $this->assertSame(0, $this->auditCount('stock_location_primary_changed', $spare));
    }

    public function testMakePrimary_RejectsALocationThatDoesNotExist(): void
    {
        $this->assertSame(AccountingError::StockLocationNotFound, $this->refusalOf(fn () => $this->lifecycle()->makePrimary(987654, null)));
    }

    public function testMakePrimary_WritesNothingForTheLocationThatIsAlreadyPrimary(): void
    {
        $primary = $this->primaryLocationId();

        $this->lifecycle()->makePrimary($primary, null);

        $this->assertSame(0, $this->auditCount('stock_location_primary_changed', $primary));
        $this->assertSame($primary, $this->primaryLocationId());
    }

    public function testMakePrimary_TakesNoStockAndNoSubAccountWithIt(): void
    {
        $this->reinstallChart();
        $this->setConfigValue('accounting_enabled', '1');
        $entities = new EntityAccountService();
        [$spare]  = $this->twoSpareLocations();
        $primary  = $this->primaryLocationId();
        $entities->sync(EntityAccountService::STOCK_LOCATION, $spare);
        $entities->sync(EntityAccountService::STOCK_LOCATION, $primary);
        $accounts = [$spare => $entities->accountFor(EntityAccountService::STOCK_LOCATION, $spare), $primary => $entities->accountFor(EntityAccountService::STOCK_LOCATION, $primary)];
        $this->putOnShelf($this->itemIds[] = $this->createTestItem(), '4', $primary);

        $this->lifecycle()->makePrimary($spare, null);

        $this->assertSame($accounts[$spare], $entities->accountFor(EntityAccountService::STOCK_LOCATION, $spare));
        $this->assertSame($accounts[$primary], $entities->accountFor(EntityAccountService::STOCK_LOCATION, $primary));
        $this->assertSame('4.000', (string) $this->accountingDb()->table('item_quantities')->where('location_id', $primary)->get()->getRow()->quantity);
    }

    public function testMakePrimary_RejectsASecondLiveLocationWithThePrimaryFlagAtTheTable(): void
    {
        [$spare] = $this->twoSpareLocations();

        $this->expectException(Throwable::class);

        $this->accountingDb()->table('stock_locations')->where('location_id', $spare)->update(['is_primary' => 1]);
    }

    public function testDeactivate_RejectsAStatusThatIsNeitherActiveNorInactiveAtTheTable(): void
    {
        [$spare] = $this->twoSpareLocations();

        $this->expectException(Throwable::class);

        $this->accountingDb()->table('stock_locations')->where('location_id', $spare)->update(['status' => 2]);
    }

    public function testEnsurePrimary_MarksTheLocationWhenTheCompanyHasNoPrimaryLocation(): void
    {
        [$spare] = $this->twoSpareLocations();
        $this->accountingDb()->table('stock_locations')->update(['is_primary' => 0]);

        $this->lifecycle()->ensurePrimary($spare);

        $this->assertSame($spare, $this->primaryLocationId());
    }

    public function testEnsurePrimary_LeavesAnExistingPrimaryLocationAlone(): void
    {
        [$spare] = $this->twoSpareLocations();
        $before  = $this->primaryLocationId();

        $this->lifecycle()->ensurePrimary($spare);

        $this->assertSame($before, $this->primaryLocationId());
    }

    public function testCheckRemoval_RejectsThePrimaryTheOnlyActiveAndOneThatHoldsStock(): void
    {
        [$spare, $other] = $this->twoSpareLocations();
        $primary         = $this->primaryLocationId();

        $this->assertSame(AccountingError::StockLocationIsPrimary, $this->refusalOf(fn () => $this->lifecycle()->checkRemoval($primary)));

        $this->putOnShelf($this->itemIds[] = $this->createTestItem(), '2', $spare);
        $this->assertSame(AccountingError::StockLocationHoldsStock, $this->refusalOf(fn () => $this->lifecycle()->checkRemoval($spare)));

        $this->accountingDb()->table('stock_locations')->whereNotIn('location_id', [$other, $primary])->update(['status' => 0]);
        $this->accountingDb()->table('stock_locations')->where('location_id', $primary)->update(['status' => 0, 'is_primary' => 0]);
        $this->accountingDb()->table('stock_locations')->where('location_id', $other)->update(['is_primary' => 0]);

        $this->assertSame(AccountingError::StockLocationLastActive, $this->refusalOf(fn () => $this->lifecycle()->checkRemoval($other)));
    }

    public function testCheckRemoval_AcceptsAnEmptyLocationAndAnInactiveOne(): void
    {
        [$spare, $other] = $this->twoSpareLocations();
        $this->lifecycle()->deactivate($other, null);

        $this->assertNull($this->refusalOf(fn () => $this->lifecycle()->checkRemoval($spare)));
        $this->assertNull($this->refusalOf(fn () => $this->lifecycle()->checkRemoval($other)));
        $this->assertNull($this->refusalOf(fn () => $this->lifecycle()->checkRemoval(987654)));
    }

    public function testBlockers_ListsEveryReasonWithItsCount(): void
    {
        [$spare] = $this->twoSpareLocations();
        $this->putOnShelf($this->itemIds[] = $this->createTestItem(), '2', $spare);
        $this->putOnShelf($this->itemIds[] = $this->createTestItem(), '5', $spare);
        $this->suspendSaleAt($spare);

        $this->assertSame(['shelves' => 2, 'suspended_sales' => 1], $this->lifecycle()->blockers($spare));
    }

    public function testDeactivate_AcceptsALocationWhoseShelvesHoldOnlyZeros(): void
    {
        [$spare] = $this->twoSpareLocations();
        $this->putOnShelf($this->itemIds[] = $this->createTestItem(), '0', $spare);

        $this->lifecycle()->deactivate($spare, null);

        $this->assertSame(0, $this->statusOf($spare));
        $this->assertSame([], $this->lifecycle()->blockers($spare));
    }

    public function testActivate_LeavesAnActiveLocationAloneWithoutAnAuditEntry(): void
    {
        [$spare] = $this->twoSpareLocations();

        $this->lifecycle()->activate($spare, null);

        $this->assertSame(1, $this->statusOf($spare));
        $this->assertSame(0, $this->auditCount('stock_location_activated', $spare));
    }

    public function testCheckRemoval_AcceptsAnInactiveLocationWhenThePrimaryIsTheOnlyActiveOne(): void
    {
        [$spare] = $this->twoSpareLocations();
        $this->accountingDb()->table('stock_locations')->where('location_id !=', $this->primaryLocationId())->update(['status' => 0]);

        $this->assertNull($this->refusalOf(fn () => $this->lifecycle()->checkRemoval($spare)));
    }

    public function testDeactivate_WaitsForTheLockThatAnotherChangeOfTheLocationsHolds(): void
    {
        [$spare] = $this->twoSpareLocations();
        $db      = $this->accountingDb();

        $db->transStart();

        try {
            $db->query('SELECT location_id FROM ' . $db->prefixTable('stock_locations') . ' WHERE location_id = ? FOR UPDATE', [$this->primaryLocationId()]);

            $this->assertWaitsForLock(fn () => (new StockLocationLifecycle($this->openSecondConnection()))->deactivate($spare, null));
        } finally {
            $db->transComplete();
        }

        $this->assertSame(1, $this->statusOf($spare));
    }

    public function testMakePrimary_WaitsForTheLockThatAnotherChangeOfTheLocationsHolds(): void
    {
        [$spare] = $this->twoSpareLocations();
        $before  = $this->primaryLocationId();
        $db      = $this->accountingDb();

        $db->transStart();

        try {
            $db->query('SELECT location_id FROM ' . $db->prefixTable('stock_locations') . ' WHERE deleted = 0 FOR UPDATE');

            $this->assertWaitsForLock(fn () => (new StockLocationLifecycle($this->openSecondConnection()))->makePrimary($spare, null));
        } finally {
            $db->transComplete();
        }

        $this->assertSame($before, $this->primaryLocationId());
    }

    private function postedJournalOn(int $accountId, string $amount): int
    {
        $draft = $this->draftWithLines([
            new JournalLineDraft($accountId, debit: $amount),
            new JournalLineDraft($this->accountId('cash'), credit: $amount),
        ]);

        return (new PostingService())->post($draft)->journalId;
    }
}
