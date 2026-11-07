<?php

namespace App\Libraries\Accounting;

use App\Libraries\Organization\BranchException;
use App\Libraries\Organization\BranchGuard;
use App\Libraries\Organization\CompanyContext;
use CodeIgniter\Database\BaseBuilder;
use CodeIgniter\Database\BaseConnection;
use Config\Database;
use DateTimeImmutable;

/**
 * Money moved from one treasury to another: a document of its own and a journal that debits the
 * treasury it goes into and credits the one it comes out of. A journal belongs to one branch, so a
 * transfer between two branches is two journals that meet in the accounts between branches: the
 * sending branch debits what the other branches owe it and credits its treasury, and the receiving
 * branch debits its treasury and credits what it owes the other branches. A transfer is taken
 * back by reversing its journals, never by deleting.
 *
 * A treasury may hold a foreign currency. A transfer between two treasuries of one foreign currency is a
 * journal in that currency, converted to the base at the rate of the day. A transfer between treasuries of
 * different currencies (the base counts as one) needs both amounts, the one that leaves and the one that
 * arrives, each in the currency of its treasury; the two are worth the same in the base at the rates of the
 * day, and what they differ by goes to the account of exchange gains or losses in the same journal. A
 * transfer between branches is never in a foreign currency.
 */
final class TreasuryTransfers
{
    use RunsInTransaction;

    public const SOURCE                  = 'treasury_transfer';
    public const SOURCE_RECEIPT          = 'treasury_transfer_receipt';
    private const NOTE_MAX               = 500;
    private const REASON_MAX             = 255;
    private const RATE_TOLERANCE_PERCENT = '10';

    private BaseConnection $db;
    private AccountingGuard $guard;
    private BranchGuard $branches;
    private PostingService $posting;
    private JournalService $journals;
    private PeriodGuard $periods;
    private EntityAccountService $accounts;
    private RoutingResolver $resolver;
    private AuditLog $audit;

    public function __construct(?BaseConnection $db = null, ?AccountingGuard $guard = null, ?BranchGuard $branches = null)
    {
        $this->db       = $db ?? Database::connect();
        $this->guard    = $guard ?? new AccountingGuard();
        $this->branches = $branches ?? new BranchGuard($this->db);
        $this->posting  = new PostingService($this->db);
        $this->journals = new JournalService($this->db);
        $this->periods  = new PeriodGuard($this->db);
        $this->accounts = new EntityAccountService($this->db);
        $this->resolver = new RoutingResolver($this->db);
        $this->audit    = new AuditLog($this->db);
    }

