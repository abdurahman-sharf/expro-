<?php

namespace App\Libraries\Accounting;

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

    public function __construct(?BaseConnection $db = null)
    {
        $this->db        = $db ?? Database::connect();
        $this->audit     = new AuditLog($this->db);
        $this->failures  = new PostingFailureLog($this->db);
        $this->numbering = new JournalNumbering($this->db);
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
        $ownsTransaction = $this->db->transDepth === 0;

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
                $existingId = $this->findExisting($draft);

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
     * @return array{lines: list<array<string, mixed>>, debit: string, credit: string, currency: string, rate: string}
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

        $this->assertAccountsPostable($lines, $draft->reversesJournalId !== null);
        $this->assertCostCentersExist($lines);

        return [
            'lines'    => $lines,
            'debit'    => $totalDebit,
            'credit'   => $totalCredit,
            'currency' => $this->resolveCurrency($draft->currencyCode),
            'rate'     => $this->normalizeRate($draft->exchangeRate),
        ];
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
     */
    private function assertAccountsPostable(array $lines, bool $allowInactive): void
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

    private function findExisting(JournalDraft $draft): ?int
    {
        if ($draft->sourceId === null) {
            return null;
        }

        $journal = (new Journal($this->db))->findBySource($draft->sourceType, $draft->sourceId, $draft->revision);

        return $journal === null ? null : (int) $journal->journal_id;
    }

    /**
     * @param array{lines: list<array<string, mixed>>, debit: string, credit: string, currency: string, rate: string} $prepared
     */
    private function write(JournalDraft $draft, array $prepared): int
    {
        $userNumbered = in_array($draft->sourceType, [JournalDraft::SOURCE_MANUAL, JournalDraft::SOURCE_REVERSAL], true);

        $inserted = $this->db->table('accounting_journals')->insert([
            'series'              => $userNumbered ? 'manual' : 'auto',
            'number'              => $userNumbered ? $this->numbering->nextManual() : null,
            'journal_date'        => $draft->date,
            'description'         => $draft->description === '' ? null : $draft->description,
            'status'              => JournalStatus::Posted->value,
            'source_type'         => $draft->sourceType,
            'source_id'           => $draft->sourceId,
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
            throw new PostingException(PostingError::WriteFailed, 'The journal header could not be written.');
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
            'revision'    => $draft->revision,
            'total'       => $prepared['debit'],
        ]);

        return $journalId;
    }

    private function isDuplicateEntry(Throwable $exception): bool
    {
        return $exception->getCode() === self::MYSQL_DUPLICATE_ENTRY || str_contains($exception->getMessage(), 'Duplicate entry');
    }
}
