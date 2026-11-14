<?php

namespace App\Libraries\Accounting\Backfill;

use App\Libraries\Accounting\AccountingException;
use App\Libraries\Accounting\JournalStatus;
use App\Libraries\Accounting\LockedRead;
use App\Libraries\Accounting\OpeningStock;
use App\Libraries\Accounting\PostingException;
use App\Libraries\Accounting\PostingService;
use App\Libraries\Accounting\StockMovement;
use CodeIgniter\Database\BaseConnection;

/**
 * One family of documents the retroactive posting can give journals to. It finds the documents that
 * have none and makes, through the builders the live posting uses, the journals each would have been
 * given. It never writes: the batch decides what is written and when.
 */
abstract class BackfillAdapter
{
    protected PostingService $posting;
    protected BackfillGate $gate;

    public function __construct(protected readonly BaseConnection $db, ?BackfillGate $gate = null)
    {
        $this->posting = new PostingService($this->db);
        $this->gate    = $gate ?? new BackfillGate($this->db);
    }

    abstract public function family(): BackfillFamily;

    /**
     * The documents of the scope that no journal of the books stands for, oldest id first, after the
     * one given. The previews of the batch itself do not count as journals, so that a batch finds its own
     * documents again when it is checked.
     *
     * @return list<BackfillDocument>
     */
    final public function candidates(BackfillScope $scope, int $afterId, int $limit, int $batchId): array
    {
        [$sql, $params] = $this->candidateQuery($scope, $afterId, $batchId);

        return array_map(
            fn (object $row): BackfillDocument => new BackfillDocument($this->family(), (int) $row->id, (string) $row->document_date, (int) $row->branch_id),
            $this->db->query($sql . ' ORDER BY id ASC LIMIT ' . max(1, $limit), $params)->getResult(),
        );
    }

    /**
     * How many documents of the scope no journal stands for.
     */
    final public function waiting(BackfillScope $scope, int $batchId = 0): int
    {
        [$sql, $params] = $this->candidateQuery($scope, 0, $batchId);

        return (int) $this->db->query("SELECT COUNT(*) AS waiting FROM ({$sql}) q", $params)->getRow()->waiting;
    }

    /**
     * The day of the latest document of the scope that no journal stands for, or null when there is none.
     */
    final public function latest(BackfillScope $scope, int $batchId = 0): ?string
    {
        [$sql, $params] = $this->candidateQuery($scope, 0, $batchId);

        $row = $this->db->query("SELECT MAX(document_date) AS latest FROM ({$sql}) q", $params)->getRow();

        return $row === null || $row->latest === null ? null : (string) $row->latest;
    }

    /**
     * The query that finds the documents, with the columns id, document_date and branch_id and no order and no limit.
     *
     * @return array{0: string, 1: list<int|string>}
     */
    abstract protected function candidateQuery(BackfillScope $scope, int $afterId, int $batchId): array;

    abstract public function plan(BackfillDocument $document, ?int $employeeId, int $batchId): BackfillPlan;

    /**
     * What the document is worth, which a screen shows beside a document the books refuse: a return is
     * negative. It is read from the document as it stands, whatever the plan found, and it is zero for a
     * document that cannot be read.
     *
     * @return numeric-string
     */
    public function documentAmount(BackfillDocument $document): string
    {
        return '0.0000';
    }

    /**
     * @return array{0: string, 1: string} The table of the documents and its key.
     */
    abstract protected function documentKey(): array;

    /**
     * Takes the lock of the row of a document, as a change to it does first, so that a change that is
     * running finishes before the document is read and one that starts later waits.
     *
     * @throws AccountingException
     */
    final public function lock(int $documentId): void
    {
        [$table, $key] = $this->documentKey();

        LockedRead::row($this->db, "SELECT {$key} FROM " . $this->db->prefixTable($table) . " WHERE {$key} = ? FOR UPDATE", [$documentId]);
    }

    /**
     * Whether a journal that the books count, or the preview of another batch, stands for the source
     * and the document, as a condition for a query on the table of the document.
     *
     * @param list<string> $sources
     */
    protected function noJournal(string $idColumn, array $sources): string
    {
        return 'NOT EXISTS (SELECT 1 FROM ' . $this->db->prefixTable('accounting_journals') . ' j WHERE j.source_type IN (' . $this->quoted($sources) . ") AND j.source_id = {$idColumn} AND (j.status <> " . $this->db->escape(JournalStatus::Preview->value) . ' OR j.backfill_batch_id IS NULL OR j.backfill_batch_id <> ?))';
    }

