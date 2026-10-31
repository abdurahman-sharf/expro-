<?php

namespace App\Libraries\Accounting;

use App\Libraries\Numbering\DocumentNumbering;
use App\Libraries\Numbering\DocumentType;
use App\Libraries\Organization\BranchException;
use App\Libraries\Organization\BranchGuard;
use App\Libraries\Organization\CompanyContext;
use CodeIgniter\Database\BaseConnection;
use Config\Database;
use DateTimeImmutable;

/**
 * The receipt vouchers: money received into a treasury that is not a sale, with the tax in it. Each
 * has one journal, written when it is saved. One that changes in what the journal says is reversed
 * and posted again as the next revision, and one that is deleted is reversed, so the books never
 * keep an edited or an orphaned journal. The voucher belongs to the branch of its treasury.
 */
final class RevenueVouchers
{
    use RunsInTransaction;

    public const ENTITY    = 'revenue';
    private const NOTE_MAX = 500;

    private BaseConnection $db;
    private BranchGuard $branches;
    private DocumentJournals $journals;
    private TreasuryService $treasuries;
    private RevenuePostingBuilder $builder;
    private AuditLog $audit;

    public function __construct(?BaseConnection $db = null, ?BranchGuard $branches = null)
    {
        $this->db         = $db ?? Database::connect();
        $this->branches   = $branches ?? new BranchGuard($this->db);
        $this->journals   = new DocumentJournals($this->db, $this->branches);
        $this->treasuries = new TreasuryService($this->db, null, $this->branches);
        $this->builder    = new RevenuePostingBuilder(new RoutingResolver($this->db));
        $this->audit      = new AuditLog($this->db);
    }

    /**
     * Saves a voucher, new or changed. The amount is what was received, tax included, and the tax is
     * the part of it that is tax. Without a treasury the voucher goes to the treasury the employee
     * works from, or else to the account of its way of payment; a voucher that is changed and names no
     * treasury keeps the one it has.
     *
     * @param array{date?: string, amount?: string, tax_amount?: string, payment_type?: string, treasury_id?: int|string, category_id?: int|string, account_id?: int|string, description?: string} $input
     *
     * @return int The voucher.
     *
     * @throws AccountingException|BranchException|PostingException
     */
    public function save(array $input, ?int $revenueId = null, ?int $employeeId = null): int
    {
        $data = $this->validated($input);

        return $this->attempt(fn (): int => $this->transactional(function () use ($data, $input, $revenueId, $employeeId): int {
            $stored = $revenueId === null ? null : $this->locked($revenueId);

            if ($stored !== null) {
                $this->branches->requireBranch((int) $stored->branch_id, $employeeId);
            }

            $this->assertReferences($data, $stored);
            $this->assertAccountChosen($data, $stored);

            [$branchId, $treasuryId] = $this->place($input, $stored, (string) $data['payment_type'], $employeeId);

            $this->branches->requireBranch($branchId, $employeeId);

            $row = [...$data, 'branch_id' => $branchId, 'treasury_id' => $treasuryId, 'treasury_branch_id' => null];

            if ($stored === null) {
                $this->db->table('revenues')->insert([...$row, 'company_id' => CompanyContext::id(), 'employee_id' => $employeeId]);

                $revenueId = (int) $this->db->insertID();

                (new DocumentNumbering($this->db))->assign(DocumentType::Revenue, 'revenues', $revenueId, $branchId);
            } else {
                $this->db->table('revenues')->where('revenue_id', $revenueId)->update($row);
            }

            $change = $this->sync((int) $revenueId, $employeeId, 'Receipt voucher #' . $revenueId . ' changed');

            $this->audit->write(self::ENTITY, (int) $revenueId, $stored === null ? 'created' : ($change === JournalChange::Replaced ? 'reposted' : 'updated'), $employeeId, JournalOrigin::User, ['amount' => $data['amount'], 'tax_amount' => $data['tax_amount']], $branchId);

            return (int) $revenueId;
        }), $revenueId, $employeeId);
    }

    /**
     * Deletes vouchers, all or none: the journal of each is reversed with it.
     *
     * @param list<int> $revenueIds
     *
     * @throws AccountingException|BranchException|PostingException
     */
    public function delete(array $revenueIds, ?int $employeeId = null): void
    {
        $ids = array_values(array_unique(array_map('intval', $revenueIds)));

        sort($ids);

        $current = null;

        try {
            $this->transactional(function () use ($ids, $employeeId, &$current): void {
                foreach ($ids as $revenueId) {
                    $current = $revenueId;
                    $stored  = $this->locked($revenueId);

                    $this->branches->requireBranch((int) $stored->branch_id, $employeeId);
                    $this->db->table('revenues')->where('revenue_id', $revenueId)->update(['deleted' => 1]);
                    $this->sync($revenueId, $employeeId, 'Receipt voucher #' . $revenueId . ' deleted');
                    $this->audit->write(self::ENTITY, $revenueId, 'deleted', $employeeId, JournalOrigin::User, ['amount' => $stored->amount], (int) $stored->branch_id);
                }
            });
        } catch (PostingException $failure) {
            $this->recordFailure($current, $failure, $employeeId);

            throw $failure;
        }
    }

