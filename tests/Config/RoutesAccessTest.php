<?php

namespace Tests\Config;

use App\Libraries\Accounting\AccountingGuard;
use App\Libraries\Documents\CustomFields;
use App\Libraries\Integrations\IntegrationAccess;
use App\Libraries\Organization\BranchGuard;
use CodeIgniter\Commands\Utilities\Routes\AutoRouterImproved\AutoRouteCollector as AutoRouteCollectorImproved;
use CodeIgniter\Commands\Utilities\Routes\SampleURIGenerator;
use CodeIgniter\Exceptions\PageNotFoundException;
use CodeIgniter\Router\DefinedRouteCollector;
use CodeIgniter\Router\Router;
use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\DatabaseTestTrait;
use CodeIgniter\Test\TestResponse;
use Config\Services;
use ReflectionClass;
use Tests\Support\AccountingControllerFixtureTrait;
use Tests\Support\DatabaseFingerprintTrait;
use Tests\Support\EmployeeFixtureTrait;
use Throwable;

/**
 * Every route of the application, defined and automatic, asked as a visitor who is not signed in, as an
 * employee who holds nothing, as an employee who holds only unrelated modules and as a clerk who holds
 * every ordinary permission but none of the accounting, management and override permissions.
 *
 * @internal
 */
final class RoutesAccessTest extends CIUnitTestCase
{
    use DatabaseTestTrait;
    use AccountingControllerFixtureTrait;
    use EmployeeFixtureTrait;
    use DatabaseFingerprintTrait;

    private const PUBLIC_ROUTES             = ['', 'login', 'migrate'];
    private const OPEN_TO_A_CUSTOMERS_CLERK = '~^(GET|POST) (home|office|customers|customer_classifications|exports|no_access|secure_Controller|accounting_lookup)(/|$)~';
    private const GUARDED_POSTS             = '~^(accounting_[a-z_]+|roles|branches|integrations|api_keys|customer_portal|employees|config)(/|$)~';
    private const DELEGATED_TO_THE_SERVICE  = ['POST accounting_journals/post_drafts', 'POST accounting_journals/delete_drafts'];
    private const PORTAL_PREFIX             = 'portal';
    private const API_PREFIX                = 'api/';

    protected $migrate     = true;
    protected $migrateOnce = true;
    protected $refresh     = false;
    protected $namespace;

    /**
     * @var list<array{verb: string, uri: string, handler: string}>|null
     */
    private static ?array $table = null;

    protected function setUp(): void
    {
        parent::setUp();

        $this->prepareAccounting();
        $_SESSION = [];
    }

    protected function tearDown(): void
    {
        $_SESSION = [];
        $this->removeInsertedEntities();

        parent::tearDown();
    }

    /**
     * @return list<array{verb: string, uri: string, handler: string}>
     */
    private function routeTable(): array
    {
        if (self::$table !== null) {
            return self::$table;
        }

        $collection = service('routes')->loadRoutes();
        $generator  = new SampleURIGenerator();
        $table      = [];

        foreach ((new DefinedRouteCollector($collection))->collect() as $route) {
            $verb = strtoupper((string) $route['method']);

            if (! in_array($verb, ['GET', 'POST'], true)) {
                continue;
            }

            $table[] = ['verb' => $verb, 'uri' => str_replace(['abc_123', 'abc123'], '123', $generator->get((string) $route['route'])), 'handler' => (string) $route['handler']];
        }

        $defined = $table;
        $auto    = (new AutoRouteCollectorImproved(
            $collection->getDefaultNamespace(),
            $collection->getDefaultController(),
            $collection->getDefaultMethod(),
            Router::HTTP_METHODS,
            $collection->getRegisteredControllers('*'),
        ))->get();

        foreach ($auto as $route) {
            $verb = strtoupper(str_replace('(auto)', '', (string) $route[0]));

            if (! in_array($verb, ['GET', 'POST'], true)) {
                continue;
            }

            $uri = (string) preg_replace('~\[/\.\.\]~', '', (string) $route[1]);
            $uri = str_replace('..', '1', $uri);

            $table[] = ['verb' => $verb, 'uri' => trim($uri, '/'), 'handler' => (string) $route[3]];
        }

        $seen = [];

        service('routes')->resetRoutes();
        service('routes')->loadRoutes();

        return self::$table = array_values(array_filter($table, static function (array $route) use (&$seen): bool {
            $key = $route['verb'] . ' ' . $route['uri'];

            return ! isset($seen[$key]) && $seen[$key] = true;
        }));
    }

