<?php

namespace Tests\Libraries\Accounting\FamilyReports;

use App\Libraries\Accounting\FamilyReports\CostCenterReport;
use App\Libraries\Accounting\FamilyReports\FamilyReportCatalog;
use CodeIgniter\Test\CIUnitTestCase;

/**
 * Where the reports of the books are listed and who may read them.
 *
 * @internal
 */
final class FamilyReportCatalogTest extends CIUnitTestCase
{
    public function testFind_ListsTheCostCenterReportUnderTheBooksWithThePermissionOfTheCostCenters(): void
    {
        $definition = FamilyReportCatalog::find('cost_centers');

        $this->assertSame('accounting_cost_centers', $definition['permission']);
        $this->assertSame('books', $definition['family']);
        $this->assertSame(CostCenterReport::class, $definition['class']);
    }
}
