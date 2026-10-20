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

    public function testReportCatalogIncludesOnlyPermittedSubjects(): void
    {
        $catalog = reportCatalog(['reports_items', 'home']);

        $this->assertCount(1, $catalog);
        $this->assertCount(1, $catalog[0]['subjects']);
        $this->assertSame('bi-box-seam', $catalog[0]['subjects'][0]['icon']);
    }

    public function testReportCatalogOmitsGroupsWithoutPermittedSubjects(): void
    {
        $this->assertSame([], reportCatalog([]));
        $this->assertSame([], reportCatalog(['reports_inventory_low']));
    }

    public function testReportCatalogLinksEachSubjectToItsReportRoutes(): void
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
}
