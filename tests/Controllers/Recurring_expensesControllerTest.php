<?php

namespace Tests\Controllers;

use App\Libraries\Recurring\RecurrenceTerms;
use App\Libraries\Recurring\RecurringGenerator;
use App\Libraries\Recurring\RecurringKind;
use App\Libraries\Recurring\RecurringSchedules;
use CodeIgniter\Exceptions\PageNotFoundException;
use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\DatabaseTestTrait;
use CodeIgniter\Test\FeatureTestTrait;
use Config\Database;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Support\AccountingFixtureTrait;
use Tests\Support\EmployeeFixtureTrait;
use Tests\Support\ExpenseAccountingFixtureTrait;

/**
 * The screens of the recurring expenses: the list, the form with the nine frequencies, the page of a
 * schedule with what each date made, the changes as POSTs the service may refuse in words, and the module
 * of the kind as the only way to a schedule.
 */
class Recurring_expensesControllerTest extends CIUnitTestCase
{
    use DatabaseTestTrait;
    use FeatureTestTrait;
    use AccountingFixtureTrait;
    use EmployeeFixtureTrait;
    use ExpenseAccountingFixtureTrait;

    protected $migrate     = true;
    protected $migrateOnce = true;
    protected $refresh     = false;
    protected $namespace   = null;

    private RecurringSchedules $schedules;
    private int $manager;

    public static function setUpBeforeClass(): void
    {
        $seeder = Database::seeder('tests');
        $seeder->call('TestDatabaseBootstrapSeeder');
    }

    protected function setUp(): void
    {
        parent::setUp();

        $this->startExpenses();
        $this->clearSchedules();

        $this->schedules = new RecurringSchedules($this->accountingDb());
        $this->manager   = $this->employeeWithGrants([RecurringKind::Expense->managePermission()]);
    }

