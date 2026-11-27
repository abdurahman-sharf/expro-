<?php

namespace App\Libraries\Accounting;

use App\Libraries\Accounting\Engine\OperationJournal;
use App\Libraries\Assets\AssetSources;
use App\Libraries\Organization\BranchError;
use App\Libraries\Organization\BranchException;
use App\Libraries\Organization\BranchGuard;
use App\Libraries\Organization\CompanyContext;
use App\Libraries\Stock\StockPermitSources;
use App\Libraries\Tax\AdvanceVat;
use CodeIgniter\Database\BaseBuilder;
use CodeIgniter\Database\BaseConnection;
use Config\Database;
use DateTimeImmutable;

final class JournalService
{
    use RunsInTransaction;

    public const TRANSFER_SOURCES = [
        TreasuryTransfers::SOURCE,
        TreasuryTransfers::SOURCE_RECEIPT,
        ReceivingPostingBuilder::SOURCE_STOCK_TRANSFER,
        ReceivingPostingBuilder::SOURCE_TRANSFER_RECEIPT,
        InterBranchSettlements::SOURCE,
        InterBranchSettlements::SOURCE_SECOND,
        StockPermitSources::TRANSFER,
        StockPermitSources::TRANSFER_RECEIPT,
    ];
    public const DOCUMENT_SOURCES = [
        StockPermitSources::PERMIT,
        StockPermitSources::STOCKTAKE,
        ExpensePostingBuilder::SOURCE,
        CashupPostingBuilder::SOURCE,
        CustomerOpening::SOURCE,
        SalesDebitNotes::SOURCE,
        RevenuePostingBuilder::SOURCE,
        ...ChequeSources::ALL,
        ...AssetSources::ALL,
    ];
    public const CREDIT_SOURCES = [
        SupplierAdvances::SOURCE,
        CustomerAdvances::SOURCE,
        CreditRefunds::CUSTOMER_SOURCE,
        CreditRefunds::SUPPLIER_SOURCE,
        AdvanceVat::SOURCE,
        AdvanceVat::SOURCE_USE,
        AdvanceVat::SOURCE_RETURN,
    ];
    public const FX_SOURCES = [
        SalePostingBuilder::SOURCE_PAYMENT_FX,
        ReceivingPostingBuilder::SOURCE_SUPPLIER_FX,
    ];
    private const MAX_LINES      = 500;
    private const AMOUNT_PATTERN = '/^\d{1,11}(\.\d{1,4})?$/';
    private const SORTABLE       = ['journal_date', 'number', 'created_at', 'total_debit', 'status'];
    private const TEXT_MAX       = 2000;
    private const LINE_TEXT_MAX  = 255;

    private BaseConnection $db;
    private AuditLog $audit;
    private AccountingGuard $guard;
    private PostingService $posting;
    private BranchGuard $branches;
    private PeriodGuard $periods;
    private JournalLineAnalysis $analysis;
    private OperationJournal $journal;
    private JournalSettings $settings;

    public function __construct(?BaseConnection $db = null, ?AccountingGuard $guard = null, ?PostingService $posting = null, ?BranchGuard $branches = null)
    {
        $this->db       = $db ?? Database::connect();
        $this->audit    = new AuditLog($this->db);
        $this->guard    = $guard ?? new AccountingGuard();
        $this->posting  = $posting ?? new PostingService($this->db);
        $this->branches = $branches ?? new BranchGuard($this->db);
        $this->periods  = new PeriodGuard($this->db);
        $this->settings = new JournalSettings($this->db);
        $this->analysis = new JournalLineAnalysis($this->db, null, $this->settings);
        $this->journal  = new OperationJournal(ManualJournalFacts::OPERATION, $this->db);
    }

    /**
     * Creates a draft, or replaces the header and lines of an existing one. A draft may be
     * unbalanced and incomplete, but every line it keeps must be well formed.
     *
     * @param array{date?: string, description?: string, branch_id?: int|string, lines?: list<array<string, mixed>>} $input
     *
     * @throws AccountingException|BranchException
     */
    public function saveDraft(array $input, ?int $employeeId = null, ?int $journalId = null): int
    {
        $this->guard->require(AccountingGuard::JOURNALS_DRAFT, $employeeId);

        $stored = $journalId === null ? null : $this->db->table('accounting_journals')->select('branch_id')->where('journal_id', $journalId)->get()->getRow();

        if ($stored !== null && ! $this->branches->canAccess((int) $stored->branch_id, $employeeId)) {
            throw new AccountingException(AccountingError::JournalNotFound, "Journal {$journalId} does not exist.");
        }

        $branchId    = $this->resolveBranch($input['branch_id'] ?? $stored?->branch_id, $employeeId);
        $date        = $this->normalizeDate((string) ($input['date'] ?? ''));
        $description = trim((string) ($input['description'] ?? ''));
        $lines       = $this->normalizeDraftLines($input['lines'] ?? [], false);
        $currency    = (new AccountingSettings($this->db))->baseCurrency();

        if ($currency === '') {
            throw new AccountingException(AccountingError::BaseCurrencyMissing, 'The base currency is not configured.');
        }

        return $this->transactional(function () use ($journalId, $branchId, $date, $description, $lines, $currency, $employeeId): int {
            if ($journalId !== null) {
                $this->lockDraft($journalId, $employeeId);
            }

            $id     = $this->posting->writeDraft($journalId, $branchId, $date, $description, $currency, $lines, $employeeId);
            $action = $journalId === null ? 'draft_created' : 'draft_updated';

            $this->analysis->store($id, $this->analysesOf($lines));
            $this->audit->write('journal', $id, $action, $employeeId, JournalOrigin::User, ['lines' => count($lines)], $branchId);

            return $id;
        });
    }

    /**
     * @throws AccountingException
     */
    public function deleteDraft(int $journalId, ?int $employeeId = null): void
    {
        $this->guard->require(AccountingGuard::JOURNALS_DRAFT, $employeeId);

        $attachments = new JournalAttachments($this->db, $this->guard, $this);
        $files       = [];

        $this->transactional(function () use ($journalId, $employeeId, $attachments, &$files): void {
            $draft = $this->lockDraft($journalId, $employeeId);
            $files = $attachments->purgeDraft($journalId, $employeeId, (int) $draft->branch_id);

            $this->posting->deleteDraft($journalId);

            $this->audit->write('journal', $journalId, 'draft_deleted', $employeeId, JournalOrigin::User, $files === [] ? [] : ['attachments' => count($files)], (int) $draft->branch_id);
        });

        $attachments->removeFiles($files);
    }

