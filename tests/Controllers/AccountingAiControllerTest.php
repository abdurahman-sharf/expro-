<?php

namespace Tests\Controllers;

use App\Libraries\Accounting\Ai\AiSettings;
use App\Libraries\Accounting\Ai\FindingService;
use App\Libraries\Accounting\Ai\ProposalService;
use App\Libraries\Accounting\AccountingGuard;
use CodeIgniter\Exceptions\PageNotFoundException;
use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\DatabaseTestTrait;
use CodeIgniter\Test\FeatureTestTrait;
use CodeIgniter\Test\TestResponse;
use Config\Database;
use Config\Filters;
use Config\Services;
use ReflectionClass;
use Throwable;
use Tests\Support\AiFixtureTrait;
use Tests\Support\CsvResponseTrait;

/**
 * The screens of the agent layer: who may open them, what they show of the proposals and the findings of the branches
 * of the reader, that approving and rejecting ask for the token against forged requests and for a confirmation,
 * that the books refuse in words, and that the master switch stops a change at once.
 *
 * @internal
 */
final class AccountingAiControllerTest extends CIUnitTestCase
{
    use DatabaseTestTrait;
    use FeatureTestTrait;
    use AiFixtureTrait;
    use CsvResponseTrait;

    protected $migrate     = true;
    protected $migrateOnce = true;
    protected $refresh     = false;
    protected $namespace   = null;

    private ProposalService $proposals;
    private int $approver;

    public static function setUpBeforeClass(): void
    {
        $seeder = Database::seeder('tests');
        $seeder->call('TestDatabaseBootstrapSeeder');
    }

    protected function setUp(): void
    {
        parent::setUp();

        $this->startAgent();
        $this->proposals = new ProposalService();
        $this->approver  = $this->agentEmployee(['accounting_ai']);
        $this->accountingDb()->table('accounting_ai_findings')->truncate();
        $this->actAsEmployee($this->approver);
    }

    protected function tearDown(): void
    {
        $this->stopAgent();

        parent::tearDown();
    }

    private function actAsEmployee(int $personId): void
    {
        $this->withSession(['person_id' => $personId, 'menu_group' => 'home']);
    }

    /**
     * @param list<string>   $permissions
     * @param list<int>|null $branchIds
     */
    private function actAsWith(array $permissions, ?array $branchIds = null): int
    {
        $personId = $this->employeeWithGrants($permissions, $branchIds);

        $this->actAsEmployee($personId);

        return $personId;
    }

    /**
     * @return array<string, mixed>
     */
    private function json(TestResponse $response): array
    {
        return json_decode($response->getJSON(), true);
    }

    private function proposal(string $key = 'rent-1', ?int $branchId = 1, string $kind = 'recurring'): int
    {
        return $this->proposals->create($this->proposalDraft('500.00', $key, '2026-10-21', $branchId, $kind), $this->approver);
    }

    private function finding(string $rule = 'AI-ROUND', ?int $branchId = 1, string $status = 'open', string $reason = 'Looked at'): int
    {
        static $counter = 0;

        $counter++;

        $this->accountingDb()->table('accounting_ai_findings')->insert([
            'rule_code'       => $rule,
            'severity'        => 'low',
            'finding_key'     => hash('sha256', 'c' . $counter . microtime()),
            'branch_id'       => $branchId,
            'subject_type'    => 'journal',
            'subject_id'      => $counter,
            'occurred_on'     => '2026-10-10',
            'template_args'   => json_encode(['number' => '00000' . $counter, 'amount' => '25000.0000', 'unit' => '1000.0000']),
            'evidence'        => json_encode([['type' => 'journal', 'journal_id' => $counter, 'number' => '00000' . $counter, 'amount' => '25000.0000']]),
            'status'          => $status,
            'decided_by'      => $status === 'open' ? null : $this->approver,
            'decided_at'      => $status === 'open' ? null : '2026-10-11 10:00:00',
            'decision_reason' => $status === 'open' ? null : $reason,
        ]);

        return (int) $this->accountingDb()->insertID();
    }

