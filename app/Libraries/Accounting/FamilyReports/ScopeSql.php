<?php

namespace App\Libraries\Accounting\FamilyReports;

use App\Libraries\Accounting\ReportScope;

final class ScopeSql
{
    /**
     * The condition that keeps what belongs to the branches of the scope. What belongs to no branch
     * (an expense saved before expenses carried one) is kept only when the scope is the whole
     * company, so that it never reaches someone who reads some branches.
     */
    public static function branches(string $column, ReportScope $scope, bool $withUnbranched = false): string
    {
        if ($scope->isEmpty()) {
            return '1 = 0';
        }

        $in = "{$column} IN (" . implode(', ', array_map('intval', $scope->ids)) . ')';

        return $withUnbranched && $scope->isCompany() ? "({$in} OR {$column} IS NULL)" : $in;
    }
}
