<?php

namespace Tests\Libraries\Numbering;

use App\Libraries\Accounting\AccountingError;
use App\Libraries\Accounting\AccountingException;
use App\Libraries\Accounting\AccountingMessage;
use App\Libraries\Numbering\DocumentNumbering;
use App\Libraries\Numbering\DocumentType;
use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\DatabaseTestTrait;
use Config\Database;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Support\AccountingFixtureTrait;
use Tests\Support\EmployeeFixtureTrait;
use Throwable;

class DocumentNumberingTest extends CIUnitTestCase
{
    use DatabaseTestTrait;
    use AccountingFixtureTrait;
    use EmployeeFixtureTrait;

    protected $migrate     = true;
    protected $migrateOnce = true;
    protected $refresh     = false;
    protected $namespace   = null;

    private DocumentNumbering $numbering;

    /** @var list<int> */
    private array $expenseIds = [];

    private ?int $categoryId = null;

    public static function setUpBeforeClass(): void
    {
        $seeder = Database::seeder('tests');
        $seeder->call('TestDatabaseBootstrapSeeder');
    }

    protected function setUp(): void
    {
        parent::setUp();

        $this->releaseOpenTransactions();
        $this->clearNumbering();
        $this->numbering = new DocumentNumbering($this->accountingDb());
    }

    protected function tearDown(): void
    {
        $this->releaseOpenTransactions();
        $this->clearNumbering();
        $this->removeExpenses();
        $this->removeInsertedEntities();

        parent::tearDown();
    }

    public function testSequence_StartsSwitchedOffWithTheShapeOfToday(): void
    {
        foreach (DocumentType::cases() as $type) {
            $sequence = $this->numbering->sequence($type);

            $this->assertFalse($sequence->enabled, $type->value);
            $this->assertSame('', $sequence->prefix, $type->value);
            $this->assertSame(0, $sequence->padding, $type->value);
        }

        $this->assertSame(0, $this->countRows('document_sequences'));
        $this->assertSame(0, $this->countRows('document_numbers'));
    }

    public function testAssign_GivesNothingWhileTheSequenceIsOff(): void
    {
        $this->assertNull($this->numbering->assign(DocumentType::Expense, 'expenses', 41));
        $this->assertSame(0, $this->countRows('document_numbers'));
        $this->assertSame(0, $this->countRows('document_sequences'));
    }

    public function testAssign_GivesConsecutiveNumbersWithThePrefixAndTheDigitsChosen(): void
    {
        $this->numbering->configure(DocumentType::CreditNote, ['enabled' => true, 'prefix' => 'CN-', 'padding' => 6]);

        $this->assertSame('CN-000001', $this->numbering->assign(DocumentType::CreditNote, 'sales', 11)?->number);
        $this->assertSame('CN-000002', $this->numbering->assign(DocumentType::CreditNote, 'sales', 12)?->number);
        $this->assertSame(3, $this->numbering->sequence(DocumentType::CreditNote)->nextNumber);
    }

    public function testAssign_WithoutDigitsOrPrefixGivesTheBareNumber(): void
    {
        $this->numbering->configure(DocumentType::CreditNote, ['enabled' => true]);

        $this->assertSame('1', $this->numbering->assign(DocumentType::CreditNote, 'sales', 11)?->number);
    }

    public function testAssign_ReturnsTheNumberADocumentAlreadyHasAndTakesNoSecondOne(): void
    {
        $this->numbering->configure(DocumentType::CreditNote, ['enabled' => true, 'padding' => 3]);

        $first  = $this->numbering->assign(DocumentType::CreditNote, 'sales', 11);
        $second = $this->numbering->assign(DocumentType::CreditNote, 'sales', 11);

        $this->assertSame('001', $second?->number);
        $this->assertSame($first?->sequence, $second?->sequence);
        $this->assertSame(2, $this->numbering->sequence(DocumentType::CreditNote)->nextNumber);
        $this->assertSame(1, $this->countRows('document_numbers'));
    }

