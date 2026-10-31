<?php

namespace Tests\Libraries\Accounting;

use App\Libraries\Accounting\AccountingError;
use App\Libraries\Accounting\AccountingException;
use App\Libraries\Accounting\AccountingGuard;
use App\Libraries\Accounting\AccountService;
use App\Libraries\Accounting\EntityAccountService;
use App\Libraries\Accounting\JournalService;
use App\Libraries\Organization\BranchException;
use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\DatabaseTestTrait;
use Config\Database;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Support\AccountingFixtureTrait;

class AccountServiceTest extends CIUnitTestCase
{
    use DatabaseTestTrait;
    use AccountingFixtureTrait;

    protected $migrate     = true;
    protected $migrateOnce = true;
    protected $refresh     = false;
    protected $namespace   = null;

    private AccountService $service;

    public static function setUpBeforeClass(): void
    {
        $seeder = Database::seeder('tests');
        $seeder->call('TestDatabaseBootstrapSeeder');
    }

    protected function setUp(): void
    {
        parent::setUp();

        $this->reinstallChart();
        $this->setConfigValue('accounting_base_currency', 'SAR');
        $this->service = new AccountService();
    }

    protected function tearDown(): void
    {
        $this->releaseOpenTransactions();
        $this->removeInsertedEntities();

        parent::tearDown();
    }

    private function assertFailsWith(AccountingError $expected, callable $action): void
    {
        try {
            $action();
        } catch (AccountingException $exception) {
            $this->assertSame($expected, $exception->error);

            return;
        }

        $this->fail('Expected the failure ' . $expected->value . '.');
    }

    private function account(int $accountId): object
    {
        return $this->accountingDb()->table('accounting_accounts')->where('account_id', $accountId)->get()->getRow();
    }

    private function postUsing(int $accountId): void
    {
        (new JournalService())->postNew([
            'date'  => '2026-10-21',
            'lines' => [
                ['account_id' => $accountId, 'debit' => '50.00'],
                ['account_id' => $this->accountId('sales_revenue'), 'credit' => '50.00'],
            ],
        ]);
    }

    public function testCreate_AddsAnAccountUnderAGroupAndInheritsItsType(): void
    {
        $accountId = $this->service->create(['parent_id' => $this->accountIdByCode('11'), 'code' => '1110', 'name' => 'Petty cash']);

        $account = $this->account($accountId);
        $this->assertSame('asset', $account->account_type);
        $this->assertSame('debit', $account->normal_balance);
        $this->assertSame('0', (string) $account->is_group);
        $this->assertSame('0', (string) $account->is_system);
        $this->assertSame('1', (string) $account->active);
        $this->assertSame((string) $this->accountIdByCode('11'), (string) $account->parent_id);
    }

    public function testCreate_DerivesTheDirectionFromTheTypeOfTheParent(): void
    {
        $revenue = $this->service->create(['parent_id' => $this->accountIdByCode('41'), 'code' => '4110', 'name' => 'Service revenue']);
        $expense = $this->service->create(['parent_id' => $this->accountIdByCode('52'), 'code' => '5210', 'name' => 'Rent']);

        $this->assertSame('credit', $this->account($revenue)->normal_balance);
        $this->assertSame('debit', $this->account($expense)->normal_balance);
    }

    public function testCreate_FlipsTheDirectionForAContraAccount(): void
    {
        $accountId = $this->service->create(['parent_id' => $this->accountIdByCode('11'), 'code' => '1115', 'name' => 'Doubtful debts', 'contra' => '1']);

        $this->assertSame('credit', $this->account($accountId)->normal_balance);
    }

    public function testCreate_CanAddAGroupAccount(): void
    {
        $accountId = $this->service->create(['parent_id' => $this->accountIdByCode('11'), 'code' => '1120', 'name' => 'Receivables', 'is_group' => '1']);

        $this->assertSame('1', (string) $this->account($accountId)->is_group);
    }

    public function testCreate_RejectsAParentThatIsNotAGroup(): void
    {
        $this->assertFailsWith(AccountingError::ParentInvalid, fn () => $this->service->create([
            'parent_id' => $this->accountId('cash'),
            'code'      => '110101',
            'name'      => 'Drawer',
        ]));
    }

