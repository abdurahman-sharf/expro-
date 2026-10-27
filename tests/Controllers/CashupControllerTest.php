<?php

namespace Tests\Controllers;

use App\Libraries\Accounting\TreasuryScopes;
use App\Libraries\Accounting\TreasuryService;
use App\Models\Cashup;
use CodeIgniter\Config\Factories;
use CodeIgniter\Database\Config;
use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\DatabaseTestTrait;
use CodeIgniter\Test\FeatureTestTrait;
use Config\OSPOS;
use Tests\Support\AccountingFixtureTrait;
use Tests\Support\EmployeeFixtureTrait;
use Tests\Support\ExpenseAccountingFixtureTrait;
use Tests\Support\ItemFixtureTrait;
use Tests\Support\PurchaseAccountingFixtureTrait;
use Tests\Support\ReportBranchFixtureTrait;
use Tests\Support\SaleAccountingFixtureTrait;

class CashupControllerTest extends CIUnitTestCase
{
    use DatabaseTestTrait;
    use FeatureTestTrait;
    use EmployeeFixtureTrait;
    use AccountingFixtureTrait;
    use ExpenseAccountingFixtureTrait;
    use ItemFixtureTrait;
    use SaleAccountingFixtureTrait;
    use PurchaseAccountingFixtureTrait;
    use ReportBranchFixtureTrait;

    protected $migrate     = true;
    protected $migrateOnce = true;
    protected $seedOnce    = true;
    protected $refresh     = false;
    protected $namespace   = null;

    private static bool $doneBootstrap = false;

    protected function setUp(): void
    {
        if (self::$doneBootstrap === false) {
            Config::seeder($this->DBGroup)->call('App\Database\Seeds\TestDatabaseBootstrapSeeder');
            Config::connect($this->DBGroup)->close();

            self::$doneBootstrap = true;
        }

        parent::setUp();

        $ospos = new OSPOS();
        $ospos->settings = [
            'company'             => 'Test Co',
            'dateformat'          => 'Y-m-d',
            'timeformat'          => 'H:i:s',
            'number_locale'       => 'en_US',
            'currency_decimals'   => 2,
            'thousands_separator' => ',',
            'currency_symbol'     => '$',
        ];
        Factories::injectMock('config', OSPOS::class, $ospos);
    }

    protected function tearDown(): void
    {
        $this->stopExpenses();
        Factories::reset();
        parent::tearDown();
    }

    protected function createCashupEmployee(): int
    {
        return $this->createEmployee(
            first_name: 'Cashier',
            last_name:  'Cashup',
            email:      'cashup.' . uniqid() . '@test.com',
            username:   'cashup_' . uniqid(),
            grants: [
                ['permission_id' => 'cashups', 'menu_group' => 'home'],
            ],
        );
    }

    protected function loginAs(int $personId): void
    {
        $this->withSession([
            'person_id'  => $personId,
            'menu_group' => 'home',
        ]);
    }

    public function testTamperedTotalIsRecomputedServerSide(): void
    {
        $cashierId = $this->createCashupEmployee();
        $this->loginAs($cashierId);

        // Component amounts only sum to (50 - 0 - 0 + 10 + 0 + 0) = 60, but the
        // client claims a total of 1000.00. The server must store 60, not 1000.
        $response = $this->post('/cashups/save/-1', [
            'open_date'            => '2026-09-05 08:00:00',
            'close_date'           => '2026-09-05 16:00:00',
            'open_amount_cash'     => '0',
            'transfer_amount_cash' => '0',
            'closed_amount_cash'   => '50',
            'closed_amount_due'    => '10',
            'closed_amount_card'   => '0',
            'closed_amount_check'  => '0',
            'closed_amount_total'  => '1000.00',
            'open_employee_id'     => '999999',
            'close_employee_id'    => '999999',
            'description'          => 'Test cashup - tampered total',
        ]);

        $response->assertStatus(200);
        $result = json_decode($response->getJSON(), true);
        $this->assertTrue($result['success']);

        $cashup = model(Cashup::class)->get_info($result['id']);
        $this->assertEquals(60.0, (float) $cashup->closed_amount_total);
    }

