<?php

namespace Tests\Controllers;

use App\Libraries\Stock\StockPermissions;
use App\Libraries\Stock\StockPermitSources;
use App\Libraries\Stock\Stocktake;
use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\DatabaseTestTrait;
use CodeIgniter\Test\FeatureTestTrait;
use Config\Database;
use Tests\Support\AccountingFixtureTrait;
use Tests\Support\EmployeeFixtureTrait;
use Tests\Support\ItemFixtureTrait;
use Tests\Support\PurchaseAccountingFixtureTrait;
use Tests\Support\SaleAccountingFixtureTrait;
use Tests\Support\StockDocumentFixtureTrait;

/**
 * The screens of the stocktakes: the list, the form that starts a count, the sheet where counts are
 * entered and the stocktake approved or cancelled, and the count sheet to print, with who may do what.
 */
class StocktakesControllerTest extends CIUnitTestCase
{
    use DatabaseTestTrait;
    use FeatureTestTrait;
    use AccountingFixtureTrait;
    use EmployeeFixtureTrait;
    use ItemFixtureTrait;
    use PurchaseAccountingFixtureTrait;
    use SaleAccountingFixtureTrait;
    use StockDocumentFixtureTrait;

    protected $migrate     = true;
    protected $migrateOnce = true;
    protected $refresh     = false;
    protected $namespace   = null;

    private Stocktake $takes;

    public static function setUpBeforeClass(): void
    {
        $seeder = Database::seeder('tests');
        $seeder->call('TestDatabaseBootstrapSeeder');
    }

    protected function setUp(): void
    {
        parent::setUp();

        $this->startStockDocuments();
        $this->takes = new Stocktake();
    }

    protected function tearDown(): void
    {
        $this->stopStockDocuments();
        $this->stopBuying();

        parent::tearDown();
    }

    /**
     * @param list<string>   $permissions
     * @param list<int>|null $branchIds
     */
    private function actAsWith(array $permissions, ?array $branchIds = null): int
    {
        $personId = $this->keeper($branchIds, $permissions);

        $this->withSession(['person_id' => $personId, 'menu_group' => 'home']);

        return $personId;
    }

    private function actAsKeeper(): int
    {
        return $this->actAsWith([StockPermissions::MODULE, StockPermissions::STOCKTAKE_MANAGE, StockPermissions::STOCKTAKE_APPROVE, 'items_stock']);
    }

    /**
     * @return array<string, mixed>
     */
    private function postJson(string $url, array $data = []): array
    {
        return json_decode($this->post($url, $data)->getJSON(), true);
    }

    private function started(int $itemId, array $more = []): int
    {
        return $this->takes->create(['location_id' => self::LOCATION_ID, 'date' => date('Y-m-d'), 'item_ids' => [$itemId], ...$more], $this->keeperId);
    }

    public function testGetIndex_ListsTheStocktakesAndOffersTheNewOneToWhoMayManageThem(): void
    {
        $stocktakeId = $this->started($this->stocked('10', '50'));

        $this->actAsKeeper();

        $page = $this->get('/stock_documents/stocktakes');

        $page->assertStatus(200);
        $page->assertSee('id="stocktake_' . $stocktakeId . '"');
        $page->assertSee('id="stocktake_new"');
        $page->assertSee(lang('Stock_documents.status_counting'));
        $page->assertSee('id="stock_tab_stocktakes"');
    }

    public function testGetIndex_OffersNothingToChangeToWhoMayOnlyLookAndRedirectsWhoLacksTheModule(): void
    {
        $this->started($this->stocked('10', '50'));

        $this->actAsWith([StockPermissions::MODULE, 'items_stock']);

        $page = $this->get('/stock_documents/stocktakes');

        $page->assertStatus(200);
        $page->assertDontSee('id="stocktake_new"');

        $this->actAsWith([]);

        $this->assertNotSame(200, $this->get('/stock_documents/stocktakes')->response()->getStatusCode());
    }

    public function testGetIndex_ShowsOnlyTheBranchesOfTheEmployeeFiltersAndPages(): void
    {
        $other  = $this->createBranch();
        $store  = $this->locationOfBranch($other, 'Branch store');
        $this->allowLocation($store, $this->keeperId);
        $itemId = $this->stocked('10', '50');

        $mine   = $this->started($itemId);
        $theirs = $this->takes->create(['location_id' => $store, 'date' => date('Y-m-d')], $this->keeperId);
        $this->takes->cancel($theirs, 'x', $this->keeperId);

        $this->actAsWith([StockPermissions::MODULE], [$other]);

        $page = $this->get('/stock_documents/stocktakes');

        $page->assertSee('id="stocktake_' . $theirs . '"');
        $page->assertDontSee('id="stocktake_' . $mine . '"');
        $page->assertSee('id="stocktakes_scope"');

        $this->actAsWith([StockPermissions::MODULE]);

        $filtered = $this->get('/stock_documents/stocktakes?status=cancelled');

        $filtered->assertSee('id="stocktake_' . $theirs . '"');
        $filtered->assertDontSee('id="stocktake_' . $mine . '"');
        $filtered->assertDontSee('id="stocktakes_scope"');
    }

