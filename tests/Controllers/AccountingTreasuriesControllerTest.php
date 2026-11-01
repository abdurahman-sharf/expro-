<?php

namespace Tests\Controllers;

use App\Libraries\Accounting\AccountingGuard;
use App\Libraries\Accounting\Bank\BankDirectory;
use App\Libraries\Accounting\JournalDraft;
use App\Libraries\Accounting\JournalLineDraft;
use App\Libraries\Accounting\PostingService;
use App\Libraries\Accounting\TreasuryScopes;
use App\Libraries\Accounting\TreasuryService;
use App\Libraries\Accounting\TreasuryTransfers;
use CodeIgniter\Exceptions\PageNotFoundException;
use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\DatabaseTestTrait;
use CodeIgniter\Test\FeatureTestTrait;
use Config\Database;
use Tests\Support\AccountingFixtureTrait;
use Tests\Support\EmployeeFixtureTrait;
use Tests\Support\ItemFixtureTrait;
use Tests\Support\PurchaseAccountingFixtureTrait;
use Tests\Support\RoleFixtureTrait;
use Tests\Support\SaleAccountingFixtureTrait;

/**
 * The screen of treasuries: who may open it and change what it shows, what it lists and what each
 * treasury holds, the transfers between treasuries, and the treasury each employee works from.
 */
class AccountingTreasuriesControllerTest extends CIUnitTestCase
{
    use DatabaseTestTrait;
    use FeatureTestTrait;
    use AccountingFixtureTrait;
    use EmployeeFixtureTrait;
    use ItemFixtureTrait;
    use SaleAccountingFixtureTrait;
    use PurchaseAccountingFixtureTrait;
    use RoleFixtureTrait;

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

    /**
     * @param list<string>   $permissions
     * @param list<int>|null $branchIds
     */
    private function actAsWith(array $permissions, ?array $branchIds = null): int
    {
        $personId = $this->employeeWithGrants($permissions, $branchIds);

        $this->withSession(['person_id' => $personId, 'menu_group' => 'home']);

        return $personId;
    }

    private function manager(?array $branchIds = null): int
    {
        return $this->actAsWith(['accounting_treasuries', AccountingGuard::TREASURIES_MANAGE], $branchIds);
    }

    /**
     * @return array<string, mixed>
     */
    private function rowsOf(string $query = ''): array
    {
        return json_decode($this->get('/accounting_treasuries/search' . $query)->getJSON(), true);
    }

    private function fund(int $treasuryId, string $amount): void
    {
        $main = $this->treasuries->ensureMain();

        (new PostingService())->post(new JournalDraft(
            date: '2026-10-20',
            lines: [new JournalLineDraft((int) $this->treasuries->accountOf($main), debit: $amount), new JournalLineDraft($this->accountId('opening_balance'), credit: $amount)],
            sourceType: 'opening',
            sourceId: random_int(1000, 9999),
            currencyCode: 'SAR',
        ));

        (new TreasuryTransfers())->transfer($main, $treasuryId, $amount, '2026-10-21');
    }

    public function testGetIndex_ShowsTheTableToWhoeverHoldsTheModule(): void
    {
        $this->actAsWith(['accounting_treasuries']);

        $response = $this->get('/accounting_treasuries');

        $response->assertStatus(200);
        $response->assertSee('accounting_treasuries');
        $response->assertSee(lang('Accounting_treasuries.note'));
        $response->assertDontSee(lang('Accounting_treasuries.new'));
    }

    public function testGetIndex_OffersTheButtonsToWhoMayManage(): void
    {
        $this->manager();

        $response = $this->get('/accounting_treasuries');

        $response->assertSee(lang('Accounting_treasuries.new'));
        $response->assertSee(lang('Accounting_treasuries.defaults'));
    }

    public function testGetIndex_RedirectsAUserWithoutTheModuleGrant(): void
    {
        $this->actAsWith(['accounting_payables']);

        $this->get('/accounting_treasuries')->assertRedirect();
    }

    public function testGetIndex_IsNotFoundWhileAccountingIsOff(): void
    {
        $this->actAsWith(['accounting_treasuries']);
        $this->setConfigValue('accounting_enabled', '0');

        $this->expectException(PageNotFoundException::class);

        $this->get('/accounting_treasuries');
    }

    public function testGetSearch_ListsTheTreasuriesWithWhatEachHolds(): void
    {
        $manager = $this->manager();
        $drawer  = $this->treasuries->create(['name' => 'Drawer', 'kind' => 'cash'], $manager);

        $this->fund($drawer, '75.50');

        $result = $this->rowsOf();

        $this->assertSame(2, $result['total']);
        $this->assertStringContainsString(lang('Accounting_treasuries.main'), $result['rows'][0]['name']);
        $this->assertSame('0.00', strip_tags($result['rows'][0]['balance']), 'What the main treasury was given went on to the drawer');
        $this->assertSame('Drawer', strip_tags($result['rows'][1]['name']));
        $this->assertStringContainsString('75.50', strip_tags($result['rows'][1]['balance']));
        $this->assertSame(lang('Accounting_treasuries.kind_cash'), $result['rows'][1]['kind']);
    }

    public function testGetSearch_FiltersByNameAndShowsOnlyTheBranchesOfTheReader(): void
    {
        $manager = $this->manager();
        $other   = $this->createBranch('B2');

        $this->treasuries->create(['name' => 'Bank Alpha', 'kind' => 'bank', 'bank_name' => 'Gulf Bank', 'account_number' => '1234567890'], $manager);
        $this->treasuries->create(['name' => 'Elsewhere', 'kind' => 'cash', 'branch_id' => $other], $manager);

        $rows = $this->rowsOf('?search=alpha')['rows'];

        $this->assertCount(1, $rows);
        $this->assertStringStartsWith('Bank Alpha', strip_tags($rows[0]['name']));

        $this->actAsWith(['accounting_treasuries'], [$other]);

        $this->assertSame(['Elsewhere'], array_map(static fn (array $row): string => strip_tags($row['name']), $this->rowsOf()['rows']));
    }

