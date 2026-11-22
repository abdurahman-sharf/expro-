<?php

namespace Tests\Database;

use App\Database\Migrations\EInvoiceIssuance;
use CodeIgniter\Database\Exceptions\DatabaseException;
use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\DatabaseTestTrait;
use Config\Database;
use RuntimeException;

class EInvoiceIssuanceMigrationTest extends CIUnitTestCase
{
    use DatabaseTestTrait;

    protected $migrate     = true;
    protected $migrateOnce = true;
    protected $refresh     = false;
    protected $namespace   = null;

    public static function setUpBeforeClass(): void
    {
        Database::seeder('tests')->call('TestDatabaseBootstrapSeeder');
    }

    private function hasColumn(string $table, string $column): bool
    {
        $db = Database::connect();

        return $db->query('SELECT 1 FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ?', [$db->prefixTable($table), $column])->getRow() !== null;
    }

    protected function tearDown(): void
    {
        $db = Database::connect();

        if ($this->hasColumn('einvoice_documents', 'original_reference')) {
            $db->table('einvoice_documents')->where('document_number', 'CN-990002')->delete();
        }

        (new EInvoiceIssuance())->up();

        parent::tearDown();
    }

    private function creditNote(?string $reference): void
    {
        Database::connect()->table('einvoice_documents')->insert([
            'source_type'     => 'sale',
            'source_id'       => 990002,
            'doc_kind'        => 'credit_note',
            'subtype'         => 'simplified',
            'document_number' => 'CN-990002',
            'uuid'            => '00000000-0000-4000-8000-000000990002',
            'icv'             => 990002,
            'previous_hash'   => 'x',
            'invoice_hash'    => 'y',
            'issue_date'      => '2026-11-21',
            'issue_time'      => '10:00:00',
            'tax_exclusive'   => '100.00',
            'tax_amount'      => '15.00',
            'tax_inclusive'   => '115.00',
            'xml'             => '<Invoice/>',
            ...($reference === null ? [] : ['original_reference' => $reference]),
        ]);
    }

    public function testUp_AddsTheStateOfADocumentAndTheRecordOfFailuresAndIsSafeToRunAgain(): void
    {
        (new EInvoiceIssuance())->up();

        $db = Database::connect();

        $this->assertTrue($this->hasColumn('einvoice_documents', 'status'));
        $this->assertTrue($this->hasColumn('einvoice_documents', 'original_sale_id'));
        $this->assertTrue($db->tableExists($db->prefixTable('einvoice_failures'), false));
    }

    public function testUp_LetsACreditNoteReferToThePrintedNumberOfASaleMadeBeforeTheSaudiRules(): void
    {
        $this->creditNote('POS 12');

        $row = Database::connect()->table('einvoice_documents')->where('document_number', 'CN-990002')->get()->getRow();

        $this->assertSame(['POS 12', 'issued'], [$row->original_reference, $row->status]);
    }

    public function testUp_RefusesACreditNoteThatRefersToNothing(): void
    {
        $this->expectException(DatabaseException::class);

        $this->creditNote(null);
    }

    public function testDown_RefusesOnceANoteRefersToAPrintedNumber(): void
    {
        $this->creditNote('POS 12');

        $this->expectException(RuntimeException::class);

        (new EInvoiceIssuance())->down();
    }

    public function testDown_RemovesWhatItAdded(): void
    {
        (new EInvoiceIssuance())->down();

        $db = Database::connect();

        $this->assertFalse($this->hasColumn('einvoice_documents', 'original_reference'));
        $this->assertFalse($db->tableExists($db->prefixTable('einvoice_failures'), false));
    }
}
