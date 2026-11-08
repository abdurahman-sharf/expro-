<?php

namespace Tests\Filters;

use App\Filters\ReportExport;
use App\Libraries\Accounting\Export\ReportExporter;
use App\Libraries\Accounting\Export\ReportSheet;
use App\Libraries\Accounting\FamilyReports\FamilyReportCatalog;
use CodeIgniter\Exceptions\PageNotFoundException;
use CodeIgniter\HTTP\Response;
use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\DatabaseTestTrait;
use CodeIgniter\Test\TestResponse;
use Config\App;
use Config\Services;
use DOMDocument;
use DOMXPath;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Support\AccountingControllerFixtureTrait;
use Tests\Support\ReportFixtureTrait;
use Tests\Support\WorkbookPartsTrait;

/**
 * The files of the reports in the formats other than CSV: each is the same report the CSV address gives,
 * run for the same employee with the same branches, so the rows and the totals of the three formats are
 * the same and nothing outside what the employee reads is in any of them.
 */
class ReportExportTest extends CIUnitTestCase
{
    use DatabaseTestTrait;
    use AccountingControllerFixtureTrait;
    use ReportFixtureTrait;
    use WorkbookPartsTrait;

    protected $migrate     = true;
    protected $migrateOnce = true;
    protected $refresh     = false;
    protected $namespace   = null;

    private int $second;
    private int $third;

    protected function setUp(): void
    {
        parent::setUp();

        $this->resetBranches();
        $this->prepareAccounting();

        $this->second = $this->createBranch('T2', 'Second branch');
        $this->third  = $this->createBranch('T3', 'Third branch');
    }

    protected function tearDown(): void
    {
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
        $personId = $this->employeeWithGrants([
            'accounting_reports', 'accounting_treasuries', 'accounting_journals', 'acc_journals_all', 'accounting_chart', 'receipt_vouchers',
            'reports_sales', 'reports_items', 'reports_categories', 'reports_customers', 'reports_employees', 'reports_payments',
            'reports_receivings', 'reports_suppliers', 'reports_inventory', 'reports_expenses_categories', ...$extra,
        ], $branchIds);

        $this->actAs($personId);

        return $personId;
    }

    private function threeBranches(): void
    {
        $this->cashSale('2026-10-05', 1, '1111.00');
        $this->cashSale('2026-10-05', $this->second, '2222.00');
        $this->cashSale('2026-10-05', $this->third, '4444.00');
    }

    /**
     * @return list<list<string>>
     */
    private function csvGrid(TestResponse $response): array
    {
        $body = $response->response()->getBody();

        $this->assertStringStartsWith("\xEF\xBB\xBF", $body);

        $handle = fopen('php://temp', 'r+b');
        fwrite($handle, substr($body, 3));
        rewind($handle);

        $rows = [];

        while (($row = fgetcsv($handle, 0, ',', '"', '')) !== false) {
            $rows[] = $row === [null] ? [] : $row;
        }

        return $rows;
    }

    /**
     * @return array<int, array<int, array{type: string, text: string}>> The cells of each row of the sheet, by row and column.
     */
    private function workbookGrid(string $workbook): array
    {
        $document = new DOMDocument();
        $document->loadXML($this->workbookParts($workbook)['xl/worksheets/sheet1.xml']);

        $xpath = new DOMXPath($document);
        $xpath->registerNamespace('m', 'http://schemas.openxmlformats.org/spreadsheetml/2006/main');

        $rows = [];

        foreach ($xpath->query('//m:sheetData/m:row') as $row) {
            $cells = [];

            foreach ($xpath->query('m:c', $row) as $cell) {
                preg_match('/^([A-Z]+)/', $cell->getAttribute('r'), $column);

                $index = 0;

                foreach (str_split($column[1]) as $letter) {
                    $index = $index * 26 + ord($letter) - 64;
                }

                $cells[$index - 1] = ['type' => $cell->getAttribute('t') === 'inlineStr' ? 'text' : 'number', 'text' => $cell->textContent];
            }

            $rows[(int) $row->getAttribute('r') - 1] = $cells;
        }

        return $rows;
    }

