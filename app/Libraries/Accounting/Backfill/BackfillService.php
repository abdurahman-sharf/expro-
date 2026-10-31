<?php

namespace App\Libraries\Accounting\Backfill;

use App\Libraries\Accounting\AccountingError;
use App\Libraries\Accounting\AccountingException;
use App\Libraries\Accounting\AccountingGuard;
use App\Libraries\Accounting\AccountingSettings;
use App\Libraries\Accounting\AuditLog;
use App\Libraries\Accounting\CreditSettlement;
use App\Libraries\Accounting\CustomerLock;
use App\Libraries\Accounting\JournalEntryType;
use App\Libraries\Accounting\JournalOrigin;
use App\Libraries\Accounting\LockOutcome;
use App\Libraries\Accounting\NamedLock;
use App\Libraries\Accounting\PostingException;
use App\Libraries\Accounting\PostingService;
use App\Libraries\Accounting\RunsInTransaction;
use App\Libraries\Accounting\StockLedger;
use App\Libraries\Accounting\StockMovement;
use App\Libraries\Accounting\SupplierLock;
use App\Libraries\Accounting\SupplierSettlement;
use App\Libraries\Organization\BranchError;
use App\Libraries\Organization\BranchException;
use App\Libraries\Organization\BranchGuard;
use App\Libraries\Organization\CompanyContext;
use CodeIgniter\Database\BaseConnection;
use CodeIgniter\Database\RawSql;
use Config\Database;
use DateTimeImmutable;

/**
 * Posting what happened before the books followed it. A batch names a range of days, perhaps a
 * branch, and the families of documents to look at. Its preview finds the documents of that range
 * that no journal stands for and writes, through the builders the live posting uses, the journals each
 * would have been given, as previews that no balance and no report counts, and lists the documents it
 * refuses with the reason. Its approval turns those previews into posted journals exactly as they
 * were shown, or refuses when anything changed since, and its cancellation removes them.
 *
 * A batch is worked in chunks of documents, each in a transaction of its own, with a cursor kept on
 * the batch, so that a run that stopped goes on from where it stopped and writes nothing twice. One run
 * at a time holds a batch, and two open batches never cover the same documents.
 */
final class BackfillService
{
    use RunsInTransaction;

    public const CHUNK_DEFAULT = 200;
    public const CHUNK_MAX     = 1000;
    public const NAME_MAX      = 120;
    private const CREATE_WAIT  = 5;

    private BaseConnection $db;
    private AuditLog $audit;
    private AccountingGuard $guard;
    private BranchGuard $branches;
    private AccountingSettings $settings;
    private PostingService $posting;
    private BackfillBatches $batches;

    /**
     * @var array<string, BackfillAdapter>
     */
    private array $adapters = [];

    public function __construct(?BaseConnection $db = null, ?AccountingGuard $guard = null, ?BranchGuard $branches = null)
    {
        $this->db       = $db ?? Database::connect();
        $this->audit    = new AuditLog($this->db);
        $this->guard    = $guard ?? new AccountingGuard();
        $this->branches = $branches ?? new BranchGuard($this->db);
        $this->settings = new AccountingSettings($this->db);
        $this->posting  = new PostingService($this->db);
        $this->batches  = new BackfillBatches($this->db);
    }

    /**
     * @param array{name?: string, from?: string, to?: string, branch_id?: int|string|null, families?: list<string>|null, chunk_size?: int|string|null} $input
     *
     * @return int The batch.
     *
     * @throws AccountingException|BranchException
     */
    public function create(array $input, ?int $employeeId = null): int
    {
        $this->guard->require(AccountingGuard::BACKFILL_MANAGE, $employeeId);

        $branchId = $this->branchOf($input['branch_id'] ?? null);

        $this->requireScope($branchId, $employeeId);

        $name = trim((string) ($input['name'] ?? ''));

        if ($name === '' || mb_strlen($name) > self::NAME_MAX) {
            throw new AccountingException(AccountingError::BackfillNameInvalid, 'A batch is named with 1 to ' . self::NAME_MAX . ' characters.');
        }

        [$from, $to] = $this->range((string) ($input['from'] ?? ''), (string) ($input['to'] ?? ''));
        $families    = $this->families($input['families'] ?? null);
        $chunk       = $this->chunkSize($input['chunk_size'] ?? null);

        $this->requireBooks();

        return $this->exclusive('create', self::CREATE_WAIT, function () use ($name, $from, $to, $branchId, $families, $chunk, $employeeId): int {
            return $this->transactional(function () use ($name, $from, $to, $branchId, $families, $chunk, $employeeId): int {
                $other = $this->batches->overlapping($from, $to, $branchId, $families);

                if ($other !== null) {
                    throw new AccountingException(AccountingError::BackfillOverlaps, "Batch {$other->id} covers the same documents.", ['name' => $other->name, 'count' => $other->id]);
                }

                $batchId = $this->batches->insert([
                    'name'       => $name,
                    'status'     => BackfillStatus::Collecting->value,
                    'start_date' => $from,
                    'end_date'   => $to,
                    'branch_id'  => $branchId,
                    'families'   => implode(',', array_map(static fn (BackfillFamily $family): string => $family->value, $families)),
                    'chunk_size' => $chunk,
                    'created_by' => $employeeId,
                ]);

                $this->audit->write('backfill_batch', $batchId, 'backfill_created', $employeeId, $this->originOf($employeeId), [
                    'name'     => $name,
                    'from'     => $from,
                    'to'       => $to,
                    'families' => array_map(static fn (BackfillFamily $family): string => $family->value, $families),
                    'chunk'    => $chunk,
                ], $branchId);

                return $batchId;
            });
        });
    }