    /**
     * Posts a stored draft, replacing its content first when new input is given. Nothing is
     * written when the journal does not pass the posting checks.
     *
     * @param array{date?: string, description?: string, branch_id?: int|string, lines?: list<array<string, mixed>>}|null $input
     *
     * @throws AccountingException|BranchException|PostingException
     */
    public function postDraft(int $journalId, ?int $employeeId = null, ?array $input = null): int
    {
        $this->guard->require(AccountingGuard::JOURNALS_POST, $employeeId);

        $stored = $this->find($journalId, $employeeId);

        if ($stored->status !== JournalStatus::Draft->value) {
            throw new AccountingException(AccountingError::NotADraft, 'Only a draft can be posted.');
        }

        if ($input !== null) {
            $this->guard->require(AccountingGuard::JOURNALS_DRAFT, $employeeId);

            $branchId    = $this->resolveBranch($input['branch_id'] ?? $stored->branch_id, $employeeId);
            $date        = $this->normalizeDate((string) ($input['date'] ?? ''));
            $description = trim((string) ($input['description'] ?? ''));
            $lines       = $this->normalizeDraftLines($input['lines'] ?? [], true);
        } else {
            $branchId    = (int) $stored->branch_id;
            $date        = $stored->journal_date;
            $description = (string) $stored->description;
            $lines       = array_map(fn (object $line, int $index): array => $this->storedLine($line, $index + 1), $stored->lines, array_keys($stored->lines));
        }

        $this->assertLineDescriptions($lines);
        $this->assertCostCentersRequired($lines);
        $this->periods->prepare($date);

        $draft = $this->manualDraft($date, $description, $lines, $employeeId, $branchId);
        $lines = $this->withTaxLines($lines);

        (new TreasuryScopes($this->db))->assertMayPost($draft->lines, $employeeId);

        $this->posting->validate($draft);

        $analyses = $this->analysesOf($lines);

        return $this->transactional(function () use ($journalId, $employeeId, $draft, $analyses): int {
            $prepared = $this->posting->postDraft($journalId, $draft, fn (): object => $this->lockDraft($journalId, $employeeId));

            $this->analysis->store($journalId, $analyses);

            $this->audit->write('journal', $journalId, 'posted', $employeeId, JournalOrigin::User, [
                'source_type' => JournalDraft::SOURCE_MANUAL,
                'total'       => $prepared['debit'],
            ], $prepared['branch_id']);

            return $journalId;
        });
    }

    /**
     * Creates and posts a manual journal in one step.
     *
     * @param array{date?: string, description?: string, branch_id?: int|string, lines?: list<array<string, mixed>>} $input
     *
     * @throws AccountingException|BranchException|PostingException
     */
    public function postNew(array $input, ?int $employeeId = null): int
    {
        $this->guard->require(AccountingGuard::JOURNALS_DRAFT, $employeeId);
        $this->guard->require(AccountingGuard::JOURNALS_POST, $employeeId);

        $branchId    = $this->resolveBranch($input['branch_id'] ?? null, $employeeId);
        $date        = $this->normalizeDate((string) ($input['date'] ?? ''));
        $description = trim((string) ($input['description'] ?? ''));
        $lines       = $this->normalizeDraftLines($input['lines'] ?? [], true);

        $this->assertLineDescriptions($lines);
        $this->assertCostCentersRequired($lines);

        $draft = $this->manualDraft($date, $description, $lines, $employeeId, $branchId);
        $lines = $this->withTaxLines($lines);

        (new TreasuryScopes($this->db))->assertMayPost($draft->lines, $employeeId);

        $this->periods->prepare($date);

        return $this->transactional(function () use ($draft, $lines): int {
            $result = $this->posting->post($draft);

            $this->analysis->store($result->journalId, $this->analysesOf($lines));

            return $result->journalId;
        });
    }

    /**
     * Whether the text of a journal may still be edited: it is posted and the books made it, as
     * opposed to a manual journal or the reversal of one, which are reversed and never edited.
     */
    public function canEditDetails(object $journal): bool
    {
        return $this->detailsRefusal($journal) === null;
    }

    /**
     * Changes the description of a posted automatic journal, the description of its lines, what
     * they are charged to (a cost center or a distribution over several), their tags and their tax
     * note, and nothing else: the amounts, the accounts, the date, the number and the source are
     * not in the input and stay as posted. A change in a closed period is refused because the cost
     * center a line belongs to decides which period an analysis counts it in.
     * The journal is locked before the period locks are taken, the order a reversal takes them,
     * so a closing that queues between the two cannot make them wait for each other.
     * Each edit is written to the audit log with what the text was and what it became.
     *
     * @param array{description?: string, version?: string, lines?: array<int|string, array<string, mixed>>} $input The lines are keyed by their line id. A version, as detailsVersion() gave it when the text was read, makes the edit refuse to overwrite text that someone else changed since.
     *
     * @return bool Whether anything changed. Nothing is written when nothing did.
     *
     * @throws AccountingException|PostingException
     */
    public function updateDetails(int $journalId, array $input, ?int $employeeId = null): bool
    {
        $this->guard->require(AccountingGuard::JOURNALS_EDIT, $employeeId);

        $stored = $this->find($journalId, $employeeId);

        $this->assertDetailsEditable($stored);

        $details = $this->normalizeDetails($input);
        $version = array_key_exists('version', $input) ? (is_string($input['version']) ? $input['version'] : '') : null;

        return $this->transactional(function () use ($journalId, $details, $version, $employeeId): bool {
            $table  = $this->db->prefixTable('accounting_journals');
            $locked = LockedRead::row($this->db, "SELECT * FROM {$table} WHERE journal_id = ? FOR UPDATE", [$journalId]);

            if ($locked === null) {
                throw new AccountingException(AccountingError::JournalNotFound, "Journal {$journalId} does not exist.");
            }

            $this->assertDetailsEditable($locked);
            $this->periods->enforce($locked->journal_date, (int) $locked->branch_id);

            $lines = LockedRead::rows($this->db, 'SELECT * FROM ' . $this->db->prefixTable('accounting_journal_lines') . ' WHERE journal_id = ? ORDER BY line_no FOR UPDATE', [$journalId]);

            foreach ($this->analysis->load($lines) as $lineId => $analysis) {
                foreach ($lines as $line) {
                    if ((int) $line->line_id === $lineId) {
                        $line->analysis = $analysis;
                    }
                }
            }

            if ($version !== null && ! hash_equals($this->detailsVersion($locked, $lines), $version)) {
                throw new AccountingException(AccountingError::DetailsChanged, "The text of journal {$journalId} changed after it was read.");
            }

            $changes = $this->detailChanges($locked, $lines, $details);

            if ($changes['after'] === []) {
                return false;
            }

            $this->posting->writeDetails($journalId, $changes['journal'] ?? [], $changes['lines']);

            foreach ($changes['analysis'] as $lineId => $analysis) {
                $this->analysis->apply($lineId, $analysis);
            }

            $this->audit->write('journal', $journalId, 'details_edited', $employeeId, JournalOrigin::User, [
                'before' => $changes['before'],
                'after'  => $changes['after'],
            ], (int) $locked->branch_id);

            return true;
        });
    }

    /**
     * A fingerprint of the text a person edits: the description of the journal and, for each line,
     * its description, cost center, distribution, tags and tax note. The edit screen carries it, so a form opened before someone
     * else changed the text is refused and does not write the old text back over the new.
     *
     * @param list<object> $lines
     */
    public function detailsVersion(object $journal, array $lines): string
    {
        $text = static fn (?string $value): ?string => $value === '' ? null : $value;

        return hash('sha256', (string) json_encode([
            $text($journal->description),
            array_map(static fn (object $line): array => [
                (int) $line->line_id,
                $text($line->description),
                $line->cost_center_id === null ? null : (int) $line->cost_center_id,
                ($line->analysis ?? null)?->describe() ?? [],
            ], $lines),
        ], JSON_UNESCAPED_UNICODE));
    }

