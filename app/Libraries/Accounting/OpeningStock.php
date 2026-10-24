<?php

namespace App\Libraries\Accounting;

use App\Libraries\Organization\BranchError;
use App\Libraries\Organization\BranchException;
use App\Libraries\Organization\BranchGuard;
use App\Libraries\Organization\CompanyContext;
use CodeIgniter\Database\BaseConnection;
use Config\Database;
use DateTimeImmutable;

/**
 * Brings the stock that stood on the shelves before the books began into them. Units that no
 * posted movement accounts for are posted, one stock location at a time, at the cost the item
 * carries: a debit to the account of the location and a credit to the opening balance equity
 * account, dated on the accounting start date, and a valued movement for each item that points
 * at the journal. The quantities on the shelves are not touched, because the units are already
 * there and only the books lack them.
 *
 * A location is posted once. What stops it, or leaves an item out, is reported and left alone:
 * an account that already holds value no movement accounts for, an opening that was posted and
 * has since been joined by more unposted units, and an item whose shelves are negative or hold
 * less than its movements say. A posted opening is never changed or deleted: it is reversed on
 * purpose, with a reason, and can then be posted again.
 */
final class OpeningStock
{
    use RunsInTransaction;

    public const SOURCE           = 'opening_stock';
    private const VALUE_TOLERANCE = '0.00005';

    private BaseConnection $db;
    private AccountingGuard $guard;
    private BranchGuard $branches;
    private AccountingSettings $settings;
    private StockLedger $ledger;
    private PostingService $posting;
    private RoutingResolver $resolver;
    private EntityAccountService $entities;
    private AuditLog $audit;

    public function __construct(?BaseConnection $db = null, ?AccountingGuard $guard = null, ?BranchGuard $branches = null)
    {
        $this->db       = $db ?? Database::connect();
        $this->guard    = $guard ?? new AccountingGuard();
        $this->branches = $branches ?? new BranchGuard($this->db);
        $this->settings = new AccountingSettings($this->db);
        $this->ledger   = new StockLedger($this->db);
        $this->posting  = new PostingService($this->db);
        $this->resolver = new RoutingResolver($this->db);
        $this->entities = new EntityAccountService($this->db);
        $this->audit    = new AuditLog($this->db);
    }

    /**
     * What posting would do for every stock location of a branch that is in use.
     *
     * @return list<OpeningStockPlan>
     *
     * @throws BranchException
     */
    public function plans(int $branchId, ?int $employeeId): array
    {
        $this->requireBranch($branchId, $employeeId);

        $locations = $this->db->table('stock_locations')
            ->select('location_id, location_name, branch_id')
            ->where('branch_id', $branchId)
            ->where('deleted', 0)
            ->orderBy('location_name', 'ASC')
            ->orderBy('location_id', 'ASC')
            ->get()
            ->getResult();

        return array_map(fn (object $location): OpeningStockPlan => $this->buildPlan($location), $locations);
    }

    /**
     * @throws AccountingException|BranchException
     */
    public function plan(int $locationId, ?int $employeeId): OpeningStockPlan
    {
        return $this->buildPlan($this->requireLocations([$locationId], $employeeId)[0]);
    }

    /**
     * Posts the opening stock of the locations, all of them or none: a location that cannot be
     * posted, because the books refuse it or a row cannot be written, undoes the others too. A
     * location that is merely not postable (already posted, nothing to post, or blocked by what
     * a person has to settle) is reported and the rest go ahead. Running it again posts nothing
     * twice.
     *
     * @param list<int> $locationIds
     *
     * @return list<OpeningStockResult>
     *
     * @throws AccountingException|BranchException|PostingException
     */
    public function post(array $locationIds, ?int $employeeId): array
    {
        $this->guard->require(AccountingGuard::OPENING_MANAGE, $employeeId);

        $date = $this->startDate();
        $ids  = $this->normalized($locationIds);

        if ($ids === []) {
            return [];
        }

        $this->requireLocations($ids, $employeeId);

        try {
            return $this->transactional(function () use ($ids, $date, $employeeId): array {
                $results = [];

                foreach ($this->lockedLocations($ids) as $location) {
                    $plan      = $this->buildPlan($location);
                    $results[] = $plan->status === OpeningStockStatus::Postable ? $this->write($plan, $date, $employeeId) : new OpeningStockResult($plan);
                }

                return $results;
            });
        } catch (AccountingException|PostingException $failure) {
            if ($failure instanceof PostingException || $failure->error === AccountingError::WriteFailed) {
                $this->recordFailure($ids, $failure, $employeeId);
            }

            throw $failure;
        }
    }

