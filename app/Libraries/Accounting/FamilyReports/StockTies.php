<?php

namespace App\Libraries\Accounting\FamilyReports;

use App\Libraries\Accounting\InventoryValuation;
use App\Libraries\Accounting\ReportScope;
use App\Libraries\Accounting\SaleAmounts;
use App\Libraries\Accounting\StockLedger;

/**
 * Sets the value of stock a report ends with beside what the accounts of the stock locations hold.
 * For the stock as it stands now in the whole company the account of each location is read through
 * the reconciliation of the stock ledger; for a day of the past or a few branches it is read from the
 * journals of those branches up to the day, as the valuation does.
 */
final class StockTies
{
    public function __construct(
        private readonly InventoryValuation $valuation = new InventoryValuation(),
        private readonly StockLedger $ledger = new StockLedger(),
    ) {
    }

    /**
     * @param numeric-string $reported    The value of the movements the report ends with, for the locations it shows.
     * @param list<int>|null $locationIds The locations the report shows, null for all of the scope.
     */
    public function closing(ReportScope $scope, string $reported, string $to, string $today, ?array $locationIds = null): TieCheck
    {
        $current = $to >= $today;
        $report  = $this->valuation->build($scope, $current ? null : $to, true);
        $held    = [];

        foreach ($report->locations as $location) {
            if ($locationIds === null || in_array($location->locationId, $locationIds, true)) {
                $held[$location->locationId] = $location->accountBalance;
            }
        }

        if ($current && $scope->isCompany() && $held !== []) {
            $reconciled = [];

            foreach ($this->ledger->reconcile(array_keys($held)) as $row) {
                $reconciled[$row['location_id']] = $row['account_balance'];
            }

            $held = $reconciled;
        }

        $sum = '0';

        foreach ($held as $balance) {
            $sum = bcadd($sum, $balance, SaleAmounts::SCALE);
        }

        return new TieCheck(lang('Accounting_family_reports.tie_stock_accounts'), $reported, $sum);
    }
}