    public function testAssign_KeepsASeparateSequenceForEachKindOfDocument(): void
    {
        $this->numbering->configure(DocumentType::CreditNote, ['enabled' => true, 'padding' => 4]);
        $this->numbering->configure(DocumentType::DebitNote, ['enabled' => true, 'padding' => 4]);

        $this->assertSame('0001', $this->numbering->assign(DocumentType::CreditNote, 'sales', 11)?->number);
        $this->assertSame('0001', $this->numbering->assign(DocumentType::DebitNote, 'receivings', 11)?->number);
        $this->assertSame('0002', $this->numbering->assign(DocumentType::CreditNote, 'sales', 12)?->number);
        $this->assertSame('0002', $this->numbering->assign(DocumentType::DebitNote, 'receivings', 12)?->number);
    }

    public function testAssign_KeepsASeparateSequenceForEachCompany(): void
    {
        $db = $this->accountingDb();
        $db->table('companies')->insert(['company_id' => 2, 'name' => 'Second company']);
        $db->table('branches')->insert(['company_id' => 2, 'code' => 'C2', 'name' => 'Company two', 'active' => 1]);
        $branchOfSecond = (int) $db->insertID();

        try {
            $this->numbering->configure(DocumentType::CreditNote, ['enabled' => true, 'padding' => 4]);
            $this->numbering->configure(DocumentType::CreditNote, ['enabled' => true, 'padding' => 4], null, 2);

            $this->assertSame('0001', $this->numbering->assign(DocumentType::CreditNote, 'sales', 11)?->number);
            $this->assertSame('0001', $this->numbering->assign(DocumentType::CreditNote, 'sales', 12, $branchOfSecond)?->number);
            $this->assertSame('0002', $this->numbering->assign(DocumentType::CreditNote, 'sales', 13)?->number);
        } finally {
            $this->clearNumbering();
            $db->table('branches')->where('branch_id', $branchOfSecond)->delete();
            $db->table('companies')->where('company_id', 2)->delete();
        }
    }

    public function testAssign_TakesTheCompanyOfTheBranchOfTheDocument(): void
    {
        $db = $this->accountingDb();
        $db->table('companies')->insert(['company_id' => 2, 'name' => 'Second company']);
        $db->table('branches')->insert(['company_id' => 2, 'code' => 'C2', 'name' => 'Company two', 'active' => 1]);
        $branchOfSecond = (int) $db->insertID();

        try {
            $this->numbering->configure(DocumentType::CreditNote, ['enabled' => true], null, 2);

            $this->assertNull($this->numbering->assign(DocumentType::CreditNote, 'sales', 11));
            $this->assertSame('1', $this->numbering->assign(DocumentType::CreditNote, 'sales', 12, $branchOfSecond)?->number);
            $this->assertSame(2, (int) $db->table('document_numbers')->where('source_id', 12)->get()->getRow()->company_id);
        } finally {
            $this->clearNumbering();
            $db->table('branches')->where('branch_id', $branchOfSecond)->delete();
            $db->table('companies')->where('company_id', 2)->delete();
        }
    }

    public function testAssign_RejectsATableThatDoesNotHoldTheKindOfDocument(): void
    {
        $this->numbering->configure(DocumentType::CreditNote, ['enabled' => true]);

        $this->assertRefusal(AccountingError::NumberingSourceInvalid, fn () => $this->numbering->assign(DocumentType::CreditNote, 'expenses', 11));
        $this->assertRefusal(AccountingError::NumberingSourceInvalid, fn () => $this->numbering->assign(DocumentType::CreditNote, 'sales', 0));
        $this->assertSame(0, $this->countRows('document_numbers'));
    }

    public function testAssign_WritesTheNumberOfACustomerInItsAccountNumber(): void
    {
        $customerId = $this->insertCustomer();
        $this->numbering->configure(DocumentType::Customer, ['enabled' => true, 'prefix' => 'C', 'padding' => 5]);

        $number = $this->numbering->assign(DocumentType::Customer, 'customers', $customerId);

        $this->assertSame('C00001', $number?->number);
        $this->assertSame('C00001', $this->accountingDb()->table('customers')->where('person_id', $customerId)->get()->getRow()->account_number);
    }

