<?php

namespace Tests\Controllers;

use App\Libraries\Stock\StockPermissions;
use App\Libraries\Stock\StockPermitSources;
use App\Libraries\Stock\StockRequest;
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
 * The screens of the stock requests: the list that shows only while the setting is on, the form that
 * asks, the request with the steps that approve, fulfil and cancel it, and who may take each.
 */
class StockRequestsControllerTest extends CIUnitTestCase
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

    private StockRequest $requests;
    private int $shop;

    public static function setUpBeforeClass(): void
    {
        $seeder = Database::seeder('tests');
        $seeder->call('TestDatabaseBootstrapSeeder');
    }

    protected function setUp(): void
    {
        parent::setUp();

        $this->startStockDocuments();
        $this->switchOnRequests();
        $this->switchOnTransferPermit();

        $this->requests = new StockRequest();
        $this->shop     = $this->openLocation('Shop');
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

        $this->allowLocation($this->shop, $personId);
        $this->withSession(['person_id' => $personId, 'menu_group' => 'home']);

        return $personId;
    }

    private function actAsKeeper(): int
    {
        return $this->actAsWith([StockPermissions::MODULE, StockPermissions::REQUESTS_MANAGE, StockPermissions::REQUESTS_APPROVE, StockPermissions::PERMITS_MANAGE, 'items_stock']);
    }

    /**
     * @return array<string, mixed>
     */
    private function postJson(string $url, array $data = []): array
    {
        return json_decode($this->post($url, $data)->getJSON(), true);
    }

    private function asked(int $itemId, string $quantity = '4'): int
    {
        return $this->requests->create(['from_location_id' => self::LOCATION_ID, 'to_location_id' => $this->shop, 'lines' => [['item_id' => $itemId, 'quantity' => $quantity]]], $this->keeperId);
    }

    public function testGetIndex_ListsTheRequestsAndOffersTheNewOneToWhoMayManageThem(): void
    {
        $requestId = $this->asked($this->stocked('10', '50'));

        $this->actAsKeeper();

        $page = $this->get('/stock_documents/requests');

        $page->assertStatus(200);
        $page->assertSee('id="request_' . $requestId . '"');
        $page->assertSee('id="request_new"');
        $page->assertSee('id="stock_tab_requests"');
        $page->assertDontSee('id="requests_off"');
    }

    public function testGetIndex_SaysTheSettingIsOffAndOffersNoNewRequestWhileItIs(): void
    {
        $this->actAsKeeper();
        $this->accountingDb()->table('app_config')->where('key', 'stock_requests_enabled')->delete();

        $page = $this->get('/stock_documents/requests');

        $page->assertStatus(200);
        $page->assertSee('id="requests_off"');
        $page->assertDontSee('id="request_new"');

        $this->assertSame(403, $this->get('/stock_documents/requests/form')->response()->getStatusCode());
    }

    public function testGetIndex_RedirectsWhoLacksTheModuleAndShowsOnlyTheBranchesOfTheEmployeeAndSaysSo(): void
    {
        $other  = $this->createBranch();
        $store  = $this->locationOfBranch($other, 'Branch store');
        $itemId = $this->stocked('10', '50');

        $local   = $this->asked($itemId);
        $across  = $this->requests->create(['from_location_id' => self::LOCATION_ID, 'to_location_id' => $store, 'lines' => [['item_id' => $itemId, 'quantity' => '1']]], $this->keeperId);

        $this->actAsWith([]);

        $this->assertNotSame(200, $this->get('/stock_documents/requests')->response()->getStatusCode());

        $this->actAsWith([StockPermissions::MODULE], [$other]);

        $page = $this->get('/stock_documents/requests');

        $page->assertSee('id="request_' . $across . '"');
        $page->assertDontSee('id="request_' . $local . '"');
        $page->assertSee('id="requests_scope"');
    }

    public function testGetForm_ServesTheFormToWhoMayManageRequestsAndRefusesEveryoneElse(): void
    {
        $this->actAsKeeper();

        $form = $this->get('/stock_documents/requests/form');

        $form->assertStatus(200);
        $form->assertSee('name="from_location_id"');
        $form->assertSee('name="to_location_id"');
        $form->assertSee('name="request_date"');

        $this->actAsWith([StockPermissions::MODULE, 'items_stock']);

        $this->assertSame(403, $this->get('/stock_documents/requests/form')->response()->getStatusCode());
    }

    public function testPostSaveRequest_AsksForStockAndPostsNothing(): void
    {
        $itemId   = $this->stocked('10', '50');
        $journals = $this->countRows('accounting_journals');

        $this->actAsKeeper();

        $answer = $this->postJson('/stock_documents/requests/save', [
            'from_location_id' => (string) self::LOCATION_ID,
            'to_location_id'   => (string) $this->shop,
            'request_date'     => date('Y-m-d'),
            'note'             => 'weekend',
            'lines'            => [['item_id' => (string) $itemId, 'quantity' => '4'], ['item_id' => '', 'quantity' => '']],
        ]);

        $this->assertTrue($answer['success']);
        $this->assertSame(lang('Stock_documents.request_saved'), $answer['message']);
        $this->assertSame($journals, $this->countRows('accounting_journals'));
        $this->assertSame('requested', $this->requests->find((int) $answer['id'])->status);
        $this->assertCount(1, $this->requests->lines((int) $answer['id']));
    }

    public function testPostSaveRequest_SaysInWordsWhyItIsRefused(): void
    {
        $itemId = $this->stocked('10', '50');

        $this->actAsKeeper();

        $answer = $this->postJson('/stock_documents/requests/save', ['from_location_id' => (string) self::LOCATION_ID, 'to_location_id' => (string) self::LOCATION_ID, 'request_date' => date('Y-m-d'), 'lines' => [['item_id' => (string) $itemId, 'quantity' => '4']]]);

        $this->assertFalse($answer['success']);
        $this->assertSame(lang('Accounting.error_stock_request_locations_invalid'), $answer['message']);
        $this->assertSame(0, $this->countRows('stock_requests'));

        $this->actAsWith([StockPermissions::MODULE, 'items_stock']);

        $denied = $this->postJson('/stock_documents/requests/save', ['from_location_id' => (string) self::LOCATION_ID, 'to_location_id' => (string) $this->shop, 'request_date' => date('Y-m-d'), 'lines' => [['item_id' => (string) $itemId, 'quantity' => '4']]]);

        $this->assertFalse($denied['success']);
        $this->assertSame(lang('Accounting.error_permission_denied'), $denied['message']);
    }

    public function testGetDocument_OffersTheStepThatFitsTheStateAndThePermission(): void
    {
        $requestId = $this->asked($this->stocked('10', '50'));

        $this->actAsKeeper();

        $page = $this->get('/stock_documents/requests/view/' . $requestId);

        $page->assertStatus(200);
        $page->assertSee('id="request_approve"');
        $page->assertDontSee('id="request_fulfil"');
        $page->assertSee('id="request_cancel"');

        $this->requests->approve($requestId, $this->keeperId);

        $approved = $this->get('/stock_documents/requests/view/' . $requestId);

        $approved->assertDontSee('id="request_approve"');
        $approved->assertSee('id="request_fulfil"');

        $this->actAsWith([StockPermissions::MODULE, StockPermissions::REQUESTS_MANAGE, 'items_stock']);

        $asker = $this->get('/stock_documents/requests/view/' . $requestId);

        $asker->assertDontSee('id="request_fulfil"');
        $asker->assertSee('id="request_cancel"');

        $this->actAsWith([StockPermissions::MODULE, 'items_stock']);

        $viewer = $this->get('/stock_documents/requests/view/' . $requestId);

        $viewer->assertStatus(200);
        $viewer->assertDontSee('id="request_cancel"');
        $this->assertSame(403, $this->get('/stock_documents/requests/view/999999')->response()->getStatusCode());
    }

    public function testPostApproveAndPostFulfil_TakeTheRequestThroughItsStatesAndMakeTheTransferPermit(): void
    {
        $itemId    = $this->stocked('10', '50');
        $requestId = $this->asked($itemId);

        $this->actAsKeeper();

        $approved = $this->postJson('/stock_documents/requests/approve/' . $requestId);

        $this->assertTrue($approved['success']);
        $this->assertSame(lang('Stock_documents.request_approved'), $approved['message']);

        $fulfilled = $this->postJson('/stock_documents/requests/fulfil/' . $requestId);

        $this->assertTrue($fulfilled['success']);
        $this->assertSame(lang('Stock_documents.request_fulfilled'), $fulfilled['message']);
        $this->assertCount(1, $this->journalsOfSource(StockPermitSources::TRANSFER, (int) $fulfilled['permit_id']));
        $this->assertSame('6.000', $this->onHand($itemId));
        $this->assertSame('4.000', $this->onHand($itemId, $this->shop));

        $page = $this->get('/stock_documents/requests/view/' . $requestId);

        $page->assertSee('id="request_permit_link"');
        $page->assertDontSee('id="request_fulfil"');
        $page->assertDontSee('id="request_cancel"');

        $again = $this->postJson('/stock_documents/requests/fulfil/' . $requestId);

        $this->assertFalse($again['success']);
        $this->assertSame(lang('Accounting.error_stock_request_status_invalid'), $again['message']);
    }

    public function testPostFulfil_SaysInWordsWhyTheShelvesRefuseAndLeavesTheRequestApproved(): void
    {
        $requestId = $this->asked($this->stocked('10', '50'), '11');

        $this->requests->approve($requestId, $this->keeperId);
        $this->actAsKeeper();

        $answer = $this->postJson('/stock_documents/requests/fulfil/' . $requestId);

        $this->assertFalse($answer['success']);
        $this->assertSame('approved', $this->requests->find($requestId)->status);
        $this->assertSame(0, $this->countRows('stock_permits'));
    }

    public function testPostApproveAndPostFulfilAndPostCancel_AnswerThatARequestOfAnotherBranchDoesNotExist(): void
    {
        $other     = $this->createBranch();
        $requestId = $this->asked($this->stocked('10', '50'));

        $this->requests->approve($requestId, $this->keeperId);
        $this->actAsWith([StockPermissions::MODULE, StockPermissions::REQUESTS_MANAGE, StockPermissions::REQUESTS_APPROVE, StockPermissions::PERMITS_MANAGE, 'items_stock'], [$other]);

        foreach (['approve' => [], 'fulfil' => [], 'cancel' => ['reason' => 'no']] as $step => $data) {
            $answer = $this->postJson('/stock_documents/requests/' . $step . '/' . $requestId, $data);

            $this->assertFalse($answer['success'], $step);
            $this->assertSame(lang('Accounting.error_stock_request_not_found', ['']), $answer['message'], $step);
        }

        $this->assertSame('approved', $this->requests->find($requestId)->status);
        $this->assertSame(0, $this->countRows('stock_permits'));
    }

    public function testPostCancelAndTheCancelForm_CancelARequestWithAReasonForWhoMayAndNoOneElse(): void
    {
        $other     = $this->createBranch();
        $requestId = $this->asked($this->stocked('10', '50'));

        $this->actAsWith([StockPermissions::MODULE, StockPermissions::REQUESTS_MANAGE], [$other]);

        $this->assertSame(403, $this->get('/stock_documents/requests/cancel_form/' . $requestId)->response()->getStatusCode());

        $refused = $this->postJson('/stock_documents/requests/cancel/' . $requestId, ['reason' => 'no']);

        $this->assertFalse($refused['success']);
        $this->assertSame('requested', $this->requests->find($requestId)->status);

        $this->actAsKeeper();

        $this->get('/stock_documents/requests/cancel_form/' . $requestId)->assertSee('name="reason"');

        $none = $this->postJson('/stock_documents/requests/cancel/' . $requestId, ['reason' => '']);

        $this->assertFalse($none['success']);

        $done = $this->postJson('/stock_documents/requests/cancel/' . $requestId, ['reason' => 'not needed']);

        $this->assertTrue($done['success']);
        $this->assertSame(lang('Stock_documents.request_cancelled'), $done['message']);
        $this->assertSame(403, $this->get('/stock_documents/requests/cancel_form/' . $requestId)->response()->getStatusCode(), 'a cancelled request cannot be cancelled again');
    }

    public function testGetIndex_OpensWhileAccountingIsOff(): void
    {
        $this->asked($this->stocked('10', '50'));
        $this->setConfigValue('accounting_enabled', '0');
        $this->actAsKeeper();

        $this->get('/stock_documents/requests')->assertStatus(200);
    }
}
