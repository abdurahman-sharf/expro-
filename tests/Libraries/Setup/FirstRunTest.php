<?php

namespace Tests\Libraries\Setup;

use App\Libraries\Accounting\AccountingSettings;
use App\Libraries\Accounting\LockOutcome;
use App\Libraries\Accounting\NamedLock;
use App\Libraries\Setup\FirstRun;
use App\Libraries\Setup\FirstRunException;
use App\Libraries\Setup\InstallState;
use App\Models\Stock_location;
use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\DatabaseTestTrait;
use Config\Database;
use Tests\Support\AccountingFixtureTrait;
use Tests\Support\FirstRunFixtureTrait;
use Tests\Support\InstallStateFixtureTrait;

class FirstRunTest extends CIUnitTestCase
{
    use DatabaseTestTrait;
    use AccountingFixtureTrait;
    use InstallStateFixtureTrait;
    use FirstRunFixtureTrait;

    protected $migrate     = true;
    protected $migrateOnce = true;
    protected $refresh     = false;
    protected $namespace   = null;
    private FirstRun $run;

    public static function setUpBeforeClass(): void
    {
        $seeder = Database::seeder('tests');
        $seeder->call('TestDatabaseBootstrapSeeder');
    }

    protected function setUp(): void
    {
        parent::setUp();

        $this->prepareFirstRun();

        $this->run = new FirstRun();
    }

    protected function tearDown(): void
    {
        $this->finishFirstRun();

        parent::tearDown();
    }

    /**
     * @param array<string, string> $changes
     *
     * @return array<string, string>
     */
    private function languageInput(array $changes = []): array
    {
        return $changes + ['language' => 'en:english', 'country' => 'EG', 'timezone' => 'Africa/Cairo', 'currency_code' => 'EGP', 'currency_symbol' => 'E£', 'currency_decimals' => '2'];
    }

    /**
     * @param array<string, string> $changes
     *
     * @return array<string, string>
     */
    private function companyInput(array $changes = []): array
    {
        return $changes + ['company' => 'Nile Trading', 'address' => '1 Corniche, Cairo', 'phone' => '+20 2 1234 5678', 'email' => 'Office@Nile.Example', 'tax_id' => '123-456-789'];
    }

    /**
     * @param array<string, string> $changes
     *
     * @return array<string, string>
     */
    private function branchInput(array $changes = []): array
    {
        return $changes + ['branch_name' => 'Cairo branch', 'location_name' => 'Cairo store'];
    }

    /**
     * @param array<string, string> $changes
     *
     * @return array<string, string>
     */
    private function taxInput(array $changes = []): array
    {
        return $changes + ['tax_name' => 'VAT', 'tax_rate' => '14', 'tax_included' => '1'];
    }

    private function saveEverythingButTheBooks(): void
    {
        $this->run->saveLanguage($this->languageInput(), $this->manager);
        $this->run->saveCompany($this->companyInput(), $this->manager);
        $this->run->saveBranch($this->branchInput(), $this->manager);
        $this->run->saveTax($this->taxInput(), $this->manager);
    }

    /**
     * @return array{companies: int, branches: int, locations: int, defaults: int}
     */
    private function rowCounts(): array
    {
        $db = $this->accountingDb();

        return [
            'companies' => $db->table('companies')->countAllResults(),
            'branches'  => $db->table('branches')->countAllResults(),
            'locations' => $db->table('stock_locations')->where('deleted', 0)->countAllResults(),
            'defaults'  => $db->table('branches')->where('is_default', 1)->countAllResults(),
        ];
    }

    private function storedValue(string $key): string
    {
        $row = $this->accountingDb()->table('app_config')->where('key', $key)->get()->getRow();

        return $row === null ? '' : (string) $row->value;
    }

    private function assertRefusedWith(string $key, callable $action): void
    {
        try {
            $action();
            $this->fail("Expected the refusal {$key}.");
        } catch (FirstRunException $failure) {
            $this->assertSame($key, $failure->key);
        }
    }

