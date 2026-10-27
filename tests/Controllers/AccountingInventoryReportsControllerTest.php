<?php

namespace Tests\Controllers;

use App\Libraries\Accounting\JournalEntryType;
use App\Libraries\Accounting\StockLedger;
use App\Libraries\Accounting\StockMovement;
use CodeIgniter\Exceptions\PageNotFoundException;
use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\DatabaseTestTrait;
use CodeIgniter\Test\TestResponse;
use Tests\Support\AccountingControllerFixtureTrait;
use Tests\Support\ItemFixtureTrait;
use Tests\Support\PurchaseAccountingFixtureTrait;
use Tests\Support\SaleAccountingFixtureTrait;
use Tests\Support\ShelfFixtureTrait;
use Tests\Support\StockBooksFixtureTrait;

/**
 * The screen of the inventory valuation and its CSV: who may open them, which branches each adds up
 * for whom, what they say of a location that does not tie to its account and of units that no
 * movement accounts for, and what comes out in the file.
 */
class AccountingInventoryReportsControllerTest extends CIUnitTestCase
{
    use DatabaseTestTrait;
    use AccountingControllerFixtureTrait;
    use ItemFixtureTrait;
    use SaleAccountingFixtureTrait;
    use PurchaseAccountingFixtureTrait;
    use ShelfFixtureTrait;
    use StockBooksFixtureTrait;

    protected $migrate     = true;
    protected $migrateOnce = true;
    protected $refresh     = false;
    protected $namespace   = null;

    private const SCREEN = '/accounting_reports/inventory_valuation';

    private int $second;
    private int $depot;

    protected function setUp(): void
    {
        parent::setUp();

        $this->startBuying();
        $this->emptyShelves();

        $this->second = $this->createBranch('V2', 'Second branch');
        $this->depot  = $this->locationOfBranch($this->second, 'Second depot');
    }

