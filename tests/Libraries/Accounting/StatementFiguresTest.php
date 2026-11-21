<?php

namespace Tests\Libraries\Accounting;

use App\Libraries\Accounting\AccountType;
use App\Libraries\Accounting\EntityAccountService;
use App\Libraries\Accounting\ReportPeriod;
use App\Libraries\Accounting\ReportScope;
use App\Libraries\Accounting\StatementDisplay;
use App\Libraries\Accounting\StatementFigures;
use App\Libraries\Accounting\StatementLine;
use App\Libraries\Accounting\TrialBalance;
use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\DatabaseTestTrait;
use Config\Database;
use Tests\Support\AccountingFixtureTrait;
use Tests\Support\EmployeeFixtureTrait;
use Tests\Support\ItemFixtureTrait;
use Tests\Support\PurchaseAccountingFixtureTrait;
use Tests\Support\ReportFixtureTrait;
use Tests\Support\SaleAccountingFixtureTrait;
use Tests\Support\StatementFixtureTrait;

/**
 * How the accounts of the chart are listed in a statement: the level, the choice of accounts, the
 * order under the parents, the folding of the accounts of parties, the direction of each section,
 * and that the totals are those of the accounts at the top of the chart whatever is listed.
 */
class StatementFiguresTest extends CIUnitTestCase
{
    use DatabaseTestTrait;
    use AccountingFixtureTrait;
    use EmployeeFixtureTrait;
    use ItemFixtureTrait;
    use ReportFixtureTrait;
    use SaleAccountingFixtureTrait;
    use PurchaseAccountingFixtureTrait;
    use StatementFixtureTrait;

    protected $migrate     = true;
    protected $migrateOnce = true;
    protected $refresh     = false;
    protected $namespace   = null;

    public static function setUpBeforeClass(): void
    {
        $seeder = Database::seeder('tests');
        $seeder->call('TestDatabaseBootstrapSeeder');
    }

    protected function setUp(): void
    {
        parent::setUp();

        $this->resetBranches();
        $this->reinstallChart();
        $this->setConfigValue('accounting_base_currency', 'SAR');

        $this->second = $this->createBranch('T2', 'Second branch');
    }

    protected function tearDown(): void
    {
        $this->stopBuying();
        $this->clearJournals();
        $this->reinstallChart();
        $this->resetBranches();

        parent::tearDown();
    }

    /**
     * @param list<AccountType> $shown
     */
    private function figures(string $to = '2026-12-31', ?string $from = null, bool $leaveOutYearEnd = false, ?ReportScope $scope = null, array $shown = []): StatementFigures
    {
        return StatementFigures::read(new TrialBalance(), $scope ?? ReportScope::resolve(null, null), ReportPeriod::of($from, $to), $leaveOutYearEnd, $shown);
    }

    /**
     * @return list<string>
     */
    private function codes(StatementFigures $figures, AccountType $type, int $level, StatementDisplay $display = StatementDisplay::All, bool $expand = false): array
    {
        return array_map(static fn (StatementLine $line): string => $line->code, $figures->section($type, $level, $display, $expand)->lines);
    }

    private function amount(StatementFigures $figures, AccountType $type, string $code, int $level = 5, StatementDisplay $display = StatementDisplay::All, bool $expand = false): ?string
    {
        foreach ($figures->section($type, $level, $display, $expand)->lines as $line) {
            if ($line->code === $code) {
                return $line->amount;
            }
        }

        return null;
    }

    public function testLevelFor_UsesTheDefaultWhenNoLevelIsAskedOrTheLevelIsNotOne(): void
    {
        $figures = $this->figures();

        $this->assertSame(3, $figures->levelFor(null, 3));
        $this->assertSame(2, $figures->levelFor(0, 2));
        $this->assertSame(2, $figures->levelFor(-4, 2));
    }

    public function testLevelFor_KeepsALevelThatTheChartHasAndNeverGoesPastTheDeepestOne(): void
    {
        $figures = $this->figures();

        $this->assertSame(1, $figures->levelFor(1, 3));
        $this->assertSame(4, $figures->levelFor(4, 3));
        $this->assertSame(4, $figures->levelFor(5, 3), 'The default chart has no account at the fifth level');
        $this->assertSame(4, $figures->levelFor(99, 3));
        $this->assertSame(4, $figures->levelFor(null, 5));
    }

