<?php

namespace App\Libraries\Assets;

use App\Libraries\Accounting\AccountingError;
use App\Libraries\Accounting\AccountingException;
use App\Libraries\Accounting\AccountingGuard;
use App\Libraries\Accounting\AuditLog;
use App\Libraries\Accounting\Currencies;
use App\Libraries\Accounting\JournalEntryType;
use App\Libraries\Accounting\JournalOrigin;
use App\Libraries\Accounting\JournalService;
use App\Libraries\Accounting\JournalStatus;
use App\Libraries\Accounting\LockedRead;
use App\Libraries\Accounting\PeriodLocks;
use App\Libraries\Accounting\PostingException;
use App\Libraries\Accounting\PostingFailureLog;
use App\Libraries\Accounting\PostingService;
use App\Libraries\Accounting\RoutingResolver;
use App\Libraries\Accounting\RunsInTransaction;
use App\Libraries\Accounting\TreasuryScopes;
use App\Libraries\Organization\BranchException;
use App\Libraries\Organization\BranchGuard;
use App\Libraries\Organization\CompanyContext;
use CodeIgniter\Database\BaseConnection;
use CodeIgniter\Database\RawSql;
use Config\Database;
use DateTimeImmutable;

/**
 * Taking an asset out of the books: selling it for money paid into a treasury, or writing it off. Both
 * take the cost and the accumulated depreciation of the asset out; a sale puts the proceeds into the
 * treasury and the difference from the book value to the gain or the loss account, a write-off puts the
 * book value to the loss account. The asset becomes sold or written off and nothing is deleted. The
 * depreciation due for the months before the disposal has to be run first, and a disposal is taken back
 * on the day it has, so a period that is closed is refused. A sale on credit to a customer is refused,
 * and so is a revaluation: neither can be posted without reaching beyond the books of the asset.
 */
final class AssetDisposals
{
    use RunsInTransaction;

    public const ENTITY      = 'asset_operation';
    private const SCALE      = 4;
    private const AMOUNT     = '/^\d{1,11}(\.\d{1,4})?$/';
    private const NOTE_MAX   = 500;
    private const REASON_MAX = 255;

    private BaseConnection $db;
    private AccountingGuard $guard;
    private BranchGuard $branches;
    private PostingService $posting;
    private AssetPostingBuilder $builder;
    private AssetFigures $figures;
    private TreasuryScopes $scopes;
    private AuditLog $audit;
    private PeriodLocks $locks;

    public function __construct(?BaseConnection $db = null, ?AccountingGuard $guard = null, ?BranchGuard $branches = null)
    {
        $this->db       = $db ?? Database::connect();
        $this->guard    = $guard ?? new AccountingGuard();
        $this->branches = $branches ?? new BranchGuard($this->db);
        $this->posting  = new PostingService($this->db);
        $this->builder  = new AssetPostingBuilder(new RoutingResolver($this->db));
        $this->figures  = new AssetFigures($this->db, new DepreciationSchedule((new Currencies($this->db))->baseDecimals()));
        $this->scopes   = new TreasuryScopes($this->db, $this->branches);
        $this->audit    = new AuditLog($this->db);
        $this->locks    = new PeriodLocks($this->db);
    }

    /**
     * Sells an asset for money paid into a treasury of its branch.
     *
     * @param array{date?: string, proceeds?: string, treasury_id?: int|string, customer_id?: int|string, note?: string} $input
     *
     * @return int The disposal.
     *
     * @throws AccountingException|BranchException|PostingException
     */
    public function sell(int $assetId, array $input, ?int $employeeId = null): int
    {
        $this->guard->require(AccountingGuard::ASSETS_MANAGE, $employeeId);

        if ((int) ($input['customer_id'] ?? 0) > 0) {
            throw new AccountingException(AccountingError::AssetSaleOnCredit, 'A sale of an asset on credit to a customer is not built.');
        }

        return $this->dispose('sale', $assetId, $input, $employeeId);
    }

    /**
     * Writes an asset off: its book value is lost.
     *
     * @param array{date?: string, note?: string} $input
     *
     * @return int The disposal.
     *
     * @throws AccountingException|BranchException|PostingException
     */
    public function writeOff(int $assetId, array $input, ?int $employeeId = null): int
    {
        $this->guard->require(AccountingGuard::ASSETS_MANAGE, $employeeId);

        return $this->dispose('write_off', $assetId, $input, $employeeId);
    }

