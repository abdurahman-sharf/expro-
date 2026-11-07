<?php

namespace Tests\Libraries\Accounting;

use App\Libraries\Accounting\AccountingError;
use App\Libraries\Accounting\AccountingException;
use App\Libraries\Accounting\AccountingGuard;
use App\Libraries\Accounting\Currencies;
use App\Libraries\Accounting\InterBranchSettlements;
use App\Libraries\Accounting\JournalDraft;
use App\Libraries\Accounting\JournalLineDraft;
use App\Libraries\Accounting\PaymentType;
use App\Libraries\Accounting\PostingError;
use App\Libraries\Accounting\PostingException;
use App\Libraries\Accounting\PostingService;
use App\Libraries\Accounting\TreasuryScopes;
use App\Libraries\Accounting\TreasuryService;
use App\Libraries\Accounting\TreasuryTransfers;
use App\Libraries\Organization\BranchError;
use App\Libraries\Organization\BranchException;
use App\Libraries\Organization\BranchGuard;
use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\DatabaseTestTrait;
use Config\Database;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Support\PreviewJournalFixtureTrait;
use Tests\Support\AccountingFixtureTrait;
use Tests\Support\EmployeeFixtureTrait;
use Tests\Support\ItemFixtureTrait;
use Tests\Support\ParityCaseTrait;
use Tests\Support\ParityFixture;
use Tests\Support\PurchaseAccountingFixtureTrait;
use Tests\Support\SaleAccountingFixtureTrait;

/**
 * The treasuries and bank accounts: an entity with an account of its own under the main treasury
 * account, the main treasury that takes the place of the account every payment already went to,
 * the treasury an employee works from, and what each holds.
 */
class TreasuryServiceTest extends CIUnitTestCase
{
    use DatabaseTestTrait;
    use PreviewJournalFixtureTrait;
    use AccountingFixtureTrait;
    use EmployeeFixtureTrait;
    use ItemFixtureTrait;
    use ParityCaseTrait;
    use SaleAccountingFixtureTrait;
    use PurchaseAccountingFixtureTrait;

    protected $migrate     = true;
    protected $migrateOnce = true;
    protected $refresh     = false;
    protected $namespace   = null;

    private TreasuryService $treasuries;

    public static function setUpBeforeClass(): void
    {
        $seeder = Database::seeder('tests');
        $seeder->call('TestDatabaseBootstrapSeeder');
    }

    protected function setUp(): void
    {
        parent::setUp();

        $this->startBuying();
        $this->treasuries = new TreasuryService();
    }

    protected function tearDown(): void
    {
        $this->stopBuying();

        parent::tearDown();
    }

    private function inEveryBranch(int $personId): int
    {
        $this->accountingDb()->table('grants')->insert(['person_id' => $personId, 'permission_id' => BranchGuard::ALL_BRANCHES, 'menu_group' => 'home']);

        return $personId;
    }

    private function manager(?array $branchIds = null): int
    {
        return $this->employeeWithGrants([AccountingGuard::TREASURIES_MANAGE], $branchIds);
    }

    /**
     * Puts money in a treasury by moving it from another one that was given it by hand.
     */
    private function fund(int $treasuryId, string $amount = '100.00'): void
    {
        $main = $this->treasuries->ensureMain();

        (new PostingService())->post(new JournalDraft(
            date: '2026-10-21',
            lines: [new JournalLineDraft((int) $this->treasuries->accountOf($main), debit: $amount), new JournalLineDraft($this->accountId('opening_balance'), credit: $amount)],
            sourceType: 'opening',
            sourceId: random_int(1000, 9999),
            currencyCode: 'SAR',
        ));

        (new TreasuryTransfers())->transfer($main, $treasuryId, $amount, '2026-10-21');
    }

    private function refusal(callable $action): AccountingException
    {
        try {
            $action();
        } catch (AccountingException $exception) {
            return $exception;
        }

        $this->fail('The change was not refused.');
    }

    public function testEnsureMain_AdoptsTheAccountEveryPaymentWentTo(): void
    {
        $mainId = $this->treasuries->ensureMain();
        $main   = $this->treasuries->find($mainId);

        $this->assertSame(1, (int) $main->is_main);
        $this->assertSame(1, (int) $main->active);
        $this->assertSame($this->accountId('cash'), $this->treasuries->accountOf($mainId));
        $this->assertSame('120101', (string) $this->accountingDb()->table('accounting_accounts')->where('account_id', $this->accountId('cash'))->get()->getRow()->code);
    }

    public function testEnsureMain_IsTheSameTreasuryTheSecondTime(): void
    {
        $this->assertSame($this->treasuries->ensureMain(), $this->treasuries->ensureMain());
        $this->assertSame(1, $this->countRows('treasuries'));
    }

    public function testCreate_OpensAnAccountOfItsOwnUnderTheTreasuryAccount(): void
    {
        $id      = $this->treasuries->create(['name' => 'Branch drawer', 'kind' => 'cash', 'description' => 'Front desk'], $this->manager());
        $account = $this->accountingDb()->table('accounting_accounts')->where('account_id', $this->treasuries->accountOf($id))->get()->getRow();
        $parent  = $this->accountingDb()->table('accounting_accounts')->where('account_id', $account->parent_id)->get()->getRow();

        $this->assertSame('1201', (string) $parent->code);
        $this->assertSame('120102', (string) $account->code);
        $this->assertSame('Branch drawer', $account->name);
        $this->assertSame('treasury', $account->entity_type);
        $this->assertSame($id, (int) $account->entity_id);
        $this->assertSame('Front desk', $this->treasuries->find($id)->description);
    }

    public function testCreate_StartsTheMainTreasuryFirst(): void
    {
        $id = $this->treasuries->create(['name' => 'Second', 'kind' => 'cash'], $this->manager());

        $this->assertSame([1, 0], array_map(static fn (object $row): int => (int) $row->is_main, $this->treasuries->all()));
        $this->assertGreaterThan($this->treasuries->ensureMain(), $id);
    }

    public function testCreate_AcceptsABankAccountAsAKindOfTreasury(): void
    {
        $id = $this->treasuries->create(['name' => 'Gulf Bank', 'kind' => 'bank', 'bank_name' => 'Gulf Bank', 'account_number' => '1234567890'], $this->manager());

        $this->assertSame('bank', $this->treasuries->find($id)->kind);
        $this->assertNotNull($this->treasuries->accountOf($id));
    }

    public function testCreate_OpensTheAccountOfABankUnderTheMainAccountOfTheBanks(): void
    {
        $id      = $this->treasuries->create(['name' => 'Gulf Bank', 'kind' => 'bank', 'bank_name' => 'Gulf Bank', 'account_number' => '1234567890'], $this->manager());
        $account = $this->accountingDb()->table('accounting_accounts')->where('account_id', $this->treasuries->accountOf($id))->get()->getRow();
        $parent  = $this->accountingDb()->table('accounting_accounts')->where('account_id', $account->parent_id)->get()->getRow();

        $this->assertSame('1202', (string) $parent->code);
        $this->assertSame($this->accountId('banks'), (int) $parent->account_id);
        $this->assertMatchesRegularExpression('/^1202\d{3}$/', (string) $account->code);
        $this->assertSame('treasury', $account->entity_type);
        $this->assertSame($id, (int) $account->entity_id);
    }

    public function testCreate_KeepsTheNameOfTheBankAndTheNumberOfTheAccountInACanonicalForm(): void
    {
        $id       = $this->treasuries->create(['name' => 'Savings', 'kind' => 'bank', 'bank_name' => '  Gulf Bank ', 'account_number' => 'sa03 8000-0000 6080'], $this->manager());
        $treasury = $this->treasuries->find($id);

        $this->assertSame('Gulf Bank', $treasury->bank_name);
        $this->assertSame('SA03800000006080', $treasury->account_number);
    }

    public function testCreate_LeavesACashTreasuryWithoutBankDetails(): void
    {
        $id       = $this->treasuries->create(['name' => 'Drawer', 'kind' => 'cash', 'bank_name' => 'Gulf Bank', 'account_number' => '1234567890'], $this->manager());
        $treasury = $this->treasuries->find($id);

        $this->assertNull($treasury->bank_name);
        $this->assertNull($treasury->account_number);
        $this->assertSame('1201', (string) $this->accountingDb()->table('accounting_accounts')->where('account_id', $this->accountingDb()->table('accounting_accounts')->where('account_id', $this->treasuries->accountOf($id))->get()->getRow()->parent_id)->get()->getRow()->code);
    }

    /**
     * @return array<string, array{array<string, mixed>, AccountingError}>
     */
    public static function invalidBankDetails(): array
    {
        $bank = ['name' => 'Gulf Bank', 'kind' => 'bank'];

        return [
            'no bank name'                 => [$bank + ['bank_name' => '  ', 'account_number' => '1234567890'], AccountingError::TreasuryBankNameInvalid],
            'a bank name left out'         => [$bank + ['account_number' => '1234567890'], AccountingError::TreasuryBankNameInvalid],
            'a bank name too long'         => [$bank + ['bank_name' => str_repeat('b', 121), 'account_number' => '1234567890'], AccountingError::TreasuryBankNameInvalid],
            'a bank name that is not text' => [$bank + ['bank_name' => ['Gulf Bank'], 'account_number' => '1234567890'], AccountingError::TreasuryBankNameInvalid],
            'no account number'            => [$bank + ['bank_name' => 'Gulf Bank', 'account_number' => ' '], AccountingError::TreasuryAccountNumberInvalid],
            'an account number left out'   => [$bank + ['bank_name' => 'Gulf Bank'], AccountingError::TreasuryAccountNumberInvalid],
            'an account number too short'  => [$bank + ['bank_name' => 'Gulf Bank', 'account_number' => '123'], AccountingError::TreasuryAccountNumberInvalid],
            'an account number too long'   => [$bank + ['bank_name' => 'Gulf Bank', 'account_number' => str_repeat('1', 35)], AccountingError::TreasuryAccountNumberInvalid],
            'an account number with signs' => [$bank + ['bank_name' => 'Gulf Bank', 'account_number' => '1234/5678'], AccountingError::TreasuryAccountNumberInvalid],
        ];
    }