    /**
     * Looks at the documents of the batch and writes the previews of the journals each would have been
     * given, as many chunks as asked for or all of them, and from where the last run stopped. A batch
     * that is not collecting any more is returned as it is.
     *
     * @throws AccountingException|BranchException|PostingException
     */
    public function preview(int $batchId, ?int $employeeId = null, ?int $maxChunks = null): BackfillBatch
    {
        $this->guard->require(AccountingGuard::BACKFILL_MANAGE, $employeeId);

        $this->visible($batchId, $employeeId);
        $this->requireBooks();

        return $this->exclusive($this->batchLock($batchId), 0, function () use ($batchId, $employeeId, $maxChunks): BackfillBatch {
            $batch = $this->batches->require($batchId);

            if ($batch->status !== BackfillStatus::Collecting) {
                return $batch;
            }

            return $this->collect($batch, $employeeId, $maxChunks);
        });
    }

    /**
     * Posts the previews of the batch as they were shown. The fingerprint is the one the preview
     * showed: if the documents or the books are not what they were when it was made, or a document
     * that qualifies is not in it, nothing is posted and a new batch has to be previewed. The batch is
     * checked as a whole before the first journal is posted and then posted in chunks, each checked
     * again as it is written, so a run that stopped goes on from its cursor.
     *
     * @throws AccountingException|BranchException|PostingException
     */
    public function approve(int $batchId, string $fingerprint, ?int $employeeId = null, ?int $maxChunks = null): BackfillBatch
    {
        $this->guard->require(AccountingGuard::BACKFILL_MANAGE, $employeeId);

        $this->visible($batchId, $employeeId);
        $this->requireBooks();

        if (! $this->settings->backfillEnabled()) {
            throw new AccountingException(AccountingError::BackfillDisabled, 'The retroactive posting is not turned on.');
        }

        return $this->exclusive($this->batchLock($batchId), 0, function () use ($batchId, $fingerprint, $employeeId, $maxChunks): BackfillBatch {
            $batch = $this->batches->require($batchId);

            $this->requireOpen($batch);

            if ($batch->status === BackfillStatus::Collecting) {
                throw new AccountingException(AccountingError::BackfillNotReady, 'The preview of the batch is not finished.');
            }

            if ($batch->fingerprint === null || ! hash_equals($batch->fingerprint, $fingerprint)) {
                throw new AccountingException(AccountingError::BackfillPreviewChanged, 'The batch is not the one that was previewed.');
            }

            $this->requireOpenings($batch);

            if ($batch->status === BackfillStatus::Preview) {
                if ($batch->readyCount === 0) {
                    throw new AccountingException(AccountingError::BackfillNothingToApprove, 'The batch holds no document that can be posted.');
                }

                $this->adapters = [];
                $this->verify($batch, $employeeId);

                $this->batches->update($batch->id, ['status' => BackfillStatus::Approving->value, 'cursor_family' => null, 'cursor_id' => 0, 'approved_count' => 0]);
                $batch = $this->batches->require($batchId);
            }

            return $this->post($batch, $employeeId, $maxChunks);
        });
    }

