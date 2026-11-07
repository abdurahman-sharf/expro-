<?php

namespace Tests\Libraries\Recurring;

use App\Libraries\Accounting\AccountingError;
use App\Libraries\Accounting\AccountingException;
use App\Libraries\Accounting\PostingError;
use App\Libraries\Organization\BranchError;
use App\Libraries\Pricing\SalesControlSettings;
use App\Libraries\Recurring\RecurringGenerator;
use App\Libraries\Recurring\RecurringKind;
use App\Libraries\Recurring\RecurringSchedules;
use App\Libraries\Recurring\RunRefusal;
use App\Libraries\Recurring\RunResult;
use App\Libraries\Recurring\ScheduleStatus;
use App\Libraries\Tax_lib;
use App\Models\Enums\Rounding_mode;
use App\Models\Sale;
use CodeIgniter\Config\Factories;
use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\DatabaseTestTrait;
use Config\Database;
use Config\OSPOS;
use DateTimeImmutable;
use DateTimeZone;
use PHPUnit\Framework\Attributes\DataProvider;
use RuntimeException;
use Tests\Support\AccountingFixtureTrait;
use Tests\Support\ConcurrentDbRaceTrait;
use Tests\Support\EmployeeFixtureTrait;
use Tests\Support\ExpenseAccountingFixtureTrait;
use Tests\Support\ItemFixtureTrait;
use Tests\Support\SaleAccountingFixtureTrait;
use Throwable;

/**
 * The documents the schedules make on their dates: one per schedule and date however often and however
 * many times at once the generator runs, in order and up to a cap, through the same path as a document
 * typed by hand, with a named refusal for a date the books turn away, and a plain legacy sale or expense
 * while the books are off.
 *
 * @internal
 */
final class RecurringGeneratorTest extends CIUnitTestCase
{
    use DatabaseTestTrait;
    use AccountingFixtureTrait;
    use ConcurrentDbRaceTrait;
    use EmployeeFixtureTrait;
    use ExpenseAccountingFixtureTrait;
    use ItemFixtureTrait;
    use SaleAccountingFixtureTrait;

    protected $migrate     = true;
    protected $migrateOnce = true;
    protected $refresh     = false;
    protected $namespace;
    private RecurringSchedules $schedules;
    private RecurringGenerator $generator;
    private int $manager;
    private int $service;
    private string $zone;

    public static function setUpBeforeClass(): void
    {
        $seeder = Database::seeder('tests');
        $seeder->call('TestDatabaseBootstrapSeeder');
    }

    protected function setUp(): void
    {
        parent::setUp();

        $this->zone = date_default_timezone_get();
        $this->startExpenses();
        $this->startSelling();
        $this->allowEmployeeEverywhere();
        $this->clearSchedules();

        $this->schedules = new RecurringSchedules($this->accountingDb());
        $this->generator = new RecurringGenerator($this->accountingDb());
        $this->manager   = $this->employeeWithGrants([RecurringKind::Invoice->managePermission(), RecurringKind::Expense->managePermission()]);
        $this->service   = $this->item('0', false);
    }

