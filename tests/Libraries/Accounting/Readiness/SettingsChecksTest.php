<?php

namespace Tests\Libraries\Accounting\Readiness;

use App\Libraries\Accounting\AccountingGuard;
use App\Libraries\Accounting\OpeningStock;
use App\Libraries\Accounting\Readiness\CheckResult;
use App\Libraries\Accounting\Readiness\CheckStatus;
use App\Libraries\Accounting\Readiness\SettingsChecks;
use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\DatabaseTestTrait;
use Config\Database;
use Tests\Support\AccountingFixtureTrait;
use Tests\Support\DatabaseFingerprintTrait;
use Tests\Support\EmployeeFixtureTrait;
use Tests\Support\ItemFixtureTrait;
use Tests\Support\SaleAccountingFixtureTrait;

/**
 * What the owner decides, as the readiness report lists it: who holds the sensitive permissions,
 * whether accounting is on and what it still lacks, the opening stock that waits and the opening
 * balances of each branch.
 */
class SettingsChecksTest extends CIUnitTestCase
{
    use DatabaseTestTrait;
    use AccountingFixtureTrait;
    use EmployeeFixtureTrait;
    use ItemFixtureTrait;
    use SaleAccountingFixtureTrait;
    use DatabaseFingerprintTrait;

    protected $migrate     = true;
    protected $migrateOnce = true;
    protected $refresh     = false;
    protected $namespace   = null;

    private SettingsChecks $checks;

    /** @var list<array<string, mixed>> */
    private array $shelfBackup = [];

    private ?array $removedPermission = null;

    public static function setUpBeforeClass(): void
    {
        $seeder = Database::seeder('tests');
        $seeder->call('TestDatabaseBootstrapSeeder');
    }

    protected function setUp(): void
    {
        parent::setUp();

        $this->accountingDb()->table('grants')->whereIn('permission_id', AccountingGuard::SENSITIVE)->delete();
        $this->shelfBackup = $this->accountingDb()->table('item_quantities')->get()->getResultArray();
        $this->accountingDb()->table('item_quantities')->emptyTable();

        $this->startSelling();
        $this->checks = new SettingsChecks($this->accountingDb());
    }

    protected function tearDown(): void
    {
        $db = $this->accountingDb();

        if ($this->removedPermission !== null) {
            $db->table('permissions')->insert($this->removedPermission);
            $this->removedPermission = null;
        }

        if (! $db->tableExists('ospos_accounting_routing', false) && $db->tableExists('ospos_accounting_routing_hold', false)) {
            $db->query('RENAME TABLE ospos_accounting_routing_hold TO ospos_accounting_routing');
        }

        $db->table('grants')->whereIn('permission_id', AccountingGuard::SENSITIVE)->delete();
        $db->table('accounting_opening_drafts')->emptyTable();
        $db->table('item_quantities')->emptyTable();

        if ($this->shelfBackup !== []) {
            $db->table('item_quantities')->insertBatch($this->shelfBackup);
        }

        $this->shelfBackup = [];
        $this->stopSelling();

        parent::tearDown();
    }

    private function check(string $id): CheckResult
    {
        foreach ($this->checks->run() as $result) {
            if ($result->id === $id) {
                return $result;
            }
        }

        $this->fail("No check {$id}.");
    }

    private function shelf(int $itemId, string $quantity, int $locationId = self::LOCATION_ID): void
    {
        $this->accountingDb()->table('item_quantities')->replace(['item_id' => $itemId, 'location_id' => $locationId, 'quantity' => $quantity]);
    }

    public function testRun_GivesTheFourChecksWithTheirReferences(): void
    {
        $results = $this->checks->run();

        $this->assertSame(['sensitive_grants', 'activation', 'opening_stock', 'opening_balances'], array_map(static fn (CheckResult $result): string => $result->id, $results));
        $this->assertSame(['OWNER-GRANTS', 'E26-ACTIVATION', 'E26-ACTIVATION', 'E26-ACTIVATION'], array_map(static fn (CheckResult $result): string => $result->ref, $results));
    }

