<?php

namespace Tests\Controllers;

use App\Libraries\Accounting\AccountingGuard;
use App\Libraries\Assets\AssetDisposals;
use CodeIgniter\Exceptions\PageNotFoundException;
use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\DatabaseTestTrait;
use Tests\Support\AccountingControllerFixtureTrait;
use Tests\Support\AssetFixtureTrait;
use Tests\Support\CsvResponseTrait;

/**
 * The screens of fixed assets: who may open them, what they list and show, that every change is a POST
 * the books may refuse in words, the preview and the approval of the depreciation, the disposals, the
 * categories and the report with its file.
 *
 * @internal
 */
final class AccountingAssetsControllerTest extends CIUnitTestCase
{
    use DatabaseTestTrait;
    use AccountingControllerFixtureTrait;
    use AssetFixtureTrait;
    use CsvResponseTrait;

    protected $migrate     = true;
    protected $migrateOnce = true;
    protected $refresh     = false;
    protected $namespace;
    private int $second;

    protected function setUp(): void
    {
        parent::setUp();

        $this->clearAssets();
        $this->prepareAccounting();
        $this->startAssets();
        $this->second = $this->createBranch('B2', 'Second branch');
    }

    protected function tearDown(): void
    {
        $this->releaseOpenTransactions();
        $this->clearAssets();
        $this->clearJournals();

        parent::tearDown();
    }

    /**
     * @param list<string>   $permissions
     * @param list<int>|null $branchIds
     */
    private function actAsWith(array $permissions, ?array $branchIds = null): int
    {
        $personId = $this->employeeWithGrants($permissions, $branchIds);

        $this->actAs($personId);

        return $personId;
    }

    private function manager(?array $branchIds = null): int
    {
        return $this->actAsWith(['accounting_assets', AccountingGuard::ASSETS_MANAGE, AccountingGuard::TREASURIES_MANAGE], $branchIds);
    }

    private function runner(?array $branchIds = null): int
    {
        return $this->actAsWith(['accounting_assets', AccountingGuard::ASSETS_DEPRECIATE], $branchIds);
    }

    /**
     * @return array<string, mixed>
     */
    private function postJson(string $url, array $data = []): array
    {
        return $this->jsonOf($this->post($url, $data));
    }

    private function fingerprint(string $month = '2026-02', ?int $branchId = 1): string
    {
        return $this->depreciation->preview($branchId, $month, $this->assetRunner)->fingerprint;
    }

    private function runMonth(string $month = '2026-02', int $branchId = 1): int
    {
        return $this->depreciation->approve($branchId, $month, $this->fingerprint($month, $branchId), $this->assetRunner)[0];
    }

    public function testGetIndex_IsNotFoundWhileAccountingIsOff(): void
    {
        $this->actAsWith(['accounting_assets']);
        $this->setConfigValue('accounting_enabled', '0');

        $this->expectException(PageNotFoundException::class);

        $this->get('/accounting_assets');
    }

    public function testGetIndex_RedirectsAUserWithoutTheModuleGrant(): void
    {
        $this->actAsWith(['accounting_cheques']);

        $this->get('/accounting_assets')->assertRedirect();
    }

    public function testGetIndex_ListsTheAssetsWithTheirFiguresAndSaysTheRegisterTiesToTheBooks(): void
    {
        $assetId = $this->enterAsset(['code' => 'DESK']);
        $this->runMonth();
        $this->actAsWith(['accounting_assets']);

        $response = $this->get('/accounting_assets?as_of=2026-02-28');

        $response->assertStatus(200);
        $response->assertSee('data-asset="' . $assetId . '"');
        $response->assertSee('DESK');
        $response->assertSee('Office desk');
        $response->assertSee('12,000.00');
        $response->assertSee('1,000.00');
        $response->assertSee('11,000.00');
        $response->assertSee('id="assets_tie"');
        $response->assertSee(lang('Accounting_assets.tie_ok'));
        $response->assertSee('accounting_assets/view/' . $assetId);
        $response->assertSee('id="assets_csv"');
    }

