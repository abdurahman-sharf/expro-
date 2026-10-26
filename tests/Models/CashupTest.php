<?php

namespace Tests\Models;

use App\Libraries\Accounting\PostingError;
use App\Libraries\Accounting\PostingException;
use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\DatabaseTestTrait;
use Config\Database;
use Tests\Support\AccountingFixtureTrait;
use Tests\Support\EmployeeFixtureTrait;
use Tests\Support\ExpenseAccountingFixtureTrait;

/**
 * What the cash-up model shows about the books while accounting is on, and that it shows and
 * writes nothing of them while accounting is off.
 */
class CashupTest extends CIUnitTestCase
{
    use DatabaseTestTrait;
    use AccountingFixtureTrait;
    use EmployeeFixtureTrait;
    use ExpenseAccountingFixtureTrait;

    protected $migrate     = true;
    protected $migrateOnce = true;
    protected $refresh     = false;
    protected $namespace   = null;

    public static function setUpBeforeClass(): void
    {
        $seeder = Database::seeder('tests');
        $seeder->call('TestDatabaseBootstrapSeeder');
    }

    protected function setUp(): void
    {
        parent::setUp();

        $this->startExpenses();
    }

    protected function tearDown(): void
    {
        $this->stopExpenses();

        parent::tearDown();
    }

    /**
     * @return array<string, mixed>
     */
    private function filters(bool $onlyUnposted = false, bool $deleted = false): array
    {
        return ['is_deleted' => $deleted ? 1 : 0, 'start_date' => '2026-01-01', 'end_date' => '2026-12-31', 'only_unposted' => $onlyUnposted];
    }

    /**
     * @return array<int, object> The rows the list shows, by cash-up.
     */
    private function listed(bool $onlyUnposted = false, bool $deleted = false): array
    {
        $rows = [];

        foreach ($this->cashups->search('', $this->filters($onlyUnposted, $deleted), 0, 0, 'cashup_id', 'asc')->getResult() as $row) {
            $rows[(int) $row->cashup_id] = $row;
        }

        return $rows;
    }

    /**
     * A cash-up saved while the books did not follow it and one saved after.
     *
     * @return array{legacy: int, followed: int}
     */
    private function oneOfEach(): array
    {
        $this->setConfigValue('accounting_enabled', '0');
        $legacy = $this->saveCashup($this->cashupData('90'));
        $this->setConfigValue('accounting_enabled', '1');

        return ['legacy' => $legacy, 'followed' => $this->saveCashup($this->cashupData('90'))];
    }

    public function testSearch_ShowsWhetherTheBooksFollowEachCashUp(): void
    {
        $ids  = $this->oneOfEach();
        $rows = $this->listed();

        $this->assertSame([0, 1], [(int) $rows[$ids['legacy']]->ledger_state, (int) $rows[$ids['followed']]->ledger_state]);
    }

    public function testSearch_ListsOnlyTheCashUpsTheBooksNeverFollowedWhenAsked(): void
    {
        $ids = $this->oneOfEach();

        $this->assertSame([$ids['legacy']], array_keys($this->listed(true)));
        $this->assertCount(2, $this->listed(false));
    }

    public function testSearch_ShowsADeletedCashUpAsOwingTheBooksNothing(): void
    {
        $ids = $this->oneOfEach();

        $this->assertTrue($this->cashups->delete_list([$ids['legacy']], $this->clerkId));
        $this->assertSame(1, (int) $this->listed(false, true)[$ids['legacy']]->ledger_state);
        $this->assertSame([], $this->listed(true, true));
    }

    public function testSearch_CarriesNoLedgerColumnWhileAccountingIsOff(): void
    {
        $this->oneOfEach();
        $this->setConfigValue('accounting_enabled', '0');

        foreach ($this->listed() as $row) {
            $this->assertObjectNotHasProperty('ledger_state', $row);
        }
    }

    public function testSearch_IgnoresTheFilterOfUnpostedCashUpsWhileAccountingIsOff(): void
    {
        $this->oneOfEach();
        $this->setConfigValue('accounting_enabled', '0');

        $this->assertCount(2, $this->listed(true));
    }