    /**
     * @param array<string, mixed> $input
     */
    #[DataProvider('invalidBankDetails')]
    public function testCreate_RequiresTheNameOfTheBankAndTheNumberOfTheAccountOfABank(array $input, AccountingError $error): void
    {
        $this->assertSame($error, $this->refusal(fn () => $this->treasuries->create($input, $this->manager()))->error);
        $this->assertSame(0, $this->countRows('treasuries'), 'Nothing was kept, not even the main treasury half way');
    }

    public function testCreate_RefusesTheSameAccountOfTheSameBankTwiceHoweverItIsWritten(): void
    {
        $manager = $this->manager();
        $this->treasuries->create(['name' => 'Gulf Bank', 'kind' => 'bank', 'bank_name' => 'Gulf Bank', 'account_number' => '1234 5678-90'], $manager);

        $refusal = $this->refusal(fn () => $this->treasuries->create(['name' => 'Gulf Bank 2', 'kind' => 'bank', 'bank_name' => 'Gulf Bank', 'account_number' => '1234567890'], $manager));

        $this->assertSame(AccountingError::TreasuryBankAccountTaken, $refusal->error);
        $this->assertSame(2, $this->countRows('treasuries'), 'Only the main treasury and the first bank account');
    }

    public function testCreate_AcceptsTheSameNumberAtAnotherBank(): void
    {
        $manager = $this->manager();
        $first   = $this->treasuries->create(['name' => 'Gulf Bank', 'kind' => 'bank', 'bank_name' => 'Gulf Bank', 'account_number' => '1234567890'], $manager);
        $second  = $this->treasuries->create(['name' => 'Delta Bank', 'kind' => 'bank', 'bank_name' => 'Delta Bank', 'account_number' => '1234567890'], $manager);

        $this->assertNotSame($first, $second);
    }

    public function testCreate_GivesEachBankAccountTheNextCodeUnderTheBanks(): void
    {
        $manager = $this->manager();
        $first   = $this->treasuries->create(['name' => 'Gulf Bank', 'kind' => 'bank', 'bank_name' => 'Gulf Bank', 'account_number' => '1234567890'], $manager);
        $second  = $this->treasuries->create(['name' => 'Delta Bank', 'kind' => 'bank', 'bank_name' => 'Delta Bank', 'account_number' => '9876543210'], $manager);
        $codes   = array_map(fn (int $id): string => (string) $this->accountingDb()->table('accounting_accounts')->where('account_id', $this->treasuries->accountOf($id))->get()->getRow()->code, [$first, $second]);

        $this->assertSame('1202001', $codes[0]);
        $this->assertSame('1202002', $codes[1]);
    }

    /**
     * @return array<string, array{array<string, mixed>}>
     */
    public static function referenceTreasuryAccounts(): array
    {
        return ParityFixture::cases('T-20', 'treasury_account');
    }

    /**
     * @param array<string, mixed> $case
     */
    #[DataProvider('referenceTreasuryAccounts')]
    public function testCreate_OpensTheAccountTheReferenceSystemOpens(array $case): void
    {
        $manager  = $this->manager();
        $journals = $this->countRows('accounting_journals');

        $id      = $this->treasuries->create(['name' => 'Fixture treasury', 'kind' => $case['inputs']['kind'], 'bank_name' => $case['inputs']['bank_name'] ?? '', 'account_number' => $case['inputs']['account_number'] ?? ''], $manager);
        $account = $this->accountingDb()->table('accounting_accounts')->where('account_id', $this->treasuries->accountOf($id))->get()->getRow();
        $parent  = $this->accountingDb()->table('accounting_accounts')->where('account_id', $account->parent_id)->get()->getRow();

        $this->assertSame($case['expected'], [
            'parent_code'          => (string) $parent->code,
            'code'                 => (string) $account->code,
            'journals_on_creation' => $this->countRows('accounting_journals') - $journals,
            'balance'              => bcadd($this->treasuries->position($id)->balance, '0', 2),
        ]);
        $this->assertSame($case['reference'], $case['expected'], 'A case that is exact has the same outcome as the reference system');
    }

    /**
     * @return array<string, array{array<string, mixed>}>
     */
    public static function referenceBankAccountsWithoutDetails(): array
    {
        return ParityFixture::cases('T-20', 'treasury_account_required');
    }

    /**
     * @param array<string, mixed> $case
     */
    #[DataProvider('referenceBankAccountsWithoutDetails')]
    public function testCreate_RefusesABankAccountTheReferenceSystemRefuses(array $case): void
    {
        $refusal = $this->refusal(fn () => $this->treasuries->create(['name' => 'Fixture bank', 'kind' => $case['inputs']['kind'], 'bank_name' => $case['inputs']['bank_name'], 'account_number' => $case['inputs']['account_number']], $this->manager()));

        $this->assertSame($case['expected']['refused'], $refusal instanceof AccountingException);
        $this->assertSame($case['expected']['records_kept'], $this->countRows('treasuries'));
    }

    /**
     * @return array<string, array{array<string, mixed>}>
     */
    public static function referenceDeactivations(): array
    {
        return ParityFixture::cases('T-23', 'treasury_deactivation');
    }

    /**
     * The reference system accepts the deactivation and leaves the treasury out of its transfer
     * lists, which strands what it holds. The owner chose to refuse it, so what is kept here is
     * the difference: refused with the balance still there and the treasury still on offer.
     *
     * @param array<string, mixed> $case
     */
    #[DataProvider('referenceDeactivations')]
    public function testDeactivate_DiffersFromTheReferenceSystemByRefusingATreasuryThatHoldsMoney(array $case): void
    {
        $manager = $this->manager();
        $id      = $this->treasuries->create(['name' => 'Fixture drawer', 'kind' => 'cash'], $manager);

        $this->fund($id, $case['inputs']['balance']);

        $refusal = $this->refusal(fn () => $this->treasuries->deactivate($id, $manager));

        $offered = array_map(static fn (object $treasury): int => (int) $treasury->treasury_id, $this->treasuries->all(null, false));

        $this->assertSame('differs', $case['classification']);
        $this->assertTrue($case['reference']['accepted']);
        $this->assertSame($case['expected']['accepted'], false);
        $this->assertSame($case['expected']['error'], $refusal->error->value);
        $this->assertSame($case['expected']['balance_after'], bcadd($this->treasuries->position($id)->balance, '0', 2));
        $this->assertSame($case['expected']['in_transfer_lists_after'], in_array($id, $offered, true));
    }

    public function testCreate_RefusesAnEmployeeWithoutThePermission(): void
    {
        $refusal = $this->refusal(fn () => $this->treasuries->create(['name' => 'Drawer', 'kind' => 'cash'], $this->employeeWithGrants([])));

        $this->assertSame(AccountingError::PermissionDenied, $refusal->error);
        $this->assertSame(0, $this->countRows('treasuries'));
    }

    /**
     * @return array<string, array{array<string, mixed>, AccountingError}>
     */
    public static function invalidInput(): array
    {
        return [
            'no name'         => [['name' => '  ', 'kind' => 'cash'], AccountingError::TreasuryInvalidName],
            'a name too long' => [['name' => str_repeat('x', 300), 'kind' => 'cash'], AccountingError::TreasuryInvalidName],
            'an unknown kind' => [['name' => 'Drawer', 'kind' => 'vault'], AccountingError::TreasuryInvalidKind],
        ];
    }

    /**
     * @param array<string, mixed> $input
     */
    #[DataProvider('invalidInput')]
    public function testCreate_RefusesWhatIsNotATreasury(array $input, AccountingError $error): void
    {
        $this->assertSame($error, $this->refusal(fn () => $this->treasuries->create($input, $this->manager()))->error);
        $this->assertSame(0, $this->countRows('treasuries'));
    }

    public function testCreate_RefusesAnotherTreasuryWithTheSameName(): void
    {
        $manager = $this->manager();
        $this->treasuries->create(['name' => 'Drawer', 'kind' => 'cash'], $manager);

        $refusal = $this->refusal(fn () => $this->treasuries->create(['name' => 'Drawer', 'kind' => 'bank', 'bank_name' => 'Gulf Bank', 'account_number' => '1234567890'], $manager));

        $this->assertSame(AccountingError::TreasuryNameTaken, $refusal->error);
        $this->assertSame('Drawer', $refusal->context['name']);
    }

    public function testCreate_RefusesABranchOutsideTheScopeOfTheEmployee(): void
    {
        $other  = $this->createBranch('B2');
        $worker = $this->manager([1]);

        $this->expectException(BranchException::class);

        $this->treasuries->create(['name' => 'Drawer', 'kind' => 'cash', 'branch_id' => $other], $worker);
    }

