<?php

namespace Tests\Filters;

use App\Filters\IsLoggedIn;
use App\Libraries\Portal\PortalSession;
use App\Libraries\Security\PasswordPolicy;
use App\Models\Employee;
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
            'a form that ends with migrate'      => ['GET', 'accounting_cheques/form/migrate'],
            'a template form whose key ends so'  => ['GET', 'stock_units/templateForm/xmigrate'],
            'a save that ends with migrate'      => ['POST', 'accounting_cheques/save/xmigrate'],
            'a report that ends with it'         => ['GET', 'accounting_cheques/report/migrate'],
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
    private function employeeOnTheInstallerPassword(string $username = 'forced-user'): int
    {
        return $this->createEmployee(
            username: $username . '-' . uniqid(),
            grants: [['permission_id' => 'home', 'menu_group' => 'home'], ['permission_id' => 'office', 'menu_group' => 'home'], ['permission_id' => 'customers', 'menu_group' => 'home']],
            employee: ['password' => password_hash(PasswordPolicy::INSTALLER_DEFAULT, PASSWORD_DEFAULT)],
        );
    }

    /**
     * @return array<string, mixed>
     */
    private function forcedSession(int $personId): array
    {
        return ['person_id' => $personId, Employee::MUST_CHANGE_KEY => true];
    }

    public function testBefore_SendsAnEmployeeWhoMustChangeThePasswordToThePageThatChangesIt(): void
    {
        $personId = $this->employeeOnTheInstallerPassword();

        $result = $this->withSession($this->forcedSession($personId))->get('customers');

        $result->assertRedirect();
        $this->assertStringEndsWith('home/mustChangePassword', $result->getRedirectUrl());
    }

    public function testBefore_AnswersAnAjaxRequestOfAnEmployeeWhoMustChangeThePasswordWithAForbiddenStatus(): void
    {
        $personId = $this->employeeOnTheInstallerPassword();

        $result = $this->withSession($this->forcedSession($personId))->withHeaders(['X-Requested-With' => 'XMLHttpRequest'])->get('customers/search');

        $result->assertStatus(403);
        $this->assertSame(lang('Employees.must_change_password'), json_decode($result->getJSON(), true)['message']);
    }

    public function testBefore_LetsAnEmployeeWhoMustChangeThePasswordReachThePageThatChangesIt(): void
    {
        $personId = $this->employeeOnTheInstallerPassword();

        $result = $this->withSession($this->forcedSession($personId))->get('home/mustChangePassword');

        $result->assertStatus(200);
        $result->assertSee(lang('Employees.must_change_password'));
    }

    public function testBefore_LetsAnEmployeeWhoMustChangeThePasswordSignOut(): void
    {
        $personId = $this->employeeOnTheInstallerPassword();

        $result = $this->withSession($this->forcedSession($personId))->get('home/logout');

        $result->assertRedirect();
        $this->assertStringEndsWith('login', $result->getRedirectUrl());
    }

    public function testBefore_LetsAnEmployeeWhoMustChangeThePasswordChangeItAndThenGoWhereHeWants(): void
    {
        $personId = $this->employeeOnTheInstallerPassword('changer');
        $username = (string) $this->db->table('employees')->where('person_id', $personId)->get()->getRow()->username;

        $changed = json_decode($this->withSession($this->forcedSession($personId))->post('home/save', [
            'username'         => $username,
            'current_password' => PasswordPolicy::INSTALLER_DEFAULT,
            'password'         => 'a-better-password',
        ])->getJSON(), true);

        $this->assertTrue($changed['success'], $changed['message']);
        $this->assertNull(session()->get(Employee::MUST_CHANGE_KEY));

        $result = $this->withSession(['person_id' => $personId])->get('customers');

        $result->assertStatus(200);
    }

    public function testBefore_StopsHoldingBackAnEmployeeWhosePasswordIsNoLongerTheInstallerOne(): void
    {
        $personId = $this->createEmployee(grants: [['permission_id' => 'home', 'menu_group' => 'home'], ['permission_id' => 'office', 'menu_group' => 'home'], ['permission_id' => 'customers', 'menu_group' => 'home']]);

        $result = $this->withSession($this->forcedSession($personId))->get('customers');

        $result->assertStatus(200);
    }

    public function testBefore_LeavesAnEmployeeWithoutTheFlagAlone(): void
    {
        $personId = $this->employeeOnTheInstallerPassword();

        $result = $this->withSession(['person_id' => $personId])->get('customers');

        $result->assertStatus(200);
    }
}
