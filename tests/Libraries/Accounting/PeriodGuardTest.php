<?php

namespace Tests\Libraries\Accounting;

use App\Libraries\Accounting\AccountingGuard;
use App\Libraries\Accounting\ClosedPeriodService;
use App\Libraries\Accounting\PeriodGuard;
use App\Libraries\Accounting\PostingError;
use App\Libraries\Accounting\PostingException;
use CodeIgniter\Database\BaseConnection;
use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\DatabaseTestTrait;
use Config\Database;
use Tests\Support\AccountingFixtureTrait;

/**
 * A poster inside a longer transaction may hold a snapshot older than a closing that has
 * committed since. Two real connections stand for the poster and for whoever closes.
 */
class PeriodGuardTest extends CIUnitTestCase
{
    use DatabaseTestTrait;
    use AccountingFixtureTrait;

    protected $migrate     = true;
    protected $migrateOnce = true;
    protected $refresh     = false;
    protected $namespace   = null;

    private BaseConnection $poster;
    private BaseConnection $closer;

    public static function setUpBeforeClass(): void
    {
        $seeder = Database::seeder('tests');
        $seeder->call('TestDatabaseBootstrapSeeder');
    }

    protected function setUp(): void
    {
        parent::setUp();

        $this->clearJournals();

        $this->poster = $this->accountingDb();
        $this->closer = $this->openSecondConnection();
    }

    protected function tearDown(): void
    {
        $this->releaseOpenTransactions();
        $this->closer->transRollback();
        $this->closer->close();

        parent::tearDown();
    }

    private function assertRefusedWith(PostingError $expected, callable $action): PostingException
    {
        try {
            $action();
        } catch (PostingException $exception) {
            $this->assertSame($expected, $exception->error);

            return $exception;
        }

        $this->fail('Expected the failure ' . $expected->value . '.');
    }

    public function testEnforce_RefusesADateTheCompanyClosedAfterTheSnapshotWasTaken(): void
    {
        $this->startWithSnapshot($this->poster);
        $this->closer->table('accounting_closed_periods')->insert(['company_id' => 1, 'branch_id' => null, 'date_from' => '2020-01-01', 'date_to' => '2026-03-31', 'active' => 1]);

        $exception = $this->assertRefusedWith(PostingError::PeriodClosed, fn () => (new PeriodGuard($this->poster))->enforce('2026-03-15', 1));

        $this->assertSame('2026-03-31', $exception->context['until']);
    }

    public function testEnforce_RefusesADateTheBranchClosedAfterTheSnapshotWasTaken(): void
    {
        $branch = $this->createBranch('B2');

        $this->startWithSnapshot($this->poster);
        $this->closer->table('accounting_closed_periods')->insert(['company_id' => 1, 'branch_id' => $branch, 'date_from' => '2020-01-01', 'date_to' => '2026-03-31', 'active' => 1]);

        $exception = $this->assertRefusedWith(PostingError::PeriodClosed, fn () => (new PeriodGuard($this->poster))->enforce('2026-03-15', $branch));

        $this->assertSame('B2', $exception->context['scope_name']);
        (new PeriodGuard($this->poster))->enforce('2026-03-15', 1);
    }

    public function testEnforce_AcceptsADateTheCompanyReopenedAfterTheSnapshotWasTaken(): void
    {
        $this->closeThrough('2026-03-31');

        $this->startWithSnapshot($this->poster);
        $this->closer->table('accounting_closed_periods')->update(['active' => 0]);

        (new PeriodGuard($this->poster))->enforce('2026-03-15', 1);

        $this->addToAssertionCount(1);
    }

    public function testEnforce_RefusesADateWhoseFiscalYearWasDeletedAfterTheSnapshotWasTaken(): void
    {
        $this->startWithSnapshot($this->poster);
        $this->closer->table('accounting_fiscal_years')->where('company_id', 1)->where('start_date', '2035-01-01')->delete();

        $this->assertRefusedWith(PostingError::OutsideFiscalYear, fn () => (new PeriodGuard($this->poster))->enforce('2035-06-01', 1));
    }

    public function testEnforce_WaitsForAClosingBeingWrittenAndThenRefusesTheDate(): void
    {
        $service = new ClosedPeriodService($this->closer);
        $this->closer->resetTransStatus();
        $this->closer->transStart();
        $service->close(null, '2026-03-31', '', $this->employeeWithGrants([AccountingGuard::PERIODS_CLOSE]));

        $this->poster->resetTransStatus();
        $this->poster->transStart();

        try {
            (new PeriodGuard($this->poster))->enforce('2026-03-15', 1);
            $this->fail('Expected the posting to wait for the closing.');
        } catch (\Throwable $exception) {
            $this->assertStringContainsString('lock', strtolower($exception->getMessage()));
        }

        $this->closer->transComplete();
        $this->poster->transRollback();
        $this->poster->resetTransStatus();

        $this->poster->transStart();
        $this->assertRefusedWith(PostingError::PeriodClosed, fn () => (new PeriodGuard($this->poster))->enforce('2026-03-15', 1));
    }
}
