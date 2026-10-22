<?php

namespace Tests\Controllers;

use App\Libraries\Accounting\AccountingGuard;
use App\Libraries\Accounting\RoutingKey;
use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\DatabaseTestTrait;
use Tests\Support\AccountingControllerFixtureTrait;

class AccountingRoutingControllerTest extends CIUnitTestCase
{
    use DatabaseTestTrait;
    use AccountingControllerFixtureTrait;

    protected $migrate     = true;
    protected $migrateOnce = true;
    protected $refresh     = false;
    protected $namespace   = null;

    protected function setUp(): void
    {
        parent::setUp();

        $this->prepareAccounting(false);
        $this->accountingDb()->table('sales_payments')->truncate();
    }

    protected function tearDown(): void
    {
        $this->releaseOpenTransactions();

        parent::tearDown();
    }

    /**
     * @param list<int>|null $branchIds
     */
    private function manager(?array $branchIds = null): int
    {
        $personId = $this->employeeWithGrants(['accounting_routing', AccountingGuard::ROUTING_MANAGE], $branchIds);
        $this->actAs($personId);

        return $personId;
    }

    private function routeOf(string $key): ?int
    {
        $row = $this->accountingDb()->table('accounting_routing')->where('routing_key', $key)->get()->getRow();

        return $row === null || $row->account_id === null ? null : (int) $row->account_id;
    }

    public function testGetIndex_ShowsTheScreenWhileAccountingIsOff(): void
    {
        $this->actAs($this->employeeWithGrants(['accounting_routing']));

        $response = $this->get('/accounting_routing');

        $response->assertStatus(200);
        $response->assertSee('routing_form');
        $response->assertSee('sales_routing');
        $response->assertSee('payments_routing');
    }

    public function testGetIndex_RedirectsAUserWithoutTheModuleGrant(): void
    {
        $this->actAs($this->employeeWithGrants(['accounting_chart']));

        $this->get('/accounting_routing')->assertRedirect();
    }

    public function testGetIndex_ListsEveryRowWithItsLabel(): void
    {
        $this->manager();

        $response = $this->get('/accounting_routing');

        foreach (RoutingKey::editable() as $key) {
            $response->assertSee(lang('Accounting_routing.item_' . str_replace('.', '_', $key->value)));
        }

        foreach ([...RoutingKey::GROUPS, 'payments'] as $group) {
            $response->assertSee(lang('Accounting_routing.' . $group . '_title'));
        }

        foreach (['cash', 'debit', 'credit', 'check', 'bank_transfer', 'wallet', 'due', 'giftcard', 'rewards'] as $type) {
            $response->assertSee(lang('Accounting_routing.payment_' . $type));
        }
    }

    public function testGetIndex_ShowsTheCompleteNoticeOnAFreshChart(): void
    {
        $this->manager();

        $this->get('/accounting_routing')->assertSee(lang('Accounting_routing.complete'));
    }

    public function testGetIndex_LinksToTheRowsThatNeedAnAccount(): void
    {
        $this->accountingDb()->table('accounting_routing')->where('routing_key', 'sales.cogs')->update(['account_id' => null]);
        $this->manager();

        $response = $this->get('/accounting_routing');

        $response->assertSee('href="#route_sales_cogs"');
        $response->assertSee(lang('Accounting_routing.status_missing'));
    }

    public function testGetIndex_OffersOnlyAccountsOfTheRightTypeForARow(): void
    {
        $this->customAccount('41', '4110', 'Extra revenue');
        $this->customAccount('11', '1110', 'Extra asset');
        $this->manager();

        $body = $this->get('/accounting_routing')->getBody();
        $revenueRow = substr($body, (int) strpos($body, 'id="route_sales_revenue_account"'));
        $revenueRow = substr($revenueRow, 0, (int) strpos($revenueRow, '</select>'));

        $this->assertStringContainsString('4110', $revenueRow);
        $this->assertStringNotContainsString('1110', $revenueRow);
    }

    public function testGetIndex_OffersGroupAccountsForAnEntityRow(): void
    {
        $this->manager();

        $body = $this->get('/accounting_routing')->getBody();
        $row  = substr($body, (int) strpos($body, 'id="route_sales_customers_account"'));
        $row  = substr($row, 0, (int) strpos($row, '</select>'));

        $this->assertStringContainsString('12041', $row);
        $this->assertStringNotContainsString('120101', $row);
    }

    public function testGetIndex_OffersTheTypesOfARowWithTheCurrentOneSelected(): void
    {
        $this->manager();

        $body = $this->get('/accounting_routing')->getBody();
        $row  = substr($body, (int) strpos($body, 'id="route_sales_discount_type"'));
        $row  = substr($row, 0, (int) strpos($row, '</select>'));

        $this->assertStringContainsString('value="cancelled" selected', $row);
        $this->assertStringContainsString('value="auto"', $row);
        $this->assertStringNotContainsString('per_item', $row);
    }

    public function testGetIndex_NamesTheTypeSelectOfEveryRowForScreenReaders(): void
    {
        $this->manager();

        $body = $this->get('/accounting_routing')->getBody();

        $this->assertSame(count(RoutingKey::editable()), substr_count($body, '][type]"'));
        $this->assertSame(count(RoutingKey::editable()), substr_count($body, 'aria-label="' . lang('Accounting_routing.column_type') . ': '));
    }

