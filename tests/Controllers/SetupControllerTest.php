<?php

namespace Tests\Controllers;

use App\Libraries\Setup\FirstRun;
use App\Libraries\Setup\InstallState;
use App\Models\Employee;
use CodeIgniter\Exceptions\PageNotFoundException;
use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\DatabaseTestTrait;
use CodeIgniter\Test\FeatureTestTrait;
use Config\Database;
use Tests\Support\AccountingFixtureTrait;
use Tests\Support\FirstRunFixtureTrait;
use Tests\Support\InstallStateFixtureTrait;

class SetupControllerTest extends CIUnitTestCase
{
    use DatabaseTestTrait;
    use FeatureTestTrait;
    use AccountingFixtureTrait;
    use InstallStateFixtureTrait;
    use FirstRunFixtureTrait;

    protected $migrate     = true;
    protected $migrateOnce = true;
    protected $refresh     = false;
    protected $namespace   = null;

    public static function setUpBeforeClass(): void
    {
        $seeder = Database::seeder('tests');
        $seeder->call('TestDatabaseBootstrapSeeder');
    }

    protected function setUp(): void
    {
        parent::setUp();

        $this->prepareFirstRun();
        $_SESSION = [];
    }

    protected function tearDown(): void
    {
        $_SESSION = [];
        $this->finishFirstRun();

        parent::tearDown();
    }

    /**
     * @return array<string, mixed>
     */
    private function owner(): array
    {
        return ['person_id' => InstallState::OWNER_ID, Employee::SETUP_PENDING_KEY => true];
    }

    /**
     * @param array<string, string> $changes
     *
     * @return array<string, string>
     */
    private function language(array $changes = []): array
    {
        return $changes + ['language' => 'en:english', 'country' => 'EG', 'timezone' => 'Africa/Cairo', 'currency_code' => 'EGP', 'currency_symbol' => 'E£', 'currency_decimals' => '2'];
    }

    private function saveTheFirstFourSteps(): void
    {
        $run = new FirstRun();

        $run->saveLanguage($this->language(), InstallState::OWNER_ID);
        $run->saveCompany(['company' => 'Nile Trading', 'address' => '', 'phone' => '', 'email' => '', 'tax_id' => ''], InstallState::OWNER_ID);
        $run->saveBranch(['branch_name' => 'Cairo branch', 'location_name' => 'Cairo store'], InstallState::OWNER_ID);
        $run->saveTax(['tax_name' => 'VAT', 'tax_rate' => '14', 'tax_included' => '1'], InstallState::OWNER_ID);
    }

    public function testGetIndex_ShowsTheFirstStepToTheOwner(): void
    {
        $response = $this->withSession($this->owner())->get('setup');

        $response->assertStatus(200);
        $response->assertSee('data-step="language"');
        $response->assertDontSee('data-step="company"');
    }

    public function testGetIndex_StartsFromArabicAndEgyptUntilTheFirstStepIsSaved(): void
    {
        $response = $this->withSession($this->owner())->get('setup');

        $body = (string) $response->response()->getBody();

        $this->assertStringContainsString('<option value="ar-EG&#x3A;arabic" selected>', $body);
        $this->assertStringContainsString('<option value="EG" data-currency="EGP"', $body);
        $response->assertSee('value="EGP"');
    }

    public function testGetIndex_OpensTheNextStepOnceOneIsSaved(): void
    {
        (new FirstRun())->saveLanguage($this->language(), InstallState::OWNER_ID);

        $response = $this->withSession($this->owner())->get('setup');

        $response->assertSee('data-step="company"');
        $response->assertDontSee('data-step="language"');
    }

    public function testGetIndex_LetsTheOwnerGoBackToAStepThatIsDone(): void
    {
        (new FirstRun())->saveLanguage($this->language(), InstallState::OWNER_ID);

        $response = $this->withSession($this->owner())->get('setup?step=language');

        $response->assertSee('data-step="language"');
        $response->assertSee('value="EGP"');
    }

    public function testGetIndex_DoesNotOpenAStepThatComesBeforeItsTurn(): void
    {
        $response = $this->withSession($this->owner())->get('setup?step=tax');

        $response->assertSee('data-step="language"');
        $response->assertDontSee('data-step="tax"');
    }

    public function testGetIndex_ShowsTheActivationWizardAfterTheFourStepsWithTheCurrencyFixed(): void
    {
        $this->saveTheFirstFourSteps();

        $response = $this->withSession($this->owner())->get('setup');

        $response->assertSee('id="activation_wizard"');
        $response->assertSee('id="currency"');
        $response->assertSee('value="EGP"');
        $response->assertSee('readonly');
    }

    public function testGetIndex_AsksTheOwnerToFinishOnceEverythingIsSaved(): void
    {
        $this->saveTheFirstFourSteps();
        $this->activateTheBooks();

        $response = $this->withSession($this->owner())->get('setup');

        $response->assertSee('id="form_finish"');
        $response->assertSee(lang('Setup.finish_ready'));
    }

