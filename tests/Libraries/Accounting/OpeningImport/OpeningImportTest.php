<?php

namespace Tests\Libraries\Accounting\OpeningImport;

use App\Libraries\Accounting\AccountingError;
use App\Libraries\Accounting\AccountingException;
use App\Libraries\Accounting\AccountingGuard;
use App\Libraries\Accounting\CustomerOpening;
use App\Libraries\Accounting\EntityAccountService;
use App\Libraries\Accounting\OpeningImport\OpeningImport;
use App\Libraries\Accounting\OpeningImport\OpeningImportError;
use App\Libraries\Accounting\OpeningImport\OpeningImportKind;
use App\Libraries\Accounting\OpeningImport\OpeningImportPlan;
use App\Libraries\Accounting\OpeningImport\OpeningImportRow;
use App\Libraries\Accounting\SupplierAdvances;
use App\Libraries\Organization\BranchException;
use Closure;
use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\DatabaseTestTrait;
use Config\Database;
use Tests\Support\AccountingFixtureTrait;
use Tests\Support\ConcurrentDbRaceTrait;
use Tests\Support\DatabaseFingerprintTrait;
use Tests\Support\EmployeeFixtureTrait;
use Tests\Support\ItemFixtureTrait;
use Tests\Support\PurchaseAccountingFixtureTrait;
use Tests\Support\SaleAccountingFixtureTrait;

/**
 * Entering the opening balances of many customers or suppliers from one file: what the preview says of each row,
 * that the approval posts through the services of one balance and only what the preview showed, that the same file
 * can be run again, that a run stops between chunks and never inside one, and that a file of thousands of rows
 * is read and posted within the time a request has.
 *
 * @internal
 */
final class OpeningImportTest extends CIUnitTestCase
{
    use ConcurrentDbRaceTrait;
    use DatabaseFingerprintTrait;
    use DatabaseTestTrait;
    use AccountingFixtureTrait;
    use EmployeeFixtureTrait;
    use ItemFixtureTrait;
    use PurchaseAccountingFixtureTrait;
    use SaleAccountingFixtureTrait;

    protected $migrate     = true;
    protected $migrateOnce = true;
    protected $refresh     = false;
    protected $namespace   = null;

    private const DAY = '2024-12-31';

    private OpeningImport $import;
    private int $manager;

    public static function setUpBeforeClass(): void
    {
        $seeder = Database::seeder('tests');
        $seeder->call('TestDatabaseBootstrapSeeder');
    }

    protected function setUp(): void
    {
        parent::setUp();

        $this->startBuying();
        $this->setConfigValue('accounting_customer_opening', '1');

        $this->manager = $this->employeeWithGrants([AccountingGuard::OPENING_MANAGE, AccountingGuard::PAYABLES_MANAGE]);
        $this->import  = new OpeningImport();
    }

    protected function tearDown(): void
    {
        $this->releaseOpenTransactions();
        $this->accountingDb()->table('receivings')->where('receiving_type', 'opening')->delete();
        $this->stopBuying();
        $this->setConfigValue('accounting_customer_opening', '0');

        parent::tearDown();
    }

    /**
     * @param list<array<int, string>> $rows
     */
    private function csv(array $rows, string $header = 'party,amount,date,note'): string
    {
        return $header . "\n" . implode("\n", array_map(static fn (array $row): string => implode(',', array_map(static fn (string $cell): string => str_contains($cell, ',') ? '"' . $cell . '"' : $cell, $row)), $rows)) . "\n";
    }

    private function customersPlan(string $csv, ?int $employeeId = null): OpeningImportPlan
    {
        return $this->import->plan(OpeningImportKind::Customers, $csv, $employeeId ?? $this->manager);
    }

    private function row(OpeningImportPlan $plan, int $index = 0): OpeningImportRow
    {
        return $plan->rows[$index];
    }

    /**
     * @return list<string>
     */
    private function codesOf(OpeningImportRow $row): array
    {
        return array_map(static fn (OpeningImportError $error): string => $error->value, $row->errors);
    }

    private function assertRefusedWith(AccountingError $error, Closure $action): AccountingException
    {
        try {
            $action();
        } catch (AccountingException $exception) {
            $this->assertSame($error, $exception->error, $exception->getMessage());

            return $exception;
        }

        $this->fail('Expected ' . $error->value);
    }

    private function customers(int $count, string $prefix = 'Bulk'): array
    {
        $ids = [];

        for ($number = 1; $number <= $count; $number++) {
            $ids[] = $this->anotherCustomer($prefix, 'Customer ' . $number);
        }

        return $ids;
    }

    private function partyOfLine(OpeningImportPlan $plan, int $line): ?int
    {
        foreach ($plan->rows as $row) {
            if ($row->line === $line) {
                return $row->partyId;
            }
        }

        return null;
    }

    private function openingJournals(string $source): array
    {
        return $this->accountingDb()->table('accounting_journals')->where('source_type', $source)->where('status', 'posted')->orderBy('journal_id')->get()->getResult();
    }

