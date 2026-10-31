<?php

namespace Tests\Controllers;

use App\Libraries\Accounting\AccountingGuard;
use App\Libraries\Accounting\EntityAccountService;
use App\Libraries\Accounting\OpeningBalanceService;
use App\Libraries\Accounting\OpeningStock;
use CodeIgniter\Exceptions\PageNotFoundException;
use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\DatabaseTestTrait;
use Tests\Support\AccountingControllerFixtureTrait;
use Tests\Support\ItemFixtureTrait;

class AccountingOpeningControllerTest extends CIUnitTestCase
{
    use DatabaseTestTrait;
    use AccountingControllerFixtureTrait;
    use ItemFixtureTrait;

    protected $migrate     = true;
    protected $migrateOnce = true;
    protected $refresh     = false;
    protected $namespace   = null;

    /** @var list<array<string, mixed>> */
    private array $shelfBackup = [];

    protected function setUp(): void
    {
        parent::setUp();

        $this->prepareAccounting();
        $this->setConfigValue('accounting_start_date', '2026-01-01');

        $this->shelfBackup = $this->accountingDb()->table('item_quantities')->get()->getResultArray();
        $this->accountingDb()->table('item_quantities')->emptyTable();
    }

    protected function tearDown(): void
    {
        $this->releaseOpenTransactions();

        $this->accountingDb()->table('item_quantities')->emptyTable();

        if ($this->shelfBackup !== []) {
            $this->accountingDb()->table('item_quantities')->insertBatch($this->shelfBackup);
        }

        $this->shelfBackup = [];
        $this->removeInsertedEntities();

        parent::tearDown();
    }

    /**
     * @param list<int>|null $branchIds
     */
    private function manager(?array $branchIds = null): int
    {
        $personId = $this->employeeWithGrants(['accounting_periods', AccountingGuard::OPENING_MANAGE], $branchIds);
        $this->actAs($personId);

        return $personId;
    }

    /**
     * @return array<string, mixed>
     */
    private function form(int $branchId = 1, string $amount = '1000.00', array $extra = []): array
    {
        return $extra + [
            'branch_id' => (string) $branchId,
            'lines'     => [
                ['account_id' => (string) $this->accountId('cash'), 'description' => 'Cash', 'debit' => $amount, 'credit' => ''],
                ['account_id' => (string) $this->accountId('retained_earnings'), 'description' => '', 'debit' => '', 'credit' => $amount],
            ],
        ];
    }

    private function shelves(string $cost, string $quantity, int $locationId = 1): int
    {
        $itemId = $this->createTestItem();

        $this->accountingDb()->table('items')->where('item_id', $itemId)->update(['cost_price' => $cost]);
        $this->accountingDb()->table('item_quantities')->replace(['item_id' => $itemId, 'location_id' => $locationId, 'quantity' => $quantity]);

        return $itemId;
    }

    /**
     * @return array{int, int} The branch and the stock location, with its account, that the first branch's employees may not see.
     */
    private function otherBranchLocation(): array
    {
        $branch   = $this->createBranch('B2');
        $location = $this->insertStockLocation('Other branch store');

        $this->accountingDb()->table('stock_locations')->where('location_id', $location)->update(['branch_id' => $branch]);
        (new EntityAccountService())->ensure(EntityAccountService::STOCK_LOCATION, $location, 'Other branch store');

        return [$branch, $location];
    }

    private function stockPost(int $locationId = 1, int $branchId = 1): array
    {
        return $this->jsonOf($this->post('/accounting_periods/opening/stock/post', ['branch_id' => (string) $branchId, 'location_id' => (string) $locationId]));
    }

    private function openingStockJournals(): int
    {
        return $this->accountingDb()->table('accounting_journals')->where('source_type', OpeningStock::SOURCE)->countAllResults();
    }

    public function testGetIndex_ShowsTheGridToAUserWithTheModuleGrant(): void
    {
        $this->actAs($this->employeeWithGrants(['accounting_periods']));

        $response = $this->get('/accounting_periods/opening');

        $response->assertStatus(200);
        $response->assertSee('opening_form');
    }

    public function testGetIndex_OffersTheButtonsOnlyToSomeoneWhoManagesOpeningBalances(): void
    {
        $this->actAs($this->employeeWithGrants(['accounting_periods']));

        $this->get('/accounting_periods/opening')->assertDontSee('id="save_draft"');

        $this->manager();

        $this->get('/accounting_periods/opening')->assertSee('id="save_draft"');
    }

    public function testGetIndex_WarnsWhenTheStartDateIsNotSet(): void
    {
        $this->setConfigValue('accounting_start_date', '');
        $this->manager();

        $response = $this->get('/accounting_periods/opening');

        $response->assertSee(lang('Accounting_periods.opening_no_start'));
        $response->assertDontSee('id="save_draft"');
    }