    /**
     * Removes the previews of a batch and the record of the documents it looked at. Nothing the books count is
     * touched. A batch whose approval has begun is not cancelled: its approval is finished.
     *
     * @throws AccountingException|BranchException
     */
    public function cancel(int $batchId, ?int $employeeId = null): BackfillBatch
    {
        $this->guard->require(AccountingGuard::BACKFILL_MANAGE, $employeeId);

        $this->visible($batchId, $employeeId);

        return $this->exclusive($this->batchLock($batchId), 0, function () use ($batchId, $employeeId): BackfillBatch {
            return $this->transactional(function () use ($batchId, $employeeId): BackfillBatch {
                $batch = $this->batches->lock($batchId) ?? throw new AccountingException(AccountingError::BackfillNotFound, "Batch {$batchId} does not exist.");

                $this->requireOpen($batch);

                if ($batch->status === BackfillStatus::Approving) {
                    throw new AccountingException(AccountingError::BackfillApproving, 'The approval of the batch has begun.');
                }

                $figures   = $this->batches->figures($batchId);
                $previews  = $this->posting->discardPreviews($batchId);
                $documents = $this->batches->removeItems($batchId);

                $this->batches->update($batchId, [
                    'status'        => BackfillStatus::Cancelled->value,
                    'decided_by'    => $employeeId,
                    'decided_at'    => new RawSql('CURRENT_TIMESTAMP'),
                    'cursor_family' => null,
                    'cursor_id'     => 0,
                ]);

                $this->audit->write('backfill_batch', $batchId, 'backfill_cancelled', $employeeId, $this->originOf($employeeId), [
                    'previews'  => $previews,
                    'documents' => $documents,
                    'ready'     => $figures['ready'],
                    'refused'   => $figures['refused'],
                ], $batch->branchId);

                return $this->batches->require($batchId);
            });
        });
    }

    /**
     * What a batch would do, without writing anything, for a range given as a batch is: the same
     * documents, the same refusals and the same fingerprint a preview of it would get.
     *
     * @param array{from?: string, to?: string, branch_id?: int|string|null, families?: list<string>|null, chunk_size?: int|string|null} $input
     *
     * @return array{from: string, to: string, branch_id: ?int, families: list<string>, fingerprint: string, summary: array{documents: int, ready: int, refused: int, journals: int, debit: string, reasons: array<string, int>}, documents: list<array<string, mixed>>}
     *
     * @throws AccountingException|BranchException
     */
    public function dryRun(array $input, ?int $employeeId = null, int $listed = 100): array
    {
        $this->guard->require(AccountingGuard::BACKFILL_MANAGE, $employeeId);

        $branchId = $this->branchOf($input['branch_id'] ?? null);

        $this->requireScope($branchId, $employeeId);

        [$from, $to] = $this->range((string) ($input['from'] ?? ''), (string) ($input['to'] ?? ''));

        $families = $this->families($input['families'] ?? null);
        $chunk    = $this->chunkSize($input['chunk_size'] ?? null);
        $scope    = new BackfillScope($from, $to, $branchId, $families);
        $hash     = hash_init('sha256');
        $summary  = ['documents' => 0, 'ready' => 0, 'refused' => 0, 'journals' => 0, 'debit' => '0.0000', 'reasons' => []];
        $listing  = [];

        $this->adapters = [];

        foreach ($families as $family) {
            $adapter = $this->adapter($family);
            $afterId = 0;

            do {
                $documents = $adapter->candidates($scope, $afterId, $chunk, 0);

                foreach ($documents as $document) {
                    $plan = $adapter->plan($document, $employeeId, 0);

                    hash_update($hash, $this->fingerprintLine($family, $document->id, $plan->isReady() ? 'ready' : 'refused', $plan->refusal?->value, $plan->digest()));

                    $summary['documents']++;
                    $summary[$plan->isReady() ? 'ready' : 'refused']++;
                    $summary['journals'] += $plan->journalCount();
                    $summary['debit'] = bcadd($summary['debit'], $plan->total(), 4);

                    if ($plan->refusal !== null) {
                        $summary['reasons'][$plan->refusal->value] = ($summary['reasons'][$plan->refusal->value] ?? 0) + 1;
                    }

                    if (count($listing) < $listed) {
                        $listing[] = $this->described($document, $plan);
                    }

                    $afterId = $document->id;
                }
            } while ($documents !== []);
        }

        return [
            'from'        => $from,
            'to'          => $to,
            'branch_id'   => $branchId,
            'families'    => array_map(static fn (BackfillFamily $family): string => $family->value, $families),
            'fingerprint' => hash_final($hash),
            'summary'     => $summary,
            'documents'   => $listing,
        ];
    }

