<?php

namespace App\Controllers;

use App\Libraries\Recurring\RecurringKind;

class Recurring_invoices extends RecurringScreen
{
    protected function kind(): RecurringKind
    {
        return RecurringKind::Invoice;
    }
}
