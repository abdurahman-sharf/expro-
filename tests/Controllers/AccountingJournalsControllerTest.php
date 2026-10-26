<?php

namespace Tests\Controllers;

use App\Libraries\Accounting\AccountingGuard;
use App\Libraries\Accounting\AuditLog;
use App\Libraries\Accounting\CostCenterService;
use App\Libraries\Accounting\JournalOrigin;
use App\Libraries\Accounting\JournalService;
use App\Libraries\Accounting\PostingService;
use App\Libraries\Accounting\TreasuryService;
use CodeIgniter\Exceptions\PageNotFoundException;
use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\DatabaseTestTrait;
use Tests\Support\AccountingControllerFixtureTrait;

class AccountingJournalsControllerTest extends CIUnitTestCase
{
    use DatabaseTestTrait;
    use AccountingControllerFixtureTrait;

    protected $migrate     = true;
    protected $migrateOnce = true;
    protected $refresh     = false;
    protected $namespace   = null;

    protected function setUp(): void
    {
        parent::setUp();

        $this->prepareAccounting();
    }

    protected function tearDown(): void
    {
        $this->releaseOpenTransactions();

        parent::tearDown();
    }

    private function accountant(): int
    {
        $personId = $this->employeeWithGrants([
            'accounting_journals',
            AccountingGuard::JOURNALS_ALL,
            AccountingGuard::JOURNALS_DRAFT,
            AccountingGuard::JOURNALS_POST,
            AccountingGuard::JOURNALS_REVERSE,
            AccountingGuard::JOURNALS_AUDIT,
        ]);
        $this->actAs($personId);

        return $personId;
    }

    /**
     * @return array<string, mixed>
     */
    private function formInput(string $debit = '100.00', string $credit = '100.00'): array
    {
        return [
            'date'        => '2026-10-21',
            'description' => 'Test journal',
            'lines'       => [
                ['account_id' => (string) $this->accountId('cash'), 'description' => '', 'cost_center_id' => '', 'debit' => $debit, 'credit' => ''],
                ['account_id' => (string) $this->accountId('sales_revenue'), 'description' => '', 'cost_center_id' => '', 'debit' => '', 'credit' => $credit],
            ],
        ];
    }

    public function testGetIndex_ShowsTheListToAUserWithTheModuleGrant(): void
    {
        $this->actAs($this->employeeWithGrants(['accounting_journals']));

        $response = $this->get('/accounting_journals');

        $response->assertStatus(200);
        $response->assertSee('id="journal_filters"');
        $response->assertDontSee('accounting_journals/new');
        $response->assertDontSee('accounting_journals/audit');
    }

    public function testGetIndex_OffersTheNewJournalAndAuditLinksWithThePermissions(): void
    {
        $this->accountant();

        $response = $this->get('/accounting_journals');

        $response->assertSee('accounting_journals/new');
        $response->assertSee('accounting_journals/audit');
    }

    public function testGetIndex_IsNotFoundWhileAccountingIsDisabled(): void
    {
        $this->prepareAccounting(false);
        $this->actAs($this->employeeWithGrants(['accounting_journals']));

        $this->expectException(PageNotFoundException::class);

        $this->get('/accounting_journals');
    }

    public function testGetIndex_RedirectsAUserWithoutTheModuleGrant(): void
    {
        $this->actAs($this->employeeWithGrants(['accounting_chart']));

        $this->get('/accounting_journals')->assertRedirect();
    }

    public function testGetNew_ShowsTheFormWithTwoEmptyLines(): void
    {
        $this->accountant();

        $response = $this->get('/accounting_journals/new');

        $response->assertStatus(200);
        $response->assertSee('id="journal_form"');
        $response->assertSee('lines[0][account_id]');
        $response->assertSee('lines[1][account_id]');
        $response->assertSee('SAR');
    }

    public function testGetNew_IsNotFoundWithoutTheDraftPermission(): void
    {
        $this->actAs($this->employeeWithGrants(['accounting_journals']));

        $this->expectException(PageNotFoundException::class);

        $this->get('/accounting_journals/new');
    }

