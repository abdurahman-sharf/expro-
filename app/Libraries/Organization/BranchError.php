<?php

namespace App\Libraries\Organization;

enum BranchError: string
{
    case PermissionDenied   = 'permission_denied';
    case NotFound           = 'not_found';
    case NotAllowed         = 'not_allowed';
    case CompanyScopeNeeded = 'company_scope_needed';
    case Inactive           = 'inactive';
    case InvalidCode        = 'invalid_code';
    case CodeTaken          = 'code_taken';
    case CodeLocked         = 'code_locked';
    case InvalidName        = 'invalid_name';
    case InvalidDate        = 'invalid_date';
    case DefaultLocked      = 'default_locked';
    case InUse              = 'in_use';
    case HasDrafts          = 'has_drafts';
    case AlreadyActive      = 'already_active';
    case AlreadyInactive    = 'already_inactive';
    case LocationNotFound   = 'location_not_found';
    case EmployeeNotFound   = 'employee_not_found';
    case OwnScope           = 'own_scope';
    case ScopeTooWide       = 'scope_too_wide';
    case NoBranch           = 'no_branch';
    case InvalidDefault     = 'invalid_default';
    case LastUnrestricted   = 'last_unrestricted';
}