    public function testGetSearch_ShowsWhatWasWrittenByHandOnTheAccountOfATreasury(): void
    {
        $manager = $this->manager();
        $drawer  = $this->treasuries->create(['name' => 'Drawer', 'kind' => 'cash'], $manager);

        (new PostingService())->post(new JournalDraft(
            date: '2026-10-21',
            lines: [new JournalLineDraft((int) $this->treasuries->accountOf($drawer), debit: '12.00'), new JournalLineDraft($this->accountId('opening_balance'), credit: '12.00')],
            sourceType: JournalDraft::SOURCE_MANUAL,
            sourceId: null,
            currencyCode: 'SAR',
        ));

        $rows = $this->rowsOf()['rows'];

        $this->assertStringContainsString('12.00', strip_tags($rows[1]['by_hand']));
        $this->assertStringContainsString(lang('Accounting_treasuries.by_hand_none'), strip_tags($rows[0]['by_hand']));
    }

    /**
     * Two treasuries, the first holding 100 and then sending 160 to the second, so that it ends
     * 60 below zero.
     *
     * @return array{int, int, int} The overdrawn treasury, the one that received, and the transfer.
     */
    private function overdrawn(): array
    {
        $manager = $this->manager();
        $short   = $this->treasuries->create(['name' => 'Short drawer', 'kind' => 'cash'], $manager);
        $other   = $this->treasuries->create(['name' => 'Other drawer', 'kind' => 'cash'], $manager);

        $this->fund($short, '100.00');

        $transferId = (new TreasuryTransfers())->transfer($short, $other, '160.00', '2026-10-22', '', $manager);

        return [$short, $other, $transferId];
    }

    public function testGetSearch_MarksATreasuryThatHoldsLessThanNothing(): void
    {
        $this->overdrawn();

        $byName = [];

        foreach ($this->rowsOf()['rows'] as $row) {
            $byName[strip_tags($row['name'])] = $row;
        }

        $this->assertCount(3, $byName);
        $this->assertStringContainsString('text-bg-danger', $byName['Short drawer']['balance']);
        $this->assertStringContainsString('60.00', strip_tags($byName['Short drawer']['balance']));
        $this->assertStringNotContainsString('text-bg-danger', $byName['Other drawer']['balance']);
    }

    public function testGetStatement_ShowsWhereTheBalanceWentBelowZero(): void
    {
        [$short, , $transferId] = $this->overdrawn();

        $response = $this->get('/accounting_treasuries/statement/' . $short);

        $response->assertStatus(200);
        $response->assertSee('id="treasury_negative"');
        $response->assertSee('id="treasury_negative_note"');
        $response->assertSee('id="treasury_crossings"');
        $response->assertSee('id="treasury_movements"');
        $response->assertSee(lang('Accounting.source_treasury_transfer'));
        $response->assertSee('#' . $transferId);
        $response->assertSee('-60.00');
        $response->assertSee('table-danger');
    }

    public function testGetStatement_SaysTheBalanceNeverWentBelowZeroForATreasuryThatDidNot(): void
    {
        [, $other] = $this->overdrawn();

        $response = $this->get('/accounting_treasuries/statement/' . $other);

        $response->assertStatus(200);
        $response->assertSee('id="treasury_no_crossings"');
        $response->assertDontSee('id="treasury_negative"');
        $response->assertDontSee('id="treasury_negative_note"');
    }

    public function testGetStatement_IsOpenToWhoeverMayOnlyReadAndLinksJournalsOnlyToWhoMayOpenThem(): void
    {
        [$short] = $this->overdrawn();

        $this->actAsWith(['accounting_treasuries']);

        $readOnly = $this->get('/accounting_treasuries/statement/' . $short);

        $readOnly->assertStatus(200);
        $readOnly->assertDontSee('accounting_journals/view');

        $this->actAsWith(['accounting_treasuries', 'accounting_journals']);

        $this->get('/accounting_treasuries/statement/' . $short)->assertSee('accounting_journals/view');
    }

    public function testGetStatement_IsNotFoundForATreasuryOfAnotherBranch(): void
    {
        $manager = $this->manager();
        $other   = $this->treasuries->create(['name' => 'Elsewhere', 'kind' => 'cash', 'branch_id' => $this->createBranch('B2')], $manager);

        $this->actAsWith(['accounting_treasuries'], [1]);

        $this->expectException(PageNotFoundException::class);

        $this->get('/accounting_treasuries/statement/' . $other);
    }

    public function testGetRow_IsNotFoundForATreasuryOfAnotherBranch(): void
    {
        $manager = $this->manager();
        $other   = $this->treasuries->create(['name' => 'Elsewhere', 'kind' => 'cash', 'branch_id' => $this->createBranch('B2')], $manager);

        $this->actAsWith(['accounting_treasuries'], [1]);

        $this->expectException(PageNotFoundException::class);

        $this->get('/accounting_treasuries/row/' . $other);
    }

    public function testPostSave_AddsATreasuryAndRefusesAnotherWithTheSameName(): void
    {
        $this->manager();

        $added = json_decode($this->post('/accounting_treasuries/save', ['name' => 'Drawer', 'kind' => 'cash', 'description' => 'Front'])->getJSON(), true);

        $this->assertTrue($added['success']);
        $this->assertSame(lang('Accounting_treasuries.successful_adding'), $added['message']);

        $again = json_decode($this->post('/accounting_treasuries/save', ['name' => 'Drawer', 'kind' => 'bank', 'bank_name' => 'Gulf Bank', 'account_number' => '1234567890'])->getJSON(), true);

        $this->assertFalse($again['success']);
        $this->assertSame(lang('Accounting.error_treasury_name_taken', ['Drawer']), $again['message']);
    }

    public function testPostSave_KeepsTheNameOfTheBankAndTheNumberOfTheAccountOfABank(): void
    {
        $this->manager();

        $added = json_decode($this->post('/accounting_treasuries/save', ['name' => 'Savings', 'kind' => 'bank', 'bank_name' => 'Gulf Bank', 'account_number' => 'sa03 8000 0000 6080'])->getJSON(), true);

        $this->assertTrue($added['success']);
        $this->assertSame('Gulf Bank', $this->treasuries->find($added['id'])->bank_name);
        $this->assertSame('SA03800000006080', $this->treasuries->find($added['id'])->account_number);
    }