    protected function tearDown(): void
    {
        date_default_timezone_set($this->zone);
        $this->releaseOpenTransactions();
        $this->clearSchedules();
        $this->accountingDb()->table('accounting_audit_log')->whereIn('entity_type', ['recurring_invoice', 'recurring_expense'])->delete();
        $this->cleanSaleIds();
        $this->stopExpenses();
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
     * @param array<string, mixed> $override
     *
     * @return array<string, mixed>
     */
    private function invoiceInput(array $override = []): array
    {
        return array_merge([
            'name'               => 'Monthly support',
            'period_count'       => '1',
            'period_unit'        => 'months',
            'start_date'         => date('Y-m-d'),
            'end_date'           => '',
            'max_repeats'        => '0',
            'issue_before_days'  => '0',
            'customer_id'        => $this->customerId,
            'location_id'        => self::LOCATION_ID,
            'payment_terms_days' => '',
            'issue_mode'         => 'draft',
            'comment'            => '',
            'active'             => '1',
            'lines'              => [['item_id' => $this->service, 'quantity' => '2', 'unit_price' => '50.00']],
            'reminders'          => [],
        ], $override);
    }

    /**
     * @param array<string, mixed> $override
     *
     * @return array<string, mixed>
     */
    private function expenseInput(array $override = []): array
    {
        return array_merge([
            'name'                => 'Office rent',
            'frequency'           => 'monthly',
            'start_date'          => date('Y-m-d'),
            'end_date'            => '',
            'max_repeats'         => '0',
            'expense_category_id' => $this->category('Rent'),
            'amount'              => '230.00',
            'tax_amount'          => '30.00',
            'payment_type'        => lang('Sales.cash'),
            'comment'             => '',
            'active'              => '1',
        ], $override);
    }

    /**
     * @param array<string, mixed> $override
     */
    private function invoiceSchedule(array $override = []): int
    {
        $input = $this->invoiceInput($override);

        return $this->schedules->create(RecurringKind::Invoice, $input, $this->manager, min((string) $input['start_date'], date('Y-m-d')));
    }

    /**
     * @param array<string, mixed> $override
     */
    private function expenseSchedule(array $override = []): int
    {
        $input = $this->expenseInput($override);

        return $this->schedules->create(RecurringKind::Expense, $input, $this->manager, min((string) $input['start_date'], date('Y-m-d')));
    }

    /**
     * @return list<object>
     */
    private function runsOf(int $scheduleId): array
    {
        return $this->accountingDb()->table('recurring_runs')->where('schedule_id', $scheduleId)->orderBy('due_date', 'ASC')->get()->getResult();
    }

    private function scheduleRow(int $scheduleId): object
    {
        return $this->accountingDb()->table('recurring_schedules')->where('schedule_id', $scheduleId)->get()->getRow();
    }

    private function cleanSaleIds(): void
    {
        foreach ($this->accountingDb()->table('sales')->select('sale_id')->get()->getResultArray() as $row) {
            $this->saleIds[] = (int) $row['sale_id'];
        }
    }

    public function testRun_MakesADraftInvoiceOnItsDateAndPostsNothingUntilItIsIssued(): void
    {
        $scheduleId = $this->invoiceSchedule();

        $report = $this->generator->run();
        $this->cleanSaleIds();

        $this->assertSame(1, $report->generated());
        $run  = $this->runsOf($scheduleId)[0];
        $sale = $this->saleRow((int) $run->sale_id);

        $this->assertSame('generated', $run->status);
        $this->assertSame('draft', $run->generated_as);
        $this->assertSame(SUSPENDED, (int) $sale->sale_status);
        $this->assertSame(SALE_TYPE_INVOICE, (int) $sale->sale_type);
        $this->assertSame(0, $this->countJournals());
        $this->assertSame(date('Y-m-d'), $sale->invoice_date);
        $this->assertSame($this->customerId, (int) $sale->customer_id);
    }

    public function testRun_IssuesTheInvoiceThroughTheNormalPostingWhenTheScheduleIssues(): void
    {
        $stocked = $this->item('10');
        $this->accountingDb()->table('item_quantities')->ignore(true)->insert(['item_id' => $stocked, 'location_id' => self::LOCATION_ID, 'quantity' => 20]);
        $scheduleId = $this->invoiceSchedule([
            'issue_mode'         => 'issue',
            'payment_terms_days' => '15',
            'lines'              => [['item_id' => $stocked, 'quantity' => '3', 'unit_price' => '40.00'], ['item_id' => $this->service, 'quantity' => '1', 'unit_price' => '100.00']],
        ]);

        $report = $this->generator->run();
        $this->cleanSaleIds();

        $this->assertSame(1, $report->generated());
        $saleId = (int) $this->runsOf($scheduleId)[0]->sale_id;
        $sale   = $this->saleRow($saleId);

        $this->assertSame(COMPLETED, (int) $sale->sale_status);
        $this->assertSame(date('Y-m-d', strtotime('+15 days')), $sale->due_date);
        $this->assertSame(17.0, $this->stock($stocked));
        $this->assertNotSame([], $this->standing($saleId));
        $this->assertTrue(array_sum(array_map(static fn (array $journal): int => $journal['source'] === 'sale' ? 1 : 0, $this->journals($saleId))) >= 1);
        $this->assertTies();
    }

    public function testRun_MakesOneDocumentWhenItIsRunAgain(): void
    {
        $scheduleId = $this->invoiceSchedule();

        $first  = $this->generator->run();
        $second = $this->generator->run();
        $this->cleanSaleIds();

        $this->assertSame(1, $first->generated());
        $this->assertSame([], $second->results);
        $this->assertCount(1, $this->runsOf($scheduleId));
        $this->assertSame(1, $this->countSales());
        $this->assertSame(1, (int) $this->scheduleRow($scheduleId)->next_occurrence);
    }

    public function testRun_MakesNothingBeforeTheDateComes(): void
    {
        $scheduleId = $this->invoiceSchedule(['start_date' => date('Y-m-d', strtotime('+3 days'))]);

        $report = $this->generator->run();

        $this->assertSame([], $report->results);
        $this->assertSame([], $this->runsOf($scheduleId));
        $this->assertSame(0, $this->countSales());
    }

    public function testRun_MakesTheInvoiceTheDaysBeforeItsDateThatTheScheduleAsks(): void
    {
        $due        = date('Y-m-d', strtotime('+3 days'));
        $scheduleId = $this->invoiceSchedule(['start_date' => $due, 'issue_before_days' => '5']);

        $report = $this->generator->run();
        $this->cleanSaleIds();

        $this->assertSame(1, $report->generated());
        $run = $this->runsOf($scheduleId)[0];

        $this->assertSame($due, $run->due_date);
        $this->assertSame(date('Y-m-d'), $this->saleRow((int) $run->sale_id)->invoice_date, 'an invoice is never dated a day that has not come');
    }

    public function testRun_CatchesUpMissedDatesInOrderUpToTheCapAndGoesOnWhenItRunsAgain(): void
    {
        $scheduleId = $this->invoiceSchedule(['start_date' => $this->daysAgo(5), 'period_count' => '1', 'period_unit' => 'days']);

        $first = $this->generator->run(null, null, 3);
        $this->cleanSaleIds();

        $this->assertSame(3, $first->generated());
        $this->assertSame([$scheduleId], $first->capped);
        $this->assertSame([$this->daysAgo(5), $this->daysAgo(4), $this->daysAgo(3)], array_column($this->runsOf($scheduleId), 'due_date'));

        $second = $this->generator->run(null, null, 3);
        $this->cleanSaleIds();

        $this->assertSame(3, $second->generated());
        $this->assertSame([], $second->capped);
        $this->assertSame(array_map(fn (int $days): string => $this->daysAgo($days), [5, 4, 3, 2, 1, 0]), array_column($this->runsOf($scheduleId), 'due_date'));
        $this->assertSame(array_map(static fn (int $i): int => $i, range(0, 5)), array_map('intval', array_column($this->runsOf($scheduleId), 'occurrence')));
    }

    public function testRun_DatesEachCaughtUpInvoiceWithItsOwnDueDate(): void
    {
        $scheduleId = $this->invoiceSchedule(['start_date' => $this->daysAgo(2), 'period_unit' => 'days', 'issue_mode' => 'issue']);

        $this->generator->run();
        $this->cleanSaleIds();

        $dates = array_map(fn (object $run): string => (string) $this->saleRow((int) $run->sale_id)->invoice_date, $this->runsOf($scheduleId));

        $this->assertSame([$this->daysAgo(2), $this->daysAgo(1), $this->daysAgo(0)], $dates);
    }

    public function testRun_CountsMonthEndDatesFromTheStartAndNeverFromTheDateBefore(): void
    {
        $scheduleId = $this->invoiceSchedule(['start_date' => '2026-01-31']);

        $this->generator->run('2026-04-30');
        $this->cleanSaleIds();

        $this->assertSame(['2026-01-31', '2026-02-28', '2026-03-31', '2026-04-30'], array_column($this->runsOf($scheduleId), 'due_date'));
    }

    public function testRun_RecordsANamedRefusalForADateInAClosedPeriodAndGoesOnWithTheNext(): void
    {
        $scheduleId = $this->invoiceSchedule(['start_date' => $this->daysAgo(3), 'period_unit' => 'days', 'issue_mode' => 'issue']);
        $this->closeThrough($this->daysAgo(2));

        $report = $this->generator->run();
        $this->cleanSaleIds();

        $this->assertSame(2, $report->refused());
        $this->assertSame(2, $report->generated());
        $this->assertCount(4, $report->results, 'each date is met once: a refused date moves the schedule on at once');
        $this->assertSame(0, $report->count(RunResult::EXISTING));

        [$first, $second, $third, $fourth] = $this->runsOf($scheduleId);

        $this->assertSame(['refused', 'refused', 'generated', 'generated'], [$first->status, $second->status, $third->status, $fourth->status]);
        $this->assertSame([RunRefusal::SOURCE_POSTING, PostingError::PeriodClosed->value], [$first->refusal_source, $first->refusal_code]);
        $this->assertNull($first->sale_id);
        $this->assertSame(2, $this->countSales());
        $this->assertSame(4, (int) $this->scheduleRow($scheduleId)->next_occurrence);
        $this->assertNotSame('', RunRefusal::wordsOf($first->refusal_source, $first->refusal_code, $first->refusal_context));
    }

    public function testRun_LeavesNothingBehindOfARefusedDate(): void
    {
        $this->invoiceSchedule(['start_date' => $this->daysAgo(1), 'period_unit' => 'days', 'issue_mode' => 'issue']);
        $this->closeThrough(date('Y-m-d'));

        $this->generator->run();

        $this->assertSame(0, $this->countSales());
        $this->assertSame(0, $this->countJournals());
        $this->assertSame(0, $this->countRows('document_numbers'));
    }

    public function testRun_SkipsAPausedAndAnEndedSchedule(): void
    {
        $paused = $this->invoiceSchedule();
        $ended  = $this->invoiceSchedule(['name' => 'Another']);

        $this->schedules->changeStatus($paused, ScheduleStatus::Paused, $this->manager);
        $this->schedules->changeStatus($ended, ScheduleStatus::Ended, $this->manager);

        $report = $this->generator->run();

        $this->assertSame([], $report->results);
        $this->assertSame(0, $this->countSales());
    }

    public function testRun_StopsAtTheMaximumNumberOfRepeatsAndEndsTheSchedule(): void
    {
        $scheduleId = $this->invoiceSchedule(['start_date' => $this->daysAgo(5), 'period_unit' => 'days', 'max_repeats' => '3']);

        $report = $this->generator->run();
        $this->cleanSaleIds();

        $this->assertSame(3, $report->generated());
        $this->assertCount(3, $report->results, 'the last date ends the schedule, which is not met again');
        $this->assertSame(0, $report->count(RunResult::ENDED));
        $row = $this->scheduleRow($scheduleId);

        $this->assertSame('ended', $row->status);
        $this->assertSame('max_repeats', $row->ended_reason);
        $this->assertNull($row->next_due_date);
        $this->assertSame([], $this->generator->run()->results);
    }

    public function testRun_StopsAfterTheEndDateAndEndsTheSchedule(): void
    {
        $scheduleId = $this->invoiceSchedule(['start_date' => $this->daysAgo(5), 'period_unit' => 'days', 'end_date' => $this->daysAgo(3)]);

        $report = $this->generator->run();
        $this->cleanSaleIds();

        $this->assertSame(3, $report->generated());
        $this->assertSame([$this->daysAgo(5), $this->daysAgo(4), $this->daysAgo(3)], array_column($this->runsOf($scheduleId), 'due_date'));
        $this->assertSame(['ended', 'end_date'], [$this->scheduleRow($scheduleId)->status, $this->scheduleRow($scheduleId)->ended_reason]);
    }

    public function testRun_MakesNothingForTheDatesAScheduleWasPausedOver(): void
    {
        $scheduleId = $this->invoiceSchedule(['start_date' => $this->daysAgo(5), 'period_unit' => 'days']);

        $this->schedules->changeStatus($scheduleId, ScheduleStatus::Paused, $this->manager);
        $this->schedules->changeStatus($scheduleId, ScheduleStatus::Active, $this->manager);
        $this->generator->run();
        $this->cleanSaleIds();

        $this->assertSame([date('Y-m-d')], array_column($this->runsOf($scheduleId), 'due_date'));
    }

    public function testRun_MakesOnlyTheScheduleItWasAskedFor(): void
    {
        $one = $this->invoiceSchedule();
        $two = $this->invoiceSchedule(['name' => 'Second']);

        $report = $this->generator->run(null, $two);
        $this->cleanSaleIds();

        $this->assertSame(1, $report->generated());
        $this->assertSame([], $this->runsOf($one));
        $this->assertCount(1, $this->runsOf($two));
    }

    public function testRun_RefusesWhenTheEmployeeOfTheScheduleLostThePermissionToManageIt(): void
    {
        $scheduleId = $this->invoiceSchedule();
        $this->accountingDb()->table('grants')->where(['person_id' => $this->manager, 'permission_id' => RecurringKind::Invoice->managePermission()])->delete();

        $report = $this->generator->run();

        $this->assertSame(1, $report->refused());
        $run = $this->runsOf($scheduleId)[0];

        $this->assertSame([RunRefusal::SOURCE_ACCOUNTING, AccountingError::PermissionDenied->value], [$run->refusal_source, $run->refusal_code]);
        $this->assertSame(0, $this->countSales());
    }

    public function testRun_RefusesWhenTheEmployeeOfTheScheduleNoLongerWorksInItsBranch(): void
    {
        $other   = $this->createBranch('B2');
        $worker  = $this->employeeWithGrants([RecurringKind::Invoice->managePermission()], [1]);
        $stock   = $this->branchLocation($other);
        $input   = $this->invoiceInput(['location_id' => self::LOCATION_ID]);
        $created = $this->schedules->create(RecurringKind::Invoice, $input, $worker);

        $this->accountingDb()->table('recurring_schedules')->where('schedule_id', $created)->update(['branch_id' => $other]);
        $this->accountingDb()->table('recurring_schedule_lines')->where('schedule_id', $created)->update(['item_location' => $stock]);

        $report = $this->generator->run();

        $this->assertSame(1, $report->refused());
        $this->assertSame(RunRefusal::SOURCE_BRANCH, $this->runsOf($created)[0]->refusal_source);
        $this->assertSame(BranchError::NotAllowed->value, $this->runsOf($created)[0]->refusal_code);
    }

    public function testRun_UsesTheUniqueKeyOfTheScheduleAndTheDateWhenTheCounterIsLost(): void
    {
        $scheduleId = $this->invoiceSchedule();

        $this->generator->run();
        $this->cleanSaleIds();
        $this->accountingDb()->table('recurring_schedules')->where('schedule_id', $scheduleId)->update(['next_occurrence' => 0, 'next_due_date' => date('Y-m-d'), 'status' => 'active', 'ended_reason' => null]);

        $report = $this->generator->run();

        $this->assertSame(1, $report->count(RunResult::EXISTING));
        $this->assertSame(0, $report->generated());
        $this->assertSame(1, $this->countSales());
        $this->assertCount(1, $this->runsOf($scheduleId));
        $this->assertSame(1, (int) $this->scheduleRow($scheduleId)->next_occurrence);
    }

    public function testRun_RefusesTheSecondRowOfADateByTheKeyOfTheTable(): void
    {
        $scheduleId = $this->invoiceSchedule();

        $this->generator->run();
        $this->cleanSaleIds();
        $this->expectException(Throwable::class);

        $this->accountingDb()->table('recurring_runs')->insert(['schedule_id' => $scheduleId, 'occurrence' => 5, 'due_date' => date('Y-m-d'), 'status' => 'refused', 'refusal_code' => 'x']);
    }

    public function testRun_MakesOneDocumentWhenTwoProcessesRunItAtTheSameMoment(): void
    {
        $scheduleId = $this->invoiceSchedule();

        [$one, $two] = $this->raceTwoProcesses('recurringRun', [date('Y-m-d'), $scheduleId], [date('Y-m-d'), $scheduleId]);
        $this->cleanSaleIds();

        $this->assertSame(1, (int) $one + (int) $two, 'exactly one of the two runs made the document');
        $this->assertCount(1, $this->runsOf($scheduleId));
        $this->assertSame(1, $this->countSales());
        $this->assertSame(1, (int) $this->scheduleRow($scheduleId)->next_occurrence);
    }

    public function testRun_CountsTheDayOfTheApplicationAndNotTheClockOfTheDatabase(): void
    {
        $databaseDay = (string) $this->accountingDb()->query('SELECT CURDATE() AS today')->getRow()->today;

        foreach (['Pacific/Kiritimati', 'Pacific/Pago_Pago'] as $candidate) {
            $day = (new DateTimeImmutable('now', new DateTimeZone($candidate)))->format('Y-m-d');

            if ($day !== $databaseDay) {
                $zone = $candidate;

                break;
            }
        }

        date_default_timezone_set($zone);
        $today    = date('Y-m-d');
        $tomorrow = date('Y-m-d', strtotime('+1 day'));
        $dueToday = $this->invoiceSchedule(['start_date' => $today]);
        $notYet   = $this->invoiceSchedule(['name' => 'Tomorrow', 'start_date' => $tomorrow]);

        $report = $this->generator->run();
        $this->cleanSaleIds();

        $this->assertSame(1, $report->generated());
        $this->assertCount(1, $this->runsOf($dueToday));
        $this->assertSame([], $this->runsOf($notYet));
        $this->assertSame($today, substr((string) $this->runsOf($dueToday)[0]->created_at, 0, 10), 'the history says the day of the application');
    }

    public function testRun_MakesALegacySaleThatPostsNothingWhileTheBooksAreOff(): void
    {
        $scheduleId = $this->invoiceSchedule(['issue_mode' => 'issue']);
        $this->setConfigValue('accounting_enabled', '0');
        $this->accountingDb()->table('accounting_audit_log')->truncate();

        $report = $this->generator->run();
        $this->cleanSaleIds();

        $this->assertSame(1, $report->generated());
        $sale = $this->saleRow((int) $this->runsOf($scheduleId)[0]->sale_id);

        $this->assertSame(COMPLETED, (int) $sale->sale_status);
        $this->assertSame(0, $this->countJournals());
        $this->assertSame(0, $this->countRows('accounting_audit_log'), 'the audit log of the books is not written while they are off');
    }

    public function testRun_AuditsEveryGenerationWhileTheBooksAreOn(): void
    {
        $scheduleId = $this->invoiceSchedule(['start_date' => $this->daysAgo(1), 'period_unit' => 'days', 'issue_mode' => 'issue']);
        $this->closeThrough($this->daysAgo(1));

        $this->generator->run();
        $this->cleanSaleIds();

        $actions = array_column($this->accountingDb()->table('accounting_audit_log')->where(['entity_type' => 'recurring_invoice', 'entity_id' => $scheduleId])->orderBy('audit_id', 'ASC')->get()->getResultArray(), 'action');

        $this->assertSame(['created', 'refused', 'generated'], $actions);
    }

    public function testRun_MakesTheExpenseThroughTheNormalPostingWithTheTaxSplit(): void
    {
        $scheduleId = $this->expenseSchedule();

        $report = $this->generator->run();

        $this->assertSame(1, $report->generated());
        $run     = $this->runsOf($scheduleId)[0];
        $expense = $this->accountingDb()->table('expenses')->where('expense_id', $run->expense_id)->get()->getRow();

        $this->assertNull($run->sale_id);
        $this->assertSame(date('Y-m-d') . ' 00:00:00', $expense->date);
        $this->assertSame('230.00', bcadd((string) $expense->amount, '0', 2));
        $this->assertSame($this->manager, (int) $expense->employee_id);
        $this->assertNotNull($expense->branch_id);
        $this->assertSame(
            [$this->accountId('expenses_default') . '=200.00/0.00', $this->accountId('tax_input') . '=30.00/0.00', $this->accountId('cash') . '=0.00/230.00'],
            $this->postedLines('expense', (int) $run->expense_id),
        );
    }

    public function testRun_MakesOneExpenseWhenItIsRunAgainAndCatchesUpInOrder(): void
    {
        $scheduleId = $this->expenseSchedule(['start_date' => '2026-01-31']);

        $first  = $this->generator->run('2026-03-31');
        $second = $this->generator->run('2026-03-31');

        $this->assertSame(3, $first->generated());
        $this->assertSame([], $second->results);
        $this->assertSame(['2026-01-31', '2026-02-28', '2026-03-31'], array_column($this->runsOf($scheduleId), 'due_date'));
        $this->assertSame(3, $this->countRows('expenses'));
    }

    public function testRun_RefusesAnExpenseOfAClosedPeriodByName(): void
    {
        $scheduleId = $this->expenseSchedule(['start_date' => $this->daysAgo(40), 'frequency' => 'monthly']);
        $this->closeThrough($this->daysAgo(20));

        $report = $this->generator->run();

        $this->assertSame(1, $report->refused());
        $this->assertSame(1, $report->generated());
        $this->assertSame(['refused', 'generated'], array_column($this->runsOf($scheduleId), 'status'));
        $this->assertSame(PostingError::PeriodClosed->value, $this->runsOf($scheduleId)[0]->refusal_code);
        $this->assertSame(1, $this->countRows('expenses'));
    }

    public function testRun_MakesAPlainExpenseWhileTheBooksAreOff(): void
    {
        $scheduleId = $this->expenseSchedule();
        $this->setConfigValue('accounting_enabled', '0');

        $report = $this->generator->run();

        $this->assertSame(1, $report->generated());
        $this->assertSame(1, $this->countRows('expenses'));
        $this->assertSame(0, $this->countJournals());
        $this->assertNotNull($this->runsOf($scheduleId)[0]->expense_id);
    }

    public function testRun_RefusesTheDayThatIsNotADay(): void
    {
        $this->expectException(AccountingException::class);

        $this->generator->run('2026-02-30');
    }

    public function testRun_WorksOutTheTaxOfAnIssuedInvoiceWithTheScaleOfTheRegisterWhateverScaleTheProcessHas(): void
    {
        $taxed = $this->item('0', false);
        $this->accountingDb()->table('items_taxes')->insert(['item_id' => $taxed, 'name' => 'VAT', 'percent' => '15.000']);
        $scheduleId = $this->invoiceSchedule(['issue_mode' => 'issue', 'lines' => [['item_id' => $taxed, 'quantity' => '1', 'unit_price' => '100.00']]]);
        $before     = bcscale();

        bcscale(0);

        try {
            $report = $this->generator->run();
            $during = bcscale();
        } finally {
            bcscale($before);
        }

        $this->cleanSaleIds();

        $this->assertSame(1, $report->generated());
        $this->assertSame(0, $during, 'the scale the process had is given back');
        $saleId = (int) $this->runsOf($scheduleId)[0]->sale_id;
        $tax    = $this->accountingDb()->table('sales_items_taxes')->where('sale_id', $saleId)->get()->getRow();

        $this->assertSame('15.0000', bcadd((string) $tax->item_tax_amount, '0', 4));
        $this->assertSame('115.00', $this->balance($this->customerAccount()));
        $this->assertTies();
    }

    public function testRun_PutsTheLinesOfTheTemplateOnTheInvoiceWithTheirPriceDiscountAndDescription(): void
    {
        $stocked = $this->item('12.50');
        $this->accountingDb()->table('item_quantities')->ignore(true)->insert(['item_id' => $stocked, 'location_id' => self::LOCATION_ID, 'quantity' => 20]);
        $scheduleId = $this->invoiceSchedule([
            'issue_mode' => 'issue',
            'lines'      => [
                ['item_id' => $stocked, 'quantity' => '3', 'unit_price' => '40.00', 'discount' => '10', 'discount_type' => '0', 'description' => 'Bag of grain'],
                ['item_id' => $this->service, 'quantity' => '2', 'unit_price' => '15.00', 'discount' => '5', 'discount_type' => '1'],
            ],
        ]);

        $this->generator->run();
        $this->cleanSaleIds();

        $saleId = (int) $this->runsOf($scheduleId)[0]->sale_id;
        $rows   = $this->accountingDb()->table('sales_items')->where('sale_id', $saleId)->orderBy('line', 'ASC')->get()->getResult();

        $this->assertCount(2, $rows);
        $this->assertSame([$stocked, 3.0, 40.0, 10.0, PERCENT, 12.5, 'Bag of grain', self::LOCATION_ID], [(int) $rows[0]->item_id, (float) $rows[0]->quantity_purchased, (float) $rows[0]->item_unit_price, (float) $rows[0]->discount, (int) $rows[0]->discount_type, (float) $rows[0]->item_cost_price, $rows[0]->description, (int) $rows[0]->item_location]);
        $this->assertSame([$this->service, 2.0, 15.0, 5.0, 1], [(int) $rows[1]->item_id, (float) $rows[1]->quantity_purchased, (float) $rows[1]->item_unit_price, (float) $rows[1]->discount, (int) $rows[1]->discount_type]);
        $this->assertSame('128.00', $this->balance($this->customerAccount()), '3 at 40 less 10 percent is 108 and 2 at 15 less 5 a unit is 20');
        $this->assertSame(17.0, $this->stock($stocked));
    }

    #[DataProvider('provideRun_NamesWhatTheSaleAnswersWhenItDoesNotSaveTheInvoiceAndMakesTheNextSchedule')]
    public function testRun_NamesWhatTheSaleAnswersWhenItDoesNotSaveTheInvoiceAndMakesTheNextSchedule(int $answer, string $code): void
    {
        $refused = $this->invoiceSchedule(['name' => 'Refused by the sale']);
        $expense = $this->expenseSchedule();
        $model   = $this->getMockBuilder(Sale::class)->disableOriginalConstructor()->onlyMethods(['save_value'])->getMock();
        $model->method('save_value')->willReturn($answer);

        try {
            Factories::injectMock('models', Sale::class, $model);
            $report = (new RecurringGenerator($this->accountingDb()))->run();
        } finally {
            Factories::reset('models');
        }

        $run = $this->runsOf($refused)[0];

        $this->assertSame(1, $report->refused());
        $this->assertSame(1, $report->generated());
        $this->assertSame(['refused', RunRefusal::SOURCE_SALE, $code, null], [$run->status, $run->refusal_source, $run->refusal_code, $run->sale_id]);
        $this->assertSame(lang('Recurring.refusal_' . $code), RunRefusal::wordsOf($run->refusal_source, $run->refusal_code, null));
        $this->assertSame(1, (int) $this->scheduleRow($refused)->next_occurrence, 'the schedule goes on after the refused date');
        $this->assertCount(1, $this->runsOf($expense));
    }

    public static function provideRun_NamesWhatTheSaleAnswersWhenItDoesNotSaveTheInvoiceAndMakesTheNextSchedule(): iterable
    {
        return [
            'no stock'         => [INSUFFICIENT_STOCK, 'insufficient_stock'],
            'discount'         => [INVOICE_DISCOUNT_INVALID, 'discount_invalid'],
            'nothing is saved' => [NEW_ENTRY, 'save_failed'],
        ];
    }

    public function testRun_RefusesAnInvoiceWhoseNumberTheFormatGaveAlreadyByNameAndGoesOn(): void
    {
        $first  = $this->invoiceSchedule(['name' => 'First', 'issue_mode' => 'issue']);
        $second = $this->invoiceSchedule(['name' => 'Second', 'issue_mode' => 'issue']);
        $format = $this->accountingDb()->table('app_config')->where('key', 'sales_invoice_format')->get()->getRow();

        $this->setConfigValue('sales_invoice_format', 'RECURRING-FIXED');

        try {
            $report = $this->generator->run();
        } finally {
            $this->setConfigValue('sales_invoice_format', $format === null ? '' : (string) $format->value);
        }

        $this->cleanSaleIds();

        $this->assertSame(1, $report->generated());
        $this->assertSame(1, $report->refused());
        $this->assertSame('RECURRING-FIXED', $this->saleRow((int) $this->runsOf($first)[0]->sale_id)->invoice_number);
        $this->assertSame([RunRefusal::SOURCE_SALE, 'invoice_number_duplicate'], [$this->runsOf($second)[0]->refusal_source, $this->runsOf($second)[0]->refusal_code]);
        $this->assertSame(1, (int) $this->scheduleRow($second)->next_occurrence, 'the schedule goes on after the refused date');
    }

    public function testRun_RefusesAnInvoiceAboveTheCreditLimitOfTheCustomerByName(): void
    {
        $scheduleId = $this->invoiceSchedule(['issue_mode' => 'issue']);

        $this->accountingDb()->table('customers')->where('person_id', $this->customerId)->update(['credit_limit' => 50]);
        $this->setConfigValue(SalesControlSettings::CREDIT_LIMIT, '1');

        try {
            $report = $this->generator->run();
        } finally {
            $this->setConfigValue(SalesControlSettings::CREDIT_LIMIT, '0');
            $this->accountingDb()->table('customers')->update(['credit_limit' => 0]);
        }

        $this->cleanSaleIds();

        $this->assertSame(1, $report->refused());
        $run = $this->runsOf($scheduleId)[0];

        $this->assertSame([RunRefusal::SOURCE_ACCOUNTING, AccountingError::CreditLimitExceeded->value], [$run->refusal_source, $run->refusal_code]);
        $this->assertSame(0, $this->countSales());
        $this->assertSame('active', $this->scheduleRow($scheduleId)->status);
    }

    public function testRun_TaxesAnInvoiceByTheDestinationOfTheCustomerWithTheTaxCategoryOfTheItem(): void
    {
        $db = $this->accountingDb();

        $db->table('tax_categories')->insert(['tax_category' => 'Goods ' . uniqid(), 'tax_group_sequence' => 1]);
        $categoryId = (int) $db->insertID();
        $db->table('tax_jurisdictions')->insert(['jurisdiction_name' => 'Zone', 'tax_group' => 'ZG' . random_int(100000, 999999), 'tax_type' => Tax_lib::TAX_TYPE_EXCLUDED, 'tax_group_sequence' => 1, 'cascade_sequence' => 0]);
        $jurisdictionId = (int) $db->insertID();
        $db->table('tax_codes')->insert(['tax_code' => 'Customer' . random_int(1000, 9999), 'tax_code_name' => 'Customer', 'city' => '', 'state' => '']);
        $codeId = (int) $db->insertID();
        $db->table('tax_rates')->insert(['rate_tax_code_id' => $codeId, 'rate_tax_category_id' => $categoryId, 'rate_jurisdiction_id' => $jurisdictionId, 'tax_rate' => '10', 'tax_rounding_code' => Rounding_mode::HALF_UP]);
        $db->table('customers')->where('person_id', $this->customerId)->update(['sales_tax_code_id' => $codeId]);

        $taxed = $this->item('0', false);
        $db->table('items')->where('item_id', $taxed)->update(['tax_category_id' => $categoryId]);
        $scheduleId = $this->invoiceSchedule(['issue_mode' => 'issue', 'lines' => [['item_id' => $taxed, 'quantity' => '1', 'unit_price' => '100.00']]]);
        $config     = config(OSPOS::class);
        $before     = $config->settings;

        $config->settings = [...$before, 'use_destination_based_tax' => '1', 'default_tax_code' => $codeId, 'default_tax_category' => $categoryId];

        try {
            $report = $this->generator->run();
        } finally {
            $config->settings = $before;
            $db->table('customers')->where('person_id', $this->customerId)->update(['sales_tax_code_id' => null]);
            $db->table('tax_rates')->where('rate_tax_code_id', $codeId)->delete();
            $db->table('tax_codes')->where('tax_code_id', $codeId)->delete();
            $db->table('tax_jurisdictions')->where('jurisdiction_id', $jurisdictionId)->delete();
        }

        $this->cleanSaleIds();

        $this->assertSame(0, $report->failed());
        $this->assertSame(1, $report->generated());
        $this->assertSame('110.00', $this->balance($this->customerAccount()), 'the tax of the category of the item at the rate of the code of the customer');
    }

    public function testRun_MakesTheExpenseOfALabelTypedInAnotherLanguageThanTheOneTheRunUses(): void
    {
        $language = service('language');
        $original = $language->getLocale();

        try {
            $language->setLocale('ar-EG');
            $arabicCash = lang('Sales.cash');
            $scheduleId = $this->expenseSchedule(['payment_type' => $arabicCash]);
            $language->setLocale('en');

            $report = $this->generator->run();
        } finally {
            $language->setLocale($original);
        }

        $this->assertNotSame(lang('Sales.cash', [], 'en'), $arabicCash, 'the label typed is not the English one');
        $this->assertSame('cash', $this->scheduleRow($scheduleId)->payment_type);
        $this->assertSame(1, $report->generated(), 'a way of payment is kept by its type and not by the name it had when it was typed');
        $this->assertSame(lang('Sales.cash', [], 'en'), $this->accountingDb()->table('expenses')->where('expense_id', $this->runsOf($scheduleId)[0]->expense_id)->get()->getRow()->payment_type);
    }

    public function testRun_LeavesAScheduleThatFailsUnexpectedlyForTheNextRunAndMakesTheOthers(): void
    {
        $broken  = $this->invoiceSchedule(['name' => 'Broken']);
        $expense = $this->expenseSchedule();
        $model   = $this->getMockBuilder(Sale::class)->disableOriginalConstructor()->onlyMethods(['save_value'])->getMock();
        $model->method('save_value')->willThrowException(new RuntimeException('unexpected'));

        try {
            Factories::injectMock('models', Sale::class, $model);
            $report = (new RecurringGenerator($this->accountingDb()))->run();
        } finally {
            Factories::reset('models');
        }

        $this->assertSame(1, $report->failed());
        $this->assertSame(1, $report->generated());
        $failed = array_values(array_filter($report->results, static fn (RunResult $result): bool => $result->outcome === RunResult::FAILED))[0];

        $this->assertSame([$broken, 'unexpected'], [$failed->scheduleId, $failed->failure]);
        $this->assertSame([], $this->runsOf($broken), 'nothing is recorded for a date nobody named a reason for');
        $this->assertSame([0, 'active'], [(int) $this->scheduleRow($broken)->next_occurrence, $this->scheduleRow($broken)->status]);
        $this->assertCount(1, $this->runsOf($expense));

        $again = $this->generator->run();
        $this->cleanSaleIds();

        $this->assertSame(1, $again->generated());
        $this->assertCount(1, $this->runsOf($broken));
    }

    public function testRetry_MakesTheDocumentOfARefusedDateWhenTheCauseIsGone(): void
    {
        $scheduleId = $this->invoiceSchedule(['start_date' => $this->daysAgo(1), 'period_unit' => 'days', 'issue_mode' => 'issue']);
        $this->closeThrough($this->daysAgo(1));
        $this->generator->run();
        $this->cleanSaleIds();

        $refused = $this->runsOf($scheduleId)[0];
        $this->assertSame('refused', $refused->status);

        $this->accountingDb()->table('accounting_closed_periods')->truncate();

        $result = $this->generator->retry($scheduleId, (int) $refused->run_id, $this->manager);
        $this->cleanSaleIds();

        $this->assertSame(RunResult::GENERATED, $result->outcome);
        $row = $this->accountingDb()->table('recurring_runs')->where('run_id', $refused->run_id)->get()->getRow();

        $this->assertSame(['generated', '2'], [$row->status, (string) $row->attempts]);
        $this->assertNull($row->refusal_code);
        $this->assertSame(2, $this->countSales());
    }

    public function testRetry_KeepsTheRefusalWhenTheCauseIsStillThere(): void
    {
        $scheduleId = $this->invoiceSchedule(['start_date' => $this->daysAgo(1), 'period_unit' => 'days', 'issue_mode' => 'issue']);
        $this->closeThrough($this->daysAgo(1));
        $this->generator->run();
        $this->cleanSaleIds();

        $refused = $this->runsOf($scheduleId)[0];
        $result  = $this->generator->retry($scheduleId, (int) $refused->run_id, $this->manager);

        $this->assertSame(RunResult::REFUSED, $result->outcome);
        $row = $this->accountingDb()->table('recurring_runs')->where('run_id', $refused->run_id)->get()->getRow();

        $this->assertSame(['refused', '2'], [$row->status, (string) $row->attempts]);
        $this->assertSame(PostingError::PeriodClosed->value, $row->refusal_code);
    }

    public function testRetry_RefusesADateThatWasMadeAndARunOfAnotherSchedule(): void
    {
        $one = $this->invoiceSchedule();
        $two = $this->invoiceSchedule(['name' => 'Second']);
        $this->generator->run();
        $this->cleanSaleIds();

        $run = $this->runsOf($one)[0];

        try {
            $this->generator->retry($one, (int) $run->run_id, $this->manager);
            $this->fail('A date that was made is not made again.');
        } catch (AccountingException $refusal) {
            $this->assertSame(AccountingError::RecurringRunNotRetryable, $refusal->error);
        }

        try {
            $this->generator->retry($two, (int) $run->run_id, $this->manager);
            $this->fail('A run of another schedule is not found.');
        } catch (AccountingException $refusal) {
            $this->assertSame(AccountingError::RecurringRunNotFound, $refusal->error);
        }

        $this->assertSame(2, $this->countSales());
    }

    public function testRetry_RequiresThePermissionToManageTheScheduleAndItsBranch(): void
    {
        $scheduleId = $this->invoiceSchedule(['start_date' => $this->daysAgo(1), 'period_unit' => 'days']);
        $this->closeThrough($this->daysAgo(1));
        $this->generator->run();
        $this->cleanSaleIds();

        $runId    = (int) $this->runsOf($scheduleId)[0]->run_id;
        $reader   = $this->employeeWithGrants([]);
        $outsider = $this->employeeWithGrants([RecurringKind::Invoice->managePermission()], [$this->createBranch('B3')]);

        foreach ([$reader, $outsider] as $employee) {
            try {
                $this->generator->retry($scheduleId, $runId, $employee);
                $this->fail('The retry should have been refused.');
            } catch (AccountingException $refusal) {
                $this->assertContains($refusal->error, [AccountingError::PermissionDenied, AccountingError::RecurringNotFound]);
            }
        }
    }
}
