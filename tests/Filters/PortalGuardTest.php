<?php

namespace Tests\Filters;

use App\Libraries\Portal\PortalAccounts;
use App\Libraries\Portal\PortalSession;
use CodeIgniter\Exceptions\PageNotFoundException;
use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\DatabaseTestTrait;
use CodeIgniter\Test\FeatureTestTrait;
use CodeIgniter\Test\TestResponse;
use Config\Database;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Support\AccountingFixtureTrait;
use Tests\Support\EmployeeFixtureTrait;
use Tests\Support\PortalFixtureTrait;

/**
 * What stands in front of every route of the portal: the switch that makes the portal a page that does not
 * exist, the refusal of an employee, the sign-in that every page but the sign-in page needs, the end of a
 * session that is idle, old, of a disabled account, of a changed password or of a deleted customer, and the
 * page that must be visited before any other while the password is a temporary one.
 *
 * @internal
 */
final class PortalGuardTest extends CIUnitTestCase
{
    use DatabaseTestTrait;
    use FeatureTestTrait;
    use AccountingFixtureTrait;
    use EmployeeFixtureTrait;
    use PortalFixtureTrait;

    protected $migrate     = true;
    protected $migrateOnce = true;
    protected $refresh     = false;
    protected $namespace;
    private int $customer;

    /**
     * @var array{account_id: int, username: string, customer_id: int}
     */
    private array $account;

    public static function setUpBeforeClass(): void
    {
        $seeder = Database::seeder('tests');
        $seeder->call('TestDatabaseBootstrapSeeder');
    }

    protected function setUp(): void
    {
        parent::setUp();

        $this->freshSecurity();
        $this->setConfigValue('accounting_enabled', '0');
        $this->switchPortal(true);

        $this->customer = $this->insertCustomer();
        $this->account  = $this->portalAccountOf($this->customer, 'omar');
    }

    protected function tearDown(): void
    {
        $this->cleanPortal();
        $this->removeInsertedEntities();

        parent::tearDown();
    }

    private function visit(string $method, string $path): TestResponse
    {
        $token = config('Security')->tokenName;

        return $method === 'POST' ? $this->post('/' . $path, [$token => 'a1b2c3d4e5f60718293a4b5c6d7e8f90']) : $this->get('/' . $path);
    }

    /**
     * @param array<string, mixed> $more
     */
    private function asCustomer(array $more = [], ?int $now = null): void
    {
        $this->withSession($this->portalSessionOf($this->account, $now) + [config('Security')->tokenName => 'a1b2c3d4e5f60718293a4b5c6d7e8f90'] + $more);
    }

    #[DataProvider('routesProvider')]
    public function testBeforeMakesEveryRouteAPageThatDoesNotExistWhileThePortalIsOff(string $method, string $path): void
    {
        $this->switchPortal(false);
        $this->asCustomer();

        $this->expectException(PageNotFoundException::class);

        $this->visit($method, $path);
    }

    #[DataProvider('routesProvider')]
    public function testBeforeMakesEveryRouteAPageThatDoesNotExistWhileThePortalIsOffEvenForNobody(string $method, string $path): void
    {
        $this->switchPortal(false);
        $this->withSession([]);

        $this->expectException(PageNotFoundException::class);

        $this->visit($method, $path);
    }

    public function testBeforeTreatsAMissingSettingAsOff(): void
    {
        $this->accountingDb()->table('app_config')->where('key', 'portal_enabled')->delete();
        $this->asCustomer();

        $this->expectException(PageNotFoundException::class);

        $this->get('/portal/statement');
    }

    #[DataProvider('routesProvider')]
    public function testBeforeRefusesASessionThatHoldsAnEmployeeOnEveryRoute(string $method, string $path): void
    {
        $this->withSession(['person_id' => $this->employeeWithGrants(['customers']), 'menu_group' => 'office', config('Security')->tokenName => 'a1b2c3d4e5f60718293a4b5c6d7e8f90']);

        $response = $this->visit($method, $path);

        $this->assertSame(403, $response->response()->getStatusCode());
        $this->assertStringContainsString(lang('Portal.refused_employee'), $response->response()->getBody());
    }

