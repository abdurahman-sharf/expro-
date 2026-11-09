<?php

namespace Tests\Filters;

use App\Libraries\Integrations\ApiKeys;
use App\Libraries\Integrations\IntegrationOptions;
use CodeIgniter\Exceptions\PageNotFoundException;
use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\DatabaseTestTrait;
use CodeIgniter\Test\FeatureTestTrait;
use CodeIgniter\Test\TestResponse;
use Config\Database;
use Config\Services;
use Tests\Support\EmployeeFixtureTrait;
use Tests\Support\IntegrationFixtureTrait;

/**
 * The filter in front of every address under api/: a page that does not exist while the API is off, a key
 * that must pass while it is on, where a key may be presented, what a refusal says and writes, the calls a
 * minute of a key, and that no address of the API takes anything but a read.
 */
class ApiAuthTest extends CIUnitTestCase
{
    use DatabaseTestTrait;
    use FeatureTestTrait;
    use EmployeeFixtureTrait;
    use IntegrationFixtureTrait;

    protected $migrate     = true;
    protected $migrateOnce = true;
    protected $refresh     = false;
    protected $namespace   = null;

    private ApiKeys $keys;

    public static function setUpBeforeClass(): void
    {
        $seeder = Database::seeder('tests');
        $seeder->call('TestDatabaseBootstrapSeeder');
    }

    protected function setUp(): void
    {
        parent::setUp();

        Services::resetSingle('response');
        $this->wipeIntegration();
        $this->keys = new ApiKeys();
    }

    protected function tearDown(): void
    {
        $this->wipeIntegration();
        $this->removeInsertedEntities();

        parent::tearDown();
    }

    /**
     * @return array{id: int, prefix: string, secret: string}
     */
    private function makeKey(array $input = []): array
    {
        $made = $this->keys->create($input + ['name' => 'Reader', 'scopes' => ['read:customers']], $this->keyManager());

        return ['id' => $made['key']->id, 'prefix' => $made['key']->prefix, 'secret' => $made['secret']];
    }

    private function ping(?string $bearer = null, array $headers = [], string $query = ''): TestResponse
    {
        $headers = $bearer === null ? $headers : $headers + ['Authorization' => 'Bearer ' . $bearer];

        return $this->withHeaders($headers)->get('api/v1/ping' . $query);
    }

    /**
     * @return array<string, mixed>
     */
    private function body(TestResponse $response): array
    {
        return json_decode($response->getJSON(), true);
    }

    public function testBefore_AnswersEveryAddressAsAPageThatDoesNotExistWhileTheApiIsOff(): void
    {
        $key = $this->makeKey();

        foreach (['api/v1/ping', 'api/v1/customers/balances', 'api/v1/documents/sales'] as $path) {
            foreach ([null, $key['secret'], 'not-a-key'] as $presented) {
                $response = $this->withHeaders($presented === null ? [] : ['Authorization' => 'Bearer ' . $presented])->get($path);

                $response->assertStatus(404);
                $this->assertSame('not_found', $this->body($response)['error']);
            }
        }

        $this->assertSame([], $this->integrationAudit('api_key') === [] ? [] : array_diff($this->integrationAudit('api_key'), ['api_key_created']), 'A call to a switched-off API writes nothing.');
        $this->assertNull($this->keys->find($key['id'])->lastUsedAt);
    }

    public function testBefore_ReadsTheSwitchAtEveryCallSoTurningItOffStopsTheKeyAtOnce(): void
    {
        $key = $this->makeKey();

        $this->switchIntegrationOn(IntegrationOptions::API);
        $this->ping($key['secret'])->assertStatus(200);

        $this->setConfigValue(IntegrationOptions::API, '0');
        $this->ping($key['secret'])->assertStatus(404);
    }

    public function testBefore_AnswersAKeyThatPassesWithTheKeyItself(): void
    {
        $this->switchIntegrationOn(IntegrationOptions::API);
        $key = $this->makeKey(['scopes' => ['read:customers', 'read:documents'], 'rate_limit' => '75']);

        $response = $this->ping($key['secret']);

        $response->assertStatus(200);
        $this->assertStringContainsString('no-store', $response->response()->getHeaderLine('Cache-Control'));

        $this->assertSame(
            ['status' => 'ok', 'prefix' => $key['prefix'], 'scopes' => ['read:customers', 'read:documents'], 'branch_id' => null, 'rate_limit' => 75, 'expires_at' => null],
            $this->body($response)['data'],
        );
        $this->assertNotNull($this->keys->find($key['id'])->lastUsedAt);
    }

    public function testBefore_TakesTheKeyFromTheBearerHeaderOrFromXApiKeyAndNeverFromTheAddress(): void
    {
        $this->switchIntegrationOn(IntegrationOptions::API);
        $key = $this->makeKey();

        $this->ping(null, ['Authorization' => 'bearer ' . $key['secret']])->assertStatus(200);
        $this->ping(null, ['X-Api-Key' => $key['secret']])->assertStatus(200);
        $this->ping(null, [], '?api_key=' . $key['secret'])->assertStatus(401);
        $this->ping(null, [], '?key=' . $key['secret'])->assertStatus(401);
        $this->ping(null, ['Authorization' => 'Basic ' . base64_encode('user:' . $key['secret'])])->assertStatus(401);
    }

