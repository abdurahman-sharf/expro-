<?php

namespace Tests\Config;

use CodeIgniter\Filters\CSRF;
use CodeIgniter\Filters\Filters;
use CodeIgniter\HTTP\IncomingRequest;
use CodeIgniter\Security\Exceptions\SecurityException;
use CodeIgniter\Test\CIUnitTestCase;
use Config\Filters as FiltersConfig;
use PHPUnit\Framework\Attributes\DataProvider;
use ReflectionClass;

/**
 * The filters of the application as they are in production. The tests run without the token filter
 * (the constructor of the configuration takes it away under test), so these put the production
 * configuration back and ask the framework which filters a route gets.
 */
class FiltersTest extends CIUnitTestCase
{
    private function productionConfig(): FiltersConfig
    {
        $config          = new FiltersConfig();
        $config->globals = (new ReflectionClass(FiltersConfig::class))->getDefaultProperties()['globals'];

        return $config;
    }

    private function postRequest(): IncomingRequest
    {
        $request = service('incomingrequest', null, false);

        return $request->setMethod('POST');
    }

    /**
     * @return array<string, array{0: string}>
     */
    public static function salesPostRoutes(): array
    {
        return [
            'the issue of a draft'          => ['sales/issue/5'],
            'the status of a quote'         => ['sales/quoteStatus/5'],
            'the conversion of a quote'     => ['sales/convertQuote/5'],
            'the register taking up a sale' => ['sales/unsuspend'],
            'the cancelling of a document'  => ['sales/delete/5'],
        ];
    }

    #[DataProvider('salesPostRoutes')]
    public function testInitialize_PutsTheTokenFilterBeforeEveryPostRouteOfTheSales(string $route): void
    {
        $filters = new Filters($this->productionConfig(), $this->postRequest(), service('response'));
        $filters->initialize($route);

        $this->assertContains('csrf', $filters->getFilters()['before'], $route);
    }

    public function testInitialize_LeavesTheTokenFilterOutOfTheRoutesItIsMeantToSpare(): void
    {
        $filters = new Filters($this->productionConfig(), $this->postRequest(), service('response'));
        $filters->initialize('login');

        $this->assertNotContains('csrf', $filters->getFilters()['before']);
    }

    public function testBefore_RejectsAPostThatCarriesNoToken(): void
    {
        $this->expectException(SecurityException::class);

        (new CSRF())->before($this->postRequest());
    }
}
