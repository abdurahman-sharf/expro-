<?php

namespace Tests\Libraries\Accounting;

use App\Libraries\Organization\BranchError;
use App\Libraries\Accounting\AccountingError;
use App\Libraries\Accounting\AccountingException;
use App\Libraries\Accounting\AccountingGuard;
use App\Libraries\Accounting\AccountingNotReadyException;
use App\Libraries\Accounting\AccountingSettings;
use App\Libraries\Accounting\EntityAccountService;
use App\Libraries\Accounting\JournalDescriptionTemplate;
use App\Libraries\Accounting\JournalService;
use App\Libraries\Accounting\PaymentType;
use App\Libraries\Accounting\ReadinessCode;
use App\Libraries\Accounting\ReadinessIssue;
use App\Libraries\Accounting\RoutingKey;
use App\Libraries\Organization\BranchException;
use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\DatabaseTestTrait;
use Config\Database;
use Tests\Support\AccountingFixtureTrait;
use Tests\Support\ItemFixtureTrait;

class AccountingSettingsTest extends CIUnitTestCase
{
    use DatabaseTestTrait;
    use AccountingFixtureTrait;
    use ItemFixtureTrait;

    protected $migrate     = true;
    protected $migrateOnce = true;
    protected $refresh     = false;
    protected $namespace   = null;
    private AccountingSettings $settings;

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
        $this->settings = new AccountingSettings();
    }

    protected function tearDown(): void
    {
        $this->setConfigValue('accounting_line_description_required', '0');
        $this->setConfigValue('accounting_expense_account_required', '0');
        $this->setConfigValue('accounting_revenue_account_required', '0');
        $this->removeInsertedEntities();

        parent::tearDown();
    }

    private function completeTheSettings(): void
    {
        $this->setConfigValue('accounting_base_currency', 'SAR');
        $this->setConfigValue('accounting_start_date', '2026-01-01');
        $this->accountingDb()->table('accounting_fiscal_years')->insert([
            'name'       => '2026',
            'start_date' => '2026-01-01',
            'end_date'   => '2026-12-31',
        ]);
    }

    /**
     * @param list<ReadinessIssue> $issues
     *
     * @return list<string>
     */
    private function codesOf(array $issues): array
    {
        return array_map(static fn ($issue): string => $issue->code->value, $issues);
    }

    public function testIsEnabled_IsFalseOnAFreshInstall(): void
    {
        $this->assertFalse($this->settings->isEnabled());
    }

    public function testReadiness_ReportsWhatAFreshInstallStillNeeds(): void
    {
        $this->assertSame(
            ['base_currency_missing', 'start_date_missing', 'no_fiscal_year'],
            $this->codesOf($this->settings->readiness()),
        );
    }

    public function testReadiness_IsEmptyWhenEverythingIsConfigured(): void
    {
        $this->completeTheSettings();

        $this->assertSame([], $this->settings->readiness());
    }

    public function testReadiness_RejectsAStartDateOutsideEveryFiscalYear(): void
    {
        $this->completeTheSettings();
        $this->setConfigValue('accounting_start_date', '2025-06-01');

        $issues = $this->settings->readiness();

        $this->assertSame([ReadinessCode::StartDateOutsideFiscalYear], array_column($issues, 'code'));
        $this->assertSame('2025-06-01', $issues[0]->subject);
    }

    public function testReadiness_RejectsAMalformedStartDate(): void
    {
        $this->completeTheSettings();
        $this->setConfigValue('accounting_start_date', '01/01/2026');

        $this->assertSame(['start_date_missing'], $this->codesOf($this->settings->readiness()));
    }

    public function testReadiness_ReportsAnUnmappedRoutingKey(): void
    {
        $this->completeTheSettings();
        $this->accountingDb()->table('accounting_routing')->where('routing_key', 'sales.cogs')->update(['account_id' => null]);

        $issues = $this->settings->readiness();

        $this->assertSame([ReadinessCode::RoutingUnmapped], array_column($issues, 'code'));
        $this->assertSame('sales.cogs', $issues[0]->subject);
    }

    public function testReadiness_ReportsTheMainAccountOfTheBanksLeftUnmapped(): void
    {
        $this->completeTheSettings();
        $this->accountingDb()->table('accounting_routing')->where('routing_key', 'treasuries.banks')->update(['account_id' => null]);

        $issues = $this->settings->readiness();

        $this->assertSame([ReadinessCode::RoutingUnmapped], array_column($issues, 'code'));
        $this->assertSame('treasuries.banks', $issues[0]->subject);
    }

    public function testReadiness_ReportsAPostableAccountOnTheRowOfTheBanks(): void
    {
        $this->completeTheSettings();
        $this->accountingDb()->table('accounting_routing')->where('routing_key', 'treasuries.banks')->update(['account_id' => $this->accountId('cash')]);

        $issues = $this->settings->readiness();

        $this->assertSame([ReadinessCode::RoutingAccountInvalid], array_column($issues, 'code'));
        $this->assertSame('treasuries.banks', $issues[0]->subject);
    }

    public function testReadiness_ReportsARoutingKeyPointingToAGroupAccount(): void
    {
        $this->completeTheSettings();
        $this->accountingDb()->table('accounting_routing')
            ->where('routing_key', 'cash.default')
            ->update(['account_id' => $this->accountIdByCode('1')]);

        $issues = $this->settings->readiness();

        $this->assertSame([ReadinessCode::RoutingAccountInvalid], array_column($issues, 'code'));
        $this->assertSame('cash.default', $issues[0]->subject);
    }

    public function testReadiness_ReportsARoutingKeyPointingToAnInactiveAccount(): void
    {
        $this->completeTheSettings();
        $this->accountingDb()->table('accounting_accounts')->where('system_key', 'cash')->update(['active' => 0]);

        $this->assertContains('routing_account_invalid', $this->codesOf($this->settings->readiness()));
    }

    public function testReadiness_IgnoresACancelledRowWithoutAnAccount(): void
    {
        $this->completeTheSettings();
        $this->accountingDb()->table('accounting_routing')->where('routing_key', 'sales.discount')->update(['account_id' => null]);

        $this->assertSame([], $this->settings->readiness());
    }

    public function testReadiness_IgnoresAnEmptyRowThatMayStayEmpty(): void
    {
        $this->completeTheSettings();
        $this->accountingDb()->table('accounting_routing')->where('routing_key', 'purchases.returns')->update(['account_id' => null]);

        $this->assertSame([], $this->settings->readiness());
    }

    public function testReadiness_ReportsAPostableAccountOnAnEntityRow(): void
    {
        $this->completeTheSettings();
        $this->accountingDb()->table('accounting_routing')->where('routing_key', 'sales.customers')->update(['account_id' => $this->accountId('cash')]);

        $issues = $this->settings->readiness();

        $this->assertSame([ReadinessCode::RoutingAccountInvalid], array_column($issues, 'code'));
        $this->assertSame('sales.customers', $issues[0]->subject);
    }

    public function testReadiness_ReportsTheEntitiesThatHaveNoAccountYet(): void
    {
        $this->completeTheSettings();
        $this->insertCustomer();
        $this->insertSupplier();

        $issues = $this->settings->readiness();

        $this->assertSame([ReadinessCode::EntityAccountsMissing], array_column($issues, 'code'));
        $this->assertSame('2', $issues[0]->subject);

        (new EntityAccountService())->fill();

        $this->assertSame([], $this->settings->readiness());
    }

    public function testReadiness_IgnoresTheEntitiesOfARowThatIsNotAutomaticButBlocksOnItsType(): void
    {
        $this->completeTheSettings();
        $this->insertCustomer();
        $this->accountingDb()->table('accounting_routing')->where('routing_key', 'sales.customers')->update(['routing_type' => 'per_item']);

        $issues = $this->settings->readiness();

        $this->assertSame([ReadinessCode::RoutingTypeUnsupported], array_column($issues, 'code'));
        $this->assertSame('sales.customers', $issues[0]->subject);
    }

    public function testReadiness_BlocksARowSavedWithATypePostingDoesNotApply(): void
    {
        $this->completeTheSettings();
        $this->accountingDb()->table('accounting_routing')->where('routing_key', 'inventory.locations')->update(['routing_type' => 'per_item']);
        $this->accountingDb()->table('accounting_routing')->where('routing_key', 'purchases.suppliers')->update(['routing_type' => 'per_item']);

        $issues = $this->settings->readiness();

        $this->assertSame([ReadinessCode::RoutingTypeUnsupported, ReadinessCode::RoutingTypeUnsupported], array_column($issues, 'code'));
        $this->assertEqualsCanonicalizing(['inventory.locations', 'purchases.suppliers'], array_column($issues, 'subject'));
    }

    public function testReadiness_AcceptsTheRowsSetToTheTypesPostingNowApplies(): void
    {
        $this->completeTheSettings();
        $this->insertCustomer();
        (new EntityAccountService())->fill();

        foreach ([['sales.revenue', 'per_item'], ['sales.returns', 'per_item'], ['sales.customers', 'main_per_entity']] as [$key, $type]) {
            $this->accountingDb()->table('accounting_routing')->where('routing_key', $key)->update(['routing_type' => $type]);
        }

        $this->assertSame([], $this->settings->readiness());
    }

    public function testReadiness_BlocksOnACustomerWithoutAnAccountWhileTheRowNamesTheAccountPerEntity(): void
    {
        $this->completeTheSettings();
        $this->insertCustomer();
        $this->accountingDb()->table('accounting_routing')->where('routing_key', 'sales.customers')->update(['routing_type' => 'main_per_entity']);

        $this->assertSame([ReadinessCode::EntityAccountsMissing], array_column($this->settings->readiness(), 'code'));
    }

    public function testReadiness_BlocksOnAnAccountAnItemNamesThatCannotTakeEntriesWhileTheRowIsPerItem(): void
    {
        $this->completeTheSettings();
        $account = $this->customAccount('41', '4101', 'Item revenue');
        $itemId  = $this->createTestItem();
        $this->accountingDb()->table('item_routing_accounts')->insert(['item_id' => $itemId, 'routing_key' => 'sales.revenue', 'account_id' => $account]);
        $this->accountingDb()->table('accounting_accounts')->where('account_id', $account)->update(['active' => 0]);
        $this->accountingDb()->table('accounting_routing')->where('routing_key', 'sales.revenue')->update(['routing_type' => 'per_item']);

        try {
            $this->settings->enable();
            $this->fail('Accounting must not be turned on over an item whose account cannot take entries.');
        } catch (AccountingNotReadyException $exception) {
            $this->assertSame([ReadinessCode::ItemAccountInvalid], array_column($exception->issues, 'code'));
        }

        $this->assertFalse($this->settings->isEnabled());
    }

    public function testEnable_RejectsWhileARowIsSavedWithATypePostingDoesNotApply(): void
    {
        $this->completeTheSettings();
        $this->accountingDb()->table('accounting_routing')->where('routing_key', 'sales.customers')->update(['routing_type' => 'per_item']);

        try {
            $this->settings->enable();
            $this->fail('Accounting must not be turned on over a routing type it cannot apply.');
        } catch (AccountingNotReadyException $exception) {
            $this->assertSame([ReadinessCode::RoutingTypeUnsupported], array_column($exception->issues, 'code'));
        }

        $this->assertFalse($this->settings->isEnabled());
    }

    public function testReadiness_ReportsARequiredPaymentTypeWithoutAnAccount(): void
    {
        $this->completeTheSettings();
        $this->accountingDb()->table('accounting_routing')->where('routing_key', 'payment.wallet')->update(['account_id' => null]);

        $issues = $this->settings->readiness();

        $this->assertSame(['payment_type_unrouted'], $this->codesOf($issues));
        $this->assertSame('wallet', $issues[0]->subject);
    }

    public function testEnable_RefusesWhileAPaymentTypeHasNoAccount(): void
    {
        $this->completeTheSettings();
        $this->accountingDb()->table('accounting_routing')->where('routing_key', 'payment.cash')->update(['account_id' => null]);

        try {
            $this->settings->enable($this->manager());
            $this->fail('Enabling should have been refused.');
        } catch (AccountingNotReadyException $exception) {
            $this->assertSame(['payment_type_unrouted'], $this->codesOf($exception->issues));
        }

        $this->assertFalse($this->settings->isEnabled());
    }

    public function testNotices_DoNotStopAccountingFromBeingTurnedOn(): void
    {
        $this->completeTheSettings();
        $this->setConfigValue('accounting_start_date', '2026-12-31');
        $this->accountingDb()->table('sales')->insert(['sale_time' => '2026-06-01 10:00:00', 'employee_id' => $this->employeeWithGrants([]), 'comment' => 'earlier sale']);
        $saleId = (int) $this->accountingDb()->insertID();

        try {
            $this->assertContains('sales_before_start_date', $this->codesOf($this->settings->notices()));
            $this->assertSame([], $this->settings->readiness());

            $this->settings->enable($this->manager());

            $this->assertTrue($this->settings->isEnabled());
        } finally {
            $this->accountingDb()->table('sales')->where('sale_id', $saleId)->delete();
        }
    }

    public function testEnable_RefusesWhileTheSettingsAreIncomplete(): void
    {
        try {
            $this->settings->enable($this->manager());
            $this->fail('Enabling should have been refused.');
        } catch (AccountingNotReadyException $exception) {
            $this->assertSame(
                ['base_currency_missing', 'start_date_missing', 'no_fiscal_year'],
                $this->codesOf($exception->issues),
            );
        }

        $this->assertFalse($this->settings->isEnabled());
        $this->assertSame(0, $this->countRows('accounting_audit_log'));
    }

    public function testEnable_TurnsTheSwitchOnAndRecordsIt(): void
    {
        $this->completeTheSettings();
        $employee = $this->employeeWithGrants([AccountingGuard::SETTINGS_MANAGE]);

        $this->settings->enable($employee);

        $this->assertTrue($this->settings->isEnabled());

        $audit = $this->accountingDb()->table('accounting_audit_log')->get()->getRow();
        $this->assertSame('accounting_enabled', $audit->action);
        $this->assertSame((string) $employee, (string) $audit->employee_id);
    }

    public function testDisable_TurnsTheSwitchOffAndRecordsIt(): void
    {
        $this->completeTheSettings();
        $employee = $this->employeeWithGrants([AccountingGuard::SETTINGS_MANAGE]);
        $this->settings->enable($employee);

        $this->settings->disable($employee);

        $this->assertFalse($this->settings->isEnabled());
        $this->assertSame(2, $this->countRows('accounting_audit_log'));
    }

    private function manager(?array $branchIds = null): int
    {
        return $this->employeeWithGrants([AccountingGuard::SETTINGS_MANAGE], $branchIds);
    }

    private function assertUpdateFails(AccountingError $expected, array $input, ?int $employeeId = null): void
    {
        try {
            $this->settings->update($input, $employeeId);
        } catch (AccountingException $exception) {
            $this->assertSame($expected, $exception->error);

            return;
        }

        $this->fail('Expected the failure ' . $expected->value . '.');
    }

    public function testUpdate_SavesTheCurrencyTheStartDateAndTheAutomaticYearSetting(): void
    {
        $this->clearJournals();

        $this->settings->update(['base_currency' => 'usd', 'start_date' => '2026-04-01', 'auto_fiscal_year' => false], $this->manager());

        $this->assertSame('USD', $this->settings->baseCurrency());
        $this->assertSame('2026-04-01', $this->settings->startDate());
        $this->assertFalse($this->settings->autoFiscalYear());

        $this->settings->update(['auto_fiscal_year' => true], $this->manager());

        $this->assertTrue($this->settings->autoFiscalYear());
    }

    public function testUpdate_RejectsAnInvalidCurrencyOrDate(): void
    {
        $this->clearJournals();

        $this->assertUpdateFails(AccountingError::InvalidCurrency, ['base_currency' => 'SAUDI']);
        $this->assertUpdateFails(AccountingError::InvalidCurrency, ['base_currency' => '12$']);
        $this->assertUpdateFails(AccountingError::InvalidDate, ['start_date' => '2026-02-30']);
        $this->assertUpdateFails(AccountingError::InvalidDate, ['start_date' => 'soon']);
    }

    public function testUpdate_FixesTheCurrencyAndTheStartDateOnceAJournalIsPosted(): void
    {
        $this->reinstallChart();
        $this->setConfigValue('accounting_base_currency', 'SAR');
        $this->setConfigValue('accounting_start_date', '2026-01-01');
        (new JournalService())->postNew($this->journalInput());

        $this->assertTrue($this->settings->isLocked());
        $this->assertUpdateFails(AccountingError::SettingsLocked, ['base_currency' => 'USD']);
        $this->assertUpdateFails(AccountingError::SettingsLocked, ['start_date' => '2026-02-01']);

        $this->settings->update(['base_currency' => 'SAR', 'start_date' => '2026-01-01', 'auto_fiscal_year' => false]);

        $this->assertFalse($this->settings->autoFiscalYear());
    }

    public function testUpdate_RejectsTheCurrencyAndTheStartDateWhileAnyJournalExistsEvenADraft(): void
    {
        $this->reinstallChart();
        $this->setConfigValue('accounting_base_currency', 'SAR');
        $this->setConfigValue('accounting_start_date', '2026-01-01');
        (new JournalService())->saveDraft($this->journalInput());

        $this->assertTrue($this->settings->isLocked());
        $this->assertUpdateFails(AccountingError::SettingsLocked, ['base_currency' => 'USD']);
        $this->assertUpdateFails(AccountingError::SettingsLocked, ['start_date' => '2026-02-01']);
        $this->assertSame('SAR', $this->settings->baseCurrency());
        $this->assertSame('2026-01-01', $this->settings->startDate());
    }

    public function testUpdate_RejectsTheCurrencyAndTheStartDateWhileAPreviewJournalExists(): void
    {
        $this->reinstallChart();
        $this->setConfigValue('accounting_base_currency', 'SAR');
        $this->setConfigValue('accounting_start_date', '2026-01-01');
        $journalId = (new JournalService())->saveDraft($this->journalInput());
        $this->accountingDb()->table('accounting_journals')->where('journal_id', $journalId)->update(['status' => 'preview']);

        $this->assertUpdateFails(AccountingError::SettingsLocked, ['base_currency' => 'USD']);
        $this->assertUpdateFails(AccountingError::SettingsLocked, ['start_date' => '2026-02-01']);
    }

    public function testUpdate_AllowsTheCurrencyAndTheStartDateWhileNoJournalExists(): void
    {
        $this->clearJournals();
        $this->setConfigValue('accounting_base_currency', 'SAR');
        $this->setConfigValue('accounting_start_date', '2026-01-01');

        $this->assertFalse($this->settings->isLocked());

        $this->settings->update(['base_currency' => 'USD', 'start_date' => '2026-02-01'], $this->manager());

        $this->assertSame('USD', $this->settings->baseCurrency());
        $this->assertSame('2026-02-01', $this->settings->startDate());
    }

    public function testUpdate_RejectsAStartDateOutsideEveryFiscalYear(): void
    {
        $this->clearJournals();
        $this->accountingDb()->table('accounting_fiscal_years')->where('start_date >=', '2027-01-01')->delete();

        $this->assertUpdateFails(AccountingError::StartDateOutsideFiscalYear, ['start_date' => '2040-01-01']);
    }

    public function testUpdate_RequiresThePermissionAndEveryBranch(): void
    {
        $this->clearJournals();

        $this->assertUpdateFails(AccountingError::PermissionDenied, ['base_currency' => 'USD'], $this->employeeWithGrants([]));

        $this->expectException(BranchException::class);

        $this->settings->update(['base_currency' => 'USD'], $this->manager([1]));
    }

    public function testUpdate_RecordsWhatChangedInTheAuditLog(): void
    {
        $this->clearJournals();
        $employee = $this->manager();

        $this->settings->update(['base_currency' => 'EUR'], $employee);

        $audit = $this->accountingDb()->table('accounting_audit_log')->where('action', 'settings_updated')->get()->getRow();

        $this->assertSame((string) $employee, (string) $audit->employee_id);
        $this->assertSame(['before' => ['base_currency' => ''], 'after' => ['base_currency' => 'EUR']], json_decode($audit->details, true));
    }

    public function testUpdate_WritesNothingWhenNothingChanges(): void
    {
        $this->clearJournals();
        $this->setConfigValue('accounting_base_currency', 'SAR');
        $this->setConfigValue('accounting_start_date', '2026-01-01');

        $this->settings->update(['base_currency' => 'SAR', 'start_date' => '2026-01-01'], $this->manager());

        $this->assertSame(0, $this->countRows('accounting_audit_log'));
    }

    public function testLineDescriptionRequired_IsOffUntilTheBooksAskForIt(): void
    {
        $this->accountingDb()->table('app_config')->where('key', 'accounting_line_description_required')->delete();

        $this->assertFalse($this->settings->lineDescriptionRequired());

        $this->setConfigValue('accounting_line_description_required', '1');

        $this->assertTrue($this->settings->lineDescriptionRequired());

        $this->setConfigValue('accounting_line_description_required', '0');

        $this->assertFalse($this->settings->lineDescriptionRequired());
    }

    public function testUpdate_SavesTheLineDescriptionRequirementEvenAfterAJournalIsPosted(): void
    {
        $this->reinstallChart();
        $this->setConfigValue('accounting_base_currency', 'SAR');
        $this->setConfigValue('accounting_start_date', '2026-01-01');
        $this->setConfigValue('accounting_line_description_required', '0');
        (new JournalService())->postNew($this->journalInput());

        $this->assertTrue($this->settings->isLocked());

        $this->settings->update(['line_description_required' => true], $this->manager());
        $this->assertTrue($this->settings->lineDescriptionRequired());
        $this->assertSame('SAR', $this->settings->baseCurrency());

        $this->settings->update(['line_description_required' => false], $this->manager());
        $this->assertFalse($this->settings->lineDescriptionRequired());
    }

    public function testUpdate_RecordsTheLineDescriptionRequirementInTheAuditLogAndLeavesTheOtherSettingsAlone(): void
    {
        $this->clearJournals();
        $this->setConfigValue('accounting_line_description_required', '0');
        $employee = $this->manager();

        $this->settings->update(['line_description_required' => true], $employee);

        $audit = $this->accountingDb()->table('accounting_audit_log')->where('action', 'settings_updated')->get()->getRow();

        $this->assertSame((string) $employee, (string) $audit->employee_id);
        $this->assertSame(['before' => ['line_description_required' => false], 'after' => ['line_description_required' => true]], json_decode($audit->details, true));
    }

    public function testUpdate_WritesNothingWhenTheLineDescriptionRequirementIsUnchanged(): void
    {
        $this->clearJournals();
        $this->setConfigValue('accounting_line_description_required', '1');

        $this->settings->update(['line_description_required' => true], $this->manager());

        $this->assertSame(0, $this->countRows('accounting_audit_log'));
        $this->assertTrue($this->settings->lineDescriptionRequired());
    }

    public function testUpdate_RequiresThePermissionToChangeTheLineDescriptionRequirement(): void
    {
        $this->clearJournals();
        $this->setConfigValue('accounting_line_description_required', '0');

        $this->assertUpdateFails(AccountingError::PermissionDenied, ['line_description_required' => true], $this->employeeWithGrants([]));

        $this->assertFalse($this->settings->lineDescriptionRequired());
        $this->assertSame(0, $this->countRows('accounting_audit_log'));
    }

    public function testLastManualNumber_FollowsTheSequence(): void
    {
        $this->clearJournals();

        $this->assertSame(0, $this->settings->lastManualNumber());

        $this->accountingDb()->table('accounting_sequences')->where('series', 'manual')->update(['last_number' => 41]);

        $this->assertSame(41, $this->settings->lastManualNumber());
    }

    public function testEnable_RequiresThePermissionAndEveryBranchForAnEmployee(): void
    {
        $this->completeTheSettings();

        try {
            $this->settings->enable($this->employeeWithGrants([]));
            $this->fail('Expected the permission to be required.');
        } catch (AccountingException $exception) {
            $this->assertSame(AccountingError::PermissionDenied, $exception->error);
        }

        $this->expectException(BranchException::class);

        $this->settings->enable($this->manager([1]));
    }

    public function testExpenseAccountRequired_IsOffUntilTheSettingTurnsItOn(): void
    {
        $this->assertFalse($this->settings->expenseAccountRequired());

        $this->settings->update(['expense_account_required' => true], $this->manager());

        $this->assertTrue($this->settings->expenseAccountRequired());

        $this->settings->update(['expense_account_required' => false], $this->manager());

        $this->assertFalse($this->settings->expenseAccountRequired());
    }

    public function testUpdate_RecordsTheChangeOfTheExpenseAccountSettingAndRequiresThePermission(): void
    {
        $this->accountingDb()->table('accounting_audit_log')->truncate();

        $this->settings->update(['expense_account_required' => true], $this->manager());

        $details = json_decode($this->accountingDb()->table('accounting_audit_log')->where('action', 'settings_updated')->get()->getRow()->details, true);

        $this->assertSame(['expense_account_required' => true], $details['after']);

        try {
            $this->settings->update(['expense_account_required' => false], $this->employeeWithGrants([]));
            $this->fail('Expected the permission to be required.');
        } catch (AccountingException $exception) {
            $this->assertSame(AccountingError::PermissionDenied, $exception->error);
        }

        $this->assertTrue($this->settings->expenseAccountRequired());
    }

    public function testCustomerOpeningEnabled_IsOffUntilTheSettingTurnsItOn(): void
    {
        $this->assertFalse($this->settings->customerOpeningEnabled());

        $this->settings->update(['customer_opening' => true], $this->manager());

        $this->assertTrue($this->settings->customerOpeningEnabled());

        $this->settings->update(['customer_opening' => false], $this->manager());

        $this->assertFalse($this->settings->customerOpeningEnabled());
    }

    public function testUpdate_RecordsTheChangeOfTheCustomerOpeningSettingAndRequiresThePermission(): void
    {
        $this->accountingDb()->table('accounting_audit_log')->truncate();

        $this->settings->update(['customer_opening' => true], $this->manager());

        $details = json_decode($this->accountingDb()->table('accounting_audit_log')->where('action', 'settings_updated')->get()->getRow()->details, true);

        $this->assertSame(['customer_opening' => true], $details['after']);
        $this->assertSame(['customer_opening' => false], $details['before']);

        try {
            $this->settings->update(['customer_opening' => false], $this->employeeWithGrants([]));
            $this->fail('Expected the permission to be required.');
        } catch (AccountingException $exception) {
            $this->assertSame(AccountingError::PermissionDenied, $exception->error);
        }

        $this->assertTrue($this->settings->customerOpeningEnabled());
        $this->setConfigValue('accounting_customer_opening', '0');
    }

    public function testRevenueAccountRequired_IsOffUntilTheSettingTurnsItOnAndIsAuditedAndNeedsThePermission(): void
    {
        $this->accountingDb()->table('accounting_audit_log')->truncate();

        $this->assertFalse($this->settings->revenueAccountRequired());

        $this->settings->update(['revenue_account_required' => true], $this->manager());

        $this->assertTrue($this->settings->revenueAccountRequired());
        $this->assertFalse($this->settings->expenseAccountRequired());

        $details = json_decode($this->accountingDb()->table('accounting_audit_log')->where('action', 'settings_updated')->get()->getRow()->details, true);

        $this->assertSame(['revenue_account_required' => true], $details['after']);

        try {
            $this->settings->update(['revenue_account_required' => false], $this->employeeWithGrants([]));
            $this->fail('Expected the permission to be required.');
        } catch (AccountingException $exception) {
            $this->assertSame(AccountingError::PermissionDenied, $exception->error);
        }

        $this->assertTrue($this->settings->revenueAccountRequired());

        $this->settings->update(['revenue_account_required' => false], $this->manager());

        $this->assertFalse($this->settings->revenueAccountRequired());
    }

    public function testNotices_ListTheExpensesAndTheCashUpsThatAreNotInTheBooks(): void
    {
        $db         = $this->accountingDb();
        $employeeId = $this->employeeWithGrants([]);

        $db->query('SET FOREIGN_KEY_CHECKS = 0');
        $db->table('expenses')->truncate();
        $db->table('cash_up')->truncate();
        $db->query('SET FOREIGN_KEY_CHECKS = 1');
        $db->table('expense_categories')->insert(['category_name' => 'Notice ' . uniqid(), 'category_description' => '']);
        $categoryId = (int) $db->insertID();

        $db->table('expenses')->insert(['date' => '2026-01-10 10:00:00', 'amount' => '10.00', 'payment_type' => 'Cash', 'expense_category_id' => $categoryId, 'description' => 'earlier', 'employee_id' => $employeeId]);
        $db->table('expenses')->insert(['date' => '2026-01-11 10:00:00', 'amount' => '20.00', 'payment_type' => 'Cash', 'expense_category_id' => $categoryId, 'description' => 'earlier', 'employee_id' => $employeeId]);
        $db->table('cash_up')->insert([
            'open_date' => '2026-01-10 08:00:00', 'close_date' => '2026-01-10 18:00:00', 'open_amount_cash' => '0', 'transfer_amount_cash' => '0', 'closed_amount_cash' => '5', 'closed_amount_card' => '0',
            'closed_amount_check' => '0', 'closed_amount_total' => '5', 'description' => 'earlier', 'open_employee_id' => $employeeId, 'close_employee_id' => $employeeId, 'closed_amount_due' => '0',
        ]);

        try {
            $notices = array_column(array_map(static fn (ReadinessIssue $issue): array => [$issue->code->value, $issue->subject], $this->settings->notices()), 1, 0);

            $this->assertSame(['expenses_unposted' => '2', 'cashups_unposted' => '1'], array_intersect_key($notices, ['expenses_unposted' => 1, 'cashups_unposted' => 1]));
            $this->assertSame([], array_intersect($this->codesOf($this->settings->readiness()), ['expenses_unposted', 'cashups_unposted']));
        } finally {
            $db->query('SET FOREIGN_KEY_CHECKS = 0');
            $db->table('expenses')->truncate();
            $db->table('cash_up')->truncate();
            $db->query('SET FOREIGN_KEY_CHECKS = 1');
            $db->table('expense_categories')->where('expense_category_id', $categoryId)->delete();
        }
    }

    public function testBackfillEnabled_IsOffWhenTheSettingIsNotThereAtAll(): void
    {
        $this->accountingDb()->table('app_config')->where('key', 'accounting_backfill_enabled')->delete();

        try {
            $this->assertFalse($this->settings->backfillEnabled());
        } finally {
            $this->setConfigValue('accounting_backfill_enabled', '0');
        }
    }

    public function testBackfillEnabled_IsOffUntilTheSettingTurnsItOnAndIsAuditedAndNeedsThePermission(): void
    {
        $this->accountingDb()->table('accounting_audit_log')->truncate();

        $this->assertFalse($this->settings->backfillEnabled());

        $this->settings->update(['backfill_enabled' => true], $this->manager());

        $details = json_decode($this->accountingDb()->table('accounting_audit_log')->where('action', 'settings_updated')->get()->getRow()->details, true);

        $this->assertTrue($this->settings->backfillEnabled());
        $this->assertSame(['backfill_enabled' => true], $details['after']);
        $this->assertSame(['backfill_enabled' => false], $details['before']);

        try {
            $this->settings->update(['backfill_enabled' => false], $this->employeeWithGrants([]));
            $this->fail('Expected the permission to be required.');
        } catch (AccountingException $exception) {
            $this->assertSame(AccountingError::PermissionDenied, $exception->error);
        }

        $this->assertTrue($this->settings->backfillEnabled());

        $this->settings->update(['backfill_enabled' => false], $this->manager());

        $this->assertFalse($this->settings->backfillEnabled());
    }

    public function testUpdate_RefusesToTurnTheRetroactivePostingOnForSomeoneWhoDoesNotWorkInEveryBranch(): void
    {
        $this->createBranch('B2', 'Second branch');

        try {
            $this->settings->update(['backfill_enabled' => true], $this->manager([1]));
            $this->fail('Expected the company scope to be required.');
        } catch (BranchException $exception) {
            $this->assertSame(BranchError::CompanyScopeNeeded, $exception->error);
        }

        $this->assertFalse($this->settings->backfillEnabled());
    }

    private function forgetChargeSettings(): void
    {
        $this->accountingDb()->table('app_config')->whereIn('key', ['accounting_sales_adjustment', 'accounting_sales_shipping', 'accounting_shipping_tax_percent', 'accounting_debit_note', 'accounting_journal_description_sales', 'accounting_journal_description_purchases'])->delete();
    }

    public function testSalesAdjustmentEnabled_IsOffUntilTurnedOnAndSoAreShippingAndTheDebitNote(): void
    {
        $this->forgetChargeSettings();

        $this->assertFalse($this->settings->salesAdjustmentEnabled());
        $this->assertFalse($this->settings->salesShippingEnabled());
        $this->assertFalse($this->settings->debitNoteEnabled());
        $this->assertSame('0', $this->settings->shippingTaxPercent());
        $this->assertSame('', $this->settings->journalDescriptionTemplate(JournalDescriptionTemplate::SALES));
        $this->assertSame('', $this->settings->journalDescriptionTemplate(JournalDescriptionTemplate::PURCHASES));
    }

    public function testUpdate_SavesTheSettingsOfTheChargesTheDebitNoteAndTheTemplatesAndAuditsWhatChanged(): void
    {
        $this->forgetChargeSettings();
        $employee = $this->manager();

        $this->settings->update([
            'sales_adjustment'              => true,
            'sales_shipping'                => true,
            'shipping_tax_percent'          => '15.5',
            'debit_note'                    => true,
            'journal_description_sales'     => ' {type} {number} - {party} ',
            'journal_description_purchases' => 'Purchase {number}',
        ], $employee);

        $this->assertTrue($this->settings->salesAdjustmentEnabled());
        $this->assertTrue($this->settings->salesShippingEnabled());
        $this->assertSame('15.5', $this->settings->shippingTaxPercent());
        $this->assertTrue($this->settings->debitNoteEnabled());
        $this->assertSame('{type} {number} - {party}', $this->settings->journalDescriptionTemplate(JournalDescriptionTemplate::SALES));
        $this->assertSame('Purchase {number}', $this->settings->journalDescriptionTemplate(JournalDescriptionTemplate::PURCHASES));

        $audit = $this->accountingDb()->table('accounting_audit_log')->where('action', 'settings_updated')->orderBy('audit_id', 'DESC')->get()->getRow();
        $details = json_decode($audit->details, true);

        $this->assertSame((string) $employee, (string) $audit->employee_id);
        $this->assertSame(['sales_adjustment' => false, 'sales_shipping' => false, 'shipping_tax_percent' => '0', 'debit_note' => false, 'journal_description_sales' => '', 'journal_description_purchases' => ''], $details['before']);
        $this->assertSame('15.5', $details['after']['shipping_tax_percent']);
    }

    public function testUpdate_WritesNothingWhenTheSettingsOfTheChargesAreUnchanged(): void
    {
        $this->forgetChargeSettings();
        $before = $this->accountingDb()->table('accounting_audit_log')->where('action', 'settings_updated')->countAllResults();

        $this->settings->update(['sales_adjustment' => false, 'sales_shipping' => false, 'debit_note' => false, 'shipping_tax_percent' => '0', 'journal_description_sales' => ''], $this->manager());

        $this->assertSame($before, $this->accountingDb()->table('accounting_audit_log')->where('action', 'settings_updated')->countAllResults());
    }

    public function testUpdate_RejectsATaxOnShippingThatIsNotAPercentage(): void
    {
        $this->forgetChargeSettings();

        foreach (['-1', '100.01', 'abc', '1.23456', ''] as $percent) {
            try {
                $this->settings->update(['shipping_tax_percent' => $percent], $this->manager());
                $this->fail("'{$percent}' should be refused");
            } catch (AccountingException $exception) {
                $this->assertSame(AccountingError::ShippingTaxPercentInvalid, $exception->error, $percent);
            }
        }

        $this->assertSame('0', $this->settings->shippingTaxPercent());
    }

    private function forgetPickerThreshold(): void
    {
        $this->accountingDb()->table('app_config')->where('key', 'accounting_picker_threshold')->delete();
    }

    public function testPickerThreshold_IsTwoHundredUntilSetAndFallsBackWhenTheStoredValueIsNotUsable(): void
    {
        $this->forgetPickerThreshold();

        $this->assertSame(200, $this->settings->pickerThreshold());

        foreach (['abc', '0', '-5', '100001', '1.5', ''] as $stored) {
            $this->setConfigValue('accounting_picker_threshold', $stored);

            $this->assertSame(200, $this->settings->pickerThreshold(), "'{$stored}' is not a usable threshold");
        }

        $this->setConfigValue('accounting_picker_threshold', '50');

        $this->assertSame(50, $this->settings->pickerThreshold());
    }

    public function testUpdate_SavesThePickerThresholdAndAuditsWhatChanged(): void
    {
        $this->forgetPickerThreshold();
        $employee = $this->manager();

        $this->settings->update(['picker_threshold' => ' 75 '], $employee);

        $this->assertSame(75, $this->settings->pickerThreshold());

        $audit   = $this->accountingDb()->table('accounting_audit_log')->where('action', 'settings_updated')->orderBy('audit_id', 'DESC')->get()->getRow();
        $details = json_decode($audit->details, true);

        $this->assertSame((string) $employee, (string) $audit->employee_id);
        $this->assertSame(['picker_threshold' => '200'], $details['before']);
        $this->assertSame(['picker_threshold' => '75'], $details['after']);
    }

    public function testUpdate_WritesNothingWhenThePickerThresholdIsUnchanged(): void
    {
        $this->forgetPickerThreshold();
        $before = $this->accountingDb()->table('accounting_audit_log')->where('action', 'settings_updated')->countAllResults();

        $this->settings->update(['picker_threshold' => '200'], $this->manager());

        $this->assertSame($before, $this->accountingDb()->table('accounting_audit_log')->where('action', 'settings_updated')->countAllResults());
    }

    public function testUpdate_RejectsAPickerThresholdThatIsNotAWholeNumberFromOneToTheMaximum(): void
    {
        $this->forgetPickerThreshold();

        foreach (['0', '-1', '100001', 'abc', '1.5', '', '1e3'] as $threshold) {
            try {
                $this->settings->update(['picker_threshold' => $threshold], $this->manager());
                $this->fail("'{$threshold}' should be refused");
            } catch (AccountingException $exception) {
                $this->assertSame(AccountingError::PickerThresholdInvalid, $exception->error, $threshold);
                $this->assertSame(AccountingSettings::MAX_PICKER_SIZE, $exception->context['max']);
            }
        }

        $this->assertSame(200, $this->settings->pickerThreshold());
    }

    public function testUpdate_RejectsThePickerThresholdToSomeoneWithoutThePermissionToManageTheSettings(): void
    {
        $this->forgetPickerThreshold();

        try {
            $this->settings->update(['picker_threshold' => '10'], $this->employeeWithGrants([]));
            $this->fail('The change should be refused');
        } catch (AccountingException $exception) {
            $this->assertSame(AccountingError::PermissionDenied, $exception->error);
        }

        $this->assertSame(200, $this->settings->pickerThreshold());
    }

    public function testUpdate_RejectsADescriptionTemplateThatIsTooLongHasUnknownPlaceholdersOrMarkup(): void
    {
        $this->forgetChargeSettings();

        foreach ([str_repeat('a', 201), '{unknown} {number}', 'Sale {number', '<b>{number}</b>', "line\nbreak"] as $template) {
            try {
                $this->settings->update(['journal_description_sales' => $template], $this->manager());
                $this->fail('A template should be refused: ' . $template);
            } catch (AccountingException $exception) {
                $this->assertSame(AccountingError::JournalTemplateInvalid, $exception->error, $template);
            }
        }

        $this->assertSame('', $this->settings->journalDescriptionTemplate(JournalDescriptionTemplate::SALES));

        $this->settings->update(['journal_description_sales' => str_repeat('a', 200)], $this->manager());

        $this->assertSame(200, mb_strlen($this->settings->journalDescriptionTemplate(JournalDescriptionTemplate::SALES)));
    }

    public function testUpdate_RequiresThePermissionToChangeTheSettingsOfTheCharges(): void
    {
        $this->forgetChargeSettings();

        try {
            $this->settings->update(['sales_shipping' => true], $this->employeeWithGrants([]));
            $this->fail('The permission should be required.');
        } catch (AccountingException $exception) {
            $this->assertSame(AccountingError::PermissionDenied, $exception->error);
        }

        $this->assertFalse($this->settings->salesShippingEnabled());
    }

    public function testJournalDescriptionTemplate_GivesNothingForAStoredTemplateThatIsNotValid(): void
    {
        $this->forgetChargeSettings();
        $this->setConfigValue('accounting_journal_description_sales', '<script>{number}</script>');

        $this->assertSame('', $this->settings->journalDescriptionTemplate(JournalDescriptionTemplate::SALES));
    }
}
