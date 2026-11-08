<?php

namespace Tests\Libraries\Accounting\Ai;

use App\Libraries\Accounting\AccountingError;
use App\Libraries\Accounting\AccountingException;
use App\Libraries\Accounting\AccountingGuard;
use App\Libraries\Accounting\Ai\ProposalDraft;
use App\Libraries\Accounting\Ai\ProposalService;
use App\Libraries\Accounting\Ai\ProposalStatus;
use App\Libraries\Accounting\Ai\ProposalText;
use App\Libraries\Accounting\EntityAccountService;
use App\Libraries\Accounting\JournalLineDraft;
use App\Libraries\Accounting\JournalOrigin;
use App\Libraries\Accounting\JournalService;
use App\Libraries\Accounting\JournalSettings;
use App\Libraries\Accounting\PeriodLocks;
use App\Libraries\Accounting\PostingError;
use App\Libraries\Accounting\PostingException;
use App\Libraries\Organization\BranchException;
use CodeIgniter\Database\Exceptions\DatabaseException;
use CodeIgniter\Events\Events;
use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\DatabaseTestTrait;
use Config\Database;
use Tests\Support\AiFixtureTrait;

/**
 * The proposals: how one is made and checked as a manual journal is, that nothing reaches the journals until a
 * person approves, that an approval posts what was proposed once, that a proposal the books have left behind is
 * superseded, and that the master switch stops the creation and the approval at once.
 *
 * @internal
 */
final class ProposalServiceTest extends CIUnitTestCase
{
    use DatabaseTestTrait;
    use AiFixtureTrait;

