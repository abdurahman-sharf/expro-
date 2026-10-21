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
}
