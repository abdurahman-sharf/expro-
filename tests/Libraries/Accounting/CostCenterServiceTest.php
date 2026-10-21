<?php

namespace Tests\Libraries\Accounting;

use App\Libraries\Accounting\AccountingError;
use App\Libraries\Accounting\AccountingException;
use App\Libraries\Accounting\AccountingGuard;
use App\Libraries\Accounting\CostCenterService;
use App\Libraries\Accounting\JournalService;
use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\DatabaseTestTrait;
use Config\Database;
use Tests\Support\AccountingFixtureTrait;

class CostCenterServiceTest extends CIUnitTestCase
{
    use DatabaseTestTrait;
    use AccountingFixtureTrait;

    protected $migrate     = true;
    protected $migrateOnce = true;
    protected $refresh     = false;
    protected $namespace   = null;

    private CostCenterService $service;

    public static function setUpBeforeClass(): void
    {
        $seeder = Database::seeder('tests');
        $seeder->call('TestDatabaseBootstrapSeeder');
    }

    protected function setUp(): void
    {
        parent::setUp();

        $this->reinstallChart();
        $this->setConfigValue('accounting_base_currency', 'SAR');
        $this->service = new CostCenterService();
    }

    protected function tearDown(): void
    {
        $this->releaseOpenTransactions();

        parent::tearDown();
    }

    private function assertFailsWith(AccountingError $expected, callable $action): void
    {
        try {
            $action();
        } catch (AccountingException $exception) {
            $this->assertSame($expected, $exception->error);

            return;
        }

        $this->fail('Expected the failure ' . $expected->value . '.');
    }

    private function costCenter(int $costCenterId): object
    {
        return $this->accountingDb()->table('accounting_cost_centers')->where('cost_center_id', $costCenterId)->get()->getRow();
    }

    private function postUsing(int $costCenterId): void
    {
        $input = $this->journalInput();
        $input['lines'][0]['cost_center_id'] = $costCenterId;

        (new JournalService())->postNew($input);
    }

    public function testCreate_AddsARootCostCenter(): void
    {
        $costCenterId = $this->service->create(['code' => 'MAIN', 'name' => 'Main branch']);

        $costCenter = $this->costCenter($costCenterId);
        $this->assertSame('MAIN', $costCenter->code);
        $this->assertNull($costCenter->parent_id);
        $this->assertSame('1', (string) $costCenter->active);
    }

    public function testCreate_AddsACostCenterUnderAnActiveParent(): void
    {
        $parent = $this->service->create(['code' => '1', 'name' => 'Branches']);
        $child  = $this->service->create(['code' => '11', 'name' => 'Riyadh', 'parent_id' => $parent]);

        $this->assertSame((string) $parent, (string) $this->costCenter($child)->parent_id);
    }

    public function testCreate_RejectsAnInvalidCodeAndAnEmptyName(): void
    {
        $this->assertFailsWith(AccountingError::InvalidCode, fn () => $this->service->create(['code' => 'a b', 'name' => 'X']));
        $this->assertFailsWith(AccountingError::InvalidCode, fn () => $this->service->create(['code' => '', 'name' => 'X']));
        $this->assertFailsWith(AccountingError::InvalidName, fn () => $this->service->create(['code' => '2', 'name' => ' ']));
    }

    public function testCreate_RejectsACodeThatIsAlreadyUsed(): void
    {
        $this->service->create(['code' => '1', 'name' => 'One']);

        $this->assertFailsWith(AccountingError::CodeTaken, fn () => $this->service->create(['code' => '1', 'name' => 'Other']));
    }

    public function testCreate_RejectsAMissingOrInactiveParent(): void
    {
        $this->assertFailsWith(AccountingError::CostCenterNotFound, fn () => $this->service->create(['code' => '2', 'name' => 'Orphan', 'parent_id' => 999999]));

        $parent = $this->service->create(['code' => '3', 'name' => 'Parent']);
        $this->service->deactivate($parent);

        $this->assertFailsWith(AccountingError::ParentInactive, fn () => $this->service->create(['code' => '31', 'name' => 'Child', 'parent_id' => $parent]));
    }

    public function testCreate_RequiresTheManagePermission(): void
    {
        $viewer  = $this->employeeWithGrants(['accounting_cost_centers']);
        $manager = $this->employeeWithGrants(['accounting_cost_centers', AccountingGuard::COST_CENTERS_MANAGE]);

        $this->assertFailsWith(AccountingError::PermissionDenied, fn () => $this->service->create(['code' => '4', 'name' => 'Denied'], $viewer));

        $this->assertGreaterThan(0, $this->service->create(['code' => '4', 'name' => 'Allowed'], $manager));
    }

    public function testUpdate_ChangesNameCodeAndParent(): void
    {
        $parent       = $this->service->create(['code' => '1', 'name' => 'Parent']);
        $costCenterId = $this->service->create(['code' => '2', 'name' => 'Child']);

        $this->service->update($costCenterId, ['name' => 'Renamed', 'code' => '22', 'parent_id' => $parent]);

        $costCenter = $this->costCenter($costCenterId);
        $this->assertSame('Renamed', $costCenter->name);
        $this->assertSame('22', $costCenter->code);
        $this->assertSame((string) $parent, (string) $costCenter->parent_id);
    }

