<?php

namespace Tests\Controllers;

use App\Libraries\Accounting\AccountingGuard;
use App\Libraries\Accounting\InterBranchSettlements;
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
use CodeIgniter\Test\TestResponse;
use Config\Database;
use Tests\Support\AccountingFixtureTrait;
use Tests\Support\EmployeeFixtureTrait;
use Tests\Support\ItemFixtureTrait;
use Tests\Support\PurchaseAccountingFixtureTrait;
use Tests\Support\SaleAccountingFixtureTrait;

/**
 * The screen of the balances between branches: who may open it and what it shows each employee, the
 * settlement of two branches from it, and the taking back of a settlement.
 */
class AccountingInterBranchControllerTest extends CIUnitTestCase
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
    private TreasuryTransfers $transfers;
    private int $manager;
    private int $main;
    private int $other;
    private int $branch;

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
        $this->transfers  = new TreasuryTransfers();
        $this->manager    = $this->employeeWithGrants([AccountingGuard::TREASURIES_MANAGE]);
        $this->branch     = $this->createBranch('B2');
        $this->main       = $this->treasuries->ensureMain();
        $this->other      = $this->treasuries->create(['name' => 'Second branch drawer', 'kind' => 'cash', 'branch_id' => $this->branch], $this->manager);

        foreach ([[$this->main, 1, 9001], [$this->other, $this->branch, 9002]] as [$treasury, $branchId, $number]) {
            (new PostingService())->post(new JournalDraft(
                date: '2026-10-20',
                lines: [new JournalLineDraft((int) $this->treasuries->accountOf($treasury), debit: '1000.00'), new JournalLineDraft($this->accountId('opening_balance'), credit: '1000.00')],
                sourceType: 'opening',
                sourceId: $number,
                currencyCode: 'SAR',
                branchId: $branchId,
            ));
        }
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

    private function settler(?array $branchIds = null): int
    {
        return $this->actAsWith(['accounting_treasuries', AccountingGuard::INTERBRANCH_SETTLE], $branchIds);
    }

    private function send(bool $fromFirst, string $amount, string $date = '2026-10-21'): void
    {
        $this->transfers->transfer($fromFirst ? $this->main : $this->other, $fromFirst ? $this->other : $this->main, $amount, $date, '', $this->manager);
    }

    /**
     * @return array<string, mixed>
     */
    private function settlement(string $kind = 'cash'): array
    {
        return ['branch' => '1', 'other' => (string) $this->branch, 'date' => '2026-10-31', 'from_treasury_id' => (string) $this->other, 'to_treasury_id' => (string) $this->main, 'note' => 'Month end', 'request_key' => 'key-' . uniqid()];
    }

    private function settleDirectly(): int
    {
        return (new InterBranchSettlements())->settle(1, $this->branch, '2026-10-31', $this->other, $this->main, '', null, $this->employeeWithGrants([AccountingGuard::INTERBRANCH_SETTLE]));
    }

    /**
     * @return array<string, mixed>
     */
    private function getJsonOf(string $url): array
    {
        return $this->jsonOf($this->get($url));
    }

    public function testGetIndex_ShowsThePairsToWhoeverHoldsTheModuleWithoutAButtonToSettle(): void
    {
        $this->send(true, '100.00');
        $this->actAsWith(['accounting_treasuries']);

        $response = $this->get('/accounting_treasuries/inter_branch');

        $response->assertStatus(200);
        $this->assertStringContainsString('id="pair_balances"', $response->getBody());
        $response->assertSee('100.00');
        $response->assertSee(lang('Accounting_treasuries.pair_basis_derived'));
        $response->assertSee(lang('Accounting_treasuries.pair_agrees_yes'));
        $this->assertStringNotContainsString('inter_branch/settle_form?branch=', $response->getBody());
    }

    public function testGetIndex_OffersTheButtonToSettleToWhoMaySettleAndWorksInBothBranches(): void
    {
        $this->send(true, '100.00');
        $this->settler();

        $response = $this->get('/accounting_treasuries/inter_branch');

        $this->assertStringContainsString('accounting_treasuries/inter_branch/settle_form?branch=1', $response->getBody());
        $this->assertSame(1, substr_count($response->getBody(), 'inter_branch/settle_form?branch='), 'One button for a pair, not one for each of its two sides');
    }

    public function testGetIndex_OffersNoButtonToSettleAPairThatIsClear(): void
    {
        $this->send(true, '100.00');
        (new InterBranchSettlements())->settle(1, $this->branch, '2026-10-22', $this->other, $this->main, '', null, $this->employeeWithGrants([AccountingGuard::INTERBRANCH_SETTLE]));
        $this->settler();

        $response = $this->get('/accounting_treasuries/inter_branch?as_of=2026-10-25');

        $this->assertStringContainsString('id="pair_balances"', $response->getBody());
        $this->assertStringContainsString('0.00', $response->getBody());
        $this->assertStringNotContainsString('inter_branch/settle_form?branch=', $response->getBody());
    }

    public function testGetIndex_OffersNoButtonToSettleWhenTheEmployeeWorksInOnlyOneOfTheBranches(): void
    {
        $this->send(true, '100.00');
        $this->settler([1]);

        $response = $this->get('/accounting_treasuries/inter_branch');

        $this->assertStringContainsString('id="pair_balances"', $response->getBody());
        $this->assertStringNotContainsString('inter_branch/settle_form?branch=', $response->getBody());
    }

    public function testGetIndex_ShowsOnlyThePairsOfTheBranchesTheEmployeeWorksInAndSaysTheyArePartial(): void
    {
        $third = $this->createBranch('B3');

        $this->send(true, '100.00');
        $this->actAsWith(['accounting_treasuries'], [$this->branch, $third]);

        $response = $this->get('/accounting_treasuries/inter_branch');
        $body     = $response->getBody();

        $this->assertSame(1, substr_count($body, '<th scope="row"><bdi dir="ltr">B2</bdi></th>'), 'The row of the branch the employee works in');
        $this->assertSame(0, substr_count($body, '<th scope="row"><bdi dir="ltr">HQ</bdi></th>'), 'Not the row of the branch they do not');
        $this->assertStringContainsString('id="pairs_scope"', $response->getBody());
        $this->assertStringNotContainsString('id="pairs_difference"', $response->getBody());
    }

    public function testGetIndex_ShowsTheDifferenceAcrossTheCompanyToWhoReadsEveryBranchOnly(): void
    {
        $this->createBranch('B3');

        (new PostingService())->post(new JournalDraft(
            date: '2026-10-21',
            lines: [new JournalLineDraft($this->accountId('branches_receivable'), debit: '25.00'), new JournalLineDraft($this->accountId('opening_balance'), credit: '25.00')],
            sourceType: JournalDraft::SOURCE_MANUAL,
        ));

        $this->actAsWith(['accounting_treasuries']);
        $this->assertStringContainsString('id="pairs_difference"', $this->get('/accounting_treasuries/inter_branch')->getBody());

        $this->actAsWith(['accounting_treasuries'], [1, $this->branch]);

        $response = $this->get('/accounting_treasuries/inter_branch');

        $this->assertStringNotContainsString('id="pairs_difference"', $response->getBody());
        $this->assertStringContainsString('id="pairs_unattributed"', $response->getBody());
    }

    public function testGetIndex_StopsAtTheDayAsked(): void
    {
        $this->send(true, '100.00', '2026-10-21');
        $this->actAsWith(['accounting_treasuries']);

        $this->assertStringContainsString('id="pairs_none"', $this->get('/accounting_treasuries/inter_branch?as_of=2026-10-20')->getBody());
        $this->assertStringContainsString('id="pair_balances"', $this->get('/accounting_treasuries/inter_branch?as_of=2026-10-21')->getBody());
    }

    public function testGetIndex_SaysSoWhenTheDayAskedIsNotOne(): void
    {
        $this->actAsWith(['accounting_treasuries']);

        $this->assertStringContainsString('id="pairs_error"', $this->get('/accounting_treasuries/inter_branch?as_of=2026-02-30')->getBody());
    }

    public function testGetIndex_RedirectsAUserWithoutTheModuleAndIsNotFoundWhileAccountingIsOff(): void
    {
        $this->actAsWith(['accounting_payables']);
        $this->get('/accounting_treasuries/inter_branch')->assertRedirect();

        $this->actAsWith(['accounting_treasuries']);
        $this->setConfigValue('accounting_enabled', '0');

        $this->expectException(PageNotFoundException::class);

        $this->get('/accounting_treasuries/inter_branch');
    }

    public function testGetPairs_ReturnsTheTableAloneForTheScreenToReload(): void
    {
        $this->send(true, '100.00');
        $this->actAsWith(['accounting_treasuries']);

        $response = $this->get('/accounting_treasuries/inter_branch/pairs');

        $this->assertStringContainsString('id="pair_balances"', $response->getBody());
        $this->assertStringNotContainsString('id="pair_filters"', $response->getBody(), 'Only the figures, not the screen around them');
        $this->assertStringNotContainsString('id="table_holder"', $response->getBody());
    }

    public function testGetSettleForm_ShowsWhatEachBranchIsOwedAndTheTreasuriesThePayerMayUse(): void
    {
        $this->send(true, '100.00');
        $this->send(false, '30.00', '2026-10-22');
        $this->settler();

        $response = $this->get('/accounting_treasuries/inter_branch/settle_form?branch=1&other=' . $this->branch . '&date=2026-10-31');

        $response->assertStatus(200);
        $this->assertStringContainsString('id="settle_cash"', $response->getBody());
        $response->assertSee('70.00');
        $this->assertStringContainsString('name="from_treasury_id"', $response->getBody());
        $this->assertStringContainsString('name="request_key"', $response->getBody());
        $this->assertSame(1, preg_match('/<select name="from_treasury_id".*?<\/select>/s', $response->getBody(), $from));
        $this->assertStringContainsString('<option value="' . $this->other . '">Second branch drawer</option>', $from[0]);
        $this->assertStringNotContainsString('<option value="' . $this->main . '">', $from[0], 'The treasury of the branch that is paid is not offered to pay from');
    }

    public function testGetSettleForm_OffersNoTreasuryToABranchSettlementThatOffsets(): void
    {
        $this->send(true, '100.00');
        $this->send(false, '100.00', '2026-10-22');
        $this->settler();

        $response = $this->get('/accounting_treasuries/inter_branch/settle_form?branch=1&other=' . $this->branch . '&date=2026-10-31');

        $this->assertStringContainsString('id="settle_netting"', $response->getBody());
        $this->assertStringNotContainsString('name="from_treasury_id"', $response->getBody());
    }

    public function testGetSettleForm_OffersOnlyTheTreasuriesTheEmployeeMayMoveMoneyIn(): void
    {
        $this->send(true, '100.00');
        $this->treasuries->update($this->other, ['name' => 'Second branch drawer', 'kind' => 'cash', 'withdraw_scope' => 'staff', 'withdraw_members' => [$this->employeeWithGrants([])]], $this->manager);
        $this->settler();

        $response = $this->get('/accounting_treasuries/inter_branch/settle_form?branch=1&other=' . $this->branch . '&date=2026-10-31');

        $this->assertDoesNotMatchRegularExpression('/<option value="' . $this->other . '">/', $response->getBody());
    }

    public function testGetSettleForm_SaysWhenThePairDoesNotAgreeAndWhenNothingIsOwed(): void
    {
        $this->settler();

        $this->get('/accounting_treasuries/inter_branch/settle_form?branch=1&other=' . $this->branch . '&date=2026-10-31')->assertSee('id="settle_nothing"');

        (new PostingService())->post(new JournalDraft(
            date: '2026-10-21',
            lines: [new JournalLineDraft($this->accountId('branches_receivable'), debit: '25.00'), new JournalLineDraft($this->accountId('opening_balance'), credit: '25.00')],
            sourceType: 'interbranch_test',
            sourceId: 1,
            branchId: 1,
        ));
        $this->accountingDb()->table('accounting_interbranch_legs')->insert([
            'journal_id'            => (int) $this->accountingDb()->table('accounting_journals')->where('source_type', 'interbranch_test')->get()->getRow()->journal_id,
            'account_id'            => $this->accountId('branches_receivable'),
            'company_id'            => 1,
            'side'                  => 'receivable',
            'counterpart_branch_id' => $this->branch,
            'document_type'         => 'interbranch_test',
            'document_id'           => 1,
            'revision'              => 1,
        ]);

        $this->get('/accounting_treasuries/inter_branch/settle_form?branch=1&other=' . $this->branch . '&date=2026-10-31')->assertSee('id="settle_not_agreed"');
    }

    public function testGetIndex_OffersTheDayAskedToSettleAndTheFormAndThePostRefuseADayBeforeASettlementThatStands(): void
    {
        $this->send(true, '100.00', '2026-10-21');
        $this->settleDirectly();
        $this->settler();

        $page = $this->get('/accounting_treasuries/inter_branch?as_of=2026-10-25');

        $this->assertSame(1, preg_match('/data-href="([^"]*settle_form[^"]*)"/', $page->getBody(), $found), 'The pair is owed 100 on that day, so it can be asked to settle');

        $url = html_entity_decode($found[1]);

        $this->assertStringContainsString('date=2026-10-25', $url);

        $form = $this->get(substr($url, strlen(base_url())));

        $form->assertStatus(200);
        $form->assertSee('id="settle_older"');
        $form->assertSee(lang('Accounting.error_settlement_older_than_settled', ['2026-10-31']));
        $this->assertStringNotContainsString('name="from_treasury_id"', $form->getBody());

        $result = $this->jsonOf($this->post('/accounting_treasuries/inter_branch/settle', ['date' => '2026-10-25'] + $this->settlement()));

        $this->assertFalse($result['success']);
        $this->assertSame(lang('Accounting.error_settlement_older_than_settled', ['2026-10-31']), $result['message']);
        $this->assertSame(1, $this->countRows('interbranch_settlements'));
        $this->assertSame('1000.0000', $this->treasuries->position($this->other)->balance);
        $this->assertSame('1000.0000', $this->treasuries->position($this->main)->balance);
    }

    public function testGetSettleForm_IsNotFoundToWhoMayNotSettleOrWorksInOnlyOneOfTheBranches(): void
    {
        $this->send(true, '100.00');

        foreach ([fn () => $this->actAsWith(['accounting_treasuries']), fn () => $this->settler([1]), fn () => $this->settler([$this->branch])] as $actAs) {
            $actAs();

            try {
                $this->get('/accounting_treasuries/inter_branch/settle_form?branch=1&other=' . $this->branch . '&date=2026-10-31');
                $this->fail('The form was shown');
            } catch (PageNotFoundException) {
                $this->addToAssertionCount(1);
            }
        }
    }

    public function testGetSettleForm_IsNotFoundForTheSameBranchTwiceAnAbsentBranchAndADateThatIsNotOne(): void
    {
        $this->send(true, '100.00');
        $this->settler();

        foreach (['branch=1&other=1&date=2026-10-31', 'branch=1&other=99999&date=2026-10-31', 'branch=1&other=' . $this->branch . '&date=not-a-date'] as $query) {
            try {
                $this->get('/accounting_treasuries/inter_branch/settle_form?' . $query);
                $this->fail('The form was shown for ' . $query);
            } catch (PageNotFoundException) {
                $this->addToAssertionCount(1);
            }
        }
    }

    public function testPostSettle_SettlesTheBranchesAndAnswersWithTheSettlement(): void
    {
        $this->send(true, '100.00');
        $employee = $this->settler();

        $result = $this->jsonOf($this->post('/accounting_treasuries/inter_branch/settle', $this->settlement()));

        $this->assertTrue($result['success']);
        $this->assertSame(lang('Accounting_treasuries.settle_done'), $result['message']);
        $this->assertSame('cash', (new InterBranchSettlements())->find($result['id'])->kind);
        $this->assertSame($employee, (int) (new InterBranchSettlements())->find($result['id'])->created_by);
        $this->assertSame('1000.0000', $this->treasuries->position($this->other)->balance);
    }

    public function testPostSettle_AnswersTheSameSettlementWhenTheSameFormIsSentTwice(): void
    {
        $this->send(true, '100.00');
        $this->settler();

        $form  = $this->settlement();
        $first = $this->jsonOf($this->post('/accounting_treasuries/inter_branch/settle', $form));
        $again = $this->jsonOf($this->post('/accounting_treasuries/inter_branch/settle', $form));

        $this->assertTrue($again['success']);
        $this->assertSame($first['id'], $again['id']);
        $this->assertSame(1, $this->countRows('interbranch_settlements'));
    }

    public function testPostSettle_AnswersWhyItWasRefusedAndSettlesNothing(): void
    {
        $this->send(true, '100.00');
        $this->closeThrough('2026-10-31', $this->branch);
        $this->settler();

        $result = $this->jsonOf($this->post('/accounting_treasuries/inter_branch/settle', $this->settlement()));

        $this->assertFalse($result['success']);
        $this->assertNotSame('', $result['message']);
        $this->assertSame(0, $this->countRows('interbranch_settlements'));
    }

    public function testPostSettle_RejectsAnEmployeeWithoutThePermissionAndOneWhoWorksInOnlyOneBranch(): void
    {
        $this->send(true, '100.00');

        foreach ([fn () => $this->actAsWith(['accounting_treasuries']), fn () => $this->settler([1])] as $actAs) {
            $actAs();

            $this->assertFalse($this->jsonOf($this->post('/accounting_treasuries/inter_branch/settle', $this->settlement()))['success']);
        }

        $this->assertSame(0, $this->countRows('interbranch_settlements'));
    }

    public function testGetSettlementsSearch_ListsToTheEmployeesOfEitherBranchAndNoOneElse(): void
    {
        $third = $this->createBranch('B3');

        $this->send(true, '100.00');

        $id = $this->settleDirectly();

        foreach ([[1], [$this->branch], null] as $works) {
            $this->settler($works);

            $rows = $this->getJsonOf('/accounting_treasuries/inter_branch/settlements/search')['rows'];

            $this->assertSame([$id], array_column($rows, 'settlement_id'));
        }

        $this->settler([$third]);

        $this->assertSame(0, $this->getJsonOf('/accounting_treasuries/inter_branch/settlements/search')['total']);
    }

    public function testGetSettlementsSearch_PagesTheListAndKeepsThePageBetweenOneAndFiveHundred(): void
    {
        $rows = [];

        for ($number = 0; $number < 501; $number++) {
            $rows[] = ['company_id' => 1, 'branch_id' => 1, 'counterpart_branch_id' => $this->branch, 'kind' => 'netting', 'settle_date' => '2026-10-31'];
        }

        $this->accountingDb()->table('interbranch_settlements')->insertBatch($rows);
        $this->settler();

        try {
            $this->assertCount(25, $this->getJsonOf('/accounting_treasuries/inter_branch/settlements/search')['rows']);
            $this->assertCount(500, $this->getJsonOf('/accounting_treasuries/inter_branch/settlements/search?limit=100000')['rows']);
            $this->assertCount(1, $this->getJsonOf('/accounting_treasuries/inter_branch/settlements/search?limit=-5')['rows']);
            $this->assertCount(1, $this->getJsonOf('/accounting_treasuries/inter_branch/settlements/search?limit=1&offset=-5')['rows']);
            $this->assertCount(1, $this->getJsonOf('/accounting_treasuries/inter_branch/settlements/search?limit=10&offset=500')['rows']);
            $this->assertSame(501, $this->getJsonOf('/accounting_treasuries/inter_branch/settlements/search?limit=1')['total']);
        } finally {
            $this->accountingDb()->table('interbranch_settlements')->truncate();
        }
    }

    public function testGetSettlementsRow_ShowsTheCancelIconOnlyToWhoMaySettleAndWorksInBothBranches(): void
    {
        $this->send(true, '100.00');

        $id = $this->settleDirectly();

        $this->settler();
        $this->assertStringContainsString('inter_branch/cancel_form/' . $id, $this->getJsonOf('/accounting_treasuries/inter_branch/settlements/row/' . $id)['edit']);

        $this->settler([1]);
        $this->assertSame('', $this->getJsonOf('/accounting_treasuries/inter_branch/settlements/row/' . $id)['edit'], 'One branch only sees the settlement');

        $this->actAsWith(['accounting_treasuries']);
        $this->assertSame('', $this->getJsonOf('/accounting_treasuries/inter_branch/settlements/row/' . $id)['edit']);
    }

    public function testGetSettlementsRow_ShowsTheTreasuriesWithAnArrowThatTurnsInArabicAndEscapesTheirNames(): void
    {
        $this->send(true, '100.00');
        $this->treasuries->update($this->other, ['name' => '<i>Second</i> drawer', 'kind' => 'cash'], $this->manager);

        $id = $this->settleDirectly();

        $this->settler();

        $cell = $this->getJsonOf('/accounting_treasuries/inter_branch/settlements/row/' . $id)['treasuries'];

        $this->assertSame('<bdi>&lt;i&gt;Second&lt;/i&gt; drawer</bdi> <span class="bi bi-arrow-right" aria-hidden="true"></span> <bdi>' . esc($this->treasuries->find($this->main)->name) . '</bdi>', $cell);
        $this->assertStringNotContainsString('→', $cell);
        $this->assertSame($cell, $this->getJsonOf('/accounting_treasuries/inter_branch/settlements/search')['rows'][0]['treasuries']);
    }

    public function testGetSettlementsRow_ShowsNoCancelIconForASettlementThatIsCancelled(): void
    {
        $this->send(true, '100.00');

        $id = $this->settleDirectly();

        $this->settler();
        $this->jsonOf($this->post('/accounting_treasuries/inter_branch/cancel/' . $id, ['reason' => 'Mistake']));

        $row = $this->getJsonOf('/accounting_treasuries/inter_branch/settlements/row/' . $id);

        $this->assertSame('', $row['edit']);
        $this->assertStringContainsString(lang('Accounting_treasuries.status_cancelled'), $row['status']);
    }

    public function testGetSettlementsRow_IsNotFoundToAnEmployeeOfNeitherBranch(): void
    {
        $this->send(true, '100.00');

        $id    = $this->settleDirectly();
        $third = $this->createBranch('B3');

        $this->settler([$third]);

        $this->expectException(PageNotFoundException::class);

        $this->get('/accounting_treasuries/inter_branch/settlements/row/' . $id);
    }

    public function testGetCancelForm_IsNotFoundToWhoMayNotSettleOrWorksInOnlyOneOfTheBranches(): void
    {
        $this->send(true, '100.00');

        $id = $this->settleDirectly();

        foreach ([fn () => $this->actAsWith(['accounting_treasuries']), fn () => $this->settler([1])] as $actAs) {
            $actAs();

            try {
                $this->get('/accounting_treasuries/inter_branch/cancel_form/' . $id);
                $this->fail('The form was shown');
            } catch (PageNotFoundException) {
                $this->addToAssertionCount(1);
            }
        }

        $this->settler();
        $this->get('/accounting_treasuries/inter_branch/cancel_form/' . $id)->assertStatus(200);
    }

    public function testPostCancel_TakesTheSettlementBackAndAnswersWhyWhenItCannot(): void
    {
        $this->send(true, '100.00');

        $id = $this->settleDirectly();

        $this->settler([1]);
        $this->assertFalse($this->jsonOf($this->post('/accounting_treasuries/inter_branch/cancel/' . $id, ['reason' => 'Mistake']))['success']);

        $this->settler();

        $this->assertFalse($this->jsonOf($this->post('/accounting_treasuries/inter_branch/cancel/' . $id, ['reason' => '']))['success']);

        $result = $this->jsonOf($this->post('/accounting_treasuries/inter_branch/cancel/' . $id, ['reason' => 'Mistake']));

        $this->assertTrue($result['success']);
        $this->assertSame('cancelled', (new InterBranchSettlements())->find($id)->status);
        $this->assertSame('900.0000', $this->treasuries->position($this->main)->balance);
    }

    public function testTransfers_LinksToTheBalancesBetweenBranches(): void
    {
        $this->actAsWith(['accounting_treasuries']);

        $this->get('/accounting_treasuries/transfers')->assertSee('accounting_treasuries/inter_branch');
    }

    /**
     * @return array<string, mixed>
     */
    private function jsonOf(TestResponse $response): array
    {
        return json_decode($response->getJSON(), true);
    }
}