    private function workbookOf(string $address): string
    {
        $response = $this->get($address);

        $response->assertStatus(200);
        $this->assertSame('application/vnd.openxmlformats-officedocument.spreadsheetml.sheet', $response->response()->getHeaderLine('Content-Type'));

        return $response->response()->getBody();
    }

    private function pdfTextOf(string $address): string
    {
        $response = $this->get($address);

        $response->assertStatus(200);
        $this->assertSame('application/pdf', $response->response()->getHeaderLine('Content-Type'));
        $this->assertStringStartsWith('%PDF-', $response->response()->getBody());

        if (trim((string) shell_exec('command -v pdftotext')) === '') {
            $this->markTestSkipped('pdftotext is not installed');
        }

        $path = tempnam(sys_get_temp_dir(), 'pdftest');

        file_put_contents($path, $response->response()->getBody());

        $text = (string) shell_exec('pdftotext -layout ' . escapeshellarg($path) . ' - 2>/dev/null');

        unlink($path);

        return $text;
    }

    private function codeOf(string $systemKey): string
    {
        return (string) $this->accountingDb()->table('accounting_accounts')->where('account_id', $this->accountId($systemKey))->get()->getRow()->code;
    }

    private function address(string $template): string
    {
        return strtr($template, ['{cash}' => (string) $this->accountId('cash')]);
    }

    /**
     * Each report that has a file: its CSV address with the filters it is asked with, and whether the
     * figures of the third branch (4,444) would show in it for someone who works in it.
     *
     * @return array<string, array{string, bool}>
     */
    public static function reports(): array
    {
        $family = static fn (string $key, string $query = ''): array => ['/accounting_reports/' . $key . '/csv' . $query, $key === 'journals'];

        return [
            'trial balance'            => ['/accounting_reports/csv?type=balances&to=2026-10-31', true],
            'trial balance by movement' => ['/accounting_reports/csv?type=movement&from=2026-10-01&to=2026-10-31', true],
            'account statement'        => ['/accounting_reports/ledger/csv?account={cash}&to=2026-10-31', true],
            'account statement blocks' => ['/accounting_reports/ledger/csv?layout=accounts&account={cash}&to=2026-10-31', true],
            'income statement'         => ['/accounting_statements/income_statement/csv?to=2026-10-31&level=5', true],
            'balance sheet'            => ['/accounting_statements/balance_sheet/csv?to=2026-10-31&level=5', true],
            'profit and loss'          => ['/accounting_statements/profit_loss/csv?from=2026-10-01&to=2026-10-31', true],
            'cash flow'                => ['/accounting_statements/cash_flow/csv?from=2026-10-01&to=2026-10-31', false],
            'tax report'               => ['/accounting_reports/tax/csv?from=2026-10-01&to=2026-10-31', false],
            'inventory valuation'      => ['/accounting_reports/inventory_valuation/csv', false],
            'revenue'                  => ['/accounting_reports/revenue/csv?show=1&group=day&from=2026-10-01&to=2026-10-31', false],
            'clients sales'            => ['/accounting_reports/clients_sales/csv?show=1&from=2026-10-01&to=2026-10-31', false],
            'item sales'               => ['/accounting_reports/item_sales/csv?show=1&group=item&from=2026-10-01&to=2026-10-31', false],
            'profit'                   => ['/accounting_reports/profit/csv?show=1&group=day&from=2026-10-01&to=2026-10-31', false],
            'item profit'              => ['/accounting_reports/item_profit/csv?show=1&group=item&from=2026-10-01&to=2026-10-31', false],
            'payments'                 => ['/accounting_reports/payments/csv?show=1&group=customer&from=2026-10-01&to=2026-10-31', false],
            'advance payments'         => ['/accounting_reports/advance_payments/csv?show=1&from=2026-10-01&to=2026-10-31', false],
            'net position'             => ['/accounting_reports/net_position/csv?show=1&to=2026-10-31', false],
            'invoice returns'          => ['/accounting_reports/invoice_returns/csv?show=1&to=2026-10-31', false],
            'journals'                 => $family('journals', '?from=2026-10-01&to=2026-10-31'),
            'journal movements'        => $family('journal_movements', '?from=2026-10-01&to=2026-10-31'),
            'chart of accounts'        => $family('chart', '?balances=1&to=2026-10-31'),
            'supplier balances'        => $family('supplier_balances', '?from=2026-10-01&to=2026-10-31'),
            'stock balance'            => $family('stock_balance'),
            'balances between branches' => ['/accounting_treasuries/inter_branch/csv?as_of=2026-10-31', false],
        ];
    }

