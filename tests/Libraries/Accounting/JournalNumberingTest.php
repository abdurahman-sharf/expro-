<?php

namespace Tests\Libraries\Accounting;

use App\Libraries\Accounting\JournalNumbering;
use App\Libraries\Accounting\PostingError;
use App\Libraries\Accounting\PostingException;
use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\DatabaseTestTrait;
use Config\Database;
use Tests\Support\AccountingFixtureTrait;

class JournalNumberingTest extends CIUnitTestCase
{
    use DatabaseTestTrait;
    use AccountingFixtureTrait;

    protected $migrate     = true;
    protected $migrateOnce = true;
    protected $refresh     = false;
    protected $namespace   = null;

    public static function setUpBeforeClass(): void
    {
        $seeder = Database::seeder('tests');
        $seeder->call('TestDatabaseBootstrapSeeder');
    }

    protected function setUp(): void
    {
        parent::setUp();

        $this->clearJournals();
    }

    public function testNextManual_ReturnsSixDigitNumbersInSequence(): void
    {
        $numbering = new JournalNumbering($this->accountingDb());

        $this->assertSame('000001', $numbering->nextManual());
        $this->assertSame('000002', $numbering->nextManual());
    }

    public function testNextManual_ContinuesFromTheStoredLastNumber(): void
    {
        $this->accountingDb()->table('accounting_sequences')->where('series', 'manual')->update(['last_number' => 41]);

        $this->assertSame('000042', (new JournalNumbering($this->accountingDb()))->nextManual());
    }

    public function testNextManual_RejectsAMissingSeries(): void
    {
        $this->accountingDb()->table('accounting_sequences')->where('series', 'manual')->delete();

        try {
            (new JournalNumbering($this->accountingDb()))->nextManual();
            $this->fail('A missing series should have been refused.');
        } catch (PostingException $exception) {
            $this->assertSame(PostingError::WriteFailed, $exception->error);
        } finally {
            $this->accountingDb()->table('accounting_sequences')->insert(['series' => 'manual', 'last_number' => 0]);
        }
    }

    public function testNextManual_KeepsASeparateSequenceForEachCompany(): void
    {
        $db = $this->accountingDb();
        $db->table('companies')->insert(['company_id' => 2, 'name' => 'Second company']);
        $db->table('accounting_sequences')->insert(['company_id' => 2, 'series' => 'manual', 'last_number' => 0]);

        try {
            $numbering = new JournalNumbering($db);

            $this->assertSame('000001', $numbering->nextManual(1));
            $this->assertSame('000001', $numbering->nextManual(2));
            $this->assertSame('000002', $numbering->nextManual(1));
        } finally {
            $db->table('accounting_sequences')->where('company_id', 2)->delete();
            $db->table('companies')->where('company_id', 2)->delete();
        }
    }

    public function testNextManual_RejectsACompanyWithoutASeries(): void
    {
        $this->expectException(PostingException::class);

        (new JournalNumbering($this->accountingDb()))->nextManual(987654);
    }
}
