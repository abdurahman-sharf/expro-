<?php

namespace App\Libraries\Accounting;

use App\Libraries\Organization\BranchGuard;
use App\Libraries\Organization\CompanyContext;
use App\Models\Accounting\Account;
use App\Models\Accounting\CostCenter;
use App\Models\Accounting\Journal;
use CodeIgniter\Database\BaseConnection;
use CodeIgniter\Database\RawSql;
use Config\Database;
use DateTimeImmutable;
use Throwable;

final class PostingService
{
    private const SCALE                 = 4;
    private const AMOUNT_PATTERN        = '/^\d{1,11}(\.\d{1,4})?$/';
    private const RATE_PATTERN          = '/^\d{1,10}(\.\d{1,8})?$/';
    private const SOURCE_PATTERN        = '/^[a-z][a-z0-9_]{0,31}$/';
    private const PARTY_TYPES           = ['customer', 'supplier'];
    private const MYSQL_DUPLICATE_ENTRY = 1062;

    private BaseConnection $db;
    private AuditLog $audit;
    private PostingFailureLog $failures;
    private JournalNumbering $numbering;
    private PeriodGuard $periods;
    private bool $yearEndRelief = false;

    public function __construct(?BaseConnection $db = null)
    {
        $this->db        = $db ?? Database::connect();
        $this->audit     = new AuditLog($this->db);
        $this->failures  = new PostingFailureLog($this->db);
        $this->numbering = new JournalNumbering($this->db);
        $this->periods   = new PeriodGuard($this->db);
    }

    /**
     * Writes the journal that closes a year for a branch, or the one that takes it back. It is the
     * one exception to the closed period: the closing of a year is dated on the last day of a year
     * that is closed already, so the closings of periods do not hold it back, and it may use an
     * account or a branch that was deactivated since, because their balances still have to be
     * closed. Nothing else is relaxed, and post() refuses a journal of this kind, so only the
     * closing of the year goes this way.
     *
     * @throws PostingException
     */
    public function postYearEnd(JournalDraft $draft): PostingResult
    {
        $closing  = $draft->sourceType === YearEndEntries::SOURCE_TYPE && $draft->reversesJournalId === null;
        $reversal = $draft->sourceType === JournalDraft::SOURCE_REVERSAL && $draft->reversesJournalId !== null;

        if ($draft->entryType !== JournalEntryType::YearEnd || ! ($closing || $reversal)) {
            throw new PostingException(PostingError::InvalidSource, 'Only the closing of a year, or the reversal of one, is written this way.', ['source_type' => $draft->sourceType]);
        }

        $this->yearEndRelief = true;

        try {
            return $this->post($draft);
        } finally {
            $this->yearEndRelief = false;
        }
    }

