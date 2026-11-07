<?php

namespace Tests\Controllers;

use App\Libraries\Recurring\RecurrencePhrase;
use App\Libraries\Recurring\RecurrenceUnit;
use App\Libraries\Recurring\RecurringGenerator;
use App\Libraries\Recurring\RecurringKind;
use App\Libraries\Recurring\RecurringSchedules;
use App\Libraries\Recurring\ScheduleStatus;
use CodeIgniter\Exceptions\PageNotFoundException;
use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\DatabaseTestTrait;
use CodeIgniter\Test\FeatureTestTrait;
use Config\Database;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Support\AccountingFixtureTrait;
use Tests\Support\EmployeeFixtureTrait;
use Tests\Support\ItemFixtureTrait;
use Tests\Support\SaleAccountingFixtureTrait;

/**
 * The screens of the recurring invoices: who may open and change them, the list with its reminders and
 * the page of a schedule with the history of its dates, that every change is a POST the service may refuse
 * in words, that a schedule is reached only through the module of its kind, and the branches of the employee.
 */
class Recurring_invoicesControllerTest extends CIUnitTestCase
{
    use DatabaseTestTrait;
    use FeatureTestTrait;
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
     * @param list<string>   $permissions
     * @param list<int>|null $branchIds
     */
    private function actAsWith(array $permissions, ?array $branchIds = null): int
    {
        $personId = $this->employeeWithGrants(['recurring_invoices', ...$permissions], $branchIds);

        $this->withSession(['person_id' => $personId, 'menu_group' => 'home']);

        return $personId;
    }

    /**
     * @param array<string, mixed> $override
     *
     * @return array<string, mixed>
     */
    private function input(array $override = []): array
    {
        return array_merge([
            'name'               => 'Monthly support',
            'period_count'       => '1',
            'period_unit'        => 'months',
            'start_date'         => date('Y-m-d'),
            'end_date'           => '',
            'max_repeats'        => '0',
            'issue_before_days'  => '0',
            'customer_id'        => (string) $this->customerId,
            'location_id'        => (string) self::LOCATION_ID,
            'payment_terms_days' => '',
            'issue_mode'         => 'draft',
            'comment'            => '',
            'active'             => '1',
            'lines'              => [['item_id' => (string) $this->service, 'quantity' => '2', 'unit_price' => '50.00', 'discount' => '0', 'discount_type' => '0', 'description' => '']],
            'reminders'          => [['send_when' => '1', 'days' => '']],
        ], $override);
    }

    /**
     * @param array<string, mixed> $override
     */
    private function schedule(array $override = [], ?string $today = null): int
    {
        $input = $this->input($override);

        return $this->schedules->create(RecurringKind::Invoice, $input, $this->manager, $today ?? min((string) $input['start_date'], date('Y-m-d')));
    }

    /**
     * @return array<string, mixed>
     */
    private function postJson(string $url, array $data = []): array
    {
        return json_decode($this->post($url, $data)->getJSON(), true);
    }

    public function testGetIndex_ListsTheSchedulesAndOffersTheNewOneToWhoMayManageThem(): void
    {
        $scheduleId = $this->schedule();

        $this->actAsWith([RecurringKind::Invoice->managePermission()]);

        $page = $this->get('/recurring_invoices');

        $page->assertStatus(200);
        $page->assertSee('id="schedule_' . $scheduleId . '"');
        $page->assertSee('id="schedule_new"');
        $page->assertSee(lang('Recurring.status_active'));
    }

    public function testGetIndex_ShowsWhoMayOnlyLookTheListWithoutTheNewButton(): void
    {
        $scheduleId = $this->schedule();

        $this->actAsWith([RecurringKind::Invoice->viewPermission()]);

        $page = $this->get('/recurring_invoices');

        $page->assertStatus(200);
        $page->assertSee('id="schedule_' . $scheduleId . '"');
        $page->assertDontSee('id="schedule_new"');
    }

    public function testGetIndex_IsNotOpenedByAnEmployeeWithoutTheModuleGrant(): void
    {
        $personId = $this->employeeWithGrants([RecurringKind::Expense->managePermission()]);
        $this->withSession(['person_id' => $personId, 'menu_group' => 'home']);

        $page = $this->get('/recurring_invoices');

        $page->assertRedirect();
        $this->assertStringContainsString('no_access/recurring_invoices', $page->getRedirectUrl());
    }