    public function testSensitiveGrants_PassesWhenNobodyHoldsAnyAndSaysSoForEachPermission(): void
    {
        $result = $this->check('sensitive_grants');

        $this->assertSame(CheckStatus::Pass, $result->status);
        $this->assertCount(count(AccountingGuard::SENSITIVE), $result->findings);
        $this->assertSame('acc_periods_close: nobody', $result->findings[array_search(AccountingGuard::PERIODS_CLOSE, AccountingGuard::SENSITIVE, true)]);
        $this->assertSame([], $result->data['holders']);
    }

    public function testSensitiveGrants_ReviewsWhoHoldsEachPermissionAndWhereTheyMayWork(): void
    {
        $holder = $this->employeeWithGrants([AccountingGuard::PERIODS_CLOSE, AccountingGuard::INTERBRANCH_SETTLE]);

        $result = $this->check('sensitive_grants');
        $line   = $this->lineOf($result, AccountingGuard::PERIODS_CLOSE);

        $this->assertSame(CheckStatus::Review, $result->status);
        $this->assertStringStartsWith('acc_periods_close: Temp Employee (employee_', $line);
        $this->assertStringEndsWith('every branch', $line);
        $this->assertStringContainsString('every branch', $this->lineOf($result, AccountingGuard::INTERBRANCH_SETTLE));
        $this->assertSame('acc_routing_manage: nobody', $this->lineOf($result, AccountingGuard::ROUTING_MANAGE));
        $this->assertSame($holder, $result->data['holders'][0]['person_id']);
        $this->assertSame(1, count($result->data['holders']));
    }

    public function testSensitiveGrants_NamesTheBranchesOfAHolderWhoWorksInSome(): void
    {
        $second = $this->createBranch('B2');
        $this->employeeWithGrants([AccountingGuard::OPENING_MANAGE], [$second]);

        $line = $this->lineOf($this->check('sensitive_grants'), AccountingGuard::OPENING_MANAGE);

        $this->assertStringEndsWith('branches B2', $line);
    }

    public function testSensitiveGrants_SeparatesSeveralHoldersOfOnePermission(): void
    {
        $this->employeeWithGrants([AccountingGuard::SETTINGS_MANAGE]);
        $this->employeeWithGrants([AccountingGuard::SETTINGS_MANAGE]);

        $line = $this->lineOf($this->check('sensitive_grants'), AccountingGuard::SETTINGS_MANAGE);

        $this->assertSame(2, substr_count($line, '; ') + 1);
    }

    public function testSensitiveGrants_IgnoresAnEmployeeWhoWasDeleted(): void
    {
        $holder = $this->employeeWithGrants([AccountingGuard::PERIODS_REOPEN]);
        $this->accountingDb()->table('employees')->where('person_id', $holder)->update(['deleted' => 1]);

        $result = $this->check('sensitive_grants');

        $this->assertSame(CheckStatus::Pass, $result->status);
        $this->assertSame('acc_periods_reopen: nobody', $this->lineOf($result, AccountingGuard::PERIODS_REOPEN));
    }

    public function testSensitiveGrants_IgnoresAPermissionThatIsNotSensitive(): void
    {
        $this->employeeWithGrants([AccountingGuard::JOURNALS_POST]);

        $this->assertSame(CheckStatus::Pass, $this->check('sensitive_grants')->status);
    }

    public function testSensitiveGrants_SaysAPermissionIsNotRegisteredYet(): void
    {
        $db                      = $this->accountingDb();
        $this->removedPermission = $db->table('permissions')->where('permission_id', AccountingGuard::INTERBRANCH_SETTLE)->get()->getRowArray();
        $db->table('permissions')->where('permission_id', AccountingGuard::INTERBRANCH_SETTLE)->delete();

        $result = $this->check('sensitive_grants');

        $this->assertSame('acc_interbranch_settle: nobody [not registered yet]', $this->lineOf($result, AccountingGuard::INTERBRANCH_SETTLE));
        $this->assertSame('acc_periods_close: nobody', $this->lineOf($result, AccountingGuard::PERIODS_CLOSE));
        $this->assertNotContains(AccountingGuard::INTERBRANCH_SETTLE, $result->data['registered']);
    }

    private function lineOf(CheckResult $result, string $permissionId): string
    {
        foreach ($result->findings as $finding) {
            if (str_starts_with($finding, $permissionId . ':')) {
                return $finding;
            }
        }

        $this->fail("No line for {$permissionId}.");
    }