    public function testUpdate_RenamesTheAccountToo(): void
    {
        $manager = $this->manager();
        $id      = $this->treasuries->create(['name' => 'Drawer', 'kind' => 'cash'], $manager);

        $this->treasuries->update($id, ['name' => 'Front drawer', 'kind' => 'bank', 'description' => 'Moved', 'bank_name' => 'Gulf Bank', 'account_number' => '1234567890'], $manager);

        $this->assertSame('Front drawer', $this->treasuries->find($id)->name);
        $this->assertSame('bank', $this->treasuries->find($id)->kind);
        $this->assertSame('Front drawer', $this->accountingDb()->table('accounting_accounts')->where('account_id', $this->treasuries->accountOf($id))->get()->getRow()->name);
    }

    public function testUpdate_TakesTheAccountToTheBanksWhenACashTreasuryBecomesABankAccountKeepingWhatItHolds(): void
    {
        $manager   = $this->manager();
        $id        = $this->treasuries->create(['name' => 'Drawer', 'kind' => 'cash'], $manager);
        $accountId = $this->treasuries->accountOf($id);

        $this->fund($id, '80.00');

        $linesBefore = $this->countRows('accounting_journal_lines');

        $this->treasuries->update($id, ['name' => 'Drawer', 'kind' => 'bank', 'bank_name' => 'Gulf Bank', 'account_number' => '1234567890'], $manager);

        $account = $this->accountingDb()->table('accounting_accounts')->where('account_id', $accountId)->get()->getRow();

        $this->assertSame($accountId, $this->treasuries->accountOf($id), 'The account is the same one, so its entries stay with it');
        $this->assertSame($this->accountId('banks'), (int) $account->parent_id);
        $this->assertMatchesRegularExpression('/^1202\d{3}$/', (string) $account->code);
        $this->assertSame('80.0000', $this->treasuries->position($id)->balance);
        $this->assertSame($linesBefore, $this->countRows('accounting_journal_lines'));
    }

    public function testUpdate_TakesTheAccountBackToTheTreasuriesWhenABankAccountBecomesACashTreasury(): void
    {
        $manager = $this->manager();
        $id      = $this->treasuries->create(['name' => 'Gulf Bank', 'kind' => 'bank', 'bank_name' => 'Gulf Bank', 'account_number' => '1234567890'], $manager);

        $this->fund($id, '45.00');
        $this->treasuries->update($id, ['name' => 'Gulf Bank', 'kind' => 'cash'], $manager);

        $account  = $this->accountingDb()->table('accounting_accounts')->where('account_id', $this->treasuries->accountOf($id))->get()->getRow();
        $treasury = $this->treasuries->find($id);

        $this->assertSame($this->accountId('treasuries'), (int) $account->parent_id);
        $this->assertMatchesRegularExpression('/^1201\d{2}$/', (string) $account->code);
        $this->assertNull($treasury->bank_name);
        $this->assertNull($treasury->account_number);
        $this->assertSame('45.0000', $this->treasuries->position($id)->balance);
    }

    public function testUpdate_RequiresTheDetailsOfABankThatWasOpenedBeforeTheyWereAsked(): void
    {
        $manager = $this->manager();
        $id      = $this->treasuries->create(['name' => 'Old bank', 'kind' => 'bank', 'bank_name' => 'Gulf Bank', 'account_number' => '1234567890'], $manager);

        $this->accountingDb()->table('treasuries')->where('treasury_id', $id)->update(['bank_name' => null, 'account_number' => null]);

        $this->assertTrue(TreasuryService::lacksBankDetails($this->treasuries->find($id)));
        $this->assertSame(AccountingError::TreasuryBankNameInvalid, $this->refusal(fn () => $this->treasuries->update($id, ['name' => 'Old bank', 'kind' => 'bank'], $manager))->error);

        $this->treasuries->update($id, ['name' => 'Old bank', 'kind' => 'bank', 'bank_name' => 'Gulf Bank', 'account_number' => '1234567890'], $manager);

        $this->assertFalse(TreasuryService::lacksBankDetails($this->treasuries->find($id)));
    }

    public function testUpdate_RefusesTheAccountOfAnotherBankAccountButAcceptsItsOwn(): void
    {
        $manager = $this->manager();
        $first   = $this->treasuries->create(['name' => 'Gulf Bank', 'kind' => 'bank', 'bank_name' => 'Gulf Bank', 'account_number' => '1234567890'], $manager);
        $second  = $this->treasuries->create(['name' => 'Delta Bank', 'kind' => 'bank', 'bank_name' => 'Delta Bank', 'account_number' => '9876543210'], $manager);

        $this->treasuries->update($first, ['name' => 'Gulf Bank renamed', 'kind' => 'bank', 'bank_name' => 'Gulf Bank', 'account_number' => '1234567890'], $manager);

        $refusal = $this->refusal(fn () => $this->treasuries->update($second, ['name' => 'Delta Bank', 'kind' => 'bank', 'bank_name' => 'Gulf Bank', 'account_number' => '1234-567890'], $manager));

        $this->assertSame(AccountingError::TreasuryBankAccountTaken, $refusal->error);
        $this->assertSame('Delta Bank', $this->treasuries->find($second)->bank_name);
    }

    public function testUpdate_RefusesToMakeTheMainTreasuryABankAccount(): void
    {
        $manager = $this->manager();
        $main    = $this->treasuries->ensureMain();

        $this->assertSame(AccountingError::TreasuryIsMain, $this->refusal(fn () => $this->treasuries->update($main, ['name' => 'Main', 'kind' => 'bank', 'bank_name' => 'Gulf Bank', 'account_number' => '1234567890'], $manager))->error);
    }

    public function testDeactivate_RefusesWhileItHoldsMoney(): void
    {
        $manager = $this->manager();
        $id      = $this->treasuries->create(['name' => 'Drawer', 'kind' => 'cash'], $manager);

        $this->fund($id, '75.50');

        $refusal = $this->refusal(fn () => $this->treasuries->deactivate($id, $manager));

        $this->assertSame(AccountingError::TreasuryHasBalance, $refusal->error);
        $this->assertSame(1, (int) $this->treasuries->find($id)->active);
    }

    public function testDeactivate_TakesTheTreasuryOffTheDefaultOfTheEmployeesWhoWorkedFromIt(): void
    {
        $manager = $this->manager();
        $id      = $this->treasuries->create(['name' => 'Drawer', 'kind' => 'cash'], $manager);

        $this->treasuries->setDefaultFor($this->inEveryBranch($this->employeeId), $id, $manager);
        $this->treasuries->deactivate($id, $manager);

        $this->assertSame(0, (int) $this->treasuries->find($id)->active);
        $this->assertNull($this->treasuries->defaultFor($this->employeeId));
    }

    public function testDeactivate_RefusesTheMainTreasuryAndOneThatIsAlreadyDeactivated(): void
    {
        $manager = $this->manager();
        $main    = $this->treasuries->ensureMain();
        $id      = $this->treasuries->create(['name' => 'Drawer', 'kind' => 'cash'], $manager);

        $this->treasuries->deactivate($id, $manager);

        $this->assertSame(AccountingError::TreasuryIsMain, $this->refusal(fn () => $this->treasuries->deactivate($main, $manager))->error);
        $this->assertSame(AccountingError::AlreadyInactive, $this->refusal(fn () => $this->treasuries->deactivate($id, $manager))->error);
    }

    public function testActivate_BringsATreasuryBackAndRefusesOneThatIsActive(): void
    {
        $manager = $this->manager();
        $id      = $this->treasuries->create(['name' => 'Drawer', 'kind' => 'cash'], $manager);

        $this->assertSame(AccountingError::AlreadyActive, $this->refusal(fn () => $this->treasuries->activate($id, $manager))->error);

        $this->treasuries->deactivate($id, $manager);
        $this->treasuries->activate($id, $manager);

        $this->assertSame(1, (int) $this->treasuries->find($id)->active);
    }

    public function testDelete_RemovesATreasuryNothingHappenedInAndRetiresItsAccount(): void
    {
        $manager = $this->manager();
        $id      = $this->treasuries->create(['name' => 'Drawer', 'kind' => 'cash'], $manager);
        $account = (int) $this->treasuries->accountOf($id);

        $this->treasuries->delete($id, $manager);

        $this->assertNull($this->treasuries->find($id));
        $this->assertSame(0, (int) $this->accountingDb()->table('accounting_accounts')->where('account_id', $account)->get()->getRow()->active);
    }

    public function testDelete_RefusesATreasuryThatWasUsed(): void
    {
        $manager = $this->manager();
        $id      = $this->treasuries->create(['name' => 'Drawer', 'kind' => 'cash'], $manager);

        $this->fund($id);

        $this->assertSame(AccountingError::TreasuryInUse, $this->refusal(fn () => $this->treasuries->delete($id, $manager))->error);
        $this->assertNotNull($this->treasuries->find($id));
    }

    public function testDelete_RejectsATreasuryASettlementBetweenBranchesMovedMoneyThrough(): void
    {
        $branchId = $this->createBranch('B2');
        $manager  = $this->manager();
        $main     = $this->treasuries->ensureMain();
        $drawer   = $this->treasuries->create(['name' => 'Second branch drawer', 'kind' => 'cash', 'branch_id' => $branchId], $manager);

        $this->fund($drawer);

        $settler = $this->employeeWithGrants([AccountingGuard::INTERBRANCH_SETTLE]);

        (new InterBranchSettlements())->settle(1, $branchId, '2026-10-31', $drawer, $main, '', null, $settler);

        $this->assertSame(AccountingError::TreasuryInUse, $this->refusal(fn () => $this->treasuries->delete($drawer, $manager))->error);
        $this->assertNotNull($this->treasuries->find($drawer));
    }

