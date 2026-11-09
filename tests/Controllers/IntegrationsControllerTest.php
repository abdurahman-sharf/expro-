<?php

namespace Tests\Controllers;

use App\Libraries\Integrations\EntityTranslations;
use App\Libraries\Integrations\IntegrationAccess;
use App\Libraries\Integrations\IntegrationOptions;
use App\Libraries\Integrations\NotificationRules;
use App\Libraries\Integrations\PaymentMethods;
use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\DatabaseTestTrait;
use CodeIgniter\Test\FeatureTestTrait;
use Config\Database;
use Tests\Support\EmployeeFixtureTrait;
use Tests\Support\IntegrationFixtureTrait;
use Tests\Support\ItemFixtureTrait;

/**
 * The hub of the integration settings and its screens of payment methods, second language and notification
 * rules: who may open each, what a refused change says, and that the screens that change settings need the
 * grant of the configuration screens as well as their own.
 */
class IntegrationsControllerTest extends CIUnitTestCase
{
    use DatabaseTestTrait;
    use FeatureTestTrait;
    use EmployeeFixtureTrait;
    use IntegrationFixtureTrait;
    use ItemFixtureTrait;

    protected $migrate     = true;
    protected $migrateOnce = true;
    protected $refresh     = false;
    protected $namespace   = null;

    /** @var list<int> */
    private array $itemIds = [];

    public static function setUpBeforeClass(): void
    {
        $seeder = Database::seeder('tests');
        $seeder->call('TestDatabaseBootstrapSeeder');
    }

    protected function setUp(): void
    {
        parent::setUp();

        $this->wipeIntegration();
    }

