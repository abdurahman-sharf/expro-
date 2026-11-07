<?php

namespace Tests\Controllers;

use App\Libraries\Accounting\AccountingGuard;
use App\Libraries\Accounting\Currencies;
use App\Libraries\Accounting\PostingService;
use CodeIgniter\Exceptions\PageNotFoundException;
use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\DatabaseTestTrait;
use Tests\Support\AccountingControllerFixtureTrait;

class AccountingCurrenciesControllerTest extends CIUnitTestCase
{
    use DatabaseTestTrait;
    use AccountingControllerFixtureTrait;

    protected $migrate     = true;
    protected $migrateOnce = true;
    protected $refresh     = false;
    protected $namespace   = null;

    protected function setUp(): void
    {
        parent::setUp();

        $this->prepareAccounting();
    }

    protected function tearDown(): void
    {
        $this->releaseOpenTransactions();
        $this->clearJournals();

        parent::tearDown();
    }

    private function manager(): int
    {
        $personId = $this->employeeWithGrants(['accounting_currencies', AccountingGuard::CURRENCIES_MANAGE]);
        $this->actAs($personId);

        return $personId;
    }

    private function reader(): int
    {
        $personId = $this->employeeWithGrants(['accounting_currencies']);
        $this->actAs($personId);

        return $personId;
    }

    public function testGetIndex_ShowsTheScreenToAUserWithTheModuleGrantWithoutTheAddButton(): void
    {
        $this->reader();

        $response = $this->get('/accounting_currencies');

        $response->assertStatus(200);
        $response->assertSee('id="table"');
        $response->assertDontSee('accounting_currencies/form"');
    }

    public function testGetIndex_OffersTheAddButtonWithTheManagePermission(): void
    {
        $this->manager();

        $this->get('/accounting_currencies')->assertSee('data-href="accounting_currencies/form"');
    }

    public function testGetIndex_IsNotFoundWhileAccountingIsDisabled(): void
    {
        $this->prepareAccounting(false);
        $this->reader();

        $this->expectException(PageNotFoundException::class);

        $this->get('/accounting_currencies');
    }

    public function testGetIndex_RedirectsAUserWithoutTheModuleGrant(): void
    {
        $this->actAs($this->employeeWithGrants(['accounting_chart']));

        $this->get('/accounting_currencies')->assertRedirect();
    }

    public function testGetSearch_ListsTheBaseCurrencyFirstWithItsRateOfOneAndItsDecimals(): void
    {
        $this->manager();
        (new Currencies())->setActive('USD', true);
        (new Currencies())->rates()->add(['currency_code' => 'USD', 'rate' => '3.75', 'effective_date' => '2020-01-01']);

        $result = $this->jsonOf($this->get('/accounting_currencies/search?limit=500'));

        $this->assertSame('SAR', $result['rows'][0]['code']);
        $this->assertSame(2, $result['rows'][0]['decimals']);
        $this->assertStringContainsString('>1<', $result['rows'][0]['latest_rate']);

        $usd = array_values(array_filter($result['rows'], static fn (array $row): bool => $row['code'] === 'USD'))[0];
        $this->assertStringContainsString('>3.75<', $usd['latest_rate']);
    }

    public function testGetSearch_FiltersByCodeAndByEitherName(): void
    {
        $this->manager();
        (new Currencies())->setActive('KWD', true);

        $this->assertSame(['KWD'], array_column($this->jsonOf($this->get('/accounting_currencies/search?search=kuw'))['rows'], 'code'));
        $this->assertSame(['KWD'], array_column($this->jsonOf($this->get('/accounting_currencies/search?search=' . urlencode('الكويتي')))['rows'], 'code'));
        $this->assertSame(1, $this->jsonOf($this->get('/accounting_currencies/search?search=kwd'))['total']);
    }

    public function testGetSearch_OffersTheRatesAndEditOfAForeignCurrencyAndOnlyTheEditOfTheBase(): void
    {
        $this->manager();
        (new Currencies())->setActive('USD', true);

        $base    = $this->jsonOf($this->get('/accounting_currencies/search?search=sar'))['rows'][0];
        $foreign = $this->jsonOf($this->get('/accounting_currencies/search?search=usd'))['rows'][0];

        $this->assertStringNotContainsString('accounting_currencies/rates/SAR', $base['edit']);
        $this->assertStringContainsString('accounting_currencies/form/SAR', $base['edit']);
        $this->assertStringNotContainsString('data-acc-action', $base['edit']);
        $this->assertStringContainsString('accounting_currencies/rates/USD', $foreign['edit']);
        $this->assertStringContainsString('data-acc-action="deactivate"', $foreign['edit']);
    }

