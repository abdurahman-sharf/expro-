<?php

namespace Tests\Support\Ledger;

use App\Libraries\Accounting\AccountingException;
use App\Libraries\Accounting\PostingException;
use App\Libraries\Documents\DocumentException;
use App\Libraries\Organization\BranchException;
use App\Models\Receiving;
use App\Models\Sale;
use CodeIgniter\Database\BaseConnection;
use Config\Database;
use Throwable;
use Tests\Support\DatabaseFingerprintTrait;

/**
 * A seeded, replayable run of mixed operations on the real services. Each operation draws its choices from a
 * generator seeded by the seed and its position, runs through the entry point a screen would use, and is
 * logged. A refusal must leave the books byte-identical; anything else that goes wrong is a violation, and the
 * log up to it is what a replay needs.
 */
final class LedgerScenario
{
    use DatabaseFingerprintTrait;
    use LedgerSaleOperations;
    use LedgerPurchaseOperations;

    /** @var list<string> Tables a refusal may write to: the notes the books keep of what they refused. */
    private const REFUSAL_MAY_WRITE = ['accounting_posting_failures', 'accounting_audit_log'];

    /** @var list<string> */
    public array $log = [];

    /** @var array<string, int> */
    public array $outcomes = [];

    /** @var array<string, int> */
    public array $refusals = [];

    /** @var array<string, array{count: int, example: string}> What the runs met that is a finding of its own and not a failure of the run. */
    public array $observed = [];

    public LedgerWorld $world;

    private BaseConnection $db;

    private Sale $saleModel;

    private Receiving $purchaseModel;

    private int $position = 0;

    private string $pending = '';

    /**
     * @param list<int> $skip Positions left out of the run, to shrink a failing replay.
     */
    public function __construct(public readonly int $seed, private readonly array $skip = [], private readonly bool $checkRefusals = true)
    {
        $this->db = Database::connect();
    }

    public function prepare(): void
    {
        $store = new LedgerDatabase();
        $store->remember();
        $store->restore();

        $this->log      = [];
        $this->outcomes = [];
        $this->refusals = [];
        $this->observed = [];
        $this->position = 0;
        $this->resetSales();
        $this->resetPurchases();

        $this->world = new LedgerWorld($this->seed);
        $this->world->build();

        $this->saleModel     = model(Sale::class);
        $this->purchaseModel = model(Receiving::class);
    }

    public function position(): int
    {
        return $this->position;
    }

    public function run(int $operations): void
    {
        $end = $this->position + $operations;

        while ($this->position < $end) {
            $this->step($this->position);
            $this->position++;
        }
    }

    public function step(int $position): void
    {
        $random = new LedgerRandom($this->seed, $position);
        $name   = $this->choose($random, $position);

        if (in_array($position, $this->skip, true)) {
            $this->log[] = sprintf('#%d %s skipped', $position, $name);

            return;
        }

        $this->pending = '';

        $before    = $this->checkRefusals ? $this->databaseFingerprint() : [];
        $lastId    = $this->lastJournalId();
        $outcome   = 'ok';
        $detail    = '';
        $violation = null;

        try {
            $detail = $this->{'operation' . str_replace('_', '', ucwords($name, '_'))}($random);
        } catch (LedgerRefusal $refusal) {
            $outcome = 'refused';
            $detail  = $this->pending . ' => ' . $refusal->getMessage();

            if ($refusal->isInternal() && ! $this->isKnownRefusal($name, $refusal, $detail)) {
                $violation = 'the books refused a normal operation with an internal code (' . $refusal->getMessage() . ')';
            }

            $this->refusals[$refusal->reason] = ($this->refusals[$refusal->reason] ?? 0) + 1;
        } catch (LedgerViolation $found) {
            $outcome   = 'violation';
            $detail    = $this->pending . ' => ' . $found->getMessage();
            $violation = $found->getMessage();
        } catch (Throwable $crash) {
            $domain = $this->refusalOf($crash);

            if ($domain !== null) {
                $outcome = 'refused';
                $detail  = $this->pending . ' => ' . $domain->getMessage();

                if ($domain->isInternal() && ! $this->isKnownRefusal($name, $domain, $detail)) {
                    $violation = 'the books refused a normal operation with an internal code (' . $domain->getMessage() . ')';
                }

                $this->refusals[$domain->reason] = ($this->refusals[$domain->reason] ?? 0) + 1;
            } else {
                $outcome   = 'crash';
                $detail    = $this->pending . ' => ' . get_class($crash) . ': ' . $crash->getMessage() . ' at ' . basename($crash->getFile()) . ':' . $crash->getLine();
                $violation = 'the operation crashed: ' . $detail;
            }
        }

        (new LedgerDatabase())->releaseTransactions();

        $this->outcomes[$name . ':' . $outcome] = ($this->outcomes[$name . ':' . $outcome] ?? 0) + 1;
        $this->log[] = sprintf('#%d %s %s %s', $position, $name, $outcome, $detail);

        if ($violation === null && $outcome === 'refused' && $this->checkRefusals) {
            $changed = $this->tablesChangedSince($before);

            if ($changed !== []) {
                $violation = 'a refused operation left rows in ' . implode(', ', $changed);
            }
        }

        if ($violation === null && $outcome === 'ok') {
            $violation = $this->newJournalsProblem($lastId);
        }

        if ($violation !== null) {
            throw new LedgerViolation($violation);
        }
    }

