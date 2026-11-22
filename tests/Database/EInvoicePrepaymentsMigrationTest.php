<?php

namespace Tests\Database;

use App\Database\Migrations\EInvoicePrepayments;
use CodeIgniter\Database\Exceptions\DatabaseException;
use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\DatabaseTestTrait;
use Config\Database;

class EInvoicePrepaymentsMigrationTest extends CIUnitTestCase
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
        Database::connect()->table('einvoice_documents')->where('source_type', 'customer_advance')->delete();
        (new EInvoicePrepayments())->up();

        parent::tearDown();
    }

    private function prepaymentDocument(): void
    {
        Database::connect()->table('einvoice_documents')->insert([
            'source_type'     => 'customer_advance',
            'source_id'       => 990001,
            'doc_kind'        => 'prepayment',
            'subtype'         => 'simplified',
            'document_number' => 'ADV-1',
            'uuid'            => '00000000-0000-4000-8000-000000990001',
            'icv'             => 990001,
            'previous_hash'   => 'x',
            'invoice_hash'    => 'y',
            'issue_date'      => '2026-11-21',
            'issue_time'      => '10:00:00',
            'tax_exclusive'   => '100.00',
            'tax_amount'      => '15.00',
            'tax_inclusive'   => '115.00',
            'xml'             => '<Invoice/>',
        ]);
    }

    public function testUp_LetsTheDocumentsHoldThePrepaymentInvoiceOfAnAdvance(): void
    {
        $this->prepaymentDocument();

        $this->assertSame(1, Database::connect()->table('einvoice_documents')->where('source_type', 'customer_advance')->countAllResults());
    }

    public function testDown_NarrowsTheChecksAgain(): void
    {
        (new EInvoicePrepayments())->down();

        $this->expectException(DatabaseException::class);

        $this->prepaymentDocument();
    }
}
