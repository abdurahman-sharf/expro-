<?php

namespace Tests\Filters;

use App\Filters\TransportHeaders;
use CodeIgniter\Config\Factories;
use CodeIgniter\HTTP\Response;
use CodeIgniter\Test\CIUnitTestCase;

class TransportHeadersTest extends CIUnitTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        putenv('ALLOWED_HOSTNAMES=shop.example.com');
        $_SERVER['HTTP_HOST'] = 'shop.example.com';
        unset($_SERVER['HTTPS'], $_ENV['FORCE_HTTPS']);
        Factories::reset('config');
    }

    protected function tearDown(): void
    {
        putenv('ALLOWED_HOSTNAMES');
        unset($_SERVER['HTTP_HOST'], $_SERVER['HTTPS'], $_ENV['FORCE_HTTPS']);
        Factories::reset('config');
        parent::tearDown();
    }

    private function respond(): Response
    {
        $response = service('response', null, false)->setHeader('X-Powered-By', 'PHP/8.3');

        (new TransportHeaders())->after(service('incomingrequest', null, false), $response);

        return $response;
    }

    public function testAfter_SendsStrictTransportSecurityForAYearWhenThePageIsServedOverHttps(): void
    {
        $_SERVER['HTTPS'] = 'on';
        Factories::reset('config');

        $this->assertSame('max-age=31536000', $this->respond()->getHeaderLine('Strict-Transport-Security'));
    }

    public function testAfter_RemovesThePoweredByHeaderWhenThePageIsServedOverHttps(): void
    {
        $_SERVER['HTTPS'] = 'on';
        Factories::reset('config');

        $this->assertFalse($this->respond()->hasHeader('X-Powered-By'));
    }

    public function testAfter_DoesNotAskBrowsersToRememberHttpsForTheSubdomains(): void
    {
        $_SERVER['HTTPS'] = 'on';
        Factories::reset('config');

        $this->assertStringNotContainsString('includeSubDomains', $this->respond()->getHeaderLine('Strict-Transport-Security'));
    }

    public function testAfter_ChangesNothingWhenThePageIsServedOverHttp(): void
    {
        $response = $this->respond();

        $this->assertFalse($response->hasHeader('Strict-Transport-Security'));
        $this->assertSame('PHP/8.3', $response->getHeaderLine('X-Powered-By'));
    }

    public function testBefore_ReturnsNothingSoTheRequestGoesOn(): void
    {
        $_SERVER['HTTPS'] = 'on';
        Factories::reset('config');

        $this->assertNull((new TransportHeaders())->before(service('incomingrequest', null, false)));
    }
}
