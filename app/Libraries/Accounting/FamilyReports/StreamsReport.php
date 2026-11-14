<?php

namespace App\Libraries\Accounting\FamilyReports;

use App\Libraries\Accounting\AccountingException;

/**
 * A report that can be read without holding its rows: a screen takes the page it shows and a file takes
 * every row one after the other, and what both hold is what build() gives.
 */
interface StreamsReport extends FamilyReport
{
    /**
     * @throws AccountingException When the filters name a day that is not one or a period that ends before it starts.
     */
    public function stream(ReportInput $input): ReportStream;
}
