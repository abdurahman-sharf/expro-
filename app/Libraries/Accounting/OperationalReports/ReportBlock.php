<?php

namespace App\Libraries\Accounting\OperationalReports;

/**
 * A run of rows of a report under one heading, with the row that adds them up. A summary report is
 * one block without a heading whose rows are the subtotals of the groups of the detailed one.
 */
final readonly class ReportBlock
{
    /**
     * @param array<string, string>       $heading  The cells of the heading row, empty when there is none.
     * @param list<array<string, string>> $rows
     * @param array<string, string>|null  $subtotal
     */
    public function __construct(
        public array $heading,
        public array $rows,
        public ?array $subtotal = null,
    ) {
    }
}