    public function testAssign_SkipsANumberAnotherCustomerAlreadyHasInItsAccountNumber(): void
    {
        $typedBy  = $this->insertCustomer('Typed', 'ByHand');
        $customer = $this->insertCustomer('Auto', 'Numbered');
        $this->numbering->configure(DocumentType::Customer, ['enabled' => true, 'prefix' => 'C', 'padding' => 5, 'next_number' => 1]);
        $this->accountingDb()->table('customers')->where('person_id', $typedBy)->update(['account_number' => 'C00001']);

        $this->assertSame('C00002', $this->numbering->assign(DocumentType::Customer, 'customers', $customer)?->number);
        $this->assertSame(3, $this->numbering->sequence(DocumentType::Customer)->nextNumber);
    }

    public function testAssign_SkipsANumberThatAnEarlierPrefixAlreadyGave(): void
    {
        $this->numbering->configure(DocumentType::CreditNote, ['enabled' => true, 'prefix' => 'A1', 'next_number' => 5]);
        $this->assertSame('A15', $this->numbering->assign(DocumentType::CreditNote, 'sales', 11)?->number);

        $this->numbering->configure(DocumentType::CreditNote, ['prefix' => 'A', 'next_number' => 15]);

        $this->assertSame('A16', $this->numbering->assign(DocumentType::CreditNote, 'sales', 12)?->number);
        $this->assertSame(['A15', 'A16'], array_column($this->accountingDb()->table('document_numbers')->select('number')->orderBy('number_id')->get()->getResultArray(), 'number'));
    }

    public function testAssign_NeverGivesANumberAgainWhenTheDocumentIsGone(): void
    {
        $this->numbering->configure(DocumentType::Expense, ['enabled' => true, 'padding' => 2]);
        $this->numbering->assign(DocumentType::Expense, 'expenses', 5);
        $this->accountingDb()->table('document_numbers')->where('source_id', 5)->update(['source_id' => 999999]);

        $this->assertSame('02', $this->numbering->assign(DocumentType::Expense, 'expenses', 5)?->number);
    }

    public function testAssign_LeavesNoNumberBehindWhenTheTransactionRollsBack(): void
    {
        $db = $this->accountingDb();
        $this->numbering->configure(DocumentType::CreditNote, ['enabled' => true, 'padding' => 3]);

        $db->transStart();
        $this->assertSame('001', $this->numbering->assign(DocumentType::CreditNote, 'sales', 11)?->number);
        $db->transRollback();
        $db->resetTransStatus();

        $this->assertSame(0, $this->countRows('document_numbers'));
        $this->assertSame(1, $this->numbering->sequence(DocumentType::CreditNote)->nextNumber);
        $this->assertSame('001', $this->numbering->assign(DocumentType::CreditNote, 'sales', 12)?->number);
    }

    public function testAssign_JoinsTheTransactionOfTheCallerAndIsKeptWithIt(): void
    {
        $db = $this->accountingDb();
        $this->numbering->configure(DocumentType::CreditNote, ['enabled' => true, 'padding' => 3]);

        $db->transStart();
        $this->numbering->assign(DocumentType::CreditNote, 'sales', 11);
        $this->numbering->assign(DocumentType::CreditNote, 'sales', 12);
        $db->transComplete();

        $this->assertSame(2, $this->countRows('document_numbers'));
        $this->assertSame(3, $this->numbering->sequence(DocumentType::CreditNote)->nextNumber);
    }

    public function testAssign_WaitsForAnotherConnectionThatHoldsTheSequence(): void
    {
        $this->numbering->configure(DocumentType::CreditNote, ['enabled' => true, 'padding' => 3]);

        $other   = $this->openSecondConnection();
        $holding = new DocumentNumbering($other);

        $other->transStart();
        $this->assertSame('001', $holding->assign(DocumentType::CreditNote, 'sales', 11)?->number);

        try {
            $this->assertWaitsForLock(fn () => $this->numbering->assign(DocumentType::CreditNote, 'sales', 12));
        } finally {
            $other->transComplete();
            $other->close();
        }

        $this->assertSame('002', $this->numbering->assign(DocumentType::CreditNote, 'sales', 12)?->number);
    }