    /**
     * Writes a balanced journal as posted. Posting the same source and revision again
     * returns the existing journal instead of writing a second one.
     *
     * Failures are recorded here only when this call owns the transaction. Inside a
     * caller's transaction the caller must roll back and then record the failure with
     * PostingFailureLog, because a row written before the rollback would be lost.
     *
     * @throws PostingException
     */
    public function post(JournalDraft $draft): PostingResult
    {
        if (! $this->yearEndRelief && ($draft->entryType === JournalEntryType::YearEnd || $draft->sourceType === YearEndEntries::SOURCE_TYPE)) {
            throw new PostingException(PostingError::InvalidSource, 'The journal that closes a year is written only by the closing of the year.', ['source_type' => $draft->sourceType]);
        }

        $ownsTransaction = $this->db->transDepth === 0;

        $this->periods->prepare($draft->date);

        try {
            $prepared = $this->validate($draft);
        } catch (PostingException $failure) {
            if ($ownsTransaction && $draft->sourceType !== JournalDraft::SOURCE_MANUAL) {
                $this->failures->record($draft, $failure);
            }

            throw $failure;
        }

        $existingId = $this->findExisting($draft);

        if ($existingId !== null) {
            return new PostingResult($existingId, false);
        }

        $transactionOpen = false;

        if ($ownsTransaction) {
            $this->db->resetTransStatus();
        }

        try {
            $this->db->transStart();
            $transactionOpen = true;

            $journalId = $this->write($draft, $prepared);

            $this->db->transComplete();
            $transactionOpen = false;

            if (! $this->db->transStatus()) {
                throw new PostingException(PostingError::WriteFailed, 'The journal could not be written.');
            }
        } catch (Throwable $exception) {
            $duplicate = $this->isDuplicateEntry($exception);

            if ($transactionOpen) {
                $ownsTransaction ? $this->db->transRollback() : $this->db->transComplete();
            }

            if ($ownsTransaction) {
                $this->db->resetTransStatus();
            }

            if ($duplicate) {
                $existingId = $this->findExisting($draft, true);

                if ($existingId !== null) {
                    return new PostingResult($existingId, false);
                }
            }

            $failure = $exception instanceof PostingException
                ? $exception
                : new PostingException(
                    PostingError::WriteFailed,
                    'The journal could not be written.',
                    ['reason' => $exception->getMessage()],
                    $exception,
                );

            if ($ownsTransaction) {
                $this->failures->record($draft, $failure);
            }

            log_message('error', 'Accounting posting failed: ' . $failure->error->value . ' ' . $exception->getMessage());

            throw $failure;
        }

        return new PostingResult($journalId, true);
    }

    /**
     * Checks a journal without writing anything and returns the normalized values.
     *
     * @return array{lines: list<array<string, mixed>>, debit: string, credit: string, currency: string, rate: string, branch_id: int}
     *
     * @throws PostingException
     */
    public function validate(JournalDraft $draft): array
    {
        if (count($draft->lines) < 2) {
            throw new PostingException(PostingError::TooFewLines, 'A journal needs at least two lines.', ['lines' => count($draft->lines)]);
        }

        $this->assertDate($draft->date);
        $this->assertSource($draft);

        $totalDebit  = bcadd('0', '0', self::SCALE);
        $totalCredit = $totalDebit;
        $lines       = [];

        foreach (array_values($draft->lines) as $index => $line) {
            $lineNumber = $index + 1;
            $debit      = $this->normalizeAmount($line->debit, $lineNumber);
            $credit     = $this->normalizeAmount($line->credit, $lineNumber);
            $hasDebit   = bccomp($debit, '0', self::SCALE) > 0;
            $hasCredit  = bccomp($credit, '0', self::SCALE) > 0;

            if ($hasDebit && $hasCredit) {
                throw new PostingException(PostingError::LineOnBothSides, "Line {$lineNumber} has both a debit and a credit.", ['line' => $lineNumber]);
            }

            if (! $hasDebit && ! $hasCredit) {
                throw new PostingException(PostingError::LineWithoutAmount, "Line {$lineNumber} has no amount.", ['line' => $lineNumber]);
            }

            $this->assertParty($line, $lineNumber);

            $totalDebit  = bcadd($totalDebit, $debit, self::SCALE);
            $totalCredit = bcadd($totalCredit, $credit, self::SCALE);

            $description = $line->description === null ? '' : trim($line->description);

            $lines[] = [
                'line_no'        => $lineNumber,
                'account_id'     => $line->accountId,
                'description'    => $description === '' ? null : mb_substr($description, 0, 255),
                'debit'          => $debit,
                'credit'         => $credit,
                'cost_center_id' => $line->costCenterId,
                'party_type'     => $line->partyType,
                'party_id'       => $line->partyId,
            ];
        }

        if (bccomp($totalDebit, $totalCredit, self::SCALE) !== 0) {
            throw new PostingException(
                PostingError::Unbalanced,
                "The journal is unbalanced: debit {$totalDebit}, credit {$totalCredit}.",
                ['debit' => $totalDebit, 'credit' => $totalCredit],
            );
        }

        $accounts = $this->assertAccountsPostable($lines, $draft->reversesJournalId !== null || $this->yearEndRelief);
        $lines    = $this->bindParties($lines, $accounts);
        $this->assertCostCentersExist($lines);

        $branchId = $this->resolveBranch($draft);

        if (! $this->yearEndRelief) {
            $this->periods->check($draft->date, $branchId);
        }

        return [
            'lines'     => $lines,
            'debit'     => $totalDebit,
            'credit'    => $totalCredit,
            'currency'  => $this->resolveCurrency($draft->currencyCode),
            'rate'      => $this->normalizeRate($draft->exchangeRate),
            'branch_id' => $branchId,
        ];
    }