    private function aiJournals(): int
    {
        return (int) $this->accountingDb()->table('accounting_journals')->where('source_type', ProposalService::SOURCE)->countAllResults();
    }

    public function testGetIndex_ListsTheProposalsWithTheirStatusAndOffersTheRunToWhoUsesTheAgent(): void
    {
        $one = $this->proposal('one');
        $two = $this->proposal('two');
        $this->proposals->reject($two, 'No', $this->approver);

        $page = $this->get('/accounting_ai');

        $page->assertStatus(200);
        $page->assertSee('id="ai_proposal_' . $one . '"');
        $page->assertSee('id="ai_proposal_' . $two . '"');
        $page->assertSee(lang('Accounting_ai.status_rejected'));
        $page->assertSee('id="ai_propose"');
        $page->assertSee('id="ai_agent_note"');
        $page->assertDontSee('id="ai_off"');
    }

    public function testGetIndex_RedirectsAnEmployeeWithoutTheModuleGrant(): void
    {
        $this->actAsWith([]);

        $this->assertNotSame(200, $this->get('/accounting_ai')->response()->getStatusCode());
    }

    public function testGetIndex_IsNotFoundWhileAccountingIsOff(): void
    {
        $this->setConfigValue('accounting_enabled', '0');

        $this->expectException(PageNotFoundException::class);

        $this->get('/accounting_ai');
    }

    public function testGetIndex_SaysWhenTheAgentIsSwitchedOffOffersNoRunAndStillShowsTheHistory(): void
    {
        $id = $this->proposal();
        $this->setConfigValue('accounting_ai_enabled', '0');

        $page = $this->get('/accounting_ai');

        $page->assertStatus(200);
        $page->assertSee('id="ai_off"');
        $page->assertDontSee('id="ai_propose"');
        $page->assertSee('id="ai_proposal_' . $id . '"');
    }

    public function testGetIndex_ShowsOnlyTheProposalsOfTheBranchesOfTheReaderAndSaysSo(): void
    {
        $branch = $this->createBranch();
        $mine   = $this->proposal('mine', 1);
        $other  = $this->proposal('other', $branch);

        $this->actAsWith(['accounting_ai'], [1]);

        $page = $this->get('/accounting_ai');

        $page->assertSee('id="ai_proposal_' . $mine . '"');
        $page->assertDontSee('id="ai_proposal_' . $other . '"');
        $this->assertStringContainsString('<div class="alert alert-secondary" id="ai_scope">', $page->getBody());
    }

    public function testGetIndex_FiltersByStatusAndKind(): void
    {
        $one = $this->proposal('one', 1, 'recurring');
        $two = $this->proposal('two', 1, 'reclassify');
        $this->proposals->reject($one, 'No', $this->approver);

        $byStatus = $this->get('/accounting_ai?status=rejected');
        $byKind   = $this->get('/accounting_ai?kind=reclassify');

        $byStatus->assertSee('id="ai_proposal_' . $one . '"');
        $byStatus->assertDontSee('id="ai_proposal_' . $two . '"');
        $byKind->assertSee('id="ai_proposal_' . $two . '"');
        $byKind->assertDontSee('id="ai_proposal_' . $one . '"');
    }

    public function testGetIndex_ShowsAnExpiredProposalAsExpiredAndPagesTheList(): void
    {
        $id = $this->proposal();
        $this->accountingDb()->table('accounting_ai_proposals')->update(['expires_at' => '2020-01-01 00:00:00']);

        $this->get('/accounting_ai')->assertSee(lang('Accounting_ai.status_expired'));

        for ($index = 0; $index < 26; $index++) {
            $this->proposal('many-' . $index);
        }

        $page = $this->get('/accounting_ai');

        $page->assertSee(lang('Accounting_ai.next'));
        $this->get('/accounting_ai?offset=25')->assertSee('id="ai_proposal_' . $id . '"');
    }