    /**
     * @return list<BackfillBatch> The batches the employee may see, the latest first.
     *
     * @throws AccountingException
     */
    public function batches(?int $employeeId = null): array
    {
        $this->guard->require(AccountingGuard::BACKFILL_MANAGE, $employeeId);

        return $this->batches->all($this->branches->allowedBranchIds($employeeId));
    }

    /**
     * A batch of a branch the employee works in, or of the company when they work in every branch.
     *
     * @throws AccountingException
     */
    public function batch(int $batchId, ?int $employeeId = null): BackfillBatch
    {
        $this->guard->require(AccountingGuard::BACKFILL_MANAGE, $employeeId);

        return $this->visible($batchId, $employeeId);
    }

    public function repository(): BackfillBatches
    {
        return $this->batches;
    }

    /**
     * What stands in the way of approving the batches, one reading for the screen and the readiness report.
     *
     * @return list<string> The codes of what is missing, none when a batch may be approved.
     */
    public function approvalBlockers(?int $branchId = null): array
    {
        $blockers = [];

        if (! $this->settings->isEnabled()) {
            $blockers[] = 'accounting_off';
        }

        if ($this->settings->startDate() === null) {
            $blockers[] = 'start_date_missing';
        }

        if (! $this->settings->backfillEnabled()) {
            $blockers[] = 'backfill_off';
        }

        foreach ($this->openingBranches($branchId) as $branch) {
            if ((new BackfillGate($this->db))->openingOf((int) $branch->branch_id) === null) {
                $blockers[] = 'opening_missing:' . $branch->code;
            }
        }

        return $blockers;
    }

    /**
     * @throws AccountingException|BranchException|PostingException
     */
    private function collect(BackfillBatch $batch, ?int $employeeId, ?int $maxChunks): BackfillBatch
    {
        $this->adapters = [];

        $scope   = $batch->scope();
        $family  = $batch->cursorFamily ?? $batch->families[0];
        $afterId = $batch->cursorFamily === null ? 0 : $batch->cursorId;
        $chunks  = 0;

        while (true) {
            $adapter   = $this->adapter($family);
            $documents = $adapter->candidates($scope, $afterId, $batch->chunkSize, $batch->id);

            if ($documents === []) {
                $next = $this->after($batch, $family);

                if ($next === null) {
                    break;
                }

                $family  = $next;
                $afterId = 0;

                $this->batches->update($batch->id, ['cursor_family' => $family->value, 'cursor_id' => 0]);

                continue;
            }

            $last = $documents[array_key_last($documents)]->id;

            $this->transactional(function () use ($batch, $documents, $adapter, $family, $last, $employeeId): void {
                foreach ($documents as $document) {
                    $this->look($batch, $document, $adapter, $employeeId);
                }

                $this->batches->update($batch->id, ['cursor_family' => $family->value, 'cursor_id' => $last]);
            });

            $afterId = $last;

            if ($maxChunks !== null && ++$chunks >= $maxChunks) {
                return $this->batches->require($batch->id);
            }
        }

        return $this->finish($batch, $employeeId);
    }

    /**
     * @throws PostingException
     */
    private function look(BackfillBatch $batch, BackfillDocument $document, BackfillAdapter $adapter, ?int $employeeId): void
    {
        $plan       = $adapter->plan($document, $employeeId, $batch->id);
        $journalIds = [];
        $stock      = [];

        foreach ($plan->isReady() ? $plan->steps : [] as $step) {
            $journalId = $step->draft === null ? null : $this->posting->postPreview($step->draft, $batch->id);

            if ($journalId !== null) {
                $journalIds[] = $journalId;
            }

            if ($step->stock !== null) {
                $stock[] = [
                    'journal'   => $journalId,
                    'source'    => $step->stock->source,
                    'source_id' => $step->stock->sourceId,
                    'entry'     => $step->stock->entryType->value,
                    'revision'  => $step->stock->revision,
                    'date'      => $step->stock->date,
                    'branch'    => $step->stock->branchId,
                    'movements' => array_map(static fn (StockMovement $movement): array => [$movement->itemId, $movement->locationId, $movement->quantity, $movement->value], $step->stock->movements),
                ];
            }
        }

        $this->batches->addItem($batch->id, [
            'family'        => $document->family->value,
            'document_id'   => $document->id,
            'document_date' => $document->date,
            'branch_id'     => $document->branchId,
            'outcome'       => $plan->isReady() ? 'ready' : 'refused',
            'reason'        => $plan->refusal?->value,
            'detail'        => $plan->detail === '' ? null : mb_substr($plan->detail, 0, 255),
            'journal_count' => count($journalIds),
            'total_debit'   => $plan->total(),
            'digest'        => $plan->digest(),
            'payload'       => $plan->isReady() ? json_encode(['journals' => $journalIds, 'stock' => $stock, 'stamp' => $plan->stamp, 'party' => [$plan->partyType, $plan->partyId]], JSON_UNESCAPED_UNICODE) : null,
        ]);
    }