    public function testPlan_ReadsTheSignOfAnAmountAsTheSideOfTheKindAndGivesTheJournalOfEachRow(): void
    {
        $other = $this->anotherCustomer('Hind', 'Saad');

        $plan = $this->customersPlan($this->csv([[(string) $this->customerId, '250.50', self::DAY, 'Invoice 7'], [(string) $other, '-40', '', '']]));

        $this->assertSame([], $plan->failing());
        $this->assertSame([CustomerOpening::OWES_US, '250.5000', self::DAY], [$this->row($plan)->side, $this->row($plan)->amount, $this->row($plan)->date]);
        $this->assertSame([CustomerOpening::WE_OWE, '40.0000', '2020-01-01'], [$this->row($plan, 1)->side, $this->row($plan, 1)->amount, $this->row($plan, 1)->date]);
        $this->assertSame([$this->customerAccount(), $this->accountId('opening_balance')], [$this->row($plan)->debitAccountId, $this->row($plan)->creditAccountId]);
        $this->assertSame([$this->accountId('opening_balance'), $this->customerAccount($other)], [$this->row($plan, 1)->debitAccountId, $this->row($plan, 1)->creditAccountId]);
        $this->assertSame(['250.5000', '40.0000', '210.5000'], array_values($plan->totals()));
        $this->assertSame('Omar Khaled', $this->row($plan)->partyName);
        $this->assertMatchesRegularExpression('/^[0-9a-f]{64}$/', $plan->fingerprint);
    }

    public function testPlan_ReadsASupplierPositiveAsWhatWeOweAndNegativeAsWhatTheyOwe(): void
    {
        $plan = $this->import->plan(OpeningImportKind::Suppliers, $this->csv([[(string) $this->supplierId, '900'], ['id:' . $this->supplierId, '-100']]), $this->manager);

        $this->assertSame([SupplierAdvances::WE_OWE, SupplierAdvances::THEY_OWE], [$this->row($plan)->side, $this->row($plan, 1)->side]);
        $this->assertSame([OpeningImportError::PartyDuplicate->value], $this->codesOf($this->row($plan)));

        $single = $this->import->plan(OpeningImportKind::Suppliers, $this->csv([[(string) $this->supplierId, '900']]), $this->manager);

        $this->assertSame([$this->accountId('opening_balance'), $this->supplierAccount()], [$this->row($single)->debitAccountId, $this->row($single)->creditAccountId]);

        $owed = $this->import->plan(OpeningImportKind::Suppliers, $this->csv([[(string) $this->supplierId, '-100']]), $this->manager);

        $this->assertSame([$this->supplierAccount(), $this->accountId('opening_balance')], [$this->row($owed)->debitAccountId, $this->row($owed)->creditAccountId]);
    }

    public function testPlan_FindsAPartyByNumberByExactNameAndByAccountNumber(): void
    {
        $this->accountingDb()->table('customers')->where('person_id', $this->customerId)->update(['account_number' => 'C-204', 'company_name' => 'Khaled Trading']);

        $plan = $this->customersPlan($this->csv([[(string) $this->customerId, '1'], ['omar   khaled', '2'], ['c-204', '3'], ['Khaled Trading', '4'], ['code: C-204', '5'], ['#' . $this->customerId, '6']]));

        $this->assertSame([$this->customerId], array_unique(array_map(static fn (OpeningImportRow $row): ?int => $row->partyId, $plan->rows)));
        $this->assertSame([OpeningImportError::PartyDuplicate->value], $this->codesOf($this->row($plan)), 'Six rows name the same party, so each is a duplicate.');
    }

    public function testPlan_RefusesAnUnknownPartyAnEmptyOneAndADeletedOne(): void
    {
        $gone = $this->anotherCustomer('Gone', 'Customer');
        $this->accountingDb()->table('customers')->where('person_id', $gone)->update(['deleted' => 1]);

        $plan = $this->customersPlan($this->csv([['Nobody Atall', '5'], ['', '5'], ['Gone Customer', '5']]));

        $this->assertSame([OpeningImportError::PartyUnknown->value], $this->codesOf($this->row($plan)));
        $this->assertSame([OpeningImportError::PartyMissing->value], $this->codesOf($this->row($plan, 1)));
        $this->assertSame([OpeningImportError::PartyUnknown->value], $this->codesOf($this->row($plan, 2)));
    }

    public function testPlan_RefusesANameThatFitsTwoPartiesAndSaysWhichAndAcceptsTheNumberInstead(): void
    {
        $first  = $this->anotherCustomer('Same', 'Name');
        $second = $this->anotherCustomer('Same', 'Name');

        $plan = $this->customersPlan($this->csv([['Same Name', '5'], ['id:' . $second, '7']]));

        $this->assertSame([OpeningImportError::PartyAmbiguous->value], $this->codesOf($this->row($plan)));
        $this->assertStringContainsString('#' . $first, $this->row($plan)->detail);
        $this->assertStringContainsString('#' . $second, $this->row($plan)->detail);
        $this->assertSame([], $this->codesOf($this->row($plan, 1)));
        $this->assertSame($second, $this->row($plan, 1)->partyId);
    }

