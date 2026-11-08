<?php

namespace Tests\Filters;

use App\Filters\ReportExport;
use App\Libraries\Accounting\Export\ReportExporter;
use CodeIgniter\Exceptions\PageNotFoundException;
use CodeIgniter\HTTP\IncomingRequest;
use CodeIgniter\HTTP\Response;
use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\DatabaseTestTrait;
use CodeIgniter\Test\TestResponse;
use Config\Services;
use DOMDocument;
use DOMXPath;
use Tests\Support\AccountingControllerFixtureTrait;
use Tests\Support\ReportFixtureTrait;
use ZipArchive;

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
        $personId = $this->employeeWithGrants(['accounting_reports', ...$extra], $branchIds);

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
     * @return list<list<array{type: string, text: string}>>
     */
    private function workbookGrid(TestResponse $response): array
    {
        $path = tempnam(sys_get_temp_dir(), 'xlsxtest');

        file_put_contents($path, $response->response()->getBody());

        $zip = new ZipArchive();

        $this->assertTrue($zip->open($path));

        $sheet = $zip->getFromName('xl/worksheets/sheet1.xml');

        $zip->close();
        unlink($path);

        $this->assertIsString($sheet);

        $document = new DOMDocument();
        $document->loadXML($sheet);

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

    public function testAfter_GivesTheTrialBalanceAsAWorkbookWithTheRowsOfTheCsv(): void
    {
        $this->threeBranches();
        $this->viewer();

        $csv = $this->csvGrid($this->get('/accounting_reports/csv?type=balances&to=2026-10-31'));

        $xlsx = $this->get('/accounting_reports/xlsx?type=balances&to=2026-10-31');

        $xlsx->assertStatus(200);
        $this->assertSame('application/vnd.openxmlformats-officedocument.spreadsheetml.sheet', $xlsx->response()->getHeaderLine('Content-Type'));
        $this->assertStringContainsString('attachment; filename="trial_balance_balances_2026-10-31.xlsx"', $xlsx->response()->getHeaderLine('Content-Disposition'));

        $sheet = $this->workbookGrid($xlsx);

        foreach ($csv as $index => $row) {
            $texts = array_map(static fn (array $cell): string => $cell['text'], $sheet[$index] ?? []);

            $this->assertSame(array_values(array_filter($row, static fn (string $cell): bool => $cell !== '')), array_values($texts), 'Row ' . ($index + 1));
        }
    }

    public function testAfter_WritesAmountsAsNumbersAndNamesAsText(): void
    {
        $this->threeBranches();
        $this->viewer();

        $sheet = $this->workbookGrid($this->get('/accounting_reports/xlsx?type=balances&to=2026-10-31'));

        $cash = null;

        foreach ($sheet as $row) {
            if (($row[1]['text'] ?? '') === lang('Accounting_reports.cash') || ($row[0]['text'] ?? '') === $this->codeOf('cash')) {
                $cash = $row;
            }
        }

        $this->assertNotNull($cash);
        $this->assertSame(['type' => 'number', 'text' => '7777'], array_map(static fn (array $cell): array => $cell, [2 => $cash[2]])[2] === null ? [] : ['type' => $cash[2]['type'], 'text' => (string) (float) $cash[2]['text']]);
        $this->assertSame('text', $cash[1]['type'], 'The name of the account is text');
    }

    public function testAfter_GivesTheTrialBalanceAsAPdfWithTheTitleAndTheAmounts(): void
    {
        $this->threeBranches();
        $this->viewer();

        $response = $this->get('/accounting_reports/pdf?type=balances&to=2026-10-31');

        $response->assertStatus(200);
        $this->assertSame('application/pdf', $response->response()->getHeaderLine('Content-Type'));
        $this->assertStringContainsString('attachment; filename="trial_balance_balances_2026-10-31.pdf"', $response->response()->getHeaderLine('Content-Disposition'));
        $this->assertStringStartsWith('%PDF-', $response->response()->getBody());

        $text = $this->pdfText($response);

        $this->assertStringContainsString(lang('Accounting_reports.trial_balance'), $text);
        $this->assertStringContainsString('7777.0000', $text);
    }

    private function codeOf(string $systemKey): string
    {
        return (string) $this->accountingDb()->table('accounting_accounts')->where('account_id', $this->accountId($systemKey))->get()->getRow()->code;
    }

    private function pdfText(TestResponse $response): string
    {
        if (trim((string) shell_exec('command -v pdftotext')) === '') {
            $this->markTestSkipped('pdftotext is not installed');
        }

        $path = tempnam(sys_get_temp_dir(), 'pdftest');

        file_put_contents($path, $response->response()->getBody());

        $text = (string) shell_exec('pdftotext -layout ' . escapeshellarg($path) . ' - 2>/dev/null');

        unlink($path);

        return $text;
    }
}
