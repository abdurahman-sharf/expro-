<?php

namespace Tests\Controllers;

use App\Libraries\Accounting\AccountingGuard;
use App\Libraries\Accounting\AuditLog;
use App\Libraries\Accounting\ChequeSources;
use App\Libraries\Accounting\CostCenterService;
use App\Libraries\Accounting\Currencies;
use App\Libraries\Accounting\InterBranchLegs;
use App\Libraries\Accounting\JournalDraft;
use App\Libraries\Accounting\JournalAttachments;
use App\Libraries\Accounting\JournalLineDraft;
use App\Libraries\Accounting\JournalOrigin;
use App\Libraries\Accounting\JournalService;
use App\Libraries\Accounting\PostingService;
use App\Libraries\Accounting\RevenuePostingBuilder;
use App\Libraries\Accounting\TreasuryService;
use App\Libraries\Accounting\TreasuryTransfers;
use App\Libraries\Accounting\YearEndClosing;
use CodeIgniter\Exceptions\PageNotFoundException;
use CodeIgniter\HTTP\ResponseInterface;
use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\DatabaseTestTrait;
use Tests\Support\AccountingControllerFixtureTrait;
use Tests\Support\JournalAttachmentFixtureTrait;

class AccountingJournalsControllerTest extends CIUnitTestCase
{
    use DatabaseTestTrait;
    use AccountingControllerFixtureTrait;
    use JournalAttachmentFixtureTrait;

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
        $this->removeAttachmentFiles();
        $this->setConfigValue('accounting_line_description_required', '0');
        service('superglobals')->setFilesArray([]);

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