    public function testPlan_TreatsANumberThatIsAlsoAnAccountNumberOfAnotherPartyAsAmbiguous(): void
    {
        $other = $this->anotherCustomer('Num', 'Eric');

        $this->accountingDb()->table('customers')->where('person_id', $other)->update(['account_number' => (string) $this->customerId]);

        $plan = $this->customersPlan($this->csv([[(string) $this->customerId, '5'], ['code:' . $this->customerId, '6']]));

        $this->assertSame([OpeningImportError::PartyAmbiguous->value], $this->codesOf($this->row($plan)));
        $this->assertSame([], $this->codesOf($this->row($plan, 1)));
        $this->assertSame($other, $this->row($plan, 1)->partyId);
    }

    public function testPlan_RefusesAZeroAmountAndATextThatIsNoNumber(): void
    {
        $other = $this->anotherCustomer('Hind', 'Saad');
        $third = $this->anotherCustomer('Salma', 'Omar');
        $four  = $this->anotherCustomer('Rami', 'Ziad');

        $plan = $this->customersPlan($this->csv([[(string) $this->customerId, '0.00'], [(string) $other, 'twelve'], [(string) $third, '1.23456'], [(string) $four, '']]));

        $this->assertSame([OpeningImportError::AmountZero->value], $this->codesOf($this->row($plan)));
        $this->assertSame([OpeningImportError::AmountInvalid->value], $this->codesOf($this->row($plan, 1)));
        $this->assertSame([OpeningImportError::AmountInvalid->value], $this->codesOf($this->row($plan, 2)));
        $this->assertSame([OpeningImportError::AmountInvalid->value], $this->codesOf($this->row($plan, 3)));
    }

    public function testPlan_ReadsThousandsSeparatorsParenthesesAndArabicDigits(): void
    {
        $ids  = $this->customers(4, 'Fmt');
        $plan = $this->customersPlan($this->csv([[(string) $ids[0], '1,234.50'], [(string) $ids[1], '(75.25)'], [(string) $ids[2], '٣٠٫٥'], [(string) $ids[3], '+8']]));

        $this->assertSame([], $plan->failing());
        $this->assertSame(['1234.5000', '75.2500', '30.5000', '8.0000'], array_map(static fn (OpeningImportRow $row): ?string => $row->amount, $plan->rows));
        $this->assertSame([CustomerOpening::OWES_US, CustomerOpening::WE_OWE, CustomerOpening::OWES_US, CustomerOpening::OWES_US], array_map(static fn (OpeningImportRow $row): ?string => $row->side, $plan->rows));
    }

    public function testPlan_RefusesADateThatIsNotADateOrIsAfterToday(): void
    {
        $other = $this->anotherCustomer('Hind', 'Saad');

        $plan = $this->customersPlan($this->csv([[(string) $this->customerId, '5', '31/12/2024'], [(string) $other, '5', '2099-01-01']]));

        $this->assertSame([OpeningImportError::DateInvalid->value], $this->codesOf($this->row($plan)));
        $this->assertSame([OpeningImportError::DateFuture->value], $this->codesOf($this->row($plan, 1)));
    }

    public function testPlan_RefusesADateInAClosedPeriodOrOutsideEveryFiscalYear(): void
    {
        $other = $this->anotherCustomer('Hind', 'Saad');

        $this->closeThrough('2024-12-31');
        $this->accountingDb()->table('accounting_fiscal_years')->where('start_date <', '2022-01-01')->delete();

        $plan = $this->customersPlan($this->csv([[(string) $this->customerId, '5', '2024-06-30'], [(string) $other, '5', '2021-03-01'], [(string) $this->anotherCustomer('Rami', 'Ziad'), '5', '2025-01-01']]));

        $this->assertSame([OpeningImportError::PeriodClosed->value], $this->codesOf($this->row($plan)));
        $this->assertSame([OpeningImportError::OutsideFiscalYear->value], $this->codesOf($this->row($plan, 1)));
        $this->assertSame([], $this->codesOf($this->row($plan, 2)));
    }

    public function testPlan_RefusesAPartyWithoutAnAccountOfItsOwn(): void
    {
        $bare = $this->insertCustomer('Bare', 'Customer');

        $plan = $this->customersPlan($this->csv([[(string) $bare, '5']]));

        $this->assertSame([OpeningImportError::PartyNoAccount->value], $this->codesOf($this->row($plan)));
    }

    public function testPlan_RefusesAPartyThatIsInTheFileMoreThanOnce(): void
    {
        $plan = $this->customersPlan($this->csv([[(string) $this->customerId, '5'], ['Omar Khaled', '5']]));

        $this->assertSame([OpeningImportError::PartyDuplicate->value], $this->codesOf($this->row($plan)));
        $this->assertSame([OpeningImportError::PartyDuplicate->value], $this->codesOf($this->row($plan, 1)));
    }