    /**
     * A revaluation changes the cost the depreciation of the asset follows, and how it is posted and
     * depreciated afterwards was not decided, so it is refused whatever is asked.
     *
     * @param array<string, mixed> $input
     *
     * @throws AccountingException
     */
    public function revalue(int $assetId, array $input, ?int $employeeId = null): never
    {
        $this->guard->require(AccountingGuard::ASSETS_MANAGE, $employeeId);

        throw new AccountingException(AccountingError::AssetRevaluationRefused, "Asset {$assetId} cannot be revalued.", ['name' => (string) $assetId]);
    }

    /**
     * Takes a disposal back: its journal is mirrored on the day it was written and the asset is as it
     * was before. A period that is closed is refused.
     *
     * @return int The journal of the reversal.
     *
     * @throws AccountingException|BranchException|PostingException
     */
    public function reverse(int $operationId, string $reason, ?int $employeeId = null): int
    {
        $this->guard->require(AccountingGuard::ASSETS_MANAGE, $employeeId);

        $reason = trim($reason);

        if ($reason === '' || mb_strlen($reason) > self::REASON_MAX) {
            throw new AccountingException(AccountingError::ReasonRequired, 'A reason of 1 to 255 characters is required.');
        }

        $known = $this->db->table('asset_operations')->where('company_id', CompanyContext::id())->where('operation_id', $operationId)->get()->getRow();

        if ($known === null) {
            throw new AccountingException(AccountingError::AssetOperationNotFound, "Disposal {$operationId} does not exist.", ['name' => (string) $operationId]);
        }

        $this->branches->requireBranch((int) $known->branch_id, $employeeId);

        return $this->transactional(function () use ($operationId, $reason, $employeeId, $known): int {
            $this->locks->shareForPosting((int) $known->branch_id);

            $table     = $this->db->prefixTable('asset_operations');
            $operation = LockedRead::row($this->db, "SELECT * FROM {$table} WHERE operation_id = ? FOR UPDATE", [$operationId]);

            if ($operation === null || $operation->status !== 'active') {
                throw new AccountingException(AccountingError::AssetOperationReversed, 'The disposal is taken back already.', ['name' => (string) $operationId]);
            }

            $assets = $this->db->prefixTable('assets');

            LockedRead::row($this->db, "SELECT asset_id FROM {$assets} WHERE asset_id = ? FOR UPDATE", [(int) $known->asset_id]);

            $this->scopes->assertMayReverse((int) $operation->journal_id, $employeeId);

            $reversal = (new JournalService($this->db, $this->guard, $this->posting, $this->branches))->reverseAutomatic((int) $operation->journal_id, (string) $operation->operation_date, $reason, $employeeId);

            $this->db->table('asset_operations')->where('operation_id', $operationId)->update([
                'status'              => 'reversed',
                'active_key'          => null,
                'reversal_journal_id' => $reversal,
                'reversed_by'         => $employeeId,
                'reversed_at'         => new RawSql('CURRENT_TIMESTAMP'),
                'reverse_reason'      => $reason,
            ]);

            $this->db->table('assets')->where('asset_id', (int) $operation->asset_id)->update(['status' => $operation->previous_status, 'disposed_at' => null]);

            $this->audit->write(self::ENTITY, $operationId, 'asset_disposal_reversed', $employeeId, $this->origin($employeeId), [
                'asset_id'    => (int) $operation->asset_id,
                'kind'        => $operation->kind,
                'journal_id'  => (int) $operation->journal_id,
                'reversal_id' => $reversal,
                'reason'      => $reason,
            ], (int) $operation->branch_id);

            return $reversal;
        });
    }

    /**
     * The disposals of the branches the employee may see, the latest first, those of one asset when given.
     *
     * @return list<object>
     */
    public function operations(?int $employeeId, ?int $assetId = null): array
    {
        $allowed = $this->branches->allowedBranchIds($employeeId);

        if ($allowed === []) {
            return [];
        }

        $builder = $this->db->table('asset_operations o')
            ->select("o.*, a.code AS asset_code, a.name AS asset_name, b.code AS branch_code, TRIM(CONCAT(cp.first_name, ' ', cp.last_name)) AS created_by_name", false)
            ->join('assets a', 'a.asset_id = o.asset_id')
            ->join('branches b', 'b.branch_id = o.branch_id')
            ->join('people cp', 'cp.person_id = o.created_by', 'left')
            ->where('o.company_id', CompanyContext::id());

        if ($allowed !== null) {
            $builder->whereIn('o.branch_id', $allowed);
        }

        if ($assetId !== null) {
            $builder->where('o.asset_id', $assetId);
        }

        return $builder->orderBy('o.operation_date', 'DESC')->orderBy('o.operation_id', 'DESC')->get()->getResult();
    }

