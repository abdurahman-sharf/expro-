<?php

namespace Tests\Database;

use App\Database\Migrations\DocumentSequences;
use App\Libraries\Numbering\DocumentNumbering;
use CodeIgniter\Database\Exceptions\DatabaseException;
use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\DatabaseTestTrait;
use Config\Database;
use Tests\Support\AccountingFixtureTrait;

class DocumentSequencesMigrationTest extends CIUnitTestCase
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

        $migration = new DocumentSequences();
        $migration->down();
        $migration->up();
    }

    protected function tearDown(): void
    {
        $this->releaseOpenTransactions();

        foreach ($this->saleIds as $saleId) {
            $this->accountingDb()->table('sales')->where('sale_id', $saleId)->delete();
        }

        $this->saleIds = [];

        (new DocumentSequences())->up();
        $this->clearTables();

        parent::tearDown();
    }

    private function hasTable(string $table): bool
    {
        return $this->accountingDb()->query(
            'SELECT COUNT(*) AS found FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ?',
            [$this->accountingDb()->prefixTable($table)],
        )->getRow()->found > 0;
    }

    /**
     * @return list<string>
     */
    private function columnsOf(string $table): array
    {
        return array_column($this->accountingDb()->query(
            'SELECT COLUMN_NAME FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? ORDER BY ORDINAL_POSITION',
            [$this->accountingDb()->prefixTable($table)],
        )->getResultArray(), 'COLUMN_NAME');
    }

    private function clearTables(): void
    {
        foreach (['document_numbers', 'document_sequences'] as $table) {
            if ($this->hasTable($table)) {
                $this->accountingDb()->table($table)->truncate();
            }
        }
    }

    private function sequenceRow(array $values = []): array
    {
        return $values + ['company_id' => 1, 'doc_type' => 'sales_invoice', 'enabled' => 1, 'prefix' => 'INV-', 'padding' => 4, 'next_number' => 1];
    }

    private function numberRow(array $values = []): array
    {
        return $values + ['company_id' => 1, 'doc_type' => 'sales_invoice', 'source_table' => 'sales', 'source_id' => 900001, 'sequence_number' => 1, 'number' => 'INV-0001'];
    }

    private function assertRefused(callable $action, string $why): void
    {
        try {
            $action();
            $this->fail($why);
        } catch (DatabaseException) {
            $this->addToAssertionCount(1);
        }
    }

    public function testUp_CreatesTheSequencesAndTheRegisterOfNumbers(): void
    {
        $this->assertTrue($this->hasTable('document_sequences'));
        $this->assertTrue($this->hasTable('document_numbers'));
        $this->assertSame(
            ['company_id', 'doc_type', 'enabled', 'prefix', 'padding', 'next_number', 'prevent_duplicates', 'updated_by', 'updated_at'],
            $this->columnsOf('document_sequences'),
        );
        $this->assertSame(
            ['number_id', 'company_id', 'doc_type', 'source_table', 'source_id', 'sequence_number', 'number', 'created_at'],
            $this->columnsOf('document_numbers'),
        );
    }

    public function testUp_StartsWithNoSequenceAndNoNumberSoEveryKindOfDocumentIsSwitchedOff(): void
    {
        $this->assertSame(0, $this->countRows('document_sequences'));
        $this->assertSame(0, $this->countRows('document_numbers'));

        foreach ((new DocumentNumbering($this->accountingDb()))->overview() as $sequence) {
            $this->assertFalse($sequence->enabled, $sequence->type->value);
            $this->assertSame('', $sequence->prefix, $sequence->type->value);
        }
    }

    public function testUp_RunsTwiceWithoutLosingASequenceOrANumber(): void
    {
        $db = $this->accountingDb();
        $db->table('document_sequences')->insert($this->sequenceRow(['next_number' => 8]));
        $db->table('document_numbers')->insert($this->numberRow());

        (new DocumentSequences())->up();
        (new DocumentSequences())->up();

        $this->assertSame(1, $this->countRows('document_sequences'));
        $this->assertSame(8, (int) $db->table('document_sequences')->get()->getRow()->next_number);
        $this->assertSame('INV-0001', $db->table('document_numbers')->get()->getRow()->number);
    }

    public function testUp_LeavesTheNumbersOfTheDocumentsThatExistAsTheyAre(): void
    {
        $db = $this->accountingDb();
        $db->table('sales')->insert(['employee_id' => 1, 'comment' => 'migration', 'invoice_number' => 'MIG' . random_int(100000, 999999), 'sale_status' => 0, 'sale_type' => 1]);
        $saleId          = (int) $db->insertID();
        $this->saleIds[] = $saleId;
        $before          = $db->table('sales')->where('sale_id', $saleId)->get()->getRowArray();

        (new DocumentSequences())->up();

        $this->assertSame($before, $db->table('sales')->where('sale_id', $saleId)->get()->getRowArray());
        $this->assertSame(0, $this->countRows('document_numbers'));
    }

    public function testUp_AddsNoPermissionAndNoGrant(): void
    {
        $permissions = $this->countRows('permissions');
        $grants      = $this->countRows('grants');
        $modules     = $this->countRows('modules');

        (new DocumentSequences())->up();

        $this->assertSame($permissions, $this->countRows('permissions'));
        $this->assertSame($grants, $this->countRows('grants'));
        $this->assertSame($modules, $this->countRows('modules'));
    }

    public function testUp_RefusesTwoSequencesOfTheSameKindForOneCompany(): void
    {
        $this->accountingDb()->table('document_sequences')->insert($this->sequenceRow());

        $this->assertRefused(fn () => $this->accountingDb()->table('document_sequences')->insert($this->sequenceRow(['prefix' => 'OTHER-'])), 'A second sequence of the same kind was accepted.');
        $this->assertSame(1, $this->countRows('document_sequences'));
    }

    public function testUp_RefusesAPaddingAboveTenAndANextNumberBelowOne(): void
    {
        $this->assertRefused(fn () => $this->accountingDb()->table('document_sequences')->insert($this->sequenceRow(['padding' => 11])), 'A padding of 11 was accepted.');
        $this->assertRefused(fn () => $this->accountingDb()->table('document_sequences')->insert($this->sequenceRow(['next_number' => 0])), 'A next number of 0 was accepted.');

        $this->accountingDb()->table('document_sequences')->insert($this->sequenceRow(['padding' => 10]));

        $this->assertSame(1, $this->countRows('document_sequences'));
    }

    public function testUp_RefusesASequenceOfACompanyThatDoesNotExist(): void
    {
        $this->assertRefused(fn () => $this->accountingDb()->table('document_sequences')->insert($this->sequenceRow(['company_id' => 987654])), 'A sequence of an unknown company was accepted.');
        $this->assertRefused(fn () => $this->accountingDb()->table('document_numbers')->insert($this->numberRow(['company_id' => 987654])), 'A number of an unknown company was accepted.');
    }

    public function testUp_RefusesTheSameNumberTwiceForOneKindAndTwoNumbersForOneDocument(): void
    {
        $this->accountingDb()->table('document_numbers')->insert($this->numberRow());

        $this->assertRefused(fn () => $this->accountingDb()->table('document_numbers')->insert($this->numberRow(['source_id' => 900002, 'sequence_number' => 2])), 'The same number was given to two documents.');
        $this->assertRefused(fn () => $this->accountingDb()->table('document_numbers')->insert($this->numberRow(['number' => 'INV-0002', 'sequence_number' => 2])), 'A document got two numbers.');
        $this->assertSame(1, $this->countRows('document_numbers'));
    }

    public function testUp_AcceptsTheSameNumberForTwoKindsOfDocument(): void
    {
        $db = $this->accountingDb();
        $db->table('document_numbers')->insert($this->numberRow());
        $db->table('document_numbers')->insert($this->numberRow(['doc_type' => 'sales_quote', 'source_id' => 900002]));

        $this->assertSame(2, $this->countRows('document_numbers'));
    }

    public function testDown_DropsBothTablesAndUpBringsThemBackEmpty(): void
    {
        $this->accountingDb()->table('document_sequences')->insert($this->sequenceRow());

        $migration = new DocumentSequences();
        $migration->down();

        $this->assertFalse($this->hasTable('document_sequences'));
        $this->assertFalse($this->hasTable('document_numbers'));

        $migration->up();

        $this->assertTrue($this->hasTable('document_sequences'));
        $this->assertTrue($this->hasTable('document_numbers'));
        $this->assertSame(0, $this->countRows('document_sequences'));
    }

    public function testDown_RunsTwiceWithoutFailing(): void
    {
        $migration = new DocumentSequences();
        $migration->down();
        $migration->down();

        $this->assertFalse($this->hasTable('document_sequences'));
        $this->assertFalse($this->hasTable('document_numbers'));
    }
}
