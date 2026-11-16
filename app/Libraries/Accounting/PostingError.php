<?php

namespace App\Libraries\Accounting;

enum PostingError: string
{
    case TooFewLines                   = 'too_few_lines';
    case InvalidDate                   = 'invalid_date';
    case InvalidAmount                 = 'invalid_amount';
    case LineOnBothSides               = 'line_on_both_sides';
    case LineWithoutAmount             = 'line_without_amount';
    case Unbalanced                    = 'unbalanced';
    case InvalidSource                 = 'invalid_source';
    case InvalidCurrency               = 'invalid_currency';
    case InvalidParty                  = 'invalid_party';
    case PartyAccountMismatch          = 'party_account_mismatch';
    case RoutingTypeUnsupported        = 'routing_type_unsupported';
    case RoutingAccountUnusable        = 'routing_account_unusable';
    case EntityAccountMissing          = 'entity_account_missing';
    case AccountNotFound               = 'account_not_found';
    case AccountNotPostable            = 'account_not_postable';
    case CostCenterNotFound            = 'cost_center_not_found';
    case BranchNotFound                = 'branch_not_found';
    case BranchInactive                = 'branch_inactive';
    case BranchMismatch                = 'branch_mismatch';
    case OutsideFiscalYear             = 'outside_fiscal_year';
    case PeriodClosed                  = 'period_closed';
    case WriteFailed                   = 'write_failed';
    case PaymentTypeUnknown            = 'payment_type_unknown';
    case CreditNeedsCustomer           = 'credit_needs_customer';
    case ReturnOriginRequired          = 'return_origin_required';
    case ReturnOriginInvalid           = 'return_origin_invalid';
    case ReturnLineNotOnOrigin         = 'return_line_not_on_origin';
    case ReturnOfUndelivered           = 'return_of_undelivered';
    case RoutingRowCancelled           = 'routing_row_cancelled';
    case SaleNotPostable               = 'sale_not_postable';
    case DiscountNeedsAdditiveTax      = 'discount_needs_additive_tax';
    case TaxAccountsExceedTax          = 'tax_accounts_exceed_tax';
    case PurchaseNotPostable           = 'purchase_not_postable';
    case PurchaseAcrossBranches        = 'purchase_across_branches';
    case TransferBranchesInvalid       = 'transfer_branches_invalid';
    case InterBranchAccountsMissing    = 'inter_branch_accounts_missing';
    case SupplierRequired              = 'supplier_required';
    case PurchaseReturnOriginRequired  = 'purchase_return_origin_required';
    case PurchaseReturnOriginInvalid   = 'purchase_return_origin_invalid';
    case PurchaseReturnLineNotOnOrigin = 'purchase_return_line_not_on_origin';
    case PurchaseReturnExceedsOrigin   = 'purchase_return_exceeds_origin';
    case PurchaseDiscountInvalid       = 'purchase_discount_invalid';
    case SaleReturnExceedsOrigin       = 'sale_return_exceeds_origin';
    case ExpenseTaxInvalid             = 'expense_tax_invalid';
    case CrossBranchPaymentInvalid     = 'cross_branch_payment_invalid';
    case RevenueTaxInvalid             = 'revenue_tax_invalid';
    case PreviewMissing                = 'preview_missing';
    case PreviewHoldsSource            = 'preview_holds_source';
    case ItemAccountUnusable           = 'item_account_unusable';

    case ForeignAmountMissing  = 'foreign_amount_missing';
    case ForeignUnbalanced     = 'foreign_unbalanced';
    case ForeignRateMismatch   = 'foreign_rate_mismatch';
    case ForeignRoundingTooBig = 'foreign_rounding_too_big';
    case FxAccountsMissing     = 'fx_accounts_missing';
    case FxNotSupported        = 'fx_not_supported';
    case TreasuryCurrency      = 'treasury_currency';
    case OperationsHeld        = 'operations_held';
}