    /**
     * @return array<string, array{0: string, 1: string}>
     */
    public static function routesProvider(): iterable
    {
        return [
            'index GET'            => ['GET', 'portal'],
            'login GET'            => ['GET', 'portal/login'],
            'login POST'           => ['POST', 'portal/login'],
            'logout POST'          => ['POST', 'portal/logout'],
            'password GET'         => ['GET', 'portal/password'],
            'password POST'        => ['POST', 'portal/password'],
            'statement GET'        => ['GET', 'portal/statement'],
            'invoices GET'         => ['GET', 'portal/invoices'],
            'credit notes GET'     => ['GET', 'portal/credit_notes'],
            'payments GET'         => ['GET', 'portal/payments'],
            'invoice GET'          => ['GET', 'portal/invoice/1'],
            'invoice file GET'     => ['GET', 'portal/invoice/1/pdf'],
            'credit note GET'      => ['GET', 'portal/credit_note/1'],
            'credit note file GET' => ['GET', 'portal/credit_note/1/pdf'],
            'payment GET'          => ['GET', 'portal/payment/1'],
            'payment file GET'     => ['GET', 'portal/payment/1/pdf'],
        ];
    }

    public function testBeforeRefusesASessionThatHoldsAnEmployeeAndACustomerAtOnce(): void
    {
        $this->asCustomer(['person_id' => $this->employeeWithGrants(['customers'])]);

        $this->assertSame(403, $this->get('/portal/statement')->response()->getStatusCode());
    }

    #[DataProvider('protectedRoutesProvider')]
    public function testBeforeSendsAVisitorWhoIsNotSignedInToTheSignInPageFromEveryOtherRoute(string $method, string $path): void
    {
        $this->withSession([config('Security')->tokenName => 'a1b2c3d4e5f60718293a4b5c6d7e8f90']);

        $response = $this->visit($method, $path);

        $this->assertStringContainsString('portal/login', (string) $response->getRedirectUrl());
    }

    public function testBeforeAnswersAnAjaxRequestOfAVisitorWhoIsNotSignedInWithAnUnauthorisedStatus(): void
    {
        $this->withSession([]);

        $response = $this->withHeaders(['X-Requested-With' => 'XMLHttpRequest'])->get('/portal/statement');

        $this->assertSame(401, $response->response()->getStatusCode());
        $this->assertFalse(json_decode($response->response()->getBody(), true)['success']);
    }

    public function testBeforeLeavesTheSignInPageOpenToAVisitorWhoIsNotSignedIn(): void
    {
        $this->withSession([]);

        $this->get('/portal/login')->assertStatus(200);
    }

    public function testBeforeAppliesToTheMethodsThatAreReachedByTheirOwnNameWhateverTheCaseOfThePath(): void
    {
        $this->withSession([]);

        foreach (['/portal/getStatement', '/Portal/getStatement', '/PORTAL/getInvoices', '/portal/postLogout'] as $path) {
            try {
                $response = $this->get($path);
            } catch (PageNotFoundException) {
                continue;
            }

            $this->assertStringContainsString('portal/login', (string) $response->getRedirectUrl(), $path);
        }

        $this->addToAssertionCount(1);
    }

    public function testBeforeLetsASignedInCustomerThrough(): void
    {
        $this->asCustomer();

        $this->get('/portal/statement')->assertStatus(200);
    }

    public function testBeforeEndsASessionThatHasBeenIdleForMoreThanHalfAnHour(): void
    {
        $this->asCustomer([], time() - PortalSession::IDLE_SECONDS - 5);

        $response = $this->get('/portal/statement');

        $this->assertStringContainsString('portal/login', (string) $response->getRedirectUrl());
        $this->assertArrayNotHasKey(PortalSession::KEY_CUSTOMER, $_SESSION);
    }

    public function testBeforeKeepsASessionThatHasBeenIdleForLessThanHalfAnHour(): void
    {
        $this->asCustomer([], time() - PortalSession::IDLE_SECONDS + 60);

        $this->get('/portal/statement')->assertStatus(200);
    }

    public function testBeforeEndsASessionOlderThanEightHoursEvenWhenItIsActive(): void
    {
        $session                             = $this->portalSessionOf($this->account);
        $session[PortalSession::KEY_STARTED] = time() - PortalSession::MAX_SECONDS - 5;
        $this->withSession($session);

        $this->assertStringContainsString('portal/login', (string) $this->get('/portal/statement')->getRedirectUrl());
    }

    public function testBeforeRenewsTheActivityTimeOfASessionThatIsUsed(): void
    {
        $this->asCustomer([], time() - 600);

        $this->get('/portal/statement');

        $this->assertGreaterThan(time() - 10, $_SESSION[PortalSession::KEY_ACTIVE]);
    }

    public function testBeforeEndsTheSessionOfAnAccountThatWasDisabledAtOnce(): void
    {
        $this->asCustomer();
        (new PortalAccounts())->setActive($this->account['account_id'], false, $this->portalManager());

        $this->assertStringContainsString('portal/login', (string) $this->get('/portal/statement')->getRedirectUrl());
    }

    public function testBeforeEndsTheSessionWhenAnEmployeeResetTheOrTheCustomerChangedThePassword(): void
    {
        $this->asCustomer();
        (new PortalAccounts())->resetPassword($this->account['account_id'], $this->portalManager());

        $this->assertStringContainsString('portal/login', (string) $this->get('/portal/statement')->getRedirectUrl());
    }

