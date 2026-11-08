<?php

namespace Tests\Libraries\MasterData;

use App\Libraries\Accounting\AccountingError;
use App\Libraries\Accounting\AccountingException;
use App\Libraries\MasterData\CustomerFields;
use App\Libraries\MasterData\CustomerStatus;
use App\Libraries\MasterData\LookupKind;
use App\Libraries\MasterData\NamedLookup;
use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\DatabaseTestTrait;
use Config\Database;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Support\AccountingFixtureTrait;
use Tests\Support\EmployeeFixtureTrait;

/**
 * @internal
 */
final class CustomerFieldsTest extends CIUnitTestCase
{
    use DatabaseTestTrait;
    use AccountingFixtureTrait;
    use EmployeeFixtureTrait;

    protected $migrate     = true;
    protected $migrateOnce = true;
    protected $refresh     = false;
    protected $namespace;

    public static function setUpBeforeClass(): void
    {
        $seeder = Database::seeder('tests');
        $seeder->call('TestDatabaseBootstrapSeeder');
    }

    protected function setUp(): void
    {
        parent::setUp();

        $this->resetBranches();
    }

    protected function tearDown(): void
    {
        $db = $this->accountingDb();

        $db->query('DELETE FROM ' . $db->prefixTable('customer_classifications'));
        $db->table('accounting_audit_log')->where('action', 'customer_master_data_changed')->delete();
        $this->removeInsertedEntities();
        $this->resetBranches();

        parent::tearDown();
    }

    private function fields(): CustomerFields
    {
        return new CustomerFields();
    }

    private function refusalOf(callable $action): ?AccountingError
    {
        try {
            $action();
        } catch (AccountingException $failure) {
            return $failure->error;
        }

        return null;
    }

    /**
     * @param array<string, mixed> $posted
     *
     * @return array<string, int|string|null>
     */
    private function read(array $posted, ?object $current = null, ?int $viewer = null, string $email = 'omar@example.com'): array
    {
        return $this->fields()->read($posted, $current, $viewer, $email);
    }

    public function testRead_ReturnsTheColumnsOfEveryFieldThatWasGiven(): void
    {
        $classification = (new NamedLookup(LookupKind::CustomerClassification))->save('Gold', true, null, 1);
        $employee       = $this->employeeWithGrants(['customers']);

        $columns = $this->read([
            'classification_id'    => (string) $classification,
            'manual_status'        => 'interested',
            'assigned_employee_id' => (string) $employee,
            'area'                 => '  Olaya  ',
            'country_code'         => 'sa',
            'customer_type'        => 'commercial',
            'is_government_entity' => '1',
            'billing_method'       => 'email',
        ]);

        $this->assertSame([
            'classification_id'    => $classification,
            'manual_status'        => 'interested',
            'assigned_employee_id' => $employee,
            'area'                 => 'Olaya',
            'country_code'         => 'SA',
            'customer_type'        => 'commercial',
            'is_government_entity' => 1,
            'billing_method'       => 'email',
        ], $columns);
    }

    public function testRead_ClearsEveryFieldTheFormLeftEmpty(): void
    {
        $this->assertSame([
            'classification_id'    => null,
            'manual_status'        => null,
            'assigned_employee_id' => null,
            'area'                 => null,
            'country_code'         => null,
            'customer_type'        => null,
            'is_government_entity' => 0,
            'billing_method'       => 'print',
        ], $this->read([]));
    }

    public function testRead_RejectsAClassificationThatDoesNotExistOrIsInactiveButAcceptsTheOneTheCustomerHolds(): void
    {
        $lookup   = new NamedLookup(LookupKind::CustomerClassification);
        $inactive = $lookup->save('Old', false, null, 1);

        $this->assertSame(AccountingError::CustomerClassificationInvalid, $this->refusalOf(fn () => $this->read(['classification_id' => '987654'])));
        $this->assertSame(AccountingError::CustomerClassificationInvalid, $this->refusalOf(fn () => $this->read(['classification_id' => (string) $inactive])));
        $this->assertSame(AccountingError::CustomerClassificationInvalid, $this->refusalOf(fn () => $this->read(['classification_id' => 'abc'])));
        $this->assertSame($inactive, $this->read(['classification_id' => (string) $inactive], (object) ['classification_id' => $inactive])['classification_id']);
    }

    /**
     * @return array<string, array{0: string}>
     */
    public static function statusProvider(): array
    {
        $cases = [];

        foreach (CustomerStatus::cases() as $status) {
            $cases[$status->value] = [$status->value];
        }

        return $cases;
    }

    #[DataProvider('statusProvider')]
    public function testRead_AcceptsEachOfTheFiveStatuses(string $status): void
    {
        $this->assertSame($status, $this->read(['manual_status' => $status])['manual_status']);
    }

    public function testRead_RejectsAStatusOutsideTheFive(): void
    {
        $this->assertSame(AccountingError::CustomerStatusInvalid, $this->refusalOf(fn () => $this->read(['manual_status' => 'vip'])));
        $this->assertCount(5, CustomerStatus::cases());
    }

