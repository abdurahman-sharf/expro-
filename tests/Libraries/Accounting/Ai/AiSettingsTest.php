<?php

namespace Tests\Libraries\Accounting\Ai;

use App\Libraries\Accounting\Ai\AiSettings;
use App\Libraries\Accounting\AccountingError;
use App\Libraries\Accounting\AccountingException;
use App\Libraries\Accounting\AccountingGuard;
use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\DatabaseTestTrait;
use Config\Database;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Support\AiFixtureTrait;

/**
 * The master switch and the thresholds: off until somebody who may change the settings turns it on, read from the
 * database every time, switched off by whoever uses the agent, with the defaults documented in the class and every
 * change in the audit log.
 *
 * @internal
 */
final class AiSettingsTest extends CIUnitTestCase
{
    use DatabaseTestTrait;
    use AiFixtureTrait;

    protected $migrate     = true;
    protected $migrateOnce = true;
    protected $refresh     = false;
    protected $namespace   = null;

    private AiSettings $settings;

    public static function setUpBeforeClass(): void
    {
        $seeder = Database::seeder('tests');
        $seeder->call('TestDatabaseBootstrapSeeder');
    }

    protected function setUp(): void
    {
        parent::setUp();

        $this->startAgent(false);
        $this->settings = new AiSettings();
    }

    protected function tearDown(): void
    {
        $this->stopAgent();

        parent::tearDown();
    }

    private function refused(callable $call): AccountingException
    {
        try {
            $call();
        } catch (AccountingException $failure) {
            return $failure;
        }

        $this->fail('The call was not refused.');
    }

    /**
     * @return list<object>
     */
    private function audit(string $action): array
    {
        return $this->accountingDb()->table('accounting_audit_log')->where('action', $action)->orderBy('audit_id', 'ASC')->get()->getResult();
    }

    public function testIsEnabled_IsOffByDefaultAndOnceTheSwitchIsTurnedOn(): void
    {
        $this->assertFalse($this->settings->isEnabled());

        $this->setConfigValue(AiSettings::ENABLED_KEY, '1');

        $this->assertTrue($this->settings->isEnabled());
    }

    public function testIsEnabled_IsOffWhereTheSettingWasNeverWritten(): void
    {
        $this->accountingDb()->table('app_config')->where('key', AiSettings::ENABLED_KEY)->delete();

        $this->assertFalse($this->settings->isEnabled());
    }

    public function testIsEnabled_IsOffWhileAccountingIsOffEvenWhenTheSwitchIsOn(): void
    {
        $this->setConfigValue(AiSettings::ENABLED_KEY, '1');
        $this->setConfigValue('accounting_enabled', '0');

        $this->assertTrue($this->settings->isSwitchedOn());
        $this->assertFalse($this->settings->isEnabled());
        $this->assertSame(AccountingError::AiDisabled, $this->refused(fn () => $this->settings->assertEnabled())->error);
    }

    public function testSetEnabled_SwitchesOffTheAgentThatIsOnWhileAccountingIsOff(): void
    {
        $this->setConfigValue(AiSettings::ENABLED_KEY, '1');
        $this->setConfigValue('accounting_enabled', '0');

        $this->settings->setEnabled(false, $this->employeeWithGrants([AccountingGuard::AI_USE]));

        $this->assertFalse($this->settings->isSwitchedOn());
    }

    public function testAssertEnabled_NamesTheErrorWhenTheSwitchIsOff(): void
    {
        $this->assertSame(AccountingError::AiDisabled, $this->refused(fn () => $this->settings->assertEnabled())->error);

        $this->setConfigValue(AiSettings::ENABLED_KEY, '1');
        $this->settings->assertEnabled();

        $this->setConfigValue(AiSettings::ENABLED_KEY, '0');

        $this->assertSame(AccountingError::AiDisabled, $this->refused(fn () => $this->settings->assertEnabled())->error);
    }

    public function testSetEnabled_RequiresThePermissionToChangeTheSettingsToTurnItOn(): void
    {
        $user = $this->employeeWithGrants([AccountingGuard::AI_USE, AccountingGuard::AI_APPROVE]);

        $this->assertSame(AccountingError::PermissionDenied, $this->refused(fn () => $this->settings->setEnabled(true, $user))->error);
        $this->assertFalse($this->settings->isEnabled());

        $manager = $this->employeeWithGrants([AccountingGuard::SETTINGS_MANAGE]);
        $this->settings->setEnabled(true, $manager);

        $this->assertTrue($this->settings->isEnabled());
    }