    /**
     * Takes the opening stock of a location back, on purpose and with a reason: its journal is
     * reversed and its movements are taken back by movements of the opposite sign, on the date
     * given. The units on the shelves do not change, and the location can be posted again.
     *
     * @throws AccountingException|BranchException|PostingException
     */
    public function reverse(int $locationId, string $date, string $reason, ?int $employeeId): void
    {
        $this->guard->require(AccountingGuard::OPENING_MANAGE, $employeeId);
        $this->startDate();

        $location = $this->requireLocations([$locationId], $employeeId)[0];
        $date     = $this->validDate($date);
        $reason   = trim($reason);

        if ($reason === '' || mb_strlen($reason) > 255) {
            throw new AccountingException(AccountingError::ReasonRequired, 'A reason of 1 to 255 characters is required.');
        }

        $this->transactional(function () use ($locationId, $date, $reason, $employeeId, $location): void {
            $locked   = $this->lockedLocations([$locationId])[0];
            $revision = $this->activeRevision($locationId);

            if ($revision === null) {
                throw new AccountingException(AccountingError::OpeningStockNotPosted, 'The stock location has no posted opening stock.', ['location_id' => $locationId]);
            }

            $journal    = $this->postedJournal($locationId, $revision);
            $reversalId = null;

            if ($journal !== null) {
                $reversalId = (new JournalService($this->db, $this->guard, $this->posting, $this->branches))->reverseOpening((int) $journal->journal_id, $date, $reason, $employeeId);
            } else {
                (new PeriodGuard($this->db))->enforce($date, (int) $locked->branch_id);
            }

            $this->ledger->reverseSource(self::SOURCE, $locationId, JournalEntryType::General, $date, $reversalId);

            $this->audit->write('opening_stock', $locationId, 'opening_stock_reversed', $employeeId, $this->origin($employeeId), ['revision' => $revision, 'reason' => $reason, 'location' => (string) $location->location_name], (int) $locked->branch_id);
        });
    }

    private function buildPlan(object $location): OpeningStockPlan
    {
        $locationId = (int) $location->location_id;
        $active     = $this->activeRevision($locationId);
        $lines      = [];
        $items      = [];

        foreach ($this->ledger->positions([$locationId]) as $position) {
            if ($position->covered()) {
                continue;
            }

            $unposted = $position->unposted();

            if (bccomp($unposted, '0', StockLedger::QUANTITY_SCALE) > 0) {
                $lines[] = new OpeningStockLine(
                    $position->itemId,
                    $position->itemName,
                    $unposted,
                    $position->itemCost,
                    SaleAmounts::round(bcmul($unposted, $position->itemCost, 8), StockLedger::VALUE_SCALE),
                );

                continue;
            }

            $negative = bccomp($position->onHand, '0', StockLedger::QUANTITY_SCALE) < 0;
            $items[]  = new OpeningStockFinding(
                $negative ? OpeningStockProblem::NegativeStock : OpeningStockProblem::MovementsExceedShelves,
                $negative ? $position->onHand : bcsub('0', $unposted, StockLedger::QUANTITY_SCALE),
                $position->itemId,
                $position->itemName,
            );
        }

        usort($lines, static fn (OpeningStockLine $a, OpeningStockLine $b): int => $a->itemId <=> $b->itemId);

        $name     = (string) $location->location_name;
        $branchId = (int) $location->branch_id;
        $value    = (new OpeningStockPlan($locationId, $name, $branchId, OpeningStockStatus::Postable, $lines, [], $active))->value();
        $findings = [];
        $status   = OpeningStockStatus::Postable;

        if ($lines === []) {
            $status = $active === null ? OpeningStockStatus::NothingToPost : OpeningStockStatus::AlreadyPosted;
        } elseif ($active !== null) {
            $status     = OpeningStockStatus::Blocked;
            $findings[] = new OpeningStockFinding(OpeningStockProblem::PostedBefore, $value);
        } elseif ($this->entities->accountFor(EntityAccountService::STOCK_LOCATION, $locationId) === null) {
            $status     = OpeningStockStatus::Blocked;
            $findings[] = new OpeningStockFinding(OpeningStockProblem::AccountMissing, '0');
        } else {
            $held = $this->ledger->reconcile([$locationId])[0]['unattributed'];

            if (bccomp(ltrim($held, '-') === '' ? '0' : ltrim($held, '-'), self::VALUE_TOLERANCE, StockLedger::VALUE_SCALE) > 0) {
                $status     = OpeningStockStatus::Blocked;
                $findings[] = new OpeningStockFinding(OpeningStockProblem::AccountHoldsValue, $held);
            }
        }

        return new OpeningStockPlan($locationId, $name, $branchId, $status, $lines, [...$findings, ...$items], $active, $active === null ? [] : $this->standingLines($locationId, $active));
    }

