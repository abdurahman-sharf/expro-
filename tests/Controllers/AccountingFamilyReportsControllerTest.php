<?php

namespace Tests\Controllers;

use App\Libraries\Accounting\AccountingGuard;
use App\Libraries\Accounting\CostCenterService;
use App\Libraries\Accounting\FamilyReports\ChartReport;
use App\Libraries\Accounting\FamilyReports\FamilyReportCatalog;
use App\Libraries\Accounting\JournalService;
use App\Libraries\Roles\RoleService;
use CodeIgniter\Exceptions\PageNotFoundException;
use CodeIgniter\Test\FeatureTestTrait;
use CodeIgniter\Test\TestResponse;
use Tests\Support\DatabaseFingerprintTrait;
use Tests\Support\FamilyReportTestCase;

/**
 * The screens and the files of the reports of purchases, suppliers, stock, expenses, income and the
 * books: who may open each, which branches each adds up for whom, what comes out in the file, that
 * a text a spreadsheet would read as a formula is shown as text, and that reading changes nothing.
 *
 * @internal
 */
final class AccountingFamilyReportsControllerTest extends FamilyReportTestCase
{
    use FeatureTestTrait;
    use DatabaseFingerprintTrait;

    protected function setUp(): void
    {
        parent::setUp();

        $this->emptyShelves();
    }

    protected function tearDown(): void
    {
        $this->restoreShelves();

        parent::tearDown();
    }

    /**
     * @param list<string>   $permissions
     * @param list<int>|null $branchIds
     */
    private function viewer(array $permissions, ?array $branchIds = null): int
    {
        $personId = $this->employeeWithGrants($permissions, $branchIds);

        $this->withSession(['person_id' => $personId, 'menu_group' => 'home']);

        return $personId;
    }

