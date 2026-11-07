<?php

namespace Tests\Libraries\Recurring;

use App\Libraries\Accounting\AccountingError;
use App\Libraries\Accounting\AccountingException;
use App\Libraries\Organization\BranchError;
use App\Libraries\Organization\BranchException;
use App\Libraries\Organization\BranchGuard;
use App\Libraries\Recurring\RecurringKind;
use App\Libraries\Recurring\ScheduleInput;
use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\DatabaseTestTrait;
use Config\Database;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Support\AccountingFixtureTrait;
use Tests\Support\EmployeeFixtureTrait;
use Tests\Support\ExpenseAccountingFixtureTrait;
use Tests\Support\ItemFixtureTrait;
use Tests\Support\SaleAccountingFixtureTrait;

/**
 * What a person types to make a schedule, checked and put in the shape it is stored in: the name, the
 * first date, the template of an invoice or of an expense, the money, and the reminders.
 */
class ScheduleInputTest extends CIUnitTestCase
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
    protected $namespace   = null;

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

        $this->service = $this->item('0', false);
    }

    protected function tearDown(): void
    {
        $this->stopExpenses();
        $this->stopSelling();

        parent::tearDown();
    }

    /**
     * @param array<string, mixed> $override
     *
     * @return array<string, mixed>
     */
    private function invoice(array $override = []): array
    {
        return array_merge([
            'name'         => 'Support',
            'period_count' => '1',
            'period_unit'  => 'months',
            'start_date'   => date('Y-m-d'),
            'customer_id'  => $this->customerId,
            'location_id'  => self::LOCATION_ID,
            'lines'        => [['item_id' => $this->service, 'quantity' => '1', 'unit_price' => '10.00']],
        ], $override);
    }

    /**
     * @param array<string, mixed> $override
     *
     * @return array<string, mixed>
     */
    private function expense(array $override = []): array
    {
        return array_merge([
            'name'                => 'Rent',
            'frequency'           => 'monthly',
            'start_date'          => date('Y-m-d'),
            'expense_category_id' => $this->category('Rent'),
            'amount'              => '230.00',
            'tax_amount'          => '30.00',
            'payment_type'        => lang('Sales.cash'),
        ], $override);
    }

    /**
     * @param array<string, mixed> $input
     *
     * @return array<string, mixed>
     */
    private function normalised(RecurringKind $kind, array $input, bool $isNew = true, ?int $employeeId = null, ?int $ownerId = null): array
    {
        return ScheduleInput::normalise($kind, $input, $this->accountingDb(), new BranchGuard($this->accountingDb()), $employeeId ?? $this->employeeId, date('Y-m-d'), $isNew, $ownerId);
    }

    private function assertRefused(AccountingError $error, callable $action, string $why = ''): void
    {
        try {
            $action();
        } catch (AccountingException $refusal) {
            $this->assertSame($error, $refusal->error, $why);

            return;
        }

        $this->fail('The input should have been refused with ' . $error->value . ($why === '' ? '' : ': ' . $why));
    }

    public function testNormalise_TrimsTheNameAndTheCommentAndRefusesAnEmptyOrTooLongOne(): void
    {
        $result = $this->normalised(RecurringKind::Invoice, $this->invoice(['name' => "  Support  \n", 'comment' => '  note ']));

        $this->assertSame(['Support', 'note'], [$result['columns']['name'], $result['columns']['comment']]);
        $this->assertRefused(AccountingError::RecurringNameInvalid, fn () => $this->normalised(RecurringKind::Invoice, $this->invoice(['name' => "  \t "])));
        $this->assertRefused(AccountingError::RecurringNameInvalid, fn () => $this->normalised(RecurringKind::Invoice, $this->invoice(['name' => ['x']])));
        $this->assertRefused(AccountingError::DescriptionTooLong, fn () => $this->normalised(RecurringKind::Invoice, $this->invoice(['comment' => str_repeat('c', 501)])));
        $this->assertSame(str_repeat('c', 500), $this->normalised(RecurringKind::Invoice, $this->invoice(['comment' => str_repeat('c', 500)]))['columns']['comment']);
    }

    public function testNormalise_RefusesAFirstDateThatHasPassedOnlyForANewSchedule(): void
    {
        $yesterday = date('Y-m-d', strtotime('-1 day'));

        $this->assertRefused(AccountingError::RecurringStartInPast, fn () => $this->normalised(RecurringKind::Invoice, $this->invoice(['start_date' => $yesterday])));
        $this->assertSame($yesterday, $this->normalised(RecurringKind::Invoice, $this->invoice(['start_date' => $yesterday]), false)['terms']->startDate);
        $this->assertSame(date('Y-m-d'), $this->normalised(RecurringKind::Invoice, $this->invoice())['terms']->startDate);
    }

    /**
     * @return array<string, array{0: mixed, 1: bool}>
     */
    public static function activeProvider(): array
    {
        return [
            'absent'   => [null, true],
            'one'      => ['1', true],
            'on'       => ['on', true],
            'true'     => [true, true],
            'zero'     => ['0', false],
            'empty'    => ['', false],
            'anything' => ['no', false],
        ];
    }

    #[DataProvider('activeProvider')]
    public function testNormalise_ReadsTheActiveSwitch(mixed $value, bool $expected): void
    {
        $input = $this->invoice();

        if ($value !== null) {
            $input['active'] = $value;
        }

        $this->assertSame($expected, $this->normalised(RecurringKind::Invoice, $input)['active']);
    }

    public function testNormalise_PutsAnInvoiceTemplateInTheShapeItIsStored(): void
    {
        $result = $this->normalised(RecurringKind::Invoice, $this->invoice(['payment_terms_days' => ' 30 ', 'issue_mode' => 'issue']));

        $this->assertSame([1, $this->customerId, 30, 'issue'], [(int) $result['columns']['branch_id'], $result['columns']['customer_id'], $result['columns']['payment_terms_days'], $result['columns']['issue_mode']]);
        $this->assertCount(1, $result['lines']);
        $this->assertSame(self::LOCATION_ID, $result['lines'][0]['item_location']);
        $this->assertSame([], $result['reminders']);
    }

    public function testNormalise_LeavesAnInvoiceAsADraftUnlessItIsToBeIssued(): void
    {
        foreach ([null, '', 'draft', 'send', 'ISSUE', ['issue']] as $mode) {
            $input = $this->invoice();

            if ($mode !== null) {
                $input['issue_mode'] = $mode;
            }

            $this->assertSame('draft', $this->normalised(RecurringKind::Invoice, $input)['columns']['issue_mode'], json_encode($mode));
        }
    }

    public function testNormalise_LeavesThePaymentTermsEmptyWhenNoneAreTypedAndRefusesBadOnes(): void
    {
        $this->assertNull($this->normalised(RecurringKind::Invoice, $this->invoice(['payment_terms_days' => '  ']))['columns']['payment_terms_days']);
        $this->assertNull($this->normalised(RecurringKind::Invoice, $this->invoice(['payment_terms_days' => '0']))['columns']['payment_terms_days'], 'zero days of terms is no terms, as on an invoice typed by hand');

        foreach (['-1', '1.5', 'net30', '99999'] as $bad) {
            $this->assertRefused(AccountingError::PaymentTermsInvalid, fn () => $this->normalised(RecurringKind::Invoice, $this->invoice(['payment_terms_days' => $bad])));
        }
    }

    public function testNormalise_RefusesACustomerThatIsDeletedOrNotThere(): void
    {
        $this->accountingDb()->table('customers')->where('person_id', $this->customerId)->update(['deleted' => 1]);

        $this->assertRefused(AccountingError::RecurringCustomerInvalid, fn () => $this->normalised(RecurringKind::Invoice, $this->invoice()));
        $this->assertRefused(AccountingError::RecurringCustomerInvalid, fn () => $this->normalised(RecurringKind::Invoice, $this->invoice(['customer_id' => '0'])));

        $this->accountingDb()->table('customers')->where('person_id', $this->customerId)->update(['deleted' => 0]);
    }

    /**
     * @return array<string, array{0: mixed, 1: bool}>
     */
    public static function amountProvider(): array
    {
        return [
            'whole'              => ['230', true],
            'one decimal'        => ['230.5', true],
            'two decimals'       => ['0.01', true],
            'three decimals'     => ['1.001', false],
            'comma'              => ['1,5', false],
            'exponent'           => ['1e3', false],
            'sign'               => ['+5', false],
            'thirteen digits'    => ['1234567890123', true],
            'fourteen digits'    => ['12345678901234', false],
            'zero'               => ['0', false],
            'zero with decimals' => ['0.00', false],
            'text'               => ['ten', false],
            'array'              => [['1'], false],
        ];
    }

    #[DataProvider('amountProvider')]
    public function testNormalise_AcceptsAnAmountOfAtMostTwoDecimalsAboveZero(mixed $amount, bool $accepted): void
    {
        $input = $this->expense(['amount' => $amount, 'tax_amount' => '0']);

        if ($accepted) {
            $this->assertSame(bcadd((string) $amount, '0', 2), $this->normalised(RecurringKind::Expense, $input)['columns']['amount']);

            return;
        }

        $this->assertRefused(AccountingError::RecurringExpenseInvalid, fn () => $this->normalised(RecurringKind::Expense, $input));
    }

    public function testNormalise_AcceptsAWayOfPaymentOfTheListByItsTypeOrByItsNameAndKeepsTheType(): void
    {
        foreach (['cash', lang('Sales.cash'), ' cash '] as $typed) {
            $this->assertSame('cash', $this->normalised(RecurringKind::Expense, $this->expense(['payment_type' => $typed]))['columns']['payment_type'], $typed);
        }

        $this->assertSame('bank_transfer', $this->normalised(RecurringKind::Expense, $this->expense(['payment_type' => lang('Sales.bank_transfer')]))['columns']['payment_type']);
    }

    public function testNormalise_KeepsTheTypeOfAWayOfPaymentTypedInAnotherLanguage(): void
    {
        $language = service('language');
        $original = $language->getLocale();
        $other    = $original === 'en' ? 'ar-EG' : 'en';

        try {
            $language->setLocale($other);
            $typed  = lang('Sales.cash');
            $stored = $this->normalised(RecurringKind::Expense, $this->expense(['payment_type' => $typed]))['columns']['payment_type'];
        } finally {
            $language->setLocale($original);
        }

        $this->assertNotSame(lang('Sales.cash'), $typed);
        $this->assertSame('cash', $stored);
        $this->assertRefused(AccountingError::RecurringExpenseInvalid, fn () => $this->normalised(RecurringKind::Expense, $this->expense(['payment_type' => $typed])), 'the name of another language than the one in use is not one of the list');
    }

    public function testNormalise_RefusesAWayOfPaymentThatIsNotOnTheList(): void
    {
        foreach (['Barter', '', ['Cash'], 'Cash:12345', 'giftcard', 'rewards', 'upi', 'CASH', 'cash;'] as $bad) {
            $this->assertRefused(AccountingError::RecurringExpenseInvalid, fn () => $this->normalised(RecurringKind::Expense, $this->expense(['payment_type' => $bad])));
        }
    }

    public function testNormalise_KeepsTheTaxOfAnExpenseWithinItsAmount(): void
    {
        $this->assertSame('230.00', $this->normalised(RecurringKind::Expense, $this->expense(['tax_amount' => '230']))['columns']['tax_amount']);
        $this->assertSame('0.00', $this->normalised(RecurringKind::Expense, $this->expense(['tax_amount' => '']))['columns']['tax_amount']);
        $this->assertRefused(AccountingError::RecurringExpenseInvalid, fn () => $this->normalised(RecurringKind::Expense, $this->expense(['tax_amount' => '230.01'])));
        $this->assertRefused(AccountingError::RecurringExpenseInvalid, fn () => $this->normalised(RecurringKind::Expense, $this->expense(['tax_amount' => '-1'])));
    }

    public function testNormalise_TakesTheBranchOfTheEmployeeForAnExpenseWithoutATreasuryAndTrimsTheSupplierCode(): void
    {
        $result = $this->normalised(RecurringKind::Expense, $this->expense(['supplier_tax_code' => '  300123  ']));

        $this->assertSame('300123', $result['columns']['supplier_tax_code']);
        $this->assertNull($this->normalised(RecurringKind::Expense, $this->expense(['supplier_tax_code' => '  ']))['columns']['supplier_tax_code']);
        $this->assertSame((new BranchGuard($this->accountingDb()))->defaultBranchId($this->employeeId), $result['columns']['branch_id']);
        $this->assertNull($result['columns']['treasury_id']);
        $this->assertRefused(AccountingError::RecurringExpenseInvalid, fn () => $this->normalised(RecurringKind::Expense, $this->expense(['supplier_tax_code' => str_repeat('9', 256)])));
    }

    public function testNormalise_RefusesAnExpenseCategoryThatIsDeleted(): void
    {
        $categoryId = $this->category('Gone');
        $this->accountingDb()->table('expense_categories')->where('expense_category_id', $categoryId)->update(['deleted' => 1]);

        $this->assertRefused(AccountingError::RecurringExpenseInvalid, fn () => $this->normalised(RecurringKind::Expense, $this->expense(['expense_category_id' => $categoryId])));
    }

    public function testNormalise_StoresTheRemindersWithTheirDaysOnlyWhereTheyBelong(): void
    {
        $result = $this->normalised(RecurringKind::Invoice, $this->invoice(['reminders' => [['send_when' => '0', 'days' => '9'], ['send_when' => '3', 'days' => '12'], ['send_when' => '4', 'days' => '0'], ['send_when' => '', 'days' => '3']]]));

        $this->assertSame([['send_when' => 0, 'days' => null], ['send_when' => 3, 'days' => 12], ['send_when' => 4, 'days' => 0]], $result['reminders']);
        $this->assertSame([], $this->normalised(RecurringKind::Expense, $this->expense(['reminders' => [['send_when' => '1', 'days' => '']]]))['reminders'], 'an expense has no reminders');
    }

    public function testNormalise_RefusesRemindersThatAreNotARowEachOrHaveMoreDaysThanFourDigits(): void
    {
        $this->assertRefused(AccountingError::RecurringRemindersInvalid, fn () => $this->normalised(RecurringKind::Invoice, $this->invoice(['reminders' => ['soon']])));
        $this->assertRefused(AccountingError::RecurringRemindersInvalid, fn () => $this->normalised(RecurringKind::Invoice, $this->invoice(['reminders' => [['send_when' => '3', 'days' => '12345']]])));
        $this->assertRefused(AccountingError::RecurringRemindersInvalid, fn () => $this->normalised(RecurringKind::Invoice, $this->invoice(['reminders' => [['send_when' => '-1', 'days' => '']]])));
        $this->assertSame(9999, $this->normalised(RecurringKind::Invoice, $this->invoice(['reminders' => [['send_when' => '3', 'days' => '9999']]]))['reminders'][0]['days']);
    }

    /**
     * @return array<string, array{0: RecurringKind, 1: string, 2: AccountingError}>
     */
    public static function listFieldProvider(): array
    {
        return [
            'frequency'          => [RecurringKind::Expense, 'frequency', AccountingError::RecurringFrequencyInvalid],
            'end date'           => [RecurringKind::Expense, 'end_date', AccountingError::RecurringDateInvalid],
            'number of dates'    => [RecurringKind::Expense, 'max_repeats', AccountingError::RecurringMaxRepeatInvalid],
            'tax of the expense' => [RecurringKind::Expense, 'tax_amount', AccountingError::RecurringExpenseInvalid],
            'supplier'           => [RecurringKind::Expense, 'supplier_id', AccountingError::RecurringExpenseInvalid],
            'issue before'       => [RecurringKind::Invoice, 'issue_before_days', AccountingError::RecurringIssueBeforeInvalid],
            'payment terms'      => [RecurringKind::Invoice, 'payment_terms_days', AccountingError::PaymentTermsInvalid],
        ];
    }

    #[DataProvider('listFieldProvider')]
    public function testNormalise_RefusesAFieldThatCameAsAListWithTheErrorOfTheField(RecurringKind $kind, string $field, AccountingError $error): void
    {
        $input = $kind === RecurringKind::Invoice ? $this->invoice([$field => ['1']]) : $this->expense([$field => ['1']]);

        $this->assertRefused($error, fn () => $this->normalised($kind, $input));
    }

    public function testNormalise_RefusesAReminderWhoseFieldsCameAsLists(): void
    {
        $this->assertRefused(AccountingError::RecurringRemindersInvalid, fn () => $this->normalised(RecurringKind::Invoice, $this->invoice(['reminders' => [['send_when' => ['3'], 'days' => '1']]])));
        $this->assertRefused(AccountingError::RecurringRemindersInvalid, fn () => $this->normalised(RecurringKind::Invoice, $this->invoice(['reminders' => [['send_when' => '3', 'days' => ['1']]]])));
    }

    public function testNormalise_TakesTheDefaultBranchOfTheOwnerOfTheScheduleAndNotOfWhoEditsIt(): void
    {
        $second = $this->createBranch('B2');
        $owner  = $this->employeeWithGrants([], [$second]);
        $editor = $this->employeeWithGrants([]);

        $result = $this->normalised(RecurringKind::Expense, $this->expense(), false, $editor, $owner);

        $this->assertSame($second, (int) $result['columns']['branch_id']);
        $this->assertNotSame($second, (new BranchGuard($this->accountingDb()))->defaultBranchId($editor), 'the editor works from another branch');
    }

    public function testNormalise_RefusesWhatTheOwnerOfTheScheduleCouldNotMakeEvenWhenTheEditorMay(): void
    {
        $second = $this->createBranch('B2');
        $owner  = $this->employeeWithGrants([], [$second]);
        $editor = $this->employeeWithGrants([]);
        $drawer = $this->treasury('Main drawer', 1);

        foreach ([
            [RecurringKind::Invoice, $this->invoice(['location_id' => self::LOCATION_ID])],
            [RecurringKind::Expense, $this->expense(['treasury_id' => $drawer])],
        ] as [$kind, $input]) {
            try {
                $this->normalised($kind, $input, false, $editor, $owner);
                $this->fail('A schedule whose owner does not work in the branch is refused: ' . $kind->value);
            } catch (BranchException $refusal) {
                $this->assertSame(BranchError::NotAllowed, $refusal->error);
            }

            $this->assertSame(1, (int) $this->normalised($kind, $input, false, $editor, $editor)['columns']['branch_id'], 'the editor alone may');
        }
    }
}