    public function testGetIndex_OffersOnlyAllowedAccounts(): void
    {
        $this->manager();

        $response = $this->get('/accounting_periods/opening');

        $response->assertSee(esc($this->accountingDb()->table('accounting_accounts')->where('system_key', 'cash')->get()->getRow()->name));
        $response->assertDontSee('data-code="4101"');
        $response->assertDontSee('data-code="1103"');
    }

    public function testGetIndex_ShowsTheBranchChoiceOnlyWhenThereAreSeveralBranches(): void
    {
        $this->manager();

        $this->get('/accounting_periods/opening')->assertDontSee('name="branch"');

        $this->createBranch('B2');

        $this->get('/accounting_periods/opening')->assertSee('name="branch"');
    }

    public function testGetIndex_IsNotFoundForABranchOutsideTheEmployeesScope(): void
    {
        $second = $this->createBranch('B2');
        $this->manager([1]);

        $this->expectException(PageNotFoundException::class);

        $this->get('/accounting_periods/opening?branch=' . $second);
    }

    public function testGetIndex_ShowsTheDraftLines(): void
    {
        $this->manager();
        (new OpeningBalanceService())->saveDraft(1, $this->form()['lines'], null);

        $response = $this->get('/accounting_periods/opening');

        $response->assertSee('value="1000"');
        $response->assertSee('id="discard_draft"');
    }

    public function testGetIndex_ShowsPostedBalancesReadOnlyWithTheReverseButton(): void
    {
        $this->manager();
        (new OpeningBalanceService())->post(1, $this->form()['lines'], false, null);

        $response = $this->get('/accounting_periods/opening');

        $response->assertSee(lang('Accounting_periods.opening_posted_title'));
        $response->assertSee('opening/reverse_form?branch=1');
        $response->assertDontSee('id="opening_form"');
    }

    public function testPostSaveDraft_StoresTheLines(): void
    {
        $this->manager();

        $result = $this->jsonOf($this->post('/accounting_periods/opening/save_draft', $this->form()));

        $this->assertTrue($result['success']);
        $this->assertNotNull((new OpeningBalanceService())->state(1, null)['draft']);
    }

    public function testPostSaveDraft_ReportsAnAccountThatCannotTakeAnOpeningBalance(): void
    {
        $this->manager();

        $result = $this->jsonOf($this->post('/accounting_periods/opening/save_draft', ['branch_id' => '1', 'lines' => [
            ['account_id' => (string) $this->accountId('sales_revenue'), 'debit' => '5.00', 'credit' => ''],
        ]]));

        $this->assertFalse($result['success']);
        $this->assertSame(lang('Accounting.error_opening_account_not_allowed', ['1']), $result['message']);
    }

    public function testPostPost_PostsABalancedOpeningAndRefusesAnUnbalancedOne(): void
    {
        $this->manager();

        $unbalanced = $this->jsonOf($this->post('/accounting_periods/opening/post', ['branch_id' => '1', 'lines' => [
            ['account_id' => (string) $this->accountId('cash'), 'debit' => '300.00', 'credit' => ''],
        ]]));

        $this->assertFalse($unbalanced['success']);
        $this->assertSame(lang('Accounting.error_opening_unbalanced', ['300.0000', '0.0000']), $unbalanced['message']);

        $posted = $this->jsonOf($this->post('/accounting_periods/opening/post', $this->form()));

        $this->assertTrue($posted['success']);
        $this->assertNotNull((new OpeningBalanceService())->state(1, null)['posted']);
    }

    public function testPostPost_PutsTheDifferenceOnTheOpeningAccountWhenAsked(): void
    {
        $this->manager();

        $result = $this->jsonOf($this->post('/accounting_periods/opening/post', ['branch_id' => '1', 'balance' => '1', 'lines' => [
            ['account_id' => (string) $this->accountId('cash'), 'debit' => '300.00', 'credit' => ''],
        ]]));

        $this->assertTrue($result['success']);
        $this->assertSame(2, $this->countRows('accounting_journal_lines'));
    }

    public function testPostPost_ReportsAClosedPeriod(): void
    {
        $this->manager();
        $this->closeThrough('2026-01-31');

        $result = $this->jsonOf($this->post('/accounting_periods/opening/post', $this->form()));

        $this->assertFalse($result['success']);
        $this->assertStringContainsString('2026-01-31', $result['message']);
    }