    public function testDelete_RefusesATreasuryAnExpenseOrACashUpNamed(): void
    {
        $manager = $this->manager();
        $db      = $this->accountingDb();

        $db->table('expense_categories')->insert(['category_name' => 'Used ' . uniqid(), 'category_description' => '']);
        $categoryId = (int) $db->insertID();

        $expenseDrawer = $this->treasuries->create(['name' => 'Expense drawer', 'kind' => 'cash'], $manager);
        $cashupDrawer  = $this->treasuries->create(['name' => 'Cash-up drawer', 'kind' => 'cash'], $manager);

        $db->table('expenses')->insert(['date' => '2026-10-20 10:00:00', 'amount' => '0.00', 'payment_type' => 'Cash', 'expense_category_id' => $categoryId, 'description' => 'Named a treasury', 'employee_id' => $manager, 'treasury_id' => $expenseDrawer, 'branch_id' => 1]);
        $db->table('cash_up')->insert([
            'open_date' => '2026-10-20 08:00:00', 'close_date' => '2026-10-20 18:00:00', 'open_amount_cash' => '0', 'transfer_amount_cash' => '0', 'closed_amount_cash' => '0', 'closed_amount_card' => '0',
            'closed_amount_check' => '0', 'closed_amount_total' => '0', 'description' => 'Named a treasury', 'open_employee_id' => $manager, 'close_employee_id' => $manager, 'closed_amount_due' => '0', 'treasury_id' => $cashupDrawer, 'branch_id' => 1,
        ]);

        try {
            $this->assertSame(AccountingError::TreasuryInUse, $this->refusal(fn () => $this->treasuries->delete($expenseDrawer, $manager))->error);
            $this->assertSame(AccountingError::TreasuryInUse, $this->refusal(fn () => $this->treasuries->delete($cashupDrawer, $manager))->error);
        } finally {
            $db->query('SET FOREIGN_KEY_CHECKS = 0');
            $db->table('expenses')->truncate();
            $db->table('cash_up')->truncate();
            $db->query('SET FOREIGN_KEY_CHECKS = 1');
            $db->table('expense_categories')->where('expense_category_id', $categoryId)->delete();
        }
    }

    public function testDelete_RejectsABankAccountThatHasAnImportedStatementOrAClosingAndNoJournalLine(): void
    {
        $manager   = $this->manager();
        $db        = $this->accountingDb();
        $imported  = $this->treasuries->create(['name' => 'Statement bank', 'kind' => 'bank', 'bank_name' => 'Gulf Bank', 'account_number' => '5500000021'], $manager);
        $reopened  = $this->treasuries->create(['name' => 'Reopened bank', 'kind' => 'bank', 'bank_name' => 'Gulf Bank', 'account_number' => '5500000022'], $manager);
        $unused    = $this->treasuries->create(['name' => 'Unused bank', 'kind' => 'bank', 'bank_name' => 'Gulf Bank', 'account_number' => '5500000023'], $manager);

        $db->table('bank_statements')->insert(['company_id' => 1, 'branch_id' => 1, 'treasury_id' => $imported, 'checksum' => str_repeat('a', 64), 'delimiter_name' => 'comma', 'row_count' => 1, 'line_count' => 1, 'date_from' => '2026-10-20', 'date_to' => '2026-10-20']);
        $db->table('bank_reconciliations')->insert(['company_id' => 1, 'branch_id' => 1, 'treasury_id' => $reopened, 'closed_through' => '2026-10-20', 'statement_balance' => '0', 'book_balance' => '0', 'status' => 'reopened']);

        try {
            foreach ([$imported, $reopened] as $treasury) {
                $this->assertSame(AccountingError::TreasuryInUse, $this->refusal(fn () => $this->treasuries->delete($treasury, $manager))->error, 'treasury ' . $treasury);
                $this->assertNotNull($this->treasuries->find($treasury));
            }

            $this->treasuries->delete($unused, $manager);

            $this->assertNull($this->treasuries->find($unused));
        } finally {
            $db->table('bank_reconciliations')->emptyTable();
            $db->table('bank_statements')->emptyTable();
        }
    }

    public function testDelete_RejectsATreasuryAChequeABookOfChequesOrAReceiptVoucherNamed(): void
    {
        $manager = $this->manager();
        $db      = $this->accountingDb();

        $collected = $this->treasuries->create(['name' => 'Cheque bank', 'kind' => 'bank', 'bank_name' => 'Gulf Bank', 'account_number' => '5500000011'], $manager);
        $booked    = $this->treasuries->create(['name' => 'Book bank', 'kind' => 'bank', 'bank_name' => 'Gulf Bank', 'account_number' => '5500000012'], $manager);
        $voucher   = $this->treasuries->create(['name' => 'Voucher drawer', 'kind' => 'cash'], $manager);
        $customer  = $this->insertCustomer();

        $db->table('cheque_books')->insert(['company_id' => 1, 'branch_id' => 1, 'treasury_id' => $booked, 'name' => 'Named a treasury', 'first_serial' => 1, 'last_serial' => 9]);
        $db->table('cheques')->insert(['company_id' => 1, 'branch_id' => 1, 'direction' => 'received', 'status' => 'received', 'party_id' => $customer, 'treasury_id' => $collected, 'cheque_number' => '1', 'amount' => '1.00', 'issue_date' => '2026-10-20', 'due_date' => '2026-10-20']);
        $db->table('revenues')->insert(['company_id' => 1, 'branch_id' => 1, 'date' => '2026-10-20', 'amount' => '1.00', 'payment_type' => 'Cash', 'treasury_id' => $voucher]);

        try {
            foreach ([$collected, $booked, $voucher] as $treasury) {
                $this->assertSame(AccountingError::TreasuryInUse, $this->refusal(fn () => $this->treasuries->delete($treasury, $manager))->error, 'treasury ' . $treasury);
            }
        } finally {
            $db->table('revenues')->emptyTable();
            $db->table('cheques')->emptyTable();
            $db->table('cheque_books')->emptyTable();
        }
    }

    public function testDelete_RefusesTheMainTreasury(): void
    {
        $main = $this->treasuries->ensureMain();

        $this->assertSame(AccountingError::TreasuryIsMain, $this->refusal(fn () => $this->treasuries->delete($main, $this->manager()))->error);
    }

    public function testStampFor_GivesTheTreasuryTheEmployeeWorksFromWhenItIsActiveAndOfTheBranch(): void
    {
        $manager = $this->manager();
        $id      = $this->treasuries->create(['name' => 'Drawer', 'kind' => 'cash'], $manager);

        $this->assertNull($this->treasuries->stampFor($this->employeeId, 1), 'Until one is chosen, payments go where they always went');

        $this->treasuries->setDefaultFor($this->inEveryBranch($this->employeeId), $id, $manager);

        $this->assertSame($id, $this->treasuries->stampFor($this->employeeId, 1));
        $this->assertNull($this->treasuries->stampFor($this->employeeId, $this->createBranch('B2')), 'A payment of another branch does not go into this treasury');
        $this->assertNull($this->treasuries->stampFor(null, 1));
    }

    public function testStampFor_SkipsTheTreasuryTheEmployeeMayNotUseInTheDirectionAskedFor(): void
    {
        $manager = $this->manager();
        $seller  = $this->employeeWithGrants([]);
        $other   = $this->employeeWithGrants([]);
        $drawer  = $this->treasuries->create(['name' => 'Seller drawer', 'kind' => 'cash', 'deposit_scope' => 'staff', 'deposit_members' => [$other]], $manager);

        $this->treasuries->setDefaultFor($seller, $drawer, $manager);

        $this->assertSame($drawer, $this->treasuries->stampFor($seller, 1), 'Asked for no direction it is the treasury the employee works from');
        $this->assertNull($this->treasuries->stampFor($seller, 1, TreasuryScopes::DEPOSIT));
        $this->assertSame($drawer, $this->treasuries->stampFor($seller, 1, TreasuryScopes::WITHDRAW));
    }

    public function testOfPayment_RefusesATreasuryItNamesThatTheEmployeeMayNotUse(): void
    {
        $manager = $this->manager();
        $seller  = $this->employeeWithGrants([]);
        $drawer  = $this->treasuries->create(['name' => 'Locked drawer', 'kind' => 'cash', 'deposit_scope' => 'staff', 'deposit_members' => [$manager]], $manager);

        $refusal = $this->refusal(fn () => $this->treasuries->ofPayment($drawer, PaymentType::Cash, TreasuryScopes::DEPOSIT, $seller, 1));

        $this->assertSame(AccountingError::TreasuryDepositDenied, $refusal->error);
        $this->assertSame($drawer, $this->treasuries->ofPayment($drawer, PaymentType::Cash, TreasuryScopes::DEPOSIT, $manager, 1));
        $this->assertSame($drawer, $this->treasuries->ofPayment($drawer, PaymentType::Cash, TreasuryScopes::WITHDRAW, $seller, 1), 'Withdrawals were not limited');
    }

