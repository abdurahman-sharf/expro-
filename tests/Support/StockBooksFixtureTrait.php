<?php

namespace Tests\Support;

use App\Libraries\Accounting\JournalDraft;
use App\Libraries\Accounting\JournalEntryType;
use App\Libraries\Accounting\JournalLineDraft;
use App\Libraries\Accounting\PostingService;
use App\Libraries\Accounting\StockLedger;
use App\Libraries\Accounting\StockMovement;

/**
 * What a test of the stock the books hold needs to put it there by day, branch and location,
 * without a document in the way. A class that uses it also uses PurchaseAccountingFixtureTrait.
 */
trait StockBooksFixtureTrait
{
    private int $bookedSource = 880000;

    /**
     * Puts stock in the books as a purchase does: a journal that debits the account of the
     * location, and the movement that goes with it, on the day and in the branch given.
     */
    protected function bookStock(string $date, int $branchId, int $itemId, string $quantity, string $value, int $locationId = self::LOCATION_ID): int
    {
        $source = ++$this->bookedSource;
        $debit  = bccomp($value, '0', 4) >= 0;
        $amount = ltrim($value, '-');
        $stock  = new JournalLineDraft($this->stockAccountOf($locationId), debit: $debit ? $amount : '0', credit: $debit ? '0' : $amount);
        $owed   = new JournalLineDraft($this->supplierAccount(), debit: $debit ? '0' : $amount, credit: $debit ? $amount : '0');

        $result = (new PostingService())->post(new JournalDraft(
            date: $date,
            lines: [$stock, $owed],
            sourceType: 'purchase',
            sourceId: $source,
            currencyCode: 'SAR',
            branchId: $branchId,
        ));

        (new StockLedger())->record('purchase', $source, JournalEntryType::General, 1, $date, $branchId, [new StockMovement($itemId, $locationId, $quantity, $value)], $result->journalId);

        return $result->journalId;
    }

    /**
     * A journal that no movement goes with, which is what a manual entry to the account of a
     * location is.
     */
    protected function manualToStock(string $amount, int $branchId = 1, int $locationId = self::LOCATION_ID, string $date = '2026-10-06'): void
    {
        (new PostingService())->post(new JournalDraft(
            date: $date,
            lines: [new JournalLineDraft($this->stockAccountOf($locationId), debit: $amount), new JournalLineDraft($this->accountId('opening_balance'), credit: $amount)],
            sourceType: JournalDraft::SOURCE_MANUAL,
            currencyCode: 'SAR',
            branchId: $branchId,
        ));
    }
}
