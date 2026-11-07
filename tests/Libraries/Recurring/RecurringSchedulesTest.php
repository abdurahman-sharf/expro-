<?php

namespace Tests\Libraries\Recurring;

use App\Libraries\Accounting\AccountingError;
use App\Libraries\Accounting\AccountingException;
use App\Libraries\Organization\BranchError;
use App\Libraries\Organization\BranchException;
use App\Libraries\Recurring\RecurringGenerator;
use App\Libraries\Recurring\RecurringKind;
use App\Libraries\Recurring\RecurringSchedules;
use App\Libraries\Recurring\ScheduleStatus;
use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\DatabaseTestTrait;
use Config\Database;
use Tests\Support\AccountingFixtureTrait;
use Tests\Support\EmployeeFixtureTrait;
use Tests\Support\ExpenseAccountingFixtureTrait;
use Tests\Support\ItemFixtureTrait;
use Tests\Support\SaleAccountingFixtureTrait;

/**
 * The schedules themselves: who may make and change them, what is refused when they are written, that the
 * start and the period stay once a date was made, how they are paused, resumed and ended, and which of
 * them the branches of an employee let them see.
 *
 * @internal
 */
final class RecurringSchedulesTest extends CIUnitTestCase
{
    use DatabaseTestTrait;
    use AccountingFixtureTrait;
    use EmployeeFixtureTrait;
    use ExpenseAccountingFixtureTrait;
    use ItemFixtureTrait;
    use SaleAccountingFixtureTrait;

    protected $migrate     = true;
    protected $migrateOnce = true;
    protected $refresh     = false;
    protected $namespace;
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

        $this->startExpenses();
        $this->startSelling();
        $this->allowEmployeeEverywhere();
        $this->clearSchedules();