    /**
     * @param list<string> $sources
     */
    protected function noStock(string $idColumn, array $sources): string
    {
        return 'NOT EXISTS (SELECT 1 FROM ' . $this->db->prefixTable('accounting_stock_entries') . ' e WHERE e.source_type IN (' . $this->quoted($sources) . ") AND e.source_id = {$idColumn})";
    }

    /**
     * @param list<string> $sources
     * @param list<int>    $ids
     */
    protected function hasOtherJournals(array $sources, array $ids, int $batchId): bool
    {
        if ($ids === []) {
            return false;
        }

        return $this->db->query(
            'SELECT 1 FROM ' . $this->db->prefixTable('accounting_journals') . ' j WHERE j.source_type IN (' . $this->quoted($sources) . ') AND j.source_id IN (' . implode(',', array_map('intval', $ids)) . ') AND (j.status <> ' . $this->db->escape(JournalStatus::Preview->value) . ' OR j.backfill_batch_id IS NULL OR j.backfill_batch_id <> ?) LIMIT 1',
            [$batchId],
        )->getRow() !== null;
    }

    /**
     * Whether the opening stock of a location the movements touch was posted after the document was saved.
     * It is posted from the units on the shelves that no movement accounts for, and the shelves it was
     * posted from already held what the document moved, so a movement for the document would count the
     * same units twice.
     *
     * @param list<StockMovement> $movements
     */
    protected function stockOpenedAfter(string $table, string $key, string $savedColumn, int $documentId, array $movements): bool
    {
        $locations = array_values(array_unique(array_map(static fn (StockMovement $movement): int => $movement->locationId, $movements)));

        if ($locations === []) {
            return false;
        }

        return $this->db->query(
            'SELECT 1 FROM ' . $this->db->prefixTable('accounting_journals') . ' j JOIN ' . $this->db->prefixTable($table) . " d ON d.{$key} = ?"
            . ' WHERE j.source_type = ? AND j.source_id IN (' . implode(',', $locations) . ') AND j.status = ? AND j.posted_at > d.' . $savedColumn . ' LIMIT 1',
            [$documentId, OpeningStock::SOURCE, JournalStatus::Posted->value],
        )->getRow() !== null;
    }

    protected function treasuryGone(?int $treasuryId): bool
    {
        return $treasuryId !== null && $this->db->table('treasuries')->where('treasury_id', $treasuryId)->countAllResults() === 0;
    }

    protected function partyGone(string $table, ?int $personId): bool
    {
        if ($personId === null) {
            return false;
        }

        $row = $this->db->table($table)->select('deleted')->where('person_id', $personId)->get()->getRow();

        return $row === null || (int) $row->deleted === 1;
    }

    /**
     * @param list<BackfillStep>   $steps
     * @param array<string, mixed> $stamp
     *
     * @throws PostingException
     */
    protected function checked(array $steps, string $source, ?string $partyType = null, ?int $partyId = null, array $stamp = []): BackfillPlan
    {
        $steps = array_values(array_filter($steps, static fn (BackfillStep $step): bool => $step->draft !== null || $step->stock !== null));

        if ($steps === []) {
            return BackfillPlan::refused(BackfillRefusal::ZeroTotal, $source);
        }

        foreach ($steps as $step) {
            if ($step->draft === null) {
                continue;
            }

            $early = $this->gate->refusalOf($step->draft->date, (int) $step->draft->branchId);

            if ($early !== null) {
                return BackfillPlan::refused($early, $source, $step->draft->sourceType . ' ' . $step->draft->date);
            }

            $this->posting->validate($step->draft);
        }

        return new BackfillPlan($steps, $source, null, '', $partyType, $partyId, $stamp);
    }

    protected function refusedByPosting(PostingException $failure, string $source): BackfillPlan
    {
        return BackfillPlan::refused(BackfillRefusal::ofPosting($failure->error), $source, $failure->error->value);
    }

    /**
     * @param list<string> $values
     */
    private function quoted(array $values): string
    {
        return implode(',', array_map(fn (string $value): string => $this->db->escape($value), $values));
    }
}