    public function testCreate_RejectsAMissingParent(): void
    {
        $this->assertFailsWith(AccountingError::ParentInvalid, fn () => $this->service->create(['parent_id' => 999999, 'code' => '9', 'name' => 'Orphan']));
        $this->assertFailsWith(AccountingError::ParentInvalid, fn () => $this->service->create(['code' => '9', 'name' => 'Orphan']));
    }

    public function testCreate_RejectsAnInactiveParent(): void
    {
        $parent = $this->customAccount('11', '1130', 'Group', true);
        $this->accountingDb()->table('accounting_accounts')->where('account_id', $parent)->update(['active' => 0]);

        $this->assertFailsWith(AccountingError::ParentInactive, fn () => $this->service->create(['parent_id' => $parent, 'code' => '113001', 'name' => 'Child']));
    }

    #[DataProvider('invalidCodeProvider')]
    public function testCreate_RejectsAnInvalidCode(string $code): void
    {
        $this->assertFailsWith(AccountingError::InvalidCode, fn () => $this->service->create([
            'parent_id' => $this->accountIdByCode('11'),
            'code'      => $code,
            'name'      => 'Bad code',
        ]));
    }

    public static function invalidCodeProvider(): iterable
    {
        yield 'empty' => [''];
        yield 'letters' => ['abc'];
        yield 'leading dash' => ['-11'];
        yield 'space' => ['11 10'];
        yield 'too long' => [str_repeat('1', 33)];
    }

    public function testCreate_RejectsACodeThatIsAlreadyUsed(): void
    {
        $this->assertFailsWith(AccountingError::CodeTaken, fn () => $this->service->create([
            'parent_id' => $this->accountIdByCode('11'),
            'code'      => '120101',
            'name'      => 'Another cash',
        ]));
    }

    public function testCreate_RejectsANameAlreadyUsedUnderTheSameParent(): void
    {
        $this->assertFailsWith(AccountingError::NameTaken, fn () => $this->service->create([
            'parent_id' => $this->accountIdByCode('1201'),
            'code'      => '1190',
            'name'      => 'الخزينة الأساسية',
        ]));
    }

    public function testCreate_AllowsTheSameNameUnderAnotherParent(): void
    {
        $accountId = $this->service->create(['parent_id' => $this->accountIdByCode('21'), 'code' => '2190', 'name' => 'الخزينة الأساسية']);

        $this->assertGreaterThan(0, $accountId);
    }

    public function testCreate_RejectsAnEmptyOrTooLongName(): void
    {
        $parent = $this->accountIdByCode('11');

        $this->assertFailsWith(AccountingError::InvalidName, fn () => $this->service->create(['parent_id' => $parent, 'code' => '1191', 'name' => '   ']));
        $this->assertFailsWith(AccountingError::InvalidName, fn () => $this->service->create(['parent_id' => $parent, 'code' => '1192', 'name' => str_repeat('a', 256)]));
    }

    public function testCreate_RequiresTheManagePermission(): void
    {
        $viewer  = $this->employeeWithGrants(['accounting_chart']);
        $manager = $this->employeeWithGrants(['accounting_chart', AccountingGuard::CHART_MANAGE]);
        $input   = ['parent_id' => $this->accountIdByCode('11'), 'code' => '1195', 'name' => 'Permitted'];

        $this->assertFailsWith(AccountingError::PermissionDenied, fn () => $this->service->create($input, $viewer));

        $this->assertGreaterThan(0, $this->service->create($input, $manager));
    }

    public function testCreate_WritesAnAuditEntry(): void
    {
        $manager   = $this->employeeWithGrants([AccountingGuard::CHART_MANAGE]);
        $accountId = $this->service->create(['parent_id' => $this->accountIdByCode('11'), 'code' => '1196', 'name' => 'Audited'], $manager);

        $audit = $this->accountingDb()->table('accounting_audit_log')->where('entity_type', 'account')->where('entity_id', $accountId)->get()->getRow();

        $this->assertSame('created', $audit->action);
        $this->assertSame((string) $manager, (string) $audit->employee_id);
        $this->assertSame('user', $audit->origin);
    }

