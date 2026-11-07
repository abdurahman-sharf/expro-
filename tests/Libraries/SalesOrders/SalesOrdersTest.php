<?php

namespace Tests\Libraries\SalesOrders;

use App\Libraries\Accounting\AccountingError;
use App\Libraries\Accounting\AccountingException;
use App\Libraries\Organization\BranchError;
use App\Libraries\Organization\BranchException;
use App\Libraries\Numbering\DocumentNumbering;
use App\Libraries\Numbering\DocumentType;
use App\Libraries\SalesOrders\SalesOrders;
use App\Libraries\SalesOrders\SalesOrderStatus;
use CodeIgniter\Database\Exceptions\DatabaseException;
use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\DatabaseTestTrait;
use Config\Database;
use Tests\Support\AccountingFixtureTrait;
use Tests\Support\ConcurrentDbRaceTrait;
use Tests\Support\EmployeeFixtureTrait;
use Tests\Support\ItemFixtureTrait;
use Tests\Support\RoleFixtureTrait;
use Tests\Support\SaleAccountingFixtureTrait;

/**
 * The sales order: a document that posts nothing and moves no stock, its statuses, who may change it and
 * see it, the branch it belongs to, and the quantity each invoice made from it takes from its lines.
 */
class SalesOrdersTest extends CIUnitTestCase
{
    use DatabaseTestTrait;
    use AccountingFixtureTrait;
    use ConcurrentDbRaceTrait;
    use EmployeeFixtureTrait;
    use ItemFixtureTrait;
    use RoleFixtureTrait;
    use SaleAccountingFixtureTrait;

    protected $migrate     = true;
    protected $migrateOnce = true;
    protected $refresh     = false;
    protected $namespace   = null;

    private SalesOrders $orders;
    private int $clerk;
    private int $service;

    public static function setUpBeforeClass(): void
    {
        $seeder = Database::seeder('tests');
        $seeder->call('TestDatabaseBootstrapSeeder');
    }

    protected function setUp(): void
    {
        parent::setUp();

        $this->startSelling();
        $this->allowEmployeeEverywhere();
        $this->clearOrders();
        $this->setConfigValue('enable_sales_order', '1');
        $this->setConfigValue('sales_order_allow_excess', '0');

        $this->orders  = new SalesOrders($this->accountingDb());
        $this->clerk   = $this->employeeWithGrants([SalesOrders::ADD, SalesOrders::EDIT, SalesOrders::DELETE, SalesOrders::VIEW_ALL, SalesOrders::CONVERT]);
        $this->service = $this->item('0', false);
    }

    protected function tearDown(): void
    {
        $this->clearOrders();
        $this->accountingDb()->table('accounting_audit_log')->whereIn('entity_type', ['sales_order', 'sales_order_settings', 'document_numbering'])->delete();
        $this->setConfigValue('enable_sales_order', '0');
        $this->setConfigValue('sales_order_allow_excess', '0');
        $this->stopSelling();

        parent::tearDown();
    }

    private function clearOrders(): void
    {
        $db = $this->accountingDb();
        $db->query('SET FOREIGN_KEY_CHECKS = 0');

        foreach (['sales_order_invoice_lines', 'sales_order_invoices', 'sales_order_lines', 'sales_orders', 'document_numbers', 'document_sequences'] as $table) {
            $db->table($table)->truncate();
        }

        $db->query('SET FOREIGN_KEY_CHECKS = 1');
    }

    /**
     * @param list<array<string, mixed>>|null $lines
     */
    private function order(?array $lines = null, ?int $customerId = null, int $location = self::LOCATION_ID, ?int $by = null, ?string $note = null): int
    {
        return $this->orders->create([
            'customer_id' => $customerId ?? $this->customerId,
            'location_id' => $location,
            'order_date'  => '2026-10-20',
            'note'        => $note ?? 'order',
            'lines'       => $lines ?? [['item_id' => $this->service, 'quantity' => '10', 'unit_price' => '50.00']],
        ], $by ?? $this->clerk);
    }

    private function approved(?array $lines = null, ?int $customerId = null): int
    {
        $orderId = $this->order($lines, $customerId);
        $this->orders->changeStatus($orderId, SalesOrderStatus::Approved, $this->clerk);

        return $orderId;
    }

    private function invoice(int $orderId, string $quantity, int $status = COMPLETED, ?int $customerId = null, ?int $by = null, int $saleId = NEW_ENTRY, ?int $itemId = null, bool $fromOrder = true): int
    {
        $saleStatus = $status;
        $items      = [$this->line($itemId ?? $this->service, 0, $quantity, '50.00')];
        $taxes      = [[], []];
        $result     = $this->sales->save_value($saleId, $saleStatus, $items, $customerId ?? $this->customerId, $by ?? $this->employeeId, 'test', null, null, null, SALE_TYPE_INVOICE, [], null, $taxes, true, orderSourceId: $fromOrder ? $orderId : null);

        if ($result > 0) {
            $this->saleIds[] = $result;
        }

        return $result;
    }

    private function refusal(callable $action): AccountingException|BranchException
    {
        try {
            $action();
        } catch (AccountingException|BranchException $refusal) {
            return $refusal;
        }

        $this->fail('The action should have been refused.');
    }

    private function assertRefused(AccountingError $error, callable $action): void
    {
        $refusal = $this->refusal($action);

        $this->assertInstanceOf(AccountingException::class, $refusal);
        $this->assertSame($error, $refusal->error);
    }

    /**
     * @return list<object>
     */
    private function auditOf(int $orderId, string $action): array
    {
        return $this->accountingDb()->table('accounting_audit_log')->where(['entity_type' => 'sales_order', 'entity_id' => $orderId, 'action' => $action])->get()->getResult();
    }

    private function journalCount(): int
    {
        return $this->accountingDb()->table('accounting_journals')->countAllResults();
    }