    public function testActivation_ReviewsDisabledAccountingAndListsWhatIsStillMissing(): void
    {
        $this->setConfigValue('accounting_enabled', '0');
        $this->setConfigValue('accounting_base_currency', '');

        $result = $this->check('activation');

        $this->assertSame(CheckStatus::Review, $result->status);
        $this->assertStringContainsString('Accounting is disabled, as it stays until the owner orders the activation.', $result->summary);
        $this->assertContains('base_currency_missing', $result->findings);
        $this->assertFalse($result->data['enabled']);
        $this->assertContains('base_currency_missing', $result->data['missing']);
    }

    public function testActivation_ReviewsDisabledAccountingWhoseSettingsAreComplete(): void
    {
        $this->setConfigValue('accounting_enabled', '0');

        $result = $this->check('activation');

        $this->assertSame(CheckStatus::Review, $result->status);
        $this->assertStringContainsString('its settings are complete: it stays disabled until the owner orders the activation', $result->summary);
        $this->assertSame([], $result->data['missing']);
    }

    public function testActivation_PassesEnabledAccountingWhoseSettingsAreComplete(): void
    {
        $result = $this->check('activation');

        $this->assertSame(CheckStatus::Pass, $result->status);
        $this->assertTrue($result->data['enabled']);
        $this->assertSame('SAR', $result->data['base_currency']);
        $this->assertSame('2020-01-01', $result->data['start_date']);
    }

    public function testActivation_FailsEnabledAccountingWhoseSettingsAreIncomplete(): void
    {
        $this->setConfigValue('accounting_base_currency', '');

        $result = $this->check('activation');

        $this->assertSame(CheckStatus::Fail, $result->status);
        $this->assertSame('Accounting is enabled while 1 of its settings are incomplete.', $result->summary);
        $this->assertSame(['base_currency_missing'], $result->findings);
    }

    public function testActivation_NamesTheSubjectOfAnIssue(): void
    {
        $this->setConfigValue('accounting_enabled', '0');
        $this->accountingDb()->table('accounting_fiscal_years')->where('company_id', 1)->delete();
        $this->setConfigValue('accounting_start_date', '2020-01-01');

        $result = $this->check('activation');

        $this->assertContains('no_fiscal_year', $result->findings);
    }

    public function testActivation_IsSkippedWithItsStateBeforeTheSchemaIsThere(): void
    {
        $this->accountingDb()->query('RENAME TABLE ospos_accounting_routing TO ospos_accounting_routing_hold');

        $result = $this->check('activation');

        $this->assertSame(CheckStatus::Skipped, $result->status);
        $this->assertTrue($result->data['enabled']);
        $this->assertStringContainsString('Accounting is enabled.', $result->summary);
    }

    public function testActivation_SaysDisabledWhenSkippedWhileAccountingIsOff(): void
    {
        $this->setConfigValue('accounting_enabled', '0');
        $this->accountingDb()->query('RENAME TABLE ospos_accounting_routing TO ospos_accounting_routing_hold');

        $result = $this->check('activation');

        $this->assertStringContainsString('Accounting is disabled.', $result->summary);
        $this->assertFalse($result->data['enabled']);
    }

    public function testOpeningStock_PassesWhenNoStockWaits(): void
    {
        $result = $this->check('opening_stock');

        $this->assertSame(CheckStatus::Pass, $result->status);
        $this->assertSame(0, $result->data['waiting']);
    }

    public function testOpeningStock_ReviewsAStockLocationWhoseShelvesHoldUnitsNoMovementAccountsFor(): void
    {
        $itemId = $this->item('10.00');
        $this->shelf($itemId, '5');

        $result = $this->check('opening_stock');

        $this->assertSame(CheckStatus::Review, $result->status);
        $this->assertFalse($result->status->blocks());
        $this->assertSame(1, $result->data['waiting']);
        $this->assertMatchesRegularExpression('/^branch HQ, .+ \(location 1\): postable, 5(\.0+)? unit\(s\) worth 50\.0000$/', $result->findings[0]);
    }

    public function testOpeningStock_ReviewsALocationWhoseItemIsNegativeAndNamesTheProblem(): void
    {
        $itemId = $this->item('10.00');
        $this->shelf($itemId, '-3');

        $result = $this->check('opening_stock');

        $this->assertSame(CheckStatus::Review, $result->status);
        $this->assertStringContainsString(': blocked,', $result->findings[0]);
        $this->assertStringEndsWith('negative_stock', $result->findings[0]);
    }