    public function testUpdate_RenamesAnAccount(): void
    {
        $accountId = $this->customAccount();

        $this->service->update($accountId, ['name' => 'Renamed']);

        $this->assertSame('Renamed', $this->account($accountId)->name);
    }

    public function testUpdate_RenamesASystemAccount(): void
    {
        $this->service->update($this->accountId('cash'), ['name' => 'Main cash']);

        $this->assertSame('Main cash', $this->account($this->accountId('cash'))->name);
    }

    public function testUpdate_ChangesTheCodeOfAnUnusedAccount(): void
    {
        $accountId = $this->customAccount();

        $this->service->update($accountId, ['code' => '1199']);

        $this->assertSame('1199', $this->account($accountId)->code);
    }

    public function testUpdate_RejectsANewCodeForAnAccountWithJournalLines(): void
    {
        $accountId = $this->customAccount();
        $this->postUsing($accountId);

        $this->assertFailsWith(AccountingError::AccountInUse, fn () => $this->service->update($accountId, ['code' => '1199']));
    }

    public function testUpdate_RejectsANewCodeForASystemAccount(): void
    {
        $this->assertFailsWith(AccountingError::SystemAccountLocked, fn () => $this->service->update($this->accountId('cash'), ['code' => '1199']));
    }

    public function testUpdate_ChangesTheDirectionOnlyWhileTheAccountIsUnused(): void
    {
        $accountId = $this->customAccount();

        $this->service->update($accountId, ['contra' => '1']);
        $this->assertSame('credit', $this->account($accountId)->normal_balance);

        $this->postUsing($accountId);

        $this->assertFailsWith(AccountingError::AccountInUse, fn () => $this->service->update($accountId, ['contra' => '0']));
    }

    public function testUpdate_WritesNothingWhenNothingChanges(): void
    {
        $accountId = $this->customAccount();
        $before    = $this->countRows('accounting_audit_log');

        $this->service->update($accountId, ['name' => 'Custom account', 'code' => '1110']);

        $this->assertSame($before, $this->countRows('accounting_audit_log'));
    }

    public function testUpdate_RejectsANameUsedBySiblingAndAnUnknownAccount(): void
    {
        $accountId = $this->customAccount();

        $this->assertFailsWith(AccountingError::NameTaken, fn () => $this->service->update($accountId, ['name' => 'مجمع الإهلاك']));
        $this->assertFailsWith(AccountingError::AccountNotFound, fn () => $this->service->update(999999, ['name' => 'Ghost']));
    }

    public function testSubAccountOfAnEntity_CannotBeDeletedDeactivatedRecodedOrRenamed(): void
    {
        $accountId = (new EntityAccountService())->ensure(EntityAccountService::CUSTOMER, $this->insertCustomer(), 'Omar Khaled');

        $this->assertFailsWith(AccountingError::SystemAccountLocked, fn () => $this->service->delete($accountId));
        $this->assertFailsWith(AccountingError::SystemAccountLocked, fn () => $this->service->deactivate($accountId));
        $this->assertFailsWith(AccountingError::SystemAccountLocked, fn () => $this->service->update($accountId, ['code' => '12049999']));
        $this->assertFailsWith(AccountingError::SystemAccountLocked, fn () => $this->service->update($accountId, ['name' => 'Another name']));
        $this->assertSame('Omar Khaled', $this->service->find($accountId)->name);
    }

    public function testDeactivate_DisablesACustomAccount(): void
    {
        $accountId = $this->customAccount();

        $this->service->deactivate($accountId);

        $this->assertSame('0', (string) $this->account($accountId)->active);
    }

    public function testDeactivate_RejectsASystemAccount(): void
    {
        $this->assertFailsWith(AccountingError::SystemAccountLocked, fn () => $this->service->deactivate($this->accountId('cash')));
    }

    public function testDeactivate_RejectsARoutingTarget(): void
    {
        $accountId = $this->customAccount();
        $this->accountingDb()->table('accounting_routing')->where('routing_key', 'cash.default')->update(['account_id' => $accountId]);

        $this->assertFailsWith(AccountingError::AccountRouted, fn () => $this->service->deactivate($accountId));
    }

