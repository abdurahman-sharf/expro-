<?php

namespace Tests\Libraries\Accounting;

use App\Libraries\Accounting\AccountingError;
use App\Libraries\Accounting\AccountingException;
use App\Libraries\Accounting\AccountingMessage;
use App\Libraries\Accounting\InterBranchPosition;
use App\Libraries\Accounting\InvoiceDiscount;
use App\Libraries\Accounting\JournalStatus;
use App\Libraries\Accounting\PostingError;
use App\Libraries\Accounting\PostingException;
use App\Libraries\Accounting\ReceivingLineEdit;
use App\Libraries\Accounting\ReceivingPosting;
use App\Libraries\Accounting\ReceivingPostingBuilder;
use App\Libraries\Accounting\StockLedger;
use App\Libraries\Accounting\TreasuryService;
use App\Libraries\Organization\BranchError;
use App\Libraries\Organization\BranchException;
use App\Libraries\Organization\BranchGuard;
use CodeIgniter\Events\Events;
use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\DatabaseTestTrait;
use Config\Database;
use Config\OSPOS;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Support\PreviewJournalFixtureTrait;
use Tests\Support\AccountingFixtureTrait;
use Tests\Support\ConcurrentDbRaceTrait;
use Tests\Support\EmployeeFixtureTrait;
use Tests\Support\ItemFixtureTrait;
use Tests\Support\ParityCaseTrait;
use Tests\Support\ParityFixture;
use Tests\Support\PurchaseAccountingFixtureTrait;
use Tests\Support\PurchaseGoldenTrait;
use Tests\Support\SaleAccountingFixtureTrait;

/**
 * What a purchase puts in the books, through the real purchase model: the goods and the tax in,
 * what the supplier is owed, the payment made at once and the payments made afterwards (a journal
 * of their own, only a completed one in the books), goods going back to a supplier at what they
 * are worth in stock, stock moving between locations, and the purchase taken back out when it is
 * deleted. Every refusal leaves nothing of the purchase behind, and every test ends with the stock
 * of every location tying to its account.
 */
class ReceivingPostingTest extends CIUnitTestCase
{
    use DatabaseTestTrait;
    use PreviewJournalFixtureTrait;
    use AccountingFixtureTrait;
    use ConcurrentDbRaceTrait;
    use EmployeeFixtureTrait;
    use ItemFixtureTrait;
    use ParityCaseTrait;
    use SaleAccountingFixtureTrait;
    use PurchaseAccountingFixtureTrait;
    use PurchaseGoldenTrait;

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
        $this->accountingDb()->table('app_config')->whereIn('key', ['accounting_journal_description_purchases'])->delete();
        $this->stopBuying();

