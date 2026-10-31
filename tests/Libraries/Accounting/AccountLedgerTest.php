<?php

namespace Tests\Libraries\Accounting;

use App\Libraries\Accounting\AccountingError;
use App\Libraries\Accounting\AccountingException;
use App\Libraries\Accounting\AccountLedger;
use App\Libraries\Accounting\AccountLedgerBlocksReport;
use App\Libraries\Accounting\AccountLedgerReport;
use App\Libraries\Accounting\EntityAccountService;
use App\Libraries\Accounting\JournalService;
use App\Libraries\Accounting\LedgerBlock;
use App\Libraries\Accounting\LedgerDisplay;
use App\Libraries\Accounting\NormalBalance;
use App\Libraries\Accounting\ReportPeriod;
use App\Libraries\Accounting\ReportScope;
use App\Libraries\Accounting\TrialBalance;
use CodeIgniter\Database\Query;
use CodeIgniter\Events\Events;
use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\DatabaseTestTrait;
use Config\Database;
use Tests\Support\AccountingFixtureTrait;
use Tests\Support\ParityCaseTrait;
use Tests\Support\ReportFixtureTrait;

/**
 * The statement of an account: its lines in the order they happened with the balance after each,
 * where it stood when the period opened, the accounts under it, the branches, and the pages.
 */
class AccountLedgerTest extends CIUnitTestCase
{
    use DatabaseTestTrait;
    use AccountingFixtureTrait;
    use ParityCaseTrait;
    use ReportFixtureTrait;

    protected $migrate     = true;
    protected $migrateOnce = true;
    protected $refresh     = false;
    protected $namespace   = null;

    private AccountLedger $ledger;
    private int $second;

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