    public function testPostSave_RefusesABankAccountWithoutItsBankOrItsNumberAndSaysWhichIsMissing(): void
    {
        $this->manager();

        $noBank = json_decode($this->post('/accounting_treasuries/save', ['name' => 'Savings', 'kind' => 'bank', 'bank_name' => '', 'account_number' => '1234567890'])->getJSON(), true);

        $this->assertFalse($noBank['success']);
        $this->assertSame(lang('Accounting.error_treasury_bank_name_invalid'), $noBank['message']);

        $noNumber = json_decode($this->post('/accounting_treasuries/save', ['name' => 'Savings', 'kind' => 'bank', 'bank_name' => 'Gulf Bank', 'account_number' => '12'])->getJSON(), true);

        $this->assertFalse($noNumber['success']);
        $this->assertSame(lang('Accounting.error_treasury_account_number_invalid'), $noNumber['message']);
        $this->assertSame(0, $this->countRows('treasuries'), 'Nothing was kept');
    }

    public function testPostSave_RefusesAnAccountThatIsAlreadyOneOfTheTreasuries(): void
    {
        $this->manager();

        $this->post('/accounting_treasuries/save', ['name' => 'Savings', 'kind' => 'bank', 'bank_name' => 'Gulf Bank', 'account_number' => '1234567890']);

        $again = json_decode($this->post('/accounting_treasuries/save', ['name' => 'Savings 2', 'kind' => 'bank', 'bank_name' => 'Gulf Bank', 'account_number' => '1234-567890'])->getJSON(), true);

        $this->assertFalse($again['success']);
        $this->assertSame(lang('Accounting.error_treasury_bank_account_taken'), $again['message']);
    }

    public function testGetView_AsksForTheBankAndTheAccountNumberOfABankOnly(): void
    {
        $manager = $this->manager();
        $bank    = $this->treasuries->create(['name' => 'Gulf Bank', 'kind' => 'bank', 'bank_name' => 'Gulf Bank', 'account_number' => '1234567890'], $manager);
        $drawer  = $this->treasuries->create(['name' => 'Drawer', 'kind' => 'cash'], $manager);

        $bankForm = $this->get('/accounting_treasuries/view/' . $bank);

        $this->assertStringContainsString('name="bank_name"', $bankForm->getBody());
        $this->assertStringContainsString('name="account_number"', $bankForm->getBody());
        $this->assertStringContainsString('value="1234567890"', $bankForm->getBody());
        $this->assertStringNotContainsString('id="bank_details_missing"', $bankForm->getBody());
        $this->assertStringNotContainsString('class="field bank-field" hidden', $bankForm->getBody());

        $drawerForm = $this->get('/accounting_treasuries/view/' . $drawer);

        $this->assertStringContainsString('class="field bank-field" hidden', $drawerForm->getBody());
    }

    public function testGetView_AsksToCompleteABankAccountThatWasOpenedBeforeTheDetailsWereAsked(): void
    {
        $manager = $this->manager();
        $bank    = $this->treasuries->create(['name' => 'Old bank', 'kind' => 'bank', 'bank_name' => 'Gulf Bank', 'account_number' => '1234567890'], $manager);

        $this->accountingDb()->table('treasuries')->where('treasury_id', $bank)->update(['bank_name' => null, 'account_number' => null]);

        $this->assertStringContainsString('id="bank_details_missing"', $this->get('/accounting_treasuries/view/' . $bank)->getBody());
    }

    public function testGetSearch_ShowsTheBankAndTheNumberOfABankAndAWarningForOneThatLacksThem(): void
    {
        $manager = $this->manager();
        $this->treasuries->create(['name' => 'Gulf savings', 'kind' => 'bank', 'bank_name' => 'Gulf Bank', 'account_number' => '1234567890'], $manager);
        $old = $this->treasuries->create(['name' => 'Old bank', 'kind' => 'bank', 'bank_name' => 'Gulf Bank', 'account_number' => '9999999999'], $manager);

        $this->accountingDb()->table('treasuries')->where('treasury_id', $old)->update(['bank_name' => null, 'account_number' => null]);

        $names = array_column($this->rowsOf()['rows'], 'name');

        $this->assertStringContainsString('Gulf Bank · <bdi dir="ltr">1234567890</bdi>', implode('|', $names));
        $this->assertStringContainsString(lang('Accounting_treasuries.bank_details_missing'), implode('|', $names));
    }

    public function testPostSave_UpdatesATreasuryAndKeepsTheOneWithoutThePermissionOut(): void
    {
        $manager = $this->manager();
        $drawer  = $this->treasuries->create(['name' => 'Drawer', 'kind' => 'cash'], $manager);

        $updated = json_decode($this->post('/accounting_treasuries/save/' . $drawer, ['name' => 'Front drawer', 'kind' => 'cash'])->getJSON(), true);

        $this->assertTrue($updated['success']);
        $this->assertSame('Front drawer', $this->treasuries->find($drawer)->name);

        $this->actAsWith(['accounting_treasuries']);

        $refused = json_decode($this->post('/accounting_treasuries/save/' . $drawer, ['name' => 'Hijacked', 'kind' => 'cash'])->getJSON(), true);

        $this->assertFalse($refused['success']);
        $this->assertSame(lang('Accounting.error_permission_denied'), $refused['message']);
        $this->assertSame('Front drawer', $this->treasuries->find($drawer)->name);
    }

    public function testPostDeactivate_RefusesATreasuryThatHoldsMoneyAndThenDeactivatesAnEmptyOne(): void
    {
        $manager = $this->manager();
        $funded  = $this->treasuries->create(['name' => 'Funded', 'kind' => 'cash'], $manager);
        $empty   = $this->treasuries->create(['name' => 'Empty', 'kind' => 'cash'], $manager);

        $this->fund($funded, '10.00');

        $refused = json_decode($this->post('/accounting_treasuries/deactivate/' . $funded)->getJSON(), true);

        $this->assertFalse($refused['success']);
        $this->assertStringContainsString('10.00', $refused['message']);

        $done = json_decode($this->post('/accounting_treasuries/deactivate/' . $empty)->getJSON(), true);

        $this->assertTrue($done['success']);
        $this->assertSame(0, (int) $this->treasuries->find($empty)->active);

        $this->assertTrue(json_decode($this->post('/accounting_treasuries/activate/' . $empty)->getJSON(), true)['success']);
    }