    public function testRead_RejectsATypeOutsideIndividualAndCommercial(): void
    {
        $this->assertSame('individual', $this->read(['customer_type' => 'individual'])['customer_type']);
        $this->assertSame(AccountingError::CustomerTypeInvalid, $this->refusalOf(fn () => $this->read(['customer_type' => 'both'])));
    }

    public function testRead_RejectsAnAreaOfMoreThanOneHundredCharactersAndAcceptsExactlyOneHundred(): void
    {
        $this->assertSame(100, mb_strlen((string) $this->read(['area' => str_repeat('م', 100)])['area']));
        $this->assertSame(AccountingError::CustomerAreaInvalid, $this->refusalOf(fn () => $this->read(['area' => str_repeat('م', 101)])));
    }

    /**
     * @return array<string, array{0: string}>
     */
    public static function badCountryProvider(): array
    {
        return ['three letters' => ['SAU'], 'one letter' => ['S'], 'a digit' => ['S1'], 'arabic letters' => ['سع'], 'a space inside' => ['S A']];
    }

    #[DataProvider('badCountryProvider')]
    public function testRead_RejectsACountryCodeThatIsNotTwoLetters(string $code): void
    {
        $this->assertSame(AccountingError::CountryCodeInvalid, $this->refusalOf(fn () => $this->read(['country_code' => $code])));
    }

    /**
     * @return array<string, array{string, int}>
     */
    public static function flagWordsProvider(): array
    {
        return ['empty' => ['', 0], 'zero' => ['0', 0], 'n' => ['n', 0], 'no in capitals' => [' NO ', 0], 'false' => ['false', 0], 'one' => ['1', 1], 'y' => ['y', 1], 'yes' => ['Yes', 1], 'true' => ['TRUE', 1]];
    }

    #[DataProvider('flagWordsProvider')]
    public function testFlag_ReadsAYesOrANoInAWordOrADigit(string $typed, int $expected): void
    {
        $this->assertSame($expected, CustomerFields::flag($typed));
    }

    #[DataProvider('badFlagWordsProvider')]
    public function testFlag_RejectsAnyOtherText(string $typed): void
    {
        $this->assertSame(AccountingError::CustomerGovernmentInvalid, $this->refusalOf(fn () => CustomerFields::flag($typed)));
    }

    /**
     * @return array<string, array{string}>
     */
    public static function badFlagWordsProvider(): array
    {
        return ['a maybe' => ['maybe'], 'two' => ['2'], 'a sentence' => ['government entity'], 'minus one' => ['-1']];
    }

    public function testRead_NormalisesACountryCodeToCapitals(): void
    {
        $this->assertSame('EG', $this->read(['country_code' => ' eg '])['country_code']);
    }

    public function testRead_RejectsABillingMethodOutsidePrintAndEmail(): void
    {
        $this->assertSame(AccountingError::CustomerBillingInvalid, $this->refusalOf(fn () => $this->read(['billing_method' => 'fax'])));
    }

    public function testRead_RequiresAnEmailAddressForTheEmailBillingMethod(): void
    {
        $this->assertSame(AccountingError::CustomerEmailRequired, $this->refusalOf(fn () => $this->read(['billing_method' => 'email'], null, null, '   ')));
        $this->assertSame('email', $this->read(['billing_method' => 'email'], null, null, 'a@b.co')['billing_method']);
    }

    public function testRead_AcceptsAnEmptyEmailAddressForPrintBilling(): void
    {
        $this->assertSame('print', $this->read(['billing_method' => 'print'], null, null, '')['billing_method']);
    }

    public function testRead_AcceptsAnEmployeeOfTheSameBranchAndRejectsOneOfAnotherBranch(): void
    {
        $branch     = $this->createBranch('B2');
        $other      = $this->createBranch('B3');
        $viewer     = $this->employeeWithGrants(['customers'], [$branch]);
        $colleague  = $this->employeeWithGrants(['customers'], [$branch]);
        $stranger   = $this->employeeWithGrants(['customers'], [$other]);

        $this->assertSame($colleague, $this->read(['assigned_employee_id' => (string) $colleague], null, $viewer)['assigned_employee_id']);
        $this->assertSame(AccountingError::CustomerEmployeeInvalid, $this->refusalOf(fn () => $this->read(['assigned_employee_id' => (string) $stranger], null, $viewer)));
    }

    public function testRead_AcceptsAnEmployeeOfEveryBranchForAViewerLimitedToOneBranch(): void
    {
        $branch   = $this->createBranch('B2');
        $viewer   = $this->employeeWithGrants(['customers'], [$branch]);
        $everyone = $this->employeeWithGrants(['customers']);

        $this->assertSame($everyone, $this->read(['assigned_employee_id' => (string) $everyone], null, $viewer)['assigned_employee_id']);
    }

