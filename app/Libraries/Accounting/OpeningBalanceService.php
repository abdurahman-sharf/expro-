<?php

namespace App\Libraries\Accounting;

use App\Libraries\Organization\BranchError;
use App\Libraries\Organization\BranchException;
use App\Libraries\Organization\BranchGuard;
use App\Libraries\Organization\CompanyContext;
use CodeIgniter\Database\BaseBuilder;
use CodeIgniter\Database\BaseConnection;
use Config\Database;

/**
 * The balances each branch starts with. A branch has one opening journal, balanced by itself,
 * dated on the accounting start date. It is first kept as a draft that can change freely, then
 * posted. A posted one is not edited: it is reversed and a new one is made.
 *
 * Only balance sheet accounts take part. Revenue and expenses start at zero, and the control
 * accounts of customers, suppliers, stock and VAT wait for the screens that own those
 * balances. The difference between the two sides can go to the opening balance equity account.
 */
final class OpeningBalanceService
{
    use RunsInTransaction;

    public const SOURCE_TYPE     = 'opening';
    private const MAX_LINES      = 500;
    private const AMOUNT_PATTERN = '/^\d{1,11}(\.\d{1,4})?$/';
    private const SCALE          = 4;
    private const CONTROL_KEYS   = [
        RoutingKey::Customers,
        RoutingKey::CounterCustomers,
        RoutingKey::Suppliers,
        RoutingKey::StockLocations,
        RoutingKey::TaxOutput,
        RoutingKey::TaxInput,
    ];

    private BaseConnection $db;
    private AuditLog $audit;
    private AccountingGuard $guard;
    private BranchGuard $branches;
    private PeriodLocks $locks;

    public function __construct(?BaseConnection $db = null, ?AccountingGuard $guard = null, ?BranchGuard $branches = null)
    {
        $this->db       = $db ?? Database::connect();
        $this->audit    = new AuditLog($this->db);
        $this->guard    = $guard ?? new AccountingGuard();
        $this->branches = $branches ?? new BranchGuard($this->db);
        $this->locks    = new PeriodLocks($this->db);
    }

    /**
     * The accounts a line may use, in code order.
     *
     * @return list<object>
     */
    public function allowedAccounts(): array
    {
        return $this->allowedAccountsBuilder()->select('a.account_id, a.code, a.name, a.account_type')->orderBy('a.code', 'ASC')->get()->getResult();
    }

    /**
     * The query behind the accounts a line may use, on the accounts table under the alias a, so that the picker
     * that searches them offers exactly what the list does.
     */
    public function allowedAccountsBuilder(): BaseBuilder
    {
        $blocked = $this->controlAccountIds();

        $builder = $this->db->table('accounting_accounts a')
            ->where('a.company_id', CompanyContext::id())
            ->where('a.is_group', 0)
            ->where('a.active', 1)
            ->whereIn('a.account_type', [AccountType::Asset->value, AccountType::Liability->value, AccountType::Equity->value])
            ->groupStart()
            ->where('a.system_key IS NULL', null, false)
            ->orWhere('a.system_key !=', RoutingKey::OpeningBalance->defaultSystemKey())
            ->groupEnd();

        if ($blocked !== []) {
            $builder->whereNotIn('a.account_id', $blocked);
        }

        return $builder;
    }

    /**
     * The control accounts and everything below them: the sub-accounts of customers, suppliers
     * and stock locations hold balances that belong to the screens of those entities.
     *
     * @return list<int>
     */
    private function controlAccountIds(): array
    {
        $routing = $this->db->table('accounting_routing')
            ->select('account_id')
            ->where('company_id', CompanyContext::id())
            ->whereIn('routing_key', array_map(static fn (RoutingKey $key): string => $key->value, self::CONTROL_KEYS))
            ->where('account_id IS NOT NULL')
            ->get()
            ->getResultArray();

        $found    = array_map('intval', array_column($routing, 'account_id'));
        $frontier = $found;

        while ($frontier !== []) {
            $children = $this->db->table('accounting_accounts')
                ->select('account_id')
                ->where('company_id', CompanyContext::id())
                ->whereIn('parent_id', $frontier)
                ->get()
                ->getResultArray();

            $frontier = array_values(array_diff(array_map('intval', array_column($children, 'account_id')), $found));
            $found    = [...$found, ...$frontier];
        }

        return $found;
    }

