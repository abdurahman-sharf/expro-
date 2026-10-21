<?php

namespace Tests\Controllers;

use App\Libraries\Accounting\AccountingGuard;
use App\Libraries\Accounting\CostCenterService;
use App\Libraries\Accounting\JournalService;
use CodeIgniter\Exceptions\PageNotFoundException;
use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\DatabaseTestTrait;
use Tests\Support\AccountingControllerFixtureTrait;

class AccountingCostCentersControllerTest extends CIUnitTestCase
{
    use DatabaseTestTrait;
    use AccountingControllerFixtureTrait;

    protected $migrate     = true;
    protected $migrateOnce = true;
    protected $refresh     = false;
    protected $namespace   = null;

    protected function setUp(): void
    {
        parent::setUp();

        $this->prepareAccounting();
    }

    protected function tearDown(): void
    {
        $this->releaseOpenTransactions();

        parent::tearDown();
    }

    private function manager(): int
    {
        $personId = $this->employeeWithGrants(['accounting_cost_centers', AccountingGuard::COST_CENTERS_MANAGE]);
        $this->actAs($personId);

        return $personId;
    }

    public function testGetIndex_ShowsTheScreenToAUserWithTheModuleGrant(): void
    {
        $this->actAs($this->employeeWithGrants(['accounting_cost_centers']));

        $response = $this->get('/accounting_cost_centers');

        $response->assertStatus(200);
        $response->assertSee('id="table"');
        $response->assertDontSee('accounting_cost_centers/view"');
    }

    public function testGetIndex_OffersTheAddButtonWithTheManagePermission(): void
    {
        $this->manager();

        $response = $this->get('/accounting_cost_centers');

        $response->assertSee('data-href="accounting_cost_centers/view"');
    }

    public function testGetIndex_IsNotFoundWhileAccountingIsDisabled(): void
    {
        $this->prepareAccounting(false);
        $this->actAs($this->employeeWithGrants(['accounting_cost_centers']));

        $this->expectException(PageNotFoundException::class);

        $this->get('/accounting_cost_centers');
    }

    public function testGetIndex_RedirectsAUserWithoutTheModuleGrant(): void
    {
        $this->actAs($this->employeeWithGrants(['accounting_chart']));

        $this->get('/accounting_cost_centers')->assertRedirect();
    }

    public function testGetSearch_ListsTheCostCenterTreeInDepthFirstOrder(): void
    {
        $this->manager();
        $service  = new CostCenterService();
        $parentId = $service->create(['code' => '1', 'name' => 'Head office']);
        $service->create(['code' => '3', 'name' => 'Warehouse']);
        $service->create(['code' => '2', 'name' => 'Sales floor', 'parent_id' => $parentId]);

        $result = $this->jsonOf($this->get('/accounting_cost_centers/search'));

        $this->assertSame(['1', '2', '3'], array_column($result['rows'], 'code'));
        $this->assertSame('Head office', $result['rows'][1]['parent_name']);
        $this->assertStringContainsString('acc-depth-1', $result['rows'][1]['name']);
    }

    public function testGetSearch_FiltersByCodeOrName(): void
    {
        $this->manager();
        $service = new CostCenterService();
        $service->create(['code' => '1', 'name' => 'Head office']);
        $service->create(['code' => '2', 'name' => 'Warehouse']);

        $result = $this->jsonOf($this->get('/accounting_cost_centers/search?search=ware'));

        $this->assertSame(['2'], array_column($result['rows'], 'code'));
        $this->assertSame(1, $result['total']);
    }

    public function testGetSearch_EscapesTheName(): void
    {
        $this->manager();
        (new CostCenterService())->create(['code' => '1', 'name' => '<script>alert(1)</script>']);

        $result = $this->jsonOf($this->get('/accounting_cost_centers/search'));

        $this->assertStringNotContainsString('<script>', $result['rows'][0]['name']);
    }

    public function testGetSearch_OffersNoDeleteForACenterUsedByAJournalLine(): void
    {
        $this->manager();
        $centerId = (new CostCenterService())->create(['code' => '1', 'name' => 'Head office']);
        $input    = $this->journalInput();

        $input['lines'][0]['cost_center_id'] = $centerId;
        (new JournalService())->postNew($input);

        $result = $this->jsonOf($this->get('/accounting_cost_centers/search'));

        $this->assertStringNotContainsString('data-acc-action="delete"', $result['rows'][0]['edit']);
    }

    public function testGetView_IsNotFoundWithoutTheManagePermission(): void
    {
        $this->actAs($this->employeeWithGrants(['accounting_cost_centers']));

        $this->expectException(PageNotFoundException::class);

        $this->get('/accounting_cost_centers/view');
    }

