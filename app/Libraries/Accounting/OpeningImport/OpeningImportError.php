<?php

namespace App\Libraries\Accounting\OpeningImport;

/**
 * Why a row of a file of opening balances cannot be posted. A row can have several.
 */
enum OpeningImportError: string
{
    case PartyMissing      = 'party_missing';
    case PartyUnknown      = 'party_unknown';
    case PartyAmbiguous    = 'party_ambiguous';
    case PartyDuplicate    = 'party_duplicate';
    case PartyNoAccount    = 'party_no_account';
    case AmountInvalid     = 'amount_invalid';
    case AmountZero        = 'amount_zero';
    case DateInvalid       = 'date_invalid';
    case DateFuture        = 'date_future';
    case PeriodClosed      = 'period_closed';
    case OutsideFiscalYear = 'outside_fiscal_year';
    case OpeningExists     = 'opening_exists';
    case PostingRefused    = 'posting_refused';

    public function languageKey(): string
    {
        return 'Accounting_opening_import.error_' . $this->value;
    }
}