        $this->schedules = new RecurringSchedules($this->accountingDb());
        $this->manager   = $this->employeeWithGrants([RecurringKind::Invoice->managePermission(), RecurringKind::Expense->managePermission()]);
        $this->service   = $this->item('0', false);
    }

    protected function tearDown(): void
    {
        $this->releaseOpenTransactions();
        $this->clearSchedules();
        $this->accountingDb()->table('accounting_audit_log')->whereIn('entity_type', ['recurring_invoice', 'recurring_expense'])->delete();

        foreach ($this->accountingDb()->table('sales')->select('sale_id')->get()->getResultArray() as $row) {
            $this->saleIds[] = (int) $row['sale_id'];
        }

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
            'max_repeats'        => '',
            'issue_before_days'  => '0',
            'customer_id'        => $this->customerId,
            'location_id'        => self::LOCATION_ID,
            'payment_terms_days' => '',
            'issue_mode'         => 'draft',
            'comment'            => 'support',
            'lines'              => [['item_id' => $this->service, 'quantity' => '2', 'unit_price' => '50.00']],
            'reminders'          => [['send_when' => '1', 'days' => ''], ['send_when' => '3', 'days' => '2']],
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
            'expense_category_id' => $this->category('Rent'),
            'amount'              => '230.00',
            'tax_amount'          => '30.00',
            'payment_type'        => lang('Sales.cash'),
        ], $override);
    }

    /**
     * @param array<string, mixed> $override
     */
    private function invoiceSchedule(array $override = [], ?int $by = null, ?string $today = null): int
    {
        $input = $this->invoiceInput($override);

        return $this->schedules->create(RecurringKind::Invoice, $input, $by ?? $this->manager, $today ?? min((string) $input['start_date'], date('Y-m-d')));
    }

    private function assertRefused(AccountingError $error, callable $action): void
    {
        try {
            $action();
        } catch (AccountingException $refusal) {
            $this->assertSame($error, $refusal->error);

            return;
        }

        $this->fail('The change should have been refused with ' . $error->value);
    }

    private function row(int $scheduleId): object
    {
        return $this->accountingDb()->table('recurring_schedules')->where('schedule_id', $scheduleId)->get()->getRow();
    }

    /**
     * @return list<string>
     */
    private function auditOf(int $scheduleId, string $entity = 'recurring_invoice'): array
    {
        return array_column($this->accountingDb()->table('accounting_audit_log')->where(['entity_type' => $entity, 'entity_id' => $scheduleId])->orderBy('audit_id', 'ASC')->get()->getResultArray(), 'action');
    }

    public function testCreate_StoresTheScheduleItsLinesAndItsRemindersAndPostsNothing(): void
    {
        $scheduleId = $this->invoiceSchedule(['payment_terms_days' => '30', 'issue_mode' => 'issue', 'issue_before_days' => '5', 'max_repeats' => '12']);
        $row        = $this->row($scheduleId);

        $this->assertSame(['invoice', 'active', 'Monthly support', '1', 'months', '12', '5', '30', 'issue'], [$row->kind, $row->status, $row->name, (string) $row->period_count, $row->period_unit, (string) $row->max_repeats, (string) $row->issue_before_days, (string) $row->payment_terms_days, $row->issue_mode]);
        $this->assertSame(date('Y-m-d'), $row->next_due_date);
        $this->assertSame(0, (int) $row->next_occurrence);
        $this->assertSame($this->manager, (int) $row->employee_id);
        $this->assertSame($this->customerId, (int) $row->customer_id);
        $this->assertCount(1, $this->schedules->lines($scheduleId));
        $this->assertSame([[1, null], [3, 2]], array_map(static fn (object $reminder): array => [(int) $reminder->send_when, $reminder->days === null ? null : (int) $reminder->days], $this->schedules->reminders($scheduleId)));
        $this->assertSame(0, $this->countSales());
        $this->assertSame(0, $this->countJournals());
        $this->assertSame(0, $this->countRows('recurring_runs'));
    }

    public function testCreate_PutsAScheduleThatIsNotActiveInPause(): void
    {
        $scheduleId = $this->invoiceSchedule(['active' => '0']);

        $this->assertSame('paused', $this->row($scheduleId)->status);
    }

    public function testCreate_TakesTheBranchOfTheStockLocationAndWritesTheAuditLog(): void
    {
        $other    = $this->createBranch('B2');
        $location = $this->branchLocation($other);
        $worker   = $this->employeeWithGrants([RecurringKind::Invoice->managePermission()], [$other]);

        $scheduleId = $this->invoiceSchedule(['location_id' => $location], $worker);

        $this->assertSame($other, (int) $this->row($scheduleId)->branch_id);
        $this->assertSame(['created'], $this->auditOf($scheduleId));
    }

    public function testCreate_WritesNoAuditEntryWhileTheBooksAreOff(): void
    {
        $this->setConfigValue('accounting_enabled', '0');

        $scheduleId = $this->invoiceSchedule();

        $this->assertSame([], $this->auditOf($scheduleId));
    }

    public function testCreate_RequiresThePermissionOfItsKindAndAnEmployee(): void
    {
        $invoiceOnly = $this->employeeWithGrants([RecurringKind::Invoice->managePermission()]);
        $reader      = $this->employeeWithGrants([]);

        $this->assertRefused(AccountingError::PermissionDenied, fn () => $this->schedules->create(RecurringKind::Expense, $this->expenseInput(), $invoiceOnly));
        $this->assertRefused(AccountingError::PermissionDenied, fn () => $this->schedules->create(RecurringKind::Invoice, $this->invoiceInput(), $reader));
        $this->assertRefused(AccountingError::PermissionDenied, fn () => $this->schedules->create(RecurringKind::Invoice, $this->invoiceInput(), null));
        $this->assertSame(0, $this->countRows('recurring_schedules'));
    }

    public function testCreate_RefusesAnEmployeeOfAnotherBranchThanTheStockLocation(): void
    {
        $other  = $this->createBranch('B2');
        $worker = $this->employeeWithGrants([RecurringKind::Invoice->managePermission()], [$other]);

        try {
            $this->schedules->create(RecurringKind::Invoice, $this->invoiceInput(), $worker);
            $this->fail('The branch of the stock location is outside the branches of the employee.');
        } catch (BranchException $refusal) {
            $this->assertSame(BranchError::NotAllowed, $refusal->error);
        }

        $this->assertSame(0, $this->countRows('recurring_schedules'));
    }

    public function testCreate_RequiresANameOfAtMost255Characters(): void
    {
        $this->assertRefused(AccountingError::RecurringNameInvalid, fn () => $this->invoiceSchedule(['name' => '   ']));
        $this->assertRefused(AccountingError::RecurringNameInvalid, fn () => $this->invoiceSchedule(['name' => str_repeat('a', 256)]));
        $this->assertNotNull($this->invoiceSchedule(['name' => str_repeat('a', 255)]));
    }

    public function testCreate_RefusesAFirstDateThatHasPassedAndAcceptsTodayAndTheFuture(): void
    {
        $this->assertRefused(AccountingError::RecurringStartInPast, fn () => $this->invoiceSchedule(['start_date' => $this->daysAgo(1)], null, date('Y-m-d')));
        $this->assertNotNull($this->invoiceSchedule(['start_date' => date('Y-m-d')], null, date('Y-m-d')));
        $this->assertNotNull($this->invoiceSchedule(['start_date' => date('Y-m-d', strtotime('+40 days'))], null, date('Y-m-d')));
    }

    public function testCreate_RefusesWhatTheTermsRefuse(): void
    {
        $this->assertRefused(AccountingError::RecurringPeriodInvalid, fn () => $this->invoiceSchedule(['period_count' => '0']));
        $this->assertRefused(AccountingError::RecurringIssueBeforeInvalid, fn () => $this->invoiceSchedule(['period_count' => '1', 'period_unit' => 'months', 'issue_before_days' => '30']));
        $this->assertRefused(AccountingError::RecurringMaxRepeatInvalid, fn () => $this->invoiceSchedule(['max_repeats' => '-2']));
        $this->assertRefused(AccountingError::RecurringEndBeforeStart, fn () => $this->invoiceSchedule(['end_date' => $this->daysAgo(2)], null, date('Y-m-d')));
    }

    public function testCreate_RefusesAnInvoiceTemplateThatIsNotComplete(): void
    {
        $this->assertRefused(AccountingError::RecurringCustomerInvalid, fn () => $this->invoiceSchedule(['customer_id' => 999999]));
        $this->assertRefused(AccountingError::RecurringLocationInvalid, fn () => $this->invoiceSchedule(['location_id' => 999999]));
        $this->assertRefused(AccountingError::RecurringNoLines, fn () => $this->invoiceSchedule(['lines' => []]));
        $this->assertRefused(AccountingError::RecurringLineInvalid, fn () => $this->invoiceSchedule(['lines' => [['item_id' => $this->service, 'quantity' => '0', 'unit_price' => '5']]]));
        $this->assertRefused(AccountingError::RecurringLineInvalid, fn () => $this->invoiceSchedule(['lines' => [['item_id' => 999999, 'quantity' => '1', 'unit_price' => '5']]]));
        $this->assertRefused(AccountingError::PaymentTermsInvalid, fn () => $this->invoiceSchedule(['payment_terms_days' => '-3']));
        $this->assertSame(0, $this->countRows('recurring_schedules'));
    }

    public function testCreate_AcceptsAtMostTenRemindersAndTheDaysOnlyForBeforeAndAfter(): void
    {
        $ten    = array_fill(0, 10, ['send_when' => '0', 'days' => '']);
        $eleven = array_fill(0, 11, ['send_when' => '0', 'days' => '']);

        $this->assertNotNull($this->invoiceSchedule(['reminders' => $ten]));
        $this->assertRefused(AccountingError::RecurringRemindersInvalid, fn () => $this->invoiceSchedule(['reminders' => $eleven]));
        $this->assertRefused(AccountingError::RecurringRemindersInvalid, fn () => $this->invoiceSchedule(['reminders' => [['send_when' => '3', 'days' => '']]]));
        $this->assertRefused(AccountingError::RecurringRemindersInvalid, fn () => $this->invoiceSchedule(['reminders' => [['send_when' => '4', 'days' => '-1']]]));
        $this->assertRefused(AccountingError::RecurringRemindersInvalid, fn () => $this->invoiceSchedule(['reminders' => [['send_when' => '5', 'days' => '']]]));
        $this->assertRefused(AccountingError::RecurringRemindersInvalid, fn () => $this->invoiceSchedule(['reminders' => 'soon']));
    }

    public function testCreate_DropsTheDaysOfAReminderThatIsOnADateAndSkipsEmptyRows(): void
    {
        $scheduleId = $this->invoiceSchedule(['reminders' => [['send_when' => '0', 'days' => '7'], ['send_when' => '', 'days' => ''], ['send_when' => '4', 'days' => '0']]]);

        $this->assertSame([[0, null], [4, 0]], array_map(static fn (object $reminder): array => [(int) $reminder->send_when, $reminder->days === null ? null : (int) $reminder->days], $this->schedules->reminders($scheduleId)));
    }

    public function testCreate_StoresAnExpenseScheduleWithItsTemplateAndTheBranchOfTheEmployee(): void
    {
        $scheduleId = $this->schedules->create(RecurringKind::Expense, $this->expenseInput(['frequency' => '3_months']), $this->manager);
        $row        = $this->row($scheduleId);

        $this->assertSame(['expense', '3', 'months', '230.00', '30.00', lang('Sales.cash'), null, null], [$row->kind, (string) $row->period_count, $row->period_unit, $row->amount, $row->tax_amount, $row->payment_type, $row->customer_id, $row->issue_mode]);
        $this->assertSame([], $this->schedules->lines($scheduleId));
        $this->assertSame(['created'], $this->auditOf($scheduleId, 'recurring_expense'));
    }

    public function testCreate_RefusesAnExpenseTemplateThatIsNotComplete(): void
    {
        $create = fn (array $override) => $this->schedules->create(RecurringKind::Expense, $this->expenseInput($override), $this->manager);

        $this->assertRefused(AccountingError::RecurringFrequencyInvalid, static fn () => $create(['frequency' => 'daily']));
        $this->assertRefused(AccountingError::RecurringExpenseInvalid, static fn () => $create(['expense_category_id' => 999999]));
        $this->assertRefused(AccountingError::RecurringExpenseInvalid, static fn () => $create(['amount' => '0']));
        $this->assertRefused(AccountingError::RecurringExpenseInvalid, static fn () => $create(['amount' => '-5']));
        $this->assertRefused(AccountingError::RecurringExpenseInvalid, static fn () => $create(['amount' => 'ten']));
        $this->assertRefused(AccountingError::RecurringExpenseInvalid, static fn () => $create(['tax_amount' => '231']));
        $this->assertRefused(AccountingError::RecurringExpenseInvalid, static fn () => $create(['payment_type' => 'Barter']));
        $this->assertRefused(AccountingError::RecurringExpenseInvalid, static fn () => $create(['supplier_id' => 999999]));
        $this->assertRefused(AccountingError::RecurringExpenseInvalid, static fn () => $create(['treasury_id' => 999999]));
        $this->assertSame(0, $this->countRows('recurring_schedules'));
    }

    public function testCreate_AcceptsATaxEqualToTheAmountAndNoTax(): void
    {
        $this->assertNotNull($this->schedules->create(RecurringKind::Expense, $this->expenseInput(['tax_amount' => '230.00']), $this->manager));
        $this->assertNotNull($this->schedules->create(RecurringKind::Expense, $this->expenseInput(['tax_amount' => '']), $this->manager));
    }

    public function testUpdate_ChangesTheNameTheLinesTheRemindersAndTheEnd(): void
    {
        $scheduleId = $this->invoiceSchedule();

        $this->schedules->update($scheduleId, $this->invoiceInput(['name' => 'Renamed', 'end_date' => date('Y-m-d', strtotime('+90 days')), 'lines' => [['item_id' => $this->service, 'quantity' => '5', 'unit_price' => '10.00'], ['item_id' => $this->service, 'quantity' => '1', 'unit_price' => '3.00']], 'reminders' => [['send_when' => '2', 'days' => '']]]), $this->manager);

        $this->assertSame('Renamed', $this->row($scheduleId)->name);
        $this->assertCount(2, $this->schedules->lines($scheduleId));
        $this->assertCount(1, $this->schedules->reminders($scheduleId));
        $this->assertSame(['created', 'updated'], $this->auditOf($scheduleId));
    }

    public function testUpdate_MayMoveTheStartAndThePeriodWhileNoDateWasMade(): void
    {
        $scheduleId = $this->invoiceSchedule();
        $later      = date('Y-m-d', strtotime('+10 days'));

        $this->schedules->update($scheduleId, $this->invoiceInput(['start_date' => $later, 'period_count' => '2', 'period_unit' => 'weeks']), $this->manager);

        $row = $this->row($scheduleId);

        $this->assertSame([$later, '2', 'weeks', $later], [$row->start_date, (string) $row->period_count, $row->period_unit, $row->next_due_date]);
    }

    public function testUpdate_RefusesToMoveTheStartOrThePeriodOnceADateWasMade(): void
    {
        $scheduleId = $this->invoiceSchedule();
        (new RecurringGenerator($this->accountingDb()))->run();

        $this->assertRefused(AccountingError::RecurringTermsLocked, fn () => $this->schedules->update($scheduleId, $this->invoiceInput(['period_count' => '2']), $this->manager));
        $this->assertRefused(AccountingError::RecurringTermsLocked, fn () => $this->schedules->update($scheduleId, $this->invoiceInput(['period_unit' => 'years']), $this->manager));
        $this->assertRefused(AccountingError::RecurringTermsLocked, fn () => $this->schedules->update($scheduleId, $this->invoiceInput(['start_date' => date('Y-m-d', strtotime('+1 day'))]), $this->manager));

        $this->schedules->update($scheduleId, $this->invoiceInput(['name' => 'Still changeable', 'max_repeats' => '5']), $this->manager);

        $this->assertSame('Still changeable', $this->row($scheduleId)->name);
        $this->assertSame(1, (int) $this->row($scheduleId)->next_occurrence);
    }

    public function testUpdate_EndsAScheduleWhoseEndDayCameBeforeItsNextDate(): void
    {
        $scheduleId = $this->invoiceSchedule(['period_unit' => 'days']);
        (new RecurringGenerator($this->accountingDb()))->run();

        $this->assertSame('active', $this->row($scheduleId)->status);

        $this->schedules->update($scheduleId, $this->invoiceInput(['period_unit' => 'days', 'end_date' => date('Y-m-d')]), $this->manager);

        $row = $this->row($scheduleId);

        $this->assertSame(['ended', 'end_date', null], [$row->status, $row->ended_reason, $row->next_due_date]);
    }

    public function testUpdate_RefusesAnEndedScheduleAndOneThatDoesNotExist(): void
    {
        $scheduleId = $this->invoiceSchedule();
        $this->schedules->changeStatus($scheduleId, ScheduleStatus::Ended, $this->manager);

        $this->assertRefused(AccountingError::RecurringEnded, fn () => $this->schedules->update($scheduleId, $this->invoiceInput(), $this->manager));
        $this->assertRefused(AccountingError::RecurringNotFound, fn () => $this->schedules->update(999999, $this->invoiceInput(), $this->manager));
    }

    public function testUpdate_RequiresThePermissionAndTheBranchOfTheSchedule(): void
    {
        $scheduleId = $this->invoiceSchedule();
        $reader     = $this->employeeWithGrants([]);
        $outsider   = $this->employeeWithGrants([RecurringKind::Invoice->managePermission()], [$this->createBranch('B3')]);

        $this->assertRefused(AccountingError::PermissionDenied, fn () => $this->schedules->update($scheduleId, $this->invoiceInput(['name' => 'Hijacked']), $reader));

        try {
            $this->schedules->update($scheduleId, $this->invoiceInput(['name' => 'Hijacked']), $outsider);
            $this->fail('An employee of another branch does not change the schedule.');
        } catch (AccountingException|BranchException $refusal) {
            $this->assertNotSame('Hijacked', $this->row($scheduleId)->name);
        }
    }

    public function testChangeStatus_PausesResumesAndEndsAndRefusesToReviveAnEndedSchedule(): void
    {
        $scheduleId = $this->invoiceSchedule();

        $this->assertSame(ScheduleStatus::Paused, $this->schedules->changeStatus($scheduleId, ScheduleStatus::Paused, $this->manager));
        $this->assertSame(ScheduleStatus::Active, $this->schedules->changeStatus($scheduleId, ScheduleStatus::Active, $this->manager));
        $this->assertSame(ScheduleStatus::Ended, $this->schedules->changeStatus($scheduleId, ScheduleStatus::Ended, $this->manager));
        $this->assertSame(['ended', 'manual', null], [$this->row($scheduleId)->status, $this->row($scheduleId)->ended_reason, $this->row($scheduleId)->next_due_date]);

        $this->assertRefused(AccountingError::RecurringStatusNotAllowed, fn () => $this->schedules->changeStatus($scheduleId, ScheduleStatus::Active, $this->manager));
        $this->assertRefused(AccountingError::RecurringStatusNotAllowed, fn () => $this->schedules->changeStatus($scheduleId, ScheduleStatus::Paused, $this->manager));
        $this->assertSame(ScheduleStatus::Ended, $this->schedules->changeStatus($scheduleId, ScheduleStatus::Ended, $this->manager));
        $this->assertSame(['created', 'status_changed', 'status_changed', 'status_changed'], $this->auditOf($scheduleId));
    }

    public function testChangeStatus_ResumesAtTheFirstDateThatIsTodayOrLater(): void
    {
        $scheduleId = $this->invoiceSchedule(['start_date' => $this->daysAgo(5), 'period_unit' => 'days']);

        $this->schedules->changeStatus($scheduleId, ScheduleStatus::Paused, $this->manager);
        $this->schedules->changeStatus($scheduleId, ScheduleStatus::Active, $this->manager);

        $row = $this->row($scheduleId);

        $this->assertSame([date('Y-m-d'), 5], [$row->next_due_date, (int) $row->next_occurrence]);
    }

    public function testChangeStatus_EndsAScheduleThatHasNoDateLeftWhenItIsResumed(): void
    {
        $scheduleId = $this->invoiceSchedule(['start_date' => $this->daysAgo(5), 'period_unit' => 'days', 'end_date' => $this->daysAgo(2)]);

        $this->schedules->changeStatus($scheduleId, ScheduleStatus::Paused, $this->manager);
        $this->assertSame(ScheduleStatus::Ended, $this->schedules->changeStatus($scheduleId, ScheduleStatus::Active, $this->manager));
        $this->assertSame('end_date', $this->row($scheduleId)->ended_reason);
    }

    public function testChangeStatus_RequiresThePermissionOfTheKindAndKeepsTheOtherBranchesOut(): void
    {
        $scheduleId = $this->invoiceSchedule();
        $expenses   = $this->employeeWithGrants([RecurringKind::Expense->managePermission()]);
        $outsider   = $this->employeeWithGrants([RecurringKind::Invoice->managePermission()], [$this->createBranch('B3')]);

        $this->assertRefused(AccountingError::PermissionDenied, fn () => $this->schedules->changeStatus($scheduleId, ScheduleStatus::Paused, $expenses));
        $this->assertRefused(AccountingError::RecurringNotFound, fn () => $this->schedules->changeStatus($scheduleId, ScheduleStatus::Paused, $outsider));
        $this->assertRefused(AccountingError::RecurringNotFound, fn () => $this->schedules->changeStatus(999999, ScheduleStatus::Paused, $this->manager));
        $this->assertSame('active', $this->row($scheduleId)->status);
    }

    public function testFind_ShowsOnlyTheSchedulesOfTheBranchesOfTheViewer(): void
    {
        $other    = $this->createBranch('B2');
        $location = $this->branchLocation($other);
        $worker   = $this->employeeWithGrants([RecurringKind::Invoice->managePermission()], [$other]);
        $mine     = $this->invoiceSchedule(['name' => 'Mine']);
        $theirs   = $this->invoiceSchedule(['name' => 'Theirs', 'location_id' => $location], $worker);

        $this->assertNotNull($this->schedules->find($mine, $this->manager));
        $this->assertNotNull($this->schedules->find($theirs, $this->manager));
        $this->assertNull($this->schedules->find($mine, $worker));
        $this->assertNotNull($this->schedules->find($theirs, $worker));
        $this->assertSame([$theirs], array_map(static fn (object $row): int => (int) $row->schedule_id, $this->schedules->search(RecurringKind::Invoice, [], $worker)['rows']));
        $this->assertSame(2, $this->schedules->search(RecurringKind::Invoice, [], $this->manager)['total']);
        $this->assertSame(0, $this->schedules->search(RecurringKind::Invoice, [], $this->employeeWithGrants([], [$this->createBranch('B3')]))['total']);
    }

    public function testSearch_FiltersByStatusNameAndCustomerAndKeepsTheKindsApart(): void
    {
        $one = $this->invoiceSchedule(['name' => 'Alpha support']);
        $two = $this->invoiceSchedule(['name' => 'Beta hosting']);
        $this->schedules->changeStatus($two, ScheduleStatus::Paused, $this->manager);
        $expense = $this->schedules->create(RecurringKind::Expense, $this->expenseInput(), $this->manager);

        $ids = static fn (array $result): array => array_map(static fn (object $row): int => (int) $row->schedule_id, $result['rows']);

        $this->assertSame([$one], $ids($this->schedules->search(RecurringKind::Invoice, ['status' => 'active'], $this->manager)));
        $this->assertSame([$two], $ids($this->schedules->search(RecurringKind::Invoice, ['status' => 'paused'], $this->manager)));
        $this->assertSame([$two], $ids($this->schedules->search(RecurringKind::Invoice, ['search' => 'hosting'], $this->manager)));
        $this->assertSame([$two, $one], $ids($this->schedules->search(RecurringKind::Invoice, ['search' => 'Omar'], $this->manager)));
        $this->assertSame([$expense], $ids($this->schedules->search(RecurringKind::Expense, [], $this->manager)));
    }

    public function testSearch_CountsWhatEachScheduleMadeAndWhatWasRefused(): void
    {
        $scheduleId = $this->invoiceSchedule(['start_date' => $this->daysAgo(2), 'period_unit' => 'days', 'issue_mode' => 'issue']);
        $this->closeThrough($this->daysAgo(1));
        (new RecurringGenerator($this->accountingDb()))->run();

        $row = $this->schedules->search(RecurringKind::Invoice, [], $this->manager)['rows'][0];

        $this->assertSame($scheduleId, (int) $row->schedule_id);
        $this->assertSame([1, 2], [(int) $row->generated_count, (int) $row->refused_count]);
    }

    public function testRuns_NamesTheRefusalOfADateInWords(): void
    {
        $scheduleId = $this->invoiceSchedule(['start_date' => $this->daysAgo(1), 'period_unit' => 'days', 'issue_mode' => 'issue']);
        $this->closeThrough($this->daysAgo(1));
        (new RecurringGenerator($this->accountingDb()))->run();

        $runs = $this->schedules->runs($scheduleId);

        $this->assertSame(['generated', 'refused'], array_column($runs, 'status'));
        $this->assertSame('', $runs[0]->refusal_words);
        $this->assertStringContainsString($this->daysAgo(1), $runs[1]->refusal_words);
    }

    public function testUpcoming_ListsTheNextDatesAndNoneForAnEndedSchedule(): void
    {
        $scheduleId = $this->invoiceSchedule(['start_date' => '2099-01-31']);
        $schedule   = $this->schedules->find($scheduleId, $this->manager);

        $this->assertSame(['2099-01-31', '2099-02-28', '2099-03-31'], $this->schedules->upcoming($schedule, 3));

        $this->schedules->changeStatus($scheduleId, ScheduleStatus::Ended, $this->manager);

        $this->assertSame([], $this->schedules->upcoming($this->schedules->find($scheduleId, $this->manager)));
    }

    public function testUpcoming_StopsAtTheLastDateOfASchedule(): void
    {
        $scheduleId = $this->invoiceSchedule(['start_date' => '2099-01-31', 'max_repeats' => '2']);

        $this->assertSame(['2099-01-31', '2099-02-28'], $this->schedules->upcoming($this->schedules->find($scheduleId, $this->manager), 5));
    }

    public function testKindOf_RefusesAScheduleThatDoesNotExist(): void
    {
        $this->assertRefused(AccountingError::RecurringNotFound, fn () => $this->schedules->kindOf(999999));
        $this->assertSame(RecurringKind::Invoice, $this->schedules->kindOf($this->invoiceSchedule()));
    }
}
