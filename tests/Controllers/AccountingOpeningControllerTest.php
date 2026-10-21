<?php

namespace Tests\Controllers;

use App\Libraries\Accounting\AccountingGuard;
use App\Libraries\Accounting\OpeningBalanceService;
use CodeIgniter\Exceptions\PageNotFoundException;
use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\DatabaseTestTrait;
use Tests\Support\AccountingControllerFixtureTrait;

class AccountingOpeningControllerTest extends CIUnitTestCase
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

        $this->prepareAccounting();
        $this->setConfigValue('accounting_start_date', '2026-01-01');
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
        $personId = $this->employeeWithGrants(['accounting_periods', AccountingGuard::OPENING_MANAGE], $branchIds);
        $this->actAs($personId);

        return $personId;
    }

    /**
     * @return array<string, mixed>
     */
    private function form(int $branchId = 1, string $amount = '1000.00', array $extra = []): array
    {
        return $extra + [
            'branch_id' => (string) $branchId,
            'lines'     => [
                ['account_id' => (string) $this->accountId('cash'), 'description' => 'Cash', 'debit' => $amount, 'credit' => ''],
                ['account_id' => (string) $this->accountId('retained_earnings'), 'description' => '', 'debit' => '', 'credit' => $amount],
            ],
        ];
    }

    public function testGetIndex_ShowsTheGridToAUserWithTheModuleGrant(): void
    {
        $this->actAs($this->employeeWithGrants(['accounting_periods']));

        $response = $this->get('/accounting_periods/opening');

        $response->assertStatus(200);
        $response->assertSee('opening_form');
    }

    public function testGetIndex_OffersTheButtonsOnlyToSomeoneWhoManagesOpeningBalances(): void
    {
        $this->actAs($this->employeeWithGrants(['accounting_periods']));

        $this->get('/accounting_periods/opening')->assertDontSee('id="save_draft"');

        $this->manager();

        $this->get('/accounting_periods/opening')->assertSee('id="save_draft"');
    }

    public function testGetIndex_WarnsWhenTheStartDateIsNotSet(): void
    {
        $this->setConfigValue('accounting_start_date', '');
        $this->manager();

        $response = $this->get('/accounting_periods/opening');

        $response->assertSee(lang('Accounting_periods.opening_no_start'));
        $response->assertDontSee('id="save_draft"');
    }

    public function testGetIndex_OffersOnlyAllowedAccounts(): void
    {
        $this->manager();

        $response = $this->get('/accounting_periods/opening');

        $response->assertSee(esc($this->accountingDb()->table('accounting_accounts')->where('system_key', 'cash')->get()->getRow()->name));
        $response->assertDontSee('data-code="4101"');
        $response->assertDontSee('data-code="1103"');
    }

    public function testGetIndex_ShowsTheBranchChoiceOnlyWhenThereAreSeveralBranches(): void
    {
        $this->manager();

        $this->get('/accounting_periods/opening')->assertDontSee('name="branch"');

        $this->createBranch('B2');

        $this->get('/accounting_periods/opening')->assertSee('name="branch"');
    }

    public function testGetIndex_IsNotFoundForABranchOutsideTheEmployeesScope(): void
    {
        $second = $this->createBranch('B2');
        $this->manager([1]);

        $this->expectException(PageNotFoundException::class);

        $this->get('/accounting_periods/opening?branch=' . $second);
    }

    public function testGetIndex_ShowsTheDraftLines(): void
    {
        $this->manager();
        (new OpeningBalanceService())->saveDraft(1, $this->form()['lines'], null);

        $response = $this->get('/accounting_periods/opening');

        $response->assertSee('value="1000"');
        $response->assertSee('id="discard_draft"');
    }

    public function testGetIndex_ShowsPostedBalancesReadOnlyWithTheReverseButton(): void
    {
        $this->manager();
        (new OpeningBalanceService())->post(1, $this->form()['lines'], false, null);

        $response = $this->get('/accounting_periods/opening');

        $response->assertSee(lang('Accounting_periods.opening_posted_title'));
        $response->assertSee('opening/reverse_form?branch=1');
        $response->assertDontSee('id="opening_form"');
    }

    public function testPostSaveDraft_StoresTheLines(): void
    {
        $this->manager();

        $result = $this->jsonOf($this->post('/accounting_periods/opening/save_draft', $this->form()));

        $this->assertTrue($result['success']);
        $this->assertNotNull((new OpeningBalanceService())->state(1, null)['draft']);
    }

    public function testPostSaveDraft_ReportsAnAccountThatCannotTakeAnOpeningBalance(): void
    {
        $this->manager();

        $result = $this->jsonOf($this->post('/accounting_periods/opening/save_draft', ['branch_id' => '1', 'lines' => [
            ['account_id' => (string) $this->accountId('sales_revenue'), 'debit' => '5.00', 'credit' => ''],
        ]]));

        $this->assertFalse($result['success']);
        $this->assertSame(lang('Accounting.error_opening_account_not_allowed', ['1']), $result['message']);
    }

    public function testPostPost_PostsABalancedOpeningAndRefusesAnUnbalancedOne(): void
    {
        $this->manager();

        $unbalanced = $this->jsonOf($this->post('/accounting_periods/opening/post', ['branch_id' => '1', 'lines' => [
            ['account_id' => (string) $this->accountId('cash'), 'debit' => '300.00', 'credit' => ''],
        ]]));

        $this->assertFalse($unbalanced['success']);
        $this->assertSame(lang('Accounting.error_opening_unbalanced', ['300.0000', '0.0000']), $unbalanced['message']);

        $posted = $this->jsonOf($this->post('/accounting_periods/opening/post', $this->form()));

        $this->assertTrue($posted['success']);
        $this->assertNotNull((new OpeningBalanceService())->state(1, null)['posted']);
    }

    public function testPostPost_PutsTheDifferenceOnTheOpeningAccountWhenAsked(): void
    {
        $this->manager();

        $result = $this->jsonOf($this->post('/accounting_periods/opening/post', ['branch_id' => '1', 'balance' => '1', 'lines' => [
            ['account_id' => (string) $this->accountId('cash'), 'debit' => '300.00', 'credit' => ''],
        ]]));

        $this->assertTrue($result['success']);
        $this->assertSame(2, $this->countRows('accounting_journal_lines'));
    }

    public function testPostPost_ReportsAClosedPeriod(): void
    {
        $this->manager();
        $this->closeThrough('2026-01-31');

        $result = $this->jsonOf($this->post('/accounting_periods/opening/post', $this->form()));

        $this->assertFalse($result['success']);
        $this->assertStringContainsString('2026-01-31', $result['message']);
    }

    public function testPostPost_RefusesABranchOutsideTheEmployeesScopeAndAMissingPermission(): void
    {
        $second = $this->createBranch('B2');
        $this->manager([1]);

        $this->assertFalse($this->jsonOf($this->post('/accounting_periods/opening/post', $this->form($second)))['success']);

        $this->actAs($this->employeeWithGrants(['accounting_periods']));

        $this->assertFalse($this->jsonOf($this->post('/accounting_periods/opening/post', $this->form()))['success']);
        $this->assertSame(0, $this->countRows('accounting_journals'));
    }

    public function testPostDiscard_RemovesTheDraft(): void
    {
        $this->manager();
        (new OpeningBalanceService())->saveDraft(1, $this->form()['lines'], null);

        $result = $this->jsonOf($this->post('/accounting_periods/opening/discard', ['branch_id' => '1']));

        $this->assertTrue($result['success']);
        $this->assertNull((new OpeningBalanceService())->state(1, null)['draft']);
    }

    public function testPostReverse_ReversesThePostedOpeningWithAReason(): void
    {
        $this->manager();
        (new OpeningBalanceService())->post(1, $this->form()['lines'], false, null);

        $missing  = $this->jsonOf($this->post('/accounting_periods/opening/reverse', ['branch_id' => '1', 'date' => '2026-01-05', 'reason' => '']));
        $reversed = $this->jsonOf($this->post('/accounting_periods/opening/reverse', ['branch_id' => '1', 'date' => '2026-01-05', 'reason' => 'Wrong counts']));

        $this->assertFalse($missing['success']);
        $this->assertTrue($reversed['success']);
        $this->assertNull((new OpeningBalanceService())->state(1, null)['posted']);
    }

    public function testGetReverseForm_IsNotFoundWithoutAPostedOpeningOrWithoutThePermission(): void
    {
        $this->manager();

        try {
            $this->get('/accounting_periods/opening/reverse_form?branch=1');
            $this->fail('Expected the form to be hidden without a posted opening.');
        } catch (PageNotFoundException) {
            $this->addToAssertionCount(1);
        }

        (new OpeningBalanceService())->post(1, $this->form()['lines'], false, null);

        $this->get('/accounting_periods/opening/reverse_form?branch=1')->assertSee('opening_reverse_form');

        $this->actAs($this->employeeWithGrants(['accounting_periods']));

        $this->expectException(PageNotFoundException::class);

        $this->get('/accounting_periods/opening/reverse_form?branch=1');
    }

    public function testGetIndex_ExplainsWhoGrantsThePermissionToSomeoneWhoCannotManage(): void
    {
        $this->actAs($this->employeeWithGrants(['accounting_periods']));

        $this->get('/accounting_periods/opening')->assertSee(lang('Accounting_periods.permission_note'));

        $this->manager();

        $this->get('/accounting_periods/opening')->assertDontSee(lang('Accounting_periods.permission_note'));
    }
}