    public function testGetSearch_ShowsNoEditToAReader(): void
    {
        $this->reader();
        (new Currencies())->setActive('USD', true);

        $row = $this->jsonOf($this->get('/accounting_currencies/search?search=usd'))['rows'][0];

        $this->assertStringNotContainsString('accounting_currencies/form', $row['edit']);
        $this->assertStringNotContainsString('data-acc-action', $row['edit']);
    }

    public function testGetForm_IsNotFoundWithoutTheManagePermission(): void
    {
        $this->reader();

        $this->expectException(PageNotFoundException::class);

        $this->get('/accounting_currencies/form');
    }

    public function testGetForm_ShowsTheDecimalsOfAnUnusedCurrencyOpenAndOfAUsedOneLocked(): void
    {
        $this->manager();
        $this->accountingDb()->table('treasuries')->insert(['company_id' => 1, 'branch_id' => 1, 'name' => 'EUR box', 'kind' => 'cash', 'currency_code' => 'EUR']);

        $selectLocked = '/<select name="decimals"[^>]*\sdisabled/';

        $this->assertDoesNotMatchRegularExpression($selectLocked, $this->get('/accounting_currencies/form/JPY')->getBody());
        $this->assertMatchesRegularExpression($selectLocked, $this->get('/accounting_currencies/form/EUR')->getBody());
        $this->get('/accounting_currencies/form')->assertSee('name="code"');
    }

    public function testGetForm_LocksTheDecimalsOfTheBaseCurrencyOnceAJournalIsPosted(): void
    {
        $this->manager();

        $this->assertDoesNotMatchRegularExpression('/<select name="decimals"[^>]*\sdisabled/', $this->get('/accounting_currencies/form/SAR')->getBody());

        (new PostingService())->post($this->balancedDraft());

        $this->assertMatchesRegularExpression('/<select name="decimals"[^>]*\sdisabled/', $this->get('/accounting_currencies/form/SAR')->getBody());
    }

    public function testPostStore_CreatesACurrencyWithTheDecimalsChosen(): void
    {
        $this->manager();

        $result = $this->jsonOf($this->post('/accounting_currencies/store/-1', ['code' => 'XAA', 'name_ar' => 'عملة', 'name_en' => 'Currency', 'decimals' => '3']));

        $this->assertTrue($result['success']);
        $this->assertSame(3, (new Currencies())->find('XAA')->decimals);
    }

    public function testPostStore_RejectsAUserWithoutTheManagePermission(): void
    {
        $this->reader();

        $result = $this->jsonOf($this->post('/accounting_currencies/store/-1', ['code' => 'XAA', 'name_ar' => 'عملة', 'name_en' => 'Currency', 'decimals' => '2']));

        $this->assertFalse($result['success']);
        $this->assertNull((new Currencies())->find('XAA'));
    }

    public function testPostStore_ReportsAnInvalidCodeInTheLanguageInUse(): void
    {
        $this->manager();

        $result = $this->jsonOf($this->post('/accounting_currencies/store/-1', ['code' => 'XY', 'name_ar' => 'عملة', 'name_en' => 'Currency', 'decimals' => '2']));

        $this->assertFalse($result['success']);
        $this->assertSame(lang('Accounting.error_currency_code_invalid'), $result['message']);
    }

    public function testPostStore_UpdatesTheNamesOfAnExistingCurrency(): void
    {
        $this->manager();

        $result = $this->jsonOf($this->post('/accounting_currencies/store/USD', ['name_ar' => 'دولار', 'name_en' => 'Dollar', 'decimals' => '2']));

        $this->assertTrue($result['success']);
        $this->assertSame('Dollar', (new Currencies())->find('USD')->nameEn);
    }

    public function testPostStore_UpdatesTheNamesOfACurrencyThatATreasuryUsedWhenTheLockedFormSendsNoDecimals(): void
    {
        $this->manager();
        $this->accountingDb()->table('treasuries')->insert(['company_id' => 1, 'branch_id' => 1, 'name' => 'EUR box', 'kind' => 'cash', 'currency_code' => 'EUR']);

        try {
            $result = $this->jsonOf($this->post('/accounting_currencies/store/EUR', ['name_ar' => 'يورو', 'name_en' => 'Euro (renamed)']));

            $this->assertTrue($result['success'], (string) ($result['message'] ?? ''));
            $this->assertSame('Euro (renamed)', (new Currencies())->find('EUR')->nameEn);
            $this->assertSame(2, (new Currencies())->find('EUR')->decimals);
        } finally {
            $this->accountingDb()->table('treasuries')->where('name', 'EUR box')->delete();
            (new Currencies())->update('EUR', ['name_en' => 'Euro'], $this->manager());
        }
    }

