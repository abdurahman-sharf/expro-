<?php

namespace Tests\Libraries\Accounting;

use App\Libraries\Accounting\AccountingActivation;
use App\Libraries\Accounting\AccountingError;
use App\Libraries\Accounting\AccountingException;
use App\Libraries\Accounting\AccountingGuard;
use App\Libraries\Accounting\AccountingNotReadyException;
use App\Libraries\Accounting\AccountingSettings;
use App\Libraries\Accounting\EntityAccountService;
use App\Libraries\Accounting\LockOutcome;
use App\Libraries\Accounting\NamedLock;
use App\Libraries\Accounting\PaymentType;
use App\Libraries\Accounting\ReadinessCode;
use App\Libraries\Accounting\RoutingKey;
use App\Libraries\Organization\BranchException;
use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\DatabaseTestTrait;
use Config\Database;
use Tests\Support\AccountingFixtureTrait;

class AccountingActivationTest extends CIUnitTestCase
{
    use DatabaseTestTrait;
    use AccountingFixtureTrait;

    protected $migrate     = true;
    protected $migrateOnce = true;
    protected $refresh     = false;
    protected $namespace   = null;
    private AccountingActivation $activation;
    private int $manager;
    private string $numberLocale = 'en_US';

    public static function setUpBeforeClass(): void
    {
        $seeder = Database::seeder('tests');
        $seeder->call('TestDatabaseBootstrapSeeder');
    }

    protected function setUp(): void
    {
        parent::setUp();

        $db = $this->accountingDb();
        $db->disableForeignKeyChecks();
        $db->table('accounting_fiscal_years')->truncate();
        $db->enableForeignKeyChecks();
        $db->table('accounting_accounts')->where('active', 0)->update(['active' => 1]);
        $db->table('accounting_audit_log')->truncate();
        $db->table('sales')->where('comment', 'activation-test')->delete();
        $db->table('receivings')->where('comment', 'activation-test')->delete();

        foreach (RoutingKey::cases() as $key) {
            $systemKey = $key->defaultSystemKey();

            $db->table('accounting_routing')
                ->where('routing_key', $key->value)
                ->update(['account_id' => $systemKey === null ? null : $this->accountId($systemKey), 'routing_type' => $key->defaultType()->value]);
        }

        (new EntityAccountService())->fill();

        foreach (PaymentType::cases() as $type) {
            if ($type->defaultSystemKey() !== null) {
                $db->table('accounting_routing')
                    ->where('routing_key', $type->routingKey())
                    ->update(['account_id' => $this->accountId($type->defaultSystemKey())]);
            }
        }

        $this->setConfigValue('accounting_enabled', '0');
        $this->setConfigValue('accounting_base_currency', '');
        $this->setConfigValue('accounting_start_date', '');
        $this->setConfigValue('accounting_activation', '');
        $this->setConfigValue('currency_code', '');
        $this->numberLocale = $this->storedValue('number_locale');

        $this->manager    = $this->employeeWithGrants([AccountingGuard::SETTINGS_MANAGE]);
        $this->activation = new AccountingActivation();
    }

    protected function tearDown(): void
    {
        $this->releaseOpenTransactions();
        $this->setConfigValue('accounting_enabled', '0');
        $this->setConfigValue('accounting_activation', '');
        $this->setConfigValue('currency_code', '');
        $this->setConfigValue('number_locale', $this->numberLocale);
        $this->accountingDb()->table('sales')->where('comment', 'activation-test')->delete();
        $this->accountingDb()->table('receivings')->where('comment', 'activation-test')->delete();
        $this->removeInsertedEntities();

        parent::tearDown();
    }

    private function sale(string $time): void
    {
        $this->accountingDb()->table('sales')->insert(['comment' => 'activation-test', 'sale_time' => $time, 'sale_status' => COMPLETED, 'employee_id' => 1]);
    }

    private function receiving(string $time): void
    {
        $this->accountingDb()->table('receivings')->insert(['comment' => 'activation-test', 'receiving_time' => $time, 'employee_id' => 1]);
    }

    /**
     * @return array<string, mixed>
     */
    private function activate(string $startDate = '2026-11-01', string $currency = 'SAR'): array
    {
        $state = $this->activation->begin($this->manager, $currency, $startDate, [AccountingActivation::ACK_IMPACT]);

        return $this->runToTheEnd($state['run_id']);
    }

    /**
     * @return array<string, mixed>
     */
    private function runToTheEnd(string $runId): array
    {
        $summary = [];

        for ($turn = 0; $turn < 50; $turn++) {
            $summary = $this->activation->step($runId, $this->manager);

            if ($summary['done']) {
                break;
            }
        }

        return $summary;
    }