    public function testConsistentTotalIsStoredAndOwnerIsForcedToAuthenticatedUser(): void
    {
        $cashierId = $this->createCashupEmployee();
        $this->loginAs($cashierId);

        // 100 - 20 - 5 + 15 + 40 + 25 = 155. The client also tries to attribute
        // the cashup to other employees (777/888); the server must force the
        // authenticated user's person_id.
        $response = $this->post('/cashups/save/-1', [
            'open_date'            => '2026-09-05 08:00:00',
            'close_date'           => '2026-09-05 16:00:00',
            'open_amount_cash'     => '20',
            'transfer_amount_cash' => '5',
            'closed_amount_cash'   => '100',
            'closed_amount_due'    => '15',
            'closed_amount_card'   => '40',
            'closed_amount_check'  => '25',
            'closed_amount_total'  => '155',
            'open_employee_id'     => '777',
            'close_employee_id'    => '888',
            'description'          => 'Test cashup - consistent total',
        ]);

        $response->assertStatus(200);
        $result = json_decode($response->getJSON(), true);
        $this->assertTrue($result['success']);

        $cashup = model(Cashup::class)->get_info($result['id']);
        $this->assertEquals(155.0, (float) $cashup->closed_amount_total);
        $this->assertEquals($cashierId, (int) $cashup->open_employee_id);
        $this->assertEquals($cashierId, (int) $cashup->close_employee_id);
    }

    /**
     * @param array<string, string> $overrides
     *
     * @return array<string, string>
     */
    private function closingForm(string $counted, array $overrides = []): array
    {
        return array_merge([
            'open_date'            => '2026-10-20 08:00:00',
            'close_date'           => '2026-10-20 18:00:00',
            'open_amount_cash'     => '100',
            'transfer_amount_cash' => '0',
            'closed_amount_cash'   => $counted,
            'closed_amount_due'    => '0',
            'closed_amount_card'   => '0',
            'closed_amount_check'  => '0',
            'description'          => 'Closing through the screen',
        ], $overrides);
    }

    private function accountingCashier(?array $branchIds = null): int
    {
        $this->startExpenses();

        $cashierId = $this->employeeWithGrants(['cashups'], $branchIds);

        $this->loginAs($cashierId);

        return $cashierId;
    }

    /**
     * @return array<string, mixed>
     */
    private function saveClosing(array $form, int $cashupId = NEW_ENTRY): array
    {
        return json_decode($this->post('/cashups/save/' . $cashupId, $form)->getJSON(), true);
    }

    public function testPostSave_PostsTheCashDifferenceOfTheCountWhenAccountingIsOn(): void
    {
        $this->accountingCashier();
        $this->sellFor('250.00');
        $this->sellFor('150.00');

        $result = $this->saveClosing($this->closingForm('485.50'));

        $this->assertTrue($result['success']);
        $this->assertSame(
            [$this->accountId('cash_difference') . '=14.50/0.00', $this->accountId('cash') . '=0.00/14.50'],
            $this->postedLines('cashup', (int) $result['id']),
        );
        $this->assertSame('-14.5000', $this->accountingDb()->table('cash_up')->where('cashup_id', $result['id'])->get()->getRow()->cash_difference);
    }

    public function testPostSave_PostsNothingWhenTheCountIsWhatWasExpected(): void
    {
        $this->accountingCashier();
        $this->sellFor('400.00');

        $result = $this->saveClosing($this->closingForm('500.00'));

        $this->assertTrue($result['success']);
        $this->assertSame(0, $this->countRows('accounting_journals'));
    }

    public function testPostSave_SaysInWordsThatTheDateIsInAClosedPeriod(): void
    {
        $this->accountingCashier();
        $this->sellFor('400.00');
        $this->closeThrough('2026-10-31');

        $result = $this->saveClosing($this->closingForm('450.00'));

        $this->assertFalse($result['success']);
        $this->assertStringContainsString('2026-10-31', $result['message']);
        $this->assertSame([0, 0], [$this->countRows('cash_up'), $this->countRows('accounting_journals')]);
    }

    public function testPostSave_SaysInWordsThatTheEmployeeMayNotWithdrawFromTheirTreasury(): void
    {
        $cashierId = $this->accountingCashier();
        $this->sellFor('400.00');

        $drawer = $this->treasury('Own drawer');

        (new TreasuryService())->setDefaultFor($cashierId, $drawer);
        $this->restrictTreasury($drawer, TreasuryScopes::WITHDRAW, [$this->clerkId]);

        $result = $this->saveClosing($this->closingForm('450.00'));

        $this->assertFalse($result['success']);
        $this->assertStringContainsString(explode('{0}', lang('Accounting.error_treasury_withdraw_denied'))[0], $result['message']);
        $this->assertSame(0, $this->countRows('cash_up'));
    }