    public function db(): BaseConnection
    {
        return $this->db;
    }

    /**
     * A purchase return refused as unbalanced while a location holds units valued at less than nothing is the finding
     * about the cost of goods sold, not a new failure.
     */
    private function isKnownRefusal(string $name, LedgerRefusal $refusal, string $detail): bool
    {
        if ($name !== 'purchase_return' || $refusal->reason !== 'unbalanced') {
            return false;
        }

        $below = (new LedgerInvariants())->locationsValuedBelowNothing();

        if ($below === []) {
            return false;
        }

        $this->observe('F1-purchase-return-refused-after-location-value-went-negative', $detail . ' | ' . $below[0]);

        return true;
    }

    private function choose(LedgerRandom $random, int $position): string
    {
        if ($position < 6) {
            return 'purchase';
        }

        return $random->weighted([
            'sale'               => 30,
            'sale_payment'       => 8,
            'sale_return'        => 8,
            'sale_cancel'        => 5,
            'purchase'           => 12,
            'purchase_payment'   => 6,
            'purchase_return'    => 4,
            'purchase_cancel'    => 3,
        ]);
    }

    private function refusalOf(Throwable $failure): ?LedgerRefusal
    {
        if ($failure instanceof LedgerRefusal) {
            return $failure;
        }

        if ($failure instanceof AccountingException || $failure instanceof PostingException) {
            return new LedgerRefusal($failure->error->value, $failure->getMessage() . ($failure->context === [] ? '' : ' ' . json_encode($failure->context)));
        }

        if ($failure instanceof BranchException) {
            return new LedgerRefusal($failure->error->value, $failure->getMessage());
        }

        if ($failure instanceof DocumentException) {
            return new LedgerRefusal('document', $failure->getMessage());
        }

        return null;
    }

    /**
     * @param array<string, array{rows: int, checksum: string|null}> $before
     *
     * @return list<string>
     */
    private function tablesChangedSince(array $before): array
    {
        $changed = [];

        foreach ($this->databaseFingerprint() as $table => $print) {
            if (($before[$table] ?? null) !== $print && ! in_array($table, array_map(fn (string $name): string => $this->db->DBPrefix . $name, self::REFUSAL_MAY_WRITE), true)) {
                $changed[] = $table;
            }
        }

        return $changed;
    }

    private function lastJournalId(): int
    {
        return (int) $this->db->query('SELECT COALESCE(MAX(journal_id), 0) AS last FROM ' . $this->db->prefixTable('accounting_journals'))->getRow()->last;
    }

    /**
     * The journals an operation wrote must not be dated inside a period that was closed, whichever branch they belong to.
     */
    private function newJournalsProblem(int $afterId): ?string
    {
        $journals = $this->db->prefixTable('accounting_journals');
        $closed   = $this->db->prefixTable('accounting_closed_periods');

        $row = $this->db->query(
            "SELECT j.journal_id, j.journal_date, j.branch_id, j.source_type FROM {$journals} j WHERE j.journal_id > ? AND j.entry_type <> 'year_end' AND EXISTS (SELECT 1 FROM {$closed} c WHERE c.active = 1 AND (c.branch_id IS NULL OR c.branch_id = j.branch_id) AND j.journal_date BETWEEN c.date_from AND c.date_to) LIMIT 1",
            [$afterId],
        )->getRow();

        return $row === null ? null : sprintf('journal %d (%s, branch %d) was written on %s, inside a closed period', $row->journal_id, $row->source_type, $row->branch_id, $row->journal_date);
    }

    protected function observe(string $code, string $example): void
    {
        $this->observed[$code] = ['count' => ($this->observed[$code]['count'] ?? 0) + 1, 'example' => $this->observed[$code]['example'] ?? $example];
    }

    protected function note(string $text): void
    {
        $this->pending = $text;
    }

    protected function refuse(string $code, string $message = ''): never
    {
        throw new LedgerRefusal($code, $message);
    }

    protected function expect(bool $condition, string $message): void
    {
        if (! $condition) {
            throw new LedgerViolation($message);
        }
    }
}
