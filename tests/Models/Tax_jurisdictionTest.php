<?php

namespace Tests\Models;

use App\Models\Tax_jurisdiction;
use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\DatabaseTestTrait;
use Config\Database;
use Tests\Support\TransactionStateTrait;

/**
 * @internal
 */
final class Tax_jurisdictionTest extends CIUnitTestCase
{
    use DatabaseTestTrait;
    use TransactionStateTrait;

    protected $migrate     = true;
    protected $migrateOnce = true;
    protected $refresh     = false;
    protected $namespace   = null;

    private Tax_jurisdiction $jurisdictions;

    /** @var list<int|string> */
    private array $kept = [];

    public static function setUpBeforeClass(): void
    {
        $seeder = Database::seeder('tests');
        $seeder->call('TestDatabaseBootstrapSeeder');
    }

    protected function setUp(): void
    {
        parent::setUp();

        $this->kept = array_column(Database::connect()->table('tax_jurisdictions')->select('jurisdiction_id')->where('deleted', 0)->get()->getResultArray(), 'jurisdiction_id');
        $this->jurisdictions = model(Tax_jurisdiction::class);
    }

    protected function tearDown(): void
    {
        $this->closeOpenTransaction();
        Database::connect()->table('tax_jurisdictions')->like('tax_group', 'T13', 'after')->delete();

        if ($this->kept !== []) {
            Database::connect()->table('tax_jurisdictions')->whereIn('jurisdiction_id', $this->kept)->update(['deleted' => 0]);
        }

        parent::tearDown();
    }

    /**
     * @return array<string, int|string>
     */
    private function row(string $group, int $id = NEW_ENTRY): array
    {
        return [
            'jurisdiction_id'     => $id,
            'jurisdiction_name'   => 'Jurisdiction ' . $group,
            'tax_group'           => $group,
            'tax_type'            => '1',
            'reporting_authority' => 'Authority',
            'tax_group_sequence'  => 1,
            'cascade_sequence'    => 0,
        ];
    }

    public function testSaveJurisdictions_SavesANewRow(): void
    {
        $group = 'T13' . substr(uniqid(), -8);

        $this->assertTrue($this->jurisdictions->save_jurisdictions([$this->row($group)]));
        $this->assertNoTransactionIsOpen();
        $this->assertSame(1, Database::connect()->table('tax_jurisdictions')->where(['tax_group' => $group, 'deleted' => 0])->countAllResults());
    }

    public function testSaveJurisdictions_AnswersFalseAndLeavesNoTransactionWhenANewRowCannotBeSaved(): void
    {
        $group = 'T13' . substr(uniqid(), -8);
        $data  = ['jurisdiction_name' => 'Old', 'tax_group' => $group, 'tax_type' => '1', 'reporting_authority' => 'Authority', 'tax_group_sequence' => 1, 'cascade_sequence' => 0, 'deleted' => 0];

        $this->assertTrue($this->jurisdictions->save_value($data));
        $this->assertTrue($this->jurisdictions->delete($data['jurisdiction_id']));

        $this->assertFalse($this->jurisdictions->save_jurisdictions([$this->row($group)]));
        $this->assertNoTransactionIsOpen();
    }
}