    /**
     * Posts the mirror entry of a posted journal and marks the original as reversed. The
     * unique key on the reversal's source makes a second reversal impossible.
     *
     * @throws AccountingException|PostingException
     */
    public function reverse(int $journalId, string $date, string $reason, ?int $employeeId = null): int
    {
        $this->guard->require(AccountingGuard::JOURNALS_REVERSE, $employeeId);

        (new TreasuryScopes($this->db))->assertMayReverse($journalId, $employeeId);

        return $this->reverseJournal($journalId, $date, $reason, $employeeId, true);
    }

    /**
     * Reverses an opening balance journal. The caller has already checked the permission to
     * manage opening balances and that the branch is within the employee's scope, so the
     * journal does not have to be the employee's own. It is refused while the branch has closed
     * out the year the journal lies in. The journal is locked before the period locks, as
     * updateDetails() and a reversal take them.
     *
     * @throws AccountingException|PostingException
     */
    public function reverseOpening(int $journalId, string $date, string $reason, ?int $employeeId): int
    {
        $isOpening = $this->db->table('accounting_journals')->where('journal_id', $journalId)->whereIn('source_type', ['opening', OpeningStock::SOURCE])->countAllResults() > 0;

        if (! $isOpening) {
            throw new AccountingException(AccountingError::JournalNotFound, "Journal {$journalId} does not exist.");
        }

        (new TreasuryScopes($this->db))->assertMayReverse($journalId, $employeeId);

        return $this->transactional(function () use ($journalId, $date, $reason, $employeeId): int {
            $table   = $this->db->prefixTable('accounting_journals');
            $opening = LockedRead::row($this->db, "SELECT branch_id, journal_date FROM {$table} WHERE journal_id = ? FOR UPDATE", [$journalId]);

            if ($opening === null) {
                throw new AccountingException(AccountingError::JournalNotFound, "Journal {$journalId} does not exist.");
            }

            (new PeriodLocks($this->db))->shareForPosting((int) $opening->branch_id);
            (new ClosedOutYears($this->db))->assertNotClosedOut((int) $opening->branch_id, (string) $opening->journal_date);

            return $this->reverseJournal($journalId, $date, $reason, $employeeId, false);
        });
    }

    /**
     * Reverses the journal of a sale or a purchase, or of one of their payments, or of a transfer
     * of money or of stock, or an advance to a supplier, when the document is cancelled or a
     * payment changes. Who may do that was decided where the document changed, so no permission
     * is asked here and the reversal counts as the system's. A journal of any other source is
     * refused.
     *
     * @throws AccountingException|PostingException
     */
    public function reverseAutomatic(int $journalId, string $date, string $reason, ?int $employeeId): int
    {
        $isAutomatic = $this->db->table('accounting_journals')->where('journal_id', $journalId)->whereIn('source_type', [
            SalePostingBuilder::SOURCE_SALE,
            SalePostingBuilder::SOURCE_PAYMENT,
            ReceivingPostingBuilder::SOURCE_PURCHASE,
            ReceivingPostingBuilder::SOURCE_PAYMENT,
            ReceivingPostingBuilder::SOURCE_SUPPLIER_PAYMENT,
            ...self::TRANSFER_SOURCES,
            ...self::DOCUMENT_SOURCES,
            ...self::CREDIT_SOURCES,
            ...self::FX_SOURCES,
        ])->countAllResults() > 0;

        if (! $isAutomatic) {
            throw new AccountingException(AccountingError::JournalNotFound, "Journal {$journalId} does not exist.");
        }

        $partners = (new InterBranchLegs($this->db))->partnersOf($journalId);

        if ($partners === []) {
            return $this->reverseJournal($journalId, $date, $reason, $employeeId, false, JournalOrigin::System);
        }

        return $this->reversePair($journalId, $partners, $date, $reason, $employeeId);
    }

    /**
     * Takes back a journal together with the journals that stand with it, in the order of the
     * branch numbers, each on its own day. What the employee may do is asked of all of them before
     * the first is reversed, so that one that is refused leaves the others as they are.
     *
     * @param list<object> $partners
     *
     * @throws AccountingException|PostingException
     */
    private function reversePair(int $journalId, array $partners, string $date, string $reason, ?int $employeeId): int
    {
        return $this->transactional(function () use ($journalId, $partners, $date, $reason, $employeeId): int {
            $own = $this->db->table('accounting_journals')->select('journal_id, journal_date, branch_id')->where('journal_id', $journalId)->get()->getRow();

            if ($own === null) {
                throw new AccountingException(AccountingError::JournalNotFound, "Journal {$journalId} does not exist.");
            }

            $scopes = new TreasuryScopes($this->db, $this->branches);

            foreach ($partners as $partner) {
                if (! $this->branches->canAccess((int) $partner->branch_id, $employeeId)) {
                    throw new AccountingException(AccountingError::InterBranchBranchesDenied, "The branch {$partner->branch_id} is outside the employee's scope.", ['branch_id' => (int) $partner->branch_id, 'name' => (string) ($this->db->table('branches')->select('code')->where('branch_id', $partner->branch_id)->get()->getRow()->code ?? $partner->branch_id)]);
                }

                $scopes->assertMayReverse((int) $partner->journal_id, $employeeId);
            }

            $journals = [[(int) $own->branch_id, $journalId, $date]];

            foreach ($partners as $partner) {
                $cutoff     = $this->periods->effectiveCutoff((int) $partner->branch_id, true);
                $journals[] = [(int) $partner->branch_id, (int) $partner->journal_id, $cutoff !== null && $partner->journal_date <= $cutoff['date'] ? date('Y-m-d') : $partner->journal_date];
            }

            usort($journals, static fn (array $a, array $b): int => [$a[0], $a[1]] <=> [$b[0], $b[1]]);

            $reversal = 0;

            foreach ($journals as [, $id, $on]) {
                $made = $this->reverseJournal($id, $on, $reason, $employeeId, false, JournalOrigin::System);

                if ($id === $journalId) {
                    $reversal = $made;
                }
            }

            return $reversal;
        });
    }