    private function storedStatus(int $orderId): string
    {
        return (string) $this->accountingDb()->table('sales_orders')->where('order_id', $orderId)->get()->getRow()->status;
    }

    public function testCreate_StoresAnOpenOrderWithItsLinesAndPostsNothingAndMovesNoStock(): void
    {
        $stocked = $this->item('10', true);
        $before  = $this->getItemQuantity($stocked, self::LOCATION_ID);
        $journals = $this->journalCount();
        $sales   = $this->countRows('sales');

        $orderId = $this->order([
            ['item_id' => $this->service, 'quantity' => '10', 'unit_price' => '50.00'],
            ['item_id' => $stocked, 'quantity' => '2.5', 'unit_price' => '20', 'discount' => '10', 'discount_type' => 0, 'description' => 'Special'],
        ]);

        $order = $this->orders->find($orderId, $this->clerk);
        $lines = $this->orders->lines($orderId);

        $this->assertSame(SalesOrderStatus::Open, $order->status_object);
        $this->assertSame((string) $this->customerId, (string) $order->customer_id);
        $this->assertSame('1', (string) $order->branch_id);
        $this->assertSame('2026-10-20', $order->order_date);
        $this->assertCount(2, $lines);
        $this->assertSame('2.500', $lines[1]->quantity);
        $this->assertSame('20.00', $lines[1]->unit_price);
        $this->assertSame('10.00', $lines[1]->discount);
        $this->assertSame('Special', $lines[1]->description);
        $this->assertSame('0.000', $lines[0]->invoiced);
        $this->assertSame('10.000', $lines[0]->remaining);
        $this->assertSame($journals, $this->journalCount(), 'an order posts no journal');
        $this->assertSame($sales, $this->countRows('sales'), 'an order is not a sale');
        $this->assertSame($before, $this->getItemQuantity($stocked, self::LOCATION_ID), 'an order moves no stock');
    }

    public function testCreate_RefusesWhileTheModuleIsOff(): void
    {
        $this->setConfigValue('enable_sales_order', '0');

        $this->assertRefused(AccountingError::SalesOrderModuleOff, fn () => $this->order());
        $this->assertSame(0, $this->countRows('sales_orders'));
    }

    public function testCreate_RequiresThePermissionToAdd(): void
    {
        $other = $this->employeeWithGrants([SalesOrders::EDIT, SalesOrders::VIEW_ALL]);

        $this->assertRefused(AccountingError::PermissionDenied, fn () => $this->order(by: $other));
        $this->assertSame(0, $this->countRows('sales_orders'));
    }

    public function testCreate_RefusesWhatIsNotAnOrder(): void
    {
        $base = ['customer_id' => $this->customerId, 'location_id' => self::LOCATION_ID, 'order_date' => '2026-10-20', 'lines' => [['item_id' => $this->service, 'quantity' => '1', 'unit_price' => '1']]];
        $cases = [
            [AccountingError::SalesOrderCustomerInvalid, ['customer_id' => 999999]],
            [AccountingError::SalesOrderCustomerInvalid, ['customer_id' => 0]],
            [AccountingError::SalesOrderLocationInvalid, ['location_id' => 999999]],
            [AccountingError::SalesOrderDateInvalid, ['order_date' => '2026-02-30']],
            [AccountingError::SalesOrderNoLines, ['lines' => []]],
            [AccountingError::SalesOrderLineInvalid, ['lines' => [['item_id' => $this->service, 'quantity' => '0', 'unit_price' => '1']]]],
            [AccountingError::SalesOrderLineInvalid, ['lines' => [['item_id' => 999999, 'quantity' => '1', 'unit_price' => '1']]]],
            [AccountingError::DescriptionTooLong, ['note' => str_repeat('x', SalesOrders::NOTE_MAX + 1)]],
        ];

        foreach ($cases as [$error, $change]) {
            $this->assertRefused($error, fn () => $this->orders->create([...$base, ...$change], $this->clerk));
        }

        $this->assertSame(0, $this->countRows('sales_orders'));
    }

    public function testCreate_TakesTheDateOfTodayWhenNoneIsGiven(): void
    {
        $orderId = $this->orders->create(['customer_id' => $this->customerId, 'location_id' => self::LOCATION_ID, 'lines' => [['item_id' => $this->service, 'quantity' => '1', 'unit_price' => '1']]], $this->clerk, '2026-11-02');

        $this->assertSame('2026-11-02', $this->orders->find($orderId, $this->clerk)->order_date);
    }

    public function testCreate_GivesTheOrderANumberOfItsOwnSequenceWithFiveDigits(): void
    {
        $numbering = new DocumentNumbering($this->accountingDb());
        $numbering->configure(DocumentType::SalesOrder, ['enabled' => true, 'padding' => 5, 'next_number' => 1], null);
        $numbering->configure(DocumentType::SalesInvoice, ['enabled' => true, 'padding' => 3, 'next_number' => 700], null);

        $first  = $this->order();
        $second = $this->order();

        $this->assertSame('00001', $this->orders->numberOf($first));
        $this->assertSame('00002', $this->orders->numberOf($second));
        $this->assertSame('00003', $this->orders->peekNumber());
        $this->assertSame('700', (string) $numbering->peek(DocumentType::SalesInvoice), 'the invoice sequence is not touched by the orders');
    }

    public function testNumberOf_ShowsTheIdInFiveDigitsWhileTheSequenceIsOff(): void
    {
        $orderId = $this->order();

        $this->assertSame(str_pad((string) $orderId, 5, '0', STR_PAD_LEFT), $this->orders->numberOf($orderId));
        $this->assertNull($this->orders->peekNumber());
    }

