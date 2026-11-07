<?php

namespace Tests\Libraries\Accounting\Readiness;

use App\Libraries\Accounting\Readiness\CheckResult;
use App\Libraries\Accounting\Readiness\CheckStatus;
use App\Libraries\Accounting\Readiness\RecurringChecks;
use App\Libraries\Recurring\RecurringKind;
use App\Libraries\Recurring\RecurringSchedules;
use App\Libraries\Recurring\ScheduleStatus;
use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\DatabaseTestTrait;
use Config\Database;
use Tests\Support\AccountingFixtureTrait;
use Tests\Support\EmployeeFixtureTrait;
use Tests\Support\ItemFixtureTrait;
use Tests\Support\SaleAccountingFixtureTrait;

/**
 * The readiness check of the schedules: a schedule that is days behind is stuck, the dates that were refused
 * are for review, and the check only reads.
 */
final class RecurringChecksTest extends CIUnitTestCase
{
    use DatabaseTestTrait;
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
        $this->stopSelling();

        parent::tearDown();
    }

    private function clearSchedules(): void
    {
        $db = $this->accountingDb();
        $db->query('SET FOREIGN_KEY_CHECKS = 0');

        foreach (['recurring_runs', 'recurring_schedule_reminders', 'recurring_schedule_lines', 'recurring_schedules'] as $table) {
            $db->table($table)->truncate();
        }

        $db->query('SET FOREIGN_KEY_CHECKS = 1');
    }

    private function schedule(string $start, int $issueBefore = 0, string $unit = 'months'): int
    {
        return $this->schedules->create(RecurringKind::Invoice, [
            'name'              => 'Support',
            'period_count'      => '1',
            'period_unit'       => $unit,
            'start_date'        => $start,
            'issue_before_days' => (string) $issueBefore,
            'customer_id'       => $this->customerId,
            'location_id'       => self::LOCATION_ID,
            'issue_mode'        => 'draft',
            'lines'             => [['item_id' => $this->service, 'quantity' => '1', 'unit_price' => '10.00']],
        ], $this->manager, $start);
    }

    private function outcome(): CheckResult
    {
        $results = (new RecurringChecks($this->accountingDb()))->run();

        $this->assertCount(1, $results);
        $this->assertSame(['recurring', 'WP35-RECURRING'], [$results[0]->id, $results[0]->ref]);

        return $results[0];
    }

    public function testRun_PassesWhenThereIsNoSchedule(): void
    {
        $result = $this->outcome();

        $this->assertSame(CheckStatus::Pass, $result->status);
        $this->assertSame(0, $result->data['active']);
    }

    public function testRun_PassesForAScheduleThatIsDueTodayAndOneThatIsTwoDaysBehind(): void
    {
        $this->schedule(date('Y-m-d'));
        $this->schedule($this->daysAgo(2), 0, 'days');

        $result = $this->outcome();

        $this->assertSame(CheckStatus::Pass, $result->status);
        $this->assertSame(2, $result->data['active']);
        $this->assertSame(0, $result->data['stuck']);
    }

    public function testRun_FailsForAnActiveScheduleThreeDaysBehindAndNamesIt(): void
    {
        $scheduleId = $this->schedule($this->daysAgo(3), 0, 'days');

        $result = $this->outcome();

        $this->assertSame(CheckStatus::Fail, $result->status);
        $this->assertSame(1, $result->data['stuck']);
        $this->assertStringContainsString('#' . $scheduleId, implode("\n", $result->findings));
        $this->assertStringContainsString($this->daysAgo(3), implode("\n", $result->findings));
        $this->assertStringContainsString('accounting:generate_recurring', implode("\n", $result->findings));
    }

    public function testRun_CountsTheDaysBeforeTheDateInHowLateAScheduleIs(): void
    {
        $this->schedule(date('Y-m-d', strtotime('+5 days')), 10, 'years');

        $this->assertSame(CheckStatus::Fail, $this->outcome()->status);
    }

    public function testRun_IgnoresAPausedAndAnEndedSchedule(): void
    {
        $paused = $this->schedule($this->daysAgo(10), 0, 'days');
        $ended  = $this->schedule($this->daysAgo(10), 0, 'days');

        $this->schedules->changeStatus($paused, ScheduleStatus::Paused, $this->manager);
        $this->schedules->changeStatus($ended, ScheduleStatus::Ended, $this->manager);

        $result = $this->outcome();

        $this->assertSame(CheckStatus::Pass, $result->status);
        $this->assertSame(0, $result->data['active']);
    }

    public function testRun_PutsTheDatesThatWereRefusedUpForReview(): void
    {
        $scheduleId = $this->schedule(date('Y-m-d'));
        $this->accountingDb()->table('recurring_runs')->insert(['schedule_id' => $scheduleId, 'occurrence' => 0, 'due_date' => date('Y-m-d'), 'status' => 'refused', 'refusal_source' => 'posting', 'refusal_code' => 'period_closed']);

        $result = $this->outcome();

        $this->assertSame(CheckStatus::Review, $result->status);
        $this->assertSame(1, $result->data['refused_dates']);
    }

    public function testRun_ChangesNothing(): void
    {
        $scheduleId = $this->schedule($this->daysAgo(5), 0, 'days');
        $before     = [$this->countRows('recurring_schedules'), $this->countRows('recurring_runs'), $this->countRows('sales'), json_encode($this->accountingDb()->table('recurring_schedules')->where('schedule_id', $scheduleId)->get()->getRowArray())];

        $this->outcome();

        $this->assertSame($before, [$this->countRows('recurring_schedules'), $this->countRows('recurring_runs'), $this->countRows('sales'), json_encode($this->accountingDb()->table('recurring_schedules')->where('schedule_id', $scheduleId)->get()->getRowArray())]);
    }
}
