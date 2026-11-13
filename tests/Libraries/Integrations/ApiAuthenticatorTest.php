<?php

namespace Tests\Libraries\Integrations;

use App\Libraries\Integrations\ApiAuthenticator;
use App\Libraries\Integrations\ApiFailure;
use App\Libraries\Integrations\ApiKeys;
use App\Libraries\Integrations\ApiRateLimiter;
use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\DatabaseTestTrait;
use Config\Database;
use Tests\Support\EmployeeFixtureTrait;
use Tests\Support\IntegrationFixtureTrait;

/**
 * What a call must present to be accepted, what each refusal writes to the audit log and what it never
 * writes, the calls a minute a key may make, and the flood of failures one address may put in the log.
 */
class ApiAuthenticatorTest extends CIUnitTestCase
{
    use DatabaseTestTrait;
    use EmployeeFixtureTrait;
    use IntegrationFixtureTrait;

    protected $migrate     = true;
    protected $migrateOnce = true;
    protected $refresh     = false;
    protected $namespace   = null;

    private int $now;
    private ApiKeys $keys;
    private ApiAuthenticator $authenticator;

    public static function setUpBeforeClass(): void
    {
        $seeder = Database::seeder('tests');
        $seeder->call('TestDatabaseBootstrapSeeder');
    }

