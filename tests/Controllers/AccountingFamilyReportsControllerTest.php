<?php

namespace Tests\Controllers;

use App\Libraries\Accounting\AccountingGuard;
use App\Libraries\Accounting\FamilyReports\ChartReport;
use App\Libraries\Accounting\FamilyReports\FamilyReportCatalog;
use App\Libraries\Accounting\JournalService;
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

    public function testGetShow_CutsTheFiltersDownToWhatTheRequestCarriesAndKeepsThemInTheFile(): void
    {
        $ids = $this->market();

        $this->viewer($this->everyReportPermission());

        $response = $this->get('/accounting_reports/purchases?from=' . $this->today() . '&to=' . $this->today() . '&supplier_id=' . $ids['second']);

        $response->assertSee('supplier_id=' . $ids['second']);
        $this->assertSame('100.0000', $this->csvCell($this->get('/accounting_reports/purchases/csv?from=' . $this->today() . '&to=' . $this->today() . '&supplier_id=' . $ids['second']), lang('Accounting_family_reports.total'), 4));
    }
}