    public function testGetIndex_OffersTheNewAssetOnlyToWhoMayChangeTheRegister(): void
    {
        $this->actAsWith(['accounting_assets']);

        $this->get('/accounting_assets')->assertDontSee('id="new_asset"');

        $this->manager();

        $this->get('/accounting_assets')->assertSee('id="new_asset"');
    }

    public function testGetIndex_SaysTheRegisterDiffersWhenTheBooksDo(): void
    {
        $this->setConfigValue('accounting_start_date', '2026-01-01');
        $this->enterAsset(['code' => 'OLD', 'funding' => 'none', 'treasury_id' => '']);
        $this->actAsWith(['accounting_assets']);

        $response = $this->get('/accounting_assets?as_of=2026-03-31');

        $response->assertSee(lang('Accounting_assets.tie_off'));
        $response->assertSee('table-danger');
    }

    public function testGetIndex_AppliesTheFiltersAndTheGrouping(): void
    {
        $this->enterAsset(['code' => 'A-1']);
        $idle = $this->enterAsset(['code' => 'A-2', 'name' => 'Idle press']);
        $this->register->setActive($idle, false, $this->assetClerk);
        $this->actAsWith(['accounting_assets']);

        $only = $this->get('/accounting_assets?status[]=inactive');

        $only->assertSee('Idle press');
        $only->assertDontSee('Office desk');

        $grouped = $this->get('/accounting_assets?group=status');

        $grouped->assertSee('id="assets_group_active"');
        $grouped->assertSee('id="assets_group_inactive"');
        $grouped->assertSee(lang('Accounting_assets.group_total'));

        $this->get('/accounting_assets?group=nonsense')->assertSee('id="assets_group_all"');
    }

    public function testGetIndex_DefaultsTheDepreciationDatesToTheLastMonth(): void
    {
        $this->actAsWith(['accounting_assets']);

        $response = $this->get('/accounting_assets');

        $response->assertSee('name="depreciation_from"');
        $response->assertSee('value="' . date('Y-m-01', strtotime('first day of last month')) . '"');
        $response->assertSee('value="' . date('Y-m-t', strtotime('last day of last month')) . '"');
    }

    public function testGetIndex_ShowsTheAssetsOfTheBranchesOfTheReaderOnly(): void
    {
        $this->enterAsset(['code' => 'MINE']);
        $this->enterAsset(['code' => 'THEIRS', 'name' => 'Other chair', 'branch_id' => $this->second, 'funding' => 'payable', 'treasury_id' => '']);
        $this->actAsWith(['accounting_assets'], [1]);

        $response = $this->get('/accounting_assets');

        $response->assertSee('MINE');
        $response->assertDontSee('THEIRS');
    }

    public function testGetCsv_GivesTheReportAsAFileWithTheTotalsAndTheTie(): void
    {
        $this->enterAsset(['code' => 'DESK', 'name' => '=SUM(1)']);
        $this->manager();

        $response = $this->get('/accounting_assets/csv?as_of=2026-03-31');

        $rows = $this->csvRows($response);

        $this->assertSame([lang('Accounting_assets.title')], $rows[0]);
        $this->assertSame([lang('Accounting_assets.as_of'), '2026-03-31'], $rows[1]);

        $asset = array_values(array_filter($rows, static fn (array $row): bool => ($row[0] ?? '') === 'DESK'))[0];

        $this->assertSame("'=SUM(1)", $asset[1], 'a name that a spreadsheet would read as a formula is kept as text');
        $this->assertSame('12000.0000', $asset[7]);
        $this->assertContains(lang('Accounting_assets.total'), array_column($rows, 0));
        $this->assertContains(lang('Accounting_assets.tie_cost'), array_column($rows, 0));
        $this->assertStringContainsString('attachment; filename="fixed_assets_2026-03-31.csv"', $response->response()->getHeaderLine('Content-Disposition'));
    }

