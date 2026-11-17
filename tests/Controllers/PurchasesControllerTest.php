<?php

namespace Tests\Controllers;

use App\Libraries\Organization\BranchGuard;
use App\Libraries\Roles\RoleService;
use CodeIgniter\Exceptions\PageNotFoundException;
use CodeIgniter\Test\FeatureTestTrait;
use Tests\Support\CsvResponseTrait;
use Tests\Support\FamilyReportTestCase;

/**
 * The screen of the purchase invoices: who may open it and what each address gives, in the list, the totals, a
 * row, the suppliers to pick and the files.
 *
 * @internal
 */
final class PurchasesControllerTest extends FamilyReportTestCase
{
    use CsvResponseTrait;
    use FeatureTestTrait;

    protected function tearDown(): void
    {
        $this->accountingDb()->table('document_numbers')->where('source_table', 'receivings')->delete();

        parent::tearDown();
    }

    /**
     * @param list<string>   $extra
     * @param list<int>|null $branchIds
     */
    private function reader(array $extra = [], ?array $branchIds = null): int
    {
        $personId = $this->employeeWithGrants(['reports', 'reports_receivings', ...$extra], $branchIds);

        $this->withSession(['person_id' => $personId, 'menu_group' => 'home']);

        return $personId;
    }

    /**
     * @return array<string, mixed>
     */
    private function json(string $address): array
    {
        return json_decode($this->get($address)->getJSON(), true);
    }

    public function testGetIndex_RedirectsWhoLacksThePermissionOrTheModule(): void
    {
        $this->withSession(['person_id' => $this->employeeWithGrants(['reports', 'reports_sales']), 'menu_group' => 'home']);
        $this->get('/purchases')->assertRedirect();

        $this->withSession(['person_id' => $this->employeeWithGrants(['accounting_payables']), 'menu_group' => 'home']);
        $this->get('/purchases')->assertRedirect();
    }

    public function testGetIndex_ShowsTheFiltersTheTableAndTheLinkOfTheOldReport(): void
    {
        $this->reader();

        $response = $this->get('/purchases');
        $body     = html_entity_decode($response->getBody());

        $response->assertStatus(200);
        $this->assertStringContainsString('<h1 class="app-title">' . lang('Purchases.title') . '</h1>', $body);
        $this->assertStringContainsString('id="purchase_filters"', $body);
        $this->assertStringContainsString('reports/detailed_receivings', $body);

        foreach (['f_supplier_name', 'f_status', 'f_payment', 'daterangepicker', 'page_size', 'export_csv', 'export_xlsx', 'export_pdf', 'purchase_sums'] as $id) {
            $this->assertStringContainsString('id="' . $id . '"', $body);
        }
    }

    public function testGetIndex_OffersTheAddButtonOutsideTheToolbarOnlyToWhoHoldsTheReceivings(): void
    {
        $this->reader();
        $this->assertStringNotContainsString('id="add_purchase"', $this->get('/purchases')->getBody());

        $this->reader(['receivings', 'receivings_stock']);
        $body = $this->get('/purchases')->getBody();

        $this->assertStringContainsString('id="add_purchase"', $body);
        $this->assertLessThan(strpos($body, 'id="toolbar"'), strpos($body, 'id="add_purchase"'));
    }

    public function testGetIndex_OffersTheBranchFilterOnlyToWhoWorksInMoreThanOneBranch(): void
    {
        $second = $this->createBranch('B2', 'Second branch');

        $this->reader([], [1]);
        $this->assertStringNotContainsString('id="f_branches"', $this->get('/purchases')->getBody());

        $this->reader([], [1, $second]);
        $this->assertStringContainsString('id="f_branches"', $this->get('/purchases')->getBody());
    }

    public function testGetIndex_RestoresTheFiltersFromTheQuery(): void
    {
        $this->reader();

        $body = $this->get('/purchases?date_from=2026-01-05&date_to=2026-01-31&status=partly_returned&payment=unpaid')->getBody();

        $this->assertStringContainsString('"2026-01-05"', $body);
        $this->assertStringContainsString('"2026-01-31"', $body);
        $this->assertMatchesRegularExpression('/<option value="partly_returned" selected>/', $body);
        $this->assertMatchesRegularExpression('/<option value="unpaid" selected>/', $body);
    }