    #[DataProvider('reports')]
    public function testAfter_GivesEveryReportAsAWorkbookWithTheRowsAndTheNumbersOfItsCsv(string $csvAddress, bool $showsBranches): void
    {
        $this->threeBranches();

        foreach ([null, [1, $this->second]] as $branches) {
            $this->viewer($branches);

            $csv  = $this->csvGrid($this->get($this->address($csvAddress)));
            $xlsx = $this->workbookGrid($this->workbookOf(str_replace('/csv', '/xlsx', $this->address($csvAddress))));

            $this->assertNotSame([], $csv, 'The report has rows');

            foreach ($csv as $index => $row) {
                $cells = $xlsx[$index] ?? [];

                foreach ($row as $column => $text) {
                    if ($text === '') {
                        $this->assertArrayNotHasKey($column, $cells, 'Row ' . ($index + 1) . ' column ' . ($column + 1) . ' is empty');

                        continue;
                    }

                    $this->assertArrayHasKey($column, $cells, 'Row ' . ($index + 1) . ' column ' . ($column + 1));

                    $number = ReportSheet::number($text);

                    $this->assertSame($number === null ? 'text' : 'number', $cells[$column]['type'], 'Row ' . ($index + 1) . ' column ' . ($column + 1) . ' ' . $text);
                    $this->assertSame($number === null ? $text : $number['value'], $cells[$column]['text']);
                }
            }
        }
    }

    #[DataProvider('reports')]
    public function testAfter_GivesEveryReportAsAPdfWithItsTitleAndNothingOutsideTheScopeOfALimitedEmployee(string $csvAddress, bool $showsBranches): void
    {
        $this->threeBranches();
        $this->viewer([1, $this->second]);

        $address = str_replace('/csv', '/pdf', $this->address($csvAddress));
        $text    = $this->pdfTextOf($address);

        $this->assertNotSame('', trim($text));

        if ($showsBranches) {
            $this->assertStringNotContainsString('4444', $text, 'The third branch is not in the file');
            $this->assertStringNotContainsString('7777', $text, 'The company is not in the file');
        }
    }

    /**
     * @return array<string, array{string, bool}>
     */
    public static function reportsWithFiguresOfBranches(): array
    {
        return array_filter(self::reports(), static fn (array $report): bool => $report[1]);
    }

    #[DataProvider('reportsWithFiguresOfBranches')]
    public function testAfter_LeavesTheOtherBranchesOutOfTheWorkbookOfALimitedEmployeeWhateverTheRequestAsks(string $csvAddress, bool $showsBranches): void
    {
        $this->threeBranches();
        $this->viewer([1, $this->second]);

        $address = str_replace('/csv', '/xlsx', $this->address($csvAddress)) . '&branches[]=1&branches[]=' . $this->second . '&branches[]=' . $this->third;
        $sheet   = $this->workbookGrid($this->workbookOf($address));
        $flat    = implode(' ', array_map(static fn (array $row): string => implode(' ', array_column($row, 'text')), $sheet));

        $this->assertStringNotContainsString('4444', $flat);
        $this->assertStringNotContainsString('7777', $flat);
        $this->assertMatchesRegularExpression('/\b(1111|2222|3333)\b/', $flat, 'The branches of the employee are in the file');
    }