    /**
     * @throws AccountingException|BranchException|PostingException
     */
    public function transfer(int $fromId, int $toId, string $amount, string $date, string $note = '', ?int $employeeId = null, ?string $toAmount = null): int
    {
        $this->guard->require(AccountingGuard::TREASURIES_MANAGE, $employeeId);

        $amount   = $this->amount($amount);
        $date     = $this->date($date);
        $note     = mb_substr(trim($note), 0, self::NOTE_MAX);
        $toAmount = $toAmount === null || trim($toAmount) === '' ? null : $this->amount($toAmount);

        if ($fromId === $toId) {
            throw new AccountingException(AccountingError::TransferSameTreasury, 'A transfer needs two different treasuries.');
        }

        return $this->transactional(function () use ($fromId, $toId, $amount, $toAmount, $date, $note, $employeeId): int {
            [$from, $to] = $this->lockBoth($fromId, $toId);

            foreach ([$from, $to] as $treasury) {
                if ((int) $treasury->active === 0) {
                    throw new AccountingException(AccountingError::TreasuryInactive, 'A transfer cannot touch a deactivated treasury.', ['treasury_id' => (int) $treasury->treasury_id]);
                }
            }

            $branchId   = (int) $from->branch_id;
            $toBranchId = (int) $to->branch_id;
            $between    = $branchId !== $toBranchId;

            $this->branches->requireBranch($branchId, $employeeId);

            if ($between) {
                $this->branches->requireBranch($toBranchId, $employeeId);
            }

            $scopes = new TreasuryScopes($this->db, $this->branches);

            $scopes->assertAllows($fromId, TreasuryScopes::WITHDRAW, $employeeId);
            $scopes->assertAllows($toId, TreasuryScopes::DEPOSIT, $employeeId);

            $fromAccount = $this->accounts->accountFor(EntityAccountService::TREASURY, $fromId);
            $toAccount   = $this->accounts->accountFor(EntityAccountService::TREASURY, $toId);

            if ($fromAccount === null || $toAccount === null) {
                throw new PostingException(PostingError::EntityAccountMissing, 'A treasury has no account of its own yet.', ['entity_id' => $fromAccount === null ? $fromId : $toId]);
            }

            [$receivable, $payable] = $between ? $this->accountsBetweenBranches() : [null, null];

            $fromCurrency = $from->currency_code ?? null;
            $toCurrency   = $to->currency_code ?? null;
            $foreign      = $fromCurrency !== null || $toCurrency !== null;

            if ($foreign && $between) {
                throw new PostingException(PostingError::FxNotSupported, 'A transfer between branches is never in a foreign currency.', ['what' => 'inter_branch_transfer']);
            }

            if (! $foreign && $toAmount !== null && bccomp($toAmount, $amount, SaleAmounts::SCALE) !== 0) {
                throw new AccountingException(AccountingError::TransferAmountInvalid, 'Two treasuries of the base currency take the same amount out and in.');
            }

            $this->db->table('treasury_transfers')->insert([
                'company_id'       => CompanyContext::id(),
                'branch_id'        => $branchId,
                'to_branch_id'     => $between ? $toBranchId : null,
                'from_treasury_id' => $fromId,
                'to_treasury_id'   => $toId,
                'amount'           => $amount,
                'transfer_date'    => $date,
                'note'             => $note,
                'created_by'       => $employeeId,
            ] + ($foreign ? ['to_amount' => $toAmount ?? $amount, 'from_currency' => $fromCurrency, 'to_currency' => $toCurrency] : []));

            $transferId  = (int) $this->db->insertID();
            $description = 'Transfer #' . $transferId . ($note === '' ? '' : ': ' . $note);

            $drafts = [];

            if (! $between) {
                $drafts[$branchId] = $foreign
                    ? $this->foreignDraft($fromCurrency, $toCurrency, $amount, $toAmount, $date, $fromAccount, $toAccount, $transferId, $description, $branchId, $employeeId)
                    : new JournalDraft(
                        date: $date,
                        lines: [new JournalLineDraft($toAccount, debit: $amount), new JournalLineDraft($fromAccount, credit: $amount)],
                        sourceType: self::SOURCE,
                        sourceId: $transferId,
                        origin: JournalOrigin::System,
                        employeeId: $employeeId,
                        description: $description,
                        branchId: $branchId,
                        entryType: JournalEntryType::General,
                    );
            } else {
                $drafts[$branchId] = new JournalDraft(
                    date: $date,
                    lines: [new JournalLineDraft((int) $receivable, debit: $amount), new JournalLineDraft($fromAccount, credit: $amount)],
                    sourceType: self::SOURCE,
                    sourceId: $transferId,
                    origin: JournalOrigin::System,
                    employeeId: $employeeId,
                    description: $description . ' (to branch ' . $this->branchCode($toBranchId) . ')',
                    branchId: $branchId,
                    entryType: JournalEntryType::General,
                );

                $drafts[$toBranchId] = new JournalDraft(
                    date: $date,
                    lines: [new JournalLineDraft($toAccount, debit: $amount), new JournalLineDraft((int) $payable, credit: $amount)],
                    sourceType: self::SOURCE_RECEIPT,
                    sourceId: $transferId,
                    origin: JournalOrigin::System,
                    employeeId: $employeeId,
                    description: $description . ' (from branch ' . $this->branchCode($branchId) . ')',
                    branchId: $toBranchId,
                    entryType: JournalEntryType::General,
                );
            }

            // Written in the order of the branch ids whichever way the money goes, so that two transfers in opposite
            // directions take the period locks of the two branches in the same order.
            ksort($drafts);

            foreach ($drafts as $draft) {
                $this->posting->post($draft);
            }

            $this->audit->write('treasury_transfer', $transferId, 'created', $employeeId, JournalOrigin::User, ['from' => $fromId, 'to' => $toId, 'amount' => $amount] + ($between ? ['to_branch' => $toBranchId] : []) + ($foreign ? ['to_amount' => $toAmount ?? $amount, 'from_currency' => $fromCurrency, 'to_currency' => $toCurrency] : []), $branchId);

            return $transferId;
        });
    }