    public function testAssign_NeverRepeatsANumberAcrossTwoConnections(): void
    {
        $this->numbering->configure(DocumentType::CreditNote, ['enabled' => true, 'padding' => 4]);

        $other   = $this->openSecondConnection();
        $second  = new DocumentNumbering($other);
        $numbers = [];

        try {
            for ($document = 1; $document <= 10; $document++) {
                $service = $document % 2 === 0 ? $second : $this->numbering;
                $db      = $document % 2 === 0 ? $other : $this->accountingDb();

                $db->transStart();
                $numbers[] = $service->assign(DocumentType::CreditNote, 'sales', 100 + $document)?->number;
                $db->transComplete();
            }
        } finally {
            $other->close();
        }

        $this->assertSame(['0001', '0002', '0003', '0004', '0005', '0006', '0007', '0008', '0009', '0010'], $numbers);
        $this->assertSame(10, $this->countRows('document_numbers'));
    }

    public function testAssign_SerialisesTwoOpenTransactionsOnTheSameSequence(): void
    {
        $this->numbering->configure(DocumentType::CreditNote, ['enabled' => true, 'padding' => 3]);

        $other  = $this->openSecondConnection();
        $second = new DocumentNumbering($other);
        $db     = $this->accountingDb();

        $db->transStart();
        $first = $this->numbering->assign(DocumentType::CreditNote, 'sales', 11)?->number;

        $blocked = false;
        $other->transStart();

        try {
            $second->assign(DocumentType::CreditNote, 'sales', 12);
        } catch (Throwable) {
            $blocked = true;
        }

        $other->transRollback();
        $other->resetTransStatus();
        $db->transComplete();

        $afterwards = $second->assign(DocumentType::CreditNote, 'sales', 12)?->number;
        $other->close();

        $this->assertTrue($blocked);
        $this->assertSame('001', $first);
        $this->assertSame('002', $afterwards);
    }

    public function testFloor_StaysAboveTheIdsOfDocumentsThatShowTheirId(): void
    {
        $this->insertExpense();
        $second = $this->insertExpense();

        $this->assertSame($second + 1, $this->numbering->floor(DocumentType::Expense, ''));
        $this->assertSame(1, $this->numbering->floor(DocumentType::Expense, 'EXP-'));
    }

    public function testFloor_IgnoresTheIdsOfDocumentsThatHaveANumber(): void
    {
        $first  = $this->insertExpense();
        $second = $this->insertExpense();
        $this->numbering->configure(DocumentType::Expense, ['enabled' => true]);
        $this->accountingDb()->table('document_numbers')->insert(['company_id' => 1, 'doc_type' => 'expense', 'source_table' => 'expenses', 'source_id' => $second, 'sequence_number' => $second + 1, 'number' => (string) ($second + 1)]);

        $this->assertSame($second + 2, $this->numbering->floor(DocumentType::Expense, ''));
        $this->assertGreaterThan($first, $second);
    }

    public function testFloor_DoesNotUseIdsForADocumentThatNeverShowedItsIdAlone(): void
    {
        $this->assertSame(1, $this->numbering->floor(DocumentType::CreditNote, ''));
        $this->assertSame(1, $this->numbering->floor(DocumentType::Purchase, ''));
    }

    public function testFloor_StaysAboveTheAccountNumbersOfTheSameShape(): void
    {
        $first  = $this->insertCustomer('One', 'Customer');
        $second = $this->insertCustomer('Two', 'Customer');
        $third  = $this->insertCustomer('Three', 'Customer');
        $this->accountingDb()->table('customers')->where('person_id', $first)->update(['account_number' => 'C00007']);
        $this->accountingDb()->table('customers')->where('person_id', $second)->update(['account_number' => 'C00003']);
        $this->accountingDb()->table('customers')->where('person_id', $third)->update(['account_number' => 'X99']);

        $this->assertSame(8, $this->numbering->floor(DocumentType::Customer, 'C'));
        $this->assertSame(1, $this->numbering->floor(DocumentType::Customer, 'K'));
        $this->assertSame(100, $this->numbering->floor(DocumentType::Customer, 'X'));
    }

    public function testFloor_IgnoresAccountNumbersThatAreNotJustDigitsAfterThePrefix(): void
    {
        $customer = $this->insertCustomer();
        $this->accountingDb()->table('customers')->where('person_id', $customer)->update(['account_number' => 'C12-B']);

        $this->assertSame(1, $this->numbering->floor(DocumentType::Customer, 'C'));
    }