    private function isPublic(string $uri): bool
    {
        return in_array($uri, self::PUBLIC_ROUTES, true)
            || $uri === self::PORTAL_PREFIX
            || str_starts_with($uri, self::PORTAL_PREFIX . '/')
            || str_starts_with($uri, self::API_PREFIX);
    }

    /**
     * @return list<string>
     */
    private function everyPermissionId(): array
    {
        $db = $this->accountingDb();

        return array_values(array_unique([
            ...array_column($db->table('modules')->select('module_id')->get()->getResultArray(), 'module_id'),
            ...array_column($db->table('permissions')->select('permission_id')->get()->getResultArray(), 'permission_id'),
        ]));
    }

    /**
     * @return list<string>
     */
    private function withheldFromTheClerk(): array
    {
        $guard = array_values((new ReflectionClass(AccountingGuard::class))->getConstants());

        return array_values(array_filter(
            [...$guard, IntegrationAccess::MANAGE, CustomFields::PERMISSION, BranchGuard::SCOPE_MANAGE, 'roles', 'config', 'employees', 'branches'],
            'is_string',
        ));
    }

    private function clerk(): int
    {
        $withheld = $this->withheldFromTheClerk();

        return $this->employeeWithGrants(array_values(array_diff($this->everyPermissionId(), $withheld, [BranchGuard::ALL_BRANCHES])), null);
    }

    private function holderOfEverything(): int
    {
        return $this->employeeWithGrants($this->everyPermissionId());
    }

    private function asking(?int $personId, string $verb, string $uri): string|TestResponse
    {
        if ($personId !== null) {
            $this->withSession(['person_id' => $personId, 'menu_group' => 'home']);
        }

        $this->accountingDb()->transBegin();
        Services::injectMock('response', service('response', null, false));

        try {
            return $this->call($verb, '/' . $uri);
        } catch (Throwable $failure) {
            return 'exception:' . $failure::class . ':' . substr(str_replace("\n", ' ', $failure->getMessage()), 0, 120);
        } finally {
            $this->session = [];
            $this->releaseOpenTransactions();
        }
    }

    private function verdict(string|TestResponse $response): string
    {
        if (is_string($response)) {
            return str_starts_with($response, 'exception:' . PageNotFoundException::class) ? 'refused:404' : $response;
        }

        $http   = $response->response();
        $status = $http->getStatusCode();

        if ($http->hasHeader('Location')) {
            $location = $http->getHeaderLine('Location');

            return match (true) {
                str_contains($location, 'no_access') => 'refused:no_access',
                str_contains($location, 'login')     => 'refused:login',
                str_ends_with($location, '/home')    => 'redirect:home',
                default                              => 'redirect:' . $location,
            };
        }

        if (in_array($status, [401, 403, 404], true)) {
            return 'refused:' . $status;
        }

        $body = (string) $http->getBody();

        if (str_starts_with(ltrim($body), '{') || str_starts_with(ltrim($body), '[')) {
            $json = json_decode($body, true);

            if (is_array($json) && array_key_exists('success', $json) && $json['success'] === false) {
                return 'refused:json:' . substr((string) ($json['message'] ?? ''), 0, 60);
            }
        }

        return $status === 200 ? 'open' : 'status:' . $status;
    }

    private function logPath(string $name): ?string
    {
        $directory = getenv('ROUTE_WALK_LOG_DIR');

        return $directory === false || $directory === '' ? null : rtrim($directory, '/') . '/' . $name;
    }