    public function testSetEnabled_WritesAnAuditEntryForEachChangeAndNoneForNoChange(): void
    {
        $manager = $this->employeeWithGrants([AccountingGuard::SETTINGS_MANAGE]);

        $this->settings->setEnabled(true, $manager);
        $this->settings->setEnabled(true, $manager);
        $this->settings->setEnabled(false, $manager);
        $this->settings->setEnabled(false, $manager);

        $on  = $this->audit('ai_enabled');
        $off = $this->audit('ai_disabled');

        $this->assertCount(1, $on);
        $this->assertCount(1, $off);
        $this->assertSame($manager, (int) $on[0]->employee_id);
        $this->assertSame('user', $on[0]->origin);
        $this->assertSame('ai_settings', $on[0]->entity_type);
    }

    public function testSetEnabled_LetsWhoeverUsesTheAgentSwitchItOffButNobodyElse(): void
    {
        $this->setConfigValue(AiSettings::ENABLED_KEY, '1');

        $stranger = $this->employeeWithGrants([AccountingGuard::JOURNALS_POST]);

        $this->assertSame(AccountingError::PermissionDenied, $this->refused(fn () => $this->settings->setEnabled(false, $stranger))->error);
        $this->assertTrue($this->settings->isEnabled());

        $this->settings->setEnabled(false, $this->employeeWithGrants([AccountingGuard::AI_USE]));

        $this->assertFalse($this->settings->isEnabled());
    }

    public function testSetEnabled_LetsTheManagerOfTheSettingsSwitchItOffToo(): void
    {
        $this->setConfigValue(AiSettings::ENABLED_KEY, '1');

        $this->settings->setEnabled(false, $this->employeeWithGrants([AccountingGuard::SETTINGS_MANAGE]));

        $this->assertFalse($this->settings->isEnabled());
    }

    public function testSetEnabled_AcceptsTheSystemAndWritesItsEntryAsTheSystems(): void
    {
        $this->settings->setEnabled(true, null);

        $this->assertTrue($this->settings->isEnabled());
        $this->assertSame('system', $this->audit('ai_enabled')[0]->origin);
    }

    public function testValue_GivesTheDocumentedDefaultOfEveryThreshold(): void
    {
        $this->assertSame([
            'proposal_expiry_days' => '14',
            'lookback_days'        => '90',
            'reclass_min_count'    => '2',
            'reclass_min_share'    => '80',
            'recurring_months'     => '3',
            'recurring_tolerance'  => '10',
            'weekend_days'         => '5,6',
            'night_start'          => '22',
            'night_end'            => '6',
            'offhours_share'       => '10',
            'offhours_history'     => '20',
            'round_unit'           => '1000.0000',
            'round_min'            => '10000.0000',
            'duplicate_days'       => '3',
            'jump_days'            => '30',
            'jump_percent'         => '100',
            'jump_min'             => '5000.0000',
            'reversal_chain'       => '3',
            'backdate_days'        => '7',
            'lock_window_days'     => '3',
        ], $this->settings->thresholds());
    }

    public function testValue_RefusesAThresholdThatDoesNotExist(): void
    {
        $this->assertSame(AccountingError::AiSettingInvalid, $this->refused(fn () => $this->settings->value('nonsense'))->error);
    }

    public function testValue_FallsBackToTheDefaultWhereTheStoredValueIsNotValid(): void
    {
        $this->setThreshold('duplicate_days', 'many');
        $this->setThreshold('round_unit', '-5');

        $this->assertSame(3, $this->settings->int('duplicate_days'));
        $this->assertSame('1000.0000', $this->settings->value('round_unit'));
    }

    public function testUpdate_RequiresThePermissionToChangeTheSettings(): void
    {
        $user = $this->employeeWithGrants([AccountingGuard::AI_USE, AccountingGuard::AI_APPROVE]);

        $this->assertSame(AccountingError::PermissionDenied, $this->refused(fn () => $this->settings->update(['duplicate_days' => '5'], $user))->error);
        $this->assertSame(3, $this->settings->int('duplicate_days'));
    }

