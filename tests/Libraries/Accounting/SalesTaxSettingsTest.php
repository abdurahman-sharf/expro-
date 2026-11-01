<?php

namespace Tests\Libraries\Accounting;

use App\Libraries\Accounting\SalesTaxSettings;
use App\Models\Appconfig;
use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\DatabaseTestTrait;
use Config\Database;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Support\AccountingFixtureTrait;

/**
 * The default invoice tax mode and the requirement of a tax on every line: how they are read, that a
 * change is audited with what it was and what it became, and that the line a sale lacks a tax on is found.
 *
 * @internal
 */
final class SalesTaxSettingsTest extends CIUnitTestCase
{
    use DatabaseTestTrait;
    use AccountingFixtureTrait;

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

        $this->setConfigValue(SalesTaxSettings::INCLUDED_KEY, '0');
        $this->setConfigValue(SalesTaxSettings::TAX_ONE_KEY, '0');
    }

    protected function tearDown(): void
    {
        $this->setConfigValue(SalesTaxSettings::INCLUDED_KEY, '0');
        $this->setConfigValue(SalesTaxSettings::TAX_ONE_KEY, '0');
        $this->accountingDb()->table('accounting_audit_log')->where('action', 'sales_tax_settings_updated')->delete();

        parent::tearDown();
    }

    private function audited(): int
    {
        return $this->accountingDb()->table('accounting_audit_log')->where(['entity_type' => 'settings', 'action' => 'sales_tax_settings_updated'])->countAllResults();
    }

    #[DataProvider('storedValueProvider')]
    public function testDefaultIncluded_ReadsTheKeyTheShopHasAlwaysUsed(string $stored, bool $expected): void
    {
        $this->setConfigValue(SalesTaxSettings::INCLUDED_KEY, $stored);

        $this->assertSame($expected, (new SalesTaxSettings())->defaultIncluded());
    }

    #[DataProvider('storedValueProvider')]
    public function testRequireTaxOne_ReadsItsKeyAndIsOffByDefault(string $stored, bool $expected): void
    {
        $this->setConfigValue(SalesTaxSettings::TAX_ONE_KEY, $stored);

        $this->assertSame($expected, (new SalesTaxSettings())->requireTaxOne());

        $this->accountingDb()->table('app_config')->where('key', SalesTaxSettings::TAX_ONE_KEY)->delete();

        $this->assertFalse((new SalesTaxSettings())->requireTaxOne(), 'a shop that never had the setting has it off');
    }

    /**
     * @return array<string, array{string, bool}>
     */
    public static function storedValueProvider(): iterable
    {
        return ['one' => ['1', true], 'zero' => ['0', false], 'empty' => ['', false]];
    }

    public function testUpdate_SavesBothSettingsAndAuditsWhatWasAndWhatBecame(): void
    {
        $changes = (new SalesTaxSettings())->update([SalesTaxSettings::INCLUDED_KEY => true, SalesTaxSettings::TAX_ONE_KEY => true], 1);

        $this->assertSame([SalesTaxSettings::INCLUDED_KEY => true, SalesTaxSettings::TAX_ONE_KEY => true], $changes);
        $this->assertTrue((new SalesTaxSettings())->defaultIncluded());
        $this->assertTrue((new SalesTaxSettings())->requireTaxOne());
        $this->assertSame('1', model(Appconfig::class)->get_value(SalesTaxSettings::INCLUDED_KEY));

        $entry   = $this->accountingDb()->table('accounting_audit_log')->where('action', 'sales_tax_settings_updated')->get()->getRow();
        $details = json_decode((string) $entry->details, true);

        $this->assertSame(1, (int) $entry->employee_id);
        $this->assertSame([SalesTaxSettings::INCLUDED_KEY => false, SalesTaxSettings::TAX_ONE_KEY => false], $details['before']);
        $this->assertSame([SalesTaxSettings::INCLUDED_KEY => true, SalesTaxSettings::TAX_ONE_KEY => true], $details['after']);
    }

    public function testUpdate_WritesNothingWhenTheValuesStayAsTheyWere(): void
    {
        $settings = new SalesTaxSettings();

        $this->assertSame([], $settings->update([SalesTaxSettings::INCLUDED_KEY => false, SalesTaxSettings::TAX_ONE_KEY => false], 1));
        $this->assertSame(0, $this->audited());

        $settings->update([SalesTaxSettings::INCLUDED_KEY => true], 1);

        $this->assertSame([], $settings->update([SalesTaxSettings::INCLUDED_KEY => true], 1));
        $this->assertSame(1, $this->audited());
    }

    public function testUpdate_LeavesTheSettingTheInputDoesNotNameAlone(): void
    {
        $settings = new SalesTaxSettings();

        $settings->update([SalesTaxSettings::TAX_ONE_KEY => true], 1);
        $settings->update([SalesTaxSettings::INCLUDED_KEY => true], 1);

        $this->assertTrue($settings->requireTaxOne());
        $this->assertTrue($settings->defaultIncluded());

        $changes = $settings->update([SalesTaxSettings::INCLUDED_KEY => false], 1);

        $this->assertSame([SalesTaxSettings::INCLUDED_KEY => false], $changes);
        $this->assertTrue($settings->requireTaxOne());
    }

    public function testUpdate_AuditsOnlyTheSettingThatChanged(): void
    {
        $settings = new SalesTaxSettings();

        $settings->update([SalesTaxSettings::INCLUDED_KEY => true], 1);
        $settings->update([SalesTaxSettings::INCLUDED_KEY => true, SalesTaxSettings::TAX_ONE_KEY => true], 1);

        $entries = $this->accountingDb()->table('accounting_audit_log')->where('action', 'sales_tax_settings_updated')->orderBy('audit_id', 'ASC')->get()->getResult();
        $second  = json_decode((string) $entries[1]->details, true);

        $this->assertCount(2, $entries);
        $this->assertSame([SalesTaxSettings::TAX_ONE_KEY => false], $second['before']);
        $this->assertSame([SalesTaxSettings::TAX_ONE_KEY => true], $second['after']);
    }

    public function testLineWithoutTax_FindsTheFirstLineThatHasNoTax(): void
    {
        $items = [0 => ['item_id' => 3, 'line' => 1], 1 => ['item_id' => 4, 'line' => 2], 2 => ['item_id' => 5, 'line' => 3]];
        $taxes = [['item_id' => 3, 'line' => 1], ['item_id' => 5, 'line' => 3]];

        $this->assertSame(2, SalesTaxSettings::lineWithoutTax($items, $taxes));
    }

    public function testLineWithoutTax_AnswersNothingWhenEveryLineHasATax(): void
    {
        $items = [0 => ['item_id' => 3, 'line' => 1], 1 => ['item_id' => 3, 'line' => 2]];
        $taxes = [['item_id' => 3, 'line' => 1, 'item_tax_amount' => '0'], ['item_id' => 3, 'line' => 2, 'item_tax_amount' => '5.00'], ['item_id' => 3, 'line' => 2, 'item_tax_amount' => '1.00']];

        $this->assertNull(SalesTaxSettings::lineWithoutTax($items, $taxes), 'a tax of nothing is still a tax');
    }

    public function testLineWithoutTax_TellsTheLinesOfOneItemApart(): void
    {
        $items = [0 => ['item_id' => 3, 'line' => 1], 1 => ['item_id' => 3, 'line' => 2]];

        $this->assertSame(2, SalesTaxSettings::lineWithoutTax($items, [['item_id' => 3, 'line' => 1]]));
        $this->assertSame(1, SalesTaxSettings::lineWithoutTax($items, []));
    }
}