    public function testPlan_RefusesAPartyThatHasADifferentOpeningBalanceAndLeavesOneThatHasTheSame(): void
    {
        $other = $this->anotherCustomer('Hind', 'Saad');

        (new CustomerOpening())->create($this->customerId, CustomerOpening::OWES_US, '100.00', self::DAY, '', $this->manager);
        (new CustomerOpening())->create($other, CustomerOpening::WE_OWE, '30.00', self::DAY, '', $this->manager);

        $plan = $this->customersPlan($this->csv([[(string) $this->customerId, '100', self::DAY], [(string) $other, '30', self::DAY]]));

        $this->assertSame([], $this->codesOf($this->row($plan)));
        $this->assertTrue($this->row($plan)->posted);
        $this->assertSame([OpeningImportError::OpeningExists->value], $this->codesOf($this->row($plan, 1)), 'The balance has the other side.');

        $changed = $this->customersPlan($this->csv([[(string) $this->customerId, '100', '2024-06-30']]));

        $this->assertSame([OpeningImportError::OpeningExists->value], $this->codesOf($this->row($changed)));
    }

    public function testPlan_RefusesASupplierThatHasAnOpeningBalanceOfEitherKind(): void
    {
        $other = $this->supplier('Second Supplies');

        (new SupplierAdvances())->openingBalance($this->supplierId, SupplierAdvances::WE_OWE, '500.00', self::DAY, '', $this->manager);
        (new SupplierAdvances())->openingBalance($other, SupplierAdvances::THEY_OWE, '70.00', self::DAY, '', $this->manager);

        $same = $this->import->plan(OpeningImportKind::Suppliers, $this->csv([[(string) $this->supplierId, '500', self::DAY], [(string) $other, '-70', self::DAY]]), $this->manager);

        $this->assertSame([true, true], [$this->row($same)->posted, $this->row($same, 1)->posted]);
        $this->assertSame([], $same->failing());

        $differs = $this->import->plan(OpeningImportKind::Suppliers, $this->csv([[(string) $this->supplierId, '501', self::DAY], [(string) $other, '70', self::DAY]]), $this->manager);

        $this->assertSame([OpeningImportError::OpeningExists->value], $this->codesOf($this->row($differs)));
        $this->assertSame([OpeningImportError::OpeningExists->value], $this->codesOf($this->row($differs, 1)));
    }

    public function testPlan_CountsAnOpeningBalanceThatWasTakenBackAsNoOpeningBalance(): void
    {
        $openings = new CustomerOpening();
        $id       = $openings->create($this->customerId, CustomerOpening::OWES_US, '100.00', self::DAY, '', $this->manager);

        $openings->cancel($id, 'wrong', $this->manager);

        $plan = $this->customersPlan($this->csv([[(string) $this->customerId, '55', self::DAY]]));

        $this->assertSame([], $this->codesOf($this->row($plan)));
        $this->assertFalse($this->row($plan)->posted);
    }

    public function testPlan_WritesNothing(): void
    {
        $this->customersPlan($this->csv([[(string) $this->customerId, '250.50', self::DAY]]));

        $before = $this->databaseFingerprint();

        $this->customersPlan($this->csv([[(string) $this->customerId, '250.50', self::DAY], ['nobody', '1']]));

        $this->assertDatabaseUnchanged($before);
    }

    public function testPlan_GivesTheSameFingerprintForTheSameFileAndAnotherWhenTheBooksOrTheFileChange(): void
    {
        $csv  = $this->csv([[(string) $this->customerId, '250.50', self::DAY]]);
        $plan = $this->customersPlan($csv);

        $this->assertSame($plan->fingerprint, $this->customersPlan($csv)->fingerprint);
        $this->assertNotSame($plan->fingerprint, $this->customersPlan($this->csv([[(string) $this->customerId, '250.51', self::DAY]]))->fingerprint);

        $this->closeThrough('2024-12-31');

        $this->assertNotSame($plan->fingerprint, $this->customersPlan($csv)->fingerprint);
    }

    public function testPlan_NeedsThePermissionOfTheKindEveryBranchAccountingOnAndTheSwitchOfCustomers(): void
    {
        $customersOnly = $this->employeeWithGrants([AccountingGuard::OPENING_MANAGE]);
        $csv           = $this->csv([[(string) $this->supplierId, '5']]);

        $this->assertRefusedWith(AccountingError::PermissionDenied, fn () => $this->import->plan(OpeningImportKind::Suppliers, $csv, $customersOnly));
        $this->assertRefusedWith(AccountingError::PermissionDenied, fn () => $this->import->plan(OpeningImportKind::Customers, $this->csv([[(string) $this->customerId, '5']]), $this->employeeWithGrants([AccountingGuard::PAYABLES_MANAGE])));

        try {
            $this->import->plan(OpeningImportKind::Suppliers, $csv, $this->employeeWithGrants([AccountingGuard::PAYABLES_MANAGE], [1]));
            $this->fail('A person limited to a branch read the balances of the company.');
        } catch (BranchException) {
            $this->addToAssertionCount(1);
        }

        $this->setConfigValue('accounting_customer_opening', '0');

        $this->assertRefusedWith(AccountingError::CustomerOpeningOff, fn () => $this->customersPlan($this->csv([[(string) $this->customerId, '5']])));
        $this->assertSame(1, $this->import->plan(OpeningImportKind::Suppliers, $csv, $this->manager)->rows[0]->partyId === $this->supplierId ? 1 : 0, 'The balances of suppliers have no switch.');

        $this->setConfigValue('accounting_enabled', '0');

        $this->assertRefusedWith(AccountingError::OpeningStockAccountingOff, fn () => $this->import->plan(OpeningImportKind::Suppliers, $csv, $this->manager));
    }