    protected $migrate     = true;
    protected $migrateOnce = true;
    protected $refresh     = false;
    protected $namespace   = null;
    private ProposalService $service;
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
        $this->service  = new ProposalService();
        $this->approver = $this->agentEmployee();
    }

    protected function tearDown(): void
    {
        $this->stopAgent();

        parent::tearDown();
    }

    private function proposalRow(int $id): object
    {
        return $this->accountingDb()->table('accounting_ai_proposals')->where('proposal_id', $id)->get()->getRow();
    }

    private function audit(string $action): array
    {
        return $this->accountingDb()->table('accounting_audit_log')->where('action', $action)->orderBy('audit_id', 'ASC')->get()->getResult();
    }

    private function aiJournals(): int
    {
        return (int) $this->accountingDb()->table('accounting_journals')->where('source_type', ProposalService::SOURCE)->countAllResults();
    }

    /**
     * @param array<string, scalar>|null $args
     */
    private function withChange(ProposalDraft $draft, ?string $proposer = null, ?string $key = null, ?string $description = null, ?array $args = null): ProposalDraft
    {
        return new ProposalDraft($draft->kind, $proposer ?? $draft->proposer, $key ?? $draft->dedupeKey, $draft->date, $draft->branchId, $description ?? $draft->description, $draft->lines, $draft->evidence, $args ?? $draft->templateArgs, $draft->subjectJournals);
    }

    private function refused(callable $call): AccountingException
    {
        try {
            $call();
        } catch (AccountingException $failure) {
            return $failure;
        }

        $this->fail('The call was not refused.');
    }

    public function testCreate_WritesTheProposalAsProposedAndNothingToTheJournals(): void
    {
        $before = $this->journalCount();
        $id     = $this->service->create($this->proposalDraft('500.00', 'rent-1'), $this->approver);
        $row    = $this->proposalRow($id);

        $this->assertSame($before, $this->journalCount());
        $this->assertSame(0, $this->aiJournals());
        $this->assertSame('proposed', $row->status);
        $this->assertSame('recurring', $row->kind);
        $this->assertSame('rules:test', $row->proposer);
        $this->assertSame($this->approver, (int) $row->requested_by);
        $this->assertNull($row->journal_id);
        $this->assertNull($row->decided_by);
        $this->assertSame('2026-10-21', $row->proposal_date);
        $this->assertSame(1, (int) $row->branch_id);
        $this->assertNotNull($row->expires_at);
        $this->assertSame(64, strlen((string) $row->fingerprint));

        $payload = json_decode((string) $row->payload, true);

        $this->assertSame('500.0000', $payload['lines'][0]['debit']);
        $this->assertSame($this->rentExpense(), $payload['lines'][0]['account_id']);
        $this->assertSame('500.0000', $payload['lines'][1]['credit']);
        $this->assertCount(1, json_decode((string) $row->evidence, true));
    }

    public function testCreate_FillsTheTitleAndTheRationaleFromTheTemplateOfTheKind(): void
    {
        $id  = $this->service->create($this->proposalDraft('500.00', 'rent-1'), $this->approver);
        $row = $this->proposalRow($id);

        $this->assertStringContainsString('500.00', $row->title);
        $this->assertStringContainsString('5201 Rent', $row->title);
        $this->assertStringContainsString('Nothing is posted until a person approves', (string) $row->rationale);
    }

    public function testCreate_WritesAnAuditEntryWithTheAgentAsOrigin(): void
    {
        $id      = $this->service->create($this->proposalDraft('500.00', 'rent-1'), $this->approver);
        $entries = $this->audit('proposal_created');

        $this->assertCount(1, $entries);
        $this->assertSame('ai_proposal', $entries[0]->entity_type);
        $this->assertSame($id, (int) $entries[0]->entity_id);
        $this->assertSame(JournalOrigin::Ai->value, $entries[0]->origin);
        $this->assertSame($this->approver, (int) $entries[0]->employee_id);
        $this->assertSame(1, (int) $entries[0]->branch_id);
        $this->assertSame('500.0000', json_decode((string) $entries[0]->details, true)['total']);
    }

    public function testCreate_AcceptsTheSystemWithoutAnEmployee(): void
    {
        $id = $this->service->create($this->proposalDraft());

        $this->assertNull($this->proposalRow($id)->requested_by);
    }

    public function testCreate_RejectsWhenTheAgentIsSwitchedOff(): void
    {
        $this->setConfigValue('accounting_ai_enabled', '0');

        $failure = $this->refused(fn () => $this->service->create($this->proposalDraft(), $this->approver));

        $this->assertSame(AccountingError::AiDisabled, $failure->error);
        $this->assertSame(0, $this->countRows('accounting_ai_proposals'));
    }

    public function testCreate_RequiresThePermissionToUseTheAgent(): void
    {
        $nobody = $this->employeeWithGrants([AccountingGuard::JOURNALS_POST]);

        $failure = $this->refused(fn () => $this->service->create($this->proposalDraft(), $nobody));

        $this->assertSame(AccountingError::PermissionDenied, $failure->error);
        $this->assertSame(0, $this->countRows('accounting_ai_proposals'));
    }

    public function testCreate_RejectsAnUnbalancedJournal(): void
    {
        $draft = new ProposalDraft('recurring', 'rules:test', 'k', '2026-10-21', 1, 'x', [new JournalLineDraft($this->rentExpense(), debit: '10.00'), new JournalLineDraft($this->cashAccount(), credit: '9.00')], [['type' => 'journal', 'journal_id' => 1]], []);

        try {
            $this->service->create($draft, $this->approver);
            $this->fail('An unbalanced journal was accepted.');
        } catch (PostingException $failure) {
            $this->assertSame(PostingError::Unbalanced, $failure->error);
        }

        $this->assertSame(0, $this->countRows('accounting_ai_proposals'));
    }

    public function testCreate_RejectsAnInactiveAccountAndAGroup(): void
    {
        $inactive = $this->customAccount('11', '1110', 'Closed account');
        $this->accountingDb()->table('accounting_accounts')->where('account_id', $inactive)->update(['active' => 0]);

        foreach ([$inactive, $this->accountIdByCode('52')] as $account) {
            $draft = new ProposalDraft('recurring', 'rules:test', 'k' . $account, '2026-10-21', 1, 'x', [new JournalLineDraft($account, debit: '10.00'), new JournalLineDraft($this->cashAccount(), credit: '10.00')], [['type' => 'journal', 'journal_id' => 1]], []);

            try {
                $this->service->create($draft, $this->approver);
                $this->fail('An account that does not take entries was accepted.');
            } catch (PostingException $failure) {
                $this->assertSame(PostingError::AccountNotPostable, $failure->error);
            }
        }
    }

    public function testCreate_RejectsADateInAClosedPeriod(): void
    {
        $this->closeThrough('2026-10-31');

        try {
            $this->service->create($this->proposalDraft('500.00', 'k', '2026-10-21'), $this->approver);
            $this->fail('A closed period was accepted.');
        } catch (PostingException $failure) {
            $this->assertSame(PostingError::PeriodClosed, $failure->error);
        }

        $this->assertSame(0, $this->countRows('accounting_ai_proposals'));
    }

    public function testCreate_RejectsAnInactiveBranch(): void
    {
        $branch = $this->createBranch('B2', 'Closed branch', false);

        try {
            $this->service->create($this->proposalDraft('500.00', 'k', '2026-10-21', $branch), $this->approver);
            $this->fail('An inactive branch was accepted.');
        } catch (PostingException $failure) {
            $this->assertSame(PostingError::BranchInactive, $failure->error);
        }
    }

    public function testCreate_RejectsABranchOutsideTheScopeOfTheEmployee(): void
    {
        $branch = $this->createBranch();
        $local  = $this->agentEmployee([], [1]);

        $this->expectException(BranchException::class);

        $this->service->create($this->proposalDraft('500.00', 'k', '2026-10-21', $branch), $local);
    }

    public function testCreate_RejectsALineOnTheAccountOfACustomerOfASupplierOrOfAStockLocation(): void
    {
        $customer = $this->insertCustomer();
        $supplier = $this->insertSupplier();
        $location = $this->insertStockLocation();
        $entities = new EntityAccountService();
        $entities->fill();

        foreach ([
            (int) $entities->accountFor('customer', $customer),
            (int) $entities->accountFor('supplier', $supplier),
            (int) $entities->accountFor('stock_location', $location),
        ] as $index => $account) {
            $draft = new ProposalDraft('recurring', 'rules:test', 'control' . $index, '2026-10-21', 1, 'x', [new JournalLineDraft($account, debit: '10.00'), new JournalLineDraft($this->cashAccount(), credit: '10.00')], [['type' => 'journal', 'journal_id' => 1]], []);

            $failure = $this->refused(fn () => $this->service->create($draft, $this->approver));

            $this->assertSame(AccountingError::AiControlAccount, $failure->error, "Account {$index} is a control account.");
        }

        $this->assertSame(0, $this->countRows('accounting_ai_proposals'));
    }

    public function testCreate_RejectsAForeignAmount(): void
    {
        $draft = new ProposalDraft('recurring', 'rules:test', 'k', '2026-10-21', 1, 'x', [new JournalLineDraft($this->rentExpense(), debit: '10.00', foreignAmount: '3.00', currencyCode: 'USD'), new JournalLineDraft($this->cashAccount(), credit: '10.00')], [['type' => 'journal', 'journal_id' => 1]], []);

        $this->assertSame(AccountingError::AiProposalInvalid, $this->refused(fn () => $this->service->create($draft, $this->approver))->error);
    }

    public function testCreate_RejectsAProposalWithoutEvidence(): void
    {
        $draft = $this->proposalDraft();
        $bare  = new ProposalDraft($draft->kind, $draft->proposer, $draft->dedupeKey, $draft->date, $draft->branchId, $draft->description, $draft->lines, [], $draft->templateArgs);

        $this->assertSame(AccountingError::AiProposalInvalid, $this->refused(fn () => $this->service->create($bare, $this->approver))->error);
    }

    public function testCreate_RejectsAKindThatIsNotAWord(): void
    {
        $draft = $this->proposalDraft('500.00', 'k', '2026-10-21', 1, 'Bad Kind!');

        $this->assertSame(AccountingError::AiProposalInvalid, $this->refused(fn () => $this->service->create($draft, $this->approver))->error);
    }

    public function testCreate_RejectsAProposerWithoutAName(): void
    {
        $draft = $this->proposalDraft();
        $bare  = new ProposalDraft($draft->kind, '', $draft->dedupeKey, $draft->date, $draft->branchId, $draft->description, $draft->lines, $draft->evidence, $draft->templateArgs);

        $this->assertSame(AccountingError::AiProposalInvalid, $this->refused(fn () => $this->service->create($bare, $this->approver))->error);
    }

    public function testCreate_AcceptsAProposerNameOfSixtyFourCharactersAndRejectsOneOfSixtyFive(): void
    {
        $id = $this->service->create($this->withChange($this->proposalDraft('500.00', 'long-1'), proposer: str_repeat('p', 64)), $this->approver);

        $this->assertSame(str_repeat('p', 64), $this->proposalRow($id)->proposer);
        $this->assertSame(AccountingError::AiProposalInvalid, $this->refused(fn () => $this->service->create($this->withChange($this->proposalDraft('500.00', 'long-2'), proposer: str_repeat('p', 65)), $this->approver))->error);
        $this->assertSame(1, $this->countRows('accounting_ai_proposals'));
    }

    public function testCreate_RejectsAProposalWithoutAKey(): void
    {
        $this->assertSame(AccountingError::AiProposalInvalid, $this->refused(fn () => $this->service->create($this->withChange($this->proposalDraft(), key: ''), $this->approver))->error);
        $this->assertSame(0, $this->countRows('accounting_ai_proposals'));
    }

    public function testCreate_AcceptsATitleOfTheLargestLengthAndRejectsALongerOne(): void
    {
        $draft = $this->proposalDraft('500.00', 'title-1');
        $room  = ProposalService::TITLE_MAX - mb_strlen(ProposalText::fill('title', $draft->kind, [...$draft->templateArgs, 'account' => '']));

        $id = $this->service->create($this->withChange($draft, args: [...$draft->templateArgs, 'account' => str_repeat('x', $room)]), $this->approver);

        $this->assertSame(ProposalService::TITLE_MAX, mb_strlen($this->proposalRow($id)->title));
        $this->assertSame(AccountingError::AiProposalInvalid, $this->refused(fn () => $this->service->create($this->withChange($this->proposalDraft('500.00', 'title-2'), args: [...$draft->templateArgs, 'account' => str_repeat('x', $room + 1)]), $this->approver))->error);
        $this->assertSame(1, $this->countRows('accounting_ai_proposals'));
    }

    public function testCreate_AcceptsATextOfTheLargestLengthAndRejectsALongerOne(): void
    {
        $id = $this->service->create($this->withChange($this->proposalDraft('500.00', 'text-1'), description: str_repeat('d', ProposalService::TEXT_MAX)), $this->approver);

        $this->assertSame(ProposalService::TEXT_MAX, mb_strlen((string) json_decode((string) $this->proposalRow($id)->payload, true)['description']));
        $this->assertSame(AccountingError::AiProposalInvalid, $this->refused(fn () => $this->service->create($this->withChange($this->proposalDraft('500.00', 'text-2'), description: str_repeat('d', ProposalService::TEXT_MAX + 1)), $this->approver))->error);
        $this->assertSame(1, $this->countRows('accounting_ai_proposals'));
    }

    public function testCreate_RejectsTheSameProposalWhileItStandsAndAfterItWasRejected(): void
    {
        $id = $this->service->create($this->proposalDraft('500.00', 'rent-1'), $this->approver);

        $this->assertSame(AccountingError::AiProposalDuplicate, $this->refused(fn () => $this->service->create($this->proposalDraft('500.00', 'rent-1'), $this->approver))->error);

        $this->service->reject($id, 'Not needed', $this->approver);

        $this->assertSame(AccountingError::AiProposalDuplicate, $this->refused(fn () => $this->service->create($this->proposalDraft('500.00', 'rent-1'), $this->approver))->error);
        $this->assertSame(1, $this->countRows('accounting_ai_proposals'));
    }

    public function testCreate_AcceptsADifferentKeyAndADifferentKindWithTheSameKey(): void
    {
        $this->service->create($this->proposalDraft('500.00', 'rent-1'), $this->approver);
        $this->service->create($this->proposalDraft('500.00', 'rent-2'), $this->approver);
        $this->service->create($this->proposalDraft('500.00', 'rent-1', '2026-10-21', 1, 'reclassify'), $this->approver);

        $this->assertSame(3, $this->countRows('accounting_ai_proposals'));
    }

    public function testCreate_RequiresADescriptionOnEveryLineWhenTheBooksAsk(): void
    {
        $this->setConfigValue('accounting_line_description_required', '1');

        $draft = new ProposalDraft('recurring', 'rules:test', 'k', '2026-10-21', 1, 'x', [new JournalLineDraft($this->rentExpense(), debit: '10.00', description: 'Rent'), new JournalLineDraft($this->cashAccount(), credit: '10.00')], [['type' => 'journal', 'journal_id' => 1]], []);

        $failure = $this->refused(fn () => $this->service->create($draft, $this->approver));

        $this->assertSame(AccountingError::LineDescriptionRequired, $failure->error);
        $this->assertSame(2, $failure->context['line']);
    }

    public function testCreate_RequiresACostCenterOnIncomeAndExpenseLinesWhenTheBooksAsk(): void
    {
        $this->setConfigValue(JournalSettings::CENTER_REQUIRED, '1');

        $failure = $this->refused(fn () => $this->service->create($this->proposalDraft(), $this->approver));

        $this->assertSame(AccountingError::CostCenterRequired, $failure->error);
        $this->assertSame(1, $failure->context['line']);
    }

    public function testCreate_AsksForACostCenterOnNoLineOfAnAccountThatIsNotIncomeOrExpense(): void
    {
        $this->setConfigValue(JournalSettings::CENTER_REQUIRED, '1');
        $this->accountingDb()->table('accounting_cost_centers')->insert(['code' => 'CC1', 'name' => 'Shop', 'active' => 1]);
        $center = (int) $this->accountingDb()->insertID();
        $draft  = $this->proposalDraft('500.00', 'center-1');
        $lines  = [new JournalLineDraft($this->rentExpense(), debit: '500.00', description: 'Rent', costCenterId: $center), new JournalLineDraft($this->cashAccount(), credit: '500.00', description: 'Rent')];

        $id = $this->service->create(new ProposalDraft($draft->kind, $draft->proposer, $draft->dedupeKey, $draft->date, $draft->branchId, $draft->description, $lines, $draft->evidence, $draft->templateArgs), $this->approver);

        $this->assertSame('proposed', $this->proposalRow($id)->status);
    }

    public function testCreate_RejectsASubjectJournalThatIsNotPosted(): void
    {
        $draft = $this->proposalDraft();
        $bare  = new ProposalDraft($draft->kind, $draft->proposer, $draft->dedupeKey, $draft->date, $draft->branchId, $draft->description, $draft->lines, $draft->evidence, $draft->templateArgs, [999999]);

        $this->assertSame(AccountingError::AiProposalInvalid, $this->refused(fn () => $this->service->create($bare, $this->approver))->error);
    }

    public function testCreate_SetsTheExpiryFromTheSetting(): void
    {
        $this->setThreshold('proposal_expiry_days', '3');

        $id  = $this->service->create($this->proposalDraft(), $this->approver);
        $row = $this->accountingDb()->query('SELECT DATEDIFF(expires_at, created_at) AS days FROM ' . $this->accountingDb()->prefixTable('accounting_ai_proposals') . ' WHERE proposal_id = ?', [$id])->getRow();

        $this->assertSame(3, (int) $row->days);
    }

    public function testApprove_PostsExactlyTheProposedLinesOnceWithTheAgentAsOriginAndTheApproverAsEmployee(): void
    {
        $id      = $this->service->create($this->proposalDraft('500.00', 'rent-1'), $this->agentEmployee());
        $journal = $this->service->approve($id, $this->approver);
        $row     = $this->accountingDb()->table('accounting_journals')->where('journal_id', $journal)->get()->getRow();
        $lines   = $this->accountingDb()->table('accounting_journal_lines')->where('journal_id', $journal)->orderBy('line_no')->get()->getResult();

        $this->assertSame('posted', $row->status);
        $this->assertSame('ai', $row->origin);
        $this->assertSame(ProposalService::SOURCE, $row->source_type);
        $this->assertSame($id, (int) $row->source_id);
        $this->assertSame($this->approver, (int) $row->posted_by);
        $this->assertSame($this->approver, (int) $row->created_by);
        $this->assertSame('2026-10-21', $row->journal_date);
        $this->assertSame('Rent proposed', $row->description);
        $this->assertSame('500.0000', $row->total_debit);
        $this->assertSame(1, (int) $row->branch_id);
        $this->assertCount(2, $lines);
        $this->assertSame($this->rentExpense(), (int) $lines[0]->account_id);
        $this->assertSame('500.0000', $lines[0]->debit);
        $this->assertSame($this->cashAccount(), (int) $lines[1]->account_id);
        $this->assertSame('500.0000', $lines[1]->credit);
        $this->assertSame(1, $this->aiJournals());
    }

    public function testApprove_MarksTheProposalApprovedWithTheJournalAndTheApprover(): void
    {
        $id      = $this->service->create($this->proposalDraft(), $this->approver);
        $journal = $this->service->approve($id, $this->approver);
        $row     = $this->proposalRow($id);

        $this->assertSame('approved', $row->status);
        $this->assertSame($journal, (int) $row->journal_id);
        $this->assertSame($this->approver, (int) $row->decided_by);
        $this->assertNotNull($row->decided_at);
    }

    public function testApprove_WritesAnAuditEntryForTheDecisionAndOneForTheJournal(): void
    {
        $id      = $this->service->create($this->proposalDraft(), $this->approver);
        $journal = $this->service->approve($id, $this->approver);
        $decided = $this->audit('proposal_approved');
        $posted  = $this->accountingDb()->table('accounting_audit_log')->where(['entity_type' => 'journal', 'entity_id' => $journal, 'action' => 'posted'])->get()->getResult();

        $this->assertCount(1, $decided);
        $this->assertSame($this->approver, (int) $decided[0]->employee_id);
        $this->assertSame($journal, json_decode((string) $decided[0]->details, true)['journal_id']);
        $this->assertSame(JournalOrigin::User->value, $decided[0]->origin);
        $this->assertCount(1, $posted);
        $this->assertSame('ai', $posted[0]->origin);
        $this->assertSame($this->approver, (int) $posted[0]->employee_id);
    }

    public function testApprove_TakesThePeriodLocksOfItsBranchBeforeItLocksTheProposal(): void
    {
        $branch = $this->createBranch();
        $id     = $this->service->create($this->proposalDraft('500.00', 'k', '2026-10-21', $branch), $this->approver);
        $seen   = [];

        $listener = static function ($query) use (&$seen): void {
            $sql = (string) $query->getQuery();

            if (str_contains($sql, 'accounting_period_locks') && (str_contains($sql, 'LOCK IN SHARE MODE') || str_contains($sql, 'FOR UPDATE'))) {
                $seen[] = 'period';
            } elseif (str_contains($sql, 'accounting_ai_proposals') && str_contains($sql, 'FOR UPDATE')) {
                $seen[] = 'proposal';
            }
        };

        Events::on('DBQuery', $listener);

        try {
            $this->service->approve($id, $this->approver);
        } finally {
            Events::removeListener('DBQuery', $listener);
        }

        $this->assertSame('period', $seen[0]);
        $this->assertContains('proposal', $seen);
        $this->assertLessThan(array_search('proposal', $seen, true), array_search('period', $seen, true));
        $this->assertSame([$branch], $this->periodLocksTakenBy(function () use ($branch): void {
            $other = $this->service->create($this->proposalDraft('500.00', 'second', '2026-10-21', $branch), $this->approver);
            $this->service->approve($other, $this->approver);
        }));
    }

    public function testApprove_WaitsForAClosingThatHoldsTheLocksOfThePeriodsInsteadOfPostingPastIt(): void
    {
        $id   = $this->service->create($this->proposalDraft(), $this->approver);
        $main = $this->accountingDb();

        $main->resetTransStatus();
        $main->transStart();
        (new PeriodLocks($main))->holdBranch(1);

        try {
            $this->assertWaitsForLock(fn () => (new ProposalService($this->openSecondConnection()))->approve($id, $this->approver));
        } finally {
            $main->transRollback();
            $main->resetTransStatus();
        }

        $this->assertSame(0, $this->aiJournals());
        $this->assertSame('proposed', $this->proposalRow($id)->status);
    }

    public function testCreate_RefusesTheSameProposalWhereTheDatabaseGivesAFailedQueryBackInsteadOfAnException(): void
    {
        $this->service->create($this->proposalDraft('500.00', 'once'), $this->approver);

        $second = new ProposalService($this->openSecondConnection(false));

        $failure = $this->refused(fn () => $second->create($this->proposalDraft('500.00', 'once'), $this->approver));

        $this->assertSame(AccountingError::AiProposalDuplicate, $failure->error);
        $this->assertSame(1, $this->countRows('accounting_ai_proposals'));
    }

    public function testApprove_RefusesAProposalThatVanishedBetweenTheReadAndTheLock(): void
    {
        $id = $this->service->create($this->proposalDraft(), $this->approver);

        $this->assertSame(AccountingError::AiProposalNotFound, $this->refused(fn () => $this->vanishingAfterFirstLock($id, fn () => $this->service->approve($id, $this->approver)))->error);
        $this->assertSame(0, $this->aiJournals());
    }

    public function testReject_RefusesAProposalThatVanishedBetweenTheReadAndTheLock(): void
    {
        $id = $this->service->create($this->proposalDraft(), $this->approver);

        $this->assertSame(AccountingError::AiProposalNotFound, $this->refused(fn () => $this->vanishingAfterHead($id, fn () => $this->service->reject($id, 'No', $this->approver)))->error);
    }

    private function vanishingAfterFirstLock(int $id, callable $action): mixed
    {
        return $this->vanishingWhen($id, $action, 'accounting_period_locks');
    }

    private function vanishingAfterHead(int $id, callable $action): mixed
    {
        return $this->vanishingWhen($id, $action, '`proposal_date`');
    }

    private function vanishingWhen(int $id, callable $action, string $marker): mixed
    {
        $gone     = false;
        $listener = function ($query) use ($id, &$gone, $marker): void {
            if (! $gone && str_contains((string) $query->getQuery(), $marker)) {
                $gone = true;
                $this->openSecondConnection()->table('accounting_ai_proposals')->where('proposal_id', $id)->delete();
            }
        };

        Events::on('DBQuery', $listener);

        try {
            return $action();
        } finally {
            Events::removeListener('DBQuery', $listener);
        }
    }

    public function testApprove_RefusesASecondApprovalAndPostsNoSecondJournal(): void
    {
        $id = $this->service->create($this->proposalDraft(), $this->approver);
        $this->service->approve($id, $this->approver);

        $failure = $this->refused(fn () => $this->service->approve($id, $this->approver));

        $this->assertSame(AccountingError::AiProposalDecided, $failure->error);
        $this->assertSame(1, $this->aiJournals());
    }

    public function testApprove_PostsOnceEvenWhenTheStatusIsPutBackByHand(): void
    {
        $id = $this->service->create($this->proposalDraft(), $this->approver);
        $this->service->approve($id, $this->approver);
        $this->accountingDb()->table('accounting_ai_proposals')->where('proposal_id', $id)->update(['status' => 'proposed', 'journal_id' => null]);

        $failure = $this->refused(fn () => $this->service->approve($id, $this->approver));

        $this->assertSame(AccountingError::AiProposalDecided, $failure->error);
        $this->assertSame(1, $this->aiJournals());
    }

    public function testApprove_RefusesWithoutAPerson(): void
    {
        $id = $this->service->create($this->proposalDraft(), $this->approver);

        $failure = $this->refused(fn () => $this->service->approve($id, null));

        $this->assertSame(AccountingError::AiApproverRequired, $failure->error);
        $this->assertSame(0, $this->aiJournals());
        $this->assertSame('proposed', $this->proposalRow($id)->status);
    }

    public function testApprove_RequiresThePermissionToApprove(): void
    {
        $id     = $this->service->create($this->proposalDraft(), $this->approver);
        $reader = $this->employeeWithGrants([AccountingGuard::AI_USE, AccountingGuard::JOURNALS_POST]);

        $failure = $this->refused(fn () => $this->service->approve($id, $reader));

        $this->assertSame(AccountingError::PermissionDenied, $failure->error);
        $this->assertSame(0, $this->aiJournals());
    }

    public function testApprove_RequiresThePermissionToPostJournals(): void
    {
        $id       = $this->service->create($this->proposalDraft(), $this->approver);
        $noPoster = $this->employeeWithGrants([AccountingGuard::AI_USE, AccountingGuard::AI_APPROVE]);

        $failure = $this->refused(fn () => $this->service->approve($id, $noPoster));

        $this->assertSame(AccountingError::PermissionDenied, $failure->error);
        $this->assertSame(0, $this->aiJournals());
    }

    public function testApprove_RefusesWhenTheAgentIsSwitchedOffAndPostsNothing(): void
    {
        $id = $this->service->create($this->proposalDraft(), $this->approver);
        $this->setConfigValue('accounting_ai_enabled', '0');

        $failure = $this->refused(fn () => $this->service->approve($id, $this->approver));

        $this->assertSame(AccountingError::AiDisabled, $failure->error);
        $this->assertSame(0, $this->aiJournals());
        $this->assertSame('proposed', $this->proposalRow($id)->status);
    }

    public function testApprove_RefusesAProposalOfABranchOutsideTheScopeOfTheApprover(): void
    {
        $branch = $this->createBranch();
        $id     = $this->service->create($this->proposalDraft('500.00', 'k', '2026-10-21', $branch), $this->approver);
        $local  = $this->agentEmployee([], [1]);

        $failure = $this->refused(fn () => $this->service->approve($id, $local));

        $this->assertSame(AccountingError::AiProposalNotFound, $failure->error);
        $this->assertSame(0, $this->aiJournals());
    }

    public function testApprove_PostsAProposalOfABranchInsideTheScopeOfTheApprover(): void
    {
        $branch = $this->createBranch();
        $id     = $this->service->create($this->proposalDraft('500.00', 'k', '2026-10-21', $branch), $this->approver);
        $local  = $this->agentEmployee([], [$branch]);

        $journal = $this->service->approve($id, $local);

        $this->assertSame($branch, (int) $this->accountingDb()->table('accounting_journals')->where('journal_id', $journal)->get()->getRow()->branch_id);
    }

    public function testApprove_RefusesAnExpiredProposalAndMarksItExpired(): void
    {
        $id = $this->service->create($this->proposalDraft(), $this->approver);
        $this->accountingDb()->table('accounting_ai_proposals')->where('proposal_id', $id)->update(['expires_at' => '2020-01-01 00:00:00']);

        $failure = $this->refused(fn () => $this->service->approve($id, $this->approver));
        $row     = $this->proposalRow($id);

        $this->assertSame(AccountingError::AiProposalExpired, $failure->error);
        $this->assertSame('expired', $row->status);
        $this->assertNull($row->dedupe_key);
        $this->assertSame(0, $this->aiJournals());
        $this->assertCount(1, $this->audit('proposal_expired'));
    }

    public function testApprove_RefusesAProposalWhoseAccountWasSwitchedOffAndMarksItSuperseded(): void
    {
        $id = $this->service->create($this->proposalDraft(), $this->approver);
        $this->accountingDb()->table('accounting_accounts')->where('account_id', $this->rentExpense())->update(['active' => 0]);

        $failure = $this->refused(fn () => $this->service->approve($id, $this->approver));
        $row     = $this->proposalRow($id);

        $this->assertSame(AccountingError::AiProposalStale, $failure->error);
        $this->assertSame('superseded', $row->status);
        $this->assertSame('accounts_changed', $row->stale_reason);
        $this->assertNull($row->dedupe_key);
        $this->assertSame(0, $this->aiJournals());
        $this->assertCount(1, $this->audit('proposal_superseded'));
    }

    public function testApprove_RefusesAProposalWhoseAccountChangedItsTypeAndMarksItSuperseded(): void
    {
        $id = $this->service->create($this->proposalDraft(), $this->approver);
        $this->accountingDb()->table('accounting_accounts')->where('account_id', $this->rentExpense())->update(['account_type' => 'asset']);

        $this->assertSame(AccountingError::AiProposalStale, $this->refused(fn () => $this->service->approve($id, $this->approver))->error);
        $this->assertSame('accounts_changed', $this->proposalRow($id)->stale_reason);
        $this->assertSame(0, $this->aiJournals());
    }

    public function testApprove_RefusesAProposalWhoseAccountBecameTheAccountOfACustomer(): void
    {
        $id = $this->service->create($this->proposalDraft(), $this->approver);
        $this->accountingDb()->table('accounting_accounts')->where('account_id', $this->rentExpense())->update(['entity_type' => 'customer', 'entity_id' => 987654]);

        $this->assertSame(AccountingError::AiProposalStale, $this->refused(fn () => $this->service->approve($id, $this->approver))->error);
        $this->assertSame(0, $this->aiJournals());
    }

    public function testApprove_RefusesAProposalWhosePeriodWasClosedAndMarksItSuperseded(): void
    {
        $id = $this->service->create($this->proposalDraft('500.00', 'k', '2026-10-21'), $this->approver);
        $this->closeThrough('2026-10-31');

        $failure = $this->refused(fn () => $this->service->approve($id, $this->approver));
        $row     = $this->proposalRow($id);

        $this->assertSame(AccountingError::AiProposalStale, $failure->error);
        $this->assertSame('superseded', $row->status);
        $this->assertSame('period_closed', $row->stale_reason);
        $this->assertSame(0, $this->aiJournals());
    }

    public function testApprove_RefusesAProposalWhoseBranchWasSwitchedOffAndMarksItSuperseded(): void
    {
        $branch = $this->createBranch();
        $id     = $this->service->create($this->proposalDraft('500.00', 'k', '2026-10-21', $branch), $this->approver);
        $this->accountingDb()->table('branches')->where('branch_id', $branch)->update(['active' => 0]);

        $this->assertSame(AccountingError::AiProposalStale, $this->refused(fn () => $this->service->approve($id, $this->approver))->error);
        $this->assertSame('branch_changed', $this->proposalRow($id)->stale_reason);
        $this->assertSame(0, $this->aiJournals());
    }

    public function testApprove_RefusesAProposalWhoseSubjectJournalWasReversed(): void
    {
        $subject = $this->manualJournal('2026-10-05', $this->otherExpenses(), $this->cashAccount(), '300.00', 'Subject');
        $draft   = $this->proposalDraft();
        $with    = new ProposalDraft($draft->kind, $draft->proposer, $draft->dedupeKey, $draft->date, $draft->branchId, $draft->description, $draft->lines, $draft->evidence, $draft->templateArgs, [$subject]);
        $id      = $this->service->create($with, $this->approver);

        (new JournalService())->reverse($subject, '2026-10-06', 'Wrong', null);

        $this->assertSame(AccountingError::AiProposalStale, $this->refused(fn () => $this->service->approve($id, $this->approver))->error);
        $this->assertSame('subject_changed', $this->proposalRow($id)->stale_reason);
        $this->assertSame(0, $this->aiJournals());
    }

    public function testApprove_PostsAProposalWhoseSubjectJournalStillStands(): void
    {
        $subject = $this->manualJournal('2026-10-05', $this->otherExpenses(), $this->cashAccount(), '300.00', 'Subject');
        $draft   = $this->proposalDraft();
        $with    = new ProposalDraft($draft->kind, $draft->proposer, $draft->dedupeKey, $draft->date, $draft->branchId, $draft->description, $draft->lines, $draft->evidence, $draft->templateArgs, [$subject]);
        $id      = $this->service->create($with, $this->approver);

        $this->assertGreaterThan(0, $this->service->approve($id, $this->approver));
    }

    public function testApprove_RefusesAProposalWhosePayloadWasChangedAfterItWasChecked(): void
    {
        $id      = $this->service->create($this->proposalDraft('500.00', 'k'), $this->approver);
        $payload = json_decode((string) $this->proposalRow($id)->payload, true);

        $payload['lines'][0]['debit']  = '900.0000';
        $payload['lines'][1]['credit'] = '900.0000';
        $this->accountingDb()->table('accounting_ai_proposals')->where('proposal_id', $id)->update(['payload' => json_encode($payload)]);

        $failure = $this->refused(fn () => $this->service->approve($id, $this->approver));

        $this->assertSame(AccountingError::AiProposalInvalid, $failure->error);
        $this->assertSame(0, $this->aiJournals());
        $this->assertSame('proposed', $this->proposalRow($id)->status);
    }

    public function testApprove_RefusesAnApproverWhoMayNotWithdrawFromATreasuryTheJournalCredits(): void
    {
        $other    = $this->employeeWithGrants([]);
        $treasury = $this->treasuryAccountOnlyFor($other);
        $draft    = new ProposalDraft('recurring', 'rules:test', 'k', '2026-10-21', 1, 'x', [new JournalLineDraft($this->rentExpense(), debit: '50.00', description: 'Rent'), new JournalLineDraft($treasury, credit: '50.00', description: 'Rent')], [['type' => 'journal', 'journal_id' => 1]], []);
        $id       = $this->service->create($draft, $this->approver);

        $failure = $this->refused(fn () => $this->service->approve($id, $this->approver));

        $this->assertSame(0, $this->aiJournals());
        $this->assertSame('proposed', $this->proposalRow($id)->status);
        $this->assertNotSame(AccountingError::AiProposalStale, $failure->error);
    }

    public function testApprove_RefusesAProposalThatDoesNotExist(): void
    {
        $this->assertSame(AccountingError::AiProposalNotFound, $this->refused(fn () => $this->service->approve(424242, $this->approver))->error);
    }

    public function testReject_KeepsTheRecordWithTheReasonAndWhoDecided(): void
    {
        $id = $this->service->create($this->proposalDraft(), $this->approver);

        $this->service->reject($id, '  Already paid by cheque  ', $this->approver);

        $row = $this->proposalRow($id);

        $this->assertSame('rejected', $row->status);
        $this->assertSame('Already paid by cheque', $row->reject_reason);
        $this->assertSame($this->approver, (int) $row->decided_by);
        $this->assertNotNull($row->decided_at);
        $this->assertNull($row->journal_id);
        $this->assertNotNull($row->dedupe_key);
        $this->assertSame(0, $this->aiJournals());
        $this->assertCount(1, $this->audit('proposal_rejected'));
        $this->assertSame('Already paid by cheque', json_decode((string) $this->audit('proposal_rejected')[0]->details, true)['reason']);
    }

    public function testReject_RequiresAReasonOfAtMostTwoHundredFiftyFiveCharacters(): void
    {
        $id = $this->service->create($this->proposalDraft(), $this->approver);

        foreach (['', '   ', str_repeat('x', 256)] as $reason) {
            $this->assertSame(AccountingError::ReasonRequired, $this->refused(fn () => $this->service->reject($id, $reason, $this->approver))->error);
        }

        $this->service->reject($id, str_repeat('x', 255), $this->approver);

        $this->assertSame('rejected', $this->proposalRow($id)->status);
    }

    public function testReject_RequiresThePermissionToApprove(): void
    {
        $id     = $this->service->create($this->proposalDraft(), $this->approver);
        $reader = $this->employeeWithGrants([AccountingGuard::AI_USE]);

        $this->assertSame(AccountingError::PermissionDenied, $this->refused(fn () => $this->service->reject($id, 'No', $reader))->error);
        $this->assertSame('proposed', $this->proposalRow($id)->status);
    }

    public function testReject_RefusesWithoutAPersonAndADecidedProposal(): void
    {
        $id = $this->service->create($this->proposalDraft(), $this->approver);

        $this->assertSame(AccountingError::AiApproverRequired, $this->refused(fn () => $this->service->reject($id, 'No', null))->error);

        $this->service->reject($id, 'No', $this->approver);

        $this->assertSame(AccountingError::AiProposalDecided, $this->refused(fn () => $this->service->reject($id, 'Again', $this->approver))->error);
        $this->assertSame(AccountingError::AiProposalDecided, $this->refused(fn () => $this->service->approve($id, $this->approver))->error);
    }

    public function testReject_WorksWhileTheAgentIsSwitchedOff(): void
    {
        $id = $this->service->create($this->proposalDraft(), $this->approver);
        $this->setConfigValue('accounting_ai_enabled', '0');

        $this->service->reject($id, 'Closing the agent down', $this->approver);

        $this->assertSame('rejected', $this->proposalRow($id)->status);
    }

    public function testReject_RefusesAProposalOfABranchOutsideTheScope(): void
    {
        $branch = $this->createBranch();
        $id     = $this->service->create($this->proposalDraft('500.00', 'k', '2026-10-21', $branch), $this->approver);
        $local  = $this->agentEmployee([], [1]);

        $this->assertSame(AccountingError::AiProposalNotFound, $this->refused(fn () => $this->service->reject($id, 'No', $local))->error);
        $this->assertSame('proposed', $this->proposalRow($id)->status);
    }

    public function testExpireDue_ExpiresTheProposalsPastTheirTimeAndFreesTheirPlace(): void
    {
        $due     = $this->service->create($this->proposalDraft('500.00', 'due'), $this->approver);
        $notDue  = $this->service->create($this->proposalDraft('500.00', 'not-due'), $this->approver);
        $decided = $this->service->create($this->proposalDraft('500.00', 'decided'), $this->approver);
        $this->service->reject($decided, 'No', $this->approver);
        $this->accountingDb()->table('accounting_ai_proposals')->whereIn('proposal_id', [$due, $decided])->update(['expires_at' => '2020-01-01 00:00:00']);

        $this->assertSame(1, $this->service->expireDue());

        $this->assertSame('expired', $this->proposalRow($due)->status);
        $this->assertNull($this->proposalRow($due)->dedupe_key);
        $this->assertSame('proposed', $this->proposalRow($notDue)->status);
        $this->assertSame('rejected', $this->proposalRow($decided)->status);
        $this->assertSame(0, $this->service->expireDue());

        $entries = $this->audit('proposal_expired');

        $this->assertCount(1, $entries);
        $this->assertNull($entries[0]->employee_id);
        $this->assertSame('system', $entries[0]->origin);

        $this->assertGreaterThan($due, $this->service->create($this->proposalDraft('500.00', 'due'), $this->approver));
    }

    public function testFind_ReturnsTheProposalWithItsEvidenceAndTheLinesOfTheJournal(): void
    {
        $id  = $this->service->create($this->proposalDraft('500.00', 'rent-1'), $this->approver);
        $row = $this->service->find($id, $this->approver);

        $this->assertSame($id, (int) $row->proposal_id);
        $this->assertSame('HQ', $row->branch_code);
        $this->assertCount(1, $row->evidence_rows);
        $this->assertSame('000001', $row->evidence_rows[0]['number']);
        $this->assertCount(2, $row->lines);
        $this->assertSame('5201', $row->lines[0]['account_code']);
        $this->assertStringContainsString('500.00', $row->title_text);
        $this->assertStringContainsString('Nothing is posted', $row->rationale_text);
        $this->assertSame(0, (int) $row->is_expired);
    }

    public function testFind_RefusesAProposalOutsideTheBranchScopeAndAReaderWithoutThePermission(): void
    {
        $branch = $this->createBranch();
        $id     = $this->service->create($this->proposalDraft('500.00', 'k', '2026-10-21', $branch), $this->approver);

        $this->assertSame(AccountingError::AiProposalNotFound, $this->refused(fn () => $this->service->find($id, $this->agentEmployee([], [1])))->error);
        $this->assertSame(AccountingError::PermissionDenied, $this->refused(fn () => $this->service->find($id, $this->employeeWithGrants([])))->error);
    }

    public function testFind_ReadsTheHistoryWhileTheAgentIsSwitchedOff(): void
    {
        $id = $this->service->create($this->proposalDraft(), $this->approver);
        $this->service->approve($id, $this->approver);
        $this->setConfigValue('accounting_ai_enabled', '0');

        $this->assertSame('approved', $this->service->find($id, $this->approver)->status);
        $this->assertSame(1, $this->service->search([], $this->approver)['total']);
        $this->assertSame(1, $this->service->counts($this->approver)['approved']);
    }

    public function testSearch_FiltersByStatusKindAndKeepsToTheBranchesOfTheReader(): void
    {
        $branch = $this->createBranch();
        $one    = $this->service->create($this->proposalDraft('500.00', 'one'), $this->approver);
        $two    = $this->service->create($this->proposalDraft('500.00', 'two', '2026-10-21', $branch, 'reclassify'), $this->approver);
        $this->service->reject($one, 'No', $this->approver);

        $all = $this->service->search([], $this->approver);

        $this->assertSame(2, $all['total']);
        $this->assertSame([$two, $one], array_map(static fn (object $row): int => (int) $row->proposal_id, $all['rows']));
        $this->assertSame([$one], array_map(static fn (object $row): int => (int) $row->proposal_id, $this->service->search(['status' => 'rejected'], $this->approver)['rows']));
        $this->assertSame([$two], array_map(static fn (object $row): int => (int) $row->proposal_id, $this->service->search(['kind' => 'reclassify'], $this->approver)['rows']));
        $this->assertSame(2, $this->service->search(['status' => 'nonsense'], $this->approver)['total']);
        $this->assertSame([$one], array_map(static fn (object $row): int => (int) $row->proposal_id, $this->service->search([], $this->agentEmployee([], [1]))['rows']));
        $this->assertSame(0, $this->service->search([], $this->agentEmployee([], []))['total']);
    }

    public function testSearch_FiltersByTheDayTheProposalWasMade(): void
    {
        $old = $this->service->create($this->proposalDraft('500.00', 'old'), $this->approver);
        $new = $this->service->create($this->proposalDraft('500.00', 'new'), $this->approver);
        $this->accountingDb()->table('accounting_ai_proposals')->where('proposal_id', $old)->update(['created_at' => '2026-09-10 08:00:00']);
        $this->accountingDb()->table('accounting_ai_proposals')->where('proposal_id', $new)->update(['created_at' => '2026-09-12 20:00:00']);

        $ids = static fn (array $result): array => array_map(static fn (object $row): int => (int) $row->proposal_id, $result['rows']);

        $this->assertSame([$new], $ids($this->service->search(['from' => '2026-09-11'], $this->approver)));
        $this->assertSame([$old], $ids($this->service->search(['to' => '2026-09-11'], $this->approver)));
        $this->assertSame([$new, $old], $ids($this->service->search(['from' => '2026-09-10', 'to' => '2026-09-12'], $this->approver)));
        $this->assertSame([$new], $ids($this->service->search(['from' => '2026-09-12'], $this->approver)));
        $this->assertSame([$old], $ids($this->service->search(['to' => '2026-09-10'], $this->approver)));
    }

    public function testSearch_RequiresThePermissionToUseTheAgent(): void
    {
        $this->service->create($this->proposalDraft(), $this->approver);

        $this->assertSame(AccountingError::PermissionDenied, $this->refused(fn () => $this->service->search([], $this->employeeWithGrants([])))->error);
    }

    public function testSearch_TellsAProposalPastItsTimeFromOneThatIsNot(): void
    {
        $late = $this->service->create($this->proposalDraft('500.00', 'late'), $this->approver);
        $live = $this->service->create($this->proposalDraft('500.00', 'live'), $this->approver);
        $this->accountingDb()->table('accounting_ai_proposals')->where('proposal_id', $late)->update(['expires_at' => '2020-01-01 00:00:00']);

        $overdue = [];

        foreach ($this->service->search([], $this->approver)['rows'] as $row) {
            $overdue[(int) $row->proposal_id] = (int) $row->is_expired;
        }

        $this->assertSame([$live => 0, $late => 1], $overdue);
    }

    public function testSearch_GivesAtMostTheLargestPageWhateverIsAsked(): void
    {
        $rows = [];

        for ($index = 0; $index < ProposalService::PAGE_MAX + 20; $index++) {
            $rows[] = ['kind' => 'recurring', 'status' => 'proposed', 'payload' => '{}', 'branch_id' => 1, 'dedupe_key' => hash('sha256', 'page' . $index)];
        }

        $this->accountingDb()->table('accounting_ai_proposals')->insertBatch($rows);

        $result = $this->service->search([], $this->approver, 10000);

        $this->assertSame(ProposalService::PAGE_MAX + 20, $result['total']);
        $this->assertCount(ProposalService::PAGE_MAX, $result['rows']);
        $this->assertCount(1, $this->service->search([], $this->approver, 0)['rows']);
        $this->assertCount(20, $this->service->search([], $this->approver, 100, ProposalService::PAGE_MAX)['rows']);
        $this->assertCount(ProposalService::PAGE_MAX, $this->service->search([], $this->approver, 500, -5)['rows']);
    }

    public function testCounts_CountsTheProposalsOfEachStatusTheReaderMaySee(): void
    {
        $branch = $this->createBranch();
        $one    = $this->service->create($this->proposalDraft('500.00', 'one'), $this->approver);
        $this->service->create($this->proposalDraft('500.00', 'two', '2026-10-21', $branch), $this->approver);
        $this->service->reject($one, 'No', $this->approver);

        $this->assertSame(['proposed' => 1, 'approved' => 0, 'rejected' => 1, 'expired' => 0, 'superseded' => 0], $this->service->counts($this->approver));
        $this->assertSame(['proposed' => 0, 'approved' => 0, 'rejected' => 1, 'expired' => 0, 'superseded' => 0], $this->service->counts($this->agentEmployee([], [1])));
    }

    public function testCounts_RequiresThePermissionToUseTheAgent(): void
    {
        $this->assertSame(AccountingError::PermissionDenied, $this->refused(fn () => $this->service->counts($this->employeeWithGrants([])))->error);
    }

    public function testKinds_ListsTheKindsThatHaveProposals(): void
    {
        $this->service->create($this->proposalDraft('500.00', 'one'), $this->approver);
        $this->service->create($this->proposalDraft('500.00', 'two', '2026-10-21', 1, 'reclassify'), $this->approver);

        $this->assertSame(['reclassify', 'recurring'], $this->service->kinds());
    }

    public function testTable_RefusesAStatusOfNoneOfTheFiveAndAnApprovedProposalWithoutAJournal(): void
    {
        $id = $this->service->create($this->proposalDraft(), $this->approver);

        foreach ([['status' => 'done'], ['status' => 'approved'], ['journal_id' => 1]] as $change) {
            try {
                $this->accountingDb()->table('accounting_ai_proposals')->where('proposal_id', $id)->update($change);
                $this->fail('The table accepted ' . json_encode($change));
            } catch (DatabaseException) {
                $this->assertSame('proposed', $this->proposalRow($id)->status);
            }
        }
    }

    public function testStatus_NamesTheFiveStates(): void
    {
        $this->assertSame(['proposed', 'approved', 'rejected', 'expired', 'superseded'], array_map(static fn (ProposalStatus $status): string => $status->value, ProposalStatus::cases()));
    }
}
