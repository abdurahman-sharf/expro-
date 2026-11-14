<?php

namespace App\Libraries\Accounting\OpeningImport;

use App\Libraries\Accounting\AccountingError;
use App\Libraries\Accounting\AccountingException;
use App\Libraries\Accounting\AccountingGuard;
use App\Libraries\Accounting\AccountingMessage;
use App\Libraries\Accounting\AccountingSettings;
use App\Libraries\Accounting\AuditLog;
use App\Libraries\Accounting\Backfill\BackfillBudget;
use App\Libraries\Accounting\CustomerLock;
use App\Libraries\Accounting\CustomerOpening;
use App\Libraries\Accounting\ExportsCsv;
use App\Libraries\Accounting\JournalOrigin;
use App\Libraries\Accounting\LockOutcome;
use App\Libraries\Accounting\NamedLock;
use App\Libraries\Accounting\PeriodGuard;
use App\Libraries\Accounting\PostingError;
use App\Libraries\Accounting\PostingException;
use App\Libraries\Accounting\RunsInTransaction;
use App\Libraries\Accounting\SupplierAdvances;
use App\Libraries\Accounting\SupplierLock;
use App\Libraries\Organization\BranchError;
use App\Libraries\Organization\BranchException;
use App\Libraries\Organization\BranchGuard;
use App\Libraries\Organization\CompanyContext;
use CodeIgniter\Database\BaseConnection;
use CodeIgniter\Database\Exceptions\DatabaseException;
use Config\Database;
use DateTimeImmutable;

/**
 * Enters the opening balances of many customers or suppliers from one file. The preview reads the file and says,
 * row by row, what would be posted and what is wrong with it, and names what it saw with a fingerprint. The approval
 * reads the file again and posts only if it comes to the same fingerprint and every row is in order, through the
 * services that enter one balance (so a journal for each party, as the forms make them), a chunk of parties in a
 * transaction of its own under their locks, so that a run that stopped leaves whole chunks and no half of one.
 * A party that has the balance of the file already is left as it is, which is what lets the same file be run again.
 */
final class OpeningImport
{
    use ExportsCsv;
    use RunsInTransaction;

    public const CHUNK     = 25;
    private const NOTE_MAX = 500;

    private BaseConnection $db;
    private AccountingGuard $guard;
    private BranchGuard $branches;
    private AccountingSettings $settings;
    private PeriodGuard $periods;
    private CustomerOpening $customers;
    private SupplierAdvances $suppliers;
    private OpeningImportParties $parties;
    private AuditLog $audit;

    public function __construct(?BaseConnection $db = null, ?AccountingGuard $guard = null, ?BranchGuard $branches = null, private readonly int $partyLockWait = 15)
    {
        $this->db        = $db ?? Database::connect();
        $this->guard     = $guard ?? new AccountingGuard();
        $this->branches  = $branches ?? new BranchGuard($this->db);
        $this->settings  = new AccountingSettings($this->db);
        $this->periods   = new PeriodGuard($this->db);
        $this->customers = new CustomerOpening($this->db, $this->guard, $this->branches);
        $this->suppliers = new SupplierAdvances($this->db, $this->guard, $this->branches);
        $this->parties   = new OpeningImportParties($this->db);
        $this->audit     = new AuditLog($this->db);
    }

