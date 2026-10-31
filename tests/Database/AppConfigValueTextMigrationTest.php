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
        return strtolower((string) $this->column()->DATA_TYPE);
    }

    private function column(): object
    {
        return $this->accountingDb()->query(
            'SELECT DATA_TYPE, CHARACTER_SET_NAME FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ?',
            [$this->accountingDb()->prefixTable('app_config'), 'value'],
        )->getRow();
    }

    private function narrowToWhatItWasBeforeTheMigration(): void
    {
        $this->accountingDb()->query('ALTER TABLE `' . $this->accountingDb()->prefixTable('app_config') . '` MODIFY COLUMN `value` VARCHAR(500) NOT NULL');

        $this->assertSame('varchar', $this->type(), 'The state before the migration');
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function settings(): array
    {
        return $this->accountingDb()->table('app_config')->orderBy('key', 'ASC')->get()->getResultArray();
    }

    public function testUp_MakesTheValueOfASettingTextSoAListFitsInIt(): void
    {
        $this->narrowToWhatItWasBeforeTheMigration();

        (new AppConfigValueText())->up();

        $this->assertSame('text', $this->type());

        $list = str_repeat('x', 3000);

        $this->accountingDb()->table('app_config')->insert(['key' => self::KEY, 'value' => $list]);

        $this->assertSame($list, $this->accountingDb()->table('app_config')->where('key', self::KEY)->get()->getRow()->value);
    }

    public function testUp_IsSafeToRunAgain(): void
    {
        $this->narrowToWhatItWasBeforeTheMigration();

        (new AppConfigValueText())->up();
        (new AppConfigValueText())->up();

        $this->assertSame('text', $this->type());
    }

    public function testUp_LeavesTheSettingsThatWereThereAsTheyWereAndTheCharacterSetOfTheColumn(): void
    {
        $this->narrowToWhatItWasBeforeTheMigration();

        $before  = $this->settings();
        $charset = $this->column()->CHARACTER_SET_NAME;

        (new AppConfigValueText())->up();

        $this->assertSame($before, $this->settings());
        $this->assertNotSame([], $before);
        $this->assertSame($charset, $this->column()->CHARACTER_SET_NAME, 'The words of a setting keep the character set they were kept in');
    }

    public function testUp_LeavesAColumnThatIsAlreadyTextAsItIs(): void
    {
        (new AppConfigValueText())->up();

        $before = $this->settings();

        (new AppConfigValueText())->up();

        $this->assertSame('text', $this->type());
        $this->assertSame($before, $this->settings());
    }

    public function testDown_KeepsTheColumnAsItIsSoNoLongValueIsCut(): void
    {
        (new AppConfigValueText())->up();
        (new AppConfigValueText())->down();

        $this->assertSame('text', $this->type());
    }
}
