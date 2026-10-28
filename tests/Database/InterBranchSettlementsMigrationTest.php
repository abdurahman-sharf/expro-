<?php

namespace Tests\Database;

use App\Database\Migrations\InterBranchSettlements;
use App\Libraries\Accounting\AccountingGuard;
use App\Libraries\Accounting\JournalDraft;
use App\Libraries\Accounting\JournalLineDraft;
use App\Libraries\Accounting\PostingService;
use App\Libraries\Accounting\TreasuryService;
use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\DatabaseTestTrait;
use Config\Database;
use Tests\Support\AccountingFixtureTrait;
use Throwable;

class InterBranchSettlementsMigrationTest extends CIUnitTestCase
{
    use DatabaseTestTrait;
    use AccountingFixtureTrait;

    protected $migrate     = true;
    protected $migrateOnce = true;
    protected $refresh     = false;
    protected $namespace   = null;

    private const TABLES = ['interbranch_settlements', 'accounting_interbranch_legs', 'interbranch_pair_locks'];

    public static function setUpBeforeClass(): void
    {
        $seeder = Database::seeder('tests');
        $seeder->call('TestDatabaseBootstrapSeeder');
    }

    protected function tearDown(): void
    {
        (new InterBranchSettlements())->up();

        parent::tearDown();
    }

    private function hasTable(string $table): bool
    {
        return $this->accountingDb()->query(
            'SELECT COUNT(*) AS found FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ?',
            [$this->accountingDb()->prefixTable($table)],
        )->getRow()->found > 0;
    }

    private function permission(): ?object
    {
        return $this->accountingDb()->table('permissions')->where('permission_id', AccountingGuard::INTERBRANCH_SETTLE)->get()->getRow();
    }

    public function testUp_CreatesTheTablesOfSettlementsOfTheRecordOfTheOtherBranchAndOfTheLocks(): void
    {
        foreach (self::TABLES as $table) {
            $this->assertTrue($this->hasTable($table), $table);
        }
    }

    public function testUp_RegistersThePermissionToSettleUnderTheTreasuriesScreenAndGrantsItToNobody(): void
    {
        $permission = $this->permission();

        $this->assertNotNull($permission);
        $this->assertSame('accounting_treasuries', $permission->module_id);
        $this->assertSame(0, $this->accountingDb()->table('grants')->where('permission_id', AccountingGuard::INTERBRANCH_SETTLE)->countAllResults());
    }

    public function testUp_IsSafeToRunAgain(): void
    {
        (new InterBranchSettlements())->up();
        (new InterBranchSettlements())->up();

        foreach (self::TABLES as $table) {
            $this->assertTrue($this->hasTable($table), $table);
        }

        $this->assertSame(1, $this->accountingDb()->table('permissions')->where('permission_id', AccountingGuard::INTERBRANCH_SETTLE)->countAllResults());
    }

    public function testUp_TouchesNoJournalAndLeavesTheTransfersThatExistWithNoRecordOfTheirOwn(): void
    {
        $journals  = $this->countRows('accounting_journals');
        $transfers = $this->countRows('treasury_transfers');

        (new InterBranchSettlements())->down();
        (new InterBranchSettlements())->up();

        $this->assertSame([$journals, $transfers], [$this->countRows('accounting_journals'), $this->countRows('treasury_transfers')]);
        $this->assertSame(0, $this->countRows('accounting_interbranch_legs'));
        $this->assertSame(0, $this->countRows('interbranch_settlements'));
    }

    private function newBranch(): int
    {
        return $this->createBranch('MG' . substr(md5(uniqid('', true)), 0, 10));
    }

    private function rebuilt(): void
    {
        $migration = new InterBranchSettlements();

        $migration->down();
        $migration->up();
    }

    private function rejects(callable $action): bool
    {
        try {
            $action();
        } catch (Throwable) {
            return true;
        }

        return false;
    }