    public function testUpdate_ChangesTheThresholdsAndWritesWhatTheyWereAndWhatTheyBecame(): void
    {
        $manager = $this->employeeWithGrants([AccountingGuard::SETTINGS_MANAGE]);

        $this->settings->update(['duplicate_days' => '5', 'round_unit' => '500', 'weekend_days' => '6,5,6'], $manager);

        $this->assertSame(5, $this->settings->int('duplicate_days'));
        $this->assertSame('500.0000', $this->settings->value('round_unit'));
        $this->assertSame([5, 6], $this->settings->weekendDays());

        $entries = $this->audit('ai_settings_updated');

        $this->assertCount(1, $entries);

        $details = json_decode((string) $entries[0]->details, true);

        $this->assertEquals(['duplicate_days' => '3', 'round_unit' => '1000.0000'], $details['before']);
        $this->assertEquals(['duplicate_days' => '5', 'round_unit' => '500.0000'], $details['after']);
    }

    public function testUpdate_WritesNothingWhereNothingChanged(): void
    {
        $manager = $this->employeeWithGrants([AccountingGuard::SETTINGS_MANAGE]);

        $this->settings->update(['duplicate_days' => '3', 'round_unit' => '1000'], $manager);

        $this->assertSame([], $this->audit('ai_settings_updated'));
    }

    /**
     * @return array<string, array{0: string, 1: string}>
     */
    public static function invalidValues(): array
    {
        return [
            'zero days to expire'      => ['proposal_expiry_days', '0'],
            'a year and a day'         => ['proposal_expiry_days', '366'],
            'text'                     => ['duplicate_days', 'many'],
            'a negative number'        => ['duplicate_days', '-1'],
            'a fraction of days'       => ['duplicate_days', '1.5'],
            'a share above 100'        => ['reclass_min_share', '101'],
            'one month of recurrence'  => ['recurring_months', '1'],
            'hour 24'                  => ['night_start', '24'],
            'a weekday 7'              => ['weekend_days', '7'],
            'weekdays with a space'    => ['weekend_days', '5, 6'],
            'a zero unit'              => ['round_unit', '0'],
            'an amount with letters'   => ['round_min', '1k'],
            'an amount of five places' => ['jump_min', '1.12345'],
            'a chain of one'           => ['reversal_chain', '1'],
        ];
    }

    #[DataProvider('invalidValues')]
    public function testUpdate_RejectsAValueOutsideWhatTheThresholdTakes(string $name, string $value): void
    {
        $manager = $this->employeeWithGrants([AccountingGuard::SETTINGS_MANAGE]);

        $this->assertSame(AccountingError::AiSettingInvalid, $this->refused(fn () => $this->settings->update([$name => $value], $manager))->error);
    }

    public function testUpdate_AcceptsTheBoundsOfAThreshold(): void
    {
        $manager = $this->employeeWithGrants([AccountingGuard::SETTINGS_MANAGE]);

        $this->settings->update(['proposal_expiry_days' => '1', 'lookback_days' => '730', 'reclass_min_share' => '100', 'weekend_days' => '', 'night_start' => '0', 'night_end' => '23'], $manager);

        $this->assertSame(1, $this->settings->int('proposal_expiry_days'));
        $this->assertSame(730, $this->settings->int('lookback_days'));
        $this->assertSame([], $this->settings->weekendDays());
    }

    public function testUpdate_RejectsAThresholdThatDoesNotExist(): void
    {
        $manager = $this->employeeWithGrants([AccountingGuard::SETTINGS_MANAGE]);

        $this->assertSame(AccountingError::AiSettingInvalid, $this->refused(fn () => $this->settings->update(['nonsense' => '1'], $manager))->error);
    }

    public function testUpdate_ChangesNothingWhenOneValueOfTheSetIsNotValid(): void
    {
        $manager = $this->employeeWithGrants([AccountingGuard::SETTINGS_MANAGE]);

        $this->refused(fn () => $this->settings->update(['duplicate_days' => '5', 'jump_days' => 'x'], $manager));

        $this->assertSame(3, $this->settings->int('duplicate_days'));
        $this->assertSame([], $this->audit('ai_settings_updated'));
    }

    public function testUpdate_RejectsANightThatStartsAndEndsAtTheSameHour(): void
    {
        $manager = $this->employeeWithGrants([AccountingGuard::SETTINGS_MANAGE]);

        $this->assertSame(AccountingError::AiSettingInvalid, $this->refused(fn () => $this->settings->update(['night_end' => '22'], $manager))->error);
        $this->assertSame(6, $this->settings->int('night_end'));
    }
}
