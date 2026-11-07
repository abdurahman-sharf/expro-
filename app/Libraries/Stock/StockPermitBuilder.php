<?php

namespace App\Libraries\Stock;

use App\Libraries\Accounting\JournalDraft;
use App\Libraries\Accounting\JournalEntryType;
use App\Libraries\Accounting\JournalLineDraft;
use App\Libraries\Accounting\JournalOrigin;
use App\Libraries\Accounting\PostingError;
use App\Libraries\Accounting\PostingException;
use App\Libraries\Accounting\ReceivingDocument;
use App\Libraries\Accounting\ReceivingLine;
use App\Libraries\Accounting\ReceivingPostingBuilder;
use App\Libraries\Accounting\ReceivingType;
use App\Libraries\Accounting\RoutingKey;
use App\Libraries\Accounting\RoutingResolver;
use App\Libraries\Accounting\SaleAmounts;
use App\Libraries\Accounting\StockLedger;
use App\Libraries\Accounting\StockMovement;
use App\Libraries\Accounting\StockPostingPlan;

/**
 * The journals and the stock movements a stock permit needs, as a plan. It writes nothing. An add
 * permit puts its units into the stock location at the cost typed on each line and credits the
 * account of the permit (the automatic account of add permits, or the one chosen). An issue permit
 * takes its units out at what a unit is worth where it leaves, which is what the books say and not
 * what is typed, and debits the account of the permit. A transfer is what a transfer between stock
 * locations has always been, so it is built by the builder of purchases and only given the source of
 * the permit: the stock moves at what it is worth where it leaves, and a transfer between two branches
 * is two journals that meet in the accounts between branches. A permit worth nothing has movements and
 * no journal, because goods that cost nothing are still goods.
 */
final class StockPermitBuilder
{
    private const SCALE = StockLedger::VALUE_SCALE;

    public function __construct(
        private readonly RoutingResolver $resolver,
        private readonly StockLedger $ledger,
        private readonly ReceivingPostingBuilder $transfers,
    ) {
    }

    /**
     * @throws PostingException
     */
    public function plan(StockPermitDocument $permit, int $revision, ?int $employeeId = null): StockPostingPlan
    {
        return match ($permit->type) {
            StockPermitType::Add      => $this->add($permit, $revision, $employeeId),
            StockPermitType::Issue    => $this->issue($permit, $revision, $employeeId),
            StockPermitType::Transfer => $this->transfer($permit, $revision, $employeeId),
        };
    }

    /**
     * @throws PostingException
     */
    private function add(StockPermitDocument $permit, int $revision, ?int $employeeId): StockPostingPlan
    {
        $movements = [];
        $total     = '0';

        foreach ($permit->lines as $line) {
            $value       = SaleAmounts::round(bcmul($line->quantity, $line->unitCost, 8), self::SCALE);
            $total       = bcadd($total, $value, self::SCALE);
            $movements[] = new StockMovement($line->itemId, $permit->locationId, bcadd($line->quantity, '0', 3), $value);
        }

        $lines = [];

        if (bccomp($total, '0', self::SCALE) > 0) {
            $lines = [
                new JournalLineDraft($this->required(RoutingKey::StockLocations, $permit->locationId), debit: $total),
                new JournalLineDraft($permit->accountId ?? $this->required(RoutingKey::StockPermitAdd), credit: $total),
            ];
        }

        return new StockPostingPlan($this->draft($permit, $lines, StockPermitSources::PERMIT, $revision, $employeeId, 'Stock add permit #'), $movements);
    }

    /**
     * @throws PostingException
     */
    private function issue(StockPermitDocument $permit, int $revision, ?int $employeeId): StockPostingPlan
    {
        $movements = [];
        $total     = '0';

        foreach ($permit->lines as $line) {
            $value       = SaleAmounts::round(bcmul($line->quantity, $this->ledger->unitCost($line->itemId, $permit->locationId, $line->itemCost), 8), self::SCALE);
            $total       = bcadd($total, $value, self::SCALE);
            $movements[] = new StockMovement($line->itemId, $permit->locationId, bcsub('0', $line->quantity, 3), bcsub('0', $value, self::SCALE));
        }

        $lines = [];

        if (bccomp($total, '0', self::SCALE) > 0) {
            $lines = [
                new JournalLineDraft($permit->accountId ?? $this->required(RoutingKey::StockPermitIssue), debit: $total),
                new JournalLineDraft($this->required(RoutingKey::StockLocations, $permit->locationId), credit: $total),
            ];
        }

        return new StockPostingPlan($this->draft($permit, $lines, StockPermitSources::PERMIT, $revision, $employeeId, 'Stock issue permit #'), $movements);
    }