    protected function tearDown(): void
    {
        $this->releaseOpenTransactions();
        $this->clearSchedules();
        $this->accountingDb()->table('accounting_audit_log')->whereIn('entity_type', ['recurring_invoice', 'recurring_expense'])->delete();
        $this->stopExpenses();

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
        $personId = $this->employeeWithGrants(['recurring_expenses', ...$permissions], $branchIds);

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
            'name'                => 'Office rent',
            'frequency'           => 'monthly',
            'start_date'          => date('Y-m-d'),
            'end_date'            => '',
            'max_repeats'         => '0',
            'expense_category_id' => (string) $this->category('Rent'),
            'amount'              => '230.00',
            'tax_amount'          => '30.00',
            'payment_type'        => lang('Sales.cash'),
            'supplier_id'         => '',
            'supplier_tax_code'   => '',
            'treasury_id'         => '',
            'comment'             => '',
            'active'              => '1',
        ], $override);
    }

    /**
     * @param array<string, mixed> $override
     */
    private function schedule(array $override = []): int
    {
        $input = $this->input($override);

        return $this->schedules->create(RecurringKind::Expense, $input, $this->manager, min((string) $input['start_date'], date('Y-m-d')));
    }

    /**
     * @return array<string, mixed>
     */
    private function postJson(string $url, array $data = []): array
    {
        return json_decode($this->post($url, $data)->getJSON(), true);
    }

    public function testGetIndex_ListsTheSchedulesWithTheirFrequencyAndOffersTheNewOne(): void
    {
        $scheduleId = $this->schedule(['frequency' => '3_months']);

        $this->actAsWith([RecurringKind::Expense->managePermission()]);

        $page = $this->get('/recurring_expenses');

        $page->assertStatus(200);
        $page->assertSee('id="schedule_' . $scheduleId . '"');
        $page->assertSee(lang('Recurring.frequency_3_months'));
        $page->assertSee('id="schedule_new"');
        $page->assertDontSee('id="schedule_reminders"');
    }

    public function testGetIndex_OffersNothingToChangeToWhoMayOnlyLook(): void
    {
        $this->schedule();

        $this->actAsWith([RecurringKind::Expense->viewPermission()]);

        $page = $this->get('/recurring_expenses');

        $page->assertStatus(200);
        $page->assertDontSee('id="schedule_new"');
    }

    public function testGetIndex_IsNotOpenedByAnEmployeeOfTheOtherModule(): void
    {
        $personId = $this->employeeWithGrants([RecurringKind::Invoice->managePermission()]);
        $this->withSession(['person_id' => $personId, 'menu_group' => 'home']);

        $page = $this->get('/recurring_expenses');

        $this->assertNotSame(200, $page->response()->getStatusCode());
    }

    public function testGetForm_ListsTheNineFrequenciesAndTheCategories(): void
    {
        $this->actAsWith([RecurringKind::Expense->managePermission()]);

        $page = $this->get('/recurring_expenses/add');

        $page->assertSee('id="schedule_form"');

        foreach (array_keys(RecurrenceTerms::EXPENSE_FREQUENCIES) as $frequency) {
            $page->assertSee('value="' . $frequency . '"');
        }
    }

    public function testGetForm_IsNotFoundForWhoMayOnlyLook(): void
    {
        $this->actAsWith([RecurringKind::Expense->viewPermission()]);
        $this->expectException(PageNotFoundException::class);

        $this->get('/recurring_expenses/add');
    }

    public function testGetShow_ShowsTheTemplateAndWhatEachDateMade(): void
    {
        $scheduleId = $this->schedule();
        (new RecurringGenerator($this->accountingDb()))->run();

        $this->actAsWith([RecurringKind::Expense->managePermission()]);

        $page = $this->get('/recurring_expenses/view/' . $scheduleId);

        $page->assertStatus(200);
        $page->assertSee('id="schedule_header"');
        $page->assertSee('id="schedule_history"');
        $page->assertSee(lang('Recurring.outcome_generated'));
        $page->assertDontSee('id="schedule_lines"');
    }

    public function testPostSave_MakesTheScheduleAndRefusesInWordsWhatIsNotValid(): void
    {
        $this->actAsWith([RecurringKind::Expense->managePermission()]);

        $made = $this->postJson('/recurring_expenses/save', $this->input());

        $this->assertTrue($made['success'], $made['message']);
        $this->assertStringEndsWith('/recurring_expenses/view/' . $made['id'], $made['redirect']);

        $refused = $this->postJson('/recurring_expenses/save', $this->input(['amount' => '0']));

        $this->assertFalse($refused['success']);
        $this->assertSame(lang('Accounting.error_recurring_expense_invalid'), $refused['message']);
        $this->assertSame(1, $this->countRows('recurring_schedules'));
    }

    public function testPostSave_RequiresThePermissionToManageAndTheModuleOfTheKind(): void
    {
        $scheduleId = $this->schedule();

        $this->actAsWith([RecurringKind::Expense->viewPermission()]);

        $this->assertFalse($this->postJson('/recurring_expenses/save', $this->input())['success']);
        $this->assertFalse($this->postJson('/recurring_expenses/save/' . $scheduleId, $this->input(['name' => 'Hijacked']))['success']);
        $this->assertSame('Office rent', $this->accountingDb()->table('recurring_schedules')->where('schedule_id', $scheduleId)->get()->getRow()->name);
    }

    public function testPostStatus_PausesAndEndsASchedule(): void
    {
        $scheduleId = $this->schedule();

        $this->actAsWith([RecurringKind::Expense->managePermission()]);

        $this->assertTrue($this->postJson('/recurring_expenses/status/' . $scheduleId, ['status' => 'paused'])['success']);
        $this->assertSame('paused', $this->accountingDb()->table('recurring_schedules')->where('schedule_id', $scheduleId)->get()->getRow()->status);
        $this->assertTrue($this->postJson('/recurring_expenses/status/' . $scheduleId, ['status' => 'ended'])['success']);
        $this->assertFalse($this->postJson('/recurring_expenses/status/' . $scheduleId, ['status' => 'active'])['success']);
    }

    public function testPostRetry_MakesTheExpenseOfARefusedDate(): void
    {
        $scheduleId = $this->schedule(['start_date' => date('Y-m-d', strtotime('-40 days')), 'frequency' => 'monthly']);
        $this->closeThrough(date('Y-m-d', strtotime('-20 days')));
        (new RecurringGenerator($this->accountingDb()))->run();

        $runId = (int) $this->accountingDb()->table('recurring_runs')->where(['schedule_id' => $scheduleId, 'status' => 'refused'])->get()->getRow()->run_id;
        $this->accountingDb()->table('accounting_closed_periods')->truncate();

        $this->actAsWith([RecurringKind::Expense->managePermission()]);

        $result = $this->postJson('/recurring_expenses/retry/' . $scheduleId . '/' . $runId);

        $this->assertTrue($result['success'], $result['message']);
        $this->assertSame(2, $this->countRows('expenses'));
    }

    /**
     * @return array<string, array{0: string}>
     */
    public static function changesProvider(): array
    {
        return [
            'save'   => ['recurring_expenses/save'],
            'status' => ['recurring_expenses/status/1'],
            'retry'  => ['recurring_expenses/retry/1/1'],
        ];
    }

    #[DataProvider('changesProvider')]
    public function testChange_IsNotReachableByAGetRequest(string $url): void
    {
        $this->actAsWith([RecurringKind::Expense->managePermission()]);
        $this->expectException(PageNotFoundException::class);

        $this->get('/' . $url);
    }
}
