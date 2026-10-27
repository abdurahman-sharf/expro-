<?php

namespace Tests\Models\Reports;

use App\Models\Reports\Report;
use CodeIgniter\Database\BaseBuilder;
use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\DatabaseTestTrait;
use Config\Database;
use RuntimeException;
use Tests\Support\AccountingFixtureTrait;

/**
 * What every report asks first: which branches the logged-in employee may read, and the limit that
 * puts on a query, whether it is built or written by hand.
 */
class ReportTest extends CIUnitTestCase
{
    use DatabaseTestTrait;
    use AccountingFixtureTrait;

    protected $migrate     = true;
    protected $migrateOnce = true;
    protected $refresh     = false;
    protected $namespace   = null;

    public static function setUpBeforeClass(): void
    {
        $seeder = Database::seeder('tests');
        $seeder->call('TestDatabaseBootstrapSeeder');
    }

    protected function tearDown(): void
    {
        session()->remove('person_id');

        parent::tearDown();
    }

    private function report(): object
    {
        return new class () extends Report {
            public function getDataColumns(): array
            {
                return [];
            }

            public function getData(array $inputs): array
            {
                return [];
            }

            public function getSummaryData(array $inputs): array
            {
                return [];
            }

            public function branches(): ?array
            {
                return $this->branchIds();
            }

            public function condition(string $column): string
            {
                return $this->branchCondition($column);
            }

            public function limited(array $inputs): array
            {
                return $this->limitedInputs($inputs);
            }

            public function limitBuilder(BaseBuilder $builder): void
            {
                $this->limitToBranches($builder, 'sales.branch_id');
            }
        };
    }

    public function testBranchIds_ReturnsTheBranchesTheLoggedInEmployeeWorksIn(): void
    {
        $second = $this->createBranch('B2');

        session()->set('person_id', $this->employeeWithGrants([], [1, $second]));
        $this->assertEqualsCanonicalizing([1, $second], $this->report()->branches());

        session()->set('person_id', $this->employeeWithGrants([], [$second]));
        $this->assertSame([$second], $this->report()->branches());
    }

    public function testBranchIds_ReturnsNullForAnEmployeeOfEveryBranchAndForTheSystem(): void
    {
        session()->set('person_id', $this->employeeWithGrants([]));
        $this->assertNull($this->report()->branches());

        session()->remove('person_id');
        $this->assertNull($this->report()->branches());
    }

    public function testBranchIds_ReturnsNoneForAnEmployeeWhoWorksInNoBranch(): void
    {
        session()->set('person_id', $this->employeeWithGrants([], []));

        $this->assertSame([], $this->report()->branches());
    }

    public function testBranchCondition_LimitsAQueryWrittenByHandToTheBranches(): void
    {
        $second = $this->createBranch('B3');
        $report = $this->report();

        session()->set('person_id', $this->employeeWithGrants([], [$second]));
        $this->assertSame(" AND sales.branch_id IN ({$second})", $report->condition('sales.branch_id'));

        session()->set('person_id', $this->employeeWithGrants([], []));
        $this->assertSame(' AND 1 = 0', $report->condition('sales.branch_id'));

        session()->set('person_id', $this->employeeWithGrants([]));
        $this->assertSame('', $report->condition('sales.branch_id'));
    }

    public function testLimitedInputs_AddsTheBranchesToTheInputsOfAReport(): void
    {
        $report = $this->report();

        session()->set('person_id', $this->employeeWithGrants([], [1]));
        $this->assertSame(['start_date' => 'x', 'branch_ids' => [1]], $report->limited(['start_date' => 'x']));

        session()->set('person_id', $this->employeeWithGrants([]));
        $this->assertSame(['start_date' => 'x', 'branch_ids' => null], $report->limited(['start_date' => 'x']));
    }

    public function testLimitToBranches_KeepsTheTableAliasesOfTheQueryBeingBuilt(): void
    {
        $second = $this->createBranch('B4');
        $db     = Database::connect();

        session()->set('person_id', $this->employeeWithGrants([], [$second]));

        $builder = $db->table('sales AS sales');

        $this->report()->limitBuilder($builder);

        $this->assertContains('sales', $db->aliasedTables);
        $this->assertStringContainsString('`sales`.`branch_id` IN (' . $second . ')', $builder->getCompiledSelect());
    }

    public function testLimitToBranches_RejectsEveryRowToAnEmployeeWhoWorksInNoBranch(): void
    {
        session()->set('person_id', $this->employeeWithGrants([], []));

        $builder = Database::connect()->table('sales AS sales');

        $this->report()->limitBuilder($builder);

        $this->assertStringContainsString('1 = 0', $builder->getCompiledSelect());
    }

    public function testLimitToBranches_LeavesTheQueryAloneForAnEmployeeOfEveryBranch(): void
    {
        session()->set('person_id', $this->employeeWithGrants([]));

        $builder = Database::connect()->table('sales AS sales');

        $this->report()->limitBuilder($builder);

        $this->assertStringNotContainsString('branch_id', $builder->getCompiledSelect());
    }

    public function testReadingTheCompany_ReadsEveryBranchForAnEmployeeOfOneAndLimitsTheReadsThatFollow(): void
    {
        session()->set('person_id', $this->employeeWithGrants([], [1]));
        $report = $this->report();

        $this->assertSame([1], $report->branches());
        $this->assertNull($report->readingTheCompany(static fn (): ?array => $report->branches()));
        $this->assertSame([1], $report->branches());
    }

    public function testReadingTheCompany_LimitsTheReadsThatFollowAReadThatFailed(): void
    {
        session()->set('person_id', $this->employeeWithGrants([], [1]));
        $report = $this->report();

        try {
            $report->readingTheCompany(static function (): never {
                throw new RuntimeException('The read failed');
            });
        } catch (RuntimeException) {
        }

        $this->assertSame([1], $report->branches());
    }
}