    public function testGetIndex_CallsTheCancelledTypeNoRoutingOnTheCostRow(): void
    {
        $this->manager();

        $body = $this->get('/accounting_routing')->getBody();
        $row  = substr($body, (int) strpos($body, 'id="route_sales_cogs_type"'));
        $row  = substr($row, 0, (int) strpos($row, '</select>'));

        $this->assertStringContainsString('>' . lang('Accounting_routing.type_none') . '<', $row);
        $this->assertStringNotContainsString(lang('Accounting_routing.type_cancelled'), $row);
    }

    public function testGetIndex_ShowsCreditSalesAndGiftCardsAsFixedRows(): void
    {
        $this->manager();

        $body = $this->get('/accounting_routing')->getBody();

        $this->assertStringNotContainsString('name="route[payment.due]"', $body);
        $this->assertStringNotContainsString('name="route[payment.giftcard]"', $body);
        $this->assertStringContainsString('name="route[payment.cash]"', $body);
    }

    public function testGetIndex_OffersTheSaveButtonOnlyToSomeoneWhoManagesTheRouting(): void
    {
        $this->actAs($this->employeeWithGrants(['accounting_routing']));

        $viewer = $this->get('/accounting_routing');

        $viewer->assertDontSee('btn btn-primary btn-sm">' . lang('Common.submit'));
        $viewer->assertDontSee('data-restore-default="route_');
        $viewer->assertSee(lang('Accounting_routing.permission_note'));

        $this->manager();

        $manager = $this->get('/accounting_routing');
        $manager->assertSee('btn btn-primary btn-sm">' . lang('Common.submit'));
        $manager->assertSee('data-restore-default="route_');
    }

    public function testGetIndex_ExplainsWhySomeoneLimitedToBranchesCannotSave(): void
    {
        $this->manager([1]);

        $response = $this->get('/accounting_routing');

        $response->assertDontSee('btn btn-primary btn-sm">' . lang('Common.submit'));
        $response->assertSee(lang('Accounting_routing.scope_note'));
    }

    public function testPostSave_ChangesTheRoutingAndAnswersWithTheSavedMessage(): void
    {
        $other = $this->customAccount('41', '4110', 'Other sales');
        $this->manager();

        $result = $this->jsonOf($this->post('/accounting_routing/save', ['route' => ['sales.revenue' => ['account' => (string) $other]]]));

        $this->assertTrue($result['success']);
        $this->assertSame(lang('Accounting_routing.saved'), $result['message']);
        $this->assertSame($other, $this->routeOf('sales.revenue'));
    }

    public function testPostSave_ChangesTheTypeOfARow(): void
    {
        $this->manager();

        $result = $this->jsonOf($this->post('/accounting_routing/save', ['route' => ['sales.discount' => ['type' => 'auto', 'account' => (string) $this->accountId('sales_discount')]]]));

        $this->assertTrue($result['success']);
        $this->assertSame('auto', $this->accountingDb()->table('accounting_routing')->where('routing_key', 'sales.discount')->get()->getRow()->routing_type);
    }

    public function testPostSave_RefusesATypeTheRowDoesNotOffer(): void
    {
        $this->manager();

        $result = $this->jsonOf($this->post('/accounting_routing/save', ['route' => ['sales.customers' => ['type' => 'cancelled']]]));

        $this->assertFalse($result['success']);
        $this->assertSame(['sales.customers' => lang('Accounting.error_routing_type_invalid')], $result['errors']);
    }

    public function testPostSave_ReportsEveryRowThatIsNotValidAndSavesNone(): void
    {
        $other = $this->customAccount('41', '4110', 'Other sales');
        $this->manager();

        $result = $this->jsonOf($this->post('/accounting_routing/save', ['route' => [
            'sales.revenue' => ['account' => (string) $other],
            'sales.cogs'    => ['account' => (string) $this->accountIdByCode('5')],
            'sales.returns' => ['account' => ''],
        ]]));

        $this->assertFalse($result['success']);
        $this->assertSame(lang('Accounting.error_routing_invalid', ['2']), $result['message']);
        $this->assertSame([
            'sales.cogs'    => lang('Accounting.error_routing_account_not_postable'),
            'sales.returns' => lang('Accounting.error_routing_account_required'),
        ], $result['errors']);
        $this->assertSame($this->accountId('sales_revenue'), $this->routeOf('sales.revenue'));
    }

    public function testPostSave_RefusesSomeoneWithoutThePermission(): void
    {
        $this->actAs($this->employeeWithGrants(['accounting_routing']));
        $other = $this->customAccount('41', '4110', 'Other sales');

        $result = $this->jsonOf($this->post('/accounting_routing/save', ['route' => ['sales.revenue' => ['account' => (string) $other]]]));

        $this->assertFalse($result['success']);
        $this->assertSame(lang('Accounting.error_permission_denied'), $result['message']);
        $this->assertSame($this->accountId('sales_revenue'), $this->routeOf('sales.revenue'));
    }

    public function testPostSave_RefusesSomeoneLimitedToBranches(): void
    {
        $this->manager([1]);
        $other = $this->customAccount('41', '4110', 'Other sales');

        $result = $this->jsonOf($this->post('/accounting_routing/save', ['route' => ['sales.revenue' => ['account' => (string) $other]]]));

        $this->assertFalse($result['success']);
        $this->assertSame($this->accountId('sales_revenue'), $this->routeOf('sales.revenue'));
    }

    public function testPostSave_IgnoresARequestWithoutRows(): void
    {
        $this->manager();

        $result = $this->jsonOf($this->post('/accounting_routing/save'));

        $this->assertTrue($result['success']);
    }
}