    /**
     * Reads the file and checks every row, writing nothing.
     *
     * @throws AccountingException|BranchException
     */
    public function plan(OpeningImportKind $kind, string $csv, ?int $employeeId = null): OpeningImportPlan
    {
        $branchId    = $this->assertAllowed($kind, $employeeId);
        $records     = (new OpeningImportParser())->parse($csv);
        $today       = date('Y-m-d');
        $defaultDate = $this->settings->startDate() ?? $today;
        $found       = $this->parties->resolve($kind, array_column($records, 'party'));
        $rows        = [];
        $uses        = [];

        foreach ($records as $record) {
            $row    = ['line' => $record['line'], 'party' => $record['party'], 'partyId' => null, 'name' => '', 'side' => null, 'amount' => null, 'date' => null, 'note' => $record['note'], 'errors' => [], 'detail' => '', 'posted' => false, 'debit' => null, 'credit' => null];
            $key    = OpeningImportParties::keyOf($record['party']);
            $others = $found[$key] ?? [];

            if ($key === '') {
                $row['errors'][] = OpeningImportError::PartyMissing;
            } elseif ($others === []) {
                $row['errors'][] = OpeningImportError::PartyUnknown;
            } elseif (count($others) > 1) {
                $row['errors'][] = OpeningImportError::PartyAmbiguous;
                $row['detail']   = implode('; ', array_map(static fn (int $id, string $name): string => '#' . $id . ' ' . $name, array_keys($others), array_values($others)));
            } else {
                $row['partyId']        = (int) array_key_first($others);
                $row['name']           = (string) reset($others);
                $uses[$row['partyId']] = ($uses[$row['partyId']] ?? 0) + 1;
            }

            [$amount, $positive, $amountError] = $this->amountOf($record['amount']);
            [$date, $dateError]                = $this->dayOf($record['date'] === '' ? $defaultDate : $record['date'], $today);

            if ($amountError !== null) {
                $row['errors'][] = $amountError;
            } else {
                $row['amount'] = $amount;
                $row['side']   = $kind->sideOf($positive);
            }

            if ($dateError !== null) {
                $row['errors'][] = $dateError;
            }

            $row['date'] = $date;
            $rows[]      = $row;
        }

        $standing = $this->parties->standing($kind, array_keys($uses));
        $refusals = [];

        foreach ($rows as &$row) {
            if ($row['partyId'] !== null && $uses[$row['partyId']] > 1) {
                $row['errors'][] = OpeningImportError::PartyDuplicate;
            }

            if ($row['errors'] !== [] || $row['partyId'] === null) {
                continue;
            }

            $existing = $standing[$row['partyId']] ?? [];

            if ($existing !== []) {
                $same = array_filter($existing, static fn (array $opening): bool => $opening['side'] === $row['side'] && bccomp($opening['amount'], $row['amount'], 4) === 0 && $opening['date'] === $row['date']);

                if ($same === []) {
                    $row['errors'][] = OpeningImportError::OpeningExists;
                } else {
                    $row['posted'] = true;
                }

                continue;
            }

            if (! array_key_exists($row['date'], $refusals)) {
                $refusals[$row['date']] = $this->dayRefusal($row['date'], $branchId);
            }

            if ($refusals[$row['date']] !== null) {
                [$error, $detail] = $refusals[$row['date']];

                $row['errors'][] = $error;
                $row['detail']   = $detail;

                continue;
            }

            try {
                $accounts      = $this->accountsOf($kind, $row['partyId'], $row['side']);
                $row['debit']  = $accounts['debit'];
                $row['credit'] = $accounts['credit'];
            } catch (PostingException $failure) {
                $row['errors'][] = $failure->error === PostingError::EntityAccountMissing ? OpeningImportError::PartyNoAccount : OpeningImportError::PostingRefused;
                $row['detail']   = $failure->error->value;
            }
        }

        unset($row);

        $built = array_map(static fn (array $row): OpeningImportRow => new OpeningImportRow($row['line'], $row['party'], $row['partyId'], $row['name'], $row['side'], $row['amount'], $row['date'], $row['note'], $row['errors'], $row['detail'], $row['posted'], $row['debit'], $row['credit']), $rows);

        return new OpeningImportPlan($kind, $built, hash('sha256', $csv), $this->fingerprintOf($kind, $built, $defaultDate, $branchId), $defaultDate, $branchId);
    }