    public function testPostDeleteTreasury_DeletesAnUnusedTreasuryAndRefusesTheMainOne(): void
    {
        $manager = $this->manager();
        $drawer  = $this->treasuries->create(['name' => 'Drawer', 'kind' => 'cash'], $manager);

        $this->assertTrue(json_decode($this->post('/accounting_treasuries/delete/' . $drawer)->getJSON(), true)['success']);
        $this->assertNull($this->treasuries->find($drawer));

        $refused = json_decode($this->post('/accounting_treasuries/delete/' . $this->treasuries->ensureMain())->getJSON(), true);

        $this->assertFalse($refused['success']);
        $this->assertSame(lang('Accounting.error_treasury_is_main'), $refused['message']);
    }

    public function testPostTransfer_MovesMoneyAndListsTheTransfer(): void
    {
        $manager = $this->manager();
        $drawer  = $this->treasuries->create(['name' => 'Drawer', 'kind' => 'cash'], $manager);
        $main    = $this->treasuries->ensureMain();

        $this->fund($drawer, '100.00');

        $done = json_decode($this->post('/accounting_treasuries/transfer', ['from_treasury_id' => $drawer, 'to_treasury_id' => $main, 'amount' => '40.25', 'transfer_date' => '2026-10-22', 'note' => 'Back'])->getJSON(), true);

        $this->assertTrue($done['success']);
        $this->assertSame('59.7500', $this->treasuries->position($drawer)->balance);

        $rows = json_decode($this->get('/accounting_treasuries/transfers/search')->getJSON(), true);

        $this->assertSame(2, $rows['total']);
        $this->assertSame('Back', $rows['rows'][0]['note']);
        $this->assertSame('Drawer', $rows['rows'][0]['from_name']);
        $this->assertStringContainsString(lang('Accounting_treasuries.status_completed'), $rows['rows'][0]['status']);
    }

    public function testPostTransfer_RefusesTheSameTreasuryAndAnAmountOfNothing(): void
    {
        $manager = $this->manager();
        $drawer  = $this->treasuries->create(['name' => 'Drawer', 'kind' => 'cash'], $manager);

        $same = json_decode($this->post('/accounting_treasuries/transfer', ['from_treasury_id' => $drawer, 'to_treasury_id' => $drawer, 'amount' => '1', 'transfer_date' => '2026-10-22'])->getJSON(), true);
        $zero = json_decode($this->post('/accounting_treasuries/transfer', ['from_treasury_id' => $drawer, 'to_treasury_id' => $this->treasuries->ensureMain(), 'amount' => '0', 'transfer_date' => '2026-10-22'])->getJSON(), true);

        $this->assertFalse($same['success']);
        $this->assertSame(lang('Accounting.error_transfer_same_treasury'), $same['message']);
        $this->assertFalse($zero['success']);
        $this->assertSame(lang('Accounting.error_transfer_amount_invalid'), $zero['message']);
    }

    public function testPostCancelTransfer_ReversesTheTransferAndNeedsAReason(): void
    {
        $manager = $this->manager();
        $drawer  = $this->treasuries->create(['name' => 'Drawer', 'kind' => 'cash'], $manager);

        $this->fund($drawer, '100.00');

        $transferId = (int) $this->accountingDb()->table('treasury_transfers')->selectMax('transfer_id', 'last')->get()->getRow()->last;

        $refused = json_decode($this->post('/accounting_treasuries/cancel_transfer/' . $transferId, ['reason' => ' '])->getJSON(), true);

        $this->assertFalse($refused['success']);

        $done = json_decode($this->post('/accounting_treasuries/cancel_transfer/' . $transferId, ['reason' => 'Wrong drawer'])->getJSON(), true);

        $this->assertTrue($done['success']);
        $this->assertSame('0.0000', $this->treasuries->position($drawer)->balance);
        $this->assertSame('cancelled', (new TreasuryTransfers())->find($transferId)->status);
    }

    /**
     * A treasury in a second branch and the branch.
     *
     * @return array{int, int}
     */
    private function inSecondBranch(int $manager, string $name = 'Elsewhere'): array
    {
        $branch = $this->createBranch('B2');

        return [$this->treasuries->create(['name' => $name, 'kind' => 'cash', 'branch_id' => $branch], $manager), $branch];
    }

    public function testPostTransfer_MovesMoneyBetweenTwoBranchesAndListsItWithBothBranches(): void
    {
        $manager         = $this->manager();
        [$other, $branch] = $this->inSecondBranch($manager);
        $main            = $this->treasuries->ensureMain();

        $done = json_decode($this->post('/accounting_treasuries/transfer', ['from_treasury_id' => $main, 'to_treasury_id' => $other, 'amount' => '75.50', 'transfer_date' => '2026-10-22', 'note' => 'Float'])->getJSON(), true);

        $this->assertTrue($done['success']);
        $this->assertSame('75.5000', $this->treasuries->position($other)->balance);

        $rows = json_decode($this->get('/accounting_treasuries/transfers/search')->getJSON(), true);
        $row  = $rows['rows'][0];

        $this->assertStringContainsString('B2', $row['branch_code']);
        $this->assertStringContainsString(lang('Accounting_treasuries.between_branches'), $row['branch_code']);
    }

    public function testPostTransfer_SaysTheAccountsBetweenBranchesAreMissingWhenTheRoutingHasNone(): void
    {
        $manager   = $this->manager();
        [$other]   = $this->inSecondBranch($manager);

        $this->accountingDb()->table('accounting_routing')->whereIn('routing_key', ['branches.receivable', 'branches.payable'])->update(['account_id' => null]);

        $result = json_decode($this->post('/accounting_treasuries/transfer', ['from_treasury_id' => $this->treasuries->ensureMain(), 'to_treasury_id' => $other, 'amount' => '5', 'transfer_date' => '2026-10-22'])->getJSON(), true);

        $this->assertFalse($result['success']);
        $this->assertSame(lang('Accounting.error_inter_branch_accounts_missing'), $result['message']);
    }

    public function testGetTransferForm_OffersTheTreasuriesOfEveryBranchOfTheManagerAndNamesTheBranchOfEach(): void
    {
        $manager = $this->manager();

        $this->inSecondBranch($manager);

        $body = $this->get('/accounting_treasuries/transfer_form')->getBody();

        $this->assertStringContainsString('Elsewhere (B2)', $body);
        $this->assertStringContainsString('data-branch="', $body);
        $this->assertStringContainsString('id="between_note"', $body);
    }