    public function testSaveLanguage_WritesTheLocaleAndMarksTheStepDone(): void
    {
        $this->run->saveLanguage($this->languageInput(), $this->manager);

        $this->assertSame('EGP', $this->storedValue('currency_code'));
        $this->assertSame('2', $this->storedValue('currency_decimals'));
        $this->assertSame('Africa/Cairo', $this->storedValue('timezone'));
        $this->assertSame('en', $this->storedValue('language_code'));
        $this->assertSame('eg', $this->storedValue('country_codes'));
        $this->assertTrue($this->run->progress()[FirstRun::LANGUAGE]);
        $this->assertSame(FirstRun::COMPANY, $this->run->currentStep());
    }

    public function testSaveLanguage_RejectsAnUnknownLanguageCountryTimezoneAndCurrency(): void
    {
        $this->assertRefusedWith('language_invalid', fn () => $this->run->saveLanguage($this->languageInput(['language' => 'xx:nothing']), $this->manager));
        $this->assertRefusedWith('country_invalid', fn () => $this->run->saveLanguage($this->languageInput(['country' => 'ZZ']), $this->manager));
        $this->assertRefusedWith('timezone_invalid', fn () => $this->run->saveLanguage($this->languageInput(['timezone' => 'Mars/Olympus']), $this->manager));
        $this->assertRefusedWith('currency_invalid', fn () => $this->run->saveLanguage($this->languageInput(['currency_code' => 'EG']), $this->manager));
        $this->assertRefusedWith('decimals_invalid', fn () => $this->run->saveLanguage($this->languageInput(['currency_decimals' => '9']), $this->manager));
        $this->assertFalse($this->run->progress()[FirstRun::LANGUAGE]);
    }

    public function testSaveLanguage_RefusesAnotherCurrencyOnceTheBooksUseOne(): void
    {
        $this->setConfigValue('accounting_base_currency', 'SAR');

        $this->assertRefusedWith('currency_locked', fn () => $this->run->saveLanguage($this->languageInput(), $this->manager));
        $this->run->saveLanguage($this->languageInput(['currency_code' => 'SAR', 'country' => 'SA', 'timezone' => 'Asia/Riyadh']), $this->manager);

        $this->assertSame('SAR', $this->storedValue('currency_code'));
    }

    public function testSaveCompany_WritesTheCompanyAndTheMainBranchWithoutAddingRows(): void
    {
        $before = $this->rowCounts();

        $this->run->saveCompany($this->companyInput(), $this->manager);

        $db      = $this->accountingDb();
        $company = $db->table('companies')->where('company_id', FirstRun::COMPANY_ID)->get()->getRow();
        $branch  = $db->table('branches')->where('is_default', 1)->get()->getRow();

        $this->assertSame($before, $this->rowCounts());
        $this->assertSame('Nile Trading', $company->name);
        $this->assertSame('123-456-789', $company->tax_id);
        $this->assertSame('123-456-789', $branch->tax_registration);
        $this->assertSame('+20 2 1234 5678', $branch->phone);
        $this->assertSame('Nile Trading', $this->storedValue('company'));
        $this->assertSame('office@nile.example', $this->storedValue('email'));
    }

    public function testSaveCompany_RejectsAMissingNameABadEmailAndABadPhone(): void
    {
        $this->assertRefusedWith('company_required', fn () => $this->run->saveCompany($this->companyInput(['company' => '  ']), $this->manager));
        $this->assertRefusedWith('email_invalid', fn () => $this->run->saveCompany($this->companyInput(['email' => 'not-an-email']), $this->manager));
        $this->assertRefusedWith('phone_invalid', fn () => $this->run->saveCompany($this->companyInput(['phone' => 'call me']), $this->manager));
        $this->assertRefusedWith('tax_id_invalid', fn () => $this->run->saveCompany($this->companyInput(['tax_id' => '<b>1</b>']), $this->manager));
        $this->assertFalse($this->run->progress()[FirstRun::COMPANY]);
    }

