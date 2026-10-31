<?php

namespace Tests\Libraries\Accounting\Bank;

use App\Libraries\Accounting\AccountingError;
use App\Libraries\Accounting\AccountingException;
use App\Libraries\Accounting\AccountingGuard;
use App\Libraries\Accounting\Bank\BankMatchSettings;
use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\DatabaseTestTrait;
use Config\Database;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Support\AccountingFixtureTrait;
use Tests\Support\EmployeeFixtureTrait;

class BankMatchSettingsTest extends CIUnitTestCase
{
    use DatabaseTestTrait;
    use AccountingFixtureTrait;
    use EmployeeFixtureTrait;

    protected $migrate     = true;
    protected $migrateOnce = true;
    protected $refresh     = false;
    protected $namespace   = null;

    private BankMatchSettings $settings;
    private int $reconciler;

    public static function setUpBeforeClass(): void
    {
        $seeder = Database::seeder('tests');
        $seeder->call('TestDatabaseBootstrapSeeder');
    }

    protected function setUp(): void
    {
        parent::setUp();

        $this->clearJournals();
        $this->accountingDb()->table('app_config')->where('key', BankMatchSettings::KEY)->delete();
        $this->settings   = new BankMatchSettings();
        $this->reconciler = $this->employeeWithGrants([AccountingGuard::BANK_RECONCILE]);
    }

    protected function tearDown(): void
    {
        $this->accountingDb()->table('app_config')->where('key', BankMatchSettings::KEY)->delete();

        parent::tearDown();
    }

    private function refusal(callable $action): AccountingException
    {
        try {
            $action();
        } catch (AccountingException $exception) {
            return $exception;
        }

        $this->fail('The setting was not refused.');
    }

    public function testWindowDays_IsSevenDaysUntilItIsChanged(): void
    {
        $this->assertSame(7, $this->settings->windowDays());
        $this->assertSame(7, BankMatchSettings::DEFAULT);
    }

    public function testSetWindowDays_KeepsTheWindowAndWritesAnAuditEntryWithWhatItWas(): void
    {
        $this->settings->setWindowDays(14, $this->reconciler);

        $this->assertSame(14, $this->settings->windowDays());

        $audit = $this->accountingDb()->table('accounting_audit_log')->where('action', 'bank_match_window_changed')->get()->getRow();

        $this->assertSame([7, 14], [json_decode($audit->details, true)['before'], json_decode($audit->details, true)['after']]);
        $this->assertSame($this->reconciler, (int) $audit->employee_id);
        $this->assertSame('bank_settings', $audit->entity_type);
    }

    public function testSetWindowDays_AcceptsTheLimitsAndATextOfDigits(): void
    {
        foreach ([BankMatchSettings::MIN, BankMatchSettings::MAX, ' 21 '] as $days) {
            $this->settings->setWindowDays($days, $this->reconciler);

            $this->assertSame((int) $days, $this->settings->windowDays());
        }
    }

    /**
     * @return array<string, array{mixed}>
     */
    public static function badWindowProvider(): array
    {
        return ['negative' => [-1], 'over the limit' => [BankMatchSettings::MAX + 1], 'text' => ['soon'], 'decimal' => ['2.5'], 'empty' => [''], 'null' => [null], 'array' => [[3]]];
    }

    #[DataProvider('badWindowProvider')]
    public function testSetWindowDays_RejectsAWindowThatIsNotAWholeNumberOfDaysInRange(mixed $days): void
    {
        $refusal = $this->refusal(fn () => $this->settings->setWindowDays($days, $this->reconciler));

        $this->assertSame(AccountingError::BankMatchWindowInvalid, $refusal->error);
        $this->assertSame([BankMatchSettings::MAX, BankMatchSettings::MIN], [$refusal->context['max'], $refusal->context['min']]);
        $this->assertSame(7, $this->settings->windowDays());
    }

    public function testSetWindowDays_RequiresThePermissionToReconcile(): void
    {
        $refusal = $this->refusal(fn () => $this->settings->setWindowDays(3, $this->employeeWithGrants([AccountingGuard::TREASURIES_MANAGE])));

        $this->assertSame(AccountingError::PermissionDenied, $refusal->error);
        $this->assertSame(7, $this->settings->windowDays());
        $this->assertSame(0, $this->accountingDb()->table('accounting_audit_log')->where('action', 'bank_match_window_changed')->countAllResults());
    }

    public function testSetWindowDays_WritesNothingWhenTheWindowIsTheSame(): void
    {
        $this->settings->setWindowDays(7, $this->reconciler);

        $this->assertSame(0, $this->accountingDb()->table('accounting_audit_log')->where('action', 'bank_match_window_changed')->countAllResults());
        $this->assertSame(0, $this->accountingDb()->table('app_config')->where('key', BankMatchSettings::KEY)->countAllResults());
    }

    public function testWindowDays_FallsBackToTheDefaultWhenTheStoredValueIsNotAWindow(): void
    {
        foreach (['abc', '-3', '61', '', '7.5'] as $stored) {
            $this->accountingDb()->table('app_config')->replace(['key' => BankMatchSettings::KEY, 'value' => $stored]);

            $this->assertSame(7, $this->settings->windowDays(), $stored);
        }
    }

    public function testSetWindowDays_IsOpenToTheSystemItself(): void
    {
        $this->settings->setWindowDays(2);

        $this->assertSame(2, $this->settings->windowDays());
    }
}