    public function find(int $revenueId): ?object
    {
        return $this->db->table('revenues r')
            ->select("r.*, c.category_name, t.name AS treasury_name, b.code AS branch_code, a.code AS account_code, a.name AS account_name, TRIM(CONCAT(p.first_name, ' ', p.last_name)) AS employee_name", false)
            ->join('revenue_categories c', 'c.revenue_category_id = r.revenue_category_id', 'left')
            ->join('treasuries t', 't.treasury_id = r.treasury_id', 'left')
            ->join('branches b', 'b.branch_id = r.branch_id')
            ->join('accounting_accounts a', 'a.account_id = r.account_id', 'left')
            ->join('people p', 'p.person_id = r.employee_id', 'left')
            ->where('r.company_id', CompanyContext::id())
            ->where('r.revenue_id', $revenueId)
            ->get()
            ->getRow();
    }

    /**
     * @param list<int>|null                                                                                  $branchIds The branches the reader may see, null for every branch.
     * @param array{search?: string, date_from?: string, date_to?: string, category_id?: int, deleted?: bool} $filters
     *
     * @return array{total: int, rows: list<object>}
     */
    public function search(?array $branchIds, array $filters, string $sort, string $order, int $limit, int $offset): array
    {
        if ($branchIds === []) {
            return ['total' => 0, 'rows' => []];
        }

        $builder = $this->db->table('revenues r')
            ->join('revenue_categories c', 'c.revenue_category_id = r.revenue_category_id', 'left')
            ->join('treasuries t', 't.treasury_id = r.treasury_id', 'left')
            ->join('branches b', 'b.branch_id = r.branch_id')
            ->join('people p', 'p.person_id = r.employee_id', 'left')
            ->join('document_numbers n', "n.doc_type = 'revenue' AND n.source_table = 'revenues' AND n.source_id = r.revenue_id", 'left')
            ->where('r.company_id', CompanyContext::id())
            ->where('r.deleted', empty($filters['deleted']) ? 0 : 1);

        if ($branchIds !== null) {
            $builder->whereIn('r.branch_id', $branchIds);
        }

        foreach (['date_from' => '>=', 'date_to' => '<='] as $key => $operator) {
            if (($filters[$key] ?? '') !== '') {
                $builder->where("r.date {$operator}", $filters[$key]);
            }
        }

        if ((int) ($filters['category_id'] ?? 0) > 0) {
            $builder->where('r.revenue_category_id', (int) $filters['category_id']);
        }

        $term = trim((string) ($filters['search'] ?? ''));

        if ($term !== '') {
            $builder->groupStart()
                ->like('r.description', $term)
                ->orLike('c.category_name', $term)
                ->orLike('n.number', $term)
                ->orLike('r.amount', $term)
                ->orLike('p.first_name', $term)
                ->orLike('p.last_name', $term)
                ->groupEnd();
        }

        $total   = $builder->countAllResults(false);
        $columns = ['revenue_id' => 'r.revenue_id', 'date' => 'r.date', 'amount' => 'r.amount', 'category_name' => 'c.category_name', 'treasury_name' => 't.name'];
        $rows    = $builder
            ->select("r.*, c.category_name, t.name AS treasury_name, b.code AS branch_code, n.number AS document_number, TRIM(CONCAT(p.first_name, ' ', p.last_name)) AS employee_name", false)
            ->orderBy($columns[$sort] ?? 'r.revenue_id', strtolower($order) === 'asc' ? 'ASC' : 'DESC')
            ->orderBy('r.revenue_id', 'DESC')
            ->limit(max(1, $limit), max(0, $offset))
            ->get()
            ->getResult();

        return ['total' => $total, 'rows' => $rows];
    }

    /**
     * The journals of a voucher, oldest first.
     *
     * @return list<object>
     */
    public function journalsOf(int $revenueId): array
    {
        return $this->db->table('accounting_journals')
            ->select('journal_id, number, series, journal_date, status, revision, total_debit, reverses_journal_id')
            ->where('source_type', RevenuePostingBuilder::SOURCE)
            ->where('source_id', $revenueId)
            ->orderBy('journal_id', 'ASC')
            ->get()
            ->getResult();
    }

