<?php

namespace App\Libraries\Stock;

use App\Libraries\Accounting\AccountType;
use App\Libraries\Numbering\DocumentType;

/**
 * What a stock permit does: an add permit brings units into a stock location (goods received from a
 * supplier, or an adjustment up), an issue permit takes them out (an adjustment down, or goods given
 * out), and a transfer permit moves them from one stock location to another.
 */
enum StockPermitType: string
{
    case Add      = 'add';
    case Issue    = 'issue';
    case Transfer = 'transfer';

    public function documentType(): DocumentType
    {
        return match ($this) {
            self::Add      => DocumentType::StockPermitAdd,
            self::Issue    => DocumentType::StockPermitIssue,
            self::Transfer => DocumentType::StockPermitTransfer,
        };
    }

    public function labelKey(): string
    {
        return 'Stock_documents.type_' . $this->value;
    }

    /**
     * The kinds of account a permit may be set against instead of its automatic account: the credit
     * side of an add permit and the debit side of an issue permit.
     *
     * @return list<AccountType>
     */
    public function accountTypes(): array
    {
        return match ($this) {
            self::Add      => [AccountType::Liability, AccountType::Equity, AccountType::Revenue, AccountType::Expense],
            self::Issue    => [AccountType::Asset, AccountType::Equity, AccountType::Expense],
            self::Transfer => [],
        };
    }
}
