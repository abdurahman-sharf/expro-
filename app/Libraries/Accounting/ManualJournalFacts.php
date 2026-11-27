<?php

namespace App\Libraries\Accounting;

use App\Libraries\Accounting\Engine\PostingFacts;

/**
 * The lines of a manual journal as they were typed, for the posting engine: for each line its account,
 * its cost center, what it comes to (positive on the debit, negative on the credit) less a tax that is
 * in its amount, and the tax it carries with the same sign, which the rules post on a line of its own.
 */
final class ManualJournalFacts
{
    public const OPERATION = 'manual';

    /**
     * @param list<array{account_id: int, cost_center_id: ?int, debit: string, credit: string, analysis?: LineAnalysis}> $lines
     */
    public static function of(array $lines, string $date, int $branchId, string $description, ?int $employeeId): PostingFacts
    {
        $entries = [];

        foreach ($lines as $line) {
            $debited = bccomp((string) $line['debit'], '0', 4) > 0;
            $amount  = $debited ? bcadd((string) $line['debit'], '0', 4) : bcsub('0', (string) $line['credit'], 4);
            $tax     = $line['analysis']->tax ?? null;
            $taxed   = $tax !== null && bccomp($tax['amount'], '0', 4) > 0;
            $signed  = $taxed ? ($debited ? bcadd($tax['amount'], '0', 4) : bcsub('0', $tax['amount'], 4)) : '0';

            $entries[] = [
                'figures' => ['amount' => $amount, 'net' => $taxed && $tax['included'] ? bcsub($amount, $signed, 4) : $amount, 'tax' => $signed],
                'fields'  => ['account_id' => (int) $line['account_id'], 'cost_center_id' => $line['cost_center_id'] ?? null],
                'flags'   => ['on_debit' => $debited, 'on_credit' => ! $debited],
            ];
        }

        return new PostingFacts(
            operation: self::OPERATION,
            sourceId: null,
            date: $date,
            branchId: $branchId,
            employeeId: $employeeId,
            lists: ['lines' => $entries],
            description: $description,
            origin: JournalOrigin::User,
        );
    }
}
