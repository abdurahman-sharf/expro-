<?php

namespace Tests\Libraries\Accounting;

use App\Libraries\Accounting\AccountingError;
use App\Libraries\Accounting\AccountingException;
use App\Libraries\Accounting\AccountingGuard;
use App\Libraries\Accounting\CostCenterService;
use App\Libraries\Accounting\JournalService;
use App\Libraries\Accounting\JournalSettings;
use App\Libraries\Organization\BranchException;
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
        $this->resetJournalSettings();
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

    private function limitedCostCenterManager(): int
    {
        return $this->employeeWithGrants([AccountingGuard::COST_CENTERS_MANAGE], [1]);
    }

    public function testCreate_RejectsAnEmployeeLimitedToBranches(): void
    {
        $this->expectException(BranchException::class);

        $this->service->create(['code' => 'MAIN', 'name' => 'Main'], $this->limitedCostCenterManager());
    }

    public function testUpdate_RejectsAnEmployeeLimitedToBranches(): void
    {
        $costCenterId = $this->service->create(['code' => 'MAIN', 'name' => 'Main']);

        $this->expectException(BranchException::class);

        $this->service->update($costCenterId, ['name' => 'Renamed'], $this->limitedCostCenterManager());
    }

    public function testDeactivate_RejectsAnEmployeeLimitedToBranches(): void
    {
        $costCenterId = $this->service->create(['code' => 'MAIN', 'name' => 'Main']);

        $this->expectException(BranchException::class);

        $this->service->deactivate($costCenterId, $this->limitedCostCenterManager());
    }

    public function testActivate_RejectsAnEmployeeLimitedToBranches(): void
    {
        $costCenterId = $this->service->create(['code' => 'MAIN', 'name' => 'Main']);
        $this->service->deactivate($costCenterId);

        $this->expectException(BranchException::class);

        $this->service->activate($costCenterId, $this->limitedCostCenterManager());
    }

    public function testDelete_RejectsAnEmployeeLimitedToBranches(): void
    {
        $costCenterId = $this->service->create(['code' => 'MAIN', 'name' => 'Main']);

        $this->expectException(BranchException::class);

        $this->service->delete($costCenterId, $this->limitedCostCenterManager());
    }

    /**
     * @return array{0: int, 1: int, 2: int}
     */
    private function threeCenters(): array
    {
        return [
            $this->service->create(['code' => 'A', 'name' => 'Alpha']),
            $this->service->create(['code' => 'B', 'name' => 'Beta']),
            $this->service->create(['code' => 'C', 'name' => 'Gamma']),
        ];
    }

    public function testDistribute_PutsTheRoundingRemainderOnTheLargestShareOfAThreeWaySplit(): void
    {
        [$a, $b, $c] = $this->threeCenters();

        $shares = $this->service->distribute([
            ['cost_center_id' => $a, 'percent' => '33.3333'],
            ['cost_center_id' => $b, 'percent' => '33.3333'],
            ['cost_center_id' => $c, 'percent' => '33.3334'],
        ], '100.0000');

        $this->assertSame(['33.3300', '33.3300', '33.3400'], array_column($shares, 'amount'));
        $this->assertSame('100.0000', bcadd(bcadd($shares[0]['amount'], $shares[1]['amount'], 4), $shares[2]['amount'], 4));
        $this->assertSame(['33.3333', '33.3333', '33.3334'], array_column($shares, 'percent'));
    }

    public function testDistribute_GivesTheRemainderToTheFirstOfTwoEqualLargestShares(): void
    {
        [$a, $b] = $this->threeCenters();

        $shares = $this->service->distribute([
            ['cost_center_id' => $a, 'percent' => '50'],
            ['cost_center_id' => $b, 'percent' => '50'],
        ], '0.01');

        $this->assertSame(['0.0000', '0.0100'], array_column($shares, 'amount'));
    }

    public function testDistribute_RoundsEveryShareToTheDecimalsOfTheBaseCurrency(): void
    {
        [$a, $b, $c] = $this->threeCenters();

        $shares = $this->service->distribute([
            ['cost_center_id' => $a, 'percent' => '10'],
            ['cost_center_id' => $b, 'percent' => '20'],
            ['cost_center_id' => $c, 'percent' => '70'],
        ], '10.0149');

        $this->assertSame(['1.0000', '2.0000', '7.0149'], array_column($shares, 'amount'));
    }

    public function testDistribute_SumsToTheAmountOfTheLineWhateverTheCents(): void
    {
        [$a, $b, $c] = $this->threeCenters();
        $rows        = [['cost_center_id' => $a, 'percent' => '14.2857'], ['cost_center_id' => $b, 'percent' => '28.5714'], ['cost_center_id' => $c, 'percent' => '57.1429']];

        foreach (['0.01', '0.05', '1.00', '99.99', '1234567.89'] as $amount) {
            $sum = '0';

            foreach ($this->service->distribute($rows, $amount) as $share) {
                $sum = bcadd($sum, $share['amount'], 4);
            }

            $this->assertSame(bcadd($amount, '0', 4), $sum, "A line of {$amount}");
        }
    }

    public function testDistribute_ReadsThePercentFromAnAmountWhenNoPercentIsGiven(): void
    {
        [$a, $b] = $this->threeCenters();

        $shares = $this->service->distribute([['cost_center_id' => $a, 'amount' => '25'], ['cost_center_id' => $b, 'percent' => '75']], '100');

        $this->assertSame(['25.0000', '75.0000'], array_column($shares, 'percent'));
        $this->assertSame(['25.0000', '75.0000'], array_column($shares, 'amount'));
    }

    public function testDistribute_RequiresPercentageOrAmountOnEveryRow(): void
    {
        [$a, $b] = $this->threeCenters();

        $this->assertFailsWith(AccountingError::DistributionPercentInvalid, fn () => $this->service->distribute([['cost_center_id' => $a, 'percent' => '100'], ['cost_center_id' => $b]], '100'));
        $this->assertFailsWith(AccountingError::DistributionPercentInvalid, fn () => $this->service->distribute([['cost_center_id' => $a, 'percent' => '', 'amount' => '']], '100'));
    }

    public function testDistribute_RejectsPercentsThatAreNotWholeNumbersUnderTheLimit(): void
    {
        [$a] = $this->threeCenters();

        foreach (['0', '-5', 'abc', '10.12345', '100.0001', '1e2', ' ', '١٠٠'] as $percent) {
            $this->assertFailsWith(AccountingError::DistributionPercentInvalid, fn () => $this->service->distribute([['cost_center_id' => $a, 'percent' => $percent]], '100'));
        }
    }

    public function testDistribute_RejectsPercentsThatDoNotAddUpTo100(): void
    {
        [$a, $b] = $this->threeCenters();

        $this->assertFailsWith(AccountingError::DistributionTotalInvalid, fn () => $this->service->distribute([['cost_center_id' => $a, 'percent' => '50'], ['cost_center_id' => $b, 'percent' => '49.9999']], '100'));
        $this->assertFailsWith(AccountingError::DistributionTotalInvalid, fn () => $this->service->distribute([['cost_center_id' => $a, 'percent' => '50'], ['cost_center_id' => $b, 'percent' => '50.0001']], '100'));
        $this->assertFailsWith(AccountingError::DistributionTotalInvalid, fn () => $this->service->distribute([['cost_center_id' => $a, 'percent' => '33.3333'], ['cost_center_id' => $b, 'percent' => '33.3333'], ['cost_center_id' => $this->service->create(['code' => 'D', 'name' => 'Delta']), 'percent' => '33.3333']], '100'));
    }

    public function testDistribute_AcceptsAnIncompleteDistributionWhenItIsNotRequiredToBeWhole(): void
    {
        [$a, $b] = $this->threeCenters();

        $shares = $this->service->distribute([['cost_center_id' => $a, 'percent' => '30'], ['cost_center_id' => $b, 'percent' => '20']], '100', 1, false);

        $this->assertSame(['30.0000', '20.0000'], array_column($shares, 'amount'));
        $this->assertFailsWith(AccountingError::DistributionTotalInvalid, fn () => $this->service->distribute([['cost_center_id' => $a, 'percent' => '60'], ['cost_center_id' => $b, 'percent' => '50']], '100', 1, false));
    }

    public function testDistribute_AcceptsMoreThan100OnlyWhileTheSettingIsOnAndUpToTheCap(): void
    {
        [$a, $b] = $this->threeCenters();
        $rows    = [['cost_center_id' => $a, 'percent' => '100'], ['cost_center_id' => $b, 'percent' => '50']];

        $this->assertFailsWith(AccountingError::DistributionTotalInvalid, fn () => $this->service->distribute($rows, '200'));

        $this->setConfigValue(JournalSettings::OVER_100, '1');

        $shares = $this->service->distribute($rows, '200');

        $this->assertSame(['200.0000', '100.0000'], array_column($shares, 'amount'));
        $this->assertSame(['100.0000', '100.0000'], array_column($this->service->distribute([['cost_center_id' => $a, 'percent' => '100'], ['cost_center_id' => $b, 'percent' => '100']], '50'), 'percent'));
        $this->assertFailsWith(AccountingError::DistributionOverLimit, fn () => $this->service->distribute([['cost_center_id' => $a, 'percent' => '100'], ['cost_center_id' => $b, 'percent' => '100.0001']], '50'));
        $this->assertFailsWith(AccountingError::DistributionPercentInvalid, fn () => $this->service->distribute([['cost_center_id' => $a, 'percent' => '200.0001']], '50'));
    }

    public function testDistribute_StillRequiresAtLeast100WhenTheSettingAllowsMore(): void
    {
        [$a] = $this->threeCenters();
        $this->setConfigValue(JournalSettings::OVER_100, '1');

        $this->assertFailsWith(AccountingError::DistributionTotalInvalid, fn () => $this->service->distribute([['cost_center_id' => $a, 'percent' => '99.9999']], '100'));
    }

    public function testDistribute_RejectsADuplicateAnInactiveAndAMissingCenterAndNamesTheLine(): void
    {
        [$a, $b] = $this->threeCenters();
        $this->service->deactivate($b);

        $this->assertFailsWith(AccountingError::DistributionDuplicate, fn () => $this->service->distribute([['cost_center_id' => $a, 'percent' => '50'], ['cost_center_id' => (string) $a, 'percent' => '50']], '100'));
        $this->assertFailsWith(AccountingError::LineCostCenterInvalid, fn () => $this->service->distribute([['cost_center_id' => $a, 'percent' => '50'], ['cost_center_id' => $b, 'percent' => '50']], '100', 4));
        $this->assertFailsWith(AccountingError::LineCostCenterInvalid, fn () => $this->service->distribute([['cost_center_id' => 999999, 'percent' => '100']], '100'));

        try {
            $this->service->distribute([['cost_center_id' => $b, 'percent' => '100']], '100', 4);
        } catch (AccountingException $exception) {
            $this->assertSame(4, $exception->context['line']);
        }
    }

    public function testDistribute_RejectsARowWithoutACenterAndMoreRowsThanTheLimit(): void
    {
        [$a] = $this->threeCenters();

        $this->assertFailsWith(AccountingError::DistributionRowInvalid, fn () => $this->service->distribute([['percent' => '100']], '100'));
        $this->assertFailsWith(AccountingError::DistributionRowInvalid, fn () => $this->service->distribute(['x'], '100'));
        $this->assertFailsWith(AccountingError::DistributionRowInvalid, fn () => $this->service->distribute([['cost_center_id' => '0', 'percent' => '100']], '100'));
        $this->assertFailsWith(AccountingError::DistributionTooManyRows, fn () => $this->service->distribute(array_fill(0, CostCenterService::MAX_ROWS + 1, ['cost_center_id' => $a, 'percent' => '1']), '100', 1, false));
    }

    public function testDistribute_ReturnsNothingForNoRows(): void
    {
        $this->assertSame([], $this->service->distribute([], '100'));
    }

    public function testSetDefaults_StoresTheRowsAndWritesTheAuditLog(): void
    {
        [$a, $b] = $this->threeCenters();
        $account = $this->accountId('cash');

        $manager = $this->employeeWithGrants([AccountingGuard::COST_CENTERS_MANAGE]);
        $changed = $this->service->setDefaults($account, [['cost_center_id' => $a, 'percent' => '60'], ['cost_center_id' => $b, 'percent' => '40']], $manager);

        $this->assertTrue($changed);
        $this->assertSame(['60.0000', '40.0000'], array_map(static fn (object $row): string => $row->percent, $this->service->defaultsOf($account)));

        $entry = $this->accountingDb()->table('accounting_audit_log')->where('entity_type', 'account')->where('entity_id', $account)->where('action', 'account_distribution_changed')->get()->getRow();

        $this->assertNotNull($entry);
        $this->assertSame($manager, (int) $entry->employee_id);
        $this->assertSame([], json_decode($entry->details, true)['before']);
        $this->assertCount(2, json_decode($entry->details, true)['after']);
    }

    public function testSetDefaults_ReplacesAndClearsTheRowsAndSaysWhenNothingChanged(): void
    {
        [$a, $b] = $this->threeCenters();
        $account = $this->accountId('cash');

        $this->service->setDefaults($account, [['cost_center_id' => $a, 'percent' => '100']]);

        $this->assertFalse($this->service->setDefaults($account, [['cost_center_id' => $a, 'percent' => '100.0000']]));
        $this->assertTrue($this->service->setDefaults($account, [['cost_center_id' => $b, 'percent' => '100']]));
        $this->assertSame([$b], array_map(static fn (object $row): int => (int) $row->cost_center_id, $this->service->defaultsOf($account)));
        $this->assertTrue($this->service->setDefaults($account, []));
        $this->assertSame([], $this->service->defaultsOf($account));
        $this->assertFalse($this->service->setDefaults($account, []));
    }

    public function testSetDefaults_RejectsRowsThatDoNotAddUpTo100EvenWhenTheSettingAllowsMore(): void
    {
        [$a, $b] = $this->threeCenters();
        $this->setConfigValue(JournalSettings::OVER_100, '1');

        $this->assertFailsWith(AccountingError::DefaultDistributionInvalid, fn () => $this->service->setDefaults($this->accountId('cash'), [['cost_center_id' => $a, 'percent' => '100'], ['cost_center_id' => $b, 'percent' => '50']]));
        $this->assertFailsWith(AccountingError::DefaultDistributionInvalid, fn () => $this->service->setDefaults($this->accountId('cash'), [['cost_center_id' => $a, 'percent' => '90']]));
        $this->assertSame([], $this->service->defaultsOf($this->accountId('cash')));
    }

    public function testSetDefaults_RejectsAGroupAndAMissingAccountAndAnInactiveCenter(): void
    {
        [$a, $b] = $this->threeCenters();
        $this->service->deactivate($b);

        $this->assertFailsWith(AccountingError::DefaultDistributionInvalid, fn () => $this->service->setDefaults($this->accountIdByCode('11'), [['cost_center_id' => $a, 'percent' => '100']]));
        $this->assertFailsWith(AccountingError::AccountNotFound, fn () => $this->service->setDefaults(999999, [['cost_center_id' => $a, 'percent' => '100']]));
        $this->assertFailsWith(AccountingError::DefaultDistributionInvalid, fn () => $this->service->setDefaults($this->accountId('cash'), [['cost_center_id' => $b, 'percent' => '100']]));
    }

    public function testSetDefaults_RequiresThePermissionToManageCostCenters(): void
    {
        [$a] = $this->threeCenters();
        $nobody = $this->employeeWithGrants([]);

        $this->assertFailsWith(AccountingError::PermissionDenied, fn () => $this->service->setDefaults($this->accountId('cash'), [['cost_center_id' => $a, 'percent' => '100']], $nobody));
        $this->assertSame([], $this->service->defaultsOf($this->accountId('cash')));
    }

    public function testSetDefaults_RejectsAnEmployeeLimitedToBranches(): void
    {
        [$a] = $this->threeCenters();

        $this->expectException(BranchException::class);

        $this->service->setDefaults($this->accountId('cash'), [['cost_center_id' => $a, 'percent' => '100']], $this->limitedCostCenterManager());
    }

    public function testDelete_RejectsACostCenterThatAnAccountDefaultsToOrALineIsDistributedOver(): void
    {
        [$a, $b] = $this->threeCenters();
        $this->service->setDefaults($this->accountId('cash'), [['cost_center_id' => $a, 'percent' => '100']]);

        $this->assertFailsWith(AccountingError::CostCenterInUse, fn () => $this->service->delete($a));

        $input = $this->journalInput();
        $input['lines'][0]['distribution'] = [['cost_center_id' => $b, 'percent' => '60'], ['cost_center_id' => $this->service->create(['code' => 'D', 'name' => 'Delta']), 'percent' => '40']];
        (new JournalService())->postNew($input);

        $this->assertFailsWith(AccountingError::CostCenterInUse, fn () => $this->service->delete($b));
        $this->assertTrue($this->service->isUsed($b));

        $rows = [];

        foreach ($this->service->all() as $row) {
            $rows[$row->code] = (string) $row->used;
        }

        $this->assertSame('1', $rows['A']);
        $this->assertSame('1', $rows['B']);
        $this->assertSame('0', $rows['C']);
    }

    public function testDefaultsByAccount_ListsOnlyTheCentersThatAreOn(): void
    {
        [$a, $b] = $this->threeCenters();
        $cash = $this->accountId('cash');

        $this->service->setDefaults($cash, [['cost_center_id' => $a, 'percent' => '70'], ['cost_center_id' => $b, 'percent' => '30']]);
        $this->service->deactivate($b);

        $this->assertSame([$cash => [['cost_center_id' => $a, 'percent' => '70.0000']]], $this->service->defaultsByAccount());
        $this->assertSame([$a], array_map(static fn (object $row): int => (int) $row->cost_center_id, $this->service->defaultsOf($cash, true)));
        $this->assertCount(2, $this->service->defaultsOf($cash));
    }

    public function testCreate_IsIndependentOfTheBranch(): void
    {
        $this->createBranch('B2');
        $costCenterId = $this->service->create(['code' => 'MAIN', 'name' => 'Main'], $this->employeeWithGrants([AccountingGuard::COST_CENTERS_MANAGE]));

        $this->assertNotNull($this->service->find($costCenterId));
    }
}
