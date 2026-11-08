<?php

namespace App\Libraries\Assets;

use App\Libraries\Accounting\JournalDraft;
use App\Libraries\Accounting\JournalLineDraft;
use App\Libraries\Accounting\JournalOrigin;
use App\Libraries\Accounting\PostingError;
use App\Libraries\Accounting\PostingException;
use App\Libraries\Accounting\RoutingKey;
use App\Libraries\Accounting\RoutingResolver;

/**
 * The journals of the register of fixed assets. Their form is standard bookkeeping and none of them was
 * observed in the reference system, so they are assumptions: buying an asset debits its cost account and
 * credits the treasury it was paid from, the account of what is owed for assets, or for an asset already
 * owned when the books began the accumulated depreciation it had and the opening balance account for the
 * rest; a month of depreciation debits each asset's expense account and credits its accumulated
 * depreciation account; selling an asset takes its cost and its accumulated depreciation out, puts the
 * proceeds into the treasury and the difference to the gain or the loss account; writing it off takes the
 * same out and the book value to the loss account.
 */
final class AssetPostingBuilder
{
    private const SCALE = 4;

    public function __construct(private readonly RoutingResolver $routing)
    {
    }

    /**
     * The journal that brings an asset into the books, or null for an asset whose balances the books hold
     * already.
     *
     * @throws PostingException
     */
    public function acquisition(object $asset, string $date, int $revision, ?int $employeeId): ?JournalDraft
    {
        $cost  = bcadd((string) $asset->cost, '0', self::SCALE);
        $lines = [new JournalLineDraft((int) $asset->cost_account_id, debit: $cost, description: $this->describe($asset))];

        switch ((string) $asset->funding) {
            case 'treasury':
                $lines[] = new JournalLineDraft($this->routing->accountFor(RoutingKey::Treasuries, (int) $asset->treasury_id) ?? $this->unrouted(RoutingKey::Treasuries), credit: $cost, description: $this->describe($asset));

                break;

            case 'payable':
                $lines[] = new JournalLineDraft($this->routing->accountFor(RoutingKey::AssetPayable) ?? $this->unrouted(RoutingKey::AssetPayable), credit: $cost, description: $this->describe($asset));

                break;

            case 'opening':
                $accumulated = bcadd((string) $asset->opening_accumulated, '0', self::SCALE);
                $net         = bcsub($cost, $accumulated, self::SCALE);

                if (bccomp($accumulated, '0', self::SCALE) > 0) {
                    $lines[] = new JournalLineDraft((int) $asset->accumulated_account_id, credit: $accumulated, description: $this->describe($asset));
                }

                if (bccomp($net, '0', self::SCALE) > 0) {
                    $lines[] = new JournalLineDraft($this->routing->accountFor(RoutingKey::OpeningBalance) ?? $this->unrouted(RoutingKey::OpeningBalance), credit: $net, description: $this->describe($asset));
                }

                break;

            default:
                return null;
        }

        return new JournalDraft(
            date: $date,
            lines: $lines,
            sourceType: AssetSources::ACQUISITION,
            sourceId: (int) $asset->asset_id,
            revision: $revision,
            origin: $employeeId === null ? JournalOrigin::System : JournalOrigin::User,
            employeeId: $employeeId,
            description: 'Fixed asset ' . $this->describe($asset),
            branchId: (int) $asset->branch_id,
        );
    }

    /**
     * One journal for the branch and the month: a debit to the expense account and a credit to the
     * accumulated depreciation account of each asset, with the code of the asset on both lines.
     *
     * @param list<array{asset: object, amount: string}> $lines
     */
    public function depreciation(int $branchId, DepreciationPeriod $period, array $lines, int $runId, ?int $employeeId): JournalDraft
    {
        $drafts = [];

        foreach ($lines as $line) {
            $asset = $line['asset'];

            $drafts[] = new JournalLineDraft((int) $asset->expense_account_id, debit: $line['amount'], description: $this->describe($asset), costCenterId: $asset->cost_center_id === null ? null : (int) $asset->cost_center_id);
            $drafts[] = new JournalLineDraft((int) $asset->accumulated_account_id, credit: $line['amount'], description: $this->describe($asset));
        }

        return new JournalDraft(
            date: $period->end,
            lines: $drafts,
            sourceType: AssetSources::DEPRECIATION,
            sourceId: $runId,
            revision: 1,
            origin: $employeeId === null ? JournalOrigin::System : JournalOrigin::User,
            employeeId: $employeeId,
            description: 'Depreciation ' . $period->label(),
            branchId: $branchId,
        );
    }