    public function testDeepestLevel_CountsTheAccountsFromTheTopOfTheChartToTheDeepestOne(): void
    {
        $this->assertSame(4, $this->figures()->deepestLevel(), '1, 11, 111, 111001');

        (new EntityAccountService())->ensure(EntityAccountService::CUSTOMER, $this->insertCustomer(), 'Omar Khaled');

        $this->assertSame(5, $this->figures()->deepestLevel(), '1, 12, 1204, 12041 and the account of the customer');
    }

    public function testDeepestLevel_CountsOnlyTheAccountsOfTheTypesTheStatementLists(): void
    {
        (new EntityAccountService())->ensure(EntityAccountService::CUSTOMER, $this->insertCustomer(), 'Omar Khaled');

        $this->assertSame(3, $this->figures(shown: [AccountType::Revenue, AccountType::Expense])->deepestLevel(), 'Revenue and expenses stop at 4, 41, 411');
        $this->assertSame(5, $this->figures(shown: [AccountType::Asset, AccountType::Liability, AccountType::Equity])->deepestLevel());
        $this->assertSame(2, $this->figures(shown: [AccountType::Equity])->deepestLevel(), 'Equity is two accounts deep: 3, 31');
        $this->assertSame(3, $this->figures(shown: [AccountType::Revenue, AccountType::Expense])->levelFor(99, 2), 'A level past the deepest of the listed types is that deepest');

        $this->customAccount('54', '5499', 'Rare expenses', true);
        $this->customAccount('5499', '549901', 'Rarer expenses');

        $this->assertSame(4, $this->figures(shown: [AccountType::Revenue, AccountType::Expense])->deepestLevel());
    }

    public function testDeepestLevel_StopsAtTheFifthWhateverTheChartHolds(): void
    {
        $this->customAccount('111001', '1110011', 'Deeper', true);
        $this->customAccount('1110011', '11100111', 'Deeper still', true);
        $this->customAccount('11100111', '111001111', 'Deepest');

        $this->assertSame(5, $this->figures()->deepestLevel());
        $this->assertSame(5, $this->figures()->levelFor(8, 3));
    }

    public function testSection_ListsTheTopOfTheChartAtLevelOneAndTheAccountsUnderItAsTheLevelGrows(): void
    {
        $figures = $this->figures();

        $this->assertSame(['4'], $this->codes($figures, AccountType::Revenue, 1));
        $this->assertSame(['4', '41', '42'], $this->codes($figures, AccountType::Revenue, 2));
        $this->assertSame(['4', '41', '411', '412', '42', '4201', '4202', '4203', '4204', '4208', '4209', '4213'], $this->codes($figures, AccountType::Revenue, 3));
        $this->assertSame(['5', '51', '52', '53', '54'], $this->codes($figures, AccountType::Expense, 2));
    }

    public function testSection_GivesEachLineItsLevel(): void
    {
        $lines = $this->figures()->section(AccountType::Asset, 4, StatementDisplay::All, false)->lines;
        $levels = array_column(array_map(static fn (StatementLine $line): array => [$line->code, $line->level], $lines), 1, 0);

        $this->assertSame(1, $levels['1']);
        $this->assertSame(2, $levels['12']);
        $this->assertSame(3, $levels['1201']);
        $this->assertSame(4, $levels['120101']);
        $this->assertSame(4, $levels['111001']);
    }

    public function testSection_ListsAnAccountUnderItsParentWhateverItsCodeSortsBeside(): void
    {
        $this->postOn('2026-10-05', 1, [[$this->accountId('tax_input'), '50.00', '0'], [$this->accountId('branches_payable'), '0', '50.00']]);

        $codes = $this->codes($this->figures(), AccountType::Liability, 4, StatementDisplay::HideZero);

        $this->assertSame(['2', '21', '211', '2214', '215', '215002'], $codes, 'Under 211 comes its own account and then the accounts of 215, not the accounts that sort between them');
    }

    public function testSection_ReadsEachSectionInItsOwnDirection(): void
    {
        $this->postOn('2026-10-05', 1, [[$this->accountId('cash'), '100.00', '0'], [$this->accountId('sales_revenue'), '0', '100.00']]);
        $this->postOn('2026-10-06', 1, [[$this->accountId('sales_returns'), '30.00', '0'], [$this->accountId('cash'), '0', '30.00']]);
        $this->postOn('2026-10-07', 1, [[$this->accountId('cogs'), '20.00', '0'], [$this->accountId('other_payables'), '0', '20.00']]);
        $this->postOn('2026-10-08', 1, [[$this->accountId('other_payables'), '5.00', '0'], [$this->accountId('cash'), '0', '5.00']]);

        $figures = $this->figures();

        $this->assertSame('65.0000', $this->amount($figures, AccountType::Asset, '120101'), 'An asset is what was debited less what was credited');
        $this->assertSame('15.0000', $this->amount($figures, AccountType::Liability, '2213'), 'A liability is what was credited less what was debited');
        $this->assertSame('100.0000', $this->amount($figures, AccountType::Revenue, '411'));
        $this->assertSame('-30.0000', $this->amount($figures, AccountType::Revenue, '412'), 'A debit balance in the revenue is a minus');
        $this->assertSame('20.0000', $this->amount($figures, AccountType::Expense, '511'));
    }