    #[DataProvider('reports')]
    public function testAfter_RedirectsAUserWithoutTheModuleOfTheScreenInEveryFormat(string $csvAddress, bool $showsBranches): void
    {
        $this->actAs($this->employeeWithGrants(['accounting_payables']));

        foreach (['csv', 'xlsx', 'pdf'] as $format) {
            $response = $this->get(str_replace('/csv', '/' . $format, $this->address($csvAddress)));

            $response->assertRedirect();
        }
    }

    #[DataProvider('reports')]
    public function testAfter_IsNotFoundWhileAccountingIsOffInEveryFormat(string $csvAddress, bool $showsBranches): void
    {
        $this->viewer();
        $this->prepareAccounting(false);

        foreach (['xlsx', 'pdf'] as $format) {
            try {
                $this->get(str_replace('/csv', '/' . $format, $this->address($csvAddress)));

                $this->fail('A file is not given while accounting is off');
            } catch (PageNotFoundException) {
                $this->assertTrue(true);
            }
        }
    }

    private function filterOf(string $route): string
    {
        $filter = (array) (service('routes')->getRoutesOptions($route, 'GET')['filter'] ?? []);

        return (string) ($filter[0] ?? '');
    }

    public function testRoutes_GiveEveryCsvAddressItsExcelAndPdfAddressWithATitleThatReadsInWords(): void
    {
        $routes = service('routes');
        $get    = array_keys($routes->getRoutes('GET', false));
        $csv    = array_filter($get, static fn (string $route): bool => str_ends_with($route, '/csv'));

        $this->assertGreaterThan(30, count($csv));

        foreach ($csv as $route) {
            foreach (['xlsx', 'pdf'] as $format) {
                $sibling = substr($route, 0, -3) . $format;

                $this->assertContains($sibling, $get, $route . ' has no ' . $format . ' address');

                $filter = $this->filterOf($sibling);

                $this->assertSame(1, preg_match('/^reportexport:' . $format . '(,([A-Za-z_]+)\.([a-z_]+))?$/', $filter, $match), $sibling . ' is not given by the filter of the files');

                if (isset($match[1])) {
                    $this->assertNotSame($match[2] . '.' . $match[3], lang($match[2] . '.' . $match[3]), 'The title of ' . $sibling . ' is not a language line');
                }
            }
        }
    }

    public function testRoutes_GiveTheFamilyReportsTheTitleOfTheirOwnLanguageLine(): void
    {
        $this->assertSame('reportexport:pdf,Accounting_family_reports.' . FamilyReportCatalog::titleKey('supplier_balances'), $this->filterOf('accounting_reports/supplier_balances/pdf'));
    }

    public function testAfter_NamesTheFileLikeTheCsvWithTheExtensionOfTheFormat(): void
    {
        $this->threeBranches();
        $this->viewer();

        foreach (['xlsx', 'pdf'] as $format) {
            $response = $this->get('/accounting_reports/' . $format . '?type=balances&to=2026-10-31');

            $this->assertStringContainsString('attachment; filename="trial_balance_balances_2026-10-31.' . $format . '"', $response->response()->getHeaderLine('Content-Disposition'));
        }
    }

    public function testAfter_GivesThePdfTheTitleTheDateAndTheAmountsOfTheScreen(): void
    {
        $this->threeBranches();
        $this->viewer();

        $text = $this->pdfTextOf('/accounting_reports/pdf?type=balances&to=2026-10-31');

        $this->assertStringContainsString(lang('Accounting_reports.trial_balance'), $text);
        $this->assertStringContainsString('7777.0000', $text);
        $this->assertStringContainsString($this->codeOf('cash'), $text);
    }

