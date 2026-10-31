<?php

namespace Tests\Database;

use App\Database\Migrations\AppConfigValueText;
use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\DatabaseTestTrait;
use Config\Database;
use Tests\Support\AccountingFixtureTrait;

class AppConfigValueTextMigrationTest extends CIUnitTestCase
{
    use DatabaseTestTrait;
    use AccountingFixtureTrait;

    private const KEY = 'wp31_migration_probe';

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
        $this->accountingDb()->table('app_config')->where('key', self::KEY)->delete();

        (new AppConfigValueText())->up();

        parent::tearDown();
    }

    private function type(): string
    {
        return strtolower((string) $this->accountingDb()->query(
            'SELECT DATA_TYPE FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ?',
            [$this->accountingDb()->prefixTable('app_config'), 'value'],
        )->getRow()->DATA_TYPE);
    }

    public function testUp_MakesTheValueOfASettingTextSoAListFitsInIt(): void
    {
        $this->assertSame('text', $this->type());

        $list = str_repeat('x', 3000);

        $this->accountingDb()->table('app_config')->insert(['key' => self::KEY, 'value' => $list]);

        $this->assertSame($list, $this->accountingDb()->table('app_config')->where('key', self::KEY)->get()->getRow()->value);
    }

    public function testUp_IsSafeToRunAgain(): void
    {
        (new AppConfigValueText())->up();
        (new AppConfigValueText())->up();

        $this->assertSame('text', $this->type());
    }

    public function testUp_LeavesTheSettingsThatWereThereAsTheyWere(): void
    {
        $db = $this->accountingDb();

        $before = $db->table('app_config')->orderBy('key', 'ASC')->get()->getResultArray();

        (new AppConfigValueText())->up();

        $this->assertSame($before, $db->table('app_config')->orderBy('key', 'ASC')->get()->getResultArray());
        $this->assertNotSame([], $before);
    }

    public function testDown_KeepsTheColumnAsItIsSoNoLongValueIsCut(): void
    {
        (new AppConfigValueText())->down();

        $this->assertSame('text', $this->type());
    }
}
