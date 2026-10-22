<?php

namespace App\Libraries\Accounting;

enum PostingError: string
{
    case TooFewLines          = 'too_few_lines';
    case InvalidDate          = 'invalid_date';
    case InvalidAmount        = 'invalid_amount';
    case LineOnBothSides      = 'line_on_both_sides';
    case LineWithoutAmount    = 'line_without_amount';
    case Unbalanced           = 'unbalanced';
    case InvalidSource        = 'invalid_source';
    case InvalidCurrency      = 'invalid_currency';
    case InvalidParty         = 'invalid_party';
    case PartyAccountMismatch = 'party_account_mismatch';
    case AccountNotFound      = 'account_not_found';
    case AccountNotPostable   = 'account_not_postable';
    case CostCenterNotFound   = 'cost_center_not_found';
    case BranchNotFound       = 'branch_not_found';
    case BranchInactive       = 'branch_inactive';
    case BranchMismatch       = 'branch_mismatch';
    case OutsideFiscalYear    = 'outside_fiscal_year';
    case PeriodClosed         = 'period_closed';
    case WriteFailed          = 'write_failed';
}
