<?php

namespace App\Libraries\Accounting\Backfill;

use App\Libraries\Accounting\AccountingError;
use App\Libraries\Accounting\AccountingException;
use App\Libraries\Accounting\JournalStatus;
use App\Libraries\Accounting\LockedRead;
use App\Libraries\Organization\CompanyContext;
use CodeIgniter\Database\BaseConnection;

/**
 * The rows of the batches and of the documents each looked at, and what a screen shows of them. A
 * reader sees the batches of the branches they work in, and the batches of the whole company only if
 * they work in every branch.
 */
final class BackfillBatches
{
    private const SELECT = 'b.*, br.code AS branch_code';

    public function __construct(private readonly BaseConnection $db)
    {
    }

    /**
     * @param array<string, mixed> $values
     */
    public function insert(array $values): int
    {
        $this->db->table('accounting_backfill_batches')->insert($values + ['company_id' => CompanyContext::id()]);

        return (int) $this->db->insertID();
    }

    /**
     * @param array<string, mixed> $values
     */
    public function update(int $batchId, array $values): void
    {
        $this->db->table('accounting_backfill_batches')->where('batch_id', $batchId)->update($values);
    }

    public function find(int $batchId): ?BackfillBatch
    {
        $row = $this->db->table('accounting_backfill_batches b')
            ->select(self::SELECT)
            ->join('branches br', 'br.branch_id = b.branch_id', 'left')
            ->where('b.company_id', CompanyContext::id())
            ->where('b.batch_id', $batchId)
            ->get()
            ->getRow();

        return $row === null ? null : BackfillBatch::fromRow($row);
    }

    /**
     * The batch as last committed, with its row locked until the transaction ends.
     *
     * @throws AccountingException
     */
    public function lock(int $batchId): ?BackfillBatch
    {
        $table = $this->db->prefixTable('accounting_backfill_batches');
        $row   = LockedRead::row($this->db, "SELECT b.*, NULL AS branch_code FROM {$table} b WHERE b.company_id = ? AND b.batch_id = ? FOR UPDATE", [CompanyContext::id(), $batchId]);

        return $row === null ? null : BackfillBatch::fromRow($row);
    }

    /**
     * @param list<int>|null $branchIds The branches the reader works in, null for every branch.
     *
     * @return list<BackfillBatch>
     */
    public function all(?array $branchIds): array
    {
        if ($branchIds === []) {
            return [];
        }

        $builder = $this->db->table('accounting_backfill_batches b')
            ->select(self::SELECT)
            ->join('branches br', 'br.branch_id = b.branch_id', 'left')
            ->where('b.company_id', CompanyContext::id());

        if ($branchIds !== null) {
            $builder->whereIn('b.branch_id', $branchIds);
        }

        return array_map(BackfillBatch::fromRow(...), $builder->orderBy('b.batch_id', 'DESC')->get()->getResult());
    }

    /**
     * The batches that are still open and cover any of the days, branches and families given.
     *
     * @param list<BackfillFamily> $families
     */
    public function overlapping(string $from, string $to, ?int $branchId, array $families): ?BackfillBatch
    {
        $builder = $this->db->table('accounting_backfill_batches b')
            ->select(self::SELECT)
            ->join('branches br', 'br.branch_id = b.branch_id', 'left')
            ->where('b.company_id', CompanyContext::id())
            ->whereIn('b.status', array_map(static fn (BackfillStatus $status): string => $status->value, array_filter(BackfillStatus::cases(), static fn (BackfillStatus $status): bool => $status->isActive())))
            ->where('b.start_date <=', $to)
            ->where('b.end_date >=', $from);

        if ($branchId !== null) {
            $builder->groupStart()->where('b.branch_id IS NULL', null, false)->orWhere('b.branch_id', $branchId)->groupEnd();
        }

        foreach ($builder->orderBy('b.batch_id', 'ASC')->get()->getResult() as $row) {
            $batch = BackfillBatch::fromRow($row);

            if (array_intersect(array_map(static fn (BackfillFamily $family): string => $family->value, $batch->families), array_map(static fn (BackfillFamily $family): string => $family->value, $families)) !== []) {
                return $batch;
            }
        }

        return null;
    }

