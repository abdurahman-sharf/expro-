<?php

namespace App\Libraries\Accounting\Ai;

use App\Libraries\Accounting\AccountingError;
use App\Libraries\Accounting\AccountingException;
use App\Libraries\Accounting\AccountingGuard;
use App\Libraries\Accounting\AccountingSettings;
use App\Libraries\Accounting\AuditLog;
use App\Libraries\Accounting\EntityAccountService;
use App\Libraries\Accounting\JournalDraft;
use App\Libraries\Accounting\JournalLineDraft;
use App\Libraries\Accounting\JournalOrigin;
use App\Libraries\Accounting\JournalSettings;
use App\Libraries\Accounting\JournalStatus;
use App\Libraries\Accounting\LockedRead;
use App\Libraries\Accounting\PeriodLocks;
use App\Libraries\Accounting\PostingException;
use App\Libraries\Accounting\PostingService;
use App\Libraries\Accounting\RunsInTransaction;
use App\Libraries\Accounting\TreasuryScopes;
use App\Libraries\Organization\BranchGuard;
use CodeIgniter\Database\BaseConnection;
use CodeIgniter\Database\RawSql;
use Config\Database;

/**
 * The agent proposes and a person decides. A proposal is a journal written down as a draft in the proposals
 * table: it is checked as a manual journal is checked, and nothing is written to the journals until a person
 * who may approve it does so, when it is posted through the posting service with the agent as its origin and the
 * approver as the employee. A proposal posts once. One whose accounts, branch or period changed since it was made
 * is not posted and is marked superseded. Creating and approving stop at once when the master switch is off;
 * reading the history, and rejecting, do not.
 */
final class ProposalService
{
    use RunsInTransaction;

    public const SOURCE        = 'ai_proposal';
    public const TITLE_MAX     = 255;
    public const TEXT_MAX      = 2000;
    public const REASON_MAX    = 255;
    public const PAGE_MAX      = 200;
    private const KIND_PATTERN = '/^[a-z][a-z0-9_]{0,31}$/';
    private const CONTROL      = [EntityAccountService::CUSTOMER, EntityAccountService::SUPPLIER, EntityAccountService::STOCK_LOCATION];

    private BaseConnection $db;
    private AuditLog $audit;
    private AccountingGuard $guard;
    private PostingService $posting;
    private BranchGuard $branches;
    private AiSettings $settings;

    public function __construct(?BaseConnection $db = null, ?AccountingGuard $guard = null, ?PostingService $posting = null, ?BranchGuard $branches = null, ?AiSettings $settings = null)
    {
        $this->db       = $db ?? Database::connect();
        $this->audit    = new AuditLog($this->db);
        $this->guard    = $guard ?? new AccountingGuard();
        $this->posting  = $posting ?? new PostingService($this->db);
        $this->branches = $branches ?? new BranchGuard($this->db);
        $this->settings = $settings ?? new AiSettings($this->db, $this->guard);
    }