    /**
     * What the branch has now: the posted opening journal with its lines, or the draft.
     *
     * @return array{start_date: ?string, posted: ?object, draft: list<array{account_id: int, description: string, debit: string, credit: string}>|null}
     *
     * @throws BranchException
     */
    public function state(int $branchId, ?int $employeeId): array
    {
        $this->requireBranch($branchId, $employeeId);

        $posted = $this->postedJournal($branchId);

        if ($posted !== null) {
            $posted->lines = $this->db->table('accounting_journal_lines l')
                ->select('l.account_id, l.description, l.debit, l.credit, a.code AS account_code, a.name AS account_name')
                ->join('accounting_accounts a', 'a.account_id = l.account_id')
                ->where('l.journal_id', $posted->journal_id)
                ->orderBy('l.line_no', 'ASC')
                ->get()
                ->getResult();
        }

        $draft = $this->db->table('accounting_opening_drafts')->where('branch_id', $branchId)->get()->getRow();

        return [
            'start_date' => (new AccountingSettings($this->db))->startDate(),
            'posted'     => $posted,
            'draft'      => $draft === null ? null : json_decode($draft->lines, true),
        ];
    }

    /**
     * @param list<array<string, mixed>> $lines
     *
     * @throws AccountingException|BranchException
     */
    public function saveDraft(int $branchId, array $lines, ?int $employeeId): void
    {
        $this->requireManage($branchId, $employeeId);
        $this->assertNotPosted($branchId);

        $lines = $this->normalizeLines($lines);

        $this->transactional(function () use ($branchId, $lines, $employeeId): void {
            $this->locks->holdBranch($branchId);
            $this->assertNotPosted($branchId);

            $this->db->query(
                'INSERT INTO ' . $this->db->prefixTable('accounting_opening_drafts') . ' (branch_id, company_id, `lines`, updated_by)
                 VALUES (?, ?, ?, ?)
                 ON DUPLICATE KEY UPDATE `lines` = VALUES(`lines`), updated_by = VALUES(updated_by)',
                [$branchId, CompanyContext::id(), json_encode($lines, JSON_UNESCAPED_UNICODE), $employeeId],
            );

            $this->audit->write('opening_balance', $branchId, 'opening_draft_saved', $employeeId, JournalOrigin::User, ['lines' => count($lines)], $branchId);
        });
    }

    /**
     * @throws AccountingException|BranchException
     */
    public function discardDraft(int $branchId, ?int $employeeId): void
    {
        $this->requireManage($branchId, $employeeId);

        $this->db->table('accounting_opening_drafts')->where('branch_id', $branchId)->delete();

        $this->audit->write('opening_balance', $branchId, 'opening_draft_discarded', $employeeId, JournalOrigin::User, [], $branchId);
    }

