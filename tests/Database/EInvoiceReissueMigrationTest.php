<?php

namespace Tests\Database;

use App\Database\Migrations\EInvoiceReissue;
use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\DatabaseTestTrait;
use Config\Database;
use RuntimeException;
use Throwable;

class EInvoiceReissueMigrationTest extends CIUnitTestCase
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
        Database::connect()->table('einvoice_documents')->where('source_id', 990777)->delete();
        (new EInvoiceReissue())->up();

        parent::tearDown();
    }

    private function reissued(): bool
    {
        try {
            return Database::connect()->table('einvoice_documents')->insert(['source_type' => 'reissue', 'source_id' => 990777, 'doc_kind' => 'invoice', 'subtype' => 'simplified', 'document_number' => 'R-1', 'uuid' => '00000000-0000-4000-8000-000000990777', 'icv' => 990777, 'previous_hash' => 'x', 'invoice_hash' => 'h', 'issue_date' => '2026-11-22', 'issue_time' => '10:00:00', 'tax_exclusive' => '1.00', 'tax_amount' => '0.15', 'tax_inclusive' => '1.15', 'xml' => '<Invoice/>']);
        } catch (Throwable) {
            return false;
        }
    }

    public function testUp_TakesADocumentIssuedAgainAndCanRunAgain(): void
    {
        (new EInvoiceReissue())->up();
        (new EInvoiceReissue())->up();

        $this->assertTrue($this->reissued());
    }

    public function testDown_RefusesOnceADocumentWasIssuedAgain(): void
    {
        $this->reissued();

        $this->expectException(RuntimeException::class);

        (new EInvoiceReissue())->down();
    }
}