    public function testCreate_BelongsToTheBranchOfTheStockLocationAndOnlyAnEmployeeOfThatBranchMayAddIt(): void
    {
        $branch   = $this->createBranch('B2', 'Second');
        $location = $this->branchLocation($branch);
        $inFirst  = $this->employeeWithGrants([SalesOrders::ADD, SalesOrders::VIEW_ALL], [1]);
        $inSecond = $this->employeeWithGrants([SalesOrders::ADD, SalesOrders::VIEW_ALL], [$branch]);

        $refusal = $this->refusal(fn () => $this->order(location: $location, by: $inFirst));

        $this->assertInstanceOf(BranchException::class, $refusal);
        $this->assertSame(BranchError::NotAllowed, $refusal->error);

        $orderId = $this->order(location: $location, by: $inSecond);

        $this->assertSame((string) $branch, (string) $this->orders->find($orderId, $inSecond)->branch_id);
        $this->assertSame(1, $this->countRows('sales_orders'));
    }

    public function testCreate_WritesTheAuditLogWhileTheBooksAreOn(): void
    {
        $orderId = $this->order();
        $entries = $this->auditOf($orderId, 'created');

        $this->assertCount(1, $entries);
        $this->assertSame((string) $this->clerk, (string) $entries[0]->employee_id);
        $this->assertSame('user', $entries[0]->origin);
        $this->assertSame('1', (string) $entries[0]->branch_id);
    }

    public function testCreate_WritesNoAuditEntryWhileTheBooksAreOff(): void
    {
        $this->setConfigValue('accounting_enabled', '0');

        $orderId = $this->order();

        $this->assertSame([], $this->auditOf($orderId, 'created'));
        $this->assertSame((string) $this->clerk, (string) $this->accountingDb()->table('sales_orders')->where('order_id', $orderId)->get()->getRow()->created_by);
    }

    public function testUpdate_ReplacesTheLinesAndTheHeaderOfAnOrderNothingWasInvoicedFrom(): void
    {
        $orderId = $this->order();
        $other   = $this->item('0', false);

        $this->orders->update($orderId, ['customer_id' => $this->customerId, 'location_id' => self::LOCATION_ID, 'order_date' => '2026-10-25', 'note' => 'changed', 'lines' => [['item_id' => $other, 'quantity' => '4', 'unit_price' => '12.5']]], $this->clerk);

        $order = $this->orders->find($orderId, $this->clerk);
        $lines = $this->orders->lines($orderId);

        $this->assertSame('changed', $order->note);
        $this->assertSame('2026-10-25', $order->order_date);
        $this->assertCount(1, $lines);
        $this->assertSame((string) $other, (string) $lines[0]->item_id);
        $this->assertSame((string) $this->clerk, (string) $order->updated_by);
        $this->assertCount(1, $this->auditOf($orderId, 'updated'));
    }

    public function testUpdate_RefusesAnOrderAnInvoiceWasMadeFromEvenWhenTheInvoiceWasCancelled(): void
    {
        $orderId = $this->approved();
        $input   = ['customer_id' => $this->customerId, 'location_id' => self::LOCATION_ID, 'lines' => [['item_id' => $this->service, 'quantity' => '1', 'unit_price' => '1']]];
        $invoice = $this->invoice($orderId, '4');

        $this->assertGreaterThan(0, $invoice);
        $this->assertRefused(AccountingError::SalesOrderLocked, fn () => $this->orders->update($orderId, $input, $this->clerk));

        $this->setConfigValue('accounting_enabled', '0');

        $this->assertTrue($this->sales->delete($invoice, false, true, $this->employeeId), 'a sale is cancelled while the books are off');
        $this->setConfigValue('accounting_enabled', '1');
        $this->assertRefused(AccountingError::SalesOrderLocked, fn () => $this->orders->update($orderId, $input, $this->clerk));
        $this->assertSame('10.000', $this->orders->lines($orderId)[0]->quantity);
    }

    public function testUpdate_RefusesACancelledOrder(): void
    {
        $orderId = $this->order();
        $this->orders->cancel($orderId, 'no longer wanted', $this->clerk);

        $this->assertRefused(AccountingError::SalesOrderLocked, fn () => $this->orders->update($orderId, ['customer_id' => $this->customerId, 'location_id' => self::LOCATION_ID, 'lines' => [['item_id' => $this->service, 'quantity' => '1', 'unit_price' => '1']]], $this->clerk));
    }

    public function testUpdate_RequiresThePermissionToEdit(): void
    {
        $orderId = $this->order();
        $other   = $this->employeeWithGrants([SalesOrders::ADD, SalesOrders::VIEW_ALL]);

        $this->assertRefused(AccountingError::PermissionDenied, fn () => $this->orders->update($orderId, ['customer_id' => $this->customerId, 'location_id' => self::LOCATION_ID, 'lines' => [['item_id' => $this->service, 'quantity' => '1', 'unit_price' => '1']]], $other));
        $this->assertSame('10.000', $this->orders->lines($orderId)[0]->quantity);
    }

    public function testUpdate_RefusesAnOrderOfABranchTheEmployeeDoesNotWorkIn(): void
    {
        $branch   = $this->createBranch('B2', 'Second');
        $location = $this->branchLocation($branch);
        $orderId  = $this->order(location: $location);
        $inFirst  = $this->employeeWithGrants([SalesOrders::EDIT, SalesOrders::VIEW_ALL], [1]);

        $refusal = $this->refusal(fn () => $this->orders->update($orderId, ['customer_id' => $this->customerId, 'location_id' => self::LOCATION_ID, 'lines' => [['item_id' => $this->service, 'quantity' => '1', 'unit_price' => '1']]], $inFirst));

        $this->assertInstanceOf(BranchException::class, $refusal);
        $this->assertSame('10.000', $this->orders->lines($orderId)[0]->quantity);
    }

    public function testUpdate_DoesNotShowAnOrderOfAnotherEmployeeToOneWhoMaySeeTheirOwnOnly(): void
    {
        $orderId = $this->order();
        $reader  = $this->employeeWithGrants([SalesOrders::EDIT]);

        $this->assertRefused(AccountingError::SalesOrderNotFound, fn () => $this->orders->update($orderId, ['customer_id' => $this->customerId, 'location_id' => self::LOCATION_ID, 'lines' => [['item_id' => $this->service, 'quantity' => '1', 'unit_price' => '1']]], $reader));
        $this->assertSame('10.000', $this->orders->lines($orderId)[0]->quantity);
    }