    public function testSection_ListsEveryAccountOfTheLevelsWhenAllAreAsked(): void
    {
        $this->cashSale('2026-10-05', 1, '100.00');

        $all = $this->codes($this->figures(), AccountType::Revenue, 3, StatementDisplay::All);

        $this->assertContains('412', $all, 'An account that never held anything is listed');
        $this->assertContains('4203', $all);
    }

    public function testSection_ListsOnlyTheAccountsThatMovedWhenAsked(): void
    {
        $journal = $this->cashSale('2026-10-05', 1, '100.00');
        $this->reverseOn($journal, '2026-10-06');
        $this->cashSale('2026-10-07', 1, '10.00');
        $this->postOn('2026-10-08', 1, [[$this->accountId('cash'), '5.00', '0'], [$this->accountId('sales_returns'), '0', '5.00']]);

        $moved = $this->codes($this->figures(), AccountType::Revenue, 3, StatementDisplay::WithActivity);

        $this->assertSame(['4', '41', '411', '412'], $moved);
        $this->assertNotContains('42', $moved);
        $this->assertNotContains('4203', $moved);
    }

    public function testSection_ListsAnAccountThatMovedAndStandsAtNothingOnlyWhenActivityIsAsked(): void
    {
        $journal = $this->cashSale('2026-10-05', 1, '100.00');
        $this->reverseOn($journal, '2026-10-06');

        $figures = $this->figures();

        $this->assertContains('411', $this->codes($figures, AccountType::Revenue, 3, StatementDisplay::WithActivity), 'It was sold and reversed');
        $this->assertSame([], $this->codes($figures, AccountType::Revenue, 3, StatementDisplay::HideZero), 'It stands at nothing');
    }

    public function testSection_HidesTheAccountsThatStandAtNothing(): void
    {
        $this->cashSale('2026-10-05', 1, '100.00');

        $figures = $this->figures();

        $this->assertSame(['4', '41', '411'], $this->codes($figures, AccountType::Revenue, 3, StatementDisplay::HideZero));
        $this->assertSame('100.0000', $this->amount($figures, AccountType::Revenue, '411', 3, StatementDisplay::HideZero));
    }

    public function testSection_KeepsTheParentOfAnAccountItListsEvenWhenTheParentStandsAtNothing(): void
    {
        $this->cashSale('2026-10-05', 1, '100.00');
        $this->postOn('2026-10-06', 1, [[$this->accountId('sales_returns'), '100.00', '0'], [$this->accountId('cash'), '0', '100.00']]);

        $lines = $this->figures()->section(AccountType::Revenue, 3, StatementDisplay::HideZero, false)->lines;

        $this->assertSame(['4', '41', '411', '412'], array_map(static fn (StatementLine $line): string => $line->code, $lines));
        $this->assertSame('0.0000', $lines[1]->amount, 'The sales and the returns offset in their group');
    }

    public function testSection_FoldsTheAccountOfAPartyUnlessAskedForAndNeverListsAnEmptyOne(): void
    {
        $entities = new EntityAccountService();
        $customer = $entities->ensure(EntityAccountService::CUSTOMER, $this->insertCustomer(), 'Omar Khaled');
        $idle     = $entities->ensure(EntityAccountService::CUSTOMER, $this->insertCustomer('Hind', 'Saad'), 'Hind Saad');

        $this->postOn('2026-10-05', 1, [[$customer, '250.00', '0'], [$this->accountId('sales_revenue'), '0', '250.00']]);

        $figures = $this->figures();
        $folded  = $figures->section(AccountType::Asset, 5, StatementDisplay::All, false);
        $opened  = $figures->section(AccountType::Asset, 5, StatementDisplay::All, true);
        $ids     = static fn ($section): array => array_map(static fn (StatementLine $line): int => $line->accountId, $section->lines);

        $this->assertNotContains($customer, $ids($folded));
        $this->assertContains($customer, $ids($opened));
        $this->assertNotContains($idle, $ids($opened), 'A party that holds nothing is not listed');
        $this->assertSame($folded->total, $opened->total);
        $this->assertTrue(array_values(array_filter($opened->lines, static fn (StatementLine $line): bool => $line->accountId === $customer))[0]->isEntity);
    }

