<?php

namespace Tests\Controllers;

use App\Libraries\Accounting\AccountingGuard;
use App\Libraries\Accounting\TaxDefinitions;
use CodeIgniter\Exceptions\PageNotFoundException;
use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\DatabaseTestTrait;
use CodeIgniter\Test\FeatureTestTrait;
use Config\Database;
use Tests\Support\AccountingFixtureTrait;

/**
 * The tax list screens: the list for whoever holds the taxes module, with accounting on or off, the
 * form with the account of the tax that only whoever may change the routing can choose, and the
 * changes as POSTs.
 *
 * @internal
 */
final class Tax_definitionsControllerTest extends CIUnitTestCase
{
    use DatabaseTestTrait;
    use FeatureTestTrait;
    use AccountingFixtureTrait;

    protected $migrate     = true;
    protected $migrateOnce = true;
    protected $refresh     = false;
    protected $namespace;
    private int $router;

    public static function setUpBeforeClass(): void
    {
        $seeder = Database::seeder('tests');
        $seeder->call('TestDatabaseBootstrapSeeder');
    }

    protected function setUp(): void
    {
        parent::setUp();

        $this->reinstallChart();
        $this->router = $this->employeeWithGrants([AccountingGuard::ROUTING_MANAGE]);
    }

    protected function tearDown(): void
    {
        $this->releaseOpenTransactions();
        $this->accountingDb()->table('tax_definitions')->truncate();
        $this->accountingDb()->table('accounting_audit_log')->where('entity_type', TaxDefinitions::ENTITY)->delete();
        $this->setConfigValue('accounting_enabled', '0');
        $this->removeInsertedEntities();

        parent::tearDown();
    }