    /**
     * @param callable(string, string): bool $skip
     *
     * @return array<string, string> Route key to verdict.
     */
    private function walk(?int $personId, ?callable $skip = null, string $query = ''): array
    {
        $verdicts = [];

        foreach ($this->routeTable() as $route) {
            if ($this->isPublic($route['uri']) || ($skip !== null && $skip($route['verb'], $route['uri']))) {
                continue;
            }

            $uri = $route['verb'] === 'GET' && $query !== '' ? $route['uri'] . '?' . $query : $route['uri'];

            $verdicts[$route['verb'] . ' ' . $route['uri']] = $this->verdict($this->asking($personId, $route['verb'], $uri));
        }

        return $verdicts;
    }

    private function writeLog(string $name, array $verdicts): void
    {
        $path = $this->logPath($name);

        if ($path === null) {
            return;
        }

        $lines = [];

        foreach ($verdicts as $route => $verdict) {
            $lines[] = $route . "\t" . $verdict;
        }

        file_put_contents($path, implode("\n", $lines) . "\n");
    }

    public function testRoutesTableListsTheDefinedAndTheAutomaticRoutes(): void
    {
        $table = $this->routeTable();

        $this->assertGreaterThan(600, count($table));
        $this->assertContains('GET accounting_journals/search', array_map(static fn (array $route): string => $route['verb'] . ' ' . $route['uri'], $table));
    }

    public function testRoutesRefuseAVisitorWhoIsNotSignedIn(): void
    {
        $before   = $this->databaseFingerprint();
        $verdicts = $this->walk(null);
        $this->writeLog('anonymous.tsv', $verdicts);

        $open = array_filter($verdicts, static fn (string $verdict): bool => ! str_starts_with($verdict, 'refused:login') && ! str_starts_with($verdict, 'refused:401'));

        $this->assertSame([], $open, 'Routes that did not send an anonymous visitor to the sign-in');
        $this->assertSame($before, $this->databaseFingerprint(), 'An anonymous visitor changed data');
    }

    public function testRoutesRefuseAnEmployeeWhoHoldsNothing(): void
    {
        $personId = $this->createEmployee();
        $before   = $this->databaseFingerprint();
        $verdicts = $this->walk($personId);
        $this->writeLog('no-grants.tsv', $verdicts);

        $open = array_filter(
            $verdicts,
            fn (string $verdict, string $route): bool => $this->reachedTheAction($verdict) && $route !== 'GET no_access',
            ARRAY_FILTER_USE_BOTH,
        );

        $this->assertSame([], $open, 'Routes an employee without any grant could open');
        $this->assertSame($before, $this->databaseFingerprint(), 'An employee without grants changed data');
    }

    public function testRoutesRefuseAnEmployeeWhoHoldsOnlyUnrelatedModules(): void
    {
        $personId = $this->employeeWithGrants(['home', 'office', 'customers']);
        $verdicts = $this->walk($personId);
        $this->writeLog('unrelated-grants.tsv', $verdicts);

        $open = array_filter(
            $verdicts,
            fn (string $verdict, string $route): bool => $this->reachedTheAction($verdict) && ! preg_match(self::OPEN_TO_A_CUSTOMERS_CLERK, $route),
            ARRAY_FILTER_USE_BOTH,
        );

        $this->assertSame([], $open, 'Routes of other modules that an employee holding only home, office and customers could open');
    }

    public function testRoutesRefuseTheAccountingAndManagementPostsToAClerkWhoHoldsNoneOfTheirPermissions(): void
    {
        $clerk    = $this->clerk();
        $before   = $this->databaseFingerprint();
        $verdicts = $this->walk($clerk, static fn (string $verb, string $uri): bool => $verb !== 'POST' || ! preg_match(self::GUARDED_POSTS, $uri));
        $this->writeLog('clerk-guarded-posts.tsv', $verdicts);

        $open = array_filter(
            $verdicts,
            fn (string $verdict, string $route): bool => $this->reachedTheAction($verdict) && ! in_array($route, self::DELEGATED_TO_THE_SERVICE, true),
            ARRAY_FILTER_USE_BOTH,
        );

        $this->assertGreaterThan(150, count($verdicts));
        $this->assertSame([], $open, 'Accounting and management posts a clerk without their permissions could reach');
        $this->assertSame($before, $this->databaseFingerprint(), 'The clerk changed data');
    }

