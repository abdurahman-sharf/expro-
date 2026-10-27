<?php

namespace Tests\Libraries\Accounting;

use App\Libraries\Accounting\AccountingGuard;
use App\Libraries\Accounting\InterBranchBalances;
use App\Libraries\Accounting\InterBranchLegs;
use App\Libraries\Accounting\InterBranchPosition;
use App\Libraries\Accounting\JournalDraft;
use App\Libraries\Accounting\JournalLineDraft;
use App\Libraries\Accounting\PairBalance;
use App\Libraries\Accounting\PostingService;
use App\Libraries\Accounting\TreasuryService;
use App\Libraries\Accounting\TreasuryTransfers;
use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\DatabaseTestTrait;
use Config\Database;
use Tests\Support\AccountingFixtureTrait;
use Tests\Support\EmployeeFixtureTrait;
use Tests\Support\ItemFixtureTrait;
use Tests\Support\PurchaseAccountingFixtureTrait;
use Tests\Support\SaleAccountingFixtureTrait;

/**
 * Which branch owes which, between every two branches: worked out from the journals of the accounts
 * between branches and the other branch of each, which the transfers that were posted before name in
 * their own documents.
 */
class InterBranchBalancesTest extends CIUnitTestCase
{
    use DatabaseTestTrait;
    use AccountingFixtureTrait;
    use EmployeeFixtureTrait;
    use ItemFixtureTrait;
    use SaleAccountingFixtureTrait;
    use PurchaseAccountingFixtureTrait;

    protected $migrate     = true;
    protected $migrateOnce = true;
    protected $refresh     = false;
    protected $namespace   = null;

    private InterBranchBalances $balances;
    private TreasuryTransfers $transfers;
    private int $manager;
    private int $first;
    private int $second;
    private int $third;
    private int $secondBranch;
    private int $thirdBranch;

    public static function setUpBeforeClass(): void
    {
        $seeder = Database::seeder('tests');
        $seeder->call('TestDatabaseBootstrapSeeder');
    }

    protected function setUp(): void
    {
        parent::setUp();

        $this->startBuying();

        $treasuries         = new TreasuryService();
        $this->manager      = $this->employeeWithGrants([AccountingGuard::TREASURIES_MANAGE]);
        $this->secondBranch = $this->createBranch('B2');
        $this->thirdBranch  = $this->createBranch('B3');
        $this->first        = $treasuries->create(['name' => 'First drawer', 'kind' => 'cash'], $this->manager);
        $this->second       = $treasuries->create(['name' => 'Second drawer', 'kind' => 'cash', 'branch_id' => $this->secondBranch], $this->manager);
        $this->third        = $treasuries->create(['name' => 'Third drawer', 'kind' => 'cash', 'branch_id' => $this->thirdBranch], $this->manager);
        $this->balances     = new InterBranchBalances();
        $this->transfers    = new TreasuryTransfers();
    }

    protected function tearDown(): void
    {
        $this->stopBuying();

        parent::tearDown();
    }

    /**
     * @return array<string, PairBalance> By the branches of the pair, "first>second".
     */
    private function rows(?array $branchIds = null, ?string $asOf = null): array
    {
        $rows = [];

        foreach ($this->balances->report($branchIds, $asOf)->rows as $row) {
            $rows[$row->branchId . '>' . $row->counterpartId] = $row;
        }

        return $rows;
    }

    private function send(int $from, int $to, string $amount, string $date = '2026-10-21'): int
    {
        return $this->transfers->transfer($from, $to, $amount, $date, '', $this->manager);
    }

    public function testReport_ShowsWhatEachBranchIsOwedByTheOtherForEveryOrderedPair(): void
    {
        $this->send($this->first, $this->second, '100.00');

        $rows = $this->rows();

        $this->assertSame(['1>' . $this->secondBranch, $this->secondBranch . '>1'], array_keys($rows));
        $this->assertSame(['100.0000', '0.0000', '100.0000'], [$rows['1>' . $this->secondBranch]->receivable, $rows['1>' . $this->secondBranch]->payable, $rows['1>' . $this->secondBranch]->owed]);
        $this->assertSame(['0.0000', '100.0000', '-100.0000'], [$rows[$this->secondBranch . '>1']->receivable, $rows[$this->secondBranch . '>1']->payable, $rows[$this->secondBranch . '>1']->owed]);
        $this->assertTrue($rows['1>' . $this->secondBranch]->agrees);
        $this->assertTrue($rows[$this->secondBranch . '>1']->agrees);
        $this->assertSame('HQ', $rows['1>' . $this->secondBranch]->code);
        $this->assertSame('B2', $rows['1>' . $this->secondBranch]->counterpartCode);
        $this->assertSame('0.0000', $this->balances->report()->difference);
    }