    private function finish(BackfillBatch $batch, ?int $employeeId): BackfillBatch
    {
        return $this->transactional(function () use ($batch, $employeeId): BackfillBatch {
            $figures     = $this->batches->figures($batch->id);
            $fingerprint = $this->fingerprintOf($batch);

            $this->batches->update($batch->id, [
                'status'         => BackfillStatus::Preview->value,
                'fingerprint'    => $fingerprint,
                'document_count' => $figures['documents'],
                'ready_count'    => $figures['ready'],
                'refused_count'  => $figures['refused'],
                'journal_count'  => $figures['journals'],
                'total_debit'    => $figures['debit'],
                'cursor_family'  => null,
                'cursor_id'      => 0,
            ]);

            $this->audit->write('backfill_batch', $batch->id, 'backfill_previewed', $employeeId, $this->originOf($employeeId), [
                'documents'   => $figures['documents'],
                'ready'       => $figures['ready'],
                'refused'     => $figures['refused'],
                'journals'    => $figures['journals'],
                'debit'       => $figures['debit'],
                'fingerprint' => $fingerprint,
            ], $batch->branchId);

            return $this->batches->require($batch->id);
        });
    }

    /**
     * Makes again, from the documents as they are now, what the preview made, and refuses unless it
     * comes to the same fingerprint: the documents of the batch are the same, none was added that qualifies,
     * and each would put into the books what it was shown to.
     *
     * @throws AccountingException
     */
    private function verify(BackfillBatch $batch, ?int $employeeId): void
    {
        $scope = $batch->scope();
        $hash  = hash_init('sha256');

        foreach ($batch->families as $family) {
            $adapter = $this->adapter($family);
            $afterId = 0;

            do {
                $documents = $adapter->candidates($scope, $afterId, $batch->chunkSize, $batch->id);

                foreach ($documents as $document) {
                    $plan = $adapter->plan($document, $employeeId, $batch->id);

                    hash_update($hash, $this->fingerprintLine($family, $document->id, $plan->isReady() ? 'ready' : 'refused', $plan->refusal?->value, $plan->digest()));

                    $afterId = $document->id;
                }
            } while ($documents !== []);
        }

        $previews = (int) $this->db->table('accounting_journals')->where('backfill_batch_id', $batch->id)->where('status', 'preview')->countAllResults();

        if ($batch->fingerprint === null || ! hash_equals($batch->fingerprint, hash_final($hash)) || $previews !== $batch->journalCount) {
            throw new AccountingException(AccountingError::BackfillPreviewChanged, 'The documents or the books changed since the preview was made.');
        }
    }

    /**
     * @throws AccountingException|PostingException
     */
    private function post(BackfillBatch $batch, ?int $employeeId, ?int $maxChunks): BackfillBatch
    {
        $chunks = 0;

        while (true) {
            $items = $this->batches->itemsAfter($batch->id, $batch->cursorId, $batch->chunkSize);

            if ($items === []) {
                break;
            }

            $this->underPartyLocks($items, fn () => $this->transactional(function () use ($batch, $items, $employeeId): void {
                $locked = $this->batches->lock($batch->id);

                if ($locked === null || $locked->status !== BackfillStatus::Approving || $locked->cursorId !== $batch->cursorId) {
                    throw new AccountingException(AccountingError::BackfillBusy, 'The batch is being approved by another run.');
                }

                foreach ($items as $item) {
                    $this->approveItem($batch, $item, $employeeId);
                }

                $this->batches->update($batch->id, ['cursor_id' => (int) $items[array_key_last($items)]->item_id, 'approved_count' => $locked->approvedCount + count($items)]);
            }));

            $batch = $this->batches->require($batch->id);

            if ($maxChunks !== null && ++$chunks >= $maxChunks) {
                return $batch;
            }
        }

        return $this->seal($batch, $employeeId);
    }