    public function document(int $revenueId): ?RevenueDocument
    {
        $row = $this->db->table('revenues r')
            ->select('r.revenue_id, r.date, r.amount, r.tax_amount, r.payment_type, r.account_id, r.treasury_id, r.treasury_branch_id, r.branch_id, r.deleted, c.account_id AS category_account_id')
            ->join('revenue_categories c', 'c.revenue_category_id = r.revenue_category_id', 'left')
            ->where('r.revenue_id', $revenueId)
            ->get()
            ->getRow();

        if ($row === null) {
            return null;
        }

        return new RevenueDocument(
            revenueId: (int) $row->revenue_id,
            date: substr((string) $row->date, 0, 10),
            amount: bcadd((string) $row->amount, '0', SaleAmounts::SCALE),
            taxAmount: bcadd((string) $row->tax_amount, '0', SaleAmounts::SCALE),
            paymentType: (string) $row->payment_type,
            accountId: $row->account_id === null ? null : (int) $row->account_id,
            categoryAccountId: $row->category_account_id === null ? null : (int) $row->category_account_id,
            treasuryId: $row->treasury_id === null ? null : (int) $row->treasury_id,
            branchId: (int) $row->branch_id,
            deleted: (int) $row->deleted === 1,
            treasuryBranchId: $row->treasury_branch_id === null ? null : (int) $row->treasury_branch_id,
        );
    }

    /**
     * Brings the journal of a saved voucher in line with the voucher: posted when there is none,
     * reversed and posted again when it no longer matches, left alone when it does, and reversed when
     * the voucher is deleted.
     *
     * @throws AccountingException|PostingException
     */
    private function sync(int $revenueId, ?int $employeeId, string $reason): JournalChange
    {
        $revenue  = $this->document($revenueId);
        $revision = $this->journals->nextRevision(RevenuePostingBuilder::SOURCE, $revenueId);
        $draft    = $revenue === null || $revenue->deleted ? null : $this->builder->revenue($revenue, $revision, $employeeId);

        return $this->journals->sync(RevenuePostingBuilder::SOURCE, $revenueId, $draft, $employeeId, $reason);
    }

    /**
     * The branch a voucher belongs to and the treasury it is received into.
     *
     * @param array<string, mixed> $input
     *
     * @return array{int, ?int}
     *
     * @throws AccountingException|BranchException
     */
    private function place(array $input, ?object $stored, string $paymentType, ?int $employeeId): array
    {
        $type = PaymentType::fromLabel($paymentType);

        if ($type === null || ! $type->usesTreasury()) {
            throw new AccountingException(AccountingError::RevenuePaymentInvalid, "The payment type {$paymentType} is not one a receipt voucher is received with.", ['payment_type' => $paymentType]);
        }

        $asked    = array_key_exists('treasury_id', $input) ? (int) $input['treasury_id'] : null;
        $storedId = $stored === null || $stored->treasury_id === null ? null : (int) $stored->treasury_id;

        if ($stored !== null && ($asked === null || $asked === 0 || $asked === $storedId)) {
            return [(int) $stored->branch_id, $storedId];
        }

        if ($asked !== null && $asked > 0) {
            $treasury = $this->treasuries->find($asked);

            if ($treasury === null) {
                throw new AccountingException(AccountingError::TreasuryNotFound, "Treasury {$asked} does not exist.", ['treasury_id' => $asked]);
            }

            if ((int) $treasury->active === 0) {
                throw new AccountingException(AccountingError::TreasuryInactive, 'A receipt voucher cannot be received into a deactivated treasury.', ['treasury_id' => $asked]);
            }

            return [(int) $treasury->branch_id, $asked];
        }

        $branchId = $this->branches->defaultBranchId($employeeId);

        return [$branchId, $this->treasuries->stampFor($employeeId, $branchId, TreasuryScopes::DEPOSIT)];
    }

    /**
     * @param array<string, mixed> $input
     *
     * @return array{date: string, amount: numeric-string, tax_amount: numeric-string, payment_type: string, revenue_category_id: ?int, account_id: ?int, description: string}
     *
     * @throws AccountingException
     */
    private function validated(array $input): array
    {
        $typed = trim((string) ($input['date'] ?? ''));
        $date  = DateTimeImmutable::createFromFormat('!Y-m-d', $typed);

        if ($date === false || $date->format('Y-m-d') !== $typed) {
            throw new AccountingException(AccountingError::InvalidDate, 'The date is not a valid date.');
        }

        $amount = trim((string) ($input['amount'] ?? ''));
        $tax    = trim((string) ($input['tax_amount'] ?? '')) === '' ? '0' : trim((string) $input['tax_amount']);

        if (preg_match('/^\d{1,11}(\.\d{1,4})?$/', $amount) !== 1 || bccomp($amount, '0', SaleAmounts::SCALE) <= 0) {
            throw new AccountingException(AccountingError::RevenueAmountInvalid, 'The amount of a receipt voucher must be more than zero.');
        }

        if (preg_match('/^\d{1,11}(\.\d{1,4})?$/', $tax) !== 1 || bccomp($tax, $amount, SaleAmounts::SCALE) > 0) {
            throw new AccountingException(AccountingError::RevenueAmountInvalid, 'The tax of a receipt voucher is not less than zero or more than its amount.');
        }

        $categoryId = (int) ($input['category_id'] ?? 0);
        $accountId  = (int) ($input['account_id'] ?? 0);

        return [
            'date'                => $date->format('Y-m-d'),
            'amount'              => bcadd($amount, '0', SaleAmounts::SCALE),
            'tax_amount'          => bcadd($tax, '0', SaleAmounts::SCALE),
            'payment_type'        => trim((string) ($input['payment_type'] ?? '')),
            'revenue_category_id' => $categoryId > 0 ? $categoryId : null,
            'account_id'          => $accountId > 0 ? $accountId : null,
            'description'         => mb_substr(trim((string) ($input['description'] ?? '')), 0, self::NOTE_MAX),
        ];
    }