    private function reverseJournal(int $journalId, string $date, string $reason, ?int $employeeId, bool $checkAccess, JournalOrigin $origin = JournalOrigin::User): int
    {
        $date   = $this->normalizeDate($date);
        $reason = trim($reason);

        if ($reason === '' || mb_strlen($reason) > 255) {
            throw new AccountingException(AccountingError::ReasonRequired, 'A reason of 1 to 255 characters is required.');
        }

        return $this->transactional(function () use ($journalId, $date, $reason, $employeeId, $checkAccess, $origin): int {
            $table    = $this->db->prefixTable('accounting_journals');
            $original = LockedRead::row($this->db, "SELECT * FROM {$table} WHERE journal_id = ? FOR UPDATE", [$journalId]);

            if ($original === null) {
                throw new AccountingException(AccountingError::JournalNotFound, "Journal {$journalId} does not exist.");
            }

            if ($checkAccess) {
                $this->assertCanAccess($original, $employeeId);

                if (in_array($original->source_type, self::TRANSFER_SOURCES, true) || (new InterBranchLegs($this->db))->isPaired($journalId)) {
                    throw new AccountingException(AccountingError::TransferJournalLocked, 'The journal of a transfer is taken back by cancelling the transfer.', ['journal_id' => $journalId]);
                }

                if (in_array($original->source_type, self::DOCUMENT_SOURCES, true)) {
                    throw new AccountingException(AccountingError::DocumentJournalLocked, 'The journal of an expense or a cash-up is taken back by deleting it or changing it.', ['journal_id' => $journalId]);
                }

                if (YearEndEntries::marks((string) $original->entry_type, (string) $original->source_type)) {
                    throw new AccountingException(AccountingError::YearEndJournalLocked, 'The journal that closes a year is taken back from the year-end screen.', ['journal_id' => $journalId]);
                }

                if (in_array($original->source_type, self::CREDIT_SOURCES, true)) {
                    throw new AccountingException(AccountingError::CreditJournalLocked, 'The journal of an advance payment or a cash refund is taken back by cancelling the document.', ['journal_id' => $journalId]);
                }

                $base = (new AccountingSettings($this->db))->baseCurrency();

                if (in_array($original->source_type, self::FX_SOURCES, true) || ($base !== '' && $original->currency_code !== $base)) {
                    throw new AccountingException(AccountingError::FxJournalLocked, 'A journal in a foreign currency, and the journal of an exchange difference, are taken back with the document or the payment they belong to.', ['journal_id' => $journalId]);
                }
            }

            if ($original->status === JournalStatus::Reversed->value) {
                throw new AccountingException(AccountingError::AlreadyReversed, 'The journal is already reversed.');
            }

            if ($original->status !== JournalStatus::Posted->value) {
                throw new AccountingException(AccountingError::NotPosted, 'Only a posted journal can be reversed.');
            }

            if ($original->reverses_journal_id !== null) {
                throw new AccountingException(AccountingError::CannotReverseReversal, 'A reversal cannot be reversed.');
            }

            $lines = [];

            foreach ($this->linesOf($journalId) as $line) {
                $lines[] = new JournalLineDraft(
                    accountId: (int) $line->account_id,
                    debit: $line->credit,
                    credit: $line->debit,
                    description: $line->description,
                    costCenterId: $line->cost_center_id === null ? null : (int) $line->cost_center_id,
                    partyType: $line->party_type,
                    partyId: $line->party_id === null ? null : (int) $line->party_id,
                    foreignAmount: $line->foreign_amount,
                    currencyCode: $line->currency_code,
                );
            }

            $result = $this->posting->post(new JournalDraft(
                date: $date,
                lines: $lines,
                sourceType: JournalDraft::SOURCE_REVERSAL,
                sourceId: $journalId,
                revision: 1,
                origin: $origin,
                employeeId: $employeeId,
                description: $reason,
                currencyCode: $original->currency_code,
                exchangeRate: $original->exchange_rate,
                reversesJournalId: $journalId,
                branchId: (int) $original->branch_id,
            ));

            if ($result->created) {
                $this->analysis->copy($journalId, $result->journalId);
            }

            $this->posting->markReversed($journalId);

            $this->audit->write('journal', $journalId, 'reversed', $employeeId, $origin, [
                'reversal_id' => $result->journalId,
                'date'        => $date,
                'reason'      => $reason,
            ], (int) $original->branch_id);

            return $result->journalId;
        });
    }

    /**
     * A journal with its lines, the people who created and posted it and the journals it is
     * linked to by a reversal.
     *
     * @throws AccountingException
     */
    public function find(int $journalId, ?int $employeeId = null): object
    {
        $journals    = $this->db->prefixTable('accounting_journals');
        $people      = $this->db->prefixTable('people');
        $branchTable = $this->db->prefixTable('branches');

        $journal = $this->db->query(
            "SELECT j.*,
                TRIM(CONCAT(cp.first_name, ' ', cp.last_name)) AS created_by_name,
                TRIM(CONCAT(pp.first_name, ' ', pp.last_name)) AS posted_by_name,
                o.number AS reverses_number,
                r.journal_id AS reversed_by_id,
                r.number AS reversed_by_number,
                bb.code AS branch_code,
                bb.name AS branch_name
             FROM {$journals} j
             LEFT JOIN {$branchTable} bb ON bb.branch_id = j.branch_id
             LEFT JOIN {$people} cp ON cp.person_id = j.created_by
             LEFT JOIN {$people} pp ON pp.person_id = j.posted_by
             LEFT JOIN {$journals} o ON o.journal_id = j.reverses_journal_id
             LEFT JOIN {$journals} r ON r.reverses_journal_id = j.journal_id
             WHERE j.journal_id = ?",
            [$journalId],
        )->getRow();

        if ($journal === null) {
            throw new AccountingException(AccountingError::JournalNotFound, "Journal {$journalId} does not exist.");
        }

        $this->assertCanAccess($journal, $employeeId);

        $journal->lines = $this->linesOf($journalId);

        return $journal;
    }

    /**
     * @param array{account_id?: int|string, description?: string, number?: string, date_from?: string, date_to?: string, source_type?: string, entry_type?: string, status?: list<string>, amount_from?: string, amount_to?: string, created_from?: string, created_to?: string, created_by?: int|string, branch_id?: int|string} $filters
     *
     * @return array{total: int, rows: list<object>}
     */
    public function search(array $filters, string $sort = 'journal_date', string $order = 'desc', int $limit = 25, int $offset = 0, ?int $employeeId = null): array
    {
        $sort  = in_array($sort, self::SORTABLE, true) ? $sort : 'journal_date';
        $order = strtolower($order) === 'asc' ? 'ASC' : 'DESC';
        $limit = max(1, min($limit, 500));

        // Every other query is made before the builder exists: creating a builder resets the
        // table aliases the connection shares, which would break this one.
        $ownOnly    = ! $this->guard->has(AccountingGuard::JOURNALS_ALL, $employeeId);
        $allowed    = $this->branches->allowedBranchIds($employeeId);
        $accountIds = (int) ($filters['account_id'] ?? 0) > 0 ? $this->accountWithDescendants((int) $filters['account_id']) : [];

        $builder = $this->db->table('accounting_journals j')
            ->select("j.journal_id, j.branch_id, b.code AS branch_code, b.name AS branch_name, j.series, j.number, j.journal_date, j.description, j.source_type, j.entry_type, j.status, j.total_debit, j.total_credit, j.currency_code, j.created_by, j.created_at, TRIM(CONCAT(p.first_name, ' ', p.last_name)) AS created_by_name", false)
            ->join('people p', 'p.person_id = j.created_by', 'left')
            ->join('branches b', 'b.branch_id = j.branch_id')
            ->where('j.status !=', JournalStatus::Preview->value);

        if ($allowed !== null) {
            $allowed === [] ? $builder->where('1 = 0', null, false) : $builder->whereIn('j.branch_id', $allowed);
        }

        if ($ownOnly) {
            $builder->where('j.created_by', $employeeId);
        }

        $this->applyFilters($builder, $filters, $accountIds);

        $total = $builder->countAllResults(false);
        $rows  = $builder->orderBy("j.{$sort}", $order)->orderBy('j.journal_id', $order)->limit($limit, $offset)->get()->getResult();

        return ['total' => $total, 'rows' => $rows];
    }

    /**
     * Active accounts that accept lines, for the journal line selector.
     *
     * @return list<object>
     */
    public function postableAccounts(): array
    {
        return $this->db->table('accounting_accounts')
            ->select('account_id, code, name')
            ->where('is_group', 0)
            ->where('active', 1)
            ->orderBy('code', 'ASC')
            ->get()
            ->getResult();
    }

