<?php

namespace Tests\Controllers;

use App\Libraries\Integrations\IntegrationAccess;
use App\Libraries\Integrations\IntegrationOptions;
use App\Libraries\Integrations\NotificationRules;
use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\DatabaseTestTrait;
use CodeIgniter\Test\FeatureTestTrait;
use Config\Database;
use Tests\Support\EmployeeFixtureTrait;
use Tests\Support\IntegrationFixtureTrait;

/**
 * The screen where an employee reads the notifications of their branches: who may open it, what it shows,
 * and that reading a notification is kept for the employee alone.
 */
class NotificationsControllerTest extends CIUnitTestCase
{
    use DatabaseTestTrait;
    use FeatureTestTrait;
    use EmployeeFixtureTrait;
    use IntegrationFixtureTrait;

    protected $migrate     = true;
    protected $migrateOnce = true;
    protected $refresh     = false;
    protected $namespace   = null;

    private int $ruleId;

    public static function setUpBeforeClass(): void
    {
        $seeder = Database::seeder('tests');
        $seeder->call('TestDatabaseBootstrapSeeder');
    }

    protected function setUp(): void
    {
        parent::setUp();

        $this->wipeIntegration();
        $this->ruleId = (new NotificationRules())->create('low_stock', 'Low stock', null, null);
    }

    protected function tearDown(): void
    {
        $this->wipeIntegration();
        $this->removeInsertedEntities();

        parent::tearDown();
    }

    private function actAsWith(array $permissions, ?array $branchIds = null): int
    {
        $personId = $this->employeeWithGrants($permissions, $branchIds);

        $this->withSession(['person_id' => $personId, 'menu_group' => 'home']);

        return $personId;
    }

    private function notice(string $subject, ?int $branchId, string $item = 'Pen'): int
    {
        $db = Database::connect();

        $db->table('notifications')->insert([
            'rule_id'     => $this->ruleId,
            'subject_key' => $subject,
            'open_key'    => $subject,
            'branch_id'   => $branchId,
            'params'      => json_encode(['item' => $item, 'location' => 'Shop', 'quantity' => '2', 'reorder' => '5']),
        ]);

        return (int) $db->insertID();
    }

    /**
     * @return array<string, mixed>
     */
    private function postJson(string $url): array
    {
        return json_decode($this->post($url)->getJSON(), true);
    }

    public function testGetIndex_ShowsAnEmployeeWithTheModuleWhatTheRulesFoundWithoutAskingForTheConfigurationGrant(): void
    {
        $this->actAsWith([IntegrationAccess::NOTIFICATIONS_MODULE]);
        $this->notice('stock:1:1', 1, 'Bright pen');

        $response = $this->get('/notifications');

        $response->assertStatus(200);
        $response->assertSee('Bright pen');
        $response->assertSee('unread_count');
        $response->assertSee('notifications_off');
    }

    public function testGetIndex_RedirectsAnEmployeeWithoutTheModule(): void
    {
        $this->actAsWith([IntegrationAccess::MODULE, IntegrationAccess::CONFIG, IntegrationAccess::MANAGE]);

        $this->get('/notifications')->assertRedirect();
    }

    public function testGetIndex_ShowsNoBannerWhileTheRulesAreOn(): void
    {
        $this->actAsWith([IntegrationAccess::NOTIFICATIONS_MODULE]);
        $this->switchIntegrationOn(IntegrationOptions::NOTIFICATIONS);

        $response = $this->get('/notifications');

        $response->assertDontSee('notifications_off');
        $response->assertSee('notifications_empty');
    }

    public function testGetIndex_ShowsOnlyTheNotificationsOfTheBranchesTheEmployeeWorksIn(): void
    {
        $second = $this->createBranch('B2');

        $this->notice('stock:1:1', 1, 'Pen of the first');
        $this->notice('stock:2:2', $second, 'Pen of the second');
        $this->actAsWith([IntegrationAccess::NOTIFICATIONS_MODULE], [$second]);

        $response = $this->get('/notifications');

        $response->assertSee('Pen of the second');
        $response->assertDontSee('Pen of the first');
    }

    public function testGetIndex_EscapesWhatANotificationSays(): void
    {
        $this->actAsWith([IntegrationAccess::NOTIFICATIONS_MODULE]);
        $this->notice('stock:1:1', 1, '<script>alert(1)</script>');

        $body = $this->get('/notifications')->getBody();

        $this->assertStringNotContainsString('<script>alert(1)</script>', $body);
        $this->assertStringContainsString('&lt;script&gt;alert(1)&lt;/script&gt;', $body);
    }

    public function testGetIndex_ShowsOnlyTheUnreadOnesWhenAsked(): void
    {
        $viewer = $this->actAsWith([IntegrationAccess::NOTIFICATIONS_MODULE]);
        $read   = $this->notice('stock:1:1', 1, 'Read pen');

        $this->notice('stock:2:1', 1, 'Unread pen');
        $this->postJson('/notifications/read/' . $read);

        $response = $this->get('/notifications?show=unread');

        $response->assertSee('Unread pen');
        $response->assertDontSee('Read pen');
        $this->assertSame(1, Database::connect()->table('notification_reads')->where('person_id', $viewer)->countAllResults());
    }

    public function testPostRead_MarksOneNotificationReadForTheEmployeeAndSaysSo(): void
    {
        $viewer = $this->actAsWith([IntegrationAccess::NOTIFICATIONS_MODULE]);
        $id     = $this->notice('stock:1:1', 1);

        $body = $this->postJson('/notifications/read/' . $id);

        $this->assertTrue($body['success']);
        $this->assertSame(lang('Integrations.notification_read'), $body['message']);
        $this->assertSame(1, Database::connect()->table('notification_reads')->where(['notification_id' => $id, 'person_id' => $viewer])->countAllResults());
    }

    public function testPostRead_RefusesANotificationOfAnotherBranchAndOneThatDoesNotExist(): void
    {
        $second = $this->createBranch('B2');
        $other  = $this->notice('stock:2:2', $second);

        $this->actAsWith([IntegrationAccess::NOTIFICATIONS_MODULE], [1]);

        $refused = $this->postJson('/notifications/read/' . $other);

        $this->assertFalse($refused['success']);
        $this->assertSame(lang('Integrations.error_notification_not_found'), $refused['message']);
        $this->assertFalse($this->postJson('/notifications/read/987654')['success']);
        $this->assertSame(0, Database::connect()->table('notification_reads')->countAllResults());
    }

    public function testPostReadAll_MarksEveryUnreadNotificationTheEmployeeSeesAndCountsThem(): void
    {
        $this->actAsWith([IntegrationAccess::NOTIFICATIONS_MODULE]);
        $this->notice('stock:1:1', 1);
        $this->notice('stock:2:1', 1);

        $body = $this->postJson('/notifications/read_all');

        $this->assertTrue($body['success']);
        $this->assertSame(2, $body['id']);
        $this->assertSame(lang('Integrations.notifications_read', ['2']), $body['message']);
        $this->assertSame(0, $this->postJson('/notifications/read_all')['id']);
    }

    public function testPostRead_IsRefusedForAnEmployeeWithoutTheModule(): void
    {
        $id = $this->notice('stock:1:1', 1);

        $this->actAsWith([IntegrationAccess::MODULE, IntegrationAccess::CONFIG]);

        $this->post('/notifications/read/' . $id)->assertRedirect();
        $this->assertSame(0, Database::connect()->table('notification_reads')->countAllResults());
    }
}