        $this->second = $this->createBranch('L2', 'Second branch');
        $this->ledger = new AccountLedger();
    }

    protected function tearDown(): void
    {
        $this->clearJournals();
        $this->reinstallChart();
        $this->resetBranches();

        parent::tearDown();
    }

    private function statement(string $account, string $to = '2026-12-31', ?string $from = null, ?ReportScope $scope = null, int $limit = 100, int $offset = 0): AccountLedgerReport
    {
        return $this->ledger->build($scope ?? ReportScope::resolve(null, null), $this->accountId($account), ReportPeriod::of($from, $to), $limit, $offset);
    }

    /**
     * @return list<string> "date debit credit balance" of each line.
     */
    private function lines(AccountLedgerReport $report): array
    {
        return array_map(static fn (array $row): string => $row['date'] . ' ' . $row['debit'] . ' ' . $row['credit'] . ' ' . $row['balance'], $report->rows);
    }

    public function testBuild_ListsTheLinesOfAnAccountInTheOrderTheyHappenedWithTheBalanceAfterEach(): void
    {
        $this->cashSale('2026-10-07', 1, '40.00');
        $this->cashSale('2026-10-05', 1, '100.00');
        $this->postOn('2026-10-06', 1, [[$this->accountId('cogs'), '30.00', '0'], [$this->accountId('cash'), '0', '30.00']]);

        $report = $this->statement('cash');

        $this->assertSame([
            '2026-10-05 100.0000 0.0000 100.0000',
            '2026-10-06 0.0000 30.0000 70.0000',
            '2026-10-07 40.0000 0.0000 110.0000',
        ], $this->lines($report));
        $this->assertSame(['0.0000', '140.0000', '30.0000', '110.0000', 3], [$report->opening, $report->debit, $report->credit, $report->closing, $report->totalRows]);
        $this->assertSame(NormalBalance::Debit, $report->normal);
        $this->assertSame('120101', $report->code);
    }

    public function testBuild_KeepsTheBalanceOfAnAccountThatNormallyHoldsACreditAsAPositiveCredit(): void
    {
        $this->cashSale('2026-10-05', 1, '100.00');
        $this->postOn('2026-10-06', 1, [[$this->accountId('sales_revenue'), '15.00', '0'], [$this->accountId('cash'), '0', '15.00']]);

        $report = $this->statement('sales_revenue');

        $this->assertSame(NormalBalance::Credit, $report->normal);
        $this->assertSame(['100.0000', '85.0000'], array_column($report->rows, 'balance'));
        $this->assertSame('85.0000', $report->closing);
    }

    public function testBuild_StartsFromWhereTheAccountStoodWhenThePeriodOpened(): void
    {
        $this->cashSale('2026-09-29', 1, '60.00');
        $this->cashSale('2026-09-30', 1, '10.00');
        $this->cashSale('2026-10-05', 1, '100.00');
        $this->cashSale('2026-11-05', 1, '1000.00');

        $report = $this->statement('cash', '2026-10-31', '2026-10-01');

        $this->assertSame('70.0000', $report->opening);
        $this->assertSame(['2026-10-05 100.0000 0.0000 170.0000'], $this->lines($report));
        $this->assertSame('170.0000', $report->closing);
    }

    public function testBuild_CountsTheFirstDayInThePeriodAndNotInWhatCameBefore(): void
    {
        $this->cashSale('2026-09-30', 1, '10.00');
        $this->cashSale('2026-10-01', 1, '100.00');

        $report = $this->statement('cash', '2026-10-31', '2026-10-01');

        $this->assertSame('10.0000', $report->opening);
        $this->assertSame(['2026-10-01 100.0000 0.0000 110.0000'], $this->lines($report));
    }

    public function testBuild_CountsTheLastDayInThePeriodAndNotWhatCameAfter(): void
    {
        $this->cashSale('2026-10-31', 1, '100.00');
        $this->cashSale('2026-11-01', 1, '1000.00');

        $report = $this->statement('cash', '2026-10-31');

        $this->assertSame(['2026-10-31 100.0000 0.0000 100.0000'], $this->lines($report));
        $this->assertSame('100.0000', $report->closing);
    }

    public function testBuild_LeavesOutADraftThatWasNeverPosted(): void
    {
        $this->cashSale('2026-10-05', 1, '100.00');
        (new JournalService())->saveDraft($this->journalInput('500.00'), null);

        $report = $this->statement('cash');

        $this->assertSame(['2026-10-05 100.0000 0.0000 100.0000'], $this->lines($report));
        $this->assertSame(1, $report->totalRows);
    }

    public function testBuild_StartsFromWhereTheAccountStoodInTheDirectionItNormallyHolds(): void
    {
        $this->cashSale('2026-09-30', 1, '60.00');
        $this->cashSale('2026-10-05', 1, '40.00');

        $report = $this->statement('sales_revenue', '2026-10-31', '2026-10-01');

        $this->assertSame('60.0000', $report->opening, 'A credit account that was credited stands at a positive');
        $this->assertSame(['2026-10-05 0.0000 40.0000 100.0000'], $this->lines($report));
    }

    public function testBuild_ReadsTheAccountsUnderAnAccountWithIt(): void
    {
        $this->cashSale('2026-10-05', 1, '100.00');
        $this->postOn('2026-10-06', 1, [[$this->accountId('cogs'), '30.00', '0'], [$this->accountId('cash'), '0', '30.00']]);

        $report = $this->ledger->build(ReportScope::resolve(null, null), $this->accountIdByCode('1201'), ReportPeriod::of(null, '2026-12-31'));

        $this->assertTrue($report->isGroup);
        $this->assertGreaterThan(1, $report->accountsCounted);
        $this->assertSame(['120101', '120101'], array_column($report->rows, 'account_code'));
        $this->assertSame('70.0000', $report->closing);
    }

    public function testBuild_AddsUpOnlyTheBranchesOfTheScopeAndNamesTheBranchOfEachLine(): void
    {
        $this->cashSale('2026-10-05', 1, '100.00');
        $this->cashSale('2026-10-06', $this->second, '55.50');

        $first = $this->statement('cash', scope: ReportScope::resolve([1], null));
        $both  = $this->statement('cash');

        $this->assertSame(['HQ'], array_column($first->rows, 'branch_code'));
        $this->assertSame('100.0000', $first->closing);
        $this->assertSame(['HQ', 'L2'], array_column($both->rows, 'branch_code'));
        $this->assertSame('155.5000', $both->closing);
        $this->assertSame(['100.0000', '155.5000'], array_column($both->rows, 'balance'));
    }

    public function testBuild_HasNothingToShowForNoBranch(): void
    {
        $this->cashSale('2026-10-05', 1, '100.00');

        $report = $this->statement('cash', scope: ReportScope::resolve(null, []));

        $this->assertSame([], $report->rows);
        $this->assertSame(0, $report->totalRows);
        $this->assertSame('0.0000', $report->closing);
    }

    public function testBuild_ShowsAReversedJournalAndItsReversalAsTwoLines(): void
    {
        $journal  = $this->cashSale('2026-10-05', 1, '100.00');
        $reversal = $this->reverseOn($journal, '2026-10-06');

        $report = $this->statement('cash');

        $this->assertSame([$journal, $reversal], array_column($report->rows, 'journal_id'));
        $this->assertSame([null, $journal], array_column($report->rows, 'reverses_journal_id'));
        $this->assertSame(['100.0000', '0.0000'], array_column($report->rows, 'balance'));
    }

    public function testBuild_TakesThePageAskedForAndKeepsTheBalanceRunningAcrossPages(): void
    {
        foreach (range(1, 7) as $day) {
            $this->cashSale('2026-10-0' . $day, 1, (string) ($day * 10) . '.00');
        }

        $whole = $this->statement('cash');
        $pages = [];

        foreach ([0, 3, 6] as $offset) {
            $pages[] = $this->statement('cash', limit: 3, offset: $offset);
        }

        $this->assertSame(7, $whole->totalRows);
        $this->assertSame([3, 3, 1], array_map(static fn (AccountLedgerReport $page): int => count($page->rows), $pages));
        $this->assertSame(array_column($whole->rows, 'balance'), array_merge(...array_map(static fn (AccountLedgerReport $page): array => array_column($page->rows, 'balance'), $pages)));
        $this->assertSame(array_column($whole->rows, 'line_id'), array_merge(...array_map(static fn (AccountLedgerReport $page): array => array_column($page->rows, 'line_id'), $pages)));

        foreach ($pages as $page) {
            $this->assertSame(7, $page->totalRows);
            $this->assertSame($whole->debit, $page->debit, 'The totals are of the whole period and not of the page');
            $this->assertSame($whole->closing, $page->closing);
        }
    }

    public function testBuild_ReturnsNoLinesForAPageBeyondTheEnd(): void
    {
        $this->cashSale('2026-10-05', 1, '100.00');

        $report = $this->statement('cash', limit: 10, offset: 50);

        $this->assertSame([], $report->rows);
        $this->assertSame(1, $report->totalRows);
        $this->assertSame('100.0000', $report->closing);
    }

    public function testBuild_ShowsTheDescriptionOfTheLineAndOtherwiseOfTheJournal(): void
    {
        $this->postOn('2026-10-05', 1, [[$this->accountId('cash'), '10.00', '0'], [$this->accountId('sales_revenue'), '0', '10.00']], 'The journal says');

        $this->assertSame(['The journal says'], array_column($this->statement('cash')->rows, 'description'));
    }

    public function testBuild_RejectsAnAccountThatIsNotInTheChart(): void
    {
        try {
            $this->ledger->build(ReportScope::resolve(null, null), 987654, ReportPeriod::of(null, '2026-12-31'));
            $this->fail('An account that does not exist was accepted.');
        } catch (AccountingException $refusal) {
            $this->assertSame(AccountingError::ReportAccountMissing, $refusal->error);
        }
    }

    public function testBuild_ReadsAnAccountWithNoLinesAsNothing(): void
    {
        $report = $this->statement('cash');

        $this->assertSame([], $report->rows);
        $this->assertSame(['0.0000', '0.0000', '0.0000', '0.0000'], [$report->opening, $report->debit, $report->credit, $report->closing]);
    }

    public function testBuild_ClosesEveryAccountAtWhatTheTrialBalanceHoldsForItInAnySetOfBranches(): void
    {
        $this->cashSale('2026-10-05', 1, '100.00');
        $this->cashSale('2026-10-06', $this->second, '60.00');
        $this->postOn('2026-10-07', 1, [[$this->accountId('cogs'), '30.00', '0'], [$this->accountId('cash'), '0', '30.00']]);
        $this->postOn('2026-10-08', $this->second, [[$this->accountId('sales_revenue'), '15.00', '0'], [$this->accountId('cash'), '0', '15.00']]);
        $this->reverseOn($this->cashSale('2026-10-09', 1, '25.00'), '2026-10-10');

        foreach ([null, [1], [$this->second]] as $branches) {
            $scope  = ReportScope::resolve($branches, null);
            $period = ReportPeriod::of(null, '2026-12-31');
            $report = (new TrialBalance())->build($scope, $period, true);

            $this->assertNotSame([], $report->rows);

            foreach ($report->rows as $row) {
                $statement = $this->ledger->build($scope, $row->accountId, $period, 1);
                $expected  = $statement->normal === NormalBalance::Debit ? $row->closing() : bcsub('0', $row->closing(), 4);

                $this->assertSame($expected, $statement->closing, 'Account ' . $row->code . ' in branches ' . json_encode($branches) . ': the statement and the trial balance differ');
            }
        }
    }

    private function blocks(string $account, string $to = '2026-12-31', ?string $from = null, LedgerDisplay $display = LedgerDisplay::Activity, ?ReportScope $scope = null, ?int $employeeId = null, ?AccountLedger $ledger = null): AccountLedgerBlocksReport
    {
        return ($ledger ?? $this->ledger)->buildBlocks($scope ?? ReportScope::resolve(null, null), $this->accountIdByCode($account), ReportPeriod::of($from, $to), $display, $employeeId);
    }

    /**
     * @return list<string> The code of each block.
     */
    private function blockCodes(AccountLedgerBlocksReport $report): array
    {
        return array_map(static fn (LedgerBlock $block): string => $block->code, $report->blocks);
    }

    private function employee(string $name): int
    {
        return $this->createEmployee(first_name: $name, last_name: 'Ledger');
    }

    public function testBuild_KeepsOnlyTheLinesOfTheEmployeeAskedForAndTheirOpeningBalance(): void
    {
        $first  = $this->employee('Ahmad');
        $second = $this->employee('Basma');

        $this->cashSale('2026-09-30', 1, '60.00', $first);
        $this->cashSale('2026-09-30', 1, '1000.00', $second);
        $this->cashSale('2026-10-05', 1, '100.00', $first);
        $this->cashSale('2026-10-06', 1, '25.00', $second);

        $report = $this->ledger->build(ReportScope::resolve(null, null), $this->accountId('cash'), ReportPeriod::of('2026-10-01', '2026-10-31'), employeeId: $first);

        $this->assertSame($first, $report->employeeId);
        $this->assertSame('60.0000', $report->opening, 'Limited to an employee, the opening balance is of the lines the employee added');
        $this->assertSame(['2026-10-05 100.0000 0.0000 160.0000'], $this->lines($report));
        $this->assertSame(['Ahmad Ledger'], array_column($report->rows, 'employee'));
        $this->assertSame(['100.0000', '0.0000', '160.0000'], [$report->debit, $report->credit, $report->closing]);
    }

    public function testBuild_ReturnsAnEmptyStatementForAnEmployeeWithNoLines(): void
    {
        $this->cashSale('2026-10-05', 1, '100.00', $this->employee('Ahmad'));

        $report = $this->ledger->build(ReportScope::resolve(null, null), $this->accountId('cash'), ReportPeriod::of(null, '2026-12-31'), employeeId: $this->employee('Basma'));

        $this->assertSame([], $report->rows);
        $this->assertSame(0, $report->totalRows);
        $this->assertSame(['0.0000', '0.0000', '0.0000', '0.0000'], [$report->opening, $report->debit, $report->credit, $report->closing]);
    }

    public function testBuild_ReadsTheLinesOfEveryEmployeeWhenNoneIsAsked(): void
    {
        $this->cashSale('2026-10-05', 1, '100.00', $this->employee('Ahmad'));
        $this->cashSale('2026-10-06', 1, '25.00', $this->employee('Basma'));
        $this->cashSale('2026-10-07', 1, '5.00');

        $report = $this->statement('cash');

        $this->assertNull($report->employeeId);
        $this->assertSame(3, $report->totalRows);
        $this->assertSame(['Ahmad Ledger', 'Basma Ledger', ''], array_column($report->rows, 'employee'));
    }

    public function testBuildBlocks_DrawsABlockForEachAccountUnderTheMainAccountWithItsOwnLines(): void
    {
        $second = $this->customAccount('1201', '120102', 'Second till');

        $this->cashSale('2026-10-05', 1, '100.00');
        $this->postOn('2026-10-06', 1, [[$second, '40.00', '0'], [$this->accountId('sales_revenue'), '0', '40.00']]);
        $this->postOn('2026-10-07', 1, [[$this->accountId('cogs'), '30.00', '0'], [$second, '0', '30.00']]);

        $report = $this->blocks('1201');

        $this->assertSame(['120101', '120102'], $this->blockCodes($report));
        $this->assertSame(['100.0000', '0.0000', '100.0000'], [$report->blocks[0]->debit, $report->blocks[0]->credit, $report->blocks[0]->closing]);
        $this->assertSame(['2026-10-06 40.0000 0.0000 40.0000', '2026-10-07 0.0000 30.0000 10.0000'], array_map(static fn (array $row): string => $row['date'] . ' ' . $row['debit'] . ' ' . $row['credit'] . ' ' . $row['balance'], $report->blocks[1]->rows));
        $this->assertSame('1201', $report->code);
    }

    public function testBuildBlocks_GivesTheNetOfDebitMinusCreditInTheGrandTotalOfAGroupStatement(): void
    {
        $second = $this->customAccount('1201', '120102', 'Second till');

        $this->cashSale('2026-10-05', 1, '100.00');
        $this->postOn('2026-10-06', 1, [[$second, '40.00', '0'], [$this->accountId('sales_revenue'), '0', '40.00']]);
        $this->postOn('2026-10-07', 1, [[$this->accountId('cogs'), '30.00', '0'], [$second, '0', '30.00']]);

        $report = $this->blocks('1201');

        $this->assertSame(['140.0000', '30.0000', '110.0000'], [$report->debit, $report->credit, $report->net()], 'The total line carries what the blocks debited and credited and the difference, not the sum of balances');
        $this->assertSame($this->ledger->build(ReportScope::resolve(null, null), $this->accountIdByCode('1201'), ReportPeriod::of(null, '2026-12-31'))->closing, $report->net());
    }

    public function testBuildBlocks_ListsAccountsByTheThreeDisplayChoicesOfAGroupStatement(): void
    {
        $opened = $this->customAccount('1201', '120102', 'Opened earlier');
        $this->customAccount('1201', '120103', 'Never used');

        $this->postOn('2026-10-02', 1, [[$opened, '75.00', '0'], [$this->accountId('sales_revenue'), '0', '75.00']]);
        $this->cashSale('2026-10-12', 1, '100.00');

        $codes = fn (LedgerDisplay $display): array => $this->blockCodes($this->blocks('1201', '2026-10-31', '2026-10-10', $display));

        $this->assertSame(['120101'], $codes(LedgerDisplay::Activity), 'Accounts with a line in the period');
        $this->assertSame(['120101', '120102'], $codes(LedgerDisplay::NonZeroBalance), 'The account that stood at something when the period opened stays although it has no line in it');
        $this->assertSame(['120101', '120102', '120103'], $codes(LedgerDisplay::All), 'Every account under the main account');
    }

    public function testBuildBlocks_DropsAnAccountThatStandsAtNothingAtBothEndsUnderTheChoiceOfAccountsThatStandAtSomething(): void
    {
        $this->cashSale('2026-10-12', 1, '50.00');
        $this->postOn('2026-10-13', 1, [[$this->accountId('cogs'), '50.00', '0'], [$this->accountId('cash'), '0', '50.00']]);

        $this->assertSame(['120101'], $this->blockCodes($this->blocks('1201', display: LedgerDisplay::Activity)));
        $this->assertSame([], $this->blockCodes($this->blocks('1201', display: LedgerDisplay::NonZeroBalance)));
    }

    public function testBuildBlocks_StartsEachBlockFromWhereItsAccountStoodWhenThePeriodOpened(): void
    {
        $this->cashSale('2026-09-29', 1, '60.00');
        $this->cashSale('2026-10-05', 1, '100.00');
        $this->postOn('2026-10-06', 1, [[$this->accountId('cogs'), '30.00', '0'], [$this->accountId('cash'), '0', '30.00']]);

        $block = $this->blocks('1201', '2026-10-31', '2026-10-01')->blocks[0];

        $this->assertSame('60.0000', $block->opening);
        $this->assertSame(['160.0000', '130.0000'], array_column($block->rows, 'balance'));
        $this->assertSame(['100.0000', '30.0000', '130.0000'], [$block->debit, $block->credit, $block->closing]);
    }

    public function testBuildBlocks_ClosesEveryBlockAtWhatTheStatementOfItsAccountSays(): void
    {
        $second = $this->customAccount('1201', '120102', 'Second till');

        $this->cashSale('2026-10-05', 1, '100.00');
        $this->postOn('2026-10-06', $this->second, [[$second, '40.00', '0'], [$this->accountId('sales_revenue'), '0', '40.00']]);
        $this->reverseOn($this->cashSale('2026-10-07', 1, '25.00'), '2026-10-08');

        foreach ([null, [1], [$this->second]] as $branches) {
            $scope = ReportScope::resolve($branches, null);

            foreach ($this->blocks('1201', scope: $scope, display: LedgerDisplay::All)->blocks as $block) {
                $statement = $this->ledger->build($scope, $block->accountId, ReportPeriod::of(null, '2026-12-31'));
                $expected  = bcsub($statement->normal === NormalBalance::Debit ? $statement->closing : bcsub('0', $statement->closing, 4), '0', 4);

                $this->assertSame($expected, $block->closing, 'Account ' . $block->code . ' in branches ' . json_encode($branches));
            }
        }
    }

    public function testBuildBlocks_NeverNamesAPartyThatHoldsNothingInTheBranchesOfTheReport(): void
    {
        $customer = (new EntityAccountService())->ensure(EntityAccountService::CUSTOMER, $this->insertCustomer(), 'Omar Khaled');
        $other    = (new EntityAccountService())->ensure(EntityAccountService::CUSTOMER, $this->insertCustomer('Layla', 'Hamad'), 'Layla Hamad');

        $this->postOn('2026-10-05', 1, [[$customer, '250.00', '0'], [$this->accountId('sales_revenue'), '0', '250.00']]);
        $this->postOn('2026-10-06', $this->second, [[$other, '80.00', '0'], [$this->accountId('sales_revenue'), '0', '80.00']]);

        $first = $this->blocks('12041', display: LedgerDisplay::All, scope: ReportScope::resolve([1], null));
        $both  = $this->blocks('12041', display: LedgerDisplay::All);

        $this->assertSame([$this->codeOfAccount($customer)], $this->blockCodes($first), 'The party of the other branch is not named');
        $this->assertCount(2, $both->blocks);
        $this->assertSame('250.0000', $first->net());
        $this->assertSame('330.0000', $both->net());
    }

    public function testBuildBlocks_ReadsOnlyTheLinesOfTheEmployeeAskedFor(): void
    {
        $first  = $this->employee('Ahmad');
        $second = $this->employee('Basma');
        $till   = $this->customAccount('1201', '120102', 'Second till');

        $this->cashSale('2026-10-05', 1, '100.00', $first);
        $this->postOn('2026-10-06', 1, [[$till, '40.00', '0'], [$this->accountId('sales_revenue'), '0', '40.00']], employeeId: $second);

        $report = $this->blocks('1201', employeeId: $second);

        $this->assertSame(['120102'], $this->blockCodes($report));
        $this->assertSame($second, $report->employeeId);
        $this->assertSame('40.0000', $report->debit);
    }

    public function testBuildBlocks_HasNothingToShowForNoBranchAndForAnEmployeeWithNoLines(): void
    {
        $this->cashSale('2026-10-05', 1, '100.00', $this->employee('Ahmad'));

        $noBranch = $this->blocks('1201', scope: ReportScope::resolve(null, []));
        $nobody   = $this->blocks('1201', employeeId: $this->employee('Basma'));

        foreach ([$noBranch, $nobody] as $report) {
            $this->assertSame([], $report->blocks);
            $this->assertSame(['0.0000', '0.0000', '0.0000'], [$report->debit, $report->credit, $report->net()]);
        }
    }

    public function testBuildBlocks_RejectsAStatementWithMoreBlocksAndLinesThanTheScreenHolds(): void
    {
        $this->cashSale('2026-10-05', 1, '10.00');
        $this->cashSale('2026-10-06', 1, '20.00');

        $limited = new AccountLedger(null, 3);

        $this->assertSame(['120101'], $this->blockCodes($this->blocks('1201', ledger: new AccountLedger(null, 3))), 'One block and two lines are three items, which fit');

        $this->cashSale('2026-10-07', 1, '30.00');

        try {
            $this->blocks('1201', ledger: $limited);
            $this->fail('A statement over the limit was drawn.');
        } catch (AccountingException $refusal) {
            $this->assertSame(AccountingError::LedgerTooLarge, $refusal->error);
            $this->assertSame(3, $refusal->context['max']);
        }
    }

    public function testBuildBlocks_AddsUpTheStoredAmountsNotTheRoundedLines(): void
    {
        $this->postOn('2026-10-05', 1, [[$this->accountId('cash'), '0.0040', '0'], [$this->accountId('sales_revenue'), '0', '0.0040']]);
        $this->postOn('2026-10-06', 1, [[$this->accountId('cash'), '0.0040', '0'], [$this->accountId('sales_revenue'), '0', '0.0040']]);

        $report = $this->blocks('1201');

        $this->assertSame(['0.0040', '0.0040'], array_column($report->blocks[0]->rows, 'debit'), 'The lines keep the four decimals they are stored at');
        $this->assertSame('0.0080', $report->debit, 'Two lines that show as 0.00 add up to 0.01');
        $this->assertSame('0.0080', $report->blocks[0]->closing);
    }

    public function testBuildBlocks_OrdersTheLinesOfAnAccountByDateThenByJournalThenByLine(): void
    {
        $later   = $this->cashSale('2026-10-06', 1, '10.00');
        $earlier = $this->cashSale('2026-10-05', 1, '20.00');
        $same    = $this->cashSale('2026-10-06', 1, '30.00');

        $rows = $this->blocks('1201')->blocks[0]->rows;

        $this->assertSame([$earlier, $later, $same], array_column($rows, 'journal_id'));
    }

    public function testBuildBlocks_ReadsItsFiguresAndItsLinesInOneSnapshotWhileTheBooksMove(): void
    {
        $this->cashSale('2026-10-05', 1, '100.00');

        $report = $this->readWhileTheBooksMove(fn (): AccountLedgerBlocksReport => $this->blocks('1201'));

        $this->assertSame(['100.0000', '100.0000'], [$report->blocks[0]->closing, $report->blocks[0]->rows[0]['balance']], 'The lines add up to the figures of the block they are drawn in');
    }

    public function testBuild_ReadsItsFiguresAndItsLinesInOneSnapshotWhileTheBooksMove(): void
    {
        $this->cashSale('2026-10-05', 1, '100.00');

        $report = $this->readWhileTheBooksMove(fn (): AccountLedgerReport => $this->statement('cash'));

        $this->assertSame(['100.0000', '100.0000'], [$report->closing, $report->rows[0]['balance']], 'The last line closes at the closing balance');
    }

    /**
     * Reads while another connection changes an amount of the cash account right after the first
     * figures of the statement were read.
     *
     * @template T
     *
     * @param callable(): T $read
     *
     * @return T
     */
    private function readWhileTheBooksMove(callable $read): mixed
    {
        $other = $this->openSecondConnection();
        $moved = false;
        $lines = $other->prefixTable('accounting_journal_lines');
        $cash  = $this->accountId('cash');

        $listener = static function (Query $query) use (&$moved, $other, $lines, $cash): void {
            if (! $moved && str_contains($query->getQuery(), 'AS found')) {
                $moved = true;
                $other->query("UPDATE {$lines} SET debit = debit + 7 WHERE account_id = {$cash} LIMIT 1");
            }
        };

        Events::on('DBQuery', $listener);

        try {
            $report = $read();
        } finally {
            Events::removeListener('DBQuery', $listener);
            $other->close();
        }

        $this->assertTrue($moved, 'The books moved between the figures and the lines');

        return $report;
    }

    public function testBuild_ReadsAClosedPeriodAsAnyOtherInEveryLayoutAndForAnEmployee(): void
    {
        $second = $this->customAccount('1201', '120102', 'Second till');
        $worker = $this->employee('Ahmad');

        $this->cashSale('2026-10-05', 1, '100.00', $worker);
        $this->postOn('2026-10-06', 1, [[$second, '40.00', '0'], [$this->accountId('sales_revenue'), '0', '40.00']], employeeId: $worker);
        $this->closeThrough('2026-10-31');

        $this->assertSame('100.0000', $this->statement('cash', '2026-10-31', '2026-10-01')->closing);
        $this->assertSame('100.0000', $this->ledger->build(ReportScope::resolve(null, null), $this->accountId('cash'), ReportPeriod::of('2026-10-01', '2026-10-31'), employeeId: $worker)->closing);

        foreach (LedgerDisplay::cases() as $display) {
            $this->assertSame('140.0000', $this->blocks('1201', '2026-10-31', '2026-10-01', $display, employeeId: $worker)->net(), $display->value);
        }
    }

    public function testEmployees_ListsOnlyThoseWhoAddedJournalsInTheBranchesOfTheScope(): void
    {
        $first  = $this->employee('Ahmad');
        $second = $this->employee('Basma');

        $this->cashSale('2026-10-05', 1, '10.00', $first);
        $this->cashSale('2026-10-06', $this->second, '20.00', $second);
        $this->cashSale('2026-10-07', 1, '5.00');

        $this->assertSame([$first, $second], array_column($this->ledger->employees(ReportScope::resolve(null, null)), 'employee_id'));
        $this->assertSame([$first], array_column($this->ledger->employees(ReportScope::resolve([1], null)), 'employee_id'), 'Someone who added journals only in a branch outside the report is not named');
        $this->assertSame([], $this->ledger->employees(ReportScope::resolve(null, [])));
        $this->assertSame('Ahmad Ledger', $this->ledger->employees(ReportScope::resolve([1], null))[0]->name);
    }

    private function codeOfAccount(int $accountId): string
    {
        return (string) $this->accountingDb()->table('accounting_accounts')->select('code')->where('account_id', $accountId)->get()->getRow()->code;
    }

    private function customerAccount(string $first = 'Omar', string $last = 'Khaled'): int
    {
        return (new EntityAccountService())->ensure(EntityAccountService::CUSTOMER, $this->insertCustomer($first, $last), $first . ' ' . $last);
    }

    public function testBuild_ReproducesTheBalanceBeforeTheFirstDayAndThePeriodTheReferenceSystemShowed(): void
    {
        $case     = $this->referenceCase('T-66', 'account_statement', 'the-statement-starts-from-the-balance-before-the-first-day', 'exact');
        $customer = $this->customerAccount();

        $this->postOn('2026-10-24', 1, [[$customer, $case['inputs']['before']['debit'], '0'], [$this->accountId('sales_revenue'), '0', $case['inputs']['before']['debit']]]);
        $this->postOn('2026-10-26', 1, [[$customer, $case['inputs']['within']['debit'], '0'], [$this->accountId('sales_revenue'), '0', $case['inputs']['within']['debit']]]);
        $this->postOn('2026-10-26', 1, [[$this->accountId('sales_returns'), $case['inputs']['within']['credit'], '0'], [$customer, '0', $case['inputs']['within']['credit']]]);

        $report = $this->ledger->build(ReportScope::resolve(null, null), $customer, ReportPeriod::of($case['inputs']['first_day'], $case['inputs']['last_day']));

        $this->assertSameAsFixture($case['expected'], ['opening' => $report->opening, 'debit' => $report->debit, 'credit' => $report->credit, 'closing' => $report->closing], 'T-66 the statement starts from the balance before the first day');
    }

    public function testBuildBlocks_ReproducesTheGrandTotalOfAGroupStatementAsTheNetOfDebitLessCredit(): void
    {
        $case = $this->referenceCase('T-66', 'account_statement', 'the-grand-total-of-a-group-statement-is-the-net', 'exact');
        $one  = $this->customerAccount();
        $two  = $this->customerAccount('Layla', 'Hamad');

        $this->postOn('2026-10-26', 1, [[$one, '5000.00', '0'], [$this->accountId('sales_revenue'), '0', '5000.00']]);
        $this->postOn('2026-10-26', 1, [[$two, '2842.06', '0'], [$this->accountId('sales_revenue'), '0', '2842.06']]);
        $this->postOn('2026-10-27', 1, [[$this->accountId('sales_returns'), $case['inputs']['within']['credit'], '0'], [$one, '0', $case['inputs']['within']['credit']]]);

        $report = $this->blocks('12041', '2026-10-31');
        $net    = $report->net();

        $this->assertSame('7842.0600', $report->debit);
        $this->assertSameAsFixture($case['expected'], ['net_debit' => bccomp($net, '0', 4) > 0 ? $net : '0', 'net_credit' => bccomp($net, '0', 4) < 0 ? bcsub('0', $net, 4) : '0'], 'T-66 the grand total of a group statement is the net');
    }

    public function testBuildBlocks_ListsFewerCustomersThanTheReferenceSystemUnderTheChoiceOfAllAccounts(): void
    {
        $case      = $this->referenceCase('T-66', 'account_statement', 'the-choices-of-accounts-of-a-group-statement', 'differs');
        $moved     = $this->customerAccount('Ahmad', 'Ali');
        $openedOne = $this->customerAccount('Basma', 'Said');
        $openedTwo = $this->customerAccount('Hind', 'Saad');

        $this->customerAccount('Omar', 'Nothing');
        $this->postOn('2026-10-20', 1, [[$openedOne, '725.00', '0'], [$this->accountId('sales_revenue'), '0', '725.00']]);
        $this->postOn('2026-10-21', 1, [[$openedTwo, '230.00', '0'], [$this->accountId('sales_revenue'), '0', '230.00']]);
        $this->postOn('2026-10-26', 1, [[$moved, '115.00', '0'], [$this->accountId('sales_revenue'), '0', '115.00']]);

        $counts = static fn (AccountLedgerBlocksReport $report): int => count($report->blocks);

        $this->assertSameAsFixture($case['expected'], [
            'all'      => $counts($this->blocks('12041', $case['inputs']['last_day'], $case['inputs']['first_day'], LedgerDisplay::All)),
            'activity' => $counts($this->blocks('12041', $case['inputs']['last_day'], $case['inputs']['first_day'], LedgerDisplay::Activity)),
            'balance'  => $counts($this->blocks('12041', $case['inputs']['last_day'], $case['inputs']['first_day'], LedgerDisplay::NonZeroBalance)),
        ], 'T-66 the choices of accounts of a group statement');
    }

    public function testBuild_ReadsAnEmployeeOfZeroAsEveryoneWhereTheReferenceSystemReadsItAsNobody(): void
    {
        $case  = $this->referenceCase('T-66', 'account_statement', 'an-employee-of-zero-is-everyone', 'deliberate_difference');
        $first = $this->employee('Ahmad');

        $this->cashSale('2026-10-05', 1, '100.00', $first);
        $this->cashSale('2026-10-06', 1, '25.00', $this->employee('Basma'));

        $everyone = $this->statement('cash')->totalRows;
        $none     = $this->ledger->build(ReportScope::resolve(null, null), $this->accountId('cash'), ReportPeriod::of(null, '2026-12-31'), employeeId: 987654)->totalRows;

        $this->assertSame(2, $everyone);
        $this->assertSameAsFixture($case['expected'], ['lines_with_employee_0' => $everyone === 2 ? 'all' : (string) $everyone, 'lines_with_employee_999' => (string) $none], 'T-66 an employee of zero is everyone');
    }

    public function testBuildBlocks_ReproducesTheTotalsOfTheStoredAmountsTheReferenceSystemShowedWhereItsLinesDoNotAddUp(): void
    {
        $this->postOn('2026-10-05', 1, [[$this->accountId('cash'), '691.6500', '0'], [$this->accountId('sales_revenue'), '0', '691.6500']]);
        $this->postOn('2026-10-06', 1, [[$this->accountId('cash'), '691.6500', '0'], [$this->accountId('sales_revenue'), '0', '691.6500']]);

        $report = $this->blocks('1201');

        $this->assertSame('1383.3000', $report->debit, 'The total of the statement is the sum of the stored amounts, which the lines shown at two decimals need not add up to');
    }
}
