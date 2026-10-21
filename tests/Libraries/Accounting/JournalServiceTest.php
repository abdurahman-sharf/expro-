<?php

namespace Tests\Libraries\Accounting;

use App\Libraries\Accounting\AccountingError;
use App\Libraries\Accounting\AccountingException;
use App\Libraries\Accounting\AccountingGuard;
use App\Libraries\Accounting\AccountService;
use App\Libraries\Accounting\CostCenterService;
use App\Libraries\Accounting\JournalService;
use App\Libraries\Accounting\PostingError;
use App\Libraries\Accounting\PostingException;
use App\Libraries\Organization\BranchError;
use App\Libraries\Organization\BranchException;
use App\Libraries\Organization\BranchGuard;
use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\DatabaseTestTrait;
use Config\Database;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Support\AccountingFixtureTrait;

class JournalServiceTest extends CIUnitTestCase
{
    use DatabaseTestTrait;
    use AccountingFixtureTrait;

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
        $this->service = new JournalService();
    }

    protected function tearDown(): void
    {
        $this->releaseOpenTransactions();

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
        $this->assertSame('1101', $journal->lines[0]->account_code);
        $this->assertSame('MAIN', $journal->lines[0]->cost_center_code);
        $this->assertNull($journal->lines[1]->cost_center_code);
        $this->assertSame('4101', $journal->lines[1]->account_code);
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
                ['account_id' => $this->accountId('inventory'), 'debit' => '5.00'],
                ['account_id' => $this->accountId('payables'), 'credit' => '5.00'],
            ],
        ]);

        $ids = static fn (array $result): array => array_map(static fn (object $row): int => (int) $row->journal_id, $result['rows']);

        $this->assertSame([$withCash], $ids($this->service->search(['account_id' => $this->accountId('cash')])));
        $this->assertEqualsCanonicalizing([$withCash, $withStock], $ids($this->service->search(['account_id' => $this->accountIdByCode('11')])));
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
}