    public function testOfPayment_StillRefusesATreasuryOfAnotherBranchBeforeAskingTheScope(): void
    {
        $manager = $this->manager();
        $other   = $this->createBranch('B2');
        $drawer  = $this->treasuries->create(['name' => 'Elsewhere', 'kind' => 'cash', 'branch_id' => $other], $manager);

        $this->assertSame(AccountingError::TreasuryNotFound, $this->refusal(fn () => $this->treasuries->ofPayment($drawer, PaymentType::Cash, TreasuryScopes::DEPOSIT, $manager, 1))->error);
    }

    public function testOfPayment_FallsBackToTheMainTreasuryWhenTheDefaultOfTheEmployeeIsNotOpenToThem(): void
    {
        $manager = $this->manager();
        $seller  = $this->employeeWithGrants([]);
        $drawer  = $this->treasuries->create(['name' => 'Seller drawer', 'kind' => 'cash', 'deposit_scope' => 'staff', 'deposit_members' => [$manager]], $manager);

        $this->treasuries->setDefaultFor($seller, $drawer, $manager);

        $this->assertNull($this->treasuries->ofPayment(null, PaymentType::Cash, TreasuryScopes::DEPOSIT, $seller, 1), 'It goes where its way of payment goes, the main treasury');
    }

    public function testOfPayment_RefusesWhenTheMainTreasuryItFallsBackToIsClosedToTheEmployeeToo(): void
    {
        $manager = $this->manager();
        $seller  = $this->employeeWithGrants([]);
        $main    = $this->treasuries->ensureMain();

        $this->treasuries->update($main, ['name' => 'Main', 'kind' => 'cash', 'deposit_scope' => 'staff', 'deposit_members' => [$manager]], $manager);

        $this->assertSame(AccountingError::TreasuryDepositDenied, $this->refusal(fn () => $this->treasuries->ofPayment(null, PaymentType::Cash, TreasuryScopes::DEPOSIT, $seller, 1))->error);
        $this->assertNull($this->treasuries->ofPayment(null, PaymentType::Cash, TreasuryScopes::DEPOSIT, $manager, 1));
    }

    public function testOfPayment_AsksNothingOfTheBooks(): void
    {
        $manager = $this->manager();
        $drawer  = $this->treasuries->create(['name' => 'Locked drawer', 'kind' => 'cash', 'deposit_scope' => 'staff', 'deposit_members' => [$manager]], $manager);

        $this->assertSame($drawer, $this->treasuries->ofPayment($drawer, PaymentType::Cash, TreasuryScopes::DEPOSIT, null, 1));
    }

    public function testAssertMayChangePayment_AsksNothingOfAChangeThatMovesNoMoneyAndNoTreasury(): void
    {
        $manager = $this->manager();
        $seller  = $this->employeeWithGrants([]);
        $drawer  = $this->treasuries->create(['name' => 'Locked drawer', 'kind' => 'cash', 'deposit_scope' => 'staff', 'deposit_members' => [$manager], 'withdraw_scope' => 'staff', 'withdraw_members' => [$manager]], $manager);

        $this->treasuries->assertMayChangePayment($drawer, $drawer, false, PaymentType::Cash, TreasuryScopes::DEPOSIT, $seller);
        $this->addToAssertionCount(1);
    }

    public function testAssertMayChangePayment_AsksTheScopeOfTheTreasuryWhenTheAmountChanges(): void
    {
        $manager = $this->manager();
        $seller  = $this->employeeWithGrants([]);
        $drawer  = $this->treasuries->create(['name' => 'Locked drawer', 'kind' => 'cash', 'deposit_scope' => 'staff', 'deposit_members' => [$manager]], $manager);

        $this->assertSame(AccountingError::TreasuryDepositDenied, $this->refusal(fn () => $this->treasuries->assertMayChangePayment($drawer, $drawer, true, PaymentType::Cash, TreasuryScopes::DEPOSIT, $seller))->error);

        $this->treasuries->assertMayChangePayment($drawer, $drawer, true, PaymentType::Cash, TreasuryScopes::DEPOSIT, $manager);
        $this->addToAssertionCount(1);
    }

    public function testAssertMayChangePayment_NeedsTheOppositeScopeOfTheTreasuryThePaymentLeaves(): void
    {
        $manager = $this->manager();
        $mover   = $this->employeeWithGrants([]);
        $from    = $this->treasuries->create(['name' => 'From drawer', 'kind' => 'cash', 'withdraw_scope' => 'staff', 'withdraw_members' => [$manager]], $manager);
        $to      = $this->treasuries->create(['name' => 'To drawer', 'kind' => 'cash'], $manager);

        $refusal = $this->refusal(fn () => $this->treasuries->assertMayChangePayment($to, $from, false, PaymentType::Cash, TreasuryScopes::DEPOSIT, $mover));

        $this->assertSame(AccountingError::TreasuryWithdrawDenied, $refusal->error);
        $this->assertSame('From drawer', $refusal->context['name']);

        $this->treasuries->assertMayChangePayment($to, $from, false, PaymentType::Cash, TreasuryScopes::DEPOSIT, $manager);
        $this->addToAssertionCount(1);
    }

    public function testAssertMayChangePayment_AsksTheMainTreasuryForAPaymentThatWasOrBecomesUnstamped(): void
    {
        $manager = $this->manager();
        $mover   = $this->employeeWithGrants([]);
        $main    = $this->treasuries->ensureMain();
        $drawer  = $this->treasuries->create(['name' => 'Drawer', 'kind' => 'cash'], $manager);

        $this->treasuries->update($main, ['name' => 'Main', 'kind' => 'cash', 'deposit_scope' => 'staff', 'deposit_members' => [$manager], 'withdraw_scope' => 'staff', 'withdraw_members' => [$manager]], $manager);

        $toMain = $this->refusal(fn () => $this->treasuries->assertMayChangePayment(null, $drawer, false, PaymentType::Cash, TreasuryScopes::DEPOSIT, $mover));
        $fromMain = $this->refusal(fn () => $this->treasuries->assertMayChangePayment($drawer, null, false, PaymentType::Cash, TreasuryScopes::DEPOSIT, $mover));

        $this->assertSame(AccountingError::TreasuryDepositDenied, $toMain->error);
        $this->assertSame(AccountingError::TreasuryWithdrawDenied, $fromMain->error);
    }

    public function testSetDefaultFor_RefusesATreasuryThatIsDeactivatedOrOutsideTheEmployeesBranches(): void
    {
        $manager  = $this->manager();
        $other    = $this->createBranch('B2');
        $worker   = $this->employeeWithGrants([], [1]);
        $elsewhere = $this->treasuries->create(['name' => 'Elsewhere', 'kind' => 'cash', 'branch_id' => $other], $manager);
        $closed   = $this->treasuries->create(['name' => 'Closed', 'kind' => 'cash'], $manager);

        $this->treasuries->deactivate($closed, $manager);

        $this->assertSame(AccountingError::TreasuryInactive, $this->refusal(fn () => $this->treasuries->setDefaultFor($worker, $closed, $manager))->error);

        try {
            $this->treasuries->setDefaultFor($worker, $elsewhere, $manager);
            $this->fail('A treasury of a branch the employee cannot use was accepted.');
        } catch (BranchException $exception) {
            $this->assertSame(BranchError::NotAllowed, $exception->error);
        }

        $this->assertNull($this->treasuries->defaultFor($worker));
    }

    public function testSetDefaultFor_TakesTheDefaultAwayWhenNoTreasuryIsGiven(): void
    {
        $manager = $this->manager();
        $id      = $this->treasuries->create(['name' => 'Drawer', 'kind' => 'cash'], $manager);

        $this->treasuries->setDefaultFor($this->inEveryBranch($this->employeeId), $id, $manager);
        $this->treasuries->setDefaultFor($this->employeeId, null, $manager);

        $this->assertNull($this->treasuries->defaultFor($this->employeeId));
    }

    public function testPosition_SeparatesWhatDocumentsPutOnTheAccountFromWhatWasWrittenByHand(): void
    {
        $manager = $this->manager();
        $id      = $this->treasuries->create(['name' => 'Drawer', 'kind' => 'cash'], $manager);

        $this->fund($id, '100.00');

        (new PostingService())->post(new JournalDraft(
            date: '2026-10-21',
            lines: [new JournalLineDraft((int) $this->treasuries->accountOf($id), debit: '30.00'), new JournalLineDraft($this->accountId('opening_balance'), credit: '30.00')],
            sourceType: JournalDraft::SOURCE_MANUAL,
            sourceId: null,
            currencyCode: 'SAR',
        ));

        $position = $this->treasuries->position($id);

        $this->assertSame('130.0000', $position->balance);
        $this->assertSame('30.0000', $position->manual);
        $this->assertSame('100.0000', $position->documented());
        $this->assertFalse($position->agrees());
    }

    public function testAll_ListsTheMainTreasuryFirstAndOnlyTheBranchesGiven(): void
    {
        $manager = $this->manager();
        $other   = $this->createBranch('B2');

        $this->treasuries->create(['name' => 'Zeta', 'kind' => 'cash'], $manager);
        $this->treasuries->create(['name' => 'Alpha', 'kind' => 'bank', 'bank_name' => 'Gulf Bank', 'account_number' => '1234567890', 'branch_id' => $other], $manager);

        $this->assertSame(['Alpha', 'Zeta'], array_slice(array_map(static fn (object $row): string => $row->name, $this->treasuries->all()), 1));
        $this->assertSame(['Alpha'], array_map(static fn (object $row): string => $row->name, $this->treasuries->all([$other])));
        $this->assertSame([], $this->treasuries->all([]));
    }