    public function testPostPost_RefusesABranchOutsideTheEmployeesScopeAndAMissingPermission(): void
    {
        $second = $this->createBranch('B2');
        $this->manager([1]);

        $this->assertFalse($this->jsonOf($this->post('/accounting_periods/opening/post', $this->form($second)))['success']);

        $this->actAs($this->employeeWithGrants(['accounting_periods']));

        $this->assertFalse($this->jsonOf($this->post('/accounting_periods/opening/post', $this->form()))['success']);
        $this->assertSame(0, $this->countRows('accounting_journals'));
    }

    public function testPostDiscard_RemovesTheDraft(): void
    {
        $this->manager();
        (new OpeningBalanceService())->saveDraft(1, $this->form()['lines'], null);

        $result = $this->jsonOf($this->post('/accounting_periods/opening/discard', ['branch_id' => '1']));

        $this->assertTrue($result['success']);
        $this->assertNull((new OpeningBalanceService())->state(1, null)['draft']);
    }

    public function testPostReverse_ReversesThePostedOpeningWithAReason(): void
    {
        $this->manager();
        (new OpeningBalanceService())->post(1, $this->form()['lines'], false, null);

        $missing  = $this->jsonOf($this->post('/accounting_periods/opening/reverse', ['branch_id' => '1', 'date' => '2026-01-05', 'reason' => '']));
        $reversed = $this->jsonOf($this->post('/accounting_periods/opening/reverse', ['branch_id' => '1', 'date' => '2026-01-05', 'reason' => 'Wrong counts']));

        $this->assertFalse($missing['success']);
        $this->assertTrue($reversed['success']);
        $this->assertNull((new OpeningBalanceService())->state(1, null)['posted']);
    }

    public function testGetReverseForm_IsNotFoundWithoutAPostedOpeningOrWithoutThePermission(): void
    {
        $this->manager();

        try {
            $this->get('/accounting_periods/opening/reverse_form?branch=1');
            $this->fail('Expected the form to be hidden without a posted opening.');
        } catch (PageNotFoundException) {
            $this->addToAssertionCount(1);
        }

        (new OpeningBalanceService())->post(1, $this->form()['lines'], false, null);

        $this->get('/accounting_periods/opening/reverse_form?branch=1')->assertSee('opening_reverse_form');

        $this->actAs($this->employeeWithGrants(['accounting_periods']));

        $this->expectException(PageNotFoundException::class);

        $this->get('/accounting_periods/opening/reverse_form?branch=1');
    }

    public function testGetIndex_ExplainsWhoGrantsThePermissionToSomeoneWhoCannotManage(): void
    {
        $this->actAs($this->employeeWithGrants(['accounting_periods']));

        $this->get('/accounting_periods/opening')->assertSee(lang('Accounting_periods.permission_note'));

        $this->manager();

        $this->get('/accounting_periods/opening')->assertDontSee(lang('Accounting_periods.permission_note'));
    }

    public function testGetIndex_ListsTheStockLocationsOfTheBranchWithTheirStateAndLeavesOtherBranchesOut(): void
    {
        $this->manager();
        $this->otherBranchLocation();
        $this->shelves('50.00', '10');

        $response = $this->get('/accounting_periods/opening');

        $response->assertSee('id="opening_stock"');
        $response->assertSee('id="stock_location_1"');
        $response->assertSee(lang('Accounting_periods.opening_stock_state_postable'));
        $response->assertSee('500.00');
        $response->assertDontSee('Other branch store');
    }

    public function testGetIndex_OffersThePostButtonsOnlyToSomeoneWhoManagesOpeningBalancesForStock(): void
    {
        $this->shelves('50.00', '10');
        $this->actAs($this->employeeWithGrants(['accounting_periods']));

        $response = $this->get('/accounting_periods/opening');

        $response->assertSee('id="stock_location_1"');
        $response->assertDontSee('stock-post" data-location');

        $this->manager();

        $this->get('/accounting_periods/opening')->assertSee('stock-post" data-location="1"');
    }

    public function testGetIndex_ExplainsWhatKeepsALocationFromBeingPosted(): void
    {
        $this->manager();
        $this->shelves('50.00', '10');
        $this->shelves('9.00', '-2');
        $this->accountingDb()->table('accounting_accounts')->where('entity_type', EntityAccountService::STOCK_LOCATION)->where('entity_id', 1)->delete();

        $response = $this->get('/accounting_periods/opening');

        $response->assertSee(lang('Accounting_periods.opening_stock_state_blocked'));
        $response->assertSee(lang('Accounting_periods.opening_stock_problem_account_missing'));
        $response->assertDontSee('stock-post" data-location');
    }

    public function testGetIndex_ShowsPostedStockWithTheReverseButtonAndNoPostButton(): void
    {
        $this->manager();
        $this->shelves('50.00', '10');
        (new OpeningStock())->post([1], null);

        $response = $this->get('/accounting_periods/opening');

        $response->assertSee(lang('Accounting_periods.opening_stock_state_already_posted'));
        $response->assertSee('500.00');
        $response->assertSee('opening/stock/reverse_form?location=1');
        $response->assertDontSee('stock-post" data-location');
    }