    public function testGetCsv_IsLimitedToTheBranchesOfTheReader(): void
    {
        $this->enterAsset(['code' => 'MINE']);
        $this->enterAsset(['code' => 'THEIRS', 'name' => 'Other chair', 'branch_id' => $this->second, 'funding' => 'payable', 'treasury_id' => '']);
        $this->actAsWith(['accounting_assets'], [1]);

        $codes = array_column($this->csvRows($this->get('/accounting_assets/csv')), 0);

        $this->assertContains('MINE', $codes);
        $this->assertNotContains('THEIRS', $codes);
    }

    public function testGetForm_ShowsTheFieldsOfTheFourMethodsAndTheNextCode(): void
    {
        $this->manager();

        $response = $this->get('/accounting_assets/form');

        $response->assertStatus(200);
        $response->assertSee('action="' . base_url('accounting_assets/save') . '"');
        $response->assertSee('placeholder="000001"');

        foreach (['name', 'cost', 'purchase_date', 'in_service_date', 'method', 'funding', 'amount_per_period', 'percentage', 'cost_per_unit', 'total_units', 'first_depreciation_date', 'end_date', 'salvage', 'accumulated_account_id', 'expense_account_id', 'track_units', 'quantity'] as $field) {
            $response->assertSee('name="' . $field . '"');
        }

        foreach (['fixed', 'declining', 'units', 'none'] as $method) {
            $response->assertSee('value="' . $method . '"');
        }
    }

    public function testGetForm_ShowsTheValuesOfTheAssetBeingEdited(): void
    {
        $assetId = $this->enterAsset(['code' => 'DESK', 'units' => [['serial_number' => 'S-77']]]);
        $this->manager();

        $response = $this->get('/accounting_assets/form/' . $assetId);

        $response->assertSee('action="' . base_url('accounting_assets/save/' . $assetId) . '"');
        $response->assertSee('value="Office desk"');
        $response->assertSee('value="12000"');
        $response->assertSee('S-77');
        $response->assertDontSee('asset_locked_note');

        $this->runMonth();

        $this->get('/accounting_assets/form/' . $assetId)->assertSee('asset_locked_note');
    }

    public function testGetForm_IsNotFoundWithoutThePermissionToChangeTheRegisterOrForAnAssetOfAnotherBranch(): void
    {
        $assetId = $this->enterAsset(['branch_id' => $this->second, 'funding' => 'payable', 'treasury_id' => '']);
        $this->actAsWith(['accounting_assets']);

        try {
            $this->get('/accounting_assets/form');
            $this->fail('The register is not theirs to change.');
        } catch (PageNotFoundException) {
            $this->assertTrue(true);
        }

        $this->manager([1]);

        $this->expectException(PageNotFoundException::class);

        $this->get('/accounting_assets/form/' . $assetId);
    }

    public function testPostSave_EntersAnAssetAndEditsIt(): void
    {
        $this->manager();

        $added = $this->postJson('/accounting_assets/save', $this->assetInput(['code' => 'DESK', 'units' => [['serial_number' => 'S-1', 'used_by' => 'not_assigned']]]));

        $this->assertTrue($added['success'], $added['message']);
        $this->assertSame(lang('Accounting_assets.saved'), $added['message']);
        $this->assertSame(base_url('accounting_assets/view/' . $added['id']), $added['redirect']);
        $this->assertSame('12000.0000', $this->accountingDb()->table('assets')->where('asset_id', $added['id'])->get()->getRow()->cost);
        $this->assertSame('12000.0000', $this->accountNet($this->accountId('fixed_assets')));
        $this->assertSame('S-1', $this->register->find((int) $added['id'])->units[0]->serial_number);

        $changed = $this->postJson('/accounting_assets/save/' . $added['id'], $this->assetInput(['code' => 'DESK', 'name' => 'Renamed desk', 'cost' => '15000']));

        $this->assertTrue($changed['success'], $changed['message']);
        $this->assertSame('Renamed desk', $this->accountingDb()->table('assets')->where('asset_id', $added['id'])->get()->getRow()->name);
        $this->assertSame('15000.0000', $this->accountNet($this->accountId('fixed_assets')));
    }

