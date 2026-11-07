<?php

namespace App\Libraries\Accounting\OperationalReports;

/**
 * What the sales documents of a report add up to beside what their journals hold: the total on the
 * accounts of the customers, the tax on the accounts of tax, and the revenue on the accounts of revenue
 * and expense. The journal side is read from the lines of the journals by the type of the account, so a
 * change of the routing does not change what it says.
 */
final class SalesTies
{
    /**
     * @param list<ReportDocument> $documents
     *
     * @return list<TieCheck>
     */
    public static function of(array $documents): array
    {
        return [
            new TieCheck('Accounting_operational_reports.tie_sales_total', Amounts::sum(array_map(static fn (ReportDocument $document): string => $document->total, $documents)), Amounts::sum(array_map(static fn (ReportDocument $document): string => $document->ledgerTotal, $documents))),
            new TieCheck('Accounting_operational_reports.tie_sales_tax', Amounts::sum(array_map(static fn (ReportDocument $document): string => $document->tax, $documents)), Amounts::sum(array_map(static fn (ReportDocument $document): string => $document->ledgerTax, $documents))),
            new TieCheck('Accounting_operational_reports.tie_sales_revenue', Amounts::sum(array_map(static fn (ReportDocument $document): string => $document->revenue(), $documents)), Amounts::sum(array_map(static fn (ReportDocument $document): string => $document->ledgerRevenue, $documents))),
        ];
    }
}
