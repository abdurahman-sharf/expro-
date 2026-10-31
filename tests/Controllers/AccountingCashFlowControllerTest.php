<?php

namespace Tests\Controllers;

use App\Libraries\Accounting\AccountingGuard;
use App\Libraries\Accounting\CashFlowSettings;
use App\Libraries\Accounting\EntityAccountService;
use CodeIgniter\Exceptions\PageNotFoundException;
use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\DatabaseTestTrait;
use CodeIgniter\Test\TestResponse;
use DOMDocument;
use DOMXPath;
use Tests\Support\AccountingControllerFixtureTrait;
use Tests\Support\ReportFixtureTrait;

/**
 * The screen of the cash flow, its CSV and the screen where its lines are set up: who may open
 * them and who may save, which branches each adds up for whom, the days, what the setup changes in
 * the report, and what comes out in the file.
 *
 * @internal
 */
final class AccountingCashFlowControllerTest extends CIUnitTestCase
{
    use DatabaseTestTrait;
    use AccountingControllerFixtureTrait;
    use ReportFixtureTrait;

    private const SCREEN   = '/accounting_statements/cash_flow';
    private const SETTINGS = '/accounting_statements/cash_flow/settings';

    protected $migrate     = true;
    protected $migrateOnce = true;
    protected $refresh     = false;
    protected $namespace;

    private int $second;
    private int $third;
    private int $customer;
    private int $supplier;
    private int $stock;

    protected function setUp(): void
    {
        parent::setUp();

        $this->resetBranches();
        $this->prepareAccounting();
        $this->setConfigValue(CashFlowSettings::KEY, '');

        $this->second   = $this->createBranch('T2', 'Second branch');
        $this->third    = $this->createBranch('T3', 'Third branch');
        $this->customer = (new EntityAccountService())->ensure(EntityAccountService::CUSTOMER, $this->insertCustomer(), 'Omar Khaled');
        $this->supplier = (new EntityAccountService())->ensure(EntityAccountService::SUPPLIER, $this->insertSupplier(), 'Gulf Supplies');
        $this->stock    = $this->customAccount('1203', '120301', 'Stock of the warehouse');
    }

    protected function tearDown(): void
    {
        $this->setConfigValue(CashFlowSettings::KEY, '');
        $this->removeInsertedEntities();
        $this->reinstallChart();
        $this->resetBranches();

        parent::tearDown();
    }

    /**
     * @param list<int>|null $branchIds
     * @param list<string>   $extra
     */
    private function viewer(?array $branchIds = null, array $extra = []): int
    {
        $personId = $this->employeeWithGrants(['accounting_reports', ...$extra], $branchIds);

        $this->actAs($personId);

        return $personId;
    }

    private function amountOf(string $value): string
    {
        return to_currency_no_money($value);
    }

    private function pageOf(TestResponse $response): DOMXPath
    {
        $document = new DOMDocument();

        libxml_use_internal_errors(true);
        $document->loadHTML('<?xml encoding="UTF-8">' . $response->getBody());
        libxml_clear_errors();

        return new DOMXPath($document);
    }

    private function cell(TestResponse $response, string $row): ?string
    {
        $nodes = $this->pageOf($response)->query('//tr[' . $row . ']/td[@data-cell="amount"]');

        return $nodes->length === 0 ? null : trim($nodes->item(0)->textContent);
    }

    /**
     * @return list<list<string>>
     */
    private function csvRows(TestResponse $response): array
    {
        $body = $response->response()->getBody();

        $this->assertStringStartsWith("\xEF\xBB\xBF", $body);

        $handle = fopen('php://temp', 'r+b');
        fwrite($handle, substr($body, 3));
        rewind($handle);

        $rows = [];

        while (($row = fgetcsv($handle, 0, ',', '"', '')) !== false) {
            $rows[] = $row;
        }

        fclose($handle);

        return $rows;
    }

