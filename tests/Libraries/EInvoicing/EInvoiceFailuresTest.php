<?php

namespace Tests\Libraries\EInvoicing;

use App\Libraries\Accounting\AccountingError;
use App\Libraries\Accounting\AccountingException;
use App\Libraries\EInvoicing\EInvoiceError;
use App\Libraries\EInvoicing\EInvoiceException;
use App\Libraries\EInvoicing\EInvoiceFailures;
use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\DatabaseTestTrait;
use Config\Database;
use RuntimeException;

class EInvoiceFailuresTest extends CIUnitTestCase
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
        Database::connect()->table('einvoice_failures')->truncate();

        parent::tearDown();
    }

    public function testRecord_KeepsTheReasonAndOnlyTheClassOfAFailureThatIsNotTheApplicationsOwn(): void
    {
        $failures = new EInvoiceFailures();

        $failures->record('sale', new EInvoiceException(EInvoiceError::BuyerAddressIncomplete, 'The buyer has no address.'), 3, 1, SALE_TYPE_INVOICE, str_repeat('a', 64));
        $failures->record('sale', new RuntimeException('SQLSTATE secret detail'), 3, 1, SALE_TYPE_POS, null);
        $failures->record('advance', new AccountingException(AccountingError::WriteFailed, 'Not saved.'), null, null, null, null);

        $rows = array_reverse($failures->recent());

        $this->assertSame(['buyer_address_incomplete', 'The buyer has no address.'], [$rows[0]->error, $rows[0]->message]);
        $this->assertSame(['unexpected', RuntimeException::class], [$rows[1]->error, $rows[1]->message], 'nothing the database said is copied');
        $this->assertSame('write_failed', $rows[2]->error);
    }

    public function testRecord_KeepsTheRecordWhenTheTransactionAroundItIsUndone(): void
    {
        $db = Database::connect();

        $db->transStart();
        (new EInvoiceFailures($db))->record('sale', new EInvoiceException(EInvoiceError::Disabled), 3, 1, SALE_TYPE_POS, null);
        $db->transRollback();

        $this->assertSame(1, $db->table('einvoice_failures')->countAllResults());
    }
}
