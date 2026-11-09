<?php

namespace Tests\Libraries\Integrations;

use App\Libraries\Integrations\ApiKey;
use App\Libraries\Integrations\ApiKeys;
use App\Libraries\Integrations\ApiScope;
use App\Libraries\Integrations\IntegrationAccess;
use App\Libraries\Integrations\IntegrationError;
use App\Libraries\Integrations\IntegrationException;
use App\Libraries\Organization\BranchError;
use App\Libraries\Organization\BranchException;
use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\DatabaseTestTrait;
use Config\Database;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Support\EmployeeFixtureTrait;
use Tests\Support\IntegrationFixtureTrait;

/**
 * The keys of the API: the whole key is given once and only its hash is kept, what a key carries, who may
 * make and revoke one, that a revoked key is kept, and that nothing the audit log or the application log
 * says holds the secret.
 */
class ApiKeysTest extends CIUnitTestCase
{
    use DatabaseTestTrait;
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

        $this->wipeIntegration();
        $this->keys = new ApiKeys();
    }

    protected function tearDown(): void
    {
        $this->wipeIntegration();
        $this->removeInsertedEntities();

        parent::tearDown();
    }

    private function refusal(callable $action): IntegrationException|BranchException
    {
        try {
            $action();
        } catch (IntegrationException|BranchException $exception) {
            return $exception;
        }

        $this->fail('The change was not refused.');
    }

    /**
     * @return array{key: ApiKey, secret: string}
     */
    private function made(array $input = []): array
    {
        return $this->keys->create($input + ['name' => 'Accounting export', 'scopes' => ['read:customers']], $this->keyManager());
    }

    public function testCreate_GivesTheWholeKeyOnceAndStoresOnlyTheHashOfItsSecret(): void
    {
        $made = $this->made();

        $this->assertMatchesRegularExpression(ApiKeys::KEY_FORMAT, $made['secret']);

        [, $prefix, $secret] = explode('_', $made['secret']);

        $this->assertSame($prefix, $made['key']->prefix);

        $row = Database::connect()->table('api_keys')->where('key_id', $made['key']->id)->get()->getRow();

        $this->assertSame(hash('sha256', $secret), $row->secret_hash);
        $this->assertNotSame($secret, $row->secret_hash);
        $this->assertSame(64, strlen($row->secret_hash));
        $this->assertStringNotContainsString($secret, json_encode((array) $row), 'The row of the key holds no part of the secret.');
        $this->assertStringNotContainsString($made['secret'], json_encode((array) $row));
    }

    public function testCreate_MakesADifferentKeyAndPrefixEveryTime(): void
    {
        $secrets  = [];
        $prefixes = [];

        for ($i = 0; $i < 12; $i++) {
            $made       = $this->made(['name' => 'Key ' . $i]);
            $secrets[]  = $made['secret'];
            $prefixes[] = $made['key']->prefix;
        }

        $this->assertCount(12, array_unique($secrets));
        $this->assertCount(12, array_unique($prefixes));
    }

    public function testCreate_StoresTheScopesTheBranchTheRateAndTheLastDay(): void
    {
        $branch = $this->createBranch('B2');

        $made = $this->made(['name' => '  Branch   reader ', 'scopes' => ['read:documents', 'read:customers'], 'branch_id' => (string) $branch, 'rate_limit' => '120', 'expires_on' => date('Y-m-d', strtotime('+10 days'))]);
        $key  = $made['key'];

        $this->assertSame('Branch reader', $key->name);
        $this->assertSame([ApiScope::ReadDocuments, ApiScope::ReadCustomers], $key->scopes);
        $this->assertSame($branch, $key->branchId);
        $this->assertSame(120, $key->rateLimit);
        $this->assertSame(date('Y-m-d', strtotime('+10 days')) . ' 23:59:59', $key->expiresAt);
        $this->assertFalse($key->revoked);
        $this->assertNull($key->lastUsedAt);
        $this->assertTrue($key->allows(ApiScope::ReadDocuments));
    }

    public function testCreate_DefaultsToEveryBranchSixtyCallsAMinuteAndNoLastDay(): void
    {
        $key = $this->made()['key'];

        $this->assertNull($key->branchId);
        $this->assertSame(60, $key->rateLimit);
        $this->assertNull($key->expiresAt);
        $this->assertFalse($key->allows(ApiScope::ReadDocuments));
    }

    public function testCreate_AuditsTheCreationWithoutTheSecretNorItsHash(): void
    {
        $manager = $this->keyManager();
        $made    = $this->keys->create(['name' => 'Audited', 'scopes' => ['read:customers']], $manager);

        [, , $secret] = explode('_', $made['secret']);

        $entry = Database::connect()->table('accounting_audit_log')->where('action', 'api_key_created')->get()->getRow();

        $this->assertSame('api_key', $entry->entity_type);
        $this->assertSame($made['key']->id, (int) $entry->entity_id);
        $this->assertSame($manager, (int) $entry->employee_id);
        $this->assertSame(['prefix' => $made['key']->prefix, 'name' => 'Audited', 'scopes' => ['read:customers'], 'branch_id' => null, 'rate_limit' => 60, 'expires_at' => null], json_decode($entry->details, true));
        $this->assertStringNotContainsString($secret, $this->auditText());
        $this->assertStringNotContainsString(hash('sha256', $secret), $this->auditText());
    }

    public function testCreate_NeverWritesTheSecretToTheApplicationLog(): void
    {
        $made = null;

        $logged = $this->loggedDuring(function () use (&$made): void {
            $made = $this->made(['name' => 'Logged']);
        });

        [, , $secret] = explode('_', $made['secret']);

        $this->assertStringNotContainsString($secret, $logged);
        $this->assertStringNotContainsString($made['secret'], $logged);
        $this->assertStringNotContainsString(hash('sha256', $secret), $logged);
    }

    public function testCreate_RequiresTheSensitivePermission(): void
    {
        $outsider = $this->employeeWithGrants([IntegrationAccess::MODULE, IntegrationAccess::CONFIG, IntegrationAccess::MANAGE]);

        $refusal = $this->refusal(fn () => $this->keys->create(['name' => 'No', 'scopes' => ['read:customers']], $outsider));

        $this->assertInstanceOf(IntegrationException::class, $refusal);
        $this->assertSame(IntegrationError::PermissionDenied, $refusal->error);
        $this->assertSame(0, Database::connect()->table('api_keys')->countAllResults());
        $this->assertSame([], $this->auditActions('api_key'));
    }

    public function testCreate_RequiresEveryBranch(): void
    {
        $restricted = $this->keyManager([$this->createBranch('B2')]);

        $refusal = $this->refusal(fn () => $this->keys->create(['name' => 'No', 'scopes' => ['read:customers']], $restricted));

        $this->assertInstanceOf(BranchException::class, $refusal);
        $this->assertSame(BranchError::CompanyScopeNeeded, $refusal->error);
        $this->assertSame(0, Database::connect()->table('api_keys')->countAllResults());
    }

    /**
     * @return array<string, array{array<string, mixed>, IntegrationError}>
     */
    public static function badInput(): array
    {
        return [
            'no name'            => [['name' => ''], IntegrationError::ApiKeyName],
            'a blank name'       => [['name' => '   '], IntegrationError::ApiKeyName],
            'a name too long'    => [['name' => str_repeat('n', 101)], IntegrationError::ApiKeyName],
            'a control character' => [['name' => "Na\x07me"], IntegrationError::ApiKeyName],
            'no scope'           => [['scopes' => []], IntegrationError::ApiKeyScopes],
            'scopes that are not a list' => [['scopes' => 'read:customers'], IntegrationError::ApiKeyScopes],
            'an unknown scope'   => [['scopes' => ['read:customers', 'write:customers']], IntegrationError::ApiKeyScopes],
            'a branch that does not exist' => [['branch_id' => '987654'], IntegrationError::ApiKeyBranch],
            'a branch that is not a number' => [['branch_id' => 'abc'], IntegrationError::ApiKeyBranch],
            'no calls allowed'   => [['rate_limit' => '0'], IntegrationError::ApiKeyRate],
            'too many calls'     => [['rate_limit' => '6001'], IntegrationError::ApiKeyRate],
            'calls that are not a number' => [['rate_limit' => 'fast'], IntegrationError::ApiKeyRate],
            'a last day that passed' => [['expires_on' => '2020-01-01'], IntegrationError::ApiKeyExpiry],
            'a last day that is not a day' => [['expires_on' => '2027-02-30'], IntegrationError::ApiKeyExpiry],
        ];
    }

    /**
     * @param array<string, mixed> $input
     */
    #[DataProvider('badInput')]
    public function testCreate_RejectsWhatIsNotAKeyAndWritesNothing(array $input, IntegrationError $error): void
    {
        $refusal = $this->refusal(fn () => $this->made($input));

        $this->assertSame($error, $refusal->error);
        $this->assertSame(0, Database::connect()->table('api_keys')->countAllResults());
        $this->assertSame([], $this->auditActions('api_key'));
    }

    public function testCreate_RejectsABranchThatIsSwitchedOff(): void
    {
        $branch = $this->createBranch('B2', 'Closed', false);

        $this->assertSame(IntegrationError::ApiKeyBranch, $this->refusal(fn () => $this->made(['branch_id' => (string) $branch]))->error);
    }

    public function testCreate_AcceptsTheBoundariesOfTheRateAndALastDayOfToday(): void
    {
        $this->assertSame(1, $this->made(['name' => 'Slow', 'rate_limit' => '1'])['key']->rateLimit);
        $this->assertSame(6000, $this->made(['name' => 'Fast', 'rate_limit' => '6000'])['key']->rateLimit);
        $this->assertSame(date('Y-m-d') . ' 23:59:59', $this->made(['name' => 'Today', 'expires_on' => date('Y-m-d')])['key']->expiresAt);
        $this->assertSame(str_repeat('n', 100), $this->made(['name' => str_repeat('n', 100)])['key']->name);
    }

    public function testRevoke_RevokesTheKeyAuditsItAndKeepsTheRow(): void
    {
        $made    = $this->made();
        $manager = $this->keyManager();

        $this->keys->revoke($made['key']->id, $manager);

        $key = $this->keys->find($made['key']->id);

        $this->assertTrue($key->revoked);
        $this->assertNotNull($key->revokedAt);
        $this->assertSame($manager, (int) Database::connect()->table('api_keys')->where('key_id', $key->id)->get()->getRow()->revoked_by);
        $this->assertSame(['api_key_created', 'api_key_revoked'], $this->auditActions('api_key'));
        $this->assertNotNull($this->keys->rowOfPrefix($key->prefix), 'A revoked key stays, so that its calls are still known by it.');
    }

    public function testRevoke_RejectsAKeyThatIsRevokedAlreadyOrDoesNotExist(): void
    {
        $made = $this->made();

        $this->keys->revoke($made['key']->id, null);

        $this->assertSame(IntegrationError::ApiKeyRevoked, $this->refusal(fn () => $this->keys->revoke($made['key']->id, null))->error);
        $this->assertSame(IntegrationError::ApiKeyNotFound, $this->refusal(fn () => $this->keys->revoke(987654, null))->error);
        $this->assertSame(['api_key_created', 'api_key_revoked'], $this->auditActions('api_key'));
    }

    public function testRevoke_RequiresTheSensitivePermissionAndEveryBranch(): void
    {
        $made       = $this->made();
        $outsider   = $this->employeeWithGrants([IntegrationAccess::MODULE, IntegrationAccess::CONFIG, IntegrationAccess::MANAGE]);
        $restricted = $this->keyManager([$this->createBranch('B2')]);

        $this->assertSame(IntegrationError::PermissionDenied, $this->refusal(fn () => $this->keys->revoke($made['key']->id, $outsider))->error);
        $this->assertSame(BranchError::CompanyScopeNeeded, $this->refusal(fn () => $this->keys->revoke($made['key']->id, $restricted))->error);
        $this->assertFalse($this->keys->find($made['key']->id)->revoked);
    }

    public function testAll_ListsTheNewestFirstWithNothingOfTheSecret(): void
    {
        $first  = $this->made(['name' => 'First']);
        $second = $this->made(['name' => 'Second']);

        $all = $this->keys->all();

        $this->assertSame([$second['key']->id, $first['key']->id], array_map(static fn (ApiKey $key): int => $key->id, $all));

        [, , $secret] = explode('_', $first['secret']);

        $this->assertStringNotContainsString($secret, json_encode($all));
        $this->assertStringNotContainsString(hash('sha256', $secret), json_encode($all));
    }

    public function testHashOf_IsTheSha256OfTheSecret(): void
    {
        $this->assertSame(hash('sha256', 'abc'), ApiKeys::hashOf('abc'));
    }

    public function testIsExpired_IsTrueOnlyAfterTheLastMoment(): void
    {
        $key = $this->made(['expires_on' => '2099-12-31'])['key'];

        $this->assertFalse($key->isExpired('2099-12-31 23:59:59'));
        $this->assertTrue($key->isExpired('2100-01-01 00:00:00'));
        $this->assertFalse($this->made(['name' => 'Forever'])['key']->isExpired('2999-01-01 00:00:00'));
    }
}