    /**
     * A journal belongs to one branch of the company. Without one it goes to the default branch.
     * A reversal may use a branch that was deactivated after the original was posted.
     */
    private function resolveBranch(JournalDraft $draft): int
    {
        $branchId = $draft->branchId ?? (new BranchGuard($this->db))->companyDefaultBranchId();
        $branch   = $this->db->table('branches')->where('branch_id', $branchId)->where('company_id', CompanyContext::id())->get()->getRow();

        if ($branch === null) {
            throw new PostingException(PostingError::BranchNotFound, "Branch {$branchId} does not exist.", ['branch_id' => $branchId]);
        }

        if ((int) $branch->active === 0 && $draft->reversesJournalId === null && ! $this->yearEndRelief) {
            throw new PostingException(PostingError::BranchInactive, "Branch {$branchId} is inactive.", ['branch_id' => $branchId]);
        }

        return $branchId;
    }

    private function assertDate(string $date): void
    {
        $parsed = DateTimeImmutable::createFromFormat('!Y-m-d', $date);

        if ($parsed === false || $parsed->format('Y-m-d') !== $date) {
            throw new PostingException(PostingError::InvalidDate, 'The journal date must be a valid Y-m-d date.', ['date' => $date]);
        }
    }

    private function assertSource(JournalDraft $draft): void
    {
        if (preg_match(self::SOURCE_PATTERN, $draft->sourceType) !== 1) {
            throw new PostingException(PostingError::InvalidSource, 'The source type is not valid.', ['source_type' => $draft->sourceType]);
        }

        $manual = $draft->sourceType === JournalDraft::SOURCE_MANUAL;

        if ($manual && $draft->sourceId !== null) {
            throw new PostingException(PostingError::InvalidSource, 'A manual journal has no source id.');
        }

        if (! $manual && ($draft->sourceId === null || $draft->sourceId < 1 || $draft->revision < 1)) {
            throw new PostingException(PostingError::InvalidSource, 'An automatic journal needs a source id and a revision of at least 1.', [
                'source_type' => $draft->sourceType,
                'source_id'   => $draft->sourceId,
                'revision'    => $draft->revision,
            ]);
        }
    }

    private function assertParty(JournalLineDraft $line, int $lineNumber): void
    {
        if ($line->partyType === null && $line->partyId === null) {
            return;
        }

        if (! in_array($line->partyType, self::PARTY_TYPES, true) || $line->partyId === null || $line->partyId < 1) {
            throw new PostingException(PostingError::InvalidParty, "Line {$lineNumber} has an invalid party.", ['line' => $lineNumber]);
        }
    }

    private function normalizeAmount(string $value, int $lineNumber): string
    {
        if (preg_match(self::AMOUNT_PATTERN, $value) !== 1) {
            throw new PostingException(PostingError::InvalidAmount, "Line {$lineNumber} has an invalid amount.", ['line' => $lineNumber, 'value' => $value]);
        }

        return bcadd($value, '0', self::SCALE);
    }

    private function normalizeRate(string $rate): string
    {
        if (preg_match(self::RATE_PATTERN, $rate) !== 1 || bccomp($rate, '0', 8) <= 0) {
            throw new PostingException(PostingError::InvalidCurrency, 'The exchange rate must be a positive number.', ['rate' => $rate]);
        }

        return bcadd($rate, '0', 8);
    }

