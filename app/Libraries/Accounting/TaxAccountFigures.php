<?php

namespace App\Libraries\Accounting;

/**
 * What the books hold on one account of tax in the period and the branches of a report, set beside
 * what the report counts of it. The movement is credit minus debit, so that what is owed is
 * positive on both the account of tax collected and the account of tax paid.
 */
final readonly class TaxAccountFigures
{
    /**
     * @param list<string>   $roles    What the account is routed as: 'output' or 'input', or both.
     * @param numeric-string $movement What every journal in the books did to the account.
     * @param numeric-string $reported What the documents of the report did to it.
     */
    public function __construct(
        public int $accountId,
        public string $code,
        public string $name,
        public array $roles,
        public string $movement,
        public string $reported,
        public int $otherJournals,
    ) {
    }

    /**
     * @return numeric-string What reached the account from journals that are not sales, purchases or expenses, such as a manual journal, an opening balance or a settlement of the tax.
     */
    public function other(): string
    {
        return bcsub($this->movement, $this->reported, 4);
    }

    public function ties(): bool
    {
        return bccomp($this->other(), '0', 4) === 0 && $this->otherJournals === 0;
    }
}