    public function testGetNew_HidesThePostButtonsWithoutThePostPermission(): void
    {
        $this->actAs($this->employeeWithGrants($this->journalPermissions()));

        $response = $this->get('/accounting_journals/new');

        $response->assertSee('id="save_draft"');
        $response->assertDontSee('id="post_journal"');
    }

    public function testPostPost_PostsABalancedJournalAndAssignsAnAutomaticNumber(): void
    {
        $this->accountant();

        $result = $this->jsonOf($this->post('/accounting_journals/post', $this->formInput()));

        $this->assertTrue($result['success']);
        $journal = (new JournalService())->find($result['id']);
        $this->assertSame('posted', $journal->status);
        $this->assertSame('000001', $journal->number);
        $this->assertStringContainsString('accounting_journals/view/' . $result['id'], $result['redirect']);
    }

    public function testPostPost_RejectsAnUnbalancedJournalWithAReadableMessage(): void
    {
        $this->accountant();

        $result = $this->jsonOf($this->post('/accounting_journals/post', $this->formInput('100.00', '90.00')));

        $this->assertFalse($result['success']);
        $this->assertStringContainsString('100', $result['message']);
        $this->assertStringContainsString('90', $result['message']);
        $this->assertSame(0, $this->countRows('accounting_journals'));
    }

    public function testPostPost_SaysWhichTreasuryTheEmployeeMayNotDepositIntoAndPostsNothing(): void
    {
        $treasuries = new TreasuryService();
        $drawer     = $treasuries->create(['name' => 'Closed drawer', 'kind' => 'cash', 'deposit_scope' => 'staff', 'deposit_members' => [$this->employeeWithGrants([])]]);
        $input      = $this->formInput();

        $input['lines'][0]['account_id'] = (string) $treasuries->accountOf($drawer);

        $this->accountant();

        $result = $this->jsonOf($this->post('/accounting_journals/post', $input));

        $this->assertFalse($result['success']);
        $this->assertStringContainsString('Closed drawer', $result['message']);
        $this->assertSame(0, $this->countRows('accounting_journals'));
    }

    public function testPostPost_RejectsALineWithoutAnAccount(): void
    {
        $this->accountant();
        $input = $this->formInput();

        $input['lines'][1]['account_id'] = '';

        $result = $this->jsonOf($this->post('/accounting_journals/post', $input));

        $this->assertFalse($result['success']);
        $this->assertStringContainsString('2', $result['message']);
    }

    public function testPostPost_RejectsAUserWithoutThePostPermission(): void
    {
        $this->actAs($this->employeeWithGrants($this->journalPermissions()));

        $result = $this->jsonOf($this->post('/accounting_journals/post', $this->formInput()));

        $this->assertFalse($result['success']);
        $this->assertSame(0, $this->countRows('accounting_journals'));
    }

    public function testPostPost_PostsAnExistingDraftWithTheEditedLines(): void
    {
        $this->accountant();
        $draft = $this->jsonOf($this->post('/accounting_journals/save_draft', $this->formInput('100.00', '90.00')));

        $result = $this->jsonOf($this->post('/accounting_journals/post/' . $draft['id'], $this->formInput('90.00', '90.00')));

        $this->assertTrue($result['success']);
        $this->assertSame($draft['id'], $result['id']);
        $this->assertSame('posted', (new JournalService())->find($draft['id'])->status);
    }

    public function testPostSaveDraft_StoresAnUnbalancedDraftWithoutANumber(): void
    {
        $this->accountant();

        $result = $this->jsonOf($this->post('/accounting_journals/save_draft', $this->formInput('100.00', '90.00')));

        $this->assertTrue($result['success']);
        $journal = (new JournalService())->find($result['id']);
        $this->assertSame('draft', $journal->status);
        $this->assertNull($journal->number);
        $this->assertStringContainsString('accounting_journals/edit/' . $result['id'], $result['redirect']);
    }

