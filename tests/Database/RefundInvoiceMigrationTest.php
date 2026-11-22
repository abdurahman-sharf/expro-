<?php

namespace Tests\Database;

use App\Database\Migrations\RefundInvoice;
use App\Libraries\Accounting\ReturnDocument;
use CodeIgniter\Database\Exceptions\DatabaseException;
use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\DatabaseTestTrait;
use Config\Database;
use Tests\Support\AccountingFixtureTrait;

class RefundInvoiceMigrationTest extends CIUnitTestCase
{
    use DatabaseTestTrait;
    use AccountingFixtureTrait;

    protected $migrate     = true;
    protected $migrateOnce = true;
    protected $refresh     = false;
    protected $namespace   = null;

    /** @var list<int> */
    private array $saleIds = [];

    public static function setUpBeforeClass(): void
    {
        $seeder = Database::seeder('tests');
        $seeder->call('TestDatabaseBootstrapSeeder');
    }

    protected function setUp(): void
    {
        parent::setUp();

        $this->releaseOpenTransactions();
    }

    protected function tearDown(): void
    {
        $this->releaseOpenTransactions();

        foreach ($this->saleIds as $saleId) {
            $this->accountingDb()->table('sales')->where('sale_id', $saleId)->delete();
        }

        $this->saleIds = [];

        (new RefundInvoice())->up();

        parent::tearDown();
    }

    private function hasColumn(): bool
    {
        return $this->accountingDb()->query(
            'SELECT COUNT(*) AS found FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ?',
            [$this->accountingDb()->prefixTable('sales'), 'return_document'],
        )->getRow()->found > 0;
    }

    private function insertReturn(?string $document = null): int
    {
        $row = ['employee_id' => 1, 'comment' => 'migration', 'sale_status' => 0, 'sale_type' => SALE_TYPE_RETURN];

        if ($document !== null) {
            $row['return_document'] = $document;
        }

        $this->accountingDb()->table('sales')->insert($row);

        return $this->saleIds[] = (int) $this->accountingDb()->insertID();
    }

    public function testUp_AddsTheColumnEmptySoEveryReturnThatExistsStaysACreditNote(): void
    {
        (new RefundInvoice())->down();

        $saleId = $this->insertReturn();
        $before = $this->accountingDb()->table('sales')->where('sale_id', $saleId)->get()->getRowArray();

        (new RefundInvoice())->up();

        $after = $this->accountingDb()->table('sales')->where('sale_id', $saleId)->get()->getRowArray();

        $this->assertTrue($this->hasColumn());
        $this->assertNull($after['return_document']);
        unset($after['return_document']);
        $this->assertSame($before, $after);
        $this->assertSame(ReturnDocument::CreditNote, ReturnDocument::fromStored(null));
    }

    public function testUp_RunsTwiceWithoutLosingARefundInvoice(): void
    {
        $saleId = $this->insertReturn('refund_invoice');

        (new RefundInvoice())->up();
        (new RefundInvoice())->up();

        $this->assertTrue($this->hasColumn());
        $this->assertSame('refund_invoice', $this->accountingDb()->table('sales')->where('sale_id', $saleId)->get()->getRow()->return_document);
    }

    public function testUp_RefusesAValueThatIsNeitherEmptyNorARefundInvoice(): void
    {
        if ($this->accountingDb()->query("SELECT 1 FROM information_schema.TABLES WHERE TABLE_SCHEMA = 'information_schema' AND TABLE_NAME = 'CHECK_CONSTRAINTS'")->getRow() === null) {
            $this->markTestSkipped('This database keeps no check (MySQL 5.7): the code alone holds the value.');
        }

        try {
            $this->insertReturn('credit_note');
            $this->fail('A stored credit note was accepted: a credit note is the empty column.');
        } catch (DatabaseException) {
            $this->addToAssertionCount(1);
        }

        try {
            $this->insertReturn('anything');
            $this->fail('An unknown document was accepted.');
        } catch (DatabaseException) {
            $this->addToAssertionCount(1);
        }
    }

    public function testDown_RemovesTheColumnAndUpBringsItBack(): void
    {
        (new RefundInvoice())->down();

        $this->assertFalse($this->hasColumn());

        (new RefundInvoice())->down();

        $this->assertFalse($this->hasColumn());

        (new RefundInvoice())->up();

        $this->assertTrue($this->hasColumn());
    }

    public function testUp_AddsNoPermissionNoGrantAndNoModule(): void
    {
        $permissions = $this->countRows('permissions');
        $grants      = $this->countRows('grants');
        $modules     = $this->countRows('modules');

        (new RefundInvoice())->up();

        $this->assertSame($permissions, $this->countRows('permissions'));
        $this->assertSame($grants, $this->countRows('grants'));
        $this->assertSame($modules, $this->countRows('modules'));
    }
}