    public function testAfter_WritesAmountsAsNumbersAndNamesAsTextInTheWorkbook(): void
    {
        $this->threeBranches();
        $this->viewer();

        $sheet = $this->workbookGrid($this->workbookOf('/accounting_reports/xlsx?type=balances&to=2026-10-31'));
        $cash  = array_values(array_filter($sheet, fn (array $row): bool => ($row[0]['text'] ?? '') === $this->codeOf('cash')))[0] ?? null;

        $this->assertNotNull($cash);
        $this->assertSame('number', $cash[2]['type']);
        $this->assertSame('7777.0000', $cash[2]['text']);
        $this->assertSame('text', $cash[1]['type']);
    }

    public function testAfter_ShowsAnAccountNamedLikeAFormulaAsTextInTheWorkbookAndNeverAsAFormula(): void
    {
        $this->threeBranches();
        $this->accountingDb()->table('accounting_accounts')->where('account_id', $this->accountId('cash'))->update(['name' => '=HYPERLINK("http://evil","x")']);
        $this->viewer();

        $workbook = $this->workbookOf('/accounting_reports/xlsx?type=balances&to=2026-10-31');
        $sheet    = $this->workbookGrid($workbook);
        $names    = array_column(array_column($sheet, 1), 'text');

        $this->assertContains("'=HYPERLINK(\"http://evil\",\"x\")", $names);
        $this->assertNotContains('=HYPERLINK("http://evil","x")', $names);

        $this->assertStringNotContainsString('<f>', $this->workbookParts($workbook)['xl/worksheets/sheet1.xml']);
    }

    public function testAfter_PassesTheRefusalOfTheReportThroughInWordsInEveryFormat(): void
    {
        $this->viewer();

        foreach (['xlsx', 'pdf'] as $format) {
            $response = $this->get('/accounting_reports/' . $format . '?type=movement&from=2026-10-31&to=2026-10-01');

            $response->assertStatus(422);
            $this->assertSame(lang('Accounting.error_report_period_invalid'), $response->response()->getBody());
        }
    }

    public function testAfter_AppliesTheOrientationAndTheSizeOfTheTypeAskedToThePdf(): void
    {
        $this->threeBranches();
        $this->viewer();

        $narrow = $this->get('/accounting_reports/pdf?type=balances&to=2026-10-31')->response()->getBody();
        $wide   = $this->get('/accounting_reports/pdf?type=balances&to=2026-10-31&orientation=landscape')->response()->getBody();
        $bad    = $this->get('/accounting_reports/pdf?type=balances&to=2026-10-31&orientation=sideways&font_size=999')->response()->getBody();

        $this->assertMatchesRegularExpression('/MediaBox \[0 0 595\.2\d* 841\.8\d*\]/', $narrow);
        $this->assertMatchesRegularExpression('/MediaBox \[0 0 841\.8\d* 595\.2\d*\]/', $wide);
        $this->assertMatchesRegularExpression('/MediaBox \[0 0 595\.2\d* 841\.8\d*\]/', $bad, 'An orientation that is not one of the two is the default');
    }

    public function testAfter_WritesTheWorkbookRightToLeftAndThePdfInArabicForAnEmployeeWhoUsesArabic(): void
    {
        $this->threeBranches();
        $person = $this->viewer();

        $this->accountingDb()->table('employees')->where('person_id', $person)->update(['language_code' => 'ar-EG', 'language' => 'arabic-egypt']);

        try {
            $sheet = $this->workbookParts($this->workbookOf('/accounting_reports/xlsx?type=balances&to=2026-10-31'))['xl/worksheets/sheet1.xml'];

            $this->assertStringContainsString('rightToLeft="1"', $sheet);
            $this->assertStringContainsString('الحساب', $sheet);

            $pdf = $this->get('/accounting_reports/pdf?type=balances&to=2026-10-31');

            $pdf->assertStatus(200);
            $this->assertStringContainsString('IBMPlexSansArabic', $pdf->response()->getBody());

            $text = $this->pdfTextOf('/accounting_reports/pdf?type=balances&to=2026-10-31');

            $this->assertStringContainsString('7777.0000', $text);
        } finally {
            Services::language()->setLocale('en');
        }
    }

