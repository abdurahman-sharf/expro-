<?php

namespace Tests\Libraries\Accounting;

use App\Libraries\Accounting\AccountingError;
use App\Libraries\Accounting\AccountingException;
use App\Libraries\Accounting\AccountingGuard;
use App\Libraries\Accounting\CreditLimit;
use App\Libraries\Accounting\CustomerAdvances;
use App\Libraries\Accounting\QuoteStatus;
use App\Libraries\Pricing\SalesControlSettings;
use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\DatabaseTestTrait;
use Config\Database;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Support\AccountingFixtureTrait;
use Tests\Support\ConcurrentDbRaceTrait;
use Tests\Support\EmployeeFixtureTrait;
use Tests\Support\ItemFixtureTrait;
use Tests\Support\SaleAccountingFixtureTrait;

/**
 * @internal
 */
final class CreditLimitTest extends CIUnitTestCase
{
    use DatabaseTestTrait;
    use AccountingFixtureTrait;
    use ConcurrentDbRaceTrait;
    use EmployeeFixtureTrait;
    use ItemFixtureTrait;
    use SaleAccountingFixtureTrait;

    protected $migrate     = true;
    protected $migrateOnce = true;
    protected $refresh     = false;
    protected $namespace;
    private CreditLimit $credit;

    public static function setUpBeforeClass(): void
    {
        $seeder = Database::seeder('tests');
        $seeder->call('TestDatabaseBootstrapSeeder');
    }

    protected function setUp(): void
    {
        parent::setUp();

        $this->startSelling();

        $this->credit = new CreditLimit();
    }

    protected function tearDown(): void
    {
        $this->setConfigValue(SalesControlSettings::CREDIT_LIMIT, '0');
        $this->setConfigValue(SalesControlSettings::CREDIT_PERIOD, '0');
        $this->accountingDb()->table('accounting_audit_log')->whereIn('action', [CreditLimit::AUDIT_OVERRIDE, CreditLimit::AUDIT_REFUSED, 'customer_credit_changed'])->delete();
        $this->accountingDb()->table('customers')->update(['credit_limit' => 0, 'credit_period_days' => 0]);

        $this->stopSelling();

        parent::tearDown();
    }

    private function giveTerms(string $limit, int $days = 0, ?int $customerId = null): void
    {
        $this->accountingDb()->table('customers')->where('person_id', $customerId ?? $this->customerId)->update(['credit_limit' => $limit, 'credit_period_days' => $days]);
    }

    private function turnOn(bool $limit = true, bool $period = false): void
    {
        $this->setConfigValue(SalesControlSettings::CREDIT_LIMIT, $limit ? '1' : '0');
        $this->setConfigValue(SalesControlSettings::CREDIT_PERIOD, $period ? '1' : '0');
    }

    private function sellerHolding(string ...$permissionIds): void
    {
        foreach ($permissionIds as $permissionId) {
            $this->accountingDb()->table('grants')->ignore(true)->insert(['permission_id' => $permissionId, 'person_id' => $this->employeeId, 'menu_group' => 'home']);
        }
    }

    private function auditCount(string $action): int
    {
        return $this->accountingDb()->table('accounting_audit_log')->where('action', $action)->countAllResults();
    }

    public function testTermsOf_ReadsTheLimitAndTheDaysOfACustomerAndNoneForOneWithoutThem(): void
    {
        $this->assertSame(['limit' => '0.0000', 'days' => 0], $this->credit->termsOf($this->customerId));

        $this->giveTerms('250.5', 45);

        $this->assertSame(['limit' => '250.5000', 'days' => 45], $this->credit->termsOf($this->customerId));
        $this->assertSame(['limit' => '0.0000', 'days' => 0], $this->credit->termsOf(987654));
    }

    public function testSaveTerms_StoresTheLimitAndTheDaysAndWritesWhatChangedInTheAuditLog(): void
    {
        $this->credit->saveTerms($this->customerId, '300.25', '30', $this->employeeId);

        $this->assertSame(['limit' => '300.2500', 'days' => 30], $this->credit->termsOf($this->customerId));

        $entry = $this->accountingDb()->table('accounting_audit_log')->where('action', 'customer_credit_changed')->get()->getRow();

        $this->assertSame('customer', $entry->entity_type);
        $this->assertSame($this->customerId, (int) $entry->entity_id);
        $this->assertSame($this->employeeId, (int) $entry->employee_id);
        $this->assertSame(['before' => ['limit' => '0.0000', 'days' => 0], 'after' => ['limit' => '300.2500', 'days' => 30]], json_decode($entry->details, true));
    }