    public function testGetProposal_ShowsTheProposedJournalTheRationaleAndTheEvidence(): void
    {
        $id = $this->proposal();

        $page = $this->get('/accounting_ai/proposal/' . $id);

        $page->assertStatus(200);
        $page->assertSee('id="ai_rationale"');
        $page->assertSee('Nothing is posted until a person approves');
        $page->assertSee('id="ai_lines_table"');
        $page->assertSee('5201');
        $page->assertSee('id="ai_evidence"');
        $page->assertSee('evidence-row');
        $page->assertDontSee('id="ai_journal_link"');
    }

    public function testGetProposal_LinksTheJournalsOfTheEvidenceOnlyToWhoMayOpenJournals(): void
    {
        $id = $this->proposal();

        $this->get('/accounting_ai/proposal/' . $id)->assertDontSee('accounting_journals/view/1"');

        $this->actAsWith(['accounting_ai', 'accounting_journals']);

        $this->get('/accounting_ai/proposal/' . $id)->assertSee('accounting_journals/view/1"');
    }

    public function testGetProposal_OffersApproveAndRejectOnlyToWhoMayDecide(): void
    {
        $id = $this->proposal();

        $page = $this->get('/accounting_ai/proposal/' . $id);

        $page->assertSee('id="ai_approve"');
        $page->assertSee('id="ai_reject"');

        $this->actAsWith(['accounting_ai']);

        $reader = $this->get('/accounting_ai/proposal/' . $id);

        $reader->assertDontSee('id="ai_approve"');
        $reader->assertDontSee('id="ai_reject"');

        $this->actAsWith(['accounting_ai', AccountingGuard::AI_APPROVE]);

        $noPoster = $this->get('/accounting_ai/proposal/' . $id);

        $noPoster->assertDontSee('id="ai_approve"');
        $noPoster->assertSee('id="ai_reject"');
    }

    public function testGetProposal_OffersNothingToDecideOnADecidedOrAnExpiredProposalOrWhileTheAgentIsOff(): void
    {
        $approved = $this->proposal('a');
        $this->proposals->approve($approved, $this->approver);
        $rejected = $this->proposal('b');
        $this->proposals->reject($rejected, 'No', $this->approver);
        $expired = $this->proposal('c');
        $this->accountingDb()->table('accounting_ai_proposals')->where('proposal_id', $expired)->update(['expires_at' => '2020-01-01 00:00:00']);

        foreach ([$approved, $rejected, $expired] as $id) {
            $page = $this->get('/accounting_ai/proposal/' . $id);

            $page->assertDontSee('id="ai_approve"');
            $page->assertDontSee('id="ai_reject"');
        }

        $standing = $this->proposal('d');
        $this->setConfigValue('accounting_ai_enabled', '0');

        $page = $this->get('/accounting_ai/proposal/' . $standing);

        $page->assertDontSee('id="ai_approve"');
        $page->assertSee('id="ai_reject"');
        $page->assertSee('id="ai_off"');
    }

    public function testGetProposal_ShowsTheDecisionTheJournalAndTheReasonOfARejection(): void
    {
        $approved = $this->proposal('a');
        $this->proposals->approve($approved, $this->approver);
        $rejected = $this->proposal('b');
        $this->proposals->reject($rejected, 'Paid by cheque', $this->approver);

        $one = $this->get('/accounting_ai/proposal/' . $approved);
        $two = $this->get('/accounting_ai/proposal/' . $rejected);

        $one->assertSee('id="ai_journal_link"');
        $two->assertSee('id="ai_reject_reason"');
        $two->assertSee('Paid by cheque');
    }

    public function testGetProposal_ShowsWhyASupersededProposalWasLeftOut(): void
    {
        $id = $this->proposal();
        $this->accountingDb()->table('accounting_accounts')->where('account_id', $this->rentExpense())->update(['active' => 0]);

        try {
            $this->proposals->approve($id, $this->approver);
        } catch (Throwable) {
            $this->addToAssertionCount(1);
        }

        $page = $this->get('/accounting_ai/proposal/' . $id);

        $page->assertSee('id="ai_stale_reason"');
        $page->assertSee(lang('Accounting_ai.stale_accounts_changed'));
    }

