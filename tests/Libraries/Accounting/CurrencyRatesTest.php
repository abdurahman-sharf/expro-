<?php

namespace Tests\Libraries\Accounting;

use App\Libraries\Accounting\AccountingError;
use App\Libraries\Accounting\AccountingException;
use App\Libraries\Accounting\AccountingGuard;
use App\Libraries\Accounting\Currencies;
use App\Libraries\Accounting\CurrencyRates;
use App\Libraries\Organization\BranchException;
use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\DatabaseTestTrait;
use Config\Database;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Support\AccountingFixtureTrait;

/**
 * @internal
 */
final class CurrencyRatesTest extends CIUnitTestCase
{
    use DatabaseTestTrait;
    use AccountingFixtureTrait;

    protected $migrate     = true;
    protected $migrateOnce = true;
    protected $refresh     = false;
    protected $namespace;
    private CurrencyRates $rates;

    public static function setUpBeforeClass(): void
    {
        $seeder = Database::seeder('tests');
        $seeder->call('TestDatabaseBootstrapSeeder');
    }

    protected function setUp(): void
    {
        parent::setUp();

        $this->clearJournals();
        $this->setConfigValue('accounting_base_currency', 'SAR');
        (new Currencies())->setActive('USD', true);
        $this->rates = new CurrencyRates();
    }

    protected function tearDown(): void
    {
        $this->releaseOpenTransactions();
        $this->clearJournals();
        $this->removeInsertedEntities();

        parent::tearDown();
    }

    private function manager(): int
    {
        return $this->employeeWithGrants([AccountingGuard::CURRENCIES_MANAGE]);
    }

    private function assertRefused(AccountingError $expected, callable $action): void
    {
        try {
            $action();
            $this->fail('A refusal was expected.');
        } catch (AccountingException $exception) {
            $this->assertSame($expected, $exception->error);
        }
    }

    public function testRateOnIsOneForTheBaseCurrency(): void
    {
        $this->assertSame('1.00000000', $this->rates->rateOn('SAR', '2026-10-21'));
    }

    public function testRateOnTakesTheLatestDayOnOrBeforeTheDateAndNothingBeforeTheFirst(): void
    {
        $this->rates->add(['currency_code' => 'USD', 'rate' => '3.75', 'effective_date' => '2026-10-10']);
        $this->rates->add(['currency_code' => 'USD', 'rate' => '3.60', 'effective_date' => '2026-10-20']);

        $this->assertNull($this->rates->rateOn('USD', '2026-10-09'));
        $this->assertSame('3.75000000', $this->rates->rateOn('USD', '2026-10-10'));
        $this->assertSame('3.75000000', $this->rates->rateOn('USD', '2026-10-19'));
        $this->assertSame('3.60000000', $this->rates->rateOn('USD', '2026-10-20'));
        $this->assertSame('3.60000000', $this->rates->rateOn('USD', '2027-01-01'));
        $this->assertNull($this->rates->rateOn('EUR', '2026-10-20'));
    }

    public function testAddRequiresThePermissionToManageCurrencies(): void
    {
        $this->assertRefused(AccountingError::PermissionDenied, fn () => $this->rates->add(['currency_code' => 'USD', 'rate' => '3.75', 'effective_date' => '2026-10-10'], $this->employeeWithGrants(['accounting_settings'])));
        $this->assertSame(0, $this->countRows('accounting_currency_rates'));
    }

    public function testAddRejectsAnEmployeeLimitedToSomeBranches(): void
    {
        $restricted = $this->employeeWithGrants([AccountingGuard::CURRENCIES_MANAGE], [$this->createBranch('B3')]);

        $this->expectException(BranchException::class);

        $this->rates->add(['currency_code' => 'USD', 'rate' => '3.75', 'effective_date' => '2026-10-10'], $restricted);
    }

    public function testAddStoresTheRateWithWhoEnteredItAndAuditsIt(): void
    {
        $manager = $this->manager();
        $rateId  = $this->rates->add(['currency_code' => 'usd', 'rate' => '3.7447370', 'effective_date' => '2026-10-10', 'note' => ' first '], $manager);

        $row = $this->rates->find($rateId);
        $this->assertSame('USD', $row->currency_code);
        $this->assertSame('3.74473700', $row->rate);
        $this->assertSame($manager, (int) $row->entered_by);
        $this->assertSame('first', $row->note);

        $audit = $this->accountingDb()->table('accounting_audit_log')->where('entity_type', 'currency_rate')->where('entity_id', $rateId)->get()->getRow();
        $this->assertSame('created', $audit->action);
        $this->assertSame($manager, (int) $audit->employee_id);
        $this->assertSame(['code' => 'USD', 'rate' => '3.74473700', 'date' => '2026-10-10'], json_decode($audit->details, true));
    }