    public function testGetIndex_FiltersByStatusAndKeepsTheExpensesOut(): void
    {
        $active = $this->schedule(['name' => 'Alpha']);
        $paused = $this->schedule(['name' => 'Beta']);
        $this->schedules->changeStatus($paused, ScheduleStatus::Paused, $this->manager);

        $this->actAsWith([RecurringKind::Invoice->viewPermission()]);

        $page = $this->get('/recurring_invoices?status=paused');

        $page->assertSee('id="schedule_' . $paused . '"');
        $page->assertDontSee('id="schedule_' . $active . '"');
    }

    public function testGetIndex_ShowsOnlyTheSchedulesOfTheBranchesOfTheEmployee(): void
    {
        $other    = $this->createBranch('B2');
        $location = $this->branchLocation($other);
        $worker   = $this->employeeWithGrants([RecurringKind::Invoice->managePermission()], [$other]);
        $mine     = $this->schedule(['name' => 'Mine']);
        $theirs   = $this->schedules->create(RecurringKind::Invoice, $this->input(['name' => 'Theirs', 'location_id' => (string) $location]), $worker);

        $this->actAsWith([RecurringKind::Invoice->viewPermission()], [$other]);

        $page = $this->get('/recurring_invoices');

        $page->assertSee('id="schedule_' . $theirs . '"');
        $page->assertDontSee('id="schedule_' . $mine . '"');
    }

    public function testGetIndex_ShowsTheRemindersWhoseDayHasComeAndSaysNothingIsSent(): void
    {
        $this->schedule(['name' => 'Support', 'reminders' => [['send_when' => '1', 'days' => '']]]);

        $this->actAsWith([RecurringKind::Invoice->viewPermission()]);

        $page = $this->get('/recurring_invoices');

        $page->assertSee('id="schedule_reminders"');
        $page->assertSee(lang('Recurring.reminders_in_app_only'));
    }

    public function testGetIndex_ShowsNoReminderOfInvoicesOnTheListOfExpenses(): void
    {
        $this->schedule(['name' => 'Support', 'reminders' => [['send_when' => '1', 'days' => '']]]);

        $this->actAsWith(['recurring_expenses', RecurringKind::Expense->viewPermission()]);

        $this->get('/recurring_expenses')->assertDontSee('id="schedule_reminders"');
    }

    public function testGetIndex_ShowsNoReminderWhoseDayHasNotComeYet(): void
    {
        $this->schedule(['start_date' => date('Y-m-d', strtotime('+20 days')), 'reminders' => [['send_when' => '3', 'days' => '2']]]);

        $this->actAsWith([RecurringKind::Invoice->viewPermission()]);

        $this->get('/recurring_invoices')->assertDontSee('id="schedule_reminders"');
    }

    public function testGetForm_OffersTheFormToWhoMayManageAndHidesItFromWhoMayNot(): void
    {
        $this->actAsWith([RecurringKind::Invoice->managePermission()]);

        $this->get('/recurring_invoices/add')->assertSee('id="schedule_form"');

        $this->actAsWith([RecurringKind::Invoice->viewPermission()]);
        $this->expectException(PageNotFoundException::class);

        $this->get('/recurring_invoices/add');
    }

    public function testGetForm_OpensASchedulePausedForEditingAndNotAnEndedOne(): void
    {
        $scheduleId = $this->schedule();

        $this->actAsWith([RecurringKind::Invoice->managePermission()]);

        $this->get('/recurring_invoices/edit/' . $scheduleId)->assertSee('id="schedule_form"');

        $this->schedules->changeStatus($scheduleId, ScheduleStatus::Ended, $this->manager);
        $this->expectException(PageNotFoundException::class);

        $this->get('/recurring_invoices/edit/' . $scheduleId);
    }

    public function testGetForm_ShowsTheTermsAsLockedOnceADateWasMade(): void
    {
        $scheduleId = $this->schedule();
        (new RecurringGenerator($this->accountingDb()))->run();

        $this->actAsWith([RecurringKind::Invoice->managePermission()]);

        $this->get('/recurring_invoices/edit/' . $scheduleId)->assertSee('id="schedule_locked"');
    }

