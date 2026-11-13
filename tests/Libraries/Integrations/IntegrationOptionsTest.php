<?php

namespace Tests\Libraries\Integrations;

use App\Libraries\Integrations\IntegrationAccess;
use App\Libraries\Integrations\IntegrationError;
use App\Libraries\Integrations\IntegrationException;
use App\Libraries\Integrations\IntegrationOptions;
use App\Libraries\Organization\BranchError;
use App\Libraries\Organization\BranchException;
use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\DatabaseTestTrait;
use Config\Database;
use Tests\Support\EmployeeFixtureTrait;
use Tests\Support\IntegrationFixtureTrait;

/**
 * The switches of the integration settings: every one off until it is turned on, who may turn which, what
 * a language must be, and that every change is audited and no change is a change.
 */
class IntegrationOptionsTest extends CIUnitTestCase
{
    use DatabaseTestTrait;
    use EmployeeFixtureTrait;
    use IntegrationFixtureTrait;

    protected $migrate     = true;
    protected $migrateOnce = true;
    protected $refresh     = false;
    protected $namespace   = null;

    private IntegrationOptions $options;

    public static function setUpBeforeClass(): void
    {
        $seeder = Database::seeder('tests');
        $seeder->call('TestDatabaseBootstrapSeeder');
    }

    protected function setUp(): void
    {
        parent::setUp();

        $this->wipeIntegration();
        $this->options = new IntegrationOptions();
    }

    protected function tearDown(): void
    {
        $this->wipeIntegration();
        $this->removeInsertedEntities();

        parent::tearDown();
    }

    private function refusal(callable $action): IntegrationException|BranchException
    {
        try {
            $action();
        } catch (IntegrationException|BranchException $exception) {
            return $exception;
        }

        $this->fail('The change was not refused.');
    }

    public function testAll_ReportsEverySwitchOffWhenNothingIsSet(): void
    {
        $this->assertSame(
            ['payment_methods' => false, 'second_language' => false, 'second_language_print' => false, 'second_language_code' => '', 'api' => false, 'notifications' => false],
            $this->options->all(),
        );
        $this->assertFalse($this->options->apiEnabled());
        $this->assertFalse($this->options->notificationsEnabled());
        $this->assertFalse($this->options->paymentMethodsCustom());
        $this->assertFalse($this->options->secondLanguageEnabled());
        $this->assertFalse($this->options->secondLanguagePrint());
    }

    public function testUpdate_TurnsSwitchesOnAndAuditsWhatChangedWithWhatItWas(): void
    {
        $manager = $this->integrationManager();

        $this->options->update(['payment_methods' => true, 'notifications' => true], $manager);

        $this->assertTrue($this->options->paymentMethodsCustom());
        $this->assertTrue($this->options->notificationsEnabled());
        $this->assertFalse($this->options->apiEnabled());

        $entry = Database::connect()->table('accounting_audit_log')->where('action', 'integration_options_updated')->get()->getRow();

        $this->assertSame('integration', $entry->entity_type);
        $this->assertSame($manager, (int) $entry->employee_id);
        $this->assertSame(['before' => ['payment_methods' => false, 'notifications' => false], 'after' => ['payment_methods' => true, 'notifications' => true]], json_decode($entry->details, true));
    }

    public function testUpdate_WritesNothingAndAuditsNothingWhenNothingChanges(): void
    {
        $manager = $this->integrationManager();

        $this->options->update(['payment_methods' => false, 'api' => false], $manager);
        $this->options->update(['notifications' => true], $manager);
        $this->options->update(['notifications' => true], $manager);

        $this->assertSame(['integration_options_updated'], $this->integrationAudit('integration'));
    }

    public function testUpdate_RequiresThePermissionToManageForTheOrdinarySwitches(): void
    {
        $outsider = $this->employeeWithGrants([IntegrationAccess::MODULE, IntegrationAccess::CONFIG]);

        $refusal = $this->refusal(fn () => $this->options->update(['payment_methods' => true], $outsider));

        $this->assertInstanceOf(IntegrationException::class, $refusal);
        $this->assertSame(IntegrationError::PermissionDenied, $refusal->error);
        $this->assertFalse($this->options->paymentMethodsCustom());
        $this->assertSame([], $this->integrationAudit('integration'));
    }

