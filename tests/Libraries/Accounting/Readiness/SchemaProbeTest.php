<?php

namespace Tests\Libraries\Accounting\Readiness;

use App\Libraries\Accounting\Readiness\SchemaProbe;
use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\DatabaseTestTrait;
use Config\Database;

class SchemaProbeTest extends CIUnitTestCase
{
    use DatabaseTestTrait;

    protected $migrate     = true;
    protected $migrateOnce = true;
    protected $refresh     = false;
    protected $namespace   = null;

    private SchemaProbe $probe;

    public static function setUpBeforeClass(): void
    {
        $seeder = Database::seeder('tests');
        $seeder->call('TestDatabaseBootstrapSeeder');
    }

    protected function setUp(): void
    {
        parent::setUp();

        $this->probe = new SchemaProbe(Database::connect());
    }

    public function testHasTable_IsTrueForATableOfTheDatabase(): void
    {
        $this->assertTrue($this->probe->hasTable('accounting_journals'));
    }

    public function testHasTable_IsFalseForATableThatDoesNotExist(): void
    {
        $this->assertFalse($this->probe->hasTable('accounting_no_such_table'));
    }

    public function testHasColumn_IsTrueForAColumnOfATable(): void
    {
        $this->assertTrue($this->probe->hasColumn('treasuries', 'bank_name'));
    }

    public function testHasColumn_IsFalseForAColumnThatDoesNotExist(): void
    {
        $this->assertFalse($this->probe->hasColumn('treasuries', 'no_such_column'));
    }

    public function testHasColumn_IsFalseWhenTheTableDoesNotExist(): void
    {
        $this->assertFalse($this->probe->hasColumn('accounting_no_such_table', 'journal_id'));
    }

    public function testMissing_ListsOnlyWhatIsNotThere(): void
    {
        $missing = $this->probe->missing(['accounting_journals', 'treasuries.bank_name', 'accounting_no_such_table', 'treasuries.no_such_column']);

        $this->assertSame(['accounting_no_such_table', 'treasuries.no_such_column'], $missing);
    }

    public function testMissing_IsEmptyWhenEverythingIsThere(): void
    {
        $this->assertSame([], $this->probe->missing(['accounting_journals', 'accounting_journals.journal_id']));
    }

    public function testMissing_IsEmptyForNothingToNeed(): void
    {
        $this->assertSame([], $this->probe->missing([]));
    }
}