    public function testPostSaveDraft_KeepsTheDescriptionAndLinesOfAnEditedDraft(): void
    {
        $this->accountant();
        $draft = $this->jsonOf($this->post('/accounting_journals/save_draft', $this->formInput()));
        $input = $this->formInput('70.00', '70.00');

        $input['description'] = 'Edited description';
        $this->post('/accounting_journals/save_draft/' . $draft['id'], $input);

        $journal = (new JournalService())->find($draft['id']);
        $this->assertSame('Edited description', $journal->description);
        $this->assertSame('70.0000', $journal->lines[0]->debit);
    }

    public function testGetEdit_ShowsAnExistingDraftWithItsLines(): void
    {
        $this->accountant();
        $draft = $this->jsonOf($this->post('/accounting_journals/save_draft', $this->formInput('55.50', '55.50')));

        $response = $this->get('/accounting_journals/edit/' . $draft['id']);

        $response->assertStatus(200);
        $response->assertSee('value="55.5"');
        $response->assertSee('id="delete_draft"');
    }

    public function testGetEdit_IsNotFoundForAPostedJournal(): void
    {
        $this->accountant();
        $journalId = $this->postedJournalBy();

        $this->expectException(PageNotFoundException::class);

        $this->get('/accounting_journals/edit/' . $journalId);
    }

    public function testGetView_ShowsAPostedJournalWithTheReverseButton(): void
    {
        $this->accountant();
        $journalId = $this->postedJournalBy();

        $response = $this->get('/accounting_journals/view/' . $journalId);

        $response->assertStatus(200);
        $response->assertSee('accounting_journals/reverse_form/' . $journalId);
        $response->assertDontSee('id="post_draft"');
    }

    public function testGetView_HidesTheReverseButtonWithoutThePermission(): void
    {
        $this->actAs($this->employeeWithGrants(['accounting_journals', AccountingGuard::JOURNALS_ALL]));
        $journalId = $this->postedJournalBy();

        $response = $this->get('/accounting_journals/view/' . $journalId);

        $response->assertDontSee('reverse_form');
    }

    public function testGetView_IsNotFoundForSomeoneElsesJournalWithoutTheAllPermission(): void
    {
        $other     = $this->accountant();
        $journalId = $this->postedJournalBy($other);
        $this->actAs($this->employeeWithGrants(['accounting_journals']));

        $this->expectException(PageNotFoundException::class);

        $this->get('/accounting_journals/view/' . $journalId);
    }

    public function testGetView_ShowsTheAuditHistoryOnlyWithTheAuditPermission(): void
    {
        $accountant = $this->accountant();
        $journalId  = $this->postedJournalBy($accountant);

        $withAudit = $this->get('/accounting_journals/view/' . $journalId);

        $this->actAs($this->employeeWithGrants(['accounting_journals', AccountingGuard::JOURNALS_ALL]));
        $withoutAudit = $this->get('/accounting_journals/view/' . $journalId);

        $withAudit->assertSee('acc-history');
        $withoutAudit->assertDontSee('acc-history');
    }

    public function testGetView_EscapesTheDescription(): void
    {
        $this->accountant();
        $input                = $this->journalInput();
        $input['description'] = '<script>alert(1)</script>';
        $journalId            = (new JournalService())->postNew($input);

        $response = $this->get('/accounting_journals/view/' . $journalId);

        $response->assertDontSee('<script>alert(1)</script>');
        $response->assertSee('&lt;script&gt;');
    }

    public function testGetSearch_ListsOwnJournalsOnlyWithoutTheAllPermission(): void
    {
        $owner = $this->employeeWithGrants($this->journalPermissions(AccountingGuard::JOURNALS_POST));
        $other = $this->accountant();
        $this->postedJournalBy($other);
        $ownId = $this->postedJournalBy($owner, '250.00');
        $this->actAs($owner);

        $result = $this->jsonOf($this->get('/accounting_journals/search'));

        $this->assertSame([$ownId], array_column($result['rows'], 'journal_id'));
    }