    /**
     * Writes a proposal after checking its journal as a manual journal is checked. No journal is written.
     *
     * @throws AccountingException|PostingException
     */
    public function create(ProposalDraft $draft, ?int $employeeId = null): int
    {
        $this->settings->assertEnabled();
        $this->guard->require(AccountingGuard::AI_USE, $employeeId);

        $this->assertShape($draft);

        $lines    = $this->normalizedLines($draft->lines);
        $journal  = $this->journalOf($draft->date, $lines, $draft->description, $draft->branchId, JournalDraft::SOURCE_MANUAL, null, $employeeId);
        $prepared = $this->posting->validate($journal);
        $branchId = $prepared['branch_id'];

        $this->branches->requireBranch($branchId, $employeeId);
        $this->assertNotControlAccounts($lines);
        $this->assertLineRules($lines);
        $this->assertSubjectsStand($draft->subjectJournals);

        $core = [
            'date'        => $draft->date,
            'branch_id'   => $branchId,
            'description' => $draft->description,
            'lines'       => $this->canonicalLines($prepared['lines']),
        ];

        $payload = $core + [
            'subject_journals' => array_values(array_unique(array_map('intval', $draft->subjectJournals))),
            'snapshot'         => $this->snapshot($core['lines'], $branchId, $draft->subjectJournals),
        ];

        $key = hash('sha256', $draft->kind . '|' . $draft->dedupeKey);

        return $this->transactional(function () use ($draft, $core, $payload, $key, $employeeId, $branchId, $prepared): int {
            $values = [
                'kind'          => $draft->kind,
                'status'        => ProposalStatus::Proposed->value,
                'title'         => ProposalText::fill('title', $draft->kind, $draft->templateArgs),
                'rationale'     => ProposalText::fill('rationale', $draft->kind, $draft->templateArgs),
                'template_args' => json_encode($draft->templateArgs, JSON_UNESCAPED_UNICODE),
                'proposer'      => $draft->proposer,
                'payload'       => json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
                'evidence'      => json_encode($draft->evidence, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
                'proposal_date' => $draft->date,
                'fingerprint'   => self::fingerprintOf($core),
                'dedupe_key'    => $key,
                'expires_at'    => new RawSql('DATE_ADD(NOW(), INTERVAL ' . $this->settings->int('proposal_expiry_days') . ' DAY)'),
                'requested_by'  => $employeeId,
                'branch_id'     => $branchId,
            ];

            $id = $this->insertOnce($values);

            $this->audit->write('ai_proposal', $id, 'proposal_created', $employeeId, JournalOrigin::Ai, [
                'kind'     => $draft->kind,
                'proposer' => $draft->proposer,
                'total'    => $prepared['debit'],
                'lines'    => count($core['lines']),
                'evidence' => count($draft->evidence),
            ], $branchId);

            return $id;
        });
    }

    /**
     * Posts the journal of a proposal, as proposed, with the agent as origin and the approver as the employee.
     * Only a person can approve, and a proposal posts once. Refused, and marked superseded when it is the
     * books that changed, when the accounts, the branch or the period are no longer what the proposal was
     * checked against.
     *
     * @throws AccountingException|PostingException
     */
    public function approve(int $proposalId, ?int $employeeId): int
    {
        $this->settings->assertEnabled();

        $approver = $this->human($employeeId);

        $this->guard->require(AccountingGuard::AI_APPROVE, $approver);
        $this->guard->require(AccountingGuard::JOURNALS_POST, $approver);

        $head = $this->head($proposalId, $approver);

        $outcome = $this->transactional(function () use ($proposalId, $approver, $head): array {
            (new PeriodLocks($this->db))->shareForPosting((int) $head->branch_id);

            $table = $this->db->prefixTable('accounting_ai_proposals');
            $row   = LockedRead::row($this->db, "SELECT *, (expires_at IS NOT NULL AND expires_at < NOW()) AS is_expired FROM {$table} WHERE proposal_id = ? FOR UPDATE", [$proposalId]);

            if ($row === null) {
                throw new AccountingException(AccountingError::AiProposalNotFound, "Proposal {$proposalId} does not exist.");
            }

            if ($row->status !== ProposalStatus::Proposed->value) {
                throw new AccountingException(AccountingError::AiProposalDecided, 'The proposal was decided already.', ['status' => $row->status]);
            }

            if ((int) $row->is_expired === 1) {
                $this->close($row, ProposalStatus::Expired, null, null, 'proposal_expired', $approver);

                return ['refusal' => new AccountingException(AccountingError::AiProposalExpired, 'The proposal has expired.')];
            }

            $payload = json_decode((string) $row->payload, true);

            if (! is_array($payload) || ! $this->intact($row, $payload)) {
                throw new AccountingException(AccountingError::AiProposalInvalid, 'The proposal no longer matches what was checked when it was made.');
            }

            $journal = $this->journalOf(
                (string) $payload['date'],
                array_map(fn (array $line): JournalLineDraft => $this->lineDraft($line), $payload['lines']),
                (string) $payload['description'],
                (int) $payload['branch_id'],
                self::SOURCE,
                $proposalId,
                $approver,
            );

            $stale = $this->staleReason($payload, $journal);

            if ($stale !== null) {
                $this->close($row, ProposalStatus::Superseded, $stale, null, 'proposal_superseded', $approver);

                return ['refusal' => new AccountingException(AccountingError::AiProposalStale, 'The books changed since the proposal was made.', ['reason' => $stale])];
            }

            (new TreasuryScopes($this->db, $this->branches))->assertMayPost($journal->lines, $approver);

            $result = $this->posting->post($journal);

            if (! $result->created) {
                throw new AccountingException(AccountingError::AiProposalDecided, 'The proposal was posted already.');
            }

            $this->db->table('accounting_ai_proposals')->where('proposal_id', $proposalId)->update([
                'status'     => ProposalStatus::Approved->value,
                'journal_id' => $result->journalId,
                'decided_by' => $approver,
                'decided_at' => new RawSql('NOW()'),
            ]);

            $this->audit->write('ai_proposal', $proposalId, 'proposal_approved', $approver, JournalOrigin::User, [
                'journal_id' => $result->journalId,
                'kind'       => $row->kind,
                'proposer'   => $row->proposer,
            ], (int) $row->branch_id);

            return ['journal' => $result->journalId];
        });

        if (isset($outcome['refusal'])) {
            throw $outcome['refusal'];
        }

        return $outcome['journal'];
    }

    /**
     * Keeps the record and says why. A rejection writes nothing to the journals, so it needs no master switch.
     *
     * @throws AccountingException
     */
    public function reject(int $proposalId, string $reason, ?int $employeeId): void
    {
        $decider = $this->human($employeeId);

        $this->guard->require(AccountingGuard::AI_APPROVE, $decider);

        $reason = trim($reason);

        if ($reason === '' || mb_strlen($reason) > self::REASON_MAX) {
            throw new AccountingException(AccountingError::ReasonRequired, 'A reason of 1 to 255 characters is required.');
        }

        $this->head($proposalId, $decider);

        $this->transactional(function () use ($proposalId, $reason, $decider): void {
            $table = $this->db->prefixTable('accounting_ai_proposals');
            $row   = LockedRead::row($this->db, "SELECT * FROM {$table} WHERE proposal_id = ? FOR UPDATE", [$proposalId]);

            if ($row === null) {
                throw new AccountingException(AccountingError::AiProposalNotFound, "Proposal {$proposalId} does not exist.");
            }

            if ($row->status !== ProposalStatus::Proposed->value) {
                throw new AccountingException(AccountingError::AiProposalDecided, 'The proposal was decided already.', ['status' => $row->status]);
            }

            $this->close($row, ProposalStatus::Rejected, null, $reason, 'proposal_rejected', $decider);
        });
    }

    /**
     * Marks the proposals whose time has come as expired and frees their place, so the same thing can be
     * proposed again.
     *
     * @return int The proposals expired.
     */
    public function expireDue(): int
    {
        $ids = array_column(
            $this->db->table('accounting_ai_proposals')
                ->select('proposal_id')
                ->where('status', ProposalStatus::Proposed->value)
                ->where('expires_at IS NOT NULL AND expires_at < NOW()', null, false)
                ->orderBy('proposal_id', 'ASC')
                ->get()
                ->getResultArray(),
            'proposal_id',
        );

        $expired = 0;

        foreach ($ids as $id) {
            $expired += $this->transactional(function () use ($id): int {
                $table = $this->db->prefixTable('accounting_ai_proposals');
                $row   = LockedRead::row($this->db, "SELECT * FROM {$table} WHERE proposal_id = ? AND status = ? AND expires_at IS NOT NULL AND expires_at < NOW() FOR UPDATE", [(int) $id, ProposalStatus::Proposed->value]);

                if ($row === null) {
                    return 0;
                }

                $this->close($row, ProposalStatus::Expired, null, null, 'proposal_expired', null);

                return 1;
            });
        }

        return $expired;
    }

    /**
     * @return object The proposal with its journal as proposed, its evidence and the names around it.
     *
     * @throws AccountingException
     */
    public function find(int $proposalId, ?int $employeeId): object
    {
        $this->guard->require(AccountingGuard::AI_USE, $employeeId);

        $row = $this->db->table('accounting_ai_proposals p')
            ->select("p.*, br.code AS branch_code, j.number AS journal_number, j.series AS journal_series, TRIM(CONCAT(d.first_name, ' ', d.last_name)) AS decider_name, TRIM(CONCAT(r.first_name, ' ', r.last_name)) AS requester_name, (p.expires_at IS NOT NULL AND p.expires_at < NOW()) AS is_expired", false)
            ->join('branches br', 'br.branch_id = p.branch_id', 'left')
            ->join('accounting_journals j', 'j.journal_id = p.journal_id', 'left')
            ->join('people d', 'd.person_id = p.decided_by', 'left')
            ->join('people r', 'r.person_id = p.requested_by', 'left')
            ->where('p.proposal_id', $proposalId)
            ->get()
            ->getRow();

        if ($row === null || ! $this->branches->canAccess((int) $row->branch_id, $employeeId)) {
            throw new AccountingException(AccountingError::AiProposalNotFound, "Proposal {$proposalId} does not exist.");
        }

        $payload  = json_decode((string) $row->payload, true);
        $payload  = is_array($payload) ? $payload : [];
        $evidence = json_decode((string) $row->evidence, true);

        $row->payload_data   = $payload;
        $row->evidence_rows  = is_array($evidence) ? $evidence : [];
        $row->lines          = $this->describedLines($payload['lines'] ?? []);
        $row->title_text     = ProposalText::fill('title', (string) $row->kind, ProposalText::argsOf($row->template_args), (string) $row->title);
        $row->rationale_text = ProposalText::fill('rationale', (string) $row->kind, ProposalText::argsOf($row->template_args), (string) $row->rationale);

        return $row;
    }

    /**
     * @param array{status?: string, kind?: string, from?: string, to?: string} $filters
     *
     * @return array{total: int, rows: list<object>}
     *
     * @throws AccountingException
     */
    public function search(array $filters, ?int $employeeId, int $limit = 25, int $offset = 0): array
    {
        $this->guard->require(AccountingGuard::AI_USE, $employeeId);

        $allowed = $this->branches->allowedBranchIds($employeeId);
        $builder = $this->db->table('accounting_ai_proposals p')
            ->select('p.proposal_id, p.kind, p.status, p.title, p.template_args, p.proposer, p.proposal_date, p.branch_id, p.created_at, p.decided_at, p.journal_id, br.code AS branch_code, (p.expires_at IS NOT NULL AND p.expires_at < NOW()) AS is_expired', false)
            ->join('branches br', 'br.branch_id = p.branch_id', 'left');

        if ($allowed !== null) {
            $allowed === [] ? $builder->where('1 = 0', null, false) : $builder->whereIn('p.branch_id', $allowed);
        }

        $status = (string) ($filters['status'] ?? '');

        if (ProposalStatus::tryFrom($status) !== null) {
            $builder->where('p.status', $status);
        }

        $kind = (string) ($filters['kind'] ?? '');

        if ($kind !== '') {
            $builder->where('p.kind', $kind);
        }

        foreach (['from' => ['p.created_at >=', ' 00:00:00'], 'to' => ['p.created_at <=', ' 23:59:59']] as $key => [$column, $time]) {
            $date = (string) ($filters[$key] ?? '');

            if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $date) === 1) {
                $builder->where($column, $date . $time);
            }
        }

