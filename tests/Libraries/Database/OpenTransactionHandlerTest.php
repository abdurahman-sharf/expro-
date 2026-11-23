<?php

namespace Tests\Libraries\Database;

use App\Libraries\Database\OpenTransactionHandler;
use CodeIgniter\Debug\ExceptionHandlerInterface;
use CodeIgniter\HTTP\RequestInterface;
use CodeIgniter\HTTP\Response;
use CodeIgniter\HTTP\ResponseInterface;
use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\DatabaseTestTrait;
use Config\Database;
use RuntimeException;
use Throwable;

/**
 * A request that stops on an exception does not leave its transaction open for the writes that follow it,
 * such as the one of the session.
 *
 * @internal
 */
final class OpenTransactionHandlerTest extends CIUnitTestCase
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
        $db = Database::connect();

        while ($db->transDepth > 0) {
            $db->transRollback();
        }

        $db->resetTransStatus();

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

    private function handle(OpenTransactionHandler $handler, Throwable $exception): void
    {
        $handler->handle($exception, service('incomingrequest', null, false), new Response(config('App')), 500, 1);
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
}