    public function testGetIndex_OffersEverySourceOfTheChequeCycleAndTheReceiptVoucherInTheSourceFilter(): void
    {
        $this->actAs($this->employeeWithGrants(['accounting_journals']));

        $body = $this->get('/accounting_journals')->getBody();

        foreach ([...ChequeSources::ALL, RevenuePostingBuilder::SOURCE] as $source) {
            $this->assertStringContainsString('<option value="' . $source . '">' . esc(lang('Accounting.source_' . $source)) . '</option>', $body, $source);
        }

        $this->assertStringContainsString('<option value="manual">', $body);
        $this->assertStringContainsString('<option value="reversal">', $body);
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

    public function testGetView_HidesTheReverseButtonOfAJournalOfATransferBetweenTreasuries(): void
    {
        $treasuries = new TreasuryService();
        $from       = $treasuries->create(['name' => 'Sending drawer', 'kind' => 'cash']);
        $to         = $treasuries->create(['name' => 'Receiving drawer', 'kind' => 'cash']);
        $id         = (new TreasuryTransfers())->transfer($from, $to, '25.00', '2026-10-21');
        $journalId  = (int) $this->accountingDb()->table('accounting_journals')->where(['source_type' => 'treasury_transfer', 'source_id' => $id])->get()->getRow()->journal_id;

        $this->accountant();

        $this->get('/accounting_journals/view/' . $journalId)->assertDontSee('accounting_journals/reverse_form/' . $journalId);
    }

    public function testGetView_HidesTheReverseButtonOfAnExchangeDifferenceJournalAndNamesItsSourceInWords(): void
    {
        $this->accountant();

        foreach (JournalService::FX_SOURCES as $source) {
            $journalId = (new PostingService())->post(new JournalDraft(
                date: '2026-10-21',
                lines: [new JournalLineDraft($this->accountId('cash'), debit: '15.00'), new JournalLineDraft($this->accountId('sales_revenue'), credit: '15.00')],
                sourceType: $source,
                sourceId: 4712,
                currencyCode: 'SAR',
            ))->journalId;

            $body = $this->get('/accounting_journals/view/' . $journalId)->getBody();

            $this->assertStringNotContainsString('accounting_journals/reverse_form/' . $journalId, $body, $source);
            $this->assertStringContainsString(esc(lang('Accounting.source_' . $source)), $body, $source);
            $this->assertNotSame('Accounting.source_' . $source, lang('Accounting.source_' . $source), $source);
        }
    }

    public function testGetView_ShowsTheForeignAmountOfEachLineAndHidesTheReverseButtonOfAJournalInAForeignCurrency(): void
    {
        $this->accountant();

        $currencies = new Currencies();
        $currencies->setActive('USD', true);
        $currencies->rates()->add(['currency_code' => 'USD', 'rate' => '3.75', 'effective_date' => '2020-01-01']);

        $journalId = (new PostingService())->post(new JournalDraft(
            date: '2026-10-21',
            lines: [
                new JournalLineDraft($this->accountId('cash'), debit: '375.00', foreignAmount: '100.0000', currencyCode: 'USD'),
                new JournalLineDraft($this->accountId('sales_revenue'), credit: '375.00', foreignAmount: '100.0000', currencyCode: 'USD'),
            ],
            sourceType: 'sale',
            sourceId: 4713,
            currencyCode: 'USD',
            exchangeRate: '3.75',
        ))->journalId;

        $body = $this->get('/accounting_journals/view/' . $journalId)->getBody();

        $this->assertStringContainsString(esc(lang('Accounting_journals.foreign_amount')), $body);
        $this->assertStringContainsString('100.00 USD', $body);
        $this->assertStringNotContainsString('accounting_journals/reverse_form/' . $journalId, $body);
    }

    public function testGetView_LeavesOutTheForeignColumnOfAJournalInTheBaseCurrency(): void
    {
        $this->accountant();

        $body = $this->get('/accounting_journals/view/' . $this->automaticJournal(sourceId: 4714))->getBody();

        $this->assertStringNotContainsString(esc(lang('Accounting_journals.foreign_amount')), $body);
    }

    public function testGetView_HidesTheReverseButtonOfAJournalThatIsOneOfAPairBetweenBranches(): void
    {
        $this->accountant();

        $journalId = $this->automaticJournal(sourceId: 4711);

        $this->get('/accounting_journals/view/' . $journalId)->assertSee('accounting_journals/reverse_form/' . $journalId);

        (new InterBranchLegs())->record($journalId, $this->accountId('cash'), InterBranchLegs::RECEIVABLE, 1, 'sale', 4711, 1);

        $this->get('/accounting_journals/view/' . $journalId)->assertDontSee('accounting_journals/reverse_form/' . $journalId);
    }

    public function testGetView_HidesTheReverseButtonOfTheJournalsOfAStockTransferBetweenBranches(): void
    {
        $this->accountant();

        foreach (['stock_transfer', 'stock_transfer_receipt'] as $source) {
            $journalId = (new PostingService())->post(new JournalDraft(
                date: '2026-10-21',
                lines: [new JournalLineDraft($this->accountId('cash'), debit: '10.00'), new JournalLineDraft($this->accountId('sales_revenue'), credit: '10.00')],
                sourceType: $source,
                sourceId: random_int(1000, 999999),
            ))->journalId;

            $page = $this->get('/accounting_journals/view/' . $journalId);

            $page->assertDontSee('accounting_journals/reverse_form/' . $journalId);
            $page->assertSee(lang('Accounting.source_' . $source));
        }
    }

    public function testGetView_HidesTheReverseButtonOfTheJournalsOfAnExpenseAndACashUp(): void
    {
        $this->accountant();

        foreach (['expense', 'cashup'] as $source) {
            $journalId = (new PostingService())->post(new JournalDraft(
                date: '2026-10-21',
                lines: [new JournalLineDraft($this->accountId('cash'), debit: '10.00'), new JournalLineDraft($this->accountId('sales_revenue'), credit: '10.00')],
                sourceType: $source,
                sourceId: random_int(1000, 999999),
            ))->journalId;

            $page = $this->get('/accounting_journals/view/' . $journalId);

            $page->assertDontSee('accounting_journals/reverse_form/' . $journalId);
            $page->assertSee(lang('Accounting.source_' . $source));
        }
    }

    /**
     * @return array{closing: int, reversal: int|null} The journal that closes 2025 and, when asked, the one that takes the closing back.
     */
    private function closedYear(bool $takenBack): array
    {
        (new JournalService())->postNew(['date' => '2025-05-10'] + $this->journalInput('100.00'));
        $this->closeThrough('2025-12-31');

        $closer  = $this->employeeWithGrants([AccountingGuard::YEAR_END_CLOSE]);
        $run     = new YearEndClosing();
        $year    = (int) $this->accountingDb()->table('accounting_fiscal_years')->where('name', '2025')->get()->getRow()->fiscal_year_id;
        $preview = $run->preview(null, $year, $closer);
        $id      = $run->approve(null, $year, $preview->fingerprint, $closer)[0];

        return [
            'closing'  => (int) $this->accountingDb()->table('accounting_year_end_closings')->where('closing_id', $id)->get()->getRow()->journal_id,
            'reversal' => $takenBack ? $run->reverse($id, 'Back', $closer) : null,
        ];
    }

    public function testGetView_HidesTheReverseButtonOfTheJournalThatClosesAYearAndNamesIt(): void
    {
        $journals = $this->closedYear(false);
        $this->accountant();

        $page = $this->get('/accounting_journals/view/' . $journals['closing']);

        $page->assertStatus(200);
        $page->assertSee(lang('Accounting.state_posted'));
        $page->assertDontSee('accounting_journals/reverse_form/' . $journals['closing']);
        $page->assertSee(lang('Accounting.source_year_end'));
        $page->assertDontSee(lang('Accounting.source_year_end') . ' — ' . lang('Accounting.entry_year_end'));
    }

    public function testGetView_NamesTheReversalOfAClosingAsTheReversalOfAYearEndClosing(): void
    {
        $journals = $this->closedYear(true);
        $this->accountant();

        $this->get('/accounting_journals/view/' . $journals['reversal'])->assertSee(lang('Accounting.source_reversal') . ' — ' . lang('Accounting.entry_year_end'));
    }

    public function testGetAuditSearch_NamesTheActionsAndTheEntityOfAYearEndClosing(): void
    {
        $this->closedYear(true);
        $this->accountant();

        $rows = $this->jsonOf($this->get('/accounting_journals/audit/search?order=asc&limit=100'))['rows'];

        $this->assertContains(lang('Accounting.action_year_end_closed'), array_column($rows, 'action'));
        $this->assertContains(lang('Accounting.action_year_end_reversed'), array_column($rows, 'action'));
        $this->assertContains(lang('Accounting.entity_year_end_closing'), array_map(static fn (string $cell): string => trim(explode('<bdi', $cell)[0]), array_column($rows, 'entity')));
        $this->assertNotContains('year_end_closed', array_column($rows, 'action'));
    }

    public function testGetView_HidesTheReverseButtonOfTheJournalsOfAnAdvanceAndOfACashRefund(): void
    {
        $this->accountant();

        foreach (JournalService::CREDIT_SOURCES as $source) {
            $journalId = (new PostingService())->post(new JournalDraft(
                date: '2026-10-21',
                lines: [new JournalLineDraft($this->accountId('cash'), debit: '10.00'), new JournalLineDraft($this->accountId('sales_revenue'), credit: '10.00')],
                sourceType: $source,
                sourceId: random_int(1000, 999999),
            ))->journalId;

            $page = $this->get('/accounting_journals/view/' . $journalId);

            $page->assertStatus(200);
            $page->assertDontSee('accounting_journals/reverse_form/' . $journalId);
            $page->assertSee(lang('Accounting.source_' . $source));
        }
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

    public function testGetAudit_OffersTheLogAsCsvExcelAndPdfFilesThatTheFiltersAreAddedTo(): void
    {
        $this->accountant();
        $this->postedJournalBy();

        $response = $this->get('/accounting_journals/audit');

        $this->assertMatchesRegularExpression('#id="audit_export" class="[^"]*" href="[^"]*/accounting_journals/audit/csv"#', $response->getBody());
        $this->assertMatchesRegularExpression('#id="audit_export_xlsx" href="[^"]*/accounting_journals/audit/xlsx"#', $response->getBody());
        $this->assertMatchesRegularExpression('#id="audit_export_pdf" href="[^"]*/accounting_journals/audit/pdf"#', $response->getBody());
        $this->assertStringContainsString("$('#audit_export, #audit_export_xlsx, #audit_export_pdf').on('click'", $response->getBody());
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

    public function testGetAuditSearch_NamesTheCreditFlowsAndTheReturnOverrideInWords(): void
    {
        $accountant = $this->accountant();
        $log        = new AuditLog();

        $log->write('customer_advance', 7, 'created', $accountant, JournalOrigin::User);
        $log->write('customer_refund', 8, 'cancelled', $accountant, JournalOrigin::User);
        $log->write('supplier_refund', 9, 'created', $accountant, JournalOrigin::User);
        $log->write('sale', 10, 'return_override', $accountant, JournalOrigin::User, ['reason' => 'lost the receipt']);

        $rows = $this->jsonOf($this->get('/accounting_journals/audit/search?order=asc'))['rows'];

        $this->assertStringContainsString(lang('Accounting.entity_customer_advance'), $rows[0]['entity']);
        $this->assertStringContainsString(lang('Accounting.entity_customer_refund'), $rows[1]['entity']);
        $this->assertStringContainsString(lang('Accounting.entity_supplier_refund'), $rows[2]['entity']);
        $this->assertSame(lang('Accounting.action_return_override'), $rows[3]['action']);

        foreach ($rows as $row) {
            $this->assertStringNotContainsString('customer_advance', $row['entity']);
            $this->assertStringNotContainsString('_refund', $row['entity']);
            $this->assertStringNotContainsString('return_override', $row['action']);
        }
    }

    public function testGetAuditSearch_FiltersByTheEntityOfACreditFlow(): void
    {
        $accountant = $this->accountant();
        $log        = new AuditLog();

        $log->write('customer_advance', 7, 'created', $accountant, JournalOrigin::User);
        $log->write('customer_refund', 8, 'created', $accountant, JournalOrigin::User);
        $log->write('supplier_refund', 9, 'created', $accountant, JournalOrigin::User);

        foreach (['customer_advance', 'customer_refund', 'supplier_refund'] as $entity) {
            $result = $this->jsonOf($this->get('/accounting_journals/audit/search?entity_type=' . $entity));

            $this->assertSame(1, $result['total'], $entity);
        }
    }

    public function testGetAudit_OffersTheCreditFlowsAndTheSaleInTheEntityFilter(): void
    {
        $this->accountant();

        $page = $this->get('/accounting_journals/audit');

        foreach (['sale', 'customer_advance', 'customer_refund', 'supplier_refund'] as $entity) {
            $page->assertSee('<option value="' . $entity . '">' . lang('Accounting.entity_' . $entity) . '</option>');
        }
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

    public function testGetAuditSearch_FiltersByTheOriginOfTheEntry(): void
    {
        $accountant = $this->accountant();
        $log        = new AuditLog();
        $log->write('journal', 1, 'posted', $accountant, JournalOrigin::User);
        $log->write('journal', 2, 'posted', $accountant, JournalOrigin::User);
        $log->write('sale', 3, 'cancelled', null, JournalOrigin::System);

        $system = $this->jsonOf($this->get('/accounting_journals/audit/search?origin=system'));
        $user   = $this->jsonOf($this->get('/accounting_journals/audit/search?origin=user'));

        $this->assertSame(1, $system['total']);
        $this->assertSame(lang('Accounting.origin_system'), $system['rows'][0]['origin']);
        $this->assertSame(2, $user['total']);
    }

    public function testGetAuditSearch_PagesWithTheTotalOfEveryEntry(): void
    {
        $accountant = $this->accountant();

        for ($entityId = 1; $entityId <= 7; $entityId++) {
            (new AuditLog())->write('journal', $entityId, 'posted', $accountant, JournalOrigin::User);
        }

        $first  = $this->jsonOf($this->get('/accounting_journals/audit/search?order=asc&limit=3&offset=0'));
        $second = $this->jsonOf($this->get('/accounting_journals/audit/search?order=asc&limit=3&offset=3'));
        $last   = $this->jsonOf($this->get('/accounting_journals/audit/search?order=asc&limit=3&offset=6'));

        $this->assertSame([7, 3, 3], [$first['total'], count($first['rows']), count($second['rows'])]);
        $this->assertSame(7, $second['total']);
        $this->assertCount(1, $last['rows']);
        $this->assertSame([1, 2, 3], array_map(static fn (array $row): int => $row['audit_id'] - $first['rows'][0]['audit_id'] + 1, $first['rows']));
        $this->assertGreaterThan($first['rows'][2]['audit_id'], $second['rows'][0]['audit_id']);

        $descending = $this->jsonOf($this->get('/accounting_journals/audit/search?order=desc&limit=3&offset=0'));

        $this->assertSame($last['rows'][0]['audit_id'], $descending['rows'][0]['audit_id']);
    }

    public function testGetAuditSearch_ReadsThePresetsAsTheObservedRanges(): void
    {
        $accountant = $this->accountant();
        $log        = new AuditLog();
        $db         = $this->accountingDb();

        foreach ([0, 20, 45] as $daysAgo) {
            $log->write('journal', $daysAgo, 'posted', $accountant, JournalOrigin::User);
            $db->table('accounting_audit_log')->update(['created_at' => date('Y-m-d 12:00:00', strtotime("-{$daysAgo} days"))], ['entity_id' => $daysAgo]);
        }

        $today        = $this->jsonOf($this->get('/accounting_journals/audit/search?preset=today'));
        $lastMonth    = $this->jsonOf($this->get('/accounting_journals/audit/search?preset=last_month'));
        $overridesDay = $this->jsonOf($this->get('/accounting_journals/audit/search?preset=today&date_from=2000-01-01'));
        $unknown      = $this->jsonOf($this->get('/accounting_journals/audit/search?preset=nonsense'));

        $this->assertSame(1, $today['total']);
        $this->assertSame(2, $lastMonth['total']);
        $this->assertSame(1, $overridesDay['total']);
        $this->assertSame(3, $unknown['total']);
    }

    public function testGetAudit_OffersEveryKnownActionAndEntityBeforeAnythingWasLogged(): void
    {
        $this->accountant();

        $page = $this->get('/accounting_journals/audit');

        foreach (['period_closed', 'permission_granted', 'year_end_reversed'] as $action) {
            $page->assertSee('<option value="' . $action . '">' . lang('Accounting.action_' . $action) . '</option>');
        }

        foreach (['employee_permission', 'role', 'price_list', 'year_end_closing'] as $entity) {
            $page->assertSee('<option value="' . $entity . '">' . lang('Accounting.entity_' . $entity) . '</option>');
        }

        $page->assertSee('<option value="system">' . lang('Accounting.origin_system') . '</option>');
        $page->assertSee('<option value="current_month">' . lang('Accounting_journals.period_current_month') . '</option>');
        $page->assertSee('audit/csv');
    }

    public function testGetAuditSearch_ShowsAReaderLimitedToABranchOnlyItsEntries(): void
    {
        $other = $this->createBranch('B2');
        $log   = new AuditLog();
        $log->write('journal', 1, 'posted', null, JournalOrigin::User, [], 1);
        $log->write('journal', 2, 'posted', null, JournalOrigin::User, [], $other);
        $log->write('settings', null, 'settings_updated', null, JournalOrigin::User);
        $this->actAs($this->employeeWithGrants(['accounting_journals', AccountingGuard::JOURNALS_AUDIT], [$other]));

        $result = $this->jsonOf($this->get('/accounting_journals/audit/search'));

        $this->assertSame(1, $result['total']);
        $this->assertStringContainsString('#2', $result['rows'][0]['entity']);
    }

    public function testGetAuditCsv_IsNotFoundWithoutTheAuditPermission(): void
    {
        $this->actAs($this->employeeWithGrants(['accounting_journals', AccountingGuard::JOURNALS_ALL]));

        $this->expectException(PageNotFoundException::class);

        $this->get('/accounting_journals/audit/csv');
    }

    public function testGetAuditCsv_WritesTheFilteredEntriesWithTheTextOfAFormulaNeutralised(): void
    {
        $this->accountant();
        $typist = $this->createEmployee('=cmd|calc', 'Typist');
        $log    = new AuditLog();
        $log->write('journal', 5, 'posted', $typist, JournalOrigin::User, ['total' => '10.0000']);
        $log->write('account', 6, 'created', $typist, JournalOrigin::System);

        $response = $this->get('/accounting_journals/audit/csv?origin=user');

        $response->assertStatus(200);
        $this->assertStringContainsString('text/csv', $response->response()->getHeaderLine('Content-Type'));
        $this->assertStringContainsString('activity_log_', $response->response()->getHeaderLine('Content-Disposition'));
        $body = $response->response()->getBody();
        $this->assertStringStartsWith("\xEF\xBB\xBF", $body);
        $this->assertStringContainsString("'=cmd|calc Typist", $body);
        $this->assertStringNotContainsString(',=cmd|calc', $body);
        $this->assertStringContainsString(lang('Accounting.action_posted'), $body);
        $this->assertStringContainsString('total: 10.0000', $body);
        $this->assertStringNotContainsString(lang('Accounting.action_created'), $body);
    }

    public function testGetAuditCsv_ListsOnlyTheEntriesOfTheBranchesOfALimitedReader(): void
    {
        $other = $this->createBranch('B2');
        $log   = new AuditLog();
        $log->write('journal', 11, 'posted', null, JournalOrigin::User, [], 1);
        $log->write('journal', 22, 'posted', null, JournalOrigin::User, [], $other);
        $log->write('settings', null, 'settings_updated', null, JournalOrigin::User);
        $this->actAs($this->employeeWithGrants(['accounting_journals', AccountingGuard::JOURNALS_AUDIT], [$other]));

        $body = $this->get('/accounting_journals/audit/csv')->response()->getBody();

        $this->assertStringContainsString('#22', $body);
        $this->assertStringNotContainsString('#11', $body);
        $this->assertStringNotContainsString(lang('Accounting.action_settings_updated'), $body);
        $this->assertStringContainsString(lang('Accounting_journals.audit_limited_branches'), $body);
    }

    /**
     * @return list<int> The entity numbers of the entries a file holds, in the order of the file.
     */
    private function entriesOfFile(string $body): array
    {
        $handle = fopen('php://temp', 'r+b');
        fwrite($handle, substr($body, 3));
        rewind($handle);

        $numbers = [];

        while (($row = fgetcsv($handle, 0, ',', '"', '')) !== false) {
            foreach ($row as $cell) {
                if (preg_match('/ #(\d+)$/', $cell, $found) === 1) {
                    $numbers[] = (int) $found[1];
                }
            }
        }

        fclose($handle);

        return $numbers;
    }

    private function writeLog(int $count, int $first = 1): void
    {
        $rows = [];

        for ($index = 0; $index < $count; $index++) {
            $rows[] = ['entity_type' => 'journal', 'entity_id' => $first + $index, 'action' => 'posted', 'origin' => 'system'];
        }

        foreach (array_chunk($rows, 5000) as $chunk) {
            $this->accountingDb()->table('accounting_audit_log')->insertBatch($chunk);
        }
    }

    public function testGetAuditCsv_RefusesALogLargerThanOneFileCanCarry(): void
    {
        $this->accountant();
        $this->writeLog(20001);

        $response = $this->get('/accounting_journals/audit/csv?entity_type=journal');

        $response->assertStatus(422);
        $this->assertStringContainsString(lang('Accounting.error_export_too_large'), $response->response()->getBody());
    }

    public function testGetAuditCsv_WritesALogOfExactlyTheLimitWholeAndOnceEach(): void
    {
        $this->accountant();
        $this->writeLog(20000);

        $response = $this->get('/accounting_journals/audit/csv?entity_type=journal&order=asc');

        $response->assertStatus(200);

        $numbers = $this->entriesOfFile($response->response()->getBody());

        $this->assertCount(20000, $numbers);
        $this->assertSame(range(1, 20000), $numbers);
    }

    public function testGetAuditCsv_WritesEveryEntryOfALogOfSeveralPagesOnceInTheOrderAsked(): void
    {
        $this->accountant();
        $this->writeLog(1203);

        $newest = $this->entriesOfFile($this->get('/accounting_journals/audit/csv?entity_type=journal')->response()->getBody());
        $oldest = $this->entriesOfFile($this->get('/accounting_journals/audit/csv?entity_type=journal&order=asc')->response()->getBody());

        $this->assertSame(range(1203, 1), $newest);
        $this->assertSame(range(1, 1203), $oldest);
    }

    public function testGetAuditCsv_IgnoresAFilterThatIsNotASingleText(): void
    {
        $this->accountant();
        (new AuditLog())->write('journal', 5, 'posted', null, JournalOrigin::User);

        $response = $this->get('/accounting_journals/audit/csv?preset[]=today&action[]=posted&entity_type[]=journal&origin[]=user&date_from[]=1&date_to[]=2&order[]=asc');

        $response->assertStatus(200);
        $this->assertContains(5, $this->entriesOfFile($response->response()->getBody()));
    }

    public function testGetAuditSearch_IgnoresAFilterThatIsNotASingleText(): void
    {
        $this->accountant();
        (new AuditLog())->write('journal', 5, 'posted', null, JournalOrigin::User);

        $result = $this->jsonOf($this->get('/accounting_journals/audit/search?preset[]=today&action[]=posted&entity_type[]=journal&origin[]=user&date_from[]=1&date_to[]=2&order[]=asc'));

        $this->assertGreaterThanOrEqual(1, $result['total']);
    }

    public function testGetAudit_OffersTheKindsOfRecordsTheLogHoldsAndNamesTheOnesTheCodeWrites(): void
    {
        $this->accountant();

        $log = new AuditLog();
        $log->write('treasury_transfer', 1, 'created', null, JournalOrigin::User);
        $log->write('made_up_kind', 2, 'made_up_action', null, JournalOrigin::User);

        $page = $this->get('/accounting_journals/audit');

        $page->assertSee('<option value="made_up_kind">made_up_kind</option>');

        foreach (['treasury', 'treasury_transfer', 'supplier_advance', 'receiving', 'interbranch_settlement'] as $entity) {
            $page->assertSee('<option value="' . $entity . '">' . lang('Accounting.entity_' . $entity) . '</option>');
            $this->assertNotSame('Accounting.entity_' . $entity, lang('Accounting.entity_' . $entity));
        }

        foreach (['default_treasury_set', 'moved', 'opening_balance_created', 'opening_balance_cancelled'] as $action) {
            $page->assertSee('<option value="' . $action . '">' . lang('Accounting.action_' . $action) . '</option>');
            $this->assertNotSame('Accounting.action_' . $action, lang('Accounting.action_' . $action));
        }
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

    private function detailsEditor(?array $branchIds = null): int
    {
        $personId = $this->employeeWithGrants(['accounting_journals', AccountingGuard::JOURNALS_ALL, AccountingGuard::JOURNALS_EDIT, AccountingGuard::JOURNALS_AUDIT], $branchIds);
        $this->actAs($personId);

        return $personId;
    }

    private function automaticJournal(?int $branchId = null, string $date = '2026-10-21', int $sourceId = 1): int
    {
        return (new PostingService())->post(new JournalDraft(
            date: $date,
            lines: [
                new JournalLineDraft($this->accountId('cash'), debit: '100.00', description: 'Cash received'),
                new JournalLineDraft($this->accountId('sales_revenue'), credit: '100.00', description: 'Sales'),
            ],
            sourceType: 'sale',
            sourceId: $sourceId,
            description: 'Invoice 7',
            currencyCode: 'SAR',
            branchId: $branchId,
        ))->journalId;
    }

    /**
     * @return list<int>
     */
    private function lineIdsOf(int $journalId): array
    {
        return array_map(static fn (object $line): int => (int) $line->line_id, (new JournalService())->find($journalId)->lines);
    }

    private function uploadAs(string $clientName, string $content, int $error = UPLOAD_ERR_OK): void
    {
        $path = $this->upload($content);

        service('superglobals')->setFilesArray(['file' => ['name' => $clientName, 'type' => 'application/octet-stream', 'tmp_name' => $path, 'error' => $error, 'size' => strlen($content)]]);
    }

    private function versionOf(int $journalId): string
    {
        $journals = new JournalService();
        $journal  = $journals->find($journalId);

        return $journals->detailsVersion($journal, $journal->lines);
    }

    private function addFileTo(int $journalId, int $employeeId, string $name = 'receipt.pdf', ?string $content = null): int
    {
        return (new JournalAttachments())->add($journalId, $this->upload($content ?? $this->pdfContent()), $name, $employeeId);
    }

    private function servedBytes(ResponseInterface $response): string
    {
        ob_start();
        $response->sendBody();

        return (string) ob_get_clean();
    }

    public function testGetEditDetails_OffersOnlyTheTextFieldsOfAPostedAutomaticJournal(): void
    {
        $journalId        = $this->automaticJournal();
        [$first, $second] = $this->lineIdsOf($journalId);
        $this->detailsEditor();

        $response = $this->get('/accounting_journals/edit_details/' . $journalId);

        $response->assertStatus(200);

        preg_match('#<form id="details_form".*?</form>#s', $response->getBody(), $form);
        preg_match_all('/<(?:input|textarea|select)[^>]*\sname="([^"]+)"/', $form[0], $names);

        $this->assertEqualsCanonicalizing([
            'description', 'honeypot', 'version',
            "lines[{$first}][description]", "lines[{$first}][cost_center_id]",
            "lines[{$second}][description]", "lines[{$second}][cost_center_id]",
        ], $names[1]);
        $response->assertSee('Invoice 7');
        $response->assertSee('Cash received');
        $response->assertSee(lang('Accounting_journals.details_locked_note'));
    }

    public function testGetEditDetails_ShowsTheAccountsAmountsDateAndNumberAsPlainText(): void
    {
        $journalId = $this->automaticJournal();
        $this->detailsEditor();

        $response = $this->get('/accounting_journals/edit_details/' . $journalId);

        $response->assertSee('A-' . sprintf('%06d', $journalId));
        $response->assertSee('100.00');
        $response->assertDontSee('name="date"');
        $response->assertDontSee('[account_id]');
        $response->assertDontSee('[debit]');
        $response->assertDontSee('[credit]');
    }

    public function testGetEditDetails_IsNotFoundForAManualJournalADraftAndAReversedJournal(): void
    {
        $manual   = $this->postedJournalBy();
        $draft    = (new JournalService())->saveDraft($this->journalInput());
        $reversed = $this->automaticJournal();
        (new JournalService())->reverse($reversed, '2026-10-22', 'Undone');
        $this->detailsEditor();

        foreach ([$manual, $draft, $reversed] as $journalId) {
            try {
                $this->get('/accounting_journals/edit_details/' . $journalId);
                $this->fail("Journal {$journalId} must not open for editing.");
            } catch (PageNotFoundException) {
                $this->addToAssertionCount(1);
            }
        }
    }

    public function testGetEditDetails_IsNotFoundWithoutThePermissionToEditDetails(): void
    {
        $journalId = $this->automaticJournal();
        $this->accountant();

        $this->expectException(PageNotFoundException::class);

        $this->get('/accounting_journals/edit_details/' . $journalId);
    }

    public function testGetEditDetails_IsNotFoundForAJournalOfAnotherBranch(): void
    {
        $second    = $this->createBranch('B2');
        $journalId = $this->automaticJournal();
        $this->detailsEditor([$second]);

        $this->expectException(PageNotFoundException::class);

        $this->get('/accounting_journals/edit_details/' . $journalId);
    }

    public function testGetEditDetails_IsNotFoundWhileAccountingIsDisabled(): void
    {
        $journalId = $this->automaticJournal();
        $this->prepareAccounting(false);
        $this->actAs($this->employeeWithGrants(['accounting_journals', AccountingGuard::JOURNALS_ALL, AccountingGuard::JOURNALS_EDIT]));

        $this->expectException(PageNotFoundException::class);

        $this->get('/accounting_journals/edit_details/' . $journalId);
    }

    public function testGetEditDetails_OffersTheActiveCostCentersAndKeepsTheOneTheLineAlreadyHas(): void
    {
        $costCenters = new CostCenterService();
        $active      = $costCenters->create(['code' => 'ACT', 'name' => 'Active center']);
        $retired     = $costCenters->create(['code' => 'OLD', 'name' => 'Retired center']);
        $journalId   = $this->automaticJournal();
        [$first]     = $this->lineIdsOf($journalId);
        $this->detailsEditor();
        $this->accountingDb()->table('accounting_journal_lines')->where('line_id', $first)->update(['cost_center_id' => $retired]);
        $costCenters->deactivate($retired);

        $response = $this->get('/accounting_journals/edit_details/' . $journalId);

        $response->assertSee('Active center');
        $response->assertSee('value="' . $retired . '" selected');
    }

    public function testGetEditDetails_EscapesTheTextItShowsInTheFields(): void
    {
        $journalId = $this->automaticJournal();
        [$first]   = $this->lineIdsOf($journalId);
        $this->accountingDb()->table('accounting_journals')->where('journal_id', $journalId)->update(['description' => '</textarea><script>alert(1)</script>']);
        $this->accountingDb()->table('accounting_journal_lines')->where('line_id', $first)->update(['description' => '"><script>alert(2)</script>']);
        $this->detailsEditor();

        $response = $this->get('/accounting_journals/edit_details/' . $journalId);

        $response->assertDontSee('<script>alert(1)</script>');
        $response->assertDontSee('<script>alert(2)</script>');
        $body = $response->response()->getBody();

        $this->assertStringContainsString('&lt;/textarea&gt;&lt;script&gt;alert(1)&lt;/script&gt;', $body);
        $this->assertStringContainsString('value="&quot;&gt;&lt;script&gt;alert&#x28;2&#x29;&lt;&#x2F;script&gt;"', $body);
    }

    public function testPostSaveDetails_SavesTheTextAndSendsTheUserBackToTheJournal(): void
    {
        $journalId        = $this->automaticJournal();
        [$first, $second] = $this->lineIdsOf($journalId);
        $employee         = $this->detailsEditor();

        $result = $this->jsonOf($this->post('/accounting_journals/save_details/' . $journalId, [
            'description' => 'Invoice 7, corrected',
            'lines'       => [$first => ['description' => 'Cash in', 'cost_center_id' => ''], $second => ['description' => 'Shop sales', 'cost_center_id' => '']],
        ]));

        $this->assertTrue($result['success']);
        $this->assertSame(lang('Accounting_journals.details_saved'), $result['message']);
        $this->assertStringContainsString('accounting_journals/view/' . $journalId, $result['redirect']);

        $journal = (new JournalService())->find($journalId);

        $this->assertSame('Invoice 7, corrected', $journal->description);
        $this->assertSame(['Cash in', 'Shop sales'], array_map(static fn (object $line): string => $line->description, $journal->lines));

        $audit = $this->accountingDb()->table('accounting_audit_log')->where('action', 'details_edited')->get()->getRow();

        $this->assertSame((string) $employee, (string) $audit->employee_id);
        $this->assertSame((string) $journalId, (string) $audit->entity_id);
    }

    public function testGetEditDetails_CarriesTheVersionOfTheTextItShowsInTheForm(): void
    {
        $journalId = $this->automaticJournal();
        $this->detailsEditor();

        $response = $this->get('/accounting_journals/edit_details/' . $journalId);

        preg_match('#<form id="details_form".*?</form>#s', $response->getBody(), $form);

        $this->assertSame(1, preg_match('/<input type="hidden" name="version" value="([0-9a-f]{64})">/', $form[0], $found));
        $this->assertSame($this->versionOf($journalId), $found[1]);
    }

    public function testPostSaveDetails_SavesAFormOpenedOnTheTextThatIsStillCurrent(): void
    {
        $journalId = $this->automaticJournal();
        $this->detailsEditor();

        $result = $this->jsonOf($this->post('/accounting_journals/save_details/' . $journalId, ['description' => 'Edited', 'version' => $this->versionOf($journalId)]));

        $this->assertTrue($result['success']);
        $this->assertSame('Edited', (new JournalService())->find($journalId)->description);
    }

    public function testPostSaveDetails_RejectsAFormOpenedBeforeSomeoneElseChangedTheTextAndKeepsTheirChange(): void
    {
        $journalId        = $this->automaticJournal();
        [$first, $second] = $this->lineIdsOf($journalId);
        $employee         = $this->detailsEditor();
        $opened           = $this->versionOf($journalId);

        (new JournalService())->updateDetails($journalId, ['lines' => [$second => ['description' => 'Changed by someone else']]], $employee);

        $result = $this->jsonOf($this->post('/accounting_journals/save_details/' . $journalId, [
            'description' => 'Edited',
            'version'     => $opened,
            'lines'       => [$first => ['description' => 'Edited line'], $second => ['description' => 'Sales']],
        ]));

        $this->assertFalse($result['success']);
        $this->assertSame(lang('Accounting.error_details_changed'), $result['message']);

        $journal = (new JournalService())->find($journalId);

        $this->assertSame('Invoice 7', $journal->description);
        $this->assertSame(['Cash received', 'Changed by someone else'], array_map(static fn (object $line): string => $line->description, $journal->lines));
    }

    public function testPostSaveDetails_IgnoresValuesThatAreNotTextInsteadOfFailing(): void
    {
        $journalId        = $this->automaticJournal();
        [$first, $second] = $this->lineIdsOf($journalId);
        $this->detailsEditor();

        $result = $this->jsonOf($this->post('/accounting_journals/save_details/' . $journalId, [
            'description' => ['x'],
            'lines'       => [$first => ['description' => ['x'], 'cost_center_id' => ['1']], $second => ['description' => 'Shop sales']],
        ]));

        $this->assertTrue($result['success']);
        $this->assertSame(lang('Accounting_journals.details_saved'), $result['message']);

        $journal = (new JournalService())->find($journalId);

        $this->assertSame('Invoice 7', $journal->description);
        $this->assertSame(['Cash received', 'Shop sales'], array_map(static fn (object $line): string => $line->description, $journal->lines));
        $this->assertNull($journal->lines[0]->cost_center_id);
    }

    public function testPostSaveDetails_SaysNothingChangedWhenTheFormIsSentAsItWas(): void
    {
        $journalId = $this->automaticJournal();
        $this->detailsEditor();

        $result = $this->jsonOf($this->post('/accounting_journals/save_details/' . $journalId, ['description' => 'Invoice 7']));

        $this->assertTrue($result['success']);
        $this->assertSame(lang('Accounting_journals.details_unchanged'), $result['message']);
        $this->assertSame(0, $this->accountingDb()->table('accounting_audit_log')->where('action', 'details_edited')->countAllResults());
    }

    public function testPostSaveDetails_IgnoresAmountsAccountsAndDatesInTheRequest(): void
    {
        $journalId        = $this->automaticJournal();
        [$first, $second] = $this->lineIdsOf($journalId);
        $before           = (new JournalService())->find($journalId);
        $this->detailsEditor();

        $result = $this->jsonOf($this->post('/accounting_journals/save_details/' . $journalId, [
            'description' => 'Edited',
            'date'        => '2020-01-01',
            'number'      => '000999',
            'lines'       => [
                $first  => ['description' => 'Edited line', 'account_id' => (string) $this->accountId('cogs'), 'debit' => '1.00', 'credit' => '0'],
                $second => ['account_id' => (string) $this->accountId('cogs'), 'credit' => '1.00', 'debit' => '0'],
            ],
        ]));

        $after = (new JournalService())->find($journalId);

        $this->assertTrue($result['success']);
        $this->assertSame('Edited', $after->description);
        $this->assertSame($before->journal_date, $after->journal_date);
        $this->assertSame($before->number, $after->number);
        $this->assertSame($before->total_debit, $after->total_debit);
        $this->assertSame(
            array_map(static fn (object $line): array => [$line->account_id, $line->debit, $line->credit], $before->lines),
            array_map(static fn (object $line): array => [$line->account_id, $line->debit, $line->credit], $after->lines),
        );
    }

    public function testPostSaveDetails_RejectsSomeoneWithoutThePermissionAndChangesNothing(): void
    {
        $journalId = $this->automaticJournal();
        $this->accountant();

        $result = $this->jsonOf($this->post('/accounting_journals/save_details/' . $journalId, ['description' => 'Edited']));

        $this->assertFalse($result['success']);
        $this->assertSame(lang('Accounting.error_permission_denied'), $result['message']);
        $this->assertSame('Invoice 7', (new JournalService())->find($journalId)->description);
    }

    public function testPostSaveDetails_RejectsAManualJournalWithAReadableMessage(): void
    {
        $journalId = $this->postedJournalBy();
        $this->detailsEditor();

        $result = $this->jsonOf($this->post('/accounting_journals/save_details/' . $journalId, ['description' => 'Edited']));

        $this->assertFalse($result['success']);
        $this->assertSame(lang('Accounting.error_posted_journal_locked'), $result['message']);
        $this->assertSame('Test journal', (new JournalService())->find($journalId)->description);
    }

    public function testPostSaveDetails_RejectsAJournalInAClosedPeriod(): void
    {
        $journalId = $this->automaticJournal(date: '2026-03-15');
        $this->closeThrough('2026-03-31');
        $this->detailsEditor();

        $result = $this->jsonOf($this->post('/accounting_journals/save_details/' . $journalId, ['description' => 'Edited']));

        $this->assertFalse($result['success']);
        $this->assertSame('Invoice 7', (new JournalService())->find($journalId)->description);
    }

    public function testPostSaveDetails_RejectsTextThatIsTooLong(): void
    {
        $journalId = $this->automaticJournal();
        $this->detailsEditor();

        $result = $this->jsonOf($this->post('/accounting_journals/save_details/' . $journalId, ['description' => str_repeat('a', 2001)]));

        $this->assertFalse($result['success']);
        $this->assertStringContainsString('2000', $result['message']);
        $this->assertSame('Invoice 7', (new JournalService())->find($journalId)->description);
    }

    public function testPostSaveDetails_ReportsAJournalOfAnotherBranchAsMissing(): void
    {
        $second    = $this->createBranch('B2');
        $journalId = $this->automaticJournal();
        $this->detailsEditor([$second]);

        $result = $this->jsonOf($this->post('/accounting_journals/save_details/' . $journalId, ['description' => 'Edited']));

        $this->assertFalse($result['success']);
        $this->assertSame(lang('Accounting.error_journal_not_found'), $result['message']);
    }

    public function testGetView_OffersTheEditLinkOnlyOnAPostedAutomaticJournalToAHolderOfThePermission(): void
    {
        $automatic = $this->automaticJournal();
        $manual    = $this->postedJournalBy();
        $this->detailsEditor();

        $this->get('/accounting_journals/view/' . $automatic)->assertSee('accounting_journals/edit_details/' . $automatic);
        $this->get('/accounting_journals/view/' . $manual)->assertDontSee('accounting_journals/edit_details/');

        $this->accountant();

        $this->get('/accounting_journals/view/' . $automatic)->assertDontSee('accounting_journals/edit_details/');
    }

    public function testGetView_ShowsTheTextEditInTheHistoryOfSomeoneWhoMayReadTheAuditLog(): void
    {
        $journalId = $this->automaticJournal();
        $this->detailsEditor();
        $this->post('/accounting_journals/save_details/' . $journalId, ['description' => 'Corrected text']);

        $response = $this->get('/accounting_journals/view/' . $journalId);

        $response->assertSee(lang('Accounting.action_details_edited'));
        $response->assertSee('Corrected text');
    }

    public function testGetView_ListsTheFilesOfTheJournalAndOffersDeleteToTheHolderOfThePermission(): void
    {
        $journalId = $this->automaticJournal();
        $employee  = $this->detailsEditor();
        $fileId    = $this->addFileTo($journalId, $employee, 'supplier invoice.pdf');

        $response = $this->get('/accounting_journals/view/' . $journalId);

        $response->assertSee('supplier invoice.pdf');
        $response->assertSee('accounting_journals/attachment/' . $fileId);
        $response->assertSee('data-delete-attachment="' . $fileId . '"');
        $response->assertSee('id="attachment_form"');

        $this->actAs($this->employeeWithGrants(['accounting_journals', AccountingGuard::JOURNALS_ALL]));

        $reader = $this->get('/accounting_journals/view/' . $journalId);

        $reader->assertSee('supplier invoice.pdf');
        $reader->assertDontSee('data-delete-attachment');
        $reader->assertDontSee('id="attachment_form"');
    }

    public function testGetView_EscapesTheNameOfAFile(): void
    {
        $journalId = $this->automaticJournal();
        $employee  = $this->detailsEditor();
        $fileId    = $this->addFileTo($journalId, $employee);
        $this->accountingDb()->table('accounting_journal_attachments')->where('attachment_id', $fileId)->update(['original_name' => '<script>alert(1)</script>.pdf']);

        $response = $this->get('/accounting_journals/view/' . $journalId);

        $response->assertDontSee('<script>alert(1)</script>.pdf');
        $response->assertSee('&lt;script&gt;alert(1)&lt;/script&gt;.pdf');
    }

    public function testGetEdit_OffersTheFilesOfADraftToItsEditor(): void
    {
        $employee = $this->accountant();
        $draftId  = (new JournalService())->saveDraft($this->journalInput(), $employee);
        $fileId   = $this->addFileTo($draftId, $employee, 'draft note.pdf');

        $response = $this->get('/accounting_journals/edit/' . $draftId);

        $response->assertSee('draft note.pdf');
        $response->assertSee('data-delete-attachment="' . $fileId . '"');
        $response->assertSee('id="attachment_form"');
    }

    public function testGetNew_AsksToSaveTheDraftBeforeFilesCanBeAttached(): void
    {
        $this->accountant();

        $response = $this->get('/accounting_journals/new');

        $response->assertSee(lang('Accounting_journals.attachments_save_first'));
        $response->assertDontSee('id="attachment_form"');
    }

    public function testPostAttach_StoresTheFileAndLeavesTheAmountsAlone(): void
    {
        $journalId = $this->automaticJournal();
        $employee  = $this->detailsEditor();
        $before    = (new JournalService())->find($journalId);
        $this->uploadAs('invoice.pdf', $this->pdfContent());

        $result = $this->jsonOf($this->post('/accounting_journals/attach/' . $journalId));

        $this->assertTrue($result['success']);
        $this->assertSame(lang('Accounting_journals.attachment_added'), $result['message']);
        $this->assertSame('invoice.pdf', $result['file']['name']);
        $this->assertSame($result['id'], $result['file']['id']);
        $this->assertStringContainsString('accounting_journals/attachment/' . $result['id'], $result['file']['url']);

        $row = $this->accountingDb()->table('accounting_journal_attachments')->where('attachment_id', $result['id'])->get()->getRow();

        $this->assertSame((string) $journalId, (string) $row->journal_id);
        $this->assertSame('invoice.pdf', $row->original_name);
        $this->assertSame((string) $employee, (string) $row->uploaded_by);
        $this->assertSame($before->total_debit, (new JournalService())->find($journalId)->total_debit);
    }

    public function testPostAttach_RejectsAKindOfFileThatIsNotAllowed(): void
    {
        $journalId = $this->automaticJournal();
        $this->detailsEditor();
        $this->uploadAs('shell.php', '<?php echo 1;');

        $result = $this->jsonOf($this->post('/accounting_journals/attach/' . $journalId));

        $this->assertFalse($result['success']);
        $this->assertSame(lang('Accounting.error_attachment_type_not_allowed'), $result['message']);
        $this->assertSame(0, $this->countRows('accounting_journal_attachments'));
    }

    public function testPostAttach_SaysTheFileIsTooLargeWhenThePhpLimitStoppedTheUpload(): void
    {
        $journalId = $this->automaticJournal();
        $this->detailsEditor();
        $this->uploadAs('large.pdf', $this->pdfContent(), UPLOAD_ERR_INI_SIZE);

        $result = $this->jsonOf($this->post('/accounting_journals/attach/' . $journalId));

        $this->assertFalse($result['success']);
        $this->assertSame(lang('Accounting.error_attachment_too_large', [5]), $result['message']);
    }

    public function testPostAttach_SaysTheUploadFailedWhenNoFileCame(): void
    {
        $journalId = $this->automaticJournal();
        $this->detailsEditor();

        $none = $this->jsonOf($this->post('/accounting_journals/attach/' . $journalId));

        $this->uploadAs('partial.pdf', $this->pdfContent(), UPLOAD_ERR_PARTIAL);

        $partial = $this->jsonOf($this->post('/accounting_journals/attach/' . $journalId));

        $this->assertFalse($none['success']);
        $this->assertFalse($partial['success']);
        $this->assertSame(lang('Accounting.error_attachment_upload_failed'), $partial['message']);
        $this->assertSame(0, $this->countRows('accounting_journal_attachments'));
    }

    public function testPostAttach_RejectsSomeoneWithoutThePermissionToEditAPostedJournal(): void
    {
        $journalId = $this->automaticJournal();
        $this->accountant();
        $this->uploadAs('invoice.pdf', $this->pdfContent());

        $result = $this->jsonOf($this->post('/accounting_journals/attach/' . $journalId));

        $this->assertFalse($result['success']);
        $this->assertSame(lang('Accounting.error_permission_denied'), $result['message']);
        $this->assertSame(0, $this->countRows('accounting_journal_attachments'));
    }

    public function testPostAttach_RejectsAJournalInAClosedPeriod(): void
    {
        $journalId = $this->automaticJournal(date: '2026-03-15');
        $this->closeThrough('2026-03-31');
        $this->detailsEditor();
        $this->uploadAs('invoice.pdf', $this->pdfContent());

        $result = $this->jsonOf($this->post('/accounting_journals/attach/' . $journalId));

        $this->assertFalse($result['success']);
        $this->assertSame(0, $this->countRows('accounting_journal_attachments'));
    }

    public function testPostAttach_AcceptsAFileOnADraftFromItsEditor(): void
    {
        $employee = $this->accountant();
        $draftId  = (new JournalService())->saveDraft($this->journalInput(), $employee);
        $this->uploadAs('draft.pdf', $this->pdfContent());

        $result = $this->jsonOf($this->post('/accounting_journals/attach/' . $draftId));

        $this->assertTrue($result['success']);
    }

    public function testPostDeleteAttachment_RemovesTheFileAndRecordsIt(): void
    {
        $journalId = $this->automaticJournal();
        $employee  = $this->detailsEditor();
        $fileId    = $this->addFileTo($journalId, $employee);

        $result = $this->jsonOf($this->post('/accounting_journals/delete_attachment/' . $fileId));

        $this->assertTrue($result['success']);
        $this->assertSame(0, $this->countRows('accounting_journal_attachments'));
        $this->assertSame(1, $this->accountingDb()->table('accounting_audit_log')->where('action', 'attachment_deleted')->where('employee_id', $employee)->countAllResults());
    }

    public function testPostDeleteAttachment_RejectsSomeoneWithoutThePermissionAndKeepsTheFile(): void
    {
        $journalId = $this->automaticJournal();
        $employee  = $this->detailsEditor();
        $fileId    = $this->addFileTo($journalId, $employee);
        $this->accountant();

        $result = $this->jsonOf($this->post('/accounting_journals/delete_attachment/' . $fileId));

        $this->assertFalse($result['success']);
        $this->assertSame(1, $this->countRows('accounting_journal_attachments'));
    }

    public function testGetAttachment_SendsTheFileAsADownloadThatTheBrowserMustNotRun(): void
    {
        $journalId = $this->automaticJournal();
        $employee  = $this->detailsEditor();
        $fileId    = $this->addFileTo($journalId, $employee, 'فاتورة المورد.pdf');

        $response = $this->get('/accounting_journals/attachment/' . $fileId);

        $response->assertStatus(200);
        $this->assertSame($this->pdfContent(), $this->servedBytes($response->response()));
        $response->assertHeader('Content-Type', 'application/pdf');
        $response->assertHeader('X-Content-Type-Options', 'nosniff');
        $this->assertStringContainsString('no-store', $response->response()->getHeaderLine('Cache-Control'));
        $this->assertStringContainsString('private', $response->response()->getHeaderLine('Cache-Control'));
        $this->assertStringNotContainsString('public', $response->response()->getHeaderLine('Cache-Control'));

        $disposition = $response->response()->getHeaderLine('Content-Disposition');

        $this->assertStringStartsWith('attachment; filename="', $disposition);
        $this->assertStringContainsString("filename*=UTF-8''" . rawurlencode('فاتورة المورد.pdf'), $disposition);
        $this->assertStringEndsWith('.pdf', explode('"', $disposition)[1]);
        $this->assertMatchesRegularExpression('/^[A-Za-z0-9._() -]+$/', explode('"', $disposition)[1]);
    }

    public function testGetAttachment_SendsTheStoredBytesEvenWhenTheFileHoldsTheEndOfAForm(): void
    {
        $journalId = $this->automaticJournal();
        $employee  = $this->detailsEditor();
        $content   = "A note that quotes </form> and </FORM> as they are.\n";
        $fileId    = $this->addFileTo($journalId, $employee, 'note.txt', $content);

        $response = $this->get('/accounting_journals/attachment/' . $fileId);

        $response->assertStatus(200);
        $this->assertSame($content, $this->servedBytes($response->response()));
        $this->assertSame((string) $this->accountingDb()->table('accounting_journal_attachments')->where('attachment_id', $fileId)->get()->getRow()->sha256, hash('sha256', $this->servedBytes($response->response())));
        $this->assertStringNotContainsString('honeypot', $this->servedBytes($response->response()));
    }

    public function testGetAttachment_IsNotFoundForAnUnknownFileAndForOneMissingFromTheServer(): void
    {
        $journalId = $this->automaticJournal();
        $employee  = $this->detailsEditor();
        $fileId    = $this->addFileTo($journalId, $employee);
        $path      = (new JournalAttachments())->open($fileId, $employee)['path'];

        try {
            $this->get('/accounting_journals/attachment/999999');
            $this->fail('An unknown file must not be found.');
        } catch (PageNotFoundException) {
            $this->addToAssertionCount(1);
        }

        unlink($path);

        $this->expectException(PageNotFoundException::class);

        $this->get('/accounting_journals/attachment/' . $fileId);
    }

    public function testGetAttachment_IsNotFoundForSomeoneWhoMayNotReadTheJournal(): void
    {
        $second    = $this->createBranch('B2');
        $journalId = $this->automaticJournal();
        $employee  = $this->detailsEditor();
        $fileId    = $this->addFileTo($journalId, $employee);
        $this->detailsEditor([$second]);

        $this->expectException(PageNotFoundException::class);

        $this->get('/accounting_journals/attachment/' . $fileId);
    }

    public function testGetAttachment_RedirectsSomeoneWithoutTheJournalsModule(): void
    {
        $journalId = $this->automaticJournal();
        $fileId    = $this->addFileTo($journalId, $this->detailsEditor());
        $this->actAs($this->employeeWithGrants(['accounting_chart']));

        $this->get('/accounting_journals/attachment/' . $fileId)->assertRedirect();
    }

    public function testGetAttachment_IsNotFoundWhileAccountingIsDisabled(): void
    {
        $journalId = $this->automaticJournal();
        $employee  = $this->detailsEditor();
        $fileId    = $this->addFileTo($journalId, $employee);
        $this->setConfigValue('accounting_enabled', '0');

        $this->expectException(PageNotFoundException::class);

        $this->get('/accounting_journals/attachment/' . $fileId);
    }

    public function testPostDeleteDraft_TakesTheFilesOfTheDraftWithIt(): void
    {
        $employee = $this->accountant();
        $draftId  = (new JournalService())->saveDraft($this->journalInput(), $employee);
        $this->addFileTo($draftId, $employee);

        $result = $this->jsonOf($this->post('/accounting_journals/delete_draft/' . $draftId));

        $this->assertTrue($result['success']);
        $this->assertSame(0, $this->countRows('accounting_journal_attachments'));
    }

    public function testGetNew_MarksTheLineDescriptionAsRequiredOnlyWhenTheBooksAskForIt(): void
    {
        $this->accountant();

        $this->get('/accounting_journals/new')->assertDontSee('line_text_hint');

        $this->setConfigValue('accounting_line_description_required', '1');

        $response = $this->get('/accounting_journals/new');

        $response->assertSee('id="line_text_hint"');
        $response->assertSee(lang('Accounting_journals.line_description_required_hint'));
        $response->assertSee('const textRequired = true;');
    }

    public function testPostPost_RejectsALineWithoutADescriptionWhenTheBooksAskForIt(): void
    {
        $this->accountant();
        $this->setConfigValue('accounting_line_description_required', '1');
        $input                                = $this->formInput();
        $input['lines'][0]['description']     = 'Cash in';

        $result = $this->jsonOf($this->post('/accounting_journals/post', $input));

        $this->assertFalse($result['success']);
        $this->assertSame(lang('Accounting.error_line_description_required', [2]), $result['message']);
        $this->assertSame(0, $this->countRows('accounting_journals'));

        $input['lines'][1]['description'] = 'Sales';

        $this->assertTrue($this->jsonOf($this->post('/accounting_journals/post', $input))['success']);
    }

    public function testPostSaveDraft_StoresALineWithoutADescriptionWhenTheBooksAskForIt(): void
    {
        $this->accountant();
        $this->setConfigValue('accounting_line_description_required', '1');

        $result = $this->jsonOf($this->post('/accounting_journals/save_draft', $this->formInput()));

        $this->assertTrue($result['success']);
    }

    public function testGetAuditSearch_NamesTheActionsAndEntitiesOfExpensesCashUpsAndCategories(): void
    {
        $accountant = $this->accountant();
        $audit      = new AuditLog();

        $audit->write('expense', 7, 'reposted', $accountant, JournalOrigin::User);
        $audit->write('expense', 7, 'cancelled', $accountant, JournalOrigin::User);
        $audit->write('cashup', 3, 'cancelled', $accountant, JournalOrigin::User);
        $audit->write('expense_category', 2, 'account_changed', $accountant, JournalOrigin::User);

        $rows = $this->jsonOf($this->get('/accounting_journals/audit/search?order=asc'))['rows'];

        $this->assertSame(
            [lang('Accounting.action_reposted'), lang('Accounting.action_cancelled'), lang('Accounting.action_cancelled'), lang('Accounting.action_account_changed')],
            array_column($rows, 'action'),
        );
        $this->assertSame(
            [lang('Accounting.entity_expense'), lang('Accounting.entity_expense'), lang('Accounting.entity_cashup'), lang('Accounting.entity_expense_category')],
            array_map(static fn (string $cell): string => trim(explode('<bdi', $cell)[0]), array_column($rows, 'entity')),
        );
        $this->assertNotContains('reposted', array_column($rows, 'action'));
    }

    public function testGetAudit_OffersTheEntityFilterForExpensesCashUpsAndCategories(): void
    {
        $this->accountant();

        $body = $this->get('/accounting_journals/audit')->getBody();

        foreach (['expense' => 'entity_expense', 'cashup' => 'entity_cashup', 'expense_category' => 'entity_expense_category'] as $code => $key) {
            $this->assertStringContainsString('<option value="' . $code . '">' . lang('Accounting.' . $key) . '</option>', $body);
        }
    }

    public function testGetAudit_OffersTheEntityFilterForChequesChequeBooksVouchersAndVoucherCategories(): void
    {
        $this->accountant();

        $body = $this->get('/accounting_journals/audit')->getBody();

        foreach (['cheque' => 'entity_cheque', 'cheque_book' => 'entity_cheque_book', 'revenue' => 'entity_revenue', 'revenue_category' => 'entity_revenue_category'] as $code => $key) {
            $this->assertSame(1, substr_count($body, '<option value="' . $code . '">' . lang('Accounting.' . $key) . '</option>'), $code);
        }
    }

    public function testGetAudit_OffersTheEntityFilterForTaxesAndNamesTheirChanges(): void
    {
        $accountant = $this->accountant();
        $audit      = new AuditLog();

        $audit->write('tax_definition', 4, 'mode_changed', $accountant, JournalOrigin::User);
        $audit->write('tax_definition', 4, 'tax_account_changed', $accountant, JournalOrigin::User);

        $body = $this->get('/accounting_journals/audit')->getBody();
        $rows = $this->jsonOf($this->get('/accounting_journals/audit/search?entity_type=tax_definition&order=asc'))['rows'];

        $this->assertSame(1, substr_count($body, '<option value="tax_definition">' . lang('Accounting.entity_tax_definition') . '</option>'));
        $this->assertSame([lang('Accounting.action_mode_changed'), lang('Accounting.action_tax_account_changed')], array_column($rows, 'action'));
    }
}