    /**
     * @param list<list<string>> $rows
     *
     * @return list<string>|null
     */
    private function rowStartingWith(array $rows, string $cell): ?array
    {
        foreach ($rows as $row) {
            if (($row[0] ?? null) === $cell) {
                return $row;
            }
        }

        return null;
    }

    private function books(int $branch = 1): void
    {
        $cash = $this->accountId('cash');

        $this->postOn('2026-10-01', $branch, [[$cash, '100000.00', '0'], [$this->accountIdByCode('31'), '0', '100000.00']]);
        $this->postOn('2026-10-03', $branch, [[$this->customer, '1150.00', '0'], [$this->accountId('sales_revenue'), '0', '1000.00'], [$this->accountId('tax_output'), '0', '150.00']]);
        $this->postOn('2026-10-04', $branch, [[$this->stock, '600.00', '0'], [$this->accountId('tax_input'), '90.00', '0'], [$this->supplier, '0', '690.00']]);
        $this->postOn('2026-10-10', $branch, [[$cash, '400.00', '0'], [$this->customer, '0', '400.00']]);
        $this->postOn('2026-10-12', $branch, [[$this->supplier, '200.00', '0'], [$cash, '0', '200.00']]);
        $this->postOn('2026-10-15', $branch, [[$this->accountIdByCode('545'), '75.00', '0'], [$cash, '0', '75.00']]);
    }

    public function testGetIndex_ShowsTheLinesPartsAndTotalsOfTheBooksAndWhatTheTreasuriesHeld(): void
    {
        $this->books();
        $this->viewer();

        $response = $this->get(self::SCREEN . '?from=2026-10-01&to=2026-10-31');

        $response->assertStatus(200);
        $response->assertSee('id="cash_flow_table"');
        $this->assertSame($this->amountOf('925.00'), $this->cell($response, '@data-line="net_income"'));
        $this->assertSame($this->amountOf('-750.00'), $this->cell($response, '@data-line="account_receivable"'));
        $this->assertSame($this->amountOf('490.00'), $this->cell($response, '@data-line="account_payable"'));
        $this->assertSame($this->amountOf('-600.00'), $this->cell($response, '@data-line="inventory"'));
        $this->assertSame($this->amountOf('100000.00'), $this->cell($response, '@data-line="common_shares"'));
        $this->assertSame($this->amountOf('65.00'), $this->cell($response, '@data-total="operating"'));
        $this->assertSame($this->amountOf('100000.00'), $this->cell($response, '@data-total="financing"'));
        $this->assertSame($this->amountOf('60.00'), $this->cell($response, '@data-total="other"'));
        $this->assertSame($this->amountOf('100125.00'), $this->cell($response, '@data-total="change"'));
        $this->assertSame($this->amountOf('0.00'), $this->cell($response, '@data-total="opening"'));
        $this->assertSame($this->amountOf('100125.00'), $this->cell($response, '@data-total="closing"'));
        $response->assertSee('id="report_ties"');
        $response->assertDontSee('id="report_differs"');
        $response->assertDontSee('id="report_error"');
    }

    public function testGetIndex_SetsOutTheAccountsNoLineTakesFromAndLinksEachToItsStatement(): void
    {
        $this->books();
        $this->viewer();

        $response = $this->get(self::SCREEN . '?from=2026-10-01&to=2026-10-31');

        $this->assertSame($this->amountOf('150.00'), $this->cell($response, '@data-account="215001"'));
        $this->assertSame($this->amountOf('-90.00'), $this->cell($response, '@data-account="215002"'));
        $response->assertSee('accounting_reports/ledger?account=' . $this->accountId('tax_output'));
        $response->assertSee(lang('Accounting_cash_flow.note'));
    }