    public function testGetTransfers_ShowsWhatEachBranchIsOwedAndOwesAndNothingWhenThereIsNoTransferBetweenBranches(): void
    {
        $manager = $this->manager();

        $this->get('/accounting_treasuries/transfers')->assertDontSee('id="branch_balances"');

        [$other] = $this->inSecondBranch($manager);

        $this->post('/accounting_treasuries/transfer', ['from_treasury_id' => $this->treasuries->ensureMain(), 'to_treasury_id' => $other, 'amount' => '40', 'transfer_date' => '2026-10-22']);

        $page = $this->get('/accounting_treasuries/transfers');

        $page->assertSee('id="branch_balances"');
        $page->assertSee(lang('Accounting_treasuries.branch_balances_title'));
        $page->assertSee('id="branch_balances_note"');
        $this->assertStringNotContainsString(lang('Accounting_treasuries.between_difference', ['0.00']), $page->getBody());
    }

    public function testGetTransfers_LinksToTheBalancesBetweenBranches(): void
    {
        $this->manager();

        $this->get('/accounting_treasuries/transfers')->assertSee('accounting_treasuries/inter_branch');
    }

    public function testGetTransfers_WarnsWhenTheAccountsBetweenBranchesDoNotOffset(): void
    {
        $this->manager();

        (new PostingService())->post(new JournalDraft(
            date: '2026-10-21',
            lines: [new JournalLineDraft($this->accountId('branches_receivable'), debit: '50.00'), new JournalLineDraft($this->accountId('opening_balance'), credit: '50.00')],
            sourceType: 'opening',
            sourceId: random_int(1000, 9999),
            currencyCode: 'SAR',
        ));

        $this->get('/accounting_treasuries/transfers')->assertSee(lang('Accounting_treasuries.between_difference', ['50.00']));
    }

    public function testGetTransfersSearch_OffersTheCancelIconOnlyToWhoWorksInEveryBranchOfTheTransfer(): void
    {
        $manager          = $this->manager();
        [$other, $branch] = $this->inSecondBranch($manager);

        (new TreasuryTransfers())->transfer($this->treasuries->ensureMain(), $other, '10', '2026-10-22', '', $manager);

        $this->assertStringContainsString('cancel_transfer_form', json_decode($this->get('/accounting_treasuries/transfers/search')->getJSON(), true)['rows'][0]['edit']);

        $this->actAsWith(['accounting_treasuries', AccountingGuard::TREASURIES_MANAGE], [$branch]);

        $this->assertSame('', json_decode($this->get('/accounting_treasuries/transfers/search')->getJSON(), true)['rows'][0]['edit']);
    }

    public function testGetCancelTransferForm_IsNotFoundToSomeoneWhoWorksInOnlyOneOfTheBranchesButTheTransferIsStillListedForThem(): void
    {
        $manager          = $this->manager();
        [$other, $branch] = $this->inSecondBranch($manager);
        $id               = (new TreasuryTransfers())->transfer($this->treasuries->ensureMain(), $other, '10', '2026-10-22', '', $manager);

        $this->actAsWith(['accounting_treasuries', AccountingGuard::TREASURIES_MANAGE], [$branch]);

        $this->assertSame(1, json_decode($this->get('/accounting_treasuries/transfers/search')->getJSON(), true)['total']);
        $this->get('/accounting_treasuries/transfers/row/' . $id)->assertStatus(200);

        $this->expectException(PageNotFoundException::class);

        $this->get('/accounting_treasuries/cancel_transfer_form/' . $id);
    }

    public function testGetTransferForm_OffersOnlyActiveTreasuriesOfTheBranchesOfTheManager(): void
    {
        $manager = $this->manager();
        $other   = $this->createBranch('B2');
        $closed  = $this->treasuries->create(['name' => 'Closed drawer', 'kind' => 'cash'], $manager);

        $this->treasuries->create(['name' => 'Elsewhere', 'kind' => 'cash', 'branch_id' => $other], $manager);
        $this->treasuries->deactivate($closed, $manager);
        $this->actAsWith(['accounting_treasuries', AccountingGuard::TREASURIES_MANAGE], [1]);

        $response = $this->get('/accounting_treasuries/transfer_form');

        $response->assertStatus(200);
        $response->assertDontSee('Closed drawer');
        $response->assertDontSee('Elsewhere');
    }

    public function testGetTransferForm_OffersTheTreasuriesTheManagerMayWithdrawFromAndDepositIntoEachInItsOwnList(): void
    {
        $manager = $this->manager();
        $other   = $this->employeeWithGrants([]);

        $this->treasuries->create(['name' => 'Payout only', 'kind' => 'cash', 'deposit_scope' => 'staff', 'deposit_members' => [$other]], $manager);
        $this->treasuries->create(['name' => 'Intake only', 'kind' => 'cash', 'withdraw_scope' => 'staff', 'withdraw_members' => [$other]], $manager);

        $body = $this->get('/accounting_treasuries/transfer_form')->getBody();

        [$from, $to] = explode('name="to_treasury_id"', $body, 2);

        $this->assertStringContainsString('Payout only', $from);
        $this->assertStringNotContainsString('Intake only', $from);
        $this->assertStringContainsString('Intake only', $to);
        $this->assertStringNotContainsString('Payout only', $to);
    }

    public function testPostTransfer_RefusesATreasuryTheManagerMayNotWithdrawFromAndSaysWhich(): void
    {
        $manager = $this->manager();
        $other   = $this->employeeWithGrants([]);
        $source  = $this->treasuries->create(['name' => 'Locked source', 'kind' => 'cash', 'withdraw_scope' => 'staff', 'withdraw_members' => [$other]], $manager);
        $target  = $this->treasuries->create(['name' => 'Target', 'kind' => 'cash'], $manager);

        $refused = json_decode($this->post('/accounting_treasuries/transfer', ['from_treasury_id' => $source, 'to_treasury_id' => $target, 'amount' => '5', 'transfer_date' => '2026-10-22'])->getJSON(), true);

        $this->assertFalse($refused['success']);
        $this->assertSame(lang('Accounting.error_treasury_withdraw_denied', ['Locked source']), $refused['message']);
        $this->assertStringContainsString('Locked source', $refused['message']);
    }

