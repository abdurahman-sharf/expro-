<?php

namespace Tests\Libraries\Accounting;

use App\Libraries\Accounting\AccountingError;
use App\Libraries\Accounting\AccountingException;
use App\Libraries\Accounting\DocumentJournals;
use App\Libraries\Accounting\JournalChange;
use App\Libraries\Accounting\JournalDraft;
use App\Libraries\Accounting\JournalLineDraft;
use App\Libraries\Accounting\TreasuryService;
use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\DatabaseTestTrait;
use Config\Database;
use Tests\Support\AccountingFixtureTrait;
use Tests\Support\EmployeeFixtureTrait;
use Tests\Support\ExpenseAccountingFixtureTrait;

/**
 * Keeping the one journal of a document in step with the document: posted when it is missing,
 * left alone while it still says what the document says, reversed and posted again as the next
 * revision when it does not, and reversed when the document calls for none.
 */
class DocumentJournalsTest extends CIUnitTestCase
{
    use DatabaseTestTrait;
    use AccountingFixtureTrait;
    use EmployeeFixtureTrait;
    use ExpenseAccountingFixtureTrait;

    protected $migrate     = true;
    protected $migrateOnce = true;
    protected $refresh     = false;
    protected $namespace   = null;

    private const SOURCE = 'expense';
    private const ID     = 501;

    private DocumentJournals $journals;

    public static function setUpBeforeClass(): void
    {
        $seeder = Database::seeder('tests');
        $seeder->call('TestDatabaseBootstrapSeeder');
    }

    protected function setUp(): void
    {
        parent::setUp();

        $this->startExpenses();

        $this->journals = new DocumentJournals();
    }

    protected function tearDown(): void
    {
        $this->stopExpenses();

        parent::tearDown();
    }

    private function daysAgo(int $days): string
    {
        return date('Y-m-d', strtotime("-{$days} days"));
    }

    private function draft(string $amount = '100.00', ?string $date = null, ?int $branchId = 1, ?int $creditedAccount = null, int $sourceId = self::ID, string $source = self::SOURCE, ?int $employeeId = null): JournalDraft
    {
        return new JournalDraft(
            date: $date ?? $this->daysAgo(2),
            lines: [
                new JournalLineDraft($this->accountId('expenses_default'), debit: $amount),
                new JournalLineDraft($creditedAccount ?? $this->accountId('cash'), credit: $amount),
            ],
            sourceType: $source,
            sourceId: $sourceId,
            revision: $this->journals->nextRevision($source, $sourceId),
            employeeId: $employeeId,
            currencyCode: 'SAR',
            branchId: $branchId,
        );
    }

    private function sync(?JournalDraft $draft, ?int $employeeId = null, int $sourceId = self::ID, string $source = self::SOURCE): JournalChange
    {
        return $this->journals->sync($source, $sourceId, $draft, $employeeId, 'The document changed');
    }

    private function statuses(int $sourceId = self::ID): array
    {
        return array_map(static fn (object $journal): string => $journal->revision . ':' . $journal->status, $this->journalsOfSource(self::SOURCE, $sourceId));
    }

    private function treasuryAccount(int $treasuryId): int
    {
        return (int) (new TreasuryService())->accountOf($treasuryId);
    }

    private function assertRefusedWith(AccountingError $error, callable $action): void
    {
        try {
            $action();
        } catch (AccountingException $exception) {
            $this->assertSame($error, $exception->error);

            return;
        }

        $this->fail('The change was not refused.');
    }

    public function testActive_ReturnsNothingWhileNoJournalStands(): void
    {
        $this->assertNull($this->journals->active(self::SOURCE, self::ID));
    }

    public function testActive_ReturnsTheJournalThatStandsForTheDocument(): void
    {
        $this->sync($this->draft('100.00', '2026-10-20'));

        $active = $this->journals->active(self::SOURCE, self::ID);

        $this->assertNotNull($active);
        $this->assertSame(['2026-10-20', 1, 1], [$active->journal_date, (int) $active->branch_id, (int) $active->revision]);
    }

    public function testActive_ReturnsTheLatestRevisionAfterTheDocumentChanged(): void
    {
        $this->sync($this->draft('100.00'));
        $this->sync($this->draft('120.00'));

        $this->assertSame(2, (int) $this->journals->active(self::SOURCE, self::ID)->revision);
    }