    private function assertFailsWith(AccountingError $expected, callable $action): void
    {
        try {
            $action();
        } catch (AccountingException $exception) {
            $this->assertSame($expected, $exception->error);

            return;
        }

        $this->fail('Expected the refusal ' . $expected->value . '.');
    }

    private function storedValue(string $key): string
    {
        return (string) ($this->accountingDb()->table('app_config')->where('key', $key)->get()->getRow()->value ?? '');
    }

    private function auditActions(): array
    {
        return array_column($this->accountingDb()->table('accounting_audit_log')->orderBy('audit_id')->get()->getResultArray(), 'action');
    }

    public function testInspect_SuggestsTheFirstDayOfThisMonthForADatabaseWithNoDocument(): void
    {
        $inspection = $this->activation->inspect();

        $this->assertSame(date('Y-m-01'), $inspection['start_date']);
        $this->assertFalse($inspection['has_documents']);
    }

    public function testInspect_SuggestsTodayForADatabaseThatAlreadyHoldsDocuments(): void
    {
        $this->sale('2026-03-10 10:00:00');

        $inspection = $this->activation->inspect();

        $this->assertSame(date('Y-m-d'), $inspection['start_date']);
        $this->assertTrue($inspection['has_documents']);
    }

    public function testInspect_KeepsTheStartDateTheOwnerAlreadyChose(): void
    {
        $this->setConfigValue('accounting_start_date', '2026-02-01');

        $this->assertSame('2026-02-01', $this->activation->inspect()['start_date']);
    }

    public function testInspect_SuggestsTheSavedCurrencyThenTheConfiguredOneThenAGuess(): void
    {
        $this->setConfigValue('accounting_base_currency', 'EGP');
        $this->assertSame(['EGP', 'saved'], [$this->activation->inspect()['currency'], $this->activation->inspect()['currency_source']]);

        $this->setConfigValue('accounting_base_currency', '');
        $this->setConfigValue('currency_code', 'sar');
        $this->assertSame(['SAR', 'configured'], [$this->activation->inspect()['currency'], $this->activation->inspect()['currency_source']]);

        $this->setConfigValue('currency_code', '');
        $this->setConfigValue('number_locale', 'ar_SA');
        $guess = $this->activation->inspect();

        $this->assertSame('guessed', $guess['currency_source']);
        $this->assertSame(1, preg_match('/^[A-Z]{3}$/', $guess['currency']));
    }

    public function testInspect_SplitsWhatTheRunSettlesItselfFromWhatNeedsAPerson(): void
    {
        $this->insertCustomer();
        $this->accountingDb()->table('accounting_routing')->where('routing_key', 'sales.cogs')->update(['account_id' => null]);

        $inspection = $this->activation->inspect();

        $automatic = array_map(static fn ($issue): string => $issue->code->value, $inspection['automatic']);
        $blockers  = array_map(static fn ($issue): string => $issue->code->value, $inspection['blockers']);

        $this->assertEqualsCanonicalizing(['base_currency_missing', 'start_date_missing', 'no_fiscal_year', 'entity_accounts_missing'], $automatic);
        $this->assertSame(['routing_unmapped'], $blockers);
        $this->assertSame(1, $inspection['entities_missing']);
    }

    public function testImpactFor_CountsTheDocumentsBeforeTheDateAndFromIt(): void
    {
        $this->sale('2026-05-01 09:00:00');
        $this->sale('2026-10-31 23:59:59');
        $this->sale('2026-11-01 00:00:00');
        $this->receiving('2026-12-05 12:00:00');

        $impact = $this->activation->impactFor('2026-11-01');

        $this->assertSame(2, $impact['before']['sales']);
        $this->assertSame(1, $impact['from']['sales']);
        $this->assertSame(0, $impact['before']['receivings']);
        $this->assertSame(1, $impact['from']['receivings']);
    }

    public function testImpactFor_RefusesADateThatIsNotADate(): void
    {
        $this->assertFailsWith(AccountingError::InvalidDate, fn () => $this->activation->impactFor('2026-13-40'));
    }

    public function testBegin_RequiresThePermissionAndEveryBranch(): void
    {
        $this->assertFailsWith(AccountingError::PermissionDenied, fn () => $this->activation->begin($this->employeeWithGrants([]), 'SAR', '2026-11-01', [AccountingActivation::ACK_IMPACT]));

        $branch = $this->createBranch();
        $limited = $this->employeeWithGrants([AccountingGuard::SETTINGS_MANAGE], [$branch]);

        $this->expectException(BranchException::class);
        $this->activation->begin($limited, 'SAR', '2026-11-01', [AccountingActivation::ACK_IMPACT]);
    }

