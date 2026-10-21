<?php

namespace Tests\Controllers;

use CodeIgniter\Database\Config;
use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\DatabaseTestTrait;
use CodeIgniter\Test\FeatureTestTrait;
use Tests\Support\EmployeeFixtureTrait;

class StyleguideControllerTest extends CIUnitTestCase
{
    use DatabaseTestTrait;
    use EmployeeFixtureTrait;
    use FeatureTestTrait;

    protected $migrate     = true;
    protected $migrateOnce = true;
    protected $refresh     = false;
    protected $namespace   = null;

    private static bool $doneBootstrap = false;

    private string|false $priorDisallowGrantChange = false;

    protected function setUp(): void
    {
        if (self::$doneBootstrap === false) {
            Config::seeder($this->DBGroup)->call('App\Database\Seeds\TestDatabaseBootstrapSeeder');
            Config::connect($this->DBGroup)->close();

            self::$doneBootstrap = true;
        }

        parent::setUp();

        $this->db->resetTransStatus();
        $this->priorDisallowGrantChange = getenv('DISALLOW_GRANT_CHANGE');
        putenv('DISALLOW_GRANT_CHANGE=false');
    }

    protected function tearDown(): void
    {
        $this->priorDisallowGrantChange === false
            ? putenv('DISALLOW_GRANT_CHANGE')
            : putenv('DISALLOW_GRANT_CHANGE=' . $this->priorDisallowGrantChange);

        parent::tearDown();
    }

    public function testGetIndex_RendersTheReferenceForAdministrators(): void
    {
        $this->withSession(['person_id' => 1, 'menu_group' => 'office']);

        $response = $this->get('/styleguide');

        $response->assertStatus(200);
        $response->assertSee('Design standard');
        $response->assertSee('sg-swatch');
    }

    public function testGetIndex_RedirectsEmployeesWithoutSettingsAccess(): void
    {
        $personId = $this->createEmployee(
            first_name: 'Cashier',
            last_name:  'NoSettings',
            grants:     [['permission_id' => 'sales', 'menu_group' => 'home']],
        );
        $this->withSession(['person_id' => $personId, 'menu_group' => 'home']);

        $response = $this->get('/styleguide');

        $response->assertRedirect();
        $this->assertStringContainsString('no_access', $response->getRedirectUrl());
    }

    public function testGetIndex_RedirectsAnonymousVisitorsToLogin(): void
    {
        $response = $this->get('/styleguide');

        $response->assertRedirectTo('login');
    }
}