    private function seal(BackfillBatch $batch, ?int $employeeId): BackfillBatch
    {
        return $this->transactional(function () use ($batch, $employeeId): BackfillBatch {
            $this->batches->update($batch->id, ['status' => BackfillStatus::Approved->value, 'decided_by' => $employeeId, 'decided_at' => new RawSql('CURRENT_TIMESTAMP')]);

            $this->audit->write('backfill_batch', $batch->id, 'backfill_approved', $employeeId, $this->originOf($employeeId), [
                'documents'   => $batch->approvedCount,
                'journals'    => $batch->journalCount,
                'debit'       => $batch->totalDebit,
                'fingerprint' => $batch->fingerprint,
            ], $batch->branchId);

            return $this->batches->require($batch->id);
        });
    }

    /**
     * @throws AccountingException|PostingException
     */
    private function approveItem(BackfillBatch $batch, object $item, ?int $employeeId): void
    {
        $document = new BackfillDocument(BackfillFamily::from((string) $item->family), (int) $item->document_id, (string) $item->document_date, $item->branch_id === null ? null : (int) $item->branch_id);
        $adapter  = $this->adapter($document->family);

        $adapter->lock($document->id);

        $plan = $adapter->plan($document, $employeeId, $batch->id);

        if (! $plan->isReady() || ! hash_equals((string) $item->digest, $plan->digest())) {
            throw new AccountingException(AccountingError::BackfillPreviewChanged, "The document {$document->id} is not what the preview showed.", ['count' => $document->id]);
        }

        $payload = json_decode((string) $item->payload, true);

        $this->stamp($plan->stamp, $batch, $employeeId);

        foreach ($payload['journals'] as $journalId) {
            $this->posting->approvePreview((int) $journalId, $batch->id, $employeeId);
        }

        $ledger = new StockLedger($this->db);

        foreach ($payload['stock'] as $stock) {
            $ledger->record(
                $stock['source'],
                (int) $stock['source_id'],
                JournalEntryType::from($stock['entry']),
                (int) $stock['revision'],
                $stock['date'],
                (int) $stock['branch'],
                array_map(static fn (array $movement): StockMovement => new StockMovement((int) $movement[0], (int) $movement[1], (string) $movement[2], (string) $movement[3]), $stock['movements']),
                $stock['journal'] === null ? null : (int) $stock['journal'],
            );
        }

        if ($plan->partyType === 'customer' && $plan->partyId !== null) {
            (new CreditSettlement($this->db))->settle($plan->partyId, $employeeId);
        } elseif ($plan->partyType === 'supplier' && $plan->partyId !== null) {
            (new SupplierSettlement($this->db))->settle($plan->partyId, $employeeId);
        }
    }

    /**
     * Writes on a document what the live posting writes when it is saved, and nothing else: the
     * branch, and for a cash-up the cash that was expected and the difference. It is written only
     * while the document still has none, so what a person has written since is never overwritten.
     *
     * @param array<string, mixed> $stamp
     *
     * @throws AccountingException
     */
    private function stamp(array $stamp, BackfillBatch $batch, ?int $employeeId): void
    {
        if ($stamp === []) {
            return;
        }

        $this->db->table($stamp['table'])->where($stamp['key'], $stamp['id'])->where($stamp['null'] . ' IS NULL', null, false)->update($stamp['columns']);

        if ($this->db->affectedRows() !== 1) {
            throw new AccountingException(AccountingError::BackfillPreviewChanged, 'The document was changed since the preview was made.', ['count' => $stamp['id']]);
        }

        $this->audit->write($stamp['table'] === 'expenses' ? 'expense' : 'cashup', (int) $stamp['id'], 'backfill_stamped', $employeeId, $this->originOf($employeeId), ['batch_id' => $batch->id] + $stamp['columns'], (int) $stamp['columns']['branch_id']);
    }

    /**
     * @throws AccountingException
     */
    private function requireOpen(BackfillBatch $batch): void
    {
        if (in_array($batch->status, [BackfillStatus::Approved, BackfillStatus::Cancelled], true)) {
            throw new AccountingException(AccountingError::BackfillDecided, "Batch {$batch->id} was " . $batch->status->value . '.', ['name' => $batch->name]);
        }
    }

    /**
     * @throws AccountingException
     */
    private function requireBooks(): void
    {
        if (! $this->settings->isEnabled()) {
            throw new AccountingException(AccountingError::BackfillAccountingOff, 'Accounting is not turned on.');
        }

        if ($this->settings->startDate() === null) {
            throw new AccountingException(AccountingError::BackfillStartDateMissing, 'The start date of the books is not set.');
        }
    }

