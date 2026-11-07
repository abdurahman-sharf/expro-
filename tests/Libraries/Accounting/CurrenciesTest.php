<?php

namespace Tests\Libraries\Accounting;

use App\Libraries\Accounting\AccountingError;
use App\Libraries\Accounting\AccountingException;
use App\Libraries\Accounting\AccountingGuard;
use App\Libraries\Accounting\Currencies;
use App\Libraries\Accounting\DocumentCurrency;
use App\Libraries\Accounting\PostingService;
use App\Libraries\Organization\BranchException;
use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\DatabaseTestTrait;
use Config\Database;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Support\AccountingFixtureTrait;

/**
 * @internal
 */
final class CurrenciesTest extends CIUnitTestCase
{
    use DatabaseTestTrait;
    use AccountingFixtureTrait;

    protected $migrate     = true;
    protected $migrateOnce = true;
    protected $refresh     = false;
    protected $namespace;
    private Currencies $currencies;

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
        $this->currencies = new Currencies();
    }

    protected function tearDown(): void
    {
        $this->releaseOpenTransactions();
        $this->clearJournals();
        $this->accountingDb()->table('treasuries')->whereIn('name', ['EUR box', 'USD box'])->delete();
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

    public function testAllListsTheBaseCurrencyFirstAndSwitchedOn(): void
    {
        $this->currencies->setActive('USD', true);
        $all = $this->currencies->all();

        $this->assertSame('SAR', $all[0]->code);
        $this->assertTrue($all[0]->active);
        $this->assertSame(2, $all[0]->decimals);
        $this->assertSame(['SAR', 'USD'], array_map(static fn ($currency): string => $currency->code, $this->currencies->all(activeOnly: true)));
    }

    public function testEnsureBasePutsTheBaseCurrencyBackSwitchedOnWhenItsRowIsGone(): void
    {
        $db = $this->accountingDb();
        $db->table('accounting_currencies')->where('currency_code', 'SAR')->delete();

        $this->currencies->ensureBase();

        $row = $db->table('accounting_currencies')->where('currency_code', 'SAR')->get()->getRow();
        $this->assertSame('1', (string) $row->active);
        $this->assertSame('الريال السعودي', $row->name_ar);

        $db->table('accounting_currencies')->where('currency_code', 'SAR')->update(['active' => 0]);
        $this->assertTrue($this->currencies->find('SAR')->active);
        $this->currencies->ensureBase();
        $this->assertSame('1', (string) $db->table('accounting_currencies')->where('currency_code', 'SAR')->get()->getRow()->active);
    }

    public function testEnsureBaseAddsABaseCodeOutsideTheCatalogWithTwoDecimals(): void
    {
        $this->setConfigValue('accounting_base_currency', 'ZZZ');

        $this->currencies->ensureBase();

        $row = $this->accountingDb()->table('accounting_currencies')->where('currency_code', 'ZZZ')->get()->getRow();
        $this->assertSame(2, (int) $row->decimals);
        $this->assertTrue($this->currencies->isBase('ZZZ'));
        $this->assertFalse($this->currencies->isBase('SAR'));
    }

    public function testDecimalsOfFollowsTheCatalogAndKeepsTwoForAnUnknownCode(): void
    {
        $this->assertSame(0, $this->currencies->decimalsOf('JPY'));
        $this->assertSame(3, $this->currencies->decimalsOf('KWD'));
        $this->assertSame(2, $this->currencies->decimalsOf('USD'));
        $this->assertSame(2, $this->currencies->decimalsOf('QQQ'));
        $this->assertSame(2, $this->currencies->decimalsOf('PLN'));
        $this->assertSame(2, $this->currencies->baseDecimals());
    }

    public function testCreateRequiresThePermissionToManageCurrencies(): void
    {
        $nobody = $this->employeeWithGrants(['accounting_settings']);

        $this->assertRefused(AccountingError::PermissionDenied, fn () => $this->currencies->create(['code' => 'XYZ', 'name_ar' => 'ع', 'name_en' => 'E', 'decimals' => 2], $nobody));
        $this->assertNull($this->currencies->find('XYZ'));
    }

    public function testCreateRejectsAnEmployeeLimitedToSomeBranches(): void
    {
        $branchId   = $this->createBranch('B2');
        $restricted = $this->employeeWithGrants([AccountingGuard::CURRENCIES_MANAGE], [$branchId]);

        $this->expectException(BranchException::class);

        $this->currencies->create(['code' => 'XYZ', 'name_ar' => 'ع', 'name_en' => 'E', 'decimals' => 2], $restricted);
    }

    public function testCreateAcceptsZeroTwoAndThreeDecimalsAndWritesTheAuditLog(): void
    {
        $manager = $this->manager();

        foreach (['XAA' => 0, 'XBB' => 2, 'XCC' => 3] as $code => $decimals) {
            $this->assertSame($code, $this->currencies->create(['code' => strtolower($code), 'name_ar' => 'عملة ' . $code, 'name_en' => 'Currency ' . $code, 'decimals' => (string) $decimals], $manager));
            $this->assertSame($decimals, $this->currencies->find($code)->decimals);
            $this->assertTrue($this->currencies->find($code)->active);
        }

        $audit = $this->accountingDb()->table('accounting_audit_log')->where('entity_type', 'currency')->where('action', 'created')->get()->getResult();

        $this->assertCount(3, $audit);
        $this->assertSame($manager, (int) $audit[0]->employee_id);
    }

    #[DataProvider('provideCreate_RejectsAnInvalidCurrency')]
    public function testCreateRejectsAnInvalidCurrency(array $input, AccountingError $expected): void
    {
        $this->assertRefused($expected, fn () => $this->currencies->create($input + ['code' => 'XYZ', 'name_ar' => 'ع', 'name_en' => 'E', 'decimals' => 2], $this->manager()));
    }

    /**
     * @return array<string, array{array<string, mixed>, AccountingError}>
     */
    public static function provideCreate_RejectsAnInvalidCurrency(): iterable
    {
        return [
            'two letters'       => [['code' => 'XY'], AccountingError::CurrencyCodeInvalid],
            'digits'            => [['code' => 'X1Z'], AccountingError::CurrencyCodeInvalid],
            'taken'             => [['code' => 'USD'], AccountingError::CurrencyCodeTaken],
            'one decimal'       => [['decimals' => 1], AccountingError::CurrencyDecimalsInvalid],
            'four decimals'     => [['decimals' => 4], AccountingError::CurrencyDecimalsInvalid],
            'empty arabic name' => [['name_ar' => ' '], AccountingError::CurrencyNameInvalid],
            'long english name' => [['name_en' => str_repeat('a', 101)], AccountingError::CurrencyNameInvalid],
        ];
    }

    public function testUpdateChangesTheNamesAndTheDecimalsOfACurrencyThatNothingUsed(): void
    {
        $manager = $this->manager();

        $this->currencies->update('JPY', ['name_en' => 'Yen', 'decimals' => '2'], $manager);

        try {
            $this->assertSame('Yen', $this->accountingDb()->table('accounting_currencies')->where('currency_code', 'JPY')->get()->getRow()->name_en);
            $this->assertSame(2, $this->currencies->decimalsOf('JPY'));

            $update = $this->accountingDb()->table('accounting_audit_log')->where('entity_type', 'currency')->where('action', 'updated')->get()->getRow();
            $this->assertSame(['name_en' => 'Japanese Yen', 'decimals' => '0'], json_decode($update->details, true)['before']);
        } finally {
            $this->accountingDb()->table('accounting_currencies')->where('currency_code', 'JPY')->update(['name_en' => 'Japanese Yen', 'decimals' => 0]);
        }
    }

    public function testUpdateRejectsNewDecimalsOnceADocumentUsedTheCurrency(): void
    {
        $this->accountingDb()->table('treasuries')->insert(['company_id' => 1, 'branch_id' => 1, 'name' => 'USD box', 'kind' => 'cash', 'currency_code' => 'USD']);

        $this->assertRefused(AccountingError::CurrencyInUse, fn () => $this->currencies->update('USD', ['decimals' => 3], $this->manager()));
        $this->assertSame(2, $this->currencies->decimalsOf('USD'));

        $this->currencies->update('USD', ['decimals' => 2, 'name_en' => 'Dollar'], $this->manager());
        $this->assertSame('Dollar', $this->currencies->find('USD')->nameEn);
    }

    public function testUpdateRejectsNewDecimalsOfTheBaseCurrencyOnceAJournalIsPosted(): void
    {
        (new PostingService())->post($this->balancedDraft());

        $this->assertRefused(AccountingError::CurrencyInUse, fn () => $this->currencies->update('SAR', ['decimals' => 3], $this->manager()));
    }

    public function testUpdateRequiresThePermissionAndAnExistingCurrency(): void
    {
        $this->assertRefused(AccountingError::PermissionDenied, fn () => $this->currencies->update('USD', ['name_en' => 'x'], $this->employeeWithGrants([])));
        $this->assertRefused(AccountingError::CurrencyNotFound, fn () => $this->currencies->update('QQQ', ['name_en' => 'x'], $this->manager()));
    }

    public function testUpdateRejectsAnEmployeeLimitedToSomeBranches(): void
    {
        $restricted = $this->employeeWithGrants([AccountingGuard::CURRENCIES_MANAGE], [$this->createBranch('B2')]);
        $before     = $this->currencies->find('EUR')->nameEn;

        try {
            $this->currencies->update('EUR', ['name_en' => 'Changed'], $restricted);
            $this->fail('An employee of one branch changed a currency of the company.');
        } catch (BranchException) {
            $this->addToAssertionCount(1);
        }

        $this->assertSame($before, $this->currencies->find('EUR')->nameEn);
    }

    public function testSetActiveSwitchesACurrencyOnAndOffAndRefusesToSwitchOffTheBase(): void
    {
        $manager = $this->manager();

        $this->currencies->setActive('EUR', true, $manager);
        $this->assertTrue($this->currencies->find('EUR')->active);

        $this->currencies->setActive('EUR', false, $manager);
        $this->assertFalse($this->currencies->find('EUR')->active);

        $this->assertRefused(AccountingError::CurrencyIsBase, fn () => $this->currencies->setActive('SAR', false, $manager));
        $this->assertTrue($this->currencies->find('SAR')->active);
        $this->assertSame(['activated', 'deactivated'], array_column($this->accountingDb()->table('accounting_audit_log')->where('entity_type', 'currency')->orderBy('audit_id')->get()->getResultArray(), 'action'));
    }

    public function testSetActiveRequiresThePermission(): void
    {
        $this->assertRefused(AccountingError::PermissionDenied, fn () => $this->currencies->setActive('EUR', true, $this->employeeWithGrants([])));
        $this->assertFalse($this->currencies->find('EUR')->active);
    }

    public function testSetActiveRejectsAnEmployeeLimitedToSomeBranches(): void
    {
        $restricted = $this->employeeWithGrants([AccountingGuard::CURRENCIES_MANAGE], [$this->createBranch('B2')]);

        try {
            $this->currencies->setActive('EUR', true, $restricted);
            $this->fail('An employee of one branch switched on a currency of the company.');
        } catch (BranchException) {
            $this->addToAssertionCount(1);
        }

        $this->assertFalse($this->currencies->find('EUR')->active);
    }

    public function testRequireForeignRejectsAnUnknownASwitchedOffAndTheBaseCurrency(): void
    {
        $this->assertRefused(AccountingError::CurrencyNotFound, fn () => $this->currencies->requireForeign('QQQ'));
        $this->assertRefused(AccountingError::CurrencyNotFound, fn () => $this->currencies->requireForeign('usd'));
        $this->assertRefused(AccountingError::CurrencyInactive, fn () => $this->currencies->requireForeign('USD'));
        $this->assertRefused(AccountingError::CurrencyIsBase, fn () => $this->currencies->requireForeign('SAR'));

        $this->currencies->setActive('USD', true);

        $this->assertSame('USD', $this->currencies->requireForeign('USD')->code);
    }

    public function testDocumentCurrencyIsNullForTheBaseCurrencyAndForNone(): void
    {
        $this->assertNull($this->currencies->documentCurrency(null, '2026-10-21'));
        $this->assertNull($this->currencies->documentCurrency('', '2026-10-21'));
        $this->assertNull($this->currencies->documentCurrency('SAR', '2026-10-21'));
    }

    public function testDocumentCurrencyTakesTheLatestRateOnOrBeforeTheDateOfTheDocument(): void
    {
        $this->currencies->setActive('USD', true);
        $rates = $this->currencies->rates();
        $rates->add(['currency_code' => 'USD', 'rate' => '3.75', 'effective_date' => '2026-10-01']);
        $rates->add(['currency_code' => 'USD', 'rate' => '3.60', 'effective_date' => '2026-11-05']);

        $before = $this->currencies->documentCurrency('USD', '2026-10-27');
        $onDay  = $this->currencies->documentCurrency('USD', '2026-11-05');

        $this->assertSame(new DocumentCurrency('USD', '3.75000000', 2), $before);
        $this->assertSame('3.60000000', $onDay->rate);
    }

    public function testDocumentCurrencyRejectsADateBeforeTheFirstRate(): void
    {
        $this->currencies->setActive('USD', true);
        $this->currencies->rates()->add(['currency_code' => 'USD', 'rate' => '3.75', 'effective_date' => '2026-10-01']);

        $this->assertRefused(AccountingError::RateMissing, fn () => $this->currencies->documentCurrency('USD', '2026-09-30'));
    }

    public function testDocumentCurrencyAcceptsATypedRateOnlyFromWhoManagesCurrencies(): void
    {
        $this->currencies->setActive('USD', true);

        $typed = $this->currencies->documentCurrency('USD', '2026-10-27', '3.7123', $this->manager());

        $this->assertSame('3.71230000', $typed->rate);
        $this->assertRefused(AccountingError::PermissionDenied, fn () => $this->currencies->documentCurrency('USD', '2026-10-27', '3.7123', $this->employeeWithGrants([])));
        $this->assertRefused(AccountingError::RateInvalid, fn () => $this->currencies->documentCurrency('USD', '2026-10-27', '-3', $this->manager()));
    }

    public function testDocumentCurrencyRefusesACurrencyOfThreeDecimalsAndAcceptsOneOfNone(): void
    {
        $this->currencies->setActive('KWD', true);
        $this->currencies->setActive('JPY', true);
        $this->currencies->rates()->add(['currency_code' => 'KWD', 'rate' => '12.04917', 'effective_date' => '2026-10-01']);
        $this->currencies->rates()->add(['currency_code' => 'JPY', 'rate' => '0.02376', 'effective_date' => '2026-10-01']);

        $this->assertRefused(AccountingError::ForeignDecimalsUnsupported, fn () => $this->currencies->documentCurrency('KWD', '2026-10-27'));
        $this->assertSame(0, $this->currencies->documentCurrency('JPY', '2026-10-27')->decimals);
    }

    public function testStoredReadsBackWhatADocumentCarriesAndIgnoresTheBase(): void
    {
        $this->assertNull($this->currencies->stored(null, null));
        $this->assertNull($this->currencies->stored('SAR', '1'));
        $this->assertNull($this->currencies->stored('USD', null));
        $this->assertSame(new DocumentCurrency('KWD', '12.04917000', 3), $this->currencies->stored('KWD', '12.04917'));
    }

    public function testInUseSeesADocumentAJournalLineAndATreasury(): void
    {
        $this->assertFalse($this->currencies->inUse('EUR'));

        $this->accountingDb()->table('treasuries')->insert(['company_id' => 1, 'branch_id' => 1, 'name' => 'EUR box', 'kind' => 'cash', 'currency_code' => 'EUR']);

        $this->assertTrue($this->currencies->inUse('EUR'));
    }

    public function testInUseNamesEveryColumnOfTheSchemaThatHoldsACurrencyCode(): void
    {
        $db     = $this->accountingDb();
        $prefix = $db->getPrefix();
        $found  = [];

        foreach ($db->query("SELECT TABLE_NAME, COLUMN_NAME FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND COLUMN_NAME IN ('currency_code', 'from_currency', 'to_currency')")->getResultArray() as $row) {
            $table = substr((string) $row['TABLE_NAME'], strlen($prefix));

            if (! in_array($table, ['accounting_currencies', 'accounting_currency_rates'], true)) {
                $found[] = $table . '.' . $row['COLUMN_NAME'];
            }
        }

        $listed = array_map(static fn (array $pair): string => $pair[0] . '.' . $pair[1], Currencies::USED_BY);

        sort($found);
        sort($listed);

        $this->assertSame($found, $listed, 'A column that holds a currency fixes its decimals once it is written');
    }

    public function testInUseSeesADraftSaleThatHasNoJournalYetAndFixesTheDecimalsOfItsCurrency(): void
    {
        $db = $this->accountingDb();

        $db->table('sales')->insert(['sale_time' => date('Y-m-d H:i:s'), 'employee_id' => $this->manager(), 'comment' => '', 'sale_status' => SUSPENDED, 'sale_type' => SALE_TYPE_POS, 'branch_id' => 1, 'currency_code' => 'EUR', 'currency_rate' => '4.10000000']);

        $saleId = (int) $db->insertID();

        try {
            $this->assertTrue($this->currencies->inUse('EUR'));
            $this->assertRefused(AccountingError::CurrencyInUse, fn () => $this->currencies->update('EUR', ['decimals' => 3], $this->manager()));
        } finally {
            $db->table('sales')->where('sale_id', $saleId)->delete();
        }

        $this->assertFalse($this->currencies->inUse('EUR'));
    }

    public function testInUseSeesATreasuryTransferInTheCurrencyOnEitherSide(): void
    {
        $db = $this->accountingDb();

        foreach (['from_currency', 'to_currency'] as $column) {
            $db->query('SET FOREIGN_KEY_CHECKS = 0');
            $db->table('treasury_transfers')->insert(['company_id' => 1, 'branch_id' => 1, 'from_treasury_id' => 1, 'to_treasury_id' => 2, 'amount' => '1.0000', 'transfer_date' => '2026-10-21', $column => 'EUR']);
            $transferId = (int) $db->insertID();
            $db->query('SET FOREIGN_KEY_CHECKS = 1');

            try {
                $this->assertTrue($this->currencies->inUse('EUR'), $column);
            } finally {
                $db->table('treasury_transfers')->where('transfer_id', $transferId)->delete();
            }
        }
    }

    public function testIsBaseIgnoresTheCaseAndTheSpacesOfTheCode(): void
    {
        $this->assertTrue($this->currencies->isBase('sar'));
        $this->assertTrue($this->currencies->isBase(' Sar '));
        $this->assertFalse($this->currencies->isBase('usd'));
        $this->assertFalse($this->currencies->isBase(''));
    }

    public function testFindReadsACurrencyWhateverTheCaseOfItsCode(): void
    {
        $this->assertSame('EUR', $this->currencies->find('eur')?->code);
        $this->assertSame('EUR', $this->currencies->find(' eUr ')?->code);
        $this->assertNull($this->currencies->find('qqq'));
    }

    public function testSetActiveRefusesToSwitchOffTheBaseCurrencyWrittenInLowerCase(): void
    {
        $this->assertRefused(AccountingError::CurrencyIsBase, fn () => $this->currencies->setActive('sar', false, $this->manager()));

        $this->assertTrue($this->currencies->find('SAR')->active);
        $this->assertSame(0, $this->accountingDb()->table('accounting_audit_log')->where(['entity_type' => 'currency', 'action' => 'deactivated'])->countAllResults());
    }

    public function testSetActiveSwitchesACurrencyOnWhateverTheCaseOfItsCode(): void
    {
        $this->currencies->setActive('eur', true, $this->manager());

        $this->assertTrue($this->currencies->find('EUR')->active);
    }

    public function testUpdateChangesACurrencyWhateverTheCaseOfItsCodeAndHoldsTheBaseToItsDecimals(): void
    {
        (new PostingService())->post($this->balancedDraft());

        $this->currencies->update('eur', ['name_en' => 'Euro (lower case)'], $this->manager());

        $this->assertSame('Euro (lower case)', $this->currencies->find('EUR')->nameEn);
        $this->assertRefused(AccountingError::CurrencyInUse, fn () => $this->currencies->update('sar', ['decimals' => 3], $this->manager()));
        $this->assertSame(2, $this->currencies->decimalsOf('SAR'));
        $this->currencies->update('EUR', ['name_en' => 'Euro'], $this->manager());
    }

    public function testInUseIgnoresTheCaseOfTheCode(): void
    {
        $this->accountingDb()->table('treasuries')->insert(['company_id' => 1, 'branch_id' => 1, 'name' => 'EUR box', 'kind' => 'cash', 'currency_code' => 'EUR']);

        $this->assertTrue($this->currencies->inUse('eur'));
    }
}