    /**
     * @return list<string>
     */
    private function everyReportPermission(): array
    {
        return array_values(array_unique([
            'accounting_reports',
            AccountingGuard::JOURNALS_ALL,
            ...array_map(static fn (array $definition): string => $definition['permission'], array_values(FamilyReportCatalog::all())),
        ]));
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

    private function grandFromCsv(TestResponse $response, string $key, string $column): float
    {
        $class   = FamilyReportCatalog::find($key)['class'];
        $columns = array_map(static fn ($candidate): string => $candidate->key, (new $class())->build($this->input($this->thisDay()))->columns);
        $index   = array_search($column, $columns, true);

        foreach ($this->csvRows($response) as $row) {
            if (in_array(lang('Accounting_family_reports.grand_total'), $row, true)) {
                return (float) $row[$index];
            }
        }

        return NAN;
    }

    private function csvCell(TestResponse $response, string $label, int $column): string
    {
        foreach ($this->csvRows($response) as $row) {
            if (($row[0] ?? '') === $label) {
                return $row[$column] ?? '';
            }
        }

        return '';
    }

    public function testGetShow_ShowsEveryReportToWhoHoldsTheModuleAndThePermissionOfIt(): void
    {
        $this->market();
        $this->viewer($this->everyReportPermission());

        foreach (FamilyReportCatalog::keys() as $key) {
            $response = $this->get('/accounting_reports/' . $key);

            $response->assertStatus(200);
            $response->assertSee('id="family_report"');
            $response->assertSee('data-report="' . $key . '"');
            $response->assertSee(lang('Accounting_family_reports.' . FamilyReportCatalog::titleKey($key)));
            $response->assertDontSee('Accounting_family_reports.');
            $response->assertDontSee('id="report_error"');
        }
    }

    public function testGetCsv_GivesEveryReportAsAFileWithAByteOrderMark(): void
    {
        $this->market();
        $this->viewer($this->everyReportPermission());

        foreach (FamilyReportCatalog::keys() as $key) {
            $response = $this->get('/accounting_reports/' . $key . '/csv');

            $response->assertStatus(200);
            $this->assertStringContainsString('text/csv', $response->response()->getHeaderLine('Content-Type'));
            $this->assertStringContainsString($key . '_', $response->response()->getHeaderLine('Content-Disposition'));
            $this->assertGreaterThan(1, count($this->csvRows($response)), $key);
        }
    }

    public function testGetShow_ChangesNothingInTheDatabase(): void
    {
        $this->market();
        $this->viewer($this->everyReportPermission());

        $before = $this->databaseFingerprint();

        foreach (FamilyReportCatalog::keys() as $key) {
            $this->get('/accounting_reports/' . $key)->assertStatus(200);
            $this->get('/accounting_reports/' . $key . '/csv')->assertStatus(200);
        }

        $this->assertDatabaseUnchanged($before);
    }

    public function testGetShow_RequiresTheModuleOfTheFinancialReportsAndThePermissionOfTheReport(): void
    {
        $this->market();

        foreach (FamilyReportCatalog::all() as $key => $definition) {
            $this->viewer(['accounting_reports']);
            $this->get('/accounting_reports/' . $key)->assertRedirect();
            $this->get('/accounting_reports/' . $key . '/csv')->assertRedirect();

            $this->viewer([$definition['permission']]);
            $this->get('/accounting_reports/' . $key)->assertRedirect();

            $this->viewer(['accounting_reports', $definition['permission']]);
            $this->get('/accounting_reports/' . $key)->assertStatus(200);
        }
    }

    public function testGetShow_IsNotFoundWhileAccountingIsOffAndForAReportThatDoesNotExist(): void
    {
        $this->viewer($this->everyReportPermission());

        try {
            $this->get('/accounting_reports/not_a_report');
            $this->fail('The report was found');
        } catch (PageNotFoundException) {
            $this->addToAssertionCount(1);
        }

        $this->setConfigValue('accounting_enabled', '0');

        $this->expectException(PageNotFoundException::class);

        $this->get('/accounting_reports/purchases');
    }

    public function testGetShow_AddsUpOnlyTheBranchesTheEmployeeWorksInWhateverTheRequestNames(): void
    {
        $item   = $this->item('0.00');
        $branch = $this->createBranch('FR2', 'Family branch');

        $this->assertGreaterThan(0, $this->buy([$this->purchaseLine($item, 0, '1', '100.00')]));
        $this->assertGreaterThan(0, $this->buy([$this->purchaseLine($item, 0, '1', '60.00', '0', '0', PERCENT, $this->locationOfBranch($branch))]));

        $day   = '?from=' . $this->today() . '&to=' . $this->today();
        $total = lang('Accounting_family_reports.total');

        $this->viewer($this->everyReportPermission());

        $this->assertSame('160.0000', $this->csvCell($this->get('/accounting_reports/purchases/csv' . $day), $total, 4));

        $this->viewer($this->everyReportPermission(), [$branch]);

        $this->assertSame('60.0000', $this->csvCell($this->get('/accounting_reports/purchases/csv' . $day), $total, 4));
        $this->assertSame('0.0000', $this->csvCell($this->get('/accounting_reports/purchases/csv' . $day . '&branches[]=1'), $total, 4));
        $this->assertSame('60.0000', $this->csvCell($this->get('/accounting_reports/purchases/csv' . $day . '&branches[]=1&branches[]=' . $branch), $total, 4));
    }

    public function testGetShow_LimitsEveryReportThatHoldsFiguresToTheBranchesOfTheEmployee(): void
    {
        $item   = $this->named('Cream');
        $branch = $this->createBranch('FR5', 'Fifth family branch');
        $depot  = $this->locationOfBranch($branch, 'Fifth depot');

        $this->bookStock($this->today(), 1, $item, '10', '100.00');
        $this->putOnShelf($item, '10');
        $this->bookStock($this->today(), $branch, $item, '4', '40.00', $depot);
        $this->putOnShelf($item, '4', $depot);

        $day = '?from=' . $this->today() . '&to=' . $this->today();

        foreach (['stock_count_sheet' => ['', 'system', 14.0, 4.0], 'product_balance' => [$day, 'net_value', 140.0, 40.0], 'journals' => [$day, 'debit', 140.0, 40.0]] as $key => [$query, $column, $all, $limited]) {
            $this->viewer($this->everyReportPermission());
            $everyone = $this->grandFromCsv($this->get('/accounting_reports/' . $key . '/csv' . $query), $key, $column);

            $this->viewer($this->everyReportPermission(), [$branch]);
            $branchOnly = $this->grandFromCsv($this->get('/accounting_reports/' . $key . '/csv' . $query), $key, $column);

            $this->assertEqualsWithDelta($all, $everyone, 0.001, $key . ' for everyone');
            $this->assertEqualsWithDelta($limited, $branchOnly, 0.001, $key . ' for the branch');
        }
    }

    public function testGetShow_ShowsTheJournalReportsOnlyTheJournalsOfWhoMayNotReadThoseOfEveryone(): void
    {
        $permissions = array_values(array_diff($this->everyReportPermission(), [AccountingGuard::JOURNALS_ALL]));
        $author      = $this->viewer([...$permissions, AccountingGuard::JOURNALS_DRAFT, AccountingGuard::JOURNALS_POST]);

        (new JournalService())->postNew($this->journalInput('100.00'), $author);
        (new JournalService())->postNew($this->journalInput('250.00'));

        $day = '?from=2026-10-21&to=2026-10-21';

        foreach (['journals' => 'debit', 'journal_movements' => 'debit'] as $key => $column) {
            $own = $this->grandFromCsv($this->get('/accounting_reports/' . $key . '/csv' . $day), $key, $column);

            $this->viewer($this->everyReportPermission());

            $everyone = $this->grandFromCsv($this->get('/accounting_reports/' . $key . '/csv' . $day), $key, $column);

            $this->withSession(['person_id' => $author, 'menu_group' => 'home']);

            $this->assertEqualsWithDelta(100.0, $own, 0.001, $key . ' for who reads their own');
            $this->assertEqualsWithDelta(350.0, $everyone, 0.001, $key . ' for who reads all');
        }
    }

    public function testGetShow_ShowsTheNoteThatSaysWhoseFiguresAnEmployeeOfSomeBranchesReads(): void
    {
        $this->createBranch('FR3', 'Third family branch');
        $second = $this->createBranch('FR4', 'Fourth family branch');

        $this->viewer($this->everyReportPermission(), [1, $second]);

        $response = $this->get('/accounting_reports/purchases');

        $response->assertSee('id="report_scope"');
        $response->assertSee('alert alert-warning');
    }

    public function testGetCsv_ShowsATextASpreadsheetWouldReadAsAFormulaAsText(): void
    {
        $item     = $this->item('0.00');
        $formula  = $this->supplier('=HYPERLINK("http://example.test","pay")');
        $markup   = $this->supplier('A<i>x</i>B');

        $this->assertGreaterThan(0, $this->buy([$this->purchaseLine($item, 0, '1', '100.00')], $formula));
        $this->assertGreaterThan(0, $this->buy([$this->purchaseLine($item, 0, '1', '50.00')], $markup));

        $this->viewer($this->everyReportPermission());

        $day = '?from=' . $this->today() . '&to=' . $this->today();
        $csv = $this->get('/accounting_reports/purchases/csv' . $day)->response()->getBody();

        $this->assertStringContainsString("'=HYPERLINK", $csv);
        $this->assertStringNotContainsString(",=HYPERLINK", $csv);
        $this->assertStringNotContainsString("\n=HYPERLINK", $csv);

        $screen = $this->get('/accounting_reports/purchases' . $day)->getBody();

        $this->assertStringNotContainsString('A<i>x</i>B', $screen);
        $this->assertStringContainsString('A&lt;i&gt;x&lt;/i&gt;B', $screen);
    }

    public function testGetShow_ExplainsAPeriodThatEndsBeforeItStartsAndRefusesItInTheFile(): void
    {
        $this->viewer($this->everyReportPermission());

        $this->get('/accounting_reports/purchases?from=2026-05-02&to=2026-05-01')->assertSee('id="report_error"');
        $this->get('/accounting_reports/purchases/csv?from=2026-05-02&to=2026-05-01')->assertStatus(422);
        $this->get('/accounting_reports/purchases?from=2026-13-45')->assertSee('id="report_error"');
    }

    public function testGetShow_PagesTheRowsOfAReportAndKeepsTheTotalsOnEveryPage(): void
    {
        for ($index = 0; $index < 45; $index++) {
            $this->customAccount('11', '11' . (900 + $index), 'Paged account ' . $index, false);
        }

        $total = (new ChartReport())->build($this->input())->rowCount();

        $this->assertGreaterThan(100, $total);

        $this->viewer($this->everyReportPermission());

        $first = $this->get('/accounting_reports/chart')->getBody();

        $this->assertStringContainsString('id="report_pages"', $first);
        $this->assertStringContainsString('rel="next"', $first);
        $this->assertSame(100, substr_count($first, '<tr><td'));

        $second = $this->get('/accounting_reports/chart?page=2')->getBody();

        $this->assertSame($total - 100, substr_count($second, '<tr><td'));
        $this->assertStringContainsString('rel="prev"', $second);
        $this->assertStringNotContainsString('rel="next"', $second);
        $this->assertSame($total - 100, substr_count($this->get('/accounting_reports/chart?page=999')->getBody(), '<tr><td'));
    }

    public function testGetShow_ShowsWhatTheReportIsSetBesideAndSaysWhenItDiffers(): void
    {
        $ids = $this->market();

        $this->viewer($this->everyReportPermission());

        $this->get('/accounting_reports/purchases?from=' . $this->today() . '&to=' . $this->today())->assertSee('data-status="ties"');

        $this->accountingDb()->table('accounting_journals')->where('source_type', 'purchase')->where('source_id', $ids['other'])->update(['status' => 'reversed']);

        $differs = $this->get('/accounting_reports/purchases?from=' . $this->today() . '&to=' . $this->today());

        $differs->assertSee('data-status="differs"');
        $differs->assertDontSee('data-status="ties"');
    }

    public function testGetShow_ShowsTheTiesToTheLedgerOfThePaymentReportsToWhoReadsEveryonesRecords(): void
    {
        $ids = $this->market();

        $this->payPurchase($ids['big'], '80.00', lang('Sales.cash'));
        $this->viewer($this->everyReportPermission());

        $day = '?from=' . $this->today() . '&to=' . $this->today();

        foreach (['supplier_payments' => [$day, 1, 'tie_treasury_payments'], 'purchase_payments' => ['', 1, 'tie_remaining_account'], 'financial_movements' => [$day, 2, 'tie_supplier_payments']] as $key => [$query, $ties, $label]) {
            $page = $this->get('/accounting_reports/' . $key . $query)->getBody();

            $this->assertSame($ties, substr_count($page, 'data-status="ties"'), $key . ' on the screen');
            $this->assertStringNotContainsString('data-status="differs"', $page, $key);
            $this->assertNotSame('', $this->csvCell($this->get('/accounting_reports/' . $key . '/csv' . $query), lang('Accounting_family_reports.' . $label), 1), $key . ' in the file');
        }
    }

    public function testGetShow_LeavesTheTiesToTheLedgerOutForWhoReadsOnlyTheirOwnRecords(): void
    {
        $ids = $this->market();

        $this->payPurchase($ids['big'], '80.00', lang('Sales.cash'));

        $reader = $this->viewer($this->everyReportPermission());

        (new RoleService())->setRecordScope($reader, 'own', null);

        $day = '?from=' . $this->today() . '&to=' . $this->today();

        foreach (['supplier_payments' => $day, 'purchase_payments' => ''] as $key => $query) {
            $page = $this->get('/accounting_reports/' . $key . $query)->getBody();

            $this->assertStringNotContainsString('data-status=', $page, $key);
        }

        $this->assertSame(1, substr_count($this->get('/accounting_reports/financial_movements' . $day)->getBody(), 'data-status="ties"'));
    }

    public function testGetShow_CutsTheFiltersDownToWhatTheRequestCarriesAndKeepsThemInTheFile(): void
    {
        $ids = $this->market();

        $this->viewer($this->everyReportPermission());

        $response = $this->get('/accounting_reports/purchases?from=' . $this->today() . '&to=' . $this->today() . '&supplier_id=' . $ids['second']);

        $response->assertSee('supplier_id=' . $ids['second']);
        $this->assertSame('100.0000', $this->csvCell($this->get('/accounting_reports/purchases/csv?from=' . $this->today() . '&to=' . $this->today() . '&supplier_id=' . $ids['second']), lang('Accounting_family_reports.total'), 4));
    }

    public function testGetCsv_GivesTheCostCenterReportWithItsGroupsTotalsAndTies(): void
    {
        $centers = new CostCenterService();
        $first   = $centers->create(['code' => 'A', 'name' => 'Alpha']);
        $second  = $centers->create(['code' => 'B', 'name' => 'Beta']);
        $input   = $this->journalInput('100.00');

        $input['date']                     = $this->today();
        $input['lines'][1]['distribution'] = [['cost_center_id' => $first, 'percent' => '60'], ['cost_center_id' => $second, 'percent' => '40']];

        (new JournalService())->postNew($input);

        $this->viewer($this->everyReportPermission());

        $day  = '?from=' . $this->today() . '&to=' . $this->today();
        $rows = $this->csvRows($this->get('/accounting_reports/cost_centers/csv' . $day));
        $text = static fn (string $key): string => lang('Accounting_family_reports.' . $key);

        $this->assertSame([$text('cost_centers_title')], $rows[0]);
        $this->assertSame([$text('col_cost_center'), $text('col_main_center'), $text('col_account'), $text('col_debit'), $text('col_credit'), $text('col_balance')], $rows[1]);
        $this->assertContains('A - Alpha', array_column($rows, 0));
        $this->assertContains('B - Beta', array_column($rows, 0));
        $this->assertContains($text('cost_center_none'), array_column($rows, 0));
        $this->assertEqualsWithDelta(100.0, $this->grandFromCsv($this->get('/accounting_reports/cost_centers/csv' . $day), 'cost_centers', 'credit'), 0.001);
        $this->assertEqualsWithDelta(100.0, $this->grandFromCsv($this->get('/accounting_reports/cost_centers/csv' . $day), 'cost_centers', 'debit'), 0.001);

        $ties = array_values(array_filter($rows, static fn (array $row): bool => in_array($row[0], [$text('tie_cc_debit'), $text('tie_cc_credit')], true)));

        $this->assertCount(2, $ties);

        foreach ($ties as $tie) {
            $this->assertSame($tie[1], $tie[2]);
            $this->assertEqualsWithDelta(0.0, (float) $tie[3], 0.00001);
        }
    }

    public function testGetShow_OpensTheCostCenterReportOnlyToWhoHoldsTheModuleOfTheReportsAndThePermissionOfTheCostCenters(): void
    {
        $this->viewer(['accounting_reports']);
        $this->get('/accounting_reports/cost_centers')->assertRedirect();

        $this->viewer(['accounting_cost_centers']);
        $this->get('/accounting_reports/cost_centers')->assertRedirect();

        $this->viewer(['accounting_reports', 'accounting_cost_centers']);
        $this->get('/accounting_reports/cost_centers')->assertStatus(200);
        $this->get('/accounting_reports/cost_centers/csv')->assertStatus(200);
    }

    public function testGetShow_CutsTheCostCenterReportDownToTheBranchesTheEmployeeWorksInWhateverTheRequestNames(): void
    {
        $branch = $this->createBranch('CCR', 'Cost center branch');
        $center = (new CostCenterService())->create(['code' => 'A', 'name' => 'Alpha']);
        $input  = $this->journalInput('100.00');

        $input['date']                           = $this->today();
        $input['lines'][1]['cost_center_id']     = $center;
        (new JournalService())->postNew($input);
        (new JournalService())->postNew($input + ['branch_id' => $branch]);

        $day = '?from=' . $this->today() . '&to=' . $this->today();

        $this->viewer($this->everyReportPermission(), [$branch]);

        $limited = $this->grandFromCsv($this->get('/accounting_reports/cost_centers/csv' . $day . '&branches[]=1&branches[]=' . $branch), 'cost_centers', 'credit');

        $this->viewer($this->everyReportPermission());

        $everyone = $this->grandFromCsv($this->get('/accounting_reports/cost_centers/csv' . $day), 'cost_centers', 'credit');

        $this->assertEqualsWithDelta(100.0, $limited, 0.001);
        $this->assertEqualsWithDelta(200.0, $everyone, 0.001);
    }
}
