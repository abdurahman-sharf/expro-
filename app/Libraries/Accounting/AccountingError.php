<?php

namespace App\Libraries\Accounting;

enum AccountingError: string
{
    case PermissionDenied    = 'permission_denied';
    case WriteFailed         = 'write_failed';
    case InvalidDate         = 'invalid_date';
    case BaseCurrencyMissing = 'base_currency_missing';

    case AccountNotFound     = 'account_not_found';
    case InvalidCode         = 'invalid_code';
    case CodeTaken           = 'code_taken';
    case InvalidName         = 'invalid_name';
    case NameTaken           = 'name_taken';
    case ParentInvalid       = 'parent_invalid';
    case ParentInactive      = 'parent_inactive';
    case SystemAccountLocked = 'system_account_locked';
    case AccountInUse        = 'account_in_use';
    case AccountRouted       = 'account_routed';
    case HasChildren         = 'has_children';
    case HasActiveChildren   = 'has_active_children';
    case AlreadyInactive     = 'already_inactive';
    case AlreadyActive       = 'already_active';

    case CostCenterNotFound = 'cost_center_not_found';
    case CostCenterCycle    = 'cost_center_cycle';
    case CostCenterInUse    = 'cost_center_in_use';

    case JournalNotFound       = 'journal_not_found';
    case NotADraft             = 'not_a_draft';
    case NotPosted             = 'not_posted';
    case AlreadyReversed       = 'already_reversed';
    case CannotReverseReversal = 'cannot_reverse_reversal';
    case ReasonRequired        = 'reason_required';
    case NoLines               = 'no_lines';
    case TooManyLines          = 'too_many_lines';
    case LineAccountRequired   = 'line_account_required';
    case LineAccountInvalid    = 'line_account_invalid';
    case LineAmountInvalid     = 'line_amount_invalid';
    case LineOnBothSides       = 'line_on_both_sides';
    case LineCostCenterInvalid = 'line_cost_center_invalid';

    case PostedJournalLocked     = 'posted_journal_locked';
    case JournalNotPosted        = 'journal_not_posted';
    case JournalLineNotFound     = 'journal_line_not_found';
    case DetailsChanged          = 'details_changed';
    case DescriptionTooLong      = 'description_too_long';
    case LineDescriptionRequired = 'line_description_required';

    case AttachmentNotFound       = 'attachment_not_found';
    case AttachmentUnavailable    = 'attachment_unavailable';
    case AttachmentTypeNotAllowed = 'attachment_type_not_allowed';
    case AttachmentTooLarge       = 'attachment_too_large';
    case AttachmentEmpty          = 'attachment_empty';
    case AttachmentUploadFailed   = 'attachment_upload_failed';
    case AttachmentLimitReached   = 'attachment_limit_reached';

    case FiscalYearNotFound    = 'fiscal_year_not_found';
    case FiscalYearInvalidName = 'fiscal_year_invalid_name';
    case FiscalYearNameTaken   = 'fiscal_year_name_taken';
    case FiscalYearRange       = 'fiscal_year_range';
    case FiscalYearTooLong     = 'fiscal_year_too_long';
    case FiscalYearOverlap     = 'fiscal_year_overlap';
    case FiscalYearGap         = 'fiscal_year_gap';
    case FiscalYearBoundary    = 'fiscal_year_boundary';
    case FiscalYearHasJournals = 'fiscal_year_has_journals';
    case FiscalYearClosed      = 'fiscal_year_closed';

    case ClosingInFuture          = 'closing_in_future';
    case ClosingBeforeStart       = 'closing_before_start';
    case ClosingOutsideFiscalYear = 'closing_outside_fiscal_year';
    case ClosingNotAfterPrevious  = 'closing_not_after_previous';
    case ClosingBlockedByDrafts   = 'closing_blocked_by_drafts';
    case NothingToReopen          = 'nothing_to_reopen';
    case ClosingChanged           = 'closing_changed';

    case InvalidCurrency            = 'invalid_currency';
    case SettingsLocked             = 'settings_locked';
    case StartDateOutsideFiscalYear = 'start_date_outside_fiscal_year';