    public function testGetForm_SelectsTheStockLocationOfTheScheduleWhenTheEmployeeWorksFromSeveral(): void
    {
        $db    = $this->accountingDb();
        $stock = $this->branchLocation($this->createBranch('B2'), 'Second store');
        $db->table('permissions')->insert(['permission_id' => 'sales_second_store', 'module_id' => 'sales', 'location_id' => $stock]);
        $scheduleId = $this->schedule(['location_id' => (string) $stock]);

        try {
            $personId = $this->actAsWith([RecurringKind::Invoice->managePermission()]);

            foreach (['sales_stock', 'sales_second_store'] as $permission) {
                $db->table('grants')->insert(['permission_id' => $permission, 'person_id' => $personId, 'menu_group' => 'home']);
            }

            $body = $this->get('/recurring_invoices/edit/' . $scheduleId)->getBody();
        } finally {
            $db->table('grants')->where('permission_id', 'sales_second_store')->delete();
            $db->table('permissions')->where('permission_id', 'sales_second_store')->delete();
        }

        $this->assertStringContainsString('<option value="' . $stock . '" selected>', $body, 'the stock location of the schedule, and not the first one the employee may sell from');
        $this->assertStringNotContainsString('<option value="' . self::LOCATION_ID . '" selected>', $body);
    }

    public function testGetForm_IsNotFoundForAScheduleOfAnotherKindInEitherModule(): void
    {
        $expense = $this->schedules->create(RecurringKind::Expense, ['name' => 'Rent', 'frequency' => 'monthly', 'start_date' => date('Y-m-d'), 'expense_category_id' => $this->categoryOf(), 'amount' => '10', 'tax_amount' => '0', 'payment_type' => 'cash'], $this->manager);
        $invoice = $this->schedule();

        $this->actAsWith([RecurringKind::Invoice->managePermission(), RecurringKind::Expense->managePermission(), 'recurring_expenses']);

        foreach (['/recurring_invoices/edit/' . $expense, '/recurring_expenses/edit/' . $invoice] as $url) {
            try {
                $this->get($url);
                $this->fail($url . ' should be not found.');
            } catch (PageNotFoundException) {
                $this->addToAssertionCount(1);
            }
        }

        $this->get('/recurring_invoices/edit/' . $invoice)->assertSee('id="schedule_form"');
        $this->get('/recurring_expenses/edit/' . $expense)->assertSee('id="schedule_form"');
    }

    public function testGetForm_LabelsTheQuantityColumnQuantityAndNotOrdered(): void
    {
        $scheduleId = $this->schedule();

        $this->actAsWith([RecurringKind::Invoice->managePermission()]);

        foreach (['/recurring_invoices/add', '/recurring_invoices/edit/' . $scheduleId] as $url) {
            $body = $this->get($url)->getBody();

            $this->assertStringContainsString('<th scope="col" class="text-end">' . lang('Sales.quantity') . '</th>', $body, $url);
            $this->assertStringNotContainsString(lang('Sales_orders.quantity'), $body, $url);
        }

        $this->assertStringNotContainsString('<th scope="col" class="text-end">' . lang('Sales_orders.quantity') . '</th>', $this->get('/recurring_invoices/view/' . $scheduleId)->getBody());
    }

    public function testGetForm_GivesTheFieldsOfTheLinesAndOfTheRemindersAnAccessibleNameAndShowsASaveThatFailed(): void
    {
        $this->actAsWith([RecurringKind::Invoice->managePermission()]);

        $body = $this->get('/recurring_invoices/add')->getBody();

        $this->assertStringContainsString("'aria-label': labels[name]", $body);
        $this->assertStringContainsString("'aria-label': \"" . esc(lang('Recurring.reminders'), 'js') . '"', $body);
        $this->assertStringContainsString("'aria-label': \"" . esc(lang('Recurring.reminder_days'), 'js') . '"', $body);
        $this->assertStringContainsString('toast_support.show("' . esc(lang('Common.request_failed'), 'js') . '", \'danger\')', $body);
    }

    public function testGetShow_ShowsAFailedChangeInsteadOfNothing(): void
    {
        $scheduleId = $this->schedule();

        $this->actAsWith([RecurringKind::Invoice->managePermission()]);

        $this->assertStringContainsString('.fail(function() {', $this->get('/recurring_invoices/view/' . $scheduleId)->getBody());
    }

    public function testGetIndex_ReadsAFilterThatCameAsAListAsNoFilter(): void
    {
        $scheduleId = $this->schedule();

        $this->actAsWith([RecurringKind::Invoice->viewPermission()]);

        $page = $this->get('/recurring_invoices?status[]=paused&search[]=x');

        $page->assertStatus(200);
        $page->assertSee('id="schedule_' . $scheduleId . '"');
    }