    public function testApprove_PostsOneJournalForEachCustomerThroughTheServiceAndRecordsTheRun(): void
    {
        $other = $this->anotherCustomer('Hind', 'Saad');
        $plan  = $this->customersPlan($this->csv([[(string) $this->customerId, '250.50', self::DAY, 'Invoice 7'], [(string) $other, '-40', '2025-03-15', '']]));

        $result = $this->import->approve(OpeningImportKind::Customers, $this->csv([[(string) $this->customerId, '250.50', self::DAY, 'Invoice 7'], [(string) $other, '-40', '2025-03-15', '']]), $plan->fingerprint, $this->manager);

        $this->assertSame([2, 0, 0, 2, true], [$result->posted, $result->alreadyPosted, $result->remaining, $result->total, $result->isComplete()]);

        $openings = $this->accountingDb()->table('customer_openings')->orderBy('opening_id')->get()->getResult();

        $this->assertSame([[$this->customerId, 'owes_us', '250.5000', self::DAY, 'Invoice 7'], [$other, 'we_owe', '40.0000', '2025-03-15', 'csv:' . substr($plan->fileHash, 0, 12)]], array_map(static fn (object $row): array => [(int) $row->customer_id, $row->side, $row->amount, $row->opening_date, $row->note], $openings));
        $this->assertSame([$this->manager, $this->manager], array_map(static fn (object $row): int => (int) $row->created_by, $openings));

        $journals = $this->openingJournals(CustomerOpening::SOURCE);

        $this->assertCount(2, $journals);

        foreach ($journals as $index => $journal) {
            $lines = $this->accountingDb()->table('accounting_journal_lines')->where('journal_id', $journal->journal_id)->orderBy('line_no')->get()->getResult();
            $row   = $plan->rows[$index];

            $this->assertSame([$row->debitAccountId, $row->creditAccountId], [(int) $lines[0]->account_id, (int) $lines[1]->account_id], 'The preview showed the accounts the journal was posted to.');
            $this->assertSame([$row->amount, $row->amount], [$lines[0]->debit === '0.0000' ? $lines[0]->credit : $lines[0]->debit, $lines[1]->debit === '0.0000' ? $lines[1]->credit : $lines[1]->debit]);
        }

        $audit = $this->accountingDb()->table('accounting_audit_log')->where('action', 'opening_import_posted')->get()->getResult();

        $this->assertCount(1, $audit);

        $details = json_decode((string) $audit[0]->details, true);

        $this->assertSame([$this->manager, 'customers', $plan->fileHash, $plan->fingerprint, 2, 2, 0], [(int) $audit[0]->employee_id, $details['kind'], $details['file'], $details['fingerprint'], $details['rows'], $details['posted'], $details['remaining']]);
        $this->assertSame(2, $this->customersPlan($this->csv([[(string) $this->customerId, '250.50', self::DAY, 'Invoice 7'], [(string) $other, '-40', '2025-03-15', '']]))->postedCount());
        $this->assertTies();
    }

    public function testApprove_PostsSuppliersWeOweAsAPurchaseWithoutLinesAndWhatTheyOweAsAnAdvance(): void
    {
        $other = $this->supplier('Second Supplies');
        $csv   = $this->csv([[(string) $this->supplierId, '900.00', self::DAY, 'Open invoices'], [(string) $other, '-60', self::DAY, '']]);
        $plan  = $this->import->plan(OpeningImportKind::Suppliers, $csv, $this->manager);

        $result = $this->import->approve(OpeningImportKind::Suppliers, $csv, $plan->fingerprint, $this->manager);

        $this->assertSame([2, 0], [$result->posted, $result->remaining]);

        $purchase = $this->accountingDb()->table('receivings')->where('receiving_type', 'opening')->get()->getRow();
        $advance  = $this->accountingDb()->table('supplier_advances')->where('kind', 'opening')->get()->getRow();

        $this->assertSame([$this->supplierId, 'Open invoices', self::DAY], [(int) $purchase->supplier_id, $purchase->comment, substr($purchase->receiving_time, 0, 10)]);
        $this->assertSame([$other, '60.0000', self::DAY], [(int) $advance->supplier_id, $advance->amount, $advance->advance_date]);

        $journals = $this->accountingDb()->table('accounting_journals')->whereIn('source_type', ['purchase', SupplierAdvances::SOURCE])->where('status', 'posted')->orderBy('journal_id')->get()->getResult();

        $this->assertCount(2, $journals);
        $this->assertSame(['900.0000', '60.0000'], array_map(static fn (object $journal): string => $journal->total_debit, $journals));
        $this->assertSame(2, $this->import->plan(OpeningImportKind::Suppliers, $csv, $this->manager)->postedCount());
        $this->assertSupplierTies();
    }