    public function testChangeStatus_ApprovesAndReopensAnOrderAndWritesTheAuditLog(): void
    {
        $orderId = $this->order();

        $this->assertSame(SalesOrderStatus::Approved, $this->orders->changeStatus($orderId, SalesOrderStatus::Approved, $this->clerk));
        $this->assertSame('approved', $this->storedStatus($orderId));
        $this->assertSame(SalesOrderStatus::Approved, $this->orders->changeStatus($orderId, SalesOrderStatus::Approved, $this->clerk), 'asking for the state it has changes nothing');
        $this->assertSame(SalesOrderStatus::Open, $this->orders->changeStatus($orderId, SalesOrderStatus::Open, $this->clerk));
        $this->assertCount(2, $this->auditOf($orderId, 'status_changed'));
    }

    public function testChangeStatus_RefusesWhatTheTableDoesNotAllowAndWhatNeedsAReason(): void
    {
        $orderId = $this->order();

        foreach ([SalesOrderStatus::Cancelled, SalesOrderStatus::Invoiced, SalesOrderStatus::PartlyInvoiced] as $to) {
            $this->assertRefused(AccountingError::SalesOrderStatusNotAllowed, fn () => $this->orders->changeStatus($orderId, $to, $this->clerk));
        }

        $this->assertSame('open', $this->storedStatus($orderId));
    }

    public function testChangeStatus_RefusesAnOrderOfABranchTheEmployeeDoesNotWorkIn(): void
    {
        $branch   = $this->createBranch('B2', 'Second');
        $location = $this->branchLocation($branch);
        $orderId  = $this->order(location: $location);
        $inFirst  = $this->employeeWithGrants([SalesOrders::EDIT, SalesOrders::VIEW_ALL], [1]);

        $refusal = $this->refusal(fn () => $this->orders->changeStatus($orderId, SalesOrderStatus::Approved, $inFirst));

        $this->assertInstanceOf(BranchException::class, $refusal);
        $this->assertSame('open', $this->storedStatus($orderId));
    }

    public function testChangeStatus_RefusesToReopenAnOrderThatIsPartlyInvoicedAndRequiresThePermissionToEdit(): void
    {
        $orderId = $this->approved();
        $other   = $this->employeeWithGrants([SalesOrders::VIEW_ALL]);

        $this->assertRefused(AccountingError::PermissionDenied, fn () => $this->orders->changeStatus($orderId, SalesOrderStatus::Open, $other));

        $this->invoice($orderId, '3');

        $this->assertSame(SalesOrderStatus::PartlyInvoiced, $this->orders->statusOf($orderId));
        $this->assertRefused(AccountingError::SalesOrderStatusNotAllowed, fn () => $this->orders->changeStatus($orderId, SalesOrderStatus::Open, $this->clerk));
        $this->assertSame('approved', $this->storedStatus($orderId));
    }

    public function testChangeStatus_RefusesWhileTheModuleIsOff(): void
    {
        $orderId = $this->order();
        $this->setConfigValue('enable_sales_order', '0');

        $this->assertRefused(AccountingError::SalesOrderModuleOff, fn () => $this->orders->changeStatus($orderId, SalesOrderStatus::Approved, $this->clerk));
    }

    public function testCancel_RequiresAReasonAndThePermissionToDelete(): void
    {
        $orderId = $this->order();
        $other   = $this->employeeWithGrants([SalesOrders::EDIT, SalesOrders::VIEW_ALL]);

        $this->assertRefused(AccountingError::ReasonRequired, fn () => $this->orders->cancel($orderId, '  ', $this->clerk));
        $this->assertRefused(AccountingError::ReasonRequired, fn () => $this->orders->cancel($orderId, str_repeat('x', SalesOrders::REASON_MAX + 1), $this->clerk));
        $this->assertRefused(AccountingError::PermissionDenied, fn () => $this->orders->cancel($orderId, 'because', $other));
        $this->assertSame('open', $this->storedStatus($orderId));
    }

    public function testCancel_CancelsAnOpenAnApprovedAndAPartlyInvoicedOrderWithTheReasonAndLeavesTheInvoicesStanding(): void
    {
        $open     = $this->order();
        $approved = $this->approved();
        $partly   = $this->approved();
        $invoice  = $this->invoice($partly, '3');

        foreach ([$open, $approved, $partly] as $orderId) {
            $this->orders->cancel($orderId, 'customer withdrew', $this->clerk);
            $this->assertSame(SalesOrderStatus::Cancelled, $this->orders->statusOf($orderId));
            $this->assertCount(1, $this->auditOf($orderId, 'cancelled'));
        }

        $order = $this->orders->find($partly, $this->clerk);

        $this->assertSame('customer withdrew', $order->cancel_reason);
        $this->assertSame((string) $this->clerk, (string) $order->cancelled_by);
        $this->assertSame((string) COMPLETED, (string) $this->accountingDb()->table('sales')->where('sale_id', $invoice)->get()->getRow()->sale_status);
    }

    public function testCancel_RefusesAnOrderOfABranchTheEmployeeDoesNotWorkIn(): void
    {
        $branch   = $this->createBranch('B2', 'Second');
        $location = $this->branchLocation($branch);
        $orderId  = $this->order(location: $location);
        $inFirst  = $this->employeeWithGrants([SalesOrders::DELETE, SalesOrders::VIEW_ALL], [1]);

        $refusal = $this->refusal(fn () => $this->orders->cancel($orderId, 'not mine to cancel', $inFirst));

        $this->assertInstanceOf(BranchException::class, $refusal);
        $this->assertSame('open', $this->storedStatus($orderId));
        $this->assertSame([], $this->auditOf($orderId, 'cancelled'));
    }