    public function testOpeningStock_PassesOnceTheOpeningStockIsPosted(): void
    {
        $itemId = $this->item('10.00');
        $this->shelf($itemId, '5');
        (new OpeningStock())->post([self::LOCATION_ID], null);

        $result = $this->check('opening_stock');

        $this->assertSame(CheckStatus::Pass, $result->status);
        $this->assertSame('already_posted', $result->data['locations'][0]['status']);
    }

    public function testOpeningStock_NamesTheBranchOfEachLocationThatWaits(): void
    {
        $second   = $this->createBranch('B2');
        $location = $this->insertStockLocation('Branch warehouse');
        $this->accountingDb()->table('stock_locations')->where('location_id', $location)->update(['branch_id' => $second]);
        $itemId = $this->item('4.00');
        $this->shelf($itemId, '2', $location);

        $result = $this->check('opening_stock');

        $this->assertSame(1, $result->data['waiting']);
        $this->assertStringStartsWith('branch B2, Branch warehouse', $result->findings[0]);
    }

    public function testOpeningBalances_PassesWithNothingEnteredAndSaysSoForEachBranch(): void
    {
        $result = $this->check('opening_balances');

        $this->assertSame(CheckStatus::Pass, $result->status);
        $this->assertSame(['branch HQ: nothing entered'], $result->findings);
        $this->assertSame([['branch' => 'HQ', 'state' => 'none']], $result->data['branches']);
    }

    public function testOpeningBalances_ReviewsABranchWhoseDraftWasNeverPosted(): void
    {
        $this->accountingDb()->table('accounting_opening_drafts')->insert(['branch_id' => 1, 'company_id' => 1, 'lines' => json_encode([['account_id' => $this->accountId('cash'), 'debit' => '10.00', 'credit' => '0'], ['account_id' => $this->accountId('opening_balance'), 'debit' => '0', 'credit' => '10.00']])]);

        $result = $this->check('opening_balances');

        $this->assertSame(CheckStatus::Review, $result->status);
        $this->assertSame(['branch HQ: a draft of 2 line(s) is saved and not posted'], $result->findings);
        $this->assertSame(1, $result->data['drafts']);
    }

    public function testOpeningBalances_PassesAndNamesTheJournalOfABranchThatPostedThem(): void
    {
        $db = $this->accountingDb();
        $db->table('accounting_journals')->insert(['company_id' => 1, 'branch_id' => 1, 'series' => 'manual', 'journal_date' => '2020-01-01', 'status' => 'posted', 'source_type' => 'opening', 'source_id' => 1, 'entry_type' => 'general', 'revision' => 1, 'origin' => 'user', 'currency_code' => 'SAR', 'total_debit' => '10.0000', 'total_credit' => '10.0000', 'posted_at' => date('Y-m-d H:i:s')]);
        $journalId = (int) $db->insertID();

        $result = $this->check('opening_balances');

        $this->assertSame(CheckStatus::Pass, $result->status);
        $this->assertSame(["branch HQ: posted as journal #{$journalId} dated 2020-01-01"], $result->findings);
    }

    public function testOpeningBalances_LeavesTheBranchesOfAnotherCompanyOut(): void
    {
        $db = $this->accountingDb();
        $db->table('companies')->insert(['name' => 'Another company']);
        $other = (int) $db->insertID();
        $db->table('branches')->insert(['company_id' => $other, 'code' => 'ZZ', 'name' => 'Foreign branch', 'active' => 1]);

        try {
            $result = $this->check('opening_balances');

            $this->assertSame(['branch HQ: nothing entered'], $result->findings);
            $this->assertSame([['branch' => 'HQ', 'state' => 'none']], $result->data['branches']);
        } finally {
            $db->table('branches')->where('company_id', $other)->delete();
            $db->table('companies')->where('company_id', $other)->delete();
        }
    }

    public function testRun_ChangesNoRow(): void
    {
        $this->employeeWithGrants([AccountingGuard::PERIODS_CLOSE]);
        $itemId = $this->item('10.00');
        $this->shelf($itemId, '5');
        $before = $this->databaseFingerprint();

        $this->checks->run();

        $this->assertDatabaseUnchanged($before);
    }
}