    public function testDeactivate_RejectsTheAccountOfAnExpenseCategory(): void
    {
        $accountId  = $this->customAccount('5', '5980', 'Marketing', false);
        $categoryId = $this->categoryOf($accountId);

        try {
            $this->assertFailsWith(AccountingError::AccountRouted, fn () => $this->service->deactivate($accountId));
            $this->assertSame('1', (string) $this->account($accountId)->active);
        } finally {
            $this->accountingDb()->table('expense_categories')->where('expense_category_id', $categoryId)->delete();
        }
    }

    public function testDelete_RejectsTheAccountOfAnExpenseCategory(): void
    {
        $accountId  = $this->customAccount('5', '5981', 'Marketing', false);
        $categoryId = $this->categoryOf($accountId);

        try {
            $this->assertFailsWith(AccountingError::AccountRouted, fn () => $this->service->delete($accountId));
            $this->assertNotNull($this->service->find($accountId));
        } finally {
            $this->accountingDb()->table('expense_categories')->where('expense_category_id', $categoryId)->delete();
        }
    }

    public function testDeactivate_RejectsTheAccountOfARevenueCategory(): void
    {
        $accountId = $this->customAccount('42', '4290', 'Consulting', false);
        $db        = $this->accountingDb();

        $db->table('revenue_categories')->insert(['category_name' => 'Consulting ' . uniqid(), 'category_description' => '', 'account_id' => $accountId]);

        try {
            $this->assertFailsWith(AccountingError::AccountRouted, fn () => $this->service->deactivate($accountId));
            $this->assertSame('1', (string) $this->account($accountId)->active);
        } finally {
            $db->table('revenue_categories')->emptyTable();
        }
    }

    public function testDelete_RejectsTheAccountOfARevenueCategory(): void
    {
        $accountId = $this->customAccount('42', '4290', 'Consulting', false);
        $db        = $this->accountingDb();

        $db->table('revenue_categories')->insert(['category_name' => 'Consulting ' . uniqid(), 'category_description' => '', 'account_id' => $accountId]);

        try {
            $this->assertFailsWith(AccountingError::AccountRouted, fn () => $this->service->delete($accountId));
            $this->assertNotNull($this->account($accountId));
        } finally {
            $db->table('revenue_categories')->emptyTable();
        }
    }

    private function categoryOf(int $accountId): int
    {
        $this->accountingDb()->table('expense_categories')->insert(['category_name' => 'Marketing ' . uniqid(), 'category_description' => '', 'account_id' => $accountId]);

        return (int) $this->accountingDb()->insertID();
    }

    public function testDeactivate_RejectsAGroupThatStillHasActiveChildren(): void
    {
        $group = $this->customAccount('11', '1140', 'Group', true);
        $this->service->create(['parent_id' => $group, 'code' => '114001', 'name' => 'Child']);

        $this->assertFailsWith(AccountingError::HasActiveChildren, fn () => $this->service->deactivate($group));
    }

    public function testDeactivate_RejectsAnAccountThatIsAlreadyInactive(): void
    {
        $accountId = $this->customAccount();
        $this->service->deactivate($accountId);

        $this->assertFailsWith(AccountingError::AlreadyInactive, fn () => $this->service->deactivate($accountId));
    }

    public function testActivate_EnablesAnInactiveAccount(): void
    {
        $accountId = $this->customAccount();
        $this->service->deactivate($accountId);

        $this->service->activate($accountId);

        $this->assertSame('1', (string) $this->account($accountId)->active);
    }

    public function testActivate_RejectsAnAccountWhoseParentIsInactive(): void
    {
        $group = $this->customAccount('11', '1140', 'Group', true);
        $child = $this->service->create(['parent_id' => $group, 'code' => '114001', 'name' => 'Child']);
        $this->service->deactivate($child);
        $this->service->deactivate($group);

        $this->assertFailsWith(AccountingError::ParentInactive, fn () => $this->service->activate($child));
    }

    public function testDelete_RemovesAnUnusedCustomAccount(): void
    {
        $accountId = $this->customAccount();

        $this->service->delete($accountId);

        $this->assertNull($this->service->find($accountId));
        $this->assertSame(1, $this->accountingDb()->table('accounting_audit_log')->where('entity_id', $accountId)->where('action', 'deleted')->countAllResults());
    }

    public function testDelete_RejectsASystemAccount(): void
    {
        $this->assertFailsWith(AccountingError::SystemAccountLocked, fn () => $this->service->delete($this->accountId('cash')));
    }

