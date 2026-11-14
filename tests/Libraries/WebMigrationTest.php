<?php

namespace Tests\Libraries;

use App\Libraries\MigrationLock;
use App\Libraries\MigrationPreflight;
use App\Libraries\WebMigration;
use CodeIgniter\Database\BaseConnection;
use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\DatabaseTestTrait;
use Config\Database;
use RuntimeException;

/**
 * The migrations as a web request starts them: refused first when the operator has something to
 * decide, one request at a time, and a failure is a sentence and not a stack trace.
 */
class WebMigrationTest extends CIUnitTestCase
{
    use DatabaseTestTrait;

    protected $migrate     = true;
    protected $migrateOnce = true;
    protected $refresh     = false;
    protected $namespace   = null;

    private ?BaseConnection $holder = null;

    protected function tearDown(): void
    {
        $this->holder?->close();
        $this->holder = null;

        parent::tearDown();
    }

    private function preflightSaying(?string $refusal): MigrationPreflight
    {
        return new class ($refusal) extends MigrationPreflight {
            public function __construct(private ?string $answer)
            {
            }

            public function refusal(): ?string
            {
                return $this->answer;
            }
        };
    }

    public function testRun_RunsTheMigrationsAndReturnsNothingWhenNothingStopsThem(): void
    {
        $runs = 0;

        $stopped = (new WebMigration(new MigrationLock(null, 0), $this->preflightSaying(null)))->run(static function () use (&$runs): void {
            $runs++;
        });

        $this->assertNull($stopped);
        $this->assertSame(1, $runs);
    }

    public function testRun_GivesTheRefusalAndRunsNothing(): void
    {
        $runs = 0;

        $stopped = (new WebMigration(new MigrationLock(null, 0), $this->preflightSaying('Decide first.')))->run(static function () use (&$runs): void {
            $runs++;
        });

        $this->assertSame('Decide first.', $stopped);
        $this->assertSame(0, $runs);
    }

    public function testRun_SaysAnotherRequestIsMigratingWhileTheLockIsTaken(): void
    {
        $this->holder = Database::connect(config('Database')->tests, false);
        $this->holder->query('SELECT GET_LOCK(?, 0)', [MigrationLock::name(Database::connect()->getDatabase())]);
        $runs = 0;

        $stopped = (new WebMigration(new MigrationLock(null, 0), $this->preflightSaying(null)))->run(static function () use (&$runs): void {
            $runs++;
        });

        $this->assertSame(WebMigration::BUSY, $stopped);
        $this->assertSame(0, $runs);
    }

    public function testRun_TurnsAFailureIntoASentenceThatNamesTheLogAndTheCommandWithoutTheMessageOfTheException(): void
    {
        $stopped = (new WebMigration(new MigrationLock(null, 0), $this->preflightSaying(null)))->run(static function (): void {
            throw new RuntimeException("Can't create table `ospos_secret_table`");
        });

        $this->assertSame(WebMigration::FAILED, $stopped);
        $this->assertStringContainsString('writable/logs', $stopped);
        $this->assertStringContainsString('php spark migrate -n App', $stopped);
        $this->assertStringNotContainsString('ospos_secret_table', $stopped);
    }

    public function testRun_ReleasesTheLockAfterAFailureSoThatTheNextRequestCanTry(): void
    {
        (new WebMigration(new MigrationLock(null, 0), $this->preflightSaying(null)))->run(static function (): void {
            throw new RuntimeException('broke');
        });

        $this->assertNull((new WebMigration(new MigrationLock(null, 0), $this->preflightSaying(null)))->run(static function (): void {
        }));
    }
}
