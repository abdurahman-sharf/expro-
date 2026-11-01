<?php

namespace Tests\Libraries\Pricing;

use App\Libraries\Accounting\AccountingError;
use App\Libraries\Accounting\AccountingException;
use App\Libraries\Accounting\AccountingGuard;
use App\Libraries\Pricing\SalesControlSettings;
use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\DatabaseTestTrait;
use Config\Database;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Support\AccountingFixtureTrait;
use Tests\Support\EmployeeFixtureTrait;
use Tests\Support\ItemFixtureTrait;

class SalesControlSettingsTest extends CIUnitTestCase
{
    use DatabaseTestTrait;
    use AccountingFixtureTrait;
    use EmployeeFixtureTrait;
    use ItemFixtureTrait;

    private const KEYS = [
        SalesControlSettings::PRICE_LISTS,
        SalesControlSettings::DEFAULT_LIST,
        SalesControlSettings::CHANGE_LIST,
        SalesControlSettings::MINIMUM_PRICE,
        SalesControlSettings::MAX_DISCOUNT_ON,
        SalesControlSettings::MAX_DISCOUNT,
        SalesControlSettings::EXEMPT_CATEGORIES,
        SalesControlSettings::CREDIT_LIMIT,
        SalesControlSettings::CREDIT_PERIOD,
    ];

    protected $migrate     = true;
    protected $migrateOnce = true;
    protected $refresh     = false;
    protected $namespace   = null;

    private SalesControlSettings $settings;
    private int $manager;

    public static function setUpBeforeClass(): void
    {
        $seeder = Database::seeder('tests');
        $seeder->call('TestDatabaseBootstrapSeeder');
    }

    protected function setUp(): void
    {
        parent::setUp();

        $this->accountingDb()->table('app_config')->whereIn('key', self::KEYS)->delete();

        $this->settings = new SalesControlSettings();
        $this->manager  = $this->employeeWithGrants([AccountingGuard::SETTINGS_MANAGE]);
    }

    protected function tearDown(): void
    {
        $this->accountingDb()->table('app_config')->whereIn('key', self::KEYS)->delete();
        $this->accountingDb()->table('accounting_audit_log')->where('entity_type', 'settings')->delete();
        $this->accountingDb()->table('price_lists')->where('price_list_id >', 0)->delete();

        parent::tearDown();
    }

    private function audit(): array
    {
        return $this->accountingDb()->table('accounting_audit_log')->where(['entity_type' => 'settings', 'action' => 'settings_updated'])->orderBy('audit_id', 'ASC')->get()->getResultArray();
    }

    private function refused(callable $change): AccountingException
    {
        try {
            $change();
        } catch (AccountingException $refusal) {
            return $refusal;
        }

        $this->fail('The change should have been refused.');
    }

    public function testAll_IsOffForAShopThatNeverOpenedTheSettings(): void
    {
        $this->assertSame([
            'price_lists'          => false,
            'default_list'         => null,
            'change_list'          => false,
            'minimum_price'        => false,
            'max_discount_enabled' => false,
            'max_discount'         => null,
            'exempt_categories'    => [],
            'credit_limit'         => false,
            'credit_period'        => false,
        ], $this->settings->all());
    }

    public function testUpdate_StoresWhatWasChangedAndWritesTheBeforeAndAfterInTheAuditLog(): void
    {
        $this->accountingDb()->table('price_lists')->insert(['name' => 'Wholesale']);
        $listId = (int) $this->accountingDb()->insertID();

        $this->settings->update(['price_lists' => true, 'default_list' => $listId, 'minimum_price' => true, 'max_discount_enabled' => true, 'max_discount' => '12.5', 'exempt_categories' => ['Services'], 'credit_limit' => true], $this->manager);

        $this->assertSame([
            'price_lists'          => true,
            'default_list'         => $listId,
            'change_list'          => false,
            'minimum_price'        => true,
            'max_discount_enabled' => true,
            'max_discount'         => '12.50',
            'exempt_categories'    => ['Services'],
            'credit_limit'         => true,
            'credit_period'        => false,
        ], $this->settings->all());

        $entries = $this->audit();

        $this->assertCount(1, $entries);
        $this->assertSame($this->manager, (int) $entries[0]['employee_id']);

        $details = json_decode($entries[0]['details'], true);

        $this->assertSame(false, $details['before']['price_lists']);
        $this->assertSame(true, $details['after']['price_lists']);
        $this->assertSame('12.50', $details['after']['max_discount']);
        $this->assertArrayNotHasKey('change_list', $details['after']);
    }

    public function testUpdate_WritesNothingWhenNothingChanged(): void
    {
        $this->settings->update(['minimum_price' => true], $this->manager);
        $this->settings->update(['minimum_price' => true, 'credit_limit' => false], $this->manager);

        $this->assertCount(1, $this->audit());
    }

    public function testUpdate_LeavesOutWhatWasNotGiven(): void
    {
        $this->settings->update(['minimum_price' => true, 'max_discount' => '5'], $this->manager);
        $this->settings->update(['credit_limit' => true], $this->manager);

        $this->assertTrue($this->settings->minimumPriceEnabled());
        $this->assertSame('5.00', $this->settings->maxDiscountPercent());
        $this->assertTrue($this->settings->creditLimitEnabled());
    }