    public function testPostSave_SaysInWordsWhatTheBooksRefuse(): void
    {
        $this->manager();

        $invalid = $this->postJson('/accounting_assets/save', $this->assetInput(['cost' => '0']));

        $this->assertFalse($invalid['success']);
        $this->assertSame(lang('Accounting.error_asset_field_invalid', ['cost']), $invalid['message']);

        $this->closeThrough('2026-01-31');

        $closed = $this->postJson('/accounting_assets/save', $this->assetInput());
        $company = (string) $this->accountingDb()->table('companies')->select('name')->where('company_id', 1)->get()->getRow()->name;

        $this->assertFalse($closed['success']);
        $this->assertSame(lang('Accounting.posting_period_closed', ['2026-01-01', '2026-01-31', $company]), $closed['message']);
        $this->assertSame(0, $this->countRows('assets'));
    }

    public function testPostSave_RejectsAnEmployeeWithoutThePermissionToChangeTheRegister(): void
    {
        $this->actAsWith(['accounting_assets']);

        $denied = $this->postJson('/accounting_assets/save', $this->assetInput());

        $this->assertFalse($denied['success']);
        $this->assertSame(lang('Accounting.error_permission_denied'), $denied['message']);
        $this->assertSame(0, $this->countRows('assets'));
    }

    public function testPostSave_RejectsAnAssetOfAnotherBranch(): void
    {
        $assetId = $this->enterAsset(['branch_id' => $this->second, 'funding' => 'payable', 'treasury_id' => '']);
        $this->manager([1]);

        $this->expectException(PageNotFoundException::class);

        $this->post('/accounting_assets/save/' . $assetId, $this->assetInput(['name' => 'Renamed']));
    }

    public function testPostDelete_AndPostStatus_ChangeTheAssetAsTheServiceDoes(): void
    {
        $assetId = $this->enterAsset();
        $this->manager();

        $off = $this->postJson('/accounting_assets/status/' . $assetId . '/0');

        $this->assertTrue($off['success']);
        $this->assertSame('inactive', $this->accountingDb()->table('assets')->where('asset_id', $assetId)->get()->getRow()->status);

        $on = $this->postJson('/accounting_assets/status/' . $assetId . '/1');

        $this->assertSame('active', $this->accountingDb()->table('assets')->where('asset_id', $assetId)->get()->getRow()->status);
        $this->assertTrue($on['success']);

        $deleted = $this->postJson('/accounting_assets/delete/' . $assetId);

        $this->assertTrue($deleted['success']);
        $this->assertSame('0.0000', $this->accountNet($this->accountId('fixed_assets')));
    }

    public function testPostDelete_SaysInWordsWhyAnAssetADepreciationStandsOnIsKept(): void
    {
        $assetId = $this->enterAsset();
        $this->runMonth();
        $this->manager();

        $refused = $this->postJson('/accounting_assets/delete/' . $assetId);

        $this->assertFalse($refused['success']);
        $this->assertStringContainsString($this->accountingDb()->table('assets')->where('asset_id', $assetId)->get()->getRow()->code, $refused['message']);
        $this->assertSame(0, (int) $this->accountingDb()->table('assets')->where('asset_id', $assetId)->get()->getRow()->deleted);
    }

    public function testGetDetail_ShowsTheTermsTheScheduleTheHistoryAndTheUnits(): void
    {
        $assetId = $this->enterAsset(['code' => 'DESK', 'units' => [['serial_number' => 'S-9', 'barcode' => 'B-9']]]);
        $this->runMonth();
        $this->manager();

        $response = $this->get('/accounting_assets/view/' . $assetId);

        $response->assertStatus(200);
        $response->assertSee('DESK');
        $response->assertSee('id="asset_terms"');
        $response->assertSee('id="asset_schedule"');
        $response->assertSee('id="asset_history"');
        $response->assertSee('id="asset_units_list"');
        $response->assertSee('S-9');
        $response->assertSee(lang('Accounting_assets.schedule_posted'));
        $response->assertSee(lang('Accounting_assets.schedule_due'));
        $response->assertSee('id="edit_asset"');
        $response->assertSee('accounting_assets/dispose_form/sale/' . $assetId);
        $response->assertSee('accounting_assets/dispose_form/write_off/' . $assetId);
    }