    /**
     * @param array<string, mixed> $values
     */
    public function addItem(int $batchId, array $values): void
    {
        $this->db->table('accounting_backfill_items')->insert(['batch_id' => $batchId] + $values);
    }

    /**
     * Removes the record of the documents of a batch, except the ready ones up to the item given, which are
     * the documents whose journals the batch has posted.
     *
     * @return int The documents removed.
     */
    public function removeItems(int $batchId, int $keepThroughItemId = 0): int
    {
        $this->db->table('accounting_backfill_items')
            ->where('batch_id', $batchId)
            ->groupStart()->where('outcome <>', 'ready')->orWhere('item_id >', $keepThroughItemId)->groupEnd()
            ->delete();

        return $this->db->affectedRows();
    }

    public function previewCount(int $batchId): int
    {
        return (int) $this->db->table('accounting_journals')->where('backfill_batch_id', $batchId)->where('status', JournalStatus::Preview->value)->countAllResults();
    }

    /**
     * @return list<object>
     */
    public function itemsAfter(int $batchId, int $afterItemId, int $limit): array
    {
        return $this->db->table('accounting_backfill_items')
            ->where('batch_id', $batchId)
            ->where('outcome', 'ready')
            ->where('item_id >', $afterItemId)
            ->orderBy('item_id', 'ASC')
            ->limit($limit)
            ->get()
            ->getResult();
    }

    /**
     * The documents of a batch, a family at a time and by id, which is the order a fingerprint is made in.
     *
     * @return list<object>
     */
    public function itemsOf(int $batchId, BackfillFamily $family): array
    {
        return $this->db->table('accounting_backfill_items')
            ->select('document_id, outcome, reason, digest')
            ->where('batch_id', $batchId)
            ->where('family', $family->value)
            ->orderBy('document_id', 'ASC')
            ->get()
            ->getResult();
    }

    /**
     * The documents of a batch of one family after a document, by id, as many as asked for: what a chunk of the
     * check of an approval reads to set beside the documents it finds now.
     *
     * @return list<object>
     */
    public function itemsAfterDocument(int $batchId, BackfillFamily $family, int $afterDocumentId, int $limit): array
    {
        return $this->db->table('accounting_backfill_items')
            ->select('document_id, outcome, reason, digest')
            ->where('batch_id', $batchId)
            ->where('family', $family->value)
            ->where('document_id >', $afterDocumentId)
            ->orderBy('document_id', 'ASC')
            ->limit(max(1, $limit))
            ->get()
            ->getResult();
    }

    /**
     * How many documents of a batch whose approval is checking them the check has been through: those of the
     * families before the one at its cursor and those of that family up to the cursor.
     */
    public function verifiedCount(BackfillBatch $batch): int
    {
        $count = 0;

        foreach ($batch->families as $family) {
            $builder = $this->db->table('accounting_backfill_items')->where(['batch_id' => $batch->id, 'family' => $family->value]);

            if ($family === $batch->cursorFamily) {
                return $count + $builder->where('document_id <=', $batch->cursorId)->countAllResults();
            }

            $count += $builder->countAllResults();
        }

        return $count;
    }

    /**
     * @return array{documents: int, ready: int, refused: int, journals: int, debit: string}
     */
    public function figures(int $batchId): array
    {
        $row = $this->db->table('accounting_backfill_items')
            ->select("COUNT(*) AS documents, COALESCE(SUM(outcome = 'ready'), 0) AS ready, COALESCE(SUM(outcome = 'refused'), 0) AS refused, COALESCE(SUM(journal_count), 0) AS journals, COALESCE(SUM(CASE WHEN outcome = 'ready' THEN total_debit ELSE 0 END), 0) AS debit", false)
            ->where('batch_id', $batchId)
            ->get()
            ->getRow();

        return ['documents' => (int) $row->documents, 'ready' => (int) $row->ready, 'refused' => (int) $row->refused, 'journals' => (int) $row->journals, 'debit' => bcadd((string) $row->debit, '0', 4)];
    }