    public function testPostStore_UpdatesTheNamesOfTheBaseCurrencyOnceAJournalIsPostedWhenTheLockedFormSendsNoDecimals(): void
    {
        $this->manager();
        (new PostingService())->post($this->balancedDraft());

        $before = (new Currencies())->find('SAR');

        try {
            $result = $this->jsonOf($this->post('/accounting_currencies/store/SAR', ['name_ar' => $before->nameAr, 'name_en' => 'Saudi Riyal (renamed)']));

            $this->assertTrue($result['success'], (string) ($result['message'] ?? ''));
            $this->assertSame('Saudi Riyal (renamed)', (new Currencies())->find('SAR')->nameEn);
        } finally {
            $this->accountingDb()->table('accounting_currencies')->where('currency_code', 'SAR')->update(['name_en' => $before->nameEn]);
        }
    }

    public function testPostStore_RejectsNewDecimalsOfACurrencyThatATreasuryUsedWhenTheyAreSentByHand(): void
    {
        $this->manager();
        $this->accountingDb()->table('treasuries')->insert(['company_id' => 1, 'branch_id' => 1, 'name' => 'EUR box', 'kind' => 'cash', 'currency_code' => 'EUR']);

        try {
            $result = $this->jsonOf($this->post('/accounting_currencies/store/EUR', ['name_ar' => 'يورو', 'name_en' => 'Euro', 'decimals' => '3']));

            $this->assertFalse($result['success']);
            $this->assertSame(lang('Accounting.error_currency_in_use'), $result['message']);
            $this->assertSame(2, (new Currencies())->find('EUR')->decimals);
        } finally {
            $this->accountingDb()->table('treasuries')->where('name', 'EUR box')->delete();
        }
    }

    public function testPostDeactivate_RefusesTheBaseCurrencyWrittenInLowerCase(): void
    {
        $this->manager();

        $this->assertFalse($this->jsonOf($this->post('/accounting_currencies/deactivate/sar'))['success']);
        $this->assertTrue((new Currencies())->find('SAR')->active);
        $this->assertSame(0, $this->accountingDb()->table('accounting_audit_log')->where(['entity_type' => 'currency', 'action' => 'deactivated'])->countAllResults());
    }

    public function testGetRates_IsNotFoundForTheBaseCurrencyWrittenInLowerCase(): void
    {
        $this->manager();

        $this->expectException(PageNotFoundException::class);

        $this->get('/accounting_currencies/rates/sar');
    }

    public function testPostActivate_SwitchesACurrencyOnAndPostDeactivateOffButNeverTheBase(): void
    {
        $this->manager();

        $this->assertTrue($this->jsonOf($this->post('/accounting_currencies/activate/USD'))['success']);
        $this->assertTrue((new Currencies())->find('USD')->active);
        $this->assertTrue($this->jsonOf($this->post('/accounting_currencies/deactivate/USD'))['success']);
        $this->assertFalse((new Currencies())->find('USD')->active);
        $this->assertFalse($this->jsonOf($this->post('/accounting_currencies/deactivate/SAR'))['success']);
        $this->assertTrue((new Currencies())->find('SAR')->active);
    }

    public function testPostActivate_RejectsAUserWithoutTheManagePermission(): void
    {
        $this->reader();

        $this->assertFalse($this->jsonOf($this->post('/accounting_currencies/activate/USD'))['success']);
        $this->assertFalse((new Currencies())->find('USD')->active);
    }

    public function testGetRates_IsNotFoundForTheBaseCurrencyAndForAnUnknownOne(): void
    {
        $this->manager();

        try {
            $this->get('/accounting_currencies/rates/SAR');
            $this->fail('The base currency has no rates screen.');
        } catch (PageNotFoundException) {
            $this->addToAssertionCount(1);
        }

        $this->expectException(PageNotFoundException::class);

        $this->get('/accounting_currencies/rates/QQQ');
    }

