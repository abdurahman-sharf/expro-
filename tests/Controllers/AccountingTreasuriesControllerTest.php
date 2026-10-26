<?php

namespace Tests\Controllers;

use App\Libraries\Accounting\AccountingGuard;
use App\Libraries\Accounting\JournalDraft;
use App\Libraries\Accounting\JournalLineDraft;
use App\Libraries\Accounting\PostingService;
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

        $this->treasuries->create(['name' => 'Bank Alpha', 'kind' => 'bank'], $manager);
        $this->treasuries->create(['name' => 'Elsewhere', 'kind' => 'cash', 'branch_id' => $other], $manager);

        $this->assertSame(['Bank Alpha'], array_map(static fn (array $row): string => strip_tags($row['name']), $this->rowsOf('?search=alpha')['rows']));

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

        $again = json_decode($this->post('/accounting_treasuries/save', ['name' => 'Drawer', 'kind' => 'bank'])->getJSON(), true);

        $this->assertFalse($again['success']);
        $this->assertSame(lang('Accounting.error_treasury_name_taken', ['Drawer']), $again['message']);
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
}
