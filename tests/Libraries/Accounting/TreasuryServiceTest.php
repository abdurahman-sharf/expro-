<?php

namespace Tests\Libraries\Accounting;

use App\Libraries\Accounting\AccountingError;
use App\Libraries\Accounting\AccountingException;
use App\Libraries\Accounting\AccountingGuard;
use App\Libraries\Accounting\JournalDraft;
use App\Libraries\Accounting\JournalLineDraft;
use App\Libraries\Accounting\PostingService;
use App\Libraries\Accounting\TreasuryService;
use App\Libraries\Accounting\TreasuryTransfers;
use App\Libraries\Organization\BranchError;
use App\Libraries\Organization\BranchException;
use App\Libraries\Organization\BranchGuard;
use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\DatabaseTestTrait;
use Config\Database;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Support\AccountingFixtureTrait;
use Tests\Support\EmployeeFixtureTrait;
use Tests\Support\ItemFixtureTrait;
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
    use AccountingFixtureTrait;
    use EmployeeFixtureTrait;
    use ItemFixtureTrait;
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
        $id = $this->treasuries->create(['name' => 'Gulf Bank', 'kind' => 'bank'], $this->manager());

        $this->assertSame('bank', $this->treasuries->find($id)->kind);
        $this->assertNotNull($this->treasuries->accountOf($id));
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

        $refusal = $this->refusal(fn () => $this->treasuries->create(['name' => 'Drawer', 'kind' => 'bank'], $manager));

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

        $this->treasuries->update($id, ['name' => 'Front drawer', 'kind' => 'bank', 'description' => 'Moved'], $manager);

        $this->assertSame('Front drawer', $this->treasuries->find($id)->name);
        $this->assertSame('bank', $this->treasuries->find($id)->kind);
        $this->assertSame('Front drawer', $this->accountingDb()->table('accounting_accounts')->where('account_id', $this->treasuries->accountOf($id))->get()->getRow()->name);
    }

    public function testUpdate_RefusesToMakeTheMainTreasuryABankAccount(): void
    {
        $manager = $this->manager();
        $main    = $this->treasuries->ensureMain();

        $this->assertSame(AccountingError::TreasuryIsMain, $this->refusal(fn () => $this->treasuries->update($main, ['name' => 'Main', 'kind' => 'bank'], $manager))->error);
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
        $this->treasuries->create(['name' => 'Alpha', 'kind' => 'bank', 'branch_id' => $other], $manager);

        $this->assertSame(['Alpha', 'Zeta'], array_slice(array_map(static fn (object $row): string => $row->name, $this->treasuries->all()), 1));
        $this->assertSame(['Alpha'], array_map(static fn (object $row): string => $row->name, $this->treasuries->all([$other])));
        $this->assertSame([], $this->treasuries->all([]));
    }
}