    public function testReport_KeepsBothDirectionsOfBranchesThatSendToEachOther(): void
    {
        $this->send($this->first, $this->second, '100.00');
        $this->send($this->second, $this->first, '30.00', '2026-10-22');

        $row = $this->rows()['1>' . $this->secondBranch];

        $this->assertSame(['100.0000', '30.0000', '70.0000'], [$row->receivable, $row->payable, $row->owed]);
        $this->assertSame('-70.0000', $this->rows()[$this->secondBranch . '>1']->owed);
    }

    public function testReport_TellsApartWhatEachPairOfThreeBranchesOwes(): void
    {
        $this->send($this->first, $this->second, '100.00');
        $this->send($this->first, $this->third, '50.00');
        $this->send($this->second, $this->third, '20.00');

        $rows = $this->rows();

        $this->assertSame('100.0000', $rows['1>' . $this->secondBranch]->owed);
        $this->assertSame('50.0000', $rows['1>' . $this->thirdBranch]->owed);
        $this->assertSame('20.0000', $rows[$this->secondBranch . '>' . $this->thirdBranch]->owed);
        $this->assertSame('-100.0000', $rows[$this->secondBranch . '>1']->owed);
        $this->assertSame('-50.0000', $rows[$this->thirdBranch . '>1']->owed);
        $this->assertSame('-20.0000', $rows[$this->thirdBranch . '>' . $this->secondBranch]->owed);
        $this->assertCount(6, $rows);

        foreach ($rows as $row) {
            $this->assertTrue($row->agrees);
        }
    }

    public function testReport_CountsTheReversalOfACancelledTransferInThePairOfTheTransfer(): void
    {
        $id = $this->send($this->first, $this->second, '100.00');

        $this->transfers->cancel($id, 'Wrong branch', $this->manager);

        $row = $this->rows()['1>' . $this->secondBranch];

        $this->assertSame(['0.0000', '0.0000', '0.0000'], [$row->receivable, $row->payable, $row->owed]);
        $this->assertSame('0.0000', $this->balances->report()->difference);
    }

    public function testReport_ReadsTheOtherBranchOfATransferFromItsDocumentAndSaysSo(): void
    {
        $this->send($this->first, $this->second, '100.00');

        $this->assertSame(PairBalance::DERIVED, $this->rows()['1>' . $this->secondBranch]->basis);
        $this->assertSame(0, $this->countRows('accounting_interbranch_legs'), 'A transfer leaves no row of its own');
    }

    public function testReport_ReadsTheOtherBranchOfAStockTransferFromItsDocument(): void
    {
        $itemId = $this->item('0.00');
        $other  = $this->locationOfBranch($this->secondBranch, 'Branch warehouse');

        $this->assertGreaterThan(0, $this->buy([$this->purchaseLine($itemId, 0, '10', '50')]));
        $this->workInEveryBranch();
        $this->assertGreaterThan(0, $this->buy([$this->purchaseLine($itemId, 0, '4', '0', '0', '0', PERCENT, $other), $this->purchaseLine($itemId, 1, '-4', '0')], null, null));

        $rows = $this->rows();

        $this->assertSame(['200.0000', PairBalance::DERIVED], [$rows['1>' . $this->secondBranch]->owed, $rows['1>' . $this->secondBranch]->basis]);
        $this->assertSame(['-200.0000', PairBalance::DERIVED], [$rows[$this->secondBranch . '>1']->owed, $rows[$this->secondBranch . '>1']->basis]);
        $this->assertTrue($rows['1>' . $this->secondBranch]->agrees);
        $this->assertSame('0.0000', $this->balances->report()->difference);
    }

    public function testReport_ReadsTheOtherBranchOfAJournalThatRecordedItAndSaysSo(): void
    {
        $this->recordedPair('60.00');

        $rows = $this->rows();

        $this->assertSame(PairBalance::RECORDED, $rows['1>' . $this->secondBranch]->basis);
        $this->assertSame('60.0000', $rows['1>' . $this->secondBranch]->owed);
        $this->assertSame('-60.0000', $rows[$this->secondBranch . '>1']->owed);
        $this->assertTrue($rows['1>' . $this->secondBranch]->agrees);
    }

