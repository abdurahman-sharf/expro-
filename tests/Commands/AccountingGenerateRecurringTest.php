<?php

namespace Tests\Commands;

use App\Libraries\Recurring\RecurringKind;
use App\Libraries\Recurring\RecurringSchedules;
use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\DatabaseTestTrait;
use CodeIgniter\Test\StreamFilterTrait;
use Config\Database;
use Tests\Support\AccountingFixtureTrait;
use Tests\Support\EmployeeFixtureTrait;
use Tests\Support\ItemFixtureTrait;
use Tests\Support\SaleAccountingFixtureTrait;

/**
 * The command cron runs: what it makes, what it says, that a second run makes nothing new and that it
 * says by its exit code whether every schedule is caught up with nothing refused.
 */
class AccountingGenerateRecurringTest extends CIUnitTestCase
{
    use DatabaseTestTrait;
    use StreamFilterTrait;
    use AccountingFixtureTrait;
    use EmployeeFixtureTrait;
    use ItemFixtureTrait;
    use SaleAccountingFixtureTrait;

    protected $migrate     = true;
    protected $migrateOnce = true;
    protected $refresh     = false;
    protected $namespace   = null;

    private RecurringSchedules $schedules;
    private int $manager;
    private int $service;

    public static function setUpBeforeClass(): void
    {
        $seeder = Database::seeder('tests');
        $seeder->call('TestDatabaseBootstrapSeeder');
    }

    protected function setUp(): void
    {
        parent::setUp();

        $this->startSelling();
        $this->allowEmployeeEverywhere();
        $this->clearSchedules();

        $this->schedules = new RecurringSchedules($this->accountingDb());
        $this->manager   = $this->employeeWithGrants([RecurringKind::Invoice->managePermission()]);
        $this->service   = $this->item('0', false);
    }

    protected function tearDown(): void
    {
        $this->clearSchedules();
        $this->accountingDb()->table('accounting_audit_log')->whereIn('entity_type', ['recurring_invoice', 'recurring_expense'])->delete();

        foreach ($this->accountingDb()->table('sales')->select('sale_id')->get()->getResultArray() as $row) {
            $this->saleIds[] = (int) $row['sale_id'];
        }

        $this->stopSelling();

        parent::tearDown();
    }

    private function clearSchedules(): void
    {
        $db = $this->accountingDb();
        $db->query('SET FOREIGN_KEY_CHECKS = 0');

        foreach (['recurring_runs', 'recurring_schedule_reminders', 'recurring_schedule_lines', 'recurring_schedules', 'document_numbers', 'document_sequences'] as $table) {
            $db->table($table)->truncate();
        }

        $db->query('SET FOREIGN_KEY_CHECKS = 1');
    }

    private function schedule(string $start, string $unit = 'days', string $mode = 'draft'): int
    {
        return $this->schedules->create(RecurringKind::Invoice, [
            'name'         => 'Support',
            'period_count' => '1',
            'period_unit'  => $unit,
            'start_date'   => $start,
            'customer_id'  => $this->customerId,
            'location_id'  => self::LOCATION_ID,
            'issue_mode'   => $mode,
            'lines'        => [['item_id' => $this->service, 'quantity' => '1', 'unit_price' => '10.00']],
        ], $this->manager, $start);
    }

    /**
     * @param array<string, string|null> $params
     *
     * @return array{int, string}
     */
    private function generate(array $params = []): array
    {
        $this->resetStreamFilterBuffer();
        $exit = service('commands')->run('accounting:generate_recurring', $params);

        return [$exit, $this->getStreamFilterBuffer()];
    }

    public function testRun_MakesTheDocumentsThatAreDueAndSaysSoWithExitZero(): void
    {
        $this->schedule(date('Y-m-d'));

        [$exit, $output] = $this->generate();

        $this->assertSame(0, $exit);
        $this->assertStringContainsString('1 made, 0 refused', $output);
        $this->assertSame(1, $this->countSales());
    }

    public function testRun_MakesNothingNewWhenItIsRunAgain(): void
    {
        $this->schedule(date('Y-m-d'));

        $this->generate();
        [$exit, $output] = $this->generate();

        $this->assertSame(0, $exit);
        $this->assertStringContainsString('0 made, 0 refused', $output);
        $this->assertSame(1, $this->countSales());
    }

    public function testRun_ExitsWithOneAndNamesTheRefusalWhenADateIsRefused(): void
    {
        $this->schedule(date('Y-m-d'), 'days', 'issue');
        $this->closeThrough(date('Y-m-d'));

        [$exit, $output] = $this->generate();

        $this->assertSame(1, $exit);
        $this->assertStringContainsString('REFUSED (period_closed)', $output);
        $this->assertStringContainsString('0 made, 1 refused', $output);
    }

    public function testRun_ExitsWithOneWhenMoreDatesAreDueThanTheCap(): void
    {
        $this->schedule($this->daysAgo(4));

        [$exit, $output] = $this->generate(['cap' => '2']);

        $this->assertSame(1, $exit);
        $this->assertStringContainsString('2 made', $output);
        $this->assertStringContainsString('1 over the cap', $output);
        $this->assertSame(2, $this->countSales());
    }

    public function testRun_MakesOnlyTheScheduleItIsAskedFor(): void
    {
        $one = $this->schedule(date('Y-m-d'));
        $two = $this->schedule(date('Y-m-d'));

        $this->generate(['schedule' => (string) $two]);

        $this->assertSame(0, $this->accountingDb()->table('recurring_runs')->where('schedule_id', $one)->countAllResults());
        $this->assertSame(1, $this->accountingDb()->table('recurring_runs')->where('schedule_id', $two)->countAllResults());
    }

    public function testRun_CountsFromTheDateItIsGivenAndRefusesADateAfterToday(): void
    {
        $this->schedule($this->daysAgo(3));

        [$exit] = $this->generate(['date' => $this->daysAgo(2)]);

        $this->assertSame(0, $exit);
        $this->assertSame(2, $this->countSales(), 'the dates of three and two days ago, and not the ones after');

        [$exit, $output] = $this->generate(['date' => date('Y-m-d', strtotime('+1 day'))]);

        $this->assertSame(EXIT_USER_INPUT, $exit);
        $this->assertSame(2, $this->countSales());
    }

    public function testRun_RefusesAnOptionThatIsNotWhatItAsksFor(): void
    {
        foreach ([['schedule' => 'x'], ['cap' => '0'], ['cap' => 'many'], ['date' => '2026-02-30'], ['date' => 'yesterday']] as $params) {
            [$exit] = $this->generate($params);

            $this->assertSame(EXIT_USER_INPUT, $exit, json_encode($params));
        }

        $this->assertSame(0, $this->countSales());
    }

    public function testRun_SaysNothingWhenThereIsNothingToMake(): void
    {
        [$exit, $output] = $this->generate();

        $this->assertSame(0, $exit);
        $this->assertStringContainsString('0 made, 0 refused', $output);
    }
}
