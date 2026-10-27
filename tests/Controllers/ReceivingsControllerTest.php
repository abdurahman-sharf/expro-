<?php

namespace Tests\Controllers;

use App\Libraries\Accounting\TreasuryService;
use App\Libraries\Numbering\DocumentNumbering;
use App\Libraries\Numbering\DocumentType;
use App\Libraries\Organization\BranchGuard;
use CodeIgniter\Exceptions\PageNotFoundException;
use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\DatabaseTestTrait;
use CodeIgniter\Test\FeatureTestTrait;
use Config\Database;
use Config\OSPOS;
use Tests\Support\AccountingFixtureTrait;
use Tests\Support\EmployeeFixtureTrait;
use Tests\Support\ItemFixtureTrait;
use Tests\Support\PurchaseAccountingFixtureTrait;
use Tests\Support\SaleAccountingFixtureTrait;

/**
 * The purchase screens: what the register keeps in the cart, who may work in which branch, and
 * what a person is told when the books refuse a purchase. The purchases themselves, and what they
 * put in the books, are the business of ReceivingPostingTest.
 */
class ReceivingsControllerTest extends CIUnitTestCase
{
    use DatabaseTestTrait;
    use FeatureTestTrait;
    use AccountingFixtureTrait;
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

        $db = $this->accountingDb();
        $db->table('document_numbers')->truncate();
        $db->table('document_sequences')->truncate();
        $db->table('accounting_audit_log')->where('entity_type', 'document_numbering')->delete();