    /**
     * @param array<string, mixed> $input
     *
     * @throws AccountingException|BranchException|PostingException
     */
    private function dispose(string $kind, int $assetId, array $input, ?int $employeeId): int
    {
        $known = $this->db->table('assets')->where('company_id', CompanyContext::id())->where('asset_id', $assetId)->where('deleted', 0)->get()->getRow();

        if ($known === null || ! $this->branches->canAccess((int) $known->branch_id, $employeeId)) {
            throw new AccountingException(AccountingError::AssetNotFound, "Asset {$assetId} does not exist.", ['name' => (string) $assetId]);
        }

        $date  = $this->date($input['date'] ?? '', $known);
        $note  = mb_substr(trim((string) ($input['note'] ?? '')), 0, self::NOTE_MAX);
        $sale  = $kind === 'sale';
        $money = $sale ? $this->proceeds($input['proceeds'] ?? '') : bcadd('0', '0', self::SCALE);

        try {
            return $this->transactional(function () use ($kind, $assetId, $date, $note, $sale, $money, $input, $employeeId, $known): int {
                $this->locks->shareForPosting((int) $known->branch_id);

                $assets = $this->db->prefixTable('assets');
                $asset  = LockedRead::row($this->db, "SELECT * FROM {$assets} WHERE asset_id = ? AND deleted = 0 FOR UPDATE", [$assetId]);

                if ($asset === null) {
                    throw new AccountingException(AccountingError::AssetNotFound, "Asset {$assetId} does not exist.", ['name' => (string) $assetId]);
                }

                $status = AssetStatus::from((string) $asset->status);

                if (! $status->canBeDisposed()) {
                    throw new AccountingException(AccountingError::AssetStatusInvalid, 'An asset that was sold or written off is not disposed of again.', ['name' => $status->value]);
                }

                $standing = $this->figures->posted($assetId, true);

                foreach (array_keys($standing) as $start) {
                    if ($start > $date) {
                        throw new AccountingException(AccountingError::AssetDisposalDateInvalid, 'A depreciation stands for a month after the date of the disposal.', ['date' => $date]);
                    }
                }

                $missed = $this->figures->missed($asset, DepreciationPeriod::containing($date), $standing, true);

                if ($missed !== []) {
                    throw new AccountingException(AccountingError::AssetDepreciationPending, 'The asset is due depreciation for months before the disposal.', ['name' => implode(', ', $missed)]);
                }

                $treasuryId  = $sale ? $this->treasury($input['treasury_id'] ?? null, (int) $asset->branch_id) : null;
                $cost        = bcadd((string) $asset->cost, '0', self::SCALE);
                $accumulated = $this->figures->accumulated($asset, null, true);
                $book        = bcsub($cost, $accumulated, self::SCALE);

                if ($asset->funding === 'none') {
                    $this->assertBooksHold($asset, $cost, $accumulated, $date);
                }

                $difference  = $sale ? bcsub($money, $book, self::SCALE) : bcsub('0', $book, self::SCALE);

                $this->db->table('asset_operations')->insert([
                    'company_id'          => CompanyContext::id(),
                    'branch_id'           => (int) $asset->branch_id,
                    'asset_id'            => $assetId,
                    'kind'                => $kind,
                    'operation_date'      => $date,
                    'proceeds'            => $money,
                    'treasury_id'         => $treasuryId,
                    'cost_removed'        => $cost,
                    'accumulated_removed' => $accumulated,
                    'result_amount'       => $difference,
                    'previous_status'     => $status->value,
                    'note'                => $note,
                    'status'              => 'active',
                    'active_key'          => 1,
                    'created_by'          => $employeeId,
                ]);

                $operationId = (int) $this->db->insertID();
                $draft       = $sale
                    ? $this->builder->sale($asset, $date, $money, (int) $treasuryId, $accumulated, $operationId, $employeeId)
                    : $this->builder->writeOff($asset, $date, $accumulated, $operationId, $employeeId);

                $this->scopes->assertMayPost($draft->lines, $employeeId);

                $posted = $this->posting->post($draft);

                if (! $posted->created) {
                    throw new AccountingException(AccountingError::WriteFailed, "A journal of the disposal {$operationId} exists already.");
                }

                $this->db->table('asset_operations')->where('operation_id', $operationId)->update(['journal_id' => $posted->journalId]);
                $this->db->table('assets')->where('asset_id', $assetId)->update(['status' => $sale ? AssetStatus::Sold->value : AssetStatus::WrittenOff->value, 'disposed_at' => $date]);

                $this->audit->write(self::ENTITY, $operationId, $sale ? 'asset_sold' : 'asset_written_off', $employeeId, $this->origin($employeeId), [
                    'asset_id'   => $assetId,
                    'date'       => $date,
                    'proceeds'   => $money,
                    'book_value' => $book,
                    'journal_id' => $posted->journalId,
                ], (int) $asset->branch_id);

                return $operationId;
            });
        } catch (PostingException $failure) {
            (new PostingFailureLog($this->db))->recordUnbuilt($sale ? AssetSources::SALE : AssetSources::WRITE_OFF, null, 1, JournalEntryType::General, $failure, $employeeId, (int) $known->branch_id);

            throw $failure;
        }
    }

