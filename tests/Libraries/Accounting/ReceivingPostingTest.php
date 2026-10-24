<?php

namespace Tests\Libraries\Accounting;

use App\Libraries\Accounting\AccountingError;
use App\Libraries\Accounting\AccountingException;
use App\Libraries\Accounting\PostingError;
use App\Libraries\Accounting\PostingException;
use App\Libraries\Accounting\ReceivingPosting;
use App\Libraries\Accounting\StockLedger;
use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\DatabaseTestTrait;
use Config\Database;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Support\AccountingFixtureTrait;
use Tests\Support\ConcurrentDbRaceTrait;
use Tests\Support\EmployeeFixtureTrait;
use Tests\Support\ItemFixtureTrait;
use Tests\Support\ParityFixture;
use Tests\Support\PurchaseAccountingFixtureTrait;
use Tests\Support\SaleAccountingFixtureTrait;

/**
 * What a purchase puts in the books, through the real purchase model: the goods and the tax in,
 * what the supplier is owed, the payment made at once, goods going back to a supplier at what they
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

    public function testPostCompleted_TakesAFixedDiscountOffTheLine(): void
    {
        $itemId = $this->item('0.00');

        $receivingId = $this->buy([$this->purchaseLine($itemId, 0, '10', '50', '0', '20', FIXED)]);

        $this->assertGreaterThan(0, $receivingId);
        $this->assertSame($this->expectedLines([['inventory', '480.00', '0.00'], ['supplier', '0.00', '480.00']]), $this->standingPurchase($receivingId)[0]['lines']);
        $this->assertSame('48.0000', $this->itemCostOf($itemId));
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
        $this->assertSame('6.1400', $this->itemCostOf($itemId), '43/7 = 6.142857, which the cost column keeps at two decimals');
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
}
