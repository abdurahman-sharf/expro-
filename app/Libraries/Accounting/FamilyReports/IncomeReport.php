<?php

namespace App\Libraries\Accounting\FamilyReports;

final class IncomeReport extends CashDocumentsReport
{
    protected function kind(): string
    {
        return CashDocumentRows::INCOME;
    }

    protected function groups(): array
    {
        return ['category', 'user', 'treasury', 'day', 'week', 'month', 'year'];
    }

    protected function titleKey(): string
    {
        return 'income_title';
    }
}