    public function testConfigure_StartsTheSequenceAboveTheDocumentsThatExist(): void
    {
        $this->insertExpense();
        $last = $this->insertExpense();

        $sequence = $this->numbering->configure(DocumentType::Expense, ['enabled' => true]);

        $this->assertSame($last + 1, $sequence->nextNumber);
        $this->assertSame((string) ($last + 1), $this->numbering->assign(DocumentType::Expense, 'expenses', $last + 5)?->number);
    }

    public function testConfigure_RejectsANextNumberBelowADocumentThatShowsItsId(): void
    {
        $last = $this->insertExpense();

        $this->assertRefusal(AccountingError::NumberingNextTooLow, fn () => $this->numbering->configure(DocumentType::Expense, ['enabled' => true, 'next_number' => $last]));
        $this->assertSame(0, $this->countRows('document_sequences'));
    }

    public function testConfigure_AcceptsARaisedNextNumber(): void
    {
        $this->numbering->configure(DocumentType::CreditNote, ['enabled' => true, 'padding' => 4]);
        $this->numbering->assign(DocumentType::CreditNote, 'sales', 11);

        $this->numbering->configure(DocumentType::CreditNote, ['next_number' => 500]);

        $this->assertSame('0500', $this->numbering->assign(DocumentType::CreditNote, 'sales', 12)?->number);
    }

    public function testConfigure_RejectsLoweringTheNextNumber(): void
    {
        $this->numbering->configure(DocumentType::CreditNote, ['enabled' => true, 'padding' => 4, 'next_number' => 40]);

        $this->assertRefusal(AccountingError::NumberingNextTooLow, fn () => $this->numbering->configure(DocumentType::CreditNote, ['next_number' => 39]));
        $this->assertSame(40, $this->numbering->sequence(DocumentType::CreditNote)->nextNumber);
    }

    public function testConfigure_RejectsANextNumberAtOrBelowANumberAlreadyGiven(): void
    {
        $this->numbering->configure(DocumentType::CreditNote, ['enabled' => true]);

        foreach ([11, 12, 13] as $saleId) {
            $this->numbering->assign(DocumentType::CreditNote, 'sales', $saleId);
        }

        $this->assertRefusal(AccountingError::NumberingNextTooLow, fn () => $this->numbering->configure(DocumentType::CreditNote, ['next_number' => 3]));
        $this->numbering->configure(DocumentType::CreditNote, ['next_number' => 4]);
    }

    public function testConfigure_KeepsTheCounterWhenThePrefixChanges(): void
    {
        $this->numbering->configure(DocumentType::CreditNote, ['enabled' => true, 'prefix' => 'A-', 'next_number' => 10]);
        $this->numbering->assign(DocumentType::CreditNote, 'sales', 11);

        $sequence = $this->numbering->configure(DocumentType::CreditNote, ['prefix' => 'B-']);

        $this->assertSame(11, $sequence->nextNumber);
        $this->assertSame('B-11', $this->numbering->assign(DocumentType::CreditNote, 'sales', 12)?->number);
    }

    public function testConfigure_KeepsTheNumbersWhenTheSequenceIsSwitchedOffAndOn(): void
    {
        $this->numbering->configure(DocumentType::CreditNote, ['enabled' => true, 'padding' => 3]);
        $this->numbering->assign(DocumentType::CreditNote, 'sales', 11);
        $this->numbering->configure(DocumentType::CreditNote, ['enabled' => false]);

        $this->assertNull($this->numbering->assign(DocumentType::CreditNote, 'sales', 12));

        $this->numbering->configure(DocumentType::CreditNote, ['enabled' => true]);

        $this->assertSame('002', $this->numbering->assign(DocumentType::CreditNote, 'sales', 13)?->number);
        $this->assertSame('001', $this->numbering->label(DocumentType::CreditNote, 'sales', 11));
    }

    public function testConfigure_RaisesTheNextNumberAboveDocumentsMadeWhileTheSequenceWasOff(): void
    {
        $this->numbering->configure(DocumentType::Expense, ['enabled' => true]);
        $this->numbering->configure(DocumentType::Expense, ['enabled' => false]);
        $later = $this->insertExpense();

        $this->assertRefusal(AccountingError::NumberingNextTooLow, fn () => $this->numbering->configure(DocumentType::Expense, ['enabled' => true, 'next_number' => 1]));
        $this->assertSame($later + 1, $this->numbering->configure(DocumentType::Expense, ['enabled' => true])->nextNumber);
    }