    public function testSaveBranch_RenamesTheMainBranchAndTheStockLocationWithoutAddingRows(): void
    {
        $before = $this->rowCounts();

        $this->run->saveBranch($this->branchInput(), $this->manager);

        $db       = $this->accountingDb();
        $branch   = $db->table('branches')->where('is_default', 1)->get()->getRow();
        $location = $db->table('stock_locations')->where('deleted', 0)->where('branch_id', (int) $branch->branch_id)->get()->getRow();

        $this->assertSame($before, $this->rowCounts());
        $this->assertSame('Cairo branch', $branch->name);
        $this->assertSame('Cairo store', $location->location_name);
    }

    public function testSaveBranch_KeepsTheGrantsOfTheOwnerOnTheRenamedLocation(): void
    {
        $this->run->saveBranch($this->branchInput(), $this->manager);

        $this->assertSame(1, $this->accountingDb()->table('permissions')->where('permission_id', 'sales_Cairo_store')->countAllResults());
        $this->assertSame(1, $this->accountingDb()->table('grants')->where('person_id', InstallState::OWNER_ID)->where('permission_id', 'sales_Cairo_store')->countAllResults());
    }

    public function testSaveBranch_RefusesANameAnotherLocationHas(): void
    {
        $other = ['location_name' => 'Second store'];

        model(Stock_location::class)->save_value($other, NEW_ENTRY);

        $name = $this->mainBranchName();

        $this->assertRefusedWith('location_taken', fn () => $this->run->saveBranch($this->branchInput(['location_name' => 'Second store']), $this->manager));
        $this->assertSame($name, $this->mainBranchName());
        $this->assertFalse($this->run->progress()[FirstRun::BRANCH]);
    }

    private function mainBranchName(): string
    {
        return (string) $this->accountingDb()->table('branches')->where('is_default', 1)->get()->getRow()->name;
    }

    public function testSaveBranch_MakesTheFirstLocationOnlyWhenTheBranchHasNone(): void
    {
        $this->accountingDb()->table('stock_locations')->update(['deleted' => 1]);

        $this->run->saveBranch($this->branchInput(), $this->manager);

        $active = $this->accountingDb()->table('stock_locations')->where('deleted', 0)->get()->getResult();

        $this->assertCount(1, $active);
        $this->assertSame('Cairo store', $active[0]->location_name);
        $this->assertSame(1, (int) $active[0]->branch_id);
    }

    public function testSaveTax_WritesTheDefaultTaxAndTheInclusiveFlag(): void
    {
        $this->run->saveTax($this->taxInput(), $this->manager);

        $this->assertSame('VAT', $this->storedValue('default_tax_1_name'));
        $this->assertSame('14', $this->storedValue('default_tax_1_rate'));
        $this->assertSame('1', $this->storedValue('tax_included'));

        $this->run->saveTax($this->taxInput(['tax_name' => '', 'tax_rate' => '', 'tax_included' => '']), $this->manager);

        $this->assertSame('', $this->storedValue('default_tax_1_rate'));
        $this->assertSame('0', $this->storedValue('tax_included'));
        $this->assertTrue($this->run->progress()[FirstRun::TAX]);
    }

    public function testSaveTax_RejectsARateOutsideZeroToOneHundredAndATaxWithoutAName(): void
    {
        $this->assertRefusedWith('tax_rate_invalid', fn () => $this->run->saveTax($this->taxInput(['tax_rate' => '101']), $this->manager));
        $this->assertRefusedWith('tax_rate_invalid', fn () => $this->run->saveTax($this->taxInput(['tax_rate' => 'abc']), $this->manager));
        $this->assertRefusedWith('tax_name_required', fn () => $this->run->saveTax($this->taxInput(['tax_name' => '']), $this->manager));
        $this->assertFalse($this->run->progress()[FirstRun::TAX]);
    }

    public function testSaveAgain_ChangesNothingButWhatWasEdited(): void
    {
        $this->saveEverythingButTheBooks();

        $first = $this->rowCounts();

        $this->saveEverythingButTheBooks();
        $this->run->saveBranch($this->branchInput(['branch_name' => 'Giza branch']), $this->manager);

        $this->assertSame($first, $this->rowCounts());
        $this->assertSame('Giza branch', $this->mainBranchName());
        $this->assertSame('Cairo store', $this->accountingDb()->table('stock_locations')->where('deleted', 0)->get()->getRow()->location_name);
    }