    private function resolveCurrency(string $currencyCode): string
    {
        $code = $currencyCode !== '' ? $currencyCode : (new AccountingSettings($this->db))->baseCurrency();

        if (preg_match('/^[A-Z]{3}$/', $code) !== 1) {
            throw new PostingException(PostingError::InvalidCurrency, 'The currency code must be three capital letters.', ['currency' => $code]);
        }

        return $code;
    }

    /**
     * A reversal may touch an account that was deactivated after the original was posted.
     *
     * @param list<array<string, mixed>> $lines
     *
     * @return array<int, object> The accounts of the lines, by id.
     */
    private function assertAccountsPostable(array $lines, bool $allowInactive): array
    {
        $accountIds = array_values(array_unique(array_column($lines, 'account_id')));

        $found = [];

        foreach ((new Account($this->db))->whereIn('account_id', $accountIds)->findAll() as $account) {
            $found[(int) $account->account_id] = $account;
        }

        foreach ($accountIds as $accountId) {
            if (! isset($found[$accountId])) {
                throw new PostingException(PostingError::AccountNotFound, "Account {$accountId} does not exist.", ['account_id' => $accountId]);
            }

            if ((int) $found[$accountId]->is_group === 1 || (! $allowInactive && (int) $found[$accountId]->active === 0)) {
                throw new PostingException(PostingError::AccountNotPostable, "Account {$accountId} is a group or inactive.", ['account_id' => $accountId]);
            }
        }

        return $found;
    }

    /**
     * The sub-account of a customer or supplier says whose it is, so a line on it carries that
     * party whether or not the caller named it. A line may not name a party the account does
     * not belong to, and a party can only be named on its own account.
     *
     * @param list<array<string, mixed>> $lines
     * @param array<int, object>         $accounts
     *
     * @return list<array<string, mixed>>
     */
    private function bindParties(array $lines, array $accounts): array
    {
        foreach ($lines as $index => $line) {
            $account    = $accounts[(int) $line['account_id']];
            $isParty    = in_array($account->entity_type, self::PARTY_TYPES, true);
            $partyKnown = $line['party_type'] !== null;

            if (! $isParty && ! $partyKnown) {
                continue;
            }

            if ($isParty && ! $partyKnown) {
                $lines[$index]['party_type'] = $account->entity_type;
                $lines[$index]['party_id']   = (int) $account->entity_id;

                continue;
            }

            if (! $isParty || $line['party_type'] !== $account->entity_type || (int) $line['party_id'] !== (int) $account->entity_id) {
                throw new PostingException(PostingError::PartyAccountMismatch, "Line {$line['line_no']} names a party that does not own the account.", ['line' => $line['line_no']]);
            }
        }

        return $lines;
    }

    /**
     * @param list<array<string, mixed>> $lines
     */
    private function assertCostCentersExist(array $lines): void
    {
        $costCenterIds = array_values(array_unique(array_filter(array_column($lines, 'cost_center_id'))));

        if ($costCenterIds === []) {
            return;
        }

        $found = [];

        foreach ((new CostCenter($this->db))->whereIn('cost_center_id', $costCenterIds)->where('active', 1)->findAll() as $costCenter) {
            $found[(int) $costCenter->cost_center_id] = true;
        }

        foreach ($costCenterIds as $costCenterId) {
            if (! isset($found[$costCenterId])) {
                throw new PostingException(PostingError::CostCenterNotFound, "Cost center {$costCenterId} does not exist or is inactive.", ['cost_center_id' => $costCenterId]);
            }
        }
    }

    /**
     * The journal already posted for the draft's source and revision. With $latest the answer
     * is what is committed right now, which a transaction holding an older snapshot needs after
     * the unique key has refused a second journal.
     */
    private function findExisting(JournalDraft $draft, bool $latest = false): ?int
    {
        if ($draft->sourceId === null) {
            return null;
        }

        $journal = $latest ? $this->findLatest($draft) : (new Journal($this->db))->findBySource($draft->sourceType, $draft->sourceId, $draft->revision, $draft->entryType);

        if ($journal === null) {
            return null;
        }

        if ($draft->branchId !== null && (int) $journal->branch_id !== $draft->branchId) {
            throw new PostingException(PostingError::BranchMismatch, 'The source was already posted in another branch.', [
                'branch_id' => $draft->branchId,
                'posted_in' => (int) $journal->branch_id,
            ]);
        }

        return (int) $journal->journal_id;
    }