    public function testReport_SaysMixedForAPairWhoseJournalsAreKnownBothWays(): void
    {
        $this->send($this->first, $this->second, '100.00');
        $this->recordedPair('60.00', '2026-10-22');

        $row = $this->rows()['1>' . $this->secondBranch];

        $this->assertSame(PairBalance::MIXED, $row->basis);
        $this->assertSame('160.0000', $row->owed);
    }

    public function testReport_PrefersTheBranchRecordedWithAJournalToTheOneItsDocumentNames(): void
    {
        $this->send($this->first, $this->second, '100.00');

        $sender = $this->accountingDb()->table('accounting_journals')->where(['source_type' => TreasuryTransfers::SOURCE, 'branch_id' => 1])->get()->getRow();

        (new InterBranchLegs())->record((int) $sender->journal_id, $this->accountId('branches_receivable'), InterBranchLegs::RECEIVABLE, $this->thirdBranch, TreasuryTransfers::SOURCE, (int) $sender->source_id, 1);

        $rows = $this->rows();

        $this->assertSame(['100.0000', PairBalance::RECORDED], [$rows['1>' . $this->thirdBranch]->owed, $rows['1>' . $this->thirdBranch]->basis]);
        $this->assertSame('0.0000', $rows['1>' . $this->secondBranch]->receivable, 'The document named the second branch, the record the third');
    }

    public function testReport_StopsAtTheDayAsked(): void
    {
        $this->send($this->first, $this->second, '100.00', '2026-10-21');
        $this->send($this->first, $this->second, '40.00', '2026-10-25');

        $this->assertSame('100.0000', $this->rows(null, '2026-10-22')['1>' . $this->secondBranch]->owed);
        $this->assertSame('140.0000', $this->rows(null, '2026-10-25')['1>' . $this->secondBranch]->owed);
        $this->assertSame([], $this->rows(null, '2026-10-20'));
        $this->assertSame('0.0000', $this->balances->report(null, '2026-10-22')->difference);
    }

    public function testReport_SetsApartWhatNoJournalSaysTheOtherBranchOf(): void
    {
        (new PostingService())->post(new JournalDraft(
            date: '2026-10-21',
            lines: [new JournalLineDraft($this->accountId('branches_receivable'), debit: '25.00'), new JournalLineDraft($this->accountId('opening_balance'), credit: '25.00')],
            sourceType: JournalDraft::SOURCE_MANUAL,
            description: 'By hand',
        ));

        (new PostingService())->post(new JournalDraft(
            date: '2026-10-21',
            lines: [new JournalLineDraft($this->accountId('opening_balance'), debit: '10.00'), new JournalLineDraft($this->accountId('branches_payable'), credit: '10.00')],
            sourceType: JournalDraft::SOURCE_MANUAL,
            description: 'By hand in the second branch',
            branchId: $this->secondBranch,
        ));

        $report = $this->balances->report();

        $this->assertSame([], $report->rows);
        $this->assertSame([['1', '25.0000', '0.0000'], [(string) $this->secondBranch, '0.0000', '10.0000']], array_map(static fn (object $row): array => [(string) $row->branch_id, $row->receivable, $row->payable], $report->unattributed));
        $this->assertSame('15.0000', $report->difference, 'The company check still sees both');
        $this->assertSame([(string) $this->secondBranch], array_map(static fn (object $row): string => (string) $row->branch_id, $this->balances->report([$this->secondBranch])->unattributed));
        $this->assertSame(['1'], array_map(static fn (object $row): string => (string) $row->branch_id, $this->balances->report([1, $this->thirdBranch])->unattributed));
    }

    public function testReport_SaysAPairDoesNotAgreeWhenOnlyOneBranchHasTheJournal(): void
    {
        $this->recordedPair('60.00', '2026-10-21', false);

        $rows = $this->rows();

        $this->assertFalse($rows['1>' . $this->secondBranch]->agrees);
        $this->assertFalse($rows[$this->secondBranch . '>1']->agrees);
        $this->assertSame('0.0000', $rows[$this->secondBranch . '>1']->owed);
        $this->assertSame('60.0000', $this->balances->report()->difference);
    }

