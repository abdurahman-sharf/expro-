<?php

namespace Tests\Libraries\Accounting;

use App\Libraries\Accounting\AccountingGuard;
use App\Libraries\Accounting\InterBranchLegs;
use App\Libraries\Accounting\InterBranchPosition;
use App\Libraries\Accounting\JournalDraft;
use App\Libraries\Accounting\JournalLineDraft;
use App\Libraries\Accounting\PostingService;
use App\Libraries\Accounting\TreasuryService;
use App\Libraries\Accounting\TreasuryTransfers;
use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\DatabaseTestTrait;
use Config\Database;
use Tests\Support\AccountingFixtureTrait;
use Tests\Support\EmployeeFixtureTrait;

/**
 * What each branch is owed by the other branches and what it owes them, and that across the
 * company the two accounts offset to nothing.
 */
class InterBranchPositionTest extends CIUnitTestCase
{
    use DatabaseTestTrait;
    use AccountingFixtureTrait;
    use EmployeeFixtureTrait;

    protected $migrate     = true;
    protected $migrateOnce = true;
    protected $refresh     = false;
    protected $namespace   = null;

    private InterBranchPosition $position;
    private TreasuryTransfers $transfers;
    private int $manager;
    private int $first;
    private int $second;
    private int $secondBranch;

    public static function setUpBeforeClass(): void
    {
        $seeder = Database::seeder('tests');
        $seeder->call('TestDatabaseBootstrapSeeder');
    }

    protected function setUp(): void
    {
        parent::setUp();

        $this->reinstallChart();
        $this->setConfigValue('accounting_base_currency', 'SAR');

        $treasuries         = new TreasuryService();
        $this->manager      = $this->employeeWithGrants([AccountingGuard::TREASURIES_MANAGE]);
        $this->secondBranch = $this->createBranch('B2');
        $this->first        = $treasuries->create(['name' => 'First branch drawer', 'kind' => 'cash'], $this->manager);
        $this->second       = $treasuries->create(['name' => 'Second branch drawer', 'kind' => 'cash', 'branch_id' => $this->secondBranch], $this->manager);
        $this->position     = new InterBranchPosition();
        $this->transfers    = new TreasuryTransfers();
    }

    protected function tearDown(): void
    {
        $this->clearJournals();
        $this->reinstallChart();

        parent::tearDown();
    }

    /**
     * @return array<int, array{string, string}> Receivable and payable by branch.
     */
    private function balances(): array
    {
        $rows = [];

        foreach ($this->position->byBranch() as $row) {
            $rows[$row->branch_id] = [$row->receivable, $row->payable];
        }

        return $rows;
    }

    public function testByBranch_ShowsWhatTheSendingBranchIsOwedAndWhatTheReceivingBranchOwes(): void
    {
        $this->transfers->transfer($this->first, $this->second, '100.00', '2026-10-21', '', $this->manager);

        $balances = $this->balances();

        $this->assertSame(['100.0000', '0.0000'], $balances[1]);
        $this->assertSame(['0.0000', '100.0000'], $balances[$this->secondBranch]);
        $this->assertSame('0.0000', $this->position->difference());
    }

    public function testByBranch_KeepsBothSidesOfBranchesThatSendToEachOther(): void
    {
        $this->transfers->transfer($this->first, $this->second, '100.00', '2026-10-21', '', $this->manager);
        $this->transfers->transfer($this->second, $this->first, '30.00', '2026-10-22', '', $this->manager);

        $balances = $this->balances();

        $this->assertSame(['100.0000', '30.0000'], $balances[1]);
        $this->assertSame(['30.0000', '100.0000'], $balances[$this->secondBranch]);
        $this->assertSame('0.0000', $this->position->difference());
    }

    public function testByBranch_CountsTheReversalsOfACancelledTransferSoItLeavesNothing(): void
    {
        $id = $this->transfers->transfer($this->first, $this->second, '100.00', '2026-10-21', '', $this->manager);

        $this->transfers->cancel($id, 'Wrong branch', $this->manager);

        $this->assertSame(['0.0000', '0.0000'], $this->balances()[1]);
        $this->assertSame(['0.0000', '0.0000'], $this->balances()[$this->secondBranch]);
        $this->assertSame('0.0000', $this->position->difference());
    }

    public function testByBranch_StillCountsWhatWasPostedBeforeTheRoutingWasPointedAtAnotherAccount(): void
    {
        $this->transfers->transfer($this->first, $this->second, '100.00', '2026-10-21', '', $this->manager);

        $elsewhere = $this->customAccount('1204', '12049', 'Another receivable');

        $this->accountingDb()->table('accounting_routing')->where('routing_key', 'branches.receivable')->update(['account_id' => $elsewhere]);

        $this->assertSame(['100.0000', '0.0000'], $this->balances()[1]);
        $this->assertSame('0.0000', $this->position->difference());

        $this->transfers->transfer($this->first, $this->second, '40.00', '2026-10-22', '', $this->manager);

        $this->assertSame(['140.0000', '0.0000'], $this->balances()[1]);
        $this->assertSame('0.0000', $this->position->difference());
    }

    public function testByBranch_CountsAnAccountATransferWasPostedToThatIsNoLongerInTheRouting(): void
    {
        $other = $this->customAccount('1204', '12048', 'Receivable of an earlier routing');

        $this->accountingDb()->table('accounting_routing')->where('routing_key', 'branches.receivable')->update(['account_id' => $other]);
        $this->transfers->transfer($this->first, $this->second, '100.00', '2026-10-21', '', $this->manager);
        $this->accountingDb()->table('accounting_routing')->where('routing_key', 'branches.receivable')->update(['account_id' => $this->accountId('branches_receivable')]);

        $this->assertSame(['100.0000', '0.0000'], $this->balances()[1]);
        $this->assertSame('0.0000', $this->position->difference());
    }

