<?php

namespace Tests\Config;

use CodeIgniter\Config\Factories;
use CodeIgniter\Test\CIUnitTestCase;
use Config\Cookie;

class CookieTest extends CIUnitTestCase
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

    public function testConstruct_MarksTheCookiesSecureWhenThePageIsServedOverHttps(): void
    {
        $_SERVER['HTTPS'] = 'on';
        Factories::reset('config');

        $cookie = new Cookie();

        $this->assertTrue($cookie->secure);
        $this->assertTrue($cookie->httponly);
        $this->assertSame('Lax', $cookie->samesite);
    }

    public function testConstruct_MarksTheCookiesSecureWhenHttpsIsDeclaredForAProxy(): void
    {
        $_ENV['FORCE_HTTPS'] = 'true';
        Factories::reset('config');

        $this->assertTrue((new Cookie())->secure);
    }

    public function testConstruct_LeavesTheCookiesOpenToHttpWhenThePageIsServedOverHttp(): void
    {
        $cookie = new Cookie();

        $this->assertFalse($cookie->secure);
        $this->assertTrue($cookie->httponly);
        $this->assertSame('Lax', $cookie->samesite);
    }
}
