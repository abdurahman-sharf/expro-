<?php

namespace Tests\Controllers;

use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\DatabaseTestTrait;
use CodeIgniter\Test\FeatureTestTrait;
use Config\Database;
use Tests\Support\AccountingFixtureTrait;
use Tests\Support\EmployeeFixtureTrait;

/**
 * The center of the tax settings opens for whoever holds any one of the modules of its screens and shows each card
 * only to whoever holds the module of the screen it opens.
 */
class TaxSettingsHubControllerTest extends CIUnitTestCase
{
    use DatabaseTestTrait;
    use FeatureTestTrait;
    use AccountingFixtureTrait;
    use EmployeeFixtureTrait;

    protected $migrate     = true;
    protected $migrateOnce = true;
    protected $refresh     = false;
    protected $namespace   = null;

    public static function setUpBeforeClass(): void
    {
        Database::seeder('tests')->call('TestDatabaseBootstrapSeeder');
    }

    protected function tearDown(): void
    {
        $this->removeInsertedEntities();

        parent::tearDown();
    }

    private function actAs(int $personId): void
    {
        $this->withSession(['person_id' => $personId, 'menu_group' => 'office']);
    }

    public function testGetIndex_ShowsTheCardsOfTheScreensTheEmployeeMayOpenOnly(): void
    {
        $this->actAs($this->employeeWithGrants(['taxes']));

        $page = $this->get('/tax_settings');

        $page->assertOK();
        $page->assertSee('tax_card_classes');
        $page->assertSee('tax_card_legacy');
        $page->assertDontSee('tax_card_company');
        $page->assertDontSee('tax_card_einvoicing');
        $page->assertDontSee('tax_card_reports');
    }

    public function testGetIndex_OpensForTheSettingsModuleAlone(): void
    {
        $this->actAs($this->employeeWithGrants(['config']));

        $page = $this->get('/tax_settings');

        $page->assertOK();
        $page->assertSee('tax_card_company');
        $page->assertDontSee('tax_card_classes');
    }

    public function testGetIndex_SendsAnEmployeeWithNoneOfTheModulesToTheNoAccessPage(): void
    {
        $this->actAs($this->employeeWithGrants(['items']));

        $this->get('/tax_settings')->assertRedirectTo('no_access/taxes');
    }
}