    public function testGetProposal_IsNotFoundOutsideTheBranchScopeAndForAnUnknownProposal(): void
    {
        $branch = $this->createBranch();
        $id     = $this->proposal('other', $branch);

        $this->actAsWith(['accounting_ai'], [1]);

        try {
            $this->get('/accounting_ai/proposal/' . $id);
            $this->fail('The proposal of another branch opened.');
        } catch (PageNotFoundException) {
            $this->addToAssertionCount(1);
        }

        $this->expectException(PageNotFoundException::class);

        $this->get('/accounting_ai/proposal/999999');
    }

    public function testGetApproveForm_CarriesTheTokenAgainstForgedRequestsAndAConfirmationWhenTheProtectionIsOn(): void
    {
        $id = $this->proposal();

        service('filters')->enableFilters(['csrf'], 'before');

        try {
            $html = view('accounting_ai/approve_form', ['proposal' => $this->proposals->find($id, $this->approver)]);
        } finally {
            Services::resetSingle('filters');
        }

        $this->assertStringContainsString('name="' . csrf_token() . '"', $html);
        $this->assertStringContainsString('name="confirm"', $html);
        $this->assertStringContainsString('accounting_ai/approve/' . $id, $html);
    }

    public function testGetRejectForm_CarriesTheTokenAndAReasonWhenTheProtectionIsOn(): void
    {
        $id = $this->proposal();

        service('filters')->enableFilters(['csrf'], 'before');

        try {
            $html = view('accounting_ai/reject_form', ['proposal' => $this->proposals->find($id, $this->approver)]);
        } finally {
            Services::resetSingle('filters');
        }

        $this->assertStringContainsString('name="' . csrf_token() . '"', $html);
        $this->assertStringContainsString('name="reason"', $html);
    }

    public function testGetDecideForm_CarriesTheTokenAndAReasonWhenTheProtectionIsOn(): void
    {
        $id = $this->finding();

        service('filters')->enableFilters(['csrf'], 'before');

        try {
            $html = view('accounting_ai/decide_form', ['finding' => (new FindingService())->find($id, $this->approver)]);
        } finally {
            Services::resetSingle('filters');
        }

        $this->assertStringContainsString('name="' . csrf_token() . '"', $html);
        $this->assertStringContainsString('name="reason"', $html);
        $this->assertStringContainsString('name="decision"', $html);
    }

    public function testEveryChangeIsAPostThatTheGlobalProtectionAgainstForgedRequestsCovers(): void
    {
        $globals = (new ReflectionClass(Filters::class))->getDefaultProperties()['globals'];

        $this->assertArrayHasKey('csrf', $globals['before']);

        foreach (['approve/5', 'reject/5', 'propose', 'scan', 'decide/5', 'settings', 'disable'] as $uri) {
            $this->assertSame(0, preg_match('#^(' . $globals['before']['csrf']['except'] . ')#', 'accounting_ai/' . $uri), "{$uri} is not excepted");
        }
    }

    public function testPostApprove_NeedsTheConfirmationAndPostsNothingWithout(): void
    {
        $id = $this->proposal();

        $result = $this->json($this->post('/accounting_ai/approve/' . $id));

        $this->assertFalse($result['success']);
        $this->assertSame(lang('Accounting_ai.confirm_required'), $result['message']);
        $this->assertSame(0, $this->aiJournals());
    }

    public function testPostApprove_PostsTheJournalOfTheProposalAndSaysWhereToGo(): void
    {
        $id = $this->proposal();

        $result = $this->json($this->post('/accounting_ai/approve/' . $id, ['confirm' => '1']));

        $this->assertTrue($result['success'], $result['message']);
        $this->assertSame(base_url('accounting_ai/proposal/' . $id), $result['redirect']);
        $this->assertSame(1, $this->aiJournals());

        $journal = $this->accountingDb()->table('accounting_journals')->where('journal_id', $result['journal_id'])->get()->getRow();

        $this->assertSame('ai', $journal->origin);
        $this->assertSame($this->approver, (int) $journal->posted_by);
    }

