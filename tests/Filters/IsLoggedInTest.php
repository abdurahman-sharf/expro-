<?php

namespace Tests\Filters;

use App\Filters\IsLoggedIn;
use App\Libraries\Portal\PortalSession;
use CodeIgniter\HTTP\Exceptions\RedirectException;
use CodeIgniter\HTTP\IncomingRequest;
use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\DatabaseTestTrait;
use CodeIgniter\Test\FeatureTestTrait;
use Config\Database;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Support\AccountingFixtureTrait;
use Tests\Support\EmployeeFixtureTrait;

/**
 * The filter that every page of the employees passes: an employee who is signed in is let through, and a
 * session that holds a customer of the portal is never taken for an employee, whatever else it holds.
 *
 * @internal
 */
final class IsLoggedInTest extends CIUnitTestCase
{
    use DatabaseTestTrait;
    use FeatureTestTrait;
    use AccountingFixtureTrait;
    use EmployeeFixtureTrait;

    protected $migrate     = true;
    protected $migrateOnce = true;
    protected $refresh     = false;
    protected $namespace;

    public static function setUpBeforeClass(): void
    {
        $seeder = Database::seeder('tests');
        $seeder->call('TestDatabaseBootstrapSeeder');
    }

    protected function setUp(): void
    {
        parent::setUp();

        $_SESSION = [];
    }

    protected function tearDown(): void
    {
        $_SESSION = [];
        $this->removeInsertedEntities();

        parent::tearDown();
    }

    private function request(bool $ajax = false): IncomingRequest
    {
        $request = service('incomingrequest', null, false);

        return $ajax ? $request->setHeader('X-Requested-With', 'XMLHttpRequest') : $request;
    }

    /**
     * @return array<string, int>
     */
    private function portalKeys(): array
    {
        return [PortalSession::KEY_CUSTOMER => 5, PortalSession::KEY_ACCOUNT => 7, PortalSession::KEY_VERSION => 1];
    }

    public function testBefore_LetsAnEmployeeWhoIsSignedInThrough(): void
    {
        $_SESSION = ['person_id' => $this->createEmployee()];

        $this->assertNull((new IsLoggedIn())->before($this->request()));
    }

    public function testBefore_SendsAVisitorWhoIsNotSignedInToTheEmployeeSignIn(): void
    {
        $this->expectException(RedirectException::class);

        (new IsLoggedIn())->before($this->request());
    }

    public function testBefore_NeverTakesTheSessionOfACustomerForAnEmployee(): void
    {
        $_SESSION = $this->portalKeys();

        $this->expectException(RedirectException::class);

        (new IsLoggedIn())->before($this->request());
    }

    public function testBefore_RefusesASessionThatHoldsAnEmployeeAndACustomerAtOnce(): void
    {
        $_SESSION = ['person_id' => $this->createEmployee()] + $this->portalKeys();

        $this->expectException(RedirectException::class);

        (new IsLoggedIn())->before($this->request());
    }

    public function testBefore_AnswersAnAjaxRequestWithTheSessionOfACustomerWithAnUnauthorisedStatus(): void
    {
        $_SESSION = ['person_id' => $this->createEmployee()] + $this->portalKeys();

        $response = (new IsLoggedIn())->before($this->request(true));

        $this->assertSame(401, $response->getStatusCode());
    }

    /**
     * @return array<string, array{0: string, 1: string}>
     */
    public static function routesWhoseLastSegmentIsFree(): array
    {
        return [
            'a view that ends with migrate'      => ['GET', 'branches/view/migrate'],
            'a view whose key ends with migrate' => ['GET', 'roles/view/xmigrate'],
            'a save that ends with migrate'      => ['POST', 'branches/save/xmigrate'],
            'a chart view that ends with it'     => ['GET', 'accounting_chart/view/migrate'],
        ];
    }

    #[DataProvider('routesWhoseLastSegmentIsFree')]
    public function testBefore_SendsAVisitorWhoAsksForARealRouteThatEndsWithMigrateToTheSignIn(string $method, string $path): void
    {
        $result = $method === 'POST' ? $this->post($path, []) : $this->get($path);

        $result->assertRedirect();
        $this->assertStringEndsWith('login', $result->getRedirectUrl(), $path);
    }

    public function testBefore_LetsAVisitorReachTheSignInItself(): void
    {
        $result = $this->get('login');

        $result->assertStatus(200);
    }
}