        parent::tearDown();
    }

    /**
     * An employee of the purchase screens, who works in every branch unless some are named.
     *
     * @param list<int>|null $branchIds
     * @param list<string>   $extraGrants
     */
    private function buyer(?array $branchIds = null, array $extraGrants = ['items', 'accounting_journals']): int
    {
        $grants = [
            ['permission_id' => 'receivings', 'menu_group' => 'home'],
            ['permission_id' => 'receivings_stock', 'menu_group' => 'home'],
            ['permission_id' => 'reports_receivings', 'menu_group' => 'home'],
        ];

        foreach ($extraGrants as $permissionId) {
            $grants[] = ['permission_id' => $permissionId, 'menu_group' => 'home'];
        }

        if ($branchIds === null) {
            $grants[] = ['permission_id' => BranchGuard::ALL_BRANCHES, 'menu_group' => '--'];
        }

        $personId = $this->createEmployee(first_name: 'Buyer', last_name: 'Test', grants: $grants);

        foreach ($branchIds ?? [] as $index => $branchId) {
            $this->accountingDb()->table('employee_branches')->insert(['person_id' => $personId, 'branch_id' => $branchId, 'is_default' => $index === 0 ? 1 : 0]);
        }

        return $personId;
    }

    private function loginAs(int $personId, array $more = []): void
    {
        $this->withSession(array_merge(['person_id' => $personId, 'menu_group' => 'home'], $more));
    }

    /**
     * A stock location in a branch, with its account and the grants the employee needs there.
     */
    private function depotIn(int $branchId, int $personId): int
    {
        $db   = $this->accountingDb();
        $name = 'depot' . uniqid();

        $locationId = $this->anotherLocation($name);
        $db->table('stock_locations')->where('location_id', $locationId)->update(['branch_id' => $branchId]);

        foreach (['items', 'receivings'] as $module) {
            $db->table('permissions')->insert(['permission_id' => "{$module}_{$name}", 'module_id' => $module, 'location_id' => $locationId]);
            $db->table('grants')->insert(['permission_id' => "{$module}_{$name}", 'person_id' => $personId, 'menu_group' => 'home']);
        }

        return $locationId;
    }

    private function grantOnLocation(int $locationId, int $personId): void
    {
        $permissions = $this->accountingDb()->table('permissions')->whereIn('module_id', ['items', 'receivings'])->where('location_id', $locationId)->get()->getResult();

        $this->accountingDb()->table('grants')->insertBatch(array_map(
            static fn (object $permission): array => ['permission_id' => $permission->permission_id, 'person_id' => $personId, 'menu_group' => 'home'],
            $permissions,
        ));
    }

    private function anotherBranch(): int
    {
        return $this->createBranch('R' . substr(md5(uniqid('', true)), 0, 10), 'Receiving branch');
    }

    /**
     * @return array<string, mixed>
     */
    private function cartLine(int $itemId, int $line, string $quantity, string $price, string $tax = '0', int $locationId = self::LOCATION_ID): array
    {
        return [
            'item_id'                    => $itemId,
            'item_location'              => $locationId,
            'item_number'                => 'TEST-1',
            'stock_name'                 => 'Test Location',
            'line'                       => $line,
            'name'                       => 'Test Item',
            'description'                => 'Test Item',
            'serialnumber'               => '',
            'attribute_values'           => null,
            'attribute_dtvalues'         => null,
            'allow_alt_description'      => 0,
            'is_serialized'              => 0,
            'quantity'                   => $quantity,
            'discount'                   => '0',
            'discount_type'              => 0,
            'in_stock'                   => '0',
            'price'                      => $price,
            'receiving_quantity'         => '1',
            'receiving_quantity_choices' => [1 => 'x1'],
            'tax_percent'                => $tax,
            'total'                      => bcmul($quantity, $price, 4),
        ];
    }

    /**
     * @param list<array<string, mixed>> $lines
     *
     * @return array<string, mixed>
     */
    private function cart(array $lines, string $mode = 'receive', ?int $supplierId = -1, ?int $origin = null, int $source = self::LOCATION_ID): array
    {
        $cart = [];

        foreach ($lines as $line) {
            $cart[$line['line']] = $line;
        }

        return array_filter([
            'recv_cart'         => $cart,
            'recv_mode'         => $mode,
            'recv_supplier'     => $supplierId === -1 ? $this->supplierId : ($supplierId ?? -1),
            'recv_stock_source' => $source,
            'recv_return_origin' => $origin,
        ], static fn (mixed $value): bool => $value !== null);
    }

    private function receivingRow(int $receivingId): ?object
    {
        return $this->accountingDb()->table('receivings')->where('receiving_id', $receivingId)->get()->getRow();
    }

    /**
     * @return array<string, mixed>
     */
    private function jsonOf($response): array
    {
        return json_decode($response->getJSON(), true);
    }

    // ---------------------------------------------------------------------------------------
    // Who may open the register
    // ---------------------------------------------------------------------------------------

    public function testGetIndex_ShowsTheRegisterToAnEmployeeOfTheBranch(): void
    {
        $this->loginAs($this->buyer());

        $this->get('/receivings')->assertStatus(200);
    }

    public function testGetIndex_SendsAnEmployeeWhoseBranchesHaveNoStockLocationToTheNoAccessPage(): void
    {
        $this->loginAs($this->buyer([$this->anotherBranch()]));

        $response = $this->get('/receivings');

        $response->assertStatus(302);
        $response->assertRedirectTo('no_access/receivings/receivings_stock');
    }

    // ---------------------------------------------------------------------------------------
    // The tax of a line
    // ---------------------------------------------------------------------------------------

    public function testPostAdd_PutsTheTaxTheItemCarriesOnTheLine(): void
    {
        $itemId = $this->item('0.00');
        $this->accountingDb()->table('items_taxes')->insert(['item_id' => $itemId, 'name' => 'VAT', 'percent' => '15.000']);
        $this->loginAs($this->buyer(), ['recv_mode' => 'receive', 'recv_stock_source' => self::LOCATION_ID]);

        $this->post('/receivings/add', ['item' => (string) $itemId])->assertStatus(200);

        $this->accountingDb()->table('items_taxes')->where('item_id', $itemId)->delete();
        $cart = session()->get('recv_cart');

        $this->assertSame('15.000', $cart[1]['tax_percent'] ?? null);
    }

    public function testPostEditItem_TakesTheTaxOfALineFromWhoeverMayEditCosts(): void
    {
        $itemId = $this->item('0.00');
        $this->loginAs($this->buyer(), $this->cart([$this->cartLine($itemId, 1, '5', '100')]));

        $this->post('/receivings/editItem/1', ['price' => '100', 'quantity' => '5', 'discount' => '0', 'receiving_quantity' => '1', 'tax_percent' => '15', 'description' => 'x', 'discount_type' => '0'])->assertStatus(200);

        $this->assertSame('15', (string) session()->get('recv_cart')[1]['tax_percent']);
    }

    public function testPostEditItem_RefusesATaxAboveAHundredAndKeepsTheOldOne(): void
    {
        $itemId = $this->item('0.00');
        $this->loginAs($this->buyer(), $this->cart([$this->cartLine($itemId, 1, '5', '100', '15')]));

        $response = $this->post('/receivings/editItem/1', ['price' => '100', 'quantity' => '5', 'discount' => '0', 'receiving_quantity' => '1', 'tax_percent' => '150', 'description' => 'x', 'discount_type' => '0']);

        $response->assertSee(lang('Receivings.error_editing_item'));
        $this->assertSame('15', (string) session()->get('recv_cart')[1]['tax_percent']);
    }

    public function testPostEditItem_KeepsTheTaxOfALineWhenTheEmployeeMayNotEditCosts(): void
    {
        $itemId = $this->item('0.00');
        $this->loginAs($this->buyer(null, []), $this->cart([$this->cartLine($itemId, 1, '5', '100', '15')]));

        $this->post('/receivings/editItem/1', ['price' => '100', 'quantity' => '5', 'discount' => '0', 'receiving_quantity' => '1', 'tax_percent' => '0', 'description' => 'x', 'discount_type' => '0'])->assertStatus(200);

        $this->assertSame('15', (string) session()->get('recv_cart')[1]['tax_percent']);
    }

    public function testPostEditItem_TakesAFixedDiscountOffEachUnitOfTheLine(): void
    {
        $itemId = $this->item('0.00');
        $this->loginAs($this->buyer(), $this->cart([$this->cartLine($itemId, 1, '10', '100', '15')]));

        $this->post('/receivings/editItem/1', ['price' => '100', 'quantity' => '10', 'discount' => '50', 'receiving_quantity' => '1', 'tax_percent' => '15', 'description' => 'x', 'discount_type' => '1'])->assertStatus(200);

        $this->assertSame('500.00', bcadd((string) session()->get('recv_cart')[1]['total'], '0', 2));
    }

    public function testGetIndex_ShowsTheTotalTheCartHoldsForALineWithAFixedDiscount(): void
    {
        $itemId = $this->item('0.00');
        $line   = array_merge($this->cartLine($itemId, 1, '10', '100'), ['discount' => '50', 'discount_type' => 1, 'total' => '500.0000']);
        $this->loginAs($this->buyer(), $this->cart([$line]));

        $response = $this->get('/receivings');

        $response->assertSee(to_currency('500'));
        $response->assertDontSee(to_currency('950'));
    }

    public function testGetIndex_ShowsTheTaxAndTheTotalWithTax(): void
    {
        $itemId = $this->item('0.00');
        $this->loginAs($this->buyer(), $this->cart([$this->cartLine($itemId, 1, '20', '100', '15')]));

        $response = $this->get('/receivings');

        $response->assertSee(lang('Receivings.tax_total'));
        $response->assertSee(lang('Receivings.total_with_tax'));
        $response->assertSee(to_currency('2300'));
    }

    // ---------------------------------------------------------------------------------------
    // Completing a purchase
    // ---------------------------------------------------------------------------------------

    public function testPostComplete_SavesAndPostsAPurchaseWithTheTaxOfItsLines(): void
    {
        $itemId = $this->item('0.00');
        $before = $this->countRows('receivings');
        $this->loginAs($this->buyer(), $this->cart([$this->cartLine($itemId, 1, '20', '100', '15')]));

        $response = $this->post('/receivings/complete', $this->completeForm());

        $response->assertStatus(200);
        $response->assertSee('RECV ');
        $this->assertSame($before + 1, $this->countRows('receivings'));

        $receivingId = (int) $this->accountingDb()->table('receivings')->selectMax('receiving_id', 'last')->get()->getRow()->last;
        $this->receivingIds[] = $receivingId;

        $this->assertSame('15.000', $this->accountingDb()->table('receivings_items')->where('receiving_id', $receivingId)->get()->getRow()->tax_percent);
        $this->assertSame('-2300.00', $this->balance($this->supplierAccount()));
        $this->assertSame('20.000', $this->onHand($itemId));
    }

    public function testPostComplete_TakesAnAmountTenderedLeftEmptyAndOneThatIsEntered(): void
    {
        $itemId = $this->item('0.00');
        $this->loginAs($this->buyer(), $this->cart([$this->cartLine($itemId, 1, '2', '100')]));

        $this->post('/receivings/complete', $this->completeForm(lang('Sales.cash'), ''))->assertSee('RECV ');

        $this->loginAs($this->buyer(), $this->cart([$this->cartLine($itemId, 1, '2', '100')]));

        $this->post('/receivings/complete', $this->completeForm(lang('Sales.cash'), '500.00'))->assertSee('RECV ');

        $ids = $this->accountingDb()->table('receivings')->select('receiving_id')->where('comment', '')->orderBy('receiving_id', 'DESC')->limit(2)->get()->getResultArray();
        $this->receivingIds = array_merge($this->receivingIds, array_map('intval', array_column($ids, 'receiving_id')));
        $this->assertCount(2, $ids);
    }

    public function testPostComplete_SaysWhyTheBooksRefusedTheReceivingAndKeepsTheCart(): void
    {
        $itemId = $this->item('0.00');
        $before = $this->countRows('receivings');
        $this->loginAs($this->buyer(), $this->cart([$this->cartLine($itemId, 1, '20', '100', '15')], 'receive', null));

        $response = $this->post('/receivings/complete', $this->completeForm());

        $response->assertSee(lang('Accounting.posting_supplier_required'));
        $this->assertSame($before, $this->countRows('receivings'));
        $this->assertNotEmpty(session()->get('recv_cart'), 'What was entered is still in the cart');
        $this->assertSame('0.000', $this->onHand($itemId));
    }

    public function testPostComplete_RefusesAReturnThatNamesNoReceiving(): void
    {
        $itemId   = $this->item('0.00');
        $purchase = $this->buy([$this->purchaseLine($itemId, 0, '10', '50')]);
        $before   = $this->countRows('receivings');
        $this->loginAs($this->buyer(), $this->cart([$this->cartLine($itemId, 1, '-2', '50')], 'return'));

        $response = $this->post('/receivings/complete', $this->completeForm());

        $response->assertSee(lang('Accounting.posting_purchase_return_origin_required'));
        $this->assertSame($before, $this->countRows('receivings'));
        $this->assertSame('10.000', $this->onHand($itemId));
        $this->assertGreaterThan(0, $purchase);
    }

    public function testPostComplete_TakesTheReceivingAReturnComesFromFromTheRegister(): void
    {
        $itemId   = $this->item('0.00');
        $purchase = $this->buy([$this->purchaseLine($itemId, 0, '10', '50')]);
        $this->loginAs($this->buyer(), $this->cart([$this->cartLine($itemId, 1, '-2', '50')], 'return', -1, $purchase));

        $this->post('/receivings/complete', $this->completeForm())->assertSee('RECV ');

        $return = $this->accountingDb()->table('receivings')->where('return_of_receiving_id', $purchase)->get()->getRow();
        $this->receivingIds[] = (int) $return->receiving_id;

        $this->assertSame('return', $return->receiving_type);
        $this->assertSame('8.000', $this->onHand($itemId));
    }

    public function testPostComplete_ARefusedReturnOfMoreThanWasBoughtSaysWhatIsLeft(): void
    {
        $itemId   = $this->item('0.00');
        $purchase = $this->buy([$this->purchaseLine($itemId, 0, '10', '50')]);
        $this->loginAs($this->buyer(), $this->cart([$this->cartLine($itemId, 1, '-11', '50')], 'return', -1, $purchase));

        $response = $this->post('/receivings/complete', $this->completeForm());

        $response->assertSee(lang('Accounting.posting_purchase_return_exceeds_origin', ['10.000']));
        $this->assertSame('10.000', $this->onHand($itemId));
    }

    public function testPostComplete_RefusesACartThatWorksInAnotherBranch(): void
    {
        $branchId = $this->anotherBranch();
        $buyerId  = $this->buyer([$branchId]);
        $location = $this->depotIn($branchId, $buyerId);
        $itemId   = $this->item('0.00');
        $before   = $this->countRows('receivings');

        $this->loginAs($buyerId, $this->cart([$this->cartLine($itemId, 1, '5', '10')], 'receive', -1, null, self::LOCATION_ID));

        $response = $this->post('/receivings/complete', $this->completeForm());

        $response->assertSee(lang('Receivings.branch_not_allowed'));
        $this->assertSame($before, $this->countRows('receivings'));
        $this->assertGreaterThan(0, $location);
    }

    public function testPostComplete_RefusesACartThatMixesTheLocationsOfTwoBranches(): void
    {
        $branchId = $this->anotherBranch();
        $buyerId  = $this->buyer();
        $location = $this->depotIn($branchId, $buyerId);
        $itemId   = $this->item('0.00');
        $before   = $this->countRows('receivings');

        $this->loginAs($buyerId, $this->cart([$this->cartLine($itemId, 1, '5', '10'), $this->cartLine($itemId, 2, '5', '10', '0', $location)]));

        $response = $this->post('/receivings/complete', $this->completeForm());

        $response->assertSee(lang('Receivings.mixed_branches'));
        $this->assertSame($before, $this->countRows('receivings'));
    }

    public function testPostComplete_RefusesAReturnOfAReceivingOfAnotherBranch(): void
    {
        $branchId = $this->anotherBranch();
        $buyerId  = $this->buyer([$branchId]);
        $this->depotIn($branchId, $buyerId);
        $itemId   = $this->item('0.00');
        $purchase = $this->buy([$this->purchaseLine($itemId, 0, '10', '50')]);
        $before   = $this->countRows('receivings');

        $location = (int) $this->accountingDb()->table('stock_locations')->where('branch_id', $branchId)->get()->getRow()->location_id;

        $this->loginAs($buyerId, $this->cart([$this->cartLine($itemId, 1, '-2', '50', '0', $location)], 'return', -1, $purchase, $location));

        $response = $this->post('/receivings/complete', $this->completeForm());

        $response->assertSee(lang('Receivings.branch_not_allowed'));
        $this->assertSame($before, $this->countRows('receivings'));
    }

    // ---------------------------------------------------------------------------------------
    // Stock moving from one branch to another
    // ---------------------------------------------------------------------------------------

    /**
     * Ten units in the first branch, a location of another, and a transfer of four ready in the register.
     *
     * @return array{item: int, branch: int, other: int}
     */
    private function transferSetup(int $buyerId): array
    {
        $branchId = $this->anotherBranch();
        $other    = $this->depotIn($branchId, $buyerId);
        $itemId   = $this->item('0.00');

        $this->assertGreaterThan(0, $this->buy([$this->purchaseLine($itemId, 0, '10', '50')]));

        return ['item' => $itemId, 'branch' => $branchId, 'other' => $other];
    }

    private function newestReceiving(): object
    {
        $row = $this->accountingDb()->table('receivings')->orderBy('receiving_id', 'DESC')->limit(1)->get()->getRow();

        $this->receivingIds[] = (int) $row->receiving_id;

        return $row;
    }

    /**
     * A transfer between branches that is already in the books.
     */
    private function transferInTheBooks(): array
    {
        $itemId = $this->item('0.00');
        $branch = $this->anotherBranch();
        $other  = $this->locationOfBranch($branch, 'Branch warehouse');

        $this->assertGreaterThan(0, $this->buy([$this->purchaseLine($itemId, 0, '10', '50')]));
        $this->workInEveryBranch();

        $receivingId = $this->buy([$this->purchaseLine($itemId, 0, '4', '0', '0', '0', PERCENT, $other), $this->purchaseLine($itemId, 1, '-4', '0')], null, null);

        $this->assertGreaterThan(0, $receivingId);

        return ['receiving' => $receivingId, 'item' => $itemId, 'branch' => $branch, 'other' => $other];
    }

    public function testGetIndex_SaysThatARequisitionBetweenBranchesIsPostedInBothBranches(): void
    {
        $buyerId  = $this->buyer();
        $branchId = $this->anotherBranch();
        $other    = $this->depotIn($branchId, $buyerId);

        $this->loginAs($buyerId, ['recv_mode' => 'requisition', 'recv_stock_source' => self::LOCATION_ID, 'recv_stock_destination' => $other]);

        $response = $this->get('/receivings');

        $response->assertStatus(200);
        $response->assertSee('id="between_branches_note"');
    }

    public function testGetIndex_SaysTheSameOfARequisitionBetweenBranchesWithAccountingOffWithoutPromisingJournals(): void
    {
        $this->setConfigValue('accounting_enabled', '0');

        $buyerId = $this->buyer();
        $other   = $this->depotIn($this->anotherBranch(), $buyerId);

        $this->loginAs($buyerId, ['recv_mode' => 'requisition', 'recv_stock_source' => self::LOCATION_ID, 'recv_stock_destination' => $other]);

        $response = $this->get('/receivings');

        $response->assertSee('id="between_branches_note"');
        $this->assertStringContainsString('with accounting on', lang('Receivings.between_branches'));
    }

    public function testPostRequisitionComplete_StartsAgainFromWhatWasEnteredAfterARefusal(): void
    {
        $buyerId = $this->buyer();
        ['item' => $itemId, 'other' => $other] = $this->transferSetup($buyerId);
        $before = $this->countRows('receivings');

        $this->accountingDb()->table('accounting_routing')->where('routing_key', 'branches.payable')->update(['account_id' => null]);
        $this->loginAs($buyerId, $this->cart([$this->cartLine($itemId, 1, '4', '0')], 'requisition', null) + ['recv_stock_destination' => $other]);

        $this->post('/receivings/requisitionComplete', $this->completeForm())->assertSee(lang('Accounting.posting_inter_branch_accounts_missing'));

        $refused = session()->get('recv_cart');

        $this->assertSame($before, $this->countRows('receivings'));
        $this->assertSame(['4.000', '-4.000'], array_values(array_map(static fn (array $item): string => bcadd((string) $item['quantity'], '0', 3), $refused)), 'The refused cart shows what goes in and what comes out');

        $this->accountingDb()->table('accounting_routing')->where('routing_key', 'branches.payable')->update(['account_id' => $this->accountId('branches_payable')]);
        $this->loginAs($buyerId, ['recv_cart' => $refused, 'recv_mode' => 'requisition', 'recv_stock_source' => self::LOCATION_ID, 'recv_stock_destination' => $other]);

        $this->post('/receivings/requisitionComplete', $this->completeForm())->assertSee('RECV ');

        $receiving = $this->newestReceiving();

        $this->assertSame('requisition', $receiving->receiving_type);
        $this->assertSame($before + 1, $this->countRows('receivings'));
        $this->assertSame('6.000', $this->onHand($itemId));
        $this->assertSame('4.000', $this->onHand($itemId, $other));
    }

    public function testGetIndex_SaysNothingOfBranchesForARequisitionWithinOneBranch(): void
    {
        $buyerId = $this->buyer();
        $same    = $this->depotIn(1, $buyerId);

        $this->loginAs($buyerId, ['recv_mode' => 'requisition', 'recv_stock_source' => self::LOCATION_ID, 'recv_stock_destination' => $same]);

        $this->get('/receivings')->assertDontSee('id="between_branches_note"');
    }

    public function testPostRequisitionComplete_MovesStockFromOneBranchToAnotherForAnEmployeeOfBoth(): void
    {
        $buyerId = $this->buyer();
        ['item' => $itemId, 'branch' => $branchId, 'other' => $other] = $this->transferSetup($buyerId);

        $this->loginAs($buyerId, $this->cart([$this->cartLine($itemId, 1, '4', '0')], 'requisition', null) + ['recv_stock_destination' => $other]);

        $response = $this->post('/receivings/requisitionComplete', $this->completeForm());

        $response->assertStatus(200);
        $response->assertSee('RECV ');

        $receiving = $this->newestReceiving();

        $this->assertSame('requisition', $receiving->receiving_type);
        $this->assertSame([1, $branchId], [(int) $receiving->branch_id, (int) $receiving->to_branch_id]);
        $this->assertCount(1, $this->standingPurchase((int) $receiving->receiving_id, 'stock_transfer'));
        $this->assertCount(1, $this->standingPurchase((int) $receiving->receiving_id, 'stock_transfer_receipt'));
        $this->assertSame('6.000', $this->onHand($itemId));
        $this->assertSame('4.000', $this->onHand($itemId, $other));
    }

    public function testPostRequisitionComplete_RefusesAnEmployeeWhoWorksInOnlyOneOfTheTwoBranches(): void
    {
        $everyBranch = $this->buyer();
        ['item' => $itemId, 'branch' => $branchId, 'other' => $other] = $this->transferSetup($everyBranch);
        $before = $this->countRows('receivings');

        foreach ([[1], [$branchId]] as $works) {
            $buyerId = $this->buyer($works);
            $this->grantOnLocation($other, $buyerId);

            $this->loginAs($buyerId, $this->cart([$this->cartLine($itemId, 1, '4', '0')], 'requisition', null) + ['recv_stock_destination' => $other]);

            $this->post('/receivings/requisitionComplete', $this->completeForm())->assertSee(lang('Receivings.branch_not_allowed'));
            $this->assertSame($before, $this->countRows('receivings'));
            $this->assertSame('10.000', $this->onHand($itemId));
            $this->assertSame('0.000', $this->onHand($itemId, $other));
        }
    }

    public function testPostComplete_RefusesACartThatMixesThreeBranchesEvenWhenItMovesStock(): void
    {
        $buyerId  = $this->buyer();
        $second   = $this->depotIn($this->anotherBranch(), $buyerId);
        $third    = $this->depotIn($this->anotherBranch(), $buyerId);
        $itemId   = $this->item('0.00');

        $this->assertGreaterThan(0, $this->buy([$this->purchaseLine($itemId, 0, '10', '50')]));

        $before = $this->countRows('receivings');

        $this->loginAs($buyerId, $this->cart([
            $this->cartLine($itemId, 1, '2', '0', '0', $second),
            $this->cartLine($itemId, 2, '2', '0', '0', $third),
            $this->cartLine($itemId, 3, '-4', '0'),
        ], 'requisition', null));

        $this->post('/receivings/complete', $this->completeForm())->assertSee(lang('Receivings.mixed_branches'));
        $this->assertSame($before, $this->countRows('receivings'));
    }

    public function testGetReceipt_ShowsATransferBetweenBranchesToTheEmployeesOfEitherBranchAndHidesItFromOthers(): void
    {
        ['receiving' => $receivingId, 'branch' => $branchId] = $this->transferInTheBooks();

        foreach ([[1], [$branchId], [1, $branchId]] as $works) {
            $this->loginAs($this->buyer($works));

            $this->get('/receivings/receipt/' . $receivingId)->assertStatus(200);
        }

        $this->loginAs($this->buyer([$this->anotherBranch()]));

        $this->expectException(PageNotFoundException::class);

        $this->get('/receivings/receipt/' . $receivingId);
    }

    public function testGetEdit_HidesATransferBetweenBranchesFromAnEmployeeOfOnlyOneOfThem(): void
    {
        ['receiving' => $receivingId, 'branch' => $branchId] = $this->transferInTheBooks();

        foreach ([[1], [$branchId]] as $works) {
            $this->loginAs($this->buyer($works));

            try {
                $this->get('/receivings/edit/' . $receivingId);
                $this->fail('The edit form of a transfer was shown to an employee of only one of its branches.');
            } catch (PageNotFoundException) {
                $this->addToAssertionCount(1);
            }
        }

        $this->loginAs($this->buyer());

        $this->get('/receivings/edit/' . $receivingId)->assertStatus(200);
    }

    public function testPostSave_RefusesATransferBetweenBranchesToAnEmployeeOfOnlyOneOfThem(): void
    {
        ['receiving' => $receivingId, 'branch' => $branchId] = $this->transferInTheBooks();

        foreach ([[1], [$branchId]] as $works) {
            $this->loginAs($this->buyer($works));

            $this->post('/receivings/save/' . $receivingId, ['date' => $this->dateOf($receivingId), 'comment' => 'changed', 'supplier_id' => '', 'employee_id' => (string) $this->employeeId, 'reference' => 'X'])->assertStatus(403);
            $this->assertSame('test', $this->receivingRow($receivingId)->comment);
        }
    }

    public function testPostDelete_RefusesATransferBetweenBranchesToAnEmployeeOfOnlyOneOfThemAndLeavesBothLegs(): void
    {
        ['receiving' => $receivingId, 'item' => $itemId, 'branch' => $branchId, 'other' => $other] = $this->transferInTheBooks();

        foreach ([[1], [$branchId]] as $works) {
            $this->loginAs($this->buyer($works));

            $response = $this->post('/receivings/delete/' . $receivingId);

            $response->assertStatus(403);
            $this->assertSame(lang('Receivings.branch_not_allowed'), $this->jsonOf($response)['message']);
            $this->assertNotNull($this->receivingRow($receivingId));
            $this->assertCount(1, $this->standingPurchase($receivingId, 'stock_transfer'));
            $this->assertCount(1, $this->standingPurchase($receivingId, 'stock_transfer_receipt'));
            $this->assertSame('4.000', $this->onHand($itemId, $other));
        }
    }

    public function testPostDelete_TakesBackBothLegsOfATransferBetweenBranchesForAnEmployeeOfBoth(): void
    {
        ['receiving' => $receivingId, 'item' => $itemId, 'other' => $other] = $this->transferInTheBooks();

        $this->loginAs($this->buyer());

        $result = $this->jsonOf($this->post('/receivings/delete/' . $receivingId));

        $this->assertTrue($result['success']);
        $this->assertNull($this->receivingRow($receivingId));
        $this->assertSame([], $this->standingPurchase($receivingId, 'stock_transfer'));
        $this->assertSame([], $this->standingPurchase($receivingId, 'stock_transfer_receipt'));
        $this->assertSame('10.000', $this->onHand($itemId));
        $this->assertSame('0.000', $this->onHand($itemId, $other));
    }

    // ---------------------------------------------------------------------------------------
    // The receiving a return comes from
    // ---------------------------------------------------------------------------------------

    public function testPostSetReturnOrigin_KeepsAReceivingAReturnCanBeTakenFrom(): void
    {
        $itemId   = $this->item('0.00');
        $purchase = $this->buy([$this->purchaseLine($itemId, 0, '10', '50')]);
        $this->loginAs($this->buyer());

        $response = $this->post('/receivings/setReturnOrigin', ['recv_return_origin' => (string) $purchase]);

        $this->assertTrue($this->jsonOf($response)['success']);
        $this->assertSame($purchase, session()->get('recv_return_origin'));
    }

    public function testPostSetReturnOrigin_RefusesAReceivingThatDoesNotExistAndAReturn(): void
    {
        $itemId   = $this->item('0.00');
        $purchase = $this->buy([$this->purchaseLine($itemId, 0, '10', '50')]);
        $return   = $this->buy([$this->purchaseLine($itemId, 0, '-1', '50')], -1, null, $purchase);
        $this->loginAs($this->buyer());

        $none = $this->jsonOf($this->post('/receivings/setReturnOrigin', ['recv_return_origin' => '987654']));
        $back = $this->jsonOf($this->post('/receivings/setReturnOrigin', ['recv_return_origin' => (string) $return]));

        $this->assertFalse($none['success']);
        $this->assertSame(lang('Receivings.return_origin_invalid'), $none['message']);
        $this->assertFalse($back['success']);
    }

    public function testPostSetReturnOrigin_RefusesAReceivingOfABranchTheEmployeeDoesNotWorkIn(): void
    {
        $itemId   = $this->item('0.00');
        $purchase = $this->buy([$this->purchaseLine($itemId, 0, '10', '50')]);
        $this->loginAs($this->buyer([$this->anotherBranch()]));

        $result = $this->jsonOf($this->post('/receivings/setReturnOrigin', ['recv_return_origin' => (string) $purchase]));

        $this->assertFalse($result['success']);
        $this->assertSame(lang('Receivings.branch_not_allowed'), $result['message']);
    }

    public function testPostSetReturnOrigin_ClearsTheReceivingWithZero(): void
    {
        $this->loginAs($this->buyer(), ['recv_return_origin' => 5]);

        $this->post('/receivings/setReturnOrigin', ['recv_return_origin' => '0'])->assertStatus(200);

        $this->assertNull(session()->get('recv_return_origin'));
    }

    public function testPostChangeMode_RejectsADestinationTheEmployeeHoldsNoGrantOn(): void
    {
        $buyerId   = $this->buyer();
        $granted   = $this->depotIn(1, $buyerId);
        $ungranted = $this->locationOfBranch(1, 'No grant ' . uniqid());
        $itemId    = $this->item('0.00');
        $cart      = $this->cart([$this->cartLine($itemId, 1, '4', '0')], 'requisition', null);
        $this->loginAs($buyerId, $cart + ['recv_stock_destination' => $granted]);

        $this->post('/receivings/changeMode', ['mode' => 'requisition', 'stock_source' => (string) self::LOCATION_ID, 'stock_destination' => (string) $ungranted])->assertSee(lang('Receivings.location_not_allowed'));

        $this->assertSame((string) $granted, (string) session()->get('recv_stock_destination'));
        $this->assertSame($cart['recv_cart'], session()->get('recv_cart'));
    }

    public function testPostChangeMode_AcceptsADestinationTheEmployeeHoldsAGrantOn(): void
    {
        $buyerId = $this->buyer();
        $granted = $this->depotIn(1, $buyerId);
        $this->loginAs($buyerId, ['recv_mode' => 'requisition', 'recv_stock_source' => self::LOCATION_ID]);

        $response = $this->post('/receivings/changeMode', ['mode' => 'requisition', 'stock_source' => (string) self::LOCATION_ID, 'stock_destination' => (string) $granted]);

        $response->assertDontSee(lang('Receivings.location_not_allowed'));
        $this->assertSame((string) $granted, (string) session()->get('recv_stock_destination'));
    }

    public function testPostChangeMode_RejectsADestinationInABranchTheEmployeeDoesNotWorkIn(): void
    {
        $everyBranch = $this->buyer();
        $branchId    = $this->anotherBranch();
        $other       = $this->depotIn($branchId, $everyBranch);
        $own         = $this->depotIn(1, $everyBranch);
        $limited     = $this->buyer([1]);

        $this->grantOnLocation($other, $limited);
        $this->grantOnLocation($own, $limited);

        $this->loginAs($limited, ['recv_mode' => 'requisition', 'recv_stock_source' => self::LOCATION_ID, 'recv_stock_destination' => $own]);

        $this->post('/receivings/changeMode', ['mode' => 'requisition', 'stock_source' => (string) self::LOCATION_ID, 'stock_destination' => (string) $other])->assertSee(lang('Receivings.location_not_allowed'));

        $this->assertSame((string) $own, (string) session()->get('recv_stock_destination'));
    }

    public function testPostChangeMode_KeepsOnlyTheNumberOfTheDestination(): void
    {
        $buyerId = $this->buyer();
        $granted = $this->depotIn(1, $buyerId);
        $this->loginAs($buyerId, ['recv_mode' => 'requisition', 'recv_stock_source' => self::LOCATION_ID]);

        $this->post('/receivings/changeMode', ['mode' => 'requisition', 'stock_source' => (string) self::LOCATION_ID, 'stock_destination' => $granted . 'abc'])->assertStatus(200);

        $this->assertSame((string) $granted, (string) session()->get('recv_stock_destination'));
    }

    public function testPostRequisitionComplete_RefusesADestinationTheEmployeeNoLongerHoldsAGrantOn(): void
    {
        $buyerId   = $this->buyer();
        $itemId    = $this->item('0.00');
        $ungranted = $this->locationOfBranch(1, 'No grant ' . uniqid());
        $cart      = $this->cart([$this->cartLine($itemId, 1, '4', '0')], 'requisition', null);
        $this->loginAs($buyerId, $cart + ['recv_stock_destination' => $ungranted]);
        $before = $this->countRows('receivings');

        $this->post('/receivings/requisitionComplete', $this->completeForm())->assertSee(lang('Receivings.location_not_allowed'));

        $this->assertSame($before, $this->countRows('receivings'));
        $this->assertSame($cart['recv_cart'], session()->get('recv_cart'));
        $this->assertSame('0.000', $this->onHand($itemId, $ungranted));
    }

    public function testPostChangeMode_ForgetsTheReceivingAReturnCameFromWhenTheModeLeavesReturn(): void
    {
        $this->loginAs($this->buyer(), ['recv_mode' => 'return', 'recv_return_origin' => 5]);

        $this->post('/receivings/changeMode', ['mode' => 'receive'])->assertStatus(200);

        $this->assertNull(session()->get('recv_return_origin'));
    }

    // ---------------------------------------------------------------------------------------
    // A receiving that is in the books: who may touch it, and what is locked
    // ---------------------------------------------------------------------------------------

    public function testGetReceipt_HidesAReceivingOfABranchTheEmployeeDoesNotWorkIn(): void
    {
        $itemId      = $this->item('0.00');
        $receivingId = $this->buy([$this->purchaseLine($itemId, 0, '10', '50')]);
        $this->loginAs($this->buyer([$this->anotherBranch()]));

        $this->expectException(PageNotFoundException::class);

        $this->get('/receivings/receipt/' . $receivingId);
    }

    public function testGetReceipt_ShowsAReceivingOfTheBranchTheEmployeeWorksIn(): void
    {
        $itemId      = $this->item('0.00');
        $receivingId = $this->buy([$this->purchaseLine($itemId, 0, '10', '50', '15')]);
        $this->loginAs($this->buyer([1]));

        $response = $this->get('/receivings/receipt/' . $receivingId);

        $response->assertStatus(200);
        $response->assertSee(lang('Receivings.tax_total'));
    }

    public function testGetEdit_HidesAReceivingOfABranchTheEmployeeDoesNotWorkIn(): void
    {
        $itemId      = $this->item('0.00');
        $receivingId = $this->buy([$this->purchaseLine($itemId, 0, '10', '50')]);
        $this->loginAs($this->buyer([$this->anotherBranch()]));

        $this->expectException(PageNotFoundException::class);

        $this->get('/receivings/edit/' . $receivingId);
    }

    public function testGetEdit_SaysThatAPostedReceivingKeepsItsSupplierAndShowsItsJournals(): void
    {
        $itemId      = $this->item('0.00');
        $receivingId = $this->buy([$this->purchaseLine($itemId, 0, '10', '50')]);
        $this->loginAs($this->buyer());

        $response = $this->get('/receivings/edit/' . $receivingId);

        $response->assertStatus(200);
        $response->assertSee('id="posted_lock"', );
        $response->assertSee('id="receiving_journals"');
    }

    public function testGetEdit_DoesNotShowTheJournalsToSomeoneWithoutTheJournalsModule(): void
    {
        $itemId      = $this->item('0.00');
        $receivingId = $this->buy([$this->purchaseLine($itemId, 0, '10', '50')]);
        $this->loginAs($this->buyer(null, ['items']));

        $this->get('/receivings/edit/' . $receivingId)->assertDontSee('id="receiving_journals"');
    }

    public function testGetEdit_LeavesAReceivingThatWasNeverPostedOpen(): void
    {
        $this->setConfigValue('accounting_enabled', '0');
        $itemId      = $this->item('0.00');
        $receivingId = $this->buy([$this->purchaseLine($itemId, 0, '10', '50')]);
        $this->loginAs($this->buyer());

        $this->get('/receivings/edit/' . $receivingId)->assertDontSee('id="posted_lock"');
    }

    public function testPostSave_RefusesAReceivingOfAnotherBranchAndChangesNothing(): void
    {
        $itemId      = $this->item('0.00');
        $receivingId = $this->buy([$this->purchaseLine($itemId, 0, '10', '50')]);
        $this->loginAs($this->buyer([$this->anotherBranch()]));

        $response = $this->post('/receivings/save/' . $receivingId, ['date' => date($this->dateFormat()), 'comment' => 'changed', 'supplier_id' => $this->supplierId, 'employee_id' => $this->employeeId, 'reference' => 'X']);

        $response->assertStatus(403);
        $this->assertSame('test', $this->receivingRow($receivingId)->comment);
    }

    public function testPostSave_RefusesToChangeTheSupplierOfAPostedReceiving(): void
    {
        $itemId      = $this->item('0.00');
        $receivingId = $this->buy([$this->purchaseLine($itemId, 0, '10', '50')]);
        $other       = $this->supplier('Another supplier');
        $this->loginAs($this->buyer());

        $result = $this->jsonOf($this->post('/receivings/save/' . $receivingId, ['date' => $this->dateOf($receivingId), 'comment' => 'changed', 'supplier_id' => (string) $other, 'employee_id' => (string) $this->employeeId, 'reference' => 'X']));

        $this->assertFalse($result['success']);
        $this->assertSame(lang('Accounting.error_purchase_locked'), $result['message']);
        $this->assertSame((string) $this->supplierId, (string) $this->receivingRow($receivingId)->supplier_id);
        $this->assertSame('test', $this->receivingRow($receivingId)->comment);
    }

    public function testPostSave_RefusesToChangeTheDateOfAPostedReceiving(): void
    {
        $itemId      = $this->item('0.00');
        $receivingId = $this->buy([$this->purchaseLine($itemId, 0, '10', '50')]);
        $this->loginAs($this->buyer());

        $result = $this->jsonOf($this->post('/receivings/save/' . $receivingId, ['date' => date($this->dateFormat(), strtotime('-3 days')), 'comment' => 'changed', 'supplier_id' => (string) $this->supplierId, 'employee_id' => (string) $this->employeeId, 'reference' => 'X']));

        $this->assertFalse($result['success']);
        $this->assertSame(lang('Accounting.error_purchase_locked'), $result['message']);
    }

    public function testPostSave_StillTakesTheCommentAndTheReferenceOfAPostedReceiving(): void
    {
        $itemId      = $this->item('0.00');
        $receivingId = $this->buy([$this->purchaseLine($itemId, 0, '10', '50')]);
        $this->loginAs($this->buyer());

        $result = $this->jsonOf($this->post('/receivings/save/' . $receivingId, ['date' => $this->dateOf($receivingId), 'comment' => 'changed', 'supplier_id' => (string) $this->supplierId, 'employee_id' => (string) $this->employeeId, 'reference' => 'X']));

        $this->assertTrue($result['success']);
        $this->assertSame('changed', $this->receivingRow($receivingId)->comment);
        $this->assertSame('X', $this->receivingRow($receivingId)->reference);
    }

    public function testPostDelete_RefusesAReceivingOfAnotherBranchAndLeavesItsJournalsAndStock(): void
    {
        $itemId      = $this->item('0.00');
        $receivingId = $this->buy([$this->purchaseLine($itemId, 0, '10', '50')]);
        $this->loginAs($this->buyer([$this->anotherBranch()]));

        $response = $this->post('/receivings/delete/' . $receivingId);

        $response->assertStatus(403);
        $this->assertSame(lang('Receivings.branch_not_allowed'), $this->jsonOf($response)['message']);
        $this->assertNotNull($this->receivingRow($receivingId));
        $this->assertSame('10.000', $this->onHand($itemId));
        $this->assertCount(1, $this->standingPurchase($receivingId));
    }

    public function testPostDelete_DeletesAPostedReceivingAndTakesItsJournalsBack(): void
    {
        $itemId      = $this->item('0.00');
        $receivingId = $this->buy([$this->purchaseLine($itemId, 0, '10', '50')]);
        $this->loginAs($this->buyer());

        $result = $this->jsonOf($this->post('/receivings/delete/' . $receivingId));

        $this->assertTrue($result['success']);
        $this->assertNull($this->receivingRow($receivingId));
        $this->assertSame('0.000', $this->onHand($itemId));
        $this->assertSame([], $this->standingPurchase($receivingId));
        $this->assertSame('0.00', $this->balance($this->supplierAccount()));
    }

    public function testPostDelete_SaysWhyAReceivingThatGoodsWentBackFromCannotBeDeleted(): void
    {
        $itemId   = $this->item('0.00');
        $purchase = $this->buy([$this->purchaseLine($itemId, 0, '10', '50')]);
        $this->buy([$this->purchaseLine($itemId, 0, '-2', '50')], -1, null, $purchase);
        $this->loginAs($this->buyer());

        $result = $this->jsonOf($this->post('/receivings/delete/' . $purchase));

        $this->assertFalse($result['success']);
        $this->assertStringContainsString(lang('Accounting.error_purchase_has_returns'), $result['message']);
        $this->assertNotNull($this->receivingRow($purchase));
        $this->assertSame('8.000', $this->onHand($itemId));
    }

    /**
     * What the browser posts when the receiving is completed: every field of the form, the ones the
     * person left empty included.
     *
     * @return array<string, string>
     */
    private function completeForm(?string $paymentType = null, string $amountTendered = ''): array
    {
        return ['comment' => '', 'recv_reference' => '', 'payment_type' => $paymentType ?? lang('Sales.due'), 'amount_tendered' => $amountTendered];
    }

    private function dateFormat(): string
    {
        $settings = config(OSPOS::class)->settings;

        return ($settings['dateformat'] ?? 'm/d/Y') . ' ' . ($settings['timeformat'] ?? 'H:i:s');
    }

    private function dateOf(int $receivingId): string
    {
        return date($this->dateFormat(), strtotime((string) $this->receivingRow($receivingId)->receiving_time));
    }

    // ---------------------------------------------------------------------------------------
    // What is owed to the supplier: payments, figures and the debit note
    // ---------------------------------------------------------------------------------------

    /**
     * What the edit form posts for payments: the ones the purchase has and, when a type and an
     * amount are given, a new one.
     *
     * @param list<array{int, string, string, string}> $existing id, type, amount and status of the payments already there
     *
     * @return array<string, string>
     */
    private function paymentFields(array $existing = [], ?string $type = null, string $amount = ''): array
    {
        $fields = ['number_of_payments' => (string) count($existing), 'payment_type_new' => $type ?? PAYMENT_TYPE_UNASSIGNED, 'payment_amount_new' => $amount];

        foreach ($existing as $i => [$id, $paymentType, $paymentAmount, $status]) {
            $fields["payment_id_{$i}"]     = (string) $id;
            $fields["payment_type_{$i}"]   = $paymentType;
            $fields["payment_amount_{$i}"] = $paymentAmount;
            $fields["payment_status_{$i}"] = $status;
        }

        return $fields;
    }

    /**
     * @param array<string, string> $payments
     *
     * @return array<string, string>
     */
    private function savedWith(int $receivingId, array $payments, string $comment = 'test'): array
    {
        return ['date' => $this->dateOf($receivingId), 'comment' => $comment, 'supplier_id' => (string) $this->supplierId, 'employee_id' => (string) $this->employeeId, 'reference' => 'X'] + $payments;
    }

    public function testGetEdit_ShowsThePaymentsTheFiguresAndTheDebitNoteButtonOfAPostedPurchaseOfASupplier(): void
    {
        $purchase = $this->purchaseOf('500.00');
        $this->payPurchase($purchase, '100.00');
        $this->loginAs($this->buyer());

        $response = $this->get('/receivings/edit/' . $purchase);

        $response->assertStatus(200);
        $response->assertSee('id="new_supplier_payment"');
        $response->assertSee('name="payment_id_0"');
        $response->assertSee('name="payment_status_0"');
        $response->assertDontSee('name="payment_id_1"');
        $response->assertSee('name="number_of_payments" value="1"');
        $response->assertSee('id="payable_figures"');
        $response->assertSee(lang('Receivings.payable_open'));
        $response->assertSee('400.00');
        $response->assertSee('id="create_debit_note"');
    }

    public function testGetEdit_OffersNoPaymentsForADebitNoteOrAPurchaseWithoutASupplier(): void
    {
        $purchase = $this->purchaseOf('500.00');
        $note     = $this->debitNoteOf($purchase, '50.00');
        $item     = $this->item('0.00');
        $cash     = $this->buy([$this->purchaseLine($item, 0, '1', '40.00')], null, lang('Sales.cash'));
        $this->loginAs($this->buyer());

        foreach ([$note, $cash] as $receivingId) {
            $response = $this->get('/receivings/edit/' . $receivingId);

            $response->assertStatus(200);
            $response->assertDontSee('id="new_supplier_payment"');
            $response->assertDontSee('id="payable_figures"');
            $response->assertDontSee('id="create_debit_note"');
        }
    }

    public function testGetEdit_HidesTheDebitNoteButtonWhenEverythingWasReturned(): void
    {
        $purchase = $this->purchaseOf('100.00');
        $this->debitNoteOf($purchase, '100.00');
        $this->loginAs($this->buyer());

        $response = $this->get('/receivings/edit/' . $purchase);

        $response->assertSee('id="payable_figures"');
        $response->assertDontSee('id="create_debit_note"');
    }

    public function testGetEdit_ListsWhatWasAppliedToThePurchaseAndWhatTheDebitNoteGave(): void
    {
        $paid = $this->purchaseOf('100.00', null, lang('Sales.cash'));
        $note = $this->debitNoteOf($paid, '100.00');
        $next = $this->purchaseOf('60.00');
        $this->loginAs($this->buyer());

        $response = $this->get('/receivings/edit/' . $next);

        $response->assertSee('id="credit_applications"');
        $response->assertSee(lang('Receivings.credit_from_note'));
        $response->assertSee('#' . $note);

        $this->get('/receivings/edit/' . $note)->assertSee('id="credit_applications"');
    }

    public function testPostSave_AddsAPaymentFromTheFormAndPostsIt(): void
    {
        $purchase = $this->purchaseOf('500.00');
        $this->loginAs($this->buyer());

        $result = $this->jsonOf($this->post('/receivings/save/' . $purchase, $this->savedWith($purchase, $this->paymentFields([], lang('Sales.cash'), '150.00'))));

        $this->assertTrue($result['success']);
        $this->assertSame(1, $this->countRows('receivings_payments'));
        $this->assertSame('350.00', $this->owed());
        $this->assertSame(1, $this->accountingDb()->table('accounting_journals')->where('source_type', 'supplier_payment')->countAllResults());
        $this->assertSupplierTies();
    }

    public function testPostSave_ChangesAndThenRemovesAPaymentFromTheForm(): void
    {
        $purchase = $this->purchaseOf('500.00');
        $payment  = $this->payPurchase($purchase, '100.00');
        $this->loginAs($this->buyer());

        $changed = $this->jsonOf($this->post('/receivings/save/' . $purchase, $this->savedWith($purchase, $this->paymentFields([[$payment, lang('Sales.cash'), '175.00', 'completed']]))));

        $this->assertTrue($changed['success']);
        $this->assertSame('325.00', $this->owed());

        $removed = $this->jsonOf($this->post('/receivings/save/' . $purchase, $this->savedWith($purchase, $this->paymentFields([[$payment, lang('Sales.cash'), '0', 'completed']]))));

        $this->assertTrue($removed['success']);
        $this->assertSame(0, $this->countRows('receivings_payments'));
        $this->assertSame('500.00', $this->owed());
        $this->assertSupplierTies();
    }

    public function testPostSave_KeepsAPaymentThatIsNotCompletedOutOfTheBooksUntilItIs(): void
    {
        $purchase = $this->purchaseOf('500.00');
        $payment  = $this->payPurchase($purchase, '100.00', null, 'draft');
        $this->loginAs($this->buyer());

        $this->assertSame('500.00', $this->owed());

        $result = $this->jsonOf($this->post('/receivings/save/' . $purchase, $this->savedWith($purchase, $this->paymentFields([[$payment, lang('Sales.cash'), '100.00', 'completed']]))));

        $this->assertTrue($result['success']);
        $this->assertSame('400.00', $this->owed());
    }

    public function testPostSave_SaysWhyAClosedPeriodRefusesAPaymentAndSavesNothingOfTheForm(): void
    {
        $purchase = $this->purchaseOf('500.00');
        $this->loginAs($this->buyer());
        $this->closeThrough(date('Y-m-d'));

        $result = $this->jsonOf($this->post('/receivings/save/' . $purchase, $this->savedWith($purchase, $this->paymentFields([], lang('Sales.cash'), '150.00'), 'with a payment')));

        $this->assertFalse($result['success']);
        $this->assertStringContainsString(date('Y-m-d'), $result['message']);
        $this->assertSame(0, $this->countRows('receivings_payments'));
        $this->assertSame('test', $this->receivingRow($purchase)->comment);
    }

    public function testPostSave_RefusesAPaymentOnAPurchaseOfABranchTheEmployeeDoesNotWorkIn(): void
    {
        $purchase = $this->purchaseOf('500.00');
        $this->loginAs($this->buyer([$this->anotherBranch()]));

        $response = $this->post('/receivings/save/' . $purchase, $this->savedWith($purchase, $this->paymentFields([], lang('Sales.cash'), '150.00')));

        $response->assertStatus(403);
        $this->assertSame(0, $this->countRows('receivings_payments'));
        $this->assertSame('500.00', $this->owed());
    }

    public function testPostSave_RefusesAPaymentTypeThatIsNotOneASupplierIsPaidWith(): void
    {
        $purchase = $this->purchaseOf('500.00');
        $this->loginAs($this->buyer());

        $result = $this->jsonOf($this->post('/receivings/save/' . $purchase, $this->savedWith($purchase, $this->paymentFields([], lang('Sales.giftcard'), '150.00'))));

        $this->assertFalse($result['success']);
        $this->assertSame(0, $this->countRows('receivings_payments'));
    }

    private function treasuryOfBranch(string $name, int $branchId = 1): int
    {
        return (new TreasuryService())->create(['name' => $name, 'kind' => 'cash', 'branch_id' => $branchId]);
    }

    public function testGetEdit_OffersTheTreasuriesOfTheBranchWhenThereIsAChoice(): void
    {
        $purchase = $this->purchaseOf('500.00');
        $this->payPurchase($purchase, '100.00', 'Cash');
        $this->treasuryOfBranch('Second drawer');
        $this->loginAs($this->buyer());

        $response = $this->get('/receivings/edit/' . $purchase);

        $response->assertStatus(200);
        $response->assertSee('name="treasury_id_0"');
        $response->assertSee('name="treasury_id_new"');
        $response->assertSee('Second drawer');
        $response->assertSee(lang('Receivings.treasury_default'));
    }

    public function testGetEdit_LeavesOutTheTreasuriesTheEditorMayNotWithdrawFromButKeepsTheOneAPaymentAlreadyCarries(): void
    {
        $purchase = $this->purchaseOf('500.00');
        $this->payPurchase($purchase, '100.00', 'Cash');
        $this->treasuryOfBranch('Open drawer');

        $locked = (new TreasuryService())->create(['name' => 'Locked drawer', 'kind' => 'cash', 'withdraw_scope' => 'staff', 'withdraw_members' => [$this->employeeId]]);

        $this->loginAs($this->buyer());

        $response = $this->get('/receivings/edit/' . $purchase);

        $response->assertStatus(200);
        $response->assertSee('Open drawer');
        $response->assertDontSee('Locked drawer');

        $this->accountingDb()->table('receivings_payments')->where('receiving_id', $purchase)->update(['treasury_id' => $locked]);

        $this->get('/receivings/edit/' . $purchase)->assertSee('Locked drawer');
    }

    public function testGetEdit_OffersNoTreasuryWhileTheBranchHasOnlyTheMainOne(): void
    {
        $purchase = $this->purchaseOf('500.00');
        $this->payPurchase($purchase, '100.00', 'Cash');
        $this->loginAs($this->buyer());

        $response = $this->get('/receivings/edit/' . $purchase);

        $response->assertStatus(200);
        $response->assertDontSee('name="treasury_id_0"');
        $response->assertDontSee('name="treasury_id_new"');
    }

    public function testGetEdit_KeepsTheTreasuryOfAnotherBranchAPaymentAlreadyCarriesSoTheFormSendsItBack(): void
    {
        $purchase = $this->purchaseOf('500.00');
        $this->treasuryOfBranch('Open drawer');
        $this->accountingDb()->table('branches')->insert(['company_id' => 1, 'code' => 'T' . random_int(100, 999), 'name' => 'Test branch', 'active' => 1]);
        $second    = (int) $this->accountingDb()->insertID();
        $elsewhere = $this->treasuryOfBranch('Main of another branch', $second);
        $payment   = $this->payPurchase($purchase, '100.00', 'Cash');
        $this->accountingDb()->table('receivings_payments')->where('payment_id', $payment)->update(['treasury_id' => $elsewhere]);
        $this->loginAs($this->buyer());

        $response = $this->get('/receivings/edit/' . $purchase);

        $this->assertMatchesRegularExpression('/<option value="' . $elsewhere . '"\s+selected>Main of another branch \([^)]+\)<\/option>/', $response->getBody(), 'It is named with the code of its branch');
    }

    public function testGetEdit_KeepsTheRetiredTreasuryAPaymentAlreadyCarriesButNotForANewPayment(): void
    {
        $purchase = $this->purchaseOf('500.00');
        $retired  = $this->treasuryOfBranch('Retired drawer');
        $this->treasuryOfBranch('Open drawer');
        $payment = $this->payPurchase($purchase, '100.00', 'Cash');
        $this->accountingDb()->table('receivings_payments')->where('payment_id', $payment)->update(['treasury_id' => $retired]);
        (new TreasuryService())->deactivate($retired);
        $this->loginAs($this->buyer());

        $response = $this->get('/receivings/edit/' . $purchase);

        $this->assertMatchesRegularExpression('/<option value="' . $retired . '"\s+selected>Retired drawer<\/option>/', $response->getBody());
        $this->assertSame(1, substr_count($response->getBody(), 'Retired drawer'));
    }

    public function testPostSave_PutsTheNewPaymentIntoTheTreasuryTheFormNames(): void
    {
        $purchase = $this->purchaseOf('500.00');
        $drawer   = $this->treasuryOfBranch('Second drawer');
        $this->loginAs($this->buyer());

        $result = $this->jsonOf($this->post('/receivings/save/' . $purchase, $this->savedWith($purchase, $this->paymentFields([], 'Cash', '150.00') + ['treasury_id_new' => (string) $drawer])));

        $this->assertTrue($result['success']);
        $this->assertSame($drawer, (int) $this->accountingDb()->table('receivings_payments')->where('receiving_id', $purchase)->get()->getRow()->treasury_id);
        $this->assertSame('-150.0000', (new TreasuryService())->position($drawer)->balance);
        $this->assertSame('350.00', $this->owed());
    }

    public function testPostSave_MovesAPaymentToTheTreasuryTheFormNames(): void
    {
        $purchase = $this->purchaseOf('500.00');
        $payment  = $this->payPurchase($purchase, '100.00', 'Cash');
        $drawer   = $this->treasuryOfBranch('Second drawer');
        $this->loginAs($this->buyer());

        $fields = $this->paymentFields([[$payment, 'Cash', '100.00', 'completed']]) + ['treasury_id_0' => (string) $drawer];
        $result = $this->jsonOf($this->post('/receivings/save/' . $purchase, $this->savedWith($purchase, $fields)));

        $this->assertTrue($result['success']);
        $this->assertSame($drawer, (int) $this->accountingDb()->table('receivings_payments')->where('payment_id', $payment)->get()->getRow()->treasury_id);
        $this->assertSame('-100.0000', (new TreasuryService())->position($drawer)->balance);
        $this->assertSame('0.00', $this->balance($this->accountId('cash')));
        $this->assertSupplierTies();
    }

    public function testPostSave_KeepsTheTreasuryOfAPaymentTheFormDoesNotName(): void
    {
        $purchase = $this->purchaseOf('500.00');
        $drawer   = $this->treasuryOfBranch('Second drawer');
        $payment  = $this->payPurchase($purchase, '100.00', 'Cash');
        $this->accountingDb()->table('receivings_payments')->where('payment_id', $payment)->update(['treasury_id' => $drawer]);
        $this->loginAs($this->buyer());

        $result = $this->jsonOf($this->post('/receivings/save/' . $purchase, $this->savedWith($purchase, $this->paymentFields([[$payment, 'Cash', '100.00', 'completed']]))));

        $this->assertTrue($result['success']);
        $this->assertSame($drawer, (int) $this->accountingDb()->table('receivings_payments')->where('payment_id', $payment)->get()->getRow()->treasury_id);
    }

    public function testPostSave_RejectsATreasuryOfAnotherBranchToABuyerWhoDoesNotWorkInItAndSavesNothingOfTheForm(): void
    {
        $purchase  = $this->purchaseOf('500.00');
        $elsewhere = $this->treasuryOfBranch('Far drawer', $this->anotherBranch());
        $this->loginAs($this->buyer([1]));

        $result = $this->jsonOf($this->post('/receivings/save/' . $purchase, $this->savedWith($purchase, $this->paymentFields([], 'Cash', '150.00') + ['treasury_id_new' => (string) $elsewhere], 'with a payment')));

        $this->assertFalse($result['success']);
        $this->assertSame(0, $this->countRows('receivings_payments'));
        $this->assertSame('test', $this->receivingRow($purchase)->comment);
        $this->assertSame('500.00', $this->owed());
    }

    public function testGetEdit_OffersTheTreasuriesOfTheOtherBranchesToABuyerWhoWorksInBothWithTheirCode(): void
    {
        $purchase = $this->purchaseOf('500.00');
        $this->payPurchase($purchase, '100.00', 'Cash');
        $this->treasuryOfBranch('Second drawer');
        $branch = $this->anotherBranch();
        $far    = $this->treasuryOfBranch('Far drawer', $branch);

        $this->loginAs($this->buyer([1, $branch]));

        $this->assertMatchesRegularExpression('/<option value="' . $far . '">Far drawer \([^)]+\)<\/option>/', $this->get('/receivings/edit/' . $purchase)->getBody());

        $this->loginAs($this->buyer([1]));

        $this->assertStringNotContainsString('Far drawer', $this->get('/receivings/edit/' . $purchase)->getBody());
    }

    public function testPostSave_PaysTheSupplierOutOfATreasuryOfAnotherBranchForABuyerWhoWorksInBoth(): void
    {
        $purchase = $this->purchaseOf('500.00');
        $branch   = $this->anotherBranch();
        $far      = $this->treasuryOfBranch('Far drawer', $branch);
        $this->loginAs($this->buyer([1, $branch]));

        $result = $this->jsonOf($this->post('/receivings/save/' . $purchase, $this->savedWith($purchase, $this->paymentFields([], 'Cash', '150.00') + ['treasury_id_new' => (string) $far], 'with a payment')));

        $payment = $this->accountingDb()->table('receivings_payments')->where('receiving_id', $purchase)->get()->getRow();

        $this->assertTrue($result['success']);
        $this->assertSame([$far, $branch], [(int) $payment->treasury_id, (int) $payment->treasury_branch_id]);
        $this->assertSame('-150.0000', (new TreasuryService())->position($far)->balance);
        $this->assertSame('350.00', $this->owed());
    }

    public function testPostDebitNote_PreparesTheRegisterForAReturnOfWhatIsLeftToTakeBack(): void
    {
        $item     = $this->item('0.00');
        $purchase = $this->buy([$this->purchaseLine($item, 0, '10', '50')]);
        $this->buy([$this->purchaseLine($item, 0, '-3', '50')], -1, null, $purchase);
        $this->loginAs($this->buyer());

        $response = $this->post('/receivings/debitNote/' . $purchase);
        $result   = $this->jsonOf($response);

        $this->assertTrue($result['success']);
        $this->assertSame(site_url('receivings'), $result['redirect']);
        $this->assertSame('return', session()->get('recv_mode'));
        $this->assertSame($purchase, session()->get('recv_return_origin'));
        $this->assertSame((int) $this->supplierId, (int) session()->get('recv_supplier'));

        $cart = session()->get('recv_cart');

        $this->assertCount(1, $cart);
        $this->assertSame([$item, -7.0], [(int) reset($cart)['item_id'], (float) reset($cart)['quantity']]);
    }

    public function testPostDebitNote_TakesTheShareOfAWholeLineDiscountThatIsLeftToTakeBack(): void
    {
        $item     = $this->item('0.00');
        $purchase = $this->buy([$this->purchaseLine($item, 0, '10', '50', '0', '100', FIXED_LINE)]);
        $this->buy([$this->purchaseLine($item, 0, '-3', '50')], -1, null, $purchase);
        $this->loginAs($this->buyer());

        $this->assertTrue($this->jsonOf($this->post('/receivings/debitNote/' . $purchase))['success']);

        $cart = session()->get('recv_cart');
        $line = reset($cart);

        $this->assertSame([-7.0, FIXED_LINE, 70.0], [(float) $line['quantity'], (int) $line['discount_type'], (float) $line['discount']]);
        $this->assertSame('-280.0000', bcadd((string) $line['total'], '0', 4));
    }

    public function testGetReceipt_ShowsTheFixedDiscountOfALineSavedForTheWholeLine(): void
    {
        $item     = $this->item('0.00');
        $purchase = $this->buy([$this->purchaseLine($item, 0, '10', '50', '0', '100', FIXED_LINE)]);
        $this->loginAs($this->buyer());

        $response = $this->get('/receivings/receipt/' . $purchase);

        $response->assertStatus(200);
        $response->assertSee('class="discount"');
        $response->assertSee(lang('Sales.discount'));
    }

    public function testPostDebitNote_SaysThatEverythingWasReturnedAndLeavesTheRegisterAlone(): void
    {
        $item     = $this->item('0.00');
        $purchase = $this->buy([$this->purchaseLine($item, 0, '4', '50')]);
        $this->buy([$this->purchaseLine($item, 0, '-4', '50')], -1, null, $purchase);
        $this->loginAs($this->buyer(), ['recv_cart' => []]);

        $result = $this->jsonOf($this->post('/receivings/debitNote/' . $purchase));

        $this->assertFalse($result['success']);
        $this->assertSame(lang('Receivings.debit_note_nothing_left'), $result['message']);
        $this->assertNull(session()->get('recv_return_origin'));
    }

    public function testPostDebitNote_RefusesAPurchaseOfABranchTheEmployeeDoesNotWorkInAndAReturn(): void
    {
        $purchase = $this->purchaseOf('100.00');
        $note     = $this->debitNoteOf($purchase, '10.00');

        $this->loginAs($this->buyer([$this->anotherBranch()]));

        $this->post('/receivings/debitNote/' . $purchase)->assertStatus(403);
        $this->assertNull(session()->get('recv_return_origin'));

        $this->loginAs($this->buyer());

        $result = $this->jsonOf($this->post('/receivings/debitNote/' . $note));

        $this->assertFalse($result['success']);
        $this->assertSame(lang('Receivings.return_origin_invalid'), $result['message']);
    }

    public function testPostDebitNote_NeedsAPurchaseWithASupplier(): void
    {
        $item     = $this->item('0.00');
        $purchase = $this->buy([$this->purchaseLine($item, 0, '1', '40.00')], null, lang('Sales.cash'));
        $this->loginAs($this->buyer());

        $result = $this->jsonOf($this->post('/receivings/debitNote/' . $purchase));

        $this->assertFalse($result['success']);
        $this->assertSame(lang('Receivings.debit_note_needs_supplier'), $result['message']);
    }

    private function switchOn(DocumentType $type, string $prefix, int $padding = 3): void
    {
        (new DocumentNumbering())->configure($type, ['enabled' => true, 'prefix' => $prefix, 'padding' => $padding, 'next_number' => 1]);
    }

    public function testPostComplete_ShowsTheNumberOfThePurchaseOnTheReceipt(): void
    {
        $this->switchOn(DocumentType::Purchase, 'PUR-');
        $itemId = $this->item('0.00');
        $this->loginAs($this->buyer(), $this->cart([$this->cartLine($itemId, 1, '20', '100', '15')]));

        $response = $this->post('/receivings/complete', $this->completeForm('Due'));

        $response->assertSee('id="document_number"');
        $response->assertSee('PUR-001');

        $this->receivingIds[] = (int) $this->accountingDb()->table('receivings')->selectMax('receiving_id', 'last')->get()->getRow()->last;
    }

    public function testPostComplete_ShowsNoNumberLineWhileTheSequencesAreOff(): void
    {
        $itemId = $this->item('0.00');
        $this->loginAs($this->buyer(), $this->cart([$this->cartLine($itemId, 1, '20', '100', '15')]));

        $response = $this->post('/receivings/complete', $this->completeForm('Due'));

        $response->assertSee('RECV ');
        $response->assertDontSee('id="document_number"');

        $this->receivingIds[] = (int) $this->accountingDb()->table('receivings')->selectMax('receiving_id', 'last')->get()->getRow()->last;
    }

    public function testGetReceipt_ShowsTheNumberOfAPurchaseAndOfAReturnToTheSupplier(): void
    {
        $this->switchOn(DocumentType::Purchase, 'PUR-');
        $this->switchOn(DocumentType::DebitNote, 'DN-');
        $purchase = $this->purchaseOf('100.00');
        $note     = $this->debitNoteOf($purchase, '10.00');
        $this->loginAs($this->buyer());

        $this->get('/receivings/receipt/' . $purchase)->assertSee('PUR-001');
        $response = $this->get('/receivings/receipt/' . $note);

        $response->assertSee('DN-001');
        $response->assertSee(lang('Numbering.type_debit_note'));
    }

    public function testGetEdit_ShowsTheNumberOfThePurchaseAndOfItsPayments(): void
    {
        $this->switchOn(DocumentType::Purchase, 'PUR-');
        $this->switchOn(DocumentType::SupplierPayment, 'SP-');
        $purchase  = $this->purchaseOf('100.00');
        $paymentId = $this->payPurchase($purchase, '20.00');
        $this->loginAs($this->buyer());

        $response = $this->get('/receivings/edit/' . $purchase);

        $response->assertSee('id="document_number"');
        $response->assertSee('PUR-001');
        $response->assertSee('#SP-001');
        $response->assertDontSee('#' . $paymentId . '<');
    }

    public function testGetEdit_ShowsThePaymentsByTheirIdWhileTheSequencesAreOff(): void
    {
        $purchase  = $this->purchaseOf('100.00');
        $paymentId = $this->payPurchase($purchase, '20.00');
        $this->loginAs($this->buyer());

        $response = $this->get('/receivings/edit/' . $purchase);

        $response->assertSee(lang('Receivings.payment') . ' #' . $paymentId);
        $response->assertDontSee('id="document_number"');
    }
}