    public function testGetIndex_DefaultsToTheYearToTheLastDayAndShowsZerosForARangeWithNothing(): void
    {
        $this->postOn('2025-12-31', 1, [[$this->accountId('cash'), '999.00', '0'], [$this->accountIdByCode('4201'), '0', '999.00']]);
        $this->postOn('2026-02-10', 1, [[$this->accountId('cash'), '100.00', '0'], [$this->accountIdByCode('4201'), '0', '100.00']]);
        $this->viewer();

        $year  = $this->get(self::SCREEN . '?to=2026-10-31');
        $empty = $this->get(self::SCREEN . '?from=2024-01-01&to=2024-12-31');

        $this->assertSame($this->amountOf('100.00'), $this->cell($year, '@data-line="net_income"'));
        $this->assertSame($this->amountOf('999.00'), $this->cell($year, '@data-total="opening"'), 'The money held when the year opened');
        $this->assertSame($this->amountOf('0.00'), $this->cell($empty, '@data-line="net_income"'));
        $this->assertSame($this->amountOf('0.00'), $this->cell($empty, '@data-total="change"'));
        $empty->assertSee('id="report_ties"');
    }

    public function testGetIndex_WritesTheHeadingInTheLanguageInUse(): void
    {
        $this->viewer();

        $response = $this->get(self::SCREEN . '?from=2026-10-01&to=2026-10-31');

        $response->assertSee(lang('Accounting_cash_flow.period_beginning'));
        $response->assertSee(lang('Accounting_cash_flow.period_ending'));
        $response->assertSee(lang('Accounting_cash_flow.section_operating'));
        $response->assertSee(lang('Accounting_cash_flow.line_net_income'));
    }

    public function testGetIndex_CutsWhatIsAskedDownToTheBranchesTheEmployeeWorksIn(): void
    {
        $this->books();
        $this->books($this->third);
        $this->viewer([1, $this->second]);

        $response = $this->get(self::SCREEN . '?from=2026-10-01&to=2026-10-31&branches[]=1&branches[]=' . $this->third);

        $this->assertSame($this->amountOf('100125.00'), $this->cell($response, '@data-total="change"'), 'The third branch is not theirs');
        $response->assertSee(lang('Accounting_reports.scope_selected', [(string) $this->accountingDb()->table('branches')->where('branch_id', 1)->get()->getRow()->code]));
    }

    public function testGetIndex_SaysTheTotalIsOfTheBranchesTheEmployeeWorksInAndOffersTheBranches(): void
    {
        $this->books();
        $this->viewer([1, $this->second]);

        $response = $this->get(self::SCREEN . '?from=2026-10-01&to=2026-10-31');

        $response->assertSee('id="report_scope"');
        $response->assertSee('id="f_branches"');
        $this->assertSame($this->amountOf('100125.00'), $this->cell($response, '@data-total="change"'));
    }

    public function testGetIndex_TiesInEveryBranchOfTheBooks(): void
    {
        $this->books();
        $this->books($this->second);
        $this->viewer();

        foreach ([[1], [$this->second], [1, $this->second]] as $branches) {
            $response = $this->get(self::SCREEN . '?from=2026-10-01&to=2026-10-31&' . http_build_query(['branches' => $branches]));

            $response->assertSee('id="report_ties"');
        }
    }

    public function testGetIndex_TakesTheTitlesAndTheLinesFromTheSetupOfTheCompany(): void
    {
        $this->books();
        $this->postSetup([['key' => 'inventory', 'title' => 'Warehouse stock'], ['key' => 'account_payable', 'visible' => '0']]);
        $this->viewer();

        $response = $this->get(self::SCREEN . '?from=2026-10-01&to=2026-10-31');

        $response->assertSee('Warehouse stock');
        $this->assertNull($this->cell($response, '@data-line="account_payable"'), 'A hidden line is not drawn');
        $this->assertSame($this->amountOf('100125.00'), $this->cell($response, '@data-total="change"'), 'And the total does not move');
        $response->assertSee('id="report_ties"');
    }

    public function testGetIndex_ShowsAMessageForADayThatIsNotOneAndForAStartAfterTheEndAndStillShowsAReport(): void
    {
        $this->viewer();

        foreach (['from=2026-02-31&to=2026-03-01', 'from=2026-10-31&to=2026-10-01'] as $query) {
            $response = $this->get(self::SCREEN . '?' . $query);

            $response->assertStatus(200);
            $response->assertSee('id="report_error"');
            $response->assertSee('id="cash_flow_table"');
        }
    }