    /**
     * @return list<int> The branches of the documents that are ready, which is where a batch writes.
     */
    public function readyBranches(int $batchId): array
    {
        $rows = $this->db->table('accounting_backfill_items')->select('branch_id')->distinct()->where('batch_id', $batchId)->where('outcome', 'ready')->orderBy('branch_id', 'ASC')->get()->getResultArray();

        return array_map('intval', array_column($rows, 'branch_id'));
    }

    /**
     * @return array{total: int, rows: list<object>}
     */
    public function items(int $batchId, ?string $outcome, int $limit, int $offset): array
    {
        $count = $this->db->table('accounting_backfill_items')->where('batch_id', $batchId);

        if ($outcome !== null) {
            $count->where('outcome', $outcome);
        }

        $total = $count->countAllResults();

        $builder = $this->db->table('accounting_backfill_items i')
            ->select('i.item_id, i.family, i.document_id, i.document_date, i.branch_id, br.code AS branch_code, i.outcome, i.reason, i.detail, i.journal_count, i.total_debit')
            ->join('branches br', 'br.branch_id = i.branch_id', 'left')
            ->where('i.batch_id', $batchId);

        if ($outcome !== null) {
            $builder->where('i.outcome', $outcome);
        }

        return ['total' => $total, 'rows' => $builder->orderBy('i.item_id', 'ASC')->limit(max(1, $limit), max(0, $offset))->get()->getResult()];
    }

    /**
     * @return array<string, int> The refused documents of a batch by reason.
     */
    public function reasons(int $batchId): array
    {
        $rows = $this->db->table('accounting_backfill_items')
            ->select('reason, COUNT(*) AS documents')
            ->where('batch_id', $batchId)
            ->where('outcome', 'refused')
            ->groupBy('reason')
            ->orderBy('documents', 'DESC')
            ->get()
            ->getResult();

        $reasons = [];

        foreach ($rows as $row) {
            $reasons[(string) $row->reason] = (int) $row->documents;
        }

        return $reasons;
    }

    /**
     * The refused documents of a batch grouped by what refused them, with what they come to: the refusal of
     * the posting, which is many different reasons, is told apart by the reason it gave. A refused document
     * is worth what the document says, a return less than nothing, so the net of a class is what the books
     * miss of it and the gross is the size of what is left out.
     *
     * @return list<array{reason: string, kind: string, documents: int, net: string, gross: string}>
     */
    public function refusalClasses(int $batchId): array
    {
        $rows = $this->db->table('accounting_backfill_items')
            ->select("reason, CASE WHEN reason = 'posting_refused' THEN COALESCE(detail, '') ELSE '' END AS kind, COUNT(*) AS documents, COALESCE(SUM(total_debit), 0) AS net, COALESCE(SUM(ABS(total_debit)), 0) AS gross", false)
            ->where('batch_id', $batchId)
            ->where('outcome', 'refused')
            ->groupBy('reason, kind')
            ->orderBy('documents', 'DESC')
            ->orderBy('reason', 'ASC')
            ->get()
            ->getResult();

        return array_map(static fn (object $row): array => [
            'reason'    => (string) $row->reason,
            'kind'      => (string) $row->kind,
            'documents' => (int) $row->documents,
            'net'       => bcadd((string) $row->net, '0', 4),
            'gross'     => bcadd((string) $row->gross, '0', 4),
        ], $rows);
    }

    /**
     * Every refused document of a batch, in the order they were looked at, for a file the owner corrects them from.
     *
     * @return list<object>
     */
    public function refusedItems(int $batchId): array
    {
        return $this->db->table('accounting_backfill_items i')
            ->select('i.family, i.document_id, i.document_date, br.code AS branch_code, i.reason, i.detail, i.total_debit')
            ->join('branches br', 'br.branch_id = i.branch_id', 'left')
            ->where('i.batch_id', $batchId)
            ->where('i.outcome', 'refused')
            ->orderBy('i.item_id', 'ASC')
            ->get()
            ->getResult();
    }