    /**
     * An asset entered as already in the books has no journal of its own, so what a disposal takes out
     * is checked against the accounts of its branch: the cost account holds at least its cost and the
     * accumulated depreciation account at least what it has depreciated by.
     *
     * @throws AccountingException
     */
    private function assertBooksHold(object $asset, string $cost, string $accumulated, string $date): void
    {
        $held = $this->ledgerNet((int) $asset->cost_account_id, (int) $asset->branch_id, $date);

        if (bccomp($held, $cost, self::SCALE) < 0) {
            throw new AccountingException(AccountingError::AssetNotInBooks, "The cost account holds less than the cost of asset {$asset->code}.", ['name' => (string) $asset->code]);
        }

        if ($asset->accumulated_account_id === null || bccomp($accumulated, '0', self::SCALE) <= 0) {
            return;
        }

        $held = bcsub('0', $this->ledgerNet((int) $asset->accumulated_account_id, (int) $asset->branch_id, $date), self::SCALE);

        if (bccomp($held, $accumulated, self::SCALE) < 0) {
            throw new AccountingException(AccountingError::AssetNotInBooks, "The accumulated depreciation account holds less than asset {$asset->code} has depreciated by.", ['name' => (string) $asset->code]);
        }
    }

    /**
     * What an account of a branch holds through a day, debits less credits.
     *
     * @return numeric-string
     */
    private function ledgerNet(int $accountId, int $branchId, string $date): string
    {
        $journals = $this->db->prefixTable('accounting_journals');
        $lines    = $this->db->prefixTable('accounting_journal_lines');
        $row      = $this->db->query(
            "SELECT COALESCE(SUM(l.debit), 0) - COALESCE(SUM(l.credit), 0) AS net FROM {$lines} l JOIN {$journals} j ON j.journal_id = l.journal_id
             WHERE j.company_id = ? AND j.branch_id = ? AND j.status IN (?, ?) AND j.journal_date <= ? AND l.account_id = ?",
            [CompanyContext::id(), $branchId, JournalStatus::Posted->value, JournalStatus::Reversed->value, $date, $accountId],
        )->getRow();

        return bcadd((string) $row->net, '0', self::SCALE);
    }

    /**
     * The day of a disposal: a day from the day the asset went into service to today.
     *
     * @throws AccountingException
     */
    private function date(mixed $value, object $asset): string
    {
        $text   = is_string($value) ? trim($value) : '';
        $parsed = DateTimeImmutable::createFromFormat('!Y-m-d', $text);

        if ($parsed === false || $parsed->format('Y-m-d') !== $text || $text > date('Y-m-d') || $text < (string) $asset->in_service_date) {
            throw new AccountingException(AccountingError::AssetDisposalDateInvalid, 'The date of the disposal is not valid.', ['date' => $text]);
        }

        return $text;
    }

    /**
     * @return numeric-string
     *
     * @throws AccountingException
     */
    private function proceeds(mixed $value): string
    {
        $text = is_scalar($value) ? trim((string) $value) : '';

        if (preg_match(self::AMOUNT, $text) !== 1 || bccomp($text, '0', self::SCALE) <= 0) {
            throw new AccountingException(AccountingError::AssetProceedsInvalid, 'The proceeds of a sale are an amount above zero.');
        }

        return bcadd($text, '0', self::SCALE);
    }

    /**
     * @throws AccountingException
     */
    private function treasury(mixed $treasuryId, int $branchId): int
    {
        $id       = (int) $treasuryId;
        $treasury = $id === 0 ? null : $this->db->table('treasuries')->where('company_id', CompanyContext::id())->where('treasury_id', $id)->where('active', 1)->get()->getRow();

        if ($treasury === null || (int) $treasury->branch_id !== $branchId) {
            throw new AccountingException(AccountingError::AssetProceedsInvalid, 'The proceeds are paid into an active treasury of the branch of the asset.');
        }

        return $id;
    }

    private function origin(?int $employeeId): JournalOrigin
    {
        return $employeeId === null ? JournalOrigin::System : JournalOrigin::User;
    }
}
