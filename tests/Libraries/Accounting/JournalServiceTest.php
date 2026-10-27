<?php

namespace Tests\Libraries\Accounting;

use App\Libraries\Accounting\AccountLedger;
use App\Libraries\Accounting\AccountingError;
use App\Libraries\Accounting\AccountingException;
use App\Libraries\Accounting\AccountingGuard;
use App\Libraries\Accounting\AccountService;
use App\Libraries\Accounting\CostCenterService;
use App\Libraries\Accounting\JournalDraft;
use App\Libraries\Accounting\JournalEntryType;
use App\Libraries\Accounting\JournalAttachments;
use App\Libraries\Accounting\JournalLineDraft;
use App\Libraries\Accounting\JournalService;
use App\Libraries\Accounting\PeriodLocks;
use App\Libraries\Accounting\PostingService;
use App\Libraries\Accounting\ReportPeriod;
use App\Libraries\Accounting\ReportScope;
use App\Libraries\Accounting\TreasuryService;
use App\Libraries\Accounting\TreasuryTransfers;
use App\Libraries\Accounting\TrialBalance;
use App\Libraries\Accounting\PostingError;
use App\Libraries\Accounting\PostingException;
use App\Libraries\Organization\BranchError;
use App\Libraries\Organization\BranchException;
use App\Libraries\Organization\BranchGuard;
use CodeIgniter\Database\Query;
use CodeIgniter\Events\Events;
use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\DatabaseTestTrait;
use Config\Database;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Support\AccountingFixtureTrait;
use Tests\Support\ItemFixtureTrait;
use Tests\Support\JournalAttachmentFixtureTrait;
use Tests\Support\SaleAccountingFixtureTrait;

class JournalServiceTest extends CIUnitTestCase
{
    use DatabaseTestTrait;
    use AccountingFixtureTrait;
    use ItemFixtureTrait;
    use JournalAttachmentFixtureTrait;
    use SaleAccountingFixtureTrait;

    protected $migrate     = true;
    protected $migrateOnce = true;
    protected $refresh     = false;
    protected $namespace   = null;