    public function testGetIndex_OffersTheSetupToWhoMayManageItAndTheReadingToWhoMayNot(): void
    {
        $this->viewer();

        $this->get(self::SCREEN)->assertSee(lang('Accounting_cash_flow.settings_view'));

        $this->viewer(null, [AccountingGuard::SETTINGS_MANAGE]);

        $this->get(self::SCREEN)->assertSee(lang('Accounting_cash_flow.settings_edit'));
    }

    public function testGetIndex_RedirectsAUserWithoutTheModuleGrantAndIsNotFoundWhileAccountingIsOff(): void
    {
        $this->actAs($this->employeeWithGrants(['accounting_payables']));

        $this->get(self::SCREEN)->assertRedirect();

        $this->prepareAccounting(false);
        $this->viewer();

        $this->expectException(PageNotFoundException::class);

        $this->get(self::SCREEN);
    }

    public function testGetCsv_GivesTheLinesPartsAndTotalsOfTheScreenInTheLanguageInUse(): void
    {
        $this->books();
        $this->viewer();

        $response = $this->get(self::SCREEN . '/csv?from=2026-10-01&to=2026-10-31');

        $response->assertStatus(200);
        $this->assertStringContainsString('text/csv', $response->response()->getHeaderLine('Content-Type'));
        $this->assertStringContainsString('filename="cash_flow_2026-10-01_2026-10-31.csv"', $response->response()->getHeaderLine('Content-Disposition'));

        $rows = $this->csvRows($response);

        $this->assertSame([lang('Accounting_cash_flow.period_beginning'), '2026-10-01'], $rows[0]);
        $this->assertSame([lang('Accounting_cash_flow.period_ending'), '2026-10-31'], $rows[1]);
        $this->assertSame('925.0000', $this->rowStartingWith($rows, lang('Accounting_cash_flow.line_net_income'))[1]);
        $this->assertSame('65.0000', $this->rowStartingWith($rows, lang('Accounting_cash_flow.total_operating'))[1]);
        $this->assertSame('60.0000', $this->rowStartingWith($rows, lang('Accounting_cash_flow.other_changes'))[1]);
        $this->assertSame('150.0000', $this->rowStartingWith($rows, '215001 ' . $this->accountNameOf('215001'))[1]);
        $this->assertSame('100125.0000', $this->rowStartingWith($rows, lang('Accounting_cash_flow.total_change'))[1]);
        $this->assertSame('100125.0000', $this->rowStartingWith($rows, lang('Accounting_cash_flow.cash_closing'))[1]);
        $this->assertNotNull($this->rowStartingWith($rows, lang('Accounting_cash_flow.ties')));
    }

    private function accountNameOf(string $code): string
    {
        return (string) $this->accountingDb()->table('accounting_accounts')->select('name')->where('code', $code)->get()->getRow()->name;
    }

    public function testGetCsv_ShowsATitleThatStartsLikeAFormulaAsTextAndKeepsNegativeNumbersAsNumbers(): void
    {
        $this->books();
        $this->postSetup([['key' => 'inventory', 'title' => '=SUM(A1:A9)']]);
        $this->viewer();

        $rows = $this->csvRows($this->get(self::SCREEN . '/csv?from=2026-10-01&to=2026-10-31'));

        $this->assertSame('-600.0000', $this->rowStartingWith($rows, "'=SUM(A1:A9)")[1], 'The title of the line is text and the amount a number');
        $this->assertSame('-90.0000', $this->rowStartingWith($rows, '215002 ' . $this->accountNameOf('215002'))[1]);
    }