    /**
     * @param array<string, mixed> $changes
     *
     * @return array<string, mixed>
     */
    private function settlement(int $counterpartId, array $changes = []): array
    {
        return $changes + ['company_id' => 1, 'branch_id' => 1, 'counterpart_branch_id' => $counterpartId, 'kind' => 'netting', 'settle_date' => '2026-10-31', 'request_key' => null];
    }

    private function insertsSettlement(array $row): bool
    {
        return ! $this->rejects(fn () => $this->accountingDb()->table('interbranch_settlements')->insert($row));
    }

    /**
     * A journal with its two lines, and the accounts of the lines.
     *
     * @return array{int, int, int} The journal and the accounts of its two lines.
     */
    private function journalWithTwoAccounts(): array
    {
        $this->reinstallChart();

        $cash   = $this->accountId('cash');
        $source = $this->accountId('opening_balance');
        $id     = (new PostingService())->post(new JournalDraft(
            date: '2026-10-21',
            lines: [new JournalLineDraft($cash, debit: '10.00'), new JournalLineDraft($source, credit: '10.00')],
            sourceType: 'opening',
            sourceId: random_int(1000, 999999),
            currencyCode: 'SAR',
            branchId: 1,
        ))->journalId;

        return [$id, $cash, $source];
    }

    /**
     * @param array<string, mixed> $changes
     *
     * @return array<string, mixed>
     */
    private function leg(int $journalId, int $accountId, int $counterpartId, array $changes = []): array
    {
        return $changes + ['journal_id' => $journalId, 'account_id' => $accountId, 'company_id' => 1, 'side' => 'receivable', 'counterpart_branch_id' => $counterpartId, 'document_type' => 'sale_payment', 'document_id' => 1, 'revision' => 1];
    }

    private function insertsLeg(array $row): bool
    {
        return ! $this->rejects(fn () => $this->accountingDb()->table('accounting_interbranch_legs')->insert($row));
    }

    public function testUp_ShutsOutTwoSettlementsWithTheSameKeyButNotTwoWithNone(): void
    {
        $this->rebuilt();

        $branchId = $this->newBranch();

        $this->assertTrue($this->insertsSettlement($this->settlement($branchId, ['request_key' => 'same'])));
        $this->assertFalse($this->insertsSettlement($this->settlement($branchId, ['request_key' => 'same'])), 'The same key twice');
        $this->assertTrue($this->insertsSettlement($this->settlement($branchId)));
        $this->assertTrue($this->insertsSettlement($this->settlement($branchId)), 'Settlements that name no key are not one');

        $this->accountingDb()->table('interbranch_settlements')->truncate();
    }

    public function testUp_ShutsOutAPairThatIsNotInTheOrderOfTheBranchNumbers(): void
    {
        $this->rebuilt();

        $branchId = $this->newBranch();

        $this->assertFalse($this->insertsSettlement($this->settlement(1, ['branch_id' => $branchId])), 'The higher branch first');
        $this->assertFalse($this->insertsSettlement($this->settlement(1)), 'The same branch twice');
        $this->assertTrue($this->insertsSettlement($this->settlement($branchId)));

        $this->accountingDb()->table('interbranch_settlements')->truncate();
    }

    public function testUp_ShutsOutAKindAndAStatusThatAreNotThoseOfASettlement(): void
    {
        $this->rebuilt();

        $branchId = $this->newBranch();

        $this->assertFalse($this->insertsSettlement($this->settlement($branchId, ['kind' => 'barter'])));
        $this->assertFalse($this->insertsSettlement($this->settlement($branchId, ['status' => 'half'])));
        $this->assertTrue($this->insertsSettlement($this->settlement($branchId, ['kind' => 'cash', 'status' => 'cancelled'])));

        $this->accountingDb()->table('interbranch_settlements')->truncate();
    }

