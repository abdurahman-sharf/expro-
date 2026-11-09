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

    /**
     * @return array<string, array{0: string}>
     */
    public static function portalRoutes(): array
    {
        return [
            'the portal itself'        => ['portal'],
            'the sign-in'              => ['portal/login'],
            'the statement'            => ['portal/statement'],
            'an invoice'               => ['portal/invoice/5'],
            'the file of an invoice'   => ['portal/invoice/5/pdf'],
            'a method reached by name' => ['portal/getStatement'],
            'the same in capitals'     => ['Portal/Statement'],
        ];
    }

    #[DataProvider('portalRoutes')]
    public function testInitialize_PutsTheTokenFilterThenTheGuardOfThePortalOnceBeforeEveryRouteOfThePortal(string $route): void
    {
        $filters = new Filters($this->productionConfig(), $this->postRequest(), service('response'));
        $filters->initialize($route);

        $before = $filters->getFilters()['before'];

        $this->assertSame(['csrf', 'portalguard'], array_values(array_filter($before, static fn (string $name): bool => in_array($name, ['portalguard', 'csrf'], true))), $route);
        $this->assertNotContains('isLoggedIn', $before, $route);
    }

    #[DataProvider('portalRoutes')]
    public function testInitialize_RunsTheGuardOfThePortalBeforeTheTokenFilterUnderTest(string $route): void
    {
        $config  = new FiltersConfig();
        $filters = new Filters($config, $this->postRequest(), service('response'));
        $filters->initialize($route);

        $this->assertSame(['portalguard', 'csrf'], array_values(array_filter($filters->getFilters()['before'], static fn (string $name): bool => in_array($name, ['portalguard', 'csrf'], true))), $route);
    }

    public function testInitialize_KeepsTheEmployeeFilterAndTheTokenFilterOnTheRoutesOfTheEmployees(): void
    {
        foreach (['customers', 'customers/save/5', 'sales/complete', 'accounting_receivables', 'portalist/x', 'reports/summary_sales'] as $route) {
            $filters = new Filters($this->productionConfig(), $this->postRequest(), service('response'));
            $filters->initialize($route);

            $this->assertContains('isLoggedIn', $filters->getFilters()['before'], $route);
            $this->assertContains('csrf', $filters->getFilters()['before'], $route);
            $this->assertNotContains('portalguard', $filters->getFilters()['before'], $route);
        }
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