    private function findLatest(JournalDraft $draft): ?object
    {
        $result = $this->db->query(
            'SELECT journal_id, branch_id FROM ' . $this->db->prefixTable('accounting_journals') . ' WHERE source_type = ? AND source_id = ? AND entry_type = ? AND revision = ? LOCK IN SHARE MODE',
            [$draft->sourceType, $draft->sourceId, $draft->entryType->value, $draft->revision],
        );

        return $result === false ? null : $result->getRow();
    }

    /**
     * @param array{lines: list<array<string, mixed>>, debit: string, credit: string, currency: string, rate: string, branch_id: int} $prepared
     */
    private function write(JournalDraft $draft, array $prepared): int
    {
        $this->yearEndRelief ? $this->periods->enforceYearEnd($draft->date, $prepared['branch_id']) : $this->periods->enforce($draft->date, $prepared['branch_id']);

        $userNumbered = in_array($draft->sourceType, [JournalDraft::SOURCE_MANUAL, JournalDraft::SOURCE_REVERSAL], true);

        $inserted = $this->db->table('accounting_journals')->insert([
            'company_id'          => CompanyContext::id(),
            'branch_id'           => $prepared['branch_id'],
            'series'              => $userNumbered ? 'manual' : 'auto',
            'number'              => $userNumbered ? $this->numbering->nextManual() : null,
            'journal_date'        => $draft->date,
            'description'         => $draft->description === '' ? null : $draft->description,
            'status'              => JournalStatus::Posted->value,
            'source_type'         => $draft->sourceType,
            'source_id'           => $draft->sourceId,
            'entry_type'          => $draft->entryType->value,
            'revision'            => $draft->revision,
            'origin'              => $draft->origin->value,
            'currency_code'       => $prepared['currency'],
            'exchange_rate'       => $prepared['rate'],
            'total_debit'         => $prepared['debit'],
            'total_credit'        => $prepared['credit'],
            'reverses_journal_id' => $draft->reversesJournalId,
            'created_by'          => $draft->employeeId,
            'posted_by'           => $draft->employeeId,
            'posted_at'           => new RawSql('CURRENT_TIMESTAMP'),
        ]);

        if ($inserted === false) {
            throw new PostingException(PostingError::WriteFailed, 'The journal header could not be written.', ['database_code' => $this->db->error()['code'] ?? 0]);
        }

        $journalId = (int) $this->db->insertID();

        if (! $userNumbered) {
            $this->db->table('accounting_journals')
                ->where('journal_id', $journalId)
                ->update(['number' => sprintf('%06d', $journalId)]);
        }

        $lines = array_map(static fn (array $line): array => ['journal_id' => $journalId] + $line, $prepared['lines']);

        if ($this->db->table('accounting_journal_lines')->insertBatch($lines) === false) {
            throw new PostingException(PostingError::WriteFailed, 'The journal lines could not be written.');
        }

        $this->audit->write('journal', $journalId, 'posted', $draft->employeeId, $draft->origin, [
            'source_type' => $draft->sourceType,
            'source_id'   => $draft->sourceId,
            'entry_type'  => $draft->entryType->value,
            'revision'    => $draft->revision,
            'total'       => $prepared['debit'],
        ], $prepared['branch_id']);

        return $journalId;
    }

    private function isDuplicateEntry(Throwable $exception): bool
    {
        if ($exception instanceof PostingException) {
            return ($exception->context['database_code'] ?? 0) === self::MYSQL_DUPLICATE_ENTRY;
        }

        return $exception->getCode() === self::MYSQL_DUPLICATE_ENTRY || str_contains($exception->getMessage(), 'Duplicate entry');
    }
}
