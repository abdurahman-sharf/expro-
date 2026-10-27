<?php

namespace Tests\Controllers;

use App\Libraries\Accounting\AccountingGuard;
use App\Libraries\Accounting\AccountService;
use App\Libraries\Accounting\JournalService;
use CodeIgniter\Exceptions\PageNotFoundException;
use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\DatabaseTestTrait;
use Tests\Support\AccountingControllerFixtureTrait;

class AccountingChartControllerTest extends CIUnitTestCase
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
    }

    protected function tearDown(): void
    {
        $this->releaseOpenTransactions();

        parent::tearDown();
    }

    public function testGetIndex_ShowsTheChartToAUserWithTheModuleGrant(): void
    {
        $this->actAs($this->employeeWithGrants(['accounting_chart']));

        $response = $this->get('/accounting_chart');

        $response->assertStatus(200);
        $response->assertSee('acc-chart');
    }

    public function testGetIndex_SaysTheBalancesOfRevenueAndExpenseAccountsAddUpFromTheStartOfTheBooks(): void
    {
        $this->actAs($this->employeeWithGrants(['accounting_chart']));

        $response = $this->get('/accounting_chart');

        $response->assertSee('id="chart_nominal_note"');
        $response->assertSee(lang('Accounting_chart.nominal_note'));
    }

    public function testGetIndex_HidesTheAddButtonWithoutTheManagePermission(): void
    {
        $this->actAs($this->employeeWithGrants(['accounting_chart']));

        $response = $this->get('/accounting_chart');

        $response->assertDontSee('accounting_chart/view?parent_id');
    }

    public function testGetIndex_OffersTheAddButtonWithTheManagePermission(): void
    {
        $this->actAs($this->employeeWithGrants(['accounting_chart', AccountingGuard::CHART_MANAGE]));

        $response = $this->get('/accounting_chart');

        $response->assertSee('accounting_chart/view?parent_id');
    }

    public function testGetIndex_IsNotFoundWhileAccountingIsDisabled(): void
    {
        $this->prepareAccounting(false);
        $this->actAs($this->employeeWithGrants(['accounting_chart']));

        $this->expectException(PageNotFoundException::class);

        $this->get('/accounting_chart');
    }

    public function testGetIndex_RedirectsAUserWithoutTheModuleGrant(): void
    {
        $this->actAs($this->employeeWithGrants(['accounting_journals']));

        $response = $this->get('/accounting_chart');

        $response->assertRedirect();
    }

    public function testGetIndex_IsNotFoundForAnUnknownParent(): void
    {
        $this->actAs($this->employeeWithGrants(['accounting_chart']));

        $this->expectException(PageNotFoundException::class);

        $this->get('/accounting_chart?parent=999999');
    }

    public function testGetTree_ListsTheRootAccountsAndOpensTheSelectedBranch(): void
    {
        $this->actAs($this->employeeWithGrants(['accounting_chart']));
        $assetsId = $this->accountIdByCode('1');
        $childId  = $this->customAccount('11', '1110', 'Petty cash drawer');

        $response = $this->get('/accounting_chart/tree?parent=' . $childId);

        $response->assertStatus(200);
        $response->assertSee('Petty cash drawer');
        $response->assertSee('accounting_chart?parent=' . $assetsId);
        $response->assertSee('aria-current="page"');
    }

    public function testGetSearch_ListsTheRootAccountsWithTheirBalances(): void
    {
        $this->actAs($this->employeeWithGrants(['accounting_chart']));

        $result = $this->jsonOf($this->get('/accounting_chart/search'));

        $codes = array_column($result['rows'], 'code');
        $this->assertContains('1', $codes);
        $this->assertSame(count($codes), $result['total']);
        $this->assertArrayHasKey('balance', $result['rows'][0]);
    }

    public function testGetSearch_ListsTheChildrenOfTheSelectedAccount(): void
    {
        $this->actAs($this->employeeWithGrants(['accounting_chart']));

        $result = $this->jsonOf($this->get('/accounting_chart/search?parent_id=' . $this->accountIdByCode('1')));

        $this->assertContains('11', array_column($result['rows'], 'code'));
        $this->assertNotContains('1', array_column($result['rows'], 'code'));
    }

    public function testGetSearch_FindsAnAccountByNameAcrossTheTree(): void
    {
        $this->actAs($this->employeeWithGrants(['accounting_chart']));
        $this->customAccount('11', '1110', 'Petty cash drawer');

        $result = $this->jsonOf($this->get('/accounting_chart/search?search=drawer'));

        $this->assertSame(['1110'], array_column($result['rows'], 'code'));
    }

    public function testGetSearch_EscapesTheAccountNameInTheRow(): void
    {
        $this->actAs($this->employeeWithGrants(['accounting_chart']));
        $this->customAccount('11', '1110', '<b>Bold</b> drawer');

        $result = $this->jsonOf($this->get('/accounting_chart/search?search=drawer'));

        $this->assertStringNotContainsString('<b>', $result['rows'][0]['name']);
        $this->assertStringContainsString('&lt;b&gt;', $result['rows'][0]['name']);
    }

    public function testGetSearch_SortsByNameDescending(): void
    {
        $this->actAs($this->employeeWithGrants(['accounting_chart']));
        $this->customAccount('11', '1110', 'Alpha drawer');
        $this->customAccount('11', '1111', 'Omega drawer');

        $result = $this->jsonOf($this->get('/accounting_chart/search?search=drawer&sort=name&order=desc'));

        $this->assertSame(['1111', '1110'], array_column($result['rows'], 'code'));
    }

    public function testGetSearch_OffersRowActionsOnlyWithTheManagePermission(): void
    {
        $this->customAccount('11', '1110', 'Petty cash drawer');

        $this->actAs($this->employeeWithGrants(['accounting_chart']));
        $viewer = $this->jsonOf($this->get('/accounting_chart/search?search=drawer'));

        $this->actAs($this->employeeWithGrants(['accounting_chart', AccountingGuard::CHART_MANAGE]));
        $manager = $this->jsonOf($this->get('/accounting_chart/search?search=drawer'));

        $this->assertSame('', $viewer['rows'][0]['edit']);
        $this->assertStringContainsString('data-acc-action="delete"', $manager['rows'][0]['edit']);
    }

    public function testGetSearch_OffersNoDeleteForASystemAccount(): void
    {
        $this->actAs($this->employeeWithGrants(['accounting_chart', AccountingGuard::CHART_MANAGE]));
        $cashId = $this->accountId('cash');
        $code   = (new AccountService())->find($cashId)->code;

        $result = $this->jsonOf($this->get('/accounting_chart/search?search=' . $code));
        $cash   = array_values(array_filter($result['rows'], static fn (array $row): bool => $row['account_id'] === $cashId));

        $this->assertNotSame([], $cash);
        $this->assertStringNotContainsString('data-acc-action="delete"', $cash[0]['edit']);
        $this->assertStringNotContainsString('data-acc-action="deactivate"', $cash[0]['edit']);
    }

    public function testGetView_IsNotFoundWithoutTheManagePermission(): void
    {
        $this->actAs($this->employeeWithGrants(['accounting_chart']));

        $this->expectException(PageNotFoundException::class);

        $this->get('/accounting_chart/view/-1');
    }

    public function testGetView_ReturnsTheFormForANewAccountWithASuggestedCode(): void
    {
        $this->actAs($this->employeeWithGrants(['accounting_chart', AccountingGuard::CHART_MANAGE]));

        $response = $this->get('/accounting_chart/view/-1?parent_id=' . $this->accountIdByCode('11'));

        $response->assertStatus(200);
        $response->assertSee('id="account_form"');
        $response->assertSee('name="is_group"');
    }

    public function testGetView_LocksTheCodeOfAnAccountWithJournalLines(): void
    {
        $employeeId = $this->employeeWithGrants(['accounting_chart', AccountingGuard::CHART_MANAGE]);
        $this->actAs($employeeId);
        $cashId = $this->accountId('cash');
        $this->postedJournalBy();

        $response = $this->get('/accounting_chart/view/' . $cashId);

        $response->assertSee('readonly');
    }

    public function testPostSave_CreatesAnAccountAndAuditsIt(): void
    {
        $this->actAs($this->employeeWithGrants(['accounting_chart', AccountingGuard::CHART_MANAGE]));

        $result = $this->jsonOf($this->post('/accounting_chart/save/-1', [
            'parent_id' => $this->accountIdByCode('11'),
            'code'      => '1110',
            'name'      => 'Petty cash drawer',
            'is_group'  => '0',
            'contra'    => '0',
        ]));

        $this->assertTrue($result['success']);
        $this->assertSame('Petty cash drawer', (new AccountService())->find($result['id'])->name);
        $this->assertSame(1, $this->accountingDb()->table('accounting_audit_log')->where('action', 'created')->where('entity_id', $result['id'])->countAllResults());
    }

    public function testPostSave_RejectsADuplicateCodeWithAReadableMessage(): void
    {
        $this->actAs($this->employeeWithGrants(['accounting_chart', AccountingGuard::CHART_MANAGE]));
        $this->customAccount('11', '1110', 'Petty cash drawer');

        $result = $this->jsonOf($this->post('/accounting_chart/save/-1', [
            'parent_id' => $this->accountIdByCode('11'),
            'code'      => '1110',
            'name'      => 'Another drawer',
        ]));

        $this->assertFalse($result['success']);
        $this->assertStringContainsString('1110', $result['message']);
    }

    public function testPostSave_RejectsAUserWithoutTheManagePermission(): void
    {
        $this->actAs($this->employeeWithGrants(['accounting_chart']));

        $result = $this->jsonOf($this->post('/accounting_chart/save/-1', [
            'parent_id' => $this->accountIdByCode('11'),
            'code'      => '1110',
            'name'      => 'Petty cash drawer',
        ]));

        $this->assertFalse($result['success']);
        $this->assertSame(0, $this->accountingDb()->table('accounting_accounts')->where('code', '1110')->countAllResults());
    }

    public function testPostSave_RenamesAnAccount(): void
    {
        $this->actAs($this->employeeWithGrants(['accounting_chart', AccountingGuard::CHART_MANAGE]));
        $accountId = $this->customAccount('11', '1110', 'Petty cash drawer');

        $result = $this->jsonOf($this->post('/accounting_chart/save/' . $accountId, [
            'code'   => '1110',
            'name'   => 'Front desk drawer',
            'contra' => '0',
        ]));

        $this->assertTrue($result['success']);
        $this->assertSame('Front desk drawer', (new AccountService())->find($accountId)->name);
    }

    public function testPostSave_KeepsTheDirectionOfAnAccountWithJournalLines(): void
    {
        $this->actAs($this->employeeWithGrants(['accounting_chart', AccountingGuard::CHART_MANAGE]));
        $cashId = $this->accountId('cash');
        $this->postedJournalBy();

        $result = $this->jsonOf($this->post('/accounting_chart/save/' . $cashId, [
            'code'   => (new AccountService())->find($cashId)->code,
            'name'   => 'Cash on hand',
            'contra' => '0',
        ]));

        $this->assertTrue($result['success']);
        $this->assertSame('debit', (new AccountService())->find($cashId)->normal_balance);
    }

    public function testPostDeactivate_DeactivatesAndPostActivateRestores(): void
    {
        $this->actAs($this->employeeWithGrants(['accounting_chart', AccountingGuard::CHART_MANAGE]));
        $accountId = $this->customAccount('11', '1110', 'Petty cash drawer');

        $deactivated = $this->jsonOf($this->post('/accounting_chart/deactivate/' . $accountId));
        $this->assertTrue($deactivated['success']);
        $this->assertSame('0', (string) (new AccountService())->find($accountId)->active);

        $activated = $this->jsonOf($this->post('/accounting_chart/activate/' . $accountId));
        $this->assertTrue($activated['success']);
        $this->assertSame('1', (string) (new AccountService())->find($accountId)->active);
    }

    public function testPostDelete_RejectsASystemAccount(): void
    {
        $this->actAs($this->employeeWithGrants(['accounting_chart', AccountingGuard::CHART_MANAGE]));

        $result = $this->jsonOf($this->post('/accounting_chart/delete/' . $this->accountId('cash')));

        $this->assertFalse($result['success']);
        $this->assertNotNull((new AccountService())->find($this->accountId('cash')));
    }

    public function testPostDelete_DeletesAnUnusedCustomAccount(): void
    {
        $this->actAs($this->employeeWithGrants(['accounting_chart', AccountingGuard::CHART_MANAGE]));
        $accountId = $this->customAccount('11', '1110', 'Petty cash drawer');

        $result = $this->jsonOf($this->post('/accounting_chart/delete/' . $accountId));

        $this->assertTrue($result['success']);
        $this->assertNull((new AccountService())->find($accountId));
    }

    public function testGetSuggestCode_ReturnsTheNextFreeCode(): void
    {
        $this->actAs($this->employeeWithGrants(['accounting_chart']));
        $this->customAccount('11', '1110', 'Petty cash drawer');

        $result = $this->jsonOf($this->get('/accounting_chart/suggest_code/' . $this->accountIdByCode('11')));

        $this->assertTrue($result['success']);
        $this->assertSame('1111', $result['code']);
    }

    public function testGetRow_ReturnsTheRowOfOneAccount(): void
    {
        $this->actAs($this->employeeWithGrants(['accounting_chart']));
        $accountId = $this->customAccount('11', '1110', 'Petty cash drawer');

        $row = $this->jsonOf($this->get('/accounting_chart/row/' . $accountId));

        $this->assertSame('1110', $row['code']);
        $this->assertSame($accountId, $row['account_id']);
    }

    /**
     * @param array<string, mixed> $result
     */
    private function balanceOf(array $result, int $accountId): string
    {
        foreach ($result['rows'] as $row) {
            if ((int) $row['account_id'] === $accountId) {
                return strip_tags((string) $row['balance']);
            }
        }

        $this->fail("The account {$accountId} is not in the list.");
    }

    private function postInBranch(int $branchId, string $amount): void
    {
        (new JournalService())->postNew($this->journalInput($amount) + ['branch_id' => $branchId]);
    }

    public function testGetSearch_CountsOnlyTheBranchesTheEmployeeMayUse(): void
    {
        $second = $this->createBranch('B2');
        $this->postInBranch(1, '100.00');
        $this->postInBranch($second, '40.00');

        $assets = $this->accountIdByCode('1');

        $this->actAs($this->employeeWithGrants(['accounting_chart'], [$second]));
        $limited = $this->jsonOf($this->get('/accounting_chart/search'));

        $this->actAs($this->employeeWithGrants(['accounting_chart']));
        $everything = $this->jsonOf($this->get('/accounting_chart/search'));

        $this->assertStringContainsString('40.00', $this->balanceOf($limited, $assets));
        $this->assertStringNotContainsString('140.00', $this->balanceOf($limited, $assets));
        $this->assertStringContainsString('140.00', $this->balanceOf($everything, $assets));
    }

    public function testGetIndex_HidesTheAddButtonFromSomeoneLimitedToBranches(): void
    {
        $this->actAs($this->employeeWithGrants(['accounting_chart', AccountingGuard::CHART_MANAGE], [1]));

        $this->get('/accounting_chart')->assertDontSee('accounting_chart/view?parent_id');
    }

    public function testGetIndex_ExplainsThatBalancesLeaveOutOtherBranches(): void
    {
        $this->createBranch('B2');
        $this->actAs($this->employeeWithGrants(['accounting_chart'], [1]));

        $this->get('/accounting_chart')->assertSee('acc-scope-note');
    }

    public function testGetIndex_HasNothingToExplainWhenThereIsOnlyOneBranch(): void
    {
        $this->actAs($this->employeeWithGrants(['accounting_chart'], [1]));

        $this->get('/accounting_chart')->assertDontSee('acc-scope-note');
    }

    public function testGetView_IsNotFoundForSomeoneLimitedToBranches(): void
    {
        $this->actAs($this->employeeWithGrants(['accounting_chart', AccountingGuard::CHART_MANAGE], [1]));

        $this->expectException(PageNotFoundException::class);

        $this->get('/accounting_chart/view');
    }

    public function testPostSave_RefusesSomeoneLimitedToBranches(): void
    {
        $this->actAs($this->employeeWithGrants(['accounting_chart', AccountingGuard::CHART_MANAGE], [1]));

        $result = $this->jsonOf($this->post('/accounting_chart/save', ['parent_id' => (string) $this->accountIdByCode('11'), 'code' => '1110', 'name' => 'Custom']));

        $this->assertFalse($result['success']);
        $this->assertSame(0, $this->accountingDb()->table('accounting_accounts')->where('code', '1110')->countAllResults());
    }
}
