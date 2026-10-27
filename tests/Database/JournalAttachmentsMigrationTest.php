<?php

namespace Tests\Database;

use App\Database\Migrations\JournalAttachments as JournalAttachmentsMigration;
use App\Libraries\Accounting\JournalService;
use CodeIgniter\Database\Exceptions\DatabaseException;
use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\DatabaseTestTrait;
use Config\Database;
use Tests\Support\AccountingFixtureTrait;

class JournalAttachmentsMigrationTest extends CIUnitTestCase
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

        $this->reinstallChart();
        $this->setConfigValue('accounting_base_currency', 'SAR');

        $migration = new JournalAttachmentsMigration();
        $migration->down();
        $migration->up();
    }

    protected function tearDown(): void
    {
        $this->releaseOpenTransactions();
        $this->clearJournals();

        parent::tearDown();
    }

    /**
     * @return array<string, mixed>
     */
    private function row(int $journalId, string $storedName = 'a1a1a1a1a1a1a1a1a1a1a1a1a1a1a1a1', int $size = 10): array
    {
        return [
            'journal_id'    => $journalId,
            'original_name' => 'receipt.pdf',
            'stored_name'   => $storedName,
            'mime_type'     => 'application/pdf',
            'size_bytes'    => $size,
            'sha256'        => str_repeat('a', 64),
            'uploaded_by'   => null,
        ];
    }

    public function testUp_CreatesTheTableWithTheColumnsTheBooksNeed(): void
    {
        $columns = array_column($this->accountingDb()->query('SHOW COLUMNS FROM ' . $this->accountingDb()->prefixTable('accounting_journal_attachments'))->getResultArray(), null, 'Field');

        $this->assertSame(
            ['attachment_id', 'journal_id', 'original_name', 'stored_name', 'mime_type', 'size_bytes', 'sha256', 'uploaded_by', 'uploaded_at'],
            array_keys($columns),
        );
        $this->assertSame('NO', $columns['journal_id']['Null']);
        $this->assertSame('NO', $columns['stored_name']['Null']);
        $this->assertSame('YES', $columns['uploaded_by']['Null']);
    }

    public function testUp_StoresARowForAJournal(): void
    {
        $journalId = (new JournalService())->postNew($this->journalInput());

        $this->accountingDb()->table('accounting_journal_attachments')->insert($this->row($journalId));

        $this->assertSame(1, $this->countRows('accounting_journal_attachments'));
    }

    public function testUp_RejectsARowForAJournalThatDoesNotExist(): void
    {
        $this->expectException(DatabaseException::class);

        $this->accountingDb()->table('accounting_journal_attachments')->insert($this->row(999999));
    }

    public function testUp_RejectsToDeleteAJournalThatStillHoldsAFile(): void
    {
        $journalId = (new JournalService())->saveDraft($this->journalInput());
        $this->accountingDb()->table('accounting_journal_attachments')->insert($this->row($journalId));
        $this->accountingDb()->table('accounting_journal_lines')->where('journal_id', $journalId)->delete();

        try {
            $this->accountingDb()->table('accounting_journals')->where('journal_id', $journalId)->delete();
            $this->fail('A journal that holds a file must not be deleted.');
        } catch (DatabaseException) {
            $this->assertSame(1, $this->accountingDb()->table('accounting_journals')->where('journal_id', $journalId)->countAllResults());
        }
    }

    public function testUp_RejectsTwoRowsForOneStoredFile(): void
    {
        $journalId = (new JournalService())->postNew($this->journalInput());
        $this->accountingDb()->table('accounting_journal_attachments')->insert($this->row($journalId));

        $this->expectException(DatabaseException::class);

        $this->accountingDb()->table('accounting_journal_attachments')->insert($this->row($journalId));
    }

    public function testUp_RejectsAFileOfNoBytes(): void
    {
        $journalId = (new JournalService())->postNew($this->journalInput());

        $this->expectException(DatabaseException::class);

        $this->accountingDb()->table('accounting_journal_attachments')->insert($this->row($journalId, 'b2b2b2b2b2b2b2b2b2b2b2b2b2b2b2b2', 0));
    }

    public function testUp_IsSafeToRunTwiceAndKeepsTheRows(): void
    {
        $journalId = (new JournalService())->postNew($this->journalInput());
        $this->accountingDb()->table('accounting_journal_attachments')->insert($this->row($journalId));

        (new JournalAttachmentsMigration())->up();

        $this->assertSame(1, $this->countRows('accounting_journal_attachments'));
    }

    public function testDown_DropsTheTableAndUpBringsItBack(): void
    {
        $migration = new JournalAttachmentsMigration();

        try {
            $migration->down();

            $this->assertFalse($this->accountingDb()->tableExists($this->accountingDb()->prefixTable('accounting_journal_attachments'), false));
        } finally {
            $migration->up();
        }

        $this->assertTrue($this->accountingDb()->tableExists($this->accountingDb()->prefixTable('accounting_journal_attachments'), false));
        $this->assertSame(0, $this->countRows('accounting_journal_attachments'));
    }
}