    public function testGetCsv_AddsUpOnlyTheBranchesTheEmployeeWorksInWhateverTheRequestAsks(): void
    {
        $this->books();
        $this->books($this->third);
        $this->viewer([1]);

        $rows = $this->csvRows($this->get(self::SCREEN . '/csv?from=2026-10-01&to=2026-10-31&branches[]=1&branches[]=' . $this->third));

        $this->assertSame('100125.0000', $this->rowStartingWith($rows, lang('Accounting_cash_flow.total_change'))[1]);
        $this->assertSame((string) $this->accountingDb()->table('branches')->where('branch_id', 1)->get()->getRow()->code, $this->rowStartingWith($rows, lang('Accounting_reports.csv_branches'))[1]);
    }

    public function testGetCsv_RefusesAPeriodThatEndsBeforeItStartsInWords(): void
    {
        $this->viewer();

        $response = $this->get(self::SCREEN . '/csv?from=2026-10-31&to=2026-10-01');

        $response->assertStatus(422);
        $this->assertSame(lang('Accounting.error_report_period_invalid'), $response->response()->getBody());
    }

    public function testGetCsv_RedirectsAUserWithoutTheModuleGrantAndIsNotFoundWhileAccountingIsOff(): void
    {
        $this->actAs($this->employeeWithGrants(['accounting_payables']));

        $this->get(self::SCREEN . '/csv')->assertRedirect();

        $this->prepareAccounting(false);
        $this->viewer();

        $this->expectException(PageNotFoundException::class);

        $this->get(self::SCREEN . '/csv');
    }

    public function testGetSettings_ShowsTheLinesToWhoReadsTheReportAndOffersNoSaveToWhoMayNotManageIt(): void
    {
        $this->viewer();

        $response = $this->get(self::SETTINGS);

        $response->assertStatus(200);
        $response->assertSee('id="cash_flow_lines"');
        $response->assertSee('data-line="net_income"');
        $response->assertSee('data-line="new"');
        $response->assertSee('id="settings_read_only"');
        $response->assertDontSee('<button type="submit"');
        $response->assertSee('disabled');
    }

    public function testGetSettings_OffersTheSaveToWhoMayManageTheSettingsInEveryBranch(): void
    {
        $this->viewer(null, [AccountingGuard::SETTINGS_MANAGE]);

        $response = $this->get(self::SETTINGS);

        $response->assertSee('<button type="submit"');
        $response->assertDontSee('id="settings_read_only"');
    }

    public function testGetSettings_ShowsItReadOnlyToWhoMayManageTheSettingsButWorksInOneBranch(): void
    {
        $this->viewer([1], [AccountingGuard::SETTINGS_MANAGE]);

        $response = $this->get(self::SETTINGS);

        $response->assertSee('id="settings_read_only"');
        $response->assertDontSee('<button type="submit"');
    }

    public function testGetSettings_RedirectsAUserWithoutTheModuleGrantAndIsNotFoundWhileAccountingIsOff(): void
    {
        $this->actAs($this->employeeWithGrants(['accounting_payables', AccountingGuard::SETTINGS_MANAGE]));

        $this->get(self::SETTINGS)->assertRedirect();

        $this->prepareAccounting(false);
        $this->viewer();

        $this->expectException(PageNotFoundException::class);

        $this->get(self::SETTINGS);
    }

    /**
     * @param list<array<string, mixed>> $lines
     *
     * @return array<string, mixed>
     */
    private function postSetup(array $lines, ?int $as = null, ?string $version = null): array
    {
        $this->actAs($as ?? $this->employeeWithGrants(['accounting_reports', AccountingGuard::SETTINGS_MANAGE]));

        return $this->jsonOf($this->post(self::SETTINGS . '/save', ['lines' => $lines, 'version' => $version ?? (new CashFlowSettings())->version()]));
    }