    #[DataProvider('invalidSettingsProvider')]
    public function testConfigure_RejectsInvalidSettings(array $input, AccountingError $error): void
    {
        $this->assertRefusal($error, fn () => $this->numbering->configure(DocumentType::CreditNote, $input));
        $this->assertSame(0, $this->countRows('document_sequences'));
    }

    /**
     * @return array<string, array{0: array<string, mixed>, 1: AccountingError}>
     */
    public static function invalidSettingsProvider(): array
    {
        return [
            'prefix with a space'      => [['prefix' => 'CN 1'], AccountingError::NumberingPrefixInvalid],
            'prefix with a quote'      => [['prefix' => "CN'"], AccountingError::NumberingPrefixInvalid],
            'prefix with a percent'    => [['prefix' => 'CN%'], AccountingError::NumberingPrefixInvalid],
            'prefix of 17 characters'  => [['prefix' => str_repeat('A', 17)], AccountingError::NumberingPrefixInvalid],
            'negative digits'          => [['padding' => -1], AccountingError::NumberingPaddingInvalid],
            'eleven digits'            => [['padding' => 11], AccountingError::NumberingPaddingInvalid],
            'digits that are text'     => [['padding' => 'six'], AccountingError::NumberingPaddingInvalid],
            'empty digits'             => [['padding' => ''], AccountingError::NumberingPaddingInvalid],
            'next number zero'         => [['next_number' => 0], AccountingError::NumberingNextInvalid],
            'next number negative'     => [['next_number' => -5], AccountingError::NumberingNextInvalid],
            'next number a decimal'    => [['next_number' => '12.5'], AccountingError::NumberingNextInvalid],
            'next number too large'    => [['next_number' => 2_000_000_001], AccountingError::NumberingNextInvalid],
            'next number text'         => [['next_number' => 'abc'], AccountingError::NumberingNextInvalid],
        ];
    }

    public function testConfigure_AcceptsTheEdgesOfTheSettings(): void
    {
        $sequence = $this->numbering->configure(DocumentType::CreditNote, [
            'enabled'     => true,
            'prefix'      => str_repeat('ب', 16),
            'padding'     => 10,
            'next_number' => 2_000_000_000,
        ]);

        $this->assertSame(10, $sequence->padding);
        $this->assertSame(str_repeat('ب', 16) . '2000000000', $this->numbering->assign(DocumentType::CreditNote, 'sales', 11)?->number);
        $this->assertSame(str_repeat('ب', 16), $this->numbering->sequence(DocumentType::CreditNote)->prefix);
    }

    public function testConfigure_AcceptsArabicLettersAndTheSeparatorsInAPrefix(): void
    {
        $sequence = $this->numbering->configure(DocumentType::CreditNote, ['enabled' => true, 'prefix' => 'إشعار-2026/#.', 'padding' => 3]);

        $this->assertSame('إشعار-2026/#.', $sequence->prefix);
        $this->assertSame('إشعار-2026/#.001', $this->numbering->assign(DocumentType::CreditNote, 'sales', 11)?->number);
    }

    public function testConfigure_TrimsThePrefix(): void
    {
        $this->assertSame('CN-', $this->numbering->configure(DocumentType::CreditNote, ['prefix' => '  CN-  '])->prefix);
    }

    public function testConfigure_RejectsAnEmployeeWithoutTheSettingsPermission(): void
    {
        $employee = $this->employeeWithGrants(['sales']);

        $this->assertRefusal(AccountingError::PermissionDenied, fn () => $this->numbering->configure(DocumentType::CreditNote, ['enabled' => true], $employee));
        $this->assertSame(0, $this->countRows('document_sequences'));
        $this->assertSame(0, $this->accountingDb()->table('accounting_audit_log')->where('entity_type', 'document_numbering')->countAllResults());
    }

    public function testConfigure_AcceptsAnEmployeeWithTheSettingsPermission(): void
    {
        $employee = $this->employeeWithGrants(['config']);

        $sequence = $this->numbering->configure(DocumentType::CreditNote, ['enabled' => true], $employee);

        $this->assertTrue($sequence->enabled);
    }

