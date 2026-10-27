<?php

namespace Tests\Helpers;

use CodeIgniter\Test\CIUnitTestCase;

/**
 * @internal
 */
final class report_helperTest extends CIUnitTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        helper(['url', 'report']);
    }

    public function testReportCatalog_IncludesOnlyPermittedSubjects(): void
    {
        $catalog = reportCatalog(['reports_items', 'home']);

        $this->assertCount(1, $catalog);
        $this->assertCount(1, $catalog[0]['subjects']);
        $this->assertSame('bi-box-seam', $catalog[0]['subjects'][0]['icon']);
    }

    public function testReportCatalog_OmitsGroupsWithoutPermittedSubjects(): void
    {
        $this->assertSame([], reportCatalog([]));
        $this->assertSame([], reportCatalog(['reports_inventory_low']));
    }

    public function testReportCatalog_LinksEachSubjectToItsReportRoutes(): void
    {
        $paths = [];

        foreach (reportCatalog(['reports_sales']) as $group) {
            foreach ($group['subjects'] as $subject) {
                foreach ($subject['links'] as $link) {
                    $paths[] = $link['path'];
                }
            }
        }

        $this->assertCount(4, $paths);
        $this->assertStringEndsWith('reports/graphical_summary_sales', $paths[0]);
        $this->assertStringEndsWith('reports/summary_sales', $paths[1]);
        $this->assertStringEndsWith('reports/detailed_sales', $paths[2]);
        $this->assertStringEndsWith('reports/detailed_item_sales', $paths[3]);
    }

    /**
     * @param list<string> $permissionIds
     *
     * @return list<string>
     */
    private function pathsFor(array $permissionIds, bool $accountingOn = true): array
    {
        $paths = [];

        foreach (reportCatalog($permissionIds, $accountingOn) as $group) {
            foreach ($group['subjects'] as $subject) {
                foreach ($subject['links'] as $link) {
                    $paths[] = $link['path'];
                }
            }
        }

        return $paths;
    }

    public function testReportCatalog_OffersTheInventoryValuationToTheFinancialReportsModuleOnly(): void
    {
        $paths = $this->pathsFor(['accounting_reports']);

        $this->assertCount(1, $paths);
        $this->assertStringEndsWith('accounting_reports/inventory_valuation', $paths[0]);

        foreach ($this->pathsFor(['reports_inventory', 'reports_sales', 'accounting_journals', 'accounting_payables']) as $path) {
            $this->assertStringNotContainsString('inventory_valuation', $path);
        }
    }

    public function testReportCatalog_LeavesOutTheReportsOfTheBooksWhileAccountingIsOff(): void
    {
        $this->assertSame([], reportCatalog(['accounting_reports'], false));

        $paths = $this->pathsFor(['accounting_reports', 'reports_inventory'], false);

        $this->assertCount(2, $paths, 'The legacy inventory reports stay');

        foreach ($paths as $path) {
            $this->assertStringNotContainsString('inventory_valuation', $path);
        }
    }
}
