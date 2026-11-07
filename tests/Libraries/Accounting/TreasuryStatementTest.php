<?php

namespace Tests\Libraries\Accounting;

use App\Libraries\Accounting\AccountingError;
use App\Libraries\Accounting\AccountingException;
use App\Libraries\Accounting\JournalDraft;
use App\Libraries\Accounting\JournalLineDraft;
use App\Libraries\Accounting\PostingService;
use App\Libraries\Accounting\TreasuryService;
use App\Libraries\Accounting\TreasuryStatement;
use Tests\Support\CashReportTestCase;

/**
 * The statement of a treasury in pages: newest first or oldest first, cut down to days and amounts
 * without changing a balance, the balance before the oldest movement of each page, and the figures that
 * come out beside the balance of the treasury and of its account.
 *
 * @internal
 */
final class TreasuryStatementTest extends CashReportTestCase
{
    private TreasuryStatement $statement;
    private int $drawer;

    protected function setUp(): void
    {
        parent::setUp();

        $this->statement = new TreasuryStatement();
        $this->drawer    = $this->treasury('Statement drawer');
    }

    private function move(string $date, string $deposit, string $withdrawal = '0'): void
    {
        $account = (int) (new TreasuryService())->accountOf($this->drawer);
        $other   = $this->accountId('opening_balance');

        (new PostingService())->post(new JournalDraft(
            date: $date,
            lines: bccomp($deposit, '0', 2) > 0
                ? [new JournalLineDraft($account, debit: $deposit), new JournalLineDraft($other, credit: $deposit)]
                : [new JournalLineDraft($other, debit: $withdrawal), new JournalLineDraft($account, credit: $withdrawal)],
            sourceType: JournalDraft::SOURCE_MANUAL,
            description: 'Movement ' . $date,
            currencyCode: 'SAR',
            branchId: 1,
        ));
    }

    /**
     * Twenty-eight movements, one a day: the odd days deposit 10 times the day, the even days withdraw 5.
     */
    private function twentyEight(): void
    {
        for ($day = 28; $day >= 1; $day--) {
            $date = date('Y-m-d', strtotime("-{$day} days"));

            $day % 2 === 1 ? $this->move($date, (string) ($day * 10) . '.00') : $this->move($date, '0', '5.00');
        }
    }

    public function testPage_GivesTwentyRowsANewestFirstWithTheBalanceBeforeTheOldestOfThePage(): void
    {
        $this->twentyEight();

        $first  = $this->statement->page($this->drawer);
        $second = $this->statement->page($this->drawer, [], 'desc', 2);

        $this->assertCount(20, $first['rows']);
        $this->assertSame(28, $first['total']);
        $this->assertSame(2, $first['pages']);
        $this->assertGreaterThan((string) $first['rows'][19]['journal_date'], (string) $first['rows'][0]['journal_date']);
        $this->assertCount(8, $second['rows']);
        $this->assertSame('0.0000', $second['before']);

        $oldest = $first['rows'][19];

        $this->assertSame(bcsub((string) $oldest['balance'], bcsub((string) $oldest['debit'], (string) $oldest['credit'], 4), 4), $first['before']);
        $this->assertSame((string) $second['rows'][0]['balance'], bcadd($first['before'], '0', 4));
    }

    public function testPage_ReadsPastTheLastPageAsTheLastPageAndAPageBelowOneAsTheFirst(): void
    {
        $this->twentyEight();

        $this->assertSame(2, $this->statement->page($this->drawer, [], 'desc', 99)['page']);
        $this->assertSame(1, $this->statement->page($this->drawer, [], 'desc', -5)['page']);
        $this->assertCount(8, $this->statement->page($this->drawer, [], 'desc', 3, 10)['rows']);
    }

    public function testPage_OrdersOldestFirstWhenAskedAndTakesTheBalanceBeforeFromTheFirstRow(): void
    {
        $this->twentyEight();

        $page = $this->statement->page($this->drawer, [], 'asc');

        $this->assertLessThan((string) $page['rows'][19]['journal_date'], (string) $page['rows'][0]['journal_date']);
        $this->assertSame('0.0000', $page['before']);
        $this->assertSame('-5.0000', (string) $page['rows'][0]['balance']);
    }

    public function testPage_CutsDownToDaysAndAmountsWithoutChangingAnyBalance(): void
    {
        $this->twentyEight();

        $all     = $this->statement->rows($this->drawer);
        $balance = [];

        foreach ($all as $row) {
            $balance[(int) $row['line_id']] = (string) $row['balance'];
        }

        $from  = date('Y-m-d', strtotime('-10 days'));
        $dated = $this->statement->page($this->drawer, ['from' => $from]);

        $this->assertSame(10, $dated['total']);

        foreach ($dated['rows'] as $row) {
            $this->assertSame($balance[(int) $row['line_id']], (string) $row['balance']);
            $this->assertGreaterThanOrEqual($from, (string) $row['journal_date']);
        }

        $before = $this->statement->page($this->drawer, ['from' => $from], 'asc')['before'];
        $last   = array_values(array_filter($all, static fn (array $row): bool => (string) $row['journal_date'] < $from));

        $this->assertSame((string) $last[count($last) - 1]['balance'], $before);

        $big = $this->statement->page($this->drawer, ['amount_from' => '100']);

        $this->assertSame(array_sum(array_map(static fn (array $row): int => bccomp((string) $row['debit'], '100', 2) >= 0 ? 1 : 0, $all)), $big['total']);

        $small = $this->statement->page($this->drawer, ['amount_from' => '5', 'amount_to' => '5']);

        $this->assertSame(14, $small['total']);
        $this->assertSame(0, $this->statement->page($this->drawer, ['to' => date('Y-m-d', strtotime('-40 days'))])['total']);
        $this->assertNull($this->statement->page($this->drawer, ['to' => date('Y-m-d', strtotime('-40 days'))])['before']);
    }

    public function testPage_RefusesADayThatIsNotOneAPeriodThatEndsBeforeItStartsAndAnAmountThatIsNotOne(): void
    {
        $this->twentyEight();

        foreach ([['from' => '2026-13-40'], ['from' => '2026-05-02', 'to' => '2026-05-01'], ['amount_from' => 'abc'], ['amount_from' => '50', 'amount_to' => '10'], ['amount_to' => '-5']] as $filters) {
            try {
                $this->statement->page($this->drawer, $filters);
                $this->fail('The filters were accepted: ' . json_encode($filters));
            } catch (AccountingException $refusal) {
                $this->assertContains($refusal->error, [AccountingError::InvalidDate, AccountingError::ReportPeriodInvalid, AccountingError::StatementAmountInvalid]);
            }
        }
    }

    public function testPage_EndsAtTheBalanceOfTheTreasuryAndOfItsAccountAndListsEveryRowInTheFile(): void
    {
        $this->twentyEight();

        $page = $this->statement->page($this->drawer);

        $this->assertSame($this->assertTreasuryTiesToItsAccount($this->drawer), bcadd($page['balance'], '0', 2));
        $this->assertCount(28, $this->statement->rows($this->drawer));
        $this->assertSame((string) $this->statement->rows($this->drawer, [], 'asc')[0]['journal_id'], (string) $this->statement->rows($this->drawer, [], 'desc')[27]['journal_id']);
    }

    public function testPage_IsEmptyForATreasuryWithNoMovement(): void
    {
        $page = $this->statement->page($this->drawer);

        $this->assertSame([], $page['rows']);
        $this->assertSame(0, $page['total']);
        $this->assertSame(1, $page['pages']);
        $this->assertNull($page['before']);
        $this->assertSame('0.0000', $page['balance']);
    }
}