    public function testConfigure_WritesWhatChangedInTheAuditLog(): void
    {
        $employee = $this->employeeWithGrants(['config']);

        $this->numbering->configure(DocumentType::CreditNote, ['enabled' => true, 'prefix' => 'CN-', 'padding' => 6], $employee);

        $entry = $this->accountingDb()->table('accounting_audit_log')->where('entity_type', 'document_numbering')->get()->getRow();

        $this->assertNotNull($entry);
        $this->assertSame('numbering_configured', $entry->action);
        $this->assertSame($employee, (int) $entry->employee_id);
        $this->assertSame('user', $entry->origin);

        $details = json_decode((string) $entry->details, true);

        $this->assertSame('credit_note', $details['doc_type']);
        $this->assertFalse($details['before']['enabled']);
        $this->assertTrue($details['after']['enabled']);
        $this->assertSame('CN-', $details['after']['prefix']);
        $this->assertSame(6, $details['after']['padding']);
    }

    public function testConfigure_WritesNothingWhenNothingChanges(): void
    {
        $this->numbering->configure(DocumentType::CreditNote, ['enabled' => true, 'prefix' => 'CN-']);
        $this->numbering->configure(DocumentType::CreditNote, ['enabled' => true, 'prefix' => 'CN-']);

        $this->assertSame(1, $this->accountingDb()->table('accounting_audit_log')->where('entity_type', 'document_numbering')->countAllResults());
    }

    public function testConfigure_RecordsTheSystemWhenNoEmployeeActs(): void
    {
        $this->numbering->configure(DocumentType::CreditNote, ['enabled' => true]);

        $entry = $this->accountingDb()->table('accounting_audit_log')->where('entity_type', 'document_numbering')->get()->getRow();

        $this->assertSame('system', $entry->origin);
        $this->assertNull($entry->employee_id);
    }

    public function testConfigure_RejectsAnUnknownCompany(): void
    {
        $this->assertRefusal(AccountingError::WriteFailed, fn () => $this->numbering->configure(DocumentType::CreditNote, ['enabled' => true], null, 987654));
    }

    public function testOverview_ListsEveryKindWithTheLowestNextNumberItMayGive(): void
    {
        $last = $this->insertExpense();
        $this->numbering->configure(DocumentType::CreditNote, ['enabled' => true, 'prefix' => 'CN-', 'padding' => 4]);

        $rows = [];

        foreach ($this->numbering->overview() as $sequence) {
            $rows[$sequence->type->value] = $sequence;
        }

        $this->assertCount(count(DocumentType::cases()), $rows);
        $this->assertTrue($rows['credit_note']->enabled);
        $this->assertSame('CN-0001', $rows['credit_note']->preview());
        $this->assertSame($last + 1, $rows['expense']->nextNumber);
        $this->assertFalse($rows['expense']->enabled);
    }

    public function testNumbers_ReturnsTheNumbersOfTheDocumentsThatHaveOne(): void
    {
        $this->numbering->configure(DocumentType::CreditNote, ['enabled' => true, 'padding' => 3]);
        $this->numbering->assign(DocumentType::CreditNote, 'sales', 21);
        $this->numbering->assign(DocumentType::CreditNote, 'sales', 22);

        $this->assertSame([21 => '001', 22 => '002'], $this->numbering->numbers(DocumentType::CreditNote, 'sales', [21, 22, 23, 0]));
        $this->assertSame([], $this->numbering->numbers(DocumentType::CreditNote, 'sales', []));
        $this->assertSame([], $this->numbering->numbers(DocumentType::CreditNote, 'expenses', [21]));
        $this->assertSame([], $this->numbering->numbers(DocumentType::DebitNote, 'receivings', [21]));
    }

    public function testLabel_FallsBackToWhatTheDocumentShowedBeforeThereWasANumber(): void
    {
        $this->assertSame('POS 41', $this->numbering->label(DocumentType::CreditNote, 'sales', 41));
        $this->assertSame('RECV 41', $this->numbering->label(DocumentType::Purchase, 'receivings', 41));
        $this->assertSame('RECV 41', $this->numbering->label(DocumentType::DebitNote, 'receivings', 41));
        $this->assertSame('41', $this->numbering->label(DocumentType::CustomerPayment, 'sales_payments', 41));
        $this->assertSame('41', $this->numbering->label(DocumentType::SupplierPayment, 'receivings_payments', 41));
        $this->assertSame('41', $this->numbering->label(DocumentType::Expense, 'expenses', 41));
    }