    public function testRoutesRecordWhichPermissionGatesEachPost(): void
    {
        if (getenv('ROUTE_WALK_LEAVE_ONE_OUT') !== '1') {
            $this->markTestSkipped('The leave-one-out walk takes minutes: set ROUTE_WALK_LEAVE_ONE_OUT=1 to run it.');
        }

        $everything = $this->walk($this->holderOfEverything(), static fn (string $verb, string $uri): bool => $verb !== 'POST' || ! preg_match(self::GUARDED_POSTS, $uri));
        $gates      = array_fill_keys(array_keys($everything), []);

        foreach ($this->gatedPermissions() as $permissionId) {
            $holder = $this->employeeWithGrants(array_values(array_diff($this->everyPermissionId(), [$permissionId])));

            foreach (array_keys($everything) as $route) {
                [$verb, $uri] = explode(' ', $route, 2);

                if ($this->verdict($this->asking($holder, $verb, $uri)) !== $everything[$route]) {
                    $gates[$route][] = $permissionId;
                }
            }
        }

        $lines = [];

        foreach ($gates as $route => $permissions) {
            $lines[] = $route . "\t" . implode(',', $permissions);
        }

        $path = $this->logPath('post-gates.tsv');

        if ($path !== null) {
            file_put_contents($path, implode("\n", $lines) . "\n");
        }

        $this->assertNotEmpty($gates);
    }

    public function testRoutesNeverBreakTheSqlOnHostileValuesOfTheQueryString(): void
    {
        $hostile = http_build_query([
            'search'     => "x' OR '1'='1\\\" ); --",
            'term'       => "'\"%_\\) UNION SELECT 1 -- ",
            'sort'       => 'zzz_not_a_column; SELECT SLEEP(1)',
            'order'      => 'desc; SELECT SLEEP(1)',
            'limit'      => '5; SELECT 1',
            'offset'     => '-1',
            'start_date' => "2026-01-01' OR 1=1 -- ",
            'end_date'   => "2026-12-31' OR 1=1 -- ",
            'from'       => "2026-01-01' OR 1=1 -- ",
            'to'         => "2026-12-31' OR 1=1 -- ",
            'date'       => "' OR 1=1 -- ",
            'kind'       => "' OR 1=1 -- ",
            'mode'       => "' OR 1=1 -- ",
            'status'     => "' OR 1=1 -- ",
            'filters'    => ["' OR 1=1 -- "],
        ]);

        $verdicts = $this->walk($this->holderOfEverything(), static fn (string $verb): bool => $verb !== 'GET', $hostile);
        $this->writeLog('hostile-query.tsv', $verdicts);

        $broken = array_filter($verdicts, static fn (string $verdict): bool => str_contains($verdict, 'DatabaseException') || str_contains(strtolower($verdict), 'sql syntax'));

        $this->assertGreaterThan(300, count($verdicts));
        $this->assertSame([], $broken, 'GET routes that let a hostile value of the query string reach the SQL');
    }

    /**
     * @return list<string>
     */
    private function gatedPermissions(): array
    {
        return array_values(array_filter(
            [...array_values((new ReflectionClass(AccountingGuard::class))->getConstants()), IntegrationAccess::MANAGE, CustomFields::PERMISSION, BranchGuard::SCOPE_MANAGE],
            'is_string',
        ));
    }

    private function reachedTheAction(string $verdict): bool
    {
        return $verdict === 'open'
            || str_starts_with($verdict, 'status:')
            || str_starts_with($verdict, 'exception:')
            || (str_starts_with($verdict, 'redirect:') && $verdict !== 'redirect:home');
    }
}
