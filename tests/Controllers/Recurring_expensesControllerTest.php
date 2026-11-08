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

        $page->assertRedirect();
        $this->assertStringContainsString('no_access/recurring_expenses', $page->getRedirectUrl());
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

    public function testGetForm_OffersWeeklyFirstForANewScheduleAndTheWaysOfPaymentByTheirType(): void
    {
        $this->actAsWith([RecurringKind::Expense->managePermission()]);

        $body = $this->get('/recurring_expenses/add')->getBody();

        $this->assertStringContainsString('<option value="weekly" selected>', $body, 'the default of the observed form is weekly');
        $this->assertStringNotContainsString('<option value="monthly" selected>', $body);
        $this->assertStringContainsString('<option value="cash"', $body);
        $this->assertStringContainsString('>' . esc(lang('Sales.cash')) . '</option>', $body);
    }

    public function testGetForm_ListsTheSuppliersInASelectWhileTheyAreFewerThanTheThreshold(): void
    {
        $supplier = $this->insertSupplier('Recurring Picker Supplies');

        $this->setConfigValue('accounting_picker_threshold', '200');
        $this->actAsWith([RecurringKind::Expense->managePermission()]);

        $body = $this->get('/recurring_expenses/add')->getBody();

        $this->assertStringContainsString('<select name="supplier_id" id="supplier_id"', $body);
        $this->assertStringContainsString('<option value="' . $supplier . '">Recurring Picker Supplies</option>', $body);
        $this->assertStringNotContainsString('id="supplier_text"', $body);
    }

    public function testGetForm_ListsEverySupplierUpToTheThresholdEvenWhenThereAreMoreThanTwoHundred(): void
    {
        $this->setConfigValue('accounting_picker_threshold', '260');
        $this->actAsWith([RecurringKind::Expense->managePermission()]);

        $last = 0;

        for ($index = 1; $index <= 230; $index++) {
            $last = $this->insertSupplier('Recurring Bulk Supplier ' . str_pad((string) $index, 3, '0', STR_PAD_LEFT));
        }

        $body = $this->get('/recurring_expenses/add')->getBody();

        $this->assertStringContainsString('<option value="' . $last . '">Recurring Bulk Supplier 230</option>', $body);
        $this->assertStringContainsString('<select name="supplier_id" id="supplier_id"', $body);
    }

    public function testGetForm_ReplacesTheSelectOfSuppliersByASearchBoxAboveTheThresholdAndKeepsTheChosenName(): void
    {
        $supplier   = $this->insertSupplier('Recurring Chosen Supplies');
        $scheduleId = $this->schedule(['supplier_id' => (string) $supplier]);

        $this->setConfigValue('accounting_picker_threshold', '1');
        $this->insertSupplier('Recurring Other Supplies');
        $this->actAsWith([RecurringKind::Expense->managePermission()]);

        $body = $this->get('/recurring_expenses/edit/' . $scheduleId)->getBody();

        $this->assertStringNotContainsString('<select name="supplier_id"', $body);
        $this->assertStringContainsString('id="supplier_text"', $body);
        $this->assertStringContainsString('value="Recurring Chosen Supplies"', $body);
        $this->assertStringContainsString('<input type="hidden" name="supplier_id" id="supplier_id" value="' . $supplier . '">', $body);
        $this->assertStringContainsString('accounting_lookup\/search', $body);
        $this->assertStringContainsString('"kind":"supplier"', $body);
        $this->assertStringNotContainsString('Recurring Other Supplies', $body, 'The suppliers are not all written into the page');
    }

    public function testGetForm_SelectsTheFrequencyAndTheWayOfPaymentOfTheScheduleWhateverLanguageItWasTypedIn(): void
    {
        $scheduleId = $this->schedule(['frequency' => '3_months', 'payment_type' => 'bank_transfer']);

        $this->actAsWith([RecurringKind::Expense->managePermission()]);

        $body = $this->get('/recurring_expenses/edit/' . $scheduleId)->getBody();

        $this->assertStringContainsString('<option value="bank_transfer" selected>', $body);
        $this->assertStringContainsString('<option value="3_months" selected>', $body);
        $this->assertStringNotContainsString('<option value="cash" selected>', $body);
    }

    public function testGetIndex_NamesTheCategoryOfEachScheduleAndNotItsComment(): void
    {
        $categoryId = $this->category('Premises rent');
        $name       = $this->accountingDb()->table('expense_categories')->where('expense_category_id', $categoryId)->get()->getRow()->category_name;
        $scheduleId = $this->schedule(['expense_category_id' => (string) $categoryId, 'comment' => 'paid by transfer']);

        $this->actAsWith([RecurringKind::Expense->viewPermission()]);

        $body = $this->get('/recurring_expenses')->getBody();

        $this->assertMatchesRegularExpression('/id="schedule_' . $scheduleId . '".*?<td>' . preg_quote(esc($name), '/') . '<\/td>/s', $body);
        $this->assertStringNotContainsString('paid by transfer', $body);
    }

    public function testGetShow_NamesTheWayOfPaymentInTheLanguageInUse(): void
    {
        $scheduleId = $this->schedule(['payment_type' => 'cash']);

        $this->actAsWith([RecurringKind::Expense->viewPermission()]);

        $this->get('/recurring_expenses/view/' . $scheduleId)->assertSee(lang('Sales.cash'));
    }

    public function testPostSave_RefusesInWordsAFieldThatCameAsAList(): void
    {
        $this->actAsWith([RecurringKind::Expense->managePermission()]);

        $refused = $this->postJson('/recurring_expenses/save', $this->input(['tax_amount' => ['1']]));

        $this->assertFalse($refused['success']);
        $this->assertSame(lang('Accounting.error_recurring_expense_invalid'), $refused['message']);
        $this->assertSame(0, $this->countRows('recurring_schedules'));
    }

    public function testPostSave_KeepsTheTypeOfTheWayOfPaymentTheFormSent(): void
    {
        $this->actAsWith([RecurringKind::Expense->managePermission()]);

        $made = $this->postJson('/recurring_expenses/save', $this->input(['payment_type' => 'bank_transfer']));

        $this->assertTrue($made['success'], $made['message']);
        $this->assertSame('bank_transfer', $this->accountingDb()->table('recurring_schedules')->where('schedule_id', $made['id'])->get()->getRow()->payment_type);
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
    public function testRoutes_RejectAGetRequestToAChange(string $url): void
    {
        $this->actAsWith([RecurringKind::Expense->managePermission()]);
        $this->expectException(PageNotFoundException::class);

        $this->get('/' . $url);
    }
}