    public function testGetSearch_ListsEveryJournalWithTheAllPermission(): void
    {
        $other = $this->employeeWithGrants($this->journalPermissions(AccountingGuard::JOURNALS_POST));
        $this->postedJournalBy($other);
        $this->postedJournalBy(null, '250.00');
        $this->accountant();

        $result = $this->jsonOf($this->get('/accounting_journals/search'));

        $this->assertSame(2, $result['total']);
    }

    public function testGetSearch_FiltersByStatusAndAmountAndAccount(): void
    {
        $this->accountant();
        $postedId = $this->postedJournalBy(null, '250.00');
        $draftId  = (new JournalService())->saveDraft($this->journalInput('80.00'));
        $cashId   = $this->accountId('cash');

        $drafts = $this->jsonOf($this->get('/accounting_journals/search?status[]=draft'));
        $big    = $this->jsonOf($this->get('/accounting_journals/search?amount_from=200'));
        $byAcc  = $this->jsonOf($this->get('/accounting_journals/search?account_id=' . $cashId));

        $this->assertSame([$draftId], array_column($drafts['rows'], 'journal_id'));
        $this->assertSame([$postedId], array_column($big['rows'], 'journal_id'));
        $this->assertSame(2, $byAcc['total']);
    }

    public function testGetSearch_OffersEditOnlyOnDrafts(): void
    {
        $this->accountant();
        $postedId = $this->postedJournalBy();
        $draftId  = (new JournalService())->saveDraft($this->journalInput());

        $rows = [];

        foreach ($this->jsonOf($this->get('/accounting_journals/search'))['rows'] as $row) {
            $rows[$row['journal_id']] = $row['edit'];
        }

        $this->assertStringContainsString('accounting_journals/edit/' . $draftId, $rows[$draftId]);
        $this->assertStringNotContainsString('accounting_journals/edit/', $rows[$postedId]);
    }

    public function testGetSearch_FormatsAmountsInLeftToRightIsolates(): void
    {
        $this->accountant();
        $this->postedJournalBy(null, '1234.50');

        $row = $this->jsonOf($this->get('/accounting_journals/search'))['rows'][0];

        $this->assertStringContainsString('<bdi dir="ltr">', $row['total_debit']);
        $this->assertStringContainsString('1,234.50', $row['total_debit']);
    }

    public function testGetSearch_PrefixesTheNumberOfAnAutomaticJournal(): void
    {
        $this->accountant();
        $manualId = $this->postedJournalBy();
        $autoId   = (new PostingService())->post($this->balancedDraft('sale', 1))->journalId;

        $rows = [];

        foreach ($this->jsonOf($this->get('/accounting_journals/search'))['rows'] as $row) {
            $rows[$row['journal_id']] = $row['number'];
        }

        $this->assertSame('000001', $rows[$manualId]);
        $this->assertSame('A-' . sprintf('%06d', $autoId), $rows[$autoId]);
    }

    public function testPostReverse_NumbersTheReversalAfterTheManualJournals(): void
    {
        $this->accountant();
        $journalId = $this->postedJournalBy();

        $result  = $this->jsonOf($this->post('/accounting_journals/reverse/' . $journalId, ['date' => '2026-10-22', 'reason' => 'Wrong amount']));
        $journal = (new JournalService())->find($result['id']);

        $this->assertSame('000002', $journal->number);
        $this->assertStringContainsString('000002', $result['message']);
    }

    public function testPostReverse_ReversesAPostedJournalWithAReason(): void
    {
        $this->accountant();
        $journalId = $this->postedJournalBy();

        $result = $this->jsonOf($this->post('/accounting_journals/reverse/' . $journalId, ['date' => '2026-10-22', 'reason' => 'Wrong amount']));

        $this->assertTrue($result['success']);
        $service = new JournalService();
        $this->assertSame('reversed', $service->find($journalId)->status);
        $this->assertSame($journalId, (int) $service->find($result['id'])->reverses_journal_id);
    }