        $total = $builder->countAllResults(false);
        $rows  = $builder->orderBy('p.proposal_id', 'DESC')->limit(max(1, min($limit, self::PAGE_MAX)), max(0, $offset))->get()->getResult();

        foreach ($rows as $row) {
            $row->title_text = ProposalText::fill('title', (string) $row->kind, ProposalText::argsOf($row->template_args), (string) $row->title);
        }

        return ['total' => $total, 'rows' => $rows];
    }

    /**
     * @return array<string, int> The proposals of each status the employee may see.
     */
    public function counts(?int $employeeId): array
    {
        $this->guard->require(AccountingGuard::AI_USE, $employeeId);

        $allowed = $this->branches->allowedBranchIds($employeeId);
        $builder = $this->db->table('accounting_ai_proposals')->select('status, COUNT(*) AS total')->groupBy('status');

        if ($allowed !== null) {
            $allowed === [] ? $builder->where('1 = 0', null, false) : $builder->whereIn('branch_id', $allowed);
        }

        $counts = array_fill_keys(array_map(static fn (ProposalStatus $status): string => $status->value, ProposalStatus::cases()), 0);

        foreach ($builder->get()->getResult() as $row) {
            $counts[(string) $row->status] = (int) $row->total;
        }

        return $counts;
    }

    /**
     * @return list<string>
     */
    public function kinds(): array
    {
        return array_column($this->db->table('accounting_ai_proposals')->distinct()->select('kind')->orderBy('kind', 'ASC')->get()->getResultArray(), 'kind');
    }

    /**
     * What the proposed journal is fingerprinted from: the date, the branch, the text and the lines.
     *
     * @param array<string, mixed> $core
     */
    public static function fingerprintOf(array $core): string
    {
        return hash('sha256', (string) json_encode($core, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
    }

    /**
     * @throws AccountingException
     */
    private function human(?int $employeeId): int
    {
        if ($employeeId === null) {
            throw new AccountingException(AccountingError::AiApproverRequired, 'A proposal is decided by a person.');
        }

        return $employeeId;
    }

    /**
     * @throws AccountingException
     */
    private function head(int $proposalId, int $employeeId): object
    {
        $row = $this->db->table('accounting_ai_proposals')->select('proposal_id, status, branch_id, proposal_date')->where('proposal_id', $proposalId)->get()->getRow();

        if ($row === null || ! $this->branches->canAccess((int) $row->branch_id, $employeeId)) {
            throw new AccountingException(AccountingError::AiProposalNotFound, "Proposal {$proposalId} does not exist.");
        }

        return $row;
    }

    /**
     * @throws AccountingException
     */
    private function assertShape(ProposalDraft $draft): void
    {
        $title = ProposalText::fill('title', $draft->kind, $draft->templateArgs);

        if (
            preg_match(self::KIND_PATTERN, $draft->kind) !== 1
            || $draft->proposer === '' || mb_strlen($draft->proposer) > 64
            || $draft->dedupeKey === ''
            || mb_strlen($title) > self::TITLE_MAX
            || mb_strlen($draft->description) > self::TEXT_MAX
            || $draft->evidence === []
        ) {
            throw new AccountingException(AccountingError::AiProposalInvalid, 'The proposal needs a kind, a proposer, a key, a text of reasonable length and evidence.');
        }
    }

    /**
     * @param list<JournalLineDraft> $lines
     *
     * @return list<JournalLineDraft>
     *
     * @throws AccountingException
     */
    private function normalizedLines(array $lines): array
    {
        foreach ($lines as $line) {
            if ($line->foreignAmount !== null || $line->currencyCode !== null) {
                throw new AccountingException(AccountingError::AiProposalInvalid, 'A proposal is in the base currency.');
            }
        }

        return array_values($lines);
    }

    /**
     * @param list<JournalLineDraft> $lines
     */
    private function journalOf(string $date, array $lines, string $description, ?int $branchId, string $sourceType, ?int $sourceId, ?int $employeeId): JournalDraft
    {
        return new JournalDraft(
            date: $date,
            lines: $lines,
            sourceType: $sourceType,
            sourceId: $sourceId,
            origin: JournalOrigin::Ai,
            employeeId: $employeeId,
            description: $description,
            branchId: $branchId,
        );
    }

    /**
     * @param list<JournalLineDraft> $lines
     *
     * @throws AccountingException
     */
    private function assertNotControlAccounts(array $lines): void
    {
        $ids = array_values(array_unique(array_map(static fn (JournalLineDraft $line): int => $line->accountId, $lines)));

        if ($ids === []) {
            return;
        }

        $control = $this->db->table('accounting_accounts')->select('account_id, code')->whereIn('account_id', $ids)->whereIn('entity_type', self::CONTROL)->orderBy('account_id', 'ASC')->get()->getRow();

        if ($control !== null) {
            throw new AccountingException(AccountingError::AiControlAccount, "Account {$control->code} belongs to a customer, a supplier or a stock location, which the system accounts own.", ['code' => $control->code]);
        }
    }

    /**
     * @param list<int> $journalIds
     *
     * @throws AccountingException
     */
    private function assertSubjectsStand(array $journalIds): void
    {
        $ids = array_values(array_unique(array_map('intval', $journalIds)));

        if ($ids === []) {
            return;
        }

        $standing = $this->db->table('accounting_journals')->whereIn('journal_id', $ids)->where('status', JournalStatus::Posted->value)->countAllResults();

        if ($standing !== count($ids)) {
            throw new AccountingException(AccountingError::AiProposalInvalid, 'A journal the proposal rests on is not posted.');
        }
    }

    /**
     * What a manual journal must carry when the books ask for it.
     *
     * @param list<JournalLineDraft> $lines
     *
     * @throws AccountingException
     */
    private function assertLineRules(array $lines): void
    {
        if ((new AccountingSettings($this->db))->lineDescriptionRequired()) {
            foreach ($lines as $index => $line) {
                if (trim((string) $line->description) === '') {
                    throw new AccountingException(AccountingError::LineDescriptionRequired, 'Line ' . ($index + 1) . ' needs a description.', ['line' => $index + 1]);
                }
            }
        }

        if (! (new JournalSettings($this->db))->costCenterRequired()) {
            return;
        }

        $ids      = array_values(array_unique(array_map(static fn (JournalLineDraft $line): int => $line->accountId, $lines)));
        $required = array_flip(array_column($this->db->table('accounting_accounts')->select('account_id')->whereIn('account_id', $ids)->whereIn('account_type', ['revenue', 'expense'])->get()->getResultArray(), 'account_id'));

        foreach ($lines as $index => $line) {
            if (isset($required[$line->accountId]) && $line->costCenterId === null) {
                throw new AccountingException(AccountingError::CostCenterRequired, 'Line ' . ($index + 1) . ' needs a cost center.', ['line' => $index + 1]);
            }
        }
    }

    /**
     * @param list<array<string, mixed>> $lines
     *
     * @return list<array<string, mixed>>
     */
    private function canonicalLines(array $lines): array
    {
        return array_map(static fn (array $line): array => [
            'account_id'     => (int) $line['account_id'],
            'debit'          => (string) $line['debit'],
            'credit'         => (string) $line['credit'],
            'description'    => $line['description'] === null ? null : (string) $line['description'],
            'cost_center_id' => $line['cost_center_id'] === null ? null : (int) $line['cost_center_id'],
            'party_type'     => $line['party_type'] ?? null,
            'party_id'       => ($line['party_id'] ?? null) === null ? null : (int) $line['party_id'],
        ], $lines);
    }

    /**
     * @param array<string, mixed> $line
     */
    private function lineDraft(array $line): JournalLineDraft
    {
        return new JournalLineDraft(
            accountId: (int) $line['account_id'],
            debit: (string) $line['debit'],
            credit: (string) $line['credit'],
            description: $line['description'] === null ? null : (string) $line['description'],
            costCenterId: $line['cost_center_id'] === null ? null : (int) $line['cost_center_id'],
            partyType: $line['party_type'] ?? null,
            partyId: ($line['party_id'] ?? null) === null ? null : (int) $line['party_id'],
        );
    }

    /**
     * @param list<array<string, mixed>> $lines
     * @param list<int>                  $subjects
     *
     * @return array{accounts: string, branch: string, subjects: string}
     */
    private function snapshot(array $lines, int $branchId, array $subjects): array
    {
        $accountIds = array_values(array_unique(array_map(static fn (array $line): int => (int) $line['account_id'], $lines)));
        sort($accountIds);

        $accounts = $this->db->table('accounting_accounts')
            ->select('account_id, code, account_type, is_group, active, entity_type, entity_id, parent_id')
            ->whereIn('account_id', $accountIds === [] ? [0] : $accountIds)
            ->orderBy('account_id', 'ASC')
            ->get()
            ->getResultArray();

        $branch = $this->db->table('branches')->select('branch_id, active')->where('branch_id', $branchId)->get()->getResultArray();

        $subjects = array_values(array_unique(array_map('intval', $subjects)));
        sort($subjects);

        $standing = $subjects === [] ? [] : $this->db->table('accounting_journals')->select('journal_id, status')->whereIn('journal_id', $subjects)->orderBy('journal_id', 'ASC')->get()->getResultArray();

        return [
            'accounts' => self::hashRows($accounts),
            'branch'   => self::hashRows($branch),
            'subjects' => self::hashRows($standing),
        ];
    }

    /**
     * @param list<array<string, mixed>> $rows
     */
    private static function hashRows(array $rows): string
    {
        return hash('sha256', (string) json_encode(array_map(static fn (array $row): array => array_map(static fn (mixed $value): string => (string) $value, $row), $rows)));
    }

    /**
     * @param array<string, mixed> $payload
     */
    private function intact(object $row, array $payload): bool
    {
        $core = ['date' => $payload['date'] ?? null, 'branch_id' => $payload['branch_id'] ?? null, 'description' => $payload['description'] ?? null, 'lines' => $payload['lines'] ?? null];

        return hash_equals((string) $row->fingerprint, self::fingerprintOf($core));
    }

    /**
     * Why the proposal can no longer be posted as it stands, or null when it can: what its accounts and its
     * branch were when it was made, whether the journals it rests on still stand, and every check a
     * posted journal passes, the period of its date included.
     *
     * @param array<string, mixed> $payload
     */
    private function staleReason(array $payload, JournalDraft $journal): ?string
    {
        $now = $this->snapshot($payload['lines'], (int) $payload['branch_id'], $payload['subject_journals'] ?? []);

        foreach (['accounts' => 'accounts_changed', 'branch' => 'branch_changed', 'subjects' => 'subject_changed'] as $part => $reason) {
            if (! hash_equals((string) ($payload['snapshot'][$part] ?? ''), $now[$part])) {
                return $reason;
            }
        }

        try {
            $this->posting->validate($journal);
        } catch (PostingException $failure) {
            return $failure->error->value;
        }

        return null;
    }

    /**
     * Ends a proposal that is standing: the status, who and when, and the place it held is freed.
     */
    private function close(object $row, ProposalStatus $status, ?string $staleReason, ?string $rejectReason, string $action, ?int $employeeId): void
    {
        $this->db->table('accounting_ai_proposals')->where('proposal_id', (int) $row->proposal_id)->update([
            'status'        => $status->value,
            'decided_by'    => $status === ProposalStatus::Rejected ? $employeeId : null,
            'decided_at'    => new RawSql('NOW()'),
            'reject_reason' => $rejectReason,
            'stale_reason'  => $staleReason,
            'dedupe_key'    => $status === ProposalStatus::Rejected ? $row->dedupe_key : null,
        ]);

        $details = array_filter(['kind' => $row->kind, 'reason' => $rejectReason ?? $staleReason], static fn (mixed $value): bool => $value !== null);

        $this->audit->write('ai_proposal', (int) $row->proposal_id, $action, $employeeId, $employeeId === null ? JournalOrigin::System : JournalOrigin::User, $details, (int) $row->branch_id);
    }

    /**
     * Writes the proposal unless one stands for the same thing already, which the unique key on the place it holds
     * says in one statement, so that two runs at once cannot both make it.
     *
     * @param array<string, mixed> $values
     *
     * @return int The id of the proposal that was written.
     *
     * @throws AccountingException
     */
    private function insertOnce(array $values): int
    {
        $insert = $this->db->table('accounting_ai_proposals')->set($values)->getCompiledInsert();
        $result = $this->db->query($insert . ' ON DUPLICATE KEY UPDATE proposal_id = proposal_id');

        if ($result === false) {
            throw new AccountingException(AccountingError::WriteFailed, 'The proposal could not be saved.');
        }

        if ($this->db->affectedRows() === 0) {
            throw new AccountingException(AccountingError::AiProposalDuplicate, 'The same proposal is standing already.');
        }

        return (int) $this->db->insertID();
    }

    /**
     * @param list<array<string, mixed>> $lines
     *
     * @return list<array<string, mixed>>
     */
    private function describedLines(array $lines): array
    {
        $ids      = array_values(array_unique(array_map(static fn (array $line): int => (int) ($line['account_id'] ?? 0), $lines)));
        $accounts = $ids === [] ? [] : $this->db->table('accounting_accounts')->select('account_id, code, name')->whereIn('account_id', $ids)->get()->getResult();
        $byId     = [];

        foreach ($accounts as $account) {
            $byId[(int) $account->account_id] = $account;
        }

        return array_map(static function (array $line) use ($byId): array {
            $account = $byId[(int) ($line['account_id'] ?? 0)] ?? null;

            return $line + ['account_code' => $account?->code ?? '', 'account_name' => $account?->name ?? ''];
        }, $lines);
    }
}