    public function testReport_ShowsOnlyThePairsOfTheBranchesTheReaderMaySeeAndNotTheCompanyCheck(): void
    {
        $this->send($this->first, $this->second, '100.00');
        $this->send($this->second, $this->third, '20.00');

        $report = $this->balances->report([$this->secondBranch]);

        $this->assertSame([$this->secondBranch . '>1', $this->secondBranch . '>' . $this->thirdBranch], array_map(static fn (PairBalance $row): string => $row->branchId . '>' . $row->counterpartId, $report->rows));
        $this->assertNull($report->difference);
        $this->assertSame([], $this->balances->report([])->rows);
        $this->assertSame([], $this->balances->report([$this->thirdBranch, 1])->unattributed);
        $this->assertSame(['1>' . $this->secondBranch, $this->thirdBranch . '>' . $this->secondBranch], array_keys($this->rows([1, $this->thirdBranch])));
    }

    public function testBetween_GivesWhatTheTwoBranchesSayOfEachOther(): void
    {
        $this->send($this->first, $this->second, '100.00');
        $this->send($this->second, $this->first, '30.00', '2026-10-22');

        $between = $this->balances->between(1, $this->secondBranch);

        $this->assertSame(['receivable' => '100.0000', 'payable' => '30.0000'], $between['first']);
        $this->assertSame(['receivable' => '30.0000', 'payable' => '100.0000'], $between['second']);
        $this->assertSame(['receivable' => '0.0000', 'payable' => '0.0000'], $this->balances->between(1, $this->thirdBranch)['first']);
        $this->assertSame(['receivable' => '100.0000', 'payable' => '0.0000'], $this->balances->between(1, $this->secondBranch, '2026-10-21')['first']);
    }

    public function testAccounts_IncludesTheAccountAJournalWasRecordedOnWhenTheRoutingNamesAnother(): void
    {
        $other = $this->customAccount('1204', '12047', 'Receivable recorded once');

        (new PostingService())->post(new JournalDraft(
            date: '2026-10-21',
            lines: [new JournalLineDraft($other, debit: '10.00'), new JournalLineDraft($this->accountId('opening_balance'), credit: '10.00')],
            sourceType: 'interbranch_test',
            sourceId: 1,
            branchId: 1,
        ));
        (new InterBranchLegs())->record((int) $this->accountingDb()->table('accounting_journals')->where('source_type', 'interbranch_test')->get()->getRow()->journal_id, $other, InterBranchLegs::RECEIVABLE, $this->secondBranch, 'interbranch_test', 1, 1);

        [$receivable, $payable] = (new InterBranchPosition())->accounts();

        $this->assertContains($other, $receivable);
        $this->assertNotContains($other, $payable);
        $this->assertContains($this->accountId('branches_receivable'), $receivable);
        $this->assertContains($this->accountId('branches_payable'), $payable);
    }

    /**
     * Two journals written by hand on the accounts between branches, with the branch on the other
     * side of each recorded: what the first branch is owed by the second, and what the second owes.
     */
    private function recordedPair(string $amount, string $date = '2026-10-21', bool $bothSides = true): void
    {
        static $number = 5000;

        $number++;

        $owed  = $this->journalOn('branches_receivable', $amount, true, 1, $date, $number);
        $legs  = new InterBranchLegs();
        $legs->record($owed, $this->accountId('branches_receivable'), InterBranchLegs::RECEIVABLE, $this->secondBranch, 'interbranch_test', $number, 1);

        if ($bothSides) {
            $owing = $this->journalOn('branches_payable', $amount, false, $this->secondBranch, $date, $number + 100000);
            $legs->record($owing, $this->accountId('branches_payable'), InterBranchLegs::PAYABLE, 1, 'interbranch_test', $number, 1);
        }
    }

    private function journalOn(string $systemKey, string $amount, bool $debit, int $branchId, string $date, int $number): int
    {
        $lines = $debit
            ? [new JournalLineDraft($this->accountId($systemKey), debit: $amount), new JournalLineDraft($this->accountId('opening_balance'), credit: $amount)]
            : [new JournalLineDraft($this->accountId('opening_balance'), debit: $amount), new JournalLineDraft($this->accountId($systemKey), credit: $amount)];

        return (new PostingService())->post(new JournalDraft(date: $date, lines: $lines, sourceType: 'interbranch_test', sourceId: $number, branchId: $branchId))->journalId;
    }
}
