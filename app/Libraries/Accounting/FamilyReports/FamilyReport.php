<?php

namespace App\Libraries\Accounting\FamilyReports;

use App\Libraries\Accounting\AccountingException;

interface FamilyReport
{
    /**
     * @throws AccountingException When the filters name a day that is not one or a period that ends before it starts.
     */
    public function build(ReportInput $input): ReportTable;
}