    public function testApprove_RunsTheSameFileAgainWithoutPostingAnythingTwice(): void
    {
        $other = $this->anotherCustomer('Hind', 'Saad');
        $csv   = $this->csv([[(string) $this->customerId, '250.50', self::DAY], [(string) $other, '-40', self::DAY]]);
        $plan  = $this->customersPlan($csv);

        $this->import->approve(OpeningImportKind::Customers, $csv, $plan->fingerprint, $this->manager);

        $journals = $this->accountingDb()->table('accounting_journals')->countAllResults();
        $again    = $this->customersPlan($csv);

        $this->assertSame($plan->fingerprint, $again->fingerprint, 'Posting a row does not change what the preview is.');
        $this->assertSame(2, $again->postedCount());
        $this->assertSame([], $again->pending());

        $result = $this->import->approve(OpeningImportKind::Customers, $csv, $again->fingerprint, $this->manager);

        $this->assertSame([0, 2, 0, true], [$result->posted, $result->alreadyPosted, $result->remaining, $result->isComplete()]);
        $this->assertSame($journals, $this->accountingDb()->table('accounting_journals')->countAllResults());
        $this->assertSame(2, $this->accountingDb()->table('customer_openings')->countAllResults());
        $this->assertSame(1, $this->accountingDb()->table('accounting_audit_log')->where('action', 'opening_import_posted')->countAllResults(), 'A run that posted nothing writes no entry of its own.');
    }

    public function testApprove_RefusesAFingerprintThatIsNotTheFilesAndPostsNothing(): void
    {
        $csv = $this->csv([[(string) $this->customerId, '250.50', self::DAY]]);

        $this->assertRefusedWith(AccountingError::OpeningImportChanged, fn () => $this->import->approve(OpeningImportKind::Customers, $csv, str_repeat('a', 64), $this->manager));
        $this->assertSame(0, $this->accountingDb()->table('customer_openings')->countAllResults());

        $plan = $this->customersPlan($csv);

        $this->closeThrough('2024-12-31');

        $this->assertRefusedWith(AccountingError::OpeningImportChanged, fn () => $this->import->approve(OpeningImportKind::Customers, $csv, $plan->fingerprint, $this->manager));
        $this->assertSame(0, $this->accountingDb()->table('customer_openings')->countAllResults());
    }

    public function testApprove_RefusesAFileWithAnErrorInAnyRowAndPostsNoneOfThem(): void
    {
        $csv  = $this->csv([[(string) $this->customerId, '250.50', self::DAY], ['nobody', '5', self::DAY]]);
        $plan = $this->customersPlan($csv);

        $exception = $this->assertRefusedWith(AccountingError::OpeningImportHasErrors, fn () => $this->import->approve(OpeningImportKind::Customers, $csv, $plan->fingerprint, $this->manager));

        $this->assertSame(1, $exception->context['count']);
        $this->assertSame(0, $this->accountingDb()->table('customer_openings')->countAllResults());
    }

    public function testApprove_GoesOnFromTheRowsThatAreNotPostedWhenARunStopsBetweenChunks(): void
    {
        $ids  = $this->customers(OpeningImport::CHUNK * 2 + 5);
        $csv  = $this->csv(array_map(static fn (int $id): array => [(string) $id, '10', self::DAY], $ids));
        $plan = $this->customersPlan($csv);

        $first = $this->import->approve(OpeningImportKind::Customers, $csv, $plan->fingerprint, $this->manager, 1);

        $this->assertSame([OpeningImport::CHUNK, 0, OpeningImport::CHUNK + 5, false], [$first->posted, $first->alreadyPosted, $first->remaining, $first->isComplete()]);
        $this->assertSame(OpeningImport::CHUNK, $this->accountingDb()->table('customer_openings')->countAllResults());

        $second = $this->import->approve(OpeningImportKind::Customers, $csv, $plan->fingerprint, $this->manager, 1);

        $this->assertSame([OpeningImport::CHUNK, OpeningImport::CHUNK, 5], [$second->posted, $second->alreadyPosted, $second->remaining]);

        $third = $this->import->approve(OpeningImportKind::Customers, $csv, $plan->fingerprint, $this->manager);

        $this->assertSame([5, OpeningImport::CHUNK * 2, 0, true], [$third->posted, $third->alreadyPosted, $third->remaining, $third->isComplete()]);
        $this->assertSame(count($ids), $this->accountingDb()->table('customer_openings')->countAllResults());
        $this->assertSame(count($ids), $this->accountingDb()->table('accounting_journals')->where('source_type', CustomerOpening::SOURCE)->where('status', 'posted')->countAllResults());
        $this->assertSame(3, $this->accountingDb()->table('accounting_audit_log')->where('action', 'opening_import_posted')->countAllResults());
        $this->assertTies();
    }

