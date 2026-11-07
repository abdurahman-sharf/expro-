<?php

namespace App\Libraries\Accounting\FamilyReports;

use App\Libraries\Accounting\CashupPostingBuilder;
use App\Libraries\Accounting\ChequeSources;
use App\Libraries\Accounting\CreditRefunds;
use App\Libraries\Accounting\CrossBranchPayments;
use App\Libraries\Accounting\CustomerAdvances;
use App\Libraries\Accounting\CustomerOpening;
use App\Libraries\Accounting\ExpensePostingBuilder;
use App\Libraries\Accounting\InterBranchSettlements;
use App\Libraries\Accounting\JournalDraft;
use App\Libraries\Accounting\OpeningBalanceService;
use App\Libraries\Accounting\OpeningStock;
use App\Libraries\Accounting\ReceivingPostingBuilder;
use App\Libraries\Accounting\RevenuePostingBuilder;
use App\Libraries\Accounting\SalePostingBuilder;
use App\Libraries\Accounting\SalesDebitNotes;
use App\Libraries\Accounting\StockAdjustment;
use App\Libraries\Accounting\SupplierAdvances;
use App\Libraries\Accounting\TreasuryTransfers;
use App\Libraries\Accounting\YearEndEntries;

/**
 * The sources a journal can have in these books, for the filter of the reports of journals. The
 * reference system offers thirty-three; the ones it has for assets, payroll and manufacturing have no
 * counterpart here, and the sources here that it has no name for (the cash refunds, the settlements
 * between branches, the opening balances, the closing of a year and the journal a payment gets in the
 * branch of a treasury of another branch) are listed under their own names.
 */
final class JournalSources
{
    /**
     * @return list<string>
     */
    public static function all(): array
    {
        return [
            JournalDraft::SOURCE_MANUAL,
            SalePostingBuilder::SOURCE_SALE,
            SalePostingBuilder::SOURCE_PAYMENT,
            ReceivingPostingBuilder::SOURCE_PURCHASE,
            ReceivingPostingBuilder::SOURCE_PAYMENT,
            ReceivingPostingBuilder::SOURCE_SUPPLIER_PAYMENT,
            SupplierAdvances::SOURCE,
            CreditRefunds::SUPPLIER_SOURCE,
            CustomerAdvances::SOURCE,
            CreditRefunds::CUSTOMER_SOURCE,
            CustomerOpening::SOURCE,
            SalesDebitNotes::SOURCE,
            ExpensePostingBuilder::SOURCE,
            RevenuePostingBuilder::SOURCE,
            CashupPostingBuilder::SOURCE,
            StockAdjustment::SOURCE,
            OpeningStock::SOURCE,
            ReceivingPostingBuilder::SOURCE_STOCK_TRANSFER,
            ReceivingPostingBuilder::SOURCE_TRANSFER_RECEIPT,
            TreasuryTransfers::SOURCE,
            TreasuryTransfers::SOURCE_RECEIPT,
            InterBranchSettlements::SOURCE,
            InterBranchSettlements::SOURCE_SECOND,
            ...ChequeSources::ALL,
            OpeningBalanceService::SOURCE_TYPE,
            YearEndEntries::SOURCE_TYPE,
            JournalDraft::SOURCE_REVERSAL,
            ...CrossBranchPayments::companionsOf(SalePostingBuilder::SOURCE_PAYMENT),
            ...CrossBranchPayments::companionsOf(ReceivingPostingBuilder::SOURCE_SUPPLIER_PAYMENT),
            ...CrossBranchPayments::companionsOf(ExpensePostingBuilder::SOURCE),
        ];
    }

    public static function label(string $source): string
    {
        $key  = 'Accounting.source_' . $source;
        $line = lang($key);

        return $line === $key ? $source : $line;
    }
}