    #[DataProvider('provideAdd_RejectsAnInvalidRate')]
    public function testAddRejectsAnInvalidRate(string $rate): void
    {
        $this->assertRefused(AccountingError::RateInvalid, fn () => $this->rates->add(['currency_code' => 'USD', 'rate' => $rate, 'effective_date' => '2026-10-10'], $this->manager()));
    }

    /**
     * @return array<string, array{string}>
     */
    public static function provideAdd_RejectsAnInvalidRate(): iterable
    {
        return ['zero' => ['0'], 'negative' => ['-3.75'], 'text' => ['abc'], 'nine decimals' => ['3.123456789'], 'empty' => [''], 'too large' => ['12345678901']];
    }

    public function testAddAcceptsTheSmallestAndTheMostPreciseRate(): void
    {
        $manager = $this->manager();

        $this->rates->add(['currency_code' => 'USD', 'rate' => '0.00000001', 'effective_date' => '2026-10-10'], $manager);
        $this->rates->add(['currency_code' => 'USD', 'rate' => '9999999999.99999999', 'effective_date' => '2026-10-11'], $manager);

        $this->assertSame('0.00000001', $this->rates->rateOn('USD', '2026-10-10'));
    }

    public function testAddRejectsTheBaseCurrencyAnUnknownCurrencyABadDateAndASecondRateForTheSameDay(): void
    {
        $manager = $this->manager();

        $this->assertRefused(AccountingError::CurrencyIsBase, fn () => $this->rates->add(['currency_code' => 'SAR', 'rate' => '1', 'effective_date' => '2026-10-10'], $manager));
        $this->assertRefused(AccountingError::CurrencyNotFound, fn () => $this->rates->add(['currency_code' => 'QQQ', 'rate' => '1', 'effective_date' => '2026-10-10'], $manager));
        $this->assertRefused(AccountingError::InvalidDate, fn () => $this->rates->add(['currency_code' => 'USD', 'rate' => '1', 'effective_date' => '2026-02-30'], $manager));

        $this->rates->add(['currency_code' => 'USD', 'rate' => '3.75', 'effective_date' => '2026-10-10'], $manager);

        $this->assertRefused(AccountingError::RateDateTaken, fn () => $this->rates->add(['currency_code' => 'USD', 'rate' => '3.80', 'effective_date' => '2026-10-10'], $manager));
        $this->assertSame(1, $this->countRows('accounting_currency_rates'));
    }

    public function testUpdateWritesWhatTheRateWasBeforeAndAfter(): void
    {
        $manager = $this->manager();
        $rateId  = $this->rates->add(['currency_code' => 'USD', 'rate' => '3.75', 'effective_date' => '2026-10-10'], $manager);

        $this->rates->update($rateId, ['rate' => '3.74', 'note' => 'corrected'], $manager);

        $this->assertSame('3.74000000', $this->rates->find($rateId)->rate);

        $audit   = $this->accountingDb()->table('accounting_audit_log')->where('entity_type', 'currency_rate')->where('action', 'updated')->get()->getRow();
        $details = json_decode($audit->details, true);

        $this->assertSame('3.75000000', $details['before']['rate']);
        $this->assertSame('3.74000000', $details['after']['rate']);
    }

    public function testUpdateRequiresThePermissionAndRefusesAnotherRateOnTheSameDay(): void
    {
        $manager = $this->manager();
        $first   = $this->rates->add(['currency_code' => 'USD', 'rate' => '3.75', 'effective_date' => '2026-10-10'], $manager);
        $this->rates->add(['currency_code' => 'USD', 'rate' => '3.60', 'effective_date' => '2026-10-20'], $manager);

        $this->assertRefused(AccountingError::PermissionDenied, fn () => $this->rates->update($first, ['rate' => '9'], $this->employeeWithGrants([])));
        $this->assertRefused(AccountingError::RateDateTaken, fn () => $this->rates->update($first, ['effective_date' => '2026-10-20'], $manager));
        $this->assertRefused(AccountingError::RateNotFound, fn () => $this->rates->update(999999, ['rate' => '3'], $manager));
        $this->assertSame('3.75000000', $this->rates->find($first)->rate);
    }