    public function testActive_IgnoresAJournalThatWasReversed(): void
    {
        $this->sync($this->draft('100.00'));
        $this->sync(null);

        $this->assertNull($this->journals->active(self::SOURCE, self::ID));
    }

    public function testActive_IgnoresTheJournalsOfOtherDocumentsAndSources(): void
    {
        $this->sync($this->draft('100.00', null, 1, null, self::ID + 1));
        $this->sync($this->draft('100.00', null, 1, null, self::ID, 'cashup'));

        $this->assertNull($this->journals->active(self::SOURCE, self::ID));
    }

    public function testNextRevision_StartsAtOneForADocumentWithoutJournals(): void
    {
        $this->assertSame(1, $this->journals->nextRevision(self::SOURCE, self::ID));
    }

    public function testNextRevision_CountsTheRevisionsThatWereReversed(): void
    {
        $this->sync($this->draft('100.00'));
        $this->sync(null);

        $this->assertSame(2, $this->journals->nextRevision(self::SOURCE, self::ID));
    }

    public function testNextRevision_IgnoresTheJournalsOfOtherDocumentsAndSources(): void
    {
        $this->sync($this->draft('100.00', null, 1, null, self::ID + 1));
        $this->sync($this->draft('100.00', null, 1, null, self::ID, 'cashup'));

        $this->assertSame(1, $this->journals->nextRevision(self::SOURCE, self::ID));
    }

    public function testSync_PostsTheDraftWhenNothingStands(): void
    {
        $this->assertSame(JournalChange::Posted, $this->sync($this->draft('100.00')));
        $this->assertSame(['1:posted'], $this->statuses());
    }

    public function testSync_DoesNothingWithoutADraftWhenNothingStands(): void
    {
        $this->assertSame(JournalChange::None, $this->sync(null));
        $this->assertSame([], $this->journalsOfSource(self::SOURCE, self::ID));
    }

    public function testSync_LeavesAJournalThatStillMatchesTheDocumentAlone(): void
    {
        $this->sync($this->draft('100.00'));

        $this->assertSame(JournalChange::Unchanged, $this->sync($this->draft('100.00')));
        $this->assertSame(['1:posted'], $this->statuses());
    }

    public function testSync_IgnoresTheOrderOfTheLinesWhenComparing(): void
    {
        $this->sync($this->draft('100.00'));

        $same = $this->draft('100.00');

        $this->assertSame(JournalChange::Unchanged, $this->sync(new JournalDraft(
            date: $same->date,
            lines: array_reverse($same->lines),
            sourceType: $same->sourceType,
            sourceId: $same->sourceId,
            revision: $same->revision,
            currencyCode: 'SAR',
            branchId: 1,
        )));
    }

    public function testSync_IgnoresTheBranchWhenTheDraftNamesNone(): void
    {
        $this->sync($this->draft('100.00', null, 1));

        $this->assertSame(JournalChange::Unchanged, $this->sync($this->draft('100.00', null, null)));
    }

    public function testSync_ReplacesAJournalWhoseAmountChanged(): void
    {
        $this->sync($this->draft('100.00'));

        $this->assertSame(JournalChange::Replaced, $this->sync($this->draft('120.00')));
        $this->assertSame(['1:reversed', '2:posted'], $this->statuses());
        $this->assertSame([$this->accountId('expenses_default') . '=120.00/0.00', $this->accountId('cash') . '=0.00/120.00'], $this->postedLines(self::SOURCE, self::ID));
        $this->assertSame('-120.00', $this->balanceOf($this->accountId('cash')));
    }

    public function testSync_ReplacesAJournalWhoseDateChanged(): void
    {
        $this->sync($this->draft('100.00', $this->daysAgo(3)));

        $this->assertSame(JournalChange::Replaced, $this->sync($this->draft('100.00', $this->daysAgo(2))));
        $this->assertSame($this->daysAgo(2), $this->journals->active(self::SOURCE, self::ID)->journal_date);
    }

    public function testSync_ReplacesAJournalWhoseBranchChanged(): void
    {
        $second = $this->createBranch('B2');

        $this->sync($this->draft('100.00', null, 1));

        $this->assertSame(JournalChange::Replaced, $this->sync($this->draft('100.00', null, $second)));
        $this->assertSame($second, (int) $this->journals->active(self::SOURCE, self::ID)->branch_id);
    }

