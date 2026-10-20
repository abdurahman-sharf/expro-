<?php

namespace Tests\Controllers;

use App\Controllers\Exports;
use CodeIgniter\Database\Config;
use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\DatabaseTestTrait;
use CodeIgniter\Test\FeatureTestTrait;
use ReflectionClass;
use ReflectionMethod;
use Tests\Support\EmployeeFixtureTrait;

class ExportsControllerTest extends CIUnitTestCase
{
    use DatabaseTestTrait;
    use FeatureTestTrait;
    use EmployeeFixtureTrait;

    protected $migrate     = true;
    protected $migrateOnce = true;
    protected $seedOnce    = true;
    protected $refresh     = false;
    protected $namespace   = null;

    private static bool $doneBootstrap = false;

    protected function setUp(): void
    {
        if (self::$doneBootstrap === false) {
            Config::seeder($this->DBGroup)->call('App\Database\Seeds\TestDatabaseBootstrapSeeder');
            Config::connect($this->DBGroup)->close();

            self::$doneBootstrap = true;
        }

        parent::setUp();
    }

    private function loginAsCashier(): void
    {
        $personId = $this->createEmployee(
            first_name: 'Exporter',
            last_name:  'Cashier',
            email:      'exporter.' . uniqid() . '@test.com',
            username:   'exporter_' . uniqid(),
            grants: [
                ['permission_id' => 'sales', 'menu_group' => 'home'],
                ['permission_id' => 'sales_stock', 'menu_group' => 'home'],
            ],
        );

        $this->withSession(['person_id' => $personId, 'menu_group' => 'home']);
    }

    private function payload(array $override = []): array
    {
        return ['payload' => json_encode($override + [
            'title'    => 'الأصناف',
            'subtitle' => '2026-01-01 - 2026-01-31',
            'columns'  => ['اسم الصنف', 'السعر'],
            'rows'     => [['أرز بسمتي', '$42.50'], ['Coffee', '$33.00']],
        ])];
    }

    public function testPostPdf_ReturnsPdfDocumentForTableData(): void
    {
        $this->loginAsCashier();

        $response = $this->post('/exports/pdf', $this->payload());

        $response->assertStatus(200);
        $response->assertHeader('Content-Type', 'application/pdf');
        $this->assertStringStartsWith('%PDF-', $response->response()->getBody());
    }

    public function testPostPdf_RejectsMalformedPayload(): void
    {
        $this->loginAsCashier();

        $response = $this->post('/exports/pdf', ['payload' => 'not json']);

        $response->assertStatus(400);
    }

    public function testPostPdf_RejectsMoreRowsThanTheLimit(): void
    {
        $this->loginAsCashier();

        $response = $this->post('/exports/pdf', $this->payload(['rows' => array_fill(0, Exports::MAX_ROWS + 1, ['x', 'y'])]));

        $response->assertStatus(413);
    }

    public function testPostPdf_AcceptsRowsWithMissingAndExtraCells(): void
    {
        $this->loginAsCashier();

        $response = $this->post('/exports/pdf', $this->payload(['rows' => [['only one'], ['a', 'b', 'c'], 'not an array']]));

        $response->assertStatus(200);
    }

    public function testPostPdf_EscapesCellMarkup(): void
    {
        helper(['locale', 'document']);
        $tableHtml = new ReflectionMethod(Exports::class, 'tableHtml');
        $exports = (new ReflectionClass(Exports::class))->newInstanceWithoutConstructor();

        $html = $tableHtml->invoke($exports, '<b>title</b>', '', ['<i>col</i>'], [['<script>alert(1)</script>']]);

        $this->assertStringNotContainsString('<script>alert', $html);
        $this->assertStringNotContainsString('<i>col</i>', $html);
        $this->assertStringContainsString('&lt;script&gt;', $html);
    }

    public function testPostPdf_SizesColumnsByContent(): void
    {
        $columnWidths = new ReflectionMethod(Exports::class, 'columnWidths');
        $exports = (new ReflectionClass(Exports::class))->newInstanceWithoutConstructor();

        $widths = $columnWidths->invoke($exports, ['id', 'description'], [['1', str_repeat('x', 60)]]);

        $this->assertCount(2, $widths);
        $this->assertGreaterThan($widths[0], $widths[1]);
        $this->assertEqualsWithDelta(100, array_sum($widths), 2);
    }
}
