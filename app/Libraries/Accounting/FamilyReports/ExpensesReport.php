<?php

namespace App\Libraries\Accounting\FamilyReports;

final class ExpensesReport extends CashDocumentsReport
{
    protected function kind(): string
    {
        return CashDocumentRows::EXPENSE;
    }

    protected function groups(): array
    {
        return ['category', 'user', 'vendor', 'treasury', 'day', 'week', 'month', 'year'];
    }

    protected function titleKey(): string
    {
        return 'expenses_title';
    }
}