    public function testPostApprove_RefusesASecondApprovalInWords(): void
    {
        $id = $this->proposal();

        $this->post('/accounting_ai/approve/' . $id, ['confirm' => '1']);
        $result = $this->json($this->post('/accounting_ai/approve/' . $id, ['confirm' => '1']));

        $this->assertFalse($result['success']);
        $this->assertSame(lang('Accounting.error_ai_proposal_decided'), $result['message']);
        $this->assertSame(1, $this->aiJournals());
    }

    public function testPostApprove_RefusesWhoMayNotApproveInWords(): void
    {
        $id = $this->proposal();

        $this->actAsWith(['accounting_ai', AccountingGuard::JOURNALS_POST]);

        $result = $this->json($this->post('/accounting_ai/approve/' . $id, ['confirm' => '1']));

        $this->assertFalse($result['success']);
        $this->assertSame(lang('Accounting.error_permission_denied'), $result['message']);
        $this->assertSame(0, $this->aiJournals());
    }

    public function testPostApprove_RefusesWhileTheAgentIsSwitchedOffInWords(): void
    {
        $id = $this->proposal();
        $this->setConfigValue('accounting_ai_enabled', '0');

        $result = $this->json($this->post('/accounting_ai/approve/' . $id, ['confirm' => '1']));

        $this->assertFalse($result['success']);
        $this->assertSame(lang('Accounting.error_ai_disabled'), $result['message']);
        $this->assertSame(0, $this->aiJournals());
    }

    public function testPostApprove_ExplainsAProposalTheBooksHaveLeftBehind(): void
    {
        $id = $this->proposal();
        $this->closeThrough('2026-10-31');

        $result = $this->json($this->post('/accounting_ai/approve/' . $id, ['confirm' => '1']));

        $this->assertFalse($result['success']);
        $this->assertSame(lang('Accounting.error_ai_proposal_stale'), $result['message']);
        $this->assertSame('superseded', $this->accountingDb()->table('accounting_ai_proposals')->where('proposal_id', $id)->get()->getRow()->status);
    }

    public function testPostApprove_RefusesAProposalOfAnotherBranch(): void
    {
        $branch = $this->createBranch();
        $id     = $this->proposal('other', $branch);

        $this->actAsWith(['accounting_ai', AccountingGuard::AI_APPROVE, AccountingGuard::JOURNALS_POST], [1]);

        $result = $this->json($this->post('/accounting_ai/approve/' . $id, ['confirm' => '1']));

        $this->assertFalse($result['success']);
        $this->assertSame(0, $this->aiJournals());
    }

    public function testPostReject_KeepsTheRecordAndNeedsAReason(): void
    {
        $id = $this->proposal();

        $without = $this->json($this->post('/accounting_ai/reject/' . $id, ['reason' => '  ']));

        $this->assertFalse($without['success']);
        $this->assertSame('proposed', $this->accountingDb()->table('accounting_ai_proposals')->where('proposal_id', $id)->get()->getRow()->status);

        $result = $this->json($this->post('/accounting_ai/reject/' . $id, ['reason' => 'Paid by cheque']));

        $this->assertTrue($result['success'], $result['message']);

        $row = $this->accountingDb()->table('accounting_ai_proposals')->where('proposal_id', $id)->get()->getRow();

        $this->assertSame('rejected', $row->status);
        $this->assertSame('Paid by cheque', $row->reject_reason);
        $this->assertSame(0, $this->aiJournals());
    }