    /**
     * Posts the rows of the file that are not posted yet, as many chunks as it is given or as many as fit in the
     * seconds it is given, whichever is spent first, and says what stands. It posts nothing unless the file comes to
     * the fingerprint the preview showed and every row is in order; a run that stopped is run again with the same
     * file and goes on from the rows that are not posted.
     *
     * @throws AccountingException|BranchException
     */
    public function approve(OpeningImportKind $kind, string $csv, string $fingerprint, ?int $employeeId = null, ?int $maxChunks = null, ?float $maxSeconds = null): OpeningImportResult
    {
        $this->assertAllowed($kind, $employeeId);

        return $this->exclusive($kind, function () use ($kind, $csv, $fingerprint, $employeeId, $maxChunks, $maxSeconds): OpeningImportResult {
            $plan = $this->plan($kind, $csv, $employeeId);

            if (! hash_equals($plan->fingerprint, $fingerprint)) {
                throw new AccountingException(AccountingError::OpeningImportChanged, 'The file or the books are not what the preview showed.');
            }

            $failing = $plan->failing();

            if ($failing !== []) {
                throw new AccountingException(AccountingError::OpeningImportHasErrors, count($failing) . ' row(s) of the file have errors.', ['count' => count($failing)]);
            }

            $pending = $plan->pending();
            $budget  = new BackfillBudget($maxChunks === null ? null : max(1, $maxChunks), $maxSeconds);
            $posted  = 0;
            $failed  = null;

            foreach (array_chunk($pending, self::CHUNK) as $chunk) {
                $stoppedAt = null;

                try {
                    $this->postChunk($kind, $chunk, $plan, $employeeId, $stoppedAt);
                } catch (AccountingException|BranchException|DatabaseException|PostingException $failure) {
                    $row    = $stoppedAt ?? $chunk[0];
                    $failed = ['line' => $row->line, 'party' => $row->partyName, 'message' => $failure instanceof DatabaseException ? lang('Accounting.error_write_failed') : AccountingMessage::of($failure)];

                    break;
                }

                $posted += count($chunk);

                $budget->spend();

                if ($budget->exhausted()) {
                    break;
                }
            }

            $result = new OpeningImportResult($posted, $plan->postedCount(), count($pending) - $posted, count($plan->rows), $plan->fingerprint, $failed);

            if ($posted > 0 || $failed !== null) {
                $this->audit->write('opening_import', null, 'opening_import_posted', $employeeId, $employeeId === null ? JournalOrigin::System : JournalOrigin::User, [
                    'kind'        => $kind->value,
                    'file'        => $plan->fileHash,
                    'fingerprint' => $plan->fingerprint,
                    'rows'        => $result->total,
                    'posted'      => $posted,
                    'remaining'   => $result->remaining,
                    ...($failed === null ? [] : ['failed_line' => $failed['line']]),
                ], $plan->branchId);
            }

            return $result;
        });
    }

    /**
     * The sample file of a kind: its remarks, the sign convention and a few example rows.
     */
    public function sample(OpeningImportKind $kind, string $startDate): string
    {
        $handle = fopen('php://temp', 'r+b');
        $prefix = 'Accounting_opening_import.sample_' . $kind->value . '_';

        foreach (['title', 'party', 'amount', 'date', 'note', 'idempotent'] as $remark) {
            fwrite($handle, '# ' . str_replace(['{date}'], [$startDate], lang($prefix . $remark)) . "\n");
        }

        $this->csvRow($handle, ['party', 'amount', 'date', 'note']);
        $this->csvRow($handle, [lang('Accounting_opening_import.sample_name'), '2500.00', $startDate, lang($prefix . 'note_positive')]);
        $this->csvRow($handle, ['id:1001', '-150.00', '', lang($prefix . 'note_negative')]);
        $this->csvRow($handle, ['code:A-204', '0.50', $startDate, '']);

        return $this->csvText($handle);
    }

    /**
     * What a file says, row by row, as a file of its own: for the rows that have errors, to correct them from, and for
     * the rows that were posted, to keep.
     */
    public function report(OpeningImportPlan $plan): string
    {
        $handle = fopen('php://temp', 'r+b');

        $this->csvRow($handle, ['line', 'party', 'party_id', 'name', 'amount', 'date', 'note', 'state', 'errors']);

        foreach ($plan->rows as $row) {
            $this->csvRow($handle, [
                (string) $row->line,
                $row->party,
                $row->partyId === null ? '' : (string) $row->partyId,
                $row->partyName,
                $row->amount === null ? '' : ($row->side === $plan->kind->sideOf(true) ? '' : '-') . $row->amount,
                $row->date ?? '',
                $row->note,
                $row->state(),
                implode(' | ', array_map(static fn (OpeningImportError $error): string => lang($error->languageKey()), $row->errors)) . ($row->detail === '' ? '' : ' (' . $row->detail . ')'),
            ]);
        }

        return $this->csvText($handle);
    }

