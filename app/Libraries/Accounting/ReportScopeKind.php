<?php

namespace App\Libraries\Accounting;

enum ReportScopeKind: string
{
    case Company  = 'company';
    case Partial  = 'partial';
    case Selected = 'selected';
}