    /**
     * @return list<OpeningStockLine>
     */
    private function standingLines(int $locationId, int $revision): array
    {
        $entries = $this->db->prefixTable('accounting_stock_entries');
        $items   = $this->db->prefixTable('items');
        $rows    = $this->db->query(
            "SELECT p.item_id, i.name, p.quantity, p.value
             FROM {$entries} p
             JOIN {$items} i ON i.item_id = p.item_id
             LEFT JOIN {$entries} r ON r.source_type = p.source_type AND r.source_id = p.source_id AND r.entry_type = p.entry_type
                 AND r.revision = p.revision AND r.line_no = p.line_no AND r.kind = 'reversal'
             WHERE p.source_type = ? AND p.source_id = ? AND p.revision = ? AND p.kind = 'posting' AND r.entry_id IS NULL
             ORDER BY p.item_id ASC",
            [self::SOURCE, $locationId, $revision],
        )->getResult();

        return array_map(static function (object $row): OpeningStockLine {
            $units = bcadd((string) $row->quantity, '0', StockLedger::QUANTITY_SCALE);
            $value = bcadd((string) $row->value, '0', StockLedger::VALUE_SCALE);

            return new OpeningStockLine((int) $row->item_id, (string) $row->name, $units, bccomp($units, '0', StockLedger::QUANTITY_SCALE) > 0 ? bcdiv($value, $units, 8) : '0', $value);
        }, $rows);
    }

    /**
     * @throws AccountingException|PostingException
     */
    private function write(OpeningStockPlan $plan, string $date, ?int $employeeId): OpeningStockResult
    {
        $revision  = $this->nextRevision($plan->locationId);
        $value     = $plan->value();
        $movements = array_map(
            static fn (OpeningStockLine $line): StockMovement => new StockMovement($line->itemId, $plan->locationId, $line->units, $line->value),
            $plan->lines,
        );

        $journalId = null;

        if (bccomp($value, '0', StockLedger::VALUE_SCALE) > 0) {
            $inventory = $this->entities->accountFor(EntityAccountService::STOCK_LOCATION, $plan->locationId);
            $equity    = $this->resolver->accountFor(RoutingKey::OpeningBalance);

            if ($inventory === null || $equity === null) {
                throw new PostingException(PostingError::RoutingRowCancelled, 'The opening stock cannot be posted without the account of the stock location and the opening balance equity account.', ['routing_key' => RoutingKey::OpeningBalance->value]);
            }

            $result = $this->posting->post(new JournalDraft(
                date: $date,
                lines: [new JournalLineDraft($inventory, debit: $value), new JournalLineDraft($equity, credit: $value)],
                sourceType: self::SOURCE,
                sourceId: $plan->locationId,
                revision: $revision,
                origin: $this->origin($employeeId),
                employeeId: $employeeId,
                description: 'Opening stock: ' . mb_substr($plan->locationName, 0, 100),
                branchId: $plan->branchId,
                entryType: JournalEntryType::General,
            ));

            if (! $result->created) {
                throw new AccountingException(AccountingError::OpeningStockAlreadyPosted, 'The stock location already has posted opening stock.', ['location_id' => $plan->locationId]);
            }

            $journalId = $result->journalId;
        }

        $this->ledger->record(self::SOURCE, $plan->locationId, JournalEntryType::General, $revision, $date, $plan->branchId, $movements, $journalId);

        $this->audit->write('opening_stock', $plan->locationId, 'opening_stock_posted', $employeeId, $this->origin($employeeId), [
            'revision' => $revision,
            'items'    => count($plan->lines),
            'units'    => $plan->units(),
            'value'    => $value,
            'location' => $plan->locationName,
        ], $plan->branchId);

        return new OpeningStockResult($plan->with(OpeningStockStatus::Posted), $journalId, $revision);
    }

    /**
     * The revision of the opening stock that stands, which is the one whose movements have not
     * been taken back or whose journal has not been reversed, or null when none does.
     */
    private function activeRevision(int $locationId): ?int
    {
        $entries = $this->db->prefixTable('accounting_stock_entries');
        $open    = $this->db->query(
            "SELECT MAX(p.revision) AS revision
             FROM {$entries} p
             LEFT JOIN {$entries} r ON r.source_type = p.source_type AND r.source_id = p.source_id AND r.entry_type = p.entry_type
                 AND r.revision = p.revision AND r.line_no = p.line_no AND r.kind = 'reversal'
             WHERE p.source_type = ? AND p.source_id = ? AND p.kind = 'posting' AND r.entry_id IS NULL",
            [self::SOURCE, $locationId],
        )->getRow();

        $journal = $this->db->table('accounting_journals')
            ->selectMax('revision', 'revision')
            ->where('source_type', self::SOURCE)
            ->where('source_id', $locationId)
            ->where('status', JournalStatus::Posted->value)
            ->get()
            ->getRow();

        $revision = max((int) ($open->revision ?? 0), (int) ($journal->revision ?? 0));

        return $revision === 0 ? null : $revision;
    }

