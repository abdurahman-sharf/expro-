<?php

namespace Tests\Database;

use App\Database\Migrations\VatClassification;
use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\DatabaseTestTrait;
use Config\Database;
use RuntimeException;

class VatClassificationMigrationTest extends CIUnitTestCase
{
    use DatabaseTestTrait;

    protected $migrate     = true;
    protected $migrateOnce = true;
    protected $refresh     = false;
    protected $namespace   = null;

    public static function setUpBeforeClass(): void
    {
        $seeder = Database::seeder('tests');
        $seeder->call('TestDatabaseBootstrapSeeder');
    }

    protected function tearDown(): void
    {
        if ($this->hasTable('sales_items_vat')) {
            Database::connect()->table('sales_items_vat')->truncate();
        }

        (new VatClassification())->up();

        parent::tearDown();
    }

    private function hasTable(string $table): bool
    {
        $db = Database::connect();

        return $db->query('SELECT COUNT(*) AS found FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ?', [$db->prefixTable($table)])->getRow()->found > 0;
    }

    public function testUp_SeedsTheStandardRateWithItsHistory(): void
    {
        $db       = Database::connect();
        $standard = $db->table('vat_classes')->where('code', 'S')->get()->getRow();
        $rates    = $db->table('vat_class_rates')->where('class_id', (int) $standard->class_id)->orderBy('valid_from')->get()->getResult();

        $this->assertSame('S', $standard->category);
        $this->assertSame([['5.000', '2018-01-01', '2020-06-30'], ['15.000', '2020-07-01', null]], array_map(static fn (object $rate): array => [$rate->percent, $rate->valid_from, $rate->valid_to], $rates));
    }

    public function testUp_SeedsTheClassesWithoutTaxWithTheirOfficialReasons(): void
    {
        $codes = array_column(Database::connect()->table('vat_classes')->select('exemption_code')->where('category !=', 'S')->get()->getResultArray(), 'exemption_code');

        foreach (['VATEX-SA-32', 'VATEX-SA-33', 'VATEX-SA-35', 'VATEX-SA-EDU', 'VATEX-SA-HEA', 'VATEX-SA-29', 'VATEX-SA-30', 'VATEX-SA-OOS'] as $code) {
            $this->assertContains($code, $codes);
        }
    }

    public function testUp_ClassifiesNoItemAndIsSafeToRunAgain(): void
    {
        $db     = Database::connect();
        $before = $db->table('vat_classes')->countAllResults();

        (new VatClassification())->up();

        $this->assertSame($before, $db->table('vat_classes')->countAllResults());
        $this->assertSame(0, $db->table('item_vat_classes')->countAllResults());
    }

    public function testUp_WritesTheModeFlagForACompanyAlreadyInSaudiMode(): void
    {
        $db   = Database::connect();
        $flag = static fn (): ?string => $db->table('app_config')->where('key', 'ksa_vat_mode')->get()->getRow()?->value;

        try {
            $db->table('company_tax_profile')->update(['ksa_mode' => 1]);

            (new VatClassification())->up();

            $this->assertSame('1', $flag());
        } finally {
            $db->table('company_tax_profile')->update(['ksa_mode' => 0]);
            $db->table('app_config')->where('key', 'ksa_vat_mode')->delete();
        }
    }

    public function testUp_WritesNoModeFlagWhileTheModeIsOff(): void
    {
        $db = Database::connect();
        $db->table('app_config')->where('key', 'ksa_vat_mode')->delete();

        (new VatClassification())->up();

        $this->assertNull($db->table('app_config')->where('key', 'ksa_vat_mode')->get()->getRow());
    }

    public function testDown_RefusesOnceALineWasSoldUnderAClass(): void
    {
        Database::connect()->table('sales_items_vat')->insert(['sale_id' => 1, 'item_id' => 1, 'line' => 1, 'category' => 'S', 'percent' => '15.000']);

        $this->expectException(RuntimeException::class);

        (new VatClassification())->down();
    }

    public function testDown_DropsTheTables(): void
    {
        (new VatClassification())->down();

        $this->assertFalse($this->hasTable('vat_classes'));
        $this->assertFalse($this->hasTable('sales_items_vat'));
    }
}