    /**
     * The opening balances are posted for every branch the batch writes in: what happened before the books
     * began is in them, and the documents of the batch are those after.
     *
     * @throws AccountingException
     */
    private function requireOpenings(BackfillBatch $batch): void
    {
        $gate     = new BackfillGate($this->db);
        $branches = $this->batches->readyBranches($batch->id);

        foreach ($branches as $branchId) {
            if ($gate->openingOf($branchId) === null) {
                $code = $this->db->table('branches')->select('code')->where('branch_id', $branchId)->get()->getRow();

                throw new AccountingException(AccountingError::BackfillOpeningMissing, "Branch {$branchId} has no posted opening balances.", ['name' => $code === null ? (string) $branchId : (string) $code->code]);
            }
        }
    }

    /**
     * @return list<object>
     */
    private function openingBranches(?int $branchId): array
    {
        $builder = $this->db->table('branches')->select('branch_id, code')->where('company_id', CompanyContext::id())->where('active', 1);

        if ($branchId !== null) {
            $builder->where('branch_id', $branchId);
        }

        return $builder->orderBy('branch_id', 'ASC')->get()->getResult();
    }

    /**
     * @throws AccountingException
     */
    private function visible(int $batchId, ?int $employeeId): BackfillBatch
    {
        $batch = $this->batches->find($batchId);

        $allowed = $batch === null ? false : ($batch->branchId === null ? $this->branches->isUnrestricted($employeeId) : $this->branches->canAccess($batch->branchId, $employeeId));

        if ($batch === null || ! $allowed) {
            throw new AccountingException(AccountingError::BackfillNotFound, "Batch {$batchId} does not exist.");
        }

        return $batch;
    }

    /**
     * @throws BranchException
     */
    private function requireScope(?int $branchId, ?int $employeeId): void
    {
        if ($branchId === null) {
            $this->branches->requireCompanyScope($employeeId);

            return;
        }

        if ($this->db->table('branches')->where('company_id', CompanyContext::id())->where('branch_id', $branchId)->countAllResults() === 0) {
            throw new BranchException(BranchError::NotFound, "Branch {$branchId} does not exist.", ['branch_id' => $branchId]);
        }

        $this->branches->requireBranch($branchId, $employeeId);
    }

    private function branchOf(mixed $value): ?int
    {
        $branchId = is_numeric($value) ? (int) $value : 0;

        return $branchId > 0 ? $branchId : null;
    }

    /**
     * @return array{0: string, 1: string}
     *
     * @throws AccountingException
     */
    private function range(string $from, string $to): array
    {
        foreach ([$from, $to] as $date) {
            $parsed = DateTimeImmutable::createFromFormat('!Y-m-d', $date);

            if ($parsed === false || $parsed->format('Y-m-d') !== $date) {
                throw new AccountingException(AccountingError::BackfillRangeInvalid, 'The range of a batch is two valid Y-m-d dates.');
            }
        }

        if ($from > $to) {
            throw new AccountingException(AccountingError::BackfillRangeInvalid, 'The range of a batch ends after it begins.');
        }

        return [$from, $to];
    }

    /**
     * @param list<string>|null $values
     *
     * @return list<BackfillFamily>
     *
     * @throws AccountingException
     */
    private function families(?array $values): array
    {
        if ($values === null || $values === []) {
            return BackfillFamily::ordered();
        }

        $chosen = [];

        foreach ($values as $value) {
            $family = is_string($value) ? BackfillFamily::tryFrom($value) : null;

            if ($family === null) {
                throw new AccountingException(AccountingError::BackfillFamiliesInvalid, 'A batch looks at the families of documents the retroactive posting knows.');
            }

            $chosen[$family->value] = $family;
        }

        return array_values(array_filter(BackfillFamily::ordered(), static fn (BackfillFamily $family): bool => isset($chosen[$family->value])));
    }

    /**
     * @throws AccountingException
     */
    private function chunkSize(mixed $value): int
    {
        if ($value === null || $value === '') {
            return self::CHUNK_DEFAULT;
        }

        $size = is_numeric($value) ? (int) $value : 0;

        if ($size < 1 || $size > self::CHUNK_MAX) {
            throw new AccountingException(AccountingError::BackfillChunkInvalid, 'A chunk holds 1 to ' . self::CHUNK_MAX . ' documents.', ['max' => self::CHUNK_MAX]);
        }

        return $size;
    }