    public function testSaveTerms_WritesNothingWhenNothingChanged(): void
    {
        $this->credit->saveTerms($this->customerId, '300', '30', $this->employeeId);
        $this->credit->saveTerms($this->customerId, '300.0000', '30', $this->employeeId);

        $this->assertSame(1, $this->auditCount('customer_credit_changed'));
    }

    public function testSaveTerms_KeepsWhatTheFormDidNotShow(): void
    {
        $this->giveTerms('500', 20);

        $this->credit->saveTerms($this->customerId, '700', null, $this->employeeId);

        $this->assertSame(['limit' => '700.0000', 'days' => 20], $this->credit->termsOf($this->customerId));

        $this->credit->saveTerms($this->customerId, null, '10', $this->employeeId);

        $this->assertSame(['limit' => '700.0000', 'days' => 10], $this->credit->termsOf($this->customerId));
    }

    public function testSaveTerms_TakesAnEmptyLimitAsNoLimit(): void
    {
        $this->giveTerms('500', 20);

        $this->credit->saveTerms($this->customerId, '', '', $this->employeeId);

        $this->assertSame(['limit' => '0.0000', 'days' => 0], $this->credit->termsOf($this->customerId));
    }

    #[DataProvider('provideSaveTerms_RejectsTermsThatAreNotANumberInRange')]
    public function testSaveTerms_RejectsTermsThatAreNotANumberInRange(string $limit, string $days): void
    {
        $this->giveTerms('50', 5);

        try {
            $this->credit->saveTerms($this->customerId, $limit, $days, $this->employeeId);
            $this->fail('The terms should have been refused.');
        } catch (AccountingException $refusal) {
            $this->assertSame(AccountingError::CreditTermsInvalid, $refusal->error);
        }

        $this->assertSame(['limit' => '50.0000', 'days' => 5], $this->credit->termsOf($this->customerId));
        $this->assertSame(0, $this->auditCount('customer_credit_changed'));
    }

    /**
     * @return array<string, array{0: string, 1: string}>
     */
    public static function provideSaveTerms_RejectsTermsThatAreNotANumberInRange(): iterable
    {
        return [
            'a negative limit'           => ['-1', '0'],
            'a limit that is no number'  => ['much', '0'],
            'a limit with five decimals' => ['1.12345', '0'],
            'days that are not whole'    => ['10', '1.5'],
            'negative days'              => ['10', '-3'],
            'more days than fit'         => ['10', '65536'],
        ];
    }

    public function testSaveTerms_AcceptsTheLargestDaysThatFit(): void
    {
        $this->credit->saveTerms($this->customerId, '0', '65535', $this->employeeId);

        $this->assertSame(65535, $this->credit->termsOf($this->customerId)['days']);
    }

    public function testRefusalFor_AcceptsASaleThatBringsTheCustomerExactlyToTheLimit(): void
    {
        $this->giveTerms('200');

        $this->invoiceOf('100.00');
        $second = $this->invoiceOf('100.00');

        $this->turnOn();

        $this->assertNull($this->credit->refusalFor($this->customerId, $second, date('Y-m-d')));
    }

    public function testRefusalFor_RefusesASaleThatTakesWhatTheCustomerOwesOverTheLimitAndNamesWhatItNeeds(): void
    {
        $this->giveTerms('200');

        $this->invoiceOf('100.00');
        $second = $this->invoiceOf('100.01');

        $this->turnOn();

        $refusal = $this->credit->refusalFor($this->customerId, $second, date('Y-m-d'));

        $this->assertInstanceOf(AccountingException::class, $refusal);
        $this->assertSame(AccountingError::CreditLimitExceeded, $refusal->error);
        $this->assertSame('200.00', $refusal->context['limit']);
        $this->assertSame('200.01', $refusal->context['balance']);
        $this->assertSame('Omar Khaled', $refusal->context['name']);
    }

    public function testRefusalFor_CountsWhatIsStillOpenAndNotWhatWasPaid(): void
    {
        $this->giveTerms('150');

        $this->invoiceOf('100.00', null, [$this->payment('Cash', '100.00')]);
        $second = $this->invoiceOf('120.00');

        $this->turnOn();

        $this->assertNull($this->credit->refusalFor($this->customerId, $second, date('Y-m-d')), 'the paid invoice owes nothing');
    }

    public function testRefusalFor_NeverRefusesASaleThatPutNothingOnCredit(): void
    {
        $this->giveTerms('50');

        $this->invoiceOf('400.00');
        $cash = $this->invoiceOf('100.00', null, [$this->payment('Cash', '100.00')]);

        $this->turnOn();

        $this->assertNull($this->credit->refusalFor($this->customerId, $cash, date('Y-m-d')), 'a customer over the limit may still pay for what they take');
    }

