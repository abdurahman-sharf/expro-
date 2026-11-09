<?php

namespace Tests\Database;

use App\Database\Migrations\PartyContacts as PartyContactsMigration;
use CodeIgniter\Database\Exceptions\DatabaseException;
use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\DatabaseTestTrait;
use Config\Database;
use RuntimeException;
use Tests\Support\AccountingFixtureTrait;

/**
 * The table of the contact lists: additive, safe to run again, no existing table touched, and a row that
 * is not the contact of a customer or of a supplier is refused.
 *
 * @internal
 */
final class PartyContactsMigrationTest extends CIUnitTestCase
{
    use DatabaseTestTrait;
    use AccountingFixtureTrait;

    protected $migrate     = true;
    protected $migrateOnce = true;
    protected $refresh     = false;
    protected $namespace;

    public static function setUpBeforeClass(): void
    {
        $seeder = Database::seeder('tests');
        $seeder->call('TestDatabaseBootstrapSeeder');
    }

    protected function tearDown(): void
    {
        (new PartyContactsMigration())->up();
        $this->accountingDb()->query('DELETE FROM ' . $this->accountingDb()->prefixTable('party_contacts'));

        parent::tearDown();
    }

    private function hasTable(): bool
    {
        return $this->accountingDb()->tableExists($this->accountingDb()->prefixTable('party_contacts'), false);
    }

    /**
     * @return array<string, mixed>
     */
    private function contact(string $kind = 'customer'): array
    {
        return ['party_kind' => $kind, 'party_id' => 1, 'first_name' => 'Sara'];
    }

    public function testUp_CreatesTheTableWithTheColumnsOfAContactAndNoRows(): void
    {
        $columns = array_column($this->accountingDb()->query('SELECT COLUMN_NAME AS name FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? ORDER BY ORDINAL_POSITION', [$this->accountingDb()->prefixTable('party_contacts')])->getResultArray(), 'name');

        $this->assertSame(['contact_id', 'party_kind', 'party_id', 'first_name', 'last_name', 'email', 'phone', 'mobile', 'note', 'position', 'sort', 'deleted', 'created_by', 'created_at', 'updated_at'], $columns);
        $this->assertSame(0, $this->countRows('party_contacts'));
    }

    public function testUp_CanRunAgainAndKeepsTheContactsThatAreThere(): void
    {
        $this->accountingDb()->table('party_contacts')->insert($this->contact());

        (new PartyContactsMigration())->up();
        (new PartyContactsMigration())->up();

        $this->assertSame(1, $this->countRows('party_contacts'));
    }

    public function testUp_RejectsAContactOfAKindThatIsNeitherCustomerNorSupplier(): void
    {
        $this->expectException(DatabaseException::class);

        $this->accountingDb()->table('party_contacts')->insert($this->contact('employee'));
    }

    public function testUp_AcceptsTheContactsOfACustomerAndOfASupplier(): void
    {
        $this->accountingDb()->table('party_contacts')->insert($this->contact('customer'));
        $this->accountingDb()->table('party_contacts')->insert($this->contact('supplier'));

        $this->assertSame(2, $this->countRows('party_contacts'));
    }

    public function testUp_RejectsAHiddenFlagThatIsNeitherZeroNorOne(): void
    {
        $this->expectException(DatabaseException::class);

        $this->accountingDb()->table('party_contacts')->insert($this->contact() + ['deleted' => 2]);
    }

    public function testUp_ChangesNoRowOfTheBooksOrOfTheParties(): void
    {
        $db       = $this->accountingDb();
        $tables   = array_map(static fn (string $table): string => $db->prefixTable($table), ['accounting_journals', 'accounting_journal_lines', 'accounting_accounts', 'customers', 'suppliers', 'people', 'sales', 'sales_payments']);
        $checksum = static fn (): array => array_column($db->query('CHECKSUM TABLE `' . implode('`, `', $tables) . '`')->getResultArray(), 'Checksum', 'Table');
        $before   = $checksum();

        (new PartyContactsMigration())->up();

        $this->assertSame($before, $checksum());
    }

    public function testDown_RefusesWhileContactsAreStoredAndDropsTheTableWhenNone(): void
    {
        $this->accountingDb()->table('party_contacts')->insert($this->contact());

        try {
            (new PartyContactsMigration())->down();
            $this->fail('The migration was rolled back over stored contacts.');
        } catch (RuntimeException $refusal) {
            $this->assertStringContainsString('contacts', $refusal->getMessage());
        }

        $this->assertTrue($this->hasTable());

        $this->accountingDb()->query('DELETE FROM ' . $this->accountingDb()->prefixTable('party_contacts'));
        (new PartyContactsMigration())->down();

        $this->assertFalse($this->hasTable());

        (new PartyContactsMigration())->up();

        $this->assertTrue($this->hasTable());
    }
}