    public function testBefore_RefusesACallWithoutAKeyWithAKeyThatIsNotOneOrWrongAndSaysNothingMore(): void
    {
        $this->switchIntegrationOn(IntegrationOptions::API);
        $key = $this->makeKey();

        $wrong = 'exk_' . $key['prefix'] . '_' . str_repeat('0', 64);

        foreach ([null, 'garbage', $wrong, 'exk_00000000_' . str_repeat('a', 64)] as $presented) {
            $response = $this->ping($presented);

            $response->assertStatus(401);
            $response->assertHeader('WWW-Authenticate', 'Bearer');

            $this->assertSame(['error' => 'unauthorized', 'message' => 'The key is missing or not accepted.'], $this->body($response));
        }
    }

    public function testBefore_RefusesARevokedKeyAndAnExpiredOneWithTheSameWordsAsAWrongOne(): void
    {
        $this->switchIntegrationOn(IntegrationOptions::API);
        $revoked = $this->makeKey(['name' => 'Revoked']);
        $expired = $this->makeKey(['name' => 'Expired']);

        $this->keys->revoke($revoked['id'], null);
        Database::connect()->table('api_keys')->where('key_id', $expired['id'])->update(['expires_at' => date('Y-m-d H:i:s', strtotime('-1 minute'))]);

        $one = $this->ping($revoked['secret']);
        $two = $this->ping($expired['secret']);

        $one->assertStatus(401);
        $two->assertStatus(401);

        $this->assertSame($this->body($one), $this->body($two));
        $this->assertSame(['revoked', 'expired'], array_map(static fn (object $row): string => json_decode($row->details, true)['reason'], Database::connect()->table('accounting_audit_log')->where('action', 'api_auth_failed')->orderBy('audit_id')->get()->getResult()));
    }

    public function testBefore_AuditsAFailedCallWithoutTheSecretAndWithoutAnySession(): void
    {
        $this->switchIntegrationOn(IntegrationOptions::API);
        $key   = $this->makeKey();
        $wrong = 'exk_' . $key['prefix'] . '_' . str_repeat('9', 64);

        $this->ping($wrong)->assertStatus(401);

        $this->assertSame(1, count(array_filter($this->integrationAudit('api_key'), static fn (string $action): bool => $action === 'api_auth_failed')));
        $this->assertStringNotContainsString(str_repeat('9', 64), $this->auditText());
        $this->assertStringNotContainsString($key['secret'], $this->auditText());
    }

    public function testBefore_NeedsNoSessionAndNeverSendsAnApiCallerToTheLoginPage(): void
    {
        $this->switchIntegrationOn(IntegrationOptions::API);

        $response = $this->ping(null);

        $this->assertNotContains($response->response()->getStatusCode(), [301, 302, 303, 307]);
        $this->assertFalse($response->isRedirect());
    }

    public function testBefore_HoldsAKeyToItsCallsAMinuteWithARetryAfter(): void
    {
        $this->switchIntegrationOn(IntegrationOptions::API);

        for ($attempt = 0; $attempt < 3; $attempt++) {
            $key      = $this->makeKey(['name' => 'Slow ' . $attempt, 'rate_limit' => '2']);
            $minute   = intdiv(time(), 60);
            $statuses = [];

            foreach ([1, 2, 3] as $call) {
                $response   = $this->ping($key['secret']);
                $statuses[] = $response->response()->getStatusCode();
            }

            if (intdiv(time(), 60) !== $minute) {
                continue;
            }

            $this->assertSame([200, 200, 429], $statuses);

            $retry = (int) $response->response()->getHeaderLine('Retry-After');

            $this->assertGreaterThanOrEqual(1, $retry);
            $this->assertLessThanOrEqual(60, $retry);
            $this->assertSame('rate_limited', $this->body($response)['error']);

            return;
        }

        $this->fail('The calls kept falling across a change of minute.');
    }

    public function testRoutes_GiveNoAddressOfTheApiAnythingButARead(): void
    {
        $routes = service('routes');

        foreach (['POST', 'PUT', 'PATCH', 'DELETE', 'OPTIONS', 'HEAD'] as $verb) {
            foreach (array_keys($routes->getRoutes($verb, false)) as $uri) {
                $this->assertStringStartsNotWith('api/', (string) $uri, "{$verb} {$uri}");
            }
        }

        $reads = array_filter(array_keys($routes->getRoutes('GET', false)), static fn ($uri): bool => str_starts_with((string) $uri, 'api/'));

        $this->assertEqualsCanonicalizing(['api/v1/ping', 'api/v1/customers/balances', 'api/v1/documents/sales'], array_values($reads));
    }

    public function testBefore_ReachesNoWriteWhateverTheKeyAndTheVerb(): void
    {
        $this->switchIntegrationOn(IntegrationOptions::API);
        $key = $this->makeKey();

        foreach (['post', 'put', 'patch', 'delete'] as $verb) {
            try {
                $status = $this->withHeaders(['Authorization' => 'Bearer ' . $key['secret']])->call($verb, 'api/v1/ping')->response()->getStatusCode();
            } catch (PageNotFoundException) {
                $status = 404;
            }

            $this->assertContains($status, [401, 403, 404, 405], $verb);
        }
    }
}