    public function testGetDetail_OffersNoChangeToWhoMayOnlyRead(): void
    {
        $assetId = $this->enterAsset();
        $this->actAsWith(['accounting_assets']);

        $response = $this->get('/accounting_assets/view/' . $assetId);

        $response->assertStatus(200);
        $response->assertDontSee('id="edit_asset"');
        $response->assertDontSee('dispose_form');
        $response->assertDontSee('id="delete_asset"');
    }

    public function testGetDetail_IsNotFoundForAnAssetOfAnotherBranchAndForADeletedOne(): void
    {
        $other   = $this->enterAsset(['branch_id' => $this->second, 'funding' => 'payable', 'treasury_id' => '']);
        $deleted = $this->enterAsset(['name' => 'Gone']);
        $this->register->delete($deleted, $this->assetClerk);
        $this->actAsWith(['accounting_assets'], [1]);

        foreach ([$other, $deleted] as $assetId) {
            try {
                $this->get('/accounting_assets/view/' . $assetId);
                $this->fail('Not visible.');
            } catch (PageNotFoundException) {
                $this->assertTrue(true);
            }
        }
    }

    public function testPostDispose_SellsAnAssetAndWritesOneOffAndSaysWhyItRefuses(): void
    {
        $sold = $this->enterAsset(['code' => 'A-1']);
        $gone = $this->enterAsset(['code' => 'A-2', 'name' => 'Lamp']);
        $this->manager();

        $form = $this->get('/accounting_assets/dispose_form/sale/' . $sold);

        $form->assertSee('name="proceeds"');
        $form->assertSee('name="treasury_id"');

        $pending = $this->postJson('/accounting_assets/dispose/sale/' . $sold, ['date' => '2026-04-10', 'proceeds' => '9000', 'treasury_id' => $this->assetTreasury]);

        $this->assertFalse($pending['success']);
        $this->assertSame(lang('Accounting.error_asset_depreciation_pending', ['2026-02, 2026-03']), $pending['message']);

        $credit = $this->postJson('/accounting_assets/dispose/sale/' . $sold, ['date' => '2026-01-20', 'proceeds' => '9000', 'treasury_id' => $this->assetTreasury, 'customer_id' => $this->insertCustomer()]);

        $this->assertSame(lang('Accounting.error_asset_sale_on_credit'), $credit['message']);

        $sale = $this->postJson('/accounting_assets/dispose/sale/' . $sold, ['date' => '2026-01-20', 'proceeds' => '9000', 'treasury_id' => $this->assetTreasury]);
        $off  = $this->postJson('/accounting_assets/dispose/write_off/' . $gone, ['date' => '2026-01-20', 'note' => 'Broken']);

        $this->assertTrue($sale['success'], $sale['message']);
        $this->assertTrue($off['success'], $off['message']);
        $this->assertSame(['sold', 'written_off'], [$this->accountingDb()->table('assets')->where('asset_id', $sold)->get()->getRow()->status, $this->accountingDb()->table('assets')->where('asset_id', $gone)->get()->getRow()->status]);
    }

    public function testPostDispose_RejectsAnEmployeeWithoutThePermissionAndAKindThatIsNotOne(): void
    {
        $assetId = $this->enterAsset();
        $this->actAsWith(['accounting_assets']);

        $denied = $this->postJson('/accounting_assets/dispose/write_off/' . $assetId, ['date' => '2026-01-20']);

        $this->assertSame(lang('Accounting.error_permission_denied'), $denied['message']);

        $this->manager();

        $this->expectException(PageNotFoundException::class);

        $this->post('/accounting_assets/dispose/barter/' . $assetId, ['date' => '2026-01-20']);
    }