    public function testCancel_RefusesAnOrderThatIsInvoicedAndOneThatIsCancelled(): void
    {
        $invoiced = $this->approved();
        $this->invoice($invoiced, '10');
        $cancelled = $this->order();
        $this->orders->cancel($cancelled, 'x', $this->clerk);

        $this->assertSame(SalesOrderStatus::Invoiced, $this->orders->statusOf($invoiced));
        $this->assertRefused(AccountingError::SalesOrderStatusNotAllowed, fn () => $this->orders->cancel($invoiced, 'late', $this->clerk));
        $this->assertRefused(AccountingError::SalesOrderStatusNotAllowed, fn () => $this->orders->cancel($cancelled, 'again', $this->clerk));
    }

    public function testFind_DoesNotShowAnOrderOutsideTheBranchesOfTheViewerOrOfAnotherEmployeeWithoutTheViewAllPermission(): void
    {
        $branch   = $this->createBranch('B2', 'Second');
        $location = $this->branchLocation($branch);
        $elsewhere = $this->order(location: $location);
        $own      = $this->order();
        $inFirst  = $this->employeeWithGrants([SalesOrders::ADD, SalesOrders::VIEW_ALL], [1]);
        $ownOnly  = $this->employeeWithGrants([SalesOrders::ADD]);

        $this->assertNull($this->orders->find($elsewhere, $inFirst));
        $this->assertNotNull($this->orders->find($own, $inFirst));
        $this->assertNull($this->orders->find($own, $ownOnly), 'without the permission to see every order an employee sees their own');
        $this->assertNotNull($this->orders->find($own, $this->clerk));
        $this->assertNotNull($this->orders->find($own, null), 'the system sees every order');
    }

    public function testFind_ShowsAnEmployeeWithoutTheViewAllPermissionTheirOwnOrders(): void
    {
        $ownOnly = $this->employeeWithGrants([SalesOrders::ADD]);
        $orderId = $this->order(by: $ownOnly);

        $this->assertSame(SalesOrderStatus::Open, $this->orders->find($orderId, $ownOnly)->status_object);
    }

    public function testFind_DoesNotShowEveryOrderToAnEmployeeNarrowedToTheirOwnRecordsByARole(): void
    {
        $role    = $this->createRole([], 'own');
        $narrow  = $this->employeeWithRoles([$role], [SalesOrders::VIEW_ALL]);
        $orderId = $this->order();

        $this->assertNull($this->orders->find($orderId, $narrow));
        $this->assertFalse($this->orders->mayViewAll($narrow));
        $this->assertTrue($this->orders->mayViewAll($this->clerk));
        $this->assertTrue($this->orders->mayViewAll(null));
    }

    public function testSearch_ListsTheOrdersWithTheirDerivedStatusAndFiltersByIt(): void
    {
        $open      = $this->order();
        $approved  = $this->approved();
        $partly    = $this->approved();
        $this->invoice($partly, '4');
        $invoiced  = $this->approved();
        $this->invoice($invoiced, '10');
        $cancelled = $this->order();
        $this->orders->cancel($cancelled, 'x', $this->clerk);

        $all = $this->orders->search([], $this->clerk);

        $this->assertSame(5, $all['total']);

        $statuses = [];

        foreach ($all['rows'] as $row) {
            $statuses[(int) $row->order_id] = $row->derived_status;
        }

        ksort($statuses);

        $this->assertSame([$open => 'open', $approved => 'approved', $partly => 'partly_invoiced', $invoiced => 'invoiced', $cancelled => 'cancelled'], $statuses);

        foreach (['open' => $open, 'approved' => $approved, 'partly_invoiced' => $partly, 'invoiced' => $invoiced, 'cancelled' => $cancelled] as $status => $expected) {
            $found = $this->orders->search(['status' => $status], $this->clerk);

            $this->assertSame(1, $found['total'], $status);
            $this->assertSame([$expected], array_map(static fn (object $row): int => (int) $row->order_id, $found['rows']), $status);
        }
    }

    public function testSearch_FiltersByDateCustomerAndNumberAndListsTheGrossOfTheLines(): void
    {
        $numbering = new DocumentNumbering($this->accountingDb());
        $numbering->configure(DocumentType::SalesOrder, ['enabled' => true, 'padding' => 5], null);

        $hind = $this->anotherCustomer('Hind', 'Saad');
        $one  = $this->orders->create(['customer_id' => $this->customerId, 'location_id' => self::LOCATION_ID, 'order_date' => '2026-10-01', 'lines' => [['item_id' => $this->service, 'quantity' => '2', 'unit_price' => '30']]], $this->clerk);
        $two  = $this->orders->create(['customer_id' => $hind, 'location_id' => self::LOCATION_ID, 'order_date' => '2026-10-20', 'lines' => [['item_id' => $this->service, 'quantity' => '1', 'unit_price' => '5']]], $this->clerk);

        $ids = static fn (array $result): array => array_map(static fn (object $row): int => (int) $row->order_id, $result['rows']);

        $this->assertSame([$two], $ids($this->orders->search(['from' => '2026-10-10'], $this->clerk)));
        $this->assertSame([$one], $ids($this->orders->search(['to' => '2026-10-10'], $this->clerk)));
        $this->assertSame([$two], $ids($this->orders->search(['customer_id' => $hind], $this->clerk)));
        $this->assertSame([$two], $ids($this->orders->search(['search' => 'Hind'], $this->clerk)));
        $this->assertSame([$one], $ids($this->orders->search(['search' => '00001'], $this->clerk)));
        $this->assertSame('60.00', bcadd($this->orders->search(['customer_id' => $this->customerId], $this->clerk)['rows'][0]->gross, '0', 2));
        $this->assertSame([$two, $one], $ids($this->orders->search([], $this->clerk)));
        $this->assertSame([$one], $ids($this->orders->search([], $this->clerk, 1, 1)), 'a page starts where the offset says');
    }

