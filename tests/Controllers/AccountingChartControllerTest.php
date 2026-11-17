<?php

namespace Tests\Controllers;

use App\Libraries\Accounting\AccountingGuard;
use App\Libraries\Accounting\AccountService;
use App\Libraries\Accounting\EntityAccountService;
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

        $response->assertDontSee('data-href="accounting_chart/view');
        $response->assertDontSee('acc-hints');
    }

    public function testGetIndex_OffersTheAddButtonWithTheManagePermission(): void
    {
        $this->actAs($this->employeeWithGrants(['accounting_chart', AccountingGuard::CHART_MANAGE]));

        $response = $this->get('/accounting_chart');

        $response->assertSee('data-href="accounting_chart/view?main=1"');
        $response->assertSee('acc-hints');
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

        $this->get('/accounting_chart')->assertDontSee('data-href="accounting_chart/view');
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

    /**
     * @return array{group: int, ids: list<int>}
     */
    private function customerAccounts(int $count, string $name = 'Lazy customer'): array
    {
        $service = new EntityAccountService();
        $ids     = [];

        for ($number = 1; $number <= $count; $number++) {
            $ids[] = $service->ensure(EntityAccountService::CUSTOMER, 900000 + $number, "{$name} {$number}");
        }

        return ['group' => (int) (new AccountService())->find($ids[0])->parent_id, 'ids' => $ids];
    }

    private function manager(): void
    {
        $this->actAs($this->employeeWithGrants(['accounting_chart', AccountingGuard::CHART_MANAGE]));
    }

    public function testGetIndex_DrawsTheStructureAndLeavesTheAccountsOfEntitiesToBeLoadedOnDemand(): void
    {
        $this->actAs($this->employeeWithGrants(['accounting_chart']));
        $this->customAccount('11', '1110', 'Petty cash drawer');
        $customers = $this->customerAccounts(3);

        $html = $this->get('/accounting_chart')->getBody();

        $this->assertStringContainsString('Petty cash drawer', $html);
        $this->assertStringNotContainsString('Lazy customer 1', $html);
        $this->assertStringContainsString('accounting_chart/branch/' . $customers['group'] . '?offset=0', $html);
        $this->assertStringContainsString(lang('Accounting_chart.show_linked', [3]), $html);
    }

    public function testGetIndex_OpensThePathToTheAccountOfTheLink(): void
    {
        $this->actAs($this->employeeWithGrants(['accounting_chart']));
        $childId = $this->customAccount('11', '1110', 'Petty cash drawer');

        $html = $this->get('/accounting_chart?parent=' . $childId)->getBody();

        $this->assertMatchesRegularExpression('~data-account-id="' . $this->accountIdByCode('1') . '".*?aria-expanded="true"~s', $html);
        $this->assertMatchesRegularExpression('~<div class="acc-row is-selected" aria-current="true">(?:(?!</li>).)*Petty cash drawer~s', $html);
    }

    public function testGetIndex_OpensTheGroupOfAnEntityAccountTheLinkNames(): void
    {
        $this->actAs($this->employeeWithGrants(['accounting_chart']));
        $customers = $this->customerAccounts(2);

        $html = $this->get('/accounting_chart?parent=' . $customers['ids'][0])->getBody();

        $this->assertMatchesRegularExpression('~data-account-id="' . $customers['group'] . '"[^>]*>\s*<div class="acc-row[^"]*">\s*<button[^>]*aria-expanded="true"~s', $html);
        $this->assertStringNotContainsString('Lazy customer 1', $html);
    }

    public function testGetNode_DrawsTheAccountWithItsBranchClosedUnlessAskedToKeepItOpen(): void
    {
        $this->actAs($this->employeeWithGrants(['accounting_chart']));
        $this->customAccount('11', '1110', 'Petty cash drawer');
        $assets = $this->accountIdByCode('1');

        $closed = $this->get('/accounting_chart/node/' . $assets)->getBody();
        $open   = $this->get('/accounting_chart/node/' . $assets . '?open=' . $assets)->getBody();

        $this->assertStringContainsString('<ul class="acc-children" hidden>', $closed);
        $this->assertStringContainsString('<ul class="acc-children">', $open);
        $this->assertStringContainsString('Petty cash drawer', $open);
    }

    public function testGetNode_MarksTheAccountItWasAskedToHighlight(): void
    {
        $this->actAs($this->employeeWithGrants(['accounting_chart']));
        $childId = $this->customAccount('11', '1110', 'Petty cash drawer');

        $html = $this->get('/accounting_chart/node/' . $this->accountIdByCode('11') . '?highlight=' . $childId)->getBody();

        $this->assertMatchesRegularExpression('~<li class="acc-node is-new" data-account-id="' . $childId . '"~', $html);
        $this->assertMatchesRegularExpression('~data-account-id="' . $this->accountIdByCode('11') . '"[^>]*>\s*<div class="acc-row[^"]*">\s*<button[^>]*aria-expanded="true"~s', $html);
    }

    public function testGetNode_IsNotFoundForAnUnknownAccount(): void
    {
        $this->actAs($this->employeeWithGrants(['accounting_chart']));

        $this->expectException(PageNotFoundException::class);

        $this->get('/accounting_chart/node/999999');
    }

    public function testGetBranch_ListsAPageOfTheAccountsOfEntitiesAndOffersTheRest(): void
    {
        $this->actAs($this->employeeWithGrants(['accounting_chart']));
        $customers = $this->customerAccounts(105);
        $group     = $customers['group'];

        $first  = $this->get("/accounting_chart/branch/{$group}?offset=0")->getBody();
        $second = $this->get("/accounting_chart/branch/{$group}?offset=100")->getBody();

        $this->assertSame(100, substr_count($first, '<li class="acc-node'));
        $this->assertStringContainsString("accounting_chart/branch/{$group}?offset=100", $first);
        $this->assertStringContainsString(lang('Accounting_chart.show_more', [5]), $first);
        $this->assertSame(5, substr_count($second, '<li class="acc-node'));
        $this->assertStringNotContainsString('acc-load', $second);
    }

    public function testGetBranch_ListsNoAccountThatBelongsToNoEntity(): void
    {
        $this->actAs($this->employeeWithGrants(['accounting_chart']));
        $customers = $this->customerAccounts(2);
        $this->customAccount($this->codeOf($customers['group']), $this->nextFreeCode($customers['group']), 'Manual account under customers');

        $html = $this->get('/accounting_chart/branch/' . $customers['group'])->getBody();

        $this->assertStringContainsString('Lazy customer 1', $html);
        $this->assertStringNotContainsString('Manual account under customers', $html);
    }

    public function testGetBranch_ShowsTheBalanceOfEachAccountOfAnEntity(): void
    {
        $this->actAs($this->employeeWithGrants(['accounting_chart']));
        $customers = $this->customerAccounts(1);

        $html = $this->get('/accounting_chart/branch/' . $customers['group'])->getBody();

        $this->assertStringContainsString('class="acc-balance"', $html);
        $this->assertStringContainsString('Lazy customer 1', $html);
    }

    public function testGetBranch_IsNotFoundForAnUnknownAccount(): void
    {
        $this->actAs($this->employeeWithGrants(['accounting_chart']));

        $this->expectException(PageNotFoundException::class);

        $this->get('/accounting_chart/branch/999999');
    }

    public function testGetFind_FindsTheAccountOfAnEntityWithItsParentAndEscapesTheName(): void
    {
        $this->actAs($this->employeeWithGrants(['accounting_chart']));
        $customers = $this->customerAccounts(1, '<b>Bold</b> customer');

        $html = $this->get('/accounting_chart/find?search=customer')->response()->getBody();

        $this->assertStringContainsString('&lt;b&gt;Bold&lt;/b&gt; customer 1', $html);
        $this->assertStringNotContainsString('<b>Bold', $html);
        $this->assertStringContainsString('class="acc-parent"', $html);
        $this->assertStringNotContainsString('class="acc-caret"', $html);
        $this->assertSame(1, $this->accountingDb()->table('accounting_accounts')->where('account_id', $customers['ids'][0])->countAllResults());
    }

    public function testGetFind_SaysSoWhenNothingMatches(): void
    {
        $this->actAs($this->employeeWithGrants(['accounting_chart']));

        $html = $this->get('/accounting_chart/find?search=zzzzzz')->getBody();

        $this->assertStringContainsString(lang('Accounting_chart.no_results'), $html);
        $this->assertStringNotContainsString('acc-results', $html);
    }

    public function testGetFind_ListsNothingForAnEmptySearch(): void
    {
        $this->actAs($this->employeeWithGrants(['accounting_chart']));

        $this->assertStringContainsString(lang('Accounting_chart.no_results'), $this->get('/accounting_chart/find?search=')->getBody());
    }

    public function testGetSearch_ListsEveryAccountOfTheStructureFlatAndNoAccountOfAnEntity(): void
    {
        $this->actAs($this->employeeWithGrants(['accounting_chart']));
        $this->customAccount('11', '1110', 'Petty cash drawer');
        $this->customerAccounts(2);

        $result = $this->jsonOf($this->get('/accounting_chart/search?flat=1&limit=500'));
        $names  = strip_tags(implode(' ', array_column($result['rows'], 'name')));

        $this->assertContains('1', array_column($result['rows'], 'code'));
        $this->assertContains('1110', array_column($result['rows'], 'code'));
        $this->assertStringContainsString('Petty cash drawer', $names);
        $this->assertStringNotContainsString('Lazy customer', $names);
        $this->assertSame(
            $this->accountingDb()->table('accounting_accounts')->where('entity_type IS NULL')->countAllResults(),
            $result['total'],
        );
    }

    public function testGetSearch_KeepsListingOnlyTheRootsWithoutTheFlatOption(): void
    {
        $this->actAs($this->employeeWithGrants(['accounting_chart']));
        $this->customAccount('11', '1110', 'Petty cash drawer');

        $codes = array_column($this->jsonOf($this->get('/accounting_chart/search'))['rows'], 'code');

        $this->assertContains('1', $codes);
        $this->assertNotContains('1110', $codes);
    }

    public function testGetSearch_OffersTheAccountStatementOnlyToWhoHoldsTheReportsModule(): void
    {
        $accountId = $this->customAccount('11', '1110', 'Petty cash drawer');

        $this->actAs($this->employeeWithGrants(['accounting_chart']));
        $without = $this->jsonOf($this->get('/accounting_chart/search?search=drawer'));

        $this->actAs($this->employeeWithGrants(['accounting_chart', 'accounting_reports']));
        $with = $this->jsonOf($this->get('/accounting_chart/search?search=drawer'));

        $this->assertStringNotContainsString('accounting_reports/ledger', html_entity_decode($without['rows'][0]['edit']));
        $this->assertStringContainsString('accounting_reports/ledger?account=' . $accountId, html_entity_decode($with['rows'][0]['edit']));
    }

    public function testGetIndex_OffersTheAccountStatementOnEveryRowToWhoHoldsTheReportsModule(): void
    {
        $this->actAs($this->employeeWithGrants(['accounting_chart', 'accounting_reports']));

        $html = $this->get('/accounting_chart')->getBody();

        $this->assertStringContainsString('accounting_reports/ledger?account=' . $this->accountIdByCode('1'), $html);

        $this->actAs($this->employeeWithGrants(['accounting_chart']));

        $this->assertStringNotContainsString('accounting_reports/ledger', $this->get('/accounting_chart')->getBody());
    }

    public function testGetIndex_OffersAddSubAccountOnlyOnAnActiveGroupToWhoManagesTheChart(): void
    {
        $this->manager();
        $group   = $this->customAccount('11', '1110', 'Drawers', true);
        $posting = $this->customAccount('11', '1111', 'Petty cash drawer');
        $closed  = $this->customAccount('11', '1112', 'Closed drawers', true);
        (new AccountService())->deactivate($closed);

        $html = $this->get('/accounting_chart')->getBody();

        $this->assertStringContainsString("accounting_chart/view?parent_id={$group}&amp;lock_parent=1", $html);
        $this->assertStringNotContainsString("accounting_chart/view?parent_id={$posting}&amp;lock_parent=1", $html);
        $this->assertStringNotContainsString("accounting_chart/view?parent_id={$closed}&amp;lock_parent=1", $html);

        $this->actAs($this->employeeWithGrants(['accounting_chart']));

        $this->assertStringNotContainsString('lock_parent=1', $this->get('/accounting_chart')->getBody());
    }

    public function testGetIndex_OffersNoAddSubAccountToSomeoneLimitedToBranches(): void
    {
        $this->actAs($this->employeeWithGrants(['accounting_chart', AccountingGuard::CHART_MANAGE], [1]));

        $this->assertStringNotContainsString('lock_parent=1', $this->get('/accounting_chart')->getBody());
    }

    public function testGetSearch_OffersNeitherDeactivateNorDeleteForAnAccountAnExpenseCategoryPointsAt(): void
    {
        $this->manager();
        $accountId = $this->customAccount('11', '1110', 'Petty cash drawer');
        $this->accountingDb()->table('expense_categories')->insert(['category_name' => 'Drawer costs ' . uniqid(), 'category_description' => '', 'deleted' => 0, 'account_id' => $accountId]);

        $edit = html_entity_decode($this->jsonOf($this->get('/accounting_chart/search?search=drawer'))['rows'][0]['edit']);

        $this->assertStringNotContainsString('data-acc-action="deactivate"', $edit);
        $this->assertStringNotContainsString('data-acc-action="delete"', $edit);
        $this->assertStringContainsString('accounting_chart/view/' . $accountId, $edit);
    }

    public function testGetView_OffersOnlyTheMainAccountsAsParentsForANewMainAccount(): void
    {
        $this->manager();

        $html = $this->get('/accounting_chart/view?main=1')->getBody();

        $this->assertStringContainsString('name="is_group" value="1"', $html);
        $this->assertStringNotContainsString('type="radio"', $html);
        $this->assertStringContainsString('value="' . $this->accountIdByCode('1') . '"', $html);
        $this->assertStringNotContainsString('value="' . $this->accountIdByCode('11') . '"', $html);
    }

    public function testGetView_FixesTheParentOfANewSubAccountAndSuggestsItsCode(): void
    {
        $this->manager();
        $parent = $this->accountIdByCode('11');

        $html = $this->get("/accounting_chart/view?parent_id={$parent}&lock_parent=1")->getBody();

        $this->assertStringContainsString('<input type="hidden" name="parent_id" id="parent_id" value="' . $parent . '"', $html);
        $this->assertStringNotContainsString('<select name="parent_id"', $html);
        $this->assertStringContainsString('name="is_group" value="0" checked', $html);
        $this->assertStringContainsString('data-suggested="' . (new AccountService())->suggestCode($parent) . '"', $html);
    }

    public function testGetView_IgnoresTheFixedParentWhenItIsNotAnActiveGroup(): void
    {
        $this->manager();
        $posting = $this->customAccount('11', '1110', 'Petty cash drawer');

        $html = $this->get("/accounting_chart/view?parent_id={$posting}&lock_parent=1")->getBody();

        $this->assertStringContainsString('<select name="parent_id"', $html);
    }

    public function testGetView_RefusesTheMainAccountFormToSomeoneLimitedToBranches(): void
    {
        $this->actAs($this->employeeWithGrants(['accounting_chart', AccountingGuard::CHART_MANAGE], [1]));

        $this->expectException(PageNotFoundException::class);

        $this->get('/accounting_chart/view?main=1');
    }

    public function testPostSave_CreatesASubAccountUnderTheFixedParentOnlyWithTheManagePermission(): void
    {
        $parent = $this->accountIdByCode('11');

        $this->actAs($this->employeeWithGrants(['accounting_chart']));
        $refused = $this->jsonOf($this->post('/accounting_chart/save/-1', ['parent_id' => (string) $parent, 'code' => '1110', 'name' => 'Drawer', 'is_group' => '0']));

        $this->manager();
        $created = $this->jsonOf($this->post('/accounting_chart/save/-1', ['parent_id' => (string) $parent, 'code' => '1110', 'name' => 'Drawer', 'is_group' => '0']));

        $this->assertFalse($refused['success']);
        $this->assertTrue($created['success']);
        $this->assertSame($parent, (int) (new AccountService())->find($created['id'])->parent_id);
    }

    private function codeOf(int $accountId): string
    {
        return (string) (new AccountService())->find($accountId)->code;
    }

    private function nextFreeCode(int $parentId): string
    {
        return (new AccountService())->suggestCode($parentId);
    }
}
