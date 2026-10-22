<?php

namespace Tests\Controllers;

use App\Libraries\Organization\BranchGuard;
use CodeIgniter\Exceptions\PageNotFoundException;
use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\DatabaseTestTrait;
use Tests\Support\AccountingControllerFixtureTrait;

class BranchesControllerTest extends CIUnitTestCase
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

        $this->prepareAccounting(false);
    }

    protected function tearDown(): void
    {
        $this->releaseOpenTransactions();

        parent::tearDown();
    }

    /**
     * @param list<int>|null $branchIds
     */
    private function manager(?array $branchIds = null): int
    {
        $personId = $this->employeeWithGrants(['branches', BranchGuard::BRANCHES_MANAGE], $branchIds);
        $this->actAs($personId);

        return $personId;
    }

    private function branchByCode(string $code): ?object
    {
        return $this->accountingDb()->table('branches')->where('code', $code)->get()->getRow();
    }

    public function testGetIndex_ShowsTheScreenWhileAccountingIsDisabled(): void
    {
        $this->actAs($this->employeeWithGrants(['branches']));

        $response = $this->get('/branches');

        $response->assertStatus(200);
        $response->assertSee('id="table"');
    }

    public function testGetIndex_RedirectsAUserWithoutTheModuleGrant(): void
    {
        $this->actAs($this->employeeWithGrants(['accounting_chart']));

        $this->get('/branches')->assertRedirect();
    }

    public function testGetIndex_OffersTheAddButtonToSomeoneWhoManagesEveryBranch(): void
    {
        $this->manager();

        $this->get('/branches')->assertSee('data-href="branches/view"');
    }

    public function testGetIndex_HidesTheAddButtonFromSomeoneLimitedToBranches(): void
    {
        $this->manager([1]);

        $this->get('/branches')->assertDontSee('data-href="branches/view"');
    }

    public function testGetSearch_ListsTheBranchesWithTheirCounts(): void
    {
        $this->manager();
        $second = $this->createBranch('B2', 'Second branch');
        $this->employeeWithGrants([], [$second]);

        $rows = $this->jsonOf($this->get('/branches/search'))['rows'];

        $this->assertSame(['HQ', 'B2'], array_column($rows, 'code'));
        $this->assertSame(1, $rows[1]['employee_count']);
        $this->assertStringContainsString('Default', $rows[0]['name']);
    }

    public function testGetSearch_ListsOnlyTheBranchesInTheEmployeesScope(): void
    {
        $second = $this->createBranch('B2');
        $this->createBranch('B3');
        $this->manager([$second]);

        $this->assertSame(['B2'], array_column($this->jsonOf($this->get('/branches/search'))['rows'], 'code'));
    }

    public function testGetSearch_FiltersByCodeOrName(): void
    {
        $this->manager();
        $this->createBranch('JED', 'Jeddah');
        $this->createBranch('DMM', 'Dammam');

        $result = $this->jsonOf($this->get('/branches/search?search=dam'));

        $this->assertSame(['DMM'], array_column($result['rows'], 'code'));
        $this->assertSame(1, $result['total']);
    }

    public function testGetSearch_EscapesTheName(): void
    {
        $this->manager();
        $this->createBranch('XSS', '<script>alert(1)</script>');

        $row = $this->jsonOf($this->get('/branches/search?search=xss'))['rows'][0];

        $this->assertStringNotContainsString('<script>', $row['name']);
    }

    public function testGetRow_IsNotFoundForABranchOutsideTheScope(): void
    {
        $second = $this->createBranch('B2');
        $this->manager([$second]);

        $this->expectException(PageNotFoundException::class);

        $this->get('/branches/row/1');
    }

    public function testGetView_IsNotFoundWithoutTheManagePermission(): void
    {
        $this->actAs($this->employeeWithGrants(['branches']));

        $this->expectException(PageNotFoundException::class);

        $this->get('/branches/view');
    }

    public function testGetView_IsNotFoundForANewBranchFromALimitedScope(): void
    {
        $this->manager([1]);

        $this->expectException(PageNotFoundException::class);

        $this->get('/branches/view');
    }

    public function testGetView_OffersTheStockLocationsToSomeoneWithEveryBranch(): void
    {
        $this->manager();

        $response = $this->get('/branches/view');

        $response->assertSee('name="locations[]"');
        $response->assertSee('value="BR2"');
    }

    public function testGetView_HidesTheStockLocationsFromALimitedScope(): void
    {
        $this->manager([1]);

        $this->get('/branches/view/1')->assertDontSee('name="locations[]"');
    }

    public function testPostSave_AddsABranch(): void
    {
        $this->manager();

        $result = $this->jsonOf($this->post('/branches/save', ['code' => 'jed', 'name' => 'Jeddah', 'address' => 'Corniche', 'phone' => '', 'tax_registration' => '', 'opened_on' => '2026-03-01']));

        $this->assertTrue($result['success']);
        $this->assertSame('Jeddah', $this->branchByCode('JED')->name);
    }

    public function testPostSave_ReportsACodeAlreadyInUse(): void
    {
        $this->manager();
        $this->createBranch('JED');

        $result = $this->jsonOf($this->post('/branches/save', ['code' => 'JED', 'name' => 'Another']));

        $this->assertFalse($result['success']);
        $this->assertStringContainsString('JED', $result['message']);
    }

    public function testPostSave_ReportsAMissingPermission(): void
    {
        $this->actAs($this->employeeWithGrants(['branches']));

        $result = $this->jsonOf($this->post('/branches/save', ['code' => 'JED', 'name' => 'Jeddah']));

        $this->assertFalse($result['success']);
        $this->assertNull($this->branchByCode('JED'));
    }

    public function testPostSave_AssignsTheTickedLocations(): void
    {
        $this->manager();
        $location = (int) $this->accountingDb()->table('stock_locations')->where('deleted', 0)->get()->getRow()->location_id;

        $result = $this->jsonOf($this->post('/branches/save', ['code' => 'JED', 'name' => 'Jeddah', 'locations_present' => '1', 'locations' => [(string) $location]]));

        $this->assertTrue($result['success']);
        $this->assertSame((int) $this->branchByCode('JED')->branch_id, (int) $this->accountingDb()->table('stock_locations')->where('location_id', $location)->get()->getRow()->branch_id);
    }

    public function testPostSave_LeavesTheLocationsAloneWhenTheFormCarriedNone(): void
    {
        $this->manager();
        $branchId = $this->createBranch('JED');
        $location = (int) $this->accountingDb()->table('stock_locations')->where('deleted', 0)->get()->getRow()->location_id;
        $this->accountingDb()->table('stock_locations')->where('location_id', $location)->update(['branch_id' => $branchId]);

        $this->post('/branches/save/' . $branchId, ['code' => 'JED', 'name' => 'Jeddah North']);

        $this->assertSame($branchId, (int) $this->accountingDb()->table('stock_locations')->where('location_id', $location)->get()->getRow()->branch_id);
    }

    public function testPostSave_UpdatesABranchAndDeactivatesIt(): void
    {
        $this->manager();
        $branchId = $this->createBranch('JED', 'Jeddah');

        $result = $this->jsonOf($this->post('/branches/save/' . $branchId, ['code' => 'JED', 'name' => 'Jeddah North', 'active' => '0']));

        $this->assertTrue($result['success']);
        $this->assertSame('Jeddah North', $this->branchByCode('JED')->name);
        $this->assertSame('0', (string) $this->branchByCode('JED')->active);
    }

    public function testPostSave_RefusesABranchOutsideTheScope(): void
    {
        $second = $this->createBranch('B2');
        $this->manager([$second]);

        $result = $this->jsonOf($this->post('/branches/save/1', ['code' => 'HQ', 'name' => 'Renamed']));

        $this->assertFalse($result['success']);
        $this->assertNotSame('Renamed', $this->branchByCode('HQ')->name);
    }

    public function testPostDeactivate_AndPostActivate_ToggleTheBranch(): void
    {
        $this->manager();
        $branchId = $this->createBranch('JED');

        $this->assertTrue($this->jsonOf($this->post('/branches/deactivate/' . $branchId))['success']);
        $this->assertSame('0', (string) $this->branchByCode('JED')->active);

        $this->assertTrue($this->jsonOf($this->post('/branches/activate/' . $branchId))['success']);
        $this->assertSame('1', (string) $this->branchByCode('JED')->active);
    }

    public function testPostDeactivate_RefusesTheDefaultBranch(): void
    {
        $this->manager();

        $result = $this->jsonOf($this->post('/branches/deactivate/1'));

        $this->assertFalse($result['success']);
        $this->assertSame('1', (string) $this->branchByCode('HQ')->active);
    }

    public function testPostDeleteBranch_DeletesAnUnusedBranchAndRefusesAUsedOne(): void
    {
        $this->manager();
        $unused = $this->createBranch('JED');
        $used   = $this->createBranch('DMM');
        $this->employeeWithGrants([], [$used]);

        $this->assertTrue($this->jsonOf($this->post('/branches/delete/' . $unused))['success']);
        $this->assertNull($this->branchByCode('JED'));

        $this->assertFalse($this->jsonOf($this->post('/branches/delete/' . $used))['success']);
        $this->assertNotNull($this->branchByCode('DMM'));
    }
}