    public function testGetDisposeForm_IsNotFoundForAnAssetThatWasDisposedOfAndForAKindThatIsNotOne(): void
    {
        $assetId = $this->enterAsset();
        (new AssetDisposals())->writeOff($assetId, ['date' => '2026-01-20'], $this->assetClerk);
        $this->manager();

        foreach (['/accounting_assets/dispose_form/sale/' . $assetId, '/accounting_assets/dispose_form/barter/' . $assetId] as $url) {
            try {
                $this->get($url);
                $this->fail('No form.');
            } catch (PageNotFoundException) {
                $this->assertTrue(true);
            }
        }
    }

    public function testPostDisposeReverse_TakesTheDisposalBack(): void
    {
        $assetId     = $this->enterAsset();
        $operationId = (new AssetDisposals())->writeOff($assetId, ['date' => '2026-01-20'], $this->assetClerk);
        $this->manager();

        $this->get('/accounting_assets/dispose_reverse_form/' . $operationId)->assertSee('name="reason"');

        $reversed = $this->postJson('/accounting_assets/dispose_reverse/' . $operationId, ['reason' => 'Mistake']);

        $this->assertTrue($reversed['success'], $reversed['message']);
        $this->assertSame('active', $this->accountingDb()->table('assets')->where('asset_id', $assetId)->get()->getRow()->status);

        $again = $this->postJson('/accounting_assets/dispose_reverse/' . $operationId, ['reason' => 'Twice']);

        $this->assertFalse($again['success']);
        $this->assertSame(lang('Accounting.error_asset_operation_reversed'), $again['message']);
    }

    public function testCategories_AreListedAddedChangedSwitchedOffAndDeleted(): void
    {
        $this->manager();

        $this->get('/accounting_assets/categories')->assertSee('id="categories_empty"');
        $this->get('/accounting_assets/category_form')->assertSee('name="cost_account_id"');

        $added = $this->postJson('/accounting_assets/save_category', ['name' => 'Furniture', 'method' => 'fixed', 'life_months' => '60', 'cost_account_id' => $this->accountId('fixed_assets')]);

        $this->assertTrue($added['success'], $added['message']);

        $page = $this->get('/accounting_assets/categories');

        $page->assertSee('Furniture');
        $page->assertSee('data-category="' . $added['id'] . '"');
        $this->get('/accounting_assets/category_form/' . $added['id'])->assertSee('value="Furniture"');

        $duplicate = $this->postJson('/accounting_assets/save_category', ['name' => 'Furniture']);

        $this->assertSame(lang('Accounting.error_asset_category_invalid'), $duplicate['message']);

        $this->assertTrue($this->postJson('/accounting_assets/category_active/' . $added['id'] . '/0')['success']);
        $this->assertSame(0, (int) $this->accountingDb()->table('asset_categories')->where('category_id', $added['id'])->get()->getRow()->active);

        $this->postJson('/accounting_assets/category_active/' . $added['id'] . '/1');
        $this->enterAsset(['category_id' => $added['id']]);

        $inUse = $this->postJson('/accounting_assets/delete_category/' . $added['id']);

        $this->assertFalse($inUse['success']);
        $this->assertSame(lang('Accounting.error_asset_category_in_use', ['Furniture']), $inUse['message']);
    }

    public function testCategories_AreNotChangedByWhoMayOnlyRead(): void
    {
        $this->actAsWith(['accounting_assets']);

        $this->get('/accounting_assets/categories')->assertDontSee('id="new_category"');

        $denied = $this->postJson('/accounting_assets/save_category', ['name' => 'Furniture']);

        $this->assertSame(lang('Accounting.error_permission_denied'), $denied['message']);

        $this->expectException(PageNotFoundException::class);

        $this->get('/accounting_assets/category_form');
    }