        parent::tearDown();
    }

    /**
     * @return array<string, int>
     */
    private function roles(): array
    {
        return [
            'inventory' => $this->stockAccount(),
            'tax_input' => $this->accountId('tax_input'),
            'supplier'  => $this->supplierAccount(),
            'cash'      => $this->accountId('cash'),
            'cogs'      => $this->accountId('cogs'),
        ];
    }

    /**
     * @param list<array{string, string, string}> $lines Role, debit and credit.
     *
     * @return list<string>
     */
    private function expectedLines(array $lines): array
    {
        $roles = $this->roles();

        return array_map(static fn (array $line): string => $roles[$line[0]] . ':' . $line[1] . ':' . $line[2], $lines);
    }

    /**
     * The lines of a journal at the four decimals they are kept at.
     *
     * @return list<string>
     */
    private function exactLines(int $journalId): array
    {
        return array_map(
            static fn (object $line): string => $line->account_id . ':' . $line->debit . ':' . $line->credit,
            $this->accountingDb()->table('accounting_journal_lines')->select('account_id, debit, credit')->where('journal_id', $journalId)->orderBy('line_no', 'ASC')->get()->getResult(),
        );
    }

    private function failure(): PostingException|AccountingException|null
    {
        $failure = $this->purchases->accountingFailure();

        return $failure instanceof PostingException || $failure instanceof AccountingException ? $failure : null;
    }

    private function assertRefusedWith(PostingError $error, int $result): void
    {
        $this->assertSame(ACCOUNTING_FAILED, $result);
        $this->assertInstanceOf(PostingException::class, $this->failure());
        $this->assertSame($error, $this->failure()->error);
    }

    /**
     * Nothing of a purchase that was refused is left: no purchase, no stock, no journal, no
     * movement, and the cost of the item as it was.
     */
    private function assertNothingLeft(int $receivings, int $itemId, string $cost, string $onHand = '0.000'): void
    {
        $this->assertSame($receivings, $this->countRows('receivings'));
        $this->assertSame($onHand, $this->onHand($itemId));
        $this->assertSame($cost, $this->itemCostOf($itemId));
        $this->assertSame(0, $this->countRows('accounting_journals'));
        $this->assertSame(0, $this->countRows('accounting_stock_entries'));
    }

    /**
     * Twenty units bought at 65.70, which the books hold at 1,314.00, while the item carries 66.00.
     *
     * @return array{item: int, purchase: int}
     */
    private function stockOfTwenty(): array
    {
        $itemId   = $this->item('0.00');
        $purchase = $this->buy([$this->purchaseLine($itemId, 0, '20', '65.70')]);

        $this->assertGreaterThan(0, $purchase);
        $this->accountingDb()->table('items')->where('item_id', $itemId)->update(['cost_price' => '66.00']);

        return ['item' => $itemId, 'purchase' => $purchase];
    }

    // ---------------------------------------------------------------------------------------
    // A purchase
    // ---------------------------------------------------------------------------------------

    public function testPostCompleted_PostsTheGoodsAndTheTaxOfAPurchaseOnCreditToTheSupplier(): void
    {
        $first  = $this->item('0.00');
        $second = $this->item('0.00');

        $receivingId = $this->buy([$this->purchaseLine($first, 0, '20', '40', '15'), $this->purchaseLine($second, 1, '10', '120', '15')]);

        $this->assertGreaterThan(0, $receivingId);

        $journals = $this->standingPurchase($receivingId);

        $this->assertCount(1, $journals);
        $this->assertSame($this->expectedLines([['inventory', '2000.00', '0.00'], ['tax_input', '300.00', '0.00'], ['supplier', '0.00', '2300.00']]), $journals[0]['lines']);
        $this->assertSame([], $this->standingPurchase($receivingId, 'purchase_payment'));
        $this->assertSame('-2300.00', $this->balance($this->supplierAccount()));
        $this->assertSame(['quantity' => '20.000', 'value' => '800.00'], $this->stockOf($first));
        $this->assertSame(['quantity' => '10.000', 'value' => '1200.00'], $this->stockOf($second));
        $this->assertSame('20.000', $this->onHand($first));
        $this->assertStockTies();
    }

    public function testPostCompleted_PostsTheJournalOfThePaymentMadeAtOnce(): void
    {
        $itemId = $this->item('0.00');

        $receivingId = $this->buy([$this->purchaseLine($itemId, 0, '20', '100', '15')], -1, lang('Sales.cash'));

        $this->assertGreaterThan(0, $receivingId);
        $this->assertSame($this->expectedLines([['supplier', '2300.00', '0.00'], ['cash', '0.00', '2300.00']]), $this->standingPurchase($receivingId, 'purchase_payment')[0]['lines']);
        $this->assertSame('0.00', $this->balance($this->supplierAccount()));
        $this->assertSame('-2300.00', $this->balance($this->accountId('cash')));
        $this->assertStockTies();
    }

    public function testPostCompleted_PaysAPurchaseWithoutASupplierFromTheTreasuryDirectly(): void
    {
        $itemId = $this->item('0.00');

        $receivingId = $this->buy([$this->purchaseLine($itemId, 0, '20', '100', '15')], null, lang('Sales.cash'));

        $this->assertGreaterThan(0, $receivingId);
        $this->assertSame($this->expectedLines([['inventory', '2000.00', '0.00'], ['tax_input', '300.00', '0.00']]) + [2 => $this->accountId('cash') . ':0.00:2300.00'], $this->standingPurchase($receivingId)[0]['lines']);
        $this->assertSame([], $this->standingPurchase($receivingId, 'purchase_payment'));
        $this->assertStockTies();
    }

    public function testSaveValue_RefusesAPurchaseWithoutASupplierThatIsNotPaidAtOnceAndLeavesNothingOfIt(): void
    {
        $itemId    = $this->item('5.00');
        $receivings = $this->countRows('receivings');

        $result = $this->buy([$this->purchaseLine($itemId, 0, '20', '40', '15')], null);

        $this->assertRefusedWith(PostingError::SupplierRequired, $result);
        $this->assertSame($receivings, $this->countRows('receivings'));
        $this->assertSame('0.000', $this->onHand($itemId));
        $this->assertSame('5.0000', $this->itemCostOf($itemId), 'The cost of the item is as it was: the average went back with the purchase');
        $this->assertSame(0, $this->countRows('accounting_stock_entries'));
        $this->assertSame(0, $this->countRows('accounting_journals'));
    }

    public function testSaveValue_WritesTheReasonInTheFailureLogAfterTheRollback(): void
    {
        $itemId = $this->item('5.00');

        $this->buy([$this->purchaseLine($itemId, 0, '20', '40', '15')], null);

        $rows = $this->accountingDb()->table('accounting_posting_failures')->get()->getResult();

        $this->assertCount(1, $rows);
        $this->assertSame('purchase', $rows[0]->source_type);
        $this->assertSame('supplier_required', $rows[0]->error_code);
    }

    public function testPostCompleted_PostsAServiceAsACostAndMovesNoStock(): void
    {
        $service = $this->item('0.00', false);

        $receivingId = $this->buy([$this->purchaseLine($service, 0, '3', '100', '15')]);

        $this->assertGreaterThan(0, $receivingId);
        $this->assertSame($this->expectedLines([['cogs', '300.00', '0.00'], ['tax_input', '45.00', '0.00'], ['supplier', '0.00', '345.00']]), $this->standingPurchase($receivingId)[0]['lines']);
        $this->assertSame(0, $this->countRows('accounting_stock_entries'));
        $this->assertStockTies();
    }

    public function testPostCompleted_TakesTheDiscountOffTheCostOfTheGoodsAndOffTheAverage(): void
    {
        $itemId = $this->item('0.00');

        $receivingId = $this->buy([$this->purchaseLine($itemId, 0, '10', '50', '15', '10', PERCENT)]);

        $this->assertGreaterThan(0, $receivingId);
        $this->assertSame($this->expectedLines([['inventory', '450.00', '0.00'], ['tax_input', '67.50', '0.00'], ['supplier', '0.00', '517.50']]), $this->standingPurchase($receivingId)[0]['lines']);
        $this->assertSame('45.0000', $this->itemCostOf($itemId), 'A unit cost 45.00 after its discount, and that is the cost the item carries');
        $this->assertStockTies();
    }

    public function testPostCompleted_TakesAFixedDiscountOffEachUnit(): void
    {
        $itemId = $this->item('0.00');

        $receivingId = $this->buy([$this->purchaseLine($itemId, 0, '10', '50', '0', '2', FIXED)]);

        $this->assertGreaterThan(0, $receivingId);
        $this->assertSame($this->expectedLines([['inventory', '480.00', '0.00'], ['supplier', '0.00', '480.00']]), $this->standingPurchase($receivingId)[0]['lines']);
        $this->assertSame('48.0000', $this->itemCostOf($itemId));
    }

    public function testCreateTempTable_TakesAFixedDiscountOffEachUnit(): void
    {
        $itemId = $this->item('0.00');

        $receivingId = $this->buy([$this->purchaseLine($itemId, 0, '10', '50', '0', '2', FIXED)]);

        $row = $this->reportRowOf($receivingId);

        $this->assertSame('480.0000', bcadd((string) $row->total, '0', 4));
        $this->assertSame('480.0000', bcadd((string) $row->subtotal, '0', 4));
    }

    private function reportRowOf(int $receivingId): object
    {
        $this->accountingDb()->query('DROP TEMPORARY TABLE IF EXISTS ' . $this->accountingDb()->prefixTable('receivings_items_temp'));
        $this->purchases->create_temp_table(['receiving_id' => $receivingId]);

        return $this->accountingDb()->table('receivings_items_temp')->get()->getRow();
    }

    public function testPostCompleted_KeepsTheMeaningOfAFixedDiscountSavedForTheWholeLine(): void
    {
        $itemId = $this->item('0.00');

        $receivingId = $this->buy([$this->purchaseLine($itemId, 0, '10', '50', '0', '20', FIXED_LINE)]);

        $this->assertGreaterThan(0, $receivingId);
        $this->assertSame($this->expectedLines([['inventory', '480.00', '0.00'], ['supplier', '0.00', '480.00']]), $this->standingPurchase($receivingId)[0]['lines']);
        $this->assertSame('48.0000', $this->itemCostOf($itemId));
    }

    public function testCreateTempTable_KeepsTheMeaningOfAFixedDiscountSavedForTheWholeLine(): void
    {
        $itemId = $this->item('0.00');

        $receivingId = $this->buy([$this->purchaseLine($itemId, 0, '10', '50', '0', '20', FIXED_LINE)]);

        $row = $this->reportRowOf($receivingId);

        $this->assertSame('480.0000', bcadd((string) $row->total, '0', 4));
        $this->assertSame('20.00', (string) $row->discount);
        $this->assertSame('2', (string) $row->discount_type);
    }

    public function testCreateTempTable_TakesTheWholeLineDiscountOffTheUnitsOfAReturnToo(): void
    {
        $itemId    = $this->item('0.00');
        $receiving = $this->buy([$this->purchaseLine($itemId, 0, '10', '50', '0', '20', FIXED_LINE)]);
        $returnId  = $this->buy([$this->purchaseLine($itemId, 0, '-10', '50', '0', '20', FIXED_LINE)], -1, null, $receiving);

        $this->assertSame('-480.0000', bcadd((string) $this->reportRowOf($returnId)->total, '0', 4));
    }

    public function testPostCompleted_PostsAPartialReturnWithAWholeLineDiscountAtWhatTheOldRulesPostedAndTheReportShowsTheSame(): void
    {
        $itemId = $this->item('0.00');
        $origin = $this->buy([$this->purchaseLine($itemId, 0, '10', '50', '15', '20', FIXED_LINE)]);
        $return = $this->buy([$this->purchaseLine($itemId, 0, '-4', '50', '15', '8', FIXED_LINE)], -1, null, $origin);

        $this->assertGreaterThan(0, $return);

        $net      = bcsub(bcmul('4', '50', 4), '8', 4);
        $tax      = bcmul($net, '0.15', 4);
        $supplier = bcadd($net, $tax, 2);
        $amounts  = $this->amountsOfPurchase($return);

        $this->assertSame('192.0000', $net);
        $this->assertSame($supplier, $amounts['supplier']);
        $this->assertSame(bcadd($tax, '0', 2), $amounts['tax_input']);

        $row = $this->reportRowOf($return);

        $this->assertSame('-' . $net, bcadd((string) $row->total, '0', 4));
        $this->assertSame($supplier, bcadd(bcadd(ltrim((string) $row->total, '-'), '0', 4), $tax, 2));
    }

    /**
     * A treasury the employee who buys works from.
     */
    private function drawerOfTheBuyer(): int
    {
        $this->accountingDb()->table('grants')->insert(['person_id' => $this->employeeId, 'permission_id' => BranchGuard::ALL_BRANCHES, 'menu_group' => 'home']);

        $treasuries = new TreasuryService();
        $drawer     = $treasuries->create(['name' => 'Buyer drawer', 'kind' => 'cash']);

        $treasuries->setDefaultFor($this->employeeId, $drawer);

        return $drawer;
    }

    public function testPostCompleted_PaysAPurchaseMadeAtOnceFromTheTreasuryTheBuyerWorksFrom(): void
    {
        $drawer = $this->drawerOfTheBuyer();
        $itemId = $this->item('0.00');
        $id     = $this->buy([$this->purchaseLine($itemId, 0, '2', '50')], -1, lang('Sales.cash'));
        $stored = $this->accountingDb()->table('receivings')->where('receiving_id', $id)->get()->getRow();
        $paid   = array_values(array_filter($this->purchaseJournals($id), static fn (array $journal): bool => $journal['source'] === 'purchase_payment'))[0];

        $this->assertSame($drawer, (int) $stored->treasury_id);
        $this->assertSame([$this->supplierAccount() . ':100.00:0.00', (new TreasuryService())->accountOf($drawer) . ':0.00:100.00'], $paid['lines']);
        $this->assertSame('-100.0000', (new TreasuryService())->position($drawer)->balance);
        $this->assertStockTies();
    }

    public function testPostCompleted_PaysAPurchaseMadeAtOnceFromTheAccountOfItsTypeWhenTheBuyerHasNoTreasury(): void
    {
        $itemId = $this->item('0.00');
        $id     = $this->buy([$this->purchaseLine($itemId, 0, '2', '50')], -1, lang('Sales.cash'));

        $this->assertNull($this->accountingDb()->table('receivings')->where('receiving_id', $id)->get()->getRow()->treasury_id);
        $this->assertSame('-100.00', $this->balance($this->accountId('cash')));
    }

    public function testPostCompleted_PutsNoTreasuryOnAPurchaseLeftOnCredit(): void
    {
        $this->drawerOfTheBuyer();

        $itemId = $this->item('0.00');
        $id     = $this->buy([$this->purchaseLine($itemId, 0, '2', '50')]);

        $this->assertNull($this->accountingDb()->table('receivings')->where('receiving_id', $id)->get()->getRow()->treasury_id);
    }

    public function testUpdate_PaysASupplierFromTheTreasuryTheBuyerWorksFromAndFromAnotherOneWhenItIsNamed(): void
    {
        $treasuries = new TreasuryService();
        $drawer     = $this->drawerOfTheBuyer();
        $bank       = $treasuries->create(['name' => 'Gulf Bank', 'kind' => 'bank', 'bank_name' => 'Gulf Bank', 'account_number' => '1234567890']);
        $itemId     = $this->item('0.00');
        $id         = $this->buy([$this->purchaseLine($itemId, 0, '4', '50')]);
        $first      = $this->payPurchase($id, '60.00');

        $this->assertTrue($this->purchases->update($id, ['comment' => 'bank', 'payments' => [
            ['payment_id' => NEW_ENTRY, 'payment_type' => lang('Sales.bank_transfer'), 'payment_amount' => '40.00', 'employee_id' => $this->employeeId, 'payment_status' => 'completed', 'reference_code' => '', 'treasury_id' => $bank],
        ]]));

        $payments = $this->accountingDb()->table('receivings_payments')->where('receiving_id', $id)->orderBy('payment_id', 'ASC')->get()->getResult();

        $this->assertSame([$drawer, $bank], array_map(static fn (object $payment): int => (int) $payment->treasury_id, $payments));
        $this->assertSame('-60.0000', $treasuries->position($drawer)->balance);
        $this->assertSame('-40.0000', $treasuries->position($bank)->balance);
        $this->assertSame('0.00', $this->balance($this->accountId('cash')));
        $this->assertGreaterThan(0, $first);
    }

    public function testUpdate_MovesASupplierPaymentToAnotherTreasuryByReversingAndPostingItAgain(): void
    {
        $treasuries = new TreasuryService();
        $bank       = $treasuries->create(['name' => 'Gulf Bank', 'kind' => 'bank', 'bank_name' => 'Gulf Bank', 'account_number' => '1234567890']);
        $itemId     = $this->item('0.00');
        $id         = $this->buy([$this->purchaseLine($itemId, 0, '4', '50')]);
        $paymentId  = $this->payPurchase($id, '60.00');

        $this->assertSame('-60.00', $this->balance($this->accountId('cash')));
        $this->assertTrue($this->changePayment($id, $paymentId, ['treasury_id' => $bank]));
        $this->assertSame('0.00', $this->balance($this->accountId('cash')));
        $this->assertSame('-60.0000', $treasuries->position($bank)->balance);
        $this->assertSame(['reversed', 'posted'], array_map(static fn (object $journal): string => $journal->status, $this->paymentJournals($paymentId)));
    }

    /**
     * A treasury of the buyer's branch that only the employees given may withdraw from.
     *
     * @param list<int> $employees
     */
    private function drawerOpenOnlyTo(array $employees, string $direction = 'withdraw'): int
    {
        $this->accountingDb()->table('grants')->insert(['person_id' => $this->employeeId, 'permission_id' => BranchGuard::ALL_BRANCHES, 'menu_group' => 'home']);

        return (new TreasuryService())->create(['name' => 'Scoped drawer', 'kind' => 'cash', $direction . '_scope' => 'staff', $direction . '_members' => $employees]);
    }

    public function testUpdate_RefusesAPaymentFromATreasuryTheBuyerMayNotWithdrawFromAndSavesNothing(): void
    {
        $drawer = $this->drawerOpenOnlyTo([$this->employeeWithGrants([])]);
        $itemId = $this->item('0.00');
        $id     = $this->buy([$this->purchaseLine($itemId, 0, '4', '50')]);

        $this->assertFalse($this->purchases->update($id, ['comment' => 'paid', 'payments' => [
            ['payment_id' => NEW_ENTRY, 'payment_type' => lang('Sales.cash'), 'payment_amount' => '60.00', 'employee_id' => $this->employeeId, 'payment_status' => 'completed', 'reference_code' => '', 'treasury_id' => $drawer],
        ]]));

        $this->assertSame(AccountingError::TreasuryWithdrawDenied, $this->purchases->accountingFailure()->error);
        $this->assertSame(0, $this->accountingDb()->table('receivings_payments')->where('receiving_id', $id)->countAllResults());
        $this->assertSame('0.0000', (new TreasuryService())->position($drawer)->balance);
    }

    public function testUpdate_AcceptsAPaymentFromATreasuryTheBuyerMayWithdrawFrom(): void
    {
        $drawer = $this->drawerOpenOnlyTo([$this->employeeId]);
        $itemId = $this->item('0.00');
        $id     = $this->buy([$this->purchaseLine($itemId, 0, '4', '50')]);

        $this->assertTrue($this->purchases->update($id, ['comment' => 'paid', 'payments' => [
            ['payment_id' => NEW_ENTRY, 'payment_type' => lang('Sales.cash'), 'payment_amount' => '60.00', 'employee_id' => $this->employeeId, 'payment_status' => 'completed', 'reference_code' => '', 'treasury_id' => $drawer],
        ]]));

        $this->assertSame('-60.0000', (new TreasuryService())->position($drawer)->balance);
    }

    public function testUpdate_PaysFromTheMainTreasuryWhenTheTreasuryTheBuyerWorksFromIsClosedToThem(): void
    {
        $drawer = $this->drawerOpenOnlyTo([$this->employeeWithGrants([])]);

        (new TreasuryService())->setDefaultFor($this->employeeId, $drawer);

        $itemId    = $this->item('0.00');
        $id        = $this->buy([$this->purchaseLine($itemId, 0, '4', '50')]);
        $paymentId = $this->payPurchase($id, '60.00');

        $this->assertNull($this->accountingDb()->table('receivings_payments')->where('payment_id', $paymentId)->get()->getRow()->treasury_id);
        $this->assertSame('-60.00', $this->balance($this->accountId('cash')));
    }

    public function testUpdate_RefusesAPaymentThatWouldComeOutOfTheMainTreasuryWhenItIsClosedToTheBuyer(): void
    {
        $treasuries = new TreasuryService();
        $main       = $treasuries->ensureMain();

        $treasuries->update($main, ['name' => 'Main', 'kind' => 'cash', 'withdraw_scope' => 'staff', 'withdraw_members' => [$this->employeeWithGrants([])]]);

        $itemId = $this->item('0.00');
        $id     = $this->buy([$this->purchaseLine($itemId, 0, '4', '50')]);

        $this->assertFalse($this->purchases->update($id, ['comment' => 'paid', 'payments' => [
            ['payment_id' => NEW_ENTRY, 'payment_type' => lang('Sales.cash'), 'payment_amount' => '60.00', 'employee_id' => $this->employeeId, 'payment_status' => 'completed', 'reference_code' => ''],
        ]]));
        $this->assertSame(AccountingError::TreasuryWithdrawDenied, $this->purchases->accountingFailure()->error);
    }

    public function testPostCompleted_RefusesAPurchaseMadeAtOnceWhenTheMainTreasuryItIsPaidFromIsClosedToTheBuyer(): void
    {
        $treasuries = new TreasuryService();
        $main       = $treasuries->ensureMain();

        $treasuries->update($main, ['name' => 'Main', 'kind' => 'cash', 'withdraw_scope' => 'staff', 'withdraw_members' => [$this->employeeWithGrants([])]]);

        $itemId = $this->item('0.00');
        $result = $this->buy([$this->purchaseLine($itemId, 0, '2', '50')], -1, lang('Sales.cash'));

        $this->assertSame(ACCOUNTING_FAILED, $result);
        $this->assertSame(AccountingError::TreasuryWithdrawDenied, $this->purchases->accountingFailure()->error);
        $this->assertSame(0, $this->accountingDb()->table('receivings')->where('employee_id', $this->employeeId)->countAllResults());
    }

    public function testUpdate_RefusesToRemoveASupplierPaymentFromATreasuryTheEditorMayNotDepositInto(): void
    {
        $drawer    = $this->drawerOpenOnlyTo([$this->employeeWithGrants([])], 'deposit');
        $itemId    = $this->item('0.00');
        $id        = $this->buy([$this->purchaseLine($itemId, 0, '4', '50')]);
        $paymentId = $this->payPurchase($id, '60.00');

        $this->assertTrue($this->changePayment($id, $paymentId, ['treasury_id' => $drawer]), 'Paying from it was not limited');
        $this->assertFalse($this->changePayment($id, $paymentId, ['payment_amount' => '0.00', 'treasury_id' => $drawer]));
        $this->assertSame(AccountingError::TreasuryDepositDenied, $this->purchases->accountingFailure()->error);
        $this->assertSame(1, $this->accountingDb()->table('receivings_payments')->where('payment_id', $paymentId)->countAllResults());
        $this->assertSame('-60.0000', (new TreasuryService())->position($drawer)->balance);
    }

    public function testUpdate_RemovesASupplierPaymentFromATreasuryTheEditorMayDepositInto(): void
    {
        $drawer    = $this->drawerOpenOnlyTo([$this->employeeId], 'deposit');
        $itemId    = $this->item('0.00');
        $id        = $this->buy([$this->purchaseLine($itemId, 0, '4', '50')]);
        $paymentId = $this->payPurchase($id, '60.00');

        $this->assertTrue($this->changePayment($id, $paymentId, ['treasury_id' => $drawer]));
        $this->assertTrue($this->changePayment($id, $paymentId, ['payment_amount' => '0.00', 'treasury_id' => $drawer]));
        $this->assertSame('0.0000', (new TreasuryService())->position($drawer)->balance);
    }

    public function testUpdate_RefusesToRemoveAnUnstampedSupplierPaymentThatLandedOnATreasuryThroughTheRoutingOfItsType(): void
    {
        $treasuries = new TreasuryService();
        $bank       = $treasuries->create(['name' => 'Routed bank', 'kind' => 'bank', 'bank_name' => 'Gulf Bank', 'account_number' => '1234567890', 'deposit_scope' => 'staff', 'deposit_members' => [$this->employeeWithGrants([])]]);

        $this->accountingDb()->table('accounting_routing')->where('routing_key', 'payment.cash')->update(['account_id' => $treasuries->accountOf($bank)]);

        $itemId    = $this->item('0.00');
        $id        = $this->buy([$this->purchaseLine($itemId, 0, '4', '50')]);
        $paymentId = $this->payPurchase($id, '60.00');

        $this->assertFalse($this->changePayment($id, $paymentId, ['payment_amount' => '0.00']));
        $this->assertSame(AccountingError::TreasuryDepositDenied, $this->purchases->accountingFailure()->error);
        $this->assertSame('-60.0000', $treasuries->position($bank)->balance);
    }

    public function testUpdate_RefusesToChangeTheAmountOfASupplierPaymentOfATreasuryClosedToTheEditorEvenWhenTheEditNamesNoTreasury(): void
    {
        $drawer    = $this->drawerOpenOnlyTo([$this->employeeId], 'withdraw');
        $itemId    = $this->item('0.00');
        $id        = $this->buy([$this->purchaseLine($itemId, 0, '4', '50')]);
        $paymentId = $this->payPurchase($id, '60.00');

        $this->assertTrue($this->changePayment($id, $paymentId, ['treasury_id' => $drawer]));

        $this->accountingDb()->table('treasury_scope_members')->where('treasury_id', $drawer)->delete();
        $this->accountingDb()->table('treasury_scope_members')->insert(['treasury_id' => $drawer, 'direction' => 'withdraw', 'member_id' => $this->employeeWithGrants([])]);

        $this->assertFalse($this->changePayment($id, $paymentId, ['payment_amount' => '90.00']));
        $this->assertSame(AccountingError::TreasuryWithdrawDenied, $this->purchases->accountingFailure()->error);
        $this->assertSame('-60.0000', (new TreasuryService())->position($drawer)->balance);
    }

    public function testUpdate_RefusesToLowerASupplierPaymentOfATreasuryTheEditorMayNotDepositInto(): void
    {
        $drawer    = $this->drawerOpenOnlyTo([$this->employeeWithGrants([])], 'deposit');
        $itemId    = $this->item('0.00');
        $id        = $this->buy([$this->purchaseLine($itemId, 0, '4', '50')]);
        $paymentId = $this->payPurchase($id, '60.00');

        $this->assertTrue($this->changePayment($id, $paymentId, ['treasury_id' => $drawer]), 'Paying from it was not limited');
        $this->assertFalse($this->changePayment($id, $paymentId, ['payment_amount' => '30.00']));
        $this->assertSame(AccountingError::TreasuryDepositDenied, $this->purchases->accountingFailure()->error);
        $this->assertSame('-60.0000', (new TreasuryService())->position($drawer)->balance);
        $this->assertSame('60.00', $this->accountingDb()->table('receivings_payments')->where('payment_id', $paymentId)->get()->getRow()->payment_amount);
    }

    public function testUpdate_LowersASupplierPaymentOfATreasuryTheEditorMayDepositInto(): void
    {
        $drawer    = $this->drawerOpenOnlyTo([$this->employeeId], 'deposit');
        $itemId    = $this->item('0.00');
        $id        = $this->buy([$this->purchaseLine($itemId, 0, '4', '50')]);
        $paymentId = $this->payPurchase($id, '60.00');

        $this->assertTrue($this->changePayment($id, $paymentId, ['treasury_id' => $drawer]));
        $this->assertTrue($this->changePayment($id, $paymentId, ['payment_amount' => '30.00']));
        $this->assertSame('-30.0000', (new TreasuryService())->position($drawer)->balance);
    }

    public function testUpdate_RefusesToTakeASupplierPaymentOfATreasuryOutOfTheBooksByChangingItsStatus(): void
    {
        $drawer    = $this->drawerOpenOnlyTo([$this->employeeWithGrants([])], 'deposit');
        $itemId    = $this->item('0.00');
        $id        = $this->buy([$this->purchaseLine($itemId, 0, '4', '50')]);
        $paymentId = $this->payPurchase($id, '60.00');

        $this->assertTrue($this->changePayment($id, $paymentId, ['treasury_id' => $drawer]), 'Paying from it was not limited');
        $this->assertFalse($this->changePayment($id, $paymentId, ['payment_status' => 'draft']));
        $this->assertSame(AccountingError::TreasuryDepositDenied, $this->purchases->accountingFailure()->error);
        $this->assertSame('-60.0000', (new TreasuryService())->position($drawer)->balance);
        $this->assertSame('completed', $this->accountingDb()->table('receivings_payments')->where('payment_id', $paymentId)->get()->getRow()->payment_status);
    }

    public function testUpdate_KeepsTheTreasuryTheSupplierPaymentCarriesEvenWhenItBelongsToAnotherBranchButRefusesToMoveItThere(): void
    {
        $treasuries = new TreasuryService();
        $drawer     = $treasuries->create(['name' => 'Carried drawer', 'kind' => 'cash']);
        $other      = $treasuries->create(['name' => 'Other drawer', 'kind' => 'cash']);
        $itemId     = $this->item('0.00');
        $id         = $this->buy([$this->purchaseLine($itemId, 0, '4', '50')]);
        $paymentId  = $this->payPurchase($id, '60.00');

        $this->assertTrue($this->changePayment($id, $paymentId, ['treasury_id' => $drawer]));

        $branch = $this->createBranch('B2');

        $this->accountingDb()->table('treasuries')->whereIn('treasury_id', [$drawer, $other])->update(['branch_id' => $branch]);

        $this->assertTrue($this->changePayment($id, $paymentId, ['treasury_id' => $drawer, 'reference_code' => 'only a note']));
        $this->assertFalse($this->changePayment($id, $paymentId, ['treasury_id' => $other]));
        $this->assertSame(AccountingError::TreasuryNotFound, $this->purchases->accountingFailure()->error);
        $this->assertSame($drawer, (int) $this->accountingDb()->table('receivings_payments')->where('payment_id', $paymentId)->get()->getRow()->treasury_id);
    }

    public function testUpdate_RefusesToCompleteASupplierPaymentOutOfATreasuryTheEditorMayNotWithdrawFrom(): void
    {
        $drawer    = $this->drawerOpenOnlyTo([$this->employeeId]);
        $itemId    = $this->item('0.00');
        $id        = $this->buy([$this->purchaseLine($itemId, 0, '4', '50')]);
        $paymentId = $this->payPurchase($id, '60.00', null, 'review');

        $this->assertTrue($this->changePayment($id, $paymentId, ['treasury_id' => $drawer]));

        $this->accountingDb()->table('treasury_scope_members')->where('treasury_id', $drawer)->delete();
        $this->accountingDb()->table('treasury_scope_members')->insert(['treasury_id' => $drawer, 'direction' => 'withdraw', 'member_id' => $this->employeeWithGrants([])]);

        $this->assertFalse($this->changePayment($id, $paymentId, ['payment_status' => 'completed']));
        $this->assertSame(AccountingError::TreasuryWithdrawDenied, $this->purchases->accountingFailure()->error);
        $this->assertSame('0.0000', (new TreasuryService())->position($drawer)->balance);
        $this->assertSame('review', $this->accountingDb()->table('receivings_payments')->where('payment_id', $paymentId)->get()->getRow()->payment_status);
    }

    public function testDeleteValue_RefusesToDeleteAPurchaseWhoseSupplierPaymentCameOutOfATreasuryTheEditorMayNotDepositInto(): void
    {
        $drawer    = $this->drawerOpenOnlyTo([$this->employeeWithGrants([])], 'deposit');
        $itemId    = $this->item('0.00');
        $id        = $this->buy([$this->purchaseLine($itemId, 0, '4', '50')]);
        $paymentId = $this->payPurchase($id, '60.00');

        $this->assertTrue($this->changePayment($id, $paymentId, ['treasury_id' => $drawer]));
        $this->assertFalse($this->purchases->delete_value($id, $this->employeeId));
        $this->assertSame(AccountingError::TreasuryDepositDenied, $this->purchases->accountingFailure()->error);
        $this->assertSame('-60.0000', (new TreasuryService())->position($drawer)->balance);
        $this->assertSame(1, $this->accountingDb()->table('receivings')->where('receiving_id', $id)->countAllResults(), 'The purchase stands');
    }

    public function testUpdate_RefusesToMoveASupplierPaymentToATreasuryTheBuyerMayNotWithdrawFrom(): void
    {
        $itemId    = $this->item('0.00');
        $id        = $this->buy([$this->purchaseLine($itemId, 0, '4', '50')]);
        $paymentId = $this->payPurchase($id, '60.00');
        $drawer    = $this->drawerOpenOnlyTo([$this->employeeWithGrants([])]);

        $this->assertFalse($this->changePayment($id, $paymentId, ['treasury_id' => $drawer]));
        $this->assertSame(AccountingError::TreasuryWithdrawDenied, $this->purchases->accountingFailure()->error);
        $this->assertNull($this->accountingDb()->table('receivings_payments')->where('payment_id', $paymentId)->get()->getRow()->treasury_id);
    }

    public function testUpdate_AsksTheDepositScopeOfTheTreasuryASupplierPaymentIsTakenOff(): void
    {
        $drawer    = $this->drawerOpenOnlyTo([$this->employeeWithGrants([])], 'deposit');
        $itemId    = $this->item('0.00');
        $id        = $this->buy([$this->purchaseLine($itemId, 0, '4', '50')]);
        $paymentId = $this->payPurchase($id, '60.00');

        $this->assertTrue($this->changePayment($id, $paymentId, ['treasury_id' => $drawer]), 'Paying from it was not limited');
        $this->assertFalse($this->changePayment($id, $paymentId, ['treasury_id' => 0]));
        $this->assertSame(AccountingError::TreasuryDepositDenied, $this->purchases->accountingFailure()->error);
        $this->assertSame($drawer, (int) $this->accountingDb()->table('receivings_payments')->where('payment_id', $paymentId)->get()->getRow()->treasury_id);
    }

    public function testUpdate_AsksNothingOfAnEditThatChangesNeitherTheAmountNorTheTreasuryOfASupplierPayment(): void
    {
        $drawer    = $this->drawerOpenOnlyTo([$this->employeeId]);
        $itemId    = $this->item('0.00');
        $id        = $this->buy([$this->purchaseLine($itemId, 0, '4', '50')]);
        $paymentId = $this->payPurchase($id, '60.00');

        $this->assertTrue($this->changePayment($id, $paymentId, ['treasury_id' => $drawer]));

        $this->accountingDb()->table('treasury_scope_members')->where('treasury_id', $drawer)->delete();
        $this->accountingDb()->table('treasury_scope_members')->insert(['treasury_id' => $drawer, 'direction' => 'withdraw', 'member_id' => $this->employeeWithGrants([])]);

        $this->assertTrue($this->changePayment($id, $paymentId, ['treasury_id' => $drawer, 'reference_code' => 'only a note']));
    }

    public function testPostCompleted_CountsThePacksOfALineAsTheUnitsInThem(): void
    {
        $itemId = $this->item('0.00');

        $receivingId = $this->buy([$this->purchaseLine($itemId, 0, '2', '5', '0', '0', PERCENT, self::LOCATION_ID, '12')]);

        $this->assertGreaterThan(0, $receivingId);
        $this->assertSame($this->expectedLines([['inventory', '120.00', '0.00'], ['supplier', '0.00', '120.00']]), $this->standingPurchase($receivingId)[0]['lines']);
        $this->assertSame(['quantity' => '24.000', 'value' => '120.00'], $this->stockOf($itemId));
        $this->assertSame('24.000', $this->onHand($itemId));
        $this->assertSame('5.0000', $this->itemCostOf($itemId));
    }

    public function testPostCompleted_KeepsTheTaxUnroundedAndTheTotalToTwoDecimalsWithTheStockTakingTheRounding(): void
    {
        $itemId = $this->item('0.00');

        $receivingId = $this->buy([$this->purchaseLine($itemId, 0, '3', '33.33', '15')]);

        $this->assertGreaterThan(0, $receivingId);

        $journal = $this->standingPurchase($receivingId)[0];

        $this->assertSame([$this->stockAccount() . ':99.9915:0.0000', $this->accountId('tax_input') . ':14.9985:0.0000', $this->supplierAccount() . ':0.0000:114.9900'], $this->exactLines($journal['id']));
        $this->assertSame(['quantity' => '3.000', 'value' => '99.99'], $this->stockOf($itemId), 'The movements carry the same 99.9915 the account holds');
        $this->assertStockTies();
    }

    public function testPostCompleted_KeepsTheMovementsOfGoodsThatCostNothingAndPostsNoJournal(): void
    {
        $itemId = $this->item('0.00');

        $receivingId = $this->buy([$this->purchaseLine($itemId, 0, '10', '0')]);

        $this->assertGreaterThan(0, $receivingId);
        $this->assertSame([], $this->purchaseJournals($receivingId));
        $this->assertSame(['quantity' => '10.000', 'value' => '0.00'], $this->stockOf($itemId));
        $this->assertSame('10.000', $this->onHand($itemId));
        $this->assertStockTies();
    }

    public function testPostCompleted_PostsInTheBranchOfTheStockLocation(): void
    {
        $itemId = $this->item('0.00');

        $receivingId = $this->buy([$this->purchaseLine($itemId, 0, '5', '10')]);

        $this->assertSame(1, $this->purchases->getBranchId($receivingId));
        $this->assertSame(1, (int) $this->accountingDb()->table('accounting_journals')->where('source_type', 'purchase')->where('source_id', $receivingId)->get()->getRow()->branch_id);
        $this->assertSame(1, (int) $this->accountingDb()->table('accounting_stock_entries')->where('source_id', $receivingId)->get()->getRow()->branch_id);
    }

    public function testPostCompleted_PostsOnTheDayOfThePurchase(): void
    {
        $itemId = $this->item('0.00');

        $receivingId = $this->buy([$this->purchaseLine($itemId, 0, '5', '10')]);

        $this->assertSame(date('Y-m-d'), $this->standingPurchase($receivingId)[0]['date']);
    }

    // ---------------------------------------------------------------------------------------
    // The weighted average
    // ---------------------------------------------------------------------------------------

    public function testSaveValue_BringsTheCostOfTheItemToTheWeightedAverageOfTheStockAndThePurchase(): void
    {
        $itemId = $this->item('0.00');

        $this->assertGreaterThan(0, $this->buy([$this->purchaseLine($itemId, 0, '10', '50')]));
        $this->assertSame('50.0000', $this->itemCostOf($itemId));

        $this->assertGreaterThan(0, $this->buy([$this->purchaseLine($itemId, 0, '10', '70')]));
        $this->assertSame('60.0000', $this->itemCostOf($itemId));

        $this->assertSame(['quantity' => '20.000', 'value' => '1200.00'], $this->stockOf($itemId));
    }

    public function testSaveValue_WeighsEveryStockLocationOfTheItem(): void
    {
        $itemId = $this->item('0.00');
        $other  = $this->anotherLocation();

        $this->assertGreaterThan(0, $this->buy([$this->purchaseLine($itemId, 0, '10', '50')]));
        $this->assertGreaterThan(0, $this->buy([$this->purchaseLine($itemId, 0, '10', '80', '0', '0', PERCENT, $other)]));

        $this->assertSame('65.0000', $this->itemCostOf($itemId), 'Twenty units in two locations, 500 and 800');
        $this->assertSame(['quantity' => '10.000', 'value' => '800.00'], $this->stockOf($itemId, $other));
    }

    public function testSaveValue_LeavesTheCostAloneWhenGoodsGoBackAndWhenStockMoves(): void
    {
        ['item' => $itemId, 'purchase' => $purchase] = $this->stockOfTwenty();
        $other = $this->anotherLocation();

        $this->assertGreaterThan(0, $this->buy([$this->purchaseLine($itemId, 0, '-2', '70', '15')], -1, null, $purchase));
        $this->assertSame('66.0000', $this->itemCostOf($itemId));

        $this->assertGreaterThan(0, $this->buy([$this->purchaseLine($itemId, 0, '3', '0', '0', '0', PERCENT, $other), $this->purchaseLine($itemId, 1, '-3', '0')], null, null));
        $this->assertSame('66.0000', $this->itemCostOf($itemId));
    }

    public function testSaveValue_GivesTheItemTheCostOfTheNewUnitsWhenItsStockIsOwed(): void
    {
        $itemId = $this->item('99.00');

        $this->accountingDb()->table('item_quantities')->insert(['item_id' => $itemId, 'location_id' => self::LOCATION_ID, 'quantity' => '-4']);

        $this->assertGreaterThan(0, $this->buy([$this->purchaseLine($itemId, 0, '10', '7.50')]));
        $this->assertSame('7.5000', $this->itemCostOf($itemId));
    }

    public function testSaveValue_KeepsTheAverageToTheEndOfTheSumAndRoundsItOnceWhenItIsStored(): void
    {
        $itemId = $this->item('1.00');

        $this->accountingDb()->table('item_quantities')->insert(['item_id' => $itemId, 'location_id' => self::LOCATION_ID, 'quantity' => '3']);

        $this->assertGreaterThan(0, $this->buy([$this->purchaseLine($itemId, 0, '4', '10')]));
        $this->assertSame('6.142857', $this->exactCostOf($itemId), '43/7 = 6.142857142, which the cost column keeps at six decimals');
    }

    public function testSaveValue_AveragesWhateverTheSettingSaysWhileTheBooksAreOn(): void
    {
        $itemId = $this->item('0.00');

        $this->setConfigValue('receiving_calculate_average_price', '');

        $this->assertGreaterThan(0, $this->buy([$this->purchaseLine($itemId, 0, '10', '50')]));
        $this->assertGreaterThan(0, $this->buy([$this->purchaseLine($itemId, 0, '10', '70')]));

        $this->assertSame('60.0000', $this->itemCostOf($itemId));
    }

    // ---------------------------------------------------------------------------------------
    // Goods going back to the supplier
    // ---------------------------------------------------------------------------------------

    /**
     * @return array<string, array{array<string, mixed>}>
     */
    public static function returnProvider(): array
    {
        return ParityFixture::cases('T-11', 'purchase_return');
    }

    /**
     * Fixture T-11: goods go back at what the stock of the item is worth by its movements, 65.70,
     * and not at the 66.00 the item carries, and what the supplier gives for them beyond that goes
     * to the cost of sales, as in the reference system.
     *
     * @param array<string, mixed> $case
     */
    #[DataProvider('returnProvider')]
    public function testSaveValue_GivesGoodsBackAtTheAverageOfTheMovementsAsTheReferenceSystemDoes(array $case): void
    {
        ['item' => $itemId, 'purchase' => $purchase] = $this->stockOfTwenty();
        $return = $case['inputs']['return'];

        $receivingId = $this->buy([$this->purchaseLine($itemId, 0, '-' . $return['quantity'], $return['price'], $return['tax_percent'])], -1, null, $purchase);

        $this->assertGreaterThan(0, $receivingId);
        $this->assertSame('exact', $case['classification']);

        $expected = $case['expected'];
        $lines    = [['supplier', $expected['supplier'], '0.00'], ['tax_input', '0.00', $expected['tax']], ['inventory', '0.00', $expected['inventory']], ['cogs', '0.00', $expected['cost_of_sales_credit']]];

        $this->assertSame($this->expectedLines($lines), $this->standingPurchase($receivingId)[0]['lines']);
        $this->assertSame($this->expectedLines($lines), array_map(static fn (string $line): string => $line, $this->expectedLines([['supplier', $case['reference']['supplier'], '0.00'], ['tax_input', '0.00', $case['reference']['tax']], ['inventory', '0.00', $case['reference']['inventory']], ['cogs', '0.00', $case['reference']['cost_of_sales_credit']]])), 'The reference system must hold the same journal');
        $this->assertSame(bcsub('1314.00', $expected['inventory'], 2), $this->stockOf($itemId)['value']);
        $this->assertStockTies();
    }

    public function testSaveValue_GivesGoodsBackAtTheCostTheItemCarriesWhenTheMovementsDoNotAccountForTheShelves(): void
    {
        $itemId = $this->item('66.00');
        $origin = $this->buy([$this->purchaseLine($itemId, 0, '5', '50')]);

        $this->accountingDb()->table('items')->where('item_id', $itemId)->update(['cost_price' => '66.00']);
        $this->accountingDb()->table('item_quantities')->where('item_id', $itemId)->update(['quantity' => '20']);

        $receivingId = $this->buy([$this->purchaseLine($itemId, 0, '-2', '70', '15')], -1, null, $origin);

        $this->assertGreaterThan(0, $receivingId);
        $this->assertSame($this->expectedLines([['supplier', '161.00', '0.00'], ['tax_input', '0.00', '21.00'], ['inventory', '0.00', '132.00'], ['cogs', '0.00', '8.00']]), $this->standingPurchase($receivingId)[0]['lines']);
    }

    public function testSaveValue_PostsTheRefundWhenTheSupplierPaysTheReturnBackAtOnce(): void
    {
        $case = ParityFixture::cases('T-12', 'purchase_journal')['return-refunded-in-cash'][0];
        ['item' => $itemId, 'purchase' => $purchase] = $this->stockOfTwenty();
        $return = $case['inputs']['return'];

        $receivingId = $this->buy([$this->purchaseLine($itemId, 0, '-' . $return['quantity'], $return['price'], $return['tax_percent'])], -1, lang('Sales.cash'), $purchase);

        $this->assertGreaterThan(0, $receivingId);

        $roles = $this->roles();

        foreach (['return' => 'purchase', 'refund' => 'purchase_payment'] as $step => $source) {
            $wanted = array_values(array_filter($case['expected']['journals'], static fn (array $journal): bool => $journal['step'] === $step))[0];

            $this->assertSame(
                array_map(static fn (array $line): string => $roles[$line[0]] . ':' . $line[1] . ':' . $line[2], $wanted['lines']),
                $this->standingPurchase($receivingId, $source)[0]['lines'],
                $step,
            );
        }

        $this->assertStockTies();
    }

    /**
     * @return array<string, array{array<string, mixed>}>
     */
    public static function purchaseJournalCases(): array
    {
        return array_filter(ParityFixture::cases('T-12', 'purchase_journal'), static fn (array $set): bool => isset($set[0]['inputs']['lines']));
    }

    /**
     * @param array<string, mixed> $case
     */
    #[DataProvider('purchaseJournalCases')]
    public function testSaveValue_PostsTheJournalsOfThePurchaseTheReferenceSystemPosted(array $case): void
    {
        $itemId = $this->item('0.00');
        $second = $this->item('0.00');
        $lines  = [];

        foreach ($case['inputs']['lines'] as $index => $line) {
            $lines[] = $this->purchaseLine($index === 0 ? $itemId : $second, $index, $line['quantity'], $line['price'], $case['inputs']['tax_percent']);
        }

        $receivingId = $this->buy($lines, -1, $case['inputs']['payment'] === 'cash' ? lang('Sales.cash') : null);

        $this->assertGreaterThan(0, $receivingId);

        foreach ($case['expected']['journals'] as $journal) {
            $source = $journal['step'] === 'purchase' ? 'purchase' : 'purchase_payment';

            $this->assertSame($this->expectedLines($journal['lines']), $this->standingPurchase($receivingId, $source)[0]['lines'], $case['id'] . ' ' . $journal['step']);
        }

        $this->assertStockTies();
    }

    public function testSaveValue_RefusesAReturnThatSaysNoPurchaseAndLeavesNothingOfIt(): void
    {
        ['item' => $itemId] = $this->stockOfTwenty();
        $receivings = $this->countRows('receivings');
        $journals   = $this->countRows('accounting_journals');
        $entries    = $this->countRows('accounting_stock_entries');

        $result = $this->buy([$this->purchaseLine($itemId, 0, '-2', '70', '15')]);

        $this->assertRefusedWith(PostingError::PurchaseReturnOriginRequired, $result);
        $this->assertSame($receivings, $this->countRows('receivings'));
        $this->assertSame($journals, $this->countRows('accounting_journals'));
        $this->assertSame($entries, $this->countRows('accounting_stock_entries'));
        $this->assertSame('20.000', $this->onHand($itemId));
    }

    public function testSaveValue_RefusesAReturnOfMoreThanThePurchaseBroughtIn(): void
    {
        ['item' => $itemId, 'purchase' => $purchase] = $this->stockOfTwenty();

        $result = $this->buy([$this->purchaseLine($itemId, 0, '-21', '70')], -1, null, $purchase);

        $this->assertRefusedWith(PostingError::PurchaseReturnExceedsOrigin, $result);
        $this->assertSame('20.000', $this->failure()->context['max'] ?? '');
        $this->assertSame('20.000', $this->onHand($itemId));
        $this->assertStockTies();
    }

    public function testSaveValue_CountsWhatEarlierReturnsGaveBackAgainstThePurchase(): void
    {
        ['item' => $itemId, 'purchase' => $purchase] = $this->stockOfTwenty();

        $this->assertGreaterThan(0, $this->buy([$this->purchaseLine($itemId, 0, '-15', '70')], -1, null, $purchase));

        $result = $this->buy([$this->purchaseLine($itemId, 0, '-6', '70')], -1, null, $purchase);

        $this->assertRefusedWith(PostingError::PurchaseReturnExceedsOrigin, $result);
        $this->assertSame('5.000', $this->failure()->context['max'] ?? '');
        $this->assertSame('5.000', $this->onHand($itemId));
        $this->assertGreaterThan(0, $this->buy([$this->purchaseLine($itemId, 0, '-5', '70')], -1, null, $purchase), 'What is left can still go back');
        $this->assertSame('0.000', $this->onHand($itemId));
        $this->assertStockTies();
    }

    public function testSaveValue_AllowsAReturnThatGivesBackExactlyWhatWasBoughtInSeveralLines(): void
    {
        $itemId   = $this->item('0.00');
        $purchase = $this->buy([$this->purchaseLine($itemId, 0, '10', '50')]);

        $this->assertGreaterThan(0, $this->buy([$this->purchaseLine($itemId, 0, '-4', '50')], -1, null, $purchase));
        $this->assertGreaterThan(0, $this->buy([$this->purchaseLine($itemId, 0, '-6', '50')], -1, null, $purchase));
        $this->assertSame('0.000', $this->onHand($itemId));
        $this->assertSame(['quantity' => '0.000', 'value' => '0.00'], $this->stockOf($itemId));
    }

    public function testSaveValue_RefusesAReturnOfAnItemThePurchaseDidNotHave(): void
    {
        ['purchase' => $purchase] = $this->stockOfTwenty();
        $other = $this->item('0.00');

        $this->assertGreaterThan(0, $this->buy([$this->purchaseLine($other, 0, '5', '10')]));

        $result = $this->buy([$this->purchaseLine($other, 0, '-1', '10')], -1, null, $purchase);

        $this->assertRefusedWith(PostingError::PurchaseReturnLineNotOnOrigin, $result);
    }

    public function testSaveValue_RefusesAReturnToAnotherSupplierThanTheOneThePurchaseCameFrom(): void
    {
        ['item' => $itemId, 'purchase' => $purchase] = $this->stockOfTwenty();

        $result = $this->buy([$this->purchaseLine($itemId, 0, '-2', '70')], $this->supplier('Another supplier'), null, $purchase);

        $this->assertRefusedWith(PostingError::PurchaseReturnOriginInvalid, $result);
    }

    public function testSaveValue_RefusesAReturnOfAPurchaseThatDoesNotExistAndOfAReturn(): void
    {
        ['item' => $itemId, 'purchase' => $purchase] = $this->stockOfTwenty();

        $this->assertRefusedWith(PostingError::PurchaseReturnOriginInvalid, $this->buy([$this->purchaseLine($itemId, 0, '-2', '70')], -1, null, 987654));

        $return = $this->buy([$this->purchaseLine($itemId, 0, '-2', '70')], -1, null, $purchase);

        $this->assertGreaterThan(0, $return);
        $this->assertRefusedWith(PostingError::PurchaseReturnOriginInvalid, $this->buy([$this->purchaseLine($itemId, 0, '-1', '70')], -1, null, $return));
    }

    public function testSaveValue_KeepsWhatTheReturnOfAPurchaseOwesTheSupplierOnTheAccountOfTheSupplier(): void
    {
        ['item' => $itemId, 'purchase' => $purchase] = $this->stockOfTwenty();

        $this->assertGreaterThan(0, $this->buy([$this->purchaseLine($itemId, 0, '-2', '70', '15')], -1, null, $purchase));

        $this->assertSame('-1153.00', bcadd($this->balance($this->supplierAccount()), '0', 2), 'The 1,314.00 of the purchase less the 161.00 that went back');
    }

    // ---------------------------------------------------------------------------------------
    // Stock moving between locations
    // ---------------------------------------------------------------------------------------

    public function testPostCompleted_MovesStockBetweenLocationsAtWhatItIsWorthWhereItLeaves(): void
    {
        $itemId = $this->item('0.00');
        $other  = $this->anotherLocation();

        $this->assertGreaterThan(0, $this->buy([$this->purchaseLine($itemId, 0, '10', '50')]));
        $this->accountingDb()->table('items')->where('item_id', $itemId)->update(['cost_price' => '60.00']);

        $receivingId = $this->buy([$this->purchaseLine($itemId, 0, '4', '0', '0', '0', PERCENT, $other), $this->purchaseLine($itemId, 1, '-4', '0')], null, null);

        $this->assertGreaterThan(0, $receivingId);
        $this->assertSame([$this->stockAccountOf($other) . ':200.00:0.00', $this->stockAccount() . ':0.00:200.00'], $this->standingPurchase($receivingId)[0]['lines']);
        $this->assertSame(['quantity' => '6.000', 'value' => '300.00'], $this->stockOf($itemId));
        $this->assertSame(['quantity' => '4.000', 'value' => '200.00'], $this->stockOf($itemId, $other));
        $this->assertSame('6.000', $this->onHand($itemId));
        $this->assertSame('4.000', $this->onHand($itemId, $other));
        $this->assertStockTies();
    }

    public function testSaveValue_RefusesAPurchaseAndAReturnThatWorkInLocationsOfDifferentBranches(): void
    {
        $itemId = $this->item('0.00');
        $branch = $this->createBranch('B3', 'Third branch');
        $other  = $this->locationOfBranch($branch, 'Branch warehouse');

        $purchase = $this->buy([$this->purchaseLine($itemId, 0, '10', '50')]);

        $this->assertGreaterThan(0, $purchase);

        $across = $this->buy([$this->purchaseLine($itemId, 0, '4', '50'), $this->purchaseLine($itemId, 1, '4', '50', '0', '0', PERCENT, $other)]);

        $this->assertRefusedWith(PostingError::PurchaseAcrossBranches, $across);

        $back = $this->buy([$this->purchaseLine($itemId, 0, '-1', '50'), $this->purchaseLine($itemId, 1, '-1', '50', '0', '0', PERCENT, $other)], -1, null, $purchase);

        $this->assertRefusedWith(PostingError::PurchaseAcrossBranches, $back);
        $this->assertSame('10.000', $this->onHand($itemId));
        $this->assertSame('0.000', $this->onHand($itemId, $other));
        $this->assertStockTies();
    }

    public function testSaveValue_RefusesAMoveThatIsNotFromOneBranchToAnotherAmongThreeBranches(): void
    {
        $itemId = $this->item('0.00');
        $second = $this->locationOfBranch($this->createBranch('B3', 'Third branch'), 'Second branch warehouse');
        $third  = $this->locationOfBranch($this->createBranch('B4', 'Fourth branch'), 'Third branch warehouse');

        $this->assertGreaterThan(0, $this->buy([$this->purchaseLine($itemId, 0, '10', '50')]));
        $this->workInEveryBranch();

        $result = $this->buy([$this->purchaseLine($itemId, 0, '2', '0', '0', '0', PERCENT, $second), $this->purchaseLine($itemId, 1, '2', '0', '0', '0', PERCENT, $third), $this->purchaseLine($itemId, 2, '-4', '0')], null, null);

        $this->assertRefusedWith(PostingError::PurchaseAcrossBranches, $result);
        $this->assertSame('10.000', $this->onHand($itemId));
        $this->assertSame('0.000', $this->onHand($itemId, $second));
        $this->assertStockTies();
    }

    // ---------------------------------------------------------------------------------------
    // Stock moving between branches
    // ---------------------------------------------------------------------------------------

    /**
     * Ten units bought at 50 in the first branch, which the item carries at 60, and a location of another branch.
     *
     * @return array{item: int, branch: int, other: int}
     */
    private function stockAndALocationOfAnotherBranch(): array
    {
        $itemId = $this->item('0.00');
        $branch = $this->createBranch('B3', 'Third branch');
        $other  = $this->locationOfBranch($branch, 'Branch warehouse');

        $this->assertGreaterThan(0, $this->buy([$this->purchaseLine($itemId, 0, '10', '50')]));
        $this->accountingDb()->table('items')->where('item_id', $itemId)->update(['cost_price' => '60.00']);

        return ['item' => $itemId, 'branch' => $branch, 'other' => $other];
    }

    private function moveBetweenBranches(int $itemId, string $units, int $to, int $from = self::LOCATION_ID): int
    {
        return $this->buy([$this->purchaseLine($itemId, 0, $units, '0', '0', '0', PERCENT, $to), $this->purchaseLine($itemId, 1, '-' . $units, '0', '0', '0', PERCENT, $from)], null, null);
    }

    private function branchOfJournal(int $journalId): int
    {
        return (int) $this->accountingDb()->table('accounting_journals')->select('branch_id')->where('journal_id', $journalId)->get()->getRow()->branch_id;
    }

    public function testPostCompleted_PostsAJournalInEachBranchThatMeetInTheAccountsBetweenBranches(): void
    {
        ['item' => $itemId, 'branch' => $branch, 'other' => $other] = $this->stockAndALocationOfAnotherBranch();
        $this->workInBranches(1, $branch);

        $receivingId = $this->moveBetweenBranches($itemId, '4', $other);

        $this->assertGreaterThan(0, $receivingId);

        $issue   = $this->standingPurchase($receivingId, 'stock_transfer');
        $receipt = $this->standingPurchase($receivingId, 'stock_transfer_receipt');

        $this->assertCount(1, $issue);
        $this->assertCount(1, $receipt);
        $this->assertSame([$this->accountId('branches_receivable') . ':200.00:0.00', $this->stockAccount() . ':0.00:200.00'], $issue[0]['lines'], 'At what the issuing location holds the unit (50.00), not what the item carries (60.00)');
        $this->assertSame([$this->stockAccountOf($other) . ':200.00:0.00', $this->accountId('branches_payable') . ':0.00:200.00'], $receipt[0]['lines']);
        $this->assertSame(1, $this->branchOfJournal($issue[0]['id']));
        $this->assertSame($branch, $this->branchOfJournal($receipt[0]['id']));
        $this->assertSame([], $this->standingPurchase($receivingId, 'purchase'));
        $this->assertSame(['quantity' => '6.000', 'value' => '300.00'], $this->stockOf($itemId));
        $this->assertSame(['quantity' => '4.000', 'value' => '200.00'], $this->stockOf($itemId, $other));
        $this->assertSame('6.000', $this->onHand($itemId));
        $this->assertSame('4.000', $this->onHand($itemId, $other));
        $this->assertStockTies();
    }

    public function testPostCompleted_KeepsTheTwoBranchesOnTheDocumentAndTheMovementsInTheirOwnBranch(): void
    {
        ['item' => $itemId, 'branch' => $branch, 'other' => $other] = $this->stockAndALocationOfAnotherBranch();
        $this->workInBranches(1, $branch);

        $receivingId = $this->moveBetweenBranches($itemId, '4', $other);
        $document    = $this->accountingDb()->table('receivings')->where('receiving_id', $receivingId)->get()->getRow();

        $this->assertSame('requisition', $document->receiving_type);
        $this->assertSame([1, $branch], [(int) $document->branch_id, (int) $document->to_branch_id]);

        $entries = [];

        foreach ($this->accountingDb()->table('accounting_stock_entries')->whereIn('source_type', ['stock_transfer', 'stock_transfer_receipt'])->where('source_id', $receivingId)->orderBy('entry_id', 'ASC')->get()->getResult() as $entry) {
            $entries[] = [$entry->source_type, (int) $entry->branch_id, (int) $entry->location_id, bcadd($entry->quantity, '0', 3), bcadd($entry->value, '0', 2), $this->branchOfJournal((int) $entry->journal_id)];
        }

        $this->assertSame([
            ['stock_transfer', 1, self::LOCATION_ID, '-4.000', '-200.00', 1],
            ['stock_transfer_receipt', $branch, $other, '4.000', '200.00', $branch],
        ], $entries);
    }

    public function testPostCompleted_LeavesWhatEachBranchIsOwedAndOwesOffsettingAcrossTheCompany(): void
    {
        ['item' => $itemId, 'branch' => $branch, 'other' => $other] = $this->stockAndALocationOfAnotherBranch();
        $this->workInBranches(1, $branch);

        $this->assertGreaterThan(0, $this->moveBetweenBranches($itemId, '4', $other));
        $this->assertGreaterThan(0, $this->moveBetweenBranches($itemId, '1', self::LOCATION_ID, $other));

        $position = [];

        foreach ((new InterBranchPosition())->byBranch() as $row) {
            $position[$row->branch_id] = [$row->receivable, $row->payable];
        }

        $this->assertSame(['200.0000', '50.0000'], $position[1], 'The first branch gave 200.00 and took 50.00 back');
        $this->assertSame(['50.0000', '200.0000'], $position[$branch]);
        $this->assertSame('0.0000', (new InterBranchPosition())->difference());
        $this->assertStockTies();
    }

    public function testPostCompleted_WritesTheJournalOfTheLowerBranchFirstWhicheverWayTheStockGoes(): void
    {
        ['item' => $itemId, 'branch' => $branch, 'other' => $other] = $this->stockAndALocationOfAnotherBranch();
        $this->workInBranches(1, $branch);

        $forward  = $this->moveBetweenBranches($itemId, '4', $other);
        $backward = $this->moveBetweenBranches($itemId, '1', self::LOCATION_ID, $other);

        foreach ([$forward, $backward] as $receivingId) {
            $ids = [];

            foreach ($this->accountingDb()->table('accounting_journals')->whereIn('source_type', ['stock_transfer', 'stock_transfer_receipt'])->where('source_id', $receivingId)->get()->getResult() as $journal) {
                $ids[(int) $journal->branch_id] = (int) $journal->journal_id;
            }

            $this->assertLessThan($ids[$branch], $ids[1], 'The journal of the lower branch is written first');
        }
    }

    public function testPostCompleted_MovesStockThatIsWorthNothingWithoutAnyJournal(): void
    {
        ['item' => $itemId, 'branch' => $branch, 'other' => $other] = $this->stockAndALocationOfAnotherBranch();
        $free = $this->item('0.00');
        $this->workInBranches(1, $branch);

        $this->assertGreaterThan(0, $this->buy([$this->purchaseLine($free, 0, '5', '0')]));

        $before      = $this->countRows('accounting_journals');
        $receivingId = $this->moveBetweenBranches($free, '2', $other);

        $this->assertGreaterThan(0, $receivingId);
        $this->assertSame($before, $this->countRows('accounting_journals'));
        $this->assertSame(['quantity' => '2.000', 'value' => '0.00'], $this->stockOf($free, $other));
        $this->assertSame('2.000', $this->onHand($free, $other));
        $this->assertSame('3.000', $this->onHand($free));
        $this->assertStockTies();
    }

    public function testPostCompleted_SplitsAnItemTakenFromOneLocationAcrossSeveralLocationsOfTheOtherBranch(): void
    {
        ['item' => $itemId, 'branch' => $branch, 'other' => $other] = $this->stockAndALocationOfAnotherBranch();
        $second = $this->locationOfBranch($branch, 'Branch shop');
        $this->workInBranches(1, $branch);

        $receivingId = $this->buy([
            $this->purchaseLine($itemId, 0, '1', '0', '0', '0', PERCENT, $other),
            $this->purchaseLine($itemId, 1, '2', '0', '0', '0', PERCENT, $second),
            $this->purchaseLine($itemId, 2, '-3', '0'),
        ], null, null);

        $this->assertGreaterThan(0, $receivingId);
        $this->assertSame(['quantity' => '1.000', 'value' => '50.00'], $this->stockOf($itemId, $other));
        $this->assertSame(['quantity' => '2.000', 'value' => '100.00'], $this->stockOf($itemId, $second));

        $receipt = $this->standingPurchase($receivingId, 'stock_transfer_receipt')[0]['lines'];

        $this->assertEqualsCanonicalizing([$this->stockAccountOf($other) . ':50.00:0.00', $this->stockAccountOf($second) . ':100.00:0.00', $this->accountId('branches_payable') . ':0.00:150.00'], $receipt);
        $this->assertSame('0.0000', (new InterBranchPosition())->difference());
        $this->assertStockTies();
    }

    public function testSaveValue_RefusesAMoveBetweenBranchesWhenTheRoutingNamesNoAccountsBetweenBranches(): void
    {
        ['item' => $itemId, 'branch' => $branch, 'other' => $other] = $this->stockAndALocationOfAnotherBranch();
        $this->workInBranches(1, $branch);

        foreach (['branches.receivable', 'branches.payable'] as $key) {
            $this->accountingDb()->table('accounting_routing')->where('routing_key', $key)->update(['account_id' => null]);

            $journals  = $this->countRows('accounting_journals');
            $entries   = $this->countRows('accounting_stock_entries');
            $receivings = $this->countRows('receivings');

            $this->assertRefusedWith(PostingError::InterBranchAccountsMissing, $this->moveBetweenBranches($itemId, '4', $other));
            $this->assertSame([$receivings, $journals, $entries], [$this->countRows('receivings'), $this->countRows('accounting_journals'), $this->countRows('accounting_stock_entries')]);
            $this->assertSame('10.000', $this->onHand($itemId));
            $this->assertSame('0.000', $this->onHand($itemId, $other));

            $this->accountingDb()->table('accounting_routing')->where('routing_key', $key)->update(['account_id' => $this->accountId($key === 'branches.receivable' ? 'branches_receivable' : 'branches_payable')]);
        }

        $this->assertGreaterThan(0, $this->moveBetweenBranches($itemId, '4', $other));
    }

    public function testSaveValue_LeavesNothingOfAMoveBetweenBranchesWhenThePeriodOfEitherBranchIsClosed(): void
    {
        ['item' => $itemId, 'branch' => $branch, 'other' => $other] = $this->stockAndALocationOfAnotherBranch();
        $this->workInBranches(1, $branch);

        foreach ([$branch, 1] as $closed) {
            $this->closeThrough(date('Y-m-d'), $closed);

            $journals   = $this->countRows('accounting_journals');
            $entries    = $this->countRows('accounting_stock_entries');
            $receivings = $this->countRows('receivings');

            $this->assertRefusedWith(PostingError::PeriodClosed, $this->moveBetweenBranches($itemId, '4', $other));
            $this->assertSame([$receivings, $journals, $entries], [$this->countRows('receivings'), $this->countRows('accounting_journals'), $this->countRows('accounting_stock_entries')], 'A leg of the transfer went into the books');
            $this->assertSame('10.000', $this->onHand($itemId));
            $this->assertSame('0.000', $this->onHand($itemId, $other));

            $this->accountingDb()->table('accounting_closed_periods')->where('branch_id', $closed)->delete();
        }
    }

    public function testSaveValue_RefusesAnEmployeeWhoWorksInOnlyOneOfTheTwoBranches(): void
    {
        ['item' => $itemId, 'branch' => $branch, 'other' => $other] = $this->stockAndALocationOfAnotherBranch();

        foreach ([[1], [$branch], []] as $works) {
            $this->employeeId = $this->employeeWithGrants([], $works);

            $receivings = $this->countRows('receivings');
            $journals   = $this->countRows('accounting_journals');
            $result     = $this->moveBetweenBranches($itemId, '4', $other);

            $this->assertSame(ACCOUNTING_FAILED, $result);
            $this->assertInstanceOf(BranchException::class, $this->purchases->accountingFailure());
            $this->assertSame(BranchError::NotAllowed, $this->purchases->accountingFailure()->error);
            $this->assertSame([$receivings, $journals], [$this->countRows('receivings'), $this->countRows('accounting_journals')]);
            $this->assertSame('10.000', $this->onHand($itemId));
            $this->assertSame('0.000', $this->onHand($itemId, $other));
        }

        $this->employeeId = $this->employeeWithGrants([], [1, $branch]);

        $this->assertGreaterThan(0, $this->moveBetweenBranches($itemId, '4', $other));
    }

    public function testSaveValue_AcceptsAnEmployeeWhoWorksInEveryBranch(): void
    {
        ['item' => $itemId, 'other' => $other] = $this->stockAndALocationOfAnotherBranch();

        $this->employeeId = $this->employeeWithGrants([]);

        $this->assertGreaterThan(0, $this->moveBetweenBranches($itemId, '4', $other));
    }

    public function testSaveValue_AsksTheTwoBranchesEvenWithAccountingOff(): void
    {
        ['item' => $itemId, 'branch' => $branch, 'other' => $other] = $this->stockAndALocationOfAnotherBranch();
        $this->setConfigValue('accounting_enabled', '0');
        $this->employeeId = $this->employeeWithGrants([], [1]);

        $this->assertSame(ACCOUNTING_FAILED, $this->moveBetweenBranches($itemId, '4', $other));
        $this->assertSame('10.000', $this->onHand($itemId));

        $this->employeeId = $this->employeeWithGrants([], [1, $branch]);

        $this->assertGreaterThan(0, $this->moveBetweenBranches($itemId, '4', $other));
        $this->assertSame('4.000', $this->onHand($itemId, $other));
    }

    public function testDeleteValue_TakesBackTheJournalOfEachBranchTheMovementsAndTheStockOfBoth(): void
    {
        ['item' => $itemId, 'branch' => $branch, 'other' => $other] = $this->stockAndALocationOfAnotherBranch();
        $this->workInBranches(1, $branch);

        $receivingId = $this->moveBetweenBranches($itemId, '4', $other);

        $this->assertGreaterThan(0, $receivingId);
        $this->assertTrue($this->purchases->delete_value($receivingId, $this->employeeId));

        $this->assertSame([], $this->standingPurchase($receivingId, 'stock_transfer'));
        $this->assertSame([], $this->standingPurchase($receivingId, 'stock_transfer_receipt'));
        $this->assertSame(['reversed', 'reversed'], array_column($this->purchaseJournals($receivingId), 'status'));
        $this->assertSame(['quantity' => '10.000', 'value' => '500.00'], $this->stockOf($itemId));
        $this->assertSame(['quantity' => '0.000', 'value' => '0.00'], $this->stockOf($itemId, $other));
        $this->assertSame('10.000', $this->onHand($itemId));
        $this->assertSame('0.000', $this->onHand($itemId, $other));
        $this->assertSame('0.0000', (new InterBranchPosition())->difference());

        foreach ((new InterBranchPosition())->byBranch() as $row) {
            $this->assertSame(['0.0000', '0.0000'], [$row->receivable, $row->payable]);
        }

        $this->assertStockTies();
    }

    public function testDeleteValue_ReversesTheJournalOfTheLowerBranchFirstWhicheverWayTheStockWent(): void
    {
        ['item' => $itemId, 'branch' => $branch, 'other' => $other] = $this->stockAndALocationOfAnotherBranch();
        $this->workInBranches(1, $branch);

        $forward  = $this->moveBetweenBranches($itemId, '4', $other);
        $backward = $this->moveBetweenBranches($itemId, '1', self::LOCATION_ID, $other);

        foreach ([$backward, $forward] as $receivingId) {
            $this->assertTrue($this->purchases->delete_value($receivingId, $this->employeeId));

            $reversals = $this->accountingDb()->table('accounting_journals')->where('source_type', 'reversal')->orderBy('journal_id', 'DESC')->limit(2)->get()->getResult();

            $this->assertLessThan((int) $reversals[0]->journal_id, (int) $reversals[1]->journal_id);
            $this->assertSame([1, $branch], [(int) $reversals[1]->branch_id, (int) $reversals[0]->branch_id], 'The journal of the lower branch is reversed first');
        }

        $this->assertStockTies();
    }

    public function testDeleteValue_RefusesAnEmployeeWhoWorksInOnlyOneOfTheTwoBranchesAndTakesBackNothing(): void
    {
        ['item' => $itemId, 'branch' => $branch, 'other' => $other] = $this->stockAndALocationOfAnotherBranch();
        $this->workInBranches(1, $branch);

        $receivingId = $this->moveBetweenBranches($itemId, '4', $other);

        foreach ([[1], [$branch]] as $works) {
            $this->assertFalse($this->purchases->delete_value($receivingId, $this->employeeWithGrants([], $works)));
            $this->assertInstanceOf(BranchException::class, $this->purchases->accountingFailure());
            $this->assertSame(lang('Branches.error_not_allowed'), AccountingMessage::of($this->purchases->accountingFailure()));
            $this->assertCount(1, $this->standingPurchase($receivingId, 'stock_transfer'));
            $this->assertCount(1, $this->standingPurchase($receivingId, 'stock_transfer_receipt'));
            $this->assertSame(1, $this->accountingDb()->table('receivings')->where('receiving_id', $receivingId)->countAllResults());
            $this->assertSame('4.000', $this->onHand($itemId, $other));
            $this->assertSame('6.000', $this->onHand($itemId));
        }

        $this->assertTrue($this->purchases->delete_value($receivingId, $this->employeeWithGrants([], [1, $branch])));
    }

    public function testDeleteValue_DeletingATransferAgainChangesNothing(): void
    {
        ['item' => $itemId, 'branch' => $branch, 'other' => $other] = $this->stockAndALocationOfAnotherBranch();
        $this->workInBranches(1, $branch);

        $receivingId = $this->moveBetweenBranches($itemId, '4', $other);

        $this->assertTrue($this->purchases->delete_value($receivingId, $this->employeeId));

        $journals = $this->countRows('accounting_journals');
        $entries  = $this->countRows('accounting_stock_entries');

        $this->assertTrue($this->purchases->delete_value($receivingId, $this->employeeId));
        $this->assertSame([$journals, $entries], [$this->countRows('accounting_journals'), $this->countRows('accounting_stock_entries')]);
    }

    public function testDeleteValue_LeavesBothLegsWhenThePeriodOfOneBranchIsClosed(): void
    {
        ['item' => $itemId, 'branch' => $branch, 'other' => $other] = $this->stockAndALocationOfAnotherBranch();
        $this->workInBranches(1, $branch);

        $receivingId = $this->moveBetweenBranches($itemId, '4', $other);

        $this->closeThrough('2099-12-31', $branch);

        $journals = $this->countRows('accounting_journals');
        $entries  = $this->countRows('accounting_stock_entries');

        $this->assertFalse($this->purchases->delete_value($receivingId, $this->employeeId));
        $this->assertInstanceOf(PostingException::class, $this->purchases->accountingFailure());
        $this->assertSame(PostingError::PeriodClosed, $this->purchases->accountingFailure()->error);
        $this->assertCount(1, $this->standingPurchase($receivingId, 'stock_transfer'), 'The branch that was open was reversed alone');
        $this->assertCount(1, $this->standingPurchase($receivingId, 'stock_transfer_receipt'));
        $this->assertSame([$journals, $entries], [$this->countRows('accounting_journals'), $this->countRows('accounting_stock_entries')]);
        $this->assertSame('4.000', $this->onHand($itemId, $other));
        $this->assertSame('6.000', $this->onHand($itemId));
        $this->assertStockTies();
    }

    public function testUpdate_RefusesToChangeTheDateOfATransferBetweenBranchesThatIsInTheBooks(): void
    {
        ['item' => $itemId, 'branch' => $branch, 'other' => $other] = $this->stockAndALocationOfAnotherBranch();
        $this->workInBranches(1, $branch);

        $receivingId = $this->moveBetweenBranches($itemId, '4', $other);

        $this->assertFalse($this->purchases->update($receivingId, ['receiving_time' => '2020-02-02 10:00:00']));
        $this->assertInstanceOf(AccountingException::class, $this->purchases->accountingFailure());
        $this->assertSame(AccountingError::PurchaseLocked, $this->purchases->accountingFailure()->error);
        $this->assertTrue($this->purchases->update($receivingId, ['comment' => 'noted']), 'What is not the date or the supplier can still change');
    }

    public function testIsPosted_SeesAJournalAndMovementsOfATransferBetweenBranches(): void
    {
        ['item' => $itemId, 'branch' => $branch, 'other' => $other] = $this->stockAndALocationOfAnotherBranch();
        $this->workInBranches(1, $branch);

        $receivingId = $this->moveBetweenBranches($itemId, '4', $other);
        $posting     = new ReceivingPosting();

        $this->assertTrue($posting->isPosted($receivingId));
        $this->assertSame(['stock_transfer', 'stock_transfer_receipt'], array_map(static fn (object $journal): string => $journal->source_type, $posting->journalsOf($receivingId)));
    }

    // ---------------------------------------------------------------------------------------
    // What the books refuse
    // ---------------------------------------------------------------------------------------

    public function testSaveValue_RefusesAPurchaseInAClosedPeriodAndLeavesNothingOfIt(): void
    {
        $itemId = $this->item('5.00');
        $this->closeThrough(date('Y-m-d'));

        $result = $this->buy([$this->purchaseLine($itemId, 0, '10', '40')]);

        $this->assertRefusedWith(PostingError::PeriodClosed, $result);
        $this->assertSame(0, $this->countRows('receivings'));
        $this->assertSame('0.000', $this->onHand($itemId));
        $this->assertSame('5.0000', $this->itemCostOf($itemId));
        $this->assertSame(0, $this->countRows('accounting_stock_entries'));
    }

    public function testSaveValue_RefusesAPurchaseFromASupplierWithoutAnAccountAndLeavesNothingOfIt(): void
    {
        $itemId     = $this->item('5.00');
        $supplierId = $this->insertSupplier('No account');

        $result = $this->buy([$this->purchaseLine($itemId, 0, '10', '40')], $supplierId);

        $this->assertRefusedWith(PostingError::EntityAccountMissing, $result);
        $this->assertSame(0, $this->countRows('receivings'));
        $this->assertSame('0.000', $this->onHand($itemId));
    }

    public function testSaveValue_RefusesAPurchaseIntoAStockLocationWithoutAnAccount(): void
    {
        $itemId = $this->item('5.00');
        $other  = $this->insertStockLocation('No account');

        $result = $this->buy([$this->purchaseLine($itemId, 0, '10', '40', '0', '0', PERCENT, $other)]);

        $this->assertRefusedWith(PostingError::EntityAccountMissing, $result);
        $this->assertSame(0, $this->countRows('receivings'));
        $this->assertSame('0.000', $this->onHand($itemId, $other));
    }

    public function testSaveValue_RefusesAPaymentTypeAPurchaseCannotBePaidWith(): void
    {
        $itemId = $this->item('5.00');

        $result = $this->buy([$this->purchaseLine($itemId, 0, '10', '40')], -1, lang('Sales.giftcard'));

        $this->assertRefusedWith(PostingError::PaymentTypeUnknown, $result);
        $this->assertSame(0, $this->countRows('receivings'));
    }

    public function testSaveValue_UndoesThePurchaseWhenItsPaymentCannotBePosted(): void
    {
        $itemId = $this->item('5.00');

        $this->accountingDb()->table('accounting_routing')->where('routing_key', 'payment.cash')->update(['account_id' => null]);

        $result = $this->buy([$this->purchaseLine($itemId, 0, '10', '40')], -1, lang('Sales.cash'));

        $this->assertSame(ACCOUNTING_FAILED, $result);
        $this->assertSame(0, $this->countRows('receivings'));
        $this->assertSame('0.000', $this->onHand($itemId));
        $this->assertSame('5.0000', $this->itemCostOf($itemId));
        $this->assertSame(0, $this->countRows('accounting_journals'), 'The journal of the purchase that was written before the payment failed went with the rollback');
        $this->assertSame(0, $this->countRows('accounting_stock_entries'));
    }

    public function testSaveValue_ARefusedReturnLeavesTheStockTheJournalsAndTheMovementsOfTheOriginAsTheyWere(): void
    {
        ['item' => $itemId, 'purchase' => $purchase] = $this->stockOfTwenty();

        $journals = $this->countRows('accounting_journals');
        $entries  = $this->countRows('accounting_stock_entries');

        $this->closeThrough(date('Y-m-d'));
        $this->assertRefusedWith(PostingError::PeriodClosed, $this->buy([$this->purchaseLine($itemId, 0, '-2', '70', '15')], -1, null, $purchase));

        $this->assertSame($journals, $this->countRows('accounting_journals'));
        $this->assertSame($entries, $this->countRows('accounting_stock_entries'));
        $this->assertSame('20.000', $this->onHand($itemId));
        $this->assertSame('66.0000', $this->itemCostOf($itemId));
        $this->assertSame(1, $this->countRows('receivings'));
    }

    // ---------------------------------------------------------------------------------------
    // Accounting off, and before it starts
    // ---------------------------------------------------------------------------------------

    public function testSaveValue_WritesNothingToTheBooksWhileAccountingIsOffAndStillKeepsTheStockAndTheAverage(): void
    {
        $itemId = $this->item('0.00');

        $this->setConfigValue('accounting_enabled', '0');
        $this->setConfigValue('receiving_calculate_average_price', '1');
        config(\Config\OSPOS::class)->settings['receiving_calculate_average_price'] = '1';

        $this->assertGreaterThan(0, $this->buy([$this->purchaseLine($itemId, 0, '10', '50')]));

        $this->assertSame(0, $this->countRows('accounting_journals'));
        $this->assertSame(0, $this->countRows('accounting_stock_entries'));
        $this->assertSame('10.000', $this->onHand($itemId));
        $this->assertSame('50.0000', $this->itemCostOf($itemId));
    }

    public function testSaveValue_StillHoldsAReturnToTheQuantitiesOfItsPurchaseWhileAccountingIsOff(): void
    {
        $this->setConfigValue('accounting_enabled', '0');

        $itemId   = $this->item('0.00');
        $purchase = $this->buy([$this->purchaseLine($itemId, 0, '10', '50')]);

        $result = $this->buy([$this->purchaseLine($itemId, 0, '-11', '50')], -1, null, $purchase);

        $this->assertRefusedWith(PostingError::PurchaseReturnExceedsOrigin, $result);
        $this->assertSame('10.000', $this->onHand($itemId));
        $this->assertSame(0, $this->countRows('accounting_posting_failures'), 'Nothing is written in the failure log of books that are off');
        $this->assertGreaterThan(0, $this->buy([$this->purchaseLine($itemId, 0, '-10', '50')], -1, null, $purchase));
    }

    public function testSaveValue_WritesNothingToTheBooksForAPurchaseDatedBeforeTheyStart(): void
    {
        $itemId = $this->item('0.00');

        $this->setConfigValue('accounting_start_date', '2035-01-01');

        $this->assertGreaterThan(0, $this->buy([$this->purchaseLine($itemId, 0, '10', '50')]));
        $this->assertSame(0, $this->countRows('accounting_journals'));
        $this->assertSame(0, $this->countRows('accounting_stock_entries'));
    }

    // ---------------------------------------------------------------------------------------
    // Deleting a purchase
    // ---------------------------------------------------------------------------------------

    public function testDeleteValue_TakesTheJournalsTheMovementsAndTheStockOfAPurchaseBack(): void
    {
        $itemId      = $this->item('0.00');
        $receivingId = $this->buy([$this->purchaseLine($itemId, 0, '20', '100', '15')], -1, lang('Sales.cash'));

        $this->assertTrue($this->purchases->delete_value($receivingId, $this->employeeId));

        $this->assertSame([], $this->standingPurchase($receivingId));
        $this->assertSame([], $this->standingPurchase($receivingId, 'purchase_payment'));
        $this->assertSame('0.00', $this->balance($this->stockAccount()));
        $this->assertSame('0.00', $this->balance($this->supplierAccount()));
        $this->assertSame('0.00', $this->balance($this->accountId('cash')));
        $this->assertSame('0.00', $this->balance($this->accountId('tax_input')));
        $this->assertSame(['quantity' => '0.000', 'value' => '0.00'], $this->stockOf($itemId));
        $this->assertSame('0.000', $this->onHand($itemId));
        $this->assertSame(0, $this->accountingDb()->table('receivings')->where('receiving_id', $receivingId)->countAllResults());
        $this->assertStockTies();
    }

    public function testDeleteValue_DeletingAgainChangesNothing(): void
    {
        $itemId      = $this->item('0.00');
        $receivingId = $this->buy([$this->purchaseLine($itemId, 0, '20', '100')]);

        $this->assertTrue($this->purchases->delete_value($receivingId, $this->employeeId));

        $journals = $this->countRows('accounting_journals');
        $entries  = $this->countRows('accounting_stock_entries');

        $this->assertTrue($this->purchases->delete_value($receivingId, $this->employeeId));
        $this->assertSame($journals, $this->countRows('accounting_journals'));
        $this->assertSame($entries, $this->countRows('accounting_stock_entries'));
        $this->assertSame('0.000', $this->onHand($itemId));
    }

    public function testDeleteValue_DatesTheReversalOnTheDayOfThePurchaseWhenItsPeriodIsOpen(): void
    {
        $itemId      = $this->item('0.00');
        $receivingId = $this->buy([$this->purchaseLine($itemId, 0, '20', '100')]);

        $this->purchases->delete_value($receivingId, $this->employeeId);

        $this->assertSame(date('Y-m-d'), $this->accountingDb()->table('accounting_journals')->where('source_type', 'reversal')->get()->getRow()->journal_date);
    }

    public function testDeleteValue_RefusesToDeleteAPurchaseThatGoodsHaveGoneBackFrom(): void
    {
        ['item' => $itemId, 'purchase' => $purchase] = $this->stockOfTwenty();

        $this->assertGreaterThan(0, $this->buy([$this->purchaseLine($itemId, 0, '-2', '70')], -1, null, $purchase));

        $this->assertFalse($this->purchases->delete_value($purchase, $this->employeeId));
        $this->assertInstanceOf(AccountingException::class, $this->failure());
        $this->assertSame(AccountingError::PurchaseHasReturns, $this->failure()->error);
        $this->assertSame('18.000', $this->onHand($itemId));
        $this->assertCount(1, $this->standingPurchase($purchase));
        $this->assertStockTies();
    }

    public function testDeleteValue_DeletingAReturnBringsTheGoodsAndTheirValueBack(): void
    {
        ['item' => $itemId, 'purchase' => $purchase] = $this->stockOfTwenty();

        $return = $this->buy([$this->purchaseLine($itemId, 0, '-2', '70', '15')], -1, null, $purchase);

        $this->assertSame('1182.60', $this->stockOf($itemId)['value']);
        $this->assertTrue($this->purchases->delete_value($return, $this->employeeId));
        $this->assertSame(['quantity' => '20.000', 'value' => '1314.00'], $this->stockOf($itemId));
        $this->assertSame('20.000', $this->onHand($itemId));
        $this->assertSame('-1314.00', $this->balance($this->supplierAccount()));
        $this->assertStockTies();
    }

    public function testDeleteValue_UndoesTheWholeDeletionWhenTheBooksRefuseIt(): void
    {
        $itemId      = $this->item('0.00');
        $receivingId = $this->buy([$this->purchaseLine($itemId, 0, '20', '100')], -1, lang('Sales.cash'));
        $entries     = $this->countRows('accounting_stock_entries');

        $this->closeThrough('2035-12-31');

        $this->assertFalse($this->purchases->delete_value($receivingId, $this->employeeId));

        $this->assertInstanceOf(PostingException::class, $this->failure());
        $this->assertSame(PostingError::PeriodClosed, $this->failure()->error);
        $this->assertSame('20.000', $this->onHand($itemId));
        $this->assertSame(1, $this->accountingDb()->table('receivings')->where('receiving_id', $receivingId)->countAllResults());
        $this->assertCount(1, $this->standingPurchase($receivingId));
        $this->assertCount(1, $this->standingPurchase($receivingId, 'purchase_payment'));
        $this->assertSame($entries, $this->countRows('accounting_stock_entries'));
    }

    // ---------------------------------------------------------------------------------------
    // The same thing twice
    // ---------------------------------------------------------------------------------------

    public function testPostCompleted_RunAgainWritesNoSecondJournalAndNoSecondMovement(): void
    {
        $itemId      = $this->item('0.00');
        $receivingId = $this->buy([$this->purchaseLine($itemId, 0, '20', '100', '15')], -1, lang('Sales.cash'));
        $journals    = $this->countRows('accounting_journals');
        $entries     = $this->countRows('accounting_stock_entries');

        (new ReceivingPosting())->postCompleted($receivingId, $this->employeeId);
        (new ReceivingPosting())->postCompleted($receivingId, $this->employeeId);

        $this->assertSame($journals, $this->countRows('accounting_journals'));
        $this->assertSame($entries, $this->countRows('accounting_stock_entries'));
        $this->assertSame('20.000', $this->onHand($itemId), 'Posting again does not touch the stock');
    }

    public function testTheMovementsOfASourceAndARevisionAreRefusedTwiceByTheDatabaseItself(): void
    {
        $itemId = $this->item('0.00');
        $this->buy([$this->purchaseLine($itemId, 0, '20', '100')]);

        $row = (array) $this->accountingDb()->table('accounting_stock_entries')->get()->getRow();
        unset($row['entry_id'], $row['created_at']);

        $this->expectException(\Throwable::class);

        $this->accountingDb()->table('accounting_stock_entries')->insert($row);
    }

    public function testPurchasesFromTwoRequestsAtTheSameMomentEndWithTheStockAndTheAverageOfBothInTurn(): void
    {
        $itemId = $this->item('0.00');

        [$one, $two] = $this->raceTwoProcesses(
            'purchase',
            [$this->supplierId, $itemId, '10', '50', $this->employeeId],
            [$this->supplierId, $itemId, '10', '70', $this->employeeId],
        );

        $this->trackRaceReceivings();

        $this->assertTrue($one);
        $this->assertTrue($two);
        $this->assertSame('20.000', $this->onHand($itemId), 'Neither purchase lost the quantity of the other');
        $this->assertSame('60.0000', $this->itemCostOf($itemId), 'The average is of both, whichever came first');
        $this->assertSame(['quantity' => '20.000', 'value' => '1200.00'], $this->stockOf($itemId));
        $this->assertSame(2, $this->accountingDb()->table('accounting_journals')->where('source_type', 'purchase')->countAllResults());
        $this->assertStockTies();
    }

    public function testReturnsFromTwoRequestsAtTheSameMomentGiveBackNoMoreThanThePurchaseBroughtIn(): void
    {
        $itemId   = $this->item('0.00');
        $purchase = $this->buy([$this->purchaseLine($itemId, 0, '10', '50')]);

        [$one, $two] = $this->raceTwoProcesses(
            'purchase',
            [$this->supplierId, $itemId, '-6', '50', $this->employeeId, $purchase],
            [$this->supplierId, $itemId, '-6', '50', $this->employeeId, $purchase],
        );

        $this->trackRaceReceivings();

        $this->assertSame(1, (int) $one + (int) $two, 'Only one of two returns of six out of ten can be saved');
        $this->assertSame('4.000', $this->onHand($itemId));
        $this->assertSame(['quantity' => '4.000', 'value' => '200.00'], $this->stockOf($itemId));
        $this->assertSame(1, $this->accountingDb()->table('receivings')->where('return_of_receiving_id', $purchase)->countAllResults());
        $this->assertStockTies();
    }

    public function testDeletionsFromTwoRequestsAtTheSameMomentTakeTheStockAndTheJournalsBackOnce(): void
    {
        $itemId      = $this->item('0.00');
        $receivingId = $this->buy([$this->purchaseLine($itemId, 0, '10', '50')], -1, lang('Sales.cash'));

        [$one, $two] = $this->raceTwoProcesses('deletePurchase', [$receivingId, $this->employeeId], [$receivingId, $this->employeeId]);

        $this->assertTrue($one);
        $this->assertTrue($two);
        $this->assertSame('0.000', $this->onHand($itemId));
        $this->assertSame(['quantity' => '0.000', 'value' => '0.00'], $this->stockOf($itemId));
        $this->assertSame(2, $this->accountingDb()->table('accounting_journals')->where('source_type', 'reversal')->countAllResults(), 'The purchase and its payment, once each');
        $this->assertSame('0.00', $this->balance($this->supplierAccount()));
        $this->assertStockTies();
    }

    public function testUpdateLines_EndsTwoCorrectionsFromTwoRequestsAtTheSameMomentWithOneStandingRevisionAndTheStockTied(): void
    {
        $itemId      = $this->item('0.00');
        $receivingId = $this->buy([$this->purchaseLine($itemId, 0, '10', '50')]);

        [$one, $two] = $this->raceTwoProcesses('editPurchaseLines', [$receivingId, $itemId, '8', $this->editor()], [$receivingId, $itemId, '6', $this->editor()]);

        $this->assertTrue($one);
        $this->assertTrue($two);
        $this->assertCount(1, $this->standingPurchase($receivingId));
        $this->assertSame(['reversed', 'reversed', 'posted'], array_column($this->purchaseJournals($receivingId), 'status'));
        $this->assertSame(bcadd($this->onHand($itemId), '0', 3), $this->stockOf($itemId)['quantity']);
        $this->assertContains($this->onHand($itemId), ['6.000', '8.000']);
        $this->assertStockTies();
        $this->assertSupplierTies();
    }

    public function testUpdateLines_DoesNotTakeTheSameJournalBackTwiceWhenTwoRequestsCorrectAReceivingWithoutASupplierAtTheSameMoment(): void
    {
        $itemId      = $this->item('0.00');
        $receivingId = $this->buy([$this->purchaseLine($itemId, 0, '10', '50')], null, lang('Sales.cash'));

        [$one, $two] = $this->raceTwoProcesses('editPurchaseLines', [$receivingId, $itemId, '8', $this->editor()], [$receivingId, $itemId, '6', $this->editor()]);

        $this->assertTrue($one);
        $this->assertTrue($two);
        $this->assertCount(1, $this->standingPurchase($receivingId));
        $this->assertSame(['reversed', 'reversed', 'posted'], array_column($this->purchaseJournals($receivingId), 'status'));
        $this->assertContains($this->onHand($itemId), ['6.000', '8.000']);
        $this->assertSame(bcadd($this->onHand($itemId), '0', 3), $this->stockOf($itemId)['quantity']);
        $this->assertStockTies();
    }

    private function trackRaceReceivings(): void
    {
        foreach ($this->accountingDb()->table('receivings')->select('receiving_id')->where('comment', 'race')->get()->getResult() as $row) {
            $this->receivingIds[] = (int) $row->receiving_id;
        }
    }

    /**
     * @return list<object>
     */
    private function paymentJournals(int $paymentId): array
    {
        return $this->accountingDb()->table('accounting_journals')->where(['source_type' => 'supplier_payment', 'source_id' => $paymentId])->orderBy('revision')->get()->getResult();
    }

    /**
     * @return list<string> "account:debit:credit" of each line.
     */
    private function linesOf(int $journalId): array
    {
        return array_map(
            static fn (object $line): string => $line->account_id . ':' . bcadd($line->debit, '0', 2) . ':' . bcadd($line->credit, '0', 2),
            $this->accountingDb()->table('accounting_journal_lines')->select('account_id, debit, credit')->where('journal_id', $journalId)->orderBy('line_no', 'ASC')->get()->getResult(),
        );
    }

    public function testUpdate_PostsAPaymentAsADebitToTheSupplierAndACreditToTheAccountItWasPaidFrom(): void
    {
        $purchase = $this->purchaseOf('100.00');
        $payment  = $this->payPurchase($purchase, '40.00');

        $journals = $this->paymentJournals($payment);

        $this->assertCount(1, $journals);
        $this->assertSame([$this->supplierAccount() . ':40.00:0.00', $this->accountId('cash') . ':0.00:40.00'], $this->linesOf((int) $journals[0]->journal_id));
        $this->assertSame(date('Y-m-d'), $journals[0]->journal_date);
        $this->assertSame('posted', $journals[0]->status);
        $this->assertSame(1, (int) $journals[0]->branch_id);
        $this->assertSame('60.00', $this->owed());
        $this->assertSupplierTies();
    }

    public function testUpdate_PostsEachPaymentOfAPurchaseInItsOwnJournal(): void
    {
        $purchase = $this->purchaseOf('100.00');
        $first    = $this->payPurchase($purchase, '30.00');
        $second   = $this->payPurchase($purchase, '20.00', lang('Sales.bank_transfer'));

        $this->assertCount(1, $this->paymentJournals($first));
        $this->assertCount(1, $this->paymentJournals($second));
        $this->assertNotSame($this->paymentJournals($first)[0]->journal_id, $this->paymentJournals($second)[0]->journal_id);
        $this->assertSame('50.00', $this->owed());
    }

    public function testUpdate_PostsANegativePaymentAsMoneyTheSupplierGaveBack(): void
    {
        $purchase = $this->purchaseOf('100.00');
        $this->payPurchase($purchase, '100.00');
        $payment = $this->payPurchase($purchase, '-30.00');

        $journals = $this->paymentJournals($payment);

        $this->assertSame([$this->accountId('cash') . ':30.00:0.00', $this->supplierAccount() . ':0.00:30.00'], $this->linesOf((int) $journals[0]->journal_id));
        $this->assertSame('30.00', $this->owed());
        $this->assertSupplierTies();
    }

    /**
     * @return array<string, array{string}>
     */
    public static function statusProvider(): array
    {
        return ['incomplete' => ['incomplete'], 'under review' => ['review'], 'failed' => ['failed'], 'draft' => ['draft']];
    }

    #[DataProvider('statusProvider')]
    public function testUpdate_KeepsAPaymentThatIsNotCompletedOutOfTheBooks(string $status): void
    {
        $purchase = $this->purchaseOf('100.00');
        $payment  = $this->payPurchase($purchase, '40.00', null, $status);

        $this->assertSame([], $this->paymentJournals($payment));
        $this->assertSame('100.00', $this->owed());
        $this->assertSame('0.00', bcadd($this->supplierPosition()->purchase($purchase)->paid, '0', 2));
        $this->assertSupplierTies();
    }

    public function testUpdate_PostsAPaymentWhenItBecomesCompletedAndTakesItBackWhenItStopsBeingCompleted(): void
    {
        $purchase = $this->purchaseOf('100.00');
        $payment  = $this->payPurchase($purchase, '40.00', null, 'draft');

        $this->assertTrue($this->changePayment($purchase, $payment, ['payment_status' => 'completed']));

        $this->assertCount(1, $this->paymentJournals($payment));
        $this->assertSame('60.00', $this->owed());

        $this->assertTrue($this->changePayment($purchase, $payment, ['payment_status' => 'failed']));

        $journals = $this->paymentJournals($payment);

        $this->assertSame('reversed', $journals[0]->status);
        $this->assertSame('100.00', $this->owed());

        $this->assertTrue($this->changePayment($purchase, $payment, ['payment_status' => 'completed']));

        $journals = $this->paymentJournals($payment);

        $this->assertSame(['reversed', 'posted'], array_map(static fn (object $journal): string => $journal->status, array_values(array_filter($journals, static fn (object $journal): bool => (int) $journal->revision === 1 || (int) $journal->revision === 2))));
        $this->assertSame(2, (int) end($journals)->revision);
        $this->assertSame('60.00', $this->owed());
        $this->assertSupplierTies();
    }

    public function testUpdate_ReversesAndPostsAgainAPaymentWhoseAmountChangedAndLeavesTheOthersAlone(): void
    {
        $purchase = $this->purchaseOf('100.00');
        $first    = $this->payPurchase($purchase, '30.00');
        $second   = $this->payPurchase($purchase, '20.00');
        $kept     = $this->paymentJournals($first)[0]->journal_id;

        $this->assertTrue($this->changePayment($purchase, $second, ['payment_amount' => '25.00']));

        $this->assertSame([(string) $kept], array_map(static fn (object $journal): string => (string) $journal->journal_id, $this->paymentJournals($first)), 'The journal of the payment that did not change is the one it had');
        $this->assertSame(['reversed', 'posted'], array_map(static fn (object $journal): string => $journal->status, array_slice($this->paymentJournals($second), 0, 2)));
        $this->assertSame('45.00', $this->owed());
        $this->assertSupplierTies();
    }

    public function testUpdate_TakesBackTheJournalOfAPaymentGivenAnAmountOfNothingAndDeletesTheRow(): void
    {
        $purchase = $this->purchaseOf('100.00');
        $payment  = $this->payPurchase($purchase, '40.00');

        $this->assertTrue($this->changePayment($purchase, $payment, ['payment_amount' => '0']));

        $this->assertSame(0, $this->accountingDb()->table('receivings_payments')->where('payment_id', $payment)->countAllResults());
        $this->assertSame('reversed', $this->paymentJournals($payment)[0]->status);
        $this->assertSame('100.00', $this->owed());
        $this->assertSupplierTies();
    }

    public function testUpdate_RunAgainWithTheSamePaymentsPostsNothingMore(): void
    {
        $purchase = $this->purchaseOf('100.00');
        $payment  = $this->payPurchase($purchase, '40.00');
        $journals = $this->countRows('accounting_journals');

        $this->assertTrue($this->changePayment($purchase, $payment, []));
        $this->assertTrue($this->changePayment($purchase, $payment, []));

        $this->assertSame($journals, $this->countRows('accounting_journals'));
        $this->assertCount(1, $this->paymentJournals($payment));
    }

    public function testUpdate_RefusesAPaymentOnAPurchaseWithoutASupplierAndSavesNothing(): void
    {
        $item     = $this->item('0.00');
        $purchase = $this->buy([$this->purchaseLine($item, 0, '1', '40.00')], null, lang('Sales.cash'));
        $journals = $this->countRows('accounting_journals');

        $paid = $this->purchases->update($purchase, ['payments' => [
            ['payment_id' => NEW_ENTRY, 'payment_type' => lang('Sales.cash'), 'payment_amount' => '10.00', 'employee_id' => $this->employeeId, 'payment_status' => 'completed', 'reference_code' => ''],
        ]]);

        $this->assertFalse($paid);
        $this->assertSame(AccountingError::SupplierPaymentNeedsSupplier, $this->purchases->accountingFailure()->error);
        $this->assertSame(0, $this->countRows('receivings_payments'));
        $this->assertSame($journals, $this->countRows('accounting_journals'));
    }

    public function testUpdate_RefusesAPaymentOnADebitNote(): void
    {
        $purchase = $this->purchaseOf('100.00');
        $note     = $this->debitNoteOf($purchase, '30.00');

        $paid = $this->purchases->update($note, ['payments' => [
            ['payment_id' => NEW_ENTRY, 'payment_type' => lang('Sales.cash'), 'payment_amount' => '10.00', 'employee_id' => $this->employeeId, 'payment_status' => 'completed', 'reference_code' => ''],
        ]]);

        $this->assertFalse($paid);
        $this->assertSame(AccountingError::SupplierPaymentNotOnInvoice, $this->purchases->accountingFailure()->error);
        $this->assertSame(0, $this->countRows('receivings_payments'));
    }

    public function testUpdate_RefusesToTouchAPaymentOfAnotherPurchase(): void
    {
        $one     = $this->purchaseOf('100.00');
        $another = $this->purchaseOf('100.00');
        $payment = $this->payPurchase($one, '40.00');

        $changed = $this->purchases->update($another, ['payments' => [
            ['payment_id' => $payment, 'payment_type' => lang('Sales.cash'), 'payment_amount' => '0', 'employee_id' => $this->employeeId, 'payment_status' => 'completed', 'reference_code' => ''],
        ]]);

        $this->assertFalse($changed);
        $this->assertSame(AccountingError::SupplierPaymentNotOnInvoice, $this->purchases->accountingFailure()->error);
        $this->assertSame(1, $this->accountingDb()->table('receivings_payments')->where('payment_id', $payment)->countAllResults());
        $this->assertSame('posted', $this->paymentJournals($payment)[0]->status);
    }

    public function testUpdate_UndoesThePaymentAndItsJournalWhenThePeriodIsClosed(): void
    {
        $purchase = $this->purchaseOf('100.00');
        $journals = $this->countRows('accounting_journals');

        $this->closeThrough(date('Y-m-d'));

        $paid = $this->purchases->update($purchase, ['payments' => [
            ['payment_id' => NEW_ENTRY, 'payment_type' => lang('Sales.cash'), 'payment_amount' => '40.00', 'employee_id' => $this->employeeId, 'payment_status' => 'completed', 'reference_code' => ''],
        ]]);

        $this->assertFalse($paid);
        $this->assertSame(PostingError::PeriodClosed, $this->purchases->accountingFailure()->error);
        $this->assertSame(0, $this->countRows('receivings_payments'));
        $this->assertSame($journals, $this->countRows('accounting_journals'));
        $this->assertSame(1, $this->accountingDb()->table('accounting_posting_failures')->where('source_type', 'purchase')->where('source_id', $purchase)->where('error_code', 'period_closed')->countAllResults(), 'The refusal is written down after the rollback');
    }

    public function testUpdate_UndoesThePaymentWhenTheAccountItIsPaidFromIsNotMapped(): void
    {
        $purchase = $this->purchaseOf('100.00');
        $journals = $this->countRows('accounting_journals');

        $this->accountingDb()->table('accounting_routing')->where('routing_key', 'payment.cash')->update(['account_id' => null]);

        $paid = $this->purchases->update($purchase, ['payments' => [
            ['payment_id' => NEW_ENTRY, 'payment_type' => lang('Sales.cash'), 'payment_amount' => '40.00', 'employee_id' => $this->employeeId, 'payment_status' => 'completed', 'reference_code' => ''],
        ]]);

        $this->assertFalse($paid);
        $this->assertSame(0, $this->countRows('receivings_payments'));
        $this->assertSame($journals, $this->countRows('accounting_journals'));
    }

    public function testUpdate_RefusesAPaymentTypeThatIsNotOneASupplierIsPaidWithAndSavesNothingOfTheSameSave(): void
    {
        $purchase = $this->purchaseOf('100.00');
        $journals = $this->countRows('accounting_journals');

        $paid = $this->purchases->update($purchase, ['comment' => 'both', 'payments' => [
            ['payment_id' => NEW_ENTRY, 'payment_type' => lang('Sales.cash'), 'payment_amount' => '10.00', 'employee_id' => $this->employeeId, 'payment_status' => 'completed', 'reference_code' => ''],
            ['payment_id' => NEW_ENTRY, 'payment_type' => lang('Sales.due'), 'payment_amount' => '10.00', 'employee_id' => $this->employeeId, 'payment_status' => 'completed', 'reference_code' => ''],
        ]]);

        $this->assertFalse($paid);
        $this->assertSame(PostingError::PaymentTypeUnknown, $this->purchases->accountingFailure()->error);
        $this->assertSame(0, $this->countRows('receivings_payments'), 'The first payment of the save went with the rollback');
        $this->assertSame($journals, $this->countRows('accounting_journals'));
        $this->assertSame('test', $this->accountingDb()->table('receivings')->where('receiving_id', $purchase)->get()->getRow()->comment, 'The comment of the same save did not stay either');
    }

    public function testUpdate_ChangesThePurchaseAndItsPaymentsTogether(): void
    {
        $purchase = $this->purchaseOf('100.00');

        $this->assertTrue($this->purchases->update($purchase, ['comment' => 'with a payment', 'payments' => [
            ['payment_id' => NEW_ENTRY, 'payment_type' => lang('Sales.cash'), 'payment_amount' => '10.00', 'employee_id' => $this->employeeId, 'payment_status' => 'completed', 'reference_code' => ''],
        ]]));

        $this->assertSame('with a payment', $this->accountingDb()->table('receivings')->where('receiving_id', $purchase)->get()->getRow()->comment);
        $this->assertSame(1, $this->countRows('receivings_payments'));
    }

    public function testUpdate_StoresThePaymentAndPostsNothingWhenTheBooksAreOff(): void
    {
        $purchase = $this->purchaseOf('100.00');
        $this->setConfigValue('accounting_enabled', '0');
        $journals = $this->countRows('accounting_journals');

        $this->assertTrue($this->purchases->update($purchase, ['payments' => [
            ['payment_id' => NEW_ENTRY, 'payment_type' => lang('Sales.cash'), 'payment_amount' => '40.00', 'employee_id' => $this->employeeId, 'payment_status' => 'completed', 'reference_code' => ''],
        ]]));

        $this->assertSame(1, $this->countRows('receivings_payments'));
        $this->assertSame($journals, $this->countRows('accounting_journals'));
        $this->assertSame(0, $this->countRows('accounting_posting_failures'));
    }

    public function testDeleteValue_TakesBackThePaymentsOfAPurchaseWithItAndRemovesTheirRows(): void
    {
        $purchase = $this->purchaseOf('100.00');
        $one      = $this->payPurchase($purchase, '30.00');
        $two      = $this->payPurchase($purchase, '20.00');

        $this->assertSame('50.00', $this->owed());
        $this->assertTrue($this->purchases->delete_value($purchase, $this->employeeId));

        $this->assertSame(0, $this->countRows('receivings_payments'));
        $this->assertSame('reversed', $this->paymentJournals($one)[0]->status);
        $this->assertSame('reversed', $this->paymentJournals($two)[0]->status);
        $this->assertSame('0.00', $this->owed());
        $this->assertSupplierTies();
    }

    public function testDeleteValue_LeavesThePurchaseAndItsPaymentsWhenThePeriodIsClosed(): void
    {
        $purchase = $this->purchaseOf('100.00');
        $payment  = $this->payPurchase($purchase, '30.00');
        $journals = $this->countRows('accounting_journals');

        $this->closeThrough(date('Y-m-d'));

        $this->assertFalse($this->purchases->delete_value($purchase, $this->employeeId));

        $this->assertSame(1, $this->accountingDb()->table('receivings')->where('receiving_id', $purchase)->countAllResults());
        $this->assertSame(1, $this->accountingDb()->table('receivings_payments')->where('payment_id', $payment)->countAllResults());
        $this->assertSame($journals, $this->countRows('accounting_journals'));
        $this->assertSame('70.00', $this->owed());
    }

    public function testJournalsOf_ListsTheJournalsOfThePaymentsWithThoseOfThePurchase(): void
    {
        $purchase = $this->purchaseOf('100.00', null, lang('Sales.cash'));
        $payment  = $this->payPurchase($purchase, '10.00');

        $sources = array_map(static fn (object $journal): string => $journal->source_type . ':' . $journal->source_id, (new ReceivingPosting())->journalsOf($purchase));

        $this->assertSame(['purchase:' . $purchase, 'purchase_payment:' . $purchase, 'supplier_payment:' . $payment], $sources);
    }

    public function testUpdate_PutsThePaymentJournalInTheBranchOfThePurchase(): void
    {
        $branch   = $this->createBranch();
        $location = $this->anotherLocation('Branch store');

        $this->accountingDb()->table('stock_locations')->where('location_id', $location)->update(['branch_id' => $branch]);

        $item     = $this->item('0.00');
        $purchase = $this->buy([$this->purchaseLine($item, 0, '1', '100.00', '0', '0', PERCENT, $location)]);
        $payment  = $this->payPurchase($purchase, '40.00');

        $this->assertGreaterThan(0, $purchase);
        $this->assertSame($branch, (int) $this->paymentJournals($payment)[0]->branch_id);
    }

    // ---------------------------------------------------------------------------------------
    // The payment made at once, whatever the dates of the payments made afterwards
    // ---------------------------------------------------------------------------------------

    public function testPostCompleted_PostsThePaymentMadeAtOnceOnTheDayOfThePurchaseWhateverTheDatesOfLaterPayments(): void
    {
        $purchase = $this->purchaseOf('100.00', null, lang('Sales.cash'));

        $this->assertTrue($this->purchases->update($purchase, ['payments' => [
            ['payment_id' => NEW_ENTRY, 'payment_type' => lang('Sales.cash'), 'payment_amount' => '10.00', 'employee_id' => $this->employeeId, 'payment_status' => 'completed', 'reference_code' => '', 'payment_date' => date('Y-m-d', strtotime('-5 days'))],
        ]]));

        $immediate = $this->accountingDb()->table('accounting_journals')->where(['source_type' => 'purchase_payment', 'source_id' => $purchase])->get()->getRow();

        $this->assertSame(date('Y-m-d'), $immediate->journal_date);
        $this->assertSame(1, (int) $immediate->revision);
    }

    // ---------------------------------------------------------------------------------------
    // What the reference system recorded in observation package F: fixtures T-14 to T-16 and T-18
    // ---------------------------------------------------------------------------------------

    /**
     * @return array<string, mixed>
     */
    private function parityCase(string $fixture, string $kind, string $id, string $classification): array
    {
        $case = ParityFixture::cases($fixture, $kind)[$id][0];

        $this->assertSame($classification, $case['classification'], $id);

        if ($classification === 'exact') {
            $this->assertSame($case['reference'], $case['expected'], "{$id} is recorded as exact and must expect what the reference system recorded");
        } else {
            $this->assertNotSame($case['reference'], $case['expected'], "{$id} is recorded as a difference and cannot expect what the reference system does");
        }

        return $case;
    }

    private function costOfSale(int $saleId): string
    {
        $row = $this->accountingDb()->table('accounting_journals')->where(['source_type' => 'sale', 'source_id' => $saleId, 'entry_type' => 'cogs'])->get()->getRow();

        return bcadd((string) $row->total_debit, '0', 4);
    }

    /**
     * Fixture T-14: 3 units bought at 100 and 4 at 50, all 7 sold, 2 more sold with none left, then
     * 5 bought at 80. The line carries the average the item carries, 71.428571.
     *
     * @return array<string, string>
     */
    private function costSequence(): array
    {
        $item = $this->item('100.00');

        $this->assertGreaterThan(0, $this->buy([$this->purchaseLine($item, 0, '3', '100', '15')]));
        $this->assertGreaterThan(0, $this->buy([$this->purchaseLine($item, 0, '4', '50', '15')]));

        $average = $this->exactCostOf($item);
        $all     = $this->save([$this->line($item, 0, '7', '200', $average)]);
        $two     = $this->save([$this->line($item, 0, '2', '200', $average)]);

        $this->assertGreaterThan(0, $all);
        $this->assertGreaterThan(0, $two);

        $costAll = $this->costOfSale($all);
        $costTwo = $this->costOfSale($two);

        $this->assertGreaterThan(0, $this->buy([$this->purchaseLine($item, 0, '5', '80', '15')]));

        return [
            'average_cost'   => number_format((float) $average, 2, '.', ''),
            'cost_of_all'    => $costAll,
            'cost_of_two'    => $costTwo,
            'item_cost_last' => number_format((float) $this->exactCostOf($item), 2, '.', ''),
            'quantity_last'  => $this->onHand($item),
            'value_last'     => (new StockLedger())->position($item, self::LOCATION_ID)['value'],
        ];
    }

    public function testSaveValue_CostsASaleOfTheWholeStockAtTheExactAverageAsTheReferenceSystemDoes(): void
    {
        $case = $this->parityCase('T-14', 'cost', 'cogs-of-all-7-units-sold', 'exact');
        $made = $this->costSequence();

        $this->assertSame($case['expected']['average_cost'], $made['average_cost']);
        $this->assertSame($case['expected']['cost_journal'], $made['cost_of_all']);
        $this->assertSame($case['reference']['cost_journal'], $made['cost_of_all']);
    }

    public function testSaveValue_CostsASaleWithNoStockAtTheAverageTheLastUnitsWentOutAtAsTheReferenceSystemDoes(): void
    {
        $case = $this->parityCase('T-14', 'cost', 'cogs-of-2-units-sold-with-no-stock-left', 'exact');
        $made = $this->costSequence();

        $this->assertSame($case['expected']['cost_journal'], $made['cost_of_two']);
        $this->assertSame($case['reference']['cost_journal'], $made['cost_of_two']);
        $this->assertSame($case['reference']['shown_as'], number_format((float) $made['cost_of_two'], 2, '.', ''), 'Shown the same and stored the same');
    }

    public function testSaveValue_GivesUnitsBoughtAfterNegativeStockTheirOwnCostAsTheReferenceSystemDoes(): void
    {
        $case = $this->parityCase('T-14', 'cost', 'average-after-negative-stock-and-a-purchase', 'exact');
        $made = $this->costSequence();

        $this->assertSame($case['expected']['item_cost'], $made['item_cost_last']);
        $this->assertSame($case['expected']['quantity'], $made['quantity_last']);
    }

    public function testSaveValue_ValuesTheStockAfterTheSequenceAsTheReferenceSystemDoes(): void
    {
        $case = $this->parityCase('T-14', 'cost', 'stock-value-in-the-books-after-the-sequence', 'exact');
        $made = $this->costSequence();

        $this->assertSame($case['expected']['value_of_3_units'], $made['value_last']);
        $this->assertSame($case['reference']['value_of_3_units'], $made['value_last']);
    }

    private function sevenUnitsAtAnAverageThatDoesNotEnd(): int
    {
        $item = $this->item('100.00');

        $this->assertGreaterThan(0, $this->buy([$this->purchaseLine($item, 0, '3', '100')]));
        $this->assertGreaterThan(0, $this->buy([$this->purchaseLine($item, 0, '4', '50')]));
        $this->assertSame('71.428571', $this->exactCostOf($item));

        return $item;
    }

    public function testSaveValue_LeavesNoRoundingInTheStockWhenTheWholeStockIsSold(): void
    {
        $item = $this->sevenUnitsAtAnAverageThatDoesNotEnd();
        $sale = $this->save([$this->line($item, 0, '7', '200', $this->exactCostOf($item))]);

        $this->assertGreaterThan(0, $sale);
        $this->assertSame(['quantity' => '0.000', 'value' => '0.0000'], (new StockLedger())->position($item, self::LOCATION_ID));
        $this->assertStockTies();
    }

    public function testSaveValue_KeepsTheCostOfALineAtTheSixDecimalsTheItemCarries(): void
    {
        $item = $this->sevenUnitsAtAnAverageThatDoesNotEnd();
        $sale = $this->save([$this->line($item, 0, '3', '200', $this->exactCostOf($item))]);

        $this->assertGreaterThan(0, $sale);
        $this->assertSame('71.428571', (string) $this->accountingDb()->table('sales_items')->where(['sale_id' => $sale, 'item_id' => $item])->get()->getRow()->item_cost_price);
        $this->assertSame('214.2857', $this->costOfSale($sale), '3 units at 71.428571 come to 214.285713');
    }

    public function testSaveValue_TakesAWholeSaleBackAtExactlyWhatItTookOut(): void
    {
        $item   = $this->sevenUnitsAtAnAverageThatDoesNotEnd();
        $sale   = $this->save([$this->line($item, 0, '7', '200', $this->exactCostOf($item))]);
        $return = $this->save([$this->line($item, 0, '-7', '200', $this->exactCostOf($item))], [], [], SALE_TYPE_RETURN, -1, true, $sale);

        $this->assertGreaterThan(0, $return);
        $this->assertSame($this->costOfSale($sale), $this->costOfSale($return));
        $this->assertSame(['quantity' => '7.000', 'value' => '500.0000'], (new StockLedger())->position($item, self::LOCATION_ID));
        $this->assertStockTies();
    }

    /**
     * What a purchase journal put on each role, as an amount.
     *
     * @return array<string, string>
     */
    private function amountsOfPurchase(int $receivingId): array
    {
        $roles   = array_flip($this->roles());
        $journal = $this->purchaseJournals($receivingId)[0];
        $amounts = [];

        foreach ($journal['lines'] as $line) {
            [$account, $debit, $credit] = explode(':', $line);

            if (isset($roles[(int) $account])) {
                $amounts[$roles[(int) $account]] = bcadd($debit, $credit, 2);
            }
        }

        return $amounts;
    }

    public function testSaveValue_BooksAReturnOfPartOfAPurchaseAndRefusesOneBeyondWhatRemainsAsTheReferenceSystemDoes(): void
    {
        $booked  = $this->parityCase('T-15', 'purchase_return', 'return-of-4-of-10', 'exact');
        $refused = $this->parityCase('T-15', 'purchase_return', 'return-beyond-what-remains-is-refused', 'exact');
        $average = $this->parityCase('T-15', 'purchase_return', 'return-does-not-change-the-average-cost', 'exact');

        $item     = $this->item('100.00');
        $purchase = $this->buy([$this->purchaseLine($item, 0, '10', '100', '15')], -1, lang('Sales.cash'));
        $return   = $this->buy([$this->purchaseLine($item, 0, '-4', '100', '15')], -1, lang('Sales.cash'), $purchase);

        $this->assertGreaterThan(0, $return);

        $amounts = $this->amountsOfPurchase($return);

        $this->assertSame($booked['expected']['supplier'], $amounts['supplier']);
        $this->assertSame($booked['expected']['input_tax'], $amounts['tax_input']);
        $this->assertSame($booked['expected']['inventory'], $amounts['inventory']);

        [, $refund] = explode(':', $this->purchaseJournals($return)[1]['lines'][0]);

        $this->assertSame($booked['expected']['refund_in_cash'], $refund);

        $saved = 0;

        foreach ($refused['inputs']['attempts'] as $units) {
            $attempt = $this->buy([$this->purchaseLine($item, 0, '-' . $units, '100', '15')], -1, lang('Sales.cash'), $purchase);

            $this->assertRefusedWith(PostingError::PurchaseReturnExceedsOrigin, $attempt);
            $saved += $attempt > 0 ? 1 : 0;
        }

        $this->assertSame($refused['expected']['attempts_saved'], (string) $saved);
        $this->assertSame($refused['expected']['stock'], $this->onHand($item));
        $this->assertSame($average['expected']['average_after_the_return'], bcadd($this->itemCostOf($item), '0', 2));

        $this->assertGreaterThan(0, $this->buy([$this->purchaseLine($item, 0, '6', '70', '15')], -1, lang('Sales.cash')));
        $this->assertSame($average['expected']['average_after_the_purchase'], bcadd($this->itemCostOf($item), '0', 2));
        $this->assertSame($average['expected']['quantity'], $this->onHand($item));
        $this->assertStockTies();
    }

    public function testSaveValue_TakesALineDiscountOffTheCostAndTheTaxTogetherAsTheReferenceSystemDoes(): void
    {
        $case = $this->parityCase('T-16', 'purchase_discount', 'line-discount-percent', 'exact');
        $item = $this->item('100.00');
        $id   = $this->buy([$this->purchaseLine($item, 0, '10', '100', '15', '10', PERCENT)], -1, lang('Sales.cash'));

        $this->assertGreaterThan(0, $id);

        $amounts = $this->amountsOfPurchase($id);

        $this->assertSame($case['expected']['inventory'], $amounts['inventory']);
        $this->assertSame($case['expected']['input_tax'], $amounts['tax_input']);
        $this->assertSame($case['expected']['supplier'], $amounts['supplier']);
        $this->assertSame($case['expected']['unit_cost'], bcadd($this->itemCostOf($item), '0', 4));
    }

    public function testSaveValue_TakesAFixedLineDiscountOffEachUnitAsTheReferenceSystemDoes(): void
    {
        $case = $this->parityCase('T-16', 'purchase_discount', 'line-discount-fixed-is-taken-off-each-unit', 'exact');
        $item = $this->item('100.00');
        $id   = $this->buy([$this->purchaseLine($item, 0, '10', '100', '15', '50', FIXED)], -1, lang('Sales.cash'));

        $this->assertGreaterThan(0, $id);

        $amounts = $this->amountsOfPurchase($id);

        $this->assertSame($case['expected']['inventory'], $amounts['inventory']);
        $this->assertSame($case['expected']['input_tax'], $amounts['tax_input']);
        $this->assertSame($case['expected']['supplier'], $amounts['supplier']);
        $this->assertSame($case['expected']['unit_cost'], bcadd($this->itemCostOf($item), '0', 4));
    }

    /**
     * @return array<string, array{array<string, mixed>}>
     */
    public static function goldenProvider(): array
    {
        $sets = [];

        foreach (self::goldenScenarios() as $scenario) {
            $sets[$scenario['name']] = [$scenario];
        }

        return $sets;
    }

    /**
     * The expected figures were recorded by running the same purchases through the code as it was
     * before the discount on the whole purchase existed.
     *
     * @param array<string, mixed> $scenario
     */
    #[DataProvider('goldenProvider')]
    public function testSaveValue_KeepsEveryJournalMovementCostAndReportRowOfAPurchaseSavedWithoutADiscountOnTheWholePurchase(array $scenario): void
    {
        $this->assertSame($scenario['expected'], $this->runGoldenScenario($scenario), $scenario['name']);
        $this->assertStockTies();
    }

    public function testSaveValue_StoresNoDiscountOnTheWholePurchaseWhenNoneWasGiven(): void
    {
        $item = $this->item('0.00');
        $id   = $this->buy([$this->purchaseLine($item, 0, '4', '25', '15', '10', PERCENT), $this->purchaseLine($item, 1, '1', '9.99', '15')]);

        $this->assertGreaterThan(0, $id);

        $header = $this->accountingDb()->table('receivings')->select('invoice_discount_type, invoice_discount')->where('receiving_id', $id)->get()->getRow();

        $this->assertNull($header->invoice_discount_type);
        $this->assertSame('0.0000', bcadd((string) $header->invoice_discount, '0', 4));
        $this->assertSame([null, null], array_map(static fn (object $line) => $line->invoice_discount_share, $this->accountingDb()->table('receivings_items')->select('invoice_discount_share')->where('receiving_id', $id)->orderBy('line', 'ASC')->get()->getResult()));
    }

    public function testSaveValue_TakesTheDiscountOnTheWholePurchaseOffTheCostAndTheTaxAfterTheLineDiscountAsTheReferenceSystemDoes(): void
    {
        $case = $this->parityCase('T-16', 'purchase_discount', 'discount-on-the-whole-purchase-after-the-line-discount', 'exact');
        $item = $this->item('100.00');
        $id   = $this->buy([$this->purchaseLine($item, 0, '10', '100', '15', '10', PERCENT)], -1, lang('Sales.cash'), null, InvoiceDiscount::of(PERCENT, '10'));

        $this->assertGreaterThan(0, $id);

        $amounts = $this->amountsOfPurchase($id);

        $this->assertSame($case['expected']['inventory'], $amounts['inventory']);
        $this->assertSame($case['expected']['input_tax'], $amounts['tax_input']);
        $this->assertSame($case['expected']['supplier'], $amounts['supplier']);
        $this->assertSame('81.0000', bcadd($this->itemCostOf($item), '0', 4), 'Ten units that cost 810 after both discounts');
        $this->assertSame($this->expectedLines([['inventory', '810.00', '0.00'], ['tax_input', '121.50', '0.00'], ['supplier', '0.00', '931.50']]), $this->standingPurchase($id)[0]['lines'], 'No journal line says discount: it is inside the cost and the tax');
        $this->assertSame($this->expectedLines([['supplier', '931.50', '0.00'], ['cash', '0.00', '931.50']]), $this->standingPurchase($id, 'purchase_payment')[0]['lines']);
        $this->assertSame(['quantity' => '10.000', 'value' => '810.00'], $this->stockOf($item));
        $this->assertSame('0.00', $this->owed(), 'Paid at once');

        $header = $this->accountingDb()->table('receivings')->select('invoice_discount_type, invoice_discount')->where('receiving_id', $id)->get()->getRow();

        $this->assertSame([PERCENT, '10.0000'], [(int) $header->invoice_discount_type, bcadd((string) $header->invoice_discount, '0', 4)]);
        $this->assertSame('90.0000', bcadd((string) $this->accountingDb()->table('receivings_items')->where('receiving_id', $id)->get()->getRow()->invoice_discount_share, '0', 4));
        $this->assertStockTies();
        $this->assertSupplierTies();
    }

    public function testSaveValue_AveragesTheCostOfThePurchasesWithTheLineAndTheInvoiceDiscountsAsTheReferenceSystemDoes(): void
    {
        $case = $this->parityCase('T-16', 'purchase_discount_average', 'average-after-the-three-purchases-with-the-invoice-discount', 'exact');
        $item = $this->item('0.00');

        $this->assertGreaterThan(0, $this->buy([$this->purchaseLine($item, 0, '10', '100', '15', '10', PERCENT)]));
        $this->assertGreaterThan(0, $this->buy([$this->purchaseLine($item, 0, '10', '100', '15', '50', FIXED)]));
        $this->assertGreaterThan(0, $this->buy([$this->purchaseLine($item, 0, '10', '100', '15', '10', PERCENT)], -1, null, null, InvoiceDiscount::of(PERCENT, '10')));

        $this->assertSame($case['expected']['quantity'], $this->onHand($item));
        $this->assertSame($case['expected']['average'], sprintf('%.2F', (float) $this->itemCostOf($item)));
        $this->assertSame(['quantity' => '30.000', 'value' => '2210.00'], $this->stockOf($item));
        $this->assertStockTies();
    }

    public function testSaveValue_SharesAnAmountOverTheLinesInProportionToWhatTheyCostAndPutsTheRemainderOnTheLast(): void
    {
        $first   = $this->item('0.00');
        $second  = $this->item('0.00');
        $service = $this->item('0.00', false);

        $id = $this->buy([
            $this->purchaseLine($first, 0, '3', '33.33', '15'),
            $this->purchaseLine($second, 1, '2', '20.01', '5'),
            $this->purchaseLine($service, 2, '1', '10.00'),
        ], -1, null, null, InvoiceDiscount::of(FIXED, '15'));

        $this->assertGreaterThan(0, $id);

        $shares = array_map(static fn (object $line): string => bcadd((string) $line->invoice_discount_share, '0', 4), $this->accountingDb()->table('receivings_items')->select('invoice_discount_share')->where('receiving_id', $id)->orderBy('line', 'ASC')->get()->getResult());

        $this->assertSame(['10.0000', '4.0000', '1.0000'], $shares);
        $this->assertSame('15.0000', array_reduce($shares, static fn (string $sum, string $share): string => bcadd($sum, $share, 4), '0'));

        $roles = $this->roles();

        $this->assertSame(
            [$roles['inventory'] . ':126.0105:0.0000', $roles['cogs'] . ':9.0000:0.0000', $roles['tax_input'] . ':15.2995:0.0000', $roles['supplier'] . ':0.0000:150.3100'],
            $this->exactLines($this->standingPurchase($id)[0]['id']),
            'Each line costs what it came to less its share, the tax follows, the supplier is owed the total to two decimals and the stock takes the rounding',
        );
        $this->assertSame('89.9905', bcadd((new StockLedger())->position($first, self::LOCATION_ID)['value'], '0', 4));
        $this->assertSame('36.0200', bcadd((new StockLedger())->position($second, self::LOCATION_ID)['value'], '0', 4));
        $this->assertSame('29.9966', bcadd($this->itemCostOf($first), '0', 4));
        $this->assertSame('18.0100', bcadd($this->itemCostOf($second), '0', 4));
        $this->assertStockTies();
        $this->assertSupplierTies();
    }

    public function testSaveValue_WeighsTheCostOfTheStockAtWhatTheUnitsCostAfterTheDiscountOnTheWholePurchase(): void
    {
        $item = $this->item('0.00');

        $this->assertGreaterThan(0, $this->buy([$this->purchaseLine($item, 0, '10', '50')]));
        $this->assertGreaterThan(0, $this->buy([$this->purchaseLine($item, 0, '10', '60')], -1, null, null, InvoiceDiscount::of(FIXED, '100')));

        $this->assertSame('50.0000', $this->itemCostOf($item), 'The second ten cost 500 after the discount, which is 50 a unit');
        $this->assertSame(['quantity' => '20.000', 'value' => '1000.00'], $this->stockOf($item));
        $this->assertStockTies();
    }

    public function testSaveValue_AcceptsADiscountOfTheWholePurchaseAndKeepsTheGoodsThatCostNothing(): void
    {
        $item = $this->item('0.00');
        $id   = $this->buy([$this->purchaseLine($item, 0, '5', '20')], -1, null, null, InvoiceDiscount::of(PERCENT, '100'));

        $this->assertGreaterThan(0, $id);
        $this->assertSame([], $this->standingPurchase($id));
        $this->assertSame(['quantity' => '5.000', 'value' => '0.00'], $this->stockOf($item));
        $this->assertSame('5.000', $this->onHand($item));
        $this->assertStockTies();
    }

    /**
     * @return array<string, array{int, string}>
     */
    public static function refusedDiscounts(): array
    {
        return [
            'a percentage above the whole'         => [PERCENT, '100.0001'],
            'an amount above what the lines cost'  => [FIXED, '1000.01'],
        ];
    }

    #[DataProvider('refusedDiscounts')]
    public function testSaveValue_RefusesADiscountOnTheWholePurchaseBeyondWhatItComesToAndLeavesNothingOfIt(int $type, string $value): void
    {
        $item = $this->item('5.00');

        $result = $this->buy([$this->purchaseLine($item, 0, '10', '100')], -1, null, null, InvoiceDiscount::of($type, $value));

        $this->assertRefusedWith(PostingError::PurchaseDiscountInvalid, $result);
        $this->assertNothingLeft(0, $item, '5.0000');
        $this->assertSame(1, $this->countRows('accounting_posting_failures'), 'The refusal is written down after the rollback, as every other');
    }

    public function testSaveValue_AcceptsADiscountEqualToWhatTheLinesCome(): void
    {
        $item = $this->item('0.00');
        $id   = $this->buy([$this->purchaseLine($item, 0, '10', '100')], -1, null, null, InvoiceDiscount::of(FIXED, '1000'));

        $this->assertGreaterThan(0, $id);
        $this->assertSame('0.00', $this->owed());
    }

    public function testSaveValue_RefusesADiscountOnTheWholePurchaseOnAReturnAndOnATransfer(): void
    {
        $item   = $this->item('0.00');
        $origin = $this->buy([$this->purchaseLine($item, 0, '10', '100')], -1, null, null, InvoiceDiscount::of(FIXED, '100'));
        $second = $this->anotherLocation();

        $return   = $this->buy([$this->purchaseLine($item, 0, '-2', '100')], -1, null, $origin, InvoiceDiscount::of(FIXED, '10'));
        $transfer = $this->buy([$this->purchaseLine($item, 0, '-2', '0'), $this->purchaseLine($item, 1, '2', '0', '0', '0', PERCENT, $second)], null, null, null, InvoiceDiscount::of(PERCENT, '10'));

        $this->assertRefusedWith(PostingError::PurchaseDiscountInvalid, $return);
        $this->assertRefusedWith(PostingError::PurchaseDiscountInvalid, $transfer);
        $this->assertSame(1, $this->countRows('receivings'));
        $this->assertSame('10.000', $this->onHand($item));
    }

    public function testSaveValue_GivesBackTheShareOfTheDiscountOfTheUnitsThatGoBackAndTheLastReturnTakesWhatIsLeft(): void
    {
        $item   = $this->item('0.00');
        $origin = $this->buy([$this->purchaseLine($item, 0, '10', '100', '15')], -1, null, null, InvoiceDiscount::of(FIXED, '100'));

        $this->assertSame('1035.00', $this->owed());

        $first  = $this->buy([$this->purchaseLine($item, 0, '-4', '100', '15')], -1, null, $origin);
        $second = $this->buy([$this->purchaseLine($item, 0, '-6', '100', '15')], -1, null, $origin);

        $this->assertGreaterThan(0, $first);
        $this->assertGreaterThan(0, $second);

        $this->assertSame($this->expectedLines([['supplier', '414.00', '0.00'], ['tax_input', '0.00', '54.00'], ['inventory', '0.00', '360.00']]), $this->standingPurchase($first)[0]['lines'], 'Four of ten units take four tenths of the 100 given, 40');
        $this->assertSame($this->expectedLines([['supplier', '621.00', '0.00'], ['tax_input', '0.00', '81.00'], ['inventory', '0.00', '540.00']]), $this->standingPurchase($second)[0]['lines'], 'The rest takes the 60 that is left');
        $this->assertSame('0.00', $this->owed(), 'Everything came back, so nothing is owed');
        $this->assertSame(['quantity' => '0.000', 'value' => '0.00'], $this->stockOf($item));
        $this->assertSame('0.00', $this->balance($this->accountId('tax_input')));
        $this->assertSame('0.00', $this->balance($this->accountId('cogs')));
        $this->assertStockTies();
        $this->assertSupplierTies();
    }

    public function testSaveValue_SplitsADiscountThatDoesNotDivideAmongTheReturnsWithoutLosingACent(): void
    {
        $item   = $this->item('0.00');
        $origin = $this->buy([$this->purchaseLine($item, 0, '3', '10')], -1, null, null, InvoiceDiscount::of(FIXED, '10'));
        $shares = [];

        for ($turn = 0; $turn < 3; $turn++) {
            $return   = $this->buy([$this->purchaseLine($item, 0, '-1', '10')], -1, null, $origin);
            $shares[] = bcadd((string) $this->accountingDb()->table('receivings_items')->where('receiving_id', $return)->get()->getRow()->invoice_discount_share, '0', 4);
        }

        $this->assertSame(['3.3300', '3.3300', '3.3400'], $shares);
        $this->assertSame('0.00', $this->owed());
        $this->assertSame(['quantity' => '0.000', 'value' => '0.00'], $this->stockOf($item));
        $this->assertStockTies();
    }

    public function testSaveValue_GivesBackAPercentageDiscountInProportionAndLeavesTheCostOfTheStockAtTheAverage(): void
    {
        $item   = $this->item('0.00');
        $origin = $this->buy([$this->purchaseLine($item, 0, '10', '100', '15', '10', PERCENT)], -1, null, null, InvoiceDiscount::of(PERCENT, '10'));
        $cost   = $this->itemCostOf($item);

        $first = $this->buy([$this->purchaseLine($item, 0, '-4', '100', '15', '10', PERCENT)], -1, null, $origin);

        $this->assertGreaterThan(0, $first);
        $this->assertSame($cost, $this->itemCostOf($item), 'A return does not change the average');
        $this->assertSame($this->expectedLines([['supplier', '372.60', '0.00'], ['tax_input', '0.00', '48.60'], ['inventory', '0.00', '324.00']]), $this->standingPurchase($first)[0]['lines'], 'Four units cost 360 after their own discount, less 36 of the 90 the whole purchase was given');
        $this->assertSame('558.90', $this->owed());

        $rest = $this->buy([$this->purchaseLine($item, 0, '-6', '100', '15', '10', PERCENT)], -1, null, $origin);

        $this->assertGreaterThan(0, $rest);
        $this->assertSame($this->expectedLines([['supplier', '558.90', '0.00'], ['tax_input', '0.00', '72.90'], ['inventory', '0.00', '486.00']]), $this->standingPurchase($rest)[0]['lines']);
        $this->assertSame('0.00', $this->owed());
        $this->assertStockTies();
        $this->assertSupplierTies();
    }

    public function testSaveValue_GivesAReturnNoShareWhenThePurchaseItComesFromHadNoDiscount(): void
    {
        $item   = $this->item('0.00');
        $origin = $this->buy([$this->purchaseLine($item, 0, '10', '100', '15')]);
        $return = $this->buy([$this->purchaseLine($item, 0, '-4', '100', '15')], -1, null, $origin);

        $this->assertGreaterThan(0, $return);
        $this->assertNull($this->accountingDb()->table('receivings_items')->where('receiving_id', $return)->get()->getRow()->invoice_discount_share);
        $this->assertSame($this->expectedLines([['supplier', '460.00', '0.00'], ['tax_input', '0.00', '60.00'], ['inventory', '0.00', '400.00']]), $this->standingPurchase($return)[0]['lines']);
    }

    public function testDeleteValue_TakesBackAPurchaseWithADiscountOnTheWholePurchaseToTheCent(): void
    {
        $item = $this->item('0.00');
        $id   = $this->buy([$this->purchaseLine($item, 0, '10', '100', '15', '10', PERCENT)], -1, lang('Sales.cash'), null, InvoiceDiscount::of(PERCENT, '10'));

        $this->assertTrue($this->purchases->delete_value($id, $this->employeeId));

        $this->assertSame([], $this->standingPurchase($id));
        $this->assertSame('0.00', $this->balance($this->stockAccount()));
        $this->assertSame('0.00', $this->balance($this->supplierAccount()));
        $this->assertSame('0.00', $this->balance($this->accountId('cash')));
        $this->assertSame('0.00', $this->balance($this->accountId('tax_input')));
        $this->assertSame(['quantity' => '0.000', 'value' => '0.00'], $this->stockOf($item));
        $this->assertSame(0, $this->accountingDb()->table('receivings')->where('receiving_id', $id)->countAllResults());
        $this->assertStockTies();
    }

    public function testDeleteValue_RefusesAPurchaseWithADiscountThatGoodsHaveGoneBackFromAndChangesNothing(): void
    {
        $item   = $this->item('0.00');
        $origin = $this->buy([$this->purchaseLine($item, 0, '10', '100')], -1, null, null, InvoiceDiscount::of(FIXED, '100'));
        $this->buy([$this->purchaseLine($item, 0, '-4', '100')], -1, null, $origin);

        $this->assertFalse($this->purchases->delete_value($origin, $this->employeeId));
        $this->assertSame(['quantity' => '6.000', 'value' => '540.00'], $this->stockOf($item));
        $this->assertSame('540.00', $this->owed());
    }

    public function testUpdate_LeavesThePurchaseJournalAloneWhenAPaymentIsAddedToAPurchaseWithADiscount(): void
    {
        $item = $this->item('0.00');
        $id   = $this->buy([$this->purchaseLine($item, 0, '10', '100', '15', '10', PERCENT)], -1, null, null, InvoiceDiscount::of(PERCENT, '10'));
        $then = $this->standingPurchase($id)[0];

        $this->payPurchase($id, '500.00');

        $now = $this->standingPurchase($id)[0];

        $this->assertSame([$then['id'], $then['revision'], $then['lines']], [$now['id'], $now['revision'], $now['lines']]);
        $this->assertSame('431.50', bcadd($this->supplierPosition()->purchase($id)->open(), '0', 2));
        $this->assertSame('431.50', $this->owed());
        $this->assertSupplierTies();
        $this->assertStockTies();
    }

    public function testUpdate_ReversesAndPostsAgainAPaymentOfAPurchaseWithADiscountAndKeepsTheSupplierTied(): void
    {
        $item    = $this->item('0.00');
        $id      = $this->buy([$this->purchaseLine($item, 0, '10', '100', '15', '10', PERCENT)], -1, null, null, InvoiceDiscount::of(PERCENT, '10'));
        $payment = $this->payPurchase($id, '500.00');
        $before  = $this->standingPurchase($id)[0];

        $this->assertTrue($this->changePayment($id, $payment, ['payment_amount' => '300.00']));

        $after = $this->standingPurchase($id)[0];

        $this->assertSame([$before['id'], $before['revision'], $before['lines']], [$after['id'], $after['revision'], $after['lines']], 'The journal of the purchase is not touched by a change of its payments');
        $this->assertSame('300.00', $this->paymentInTheBooks($payment));
        $this->assertSame('631.50', bcadd($this->supplierPosition()->purchase($id)->open(), '0', 2));
        $this->assertSupplierTies();
        $this->assertStockTies();
    }

    public function testDeleteValue_TakesBackAReturnOfAPurchaseWithADiscountAndLetsTheNextReturnTakeItsShareAgain(): void
    {
        $item   = $this->item('0.00');
        $origin = $this->buy([$this->purchaseLine($item, 0, '10', '100')], -1, null, null, InvoiceDiscount::of(FIXED, '100'));
        $first  = $this->buy([$this->purchaseLine($item, 0, '-4', '100')], -1, null, $origin);

        $this->assertSame('540.00', $this->owed(), 'Nine hundred less the 360 of four units');
        $this->assertTrue($this->purchases->delete_value($first, $this->employeeId));
        $this->assertSame('900.00', $this->owed());

        $all = $this->buy([$this->purchaseLine($item, 0, '-10', '100')], -1, null, $origin);

        $this->assertGreaterThan(0, $all);
        $this->assertSame('100.0000', bcadd((string) $this->accountingDb()->table('receivings_items')->where('receiving_id', $all)->get()->getRow()->invoice_discount_share, '0', 4), 'The deleted return no longer holds back any of the discount');
        $this->assertSame('0.00', $this->owed());
        $this->assertStockTies();
        $this->assertSupplierTies();
    }

    public function testCreateTempTable_TakesTheShareOfTheDiscountOnTheWholePurchaseOffTheLinesAndTheReturns(): void
    {
        $item   = $this->item('0.00');
        $origin = $this->buy([$this->purchaseLine($item, 0, '10', '100', '15', '10', PERCENT)], -1, null, null, InvoiceDiscount::of(PERCENT, '10'));
        $return = $this->buy([$this->purchaseLine($item, 0, '-4', '100', '15', '10', PERCENT)], -1, null, $origin);
        $row    = $this->reportRowOf($origin);

        $this->assertSame('810.0000', bcadd((string) $row->total, '0', 4));
        $this->assertSame('810.0000', bcadd((string) $row->subtotal, '0', 4));
        $this->assertSame('-324.0000', bcadd((string) $this->reportRowOf($return)->total, '0', 4), 'A return of four tenths takes four tenths of the 90, 36, so 360 less 36');
        $this->assertSame($this->purchaseNetOf($origin), bcadd((string) $row->total, '0', 4));
    }

    /**
     * What the stock lines and the expense of a purchase journal come to, which is what the report
     * adds up too.
     */
    private function purchaseNetOf(int $receivingId): string
    {
        $journal = $this->standingPurchase($receivingId)[0];
        $net     = '0';

        foreach ($this->exactLines($journal['id']) as $line) {
            [$account, $debit] = explode(':', $line);

            if (in_array((int) $account, [$this->roles()['inventory'], $this->roles()['cogs']], true)) {
                $net = bcadd($net, $debit, 4);
            }
        }

        return $net;
    }

    public function testSaveValue_StoresTheDiscountAndTheSharesAndKeepsTheAverageWhileAccountingIsOff(): void
    {
        $item = $this->item('0.00');

        $this->setConfigValue('accounting_enabled', '0');
        $this->setConfigValue('receiving_calculate_average_price', '1');
        config(OSPOS::class)->settings['receiving_calculate_average_price'] = '1';

        $id = $this->buy([$this->purchaseLine($item, 0, '10', '100', '15', '10', PERCENT)], -1, null, null, InvoiceDiscount::of(PERCENT, '10'));

        $this->assertGreaterThan(0, $id);
        $this->assertSame(0, $this->countRows('accounting_journals'));
        $this->assertSame(0, $this->countRows('accounting_stock_entries'));
        $this->assertSame('90.0000', bcadd((string) $this->accountingDb()->table('receivings_items')->where('receiving_id', $id)->get()->getRow()->invoice_discount_share, '0', 4));
        $this->assertSame('81.0000', $this->itemCostOf($item));
        $this->assertSame('10.000', $this->onHand($item));
    }

    public function testSaveValue_RefusesADiscountBeyondThePurchaseWhileAccountingIsOffToo(): void
    {
        $item = $this->item('5.00');

        $this->setConfigValue('accounting_enabled', '0');

        $result = $this->buy([$this->purchaseLine($item, 0, '10', '100')], -1, null, null, InvoiceDiscount::of(FIXED, '1000.01'));

        $this->assertRefusedWith(PostingError::PurchaseDiscountInvalid, $result);
        $this->assertSame(0, $this->countRows('receivings'));
        $this->assertSame('0.000', $this->onHand($item));
        $this->assertSame('5.0000', $this->itemCostOf($item));
        $this->assertSame(0, $this->countRows('accounting_posting_failures'), 'The books are off, so nothing is written there');
    }

    public function testSaveValue_PostsAPurchaseWithADiscountInTheBranchOfItsLocationAndOnlyTheClosedPeriodOfThatBranchStopsIt(): void
    {
        $item   = $this->item('0.00');
        $branch = $this->createBranch('B3', 'Third branch');
        $other  = $this->locationOfBranch($branch, 'Branch warehouse');

        $this->closeThrough(date('Y-m-d'), 1);

        $id = $this->buy([$this->purchaseLine($item, 0, '10', '100', '15', '0', PERCENT, $other)], -1, null, null, InvoiceDiscount::of(PERCENT, '10'));

        $this->assertGreaterThan(0, $id, 'The period of the main branch is closed, and this is the other branch');
        $this->assertSame($branch, $this->purchases->getBranchId($id));
        $this->assertSame($branch, (int) $this->accountingDb()->table('accounting_journals')->where('source_type', 'purchase')->where('source_id', $id)->get()->getRow()->branch_id);
        $roles = $this->roles();

        $this->assertSame([$this->stockAccountOf($other) . ':900.0000:0.0000', $roles['tax_input'] . ':135.0000:0.0000', $roles['supplier'] . ':0.0000:1035.0000'], $this->exactLines($this->standingPurchase($id)[0]['id']));

        $this->closeThrough(date('Y-m-d'), $branch);

        $refused = $this->buy([$this->purchaseLine($item, 0, '10', '100', '15', '0', PERCENT, $other)], -1, null, null, InvoiceDiscount::of(PERCENT, '10'));

        $this->assertRefusedWith(PostingError::PeriodClosed, $refused);
        $this->assertSame(1, $this->countRows('receivings'), 'Nothing of the refused purchase is left, not even what it took of the discount');
        $this->assertStockTies();
    }

    public function testSaveValue_RefusesADiscountedPurchaseThatWorksInTwoBranches(): void
    {
        $item  = $this->item('0.00');
        $other = $this->locationOfBranch($this->createBranch('B3', 'Third branch'), 'Branch warehouse');

        $across = $this->buy([$this->purchaseLine($item, 0, '4', '50'), $this->purchaseLine($item, 1, '4', '50', '0', '0', PERCENT, $other)], -1, null, null, InvoiceDiscount::of(PERCENT, '10'));

        $this->assertRefusedWith(PostingError::PurchaseAcrossBranches, $across);
        $this->assertSame(0, $this->countRows('receivings'));
    }

    public function testSaveValue_RefusesAPurchaseWithADiscountInAClosedPeriodAndLeavesNothingOfIt(): void
    {
        $item = $this->item('5.00');
        $this->closeThrough(date('Y-m-d'));

        $result = $this->buy([$this->purchaseLine($item, 0, '10', '40')], -1, null, null, InvoiceDiscount::of(PERCENT, '10'));

        $this->assertRefusedWith(PostingError::PeriodClosed, $result);
        $this->assertNothingLeft(0, $item, '5.0000');
        $this->assertSame(0, $this->accountingDb()->table('receivings_items')->where('invoice_discount_share IS NOT NULL', null, false)->countAllResults());
    }

    /**
     * What a payment has put in the books, as the journals of it that stand.
     */
    private function paymentInTheBooks(int $paymentId): string
    {
        $row = $this->accountingDb()->table('accounting_journals')->selectSum('total_debit', 'total')->where(['source_type' => 'supplier_payment', 'source_id' => $paymentId, 'status' => 'posted'])->get()->getRow();

        return bcadd((string) ($row->total ?? '0'), '0', 2);
    }

    public function testUpdate_PutsAPaymentInTheBooksOnlyWhileItIsCompletedAsTheReferenceSystemDoes(): void
    {
        $case     = $this->parityCase('T-18', 'payment_status', 'only-a-completed-payment-is-in-the-books', 'exact');
        $purchase = $this->purchaseOf('115.00');
        $payment  = $this->payPurchase($purchase, '40.00');

        $this->assertSame($case['expected']['in_books_completed'], $this->paymentInTheBooks($payment));
        $this->assertSame($case['expected']['open_when_completed'], bcadd($this->supplierPosition()->purchase($purchase)->open(), '0', 2));

        foreach (['incomplete', 'review', 'failed', 'draft'] as $status) {
            $this->assertTrue($this->changePayment($purchase, $payment, ['payment_status' => $status]));
            $this->assertSame($case['expected']['in_books_' . $status], $this->paymentInTheBooks($payment), $status);
            $this->assertSame($case['expected']['open_when_not_completed'], bcadd($this->supplierPosition()->purchase($purchase)->open(), '0', 2), $status);
        }

        $this->assertSupplierTies();
    }

    public function testUpdate_PostsAFreshJournalWhenAPaymentIsCompletedAgainAsTheReferenceSystemDoes(): void
    {
        $case     = $this->parityCase('T-18', 'payment_status', 'completing-again-books-a-fresh-journal', 'exact');
        $purchase = $this->purchaseOf('115.00');
        $payment  = $this->payPurchase($purchase, '40.00');
        $first    = (int) $this->accountingDb()->table('accounting_journals')->where(['source_type' => 'supplier_payment', 'source_id' => $payment, 'status' => 'posted'])->get()->getRow()->journal_id;

        $this->assertTrue($this->changePayment($purchase, $payment, ['payment_status' => 'failed']));
        $this->assertTrue($this->changePayment($purchase, $payment, ['payment_status' => 'completed']));

        $standing = $this->accountingDb()->table('accounting_journals')->where(['source_type' => 'supplier_payment', 'source_id' => $payment, 'status' => 'posted'])->get()->getResult();

        $this->assertCount(1, $standing);
        $this->assertNotSame($first, (int) $standing[0]->journal_id);
        $this->assertSame($case['expected']['fresh_journal'], 'yes');
        $this->assertSame($case['expected']['amount'], bcadd((string) $standing[0]->total_debit, '0', 2));
    }

    // ---------------------------------------------------------------------------------------
    // A payment to a supplier out of a treasury of another branch
    // ---------------------------------------------------------------------------------------

    /**
     * A second branch with a treasury of its own, and a buyer who works in both branches.
     *
     * @param array<string, mixed> $more What else the treasury is created with.
     *
     * @return array{branch: int, treasury: int, account: int}
     */
    private function treasuryOfAnotherBranch(array $more = []): array
    {
        $branch   = $this->createBranch('B2');
        $treasury = (new TreasuryService())->create(['name' => 'Branch drawer', 'kind' => 'cash', 'branch_id' => $branch] + $more);

        $this->workInBranches(1, $branch);

        return ['branch' => $branch, 'treasury' => $treasury, 'account' => (int) (new TreasuryService())->accountOf($treasury)];
    }

    /**
     * @param array<string, mixed> $more
     */
    private function payPurchaseFrom(int $receivingId, string $amount, int $treasury, array $more = []): bool
    {
        return $this->purchases->update($receivingId, ['comment' => 'paid', 'payments' => [
            ['payment_id' => NEW_ENTRY, 'payment_type' => lang('Sales.cash'), 'payment_amount' => $amount, 'employee_id' => $this->employeeId, 'payment_status' => 'completed', 'reference_code' => '', 'treasury_id' => $treasury] + $more,
        ]]);
    }

    private function lastPaymentOf(int $receivingId): object
    {
        return $this->accountingDb()->table('receivings_payments')->where('receiving_id', $receivingId)->orderBy('payment_id', 'DESC')->get()->getRow();
    }

    /**
     * The journals of a supplier payment: the ones that stand by source, with their branch and lines.
     *
     * @return array<string, array{branch: int, lines: list<string>, revision: int}>
     */
    private function supplierPaymentJournals(int $paymentId, string $status = 'posted'): array
    {
        $found = [];

        foreach ($this->accountingDb()->table('accounting_journals')->whereIn('source_type', ['supplier_payment', 'supplier_payment_branch'])->where(['source_id' => $paymentId, 'status' => $status])->orderBy('journal_id', 'ASC')->get()->getResult() as $journal) {
            $found[$journal->source_type] = [
                'branch'   => (int) $journal->branch_id,
                'lines'    => array_map(
                    static fn (object $line): string => $line->account_id . ':' . bcadd((string) $line->debit, '0', 2) . ':' . bcadd((string) $line->credit, '0', 2),
                    $this->accountingDb()->table('accounting_journal_lines')->select('account_id, debit, credit')->where('journal_id', $journal->journal_id)->orderBy('line_no', 'ASC')->get()->getResult(),
                ),
                'revision' => (int) $journal->revision,
            ];
        }

        return $found;
    }

    private function assertEveryBranchBalancedAndTheAccountsBetweenBranchesOffset(): void
    {
        foreach ($this->accountingDb()->table('branches')->get()->getResult() as $branch) {
            $sums = $this->accountingDb()->table('accounting_journal_lines l')->select('SUM(l.debit) AS debit, SUM(l.credit) AS credit', false)->join('accounting_journals j', 'j.journal_id = l.journal_id')->where('j.branch_id', $branch->branch_id)->get()->getRow();

            $this->assertSame(bcadd((string) $sums->debit, '0', 4), bcadd((string) $sums->credit, '0', 4), 'The trial balance of branch ' . $branch->code . ' balances');
        }

        $this->assertSame('0.0000', (new InterBranchPosition())->difference());
    }

    public function testUpdate_PaysASupplierOutOfATreasuryOfAnotherBranchAsAJournalInEachBranch(): void
    {
        ['branch' => $branch, 'treasury' => $treasury, 'account' => $account] = $this->treasuryOfAnotherBranch();

        $receivingId = $this->purchaseOf('300.00');

        $this->assertTrue($this->payPurchaseFrom($receivingId, '100.00', $treasury));

        $payment = $this->lastPaymentOf($receivingId);

        $this->assertSame($branch, (int) $payment->treasury_branch_id);
        $this->assertSame([
            'supplier_payment'        => ['branch' => 1, 'lines' => [$this->supplierAccount() . ':100.00:0.00', $this->accountId('branches_payable') . ':0.00:100.00'], 'revision' => 1],
            'supplier_payment_branch' => ['branch' => $branch, 'lines' => [$this->accountId('branches_receivable') . ':100.00:0.00', $account . ':0.00:100.00'], 'revision' => 1],
        ], $this->supplierPaymentJournals((int) $payment->payment_id));
        $this->assertSame('-100.0000', (new TreasuryService())->position($treasury)->balance, 'The treasury holds what its account says');
        $this->assertSame('200.00', $this->owed(), 'The supplier is owed what is left of the purchase');
        $this->assertSupplierTies();
        $this->assertEveryBranchBalancedAndTheAccountsBetweenBranchesOffset();
    }

    public function testUpdate_PostsMoneyTheSupplierGaveBackIntoATreasuryOfAnotherBranchTheOtherWay(): void
    {
        ['branch' => $branch, 'treasury' => $treasury, 'account' => $account] = $this->treasuryOfAnotherBranch();

        $receivingId = $this->purchaseOf('300.00');

        $this->assertTrue($this->payPurchaseFrom($receivingId, '-40.00', $treasury));

        $this->assertSame([
            'supplier_payment'        => ['branch' => 1, 'lines' => [$this->accountId('branches_receivable') . ':40.00:0.00', $this->supplierAccount() . ':0.00:40.00'], 'revision' => 1],
            'supplier_payment_branch' => ['branch' => $branch, 'lines' => [$account . ':40.00:0.00', $this->accountId('branches_payable') . ':0.00:40.00'], 'revision' => 1],
        ], $this->supplierPaymentJournals((int) $this->lastPaymentOf($receivingId)->payment_id));
        $this->assertSame('40.0000', (new TreasuryService())->position($treasury)->balance);
        $this->assertEveryBranchBalancedAndTheAccountsBetweenBranchesOffset();
    }

    public function testUpdate_RejectsATreasuryOfAnotherBranchToABuyerWhoWorksInOnlyOneOfTheTwo(): void
    {
        ['branch' => $branch, 'treasury' => $treasury] = $this->treasuryOfAnotherBranch();

        $receivingId = $this->purchaseOf('300.00');

        foreach ([[1], [$branch]] as $works) {
            $this->workInBranches(...$works);

            $before = [$this->countRows('receivings_payments'), $this->countRows('accounting_journals')];

            $this->assertFalse($this->payPurchaseFrom($receivingId, '100.00', $treasury));
            $this->assertSame(AccountingError::TreasuryNotFound, $this->purchases->accountingFailure()->error);
            $this->assertSame($before, [$this->countRows('receivings_payments'), $this->countRows('accounting_journals')]);
        }

        $this->assertSame('0.0000', (new TreasuryService())->position($treasury)->balance);
    }

    public function testUpdate_AsksTheWithdrawScopeOfTheTreasuryOfTheOtherBranchForAPaymentOutOfIt(): void
    {
        ['treasury' => $treasury] = $this->treasuryOfAnotherBranch(['withdraw_scope' => 'staff', 'withdraw_members' => [$this->employeeWithGrants([])]]);

        $receivingId = $this->purchaseOf('300.00');

        $this->assertFalse($this->payPurchaseFrom($receivingId, '100.00', $treasury));
        $this->assertSame(AccountingError::TreasuryWithdrawDenied, $this->purchases->accountingFailure()->error);
        $this->assertSame(0, $this->countRows('receivings_payments'));
    }

    public function testUpdate_RejectsAPaymentOutOfATreasuryOfAnotherBranchThatIsDeactivated(): void
    {
        ['treasury' => $treasury] = $this->treasuryOfAnotherBranch();

        (new TreasuryService())->deactivate($treasury);

        $this->assertFalse($this->payPurchaseFrom($this->purchaseOf('300.00'), '100.00', $treasury));
        $this->assertSame(AccountingError::TreasuryInactive, $this->purchases->accountingFailure()->error);
    }

    public function testUpdate_LeavesNothingOfThePaymentWhenThePeriodOfEitherBranchIsClosed(): void
    {
        ['branch' => $branch, 'treasury' => $treasury] = $this->treasuryOfAnotherBranch();

        $receivingId = $this->purchaseOf('300.00');

        foreach ([$branch, 1] as $closed) {
            $this->closeThrough(date('Y-m-d'), $closed);

            $before = [$this->countRows('receivings_payments'), $this->countRows('accounting_journals'), $this->countRows('accounting_interbranch_legs')];

            $this->assertFalse($this->payPurchaseFrom($receivingId, '100.00', $treasury));
            $this->assertSame(PostingError::PeriodClosed, $this->purchases->accountingFailure()->error);
            $this->assertSame($before, [$this->countRows('receivings_payments'), $this->countRows('accounting_journals'), $this->countRows('accounting_interbranch_legs')]);

            $this->accountingDb()->table('accounting_closed_periods')->truncate();
        }
    }

    public function testUpdate_ReversesBothJournalsAndPostsThePairAgainWhenThePaymentChanges(): void
    {
        ['branch' => $branch, 'treasury' => $treasury, 'account' => $account] = $this->treasuryOfAnotherBranch();

        $receivingId = $this->purchaseOf('300.00');

        $this->assertTrue($this->payPurchaseFrom($receivingId, '100.00', $treasury));

        $paymentId = (int) $this->lastPaymentOf($receivingId)->payment_id;

        $this->assertTrue($this->changePayment($receivingId, $paymentId, ['payment_amount' => '60.00', 'treasury_id' => $treasury]));
        $this->assertSame([
            'supplier_payment'        => ['branch' => 1, 'lines' => [$this->supplierAccount() . ':60.00:0.00', $this->accountId('branches_payable') . ':0.00:60.00'], 'revision' => 2],
            'supplier_payment_branch' => ['branch' => $branch, 'lines' => [$this->accountId('branches_receivable') . ':60.00:0.00', $account . ':0.00:60.00'], 'revision' => 2],
        ], $this->supplierPaymentJournals($paymentId));
        $this->assertSame(['supplier_payment', 'supplier_payment_branch'], array_keys($this->supplierPaymentJournals($paymentId, 'reversed')));
        $this->assertSame('-60.0000', (new TreasuryService())->position($treasury)->balance);
        $this->assertEveryBranchBalancedAndTheAccountsBetweenBranchesOffset();
    }

    public function testUpdate_LeavesThePairAloneWhenNothingAboutThePaymentChanges(): void
    {
        ['treasury' => $treasury] = $this->treasuryOfAnotherBranch();

        $receivingId = $this->purchaseOf('300.00');

        $this->assertTrue($this->payPurchaseFrom($receivingId, '100.00', $treasury));

        $paymentId = (int) $this->lastPaymentOf($receivingId)->payment_id;
        $before    = $this->countRows('accounting_journals');

        $this->assertTrue($this->changePayment($receivingId, $paymentId, ['treasury_id' => $treasury]));
        $this->assertTrue($this->changePayment($receivingId, $paymentId, ['reference_code' => 'a note']));

        $this->assertSame($before, $this->countRows('accounting_journals'));
    }

    public function testUpdate_MovesAPaymentFromATreasuryOfAnotherBranchToOneOfItsOwnAndBack(): void
    {
        ['branch' => $branch, 'treasury' => $treasury] = $this->treasuryOfAnotherBranch();

        $own         = (new TreasuryService())->create(['name' => 'Own drawer', 'kind' => 'cash']);
        $receivingId = $this->purchaseOf('300.00');

        $this->assertTrue($this->payPurchaseFrom($receivingId, '100.00', $treasury));

        $paymentId = (int) $this->lastPaymentOf($receivingId)->payment_id;

        $this->assertTrue($this->changePayment($receivingId, $paymentId, ['treasury_id' => $own]));
        $this->assertSame(['supplier_payment'], array_keys($this->supplierPaymentJournals($paymentId)));
        $this->assertNull($this->lastPaymentOf($receivingId)->treasury_branch_id);
        $this->assertSame(['0.0000', '-100.0000'], [(new TreasuryService())->position($treasury)->balance, (new TreasuryService())->position($own)->balance]);

        $this->assertTrue($this->changePayment($receivingId, $paymentId, ['treasury_id' => $treasury]));
        $this->assertSame(['supplier_payment', 'supplier_payment_branch'], array_keys($this->supplierPaymentJournals($paymentId)));
        $this->assertSame($branch, (int) $this->lastPaymentOf($receivingId)->treasury_branch_id);
        $this->assertSame(['-100.0000', '0.0000'], [(new TreasuryService())->position($treasury)->balance, (new TreasuryService())->position($own)->balance]);
        $this->assertEveryBranchBalancedAndTheAccountsBetweenBranchesOffset();
    }

    public function testUpdate_TakesBothJournalsBackWhenThePaymentIsRemoved(): void
    {
        ['treasury' => $treasury] = $this->treasuryOfAnotherBranch();

        $receivingId = $this->purchaseOf('300.00');

        $this->assertTrue($this->payPurchaseFrom($receivingId, '100.00', $treasury));

        $paymentId = (int) $this->lastPaymentOf($receivingId)->payment_id;

        $this->assertTrue($this->changePayment($receivingId, $paymentId, ['payment_amount' => '0.00']));
        $this->assertSame([], $this->supplierPaymentJournals($paymentId));
        $this->assertSame('0.0000', (new TreasuryService())->position($treasury)->balance);
        $this->assertEveryBranchBalancedAndTheAccountsBetweenBranchesOffset();
    }

    public function testUpdate_RejectsABuyerWhoWorksInOnlyOneOfTheBranchesToChangeAPaymentAcrossThemAndChangesNothing(): void
    {
        ['treasury' => $treasury] = $this->treasuryOfAnotherBranch();

        $receivingId = $this->purchaseOf('300.00');

        $this->assertTrue($this->payPurchaseFrom($receivingId, '100.00', $treasury));

        $paymentId = (int) $this->lastPaymentOf($receivingId)->payment_id;

        $this->workInBranches(1);

        $before = $this->countRows('accounting_journals');

        $this->assertFalse($this->changePayment($receivingId, $paymentId, ['payment_amount' => '60.00', 'treasury_id' => $treasury]));
        $this->assertSame(AccountingError::InterBranchBranchesDenied, $this->purchases->accountingFailure()->error);
        $this->assertSame($before, $this->countRows('accounting_journals'));
        $this->assertSame('100.00', $this->lastPaymentOf($receivingId)->payment_amount);
    }

    public function testDeleteValue_TakesBackBothJournalsOfAPaymentOutOfATreasuryOfAnotherBranch(): void
    {
        ['treasury' => $treasury] = $this->treasuryOfAnotherBranch();

        $receivingId = $this->purchaseOf('300.00');

        $this->assertTrue($this->payPurchaseFrom($receivingId, '100.00', $treasury));

        $paymentId = (int) $this->lastPaymentOf($receivingId)->payment_id;

        $this->assertTrue($this->purchases->delete_value($receivingId, $this->employeeId));
        $this->assertSame([], $this->supplierPaymentJournals($paymentId));
        $this->assertSame(['supplier_payment', 'supplier_payment_branch'], array_keys($this->supplierPaymentJournals($paymentId, 'reversed')));
        $this->assertSame('0.0000', (new TreasuryService())->position($treasury)->balance);
        $this->assertEveryBranchBalancedAndTheAccountsBetweenBranchesOffset();
    }

    public function testDeleteValue_RejectsABuyerWhoMayNotDepositIntoTheTreasuryOfTheOtherBranchAndTakesBackNothing(): void
    {
        ['treasury' => $treasury] = $this->treasuryOfAnotherBranch();

        $receivingId = $this->purchaseOf('300.00');

        $this->assertTrue($this->payPurchaseFrom($receivingId, '100.00', $treasury));

        $paymentId = (int) $this->lastPaymentOf($receivingId)->payment_id;

        $this->accountingDb()->table('treasuries')->where('treasury_id', $treasury)->update(['deposit_scope' => 'staff']);
        $this->accountingDb()->table('treasury_scope_members')->insert(['treasury_id' => $treasury, 'direction' => 'deposit', 'member_id' => $this->employeeWithGrants([])]);

        $this->assertFalse($this->purchases->delete_value($receivingId, $this->employeeId));
        $this->assertSame(['supplier_payment', 'supplier_payment_branch'], array_keys($this->supplierPaymentJournals($paymentId)));
        $this->assertSame('-100.0000', (new TreasuryService())->position($treasury)->balance);
    }

    public function testUpdate_KeepsAPaymentStampedWithATreasuryOfAnotherBranchBeforeThisAsTheOneJournalItHas(): void
    {
        ['treasury' => $treasury, 'account' => $account] = $this->treasuryOfAnotherBranch();

        $this->accountingDb()->table('accounting_routing')->where('routing_key', 'payment.cash')->update(['account_id' => $account]);

        $receivingId = $this->purchaseOf('300.00');
        $paymentId   = $this->payPurchase($receivingId, '100.00');

        $this->accountingDb()->table('receivings_payments')->where('payment_id', $paymentId)->update(['treasury_id' => $treasury]);

        $before = $this->countRows('accounting_journals');

        $this->assertSame(['supplier_payment'], array_keys($this->supplierPaymentJournals($paymentId)));
        $this->assertTrue($this->changePayment($receivingId, $paymentId, ['reference_code' => 'only a note']));
        $this->assertSame($before, $this->countRows('accounting_journals'));
        $this->assertNull($this->lastPaymentOf($receivingId)->treasury_branch_id);
        $this->assertSame(0, $this->countRows('accounting_interbranch_legs'));
    }

    public function testJournalsOf_ListsTheJournalOfTheTreasuryBranchWithTheJournalOfThePayment(): void
    {
        ['treasury' => $treasury] = $this->treasuryOfAnotherBranch();

        $receivingId = $this->purchaseOf('300.00');

        $this->assertTrue($this->payPurchaseFrom($receivingId, '100.00', $treasury));
        $this->assertSame(['purchase', 'supplier_payment', 'supplier_payment_branch'], array_column((new ReceivingPosting())->journalsOf($receivingId), 'source_type'));
    }

    // ---------------------------------------------------------------------------------------
    // Correcting the lines of a saved purchase
    // ---------------------------------------------------------------------------------------

    private ?int $editorId = null;

    private function editor(): int
    {
        return $this->editorId ??= $this->employeeWithGrants([]);
    }

    /**
     * @param array<string, mixed> $fields
     */
    private function correct(int $receivingId, int $itemId, array $fields, int $line = 0, ?int $employeeId = null): bool
    {
        $edit = ReceivingLineEdit::changing($itemId, $line, $fields);

        $this->assertNotNull($edit, 'The correction was not a line that can be saved');

        return $this->purchases->updateLines($receivingId, [$edit], $employeeId ?? $this->editor());
    }

    /**
     * What the stock movements of a purchase hold, in the order they were written, as
     * "kind:revision:quantity:value".
     *
     * @return list<string>
     */
    private function movementsOf(int $receivingId): array
    {
        return array_map(
            static fn (object $entry): string => $entry->kind . ':' . $entry->revision . ':' . $entry->quantity . ':' . $entry->value,
            $this->accountingDb()->table('accounting_stock_entries')->where(['source_type' => 'purchase', 'source_id' => $receivingId])->orderBy('entry_id', 'ASC')->get()->getResult(),
        );
    }

    /**
     * Everything a refused correction must leave as it was.
     *
     * @return array<string, mixed>
     */
    private function bookState(int $receivingId, int $itemId): array
    {
        return [
            'lines'    => $this->accountingDb()->table('receivings_items')->where('receiving_id', $receivingId)->orderBy('line', 'ASC')->get()->getResultArray(),
            'journals' => $this->purchaseJournals($receivingId),
            'entries'  => $this->movementsOf($receivingId),
            'journalsN' => $this->countRows('accounting_journals'),
            'onHand'   => $this->onHand($itemId),
            'cost'     => $this->exactCostOf($itemId),
            'inventory' => $this->countRows('inventory'),
        ];
    }

    public function testUpdateLines_ReversesTheOldJournalAndTheMovementsAndPostsRevisionTwoWhenTenUnitsBecomeEight(): void
    {
        $itemId      = $this->item('0.00');
        $receivingId = $this->buy([$this->purchaseLine($itemId, 0, '10', '100')]);
        $first       = $this->standingPurchase($receivingId)[0];

        $this->assertSame(1, $first['revision']);
        $this->assertTrue($this->correct($receivingId, $itemId, ['quantity' => '8']));

        $journals = $this->purchaseJournals($receivingId);

        $this->assertSame([1, 2], array_column($journals, 'revision'));
        $this->assertSame(['reversed', 'posted'], array_column($journals, 'status'));
        $this->assertSame($this->expectedLines([['inventory', '1000.00', '0.00'], ['supplier', '0.00', '1000.00']]), $journals[0]['lines']);
        $this->assertSame($this->expectedLines([['inventory', '800.00', '0.00'], ['supplier', '0.00', '800.00']]), $journals[1]['lines']);

        $reversal = $this->accountingDb()->table('accounting_journals')->where('source_type', 'reversal')->where('reverses_journal_id', $first['id'])->get()->getRow();

        $this->assertNotNull($reversal, 'The first revision is taken back by a reversal, never edited');
        $this->assertSame(['posting:1:10.000:1000.0000', 'reversal:1:-10.000:-1000.0000', 'posting:2:8.000:800.0000'], $this->movementsOf($receivingId));
        $this->assertSame(['quantity' => '8.000', 'value' => '800.00'], $this->stockOf($itemId));
        $this->assertSame('8.000', $this->onHand($itemId));
        $this->assertSame('8.000', $this->storedLine($receivingId, $itemId)->quantity_purchased);
        $this->assertSame('800.00', $this->owed());
        $this->assertSame(1, $this->accountingDb()->table('accounting_audit_log')->where(['entity_type' => 'purchase', 'entity_id' => $receivingId, 'action' => 'lines_edited'])->countAllResults());
        $this->assertStockTies();
        $this->assertSupplierTies();
    }

    public function testUpdateLines_WritesTheCorrectionToTheInventoryRecordAsTheChangeOfTheShelves(): void
    {
        $itemId      = $this->item('0.00');
        $receivingId = $this->buy([$this->purchaseLine($itemId, 0, '10', '100')]);

        $this->assertTrue($this->correct($receivingId, $itemId, ['quantity' => '8']));

        $row = $this->accountingDb()->table('inventory')->where(['trans_items' => $itemId, 'trans_comment' => 'Editing receiving ' . $receivingId])->get()->getRow();

        $this->assertNotNull($row);
        $this->assertSame('-2.000', bcadd((string) $row->trans_inventory, '0', 3));
        $this->assertSame($this->editor(), (int) $row->trans_user);
    }

    public function testUpdateLines_ChangesThePriceAndTheTaxOfALineAndKeepsTheSupplierTied(): void
    {
        $itemId      = $this->item('0.00');
        $receivingId = $this->buy([$this->purchaseLine($itemId, 0, '10', '100', '15')]);

        $this->assertTrue($this->correct($receivingId, $itemId, ['price' => '120', 'tax_percent' => '5']));

        $this->assertSame($this->expectedLines([['inventory', '1200.00', '0.00'], ['tax_input', '60.00', '0.00'], ['supplier', '0.00', '1260.00']]), $this->standingPurchase($receivingId)[0]['lines']);
        $this->assertSame('1260.00', $this->owed());
        $this->assertSame('120.00', $this->storedLine($receivingId, $itemId)->item_unit_price);
        $this->assertSame('5.000', $this->storedLine($receivingId, $itemId)->tax_percent);
        $this->assertStockTies();
        $this->assertSupplierTies();
    }

    public function testUpdateLines_ChangesTheDiscountOfALineAndItsDescription(): void
    {
        $itemId      = $this->item('0.00');
        $receivingId = $this->buy([$this->purchaseLine($itemId, 0, '10', '100')]);

        $this->assertTrue($this->correct($receivingId, $itemId, ['discount' => '10', 'discount_type' => PERCENT, 'description' => 'Damaged boxes', 'serialnumber' => 'SN-1']));

        $this->assertSame('900.00', $this->owed());
        $this->assertSame('Damaged boxes', $this->storedLine($receivingId, $itemId)->description);
        $this->assertSame('SN-1', $this->storedLine($receivingId, $itemId)->serialnumber);
        $this->assertStockTies();
    }

    public function testUpdateLines_TakesALineOutWithItsStockAndItsTax(): void
    {
        $first       = $this->item('0.00');
        $second      = $this->item('0.00');
        $receivingId = $this->buy([$this->purchaseLine($first, 0, '10', '100', '15'), $this->purchaseLine($second, 1, '5', '40', '15')]);

        $this->assertTrue($this->purchases->updateLines($receivingId, [ReceivingLineEdit::removing($second, 1)], $this->editor()));

        $this->assertSame($this->expectedLines([['inventory', '1000.00', '0.00'], ['tax_input', '150.00', '0.00'], ['supplier', '0.00', '1150.00']]), $this->standingPurchase($receivingId)[0]['lines']);
        $this->assertNull($this->storedLine($receivingId, $second, 1));
        $this->assertSame('0.000', $this->onHand($second));
        $this->assertSame(['quantity' => '0.000', 'value' => '0.00'], $this->stockOf($second));
        $this->assertSame('10.000', $this->onHand($first));
        $this->assertSame('1150.00', $this->owed());
        $this->assertStockTies();
        $this->assertSupplierTies();
    }

    public function testUpdateLines_RefusesToTakeOutEveryLine(): void
    {
        $itemId      = $this->item('0.00');
        $receivingId = $this->buy([$this->purchaseLine($itemId, 0, '10', '100')]);
        $before      = $this->bookState($receivingId, $itemId);

        $this->assertFalse($this->purchases->updateLines($receivingId, [ReceivingLineEdit::removing($itemId, 0)], $this->editor()));
        $this->assertInstanceOf(AccountingException::class, $this->failure());
        $this->assertSame(AccountingError::PurchaseEditEmpty, $this->failure()->error);
        $this->assertSame($before, $this->bookState($receivingId, $itemId));
    }

    public function testUpdateLines_RefusesALineThatIsNotOnTheReceivingAndALineGivenTwice(): void
    {
        $itemId      = $this->item('0.00');
        $other       = $this->item('0.00');
        $receivingId = $this->buy([$this->purchaseLine($itemId, 0, '10', '100')]);
        $before      = $this->bookState($receivingId, $itemId);

        $this->assertFalse($this->purchases->updateLines($receivingId, [ReceivingLineEdit::changing($other, 0, ['quantity' => '3'])], $this->editor()));
        $this->assertSame(AccountingError::PurchaseEditLineInvalid, $this->failure()->error);

        $twice = [ReceivingLineEdit::changing($itemId, 0, ['quantity' => '3']), ReceivingLineEdit::changing($itemId, 0, ['quantity' => '4'])];

        $this->assertFalse($this->purchases->updateLines($receivingId, $twice, $this->editor()));
        $this->assertSame(AccountingError::PurchaseEditLineInvalid, $this->failure()->error);
        $this->assertSame($before, $this->bookState($receivingId, $itemId));
    }

    public function testUpdateLines_RefusesAPercentageOverAHundredOnALineKeptWithAPercentageType(): void
    {
        $itemId      = $this->item('0.00');
        $receivingId = $this->buy([$this->purchaseLine($itemId, 0, '10', '100')]);
        $before      = $this->bookState($receivingId, $itemId);

        $this->assertFalse($this->correct($receivingId, $itemId, ['discount' => '150']));
        $this->assertSame(AccountingError::PurchaseEditLineInvalid, $this->failure()->error);
        $this->assertSame($before, $this->bookState($receivingId, $itemId));
        $this->assertTrue($this->correct($receivingId, $itemId, ['discount' => '150', 'discount_type' => FIXED_LINE]));
    }

    public function testUpdateLines_RefusesAReceivingThatHasReturnsAndChangesNothing(): void
    {
        ['item' => $itemId, 'purchase' => $purchase] = $this->stockOfTwenty();

        $this->assertGreaterThan(0, $this->buy([$this->purchaseLine($itemId, 0, '-2', '70')], -1, null, $purchase));

        $before = $this->bookState($purchase, $itemId);

        $this->assertFalse($this->correct($purchase, $itemId, ['quantity' => '15']));
        $this->assertSame(AccountingError::PurchaseEditHasReturns, $this->failure()->error);
        $this->assertSame($before, $this->bookState($purchase, $itemId));
    }

    public function testUpdateLines_RefusesAReturnAndAReceivingThatDoesNotExist(): void
    {
        ['item' => $itemId, 'purchase' => $purchase] = $this->stockOfTwenty();

        $return = $this->buy([$this->purchaseLine($itemId, 0, '-2', '70')], -1, null, $purchase);

        $this->assertFalse($this->correct($return, $itemId, ['quantity' => '1']));
        $this->assertSame(AccountingError::PurchaseEditNotAReceipt, $this->failure()->error);
        $this->assertFalse($this->correct(999999, $itemId, ['quantity' => '1']));
        $this->assertSame(AccountingError::PurchaseEditNotAReceipt, $this->failure()->error);
    }

    public function testUpdateLines_RefusesAClosedPeriodAndLeavesNothingChanged(): void
    {
        $itemId      = $this->item('0.00');
        $receivingId = $this->buy([$this->purchaseLine($itemId, 0, '10', '100')]);
        $before      = $this->bookState($receivingId, $itemId);

        $this->closeThrough('2035-12-31');

        $this->assertFalse($this->correct($receivingId, $itemId, ['quantity' => '8']));
        $this->assertInstanceOf(PostingException::class, $this->failure());
        $this->assertSame(PostingError::PeriodClosed, $this->failure()->error);
        $this->assertSame($before, $this->bookState($receivingId, $itemId));
        $this->assertSame(1, $this->accountingDb()->table('accounting_posting_failures')->where('source_type', 'purchase')->where('source_id', $receivingId)->where('error_code', 'period_closed')->countAllResults(), 'The refusal is written down after the rollback');
        $this->assertStockTies();
    }

    public function testUpdateLines_RefusesWhenTheGoodsWereSoldAndAcceptsALeavingOfExactlyNothing(): void
    {
        $itemId      = $this->item('0.00');
        $receivingId = $this->buy([$this->purchaseLine($itemId, 0, '10', '100')]);

        $this->assertGreaterThan(0, $this->save([$this->line($itemId, 0, '7', '150', $this->exactCostOf($itemId))]));
        $this->assertSame('3.000', $this->onHand($itemId));

        $before = $this->bookState($receivingId, $itemId);

        $this->assertFalse($this->correct($receivingId, $itemId, ['quantity' => '5']));
        $this->assertSame(AccountingError::PurchaseStockWouldGoNegative, $this->failure()->error);
        $this->assertStringContainsString('Test Item', AccountingMessage::of($this->failure()));
        $this->assertSame($before, $this->bookState($receivingId, $itemId));

        $this->assertTrue($this->correct($receivingId, $itemId, ['quantity' => '7']));
        $this->assertSame('0.000', $this->onHand($itemId));
        $this->assertStockTies();
    }

    public function testUpdateLines_LeavesTheCostOfGoodsSoldOfEarlierSalesAsItWasPosted(): void
    {
        $itemId = $this->item('0.00');
        $first  = $this->buy([$this->purchaseLine($itemId, 0, '10', '100')]);
        $sale   = $this->save([$this->line($itemId, 0, '4', '150', $this->exactCostOf($itemId))]);
        $second = $this->buy([$this->purchaseLine($itemId, 0, '10', '120')]);

        $this->assertGreaterThan(0, $first);
        $this->assertSame('112.500000', $this->exactCostOf($itemId));

        $costed = $this->journals($sale);

        $this->assertTrue($this->correct($second, $itemId, ['quantity' => '8']));
        $this->assertSame($costed, $this->journals($sale), 'The cost of the sale was posted at the average of its time and is not touched');
        $this->assertSame('111.428571', $this->exactCostOf($itemId));
        $this->assertStockTies();
    }

    public function testUpdateLines_KeepsThePaymentsOfTheReceivingAndTheSupplierTied(): void
    {
        $itemId      = $this->item('0.00');
        $receivingId = $this->buy([$this->purchaseLine($itemId, 0, '10', '100')]);
        $paymentId   = $this->payPurchase($receivingId, '300.00');
        $payment     = $this->supplierPaymentJournals($paymentId);

        $this->assertTrue($this->correct($receivingId, $itemId, ['quantity' => '8']));
        $this->assertSame($payment, $this->supplierPaymentJournals($paymentId), 'The payment is kept as it was posted');
        $this->assertSame('500.0000', $this->supplierPosition()->purchase($receivingId)->open());
        $this->assertSame('500.00', $this->owed());
        $this->assertSupplierTies();
        $this->assertStockTies();
    }

    public function testUpdateLines_LeavesThePaymentMadeAtOnceAndTurnsWhatIsPaidBeyondTheNewTotalIntoCredit(): void
    {
        $itemId      = $this->item('0.00');
        $receivingId = $this->buy([$this->purchaseLine($itemId, 0, '10', '100')], -1, lang('Sales.cash'));
        $paid        = $this->standingPurchase($receivingId, 'purchase_payment');

        $this->assertCount(1, $paid);
        $this->assertTrue($this->correct($receivingId, $itemId, ['quantity' => '8']));
        $this->assertSame($paid, $this->standingPurchase($receivingId, 'purchase_payment'));
        $this->assertSame('200.0000', $this->supplierPosition()->purchase($receivingId)->excess());
        $this->assertSame('-200.00', $this->owed());
        $this->assertSupplierTies();
        $this->assertStockTies();
    }

    public function testUpdateLines_SettlesTheCreditOfTheSupplierAgainOnAnotherOpenPurchase(): void
    {
        $itemId = $this->item('0.00');
        $first  = $this->buy([$this->purchaseLine($itemId, 0, '10', '100')], -1, lang('Sales.cash'));
        $second = $this->buy([$this->purchaseLine($itemId, 0, '5', '100')]);

        $this->assertTrue($this->correct($first, $itemId, ['quantity' => '8']));
        $this->assertContains('invoice_excess:' . $first . '->' . $second . ':200.00', $this->supplierApplications());
        $this->assertSame('300.00', bcadd($this->supplierPosition()->purchase($second)->open(), '0', 2));
        $this->assertSupplierTies();
    }

    public function testUpdateLines_SharesTheDiscountOnTheWholePurchaseOverTheLinesAsTheyNowAre(): void
    {
        $first       = $this->item('0.00');
        $second      = $this->item('0.00');
        $receivingId = $this->buy([$this->purchaseLine($first, 0, '10', '100'), $this->purchaseLine($second, 1, '10', '50')], -1, null, null, InvoiceDiscount::of(PERCENT, '10'));

        $this->assertSame(['100.0000', '50.0000'], [$this->storedLine($receivingId, $first)->invoice_discount_share, $this->storedLine($receivingId, $second, 1)->invoice_discount_share]);
        $this->assertTrue($this->correct($receivingId, $first, ['quantity' => '5']));
        $this->assertSame(['50.0000', '50.0000'], [$this->storedLine($receivingId, $first)->invoice_discount_share, $this->storedLine($receivingId, $second, 1)->invoice_discount_share]);
        $this->assertSame($this->expectedLines([['inventory', '900.00', '0.00'], ['supplier', '0.00', '900.00']]), $this->standingPurchase($receivingId)[0]['lines']);
        $this->assertSame('900.00', $this->owed());
        $this->assertStockTies();
        $this->assertSupplierTies();
    }

    public function testUpdateLines_ClearsTheShareOfALineThatTheDiscountNoLongerReaches(): void
    {
        $first       = $this->item('0.00');
        $second      = $this->item('0.00');
        $receivingId = $this->buy([$this->purchaseLine($first, 0, '10', '100'), $this->purchaseLine($second, 1, '10', '50')], -1, null, null, InvoiceDiscount::of(FIXED, '1000'));

        $this->assertSame(['666.67', '333.33'], [bcadd((string) $this->storedLine($receivingId, $first)->invoice_discount_share, '0', 2), bcadd((string) $this->storedLine($receivingId, $second, 1)->invoice_discount_share, '0', 2)]);
        $this->assertTrue($this->purchases->updateLines($receivingId, [ReceivingLineEdit::changing($second, 1, ['price' => '0'])], $this->editor()));
        $this->assertSame(['1000.0000', '0.0000'], [$this->storedLine($receivingId, $first)->invoice_discount_share, bcadd((string) $this->storedLine($receivingId, $second, 1)->invoice_discount_share, '0', 4)]);
        $this->assertSame('0.00', $this->owed());
        $this->assertStockTies();
    }

    public function testUpdateLines_RefusesADiscountOfAnAmountThatTheLinesNoLongerComeTo(): void
    {
        $itemId      = $this->item('0.00');
        $receivingId = $this->buy([$this->purchaseLine($itemId, 0, '10', '100')], -1, null, null, InvoiceDiscount::of(FIXED, '600'));
        $before      = $this->bookState($receivingId, $itemId);

        $this->assertFalse($this->correct($receivingId, $itemId, ['quantity' => '5']));
        $this->assertInstanceOf(PostingException::class, $this->failure());
        $this->assertSame(PostingError::PurchaseDiscountInvalid, $this->failure()->error);
        $this->assertSame($before, $this->bookState($receivingId, $itemId));
    }

    public function testUpdateLines_RefusesABranchTheEmployeeDoesNotWorkInAndChangesNothing(): void
    {
        $branch      = $this->createBranch('B2', 'Second branch');
        $other       = $this->locationOfBranch($branch, 'Branch warehouse');
        $itemId      = $this->item('0.00');
        $receivingId = $this->buy([$this->purchaseLine($itemId, 0, '10', '100', '0', '0', PERCENT, $other)]);
        $before      = $this->bookState($receivingId, $itemId);

        $this->assertFalse($this->correct($receivingId, $itemId, ['quantity' => '8'], 0, $this->employeeWithGrants([], [1])));
        $this->assertInstanceOf(BranchException::class, $this->purchases->accountingFailure());
        $this->assertSame(lang('Branches.error_not_allowed'), AccountingMessage::of($this->purchases->accountingFailure()));
        $this->assertSame($before, $this->bookState($receivingId, $itemId));
        $this->assertTrue($this->correct($receivingId, $itemId, ['quantity' => '8'], 0, $this->employeeWithGrants([], [$branch])));
        $this->assertSame('8.000', $this->onHand($itemId, $other));
        $this->assertStockTies();
    }

    public function testUpdateLines_RunAgainWithTheSameValuesWritesNothingMore(): void
    {
        $itemId      = $this->item('0.00');
        $receivingId = $this->buy([$this->purchaseLine($itemId, 0, '10', '100')]);

        $this->assertTrue($this->correct($receivingId, $itemId, ['quantity' => '8']));

        $before = $this->bookState($receivingId, $itemId);

        $this->assertTrue($this->correct($receivingId, $itemId, ['quantity' => '8']));
        $this->assertTrue($this->correct($receivingId, $itemId, ['quantity' => '8.000', 'price' => '100', 'discount' => '0']));
        $this->assertSame($before, $this->bookState($receivingId, $itemId));
    }

    public function testUpdateLines_PostsRevisionThreeOnASecondCorrectionAndDeletingTakesBackOnlyTheStandingRevision(): void
    {
        $itemId      = $this->item('0.00');
        $receivingId = $this->buy([$this->purchaseLine($itemId, 0, '10', '100')]);

        $this->assertTrue($this->correct($receivingId, $itemId, ['quantity' => '8']));
        $this->assertTrue($this->correct($receivingId, $itemId, ['quantity' => '6']));
        $this->assertSame([1, 2, 3], array_column($this->purchaseJournals($receivingId), 'revision'));
        $this->assertSame(['reversed', 'reversed', 'posted'], array_column($this->purchaseJournals($receivingId), 'status'));
        $this->assertTrue($this->purchases->delete_value($receivingId, $this->employeeId));
        $this->assertSame([], $this->standingPurchase($receivingId));
        $this->assertSame(['quantity' => '0.000', 'value' => '0.00'], $this->stockOf($itemId));
        $this->assertSame('0.000', $this->onHand($itemId));
        $this->assertSame('0.00', $this->balance($this->stockAccount()));
        $this->assertStockTies();
    }

    public function testUpdateLines_PostsAZeroValuedReceivingAsARevisionOfMovementsOnly(): void
    {
        $itemId      = $this->item('0.00');
        $receivingId = $this->buy([$this->purchaseLine($itemId, 0, '10', '0')]);

        $this->assertSame([], $this->standingPurchase($receivingId));
        $this->assertTrue($this->correct($receivingId, $itemId, ['price' => '5']));
        $this->assertSame(['posting:1:10.000:0.0000', 'reversal:1:-10.000:0.0000', 'posting:2:10.000:50.0000'], $this->movementsOf($receivingId));
        $this->assertSame('50.00', $this->owed());
        $this->assertStockTies();
    }

    public function testUpdateLines_ChangesTheCashPaidOfAPurchaseWithoutASupplier(): void
    {
        $itemId      = $this->item('0.00');
        $receivingId = $this->buy([$this->purchaseLine($itemId, 0, '4', '50')], null, lang('Sales.cash'));

        $this->assertGreaterThan(0, $receivingId);
        $this->assertSame('-200.00', $this->balance($this->accountId('cash')));
        $this->assertTrue($this->correct($receivingId, $itemId, ['quantity' => '2']));
        $this->assertSame('-100.00', $this->balance($this->accountId('cash')));
        $this->assertSame(['reversed', 'posted'], array_column($this->purchaseJournals($receivingId), 'status'));
        $this->assertStockTies();
    }

    public function testUpdateLines_RefusesToPayLessOutOfATreasuryTheEditorMayNotDepositInto(): void
    {
        $other      = $this->employeeWithGrants([]);
        $treasuries = new TreasuryService();

        $this->accountingDb()->table('grants')->insert(['person_id' => $this->employeeId, 'permission_id' => BranchGuard::ALL_BRANCHES, 'menu_group' => 'home']);

        $drawer = $treasuries->create(['name' => 'Scoped drawer', 'kind' => 'cash', 'withdraw_scope' => 'staff', 'withdraw_members' => [$this->employeeId], 'deposit_scope' => 'staff', 'deposit_members' => [$other]]);

        $treasuries->setDefaultFor($this->employeeId, $drawer);

        $itemId      = $this->item('0.00');
        $receivingId = $this->buy([$this->purchaseLine($itemId, 0, '4', '50')], null, lang('Sales.cash'));

        $this->assertGreaterThan(0, $receivingId);
        $this->assertSame('-200.0000', $treasuries->position($drawer)->balance);

        $before = $this->bookState($receivingId, $itemId);

        $this->assertFalse($this->correct($receivingId, $itemId, ['quantity' => '2']));
        $this->assertSame(AccountingError::TreasuryDepositDenied, $this->purchases->accountingFailure()->error);
        $this->assertSame($before, $this->bookState($receivingId, $itemId));
        $this->assertSame('-200.0000', $treasuries->position($drawer)->balance);
        $this->assertTrue($this->correct($receivingId, $itemId, ['quantity' => '2'], 0, $other));
        $this->assertSame('-100.0000', $treasuries->position($drawer)->balance);
    }

    public function testUpdateLines_ChangesTheStockWithoutTheBooksForAReceivingDatedBeforeTheyStart(): void
    {
        $itemId = $this->item('0.00');

        $this->setConfigValue('accounting_start_date', '2035-01-01');

        $receivingId = $this->buy([$this->purchaseLine($itemId, 0, '10', '50')]);

        $this->assertTrue($this->correct($receivingId, $itemId, ['quantity' => '6']));
        $this->assertSame('6.000', $this->onHand($itemId));
        $this->assertSame(0, $this->countRows('accounting_journals'));
        $this->assertSame(0, $this->countRows('accounting_stock_entries'));
        $this->assertSame(0, $this->accountingDb()->table('accounting_audit_log')->where('action', 'lines_edited')->countAllResults());
        $this->assertSame('50.0000', $this->itemCostOf($itemId));
    }

    public function testUpdateLines_ChangesTheStockAndTheAverageWhileTheBooksAreOffWhenTheAverageIsOn(): void
    {
        $itemId = $this->item('0.00');

        $this->setConfigValue('accounting_enabled', '0');
        $this->setConfigValue('receiving_calculate_average_price', '1');
        config(OSPOS::class)->settings['receiving_calculate_average_price'] = '1';

        $first  = $this->buy([$this->purchaseLine($itemId, 0, '10', '100')]);
        $second = $this->buy([$this->purchaseLine($itemId, 0, '10', '120')]);

        $this->assertGreaterThan(0, $first);
        $this->assertSame('110.0000', $this->itemCostOf($itemId));
        $this->assertTrue($this->correct($second, $itemId, ['quantity' => '8']));
        $this->assertSame('18.000', $this->onHand($itemId));
        $this->assertSame('108.888889', $this->exactCostOf($itemId));
        $this->assertSame(0, $this->countRows('accounting_journals'));
        $this->assertSame(0, $this->countRows('accounting_stock_entries'));
        $this->assertSame(0, $this->accountingDb()->table('accounting_audit_log')->where('action', 'lines_edited')->countAllResults());

        config(OSPOS::class)->settings['receiving_calculate_average_price'] = '';
    }

    public function testUpdateLines_LeavesTheCostAloneWhileTheBooksAreOffAndTheAverageIsOff(): void
    {
        $itemId = $this->item('100.00');

        $this->setConfigValue('accounting_enabled', '0');
        config(OSPOS::class)->settings['receiving_calculate_average_price'] = '';

        $receivingId = $this->buy([$this->purchaseLine($itemId, 0, '10', '100')]);

        $this->assertTrue($this->correct($receivingId, $itemId, ['quantity' => '8', 'price' => '90']));
        $this->assertSame('8.000', $this->onHand($itemId));
        $this->assertSame('100.0000', $this->itemCostOf($itemId), 'An average of the 8 units at 90 would have been 90');
        $this->assertSame('90.00', $this->storedLine($receivingId, $itemId)->item_unit_price);
    }

    public function testUpdateLines_AveragesTheItemOfALineWhoseShareOfTheDiscountOnTheWholePurchaseMovedWithoutTheLineBeingTouched(): void
    {
        $first       = $this->item('0.00');
        $second      = $this->item('0.00');
        $receivingId = $this->buy([$this->purchaseLine($first, 0, '10', '100'), $this->purchaseLine($second, 1, '10', '50')], -1, null, null, InvoiceDiscount::of(FIXED, '600'));

        $this->assertSame(['60.000000', '30.000000'], [$this->exactCostOf($first), $this->exactCostOf($second)]);
        $this->assertTrue($this->correct($receivingId, $first, ['quantity' => '5']));
        $this->assertSame(['300.0000', '300.0000'], [bcadd((string) $this->storedLine($receivingId, $first)->invoice_discount_share, '0', 4), bcadd((string) $this->storedLine($receivingId, $second, 1)->invoice_discount_share, '0', 4)]);
        $this->assertSame('40.000000', $this->exactCostOf($first));
        $this->assertSame('20.000000', $this->exactCostOf($second), 'The ten units of the second item are worth 200 after the discount moved, which is 20 each');
        $this->assertSame('200.00', $this->stockOf($second)['value']);
        $this->assertStockTies();
    }

    public function testUpdateLines_AveragesTheItemOfALineThatTakesTheWholeDiscountOnceTheOtherLineIsTakenOut(): void
    {
        $first       = $this->item('0.00');
        $second      = $this->item('0.00');
        $receivingId = $this->buy([$this->purchaseLine($first, 0, '10', '100'), $this->purchaseLine($second, 1, '10', '50')], -1, null, null, InvoiceDiscount::of(FIXED, '300'));

        $this->assertSame(['80.000000', '40.000000'], [$this->exactCostOf($first), $this->exactCostOf($second)]);
        $this->assertTrue($this->purchases->updateLines($receivingId, [ReceivingLineEdit::removing($first, 0)], $this->editor()));
        $this->assertSame('200.00', $this->stockOf($second)['value']);
        $this->assertSame('20.000000', $this->exactCostOf($second));
        $this->assertSame('80.000000', $this->exactCostOf($first), 'Nothing is left of the first item to weigh');
        $this->assertStockTies();
    }

    /**
     * @return array<string, array{string}>
     */
    public static function receivingTheAverageNeverHeldProvider(): array
    {
        return [
            'dated before the books start'                     => ['2035-01-01'],
            'dated after the start but saved before it was on' => ['2020-01-01'],
        ];
    }

    #[DataProvider('receivingTheAverageNeverHeldProvider')]
    public function testUpdateLines_LeavesTheCostAloneForAReceivingTheAverageNeverHeldWhileTheBooksAreOn(string $startDate): void
    {
        $itemId = $this->item('50.00');

        $this->setConfigValue('accounting_enabled', '0');
        config(OSPOS::class)->settings['receiving_calculate_average_price'] = '';

        $receivingId = $this->buy([$this->purchaseLine($itemId, 0, '10', '100')]);

        $this->assertSame('50.0000', $this->itemCostOf($itemId), 'Saved before the books and without the average, the receiving never reached the cost');

        $this->setConfigValue('accounting_enabled', '1');
        $this->setConfigValue('accounting_start_date', $startDate);

        $this->assertTrue($this->correct($receivingId, $itemId, ['quantity' => '8']));
        $this->assertSame('8.000', $this->onHand($itemId));
        $this->assertSame('50.0000', $this->itemCostOf($itemId), 'Eight units at 100 taken out of a stock that never held them would have made it 37.5');
    }

    public function testUpdateLines_LeavesTheCostAloneForAPostedReceivingOnceTheBooksAreTurnedOffAndTheAverageIsOff(): void
    {
        $itemId = $this->item('0.00');

        config(OSPOS::class)->settings['receiving_calculate_average_price'] = '';

        $receivingId = $this->buy([$this->purchaseLine($itemId, 0, '10', '100')]);

        $this->assertSame('100.0000', $this->itemCostOf($itemId));

        $this->setConfigValue('accounting_enabled', '0');

        $this->assertTrue($this->correct($receivingId, $itemId, ['price' => '90']));
        $this->assertSame('100.0000', $this->itemCostOf($itemId), 'While the books are off and the average is off the cost is the one the person keeps');
        $this->setConfigValue('accounting_enabled', '1');
    }

    public function testUpdateLines_AveragesAReceivingTheBooksDoNotFollowWhenTheAverageSettingIsOn(): void
    {
        $itemId = $this->item('50.00');

        $this->setConfigValue('accounting_enabled', '0');
        $this->setConfigValue('receiving_calculate_average_price', '1');
        config(OSPOS::class)->settings['receiving_calculate_average_price'] = '1';

        try {
            $receivingId = $this->buy([$this->purchaseLine($itemId, 0, '10', '100')]);

            $this->assertSame('100.0000', $this->itemCostOf($itemId));

            $this->setConfigValue('accounting_enabled', '1');
            $this->setConfigValue('accounting_start_date', '2035-01-01');

            $this->assertTrue($this->correct($receivingId, $itemId, ['price' => '90']));
            $this->assertSame('90.0000', $this->itemCostOf($itemId));
        } finally {
            config(OSPOS::class)->settings['receiving_calculate_average_price'] = '';
        }
    }

    #[DataProvider('receivingTheAverageNeverHeldProvider')]
    public function testDeleteValue_LeavesTheCostAloneForAReceivingTheAverageNeverHeldWhileTheBooksAreOn(string $startDate): void
    {
        $itemId = $this->item('50.00');

        $this->setConfigValue('accounting_enabled', '0');
        config(OSPOS::class)->settings['receiving_calculate_average_price'] = '';

        $receivingId = $this->buy([$this->purchaseLine($itemId, 0, '10', '100')]);

        $this->accountingDb()->table('item_quantities')->where(['item_id' => $itemId, 'location_id' => self::LOCATION_ID])->update(['quantity' => '20']);
        $this->setConfigValue('accounting_enabled', '1');
        $this->setConfigValue('accounting_start_date', $startDate);

        $this->assertTrue($this->purchases->delete_value($receivingId, $this->editor()));
        $this->assertSame('10.000', $this->onHand($itemId));
        $this->assertSame('50.0000', $this->itemCostOf($itemId), 'Taking 10 units at 100 out of 20 at 50 would have made the cost nothing');
    }

    public function testUpdateLines_GoesBackToTheAverageOfTheStockThatIsLeftWhenALineIsTakenOut(): void
    {
        $bought = $this->item('0.00');
        $other  = $this->item('0.00');

        $this->assertGreaterThan(0, $this->buy([$this->purchaseLine($bought, 0, '10', '100')]));

        $second = $this->buy([$this->purchaseLine($bought, 0, '10', '120'), $this->purchaseLine($other, 1, '5', '40')]);

        $this->assertSame('110.000000', $this->exactCostOf($bought));
        $this->assertTrue($this->purchases->updateLines($second, [ReceivingLineEdit::removing($bought, 0)], $this->editor()));
        $this->assertSame('10.000', $this->onHand($bought));
        $this->assertSame('100.000000', $this->exactCostOf($bought));
        $this->assertSame('40.000000', $this->exactCostOf($other));
        $this->assertStockTies();
    }

    public function testUpdateLines_AveragesTheItemWhenTheUnitsOfALineThatCostsNothingChange(): void
    {
        $itemId = $this->item('0.00');

        $this->assertGreaterThan(0, $this->buy([$this->purchaseLine($itemId, 0, '10', '100')]));

        $free = $this->buy([$this->purchaseLine($itemId, 0, '10', '0')]);

        $this->assertSame('50.000000', $this->exactCostOf($itemId));
        $this->assertTrue($this->correct($free, $itemId, ['quantity' => '8']));
        $this->assertSame('18.000', $this->onHand($itemId));
        $this->assertSame('55.555556', $this->exactCostOf($itemId), 'Ten units worth 1,000 over eighteen units');
        $this->assertStockTies();
    }

    public function testUpdateLines_WritesNothingForALineWhoseTextWasNeverEnteredWhenTheFormSendsItEmpty(): void
    {
        $itemId      = $this->item('0.00');
        $receivingId = $this->buy([$this->purchaseLine($itemId, 0, '10', '100')]);

        $this->accountingDb()->table('receivings_items')->where('receiving_id', $receivingId)->update(['description' => null, 'serialnumber' => null]);

        $before = $this->bookState($receivingId, $itemId);

        $this->assertTrue($this->correct($receivingId, $itemId, ['description' => '', 'serialnumber' => '']));
        $this->assertSame($before, $this->bookState($receivingId, $itemId));
        $this->assertSame(0, $this->accountingDb()->table('accounting_audit_log')->where('action', 'lines_edited')->countAllResults());

        $this->closeThrough(date('Y-m-d'));

        $this->assertTrue($this->correct($receivingId, $itemId, ['description' => '']), 'Nothing changes, so a closed period has nothing to refuse');
    }

    /**
     * @return list<string>
     */
    private function queriesDuring(callable $action): array
    {
        $queries  = [];
        $listener = static function ($query) use (&$queries): void {
            $queries[] = (string) $query->getQuery();
        };

        Events::on('DBQuery', $listener);

        try {
            $action();
        } finally {
            Events::removeListener('DBQuery', $listener);
        }

        return $queries;
    }

    /**
     * @param list<string> $queries
     */
    private function firstQueryLike(array $queries, string $pattern): ?int
    {
        foreach ($queries as $index => $query) {
            if (preg_match($pattern, $query) === 1) {
                return $index;
            }
        }

        return null;
    }

    public function testUpdateLines_LocksTheItemsOfTheLinesBeforeItChangesAnything(): void
    {
        $itemId      = $this->item('0.00');
        $receivingId = $this->buy([$this->purchaseLine($itemId, 0, '10', '100')]);

        $queries = $this->queriesDuring(fn () => $this->assertTrue($this->correct($receivingId, $itemId, ['quantity' => '8'])));
        $lock    = $this->firstQueryLike($queries, '/FROM `?\w*items`? WHERE item_id IN \(' . $itemId . '\) ORDER BY item_id FOR UPDATE/');
        $write   = $this->firstQueryLike($queries, '/^(UPDATE|DELETE FROM|INSERT INTO) `?\w*receivings_items`?/');

        $this->assertNotNull($lock, 'The items of the lines are locked');
        $this->assertNotNull($write);
        $this->assertLessThan($write, $lock, 'The lock is taken before the first line is written');
    }

    public function testUpdateLines_LocksTheShelfBeforeTakingStockOffItAndNotWhenAddingToIt(): void
    {
        $itemId      = $this->item('0.00');
        $receivingId = $this->buy([$this->purchaseLine($itemId, 0, '10', '100')]);
        $shelfLock   = '/FROM `?\w*item_quantities`? WHERE item_id = ' . $itemId . ' AND location_id = ' . self::LOCATION_ID . ' FOR UPDATE/';

        $taking = $this->queriesDuring(fn () => $this->assertTrue($this->correct($receivingId, $itemId, ['quantity' => '8'])));
        $locked = $this->firstQueryLike($taking, $shelfLock);
        $moved  = $this->firstQueryLike($taking, '/^(UPDATE|INSERT INTO) `?\w*item_quantities`?/');

        $this->assertNotNull($locked, 'The shelf is locked while it is checked');
        $this->assertNotNull($moved);
        $this->assertLessThan($moved, $locked, 'The lock is taken before the stock moves');

        $adding = $this->queriesDuring(fn () => $this->assertTrue($this->correct($receivingId, $itemId, ['quantity' => '12'])));

        $this->assertNull($this->firstQueryLike($adding, $shelfLock), 'Adding stock needs no check of the shelf');
        $this->assertSame('12.000', $this->onHand($itemId));
    }

    public function testUpdateLines_DoesNotHoldGoodsThatKeepNoStockToTheShelf(): void
    {
        $itemId      = $this->item('0.00', false);
        $receivingId = $this->buy([$this->purchaseLine($itemId, 0, '10', '100')]);

        $this->accountingDb()->table('item_quantities')->where(['item_id' => $itemId, 'location_id' => self::LOCATION_ID])->update(['quantity' => '0']);

        $this->assertTrue($this->correct($receivingId, $itemId, ['quantity' => '8']), 'There is no stock of the item to go below nothing');
        $this->assertSame('8.000', $this->storedLine($receivingId, $itemId)->quantity_purchased);
    }

    public function testUpdateLines_RefusesToLeaveLessThanNothingWhileTheBooksAreOffToo(): void
    {
        $itemId = $this->item('0.00');

        $this->setConfigValue('accounting_enabled', '0');

        $receivingId = $this->buy([$this->purchaseLine($itemId, 0, '10', '100')]);

        $this->accountingDb()->table('item_quantities')->where(['item_id' => $itemId, 'location_id' => self::LOCATION_ID])->update(['quantity' => '3']);

        $this->assertFalse($this->correct($receivingId, $itemId, ['quantity' => '5']));
        $this->assertSame(AccountingError::PurchaseStockWouldGoNegative, $this->failure()->error);
        $this->assertSame('3.000', $this->onHand($itemId));
        $this->assertTrue($this->correct($receivingId, $itemId, ['quantity' => '7']));
        $this->assertSame('0.000', $this->onHand($itemId));
    }

    public function testDeleteValue_RefusesAPurchaseWhoseGoodsWereSoldAndChangesNothing(): void
    {
        $itemId      = $this->item('0.00');
        $receivingId = $this->buy([$this->purchaseLine($itemId, 0, '10', '100')]);

        $this->assertGreaterThan(0, $this->save([$this->line($itemId, 0, '7', '150', $this->exactCostOf($itemId))]));

        $before = $this->bookState($receivingId, $itemId);

        $this->assertFalse($this->purchases->delete_value($receivingId, $this->employeeId));
        $this->assertInstanceOf(AccountingException::class, $this->failure());
        $this->assertSame(AccountingError::PurchaseStockWouldGoNegative, $this->failure()->error);
        $this->assertSame($before, $this->bookState($receivingId, $itemId));
        $this->assertSame(1, $this->accountingDb()->table('receivings')->where('receiving_id', $receivingId)->countAllResults());
        $this->assertStockTies();
        $this->assertNoLockIsHeldOn($itemId);
    }

    private function assertNoLockIsHeldOn(int $itemId): void
    {
        $other = $this->openSecondConnection();

        try {
            $other->query('SELECT item_id FROM ' . $other->prefixTable('items') . ' WHERE item_id = ? FOR UPDATE', [$itemId]);
            $this->addToAssertionCount(1);
        } finally {
            $other->close();
        }
    }

    public function testDeleteValue_DeletesAPurchaseWhoseGoodsAreStillThereBesideSoldGoodsOfAnother(): void
    {
        $itemId = $this->item('0.00');
        $first  = $this->buy([$this->purchaseLine($itemId, 0, '10', '100')]);
        $second = $this->buy([$this->purchaseLine($itemId, 0, '5', '100')]);

        $this->assertGreaterThan(0, $first);
        $this->assertGreaterThan(0, $this->save([$this->line($itemId, 0, '5', '150', $this->exactCostOf($itemId))]));
        $this->assertSame('10.000', $this->onHand($itemId));
        $this->assertTrue($this->purchases->delete_value($second, $this->employeeId));
        $this->assertSame('5.000', $this->onHand($itemId));
        $this->assertStockTies();
    }

    public function testDeleteValue_GoesBackToTheAverageOfTheStockThatIsLeftAfterASaleAndLeavesTheSaleAsPosted(): void
    {
        $itemId = $this->item('0.00');

        $this->assertGreaterThan(0, $this->buy([$this->purchaseLine($itemId, 0, '10', '100')]));

        $sale   = $this->save([$this->line($itemId, 0, '4', '150', $this->exactCostOf($itemId))]);
        $second = $this->buy([$this->purchaseLine($itemId, 0, '10', '120')]);

        $this->assertSame('112.500000', $this->exactCostOf($itemId));

        $costed = $this->journals($sale);

        $this->assertTrue($this->purchases->delete_value($second, $this->employeeId));
        $this->assertSame('100.000000', $this->exactCostOf($itemId));
        $this->assertSame($costed, $this->journals($sale));
        $this->assertSame('6.000', $this->onHand($itemId));
        $this->assertStockTies();
    }

    public function testDeleteValue_KeepsTheCostWhenNoStockIsLeftToWeigh(): void
    {
        $itemId      = $this->item('0.00');
        $receivingId = $this->buy([$this->purchaseLine($itemId, 0, '10', '100')]);

        $this->assertTrue($this->purchases->delete_value($receivingId, $this->employeeId));
        $this->assertSame('100.000000', $this->exactCostOf($itemId));
        $this->assertSame('0.000', $this->onHand($itemId));
    }

    public function testDeleteValue_DoesNotWeighTheStockOfADeletionThatLeavesTheShelvesAlone(): void
    {
        $itemId      = $this->item('0.00');
        $receivingId = $this->buy([$this->purchaseLine($itemId, 0, '10', '100')]);

        $this->assertGreaterThan(0, $this->save([$this->line($itemId, 0, '7', '150', $this->exactCostOf($itemId))]));

        $costed = $this->exactCostOf($itemId);

        $this->assertTrue($this->purchases->delete_value($receivingId, $this->employeeId, false));
        $this->assertSame($costed, $this->exactCostOf($itemId));
        $this->assertSame('3.000', $this->onHand($itemId));
    }

    public function testDeleteValue_StillDeletesAPurchaseWhoseGoodsWereSoldAndLeavesTheCostWhileTheBooksAreOff(): void
    {
        $itemId = $this->item('0.00');

        $this->setConfigValue('accounting_enabled', '0');
        $this->setConfigValue('receiving_calculate_average_price', '1');
        config(OSPOS::class)->settings['receiving_calculate_average_price'] = '1';

        $this->assertGreaterThan(0, $this->buy([$this->purchaseLine($itemId, 0, '10', '50')]));

        $second = $this->buy([$this->purchaseLine($itemId, 0, '10', '70')]);

        $this->accountingDb()->table('item_quantities')->where(['item_id' => $itemId, 'location_id' => self::LOCATION_ID])->update(['quantity' => '3']);

        $this->assertTrue($this->purchases->delete_value($second, $this->employeeId));
        $this->assertSame('60.0000', $this->itemCostOf($itemId), 'The cost is the one OSPOS keeps: a deletion does not weigh it again');
        $this->assertSame('-7.000', $this->onHand($itemId));

        config(OSPOS::class)->settings['receiving_calculate_average_price'] = '';
    }

    // ---------------------------------------------------------------------------------------
    // What the reference system recorded in the second write batch: fixtures T-47 to T-50
    // ---------------------------------------------------------------------------------------

    /**
     * The purchases of a case, of one item and one supplier, in the order they were made.
     *
     * @param list<array{quantity: string, price: string}> $purchases
     *
     * @return array{item: int, supplier: int, ids: list<int>}
     */
    private function purchasesOfCase(array $purchases): array
    {
        $item     = $this->item('0.00');
        $supplier = $this->supplier('Supplier of the case');
        $ids      = [];

        foreach ($purchases as $purchase) {
            $ids[] = $this->buy([$this->purchaseLine($item, 0, $purchase['quantity'], $purchase['price'])], $supplier);

            $this->assertGreaterThan(0, end($ids));
        }

        return ['item' => $item, 'supplier' => $supplier, 'ids' => $ids];
    }

    /**
     * @return array<string, int>
     */
    private function purchaseRoles(int $supplier): array
    {
        return [
            'supplier'  => $this->supplierAccount($supplier),
            'inventory' => $this->stockAccount(),
            'cogs'      => $this->accountId('cogs'),
            'treasury'  => $this->accountId('cash'),
        ];
    }

    public function testSaveValue_AveragesTheCostOfTwoPurchasesAsTheReferenceSystemDoes(): void
    {
        $case = $this->referenceCase('T-47', 'purchase_average', 'average-cost-after-two-purchases', 'exact');
        $item = $this->item('0.00');
        $made = [];

        foreach ($case['inputs']['purchases'] as $index => $purchase) {
            $this->assertGreaterThan(0, $this->buy([$this->purchaseLine($item, 0, $purchase['quantity'], $purchase['price'])]));

            $made[$index] = bcadd($this->exactCostOf($item), '0', 2);
        }

        $this->assertSameAsFixture($case['expected'], [
            'average_after_the_first'  => $made[0],
            'average_after_the_second' => $made[1],
            'stock_after_the_second'   => bcadd($this->onHand($item), '0', 0),
        ], 'average after two purchases');
    }

    public function testUpdateLines_RecomputesTheAverageOfTheItemAsTheReferenceSystemDoes(): void
    {
        $case   = $this->referenceCase('T-47', 'purchase_line_edit', 'editing-a-saved-purchase-line', 'exact');
        $made   = $this->purchasesOfCase($case['inputs']['purchases']);
        $second = $made['ids'][1];

        $this->assertTrue($this->correct($second, $made['item'], ['quantity' => '8']));

        $afterTheQuantity = [
            'stock'   => bcadd($this->onHand($made['item']), '0', 0),
            'average' => $this->exactCostOf($made['item']),
        ];

        $this->assertTrue($this->correct($second, $made['item'], ['price' => '140']));

        $this->assertSameAsFixture($case['expected'], [
            'after_the_quantity_edit' => $afterTheQuantity,
            'after_the_price_edit'    => [
                'stock'       => bcadd($this->onHand($made['item']), '0', 0),
                'average'     => $this->exactCostOf($made['item']),
                'stock_value' => $this->stockOf($made['item'])['value'],
            ],
        ], 'average after editing the saved line');
        $this->assertStockTies();
    }

    public function testUpdateLines_ReversesTheJournalAndPostsANewRevisionWhereTheReferenceSystemEditsItInPlace(): void
    {
        $case   = $this->referenceCase('T-47', 'purchase_line_edit', 'journal-of-an-edited-purchase-line', 'deliberate_difference');
        $made   = $this->purchasesOfCase($case['inputs']['purchases']);
        $second = $made['ids'][1];
        $first  = $this->standingPurchase($second)[0]['id'];

        $this->assertTrue($this->correct($second, $made['item'], ['quantity' => '8']));
        $this->assertTrue($this->correct($second, $made['item'], ['price' => '140']));

        $journals = $this->purchaseJournals($second);
        $standing = $this->standingPurchase($second);

        $this->assertSameAsFixture($case['expected'], [
            'journal_edited_in_place'  => $standing[0]['id'] === $first,
            'journals_of_the_purchase' => (string) count($journals),
            'reversals'                => (string) $this->accountingDb()->table('accounting_journals')->where('source_type', 'reversal')->whereIn('reverses_journal_id', array_column($journals, 'id'))->countAllResults(),
            'standing_amount'          => array_reduce($standing[0]['lines'], static fn (string $sum, string $line): string => bcadd($sum, explode(':', $line)[1], 2), '0.00'),
        ], 'journal of the edited line');
    }

    public function testDeleteValue_GivesTheCorrectionByDeletingAndBuyingAgainTheAverageTheReferenceSystemGetsByEditingTheLine(): void
    {
        $case = $this->referenceCase('T-47', 'purchase_line_edit', 'correcting-a-purchase-by-deleting-it-and-buying-again', 'exact');
        $made = $this->purchasesOfCase($case['inputs']['purchases']);

        $this->assertTrue($this->purchases->delete_value($made['ids'][1], $this->employeeId));
        $this->assertGreaterThan(0, $this->buy([$this->purchaseLine($made['item'], 0, '8', '140')], $made['supplier']));

        $this->assertSameAsFixture($case['expected'], [
            'average_after_the_correction' => $this->exactCostOf($made['item']),
            'stock_value'                  => $this->stockOf($made['item'])['value'],
            'stock'                        => bcadd($this->onHand($made['item']), '0', 0),
        ], 'correction by delete and buy again');
        $this->assertStockTies();
    }

    public function testDeleteValue_GoesBackToTheAverageOfTheMovementsLeftAsTheReferenceSystemDoes(): void
    {
        $case = $this->referenceCase('T-48', 'purchase_delete', 'deleting-a-purchase-recomputes-the-average', 'exact');
        $made = $this->purchasesOfCase($case['inputs']['purchases']);
        $mean = $this->exactCostOf($made['item']);

        $this->assertTrue($this->purchases->delete_value($made['ids'][1], $this->employeeId));
        $this->assertSameAsFixture($case['expected'], [
            'average_before_the_delete' => $mean,
            'average_after_the_delete'  => $this->exactCostOf($made['item']),
            'stock_after_the_delete'    => bcadd($this->onHand($made['item']), '0', 0),
        ], 'average around the delete');
        $this->assertStockTies();
    }

    /**
     * The journals of a return of a case, taken from the first purchase of the case: the return
     * itself and, when it was refunded in cash, the refund.
     *
     * @param array<string, mixed> $inputs
     *
     * @return array{return: list<array{role: string, debit: string, credit: string}>, refund: list<array{role: string, debit: string, credit: string}>, average: string}
     */
    private function returnOfCase(array $inputs): array
    {
        $made    = $this->purchasesOfCase($inputs['purchases']);
        $return  = $inputs['return'];
        $roles   = $this->purchaseRoles($made['supplier']);
        $savedAs = $this->buy([$this->purchaseLine($made['item'], 0, '-' . $return['quantity'], $return['price'])], $made['supplier'], $return['refunded_in_cash'] ? lang('Sales.cash') : null, $made['ids'][0]);

        $this->assertGreaterThan(0, $savedAs);

        $refund = $this->standingPurchase($savedAs, 'purchase_payment');

        return [
            'return'  => $this->roleLines((int) $this->standingPurchase($savedAs)[0]['id'], $roles, 2),
            'refund'  => $refund === [] ? [] : $this->roleLines((int) $refund[0]['id'], $roles, 2),
            'average' => $this->exactCostOf($made['item']),
        ];
    }

    public function testSaveValue_GivesBackThreeUnitsAtTheAverageAndTheDifferenceToCostOfSalesAsTheReferenceSystemDoes(): void
    {
        $case = $this->referenceCase('T-49', 'purchase_return', 'return-of-3-units-without-a-cash-refund', 'exact');
        $made = $this->returnOfCase($case['inputs']);

        $this->assertSameAsFixture($case['expected'], ['journals' => ['return' => $made['return']], 'average_after' => $made['average']], 'return of 3 units');
        $this->assertSame([], $made['refund']);
    }

    public function testSaveValue_WritesTheRefundJournalOfAReturnRefundedInCashAsTheReferenceSystemDoes(): void
    {
        $case = $this->referenceCase('T-49', 'purchase_return', 'return-of-2-units-with-a-cash-refund', 'exact');
        $made = $this->returnOfCase($case['inputs']);

        $this->assertSameAsFixture($case['expected'], ['journals' => ['return' => $made['return'], 'refund' => $made['refund']], 'average_after' => $made['average']], 'return of 2 units refunded in cash');
    }

    public function testSaveValue_CreditsTheStockAtTheAverageAndThePriceDifferenceToCostOfSalesOnADebitNoteAsTheReferenceSystemDoes(): void
    {
        $case = $this->referenceCase('T-50', 'purchase_return', 'debit-note-priced-above-the-average', 'exact');
        $made = $this->returnOfCase($case['inputs']);

        $this->assertSameAsFixture($case['expected'], ['journals' => ['return' => $made['return']], 'average_after' => $made['average']], 'debit note above the average');
    }

    // ---------------------------------------------------------------------------------------
    // The date typed on a purchase, its payment terms and its due date (WP13)
    // ---------------------------------------------------------------------------------------

    private function purchaseRow(int $receivingId): object
    {
        return $this->accountingDb()->table('receivings')->where('receiving_id', $receivingId)->get()->getRow();
    }

    /**
     * @return list<string> The days the standing journals of a purchase are dated.
     */
    private function standingDays(int $receivingId, string $source = 'purchase'): array
    {
        return array_values(array_unique(array_column($this->standingPurchase($receivingId, $source), 'date')));
    }

    public function testSaveValue_DatesTheJournalsAndTheStockOfAPurchaseByTheDayTypedOnItAndKeepsTheTimeItWasSaved(): void
    {
        $this->grantPurchaseDate();

        $item        = $this->item('0.00');
        $day         = $this->daysAgo(10);
        $receivingId = $this->buy([$this->purchaseLine($item, 0, '5', '20')], -1, null, null, null, $day);
        $purchase    = $this->purchaseRow($receivingId);

        $this->assertSame($day, $purchase->invoice_date);
        $this->assertSame(date('Y-m-d'), substr((string) $purchase->receiving_time, 0, 10));
        $this->assertSame([$day], $this->standingDays($receivingId));
        $this->assertSame($day, $this->supplierPosition()->purchase($receivingId)->date);
        $this->assertGreaterThan(0, $this->accountingDb()->table('accounting_stock_entries')->where(['source_type' => 'purchase', 'source_id' => $receivingId, 'entry_date' => $day])->countAllResults());
        $this->assertSupplierTies();
        $this->assertStockTies();
    }

    public function testSaveValue_DatesAPurchaseTodayWhenNoDayIsTypedAndStoresNoDay(): void
    {
        $receivingId = $this->purchaseOf('100.00');
        $purchase    = $this->purchaseRow($receivingId);

        $this->assertNull($purchase->invoice_date);
        $this->assertNull($purchase->payment_terms_days);
        $this->assertNull($purchase->due_date);
        $this->assertSame([date('Y-m-d')], $this->standingDays($receivingId));
        $this->assertSame(date('Y-m-d'), $this->supplierPosition()->purchase($receivingId)->dueDate);
    }

    public function testSaveValue_DatesThePaymentMadeAtSaveOnTheDayTheMoneyWentWhateverDayIsTypedOnThePurchase(): void
    {
        $this->grantPurchaseDate();

        $item        = $this->item('0.00');
        $day         = $this->daysAgo(7);
        $receivingId = $this->buy([$this->purchaseLine($item, 0, '5', '100')], -1, lang('Sales.cash'), null, null, $day);

        $this->assertSame([$day], $this->standingDays($receivingId, 'purchase'));
        $this->assertSame([date('Y-m-d')], $this->standingDays($receivingId, 'purchase_payment'));
        $this->assertSame('0.00', $this->balance($this->supplierAccount()));
        $this->assertSame('-500.00', $this->balance($this->accountId('cash')));

        $before = $this->supplierPosition(null, $this->daysAgo(1));

        $this->assertSame('500.0000', $before->open(), 'The purchase was unpaid until the day the money went');
        $this->assertTrue($before->agrees());
        $this->assertSupplierTies();
    }

    public function testSaveValue_RefusesADayTypedByABuyerWhoMayNotTypeItAndSavesNothing(): void
    {
        $item      = $this->item('0.00');
        $purchases = $this->countRows('receivings');

        $this->assertSame(ACCOUNTING_FAILED, $this->buy([$this->purchaseLine($item, 0, '1', '50')], -1, null, null, null, $this->daysAgo(3)));
        $this->assertSame(AccountingError::PermissionDenied, $this->failure()->error);
        $this->assertSame($purchases, $this->countRows('receivings'));
        $this->assertSame(0, $this->countJournals());
    }

    public function testSaveValue_RefusesAPurchaseDatedInAClosedPeriodAndLeavesNothingOfIt(): void
    {
        $this->grantPurchaseDate();
        $this->closeThrough($this->daysAgo(5));

        $item      = $this->item('0.00');
        $purchases = $this->countRows('receivings');

        $this->assertSame(ACCOUNTING_FAILED, $this->buy([$this->purchaseLine($item, 0, '1', '50')], -1, null, null, null, $this->daysAgo(10)));
        $this->assertSame(PostingError::PeriodClosed, $this->failure()->error);
        $this->assertSame($purchases, $this->countRows('receivings'));
        $this->assertSame(0, $this->countJournals());
        $this->assertSame(0, $this->countRows('accounting_stock_entries'));

        $open = $this->buy([$this->purchaseLine($item, 0, '1', '50')], -1, null, null, null, $this->daysAgo(4));

        $this->assertGreaterThan(0, $open);
        $this->assertSame([$this->daysAgo(4)], $this->standingDays($open));
    }

    /**
     * @return array<string, array{string, AccountingError}>
     */
    public static function refusedPurchaseDayProvider(): array
    {
        return [
            'tomorrow'          => [date('Y-m-d', strtotime('+1 day')), AccountingError::InvoiceDateInvalid],
            'not a date'        => ['yesterday', AccountingError::InvoiceDateInvalid],
            'a day that is not' => ['2026-02-30', AccountingError::InvoiceDateInvalid],
            'before the books'  => ['2019-12-31', AccountingError::InvoiceDateBeforeStart],
        ];
    }

    #[DataProvider('refusedPurchaseDayProvider')]
    public function testSaveValue_RefusesADayThatIsNotARealDayUpToTodayOrIsBeforeTheBooksStart(string $typed, AccountingError $error): void
    {
        $this->grantPurchaseDate();

        $item      = $this->item('0.00');
        $purchases = $this->countRows('receivings');

        $this->assertSame(ACCOUNTING_FAILED, $this->buy([$this->purchaseLine($item, 0, '1', '50')], -1, null, null, null, $typed));
        $this->assertSame($error, $this->failure()->error);
        $this->assertSame($purchases, $this->countRows('receivings'));
        $this->assertSame(0, $this->countRows('accounting_posting_failures'));
    }

    public function testSaveValue_TakesNoTypedDayOrTermsForAReturnOrWhileAccountingIsOff(): void
    {
        $this->grantPurchaseDate();

        $purchase = $this->purchaseOf('100.00');
        $line     = $this->accountingDb()->table('receivings_items')->where('receiving_id', $purchase)->get()->getRow();
        $return   = $this->buy([$this->purchaseLine((int) $line->item_id, 0, '-0.5', (string) $line->item_unit_price)], -1, null, $purchase, null, $this->daysAgo(3), 30);

        $this->assertGreaterThan(0, $return);
        $this->assertNull($this->purchaseRow($return)->invoice_date);
        $this->assertNull($this->purchaseRow($return)->payment_terms_days);

        $this->setConfigValue('accounting_enabled', '0');

        $audit = $this->countRows('accounting_audit_log');
        $off   = $this->buy([$this->purchaseLine($this->item('0.00'), 0, '1', '10')], -1, null, null, null, $this->daysAgo(3), 30);

        $this->assertGreaterThan(0, $off);
        $this->assertNull($this->purchaseRow($off)->invoice_date);
        $this->assertNull($this->purchaseRow($off)->payment_terms_days);
        $this->assertNull($this->purchaseRow($off)->due_date);
        $this->assertSame($audit, $this->countRows('accounting_audit_log'));
    }

    public function testSaveValue_WorksOutTheDueDateFromTheDayOfThePurchaseAndItsTerms(): void
    {
        $receivingId = $this->purchaseDated('100.00', $this->daysAgo(5), 30);
        $purchase    = $this->purchaseRow($receivingId);

        $this->assertSame(30, (int) $purchase->payment_terms_days);
        $this->assertSame(date('Y-m-d', strtotime('+25 days')), $purchase->due_date);
        $this->assertSame($purchase->due_date, $this->supplierPosition()->purchase($receivingId)->dueDate);
        $this->assertSame(30, $this->supplierPosition()->purchase($receivingId)->termsDays);
    }

    public function testSaveValue_WorksOutTheDueDateFromTodayWhenThePurchaseHasTermsAndNoTypedDay(): void
    {
        $receivingId = $this->buy([$this->purchaseLine($this->item('0.00'), 0, '1', '100')], -1, null, null, null, null, '7');
        $purchase    = $this->purchaseRow($receivingId);

        $this->assertNull($purchase->invoice_date);
        $this->assertSame(date('Y-m-d', strtotime('+7 days')), $purchase->due_date);
    }

    public function testSaveValue_StoresNoTermsAndNoDueDateForAPurchaseWithNoTermsOrZeroDays(): void
    {
        foreach ([null, '', '0', 0] as $terms) {
            $receivingId = $this->purchaseDated('10.00', $this->daysAgo(2), $terms);

            $this->assertNull($this->purchaseRow($receivingId)->payment_terms_days);
            $this->assertNull($this->purchaseRow($receivingId)->due_date);
            $this->assertSame($this->daysAgo(2), $this->supplierPosition()->purchase($receivingId)->dueDate);
        }
    }

    public function testSaveValue_RefusesTermsThatAreNotAWholeNumberOfDaysAndSavesNothing(): void
    {
        foreach (['-1', '2.5', 'soon', '3651'] as $terms) {
            $purchases = $this->countRows('receivings');

            $this->assertSame(ACCOUNTING_FAILED, $this->buy([$this->purchaseLine($this->item('0.00'), 0, '1', '10')], -1, null, null, null, null, $terms), $terms);
            $this->assertSame(AccountingError::PaymentTermsInvalid, $this->failure()->error);
            $this->assertSame($purchases, $this->countRows('receivings'));
        }
    }

    public function testSaveValue_WritesInTheAuditLogWhoTypedTheDayOfAPurchaseAndWhen(): void
    {
        $receivingId = $this->purchaseDated('100.00', $this->daysAgo(3));
        $entries     = $this->accountingDb()->table('accounting_audit_log')->where(['entity_type' => 'receiving', 'entity_id' => $receivingId, 'action' => 'invoice_date_typed'])->get()->getResult();

        $this->assertCount(1, $entries);
        $this->assertSame($this->employeeId, (int) $entries[0]->employee_id);
        $this->assertSame(['date' => $this->daysAgo(3), 'saved_on' => date('Y-m-d')], json_decode((string) $entries[0]->details, true));

        $before = $this->accountingDb()->table('accounting_audit_log')->where('action', 'invoice_date_typed')->countAllResults();

        $this->purchaseOf('10.00');

        $this->assertSame($before, $this->accountingDb()->table('accounting_audit_log')->where('action', 'invoice_date_typed')->countAllResults());
    }

    public function testUpdate_RefusesToChangeTheDayTypedOnAPostedPurchaseAndLeavesItsJournalsAsTheyWere(): void
    {
        $receivingId = $this->purchaseDated('250.00', $this->daysAgo(8), 10);
        $before      = $this->purchaseJournals($receivingId);
        $row         = (array) $this->purchaseRow($receivingId);

        $this->assertFalse($this->purchases->update($receivingId, ['invoice_date' => $this->daysAgo(2), 'comment' => 'x']));
        $this->assertSame(AccountingError::PurchaseLocked, $this->failure()->error);

        $this->assertFalse($this->purchases->update($receivingId, ['invoice_date' => date('Y-m-d'), 'comment' => 'x']));
        $this->assertSame(AccountingError::PurchaseLocked, $this->failure()->error);

        $this->assertSame($before, $this->purchaseJournals($receivingId));
        $this->assertSame($row, (array) $this->purchaseRow($receivingId));
    }

    public function testUpdate_RefusesToChangeTheDayTypedOnAPurchaseWhoseJournalDoesNotStand(): void
    {
        $receivingId = $this->purchaseDated('250.00', $this->daysAgo(8));
        $db          = $this->accountingDb();

        $db->table('accounting_journals')->where('source_id', $receivingId)->whereIn('source_type', [ReceivingPostingBuilder::SOURCE_PURCHASE])->update(['status' => JournalStatus::Reversed->value]);
        $db->table('accounting_stock_entries')->where('source_id', $receivingId)->where('source_type', ReceivingPostingBuilder::SOURCE_PURCHASE)->delete();

        $this->assertFalse((new ReceivingPosting())->isPosted($receivingId));

        $row = (array) $this->purchaseRow($receivingId);

        $this->assertFalse($this->purchases->update($receivingId, ['invoice_date' => $this->daysAgo(2), 'comment' => 'x']));
        $this->assertSame(AccountingError::PurchaseLocked, $this->failure()->error);
        $this->assertSame($row, (array) $this->purchaseRow($receivingId));
    }

    public function testUpdate_AcceptsTheDayTypedOnAPostedPurchaseWhenItIsTheSameDay(): void
    {
        $receivingId = $this->purchaseDated('250.00', $this->daysAgo(8));

        $this->assertTrue($this->purchases->update($receivingId, ['invoice_date' => $this->daysAgo(8), 'comment' => 'same day']));
        $this->assertSame('same day', $this->purchaseRow($receivingId)->comment);
        $this->assertSame($this->daysAgo(8), $this->purchaseRow($receivingId)->invoice_date);
    }

    public function testUpdate_RefusesToMoveTheTimeAPurchaseWasSavedEvenToTheDayItIsDated(): void
    {
        $receivingId = $this->purchaseDated('250.00', $this->daysAgo(8));

        $this->assertFalse($this->purchases->update($receivingId, ['receiving_time' => $this->daysAgo(8) . ' 09:00:00', 'comment' => 'x']));
        $this->assertSame(AccountingError::PurchaseLocked, $this->failure()->error);
        $this->assertSame(date('Y-m-d'), substr((string) $this->purchaseRow($receivingId)->receiving_time, 0, 10));
    }

    public function testUpdate_ChangesThePaymentTermsOfAPostedPurchaseAndNothingInItsJournals(): void
    {
        $receivingId = $this->purchaseDated('395.00', $this->daysAgo(2), 1);
        $before      = $this->purchaseJournals($receivingId);

        $this->assertSame($this->daysAgo(1), $this->purchaseRow($receivingId)->due_date);

        foreach ([[2, $this->daysAgo(0)], [30, date('Y-m-d', strtotime('+28 days'))], [null, null]] as [$terms, $due]) {
            $this->assertTrue($this->purchases->update($receivingId, ['payment_terms_days' => $terms === null ? '' : (string) $terms, 'comment' => 'terms', 'changed_by' => $this->employeeId]));

            $purchase = $this->purchaseRow($receivingId);

            $this->assertSame($terms, $purchase->payment_terms_days === null ? null : (int) $purchase->payment_terms_days);
            $this->assertSame($due, $purchase->due_date);
            $this->assertSame($due ?? $this->daysAgo(2), $this->supplierPosition()->purchase($receivingId)->dueDate);
            $this->assertSame($before, $this->purchaseJournals($receivingId));
        }

        $this->assertSupplierTies();
    }

    public function testUpdate_WritesInTheAuditLogWhatTheTermsWereAndWhatTheyBecameAndWritesNothingWhenTheyStay(): void
    {
        $receivingId = $this->purchaseDated('100.00', $this->daysAgo(2), 5);

        $this->assertTrue($this->purchases->update($receivingId, ['payment_terms_days' => '5', 'comment' => 'same', 'changed_by' => $this->employeeId]));
        $this->assertSame(0, $this->accountingDb()->table('accounting_audit_log')->where('action', 'payment_terms_changed')->countAllResults());

        $this->assertTrue($this->purchases->update($receivingId, ['payment_terms_days' => '9', 'comment' => 'longer', 'changed_by' => $this->employeeId]));

        $entries = $this->accountingDb()->table('accounting_audit_log')->where(['entity_type' => 'receiving', 'entity_id' => $receivingId, 'action' => 'payment_terms_changed'])->get()->getResult();

        $this->assertCount(1, $entries);
        $this->assertSame($this->employeeId, (int) $entries[0]->employee_id);
        $this->assertSame(['from' => 5, 'to' => 9, 'due_date' => date('Y-m-d', strtotime('+7 days'))], json_decode((string) $entries[0]->details, true));
    }

    public function testUpdate_RefusesTermsThatAreNotAWholeNumberOfDaysAndChangesNothing(): void
    {
        $receivingId = $this->purchaseDated('100.00', $this->daysAgo(2), 5);

        foreach (['-3', '1.5', 'x', '99999'] as $terms) {
            $this->assertFalse($this->purchases->update($receivingId, ['payment_terms_days' => $terms, 'comment' => 'bad']), $terms);
            $this->assertSame(AccountingError::PaymentTermsInvalid, $this->failure()->error);
        }

        $this->assertSame(5, (int) $this->purchaseRow($receivingId)->payment_terms_days);
        $this->assertSame('test', $this->purchaseRow($receivingId)->comment);
    }

    public function testUpdate_TakesNoTermsForAReturnOrWhileAccountingIsOff(): void
    {
        $purchase = $this->purchaseOf('100.00');
        $return   = $this->debitNoteOf($purchase, '50.00');

        $this->assertTrue($this->purchases->update($return, ['payment_terms_days' => '30', 'comment' => 'return']));
        $this->assertNull($this->purchaseRow($return)->payment_terms_days);

        $this->setConfigValue('accounting_enabled', '0');

        $this->assertTrue($this->purchases->update($purchase, ['payment_terms_days' => '30', 'comment' => 'off']));
        $this->assertNull($this->purchaseRow($purchase)->payment_terms_days);
        $this->assertNull($this->purchaseRow($purchase)->due_date);
    }

    public function testUpdate_ChangesTheTermsOfAPurchaseInAClosedPeriodBecauseNoJournalMoves(): void
    {
        $receivingId = $this->purchaseDated('100.00', $this->daysAgo(10), 5);

        $this->closeThrough($this->daysAgo(2));

        $before = $this->purchaseJournals($receivingId);

        $this->assertTrue($this->purchases->update($receivingId, ['payment_terms_days' => '60', 'comment' => 'late', 'changed_by' => $this->employeeId]));
        $this->assertSame(60, (int) $this->purchaseRow($receivingId)->payment_terms_days);
        $this->assertSame($before, $this->purchaseJournals($receivingId));
    }

    public function testPostCompleted_PaysAPurchaseByCheckFromTheBankTheBuyerWorksFromAndWritesNoChequeRecord(): void
    {
        $this->accountingDb()->table('grants')->insert(['person_id' => $this->employeeId, 'permission_id' => BranchGuard::ALL_BRANCHES, 'menu_group' => 'home']);

        $treasuries = new TreasuryService();
        $bank       = $treasuries->create(['name' => 'Buyer bank', 'kind' => 'bank', 'bank_name' => 'Gulf Bank', 'account_number' => '5500000003']);

        $treasuries->setDefaultFor($this->employeeId, $bank);

        $itemId = $this->item('0.00');
        $id     = $this->buy([$this->purchaseLine($itemId, 0, '2', '50')], -1, lang('Sales.check'));
        $paid   = array_values(array_filter($this->purchaseJournals($id), static fn (array $journal): bool => $journal['source'] === 'purchase_payment'))[0];

        $this->assertSame($bank, (int) $this->accountingDb()->table('receivings')->where('receiving_id', $id)->get()->getRow()->treasury_id);
        $this->assertSame([$this->supplierAccount() . ':100.00:0.00', $treasuries->accountOf($bank) . ':0.00:100.00'], $paid['lines']);
        $this->assertSame(0, $this->countRows('cheques'));
        $this->assertSame('0.00', $this->balance($this->accountId('notes_payable')));
    }

    public function testPostCompleted_PaysAPurchaseByCheckFromTheAccountRoutedForChecksWhenTheBuyerHasNoTreasury(): void
    {
        $held = $this->customAccount('12', '1230', 'Checks held');

        $this->accountingDb()->table('accounting_routing')->where('routing_key', 'payment.check')->update(['account_id' => $held]);

        $itemId = $this->item('0.00');
        $id     = $this->buy([$this->purchaseLine($itemId, 0, '2', '50')], -1, lang('Sales.check'));

        $this->assertNull($this->accountingDb()->table('receivings')->where('receiving_id', $id)->get()->getRow()->treasury_id);
        $this->assertSame('-100.00', $this->balance($held));
        $this->assertSame(0, $this->countRows('cheques'));
    }

    public function testJournalsOf_LeavesOutThePreviewsOfABatch(): void
    {
        $receivingId = $this->purchaseOf('500', null, lang('Sales.cash'));
        $before      = $this->purchaseJournals($receivingId);

        $this->previewOfSource('purchase', $receivingId);
        $this->previewOfSource('purchase_payment', $receivingId);

        $this->assertSame($before, $this->purchaseJournals($receivingId));
    }

    // ---------------------------------------------------------------------------------------
    // The description template of the journals of purchases
    // ---------------------------------------------------------------------------------------

    private function descriptionOfPurchase(int $receivingId, string $source = 'purchase'): string
    {
        return (string) $this->accountingDb()->table('accounting_journals')->where(['source_type' => $source, 'source_id' => $receivingId])->orderBy('journal_id')->get()->getRow()->description;
    }

    public function testSaveValue_DescribesTheJournalOfAPurchaseWithTheTemplateOfPurchases(): void
    {
        $this->accountingDb()->table('suppliers')->where('person_id', $this->supplierId)->update(['company_name' => 'Gulf Trading']);
        $this->setConfigValue('accounting_journal_description_purchases', '{type} {number} from {party} on {date}');

        $receivingId = $this->purchaseOf('100.00');

        $this->assertSame(lang('Accounting.template_type_purchase') . ' RECV ' . $receivingId . ' from Gulf Trading on ' . date('Y-m-d'), $this->descriptionOfPurchase($receivingId));
    }

    public function testSaveValue_LeavesTheDescriptionOfAPurchaseAsItWasWhenThereIsNoTemplate(): void
    {
        $receivingId = $this->purchaseOf('100.00');

        $this->assertSame('Purchase #' . $receivingId, $this->descriptionOfPurchase($receivingId));
    }

    public function testSaveValue_DoesNotDescribeThePaymentOfAPurchaseWithTheTemplate(): void
    {
        $this->setConfigValue('accounting_journal_description_purchases', 'Doc {number}');

        $receivingId = $this->purchaseOf('100.00', null, lang('Sales.cash'));

        $this->assertSame('Doc RECV ' . $receivingId, $this->descriptionOfPurchase($receivingId));
        $this->assertSame('Payment of purchase #' . $receivingId, $this->descriptionOfPurchase($receivingId, 'purchase_payment'));
    }
}