    public function testRefusalFor_IgnoresACustomerWithoutALimit(): void
    {

        $sale = $this->invoiceOf('100000.00');

        $this->turnOn();

        $this->assertNull($this->credit->refusalFor($this->customerId, $sale, date('Y-m-d')));
    }

    public function testRefusalFor_IgnoresTheLimitWhileTheControlIsOff(): void
    {
        $this->giveTerms('10');

        $sale = $this->invoiceOf('100.00');

        $this->assertNull($this->credit->refusalFor($this->customerId, $sale, date('Y-m-d')));
    }

    public function testRefusalFor_CountsOnlyWhatIsOpenAfterAnAdvancePaymentWasSpentOnTheInvoices(): void
    {
        $clerk = $this->employeeWithGrants([AccountingGuard::RECEIVABLES_MANAGE, AccountingGuard::TREASURIES_MANAGE]);

        $this->giveTerms('100');

        (new CustomerAdvances())->receive($this->customerId, '50.00', date('Y-m-d'), 'Cash', null, '', '', $clerk);

        $this->invoiceOf('140.00');

        $second = $this->invoiceOf('20.00');

        $this->turnOn();

        $this->assertSame('110.00', bcadd($this->position()->open(), '0', 2), 'the books spent the advance on the first invoice');

        $refusal = $this->credit->refusalFor($this->customerId, $second, date('Y-m-d'));

        $this->assertSame(AccountingError::CreditLimitExceeded, $refusal?->error);
        $this->assertSame('110.00', $refusal->context['balance']);
    }

    public function testRefusalFor_CountsTheInvoiceAfterACreditNoteWasSpentOnTheOlderOne(): void
    {
        $this->giveTerms('100');

        $first = $this->invoiceOf('100.00');

        $this->creditNoteOf($first, '40.00');

        $second = $this->invoiceOf('50.00');

        $this->turnOn();

        $refusal = $this->credit->refusalFor($this->customerId, $second, date('Y-m-d'));

        $this->assertSame(AccountingError::CreditLimitExceeded, $refusal?->error);
        $this->assertSame('110.00', $refusal->context['balance'], '60 left of the first invoice and 50 of the new one');
    }

    public function testRefusalFor_OnlyLooksAtTheCustomerOfTheSale(): void
    {
        $other = $this->anotherCustomer();

        $this->giveTerms('100');

        $this->invoiceOf('90.00', $other);

        $sale = $this->invoiceOf('90.00');

        $this->turnOn();

        $this->assertNull($this->credit->refusalFor($this->customerId, $sale, date('Y-m-d')));
    }

    public function testRefusalFor_RefusesAnOldestOpenInvoiceOlderThanTheDaysOfTheCustomer(): void
    {
        $this->giveTerms('0', 30);

        $this->invoiceDated('50.00', $this->daysAgo(31));

        $sale = $this->invoiceOf('10.00');

        $this->turnOn(false, true);

        $refusal = $this->credit->refusalFor($this->customerId, $sale, date('Y-m-d'));

        $this->assertSame(AccountingError::CreditPeriodExceeded, $refusal?->error);
        $this->assertSame(30, $refusal->context['count']);
        $this->assertSame(31, $refusal->context['days']);
    }

    public function testRefusalFor_AcceptsAnOldestOpenInvoiceThatIsExactlyAsOldAsTheDays(): void
    {
        $this->giveTerms('0', 30);

        $this->invoiceDated('50.00', $this->daysAgo(30));

        $sale = $this->invoiceOf('10.00');

        $this->turnOn(false, true);

        $this->assertNull($this->credit->refusalFor($this->customerId, $sale, date('Y-m-d')));
    }

    public function testRefusalFor_DoesNotCountAnOldInvoiceThatWasPaid(): void
    {
        $this->giveTerms('0', 30);

        $this->invoiceDated('50.00', $this->daysAgo(90), null, null, [$this->payment('Cash', '50.00')]);

        $sale = $this->invoiceOf('10.00');

        $this->turnOn(false, true);

        $this->assertNull($this->credit->refusalFor($this->customerId, $sale, date('Y-m-d')));
    }

    public function testRefusalFor_DoesNotHoldTheSaleItselfToTheDays(): void
    {
        $this->giveTerms('0', 30);

        $sale = $this->invoiceDated('50.00', $this->daysAgo(60));

        $this->turnOn(false, true);

        $this->assertNull($this->credit->refusalFor($this->customerId, $sale, date('Y-m-d')), 'the invoice being issued is not an older invoice of the customer');
    }