    public function testSection_DoesNotListAPartyBelowTheLevelAskedEvenWhenAskedFor(): void
    {
        $customer = (new EntityAccountService())->ensure(EntityAccountService::CUSTOMER, $this->insertCustomer(), 'Omar Khaled');

        $this->postOn('2026-10-05', 1, [[$customer, '250.00', '0'], [$this->accountId('sales_revenue'), '0', '250.00']]);

        $figures = $this->figures();

        $this->assertNull($this->amount($figures, AccountType::Asset, $this->codeOfAccount($customer), 4, expand: true));
        $this->assertSame('250.0000', $this->amount($figures, AccountType::Asset, $this->codeOfAccount($customer), 5, expand: true));
        $this->assertSame('250.0000', $this->amount($figures, AccountType::Asset, '12041', 4, expand: true), 'It stays in the account above');
    }

    public function testSection_ListsAPartyThatMovedAndNetsToNothingOnlyWhenActivityIsAsked(): void
    {
        $customer = (new EntityAccountService())->ensure(EntityAccountService::CUSTOMER, $this->insertCustomer(), 'Omar Khaled');

        $this->postOn('2026-10-05', 1, [[$customer, '100.00', '0'], [$this->accountId('sales_revenue'), '0', '100.00']]);
        $this->postOn('2026-10-06', 1, [[$this->accountId('cash'), '100.00', '0'], [$customer, '0', '100.00']]);

        $ids = fn (StatementDisplay $display): array => array_map(static fn (StatementLine $line): int => $line->accountId, $this->figures()->section(AccountType::Asset, 5, $display, true)->lines);

        $this->assertContains($customer, $ids(StatementDisplay::WithActivity));
        $this->assertContains($customer, $ids(StatementDisplay::All));
        $this->assertNotContains($customer, $ids(StatementDisplay::HideZero), 'It stands at nothing');
    }

    public function testSection_DoesNotListTheGroupOfPartiesThatOffsetEachOtherForTheSakeOfAFoldedParty(): void
    {
        $entities = new EntityAccountService();
        $owes     = $entities->ensure(EntityAccountService::CUSTOMER, $this->insertCustomer(), 'Omar Khaled');
        $paid     = $entities->ensure(EntityAccountService::CUSTOMER, $this->insertCustomer('Hind', 'Saad'), 'Hind Saad');

        $this->postOn('2026-10-05', 1, [[$owes, '100.00', '0'], [$this->accountId('sales_revenue'), '0', '100.00']]);
        $this->postOn('2026-10-06', 1, [[$this->accountId('cash'), '100.00', '0'], [$paid, '0', '100.00']]);

        $group = $this->accountIdByCode('12041');
        $ids   = fn (bool $expand): array => array_map(static fn (StatementLine $line): int => $line->accountId, $this->figures()->section(AccountType::Asset, 5, StatementDisplay::HideZero, $expand)->lines);

        $this->assertNotContains($group, $ids(false), 'The customers offset each other in their group, which stands at nothing');
        $this->assertContains($group, $ids(true), 'Listing a customer lists the group above it');
        $this->assertContains($owes, $ids(true));
    }

    public function testSection_ListsAnAccountUnderAFoldedLocationWithoutTheAccountOfTheLocation(): void
    {
        $entity = (new EntityAccountService())->ensure(EntityAccountService::STOCK_LOCATION, $this->insertStockLocation('Depot'), 'Depot');
        $now    = date('Y-m-d H:i:s');
        $db     = $this->accountingDb();
        $parent = $db->table('accounting_accounts')->where('account_id', $entity)->get()->getRowArray();

        unset($parent['account_id']);
        $db->table('accounting_accounts')->insert([...$parent, 'parent_id' => $entity, 'code' => $parent['code'] . '01', 'name' => 'Under a location', 'entity_type' => null, 'entity_id' => null, 'is_system' => 0, 'system_key' => null, 'created_at' => $now, 'updated_at' => $now]);

        $child = (int) $db->insertID();

        $this->postOn('2026-10-05', 1, [[$child, '30.00', '0'], [$this->accountId('sales_revenue'), '0', '30.00']]);

        $ids = static fn (StatementFigures $figures, bool $expand): array => array_map(static fn (StatementLine $line): int => $line->accountId, $figures->section(AccountType::Asset, 5, StatementDisplay::HideZero, $expand)->lines);

        $this->assertContains($child, $ids($this->figures(), false));
        $this->assertNotContains($entity, $ids($this->figures(), false), 'The account of the location is folded away');
        $this->assertContains($entity, $ids($this->figures(), true));
    }