    private JournalService $service;

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
        $this->setConfigValue('accounting_line_description_required', '0');
        $this->service = new JournalService();
    }

    protected function tearDown(): void
    {
        $this->releaseOpenTransactions();
        $this->removeAttachmentFiles();
        $this->stopSelling();
        $this->setConfigValue('accounting_line_description_required', '0');

        parent::tearDown();
    }

    private function assertFailsWith(AccountingError $expected, callable $action): void
    {
        try {
            $action();
        } catch (AccountingException $exception) {
            $this->assertSame($expected, $exception->error);

            return;
        }

        $this->fail('Expected the failure ' . $expected->value . '.');
    }

    private function assertPostingFailsWith(PostingError $expected, callable $action): void
    {
        try {
            $action();
        } catch (PostingException $exception) {
            $this->assertSame($expected, $exception->error);

            return;
        }

        $this->fail('Expected the posting failure ' . $expected->value . '.');
    }

    private function assertBranchFailsWith(BranchError $expected, callable $action): void
    {
        try {
            $action();
        } catch (BranchException $exception) {
            $this->assertSame($expected, $exception->error);

            return;
        }

        $this->fail('Expected the branch failure ' . $expected->value . '.');
    }

    /**
     * @param list<int>|null $branchIds
     */
    private function accountant(?array $branchIds = null): int
    {
        return $this->employeeWithGrants(
            ['accounting_journals', AccountingGuard::JOURNALS_DRAFT, AccountingGuard::JOURNALS_POST, AccountingGuard::JOURNALS_REVERSE, AccountingGuard::JOURNALS_ALL],
            $branchIds,
        );
    }

    private function journal(int $journalId): ?object
    {
        return $this->accountingDb()->table('accounting_journals')->where('journal_id', $journalId)->get()->getRow();
    }

    /**
     * @return list<object>
     */
    private function lines(int $journalId): array
    {
        return $this->accountingDb()->table('accounting_journal_lines')->where('journal_id', $journalId)->orderBy('line_no')->get()->getResult();
    }

    private function unbalancedInput(): array
    {
        $input                      = $this->journalInput('100.00');
        $input['lines'][1]['credit'] = '40.00';

        return $input;
    }

    public function testSaveDraft_StoresADraftWithoutANumberAndWithItsTotals(): void
    {
        $journalId = $this->service->saveDraft($this->journalInput('100.00'));

        $journal = $this->journal($journalId);
        $this->assertSame('draft', $journal->status);
        $this->assertNull($journal->number);
        $this->assertSame('manual', $journal->series);
        $this->assertSame('manual', $journal->source_type);
        $this->assertSame('user', $journal->origin);
        $this->assertSame('SAR', $journal->currency_code);
        $this->assertSame('100.0000', $journal->total_debit);
        $this->assertSame('100.0000', $journal->total_credit);
        $this->assertSame('Test journal', $journal->description);
        $this->assertCount(2, $this->lines($journalId));
    }

    public function testSaveDraft_AcceptsAnUnbalancedDraft(): void
    {
        $journalId = $this->service->saveDraft($this->unbalancedInput());

        $journal = $this->journal($journalId);
        $this->assertSame('100.0000', $journal->total_debit);
        $this->assertSame('40.0000', $journal->total_credit);
    }

    public function testSaveDraft_DropsBlankLinesAndKeepsTheRest(): void
    {
        $input            = $this->journalInput();
        $input['lines'][] = ['account_id' => '', 'description' => '', 'debit' => '', 'credit' => ''];
        $input['lines'][] = [];

        $journalId = $this->service->saveDraft($input);

        $this->assertCount(2, $this->lines($journalId));
    }

    public function testSaveDraft_KeepsTheDescriptionAndCostCenterOfALine(): void
    {
        $costCenter                           = (new CostCenterService())->create(['code' => 'MAIN', 'name' => 'Main']);
        $input                                = $this->journalInput();
        $input['lines'][0]['description']     = 'Cash sale';
        $input['lines'][0]['cost_center_id']  = $costCenter;

        $line = $this->lines($this->service->saveDraft($input))[0];

        $this->assertSame('Cash sale', $line->description);
        $this->assertSame((string) $costCenter, (string) $line->cost_center_id);
    }

    public function testSaveDraft_RejectsALineWithAmountsButNoAccount(): void
    {
        $input                           = $this->journalInput();
        $input['lines'][1]['account_id'] = '';

        $this->assertFailsWith(AccountingError::LineAccountRequired, fn () => $this->service->saveDraft($input));
    }

    public function testSaveDraft_RejectsALineWithBothADebitAndACredit(): void
    {
        $input                       = $this->journalInput();
        $input['lines'][0]['credit'] = '5.00';

        $this->assertFailsWith(AccountingError::LineOnBothSides, fn () => $this->service->saveDraft($input));
    }

    #[DataProvider('invalidAmountProvider')]
    public function testSaveDraft_RejectsAMalformedOrNegativeAmount(string $amount): void
    {
        $input                      = $this->journalInput();
        $input['lines'][0]['debit'] = $amount;

        $this->assertFailsWith(AccountingError::LineAmountInvalid, fn () => $this->service->saveDraft($input));
    }

    public static function invalidAmountProvider(): iterable
    {
        yield 'negative' => ['-1'];
        yield 'text' => ['abc'];
        yield 'too many decimals' => ['1.23456'];
        yield 'thousands separator' => ['1,000'];
    }

    public function testSaveDraft_RejectsAGroupAnInactiveAndAnUnknownAccount(): void
    {
        $inactive = $this->customAccount();
        (new AccountService())->deactivate($inactive);

        foreach ([$this->accountIdByCode('11'), $inactive, 999999] as $accountId) {
            $input                           = $this->journalInput();
            $input['lines'][0]['account_id'] = $accountId;

            $this->assertFailsWith(AccountingError::LineAccountInvalid, fn () => $this->service->saveDraft($input));
        }
    }

    public function testSaveDraft_RejectsAnUnknownOrInactiveCostCenter(): void
    {
        $inactive = (new CostCenterService())->create(['code' => 'OLD', 'name' => 'Old']);
        (new CostCenterService())->deactivate($inactive);

        foreach ([999999, $inactive] as $costCenterId) {
            $input                               = $this->journalInput();
            $input['lines'][0]['cost_center_id'] = $costCenterId;

            $this->assertFailsWith(AccountingError::LineCostCenterInvalid, fn () => $this->service->saveDraft($input));
        }
    }

    public function testSaveDraft_RejectsAJournalWithNoLines(): void
    {
        $this->assertFailsWith(AccountingError::NoLines, fn () => $this->service->saveDraft(['date' => '2026-10-21', 'lines' => []]));
        $this->assertFailsWith(AccountingError::NoLines, fn () => $this->service->saveDraft(['date' => '2026-10-21', 'lines' => [[], ['debit' => '']]]));
    }

    public function testSaveDraft_RejectsMoreThanTheMaximumNumberOfLines(): void
    {
        $line = ['account_id' => $this->accountId('cash'), 'debit' => '1.00'];

        $this->assertFailsWith(AccountingError::TooManyLines, fn () => $this->service->saveDraft([
            'date'  => '2026-10-21',
            'lines' => array_fill(0, 501, $line),
        ]));
    }

    public function testSaveDraft_RejectsAnInvalidDate(): void
    {
        $input         = $this->journalInput();
        $input['date'] = '2026-02-30';

        $this->assertFailsWith(AccountingError::InvalidDate, fn () => $this->service->saveDraft($input));
    }

    public function testSaveDraft_RejectsAMissingBaseCurrency(): void
    {
        $this->setConfigValue('accounting_base_currency', '');

        $this->assertFailsWith(AccountingError::BaseCurrencyMissing, fn () => $this->service->saveDraft($this->journalInput()));
    }

    public function testSaveDraft_ReplacesTheLinesOfAnExistingDraft(): void
    {
        $journalId = $this->service->saveDraft($this->journalInput('100.00'));

        $input                = $this->journalInput('30.00', 'Changed');
        $input['date']        = '2026-10-25';
        $input['lines'][]     = ['account_id' => $this->accountId('cogs'), 'debit' => '5.00'];
        $same                 = $this->service->saveDraft($input, null, $journalId);

        $journal = $this->journal($journalId);
        $this->assertSame($journalId, $same);
        $this->assertSame('Changed', $journal->description);
        $this->assertSame('2026-10-25', $journal->journal_date);
        $this->assertSame('35.0000', $journal->total_debit);
        $this->assertCount(3, $this->lines($journalId));
        $this->assertSame(1, $this->countRows('accounting_journals'));
    }

    public function testSaveDraft_RejectsChangingAPostedJournal(): void
    {
        $journalId = $this->service->postNew($this->journalInput());

        $this->assertFailsWith(AccountingError::NotADraft, fn () => $this->service->saveDraft($this->journalInput(), null, $journalId));
    }

    public function testSaveDraft_RequiresTheDraftPermission(): void
    {
        $viewer = $this->employeeWithGrants(['accounting_journals']);
        $author = $this->employeeWithGrants(['accounting_journals', AccountingGuard::JOURNALS_DRAFT]);

        $this->assertFailsWith(AccountingError::PermissionDenied, fn () => $this->service->saveDraft($this->journalInput(), $viewer));

        $this->assertGreaterThan(0, $this->service->saveDraft($this->journalInput(), $author));
    }

    public function testSaveDraft_LetsOnlyTheOwnerOrAHolderOfAllEditADraft(): void
    {
        $owner   = $this->employeeWithGrants(['accounting_journals', AccountingGuard::JOURNALS_DRAFT]);
        $other   = $this->employeeWithGrants(['accounting_journals', AccountingGuard::JOURNALS_DRAFT]);
        $manager = $this->employeeWithGrants(['accounting_journals', AccountingGuard::JOURNALS_DRAFT, AccountingGuard::JOURNALS_ALL]);

        $journalId = $this->service->saveDraft($this->journalInput(), $owner);

        $this->assertFailsWith(AccountingError::JournalNotFound, fn () => $this->service->saveDraft($this->journalInput('9.00'), $other, $journalId));

        $this->service->saveDraft($this->journalInput('7.00'), $manager, $journalId);
        $this->assertSame('7.0000', $this->journal($journalId)->total_debit);
    }

    public function testSaveDraft_WritesAnAuditEntryForEachSave(): void
    {
        $journalId = $this->service->saveDraft($this->journalInput());
        $this->service->saveDraft($this->journalInput('5.00'), null, $journalId);

        $actions = array_column(
            $this->accountingDb()->table('accounting_audit_log')->where('entity_id', $journalId)->orderBy('audit_id')->get()->getResultArray(),
            'action',
        );

        $this->assertSame(['draft_created', 'draft_updated'], $actions);
    }

    public function testDeleteDraft_RemovesTheDraftAndItsLines(): void
    {
        $journalId = $this->service->saveDraft($this->journalInput());

        $this->service->deleteDraft($journalId);

        $this->assertNull($this->journal($journalId));
        $this->assertSame(0, $this->countRows('accounting_journal_lines'));
        $this->assertSame(1, $this->accountingDb()->table('accounting_audit_log')->where('action', 'draft_deleted')->countAllResults());
    }

    public function testDeleteDraft_RejectsAPostedJournalAndAnUnknownOne(): void
    {
        $posted = $this->service->postNew($this->journalInput());

        $this->assertFailsWith(AccountingError::NotADraft, fn () => $this->service->deleteDraft($posted));
        $this->assertFailsWith(AccountingError::JournalNotFound, fn () => $this->service->deleteDraft(999999));
        $this->assertNotNull($this->journal($posted));
    }

    public function testPostDraft_PostsAStoredDraftAndAssignsTheNextNumber(): void
    {
        $poster    = $this->employeeWithGrants(['accounting_journals', AccountingGuard::JOURNALS_DRAFT, AccountingGuard::JOURNALS_POST]);
        $journalId = $this->service->saveDraft($this->journalInput('80.00'), $poster);

        $this->service->postDraft($journalId, $poster);

        $journal = $this->journal($journalId);
        $this->assertSame('posted', $journal->status);
        $this->assertSame('000001', $journal->number);
        $this->assertSame((string) $poster, (string) $journal->posted_by);
        $this->assertNotNull($journal->posted_at);
        $this->assertSame('80.0000', $journal->total_debit);
        $this->assertCount(2, $this->lines($journalId));
    }

    public function testPostDraft_NumbersDraftsInThePostingOrder(): void
    {
        $first  = $this->service->saveDraft($this->journalInput('1.00'));
        $second = $this->service->saveDraft($this->journalInput('2.00'));

        $this->service->postDraft($second);
        $this->service->postDraft($first);

        $this->assertSame('000001', $this->journal($second)->number);
        $this->assertSame('000002', $this->journal($first)->number);
    }

    public function testPostDraft_RejectsAnUnbalancedDraftAndLeavesItADraft(): void
    {
        $journalId = $this->service->saveDraft($this->unbalancedInput());

        $this->assertPostingFailsWith(PostingError::Unbalanced, fn () => $this->service->postDraft($journalId));

        $journal = $this->journal($journalId);
        $this->assertSame('draft', $journal->status);
        $this->assertNull($journal->number);
    }

    public function testPostDraft_ReplacesTheContentWhenInputIsGiven(): void
    {
        $journalId = $this->service->saveDraft($this->unbalancedInput());

        $this->service->postDraft($journalId, null, $this->journalInput('60.00', 'Fixed'));

        $journal = $this->journal($journalId);
        $this->assertSame('posted', $journal->status);
        $this->assertSame('Fixed', $journal->description);
        $this->assertSame('60.0000', $journal->total_debit);
        $this->assertCount(2, $this->lines($journalId));
    }

    public function testPostDraft_WritesNothingWhenTheNewInputDoesNotPass(): void
    {
        $journalId = $this->service->saveDraft($this->journalInput('10.00', 'Original'));

        $this->assertPostingFailsWith(PostingError::Unbalanced, fn () => $this->service->postDraft($journalId, null, $this->unbalancedInput()));

        $journal = $this->journal($journalId);
        $this->assertSame('draft', $journal->status);
        $this->assertSame('Original', $journal->description);
        $this->assertSame('10.0000', $journal->total_debit);
    }

    public function testPostDraft_RejectsAJournalThatIsNotADraft(): void
    {
        $posted = $this->service->postNew($this->journalInput());

        $this->assertFailsWith(AccountingError::NotADraft, fn () => $this->service->postDraft($posted));
    }

    public function testPostDraft_RequiresThePostPermission(): void
    {
        $author    = $this->employeeWithGrants(['accounting_journals', AccountingGuard::JOURNALS_DRAFT]);
        $journalId = $this->service->saveDraft($this->journalInput(), $author);

        $this->assertFailsWith(AccountingError::PermissionDenied, fn () => $this->service->postDraft($journalId, $author));
    }

    public function testPostNew_PostsAManualJournalWithAFreshNumber(): void
    {
        $journalId = $this->service->postNew($this->journalInput('45.50'));

        $journal = $this->journal($journalId);
        $this->assertSame('posted', $journal->status);
        $this->assertSame('000001', $journal->number);
        $this->assertSame('manual', $journal->source_type);
        $this->assertSame('45.5000', $journal->total_debit);
    }

    public function testPostNew_RejectsAnUnbalancedJournalAndSavesNothing(): void
    {
        $this->assertPostingFailsWith(PostingError::Unbalanced, fn () => $this->service->postNew($this->unbalancedInput()));

        $this->assertSame(0, $this->countRows('accounting_journals'));
        $this->assertSame(0, $this->countRows('accounting_journal_lines'));
    }

    public function testPostNew_RequiresBothTheDraftAndThePostPermissions(): void
    {
        $postOnly  = $this->employeeWithGrants(['accounting_journals', AccountingGuard::JOURNALS_POST]);
        $draftOnly = $this->employeeWithGrants(['accounting_journals', AccountingGuard::JOURNALS_DRAFT]);
        $both      = $this->employeeWithGrants(['accounting_journals', AccountingGuard::JOURNALS_DRAFT, AccountingGuard::JOURNALS_POST]);

        $this->assertFailsWith(AccountingError::PermissionDenied, fn () => $this->service->postNew($this->journalInput(), $postOnly));
        $this->assertFailsWith(AccountingError::PermissionDenied, fn () => $this->service->postNew($this->journalInput(), $draftOnly));

        $this->assertGreaterThan(0, $this->service->postNew($this->journalInput(), $both));
    }

    public function testReverse_PostsTheMirrorEntryAndMarksTheOriginalReversed(): void
    {
        $original = $this->service->postNew($this->journalInput('75.00'));

        $reversal = $this->service->reverse($original, '2026-10-30', 'Wrong month');

        $this->assertSame('reversed', $this->journal($original)->status);

        $entry = $this->journal($reversal);
        $this->assertSame('posted', $entry->status);
        $this->assertSame('reversal', $entry->source_type);
        $this->assertSame((string) $original, (string) $entry->source_id);
        $this->assertSame((string) $original, (string) $entry->reverses_journal_id);
        $this->assertSame('2026-10-30', $entry->journal_date);
        $this->assertSame('Wrong month', $entry->description);
        $this->assertSame('75.0000', $entry->total_debit);

        $originalLines = $this->lines($original);
        $reversalLines = $this->lines($reversal);
        $this->assertSame($originalLines[0]->account_id, $reversalLines[0]->account_id);
        $this->assertSame('0.0000', $reversalLines[0]->debit);
        $this->assertSame('75.0000', $reversalLines[0]->credit);
        $this->assertSame('75.0000', $reversalLines[1]->debit);
    }

    public function testReverse_RejectsADraftAndAJournalThatIsAlreadyReversed(): void
    {
        $draft    = $this->service->saveDraft($this->journalInput());
        $original = $this->service->postNew($this->journalInput());
        $this->service->reverse($original, '2026-10-22', 'Once');

        $this->assertFailsWith(AccountingError::NotPosted, fn () => $this->service->reverse($draft, '2026-10-22', 'Draft'));
        $this->assertFailsWith(AccountingError::AlreadyReversed, fn () => $this->service->reverse($original, '2026-10-23', 'Twice'));
        $this->assertSame(1, $this->accountingDb()->table('accounting_journals')->where('source_type', 'reversal')->countAllResults());
    }

    public function testReverse_RejectsReversingAReversal(): void
    {
        $original = $this->service->postNew($this->journalInput());
        $reversal = $this->service->reverse($original, '2026-10-22', 'Once');

        $this->assertFailsWith(AccountingError::CannotReverseReversal, fn () => $this->service->reverse($reversal, '2026-10-23', 'Undo'));
    }

    public function testReverse_RequiresAReasonAndAValidDate(): void
    {
        $original = $this->service->postNew($this->journalInput());

        $this->assertFailsWith(AccountingError::ReasonRequired, fn () => $this->service->reverse($original, '2026-10-22', '  '));
        $this->assertFailsWith(AccountingError::ReasonRequired, fn () => $this->service->reverse($original, '2026-10-22', str_repeat('x', 256)));
        $this->assertFailsWith(AccountingError::InvalidDate, fn () => $this->service->reverse($original, 'yesterday', 'Reason'));
        $this->assertSame('posted', $this->journal($original)->status);
    }

    public function testReverse_AllowsAnAccountThatWasDeactivatedAfterPosting(): void
    {
        $accounts = new AccountService();
        $account  = $this->customAccount();

        $input                           = $this->journalInput();
        $input['lines'][0]['account_id'] = $account;
        $original                        = $this->service->postNew($input);

        $accounts->deactivate($account);

        $reversal = $this->service->reverse($original, '2026-10-22', 'Cleanup');

        $this->assertSame('posted', $this->journal($reversal)->status);
    }

    public function testReverse_RequiresTheReversePermission(): void
    {
        $original = $this->service->postNew($this->journalInput());
        $viewer   = $this->employeeWithGrants(['accounting_journals', AccountingGuard::JOURNALS_ALL]);

        $this->assertFailsWith(AccountingError::PermissionDenied, fn () => $this->service->reverse($original, '2026-10-22', 'Reason', $viewer));
    }

    public function testReverse_RejectsAJournalTheEmployeeCannotSee(): void
    {
        $original = $this->service->postNew($this->journalInput());
        $reverser = $this->employeeWithGrants(['accounting_journals', AccountingGuard::JOURNALS_REVERSE]);

        $this->assertFailsWith(AccountingError::JournalNotFound, fn () => $this->service->reverse($original, '2026-10-22', 'Reason', $reverser));
    }

    public function testReverse_RefusesAnEmployeeWhoMayNotWithdrawFromTheTreasuryTheJournalPutMoneyInto(): void
    {
        $treasuries                      = new TreasuryService();
        $drawer                          = $treasuries->create(['name' => 'Closed drawer', 'kind' => 'cash', 'withdraw_scope' => 'staff', 'withdraw_members' => [$this->employeeWithGrants([])]]);
        $input                           = $this->journalInput('75.00');
        $input['lines'][0]['account_id'] = (int) $treasuries->accountOf($drawer);
        $original                        = $this->service->postNew($input);

        $this->assertFailsWith(AccountingError::TreasuryWithdrawDenied, fn () => $this->service->reverse($original, '2026-10-22', 'Reason', $this->accountant()));
        $this->assertSame('posted', $this->journal($original)->status);
        $this->assertSame(0, $this->accountingDb()->table('accounting_journals')->where('source_type', 'reversal')->countAllResults());
    }

    public function testReverse_AcceptsAnEmployeeWhoMayWithdrawFromTheTreasuryTheJournalPutMoneyIntoAndTheBooksAlways(): void
    {
        $treasuries                      = new TreasuryService();
        $reverser                        = $this->accountant();
        $drawer                          = $treasuries->create(['name' => 'Open drawer', 'kind' => 'cash', 'withdraw_scope' => 'staff', 'withdraw_members' => [$reverser]]);
        $input                           = $this->journalInput('75.00');
        $input['lines'][0]['account_id'] = (int) $treasuries->accountOf($drawer);
        $first                           = $this->service->postNew($input);
        $second                          = $this->service->postNew($input);

        $this->assertSame('posted', $this->journal($this->service->reverse($first, '2026-10-22', 'Reason', $reverser))->status);

        $treasuries->update($drawer, ['name' => 'Open drawer', 'kind' => 'cash', 'withdraw_scope' => 'staff', 'withdraw_members' => [$this->employeeWithGrants([])]]);

        $this->assertSame('posted', $this->journal($this->service->reverse($second, '2026-10-22', 'The books act'))->status);
    }

    /**
     * A journal of the given amount that debits a treasury scoped to the employees given, or credits it.
     *
     * @return array<string, mixed>
     */
    private function journalOnATreasury(string $scope, array $members, bool $debit, string $amount = '75.00'): array
    {
        $treasuries = new TreasuryService();
        $drawer     = $treasuries->create(['name' => 'Scoped drawer ' . uniqid(), 'kind' => 'cash', ($debit ? 'deposit' : 'withdraw') . '_scope' => $scope, ($debit ? 'deposit' : 'withdraw') . '_members' => $members]);
        $input      = $this->journalInput($amount);
        $line       = $debit ? 0 : 1;

        $input['lines'][$line]['account_id'] = (int) $treasuries->accountOf($drawer);

        return $input;
    }

    public function testPostNew_RefusesAnEmployeeWhoMayNotDepositIntoTheTreasuryTheJournalDebits(): void
    {
        $input = $this->journalOnATreasury('staff', [$this->employeeWithGrants([])], true);

        $this->assertFailsWith(AccountingError::TreasuryDepositDenied, fn () => $this->service->postNew($input, $this->accountant()));
        $this->assertSame(0, $this->accountingDb()->table('accounting_journals')->where('source_type', 'manual')->countAllResults());
    }

    public function testPostNew_RefusesAnEmployeeWhoMayNotWithdrawFromTheTreasuryTheJournalCredits(): void
    {
        $input = $this->journalOnATreasury('staff', [$this->employeeWithGrants([])], false);

        $this->assertFailsWith(AccountingError::TreasuryWithdrawDenied, fn () => $this->service->postNew($input, $this->accountant()));
        $this->assertSame(0, $this->accountingDb()->table('accounting_journals')->where('source_type', 'manual')->countAllResults());
    }

    public function testPostNew_AcceptsAnEmployeeWhoMayUseTheTreasuryAndTheBooksAlways(): void
    {
        $accountant = $this->accountant();
        $closed     = $this->journalOnATreasury('staff', [$this->employeeWithGrants([])], true);
        $open       = $this->journalOnATreasury('staff', [$accountant], true);

        $this->assertGreaterThan(0, $this->service->postNew($open, $accountant));
        $this->assertGreaterThan(0, $this->service->postNew($closed));
    }

    public function testPostDraft_RefusesWhatPostNewRefusesAndSavingTheDraftAsksNothing(): void
    {
        $input      = $this->journalOnATreasury('staff', [$this->employeeWithGrants([])], true);
        $accountant = $this->accountant();
        $draftId    = $this->service->saveDraft($input, $accountant);

        $this->assertFailsWith(AccountingError::TreasuryDepositDenied, fn () => $this->service->postDraft($draftId, $accountant));
        $this->assertFailsWith(AccountingError::TreasuryDepositDenied, fn () => $this->service->postDraft($draftId, $accountant, $input));
        $this->assertSame('draft', $this->journal($draftId)->status);
        $this->assertSame($draftId, $this->service->postDraft($draftId), 'The books may post it');
    }

    public function testReverse_RefusesTheJournalOfATransferBetweenTreasuriesAndLeavesItToTheCancellationOfTheTransfer(): void
    {
        $treasuries = new TreasuryService();
        $branch     = $this->createBranch('B2');
        $from       = $treasuries->create(['name' => 'Sending drawer', 'kind' => 'cash']);
        $to         = $treasuries->create(['name' => 'Receiving drawer', 'kind' => 'cash', 'branch_id' => $branch]);
        $id         = (new TreasuryTransfers())->transfer($from, $to, '25.00', '2026-10-21');
        $reverser   = $this->accountant();

        foreach ($this->accountingDb()->table('accounting_journals')->whereIn('source_type', ['treasury_transfer', 'treasury_transfer_receipt'])->where('source_id', $id)->get()->getResult() as $journal) {
            $this->assertFailsWith(AccountingError::TransferJournalLocked, fn () => $this->service->reverse((int) $journal->journal_id, '2026-10-22', 'By hand', $reverser));
            $this->assertSame('posted', $this->journal((int) $journal->journal_id)->status);
        }

        (new TreasuryTransfers())->cancel($id, 'The right way');

        $this->assertSame('0.0000', $treasuries->position($to)->balance);
    }

    /**
     * A journal of one of the sources the stock transfers between branches write, posted by the system.
     */
    private function journalOfAStockTransfer(string $source): int
    {
        $draft = new JournalDraft(
            date: '2026-10-21',
            lines: [new JournalLineDraft($this->accountId('cash'), debit: '10.00'), new JournalLineDraft($this->accountId('sales_revenue'), credit: '10.00')],
            sourceType: $source,
            sourceId: random_int(1000, 999999),
        );

        return (new PostingService())->post($draft)->journalId;
    }

    public function testReverse_RefusesTheJournalsOfAStockTransferAndLeavesThemToItsDeletion(): void
    {
        $reverser = $this->accountant();

        foreach (['stock_transfer', 'stock_transfer_receipt'] as $source) {
            $journalId = $this->journalOfAStockTransfer($source);

            $this->assertFailsWith(AccountingError::TransferJournalLocked, fn () => $this->service->reverse($journalId, '2026-10-22', 'By hand', $reverser));
            $this->assertSame('posted', $this->journal($journalId)->status);
        }
    }

    public function testReverseAutomatic_TakesBackTheJournalsOfAStockTransfer(): void
    {
        foreach (['stock_transfer', 'stock_transfer_receipt'] as $source) {
            $journalId = $this->journalOfAStockTransfer($source);

            $this->assertGreaterThan(0, $this->service->reverseAutomatic($journalId, '2026-10-22', 'Transfer deleted', null));
            $this->assertSame('reversed', $this->journal($journalId)->status);
        }
    }

    public function testReverse_WritesAnAuditEntryWithTheReason(): void
    {
        $original = $this->service->postNew($this->journalInput());
        $reversal = $this->service->reverse($original, '2026-10-22', 'Entered twice');

        $audit = $this->accountingDb()->table('accounting_audit_log')->where('entity_id', $original)->where('action', 'reversed')->get()->getRow();

        $details = json_decode($audit->details, true);
        $this->assertSame($reversal, $details['reversal_id']);
        $this->assertSame('Entered twice', $details['reason']);
    }

    public function testFind_ReturnsTheLinesWithAccountAndCostCenterNames(): void
    {
        $costCenter                          = (new CostCenterService())->create(['code' => 'MAIN', 'name' => 'Main']);
        $input                               = $this->journalInput();
        $input['lines'][0]['cost_center_id'] = $costCenter;
        $journalId                           = $this->service->postNew($input);

        $journal = $this->service->find($journalId);

        $this->assertCount(2, $journal->lines);
        $this->assertSame('120101', $journal->lines[0]->account_code);
        $this->assertSame('MAIN', $journal->lines[0]->cost_center_code);
        $this->assertNull($journal->lines[1]->cost_center_code);
        $this->assertSame('411', $journal->lines[1]->account_code);
    }

    public function testFind_LinksAReversalToItsOriginal(): void
    {
        $original = $this->service->postNew($this->journalInput());
        $reversal = $this->service->reverse($original, '2026-10-22', 'Reason');

        $first  = $this->service->find($original);
        $second = $this->service->find($reversal);

        $this->assertSame((string) $reversal, (string) $first->reversed_by_id);
        $this->assertSame($this->journal($reversal)->number, $first->reversed_by_number);
        $this->assertSame($first->number, $second->reverses_number);
    }

    public function testFind_ReturnsTheNamesOfThePeopleInvolved(): void
    {
        $poster    = $this->createEmployee('Huda', 'Salem', grants: [
            ['permission_id' => 'accounting_journals', 'menu_group' => 'home'],
            ['permission_id' => AccountingGuard::JOURNALS_DRAFT, 'menu_group' => 'home'],
            ['permission_id' => AccountingGuard::JOURNALS_POST, 'menu_group' => 'home'],
            ['permission_id' => BranchGuard::ALL_BRANCHES, 'menu_group' => '--'],
        ]);
        $journalId = $this->service->postNew($this->journalInput(), $poster);

        $journal = $this->service->find($journalId);

        $this->assertSame('Huda Salem', $journal->created_by_name);
        $this->assertSame('Huda Salem', $journal->posted_by_name);
    }

    public function testFind_HidesAnotherEmployeesJournalWithoutTheAllPermission(): void
    {
        $journalId = $this->service->postNew($this->journalInput());
        $reader    = $this->employeeWithGrants(['accounting_journals']);
        $manager   = $this->employeeWithGrants(['accounting_journals', AccountingGuard::JOURNALS_ALL]);

        $this->assertFailsWith(AccountingError::JournalNotFound, fn () => $this->service->find($journalId, $reader));
        $this->assertSame($journalId, (int) $this->service->find($journalId, $manager)->journal_id);
    }

    public function testFind_RejectsAnUnknownJournal(): void
    {
        $this->assertFailsWith(AccountingError::JournalNotFound, fn () => $this->service->find(999999));
    }

    public function testSearch_ReturnsJournalsNewestFirstWithTheCreatorName(): void
    {
        $author = $this->createEmployee('Omar', 'Khaled', grants: [
            ['permission_id' => 'accounting_journals', 'menu_group' => 'home'],
            ['permission_id' => AccountingGuard::JOURNALS_DRAFT, 'menu_group' => 'home'],
            ['permission_id' => AccountingGuard::JOURNALS_POST, 'menu_group' => 'home'],
            ['permission_id' => BranchGuard::ALL_BRANCHES, 'menu_group' => '--'],
        ]);

        $input = $this->journalInput();
        $first = $this->service->postNew(['date' => '2026-10-01'] + $input, $author);
        $last  = $this->service->postNew(['date' => '2026-10-20'] + $input, $author);

        $result = $this->service->search([]);

        $this->assertSame(2, $result['total']);
        $this->assertSame([$last, $first], array_map(static fn (object $row): int => (int) $row->journal_id, $result['rows']));
        $this->assertSame('Omar Khaled', $result['rows'][0]->created_by_name);
    }

    public function testSearch_FiltersByStatusAndSource(): void
    {
        $draft    = $this->service->saveDraft($this->journalInput());
        $posted   = $this->service->postNew($this->journalInput());
        $reversed = $this->service->postNew($this->journalInput());
        $this->service->reverse($reversed, '2026-10-22', 'Reason');

        $ids = static fn (array $result): array => array_map(static fn (object $row): int => (int) $row->journal_id, $result['rows']);

        $this->assertSame([$draft], $ids($this->service->search(['status' => ['draft']])));
        $this->assertSame([$posted], $ids($this->service->search(['status' => ['posted'], 'source_type' => 'manual'])));
        $this->assertSame([$reversed], $ids($this->service->search(['status' => ['reversed']])));
        $this->assertSame(1, $this->service->search(['source_type' => 'reversal'])['total']);
        $this->assertSame(4, $this->service->search(['status' => ['bogus', 'draft', 'posted', 'reversed']])['total']);
    }

    public function testSearch_FiltersByEntryType(): void
    {
        $revenue = (new PostingService())->post($this->balancedDraft(sourceId: 5, entryType: JournalEntryType::Revenue))->journalId;
        $cogs    = (new PostingService())->post($this->balancedDraft(sourceId: 5, entryType: JournalEntryType::Cogs))->journalId;

        $ids = static fn (array $result): array => array_map(static fn (object $row): int => (int) $row->journal_id, $result['rows']);

        $this->assertSame([$revenue], $ids($this->service->search(['entry_type' => 'revenue'])));
        $this->assertSame([$cogs], $ids($this->service->search(['entry_type' => 'cogs'])));
        $this->assertSame(2, $this->service->search(['entry_type' => 'bogus'])['total']);
        $this->assertSame('cogs', $this->service->search(['entry_type' => 'cogs'])['rows'][0]->entry_type);
    }

    public function testSearch_FiltersByNumberDescriptionAndAmount(): void
    {
        $small = $this->service->postNew($this->journalInput('10.00', 'Rent for October'));
        $large = $this->service->postNew($this->journalInput('900.00', 'Equipment'));

        $ids = static fn (array $result): array => array_map(static fn (object $row): int => (int) $row->journal_id, $result['rows']);

        $this->assertSame([$small], $ids($this->service->search(['description' => 'Rent'])));
        $this->assertSame([$large], $ids($this->service->search(['number' => '000002'])));
        $this->assertSame([$large], $ids($this->service->search(['amount_from' => '500'])));
        $this->assertSame([$small], $ids($this->service->search(['amount_to' => '500'])));
        $this->assertSame([], $ids($this->service->search(['amount_from' => '100', 'amount_to' => '200'])));
    }

    public function testSearch_FiltersByAnAccountAndItsDescendants(): void
    {
        $withCash  = $this->service->postNew($this->journalInput('10.00'));
        $withStock = $this->service->postNew([
            'date'  => '2026-10-21',
            'lines' => [
                ['account_id' => $this->accountId('purchases'), 'debit' => '5.00'],
                ['account_id' => $this->accountId('other_payables'), 'credit' => '5.00'],
            ],
        ]);

        $ids = static fn (array $result): array => array_map(static fn (object $row): int => (int) $row->journal_id, $result['rows']);

        $this->assertSame([$withCash], $ids($this->service->search(['account_id' => $this->accountId('cash')])));
        $this->assertEqualsCanonicalizing([$withCash, $withStock], $ids($this->service->search(['account_id' => $this->accountIdByCode('12')])));
        $this->assertSame([$withStock], $ids($this->service->search(['account_id' => $this->accountIdByCode('21')])));
    }

    public function testSearch_FiltersByJournalDateAndCreationDate(): void
    {
        $early = $this->service->postNew(['date' => '2026-09-01'] + $this->journalInput());
        $late  = $this->service->postNew(['date' => '2026-10-15'] + $this->journalInput());

        $ids = static fn (array $result): array => array_map(static fn (object $row): int => (int) $row->journal_id, $result['rows']);

        $this->assertSame([$early], $ids($this->service->search(['date_to' => '2026-09-30'])));
        $this->assertSame([$late], $ids($this->service->search(['date_from' => '2026-10-01'])));
        $this->assertSame(2, $this->service->search(['created_from' => date('Y-m-d'), 'created_to' => date('Y-m-d')])['total']);
        $this->assertSame(0, $this->service->search(['created_to' => '2000-01-01'])['total']);
        $this->assertSame(2, $this->service->search(['date_from' => 'not a date'])['total']);
    }

    public function testSearch_ShowsOnlyOwnJournalsWithoutTheAllPermission(): void
    {
        $grants = ['accounting_journals', AccountingGuard::JOURNALS_DRAFT, AccountingGuard::JOURNALS_POST];
        $mine   = $this->employeeWithGrants($grants);
        $other  = $this->employeeWithGrants($grants);

        $own = $this->service->postNew($this->journalInput(), $mine);
        $this->service->postNew($this->journalInput(), $other);

        $result = $this->service->search([], 'journal_date', 'desc', 25, 0, $mine);

        $this->assertSame(1, $result['total']);
        $this->assertSame($own, (int) $result['rows'][0]->journal_id);
    }

    public function testSearch_PagesAndCountsTheTotal(): void
    {
        for ($i = 1; $i <= 5; $i++) {
            $this->service->postNew($this->journalInput((string) $i . '.00'));
        }

        $page = $this->service->search([], 'number', 'asc', 2, 2);

        $this->assertSame(5, $page['total']);
        $this->assertSame(['000003', '000004'], array_column($page['rows'], 'number'));
    }

    public function testSearch_ExcludesPreviewJournals(): void
    {
        $journalId = $this->service->postNew($this->journalInput());
        $this->accountingDb()->table('accounting_journals')->where('journal_id', $journalId)->update(['status' => 'preview']);

        $this->assertSame(0, $this->service->search([])['total']);
    }

    public function testSearch_FallsBackToTheDefaultOrderForAnUnknownColumn(): void
    {
        $first = $this->service->postNew(['date' => '2026-10-01'] + $this->journalInput());
        $last  = $this->service->postNew(['date' => '2026-10-20'] + $this->journalInput());

        $rows = $this->service->search([], 'password; DROP TABLE x', 'sideways')['rows'];

        $this->assertSame([$last, $first], array_map(static fn (object $row): int => (int) $row->journal_id, $rows));
    }

    public function testSaveDraft_StoresTheRequestedBranch(): void
    {
        $second = $this->createBranch('B2');

        $journalId = $this->service->saveDraft($this->journalInput() + ['branch_id' => $second], $this->accountant());

        $this->assertSame($second, (int) $this->journal($journalId)->branch_id);
    }

    public function testSaveDraft_UsesTheEmployeesDefaultBranchWhenNoneIsGiven(): void
    {
        $second   = $this->createBranch('B2');
        $employee = $this->accountant([$second, 1]);

        $journalId = $this->service->saveDraft($this->journalInput(), $employee);

        $this->assertSame($second, (int) $this->journal($journalId)->branch_id);
    }

    public function testSaveDraft_RejectsABranchOutsideTheEmployeesScope(): void
    {
        $second   = $this->createBranch('B2');
        $employee = $this->accountant([1]);

        $this->assertBranchFailsWith(BranchError::NotAllowed, fn () => $this->service->saveDraft($this->journalInput() + ['branch_id' => $second], $employee));
        $this->assertSame(0, $this->countRows('accounting_journals'));
    }

    public function testSaveDraft_RejectsAnInactiveBranch(): void
    {
        $inactive = $this->createBranch('B2', active: false);

        $this->assertBranchFailsWith(BranchError::Inactive, fn () => $this->service->saveDraft($this->journalInput() + ['branch_id' => $inactive], $this->accountant()));
    }

    public function testSaveDraft_RejectsABranchThatDoesNotExist(): void
    {
        $this->assertBranchFailsWith(BranchError::NotFound, fn () => $this->service->saveDraft($this->journalInput() + ['branch_id' => 987654], $this->accountant()));
    }

    public function testSaveDraft_RejectsEditingADraftOfAnotherBranch(): void
    {
        $second    = $this->createBranch('B2');
        $journalId = $this->service->saveDraft($this->journalInput() + ['branch_id' => $second], $this->accountant());
        $outsider  = $this->accountant([1]);

        $this->assertFailsWith(AccountingError::JournalNotFound, fn () => $this->service->saveDraft($this->journalInput('9.00'), $outsider, $journalId));
        $this->assertSame('100.0000', $this->journal($journalId)->total_debit);
    }

    public function testDeleteDraft_RejectsADraftOfAnotherBranch(): void
    {
        $second    = $this->createBranch('B2');
        $journalId = $this->service->saveDraft($this->journalInput() + ['branch_id' => $second], $this->accountant());

        $this->assertFailsWith(AccountingError::JournalNotFound, fn () => $this->service->deleteDraft($journalId, $this->accountant([1])));
        $this->assertNotNull($this->journal($journalId));
    }

    public function testPostNew_PostsInTheRequestedBranchAndAuditsItThere(): void
    {
        $second = $this->createBranch('B2');

        $journalId = $this->service->postNew($this->journalInput() + ['branch_id' => $second], $this->accountant([$second]));

        $this->assertSame($second, (int) $this->journal($journalId)->branch_id);

        $audit = $this->accountingDb()->table('accounting_audit_log')->where('entity_type', 'journal')->where('entity_id', $journalId)->get()->getRow();

        $this->assertSame($second, (int) $audit->branch_id);
    }

    public function testPostNew_RejectsABranchOutsideTheEmployeesScope(): void
    {
        $second   = $this->createBranch('B2');
        $employee = $this->accountant([$second]);

        $this->assertBranchFailsWith(BranchError::NotAllowed, fn () => $this->service->postNew($this->journalInput() + ['branch_id' => 1], $employee));
        $this->assertSame(0, $this->countRows('accounting_journals'));
    }

    public function testPostNew_NumbersManualJournalsOfEveryBranchInOneSeries(): void
    {
        $second = $this->createBranch('B2');

        $first  = $this->service->postNew($this->journalInput() + ['branch_id' => 1]);
        $other  = $this->service->postNew($this->journalInput() + ['branch_id' => $second]);
        $return = $this->service->postNew($this->journalInput() + ['branch_id' => 1]);

        $this->assertSame(['000001', '000002', '000003'], [$this->journal($first)->number, $this->journal($other)->number, $this->journal($return)->number]);
    }

    public function testPostDraft_RejectsADraftOfAnotherBranch(): void
    {
        $second    = $this->createBranch('B2');
        $journalId = $this->service->saveDraft($this->journalInput() + ['branch_id' => $second], $this->accountant());

        $this->assertFailsWith(AccountingError::JournalNotFound, fn () => $this->service->postDraft($journalId, $this->accountant([1])));
        $this->assertSame('draft', $this->journal($journalId)->status);
    }

    public function testPostDraft_MovesTheDraftToTheBranchGivenInTheInput(): void
    {
        $second    = $this->createBranch('B2');
        $employee  = $this->accountant();
        $journalId = $this->service->saveDraft($this->journalInput(), $employee);

        $this->service->postDraft($journalId, $employee, $this->journalInput() + ['branch_id' => $second]);

        $this->assertSame($second, (int) $this->journal($journalId)->branch_id);
        $this->assertSame('posted', $this->journal($journalId)->status);
    }

    public function testReverse_RejectsAJournalOfAnotherBranch(): void
    {
        $second    = $this->createBranch('B2');
        $journalId = $this->service->postNew($this->journalInput() + ['branch_id' => $second]);

        $this->assertFailsWith(AccountingError::JournalNotFound, fn () => $this->service->reverse($journalId, '2026-10-22', 'Mistake', $this->accountant([1])));
        $this->assertSame('posted', $this->journal($journalId)->status);
    }

    public function testReverse_KeepsTheBranchOfTheOriginal(): void
    {
        $second     = $this->createBranch('B2');
        $journalId  = $this->service->postNew($this->journalInput() + ['branch_id' => $second]);
        $reversalId = $this->service->reverse($journalId, '2026-10-22', 'Mistake', $this->accountant([$second]));

        $this->assertSame($second, (int) $this->journal($reversalId)->branch_id);
    }

    public function testReverse_AcceptsAJournalOfABranchDeactivatedLater(): void
    {
        $second    = $this->createBranch('B2');
        $journalId = $this->service->postNew($this->journalInput() + ['branch_id' => $second]);

        $this->accountingDb()->table('branches')->where('branch_id', $second)->update(['active' => 0]);

        $reversalId = $this->service->reverse($journalId, '2026-10-22', 'Branch closing');

        $this->assertSame($second, (int) $this->journal($reversalId)->branch_id);
    }

    public function testFind_ReturnsTheBranchOfTheJournal(): void
    {
        $second    = $this->createBranch('B2', 'Second branch');
        $journalId = $this->service->postNew($this->journalInput() + ['branch_id' => $second]);

        $journal = $this->service->find($journalId, $this->accountant([$second]));

        $this->assertSame('B2', $journal->branch_code);
        $this->assertSame('Second branch', $journal->branch_name);
    }

    public function testFind_HidesAJournalOfAnotherBranchAsNotFound(): void
    {
        $second    = $this->createBranch('B2');
        $journalId = $this->service->postNew($this->journalInput() + ['branch_id' => $second]);

        $this->assertFailsWith(AccountingError::JournalNotFound, fn () => $this->service->find($journalId, $this->accountant([1])));
        $this->assertSame($journalId, (int) $this->service->find($journalId, $this->accountant())->journal_id);
    }

    public function testSearch_ListsOnlyTheJournalsOfTheEmployeesBranches(): void
    {
        $second = $this->createBranch('B2');
        $first  = $this->service->postNew($this->journalInput() + ['branch_id' => 1]);
        $other  = $this->service->postNew($this->journalInput() + ['branch_id' => $second]);

        $this->assertSame([$other], array_map(static fn (object $row): int => (int) $row->journal_id, $this->service->search([], employeeId: $this->accountant([$second]))['rows']));
        $this->assertSame([$first], array_map(static fn (object $row): int => (int) $row->journal_id, $this->service->search([], employeeId: $this->accountant([1]))['rows']));
        $this->assertSame(2, $this->service->search([], employeeId: $this->accountant())['total']);
    }

    public function testSearch_ReturnsNothingForAnEmployeeWithoutBranches(): void
    {
        $this->service->postNew($this->journalInput());

        $this->assertSame(0, $this->service->search([], employeeId: $this->accountant([]))['total']);
    }

    public function testSearch_FiltersByBranch(): void
    {
        $second = $this->createBranch('B2');
        $this->service->postNew($this->journalInput() + ['branch_id' => 1]);
        $other = $this->service->postNew($this->journalInput() + ['branch_id' => $second]);

        $result = $this->service->search(['branch_id' => $second]);

        $this->assertSame(1, $result['total']);
        $this->assertSame($other, (int) $result['rows'][0]->journal_id);
        $this->assertSame('B2', $result['rows'][0]->branch_code);
    }

    public function testSearch_IgnoresABranchFilterOutsideTheEmployeesScope(): void
    {
        $second = $this->createBranch('B2');
        $this->service->postNew($this->journalInput() + ['branch_id' => $second]);

        $this->assertSame(0, $this->service->search(['branch_id' => $second], employeeId: $this->accountant([1]))['total']);
    }

    public function testPostableAccounts_ExcludesGroupsAndInactiveAccounts(): void
    {
        $inactive = $this->customAccount();
        (new AccountService())->deactivate($inactive);

        $ids = array_map('intval', array_column($this->service->postableAccounts(), 'account_id'));

        $this->assertContains($this->accountId('cash'), $ids);
        $this->assertNotContains($this->accountIdByCode('11'), $ids);
        $this->assertNotContains($inactive, $ids);
    }

    public function testPostNew_RejectsADateInAClosedPeriod(): void
    {
        $this->closeThrough('2026-03-31');

        $this->assertPostingFailsWith(PostingError::PeriodClosed, fn () => $this->service->postNew(['date' => '2026-03-31'] + $this->journalInput()));
        $this->assertSame(0, $this->countRows('accounting_journals'));

        $this->service->postNew(['date' => '2026-04-01'] + $this->journalInput());

        $this->assertSame(1, $this->countRows('accounting_journals'));
    }

    public function testPostNew_RejectsADateOutsideEveryFiscalYear(): void
    {
        $this->assertPostingFailsWith(PostingError::OutsideFiscalYear, fn () => $this->service->postNew(['date' => '2019-06-01'] + $this->journalInput()));
    }

    public function testPostDraft_RejectsADateInAClosedPeriodAndKeepsTheDraft(): void
    {
        $draftId = $this->service->saveDraft(['date' => '2026-03-15'] + $this->journalInput());
        $this->closeThrough('2026-03-31');

        $this->assertPostingFailsWith(PostingError::PeriodClosed, fn () => $this->service->postDraft($draftId));

        $this->assertSame('draft', $this->journal($draftId)->status);
        $this->assertNull($this->journal($draftId)->number);
    }

    public function testPostDraft_PostsOnceTheClosingIsReopened(): void
    {
        $draftId = $this->service->saveDraft(['date' => '2026-03-15'] + $this->journalInput());
        $this->closeThrough('2026-03-31');

        $this->assertPostingFailsWith(PostingError::PeriodClosed, fn () => $this->service->postDraft($draftId));

        $this->accountingDb()->table('accounting_closed_periods')->update(['active' => 0]);
        $this->service->postDraft($draftId);

        $this->assertSame('posted', $this->journal($draftId)->status);
    }

    public function testPostDraft_ChecksTheDateOfTheEditedInputToo(): void
    {
        $draftId = $this->service->saveDraft(['date' => '2026-04-15'] + $this->journalInput());
        $this->closeThrough('2026-03-31');

        $this->assertPostingFailsWith(PostingError::PeriodClosed, fn () => $this->service->postDraft($draftId, null, ['date' => '2026-03-20'] + $this->journalInput()));
    }

    public function testSaveDraft_AcceptsADateInAClosedPeriod(): void
    {
        $this->closeThrough('2026-03-31');

        $draftId = $this->service->saveDraft(['date' => '2026-03-15'] + $this->journalInput());

        $this->assertSame('draft', $this->journal($draftId)->status);
    }

    public function testReverse_RejectsAReversalDateInAClosedPeriodAndAcceptsAnOpenDate(): void
    {
        $journalId = $this->service->postNew(['date' => '2026-03-10'] + $this->journalInput());
        $this->closeThrough('2026-03-31');

        $this->assertPostingFailsWith(PostingError::PeriodClosed, fn () => $this->service->reverse($journalId, '2026-03-20', 'Mistake'));
        $this->assertSame('posted', $this->journal($journalId)->status);

        $reversalId = $this->service->reverse($journalId, '2026-04-02', 'Mistake');

        $this->assertSame('2026-04-02', $this->journal($reversalId)->journal_date);
        $this->assertSame('reversed', $this->journal($journalId)->status);
    }

    /**
     * @param list<int>|null $branchIds
     */
    private function detailsEditor(?array $branchIds = null): int
    {
        return $this->employeeWithGrants(['accounting_journals', AccountingGuard::JOURNALS_ALL, AccountingGuard::JOURNALS_EDIT], $branchIds);
    }

    private function automaticJournal(?int $branchId = null, string $date = '2026-10-21', int $sourceId = 1, string $sourceType = 'sale'): int
    {
        return (new PostingService())->post(new JournalDraft(
            date: $date,
            lines: [
                new JournalLineDraft($this->accountId('cash'), debit: '100.00', description: 'Cash received'),
                new JournalLineDraft($this->accountId('sales_revenue'), credit: '100.00', description: 'Sales'),
            ],
            sourceType: $sourceType,
            sourceId: $sourceId,
            description: 'Invoice 7',
            currencyCode: 'SAR',
            branchId: $branchId,
        ))->journalId;
    }

    /**
     * @return list<int> The ids of the lines of a journal in line order.
     */
    private function lineIds(int $journalId): array
    {
        return array_map(static fn (object $line): int => (int) $line->line_id, $this->lines($journalId));
    }

    /**
     * What the books hold of a journal apart from the text that may be edited and the time it was
     * last touched.
     *
     * @return array{journal: array<string, mixed>, lines: list<array<string, mixed>>}
     */
    private function lockedState(int $journalId): array
    {
        $journal = (array) $this->journal($journalId);
        unset($journal['description'], $journal['updated_at']);

        $lines = array_map(static function (object $line): array {
            $line = (array) $line;
            unset($line['description'], $line['cost_center_id']);

            return $line;
        }, $this->lines($journalId));

        return ['journal' => $journal, 'lines' => $lines];
    }

    private function trialBalance(): array
    {
        $report = (new TrialBalance())->build(ReportScope::resolve(null, null), ReportPeriod::of(null, '2026-12-31'));

        return ['rows' => $report->rows, 'totals' => $report->totals];
    }

    private function detailsAudit(int $journalId): array
    {
        return $this->accountingDb()->table('accounting_audit_log')->where('entity_type', 'journal')->where('entity_id', $journalId)->where('action', 'details_edited')->orderBy('audit_id')->get()->getResult();
    }

    public function testUpdateDetails_ChangesTheTextOfAPostedAutomaticJournalAndNothingElse(): void
    {
        $journalId = $this->automaticJournal();
        $center    = (new CostCenterService())->create(['code' => 'MAIN', 'name' => 'Main']);
        $before    = $this->lockedState($journalId);
        [$first, $second] = $this->lineIds($journalId);

        $changed = $this->service->updateDetails($journalId, [
            'description' => '  Invoice 7, corrected  ',
            'lines'       => [
                $first  => ['description' => 'Cash from the customer', 'cost_center_id' => (string) $center],
                $second => ['description' => 'Revenue of the shop'],
            ],
        ], $this->detailsEditor());

        $this->assertTrue($changed);
        $this->assertSame('Invoice 7, corrected', $this->journal($journalId)->description);

        $lines = $this->lines($journalId);

        $this->assertSame('Cash from the customer', $lines[0]->description);
        $this->assertSame((string) $center, (string) $lines[0]->cost_center_id);
        $this->assertSame('Revenue of the shop', $lines[1]->description);
        $this->assertNull($lines[1]->cost_center_id);
        $this->assertSame($before, $this->lockedState($journalId));
    }

    public function testUpdateDetails_LeavesTheTrialBalanceAndTheAccountsExactlyAsTheyWere(): void
    {
        $journalId = $this->automaticJournal();
        $this->automaticJournal(null, '2026-10-22', 2);
        $center  = (new CostCenterService())->create(['code' => 'MAIN', 'name' => 'Main']);
        $balance = $this->trialBalance();
        $cash    = $this->balanceOf('cash');

        $this->service->updateDetails($journalId, ['description' => 'Another text', 'lines' => [$this->lineIds($journalId)[0] => ['cost_center_id' => $center]]], $this->detailsEditor());

        $this->assertEquals($balance, $this->trialBalance());
        $this->assertSame($cash, $this->balanceOf('cash'));
        $this->assertSame('200.0000', $this->journalTotals()['debit']);
        $this->assertSame('200.0000', $this->journalTotals()['credit']);
    }

    private function balanceOf(string $systemKey): string
    {
        $row = $this->accountingDb()->table('accounting_journal_lines')->select('SUM(debit - credit) AS balance', false)->where('account_id', $this->accountId($systemKey))->get()->getRow();

        return (string) $row->balance;
    }

    /**
     * @return array{debit: string, credit: string}
     */
    private function journalTotals(): array
    {
        $row = $this->accountingDb()->table('accounting_journal_lines')->select('SUM(debit) AS debit, SUM(credit) AS credit', false)->get()->getRow();

        return ['debit' => (string) $row->debit, 'credit' => (string) $row->credit];
    }

    public function testUpdateDetails_ShowsTheNewTextInTheAccountStatementAndMovesNoBalance(): void
    {
        $journalId = $this->automaticJournal();
        [$first]   = $this->lineIds($journalId);
        $cash      = $this->accountId('cash');
        $ledger    = new AccountLedger();
        $scope     = ReportScope::resolve(null, null);
        $period    = ReportPeriod::of(null, '2026-12-31');
        $before    = $ledger->build($scope, $cash, $period);

        $this->service->updateDetails($journalId, ['description' => 'Edited header', 'lines' => [$first => ['description' => 'Edited cash line']]], $this->detailsEditor());

        $after = $ledger->build($scope, $cash, $period);

        $this->assertSame('Cash received', $before->rows[0]['description']);
        $this->assertSame('Edited cash line', $after->rows[0]['description']);
        $this->assertSame([$before->opening, $before->debit, $before->credit, $before->closing], [$after->opening, $after->debit, $after->credit, $after->closing]);
        $this->assertSame($before->rows[0]['balance'], $after->rows[0]['balance']);
        $this->assertSame($before->rows[0]['debit'], $after->rows[0]['debit']);
    }

    public function testUpdateDetails_WritesAnAuditEntryWithWhatTheTextWasAndWhatItBecame(): void
    {
        $journalId = $this->automaticJournal();
        $employee  = $this->detailsEditor();
        $center    = (new CostCenterService())->create(['code' => 'MAIN', 'name' => 'Main']);
        [$first]   = $this->lineIds($journalId);

        $this->service->updateDetails($journalId, ['description' => 'Corrected', 'lines' => [$first => ['description' => 'Cash in', 'cost_center_id' => $center]]], $employee);

        $entries = $this->detailsAudit($journalId);

        $this->assertCount(1, $entries);
        $this->assertSame((string) $employee, (string) $entries[0]->employee_id);
        $this->assertSame('user', $entries[0]->origin);
        $this->assertSame('1', (string) $entries[0]->branch_id);
        $this->assertSame([
            'before' => ['description' => 'Invoice 7', 'lines' => [1 => ['description' => 'Cash received', 'cost_center_id' => null]]],
            'after'  => ['description' => 'Corrected', 'lines' => [1 => ['description' => 'Cash in', 'cost_center_id' => $center]]],
        ], json_decode($entries[0]->details, true));
    }

    public function testUpdateDetails_WritesOneEntryForEachEditSoTheHistoryKeepsEveryVersion(): void
    {
        $journalId = $this->automaticJournal();
        $employee  = $this->detailsEditor();

        $this->service->updateDetails($journalId, ['description' => 'Second text'], $employee);
        $this->service->updateDetails($journalId, ['description' => 'Third text'], $employee);

        $entries = $this->detailsAudit($journalId);

        $this->assertCount(2, $entries);
        $this->assertSame('Invoice 7', json_decode($entries[0]->details, true)['before']['description']);
        $this->assertSame('Second text', json_decode($entries[0]->details, true)['after']['description']);
        $this->assertSame('Second text', json_decode($entries[1]->details, true)['before']['description']);
        $this->assertSame('Third text', json_decode($entries[1]->details, true)['after']['description']);
    }

    public function testUpdateDetails_WritesNothingWhenNothingChanged(): void
    {
        $journalId = $this->automaticJournal();
        [$first]   = $this->lineIds($journalId);
        $employee  = $this->detailsEditor();

        $changed = $this->service->updateDetails($journalId, ['description' => 'Invoice 7', 'lines' => [$first => ['description' => 'Cash received', 'cost_center_id' => '']]], $employee);

        $this->assertFalse($changed);
        $this->assertSame([], $this->detailsAudit($journalId));
        $this->assertFalse($this->service->updateDetails($journalId, [], $employee));
        $this->assertFalse($this->service->updateDetails($journalId, ['lines' => []], $employee));
    }

    public function testUpdateDetails_RecordsOnlyWhatChangedInTheAuditEntry(): void
    {
        $journalId = $this->automaticJournal();
        [$first, $second] = $this->lineIds($journalId);

        $this->service->updateDetails($journalId, ['description' => 'Invoice 7', 'lines' => [$first => ['description' => 'Cash received'], $second => ['description' => 'Shop revenue']]], $this->detailsEditor());

        $this->assertSame([
            'before' => ['lines' => [2 => ['description' => 'Sales']]],
            'after'  => ['lines' => [2 => ['description' => 'Shop revenue']]],
        ], json_decode($this->detailsAudit($journalId)[0]->details, true));
    }

    public function testUpdateDetails_IgnoresEverythingThatIsNotText(): void
    {
        $journalId = $this->automaticJournal();
        $before    = $this->lockedState($journalId);
        [$first, $second] = $this->lineIds($journalId);
        $other     = $this->accountId('cogs');

        $this->service->updateDetails($journalId, [
            'description'   => 'Edited',
            'date'          => '2025-01-01',
            'number'        => '999999',
            'status'        => 'draft',
            'source_type'   => 'manual',
            'source_id'     => 99,
            'branch_id'     => 7,
            'total_debit'   => '1.00',
            'currency_code' => 'USD',
            'lines'         => [
                $first  => ['description' => 'Edited line', 'account_id' => $other, 'debit' => '1.00', 'credit' => '0.00', 'party_type' => 'customer', 'party_id' => 5],
                $second => ['account_id' => $other, 'debit' => '0.00', 'credit' => '5.00'],
            ],
        ], $this->detailsEditor());

        $this->assertSame('Edited', $this->journal($journalId)->description);
        $this->assertSame('Edited line', $this->lines($journalId)[0]->description);
        $this->assertSame($before, $this->lockedState($journalId));
    }

    public function testUpdateDetails_ClearsATextSentBlankAndKeepsWhatIsNotMentioned(): void
    {
        $journalId = $this->automaticJournal();
        [$first, $second] = $this->lineIds($journalId);

        $this->service->updateDetails($journalId, ['description' => "   \n ", 'lines' => [$first => ['description' => '']]], $this->detailsEditor());

        $this->assertNull($this->journal($journalId)->description);
        $this->assertNull($this->lines($journalId)[0]->description);
        $this->assertSame('Sales', $this->lines($journalId)[1]->description);
        $this->assertSame(
            ['before' => ['description' => 'Invoice 7', 'lines' => [1 => ['description' => 'Cash received']]], 'after' => ['description' => null, 'lines' => [1 => ['description' => null]]]],
            json_decode($this->detailsAudit($journalId)[0]->details, true),
        );
    }

    public function testUpdateDetails_AcceptsTextOfExactlyTheMaximumLengthAndRejectsOneMoreCharacter(): void
    {
        $journalId = $this->automaticJournal();
        [$first]   = $this->lineIds($journalId);
        $employee  = $this->detailsEditor();

        $this->service->updateDetails($journalId, ['description' => str_repeat('ع', 2000), 'lines' => [$first => ['description' => str_repeat('ع', 255)]]], $employee);

        $this->assertSame(str_repeat('ع', 2000), $this->journal($journalId)->description);
        $this->assertSame(str_repeat('ع', 255), $this->lines($journalId)[0]->description);

        $this->assertFailsWith(AccountingError::DescriptionTooLong, fn () => $this->service->updateDetails($journalId, ['description' => str_repeat('a', 2001)], $employee));
        $this->assertFailsWith(AccountingError::DescriptionTooLong, fn () => $this->service->updateDetails($journalId, ['lines' => [$first => ['description' => str_repeat('a', 256)]]], $employee));

        $this->assertSame(str_repeat('ع', 2000), $this->journal($journalId)->description);
        $this->assertCount(1, $this->detailsAudit($journalId));
    }

    public function testUpdateDetails_RejectsAManualJournalAndTheReversalOfOneAndChangesNothing(): void
    {
        $manualId   = $this->service->postNew($this->journalInput());
        $reversalId = $this->service->reverse($manualId, '2026-10-22', 'Mistake');
        $other      = $this->service->postNew($this->journalInput('30.00', 'Second manual'));
        $employee   = $this->detailsEditor();

        $this->assertFailsWith(AccountingError::PostedJournalLocked, fn () => $this->service->updateDetails($other, ['description' => 'Edited'], $employee));
        $this->assertFailsWith(AccountingError::PostedJournalLocked, fn () => $this->service->updateDetails($reversalId, ['description' => 'Edited'], $employee));

        $this->assertSame('Second manual', $this->journal($other)->description);
        $this->assertSame('Mistake', $this->journal($reversalId)->description);
        $this->assertSame([], $this->detailsAudit($other));
        $this->assertSame([], $this->detailsAudit($reversalId));
    }

    public function testUpdateDetails_RejectsADraftAndAReversedJournal(): void
    {
        $draftId  = $this->service->saveDraft($this->journalInput());
        $reversed = $this->automaticJournal();
        $this->service->reverse($reversed, '2026-10-22', 'Undone');
        $employee = $this->detailsEditor();

        $this->assertFailsWith(AccountingError::JournalNotPosted, fn () => $this->service->updateDetails($draftId, ['description' => 'Edited'], $employee));
        $this->assertFailsWith(AccountingError::JournalNotPosted, fn () => $this->service->updateDetails($reversed, ['description' => 'Edited'], $employee));

        $this->assertSame('Invoice 7', $this->journal($reversed)->description);
        $this->assertSame('Test journal', $this->journal($draftId)->description);
    }

    public function testUpdateDetails_RejectsAnUnknownJournal(): void
    {
        $this->assertFailsWith(AccountingError::JournalNotFound, fn () => $this->service->updateDetails(999999, ['description' => 'Edited'], $this->detailsEditor()));
    }

    public function testUpdateDetails_AcceptsAnOpeningBalanceJournalBecauseTheBooksMadeIt(): void
    {
        $journalId = $this->automaticJournal(sourceType: 'opening');

        $this->assertTrue($this->service->updateDetails($journalId, ['description' => 'Opening balances of the shop'], $this->detailsEditor()));
        $this->assertSame('Opening balances of the shop', $this->journal($journalId)->description);
    }

    public function testUpdateDetails_AcceptsAJournalWrittenBeforeTheTextHadAnythingToSay(): void
    {
        $journalId = $this->automaticJournal();
        $db        = $this->accountingDb();
        $db->table('accounting_journals')->where('journal_id', $journalId)->update(['description' => null, 'origin' => 'system', 'posted_by' => null, 'created_by' => null]);
        $db->table('accounting_journal_lines')->where('journal_id', $journalId)->update(['description' => null, 'cost_center_id' => null]);
        [$first] = $this->lineIds($journalId);
        $before  = $this->lockedState($journalId);

        $this->service->updateDetails($journalId, ['description' => 'Described now', 'lines' => [$first => ['description' => 'Cash']]], $this->detailsEditor());

        $this->assertSame('Described now', $this->journal($journalId)->description);
        $this->assertSame('Cash', $this->lines($journalId)[0]->description);
        $this->assertSame($before, $this->lockedState($journalId));
        $this->assertSame(['before' => ['description' => null, 'lines' => [1 => ['description' => null]]], 'after' => ['description' => 'Described now', 'lines' => [1 => ['description' => 'Cash']]]], json_decode($this->detailsAudit($journalId)[0]->details, true));
    }

    public function testUpdateDetails_RequiresThePermissionToEditDetails(): void
    {
        $journalId = $this->automaticJournal();
        $everythingElse = $this->employeeWithGrants(['accounting_journals', AccountingGuard::JOURNALS_ALL, AccountingGuard::JOURNALS_DRAFT, AccountingGuard::JOURNALS_POST, AccountingGuard::JOURNALS_REVERSE, AccountingGuard::JOURNALS_AUDIT]);

        $this->assertFailsWith(AccountingError::PermissionDenied, fn () => $this->service->updateDetails($journalId, ['description' => 'Edited'], $everythingElse));

        $this->assertSame('Invoice 7', $this->journal($journalId)->description);
        $this->assertSame([], $this->detailsAudit($journalId));

        $this->assertTrue($this->service->updateDetails($journalId, ['description' => 'Edited by the books'], null));
        $this->assertTrue($this->service->updateDetails($journalId, ['description' => 'Edited by a holder'], $this->detailsEditor()));
    }

    public function testUpdateDetails_HidesAJournalOfAnotherBranchAsNotFoundAndAcceptsOneOfTheEmployeesOwn(): void
    {
        $second  = $this->createBranch('B2', 'Second branch');
        $journal = $this->automaticJournal();
        $own     = $this->automaticJournal($second, '2026-10-21', 2);
        $limited = $this->detailsEditor([$second]);

        $this->assertFailsWith(AccountingError::JournalNotFound, fn () => $this->service->updateDetails($journal, ['description' => 'Edited'], $limited));
        $this->assertSame('Invoice 7', $this->journal($journal)->description);
        $this->assertSame([], $this->detailsAudit($journal));

        $this->assertTrue($this->service->updateDetails($own, ['description' => 'Edited in B2'], $limited));
        $this->assertSame((string) $second, (string) $this->detailsAudit($own)[0]->branch_id);
    }

    public function testUpdateDetails_HidesAJournalFromAnEmployeeWhoMayNotSeeEveryJournal(): void
    {
        $journalId = $this->automaticJournal();
        $employee  = $this->employeeWithGrants(['accounting_journals', AccountingGuard::JOURNALS_EDIT]);

        $this->assertFailsWith(AccountingError::JournalNotFound, fn () => $this->service->updateDetails($journalId, ['description' => 'Edited'], $employee));
        $this->assertSame('Invoice 7', $this->journal($journalId)->description);
    }

    public function testUpdateDetails_RejectsAJournalInAPeriodClosedForTheCompany(): void
    {
        $journalId = $this->automaticJournal(date: '2026-03-15');
        $employee  = $this->detailsEditor();
        $this->closeThrough('2026-03-31');

        $this->assertPostingFailsWith(PostingError::PeriodClosed, fn () => $this->service->updateDetails($journalId, ['description' => 'Edited'], $employee));
        $this->assertSame('Invoice 7', $this->journal($journalId)->description);
        $this->assertSame([], $this->detailsAudit($journalId));
    }

    public function testUpdateDetails_RejectsAJournalInAPeriodClosedForItsBranchOnly(): void
    {
        $second   = $this->createBranch('B2', 'Second branch');
        $inFirst  = $this->automaticJournal(date: '2026-03-15');
        $inSecond = $this->automaticJournal($second, '2026-03-15', 2);
        $employee = $this->detailsEditor();
        $this->closeThrough('2026-03-31', $second);

        $this->assertPostingFailsWith(PostingError::PeriodClosed, fn () => $this->service->updateDetails($inSecond, ['description' => 'Edited'], $employee));
        $this->assertSame('Invoice 7', $this->journal($inSecond)->description);

        $this->assertTrue($this->service->updateDetails($inFirst, ['description' => 'Edited'], $employee));
    }

    public function testUpdateDetails_AcceptsAJournalDatedAfterTheClosingAndOneOnceTheClosingIsReopened(): void
    {
        $closed   = $this->automaticJournal(date: '2026-03-15');
        $open     = $this->automaticJournal(null, '2026-04-01', 2);
        $employee = $this->detailsEditor();
        $this->closeThrough('2026-03-31');

        $this->assertTrue($this->service->updateDetails($open, ['description' => 'After the closing'], $employee));
        $this->assertPostingFailsWith(PostingError::PeriodClosed, fn () => $this->service->updateDetails($closed, ['description' => 'Edited'], $employee));

        $this->accountingDb()->table('accounting_closed_periods')->update(['active' => 0]);

        $this->assertTrue($this->service->updateDetails($closed, ['description' => 'Reopened'], $employee));
    }

    public function testUpdateDetails_RejectsALineOfAnotherJournalAndChangesNothingOfTheRest(): void
    {
        $journalId = $this->automaticJournal();
        $other     = $this->automaticJournal(null, '2026-10-21', 2);
        [$mine]    = $this->lineIds($journalId);
        [$theirs]  = $this->lineIds($other);

        $this->assertFailsWith(AccountingError::JournalLineNotFound, fn () => $this->service->updateDetails($journalId, [
            'description' => 'Edited',
            'lines'       => [$mine => ['description' => 'Edited line'], $theirs => ['description' => 'Stolen line']],
        ], $this->detailsEditor()));

        $this->assertSame('Invoice 7', $this->journal($journalId)->description);
        $this->assertSame('Cash received', $this->lines($journalId)[0]->description);
        $this->assertSame('Cash received', $this->lines($other)[0]->description);
        $this->assertSame([], $this->detailsAudit($journalId));
        $this->assertFailsWith(AccountingError::JournalLineNotFound, fn () => $this->service->updateDetails($journalId, ['lines' => [999999 => ['description' => 'Nothing']]], $this->detailsEditor()));
    }

    public function testUpdateDetails_RejectsAnUnknownOrInactiveCostCenterAndAcceptsRemovingOne(): void
    {
        $journalId = $this->automaticJournal();
        [$first]   = $this->lineIds($journalId);
        $employee  = $this->detailsEditor();
        $active    = (new CostCenterService())->create(['code' => 'ON', 'name' => 'On']);
        $inactive  = (new CostCenterService())->create(['code' => 'OFF', 'name' => 'Off']);
        (new CostCenterService())->deactivate($inactive);

        $this->assertFailsWith(AccountingError::LineCostCenterInvalid, fn () => $this->service->updateDetails($journalId, ['lines' => [$first => ['cost_center_id' => 999999]]], $employee));
        $this->assertFailsWith(AccountingError::LineCostCenterInvalid, fn () => $this->service->updateDetails($journalId, ['description' => 'Edited', 'lines' => [$first => ['cost_center_id' => $inactive]]], $employee));
        $this->assertNull($this->lines($journalId)[0]->cost_center_id);
        $this->assertSame('Invoice 7', $this->journal($journalId)->description);

        $this->service->updateDetails($journalId, ['lines' => [$first => ['cost_center_id' => $active]]], $employee);
        $this->assertSame((string) $active, (string) $this->lines($journalId)[0]->cost_center_id);

        $this->accountingDb()->table('accounting_cost_centers')->where('cost_center_id', $active)->update(['active' => 0]);
        $this->assertFalse($this->service->updateDetails($journalId, ['lines' => [$first => ['cost_center_id' => $active]]], $employee));

        $this->service->updateDetails($journalId, ['lines' => [$first => ['cost_center_id' => '']]], $employee);
        $this->assertNull($this->lines($journalId)[0]->cost_center_id);
    }

    public function testUpdateDetails_WaitsForAClosingInProgressAndChangesNothingWhenItCannotHaveTheLock(): void
    {
        $journalId = $this->automaticJournal(date: '2026-03-15');
        $employee  = $this->detailsEditor();
        $other     = $this->openSecondConnection(false);
        $other->resetTransStatus();
        $other->transStart();
        (new PeriodLocks($other))->holdCompany();
        $this->accountingDb()->query('SET SESSION innodb_lock_wait_timeout = 1');

        try {
            $this->assertWaitsForLock(fn () => $this->service->updateDetails($journalId, ['description' => 'Edited'], $employee));
        } finally {
            $other->transRollback();
            $other->close();
            $this->accountingDb()->query('SET SESSION innodb_lock_wait_timeout = 50');
        }

        $this->assertSame('Invoice 7', $this->journal($journalId)->description);
        $this->assertSame([], $this->detailsAudit($journalId));
        $this->assertTrue($this->service->updateDetails($journalId, ['description' => 'Edited'], $employee));
    }

    public function testUpdateDetails_ChecksTheJournalAgainAfterLockingItAndRefusesOneReversedMeanwhile(): void
    {
        $journalId = $this->automaticJournal();
        $employee  = $this->detailsEditor();
        $other     = $this->openSecondConnection(false);
        $done      = false;
        $listen    = function (Query $query) use (&$done, $other, $journalId): void {
            if (! $done && str_contains($query->getQuery(), 'SELECT j.*')) {
                $done = true;
                $other->query('UPDATE ' . $other->prefixTable('accounting_journals') . " SET status = 'reversed' WHERE journal_id = ?", [$journalId]);
            }
        };

        Events::on('DBQuery', $listen);

        try {
            $this->assertFailsWith(AccountingError::JournalNotPosted, fn () => $this->service->updateDetails($journalId, ['description' => 'Edited'], $employee));
        } finally {
            Events::removeListener('DBQuery', $listen);
            $other->close();
        }

        $this->assertTrue($done);
        $this->assertSame('Invoice 7', $this->journal($journalId)->description);
        $this->assertSame([], $this->detailsAudit($journalId));
    }

    public function testUpdateDetails_SaysAManualJournalIsLockedBeforeItTalksAboutAClosedPeriod(): void
    {
        $manualId = $this->service->postNew(['date' => '2026-03-15'] + $this->journalInput());
        $this->closeThrough('2026-03-31');

        $this->assertFailsWith(AccountingError::PostedJournalLocked, fn () => $this->service->updateDetails($manualId, ['description' => 'Edited'], $this->detailsEditor()));
    }

    public function testUpdateDetails_WaitsForARequestThatHoldsTheJournalAndChangesNothingWhenItCannotHaveIt(): void
    {
        $journalId = $this->automaticJournal();
        $employee  = $this->detailsEditor();
        $other     = $this->openSecondConnection(false);
        $other->resetTransStatus();
        $other->transStart();
        $other->query('SELECT journal_id FROM ' . $other->prefixTable('accounting_journals') . ' WHERE journal_id = ? FOR UPDATE', [$journalId]);
        $this->accountingDb()->query('SET SESSION innodb_lock_wait_timeout = 1');

        try {
            $this->assertWaitsForLock(fn () => $this->service->updateDetails($journalId, ['description' => 'Edited'], $employee));
        } finally {
            $other->transRollback();
            $other->close();
            $this->accountingDb()->query('SET SESSION innodb_lock_wait_timeout = 50');
        }

        $this->assertSame('Invoice 7', $this->journal($journalId)->description);
        $this->assertSame([], $this->detailsAudit($journalId));
    }

    public function testUpdateDetails_WaitsForARequestThatHoldsTheLinesBeforeItReadsThem(): void
    {
        $journalId = $this->automaticJournal();
        $employee  = $this->detailsEditor();
        $other     = $this->openSecondConnection(false);
        $other->resetTransStatus();
        $other->transStart();
        $other->query('SELECT line_id FROM ' . $other->prefixTable('accounting_journal_lines') . ' WHERE journal_id = ? FOR UPDATE', [$journalId]);
        $this->accountingDb()->query('SET SESSION innodb_lock_wait_timeout = 1');

        try {
            $this->assertWaitsForLock(fn () => $this->service->updateDetails($journalId, ['description' => 'Edited'], $employee));
        } finally {
            $other->transRollback();
            $other->close();
            $this->accountingDb()->query('SET SESSION innodb_lock_wait_timeout = 50');
        }

        $this->assertSame('Invoice 7', $this->journal($journalId)->description);
    }

    public function testUpdateDetails_RefusesAJournalThatVanishedWhileTheRequestWasWaiting(): void
    {
        $journalId = $this->automaticJournal();
        $employee  = $this->detailsEditor();
        $other     = $this->openSecondConnection(false);
        $done      = false;
        $listen    = function (Query $query) use (&$done, $other, $journalId): void {
            if (! $done && str_contains($query->getQuery(), 'SELECT j.*')) {
                $done = true;
                $other->query('SET FOREIGN_KEY_CHECKS = 0');
                $other->query('DELETE FROM ' . $other->prefixTable('accounting_journal_lines') . ' WHERE journal_id = ?', [$journalId]);
                $other->query('DELETE FROM ' . $other->prefixTable('accounting_journals') . ' WHERE journal_id = ?', [$journalId]);
            }
        };

        Events::on('DBQuery', $listen);

        try {
            $this->assertFailsWith(AccountingError::JournalNotFound, fn () => $this->service->updateDetails($journalId, ['description' => 'Edited'], $employee));
        } finally {
            Events::removeListener('DBQuery', $listen);
            $other->close();
        }

        $this->assertTrue($done);
        $this->assertSame([], $this->detailsAudit($journalId));
    }

    public function testUpdateDetails_LeavesTheSaleFreeToBeCancelledAndTheBooksTieAfterwards(): void
    {
        $this->startSelling();
        $item   = $this->item('10.00');
        $saleId = $this->save([$this->line($item, 0, '2', '100', '10.00')], [], [$this->payment(lang('Sales.cash'), '200.00')], SALE_TYPE_POS);

        $standing = $this->standing($saleId);

        $this->assertNotSame([], $standing);

        $employee = $this->detailsEditor();
        $balance  = $this->trialBalance();

        foreach ($standing as $journal) {
            $this->service->updateDetails($journal['id'], ['description' => 'Checked by the accountant ' . $journal['id']], $employee);
        }

        $this->assertEquals($balance, $this->trialBalance());

        $this->assertTrue($this->sales->delete($saleId, false, true, $this->employeeId));

        foreach ($standing as $journal) {
            $this->assertSame('reversed', $this->journal($journal['id'])->status);
            $this->assertSame('Checked by the accountant ' . $journal['id'], $this->journal($journal['id'])->description);
        }

        $this->assertSame([], $this->standing($saleId));
        $this->assertSame('0.0000', bcadd($this->balance($this->accountId('sales_revenue')), '0', 4));
    }

    public function testCanEditDetails_AcceptsAPostedAutomaticJournalAndRefusesTheRest(): void
    {
        $automatic = $this->automaticJournal();
        $manual    = $this->service->postNew($this->journalInput());
        $reversal  = $this->service->reverse($manual, '2026-10-22', 'Mistake');
        $draft     = $this->service->saveDraft($this->journalInput());
        $undone    = $this->automaticJournal(null, '2026-10-21', 2);
        $this->service->reverse($undone, '2026-10-22', 'Undone');

        $this->assertTrue($this->service->canEditDetails($this->service->find($automatic)));
        $this->assertFalse($this->service->canEditDetails($this->service->find($manual)));
        $this->assertFalse($this->service->canEditDetails($this->service->find($reversal)));
        $this->assertFalse($this->service->canEditDetails($this->service->find($draft)));
        $this->assertFalse($this->service->canEditDetails($this->service->find($undone)));
    }

    public function testPostNew_AcceptsLinesWithoutDescriptionsWhileTheBooksDoNotAskForThem(): void
    {
        $journalId = $this->service->postNew($this->journalInput());

        $this->assertSame('posted', $this->journal($journalId)->status);
    }

    public function testPostNew_RequiresADescriptionOnEveryLineWhenTheBooksAskForIt(): void
    {
        $this->setConfigValue('accounting_line_description_required', '1');
        $input                                = $this->journalInput();
        $input['lines'][0]['description']     = 'Cash in';

        try {
            $this->service->postNew($input);
            $this->fail('A line without a description must be refused.');
        } catch (AccountingException $exception) {
            $this->assertSame(AccountingError::LineDescriptionRequired, $exception->error);
            $this->assertSame(2, $exception->context['line']);
        }

        $this->assertSame(0, $this->countRows('accounting_journals'));

        $input['lines'][1]['description'] = 'Sales';

        $this->assertSame('posted', $this->journal($this->service->postNew($input))->status);
    }

    public function testPostNew_RejectsALineDescriptionOfOnlySpacesWhenTheBooksAskForOne(): void
    {
        $this->setConfigValue('accounting_line_description_required', '1');
        $input                            = $this->journalInput();
        $input['lines'][0]['description'] = 'Cash in';
        $input['lines'][1]['description'] = " \t ";

        $this->assertFailsWith(AccountingError::LineDescriptionRequired, fn () => $this->service->postNew($input));
    }

    public function testPostDraft_RequiresADescriptionOnEveryLineWhenTheBooksAskForIt(): void
    {
        $draftId = $this->service->saveDraft($this->journalInput());
        $this->setConfigValue('accounting_line_description_required', '1');

        $this->assertFailsWith(AccountingError::LineDescriptionRequired, fn () => $this->service->postDraft($draftId));
        $this->assertSame('draft', $this->journal($draftId)->status);

        $input                            = $this->journalInput();
        $input['lines'][0]['description'] = 'Cash in';

        $this->assertFailsWith(AccountingError::LineDescriptionRequired, fn () => $this->service->postDraft($draftId, null, $input));

        $input['lines'][1]['description'] = 'Sales';
        $this->service->postDraft($draftId, null, $input);

        $this->assertSame('posted', $this->journal($draftId)->status);
    }

    public function testPostDraft_PostsADraftWithCompleteDescriptionsWhenTheBooksAskForThem(): void
    {
        $input                            = $this->journalInput();
        $input['lines'][0]['description'] = 'Cash in';
        $input['lines'][1]['description'] = 'Sales';
        $draftId                          = $this->service->saveDraft($input);
        $this->setConfigValue('accounting_line_description_required', '1');

        $this->service->postDraft($draftId);

        $this->assertSame('posted', $this->journal($draftId)->status);
    }

    public function testSaveDraft_AcceptsALineWithoutADescriptionWhenTheBooksAskForOne(): void
    {
        $this->setConfigValue('accounting_line_description_required', '1');

        $draftId = $this->service->saveDraft($this->journalInput());

        $this->assertSame('draft', $this->journal($draftId)->status);
    }

    public function testReverse_AcceptsAJournalWhoseLinesHaveNoDescriptionWhenTheBooksAskForOne(): void
    {
        $journalId = $this->service->postNew($this->journalInput());
        $this->setConfigValue('accounting_line_description_required', '1');

        $reversalId = $this->service->reverse($journalId, '2026-10-22', 'Mistake');

        $this->assertSame('posted', $this->journal($reversalId)->status);
        $this->assertSame('reversed', $this->journal($journalId)->status);
    }

    public function testDeleteDraft_TakesItsFilesOutWithItAndWritesOneAuditEntryForEach(): void
    {
        $employee = $this->employeeWithGrants(['accounting_journals', AccountingGuard::JOURNALS_ALL, AccountingGuard::JOURNALS_DRAFT]);
        $draftId  = $this->service->saveDraft($this->journalInput(), $employee);
        $attach   = new JournalAttachments();
        $first    = $attach->add($draftId, $this->upload($this->pdfContent()), 'first.pdf', $employee);
        $second   = $attach->add($draftId, $this->upload($this->pdfContent()), 'second.pdf', $employee);
        $paths    = array_map(fn (int $id): string => $attach->open($id, $employee)['path'], [$first, $second]);

        $this->service->deleteDraft($draftId, $employee);

        $this->assertNull($this->journal($draftId));
        $this->assertSame(0, $this->countRows('accounting_journal_attachments'));
        $this->assertFileDoesNotExist($paths[0]);
        $this->assertFileDoesNotExist($paths[1]);

        $audit = $this->accountingDb()->table('accounting_audit_log')->where('entity_id', $draftId)->where('action', 'attachment_deleted')->get()->getResult();

        $this->assertCount(2, $audit);
        $this->assertTrue(json_decode($audit[0]->details, true)['with_draft']);
        $this->assertSame(['attachments' => 2], json_decode($this->accountingDb()->table('accounting_audit_log')->where('entity_id', $draftId)->where('action', 'draft_deleted')->get()->getRow()->details, true));
    }

    public function testDeleteDraft_KeepsTheFilesOfADraftItCannotDelete(): void
    {
        $owner    = $this->employeeWithGrants(['accounting_journals', AccountingGuard::JOURNALS_DRAFT]);
        $stranger = $this->employeeWithGrants(['accounting_journals', AccountingGuard::JOURNALS_DRAFT]);
        $draftId  = $this->service->saveDraft($this->journalInput(), $owner);
        $attach   = new JournalAttachments();
        $fileId   = $attach->add($draftId, $this->upload($this->pdfContent()), 'first.pdf', $owner);
        $path     = $attach->open($fileId, $owner)['path'];

        $this->assertFailsWith(AccountingError::JournalNotFound, fn () => $this->service->deleteDraft($draftId, $stranger));

        $this->assertNotNull($this->journal($draftId));
        $this->assertSame(1, $this->countRows('accounting_journal_attachments'));
        $this->assertFileExists($path);
    }
}