    /**
     * @throws AccountingException|BranchException|PostingException
     */
    public function cancel(int $transferId, string $reason, ?int $employeeId = null): void
    {
        $this->guard->require(AccountingGuard::TREASURIES_MANAGE, $employeeId);

        $reason = trim($reason);

        if ($reason === '' || mb_strlen($reason) > self::REASON_MAX) {
            throw new AccountingException(AccountingError::ReasonRequired, 'A reason of 1 to 255 characters is required.');
        }

        $this->transactional(function () use ($transferId, $reason, $employeeId): void {
            $table    = $this->db->prefixTable('treasury_transfers');
            $transfer = LockedRead::row($this->db, "SELECT * FROM {$table} WHERE company_id = ? AND transfer_id = ? FOR UPDATE", [CompanyContext::id(), $transferId]);

            if ($transfer === null) {
                throw new AccountingException(AccountingError::TransferNotFound, "Transfer {$transferId} does not exist.", ['transfer_id' => $transferId]);
            }

            $branchId = (int) $transfer->branch_id;

            foreach (self::branchesOf($transfer) as $involved) {
                $this->branches->requireBranch($involved, $employeeId);
            }

            if ($transfer->status === 'cancelled') {
                throw new AccountingException(AccountingError::TransferAlreadyCancelled, 'The transfer is already cancelled.', ['transfer_id' => $transferId]);
            }

            $journals = $this->db->table('accounting_journals')
                ->select('journal_id, journal_date, branch_id')
                ->where('source_id', $transferId)
                ->whereIn('source_type', [self::SOURCE, self::SOURCE_RECEIPT])
                ->where('status', JournalStatus::Posted->value)
                // Reversed in the same order of the branch ids, for the same reason.
                ->orderBy('branch_id', 'ASC')
                ->get()
                ->getResult();
            $scopes = new TreasuryScopes($this->db, $this->branches);

            foreach ($journals as $journal) {
                $scopes->assertMayReverse((int) $journal->journal_id, $employeeId);
            }

            foreach ($journals as $journal) {
                $cutoff = $this->periods->effectiveCutoff((int) $journal->branch_id, true);
                $date   = $cutoff !== null && $journal->journal_date <= $cutoff['date'] ? date('Y-m-d') : $journal->journal_date;

                $this->journals->reverseAutomatic((int) $journal->journal_id, $date, $reason, $employeeId);
            }

            $this->db->table('treasury_transfers')->where('transfer_id', $transferId)->update([
                'status'        => 'cancelled',
                'cancelled_by'  => $employeeId,
                'cancelled_at'  => date('Y-m-d H:i:s'),
                'cancel_reason' => $reason,
            ]);

            $this->audit->write('treasury_transfer', $transferId, 'cancelled', $employeeId, JournalOrigin::User, ['reason' => $reason], $branchId);
        });
    }

    public function find(int $transferId): ?object
    {
        return $this->query()->where('tt.transfer_id', $transferId)->get()->getRow();
    }

    /**
     * The transfers a reader may see, the newest first: those that leave a branch of theirs and
     * those that go into one.
     *
     * @param list<int>|null $branchIds Null for every branch.
     *
     * @return array{total: int, rows: list<object>}
     */
    public function search(?array $branchIds = null, int $limit = 25, int $offset = 0): array
    {
        if ($branchIds === []) {
            return ['total' => 0, 'rows' => []];
        }

        $count = $this->db->table('treasury_transfers tt')->where('tt.company_id', CompanyContext::id());

        if ($branchIds !== null) {
            $count->groupStart()->whereIn('tt.branch_id', $branchIds)->orWhereIn('tt.to_branch_id', $branchIds)->groupEnd();
        }

        $total = $count->countAllResults();
        $rows  = $this->query();

        if ($branchIds !== null) {
            $rows->groupStart()->whereIn('tt.branch_id', $branchIds)->orWhereIn('tt.to_branch_id', $branchIds)->groupEnd();
        }

        return ['total' => $total, 'rows' => $rows->orderBy('tt.transfer_id', 'DESC')->limit($limit, $offset)->get()->getResult()];
    }