    public function testPostStockPost_PostsTheLocationOnceAndARetryPostsNothingMore(): void
    {
        $this->manager();
        $this->shelves('50.00', '10');

        $first = $this->stockPost();

        $this->assertTrue($first['success']);
        $this->assertSame(lang('Accounting_periods.opening_stock_posted', ['1']), $first['message']);
        $this->assertSame(1, $this->openingStockJournals());
        $this->assertSame(1, $this->accountingDb()->table('accounting_stock_entries')->where('source_type', OpeningStock::SOURCE)->countAllResults());

        $second = $this->stockPost();

        $this->assertFalse($second['success']);
        $this->assertSame(lang('Accounting_periods.opening_stock_nothing_posted'), $second['message']);
        $this->assertSame(1, $this->openingStockJournals());
        $this->assertSame(1, $this->accountingDb()->table('accounting_stock_entries')->where('source_type', OpeningStock::SOURCE)->countAllResults());
        $this->assertSame('10.000', bcadd((string) $this->accountingDb()->table('item_quantities')->get()->getRow()->quantity, '0', 3));
    }

    public function testPostStockPost_PostsEveryLocationThatIsReadyWhenNoneIsNamed(): void
    {
        $this->manager();
        $store = $this->insertStockLocation('Second store');
        (new EntityAccountService())->ensure(EntityAccountService::STOCK_LOCATION, $store, 'Second store');
        $this->shelves('50.00', '10');
        $this->shelves('20.00', '3', $store);
        $this->shelves('5.00', '0', 1);

        $result = $this->stockPost(0);

        $this->assertTrue($result['success']);
        $this->assertSame(lang('Accounting_periods.opening_stock_posted', ['2']), $result['message']);
        $this->assertSame(2, $this->openingStockJournals());
    }

    public function testPostStockPost_RefusesWithoutThePermissionToManageOpeningBalances(): void
    {
        $this->shelves('50.00', '10');
        $this->actAs($this->employeeWithGrants(['accounting_periods']));

        $result = $this->stockPost();

        $this->assertFalse($result['success']);
        $this->assertSame(0, $this->openingStockJournals());
        $this->assertSame(0, $this->accountingDb()->table('accounting_stock_entries')->countAllResults());
    }

    public function testPostStockPost_RefusesALocationOfABranchOutsideTheEmployeesScope(): void
    {
        [$branch, $location] = $this->otherBranchLocation();
        $this->shelves('50.00', '10');
        $this->shelves('50.00', '10', $location);
        $this->manager([1]);

        $named    = $this->stockPost($location, $branch);
        $smuggled = $this->stockPost($location, 1);
        $all      = $this->stockPost(0, $branch);

        $this->assertFalse($named['success']);
        $this->assertFalse($smuggled['success']);
        $this->assertFalse($all['success']);
        $this->assertSame(0, $this->openingStockJournals());
    }

    public function testPostStockPost_ReportsALocationThatNeedsAPersonAndPostsNothing(): void
    {
        $this->manager();
        $this->shelves('50.00', '10');
        $this->accountingDb()->table('accounting_accounts')->where('entity_type', EntityAccountService::STOCK_LOCATION)->where('entity_id', 1)->delete();

        $result = $this->stockPost();

        $this->assertFalse($result['success']);
        $this->assertSame(lang('Accounting_periods.opening_stock_problem_account_missing'), $result['message']);
        $this->assertSame(0, $this->openingStockJournals());
    }

    public function testPostStockPost_ReportsAClosedPeriodAndPostsNothing(): void
    {
        $this->manager();
        $this->shelves('50.00', '10');
        $this->closeThrough('2026-01-31');

        $result = $this->stockPost();

        $this->assertFalse($result['success']);
        $this->assertStringContainsString('2026-01-31', $result['message']);
        $this->assertSame(0, $this->openingStockJournals());
        $this->assertSame(0, $this->accountingDb()->table('accounting_stock_entries')->countAllResults());
    }

    public function testGetStockReverseForm_IsNotFoundWithoutPostedOpeningStockOrWithoutThePermission(): void
    {
        $this->manager();
        $this->shelves('50.00', '10');

        try {
            $this->get('/accounting_periods/opening/stock/reverse_form?location=1');
            $this->fail('Expected the form to be hidden without posted opening stock.');
        } catch (PageNotFoundException) {
            $this->addToAssertionCount(1);
        }

        (new OpeningStock())->post([1], null);

        $this->get('/accounting_periods/opening/stock/reverse_form?location=1')->assertSee('opening_stock_reverse_form');

        $this->actAs($this->employeeWithGrants(['accounting_periods']));

        $this->expectException(PageNotFoundException::class);

        $this->get('/accounting_periods/opening/stock/reverse_form?location=1');
    }