    public function testRefusalFor_LeavesTheDaysAloneWhileTheirControlIsOff(): void
    {
        $this->giveTerms('0', 30);

        $this->invoiceDated('50.00', $this->daysAgo(90));

        $sale = $this->invoiceOf('10.00');

        $this->turnOn(true, false);

        $this->assertNull($this->credit->refusalFor($this->customerId, $sale, date('Y-m-d')));
    }

    public function testSave_RefusesTheSaleThatGoesOverTheLimitAndLeavesNoSaleNoJournalAndNoStock(): void
    {
        $this->giveTerms('150');
        $this->turnOn();

        $this->invoiceOf('100.00');

        $sales    = $this->countSales();
        $journals = $this->countJournals();
        $balance  = $this->balance($this->customerAccount());

        $this->assertSame(ACCOUNTING_FAILED, $this->invoiceOf('60.00'));
        $this->assertSame(AccountingError::CreditLimitExceeded, $this->failure()->error);
        $this->assertSame($sales, $this->countSales());
        $this->assertSame($journals, $this->countJournals());
        $this->assertSame($balance, $this->balance($this->customerAccount()));
        $this->assertTies();
    }

    public function testSave_AcceptsTheSaleThatFitsTheLimit(): void
    {
        $this->giveTerms('150');
        $this->turnOn();

        $this->invoiceOf('100.00');

        $this->assertGreaterThan(0, $this->invoiceOf('50.00'));
        $this->assertSame('150.00', bcadd($this->position()->open(), '0', 2));
    }

    public function testSave_WritesTheRefusalInTheAuditLogAfterTheSaleWasTakenBack(): void
    {
        $this->giveTerms('50');
        $this->turnOn();

        $this->assertSame(ACCOUNTING_FAILED, $this->invoiceOf('60.00'));

        $entry = $this->accountingDb()->table('accounting_audit_log')->where('action', CreditLimit::AUDIT_REFUSED)->get()->getRow();

        $this->assertSame('customer', $entry->entity_type);
        $this->assertSame($this->customerId, (int) $entry->entity_id);
        $this->assertSame($this->employeeId, (int) $entry->employee_id);

        $details = json_decode($entry->details, true);

        $this->assertSame('credit_limit_exceeded', $details['rule']);
        $this->assertSame('50.00', $details['limit']);
        $this->assertSame('60.00', $details['balance']);
        $this->assertSame(0, $this->auditCount(CreditLimit::AUDIT_OVERRIDE));
    }

    public function testSave_LetsAnEmployeeWhoMayOverrideSellOverTheLimitAndWritesItInTheAuditLog(): void
    {
        $this->giveTerms('50');
        $this->turnOn();
        $this->sellerHolding(AccountingGuard::CREDIT_OVERRIDE);

        $saleId = $this->invoiceOf('60.00');

        $this->assertGreaterThan(0, $saleId);
        $this->assertSame('60.00', bcadd($this->position()->open(), '0', 2));

        $entry = $this->accountingDb()->table('accounting_audit_log')->where('action', CreditLimit::AUDIT_OVERRIDE)->get()->getRow();

        $this->assertSame('sale', $entry->entity_type);
        $this->assertSame($saleId, (int) $entry->entity_id);
        $this->assertSame($this->employeeId, (int) $entry->employee_id);
        $this->assertSame('credit_limit_exceeded', json_decode($entry->details, true)['rule']);
        $this->assertSame(0, $this->auditCount(CreditLimit::AUDIT_REFUSED));
    }

    public function testSave_DoesNotLetThePermissionToOverridePricesSellOverTheCreditLimit(): void
    {
        $this->giveTerms('50');
        $this->turnOn();
        $this->sellerHolding(AccountingGuard::PRICE_OVERRIDE);

        $this->assertSame(ACCOUNTING_FAILED, $this->invoiceOf('60.00'));
    }

    public function testSave_NeverRefusesADraftBecauseOfTheLimit(): void
    {
        $this->giveTerms('50');
        $this->turnOn();

        $service = $this->item('0', false);
        $draft   = $this->save([$this->line($service, 0, '1', '500.00')], [], [], SALE_TYPE_INVOICE, -1, true, null, SUSPENDED);

        $this->assertGreaterThan(0, $draft);
        $this->assertSame(0, $this->auditCount(CreditLimit::AUDIT_REFUSED));
        $this->assertSame('0.00', bcadd($this->position()->open(), '0', 2), 'a draft owes nothing');
    }