    public function testGetView_OffersTheScopesOfATreasuryWithTheEmployeesAndTheBranchesToChooseFrom(): void
    {
        $manager = $this->manager();
        $branch  = $this->createBranch('B2');
        $drawer  = $this->treasuries->create(['name' => 'Scoped drawer', 'kind' => 'cash', 'deposit_scope' => 'staff', 'deposit_members' => [$manager], 'withdraw_scope' => 'branches', 'withdraw_members' => [$branch]], $manager);

        $body = $this->get('/accounting_treasuries/view/' . $drawer)->getBody();

        $this->assertStringContainsString('name="deposit_scope"', $body);
        $this->assertStringContainsString('name="withdraw_scope"', $body);
        $this->assertMatchesRegularExpression('/<option value="staff" selected>/', $body);
        $this->assertMatchesRegularExpression('/<option value="branches" selected>/', $body);
        $this->assertMatchesRegularExpression('/name="deposit_members\[\]" value="' . $manager . '" checked/', $body);
        $this->assertMatchesRegularExpression('/name="withdraw_members\[\]" value="' . $branch . '" checked/', $body);
        $this->assertStringContainsString(lang('Accounting_treasuries.scope_title'), $body);
    }

    public function testGetView_OffersTheActiveRolesAndStillOpensBeforeTheMigrationOfTheRolesHasRun(): void
    {
        $manager = $this->manager();
        $role    = $this->createRole([], 'all', true, 'Tellers ' . uniqid());
        $retired = $this->createRole([], 'all', false, 'Retired ' . uniqid());
        $drawer  = $this->treasuries->create(['name' => 'Role drawer', 'kind' => 'cash'], $manager);

        $body = $this->get('/accounting_treasuries/view/' . $drawer)->getBody();

        $this->assertStringContainsString('id="deposit_role_' . $role . '"', $body);
        $this->assertStringNotContainsString('id="deposit_role_' . $retired . '"', $body);

        $this->withoutRoleTables(function () use ($drawer): void {
            foreach (['/accounting_treasuries/view/' . $drawer, '/accounting_treasuries/view'] as $url) {
                $page = $this->get($url);

                $page->assertStatus(200);
                $this->assertStringContainsString('name="deposit_scope"', $page->getBody());
                $this->assertStringNotContainsString('id="deposit_role_', $page->getBody());
            }
        });
    }

    public function testGetView_OffersEverybodyAsTheScopeOfANewTreasury(): void
    {
        $this->manager();

        $body = $this->get('/accounting_treasuries/view')->getBody();

        $this->assertMatchesRegularExpression('/<option value="all" selected>/', $body);
        $this->assertStringNotContainsString('<option value="staff" selected>', $body);
    }

    public function testPostSave_StoresTheScopesTheFormSends(): void
    {
        $manager = $this->manager();
        $branch  = $this->createBranch('B2');

        $added = json_decode($this->post('/accounting_treasuries/save', [
            'name'             => 'Scoped drawer',
            'kind'             => 'cash',
            'deposit_scope'    => 'staff',
            'deposit_members'  => [(string) $manager],
            'withdraw_scope'   => 'branches',
            'withdraw_members' => [(string) $branch],
        ])->getJSON(), true);

        $this->assertTrue($added['success']);

        $scopes = new TreasuryScopes();

        $this->assertSame(['mode' => 'staff', 'members' => [$manager], 'exists' => true], $scopes->scopeOf($added['id'], TreasuryScopes::DEPOSIT));
        $this->assertSame(['mode' => 'branches', 'members' => [$branch], 'exists' => true], $scopes->scopeOf($added['id'], TreasuryScopes::WITHDRAW));
    }

    public function testPostSave_RefusesAScopeOfEmployeesThatNamesNobodyAndKeepsTheOldOne(): void
    {
        $manager = $this->manager();
        $drawer  = $this->treasuries->create(['name' => 'Scoped drawer', 'kind' => 'cash', 'deposit_scope' => 'staff', 'deposit_members' => [$manager]], $manager);

        $refused = json_decode($this->post('/accounting_treasuries/save/' . $drawer, ['name' => 'Renamed', 'kind' => 'cash', 'deposit_scope' => 'staff'])->getJSON(), true);

        $this->assertFalse($refused['success']);
        $this->assertSame(lang('Accounting.error_treasury_scope_invalid'), $refused['message']);
        $this->assertSame('Scoped drawer', $this->treasuries->find($drawer)->name);
        $this->assertSame([$manager], (new TreasuryScopes())->scopeOf($drawer, TreasuryScopes::DEPOSIT)['members']);
    }

    public function testPostSave_LeavesTheScopesAloneWhenTheFormSendsNone(): void
    {
        $manager = $this->manager();
        $drawer  = $this->treasuries->create(['name' => 'Scoped drawer', 'kind' => 'cash', 'withdraw_scope' => 'staff', 'withdraw_members' => [$manager]], $manager);

        $this->post('/accounting_treasuries/save/' . $drawer, ['name' => 'Renamed', 'kind' => 'cash']);

        $this->assertSame('Renamed', $this->treasuries->find($drawer)->name);
        $this->assertSame('staff', (new TreasuryScopes())->scopeOf($drawer, TreasuryScopes::WITHDRAW)['mode']);
    }

    public function testPostSave_DoesNotLetAnEmployeeWithoutThePermissionToManageChangeAScopeHoweverTheyAreListedInIt(): void
    {
        $manager = $this->manager();
        $drawer  = $this->treasuries->create(['name' => 'Scoped drawer', 'kind' => 'cash'], $manager);
        $member  = $this->actAsWith(['accounting_treasuries']);

        $refused = json_decode($this->post('/accounting_treasuries/save/' . $drawer, ['name' => 'Scoped drawer', 'kind' => 'cash', 'deposit_scope' => 'staff', 'deposit_members' => [(string) $member]])->getJSON(), true);

        $this->assertFalse($refused['success']);
        $this->assertSame(lang('Accounting.error_permission_denied'), $refused['message']);
        $this->assertSame('all', (new TreasuryScopes())->scopeOf($drawer, TreasuryScopes::DEPOSIT)['mode']);
    }