    public function testGetStockReverseForm_IsNotFoundForALocationOfABranchOutsideTheEmployeesScope(): void
    {
        [, $location] = $this->otherBranchLocation();
        $this->shelves('50.00', '10', $location);
        (new OpeningStock())->post([$location], null);
        $this->manager([1]);

        $this->expectException(PageNotFoundException::class);

        $this->get('/accounting_periods/opening/stock/reverse_form?location=' . $location);
    }

    public function testPostStockReverse_ReversesWithAReasonAndTheLocationCanBePostedAgain(): void
    {
        $this->manager();
        $this->shelves('50.00', '10');
        $this->stockPost();

        $missing = $this->jsonOf($this->post('/accounting_periods/opening/stock/reverse', ['branch_id' => '1', 'location_id' => '1', 'date' => '2026-01-05', 'reason' => '']));

        $this->assertFalse($missing['success']);
        $this->assertNotNull((new OpeningStock())->plan(1, null)->activeRevision);

        $reversed = $this->jsonOf($this->post('/accounting_periods/opening/stock/reverse', ['branch_id' => '1', 'location_id' => '1', 'date' => '2026-01-05', 'reason' => 'Wrong counts']));

        $this->assertTrue($reversed['success']);
        $this->assertSame(lang('Accounting_periods.opening_stock_reversed'), $reversed['message']);
        $this->assertNull((new OpeningStock())->plan(1, null)->activeRevision);

        $again = $this->stockPost();

        $this->assertTrue($again['success']);
        $this->assertSame(2, (new OpeningStock())->plan(1, null)->activeRevision);
    }

    public function testPostStockReverse_RefusesWithoutThePermissionAndLeavesThePostingAlone(): void
    {
        $this->manager();
        $this->shelves('50.00', '10');
        $this->stockPost();
        $journals = $this->countRows('accounting_journals');

        $this->actAs($this->employeeWithGrants(['accounting_periods']));

        $result = $this->jsonOf($this->post('/accounting_periods/opening/stock/reverse', ['branch_id' => '1', 'location_id' => '1', 'date' => '2026-01-05', 'reason' => 'No right']));

        $this->assertFalse($result['success']);
        $this->assertSame($journals, $this->countRows('accounting_journals'));
        $this->assertNotNull((new OpeningStock())->plan(1, null)->activeRevision);
    }

    public function testGetIndex_ShowsALocationPostedWithItemsLeftOutAsPartiallyPostedWithThemListedAndNeverAsDone(): void
    {
        $this->manager();
        $this->shelves('50.00', '10');
        $this->shelves('3.00', '-3');
        (new OpeningStock())->post([1], null);

        $response = $this->get('/accounting_periods/opening');

        $response->assertSee(lang('Accounting_periods.opening_stock_state_partially_posted'));
        $response->assertSee(lang('Accounting_periods.opening_stock_problem_negative_stock', ['Test Item', to_quantity_decimals('-3')]));
        $response->assertSee(lang('Accounting_periods.opening_stock_partial_hint'));
        $response->assertSee('opening/stock/reverse_form?location=1');
        $response->assertDontSee('badge text-bg-success">' . lang('Accounting_periods.opening_stock_state_already_posted'));
        $response->assertDontSee('differences_form?location=1');
    }

    public function testGetIndex_OffersToPostTheDifferencesOfALocationThatCannotPostAnythingElse(): void
    {
        $this->shelves('3.00', '-3');
        $this->actAs($this->employeeWithGrants(['accounting_periods']));

        $this->get('/accounting_periods/opening')->assertDontSee('differences_form?location=1');

        $this->manager();

        $response = $this->get('/accounting_periods/opening');

        $response->assertSee(lang('Accounting_periods.opening_stock_state_blocked'));
        $response->assertDontSee(lang('Accounting_periods.opening_stock_state_nothing_to_post'));
        $response->assertSee('differences_form?location=1');
        $response->assertDontSee('stock-post" data-location');
    }

    public function testGetStockDifferencesForm_ListsTheItemsLeftOutAndAsksForAReason(): void
    {
        $this->manager();
        $this->shelves('3.00', '-3');

        $response = $this->get('/accounting_periods/opening/stock/differences_form?location=1');

        $response->assertSee('opening_stock_differences_form');
        $response->assertSee('Test Item');
        $response->assertSee('-9.00');
        $response->assertSee('name="reason"');
        $response->assertSee('name="with_differences" value="1"');
    }