    /**
     * @param array<string, mixed> $filters
     * @param list<int>            $accountIds The filtered account and its descendants, or none.
     */
    private function applyFilters(BaseBuilder $builder, array $filters, array $accountIds): void
    {
        if ($accountIds !== []) {
            $ids = implode(',', array_map('intval', $accountIds));

            $builder->where('EXISTS (SELECT 1 FROM ' . $this->db->prefixTable('accounting_journal_lines') . " l WHERE l.journal_id = j.journal_id AND l.account_id IN ({$ids}))", null, false);
        }

        $description = trim((string) ($filters['description'] ?? ''));

        if ($description !== '') {
            $builder->like('j.description', $description);
        }

        $number = trim((string) ($filters['number'] ?? ''));

        if ($number !== '') {
            $builder->like('j.number', $number);
        }

        if ($this->isDate($filters['date_from'] ?? null)) {
            $builder->where('j.journal_date >=', $filters['date_from']);
        }

        if ($this->isDate($filters['date_to'] ?? null)) {
            $builder->where('j.journal_date <=', $filters['date_to']);
        }

        $sourceType = (string) ($filters['source_type'] ?? '');

        if ($sourceType !== '' && preg_match('/^[a-z][a-z0-9_]{0,31}$/', $sourceType) === 1) {
            $builder->where('j.source_type', $sourceType);
        }

        $entryType = JournalEntryType::tryFrom((string) ($filters['entry_type'] ?? ''));

        if ($entryType !== null) {
            $builder->where('j.entry_type', $entryType->value);
        }

        $known    = array_map(static fn (JournalStatus $status): string => $status->value, [JournalStatus::Draft, JournalStatus::Posted, JournalStatus::Reversed]);
        $statuses = array_values(array_intersect($known, array_map('strval', (array) ($filters['status'] ?? []))));

        if ($statuses !== []) {
            $builder->whereIn('j.status', $statuses);
        }

        foreach (['amount_from' => '>=', 'amount_to' => '<='] as $key => $operator) {
            $amount = trim((string) ($filters[$key] ?? ''));

            if ($amount !== '' && preg_match(self::AMOUNT_PATTERN, $amount) === 1) {
                $builder->where("j.total_debit {$operator}", $amount);
            }
        }

        if ($this->isDate($filters['created_from'] ?? null)) {
            $builder->where('j.created_at >=', $filters['created_from'] . ' 00:00:00');
        }

        if ($this->isDate($filters['created_to'] ?? null)) {
            $builder->where('j.created_at <=', $filters['created_to'] . ' 23:59:59');
        }

        $createdBy = (int) ($filters['created_by'] ?? 0);

        if ($createdBy > 0) {
            $builder->where('j.created_by', $createdBy);
        }

        $branchId = (int) ($filters['branch_id'] ?? 0);

        if ($branchId > 0) {
            $builder->where('j.branch_id', $branchId);
        }
    }

    /**
     * @return list<int>
     */
    private function accountWithDescendants(int $accountId): array
    {
        $children = [];

        foreach ($this->db->table('accounting_accounts')->select('account_id, parent_id')->get()->getResult() as $row) {
            if ($row->parent_id !== null) {
                $children[(int) $row->parent_id][] = (int) $row->account_id;
            }
        }

        $ids   = [];
        $queue = [$accountId];

        while ($queue !== []) {
            $current = array_pop($queue);
            $ids[]   = $current;

            foreach ($children[$current] ?? [] as $child) {
                $queue[] = $child;
            }
        }

        return $ids;
    }

    /**
     * @return list<object>
     */
    private function linesOf(int $journalId): array
    {
        $lines = $this->db->table('accounting_journal_lines l')
            ->select('l.*, a.code AS account_code, a.name AS account_name, c.code AS cost_center_code, c.name AS cost_center_name')
            ->join('accounting_accounts a', 'a.account_id = l.account_id')
            ->join('accounting_cost_centers c', 'c.cost_center_id = l.cost_center_id', 'left')
            ->where('l.journal_id', $journalId)
            ->orderBy('l.line_no', 'ASC')
            ->get()
            ->getResult();

        $analyses = $this->analysis->load($lines);

        foreach ($lines as $line) {
            $line->analysis = $analyses[(int) $line->line_id];
        }

        return $lines;
    }

    private function lockDraft(int $journalId, ?int $employeeId): object
    {
        $table = $this->db->prefixTable('accounting_journals');
        $row   = LockedRead::row($this->db, "SELECT * FROM {$table} WHERE journal_id = ? FOR UPDATE", [$journalId]);

        if ($row === null || $row->source_type !== JournalDraft::SOURCE_MANUAL) {
            throw new AccountingException(AccountingError::JournalNotFound, "Journal {$journalId} does not exist.");
        }

        $this->assertCanAccess($row, $employeeId);

        if ($row->status !== JournalStatus::Draft->value) {
            throw new AccountingException(AccountingError::NotADraft, 'Only a draft can be changed.');
        }

        return $row;
    }

    private function detailsRefusal(object $journal): ?AccountingError
    {
        if ($journal->status !== JournalStatus::Posted->value) {
            return AccountingError::JournalNotPosted;
        }

        if ($journal->source_type === JournalDraft::SOURCE_MANUAL || $journal->reverses_journal_id !== null) {
            return AccountingError::PostedJournalLocked;
        }

        return null;
    }

    /**
     * @throws AccountingException
     */
    private function assertDetailsEditable(object $journal): void
    {
        $refusal = $this->detailsRefusal($journal);

        if ($refusal !== null) {
            throw new AccountingException($refusal, "Journal {$journal->journal_id} cannot have its text edited.");
        }
    }

    /**
     * What the caller asked to change, cleaned: the description of the journal, and for the lines
     * it names the description, the cost center or the distribution, the tags and the tax note.
     * Anything else in the input is left out, and so is a value that is not text, such as the list a
     * malformed request can send.
     *
     * @param array<string, mixed> $input
     *
     * @return array{description?: ?string, lines: array<int, array<string, mixed>>}
     *
     * @throws AccountingException
     */
    private function normalizeDetails(array $input): array
    {
        $details = ['lines' => []];

        if (array_key_exists('description', $input) && $this->isText($input['description'])) {
            $details['description'] = $this->boundedText((string) $input['description'], self::TEXT_MAX);
        }

        foreach (is_array($input['lines'] ?? null) ? $input['lines'] : [] as $lineId => $line) {
            if (! is_array($line)) {
                continue;
            }

            if (array_key_exists('description', $line) && $this->isText($line['description'])) {
                $details['lines'][(int) $lineId]['description'] = $this->boundedText((string) $line['description'], self::LINE_TEXT_MAX);
            }

            if (array_key_exists('cost_center_id', $line) && $this->isText($line['cost_center_id'])) {
                $costCenterId = (int) $line['cost_center_id'];

                $details['lines'][(int) $lineId]['cost_center_id'] = $costCenterId > 0 ? $costCenterId : null;
            }

            if (is_array($line['distribution'] ?? null) && $line['distribution'] !== []) {
                $details['lines'][(int) $lineId]['distribution'] = $line['distribution'];
            }

            foreach (['tags', 'tax_code', 'tax_percent', 'tax_included'] as $key) {
                if (array_key_exists($key, $line)) {
                    $details['lines'][(int) $lineId][$key] = $line[$key];
                }
            }
        }

        return $details;
    }