    public function testSearch_ListsOnlyTheOrdersOfTheBranchesAndOfTheEmployeeWhoMaySeeOwnOrdersOnly(): void
    {
        $branch    = $this->createBranch('B2', 'Second');
        $location  = $this->branchLocation($branch);
        $inFirst   = $this->employeeWithGrants([SalesOrders::ADD, SalesOrders::VIEW_ALL], [1]);
        $inBoth    = $this->employeeWithGrants([SalesOrders::ADD], [1, $branch]);
        $first     = $this->order(by: $inFirst);
        $second    = $this->order(location: $location);
        $ownOfBoth = $this->order(by: $inBoth);

        $ids = static fn (array $result): array => array_map(static fn (object $row): int => (int) $row->order_id, $result['rows']);

        $this->assertSame([$ownOfBoth, $first], $ids($this->orders->search([], $inFirst)));
        $this->assertSame([$ownOfBoth], $ids($this->orders->search([], $inBoth)));
        $this->assertSame([$ownOfBoth, $second, $first], $ids($this->orders->search([], $this->clerk)));
    }

    public function testSearch_ListsNothingForAnEmployeeWithoutABranch(): void
    {
        $this->order();
        $stranded = $this->employeeWithGrants([SalesOrders::VIEW_ALL], []);

        $this->assertSame(0, $this->orders->search([], $stranded)['total']);
    }

    public function testAssertConvertible_AcceptsAnApprovedOrderAndOneThatWasInvoicedInPart(): void
    {
        $orderId = $this->approved();

        $this->assertSame(SalesOrderStatus::Approved, $this->orders->assertConvertible($orderId, $this->clerk)->status_object);

        $this->invoice($orderId, '3');

        $this->assertSame(SalesOrderStatus::PartlyInvoiced, $this->orders->assertConvertible($orderId, $this->clerk)->status_object);
    }

    public function testAssertConvertible_RefusesAnOpenAnInvoicedAndACancelledOrder(): void
    {
        $open      = $this->order();
        $invoiced  = $this->approved();
        $this->invoice($invoiced, '10');
        $cancelled = $this->approved();
        $this->orders->cancel($cancelled, 'x', $this->clerk);

        foreach ([$open, $invoiced, $cancelled] as $orderId) {
            $this->assertRefused(AccountingError::SalesOrderNotConvertible, fn () => $this->orders->assertConvertible($orderId, $this->clerk));
        }
    }

    public function testAssertConvertible_RequiresThePermissionToConvertAndTheModuleBeingOnAndAnOrderThatExists(): void
    {
        $orderId = $this->approved();
        $other   = $this->employeeWithGrants([SalesOrders::VIEW_ALL]);

        $this->assertRefused(AccountingError::PermissionDenied, fn () => $this->orders->assertConvertible($orderId, $other));
        $this->assertRefused(AccountingError::SalesOrderNotFound, fn () => $this->orders->assertConvertible(999999, $this->clerk));

        $this->setConfigValue('enable_sales_order', '0');

        $this->assertRefused(AccountingError::SalesOrderModuleOff, fn () => $this->orders->assertConvertible($orderId, $this->clerk));
    }

    public function testAssertConvertible_RefusesAnOrderOfABranchTheEmployeeDoesNotWorkIn(): void
    {
        $branch   = $this->createBranch('B2', 'Second');
        $location = $this->branchLocation($branch);
        $orderId  = $this->order(location: $location);
        $this->orders->changeStatus($orderId, SalesOrderStatus::Approved, $this->clerk);
        $inFirst  = $this->employeeWithGrants([SalesOrders::CONVERT, SalesOrders::VIEW_ALL], [1]);

        $this->assertNull($this->orders->find($orderId, $inFirst));
        $this->assertRefused(AccountingError::SalesOrderNotFound, fn () => $this->orders->assertConvertible($orderId, $inFirst));
    }

    public function testRemainingLines_ListsWhatIsLeftOfEveryLineAndLeavesOutTheLinesThatAreDone(): void
    {
        $other   = $this->item('0', false);
        $orderId = $this->approved([['item_id' => $this->service, 'quantity' => '10', 'unit_price' => '50'], ['item_id' => $other, 'quantity' => '4', 'unit_price' => '20']]);

        $this->invoice($orderId, '4', itemId: $other);
        $this->invoice($orderId, '6');

        $remaining = $this->orders->remainingLines($orderId);

        $this->assertCount(1, $remaining);
        $this->assertSame((string) $this->service, (string) $remaining[0]->item_id);
        $this->assertSame('4.000', $remaining[0]->remaining);
    }

    public function testRecordInvoice_TracksTheQuantityOfEachLineAndTheStatusFollowsTheInvoices(): void
    {
        $orderId = $this->approved();

        $first = $this->invoice($orderId, '4');

        $this->assertGreaterThan(0, $first);
        $this->assertSame(SalesOrderStatus::PartlyInvoiced, $this->orders->statusOf($orderId));
        $this->assertSame('4.000', $this->orders->lines($orderId)[0]->invoiced);
        $this->assertSame('6.000', $this->orders->lines($orderId)[0]->remaining);
        $this->assertSame($orderId, $this->orders->orderOfSale($first));

        $second = $this->invoice($orderId, '6');

        $this->assertGreaterThan(0, $second);
        $this->assertSame(SalesOrderStatus::Invoiced, $this->orders->statusOf($orderId));
        $this->assertSame('10.000', $this->orders->lines($orderId)[0]->invoiced);
        $this->assertSame('0.000', $this->orders->lines($orderId)[0]->remaining);
        $this->assertSame([$first, $second], array_map(static fn (object $row): int => (int) $row->sale_id, $this->orders->invoicesOf($orderId)));
        $this->assertCount(2, $this->auditOf($orderId, 'invoiced'));
    }