    public function testRead_AcceptsAnyEmployeeForAViewerOfEveryBranchAndRejectsOneThatDoesNotExist(): void
    {
        $viewer = $this->employeeWithGrants(['customers']);
        $branch = $this->createBranch('B2');
        $worker = $this->employeeWithGrants(['customers'], [$branch]);

        $this->assertSame($worker, $this->read(['assigned_employee_id' => (string) $worker], null, $viewer)['assigned_employee_id']);
        $this->assertSame(AccountingError::CustomerEmployeeInvalid, $this->refusalOf(fn () => $this->read(['assigned_employee_id' => '987654'], null, $viewer)));
    }

    public function testRead_KeepsTheEmployeeTheCustomerAlreadyHasEvenWhenTheViewerCannotSeeIt(): void
    {
        $branch   = $this->createBranch('B2');
        $other    = $this->createBranch('B3');
        $viewer   = $this->employeeWithGrants(['customers'], [$branch]);
        $assigned = $this->employeeWithGrants(['customers'], [$other]);

        $columns = $this->read(['assigned_employee_id' => (string) $assigned], (object) ['assigned_employee_id' => $assigned], $viewer);

        $this->assertSame($assigned, $columns['assigned_employee_id']);
    }

    public function testAssignableEmployees_ListsTheEmployeesOfTheViewersBranchesAndThoseOfEveryBranch(): void
    {
        $branch    = $this->createBranch('B2');
        $other     = $this->createBranch('B3');
        $viewer    = $this->employeeWithGrants(['customers'], [$branch]);
        $colleague = $this->employeeWithGrants(['customers'], [$branch]);
        $stranger  = $this->employeeWithGrants(['customers'], [$other]);
        $everyone  = $this->employeeWithGrants(['customers']);

        $listed = array_keys($this->fields()->assignableEmployees($viewer));

        $this->assertContains($viewer, $listed);
        $this->assertContains($colleague, $listed);
        $this->assertContains($everyone, $listed);
        $this->assertNotContains($stranger, $listed);
    }

    public function testAssignableEmployees_ListsNothingForAViewerWithoutAnyBranch(): void
    {
        $viewer = $this->employeeWithGrants(['customers'], []);

        $this->assertSame([], $this->fields()->assignableEmployees($viewer));
    }

    public function testAssignableEmployees_LeavesOutEmployeesWhoWereDeleted(): void
    {
        $viewer = $this->employeeWithGrants(['customers']);
        $gone   = $this->employeeWithGrants(['customers']);
        $this->accountingDb()->table('employees')->where('person_id', $gone)->update(['deleted' => 1]);

        $listed = array_keys($this->fields()->assignableEmployees($viewer));

        $this->assertContains($viewer, $listed);
        $this->assertNotContains($gone, $listed);
    }

    public function testAudit_WritesWhatChangedWithItsOldAndNewValue(): void
    {
        $customerId = $this->insertCustomer();
        $before     = (object) ['manual_status' => 'served', 'area' => 'Olaya', 'billing_method' => 'print', 'is_government_entity' => 0];

        $after = $this->read(['manual_status' => 'archived', 'area' => 'Olaya', 'customer_type' => 'individual']);

        $this->fields()->audit($customerId, $before, $after, 7);

        $row     = $this->accountingDb()->table('accounting_audit_log')->where(['entity_type' => 'customer', 'entity_id' => $customerId, 'action' => 'customer_master_data_changed'])->get()->getRow();
        $changes = json_decode((string) $row->details, true)['changes'];

        $this->assertSame(7, (int) $row->employee_id);
        $this->assertSame(['manual_status', 'customer_type'], array_keys($changes));
        $this->assertSame(['from' => 'served', 'to' => 'archived'], $changes['manual_status']);
        $this->assertSame(['from' => null, 'to' => 'individual'], $changes['customer_type']);
    }

    public function testAudit_WritesNothingWhenNothingChanged(): void
    {
        $customerId = $this->insertCustomer();

        $this->fields()->audit($customerId, null, $this->read([]), 7);

        $this->assertSame(0, $this->accountingDb()->table('accounting_audit_log')->where(['entity_type' => 'customer', 'entity_id' => $customerId])->countAllResults());
    }

    public function testAudit_TreatsANewCustomerAsStartingFromTheNeutralValues(): void
    {
        $customerId = $this->insertCustomer();

        $this->fields()->audit($customerId, null, $this->read(['is_government_entity' => '1', 'billing_method' => 'email'], null, null, 'a@b.co'), 7);

        $changes = json_decode((string) $this->accountingDb()->table('accounting_audit_log')->where(['entity_type' => 'customer', 'entity_id' => $customerId])->get()->getRow()->details, true)['changes'];

        $this->assertSame(['is_government_entity', 'billing_method'], array_keys($changes));
        $this->assertSame(['from' => 0, 'to' => 1], $changes['is_government_entity']);
    }

    public function testCountryCode_ReadsNoCodeAsNull(): void
    {
        $this->assertNull(CustomerFields::countryCode(''));
        $this->assertSame('AE', CustomerFields::countryCode('ae'));
    }
}