    public function testUpdate_RequiresThePermissionToChangeTheAccountingSettings(): void
    {
        $clerk = $this->employeeWithGrants([AccountingGuard::PRICE_OVERRIDE, AccountingGuard::CREDIT_OVERRIDE]);

        $refusal = $this->refused(fn () => $this->settings->update(['credit_limit' => true], $clerk));

        $this->assertSame(AccountingError::PermissionDenied, $refusal->error);
        $this->assertFalse($this->settings->creditLimitEnabled());
        $this->assertSame([], $this->audit());
    }

    public function testUpdate_AcceptsTheSystemAsTheEmployee(): void
    {
        $this->settings->update(['credit_period' => true]);

        $this->assertTrue($this->settings->creditPeriodEnabled());
    }

    /**
     * @return array<string, array{0: string}>
     */
    public static function invalidPercentProvider(): array
    {
        return ['over a hundred' => ['100.01'], 'negative' => ['-1'], 'not a number' => ['ten'], 'three decimals' => ['10.123'], 'a thousand' => ['1000']];
    }

    #[DataProvider('invalidPercentProvider')]
    public function testUpdate_RejectsAHighestDiscountThatIsNotAPercent(string $percent): void
    {
        $refusal = $this->refused(fn () => $this->settings->update(['max_discount' => $percent], $this->manager));

        $this->assertSame(AccountingError::ControlSettingInvalid, $refusal->error);
        $this->assertNull($this->settings->maxDiscountPercent());
        $this->assertSame([], $this->audit());
    }

    public function testUpdate_AcceptsTheLimitsOfAPercentAndTakesAnEmptyOneAsNone(): void
    {
        $this->settings->update(['max_discount' => '100'], $this->manager);

        $this->assertSame('100.00', $this->settings->maxDiscountPercent());

        $this->settings->update(['max_discount' => '0'], $this->manager);

        $this->assertSame('0.00', $this->settings->maxDiscountPercent());

        $this->settings->update(['max_discount' => ''], $this->manager);

        $this->assertNull($this->settings->maxDiscountPercent());
    }

    public function testUpdate_RejectsADefaultListThatDoesNotExist(): void
    {
        $refusal = $this->refused(fn () => $this->settings->update(['default_list' => 99999], $this->manager));

        $this->assertSame(AccountingError::ControlSettingInvalid, $refusal->error);
        $this->assertNull($this->settings->defaultPriceListId());
    }

    public function testUpdate_TakesNothingOrZeroAsNoDefaultList(): void
    {
        $this->accountingDb()->table('price_lists')->insert(['name' => 'Retail']);
        $listId = (int) $this->accountingDb()->insertID();

        $this->settings->update(['default_list' => (string) $listId], $this->manager);

        $this->assertSame($listId, $this->settings->defaultPriceListId());

        $this->settings->update(['default_list' => '0'], $this->manager);

        $this->assertNull($this->settings->defaultPriceListId());
    }

    public function testUpdate_KeepsTheCategoriesTrimmedOnceAndInOrderAndRejectsOnesThatAreNotText(): void
    {
        $this->settings->update(['exempt_categories' => [' Services ', 'Accessories', 'Services', '']], $this->manager);

        $this->assertSame(['Accessories', 'Services'], $this->settings->discountExemptCategories());

        $refusal = $this->refused(fn () => $this->settings->update(['exempt_categories' => [str_repeat('x', 256)]], $this->manager));

        $this->assertSame(AccountingError::ControlSettingInvalid, $refusal->error);
        $this->assertSame(['Accessories', 'Services'], $this->settings->discountExemptCategories());
    }

    public function testDiscountExemptCategories_IgnoresWhatIsStoredThatIsNotAListOfText(): void
    {
        $this->setConfigValue(SalesControlSettings::EXEMPT_CATEGORIES, '{"a": 1}');

        $this->assertSame([], $this->settings->discountExemptCategories());

        $this->setConfigValue(SalesControlSettings::EXEMPT_CATEGORIES, 'not json');

        $this->assertSame([], $this->settings->discountExemptCategories());

        $this->setConfigValue(SalesControlSettings::EXEMPT_CATEGORIES, '["Tools", 5, ""]');

        $this->assertSame(['Tools'], $this->settings->discountExemptCategories());
    }

    public function testMaxDiscountPercent_IgnoresAStoredValueThatIsNotAPercent(): void
    {
        $this->setConfigValue(SalesControlSettings::MAX_DISCOUNT, '250');

        $this->assertNull($this->settings->maxDiscountPercent());
    }

    public function testItemCategories_ListsEachCategoryOfTheItemsThatAreNotDeletedOnce(): void
    {
        $first  = $this->createTestItem();
        $second = $this->createTestItem();
        $gone   = $this->createTestItem();

        $this->accountingDb()->table('items')->where('item_id', $first)->update(['category' => 'Zeta WP36']);
        $this->accountingDb()->table('items')->where('item_id', $second)->update(['category' => 'Zeta WP36']);
        $this->accountingDb()->table('items')->where('item_id', $gone)->update(['category' => 'Gone WP36', 'deleted' => 1]);

        $categories = $this->settings->itemCategories();

        $this->assertSame(1, count(array_keys($categories, 'Zeta WP36', true)));
        $this->assertNotContains('Gone WP36', $categories);
        $this->assertNotContains('', $categories);
    }

    public function testIsPercent_Accepts0To100WithTwoDecimalsAtMost(): void
    {
        foreach (['0', '0.5', '99.99', '100', '100.00', '7'] as $percent) {
            $this->assertTrue(SalesControlSettings::isPercent($percent), $percent);
        }

        foreach (['', '101', '100.01', '1.234', '-1', '1e2', ' 5'] as $percent) {
            $this->assertFalse(SalesControlSettings::isPercent($percent), $percent);
        }
    }
}
