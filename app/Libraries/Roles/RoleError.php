<?php

namespace App\Libraries\Roles;

enum RoleError: string
{
    case PermissionDenied    = 'permission_denied';
    case NotFound            = 'not_found';
    case InvalidName         = 'invalid_name';
    case NameTaken           = 'name_taken';
    case InvalidDescription  = 'invalid_description';
    case InvalidScope        = 'invalid_scope';
    case UnknownPermission   = 'unknown_permission';
    case SensitivePermission = 'sensitive_permission';
    case RefusedPermission   = 'refused_permission';
    case BranchPermission    = 'branch_permission';
    case CannotGrant         = 'cannot_grant';
    case InUse               = 'in_use';
    case EmployeeNotFound    = 'employee_not_found';
    case LocationNotFound    = 'location_not_found';
}