    /**
     * One journal of a stock transfer: what the branch is owed or owes on the account given against cash.
     */
    private function stockLeg(string $source, int $sourceId, int $branchId, int $account, string $amount, bool $owed): void
    {
        $cash  = $this->accountId('cash');
        $lines = $owed
            ? [new JournalLineDraft($account, debit: $amount), new JournalLineDraft($cash, credit: $amount)]
            : [new JournalLineDraft($cash, debit: $amount), new JournalLineDraft($account, credit: $amount)];

        (new PostingService())->post(new JournalDraft(date: '2026-10-21', lines: $lines, sourceType: $source, sourceId: $sourceId, branchId: $branchId, currencyCode: 'SAR'));
    }

    public function testByBranch_CountsAStockTransferBetweenBranchesAsItCountsATransferOfMoney(): void
    {
        $this->stockLeg('stock_transfer', 501, 1, $this->accountId('branches_receivable'), '70.00', true);
        $this->stockLeg('stock_transfer_receipt', 501, $this->secondBranch, $this->accountId('branches_payable'), '70.00', false);
        $this->transfers->transfer($this->first, $this->second, '100.00', '2026-10-21', '', $this->manager);

        $balances = $this->balances();

        $this->assertSame(['170.0000', '0.0000'], $balances[1]);
        $this->assertSame(['0.0000', '170.0000'], $balances[$this->secondBranch]);
        $this->assertSame('0.0000', $this->position->difference());
    }

    public function testDifference_ShowsAStockTransferWhoseReceiptIsMissing(): void
    {
        $this->stockLeg('stock_transfer', 502, 1, $this->accountId('branches_receivable'), '70.00', true);

        $this->assertSame('70.0000', $this->position->difference());
    }

    public function testByBranch_StillCountsAStockTransferPostedToAccountsThatAreNoLongerInTheRouting(): void
    {
        $receivable = $this->customAccount('1204', '12047', 'Receivable of an earlier routing');
        $payable    = $this->customAccount('211', '2117', 'Payable of an earlier routing');

        $this->stockLeg('stock_transfer', 503, 1, $receivable, '80.00', true);
        $this->stockLeg('stock_transfer_receipt', 503, $this->secondBranch, $payable, '80.00', false);

        $this->assertSame(['80.0000', '0.0000'], $this->balances()[1]);
        $this->assertSame(['0.0000', '80.0000'], $this->balances()[$this->secondBranch]);
        $this->assertSame('0.0000', $this->position->difference());
    }

    public function testByBranch_ListsOnlyTheBranchesAskedFor(): void
    {
        $this->assertSame([], $this->position->byBranch([]));
        $this->assertSame([$this->secondBranch], array_map(static fn (object $row): int => $row->branch_id, $this->position->byBranch([$this->secondBranch])));
    }

    public function testDifference_ShowsAJournalOfTheOneAccountThatHasNoCounterpartInTheOther(): void
    {
        (new PostingService())->post(new JournalDraft(
            date: '2026-10-21',
            lines: [new JournalLineDraft($this->accountId('branches_receivable'), debit: '50.00'), new JournalLineDraft($this->accountId('opening_balance'), credit: '50.00')],
            sourceType: 'opening',
            sourceId: random_int(1000, 9999),
            currencyCode: 'SAR',
        ));

        $this->assertSame('50.0000', $this->position->difference());
    }

    public function testDifference_CountsOnlyWhatWasPostedByTheLastDayAsked(): void
    {
        foreach (['2026-10-21' => '50.00', '2026-11-15' => '20.00'] as $date => $amount) {
            (new PostingService())->post(new JournalDraft(
                date: $date,
                lines: [new JournalLineDraft($this->accountId('branches_receivable'), debit: $amount), new JournalLineDraft($this->accountId('opening_balance'), credit: $amount)],
                sourceType: 'opening',
                sourceId: random_int(1000, 9999),
                currencyCode: 'SAR',
            ));
        }

        $this->assertSame('0.0000', $this->position->difference('2026-10-20'));
        $this->assertSame('50.0000', $this->position->difference('2026-10-21'));
        $this->assertSame('50.0000', $this->position->difference('2026-11-14'));
        $this->assertSame('70.0000', $this->position->difference('2026-11-15'));
        $this->assertSame('70.0000', $this->position->difference());
    }

    public function testDifference_IgnoresTheJournalsOfAnotherCompany(): void
    {
        $db = $this->accountingDb();

        $db->table('companies')->insert(['name' => 'Another company']);
        $other = (int) $db->insertID();

        try {
            (new PostingService())->post(new JournalDraft(
                date: '2026-10-21',
                lines: [new JournalLineDraft($this->accountId('branches_receivable'), debit: '50.00'), new JournalLineDraft($this->accountId('opening_balance'), credit: '50.00')],
                sourceType: 'opening',
                sourceId: random_int(1000, 9999),
                currencyCode: 'SAR',
            ));
            $db->table('accounting_journals')->update(['company_id' => $other]);

            $this->assertSame('0.0000', $this->position->difference());
        } finally {
            $db->table('accounting_journals')->update(['company_id' => 1]);
            $db->table('companies')->where('company_id', $other)->delete();
        }
    }

    public function testPosition_IsNothingWhenTheRoutingNamesNoAccountsBetweenBranches(): void
    {
        $this->accountingDb()->table('accounting_routing')->whereIn('routing_key', ['branches.receivable', 'branches.payable'])->update(['account_id' => null]);

        $this->assertSame('0.0000', $this->position->difference());
        $this->assertSame(['0.0000', '0.0000'], $this->balances()[1]);
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
}