    public function testGetView_ListsOnlyCentersThatCanBecomeTheParent(): void
    {
        $this->manager();
        $service  = new CostCenterService();
        $parentId = $service->create(['code' => '1', 'name' => 'Head office']);
        $childId  = $service->create(['code' => '2', 'name' => 'Sales floor', 'parent_id' => $parentId]);

        $response = $this->get('/accounting_cost_centers/view/' . $parentId);

        $response->assertDontSee('2 - Sales floor');
        $response->assertSee('name="active"');
        $this->assertNotNull($service->find($childId));
    }

    public function testGetView_SuggestsTheNextCodeForANewCenter(): void
    {
        $this->manager();
        (new CostCenterService())->create(['code' => '7', 'name' => 'Head office']);

        $response = $this->get('/accounting_cost_centers/view');

        $response->assertSee('value="8"');
    }

    public function testPostSave_CreatesACostCenter(): void
    {
        $this->manager();

        $result = $this->jsonOf($this->post('/accounting_cost_centers/save/-1', ['code' => '1', 'name' => 'Head office', 'parent_id' => '']));

        $this->assertTrue($result['success']);
        $this->assertSame('Head office', (new CostCenterService())->find($result['id'])->name);
    }

    public function testPostSave_RejectsADuplicateCode(): void
    {
        $this->manager();
        (new CostCenterService())->create(['code' => '1', 'name' => 'Head office']);

        $result = $this->jsonOf($this->post('/accounting_cost_centers/save/-1', ['code' => '1', 'name' => 'Warehouse']));

        $this->assertFalse($result['success']);
        $this->assertStringContainsString('1', $result['message']);
    }

    public function testPostSave_RejectsAUserWithoutTheManagePermission(): void
    {
        $this->actAs($this->employeeWithGrants(['accounting_cost_centers']));

        $result = $this->jsonOf($this->post('/accounting_cost_centers/save/-1', ['code' => '1', 'name' => 'Head office']));

        $this->assertFalse($result['success']);
        $this->assertSame(0, $this->countRows('accounting_cost_centers'));
    }

    public function testPostSave_RejectsMovingACenterUnderItsOwnChild(): void
    {
        $this->manager();
        $service  = new CostCenterService();
        $parentId = $service->create(['code' => '1', 'name' => 'Head office']);
        $childId  = $service->create(['code' => '2', 'name' => 'Sales floor', 'parent_id' => $parentId]);

        $result = $this->jsonOf($this->post('/accounting_cost_centers/save/' . $parentId, ['code' => '1', 'name' => 'Head office', 'parent_id' => $childId]));

        $this->assertFalse($result['success']);
    }

    public function testPostSave_DeactivatesACenterWhenTheStatusChanges(): void
    {
        $this->manager();
        $centerId = (new CostCenterService())->create(['code' => '1', 'name' => 'Head office']);

        $result = $this->jsonOf($this->post('/accounting_cost_centers/save/' . $centerId, ['code' => '1', 'name' => 'Head office', 'parent_id' => '', 'active' => '0']));

        $this->assertTrue($result['success']);
        $this->assertSame('0', (string) (new CostCenterService())->find($centerId)->active);
    }

    public function testPostActivate_RestoresADeactivatedCenter(): void
    {
        $this->manager();
        $service  = new CostCenterService();
        $centerId = $service->create(['code' => '1', 'name' => 'Head office']);
        $service->deactivate($centerId);

        $result = $this->jsonOf($this->post('/accounting_cost_centers/activate/' . $centerId));

        $this->assertTrue($result['success']);
        $this->assertSame('1', (string) $service->find($centerId)->active);
    }

    public function testPostDeleteCostCenter_DeletesAnUnusedCenterAndRefusesACenterWithChildren(): void
    {
        $this->manager();
        $service  = new CostCenterService();
        $parentId = $service->create(['code' => '1', 'name' => 'Head office']);
        $childId  = $service->create(['code' => '2', 'name' => 'Sales floor', 'parent_id' => $parentId]);

        $refused = $this->jsonOf($this->post('/accounting_cost_centers/delete/' . $parentId));
        $deleted = $this->jsonOf($this->post('/accounting_cost_centers/delete/' . $childId));

        $this->assertFalse($refused['success']);
        $this->assertTrue($deleted['success']);
        $this->assertNull($service->find($childId));
    }

    public function testGetRow_ReturnsTheRowOfOneCenter(): void
    {
        $this->manager();
        $centerId = (new CostCenterService())->create(['code' => '1', 'name' => 'Head office']);

        $row = $this->jsonOf($this->get('/accounting_cost_centers/row/' . $centerId));

        $this->assertSame('1', $row['code']);
    }
}