    public function testPostReverse_RequiresAReason(): void
    {
        $this->accountant();
        $journalId = $this->postedJournalBy();

        $result = $this->jsonOf($this->post('/accounting_journals/reverse/' . $journalId, ['date' => '2026-10-22', 'reason' => '  ']));

        $this->assertFalse($result['success']);
        $this->assertSame('posted', (new JournalService())->find($journalId)->status);
    }

    public function testPostReverse_RejectsAUserWithoutTheReversePermission(): void
    {
        $this->actAs($this->employeeWithGrants(['accounting_journals', AccountingGuard::JOURNALS_ALL]));
        $journalId = $this->postedJournalBy();

        $result = $this->jsonOf($this->post('/accounting_journals/reverse/' . $journalId, ['date' => '2026-10-22', 'reason' => 'Wrong amount']));

        $this->assertFalse($result['success']);
        $this->assertSame('posted', (new JournalService())->find($journalId)->status);
    }

    public function testGetReverseForm_ShowsTheDateAndReasonFields(): void
    {
        $this->accountant();
        $journalId = $this->postedJournalBy();

        $response = $this->get('/accounting_journals/reverse_form/' . $journalId);

        $response->assertSee('name="date"');
        $response->assertSee('name="reason"');
    }

    public function testPostPostDrafts_PostsBalancedDraftsAndReportsTheOthers(): void
    {
        $this->accountant();
        $service  = new JournalService();
        $good     = $service->saveDraft($this->journalInput());
        $bad      = $service->saveDraft($this->journalInput());
        $input    = $this->journalInput();

        $input['lines'][1]['credit'] = '90.00';
        $service->saveDraft($input, null, $bad);

        $result = $this->jsonOf($this->post('/accounting_journals/post_drafts', ['ids' => [(string) $good, (string) $bad]]));

        $this->assertFalse($result['success']);
        $this->assertStringContainsString('#' . $bad, $result['message']);
        $this->assertSame('posted', $service->find($good)->status);
        $this->assertSame('draft', $service->find($bad)->status);
    }

    public function testPostDeleteDrafts_DeletesDraftsAndKeepsPostedJournals(): void
    {
        $this->accountant();
        $draftId  = (new JournalService())->saveDraft($this->journalInput());
        $postedId = $this->postedJournalBy();

        $result = $this->jsonOf($this->post('/accounting_journals/delete_drafts', ['ids' => [(string) $draftId, (string) $postedId]]));

        $this->assertFalse($result['success']);
        $this->assertSame(0, $this->accountingDb()->table('accounting_journals')->where('journal_id', $draftId)->countAllResults());
        $this->assertSame(1, $this->accountingDb()->table('accounting_journals')->where('journal_id', $postedId)->countAllResults());
    }

    public function testPostDeleteDraft_DeletesOneDraft(): void
    {
        $this->accountant();
        $draftId = (new JournalService())->saveDraft($this->journalInput());

        $result = $this->jsonOf($this->post('/accounting_journals/delete_draft/' . $draftId));

        $this->assertTrue($result['success']);
        $this->assertSame(0, $this->countRows('accounting_journals'));
    }

    public function testGetAudit_IsNotFoundWithoutTheAuditPermission(): void
    {
        $this->actAs($this->employeeWithGrants(['accounting_journals', AccountingGuard::JOURNALS_ALL]));

        $this->expectException(PageNotFoundException::class);

        $this->get('/accounting_journals/audit');
    }

    public function testGetAudit_ShowsTheFiltersToAUserWithTheAuditPermission(): void
    {
        $this->accountant();
        $this->postedJournalBy();

        $response = $this->get('/accounting_journals/audit');

        $response->assertStatus(200);
        $response->assertSee('id="audit_filters"');
    }

    public function testGetAuditSearch_ListsEntriesNewestFirstWithReadableLabels(): void
    {
        $accountant = $this->accountant();
        (new AuditLog())->write('journal', 1, 'posted', $accountant, JournalOrigin::User, ['total' => '10.0000']);
        (new AuditLog())->write('account', 2, 'created', $accountant, JournalOrigin::User);

        $result = $this->jsonOf($this->get('/accounting_journals/audit/search'));

        $this->assertSame(2, $result['total']);
        $this->assertSame('Created', $result['rows'][0]['action']);
        $this->assertStringContainsString('total: 10.0000', $result['rows'][1]['details']);
    }

