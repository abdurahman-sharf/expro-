<?php

namespace Tests\Models;

use App\Models\Tax_category;
use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\DatabaseTestTrait;
use Config\Database;
use Tests\Support\TransactionStateTrait;

/**
 * @internal
 */
final class Tax_categoryTest extends CIUnitTestCase
{
    use DatabaseTestTrait;
    use TransactionStateTrait;

    protected $migrate     = true;
    protected $migrateOnce = true;
    protected $refresh     = false;
    protected $namespace   = null;

    private Tax_category $categories;

    /** @var list<int|string> */
    private array $kept = [];

    public static function setUpBeforeClass(): void
    {
        $seeder = Database::seeder('tests');
        $seeder->call('TestDatabaseBootstrapSeeder');
    }

    protected function setUp(): void
    {
        parent::setUp();

        $this->kept       = array_column(Database::connect()->table('tax_categories')->select('tax_category_id')->where('deleted', 0)->get()->getResultArray(), 'tax_category_id');
        $this->categories = model(Tax_category::class);
    }

    protected function tearDown(): void
    {
        $this->closeOpenTransaction();
        Database::connect()->table('tax_categories')->like('tax_category', 'T13', 'after')->delete();

        if ($this->kept !== []) {
            Database::connect()->table('tax_categories')->whereIn('tax_category_id', $this->kept)->update(['deleted' => 0]);
        }

        parent::tearDown();
    }

    public function testSaveCategories_SavesANewRow(): void
    {
        $name = 'T13' . substr(uniqid(), -8);

        $this->assertTrue($this->categories->save_categories([['tax_category_id' => NEW_ENTRY, 'tax_category' => $name, 'tax_group_sequence' => 1]]));
        $this->assertNoTransactionIsOpen();
        $this->assertSame(1, Database::connect()->table('tax_categories')->where(['tax_category' => $name, 'deleted' => 0])->countAllResults());
    }

    public function testSaveCategories_SoftDeletesTheRowsAbsentFromThePost(): void
    {
        $absent = 'T13' . substr(uniqid(), -8);

        $this->assertTrue($this->categories->save_categories([['tax_category_id' => NEW_ENTRY, 'tax_category' => $absent, 'tax_group_sequence' => 1]]));

        $absentId = (int) Database::connect()->table('tax_categories')->select('tax_category_id')->where('tax_category', $absent)->get()->getRow()->tax_category_id;

        $this->assertTrue($this->categories->save_categories([['tax_category_id' => NEW_ENTRY, 'tax_category' => 'T13' . substr(uniqid(), -8), 'tax_group_sequence' => 1]]));
        $this->assertSame(1, (int) Database::connect()->table('tax_categories')->select('deleted')->where('tax_category_id', $absentId)->get()->getRow()->deleted);
    }

    public function testSaveCategories_AnswersFalseAndLeavesNoTransactionWhenANewRowCannotBeSaved(): void
    {
        $name = 'T13' . substr(uniqid(), -8);

        $this->assertFalse($this->categories->save_categories([['tax_category_id' => NEW_ENTRY, 'tax_category' => $name, 'tax_group_sequence' => null]]));
        $this->assertNoTransactionIsOpen();
    }
}