    public function testRecordInvoice_PostsTheInvoiceAsAnyOtherInvoiceAndTheOrderItselfPostedNothing(): void
    {
        $orderId  = $this->approved();
        $journals = $this->journalCount();

        $this->assertSame(0, $journals);

        $invoice = $this->invoice($orderId, '10');

        $this->assertGreaterThan(0, $invoice);
        $this->assertNotSame([], $this->standing($invoice), 'the invoice made from the order is posted');
        $this->assertSame('500.00', $this->balance($this->customerAccount()));
    }

    public function testRecordInvoice_RefusesMoreThanTheOrderHoldsAndSavesNothing(): void
    {
        $orderId = $this->approved();
        $this->invoice($orderId, '4');
        $sales    = $this->countRows('sales');
        $journals = $this->journalCount();

        $result = $this->invoice($orderId, '7');

        $this->assertSame(ACCOUNTING_FAILED, $result);
        $failure = $this->sales->accountingFailure();
        $this->assertInstanceOf(AccountingException::class, $failure);
        $this->assertSame(AccountingError::SalesOrderQuantityExceeded, $failure->error);
        $this->assertSame('6.000', $failure->context['max']);
        $this->assertSame($sales, $this->countRows('sales'), 'the refused invoice is not left behind');
        $this->assertSame($journals, $this->journalCount());
        $this->assertSame('4.000', $this->orders->lines($orderId)[0]->invoiced);
    }

    public function testRecordInvoice_AllowsMoreThanTheOrderHoldsWhenTheSettingAllowsItAndTheLineIsDone(): void
    {
        $this->setConfigValue('sales_order_allow_excess', '1');

        $orderId = $this->approved();
        $invoice = $this->invoice($orderId, '12');

        $this->assertGreaterThan(0, $invoice);
        $this->assertSame('12.000', $this->orders->lines($orderId)[0]->invoiced);
        $this->assertSame('0.000', $this->orders->lines($orderId)[0]->remaining);
        $this->assertSame(SalesOrderStatus::Invoiced, $this->orders->statusOf($orderId));
    }

    public function testRecordInvoice_RefusesAnItemTheOrderDoesNotHoldUnlessTheSettingAllowsIt(): void
    {
        $orderId = $this->approved();
        $other   = $this->item('0', false);

        $this->assertSame(ACCOUNTING_FAILED, $this->invoice($orderId, '1', itemId: $other));
        $this->assertSame(AccountingError::SalesOrderQuantityExceeded, $this->sales->accountingFailure()->error);

        $this->setConfigValue('sales_order_allow_excess', '1');

        $this->assertGreaterThan(0, $this->invoice($orderId, '1', itemId: $other));
        $this->assertSame('0.000', $this->orders->lines($orderId)[0]->invoiced, 'an item the order does not hold takes nothing from it');
    }

    public function testRecordInvoice_FillsTheLinesOfOneItemInOrder(): void
    {
        $orderId = $this->approved([['item_id' => $this->service, 'quantity' => '3', 'unit_price' => '50'], ['item_id' => $this->service, 'quantity' => '5', 'unit_price' => '40']]);

        $this->assertGreaterThan(0, $this->invoice($orderId, '4'));

        $lines = $this->orders->lines($orderId);

        $this->assertSame(['3.000', '1.000'], [$lines[0]->invoiced, $lines[1]->invoiced]);
        $this->assertSame(ACCOUNTING_FAILED, $this->invoice($orderId, '5'));
        $this->assertGreaterThan(0, $this->invoice($orderId, '4'));
        $this->assertSame(SalesOrderStatus::Invoiced, $this->orders->statusOf($orderId));
    }

    public function testRecordInvoice_RefusesAnOrderThatIsNotApprovedOrIsCancelled(): void
    {
        $open      = $this->order();
        $cancelled = $this->approved();
        $this->orders->cancel($cancelled, 'x', $this->clerk);

        foreach ([$open, $cancelled] as $orderId) {
            $this->assertSame(ACCOUNTING_FAILED, $this->invoice($orderId, '1'));
            $this->assertSame(AccountingError::SalesOrderNotConvertible, $this->sales->accountingFailure()->error);
        }

        $this->assertSame(0, $this->countRows('sales_order_invoice_lines'));
    }

    public function testRecordInvoice_RefusesAnInvoiceForAnotherCustomer(): void
    {
        $orderId = $this->approved();
        $hind    = $this->anotherCustomer('Hind', 'Saad');

        $this->assertSame(ACCOUNTING_FAILED, $this->invoice($orderId, '1', customerId: $hind));
        $this->assertSame(AccountingError::SalesOrderCustomerMismatch, $this->sales->accountingFailure()->error);
        $this->assertSame(0, $this->countRows('sales_order_invoices'));
    }

    public function testRecordInvoice_RefusesAnInvoiceSavedByAnEmployeeWhoDoesNotWorkInTheBranchOfTheOrder(): void
    {
        $branch   = $this->createBranch('B2', 'Second');
        $location = $this->branchLocation($branch);
        $orderId  = $this->order(location: $location);
        $this->orders->changeStatus($orderId, SalesOrderStatus::Approved, $this->clerk);
        $inFirst  = $this->employeeWithGrants([], [1]);

        $this->assertSame(ACCOUNTING_FAILED, $this->invoice($orderId, '1', by: $inFirst));
        $this->assertInstanceOf(BranchException::class, $this->sales->accountingFailure());
        $this->assertSame(0, $this->countRows('sales_order_invoices'));
    }