    private function isText(mixed $value): bool
    {
        return $value === null || is_scalar($value);
    }

    /**
     * @throws AccountingException
     */
    private function boundedText(string $text, int $max): ?string
    {
        $text = trim($text);

        if (mb_strlen($text) > $max) {
            throw new AccountingException(AccountingError::DescriptionTooLong, "The text is longer than {$max} characters.", ['max' => $max]);
        }

        return $text === '' ? null : $text;
    }

    /**
     * Compares what is stored, as locked, with what was asked, and returns the columns to write and
     * what the audit log records. A line is named by its number in the log, as the screen shows it.
     * What a line is charged to, its tags and its tax note are each compared as a whole: a line with a
     * distribution keeps it unless the input gives rows or a cost center, and a tag or a tax note
     * that is left out of the input stays as it is.
     *
     * @param list<object>                                                          $lines
     * @param array{description?: ?string, lines: array<int, array<string, mixed>>} $details
     *
     * @return array{journal?: array<string, ?string>, lines: array<int, array<string, mixed>>, analysis: array<int, LineAnalysis>, before: array<string, mixed>, after: array<string, mixed>}
     *
     * @throws AccountingException
     */
    private function detailChanges(object $journal, array $lines, array $details): array
    {
        $changes = ['lines' => [], 'analysis' => [], 'before' => [], 'after' => []];

        if (array_key_exists('description', $details)) {
            $before = $journal->description === null || $journal->description === '' ? null : $journal->description;

            if ($details['description'] !== $before) {
                $changes['journal']               = ['description' => $details['description']];
                $changes['before']['description'] = $before;
                $changes['after']['description']  = $details['description'];
            }
        }

        $byId = [];

        foreach ($lines as $line) {
            $byId[(int) $line->line_id] = $line;
        }

        $required = $this->settings->costCenterRequired() ? $this->requiredAccounts(array_map(static fn (object $line): int => (int) $line->account_id, $lines)) : [];

        foreach ($details['lines'] as $lineId => $asked) {
            if (! isset($byId[$lineId])) {
                throw new AccountingException(AccountingError::JournalLineNotFound, "Line {$lineId} does not belong to the journal.", ['line' => $lineId]);
            }

            $line    = $byId[$lineId];
            $columns = [];
            $before  = [];
            $after   = [];

            if (array_key_exists('description', $asked)) {
                $was = $line->description === null || $line->description === '' ? null : $line->description;

                if ($asked['description'] !== $was) {
                    $columns['description'] = $asked['description'];
                    $before['description']  = $was;
                    $after['description']   = $asked['description'];
                }
            }

            $stored  = $line->analysis ?? new LineAnalysis($line->cost_center_id === null ? null : (int) $line->cost_center_id);
            $desired = $this->desiredAnalysis($line, $stored, $asked);

            if (! $desired->sameAs($stored)) {
                if (! $desired->sameCharge($stored)) {
                    if ($stored->hasCharge() && ! $desired->hasCharge() && isset($required[(int) $line->account_id])) {
                        throw new AccountingException(AccountingError::CostCenterRequired, 'Line ' . (int) $line->line_no . ' needs a cost center.', ['line' => (int) $line->line_no]);
                    }

                    $before += $this->chargeOf($stored);
                    $after += $this->chargeOf($desired);
                }

                foreach (['tags' => 'tags', 'tax' => 'tax'] as $property => $key) {
                    if ($desired->{$property} !== $stored->{$property}) {
                        $before[$key] = $stored->{$property};
                        $after[$key]  = $desired->{$property};
                    }
                }

                $changes['analysis'][$lineId] = $desired;
            }

            if ($columns !== []) {
                $changes['lines'][$lineId] = $columns;
            }

            if ($before !== [] || $after !== []) {
                $changes['before']['lines'][(int) $line->line_no] = $before;
                $changes['after']['lines'][(int) $line->line_no]  = $after;
            }
        }

        return $changes;
    }

    /**
     * What a line carries once the request is applied: the parts it names are cleaned and checked,
     * the others stay as stored.
     *
     * @param array<string, mixed> $asked
     *
     * @throws AccountingException
     */
    private function desiredAnalysis(object $line, LineAnalysis $stored, array $asked): LineAnalysis
    {
        $chargeAsked = (isset($asked['distribution']) || array_key_exists('cost_center_id', $asked)) && ! $this->repeatsCharge($stored, $asked);
        $tagsAsked   = array_key_exists('tags', $asked);
        $taxAsked    = array_key_exists('tax_code', $asked) || array_key_exists('tax_percent', $asked);

        if (! $chargeAsked && ! $tagsAsked && ! $taxAsked) {
            return $stored;
        }

        $amount = bccomp($line->debit, '0', 4) > 0 ? $line->debit : $line->credit;
        $clean  = $this->analysis->clean(array_intersect_key($asked, array_flip(['cost_center_id', 'distribution', 'tags', 'tax_code', 'tax_percent', 'tax_included'])), (int) $line->line_no, $amount, true);

        return new LineAnalysis(
            $chargeAsked ? $clean->costCenterId : $stored->costCenterId,
            $chargeAsked ? $clean->distribution : $stored->distribution,
            $tagsAsked ? $clean->tags : $stored->tags,
            $taxAsked ? $clean->tax : $stored->tax,
        );
    }

    /**
     * Whether the request only gives back what the line is charged to already, as the edit screen does
     * on every save. It is not checked again, so that a cost center that has been switched off since does
     * not stop an edit of the text next to it.
     *
     * @param array<string, mixed> $asked
     */
    private function repeatsCharge(LineAnalysis $stored, array $asked): bool
    {
        if (! isset($asked['distribution'])) {
            return $stored->distribution === [] && ($asked['cost_center_id'] ?? null) === $stored->costCenterId;
        }

        $given = [];

        foreach ($asked['distribution'] as $row) {
            $percent = is_array($row) && is_scalar($row['percent'] ?? null) ? trim((string) $row['percent']) : '';

            if (! is_array($row) || ! is_scalar($row['cost_center_id'] ?? null) || preg_match('/^\d{1,3}(\.\d{1,4})?$/', $percent) !== 1) {
                return false;
            }

            $given[] = ['cost_center_id' => (int) $row['cost_center_id'], 'percent' => bcadd($percent, '0', 4)];
        }

        return $given === array_map(static fn (array $row): array => ['cost_center_id' => $row['cost_center_id'], 'percent' => $row['percent']], $stored->distribution);
    }

    /**
     * @return array<string, mixed> What a line is charged to, as the audit log records it.
     */
    private function chargeOf(LineAnalysis $analysis): array
    {
        return ['cost_center_id' => $analysis->costCenterId] + ($analysis->distribution === [] ? [] : ['distribution' => array_map(static fn (array $row): array => ['cost_center_id' => $row['cost_center_id'], 'percent' => $row['percent']], $analysis->distribution)]);
    }

