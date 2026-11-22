<?php

namespace Tests\Database;

use App\Database\Migrations\AdvanceVatReturns;
use App\Database\Migrations\EInvoiceReissue;
use CodeIgniter\Database\Exceptions\DatabaseException;
use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\DatabaseTestTrait;
use Config\Database;
use RuntimeException;

class AdvanceVatReturnsMigrationTest extends CIUnitTestCase
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

    protected function tearDown(): void
    {
        $db = Database::connect();

        $db->table('einvoice_documents')->where('source_type', 'advance_return')->delete();

        if ($db->tableExists($db->prefixTable('customer_advance_vat_returns'), false)) {
            $db->table('customer_advance_vat_returns')->truncate();
        }

        (new AdvanceVatReturns())->up();
        (new EInvoiceReissue())->up();

        parent::tearDown();
    }

    private function returnNote(): void
    {
        Database::connect()->table('einvoice_documents')->insert([
            'source_type'          => 'advance_return',
            'source_id'            => 990003,
            'doc_kind'             => 'invoice',
            'subtype'              => 'simplified',
            'document_number'      => 'ADV-CN 990003',
            'uuid'                 => '00000000-0000-4000-8000-000000990003',
            'icv'                  => 990003,
            'previous_hash'        => 'x',
            'invoice_hash'         => 'y',
            'issue_date'           => '2026-11-21',
            'issue_time'           => '10:00:00',
            'tax_exclusive'        => '100.00',
            'tax_amount'           => '15.00',
            'tax_inclusive'        => '115.00',
            'xml'                  => '<Invoice/>',
        ]);
    }

    public function testUp_CreatesTheReturnsAndLetsTheDocumentsHoldTheirCreditNotes(): void
    {
        (new AdvanceVatReturns())->up();
        $this->returnNote();

        $db = Database::connect();

        $this->assertTrue($db->tableExists($db->prefixTable('customer_advance_vat_returns'), false));
        $this->assertSame(1, $db->table('einvoice_documents')->where('source_type', 'advance_return')->countAllResults());
    }

    public function testDown_RefusesOnceSomethingWentBack(): void
    {
        Database::connect()->table('customer_advance_vat_returns')->insert(['advance_id' => 1, 'gross' => '46.00', 'taxable' => '40.00', 'vat' => '6.00', 'created_at' => '2026-11-21 10:00:00']);

        $this->expectException(RuntimeException::class);

        (new AdvanceVatReturns())->down();
    }

    public function testDown_NarrowsTheChecksAgainAndDropsTheReturns(): void
    {
        (new AdvanceVatReturns())->down();

        $this->assertFalse(Database::connect()->tableExists(Database::connect()->prefixTable('customer_advance_vat_returns'), false));

        $this->expectException(DatabaseException::class);

        $this->returnNote();
    }
}
