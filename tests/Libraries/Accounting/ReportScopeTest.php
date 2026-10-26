<?php

namespace Tests\Libraries\Accounting;

use App\Libraries\Accounting\ReportScope;
use App\Libraries\Accounting\ReportScopeKind;
use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\DatabaseTestTrait;
use Config\Database;
use Tests\Support\AccountingFixtureTrait;

class ReportScopeTest extends CIUnitTestCase
{
    use DatabaseTestTrait;
    use AccountingFixtureTrait;

    protected $migrate     = true;
    protected $migrateOnce = true;
    protected $refresh     = false;
    protected $namespace   = null;

    private int $second;
    private int $third;

    public static function setUpBeforeClass(): void
    {
        $seeder = Database::seeder('tests');
        $seeder->call('TestDatabaseBootstrapSeeder');
    }

    protected function setUp(): void
    {
        parent::setUp();

        $this->resetBranches();

        $this->second = $this->createBranch('S2', 'Second branch');
        $this->third  = $this->createBranch('S3', 'Third branch');
    }

    protected function tearDown(): void
    {
        $this->resetBranches();

        parent::tearDown();
    }

    public function testResolve_IsTheWholeCompanyForSomeoneWhoWorksInEveryBranchAndAsksForNothing(): void
    {
        $scope = ReportScope::resolve(null, null);

        $this->assertSame([1, $this->second, $this->third], $scope->ids);
        $this->assertSame(ReportScopeKind::Company, $scope->kind);
        $this->assertTrue($scope->isCompany());
        $this->assertSame([1 => 'HQ', $this->second => 'S2', $this->third => 'S3'], $scope->codes);
    }

    public function testResolve_IsTheBranchesAskedForWhenThereAreFewerThanTheCompanyHas(): void
    {
        $scope = ReportScope::resolve([$this->third, 1], null);

        $this->assertSame([1, $this->third], $scope->ids);
        $this->assertSame(ReportScopeKind::Selected, $scope->kind);
        $this->assertSame([1 => 'HQ', $this->third => 'S3'], $scope->codes);
    }

    public function testResolve_IsTheWholeCompanyWhenEveryBranchIsAskedFor(): void
    {
        $this->assertSame(ReportScopeKind::Company, ReportScope::resolve([1, $this->second, $this->third], null)->kind);
    }

    public function testResolve_IsPartialForSomeoneLimitedToSomeBranchesWhoAsksForNothing(): void
    {
        $scope = ReportScope::resolve(null, [1, $this->second]);

        $this->assertSame([1, $this->second], $scope->ids);
        $this->assertSame(ReportScopeKind::Partial, $scope->kind);
        $this->assertFalse($scope->isCompany());
    }

    public function testResolve_StaysPartialWhenTheyAskForAllTheirBranches(): void
    {
        $this->assertSame(ReportScopeKind::Partial, ReportScope::resolve([1, $this->second], [1, $this->second])->kind);
    }

    public function testResolve_NeverGivesABranchOutsideWhatTheEmployeeWorksIn(): void
    {
        $scope = ReportScope::resolve([$this->second, $this->third], [1, $this->second]);

        $this->assertSame([$this->second], $scope->ids);
        $this->assertSame(ReportScopeKind::Selected, $scope->kind);
    }

    public function testResolve_IsTheWholeCompanyForSomeoneLimitedToEveryBranchOneByOne(): void
    {
        $this->assertSame(ReportScopeKind::Company, ReportScope::resolve(null, [1, $this->second, $this->third])->kind);
    }

    public function testResolve_IsEmptyForSomeoneWhoWorksInNoBranchOrAsksForABranchThatIsNotTheirs(): void
    {
        $none  = ReportScope::resolve(null, []);
        $other = ReportScope::resolve([$this->third], [1]);

        $this->assertTrue($none->isEmpty());
        $this->assertSame([], $none->ids);
        $this->assertTrue($other->isEmpty());
        $this->assertFalse($other->isCompany());
    }

    public function testResolve_IgnoresBranchesThatDoNotExistAndRepeats(): void
    {
        $scope = ReportScope::resolve([$this->second, $this->second, 987654], null);

        $this->assertSame([$this->second], $scope->ids);
    }
}