    public function testGetDepreciation_ShowsThePreviewOfTheMonthAndOffersTheApprovalWithItsFingerprint(): void
    {
        $this->enterAsset(['code' => 'DESK']);
        $this->runner();

        $response = $this->get('/accounting_assets/depreciation?month=2026-02&scope=1');

        $response->assertStatus(200);
        $response->assertSee('id="depreciation_preview"');
        $response->assertSee('id="depreciation_branch_1"');
        $response->assertSee('DESK');
        $response->assertSee('1,000.00');
        $response->assertSee(lang('Accounting_assets.branch_status_ready'));
        $response->assertSee('depreciation/approve_form?month=2026-02&amp;scope=1&amp;fingerprint=' . $this->fingerprint());
    }

    public function testGetDepreciation_ShowsNoPreviewToAUserWithoutThePermissionButShowsTheRuns(): void
    {
        $this->enterAsset(['code' => 'DESK']);
        $this->runMonth();
        $this->actAsWith(['accounting_assets']);

        $response = $this->get('/accounting_assets/depreciation?month=2026-02');

        $response->assertSee('id="depreciation_permission_note"');
        $response->assertSee('id="runs_table"');
        $response->assertDontSee('id="depreciation_preview"');
        $response->assertDontSee('depreciation_approve');
        $response->assertDontSee('depreciation-reverse');
    }

    public function testGetDepreciation_ExplainsWhatStopsTheRunAndOffersNoApproval(): void
    {
        $this->enterAsset(['code' => 'DESK']);
        $this->closeThrough('2026-02-28');
        $this->runner();

        $response = $this->get('/accounting_assets/depreciation?month=2026-02&scope=1');

        $response->assertSee('depreciation-blocker');
        $response->assertSee(lang('Accounting_assets.branch_status_blocked'));
        $response->assertDontSee('id="depreciation_approve"');
    }

    public function testGetDepreciation_OffersTheCompanyOnlyToSomeoneWithEveryBranch(): void
    {
        $this->runner([$this->second]);

        $response = $this->get('/accounting_assets/depreciation');

        $response->assertDontSee('value="company"');
        $response->assertSee('value="' . $this->second . '"');

        $this->expectException(PageNotFoundException::class);

        $this->get('/accounting_assets/depreciation?scope=company');
    }

    public function testPostApprove_PostsWhatThePreviewShowedAndRefusesAStaleFingerprint(): void
    {
        $this->enterAsset(['code' => 'DESK']);
        $this->runner();

        $form = $this->get('/accounting_assets/depreciation/approve_form?month=2026-02&scope=1&fingerprint=' . $this->fingerprint());

        $form->assertSee('name="fingerprint" value="' . $this->fingerprint() . '"');
        $form->assertSee('name="confirm"');

        $stale = $this->postJson('/accounting_assets/depreciation/approve', ['month' => '2026-02', 'scope' => '1', 'fingerprint' => str_repeat('a', 64)]);

        $this->assertFalse($stale['success']);
        $this->assertSame(lang('Accounting.error_asset_run_changed'), $stale['message']);
        $this->assertSame(0, $this->countRows('asset_depreciation_runs'));

        $approved = $this->postJson('/accounting_assets/depreciation/approve', ['month' => '2026-02', 'scope' => '1', 'fingerprint' => $this->fingerprint()]);

        $this->assertTrue($approved['success'], $approved['message']);
        $this->assertSame(1, $this->countRows('asset_depreciation_runs'));
        $this->assertSame('-1000.0000', $this->accountNet($this->accountId('accumulated_depreciation')));

        $again = $this->postJson('/accounting_assets/depreciation/approve', ['month' => '2026-02', 'scope' => '1', 'fingerprint' => $this->fingerprint()]);

        $this->assertFalse($again['success']);
        $this->assertSame(lang('Accounting.error_asset_nothing_due', ['2026-02']), $again['message']);
    }

    public function testPostApprove_RejectsAnEmployeeWithoutThePermissionToDepreciate(): void
    {
        $this->enterAsset(['code' => 'DESK']);
        $fingerprint = $this->fingerprint();
        $this->manager();

        $denied = $this->postJson('/accounting_assets/depreciation/approve', ['month' => '2026-02', 'scope' => '1', 'fingerprint' => $fingerprint]);

        $this->assertFalse($denied['success']);
        $this->assertSame(lang('Accounting.error_permission_denied'), $denied['message']);
        $this->assertSame(0, $this->countRows('asset_depreciation_runs'));
    }