    public function testGetAuditSearch_FiltersByAction(): void
    {
        $accountant = $this->accountant();
        (new AuditLog())->write('journal', 1, 'posted', $accountant, JournalOrigin::User);
        (new AuditLog())->write('account', 2, 'created', $accountant, JournalOrigin::User);

        $result = $this->jsonOf($this->get('/accounting_journals/audit/search?action=posted'));

        $this->assertSame(1, $result['total']);
    }

    public function testGetAuditSearch_IsNotFoundWithoutTheAuditPermission(): void
    {
        $this->actAs($this->employeeWithGrants(['accounting_journals', AccountingGuard::JOURNALS_ALL]));

        $this->expectException(PageNotFoundException::class);

        $this->get('/accounting_journals/audit/search');
    }

    private function recordFailure(string $source = 'sale', int $sourceId = 7, string $code = 'period_closed', ?int $branchId = 1): void
    {
        $this->accountingDb()->table('accounting_posting_failures')->insert([
            'source_type' => $source,
            'source_id'   => $sourceId,
            'revision'    => 1,
            'error_code'  => $code,
            'message'     => 'Stored message for ' . $code,
            'employee_id' => null,
            'branch_id'   => $branchId,
        ]);
    }

    public function testGetFailures_IsNotFoundWithoutTheAuditPermission(): void
    {
        $this->actAs($this->employeeWithGrants(['accounting_journals', AccountingGuard::JOURNALS_ALL]));

        $this->expectException(PageNotFoundException::class);

        $this->get('/accounting_journals/failures');
    }

    public function testGetFailures_ShowsTheFiltersAndTheTabsToAUserWithTheAuditPermission(): void
    {
        $this->accountant();
        $this->recordFailure();

        $response = $this->get('/accounting_journals/failures');

        $response->assertStatus(200);
        $response->assertSee('id="failure_filters"');
        $response->assertSee(lang('Accounting_journals.audit_tab_log'));
        $response->assertSee(lang('Accounting_journals.audit_tab_failures'));
    }

    public function testGetAudit_LinksToTheFailureLogTab(): void
    {
        $this->accountant();

        $this->get('/accounting_journals/audit')->assertSee('href="' . base_url('accounting_journals/failures') . '"');
    }

    public function testGetFailuresSearch_ListsFailuresWithTranslatedReasonsAndTheRolledBackOutcome(): void
    {
        $this->accountant();
        $this->recordFailure('sale', 7, 'period_closed');

        $result = $this->jsonOf($this->get('/accounting_journals/failures/search'));

        $this->assertSame(1, $result['total']);
        $this->assertSame(lang('Accounting.posting_period_closed'), $result['rows'][0]['reason']);
        $this->assertSame(lang('Accounting.source_sale') . ' #7', $result['rows'][0]['source']);
        $this->assertSame(lang('Accounting_journals.failure_rolled_back'), $result['rows'][0]['outcome']);
    }

    public function testGetFailuresSearch_FallsBackToTheStoredMessageForACodeWithoutATranslation(): void
    {
        $this->accountant();
        $this->recordFailure('sale', 8, 'payment_type_unrouted');

        $result = $this->jsonOf($this->get('/accounting_journals/failures/search'));

        $this->assertSame('Stored message for payment_type_unrouted', $result['rows'][0]['reason']);
    }

    public function testGetFailuresSearch_FiltersBySource(): void
    {
        $this->accountant();
        $this->recordFailure('sale', 1);
        $this->recordFailure('receiving', 2);

        $result = $this->jsonOf($this->get('/accounting_journals/failures/search?source_type=receiving'));

        $this->assertSame(1, $result['total']);
    }