    public function testGetSearch_AnswersForbiddenWhoHoldsTheModuleButNotThePermission(): void
    {
        $this->withSession(['person_id' => $this->employeeWithGrants(['reports', 'reports_sales']), 'menu_group' => 'home']);

        $response = $this->get('/purchases/search');

        $response->assertStatus(403);
        $this->assertFalse($this->json('/purchases/search')['success']);
        $this->assertFalse($this->json('/purchases/sums')['success']);
        $this->assertFalse($this->json('/purchases/suppliers')['success'] ?? false);
    }

    public function testGetSearch_PagesTheDocumentsAndEscapesWhatTheyCarry(): void
    {
        $ids = $this->market();
        $this->reader(['receivings', 'receivings_stock']);

        $this->accountingDb()->table('document_numbers')->insert(['company_id' => 1, 'doc_type' => 'purchase', 'source_table' => 'receivings', 'source_id' => $ids['big'], 'number' => '<img src=x onerror=alert(1)>', 'sequence_number' => 1]);

        $all  = $this->json('/purchases/search?limit=2&offset=0&sort=receiving_id&order=asc');
        $next = $this->json('/purchases/search?limit=2&offset=2&sort=receiving_id&order=asc');

        $this->assertSame(4, $all['total']);
        $this->assertCount(2, $all['rows']);
        $this->assertCount(2, $next['rows']);
        $this->assertStringContainsString('&lt;img src=x onerror=alert(1)&gt;', $all['rows'][0]['number']);
        $this->assertStringNotContainsString('<img', $all['rows'][0]['number']);
        $this->assertSame($ids['big'], $all['rows'][0]['receiving_id']);
    }

    public function testGetSearch_GivesTheOptionsOnlyToWhoHoldsTheReceivings(): void
    {
        $this->market();

        $this->reader();
        $this->assertSame('', $this->json('/purchases/search')['rows'][0]['options']);

        $this->reader(['receivings', 'receivings_stock']);
        $row = $this->json('/purchases/search?sort=receiving_id&order=asc')['rows'][0];

        $options = html_entity_decode($row['options']);

        $this->assertStringContainsString('data-bs-toggle="dropdown"', $options);
        $this->assertStringContainsString('receivings/edit/' . $row['receiving_id'], $options);
        $this->assertStringContainsString('data-purchase-action="delete"', $options);
    }

    public function testGetSearch_OffersTheDebitNoteOnlyForAPurchaseThatCanStillBeReturned(): void
    {
        $ids = $this->market();
        $this->reader(['receivings', 'receivings_stock']);

        $rows = array_column($this->json('/purchases/search')['rows'], null, 'receiving_id');

        $this->assertStringContainsString('data-purchase-action="debit_note"', $rows[$ids['big']]['options']);
        $this->assertStringNotContainsString('data-purchase-action="debit_note"', $rows[$ids['note']]['options']);
    }

    public function testGetSearch_KeepsTheDocumentsOfAnotherEmployeeFromAnOwnOnlyReader(): void
    {
        $this->market();
        $author = $this->employeeId;
        $item   = $this->item('0.00');

        $this->workInEveryBranch();

        $other = $this->employeeId;
        $this->assertGreaterThan(0, $this->buy([$this->purchaseLine($item, 0, '1', '60.00')]));

        $this->withSession(['person_id' => $author, 'menu_group' => 'home']);
        $this->accountingDb()->table('grants')->ignore(true)->insertBatch([['permission_id' => 'reports', 'person_id' => $author, 'menu_group' => 'home'], ['permission_id' => 'reports_receivings', 'person_id' => $author, 'menu_group' => 'home'], ['permission_id' => BranchGuard::ALL_BRANCHES, 'person_id' => $author, 'menu_group' => 'home']]);
        (new RoleService())->setRecordScope($author, 'own', null);

        $this->assertSame(4, $this->json('/purchases/search')['total']);

        $this->withSession(['person_id' => $other, 'menu_group' => 'home']);
        $this->accountingDb()->table('grants')->ignore(true)->insertBatch([['permission_id' => 'reports', 'person_id' => $other, 'menu_group' => 'home'], ['permission_id' => 'reports_receivings', 'person_id' => $other, 'menu_group' => 'home'], ['permission_id' => BranchGuard::ALL_BRANCHES, 'person_id' => $other, 'menu_group' => 'home']]);

        $this->assertSame(5, $this->json('/purchases/search')['total']);
    }

