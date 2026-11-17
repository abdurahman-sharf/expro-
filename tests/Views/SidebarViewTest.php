<?php

namespace Tests\Views;

use App\Libraries\Roles\RoleGrants;
use App\Models\Employee;
use App\Models\Module;
use CodeIgniter\HTTP\IncomingRequest;
use CodeIgniter\HTTP\SiteURI;
use CodeIgniter\HTTP\UserAgent;
use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\DatabaseTestTrait;
use Config\App;
use Config\Database;
use Config\Services;
use DOMDocument;
use DOMElement;
use DOMXPath;
use Tests\Support\AccountingFixtureTrait;
use Tests\Support\NavigationShapesTrait;
use Tests\Support\RoleFixtureTrait;

/**
 * The markup of the sidebar: what a script and a screen reader rely on, rendered for people who hold different things.
 *
 * @internal
 */
final class SidebarViewTest extends CIUnitTestCase
{
    use AccountingFixtureTrait;
    use DatabaseTestTrait;
    use NavigationShapesTrait;
    use RoleFixtureTrait;

    protected $migrate     = true;
    protected $migrateOnce = true;
    protected $refresh     = false;
    protected $namespace;

    public static function setUpBeforeClass(): void
    {
        Database::seeder('tests')->call('TestDatabaseBootstrapSeeder');
    }

    protected function setUp(): void
    {
        parent::setUp();

        helper(['navigation', 'asset', 'url']);
        RoleGrants::flush();
    }

    protected function tearDown(): void
    {
        session()->remove('person_id');
        Services::resetSingle('request');

        parent::tearDown();
    }

    private function sidebarOf(int $personId, string $path): DOMXPath
    {
        $config = config(App::class);
        Services::injectMock('request', new IncomingRequest($config, new SiteURI($config, $path), null, new UserAgent()));

        $html = '';

        $this->withAccounting(true, function () use ($personId, &$html): void {
            $html = view('partial/sidebar', [
                'user_info'       => model(Employee::class)->get_info($personId),
                'allowed_modules' => model(Module::class)->getAllowedModules($personId)->getResult(),
            ], ['saveData' => false]);
        });

        $document = new DOMDocument();
        $previous = libxml_use_internal_errors(true);
        $document->loadHTML('<?xml encoding="utf-8"?>' . $html);
        libxml_clear_errors();
        libxml_use_internal_errors($previous);

        return new DOMXPath($document);
    }

    private function elements(DOMXPath $page, string $query): array
    {
        return iterator_to_array($page->query($query));
    }

    private function one(DOMXPath $page, string $query): DOMElement
    {
        $found = $this->elements($page, $query);

        $this->assertCount(1, $found, $query);
        $this->assertInstanceOf(DOMElement::class, $found[0]);

        return $found[0];
    }

    public function testSidebar_OpensOnlyTheSectionOfThePageAndFoldsTheOthersAwayFromTheKeyboard(): void
    {
        $page = $this->sidebarOf($this->employeeHolding($this->everyPermissionId()), 'accounting_periods/year_end');

        $this->assertSame('ledger', $this->one($page, '//nav[contains(@class, "app-nav")]')->getAttribute('data-nav-open'));

        $toggles = $this->elements($page, '//button[contains(@class, "app-nav-toggle")]');

        $this->assertCount(11, $toggles);
        $this->assertCount(0, $this->elements($page, '//section[contains(@class, "is-direct")]'));

        $open = [];

        foreach ($toggles as $toggle) {
            $panel = $this->one($page, '//div[@id="' . $toggle->getAttribute('aria-controls') . '"]');

            $this->assertSame($toggle->getAttribute('id'), $panel->getAttribute('aria-labelledby'));
            $this->assertSame('group', $panel->getAttribute('role'));
            $this->assertSame($toggle->getAttribute('aria-expanded') !== 'true', $panel->hasAttribute('hidden'), $toggle->getAttribute('id'));
            $this->assertCount(1, $this->elements($page, '//button[@id="' . $toggle->getAttribute('id') . '"]/i[contains(@class, "app-nav-caret")]'));

            if ($toggle->getAttribute('aria-expanded') === 'true') {
                $open[] = $toggle->getAttribute('id');
            }
        }

        $this->assertSame(['nav-toggle-ledger'], $open);
    }

