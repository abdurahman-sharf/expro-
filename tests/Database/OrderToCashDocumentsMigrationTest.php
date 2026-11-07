<?php

namespace Tests\Database;

use App\Database\Migrations\OrderToCashDocuments;
use App\Models\Employee;
use CodeIgniter\Database\Exceptions\DatabaseException;
use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\DatabaseTestTrait;
use Config\Database;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Support\AccountingFixtureTrait;
use Tests\Support\EmployeeFixtureTrait;
use Tests\Support\ItemFixtureTrait;

class OrderToCashDocumentsMigrationTest extends CIUnitTestCase
{
    use DatabaseTestTrait;
    use AccountingFixtureTrait;
    use EmployeeFixtureTrait;
    use ItemFixtureTrait;

    protected $migrate     = true;
    protected $migrateOnce = true;
    protected $refresh     = false;
    protected $namespace   = null;

    private const TABLES = ['sales_orders', 'sales_order_lines', 'sales_order_invoices', 'sales_order_invoice_lines', 'recurring_schedules', 'recurring_schedule_lines', 'recurring_schedule_reminders', 'recurring_runs'];

    private const PERMISSIONS = [
        'customer_orders'    => ['customer_orders', 'so_add', 'so_edit', 'so_delete', 'so_view_all', 'so_convert'],
        'recurring_invoices' => ['recurring_invoices', 'recurring_invoices_view', 'recurring_invoices_manage'],
        'recurring_expenses' => ['recurring_expenses', 'recurring_expenses_view', 'recurring_expenses_manage'],
    ];

    public static function setUpBeforeClass(): void
    {
        $seeder = Database::seeder('tests');
        $seeder->call('TestDatabaseBootstrapSeeder');
    }

    protected function tearDown(): void
    {
        (new OrderToCashDocuments())->up();

        $db = $this->accountingDb();
        $db->query('SET FOREIGN_KEY_CHECKS = 0');

        foreach (array_reverse(self::TABLES) as $table) {
            $db->table($table)->truncate();
        }

        $db->query('SET FOREIGN_KEY_CHECKS = 1');
        $this->removeInsertedEntities();

        parent::tearDown();
    }

    private function hasTable(string $table): bool
    {
        return $this->accountingDb()->query('SELECT COUNT(*) AS found FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ?', [$this->accountingDb()->prefixTable($table)])->getRow()->found > 0;
    }

    /**
     * @param array<string, mixed> $override
     */
    private function scheduleRow(array $override = []): array
    {
        return array_merge([
            'company_id'   => 1,
            'branch_id'    => 1,
            'kind'         => 'invoice',
            'name'         => 'Support',
            'status'       => 'active',
            'period_count' => 1,
            'period_unit'  => 'months',
            'start_date'   => date('Y-m-d'),
            'employee_id'  => $this->employee(),
            'customer_id'  => $this->customer(),
            'issue_mode'   => 'draft',
        ], $override);
    }

    private ?int $employeeId = null;
    private ?int $customerId = null;

    private function employee(): int
    {
        return $this->employeeId ??= $this->employeeWithGrants([]);
    }

    private function customer(): int
    {
        return $this->customerId ??= $this->insertCustomer();
    }

    private function schedule(array $override = []): int
    {
        $this->accountingDb()->table('recurring_schedules')->insert($this->scheduleRow($override));

        return (int) $this->accountingDb()->insertID();
    }

    private function expenseSchedule(): int
    {
        $db = $this->accountingDb();
        $db->table('expense_categories')->insert(['category_name' => 'Rent ' . uniqid(), 'category_description' => '', 'deleted' => 0]);

        return $this->schedule(['kind' => 'expense', 'customer_id' => null, 'issue_mode' => null, 'expense_category_id' => (int) $db->insertID(), 'amount' => '10.00', 'payment_type' => 'Cash']);
    }

    private function assertRefused(string $table, array $row, string $why): void
    {
        $before = $this->countRows($table);

        try {
            $this->accountingDb()->table($table)->insert($row);
            $this->fail($why);
        } catch (DatabaseException) {
            $this->assertSame($before, $this->countRows($table), $why);
        }
    }

    public function testUp_CreatesTheTablesAndRegistersTheModulesAndTheirPermissionsWithoutGrantingThem(): void
    {
        $db = $this->accountingDb();

        foreach (self::TABLES as $table) {
            $this->assertTrue($this->hasTable($table), $table);
        }

        foreach (self::PERMISSIONS as $module => $permissions) {
            $this->assertSame([$module], array_column($db->table('modules')->where('module_id', $module)->get()->getResultArray(), 'module_id'));
            $this->assertEqualsCanonicalizing($permissions, array_column($db->table('permissions')->where('module_id', $module)->get()->getResultArray(), 'permission_id'));
            $this->assertSame(0, $db->table('grants')->whereIn('permission_id', $permissions)->countAllResults(), $module . ' is granted to nobody');
        }
    }