    // ---------------------------------------------------------------------------------------
    // The treasury a payment on a document of one branch names in another
    // ---------------------------------------------------------------------------------------

    /**
     * A second branch with a treasury of its own.
     *
     * @return array{branch: int, treasury: int}
     */
    private function treasuryOfAnotherBranch(string $name = 'Branch drawer'): array
    {
        $branch = $this->createBranch('B' . random_int(10, 99));

        return ['branch' => $branch, 'treasury' => $this->treasuries->create(['name' => $name, 'kind' => 'cash', 'branch_id' => $branch], $this->manager())];
    }

    public function testOtherBranchOf_IsNothingForATreasuryOfTheBranchOfTheDocument(): void
    {
        $own = $this->treasuries->create(['name' => 'Own drawer', 'kind' => 'cash'], $this->manager());

        $this->assertNull($this->treasuries->otherBranchOf($own, 1, $this->employeeWithGrants([], [1])));
    }

    public function testOtherBranchOf_NamesTheBranchOfATreasuryOfAnotherForAnEmployeeWhoWorksInBoth(): void
    {
        ['branch' => $branch, 'treasury' => $treasury] = $this->treasuryOfAnotherBranch();

        $this->assertSame($branch, $this->treasuries->otherBranchOf($treasury, 1, $this->employeeWithGrants([], [1, $branch])));
        $this->assertSame($branch, $this->treasuries->otherBranchOf($treasury, 1, $this->employeeWithGrants([])), 'Every branch');
        $this->assertSame($branch, $this->treasuries->otherBranchOf($treasury, 1, null), 'The books');
    }

    public function testOtherBranchOf_RejectsATreasuryThatDoesNotExist(): void
    {
        $this->assertSame(AccountingError::TreasuryNotFound, $this->refusal(fn () => $this->treasuries->otherBranchOf(99999, 1, null))->error);
    }

    public function testOtherBranchOf_RejectsATreasuryOfAnotherBranchToAnEmployeeWhoWorksInOnlyOneOfTheTwoAsIfItWereNotThere(): void
    {
        ['branch' => $branch, 'treasury' => $treasury] = $this->treasuryOfAnotherBranch();

        foreach ([[1], [$branch]] as $works) {
            $this->assertSame(AccountingError::TreasuryNotFound, $this->refusal(fn () => $this->treasuries->otherBranchOf($treasury, 1, $this->employeeWithGrants([], $works)))->error);
        }
    }

    public function testOtherBranchOf_TellsAnEmployeeWhoMeetsAPaymentThatIsAlreadyInTheTreasuryThatBothBranchesAreNeeded(): void
    {
        ['branch' => $branch, 'treasury' => $treasury] = $this->treasuryOfAnotherBranch();

        foreach ([[1], [$branch]] as $works) {
            $refusal = $this->refusal(fn () => $this->treasuries->otherBranchOf($treasury, 1, $this->employeeWithGrants([], $works), true));

            $this->assertSame(AccountingError::InterBranchBranchesDenied, $refusal->error);
            $this->assertArrayHasKey('name', $refusal->context);
        }
    }

    public function testOtherBranchOf_RejectsATreasuryOfAnotherBranchThatIsDeactivatedUnlessThePaymentIsAlreadyInIt(): void
    {
        ['treasury' => $treasury] = $this->treasuryOfAnotherBranch();

        $this->treasuries->deactivate($treasury, $this->manager());

        $this->assertSame(AccountingError::TreasuryInactive, $this->refusal(fn () => $this->treasuries->otherBranchOf($treasury, 1, null))->error);
        $this->assertNotNull($this->treasuries->otherBranchOf($treasury, 1, null, true));
    }

    public function testOtherBranchOf_RejectsATreasuryOfAnotherBranchWhileTheBooksAreOff(): void
    {
        ['treasury' => $treasury] = $this->treasuryOfAnotherBranch();

        $this->setConfigValue('accounting_enabled', '0');

        $this->assertSame(AccountingError::TreasuryNotFound, $this->refusal(fn () => $this->treasuries->otherBranchOf($treasury, 1, null))->error);
    }

    public function testPlaceOfPayment_GivesTheTreasuryNamedAndTheBranchItBelongsToWhenThatIsAnother(): void
    {
        ['branch' => $branch, 'treasury' => $treasury] = $this->treasuryOfAnotherBranch();

        $own      = $this->treasuries->create(['name' => 'Own drawer', 'kind' => 'cash'], $this->manager());
        $employee = $this->employeeWithGrants([]);

        $this->assertSame([$treasury, $branch], $this->treasuries->placeOfPayment($treasury, PaymentType::Cash, TreasuryScopes::DEPOSIT, $employee, 1));
        $this->assertSame([$own, null], $this->treasuries->placeOfPayment($own, PaymentType::Cash, TreasuryScopes::DEPOSIT, $employee, 1));
    }

    public function testPlaceOfPayment_AsksTheScopeOfTheTreasuryNamedInTheDirectionOfThePayment(): void
    {
        ['treasury' => $treasury] = $this->treasuryOfAnotherBranch();

        $this->treasuries->update($treasury, ['name' => 'Branch drawer', 'kind' => 'cash', 'deposit_scope' => 'staff', 'deposit_members' => [$this->employeeWithGrants([])]], $this->manager());

        $employee = $this->employeeWithGrants([]);

        $this->assertSame(AccountingError::TreasuryDepositDenied, $this->refusal(fn () => $this->treasuries->placeOfPayment($treasury, PaymentType::Cash, TreasuryScopes::DEPOSIT, $employee, 1))->error);
        $this->assertSame($treasury, $this->treasuries->placeOfPayment($treasury, PaymentType::Cash, TreasuryScopes::WITHDRAW, $employee, 1)[0]);
    }

    public function testPlaceOfPayment_LeavesAPaymentThatNamesNoTreasuryToTheOneItsEmployeeWorksFromOfTheSameBranch(): void
    {
        ['treasury' => $far] = $this->treasuryOfAnotherBranch();

        $employee = $this->employeeWithGrants([]);
        $drawer   = $this->treasuries->create(['name' => 'Seller drawer', 'kind' => 'cash'], $this->manager());

        $this->treasuries->setDefaultFor($employee, $drawer);

        $this->assertSame([$drawer, null], $this->treasuries->placeOfPayment(0, PaymentType::Cash, TreasuryScopes::DEPOSIT, $employee, 1));
        $this->assertSame([$drawer, null], $this->treasuries->placeOfPayment(null, PaymentType::Cash, TreasuryScopes::DEPOSIT, $employee, 1));
        $this->assertNotSame($far, $drawer);
    }

    public function testChoicesFor_OffersTheTreasuriesOfTheBranchAndTheOthersTheEmployeeWorksInWithTheirCode(): void
    {
        ['branch' => $branch, 'treasury' => $far] = $this->treasuryOfAnotherBranch('Far drawer');

        $own      = $this->treasuries->create(['name' => 'Own drawer', 'kind' => 'cash'], $this->manager());
        $employee = $this->employeeWithGrants([], [1, $branch]);
        $options  = $this->treasuries->choicesFor(1, TreasuryScopes::DEPOSIT, $employee);

        $this->assertSame('Own drawer', $options[$own]);
        $this->assertSame('Far drawer (' . $this->treasuries->find($far)->branch_code . ')', $options[$far]);
    }

    public function testChoicesFor_OffersNothingOfAnotherBranchToAnEmployeeWhoDoesNotWorkInBothOrWhileTheBooksAreOff(): void
    {
        ['branch' => $branch, 'treasury' => $far] = $this->treasuryOfAnotherBranch('Far drawer');

        $own = $this->treasuries->create(['name' => 'Own drawer', 'kind' => 'cash'], $this->manager());

        $limited = array_keys($this->treasuries->choicesFor(1, TreasuryScopes::DEPOSIT, $this->employeeWithGrants([], [1])));

        $this->assertContains($own, $limited);
        $this->assertNotContains($far, $limited);

        $elsewhere = array_keys($this->treasuries->choicesFor($branch, TreasuryScopes::DEPOSIT, $this->employeeWithGrants([], [1])));

        $this->assertNotContains($own, $elsewhere, 'The branch of the document is one the employee does not work in');

        $this->setConfigValue('accounting_enabled', '0');

        $off = array_keys($this->treasuries->choicesFor(1, TreasuryScopes::DEPOSIT, $this->employeeWithGrants([])));

        $this->assertContains($own, $off);
        $this->assertNotContains($far, $off);
    }

    public function testChoicesFor_OffersOnlyActiveTreasuriesTheEmployeeMayUseInTheDirectionAndTheOnesAPaymentCarries(): void
    {
        ['branch' => $branch, 'treasury' => $far] = $this->treasuryOfAnotherBranch('Far drawer');

        $locked   = $this->treasuries->create(['name' => 'Locked drawer', 'kind' => 'cash', 'branch_id' => $branch, 'deposit_scope' => 'staff', 'deposit_members' => [$this->employeeWithGrants([])]], $this->manager());
        $retired  = $this->treasuries->create(['name' => 'Retired drawer', 'kind' => 'cash', 'branch_id' => $branch], $this->manager());
        $employee = $this->employeeWithGrants([], [1, $branch]);

        $this->treasuries->deactivate($retired, $this->manager());

        $options = $this->treasuries->choicesFor(1, TreasuryScopes::DEPOSIT, $employee);

        $this->assertArrayHasKey($far, $options);
        $this->assertArrayNotHasKey($locked, $options);
        $this->assertArrayNotHasKey($retired, $options);

        $carried = $this->treasuries->choicesFor(1, TreasuryScopes::DEPOSIT, $employee, [$locked, $retired]);

        $this->assertArrayHasKey($locked, $carried);
        $this->assertArrayHasKey($retired, $carried);
        $this->assertArrayHasKey($locked, $this->treasuries->choicesFor(1, TreasuryScopes::WITHDRAW, $employee), 'Only deposits are closed');
    }

