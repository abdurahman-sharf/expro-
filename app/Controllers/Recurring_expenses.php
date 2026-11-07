<?php

namespace App\Controllers;

use App\Libraries\Recurring\RecurringKind;

class Recurring_expenses extends RecurringScreen
{
    protected function kind(): RecurringKind
    {
        return RecurringKind::Expense;
    }
}