    public function testGetApproveFormAndGetRejectForm_AreNotFoundForADecidedProposalAndForWhoMayNotDecide(): void
    {
        $id = $this->proposal();
        $this->proposals->reject($id, 'No', $this->approver);

        foreach (['approve_form', 'reject_form'] as $form) {
            try {
                $this->get('/accounting_ai/' . $form . '/' . $id);
                $this->fail('The form of a decided proposal opened.');
            } catch (PageNotFoundException) {
                $this->addToAssertionCount(1);
            }
        }

        $open = $this->proposal('open');
        $this->actAsWith(['accounting_ai']);

        $this->expectException(PageNotFoundException::class);

        $this->get('/accounting_ai/approve_form/' . $open);
    }

    public function testPostPropose_MakesTheProposalsOfTheRulesAndReportsTheFigures(): void
    {
        foreach (['2026-08-05', '2026-09-05', '2026-10-05'] as $date) {
            $this->manualJournal($date, $this->rentExpense(), $this->cashAccount(), '2000.00', 'Office rent');
        }

        $before = $this->journalCount();
        $result = $this->json($this->post('/accounting_ai/propose'));

        $this->assertTrue($result['success'], $result['message']);
        $this->assertSame($before, $this->journalCount());
        $this->assertSame(1, (int) $this->accountingDb()->table('accounting_ai_proposals')->countAllResults());
        $this->assertSame(base_url('accounting_ai'), $result['redirect']);
    }

    public function testPostPropose_RefusesWhileTheAgentIsSwitchedOffInWords(): void
    {
        $this->setConfigValue('accounting_ai_enabled', '0');

        $result = $this->json($this->post('/accounting_ai/propose'));

        $this->assertFalse($result['success']);
        $this->assertSame(lang('Accounting.error_ai_disabled'), $result['message']);
    }

    public function testPostScan_KeepsTheFindingsAndSaysWhereToGo(): void
    {
        $this->manualJournal('2026-10-10', $this->rentExpense(), $this->cashAccount(), '25000.00', 'Round one');

        $result = $this->json($this->post('/accounting_ai/scan'));

        $this->assertTrue($result['success'], $result['message']);
        $this->assertSame(base_url('accounting_ai/findings'), $result['redirect']);
        $this->assertSame(1, (int) $this->accountingDb()->table('accounting_ai_findings')->where('rule_code', 'AI-ROUND')->countAllResults());
    }

    public function testPostScan_RefusesWhileTheAgentIsSwitchedOffInWords(): void
    {
        $this->setConfigValue('accounting_ai_enabled', '0');

        $result = $this->json($this->post('/accounting_ai/scan'));

        $this->assertFalse($result['success']);
        $this->assertSame(lang('Accounting.error_ai_disabled'), $result['message']);
        $this->assertSame(0, (int) $this->accountingDb()->table('accounting_ai_findings')->countAllResults());
    }

    public function testGetFindings_ListsTheFindingsWithTheirEvidenceAndFilters(): void
    {
        $round     = $this->finding('AI-ROUND');
        $duplicate = $this->finding('AI-DUPLICATE');
        $dismissed = $this->finding('AI-ROUND', 1, 'dismissed', 'Budgeted');

        $page = $this->get('/accounting_ai/findings');

        $page->assertStatus(200);
        $page->assertSee('id="ai_finding_' . $round . '"');
        $page->assertSee('id="ai_finding_' . $duplicate . '"');
        $page->assertSee('Budgeted');
        $page->assertSee('id="ai_findings_note"');
        $page->assertSee('id="ai_scan"');
        $page->assertSee('evidence-row');

        $byRule = $this->get('/accounting_ai/findings?rule=AI-DUPLICATE');

        $byRule->assertSee('id="ai_finding_' . $duplicate . '"');
        $byRule->assertDontSee('id="ai_finding_' . $round . '"');

        $byStatus = $this->get('/accounting_ai/findings?status=dismissed');

        $byStatus->assertSee('id="ai_finding_' . $dismissed . '"');
        $byStatus->assertDontSee('id="ai_finding_' . $round . '"');
    }