    /**
     * @return int The branch the balances are posted in: the default branch of the employee.
     *
     * @throws AccountingException|BranchException
     */
    private function assertAllowed(OpeningImportKind $kind, ?int $employeeId): int
    {
        $this->guard->require($kind->permission(), $employeeId);
        $this->branches->requireCompanyScope($employeeId);

        if (! $this->settings->isEnabled()) {
            throw new AccountingException(AccountingError::OpeningStockAccountingOff, 'Accounting is not turned on.');
        }

        if ($kind === OpeningImportKind::Customers && ! $this->settings->customerOpeningEnabled()) {
            throw new AccountingException(AccountingError::CustomerOpeningOff, 'Opening balances of customers are not turned on.');
        }

        $branchId = $this->branches->defaultBranchId($employeeId);
        $branch   = $this->db->table('branches')->where('branch_id', $branchId)->where('company_id', CompanyContext::id())->get()->getRow();

        if ($branch === null) {
            throw new BranchException(BranchError::NotFound, "Branch {$branchId} does not exist.", ['branch_id' => $branchId]);
        }

        $this->branches->requireBranch($branchId, $employeeId);

        if ((int) $branch->active === 0) {
            throw new BranchException(BranchError::Inactive, "Branch {$branchId} is inactive.", ['branch_id' => $branchId]);
        }

        return $branchId;
    }

    /**
     * @return array{0: ?numeric-string, 1: bool, 2: ?OpeningImportError} The amount without its sign, whether the file gave it a plus, and what is wrong with it.
     */
    private function amountOf(string $text): array
    {
        $text     = strtr(trim($text), ['٠' => '0', '١' => '1', '٢' => '2', '٣' => '3', '٤' => '4', '٥' => '5', '٦' => '6', '٧' => '7', '٨' => '8', '٩' => '9', '٫' => '.', '٬' => ',', "\u{00A0}" => '', ' ' => '']);
        $positive = true;

        if (preg_match('/^\((.+)\)$/', $text, $match) === 1) {
            $positive = false;
            $text     = $match[1];
        }

        if ($text !== '' && ($text[0] === '-' || $text[0] === '+')) {
            $positive = $text[0] === '+' ? $positive : ! $positive;
            $text     = substr($text, 1);
        }

        if (preg_match('/^\d{1,3}(,\d{3})+(\.\d+)?$/', $text) === 1) {
            $text = str_replace(',', '', $text);
        }

        if (preg_match('/^\d{1,11}(\.\d{1,4})?$/', $text) !== 1) {
            return [null, true, OpeningImportError::AmountInvalid];
        }

        $amount = bcadd($text, '0', 4);

        return bccomp($amount, '0', 4) === 0 ? [null, true, OpeningImportError::AmountZero] : [$amount, $positive, null];
    }

    /**
     * @return array{0: ?string, 1: ?OpeningImportError}
     */
    private function dayOf(string $text, string $today): array
    {
        $text = str_replace('/', '-', strtr(trim($text), ['٠' => '0', '١' => '1', '٢' => '2', '٣' => '3', '٤' => '4', '٥' => '5', '٦' => '6', '٧' => '7', '٨' => '8', '٩' => '9']));
        $day  = DateTimeImmutable::createFromFormat('!Y-m-d', $text);

        if ($day === false || $day->format('Y-m-d') !== $text) {
            return [null, OpeningImportError::DateInvalid];
        }

        return $text > $today ? [$text, OpeningImportError::DateFuture] : [$text, null];
    }

