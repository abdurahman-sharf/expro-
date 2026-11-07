<?php

namespace Tests\Libraries\Recurring;

use App\Libraries\Recurring\RecurringGenerator;
use App\Libraries\Recurring\RecurringKind;
use App\Libraries\Recurring\RecurringReminders;
use App\Libraries\Recurring\RecurringSchedules;
use App\Libraries\Recurring\ScheduleStatus;
use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\DatabaseTestTrait;
use Config\Database;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Support\AccountingFixtureTrait;
use Tests\Support\EmployeeFixtureTrait;
use Tests\Support\ItemFixtureTrait;
use Tests\Support\SaleAccountingFixtureTrait;

/**
 * The reminders of the schedules, which are lines on a page and nothing else: when each of the five
 * choices falls, which of them look at the next date and which at the invoice made last, that they stay
 * for a month, that the branches of the viewer limit them, and that nothing in the code of the schedules
 * sends a message out of the system.
 */
class RecurringRemindersTest extends CIUnitTestCase
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
    private RecurringReminders $reminders;
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
        $this->reminders = new RecurringReminders($this->accountingDb());
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

    /**
     * @param list<array{send_when: string, days: string}> $reminders
     */
    private function schedule(string $start, array $reminders, int $issueBefore = 0, string $terms = '', string $unit = 'months', ?int $by = null, ?int $location = null): int
    {
        return $this->schedules->create(RecurringKind::Invoice, [
            'name'               => 'Support ' . $start,
            'period_count'       => '1',
            'period_unit'        => $unit,
            'start_date'         => $start,
            'issue_before_days'  => (string) $issueBefore,
            'payment_terms_days' => $terms,
            'customer_id'        => $this->customerId,
            'location_id'        => $location ?? self::LOCATION_ID,
            'issue_mode'         => 'draft',
            'lines'              => [['item_id' => $this->service, 'quantity' => '1', 'unit_price' => '10.00']],
            'reminders'          => $reminders,
        ], $by ?? $this->manager, $start);
    }

    private function ahead(int $days): string
    {
        return date('Y-m-d', strtotime(sprintf('%+d days', $days)));
    }

    /**
     * @return array<string, array{0: int, 1: string, 2: int, 3: int, 4: string, 5: string}>
     */
    public static function datesProvider(): array
    {
        return [
            'on the issue date, five days before'  => [0, '', 5, 0, '2026-03-10', '2026-03-05'],
            'on the invoice date'                  => [1, '', 5, 0, '2026-03-10', '2026-03-10'],
            'on the due date, with terms'          => [2, '', 5, 30, '2026-03-10', '2026-04-09'],
            'on the due date, without terms'       => [2, '', 0, 0, '2026-03-10', '2026-03-10'],
            'three days before the invoice date'   => [3, '3', 0, 0, '2026-03-10', '2026-03-07'],
            'three days after the invoice date'    => [4, '3', 0, 0, '2026-03-10', '2026-03-13'],
            'zero days before'                     => [3, '0', 0, 0, '2026-03-10', '2026-03-10'],
        ];
    }

    #[DataProvider('datesProvider')]
    public function testDateOf_PlacesEachChoiceRelativeToTheInvoiceDate(int $when, string $days, int $issueBefore, int $terms, string $invoiceDate, string $expected): void
    {
        $this->assertSame($expected, RecurringReminders::dateOf($when, $days === '' ? null : (int) $days, $invoiceDate, $issueBefore, $terms === 0 ? null : $terms));
    }

    public function testDue_ListsAReminderOnTheDayItIsSetForAndNotBefore(): void
    {
        $scheduleId = $this->schedule($this->ahead(5), [['send_when' => '3', 'days' => '5']]);

        $this->assertSame([$scheduleId], array_column($this->reminders->due(date('Y-m-d'), $this->manager), 'schedule_id'));
        $this->assertSame([], $this->reminders->due($this->ahead(-1), $this->manager));
    }

    public function testDue_ListsTheOnesThatLookAheadFromTheNextDateAndTheOnesThatLookBackFromTheInvoiceMadeLast(): void
    {
        $scheduleId = $this->schedule(date('Y-m-d'), [['send_when' => '1', 'days' => ''], ['send_when' => '2', 'days' => ''], ['send_when' => '4', 'days' => '0']], 0, '0', 'days');

        $first = $this->reminders->due(date('Y-m-d'), $this->manager);

        $this->assertSame([1], array_column($first, 'send_when'), 'nothing was made yet, so the ones that look back have nothing to look at');

        (new RecurringGenerator($this->accountingDb()))->run();

        $after = $this->reminders->due(date('Y-m-d'), $this->manager);

        $this->assertSame([2, 4], array_column(array_filter($after, static fn (array $row): bool => $row['send_when'] >= 2), 'send_when'));
        $this->assertSame([$scheduleId], array_values(array_unique(array_column($after, 'schedule_id'))));
        $this->assertSame(date('Y-m-d'), $after[0]['invoice_date']);
    }

    public function testDue_KeepsAReminderForAMonthAndNotLonger(): void
    {
        $this->schedule($this->ahead(-30), [['send_when' => '0', 'days' => '']], 0, '', 'years', null, null);
        $this->accountingDb()->table('recurring_schedules')->update(['next_due_date' => $this->ahead(-30)]);

        $this->assertCount(1, $this->reminders->due(date('Y-m-d'), $this->manager));

        $this->accountingDb()->table('recurring_schedules')->update(['next_due_date' => $this->ahead(-31)]);

        $this->assertSame([], $this->reminders->due(date('Y-m-d'), $this->manager));
    }

    public function testDue_LeavesOutAPausedAndAnEndedSchedule(): void
    {
        $paused = $this->schedule(date('Y-m-d'), [['send_when' => '1', 'days' => '']]);
        $ended  = $this->schedule(date('Y-m-d'), [['send_when' => '1', 'days' => '']]);

        $this->schedules->changeStatus($paused, ScheduleStatus::Paused, $this->manager);
        $this->schedules->changeStatus($ended, ScheduleStatus::Ended, $this->manager);

        $this->assertSame([], $this->reminders->due(date('Y-m-d'), $this->manager));
    }

    public function testDue_ListsOnlyTheSchedulesOfTheBranchesOfTheViewer(): void
    {
        $other    = $this->createBranch('B2');
        $location = $this->branchLocation($other);
        $worker   = $this->employeeWithGrants([RecurringKind::Invoice->managePermission()], [$other]);
        $mine     = $this->schedule(date('Y-m-d'), [['send_when' => '1', 'days' => '']]);
        $theirs   = $this->schedule(date('Y-m-d'), [['send_when' => '1', 'days' => '']], 0, '', 'months', $worker, $location);

        $this->assertEqualsCanonicalizing([$mine, $theirs], array_column($this->reminders->due(date('Y-m-d'), $this->manager), 'schedule_id'));
        $this->assertSame([$theirs], array_column($this->reminders->due(date('Y-m-d'), $worker), 'schedule_id'));
        $this->assertSame([], $this->reminders->due(date('Y-m-d'), $this->employeeWithGrants([], [$this->createBranch('B3')])));
    }

    public function testDue_ListsNothingForAScheduleWithoutReminders(): void
    {
        $this->schedule(date('Y-m-d'), []);

        $this->assertSame([], $this->reminders->due(date('Y-m-d'), $this->manager));
    }

    public function testDue_SendsNothingOutOfTheSystem(): void
    {
        $sources = array_merge(glob(APPPATH . 'Libraries/Recurring/*.php') ?: [], [APPPATH . 'Commands/AccountingGenerateRecurring.php', APPPATH . 'Controllers/RecurringScreen.php']);

        $this->assertNotSame([], $sources);

        foreach ($sources as $file) {
            $code = (string) file_get_contents($file);

            $this->assertDoesNotMatchRegularExpression('/Email_lib|MY_Email|Sms_lib|Mailchimp|service\(\s*[\'"]email|->send\(|mail\(|curl_/i', $code, basename($file) . ' must not send anything');
        }
    }
}