    public function testGetFoundRows_CountsOnlyTheCashUpsTheBooksNeverFollowedWhenAsked(): void
    {
        $this->oneOfEach();

        $this->assertSame(2, $this->cashups->get_found_rows('', $this->filters()));
        $this->assertSame(1, $this->cashups->get_found_rows('', $this->filters(true)));
    }

    public function testGetInfo_TellsTheLedgerStateAndWhatWasExpectedAndTheDifference(): void
    {
        $ids = $this->oneOfEach();
        $this->sellFor('50.00');

        $short = $this->saveCashup($this->cashupData('140'));
        $info  = $this->cashups->get_info($short);

        $this->assertSame(1, (int) $info->ledger_state);
        $this->assertSame(['150.0000', '-10.0000'], [bcadd($info->expected_amount_cash, '0', 4), bcadd($info->cash_difference, '0', 4)]);
        $this->assertSame(0, (int) $this->cashups->get_info($ids['legacy'])->ledger_state);
        $this->assertNull($this->cashups->get_info($ids['legacy'])->cash_difference);
    }

    public function testGetInfo_CarriesNoLedgerStateWhileAccountingIsOff(): void
    {
        $ids = $this->oneOfEach();

        $this->setConfigValue('accounting_enabled', '0');

        $this->assertObjectNotHasProperty('ledger_state', $this->cashups->get_info($ids['followed']));
    }

    public function testSaveValue_ForgetsAnEarlierRefusalOnceTheNextSaveSucceeds(): void
    {
        $this->sellFor('50.00');
        $this->closeThrough('2026-10-31');

        $this->assertNull($this->saveCashup($this->cashupData('100')));
        $this->assertInstanceOf(PostingException::class, $this->cashups->accountingFailure());
        $this->assertSame(PostingError::PeriodClosed, $this->cashups->accountingFailure()->error);

        $this->assertNotNull($this->saveCashup($this->cashupData('150')));
        $this->assertNull($this->cashups->accountingFailure());
    }

    public function testSaveValue_WritesNoAccountingColumnWhileAccountingIsOff(): void
    {
        $this->setConfigValue('accounting_enabled', '0');

        $data                         = $this->cashupData('90');
        $data['branch_id']            = 1;
        $data['treasury_id']          = $this->treasury();
        $data['expected_amount_cash'] = '500';
        $data['cash_difference']      = '-410';
        $cashupId                     = $this->saveCashup($data);
        $row                          = $this->accountingDb()->table('cash_up')->where('cashup_id', $cashupId)->get()->getRow();

        $this->assertSame([null, null, null, null], [$row->branch_id, $row->treasury_id, $row->expected_amount_cash, $row->cash_difference]);
        $this->assertSame(0, $this->countRows('accounting_journals'));
    }

    public function testDeleteList_MarksTheCashUpsDeletedWithoutTouchingTheBooksWhileAccountingIsOff(): void
    {
        $ids = $this->oneOfEach();

        $this->setConfigValue('accounting_enabled', '0');

        $journals = $this->countRows('accounting_journals');

        $this->assertTrue($this->cashups->delete_list([$ids['followed'], $ids['legacy']]));
        $this->assertSame($journals, $this->countRows('accounting_journals'));
        $this->assertSame(2, $this->accountingDb()->table('cash_up')->where('deleted', 1)->countAllResults());
    }

    public function testDeleteList_ForgetsAnEarlierRefusalOnceTheNextDeleteSucceeds(): void
    {
        $ids = $this->oneOfEach();

        $this->assertFalse($this->cashups->delete_list([$ids['followed']], $this->employeeWithGrants([], [$this->createBranch('B2')])));
        $this->assertNotNull($this->cashups->accountingFailure());

        $this->assertTrue($this->cashups->delete_list([$ids['followed']], $this->clerkId));
        $this->assertNull($this->cashups->accountingFailure());
    }
}