    public function testGetStockDifferencesForm_IsNotFoundWithoutDifferencesWithoutThePermissionAndForAPostedLocation(): void
    {
        $this->manager();
        $this->shelves('50.00', '10');

        try {
            $this->get('/accounting_periods/opening/stock/differences_form?location=1');
            $this->fail('Expected the form to be hidden when nothing was left out.');
        } catch (PageNotFoundException) {
            $this->addToAssertionCount(1);
        }

        $this->shelves('3.00', '-3');
        (new OpeningStock())->post([1], null);

        try {
            $this->get('/accounting_periods/opening/stock/differences_form?location=1');
            $this->fail('Expected the form to be hidden once the opening stock stands.');
        } catch (PageNotFoundException) {
            $this->addToAssertionCount(1);
        }

        (new OpeningStock())->reverse(1, '2026-01-05', 'Taking in the items left out', null);
        $this->get('/accounting_periods/opening/stock/differences_form?location=1')->assertSee('opening_stock_differences_form');

        $this->actAs($this->employeeWithGrants(['accounting_periods']));

        $this->expectException(PageNotFoundException::class);

        $this->get('/accounting_periods/opening/stock/differences_form?location=1');
    }

    public function testPostStockPost_SaysHowManyLocationsAreStillPartlyPostedAndPostsTheItemsLeftOutOnlyWhenAskedToWithAReason(): void
    {
        $this->manager();
        $this->shelves('50.00', '10');
        $owed = $this->shelves('3.00', '-3');

        $partly = $this->stockPost();

        $this->assertTrue($partly['success']);
        $this->assertSame(lang('Accounting_periods.opening_stock_posted_partly', ['1', '1']), $partly['message']);
        $this->assertSame(0, $this->accountingDb()->table('accounting_stock_entries')->where('source_type', OpeningStock::SOURCE)->where('item_id', $owed)->countAllResults());

        $this->post('/accounting_periods/opening/stock/reverse', ['branch_id' => '1', 'location_id' => '1', 'date' => '2026-01-05', 'reason' => 'Taking in the items left out']);

        $everyone = $this->jsonOf($this->post('/accounting_periods/opening/stock/post', ['branch_id' => '1', 'location_id' => '0', 'with_differences' => '1', 'reason' => 'Counted']));

        $this->assertTrue($everyone['success']);
        $this->assertSame(lang('Accounting_periods.opening_stock_posted_partly', ['1', '1']), $everyone['message'], 'Naming no location never settles');

        $this->post('/accounting_periods/opening/stock/reverse', ['branch_id' => '1', 'location_id' => '1', 'date' => '2026-01-06', 'reason' => 'Taking in the items left out']);

        $noReason = $this->jsonOf($this->post('/accounting_periods/opening/stock/post', ['branch_id' => '1', 'location_id' => '1', 'with_differences' => '1', 'reason' => '']));

        $this->assertFalse($noReason['success']);
        $this->assertSame(lang('Accounting.error_reason_required'), $noReason['message']);

        $settled = $this->jsonOf($this->post('/accounting_periods/opening/stock/post', ['branch_id' => '1', 'location_id' => '1', 'with_differences' => '1', 'reason' => 'Counted: the shelves are right']));

        $this->assertTrue($settled['success']);
        $this->assertSame(lang('Accounting_periods.opening_stock_posted', ['1']), $settled['message']);
        $this->assertSame(1, $this->accountingDb()->table('accounting_stock_entries')->where('source_type', OpeningStock::SOURCE)->where('item_id', $owed)->where('kind', 'posting')->where('revision', 3)->countAllResults());
        $this->assertNull((new OpeningStock())->plan(1, null)->differences[0] ?? null);
    }

    public function testPostStockPost_RefusesToSettleTheDifferencesWithoutThePermission(): void
    {
        $this->shelves('3.00', '-3');
        $this->actAs($this->employeeWithGrants(['accounting_periods']));

        $result = $this->jsonOf($this->post('/accounting_periods/opening/stock/post', ['branch_id' => '1', 'location_id' => '1', 'with_differences' => '1', 'reason' => 'Counted']));

        $this->assertFalse($result['success']);
        $this->assertSame(0, $this->openingStockJournals());
        $this->assertSame(0, $this->accountingDb()->table('accounting_stock_entries')->countAllResults());
    }

    private function setItemCost(int $itemId, string $cost): void
    {
        $this->accountingDb()->table('items')->where('item_id', $itemId)->update(['cost_price' => $cost]);
    }