    /**
     * @return array{0: OpeningImportError, 1: string}|null
     */
    private function dayRefusal(string $date, int $branchId): ?array
    {
        try {
            $this->periods->check($date, $branchId);
        } catch (PostingException $failure) {
            return match ($failure->error) {
                PostingError::PeriodClosed      => [OpeningImportError::PeriodClosed, ''],
                PostingError::OutsideFiscalYear => [OpeningImportError::OutsideFiscalYear, ''],
                default                         => [OpeningImportError::PostingRefused, $failure->error->value],
            };
        }

        return null;
    }

    /**
     * @return array{debit: int, credit: int}
     *
     * @throws PostingException
     */
    private function accountsOf(OpeningImportKind $kind, int $partyId, string $side): array
    {
        return $kind === OpeningImportKind::Customers ? $this->customers->accountsFor($partyId, $side) : $this->suppliers->openingAccounts($partyId, $side);
    }

    /**
     * @param list<OpeningImportRow> $rows
     */
    private function fingerprintOf(OpeningImportKind $kind, array $rows, string $defaultDate, int $branchId): string
    {
        $hash = hash_init('sha256');

        hash_update($hash, implode('|', [$kind->value, $defaultDate, $branchId]) . "\n");

        foreach ($rows as $row) {
            hash_update($hash, implode('|', [$row->line, $row->partyId, $row->side, $row->amount, $row->date, $row->note, $row->errors === [] ? 'ok' : implode(',', array_map(static fn (OpeningImportError $error): string => $error->value, $row->errors)), $row->detail]) . "\n");
        }

        return hash_final($hash);
    }

    /**
     * @param list<OpeningImportRow> $rows
     *
     * @throws AccountingException|BranchException|PostingException
     */
    private function postChunk(OpeningImportKind $kind, array $rows, OpeningImportPlan $plan, ?int $employeeId, ?OpeningImportRow &$stoppedAt): void
    {
        $this->underPartyLocks($kind, array_map(static fn (OpeningImportRow $row): int => (int) $row->partyId, $rows), fn () => $this->transactional(function () use ($kind, $rows, $plan, $employeeId, &$stoppedAt): void {
            foreach ($rows as $row) {
                $stoppedAt = $row;
                $note      = trim($row->note) !== '' ? mb_substr($row->note, 0, self::NOTE_MAX) : 'csv:' . substr($plan->fileHash, 0, 12);

                if ($kind === OpeningImportKind::Customers) {
                    $this->customers->create((int) $row->partyId, (string) $row->side, (string) $row->amount, (string) $row->date, $note, $employeeId, $plan->branchId);
                } else {
                    $this->suppliers->openingBalance((int) $row->partyId, (string) $row->side, (string) $row->amount, (string) $row->date, $note, $employeeId);
                }
            }

            $stoppedAt = null;
        }));
    }

    /**
     * @param list<int>        $partyIds
     * @param callable(): void $work
     */
    private function underPartyLocks(OpeningImportKind $kind, array $partyIds, callable $work): void
    {
        $partyIds = array_values(array_unique($partyIds));

        sort($partyIds);

        $run = $work;

        foreach (array_reverse($partyIds) as $partyId) {
            $inner = $run;
            $run   = $kind === OpeningImportKind::Customers
                ? fn () => (new CustomerLock($this->db, $this->partyLockWait))->run($partyId, $inner)
                : fn () => (new SupplierLock($this->db, $this->partyLockWait))->run($partyId, $inner);
        }

        $run();
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
    private function exclusive(OpeningImportKind $kind, callable $work): mixed
    {
        $name  = 'ospos_opening_import_' . substr(sha1($this->db->getDatabase()), 0, 10) . '_' . $kind->value;
        $lock  = new NamedLock($this->db, 0);
        $taken = $lock->take($name);

        if ($taken !== LockOutcome::Taken) {
            throw new AccountingException(AccountingError::OpeningImportBusy, 'Another import of opening balances is running.', ['lock' => $taken->value]);
        }

        try {
            return $work();
        } finally {
            $lock->release($name);
        }
    }
}