    public function testDelete_RejectsAnAccountWithChildren(): void
    {
        $group = $this->customAccount('11', '1140', 'Group', true);
        $this->service->create(['parent_id' => $group, 'code' => '114001', 'name' => 'Child']);

        $this->assertFailsWith(AccountingError::HasChildren, fn () => $this->service->delete($group));
    }

    public function testDelete_RejectsAnAccountWithJournalLines(): void
    {
        $accountId = $this->customAccount();
        $this->postUsing($accountId);

        $this->assertFailsWith(AccountingError::AccountInUse, fn () => $this->service->delete($accountId));
        $this->assertNotNull($this->service->find($accountId));
    }

    public function testDelete_RejectsARoutingTarget(): void
    {
        $accountId = $this->customAccount();
        $this->accountingDb()->table('accounting_routing')->where('routing_key', 'cash.default')->update(['account_id' => $accountId]);

        $this->assertFailsWith(AccountingError::AccountRouted, fn () => $this->service->delete($accountId));
    }

    public function testDelete_RequiresTheManagePermission(): void
    {
        $accountId = $this->customAccount();
        $viewer    = $this->employeeWithGrants(['accounting_chart']);

        $this->assertFailsWith(AccountingError::PermissionDenied, fn () => $this->service->delete($accountId, $viewer));
        $this->assertNotNull($this->service->find($accountId));
    }

    public function testBalances_RollUpPostedLinesThroughTheTree(): void
    {
        (new JournalService())->postNew($this->journalInput('250.00'));

        $balances = $this->service->balances();

        $this->assertSame('250.0000', $balances[$this->accountId('cash')]['balance']);
        $this->assertSame('250.0000', $balances[$this->accountIdByCode('1201')]['balance']);
        $this->assertSame('250.0000', $balances[$this->accountIdByCode('1')]['balance']);
        $this->assertSame('250.0000', $balances[$this->accountId('cash')]['debit']);
        $this->assertSame('0.0000', $balances[$this->accountIdByCode('2')]['balance']);
    }

    public function testBalances_SignTheBalanceToTheNormalDirection(): void
    {
        (new JournalService())->postNew($this->journalInput('250.00'));

        $balances = $this->service->balances();

        $this->assertSame('250.0000', $balances[$this->accountId('sales_revenue')]['balance']);
        $this->assertSame('250.0000', $balances[$this->accountIdByCode('4')]['balance']);
        $this->assertSame('250.0000', $balances[$this->accountId('sales_revenue')]['credit']);
    }

    public function testBalances_IgnoreDrafts(): void
    {
        (new JournalService())->saveDraft($this->journalInput('999.00'));

        $this->assertSame('0.0000', $this->service->balances()[$this->accountId('cash')]['balance']);
    }

    public function testBalances_NetToZeroAfterAReversal(): void
    {
        $journals  = new JournalService();
        $journalId = $journals->postNew($this->journalInput('120.00'));
        $journals->reverse($journalId, '2026-10-22', 'Entered twice');

        $balances = $this->service->balances();

        $this->assertSame('0.0000', $balances[$this->accountId('cash')]['balance']);
        $this->assertSame('120.0000', $balances[$this->accountId('cash')]['debit']);
        $this->assertSame('120.0000', $balances[$this->accountId('cash')]['credit']);
    }

    public function testSuggestCode_FollowsTheLastSiblingCode(): void
    {
        $this->assertSame('120102', $this->service->suggestCode($this->accountIdByCode('1201')));
    }

    public function testSuggestCode_StartsFromTheParentCodeWhenThereAreNoChildren(): void
    {
        $group = $this->customAccount('11', '1140', 'Group', true);

        $this->assertSame('114001', $this->service->suggestCode($group));
    }

    public function testChildren_ReturnsTheRootsWhenNoParentIsGiven(): void
    {
        $roots = $this->service->children(null);

        $this->assertSame(['1', '2', '3', '4', '5'], array_column($roots, 'code'));
    }