    public function testBeforeEndsTheSessionOfACustomerWhoWasDeleted(): void
    {
        $this->asCustomer();
        $this->accountingDb()->table('customers')->where('person_id', $this->customer)->update(['deleted' => 1]);

        $this->assertStringContainsString('portal/login', (string) $this->get('/portal/statement')->getRedirectUrl());
    }

    public function testBeforeEndsTheSessionWhoseAccountIsGone(): void
    {
        $this->asCustomer();
        $this->accountingDb()->table('portal_accounts')->where('account_id', $this->account['account_id'])->delete();

        $this->assertStringContainsString('portal/login', (string) $this->get('/portal/statement')->getRedirectUrl());
    }

    public function testBeforeRefusesASessionThatNamesACustomerThatIsNotTheOneOfItsAccount(): void
    {
        $other                                = $this->insertCustomer('Hind', 'Saad');
        $session                              = $this->portalSessionOf($this->account);
        $session[PortalSession::KEY_CUSTOMER] = $other;
        $this->withSession($session);

        $this->assertStringContainsString('portal/login', (string) $this->get('/portal/statement')->getRedirectUrl());
    }

    public function testBeforeRefusesASessionWithOnlyTheCustomerAndNoAccount(): void
    {
        $this->withSession([PortalSession::KEY_CUSTOMER => $this->customer, PortalSession::KEY_STARTED => time(), PortalSession::KEY_ACTIVE => time()]);

        $this->assertStringContainsString('portal/login', (string) $this->get('/portal/statement')->getRedirectUrl());
    }

    public function testBeforeRefusesASessionWhoseKeysAreNotWholeNumbers(): void
    {
        $session                              = $this->portalSessionOf($this->account);
        $session[PortalSession::KEY_CUSTOMER] = (string) $this->customer;
        $this->withSession($session);

        $this->assertStringContainsString('portal/login', (string) $this->get('/portal/statement')->getRedirectUrl());
    }

    #[DataProvider('protectedRoutesProvider')]
    public function testBeforeSendsACustomerWhoMustChangeThePasswordToTheChangePageFromEveryOtherRoute(string $method, string $path): void
    {
        $this->accountingDb()->table('portal_accounts')->where('account_id', $this->account['account_id'])->update(['must_change' => 1]);
        $this->asCustomer();

        $response = $this->visit($method, $path);

        if (in_array($path, ['portal/password', 'portal/logout'], true)) {
            $this->assertStringNotContainsString('portal/password', (string) ($path === 'portal/password' ? '' : $response->getRedirectUrl()));
            $this->assertNotSame(401, $response->response()->getStatusCode());

            return;
        }

        $this->assertStringContainsString('portal/password', (string) $response->getRedirectUrl());
    }

    /**
     * @return array<string, array{0: string, 1: string}>
     */
    public static function protectedRoutesProvider(): iterable
    {
        return array_filter(self::routesProvider(), static fn (array $route): bool => $route[1] !== 'portal/login');
    }

    public function testBeforeLetsACustomerWhoMustChangeThePasswordReachTheChangePage(): void
    {
        $this->accountingDb()->table('portal_accounts')->where('account_id', $this->account['account_id'])->update(['must_change' => 1]);
        $this->asCustomer();

        $this->get('/portal/password')->assertStatus(200);
    }

    public function testBeforeLetsACustomerWhoMustChangeThePasswordSignOut(): void
    {
        $this->accountingDb()->table('portal_accounts')->where('account_id', $this->account['account_id'])->update(['must_change' => 1]);
        $this->asCustomer();

        $this->assertStringContainsString('portal/login', (string) $this->visit('POST', 'portal/logout')->getRedirectUrl());
    }

    public function testAfter_AsksTheBrowserAndEveryCacheOnTheWayToKeepNoCopyOfAPageOfThePortal(): void
    {
        $this->asCustomer();

        $response = $this->get('/portal/statement');

        $this->assertStringContainsString('no-store', $response->response()->getHeaderLine('Cache-Control'));
        $this->assertStringContainsString('private', $response->response()->getHeaderLine('Cache-Control'));
        $this->assertSame('no-cache', $response->response()->getHeaderLine('Pragma'));
    }

    public function testAfter_DoesNotTouchTheCachingOfThePagesOfTheEmployees(): void
    {
        $this->withSession(['person_id' => $this->employeeWithGrants(['customers']), 'menu_group' => 'office']);

        $response = $this->get('/customers');

        $this->assertStringNotContainsString('no-store', $response->response()->getHeaderLine('Cache-Control'));
    }
}
