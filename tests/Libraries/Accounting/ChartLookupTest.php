<?php

namespace Tests\Libraries\Accounting;

use App\Libraries\Accounting\ChartLookup;
use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\DatabaseTestTrait;
use Config\Database;
use Tests\Support\AccountingFixtureTrait;

/**
 * Where an account sits in the chart: the accounts the chart names by their system key or code, the
 * accounts under a main account, and the accounts of the treasuries and the banks.
 */
final class ChartLookupTest extends CIUnitTestCase
{
    use DatabaseTestTrait;
    use AccountingFixtureTrait;

    protected $migrate     = true;
    protected $migrateOnce = true;
    protected $refresh     = false;
    protected $namespace   = null;

    private ChartLookup $chart;

    public static function setUpBeforeClass(): void
    {
        $seeder = Database::seeder('tests');
        $seeder->call('TestDatabaseBootstrapSeeder');
    }

    protected function setUp(): void
    {
        parent::setUp();

        $this->resetBranches();
        $this->reinstallChart();

        $this->chart = new ChartLookup();
    }

    protected function tearDown(): void
    {
        $this->reinstallChart();
        $this->resetBranches();

        parent::tearDown();
    }

    public function testAccounts_ListsTheAccountsOfTheCompanyByIdInTheOrderOfTheirCodes(): void
    {
        $codes = array_map(static fn (object $account): string => (string) $account->code, array_values($this->chart->accounts()));

        $sorted = $codes;
        sort($sorted, SORT_STRING);

        $this->assertSame($sorted, $codes);
        $this->assertContains('120101', $codes);
        $this->assertSame($this->accountIdByCode('120101'), array_search('120101', array_map(static fn (object $account): string => (string) $account->code, $this->chart->accounts()), true));
    }

    public function testIdsOfKeys_FindsTheAccountsTheChartNamesAndNothingForAKeyItDoesNotHave(): void
    {
        $this->assertSame([$this->accountIdByCode('1201'), $this->accountIdByCode('1202')], $this->chart->idsOfKeys('treasuries', 'banks'));
        $this->assertSame([], $this->chart->idsOfKeys('no_such_key'));
        $this->assertSame([], $this->chart->idsOfKeys());
    }

    public function testIdsOfCodes_FindsTheAccountsWithTheseCodes(): void
    {
        $this->assertSame([$this->accountIdByCode('53')], $this->chart->idsOfCodes('53'));
        $this->assertSame([], $this->chart->idsOfCodes('9999'));
    }

    public function testSubtreeOf_ReturnsEachAccountAskedForAndEveryAccountUnderIt(): void
    {
        $tree = $this->chart->subtreeOf([$this->accountIdByCode('1201')]);

        $this->assertArrayHasKey($this->accountIdByCode('1201'), $tree);
        $this->assertArrayHasKey($this->accountIdByCode('120101'), $tree);
        $this->assertArrayNotHasKey($this->accountIdByCode('1202'), $tree);
        $this->assertArrayNotHasKey($this->accountIdByCode('12'), $tree, 'The account above is not under it');
    }

    public function testSubtreeOf_JoinsTheTreesOfSeveralAccountsAndIgnoresAnAccountThatIsNotInTheChart(): void
    {
        $custom = $this->customAccount('1201', '120102', 'Second till');
        $tree   = $this->chart->subtreeOf([$this->accountIdByCode('1201'), $this->accountIdByCode('1202'), 987654]);

        $this->assertArrayHasKey($custom, (new ChartLookup())->subtreeOf([$this->accountIdByCode('1201')]));
        $this->assertArrayHasKey($this->accountIdByCode('1202'), $tree);
        $this->assertArrayNotHasKey(987654, $tree);
        $this->assertSame([], $this->chart->subtreeOf([]));
    }

    public function testCashAccounts_AreTheTreasuriesAndTheBanksWithEveryAccountUnderThem(): void
    {
        $bank = $this->customAccount('1202', '120201', 'Bank account');
        $cash = (new ChartLookup())->cashAccounts();

        $this->assertArrayHasKey($bank, $cash);
        $this->assertArrayHasKey($this->accountIdByCode('120101'), $cash);
        $this->assertArrayNotHasKey($this->accountIdByCode('1203'), $cash, 'The stock is not money');
        $this->assertArrayNotHasKey($this->accountIdByCode('12041'), $cash);
    }
}