    protected function setUp(): void
    {
        parent::setUp();

        $this->wipeIntegration();
        $this->now           = intdiv(time(), 60) * 60;
        $this->keys          = new ApiKeys();
        $this->authenticator = new ApiAuthenticator(null, $this->keys, new ApiRateLimiter(null, fn (): int => $this->now));
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

    /**
     * @return list<array<string, mixed>>
     */
    private function failuresAudited(): array
    {
        return array_map(static fn (object $row): array => ['entity_id' => $row->entity_id, 'details' => json_decode($row->details, true)], Database::connect()->table('accounting_audit_log')->where('action', 'api_auth_failed')->orderBy('audit_id')->get()->getResult());
    }

    public function testAuthenticate_AcceptsTheKeyItHandedOutAndWritesItsLastUse(): void
    {
        $key = $this->makeKey();

        $result = $this->authenticator->authenticate($key['secret'], '203.0.113.5');

        $this->assertTrue($result->succeeded());
        $this->assertSame($key['id'], $result->key->id);
        $this->assertNull($result->failure);
        $this->assertSame(date('Y-m-d H:i:s', $this->now), $this->keys->find($key['id'])->lastUsedAt);
        $this->assertSame([], $this->failuresAudited());
    }

    public function testAuthenticate_AcceptsTheKeyWithBlanksAroundIt(): void
    {
        $key = $this->makeKey();

        $this->assertTrue($this->authenticator->authenticate("  {$key['secret']}\n", '203.0.113.5')->succeeded());
    }

    /**
     * @return array<string, array{?string, ApiFailure}>
     */
    private function badPresentations(): array
    {
        return [
            'nothing'            => [null, ApiFailure::Missing],
            'an empty text'      => ['   ', ApiFailure::Missing],
            'a text that is no key' => ['not-a-key', ApiFailure::Malformed],
            'a key with a short secret' => ['exk_aabbccdd_' . str_repeat('a', 63), ApiFailure::Malformed],
            'a key in capitals'  => ['EXK_AABBCCDD_' . str_repeat('A', 64), ApiFailure::Malformed],
            'a prefix nobody holds' => ['exk_00000000_' . str_repeat('a', 64), ApiFailure::Unknown],
        ];
    }

    public function testAuthenticate_RefusesWhatIsNotAKeyAndAuditsTheReasonWithoutTheText(): void
    {
        foreach ($this->badPresentations() as $name => [$presented, $failure]) {
            $result = $this->authenticator->authenticate($presented, '203.0.113.5');

            $this->assertFalse($result->succeeded(), $name);
            $this->assertSame($failure, $result->failure, $name);
        }

        $reasons = array_map(static fn (array $entry): string => $entry['details']['reason'], $this->failuresAudited());

        $this->assertSame(['missing', 'missing', 'malformed', 'malformed', 'malformed', 'unknown_key'], $reasons);
        $this->assertStringNotContainsString('not-a-key', $this->auditText());
        $this->assertStringNotContainsString(str_repeat('a', 63), $this->auditText());
    }

    public function testAuthenticate_RefusesAWrongSecretForAKnownPrefixAndAuditsTheKey(): void
    {
        $key   = $this->makeKey();
        $wrong = 'exk_' . $key['prefix'] . '_' . str_repeat('0', 64);

        $result = $this->authenticator->authenticate($wrong, '203.0.113.5');

        $this->assertSame(ApiFailure::Unknown, $result->failure);

        $entry = $this->failuresAudited()[0];

        $this->assertSame((string) $key['id'], (string) $entry['entity_id']);
        $this->assertSame(['reason' => 'unknown_key', 'prefix' => $key['prefix'], 'address' => '203.0.113.5'], $entry['details']);
        $this->assertStringNotContainsString(str_repeat('0', 64), $this->auditText());
        $this->assertNull($this->keys->find($key['id'])->lastUsedAt);
    }

    public function testAuthenticate_RefusesTheKeyOfAnotherCompanyAsUnknown(): void
    {
        $db = Database::connect();
        $db->table('companies')->insert(['name' => 'Another company']);

        $companyId = (int) $db->insertID();
        $secret    = bin2hex(random_bytes(32));

        try {
            $db->table('api_keys')->insert(['company_id' => $companyId, 'key_prefix' => 'a1b2c3d4', 'secret_hash' => hash('sha256', $secret), 'name' => 'Theirs', 'scopes' => 'read:customers']);

            $result = $this->authenticator->authenticate('exk_a1b2c3d4_' . $secret, '203.0.113.5');

            $this->assertFalse($result->succeeded());
            $this->assertSame(ApiFailure::Unknown, $result->failure);
        } finally {
            $db->table('api_keys')->where('company_id', $companyId)->delete();
            $db->table('companies')->where('company_id', $companyId)->delete();
        }
    }

    public function testAuthenticate_RefusesARevokedKeyAtOnce(): void
    {
        $key = $this->makeKey();

        $this->assertTrue($this->authenticator->authenticate($key['secret'], '203.0.113.5')->succeeded());

        $this->keys->revoke($key['id'], null);

        $result = $this->authenticator->authenticate($key['secret'], '203.0.113.5');

        $this->assertFalse($result->succeeded());
        $this->assertSame(ApiFailure::Revoked, $result->failure);
        $this->assertSame('revoked', $this->failuresAudited()[0]['details']['reason']);
    }

    public function testAuthenticate_RefusesAnExpiredKeyAfterItsLastMomentAndAcceptsItAtThatMoment(): void
    {
        $key = $this->makeKey(['expires_on' => date('Y-m-d')]);

        $this->now = strtotime(date('Y-m-d') . ' 23:59:59');

        $this->assertTrue($this->authenticator->authenticate($key['secret'], '203.0.113.5')->succeeded(), 'The last day is a whole day.');

        $this->now += 1;

        $result = $this->authenticator->authenticate($key['secret'], '203.0.113.5');

        $this->assertSame(ApiFailure::Expired, $result->failure);
        $this->assertSame('expired', $this->failuresAudited()[0]['details']['reason']);
    }

    public function testAuthenticate_HoldsAKeyToItsCallsAMinuteAndTellsWhenToComeBack(): void
    {
        $key = $this->makeKey(['rate_limit' => '3']);

        foreach ([1, 2, 3] as $call) {
            $this->assertTrue($this->authenticator->authenticate($key['secret'], '203.0.113.5')->succeeded(), "call {$call}");
        }

        $result = $this->authenticator->authenticate($key['secret'], '203.0.113.5');

        $this->assertFalse($result->succeeded());
        $this->assertSame(ApiFailure::RateLimited, $result->failure);
        $this->assertGreaterThanOrEqual(1, $result->retryAfter);
        $this->assertLessThanOrEqual(60, $result->retryAfter);
        $this->assertSame([], $this->failuresAudited(), 'A key that is over its calls has not failed to authenticate.');

        $this->now += 60;

        $this->assertTrue($this->authenticator->authenticate($key['secret'], '203.0.113.5')->succeeded(), 'The next minute starts from nothing.');
    }

    public function testAuthenticate_CountsEachKeyOnItsOwn(): void
    {
        $slow  = $this->makeKey(['name' => 'Slow', 'rate_limit' => '1']);
        $other = $this->makeKey(['name' => 'Other', 'rate_limit' => '1']);

        $this->assertTrue($this->authenticator->authenticate($slow['secret'], '203.0.113.5')->succeeded());
        $this->assertSame(ApiFailure::RateLimited, $this->authenticator->authenticate($slow['secret'], '203.0.113.5')->failure);
        $this->assertTrue($this->authenticator->authenticate($other['secret'], '203.0.113.5')->succeeded());
    }

    public function testAuthenticate_DoesNotCountTheCallsOfARefusedKeyAgainstItsRate(): void
    {
        $key = $this->makeKey(['rate_limit' => '2']);

        $this->authenticator->authenticate('exk_' . $key['prefix'] . '_' . str_repeat('0', 64), '203.0.113.5');
        $this->authenticator->authenticate('exk_' . $key['prefix'] . '_' . str_repeat('0', 64), '203.0.113.5');

        $this->assertTrue($this->authenticator->authenticate($key['secret'], '203.0.113.5')->succeeded());
        $this->assertTrue($this->authenticator->authenticate($key['secret'], '203.0.113.5')->succeeded());
    }

    public function testAuthenticate_WritesTheFirstTwentyFailuresOfOneAddressThenOnceThatItIsThrottled(): void
    {
        for ($call = 1; $call <= 30; $call++) {
            $this->authenticator->authenticate(null, '203.0.113.9');
        }

        $this->assertSame(['api_auth_failed' => 20, 'api_auth_throttled' => 1], array_count_values($this->integrationAudit('api_key')));
    }

    public function testAuthenticate_CountsTheFailuresOfEachAddressOnItsOwnAndStillAcceptsAGoodKeyFromAFloodingAddress(): void
    {
        $key = $this->makeKey();

        for ($call = 1; $call <= 25; $call++) {
            $this->authenticator->authenticate(null, '203.0.113.9');
        }

        $this->authenticator->authenticate(null, '203.0.113.10');

        $this->assertSame(21, count(array_filter($this->integrationAudit('api_key'), static fn (string $action): bool => $action === 'api_auth_failed')) - 0, '20 of the first address and 1 of the second.');
        $this->assertTrue($this->authenticator->authenticate($key['secret'], '203.0.113.9')->succeeded());
    }

    public function testAuthenticate_NeverWritesTheSecretToTheAuditLogOrTheApplicationLog(): void
    {
        $key    = $this->makeKey();
        $revoke = $this->makeKey(['name' => 'Revoked']);

        $this->keys->revoke($revoke['id'], null);

        [, , $secret]       = explode('_', $key['secret']);
        [, , $revokeSecret] = explode('_', $revoke['secret']);

        $logged = $this->loggedDuring(function () use ($key, $revoke): void {
            $this->authenticator->authenticate($key['secret'], '203.0.113.5');
            $this->authenticator->authenticate('exk_' . $key['prefix'] . '_' . str_repeat('1', 64), '203.0.113.5');
            $this->authenticator->authenticate($revoke['secret'], '203.0.113.5');
            $this->authenticator->authenticate('exk_00000000_' . str_repeat('2', 64), '203.0.113.5');
            $this->authenticator->authenticate('garbage ' . $key['secret'], '203.0.113.5');
        });

        foreach ([$secret, $revokeSecret, str_repeat('1', 64), str_repeat('2', 64), hash('sha256', $secret)] as $text) {
            $this->assertStringNotContainsString($text, $logged);
            $this->assertStringNotContainsString($text, $this->auditText());
        }

        $this->assertStringContainsString($key['prefix'], $logged, 'The log names the key by its prefix.');
    }
}
