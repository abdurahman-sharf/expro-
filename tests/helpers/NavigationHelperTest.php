<?php

namespace Tests\Helpers;

use CodeIgniter\Test\CIUnitTestCase;

class NavigationHelperTest extends CIUnitTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        helper('navigation');
    }

    private function modules(string ...$moduleIds): array
    {
        return array_map(static fn(string $moduleId): object => (object) ['module_id' => $moduleId], $moduleIds);
    }

    private function itemIds(array $groups): array
    {
        return array_map(
            static fn(array $group): array => array_column($group['items'], 'id'),
            $groups
        );
    }

    public function testNavigationGroups_PlacesModulesInTheirGroupsInOrder(): void
    {
        $groups = navigationGroups($this->modules('config', 'sales', 'home', 'items', 'receivings'), 'sales');

        $this->assertSame([['home'], ['sales'], ['receivings'], ['items'], ['config']], $this->itemIds($groups));
    }

    public function testNavigationGroups_OmitsGroupsWithoutAllowedModules(): void
    {
        $groups = navigationGroups($this->modules('reports'), 'reports');

        $this->assertCount(1, $groups);
        $this->assertSame(['reports'], array_column($groups[0]['items'], 'id'));
    }

    public function testNavigationGroups_AppendsUnknownModulesToAdministration(): void
    {
        $groups = navigationGroups($this->modules('employees', 'custom_module'), 'home');

        $this->assertSame([['employees', 'custom_module']], $this->itemIds($groups));
    }

    public function testNavigationGroups_MarksOnlyTheActiveModule(): void
    {
        $groups = navigationGroups($this->modules('sales', 'items'), 'items');

        $active = [];
        foreach ($groups as $group) {
            foreach ($group['items'] as $item) {
                $active[$item['id']] = $item['active'];
            }
        }

        $this->assertSame(['sales' => false, 'items' => true], $active);
    }

    public function testNavigationGroups_UsesTheFallbackIconForUnknownModules(): void
    {
        $groups = navigationGroups($this->modules('custom_module'), 'home');

        $this->assertSame(NAVIGATION_FALLBACK_ICON, $groups[0]['items'][0]['icon']);
    }

    public function testNavigationGroups_LinksToTheModuleRoot(): void
    {
        $groups = navigationGroups($this->modules('customers'), 'home');

        $this->assertSame(base_url('customers'), $groups[0]['items'][0]['url']);
    }

    public function testNavigationActiveModule_ReturnsHomeForTheRootPath(): void
    {
        $this->assertSame('home', navigationActiveModule(''));
    }

    public function testNavigationActiveModule_ReturnsTheFirstSegment(): void
    {
        $this->assertSame('items', navigationActiveModule('items'));
    }

    public function testNavigationPageTitle_ReturnsTheModuleName(): void
    {
        $this->assertSame(lang('Module.sales'), navigationPageTitle('sales'));
    }

    public function testNavigationPageTitle_ReturnsEmptyForNonModuleControllers(): void
    {
        $this->assertSame('', navigationPageTitle('tax_codes'));
    }

    public function testSettingsSections_ListsEverySectionOnce(): void
    {
        $ids = [];

        foreach (settingsSections() as [, $sections]) {
            foreach ($sections as [$id]) {
                $ids[] = $id;
            }
        }

        $this->assertCount(13, $ids);
        $this->assertSame($ids, array_values(array_unique($ids)));
    }

    public function testNavigationSearchIndex_ListsOnlyAllowedModules(): void
    {
        $entries = navigationSearchIndex($this->modules('sales', 'items'), []);

        $this->assertSame(['sales', 'items'], array_map(static fn (array $entry): string => basename($entry['url']), $entries));
        $this->assertSame(['module'], array_values(array_unique(array_column($entries, 'type'))));
    }

    public function testNavigationSearchIndex_AddsPermittedReportsForTheReportsModule(): void
    {
        $entries = navigationSearchIndex($this->modules('reports'), ['reports_items', 'home']);
        $reports = array_values(array_filter($entries, static fn (array $entry): bool => $entry['type'] === 'report'));

        $this->assertCount(2, $reports);
        $this->assertStringEndsWith('reports/graphical_summary_items', $reports[0]['url']);
        $this->assertStringEndsWith('reports/summary_items', $reports[1]['url']);
    }

    public function testNavigationSearchIndex_OmitsReportsWithoutTheReportsModule(): void
    {
        $entries = navigationSearchIndex($this->modules('sales'), ['reports_items']);

        $this->assertNotContains('report', array_column($entries, 'type'));
    }

    public function testNavigationSearchIndex_AddsSettingsSectionsForTheConfigModule(): void
    {
        $entries  = navigationSearchIndex($this->modules('config'), []);
        $settings = array_values(array_filter($entries, static fn (array $entry): bool => $entry['type'] === 'setting'));

        $this->assertCount(12, $settings);
        $this->assertStringEndsWith('config#info_tab', $settings[0]['url']);
    }

    public function testNavigationSearchIndex_OmitsSettingsWithoutTheConfigModule(): void
    {
        $entries = navigationSearchIndex($this->modules('sales'), []);

        $this->assertNotContains('setting', array_column($entries, 'type'));
    }
}