    public function testApprove_StopsAfterTheSecondsItWasGivenWhateverNumberOfChunksItMayDo(): void
    {
        $ids  = $this->customers(OpeningImport::CHUNK + 3);
        $csv  = $this->csv(array_map(static fn (int $id): array => [(string) $id, '10', self::DAY], $ids));
        $plan = $this->customersPlan($csv);

        $result = $this->import->approve(OpeningImportKind::Customers, $csv, $plan->fingerprint, $this->manager, 100, 0.0);

        $this->assertSame([OpeningImport::CHUNK, 3], [$result->posted, $result->remaining]);
    }

    public function testApprove_LeavesNothingOfAChunkThatFailsAndSaysWhichRowStoppedIt(): void
    {
        $ids  = $this->customers(OpeningImport::CHUNK + 4);
        $csv  = $this->csv(array_map(static fn (int $id): array => [(string) $id, '10', self::DAY], $ids));
        $plan = $this->customersPlan($csv);
        $db   = $this->accountingDb();
        $stop = $db->prefixTable('f6_import_blocker');
        $bad  = $ids[OpeningImport::CHUNK + 2];

        $db->query("DROP TRIGGER IF EXISTS {$stop}");
        $db->query("CREATE TRIGGER {$stop} BEFORE INSERT ON " . $db->prefixTable('customer_openings') . " FOR EACH ROW BEGIN IF NEW.customer_id = {$bad} THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'blocked'; END IF; END");

        try {
            $result = $this->import->approve(OpeningImportKind::Customers, $csv, $plan->fingerprint, $this->manager);
        } finally {
            $db->query("DROP TRIGGER IF EXISTS {$stop}");
            $this->releaseOpenTransactions();
        }

        $this->assertNotNull($result->failed);
        $this->assertSame([OpeningImport::CHUNK, $bad], [$result->posted, $this->partyOfLine($plan, $result->failed['line'])]);
        $this->assertSame(OpeningImport::CHUNK, $db->table('customer_openings')->countAllResults(), 'The chunk that failed left nothing of its first rows.');
        $this->assertSame(OpeningImport::CHUNK, $db->table('accounting_journals')->where('source_type', CustomerOpening::SOURCE)->where('status', 'posted')->countAllResults());
        $this->assertSame(1, $db->table('accounting_audit_log')->where('action', 'opening_import_posted')->countAllResults());

        $again = $this->import->approve(OpeningImportKind::Customers, $csv, $plan->fingerprint, $this->manager);

        $this->assertNull($again->failed);
        $this->assertSame([4, OpeningImport::CHUNK, 0, true], [$again->posted, $again->alreadyPosted, $again->remaining, $again->isComplete()]);
        $this->assertSame(count($ids), $db->table('customer_openings')->countAllResults());
    }

    public function testApprove_RefusesAFileWhenAPartyGotAnOpeningBalanceAfterThePreview(): void
    {
        $csv  = $this->csv([[(string) $this->customerId, '250.50', self::DAY]]);
        $plan = $this->customersPlan($csv);

        (new CustomerOpening())->create($this->customerId, CustomerOpening::OWES_US, '99.00', self::DAY, '', $this->manager);

        $this->assertRefusedWith(AccountingError::OpeningImportChanged, fn () => $this->import->approve(OpeningImportKind::Customers, $csv, $plan->fingerprint, $this->manager));
        $this->assertSame(1, $this->accountingDb()->table('customer_openings')->countAllResults());
    }

    public function testApprove_RefusesASecondRunWhileAnotherRunHoldsTheImport(): void
    {
        $csv  = $this->csv([[(string) $this->customerId, '250.50', self::DAY]]);
        $plan = $this->customersPlan($csv);

        $other = $this->openSecondConnection();
        $name  = 'ospos_opening_import_' . substr(sha1($this->accountingDb()->getDatabase()), 0, 10) . '_customers';

        $this->assertSame(1, (int) $other->query('SELECT GET_LOCK(?, 0) AS got', [$name])->getRow()->got);

        try {
            $this->assertRefusedWith(AccountingError::OpeningImportBusy, fn () => $this->import->approve(OpeningImportKind::Customers, $csv, $plan->fingerprint, $this->manager));
        } finally {
            $other->query('SELECT RELEASE_LOCK(?)', [$name]);
            $other->close();
        }

        $this->assertSame(0, $this->accountingDb()->table('customer_openings')->countAllResults());
        $this->assertSame(1, $this->import->approve(OpeningImportKind::Customers, $csv, $plan->fingerprint, $this->manager)->posted);
    }

    public function testApprove_NeedsThePermissionOfTheKind(): void
    {
        $csv  = $this->csv([[(string) $this->customerId, '250.50', self::DAY]]);
        $plan = $this->customersPlan($csv);

        $this->assertRefusedWith(AccountingError::PermissionDenied, fn () => $this->import->approve(OpeningImportKind::Customers, $csv, $plan->fingerprint, $this->employeeWithGrants([AccountingGuard::PAYABLES_MANAGE])));
        $this->assertSame(0, $this->accountingDb()->table('customer_openings')->countAllResults());
    }

