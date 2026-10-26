<?php

namespace Tests\Libraries\Accounting;

use App\Libraries\Accounting\AccountingGuard;
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

    public function testPosition_IsNothingWhenTheRoutingNamesNoAccountsBetweenBranches(): void
    {
        $this->accountingDb()->table('accounting_routing')->whereIn('routing_key', ['branches.receivable', 'branches.payable'])->update(['account_id' => null]);

        $this->assertSame('0.0000', $this->position->difference());
        $this->assertSame(['0.0000', '0.0000'], $this->balances()[1]);
    }
}