    /**
     * @param list<string> $permissions
     */
    private function actAsWith(array $permissions): int
    {
        $personId = $this->employeeWithGrants($permissions);

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

    public function testGetIndexShowsTheListToWhoeverHoldsTheTaxesModuleWhetherAccountingIsOnOrOff(): void
    {
        foreach (['0', '1'] as $accounting) {
            $this->setConfigValue('accounting_enabled', $accounting);
            $this->actAsWith(['taxes']);

            $view = $this->get('/tax_definitions');

            $view->assertStatus(200);
            $view->assertSee(lang('Tax_definitions.new'));
        }
    }

    public function testGetIndexRedirectsAUserWithoutTheTaxesModule(): void
    {
        $this->actAsWith(['receipt_vouchers']);

        $this->get('/tax_definitions')->assertRedirect();
    }

    public function testGetSearchListsTheTaxesWithTheirModesAndAccountsAndFiltersThem(): void
    {
        $this->actAsWith(['taxes']);

        $taxes   = new TaxDefinitions();
        $account = $this->customAccount('215', '215003', 'Tax on services');

        $taxes->save(['tax_name' => 'Service', 'tax_mode' => TaxDefinitions::INCLUDED, 'account_id' => $account], null, $this->router);
        $taxes->save(['tax_name' => 'VAT'], null, $this->router);

        $all     = json_decode($this->get('/tax_definitions/search?sort=tax_name&order=asc')->getJSON(), true);
        $service = json_decode($this->get('/tax_definitions/search?search=Serv')->getJSON(), true);

        $this->assertSame(2, $all['total']);
        $this->assertSame(['Service', 'VAT'], array_column($all['rows'], 'tax_name'));
        $this->assertSame([lang('Tax_definitions.mode_included'), lang('Tax_definitions.mode_default')], array_column($all['rows'], 'tax_mode'));
        $this->assertSame('215003 - Tax on services', $all['rows'][0]['account']);
        $this->assertSame(lang('Tax_definitions.account_default'), $all['rows'][1]['account']);
        $this->assertSame(['Service'], array_column($service['rows'], 'tax_name'));
    }

    public function testGetViewOffersTheAccountsOnlyToWhoMayChangeTheRouting(): void
    {
        $this->customAccount('215', '215003', 'Tax on services');

        $this->actAsWith(['taxes']);

        $locked = $this->get('/tax_definitions/view');

        $locked->assertSee('name="tax_name"');
        $locked->assertSee('disabled');
        $locked->assertSee(lang('Tax_definitions.account_locked'));

        $this->actAsWith(['taxes', AccountingGuard::ROUTING_MANAGE]);

        $open = $this->get('/tax_definitions/view');

        $open->assertSee('215003 - Tax on services');
        $open->assertSee(lang('Tax_definitions.account_desc'));
        $open->assertSee(lang('Tax_definitions.mode_included'));
    }

    public function testGetViewKeepsTheNameOfASavedTaxFromBeingEdited(): void
    {
        $id = (new TaxDefinitions())->save(['tax_name' => 'VAT'], null, $this->router);

        $this->actAsWith(['taxes']);

        $this->get('/tax_definitions/view/' . $id)->assertSee('readonly');
    }

    public function testPostSaveSavesAndChangesATaxAndSaysInWordsWhyItRefuses(): void
    {
        $this->actAsWith(['taxes']);

        $added = $this->postJson('/tax_definitions/save', ['tax_name' => 'VAT', 'tax_mode' => TaxDefinitions::INCLUDED]);

        $this->assertTrue($added['success']);
        $this->assertSame(lang('Tax_definitions.successful_adding'), $added['message']);

        $changed = $this->postJson('/tax_definitions/save/' . $added['id'], ['tax_name' => 'VAT', 'tax_mode' => TaxDefinitions::EXCLUDED]);

        $this->assertTrue($changed['success']);
        $this->assertSame('0', (string) (new TaxDefinitions())->find($added['id'])->tax_included);

        $taken = $this->postJson('/tax_definitions/save', ['tax_name' => 'vat']);

        $this->assertFalse($taken['success']);
        $this->assertSame(lang('Accounting.error_tax_name_taken'), $taken['message']);

        $empty = $this->postJson('/tax_definitions/save', ['tax_name' => ' ']);

        $this->assertSame(lang('Accounting.error_tax_name_invalid'), $empty['message']);

        $bad = $this->postJson('/tax_definitions/save', ['tax_name' => 'Other', 'tax_mode' => 'sometimes']);

        $this->assertSame(lang('Accounting.error_tax_mode_invalid'), $bad['message']);
    }

    public function testPostSaveRejectsAnAccountWithoutThePermissionToChangeTheRouting(): void
    {
        $account = $this->customAccount('215', '215003', 'Tax on services');

        $this->actAsWith(['taxes']);

        $refused = $this->postJson('/tax_definitions/save', ['tax_name' => 'Service', 'account_id' => $account]);

        $this->assertFalse($refused['success']);
        $this->assertSame(lang('Accounting.error_permission_denied'), $refused['message']);
        $this->assertSame(0, $this->countRows('tax_definitions'));

        $this->actAsWith(['taxes', AccountingGuard::ROUTING_MANAGE]);

        $this->assertTrue($this->postJson('/tax_definitions/save', ['tax_name' => 'Service', 'account_id' => $account])['success']);
    }

    public function testPostSaveRejectsAnAccountThatCannotTakeATax(): void
    {
        $this->actAsWith(['taxes', AccountingGuard::ROUTING_MANAGE]);

        $refused = $this->postJson('/tax_definitions/save', ['tax_name' => 'Service', 'account_id' => $this->accountId('cogs')]);

        $this->assertFalse($refused['success']);
        $this->assertSame(lang('Accounting.error_tax_liability_account_invalid'), $refused['message']);
    }

    public function testPostSaveKeepsTheAccountOfATaxWhenTheFormDoesNotSendOne(): void
    {
        $account = $this->customAccount('215', '215003', 'Tax on services');

        $this->actAsWith(['taxes', AccountingGuard::ROUTING_MANAGE]);

        $added = $this->postJson('/tax_definitions/save', ['tax_name' => 'Service', 'account_id' => $account]);

        $this->assertTrue($this->postJson('/tax_definitions/save/' . $added['id'], ['tax_name' => 'Service', 'tax_mode' => TaxDefinitions::INCLUDED])['success']);
        $this->assertSame($account, (int) (new TaxDefinitions())->find($added['id'])->liability_account_id);
    }

    public function testPostSaveIsNotFoundForATaxThatDoesNotExistAndIsNeverReachedByALink(): void
    {
        $this->actAsWith(['taxes']);

        $id = (new TaxDefinitions())->save(['tax_name' => 'VAT'], null, $this->router);

        foreach (['/tax_definitions/save', '/tax_definitions/save/' . $id] as $path) {
            try {
                $this->get($path);
                $this->fail('A GET reached ' . $path);
            } catch (PageNotFoundException) {
                $this->addToAssertionCount(1);
            }
        }

        $this->expectException(PageNotFoundException::class);

        $this->post('/tax_definitions/save/99999', ['tax_name' => 'VAT']);
    }

    public function testPostDeleteDeletesTheTaxesAndAGetNeverDoes(): void
    {
        $this->actAsWith(['taxes']);

        $id = (new TaxDefinitions())->save(['tax_name' => 'VAT'], null, $this->router);

        try {
            $this->get('/tax_definitions/delete');
            $this->fail('A GET deleted taxes');
        } catch (PageNotFoundException) {
            $this->addToAssertionCount(1);
        }

        $this->assertNotNull((new TaxDefinitions())->find($id));

        $personId = $this->actAsWith(['taxes']);
        $deleted  = $this->postJson('/tax_definitions/delete', ['ids' => [(string) $id]]);

        $this->assertTrue($deleted['success']);
        $this->assertNull((new TaxDefinitions())->find($id));
        $this->assertSame($personId, (int) $this->accountingDb()->table('accounting_audit_log')->where(['entity_type' => TaxDefinitions::ENTITY, 'entity_id' => $id, 'action' => 'deleted'])->get()->getRow()->employee_id, 'the audit names who deleted the tax');
    }

    public function testGetRowShowsOneTaxAndIsNotFoundForADeletedOne(): void
    {
        $this->actAsWith(['taxes']);

        $id = (new TaxDefinitions())->save(['tax_name' => 'VAT'], null, $this->router);

        $this->assertSame('VAT', json_decode($this->get('/tax_definitions/row/' . $id)->getJSON(), true)['tax_name']);

        (new TaxDefinitions())->delete([$id], $this->router);

        $this->expectException(PageNotFoundException::class);

        $this->get('/tax_definitions/row/' . $id);
    }
}