    public function testProgress_ResumesFromTheSavedStepOfARestoredDatabase(): void
    {
        $this->setConfigValue(FirstRun::PROGRESS_KEY, json_encode([FirstRun::LANGUAGE => true, FirstRun::COMPANY => true]));

        $this->assertSame(FirstRun::BRANCH, (new FirstRun())->currentStep());
    }

    public function testProblems_NamesEveryStepThatWasNotDone(): void
    {
        $problems = $this->run->problems();

        foreach (['language_not_saved', 'company_not_saved', 'branch_not_saved', 'tax_not_saved', 'accounting_not_ready'] as $expected) {
            $this->assertContains($expected, $problems);
        }
    }

    public function testFinish_RefusesWhileAnythingIsMissingAndLeavesTheInstallationInItsSetup(): void
    {
        $this->saveEverythingButTheBooks();

        try {
            $this->run->finish($this->manager);
            $this->fail('The setup was finished without the books.');
        } catch (FirstRunException $failure) {
            $this->assertSame('incomplete', $failure->key);
            $this->assertContains('accounting_not_ready', $failure->context['problems']);
        }

        $this->assertTrue((new InstallState())->needsSetup());
    }

    public function testFinish_SetsTheInstallationUpAfterEveryStepAndTheActivation(): void
    {
        $this->saveEverythingButTheBooks();
        $this->activateTheBooks();

        $this->assertSame([], $this->run->problems());
        $this->assertSame(FirstRun::FINISH, $this->run->currentStep());

        $this->run->finish($this->manager);

        $this->assertSame(InstallState::COMPLETE, (new InstallState())->state());
        $this->assertSame('', $this->storedValue(FirstRun::PROGRESS_KEY));
        $this->assertSame(1, $this->accountingDb()->table('accounting_audit_log')->where('action', 'setup_completed')->countAllResults());
    }

    public function testFinish_RefusesWhenTheCurrencyOfTheScreensIsNotTheCurrencyOfTheBooks(): void
    {
        $this->saveEverythingButTheBooks();
        $this->activateTheBooks();
        $this->setConfigValue('currency_code', 'USD');

        $this->assertContains('currency_mismatch', $this->run->problems());
        $this->assertRefusedWith('incomplete', fn () => $this->run->finish($this->manager));
        $this->assertTrue((new InstallState())->needsSetup());
    }

    public function testSave_AnswersClosedOnceTheInstallationIsSetUp(): void
    {
        $this->setInstallState(InstallState::COMPLETE);

        $this->assertRefusedWith('closed', fn () => $this->run->saveCompany($this->companyInput(), $this->manager));
        $this->assertRefusedWith('closed', fn () => $this->run->finish($this->manager));
        $this->assertNotSame('Nile Trading', $this->storedValue('company'));
    }

    public function testSave_AnswersBusyWhileAnotherRequestHoldsTheSetup(): void
    {
        $other = $this->openSecondConnection();
        $name  = 'ospos_first_run_' . substr(sha1($this->accountingDb()->getDatabase()), 0, 10);
        $lock  = new NamedLock($other, 0);

        $this->assertSame(LockOutcome::Taken, $lock->take($name));

        try {
            $this->assertRefusedWith('busy', fn () => $this->run->saveCompany($this->companyInput(), $this->manager));
            $this->assertFalse($this->run->progress()[FirstRun::COMPANY]);
        } finally {
            $lock->release($name);
            $other->close();
        }

        $this->run->saveCompany($this->companyInput(), $this->manager);

        $this->assertTrue($this->run->progress()[FirstRun::COMPANY]);
    }

    public function testAccountingStep_IsNotDoneUntilTheBooksAreOnAndReady(): void
    {
        $this->assertFalse($this->run->progress()[FirstRun::ACCOUNTING]);

        $this->activateTheBooks();

        $this->assertTrue((new AccountingSettings())->isEnabled());
        $this->assertTrue($this->run->progress()[FirstRun::ACCOUNTING]);
    }
}