    /**
     * What a browser sends when the form is submitted: the fields that are not disabled, a checkbox
     * only when it is checked, a list of choices with its chosen options and nothing when none is
     * chosen, a list of one choice with the one it shows.
     *
     * @return list<array{string, string}>
     */
    private function submittedAsABrowserDoes(TestResponse $response): array
    {
        $page   = $this->pageOf($response);
        $pairs  = [];
        $fields = $page->query('//form[@id="cash_flow_settings_form"]//input[@name][not(@disabled)] | //form[@id="cash_flow_settings_form"]//select[@name][not(@disabled)]');

        foreach ($fields as $field) {
            $name = $field->getAttribute('name');

            if ($field->nodeName === 'select') {
                $chosen = $page->query('.//option[@selected]', $field);
                $chosen = $chosen->length === 0 && ! $field->hasAttribute('multiple') ? $page->query('.//option[1]', $field) : $chosen;

                foreach ($chosen as $option) {
                    $pairs[] = [$name, $option->getAttribute('value')];
                }

                continue;
            }

            if ($field->getAttribute('type') === 'checkbox' && ! $field->hasAttribute('checked')) {
                continue;
            }

            $pairs[] = [$name, $field->getAttribute('value')];
        }

        return $pairs;
    }

    /**
     * @param list<array{string, string}> $pairs
     *
     * @return array<string, mixed>
     */
    private function postedAsABrowserDoes(array $pairs): array
    {
        parse_str(implode('&', array_map(static fn (array $pair): string => rawurlencode($pair[0]) . '=' . rawurlencode($pair[1]), $pairs)), $data);

        return $this->jsonOf($this->post(self::SETTINGS . '/save', $data));
    }

    public function testPostSettingsSave_SavesTheLinesAndWritesWhatChangedToTheAuditLog(): void
    {
        $result = $this->postSetup([['key' => 'inventory', 'title' => 'Warehouse stock', 'visible' => '1'], ['key' => 'account_payable', 'visible' => '0']]);

        $this->assertTrue($result['success']);
        $this->assertSame(lang('Accounting_cash_flow.settings_saved'), $result['message']);

        $lines = array_column((new CashFlowSettings())->lines(), null, 'key');

        $this->assertSame(['Warehouse stock', false], [$lines['inventory']->title, $lines['account_payable']->visible]);
        $this->assertSame(1, $this->accountingDb()->table('accounting_audit_log')->where('action', 'cash_flow_lines_updated')->countAllResults());
    }

    public function testPostSettingsSave_AddsALineAndTheReportDrawsIt(): void
    {
        $loans = $this->customAccount('2', '2999', 'Loans');

        $this->postOne(['key' => '', 'title' => 'Loans received', 'accounts' => [(string) $loans], 'formula' => 'credit_in_period', 'section' => 'financing', 'visible' => '1']);
        $this->postOn('2026-10-05', 1, [[$this->accountId('cash'), '300.00', '0'], [$loans, '0', '300.00']]);
        $this->viewer();

        $response = $this->get(self::SCREEN . '?from=2026-10-01&to=2026-10-31');

        $response->assertSee('Loans received');
        $this->assertSame($this->amountOf('300.00'), $this->cell($response, '@data-line="custom_1"'));
        $this->assertSame($this->amountOf('300.00'), $this->cell($response, '@data-total="financing"'));
        $this->assertSame($this->amountOf('0.00'), $this->cell($response, '@data-total="other"'));
    }

    /**
     * @param array<string, mixed> $line
     */
    private function postOne(array $line): void
    {
        $this->assertTrue($this->postSetup([$line])['success']);
    }

    public function testPostSettingsSave_RequiresThePermissionToManageTheSettings(): void
    {
        $result = $this->postSetup([['key' => 'inventory', 'title' => 'Warehouse stock']], $this->employeeWithGrants(['accounting_reports']));

        $this->assertFalse($result['success']);
        $this->assertSame(lang('Accounting.error_permission_denied'), $result['message']);
        $this->assertSame('', array_column((new CashFlowSettings())->lines(), null, 'key')['inventory']->title);
        $this->assertSame(0, $this->accountingDb()->table('accounting_audit_log')->where('action', 'cash_flow_lines_updated')->countAllResults());
    }

