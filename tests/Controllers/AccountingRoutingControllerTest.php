<?php

namespace Tests\Controllers;

use App\Libraries\Accounting\AccountingGuard;
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

        foreach (['sales_revenue', 'sales_returns', 'sales_discount', 'sales_cogs', 'inventory_stock', 'tax_output', 'cash_difference', 'receivables_control', 'giftcards_liability'] as $key) {
            $response->assertSee(lang('Accounting_routing.item_' . $key));
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

        $result = $this->jsonOf($this->post('/accounting_routing/save', ['route' => ['sales.revenue' => (string) $other]]));

        $this->assertTrue($result['success']);
        $this->assertSame(lang('Accounting_routing.saved'), $result['message']);
        $this->assertSame($other, $this->routeOf('sales.revenue'));
    }

    public function testPostSave_ReportsEveryRowThatIsNotValidAndSavesNone(): void
    {
        $other = $this->customAccount('41', '4110', 'Other sales');
        $this->manager();

        $result = $this->jsonOf($this->post('/accounting_routing/save', ['route' => [
            'sales.revenue' => (string) $other,
            'sales.cogs'    => (string) $this->accountIdByCode('5'),
            'sales.returns' => '',
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

        $result = $this->jsonOf($this->post('/accounting_routing/save', ['route' => ['sales.revenue' => (string) $other]]));

        $this->assertFalse($result['success']);
        $this->assertSame(lang('Accounting.error_permission_denied'), $result['message']);
        $this->assertSame($this->accountId('sales_revenue'), $this->routeOf('sales.revenue'));
    }

    public function testPostSave_RefusesSomeoneLimitedToBranches(): void
    {
        $this->manager([1]);
        $other = $this->customAccount('41', '4110', 'Other sales');

        $result = $this->jsonOf($this->post('/accounting_routing/save', ['route' => ['sales.revenue' => (string) $other]]));

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