    /**
     * Posts the opening journal of the branch from the given lines, or from the stored draft
     * when none are given. With $balanceWithOpening the difference between the two sides is
     * put on the opening balance equity account.
     *
     * The branch's period lock is held exclusively while the journal is written, so two posts
     * for the same branch run one after the other and the second finds the first one's journal.
     * The check and the revision are read after the lock is taken, from a transaction this
     * method starts.
     *
     * @param list<array<string, mixed>>|null $lines
     *
     * @throws AccountingException|BranchException|PostingException
     */
    public function post(int $branchId, ?array $lines, bool $balanceWithOpening, ?int $employeeId): int
    {
        $this->requireManage($branchId, $employeeId);
        $this->assertNotPosted($branchId);

        $startDate = (new AccountingSettings($this->db))->startDate();

        if ($startDate === null) {
            throw new AccountingException(AccountingError::OpeningStartDateMissing, 'The accounting start date is not set.');
        }

        $lines = $this->normalizeLines($lines ?? $this->storedDraft($branchId));
        $lines = $this->balanced($lines, $balanceWithOpening);

        return $this->transactional(function () use ($branchId, $lines, $startDate, $employeeId): int {
            $this->locks->holdBranch($branchId);
            $this->assertNotPosted($branchId);

            $this->db->table('accounting_opening_drafts')->where('branch_id', $branchId)->delete();

            $draft = new JournalDraft(
                date: $startDate,
                lines: array_map(static fn (array $line): JournalLineDraft => new JournalLineDraft(
                    accountId: $line['account_id'],
                    debit: $line['debit'],
                    credit: $line['credit'],
                    description: $line['description'] === '' ? null : $line['description'],
                ), $lines),
                sourceType: self::SOURCE_TYPE,
                sourceId: $branchId,
                revision: $this->nextRevision($branchId),
                origin: JournalOrigin::User,
                employeeId: $employeeId,
                branchId: $branchId,
            );

            (new TreasuryScopes($this->db))->assertMayPost($draft->lines, $employeeId);

            $result = (new PostingService($this->db))->post($draft);

            if (! $result->created) {
                throw new AccountingException(AccountingError::OpeningAlreadyPosted, 'The branch already has posted opening balances. Reverse them first.');
            }

            return $result->journalId;
        });
    }

    /**
     * @throws AccountingException|BranchException|PostingException
     */
    public function reverse(int $branchId, string $date, string $reason, ?int $employeeId): int
    {
        $this->requireManage($branchId, $employeeId);

        $posted = $this->postedJournal($branchId);

        if ($posted === null) {
            throw new AccountingException(AccountingError::OpeningNotPosted, 'The branch has no posted opening balances.');
        }

        return (new JournalService($this->db, $this->guard, null, $this->branches))->reverseOpening((int) $posted->journal_id, $date, $reason, $employeeId);
    }

    /**
     * @throws AccountingException|BranchException
     */
    private function requireManage(int $branchId, ?int $employeeId): void
    {
        $this->guard->require(AccountingGuard::OPENING_MANAGE, $employeeId);
        $this->requireBranch($branchId, $employeeId);
    }

    /**
     * @throws BranchException
     */
    private function requireBranch(int $branchId, ?int $employeeId): void
    {
        $exists = $this->db->table('branches')->where('branch_id', $branchId)->where('company_id', CompanyContext::id())->countAllResults() > 0;

        if (! $exists) {
            throw new BranchException(BranchError::NotFound, "Branch {$branchId} does not exist.", ['branch_id' => $branchId]);
        }

        $this->branches->requireBranch($branchId, $employeeId);
    }

    private function postedJournal(int $branchId): ?object
    {
        return $this->db->table('accounting_journals')
            ->where('company_id', CompanyContext::id())
            ->where('source_type', self::SOURCE_TYPE)
            ->where('source_id', $branchId)
            ->where('status', JournalStatus::Posted->value)
            ->get()
            ->getRow();
    }

    private function assertNotPosted(int $branchId): void
    {
        if ($this->postedJournal($branchId) !== null) {
            throw new AccountingException(AccountingError::OpeningAlreadyPosted, 'The branch already has posted opening balances. Reverse them first.');
        }
    }