    public function testGetRates_ShowsTheScreenWithTheAddButtonOnlyToWhoManages(): void
    {
        $this->reader();
        $readerView = $this->get('/accounting_currencies/rates/USD');
        $readerView->assertStatus(200);
        $readerView->assertDontSee('accounting_currencies/rate_form/USD');

        $this->manager();
        $this->get('/accounting_currencies/rates/USD')->assertSee('accounting_currencies/rate_form/USD');
    }

    public function testPostRateStore_AddsARateAndTheSearchListsItNewestFirst(): void
    {
        $this->manager();
        (new Currencies())->setActive('USD', true);

        $first  = $this->jsonOf($this->post('/accounting_currencies/rate_store/USD', ['rate' => '3.75', 'effective_date' => '2026-10-10', 'note' => 'a']));
        $second = $this->jsonOf($this->post('/accounting_currencies/rate_store/USD', ['rate' => '3.60', 'effective_date' => '2026-10-20', 'note' => 'b']));

        $this->assertTrue($first['success'] && $second['success']);

        $rows = $this->jsonOf($this->get('/accounting_currencies/rates/USD/search'))['rows'];

        $this->assertSame([$second['id'], $first['id']], array_column($rows, 'rate_id'));
        $this->assertStringContainsString('3.6', $rows[0]['rate']);
    }

    public function testPostRateStore_RejectsAReaderAndAnInvalidRateAndTheBaseCurrency(): void
    {
        $this->reader();
        (new Currencies())->setActive('USD', true);

        $this->assertFalse($this->jsonOf($this->post('/accounting_currencies/rate_store/USD', ['rate' => '3.75', 'effective_date' => '2026-10-10']))['success']);

        $this->manager();

        $this->assertFalse($this->jsonOf($this->post('/accounting_currencies/rate_store/USD', ['rate' => '0', 'effective_date' => '2026-10-10']))['success']);
        $this->assertFalse($this->jsonOf($this->post('/accounting_currencies/rate_store/SAR', ['rate' => '1', 'effective_date' => '2026-10-10']))['success']);
        $this->assertSame(0, $this->countRows('accounting_currency_rates'));
    }

    public function testPostRateStore_UpdatesARateOfThatCurrencyOnly(): void
    {
        $this->manager();
        $currencies = new Currencies();
        $currencies->setActive('USD', true);
        $currencies->setActive('EUR', true);
        $rateId = $currencies->rates()->add(['currency_code' => 'USD', 'rate' => '3.75', 'effective_date' => '2026-10-10']);

        $this->assertTrue($this->jsonOf($this->post('/accounting_currencies/rate_store/USD/' . $rateId, ['rate' => '3.70', 'effective_date' => '2026-10-10']))['success']);
        $this->assertSame('3.70000000', $currencies->rates()->find($rateId)->rate);

        $this->expectException(PageNotFoundException::class);

        $this->post('/accounting_currencies/rate_store/EUR/' . $rateId, ['rate' => '9', 'effective_date' => '2026-10-10']);
    }

    public function testPostRateDelete_RemovesARateOnlyForWhoManages(): void
    {
        $this->manager();
        $currencies = new Currencies();
        $currencies->setActive('USD', true);
        $rateId = $currencies->rates()->add(['currency_code' => 'USD', 'rate' => '3.75', 'effective_date' => '2026-10-10']);

        $this->reader();
        $this->assertFalse($this->jsonOf($this->post('/accounting_currencies/rate_delete/' . $rateId))['success']);
        $this->assertNotNull($currencies->rates()->find($rateId));

        $this->manager();
        $this->assertTrue($this->jsonOf($this->post('/accounting_currencies/rate_delete/' . $rateId))['success']);
        $this->assertNull($currencies->rates()->find($rateId));
    }

    public function testGetRateForm_IsNotFoundWithoutTheManagePermissionAndForARateOfAnotherCurrency(): void
    {
        $this->reader();

        try {
            $this->get('/accounting_currencies/rate_form/USD');
            $this->fail('A reader has no rate form.');
        } catch (PageNotFoundException) {
            $this->addToAssertionCount(1);
        }

        $this->manager();
        $currencies = new Currencies();
        $currencies->setActive('USD', true);
        $rateId = $currencies->rates()->add(['currency_code' => 'USD', 'rate' => '3.75', 'effective_date' => '2026-10-10']);

        $this->get('/accounting_currencies/rate_form/USD/' . $rateId)->assertSee('value="3.75"');

        $this->expectException(PageNotFoundException::class);

        $this->get('/accounting_currencies/rate_form/EUR/' . $rateId);
    }
}