    public function testGetForm_ServesTheFormToWhoMayManageStocktakesAndRefusesEveryoneElse(): void
    {
        $this->actAsKeeper();

        $form = $this->get('/stock_documents/stocktakes/form');

        $form->assertStatus(200);
        $form->assertSee('name="location_id"');
        $form->assertSee('name="count_date"');
        $form->assertSee('name="all_items"');

        $this->actAsWith([StockPermissions::MODULE, 'items_stock']);

        $this->assertSame(403, $this->get('/stock_documents/stocktakes/form')->response()->getStatusCode());
    }

    public function testPostSaveStocktakeAndTheSheet_StartTheCountAndShowWhatTheSystemFroze(): void
    {
        $itemId = $this->stocked('10', '50');

        $this->actAsKeeper();

        $answer = $this->postJson('/stock_documents/stocktakes/save', ['location_id' => (string) self::LOCATION_ID, 'count_date' => date('Y-m-d'), 'note' => 'year end', 'all_items' => '0']);

        $this->assertTrue($answer['success']);
        $this->assertSame(lang('Stock_documents.stocktake_started'), $answer['message']);

        $stocktakeId = (int) $answer['id'];

        $add = $this->postJson('/stock_documents/stocktakes/add_items/' . $stocktakeId, ['item_ids' => [(string) $itemId]]);

        $this->assertTrue($add['success']);

        $sheet = $this->get('/stock_documents/stocktakes/view/' . $stocktakeId);

        $sheet->assertStatus(200);
        $sheet->assertSee('id="stocktake_line_' . $itemId . '"');
        $sheet->assertSee('name="counted[' . $itemId . ']"');
        $sheet->assertSee('id="stocktake_approve"');
        $sheet->assertSee('id="stocktake_cancel"');
        $sheet->assertSee('year end');
        $sheet->assertSee('id="stocktake_sheet_link"');
    }

    public function testPostCount_EntersTheCountsAndPostApproveAppliesThemWhileTheBooksFollow(): void
    {
        $itemId      = $this->stocked('10', '50');
        $stocktakeId = $this->started($itemId);

        $this->actAsKeeper();

        $counted = $this->postJson('/stock_documents/stocktakes/count/' . $stocktakeId, ['counted' => [(string) $itemId => '8']]);

        $this->assertTrue($counted['success']);
        $this->assertSame(lang('Stock_documents.counts_saved'), $counted['message']);

        $approved = $this->postJson('/stock_documents/stocktakes/approve/' . $stocktakeId);

        $this->assertTrue($approved['success']);
        $this->assertSame(lang('Stock_documents.stocktake_approved'), $approved['message']);
        $this->assertSame('8.000', $this->onHand($itemId));
        $this->assertCount(1, $this->journalsOfSource(StockPermitSources::STOCKTAKE, $stocktakeId));

        $page = $this->get('/stock_documents/stocktakes/view/' . $stocktakeId);

        $page->assertDontSee('id="stocktake_approve"');
        $page->assertDontSee('id="counts_save"');
        $page->assertSee('-2');
    }

    public function testPostCountAndPostApprove_SayInWordsWhatTheBooksRefuse(): void
    {
        $first       = $this->stocked('10', '50');
        $second      = $this->stocked('3', '20');
        $stocktakeId = $this->takes->create(['location_id' => self::LOCATION_ID, 'item_ids' => [$first, $second]], $this->keeperId);

        $this->actAsKeeper();

        $bad = $this->postJson('/stock_documents/stocktakes/count/' . $stocktakeId, ['counted' => [(string) $first => '-1']]);

        $this->assertFalse($bad['success']);
        $this->assertSame(lang('Accounting.error_stocktake_lines_invalid', [(string) $first]), $bad['message']);

        $this->postJson('/stock_documents/stocktakes/count/' . $stocktakeId, ['counted' => [(string) $first => '8']]);

        $refused = $this->postJson('/stock_documents/stocktakes/approve/' . $stocktakeId);

        $this->assertFalse($refused['success']);
        $this->assertSame('10.000', $this->onHand($first));
    }

    public function testPostApprove_NeedsThePermissionToApproveAndTheSheetOffersNoButtonWithoutIt(): void
    {
        $itemId      = $this->stocked('10', '50');
        $stocktakeId = $this->started($itemId);

        $this->takes->count($stocktakeId, [$itemId => '8'], $this->keeperId);
        $this->actAsWith([StockPermissions::MODULE, StockPermissions::STOCKTAKE_MANAGE, 'items_stock']);

        $page = $this->get('/stock_documents/stocktakes/view/' . $stocktakeId);

        $page->assertStatus(200);
        $page->assertDontSee('id="stocktake_approve"');
        $page->assertSee('id="counts_save"');

        $answer = $this->postJson('/stock_documents/stocktakes/approve/' . $stocktakeId);

        $this->assertFalse($answer['success']);
        $this->assertSame(lang('Accounting.error_permission_denied'), $answer['message']);
        $this->assertSame('10.000', $this->onHand($itemId));
    }