    public function testGetFailuresSearch_ShowsAReaderLimitedToABranchOnlyItsFailures(): void
    {
        $other = $this->createBranch('B2');
        $this->recordFailure('sale', 1, 'period_closed', 1);
        $this->recordFailure('sale', 2, 'period_closed', $other);
        $personId = $this->employeeWithGrants(['accounting_journals', AccountingGuard::JOURNALS_AUDIT], [$other]);
        $this->actAs($personId);

        $result = $this->jsonOf($this->get('/accounting_journals/failures/search'));

        $this->assertSame(1, $result['total']);
        $this->assertSame('B2', $result['rows'][0]['branch']);
    }

    public function testGetFailuresSearch_IsNotFoundWithoutTheAuditPermission(): void
    {
        $this->actAs($this->employeeWithGrants(['accounting_journals', AccountingGuard::JOURNALS_ALL]));

        $this->expectException(PageNotFoundException::class);

        $this->get('/accounting_journals/failures/search');
    }

    public function testGetNew_OffersOnlyActiveCostCentersOnTheLines(): void
    {
        $this->accountant();
        $service = new CostCenterService();
        $service->create(['code' => '1', 'name' => 'Head office']);
        $inactive = $service->create(['code' => '2', 'name' => 'Closed branch']);
        $service->deactivate($inactive);

        $response = $this->get('/accounting_journals/new');

        $response->assertSee('1 - Head office');
        $response->assertDontSee('2 - Closed branch');
    }

    /**
     * @param list<int> $branchIds
     */
    private function scopedAccountant(array $branchIds): int
    {
        $personId = $this->employeeWithGrants([
            'accounting_journals',
            AccountingGuard::JOURNALS_ALL,
            AccountingGuard::JOURNALS_DRAFT,
            AccountingGuard::JOURNALS_POST,
            AccountingGuard::JOURNALS_REVERSE,
            AccountingGuard::JOURNALS_AUDIT,
        ], $branchIds);
        $this->actAs($personId);

        return $personId;
    }

    private function journalIn(int $branchId, string $amount = '100.00'): int
    {
        return (new JournalService())->postNew($this->journalInput($amount) + ['branch_id' => $branchId]);
    }

    public function testGetIndex_ShowsTheBranchColumnAndFilterOnlyWhenSeveralBranchesAreUsable(): void
    {
        $this->accountant();

        $single = $this->get('/accounting_journals');

        $single->assertDontSee('id="f_branch"');
        $single->assertDontSee('branch_code');

        $this->createBranch('B2');

        $several = $this->get('/accounting_journals');

        $several->assertSee('id="f_branch"');
        $several->assertSee('branch_code');
    }

    public function testGetIndex_HidesTheBranchControlsFromSomeoneLimitedToOneBranch(): void
    {
        $second = $this->createBranch('B2');
        $this->scopedAccountant([$second]);

        $response = $this->get('/accounting_journals');

        $response->assertDontSee('id="f_branch"');
        $response->assertDontSee('branch_code');
    }

    public function testGetSearch_ListsOnlyTheJournalsOfTheEmployeesBranches(): void
    {
        $second = $this->createBranch('B2');
        $first  = $this->journalIn(1);
        $other  = $this->journalIn($second);
        $this->scopedAccountant([$second]);

        $rows = $this->jsonOf($this->get('/accounting_journals/search'))['rows'];

        $this->assertSame([$other], array_column($rows, 'journal_id'));
        $this->assertNotContains($first, array_column($rows, 'journal_id'));
    }

    public function testGetSearch_FiltersByBranch(): void
    {
        $second = $this->createBranch('B2');
        $this->journalIn(1);
        $other = $this->journalIn($second);
        $this->accountant();

        $result = $this->jsonOf($this->get('/accounting_journals/search?branch_id=' . $second));

        $this->assertSame([$other], array_column($result['rows'], 'journal_id'));
        $this->assertSame('B2', $result['rows'][0]['branch_code']);
    }

    public function testGetView_IsNotFoundForAJournalOfAnotherBranch(): void
    {
        $second    = $this->createBranch('B2');
        $journalId = $this->journalIn($second);
        $this->scopedAccountant([1]);

        $this->expectException(PageNotFoundException::class);

        $this->get('/accounting_journals/view/' . $journalId);
    }

