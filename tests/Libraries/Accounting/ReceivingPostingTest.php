<?php

namespace Tests\Libraries\Accounting;

use App\Libraries\Accounting\AccountingError;
use App\Libraries\Accounting\AccountingException;
use App\Libraries\Accounting\PostingError;
use App\Libraries\Accounting\PostingException;
use App\Libraries\Accounting\ReceivingPosting;
use App\Libraries\Accounting\StockLedger;
use App\Libraries\Accounting\TreasuryService;
use App\Libraries\Organization\BranchGuard;
use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\DatabaseTestTrait;
use Config\Database;
use PHPUnit\Framework\Attributes\DataProvider;
use ReflectionMethod;
use Tests\Support\AccountingFixtureTrait;
use Tests\Support\ConcurrentDbRaceTrait;
use Tests\Support\EmployeeFixtureTrait;
use Tests\Support\ItemFixtureTrait;
use Tests\Support\ParityFixture;
use Tests\Support\PurchaseAccountingFixtureTrait;
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
    use AccountingFixtureTrait;
    use ConcurrentDbRaceTrait;
    use EmployeeFixtureTrait;
    use ItemFixtureTrait;
    use SaleAccountingFixtureTrait;
    use PurchaseAccountingFixtureTrait;

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

    public function testSaveValue_RefusesAMoveBetweenLocationsOfDifferentBranches(): void
    {
        $itemId = $this->item('0.00');
        $other  = $this->anotherLocation('Branch warehouse');
        $branch = $this->createBranch('B3', 'Third branch');

        $this->accountingDb()->table('stock_locations')->where('location_id', $other)->update(['branch_id' => $branch]);
        $this->assertGreaterThan(0, $this->buy([$this->purchaseLine($itemId, 0, '10', '50')]));

        $result = $this->buy([$this->purchaseLine($itemId, 0, '4', '0', '0', '0', PERCENT, $other), $this->purchaseLine($itemId, 1, '-4', '0')], null, null);

        $this->assertRefusedWith(PostingError::PurchaseAcrossBranches, $result);
        $this->assertSame('10.000', $this->onHand($itemId));
        $this->assertSame('0.000', $this->onHand($itemId, $other));
        $this->assertStockTies();
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

    public function testSaveValue_HasNoDiscountOnTheWholePurchaseThatTheReferenceSystemTakesAfterTheLineDiscount(): void
    {
        $case = $this->parityCase('T-16', 'purchase_discount', 'discount-on-the-whole-purchase-after-the-line-discount', 'differs');
        $names = array_map(static fn ($parameter): string => $parameter->getName(), (new ReflectionMethod($this->purchases, 'save_value'))->getParameters());

        $this->assertSame('not_available', $case['expected']['discount_on_the_whole_purchase']);
        $this->assertSame([], array_values(array_filter($names, static fn (string $name): bool => str_contains($name, 'discount'))));
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
}