    public function testGetIndex_MakesAnotherEmployeeWaitForTheOwner(): void
    {
        $response = $this->withSession(['person_id' => $this->manager, Employee::SETUP_PENDING_KEY => true])->get('setup');

        $response->assertStatus(200);
        $response->assertSee(lang('Setup.waiting_for_owner'));
        $response->assertDontSee('data-step=');
    }

    public function testGetIndex_SendsHomeOnceTheInstallationIsSetUp(): void
    {
        $this->setInstallState(InstallState::COMPLETE);

        $response = $this->withSession($this->owner())->get('setup');

        $response->assertRedirect();
        $this->assertStringEndsWith('home', $response->getRedirectUrl());
    }

    public function testPostLanguage_SavesTheStepAndNamesTheNextOne(): void
    {
        $response = $this->withSession($this->owner())->post('setup/language', $this->language());

        $body = json_decode($response->getJSON(), true);

        $this->assertTrue($body['success'], $body['message']);
        $this->assertSame(FirstRun::COMPANY, $body['next']);
        $this->assertSame('EGP', $this->storedValueOf('currency_code'));
    }

    public function testPostCompany_AnswersWhatIsWrongAndSavesNothing(): void
    {
        $response = $this->withSession($this->owner())->post('setup/company', ['company' => '', 'address' => '', 'phone' => '', 'email' => '', 'tax_id' => '']);

        $body = json_decode($response->getJSON(), true);

        $this->assertFalse($body['success']);
        $this->assertSame(lang('Setup.error_company_required', [FirstRun::TEXT_MAX]), $body['message']);
        $this->assertFalse((new FirstRun())->progress()[FirstRun::COMPANY]);
    }

    public function testPostBranch_RefusesAValueThatIsNotText(): void
    {
        $response = $this->withSession($this->owner())->post('setup/branch', ['branch_name' => ['x'], 'location_name' => 'Cairo store']);

        $response->assertStatus(400);
        $this->assertFalse((new FirstRun())->progress()[FirstRun::BRANCH]);
    }

    public function testPostTax_SavesTheTaxAndTheInclusiveFlag(): void
    {
        $response = $this->withSession($this->owner())->post('setup/tax', ['tax_name' => 'VAT', 'tax_rate' => '14', 'tax_included' => '1']);

        $this->assertTrue(json_decode($response->getJSON(), true)['success']);
        $this->assertSame('1', $this->storedValueOf('tax_included'));
    }

    public function testPost_RefusesAnEmployeeWhoIsNotTheOwner(): void
    {
        $response = $this->withSession(['person_id' => $this->manager, Employee::SETUP_PENDING_KEY => true])->post('setup/company', ['company' => 'Intruder']);

        $response->assertStatus(403);
        $this->assertNotSame('Intruder', $this->storedValueOf('company'));
    }

    public function testPost_AnswersNotFoundOnceTheInstallationIsSetUp(): void
    {
        $this->setInstallState(InstallState::COMPLETE);

        $this->expectException(PageNotFoundException::class);

        $this->withSession($this->owner())->post('setup/company', ['company' => 'Late']);
    }

    public function testPostFinish_RefusesWhileTheBooksAreNotOn(): void
    {
        $this->saveTheFirstFourSteps();

        $body = json_decode($this->withSession($this->owner())->post('setup/finish', [])->getJSON(), true);

        $this->assertFalse($body['success']);
        $this->assertStringContainsString(lang('Setup.problem_accounting_not_ready'), $body['message']);
        $this->assertTrue((new InstallState())->needsSetup());
    }

    public function testPostFinish_SetsTheInstallationUpAndFreesTheSession(): void
    {
        $this->saveTheFirstFourSteps();
        $this->activateTheBooks();

        $response = $this->withSession($this->owner())->post('setup/finish', []);
        $body     = json_decode($response->getJSON(), true);

        $this->assertTrue($body['success'], $body['message']);
        $this->assertSame(InstallState::COMPLETE, (new InstallState())->state());
        $this->assertNull(session()->get(Employee::SETUP_PENDING_KEY));
    }

    public function testSetupFlow_GivesTheOwnerOfANewInstallationAWorkingHomeAfterTheLastStep(): void
    {
        $this->saveTheFirstFourSteps();
        $this->activateTheBooks();
        $this->withSession($this->owner())->post('setup/finish', []);

        $home = $this->withSession(['person_id' => InstallState::OWNER_ID])->get('home');

        $home->assertStatus(200);
    }

    private function storedValueOf(string $key): string
    {
        $row = $this->accountingDb()->table('app_config')->where('key', $key)->get()->getRow();

        return $row === null ? '' : (string) $row->value;
    }
}