    protected function tearDown(): void
    {
        $this->stopBuying();
        $this->restoreShelves();
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

    private function named(string $name, string $cost = '0.00'): int
    {
        $itemId = $this->item($cost);

        $this->accountingDb()->table('items')->where('item_id', $itemId)->update(['name' => $name, 'item_number' => 'N-' . $itemId]);

        return $itemId;
    }

    /**
     * Stock in the books and on the shelves in both branches: 100.00 in the first, 80.00 in the
     * second.
     *
     * @return array{first: int, other: int}
     */
    private function twoBranches(): array
    {
        $first = $this->named('Cream');
        $other = $this->named('Perfume');

        $this->bookStock('2026-10-05', 1, $first, '10', '100.00');
        $this->putOnShelf($first, '10');
        $this->bookStock('2026-10-05', $this->second, $other, '4', '80.00', $this->depot);
        $this->putOnShelf($other, '4', $this->depot);

        return ['first' => $first, 'other' => $other];
    }

    private function totalShown(TestResponse $response): string
    {
        preg_match('#id="report_total">[^<]*<bdi dir="ltr">([^<]*)</bdi>#', $response->getBody(), $found);

        return $found[1] ?? '';
    }

    /**
     * @return list<list<string>>
     */
    private function csvRows(TestResponse $response): array
    {
        $body = $response->response()->getBody();

        $this->assertStringStartsWith("\xEF\xBB\xBF", $body);

        $handle = fopen('php://temp', 'r+');
        fwrite($handle, substr($body, 3));
        rewind($handle);

        $rows = [];

        while (($row = fgetcsv($handle, 0, ',', '"', '')) !== false) {
            $rows[] = $row;
        }

        fclose($handle);

        return $rows;
    }

    public function testGetIndex_ShowsTheValuationToWhoeverHoldsTheModule(): void
    {
        $this->twoBranches();
        $this->viewer();

        $response = $this->get(self::SCREEN);

        $response->assertStatus(200);
        $response->assertSee('id="inventory_valuation"');
        $response->assertSee('Cream');
        $response->assertSee('Perfume');
        $response->assertSee('Second depot');
        $response->assertSee('<bdi dir="ltr">' . $this->amountOf('180.00') . '</bdi>');
        $response->assertSee('id="report_ties"');
        $response->assertSee('data-status="ties"');
        $response->assertDontSee('data-status="differs"');
        $response->assertDontSee('id="report_unposted"');
        $response->assertDontSee('id="report_error"');
        $response->assertSee(lang('Accounting_reports.scope_company'));
        $response->assertSee('window.print()');
    }

    public function testGetIndex_ShowsTheValueOfAPurchaseSavedThroughTheApplication(): void
    {
        $item = $this->named('Bought cream');

        $this->assertGreaterThan(0, $this->buy([$this->purchaseLine($item, 0, '10', '50')]));
        $this->viewer();

        $response = $this->get(self::SCREEN);

        $response->assertSee('Bought cream');
        $response->assertSee('<bdi dir="ltr">' . $this->amountOf('500.00') . '</bdi>');
        $response->assertSee('data-status="ties"');
        $response->assertDontSee('data-unposted="1"');
    }

    public function testGetIndex_RedirectsAUserWithoutTheModuleGrant(): void
    {
        $this->twoBranches();
        $this->actAs($this->employeeWithGrants(['accounting_payables']));

        $this->get(self::SCREEN)->assertRedirect();
    }

    public function testGetIndex_IsNotFoundWhileAccountingIsOff(): void
    {
        $this->viewer();
        $this->setConfigValue('accounting_enabled', '0');

        $this->expectException(PageNotFoundException::class);

        $this->get(self::SCREEN);
    }

    public function testGetIndex_AddsUpTheBranchesAskedFor(): void
    {
        $this->twoBranches();
        $this->viewer();

        $response = $this->get(self::SCREEN . '?branches[]=' . $this->second);

        $response->assertSee('Perfume');
        $response->assertDontSee('Cream');
        $response->assertSee('<bdi dir="ltr">' . $this->amountOf('80.00') . '</bdi>');
        $response->assertDontSee($this->amountOf('180.00'));
        $response->assertSee(lang('Accounting_reports.scope_selected', ['V2']));
    }

    public function testGetIndex_CutsWhatIsAskedDownToTheBranchesTheEmployeeWorksIn(): void
    {
        $this->twoBranches();
        $this->viewer([1]);

        $response = $this->get(self::SCREEN . '?branches[]=1&branches[]=' . $this->second);

        $response->assertSee('Cream');
        $response->assertDontSee('Perfume');
        $response->assertDontSee('Second depot');
        $response->assertDontSee($this->amountOf('180.00'));
        $response->assertDontSee($this->amountOf('80.00'));
    }

    public function testGetIndex_ShowsWhoWorksInSomeBranchesThatTheTotalIsOfThoseAndNotOfTheCompany(): void
    {
        $this->twoBranches();
        $third = $this->createBranch('V3', 'Third branch');
        $this->viewer([1, $this->second]);

        $partial = $this->get(self::SCREEN);

        $partial->assertSee('id="report_scope"');
        $partial->assertSee('alert alert-warning');
        $partial->assertSee(lang('Accounting_reports.scope_partial', [implode(lang('Accounting_reports.branch_separator'), ['HQ', 'V2'])]));
        $partial->assertSee('Perfume');
        $partial->assertDontSee(lang('Accounting_reports.scope_company'));

        $this->viewer([1, $third]);

        $other = $this->get(self::SCREEN);

        $other->assertSee(lang('Accounting_reports.scope_partial', [implode(lang('Accounting_reports.branch_separator'), ['HQ', 'V3'])]));
        $other->assertDontSee('Perfume');
        $other->assertDontSee(lang('Accounting_reports.scope_company'));
    }

    public function testGetIndex_ShowsNoBranchChoiceAndNoScopeNoteToWhoWorksInOneBranch(): void
    {
        $this->twoBranches();
        $this->viewer([$this->second]);

        $response = $this->get(self::SCREEN);

        $response->assertDontSee('id="f_branches"');
        $response->assertDontSee('id="report_scope"');
        $response->assertSee('Perfume');
        $response->assertDontSee('Cream');
    }

    public function testGetIndex_OffersTheBranchesToWhoReadsMoreThanOne(): void
    {
        $this->twoBranches();
        $this->viewer();

        $this->get(self::SCREEN)->assertSee('id="f_branches"');
    }

    public function testGetIndex_ShowsALocationThatDoesNotTieToItsAccountAsDifferent(): void
    {
        $this->twoBranches();
        $this->manualToStock('30.00');
        $this->viewer();

        $response = $this->get(self::SCREEN);

        $response->assertSee('data-status="differs"');
        $response->assertSee('id="report_differs"');
        $response->assertSee('data-verdict="differs"');
        $response->assertSee(lang('Accounting_inventory_reports.account_more', [$this->amountOf('30.00')]));
        $response->assertDontSee('id="report_ties"');
    }

    public function testGetIndex_SaysThatTheAccountHoldsLessWhenAMovementReachedNoAccount(): void
    {
        $item = $this->named('Loose cream');

        (new StockLedger())->record('purchase', 889101, JournalEntryType::General, 1, '2026-10-05', 1, [new StockMovement($item, self::LOCATION_ID, '5', '50.00')], null);
        $this->putOnShelf($item, '5');
        $this->viewer();

        $this->get(self::SCREEN)->assertSee(lang('Accounting_inventory_reports.account_less', [$this->amountOf('50.00')]));
    }

    public function testGetIndex_FlagsUnitsOnTheShelvesThatNoMovementAccountsForAndDoesNotValueThem(): void
    {
        $item = $this->named('Old stock', '12.00');

        $this->putOnShelf($item, '5');
        $this->viewer();

        $response = $this->get(self::SCREEN);

        $response->assertSee('Old stock');
        $response->assertSee('data-unposted="1"');
        $response->assertSee(lang('Accounting_inventory_reports.unposted_units'));
        $response->assertSee('data-verdict="unposted"');
        $response->assertSee('id="report_unposted"');
        $response->assertSee(lang('Accounting_inventory_reports.unposted_summary', [1]));
        $response->assertSee('<bdi dir="ltr">' . $this->amountOf('0.00') . '</bdi>');
        $this->assertSame($this->amountOf('0.00'), $this->totalShown($response), 'The cost of the item is not the value of units no movement accounts for');
    }

    public function testGetIndex_SaysWhenTheBooksHoldMoreUnitsThanTheShelves(): void
    {
        $item = $this->named('Short cream');

        $this->bookStock('2026-10-05', 1, $item, '10', '100.00');
        $this->putOnShelf($item, '7');
        $this->viewer();

        $this->get(self::SCREEN)->assertSee(lang('Accounting_inventory_reports.unposted_negative'));
    }

    public function testGetIndex_ShowsTheStockOfAPastDayWithoutComparingTheShelves(): void
    {
        $item = $this->named('Dated cream');

        $this->bookStock('2026-10-01', 1, $item, '5', '50.00');
        $this->bookStock('2026-10-10', 1, $item, '-2', '-20.00');
        $this->putOnShelf($item, '9');
        $this->viewer();

        $past = $this->get(self::SCREEN . '?as_of=2026-10-05');
        $now  = $this->get(self::SCREEN);

        $past->assertSee(lang('Accounting_inventory_reports.period_as_of', [to_date(strtotime('2026-10-05'))]));
        $past->assertSee('<bdi dir="ltr">' . $this->amountOf('50.00') . '</bdi>');
        $past->assertSee(lang('Accounting_inventory_reports.past_note'));
        $past->assertDontSee(lang('Accounting_inventory_reports.col_shelves'));
        $past->assertDontSee('data-unposted="1"');
        $past->assertSee('value="2026-10-05"');

        $now->assertSee(lang('Accounting_inventory_reports.period_now'));
        $now->assertSee(lang('Accounting_inventory_reports.col_shelves'));
        $now->assertSee('<bdi dir="ltr">' . $this->amountOf('30.00') . '</bdi>');
        $now->assertDontSee(lang('Accounting_inventory_reports.past_note'));
    }

    public function testGetIndex_ShowsTheMessageAndTheStockAsItStandsNowForADayThatIsNotADate(): void
    {
        $this->twoBranches();
        $this->viewer();

        $response = $this->get(self::SCREEN . '?as_of=2026-02-31');

        $response->assertStatus(200);
        $response->assertSee('id="report_error"');
        $response->assertSee(lang('Accounting_inventory_reports.period_now'));
        $response->assertSee('Cream');
        $response->assertSee('value="2026-02-31"');
    }

    public function testGetIndex_AnswersAMalformedQueryWithTheDefaultReportInsteadOfAnError(): void
    {
        $this->twoBranches();
        $this->viewer();

        foreach (['?as_of[]=2026-10-05', '?branches=1', '?empty[]=1', '?branches[]=abc&branches[]=-4', '?branches[][]=1'] as $query) {
            $response = $this->get(self::SCREEN . $query);

            $this->assertContains($response->response()->getStatusCode(), [200], 'Query ' . $query);
        }

        $this->get(self::SCREEN . '?as_of[]=2026-10-05')->assertSee(lang('Accounting_inventory_reports.period_now'));
    }

    public function testGetIndex_ShowsAScopeWithNoBranchOfTheEmployeeAsNothingToAddUp(): void
    {
        $this->twoBranches();
        $this->viewer([1]);

        $response = $this->get(self::SCREEN . '?branches[]=' . $this->second);

        $response->assertSee('id="report_no_branches"');
        $response->assertDontSee('Perfume');
    }

    public function testGetIndex_ShowsLocationsWithNothingInThemWhenAsked(): void
    {
        $this->anotherLocation('Idle depot');
        $this->viewer();

        $this->get(self::SCREEN)->assertDontSee('Idle depot');
        $this->get(self::SCREEN . '?empty=1')->assertSee('Idle depot');
        $this->get(self::SCREEN)->assertSee('id="report_empty"');
    }

    public function testGetIndex_EscapesTheNamesOfLocationsAndItems(): void
    {
        $item  = $this->named('<b>bold</b> cream');
        $depot = $this->anotherLocation('<script>alert(1)</script>');

        $this->bookStock('2026-10-05', 1, $item, '1', '10.00', $depot);
        $this->putOnShelf($item, '1', $depot);
        $this->viewer();

        $body = $this->get(self::SCREEN)->getBody();

        $this->assertStringNotContainsString('<script>alert(1)</script>', $body);
        $this->assertStringNotContainsString('<b>bold</b>', $body);
        $this->assertStringContainsString('&lt;script&gt;alert(1)&lt;/script&gt;', $body);
        $this->assertStringContainsString('&lt;b&gt;bold&lt;/b&gt; cream', $body);
    }

    public function testGetIndex_LinksEachLocationToTheStatementOfItsAccount(): void
    {
        $this->twoBranches();
        $this->viewer();

        $response = $this->get(self::SCREEN . '?as_of=2026-10-05&branches[]=1&branches[]=' . $this->second);

        $response->assertSee('accounting_reports/ledger?account=' . $this->stockAccountOf(self::LOCATION_ID) . '&amp;to=2026-10-05');
        $response->assertSee('accounting_reports/ledger?account=' . $this->stockAccountOf($this->depot));
    }

    public function testGetIndex_LinksTheCsvWithTheFiltersAsked(): void
    {
        $this->twoBranches();
        $this->viewer();

        $plain = $this->get(self::SCREEN);
        $asked = $this->get(self::SCREEN . '?as_of=2026-10-05&empty=1&branches[]=1');

        $plain->assertSee('href="' . base_url('accounting_reports/inventory_valuation/csv') . '"');
        $asked->assertSee('inventory_valuation/csv?as_of=2026-10-05&amp;empty=1&amp;branches%5B0%5D=1');
    }

    public function testGetIndex_IsReachedFromTheTrialBalanceAndTheLedger(): void
    {
        $this->viewer();

        $this->get('/accounting_reports')->assertSee('id="inventory_valuation_link"');
        $this->get('/accounting_reports/ledger')->assertSee('id="inventory_valuation_link"');
    }

    public function testGetCsv_GivesTheRowsOfTheReportWithTheTotalOfEveryLocationAndTheCompany(): void
    {
        $first = $this->twoBranches()['first'];
        $this->manualToStock('30.00');
        $this->viewer();

        $response = $this->get(self::SCREEN . '/csv');

        $response->assertStatus(200);
        $this->assertStringContainsString('text/csv', $response->response()->getHeaderLine('Content-Type'));
        $this->assertStringContainsString('attachment; filename="inventory_valuation_' . date('Y-m-d') . '.csv"', $response->response()->getHeaderLine('Content-Disposition'));

        $rows = $this->csvRows($response);

        $this->assertSame(lang('Accounting_inventory_reports.col_type'), $rows[0][0]);
        $this->assertCount(12, $rows[0]);
        $this->assertCount(6, $rows, 'The header, two items, two locations and the company');

        $this->assertSame([lang('Accounting_inventory_reports.row_item'), 'HQ', 'stock', 'N-' . $first, 'Cream', '10.000', '10.000000', '100.0000', '10.000', '0.000', '', ''], $rows[3]);
        $this->assertSame(lang('Accounting_inventory_reports.row_location'), $rows[4][0]);
        $this->assertSame(['100.0000', '130.0000', '30.0000'], [$rows[4][7], $rows[4][10], $rows[4][11]]);
        $this->assertSame(['Second depot', '80.0000', '80.0000', '0.0000'], [$rows[2][2], $rows[2][7], $rows[2][10], $rows[2][11]]);
        $this->assertSame(lang('Accounting_inventory_reports.row_total_company'), $rows[5][0]);
        $this->assertSame(['180.0000', '210.0000', '30.0000'], [$rows[5][7], $rows[5][10], $rows[5][11]]);
    }

    public function testGetCsv_RequiresTheModuleGrant(): void
    {
        $this->twoBranches();
        $this->actAs($this->employeeWithGrants(['accounting_payables']));

        $this->get(self::SCREEN . '/csv')->assertRedirect();
    }

    public function testGetCsv_IsNotFoundWhileAccountingIsOff(): void
    {
        $this->viewer();
        $this->setConfigValue('accounting_enabled', '0');

        $this->expectException(PageNotFoundException::class);

        $this->get(self::SCREEN . '/csv');
    }

    public function testGetCsv_CutsWhatIsAskedDownToTheBranchesTheEmployeeWorksInAndSaysTheTotalIsNotTheCompanys(): void
    {
        $this->twoBranches();
        $this->viewer([1]);

        $response = $this->get(self::SCREEN . '/csv?branches[]=1&branches[]=' . $this->second);
        $body     = $response->response()->getBody();
        $rows     = $this->csvRows($response);

        $this->assertStringContainsString('Cream', $body);
        $this->assertStringNotContainsString('Perfume', $body);
        $this->assertStringNotContainsString('Second depot', $body);
        $this->assertSame(lang('Accounting_inventory_reports.row_total_branches'), end($rows)[0]);
        $this->assertSame('100.0000', end($rows)[7]);
    }

    public function testGetCsv_CountsTheDayAskedFor(): void
    {
        $item = $this->named('Dated cream');

        $this->bookStock('2026-10-01', 1, $item, '5', '50.00');
        $this->bookStock('2026-10-10', 1, $item, '-2', '-20.00');
        $this->viewer();

        $response = $this->get(self::SCREEN . '/csv?as_of=2026-10-05');
        $rows     = $this->csvRows($response);

        $this->assertStringContainsString('inventory_valuation_2026-10-05.csv', $response->response()->getHeaderLine('Content-Disposition'));
        $this->assertSame(['5.000', '50.0000', '', ''], [$rows[1][5], $rows[1][7], $rows[1][8], $rows[1][9]], 'The shelves are not compared for a past day');
        $this->assertSame('50.0000', end($rows)[7]);
    }

    public function testGetCsv_ShowsATextThatASpreadsheetWouldReadAsAFormulaAsText(): void
    {
        $formula = $this->named('=HYPERLINK("http://example.test","x")');
        $minus   = $this->named('-cream');
        $plain   = $this->named('Plain cream');

        $this->bookStock('2026-10-05', 1, $formula, '1', '10.00');
        $this->bookStock('2026-10-05', 1, $minus, '1', '10.00');
        $this->bookStock('2026-10-05', 1, $plain, '-1', '-10.00');
        $this->viewer();

        $rows  = $this->csvRows($this->get(self::SCREEN . '/csv'));
        $names = array_column($rows, 4);

        $this->assertContains("'=HYPERLINK(\"http://example.test\",\"x\")", $names);
        $this->assertContains("'-cream", $names);
        $this->assertContains('Plain cream', $names);
        $this->assertNotContains('=HYPERLINK("http://example.test","x")', $names);

        $negative = array_values(array_filter($rows, static fn (array $row): bool => $row[4] === 'Plain cream'))[0];

        $this->assertSame(['-1.000', '-10.0000'], [$negative[5], $negative[7]], 'A number that is negative stays a number');
    }

    public function testGetCsv_RefusesADayThatIsNotADate(): void
    {
        $this->twoBranches();
        $this->viewer();

        $response = $this->get(self::SCREEN . '/csv?as_of=2026-02-31');

        $response->assertStatus(422);
        $this->assertStringNotContainsString('Cream', $response->response()->getBody());
        $this->assertSame(lang('Accounting.error_invalid_date'), $response->response()->getBody());
    }

    public function testGetCsv_AnswersAMalformedQueryWithTheDefaultFile(): void
    {
        $this->twoBranches();
        $this->viewer();

        $response = $this->get(self::SCREEN . '/csv?as_of[]=2026-10-05&branches=1&empty[]=1');

        $response->assertStatus(200);
        $this->assertCount(6, $this->csvRows($response));
    }
}