    /**
     * @throws PostingException
     */
    private function transfer(StockPermitDocument $permit, int $revision, ?int $employeeId): StockPostingPlan
    {
        if ($permit->toLocationId === null) {
            throw new PostingException(PostingError::PurchaseNotPostable, 'A transfer permit names the stock location the stock goes to.', ['permit_id' => $permit->permitId]);
        }

        $lines = [];

        foreach ($permit->lines as $line) {
            $lines[] = $this->movement($line, bcsub('0', $line->quantity, 3), $permit->locationId, $permit->branchId, $line->line * 2);
            $lines[] = $this->movement($line, bcadd($line->quantity, '0', 3), $permit->toLocationId, $permit->toBranchId ?? $permit->branchId, $line->line * 2 + 1);
        }

        $plan = $this->transfers->purchase(
            new ReceivingDocument(
                receivingId: $permit->permitId,
                date: $permit->date,
                type: ReceivingType::Requisition,
                supplierId: null,
                branchId: $permit->branchId,
                paymentType: null,
                returnOfReceivingId: null,
                lines: $lines,
                toBranchId: $permit->toBranchId,
                branchCodes: $permit->branchCodes,
            ),
            $revision,
            $employeeId,
        );

        $description = 'Stock transfer permit #' . $permit->label;

        return new StockPostingPlan(
            $plan->journal?->withSource(StockPermitSources::TRANSFER, $permit->permitId, $description . $this->suffix($permit, true)),
            $plan->movements,
            $plan->receipt?->withSource(StockPermitSources::TRANSFER_RECEIPT, $permit->permitId, $description . $this->suffix($permit, false)),
            $plan->receiptMovements,
        );
    }

    private function suffix(StockPermitDocument $permit, bool $sending): string
    {
        if ($permit->toBranchId === null) {
            return '';
        }

        $other = $sending ? $permit->toBranchId : $permit->branchId;

        return ($sending ? ' (to branch ' : ' (from branch ') . ($permit->branchCodes[$other] ?? (string) $other) . ')';
    }

    private function movement(StockPermitLine $line, string $quantity, int $locationId, int $branchId, int $number): ReceivingLine
    {
        return new ReceivingLine(
            itemId: $line->itemId,
            line: $number,
            quantity: $quantity,
            unitsPerPack: '1.000',
            unitPrice: '0',
            discount: '0',
            discountType: 0,
            taxPercent: '0',
            locationId: $locationId,
            hasStock: true,
            itemCost: $line->itemCost,
            branchId: $branchId,
        );
    }

    /**
     * @param list<JournalLineDraft> $lines
     */
    private function draft(StockPermitDocument $permit, array $lines, string $source, int $revision, ?int $employeeId, string $description): ?JournalDraft
    {
        if (count($lines) < 2) {
            return null;
        }

        return new JournalDraft(
            date: $permit->date,
            lines: $lines,
            sourceType: $source,
            sourceId: $permit->permitId,
            revision: $revision,
            origin: JournalOrigin::System,
            employeeId: $employeeId,
            description: $description . $permit->label,
            branchId: $permit->branchId,
            entryType: JournalEntryType::General,
        );
    }

    /**
     * @throws PostingException
     */
    private function required(RoutingKey $key, ?int $entityId = null): int
    {
        $account = $this->resolver->accountFor($key, $entityId);

        if ($account === null) {
            throw new PostingException(PostingError::RoutingRowCancelled, "The routing of {$key->value} is cancelled, but a stock permit cannot be posted without it.", ['routing_key' => $key->value]);
        }

        return $account;
    }
}