    public function testGetFindings_KeepsToTheBranchesOfTheReaderAndReadsWhileTheAgentIsSwitchedOff(): void
    {
        $branch = $this->createBranch();
        $mine   = $this->finding('AI-ROUND', 1);
        $other  = $this->finding('AI-ROUND', $branch);

        $this->actAsWith(['accounting_ai'], [1]);
        $this->setConfigValue('accounting_ai_enabled', '0');

        $page = $this->get('/accounting_ai/findings');

        $page->assertSee('id="ai_finding_' . $mine . '"');
        $page->assertDontSee('id="ai_finding_' . $other . '"');
        $page->assertSee('id="ai_off"');
        $page->assertDontSee('id="ai_scan"');
    }

    public function testGetFindingsCsv_GivesTheFilteredFindingsAndNeutralisesWhatASpreadsheetWouldReadAsAFormula(): void
    {
        $this->finding('AI-ROUND', 1, 'dismissed', '=HYPERLINK("http://example.test")');
        $this->finding('AI-DUPLICATE');

        $all  = $this->csvRows($this->get('/accounting_ai/findings/csv'));
        $only = $this->csvRows($this->get('/accounting_ai/findings/csv?rule=AI-ROUND'));

        $this->assertCount(3, $all);
        $this->assertSame(lang('Accounting_ai.finding_id'), $all[0][0]);
        $this->assertCount(2, $only);
        $this->assertSame('AI-ROUND', $only[1][1]);
        $this->assertSame("'=HYPERLINK(\"http://example.test\")", $only[1][10]);
        $this->assertStringContainsString('journal_id=', $only[1][7]);
    }

    public function testGetFindingsCsv_KeepsToTheBranchesOfTheReaderAndIsAFileDownload(): void
    {
        $branch = $this->createBranch();
        $this->finding('AI-ROUND', 1);
        $this->finding('AI-ROUND', $branch);

        $this->actAsWith(['accounting_ai'], [1]);

        $response = $this->get('/accounting_ai/findings/csv');

        $this->assertCount(2, $this->csvRows($response));
        $this->assertStringContainsString('attachment; filename="ai_findings_', $response->response()->getHeaderLine('Content-Disposition'));
        $this->assertStringContainsString('text/csv', $response->response()->getHeaderLine('Content-Type'));
    }

    public function testPostDecide_ConfirmsOrDismissesAnOpenFindingWithAReasonOnce(): void
    {
        $id = $this->finding();

        $without = $this->json($this->post('/accounting_ai/decide/' . $id, ['decision' => 'confirmed', 'reason' => '']));

        $this->assertFalse($without['success']);

        $result = $this->json($this->post('/accounting_ai/decide/' . $id, ['decision' => 'confirmed', 'reason' => 'A real mistake']));

        $this->assertTrue($result['success'], $result['message']);
        $this->assertSame('confirmed', $this->accountingDb()->table('accounting_ai_findings')->where('finding_id', $id)->get()->getRow()->status);

        $again = $this->json($this->post('/accounting_ai/decide/' . $id, ['decision' => 'dismissed', 'reason' => 'Changed my mind']));

        $this->assertFalse($again['success']);
        $this->assertSame(lang('Accounting.error_ai_finding_decided'), $again['message']);
    }

    public function testPostDecide_RefusesADecisionThatIsNeitherOfTheTwo(): void
    {
        $id = $this->finding();

        $result = $this->json($this->post('/accounting_ai/decide/' . $id, ['decision' => 'approved', 'reason' => 'Fine']));

        $this->assertFalse($result['success']);
        $this->assertSame(lang('Accounting.error_ai_decision_invalid'), $result['message']);
    }

    public function testGetDecideForm_IsNotFoundForADecidedFindingAndForOneOutsideTheScope(): void
    {
        $branch  = $this->createBranch();
        $decided = $this->finding('AI-ROUND', 1, 'dismissed');
        $other   = $this->finding('AI-ROUND', $branch);

        foreach ([$decided] as $id) {
            try {
                $this->get('/accounting_ai/decide_form/' . $id);
                $this->fail('The form of a decided finding opened.');
            } catch (PageNotFoundException) {
                $this->addToAssertionCount(1);
            }
        }

        $this->actAsWith(['accounting_ai'], [1]);

        $this->expectException(PageNotFoundException::class);

        $this->get('/accounting_ai/decide_form/' . $other);
    }