    public function testPostSave_ForcesTheEmployeesToTheLoggedInOneAndUsesThemToDecideWhatIsAllowed(): void
    {
        $limited = $this->accountingCashier([1]);
        $second  = $this->createBranch('B2');
        $this->sellFor('400.00', 'Cash', '2026-10-20 12:00:00', $second);

        $result = $this->saveClosing($this->closingForm('450.00', ['open_employee_id' => (string) $this->clerkId, 'close_employee_id' => (string) $this->clerkId]));

        $cashup = model(Cashup::class)->get_info($result['id']);

        $this->assertTrue($result['success']);
        $this->assertSame([$limited, $limited], [(int) $cashup->open_employee_id, (int) $cashup->close_employee_id]);
        $this->assertSame(1, (int) $this->accountingDb()->table('cash_up')->where('cashup_id', $result['id'])->get()->getRow()->branch_id);
        $this->assertSame('100.0000', $this->accountingDb()->table('cash_up')->where('cashup_id', $result['id'])->get()->getRow()->expected_amount_cash);
    }

    public function testPostSave_ChangesNothingInTheBooksWhileAccountingIsOff(): void
    {
        $this->startExpenses();
        $this->setConfigValue('accounting_enabled', '0');
        $this->sellFor('400.00');
        $this->loginAs($this->employeeWithGrants(['cashups']));

        $result = $this->saveClosing($this->closingForm('450.00'));
        $row    = $this->accountingDb()->table('cash_up')->where('cashup_id', $result['id'])->get()->getRow();

        $this->assertTrue($result['success']);
        $this->assertSame(0, $this->countRows('accounting_journals'));
        $this->assertSame([null, null, null], [$row->branch_id, $row->expected_amount_cash, $row->cash_difference]);
    }

    public function testPostSave_ReversesAndPostsAgainWhenTheCountIsEdited(): void
    {
        $this->accountingCashier();
        $this->sellFor('400.00');

        $created = $this->saveClosing($this->closingForm('485.50'));
        $edited  = $this->saveClosing($this->closingForm('490.00'), (int) $created['id']);

        $this->assertTrue($edited['success']);
        $this->assertSame(['reversed', 'posted'], array_column($this->journalsOfSource('cashup', (int) $created['id']), 'status'));
    }

    public function testPostDelete_ReversesTheJournalOfTheCashUps(): void
    {
        $this->accountingCashier();
        $this->sellFor('400.00');

        $created  = $this->saveClosing($this->closingForm('485.50'));
        $response = json_decode($this->post('/cashups/delete', ['ids' => [(string) $created['id']]])->getJSON(), true);

        $this->assertTrue($response['success']);
        $this->assertSame('0.00', $this->balanceOf($this->accountId('cash_difference')));
    }

    public function testPostSave_SaysInWordsThatTheCashUpIsOfABranchTheEmployeeDoesNotWorkIn(): void
    {
        $this->accountingCashier();

        $second = $this->createBranch('B2');
        $remote = $this->employeeWithGrants(['cashups'], [$second]);

        $this->loginAs($remote);

        $created = $this->saveClosing($this->closingForm('90'));

        $this->assertTrue($created['success']);

        $this->loginAs($this->employeeWithGrants(['cashups'], [1]));

        $result = $this->saveClosing($this->closingForm('60'), (int) $created['id']);

        $this->assertFalse($result['success']);
        $this->assertNotSame(lang('Cashups.error_adding_updating'), $result['message']);
        $this->assertSame('90.00', $this->accountingDb()->table('cash_up')->where('cashup_id', $created['id'])->get()->getRow()->closed_amount_cash);
    }

    public function testPostDelete_SaysInWordsThatTheEmployeeMayNotDepositBackIntoTheTreasury(): void
    {
        $cashierId = $this->accountingCashier();
        $this->sellFor('400.00');

        $drawer = $this->treasury('Own drawer');

        (new TreasuryService())->setDefaultFor($cashierId, $drawer);

        $created = $this->saveClosing($this->closingForm('485.50'));

        $this->restrictTreasury($drawer, TreasuryScopes::DEPOSIT, [$this->clerkId]);

        $response = json_decode($this->post('/cashups/delete', ['ids' => [(string) $created['id']]])->getJSON(), true);

        $this->assertFalse($response['success']);
        $this->assertStringContainsString(explode('{0}', lang('Accounting.error_treasury_deposit_denied'))[0], $response['message']);
        $this->assertSame(0, $this->accountingDb()->table('cash_up')->where('deleted', 1)->countAllResults());
    }