    public function testGetSearch_ShowsWhoMayDepositAndWithdrawWhenItIsNotEverybody(): void
    {
        $manager = $this->manager();

        $this->treasuries->create(['name' => 'Open drawer', 'kind' => 'cash'], $manager);
        $this->treasuries->create(['name' => 'Scoped drawer', 'kind' => 'cash', 'deposit_scope' => 'staff', 'deposit_members' => [$manager], 'withdraw_scope' => 'branches', 'withdraw_members' => [1]], $manager);

        $rows = array_column($this->rowsOf('?search=drawer')['rows'], 'name');

        $this->assertStringNotContainsString(explode('{0}', lang('Accounting_treasuries.scope_line_deposit', ['{0}']))[0], $rows[0]);
        $this->assertStringContainsString(lang('Accounting_treasuries.scope_line_deposit', [lang('Accounting_treasuries.scope_staff_count', [1])]), $rows[1]);
        $this->assertStringContainsString(lang('Accounting_treasuries.scope_line_withdraw', [lang('Accounting_treasuries.scope_branches_count', [1])]), $rows[1]);
    }

    public function testGetTransferForm_IsNotFoundWithoutThePermissionToManage(): void
    {
        $this->actAsWith(['accounting_treasuries']);

        $this->expectException(PageNotFoundException::class);

        $this->get('/accounting_treasuries/transfer_form');
    }

    public function testPostDefault_ChoosesTheTreasuryAnEmployeeWorksFromAndTakesItAway(): void
    {
        $manager  = $this->manager();
        $drawer   = $this->treasuries->create(['name' => 'Drawer', 'kind' => 'cash'], $manager);
        $employee = $this->employeeWithGrants([]);

        $page = $this->get('/accounting_treasuries/defaults');

        $page->assertStatus(200);
        $page->assertSee(lang('Accounting_treasuries.defaults_note'));

        $this->assertTrue(json_decode($this->post('/accounting_treasuries/default/' . $employee, ['treasury_id' => $drawer])->getJSON(), true)['success']);
        $this->assertSame($drawer, $this->treasuries->defaultFor($employee));

        $this->assertTrue(json_decode($this->post('/accounting_treasuries/default/' . $employee, ['treasury_id' => 0])->getJSON(), true)['success']);
        $this->assertNull($this->treasuries->defaultFor($employee));
    }

    public function testPostDefault_RefusesATreasuryOutsideTheBranchesOfTheEmployee(): void
    {
        $manager  = $this->manager();
        $other    = $this->createBranch('B2');
        $elsewhere = $this->treasuries->create(['name' => 'Elsewhere', 'kind' => 'cash', 'branch_id' => $other], $manager);
        $employee = $this->employeeWithGrants([], [1]);

        $refused = json_decode($this->post('/accounting_treasuries/default/' . $employee, ['treasury_id' => $elsewhere])->getJSON(), true);

        $this->assertFalse($refused['success']);
        $this->assertNull($this->treasuries->defaultFor($employee));
    }

    public function testGetView_OffersTheBankDirectoryWithBothNamesAndPreselectsTheBankOfTheAccount(): void
    {
        $manager = $this->manager();
        $bank    = $this->treasuries->create(['name' => 'Rajhi', 'kind' => 'bank', 'bank_code' => 'SA_RAJHI', 'account_number' => '1234567890'], $manager);

        $response = $this->get('/accounting_treasuries/view/' . $bank);
        $body     = $response->getBody();

        $response->assertSee('مصرف الراجحي');
        $this->assertStringContainsString('name="bank_code"', $body);
        $this->assertStringContainsString('id="bank_search"', $body);
        $this->assertStringContainsString('<option value="SA_RAJHI"', $body);
        $this->assertMatchesRegularExpression('/<option value="SA_RAJHI"[^>]* selected>Al Rajhi Bank - /', $body);
        $this->assertStringContainsString('<option value="AE_ENBD"', $body);
        $this->assertMatchesRegularExpression('/class="field bank-field bank-name-field" hidden/', $body);
        $this->assertStringContainsString(lang('Accounting_treasuries.bank_other'), $body);
    }

    public function testGetBanks_FindsABankWhateverTheCaseTheArabicMarksAndTheLetterFormsTyped(): void
    {
        $this->manager();

        $codes = fn (string $term): array => array_column(json_decode($this->get('/accounting_treasuries/banks?term=' . rawurlencode($term))->getJSON(), true)['rows'], 'code');

        $this->assertContains('SA_ANB', $codes('العربى الوطنى'));
        $this->assertContains('SA_RIYAD', $codes('بَنك الرياض'));
        $this->assertContains('SA_RIYAD', $codes('RIYAD  bank'));
        $this->assertContains('SA_RAJHI', $codes('مصرف الراجحي'));
        $this->assertSame([], $codes('no such bank anywhere'));
        $this->assertCount(count(BankDirectory::search()), $codes(''));
    }

    public function testGetBanks_IsNotFoundToWhoCannotManageTreasuries(): void
    {
        $this->actAsWith(['accounting_treasuries']);

        $this->expectException(PageNotFoundException::class);

        $this->get('/accounting_treasuries/banks?term=riyad');
    }

    public function testGetView_SearchesTheBankDirectoryOnTheServerSoThatItIgnoresTheMarksAndLetterFormsAsTheDirectoryDoes(): void
    {
        $manager = $this->manager();
        $bank    = $this->treasuries->create(['name' => 'Rajhi', 'kind' => 'bank', 'bank_code' => 'SA_RAJHI', 'account_number' => '1234567890'], $manager);

        $body = $this->get('/accounting_treasuries/view/' . $bank)->getBody();

        $this->assertStringContainsString('accounting_treasuries\/banks', $body);
        $this->assertStringNotContainsString('data-search', $body);
    }

    public function testGetView_PreselectsTheDirectoryBankOfAnAccountOpenedByHandWhoseNameIsInItAndSavesNothing(): void
    {
        $manager = $this->manager();
        $bank    = $this->treasuries->create(['name' => 'Old Riyad', 'kind' => 'bank', 'bank_name' => 'Riyad Bank', 'account_number' => '1234567890'], $manager);
        $before  = $this->accountingDb()->table('treasuries')->where('treasury_id', $bank)->get()->getRowArray();

        $body = $this->get('/accounting_treasuries/view/' . $bank)->getBody();

        $this->assertMatchesRegularExpression('/<option value="SA_RIYAD"[^>]* selected>/', $body);
        $this->assertSame($before, $this->accountingDb()->table('treasuries')->where('treasury_id', $bank)->get()->getRowArray());
    }

