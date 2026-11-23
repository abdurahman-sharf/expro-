<?php

namespace Tests\Libraries\Database;

use App\Libraries\Database\AbandonedTransaction;
use CodeIgniter\Database\MySQLi\Connection;
use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\DatabaseTestTrait;
use Config\Database;
use RuntimeException;
use Tests\Support\TransactionStateTrait;
use TypeError;

/**
 * A transaction that the work which began it did not get to end is ended, down to the depth the work
 * began at, and never beyond it.
 *
 * @internal
 */
final class AbandonedTransactionTest extends CIUnitTestCase
{
    use DatabaseTestTrait;
    use TransactionStateTrait;

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
        $this->closeOpenTransaction();

        parent::tearDown();
    }

    private function writeInsideATransaction(string $key): void
    {
        $db = Database::connect();

        $db->transStart();
        $db->table('app_config')->insert(['key' => $key, 'value' => '1']);
    }

    private function isWritten(string $key): bool
    {
        return Database::connect()->table('app_config')->where('key', $key)->countAllResults() === 1;
    }

    private function failInsideATransaction(): void
    {
        $db = Database::connect();

        $db->transStart();
        $db->query('SELECT * FROM table_that_does_not_exist_for_the_test');
    }

    public function testClose_RollsBackEveryLevelAboveTheDepth(): void
    {
        $key = 'abandoned_' . uniqid();
        $db  = Database::connect();

        $this->writeInsideATransaction($key);
        $db->transStart();

        AbandonedTransaction::close($db, 0);

        $this->assertSame(0, $db->transDepth);
        $this->assertFalse($this->isWritten($key));
    }

    public function testClose_LeavesTheLevelsOfTheCaller(): void
    {
        $db = Database::connect();

        $db->transStart();
        $db->transStart();
        $db->transStart();

        AbandonedTransaction::close($db, 1);

        $this->assertSame(1, $db->transDepth);
    }

    public function testClose_DoesNothingWhenNoLevelIsAboveTheDepth(): void
    {
        $key = 'abandoned_' . uniqid();
        $db  = Database::connect();

        $this->writeInsideATransaction($key);

        AbandonedTransaction::close($db, 1);

        $this->assertSame(1, $db->transDepth);
        $this->assertTrue($this->isWritten($key));
    }

    public function testClose_ForgetsTheFailedStatusWhenNoTransactionIsLeft(): void
    {
        $db = Database::connect();

        $this->failInsideATransaction();

        AbandonedTransaction::close($db, 0);

        $this->assertTrue($db->transStatus());
    }

    public function testClose_LeavesTheFailedStatusToTheCallerWhoseTransactionIsStillOpen(): void
    {
        $db = Database::connect();

        $db->transStart();
        $this->failInsideATransaction();

        AbandonedTransaction::close($db, 1);

        $this->assertSame(1, $db->transDepth);
        $this->assertFalse($db->transStatus());
    }

    public function testClose_StopsWhenATransactionCannotBeRolledBackAndRaisesNothing(): void
    {
        $connection = new class () extends Connection {
            public function __construct()
            {
            }

            public function open(int $levels): void
            {
                $this->transDepth = $levels;
            }

            public function transRollback(): bool
            {
                throw new RuntimeException('The connection is gone.');
            }
        };

        $connection->open(2);

        AbandonedTransaction::close($connection, 0);

        $this->assertSame(2, $connection->transDepth);
    }

    public function testClose_StopsAtTheFirstRollbackThatFails(): void
    {
        $connection = new class () extends Connection {
            public int $rollbacks = 0;

            public function __construct()
            {
            }

            public function open(int $levels): void
            {
                $this->transDepth = $levels;
            }

            public function transRollback(): bool
            {
                if (++$this->rollbacks > 3) {
                    throw new RuntimeException('Runaway.');
                }

                return false;
            }
        };

        $connection->open(5);

        AbandonedTransaction::close($connection, 0);

        $this->assertSame(1, $connection->rollbacks);
    }

    public function testGuard_ReturnsWhatTheWorkReturns(): void
    {
        $this->assertSame('done', AbandonedTransaction::guard(Database::connect(), static fn (): string => 'done'));
    }

    public function testGuard_RollsBackWhatTheWorkLeftOpenAndRaisesTheSameException(): void
    {
        $key     = 'abandoned_' . uniqid();
        $db      = Database::connect();
        $failure = new RuntimeException('The work failed.');
        $thrown  = null;

        try {
            AbandonedTransaction::guard($db, function () use ($key, $failure): never {
                $this->writeInsideATransaction($key);

                throw $failure;
            });
        } catch (RuntimeException $caught) {
            $thrown = $caught;
        }

        $this->assertSame($failure, $thrown);
        $this->assertSame(0, $db->transDepth);
        $this->assertFalse($this->isWritten($key));
    }

    public function testGuard_RollsBackWhatAnErrorOfTheWorkLeftOpen(): void
    {
        $db     = Database::connect();
        $error  = new TypeError('The work failed with an Error.');
        $thrown = null;

        try {
            AbandonedTransaction::guard($db, function () use ($error): never {
                $this->writeInsideATransaction('abandoned_' . uniqid());

                throw $error;
            });
        } catch (TypeError $caught) {
            $thrown = $caught;
        }

        $this->assertSame($error, $thrown);
        $this->assertSame(0, $db->transDepth);
    }

    public function testGuard_LeavesTheTransactionOfTheCallerOpen(): void
    {
        $db     = Database::connect();
        $thrown = null;

        $db->transStart();

        try {
            AbandonedTransaction::guard($db, function (): never {
                $this->writeInsideATransaction('abandoned_' . uniqid());

                throw new RuntimeException('The work failed.');
            });
        } catch (RuntimeException $caught) {
            $thrown = $caught;
        }

        $this->assertNotNull($thrown);
        $this->assertSame(1, $db->transDepth);
    }

    public function testGuard_DoesNotRollBackAgainWhatTheWorkEndedItself(): void
    {
        $db     = Database::connect();
        $thrown = null;

        $db->transStart();

        try {
            AbandonedTransaction::guard($db, static function () use ($db): never {
                $db->transStart();
                $db->transRollback();

                throw new RuntimeException('The work failed.');
            });
        } catch (RuntimeException $caught) {
            $thrown = $caught;
        }

        $this->assertNotNull($thrown);
        $this->assertSame(1, $db->transDepth);
    }
}