    protected function tearDown(): void
    {
        $this->wipeIntegration();

        foreach ($this->itemIds as $itemId) {
            Database::connect()->table('item_quantities')->where('item_id', $itemId)->delete();
            Database::connect()->table('inventory')->where('trans_items', $itemId)->delete();
            Database::connect()->table('items')->where('item_id', $itemId)->delete();
        }

        $this->itemIds = [];
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

    /**
     * @return array<string, mixed>
     */
    private function postJson(string $url, array $data = []): array
    {
        return json_decode($this->post($url, $data)->getJSON(), true);
    }

    public function testGetIndex_ShowsTheScreensToAManagerAndNoKeysCardNorApiSwitch(): void
    {
        $this->actAsWith([IntegrationAccess::MODULE, IntegrationAccess::CONFIG, IntegrationAccess::MANAGE]);

        $response = $this->get('/integrations');

        $response->assertStatus(200);
        $response->assertSee('link_payment_methods');
        $response->assertSee('link_translations');
        $response->assertSee('link_rules');
        $response->assertSee('opt_payment_methods');
        $response->assertDontSee('link_api_keys');
        $response->assertDontSee('opt_api');
        $response->assertSee('integration_not_built');
    }

    public function testGetIndex_ShowsTheKeysCardAndTheApiSwitchToAHolderOfTheSensitivePermissionOnly(): void
    {
        $this->actAsWith([IntegrationAccess::MODULE, IntegrationAccess::CONFIG, IntegrationAccess::API_KEYS]);

        $response = $this->get('/integrations');

        $response->assertStatus(200);
        $response->assertSee('link_api_keys');
        $response->assertSee('opt_api');
        $response->assertDontSee('link_payment_methods');
        $response->assertDontSee('opt_payment_methods');
    }

    public function testGetIndex_AsksForEveryBranchBeforeItShowsTheKeysCard(): void
    {
        $this->actAsWith([IntegrationAccess::MODULE, IntegrationAccess::CONFIG, IntegrationAccess::API_KEYS], [$this->createBranch('B2')]);

        $this->get('/integrations')->assertDontSee('link_api_keys');
    }

    public function testGetIndex_RedirectsAnEmployeeWhoHoldsTheModuleButNotTheConfigurationGrant(): void
    {
        $this->actAsWith([IntegrationAccess::MODULE, IntegrationAccess::MANAGE, IntegrationAccess::API_KEYS]);

        $this->get('/integrations')->assertRedirect();
    }

    public function testGetIndex_RedirectsAnEmployeeWhoHoldsTheConfigurationGrantButNotTheModule(): void
    {
        $this->actAsWith([IntegrationAccess::CONFIG, IntegrationAccess::MANAGE]);

        $this->get('/integrations')->assertRedirect();
    }

    public function testGetIndex_ShowsAStatusOnlyToAnEmployeeWhoHoldsTheModuleAndTheConfigurationGrantAlone(): void
    {
        $this->actAsWith([IntegrationAccess::MODULE, IntegrationAccess::CONFIG]);

        $response = $this->get('/integrations');

        $response->assertStatus(200);
        $response->assertDontSee('link_payment_methods');
        $response->assertDontSee('integration_options_form');
    }

    public function testPostOptions_TurnsTheSwitchesOnForAManagerAndSaysSo(): void
    {
        $this->actAsWith([IntegrationAccess::MODULE, IntegrationAccess::CONFIG, IntegrationAccess::MANAGE]);

        $body = $this->postJson('/integrations/options', ['payment_methods' => '1', 'notifications' => '1', 'second_language' => '0']);

        $this->assertTrue($body['success']);
        $this->assertSame(lang('Integrations.options_saved'), $body['message']);
        $this->assertTrue((new IntegrationOptions())->paymentMethodsCustom());
        $this->assertTrue((new IntegrationOptions())->notificationsEnabled());
    }

    public function testPostOptions_RefusesTheApiSwitchToAManagerAndNamesThePermission(): void
    {
        $this->actAsWith([IntegrationAccess::MODULE, IntegrationAccess::CONFIG, IntegrationAccess::MANAGE]);

        $body = $this->postJson('/integrations/options', ['api' => '1']);

        $this->assertFalse($body['success']);
        $this->assertSame(lang('Integrations.error_permission_denied'), $body['message']);
        $this->assertFalse((new IntegrationOptions())->apiEnabled());
    }

    public function testPostOptions_TurnsTheApiOnForAHolderOfTheSensitivePermissionOfEveryBranch(): void
    {
        $this->actAsWith([IntegrationAccess::MODULE, IntegrationAccess::CONFIG, IntegrationAccess::API_KEYS]);

        $this->assertTrue($this->postJson('/integrations/options', ['api' => '1'])['success']);
        $this->assertTrue((new IntegrationOptions())->apiEnabled());
    }

    public function testPostOptions_SaysTheWholeCompanyIsNeededToAHolderLimitedToABranch(): void
    {
        $this->actAsWith([IntegrationAccess::MODULE, IntegrationAccess::CONFIG, IntegrationAccess::API_KEYS], [$this->createBranch('B2')]);

        $body = $this->postJson('/integrations/options', ['api' => '1']);

        $this->assertFalse($body['success']);
        $this->assertSame(lang('Branches.error_company_scope_needed'), $body['message']);
        $this->assertFalse((new IntegrationOptions())->apiEnabled());
    }

    public function testPostOptions_RefusesALanguageThatIsNotOneAndSaysWhy(): void
    {
        $this->actAsWith([IntegrationAccess::MODULE, IntegrationAccess::CONFIG, IntegrationAccess::MANAGE]);

        $body = $this->postJson('/integrations/options', ['second_language' => '1', 'second_language_code' => '']);

        $this->assertFalse($body['success']);
        $this->assertSame(lang('Integrations.error_setting_invalid'), $body['message']);
    }

    public function testPostOptions_IsRefusedForAnEmployeeWithoutTheConfigurationGrant(): void
    {
        $this->actAsWith([IntegrationAccess::MODULE, IntegrationAccess::MANAGE]);

        $this->post('/integrations/options', ['payment_methods' => '1'])->assertRedirect();
        $this->assertFalse((new IntegrationOptions())->paymentMethodsCustom());
    }

    public function testGetPaymentMethods_ListsTheSevenWaysWithTheirRoutingAndTheBannerWhileTheSwitchIsOff(): void
    {
        $this->actAsWith([IntegrationAccess::MODULE, IntegrationAccess::CONFIG, IntegrationAccess::MANAGE]);

        $response = $this->get('/integrations/payment_methods');

        $response->assertStatus(200);

        foreach (['cash', 'debit', 'credit', 'check', 'bank_transfer', 'upi', 'wallet'] as $code) {
            $response->assertSee('name[' . $code . ']');
        }

        $response->assertSee('payment_methods_off');
        $response->assertSee(lang('Integrations.method_always_on'));
    }

    public function testGetPaymentMethods_ShowsNoBannerOnceTheSwitchIsOnAndShowsTheNameChosen(): void
    {
        $this->actAsWith([IntegrationAccess::MODULE, IntegrationAccess::CONFIG, IntegrationAccess::MANAGE]);
        (new PaymentMethods())->save(['wallet' => ['name' => 'Pay by phone']], null);
        $this->switchOn(IntegrationOptions::PAYMENT_METHODS);

        $response = $this->get('/integrations/payment_methods');

        $response->assertDontSee('payment_methods_off');
        $response->assertSee('Pay by phone');
    }

    public function testGetPaymentMethods_RedirectsAnEmployeeWithoutThePermissionToManage(): void
    {
        $this->actAsWith([IntegrationAccess::MODULE, IntegrationAccess::CONFIG, IntegrationAccess::API_KEYS]);

        $this->get('/integrations/payment_methods')->assertRedirect();
    }

    public function testPostPaymentMethods_SavesWhatTheFormSendsAndRefusesWhatItMustNot(): void
    {
        $this->actAsWith([IntegrationAccess::MODULE, IntegrationAccess::CONFIG, IntegrationAccess::MANAGE]);

        $body = $this->postJson('/integrations/payment_methods', ['enabled' => ['cash' => '1', 'debit' => '0', 'wallet' => '1'], 'name' => ['cash' => '', 'wallet' => 'Pay by phone']]);

        $this->assertTrue($body['success']);
        $this->assertSame(lang('Integrations.payment_methods_saved'), $body['message']);

        $stored = Database::connect()->table('payment_methods')->orderBy('method_code')->get()->getResultArray();

        $this->assertSame(['debit', 'wallet'], array_column($stored, 'method_code'));
        $this->assertSame('Pay by phone', $stored[1]['display_name']);

        $refused = $this->postJson('/integrations/payment_methods', ['enabled' => ['cash' => '0']]);

        $this->assertFalse($refused['success']);
        $this->assertSame(lang('Integrations.error_payment_method_protected'), $refused['message']);
    }

    public function testPostPaymentMethods_IsRefusedWithoutThePermissionToManage(): void
    {
        $this->actAsWith([IntegrationAccess::MODULE, IntegrationAccess::CONFIG, IntegrationAccess::API_KEYS]);

        $this->post('/integrations/payment_methods', ['enabled' => ['debit' => '0']])->assertRedirect();
        $this->assertSame(0, Database::connect()->table('payment_methods')->countAllResults());
    }

    public function testGetTranslations_ListsWhatIsKeptAndFiltersItAndOpensOneForEditing(): void
    {
        $this->actAsWith([IntegrationAccess::MODULE, IntegrationAccess::CONFIG, IntegrationAccess::MANAGE]);
        $customer = $this->insertCustomer();
        $item     = $this->itemIds[] = $this->createTestItem();
        $id       = (new EntityTranslations())->save('customer', (string) $customer, 'en', 'Omar Trading', 'Riyadh', null);

        (new EntityTranslations())->save('item', (string) $item, 'en', 'Bright pen', '', null);
        $this->useSecondLanguage('en', false);

        $all = $this->get('/integrations/translations');

        $all->assertStatus(200);
        $all->assertSee('Omar Trading');
        $all->assertSee('Bright pen');
        $all->assertSee('translations_not_printed');

        $filtered = $this->get('/integrations/translations?kind=item');

        $filtered->assertSee('Bright pen');
        $filtered->assertDontSee('Omar Trading');

        $editing = $this->get('/integrations/translations?edit=' . $id);

        $editing->assertSee('Riyadh');
        $editing->assertSee(lang('Integrations.translation_edit'));
    }

    public function testGetTranslations_EscapesTheNamesAsText(): void
    {
        $this->actAsWith([IntegrationAccess::MODULE, IntegrationAccess::CONFIG, IntegrationAccess::MANAGE]);
        $customer = $this->insertCustomer();

        (new EntityTranslations())->save('customer', (string) $customer, 'en', '<script>alert(1)</script>', '<b>bold</b>', null);

        $response = $this->get('/integrations/translations');

        $this->assertStringNotContainsString('<script>alert(1)</script>', $response->getBody());
        $this->assertStringContainsString('&lt;script&gt;alert(1)&lt;/script&gt;', $response->getBody());
        $this->assertStringContainsString('&lt;b&gt;bold&lt;/b&gt;', $response->getBody());
    }

    public function testGetTranslations_RedirectsAnEmployeeWithoutThePermissionToManage(): void
    {
        $this->actAsWith([IntegrationAccess::MODULE, IntegrationAccess::CONFIG]);

        $this->get('/integrations/translations')->assertRedirect();
    }

    public function testPostTranslationSave_KeepsTheNameInTheSecondLanguageOfTheShopAndDeleteRemovesIt(): void
    {
        $this->actAsWith([IntegrationAccess::MODULE, IntegrationAccess::CONFIG, IntegrationAccess::MANAGE]);
        $customer = $this->insertCustomer();

        $this->useSecondLanguage('en');

        $body = $this->postJson('/integrations/translations/save', ['kind' => 'customer', 'key' => (string) $customer, 'name' => 'Omar Trading', 'address_note' => 'Riyadh']);

        $this->assertTrue($body['success']);
        $this->assertSame(lang('Integrations.translation_saved'), $body['message']);
        $this->assertSame('en', Database::connect()->table('entity_translations')->get()->getRow()->language);

        $deleted = $this->postJson('/integrations/translations/delete/' . $body['id']);

        $this->assertTrue($deleted['success']);
        $this->assertSame(0, Database::connect()->table('entity_translations')->countAllResults());
    }

    public function testPostTranslationSave_SaysWhatIsWrongWithARecordThatDoesNotExist(): void
    {
        $this->actAsWith([IntegrationAccess::MODULE, IntegrationAccess::CONFIG, IntegrationAccess::MANAGE]);
        $this->useSecondLanguage('en');

        $body = $this->postJson('/integrations/translations/save', ['kind' => 'customer', 'key' => '987654', 'name' => 'Nobody']);

        $this->assertFalse($body['success']);
        $this->assertSame(lang('Integrations.error_translation_entity_missing', ['987654']), $body['message']);
    }

    public function testPostTranslationSave_IsRefusedWithoutThePermissionToManage(): void
    {
        $this->actAsWith([IntegrationAccess::MODULE, IntegrationAccess::CONFIG]);

        $this->post('/integrations/translations/save', ['kind' => 'item', 'key' => '1', 'name' => 'x'])->assertRedirect();
        $this->assertSame(0, Database::connect()->table('entity_translations')->countAllResults());
    }

    public function testGetRules_ListsTheRulesAndTheNoteThatNothingIsSentAnywhere(): void
    {
        $this->actAsWith([IntegrationAccess::MODULE, IntegrationAccess::CONFIG, IntegrationAccess::MANAGE]);
        (new NotificationRules())->create('invoice_overdue', 'Late invoices', '7', null);

        $response = $this->get('/integrations/rules');

        $response->assertStatus(200);
        $response->assertSee('Late invoices');
        $response->assertSee('rules_no_outbound');
        $response->assertSee('rules_off');
    }

    public function testGetRules_RedirectsAnEmployeeWithoutThePermissionToManage(): void
    {
        $this->actAsWith([IntegrationAccess::MODULE, IntegrationAccess::CONFIG]);

        $this->get('/integrations/rules')->assertRedirect();
    }

    public function testPostRuleSave_MakesAndChangesARuleAndSwitchesItAndDeletesIt(): void
    {
        $this->actAsWith([IntegrationAccess::MODULE, IntegrationAccess::CONFIG, IntegrationAccess::MANAGE]);

        $made = $this->postJson('/integrations/rules/save', ['kind' => 'cheque_due', 'name' => 'Cheques', 'threshold' => '5']);

        $this->assertTrue($made['success']);

        $ruleId = (int) $made['id'];

        $this->assertTrue($this->postJson('/integrations/rules/save/' . $ruleId, ['name' => 'Cheques soon', 'threshold' => '9'])['success']);
        $this->assertSame(9, (new NotificationRules())->find($ruleId)->threshold);
        $this->assertTrue($this->postJson('/integrations/rules/active/' . $ruleId . '/0')['success']);
        $this->assertFalse((new NotificationRules())->find($ruleId)->active);
        $this->assertTrue($this->postJson('/integrations/rules/delete/' . $ruleId)['success']);
        $this->assertNull((new NotificationRules())->find($ruleId));
    }

    public function testPostRuleSave_SaysWhatIsWrongWithDaysOutsideTheBounds(): void
    {
        $this->actAsWith([IntegrationAccess::MODULE, IntegrationAccess::CONFIG, IntegrationAccess::MANAGE]);

        $body = $this->postJson('/integrations/rules/save', ['kind' => 'invoice_overdue', 'name' => 'Late', 'threshold' => '400']);

        $this->assertFalse($body['success']);
        $this->assertSame(lang('Integrations.error_rule_threshold', ['1', '365']), $body['message']);
    }

    public function testPostRuleSave_IsRefusedWithoutThePermissionToManage(): void
    {
        $this->actAsWith([IntegrationAccess::MODULE, IntegrationAccess::CONFIG]);

        $this->post('/integrations/rules/save', ['kind' => 'low_stock', 'name' => 'Low'])->assertRedirect();
        $this->assertSame(0, Database::connect()->table('notification_rules')->countAllResults());
    }
}