    public function testGetIndex_OffersToPostAgainAtTheCurrentCostOnlyForPostedStockToSomeoneWhoManages(): void
    {
        $this->manager();
        $this->shelves('50.00', '10');

        $this->get('/accounting_periods/opening')->assertDontSee('stock-reprice');

        $this->stockPost();

        $listing = $this->get('/accounting_periods/opening');

        $listing->assertSee('stock-reprice');
        $listing->assertSee('reprice_form?location=1');

        $this->actAs($this->employeeWithGrants(['accounting_periods']));

        $this->get('/accounting_periods/opening')->assertDontSee('stock-reprice');
    }

    public function testGetStockRepriceForm_ShowsTheCostBeforeAndAfterTheRevisionsAndAskForAReason(): void
    {
        $this->manager();
        $item = $this->shelves('50.00', '10');
        $this->stockPost();
        $this->setItemCost($item, '60.00');

        $response = $this->get('/accounting_periods/opening/stock/reprice_form?location=1');

        foreach (['opening_stock_reprice_form', '50.0000', '60.0000', 'name="reason"', 'name="plan"', 'revisions_table'] as $text) {
            $response->assertSee($text);
        }
    }

    public function testGetStockRepriceForm_ExplainsWhatStopsItAndOffersNoFormWhenLaterMovementsExist(): void
    {
        $this->manager();
        $item = $this->shelves('50.00', '10');
        $this->stockPost();
        $this->setItemCost($item, '60.00');
        $this->accountingDb()->table('accounting_stock_entries')->insert([
            'company_id' => 1, 'branch_id' => 1, 'source_type' => 'sale', 'source_id' => 999, 'entry_type' => 'cost', 'revision' => 1, 'line_no' => 1, 'kind' => 'posting',
            'entry_date' => '2026-02-01', 'item_id' => $item, 'location_id' => 1, 'quantity' => '-1.000', 'value' => '-50.0000', 'journal_id' => null,
        ]);

        $response = $this->get('/accounting_periods/opening/stock/reprice_form?location=1');

        $response->assertSee(lang('Accounting_periods.opening_stock_reprice_block_later_movements', ['1']));
        $response->assertDontSee('opening_stock_reprice_form');
    }

    public function testGetStockRepriceForm_IsNotFoundWithoutPostedStockWithoutThePermissionAndOutsideTheScope(): void
    {
        $this->manager();
        $this->shelves('50.00', '10');

        foreach (['/accounting_periods/opening/stock/reprice_form?location=1', '/accounting_periods/opening/stock/reprice_form?location=999999'] as $url) {
            try {
                $this->get($url);
                $this->fail('Expected the form to be hidden.');
            } catch (PageNotFoundException) {
                $this->addToAssertionCount(1);
            }
        }

        [, $location] = $this->otherBranchLocation();
        $this->shelves('50.00', '10', $location);
        (new OpeningStock())->post([1, $location], null);
        $this->manager([1]);

        try {
            $this->get('/accounting_periods/opening/stock/reprice_form?location=' . $location);
            $this->fail('A location of another branch must be hidden.');
        } catch (PageNotFoundException) {
            $this->addToAssertionCount(1);
        }

        $this->actAs($this->employeeWithGrants(['accounting_periods']));

        $this->expectException(PageNotFoundException::class);

        $this->get('/accounting_periods/opening/stock/reprice_form?location=1');
    }

    public function testPostStockReprice_PostsTheOpeningStockAgainAtTheCurrentCostWithAReasonAndOnlyOnce(): void
    {
        $this->manager();
        $item = $this->shelves('50.00', '10');
        $this->stockPost();
        $this->setItemCost($item, '60.00');
        $plan = (new OpeningStock())->repricePlan(1, null)->fingerprint();

        $missing = $this->jsonOf($this->post('/accounting_periods/opening/stock/reprice', ['branch_id' => '1', 'location_id' => '1', 'plan' => $plan, 'reason' => '']));

        $this->assertFalse($missing['success']);
        $this->assertSame(1, (new OpeningStock())->plan(1, null)->activeRevision);

        $done = $this->jsonOf($this->post('/accounting_periods/opening/stock/reprice', ['branch_id' => '1', 'location_id' => '1', 'plan' => $plan, 'reason' => 'Cost corrected']));

        $this->assertTrue($done['success']);
        $this->assertSame(lang('Accounting_periods.opening_stock_repriced'), $done['message']);
        $this->assertSame(2, (new OpeningStock())->plan(1, null)->activeRevision);
        $this->assertSame('600.00', bcadd((new OpeningStock())->plan(1, null)->postedValue(), '0', 2));

        $again = $this->jsonOf($this->post('/accounting_periods/opening/stock/reprice', ['branch_id' => '1', 'location_id' => '1', 'plan' => $plan, 'reason' => 'Cost corrected']));

        $this->assertFalse($again['success'], 'A second request with the same plan is refused: the plan it saw is gone');
        $this->assertSame(2, (new OpeningStock())->plan(1, null)->activeRevision);
    }