    public function testBegin_RefusesWhileAccountingIsAlreadyOn(): void
    {
        $this->setConfigValue('accounting_enabled', '1');

        $this->assertFailsWith(AccountingError::ActivationAlreadyOn, fn () => $this->activation->begin($this->manager, 'SAR', '2026-11-01', [AccountingActivation::ACK_IMPACT]));
    }

    public function testBegin_RefusesACurrencyThatIsNotThreeLetters(): void
    {
        $this->assertFailsWith(AccountingError::InvalidCurrency, fn () => $this->activation->begin($this->manager, 'SA', '2026-11-01', [AccountingActivation::ACK_IMPACT]));
    }

    public function testBegin_RefusesAStartDateThatIsNotADate(): void
    {
        $this->assertFailsWith(AccountingError::InvalidDate, fn () => $this->activation->begin($this->manager, 'SAR', '2026-02-30', [AccountingActivation::ACK_IMPACT]));
    }

    public function testBegin_RefusesAStartDateBeforeTheFirstFiscalYear(): void
    {
        $this->accountingDb()->table('accounting_fiscal_years')->insert(['name' => '2026', 'start_date' => '2026-01-01', 'end_date' => '2026-12-31']);

        $this->assertFailsWith(AccountingError::StartDateOutsideFiscalYear, fn () => $this->activation->begin($this->manager, 'SAR', '2025-06-01', [AccountingActivation::ACK_IMPACT]));
    }

    public function testBegin_RequiresTheManagerToAcknowledgeWhatTheStartDateMeans(): void
    {
        try {
            $this->activation->begin($this->manager, 'SAR', '2026-11-01', []);
            $this->fail('Expected the refusal.');
        } catch (AccountingException $exception) {
            $this->assertSame(AccountingError::ActivationUnconfirmed, $exception->error);
            $this->assertSame(AccountingActivation::ACK_IMPACT, $exception->context['name']);
        }

        $this->assertNull($this->activation->status());
    }

    public function testBegin_AsksForASeparateAcknowledgementWhenDocumentsLieFromTheStartDate(): void
    {
        $this->sale('2026-11-20 10:00:00');

        try {
            $this->activation->begin($this->manager, 'SAR', '2026-11-01', [AccountingActivation::ACK_IMPACT]);
            $this->fail('Expected the refusal.');
        } catch (AccountingException $exception) {
            $this->assertSame(AccountingActivation::ACK_DOCUMENTS, $exception->context['name']);
        }

        $state = $this->activation->begin($this->manager, 'SAR', '2026-11-01', [AccountingActivation::ACK_IMPACT, AccountingActivation::ACK_DOCUMENTS]);

        $this->assertSame(AccountingActivation::RUNNING, $state['status']);
    }

    public function testBegin_RefusesWhileSomethingOnlyAPersonCanSettleRemains(): void
    {
        $this->accountingDb()->table('accounting_routing')->where('routing_key', 'sales.cogs')->update(['account_id' => null]);

        try {
            $this->activation->begin($this->manager, 'SAR', '2026-11-01', [AccountingActivation::ACK_IMPACT]);
            $this->fail('Expected the refusal.');
        } catch (AccountingNotReadyException $exception) {
            $this->assertSame([ReadinessCode::RoutingUnmapped], array_column($exception->issues, 'code'));
        }
    }

    public function testBegin_SavesTheRunAndTheConfirmationInTheAudit(): void
    {
        $state = $this->activation->begin($this->manager, 'sar', '2026-11-01', [AccountingActivation::ACK_IMPACT]);

        $this->assertSame(['running', 'years', 'SAR', '2026-11-01'], [$state['status'], $state['step'], $state['currency'], $state['start_date']]);
        $this->assertSame($state['run_id'], $this->activation->status()['run_id']);
        $this->assertSame(['accounting_activation_started'], $this->auditActions());
        $this->assertSame('0', $this->storedValue('accounting_enabled'));
    }