    public function testPostSettingsSave_RequiresEveryBranchBecauseTheSettingIsTheCompanys(): void
    {
        $result = $this->postSetup([['key' => 'inventory', 'title' => 'Warehouse stock']], $this->employeeWithGrants(['accounting_reports', AccountingGuard::SETTINGS_MANAGE], [1]));

        $this->assertFalse($result['success']);
        $this->assertSame('', array_column((new CashFlowSettings())->lines(), null, 'key')['inventory']->title);
    }

    public function testPostSettingsSave_RefusesALineThatIsNotValidInWordsAndKeepsNothing(): void
    {
        $result = $this->postSetup([['key' => 'inventory', 'title' => 'Warehouse stock'], ['key' => 'account_payable', 'formula' => 'sum_everything']]);

        $this->assertFalse($result['success']);
        $this->assertSame(lang('Accounting.error_cash_flow_line_invalid', [CashFlowSettings::MAX_CUSTOM_LINES, 'account_payable']), $result['message']);
        $this->assertSame('', array_column((new CashFlowSettings())->lines(), null, 'key')['inventory']->title, 'The line before the refused one is not kept either');
    }

    public function testPostSettingsSave_RefusesTheAccountOfATreasuryInWords(): void
    {
        $result = $this->postSetup([['key' => 'inventory', 'accounts' => [(string) $this->accountId('cash')]]]);

        $this->assertFalse($result['success']);
        $this->assertSame(lang('Accounting.error_cash_flow_account_invalid', ['inventory']), $result['message']);
    }

    public function testPostSettingsSave_ReadsAnythingButAListOfLinesAsNone(): void
    {
        $this->actAs($this->employeeWithGrants(['accounting_reports', AccountingGuard::SETTINGS_MANAGE]));

        $result = $this->jsonOf($this->post(self::SETTINGS . '/save', ['lines' => 'not a list', 'version' => (new CashFlowSettings())->version()]));

        $this->assertTrue($result['success']);
        $this->assertCount(8, (new CashFlowSettings())->lines());
    }

    public function testPostSettingsSave_RedirectsAUserWithoutTheModuleGrant(): void
    {
        $this->actAs($this->employeeWithGrants(['accounting_payables', AccountingGuard::SETTINGS_MANAGE]));

        $this->post(self::SETTINGS . '/save', ['lines' => [['key' => 'inventory', 'title' => 'Warehouse stock']]])->assertRedirect();

        $this->assertSame('', array_column((new CashFlowSettings())->lines(), null, 'key')['inventory']->title);
    }

    public function testGetSettings_SendsTheVersionOfTheLinesAndTheEmptyChoiceOfAccountsWithEveryList(): void
    {
        $this->postOne(['key' => '', 'title' => 'Loans received', 'accounts' => [(string) $this->customAccount('2', '2999', 'Loans')], 'section' => 'financing']);
        $this->viewer(null, [AccountingGuard::SETTINGS_MANAGE]);

        $page = $this->pageOf($this->get(self::SETTINGS));

        $this->assertSame((new CashFlowSettings())->version(), $page->query('//form[@id="cash_flow_settings_form"]/input[@name="version"]')->item(0)->getAttribute('value'));
        $this->assertSame(9, $page->query('//select[@multiple]')->length, 'The seven lines that take accounts, the line that was added and the new line');
        $this->assertSame(9, $page->query('//input[@type="hidden"][@value=""][contains(@name, "[accounts][]")]')->length, 'Every list of accounts comes with the empty choice');
        $this->assertSame(0, $page->query('//select[contains(@name, "[section]")][not(@aria-label)]')->length, 'Every part has a name for a screen reader');
        $this->assertSame(2, $page->query('//select[contains(@name, "[section]")]')->length);
    }