    public function testUpdateRejectsAnEmployeeLimitedToSomeBranches(): void
    {
        $rateId     = $this->rates->add(['currency_code' => 'USD', 'rate' => '3.75', 'effective_date' => '2026-10-10'], $this->manager());
        $restricted = $this->employeeWithGrants([AccountingGuard::CURRENCIES_MANAGE], [$this->createBranch('B3')]);

        try {
            $this->rates->update($rateId, ['rate' => '9'], $restricted);
            $this->fail('An employee of one branch changed a rate of the company.');
        } catch (BranchException) {
            $this->addToAssertionCount(1);
        }

        $this->assertSame('3.75000000', $this->rates->find($rateId)->rate);
    }

    public function testDeleteRejectsAnEmployeeLimitedToSomeBranches(): void
    {
        $rateId     = $this->rates->add(['currency_code' => 'USD', 'rate' => '3.75', 'effective_date' => '2026-10-10'], $this->manager());
        $restricted = $this->employeeWithGrants([AccountingGuard::CURRENCIES_MANAGE], [$this->createBranch('B3')]);

        try {
            $this->rates->delete($rateId, $restricted);
            $this->fail('An employee of one branch deleted a rate of the company.');
        } catch (BranchException) {
            $this->addToAssertionCount(1);
        }

        $this->assertNotNull($this->rates->find($rateId));
    }

    public function testUpdateChangesNothingAndWritesNoAuditWhenNothingChanged(): void
    {
        $rateId = $this->rates->add(['currency_code' => 'USD', 'rate' => '3.75', 'effective_date' => '2026-10-10'], $this->manager());

        $this->rates->update($rateId, ['rate' => '3.75', 'effective_date' => '2026-10-10'], $this->manager());

        $this->assertSame(0, $this->accountingDb()->table('accounting_audit_log')->where('action', 'updated')->countAllResults());
    }

    public function testDeleteRemovesTheRateAndAuditsIt(): void
    {
        $manager = $this->manager();
        $rateId  = $this->rates->add(['currency_code' => 'USD', 'rate' => '3.75', 'effective_date' => '2026-10-10'], $manager);

        $this->assertRefused(AccountingError::PermissionDenied, fn () => $this->rates->delete($rateId, $this->employeeWithGrants([])));
        $this->assertNotNull($this->rates->find($rateId));

        $this->rates->delete($rateId, $manager);

        $this->assertNull($this->rates->find($rateId));
        $this->assertNull($this->rates->rateOn('USD', '2026-10-10'));
        $this->assertSame('deleted', $this->accountingDb()->table('accounting_audit_log')->where('entity_type', 'currency_rate')->where('entity_id', $rateId)->orderBy('audit_id', 'DESC')->get()->getRow()->action);
    }

    public function testHistoryListsTheNewestDayFirstAndFiltersByCurrency(): void
    {
        $manager = $this->manager();
        (new Currencies())->setActive('EUR', true);
        $this->rates->add(['currency_code' => 'USD', 'rate' => '3.75', 'effective_date' => '2026-10-10'], $manager);
        $this->rates->add(['currency_code' => 'USD', 'rate' => '3.60', 'effective_date' => '2026-10-20'], $manager);
        $this->rates->add(['currency_code' => 'EUR', 'rate' => '4.10', 'effective_date' => '2026-10-15'], $manager);

        $this->assertSame(['2026-10-20', '2026-10-15', '2026-10-10'], array_column($this->rates->history(), 'effective_date'));
        $this->assertSame(['2026-10-20', '2026-10-10'], array_column($this->rates->history('USD'), 'effective_date'));
    }

    public function testRateOnNeverChangesWhatADocumentCapturedBeforeTheTableChanged(): void
    {
        $manager  = $this->manager();
        $rateId   = $this->rates->add(['currency_code' => 'USD', 'rate' => '3.75', 'effective_date' => '2026-10-10'], $manager);
        $captured = (new Currencies())->documentCurrency('USD', '2026-10-27');

        $this->rates->update($rateId, ['rate' => '3.00'], $manager);

        $this->assertSame('3.75000000', $captured->rate);
        $this->assertSame('3.00000000', (new Currencies())->documentCurrency('USD', '2026-10-27')->rate);
    }
}
