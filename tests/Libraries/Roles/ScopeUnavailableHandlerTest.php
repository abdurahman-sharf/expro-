<?php

namespace Tests\Libraries\Roles;

use App\Libraries\Roles\RecordScopeUnavailableException;
use App\Libraries\Roles\RoleGrants;
use App\Libraries\Roles\RoleTablesUnavailableException;
use App\Libraries\Roles\ScopeUnavailableHandler;
use App\Models\Employee;
use CodeIgniter\Config\Factories;
use CodeIgniter\Database\Exceptions\DatabaseException;
use CodeIgniter\Debug\ExceptionHandlerInterface;
use CodeIgniter\HTTP\CLIRequest;
use CodeIgniter\HTTP\IncomingRequest;
use CodeIgniter\HTTP\RequestInterface;
use CodeIgniter\HTTP\Response;
use CodeIgniter\HTTP\ResponseInterface;
use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\DatabaseTestTrait;
use Config\Database;
use Config\OSPOS;
use Config\Services;
use ReflectionProperty;
use RuntimeException;
use stdClass;
use Tests\Support\FailingTableConnection;
use Throwable;

/**
 * @internal
 */
final class ScopeUnavailableHandlerTest extends CIUnitTestCase
{
    use DatabaseTestTrait;

    protected $migrate     = true;
    protected $migrateOnce = true;
    protected $refresh     = false;
    protected $namespace;

    private string $language;

    public static function setUpBeforeClass(): void
    {
        Database::seeder('tests')->call('TestDatabaseBootstrapSeeder');
    }

    protected function setUp(): void
    {
        parent::setUp();

        $_SESSION = [];
        $this->language = config(OSPOS::class)->settings['language_code'] ?? 'en';
        service('language')->setLocale($this->language);
    }

    protected function tearDown(): void
    {
        config(OSPOS::class)->settings['language_code'] = $this->language;
        service('language')->setLocale($this->language);
        Factories::reset('models');
        $_SESSION = [];

        parent::tearDown();
    }

    private function spy(): object
    {
        return new class () implements ExceptionHandlerInterface {
            /** @var list<array{Throwable, RequestInterface, ResponseInterface, int, int}> */
            public array $calls = [];

            public function handle(Throwable $exception, RequestInterface $request, ResponseInterface $response, int $statusCode, int $exitCode): void
            {
                $this->calls[] = [$exception, $request, $response, $statusCode, $exitCode];
            }
        };
    }

    private function request(string $accept): IncomingRequest
    {
        return service('incomingrequest', null, false)->setHeader('Accept', $accept)->setLocale('en');
    }

    private function scopeFailure(): RecordScopeUnavailableException
    {
        return new RecordScopeUnavailableException(
            7,
            'The record scope of the employee 7 could not be read from employee_record_scopes: Unknown column secret_column',
            new DatabaseException('Table secret_table is gone'),
        );
    }

    /**
     * @return array{Response, string}
     */
    private function answered(Throwable $exception, string $accept): array
    {
        $response = new Response(config('App'));
        $next     = $this->spy();

        ob_start();

        try {
            (new ScopeUnavailableHandler($next))->handle($exception, $this->request($accept), $response, 500, 1);
        } finally {
            $output = (string) ob_get_clean();
        }

        $this->assertSame([], $next->calls, 'The handler it wraps is not asked once the notice was answered');

        return [$response, $output];
    }

    public function testHandle_AnswersTheNoticeAsJsonToARequestThatDoesNotAcceptHtml(): void
    {
        [$response, $output] = $this->answered($this->scopeFailure(), 'application/json, text/javascript, */*; q=0.01');

        $this->assertSame(503, $response->getStatusCode());
        $this->assertStringContainsString('application/json', $response->getHeaderLine('Content-Type'));
        $this->assertSame(['success' => false, 'message' => lang('Roles.error_scope_unavailable')], json_decode($output, true));
    }