    public function testGetView_ShowsTheExpectedCashOfTheBranchWhenTheCashUpIsClosed(): void
    {
        $this->accountingCashier();
        $this->sellFor('400.00', 'Cash', date('Y-m-d') . ' 09:00:00');

        $opened = $this->saveClosing($this->closingForm('0', ['open_date' => date('Y-m-d') . ' 08:00:00', 'close_date' => date('Y-m-d') . ' 18:00:00']));

        $this->assertTrue($opened['success']);
        $this->assertSame(0, $this->countRows('accounting_journals'));

        $response = $this->get('/cashups/view/' . $opened['id']);

        $response->assertSee(lang('Cashups.expected_cash'));
        $response->assertSee('500.00');
    }

    public function testGetView_ShowsWhatWasExpectedWhenAClosedCashUpWasSaved(): void
    {
        $this->accountingCashier();
        $this->sellFor('400.00', 'Cash', date('Y-m-d') . ' 09:00:00');

        $closed = $this->saveClosing($this->closingForm('485.50', ['open_date' => date('Y-m-d') . ' 08:00:00', 'close_date' => date('Y-m-d') . ' 18:00:00']));

        $this->assertTrue($closed['success']);

        $response = $this->get('/cashups/view/' . $closed['id']);

        $response->assertSee(lang('Cashups.expected_cash'));
        $response->assertSee('500.00');
    }

    public function testGetView_ShowsNoExpectedCashWhileAccountingIsOff(): void
    {
        $this->startExpenses();
        $this->setConfigValue('accounting_enabled', '0');
        $this->loginAs($this->employeeWithGrants(['cashups']));

        $opened = $this->saveClosing($this->closingForm('0'));

        $this->get('/cashups/view/' . $opened['id'])->assertDontSee(lang('Cashups.expected_cash'));
    }

    public function testGetSearch_ShowsWhichCashUpsAreInTheBooksAndFiltersTheOnesThatAreNot(): void
    {
        $this->accountingCashier();
        $this->setConfigValue('accounting_enabled', '0');

        $legacy = (int) $this->saveClosing($this->closingForm('0'))['id'];

        $this->setConfigValue('accounting_enabled', '1');

        $followed = (int) $this->saveClosing($this->closingForm('0'))['id'];
        $query    = '?search=&start_date=2026-10-01&end_date=2026-10-31&limit=25&offset=0&sort=cashup_id&order=asc';
        $rows     = json_decode($this->get('/cashups/search' . $query)->getJSON(), true)['rows'];
        $byId     = array_column($rows, 'ledger_state', 'cashup_id');

        $this->assertSame(lang('Cashups.ledger_unposted'), $byId[$legacy]);
        $this->assertSame(lang('Cashups.ledger_posted'), $byId[$followed]);

        $only = json_decode($this->get('/cashups/search' . $query . '&filters[]=only_unposted')->getJSON(), true);

        $this->assertSame([(string) $legacy], array_column($only['rows'], 'cashup_id'));
    }

    public function testGetSearch_ShowsNoLedgerColumnWhileAccountingIsOff(): void
    {
        $this->accountingCashier();
        $this->saveClosing($this->closingForm('0'));
        $this->setConfigValue('accounting_enabled', '0');

        $rows = json_decode($this->get('/cashups/search?search=&start_date=2026-10-01&end_date=2026-10-31&limit=25&offset=0&sort=cashup_id&order=asc')->getJSON(), true)['rows'];

        $this->assertNotSame([], $rows);
        $this->assertArrayNotHasKey('ledger_state', $rows[0]);
    }

    public function testGetIndex_OffersTheFilterOfWhatIsNotInTheBooksOnlyWithAccountingOn(): void
    {
        $this->accountingCashier();

        Factories::reset();
        config(OSPOS::class)->update_settings();

        $this->get('/cashups')->assertSee(lang('Cashups.unposted_filter'));

        $this->setConfigValue('accounting_enabled', '0');

        $this->get('/cashups')->assertDontSee(lang('Cashups.unposted_filter'));
    }

    public function testGetIndex_ShowsTheLedgerColumnOnlyWithAccountingOn(): void
    {
        $this->accountingCashier();

        Factories::reset();
        config(OSPOS::class)->update_settings();

        $this->assertStringContainsString('ledger_state', $this->get('/cashups')->getBody());

        $this->setConfigValue('accounting_enabled', '0');

        $this->assertStringNotContainsString('ledger_state', $this->get('/cashups')->getBody());
    }