    public function testGetSums_GivesTheTotalsOfTheWholeFilterAndNotOfThePage(): void
    {
        $this->market();
        $this->reader();

        $response = $this->json('/purchases/sums');

        $this->assertTrue($response['success']);
        $this->assertStringContainsString('purchase-sums', $response['html']);
        $this->assertStringContainsString('395.00', $response['html']);
        $this->assertStringContainsString('50.00', $response['html']);
    }

    public function testGetRow_AnswersTheSameNotFoundForAForeignAndAMissingDocument(): void
    {
        $ids = $this->market();
        $this->reader([], [$this->createBranch('B9', 'Another branch')]);

        $foreign = $this->get('/purchases/row/' . $ids['big']);
        $missing = $this->get('/purchases/row/987654');

        $foreign->assertStatus(404);
        $missing->assertStatus(404);
        $this->assertSame($foreign->getJSON(), $missing->getJSON());
    }

    public function testGetRow_GivesTheRowOfADocumentTheReaderMaySee(): void
    {
        $ids = $this->market();
        $this->reader(['receivings', 'receivings_stock']);

        $row = $this->json('/purchases/row/' . $ids['small']);

        $this->assertSame($ids['small'], $row['receiving_id']);
        $this->assertStringContainsString('Partly', $row['purchase_status'] . lang('Purchases.status_partly_returned'));
    }

    public function testGetSuppliers_OffersOnlyTheSuppliersOfTheDocumentsTheReaderMaySee(): void
    {
        $ids = $this->market();
        $this->reader();

        $this->assertSame([['id' => $ids['second'], 'name' => 'Najd Trading']], $this->json('/purchases/suppliers?term=Najd'));
        $this->assertSame([], $this->json('/purchases/suppliers?term=%25'));
    }

    public function testGetCsv_GivesTheTitleTheHeadingsTheRowsAndTheTotalsOfTheFilter(): void
    {
        $ids = $this->market();
        $this->reader();

        $rows = $this->csvRows($this->get('/purchases/csv?sort=receiving_id&order=asc'));

        $this->assertSame([lang('Purchases.title')], $rows[0]);
        $this->assertSame(lang('Purchases.col_number'), $rows[2][0]);
        $this->assertContains('RECV ' . $ids['big'], array_column($rows, 0));
        $this->assertContains('-50.00', array_column($rows, 7));
        $this->assertSame(lang('Purchases.sums_net'), $rows[count($rows) - 1][0]);
        $this->assertSame('395.00', $rows[count($rows) - 1][1]);
    }

    public function testGetCsv_FollowsTheFiltersAndNeutralisesWhatASpreadsheetWouldRunAsAFormula(): void
    {
        $ids = $this->market();
        $this->reader();

        $this->accountingDb()->table('receivings')->where('receiving_id', $ids['big'])->update(['reference' => '=HYPERLINK("a")']);

        $all  = $this->csvRows($this->get('/purchases/csv'));
        $only = $this->csvRows($this->get('/purchases/csv?status=return'));

        $this->assertContains("'=HYPERLINK(\"a\")", array_merge(...$all));
        $this->assertNotContains('=HYPERLINK("a")', array_merge(...$all));
        $this->assertCount(1, array_filter(array_column($only, 0), static fn (string $cell): bool => str_starts_with($cell, 'RECV ')));
    }

    public function testGetCsv_IsNotFoundWithoutThePermission(): void
    {
        $this->market();

        $this->withSession(['person_id' => $this->employeeWithGrants(['reports', 'reports_sales']), 'menu_group' => 'home']);
        $this->expectException(PageNotFoundException::class);

        $this->get('/purchases/csv');
    }

    public function testGetXlsx_AndGetPdf_GiveTheFileOfTheCsvThroughTheExportFilter(): void
    {
        $this->market();
        $this->reader();

        $xlsx = $this->get('/purchases/xlsx');

        $this->assertStringContainsString('spreadsheetml', $xlsx->response()->getHeaderLine('Content-Type'));
        $this->assertStringStartsWith('PK', $xlsx->response()->getBody());

        $pdf = $this->get('/purchases/pdf');

        $this->assertStringContainsString('pdf', $pdf->response()->getHeaderLine('Content-Type'));
        $this->assertStringStartsWith('%PDF', $pdf->response()->getBody());
    }

    public function testGetSearch_ReadsOnlyTheFiltersItKnows(): void
    {
        $this->market();
        $this->reader();

        $hostile = $this->json('/purchases/search?status=1%20OR%201=1&payment=x&supplier_id=abc&date_from=2026-02-30');

        $this->assertSame(4, $hostile['total']);
    }
}
