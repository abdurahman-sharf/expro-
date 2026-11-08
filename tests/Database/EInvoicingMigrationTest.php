<?php

namespace Tests\Database;

use App\Database\Migrations\EInvoicing;
use App\Libraries\Accounting\AccountingGuard;
use App\Libraries\EInvoicing\PartyIdentificationType;
use CodeIgniter\Database\Exceptions\DatabaseException;
use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\DatabaseTestTrait;
use Config\Database;
use RuntimeException;
use Tests\Support\DatabaseFingerprintTrait;

class EInvoicingMigrationTest extends CIUnitTestCase
{
    use DatabaseTestTrait;
    use DatabaseFingerprintTrait;

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
        $db = Database::connect();
        $db->query('SET FOREIGN_KEY_CHECKS = 0');

        foreach (['einvoice_transport_log', 'einvoice_documents', 'customer_einvoice_parties'] as $table) {
            $db->table($table)->truncate();
        }

        $db->query('SET FOREIGN_KEY_CHECKS = 1');

        (new EInvoicing())->up();

        parent::tearDown();
    }

    private function hasTable(string $table): bool
    {
        $db = Database::connect();

        return $db->query('SELECT COUNT(*) AS found FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ?', [$db->prefixTable($table)])->getRow()->found > 0;
    }

    private function refused(callable $action): bool
    {
        try {
            $action();
        } catch (DatabaseException) {
            return true;
        }

        return false;
    }

    /**
     * @return array<string, mixed>
     */
    private function document(array $override = []): array
    {
        static $number = 0;

        $number++;

        return [...[
            'company_id'      => 1,
            'branch_id'       => 1,
            'source_type'     => 'sale',
            'source_id'       => 900000 + $number,
            'doc_kind'        => 'invoice',
            'subtype'         => 'simplified',
            'document_number' => 'INV-' . $number,
            'uuid'            => sprintf('00000000-0000-4000-8000-%012d', $number),
            'icv'             => $number,
            'previous_hash'   => 'p',
            'invoice_hash'    => 'h',
            'issue_date'      => '2026-01-01',
            'issue_time'      => '10:00:00',
            'tax_exclusive'   => '100.00',
            'tax_amount'      => '15.00',
            'tax_inclusive'   => '115.00',
            'xml'             => '<Invoice/>',
        ], ...$override];
    }

    public function testUp_CreatesTheTablesTheModuleAndTheSensitivePermissionWithoutGrantingAnything(): void
    {
        (new EInvoicing())->down();

        foreach (['einvoice_counters', 'einvoice_documents', 'einvoice_transport_log', 'customer_einvoice_parties'] as $table) {
            $this->assertFalse($this->hasTable($table), $table);
        }

        (new EInvoicing())->up();

        $db = Database::connect();

        foreach (['einvoice_counters', 'einvoice_documents', 'einvoice_transport_log', 'customer_einvoice_parties'] as $table) {
            $this->assertTrue($this->hasTable($table), $table);
        }

        $this->assertSame('module_accounting_einvoicing', $db->table('modules')->where('module_id', 'accounting_einvoicing')->get()->getRow()->name_lang_key);
        $this->assertSame('accounting_einvoicing', $db->table('permissions')->where('permission_id', AccountingGuard::EINVOICING_MANAGE)->get()->getRow()->module_id);
        $this->assertSame(0, $db->table('grants')->whereIn('permission_id', ['accounting_einvoicing', AccountingGuard::EINVOICING_MANAGE])->countAllResults());
        $this->assertContains(AccountingGuard::EINVOICING_MANAGE, AccountingGuard::SENSITIVE);
    }

    public function testUp_OpensTheCounterOfEveryCompanyAtZeroAndKeepsItWhenRunAgain(): void
    {
        $db = Database::connect();
        $db->table('einvoice_counters')->where('company_id', 1)->update(['last_icv' => 5, 'last_hash' => 'abc']);

        (new EInvoicing())->up();

        $row = $db->table('einvoice_counters')->where('company_id', 1)->get()->getRow();

        $this->assertSame(5, (int) $row->last_icv);
        $this->assertSame('abc', $row->last_hash);

        $db->table('einvoice_counters')->where('company_id', 1)->update(['last_icv' => 0, 'last_hash' => '']);
    }

    public function testUp_IsSafeToRunAgainAndChangesNothingTheSecondTime(): void
    {
        $before = $this->databaseFingerprint();

        (new EInvoicing())->up();
        (new EInvoicing())->up();

        $this->assertDatabaseUnchanged($before, 'Running the migration again changed a row.');
    }

    public function testUp_TouchesNoJournalNoSaleAndNoCustomer(): void
    {
        $db     = Database::connect();
        $before = $this->databaseFingerprint();

        (new EInvoicing())->down();
        (new EInvoicing())->up();

        $after = $this->databaseFingerprint();

        foreach (['accounting_journals', 'accounting_journal_lines', 'sales', 'sales_items', 'sales_items_taxes', 'customers', 'people', 'items', 'receivings'] as $table) {
            $this->assertSame($before[$db->prefixTable($table)] ?? null, $after[$db->prefixTable($table)] ?? null, $table);
        }
    }

    public function testDocuments_RefuseTwoDocumentsOfTheSameSaleTheSameCounterOrTheSameIdentifier(): void
    {
        $db    = Database::connect();
        $first = $this->document();

        $db->table('einvoice_documents')->insert($first);

        $this->assertTrue($this->refused(fn () => $db->table('einvoice_documents')->insert($this->document(['source_id' => $first['source_id']]))));
        $this->assertTrue($this->refused(fn () => $db->table('einvoice_documents')->insert($this->document(['icv' => $first['icv']]))));
        $this->assertTrue($this->refused(fn () => $db->table('einvoice_documents')->insert($this->document(['uuid' => $first['uuid']]))));
        $this->assertFalse($this->refused(fn () => $db->table('einvoice_documents')->insert($this->document())));
    }

    public function testDocuments_RefuseAKindASubtypeAndASourceTheStandardDoesNotKnow(): void
    {
        $db = Database::connect();

        $this->assertTrue($this->refused(fn () => $db->table('einvoice_documents')->insert($this->document(['doc_kind' => 'quote']))));
        $this->assertTrue($this->refused(fn () => $db->table('einvoice_documents')->insert($this->document(['subtype' => 'both']))));
        $this->assertTrue($this->refused(fn () => $db->table('einvoice_documents')->insert($this->document(['source_type' => 'receiving']))));
        $this->assertTrue($this->refused(fn () => $db->table('einvoice_documents')->insert($this->document(['icv' => 0]))));
        $this->assertTrue($this->refused(fn () => $db->table('einvoice_documents')->insert($this->document(['signed' => 2]))));
    }

    public function testDocuments_RefuseACreditNoteAndADebitNoteThatReferToNoDocument(): void
    {
        $db = Database::connect();

        $this->assertTrue($this->refused(fn () => $db->table('einvoice_documents')->insert($this->document(['doc_kind' => 'credit_note']))));
        $this->assertTrue($this->refused(fn () => $db->table('einvoice_documents')->insert($this->document(['doc_kind' => 'debit_note', 'source_type' => 'sales_debit_note']))));

        $db->table('einvoice_documents')->insert($this->document());
        $original = (int) $db->insertID();

        $this->assertFalse($this->refused(fn () => $db->table('einvoice_documents')->insert($this->document(['doc_kind' => 'credit_note', 'original_document_id' => $original]))));
        $this->assertTrue($this->refused(fn () => $db->table('einvoice_documents')->insert($this->document(['doc_kind' => 'credit_note', 'original_document_id' => 987654321]))));
    }

    public function testDocuments_CannotBeDeletedWhileAnotherDocumentOrAnEntryOfTheLogRefersToThem(): void
    {
        $db = Database::connect();

        $db->table('einvoice_documents')->insert($this->document());
        $original = (int) $db->insertID();
        $db->table('einvoice_documents')->insert($this->document(['doc_kind' => 'credit_note', 'original_document_id' => $original]));
        $db->table('einvoice_transport_log')->insert(['document_id' => $original, 'transport' => 'null', 'status' => 'not_submitted']);

        $this->assertTrue($this->refused(fn () => $db->table('einvoice_documents')->where('document_id', $original)->delete()));
    }

    public function testTransportLog_RefusesAStatusItDoesNotKnowAndALogOfNoDocument(): void
    {
        $db = Database::connect();

        $db->table('einvoice_documents')->insert($this->document());
        $documentId = (int) $db->insertID();

        $this->assertFalse($this->refused(fn () => $db->table('einvoice_transport_log')->insert(['document_id' => $documentId, 'transport' => 'null', 'status' => 'not_submitted'])));
        $this->assertTrue($this->refused(fn () => $db->table('einvoice_transport_log')->insert(['document_id' => $documentId, 'transport' => 'null', 'status' => 'sent'])));
        $this->assertTrue($this->refused(fn () => $db->table('einvoice_transport_log')->insert(['document_id' => 987654321, 'transport' => 'null', 'status' => 'not_submitted'])));
    }

    public function testCustomerParties_AcceptEachOfTheElevenCodesAndNothingElse(): void
    {
        $db         = Database::connect();
        $customerId = (int) $db->table('customers')->select('person_id')->limit(1)->get()->getRow()?->person_id;

        if ($customerId === 0) {
            $db->table('people')->insert(['first_name' => 'E', 'last_name' => 'Invoice', 'phone_number' => '', 'email' => 'einvoice.' . uniqid() . '@test.com', 'address_1' => '', 'address_2' => '', 'city' => '', 'state' => '', 'zip' => '', 'country' => '', 'comments' => '']);
            $customerId = (int) $db->insertID();
            $db->table('customers')->insert(['person_id' => $customerId]);
        }

        foreach (PartyIdentificationType::codes() as $code) {
            $this->assertFalse($this->refused(fn () => $db->table('customer_einvoice_parties')->replace(['person_id' => $customerId, 'identification_type' => $code])), $code);
        }

        $this->assertTrue($this->refused(fn () => $db->table('customer_einvoice_parties')->replace(['person_id' => $customerId, 'identification_type' => 'XXX'])));
        $this->assertTrue($this->refused(fn () => $db->table('customer_einvoice_parties')->replace(['person_id' => 987654321, 'identification_type' => 'CRN'])));
        $this->assertFalse($this->refused(fn () => $db->table('customer_einvoice_parties')->replace(['person_id' => $customerId, 'identification_type' => null])));
    }

    public function testDown_RefusesToDropDocumentsThatWereIssued(): void
    {
        Database::connect()->table('einvoice_documents')->insert($this->document());

        try {
            (new EInvoicing())->down();
            $this->fail('The migration dropped documents that were issued.');
        } catch (RuntimeException) {
            $this->assertTrue($this->hasTable('einvoice_documents'));
        }
    }
}
