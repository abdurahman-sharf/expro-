<?php

namespace Tests\Libraries\Database;

use App\Libraries\Database\FailedTransaction;
use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\DatabaseTestTrait;
use Config\Database;

/**
 * The failure of a transaction is forgotten once no transaction is open, and never while one is.
 *
 * @internal
 */
final class FailedTransactionTest extends CIUnitTestCase
{
    use DatabaseTestTrait;

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
        Database::connect()->resetTransStatus();

        parent::tearDown();
    }

    private function failInsideATransaction(): void
    {
        $db = Database::connect();

        $db->transStart();
        $db->query('SELECT * FROM table_that_does_not_exist_for_the_test');
    }

    public function testForget_MakesTheNextTransactionCommitAfterOneFailed(): void
    {
        $db = Database::connect();

        $this->failInsideATransaction();
        $db->transComplete();

        $this->assertFalse($db->transStatus());

        FailedTransaction::forget($db);

        $db->transStart();
        $db->transComplete();

        $this->assertTrue($db->transStatus());
    }

    public function testForget_LeavesTheFailureToTheOwnerOfTheTransactionThatIsStillOpen(): void
    {
        $db = Database::connect();

        $this->failInsideATransaction();
        FailedTransaction::forget($db);

        $this->assertFalse($db->transStatus());

        $db->transComplete();
    }
}