    private function closedAmount(string $body, string $field): string
    {
        $this->assertSame(1, preg_match('/<input[^>]*id="' . $field . '"[^>]*>/', $body, $input), $field);
        $this->assertSame(1, preg_match('/value="([^"]*)"/', $input[0], $value), $field);

        return $value[1];
    }

    public function testGetView_PrefillsTheClosingAmountsFromTheSalesOfEveryBranchForAnEmployeeOfOneBranch(): void
    {
        Factories::reset('config');
        $this->startBuying();
        $this->businessInTwoBranches();

        try {
            $db = $this->accountingDb();
            $db->table('sales')->whereIn('sale_id', [$this->reportSales['a'], $this->reportSales['b']])->update(['sale_time' => date('Y-m-d H:i:s')]);
            $db->table('cash_up')->insert([
                'open_date' => date('Y-m-d H:i:s', strtotime('-1 day')), 'close_date' => date('Y-m-d H:i:s', strtotime('-1 day')),
                'open_amount_cash' => '0', 'transfer_amount_cash' => '0', 'closed_amount_cash' => '0', 'closed_amount_due' => '0',
                'closed_amount_card' => '0', 'closed_amount_check' => '0', 'closed_amount_total' => '0',
                'description' => 'branch cash-up', 'open_employee_id' => $this->employeeId, 'close_employee_id' => $this->employeeId, 'deleted' => 0,
            ]);

            $cashupId = (int) $db->insertID();

            $this->loginAs($this->employeeWithGrants(['cashups'], [1]));
            $this->dropEveryReportTable();

            $body = $this->get('/cashups/view/' . $cashupId)->getBody();

            $this->assertSame('207.00', $this->closedAmount($body, 'closed_amount_cash'));
            $this->assertSame('126.00', $this->closedAmount($body, 'closed_amount_card'));
        } finally {
            $this->accountingDb()->table('cash_up')->where('description', 'branch cash-up')->delete();
            $this->forgetTheReportBusiness();
            $this->stopBuying();
            $this->removeTheReportItems();
        }
    }

    public function testGetView_ShowsTheExpectedCashTheDifferenceAndTheNoteOnceEach(): void
    {
        $this->accountingCashier();
        $this->sellFor('400.00', 'Cash', date('Y-m-d') . ' 09:00:00');

        $closed = $this->saveClosing($this->closingForm('485.50', ['open_date' => date('Y-m-d') . ' 08:00:00', 'close_date' => date('Y-m-d') . ' 18:00:00']));
        $body   = $this->get('/cashups/view/' . $closed['id'])->getBody();

        $this->assertSame(1, substr_count($body, lang('Cashups.expected_cash')));
        $this->assertSame(1, substr_count($body, lang('Cashups.difference')));
        $this->assertSame(1, substr_count($body, lang('Cashups.difference_note')));
    }

    public function testGetSearch_SortsByWhetherTheCashUpIsInTheBooks(): void
    {
        $this->accountingCashier();

        $followed = (int) $this->saveClosing($this->closingForm('0'))['id'];

        $this->setConfigValue('accounting_enabled', '0');

        $legacy = (int) $this->saveClosing($this->closingForm('0'))['id'];

        $this->setConfigValue('accounting_enabled', '1');

        $query = '?search=&start_date=2026-10-01&end_date=2026-10-31&limit=25&offset=0&sort=ledger_state';
        $up    = json_decode($this->get('/cashups/search' . $query . '&order=asc')->getJSON(), true)['rows'];
        $down  = json_decode($this->get('/cashups/search' . $query . '&order=desc')->getJSON(), true)['rows'];

        $this->assertGreaterThan($followed, $legacy);
        $this->assertSame([(string) $legacy, (string) $followed], array_column($up, 'cashup_id'));
        $this->assertSame([(string) $followed, (string) $legacy], array_column($down, 'cashup_id'));
    }

    public function testGetSearch_IgnoresASortByTheLedgerColumnWhileAccountingIsOff(): void
    {
        $this->accountingCashier();

        $first  = (int) $this->saveClosing($this->closingForm('0'))['id'];
        $second = (int) $this->saveClosing($this->closingForm('0'))['id'];

        $this->setConfigValue('accounting_enabled', '0');

        $response = $this->get('/cashups/search?search=&start_date=2026-10-01&end_date=2026-10-31&limit=25&offset=0&sort=ledger_state&order=desc');

        $response->assertStatus(200);
        $this->assertSame([(string) $second, (string) $first], array_column(json_decode($response->getJSON(), true)['rows'], 'cashup_id'));
    }
}