    /**
     * The category and the account of a voucher are ones that can be chosen, unless the voucher
     * already has them: a category that was deleted or an account that was put out of use since stays
     * on the voucher that carries it, and saving the voucher again does not move it.
     *
     * @param array<string, mixed> $data
     *
     * @throws AccountingException
     */
    private function assertReferences(array $data, ?object $stored): void
    {
        $categoryId = $data['revenue_category_id'];
        $accountId  = $data['account_id'];

        if ($categoryId !== null && ($stored === null || (int) $stored->revenue_category_id !== $categoryId)
            && $this->db->table('revenue_categories')->where(['revenue_category_id' => $categoryId, 'deleted' => 0])->countAllResults() === 0) {
            throw new AccountingException(AccountingError::RevenueCategoryNotFound, "Revenue category {$categoryId} does not exist.", ['category_id' => $categoryId]);
        }

        if ($accountId !== null && ($stored === null || (int) $stored->account_id !== $accountId)) {
            $this->assertRevenueAccount($accountId);
        }
    }

    /**
     * A voucher without an account of its own, in a category without one, is refused when the
     * settings ask for an account on every voucher, for a new voucher or when the voucher moves to
     * another account or category. A voucher that keeps both is never blocked by the setting.
     *
     * @param array<string, mixed> $data
     *
     * @throws AccountingException
     */
    private function assertAccountChosen(array $data, ?object $stored): void
    {
        if (! (new AccountingSettings($this->db))->revenueAccountRequired() || $data['account_id'] !== null) {
            return;
        }

        if ($stored !== null && (int) $stored->account_id === 0 && (int) $stored->revenue_category_id === (int) $data['revenue_category_id']) {
            return;
        }

        $category = $data['revenue_category_id'] === null ? null : $this->db->table('revenue_categories')->select('account_id')->where('revenue_category_id', $data['revenue_category_id'])->get()->getRow();

        if ($category === null || $category->account_id === null) {
            throw new AccountingException(AccountingError::RevenueAccountRequired, 'A receipt voucher must be credited to an account chosen on it or to a category that has one.');
        }
    }

    /**
     * @throws AccountingException
     */
    private function assertRevenueAccount(int $accountId): void
    {
        foreach ((new RevenueCategories($this->db))->accountOptions() as $option) {
            if ((int) $option->account_id === $accountId) {
                return;
            }
        }

        throw new AccountingException(AccountingError::RevenueAccountInvalid, "Account {$accountId} cannot take a receipt voucher.", ['account_id' => $accountId]);
    }

    /**
     * @throws AccountingException
     */
    private function locked(int $revenueId): object
    {
        $row = LockedRead::row($this->db, 'SELECT * FROM ' . $this->db->prefixTable('revenues') . ' WHERE company_id = ? AND revenue_id = ? AND deleted = 0 FOR UPDATE', [CompanyContext::id(), $revenueId]);

        if ($row === null) {
            throw new AccountingException(AccountingError::RevenueNotFound, "Receipt voucher {$revenueId} does not exist.", ['revenue_id' => $revenueId]);
        }

        return $row;
    }

    /**
     * @template T
     *
     * @param callable(): T $work
     *
     * @return T
     *
     * @throws AccountingException|BranchException|PostingException
     */
    private function attempt(callable $work, ?int $revenueId, ?int $employeeId): mixed
    {
        try {
            return $work();
        } catch (PostingException $failure) {
            $this->recordFailure($revenueId, $failure, $employeeId);

            throw $failure;
        }
    }

    private function recordFailure(?int $revenueId, PostingException $failure, ?int $employeeId): void
    {
        (new PostingFailureLog($this->db))->recordUnbuilt(RevenuePostingBuilder::SOURCE, $revenueId, 1, JournalEntryType::General, $failure, $employeeId);
    }
}