    public function testGetSettings_ShowsTheStateAndTheThresholdsWithTheirDefaultsAndTheFormToWhoManagesTheSettings(): void
    {
        $this->actAsWith(['accounting_ai', AccountingGuard::SETTINGS_MANAGE]);

        $page = $this->get('/accounting_ai/settings');

        $page->assertStatus(200);
        $page->assertSee('id="ai_state"');
        $page->assertSee(lang('Accounting_ai.state_on'));
        $page->assertSee('id="ai_settings_form"');
        $page->assertSee('name="threshold[duplicate_days]"');
        $page->assertSee('id="ai_disable"');

        foreach (array_keys(AiSettings::THRESHOLDS) as $name) {
            $page->assertSee('id="t_' . $name . '"');
        }
    }

    public function testGetSettings_ShowsTheThresholdsReadOnlyAndTheKillSwitchToWhoUsesTheAgent(): void
    {
        $page = $this->get('/accounting_ai/settings');

        $page->assertStatus(200);
        $page->assertDontSee('id="ai_settings_form"');
        $page->assertSee('id="ai_thresholds_readonly"');
        $page->assertSee('id="ai_disable"');
    }

    public function testPostSettings_RequiresThePermissionToChangeTheSettings(): void
    {
        $result = $this->json($this->post('/accounting_ai/settings', ['enabled' => '1', 'threshold' => ['duplicate_days' => '5']]));

        $this->assertFalse($result['success']);
        $this->assertSame(lang('Accounting.error_permission_denied'), $result['message']);
        $this->assertSame(3, (new AiSettings())->int('duplicate_days'));
    }

    public function testPostSettings_SavesTheThresholdsAndTheSwitchAndWritesTheAuditEntries(): void
    {
        $this->actAsWith(['accounting_ai', AccountingGuard::SETTINGS_MANAGE]);
        $this->setConfigValue('accounting_ai_enabled', '0');

        $result = $this->json($this->post('/accounting_ai/settings', ['enabled' => '1', 'threshold' => ['duplicate_days' => '5', 'round_unit' => '500']]));

        $this->assertTrue($result['success'], $result['message']);

        $settings = new AiSettings();

        $this->assertTrue($settings->isEnabled());
        $this->assertSame(5, $settings->int('duplicate_days'));
        $this->assertSame('500.0000', $settings->value('round_unit'));
        $this->assertSame(1, (int) $this->accountingDb()->table('accounting_audit_log')->where('action', 'ai_enabled')->countAllResults());
        $this->assertSame(1, (int) $this->accountingDb()->table('accounting_audit_log')->where('action', 'ai_settings_updated')->countAllResults());
    }

    public function testPostSettings_RefusesAValueOutsideWhatAThresholdTakesInWords(): void
    {
        $this->actAsWith(['accounting_ai', AccountingGuard::SETTINGS_MANAGE]);

        $result = $this->json($this->post('/accounting_ai/settings', ['enabled' => '1', 'threshold' => ['duplicate_days' => 'many']]));

        $this->assertFalse($result['success']);
        $this->assertStringContainsString('duplicate_days', $result['message']);
        $this->assertSame(3, (new AiSettings())->int('duplicate_days'));
    }

    public function testPostDisable_SwitchesTheAgentOffAtOnceForWhoUsesIt(): void
    {
        $id = $this->proposal();

        $result = $this->json($this->post('/accounting_ai/disable'));

        $this->assertTrue($result['success'], $result['message']);
        $this->assertFalse((new AiSettings())->isEnabled());

        $approve = $this->json($this->post('/accounting_ai/approve/' . $id, ['confirm' => '1']));

        $this->assertFalse($approve['success']);
        $this->assertSame(0, $this->aiJournals());
        $this->assertSame(1, (int) $this->accountingDb()->table('accounting_audit_log')->where('action', 'ai_disabled')->countAllResults());
    }
}
