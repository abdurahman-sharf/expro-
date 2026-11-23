<?php

namespace Tests\Libraries\Database;

use App\Libraries\Database\OpenTransactionHandler;
use CodeIgniter\Database\Config as DatabaseConfig;
use CodeIgniter\Database\MySQLi\Connection;
use CodeIgniter\Debug\ExceptionHandlerInterface;
use CodeIgniter\HTTP\RequestInterface;
use CodeIgniter\HTTP\Response;
use CodeIgniter\HTTP\ResponseInterface;
use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\DatabaseTestTrait;
use Config\Database;
use ReflectionProperty;
use RuntimeException;
use Tests\Support\TransactionStateTrait;
use Throwable;
use TypeError;

/**
 * A request that stops on an exception does not leave its transaction open for the writes that follow it,
 * such as the one of the session.
 *
 * @internal
 */
final class OpenTransactionHandlerTest extends CIUnitTestCase
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

    private function spy(): object
    {
        return new class () implements ExceptionHandlerInterface {
            /** @var list<array{Throwable, int, int, int}> */
            public array $calls = [];

            public function handle(Throwable $exception, RequestInterface $request, ResponseInterface $response, int $statusCode, int $exitCode): void
            {
                $this->calls[] = [$exception, Database::connect()->transDepth, $statusCode, $exitCode];
            }
        };
    }

    private function handle(OpenTransactionHandler $handler, Throwable $exception, int $statusCode = 500, int $exitCode = 1): void
    {
        $handler->handle($exception, service('incomingrequest', null, false), new Response(config('App')), $statusCode, $exitCode);
    }

    /**
     * @param array<string, Connection> $connections
     */
    private function withConnections(array $connections, callable $then): void
    {
        $instances = new ReflectionProperty(DatabaseConfig::class, 'instances');
        $kept      = $instances->getValue();

        $instances->setValue(null, $connections);

        try {
            $then();
        } finally {
            $instances->setValue(null, $kept);
        }
    }

    public function testHandle_RollsBackWhatTheRequestLeftOpenBeforeItPassesTheExceptionOn(): void
    {
        $key       = 'handler_' . uniqid();
        $db        = Database::connect();
        $next      = $this->spy();
        $exception = new RuntimeException('The request failed.');

        $db->transStart();
        $db->transStart();
        $db->table('app_config')->insert(['key' => $key, 'value' => '1']);

        $this->handle(new OpenTransactionHandler($next), $exception);

        $this->assertSame([[$exception, 0, 500, 1]], $next->calls);
        $this->assertSame(0, $db->table('app_config')->where('key', $key)->countAllResults());
    }

    public function testHandle_ForgetsTheFailedStatusSoThatTheWritesThatFollowCanCommit(): void
    {
        $db = Database::connect();

        $db->transStart();
        $db->query('SELECT * FROM table_that_does_not_exist_for_the_test');

        $this->handle(new OpenTransactionHandler($this->spy()), new RuntimeException('The request failed.'));

        $this->assertTrue($db->transStatus());
    }

    public function testHandle_PassesTheExceptionOnWhenNoTransactionIsOpen(): void
    {
        $next      = $this->spy();
        $exception = new RuntimeException('The request failed.');

        $this->handle(new OpenTransactionHandler($next), $exception);

        $this->assertSame([[$exception, 0, 500, 1]], $next->calls);
    }

    public function testHandle_PassesTheStatusAndTheExitCodeOfTheRequestOn(): void
    {
        $next      = $this->spy();
        $exception = new RuntimeException('The page is not there.');

        $this->handle(new OpenTransactionHandler($next), $exception, 404, 3);

        $this->assertSame([[$exception, 0, 404, 3]], $next->calls);
    }

    public function testHandle_RollsBackEveryConnectionTheRequestMade(): void
    {
        $first  = Database::connect();
        $second = new Connection((new Database())->tests);

        $this->withConnections(['first' => $first, 'second' => $second], function () use ($first, $second): void {
            $first->transStart();
            $second->transStart();

            $this->handle(new OpenTransactionHandler($this->spy()), new RuntimeException('The request failed.'));

            $this->assertSame([0, 0], [$first->transDepth, $second->transDepth]);
        });

        $second->close();
    }

    public function testHandle_PassesTheExceptionOnWhenAConnectionCannotBeEnded(): void
    {
        $broken = new class () extends Connection {
            public function __construct()
            {
            }

            public function resetTransStatus(): static
            {
                throw new TypeError('The connection answered with the wrong type.');
            }
        };
        $next = new class () implements ExceptionHandlerInterface {
            public int $calls = 0;

            public function handle(Throwable $exception, RequestInterface $request, ResponseInterface $response, int $statusCode, int $exitCode): void
            {
                $this->calls++;
            }
        };

        $this->withConnections(['broken' => $broken], function () use ($next): void {
            $this->handle(new OpenTransactionHandler($next), new RuntimeException('The request failed.'));
        });

        $this->assertSame(1, $next->calls);
    }

    public function testHandle_OpensNoConnectionWhenTheRequestMadeNone(): void
    {
        $next = new class () implements ExceptionHandlerInterface {
            public int $calls = 0;

            public function handle(Throwable $exception, RequestInterface $request, ResponseInterface $response, int $statusCode, int $exitCode): void
            {
                $this->calls++;
            }
        };
        $opened = null;

        $this->withConnections([], function () use ($next, &$opened): void {
            $this->handle(new OpenTransactionHandler($next), new RuntimeException('The request failed.'));

            $opened = DatabaseConfig::getConnections();
        });

        $this->assertSame([], $opened);
        $this->assertSame(1, $next->calls);
    }
}
