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
        if (! $this->yearEndRelief && YearEndEntries::marks($draft->entryType->value, $draft->sourceType)) {
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
     * Writes the journal of a document that the books do not hold yet as a preview of a batch of the
     * retroactive posting. A preview has no number, is counted by no balance and no report, and holds
     * the key of its source, so the document cannot be posted another way while the preview stands.
     * It is checked as a posted journal is, the period included. It runs inside the transaction of
     * the batch, and a preview whose source already has a journal is refused.
     *
     * @throws PostingException
     */
    public function postPreview(JournalDraft $draft, int $batchId): int
    {
        if (in_array($draft->sourceType, [JournalDraft::SOURCE_MANUAL, JournalDraft::SOURCE_REVERSAL], true) || YearEndEntries::marks($draft->entryType->value, $draft->sourceType)) {
            throw new PostingException(PostingError::InvalidSource, 'Only the journal of a document is written as a preview.', ['source_type' => $draft->sourceType]);
        }

        $prepared = $this->validate($draft);

        if ($this->findExisting($draft, true) !== null) {
            throw new PostingException(PostingError::InvalidSource, 'The source of the journal has a journal already.', ['source_type' => $draft->sourceType, 'source_id' => $draft->sourceId]);
        }

        return $this->write($draft, $prepared, JournalStatus::Preview, $batchId);
    }

    /**
     * Turns the preview of a batch into a posted journal, as it was written: the lines are not
     * touched, the date stays the date of the document, and the number is the one a journal posted by
     * the books takes. The period of its date and branch is checked again and locked first.
     *
     * @throws PostingException
     */
    public function approvePreview(int $journalId, int $batchId, ?int $employeeId): void
    {
        $table   = $this->db->prefixTable('accounting_journals');
        $journal = LockedRead::row($this->db, "SELECT journal_id, branch_id, journal_date, status, backfill_batch_id, source_type, source_id, entry_type, revision, total_debit FROM {$table} WHERE journal_id = ? FOR UPDATE", [$journalId]);

        if ($journal === null || $journal->status !== JournalStatus::Preview->value || (int) $journal->backfill_batch_id !== $batchId) {
            throw new PostingException(PostingError::PreviewMissing, "Journal {$journalId} is not a preview of batch {$batchId}.", ['journal_id' => $journalId]);
        }

        $this->periods->enforce((string) $journal->journal_date, (int) $journal->branch_id);

        $this->db->table('accounting_journals')->where('journal_id', $journalId)->update([
            'status'    => JournalStatus::Posted->value,
            'number'    => sprintf('%06d', $journalId),
            'posted_by' => $employeeId,
            'posted_at' => new RawSql('CURRENT_TIMESTAMP'),
        ]);

        $this->audit->write('journal', $journalId, 'posted', $employeeId, JournalOrigin::System, [
            'source_type' => $journal->source_type,
            'source_id'   => (int) $journal->source_id,
            'entry_type'  => $journal->entry_type,
            'revision'    => (int) $journal->revision,
            'total'       => $journal->total_debit,
            'batch_id'    => $batchId,
        ], (int) $journal->branch_id);
    }

    /**
     * Removes every preview of a batch, with its lines. A journal that is posted is never removed
     * this way: only a journal that no book has counted can be taken out.
     *
     * @return int The previews removed.
     */
    public function discardPreviews(int $batchId): int
    {
        $journals = $this->db->prefixTable('accounting_journals');
        $lines    = $this->db->prefixTable('accounting_journal_lines');

        $this->db->query("DELETE l FROM {$lines} l JOIN {$journals} j ON j.journal_id = l.journal_id WHERE j.backfill_batch_id = ? AND j.status = ?", [$batchId, JournalStatus::Preview->value]);
        $this->db->query("DELETE FROM {$journals} WHERE backfill_batch_id = ? AND status = ?", [$batchId, JournalStatus::Preview->value]);

        return $this->db->affectedRows();
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

            [$foreignAmount, $lineCurrency] = $this->normalizeForeign($line, $lineNumber);

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
                'foreign_amount' => $foreignAmount,
                'currency_code'  => $lineCurrency,
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

        $currency = $this->resolveCurrency($draft->currencyCode);
        $rate     = $this->normalizeRate($draft->exchangeRate);

        $this->assertForeign($lines, $currency, $rate, $draft->reversesJournalId !== null || $this->yearEndRelief);
        $this->assertTreasuryCurrencies($lines, $accounts);

        return [
            'lines'     => $lines,
            'debit'     => $totalDebit,
            'credit'    => $totalCredit,
            'currency'  => $currency,
            'rate'      => $rate,
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

    /**
     * The foreign amount of a line and the currency it is in go together, and the amount is above zero: which
     * side of the journal it is on says whether it is owed or owing.
     *
     * @return array{?string, ?string}
     *
     * @throws PostingException
     */
    private function normalizeForeign(JournalLineDraft $line, int $lineNumber): array
    {
        if ($line->foreignAmount === null && $line->currencyCode === null) {
            return [null, null];
        }

        if ($line->foreignAmount === null || $line->currencyCode === null) {
            throw new PostingException(PostingError::ForeignAmountMissing, "Line {$lineNumber} has a foreign amount or a currency without the other.", ['line' => $lineNumber]);
        }

        $amount = $this->normalizeAmount($line->foreignAmount, $lineNumber);

        if (bccomp($amount, '0', self::SCALE) <= 0 || preg_match('/^[A-Z]{3}$/', $line->currencyCode) !== 1) {
            throw new PostingException(PostingError::InvalidAmount, "Line {$lineNumber} has an invalid foreign amount.", ['line' => $lineNumber, 'value' => $line->foreignAmount]);
        }

        return [$amount, $line->currencyCode];
    }

    /**
     * A journal in the base currency has the rate one and no foreign amount unless a line is in another
     * currency (the line of a treasury that holds one). A journal in another currency carries a foreign
     * amount on every line, in its currency, that adds up to the same on both sides, and each base amount is
     * what the foreign one comes to at the rate of the journal, apart from the few rounding units the line
     * that balances the journal takes. A currency the books do not know, or have switched off, is refused,
     * except for the reversal of a journal that was posted while it was on.
     *
     * @param list<array<string, mixed>> $lines
     *
     * @throws PostingException
     */
    private function assertForeign(array $lines, string $currency, string $rate, bool $allowInactive): void
    {
        $base    = (new AccountingSettings($this->db))->baseCurrency();
        $foreign = $base !== '' && $currency !== $base;
        $any     = $foreign || array_filter(array_column($lines, 'foreign_amount'), static fn (?string $amount): bool => $amount !== null) !== [];

        if (! $any) {
            if ($base !== '' && bccomp($rate, '1', 8) !== 0) {
                throw new PostingException(PostingError::ForeignRateMismatch, 'A journal in the base currency has the rate one.', ['rate' => $rate]);
            }

            return;
        }

        $currencies = new Currencies($this->db);
        $decimals   = $currencies->baseDecimals();
        $unit       = $decimals === 0 ? '1' : '0.' . str_repeat('0', $decimals - 1) . '1';
        $tolerance  = bcmul($unit, (string) (intdiv(count($lines), 2) + 1), self::SCALE);

        if ($foreign) {
            $this->assertCurrencyUsable($currencies, $currency, $allowInactive);
        } elseif (bccomp($rate, '1', 8) !== 0) {
            throw new PostingException(PostingError::ForeignRateMismatch, 'A journal in the base currency has the rate one.', ['rate' => $rate]);
        }

        $foreignDebit  = '0';
        $foreignCredit = '0';

        foreach ($lines as $line) {
            if ($line['foreign_amount'] === null) {
                if ($foreign) {
                    throw new PostingException(PostingError::ForeignAmountMissing, "Line {$line['line_no']} of a journal in {$currency} has no foreign amount.", ['line' => $line['line_no']]);
                }

                continue;
            }

            if ($foreign && $line['currency_code'] !== $currency) {
                throw new PostingException(PostingError::ForeignRateMismatch, "Line {$line['line_no']} is not in the currency of the journal.", ['line' => $line['line_no']]);
            }

            if (! $foreign) {
                if ($line['currency_code'] === $base) {
                    throw new PostingException(PostingError::ForeignRateMismatch, "Line {$line['line_no']} carries a foreign amount in the base currency.", ['line' => $line['line_no']]);
                }

                $this->assertCurrencyUsable($currencies, (string) $line['currency_code'], $allowInactive);

                continue;
            }

            $isDebit    = bccomp($line['debit'], '0', self::SCALE) > 0;
            $baseAmount = $isDebit ? $line['debit'] : $line['credit'];
            $expected   = Money::toBase($line['foreign_amount'], $rate, $decimals);

            if (bccomp(SaleAmounts::absolute(bcsub($baseAmount, $expected, self::SCALE)), $tolerance, self::SCALE) > 0) {
                throw new PostingException(PostingError::ForeignRateMismatch, "Line {$line['line_no']} is not what its foreign amount comes to at the rate of the journal.", ['line' => $line['line_no'], 'rate' => $rate]);
            }

            if ($isDebit) {
                $foreignDebit = bcadd($foreignDebit, $line['foreign_amount'], self::SCALE);
            } else {
                $foreignCredit = bcadd($foreignCredit, $line['foreign_amount'], self::SCALE);
            }
        }

        if ($foreign && bccomp($foreignDebit, $foreignCredit, self::SCALE) !== 0) {
            throw new PostingException(PostingError::ForeignUnbalanced, "The journal is unbalanced in {$currency}: debit {$foreignDebit}, credit {$foreignCredit}.", ['debit' => $foreignDebit, 'credit' => $foreignCredit]);
        }
    }

    /**
     * @throws PostingException
     */
    private function assertCurrencyUsable(Currencies $currencies, string $code, bool $allowInactive): void
    {
        $known = $currencies->find($code);

        if ($known === null || (! $known->active && ! $allowInactive)) {
            throw new PostingException(PostingError::InvalidCurrency, "The currency {$code} is not one the books use.", ['currency' => $code]);
        }
    }

    /**
     * A treasury that holds a currency takes and gives only amounts in that currency: every line on its
     * account says how much, in the currency of the treasury.
     *
     * @param list<array<string, mixed>> $lines
     * @param array<int, object>         $accounts
     *
     * @throws PostingException
     */
    private function assertTreasuryCurrencies(array $lines, array $accounts): void
    {
        $treasuries = [];

        foreach ($lines as $line) {
            $account = $accounts[(int) $line['account_id']];

            if ($account->entity_type === EntityAccountService::TREASURY) {
                $treasuries[(int) $account->account_id] = (int) $account->entity_id;
            }
        }

        if ($treasuries === []) {
            return;
        }

        $held = array_column(
            $this->db->table('treasuries')->select('treasury_id, currency_code')->whereIn('treasury_id', array_values($treasuries))->where('currency_code IS NOT NULL', null, false)->get()->getResultArray(),
            'currency_code',
            'treasury_id',
        );

        if ($held === []) {
            return;
        }

        foreach ($lines as $line) {
            $treasuryId = $treasuries[(int) $line['account_id']] ?? null;

            if ($treasuryId !== null && isset($held[$treasuryId]) && ($line['foreign_amount'] === null || $line['currency_code'] !== $held[$treasuryId])) {
                throw new PostingException(PostingError::TreasuryCurrency, "Line {$line['line_no']} is on a treasury that holds {$held[$treasuryId]}, so it needs an amount in {$held[$treasuryId]}.", ['line' => $line['line_no'], 'currency' => $held[$treasuryId]]);
            }
        }
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

        if (($journal->status ?? null) === JournalStatus::Preview->value) {
            throw new PostingException(PostingError::PreviewHoldsSource, 'A preview of a batch of the retroactive posting holds the source.', ['source_type' => $draft->sourceType, 'source_id' => $draft->sourceId]);
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
            'SELECT journal_id, branch_id, status FROM ' . $this->db->prefixTable('accounting_journals') . ' WHERE source_type = ? AND source_id = ? AND entry_type = ? AND revision = ? LOCK IN SHARE MODE',
            [$draft->sourceType, $draft->sourceId, $draft->entryType->value, $draft->revision],
        );

        return $result === false ? null : $result->getRow();
    }

    /**
     * @param array{lines: list<array<string, mixed>>, debit: string, credit: string, currency: string, rate: string, branch_id: int} $prepared
     */
    private function write(JournalDraft $draft, array $prepared, JournalStatus $status = JournalStatus::Posted, ?int $batchId = null): int
    {
        $this->yearEndRelief ? $this->periods->enforceYearEnd($draft->date, $prepared['branch_id']) : $this->periods->enforce($draft->date, $prepared['branch_id']);

        $userNumbered = in_array($draft->sourceType, [JournalDraft::SOURCE_MANUAL, JournalDraft::SOURCE_REVERSAL], true);
        $standing     = $status === JournalStatus::Posted;

        $inserted = $this->db->table('accounting_journals')->insert([
            'company_id'          => CompanyContext::id(),
            'branch_id'           => $prepared['branch_id'],
            'series'              => $userNumbered ? 'manual' : 'auto',
            'number'              => $userNumbered ? $this->numbering->nextManual() : null,
            'journal_date'        => $draft->date,
            'description'         => $draft->description === '' ? null : $draft->description,
            'status'              => $status->value,
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
            'backfill_batch_id'   => $batchId,
            'created_by'          => $draft->employeeId,
            'posted_by'           => $standing ? $draft->employeeId : null,
            'posted_at'           => $standing ? new RawSql('CURRENT_TIMESTAMP') : null,
        ]);

        if ($inserted === false) {
            throw new PostingException(PostingError::WriteFailed, 'The journal header could not be written.', ['database_code' => $this->db->error()['code'] ?? 0]);
        }

        $journalId = (int) $this->db->insertID();

        if (! $userNumbered && $standing) {
            $this->db->table('accounting_journals')
                ->where('journal_id', $journalId)
                ->update(['number' => sprintf('%06d', $journalId)]);
        }

        $lines = array_map(static fn (array $line): array => ['journal_id' => $journalId] + $line, $prepared['lines']);

        if ($this->db->table('accounting_journal_lines')->insertBatch($lines) === false) {
            throw new PostingException(PostingError::WriteFailed, 'The journal lines could not be written.');
        }

        if ($standing) {
            $this->audit->write('journal', $journalId, 'posted', $draft->employeeId, $draft->origin, [
                'source_type' => $draft->sourceType,
                'source_id'   => $draft->sourceId,
                'entry_type'  => $draft->entryType->value,
                'revision'    => $draft->revision,
                'total'       => $prepared['debit'],
            ], $prepared['branch_id']);
        }

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