    // ---------------------------------------------------------------------------------------
    // What the reference system recorded in the second write batch: fixture T-52
    // ---------------------------------------------------------------------------------------

    /**
     * A treasury that took 100 from the main one and gave it back, so it moved money and holds nothing.
     */
    private function treasuryThatMovedMoneyAndHoldsNothing(): int
    {
        $manager = $this->manager();
        $id      = $this->treasuries->create(['name' => 'Moved and empty', 'kind' => 'cash'], $manager);

        $this->fund($id, '100.00');
        (new TreasuryTransfers())->transfer($id, $this->treasuries->ensureMain(), '100.00', '2026-10-21');

        return $id;
    }

    public function testDelete_RemovesATreasuryWithNoMovementWithItsAccountAsTheReferenceSystemDoes(): void
    {
        $case    = $this->referenceCase('T-52', 'treasury_rules', 'a-treasury-with-no-movement-can-be-deleted', 'exact');
        $manager = $this->manager();
        $id      = $this->treasuries->create(['name' => 'Never used', 'kind' => 'cash'], $manager);
        $account = (int) $this->treasuries->accountOf($id);

        $this->treasuries->delete($id, $manager);

        $this->assertSameAsFixture($case['expected'], [
            'deleted'             => $this->treasuries->find($id) === null,
            'its_account_is_gone' => (int) $this->accountingDb()->table('accounting_accounts')->where('account_id', $account)->get()->getRow()->active === 0,
        ], 'treasury with no movement');
    }

    public function testDelete_RefusesATreasuryThatMovedMoneyEvenAtAZeroBalanceAsTheReferenceSystemDoes(): void
    {
        $case = $this->referenceCase('T-52', 'treasury_rules', 'a-treasury-that-moved-money-cannot-be-deleted-at-a-zero-balance', 'exact');
        $id   = $this->treasuryThatMovedMoneyAndHoldsNothing();

        $refusal = $this->refusal(fn () => $this->treasuries->delete($id, $this->manager()));

        $this->assertSame(AccountingError::TreasuryInUse, $refusal->error);
        $this->assertSameAsFixture($case['expected'], ['deleted' => $this->treasuries->find($id) === null, 'balance' => bcadd($this->treasuries->position($id)->balance, '0', 2)], 'treasury that moved money');
    }

    public function testDeactivate_AcceptsAnEmptyTreasuryAndActivateBringsItBackAsTheReferenceSystemDoes(): void
    {
        $case    = $this->referenceCase('T-52', 'treasury_rules', 'an-empty-treasury-can-be-deactivated-and-activated-again', 'exact');
        $id      = $this->treasuryThatMovedMoneyAndHoldsNothing();
        $manager = $this->manager();

        $this->treasuries->deactivate($id, $manager);

        $deactivated = (int) $this->treasuries->find($id)->active === 0;
        $transfers   = true;

        try {
            (new TreasuryTransfers())->transfer($this->treasuries->ensureMain(), $id, '1.00', '2026-10-21');
        } catch (AccountingException) {
            $transfers = false;
        }

        $this->treasuries->activate($id, $manager);

        $this->assertSameAsFixture($case['expected'], [
            'deactivated'                         => $deactivated,
            'can_be_transferred_to_while_inactive' => $transfers,
            'activated_again'                     => (int) $this->treasuries->find($id)->active === 1,
        ], 'deactivation and activation');
    }

    public function testPosition_LeavesOutThePreviewsOfABatch(): void
    {
        $drawer = $this->treasuries->create(['name' => 'Drawer', 'kind' => 'cash']);

        $this->fund($drawer, '100.00');

        $before = $this->treasuries->position($drawer);

        $this->previewEverything();
        $this->assertPreviewsStand();

        $this->assertEquals($before, $this->treasuries->position($drawer));
        $this->assertSame('100.0000', bcadd($this->treasuries->position($drawer)->balance, '0', 4));
    }

    public function testCreate_KeepsTheCodeAndTheEnglishNameOfABankPickedFromTheDirectory(): void
    {
        $id       = $this->treasuries->create(['name' => 'Main account', 'kind' => 'bank', 'bank_code' => 'SA_RAJHI', 'account_number' => 'SA0380000000608010167519'], $this->manager());
        $treasury = $this->treasuries->find($id);

        $this->assertSame(['SA_RAJHI', 'Al Rajhi Bank'], [$treasury->bank_code, $treasury->bank_name]);
        $this->assertFalse(TreasuryService::lacksBankDetails($treasury));
    }

    public function testCreate_TakesTheNameOfTheDirectoryOverANameTypedBesideTheCode(): void
    {
        $id = $this->treasuries->create(['name' => 'Main account', 'kind' => 'bank', 'bank_code' => 'SA_RIYAD', 'bank_name' => 'typed by hand', 'account_number' => '1234567890'], $this->manager());

        $this->assertSame('Riyad Bank', $this->treasuries->find($id)->bank_name);
    }

    public function testCreate_RejectsABankCodeThatIsNotInTheDirectoryAndKeepsNothing(): void
    {
        $refusal = $this->refusal(fn () => $this->treasuries->create(['name' => 'Main account', 'kind' => 'bank', 'bank_code' => 'XX_NOPE', 'bank_name' => 'Gulf Bank', 'account_number' => '1234567890'], $this->manager()));

        $this->assertSame(AccountingError::BankDirectoryCodeInvalid, $refusal->error);
        $this->assertSame('XX_NOPE', $refusal->context['code']);
        $this->assertSame(0, $this->countRows('treasuries'));
    }

    public function testCreate_KeepsABankOutsideTheDirectoryByTheNameTypedAndWithoutACode(): void
    {
        $id       = $this->treasuries->create(['name' => 'Local', 'kind' => 'bank', 'bank_code' => '', 'bank_name' => 'Village Savings Bank', 'account_number' => '1234567890'], $this->manager());
        $treasury = $this->treasuries->find($id);

        $this->assertSame(['Village Savings Bank', null], [$treasury->bank_name, $treasury->bank_code]);
    }

    public function testCreate_LeavesACashTreasuryWithoutABankCode(): void
    {
        $id = $this->treasuries->create(['name' => 'Drawer', 'kind' => 'cash', 'bank_code' => 'SA_RAJHI'], $this->manager());

        $this->assertNull($this->treasuries->find($id)->bank_code);
    }

    public function testCreate_RefusesTheSameAccountOfTheSameDirectoryBankTwiceAndOfAHandWrittenNameOfIt(): void
    {
        $manager = $this->manager();
        $this->treasuries->create(['name' => 'First', 'kind' => 'bank', 'bank_code' => 'SA_RAJHI', 'account_number' => '1234567890'], $manager);

        $same = $this->refusal(fn () => $this->treasuries->create(['name' => 'Second', 'kind' => 'bank', 'bank_code' => 'SA_RAJHI', 'account_number' => '1234 567890'], $manager));
        $typed = $this->refusal(fn () => $this->treasuries->create(['name' => 'Third', 'kind' => 'bank', 'bank_name' => 'Al Rajhi Bank', 'account_number' => '1234567890'], $manager));

        $this->assertSame([AccountingError::TreasuryBankAccountTaken, AccountingError::TreasuryBankAccountTaken], [$same->error, $typed->error]);
    }

    public function testUpdate_MovesABankAccountFromAHandWrittenNameToTheDirectoryAndAuditsWhatItWas(): void
    {
        $manager = $this->manager();
        $id      = $this->treasuries->create(['name' => 'Old bank', 'kind' => 'bank', 'bank_name' => 'Riyad Bank', 'account_number' => '1234567890'], $manager);

        $this->assertNull($this->treasuries->find($id)->bank_code);

        $this->treasuries->update($id, ['name' => 'Old bank', 'kind' => 'bank', 'bank_code' => 'SA_RIYAD', 'account_number' => '1234567890'], $manager);

        $this->assertSame('SA_RIYAD', $this->treasuries->find($id)->bank_code);

        $audit   = $this->accountingDb()->table('accounting_audit_log')->where(['entity_type' => 'treasury', 'entity_id' => $id, 'action' => 'updated'])->get()->getRow();
        $details = json_decode($audit->details, true);

        $this->assertNull($details['before']['bank_code']);
        $this->assertSame('SA_RIYAD', $details['after']['bank_code']);
    }

    public function testUpdate_DropsTheBankCodeWhenTheAccountBecomesACashTreasury(): void
    {
        $manager = $this->manager();
        $id      = $this->treasuries->create(['name' => 'Switching', 'kind' => 'bank', 'bank_code' => 'SA_RIYAD', 'account_number' => '1234567890'], $manager);

        $this->treasuries->update($id, ['name' => 'Switching', 'kind' => 'cash', 'bank_code' => 'SA_RIYAD'], $manager);

        $this->assertNull($this->treasuries->find($id)->bank_code);
        $this->assertNull($this->treasuries->find($id)->bank_name);
    }