    case OpeningStartDateMissing  = 'opening_start_date_missing';
    case OpeningAccountNotAllowed = 'opening_account_not_allowed';
    case OpeningUnbalanced        = 'opening_unbalanced';
    case OpeningAlreadyPosted     = 'opening_already_posted';
    case OpeningNotPosted         = 'opening_not_posted';

    case OpeningStockAccountingOff    = 'opening_stock_accounting_off';
    case OpeningStockAlreadyPosted    = 'opening_stock_already_posted';
    case OpeningStockLocationNotFound = 'opening_stock_location_not_found';
    case OpeningStockNotPosted        = 'opening_stock_not_posted';

    case RoutingInvalid            = 'routing_invalid';
    case RoutingKeyUnknown         = 'routing_key_unknown';
    case RoutingAccountRequired    = 'routing_account_required';
    case RoutingAccountNotPostable = 'routing_account_not_postable';
    case RoutingAccountNotGroup    = 'routing_account_not_group';
    case RoutingAccountInactive    = 'routing_account_inactive';
    case RoutingAccountTypeInvalid = 'routing_account_type_invalid';
    case RoutingAccountReserved    = 'routing_account_reserved';
    case RoutingTypeInvalid        = 'routing_type_invalid';

    case EntityKindUnknown        = 'entity_kind_unknown';
    case EntityMainAccountInvalid = 'entity_main_account_invalid';

    case SaleLocked           = 'sale_locked';
    case SalePaymentNotOnSale = 'sale_payment_not_on_sale';
    case IssuedDocumentLocked = 'issued_document_locked';
    case CustomerBusy         = 'customer_busy';

    case PurchaseLocked     = 'purchase_locked';
    case PurchaseHasReturns = 'purchase_has_returns';

    case SupplierBusy                 = 'supplier_busy';
    case SupplierPaymentNeedsSupplier = 'supplier_payment_needs_supplier';
    case SupplierPaymentNotOnInvoice  = 'supplier_payment_not_on_invoice';

    case AdvanceNotFound         = 'advance_not_found';
    case AdvanceAmountInvalid    = 'advance_amount_invalid';
    case AdvanceAlreadyCancelled = 'advance_already_cancelled';
    case AdvanceKindInvalid      = 'advance_kind_invalid';
    case AdvanceSupplierMissing  = 'advance_supplier_missing';
    case AdvancePaymentUnknown   = 'advance_payment_unknown';
    case OpeningNotFound         = 'opening_not_found';

    case TreasuryNotFound             = 'treasury_not_found';
    case TreasuryInvalidName          = 'treasury_invalid_name';
    case TreasuryInvalidKind          = 'treasury_invalid_kind';
    case TreasuryNameTaken            = 'treasury_name_taken';
    case TreasuryBankNameInvalid      = 'treasury_bank_name_invalid';
    case TreasuryAccountNumberInvalid = 'treasury_account_number_invalid';
    case TreasuryBankAccountTaken     = 'treasury_bank_account_taken';
    case TreasuryIsMain               = 'treasury_is_main';
    case TreasuryHasBalance           = 'treasury_has_balance';
    case TreasuryInUse                = 'treasury_in_use';
    case TreasuryInactive             = 'treasury_inactive';
    case TreasuryDepositDenied        = 'treasury_deposit_denied';
    case TreasuryWithdrawDenied       = 'treasury_withdraw_denied';
    case TreasuryScopeInvalid         = 'treasury_scope_invalid';

    case TransferNotFound           = 'transfer_not_found';
    case TransferSameTreasury       = 'transfer_same_treasury';
    case TransferAmountInvalid      = 'transfer_amount_invalid';
    case InterBranchAccountsMissing = 'inter_branch_accounts_missing';
    case TransferJournalLocked      = 'transfer_journal_locked';
    case TransferAlreadyCancelled   = 'transfer_already_cancelled';

    case ReportPeriodInvalid  = 'report_period_invalid';
    case ReportAccountMissing = 'report_account_missing';
}