    public function testSidebar_MarksOnlyThePageBeingShownAsCurrentAndKeepsItInsideTheOpenSection(): void
    {
        $page = $this->sidebarOf($this->employeeHolding($this->everyPermissionId()), 'accounting_periods/year_end');

        $current = $this->one($page, '//a[@aria-current="page"]');

        $this->assertSame('year_end', $current->getAttribute('data-nav-id'));
        $this->assertStringContainsString('active', $current->getAttribute('class'));
        $this->assertCount(1, $this->elements($page, '//div[@id="nav-panel-ledger"]//a[@aria-current="page"]'));
        $this->assertCount(1, $this->elements($page, '//section[contains(@class, "has-active")]'));
    }

    public function testSidebar_RendersASectionWithOnePageAsALinkWithoutAChevronOrAPanel(): void
    {
        $page = $this->sidebarOf($this->employeeHolding(['home']), 'home');

        $this->assertSame('', $this->one($page, '//nav[contains(@class, "app-nav")]')->getAttribute('data-nav-open'));
        $this->assertCount(0, $this->elements($page, '//button[contains(@class, "app-nav-toggle")]'));
        $this->assertCount(0, $this->elements($page, '//*[contains(@class, "app-nav-caret")]'));
        $this->assertCount(0, $this->elements($page, '//div[contains(@class, "app-nav-panel")]'));

        $section = $this->one($page, '//section[contains(@class, "is-direct")]');

        $this->assertStringContainsString('has-active', $section->getAttribute('class'));
        $this->assertSame('home', $this->one($page, '//h2/a[@aria-current="page"]')->getAttribute('data-nav-id'));
    }

    public function testSidebar_KeepsTheSettingsAsTheCurrentPageForSomeoneWhoHoldsOnlyTheSettingsAndTheStartPage(): void
    {
        $page = $this->sidebarOf($this->employeeHolding(['home', 'config']), 'config');

        $warehouses = $this->one($page, '//a[@data-nav-id="warehouses"]');

        $this->assertSame('stock_tab', $warehouses->getAttribute('data-nav-fragment'));
        $this->assertFalse($warehouses->hasAttribute('aria-current'));
        $this->assertCount(1, $this->elements($page, '//section[@data-nav-section="inventory"][contains(@class, "is-direct")]'));

        $this->assertSame('config', $this->one($page, '//a[@aria-current="page"]')->getAttribute('data-nav-id'));

        $tabs = array_map(static fn (DOMElement $link): string => $link->getAttribute('data-nav-fragment'), $this->elements($page, '//div[@id="nav-panel-templates"]//a[@data-nav-fragment]'));

        $this->assertSame(['receipt_tab', 'invoice_tab', 'barcode_tab'], $tabs);
        $this->assertCount(1, $this->elements($page, '//div[@id="nav-panel-templates"][@hidden]'));
    }

    public function testSidebar_MarksTheViewOfTheNetPositionThatTheQueryOfTheRequestAsksFor(): void
    {
        $personId = $this->employeeHolding(['home', 'accounting_reports', 'reports_sales']);

        $returned = $this->sidebarOf($personId, 'accounting_reports/net_position?status[]=returned&status[]=partial_return&show=1');
        $paid     = $this->sidebarOf($personId, 'accounting_reports/net_position?status[]=paid&show=1');

        $this->assertSame('returned_invoices', $this->one($returned, '//a[@aria-current="page"]')->getAttribute('data-nav-id'));
        $this->assertSame('collected_invoices', $this->one($paid, '//a[@aria-current="page"]')->getAttribute('data-nav-id'));
        $this->assertSame('nav-toggle-sales', $this->one($returned, '//button[@aria-expanded="true"]')->getAttribute('id'));
    }

    public function testSidebar_KeepsTheTaxesCurrentOnTheScreenOfTheTaxDefinitions(): void
    {
        $page = $this->sidebarOf($this->employeeHolding(['home', 'taxes']), 'tax_definitions');

        $this->assertSame('taxes', $this->one($page, '//a[@aria-current="page"]')->getAttribute('data-nav-id'));
    }

    public function testSidebar_PrintsTheIdOfAPageEscaped(): void
    {
        $source = (string) file_get_contents(APPPATH . 'Views/partial/sidebar.php');

        $this->assertSame(0, preg_match('/data-nav-id="<\?= \$(?:item|only)\[\'id\'\] \?>"/', $source));
        $this->assertSame(2, preg_match_all('/data-nav-id="<\?= esc\(\$(?:item|only)\[\'id\'\], \'attr\'\) \?>"/', $source));
    }
}