    public function testApprove_PostsTheBalancesOfAFileOfTwoThousandPartiesInStepsThatEachFitARequest(): void
    {
        $people    = [];
        $customers = [];
        $count     = 2000;

        for ($number = 1; $number <= $count; $number++) {
            $people[] = ['first_name' => 'Mass', 'last_name' => 'Customer ' . $number, 'email' => 'mass' . $number . '@example.com', 'phone_number' => '', 'address_1' => '', 'address_2' => '', 'city' => '', 'state' => '', 'zip' => '', 'country' => '', 'comments' => ''];
        }

        $db = $this->accountingDb();

        $db->table('people')->insertBatch($people);

        $ids = array_map('intval', array_column($db->table('people')->select('person_id')->like('email', 'mass', 'after')->like('email', '@example.com', 'before')->orderBy('person_id')->get()->getResultArray(), 'person_id'));

        $this->assertCount($count, $ids);

        foreach ($ids as $id) {
            $customers[]            = ['person_id' => $id, 'employee_id' => 1];
            $this->insertedPeople[] = $id;
        }

        $db->table('customers')->insertBatch($customers);

        $startedFilling = microtime(true);

        (new EntityAccountService())->fill();

        $filling = microtime(true) - $startedFilling;
        $rows    = [];

        foreach ($ids as $index => $id) {
            $rows[] = $index % 2 === 0 ? ['Mass Customer ' . ($index + 1), (string) (100 + $index) . '.25', self::DAY, 'Row ' . $index] : [(string) $id, '-' . (10 + $index), '', ''];
        }

        $csv     = $this->csv($rows);
        $started = microtime(true);
        $plan    = $this->customersPlan($csv);
        $preview = microtime(true) - $started;

        $this->assertSame([], $plan->failing());
        $this->assertSame($count, count($plan->pending()));
        $this->assertLessThan(20.0, $preview, 'The preview of the file is one request.');

        $steps   = 0;
        $slowest = 0.0;
        $total   = 0.0;

        do {
            $started = microtime(true);
            $result  = $this->import->approve(OpeningImportKind::Customers, $csv, $plan->fingerprint, $this->manager, 40, 5.0);
            $lasted  = microtime(true) - $started;
            $slowest = max($slowest, $lasted);
            $total  += $lasted;
            $steps++;

            $this->assertNull($result->failed, (string) ($result->failed['message'] ?? ''));
        } while (! $result->isComplete() && $steps < 200);

        fwrite(STDERR, sprintf("\n[2,000-row import] filling accounts %.1fs, preview %.1fs, %d approval steps, slowest %.1fs, all steps %.1fs\n", $filling, $preview, $steps, $slowest, $total));

        $this->assertTrue($result->isComplete());
        $this->assertGreaterThan(1, $steps, 'The posting is spread over several requests.');
        $this->assertLessThan(25.0, $slowest, 'Each request of the approval fits the time a request is given.');
        $this->assertSame($count, $db->table('customer_openings')->where('status', 'posted')->countAllResults());
        $this->assertSame($count, $db->table('accounting_journals')->where('source_type', CustomerOpening::SOURCE)->where('status', 'posted')->countAllResults());
        $this->assertSame($count, $this->customersPlan($csv)->postedCount());
        $this->assertTies();
    }

    public function testSample_IsAFileThatTheImportReadsAndThatPostsNothingAsItStands(): void
    {
        foreach (OpeningImportKind::cases() as $kind) {
            $sample = $this->import->sample($kind, '2020-01-01');

            $this->assertStringContainsString("party,amount,date,note\n", $sample);
            $this->assertStringContainsString('# ', $sample);

            $plan = $this->import->plan($kind, $sample, $this->manager);

            $this->assertCount(3, $plan->rows);
            $this->assertSame([], $plan->pending(), 'Nobody is called by the names of the sample.');
        }
    }

    public function testReport_ListsEveryRowWithItsStateAndWhatIsWrongWithIt(): void
    {
        $other = $this->anotherCustomer('Hind', 'Saad');
        $plan  = $this->customersPlan($this->csv([[(string) $this->customerId, '250.50', self::DAY, 'Invoice 7'], [(string) $other, '-40', self::DAY, ''], ['nobody', '5', self::DAY, '']]));

        $report = array_map('str_getcsv', explode("\n", trim($this->import->report($plan))));

        $this->assertSame(['line', 'party', 'party_id', 'name', 'amount', 'date', 'note', 'state', 'errors'], $report[0]);
        $this->assertSame(['2', (string) $this->customerId, (string) $this->customerId, 'Omar Khaled', '250.5000', self::DAY, 'Invoice 7', 'pending', ''], $report[1]);
        $this->assertSame('-40.0000', $report[2][4]);
        $this->assertSame('error', $report[3][7]);
        $this->assertStringContainsString(lang('Accounting_opening_import.error_party_unknown'), $report[3][8]);
    }
}