    public function testGetView_ShowsTheBranchToSomeoneWhoCanUseSeveral(): void
    {
        $second    = $this->createBranch('B2', 'Second branch');
        $journalId = $this->journalIn($second);
        $this->accountant();

        $this->get('/accounting_journals/view/' . $journalId)->assertSee('Second branch');
    }

    public function testGetEdit_IsNotFoundForADraftOfAnotherBranch(): void
    {
        $second  = $this->createBranch('B2');
        $draftId = (new JournalService())->saveDraft($this->journalInput() + ['branch_id' => $second]);
        $this->scopedAccountant([1]);

        $this->expectException(PageNotFoundException::class);

        $this->get('/accounting_journals/edit/' . $draftId);
    }

    public function testGetNew_OffersTheBranchesTheEmployeeMayPostIn(): void
    {
        $second = $this->createBranch('B2', 'Second branch');
        $this->createBranch('B3', 'Third branch');
        $this->scopedAccountant([1, $second]);

        $response = $this->get('/accounting_journals/new');

        $response->assertSee('B2 - Second branch');
        $response->assertDontSee('B3 - Third branch');
    }

    public function testPostPost_RejectsABranchOutsideTheEmployeesScope(): void
    {
        $second = $this->createBranch('B2');
        $this->scopedAccountant([1]);

        $result = $this->jsonOf($this->post('/accounting_journals/post', $this->formInput() + ['branch_id' => (string) $second]));

        $this->assertFalse($result['success']);
        $this->assertSame(0, $this->countRows('accounting_journals'));
    }

    public function testPostSaveDraft_StoresTheChosenBranch(): void
    {
        $second = $this->createBranch('B2');
        $this->accountant();

        $result = $this->jsonOf($this->post('/accounting_journals/save_draft', $this->formInput() + ['branch_id' => (string) $second]));

        $this->assertTrue($result['success']);
        $this->assertSame($second, (int) $this->accountingDb()->table('accounting_journals')->where('journal_id', $result['id'])->get()->getRow()->branch_id);
    }

    public function testPostReverse_RefusesAJournalOfAnotherBranch(): void
    {
        $second    = $this->createBranch('B2');
        $journalId = $this->journalIn($second);
        $this->scopedAccountant([1]);

        $result = $this->jsonOf($this->post('/accounting_journals/reverse/' . $journalId, ['date' => '2026-10-22', 'reason' => 'Mistake']));

        $this->assertFalse($result['success']);
        $this->assertSame('posted', $this->accountingDb()->table('accounting_journals')->where('journal_id', $journalId)->get()->getRow()->status);
    }

    public function testPostPostDrafts_ReportsADraftOfAnotherBranchAsMissing(): void
    {
        $second  = $this->createBranch('B2');
        $draftId = (new JournalService())->saveDraft($this->journalInput() + ['branch_id' => $second]);
        $this->scopedAccountant([1]);

        $result = $this->jsonOf($this->post('/accounting_journals/post_drafts', ['ids' => [(string) $draftId]]));

        $this->assertFalse($result['success']);
        $this->assertSame('draft', $this->accountingDb()->table('accounting_journals')->where('journal_id', $draftId)->get()->getRow()->status);
    }

    public function testGetAuditSearch_ListsOnlyTheEntriesOfTheEmployeesBranches(): void
    {
        $second = $this->createBranch('B2');
        $first  = $this->journalIn(1);
        $other  = $this->journalIn($second);
        $this->scopedAccountant([$second]);

        $rows = $this->jsonOf($this->get('/accounting_journals/audit/search'))['rows'];

        $journalIds = [];

        foreach ($rows as $row) {
            if (preg_match('/#(\d+)/', $row['entity'], $match) === 1) {
                $journalIds[] = (int) $match[1];
            }
        }

        $this->assertContains($other, $journalIds);
        $this->assertNotContains($first, $journalIds);
    }
}
