<?php

namespace Tests\Database;

use App\Database\Migrations\SalesDebitNotes;
use CodeIgniter\Database\Exceptions\DatabaseException;
use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\DatabaseTestTrait;
use Config\Database;
use Tests\Support\AccountingFixtureTrait;

class SalesDebitNotesMigrationTest extends CIUnitTestCase
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

    protected function tearDown(): void
    {
        (new SalesDebitNotes())->up();

        $db = $this->accountingDb();
        $db->query('DELETE FROM ' . $db->prefixTable('customer_credit_applications'));
        $db->query('DELETE FROM ' . $db->prefixTable('sales_debit_notes'));
        $db->query('DELETE FROM ' . $db->prefixTable('customer_openings'));
        $this->removeInsertedEntities();

        parent::tearDown();
    }

    private function hasTable(string $table): bool
    {
        return $this->accountingDb()->query('SELECT COUNT(*) AS found FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ?', [$this->accountingDb()->prefixTable($table)])->getRow()->found > 0;
    }

    private function hasConstraint(string $name): bool
    {
        return $this->accountingDb()->query('SELECT COUNT(*) AS found FROM information_schema.TABLE_CONSTRAINTS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND CONSTRAINT_NAME = ?', [$this->accountingDb()->prefixTable('customer_credit_applications'), $this->accountingDb()->prefixTable($name)])->getRow()->found > 0;
    }

    private function applicationOf(?int $invoice, ?int $opening, ?int $note, int $customerId): void
    {
        $this->accountingDb()->table('customer_credit_applications')->insert(['customer_id' => $customerId, 'invoice_sale_id' => $invoice, 'opening_id' => $opening, 'debit_note_id' => $note, 'source_type' => 'customer_advance', 'source_id' => 1, 'amount' => '1.0000', 'automatic' => 1]);
    }

    private function noteFor(int $customerId): int
    {
        $this->accountingDb()->table('sales_debit_notes')->insert(['company_id' => 1, 'branch_id' => 1, 'customer_id' => $customerId, 'note_date' => date('Y-m-d'), 'amount' => '10.0000', 'tax_percent' => '0', 'tax_amount' => '0.0000', 'total' => '10.0000']);

        return (int) $this->accountingDb()->insertID();
    }

    public function testUp_CreatesTheTableAndRegistersTheModuleAndThePermissionWithoutGrantingThem(): void
    {
        $db = $this->accountingDb();

        $this->assertTrue($this->hasTable('sales_debit_notes'));
        $this->assertSame(['accounting_debit_notes'], array_column($db->table('modules')->where('module_id', 'accounting_debit_notes')->get()->getResultArray(), 'module_id'));
        $this->assertSame(['acc_debit_notes_manage', 'accounting_debit_notes'], array_column($db->table('permissions')->whereIn('permission_id', ['acc_debit_notes_manage', 'accounting_debit_notes'])->orderBy('permission_id')->get()->getResultArray(), 'permission_id'));
        $this->assertSame('accounting_debit_notes', $db->table('permissions')->where('permission_id', 'acc_debit_notes_manage')->get()->getRow()->module_id);
        $this->assertSame(0, $db->table('grants')->whereIn('permission_id', ['acc_debit_notes_manage', 'accounting_debit_notes'])->countAllResults());
    }

    public function testUp_LetsAnApplicationPointAtExactlyOneOfAnInvoiceAnOpeningOrADebitNote(): void
    {
        $customerId = $this->insertCustomer();
        $note       = $this->noteFor($customerId);

        $this->accountingDb()->table('customer_openings')->insert(['company_id' => 1, 'branch_id' => 1, 'customer_id' => $customerId, 'revision' => 1, 'side' => 'owes_us', 'amount' => '5.0000', 'opening_date' => date('Y-m-d')]);

        $opening = (int) $this->accountingDb()->insertID();

        $this->applicationOf(null, null, $note, $customerId);
        $this->applicationOf(null, $opening, null, $customerId);
        $this->assertSame(2, $this->countRows('customer_credit_applications'), 'one target is accepted, a note or an opening');

        foreach ([[null, null, null], [null, $opening, $note]] as [$invoice, $openingTarget, $debitNote]) {
            try {
                $this->applicationOf($invoice, $openingTarget, $debitNote, $customerId);
                $this->fail('An application with ' . json_encode([$invoice, $openingTarget, $debitNote]) . ' should be refused.');
            } catch (DatabaseException) {
                $this->assertSame(2, $this->countRows('customer_credit_applications'));
            }
        }
    }

    public function testUp_KeepsTheRowsThatExistAndRunsAgainWithoutHarm(): void
    {
        $customerId = $this->insertCustomer();
        $note       = $this->noteFor($customerId);

        $this->applicationOf(null, null, $note, $customerId);

        (new SalesDebitNotes())->up();
        (new SalesDebitNotes())->up();

        $this->assertSame(1, $this->countRows('sales_debit_notes'));
        $this->assertSame(1, $this->countRows('customer_credit_applications'));
        $this->assertTrue($this->hasConstraint('customer_credit_applications_target'));
        $this->assertEquals(1, $this->accountingDb()->query('SELECT COUNT(*) AS found FROM information_schema.CHECK_CONSTRAINTS AS cc INNER JOIN information_schema.TABLE_CONSTRAINTS AS tc ON tc.CONSTRAINT_SCHEMA = cc.CONSTRAINT_SCHEMA AND tc.CONSTRAINT_NAME = cc.CONSTRAINT_NAME AND tc.CONSTRAINT_TYPE = \'CHECK\' WHERE cc.CONSTRAINT_SCHEMA = DATABASE() AND tc.TABLE_NAME = ? AND cc.CONSTRAINT_NAME = ? AND cc.CHECK_CLAUSE LIKE ?', [$this->accountingDb()->prefixTable('customer_credit_applications'), $this->accountingDb()->prefixTable('customer_credit_applications_target'), '%debit_note_id%'])->getRow()->found, 'the one rule names the three targets');
    }

    public function testDown_RunsTwiceWithoutHarmAfterTheOpeningColumnIsGone(): void
    {
        (new SalesDebitNotes())->down();
        (new SalesDebitNotes())->down();

        $this->assertFalse($this->hasTable('sales_debit_notes'));
    }

    public function testDown_DropsTheTableAndTheColumnAndRestoresTheRuleOfTwoTargets(): void
    {
        $customerId = $this->insertCustomer();
        $note       = $this->noteFor($customerId);

        $this->accountingDb()->table('customer_openings')->insert(['company_id' => 1, 'branch_id' => 1, 'customer_id' => $customerId, 'revision' => 1, 'side' => 'owes_us', 'amount' => '5.0000', 'opening_date' => date('Y-m-d')]);

        $this->applicationOf(null, null, $note, $customerId);
        $this->applicationOf(null, (int) $this->accountingDb()->insertID(), null, $customerId);

        (new SalesDebitNotes())->down();

        $this->assertFalse($this->hasTable('sales_debit_notes'));
        $this->assertSame(1, $this->countRows('customer_credit_applications'), 'only the application that pointed at a note is deleted');
        $this->assertTrue($this->hasConstraint('customer_credit_applications_target'));
        $this->assertEquals(0, $this->accountingDb()->query('SELECT COUNT(*) AS found FROM information_schema.CHECK_CONSTRAINTS AS cc INNER JOIN information_schema.TABLE_CONSTRAINTS AS tc ON tc.CONSTRAINT_SCHEMA = cc.CONSTRAINT_SCHEMA AND tc.CONSTRAINT_NAME = cc.CONSTRAINT_NAME AND tc.CONSTRAINT_TYPE = \'CHECK\' WHERE cc.CONSTRAINT_SCHEMA = DATABASE() AND tc.TABLE_NAME = ? AND cc.CONSTRAINT_NAME = ? AND cc.CHECK_CLAUSE LIKE ?', [$this->accountingDb()->prefixTable('customer_credit_applications'), $this->accountingDb()->prefixTable('customer_credit_applications_target'), '%debit_note_id%'])->getRow()->found, 'the rule of two targets is back');
        $this->assertSame([], array_filter($this->accountingDb()->getFieldNames('customer_credit_applications'), static fn (string $name): bool => $name === 'debit_note_id'));
        $this->assertSame(0, $this->accountingDb()->table('permissions')->where('permission_id', 'acc_debit_notes_manage')->countAllResults());
    }
}