    /**
     * The journal of a transfer in which a treasury holds a foreign currency. Both in one currency: the
     * journal is in it. Different currencies: the journal is in the base, each leg carries the amount in its
     * own currency, and the exchange difference is a line of the same journal.
     *
     * @throws AccountingException|PostingException
     */
    private function foreignDraft(?string $fromCurrency, ?string $toCurrency, string $amount, ?string $toAmount, string $date, int $fromAccount, int $toAccount, int $transferId, string $description, int $branchId, ?int $employeeId): JournalDraft
    {
        $currencies   = new Currencies($this->db);
        $baseDecimals = $currencies->baseDecimals();

        if ($fromCurrency === $toCurrency) {
            $draft = new JournalDraft(
                date: $date,
                lines: [new JournalLineDraft($toAccount, debit: $amount), new JournalLineDraft($fromAccount, credit: $amount)],
                sourceType: self::SOURCE,
                sourceId: $transferId,
                origin: JournalOrigin::System,
                employeeId: $employeeId,
                description: $description,
                branchId: $branchId,
                entryType: JournalEntryType::General,
            );

            if ($toAmount !== null && bccomp($toAmount, $amount, SaleAmounts::SCALE) !== 0) {
                throw new AccountingException(AccountingError::TransferAmountInvalid, 'Two treasuries of one currency take the same amount out and in.');
            }

            return ForeignConversion::toBase($draft, $this->currencyOnDay((string) $fromCurrency, $date, $currencies), $baseDecimals, 0);
        }

        if ($toAmount === null) {
            throw new AccountingException(AccountingError::TransferAmountRequired, 'A transfer between treasuries of different currencies needs the amount that arrives.');
        }

        $baseFrom = $fromCurrency === null ? $amount : Money::toBase($amount, $this->currencyOnDay($fromCurrency, $date, $currencies)->rate, $baseDecimals);
        $baseTo   = $toCurrency === null ? $toAmount : Money::toBase($toAmount, $this->currencyOnDay($toCurrency, $date, $currencies)->rate, $baseDecimals);

        $lines = [
            new JournalLineDraft($toAccount, debit: $baseTo, foreignAmount: $toCurrency === null ? null : $toAmount, currencyCode: $toCurrency),
            new JournalLineDraft($fromAccount, credit: $baseFrom, foreignAmount: $fromCurrency === null ? null : $amount, currencyCode: $fromCurrency),
        ];

        $difference = bcsub($baseTo, $baseFrom, SaleAmounts::SCALE);

        if (bccomp(bcmul(SaleAmounts::absolute($difference), '100', SaleAmounts::SCALE), bcmul($baseFrom, self::RATE_TOLERANCE_PERCENT, SaleAmounts::SCALE), SaleAmounts::SCALE) > 0) {
            throw new AccountingException(AccountingError::TransferRateOutOfRange, 'The two amounts of the transfer are further apart than the rates of the day allow, so it is not booked as an exchange gain or loss.', ['from' => Money::round($baseFrom, $baseDecimals), 'to' => Money::round($baseTo, $baseDecimals), 'percent' => self::RATE_TOLERANCE_PERCENT]);
        }

        if (bccomp($difference, '0', SaleAmounts::SCALE) > 0) {
            $lines[] = new JournalLineDraft($this->exchangeAccount(RoutingKey::FxGain), credit: $difference);
        } elseif (bccomp($difference, '0', SaleAmounts::SCALE) < 0) {
            $lines[] = new JournalLineDraft($this->exchangeAccount(RoutingKey::FxLoss), debit: SaleAmounts::absolute($difference));
        }

        return new JournalDraft(
            date: $date,
            lines: $lines,
            sourceType: self::SOURCE,
            sourceId: $transferId,
            origin: JournalOrigin::System,
            employeeId: $employeeId,
            description: $description,
            branchId: $branchId,
            entryType: JournalEntryType::General,
        );
    }

    /**
     * @throws AccountingException
     */
    private function currencyOnDay(string $code, string $date, Currencies $currencies): DocumentCurrency
    {
        $rate = $currencies->rates()->rateOn($code, $date);

        if ($rate === null) {
            throw new AccountingException(AccountingError::RateMissing, "Currency {$code} has no rate on or before {$date}.", ['code' => $code, 'date' => $date]);
        }

        return new DocumentCurrency($code, $rate, $currencies->decimalsOf($code));
    }