    public function testGetIndex_SaysHowOftenAScheduleRepeatsInTheNumberTheCountAsksFor(): void
    {
        $scheduleId = $this->schedule();

        $this->actAsWith([RecurringKind::Invoice->viewPermission()]);

        $this->get('/recurring_invoices')->assertSee(RecurrencePhrase::every(1, RecurrenceUnit::Months));
        $this->get('/recurring_invoices/view/' . $scheduleId)->assertSee(RecurrencePhrase::every(1, RecurrenceUnit::Months));
    }

    public function testGetShow_ShowsTheTermsTheLinesAndTheHistoryWithTheWordsOfARefusal(): void
    {
        $scheduleId = $this->schedule(['start_date' => $this->daysAgo(1), 'period_unit' => 'days', 'issue_mode' => 'issue']);
        $this->closeThrough($this->daysAgo(1));
        (new RecurringGenerator($this->accountingDb()))->run();

        $this->actAsWith([RecurringKind::Invoice->managePermission()]);

        $page = $this->get('/recurring_invoices/view/' . $scheduleId);

        $page->assertStatus(200);
        $page->assertSee('id="schedule_header"');
        $page->assertSee('id="schedule_lines"');
        $page->assertSee('id="schedule_history"');
        $page->assertSee(lang('Recurring.outcome_refused'));
        $page->assertSee(lang('Recurring.outcome_generated'));
        $page->assertSee('class="btn btn-outline-primary btn-sm run-retry"');
    }

    public function testGetShow_OffersNoActionToWhoMayOnlyLook(): void
    {
        $scheduleId = $this->schedule();

        $this->actAsWith([RecurringKind::Invoice->viewPermission()]);

        $page = $this->get('/recurring_invoices/view/' . $scheduleId);

        $page->assertStatus(200);
        $page->assertDontSee('id="schedule_edit"');
        $page->assertDontSee('data-status=');
    }

    public function testGetShow_IsNotFoundForAScheduleOfAnotherKindOrBranchOrThatDoesNotExist(): void
    {
        $expense = $this->schedules->create(RecurringKind::Expense, ['name' => 'Rent', 'frequency' => 'monthly', 'start_date' => date('Y-m-d'), 'expense_category_id' => $this->categoryOf(), 'amount' => '10', 'tax_amount' => '0', 'payment_type' => lang('Sales.cash')], $this->manager);

        $this->actAsWith([RecurringKind::Invoice->viewPermission()]);

        foreach (['/recurring_invoices/view/' . $expense, '/recurring_invoices/view/999999'] as $url) {
            try {
                $this->get($url);
                $this->fail($url . ' should be not found.');
            } catch (PageNotFoundException) {
                $this->addToAssertionCount(1);
            }
        }

        $mine = $this->schedule();
        $this->actAsWith([RecurringKind::Invoice->viewPermission()], [$this->createBranch('B3')]);
        $this->expectException(PageNotFoundException::class);

        $this->get('/recurring_invoices/view/' . $mine);
    }

    private function categoryOf(): int
    {
        $db = $this->accountingDb();
        $db->table('expense_categories')->insert(['category_name' => 'Rent ' . uniqid(), 'category_description' => '', 'deleted' => 0]);

        return (int) $db->insertID();
    }

    public function testAssertKind_RefusesAnInvoiceScheduleToTheExpensesModule(): void
    {
        $scheduleId = $this->schedule();

        $this->actAsWith(['recurring_expenses', RecurringKind::Expense->managePermission(), RecurringKind::Invoice->managePermission()]);

        $changed = $this->postJson('/recurring_expenses/status/' . $scheduleId, ['status' => 'paused']);

        $this->assertFalse($changed['success']);
        $this->assertSame('active', $this->accountingDb()->table('recurring_schedules')->where('schedule_id', $scheduleId)->get()->getRow()->status);

        $this->accountingDb()->table('recurring_runs')->insert(['schedule_id' => $scheduleId, 'occurrence' => 0, 'due_date' => date('Y-m-d'), 'status' => 'refused', 'refusal_source' => 'posting', 'refusal_code' => 'period_closed']);
        $runId = (int) $this->accountingDb()->insertID();

        $retried = $this->postJson('/recurring_expenses/retry/' . $scheduleId . '/' . $runId);

        $this->assertFalse($retried['success']);
        $this->assertSame('refused', $this->accountingDb()->table('recurring_runs')->where('run_id', $runId)->get()->getRow()->status);

        $saved = $this->postJson('/recurring_expenses/save/' . $scheduleId, $this->input(['name' => 'Hijacked']));

        $this->assertFalse($saved['success']);
        $this->assertSame('Monthly support', $this->accountingDb()->table('recurring_schedules')->where('schedule_id', $scheduleId)->get()->getRow()->name);

        $this->expectException(PageNotFoundException::class);

        $this->get('/recurring_expenses/view/' . $scheduleId);
    }

