<?php

namespace App\Libraries\Accounting;

enum PostingError: string
{
    case TooFewLines              = 'too_few_lines';
    case InvalidDate              = 'invalid_date';
    case InvalidAmount            = 'invalid_amount';
    case LineOnBothSides          = 'line_on_both_sides';
    case LineWithoutAmount        = 'line_without_amount';
    case Unbalanced               = 'unbalanced';
    case InvalidSource            = 'invalid_source';
    case InvalidCurrency          = 'invalid_currency';
    case InvalidParty             = 'invalid_party';
    case PartyAccountMismatch     = 'party_account_mismatch';
    case RoutingTypeUnsupported   = 'routing_type_unsupported';
    case RoutingAccountUnusable   = 'routing_account_unusable';
    case EntityAccountMissing     = 'entity_account_missing';
    case AccountNotFound          = 'account_not_found';
    case AccountNotPostable       = 'account_not_postable';
    case CostCenterNotFound       = 'cost_center_not_found';
    case BranchNotFound           = 'branch_not_found';
    case BranchInactive           = 'branch_inactive';
    case BranchMismatch           = 'branch_mismatch';
    case OutsideFiscalYear        = 'outside_fiscal_year';
    case PeriodClosed             = 'period_closed';
    case WriteFailed              = 'write_failed';
    case PaymentTypeUnknown       = 'payment_type_unknown';
    case CreditNeedsCustomer      = 'credit_needs_customer';
    case ReturnOriginRequired     = 'return_origin_required';
    case ReturnOriginInvalid      = 'return_origin_invalid';
    case ReturnLineNotOnOrigin    = 'return_line_not_on_origin';
    case ReturnOfUndelivered      = 'return_of_undelivered';
    case RoutingRowCancelled      = 'routing_row_cancelled';
    case SaleNotPostable          = 'sale_not_postable';
    case DiscountNeedsAdditiveTax = 'discount_needs_additive_tax';
}