    public function testMovements_NamesTheLineOfTheBooksOfEachMovement(): void
    {
        $manager = $this->manager();
        $id      = $this->treasuries->create(['name' => 'Bank', 'kind' => 'bank', 'bank_code' => 'SA_RIYAD', 'account_number' => '1234567890'], $manager);

        $this->fund($id, '40.00');

        $lines = array_column($this->treasuries->movements($id)['rows'], 'line_id');

        $this->assertCount(1, $lines);
        $this->assertSame((int) $this->treasuries->accountOf($id), (int) $this->accountingDb()->table('accounting_journal_lines')->where('line_id', $lines[0])->get()->getRow()->account_id);
    }

    // ---------------------------------------------------------------------------------------
    // A treasury that holds a foreign currency
    // ---------------------------------------------------------------------------------------

    private function dollarBox(string $name = 'Dollar box'): int
    {
        $currencies = new Currencies();
        $currencies->setActive('USD', true);

        if ($currencies->rates()->rateOn('USD', '2026-10-21') === null) {
            $currencies->rates()->add(['currency_code' => 'USD', 'rate' => '3.75', 'effective_date' => '2020-01-01']);
        }

        return $this->treasuries->create(['name' => $name, 'kind' => 'cash', 'currency_code' => 'USD'], $this->manager());
    }

    private function putDollars(int $treasuryId, string $dollars, string $rate = '3.75000000'): void
    {
        $base = bcmul($dollars, $rate, 2);

        (new PostingService())->post(new JournalDraft(
            date: '2026-10-21',
            lines: [
                new JournalLineDraft((int) $this->treasuries->accountOf($treasuryId), debit: $base, foreignAmount: $dollars, currencyCode: 'USD'),
                new JournalLineDraft($this->accountId('opening_balance'), credit: $base, foreignAmount: $dollars, currencyCode: 'USD'),
            ],
            sourceType: 'opening',
            sourceId: random_int(1000, 9999),
            currencyCode: 'USD',
            exchangeRate: $rate,
        ));
    }

    public function testCreate_AcceptsAForeignCurrencyAndTakesTheBaseCurrencyAsNone(): void
    {
        $dollars = $this->dollarBox();
        $base    = $this->treasuries->create(['name' => 'Riyal box', 'kind' => 'cash', 'currency_code' => 'SAR'], $this->manager());
        $none    = $this->treasuries->create(['name' => 'Plain box', 'kind' => 'bank', 'bank_name' => 'Riyad Bank', 'account_number' => '3500000009'], $this->manager());

        $this->assertSame('USD', $this->treasuries->find($dollars)->currency_code);
        $this->assertNull($this->treasuries->find($base)->currency_code);
        $this->assertNull($this->treasuries->find($none)->currency_code);
        $this->assertSame('USD', json_decode($this->accountingDb()->table('accounting_audit_log')->where(['entity_type' => 'treasury', 'entity_id' => $dollars, 'action' => 'created'])->get()->getRow()->details, true)['currency']);
    }

    public function testCreate_RejectsACurrencyThatIsUnknownOrSwitchedOff(): void
    {
        $this->assertSame(AccountingError::CurrencyNotFound, $this->refusal(fn () => $this->treasuries->create(['name' => 'Odd box', 'kind' => 'cash', 'currency_code' => 'QQQ'], $this->manager()))->error);
        $this->assertSame(AccountingError::CurrencyInactive, $this->refusal(fn () => $this->treasuries->create(['name' => 'Odd box', 'kind' => 'cash', 'currency_code' => 'EUR'], $this->manager()))->error);
        $this->assertSame(0, $this->accountingDb()->table('treasuries')->where('name', 'Odd box')->countAllResults());
    }

    public function testUpdate_ChangesTheCurrencyOfATreasuryThatNothingUsedAndWritesItToTheAuditLog(): void
    {
        $this->dollarBox('Anchor box');
        $treasury = $this->treasuries->create(['name' => 'Spare box', 'kind' => 'cash'], $this->manager());

        $this->treasuries->update($treasury, ['name' => 'Spare box', 'kind' => 'cash', 'currency_code' => 'USD'], $this->manager());

        $this->assertSame('USD', $this->treasuries->find($treasury)->currency_code);

        $audit = json_decode($this->accountingDb()->table('accounting_audit_log')->where(['entity_type' => 'treasury', 'entity_id' => $treasury, 'action' => 'updated'])->get()->getRow()->details, true);
        $this->assertSame([null, 'USD'], [$audit['before']['currency'], $audit['after']['currency']]);
    }

    public function testUpdate_RefusesToChangeTheCurrencyOfATreasuryThatHasBeenUsedAndOfTheMainOne(): void
    {
        $box = $this->dollarBox();
        $this->putDollars($box, '100.00');

        $this->assertSame(AccountingError::TreasuryCurrencyLocked, $this->refusal(fn () => $this->treasuries->update($box, ['name' => 'Dollar box', 'kind' => 'cash', 'currency_code' => ''], $this->manager()))->error);
        $this->assertSame('USD', $this->treasuries->find($box)->currency_code);

        $main = $this->treasuries->ensureMain();

        $this->assertSame(AccountingError::TreasuryCurrencyLocked, $this->refusal(fn () => $this->treasuries->update($main, ['name' => $this->treasuries->find($main)->name, 'kind' => 'cash', 'currency_code' => 'USD'], $this->manager()))->error);

        $this->treasuries->update($box, ['name' => 'Dollar box', 'kind' => 'cash', 'currency_code' => 'USD', 'description' => 'same currency, new text'], $this->manager());
        $this->assertSame('USD', $this->treasuries->find($box)->currency_code);
    }

    public function testUpdate_KeepsTheCurrencyWhenTheFormDoesNotSendIt(): void
    {
        $box = $this->dollarBox();
        $this->putDollars($box, '100.00');

        $this->treasuries->update($box, ['name' => 'Dollar box', 'kind' => 'cash', 'description' => 'renamed by an old form'], $this->manager());

        $this->assertSame('USD', $this->treasuries->find($box)->currency_code);
    }

    public function testPosition_KeepsTheBalanceInTheCurrencyOfTheTreasuryAndInTheBaseAndTheBaseStillEqualsTheAccount(): void
    {
        $box = $this->dollarBox();
        $this->putDollars($box, '100.00');
        $this->putDollars($box, '40.00', '3.60000000');

        $position = $this->treasuries->position($box);

        $this->assertSame('USD', $position->currencyCode);
        $this->assertSame('140.0000', $position->foreignBalance);
        $this->assertSame('519.0000', $position->balance);
        $this->assertSame(bcadd($position->balance, '0', 2), $this->balance((int) $position->accountId));
    }

    public function testPosition_HasNoForeignBalanceForATreasuryInTheBaseCurrency(): void
    {
        $position = $this->treasuries->position($this->treasuries->ensureMain());

        $this->assertNull($position->currencyCode);
        $this->assertNull($position->foreignBalance);
    }

    public function testPost_RefusesALineOnAForeignTreasuryThatHasNoAmountInItsCurrencyAndOneInAnotherCurrency(): void
    {
        $box = $this->dollarBox();
        (new Currencies())->setActive('EUR', true);
        (new Currencies())->rates()->add(['currency_code' => 'EUR', 'rate' => '4.10', 'effective_date' => '2020-01-01']);
        $account = (int) $this->treasuries->accountOf($box);

        $base = new JournalDraft(date: '2026-10-21', lines: [new JournalLineDraft($account, debit: '10.00'), new JournalLineDraft($this->accountId('opening_balance'), credit: '10.00')], sourceType: 'opening', sourceId: 4001, currencyCode: 'SAR');
        $euro = new JournalDraft(date: '2026-10-21', lines: [new JournalLineDraft($account, debit: '41.00', foreignAmount: '10.00', currencyCode: 'EUR'), new JournalLineDraft($this->accountId('opening_balance'), credit: '41.00', foreignAmount: '10.00', currencyCode: 'EUR')], sourceType: 'opening', sourceId: 4002, currencyCode: 'EUR', exchangeRate: '4.10');

        foreach ([$base, $euro] as $draft) {
            try {
                (new PostingService())->post($draft);
                $this->fail('A line on a treasury of dollars took something else.');
            } catch (PostingException $exception) {
                $this->assertSame(PostingError::TreasuryCurrency, $exception->error);
            }
        }

        $this->assertSame('0.0000', $this->treasuries->position($box)->balance);
    }

    public function testPost_AcceptsAForeignPaymentIntoATreasuryOfTheBaseCurrency(): void
    {
        $main = $this->treasuries->ensureMain();
        (new Currencies())->setActive('USD', true);

        $result = (new PostingService())->post(new JournalDraft(date: '2026-10-21', lines: [
            new JournalLineDraft((int) $this->treasuries->accountOf($main), debit: '375.00', foreignAmount: '100.00', currencyCode: 'USD'),
            new JournalLineDraft($this->accountId('sales_revenue'), credit: '375.00', foreignAmount: '100.00', currencyCode: 'USD'),
        ], sourceType: 'sale', sourceId: 4003, currencyCode: 'USD', exchangeRate: '3.75'));

        $this->assertTrue($result->created);
        $this->assertSame('375.0000', $this->treasuries->position($main)->balance);
    }
}
