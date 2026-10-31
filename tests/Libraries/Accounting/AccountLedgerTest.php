<?php

namespace Tests\Libraries\Accounting;

use App\Libraries\Accounting\AccountingError;
use App\Libraries\Accounting\AccountingException;
use App\Libraries\Accounting\AccountLedger;
use App\Libraries\Accounting\AccountLedgerReport;
use App\Libraries\Accounting\JournalDraft;
use App\Libraries\Accounting\JournalEntryType;
use App\Libraries\Accounting\JournalLineDraft;
use App\Libraries\Accounting\JournalService;
use App\Libraries\Accounting\NormalBalance;
use App\Libraries\Accounting\PostingService;
use App\Libraries\Accounting\ReportPeriod;
use App\Libraries\Accounting\ReportScope;
use App\Libraries\Accounting\TrialBalance;
use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\DatabaseTestTrait;
use Config\Database;
use Tests\Support\AccountingFixtureTrait;
use Tests\Support\ReportFixtureTrait;

/**
 * The statement of an account: its lines in the order they happened with the balance after each,
 * where it stood when the period opened, the accounts under it, the branches, and the pages.
 */
class AccountLedgerTest extends CIUnitTestCase
{
    use DatabaseTestTrait;
    use AccountingFixtureTrait;
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

    private function closeTheYear(string $date, string $amount): int
    {
        return (new PostingService())->postYearEnd(new JournalDraft(
            date: $date,
            lines: [new JournalLineDraft($this->accountId('sales_revenue'), debit: $amount), new JournalLineDraft($this->accountId('retained_earnings'), credit: $amount)],
            sourceType: 'year_end',
            sourceId: random_int(1000, 9999),
            currencyCode: 'SAR',
            branchId: 1,
            entryType: JournalEntryType::YearEnd,
        ))->journalId;
    }

    public function testBuild_ShowsTheJournalThatClosesAYearAndSaysWhatItIsByItsType(): void
    {
        $this->cashSale('2026-10-05', 1, '100.00');
        $closing = $this->closeTheYear('2026-12-31', '100.00');

        $report = $this->statement('sales_revenue');

        $this->assertSame(['general', 'year_end'], array_column($report->rows, 'entry_type'));
        $this->assertSame($closing, $report->rows[1]['journal_id']);
        $this->assertSame('year_end', $report->rows[1]['source_type']);
        $this->assertSame('0.0000', $report->closing, 'The closing is counted, so the account stands at nothing after it');
        $this->assertSame('100.0000', $this->statement('sales_revenue', '2026-12-30')->closing);
    }

    public function testBuild_TagsTheLineOfTheRetainedEarningsThatTheClosingWrote(): void
    {
        $this->cashSale('2026-10-05', 1, '100.00');
        $this->closeTheYear('2026-12-31', '100.00');

        $report = $this->statement('retained_earnings');

        $this->assertSame(['year_end'], array_column($report->rows, 'entry_type'));
        $this->assertSame('100.0000', $report->closing);
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
}
