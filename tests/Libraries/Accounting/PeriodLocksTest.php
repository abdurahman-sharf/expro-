<?php

namespace Tests\Libraries\Accounting;

use App\Libraries\Accounting\AccountingError;
use App\Libraries\Accounting\AccountingException;
use App\Libraries\Accounting\PeriodLocks;
use CodeIgniter\Database\BaseConnection;
use CodeIgniter\Database\Exceptions\DatabaseException;
use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\DatabaseTestTrait;
use Config\Database;
use Tests\Support\AccountingFixtureTrait;

/**
 * Two real connections, one standing for a posting in progress and one for a closing, show
 * who waits for whom. The second connection gives up after a second instead of waiting out
 * the server's default.
 */
class PeriodLocksTest extends CIUnitTestCase
{
    use DatabaseTestTrait;
    use AccountingFixtureTrait;

    protected $migrate     = true;
    protected $migrateOnce = true;
    protected $refresh     = false;
    protected $namespace   = null;

    private BaseConnection $first;
    private BaseConnection $second;
    private int $branch;

    public static function setUpBeforeClass(): void
    {
        $seeder = Database::seeder('tests');
        $seeder->call('TestDatabaseBootstrapSeeder');
    }

    protected function setUp(): void
    {
        parent::setUp();

        $this->clearJournals();

        $this->first  = $this->accountingDb();
        $this->second = Database::connect('tests', false);
        $this->second->query('SET SESSION innodb_lock_wait_timeout = 1');
        $this->branch = $this->createBranch('B2');

        (new PeriodLocks($this->first))->shareForPosting($this->branch);
        $this->first->query('SELECT 1');
        $this->releaseOpenTransactions();
    }

    protected function tearDown(): void
    {
        $this->releaseOpenTransactions();
        $this->second->transRollback();
        $this->second->close();

        parent::tearDown();
    }

    private function inTransaction(BaseConnection $connection): void
    {
        $connection->resetTransStatus();
        $connection->transStart();
    }

    private function assertWaits(callable $action): void
    {
        try {
            $action();
        } catch (AccountingException $exception) {
            $this->assertSame(AccountingError::WriteFailed, $exception->error);

            return;
        } catch (DatabaseException $exception) {
            $this->assertStringContainsString('Lock wait timeout', $exception->getMessage());

            return;
        }

        $this->fail('Expected the request to wait for the lock.');
    }

    public function testShareForPosting_LetsTwoPostingsInDifferentBranchesRunTogether(): void
    {
        $this->inTransaction($this->first);
        $this->inTransaction($this->second);

        (new PeriodLocks($this->first))->shareForPosting(1);
        (new PeriodLocks($this->second))->shareForPosting($this->branch);

        $this->addToAssertionCount(1);
    }

    public function testShareForPosting_LetsTwoPostingsInTheSameBranchRunTogether(): void
    {
        $this->inTransaction($this->first);
        $this->inTransaction($this->second);

        (new PeriodLocks($this->first))->shareForPosting(1);
        (new PeriodLocks($this->second))->shareForPosting(1);

        $this->addToAssertionCount(1);
    }

    public function testHoldCompany_WaitsForAPostingInProgress(): void
    {
        $this->inTransaction($this->first);
        $this->inTransaction($this->second);

        (new PeriodLocks($this->first))->shareForPosting(1);

        $this->assertWaits(fn () => (new PeriodLocks($this->second))->holdCompany());
    }

    public function testHoldCompany_GoesAheadOnceThePostingIsDone(): void
    {
        $this->inTransaction($this->first);
        (new PeriodLocks($this->first))->shareForPosting(1);
        $this->first->transComplete();

        $this->inTransaction($this->second);
        (new PeriodLocks($this->second))->holdCompany();

        $this->addToAssertionCount(1);
    }

    public function testShareForPosting_WaitsForAClosingOfTheCompany(): void
    {
        $this->inTransaction($this->first);
        $this->inTransaction($this->second);

        (new PeriodLocks($this->first))->holdCompany();

        $this->assertWaits(fn () => (new PeriodLocks($this->second))->shareForPosting($this->branch));
    }

    public function testHoldBranch_WaitsOnlyForPostingsInThatBranch(): void
    {
        $this->inTransaction($this->first);
        $this->inTransaction($this->second);

        (new PeriodLocks($this->first))->shareForPosting($this->branch);

        (new PeriodLocks($this->second))->holdBranch(1);

        $this->assertWaits(fn () => (new PeriodLocks($this->second))->holdBranch($this->branch));
    }

    public function testShareForPosting_WaitsForAClosingOfItsOwnBranchButNotAnother(): void
    {
        $this->inTransaction($this->first);
        $this->inTransaction($this->second);

        (new PeriodLocks($this->first))->holdBranch($this->branch);

        (new PeriodLocks($this->second))->shareForPosting(1);

        $this->assertWaits(fn () => (new PeriodLocks($this->second))->shareForPosting($this->branch));
    }

    public function testShareForPosting_CreatesTheRowOfABranchThatHasNone(): void
    {
        $this->accountingDb()->table('accounting_period_locks')->where('branch_id', $this->branch)->delete();

        $this->inTransaction($this->first);
        (new PeriodLocks($this->first))->shareForPosting($this->branch);

        $this->assertSame(1, $this->accountingDb()->table('accounting_period_locks')->where('branch_id', $this->branch)->countAllResults());
    }
}
