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

    case OpeningStockAccountHoldsValue = 'opening_stock_account_holds_value';
    case OpeningStockLaterMovements    = 'opening_stock_later_movements';
    case OpeningStockNothingToReprice  = 'opening_stock_nothing_to_reprice';
    case OpeningStockPlanChanged       = 'opening_stock_plan_changed';

    case CustomerOpeningCustomerMissing = 'customer_opening_customer_missing';
    case CustomerOpeningExists          = 'customer_opening_exists';
    case CustomerOpeningNotFound        = 'customer_opening_not_found';
    case CustomerOpeningOff             = 'customer_opening_off';
    case CustomerOpeningReversed        = 'customer_opening_reversed';
    case CustomerOpeningSideInvalid     = 'customer_opening_side_invalid';

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

    case NumberingTypeUnknown    = 'numbering_type_unknown';
    case NumberingPrefixInvalid  = 'numbering_prefix_invalid';
    case NumberingPaddingInvalid = 'numbering_padding_invalid';
    case NumberingNextInvalid    = 'numbering_next_invalid';
    case NumberingNextTooLow     = 'numbering_next_too_low';
    case NumberingSourceInvalid  = 'numbering_source_invalid';
    case NumberingExhausted      = 'numbering_exhausted';

    case SaleLocked           = 'sale_locked';
    case SalePaymentNotOnSale = 'sale_payment_not_on_sale';
    case IssuedDocumentLocked = 'issued_document_locked';
    case CustomerBusy         = 'customer_busy';

    case DraftChanged          = 'draft_changed';
    case DraftNotFound         = 'draft_not_found';
    case DraftStoredValuePaid  = 'draft_stored_value_paid';
    case QuoteLocked           = 'quote_locked';
    case QuoteNotConvertible   = 'quote_not_convertible';
    case QuoteNotFound         = 'quote_not_found';
    case QuoteStatusNotAllowed = 'quote_status_not_allowed';

    case InvoiceDateInvalid     = 'invoice_date_invalid';
    case InvoiceDateBeforeStart = 'invoice_date_before_start';
    case PaymentTermsInvalid    = 'payment_terms_invalid';

    case PurchaseLocked               = 'purchase_locked';
    case PurchaseHasReturns           = 'purchase_has_returns';
    case PurchaseEditEmpty            = 'purchase_edit_empty';
    case PurchaseEditHasReturns       = 'purchase_edit_has_returns';
    case PurchaseEditLineInvalid      = 'purchase_edit_line_invalid';
    case PurchaseEditNotAReceipt      = 'purchase_edit_not_a_receipt';
    case PurchaseStockWouldGoNegative = 'purchase_stock_would_go_negative';

    case SupplierBusy                 = 'supplier_busy';
    case SupplierPaymentNeedsSupplier = 'supplier_payment_needs_supplier';
    case SupplierPaymentNotOnInvoice  = 'supplier_payment_not_on_invoice';
    case PaymentDateBeforeBooks       = 'payment_date_before_books';
    case PaymentDateInFuture          = 'payment_date_in_future';

    case AdvanceNotFound         = 'advance_not_found';
    case AdvanceAmountInvalid    = 'advance_amount_invalid';
    case AdvanceAlreadyCancelled = 'advance_already_cancelled';
    case AdvanceKindInvalid      = 'advance_kind_invalid';
    case AdvanceSupplierMissing  = 'advance_supplier_missing';
    case AdvancePaymentUnknown   = 'advance_payment_unknown';
    case OpeningNotFound         = 'opening_not_found';

    case AdvanceCustomerMissing = 'advance_customer_missing';
    case CreditJournalLocked    = 'credit_journal_locked';
    case CreditPaymentUnknown   = 'credit_payment_unknown';
    case CreditRefunded         = 'credit_refunded';
    case RefundNotFound         = 'refund_not_found';
    case RefundAlreadyCancelled = 'refund_already_cancelled';
    case RefundExceedsCredit    = 'refund_exceeds_credit';
    case RefundPartyMissing     = 'refund_party_missing';

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
    case LedgerTooLarge       = 'ledger_too_large';
    case ReportTooManyPeriods = 'report_too_many_periods';

    case ProfitBasisUnavailable = 'profit_basis_unavailable';

    case CashFlowLineInvalid    = 'cash_flow_line_invalid';
    case CashFlowAccountInvalid = 'cash_flow_account_invalid';
    case CashFlowLinesChanged   = 'cash_flow_lines_changed';
    case CashFlowSetupTooLarge  = 'cash_flow_setup_too_large';

    case TaxBasisUnavailable = 'tax_basis_unavailable';

    case ExpenseCategoryAccountRequired = 'expense_category_account_required';
    case ExpenseCategoryAccountInvalid  = 'expense_category_account_invalid';
    case DocumentJournalLocked          = 'document_journal_locked';

    case SettlementNotFound         = 'settlement_not_found';
    case SettlementAlreadyCancelled = 'settlement_already_cancelled';
    case SettlementNothingToSettle  = 'settlement_nothing_to_settle';
    case SettlementOlderThanSettled = 'settlement_older_than_settled';
    case SettlementPairNotAgreed    = 'settlement_pair_not_agreed';
    case SettlementSameBranch       = 'settlement_same_branch';
    case SettlementTreasuryRequired = 'settlement_treasury_required';
    case InterBranchBranchesDenied  = 'interbranch_branches_denied';

    case YearEndYearNotFound          = 'year_end_year_not_found';
    case YearEndPeriodNotClosed       = 'year_end_period_not_closed';
    case YearEndHasDrafts             = 'year_end_has_drafts';
    case YearEndAlreadyClosed         = 'year_end_already_closed';
    case YearEndAmountTooLarge        = 'year_end_amount_too_large';
    case YearEndNothingToClose        = 'year_end_nothing_to_close';
    case YearEndPreviewChanged        = 'year_end_preview_changed';
    case YearEndNotFound              = 'year_end_not_found';
    case YearEndAlreadyReversed       = 'year_end_already_reversed';
    case YearEndClosedOut             = 'year_end_closed_out';
    case YearEndJournalLocked         = 'year_end_journal_locked';
    case YearEndRetainedEarningsUnset = 'year_end_retained_earnings_unset';

    case ChequeNotFound         = 'cheque_not_found';
    case ChequeStatusInvalid    = 'cheque_status_invalid';
    case ChequeLocked           = 'cheque_locked';
    case ChequeDeleteLocked     = 'cheque_delete_locked';
    case ChequeAmountInvalid    = 'cheque_amount_invalid';
    case ChequeDateInvalid      = 'cheque_date_invalid';
    case ChequeCollectBeforeDue = 'cheque_collect_before_due';
    case ChequeFeesRequired     = 'cheque_fees_required';
    case ChequeFeesInvalid      = 'cheque_fees_invalid';
    case ChequeNumberRequired   = 'cheque_number_required';
    case ChequeNumberTaken      = 'cheque_number_taken';
    case ChequePartyMissing     = 'cheque_party_missing';
    case ChequeTreasuryInvalid  = 'cheque_treasury_invalid';
    case ChequeBookRequired     = 'cheque_book_required';
    case ChequeBookNotFound     = 'cheque_book_not_found';
    case ChequeBookInactive     = 'cheque_book_inactive';
    case ChequeBookInvalid      = 'cheque_book_invalid';
    case ChequeBookInUse        = 'cheque_book_in_use';
    case ChequeNumberOutOfBook  = 'cheque_number_out_of_book';
    case ChequeNothingToUndo    = 'cheque_nothing_to_undo';

    case RevenueNotFound            = 'revenue_not_found';
    case RevenueAmountInvalid       = 'revenue_amount_invalid';
    case RevenuePaymentInvalid      = 'revenue_payment_invalid';
    case RevenueAccountInvalid      = 'revenue_account_invalid';
    case RevenueAccountRequired     = 'revenue_account_required';
    case RevenueCategoryNotFound    = 'revenue_category_not_found';
    case RevenueCategoryNameInvalid = 'revenue_category_name_invalid';
    case RevenueCategoryNameTaken   = 'revenue_category_name_taken';

    case BackfillNotFound         = 'backfill_not_found';
    case BackfillNameInvalid      = 'backfill_name_invalid';
    case BackfillRangeInvalid     = 'backfill_range_invalid';
    case BackfillFamiliesInvalid  = 'backfill_families_invalid';
    case BackfillChunkInvalid     = 'backfill_chunk_invalid';
    case BackfillOverlaps         = 'backfill_overlaps';
    case BackfillBusy             = 'backfill_busy';
    case BackfillDecided          = 'backfill_decided';
    case BackfillNotReady         = 'backfill_not_ready';
    case BackfillApproving        = 'backfill_approving';
    case BackfillDisabled         = 'backfill_disabled';
    case BackfillAccountingOff    = 'backfill_accounting_off';
    case BackfillStartDateMissing = 'backfill_start_date_missing';
    case BackfillOpeningMissing   = 'backfill_opening_missing';
    case BackfillPreviewChanged   = 'backfill_preview_changed';
    case BackfillNothingToApprove = 'backfill_nothing_to_approve';

    case AccountTaxStamped          = 'account_tax_stamped';
    case TaxDefinitionNotFound      = 'tax_definition_not_found';
    case TaxLiabilityAccountInvalid = 'tax_liability_account_invalid';
    case TaxModeInvalid             = 'tax_mode_invalid';
    case TaxNameInvalid             = 'tax_name_invalid';
    case TaxNameTaken               = 'tax_name_taken';
    case TaxOneRequired             = 'tax_one_required';

    case HeaderChargesOff        = 'header_charges_off';
    case HeaderChargesInvalid    = 'header_charges_invalid';
    case HeaderChargesNotAllowed = 'header_charges_not_allowed';
    case AdjustmentExceedsTotal  = 'adjustment_exceeds_total';

    case DebitNoteOff              = 'debit_note_off';
    case DebitNoteNotFound         = 'debit_note_not_found';
    case DebitNoteReversed         = 'debit_note_reversed';
    case DebitNoteAmountInvalid    = 'debit_note_amount_invalid';
    case DebitNoteTaxInvalid       = 'debit_note_tax_invalid';
    case DebitNoteCustomerMissing  = 'debit_note_customer_missing';
    case DebitNoteAccountInvalid   = 'debit_note_account_invalid';
    case DebitNoteNoteTooLong      = 'debit_note_note_too_long';
    case JournalTemplateInvalid    = 'journal_template_invalid';
    case ShippingTaxPercentInvalid = 'shipping_tax_percent_invalid';

    case PriceBelowMinimum     = 'price_below_minimum';
    case DiscountAboveCeiling  = 'discount_above_ceiling';
    case CreditLimitExceeded   = 'credit_limit_exceeded';
    case CreditPeriodExceeded  = 'credit_period_exceeded';
    case PriceListNameInvalid  = 'price_list_name_invalid';
    case PriceListNameTaken    = 'price_list_name_taken';
    case PriceListNotFound     = 'price_list_not_found';
    case PriceListInUse        = 'price_list_in_use';
    case PriceListInactive     = 'price_list_inactive';
    case PriceInvalid          = 'price_invalid';
    case ControlSettingInvalid = 'control_setting_invalid';
    case CreditTermsInvalid    = 'credit_terms_invalid';

    case BankAccountNotFound              = 'bank_account_not_found';
    case BankStatementEmpty               = 'bank_statement_empty';
    case BankStatementTooLarge            = 'bank_statement_too_large';
    case BankStatementEncodingInvalid     = 'bank_statement_encoding_invalid';
    case BankStatementTooManyRows         = 'bank_statement_too_many_rows';
    case BankStatementMappingInvalid      = 'bank_statement_mapping_invalid';
    case BankStatementRowsInvalid         = 'bank_statement_rows_invalid';
    case BankStatementClosedRange         = 'bank_statement_closed_range';
    case BankStatementNotFound            = 'bank_statement_not_found';
    case BankStatementHasMatches          = 'bank_statement_has_matches';
    case BankLineNotFound                 = 'bank_line_not_found';
    case BankLineMatched                  = 'bank_line_matched';
    case BankJournalLinesRequired         = 'bank_journal_lines_required';
    case BankJournalLineInvalid           = 'bank_journal_line_invalid';
    case BankJournalLineMatched           = 'bank_journal_line_matched';
    case BankMatchAmountMismatch          = 'bank_match_amount_mismatch';
    case BankMatchDirectionMismatch       = 'bank_match_direction_mismatch';
    case BankMatchNotFound                = 'bank_match_not_found';
    case BankMatchLocked                  = 'bank_match_locked';
    case BankMatchClosedRange             = 'bank_match_closed_range';
    case BankMatchWindowInvalid           = 'bank_match_window_invalid';
    case BankReconciliationNotFound       = 'bank_reconciliation_not_found';
    case BankReconciliationDifference     = 'bank_reconciliation_difference';
    case BankReconciliationDateInvalid    = 'bank_reconciliation_date_invalid';
    case BankReconciliationDateOutside    = 'bank_reconciliation_date_outside';
    case BankReconciliationBalanceInvalid = 'bank_reconciliation_balance_invalid';
    case BankReconciliationNotLatest      = 'bank_reconciliation_not_latest';
    case BankReconciliationReopened       = 'bank_reconciliation_reopened';
    case BankDirectoryCodeInvalid         = 'bank_directory_code_invalid';
    case ItemRoutingKeyInvalid            = 'item_routing_key_invalid';
    case ItemRoutingItemNotFound          = 'item_routing_item_not_found';
    case RoutingItemAccountsInvalid       = 'routing_item_accounts_invalid';

    case CurrencyNotFound             = 'currency_not_found';
    case CurrencyCodeInvalid          = 'currency_code_invalid';
    case CurrencyCodeTaken            = 'currency_code_taken';
    case CurrencyNameInvalid          = 'currency_name_invalid';
    case CurrencyDecimalsInvalid      = 'currency_decimals_invalid';
    case CurrencyInUse                = 'currency_in_use';
    case CurrencyIsBase               = 'currency_is_base';
    case CurrencyInactive             = 'currency_inactive';
    case RateInvalid                  = 'currency_rate_invalid';
    case RateMissing                  = 'currency_rate_missing';
    case RateNotFound                 = 'currency_rate_not_found';
    case RateDateTaken                = 'currency_rate_date_taken';
    case DocumentCurrencyLocked       = 'document_currency_locked';
    case ForeignCurrencyUnsupported   = 'foreign_currency_unsupported';
    case TreasuryCurrencyLocked       = 'treasury_currency_locked';
    case TransferAmountRequired       = 'transfer_amount_required';
    case FxJournalLocked              = 'fx_journal_locked';
    case ForeignDecimalsUnsupported   = 'foreign_decimals_unsupported';
    case StoredValueInForeignCurrency = 'stored_value_in_foreign_currency';
    case TransferRateOutOfRange       = 'transfer_rate_out_of_range';


    case StockDocumentOff             = 'stock_document_off';
    case StockPermitNotFound          = 'stock_permit_not_found';
    case StockPermitCancelled         = 'stock_permit_cancelled';
    case StockPermitTypeInvalid       = 'stock_permit_type_invalid';
    case StockPermitLinesInvalid      = 'stock_permit_lines_invalid';
    case StockPermitLocationInvalid   = 'stock_permit_location_invalid';
    case StockPermitPartyInvalid      = 'stock_permit_party_invalid';
    case StockPermitAccountInvalid    = 'stock_permit_account_invalid';
    case StockPermitNoteTooLong       = 'stock_permit_note_too_long';
    case StockLocationDenied          = 'stock_location_denied';
    case StocktakeNotFound            = 'stocktake_not_found';
    case StocktakeExists              = 'stocktake_exists';
    case StocktakeNotCounting         = 'stocktake_not_counting';
    case StocktakeCancelled           = 'stocktake_cancelled';
    case StocktakeLinesInvalid        = 'stocktake_lines_invalid';
    case StocktakeNotCounted          = 'stocktake_not_counted';
    case StocktakeNothingToApprove    = 'stocktake_nothing_to_approve';
    case StocktakeItemsInvalid        = 'stocktake_items_invalid';
    case StocktakeItemOpen            = 'stocktake_item_open';
    case StocktakeCountChanged        = 'stocktake_count_changed';
    case StocktakeChanged             = 'stocktake_changed';
    case StockRequestNotFound         = 'stock_request_not_found';
    case StockRequestStatusInvalid    = 'stock_request_status_invalid';
    case StockRequestLocationsInvalid = 'stock_request_locations_invalid';
    case StockRequestLinesInvalid     = 'stock_request_lines_invalid';
}
