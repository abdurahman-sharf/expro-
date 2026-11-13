<?php

namespace Tests\Controllers;

use App\Libraries\Integrations\ApiKeys;
use App\Libraries\Integrations\IntegrationAccess;
use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\DatabaseTestTrait;
use CodeIgniter\Test\FeatureTestTrait;
use Config\Database;
use Config\Services;
use Tests\Support\EmployeeFixtureTrait;
use Tests\Support\IntegrationFixtureTrait;

/**
 * The screen of the API keys: it needs the configuration grant, the sensitive permission and every branch;
 * the secret is in the answer to the creation and nowhere else; a key is revoked from it.
 */
class Api_keysControllerTest extends CIUnitTestCase
{
    use DatabaseTestTrait;
    use FeatureTestTrait;
    use EmployeeFixtureTrait;
    use IntegrationFixtureTrait;

    protected $migrate     = true;
    protected $migrateOnce = true;
    protected $refresh     = false;
    protected $namespace   = null;

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
    }

    protected function tearDown(): void
    {
        $this->wipeIntegration();
        $this->removeInsertedEntities();

        parent::tearDown();
    }

    /**
     * @param list<string> $permissions
     */
    private function actAsWith(array $permissions, ?array $branchIds = null): int
    {
        $personId = $this->employeeWithGrants($permissions, $branchIds);

        $this->withSession(['person_id' => $personId, 'menu_group' => 'home']);

        return $personId;
    }

    private function manager(): int
    {
        return $this->actAsWith([IntegrationAccess::MODULE, IntegrationAccess::CONFIG, IntegrationAccess::API_KEYS]);
    }

    /**
     * @return array<string, mixed>
     */
    private function postJson(string $url, array $data = []): array
    {
        return json_decode($this->post($url, $data)->getJSON(), true);
    }

    public function testGetIndex_ShowsTheKeysAndTheFormToAHolderOfTheSensitivePermissionOfEveryBranch(): void
    {
        $this->manager();
        (new ApiKeys())->create(['name' => 'Accounting export', 'scopes' => ['read:customers']], null);

        $response = $this->get('/api_keys');

        $response->assertStatus(200);
        $response->assertSee('Accounting export');
        $response->assertSee('key_form');
        $response->assertSee('api_off');
    }

    public function testGetIndex_OpensWhetherAccountingIsOnOrOff(): void
    {
        $this->manager();
        $was = Database::connect()->table('app_config')->where('key', 'accounting_enabled')->get()->getRow()?->value ?? '0';

        try {
            foreach (['0', '1'] as $accounting) {
                $this->setConfigValue('accounting_enabled', $accounting);

                $this->get('/api_keys')->assertStatus(200);
            }
        } finally {
            $this->setConfigValue('accounting_enabled', (string) $was);
        }
    }

    public function testGetIndex_RedirectsAManagerOfIntegrationsWhoLacksTheSensitivePermission(): void
    {
        $this->actAsWith([IntegrationAccess::MODULE, IntegrationAccess::CONFIG, IntegrationAccess::MANAGE]);

        $this->get('/api_keys')->assertRedirectTo('no_access/' . IntegrationAccess::MODULE . '/' . IntegrationAccess::API_KEYS);
    }

    public function testGetIndex_RedirectsAHolderOfTheSensitivePermissionWithoutTheConfigurationGrant(): void
    {
        $this->actAsWith([IntegrationAccess::MODULE, IntegrationAccess::API_KEYS]);

        $this->get('/api_keys')->assertRedirectTo('no_access/' . IntegrationAccess::CONFIG);
    }

    public function testGetIndex_RedirectsAHolderOfTheSensitivePermissionWhoWorksInOneBranchOnly(): void
    {
        $this->actAsWith([IntegrationAccess::MODULE, IntegrationAccess::CONFIG, IntegrationAccess::API_KEYS], [$this->createBranch('B2')]);

        $this->get('/api_keys')->assertRedirectTo('no_access/' . IntegrationAccess::MODULE . '/' . IntegrationAccess::API_KEYS);
    }

    public function testGetIndex_RedirectsAHolderOfEverythingExceptTheModule(): void
    {
        $this->actAsWith([IntegrationAccess::CONFIG, IntegrationAccess::API_KEYS]);

        $this->get('/api_keys')->assertRedirectTo('no_access/' . IntegrationAccess::MODULE . '/');
    }

    public function testPostCreate_AnswersWithTheWholeKeyOnceAndStoresOnlyItsHash(): void
    {
        $this->manager();

        $response = $this->post('/api_keys/create', ['name' => 'Accounting export', 'scopes' => ['read:customers', 'read:documents'], 'branch_id' => '', 'rate_limit' => '90', 'expires_on' => '']);
        $body     = json_decode($response->getJSON(), true);

        $this->assertTrue($body['success']);
        $this->assertSame(lang('Integrations.key_created'), $body['message']);
        $this->assertMatchesRegularExpression(ApiKeys::KEY_FORMAT, $body['secret']);
        $this->assertStringContainsString('no-store', $response->response()->getHeaderLine('Cache-Control'));

        [, $prefix, $secret] = explode('_', $body['secret']);

        $this->assertSame($prefix, $body['prefix']);

        $row = Database::connect()->table('api_keys')->get()->getRow();

        $this->assertSame(hash('sha256', $secret), $row->secret_hash);
        $this->assertSame('read:customers,read:documents', $row->scopes);
        $this->assertSame(90, (int) $row->rate_limit);
        $this->assertStringNotContainsString($secret, $this->auditText());
    }

    public function testGetIndex_NeverShowsTheSecretOrItsHashAfterTheKeyIsMade(): void
    {
        $this->manager();

        $body = $this->postJson('/api_keys/create', ['name' => 'Seen once', 'scopes' => ['read:customers']]);

        [, $prefix, $secret] = explode('_', $body['secret']);

        $page = $this->get('/api_keys');

        $page->assertSee($prefix);
        $this->assertStringNotContainsString($secret, $page->getBody());
        $this->assertStringNotContainsString(hash('sha256', $secret), $page->getBody());
    }

    public function testPostCreate_SaysWhatIsWrongWithAKeyThatIsNotOne(): void
    {
        $this->manager();

        $noScope = $this->postJson('/api_keys/create', ['name' => 'No scope']);
        $noName  = $this->postJson('/api_keys/create', ['name' => '', 'scopes' => ['read:customers']]);
        $badRate = $this->postJson('/api_keys/create', ['name' => 'Rate', 'scopes' => ['read:customers'], 'rate_limit' => '0']);

        $this->assertSame([false, lang('Integrations.error_api_key_scopes')], [$noScope['success'], $noScope['message']]);
        $this->assertSame([false, lang('Integrations.error_api_key_name', ['100'])], [$noName['success'], $noName['message']]);
        $this->assertSame([false, lang('Integrations.error_api_key_rate', ['6000'])], [$badRate['success'], $badRate['message']]);
        $this->assertArrayNotHasKey('secret', $noScope);
        $this->assertSame(0, Database::connect()->table('api_keys')->countAllResults());
    }

    public function testPostCreate_RefusesScopesAndANameThatAreListsInWordsAndStoresNothing(): void
    {
        $this->manager();

        $scopes = $this->postJson('/api_keys/create', ['name' => 'Nested', 'scopes' => [['read:customers']]]);
        $name   = $this->postJson('/api_keys/create', ['name' => ['Nested'], 'scopes' => ['read:customers']]);

        $this->assertSame([false, lang('Integrations.error_api_key_scopes')], [$scopes['success'], $scopes['message']]);
        $this->assertFalse($name['success']);
        $this->assertSame(0, Database::connect()->table('api_keys')->countAllResults());
    }

    public function testPostCreate_IsRefusedForAnEmployeeWhoMayNotOpenTheScreen(): void
    {
        $this->actAsWith([IntegrationAccess::MODULE, IntegrationAccess::CONFIG, IntegrationAccess::MANAGE]);

        $this->post('/api_keys/create', ['name' => 'Sneaky', 'scopes' => ['read:customers']])->assertRedirectTo('no_access/' . IntegrationAccess::MODULE . '/' . IntegrationAccess::API_KEYS);
        $this->assertSame(0, Database::connect()->table('api_keys')->countAllResults());
    }

    public function testPostRevoke_RevokesAKeyAndSaysSoAndRefusesASecondTime(): void
    {
        $this->manager();

        $made = $this->postJson('/api_keys/create', ['name' => 'To revoke', 'scopes' => ['read:customers']]);

        $revoked = $this->postJson('/api_keys/revoke/' . $made['id']);

        $this->assertTrue($revoked['success']);
        $this->assertSame(lang('Integrations.key_revoked'), $revoked['message']);
        $this->assertTrue((new ApiKeys())->find((int) $made['id'])->revoked);

        $again = $this->postJson('/api_keys/revoke/' . $made['id']);

        $this->assertFalse($again['success']);
        $this->assertSame(lang('Integrations.error_api_key_revoked'), $again['message']);
        $this->assertSame(lang('Integrations.error_api_key_not_found'), $this->postJson('/api_keys/revoke/987654')['message']);
    }

    public function testGetIndex_ShowsARevokedKeyAsRevokedWithNoButtonToRevokeItAgain(): void
    {
        $this->manager();

        $made = $this->postJson('/api_keys/create', ['name' => 'Gone', 'scopes' => ['read:customers']]);

        $this->postJson('/api_keys/revoke/' . $made['id']);

        $page = $this->get('/api_keys');

        $page->assertSee(lang('Integrations.key_status_revoked'));
        $page->assertDontSee('api_keys/revoke/' . $made['id']);
    }

    public function testPostRevoke_IsRefusedForAnEmployeeWhoMayNotOpenTheScreen(): void
    {
        $key = (new ApiKeys())->create(['name' => 'Kept', 'scopes' => ['read:customers']], null)['key'];

        $this->actAsWith([IntegrationAccess::MODULE, IntegrationAccess::CONFIG, IntegrationAccess::MANAGE]);

        $this->post('/api_keys/revoke/' . $key->id)->assertRedirectTo('no_access/' . IntegrationAccess::MODULE . '/' . IntegrationAccess::API_KEYS);
        $this->assertFalse((new ApiKeys())->find($key->id)->revoked);
    }
}