    public function testGetView_ShowsTheNameOfABankOutsideTheDirectoryAndSelectsNoneOfTheList(): void
    {
        $manager = $this->manager();
        $bank    = $this->treasuries->create(['name' => 'Village', 'kind' => 'bank', 'bank_name' => 'Village Savings Bank', 'account_number' => '1234567890'], $manager);

        $body = $this->get('/accounting_treasuries/view/' . $bank)->getBody();

        $this->assertDoesNotMatchRegularExpression('/<option value="[A-Z]{2}_[A-Z0-9]+"[^>]* selected>/', $body);
        $this->assertDoesNotMatchRegularExpression('/class="field bank-field bank-name-field" hidden/', $body);
        $this->assertStringContainsString('value="Village Savings Bank"', $body);
    }

    public function testPostSave_KeepsTheBankPickedFromTheDirectoryAndRefusesACodeThatIsNotIn(): void
    {
        $this->manager();

        $added   = json_decode($this->post('/accounting_treasuries/save', ['name' => 'Rajhi', 'kind' => 'bank', 'bank_code' => 'SA_RAJHI', 'bank_name' => '', 'account_number' => '1234567890'])->getJSON(), true);
        $refused = json_decode($this->post('/accounting_treasuries/save', ['name' => 'Nowhere', 'kind' => 'bank', 'bank_code' => 'XX_NOPE', 'bank_name' => '', 'account_number' => '5555555555'])->getJSON(), true);

        $this->assertTrue($added['success']);
        $this->assertSame(['SA_RAJHI', 'Al Rajhi Bank'], [$this->treasuries->find($added['id'])->bank_code, $this->treasuries->find($added['id'])->bank_name]);
        $this->assertFalse($refused['success']);
        $this->assertSame(lang('Accounting.error_bank_directory_code_invalid', ['XX_NOPE']), $refused['message']);
    }

    public function testGetSearch_ShowsTheNameOfTheDirectoryBankInTheLanguageInUse(): void
    {
        $manager = $this->manager();
        $this->treasuries->create(['name' => 'Rajhi', 'kind' => 'bank', 'bank_code' => 'SA_RAJHI', 'account_number' => '1234567890'], $manager);

        $english = $this->rowsOf()['rows'][1];

        $this->accountingDb()->table('employees')->where('person_id', $manager)->update(['language_code' => 'ar-EG', 'language' => 'arabic-egypt']);

        $arabic = $this->rowsOf()['rows'][1];

        $this->assertStringContainsString('Al Rajhi Bank', $english['name']);
        $this->assertStringContainsString('1234567890', $english['name']);
        $this->assertStringContainsString('مصرف الراجحي', $arabic['name']);
        $this->assertStringNotContainsString('Al Rajhi Bank', $arabic['name']);
    }

    public function testGetView_PreselectsTheBankOfTheCodeEvenWhenTheNameKeptWithItWasChanged(): void
    {
        $manager = $this->manager();
        $bank    = $this->treasuries->create(['name' => 'Rajhi', 'kind' => 'bank', 'bank_code' => 'SA_RAJHI', 'account_number' => '1234567890'], $manager);

        $this->accountingDb()->table('treasuries')->where('treasury_id', $bank)->update(['bank_name' => 'Old spelling of the name']);

        $body = $this->get('/accounting_treasuries/view/' . $bank)->getBody();

        $this->assertMatchesRegularExpression('/<option value="SA_RAJHI"[^>]* selected>/', $body);
        $this->assertMatchesRegularExpression('/class="field bank-field bank-name-field" hidden/', $body);
    }

    public function testGetIndex_OffersTheLinkToTheBankReconciliationOnlyToWhoMayReconcile(): void
    {
        $this->actAsWith(['accounting_treasuries']);

        $this->get('/accounting_treasuries')->assertDontSee('bank_reconciliation_link');

        $this->actAsWith(['accounting_treasuries', AccountingGuard::BANK_RECONCILE]);

        $this->get('/accounting_treasuries')->assertSee('bank_reconciliation_link');
    }

    public function testGetStatement_FlagsTheLinesMatchedWithABankStatementToWhoMayReconcileOnly(): void
    {
        $manager = $this->manager();
        $bank    = $this->treasuries->create(['name' => 'Rajhi', 'kind' => 'bank', 'bank_code' => 'SA_RAJHI', 'account_number' => '1234567890'], $manager);

        $this->fund($bank, '100.00');

        $line = (int) $this->accountingDb()->table('accounting_journal_lines')->where('account_id', $this->treasuries->accountOf($bank))->get()->getRow()->line_id;
        $db   = $this->accountingDb();

        $db->table('bank_statements')->insert(['company_id' => 1, 'branch_id' => 1, 'treasury_id' => $bank, 'file_name' => 'x.csv', 'checksum' => str_repeat('a', 64), 'delimiter_name' => 'comma', 'row_count' => 1, 'line_count' => 1, 'date_from' => '2026-10-21', 'date_to' => '2026-10-21']);
        $statementId = (int) $db->insertID();
        $db->table('bank_statement_lines')->insert(['statement_id' => $statementId, 'company_id' => 1, 'branch_id' => 1, 'treasury_id' => $bank, 'row_no' => 2, 'line_date' => '2026-10-21', 'amount' => '100.0000', 'dedupe_key' => str_repeat('b', 64)]);
        $db->table('bank_matches')->insert(['company_id' => 1, 'branch_id' => 1, 'treasury_id' => $bank, 'statement_line_id' => (int) $db->insertID(), 'amount' => '100.0000']);
        $db->table('bank_match_lines')->insert(['match_id' => (int) $db->insertID(), 'journal_line_id' => $line, 'amount' => '100.0000']);

        $this->actAsWith(['accounting_treasuries']);

        $this->get('/accounting_treasuries/statement/' . $bank)->assertDontSee('bank-matched');

        $this->actAsWith(['accounting_treasuries', AccountingGuard::BANK_RECONCILE]);

        $this->get('/accounting_treasuries/statement/' . $bank)->assertSee('bank-matched');
    }
}