    private function after(BackfillBatch $batch, BackfillFamily $family): ?BackfillFamily
    {
        $found = false;

        foreach ($batch->families as $each) {
            if ($found) {
                return $each;
            }

            $found = $each === $family;
        }

        return null;
    }

    private function adapter(BackfillFamily $family): BackfillAdapter
    {
        return $this->adapters[$family->value] ??= match ($family) {
            BackfillFamily::Sales     => new SaleBackfill($this->db),
            BackfillFamily::Purchases => new PurchaseBackfill($this->db),
            BackfillFamily::Expenses  => new ExpenseBackfill($this->db),
            BackfillFamily::Cashups   => new CashupBackfill($this->db),
        };
    }

    private function fingerprintOf(BackfillBatch $batch): string
    {
        $hash = hash_init('sha256');

        foreach ($batch->families as $family) {
            foreach ($this->batches->itemsOf($batch->id, $family) as $item) {
                hash_update($hash, $this->fingerprintLine($family, (int) $item->document_id, (string) $item->outcome, $item->reason === null ? null : (string) $item->reason, (string) $item->digest));
            }
        }

        return hash_final($hash);
    }

    private function fingerprintLine(BackfillFamily $family, int $documentId, string $outcome, ?string $reason, string $digest): string
    {
        return implode('|', [$family->value, $documentId, $outcome, $reason ?? '', $digest]) . "\n";
    }

    /**
     * @return array<string, mixed>
     */
    private function described(BackfillDocument $document, BackfillPlan $plan): array
    {
        $journals = [];

        foreach ($plan->steps as $step) {
            if ($step->draft === null) {
                continue;
            }

            $journals[] = [
                'source' => $step->draft->sourceType,
                'id'     => $step->draft->sourceId,
                'entry'  => $step->draft->entryType->value,
                'date'   => $step->draft->date,
                'branch' => $step->draft->branchId,
                'lines'  => array_map(static fn ($line): array => ['account_id' => $line->accountId, 'debit' => bcadd($line->debit, '0', 4), 'credit' => bcadd($line->credit, '0', 4)], $step->draft->lines),
            ];
        }

        return [
            'family'   => $document->family->value,
            'id'       => $document->id,
            'date'     => $document->date,
            'branch'   => $document->branchId,
            'outcome'  => $plan->isReady() ? 'ready' : 'refused',
            'reason'   => $plan->refusal?->value,
            'detail'   => $plan->detail,
            'journals' => $journals,
        ];
    }

    /**
     * @template T
     *
     * @param callable(): T $work
     *
     * @return T
     *
     * @throws AccountingException
     */
    private function exclusive(string $name, int $wait, callable $work): mixed
    {
        $name  = 'ospos_backfill_' . substr(sha1($this->db->getDatabase()), 0, 10) . '_' . $name;
        $lock  = new NamedLock($this->db, $wait);
        $taken = $lock->take($name);

        if ($taken !== LockOutcome::Taken) {
            throw new AccountingException(AccountingError::BackfillBusy, 'Another run of the retroactive posting holds this batch.', ['lock' => $taken->value]);
        }

        try {
            return $work();
        } finally {
            $lock->release($name);
        }
    }

    private function batchLock(int $batchId): string
    {
        return 'batch_' . $batchId;
    }

    /**
     * @param list<object>     $items
     * @param callable(): void $work
     */
    private function underPartyLocks(array $items, callable $work): void
    {
        $customers = [];
        $suppliers = [];

        foreach ($items as $item) {
            $party = json_decode((string) $item->payload, true)['party'] ?? [null, null];

            if ($party[1] === null) {
                continue;
            }

            if ($party[0] === 'customer') {
                $customers[(int) $party[1]] = (int) $party[1];
            } elseif ($party[0] === 'supplier') {
                $suppliers[(int) $party[1]] = (int) $party[1];
            }
        }

        ksort($customers);
        ksort($suppliers);

        $run = $work;

        foreach (array_reverse(array_values($suppliers)) as $supplierId) {
            $inner = $run;
            $run   = fn () => (new SupplierLock($this->db))->run($supplierId, $inner);
        }

        foreach (array_reverse(array_values($customers)) as $customerId) {
            $inner = $run;
            $run   = fn () => (new CustomerLock($this->db))->run($customerId, $inner);
        }

        $run();
    }

    private function originOf(?int $employeeId): JournalOrigin
    {
        return $employeeId === null ? JournalOrigin::System : JournalOrigin::User;
    }
}
