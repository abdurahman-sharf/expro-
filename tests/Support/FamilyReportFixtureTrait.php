<?php

namespace Tests\Support;

use App\Libraries\Accounting\FamilyReports\FamilyReport;
use App\Libraries\Accounting\FamilyReports\ReportInput;
use App\Libraries\Accounting\FamilyReports\ReportRow;
use App\Libraries\Accounting\FamilyReports\ReportTable;
use App\Libraries\Accounting\ReportScope;

/**
 * What a test of a report of the families needs to ask for one and to read what it answers: the
 * scopes an employee can have, the request, and the cells of the table.
 */
trait FamilyReportFixtureTrait
{
    protected function today(): string
    {
        return date('Y-m-d');
    }

    protected function everyBranch(): ReportScope
    {
        return ReportScope::resolve(null, null);
    }

    protected function limitedTo(int ...$branchIds): ReportScope
    {
        return ReportScope::resolve(null, array_values($branchIds));
    }

    protected function chosen(int ...$branchIds): ReportScope
    {
        return ReportScope::resolve(array_values($branchIds), null);
    }

    /**
     * @param array<string, mixed> $query
     */
    protected function input(array $query = [], ?ReportScope $scope = null, ?int $viewer = null, ?int $journalOwner = null): ReportInput
    {
        return new ReportInput($scope ?? $this->everyBranch(), $viewer, $query, $this->today(), $journalOwner);
    }

    /**
     * @param array<string, mixed> $query
     */
    protected function built(FamilyReport $report, array $query = [], ?ReportScope $scope = null, ?int $viewer = null, ?int $journalOwner = null): ReportTable
    {
        return $report->build($this->input($query, $scope, $viewer, $journalOwner));
    }

    /**
     * @return list<ReportRow>
     */
    protected function rowsOf(ReportTable $table): array
    {
        $rows = [];

        foreach ($table->blocks as $block) {
            $rows = [...$rows, ...$block->rows];
        }

        return $rows;
    }

    /**
     * @return list<string>
     */
    protected function columnOf(ReportTable $table, string $key): array
    {
        return array_map(static fn (ReportRow $row): string => $row->cell($key), $this->rowsOf($table));
    }

    protected function grandOf(ReportTable $table, string $key): string
    {
        return $table->grand?->cell($key) ?? '';
    }

    protected function rowWhere(ReportTable $table, string $key, string $value): ?ReportRow
    {
        foreach ($this->rowsOf($table) as $row) {
            if ($row->cell($key) === $value) {
                return $row;
            }
        }

        return null;
    }

    protected function assertReportTies(ReportTable $table): void
    {
        $this->assertNotSame([], $table->ties, 'The report has nothing it is set beside');

        foreach ($table->ties as $tie) {
            $this->assertTrue($tie->ties(), $tie->label . ': ' . $tie->reported . ' against ' . $tie->book);
        }
    }

    protected function assertAmount(string $expected, string $actual, string $message = ''): void
    {
        $this->assertSame(bcadd($expected, '0', 4), bcadd($actual, '0', 4), $message);
    }
}