    public function testGetApproveForm_IsNotFoundWithoutTheFingerprintOfThePreview(): void
    {
        $this->enterAsset(['code' => 'DESK']);
        $this->runner();

        foreach (['', '&fingerprint=short', '&fingerprint=' . str_repeat('G', 64), '&fingerprint[]=' . str_repeat('a', 64)] as $suffix) {
            try {
                $this->get('/accounting_assets/depreciation/approve_form?month=2026-02&scope=1' . $suffix);
                $this->fail('A form without the fingerprint of a preview is not offered.');
            } catch (PageNotFoundException) {
                $this->assertTrue(true);
            }
        }
    }

    public function testPostReverse_TakesARunBackWithAReason(): void
    {
        $this->enterAsset(['code' => 'DESK']);
        $runId = $this->runMonth();
        $this->runner();

        $this->get('/accounting_assets/depreciation/reverse_form/' . $runId)->assertSee('name="reason"');

        $none = $this->postJson('/accounting_assets/depreciation/reverse/' . $runId, ['reason' => '']);

        $this->assertFalse($none['success']);
        $this->assertSame(lang('Accounting.error_reason_required'), $none['message']);

        $reversed = $this->postJson('/accounting_assets/depreciation/reverse/' . $runId, ['reason' => 'Wrong month']);

        $this->assertTrue($reversed['success'], $reversed['message']);
        $this->assertSame('0.0000', $this->accountNet($this->accountId('accumulated_depreciation')));

        $this->expectException(PageNotFoundException::class);

        $this->get('/accounting_assets/depreciation/reverse_form/' . $runId);
    }

    public function testGetReverseForm_IsNotFoundWithoutThePermissionToDepreciate(): void
    {
        $this->enterAsset(['code' => 'DESK']);
        $runId = $this->runMonth();
        $this->manager();

        $this->expectException(PageNotFoundException::class);

        $this->get('/accounting_assets/depreciation/reverse_form/' . $runId);
    }

    public function testPostUsage_RecordsTheUnitsOfAMonth(): void
    {
        $assetId = $this->enterAsset(['method' => 'units', 'cost_per_unit' => '2.5', 'amount_per_period' => '']);
        $this->manager();

        $this->get('/accounting_assets/usage_form/' . $assetId)->assertSee('name="units"');

        $saved = $this->postJson('/accounting_assets/usage/' . $assetId, ['month' => '2026-02', 'units' => '120']);

        $this->assertTrue($saved['success'], $saved['message']);
        $this->assertSame('120.00', $this->accountingDb()->table('asset_usage')->where('asset_id', $assetId)->get()->getRow()->units);

        $refused = $this->postJson('/accounting_assets/usage/' . $assetId, ['month' => '2026-13', 'units' => '1']);

        $this->assertFalse($refused['success']);
    }

    public function testGetApproveForm_IsNotFoundWhenNothingIsDue(): void
    {
        $this->enterAsset(['code' => 'DESK']);
        $this->runner();

        $this->expectException(PageNotFoundException::class);

        $this->get('/accounting_assets/depreciation/approve_form?month=2026-01&scope=1&fingerprint=' . $this->fingerprint('2026-01'));
    }

    public function testPostApprove_RejectsAScopeThatIsNotOne(): void
    {
        $this->enterAsset(['code' => 'DESK']);
        $this->runner();

        foreach (['', 'x', '0', '-1', 'company; drop'] as $scope) {
            try {
                $this->post('/accounting_assets/depreciation/approve', ['month' => '2026-02', 'scope' => $scope, 'fingerprint' => $this->fingerprint()]);
                $this->fail('A scope that is not the company or a branch is not taken.');
            } catch (PageNotFoundException) {
                $this->assertTrue(true);
            }
        }

        $this->assertSame(0, $this->countRows('asset_depreciation_runs'));
    }
}