    public function testSave_NeverRefusesAQuoteBecauseOfTheLimit(): void
    {
        $this->giveTerms('50');
        $this->turnOn();

        $service = $this->item('0', false);
        $quote   = $this->save([$this->line($service, 0, '1', '500.00')], [], [], SALE_TYPE_QUOTE, -1, true, null, SUSPENDED, NEW_ENTRY, null, quoteStatus: QuoteStatus::Draft);

        $this->assertGreaterThan(0, $quote);
    }

    public function testSave_NeverRefusesAReturnOrACreditNoteBecauseOfTheLimit(): void
    {
        $this->giveTerms('100');
        $this->turnOn();

        $invoice = $this->invoiceOf('100.00');
        $return  = $this->creditNoteOf($invoice, '100.00');

        $this->assertGreaterThan(0, $return);
        $this->assertSame(0, $this->auditCount(CreditLimit::AUDIT_REFUSED));
    }

    public function testSave_ReadsNothingForACustomerWithNoLimitAndLeavesTheSaleAsItWas(): void
    {
        $this->turnOn(true, true);

        $this->assertGreaterThan(0, $this->invoiceOf('999999.00'));
        $this->assertSame(0, $this->auditCount(CreditLimit::AUDIT_REFUSED));
        $this->assertSame(0, $this->auditCount(CreditLimit::AUDIT_OVERRIDE));
    }

    public function testSave_LeavesASaleWithNoCustomerAloneWhileTheControlsAreOn(): void
    {
        $this->turnOn(true, true);

        $service = $this->item('0', false);

        $this->assertGreaterThan(0, $this->save([$this->line($service, 0, '1', '100.00')], [], [$this->payment('Cash', '100.00')], SALE_TYPE_POS, null));
        $this->assertSame(0, $this->auditCount(CreditLimit::AUDIT_REFUSED));
    }

    public function testSave_DoesNotCheckTheLimitWhileAccountingIsOff(): void
    {
        $this->giveTerms('10');
        $this->turnOn();
        $this->setConfigValue('accounting_enabled', '0');

        $this->assertGreaterThan(0, $this->invoiceOf('100.00'));

        $this->setConfigValue('accounting_enabled', '1');
    }

    public function testSave_RefusesTheSaleThatBreaksTheCreditDays(): void
    {
        $this->giveTerms('0', 30);
        $this->turnOn(false, true);

        $this->invoiceDated('50.00', $this->daysAgo(45));

        $this->assertSame(ACCOUNTING_FAILED, $this->invoiceOf('10.00'));
        $this->assertSame(AccountingError::CreditPeriodExceeded, $this->failure()->error);
        $this->assertSame(1, $this->auditCount(CreditLimit::AUDIT_REFUSED));
    }

    public function testSave_StopsTwoSalesMadeAtTheSameMomentFromBothFittingInWhatOnlyOneFitsIn(): void
    {
        $service = $this->item('0', false);

        $this->giveTerms('150');
        $this->turnOn();

        [$one, $two] = $this->raceTwoProcesses(
            'serviceInvoice',
            [$this->customerId, $service, '100.00', $this->employeeId],
            [$this->customerId, $service, '100.00', $this->employeeId],
        );

        $this->trackRaceSales();

        $this->assertNotSame($one, $two, 'exactly one of the two passes');
        $this->assertSame(1, $this->accountingDb()->table('sales')->where('comment', 'race')->countAllResults());

        $position = $this->position();

        $this->assertSame('100.00', bcadd($position->open(), '0', 2));
        $this->assertSame('100.00', bcadd($position->accountBalance, '0', 2));
        $this->assertSame(1, $this->auditCount(CreditLimit::AUDIT_REFUSED));
        $this->assertTies();
    }

    public function testSave_LetsTwoSalesMadeAtTheSameMomentBothPassWhenBothFit(): void
    {
        $service = $this->item('0', false);

        $this->giveTerms('250');
        $this->turnOn();

        [$one, $two] = $this->raceTwoProcesses(
            'serviceInvoice',
            [$this->customerId, $service, '100.00', $this->employeeId],
            [$this->customerId, $service, '100.00', $this->employeeId],
        );

        $this->trackRaceSales();

        $this->assertTrue($one);
        $this->assertTrue($two);
        $this->assertSame('200.00', bcadd($this->position()->open(), '0', 2));
        $this->assertSame(0, $this->auditCount(CreditLimit::AUDIT_REFUSED));
    }

    private function trackRaceSales(): void
    {
        foreach ($this->accountingDb()->table('sales')->select('sale_id')->where('comment', 'race')->get()->getResult() as $row) {
            $this->saleIds[] = (int) $row->sale_id;
        }
    }
}
