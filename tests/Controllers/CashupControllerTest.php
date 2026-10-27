<?php

namespace Tests\Controllers;

use App\Models\Cashup;
use CodeIgniter\Config\Factories;
use CodeIgniter\Database\Config;
use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\DatabaseTestTrait;
use CodeIgniter\Test\FeatureTestTrait;
use Config\OSPOS;
use Tests\Support\AccountingFixtureTrait;
use Tests\Support\EmployeeFixtureTrait;
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
        ];
        Factories::injectMock('config', OSPOS::class, $ospos);
    }

    protected function tearDown(): void
    {
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
}