    public function testUp_GivesNoGrantOfSalesOrdersTheRightToTheRegister(): void
    {
        $db       = $this->accountingDb();
        $employee = model(Employee::class);

        foreach ([['customer_orders'], ['customer_orders', 'sales_stock'], [...self::PERMISSIONS['customer_orders'], 'sales_stock']] as $held) {
            $personId = $this->employeeWithGrants($held);

            $this->assertTrue($employee->has_module_grant('customer_orders', $personId), implode(', ', $held));
            $this->assertFalse($employee->has_module_grant('sales', $personId), 'whoever may write orders, and sell from a location to do it, does not get the register: ' . implode(', ', $held));
            $this->assertFalse($employee->has_grant('sales', $personId));
        }

        $this->assertSame(0, $db->table('permissions')->like('permission_id', 'customer_orders_', 'after')->countAllResults(), 'a sub-permission that starts with the id of the module asks the register check for more than the module');
    }

    public function testUp_RunsAgainWithoutHarmAndKeepsTheRowsThatExist(): void
    {
        $scheduleId = $this->schedule();

        (new OrderToCashDocuments())->up();
        (new OrderToCashDocuments())->up();

        $this->assertSame(1, $this->countRows('recurring_schedules'));
        $this->assertSame($scheduleId, (int) $this->accountingDb()->table('recurring_schedules')->get()->getRow()->schedule_id);
        $this->assertSame(1, $this->accountingDb()->table('modules')->where('module_id', 'customer_orders')->countAllResults());
        $this->assertSame(6, $this->accountingDb()->table('permissions')->where('module_id', 'customer_orders')->countAllResults());
    }

    public function testUp_RefusesTwoRunsOfOneScheduleOnOneDateAndTwoWithOneOccurrence(): void
    {
        $scheduleId = $this->schedule();
        $day        = date('Y-m-d');

        $this->accountingDb()->table('recurring_runs')->insert(['schedule_id' => $scheduleId, 'occurrence' => 0, 'due_date' => $day, 'status' => 'refused', 'refusal_code' => 'period_closed']);

        $this->assertRefused('recurring_runs', ['schedule_id' => $scheduleId, 'occurrence' => 1, 'due_date' => $day, 'status' => 'refused', 'refusal_code' => 'period_closed'], 'a date has one run');
        $this->assertRefused('recurring_runs', ['schedule_id' => $scheduleId, 'occurrence' => 0, 'due_date' => date('Y-m-d', strtotime('+1 day')), 'status' => 'refused', 'refusal_code' => 'period_closed'], 'an occurrence has one run');
        $this->accountingDb()->table('recurring_runs')->insert(['schedule_id' => $scheduleId, 'occurrence' => 1, 'due_date' => date('Y-m-d', strtotime('+1 day')), 'status' => 'refused', 'refusal_code' => 'period_closed']);
        $this->assertSame(2, $this->countRows('recurring_runs'));
    }

    /**
     * @return array<string, array{0: array<string, mixed>}>
     */
    public static function badSchedulesProvider(): array
    {
        return [
            'an invoice without a customer'      => [['customer_id' => null]],
            'an invoice without an issue mode'   => [['issue_mode' => null]],
            'a kind that is not one'             => [['kind' => 'revenue']],
            'a status that is not one'           => [['status' => 'frozen']],
            'a unit that is not one'             => [['period_unit' => 'fortnights']],
            'a period of zero'                   => [['period_count' => 0]],
            'an issue mode that is not one'      => [['issue_mode' => 'send']],
        ];
    }

    #[DataProvider('badSchedulesProvider')]
    public function testUp_RefusesAScheduleWhoseTemplateOrTermsAreNotWhatTheTableAllows(array $override): void
    {
        $this->assertRefused('recurring_schedules', $this->scheduleRow($override), 'the schedule should be refused: ' . json_encode($override));
    }

    public function testUp_RefusesAnExpenseScheduleWithoutACategoryAnAmountAndAWayOfPayment(): void
    {
        $this->expenseSchedule();

        $row = $this->scheduleRow(['kind' => 'expense', 'customer_id' => null, 'issue_mode' => null]);

        $this->assertRefused('recurring_schedules', $row, 'an expense without a category, an amount and a way of payment');
    }

