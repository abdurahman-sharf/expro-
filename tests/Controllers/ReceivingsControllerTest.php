<?php

namespace Tests\Controllers;

use App\Libraries\Accounting\InvoiceDiscount;
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
use Config\Services;
use DOMDocument;
use DOMXPath;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Support\AccountingFixtureTrait;
use Tests\Support\EmployeeFixtureTrait;
use Tests\Support\ItemFixtureTrait;
use Tests\Support\PurchaseAccountingFixtureTrait;
use Tests\Support\RoleFixtureTrait;
use Tests\Support\SaleAccountingFixtureTrait;
use App\Libraries\Roles\DefaultLocations;

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
    use RoleFixtureTrait;

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
     * @param list<int>|null       $branchIds
     * @param list<string>         $extraGrants
     * @param array<string, mixed> $employee    Overrides of the row of the employee, such as the language.
     */
    private function buyer(?array $branchIds = null, array $extraGrants = ['items', 'accounting_journals'], array $employee = []): int
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

        $personId = $this->createEmployee(first_name: 'Buyer', last_name: 'Test', grants: $grants, employee: $employee);

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

    public function testPostRequisitionComplete_RejectsADestinationTheEmployeeNoLongerHoldsAGrantOn(): void
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

    public function testPostRequisitionComplete_RejectsASourceTheEmployeeNoLongerHoldsAGrantOn(): void
    {
        $buyerId   = $this->buyer();
        $itemId    = $this->item('0.00');
        $ungranted = $this->locationOfBranch(1, 'No grant ' . uniqid());
        $granted   = $this->depotIn(1, $buyerId);
        $cart      = $this->cart([$this->cartLine($itemId, 1, '4', '0')], 'requisition', null, null, $ungranted);
        $this->loginAs($buyerId, $cart + ['recv_stock_destination' => $granted]);
        $before = $this->countRows('receivings');

        $this->post('/receivings/requisitionComplete', $this->completeForm())->assertSee(lang('Receivings.location_not_allowed'));

        $this->assertSame($before, $this->countRows('receivings'));
        $this->assertSame($cart['recv_cart'], session()->get('recv_cart'));
        $this->assertSame('0.000', $this->onHand($itemId, $granted));
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
     * @param array<string, string>                    $more     fields the form has besides these, such as the dates
     *
     * @return array<string, string>
     */
    private function paymentFields(array $existing = [], ?string $type = null, string $amount = '', array $more = []): array
    {
        $fields = ['number_of_payments' => (string) count($existing), 'payment_type_new' => $type ?? PAYMENT_TYPE_UNASSIGNED, 'payment_amount_new' => $amount] + $more;

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

    private function daysAgo(int $days): string
    {
        return date('Y-m-d', strtotime("-{$days} days"));
    }

    public function testGetEdit_ShowsTheDateOfEveryPaymentAndTodayForANewOneWithNothingLaterThanToday(): void
    {
        $purchase = $this->purchaseOf('500.00');
        $payment  = $this->payPurchase($purchase, '100.00');

        $this->accountingDb()->table('receivings_payments')->where('payment_id', $payment)->update(['payment_date' => $this->daysAgo(3)]);
        $this->loginAs($this->buyer());

        $body = $this->get('/receivings/edit/' . $purchase)->getBody();

        $this->assertMatchesRegularExpression('/<input type="date" name="payment_date_0"[^>]*max="' . date('Y-m-d') . '"[^>]*value="' . $this->daysAgo(3) . '"/', $body);
        $this->assertMatchesRegularExpression('/<input type="date" name="payment_date_new"[^>]*max="' . date('Y-m-d') . '"[^>]*value="' . date('Y-m-d') . '"/', $body);
        $this->assertStringContainsString('id="payment_date_note"', $body);
        $this->assertStringContainsString(lang('Receivings.payment_date_note'), $body);
    }

    public function testGetEdit_ShowsTheTimeThePaymentWasSavedApartFromItsDate(): void
    {
        $purchase = $this->purchaseOf('500.00');
        $payment  = $this->payPurchase($purchase, '100.00');

        $this->accountingDb()->table('receivings_payments')->where('payment_id', $payment)->update(['payment_date' => $this->daysAgo(3), 'payment_time' => $this->daysAgo(1) . ' 09:30:00']);
        $this->loginAs($this->buyer());

        $body = $this->get('/receivings/edit/' . $purchase)->getBody();

        $this->assertStringContainsString(lang('Receivings.payment_saved_at', [to_datetime(strtotime($this->daysAgo(1) . ' 09:30:00'))]), html_entity_decode($body));
    }

    public function testGetEdit_ShowsADayAPaymentWithNoDateWasSavedAsItsDate(): void
    {
        $purchase = $this->purchaseOf('500.00');
        $payment  = $this->payPurchase($purchase, '100.00');

        $this->accountingDb()->table('receivings_payments')->where('payment_id', $payment)->update(['payment_date' => null, 'payment_time' => $this->daysAgo(6) . ' 09:30:00']);
        $this->loginAs($this->buyer());

        $this->assertMatchesRegularExpression('/name="payment_date_0"[^>]*value="' . $this->daysAgo(6) . '"/', $this->get('/receivings/edit/' . $purchase)->getBody());
    }

    public function testGetEdit_ShowsTheDatesWhileTheBooksAreOff(): void
    {
        $purchase = $this->purchaseOf('500.00');
        $this->payPurchase($purchase, '100.00');
        $this->setConfigValue('accounting_enabled', '0');
        $this->loginAs($this->buyer());

        $response = $this->get('/receivings/edit/' . $purchase);

        $response->assertSee('name="payment_date_0"');
        $response->assertSee('name="payment_date_new"');
    }

    public function testPostSave_PostsTheNewPaymentOnTheDateTheFormNamed(): void
    {
        $purchase = $this->purchaseOf('500.00');
        $this->loginAs($this->buyer());

        $result = $this->jsonOf($this->post('/receivings/save/' . $purchase, $this->savedWith($purchase, $this->paymentFields([], lang('Sales.cash'), '150.00', ['payment_date_new' => $this->daysAgo(4)]))));

        $this->assertTrue($result['success']);

        $journal = $this->accountingDb()->table('accounting_journals')->where('source_type', 'supplier_payment')->get()->getRow();

        $this->assertSame($this->daysAgo(4), $journal->journal_date);
        $this->assertSame($this->daysAgo(4), $this->accountingDb()->table('receivings_payments')->where('receiving_id', $purchase)->get()->getRow()->payment_date);
        $this->assertSame('350.00', $this->owed());
        $this->assertSupplierTies();
    }

    public function testPostSave_DatesTheNewPaymentTodayWhenTheFormSendsNoDateOrAnEmptyOne(): void
    {
        $purchase = $this->purchaseOf('500.00');
        $this->loginAs($this->buyer());

        $this->assertTrue($this->jsonOf($this->post('/receivings/save/' . $purchase, $this->savedWith($purchase, $this->paymentFields([], lang('Sales.cash'), '10.00'))))['success']);
        $this->assertTrue($this->jsonOf($this->post('/receivings/save/' . $purchase, $this->savedWith($purchase, $this->paymentFields([], lang('Sales.cash'), '20.00', ['payment_date_new' => '']))))['success']);

        foreach ($this->accountingDb()->table('receivings_payments')->where('receiving_id', $purchase)->get()->getResult() as $payment) {
            $this->assertSame(date('Y-m-d'), $payment->payment_date);
        }
    }

    public function testPostSave_SaysWhyADateAfterTodayRefusesThePaymentAndSavesNothingOfTheForm(): void
    {
        $purchase = $this->purchaseOf('500.00');
        $this->loginAs($this->buyer());

        $tomorrow = date('Y-m-d', strtotime('+1 day'));
        $result   = $this->jsonOf($this->post('/receivings/save/' . $purchase, $this->savedWith($purchase, $this->paymentFields([], lang('Sales.cash'), '150.00', ['payment_date_new' => $tomorrow]), 'with a payment')));

        $this->assertFalse($result['success']);
        $this->assertSame(lang('Accounting.error_payment_date_in_future', [$tomorrow]), $result['message']);
        $this->assertSame(0, $this->countRows('receivings_payments'));
        $this->assertSame('test', $this->receivingRow($purchase)->comment);
    }

    public function testPostSave_SaysWhyADateThatIsNotADayRefusesThePayment(): void
    {
        $purchase = $this->purchaseOf('500.00');
        $this->loginAs($this->buyer());

        $result = $this->jsonOf($this->post('/receivings/save/' . $purchase, $this->savedWith($purchase, $this->paymentFields([], lang('Sales.cash'), '150.00', ['payment_date_new' => '31/02/2026']))));

        $this->assertFalse($result['success']);
        $this->assertSame(lang('Accounting.error_invalid_date'), $result['message']);
        $this->assertSame(0, $this->countRows('receivings_payments'));
    }

    public function testPostSave_SaysWhichPeriodIsClosedWhenTheDateOfThePaymentIsInIt(): void
    {
        $purchase = $this->purchaseOf('500.00');
        $this->loginAs($this->buyer());
        $this->closeThrough($this->daysAgo(10));

        $result = $this->jsonOf($this->post('/receivings/save/' . $purchase, $this->savedWith($purchase, $this->paymentFields([], lang('Sales.cash'), '150.00', ['payment_date_new' => $this->daysAgo(12)]))));

        $this->assertFalse($result['success']);
        $this->assertStringContainsString($this->daysAgo(12), $result['message']);
        $this->assertStringContainsString($this->daysAgo(10), $result['message']);
        $this->assertSame(0, $this->countRows('receivings_payments'));

        $accepted = $this->jsonOf($this->post('/receivings/save/' . $purchase, $this->savedWith($purchase, $this->paymentFields([], lang('Sales.cash'), '150.00', ['payment_date_new' => $this->daysAgo(9)]))));

        $this->assertTrue($accepted['success']);
    }

    public function testPostSave_MovesTheDateOfAPaymentAndPostsItAgain(): void
    {
        $purchase = $this->purchaseOf('500.00');
        $payment  = $this->payPurchase($purchase, '100.00');
        $this->loginAs($this->buyer());

        $result = $this->jsonOf($this->post('/receivings/save/' . $purchase, $this->savedWith($purchase, $this->paymentFields([[$payment, lang('Sales.cash'), '100.00', 'completed']], null, '', ['payment_date_0' => $this->daysAgo(5)]))));

        $this->assertTrue($result['success']);
        $this->assertSame(['reversed', 'posted'], array_map(static fn (object $journal): string => $journal->status, $this->accountingDb()->table('accounting_journals')->where(['source_type' => 'supplier_payment', 'source_id' => $payment])->orderBy('revision')->get()->getResult()));
        $this->assertSame($this->daysAgo(5), $this->accountingDb()->table('receivings_payments')->where('payment_id', $payment)->get()->getRow()->payment_date);
        $this->assertSame('400.00', $this->owed());
        $this->assertSupplierTies();
    }

    public function testPostSave_LeavesAPaymentWithNoDateAloneWhenTheFormSendsBackTheDayItShowed(): void
    {
        $purchase = $this->purchaseOf('500.00');
        $payment  = $this->payPurchase($purchase, '100.00');

        $this->accountingDb()->table('receivings_payments')->where('payment_id', $payment)->update(['payment_date' => null]);
        $this->loginAs($this->buyer());

        $result = $this->jsonOf($this->post('/receivings/save/' . $purchase, $this->savedWith($purchase, $this->paymentFields([[$payment, lang('Sales.cash'), '100.00', 'completed']], null, '', ['payment_date_0' => date('Y-m-d')]))));

        $this->assertTrue($result['success']);
        $this->assertNull($this->accountingDb()->table('receivings_payments')->where('payment_id', $payment)->get()->getRow()->payment_date);
        $this->assertSame(1, $this->accountingDb()->table('accounting_journals')->where(['source_type' => 'supplier_payment', 'source_id' => $payment])->countAllResults());
    }

    public function testPostSave_KeepsThePaymentsAsTheyAreWhenAFormWithNoDateFieldsIsPosted(): void
    {
        $purchase = $this->purchaseOf('500.00');
        $payment  = $this->payPurchase($purchase, '100.00');

        $this->accountingDb()->table('receivings_payments')->where('payment_id', $payment)->update(['payment_date' => $this->daysAgo(3)]);
        $this->loginAs($this->buyer());

        $result = $this->jsonOf($this->post('/receivings/save/' . $purchase, $this->savedWith($purchase, $this->paymentFields([[$payment, lang('Sales.cash'), '100.00', 'completed']]))));

        $this->assertTrue($result['success']);
        $this->assertSame($this->daysAgo(3), $this->accountingDb()->table('receivings_payments')->where('payment_id', $payment)->get()->getRow()->payment_date);
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

    public function testGetEdit_EscapesTheNamesOfTheTreasuriesItOffersInTheDropdownsOfTheNewAndTheExistingPayments(): void
    {
        $purchase = $this->purchaseOf('500.00');
        $payment  = $this->payPurchase($purchase, '100.00', 'Cash');
        $this->treasuryOfBranch('Open <b>drawer</b>');
        $branch = $this->anotherBranch();
        $far    = $this->treasuryOfBranch('Far <img src=x onerror=alert(1)>', $branch);
        $this->accountingDb()->table('receivings_payments')->where('payment_id', $payment)->update(['treasury_id' => $far]);

        $this->loginAs($this->buyer([1, $branch]));

        $body = $this->get('/receivings/edit/' . $purchase)->getBody();

        $this->assertStringNotContainsString('<img src=x', $body);
        $this->assertStringNotContainsString('<b>drawer</b>', $body);
        $this->assertSame(2, substr_count($body, 'Far &lt;img src=x onerror=alert(1)&gt; ('), 'The dropdown of the payment and the one of a new payment');
        $this->assertSame(2, substr_count($body, 'Open &lt;b&gt;drawer&lt;/b&gt;</option>'));
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

    // ---------------------------------------------------------------------------------------
    // The discount on the whole receiving
    // ---------------------------------------------------------------------------------------

    /**
     * @return array<string, mixed>
     */
    private function withInvoiceDiscount(array $session, int $type, string $value): array
    {
        return array_merge($session, ['recv_invoice_discount' => ['type' => $type, 'value' => $value]]);
    }

    public function testPostSetInvoiceDiscount_KeepsAPercentageAndShowsItBetweenTheSubtotalAndTheTax(): void
    {
        $itemId = $this->item('0.00');
        $this->loginAs($this->buyer(), $this->cart([$this->cartLine($itemId, 1, '10', '100', '15')]));

        $response = $this->post('/receivings/setInvoiceDiscount', ['invoice_discount' => '10', 'invoice_discount_type' => '0']);

        $response->assertStatus(200);
        $response->assertSeeElement('#receiving_invoice_discount');
        $response->assertSee(lang('Receivings.invoice_discount_amount'));
        $response->assertSee(to_currency('-100'));
        $response->assertSee(to_currency('135'));
        $response->assertSee(to_currency('1035'));
        $this->assertSame([PERCENT, '10.0000'], [session()->get('recv_invoice_discount')['type'], session()->get('recv_invoice_discount')['value']]);
    }

    public function testPostSetInvoiceDiscount_KeepsAnAmountAndTakesItOffTheTaxToo(): void
    {
        $itemId = $this->item('0.00');
        $this->loginAs($this->buyer(), $this->cart([$this->cartLine($itemId, 1, '10', '100', '15')]));

        $response = $this->post('/receivings/setInvoiceDiscount', ['invoice_discount' => '200', 'invoice_discount_type' => '1']);

        $response->assertSee(to_currency('-200'));
        $response->assertSee(to_currency('120'));
        $response->assertSee(to_currency('920'));
        $this->assertSame([FIXED, '200.00'], [session()->get('recv_invoice_discount')['type'], session()->get('recv_invoice_discount')['value']]);
    }

    /**
     * @return array<string, array{string, string}>
     */
    public static function refusedInvoiceDiscounts(): array
    {
        return [
            'text'                           => ['abc', '0'],
            'a negative percentage'          => ['-5', '0'],
            'a percentage above the whole'   => ['101', '0'],
            'an amount above the lines'      => ['1000.01', '1'],
        ];
    }

    #[DataProvider('refusedInvoiceDiscounts')]
    public function testPostSetInvoiceDiscount_RefusesWhatIsNotAValidDiscountAndKeepsTheOneThatWas(string $value, string $type): void
    {
        $itemId = $this->item('0.00');
        $this->loginAs($this->buyer(), $this->withInvoiceDiscount($this->cart([$this->cartLine($itemId, 1, '10', '100')]), PERCENT, '5.0000'));

        $response = $this->post('/receivings/setInvoiceDiscount', ['invoice_discount' => $value, 'invoice_discount_type' => $type]);

        $response->assertSee(lang('Receivings.invoice_discount_invalid'));
        $this->assertSame('5.0000', session()->get('recv_invoice_discount')['value']);
    }

    public function testPostSetInvoiceDiscount_RemovesTheDiscountWhenTheValueIsEmpty(): void
    {
        $itemId = $this->item('0.00');
        $this->loginAs($this->buyer(), $this->withInvoiceDiscount($this->cart([$this->cartLine($itemId, 1, '10', '100')]), PERCENT, '5.0000'));

        $this->post('/receivings/setInvoiceDiscount', ['invoice_discount' => '', 'invoice_discount_type' => '0'])->assertStatus(200);

        $this->assertNull(session()->get('recv_invoice_discount'));
    }

    public function testPostSetInvoiceDiscount_RefusesAnythingButGoodsComingIn(): void
    {
        $itemId = $this->item('0.00');
        $this->loginAs($this->buyer(), $this->cart([$this->cartLine($itemId, 1, '-2', '50')], 'return'));

        $response = $this->post('/receivings/setInvoiceDiscount', ['invoice_discount' => '10', 'invoice_discount_type' => '0']);

        $response->assertSee(lang('Receivings.invoice_discount_wrong_mode'));
        $this->assertNull(session()->get('recv_invoice_discount'));
    }

    public function testPostChangeMode_ForgetsTheDiscountWhenTheModeLeavesReceive(): void
    {
        $itemId = $this->item('0.00');
        $this->loginAs($this->buyer(), $this->withInvoiceDiscount($this->cart([$this->cartLine($itemId, 1, '10', '100')]), PERCENT, '5.0000'));

        $this->post('/receivings/changeMode', ['mode' => 'return'])->assertStatus(200);

        $this->assertNull(session()->get('recv_invoice_discount'));
    }

    public function testGetIndex_OffersTheDiscountOnlyToGoodsComingIn(): void
    {
        $itemId = $this->item('0.00');

        $this->loginAs($this->buyer(), $this->cart([$this->cartLine($itemId, 1, '10', '100')]));
        $this->get('/receivings')->assertSeeElement('#invoice_discount_form');

        $this->loginAs($this->buyer(), $this->cart([$this->cartLine($itemId, 1, '-2', '100')], 'return'));
        $this->get('/receivings')->assertDontSeeElement('#invoice_discount_form');
    }

    public function testPostSetInvoiceDiscount_SendsAnEmployeeWithoutTheReceivingsModuleToTheNoAccessPage(): void
    {
        $itemId = $this->item('0.00');
        $person = $this->createEmployee(first_name: 'No', last_name: 'Access', grants: [['permission_id' => 'items', 'menu_group' => 'home']]);
        $this->loginAs($person, $this->cart([$this->cartLine($itemId, 1, '10', '100')]));

        $response = $this->post('/receivings/setInvoiceDiscount', ['invoice_discount' => '10', 'invoice_discount_type' => '0']);

        $this->assertStringContainsString('no_access', $response->getRedirectUrl());
        $this->assertNull(session()->get('recv_invoice_discount'));
    }

    public function testPostComplete_SendsAnEmployeeWithoutTheReceivingsModuleToTheNoAccessPageWhateverTheRegisterHolds(): void
    {
        $itemId = $this->item('0.00');
        $before = $this->countRows('receivings');
        $person = $this->createEmployee(first_name: 'No', last_name: 'Access', grants: [['permission_id' => 'items', 'menu_group' => 'home']]);
        $this->loginAs($person, $this->withInvoiceDiscount($this->cart([$this->cartLine($itemId, 1, '10', '100')]), PERCENT, '10.0000'));

        $response = $this->post('/receivings/complete', $this->completeForm());

        $this->assertStringContainsString('no_access', $response->getRedirectUrl());
        $this->assertSame($before, $this->countRows('receivings'));
    }

    public function testPostSetInvoiceDiscount_SaysItsWordsInArabicToABuyerWhoReadsArabic(): void
    {
        $itemId = $this->item('0.00');
        $this->loginAs($this->buyer(null, ['items'], ['language_code' => 'ar-EG', 'language' => 'arabic']), $this->cart([$this->cartLine($itemId, 1, '10', '100', '15')]));

        $response = $this->post('/receivings/setInvoiceDiscount', ['invoice_discount' => '10', 'invoice_discount_type' => '0']);

        $response->assertSee('خصم على إجمالي الفاتورة');
        $response->assertSee('خصم الفاتورة');
        $response->assertDontSee('Discount on the whole invoice');
    }

    public function testGetReceipt_SaysTheDiscountInArabicToABuyerWhoReadsArabic(): void
    {
        $itemId   = $this->item('0.00');
        $purchase = $this->buy([$this->purchaseLine($itemId, 0, '10', '100', '15')], -1, null, null, InvoiceDiscount::of(PERCENT, '10'));
        $this->loginAs($this->buyer(null, ['items'], ['language_code' => 'ar-EG', 'language' => 'arabic']));

        $receipt = $this->get('/receivings/receipt/' . $purchase);

        $receipt->assertSeeElement('#receipt_invoice_discount');
        $receipt->assertSee('خصم الفاتورة');
    }

    public function testPostCancelReceiving_ForgetsTheDiscount(): void
    {
        $itemId = $this->item('0.00');
        $this->loginAs($this->buyer(), $this->withInvoiceDiscount($this->cart([$this->cartLine($itemId, 1, '10', '100')]), PERCENT, '5.0000'));

        $this->post('/receivings/cancelReceiving')->assertStatus(200);

        $this->assertNull(session()->get('recv_invoice_discount'));
    }

    public function testPostComplete_SavesTheDiscountPostsItAndShowsItOnTheReceipt(): void
    {
        $itemId = $this->item('0.00');
        $this->loginAs($this->buyer(), $this->withInvoiceDiscount($this->cart([$this->cartLine($itemId, 1, '10', '100', '15')]), PERCENT, '10.0000'));

        $response = $this->post('/receivings/complete', $this->completeForm());

        $response->assertStatus(200);
        $response->assertSeeElement('#receipt_invoice_discount');
        $response->assertSee(to_currency('-100'));
        $response->assertSee(to_currency('1035'));

        $receivingId = (int) $this->accountingDb()->table('receivings')->selectMax('receiving_id', 'last')->get()->getRow()->last;
        $this->receivingIds[] = $receivingId;

        $header = $this->receivingRow($receivingId);

        $this->assertSame([PERCENT, '10.0000'], [(int) $header->invoice_discount_type, bcadd((string) $header->invoice_discount, '0', 4)]);
        $this->assertSame('-1035.00', $this->balance($this->supplierAccount()));
        $this->assertSame('135.00', $this->balance($this->accountId('tax_input')));
        $this->assertSame(['quantity' => '10.000', 'value' => '900.00'], $this->stockOf($itemId));
        $this->assertNull(session()->get('recv_invoice_discount'), 'The register starts again');
        $this->assertStockTies();
    }

    public function testPostComplete_SaysWhyADiscountAboveTheReceivingWasRefusedAndKeepsTheCart(): void
    {
        $itemId = $this->item('0.00');
        $before = $this->countRows('receivings');
        $this->loginAs($this->buyer(), $this->withInvoiceDiscount($this->cart([$this->cartLine($itemId, 1, '10', '100')]), FIXED, '5000.00'));

        $response = $this->post('/receivings/complete', $this->completeForm());

        $response->assertSee(lang('Accounting.posting_purchase_discount_invalid'));
        $this->assertSame($before, $this->countRows('receivings'));
        $this->assertNotEmpty(session()->get('recv_cart'));
        $this->assertSame('0.000', $this->onHand($itemId));
    }

    public function testPostComplete_RefusesACartOfAnotherBranchWhateverItsDiscount(): void
    {
        $branchId = $this->anotherBranch();
        $buyerId  = $this->buyer([$branchId]);
        $this->depotIn($branchId, $buyerId);
        $itemId = $this->item('0.00');
        $before = $this->countRows('receivings');

        $this->loginAs($buyerId, $this->withInvoiceDiscount($this->cart([$this->cartLine($itemId, 1, '5', '10')], 'receive', -1, null, self::LOCATION_ID), PERCENT, '10.0000'));

        $this->post('/receivings/complete', $this->completeForm())->assertSee(lang('Receivings.branch_not_allowed'));

        $this->assertSame($before, $this->countRows('receivings'));
    }

    public function testPostComplete_IgnoresTheDiscountOfTheRegisterWhenTheGoodsGoBack(): void
    {
        $itemId   = $this->item('0.00');
        $purchase = $this->buy([$this->purchaseLine($itemId, 0, '10', '50')]);
        $this->loginAs($this->buyer(), $this->withInvoiceDiscount($this->cart([$this->cartLine($itemId, 1, '-2', '50')], 'return', -1, $purchase), PERCENT, '10.0000'));

        $this->post('/receivings/complete', $this->completeForm())->assertSee('RECV ');

        $return = $this->accountingDb()->table('receivings')->where('return_of_receiving_id', $purchase)->get()->getRow();
        $this->receivingIds[] = (int) $return->receiving_id;

        $this->assertNull($return->invoice_discount_type);
        $this->assertSame('400.00', $this->owed());
    }

    public function testPostComplete_SavesTheDiscountWhileAccountingIsOffAndShowsItOnTheReceipt(): void
    {
        $itemId = $this->item('0.00');
        $this->setConfigValue('accounting_enabled', '0');
        $this->loginAs($this->buyer(), $this->withInvoiceDiscount($this->cart([$this->cartLine($itemId, 1, '10', '100', '15')]), FIXED, '100.00'));

        $response = $this->post('/receivings/complete', $this->completeForm());

        $response->assertSeeElement('#receipt_invoice_discount');
        $response->assertSee(to_currency('1035'));

        $receivingId = (int) $this->accountingDb()->table('receivings')->selectMax('receiving_id', 'last')->get()->getRow()->last;
        $this->receivingIds[] = $receivingId;

        $this->assertSame('100.0000', bcadd((string) $this->accountingDb()->table('receivings_items')->where('receiving_id', $receivingId)->get()->getRow()->invoice_discount_share, '0', 4));
        $this->assertSame(0, $this->countRows('accounting_journals'));
    }

    public function testGetReceipt_ShowsTheDiscountThatWasSavedAndNothingForAReceivingWithoutOne(): void
    {
        $itemId = $this->item('0.00');
        $with   = $this->buy([$this->purchaseLine($itemId, 0, '10', '100', '15')], -1, null, null, InvoiceDiscount::of(PERCENT, '10'));
        $without = $this->buy([$this->purchaseLine($itemId, 0, '10', '100', '15')]);
        $this->loginAs($this->buyer());

        $receipt = $this->get('/receivings/receipt/' . $with);

        $receipt->assertSeeElement('#receipt_invoice_discount');
        $receipt->assertSee(to_currency('-100'));
        $receipt->assertSee(to_currency('135'));
        $receipt->assertSee(to_currency('1035'));

        $plain = $this->get('/receivings/receipt/' . $without);

        $plain->assertDontSeeElement('#receipt_invoice_discount');
        $plain->assertSee(to_currency('1150'));
    }

    public function testGetReceipt_ShowsTheShareAReturnTookOfTheDiscount(): void
    {
        $itemId   = $this->item('0.00');
        $purchase = $this->buy([$this->purchaseLine($itemId, 0, '10', '100', '15')], -1, null, null, InvoiceDiscount::of(FIXED, '100'));
        $return   = $this->buy([$this->purchaseLine($itemId, 0, '-4', '100', '15')], -1, null, $purchase);
        $this->loginAs($this->buyer());

        $receipt = $this->get('/receivings/receipt/' . $return);

        $receipt->assertSee(lang('Receivings.invoice_discount_share'));
        $receipt->assertSee(to_currency('40'));
        $receipt->assertSee(to_currency('-414'));
    }

    public function testGetEdit_ShowsTheDiscountOfAReceivingThatHasOneAndNothingForOneThatHasNot(): void
    {
        $itemId  = $this->item('0.00');
        $with    = $this->buy([$this->purchaseLine($itemId, 0, '10', '100')], -1, null, null, InvoiceDiscount::of(PERCENT, '10'));
        $without = $this->buy([$this->purchaseLine($itemId, 0, '10', '100')]);
        $this->loginAs($this->buyer());

        $form = $this->get('/receivings/edit/' . $with);

        $form->assertSeeElement('#invoice_discount_info');
        $form->assertSee(to_currency('100'));
        $this->get('/receivings/edit/' . $without)->assertDontSeeElement('#invoice_discount_info');
    }

    public function testPostDebitNote_PreparesAReturnThatShowsAndTakesItsShareOfTheDiscount(): void
    {
        $itemId   = $this->item('0.00');
        $purchase = $this->buy([$this->purchaseLine($itemId, 0, '10', '50', '15')], -1, null, null, InvoiceDiscount::of(FIXED, '100'));
        $this->loginAs($this->buyer());

        $this->assertTrue($this->jsonOf($this->post('/receivings/debitNote/' . $purchase))['success']);

        $this->withSession($_SESSION);

        $register = $this->get('/receivings');

        $register->assertSee(lang('Receivings.invoice_discount_share'));
        $register->assertSee(to_currency('100'));
        $register->assertSee(to_currency('-460'));

        $this->withSession($_SESSION);

        $this->post('/receivings/complete', $this->completeForm())->assertSee('RECV ');

        $return = $this->accountingDb()->table('receivings')->where('return_of_receiving_id', $purchase)->get()->getRow();
        $this->receivingIds[] = (int) $return->receiving_id;

        $this->assertSame('0.00', $this->owed());
        $this->assertStockTies();
        $this->assertSupplierTies();
    }

    public function testGetIndex_ShowsTheTaxOfAReturnThatTookAShareSoThatTheRowsAddUp(): void
    {
        $itemId   = $this->item('0.00');
        $purchase = $this->buy([$this->purchaseLine($itemId, 0, '10', '50', '15')], -1, null, null, InvoiceDiscount::of(FIXED, '100'));
        $this->loginAs($this->buyer());

        $this->assertTrue($this->jsonOf($this->post('/receivings/debitNote/' . $purchase))['success']);

        $this->withSession($_SESSION);

        $register = $this->get('/receivings');

        $register->assertSeeElement('#receiving_tax');
        $this->assertSame(array_map('to_currency', ['-500', '100', '-60', '-460']), $this->registerAmounts($register->getBody()));
    }

    public function testGetReceipt_ShowsTheTaxOfAReturnThatTookAShareSoThatTheRowsAddUp(): void
    {
        $itemId   = $this->item('0.00');
        $purchase = $this->buy([$this->purchaseLine($itemId, 0, '10', '100', '15')], -1, null, null, InvoiceDiscount::of(FIXED, '100'));
        $return   = $this->buy([$this->purchaseLine($itemId, 0, '-4', '100', '15')], -1, null, $purchase);
        $this->loginAs($this->buyer());

        $receipt = $this->get('/receivings/receipt/' . $return);

        $receipt->assertSeeElement('#receipt_tax');
        $this->assertSame(array_map('to_currency', ['-400', '40', '-54', '-414']), $this->receiptAmounts($receipt->getBody()));
    }

    public function testGetReceipt_LeavesOutTheTaxOfAReturnThatTookNoShare(): void
    {
        $itemId   = $this->item('0.00');
        $purchase = $this->buy([$this->purchaseLine($itemId, 0, '10', '100', '15')]);
        $return   = $this->buy([$this->purchaseLine($itemId, 0, '-4', '100', '15')], -1, null, $purchase);
        $this->loginAs($this->buyer());

        $receipt = $this->get('/receivings/receipt/' . $return);

        $receipt->assertDontSeeElement('#receipt_tax');
        $receipt->assertDontSeeElement('#receipt_invoice_discount');
        $receipt->assertSee(to_currency('-460'));
    }

    public function testGetIndex_WarnsThatTheDiscountNoLongerFitsAfterALineWasLowered(): void
    {
        $itemId = $this->item('0.00');
        $this->loginAs($this->buyer(), $this->cart([$this->cartLine($itemId, 1, '10', '100')]));

        $this->post('/receivings/setInvoiceDiscount', ['invoice_discount' => '900', 'invoice_discount_type' => '1'])->assertDontSee(lang('Receivings.invoice_discount_invalid'));

        $this->withSession($_SESSION);

        $response = $this->post('/receivings/editItem/1', ['price' => '100', 'quantity' => '5', 'discount' => '0', 'receiving_quantity' => '1', 'tax_percent' => '0', 'description' => 'x', 'discount_type' => '0']);

        $response->assertSee(lang('Receivings.invoice_discount_invalid'));
    }

    public function testPostDeleteItem_WarnsThatTheDiscountNoLongerFitsWhenTheLinesLeftComeToLess(): void
    {
        $itemId = $this->item('0.00');
        $this->loginAs($this->buyer(), $this->withInvoiceDiscount($this->cart([$this->cartLine($itemId, 1, '10', '100'), $this->cartLine($itemId, 2, '1', '50')]), FIXED, '900.00'));

        $response = $this->get('/receivings/deleteItem/1');

        $response->assertSee(lang('Receivings.invoice_discount_invalid'));
    }

    public function testGetIndex_WarnsThatAReceivingOfGoodsGoingOutTakesNoDiscount(): void
    {
        $itemId = $this->item('0.00');
        $this->loginAs($this->buyer(), $this->withInvoiceDiscount($this->cart([$this->cartLine($itemId, 1, '-2', '100')]), PERCENT, '10.0000'));

        $this->get('/receivings')->assertSee(lang('Receivings.invoice_discount_wrong_mode'));
    }

    public function testGetIndex_SaysNothingOfADiscountThatFitsTheLines(): void
    {
        $itemId = $this->item('0.00');
        $this->loginAs($this->buyer(), $this->withInvoiceDiscount($this->cart([$this->cartLine($itemId, 1, '10', '100')]), FIXED, '1000.00'));

        $response = $this->get('/receivings');

        $response->assertDontSee(lang('Receivings.invoice_discount_invalid'));
        $response->assertDontSee(lang('Receivings.invoice_discount_wrong_mode'));
    }

    public function testPostSetInvoiceDiscount_RefusesAListInsteadOfANumber(): void
    {
        $itemId = $this->item('0.00');
        $this->loginAs($this->buyer(), $this->withInvoiceDiscount($this->cart([$this->cartLine($itemId, 1, '10', '100')]), PERCENT, '5.0000'));

        $response = $this->post('/receivings/setInvoiceDiscount', ['invoice_discount' => ['5'], 'invoice_discount_type' => '0']);

        $response->assertStatus(200);
        $response->assertSee(lang('Receivings.invoice_discount_invalid'));
        $this->assertSame('5.0000', session()->get('recv_invoice_discount')['value']);
    }

    public function testPostSetInvoiceDiscount_RefusesAListInsteadOfAType(): void
    {
        $itemId = $this->item('0.00');
        $this->loginAs($this->buyer(), $this->withInvoiceDiscount($this->cart([$this->cartLine($itemId, 1, '10', '100')]), PERCENT, '5.0000'));

        $response = $this->post('/receivings/setInvoiceDiscount', ['invoice_discount' => '50', 'invoice_discount_type' => ['1']]);

        $response->assertSee(lang('Receivings.invoice_discount_invalid'));
        $this->assertSame([PERCENT, '5.0000'], [session()->get('recv_invoice_discount')['type'], session()->get('recv_invoice_discount')['value']]);
    }

    public function testPostSetInvoiceDiscount_LeavesOutTheTaxRowWhenTheLinesCarryNoTax(): void
    {
        $itemId = $this->item('0.00');
        $this->loginAs($this->buyer(), $this->cart([$this->cartLine($itemId, 1, '10', '100')]));

        $response = $this->post('/receivings/setInvoiceDiscount', ['invoice_discount' => '10', 'invoice_discount_type' => '0']);

        $response->assertDontSeeElement('#receiving_tax');
        $this->assertSame(array_map('to_currency', ['1000', '-100', '900']), $this->registerAmounts($response->getBody()));
    }

    public function testGetReceipt_LeavesOutTheTaxRowOfAPurchaseWithADiscountAndNoTax(): void
    {
        $itemId   = $this->item('0.00');
        $purchase = $this->buy([$this->purchaseLine($itemId, 0, '10', '100')], -1, null, null, InvoiceDiscount::of(PERCENT, '10'));
        $this->loginAs($this->buyer());

        $receipt = $this->get('/receivings/receipt/' . $purchase);

        $receipt->assertDontSeeElement('#receipt_tax');
        $this->assertSame(array_map('to_currency', ['1000', '-100', '900']), $this->receiptAmounts($receipt->getBody()));
    }

    public function testGetIndex_SaysNothingOfADiscountWhileTheRegisterHoldsNoLines(): void
    {
        $this->loginAs($this->buyer(), $this->withInvoiceDiscount(['recv_mode' => 'receive'], FIXED, '50.00'));

        $response = $this->get('/receivings');

        $response->assertDontSee(lang('Receivings.invoice_discount_invalid'));
        $response->assertDontSee(lang('Receivings.invoice_discount_wrong_mode'));
    }

    public function testGetIndex_SaysNothingOfGoodsGoingOutWhenThereIsNoDiscount(): void
    {
        $itemId = $this->item('0.00');
        $this->loginAs($this->buyer(), $this->cart([$this->cartLine($itemId, 1, '-2', '100')]));

        $response = $this->get('/receivings');

        $response->assertDontSee(lang('Receivings.invoice_discount_invalid'));
        $response->assertDontSee(lang('Receivings.invoice_discount_wrong_mode'));
    }

    public function testGetIndex_SaysNothingOfADiscountKeptWhileTheRegisterIsNotInReceiveMode(): void
    {
        $itemId = $this->item('0.00');
        $this->loginAs($this->buyer(), $this->withInvoiceDiscount($this->cart([$this->cartLine($itemId, 1, '-2', '100')], 'return'), PERCENT, '10.0000'));

        $response = $this->get('/receivings');

        $response->assertDontSee(lang('Receivings.invoice_discount_invalid'));
        $response->assertDontSee(lang('Receivings.invoice_discount_wrong_mode'));
    }

    public function testPostChangeMode_KeepsTheDiscountAndSaysSoWhenTheDestinationIsRefused(): void
    {
        $buyerId   = $this->buyer();
        $ungranted = $this->locationOfBranch(1, 'No grant ' . uniqid());
        $itemId    = $this->item('0.00');
        $this->loginAs($buyerId, $this->withInvoiceDiscount($this->cart([$this->cartLine($itemId, 1, '10', '100')]), PERCENT, '5.0000'));

        $this->post('/receivings/changeMode', ['mode' => 'receive', 'stock_source' => (string) self::LOCATION_ID, 'stock_destination' => (string) $ungranted])->assertSee(lang('Receivings.location_not_allowed'));

        $this->assertSame(['type' => PERCENT, 'value' => '5.0000'], session()->get('recv_invoice_discount'));
        $this->assertSame('receive', session()->get('recv_mode'));
    }

    public function testPostChangeMode_ForgetsTheDiscountAndShowsTheRefusalWhenARequisitionNamesADestinationWithoutAGrant(): void
    {
        $buyerId   = $this->buyer();
        $granted   = $this->depotIn(1, $buyerId);
        $ungranted = $this->locationOfBranch(1, 'No grant ' . uniqid());
        $itemId    = $this->item('0.00');
        $cart      = $this->withInvoiceDiscount($this->cart([$this->cartLine($itemId, 1, '4', '10')], 'requisition', null), PERCENT, '5.0000');
        $this->loginAs($buyerId, $cart + ['recv_stock_destination' => $granted]);

        $this->post('/receivings/changeMode', ['mode' => 'requisition', 'stock_source' => (string) self::LOCATION_ID, 'stock_destination' => (string) $ungranted])->assertSee(lang('Receivings.location_not_allowed'));

        $this->assertNull(session()->get('recv_invoice_discount'));
        $this->assertSame((string) $granted, (string) session()->get('recv_stock_destination'));
    }

    public function testPostComplete_ShowsTheNumberAndTheDiscountOfThePurchaseOnTheReceipt(): void
    {
        $this->switchOn(DocumentType::Purchase, 'PUR-');
        $itemId = $this->item('0.00');
        $this->loginAs($this->buyer(), $this->withInvoiceDiscount($this->cart([$this->cartLine($itemId, 1, '10', '100', '15')]), PERCENT, '10.0000'));

        $response = $this->post('/receivings/complete', $this->completeForm());

        $response->assertSeeElement('#document_number');
        $response->assertSee('PUR-001');
        $response->assertSeeElement('#receipt_invoice_discount');
        $response->assertSee(to_currency('-100'));
        $response->assertSee(to_currency('1035'));

        $receivingId          = (int) $this->accountingDb()->table('receivings')->selectMax('receiving_id', 'last')->get()->getRow()->last;
        $this->receivingIds[] = $receivingId;

        $this->assertSame('PUR-001', (new DocumentNumbering())->numbers(DocumentType::Purchase, 'receivings', [$receivingId])[$receivingId]);
        $this->assertSame('-1035.00', $this->balance($this->supplierAccount()));
        $this->assertSame(['quantity' => '10.000', 'value' => '900.00'], $this->stockOf($itemId));
    }

    public function testPostComplete_ConsumesNoNumberWhenTheDiscountAboveThePurchaseIsRefused(): void
    {
        $this->switchOn(DocumentType::Purchase, 'PUR-');
        $itemId = $this->item('0.00');
        $before = $this->countRows('receivings');
        $this->loginAs($this->buyer(), $this->withInvoiceDiscount($this->cart([$this->cartLine($itemId, 1, '10', '100')]), FIXED, '5000.00'));

        $this->post('/receivings/complete', $this->completeForm())->assertSee(lang('Accounting.posting_purchase_discount_invalid'));

        $this->assertSame($before, $this->countRows('receivings'));
        $this->assertSame(0, $this->countRows('document_numbers'));
        $this->assertSame(1, (new DocumentNumbering())->sequence(DocumentType::Purchase)->nextNumber);
        $this->assertNotEmpty(session()->get('recv_cart'));

        $this->withSession($_SESSION);
        $this->post('/receivings/setInvoiceDiscount', ['invoice_discount' => '10', 'invoice_discount_type' => (string) PERCENT])->assertStatus(200);

        $this->withSession($_SESSION);
        $this->post('/receivings/complete', $this->completeForm())->assertSee('PUR-001');

        $this->receivingIds[] = (int) $this->accountingDb()->table('receivings')->selectMax('receiving_id', 'last')->get()->getRow()->last;
    }

    public function testGetReceipt_ShowsTheNumberAndTheDiscountOfAPurchaseTogether(): void
    {
        $this->switchOn(DocumentType::Purchase, 'PUR-');
        $itemId   = $this->item('0.00');
        $purchase = $this->buy([$this->purchaseLine($itemId, 0, '10', '100', '15')], -1, null, null, InvoiceDiscount::of(PERCENT, '10'));
        $this->loginAs($this->buyer());

        $receipt = $this->get('/receivings/receipt/' . $purchase);

        $receipt->assertSeeElement('#document_number');
        $receipt->assertSee('PUR-001');
        $receipt->assertSeeElement('#receipt_invoice_discount');
        $receipt->assertSee(to_currency('-100'));
        $receipt->assertSee(to_currency('1035'));
    }

    public function testGetReceipt_ShowsTheDebitNoteNumberAndTheShareOfTheDiscountOfAReturn(): void
    {
        $this->switchOn(DocumentType::Purchase, 'PUR-');
        $this->switchOn(DocumentType::DebitNote, 'DN-');
        $itemId   = $this->item('0.00');
        $purchase = $this->buy([$this->purchaseLine($itemId, 0, '10', '100', '15')], -1, null, null, InvoiceDiscount::of(FIXED, '100'));
        $return   = $this->buy([$this->purchaseLine($itemId, 0, '-4', '100', '15')], -1, null, $purchase);
        $this->loginAs($this->buyer());

        $receipt = $this->get('/receivings/receipt/' . $return);

        $receipt->assertSee('DN-001');
        $receipt->assertSee(lang('Numbering.type_debit_note'));
        $receipt->assertDontSee('PUR-001');
        $receipt->assertSee(lang('Receivings.invoice_discount_share'));
        $receipt->assertSee(to_currency('40'));
        $receipt->assertSee(to_currency('-414'));
    }

    public function testGetEdit_ShowsTheNumberAndTheDiscountOfAPurchaseTogether(): void
    {
        $this->switchOn(DocumentType::Purchase, 'PUR-');
        $itemId   = $this->item('0.00');
        $purchase = $this->buy([$this->purchaseLine($itemId, 0, '10', '100')], -1, null, null, InvoiceDiscount::of(PERCENT, '10'));
        $this->loginAs($this->buyer());

        $form = $this->get('/receivings/edit/' . $purchase);

        $form->assertSeeElement('#document_number');
        $form->assertSee('PUR-001');
        $form->assertSeeElement('#invoice_discount_info');
        $form->assertSee(to_currency('100'));
    }

    public function testPostComplete_ShowsTheDebitNoteNumberAndTheShareOfTheDiscountOnTheReceiptOfTheReturnTheRegisterPrepared(): void
    {
        $this->switchOn(DocumentType::Purchase, 'PUR-');
        $this->switchOn(DocumentType::DebitNote, 'DN-');
        $itemId   = $this->item('0.00');
        $purchase = $this->buy([$this->purchaseLine($itemId, 0, '10', '50', '15')], -1, null, null, InvoiceDiscount::of(FIXED, '100'));
        $this->loginAs($this->buyer());

        $this->assertTrue($this->jsonOf($this->post('/receivings/debitNote/' . $purchase))['success']);

        $this->withSession($_SESSION);

        $receipt = $this->post('/receivings/complete', $this->completeForm());

        $return               = $this->accountingDb()->table('receivings')->where('return_of_receiving_id', $purchase)->get()->getRow();
        $this->receivingIds[] = (int) $return->receiving_id;

        $receipt->assertSee('DN-001');
        $receipt->assertSee(lang('Receivings.invoice_discount_share'));
        $receipt->assertSee(to_currency('100'));
        $receipt->assertSee(to_currency('-460'));
        $this->assertSame('0.00', $this->owed());
        $this->assertStockTies();
        $this->assertSupplierTies();
    }

    /**
     * @return list<string> what the rows of the totals show as their amounts, in the order they are shown
     */
    private function amountsOf(string $html, string $cellsQuery): array
    {
        $document = new DOMDocument();
        @$document->loadHTML('<?xml encoding="utf-8"?>' . $html);

        $amounts = [];

        foreach ((new DOMXPath($document))->query($cellsQuery) as $cell) {
            $text = trim($cell->textContent);

            if (preg_match('/\d/', $text) === 1) {
                $amounts[] = $text;
            }
        }

        return $amounts;
    }

    private function registerAmounts(string $html): array
    {
        return $this->amountsOf($html, "//*[@id='sale_totals']/div/dd");
    }

    private function receiptAmounts(string $html): array
    {
        return $this->amountsOf($html, "//tr[td[@colspan='3' and @style]]/td[last()]");
    }

    // ---------------------------------------------------------------------------------------
    // The date typed on a purchase, its payment terms and its due date (WP13)
    // ---------------------------------------------------------------------------------------

    /**
     * A buyer with a cart of one purchase of ten units at the register, with or without the
     * permission to type the date of a purchase.
     *
     * @param array<string, mixed> $more
     */
    private function registerWithTheDateGrant(bool $granted = true, array $more = []): int
    {
        $itemId  = $this->item('0.00');
        $buyerId = $this->buyer(null, $granted ? ['items', 'receivings_invoice_date'] : ['items']);

        $this->loginAs($buyerId, array_merge($this->cart([$this->cartLine($itemId, 1, '10', '100')]), $more));

        return $buyerId;
    }

    private function carryTheTimingToTheNextRequest(): void
    {
        $this->withSession(array_merge($this->session, array_intersect_key(Services::session()->get(), array_flip(['recv_invoice_date', 'recv_payment_terms', 'recv_cart', 'recv_mode', 'recv_supplier', 'recv_stock_source']))));
    }

    public function testPostSetInvoiceDate_RefusesABuyerWithoutThePermissionAndSetsNothing(): void
    {
        $this->registerWithTheDateGrant(false);

        $response = $this->post('/receivings/setInvoiceDate', ['recv_invoice_date' => $this->daysAgo(3)]);

        $response->assertStatus(403);
        $this->assertFalse($this->jsonOf($response)['success']);
        $this->assertNull(Services::session()->get('recv_invoice_date'));
    }

    public function testPostSetInvoiceDate_RefusesWhileAccountingIsOffAndSetsNothing(): void
    {
        $this->registerWithTheDateGrant();
        $this->setConfigValue('accounting_enabled', '0');

        $this->post('/receivings/setInvoiceDate', ['recv_invoice_date' => $this->daysAgo(3)])->assertStatus(403);
        $this->assertNull(Services::session()->get('recv_invoice_date'));
    }

    public function testPostSetInvoiceDate_SetsADayUpToTodayAndAnswersTheDueDateOfTheTermsOnTheRegister(): void
    {
        $this->registerWithTheDateGrant(true, ['recv_payment_terms' => '10']);

        $answer = $this->jsonOf($this->post('/receivings/setInvoiceDate', ['recv_invoice_date' => $this->daysAgo(3)]));

        $this->assertTrue($answer['success']);
        $this->assertSame($this->daysAgo(3), Services::session()->get('recv_invoice_date'));
        $this->assertSame(to_date(strtotime('+7 days')), $answer['due_date_label']);
    }

    /**
     * @return array<string, array{string}>
     */
    public static function refusedRegisterDayProvider(): array
    {
        return [
            'tomorrow'         => [date('Y-m-d', strtotime('+1 day'))],
            'not a date'       => ['soon'],
            'before the books' => ['2019-12-31'],
        ];
    }

    #[DataProvider('refusedRegisterDayProvider')]
    public function testPostSetInvoiceDate_RefusesADayThatIsNotUpToTodayOrIsBeforeTheBooksAndKeepsWhatWasSet(string $typed): void
    {
        $this->registerWithTheDateGrant(true, ['recv_invoice_date' => $this->daysAgo(2)]);

        $answer = $this->jsonOf($this->post('/receivings/setInvoiceDate', ['recv_invoice_date' => $typed]));

        $this->assertFalse($answer['success']);
        $this->assertNotSame('', $answer['message']);
        $this->assertSame($this->daysAgo(2), Services::session()->get('recv_invoice_date'));
    }

    public function testPostSetInvoiceDate_RefusesAListOfDaysAndKeepsWhatWasSet(): void
    {
        $this->registerWithTheDateGrant(true, ['recv_invoice_date' => $this->daysAgo(2)]);

        $answer = $this->jsonOf($this->post('/receivings/setInvoiceDate', ['recv_invoice_date' => [$this->daysAgo(1)]]));

        $this->assertFalse($answer['success']);
        $this->assertNotSame('', $answer['message']);
        $this->assertSame($this->daysAgo(2), Services::session()->get('recv_invoice_date'));
    }

    public function testPostSetInvoiceDate_TakesBackTheDayWhenItIsSentEmpty(): void
    {
        $this->registerWithTheDateGrant(true, ['recv_invoice_date' => $this->daysAgo(2)]);

        $this->assertTrue($this->jsonOf($this->post('/receivings/setInvoiceDate', ['recv_invoice_date' => '']))['success']);
        $this->assertNull(Services::session()->get('recv_invoice_date'));
    }

    public function testPostSetPaymentTerms_SetsTheTermsForAnyBuyerAndAnswersTheDueDate(): void
    {
        $this->registerWithTheDateGrant(false);

        $answer = $this->jsonOf($this->post('/receivings/setPaymentTerms', ['recv_payment_terms' => '30']));

        $this->assertTrue($answer['success']);
        $this->assertSame('30', Services::session()->get('recv_payment_terms'));
        $this->assertSame(to_date(strtotime('+30 days')), $answer['due_date_label']);
    }

    public function testPostSetPaymentTerms_RefusesWhatIsNotAWholeNumberOfDaysAndKeepsWhatWasSet(): void
    {
        $this->registerWithTheDateGrant(true, ['recv_payment_terms' => '5']);

        foreach (['-1', '2.5', 'soon', '3651'] as $typed) {
            $answer = $this->jsonOf($this->post('/receivings/setPaymentTerms', ['recv_payment_terms' => $typed]));

            $this->assertFalse($answer['success'], $typed);
            $this->assertSame('5', Services::session()->get('recv_payment_terms'), $typed);
        }
    }

    public function testPostSetPaymentTerms_RefusesAListOfTermsAndKeepsWhatWasSet(): void
    {
        $this->registerWithTheDateGrant(true, ['recv_payment_terms' => '5']);

        $answer = $this->jsonOf($this->post('/receivings/setPaymentTerms', ['recv_payment_terms' => ['30']]));

        $this->assertFalse($answer['success']);
        $this->assertNotSame('', $answer['message']);
        $this->assertSame('5', Services::session()->get('recv_payment_terms'));
    }

    public function testPostSetPaymentTerms_TakesBackTheTermsWhenTheyAreSentZeroAndRefusesWhileAccountingIsOff(): void
    {
        $this->registerWithTheDateGrant(true, ['recv_payment_terms' => '5']);

        $this->assertTrue($this->jsonOf($this->post('/receivings/setPaymentTerms', ['recv_payment_terms' => '0']))['success']);
        $this->assertNull(Services::session()->get('recv_payment_terms'));

        $this->withSession(array_merge($this->session, ['recv_payment_terms' => '5']));
        $this->setConfigValue('accounting_enabled', '0');

        $this->post('/receivings/setPaymentTerms', ['recv_payment_terms' => '9'])->assertStatus(403);
        $this->assertSame('5', Services::session()->get('recv_payment_terms'));
    }

    public function testGetIndex_OffersTheDateOnlyToABuyerWhoMayTypeItAndTheTermsToEveryoneInTheModeOfAReceiving(): void
    {
        $this->registerWithTheDateGrant();

        $page = $this->get('/receivings');

        $page->assertSee('id="recv_invoice_date"');
        $page->assertSee('id="recv_payment_terms"');
        $page->assertSee('id="recv_due_date"');

        $this->registerWithTheDateGrant(false);

        $page = $this->get('/receivings');

        $page->assertDontSee('id="recv_invoice_date"');
        $page->assertSee('id="recv_payment_terms"');
    }

    public function testGetIndex_OffersNeitherTheDateNorTheTermsOutsideTheModeOfAReceivingOrWhileAccountingIsOff(): void
    {
        $this->registerWithTheDateGrant(true, ['recv_mode' => 'return']);

        $this->get('/receivings')->assertDontSee('id="recv_payment_terms"');

        $this->withSession(array_merge($this->session, ['recv_mode' => 'receive']));
        $this->setConfigValue('accounting_enabled', '0');

        $page = $this->get('/receivings');

        $page->assertDontSee('id="recv_invoice_date"');
        $page->assertDontSee('id="recv_payment_terms"');
    }

    public function testPostComplete_DatesThePurchaseByTheDayAndTheTermsOfTheRegisterAndPrintsThemOnTheReceipt(): void
    {
        $this->registerWithTheDateGrant();

        $this->post('/receivings/setInvoiceDate', ['recv_invoice_date' => $this->daysAgo(6)]);
        $this->carryTheTimingToTheNextRequest();
        $this->post('/receivings/setPaymentTerms', ['recv_payment_terms' => '10']);
        $this->carryTheTimingToTheNextRequest();

        $purchases = $this->countRows('receivings');
        $response  = $this->post('/receivings/complete', $this->completeForm());

        $this->assertSame($purchases + 1, $this->countRows('receivings'));

        $receivingId          = (int) $this->accountingDb()->table('receivings')->selectMax('receiving_id', 'last')->get()->getRow()->last;
        $this->receivingIds[] = $receivingId;
        $purchase             = $this->receivingRow($receivingId);

        $this->assertSame($this->daysAgo(6), $purchase->invoice_date);
        $this->assertSame(10, (int) $purchase->payment_terms_days);
        $this->assertSame(date('Y-m-d', strtotime('+4 days')), $purchase->due_date);
        $this->assertSame([$this->daysAgo(6)], array_values(array_unique(array_column($this->standingPurchase($receivingId), 'date'))));
        $response->assertSeeElement('#invoice_date');
        $response->assertSeeElement('#due_date');
        $response->assertSee(to_date(strtotime('-6 days')));
        $response->assertSee(to_date(strtotime('+4 days')));
        $this->assertNull(Services::session()->get('recv_invoice_date'), 'the register forgets the day of a purchase that was made');
        $this->assertNull(Services::session()->get('recv_payment_terms'));
    }

    public function testPostComplete_PrintsNeitherTheDayNorTheDueDateOnAPurchaseWithNeither(): void
    {
        $this->registerWithTheDateGrant();

        $response = $this->post('/receivings/complete', $this->completeForm());

        $this->receivingIds[] = (int) $this->accountingDb()->table('receivings')->selectMax('receiving_id', 'last')->get()->getRow()->last;

        $response->assertDontSeeElement('#invoice_date');
        $response->assertDontSeeElement('#due_date');
    }

    public function testPostComplete_RefusesADayInAClosedPeriodAndSaysWhyAndSavesNothing(): void
    {
        $this->registerWithTheDateGrant(true, ['recv_invoice_date' => $this->daysAgo(8)]);
        $this->closeThrough($this->daysAgo(5));

        $purchases = $this->countRows('receivings');

        $response = $this->post('/receivings/complete', $this->completeForm());

        $company = (string) $this->accountingDb()->table('companies')->select('name')->where('company_id', 1)->get()->getRow()->name;

        $response->assertSee('<div class="alert alert-danger">' . esc(lang('Accounting.posting_period_closed', [$this->daysAgo(8), $this->daysAgo(5), $company])) . '</div>');
        $this->assertSame($purchases, $this->countRows('receivings'));
        $this->assertSame(0, $this->countJournals());
        $this->assertCount(1, Services::session()->get('recv_cart'), 'the cart of the buyer is still there');
    }

    public function testPostComplete_RefusesTheDayOfABuyerWhoMayNoLongerTypeItAndSavesNothing(): void
    {
        $this->registerWithTheDateGrant(false, ['recv_invoice_date' => $this->daysAgo(3)]);

        $purchases = $this->countRows('receivings');

        $response = $this->post('/receivings/complete', $this->completeForm());

        $response->assertSee(lang('Accounting.error_permission_denied'));
        $this->assertSame($purchases, $this->countRows('receivings'));
    }

    public function testPostComplete_TakesNoTypedDayForAReturn(): void
    {
        $purchase = $this->purchaseOf('200.00');
        $line     = $this->accountingDb()->table('receivings_items')->where('receiving_id', $purchase)->get()->getRow();

        $this->loginAs(
            $this->buyer(null, ['items', 'receivings_invoice_date']),
            $this->cart([$this->cartLine((int) $line->item_id, 1, '-0.5', (string) $line->item_unit_price)], 'return', -1, $purchase) + ['recv_invoice_date' => $this->daysAgo(3), 'recv_payment_terms' => '9'],
        );

        $this->post('/receivings/complete', $this->completeForm());

        $return               = $this->receivingRow((int) $this->accountingDb()->table('receivings')->selectMax('receiving_id', 'last')->get()->getRow()->last);
        $this->receivingIds[] = (int) $return->receiving_id;

        $this->assertSame('return', $return->receiving_type);
        $this->assertNull($return->invoice_date);
        $this->assertNull($return->payment_terms_days);
    }

    public function testGetEdit_ShowsTheDayTheTermsAndTheDueDateOfAPostedPurchase(): void
    {
        $purchase = $this->purchaseDated('100.00', $this->daysAgo(6), 10);

        $this->loginAs($this->buyer());

        $page = $this->get('/receivings/edit/' . $purchase);

        $page->assertSee('id="invoice_date"');
        $page->assertSee(to_date(strtotime('-6 days')));
        $page->assertSee('id="payment_terms_days"');
        $page->assertSee('id="due_date"');
        $page->assertSee(to_date(strtotime('+4 days')));
    }

    public function testGetEdit_ShowsNoDayTermsOrDueDateOnAReturnOrWhileAccountingIsOff(): void
    {
        $purchase = $this->purchaseOf('200.00');
        $return   = $this->debitNoteOf($purchase, '50.00');

        $this->loginAs($this->buyer());

        $this->get('/receivings/edit/' . $return)->assertDontSee('id="payment_terms_days"');

        $this->setConfigValue('accounting_enabled', '0');

        $page = $this->get('/receivings/edit/' . $purchase);

        $page->assertDontSee('id="payment_terms_days"');
        $page->assertDontSee('id="due_date"');
    }

    public function testPostSave_ChangesThePaymentTermsOfAPostedPurchaseAndLeavesItsJournalsAsTheyWere(): void
    {
        $purchase = $this->purchaseDated('100.00', $this->daysAgo(6), 1);
        $before   = $this->purchaseJournals($purchase);

        $this->loginAs($this->buyer());

        $result = $this->jsonOf($this->post('/receivings/save/' . $purchase, $this->savedWith($purchase, $this->paymentFields()) + ['payment_terms_days' => '30']));

        $this->assertTrue($result['success']);
        $this->assertSame(30, (int) $this->receivingRow($purchase)->payment_terms_days);
        $this->assertSame(date('Y-m-d', strtotime('+24 days')), $this->receivingRow($purchase)->due_date);
        $this->assertSame($before, $this->purchaseJournals($purchase));
        $this->assertSame(1, $this->accountingDb()->table('accounting_audit_log')->where(['entity_type' => 'receiving', 'entity_id' => $purchase, 'action' => 'payment_terms_changed'])->countAllResults());
    }

    public function testPostSave_RefusesTermsThatAreNotAWholeNumberOfDaysAndSaysSo(): void
    {
        $purchase = $this->purchaseDated('100.00', $this->daysAgo(6), 1);

        $this->loginAs($this->buyer());

        $result = $this->jsonOf($this->post('/receivings/save/' . $purchase, $this->savedWith($purchase, $this->paymentFields()) + ['payment_terms_days' => 'soon']));

        $this->assertFalse($result['success']);
        $this->assertSame(lang('Accounting.error_payment_terms_invalid', [(string) 3650]), $result['message']);
        $this->assertSame(1, (int) $this->receivingRow($purchase)->payment_terms_days);
    }

    public function testPostSave_KeepsTheTermsOfAPurchaseWhenTheFormSendsNone(): void
    {
        $purchase = $this->purchaseDated('100.00', $this->daysAgo(6), 7);

        $this->loginAs($this->buyer());

        $result = $this->jsonOf($this->post('/receivings/save/' . $purchase, $this->savedWith($purchase, $this->paymentFields())));

        $this->assertTrue($result['success']);
        $this->assertSame(7, (int) $this->receivingRow($purchase)->payment_terms_days);
    }

    public function testGetReceipt_PrintsTheDayAndTheDueDateOnlyWhenThePurchaseHasThem(): void
    {
        $withTerms    = $this->purchaseDated('100.00', $this->daysAgo(6), 10);
        $withoutTerms = $this->purchaseDated('50.00', $this->daysAgo(2));
        $plain        = $this->purchaseOf('25.00');

        $this->loginAs($this->buyer());

        $page = $this->get('/receivings/receipt/' . $withTerms);

        $page->assertSeeElement('#invoice_date');
        $page->assertSee(to_date(strtotime('-6 days')));
        $page->assertSeeElement('#due_date');
        $page->assertSee(to_date(strtotime('+4 days')));

        $page = $this->get('/receivings/receipt/' . $withoutTerms);

        $page->assertSeeElement('#invoice_date');
        $page->assertDontSeeElement('#due_date');

        $page = $this->get('/receivings/receipt/' . $plain);

        $page->assertDontSeeElement('#invoice_date');
        $page->assertDontSeeElement('#due_date');
    }

    // ---------------------------------------------------------------------------------------
    // The purchase taxes of an item and the numbers of a supplier
    // ---------------------------------------------------------------------------------------

    private function addToRegister(int $itemId): string
    {
        $this->loginAs($this->buyer(), ['recv_mode' => 'receive', 'recv_stock_source' => self::LOCATION_ID, 'recv_supplier' => $this->supplierId]);
        $this->post('/receivings/add', ['item' => (string) $itemId])->assertStatus(200);

        return (string) session()->get('recv_cart')[1]['tax_percent'];
    }

    /**
     * @param list<array{string, string}> $salesTaxes name and rate
     * @param array<string, mixed>        $purchaseTaxes columns of the item
     */
    private function itemWithTaxes(array $salesTaxes, array $purchaseTaxes = []): int
    {
        $itemId = $this->item('0.00');

        foreach ($salesTaxes as [$name, $percent]) {
            $this->accountingDb()->table('items_taxes')->insert(['item_id' => $itemId, 'name' => $name, 'percent' => $percent]);
        }

        if ($purchaseTaxes !== []) {
            $this->accountingDb()->table('items')->where('item_id', $itemId)->update($purchaseTaxes);
        }

        return $itemId;
    }

    public function testPostAdd_PutsThePurchaseTaxOfTheItemOnTheLineInPlaceOfItsSalesTax(): void
    {
        $itemId = $this->itemWithTaxes([['A tax', '15.000']], ['purchase_tax_1_name' => 'Import', 'purchase_tax_1_percent' => '5.000']);

        $this->assertSame('5.000', $this->addToRegister($itemId));
    }

    public function testPostAdd_FollowsTheSalesTaxOfTheSameNumberForAPurchaseTaxThatIsNotSet(): void
    {
        $itemId = $this->itemWithTaxes([['A tax', '15.000'], ['B tax', '5.000']], ['purchase_tax_2_percent' => '2.000']);

        $this->assertSame('17.000', $this->addToRegister($itemId), 'The first follows the sales tax (15) and the second is its own (2)');
    }

    public function testPostAdd_PutsNoTaxOnTheLineOfAnItemWhosePurchaseTaxesAreSetToNothing(): void
    {
        $itemId = $this->itemWithTaxes([['A tax', '15.000'], ['B tax', '5.000']], ['purchase_tax_1_percent' => '0.000', 'purchase_tax_2_percent' => '0.000']);

        $this->assertSame('0.000', $this->addToRegister($itemId));
    }

    public function testPostAdd_KeepsTakingTheSalesTaxesOfAnItemThatSetsNoPurchaseTax(): void
    {
        $itemId = $this->itemWithTaxes([['A tax', '15.000'], ['B tax', '5.000']]);

        $this->assertSame('20.000', $this->addToRegister($itemId));
    }

    public function testPostComplete_SavesAndPostsTheTaxThePurchaseTaxOfTheItemGaveTheLine(): void
    {
        $itemId = $this->itemWithTaxes([['A tax', '15.000']], ['purchase_tax_1_percent' => '5.000']);

        $this->accountingDb()->table('items')->where('item_id', $itemId)->update(['cost_price' => '100.00']);
        $this->addToRegister($itemId);
        $this->loginAs($this->buyer(), ['recv_cart' => session()->get('recv_cart'), 'recv_mode' => 'receive', 'recv_supplier' => $this->supplierId, 'recv_stock_source' => self::LOCATION_ID]);
        $this->post('/receivings/complete', $this->completeForm())->assertSee('RECV ');

        $receivingId = (int) $this->accountingDb()->table('receivings')->selectMax('receiving_id', 'last')->get()->getRow()->last;
        $this->receivingIds[] = $receivingId;

        $this->assertSame('5.000', $this->accountingDb()->table('receivings_items')->where('receiving_id', $receivingId)->get()->getRow()->tax_percent);
        $this->assertSame(['inventory' => '100.00', 'tax_input' => '5.00', 'supplier' => '105.00'], [
            'inventory' => bcadd($this->balance($this->stockAccount()), '0', 2),
            'tax_input' => bcadd($this->balance($this->accountId('tax_input')), '0', 2),
            'supplier'  => bcsub('0', $this->balance($this->supplierAccount()), 2),
        ]);
    }

    public function testGetReceipt_KeepsTheTaxStoredOnALineWhateverThePurchaseTaxOfItsItemBecomes(): void
    {
        $itemId      = $this->itemWithTaxes([['A tax', '15.000']]);
        $receivingId = $this->buy([$this->purchaseLine($itemId, 0, '10', '100', '15')]);

        $this->accountingDb()->table('items')->where('item_id', $itemId)->update(['purchase_tax_1_percent' => '5.000']);
        $this->loginAs($this->buyer());

        $response = $this->get('/receivings/receipt/' . $receivingId);

        $response->assertStatus(200);
        $response->assertSee(to_currency('150'));
        $this->assertSame('15.000', $this->accountingDb()->table('receivings_items')->where('receiving_id', $receivingId)->get()->getRow()->tax_percent);
    }

    public function testPostDebitNote_TakesTheTaxStoredOnTheLinesWhateverThePurchaseTaxOfTheItemBecomes(): void
    {
        $itemId      = $this->itemWithTaxes([['A tax', '15.000']]);
        $receivingId = $this->buy([$this->purchaseLine($itemId, 0, '10', '100', '15')]);

        $this->accountingDb()->table('items')->where('item_id', $itemId)->update(['purchase_tax_1_percent' => '5.000']);
        $this->loginAs($this->buyer());
        $this->post('/receivings/debitNote/' . $receivingId)->assertStatus(200);

        $this->assertSame('15.000', (string) session()->get('recv_cart')[1]['tax_percent']);
    }

    public function testGetReceipt_ShowsTheTaxNumberAndTheCommercialRegistryOfTheSupplier(): void
    {
        $this->accountingDb()->table('suppliers')->where('person_id', $this->supplierId)->update(['tax_id' => '300123456700003', 'commercial_registry' => '1010123456']);

        $itemId      = $this->item('0.00');
        $receivingId = $this->buy([$this->purchaseLine($itemId, 0, '10', '50')]);
        $this->loginAs($this->buyer());

        $response = $this->get('/receivings/receipt/' . $receivingId);

        $response->assertSee(lang('Receivings.supplier_tax_id') . ': 300123456700003');
        $response->assertSee(lang('Receivings.supplier_commercial_registry') . ': 1010123456');
    }

    public function testGetReceipt_ShowsNeitherNumberForASupplierWhoHasNone(): void
    {
        $itemId      = $this->item('0.00');
        $receivingId = $this->buy([$this->purchaseLine($itemId, 0, '10', '50')]);
        $this->loginAs($this->buyer());

        $response = $this->get('/receivings/receipt/' . $receivingId);

        $response->assertDontSeeElement('#supplier_tax_id');
        $response->assertDontSeeElement('#supplier_commercial_registry');
    }

    public function testGetIndex_ShowsTheNumbersOfTheSupplierOfTheRegister(): void
    {
        $this->accountingDb()->table('suppliers')->where('person_id', $this->supplierId)->update(['tax_id' => '300123456700003', 'commercial_registry' => '1010123456']);

        $itemId = $this->item('0.00');
        $this->loginAs($this->buyer(), $this->cart([$this->cartLine($itemId, 1, '2', '100')]));

        $response = $this->get('/receivings');

        $response->assertSee('300123456700003');
        $response->assertSee('1010123456');
    }

    public function testPostComplete_ShowsTheNumbersOfTheSupplierOnTheReceiptItPrints(): void
    {
        $this->accountingDb()->table('suppliers')->where('person_id', $this->supplierId)->update(['tax_id' => '300123456700003', 'commercial_registry' => '1010123456']);

        $itemId = $this->item('0.00');
        $this->loginAs($this->buyer(), $this->cart([$this->cartLine($itemId, 1, '2', '100')]));

        $response = $this->post('/receivings/complete', $this->completeForm());

        $response->assertSeeElement('#supplier_commercial_registry');
        $response->assertSee('1010123456');

        $this->receivingIds[] = (int) $this->accountingDb()->table('receivings')->selectMax('receiving_id', 'last')->get()->getRow()->last;
    }

    public function testGetEdit_ShowsTheNumbersOfTheSupplier(): void
    {
        $this->accountingDb()->table('suppliers')->where('person_id', $this->supplierId)->update(['tax_id' => '300123456700003', 'commercial_registry' => '1010123456']);

        $itemId      = $this->item('0.00');
        $receivingId = $this->buy([$this->purchaseLine($itemId, 0, '10', '50')]);
        $this->loginAs($this->buyer());

        $response = $this->get('/receivings/edit/' . $receivingId);

        $response->assertSeeElement('#supplier_tax_id');
        $response->assertSee('1010123456');
    }

    // ---------------------------------------------------------------------------------------
    // Correcting the lines of a saved receiving
    // ---------------------------------------------------------------------------------------

    /**
     * What the lines form posts for the one line of a receiving of 10 units at 100: every number as it was
     * shown and as it was left, with what is given instead of it.
     *
     * @param array<string, string> $changes
     *
     * @return array{lines: array<int, array<string, string>>}
     */
    private function lineForm(int $itemId, array $changes = [], int $line = 0): array
    {
        $shown = ['quantity' => '10', 'price' => '100.00', 'discount' => '0.00', 'discount_type' => '0', 'tax_percent' => '0.000'];

        $fields = ['item_id' => (string) $itemId, 'line' => (string) $line, 'description' => 'Test item'];

        foreach ($shown as $name => $value) {
            $fields[$name]          = $value;
            $fields[$name . '_was'] = $value;
        }

        return ['lines' => [0 => $changes + $fields]];
    }

    public function testGetEdit_OffersTheLinesOfAReceivingToCorrect(): void
    {
        $itemId      = $this->item('0.00');
        $receivingId = $this->buy([$this->purchaseLine($itemId, 0, '10', '100', '15')]);
        $this->loginAs($this->buyer());

        $response = $this->get('/receivings/edit/' . $receivingId);

        $response->assertSeeElement('#receivings_lines_form');
        $response->assertSeeInField('lines[0][quantity]', '10');
        $response->assertSeeElement('#line_0_price');
        $response->assertSeeElement('#save_lines');
        $response->assertSee(lang('Receivings.lines_help'));
        $response->assertDontSee(lang('Receivings.lines_help_unposted'));
    }

    public function testGetEdit_SaysWhatSavingTheLinesDoesWhenTheBooksAreOff(): void
    {
        $this->setConfigValue('accounting_enabled', '0');

        $itemId      = $this->item('0.00');
        $receivingId = $this->buy([$this->purchaseLine($itemId, 0, '10', '100')]);
        $this->loginAs($this->buyer());

        $response = $this->get('/receivings/edit/' . $receivingId);

        $response->assertSeeElement('#receivings_lines_form');
        $response->assertSee(lang('Receivings.lines_help_unposted'));
    }

    public function testGetEdit_DoesNotOfferTheLinesOfAReceivingThatGoodsHaveGoneBackFrom(): void
    {
        $itemId      = $this->item('0.00');
        $receivingId = $this->buy([$this->purchaseLine($itemId, 0, '10', '100')]);

        $this->assertGreaterThan(0, $this->buy([$this->purchaseLine($itemId, 0, '-2', '100')], -1, null, $receivingId));
        $this->loginAs($this->buyer());

        $response = $this->get('/receivings/edit/' . $receivingId);

        $response->assertDontSeeElement('#receivings_lines_form');
        $response->assertSeeElement('#lines_locked_returns');
    }

    public function testGetEdit_DoesNotOfferTheLinesOfAReturn(): void
    {
        $itemId      = $this->item('0.00');
        $receivingId = $this->buy([$this->purchaseLine($itemId, 0, '10', '100')]);
        $returnId    = $this->buy([$this->purchaseLine($itemId, 0, '-2', '100')], -1, null, $receivingId);
        $this->loginAs($this->buyer());

        $response = $this->get('/receivings/edit/' . $returnId);

        $response->assertDontSeeElement('#receivings_lines_form');
        $response->assertDontSeeElement('#lines_locked_returns');
    }

    public function testGetEdit_ShowsTheTaxOfALineToWhoeverMayNotEditCostsButNotForEditing(): void
    {
        $itemId      = $this->item('0.00');
        $receivingId = $this->buy([$this->purchaseLine($itemId, 0, '10', '100', '15')]);
        $this->loginAs($this->buyer(null, []));

        $response = $this->get('/receivings/edit/' . $receivingId);

        $this->assertMatchesRegularExpression('/<input[^>]*id="line_0_tax"[^>]*readonly/', $response->getBody());
    }

    public function testGetEdit_ShowsTheTaxOfALineForEditingToWhoeverMayEditCosts(): void
    {
        $itemId      = $this->item('0.00');
        $receivingId = $this->buy([$this->purchaseLine($itemId, 0, '10', '100', '15')]);
        $this->loginAs($this->buyer());

        $response = $this->get('/receivings/edit/' . $receivingId);

        $response->assertSeeElement('#line_0_tax');
        $this->assertDoesNotMatchRegularExpression('/<input[^>]*id="line_0_tax"[^>]*readonly/', $response->getBody());
    }

    public function testGetEdit_OffersTheLinesOnlyForReadingToAnEmployeeWithoutTheGrantOfTheStockLocationOfALine(): void
    {
        $itemId      = $this->item('0.00');
        $receivingId = $this->buy([$this->purchaseLine($itemId, 0, '10', '100')]);
        $employee    = $this->createEmployee(grants: [
            ['permission_id' => 'receivings', 'menu_group' => 'home'],
            ['permission_id' => BranchGuard::ALL_BRANCHES, 'menu_group' => '--'],
        ]);

        $this->depotIn(1, $employee);
        $this->loginAs($employee);

        $response = $this->get('/receivings/edit/' . $receivingId);

        $response->assertSeeElement('#receivings_lines_form');
        $response->assertSeeElement('#lines_locked_location');
        $response->assertDontSeeElement('#save_lines');
        $response->assertSee(lang('Receivings.location_not_allowed'));
        $this->assertMatchesRegularExpression('/<fieldset id="receiving_lines" disabled>/', $response->getBody());
    }

    public function testGetEdit_OffersTheLinesOnlyForReadingWhenTheStockLocationOfALineWasDeleted(): void
    {
        $employee   = $this->buyer();
        $locationId = $this->depotIn(1, $employee);
        $itemId     = $this->item('0.00');

        $receivingId = $this->buy([$this->purchaseLine($itemId, 0, '10', '100', locationId: $locationId)]);

        $this->accountingDb()->table('stock_locations')->where('location_id', $locationId)->update(['deleted' => 1]);
        $this->loginAs($employee);

        $response = $this->get('/receivings/edit/' . $receivingId);

        $response->assertSeeElement('#lines_locked_location');
        $response->assertDontSeeElement('#save_lines');
    }

    public function testGetEdit_OffersTheSaveOfTheLinesToAnEmployeeWhoMayCorrectEveryLocationOfThem(): void
    {
        $itemId      = $this->item('0.00');
        $receivingId = $this->buy([$this->purchaseLine($itemId, 0, '10', '100')]);
        $this->loginAs($this->buyer());

        $response = $this->get('/receivings/edit/' . $receivingId);

        $response->assertSeeElement('#save_lines');
        $response->assertDontSeeElement('#lines_locked_location');
        $this->assertDoesNotMatchRegularExpression('/<fieldset id="receiving_lines" disabled>/', $response->getBody());
    }

    public function testGetEdit_CarriesTheWarningsForLinesAndDetailsThatAreNotSavedYet(): void
    {
        $itemId      = $this->item('0.00');
        $receivingId = $this->buy([$this->purchaseLine($itemId, 0, '10', '100')]);
        $this->loginAs($this->buyer());

        $body = $this->get('/receivings/edit/' . $receivingId)->getBody();

        $this->assertStringContainsString(json_encode(lang('Receivings.lines_unsaved')), $body);
        $this->assertStringContainsString(json_encode(lang('Receivings.details_unsaved')), $body);
        $this->assertStringContainsString(json_encode(lang('Receivings.lines_not_updated')), $body);
    }

    public function testPostSaveLines_CorrectsTheQuantityAndPostsRevisionTwo(): void
    {
        $itemId      = $this->item('0.00');
        $receivingId = $this->buy([$this->purchaseLine($itemId, 0, '10', '100')]);
        $this->loginAs($this->buyer());

        $result = $this->jsonOf($this->post('/receivings/saveLines/' . $receivingId, $this->lineForm($itemId, ['quantity' => '8'])));

        $this->assertTrue($result['success']);
        $this->assertSame(lang('Receivings.lines_updated'), $result['message']);
        $this->assertSame($receivingId, $result['id']);
        $this->assertSame('8.000', $this->storedLine($receivingId, $itemId)->quantity_purchased);
        $this->assertSame([1, 2], array_column($this->purchaseJournals($receivingId), 'revision'));
        $this->assertSame('8.000', $this->onHand($itemId));
        $this->assertSame('800.00', $this->owed());
        $this->assertStockTies();
        $this->assertSupplierTies();
    }

    public function testPostSaveLines_ReadsAFieldThatWasNotTouchedAsUnchangedWhateverThePrecisionItWasShownAt(): void
    {
        $itemId      = $this->item('0.00');
        $receivingId = $this->buy([$this->purchaseLine($itemId, 0, '2.5', '10.50')]);
        $this->loginAs($this->buyer());

        $form = $this->lineForm($itemId, ['quantity' => '3', 'quantity_was' => '3', 'price' => '11', 'price_was' => '11']);
        $result = $this->jsonOf($this->post('/receivings/saveLines/' . $receivingId, $form));

        $this->assertTrue($result['success']);
        $this->assertSame('2.500', $this->storedLine($receivingId, $itemId)->quantity_purchased, 'The 3 on the screen was 2.5 rounded to no decimals and was not touched');
        $this->assertSame('10.50', $this->storedLine($receivingId, $itemId)->item_unit_price);
        $this->assertCount(1, $this->purchaseJournals($receivingId), 'Nothing changed, so nothing was posted again');
    }

    public function testPostSaveLines_TakesAFieldTheEmployeeTouchedOnAScreenThatShowsFewerDecimals(): void
    {
        $itemId      = $this->item('0.00');
        $receivingId = $this->buy([$this->purchaseLine($itemId, 0, '2.5', '10.50')]);
        $this->loginAs($this->buyer());

        $form = $this->lineForm($itemId, ['quantity' => '2', 'quantity_was' => '3', 'price' => '11', 'price_was' => '11']);

        $this->assertTrue($this->jsonOf($this->post('/receivings/saveLines/' . $receivingId, $form))['success']);
        $this->assertSame('2.000', $this->storedLine($receivingId, $itemId)->quantity_purchased);
        $this->assertSame('10.50', $this->storedLine($receivingId, $itemId)->item_unit_price);
    }

    public function testPostSaveLines_TakesTheTaxOfALineOnlyFromWhoeverMayEditCosts(): void
    {
        $itemId      = $this->item('0.00');
        $receivingId = $this->buy([$this->purchaseLine($itemId, 0, '10', '100', '15')]);
        $form        = $this->lineForm($itemId, ['quantity' => '8', 'tax_percent' => '5', 'tax_percent_was' => '15.00']);

        $this->loginAs($this->buyer(null, []));

        $this->assertTrue($this->jsonOf($this->post('/receivings/saveLines/' . $receivingId, $form))['success']);
        $this->assertSame('15.000', $this->storedLine($receivingId, $itemId)->tax_percent, 'Whoever may not edit costs does not edit tax');
        $this->assertSame('8.000', $this->storedLine($receivingId, $itemId)->quantity_purchased);

        $this->loginAs($this->buyer());

        $this->assertTrue($this->jsonOf($this->post('/receivings/saveLines/' . $receivingId, $form))['success']);
        $this->assertSame('5.000', $this->storedLine($receivingId, $itemId)->tax_percent);
    }

    public function testPostSaveLines_TakesALineOutWhenTheFormMarksIt(): void
    {
        $first       = $this->item('0.00');
        $second      = $this->item('0.00');
        $receivingId = $this->buy([$this->purchaseLine($first, 0, '10', '100'), $this->purchaseLine($second, 1, '5', '40')]);
        $this->loginAs($this->buyer());

        $form                  = $this->lineForm($second, ['remove' => '1'], 1);
        $form['lines'][1]      = $this->lineForm($first)['lines'][0];
        $result                = $this->jsonOf($this->post('/receivings/saveLines/' . $receivingId, $form));

        $this->assertTrue($result['success']);
        $this->assertNull($this->storedLine($receivingId, $second, 1));
        $this->assertNotNull($this->storedLine($receivingId, $first, 0));
        $this->assertSame('1000.00', $this->owed());
        $this->assertSame('0.000', $this->onHand($second));
    }

    public function testPostSaveLines_RefusesALineThatIsNotValidAndChangesNothing(): void
    {
        $itemId      = $this->item('0.00');
        $receivingId = $this->buy([$this->purchaseLine($itemId, 0, '10', '100')]);
        $this->loginAs($this->buyer());

        foreach ([['quantity' => '0'], ['quantity' => '-3'], ['quantity' => 'abc'], ['price' => '-1'], ['discount' => '120', 'discount_type' => '0']] as $changes) {
            $result = $this->jsonOf($this->post('/receivings/saveLines/' . $receivingId, $this->lineForm($itemId, $changes)));

            $this->assertFalse($result['success'], json_encode($changes));
            $this->assertSame(lang('Accounting.error_purchase_edit_line_invalid'), $result['message']);
        }

        $this->assertSame('10.000', $this->storedLine($receivingId, $itemId)->quantity_purchased);
        $this->assertCount(1, $this->purchaseJournals($receivingId));
    }

    public function testPostSaveLines_SaysWhyTheBooksRefusedTheCorrection(): void
    {
        $itemId      = $this->item('0.00');
        $receivingId = $this->buy([$this->purchaseLine($itemId, 0, '10', '100')]);
        $this->loginAs($this->buyer());

        $this->closeThrough('2035-12-31');

        $result = $this->jsonOf($this->post('/receivings/saveLines/' . $receivingId, $this->lineForm($itemId, ['quantity' => '8'])));

        $this->assertFalse($result['success']);
        $this->assertStringContainsString('2035-12-31', $result['message']);
        $this->assertSame('10.000', $this->storedLine($receivingId, $itemId)->quantity_purchased);
        $this->assertSame('10.000', $this->onHand($itemId));
    }

    public function testPostSaveLines_SaysThatTheGoodsWereSoldWhenTheyWere(): void
    {
        $itemId      = $this->item('0.00');
        $receivingId = $this->buy([$this->purchaseLine($itemId, 0, '10', '100')]);

        $this->assertGreaterThan(0, $this->save([$this->line($itemId, 0, '7', '150', $this->exactCostOf($itemId))]));
        $this->loginAs($this->buyer());

        $result = $this->jsonOf($this->post('/receivings/saveLines/' . $receivingId, $this->lineForm($itemId, ['quantity' => '5'])));

        $this->assertFalse($result['success']);
        $this->assertSame(lang('Accounting.error_purchase_stock_would_go_negative', ['Test Item', 'stock']), $result['message']);
        $this->assertSame('3.000', $this->onHand($itemId));
    }

    public function testPostSaveLines_RefusesAReceivingThatGoodsHaveGoneBackFrom(): void
    {
        $itemId      = $this->item('0.00');
        $receivingId = $this->buy([$this->purchaseLine($itemId, 0, '10', '100')]);

        $this->assertGreaterThan(0, $this->buy([$this->purchaseLine($itemId, 0, '-2', '100')], -1, null, $receivingId));
        $this->loginAs($this->buyer());

        $result = $this->jsonOf($this->post('/receivings/saveLines/' . $receivingId, $this->lineForm($itemId, ['quantity' => '8'])));

        $this->assertFalse($result['success']);
        $this->assertSame(lang('Accounting.error_purchase_edit_has_returns'), $result['message']);
    }

    public function testPostSaveLines_RefusesAReceivingOfABranchTheEmployeeDoesNotWorkIn(): void
    {
        $itemId      = $this->item('0.00');
        $receivingId = $this->buy([$this->purchaseLine($itemId, 0, '10', '100')]);
        $this->loginAs($this->buyer([$this->anotherBranch()]));

        $response = $this->post('/receivings/saveLines/' . $receivingId, $this->lineForm($itemId, ['quantity' => '8']));

        $response->assertStatus(403);
        $this->assertSame('10.000', $this->storedLine($receivingId, $itemId)->quantity_purchased);
        $this->assertCount(1, $this->purchaseJournals($receivingId));
    }

    public function testPostSaveLines_RefusesAReceivingOfABranchEvenWhenItsStockLocationHasSinceMovedToTheEmployeesBranch(): void
    {
        $itemId     = $this->item('0.00');
        $branch     = $this->anotherBranch();
        $employee   = $this->buyer([$branch]);
        $locationId = $this->depotIn(1, $employee);

        $receivingId = $this->buy([$this->purchaseLine($itemId, 0, '10', '100', locationId: $locationId)]);

        $this->accountingDb()->table('stock_locations')->where('location_id', $locationId)->update(['branch_id' => $branch]);
        $this->loginAs($employee);

        $response = $this->post('/receivings/saveLines/' . $receivingId, $this->lineForm($itemId, ['quantity' => '8']));

        $response->assertStatus(403);
        $this->assertSame(lang('Receivings.branch_not_allowed'), json_decode((string) $response->getJSON(), true)['message'], 'The branch of the receiving decides, not the branch its stock location has now');
        $this->assertSame('10.000', $this->storedLine($receivingId, $itemId)->quantity_purchased);
        $this->assertCount(1, $this->purchaseJournals($receivingId));
    }

    public function testPostSaveLines_RefusesAStockLocationTheEmployeeHasNoGrantOn(): void
    {
        $itemId      = $this->item('0.00');
        $receivingId = $this->buy([$this->purchaseLine($itemId, 0, '10', '100')]);
        $employee    = $this->createEmployee(grants: [
            ['permission_id' => 'receivings', 'menu_group' => 'home'],
            ['permission_id' => BranchGuard::ALL_BRANCHES, 'menu_group' => '--'],
        ]);

        $this->depotIn(1, $employee);
        $this->loginAs($employee);

        $response = $this->post('/receivings/saveLines/' . $receivingId, $this->lineForm($itemId, ['quantity' => '8']));

        $response->assertStatus(403);
        $this->assertSame('10.000', $this->storedLine($receivingId, $itemId)->quantity_purchased);
    }

    public function testPostSaveLines_SendsAnEmployeeWithoutTheReceivingsModuleToTheNoAccessPage(): void
    {
        $itemId      = $this->item('0.00');
        $receivingId = $this->buy([$this->purchaseLine($itemId, 0, '10', '100')]);
        $employee    = $this->createEmployee(grants: [['permission_id' => 'sales', 'menu_group' => 'home'], ['permission_id' => BranchGuard::ALL_BRANCHES, 'menu_group' => '--']]);
        $this->loginAs($employee);

        $response = $this->post('/receivings/saveLines/' . $receivingId, $this->lineForm($itemId, ['quantity' => '8']));

        $this->assertStringContainsString('no_access', $response->getRedirectUrl());
        $this->assertSame('10.000', $this->storedLine($receivingId, $itemId)->quantity_purchased);
    }

    public function testPostSaveLines_ChangesTheLinesAndTheStockWhileTheBooksAreOff(): void
    {
        $this->setConfigValue('accounting_enabled', '0');

        $itemId      = $this->item('0.00');
        $receivingId = $this->buy([$this->purchaseLine($itemId, 0, '10', '100')]);
        $this->loginAs($this->buyer());

        $result = $this->jsonOf($this->post('/receivings/saveLines/' . $receivingId, $this->lineForm($itemId, ['quantity' => '8'])));

        $this->assertTrue($result['success']);
        $this->assertSame('8.000', $this->storedLine($receivingId, $itemId)->quantity_purchased);
        $this->assertSame('8.000', $this->onHand($itemId));
        $this->assertSame(0, $this->countRows('accounting_journals'));
    }

    public function testPostSaveLines_SaysItsWordsInArabicToABuyerWhoReadsArabic(): void
    {
        $itemId      = $this->item('0.00');
        $receivingId = $this->buy([$this->purchaseLine($itemId, 0, '10', '100')]);

        $this->assertGreaterThan(0, $this->buy([$this->purchaseLine($itemId, 0, '-2', '100')], -1, null, $receivingId));
        $this->loginAs($this->buyer(null, ['items'], ['language_code' => 'ar-EG', 'language' => 'arabic']));

        $result = $this->jsonOf($this->post('/receivings/saveLines/' . $receivingId, $this->lineForm($itemId, ['quantity' => '8'])));

        $this->assertStringContainsString('رُدَّت', $result['message']);
        $this->assertSame(lang('Accounting.error_purchase_edit_has_returns'), $result['message']);
    }

    public function testGetEdit_SaysItsWordsInArabicToABuyerWhoReadsArabic(): void
    {
        $itemId      = $this->item('0.00');
        $receivingId = $this->buy([$this->purchaseLine($itemId, 0, '10', '100')]);
        $this->loginAs($this->buyer(null, ['items'], ['language_code' => 'ar-EG', 'language' => 'arabic']));

        $response = $this->get('/receivings/edit/' . $receivingId);

        $response->assertSee('حفظ الأسطر');
        $response->assertDontSee('Save lines');
    }

    private function ownRecordsBuyer(): int
    {
        return $this->employeeWithRoles([$this->createRole([], 'own')], ['receivings', 'receivings_stock', 'reports_receivings', 'items', BranchGuard::ALL_BRANCHES]);
    }

    private function everyRecordBuyer(): int
    {
        return $this->employeeWithRoles([$this->createRole([], 'all')], ['receivings', 'receivings_stock', 'reports_receivings', 'items', BranchGuard::ALL_BRANCHES]);
    }

    private function purchaseMadeBy(int $employeeId): int
    {
        $previous         = $this->employeeId;
        $this->employeeId = $employeeId;

        try {
            return $this->purchaseOf('100.00');
        } finally {
            $this->employeeId = $previous;
        }
    }

    private function assertPageIsNotFound(string $url): void
    {
        try {
            $this->get($url);
            $this->fail("{$url} was shown to an employee who sees their own records only.");
        } catch (PageNotFoundException) {
            $this->addToAssertionCount(1);
        }
    }

    /**
     * @return array<string, array{string}>
     */
    public static function purchasePages(): array
    {
        return ['the receipt' => ['receipt'], 'the edit form' => ['edit']];
    }

    #[DataProvider('purchasePages')]
    public function testPurchasePages_AreNotFoundByIdForAnOwnRecordsEmployeeWhoGuessesAnotherEmployeesPurchase(string $page): void
    {
        $theirs = $this->purchaseMadeBy($this->everyRecordBuyer());

        $this->loginAs($this->ownRecordsBuyer());

        $this->assertPageIsNotFound("/receivings/{$page}/{$theirs}");
        $this->assertPageIsNotFound("/receivings/{$page}/999999");
    }

    #[DataProvider('purchasePages')]
    public function testPurchasePages_OpenTheOwnPurchaseOfAnOwnRecordsEmployeeAndAnyOneForAnEmployeeWhoSeesEverything(string $page): void
    {
        $own      = $this->ownRecordsBuyer();
        $everyone = $this->everyRecordBuyer();
        $mine     = $this->purchaseMadeBy($own);
        $theirs   = $this->purchaseMadeBy($everyone);

        $this->loginAs($own);
        $this->get("/receivings/{$page}/{$mine}")->assertStatus(200);

        $this->loginAs($everyone);
        $this->get("/receivings/{$page}/{$mine}")->assertStatus(200);
        $this->get("/receivings/{$page}/{$theirs}")->assertStatus(200);
    }

    public function testPostSave_RefusesAnotherEmployeesPurchaseWithTheAnswerOfAPurchaseThatDoesNotExist(): void
    {
        $theirs = $this->purchaseMadeBy($this->everyRecordBuyer());
        $form   = ['date' => $this->dateOf($theirs), 'comment' => 'changed', 'supplier_id' => '', 'employee_id' => (string) $this->employeeId, 'reference' => 'X'];

        $this->loginAs($this->ownRecordsBuyer());

        $foreign = $this->post('/receivings/save/' . $theirs, $form);
        $missing = $this->post('/receivings/save/999999', $form);

        $foreign->assertStatus(403);
        $this->assertSame(lang('Roles.record_unavailable'), $this->jsonOf($foreign)['message']);
        $this->assertSame($this->jsonOf($missing), $this->jsonOf($foreign));
        $this->assertSame('test', $this->receivingRow($theirs)->comment);
    }

    public function testPostDelete_RefusesAnotherEmployeesPurchaseAndLeavesItsJournalsAndStock(): void
    {
        $theirs  = $this->purchaseMadeBy($this->everyRecordBuyer());
        $journal = $this->standingPurchase($theirs);

        $this->loginAs($this->ownRecordsBuyer());

        $foreign = $this->post('/receivings/delete/' . $theirs);
        $missing = $this->post('/receivings/delete/999999');

        $foreign->assertStatus(403);
        $this->assertSame(lang('Roles.record_unavailable'), $this->jsonOf($foreign)['message']);
        $this->assertSame($this->jsonOf($missing), $this->jsonOf($foreign));
        $this->assertNotNull($this->receivingRow($theirs));
        $this->assertSame($journal, $this->standingPurchase($theirs));
    }

    public function testPostDelete_DeletesTheOwnPurchaseOfAnOwnRecordsEmployeeAndAnyOneForAnEmployeeWhoSeesEverything(): void
    {
        $own      = $this->ownRecordsBuyer();
        $everyone = $this->everyRecordBuyer();
        $mine     = $this->purchaseMadeBy($own);
        $other    = $this->purchaseMadeBy($own);

        $this->loginAs($own);
        $this->assertTrue($this->jsonOf($this->post('/receivings/delete/' . $mine))['success']);

        $this->loginAs($everyone);
        $this->assertTrue($this->jsonOf($this->post('/receivings/delete/' . $other))['success']);

        $this->assertNull($this->receivingRow($mine));
        $this->assertNull($this->receivingRow($other));
    }

    public function testPostSaveLines_RefusesAnotherEmployeesPurchaseWithThePlainRefusal(): void
    {
        $theirs = $this->purchaseMadeBy($this->everyRecordBuyer());

        $this->loginAs($this->ownRecordsBuyer());

        $response = $this->post('/receivings/saveLines/' . $theirs, ['quantity' => ['0' => '9']]);

        $response->assertStatus(403);
        $this->assertSame(lang('Roles.record_unavailable'), $this->jsonOf($response)['message']);
    }

    public function testPostDebitNote_RefusesAnotherEmployeesPurchaseWithThePlainRefusal(): void
    {
        $theirs = $this->purchaseMadeBy($this->everyRecordBuyer());

        $this->loginAs($this->ownRecordsBuyer());

        $response = $this->post('/receivings/debitNote/' . $theirs);

        $response->assertStatus(403);
        $this->assertSame(lang('Roles.record_unavailable'), $this->jsonOf($response)['message']);
    }

    public function testPostSetReturnOrigin_RefusesAnotherEmployeesPurchaseWithThePlainRefusal(): void
    {
        $theirs = $this->purchaseMadeBy($this->everyRecordBuyer());

        $this->loginAs($this->ownRecordsBuyer());

        $response = $this->post('/receivings/setReturnOrigin', ['recv_return_origin' => (string) $theirs]);

        $this->assertSame(lang('Roles.record_unavailable'), $this->jsonOf($response)['message']);
    }

    public function testGetIndex_StartsTheReceivingScreenInTheDefaultStockLocationOfTheEmployee(): void
    {
        $buyer  = $this->buyer();
        $first  = $this->depotIn(1, $buyer);
        $second = $this->depotIn(1, $buyer);

        (new DefaultLocations())->set($buyer, $second, null);

        $this->loginAs($buyer);
        $this->get('/receivings')->assertStatus(200);

        $this->assertSame($second, (int) Services::session()->get('recv_stock_source'));
        $this->assertNotSame($first, (int) Services::session()->get('recv_stock_source'));
    }
}
