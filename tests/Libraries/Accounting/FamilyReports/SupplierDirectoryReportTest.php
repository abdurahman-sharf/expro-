<?php

namespace Tests\Libraries\Accounting\FamilyReports;

use App\Libraries\Accounting\FamilyReports\SupplierDirectoryReport;
use Tests\Support\FamilyReportTestCase;

/**
 * The suppliers with their code, tax number, registry, address and phone, cut down to a city or a
 * country as it is written on the supplier, and the same for every branch.
 *
 * @internal
 */
final class SupplierDirectoryReportTest extends FamilyReportTestCase
{
    private function live(int $supplierId, string $city, string $country, string $tax): void
    {
        $this->accountingDb()->table('people')->where('person_id', $supplierId)->update(['city' => $city, 'country' => $country, 'phone_number' => '0501234567']);
        $this->accountingDb()->table('suppliers')->where('person_id', $supplierId)->update(['tax_id' => $tax, 'commercial_registry' => 'CR-' . $supplierId]);
    }

    public function testBuild_ListsTheSuppliersWithTheirCodeTaxNumberAndRegistry(): void
    {
        $this->live($this->supplierId, 'Riyadh', 'KSA', '300123');

        $table = $this->built(new SupplierDirectoryReport());
        $row   = $this->rowWhere($table, 'code', (string) $this->supplierId);

        $this->assertNotNull($row);
        $this->assertSame('Gulf Supplies', $row->cell('name'));
        $this->assertSame('300123', $row->cell('tax_number'));
        $this->assertSame('CR-' . $this->supplierId, $row->cell('registry'));
        $this->assertStringContainsString('Riyadh', $row->cell('address'));
        $this->assertSame('0501234567', $row->cell('phone'));
    }

    public function testBuild_FiltersByTheCityAsItIsStoredAndOnlyThatWay(): void
    {
        $other = $this->anotherSupplier();

        $this->live($this->supplierId, 'Riyadh', 'KSA', '1');
        $this->live($other, 'Jeddah', 'KSA', '2');

        $report = new SupplierDirectoryReport();

        $this->assertCount(1, $this->rowsOf($this->built($report, ['city' => 'Riyadh'])));
        $this->assertSame('Gulf Supplies', $this->rowsOf($this->built($report, ['city' => 'Riyadh']))[0]->cell('name'));
        $this->assertCount(0, $this->rowsOf($this->built($report, ['city' => 'الرياض'])));
        $this->assertCount(2, $this->rowsOf($this->built($report, ['country' => 'KSA'])));
    }

    public function testBuild_IsTheSameForEveryScopeBecauseItHoldsNoFigures(): void
    {
        $report = new SupplierDirectoryReport();

        $this->assertCount(count($this->rowsOf($this->built($report))), $this->rowsOf($this->built($report, [], $this->limitedTo(1))));
        $this->assertSame([], $this->built($report)->ties);
    }
}