    /**
     * @return array<string, array{0: array<string, mixed>}>
     */
    public static function badRemindersProvider(): array
    {
        return [
            'days missing for a day before' => [['send_when' => 3, 'days' => null]],
            'days missing for a day after'  => [['send_when' => 4, 'days' => null]],
            'days on a fixed date'          => [['send_when' => 0, 'days' => 5]],
            'a choice past the five'        => [['send_when' => 5, 'days' => null]],
        ];
    }

    #[DataProvider('badRemindersProvider')]
    public function testUp_RefusesAReminderWhoseDaysDoNotMatchItsChoice(array $override): void
    {
        $scheduleId = $this->schedule();

        $this->assertRefused('recurring_schedule_reminders', array_merge(['schedule_id' => $scheduleId], $override), 'the reminder should be refused: ' . json_encode($override));
        $this->accountingDb()->table('recurring_schedule_reminders')->insert(['schedule_id' => $scheduleId, 'send_when' => 3, 'days' => 2]);
        $this->assertSame(1, $this->countRows('recurring_schedule_reminders'));
    }

    public function testUp_RefusesARunWithoutItsOutcome(): void
    {
        $scheduleId = $this->schedule();
        $day        = date('Y-m-d');

        $this->assertRefused('recurring_runs', ['schedule_id' => $scheduleId, 'occurrence' => 0, 'due_date' => $day, 'status' => 'generated'], 'a made date names its document');
        $this->assertRefused('recurring_runs', ['schedule_id' => $scheduleId, 'occurrence' => 0, 'due_date' => $day, 'status' => 'refused'], 'a refused date names its reason');
        $this->assertRefused('recurring_runs', ['schedule_id' => $scheduleId, 'occurrence' => 0, 'due_date' => $day, 'status' => 'done', 'refusal_code' => 'x'], 'a status that is not one');
    }

    public function testUp_RefusesAnOrderStatusThatIsNotStoredAndALineWithoutAQuantity(): void
    {
        $customerId = $this->customer();
        $itemId     = $this->createTestItem(HAS_NO_STOCK);

        $this->assertRefused('sales_orders', ['company_id' => 1, 'branch_id' => 1, 'customer_id' => $customerId, 'order_date' => date('Y-m-d'), 'status' => 'invoiced'], 'invoiced is read from the invoices and never stored');

        $this->accountingDb()->table('sales_orders')->insert(['company_id' => 1, 'branch_id' => 1, 'customer_id' => $customerId, 'order_date' => date('Y-m-d')]);

        $orderId = (int) $this->accountingDb()->insertID();

        $this->assertRefused('sales_order_lines', ['order_id' => $orderId, 'line_no' => 1, 'item_id' => $itemId, 'quantity' => '0', 'unit_price' => '5.00', 'item_location' => 1], 'a line needs a quantity above zero');
        $this->assertRefused('sales_order_lines', ['order_id' => $orderId, 'line_no' => 1, 'item_id' => $itemId, 'quantity' => '1', 'unit_price' => '-5.00', 'item_location' => 1], 'a price is not negative');

        $this->accountingDb()->table('sales_order_lines')->insert(['order_id' => $orderId, 'line_no' => 1, 'item_id' => $itemId, 'quantity' => '1', 'unit_price' => '5.00', 'item_location' => 1]);

        $this->assertRefused('sales_order_lines', ['order_id' => $orderId, 'line_no' => 1, 'item_id' => $itemId, 'quantity' => '2', 'unit_price' => '5.00', 'item_location' => 1], 'a line number is used once in an order');
    }

    public function testDown_DropsTheTablesAndRemovesTheModulesTheirPermissionsAndTheirGrants(): void
    {
        $this->accountingDb()->table('grants')->insert(['permission_id' => 'so_add', 'person_id' => $this->employee(), 'menu_group' => 'home']);

        (new OrderToCashDocuments())->down();

        foreach (self::TABLES as $table) {
            $this->assertFalse($this->hasTable($table), $table);
        }

        foreach (self::PERMISSIONS as $module => $permissions) {
            $this->assertSame(0, $this->accountingDb()->table('modules')->where('module_id', $module)->countAllResults());
            $this->assertSame(0, $this->accountingDb()->table('permissions')->whereIn('permission_id', $permissions)->countAllResults());
            $this->assertSame(0, $this->accountingDb()->table('grants')->whereIn('permission_id', $permissions)->countAllResults());
        }
    }

    public function testDown_RunsTwiceWithoutHarm(): void
    {
        (new OrderToCashDocuments())->down();
        (new OrderToCashDocuments())->down();

        $this->assertFalse($this->hasTable('recurring_runs'));
    }
}