    public function testStep_RunsEveryStepAndTurnsAccountingOnOnlyAfterTheBooksAreReadBack(): void
    {
        $this->insertCustomer();
        $this->insertSupplier();

        $summary = $this->activate('2026-11-01', 'SAR');

        $this->assertTrue($summary['done']);
        $this->assertSame('1', $this->storedValue('accounting_enabled'));
        $this->assertSame('SAR', $this->storedValue('accounting_base_currency'));
        $this->assertSame('2026-11-01', $this->storedValue('accounting_start_date'));
        $this->assertSame([], (new AccountingSettings())->readiness());
        $this->assertSame(['accounting_activation_started', 'accounting_enabled', 'accounting_activated'], array_values(array_filter($this->auditActions(), static fn (string $action): bool => in_array($action, ['accounting_activation_started', 'accounting_enabled', 'accounting_activated'], true))));
    }

    public function testStep_CreatesTheCalendarYearOfTheStartDateAndTheYearAfterThisOne(): void
    {
        $this->activate('2026-11-01');

        $names = array_column($this->accountingDb()->table('accounting_fiscal_years')->orderBy('start_date')->get()->getResultArray(), 'name');

        $this->assertSame('2026', $names[0]);
        $this->assertContains((string) ((int) date('Y') + 1), $names);
    }

    public function testStep_OpensTheAccountsOfEveryEntityThatLacksOne(): void
    {
        $customer = $this->insertCustomer();
        $supplier = $this->insertSupplier();
        $location = $this->insertStockLocation('Back store');

        $summary  = $this->activate();
        $entities = new EntityAccountService();

        $this->assertNotNull($entities->accountFor(EntityAccountService::CUSTOMER, $customer));
        $this->assertNotNull($entities->accountFor(EntityAccountService::SUPPLIER, $supplier));
        $this->assertNotNull($entities->accountFor(EntityAccountService::STOCK_LOCATION, $location));
        $this->assertSame([], $entities->missingCounts());
        $this->assertGreaterThanOrEqual(3, $summary['entities_created']);
    }

    public function testStep_LeavesTheDocumentsOfTheirOwnDatesOutOfTheBooks(): void
    {
        $this->sale('2026-03-10 10:00:00');
        $this->sale('2026-11-20 10:00:00');
        $journalsBefore = $this->countRows('accounting_journals');

        $state = $this->activation->begin($this->manager, 'SAR', '2026-11-01', [AccountingActivation::ACK_IMPACT, AccountingActivation::ACK_DOCUMENTS]);
        $this->runToTheEnd($state['run_id']);

        $this->assertSame('1', $this->storedValue('accounting_enabled'));
        $this->assertSame($journalsBefore, $this->countRows('accounting_journals'));
    }

    public function testStep_KeepsTheStateAfterEveryStepSoAnotherRequestCanResume(): void
    {
        $state = $this->activation->begin($this->manager, 'SAR', '2026-11-01', [AccountingActivation::ACK_IMPACT]);
        $seen  = [];

        for ($turn = 0; $turn < 12; $turn++) {
            $summary = $this->activation->step($state['run_id'], $this->manager, 0.0);
            $seen[]  = $summary['step'];

            $this->assertSame($summary['step'], $this->activation->status()['step']);

            if ($summary['done']) {
                break;
            }
        }

        $this->assertSame(['settings', 'entities', 'commit', 'verify'], array_slice($seen, 0, 4));
        $this->assertTrue($summary['done']);
    }

    public function testStep_CarriesOnFromTheStepThatFailedOnceTheCauseIsFixed(): void
    {
        $this->insertCustomer();

        $state = $this->activation->begin($this->manager, 'SAR', '2026-11-01', [AccountingActivation::ACK_IMPACT]);
        $this->accountingDb()->table('accounting_routing')->where('routing_key', 'sales.customers')->update(['account_id' => null]);

        $this->assertFailsWith(AccountingError::EntityMainAccountInvalid, fn () => $this->runToTheEnd($state['run_id']));

        $failed = $this->activation->status();

        $this->assertSame([AccountingActivation::FAILED, AccountingActivation::STEP_ENTITIES, 'entity_main_account_invalid'], [$failed['status'], $failed['step'], $failed['error']]);
        $this->assertContains('accounting_activation_failed', $this->auditActions());
        $this->assertSame('0', $this->storedValue('accounting_enabled'));

        $this->accountingDb()->table('accounting_routing')->where('routing_key', 'sales.customers')->update(['account_id' => $this->accountId(RoutingKey::from('sales.customers')->defaultSystemKey())]);

        $summary = $this->runToTheEnd($state['run_id']);

        $this->assertTrue($summary['done']);
        $this->assertNull($summary['error']);
        $this->assertSame('1', $this->storedValue('accounting_enabled'));
    }