    public function testRecordInvoice_TakesNothingFromADraftUntilItIsIssuedAndStillFindsTheOrderWhenTheDraftIsSavedAgain(): void
    {
        $orderId = $this->approved();
        $draft   = $this->invoice($orderId, '4', SUSPENDED);

        $this->assertGreaterThan(0, $draft);
        $this->assertSame($orderId, $this->orders->orderOfSale($draft), 'the draft remembers its order');
        $this->assertSame(0, $this->countRows('sales_order_invoice_lines'), 'a draft takes no quantity');
        $this->assertSame(SalesOrderStatus::Approved, $this->orders->statusOf($orderId));

        $again = $this->invoice($orderId, '5', SUSPENDED, saleId: $draft, fromOrder: false);

        $this->assertSame($draft, $again);
        $this->assertSame($orderId, $this->orders->orderOfSale($draft));

        $issued = $this->invoice($orderId, '5', COMPLETED, saleId: $draft, fromOrder: false);

        $this->assertSame($draft, $issued);
        $this->assertSame('5.000', $this->orders->lines($orderId)[0]->invoiced, 'issuing the draft takes what it holds, and it is found without the register');
        $this->assertSame(SalesOrderStatus::PartlyInvoiced, $this->orders->statusOf($orderId));
    }

    public function testRecordInvoice_TakesTheQuantityOnceWhenTheSameSaleIsSavedAgain(): void
    {
        $orderId = $this->approved();
        $draft   = $this->invoice($orderId, '4', SUSPENDED);

        $this->assertSame($draft, $this->invoice($orderId, '4', COMPLETED, saleId: $draft));
        $this->assertSame('4.000', $this->orders->lines($orderId)[0]->invoiced);
        $this->assertSame(1, $this->countRows('sales_order_invoice_lines'));
    }

    public function testRecordInvoice_GivesTheQuantityBackWhenTheInvoiceIsCancelled(): void
    {
        $orderId = $this->approved();
        $first   = $this->invoice($orderId, '10');

        $this->assertSame(SalesOrderStatus::Invoiced, $this->orders->statusOf($orderId));

        $this->setConfigValue('accounting_enabled', '0');

        $this->assertTrue($this->sales->delete($first, false, true, $this->employeeId), 'an issued invoice is cancelled only while the books are off, otherwise it is corrected with a credit note');
        $this->assertSame(SalesOrderStatus::Approved, $this->orders->statusOf($orderId));
        $this->assertSame('0.000', $this->orders->lines($orderId)[0]->invoiced);
        $this->assertSame('10.000', $this->orders->lines($orderId)[0]->remaining);
        $this->assertGreaterThan(0, $this->invoice($orderId, '10'), 'what a cancelled invoice took can be invoiced again');
    }

    public function testRecordInvoice_WorksWithTheBooksOffAsAnyLegacySale(): void
    {
        $this->setConfigValue('accounting_enabled', '0');

        $orderId = $this->approved();
        $invoice = $this->invoice($orderId, '4');

        $this->assertGreaterThan(0, $invoice);
        $this->assertSame(0, $this->journalCount());
        $this->assertSame(SalesOrderStatus::PartlyInvoiced, $this->orders->statusOf($orderId));
        $this->assertSame(ACCOUNTING_FAILED, $this->invoice($orderId, '7'));
        $this->assertSame([], $this->auditOf($orderId, 'invoiced'), 'nothing is written in the audit log of the books while they are off');
    }

    public function testRecordInvoice_WaitsForTheLockOfTheOrderSoTwoInvoicesNeverTakeTheSameQuantity(): void
    {
        $orderId = $this->approved();
        $second  = $this->openSecondConnection();

        $second->transStart();
        $second->query('SELECT order_id FROM ' . $second->prefixTable('sales_orders') . ' WHERE order_id = ? FOR UPDATE', [$orderId]);
        $this->accountingDb()->query('SET SESSION innodb_lock_wait_timeout = 1');

        try {
            $result = $this->invoice($orderId, '1');

            $this->assertSame(ACCOUNTING_FAILED, $result, 'the invoice waits for the order and is refused when the lock is not given');
            $this->assertSame(AccountingError::WriteFailed, $this->sales->accountingFailure()->error);
        } catch (DatabaseException $exception) {
            $this->assertStringContainsString('Lock wait timeout', $exception->getMessage());
        } finally {
            $this->accountingDb()->query('SET SESSION innodb_lock_wait_timeout = 50');
            $second->transRollback();
            $this->releaseOpenTransactions();
        }

        $this->assertSame(0, $this->countRows('sales_order_invoice_lines'));
    }

    public function testRecordInvoice_LetsOneOfTwoInvoicesAtTheSameMomentTakeTheQuantityTheOrderHolds(): void
    {
        $orderId = $this->approved();
        $before  = $this->countRows('sales');

        [$first, $second] = $this->raceTwoProcesses('orderInvoice', [$orderId, '7', $this->service, $this->customerId, $this->employeeId], [$orderId, '7', $this->service, $this->customerId, $this->employeeId]);

        $this->saleIds = [...$this->saleIds, ...array_map(static fn (array $row): int => (int) $row['sale_id'], $this->accountingDb()->table('sales')->select('sale_id')->where('customer_id', $this->customerId)->get()->getResultArray())];

        $this->assertSame(1, (int) $first + (int) $second, 'of two invoices of 7 from an order of 10, one is saved and the other is refused');
        $this->assertSame('7.000', $this->orders->lines($orderId)[0]->invoiced);
        $this->assertSame($before + 1, $this->countRows('sales'));
        $this->assertSame(1, $this->countRows('sales_order_invoice_lines'));
    }

    public function testSettings_AreOffUntilTheyAreTurnedOn(): void
    {
        $this->setConfigValue('enable_sales_order', '0');
        $this->accountingDb()->table('app_config')->whereIn('key', ['enable_sales_order', 'sales_order_allow_excess'])->delete();

        $this->assertFalse($this->orders->isEnabled());
        $this->assertFalse($this->orders->allowsExcess());
    }

    public function testRecordInvoice_RefusesAnInvoiceWithoutTheOrderBeingFound(): void
    {
        $orderId = $this->approved();
        $sale    = $this->invoice($orderId, '1');

        $this->assertGreaterThan(0, $sale);

        $this->assertRefused(AccountingError::SalesOrderNotFound, fn () => $this->orders->recordInvoice($sale, 999999, [$this->line($this->service, 0, '1', '50')], $this->customerId, $this->employeeId, 1));
    }
}