    public function testPostSettingsSave_PutsALineBackOnItsOwnAccountsWhenTheFormIsSubmittedWithNoAccountChosen(): void
    {
        $this->postOne(['key' => 'inventory', 'accounts' => [(string) $this->stock]]);
        $this->viewer(null, [AccountingGuard::SETTINGS_MANAGE]);

        $pairs = array_values(array_filter($this->submittedAsABrowserDoes($this->get(self::SETTINGS)), fn (array $pair): bool => ! ($pair[1] === (string) $this->stock && str_ends_with($pair[0], '[accounts][]'))));

        $this->assertSame([['lines[4][accounts][]', '']], array_values(array_filter($pairs, static fn (array $pair): bool => $pair[0] === 'lines[4][accounts][]')), 'With none chosen the browser sends only the empty choice');

        $result = $this->postedAsABrowserDoes($pairs);

        $this->assertTrue($result['success'], $result['message']);
        $this->assertSame([], array_column((new CashFlowSettings())->lines(), null, 'key')['inventory']->accountIds, 'The line is back on the accounts it starts with');
        $this->assertSame(2, $this->accountingDb()->table('accounting_audit_log')->where('action', 'cash_flow_lines_updated')->countAllResults());
    }

    public function testPostSettingsSave_KeepsTheLinesAsTheyAreWhenTheFormIsSubmittedAsItWasShown(): void
    {
        $this->postOne(['key' => 'inventory', 'title' => 'Warehouse stock', 'accounts' => [(string) $this->stock]]);
        $this->viewer(null, [AccountingGuard::SETTINGS_MANAGE]);

        $result = $this->postedAsABrowserDoes($this->submittedAsABrowserDoes($this->get(self::SETTINGS)));

        $this->assertTrue($result['success'], $result['message']);

        $line = array_column((new CashFlowSettings())->lines(), null, 'key')['inventory'];

        $this->assertSame(['Warehouse stock', [$this->stock]], [$line->title, $line->accountIds]);
        $this->assertCount(8, (new CashFlowSettings())->lines(), 'The blank row for a new line adds none');
        $this->assertSame(1, $this->accountingDb()->table('accounting_audit_log')->where('action', 'cash_flow_lines_updated')->countAllResults(), 'A form that changes nothing writes nothing down');
    }

    public function testPostSettingsSave_RefusesAFormOpenedBeforeAnotherSaveInWordsAndKeepsTheOtherSave(): void
    {
        $this->viewer(null, [AccountingGuard::SETTINGS_MANAGE]);

        $stale = $this->submittedAsABrowserDoes($this->get(self::SETTINGS));

        $this->postOne(['key' => 'inventory', 'title' => 'Warehouse stock']);
        $this->viewer(null, [AccountingGuard::SETTINGS_MANAGE]);

        $result = $this->postedAsABrowserDoes($stale);

        $this->assertFalse($result['success']);
        $this->assertSame(lang('Accounting.error_cash_flow_lines_changed'), $result['message']);
        $this->assertSame('Warehouse stock', array_column((new CashFlowSettings())->lines(), null, 'key')['inventory']->title, 'The form that was out of date does not put the old title back');
    }

    public function testPostSettingsSave_RefusesARequestThatSendsNoVersionOfTheLines(): void
    {
        $this->actAs($this->employeeWithGrants(['accounting_reports', AccountingGuard::SETTINGS_MANAGE]));

        $result = $this->jsonOf($this->post(self::SETTINGS . '/save', ['lines' => [['key' => 'inventory', 'title' => 'Warehouse stock']]]));

        $this->assertFalse($result['success']);
        $this->assertSame(lang('Accounting.error_cash_flow_lines_changed'), $result['message']);
        $this->assertSame('', array_column((new CashFlowSettings())->lines(), null, 'key')['inventory']->title);
    }

    public function testPostSettingsSave_RefusesATitleThatIsNotTextInWordsAndNotWithAServerError(): void
    {
        $result = $this->postSetup([['key' => 'inventory', 'title' => ['x']]]);

        $this->assertFalse($result['success']);
        $this->assertSame(lang('Accounting.error_cash_flow_line_invalid', [CashFlowSettings::MAX_CUSTOM_LINES, 'inventory']), $result['message']);
    }
}