    public function testLabel_ShowsTheNumberOnceTheDocumentHasOne(): void
    {
        $this->numbering->configure(DocumentType::CreditNote, ['enabled' => true, 'prefix' => 'CN-', 'padding' => 4]);
        $this->numbering->assign(DocumentType::CreditNote, 'sales', 41);

        $this->assertSame('CN-0001', $this->numbering->label(DocumentType::CreditNote, 'sales', 41));
        $this->assertSame('POS 42', $this->numbering->label(DocumentType::CreditNote, 'sales', 42));
    }

    public function testDocumentType_SaysWhereEveryKindIsStored(): void
    {
        foreach (DocumentType::cases() as $type) {
            $this->assertNotSame([], $type->sources(), $type->value);
        }

        $this->assertSame('invoice_number', DocumentType::SalesInvoice->numberColumn());
        $this->assertSame('quote_number', DocumentType::SalesQuote->numberColumn());
        $this->assertSame('account_number', DocumentType::Customer->numberColumn());
        $this->assertNull(DocumentType::CreditNote->numberColumn());
        $this->assertSame(['receivings_payments' => 'payment_id', 'supplier_advances' => 'advance_id'], DocumentType::SupplierPayment->sources());
    }

    public function testAccountingFile_ExplainsEveryNumberingRefusalInEveryTranslatedLocale(): void
    {
        foreach (['en', 'en-GB', 'ar-EG', 'ar-LB'] as $locale) {
            $messages = include __DIR__ . "/../../../app/Language/{$locale}/Accounting.php";

            foreach (AccountingError::cases() as $error) {
                if (str_starts_with($error->value, 'numbering_')) {
                    $this->assertNotSame('', $messages['error_' . $error->value] ?? '', "{$locale} lacks {$error->value}");
                }
            }

            foreach (['action_numbering_configured', 'entity_document_numbering'] as $key) {
                $this->assertNotSame('', $messages[$key] ?? '', "{$locale} lacks {$key}");
            }
        }
    }

    public function testMessage_NamesTheLowestNextNumber(): void
    {
        try {
            $this->numbering->configure(DocumentType::Expense, ['enabled' => true, 'next_number' => 1]);
            $last = $this->insertExpense();
            $this->numbering->configure(DocumentType::Expense, ['next_number' => 1]);
            $this->fail('The next number should have been refused.');
        } catch (AccountingException $exception) {
            $this->assertStringContainsString((string) ($last + 1), AccountingMessage::of($exception));
        }
    }

    private function assertRefusal(AccountingError $error, callable $action): void
    {
        try {
            $action();
            $this->fail('The action should have been refused with ' . $error->value);
        } catch (AccountingException $exception) {
            $this->assertSame($error, $exception->error);
        }
    }

    private function clearNumbering(): void
    {
        $db = $this->accountingDb();
        $db->table('document_numbers')->truncate();
        $db->table('document_sequences')->truncate();
        $db->table('accounting_audit_log')->where('entity_type', 'document_numbering')->delete();
    }

    private function insertExpense(): int
    {
        $db = $this->accountingDb();

        if ($this->categoryId === null) {
            $db->table('expense_categories')->insert(['category_name' => 'Numbering test ' . uniqid(), 'category_description' => '', 'deleted' => 0]);
            $this->categoryId = (int) $db->insertID();
        }

        $db->table('expenses')->insert([
            'amount'              => '10.00',
            'payment_type'        => 'Cash',
            'expense_category_id' => $this->categoryId,
            'description'         => 'Numbering test',
            'employee_id'         => 1,
            'deleted'             => 0,
        ]);

        return $this->expenseIds[] = (int) $db->insertID();
    }

    private function removeExpenses(): void
    {
        $db = $this->accountingDb();

        if ($this->expenseIds !== []) {
            $db->table('expenses')->whereIn('expense_id', $this->expenseIds)->delete();
            $this->expenseIds = [];
        }

        if ($this->categoryId !== null) {
            $db->table('expense_categories')->where('expense_category_id', $this->categoryId)->delete();
            $this->categoryId = null;
        }
    }
}
