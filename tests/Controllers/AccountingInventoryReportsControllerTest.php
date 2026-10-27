<?php

namespace Tests\Controllers;

use App\Libraries\Accounting\JournalEntryType;
use App\Libraries\Accounting\RoutingKey;
use App\Libraries\Accounting\StockLedger;
use App\Libraries\Accounting\StockMovement;
use App\Libraries\Organization\BranchService;
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
 *
 * @internal
 */
final class AccountingInventoryReportsControllerTest extends CIUnitTestCase
{
    use DatabaseTestTrait;
    use AccountingControllerFixtureTrait;
    use ItemFixtureTrait;
    use SaleAccountingFixtureTrait;
    use PurchaseAccountingFixtureTrait;
    use ShelfFixtureTrait;
    use StockBooksFixtureTrait;

    private const SCREEN = '/accounting_reports/inventory_valuation';

    protected $migrate     = true;
    protected $migrateOnce = true;
    protected $refresh     = false;
    protected $namespace;
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

    private function stockAccountsRow(TestResponse $response, string $row): string
    {
        preg_match('#<section[^>]*id="stock_accounts".*?</section>#s', $response->getBody(), $section);
        preg_match('#data-total="' . $row . '">.*?<bdi dir="ltr">([^<]*)</bdi>#s', $section[0] ?? '', $found);

        return $found[1] ?? '';
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

    public function testGetIndex_RequiresTheModuleGrant(): void
    {
        $this->twoBranches();
        $this->actAs($this->employeeWithGrants(['accounting_payables']));

        $this->get(self::SCREEN)->assertRedirect();
    }

    public function testGetIndex_RequiresAccountingToBeOn(): void
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

    public function testGetIndex_RejectsADayThatIsNotADateWithAMessageAndShowsTheStockAsItStandsNow(): void
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

    public function testGetIndex_AcceptsAMalformedQueryAsTheDefaultReport(): void
    {
        $this->twoBranches();
        $this->anotherLocation('Idle depot');
        $this->viewer();

        foreach (['?as_of[]=2026-10-05', '?branches=1', '?empty[]=1', '?branches=1&as_of[]=x&empty[]=1'] as $query) {
            $response = $this->get(self::SCREEN . $query);
            $body     = $response->getBody();

            $response->assertStatus(200);
            $this->assertStringContainsString(lang('Accounting_inventory_reports.period_now'), $body, 'Query ' . $query);
            $this->assertStringContainsString('Cream', $body, 'Query ' . $query);
            $this->assertStringContainsString('Perfume', $body, 'Query ' . $query);
            $this->assertStringNotContainsString('Idle depot', $body, 'Query ' . $query);
            $this->assertStringNotContainsString('id="report_error"', $body, 'Query ' . $query);
            $this->assertStringNotContainsString('id="report_no_branches"', $body, 'Query ' . $query);
            $this->assertStringNotContainsString('value="2026-10-05"', $body, 'Query ' . $query);
            $this->assertSame($this->amountOf('180.00'), $this->totalShown($response), 'Query ' . $query);
        }
    }

    public function testGetIndex_ShowsNothingToAddUpForABranchThatIsNoRealId(): void
    {
        $this->twoBranches();
        $this->viewer();

        foreach (['?branches[]=abc&branches[]=-4', '?branches[]=99999999999999999999', '?branches[][]=1', '?branches[]=0'] as $query) {
            $response = $this->get(self::SCREEN . $query);
            $body     = $response->getBody();

            $response->assertStatus(200);
            $this->assertStringContainsString('id="report_no_branches"', $body, 'Query ' . $query);
            $this->assertStringNotContainsString('Cream', $body, 'Query ' . $query);
            $this->assertStringNotContainsString('Perfume', $body, 'Query ' . $query);
            $this->assertSame($this->amountOf('0.00'), $this->totalShown($response), 'Query ' . $query);
        }
    }

    public function testGetIndex_DoesNotLetABranchThatIsNotPlainTextWidenWhatAnEmployeeReads(): void
    {
        $this->twoBranches();
        $this->viewer([1]);

        $response = $this->get(self::SCREEN . '?branches[][]=' . $this->second . '&branches[][]=1');
        $body     = $response->getBody();

        $response->assertStatus(200);
        $this->assertStringNotContainsString('Perfume', $body);
        $this->assertStringNotContainsString('Second depot', $body);
        $this->assertStringContainsString('id="report_no_branches"', $body);
    }

    public function testGetIndex_RejectsADayThatIsHugeOrMisspelledWithAMessageAndShowsTheStockAsItStandsNow(): void
    {
        $this->twoBranches();
        $this->viewer();

        foreach (['99999-01-01', '9999-99-99', '2026-1-5', '0000-00-00'] as $day) {
            $response = $this->get(self::SCREEN . '?as_of=' . $day);
            $body     = $response->getBody();

            $response->assertStatus(200);
            $this->assertStringContainsString('id="report_error"', $body, 'Day ' . $day);
            $this->assertStringContainsString(lang('Accounting_inventory_reports.period_now'), $body, 'Day ' . $day);
            $this->assertStringContainsString('Cream', $body, 'Day ' . $day);
        }
    }

    public function testGetIndex_AcceptsTheLastDayThereIsAsTheStockAsItStandsNow(): void
    {
        $this->twoBranches();
        $this->viewer();

        $response = $this->get(self::SCREEN . '?as_of=9999-12-31');

        $response->assertStatus(200);
        $response->assertSee(lang('Accounting_inventory_reports.period_now'));
        $response->assertDontSee('id="report_error"');
        $this->assertSame($this->amountOf('180.00'), $this->totalShown($response));
    }

    public function testGetIndex_SaysThatTheStockAsItStandsNowCountsEveryJournalWhateverItsDate(): void
    {
        $this->twoBranches();
        $this->manualToStock('30.00', 1, self::LOCATION_ID, '2035-06-01');
        $this->viewer();

        $response = $this->get(self::SCREEN);

        $response->assertSee(lang('Accounting_inventory_reports.account_more', [$this->amountOf('30.00')]));
        $response->assertSee(lang('Accounting_inventory_reports.as_of_hint'));
        $this->assertSame($this->amountOf('210.00'), $this->stockAccountsRow($response, 'stock_account'));
    }

    public function testGetIndex_ShowsAScopeWithNoBranchOfTheEmployeeAsNothingToAddUp(): void
    {
        $this->twoBranches();
        $this->viewer([1]);

        $response = $this->get(self::SCREEN . '?branches[]=' . $this->second);

        $response->assertSee('id="report_no_branches"');
        $response->assertDontSee('Perfume');
    }

    public function testGetIndex_SaysThatALocationWithNoAccountCannotBeTiedAndLinksNoStatementForIt(): void
    {
        $item  = $this->named('Loose cream');
        $loose = $this->insertStockLocation('Loose shelf');

        (new StockLedger())->record('purchase', 889201, JournalEntryType::General, 1, '2026-10-05', 1, [new StockMovement($item, $loose, '3', '30.00')], null);
        $this->putOnShelf($item, '3', $loose);
        $this->viewer();

        $response = $this->get(self::SCREEN);

        $response->assertStatus(200);
        $response->assertSee('Loose shelf');
        $response->assertSee('data-status="differs"');
        $response->assertSee('data-verdict="differs"');
        $response->assertSee(lang('Accounting_inventory_reports.no_account'));
        $response->assertSee(lang('Accounting_inventory_reports.no_account_row'));
        $response->assertDontSee(lang('Accounting_inventory_reports.account_less', [$this->amountOf('30.00')]));
        $response->assertDontSee('accounting_reports/ledger?account=');
    }

    public function testGetIndex_ShowsWhatTheMainStockAccountHoldsBesideWhatTheMovementsSay(): void
    {
        $this->twoBranches();
        $this->manualToStock('30.00');
        $this->viewer();

        $response = $this->get(self::SCREEN);

        $response->assertSee('id="stock_accounts"');
        $response->assertSee(lang('Accounting_inventory_reports.stock_account_row', ['1203']));
        $this->assertSame($this->amountOf('180.00'), $this->stockAccountsRow($response, 'movements'));
        $this->assertSame($this->amountOf('210.00'), $this->stockAccountsRow($response, 'stock_account'));
        $this->assertSame($this->amountOf('30.00'), $this->stockAccountsRow($response, 'stock_difference'));
    }

    public function testGetIndex_ShowsNoStockAccountsSectionWhenTheChartHasNoMainStockAccount(): void
    {
        $this->twoBranches();
        $this->accountingDb()->table('accounting_routing')->where('routing_key', RoutingKey::StockLocations->value)->delete();
        $this->viewer();

        $response = $this->get(self::SCREEN);

        $response->assertStatus(200);
        $response->assertDontSee('id="stock_accounts"');
        $response->assertSee('Cream');
    }

    /**
     * @return array{moved: int, own: int, theirs: int}
     */
    private function locationThatChangedBranch(): array
    {
        $own    = $this->named('Own cream');
        $theirs = $this->named('Their perfume');
        $moved  = $this->anotherLocation('Moved shelf');

        $this->bookStock('2026-10-05', 1, $own, '10', '100.00', $moved);
        $this->putOnShelf($own, '10', $moved);

        (new BranchService())->assignLocations($this->second, [$moved], 1);

        $this->putOnShelf($theirs, '7', $moved);

        return ['moved' => $moved, 'own' => $own, 'theirs' => $theirs];
    }

    public function testGetIndex_GivesNoStockOfAnotherBranchAwayForALocationThatLeftTheBranchOfTheEmployee(): void
    {
        $stock = $this->locationThatChangedBranch();
        $this->viewer([1]);

        $response = $this->get(self::SCREEN);

        $this->assertMatchesRegularExpression('#data-item="' . $stock['own'] . '".*?colspan="3">&mdash;</td>#s', $response->getBody(), 'The shelf figures of a line that was not compared are dashes');

        $response->assertSee('Moved shelf');
        $response->assertSee('Own cream');
        $response->assertDontSee('Their perfume');
        $response->assertSee('data-verdict="shelves_skipped"');
        $response->assertSee(lang('Accounting_inventory_reports.shelves_skipped'));
        $response->assertDontSee('data-unposted="1"');
        $response->assertDontSee('id="report_unposted"');
        $response->assertSee('data-status="ties"');
    }

    public function testGetIndex_DoesNotCallStockOfAnEarlierBranchUnpostedForTheBranchTheLocationNowBelongsTo(): void
    {
        $this->locationThatChangedBranch();
        $this->viewer([$this->second]);

        $response = $this->get(self::SCREEN . '?empty=1');

        $response->assertSee('Moved shelf');
        $response->assertSee('data-verdict="shelves_skipped"');
        $response->assertDontSee('Their perfume');
        $response->assertDontSee('Own cream');
        $response->assertDontSee('data-unposted="1"');
    }

    public function testGetIndex_ComparesTheShelvesOfALocationThatLeftItsBranchForWhoReadsEveryBranch(): void
    {
        $this->locationThatChangedBranch();
        $this->viewer();

        $response = $this->get(self::SCREEN);

        $response->assertSee('Their perfume');
        $response->assertSee('data-unposted="1"');
        $response->assertDontSee('data-verdict="shelves_skipped"');
        $response->assertSee(lang('Accounting_inventory_reports.unposted_summary', [1]));
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

    public function testGetIndex_CarriesAScopeWithNoBranchIntoTheLinksSoTheyDoNotWidenIt(): void
    {
        $this->twoBranches();
        $this->viewer([1]);

        $response = $this->get(self::SCREEN . '?branches[]=' . $this->second);

        $response->assertSee('inventory_valuation/csv?branches%5B0%5D=0');
        $this->get(self::SCREEN . '/csv?branches[0]=0')->assertStatus(200);
        $this->assertCount(2, $this->csvRows($this->get(self::SCREEN . '/csv?branches[0]=0')), 'The header and the total of nothing');
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

    public function testGetCsv_RequiresAccountingToBeOn(): void
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
        $names = [
            '=HYPERLINK("http://example.test","x")',
            '+cmd|x',
            '-cream',
            '@SUM(1+1)',
            "\tTabbed cream",
            "\rReturned cream",
        ];

        foreach ($names as $name) {
            $this->bookStock('2026-10-05', 1, $this->named($name), '1', '10.00');
        }

        $plain = $this->named('Plain cream');
        $this->bookStock('2026-10-05', 1, $plain, '-1', '-10.00');

        $this->viewer();

        $rows      = $this->csvRows($this->get(self::SCREEN . '/csv'));
        $itemNames = array_column($rows, 4);

        foreach ($names as $name) {
            $this->assertContains("'" . $name, $itemNames, 'The name ' . json_encode($name));
            $this->assertNotContains($name, $itemNames, 'The name ' . json_encode($name));
        }

        $this->assertContains('Plain cream', $itemNames);

        $negative = array_values(array_filter($rows, static fn (array $row): bool => $row[4] === 'Plain cream'))[0];

        $this->assertSame(['-1.000', '-10.0000'], [$negative[5], $negative[7]], 'A number that is negative stays a number');
    }

    public function testGetCsv_ShowsTheNamesOfLocationsAndBranchesThatAreFormulasAsText(): void
    {
        $item   = $this->named('Cream');
        $branch = $this->createBranch('+V9', 'Formula branch');
        $depot  = $this->locationOfBranch($branch, '@Formula shelf');
        $loose  = $this->anotherLocation('=Formula shelf');

        $this->bookStock('2026-10-05', $branch, $item, '1', '10.00', $depot);
        $this->bookStock('2026-10-05', 1, $item, '1', '10.00', $loose);
        $this->viewer();

        $rows = $this->csvRows($this->get(self::SCREEN . '/csv'));

        $this->assertContains("'+V9", array_column($rows, 1));
        $this->assertContains("'@Formula shelf", array_column($rows, 2));
        $this->assertContains("'=Formula shelf", array_column($rows, 2));
        $this->assertNotContains('+V9', array_column($rows, 1));
        $this->assertNotContains('@Formula shelf', array_column($rows, 2));
        $this->assertNotContains('=Formula shelf', array_column($rows, 2));
    }

    public function testGetCsv_RejectsADayThatIsNotADate(): void
    {
        $this->twoBranches();
        $this->viewer();

        foreach (['2026-02-31', '99999-01-01', '2026-1-5'] as $day) {
            $response = $this->get(self::SCREEN . '/csv?as_of=' . $day);

            $response->assertStatus(422);
            $this->assertStringNotContainsString('Cream', $response->response()->getBody());
            $this->assertSame(lang('Accounting.error_invalid_date'), $response->response()->getBody(), 'Day ' . $day);
        }
    }

    public function testGetCsv_AcceptsAMalformedQueryAsTheDefaultFile(): void
    {
        $this->twoBranches();
        $this->viewer();

        $response = $this->get(self::SCREEN . '/csv?as_of[]=2026-10-05&branches=1&empty[]=1');

        $response->assertStatus(200);
        $this->assertCount(6, $this->csvRows($response));
    }
}