    /**
     * When the books ask for it, every line of a journal that is posted says what it is for. A
     * draft may still be incomplete, and a reversal or an automatic journal does not pass here.
     *
     * @param list<array<string, mixed>> $lines
     *
     * @throws AccountingException
     */
    private function assertLineDescriptions(array $lines): void
    {
        if (! (new AccountingSettings($this->db))->lineDescriptionRequired()) {
            return;
        }

        foreach (array_values($lines) as $index => $line) {
            if (($line['description'] ?? '') === '') {
                throw new AccountingException(AccountingError::LineDescriptionRequired, 'Line ' . ($index + 1) . ' needs a description.', ['line' => $index + 1]);
            }
        }
    }

    /**
     * A user without the permission to see every journal sees only the ones they created.
     */
    private function assertCanAccess(object $journal, ?int $employeeId): void
    {
        $outsideScope = ! $this->branches->canAccess((int) $journal->branch_id, $employeeId);
        $notTheirs    = $employeeId !== null && (int) $journal->created_by !== $employeeId && ! $this->guard->has(AccountingGuard::JOURNALS_ALL, $employeeId);

        if ($outsideScope || $notTheirs) {
            throw new AccountingException(AccountingError::JournalNotFound, "Journal {$journal->journal_id} does not exist.");
        }
    }

    /**
     * The branch a journal goes to: the one asked for, else the employee's default. It must be
     * an active branch of the company inside the employee's scope.
     *
     * @throws BranchException
     */
    private function resolveBranch(mixed $requested, ?int $employeeId): int
    {
        $branchId = (int) $requested > 0 ? (int) $requested : $this->branches->defaultBranchId($employeeId);
        $branch   = $this->db->table('branches')->where('branch_id', $branchId)->where('company_id', CompanyContext::id())->get()->getRow();

        if ($branch === null) {
            throw new BranchException(BranchError::NotFound, "Branch {$branchId} does not exist.", ['branch_id' => $branchId]);
        }

        $this->branches->requireBranch($branchId, $employeeId);

        if ((int) $branch->active === 0) {
            throw new BranchException(BranchError::Inactive, "Branch {$branchId} is inactive.", ['branch_id' => $branchId]);
        }

        return $branchId;
    }

    /**
     * @param list<array<string, mixed>> $lines
     */
    private function toPostingDraft(string $date, string $description, array $lines, ?int $employeeId, int $branchId): JournalDraft
    {
        return new JournalDraft(
            date: $date,
            lines: array_map(static fn (array $line): JournalLineDraft => new JournalLineDraft(
                accountId: (int) $line['account_id'],
                debit: (string) $line['debit'],
                credit: (string) $line['credit'],
                description: $line['description'],
                costCenterId: $line['cost_center_id'],
                autoTax: $line['auto_tax'] ?? false,
            ), $lines),
            sourceType: JournalDraft::SOURCE_MANUAL,
            origin: JournalOrigin::User,
            employeeId: $employeeId,
            description: $description,
            branchId: $branchId,
        );
    }

    /**
     * The journal a manual journal posts, from the lines as they were typed, in the mode manual journals
     * are posted in.
     *
     * @param list<array<string, mixed>> $lines
     *
     * @throws PostingException
     */
    private function manualDraft(string $date, string $description, array $lines, ?int $employeeId, int $branchId): JournalDraft
    {
        $draft = $this->journal->draft(
            fn (): JournalDraft => $this->toPostingDraft($date, $description, $this->withTaxLines($lines), $employeeId, $branchId),
            static fn () => ManualJournalFacts::of($lines, $date, $branchId, $description, $employeeId),
            JournalDraft::SOURCE_MANUAL,
            null,
            1,
        );

        if ($draft === null) {
            throw new PostingException(PostingError::TooFewLines, 'A journal needs at least two lines.', ['lines' => 0]);
        }

        return $draft;
    }

    /**
     * The lines of a journal about to be posted with the line the books add for the tax of each line
     * that carries one, right after it and on its side: the tax paid for a debit, the tax collected
     * for a credit. A tax that is in the amount of its line is taken out of it, so the line stands at
     * its net. The other side of the journal has to cover the tax, or the journal does not balance.
     *
     * @param list<array<string, mixed>> $lines
     *
     * @return list<array<string, mixed>>
     *
     * @throws PostingException
     */
    private function withTaxLines(array $lines): array
    {
        $resolver = null;
        $expanded = [];

        foreach ($lines as $line) {
            $tax    = $line['analysis']->tax ?? null;
            $amount = $tax['amount'] ?? '0';

            if ($tax === null || bccomp($amount, '0', 4) <= 0) {
                $expanded[] = ['line_no' => count($expanded) + 1] + $line;

                continue;
            }

            $debited = bccomp((string) $line['debit'], '0', 4) > 0;
            $side    = $debited ? 'debit' : 'credit';
            $key     = $debited ? RoutingKey::TaxInput : RoutingKey::TaxOutput;
            $resolver ??= new RoutingResolver($this->db);
            $account = $resolver->accountFor($key);

            if ($account === null) {
                throw new PostingException(PostingError::RoutingRowCancelled, "The routing of {$key->value} is cancelled, so the tax of line {$line['line_no']} has no account.", ['routing_key' => $key->value, 'line' => $line['line_no']]);
            }

            if ($tax['included']) {
                $line[$side] = bcsub((string) $line[$side], $amount, 4);
            }

            $expanded[] = ['line_no' => count($expanded) + 1] + $line;
            $expanded[] = [
                'line_no'        => count($expanded) + 1,
                'account_id'     => $account,
                'description'    => trim($tax['code'] . ' ' . rtrim(rtrim($tax['percent'], '0'), '.') . '%'),
                'cost_center_id' => null,
                'debit'          => $debited ? $amount : '0.0000',
                'credit'         => $debited ? '0.0000' : $amount,
                'analysis'       => new LineAnalysis(),
                'auto_tax'       => true,
            ];
        }

        return $expanded;
    }

    /**
     * Drops blank lines and checks the rest. A draft may be unbalanced, and its distributions may be
     * incomplete; a journal about to be posted may be neither.
     *
     * @return list<array{line_no: int, account_id: int, description: ?string, cost_center_id: ?int, debit: string, credit: string, analysis: LineAnalysis}>
     *
     * @throws AccountingException
     */
    private function normalizeDraftLines(mixed $raw, bool $forPost): array
    {
        $lines = [];

        foreach (is_array($raw) ? array_values($raw) : [] as $index => $line) {
            $lineNumber   = $index + 1;
            $line         = is_array($line) ? $line : [];
            $accountId    = (int) ($line['account_id'] ?? 0);
            $costCenterId = (int) ($line['cost_center_id'] ?? 0);
            $description  = trim((string) ($line['description'] ?? ''));
            $debit        = $this->normalizeAmount($line['debit'] ?? '', $lineNumber);
            $credit       = $this->normalizeAmount($line['credit'] ?? '', $lineNumber);
            $hasDebit     = bccomp($debit, '0', 4) > 0;
            $hasCredit    = bccomp($credit, '0', 4) > 0;

            if ($accountId === 0 && $costCenterId === 0 && $description === '' && ! $hasDebit && ! $hasCredit && ! $this->carriesAnalysis($line)) {
                continue;
            }

            if ($accountId === 0) {
                throw new AccountingException(AccountingError::LineAccountRequired, "Line {$lineNumber} needs an account.", ['line' => $lineNumber]);
            }

            if ($hasDebit && $hasCredit) {
                throw new AccountingException(AccountingError::LineOnBothSides, "Line {$lineNumber} has both a debit and a credit.", ['line' => $lineNumber]);
            }

            $analysis = $this->analysis->clean($line, $lineNumber, $hasDebit ? $debit : $credit, $forPost);

            $lines[] = [
                'line_no'        => count($lines) + 1,
                'account_id'     => $accountId,
                'description'    => $description === '' ? null : mb_substr($description, 0, 255),
                'cost_center_id' => $analysis->costCenterId,
                'debit'          => $debit,
                'credit'         => $credit,
                'analysis'       => $analysis,
            ];
        }

        if ($lines === []) {
            throw new AccountingException(AccountingError::NoLines, 'The journal has no lines.');
        }

        if (count($lines) > self::MAX_LINES) {
            throw new AccountingException(AccountingError::TooManyLines, 'The journal has too many lines.', ['max' => self::MAX_LINES]);
        }

        $this->assertLineReferences($lines);

        return $lines;
    }

