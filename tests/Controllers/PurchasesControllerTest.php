<?php

namespace Tests\Controllers;

use App\Libraries\Accounting\Currencies;
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

        $plain = $this->get('/purchases');

        $plain->assertStatus(200);
        $this->assertStringContainsString('id="purchase_filters"', $plain->getBody());
        $this->assertStringNotContainsString('id="add_purchase"', $plain->getBody());

        $this->reader(['receivings', 'receivings_stock']);
        $body = $this->get('/purchases')->getBody();

        $this->assertStringContainsString('id="add_purchase"', $body);
        $this->assertLessThan(strpos($body, 'id="toolbar"'), strpos($body, 'id="add_purchase"'));
    }

    public function testGetIndex_OffersTheBranchFilterOnlyToWhoWorksInMoreThanOneBranch(): void
    {
        $second = $this->createBranch('B2', 'Second branch');

        $this->reader([], [1]);

        $one = $this->get('/purchases');

        $one->assertStatus(200);
        $this->assertStringContainsString('id="purchase_filters"', $one->getBody());
        $this->assertStringNotContainsString('id="f_branches"', $one->getBody());

        $this->reader([], [1, $second]);

        $two = $this->get('/purchases');

        $two->assertStatus(200);
        $this->assertStringContainsString('id="f_branches"', $two->getBody());
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

    public function testGetIndex_FillsTheMissingDayOfALinkAndPutsABackwardsRangeRightWay(): void
    {
        $this->reader();

        $from = $this->get('/purchases?date_from=2026-01-05')->getBody();
        $to   = $this->get('/purchases?date_to=2026-02-10')->getBody();
        $swap = $this->get('/purchases?date_from=2026-03-01&date_to=2026-02-01')->getBody();

        $this->assertStringContainsString('const initialFrom = "2026-01-05";', $from);
        $this->assertStringContainsString('const initialTo = "' . date('Y-m-d') . '";', $from);
        $this->assertStringContainsString('const initialFrom = "2026-02-01";', $to);
        $this->assertStringContainsString('const initialTo = "2026-02-10";', $to);
        $this->assertStringContainsString('const initialFrom = "2026-02-01";', $swap);
        $this->assertStringContainsString('const initialTo = "2026-03-01";', $swap);
    }

    public function testGetIndex_NamesTheSupplierOfALinkAndTheNumberOfOneWhoHasNoDocumentToSee(): void
    {
        $ids = $this->market();
        $this->reader();

        $known   = html_entity_decode($this->get('/purchases?supplier_id=' . $ids['second'])->getBody());
        $unknown = html_entity_decode($this->get('/purchases?supplier_id=987654')->getBody());

        $this->assertStringContainsString('id="f_supplier_name"', $known);
        $this->assertMatchesRegularExpression('/id="f_supplier_name"[^>]*value="Najd Trading"/', $known);
        $this->assertMatchesRegularExpression('/id="f_supplier_name"[^>]*value="#987654"/', $unknown);
    }

    public function testGetIndex_AsksForTheConfirmationOfDeletingAReceiving(): void
    {
        $this->reader();

        $body = html_entity_decode($this->get('/purchases')->getBody());

        $this->assertStringContainsString(lang('Receivings.confirm_delete'), $body);
        $this->assertStringNotContainsString(lang('Reports.confirm_delete'), $body);
    }

    public function testGetIndex_TellsAnOwnOnlyReaderSoAndHidesTheOldReportThatKnowsNoSuchLimit(): void
    {
        $second   = $this->createBranch('B2', 'Second branch');
        $personId = $this->reader([], [1, $second]);

        $all = html_entity_decode($this->get('/purchases')->getBody());

        $this->assertStringContainsString('id="legacy_report"', $all);
        $this->assertStringNotContainsString(lang('Purchases.scope_own_records'), $all);

        (new RoleService())->setRecordScope($personId, 'own', null);

        $own = html_entity_decode($this->get('/purchases')->getBody());

        $this->assertStringContainsString(lang('Purchases.scope_own_records'), $own);
        $this->assertStringNotContainsString('id="legacy_report"', $own);
        $this->assertSame(lang('Purchases.scope_own_records'), $this->csvRows($this->get('/purchases/csv'))[1][0]);
    }

    public function testGetSums_AndGetCsv_KeepTheDecimalsOfAForeignCurrency(): void
    {
        $this->enableDollars();

        $currencies = new Currencies();
        $currencies->setActive('JPY', true);
        $currencies->rates()->add(['currency_code' => 'JPY', 'rate' => '0.025', 'effective_date' => $this->daysAgo(30)]);

        $this->grantPurchaseDate();
        $this->purchases->useCurrency('JPY');
        $this->assertGreaterThan(0, $this->buy([$this->purchaseLine($this->item('0.00'), 0, '3', '1234')], -1, null, null, null, date('Y-m-d', strtotime('-10 days'))));
        $this->purchases->useCurrency('');
        $this->reader();

        $html = html_entity_decode($this->json('/purchases/sums')['html']);
        $rows = $this->csvRows($this->get('/purchases/csv'));
        $last = $rows[count($rows) - 1];

        $this->assertStringContainsString('3,702', $html);
        $this->assertStringNotContainsString('3,702.00', $html);
        $this->assertSame(lang('Purchases.sums_net') . ' (JPY)', $last[0]);
        $this->assertSame('3702', $last[1]);
    }

    private function holdTheModuleButNotThePermission(): void
    {
        $this->withSession(['person_id' => $this->employeeWithGrants(['reports', 'reports_sales']), 'menu_group' => 'home']);
    }

    public function testGetSearch_AnswersForbiddenWhoHoldsTheModuleButNotThePermission(): void
    {
        $this->market();
        $this->holdTheModuleButNotThePermission();

        $this->get('/purchases/search')->assertStatus(403);

        $body = $this->json('/purchases/search');

        $this->assertFalse($body['success']);
        $this->assertArrayNotHasKey('rows', $body);
        $this->assertArrayNotHasKey('total', $body);
    }

    public function testGetSums_AnswersForbiddenWhoHoldsTheModuleButNotThePermission(): void
    {
        $this->market();
        $this->holdTheModuleButNotThePermission();

        $this->get('/purchases/sums')->assertStatus(403);

        $body = $this->json('/purchases/sums');

        $this->assertFalse($body['success']);
        $this->assertArrayNotHasKey('html', $body);
        $this->assertStringNotContainsString('395.00', json_encode($body));
    }

    public function testGetRow_AnswersForbiddenWhoHoldsTheModuleButNotThePermission(): void
    {
        $ids = $this->market();
        $this->holdTheModuleButNotThePermission();

        $this->get('/purchases/row/' . $ids['big'])->assertStatus(403);

        $body = $this->json('/purchases/row/' . $ids['big']);

        $this->assertFalse($body['success']);
        $this->assertArrayNotHasKey('receiving_id', $body);
    }

    public function testGetSuppliers_AnswersForbiddenWhoHoldsTheModuleButNotThePermission(): void
    {
        $this->market();
        $this->holdTheModuleButNotThePermission();

        $this->get('/purchases/suppliers?term=Najd')->assertStatus(403);

        $body = $this->json('/purchases/suppliers?term=Najd');

        $this->assertFalse($body['success']);
        $this->assertStringNotContainsString('Najd', json_encode($body));
    }

    public function testEveryAddress_RefusesWhoHoldsThePermissionButNotTheModule(): void
    {
        $ids = $this->market();

        $this->withSession(['person_id' => $this->employeeWithGrants(['reports_receivings']), 'menu_group' => 'home']);

        foreach (['/purchases', '/purchases/search', '/purchases/sums', '/purchases/row/' . $ids['big'], '/purchases/suppliers?term=Najd'] as $address) {
            $response = $this->get($address);

            $this->assertTrue($response->isRedirect() || $response->response()->getStatusCode() === 403, $address);
            $this->assertStringNotContainsString('Najd', (string) $response->response()->getBody(), $address);
        }
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

    public function testGetSearch_OffersNoDebitNoteForAPurchaseThatWasReturnedInFull(): void
    {
        $whole = $this->purchaseOf('80.00');

        $this->debitNoteOf($whole, '80.00');
        $this->reader(['receivings', 'receivings_stock']);

        $rows = array_column($this->json('/purchases/search')['rows'], null, 'receiving_id');

        $this->assertStringNotContainsString('data-purchase-action="debit_note"', $rows[$whole]['options']);
        $this->assertStringContainsString('data-purchase-action="delete"', $rows[$whole]['options']);
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

        $status = html_entity_decode(strip_tags($row['purchase_status']));

        $this->assertSame($ids['small'], $row['receiving_id']);
        $this->assertStringContainsString(lang('Purchases.status_received'), $status);
        $this->assertStringContainsString(lang('Purchases.status_partly_returned'), $status);
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

    public function testGetSearch_AndGetSums_ApplyTheFiltersOfTheRequest(): void
    {
        $ids = $this->market();
        $this->reader();

        $returns = $this->json('/purchases/search?status=return');

        $this->assertSame(1, $returns['total']);
        $this->assertSame($ids['note'], $returns['rows'][0]['receiving_id']);
        $this->assertSame(1, $this->json('/purchases/search?supplier_id=' . $ids['second'])['total']);
        $this->assertSame(0, $this->json('/purchases/search?date_from=2999-01-01&date_to=2999-12-31')['total']);
        $this->assertSame(1, $this->json('/purchases/search?search=Najd')['total']);

        $sums = $this->json('/purchases/sums?status=return');

        $this->assertStringContainsString('50.00', $sums['html']);
        $this->assertStringNotContainsString('395.00', $sums['html']);
        $this->assertStringContainsString('100.00', $this->json('/purchases/sums?supplier_id=' . $ids['second'])['html']);
        $this->assertStringNotContainsString('395.00', $this->json('/purchases/sums?supplier_id=' . $ids['second'])['html']);
    }

    public function testEveryAddress_ShowsABranchLimitedReaderOnlyTheDocumentsOfTheirBranches(): void
    {
        $ids    = $this->market();
        $home   = (int) $this->accountingDb()->table('receivings')->where('receiving_id', $ids['big'])->get()->getRow()->branch_id;
        $second = $this->createBranch('B2', 'Second branch');

        $this->accountingDb()->table('receivings')->where('receiving_id', $ids['other'])->update(['branch_id' => $second]);

        $this->reader([], [$home]);

        $this->assertSame(3, $this->json('/purchases/search')['total']);
        $this->assertStringContainsString('295.00', $this->json('/purchases/sums')['html']);
        $this->assertStringNotContainsString('395.00', $this->json('/purchases/sums')['html']);
        $this->assertSame([], $this->json('/purchases/suppliers?term=Najd'));
        $this->assertNotContains('RECV ' . $ids['other'], array_column($this->csvRows($this->get('/purchases/csv')), 0));

        $this->reader([], [$second]);

        $this->assertSame(1, $this->json('/purchases/search')['total']);
        $this->assertStringContainsString('100.00', $this->json('/purchases/sums')['html']);
        $this->assertSame([['id' => $ids['second'], 'name' => 'Najd Trading']], $this->json('/purchases/suppliers?term=Najd'));
        $this->assertContains('RECV ' . $ids['other'], array_column($this->csvRows($this->get('/purchases/csv')), 0));
        $this->assertNotContains('RECV ' . $ids['big'], array_column($this->csvRows($this->get('/purchases/csv')), 0));
    }

    public function testEveryAddress_KeepsTheDocumentsOfAnotherEmployeeFromAnOwnOnlyReader(): void
    {
        $this->market();
        $author = $this->employeeId;
        $item   = $this->item('0.00');

        $this->workInEveryBranch();

        $other = $this->employeeId;
        $mine  = $this->buy([$this->purchaseLine($item, 0, '1', '60.00')]);

        $this->accountingDb()->table('grants')->ignore(true)->insertBatch([['permission_id' => 'reports', 'person_id' => $author, 'menu_group' => 'home'], ['permission_id' => 'reports_receivings', 'person_id' => $author, 'menu_group' => 'home'], ['permission_id' => BranchGuard::ALL_BRANCHES, 'person_id' => $author, 'menu_group' => 'home']]);
        (new RoleService())->setRecordScope($author, 'own', null);
        $this->withSession(['person_id' => $author, 'menu_group' => 'home']);

        $this->assertStringContainsString('395.00', $this->json('/purchases/sums')['html']);
        $this->assertStringNotContainsString('455.00', $this->json('/purchases/sums')['html']);
        $this->get('/purchases/row/' . $mine)->assertStatus(404);

        $rows = $this->csvRows($this->get('/purchases/csv'));

        $this->assertNotContains('RECV ' . $mine, array_column($rows, 0));
        $this->assertSame('395.00', $rows[count($rows) - 1][1]);

        $this->withSession(['person_id' => $other, 'menu_group' => 'home']);
        $this->accountingDb()->table('grants')->ignore(true)->insertBatch([['permission_id' => 'reports', 'person_id' => $other, 'menu_group' => 'home'], ['permission_id' => 'reports_receivings', 'person_id' => $other, 'menu_group' => 'home'], ['permission_id' => BranchGuard::ALL_BRANCHES, 'person_id' => $other, 'menu_group' => 'home']]);

        $this->assertStringContainsString('455.00', $this->json('/purchases/sums')['html']);
        $this->get('/purchases/row/' . $mine)->assertStatus(200);
    }

    public function testGetSearch_TakesTheDefaultPageSizeForZeroAndNeverAnswersLessThanOneRow(): void
    {
        $this->market();
        $this->reader();

        $this->assertCount(4, $this->json('/purchases/search?limit=0')['rows']);
        $this->assertCount(1, $this->json('/purchases/search?limit=-3')['rows']);
        $this->assertCount(1, $this->json('/purchases/search?limit=1&offset=-5')['rows']);
        $this->assertSame(4, $this->json('/purchases/search?limit=1&offset=-5')['total']);
    }

    public function testGetSearch_ReadsOnlyTheFiltersItKnows(): void
    {
        $this->market();
        $this->reader();

        $hostile = $this->json('/purchases/search?status=1%20OR%201=1&payment=x&supplier_id=abc&date_from=2026-02-30');

        $this->assertSame(4, $hostile['total']);
    }
}