    private function nextRevision(int $branchId): int
    {
        $row = $this->db->table('accounting_journals')
            ->selectMax('revision', 'latest')
            ->where('source_type', self::SOURCE_TYPE)
            ->where('source_id', $branchId)
            ->get()
            ->getRow();

        return (int) ($row->latest ?? 0) + 1;
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function storedDraft(int $branchId): array
    {
        $draft = $this->db->table('accounting_opening_drafts')->where('branch_id', $branchId)->get()->getRow();

        return $draft === null ? [] : (json_decode($draft->lines, true) ?: []);
    }

    /**
     * @param list<array<string, mixed>> $raw
     *
     * @return list<array{account_id: int, description: string, debit: string, credit: string}>
     *
     * @throws AccountingException
     */
    private function normalizeLines(array $raw): array
    {
        $allowed = array_map('intval', array_column($this->allowedAccounts(), 'account_id'));
        $lines   = [];

        foreach (array_values($raw) as $index => $line) {
            $lineNumber  = $index + 1;
            $line        = is_array($line) ? $line : [];
            $accountId   = (int) ($line['account_id'] ?? 0);
            $description = mb_substr(trim((string) ($line['description'] ?? '')), 0, 255);
            $debit       = $this->amount($line['debit'] ?? '', $lineNumber);
            $credit      = $this->amount($line['credit'] ?? '', $lineNumber);
            $hasDebit    = bccomp($debit, '0', self::SCALE) > 0;
            $hasCredit   = bccomp($credit, '0', self::SCALE) > 0;

            if ($accountId === 0 && $description === '' && ! $hasDebit && ! $hasCredit) {
                continue;
            }

            if ($accountId === 0) {
                throw new AccountingException(AccountingError::LineAccountRequired, "Line {$lineNumber} needs an account.", ['line' => $lineNumber]);
            }

            if (! in_array($accountId, $allowed, true)) {
                throw new AccountingException(AccountingError::OpeningAccountNotAllowed, "Account {$accountId} cannot take an opening balance.", ['line' => $lineNumber, 'account_id' => $accountId]);
            }

            if ($hasDebit && $hasCredit) {
                throw new AccountingException(AccountingError::LineOnBothSides, "Line {$lineNumber} has both a debit and a credit.", ['line' => $lineNumber]);
            }

            if (! $hasDebit && ! $hasCredit) {
                throw new AccountingException(AccountingError::LineAmountInvalid, "Line {$lineNumber} has no amount.", ['line' => $lineNumber]);
            }

            $lines[] = ['account_id' => $accountId, 'description' => $description, 'debit' => $debit, 'credit' => $credit];
        }

        if ($lines === []) {
            throw new AccountingException(AccountingError::NoLines, 'There are no balances to save.');
        }

        if (count($lines) > self::MAX_LINES) {
            throw new AccountingException(AccountingError::TooManyLines, 'There are too many lines.', ['max' => self::MAX_LINES]);
        }

        return $lines;
    }

    /**
     * @param list<array{account_id: int, description: string, debit: string, credit: string}> $lines
     *
     * @return list<array{account_id: int, description: string, debit: string, credit: string}>
     *
     * @throws AccountingException
     */
    private function balanced(array $lines, bool $balanceWithOpening): array
    {
        $debit  = '0';
        $credit = '0';

        foreach ($lines as $line) {
            $debit  = bcadd($debit, $line['debit'], self::SCALE);
            $credit = bcadd($credit, $line['credit'], self::SCALE);
        }

        $difference = bccomp($debit, $credit, self::SCALE);

        if ($difference === 0) {
            return $lines;
        }

        if (! $balanceWithOpening) {
            throw new AccountingException(AccountingError::OpeningUnbalanced, 'The opening balances are not balanced.', ['debit' => $debit, 'credit' => $credit]);
        }

        $account = $this->db->table('accounting_routing')
            ->select('account_id')
            ->where('company_id', CompanyContext::id())
            ->where('routing_key', RoutingKey::OpeningBalance->value)
            ->get()
            ->getRow();

        if ($account === null || $account->account_id === null) {
            throw new AccountingException(AccountingError::OpeningUnbalanced, 'The opening balance equity account is not set.', ['debit' => $debit, 'credit' => $credit]);
        }

        $lines[] = [
            'account_id'  => (int) $account->account_id,
            'description' => '',
            'debit'       => $difference < 0 ? bcsub($credit, $debit, self::SCALE) : '0',
            'credit'      => $difference > 0 ? bcsub($debit, $credit, self::SCALE) : '0',
        ];

        return $lines;
    }

    private function amount(mixed $value, int $lineNumber): string
    {
        $text = trim((string) $value);

        if ($text === '') {
            return '0';
        }

        if (preg_match(self::AMOUNT_PATTERN, $text) !== 1) {
            throw new AccountingException(AccountingError::LineAmountInvalid, "The amount on line {$lineNumber} is not valid.", ['line' => $lineNumber]);
        }

        return bcadd($text, '0', self::SCALE);
    }
}