    /**
     * The documents of a batch that fall outside every fiscal year, and the days of the first and the last of them.
     *
     * @return array{documents: int, from: string, to: string}|null Null when none does.
     */
    public function outsideFiscalYears(int $batchId): ?array
    {
        $row = $this->db->table('accounting_backfill_items')
            ->select('COUNT(*) AS documents, MIN(document_date) AS first_day, MAX(document_date) AS last_day', false)
            ->where('batch_id', $batchId)
            ->where('outcome', 'refused')
            ->where('reason', BackfillRefusal::OutsideFiscalYear->value)
            ->get()
            ->getRow();

        return (int) $row->documents === 0 ? null : ['documents' => (int) $row->documents, 'from' => (string) $row->first_day, 'to' => (string) $row->last_day];
    }

    /**
     * The journals a batch writes, one row for each, with the document each belongs to.
     *
     * @return array{total: int, rows: list<object>}
     */
    public function journals(int $batchId, int $limit, int $offset): array
    {
        $total = $this->db->table('accounting_journals')->where('backfill_batch_id', $batchId)->countAllResults();

        $rows = $this->db->table('accounting_journals j')
            ->select('j.journal_id, j.journal_date, j.number, j.status, j.source_type, j.source_id, j.entry_type, j.total_debit, j.branch_id, br.code AS branch_code, j.description')
            ->join('branches br', 'br.branch_id = j.branch_id', 'left')
            ->where('j.backfill_batch_id', $batchId)
            ->orderBy('j.journal_id', 'ASC')
            ->limit(max(1, $limit), max(0, $offset))
            ->get()
            ->getResult();

        return ['total' => $total, 'rows' => $rows];
    }

    /**
     * @return list<object>
     */
    public function lines(int $journalId): array
    {
        return $this->db->table('accounting_journal_lines l')
            ->select('l.line_no, l.account_id, a.code AS account_code, a.name AS account_name, l.debit, l.credit')
            ->join('accounting_accounts a', 'a.account_id = l.account_id')
            ->where('l.journal_id', $journalId)
            ->orderBy('l.line_no', 'ASC')
            ->get()
            ->getResult();
    }

    /**
     * What the batch does to each account it touches: the balance the books hold without it, what it
     * adds, and the balance with it. The balance counts the journals the books count and none of the
     * previews, and, for a batch that was approved, none of its own journals either.
     *
     * @return list<object>
     */
    public function accountTotals(BackfillBatch $batch): array
    {
        $journals = $this->db->prefixTable('accounting_journals');
        $lines    = $this->db->prefixTable('accounting_journal_lines');
        $accounts = $this->db->prefixTable('accounting_accounts');
        $scope    = $batch->branchId === null ? '' : ' AND j.branch_id = ' . (int) $batch->branchId;

        $rows = $this->db->query(
            "SELECT a.account_id, a.code, a.name, COALESCE(b.balance, 0) AS before_balance, p.debit, p.credit
             FROM (
                 SELECT l.account_id, SUM(l.debit) AS debit, SUM(l.credit) AS credit
                 FROM {$lines} l JOIN {$journals} j ON j.journal_id = l.journal_id
                 WHERE j.backfill_batch_id = ?
                 GROUP BY l.account_id
             ) p
             JOIN {$accounts} a ON a.account_id = p.account_id
             LEFT JOIN (
                 SELECT l.account_id, SUM(l.debit - l.credit) AS balance
                 FROM {$lines} l JOIN {$journals} j ON j.journal_id = l.journal_id
                 WHERE j.status IN (?, ?) AND (j.backfill_batch_id IS NULL OR j.backfill_batch_id <> ?){$scope}
                 GROUP BY l.account_id
             ) b ON b.account_id = p.account_id
             ORDER BY a.code ASC",
            [$batch->id, JournalStatus::Posted->value, JournalStatus::Reversed->value, $batch->id],
        )->getResult();

        foreach ($rows as $row) {
            $row->before_balance = bcadd((string) $row->before_balance, '0', 4);
            $row->debit          = bcadd((string) $row->debit, '0', 4);
            $row->credit         = bcadd((string) $row->credit, '0', 4);
            $row->after_balance  = bcadd(bcadd($row->before_balance, $row->debit, 4), bcsub('0', $row->credit, 4), 4);
        }

        return $rows;
    }

    /**
     * @throws AccountingException
     */
    public function require(int $batchId): BackfillBatch
    {
        return $this->find($batchId) ?? throw new AccountingException(AccountingError::BackfillNotFound, "Batch {$batchId} does not exist.");
    }
}