    /**
     * @throws PostingException
     */
    public function sale(object $asset, string $date, string $proceeds, int $treasuryId, string $accumulated, int $operationId, ?int $employeeId): JournalDraft
    {
        $cost   = bcadd((string) $asset->cost, '0', self::SCALE);
        $result = bcsub($proceeds, bcsub($cost, $accumulated, self::SCALE), self::SCALE);
        $lines  = [new JournalLineDraft($this->routing->accountFor(RoutingKey::Treasuries, $treasuryId) ?? $this->unrouted(RoutingKey::Treasuries), debit: $proceeds, description: $this->describe($asset))];

        $this->takeOut($lines, $asset, $cost, $accumulated);

        if (bccomp($result, '0', self::SCALE) > 0) {
            $lines[] = new JournalLineDraft($this->routing->accountFor(RoutingKey::AssetGain) ?? $this->unrouted(RoutingKey::AssetGain), credit: $result, description: $this->describe($asset));
        } elseif (bccomp($result, '0', self::SCALE) < 0) {
            $lines[] = $this->loss($asset, bcsub('0', $result, self::SCALE));
        }

        return $this->disposal($asset, AssetSources::SALE, $date, $lines, $operationId, $employeeId, 'Sale of fixed asset ');
    }

    /**
     * @throws PostingException
     */
    public function writeOff(object $asset, string $date, string $accumulated, int $operationId, ?int $employeeId): JournalDraft
    {
        $cost  = bcadd((string) $asset->cost, '0', self::SCALE);
        $net   = bcsub($cost, $accumulated, self::SCALE);
        $lines = [];

        $this->takeOut($lines, $asset, $cost, $accumulated);

        if (bccomp($net, '0', self::SCALE) > 0) {
            $lines[] = $this->loss($asset, $net);
        }

        return $this->disposal($asset, AssetSources::WRITE_OFF, $date, $lines, $operationId, $employeeId, 'Write-off of fixed asset ');
    }

    /**
     * @param list<JournalLineDraft> $lines
     */
    private function takeOut(array &$lines, object $asset, string $cost, string $accumulated): void
    {
        if (bccomp($accumulated, '0', self::SCALE) > 0) {
            $lines[] = new JournalLineDraft((int) $asset->accumulated_account_id, debit: $accumulated, description: $this->describe($asset));
        }

        $lines[] = new JournalLineDraft((int) $asset->cost_account_id, credit: $cost, description: $this->describe($asset));
    }

    /**
     * @throws PostingException
     */
    private function loss(object $asset, string $amount): JournalLineDraft
    {
        return new JournalLineDraft($this->routing->accountFor(RoutingKey::AssetLoss) ?? $this->unrouted(RoutingKey::AssetLoss), debit: $amount, description: $this->describe($asset), costCenterId: $asset->cost_center_id === null ? null : (int) $asset->cost_center_id);
    }

    /**
     * @param list<JournalLineDraft> $lines
     */
    private function disposal(object $asset, string $source, string $date, array $lines, int $operationId, ?int $employeeId, string $description): JournalDraft
    {
        return new JournalDraft(
            date: $date,
            lines: $lines,
            sourceType: $source,
            sourceId: $operationId,
            revision: 1,
            origin: $employeeId === null ? JournalOrigin::System : JournalOrigin::User,
            employeeId: $employeeId,
            description: $description . $this->describe($asset),
            branchId: (int) $asset->branch_id,
        );
    }

    private function describe(object $asset): string
    {
        return mb_substr(trim($asset->code . ' ' . $asset->name), 0, 120);
    }

    /**
     * @throws PostingException
     */
    private function unrouted(RoutingKey $key): never
    {
        throw new PostingException(PostingError::RoutingRowCancelled, "The routing of {$key->value} is cancelled.", ['routing_key' => $key->value]);
    }
}