    public function testChildren_FlagsWhatTheScreenNeedsToOfferActions(): void
    {
        $this->postUsing($this->accountId('cash'));
        $this->customAccount('1201', '120102', 'Second treasury');

        $children = [];

        foreach ($this->service->children($this->accountIdByCode('1201')) as $child) {
            $children[$child->code] = $child;
        }

        $this->assertSame('1', (string) $children['120101']->used);
        $this->assertSame('1', (string) $children['120101']->routed);
        $this->assertSame('0', (string) $children['120102']->used);
        $this->assertSame('0', (string) $children['120102']->routed);
        $this->assertSame('0', (string) $children['120101']->has_children);
    }

    public function testSearch_FindsAnAccountByCodePrefixOrByName(): void
    {
        $byCode = $this->service->search('120101');
        $byName = $this->service->search('المخزون');

        $this->assertSame(['120101'], array_column($byCode, 'code'));
        $this->assertContains('1203', array_column($byName, 'code'));
        $this->assertSame([], $this->service->search('   '));
    }

    public function testSearch_TreatsQuotesAndWildcardsAsPlainText(): void
    {
        $this->customAccount('11', '1110', "Ali's 100% cash");

        $this->assertSame(['1110'], array_column($this->service->search("Ali's"), 'code'));
        $this->assertSame(['1110'], array_column($this->service->search('100%'), 'code'));
        $this->assertSame(['1110'], array_column($this->service->search('%'), 'code'));
        $this->assertSame([], $this->service->search("x' OR '1'='1"));
    }

    public function testFindFlagged_ReturnsTheListFlagsOfOneAccount(): void
    {
        $cashId = $this->accountId('cash');

        $flagged = $this->service->findFlagged($cashId);

        $this->assertSame('1', (string) $flagged->routed);
        $this->assertSame('0', (string) $flagged->used);
        $this->assertNull($this->service->findFlagged(999999));
    }

    public function testBalances_CountOnlyTheGivenBranches(): void
    {
        $second = $this->createBranch('B2');
        $posting = new JournalService();

        $posting->postNew($this->journalInput('100.00') + ['branch_id' => 1]);
        $posting->postNew($this->journalInput('40.00') + ['branch_id' => $second]);

        $cash = $this->accountId('cash');

        $this->assertSame('140.0000', $this->service->balances()[$cash]['balance']);
        $this->assertSame('100.0000', $this->service->balances([1])[$cash]['balance']);
        $this->assertSame('40.0000', $this->service->balances([$second])[$cash]['balance']);
        $this->assertSame('140.0000', $this->service->balances([1, $second])[$this->accountIdByCode('1')]['balance']);
    }

    public function testBalances_CountNothingForAnEmptyListOfBranches(): void
    {
        (new JournalService())->postNew($this->journalInput('100.00'));

        $this->assertSame('0.0000', $this->service->balances([])[$this->accountId('cash')]['balance']);
    }

    private function limitedChartManager(): int
    {
        return $this->employeeWithGrants([AccountingGuard::CHART_MANAGE], [1]);
    }

    public function testCreate_RejectsAnEmployeeLimitedToBranches(): void
    {
        $this->expectException(BranchException::class);

        $this->service->create(['parent_id' => $this->accountIdByCode('11'), 'code' => '1110', 'name' => 'Custom'], $this->limitedChartManager());
    }

    public function testUpdate_RejectsAnEmployeeLimitedToBranches(): void
    {
        $accountId = $this->customAccount();

        $this->expectException(BranchException::class);

        $this->service->update($accountId, ['name' => 'Renamed'], $this->limitedChartManager());
    }

    public function testDeactivate_RejectsAnEmployeeLimitedToBranches(): void
    {
        $accountId = $this->customAccount();

        $this->expectException(BranchException::class);

        $this->service->deactivate($accountId, $this->limitedChartManager());
    }

    public function testActivate_RejectsAnEmployeeLimitedToBranches(): void
    {
        $accountId = $this->customAccount();
        $this->service->deactivate($accountId);

        $this->expectException(BranchException::class);

        $this->service->activate($accountId, $this->limitedChartManager());
    }

    public function testDelete_RejectsAnEmployeeLimitedToBranches(): void
    {
        $accountId = $this->customAccount();

        $this->expectException(BranchException::class);

        $this->service->delete($accountId, $this->limitedChartManager());
        $this->assertNotNull($this->service->find($accountId));
    }
}