    public function testStep_CreatesNothingTwiceWhenARunIsRepeated(): void
    {
        $this->insertCustomer();
        $summary = $this->activate();

        $accounts = $this->countRows('accounting_accounts');
        $years    = $this->countRows('accounting_fiscal_years');
        $audit    = $this->countRows('accounting_audit_log');

        $again = $this->activation->step($summary['run_id'], $this->manager);

        $this->assertTrue($again['done']);
        $this->assertSame([$accounts, $years, $audit], [$this->countRows('accounting_accounts'), $this->countRows('accounting_fiscal_years'), $this->countRows('accounting_audit_log')]);
    }

    public function testStep_RefusesARunThatIsNotTheOneInProgress(): void
    {
        $this->activation->begin($this->manager, 'SAR', '2026-11-01', [AccountingActivation::ACK_IMPACT]);

        $this->assertFailsWith(AccountingError::ActivationUnknown, fn () => $this->activation->step('0000000000000000', $this->manager));
    }

    public function testStep_RequiresThePermission(): void
    {
        $state = $this->activation->begin($this->manager, 'SAR', '2026-11-01', [AccountingActivation::ACK_IMPACT]);

        $this->assertFailsWith(AccountingError::PermissionDenied, fn () => $this->activation->step($state['run_id'], $this->employeeWithGrants([])));
    }

    public function testStep_RefusesASecondRequestWhileAnotherHoldsTheRunAndWorksOnceItLetsGo(): void
    {
        $state  = $this->activation->begin($this->manager, 'SAR', '2026-11-01', [AccountingActivation::ACK_IMPACT]);
        $other  = $this->openSecondConnection();
        $name   = 'ospos_activation_' . substr(sha1($this->accountingDb()->getDatabase()), 0, 10);
        $lock   = new NamedLock($other, 0);

        $this->assertSame(LockOutcome::Taken, $lock->take($name));

        try {
            $this->assertFailsWith(AccountingError::ActivationBusy, fn () => $this->activation->step($state['run_id'], $this->manager));
            $this->assertSame('0', $this->storedValue('accounting_enabled'));
        } finally {
            $lock->release($name);
            $other->close();
        }

        $this->assertTrue($this->runToTheEnd($state['run_id'])['done']);
    }

    public function testStep_ReturnsToOpeningAccountsWhenAnEntityAppearsBeforeTheSwitchIsTurned(): void
    {
        $state = $this->activation->begin($this->manager, 'SAR', '2026-11-01', [AccountingActivation::ACK_IMPACT]);

        for ($turn = 0; $turn < 12; $turn++) {
            if ($this->activation->step($state['run_id'], $this->manager, 0.0)['step'] === AccountingActivation::STEP_COMMIT) {
                break;
            }
        }

        $late = $this->insertCustomer('Late', 'Comer');

        $summary = $this->runToTheEnd($state['run_id']);

        $this->assertTrue($summary['done']);
        $this->assertNotNull((new EntityAccountService())->accountFor(EntityAccountService::CUSTOMER, $late));
    }

    public function testStep_DoesNotTellSuccessWhenTheBooksDoNotHoldWhatWasConfirmed(): void
    {
        $state = $this->activation->begin($this->manager, 'SAR', '2026-11-01', [AccountingActivation::ACK_IMPACT]);

        for ($turn = 0; $turn < 12; $turn++) {
            if ($this->activation->step($state['run_id'], $this->manager, 0.0)['step'] === AccountingActivation::STEP_VERIFY) {
                break;
            }
        }

        $this->setConfigValue('accounting_base_currency', 'USD');

        try {
            $this->activation->step($state['run_id'], $this->manager);
            $this->fail('Expected the verification to fail.');
        } catch (AccountingException $exception) {
            $this->assertSame(AccountingError::ActivationVerification, $exception->error);
            $this->assertSame('currency', $exception->context['name']);
        }

        $status = $this->activation->status();

        $this->assertFalse($status['done']);
        $this->assertSame([AccountingActivation::FAILED, 'activation_verification', 'currency'], [$status['status'], $status['error'], $status['error_name']]);
    }

    public function testSettingsEnable_WritesTheSwitchOnlyWhenTheReadinessIsEmpty(): void
    {
        $settings = new AccountingSettings();

        try {
            $settings->enable();
            $this->fail('Expected the readiness to refuse.');
        } catch (AccountingNotReadyException) {
            $this->assertSame('0', $this->storedValue('accounting_enabled'));
        }

        $this->assertSame([], $this->accountingDb()->table('accounting_audit_log')->where('action', 'accounting_enabled')->get()->getResultArray());
    }
}
