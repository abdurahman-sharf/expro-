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

    case FiscalYearNotFound    = 'fiscal_year_not_found';
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

    case InvalidCurrency            = 'invalid_currency';
    case SettingsLocked             = 'settings_locked';
    case StartDateOutsideFiscalYear = 'start_date_outside_fiscal_year';

    case OpeningStartDateMissing  = 'opening_start_date_missing';
    case OpeningAccountNotAllowed = 'opening_account_not_allowed';
    case OpeningUnbalanced        = 'opening_unbalanced';
    case OpeningAlreadyPosted     = 'opening_already_posted';
    case OpeningNotPosted         = 'opening_not_posted';
}