    public function testPostSave_MakesTheScheduleAndSendsTheEmployeeToIt(): void
    {
        $this->actAsWith([RecurringKind::Invoice->managePermission()]);

        $result = $this->postJson('/recurring_invoices/save', $this->input());

        $this->assertTrue($result['success'], $result['message']);
        $this->assertStringEndsWith('/recurring_invoices/view/' . $result['id'], $result['redirect']);
        $this->assertSame(1, $this->countRows('recurring_schedules'));
        $this->assertSame(1, $this->countRows('recurring_schedule_lines'));
        $this->assertSame(0, $this->countRows('sales'));
    }

    public function testPostSave_RefusesInWordsWhatTheServiceRefuses(): void
    {
        $this->actAsWith([RecurringKind::Invoice->managePermission()]);

        $result = $this->postJson('/recurring_invoices/save', $this->input(['name' => '']));

        $this->assertFalse($result['success']);
        $this->assertStringContainsString('255', $result['message']);
        $this->assertSame(0, $this->countRows('recurring_schedules'));

        $issueBefore = $this->postJson('/recurring_invoices/save', $this->input(['issue_before_days' => '30']));

        $this->assertFalse($issueBefore['success']);
        $this->assertStringContainsString('29', $issueBefore['message']);
    }

    public function testPostSave_RequiresThePermissionToManage(): void
    {
        $scheduleId = $this->schedule();

        $this->actAsWith([RecurringKind::Invoice->viewPermission()]);

        $added = $this->postJson('/recurring_invoices/save', $this->input());
        $this->assertFalse($added['success']);
        $this->assertSame(lang('Accounting.error_permission_denied'), $added['message']);

        $changed = $this->postJson('/recurring_invoices/save/' . $scheduleId, $this->input(['name' => 'Hijacked']));
        $this->assertFalse($changed['success']);
        $this->assertSame('Monthly support', $this->accountingDb()->table('recurring_schedules')->where('schedule_id', $scheduleId)->get()->getRow()->name);
    }

    public function testPostSave_ChangesASchedule(): void
    {
        $scheduleId = $this->schedule();

        $this->actAsWith([RecurringKind::Invoice->managePermission()]);

        $result = $this->postJson('/recurring_invoices/save/' . $scheduleId, $this->input(['name' => 'Renamed']));

        $this->assertTrue($result['success'], $result['message']);
        $this->assertSame('Renamed', $this->accountingDb()->table('recurring_schedules')->where('schedule_id', $scheduleId)->get()->getRow()->name);
    }

    public function testPostSave_DoesNotChangeAScheduleOfAnotherKind(): void
    {
        $expense = $this->schedules->create(RecurringKind::Expense, ['name' => 'Rent', 'frequency' => 'monthly', 'start_date' => date('Y-m-d'), 'expense_category_id' => $this->categoryOf(), 'amount' => '10', 'tax_amount' => '0', 'payment_type' => lang('Sales.cash')], $this->manager);

        $this->actAsWith([RecurringKind::Invoice->managePermission()]);

        $result = $this->postJson('/recurring_invoices/save/' . $expense, $this->input(['name' => 'Hijacked']));

        $this->assertFalse($result['success']);
        $this->assertSame('Rent', $this->accountingDb()->table('recurring_schedules')->where('schedule_id', $expense)->get()->getRow()->name);
    }