    public function testHandle_AnswersTheNoticeAsAPageToARequestThatAcceptsHtml(): void
    {
        [$response, $output] = $this->answered($this->scopeFailure(), 'text/html,application/xhtml+xml');

        $this->assertSame(503, $response->getStatusCode());
        $this->assertStringContainsString('text/html', $response->getHeaderLine('Content-Type'));
        $this->assertStringContainsString('<p id="scope_unavailable" role="alert">' . esc(lang('Roles.error_scope_unavailable')) . '</p>', $output);
        $this->assertStringContainsString('dir="auto"', $output);
    }

    public function testHandle_AnswersWhenTheMigrationRecordCannotBeRead(): void
    {
        [$response, $output] = $this->answered(new RoleTablesUnavailableException('The record of the migration of the roles could not be read.'), 'application/json');

        $this->assertSame(503, $response->getStatusCode());
        $this->assertFalse(json_decode($output, true)['success']);
    }

    public function testHandle_AnswersWhenAnotherExceptionWrapsTheFailure(): void
    {
        [$response] = $this->answered(new RuntimeException('Posting failed', 0, $this->scopeFailure()), 'application/json');

        $this->assertSame(503, $response->getStatusCode());
    }

    public function testHandle_SaysNothingOfTheFailure(): void
    {
        foreach (['application/json', 'text/html'] as $accept) {
            [$response, $output] = $this->answered($this->scopeFailure(), $accept);

            $answer = $output . $response->getReasonPhrase();

            foreach (array_keys($response->headers()) as $name) {
                $answer .= $name . $response->getHeaderLine($name);
            }

            foreach (['secret_column', 'secret_table', 'employee_record_scopes', 'RecordScopeUnavailableException', 'DatabaseException', 'employee 7'] as $leak) {
                $this->assertStringNotContainsString($leak, $answer, $accept);
            }
        }
    }

    public function testHandle_EscapesTheNoticeInThePage(): void
    {
        $language = service('language');
        lang('Roles.error_scope_unavailable');

        $property = new ReflectionProperty($language, 'language');
        $lines    = $property->getValue($language);

        $lines[$language->getLocale()]['Roles']['error_scope_unavailable'] = '<b>"x"</b> & y';
        $property->setValue($language, $lines);

        try {
            [, $output] = $this->answered($this->scopeFailure(), 'text/html');
        } finally {
            Services::resetSingle('language');
        }

        $this->assertStringContainsString('&lt;b&gt;', $output);
        $this->assertStringNotContainsString('<b>', $output);
    }

    public function testHandle_SpeaksTheLanguageOfTheSystem(): void
    {
        config(OSPOS::class)->settings['language_code'] = 'ar-EG';

        [, $output] = $this->answered($this->scopeFailure(), 'application/json');

        $this->assertSame('تعذّرت قراءة صلاحيات حسابك فلم يُعرض أو يتغير شيء. أعد المحاولة بعد قليل، وإن تكرر فأبلغ مدير النظام.', json_decode($output, true)['message']);

        [, $page] = $this->answered($this->scopeFailure(), 'text/html');

        $this->assertStringContainsString('<html lang="ar-EG">', $page);
    }

    public function testHandle_SpeaksTheLanguageOfTheSystemWhenTheLanguageOfTheEmployeeCannotBeRead(): void
    {
        config(OSPOS::class)->settings['language_code'] = 'ar-EG';

        Factories::injectMock('models', Employee::class, new class () extends Employee {
            public function is_logged_in(): bool
            {
                throw new RuntimeException('The employees cannot be read');
            }
        });

        try {
            [, $output] = $this->answered($this->scopeFailure(), 'application/json');
        } finally {
            Factories::reset('models');
        }

        $this->assertStringStartsWith('تعذّرت قراءة صلاحيات حسابك', json_decode($output, true)['message']);
    }

    public function testHandle_AnswersWithoutWhatTheFailedRequestQueuedOnTheResponse(): void
    {
        $response = new Response(config('App'));
        $response->setHeader('Cache-Control', 'public, max-age=600')->setHeader('ETag', '"abc"')->setHeader('Content-Disposition', 'attachment; filename="expenses.csv"');

        ob_start();

        try {
            (new ScopeUnavailableHandler($this->spy()))->handle($this->scopeFailure(), $this->request('text/html'), $response, 500, 1);
        } finally {
            ob_end_clean();
        }

        $this->assertFalse($response->hasHeader('Content-Disposition'));
        $this->assertFalse($response->hasHeader('ETag'));
        $this->assertStringNotContainsString('public', $response->getHeaderLine('Cache-Control'));
        $this->assertStringContainsString('no-store', $response->getHeaderLine('Cache-Control'));
    }

