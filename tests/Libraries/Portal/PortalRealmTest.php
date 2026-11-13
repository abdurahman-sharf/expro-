<?php

namespace Tests\Libraries\Portal;

use App\Libraries\Portal\PortalRealm;
use CodeIgniter\Cookie\Cookie as CookieValue;
use CodeIgniter\HTTP\IncomingRequest;
use CodeIgniter\HTTP\SiteURI;
use CodeIgniter\HTTP\UserAgent;
use CodeIgniter\Test\CIUnitTestCase;
use Config\App;
use Config\Cookie;
use Config\Services;
use Config\Session;
use PHPUnit\Framework\Attributes\DataProvider;

/**
 * @internal
 */
final class PortalRealmTest extends CIUnitTestCase
{
    protected function tearDown(): void
    {
        Services::resetSingle('request');

        parent::tearDown();
    }

    #[DataProvider('provideIsPortalPath_RecognisesThePrefixOfThePortalOnly')]
    public function testIsPortalPath_RecognisesThePrefixOfThePortalOnly(string $path, bool $expected): void
    {
        $this->assertSame($expected, PortalRealm::isPortalPath($path));
    }

    /**
     * @return array<string, array{0: string, 1: bool}>
     */
    public static function provideIsPortalPath_RecognisesThePrefixOfThePortalOnly(): iterable
    {
        return [
            'the portal'                       => ['portal', true],
            'a page of the portal'             => ['portal/statement', true],
            'with slashes around'              => ['/portal/login/', true],
            'in capitals'                      => ['PORTAL/Invoices', true],
            'a deep page'                      => ['portal/invoice/5/pdf', true],
            'a path that only starts the same' => ['portals', false],
            'another prefix'                   => ['customers', false],
            'a path that holds it'             => ['reports/portal', false],
            'an employee login'                => ['login', false],
            'empty'                            => ['', false],
        ];
    }

    public function testApply_GivesThePortalACookieOfItsOwnNameAShortLifeAndAStrictSecureCookie(): void
    {
        $session          = new Session();
        $cookie           = new Cookie();
        $cookie->samesite = CookieValue::SAMESITE_LAX;
        $cookie->secure   = false;

        PortalRealm::apply($session, $cookie, 'portal/login', true);

        $this->assertSame(PortalRealm::COOKIE, $session->cookieName);
        $this->assertSame(PortalRealm::SESSION_SECONDS, $session->expiration);
        $this->assertSame(CookieValue::SAMESITE_STRICT, $cookie->samesite);
        $this->assertTrue($cookie->secure);
    }

    public function testApply_DoesNotMarkTheCookieSecureOnAPlainRequestUnlessTheConfigurationAsksForIt(): void
    {
        $cookie         = new Cookie();
        $cookie->secure = false;

        PortalRealm::apply(new Session(), $cookie, 'portal', false);
        $this->assertFalse($cookie->secure);

        $cookie->secure = true;
        PortalRealm::apply(new Session(), $cookie, 'portal', false);
        $this->assertTrue($cookie->secure);
    }

    public function testApply_LeavesTheSessionOfTheEmployeesAsItIs(): void
    {
        $session = new Session();
        $cookie  = new Cookie();
        $name    = $session->cookieName;
        $life    = $session->expiration;
        $same    = $cookie->samesite;

        PortalRealm::apply($session, $cookie, 'customers/view/5', true);

        $this->assertSame([$name, $life, $same], [$session->cookieName, $session->expiration, $cookie->samesite]);
        $this->assertNotSame(PortalRealm::COOKIE, $session->cookieName);
    }

    public function testApply_GivesThePortalACookieNameOtherThanTheOneOfTheEmployees(): void
    {
        $this->assertNotSame((new Session())->cookieName, PortalRealm::COOKIE);
    }

    public function testConfigSession_TakesTheRealmOfThePortalWhenTheRequestIsForAPageOfThePortal(): void
    {
        $config = config(App::class);
        Services::injectMock('request', new IncomingRequest($config, new SiteURI($config, 'portal/statement'), null, new UserAgent()));

        $this->assertSame('portal/statement', PortalRealm::currentPath());
        $this->assertSame(PortalRealm::COOKIE, (new Session())->cookieName);
    }

    public function testConfigSession_KeepsTheEmployeeCookieWhenTheRequestIsForAnotherPage(): void
    {
        $config = config(App::class);
        Services::injectMock('request', new IncomingRequest($config, new SiteURI($config, 'customers'), null, new UserAgent()));

        $this->assertNotSame(PortalRealm::COOKIE, (new Session())->cookieName);
    }
}
