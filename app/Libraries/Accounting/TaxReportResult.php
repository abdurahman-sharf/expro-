<?php

namespace App\Libraries\Accounting;

use LogicException;

/**
 * The tax of a period from the journals in the books, for the branches of a scope. The tax of every
 * part is signed by what it does to the tax owed, so that the parts add up to the tax payable.
 */
final readonly class TaxReportResult
{
    /**
     * @param list<TaxSectionFigures> $sections           In the order of TaxSection.
     * @param list<TaxAccountFigures> $accounts           One for each account the tax is routed to.
     * @param int                     $legacyDocuments    The documents with tax dated before the books began that fall in the period, which no journal holds.
     * @param int                     $unmatchedDocuments The documents whose lines do not say what their journals say, left out of the base.
     */
    public function __construct(
        public array $sections,
        public array $accounts,
        public ReportScope $scope,
        public ?string $from,
        public string $to,
        public TaxBasis $basis,
        public ?string $startDate,
        public int $legacyDocuments,
        public int $unmatchedDocuments,
        public bool $outputRouted,
        public bool $inputRouted,
    ) {
    }

    public function section(TaxSection $section): TaxSectionFigures
    {
        foreach ($this->sections as $figures) {
            if ($figures->section === $section) {
                return $figures;
            }
        }

        throw new LogicException('The report has every part.');
    }

    /**
     * @return numeric-string The tax of sales, less that of the sales taken back.
     */
    public function output(): string
    {
        return $this->sum(true, 'tax');
    }

    /**
     * @return numeric-string The tax paid on purchases and expenses, less that of the purchases taken back.
     */
    public function input(): string
    {
        return bcsub('0', $this->sum(false, 'tax'), 4);
    }

    /**
     * @return numeric-string What is payable, or refundable when it is below nothing.
     */
    public function net(): string
    {
        return bcsub($this->output(), $this->input(), 4);
    }

    /**
     * @return numeric-string The part of sales that carries tax, less that of the sales taken back.
     */
    public function outputBase(): string
    {
        return $this->sum(true, 'base');
    }

    /**
     * @return numeric-string The part of purchases and expenses that carries tax, less that of the purchases taken back.
     */
    public function inputBase(): string
    {
        return bcsub('0', $this->sum(false, 'base'), 4);
    }

    public function ties(): bool
    {
        foreach ($this->accounts as $account) {
            if (! $account->ties()) {
                return false;
            }
        }

        return $this->outputRouted && $this->inputRouted;
    }

    public function isEmpty(): bool
    {
        if (! $this->outputRouted || ! $this->inputRouted) {
            return false;
        }

        foreach ($this->sections as $figures) {
            if ($figures->documents > 0) {
                return false;
            }
        }

        foreach ($this->accounts as $account) {
            if (bccomp($account->movement, '0', 4) !== 0 || $account->otherJournals > 0) {
                return false;
            }
        }

        return true;
    }

    public function reachesBeforeBooks(): bool
    {
        return $this->startDate !== null && ($this->from === null || $this->from < $this->startDate);
    }

    /**
     * @return numeric-string
     */
    private function sum(bool $output, string $measure): string
    {
        $sum = '0';

        foreach ($this->sections as $figures) {
            if ($figures->section->isOutput() === $output) {
                $sum = bcadd($sum, $measure === 'tax' ? $figures->tax : $figures->base, 4);
            }
        }

        return $sum;
    }
}