    /**
     * Whether a line of the input holds something to keep although it has no account, amount or
     * description yet: rows of a distribution, tags or a tax note.
     *
     * @param array<string, mixed> $line
     */
    private function carriesAnalysis(array $line): bool
    {
        foreach (['tags', 'tax_code', 'tax_percent'] as $key) {
            $value = $line[$key] ?? '';

            if (is_array($value) ? $value !== [] : trim((string) $value) !== '') {
                return true;
            }
        }

        foreach (is_array($line['distribution'] ?? null) ? $line['distribution'] : [] as $row) {
            if (! is_array($row) || implode('', array_map(static fn (mixed $cell): string => is_scalar($cell) ? trim((string) $cell) : 'x', $row)) !== '') {
                return true;
            }
        }

        return false;
    }

    /**
     * A stored line as the checks of posting take it, with what it carries beyond its amount made whole.
     *
     * @return array{line_no: int, account_id: int, description: ?string, cost_center_id: ?int, debit: string, credit: string, analysis: LineAnalysis}
     *
     * @throws AccountingException
     */
    private function storedLine(object $line, int $lineNumber): array
    {
        $stored   = $line->analysis;
        $analysis = $this->analysis->clean([
            'cost_center_id' => $stored->costCenterId,
            'distribution'   => array_map(static fn (array $row): array => ['cost_center_id' => $row['cost_center_id'], 'percent' => $row['percent']], $stored->distribution),
            'tags'           => $stored->tags,
            'tax_code'       => $stored->tax['code'] ?? '',
            'tax_percent'    => $stored->tax['percent'] ?? '',
            'tax_included'   => ($stored->tax['included'] ?? false) ? '1' : '0',
        ], $lineNumber, bccomp($line->debit, '0', 4) > 0 ? $line->debit : $line->credit, true, true);

        return [
            'line_no'        => $lineNumber,
            'account_id'     => (int) $line->account_id,
            'description'    => $line->description,
            'cost_center_id' => $analysis->costCenterId,
            'debit'          => $line->debit,
            'credit'         => $line->credit,
            'analysis'       => $analysis,
        ];
    }

    /**
     * @param list<array<string, mixed>> $lines
     *
     * @return array<int, LineAnalysis>
     */
    private function analysesOf(array $lines): array
    {
        $analyses = [];

        foreach (array_values($lines) as $index => $line) {
            $analyses[$index + 1] = $line['analysis'] ?? new LineAnalysis();
        }

        return $analyses;
    }

    /**
     * When the books ask for it, every income and expense line of a journal that is posted is charged
     * to a cost center or a distribution. A draft may still lack one.
     *
     * @param list<array<string, mixed>> $lines
     *
     * @throws AccountingException
     */
    private function assertCostCentersRequired(array $lines): void
    {
        if (! $this->settings->costCenterRequired()) {
            return;
        }

        $required = $this->requiredAccounts(array_column($lines, 'account_id'));

        foreach (array_values($lines) as $index => $line) {
            if (isset($required[(int) $line['account_id']]) && ! ($line['analysis'] ?? new LineAnalysis())->hasCharge()) {
                throw new AccountingException(AccountingError::CostCenterRequired, 'Line ' . ($index + 1) . ' needs a cost center.', ['line' => $index + 1]);
            }
        }
    }

    /**
     * @param list<int> $accountIds
     *
     * @return array<int, true> The accounts among them that are income or expense.
     */
    private function requiredAccounts(array $accountIds): array
    {
        if ($accountIds === []) {
            return [];
        }

        $found = [];

        foreach ($this->db->table('accounting_accounts')->select('account_id')->whereIn('account_id', array_values(array_unique($accountIds)))->whereIn('account_type', [AccountType::Revenue->value, AccountType::Expense->value])->get()->getResult() as $row) {
            $found[(int) $row->account_id] = true;
        }

        return $found;
    }

    /**
     * @param list<array<string, mixed>> $lines
     */
    private function assertLineReferences(array $lines): void
    {
        $accountIds = array_values(array_unique(array_column($lines, 'account_id')));
        $accounts   = $this->db->table('accounting_accounts')->select('account_id, is_group, active')->whereIn('account_id', $accountIds)->get()->getResult();
        $postable   = [];

        foreach ($accounts as $account) {
            if ((int) $account->is_group === 0 && (int) $account->active === 1) {
                $postable[(int) $account->account_id] = true;
            }
        }

        foreach ($lines as $line) {
            if (! isset($postable[$line['account_id']])) {
                throw new AccountingException(AccountingError::LineAccountInvalid, "Account {$line['account_id']} is missing, a group or inactive.", ['line' => $line['line_no'], 'account_id' => $line['account_id']]);
            }
        }

        $costCenterIds = array_values(array_unique(array_filter(array_column($lines, 'cost_center_id'))));

        if ($costCenterIds === []) {
            return;
        }

        $found = array_column(
            $this->db->table('accounting_cost_centers')->select('cost_center_id')->whereIn('cost_center_id', $costCenterIds)->where('active', 1)->get()->getResultArray(),
            'cost_center_id',
        );

        foreach ($lines as $line) {
            if ($line['cost_center_id'] !== null && ! in_array((string) $line['cost_center_id'], array_map('strval', $found), true)) {
                throw new AccountingException(AccountingError::LineCostCenterInvalid, "Cost center {$line['cost_center_id']} is missing or inactive.", ['line' => $line['line_no']]);
            }
        }
    }

    private function normalizeAmount(mixed $value, int $lineNumber): string
    {
        $value = trim((string) $value);

        if ($value === '') {
            return '0.0000';
        }

        if (preg_match(self::AMOUNT_PATTERN, $value) !== 1) {
            throw new AccountingException(AccountingError::LineAmountInvalid, "Line {$lineNumber} has an invalid amount.", ['line' => $lineNumber, 'value' => $value]);
        }

        return bcadd($value, '0', 4);
    }

    private function normalizeDate(string $date): string
    {
        if (! $this->isDate($date)) {
            throw new AccountingException(AccountingError::InvalidDate, 'The date must be a valid Y-m-d date.', ['date' => $date]);
        }

        return $date;
    }

    private function isDate(mixed $value): bool
    {
        if (! is_string($value)) {
            return false;
        }

        $parsed = DateTimeImmutable::createFromFormat('!Y-m-d', $value);

        return $parsed !== false && $parsed->format('Y-m-d') === $value;
    }
}