    public function testUpdate_RejectsPlacingACostCenterUnderItselfOrItsDescendant(): void
    {
        $parent = $this->service->create(['code' => '1', 'name' => 'Parent']);
        $child  = $this->service->create(['code' => '11', 'name' => 'Child', 'parent_id' => $parent]);

        $this->assertFailsWith(AccountingError::CostCenterCycle, fn () => $this->service->update($parent, ['parent_id' => $parent]));
        $this->assertFailsWith(AccountingError::CostCenterCycle, fn () => $this->service->update($parent, ['parent_id' => $child]));
    }

    public function testUpdate_MovesACostCenterBackToTheRoot(): void
    {
        $parent = $this->service->create(['code' => '1', 'name' => 'Parent']);
        $child  = $this->service->create(['code' => '11', 'name' => 'Child', 'parent_id' => $parent]);

        $this->service->update($child, ['parent_id' => '']);

        $this->assertNull($this->costCenter($child)->parent_id);
    }

    public function testUpdate_RejectsACodeThatBelongsToAnotherCostCenter(): void
    {
        $this->service->create(['code' => '1', 'name' => 'One']);
        $second = $this->service->create(['code' => '2', 'name' => 'Two']);

        $this->assertFailsWith(AccountingError::CodeTaken, fn () => $this->service->update($second, ['code' => '1']));
    }

    public function testDeactivate_DisablesACostCenterWithoutActiveChildren(): void
    {
        $costCenterId = $this->service->create(['code' => '1', 'name' => 'One']);

        $this->service->deactivate($costCenterId);

        $this->assertSame('0', (string) $this->costCenter($costCenterId)->active);
        $this->assertFailsWith(AccountingError::AlreadyInactive, fn () => $this->service->deactivate($costCenterId));
    }

    public function testDeactivate_RejectsACostCenterWithActiveChildren(): void
    {
        $parent = $this->service->create(['code' => '1', 'name' => 'Parent']);
        $this->service->create(['code' => '11', 'name' => 'Child', 'parent_id' => $parent]);

        $this->assertFailsWith(AccountingError::HasActiveChildren, fn () => $this->service->deactivate($parent));
    }

    public function testActivate_RejectsACostCenterWhoseParentIsInactive(): void
    {
        $parent = $this->service->create(['code' => '1', 'name' => 'Parent']);
        $child  = $this->service->create(['code' => '11', 'name' => 'Child', 'parent_id' => $parent]);
        $this->service->deactivate($child);
        $this->service->deactivate($parent);

        $this->assertFailsWith(AccountingError::ParentInactive, fn () => $this->service->activate($child));

        $this->service->activate($parent);
        $this->service->activate($child);
        $this->assertSame('1', (string) $this->costCenter($child)->active);
    }

    public function testDelete_RemovesAnUnusedCostCenter(): void
    {
        $costCenterId = $this->service->create(['code' => '1', 'name' => 'One']);

        $this->service->delete($costCenterId);

        $this->assertNull($this->service->find($costCenterId));
    }

    public function testDelete_RejectsACostCenterWithChildrenOrJournalLines(): void
    {
        $parent = $this->service->create(['code' => '1', 'name' => 'Parent']);
        $child  = $this->service->create(['code' => '11', 'name' => 'Child', 'parent_id' => $parent]);

        $this->assertFailsWith(AccountingError::HasChildren, fn () => $this->service->delete($parent));

        $this->postUsing($child);

        $this->assertFailsWith(AccountingError::CostCenterInUse, fn () => $this->service->delete($child));
        $this->assertNotNull($this->service->find($child));
    }

    public function testAll_FlagsChildrenAndUsage(): void
    {
        $parent = $this->service->create(['code' => '1', 'name' => 'Parent']);
        $child  = $this->service->create(['code' => '11', 'name' => 'Child', 'parent_id' => $parent]);
        $this->postUsing($child);

        $rows = [];

        foreach ($this->service->all() as $row) {
            $rows[$row->code] = $row;
        }

        $this->assertSame('1', (string) $rows['1']->has_children);
        $this->assertSame('0', (string) $rows['1']->used);
        $this->assertSame('1', (string) $rows['11']->used);
    }

    public function testSelectable_ReturnsOnlyActiveCostCenters(): void
    {
        $this->service->create(['code' => '1', 'name' => 'Active']);
        $inactive = $this->service->create(['code' => '2', 'name' => 'Inactive']);
        $this->service->deactivate($inactive);

        $this->assertSame(['1'], array_column($this->service->selectable(), 'code'));
    }

    public function testSuggestCode_FollowsTheHighestNumericCode(): void
    {
        $this->assertSame('1', $this->service->suggestCode());

        $this->service->create(['code' => '7', 'name' => 'Seven']);
        $this->service->create(['code' => 'HQ', 'name' => 'Head office']);

        $this->assertSame('8', $this->service->suggestCode());
    }
}
