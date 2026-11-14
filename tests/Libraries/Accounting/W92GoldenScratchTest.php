<?php

namespace Tests\Libraries\Accounting;

use App\Libraries\Accounting\AccountingGuard;
use App\Libraries\Accounting\AgedLedger;
use App\Libraries\Accounting\CustomerAdvances;
use App\Libraries\Accounting\CustomerLedger;
use App\Libraries\Accounting\EntityAccountService;
use App\Libraries\Accounting\FamilyReports\FamilyReportCatalog;
use App\Libraries\Accounting\OperationalReports\AdvancePaymentsReport;
use App\Libraries\Accounting\OperationalReports\ClientsSalesReport;
use App\Libraries\Accounting\OperationalReports\CrossReferenceReport;
use App\Libraries\Accounting\OperationalReports\ItemGroup;
use App\Libraries\Accounting\OperationalReports\ItemProfitReport;
use App\Libraries\Accounting\OperationalReports\ItemSalesReport;
use App\Libraries\Accounting\OperationalReports\NetPositionReport;
use App\Libraries\Accounting\OperationalReports\PaymentGroup;
use App\Libraries\Accounting\OperationalReports\PaymentsReport;
use App\Libraries\Accounting\OperationalReports\ProfitReport;
use App\Libraries\Accounting\OperationalReports\RevenueGroup;
use App\Libraries\Accounting\OperationalReports\RevenueReport;
use App\Libraries\Accounting\ReportScope;
use App\Libraries\Accounting\SupplierLedger;
use Tests\Support\FamilyReportTestCase;

final class W92GoldenScratchTest extends FamilyReportTestCase
{
    public function testDump(): void
    {
        $out = getenv('W92_DUMP') ?: '/tmp/w92_dump.json';

        $this->grantInvoiceDate();
        $this->grantPurchaseDate();

        $other  = $this->anotherCustomer('Hind', 'Saad');
        $cycle  = $this->referenceInvoice();
        $first  = $this->save($cycle['items'], $cycle['taxes'], [$this->payment(lang('Sales.cash'), '300.00')], SALE_TYPE_INVOICE, $this->customerId, true, null, COMPLETED, NEW_ENTRY, null, invoiceDate: $this->daysAgo(12));
        $second = $this->invoiceDated('115', $this->daysAgo(6), null, $other, [$this->payment(lang('Sales.cash'), '100')]);
        $this->creditNoteOf($first, '57.50');
        $this->refundInvoiceOf($second, '28.75', $other);
        $this->invoiceDated('80', $this->daysAgo(2), null, $this->customerId);
        (new CustomerAdvances())->receive($other, '30.00', date('Y-m-d'), 'Cash', null, '', '', $this->employeeWithGrants([AccountingGuard::RECEIVABLES_MANAGE]));

        $item = $this->item('0.00');
        $this->buy([$this->purchaseLine($item, 0, '4', '50.00', '15')], -1, null, null, null, $this->daysAgo(10));
        $second = $this->buy([$this->purchaseLine($item, 0, '2', '30.00')], -1, lang('Sales.cash'), null, null, $this->daysAgo(4));
        $this->debitNoteOf($second, '20.00');

        $scope = ReportScope::resolve(null, null);
        $from  = '2020-01-01';
        $to    = date('Y-m-d', strtotime('+1 day'));
        $day   = date('Y-m-d');
        $dump  = [];

        $strip = static function (mixed $value) use (&$strip): mixed {
            if (is_array($value)) {
                unset($value['refusal'], $value['refusalArguments'], $value['currencyCode'], $value['currencyRate']);

                return array_map($strip, $value);
            }

            return $value;
        };
        $enc = static fn (mixed $value): mixed => $strip(json_decode(json_encode($value, JSON_PARTIAL_OUTPUT_ON_ERROR), true));

        foreach (RevenueGroup::cases() as $group) {
            foreach ([true, false] as $details) {
                $dump['revenue_' . $group->value . ($details ? '_d' : '_s')] = $enc((new RevenueReport())->build($scope, $group, $details, $from, $to, $day));
            }
        }

        foreach ([true, false] as $byCustomer) {
            foreach ([true, false] as $details) {
                $dump['clients_' . (int) $byCustomer . (int) $details] = $enc((new ClientsSalesReport())->build($scope, null, $byCustomer, $details, $from, $to));
                $dump['cross_' . (int) $byCustomer . (int) $details]   = $enc((new CrossReferenceReport())->build($scope, $details, $byCustomer, $from, $to));
            }
        }

        foreach ([ItemGroup::Item, ItemGroup::Category, ItemGroup::Customer, ItemGroup::User] as $group) {
            $dump['itemsales_' . $group->value]  = $enc((new ItemSalesReport())->build($scope, $group, true, $from, $to, $day));
            $dump['itemprofit_' . $group->value] = $enc((new ItemProfitReport())->build($scope, $group, $from, $to));
            $dump['profit_' . $group->value]     = $enc((new ProfitReport())->build($scope, $group, true, $from, $to));
        }

        foreach ([true, false] as $details) {
            $dump['net_' . (int) $details] = $enc((new NetPositionReport())->build($scope, $details, $from, $to));
        }

        foreach ([PaymentGroup::Customer, PaymentGroup::Method, PaymentGroup::Treasury] as $group) {
            $dump['payments_' . $group->value] = $enc((new PaymentsReport())->build($scope, $group, true, $from, $to));
        }

        $dump['advances'] = $enc((new AdvancePaymentsReport())->build($scope, $from, $to));

        foreach (FamilyReportCatalog::all() as $key => $definition) {
            $report = new $definition['class']();

            foreach ([['from' => $from, 'to' => $to], ['to' => $to]] as $index => $query) {
                try {
                    $dump['family_' . $key . '_' . $index] = $enc($report->build($this->input($query)));
                } catch (\Throwable $exception) {
                    $dump['family_' . $key . '_' . $index] = 'EXC ' . get_class($exception);
                }
            }
        }

        $dump['aged_customers'] = $enc((new AgedLedger())->build(EntityAccountService::CUSTOMER, $to, 30));
        $dump['aged_suppliers'] = $enc((new AgedLedger())->build(EntityAccountService::SUPPLIER, $to, 30));
        $dump['position_customer'] = $enc((new CustomerLedger())->position($this->customerId));
        $dump['position_supplier'] = $enc((new SupplierLedger())->position($this->supplierId));

        file_put_contents($out, json_encode($dump, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_PARTIAL_OUTPUT_ON_ERROR));
        $this->assertNotEmpty($dump);
    }
}