    public function testPostStockReprice_RefusesWithoutThePermissionAndOutsideTheScopeAndLeavesThePostingAlone(): void
    {
        $this->manager();
        $item = $this->shelves('50.00', '10');
        $this->stockPost();
        $this->setItemCost($item, '60.00');
        $plan     = (new OpeningStock())->repricePlan(1, null)->fingerprint();
        $journals = $this->countRows('accounting_journals');

        $this->actAs($this->employeeWithGrants(['accounting_periods']));

        $refused = $this->jsonOf($this->post('/accounting_periods/opening/stock/reprice', ['branch_id' => '1', 'location_id' => '1', 'plan' => $plan, 'reason' => 'No right']));

        $this->assertFalse($refused['success']);

        [, $location] = $this->otherBranchLocation();
        $other        = $this->shelves('50.00', '10', $location);
        (new OpeningStock())->post([$location], null);
        $this->setItemCost($other, '60.00');
        $otherPlan = (new OpeningStock())->repricePlan($location, null);
        $this->manager([1]);

        $this->assertTrue($otherPlan->allowed(), 'With the right plan and the right of the manager the correction would go through');

        $outside = $this->jsonOf($this->post('/accounting_periods/opening/stock/reprice', ['branch_id' => '1', 'location_id' => (string) $location, 'plan' => $otherPlan->fingerprint(), 'reason' => 'Smuggled']));

        $this->assertFalse($outside['success']);
        $this->assertSame(lang('Branches.error_not_allowed'), $outside['message']);
        $this->assertSame($journals + 1, $this->countRows('accounting_journals'), 'Only the opening of the other location was posted by the test itself');
        $this->assertSame(1, (new OpeningStock())->plan($location, null)->activeRevision);
    }

    public function testPostStockReprice_ReportsALaterMovementAndAClosedPeriodAndChangesNothing(): void
    {
        $this->manager();
        $item = $this->shelves('50.00', '10');
        $this->stockPost();
        $this->setItemCost($item, '60.00');
        $plan     = (new OpeningStock())->repricePlan(1, null)->fingerprint();
        $journals = $this->countRows('accounting_journals');

        $this->accountingDb()->table('accounting_stock_entries')->insert([
            'company_id' => 1, 'branch_id' => 1, 'source_type' => 'sale', 'source_id' => 9001, 'entry_type' => 'cost', 'revision' => 1, 'line_no' => 1, 'kind' => 'posting',
            'entry_date' => '2026-02-01', 'item_id' => $item, 'location_id' => 1, 'quantity' => '-1.000', 'value' => '0.0000', 'journal_id' => null,
        ]);

        $later = $this->jsonOf($this->post('/accounting_periods/opening/stock/reprice', ['branch_id' => '1', 'location_id' => '1', 'plan' => $plan, 'reason' => 'After a sale']));

        $this->assertFalse($later['success']);
        $this->assertSame(lang('Accounting.error_opening_stock_later_movements', ['1']), $later['message']);
        $this->assertSame($journals, $this->countRows('accounting_journals'));
        $this->assertSame(1, (new OpeningStock())->plan(1, null)->activeRevision);

        $this->accountingDb()->table('accounting_stock_entries')->where('source_type', 'sale')->where('source_id', 9001)->delete();
        $this->closeThrough('2026-01-31');

        $closed = $this->jsonOf($this->post('/accounting_periods/opening/stock/reprice', ['branch_id' => '1', 'location_id' => '1', 'plan' => $plan, 'reason' => 'Late']));

        $this->assertFalse($closed['success']);
        $this->assertStringContainsString('2026-01-31', $closed['message']);
        $this->assertSame($journals, $this->countRows('accounting_journals'));
        $this->assertSame(1, (new OpeningStock())->plan(1, null)->activeRevision);
    }

    public function testGetIndex_NamesADeletedLocationThatStillHoldsStockAndNothingWhenNoneDoes(): void
    {
        $this->manager();
        $item = $this->shelves('50.00', '10');
        $gone = $this->insertStockLocation('Closed store');
        $this->accountingDb()->table('item_quantities')->replace(['item_id' => $item, 'location_id' => $gone, 'quantity' => '6']);

        $this->get('/accounting_periods/opening')->assertDontSee('stock_deleted_notice');

        $this->accountingDb()->table('stock_locations')->where('location_id', $gone)->update(['deleted' => 1]);

        $page = $this->get('/accounting_periods/opening');

        $page->assertSee('stock_deleted_notice');
        $page->assertSee('Closed store');
        $page->assertSee(lang('Accounting_periods.opening_stock_deleted_notice', ['Closed store', '1', '6']));
    }
}