    public function testAfter_LeavesAWorkbookOutOfAFileThatIsNotACsv(): void
    {
        $response = new Response(new App());
        $response->setStatusCode(200)->setContentType('application/json')->setBody('{"a":1}');

        $after = (new ReportExport())->after(service('request'), $response, ['xlsx', 'Accounting_reports.trial_balance']);

        $this->assertSame('{"a":1}', $after->getBody());
        $this->assertStringContainsString('application/json', $after->getHeaderLine('Content-Type'));
    }

    public function testAfter_LeavesAnErrorResponseAsTheControllerGaveIt(): void
    {
        $response = new Response(new App());
        $response->setStatusCode(422)->setContentType('text/csv', 'UTF-8')->setBody('refused');

        $after = (new ReportExport())->after(service('request'), $response, ['pdf', 'Accounting_reports.trial_balance']);

        $this->assertSame(422, $after->getStatusCode());
        $this->assertSame('refused', $after->getBody());
    }

    public function testAfter_GivesTheCsvBackWhenTheFormatIsCsvOrUnknown(): void
    {
        foreach ([['csv'], ['xls'], []] as $arguments) {
            $response = new Response(new App());
            $response->setStatusCode(200)->setContentType('text/csv', 'UTF-8')->setBody("A,B\n1,2\n");

            $after = (new ReportExport())->after(service('request'), $response, $arguments);

            $this->assertSame("A,B\n1,2\n", $after->getBody());
            $this->assertStringContainsString('text/csv', $after->getHeaderLine('Content-Type'));
        }
    }

    private function csvResponse(int $rows): Response
    {
        $text = "A,B\n" . str_repeat("1,2.50\n", $rows - 1);

        $response = new Response(new App());
        $response->setStatusCode(200)->setContentType('text/csv', 'UTF-8')->setHeader('Content-Disposition', 'attachment; filename="big.csv"')->setBody("\xEF\xBB\xBF" . $text);

        return $response;
    }

    public function testAfter_RefusesAWorkbookOfMoreRowsThanTheLimitByNameAndGivesNoFile(): void
    {
        $after = (new ReportExport())->after(service('request'), $this->csvResponse(ReportExporter::MAX_ROWS + 1), ['xlsx', 'Accounting_reports.trial_balance']);

        $this->assertSame(422, $after->getStatusCode());
        $this->assertSame(lang('Accounting.error_report_file_too_large', [ReportExporter::MAX_ROWS]), $after->getBody());
        $this->assertStringContainsString('text/plain', $after->getHeaderLine('Content-Type'));
        $this->assertSame('', $after->getHeaderLine('Content-Disposition'));
    }

    public function testAfter_GivesAWorkbookOfExactlyTheLimitOfRows(): void
    {
        $after = (new ReportExport())->after(service('request'), $this->csvResponse(ReportExporter::MAX_ROWS), ['xlsx', 'Accounting_reports.trial_balance']);

        $this->assertSame(200, $after->getStatusCode());
        $this->assertStringStartsWith('PK', $after->getBody());
        $this->assertStringContainsString('attachment; filename="big.xlsx"', $after->getHeaderLine('Content-Disposition'));
    }

    public function testAfter_RefusesAPdfOfMoreRowsThanItsLimitByName(): void
    {
        $after = (new ReportExport())->after(service('request'), $this->csvResponse(ReportExporter::MAX_PDF_ROWS + 1), ['pdf', 'Accounting_reports.trial_balance']);

        $this->assertSame(422, $after->getStatusCode());
        $this->assertSame(lang('Accounting.error_report_file_too_large', [ReportExporter::MAX_PDF_ROWS]), $after->getBody());
    }
}
