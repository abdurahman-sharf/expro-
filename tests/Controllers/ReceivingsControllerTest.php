<?php

namespace Tests\Controllers;

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

    private function anotherBranch(): int
    {
        return $this->createBranch('R' . random_int(100, 999), 'Receiving branch');
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
}