    private function nextRevision(int $locationId): int
    {
        $journal = $this->db->table('accounting_journals')->selectMax('revision', 'revision')->where('source_type', self::SOURCE)->where('source_id', $locationId)->get()->getRow();
        $entries = $this->db->table('accounting_stock_entries')->selectMax('revision', 'revision')->where('source_type', self::SOURCE)->where('source_id', $locationId)->get()->getRow();

        return max((int) ($journal->revision ?? 0), (int) ($entries->revision ?? 0)) + 1;
    }

    private function postedJournal(int $locationId, int $revision): ?object
    {
        return $this->db->table('accounting_journals')
            ->where('source_type', self::SOURCE)
            ->where('source_id', $locationId)
            ->where('revision', $revision)
            ->where('status', JournalStatus::Posted->value)
            ->get()
            ->getRow();
    }

    /**
     * @param list<int> $ids
     *
     * @return list<object>
     *
     * @throws AccountingException|BranchException
     */
    private function requireLocations(array $ids, ?int $employeeId): array
    {
        $rows = $this->db->table('stock_locations')
            ->select('location_id, location_name, branch_id')
            ->whereIn('location_id', $ids)
            ->where('deleted', 0)
            ->orderBy('location_id', 'ASC')
            ->get()
            ->getResult();

        if (count($rows) !== count($ids)) {
            throw new AccountingException(AccountingError::OpeningStockLocationNotFound, 'A stock location does not exist or is not in use.', ['count' => count($ids) - count($rows)]);
        }

        foreach ($rows as $row) {
            $this->requireBranch((int) $row->branch_id, $employeeId);
        }

        return $rows;
    }

    /**
     * Takes the rows of the stock locations, in the order of their ids, so that two requests for
     * the same locations run one after the other and the second finds what the first did.
     *
     * @param list<int> $ids
     *
     * @return list<object>
     *
     * @throws AccountingException
     */
    private function lockedLocations(array $ids): array
    {
        $table = $this->db->prefixTable('stock_locations');
        $rows  = LockedRead::rows(
            $this->db,
            "SELECT location_id, location_name, branch_id FROM {$table} WHERE location_id IN (" . implode(',', array_fill(0, count($ids), '?')) . ') AND deleted = 0 ORDER BY location_id ASC FOR UPDATE',
            $ids,
        );

        if (count($rows) !== count($ids)) {
            throw new AccountingException(AccountingError::OpeningStockLocationNotFound, 'A stock location does not exist or is not in use.', ['count' => count($ids) - count($rows)]);
        }

        return $rows;
    }

    /**
     * @param list<int> $ids
     */
    private function recordFailure(array $ids, AccountingException|PostingException $failure, ?int $employeeId): void
    {
        $posting = $failure instanceof PostingException
            ? $failure
            : new PostingException(PostingError::WriteFailed, $failure->getMessage(), $failure->context, $failure);

        $branch = $this->db->table('stock_locations')->select('branch_id')->where('location_id', $ids[0])->get()->getRow();

        (new PostingFailureLog($this->db))->recordUnbuilt(self::SOURCE, count($ids) === 1 ? $ids[0] : null, 1, JournalEntryType::General, $posting, $employeeId, $branch === null ? null : (int) $branch->branch_id);
    }

    /**
     * @throws AccountingException
     */
    private function startDate(): string
    {
        if (! $this->settings->isEnabled()) {
            throw new AccountingException(AccountingError::OpeningStockAccountingOff, 'Accounting is not turned on.');
        }

        $date = $this->settings->startDate();

        if ($date === null) {
            throw new AccountingException(AccountingError::OpeningStartDateMissing, 'The accounting start date is not set.');
        }

        return $date;
    }

    /**
     * @throws AccountingException
     */
    private function validDate(string $date): string
    {
        $parsed = DateTimeImmutable::createFromFormat('!Y-m-d', $date);

        if ($parsed === false || $parsed->format('Y-m-d') !== $date) {
            throw new AccountingException(AccountingError::InvalidDate, 'The date is not valid.');
        }

        return $date;
    }

    /**
     * @param list<int> $ids
     *
     * @return list<int>
     */
    private function normalized(array $ids): array
    {
        $ids = array_values(array_unique(array_filter(array_map('intval', $ids), static fn (int $id): bool => $id > 0)));
        sort($ids);

        return $ids;
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

    private function origin(?int $employeeId): JournalOrigin
    {
        return $employeeId === null ? JournalOrigin::System : JournalOrigin::User;
    }
}