    public function testPostRemoveItem_TakesAnItemOffTheSheet(): void
    {
        $first       = $this->stocked('10', '50');
        $second      = $this->stocked('3', '20');
        $stocktakeId = $this->takes->create(['location_id' => self::LOCATION_ID, 'item_ids' => [$first, $second]], $this->keeperId);

        $this->actAsKeeper();

        $answer = $this->postJson('/stock_documents/stocktakes/remove_item/' . $stocktakeId, ['item_id' => (string) $second]);

        $this->assertTrue($answer['success']);
        $this->assertSame([$first], array_map(static fn (object $line): int => (int) $line->item_id, $this->takes->lines($stocktakeId)));
    }

    public function testGetSheet_PrintsTheSystemQuantitiesFrozenForCounting(): void
    {
        $itemId      = $this->stocked('10', '50');
        $stocktakeId = $this->started($itemId);

        $this->actAsKeeper();

        $sheet = $this->get('/stock_documents/stocktakes/sheet/' . $stocktakeId);

        $sheet->assertStatus(200);
        $sheet->assertSee('id="count_sheet"');
        $sheet->assertSee(lang('Stock_documents.system_quantity'));
        $sheet->assertSee('window.print()');
        $this->assertStringContainsString('>10<', $sheet->getBody());
    }

    public function testSheetAndCancelFormAndCancel_AreRefusedToABranchOutsideTheScopeAndToWhoLacksThePermission(): void
    {
        $other       = $this->createBranch();
        $itemId      = $this->stocked('10', '50');
        $stocktakeId = $this->started($itemId);

        $this->actAsWith([StockPermissions::MODULE, StockPermissions::STOCKTAKE_MANAGE], [$other]);

        foreach (['view', 'sheet', 'cancel_form'] as $page) {
            $this->assertSame(403, $this->get('/stock_documents/stocktakes/' . $page . '/' . $stocktakeId)->response()->getStatusCode(), $page);
        }

        $refused = $this->postJson('/stock_documents/stocktakes/cancel/' . $stocktakeId, ['reason' => 'no']);

        $this->assertFalse($refused['success']);
        $this->assertSame('counting', $this->takes->find($stocktakeId)->status);

        $this->actAsWith([StockPermissions::MODULE]);

        $this->assertSame(403, $this->get('/stock_documents/stocktakes/cancel_form/' . $stocktakeId)->response()->getStatusCode());
    }

    public function testPostCancel_DiscardsACountAndReversesAnApprovedOneWithAReason(): void
    {
        $first  = $this->stocked('10', '50');
        $second = $this->stocked('4', '20');

        $counting = $this->started($first);
        $approved = $this->takes->create(['location_id' => self::LOCATION_ID, 'date' => date('Y-m-d', strtotime('-1 day')), 'item_ids' => [$second]], $this->keeperId);

        $this->takes->count($approved, [$second => '3'], $this->keeperId);
        $this->takes->approve($approved, $this->keeperId);
        $this->actAsKeeper();

        $this->get('/stock_documents/stocktakes/cancel_form/' . $counting)->assertSee('name="reason"');

        $none = $this->postJson('/stock_documents/stocktakes/cancel/' . $counting, ['reason' => '']);

        $this->assertFalse($none['success']);
        $this->assertSame(lang('Accounting.error_reason_required'), $none['message']);

        $discarded = $this->postJson('/stock_documents/stocktakes/cancel/' . $counting, ['reason' => 'wrong day']);
        $reversed  = $this->postJson('/stock_documents/stocktakes/cancel/' . $approved, ['reason' => 'wrong shelf']);

        $this->assertTrue($discarded['success']);
        $this->assertTrue($reversed['success']);
        $this->assertSame(lang('Stock_documents.stocktake_cancelled'), $reversed['message']);
        $this->assertSame('4.000', $this->onHand($second));

        $page = $this->get('/stock_documents/stocktakes/view/' . $approved);

        $page->assertDontSee('id="stocktake_cancel"');
        $page->assertSee('wrong shelf');
    }

    public function testGetDocument_ListsTheJournalsToWhoMayOpenThem(): void
    {
        $itemId      = $this->stocked('10', '50');
        $stocktakeId = $this->started($itemId);

        $this->takes->count($stocktakeId, [$itemId => '8'], $this->keeperId);
        $this->takes->approve($stocktakeId, $this->keeperId);

        $this->actAsWith([StockPermissions::MODULE, 'items_stock', 'accounting_journals']);

        $this->get('/stock_documents/stocktakes/view/' . $stocktakeId)->assertSee('id="stocktake_journals"');

        $this->actAsWith([StockPermissions::MODULE, 'items_stock']);

        $this->get('/stock_documents/stocktakes/view/' . $stocktakeId)->assertDontSee('id="stocktake_journals"');
    }

    public function testGetIndex_OpensWhileAccountingIsOff(): void
    {
        $this->started($this->stocked('10', '50'));
        $this->setConfigValue('accounting_enabled', '0');
        $this->actAsKeeper();

        $this->get('/stock_documents/stocktakes')->assertStatus(200);
    }
}
