<?php

namespace App\Libraries\Accounting;

/**
 * Turns the journal of a document in a foreign currency, written in the amounts of the document, into the
 * journal the books keep: every line converted to the base currency at the rate of the document and rounded
 * to the decimals of the base, each on its own, with the foreign amount and the currency kept on the line.
 * The rounding of the lines does not add up to a balanced journal by itself, so the difference falls on one
 * line the caller names, the one that balances the journal (the revenue of the goods on an invoice, the
 * largest cost on a purchase), as the cent of an invoice falls on the revenue in the base currency (E14).
 * A difference larger than the few units that rounding can make, or one that would turn the line to nothing,
 * is not rounding and is refused.
 */
final class ForeignConversion
{
    /**
     * @param int $balancing The position of the line that takes the rounding difference.
     *
     * @throws PostingException
     */
    public static function toBase(JournalDraft $draft, DocumentCurrency $currency, int $baseDecimals, int $balancing): JournalDraft
    {
        $converted = [];
        $debit     = '0';
        $credit    = '0';

        foreach ($draft->lines as $index => $line) {
            $isDebit = bccomp($line->debit, '0', SaleAmounts::SCALE) > 0;
            $foreign = $isDebit ? $line->debit : $line->credit;
            $base    = Money::toBase($foreign, $currency->rate, $baseDecimals);

            $converted[$index] = [$isDebit, $foreign, $base];

            if ($isDebit) {
                $debit = bcadd($debit, $base, SaleAmounts::SCALE);
            } else {
                $credit = bcadd($credit, $base, SaleAmounts::SCALE);
            }
        }

        if (! isset($converted[$balancing])) {
            throw new PostingException(PostingError::ForeignRoundingTooBig, 'The line that balances the journal is not one of its lines.', ['line' => $balancing]);
        }

        $difference = bcsub($debit, $credit, SaleAmounts::SCALE);
        $unit       = $baseDecimals === 0 ? '1' : '0.' . str_repeat('0', $baseDecimals - 1) . '1';
        $allowed    = bcmul($unit, (string) (intdiv(count($converted), 2) + 1), SaleAmounts::SCALE);

        if (bccomp(SaleAmounts::absolute($difference), $allowed, SaleAmounts::SCALE) > 0) {
            throw new PostingException(PostingError::ForeignRoundingTooBig, "Converting the journal to the base currency leaves {$difference}, which is more than rounding.", ['difference' => $difference]);
        }

        [$isDebit, $foreign, $base] = $converted[$balancing];

        $balanced = $isDebit ? bcsub($base, $difference, SaleAmounts::SCALE) : bcadd($base, $difference, SaleAmounts::SCALE);

        if (bccomp($balanced, '0', SaleAmounts::SCALE) <= 0) {
            throw new PostingException(PostingError::ForeignRoundingTooBig, 'The line that balances the journal would come to nothing.', ['line' => $balancing]);
        }

        $converted[$balancing][2] = $balanced;

        $lines = [];

        foreach ($draft->lines as $index => $line) {
            [$isDebit, $foreign, $base] = $converted[$index];

            $lines[] = new JournalLineDraft(
                accountId: $line->accountId,
                debit: $isDebit ? bcadd($base, '0', SaleAmounts::SCALE) : '0',
                credit: $isDebit ? '0' : bcadd($base, '0', SaleAmounts::SCALE),
                description: $line->description,
                costCenterId: $line->costCenterId,
                partyType: $line->partyType,
                partyId: $line->partyId,
                foreignAmount: bcadd($foreign, '0', SaleAmounts::SCALE),
                currencyCode: $currency->code,
            );
        }

        return $draft->withLinesIn($lines, $currency->code, $currency->rate);
    }

    /**
     * The position of the line of an account in a draft, which is the line that balances a journal when
     * the caller knows it by its account.
     *
     * @throws PostingException
     */
    public static function indexOf(JournalDraft $draft, int $accountId): int
    {
        foreach ($draft->lines as $index => $line) {
            if ($line->accountId === $accountId) {
                return $index;
            }
        }

        throw new PostingException(PostingError::ForeignRoundingTooBig, 'The line that balances the journal is not one of its lines.', ['account_id' => $accountId]);
    }
}