    private function codeOfAccount(int $accountId): string
    {
        return (string) $this->accountingDb()->table('accounting_accounts')->select('code')->where('account_id', $accountId)->get()->getRow()->code;
    }

    public function testSection_AddsUpTheSameWhateverIsListed(): void
    {
        $this->observedBooks(1, $this->second);

        $figures = $this->figures();

        foreach ([AccountType::Asset, AccountType::Liability, AccountType::Equity, AccountType::Revenue, AccountType::Expense] as $type) {
            $totals = [];

            foreach ([1, 2, 4] as $level) {
                foreach (StatementDisplay::cases() as $display) {
                    foreach ([false, true] as $expand) {
                        $totals[] = $figures->section($type, $level, $display, $expand)->total;
                    }
                }
            }

            $this->assertSame([$figures->total($type)], array_values(array_unique($totals)), $type->value);
        }
    }

    public function testTotal_AddsUpTheAccountsAtTheTopOfTheChartOfTheType(): void
    {
        $this->observedBooks(1, $this->second);

        $figures = $this->figures();

        $this->assertSame('96991.8400', $figures->total(AccountType::Asset));
        $this->assertSame('268.3000', $figures->total(AccountType::Liability));
        $this->assertSame('100000.0000', $figures->total(AccountType::Equity));
        $this->assertSame('8112.0100', $figures->total(AccountType::Revenue));
        $this->assertSame('11388.4700', $figures->total(AccountType::Expense));
    }

    public function testRead_LeavesOutTheJournalsThatCloseAYearOnlyWhenAsked(): void
    {
        $this->cashSale('2026-10-05', 1, '100.00');
        $this->postYearEnd('2026-12-31', 1, [[$this->accountId('sales_revenue'), '100.00', '0'], [$this->accountId('retained_earnings'), '0', '100.00']]);

        $this->assertSame('100.0000', $this->figures(leaveOutYearEnd: true)->total(AccountType::Revenue));
        $this->assertSame('0.0000', $this->figures(leaveOutYearEnd: false)->total(AccountType::Revenue));
        $this->assertSame('0.0000', $this->figures(leaveOutYearEnd: true)->total(AccountType::Equity), 'The closing moved nothing to account 32 for the statement of a period');
        $this->assertSame('100.0000', $this->figures(leaveOutYearEnd: false)->total(AccountType::Equity));
    }

    public function testRead_ReadsOnlyThePeriodAskedFor(): void
    {
        $this->cashSale('2026-09-30', 1, '1.00');
        $this->cashSale('2026-10-01', 1, '2.00');
        $this->cashSale('2026-10-31', 1, '4.00');
        $this->cashSale('2026-11-01', 1, '8.00');

        $this->assertSame('6.0000', $this->figures('2026-10-31', '2026-10-01')->total(AccountType::Revenue));
        $this->assertSame('7.0000', $this->figures('2026-10-31')->total(AccountType::Revenue));
    }

    public function testRead_ReadsOnlyTheBranchesOfTheScope(): void
    {
        $this->cashSale('2026-10-05', 1, '100.00');
        $this->cashSale('2026-10-05', $this->second, '30.00');

        $this->assertSame('100.0000', $this->figures(scope: ReportScope::resolve([1], null))->total(AccountType::Revenue));
        $this->assertSame('30.0000', $this->figures(scope: ReportScope::resolve([$this->second], null))->total(AccountType::Revenue));
        $this->assertSame('130.0000', $this->figures()->total(AccountType::Revenue));
        $this->assertSame('0.0000', $this->figures(scope: ReportScope::resolve(null, []))->total(AccountType::Revenue));
    }

    public function testSection_FinishesWhenTheAccountsOfTheChartAreEachOtherParents(): void
    {
        $cash  = $this->accountId('cash');
        $sales = $this->accountId('sales_revenue');

        $this->postOn('2026-10-05', 1, [[$cash, '100.00', '0'], [$sales, '0', '100.00']]);

        $this->accountingDb()->table('accounting_accounts')->where('account_id', $cash)->update(['parent_id' => $sales]);
        $this->accountingDb()->table('accounting_accounts')->where('account_id', $sales)->update(['parent_id' => $cash]);

        $figures = $this->figures();

        $this->assertGreaterThanOrEqual(1, $figures->deepestLevel());
        $this->assertContains('411', $this->codes($figures, AccountType::Revenue, 5));
        $this->assertContains('120101', $this->codes($figures, AccountType::Asset, 5));
    }
}