    public function testPostStatus_PausesResumesAndEndsAndRefusesAnUnknownStatus(): void
    {
        $scheduleId = $this->schedule();

        $this->actAsWith([RecurringKind::Invoice->managePermission()]);

        $this->assertTrue($this->postJson('/recurring_invoices/status/' . $scheduleId, ['status' => 'paused'])['success']);
        $this->assertSame('paused', $this->accountingDb()->table('recurring_schedules')->where('schedule_id', $scheduleId)->get()->getRow()->status);
        $this->assertTrue($this->postJson('/recurring_invoices/status/' . $scheduleId, ['status' => 'active'])['success']);
        $this->assertFalse($this->postJson('/recurring_invoices/status/' . $scheduleId, ['status' => 'frozen'])['success']);
        $this->assertTrue($this->postJson('/recurring_invoices/status/' . $scheduleId, ['status' => 'ended'])['success']);
        $this->assertFalse($this->postJson('/recurring_invoices/status/' . $scheduleId, ['status' => 'active'])['success']);
    }

    public function testPostStatus_RequiresThePermissionToManage(): void
    {
        $scheduleId = $this->schedule();

        $this->actAsWith([RecurringKind::Invoice->viewPermission()]);

        $this->assertFalse($this->postJson('/recurring_invoices/status/' . $scheduleId, ['status' => 'paused'])['success']);
        $this->assertSame('active', $this->accountingDb()->table('recurring_schedules')->where('schedule_id', $scheduleId)->get()->getRow()->status);
    }

    public function testPostRetry_MakesTheDateOnceTheCauseIsGoneAndKeepsTheRefusalWhileItIsNot(): void
    {
        $scheduleId = $this->schedule(['start_date' => $this->daysAgo(1), 'period_unit' => 'days', 'issue_mode' => 'issue']);
        $this->closeThrough($this->daysAgo(1));
        (new RecurringGenerator($this->accountingDb()))->run();

        $runId = (int) $this->accountingDb()->table('recurring_runs')->where(['schedule_id' => $scheduleId, 'status' => 'refused'])->get()->getRow()->run_id;

        $this->actAsWith([RecurringKind::Invoice->managePermission()]);

        $again = $this->postJson('/recurring_invoices/retry/' . $scheduleId . '/' . $runId);
        $this->assertFalse($again['success']);
        $this->assertNotSame('', $again['message']);

        $this->accountingDb()->table('accounting_closed_periods')->truncate();

        $made = $this->postJson('/recurring_invoices/retry/' . $scheduleId . '/' . $runId);
        $this->assertTrue($made['success'], $made['message']);
        $this->assertSame('generated', $this->accountingDb()->table('recurring_runs')->where('run_id', $runId)->get()->getRow()->status);
    }

    public function testPostRetry_RequiresThePermissionToManage(): void
    {
        $scheduleId = $this->schedule(['start_date' => $this->daysAgo(1), 'period_unit' => 'days', 'issue_mode' => 'issue']);
        $this->closeThrough($this->daysAgo(1));
        (new RecurringGenerator($this->accountingDb()))->run();

        $runId = (int) $this->accountingDb()->table('recurring_runs')->where(['schedule_id' => $scheduleId, 'status' => 'refused'])->get()->getRow()->run_id;
        $this->accountingDb()->table('accounting_closed_periods')->truncate();

        $this->actAsWith([RecurringKind::Invoice->viewPermission()]);

        $this->assertFalse($this->postJson('/recurring_invoices/retry/' . $scheduleId . '/' . $runId)['success']);
        $this->assertSame('refused', $this->accountingDb()->table('recurring_runs')->where('run_id', $runId)->get()->getRow()->status);
    }

    /**
     * @return array<string, array{0: string}>
     */
    public static function changesProvider(): array
    {
        return [
            'save'     => ['recurring_invoices/save'],
            'save one' => ['recurring_invoices/save/1'],
            'status'   => ['recurring_invoices/status/1'],
            'retry'    => ['recurring_invoices/retry/1/1'],
        ];
    }

    #[DataProvider('changesProvider')]
    public function testRoutes_RejectAGetRequestToAChange(string $url): void
    {
        $this->actAsWith([RecurringKind::Invoice->managePermission()]);
        $this->expectException(PageNotFoundException::class);

        $this->get('/' . $url);
    }

    public function testGetCustomers_AnswersOnlyToWhoMayManage(): void
    {
        $this->actAsWith([RecurringKind::Invoice->managePermission()]);

        $customers = json_decode($this->get('/recurring_invoices/customers?term=Omar')->getJSON(), true);
        $this->assertContains($this->customerId, array_column($customers, 'value'));

        $this->actAsWith([RecurringKind::Invoice->viewPermission()]);

        $this->get('/recurring_invoices/customers')->assertStatus(403);
        $this->get('/recurring_invoices/items')->assertStatus(403);
    }
}