    public function testUpdate_RequiresThePermissionToManageKeysForTheApiSwitchAndNotJustThePermissionToManage(): void
    {
        $manager = $this->integrationManager();

        $refusal = $this->refusal(fn () => $this->options->update(['api' => true], $manager));

        $this->assertInstanceOf(IntegrationException::class, $refusal);
        $this->assertSame(IntegrationError::PermissionDenied, $refusal->error);
        $this->assertFalse($this->options->apiEnabled());

        $this->options->update(['api' => true], $this->keyManager());

        $this->assertTrue($this->options->apiEnabled());
    }

    public function testUpdate_RequiresEveryBranchForTheApiSwitch(): void
    {
        $second     = $this->createBranch('B2');
        $restricted = $this->keyManager([$second]);

        $refusal = $this->refusal(fn () => $this->options->update(['api' => true], $restricted));

        $this->assertInstanceOf(BranchException::class, $refusal);
        $this->assertSame(BranchError::CompanyScopeNeeded, $refusal->error);
        $this->assertFalse($this->options->apiEnabled());
    }

    public function testUpdate_LetsTheSystemChangeAnySwitch(): void
    {
        $this->options->update(['api' => true, 'payment_methods' => true], null);

        $this->assertTrue($this->options->apiEnabled());
        $this->assertTrue($this->options->paymentMethodsCustom());
    }

    public function testUpdate_ChangesBothKindsOfSwitchOnlyForSomeoneWhoMayChangeBoth(): void
    {
        $manager = $this->integrationManager();

        $refusal = $this->refusal(fn () => $this->options->update(['payment_methods' => true, 'api' => true], $manager));

        $this->assertInstanceOf(IntegrationException::class, $refusal);
        $this->assertFalse($this->options->paymentMethodsCustom(), 'A refused change writes none of its switches.');
        $this->assertFalse($this->options->apiEnabled());
    }

    public function testUpdate_RejectsALanguageThatIsNotALanguageOfTheApplication(): void
    {
        $refusal = $this->refusal(fn () => $this->options->update(['second_language_code' => 'xx-XX'], null));

        $this->assertSame(IntegrationError::SettingInvalid, $refusal->error);
        $this->assertSame('', $this->options->secondLanguageCode());
    }

    public function testUpdate_RequiresALanguageWhileTheSecondLanguageIsOn(): void
    {
        $refusal = $this->refusal(fn () => $this->options->update(['second_language' => true], null));

        $this->assertSame(IntegrationError::SettingInvalid, $refusal->error);
        $this->assertFalse($this->options->all()['second_language']);

        $refusal = $this->refusal(fn () => $this->options->update(['second_language_print' => true], null));

        $this->assertSame(IntegrationError::SettingInvalid, $refusal->error);
    }

    public function testUpdate_AcceptsALanguageOfTheApplicationAndTurnsTheSecondLanguageOnWithIt(): void
    {
        $this->options->update(['second_language' => true, 'second_language_code' => 'en', 'second_language_print' => true], null);

        $this->assertTrue($this->options->secondLanguageEnabled());
        $this->assertTrue($this->options->secondLanguagePrint());
        $this->assertSame('en', $this->options->secondLanguageCode());
    }

    public function testSecondLanguagePrint_NeedsTheSecondLanguageAndItsSwitchBoth(): void
    {
        $this->setConfigValue(IntegrationOptions::SECOND_LANGUAGE_PRINT, '1');

        $this->assertFalse($this->options->secondLanguagePrint(), 'The print switch alone prints nothing.');

        $this->setConfigValue(IntegrationOptions::SECOND_LANGUAGE, '1');

        $this->assertFalse($this->options->secondLanguagePrint(), 'A second language with no language chosen prints nothing.');

        $this->setConfigValue(IntegrationOptions::SECOND_LANGUAGE_CODE, 'ar-EG');

        $this->assertTrue($this->options->secondLanguagePrint());

        $this->setConfigValue(IntegrationOptions::SECOND_LANGUAGE_PRINT, '0');

        $this->assertFalse($this->options->secondLanguagePrint());
    }

    public function testSecondLanguageCode_IgnoresAStoredCodeThatIsNoLongerALanguageOfTheApplication(): void
    {
        $this->setConfigValue(IntegrationOptions::SECOND_LANGUAGE_CODE, 'zz');

        $this->assertSame('', $this->options->secondLanguageCode());
    }

    public function testLocales_ListsEveryLanguageFolderInOrder(): void
    {
        $locales = IntegrationOptions::locales();

        $this->assertContains('en', $locales);
        $this->assertContains('ar-EG', $locales);
        $this->assertSame($locales, array_values(array_unique($locales)));

        $sorted = $locales;
        sort($sorted);

        $this->assertSame($sorted, $locales);
    }
}