    public function testUp_ShutsOutASettlementOfACompanyOrABranchThatIsNotThere(): void
    {
        $this->rebuilt();

        $gone  = $this->newBranch();
        $there = $this->newBranch();

        $this->accountingDb()->table('branches')->where('branch_id', $gone)->delete();

        $this->assertFalse($this->insertsSettlement($this->settlement($there, ['company_id' => 999])), 'A company that is not there');
        $this->assertFalse($this->insertsSettlement($this->settlement($there, ['branch_id' => $gone])), 'A branch that is not there, first');
        $this->assertFalse($this->insertsSettlement($this->settlement($gone)), 'A branch that is not there, second');
        $this->assertTrue($this->insertsSettlement($this->settlement($there)));

        $this->accountingDb()->table('interbranch_settlements')->truncate();
    }

    public function testUp_KeepsTheTreasuriesASettlementMovedMoneyBetween(): void
    {
        $this->rebuilt();
        $this->reinstallChart();

        $branchId   = $this->newBranch();
        $treasuries = new TreasuryService();
        $from       = $treasuries->create(['name' => 'Settlement from ' . uniqid(), 'kind' => 'cash', 'branch_id' => $branchId]);
        $to         = $treasuries->create(['name' => 'Settlement to ' . uniqid(), 'kind' => 'cash']);

        $this->assertTrue($this->insertsSettlement($this->settlement($branchId, ['kind' => 'cash', 'from_treasury_id' => $from, 'to_treasury_id' => $to])));
        $this->assertTrue($this->rejects(fn () => $this->accountingDb()->table('treasuries')->where('treasury_id', $from)->delete()), 'The treasury it went out of');
        $this->assertTrue($this->rejects(fn () => $this->accountingDb()->table('treasuries')->where('treasury_id', $to)->delete()), 'The treasury it went into');
        $this->assertFalse($this->insertsSettlement($this->settlement($branchId, ['from_treasury_id' => 999999])), 'A treasury that is not there');

        $this->accountingDb()->table('interbranch_settlements')->truncate();
        $this->clearJournals();
    }

    public function testUp_ShutsOutARecordOfASideThatIsNeitherReceivableNorPayable(): void
    {
        [$journalId, $cash, $source] = $this->journalWithTwoAccounts();

        $this->rebuilt();

        $this->assertFalse($this->insertsLeg($this->leg($journalId, $cash, 1, ['side' => 'both'])));
        $this->assertTrue($this->insertsLeg($this->leg($journalId, $cash, 1)));
        $this->assertTrue($this->insertsLeg($this->leg($journalId, $source, 1, ['side' => 'payable'])));
        $this->assertFalse($this->insertsLeg($this->leg($journalId, $source, 1)), 'One record for each account of a journal');

        $this->clearJournals();
    }

    public function testUp_KeepsTheJournalsAndAccountsThatHaveARecordAndShutsOutOnesThatAreNotThere(): void
    {
        [$journalId, $cash] = $this->journalWithTwoAccounts();

        $this->rebuilt();

        $gone = $this->newBranch();

        $this->accountingDb()->table('branches')->where('branch_id', $gone)->delete();

        $this->assertFalse($this->insertsLeg($this->leg(999999, $cash, 1)), 'A journal that is not there');
        $this->assertFalse($this->insertsLeg($this->leg($journalId, 999999, 1)), 'An account that is not there');
        $this->assertFalse($this->insertsLeg($this->leg($journalId, $cash, $gone)), 'A branch that is not there');
        $this->assertFalse($this->insertsLeg($this->leg($journalId, $cash, 1, ['company_id' => 999])), 'A company that is not there');
        $this->assertTrue($this->insertsLeg($this->leg($journalId, $cash, 1)));

        $this->accountingDb()->table('accounting_journal_lines')->where('journal_id', $journalId)->delete();

        $this->assertTrue($this->rejects(fn () => $this->accountingDb()->table('accounting_journals')->where('journal_id', $journalId)->delete()), 'The journal that has a record');

        $this->clearJournals();
    }

    public function testDown_RemovesTheTablesAndThePermission(): void
    {
        (new InterBranchSettlements())->down();

        foreach (self::TABLES as $table) {
            $this->assertFalse($this->hasTable($table), $table);
        }

        $this->assertNull($this->permission());
    }
}