    public function testHandle_SpeaksTheLanguageOfTheEmployeeWhoIsSignedIn(): void
    {
        config(OSPOS::class)->settings['language_code'] = 'en';

        Factories::injectMock('models', Employee::class, new class () extends Employee {
            public function is_logged_in(): bool
            {
                return true;
            }

            public function get_logged_in_employee_info(): array|false|float|int|stdClass|string|null
            {
                return (object) ['language_code' => 'ar-EG'];
            }
        });

        [, $output] = $this->answered($this->scopeFailure(), 'application/json');

        $this->assertStringStartsWith('تعذّرت قراءة صلاحيات حسابك', json_decode($output, true)['message']);
    }

    public function testHandle_EscapesTheLanguageOfThePage(): void
    {
        config(OSPOS::class)->settings['language_code'] = '"><script>alert(1)</script>';

        [, $output] = $this->answered($this->scopeFailure(), 'text/html');

        $this->assertStringNotContainsString('<script>', $output);
        $this->assertStringContainsString('lang="&quot;&gt;&lt;script&gt;', $output);
    }

    public function testHandle_PassesEveryOtherFailureOnToTheHandlerItWraps(): void
    {
        $next      = $this->spy();
        $exception = new RuntimeException('Something else broke');
        $request   = $this->request('text/html');
        $response  = new Response(config('App'));

        ob_start();
        (new ScopeUnavailableHandler($next))->handle($exception, $request, $response, 500, 1);
        $output = (string) ob_get_clean();

        $this->assertSame([[$exception, $request, $response, 500, 1]], $next->calls);
        $this->assertSame('', $output);
        $this->assertSame(200, $response->getStatusCode());
    }

    public function testHandle_PassesACommandOnWhateverTheFailure(): void
    {
        $next      = $this->spy();
        $exception = $this->scopeFailure();
        $request   = new CLIRequest(config('App'));
        $response  = new Response(config('App'));

        ob_start();
        (new ScopeUnavailableHandler($next))->handle($exception, $request, $response, 500, 1);
        $output = (string) ob_get_clean();

        $this->assertSame([[$exception, $request, $response, 500, 1]], $next->calls);
        $this->assertSame('', $output);
    }

    public function testConcerns_FindsTheFailureAnywhereInTheChainOfCauses(): void
    {
        $failure = $this->scopeFailure();

        $this->assertTrue(ScopeUnavailableHandler::concerns($failure));
        $this->assertTrue(ScopeUnavailableHandler::concerns(new RoleTablesUnavailableException('x')));
        $this->assertTrue(ScopeUnavailableHandler::concerns(new RuntimeException('outer', 0, new RuntimeException('middle', 0, $failure))));
        $this->assertFalse(ScopeUnavailableHandler::concerns(new RuntimeException('outer', 0, new DatabaseException('inner'))));
        $this->assertFalse(ScopeUnavailableHandler::concerns(new RuntimeException('plain')));
    }

    public function testHandle_AnswersTheFailureToReadTheRolesOfAnEmployeeWithoutItsText(): void
    {
        $connection               = new FailingTableConnection(config('Database')->tests);
        $connection->failingTable = 'employee_roles';

        try {
            (new RoleGrants($connection))->idsFor(7);
            $this->fail('Roles that could not be read were taken for roles that give nothing.');
        } catch (RoleTablesUnavailableException $exception) {
            $this->assertTrue(ScopeUnavailableHandler::concerns($exception));

            [$response, $output] = $this->answered($exception, 'application/json');

            $this->assertSame(503, $response->getStatusCode());
            $this->assertSame(['success' => false, 'message' => lang('Roles.error_scope_unavailable')], json_decode($output, true));
            $this->assertStringNotContainsString('employee 7', $output);
        }
    }
}