    /**
     * @throws PostingException
     */
    private function exchangeAccount(RoutingKey $key): int
    {
        try {
            $account = $this->resolver->accountFor($key);
        } catch (PostingException $failure) {
            throw new PostingException(PostingError::FxAccountsMissing, "The routing of {$key->value} has no usable account, so the exchange difference cannot be posted.", ['routing_key' => $key->value], $failure);
        }

        if ($account === null) {
            throw new PostingException(PostingError::FxAccountsMissing, "The routing of {$key->value} has no account, so the exchange difference cannot be posted.", ['routing_key' => $key->value]);
        }

        return $account;
    }

    /**
     * The branches a transfer touches: the one it leaves, and the one it goes to when that is
     * another.
     *
     * @return list<int>
     */
    public static function branchesOf(object $transfer): array
    {
        $branches = [(int) $transfer->branch_id];

        if ($transfer->to_branch_id !== null) {
            $branches[] = (int) $transfer->to_branch_id;
        }

        return $branches;
    }

    private function query(): BaseBuilder
    {
        return $this->db->table('treasury_transfers tt')
            ->select('tt.*, f.name AS from_name, t.name AS to_name, b.code AS branch_code, tb.code AS to_branch_code')
            ->join('treasuries f', 'f.treasury_id = tt.from_treasury_id')
            ->join('treasuries t', 't.treasury_id = tt.to_treasury_id')
            ->join('branches b', 'b.branch_id = tt.branch_id')
            ->join('branches tb', 'tb.branch_id = tt.to_branch_id', 'left')
            ->where('tt.company_id', CompanyContext::id());
    }

    /**
     * The accounts the two branches of a transfer meet in: what the sending branch is owed and
     * what the receiving branch owes.
     *
     * @return array{int, int}
     *
     * @throws AccountingException
     */
    private function accountsBetweenBranches(): array
    {
        try {
            $receivable = $this->resolver->accountFor(RoutingKey::InterBranchReceivable);
            $payable    = $this->resolver->accountFor(RoutingKey::InterBranchPayable);
        } catch (PostingException) {
            $receivable = $payable = null;
        }

        if ($receivable === null || $payable === null) {
            throw new AccountingException(AccountingError::InterBranchAccountsMissing, 'A transfer between branches needs the two accounts between branches in the routing.');
        }

        return [$receivable, $payable];
    }

    private function branchCode(int $branchId): string
    {
        return (string) ($this->db->table('branches')->select('code')->where('branch_id', $branchId)->get()->getRow()->code ?? $branchId);
    }

    /**
     * Both treasuries, locked in the order of their ids so that two transfers between the same
     * pair, in opposite directions, never wait for each other.
     *
     * @return array{object, object}
     *
     * @throws AccountingException
     */
    private function lockBoth(int $fromId, int $toId): array
    {
        $table = $this->db->prefixTable('treasuries');
        $rows  = LockedRead::rows($this->db, "SELECT * FROM {$table} WHERE company_id = ? AND treasury_id IN (?, ?) ORDER BY treasury_id ASC FOR UPDATE", [CompanyContext::id(), $fromId, $toId]);
        $byId  = [];

        foreach ($rows as $row) {
            $byId[(int) $row->treasury_id] = $row;
        }

        foreach ([$fromId, $toId] as $id) {
            if (! isset($byId[$id])) {
                throw new AccountingException(AccountingError::TreasuryNotFound, "Treasury {$id} does not exist.", ['treasury_id' => $id]);
            }
        }

        return [$byId[$fromId], $byId[$toId]];
    }

    /**
     * @return numeric-string
     *
     * @throws AccountingException
     */
    private function amount(string $value): string
    {
        $value = trim($value);

        if (preg_match('/^\d{1,11}(\.\d{1,4})?$/', $value) !== 1 || bccomp($value, '0', SaleAmounts::SCALE) <= 0) {
            throw new AccountingException(AccountingError::TransferAmountInvalid, 'The amount of a transfer must be more than zero.');
        }

        return bcadd($value, '0', SaleAmounts::SCALE);
    }

    /**
     * @throws AccountingException
     */
    private function date(string $value): string
    {
        $date = DateTimeImmutable::createFromFormat('!Y-m-d', trim($value));

        if ($date === false || $date->format('Y-m-d') !== trim($value)) {
            throw new AccountingException(AccountingError::InvalidDate, 'The date is not a valid date.');
        }

        return $date->format('Y-m-d');
    }
}