    public function testSync_ReplacesAJournalWhoseAccountChanged(): void
    {
        $this->sync($this->draft('100.00'));

        $this->assertSame(JournalChange::Replaced, $this->sync($this->draft('100.00', null, 1, $this->accountId('sales_revenue'))));
        $this->assertSame([$this->accountId('expenses_default') . '=100.00/0.00', $this->accountId('sales_revenue') . '=0.00/100.00'], $this->postedLines(self::SOURCE, self::ID));
    }

    public function testSync_ReversesTheJournalWhenTheDocumentCallsForNone(): void
    {
        $this->sync($this->draft('100.00'));

        $this->assertSame(JournalChange::Reversed, $this->sync(null));
        $this->assertSame(['1:reversed'], $this->statuses());
        $this->assertSame('0.00', $this->balanceOf($this->accountId('cash')));
        $this->assertSame('0.00', $this->balanceOf($this->accountId('expenses_default')));
    }

    public function testSync_TakesTheJournalBackOnItsOwnDayWhileItsPeriodIsOpen(): void
    {
        $this->sync($this->draft('100.00', $this->daysAgo(10)));
        $this->sync(null);

        $reversal = $this->accountingDb()->table('accounting_journals')->where('reverses_journal_id', $this->journalsOfSource(self::SOURCE, self::ID)[0]->journal_id)->get()->getRow();

        $this->assertSame($this->daysAgo(10), $reversal->journal_date);
    }

    public function testSync_TakesTheJournalBackTodayWhenItsPeriodHasBeenClosed(): void
    {
        $this->sync($this->draft('100.00', $this->daysAgo(10)));
        $this->closeThrough($this->daysAgo(5), 1);

        $this->assertSame(JournalChange::Reversed, $this->sync(null));

        $reversal = $this->accountingDb()->table('accounting_journals')->where('reverses_journal_id', $this->journalsOfSource(self::SOURCE, self::ID)[0]->journal_id)->get()->getRow();

        $this->assertSame(date('Y-m-d'), $reversal->journal_date);
    }

    public function testSync_RefusesToPostMoneyOutOfATreasuryTheEmployeeMayNotWithdrawFrom(): void
    {
        $treasury = $this->treasury('Locked drawer');

        $this->restrictTreasury($treasury, 'withdraw', [$this->employeeWithGrants([])]);

        $this->assertRefusedWith(AccountingError::TreasuryWithdrawDenied, fn () => $this->sync($this->draft('100.00', null, 1, $this->treasuryAccount($treasury), self::ID, self::SOURCE, $this->clerkId), $this->clerkId));
        $this->assertSame([], $this->journalsOfSource(self::SOURCE, self::ID));
    }

    public function testSync_RefusesToReplaceTheJournalOfATreasuryTheEmployeeMayNotWithdrawFrom(): void
    {
        $treasury = $this->treasury('Locked drawer');

        $this->sync($this->draft('100.00', null, 1, $this->treasuryAccount($treasury)));
        $this->restrictTreasury($treasury, 'withdraw', [$this->employeeWithGrants([])]);

        $this->assertRefusedWith(AccountingError::TreasuryWithdrawDenied, fn () => $this->sync($this->draft('150.00', null, 1, $this->treasuryAccount($treasury), self::ID, self::SOURCE, $this->clerkId), $this->clerkId));
        $this->assertSame(['1:posted'], $this->statuses());
    }

    public function testSync_RefusesToReverseTheJournalOfATreasuryTheEmployeeMayNotDepositInto(): void
    {
        $treasury = $this->treasury('Locked drawer');

        $this->sync($this->draft('100.00', null, 1, $this->treasuryAccount($treasury)));
        $this->restrictTreasury($treasury, 'deposit', [$this->employeeWithGrants([])]);

        $this->assertRefusedWith(AccountingError::TreasuryDepositDenied, fn () => $this->sync(null, $this->clerkId));
        $this->assertSame(['1:posted'], $this->statuses());
    }

    public function testSync_AsksNothingOfAChangeThatMovesNoMoney(): void
    {
        $treasury = $this->treasury('Locked drawer');

        $this->sync($this->draft('100.00', null, 1, $this->treasuryAccount($treasury)));
        $this->restrictTreasury($treasury, 'withdraw', [$this->employeeWithGrants([])]);

        $this->assertSame(JournalChange::Unchanged, $this->sync($this->draft('100.00', null, 1, $this->treasuryAccount($treasury), self::ID, self::SOURCE, $this->clerkId), $this->clerkId));
    }
}
